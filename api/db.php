<?php
// api/db.php

// --- DATABASE CONFIGURATION ---
// These are the fallback credentials if environment variables are not set.
$host = getenv('MENTORBRIDGE_DB_HOST') ?: '127.0.0.1';
$port = getenv('MENTORBRIDGE_DB_PORT') ?: '3306';
$db   = getenv('MENTORBRIDGE_DB_NAME') ?: 'mentorbridge';
$user = getenv('MENTORBRIDGE_DB_USER') ?: 'root';
$pass = getenv('MENTORBRIDGE_DB_PASS') ?: 'Ojas@522006';
$charset = 'utf8mb4';
// ------------------------------

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "Database connection failed."]);
    exit;
}
?>