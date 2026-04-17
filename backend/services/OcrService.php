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

            // Pre-process image: Increase contrast for water meters
            $processedImage = $imageContent;
            if ($meterType === 'water') {
                $processedImage = $this->enhanceImageContrast($imageContent);
            }

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
    }    /**
     * Extracts meter reading from FullTextAnnotation based on structural meter patterns
     */
    private function extractMeterData($annotation, string $imageContent, string $meterType, array $labels): array
    {
        $allReadings = [];
        $unitLocations = [];

        // Determine units/context to look for
        $targetUnits = ($meterType === 'elec') ? ['kwh', 'kw.h'] : ['m3', 'm³'];

        $this->logOcrDebug("--- Starting Structural Extraction for $meterType ---");

        // First pass: collect all unit locations
        foreach ($annotation->getPages() as $page) {
            foreach ($page->getBlocks() as $block) {
                foreach ($block->getParagraphs() as $paragraph) {
                    foreach ($paragraph->getWords() as $word) {
                        $wordText = '';
                        foreach ($word->getSymbols() as $symbol) $wordText .= $symbol->getText();
                        $cleanWord = strtolower(str_replace(['^', '.', ' '], '', $wordText));
                        if (in_array($cleanWord, $targetUnits)) {
                            $unitLocations[] = ['text' => $wordText, 'box' => $word->getBoundingBox()];
                        }
                    }
                }
            }
        }

        // Second pass: Traverse and find candidates with structural metadata
        foreach ($annotation->getPages() as $pageIdx => $page) {
            foreach ($page->getBlocks() as $block) {
                foreach ($block->getParagraphs() as $paraIdx => $paragraph) {
                    $wordsInPara = iterator_to_array($paragraph->getWords());
                    $wordsWithMeta = [];
                    $allSymbolsInPara = [];
                    
                    foreach ($wordsInPara as $wordIdx => $word) {
                        $wordText = '';
                        $symbolsWithMeta = [];
                        foreach ($word->getSymbols() as $symbol) {
                            $char = $symbol->getText();
                            $box = $symbol->getBoundingBox();
                            $vertices = $box->getVertices();
                            
                            $height = abs($vertices[2]->getY() - $vertices[1]->getY());
                            $isRed = $this->isRedOrRedBordered($box, $imageContent);
                            $isBlackBg = $this->isBlackBackground($box, $imageContent);
                            $isWhiteBg = $this->isWhiteBackground($box, $imageContent);

                            $meta = [
                                'char' => $char,
                                'height' => $height,
                                'is_red' => $isRed,
                                'is_black_bg' => $isBlackBg,
                                'is_white_bg' => $isWhiteBg,
                                'box' => $box
                            ];
                            $symbolsWithMeta[] = $meta;
                            $allSymbolsInPara[] = $meta;
                            $wordText .= $char;
                        }
                        
                        $wordsWithMeta[] = [
                            'text' => $wordText,
                            'symbols' => $symbolsWithMeta,
                            'box' => $word->getBoundingBox(),
                            'paraIdx' => $paraIdx,
                            'wordIdx' => $wordIdx
                        ];
                    }

                    // Process structural candidates in this paragraph
                    $this->matchStructuralPatterns($allSymbolsInPara, $unitLocations, $allReadings, $meterType, $labels);
                }
            }
        }

        // Sort Readings by Score (Structural matches get high scores)
        usort($allReadings, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        if (!empty($allReadings)) {
            $best = $allReadings[0];
            $this->logOcrDebug("Selected BEST structural candidate: " . $best['text'] . " with score " . $best['score']);
            $readingResult = $best['text'];
        } else {
            $this->logOcrDebug("No structural candidates found, falling back to parseDigits");
            $readingResult = $this->parseDigits($annotation->getText(), $meterType);
        }

        return [
            'reading' => $readingResult,
            'meter_number' => null
        ];
    }

    /**
     * Matches symbols against specific structural patterns (Gas, Water, Elec)
     */
    private function matchStructuralPatterns(array $symbols, array $unitLocations, array &$allReadings, string $meterType, array $labels): void
    {
        // Filter out non-digits from the structural sequence
        $digitSymbols = array_values(array_filter($symbols, function($s) {
            return preg_match('/^[0-9.,]$/', $s['char']);
        }));

        $count = count($digitSymbols);
        if ($count < 5) return;

        // Slide a window across the digits to find 5-8 digit sequences
        for ($i = 0; $i <= $count - 5; $i++) {
            for ($len = 5; $len <= 8 && ($i + $len) <= $count; $len++) {
                $sequence = array_slice($digitSymbols, $i, $len);
                $this->evaluateSequence($sequence, $unitLocations, $allReadings, $meterType, $labels);
            }
        }
    }

    /**
     * Evaluates a specific sequence of digits against the meter-type rules
     */
    private function evaluateSequence(array $sequence, array $unitLocations, array &$allReadings, string $meterType, array $labels): void
    {
        $text = '';
        $colors = '';
        $totalHeight = 0;
        foreach ($sequence as $s) {
            $text .= $s['char'];
            $colors .= ($s['is_red'] ? 'R' : ($s['is_black_bg'] ? 'B' : 'W'));
            $totalHeight += $s['height'];
        }
        $avgHeight = $totalHeight / count($sequence);
        $cleanDigits = preg_replace('/[^0-9]/', '', $text);
        
        $score = 0;
        $finalValue = $cleanDigits;

        // check height consistency
        foreach ($sequence as $s) {
            if (abs($s['height'] - $avgHeight) > ($avgHeight * 0.3)) return; // Too inconsistent
        }

        if ($meterType === 'gas') {
            // Pattern: [B,B,B,B,B,R,R,R] (5 black, 3 red) - take first 5
            if (strpos($colors, 'BBBBBRRR') === 0 && strlen($cleanDigits) >= 5) {
                $score += 1000;
                $finalValue = substr($cleanDigits, 0, 5);
            } elseif (strpos($colors, 'BBBBB') === 0 && strlen($cleanDigits) >= 5) {
                $score += 200; // Partial match
                $finalValue = substr($cleanDigits, 0, 5);
            }
            if ($this->isSameLineAs($sequence[0]['box'], $unitLocations, 'm3')) $score += 100;

        } elseif ($meterType === 'water') {
            // Unimag Pattern: [B,B,B,B,B,R,R,R] - take 5 black
            if (strpos($colors, 'BBBBBRRR') === 0 && strlen($cleanDigits) >= 5) {
                $score += 1000;
                $finalValue = substr($cleanDigits, 0, 5);
            }
            // Standard Pattern: 5 digits in white raster + m3
            $whiteCount = 0;
            foreach ($sequence as $s) if ($s['is_white_bg']) $whiteCount++;
            if ($whiteCount >= 5 && strlen($cleanDigits) == 5) {
                $score += 800;
            }
            if ($this->isFollowedBy($sequence[count($sequence)-1]['box'], $unitLocations, 'm3')) $score += 200;

        } elseif ($meterType === 'elec') {
            // Type 1: Analog [B,B,B,B,B,R] - 5 black, 1 red
            if (strpos($colors, 'BBBBBR') === 0 && strlen($cleanDigits) >= 5) {
                $score += 1000;
                $finalValue = substr($cleanDigits, 0, 5);
            }
            // Type 2: LCD - Consistent size before point
            if (strpos($text, '.') !== false || strpos($text, ',') !== false) {
                $parts = preg_split('/[.,]/', $text);
                if (strlen($parts[0]) >= 5) {
                    $score += 900;
                    $finalValue = $parts[0];
                }
            }
            if ($this->isFollowedBy($sequence[count($sequence)-1]['box'], $unitLocations, 'kwh')) $score += 200;
        }

        if ($score > 0) {
            $allReadings[] = [
                'text' => $finalValue,
                'score' => $score,
                'pattern' => $colors,
                'raw' => $text
            ];
            $this->logOcrDebug("Pattern Candidate: $text | Pattern: $colors | Score: $score | Final: $finalValue");
        }
    }

    /**
     * Best-effort color detection for red backgrounds or borders
     */
    private function isRedOrRedBordered($box, string $imageContent): bool
    {
        if (!extension_loaded('gd')) return false;

        try {
            $im = imagecreatefromstring($imageContent);
            if (!$im) return false;

            $v = $box->getVertices();
            $x1 = (int)min($v[0]->getX(), $v[3]->getX());
            $y1 = (int)min($v[0]->getY(), $v[1]->getY());
            $x2 = (int)max($v[1]->getX(), $v[2]->getX());
            $y2 = (int)max($v[2]->getY(), $v[3]->getY());

            $width = imagesx($im);
            $height = imagesy($im);

            $redCount = 0;
            $totalPoints = 0;
            
            // Sample points in and around the bounding box to catch borders
            $stepX = max(1, ($x2-$x1)/10);
            $stepY = max(1, ($y2-$y1)/10);

            for ($x = max(0, $x1 - 2); $x < min($width, $x2 + 2); $x += $stepX) {
                for ($y = max(0, $y1 - 2); $y < min($height, $y2 + 2); $y += $stepY) {
                    $rgb = imagecolorat($im, (int)$x, (int)$y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    if ($r > 130 && $r > $g + 40 && $r > $b + 40) {
                        $redCount++;
                    }
                    $totalPoints++;
                }
            }

            imagedestroy($im);
            return ($totalPoints > 0) && ($redCount / $totalPoints) > 0.12; 

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Detects if the background is dark (black/dark grey)
     */
    private function isBlackBackground($box, string $imageContent): bool
    {
        if (!extension_loaded('gd')) return true;

        try {
            $im = imagecreatefromstring($imageContent);
            if (!$im) return true;

            $v = $box->getVertices();
            $x1 = (int)min($v[0]->getX(), $v[3]->getX());
            $y1 = (int)min($v[0]->getY(), $v[1]->getY());
            $x2 = (int)max($v[1]->getX(), $v[2]->getX());
            $y2 = (int)max($v[2]->getY(), $v[3]->getY());

            $width = imagesx($im);
            $height = imagesy($im);

            $darkPoints = 0;
            $totalPoints = 0;
            
            for ($x = max(0, $x1); $x < min($width, $x2); $x += max(1, ($x2-$x1)/8)) {
                for ($y = max(0, $y1); $y < min($height, $y2); $y += max(1, ($y2-$y1)/8)) {
                    $rgb = imagecolorat($im, (int)$x, (int)$y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    $brightness = ($r + $g + $b) / 3;
                    if ($brightness < 110) $darkPoints++; 
                    $totalPoints++;
                }
            }

            imagedestroy($im);
            return ($totalPoints > 0) && ($darkPoints / $totalPoints) > 0.5;

        } catch (Exception $e) {
            return true;
        }
    }

    /**
     * Detects if the background is white (for water meter rasters)
     */
    private function isWhiteBackground($box, string $imageContent): bool
    {
        if (!extension_loaded('gd')) return false;

        try {
            $im = imagecreatefromstring($imageContent);
            if (!$im) return false;

            $v = $box->getVertices();
            $x1 = (int)min($v[0]->getX(), $v[3]->getX());
            $y1 = (int)min($v[0]->getY(), $v[1]->getY());
            $x2 = (int)max($v[1]->getX(), $v[2]->getX());
            $y2 = (int)max($v[2]->getY(), $v[3]->getY());

            $width = imagesx($im);
            $height = imagesy($im);

            $whitePoints = 0;
            $totalPoints = 0;
            
            for ($x = max(0, $x1); $x < min($width, $x2); $x += max(1, ($x2-$x1)/8)) {
                for ($y = max(0, $y1); $y < min($height, $y2); $y += max(1, ($y2-$y1)/8)) {
                    $rgb = imagecolorat($im, (int)$x, (int)$y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    $brightness = ($r + $g + $b) / 3;
                    if ($brightness > 200) $whitePoints++; 
                    $totalPoints++;
                }
            }

            imagedestroy($im);
            return ($totalPoints > 0) && ($whitePoints / $totalPoints) > 0.6;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Checks if a word is preceded by an exclusion keyword (e.g., "nr")
     */
    private function hasRedFlag(int $wordIdx, array $paragraphWords): bool
    {
        if ($wordIdx === 0) return false;
        
        $prevWord = strtolower(trim($paragraphWords[$wordIdx - 1]['text']));
        $redFlags = ['nr', 'sn', 'no', 'serienr', 'n°', 'meter', 'no.', 'nr.', 'sn.'];
        
        foreach ($redFlags as $flag) {
            if (strpos($prevWord, $flag) !== false) return true;
        }
        return false;
    }

    private function logOcrDebug(string $message): void
    {
        $logPath = __DIR__ . '/../logs/ocr_debug.log';
        if (!is_dir(dirname($logPath))) mkdir(dirname($logPath), 0777, true);
        file_put_contents($logPath, date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
    }

    /**
     * Increases contrast of the image to improve OCR
     */
    private function enhanceImageContrast(string $imageContent): string
    {
        if (!extension_loaded('gd')) return $imageContent;

        try {
            $im = imagecreatefromstring($imageContent);
            if (!$im) return $imageContent;

            // Apply contrast filter (negative value increases contrast in PHP GD)
            imagefilter($im, IMG_FILTER_CONTRAST, -40);

            ob_start();
            imagejpeg($im, null, 90);
            $newImageContent = ob_get_clean();
            imagedestroy($im);

            return $newImageContent;
        } catch (Exception $e) {
            return $imageContent;
        }
    }

    /**
     * Calculates the distance between the centers of two bounding boxes
     */
    private function getDistance($box1, $box2): float
    {
        $v1 = $box1->getVertices();
        $v2 = $box2->getVertices();

        $c1x = ($v1[0]->getX() + $v1[2]->getX()) / 2;
        $c1y = ($v1[0]->getY() + $v1[2]->getY()) / 2;

        $c2x = ($v2[0]->getX() + $v2[2]->getX()) / 2;
        $c2y = ($v2[0]->getY() + $v2[2]->getY()) / 2;

        return sqrt(pow($c1x - $c2x, 2) + pow($c1y - $c2y, 2));
    }

    /**
     * Checks if a box is on the same line as a certain unit
     */
    private function isSameLineAs($box, array $units, string $target): bool
    {
        $v = $box->getVertices();
        $centerY = ($v[0]->getY() + $v[2]->getY()) / 2;
        $height = abs($v[2]->getY() - $v[1]->getY());

        foreach ($units as $u) {
            if (strpos(strtolower($u['text']), $target) !== false) {
                $uv = $u['box']->getVertices();
                $ucentery = ($uv[0]->getY() + $uv[2]->getY()) / 2;
                if (abs($centerY - $ucentery) < ($height * 0.8)) return true;
            }
        }
        return false;
    }

    /**
     * Checks if a box is followed by a certain unit (to the right)
     */
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
                
                // Same line and to the right
                if (abs($centerY - $ucentery) < ($height * 1.5) && ($uleftx >= $rightX - ($height * 2))) {
                    return true;
                }
            }
        }
        return false;
    }

    private function parseDigits(string $text, string $meterType = 'unknown'): ?string
    {
        if ($meterType === 'elec' && (strpos($text, '.') !== false || strpos($text, ',') !== false)) {
            if (preg_match('/(\d+)[\.,]\d+\s*kwh/i', $text, $m)) return $m[1];
        }

        $cleanText = str_replace([' ', '.', ','], '', $text);
        if (preg_match_all('/\b\d{5,8}\b/', $cleanText, $matches)) {
            $match = $matches[0][0];
            return (strlen($match) > 5) ? substr($match, 0, 5) : $match;
        }
        return null;
    }

    /**
     * Validates if the detected information matches the expected meter type
     */
    private function validateMeterType(string $allText, array $labels, string $expectedType): array
    {
        $allTextLower = strtolower($allText);
        $valid = true;
        $message = "OK";

        if ($expectedType === 'gas') {
            if (strpos($allTextLower, 'm3') === false && strpos($allText, 'm³') === false) {
                $valid = false;
                $message = "Dit lijkt geen gasmeter te zijn (geen m3 gevonden). Fotografeer de juiste meter.";
            }
        } elseif ($expectedType === 'elec') {
            if (strpos($allTextLower, 'kwh') === false && strpos($allTextLower, 'kw.h') === false) {
                $valid = false;
                $message = "Dit lijkt geen elektrameter te zijn (geen kWh gevonden). Fotografeer de juiste meter.";
            }
        } elseif ($expectedType === 'water') {
            // Very lenient for water: check for ANY water-related keyword OR m3
            if (strpos($allTextLower, 'water') === false && strpos($allTextLower, 'm3') === false && strpos($allText, 'm³') === false) {
                $valid = false;
                $message = "Dit lijkt geen watermeter te zijn. Fotografeer de juiste meter.";
            }
        }

        return ['valid' => $valid, 'message' => $message];
    }

    private function getMockReading(): array
    {
        return [
            'reading' => (string)rand(10000, 99999),
            'meter_number' => 'SN-' . rand(100000, 999999),
            'validation' => [
                'valid' => true,
                'message' => 'OK'
            ]
        ];
    }
}
