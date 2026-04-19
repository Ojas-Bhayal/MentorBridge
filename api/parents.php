<?php
// api/parents.php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    jsonError('Unauthorized', 401);
}
if ($_SESSION['role'] !== 'Mentor') {
    jsonError('Forbidden', 403);
}
// NEW FIX: Restrict mentors to only see parents linked to their specific students
$specific_id = $_SESSION['specific_id'];

$stmt = $pdo->prepare("
    SELECT DISTINCT p.parent_id, p.user_id, u.name, u.email 
    FROM Parents p 
    JOIN Users u ON p.user_id = u.user_id 
    JOIN Parent_Student ps ON p.parent_id = ps.parent_id 
    JOIN Mentor_Student ms ON ps.student_id = ms.student_id 
    WHERE ms.mentor_id = ? 
    ORDER BY u.name
");
$stmt->execute([$specific_id]);

$parents = $stmt->fetchAll();
jsonSuccess(['parents' => $parents]);
