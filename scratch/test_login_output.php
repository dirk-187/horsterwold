<?php
// Simulate a verify request to login.php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'localhost';

$token = 'bf588f87f7e82c54055d0e16b5245ebb13423de9c060ed58';
$input = json_encode(['action' => 'verify', 'token' => $token]);

// We can't easily mock php://input for a required file, 
// but we can check if there are any obvious output issues.

ob_start();
include 'backend/api/login.php';
$output = ob_get_clean();

echo "Output:\n";
echo $output . "\n";
?>
