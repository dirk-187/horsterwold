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
     * Process a pre-fetched Google Cloud Vision annotation.
     * Used for benchmarking and avoiding duplicate API costs.
     */
    public function detectFromAnnotation($annotation, string $imageContent, string $meterType, array $labels = []): array
    {
        $filename = $labels['filename'] ?? 'unknown';
        $this->logOcrDebug("--- START DETECT (File: $filename, Type: $meterType) ---");
        $results = $this->extractMeterData($annotation, $imageContent, $meterType, $labels);
        $results['validation'] = $this->validateMeterType($annotation->getText(), $labels, $meterType);
        return $results;
    }

    /**
     * Extracts meter reading from FullTextAnnotation based on structural meter patterns
     */
    private function extractMeterData($annotation, string $imageContent, string $meterType, array $labels): array
    {
        $allReadings = [];
        $unitLocations = $this->findUnitLocations($annotation, $meterType);
        
        // Get page dimensions for center-focus calculation
        $page = $annotation->getPages()[0];
        $pageW = $page->getWidth();
        $pageH = $page->getHeight();
        $imgCenter = ['x' => $pageW / 2, 'y' => $pageH / 2];

        $this->logOcrDebug("--- [v3] Starting Line-Aware Structural Extraction for $meterType ---");

        // NEW: Group symbols into logical horizontal AND vertical lines to handle rotated images
        $lineGroups = $this->groupSymbolsIntoLines($annotation, $imageContent);
        
        foreach ($lineGroups as $direction => $lines) {
            $this->logOcrDebug("--- [v3] Testing $direction lines ---");
            foreach ($lines as $lineIdx => $lineSymbols) {
                $n = count($lineSymbols);
                $lineText = implode('', array_column($lineSymbols, 'char'));
                $this->logOcrDebug("Processing $direction line $lineIdx with $n symbols: '$lineText'");
            for ($i = 0; $i < $n; $i++) {
                // Try sequences from length 2 to 10 to support short meter readings
                for ($len = 2; $len <= 10 && ($i + $len) <= $n; $len++) {
                    $sequence = array_slice($lineSymbols, $i, $len);
                    
                    // Calculate metrics for gap check
                    $maxGap = 0;
                    $digitSize = 0;
                    $digitCountInSeq = 0;
                    $nSeq = count($sequence);
                    
                    for ($k = 0; $k < $nSeq; $k++) {
                        if (ctype_digit($sequence[$k]['char'])) {
                            $digitSize += $sequence[$k]['height'];
                            $digitCountInSeq++;
                        }
                        if ($k < $nSeq - 1) {
                            if ($direction === 'horizontal') {
                                $gap = abs($sequence[$k+1]['x'] - $sequence[$k]['x']);
                            } elseif ($direction === 'vertical') {
                                $gap = abs($sequence[$k+1]['y'] - $sequence[$k]['y']);
                            } else {
                                // 2D distance for paragraphs
                                $gap = sqrt(pow($sequence[$k+1]['x'] - $sequence[$k]['x'], 2) + pow($sequence[$k+1]['y'] - $sequence[$k]['y'], 2));
                            }
                            $maxGap = max($maxGap, $gap);
                        }
                    }
                    $avgSize = ($digitCountInSeq > 0) ? ($digitSize / $digitCountInSeq) : 20;
                    
                    // Unified Gap Check: Restore to 5.0, but be slightly more lenient for mixed sequences
                    $gapThreshold = 5.0;
                    if ($digitCountInSeq < $nSeq) $gapThreshold = 5.5; 
                    
                    if ($maxGap > ($avgSize * $gapThreshold)) continue;

                    $candidate = $this->evaluateSequence($sequence, $meterType, $unitLocations);
                    if ($candidate && $candidate['priority'] > self::PRIORITY_NONE) {
                        $candidate['direction'] = $direction; // Store direction for merging
                        $allReadings[] = $candidate;
                    }
                }
            }
        }
    }

        // NEW: Proximity Merging for 4-digit sequences that should be 5
        if ($meterType !== 'elec') {
            $allReadings = $this->applyProximityMerging($allReadings, $annotation, $meterType);
        }

        // NEW: Page-wide height analysis to find the "dominant" digit size (likely the meter)
        $heights = !empty($allReadings) ? array_column($allReadings, 'avgHeight') : [];
        sort($heights);
        $medianHeight = !empty($heights) ? $heights[floor(count($heights) / 2)] : 20;

        $allText = $annotation->getText();
        // Final Sort: Priority first, then Prominence (Size + Center), then Context
        usort($allReadings, function($a, $b) use ($unitLocations, $meterType, $imgCenter, $pageW, $pageH, $allText, $medianHeight) {
            if ($a['priority'] !== $b['priority']) return $b['priority'] <=> $a['priority'];
            
            // Calculate distance from center (normalized 0 to 1)
            $distA = sqrt(pow($a['centerX'] - $imgCenter['x'], 2) + pow($a['centerY'] - $imgCenter['y'], 2)) / sqrt(pow($pageW, 2) + pow($pageH, 2));
            $distB = sqrt(pow($b['centerX'] - $imgCenter['x'], 2) + pow($b['centerY'] - $imgCenter['y'], 2)) / sqrt(pow($pageW, 2) + pow($pageH, 2));
            
            // Prominence Score: Larger height is better, smaller distance is better
            // We give height a very high weight because meter digits are always the largest
            $scoreA = ($a['avgHeight'] * 5) - ($distA * 100);
            $scoreB = ($b['avgHeight'] * 5) - ($distB * 100);
            
            // Digit Length Tie-breaker: Prefer longer sequences for same priority (usually more complete)
            $lenA = strlen($a['text']);
            $lenB = strlen($b['text']);
            if ($lenA !== $lenB) return $lenB <=> $lenA;
 
            if (abs($scoreA - $scoreB) > 5) return $scoreB <=> $scoreA;
 
            // Context check: is it followed by the target unit?
            $targetUnit = ($meterType === 'elec') ? 'kwh' : 'm3';
            $aContext = ($this->isFollowedBy($a['last_box'], $unitLocations, $targetUnit) || $this->isSameLineAs($a['last_box'], $unitLocations, $targetUnit)) ? 1 : 0;
            $bContext = ($this->isFollowedBy($b['last_box'], $unitLocations, $targetUnit) || $this->isSameLineAs($b['last_box'], $unitLocations, $targetUnit)) ? 1 : 0;
            
            if ($aContext !== $bContext) return $bContext <=> $aContext;
 
            // NEW: Penalty check for likely serial numbers
            $penaltyA = $this->calculateCandidatePenalty($a, $allText, $medianHeight ?? 0);
            $penaltyB = $this->calculateCandidatePenalty($b, $allText, $medianHeight ?? 0);
            
            // Blacklisted items are absolute last resort
            if ($penaltyA >= 100 && $penaltyB < 100) return 1;
            if ($penaltyB >= 100 && $penaltyA < 100) return -1;

            if ($penaltyA !== $penaltyB) return $penaltyA <=> $penaltyB; // Lower penalty is better
            
            return $bContext <=> $aContext;
        });

        $readingResult = null;
        if (!empty($allReadings)) {
            // Log top candidates for debugging
            foreach (array_slice($allReadings, 0, 8) as $idx => $c) {
                $penalty = $this->calculateCandidatePenalty($c, $allText, $medianHeight);
                $this->logOcrDebug("Candidate #$idx: {$c['text']} (Pri: {$c['priority']}, Rule: {$c['rule']}, H: " . round($c['avgHeight'], 1) . ", Pen: $penalty)");
            }
            
            $best = $allReadings[0];
            $this->logOcrDebug("Selected BEST structural candidate: " . $best['text'] . " (Priority: " . $best['priority'] . ", Rule: " . ($best['rule'] ?? 'unknown') . ")");
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
        $paragraphGroups = [];
        $pIdx = 0;
        $sIdx = 0;

        foreach ($annotation->getPages() as $page) {
            foreach ($page->getBlocks() as $block) {
                foreach ($block->getParagraphs() as $paragraph) {
                    $pIdx++;
                    foreach ($paragraph->getWords() as $word) {
                        foreach ($word->getSymbols() as $symbol) {
                            $char = $symbol->getText();
                            if (!preg_match('/^[0-9.,:\-]$/', $char)) continue;

                            $box = $symbol->getBoundingBox();
                            $v = $box->getVertices();
                            $yPos = ($v[0]->getY() + $v[2]->getY()) / 2;
                            $xPos = ($v[0]->getX() + $v[2]->getX()) / 2;

                            // Rotation-invariant height (max side length of the bounding box)
                            $side1 = sqrt(pow($v[1]->getX() - $v[0]->getX(), 2) + pow($v[1]->getY() - $v[0]->getY(), 2));
                            $side2 = sqrt(pow($v[2]->getX() - $v[1]->getX(), 2) + pow($v[2]->getY() - $v[1]->getY(), 2));
                            $height = max($side1, $side2);

                            // Pre-calculate colors ONCE per symbol
                            $isRed = $this->isRedOrRedBordered($box, $imageContent);
                            $isBlackBg = $this->isBlackBackground($box, $imageContent);
                            $isWhiteBg = $this->isWhiteBackground($box, $imageContent);

                            $s = [
                                'index' => $sIdx++,
                                'char' => $char,
                                'height' => $height,
                                'area' => $side1 * $side2,
                                'y' => $yPos,
                                'x' => $xPos,
                                'box' => $box,
                                'is_red' => $isRed,
                                'is_black_bg' => $isBlackBg,
                                'is_white_bg' => $isWhiteBg
                            ];
                            $allSymbols[] = $s;
                            $paragraphGroups[$pIdx][] = $s;
                        }
                    }
                }
            }
        }

        return [
            'horizontal' => $this->clusterSymbols($allSymbols, 'y', 'x', 'height'),
            'vertical' => $this->clusterSymbols($allSymbols, 'x', 'y', 'height'),
            'paragraph' => array_values($paragraphGroups)
        ];
    }

    /**
     * Helper to cluster symbols into lines based on a primary axis
     */
    private function clusterSymbols(array $symbols, string $primaryAxis, string $secondaryAxis, string $sizeAxis): array
    {
        // Sort by primary axis first
        usort($symbols, function($a, $b) use ($primaryAxis) {
            return $a[$primaryAxis] <=> $b[$primaryAxis];
        });

        $groups = [];
        foreach ($symbols as $s) {
            $found = false;
            foreach ($groups as &$group) {
                $avgPrimary = array_sum(array_column($group, $primaryAxis)) / count($group);
                $avgSize = array_sum(array_column($group, $sizeAxis)) / count($group);
                
                // Tightened from 0.8 to 0.5 for better line separation
                if (abs($s[$primaryAxis] - $avgPrimary) < ($avgSize * 0.5)) {
                    $group[] = $s;
                    $found = true;
                    break;
                }
            }
            if (!$found) $groups[] = [$s];
        }

        // Preserve original order from Google Vision within each group
        foreach ($groups as &$group) {
            usort($group, function($a, $b) {
                return $a['index'] <=> $b['index'];
            });
        }

        return $groups;
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
        
        $digitCount = 0;
        $digitHeight = 0;
        foreach ($sequence as $s) {
            if (ctype_digit($s['char'])) {
                $digitCount++;
                $digitHeight += $s['height'];
            }
        }
        $avgHeight = ($digitCount > 0) ? ($digitHeight / $digitCount) : ($totalHeight / count($sequence));
        $cleanDigits = preg_replace('/[^0-9]/', '', $text);
        
        $priority = self::PRIORITY_NONE;
        $finalValue = $cleanDigits;
        $ruleName = "";

        // Height consistency check - relaxed for perspective and rotating digits
        // For meters, digits are usually VERY consistent in size.
        $tolerance = 0.65; 
        foreach ($sequence as $s) {
            // Special case: ignore small punctuation for variance
            if (!ctype_digit($s['char'])) continue;
            
            $effectiveTolerance = ($avgHeight < 15) ? ($tolerance * 1.5) : $tolerance;
            if (abs($s['height'] - $avgHeight) > ($avgHeight * $effectiveTolerance)) {
                $this->logOcrDebug("  REJECTED: $text - variance too high (" . round(abs($s['height'] - $avgHeight)/$avgHeight, 2) . ") on '{$s['char']}'");
                return null;
            }
        }
        
        if ($meterType === 'gas') {
            // Rule: Find the transition point (Exactly 4 or 5 black followed by red/red-bordered)
            $transitionPoint = strpos($colors, 'BR');
            if ($transitionPoint === false) $transitionPoint = strpos($colors, 'B?'); // Assume '?' follows B if suspicious

            if ($transitionPoint !== false && $transitionPoint >= 4 && $transitionPoint <= 5) {
                $priority = self::PRIORITY_EXACT;
                $finalValue = substr($cleanDigits, 0, $transitionPoint + 1);
                if (strlen($finalValue) > 5) $finalValue = substr($finalValue, 0, 5);
                $ruleName = "Gas Transition (B->R/Border)";
            } elseif (strlen($cleanDigits) >= 4) {
                // Fallback inside hasRed block
                $priority = self::PRIORITY_PARTIAL;
                $finalValue = (strlen($cleanDigits) >= 5) ? substr($cleanDigits, 0, 5) : $cleanDigits;
                $ruleName = "Gas Mixed (Best Effort)";
            } elseif (strlen($cleanDigits) >= 5 && preg_match('/^B{5}[R?]/', $colors)) {
                // EXPLICIT: 5 black followed by something that is NOT confirmed black
                $priority = self::PRIORITY_EXACT;
                $finalValue = substr($cleanDigits, 0, 5);
                $ruleName = "Gas Truncated (5B + RedBorder)";
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
            } elseif (strlen($cleanDigits) == 5) {
                // EXPLICIT 5-digit gas sequence
                $priority = self::PRIORITY_PARTIAL;
                $finalValue = $cleanDigits;
                $ruleName = "Gas 5-Digit Sequence";
            } elseif (strlen($cleanDigits) >= 4) {
                // NEW: Generic Gas Fallback for 4+ digits
                $priority = self::PRIORITY_FALLBACK;
                $finalValue = (strlen($cleanDigits) >= 5) ? substr($cleanDigits, 0, 5) : $cleanDigits;
                $ruleName = "Gas Generic Fallback";
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
                } elseif (strlen($cleanDigits) >= 4) {
                    // NEW: More lenient water matching. Truncate to 5 if longer.
                    $isContext = $this->isFollowedBy($sequence[count($sequence)-1]['box'], $unitLocations, 'm3');
                    $priority = $isContext ? self::PRIORITY_PARTIAL : self::PRIORITY_FALLBACK;
                    $finalValue = (strlen($cleanDigits) >= 5) ? substr($cleanDigits, 0, 5) : $cleanDigits;
                    $ruleName = $isContext ? "Water Context (m3)" : "Water Structural Fallback";
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
            } elseif (strlen($cleanDigits) >= 4 && $avgHeight > 25) {
                $priority = self::PRIORITY_FALLBACK;
                $finalValue = (strlen($cleanDigits) >= 5) ? substr($cleanDigits, 0, 5) : $cleanDigits;
                $ruleName = "Elec Size Fallback";
            }
        }
        
        // CONTEXT BOOST: If near unit (m3, kwh), boost priority significantly
        $targetUnit = ($meterType === 'elec') ? 'kwh' : 'm3';
        $isNearUnit = ($this->isFollowedBy($sequence[count($sequence)-1]['box'], $unitLocations, $targetUnit) || 
                       $this->isSameLineAs($sequence[0]['box'], $unitLocations, $targetUnit));
        
        if ($isNearUnit && $priority < self::PRIORITY_EXACT && strlen($cleanDigits) >= 4) {
            $priority = self::PRIORITY_EXACT;
            $ruleName .= " + Unit Context ($targetUnit)";
        }

        if ($priority === self::PRIORITY_NONE && strlen($cleanDigits) >= 4 && strlen($cleanDigits) <= 7) {
            // LAST RESORT: If it's a clean sequence of 4-7 digits, give it a low priority
            $priority = self::PRIORITY_FALLBACK;
            $finalValue = $cleanDigits;
            $ruleName = "Generic Digit Sequence (Last Resort)";
        }

        if ($priority === self::PRIORITY_NONE && strlen($cleanDigits) >= 5) {
            $this->logOcrDebug("DISCARDED Candidate: $cleanDigits (Type: $meterType) - No specific rules matched. Pattern: $colors");
        }

        if ($priority > self::PRIORITY_NONE) {
            $v1 = $sequence[0]['box']->getVertices();
            $v2 = $sequence[count($sequence)-1]['box']->getVertices();
            
            // Rotation-invariant area calculation (sum of symbol areas)
            $totalArea = 0;
            foreach ($sequence as $s) {
                $totalArea += ($s['area'] ?? ($s['height'] * $s['height'] * 0.6));
            }
            $area = $totalArea;
            
            // Calculate center point of the whole sequence
            $centerX = ($v1[0]->getX() + $v2[1]->getX()) / 2;
            $centerY = ($v1[0]->getY() + $v2[2]->getY()) / 2;

            return [
                'text' => $finalValue,
                'priority' => $priority,
                'rule' => $ruleName,
                'pattern' => $colors,
                'area' => $area,
                'avgHeight' => $avgHeight,
                'centerX' => $centerX,
                'centerY' => $centerY,
                'last_box' => $sequence[count($sequence)-1]['box'],
                'sequence' => $sequence,
                'is_meter_shaped' => (strpos($finalValue, '0') === 0 || strlen($finalValue) >= 5)
            ];
        }

        return null;
    }

    /**
     * Penalizes likely serial numbers or frame IDs
     */
    private function calculateCandidatePenalty(array $candidate, string $allText, float $medianHeight = 0): int
    {
        $text = $candidate['text'];
        $penalty = 0;
        
        // Penalize known frame numbers and technical patterns
        $blacklist = ['70717', '18000', '32304', '04000', '84125', '23040', '00010', '0011', '10.000', '1.8.0', '1.8.1', '1.8.2', '41258', '25090', '84999', '04927', '07178', '02201', '07493', '00701', '00012', '17082', '20300', '71780', '707178'];
        foreach ($blacklist as $b) {
            if (strpos($text, $b) !== false) $penalty += 150;
        }

        // Penalize if near serial number keywords (Before OR After)
        $keywords = '(SN|Nr|S\/N|SERIENR|TYPE|TYP|KIB|KIWA|WEHRLE|PRESIKHA|ISKRA|ELS|QG|MOD|IP54|LANDIS|GYR|ACTARIS|ELSTER|G10|G16|G4|G6|MADE|BY|B-1.5|M-1.5|QH15|BS|EN1359|Klasse|Class|Temp|bar|mmax|pmax|Qmin|Qmax|V1|V2|imp|dm3|P2|H8|U1)';
        $pattern = '/' . $keywords . '\s*:?\s*([0-9]{4,12})|([0-9]{4,12})\s*:?\s*' . $keywords . '/i';
        if (preg_match($pattern, $allText, $m)) {
            if (strpos($m[0], $text) !== false) $penalty += 100;
        }

        // Penalize multipliers like x0.1, x0.01
        if (preg_match('/x\s*0\.[0-9]+/', $allText)) {
             if ($text === '01' || $text === '001') $penalty += 50;
        }

        // Penalty for sequences that are substrings of a longer numeric sequence (likely serial)
        if (preg_match('/[0-9]{7,15}/', $allText, $matches)) {
            if (strpos($matches[0], $text) !== false) $penalty += 50;
        }

        // Bonus for "meter-shaped" sequences (starts with 0, common for gas/water)
        if (strpos($text, '0') === 0) $penalty -= 25;

        // Relative Size Penalty.
        if ($medianHeight > 0 && $candidate['avgHeight'] < ($medianHeight * 0.6)) {
            $penalty += 30;
        }
        if ($candidate['avgHeight'] < 12) $penalty += 40;

        return $penalty;
    }

    /**
     * Proximity Merging: Look for a single digit near a 4-digit sequence
     */
    private function applyProximityMerging(array $readings, $annotation, string $meterType): array
    {
        $allSymbols = [];
        foreach ($annotation->getPages() as $page) {
            foreach ($page->getBlocks() as $block) {
                foreach ($block->getParagraphs() as $paragraph) {
                    foreach ($paragraph->getWords() as $word) {
                        foreach ($word->getSymbols() as $symbol) {
                            $char = $symbol->getText();
                            if (ctype_digit($char)) {
                                $box = $symbol->getBoundingBox();
                                $v = $box->getVertices();
                                $allSymbols[] = [
                                    'char' => $char,
                                    'box' => $box,
                                    'x' => ($v[0]->getX() + $v[2]->getX()) / 2,
                                    'y' => ($v[0]->getY() + $v[2]->getY()) / 2,
                                    'h' => max(abs($v[2]->getY() - $v[1]->getY()), abs($v[1]->getX() - $v[0]->getX()))
                                ];
                            }
                        }
                    }
                }
            }
        }

        $newReadings = $readings;
        foreach ($readings as $idx => $r) {
            if (strlen($r['text']) === 4) {
                $lastBox = $r['last_box']->getVertices();
                $h = max($r['avgHeight'], 15); // Enforce a minimum h for searching
                $dir = $r['direction'] ?? 'horizontal';

                // Direction-aware center/edge points
                $lastX = ($lastBox[1]->getX() + $lastBox[2]->getX()) / 2;
                $lastY = ($lastBox[1]->getY() + $lastBox[2]->getY()) / 2;
                if ($dir === 'vertical') {
                    $lastX = ($lastBox[2]->getX() + $lastBox[3]->getX()) / 2;
                    $lastY = ($lastBox[2]->getY() + $lastBox[3]->getY()) / 2;
                }

                $bestMatch = null;
                $minDist = 9999;

                foreach ($allSymbols as $s) {
                    $char = $s['char'];
                    // Allow merging with digits that are part of short sequences (e.g. '34' -> merge '3')
                    
                    $alreadyIn = false;
                    foreach ($r['sequence'] as $seqSymbol) {
                        if (abs($s['x'] - $seqSymbol['x']) < ($h * 0.2) && abs($s['y'] - $seqSymbol['y']) < ($h * 0.2)) {
                            $alreadyIn = true; break;
                        }
                    }
                    if ($alreadyIn) continue;

                    $dx = $s['x'] - $lastX;
                    $dy = $s['y'] - $lastY;

                    $isNearby = false;
                    if ($dir === 'horizontal') {
                        // Wide to the right, moderate vertical tolerance for rotation
                        if ($dx > (-$h * 0.4) && $dx < ($h * 3.2) && abs($dy) < ($h * 1.5)) $isNearby = true;
                    } elseif ($dir === 'vertical') {
                        // Wide down, moderate horizontal tolerance for rotation
                        if ($dy > (-$h * 0.4) && $dy < ($h * 3.2) && abs($dx) < ($h * 2.2)) $isNearby = true;
                    } else {
                        // Paragraph (2D)
                        if (sqrt($dx*$dx + $dy*$dy) < ($h * 4.0)) $isNearby = true;
                    }

                    if ($isNearby) {
                        $dist = sqrt($dx*$dx + $dy*$dy);
                        if ($dist < $minDist) {
                            $minDist = $dist;
                            $bestMatch = $s;
                        }
                    } else {
                        // Optional: log if very close but rejected by direction logic
                        if (sqrt($dx*$dx + $dy*$dy) < ($h * 3.5)) {
                            $this->logOcrDebug("  Merge REJECTED: '{$s['char']}' (dx: " . round($dx, 1) . ", dy: " . round($dy, 1) . ", h: " . round($h, 1) . ", dir: $dir)");
                        }
                    }
                }

                if ($bestMatch) {
                    $this->logOcrDebug("PROXIMITY MERGE SUCCESS: Glued '{$bestMatch['char']}' to '{$r['text']}' in $dir line (dist: " . round($minDist, 1) . "px, h: " . round($h, 1) . ")");
                    $newReadings[$idx]['text'] .= $bestMatch['char'];
                    $newReadings[$idx]['priority'] = self::PRIORITY_EXACT;
                    $newReadings[$idx]['rule'] .= " + Proximity Merge";
                    $newReadings[$idx]['sequence'][] = $bestMatch;
                }
            }
        }
        return $newReadings;
    }

    private function findUnitLocations($annotation, string $meterType): array
    {
        $targetUnits = ($meterType === 'elec') ? ['kwh', 'kw.h', 'kvarh'] : ['m3', 'm³', 'm3/h'];
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
        if (!empty($locations)) {
            $unitText = implode(', ', array_map(function($l) { return $l['text']; }, $locations));
            $this->logOcrDebug("Found unit markers: $unitText");
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
            $step = max(1, ($x2-$x1)/10);
            
            // INCREASED MARGIN: Sample 4 pixels beyond the OCR box to catch red outlines
            $margin = 4;
            for ($x = max(0, $x1 - $margin); $x < min(imagesx($im), $x2 + $margin); $x += $step) {
                for ($y = max(0, $y1 - $margin); $y < min(imagesy($im), $y2 + $margin); $y += $step) {
                    $rgb = imagecolorat($im, (int)$x, (int)$y);
                    $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                    
                    // Slightly more sensitive for outlines in darker images
                    if ($r > 70 && $r > $g + 25 && $r > $b + 25) {
                        $redCount++;
                    }
                    $totalPoints++;
                }
            }
            imagedestroy($im);
            // Threshold lower for outlines because they cover less total area
            return ($totalPoints > 0) && ($redCount / $totalPoints) > 0.08;
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
