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
// NEW FIX: Restrict parents to only see mentors linked to their specific students
$role = $_SESSION['role'];
$specific_id = $_SESSION['specific_id'];

if ($role === 'Parent') {
    $stmt = $pdo->prepare("
        SELECT DISTINCT m.mentor_id, m.user_id, u.name, u.email 
        FROM Mentors m 
        JOIN Users u ON m.user_id = u.user_id 
        JOIN Mentor_Student ms ON m.mentor_id = ms.mentor_id 
        JOIN Parent_Student ps ON ms.student_id = ps.student_id 
        WHERE ps.parent_id = ? 
        ORDER BY u.name
    ");
    $stmt->execute([$specific_id]);
} else if ($role === 'Mentor') {
    // If a mentor checks the directory, only show their own profile
    $stmt = $pdo->prepare("
        SELECT m.mentor_id, m.user_id, u.name, u.email 
        FROM Mentors m 
        JOIN Users u ON m.user_id = u.user_id 
        WHERE m.mentor_id = ?
    ");
    $stmt->execute([$specific_id]);
}

$mentors = $stmt->fetchAll();
jsonSuccess(['mentors' => $mentors]);
