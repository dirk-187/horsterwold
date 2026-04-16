<?php
$url = 'http://localhost/horsterwold/backend/api/login.php';
$data = ['action' => 'verify', 'token' => 'dummy'];
$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($data),
        'ignore_errors' => true
    ]
];
$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

echo "HTTP_CODE: " . $http_response_header[0] . "\n\n";
echo trim($result);
