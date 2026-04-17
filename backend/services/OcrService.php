<?php
/**
 * OcrService — Handles Google Cloud Vision API integration
 */

namespace Horsterwold\Services;

use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Feature\Type;
use Exception;

class OcrService
{
    private string $keyFilePath;

    // Priority Constants
    private const PRIORITY_EXACT = 100;
    private const PRIORITY_PARTIAL = 50;
    private const PRIORITY_FALLBACK = 10;
    private const PRIORITY_NONE = 0;

    public function __construct()
    {
        $this->keyFilePath = defined('GOOGLE_KEY_FILE') ? GOOGLE_KEY_FILE : '';
    }

    /**
     * Detect text in an image and extract meter reading
     * Returns an array with 'reading' and 'meter_number' (optional)
     */
    public function detectMeterReading(string $imageContent, string $meterType = 'unknown'): ?array
    {
        if (defined('OCR_PROVIDER') && OCR_PROVIDER === 'mock') {
            return $this->getMockReading();
        }

        if (empty($this->keyFilePath) || !file_exists($this->keyFilePath)) {
            throw new Exception("Google Cloud Vision key file not found at: " . $this->keyFilePath);
        }

        try {
            $imageAnnotator = new ImageAnnotatorClient([
                'credentials' => $this->keyFilePath
            ]);

            // Pre-process image based on meter type
            $processedImage = $this->enhanceImageContrast($imageContent, $meterType);

            // Request both Document Text Detection and Label Detection
            $image = $processedImage;
            $features = [
                (new Feature())->setType(Type::DOCUMENT_TEXT_DETECTION),
                (new Feature())->setType(Type::LABEL_DETECTION)
            ];

            $response = $imageAnnotator->annotateImage($image, $features);
            $fullAnnotation = $response->getFullTextAnnotation();
            
            $labels = [];
            foreach ($response->getLabelAnnotations() as $label) {
                $labels[] = strtolower($label->getDescription());
            }

            if (!$fullAnnotation) {
                // Fallback to basic text detection if document detection fails
                $featuresFallback = [(new Feature())->setType(Type::TEXT_DETECTION)];
                $response = $imageAnnotator->annotateImage($image, $featuresFallback);
                $texts = $response->getTextAnnotations();
                
                if (empty($texts)) {
                    $imageAnnotator->close();
                    return null;
                }

                $allText = $texts[0]->getDescription();
                $imageAnnotator->close();
                
                $results = [
                    'reading' => $this->parseDigits($allText, $meterType),
                    'meter_number' => null
                ];
                $results['validation'] = $this->validateMeterType($allText, $labels, $meterType);
                return $results;
            }

            $results = $this->extractMeterData($fullAnnotation, $imageContent, $meterType, $labels);
            $results['validation'] = $this->validateMeterType($fullAnnotation->getText(), $labels, $meterType);
            
            $imageAnnotator->close();
            return $results;

        } catch (Exception $e) {
            error_log("OCR Error: " . $e->getMessage());
            $errorMsg = (defined('APP_ENV') && APP_ENV === 'development') ? $e->getMessage() : "fout bij het uitlezen van de meter";
            throw new Exception($errorMsg);
        }
    }

    /**
     * Extracts meter reading from FullTextAnnotation based on structural meter patterns
     */
    private function extractMeterData($annotation, string $imageContent, string $meterType, array $labels): array
    {
        $allReadings = [];
        $unitLocations = $this->findUnitLocations($annotation, $meterType);

        $this->logOcrDebug("--- Starting Line-Aware Structural Extraction for $meterType ---");

        // NEW: Group symbols into logical horizontal lines across the entire page
        $lines = $this->groupSymbolsIntoLines($annotation, $imageContent);

        foreach ($lines as $lineIdx => $lineSymbols) {
            $n = count($lineSymbols);
            $this->logOcrDebug("Processing line $lineIdx with $n symbols...");
            for ($i = 0; $i < $n; $i++) {
                // Try sequences from length 4 to 10
                for ($len = 4; $len <= 10 && ($i + $len) <= $n; $len++) {
                    $sequence = array_slice($lineSymbols, $i, $len);
                    $candidate = $this->evaluateSequence($sequence, $meterType, $unitLocations);
                    if ($candidate && $candidate['priority'] > self::PRIORITY_NONE) {
                        $allReadings[] = $candidate;
                    }
                }
            }
        }

        // Final Sort: Priority first, then Context, then average height (LARGER IS BETTER), then Area
        usort($allReadings, function($a, $b) use ($unitLocations, $meterType) {
            if ($a['priority'] !== $b['priority']) return $b['priority'] <=> $a['priority'];
            
            // Context check: is it followed by the target unit?
            $targetUnit = ($meterType === 'elec') ? 'kwh' : 'm3';
            $aContext = ($this->isFollowedBy($a['last_box'], $unitLocations, $targetUnit) || $this->isSameLineAs($a['last_box'], $unitLocations, $targetUnit)) ? 1 : 0;
            $bContext = ($this->isFollowedBy($b['last_box'], $unitLocations, $targetUnit) || $this->isSameLineAs($b['last_box'], $unitLocations, $targetUnit)) ? 1 : 0;
            
            if ($aContext !== $bContext) return $bContext <=> $aContext;
            
            // Height is the ultimate differentiator for meter readings vs serial numbers
            if (abs($a['avgHeight'] - $b['avgHeight']) > 2) {
                return $b['avgHeight'] <=> $a['avgHeight'];
            }
            
            return $b['area'] <=> $a['area'];
        });

        $readingResult = null;
        if (!empty($allReadings)) {
            $best = $allReadings[0];
            $this->logOcrDebug("Selected BEST structural candidate: " . $best['text'] . " (Priority Level: " . $best['priority'] . ")");
            $readingResult = $best['text'];
        } else {
            $this->logOcrDebug("No structural patterns matched. Performing Global Size Sweep...");
            $readingResult = $this->performGlobalSizeSweep($lines, $meterType);
        }

        return [
            'reading' => $readingResult,
            'meter_number' => null
        ];
    }

    /**
     * Groups all symbols on the page into logical horizontal lines
     */
    private function groupSymbolsIntoLines($annotation, string $imageContent): array
    {
        $allSymbols = [];
        foreach ($annotation->getPages() as $page) {
            foreach ($page->getBlocks() as $block) {
                foreach ($block->getParagraphs() as $paragraph) {
                    foreach ($paragraph->getWords() as $word) {
                        foreach ($word->getSymbols() as $symbol) {
                            $char = $symbol->getText();
                            if (!preg_match('/^[0-9.,]$/', $char)) continue;

                            $box = $symbol->getBoundingBox();
                            $v = $box->getVertices();
                            $yPos = ($v[0]->getY() + $v[2]->getY()) / 2;
                            $xPos = ($v[0]->getX() + $v[2]->getX()) / 2;
                            $height = abs($v[2]->getY() - $v[1]->getY());

                            // Pre-calculate colors ONCE per symbol
                            $isRed = $this->isRedOrRedBordered($box, $imageContent);
                            $isBlackBg = $this->isBlackBackground($box, $imageContent);
                            $isWhiteBg = $this->isWhiteBackground($box, $imageContent);

                            $allSymbols[] = [
                                'char' => $char,
                                'height' => $height,
                                'y' => $yPos,
                                'x' => $xPos,
                                'box' => $box,
                                'is_red' => $isRed,
                                'is_black_bg' => $isBlackBg,
                                'is_white_bg' => $isWhiteBg
                            ];
                        }
                    }
                }
            }
        }

        if (empty($allSymbols)) return [];

        // Sort by Y first to facilitate grouping
        usort($allSymbols, function($a, $b) {
            return $a['y'] <=> $b['y'];
        });

        $lines = [];
        foreach ($allSymbols as $s) {
            $foundLine = false;
            // Try to find an existing line where this symbol fits vertically
            foreach ($lines as &$line) {
                $lineAvgY = array_sum(array_column($line, 'y')) / count($line);
                $lineAvgH = array_sum(array_column($line, 'height')) / count($line);
                
                // Generous threshold: within 70% of the line's average height
                if (abs($s['y'] - $lineAvgY) < ($lineAvgH * 0.7)) {
                    $line[] = $s;
                    $foundLine = true;
                    break;
                }
            }
            if (!$foundLine) {
                $lines[] = [$s];
            }
        }

        // Final polishing: sort by X
        foreach ($lines as &$line) {
            usort($line, function($a, $b) {
                return $a['x'] <=> $b['x'];
            });
        }

        return $lines;
    }

    /**
     * Evaluates a specific sequence of symbols against the meter-type priority rules
     */
    private function evaluateSequence(array $sequence, string $meterType, array $unitLocations): ?array
    {
        $text = '';
        $colors = '';
        $totalHeight = 0;
        $blackDigits = '';
        
        foreach ($sequence as $s) {
            $char = $s['char'];
            $text .= $char;
            
            if (!$s['is_red'] && $s['is_black_bg'] && ctype_digit($char)) {
                $blackDigits .= $char;
            }
            $colors .= ($s['is_red'] ? 'R' : ($s['is_black_bg'] ? 'B' : ($s['is_white_bg'] ? 'W' : '?')));
            $totalHeight += $s['height'];
        }
        
        $avgHeight = $totalHeight / count($sequence);
        $cleanDigits = preg_replace('/[^0-9]/', '', $text);
        
        $priority = self::PRIORITY_NONE;
        $finalValue = $cleanDigits;
        $ruleName = "";

        // Height consistency check
        $tolerance = ($meterType === 'elec') ? 0.12 : 0.25;
        foreach ($sequence as $s) {
            if (abs($s['height'] - $avgHeight) > ($avgHeight * $tolerance)) return null;
        }
        
        if ($meterType === 'gas') {
            // Rule: Find the transition point (Exactly 4 or 5 black followed by red)
            $transitionPoint = strpos($colors, 'BR');
            if ($transitionPoint !== false && $transitionPoint >= 4 && $transitionPoint <= 5) {
                $priority = self::PRIORITY_EXACT;
                $finalValue = substr($cleanDigits, 0, $transitionPoint + 1);
                if (strlen($finalValue) > 5) $finalValue = substr($finalValue, 0, 5);
                $ruleName = "Gas Transition (B->R)";
            } elseif (strpos($colors, 'BBBBBRRR') === 0 && strlen($blackDigits) >= 5) {
                $priority = self::PRIORITY_PARTIAL;
                $finalValue = substr($blackDigits, 0, 5);
                $ruleName = "Gas Exact (5B-3R)";
            } elseif (strlen($cleanDigits) >= 5 && preg_match('/^B{5}/', $colors)) {
                // If we see 5 blacks followed by NOTHING or something DIFFERENT, that's our gas reading
                $priority = self::PRIORITY_PARTIAL;
                $finalValue = substr($cleanDigits, 0, 5);
                $ruleName = "Gas Prefix (5B-Fixed)";
            } elseif ((strpos($colors, 'BBBBB') !== false) && strlen($blackDigits) >= 5) {
                $priority = self::PRIORITY_FALLBACK;
                $pos = strpos($colors, 'BBBBB');
                $finalValue = substr($blackDigits, $pos, 5); 
                $ruleName = "Gas Prefix (5B)";
            }

        } elseif ($meterType === 'water') {
            if (strpos($colors, 'BBBBBRRR') === 0 && strlen($blackDigits) >= 5) {
                $priority = self::PRIORITY_EXACT;
                $finalValue = substr($blackDigits, 0, 5);
                $ruleName = "Water Unimag (5B-3R)";
            } else {
                $whiteCount = substr_count($colors, 'W');
                if ($whiteCount >= 5 && strlen($cleanDigits) == 5) {
                    $priority = self::PRIORITY_EXACT;
                    $ruleName = "Water Raster (5W)";
                } elseif (strlen($cleanDigits) >= 5 && $this->isFollowedBy($sequence[count($sequence)-1]['box'], $unitLocations, 'm3')) {
                    $priority = self::PRIORITY_FALLBACK;
                    $ruleName = "Water Context (m3)";
                }
            }

        } elseif ($meterType === 'elec') {
            if (strpos($colors, 'BBBBBR') === 0 && strlen($blackDigits) >= 5) {
                $priority = self::PRIORITY_EXACT;
                $finalValue = substr($blackDigits, 0, 5);
                $ruleName = "Elec Analoog (5B-1R)";
            } elseif (preg_match('/(\d{5,8})[.,](\d)/', $text, $matches)) {
                if (preg_match('/1[.,]8[.,]/', $text)) return null; // Ignore OBIS
                $priority = $this->isFollowedBy($sequence[count($sequence)-1]['box'], $unitLocations, 'kwh') 
                    ? self::PRIORITY_EXACT : self::PRIORITY_PARTIAL;
                $finalValue = $matches[1];
                $ruleName = "Elec LCD (decimal)";
            } elseif (strlen($cleanDigits) >= 5 && $avgHeight > 25) {
                $priority = self::PRIORITY_FALLBACK;
                $ruleName = "Elec Size Fallback";
            }
        }

        if ($priority > self::PRIORITY_NONE) {
            $v1 = $sequence[0]['box']->getVertices();
            $v2 = $sequence[count($sequence)-1]['box']->getVertices();
            $area = abs($v2[1]->getX() - $v1[0]->getX()) * $avgHeight;

            return [
                'text' => $finalValue,
                'priority' => $priority,
                'pattern' => $colors,
                'area' => $area,
                'avgHeight' => $avgHeight,
                'last_box' => $sequence[count($sequence)-1]['box']
            ];
        }

        return null;
    }

    private function findUnitLocations($annotation, string $meterType): array
    {
        $targetUnits = ($meterType === 'elec') ? ['kwh', 'kw.h'] : ['m3', 'm³'];
        $locations = [];

        foreach ($annotation->getPages() as $page) {
            foreach ($page->getBlocks() as $block) {
                foreach ($block->getParagraphs() as $paragraph) {
                    foreach ($paragraph->getWords() as $word) {
                        $wordText = '';
                        foreach ($word->getSymbols() as $symbol) $wordText .= $symbol->getText();
                        $clean = strtolower(str_replace(['^', '.', ' '], '', $wordText));
                        if (in_array($clean, $targetUnits)) {
                            $locations[] = ['text' => $wordText, 'box' => $word->getBoundingBox()];
                        }
                    }
                }
            }
        }
        return $locations;
    }

    private function validateMeterType(string $allText, array $labels, string $expectedType): array
    {
        $low = strtolower($allText);
        $valid = true;
        $message = "OK";

        if ($expectedType === 'gas' && strpos($low, 'm3') === false && strpos($allText, 'm³') === false && !in_array('gas', $labels)) {
            // Only warn, don't block
            $message = "Geen m3 gevonden op foto.";
        }
        
        return ['valid' => $valid, 'message' => $message];
    }

    /**
     * Finds the largest likely digit group on the page, regardless of structure
     */
    private function performGlobalSizeSweep(array $lines, string $meterType): ?string
    {
        $candidates = [];
        $serialBlacklist = ['04000', '18000', '32304']; // Known frame numbers

        foreach ($lines as $symbols) {
            $n = count($symbols);
            for ($i = 0; $i < $n; $i++) {
                for ($len = 4; $len <= 10 && ($i + $len) <= $n; $len++) {
                    $seq = array_slice($symbols, $i, $len);
                    $text = '';
                    $totalH = 0;
                    foreach ($seq as $s) {
                        $text .= $s['char'];
                        $totalH += $s['height'];
                    }
                    $avgH = $totalH / $len;
                    $digits = preg_replace('/[^0-9]/', '', $text);
                    
                    if (strlen($digits) >= 4 && strlen($digits) <= 8) {
                        // Skip if it looks like a serial number frame
                        $isBlacklisted = false;
                        foreach ($serialBlacklist as $b) {
                            if (strpos($digits, $b) !== false) $isBlacklisted = true;
                        }
                        if ($isBlacklisted) continue;
                        if (preg_match('/ISK|SN|NR|SERIENR|TYP|S\/N/i', $text)) continue;

                        $candidates[] = [
                            'text' => $digits,
                            'height' => $avgH,
                            'len' => strlen($digits)
                        ];
                    }
                }
            }
        }

        if (empty($candidates)) return null;

        // Sort by height first, then length
        usort($candidates, function($a, $b) {
            if (abs($a['height'] - $b['height']) > 2) return $b['height'] <=> $a['height'];
            return $b['len'] <=> $a['len'];
        });

        $best = $candidates[0]['text'];
        if (strlen($best) > 5 && $meterType !== 'elec') $best = substr($best, 0, 5);
        
        $this->logOcrDebug("Global Size Sweep selected: " . $best . " (Height: " . $candidates[0]['height'] . ")");
        return $best;
    }

    private function isRedOrRedBordered($box, string $imageContent): bool
    {
        if (!extension_loaded('gd')) return false;
        try {
            $im = imagecreatefromstring($imageContent);
            if (!$im) return false;
            $v = $box->getVertices();
            $x1 = (int)min($v[0]->getX(), $v[3]->getX()); $y1 = (int)min($v[0]->getY(), $v[1]->getY());
            $x2 = (int)max($v[1]->getX(), $v[2]->getX()); $y2 = (int)max($v[2]->getY(), $v[3]->getY());

            $redCount = 0; $totalPoints = 0;
            $step = max(1, ($x2-$x1)/8);
            for ($x = max(0, $x1-1); $x < min(imagesx($im), $x2+1); $x += $step) {
                for ($y = max(0, $y1-1); $y < min(imagesy($im), $y2+1); $y += $step) {
                    $rgb = imagecolorat($im, (int)$x, (int)$y);
                    $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                    if ($r > 80 && $r > $g + 30 && $r > $b + 30) $redCount++;
                    $totalPoints++;
                }
            }
            imagedestroy($im);
            return ($totalPoints > 0) && ($redCount / $totalPoints) > 0.12;
        } catch (Exception $e) { return false; }
    }

    private function isBlackBackground($box, string $imageContent): bool
    {
        if (!extension_loaded('gd')) return true;
        try {
            $im = imagecreatefromstring($imageContent);
            if (!$im) return true;
            $v = $box->getVertices();
            $x1 = (int)min($v[0]->getX(), $v[3]->getX()); $y1 = (int)min($v[0]->getY(), $v[1]->getY());
            $x2 = (int)max($v[1]->getX(), $v[2]->getX()); $y2 = (int)max($v[2]->getY(), $v[3]->getY());

            $darkPoints = 0; $totalPoints = 0;
            $step = max(1, ($x2-$x1)/8);
            for ($x = max(0, $x1); $x < min(imagesx($im), $x2); $x += $step) {
                for ($y = max(0, $y1); $y < min(imagesy($im), $y2); $y += $step) {
                    $rgb = imagecolorat($im, (int)$x, (int)$y);
                    $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                    $brightness = ($r + $g + $b) / 3;
                    $maxDiff = max($r, $g, $b) - min($r, $g, $b);
                    if ($brightness < 160 && $maxDiff < 30) $darkPoints++;
                    $totalPoints++;
                }
            }
            imagedestroy($im);
            return ($totalPoints > 0) && ($darkPoints / $totalPoints) > 0.4;
        } catch (Exception $e) { return true; }
    }

    private function isWhiteBackground($box, string $imageContent): bool
    {
        if (!extension_loaded('gd')) return false;
        try {
            $im = imagecreatefromstring($imageContent);
            if (!$im) return false;
            $v = $box->getVertices();
            $x1 = (int)min($v[0]->getX(), $v[3]->getX()); $y1 = (int)min($v[0]->getY(), $v[1]->getY());
            $x2 = (int)max($v[1]->getX(), $v[2]->getX()); $y2 = (int)max($v[2]->getY(), $v[3]->getY());

            $whitePoints = 0; $totalPoints = 0;
            for ($x = max(0, $x1); $x < min(imagesx($im), $x2); $x += max(1, ($x2-$x1)/8)) {
                for ($y = max(0, $y1); $y < min(imagesy($im), $y2); $y += max(1, ($y2-$y1)/8)) {
                    $rgb = imagecolorat($im, (int)$x, (int)$y);
                    $brightness = ((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF)) / 3;
                    if ($brightness > 190) $whitePoints++;
                    $totalPoints++;
                }
            }
            imagedestroy($im);
            return ($totalPoints > 0) && ($whitePoints / $totalPoints) > 0.6;
        } catch (Exception $e) { return false; }
    }

    private function isSameLineAs($box, array $units, string $target): bool
    {
        $v = $box->getVertices();
        $centerY = ($v[0]->getY() + $v[2]->getY()) / 2;
        $height = abs($v[2]->getY() - $v[1]->getY());

        foreach ($units as $u) {
            if (strpos(strtolower($u['text']), $target) !== false) {
                $uv = $u['box']->getVertices();
                $ucentery = ($uv[0]->getY() + $uv[2]->getY()) / 2;
                if (abs($centerY - $ucentery) < ($height * 1.5)) return true;
            }
        }
        return false;
    }

    private function isFollowedBy($box, array $units, string $target): bool
    {
        $v = $box->getVertices();
        $centerY = ($v[0]->getY() + $v[2]->getY()) / 2;
        $height = abs($v[2]->getY() - $v[1]->getY());
        $rightX = max($v[1]->getX(), $v[2]->getX());

        foreach ($units as $u) {
            if (strpos(strtolower($u['text']), $target) !== false) {
                $uv = $u['box']->getVertices();
                $ucentery = ($uv[0]->getY() + $uv[2]->getY()) / 2;
                $uleftx = min($uv[0]->getX(), $uv[3]->getX());
                if (abs($centerY - $ucentery) < ($height * 1.5) && ($uleftx >= $rightX - ($height * 2))) return true;
            }
        }
        return false;
    }

    private function parseDigits(string $text, string $meterType = 'unknown'): ?string
    {
        $cleanText = str_replace([' ', '.', ','], '', $text);
        if (preg_match_all('/\b\d{5,8}\b/', $cleanText, $matches)) {
            $match = $matches[0][0];
            return (strlen($match) > 5) ? substr($match, 0, 5) : $match;
        }
        return null;
    }
    private function logOcrDebug(string $message): void
    {
        $logPath = __DIR__ . '/../logs/ocr_debug.log';
        if (!is_dir(dirname($logPath))) mkdir(dirname($logPath), 0777, true);
        file_put_contents($logPath, date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
    }

    private function enhanceImageContrast(string $imageContent, string $meterType): string
    {
        if (!extension_loaded('gd')) return $imageContent;
        try {
            $im = imagecreatefromstring($imageContent);
            if (!$im) return $imageContent;

            // Apply different filters based on meter type
            if ($meterType === 'elec') {
                // High contrast and significant sharpening for LCD segments
                imagefilter($im, IMG_FILTER_CONTRAST, -60);
                imagefilter($im, IMG_FILTER_BRIGHTNESS, 10);
                
                // Sharpen matrix for better LCD edges
                $matrix = [
                    [0, -1, 0],
                    [-1, 5, -1],
                    [0, -1, 0]
                ];
                imageconvolution($im, $matrix, 1, 0);
            } elseif ($meterType === 'water') {
                // Strong contrast for pale water meter digits
                imagefilter($im, IMG_FILTER_CONTRAST, -50);
            } elseif ($meterType === 'gas') {
                // Mild contrast to preserve red/black saturation difference
                imagefilter($im, IMG_FILTER_CONTRAST, -25);
            }

            ob_start();
            imagejpeg($im, null, 90);
            $newImageContent = ob_get_clean();
            imagedestroy($im);
            return $newImageContent;
        } catch (Exception $e) { return $imageContent; }
    }

    private function getMockReading(): array
    {
        return [
            'reading' => (string)rand(10000, 99999), 
            'meter_number' => 'SN-'.rand(100,999), 
            'validation' => ['valid' => true, 'message' => 'OK']
        ];
    }
}
