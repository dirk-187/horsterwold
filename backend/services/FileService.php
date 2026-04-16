<?php
/**
 * FileService — Handles image storage and metadata extraction
 */

namespace Horsterwold\Services;

use Exception;

class FileService
{
    private string $uploadDir;
    private string $baseUrl;

    public function __construct()
    {
        $this->uploadDir = __DIR__ . '/../../public/uploads/meters/';
        $this->baseUrl = defined('STORAGE_BASE_URL') ? STORAGE_BASE_URL : 'uploads/meters/';
        
        // Ensure upload directory exists
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Store a base64 image as a JPG file and extract EXIF date
     */
    public function storeBase64Image(string $base64Data, string $filename): array
    {
        // Remove data URL prefix if present
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
        }
        
        $imageData = base64_decode($base64Data);
        if ($imageData === false) {
            throw new Exception("Kon de afbeelding niet decoderen.");
        }

        // Generate subdirectory by month
        $monthDir = date('Y-m');
        $fullPath = $this->uploadDir . $monthDir . '/';
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        $filepath = $fullPath . $filename . '.jpg';
        if (file_put_contents($filepath, $imageData) === false) {
            throw new Exception("Kon het bestand niet opslaan op de server.");
        }

        // Extract EXIF date (DateTimeOriginal)
        $exifDate = $this->getExifDate($filepath);

        // Return relative URL for DB
        $relativeUrl = 'meters/' . $monthDir . '/' . $filename . '.jpg';

        return [
            'url' => $relativeUrl,
            'full_path' => $filepath,
            'exif_date' => $exifDate
        ];
    }

    /**
     * Extract the creation date from EXIF metadata
     */
    private function getExifDate(string $filepath): ?string
    {
        if (!function_exists('exif_read_data')) {
            return date('Y-m-d H:i:s'); // Fallback to current server time if exif is missing
        }

        try {
            $exif = @exif_read_data($filepath);
            if ($exif && isset($exif['DateTimeOriginal'])) {
                // Return YYYY-MM-DD HH:MM:SS
                return date('Y-m-d H:i:s', strtotime($exif['DateTimeOriginal']));
            }
        } catch (Exception $e) {
            error_log("EXIF error: " . $e->getMessage());
        }

        return date('Y-m-d H:i:s'); // Fallback to current time
    }
}
