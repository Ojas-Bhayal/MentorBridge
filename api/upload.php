<?php
// api/upload.php
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

if (!isset($_SESSION['user_id'])) {
    jsonError('Unauthorized', 401);
}

// CSRF check from header
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    jsonError('Invalid CSRF token', 403);
}

$allowed = [
    'application/pdf' => 'pdf',
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/jpg' => 'jpg'
];
$maxSize = 5 * 1024 * 1024; // 5MB

if (!isset($_FILES['file'])) {
    jsonError('No file uploaded.');
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonError('Upload failed with error code: ' . $file['error']);
}

if ($file['size'] > $maxSize) {
    jsonError('File too large (max 5MB).');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!array_key_exists($mimeType, $allowed)) {
    jsonError('Invalid file type. Allowed: PDF, PNG, JPEG.');
}

$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = $allowed[$mimeType];
$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '', pathinfo($file['name'], PATHINFO_FILENAME));
$filename = uniqid('report_') . '_' . $safeName . '.' . $ext;

if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
    jsonError('Failed to save uploaded file.');
}

jsonSuccess(['file_path' => 'uploads/' . $filename]);
?>