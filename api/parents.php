<?php
// api/parents.php
require_once 'config.php';

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
