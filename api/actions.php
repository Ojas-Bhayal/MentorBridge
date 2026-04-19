<?php
// api/actions.php
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
require 'action_handlers.php';
if (!isset($_SESSION['user_id'])) {
    jsonError('Unauthorized', 401);
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$specific_id = $_SESSION['specific_id'];
$action = $_GET['action'] ?? '';

requirePostMethod();
$data = getJsonInput();
requireCsrf();
dispatchAction($pdo, $role, $user_id, $specific_id, $action, $data);
