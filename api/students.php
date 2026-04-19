<?php
// api/students.php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    jsonError('Unauthorized', 401);
}
// Only mentors and parents should see the student directory
$allowedRoles = ['Mentor', 'Parent'];
if (!in_array($_SESSION['role'], $allowedRoles, true)) {
    jsonError('Forbidden', 403);
}
// NEW FIX: Scope the student directory based on the user's role
$role = $_SESSION['role'];
$specific_id = $_SESSION['specific_id'];

if ($role === 'Mentor') {
    $stmt = $pdo->prepare("
        SELECT s.student_id, s.user_id, u.name, u.email 
        FROM Students s 
        JOIN Users u ON s.user_id = u.user_id 
        JOIN Mentor_Student ms ON s.student_id = ms.student_id 
        WHERE ms.mentor_id = ? 
        ORDER BY u.name
    ");
    $stmt->execute([$specific_id]);
} else if ($role === 'Parent') {
    $stmt = $pdo->prepare("
        SELECT s.student_id, s.user_id, u.name, u.email 
        FROM Students s 
        JOIN Users u ON s.user_id = u.user_id 
        JOIN Parent_Student ps ON s.student_id = ps.student_id 
        WHERE ps.parent_id = ? 
        ORDER BY u.name
    ");
    $stmt->execute([$specific_id]);
}

$students = $stmt->fetchAll();
jsonSuccess(['students' => $students]);
