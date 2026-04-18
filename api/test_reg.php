<?php
$ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode(['name'=>'T','email'=>'t@t.com','password'=>'12345678','role'=>'Student'])]]);
$result = file_get_contents('http://localhost/MentorBridge/api/auth.php?action=register', false, $ctx);
if ($result === false) {
    echo "HTTP request failed. Details: \n";
    print_r($http_response_header);
} else {
    var_dump($result);
}
