<?php
// api/mentors.php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    jsonError('Unauthorized', 401);
}
$allowedRoles = ['Mentor', 'Parent'];
if (!in_array($_SESSION['role'], $allowedRoles, true)) {
    jsonError('Forbidden', 403);
}
$stmt = $pdo->query(
    "SELECT m.mentor_id, m.user_id, u.name, u.email FROM Mentors m JOIN Users u ON m.user_id = u.user_id ORDER BY u.name"
);
$mentors = $stmt->fetchAll();
jsonSuccess(['mentors' => $mentors]);
