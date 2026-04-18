<?php
// api/students.php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
require 'db.php';
require 'helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    jsonError('Unauthorized', 401);
}

$stmt = $pdo->query(
    "SELECT s.student_id, s.user_id, u.name, u.email FROM Students s JOIN Users u ON s.user_id = u.user_id ORDER BY u.name"
);
$students = $stmt->fetchAll();
jsonSuccess(['students' => $students]);
