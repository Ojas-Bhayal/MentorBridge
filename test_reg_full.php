<?php
$data = json_encode([
    'name' => 'T',
    'email' => 't_front_' . time() . '@test.com',
    'password' => '12345678',
    'role_id' => 4
]);

$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\n",
        'method'  => 'POST',
        'content' => $data,
        'ignore_errors' => true // TO GET HTTP 500 payload
    ]
];
$context  = stream_context_create($options);
$result = file_get_contents('http://localhost/MentorBridge/api/auth.php?action=register', false, $context);

echo "HTTP Response Headers:\n";
print_r($http_response_header);
echo "\nResponse Body:\n";
echo $result;
