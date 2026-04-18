<?php
// api/mentors.php
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
    "SELECT m.mentor_id, m.user_id, u.name, u.email FROM Mentors m JOIN Users u ON m.user_id = u.user_id ORDER BY u.name"
);
$mentors = $stmt->fetchAll();
jsonSuccess(['mentors' => $mentors]);
