<?php
/**
 * OcrService — Handles Google Cloud Vision API integration
 * V4 - Simplified Reset (Focus on Structural Integrity and Gas Meters)
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
    public const PRIORITY_EXACT = 100;
    public const PRIORITY_PARTIAL = 50;
    public const PRIORITY_FALLBACK = 10;
    public const PRIORITY_NONE = 0;

    public function __construct()
    {
        $this->keyFilePath = defined('GOOGLE_KEY_FILE') ? GOOGLE_KEY_FILE : '';
    }

    /**
     * Detect text in an image and extract meter reading
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
            $imageAnnotator = new ImageAnnotatorClient(['credentials' => $this->keyFilePath]);

            // Request both Document Text Detection and Label Detection
            $features = [
                (new Feature())->setType(Type::DOCUMENT_TEXT_DETECTION),
                (new Feature())->setType(Type::LABEL_DETECTION)
            ];

            $response = $imageAnnotator->annotateImage($imageContent, $features);
            $fullAnnotation = $response->getFullTextAnnotation();
            
            $labels = [];
            foreach ($response->getLabelAnnotations() as $label) {
                $labels[] = strtolower($label->getDescription());
            }

            if (!$fullAnnotation) {
                $imageAnnotator->close();
                return null;
            }

            $labels['all_text'] = $fullAnnotation->getText();
            $results = $this->extractMeterData($fullAnnotation, $imageContent, $meterType, $labels);
            $results['validation'] = $this->validateMeterType($fullAnnotation->getText(), $labels, $meterType);
            
            $imageAnnotator->close();
            return $results;

        } catch (Exception $e) {
            error_log("OCR Error: " . $e->getMessage());
            throw new Exception("Fout bij het uitlezen van de meter: " . $e->getMessage());
        }
    }

    /**
     * Process a pre-fetched Google Cloud Vision annotation (Benchmarking)
     */
    public function detectFromAnnotation($annotation, string $imageContent, string $meterType, array $labels = []): array
    {
        $filename = $labels['filename'] ?? 'unknown';
        $allText = $annotation->getText();
        
        $modelData = $this->identifyMeterModel($allText, $labels);
        $labels['meter_model'] = $modelData['model'];
        $labels['detected_type'] = $modelData['type'];
        $labels['current_test_type'] = $meterType;

        $this->logOcrDebug("--- START DETECT (File: $filename, Type: $meterType, Model: {$modelData['model']}, Detected: {$modelData['type']}) ---");
        
        $labels['all_text'] = $allText;
        $results = $this->extractMeterData($annotation, $imageContent, $meterType, $labels);
        $results['validation'] = $this->validateMeterType($allText, $labels, $meterType);
        $results['meter_model'] = $modelData['model'];
        
        return $results;
    }

    /**
     * Extracts meter reading using structural patterns and context
     */
    private function extractMeterData($annotation, string $imageContent, string $meterType, array $labels): array
    {
        $allReadings = [];
        $unitLocations = $this->findUnitLocations($annotation, $meterType);
        
        $page = $annotation->getPages()[0];
        $pageW = $page->getWidth();
        $pageH = $page->getHeight();
        $imgCenter = ['x' => $pageW / 2, 'y' => $pageH / 2];

        $lines = $this->groupSymbolsIntoParagraphs($annotation, $imageContent);
        
        $allSymbols = [];
        foreach ($lines as $line) $allSymbols = array_merge($allSymbols, $line);

        // Pre-calculate median height for the whole page
        $allHeights = array_column($allSymbols, 'height');
        sort($allHeights);
        $medianHeight = !empty($allHeights) ? $allHeights[floor(count($allHeights) / 2)] : 20;

        foreach ($lines as $lineIdx => $symbols) {
            $lineText = '';
            foreach ($symbols as $s) $lineText .= $s['char'];
            
            // Filter symbols for sequence building (only digits, dots, commas)
            $candidateSymbols = array_values(array_filter($symbols, function($s) {
                return $s['is_candidate'];
            }));
            
            $n = count($candidateSymbols);
            
            // 2. Test sequences within the filtered line
            for ($i = 0; $i < $n; $i++) {
                for ($len = 3; $len <= 10 && ($i + $len) <= $n; $len++) {
                    $sequence = array_slice($candidateSymbols, $i, $len);
                    
                    // Basic Gap Check (ensure they belong together)
                    if (!$this->isConsistentSequence($sequence, $symbols)) {
                        $txt = '';
                        foreach($sequence as $s) $txt .= $s['char'];
                        $digits = preg_replace('/[^0-9]/', '', $txt);
                        if (strlen($digits) === 8) {
                             $this->logOcrDebug("Rejected: $txt (Inconsistent Gap)");
                        }
                        continue;
                    }

                    $lineText = '';
                    foreach ($symbols as $s) $lineText .= $s['char'];
                    
                    $lineLabels = array_merge($labels, [
                        'line_total_symbols' => $n, 
                        'median_height' => $medianHeight,
                        'line_text' => $lineText
                    ]);
                    $lineLabels['unit_locations'] = $unitLocations;
                    $candidate = $this->evaluateSequence($sequence, $meterType, $annotation->getText(), (float)$medianHeight, $lineLabels);
                        if ($candidate && $candidate['priority'] > self::PRIORITY_NONE) {
                            $beforeText = '';
                            foreach ($symbols as $s) {
                                if ($s['index'] === $sequence[0]['index']) break;
                                $beforeText .= $s['char'];
                            }
                            $candidate['beforeText'] = $beforeText;
                            
                            // Boost if it's at the start of a clear line/paragraph
                            if (empty(trim($beforeText)) && !preg_match('/(nr|sn|no|g4)/i', $beforeText)) {
                                $candidate['priority'] += 5;
                                $candidate['rule'] .= " (Start-of-Line Boost)";
                            }

                            // Decimal-aware Long-Line Penalty: 
                            if ($n > $len) {
                                $isDecimalFollowup = false;
                                if ($len === 5 && $n >= 8 && $i === 0) {
                                    $followupColors = '';
                                    for ($k = 5; $k < min($n, 10); $k++) $followupColors .= $symbols[$k]['is_red'] ? 'R' : 'B';
                                    if (strpos($followupColors, 'R') !== false) $isDecimalFollowup = true;
                                }
                                
                                if (!$isDecimalFollowup) {
                                    $priorityLoss = ($n - $len) * 5;
                                    $candidate['priority'] = max(10, $candidate['priority'] - $priorityLoss);
                                    $candidate['rule'] .= " (Long-Line Penalty)";
                                }
                            }
                            $allReadings[] = $candidate;
                        } else {
                            $txt = '';
                            foreach($sequence as $s) $txt .= $s['char'];
                            $digits = preg_replace('/[^0-9]/', '', $txt);
                            if (strlen($digits) === 8 || strlen($digits) === 5) {
                                $this->logOcrDebug("Rejected: $txt (Priority 0)");
                            }
                        }
                }
            }
        }

        // 3. Optional: Proximity Merging (Glues a nearby digit to a 4-digit sequence)
        $allReadings = $this->applyProximityMerging($allReadings, $annotation, $meterType);

        // 4. Deduplicate candidates (same text)
        // Filter overlapping candidates (if one sequence is a subset of another)
        usort($allReadings, function($a, $b) {
            if (strlen($b['text']) !== strlen($a['text'])) return strlen($b['text']) <=> strlen($a['text']);
            return $b['priority'] <=> $a['priority'];
        });
        
        $filteredReadings = [];
        foreach ($allReadings as $reading) {
            $isSubset = false;
            foreach ($filteredReadings as $existing) {
                // If this is a substring of an existing better candidate, skip it
                if ($reading['priority'] <= $existing['priority'] && strpos($existing['text'], $reading['text']) !== false) {
                    $isSubset = true;
                    break;
                }
                // Special case: overlapping windows from the same line and same length
                // Keep only the one with higher priority
                if (strlen($reading['text']) === strlen($existing['text']) && 
                    abs($reading['centerY'] - $existing['centerY']) < 5 && 
                    $reading['priority'] < $existing['priority']) {
                    $isSubset = true;
                    break;
                }
            }
            if (!$isSubset) {
                $filteredReadings[] = $reading;
            }
        }
        $allReadings = $filteredReadings;

        // Keep the one with the highest priority and lowest penalty
        $uniqueReadings = [];
        foreach ($allReadings as $reading) {
            $txt = $reading['text'];
            if (!isset($uniqueReadings[$txt]) || $reading['priority'] > $uniqueReadings[$txt]['priority']) {
                $uniqueReadings[$txt] = $reading;
            }
        }
        $allReadings = array_values($uniqueReadings);

        // 5. Ranking

        usort($allReadings, function($a, $b) use ($unitLocations, $meterType, $imgCenter, $pageW, $pageH, $medianHeight, $labels, $annotation) {
            $penaltyA = $this->calculateCandidatePenalty($a, $meterType, $annotation->getText(), (float)$medianHeight, $labels);
            $penaltyB = $this->calculateCandidatePenalty($b, $meterType, $annotation->getText(), (float)$medianHeight, $labels);
            
            // Integrated Score: Priority minus a factor of the penalty
            $scoreA = $a['priority'] - ($penaltyA / 10.0);
            $scoreB = $b['priority'] - ($penaltyB / 10.0);
            
            if (abs($scoreA - $scoreB) > 0.1) return $scoreB <=> $scoreA;
            
            // Tie-breaker 1: Prefer longer text (prevents picking substrings)
            if (strlen($a['text']) !== strlen($b['text'])) {
                return strlen($b['text']) - strlen($a['text']);
            }
            
            // Tie-breaker 2: Distance to center
            $distA = sqrt(pow($a['centerX'] - $imgCenter['x'], 2) + pow($a['centerY'] - $imgCenter['y'], 2));
            $distB = sqrt(pow($b['centerX'] - $imgCenter['x'], 2) + pow($b['centerY'] - $imgCenter['y'], 2));
            
            if (abs($a['avgHeight'] - $b['avgHeight']) > 2) return $b['avgHeight'] <=> $a['avgHeight'];
            
            return $distA <=> $distB;
        });

        // Log Top 5 for debugging
        foreach (array_slice($allReadings, 0, 5) as $idx => $cand) {
            $penalty = $this->calculateCandidatePenalty($cand, $meterType, $annotation->getText(), (float)$medianHeight, $labels);
            $this->logOcrDebug("Candidate #$idx: {$cand['text']} (Pri: {$cand['priority']}, Pen: $penalty, Rule: {$cand['rule']})");
        }

        $best = !empty($allReadings) ? $allReadings[0] : null;

        if ($best) {
            $this->logOcrDebug("Selected BEST: {$best['text']} (Rule: {$best['rule']}, Pri: {$best['priority']})");
        }

        return [
            'reading' => $best['text'] ?? null,
            'rule' => $best['rule'] ?? 'unknown',
            'all_readings' => array_slice($allReadings, 0, 10),
            'meter_model' => $labels['meter_model'] ?? 'GENERIC'
        ];
    }

    private function groupSymbolsIntoParagraphs($annotation, string $imageContent): array
    {
        $lines = [];
        $sIdx = 0;
        foreach ($annotation->getPages() as $page) {
            foreach ($page->getBlocks() as $block) {
                foreach ($block->getParagraphs() as $paragraph) {
                    $lineSymbols = [];
                    $wIdx = 0;
                    foreach ($paragraph->getWords() as $word) {
                        foreach ($word->getSymbols() as $symbol) {
                            $char = $symbol->getText();
                            $isCandidate = preg_match('/^[0-9.,]$/', $char);

                            $box = $symbol->getBoundingBox();
                            $v = $box->getVertices();
                            
                            // Rotation-invariant height
                            $side1 = sqrt(pow($v[1]->getX() - $v[0]->getX(), 2) + pow($v[1]->getY() - $v[0]->getY(), 2));
                            $side2 = sqrt(pow($v[2]->getX() - $v[1]->getX(), 2) + pow($v[2]->getY() - $v[1]->getY(), 2));
                            $height = max($side1, $side2);

                            $symbolData = [
                                'index' => $sIdx++,
                                'index_in_line' => $wIdx++,
                                'char' => $char,
                                'height' => $height,
                                'x' => ($v[0]->getX() + $v[2]->getX()) / 2,
                                'y' => ($v[0]->getY() + $v[2]->getY()) / 2,
                                'box' => $box,
                                'is_red' => $this->isRedOrRedBordered($box, $imageContent),
                                'is_black_bg' => $this->isBlackBackground($box, $imageContent),
                                'is_candidate' => $isCandidate
                            ];
                            $lineSymbols[] = $symbolData;
                        }
                    }
                    if (!empty($lineSymbols)) {
                        // Filter out lines that have NO digits at all to save processing
                        $hasDigits = false;
                        foreach($lineSymbols as $ls) if ($ls['is_candidate']) { $hasDigits = true; break; }
                        if ($hasDigits) $lines[] = $lineSymbols;
                    }
                }
            }
        }
        return $lines;
    }

    private function isConsistentSequence(array $sequence, array $allParagraphSymbols = []): bool
    {
        $n = count($sequence);
        if ($n < 2) return true;
        
        for ($i = 0; $i < $n - 1; $i++) {
            // Check for intervening non-digit symbols in the original paragraph
            if (!empty($allParagraphSymbols)) {
                $idx1 = -1; $idx2 = -1;
                foreach ($allParagraphSymbols as $idx => $s) {
                    if ($s === $sequence[$i]) $idx1 = $idx;
                    if ($s === $sequence[$i+1]) $idx2 = $idx;
                }
                if ($idx1 !== -1 && $idx2 !== -1 && $idx2 > $idx1 + 1) {
                    // Check if any intervening symbol is NOT a digit/dot/comma
                    for ($j = $idx1 + 1; $j < $idx2; $j++) {
                        $char = $allParagraphSymbols[$j]['char'];
                        if (!preg_match('/[0-9\.,]/', $char)) {
                            return false; // Break at other symbols
                        }
                    }
                }
            }

            $gap = sqrt(pow($sequence[$i+1]['x'] - $sequence[$i]['x'], 2) + pow($sequence[$i+1]['y'] - $sequence[$i]['y'], 2));
            $h = $sequence[$i]['height'];
            
            // Default tight gap for consistent digits
            $multiplier = 5.0; 
            
            // Allow larger gaps specifically for black-to-red transitions (different windows)
            if ($sequence[$i]['is_black_bg'] && $sequence[$i+1]['is_red']) {
                $multiplier = 10.0;
            }
            
            if ($gap > ($h * $multiplier)) return false;
        }
        return true;
    }

    private function evaluateSequence(array $sequence, string $meterType, string $allText, float $medianHeight, array $labels = []): ?array
    {
        $text = '';
        $colors = '';
        $totalHeight = 0;
        foreach ($sequence as $s) {
            $text .= $s['char'];
            $colors .= ($s['is_red'] ? 'R' : 'B');
            $totalHeight += $s['height'];
        }
        $avgHeight = $totalHeight / count($sequence);
        $onlyDigits = preg_replace('/[^0-9]/', '', $text);
        
        $finalValue = $onlyDigits;
        $priority = 0;
        $rule = "unknown";

        // Gas Rules
        if ($meterType === 'gas') {
            if (strlen($onlyDigits) >= 5) {
                $redPos = strpos($colors, 'R');
                if ($redPos !== false) {
                    $finalValue = substr($onlyDigits, 0, $redPos);
                    if (strlen($finalValue) > 5) $finalValue = substr($finalValue, -5);
                    $priority = self::PRIORITY_EXACT + 20;
                    $rule = "Gas Long Sequence (Red Anchored)";
                } else {
                    $finalValue = substr($onlyDigits, 0, 5);
                    $priority = self::PRIORITY_EXACT;
                    $rule = "Gas Long Sequence (First 5)";
                }
            } elseif (strlen($onlyDigits) === 4) {
                $priority = self::PRIORITY_PARTIAL + 40;
                $rule = "Gas 4-Digit Standard";
            }
            if (preg_match('/bk-g|elster|itron|g4|g6/i', $allText)) {
                $priority += 30;
                $rule .= " (Gas Context Boost)";
            }
        }
        // Water Rules
        elseif ($meterType === 'water') {
            if (strlen($onlyDigits) === 5) {
                 $priority = self::PRIORITY_EXACT + 20;
                 $rule = "Water 5-Digit Standard";
            } elseif (strlen($onlyDigits) >= 6) {
                $redPos = strpos($colors, 'R');
                if ($redPos !== false && $redPos >= 3) {
                     $finalValue = substr($onlyDigits, 0, $redPos);
                     $priority = self::PRIORITY_EXACT + 10;
                     $rule = "Water Long Sequence (Red Anchored)";
                } else {
                    $finalValue = substr($onlyDigits, 0, 5);
                    $priority = self::PRIORITY_PARTIAL;
                    $rule = "Water Long Sequence (First 5)";
                }
            }
            if ($priority > 0 && preg_match('/presikhaaf|wehrle|sensus|itron|diehl|zenner|b-meters|m³|m3|liter|m/i', $allText)) {
                $priority += 40;
                $rule .= " (Water Context Boost)";
            }
        }
        // Elec Rules
        elseif ($meterType === 'elec') {
            $digitCount = strlen($onlyDigits);
            if ($digitCount >= 4 && $digitCount <= 10) {
                $priority = self::PRIORITY_EXACT;
                $rule = "Elec Digit Sequence";
                
                $redPos = strpos($colors, 'R');
                if ($redPos !== false && $redPos >= 4) {
                    $finalValue = substr($onlyDigits, 0, $redPos);
                    $priority = self::PRIORITY_EXACT + 40;
                    $rule = "Elec Red-Anchored Decimal";
                }
                
                $dotPos = strpos($text, '.');
                if ($dotPos === false) $dotPos = strpos($text, ',');
                if ($dotPos !== false && $dotPos >= 3) {
                    $finalValue = substr($onlyDigits, 0, $dotPos);
                    $priority = self::PRIORITY_EXACT + (in_array($dotPos, [5, 6]) ? 60 : 40); 
                    $rule = "Elec Dot-Anchored Decimal";
                }
                
                if ($digitCount === 5) $priority += 25;
                if ($digitCount === 6) $priority += 15;
                
                if (($labels['meter_model'] ?? '') === 'ISKRA') {
                    $hasIndicatorInLine = preg_match('/1\.?8\.?[0-9]/i', $labels['line_text'] ?? '');
                    if (preg_match('/^1\.?8\.?[0-9]/i', $text) || preg_match('/^18[0-9]/i', $text)) {
                         $priority -= 200; // Strong indicator penalty
                         $rule .= " (Iskra Indicator)";
                    } elseif ($hasIndicatorInLine) {
                         $priority += 180;
                         $rule .= " (Iskra Near Indicator)";
                    }
                    
                    // Handle 7.1 or 8.1 notation (Whole part only)
                    if (preg_match('/^[0-9]{5,8}[.,][0-9]$/', $text)) {
                        $finalValue = substr($onlyDigits, 0, -1);
                        $priority += 60;
                        $rule .= " (Iskra Decimal Logic)";
                    }

                    if ($digitCount >= 6 && $digitCount <= 8) {
                        $priority += 30;
                        $rule .= " (Iskra Length Boost)";
                    }
                }
                
                // Baylan Exception: 3 decimals are standard
                if (($labels['meter_model'] ?? '') === 'BAYLAN' && $digitCount >= 6) {
                    $finalValue = substr($onlyDigits, 0, $digitCount - 3);
                    $priority += 40;
                    $rule .= " (Baylan 3-Dec Rule)";
                }

                if (strpos($onlyDigits, '000') === 0) $priority += 20;
                elseif (strpos($onlyDigits, '00') === 0) $priority += 15;
            }
        }

        if ($medianHeight > 0 && $avgHeight > $medianHeight * 1.5) {
            $priority += 40;
            $rule .= " (Major Height Boost)";
        }

        if (!empty($sequence) && ($sequence[0]['index_in_line'] ?? 0) < 2) {
            $priority += 15;
            $rule .= " (Start-of-Line Boost)";
        }

        if ($priority > self::PRIORITY_NONE) {
            $v1 = $sequence[0]['box']->getVertices();
            $v2 = $sequence[count($sequence)-1]['box']->getVertices();
            $candidate = [
                'text' => $finalValue,
                'beforeText' => '',
                'priority' => $priority,
                'rule' => $rule,
                'avgHeight' => $avgHeight,
                'centerX' => ($v1[0]->getX() + $v2[1]->getX()) / 2,
                'centerY' => ($v1[0]->getY() + $v2[2]->getY()) / 2,
                'sequence' => $sequence,
                'line_text' => $labels['line_text'] ?? '',
                'median_height' => $medianHeight
            ];
            $candidate['penalty'] = $this->calculateCandidatePenalty($candidate, $meterType, $allText, $medianHeight, $labels);
            $candidate['priority'] -= $candidate['penalty'];
            return $candidate;
        }

        return null;
    }

    public function calculateCandidatePenalty(array $candidate, string $meterType, string $allText, float $medianHeight, array $labels = []): int
    {
        $penalty = 0;
        $text = $candidate['text'];
        $avgH = $candidate['avgHeight'];
        $detectedType = $labels['detected_type'] ?? 'unknown';

        // 0. Type Mismatch Bias
        if ($detectedType !== 'unknown' && $meterType !== 'unknown' && $detectedType !== $meterType) {
            $penalty += 300; 
        }
        
        // 0b. Standard Numbers Penalty
        if (preg_match('/(1359|62053|4064|2003|2024|3x230|400v|50hz|bk-g|g2\.5|g4|q3|r80|r160|cem22|cem21|0071)/i', $text)) {
            $penalty += 400;
        }

        // 1. Height penalty
        $medH = $candidate['median_height'] ?? $medianHeight;
        if ($medH > 0) {
            if ($avgH < $medH * 0.6) {
                $penalty += 300; 
            } elseif ($avgH > $medH * 4.0 && $candidate['priority'] < 140) {
                $penalty += 150;
            }
        }

        // 2. Gas Logic
        if ($meterType === 'gas') {
            if (preg_match('/(t[12]|l[123]|fazant|landis|kaifa|kamstrup)/i', $allText)) {
                $penalty += 400; 
            }
        }
        
        // 0c. Technical label penalty (Same line check)
        $lineText = $labels['line_text'] ?? '';
        if (preg_match('/(nr|sn|type|model|g4|qmax|zri|zr1|mid|q3|r40|t50|cem|sku|no\.|s\/n|q2\.5|r100h|uodo|16bar|class)/i', $lineText)) {
            $penalty += 300;
        }
        
        // Small digit indicator penalty (Technical labels are often smaller)
        if ($medH > 0 && $avgH < $medH * 0.7 && strlen($text) >= 5) {
            $penalty += 200;
        }

        return $penalty;
    }

    public function identifyMeterModel(string $allText, array $labels): array
    {
        $text = strtolower($allText);
        $model = 'GENERIC';
        $type = 'unknown';

        if (strpos($text, 'elster') !== false) $model = 'ELSTER';
        elseif (strpos($text, 'itron') !== false) $model = 'ITRON';
        elseif (strpos($text, 'wehrle') !== false) $model = 'WEHRLE';
        elseif (strpos($text, 'zenner') !== false) $model = 'ZENNER';
        elseif (strpos($text, 'landis') !== false) $model = 'LANDIS';
        elseif (strpos($text, 'kaifa') !== false) $model = 'KAIFA';
        elseif (strpos($text, 'kamstrup') !== false) $model = 'KAMSTRUP';
        elseif (strpos($text, 'presikhaaf') !== false) $model = 'PRESIKHAAF';
        elseif (strpos($text, 'sensus') !== false) $model = 'SENSUS';
        elseif (strpos($text, 'diehl') !== false) $model = 'DIEHL';
        elseif (strpos($text, 'b meters') !== false || strpos($text, 'b-meters') !== false) $model = 'BMETERS';
        elseif (strpos($text, 'hager') !== false) $model = 'HAGER';
        elseif (strpos($text, 'eaton') !== false) $model = 'EATON';
        elseif (strpos($text, 'abb') !== false) $model = 'ABB';
        elseif (strpos($text, 'schneider') !== false) $model = 'SCHNEIDER';
        elseif (strpos($text, 'iskraemeco') !== false) $model = 'ISKRAEMECO';
        elseif (strpos($text, 'dzm') !== false) $model = 'DZM';
        elseif (strpos($text, 'b-meters') !== false) $model = 'BMETERS';
        elseif (strpos($text, 'baylan') !== false) $model = 'BAYLAN';
        elseif (strpos($text, 'iskra') !== false || strpos($text, 'skra') !== false) $model = 'ISKRA';

        // High-confidence indicators
        $hasKwh = preg_match('/\b(kwh|kw h|230v|400v|imp\/kwh|tariff|fazant|en62053|active energy|energy|drehstrom|t[1-4]|l[1-3]|iskra|skra|kranj|hager|eaton|abb|schneider|iskraemeco|stromzähler|leistung|energie|baylan)\b/iu', $text);
        $hasWater = preg_match('/\b(qn|q3|r160|r80|liters|m3\/h|watermeter|warmwater|navarch|presikhaaf|wehrle|sensus|itron|diehl|zenner|m001|pt800|mnk|zri|rich)\b/iu', $text);
        $hasGas = preg_match('/\b(bk-g|g4|g6|g2\.5|1359|en1359|qmax|qmin|gas|schröder|elkro)\b/iu', $text);
        $hasM3 = preg_match('/(m3|m\x{00b3})/iu', $text);

        if ($hasKwh) {
            $type = 'elec';
        } elseif ($hasGas) {
            $type = 'gas';
        } elseif ($hasWater) {
            $type = 'water';
        } elseif ($hasM3) {
            // If we have m3 but no explicit gas indicators, check for water brands again
            if ($model === 'WEHRLE' || $model === 'ZENNER' || $model === 'SENSUS' || $model === 'PRESIKHAAF' || $model === 'ITRON') {
                $type = 'water';
            } else {
                $type = 'gas';
            }
        }

        $this->logOcrDebug("Type Detection: kw:$hasKwh, w:$hasWater, g:$hasGas, m3:$hasM3 -> Detected: $type");

        return ['model' => $model, 'type' => $type];
    }

    private function applyProximityMerging(array $readings, $annotation, string $meterType): array
    {
        // Placeholder for proximity merging logic if needed later
        return $readings;
    }
    private function findUnitLocations($annotation, string $meterType): array
    {
        $targetRegex = ($meterType === 'elec') ? '/kwh/i' : '/m[3³]/i';
        $locations = [];
        $text = strtolower($annotation->getText());
        if (preg_match($targetRegex, $text)) {
            // Find specific boxes for these units
            foreach ($annotation->getPages() as $page) {
                foreach ($page->getBlocks() as $block) {
                    foreach ($block->getParagraphs() as $paragraph) {
                        foreach ($paragraph->getWords() as $word) {
                            $symbols = $word->getSymbols();
                            $wText = '';
                            foreach($symbols as $s) $wText .= $s->getText();
                            if (preg_match($targetRegex, $wText)) {
                                $locations[] = ['box' => $word->getBoundingBox(), 'text' => $wText];
                            }
                        }
                    }
                }
            }
        }
        return $locations;
    }

    private function isNearUnit($box, array $unitLocations, string $targetType): bool
    {
        $targetRegex = ($targetType === 'elec') ? '/(kwh|kw h)/i' : '/(m[3³]|liter|^m$)/i';
        $v1 = $box->getVertices();
        $y1 = ($v1[0]->getY() + $v1[2]->getY()) / 2;
        $x1 = ($v1[0]->getX() + $v1[1]->getX()) / 2;
        $h1 = abs($v1[2]->getY() - $v1[0]->getY());

        foreach ($unitLocations as $unit) {
            if (preg_match($targetRegex, $unit['text'])) {
                $v2 = $unit['box']->getVertices();
                $y2 = ($v2[0]->getY() + $v2[2]->getY()) / 2;
                $x2 = ($v2[0]->getX() + $v2[1]->getX()) / 2;
                
                $dy = abs($y1 - $y2);
                $dx = abs($x1 - $x2);

                // Reading is usually to the LEFT of the unit (m3/kwh)
                // Same horizontal line, unit is to the right
                if ($dy < $h1 * 1.5 && $x2 > $x1 && $dx < $h1 * 8.0) {
                    return true;
                }
                
                // Fallback: very close in any direction
                $dist = sqrt(pow($dx, 2) + pow($dy, 2));
                if ($dist < $h1 * 4.0) return true;
            }
        }
        return false;
    }

    private function validateMeterType(string $allText, array $labels, string $expectedType): array
    {
        return ['valid' => true, 'message' => 'OK'];
    }

    private function isRedOrRedBordered($box, string $imageContent): bool
    {
        if (empty($imageContent)) return false;
        
        static $lastImgContent = null;
        static $img = null;
        if ($lastImgContent !== $imageContent) {
            if ($img) imagedestroy($img);
            $img = @imagecreatefromstring($imageContent);
            $lastImgContent = $imageContent;
        }
        if (!$img) return false;

        $v = $box->getVertices();
        $x1 = $v[0]->getX(); $y1 = $v[0]->getY();
        $x2 = $v[2]->getX(); $y2 = $v[2]->getY();
        
        $width = imagesx($img);
        $height = imagesy($img);

        // Sample points strictly INSIDE the bounding box to avoid hitting adjacent digits
        // Google Vision's boxes are usually very tight around the symbol.
        // We sample near the edges and the center.
        $w = abs($x2 - $x1);
        $h_box = abs($y2 - $y1);
        $minX = min($x1, $x2);
        $minY = min($y1, $y2);

        $points = [
            ['x' => $minX + $w * 0.5, 'y' => $minY + $h_box * 0.5], // Center
            // Margins (8 points) - Slightly more conservative to avoid neighbor bleed
            ['x' => $minX - $w * 0.1, 'y' => $minY + $h_box * 0.5], 
            ['x' => $minX + $w * 1.1, 'y' => $minY + $h_box * 0.5],
            ['x' => $minX + $w * 0.5, 'y' => $minY - $h_box * 0.1],
            ['x' => $minX + $w * 0.5, 'y' => $minY + $h_box * 1.1],
            ['x' => $minX - $w * 0.1, 'y' => $minY - $h_box * 0.1],
            ['x' => $minX + $w * 1.1, 'y' => $minY + $h_box * 1.1],
            ['x' => $minX - $w * 0.1, 'y' => $minY + $h_box * 1.1],
            ['x' => $minX + $w * 1.1, 'y' => $minY - $h_box * 0.1],
            // Inner corners (2 points)
            ['x' => $minX + $w * 0.2, 'y' => $minY + $h_box * 0.2],
            ['x' => $minX + $w * 0.8, 'y' => $minY + $h_box * 0.8]
        ];

        $redCount = 0;
        foreach ($points as $p) {
            $px = (int)max(0, min($width - 1, $p['x']));
            $py = (int)max(0, min($height - 1, $p['y']));
            $rgb = imagecolorat($img, $px, $py);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;

            // Red detection: R is significantly higher than G and B
            // or very high R with low G/B
            if (($r > 100 && $r > $g * 1.4 && $r > $b * 1.4) || ($r > 150 && $r > $g * 1.2 && $r > $b * 1.2)) {
                $redCount++;
            }
        }

        return $redCount >= 2;
    }

    private function isBlackBackground($box, string $imageContent): bool
    {
        if (empty($imageContent)) return true;
        
        static $lastImgContent = null;
        static $img = null;
        if ($lastImgContent !== $imageContent) {
            if ($img) imagedestroy($img);
            $img = @imagecreatefromstring($imageContent);
            $lastImgContent = $imageContent;
        }
        if (!$img) return true;

        $v = $box->getVertices();
        $x1 = $v[0]->getX(); $y1 = $v[0]->getY();
        $x2 = $v[2]->getX(); $y2 = $v[2]->getY();
        
        $width = imagesx($img);
        $height = imagesy($img);

        $px = (int)max(0, min($width - 1, ($x1 + $x2) / 2));
        $py = (int)max(0, min($height - 1, ($y1 + $y2) / 2));
        $rgb = imagecolorat($img, $px, $py);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        // Black/Dark detection
        return ($r < 80 && $g < 80 && $b < 80);
    }

    private function logOcrDebug(string $message): void
    {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/ocr_debug.log';
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL, FILE_APPEND);
    }
}
