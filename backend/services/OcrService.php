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

            // Request both Document Text Detection and Label Detection
            $image = $imageContent;
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
                    'reading' => $this->parseDigits($allText),
                    'meter_number' => null
                ];
                $results['validation'] = $this->validateMeterType($allText, $labels, $meterType);
                return $results;
            }

            $results = $this->extractMeterData($fullAnnotation, $imageContent, $meterType);
            $results['validation'] = $this->validateMeterType($fullAnnotation->getText(), $labels, $meterType);
            
            $imageAnnotator->close();
            return $results;

        } catch (Exception $e) {
            error_log("OCR Error: " . $e->getMessage());
            $errorMsg = (defined('APP_ENV') && APP_ENV === 'development') ? $e->getMessage() : "fout bij het uitlezen van de meter";
            throw new Exception($errorMsg);
        }
    }    /**
     * Extracts meter reading and meter number from FullTextAnnotation
     */
    private function extractMeterData($annotation, string $imageContent, string $meterType): array
    {
        $allReadings = [];
        $allPotentialMeterNumbers = [];
        $unitLocations = [];

        // Determine units to look for
        $targetUnits = ($meterType === 'elec') ? ['kwh', 'kw.h'] : ['m3', 'm³'];

        // Traverse through pages, blocks, paragraphs, and words
        foreach ($annotation->getPages() as $page) {
            foreach ($page->getBlocks() as $block) {
                foreach ($block->getParagraphs() as $paragraph) {
                    $wordsInLine = [];
                    foreach ($paragraph->getWords() as $word) {
                        $wordText = '';
                        $symbolsWithMeta = [];
                        foreach ($word->getSymbols() as $symbol) {
                            $char = $symbol->getText();
                            $box = $symbol->getBoundingBox();
                            $vertices = $box->getVertices();
                            
                            // Height of the individual symbol
                            $height = abs($vertices[2]->getY() - $vertices[1]->getY());
                            $isRed = $this->hasRedBackground($box, $imageContent);

                            $symbolsWithMeta[] = [
                                'char' => $char,
                                'height' => $height,
                                'is_red' => $isRed,
                                'box' => $box
                            ];
                            $wordText .= $char;
                        }
                        
                        $cleanWord = strtolower(str_replace(['^', '.', ' '], '', $wordText));
                        $box = $word->getBoundingBox();

                        // Detect unit locations
                        if (in_array($cleanWord, $targetUnits)) {
                            $unitLocations[] = ['text' => $wordText, 'box' => $box];
                        }

                        $wordsInLine[] = [
                            'text' => $wordText,
                            'symbols' => $symbolsWithMeta,
                            'box' => $box
                        ];
                    }

                    // Process words in this line to find candidates
                    $this->findCandidates($wordsInLine, $unitLocations, $allReadings, $allPotentialMeterNumbers);
                }
            }
        }

        // Sort Readings: Prefer high unit proximity, then area
        usort($allReadings, function($a, $b) {
            if ($a['unit_proximity'] != $b['unit_proximity']) {
                return $a['unit_proximity'] <=> $b['unit_proximity'];
            }
            return $b['area'] <=> $a['area'];
        });

        $readingResult = !empty($allReadings) ? $allReadings[0]['text'] : null;
        if (!$readingResult) $readingResult = $this->parseDigits($annotation->getText());

        // Sort Meter Numbers: Prefer pattern matches, then area
        usort($allPotentialMeterNumbers, function($a, $b) {
            if ($a['priority'] != $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }
            return ($b['area'] ?? 0) <=> ($a['area'] ?? 0);
        });
        
        $meterNumberResult = !empty($allPotentialMeterNumbers) ? $allPotentialMeterNumbers[0]['text'] : null;

        return [
            'reading' => $readingResult,
            'meter_number' => $meterNumberResult
        ];
    }

    /**
     * Finds reading and meter number candidates in a line of words
     * Enforces size consistency for readings
     */
    private function findCandidates(array $words, array $unitLocations, array &$allReadings, array &$allPotentialMeterNumbers): void
    {
        $fullLineText = '';
        foreach ($words as $w) $fullLineText .= $w['text'] . ' ';
        $fullLineText = trim($fullLineText);

        // Meter Number Patterns (ISK00, nr, zr, serienr)
        if (preg_match('/(?:ISK00|nr|zr)[:\s]*(\d{8})/i', $fullLineText, $m)) {
            $allPotentialMeterNumbers[] = ['text' => $m[1], 'priority' => 100];
        }
        if (preg_match('/(?:serienr)[:\s]*(\d{6})/i', $fullLineText, $m)) {
            $allPotentialMeterNumbers[] = ['text' => $m[1], 'priority' => 90];
        }

        foreach ($words as $word) {
            $text = $word['text'];
            if (!preg_match('/\d{5,9}/', $text)) continue;

            // Group symbols by height to ensure UNIFORM size
            $groups = [];
            foreach ($word['symbols'] as $s) {
                if (!ctype_digit($s['char'])) continue; // Only digits for reading
                if ($s['is_red']) continue; // EXCLUDE RED BACKGROUND DIGITS

                $found = false;
                foreach ($groups as &$group) {
                    // Tolerance for height (15%)
                    if (abs($s['height'] - $group['avg_height']) < ($group['avg_height'] * 0.15)) {
                        $group['text'] .= $s['char'];
                        $group['count']++;
                        $group['total_height'] += $s['height'];
                        $group['avg_height'] = $group['total_height'] / $group['count'];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $groups[] = [
                        'text' => $s['char'],
                        'count' => 1,
                        'total_height' => $s['height'],
                        'avg_height' => $s['height'],
                        'box' => $s['box']
                    ];
                }
            }

            // A valid reading candidate must have 5-8 digits of the same height
            foreach ($groups as $group) {
                if (strlen($group['text']) >= 5 && strlen($group['text']) <= 8) {
                    // Calculate area for prominence
                    $v = $group['box']->getVertices();
                    $area = abs($v[1]->getX() - $v[0]->getX()) * abs($v[2]->getY() - $v[1]->getY());

                    // Calculate unit proximity
                    $minDist = 9999;
                    foreach ($unitLocations as $u) {
                        $dist = $this->getDistance($group['box'], $u['box']);
                        if ($dist < $minDist) $minDist = $dist;
                    }

                    $allReadings[] = [
                        'text' => $group['text'],
                        'area' => $area,
                        'unit_proximity' => $minDist
                    ];
                }

                // If it's a 8-digit sequence, it's also a meter number candidate (lower priority)
                if (strlen($group['text']) == 8) {
                    $allPotentialMeterNumbers[] = ['text' => $group['text'], 'priority' => 50, 'area' => 0];
                }
            }
        }
    }

    /**
     * Best-effort color detection for red backgrounds
     */
    private function hasRedBackground($box, string $imageContent): bool
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

            $redPixels = 0;
            $totalPoints = 0;
            
            // Sample points in the bounding box
            for ($x = max(0, $x1); $x < min($width, $x2); $x += max(1, ($x2-$x1)/8)) {
                for ($y = max(0, $y1); $y < min($height, $y2); $y += max(1, ($y2-$y1)/8)) {
                    $rgb = imagecolorat($im, (int)$x, (int)$y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    // Red detection: R is significantly higher than G and B
                    // For red backgrounds, R is usually > 130 and > G+60
                    if ($r > 130 && $r > $g + 60 && $r > $b + 60) {
                        $redPixels++;
                    }
                    $totalPoints++;
                }
            }

            imagedestroy($im);
            return ($totalPoints > 0) && ($redPixels / $totalPoints) > 0.25;

        } catch (Exception $e) {
            return false;
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

    private function parseDigits(string $text): ?string
    {
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
            // Check for m3
            if (strpos($allTextLower, 'm3') === false && strpos($allText, 'm³') === false) {
                $valid = false;
                $message = "Dit lijkt geen gasmeter te zijn (geen m3 gevonden). Fotografeer de juiste meter.";
            }
        } elseif ($expectedType === 'elec') {
            // Check for kwh
            if (strpos($allTextLower, 'kwh') === false && strpos($allTextLower, 'kw.h') === false) {
                $valid = false;
                $message = "Dit lijkt geen elektrameter te zijn (geen kWh gevonden). Fotografeer de juiste meter.";
            }
        } elseif ($expectedType === 'water') {
            // Check for labels like water meter, gauge, or circle
            $waterKeywords = ['water meter', 'gauge', 'circle', 'measuring instrument', 'dial', 'water'];
            $foundKeyword = false;
            foreach ($labels as $label) {
                foreach ($waterKeywords as $kw) {
                    if (strpos($label, $kw) !== false) {
                        $foundKeyword = true;
                        break 2;
                    }
                }
            }
            
            // Also check text for "water" or "m3" (though m3 is shared with gas)
            if (!$foundKeyword && strpos($allTextLower, 'water') === false && strpos($allTextLower, 'm3') === false && strpos($allText, 'm³') === false) {
                $valid = false;
                $message = "Dit lijkt geen watermeter te zijn. Fotografeer de juiste meter.";
            }
        }

        return [
            'valid' => $valid,
            'message' => $message
        ];
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
