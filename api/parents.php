<?php
// api/parents.php
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
require 'security_headers.php';
sendSecurityHeaders();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    jsonError('Unauthorized', 401);
}
if ($_SESSION['role'] !== 'Mentor') {
    jsonError('Forbidden', 403);
}
$stmt = $pdo->query(
    "SELECT p.parent_id, p.user_id, u.name, u.email FROM Parents p JOIN Users u ON p.user_id = u.user_id ORDER BY u.name"
);
$parents = $stmt->fetchAll();
jsonSuccess(['parents' => $parents]);
