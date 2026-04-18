<?php
// api/users.php
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

$action = $_GET['action'] ?? 'me';
if ($action === 'me') {
    $stmt = $pdo->prepare("SELECT u.user_id, u.name, u.email, r.role_name FROM Users u JOIN Roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        jsonError('User not found.', 404);
    }
    jsonSuccess(['user' => $user]);
} else if ($action === 'update_profile') {
    requirePostMethod();
    requireCsrf();
    $data = getJsonInput();

    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError('Name and valid email are required.');
    }

    $checkEmail = $pdo->prepare('SELECT 1 FROM Users WHERE email = ? AND user_id != ?');
    $checkEmail->execute([$email, $_SESSION['user_id']]);
    if ($checkEmail->fetchColumn()) {
        jsonError('Email is already taken.');
    }

    $update = $pdo->prepare('UPDATE Users SET name = ?, email = ? WHERE user_id = ?');
    $update->execute([$name, $email, $_SESSION['user_id']]);
    jsonSuccess(['updated' => true]);
} else {
    jsonError('Invalid action.', 400);
}
