<?php
$filenames = [
    "1-2.jpg",
    "1-3.jpg",
    "1.jpg",
    "1000089057.jpeg",
    "142_G-W-E_3e308..jpeg",
    "16_onbekend_PHOTO..jpeg",
    "25-001_G-W-E_..",
    "47_onbekend-onbekend-E_Meterkast..jpg"
];

foreach ($filenames as $name) {
    preg_match('/\d+/', $name, $matches);
    $kavel = $matches[0] ?? '?' ;
    
    $category = 'unsorted';
    if (strpos($name, '-W-') !== false || strpos($name, '_W_') !== false) {
        // Simple detection for Water/Gas/Electra if present
    }
    
    echo "File: $name -> Kavel: $kavel\n";
}
?>
