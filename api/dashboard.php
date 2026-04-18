<?php
// api/dashboard.php
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
    jsonError("Unauthorized", 401);
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$specific_id = $_SESSION['specific_id'];

$userStmt = $pdo->prepare("SELECT user_id, name, email, role_id FROM Users WHERE user_id = ?");
$userStmt->execute([$user_id]);
$currentUser = $userStmt->fetch();

$rolesStmt = $pdo->query("SELECT role_id, role_name FROM Roles ORDER BY role_id");
$allRoles = $rolesStmt->fetchAll();

$commonData = [
    'current_user' => $currentUser,
    'roles' => $allRoles
];

if ($role === 'Student') {
    $data = array_merge($commonData, ['performance' => null, 'goals' => [], 'appointments' => [], 'sessions' => [], 'feedback' => [], 'consent' => null, 'notifications' => []]);
    
    // Performance History (for charts & stats)
    $stmt = $pdo->prepare("SELECT * FROM Performance WHERE student_id = ? ORDER BY recorded_at ASC");
    $stmt->execute([$specific_id]);
    $data['performance_history'] = $stmt->fetchAll();
    
    // Grabbing the newest one explicitly for text stats
    $data['performance'] = count($data['performance_history']) > 0 ? end($data['performance_history']) : null;

    // Student Risk Status
    $stmt = $pdo->prepare("SELECT status FROM StudentStatus WHERE student_id = ? ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([$specific_id]);
    $data['status'] = $stmt->fetchColumn();
    
    // Goals
    $stmt = $pdo->prepare("SELECT * FROM Goals WHERE student_id = ? ORDER BY deadline ASC");
    $stmt->execute([$specific_id]);
    $data['goals'] = $stmt->fetchAll();
    
    // Appointments
    $stmt = $pdo->prepare("SELECT a.*, u.name as mentor_name FROM Appointments a JOIN Mentors m ON a.mentor_id = m.mentor_id JOIN Users u ON m.user_id = u.user_id WHERE a.student_id = ?");
    $stmt->execute([$specific_id]);
    $data['appointments'] = $stmt->fetchAll();

    // Sessions
    $stmt = $pdo->prepare("SELECT s.*, u.name as mentor_name FROM Sessions s JOIN Mentors m ON s.mentor_id = m.mentor_id JOIN Users u ON m.user_id = u.user_id WHERE s.student_id = ? ORDER BY scheduled_at DESC");
    $stmt->execute([$specific_id]);
    $data['sessions'] = $stmt->fetchAll();

    // Feedback
    $stmt = $pdo->prepare("SELECT f.*, u.name as mentor_name FROM Feedback f JOIN Mentors m ON f.mentor_id = m.mentor_id JOIN Users u ON m.user_id = u.user_id WHERE f.student_id = ? ORDER BY created_at DESC");
    $stmt->execute([$specific_id]);
    $data['feedback'] = $stmt->fetchAll();

    // Escalations
    $stmt = $pdo->prepare("SELECT * FROM Escalations WHERE student_id = ? ORDER BY triggered_at DESC");
    $stmt->execute([$specific_id]);
    $data['escalations'] = $stmt->fetchAll();

    // Reports
    $stmt = $pdo->prepare("SELECT * FROM Reports WHERE student_id = ? ORDER BY created_at DESC");
    $stmt->execute([$specific_id]);
    $data['reports'] = $stmt->fetchAll();

    // Consent
    $stmt = $pdo->prepare("SELECT * FROM Consent WHERE student_id = ? ORDER BY consent_id DESC LIMIT 1");
    $stmt->execute([$specific_id]);
    $data['consent'] = $stmt->fetch();
    if(!$data['consent']) {
        // Init default consent if null
        $insertConsent = $pdo->prepare("INSERT INTO Consent (student_id) VALUES (?)");
        $insertConsent->execute([$specific_id]);
        $stmt->execute([$specific_id]);
        $data['consent'] = $stmt->fetch();
    }

    // Notifications
    $stmt = $pdo->prepare("SELECT * FROM NotificationQueue WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$user_id]);
    $data['notifications'] = $stmt->fetchAll();

    // Pending parent link requests for the student
    $stmt = $pdo->prepare("SELECT plr.request_id, plr.parent_id, u.name AS parent_name, u.email AS parent_email, plr.created_at FROM Parent_Link_Requests plr JOIN Parents p ON plr.parent_id = p.parent_id JOIN Users u ON p.user_id = u.user_id WHERE plr.student_id = ? AND plr.status = 'pending' ORDER BY plr.created_at DESC");
    $stmt->execute([$specific_id]);
    $data['parent_link_requests'] = $stmt->fetchAll();

    // Available Mentors (for appointments)
    $stmt = $pdo->prepare("SELECT m.mentor_id, u.name as mentor_name FROM Mentors m JOIN Users u ON m.user_id = u.user_id");
    $stmt->execute();
    $data['available_mentors'] = $stmt->fetchAll();

    // Assigned Mentors
    $stmt = $pdo->prepare("SELECT m.mentor_id, u.name as mentor_name FROM Mentors m JOIN Users u ON m.user_id = u.user_id JOIN Mentor_Student ms ON m.mentor_id = ms.mentor_id WHERE ms.student_id = ?");
    $stmt->execute([$specific_id]);
    $data['assigned_mentors'] = $stmt->fetchAll();
    
    jsonSuccess(['data' => $data]);

} else if ($role === 'Mentor') {
    $data = array_merge($commonData, ['students' => [], 'appointments' => [], 'sessions' => [], 'escalations' => []]);
    
    // Student roster: show assigned students
    $stmt = $pdo->prepare(
        "SELECT s.student_id, u.name FROM Students s JOIN Users u ON s.user_id = u.user_id 
         JOIN Mentor_Student ms ON s.student_id = ms.student_id 
         WHERE ms.mentor_id = ? ORDER BY u.name"
    );
    $stmt->execute([$specific_id]);
    $roster = $stmt->fetchAll();
    
    // Grab details for roster (Performance, Status, Consent)
    foreach($roster as &$student) {
        // Performance
        $pStmt = $pdo->prepare("SELECT gpa, attendance, exam_score FROM Performance WHERE student_id = ? ORDER BY recorded_at DESC LIMIT 1");
        $pStmt->execute([$student['student_id']]);
        $student['performance'] = $pStmt->fetch();

        // Status
        $statStmt = $pdo->prepare("SELECT status FROM StudentStatus WHERE student_id = ? ORDER BY updated_at DESC LIMIT 1");
        $statStmt->execute([$student['student_id']]);
        $student['status'] = $statStmt->fetchColumn();

        // Consent
        $cStmt = $pdo->prepare("SELECT allow_personal_notes, allow_session_notes, allow_feedback FROM Consent WHERE student_id = ?");
        $cStmt->execute([$student['student_id']]);
        $student['consent'] = $cStmt->fetch();
        if (!$student['consent']) {
            $student['consent'] = [
                'allow_personal_notes' => 0,
                'allow_session_notes' => 0,
                'allow_feedback' => 1
            ];
        }

        // Mark as linked
        $student['linked'] = true;
    }
    $data['students'] = $roster;

    // Appointments
    $stmt = $pdo->prepare("SELECT a.*, u.name as student_name FROM Appointments a JOIN Students s ON a.student_id = s.student_id JOIN Users u ON s.user_id = u.user_id WHERE a.mentor_id = ?");
    $stmt->execute([$specific_id]);
    $data['appointments'] = $stmt->fetchAll();

    // Sessions (for mentor to manage)
    $stmt = $pdo->prepare("SELECT ses.*, u.name as student_name FROM Sessions ses JOIN Students s ON ses.student_id = s.student_id JOIN Users u ON s.user_id = u.user_id WHERE ses.mentor_id = ?");
    $stmt->execute([$specific_id]);
    $data['sessions'] = $stmt->fetchAll();

    // Escalations issued by this mentor (simplified linking through mentor sessions/appointments to students)
    // To simplify, we will just pull escalations for students in the mentor's roster.
    if(count($roster) > 0) {
        $studentIds = array_column($roster, 'student_id');
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $eStmt = $pdo->prepare("SELECT e.*, u.name as student_name FROM Escalations e JOIN Students s ON e.student_id = s.student_id JOIN Users u ON s.user_id = u.user_id WHERE e.student_id IN ($placeholders) ORDER BY triggered_at DESC");
        $eStmt->execute($studentIds);
        $data['escalations'] = $eStmt->fetchAll();
    }

    // All students (for initial session/appointment creation when roster is empty)
    $allStudentsStmt = $pdo->prepare("SELECT s.student_id, u.name FROM Students s JOIN Users u ON s.user_id = u.user_id ORDER BY u.name");
    $allStudentsStmt->execute();
    $data['all_students'] = $allStudentsStmt->fetchAll();

    // Notifications for mentor
    $nStmt = $pdo->prepare("SELECT * FROM NotificationQueue WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $nStmt->execute([$user_id]);
    $data['notifications'] = $nStmt->fetchAll();
    
    jsonSuccess(['data' => $data]);

} else if ($role === 'Parent') {
    $data = array_merge($commonData, ['students' => []]);
    
    // Linked Students with full tracking
    $stmt = $pdo->prepare("SELECT s.student_id, u.name FROM Parent_Student ps JOIN Students s ON ps.student_id = s.student_id JOIN Users u ON s.user_id = u.user_id WHERE ps.parent_id = ?");
    $stmt->execute([$specific_id]);
    $linked_students = $stmt->fetchAll();

    foreach($linked_students as &$student) {
        $sid = $student['student_id'];
        // Performance History
        $pStmt = $pdo->prepare("SELECT * FROM Performance WHERE student_id = ? ORDER BY recorded_at ASC");
        $pStmt->execute([$sid]);
        $student['performance_history'] = $pStmt->fetchAll();
        $student['performance'] = count($student['performance_history']) > 0 ? end($student['performance_history']) : null;

        // Accessible sessions (Type = 'parent')
        $sStmt = $pdo->prepare("SELECT * FROM Sessions WHERE student_id = ? AND type = 'parent'");
        $sStmt->execute([$sid]);
        $student['sessions'] = $sStmt->fetchAll();

        // Reports
        $rStmt = $pdo->prepare("SELECT * FROM Reports WHERE student_id = ?");
        $rStmt->execute([$sid]);
        $student['reports'] = $rStmt->fetchAll();

        // Escalations (Alerts)
        $eStmt = $pdo->prepare("SELECT * FROM Escalations WHERE student_id = ? ORDER BY triggered_at DESC");
        $eStmt->execute([$sid]);
        $student['escalations'] = $eStmt->fetchAll();

        // Consent
        $cStmt = $pdo->prepare("SELECT allow_personal_notes, allow_session_notes, allow_feedback FROM Consent WHERE student_id = ?");
        $cStmt->execute([$sid]);
        $student['consent'] = $cStmt->fetch();
        if (!$student['consent']) {
            $student['consent'] = [
                'allow_personal_notes' => 0,
                'allow_session_notes' => 0,
                'allow_feedback' => 1
            ];
        }
    }
    $data['students'] = $linked_students;

    // Notifications for parent
    $stmt = $pdo->prepare("SELECT * FROM NotificationQueue WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$user_id]);
    $data['notifications'] = $stmt->fetchAll();

    // View appointments (Parent requested) -> Since schema links Appointments to student_id and mentor_id,
    // Parent sees appointments for their students.
    if (count($linked_students) > 0) {
        $sIds = array_column($linked_students, 'student_id');
        $ph = implode(',', array_fill(0, count($sIds), '?'));
        $aStmt = $pdo->prepare("SELECT a.*, u.name as student_name, mu.name as mentor_name FROM Appointments a JOIN Mentors m ON a.mentor_id = m.mentor_id JOIN Users mu ON m.user_id = mu.user_id JOIN Students s ON a.student_id = s.student_id JOIN Users u ON s.user_id = u.user_id WHERE a.student_id IN ($ph)");
        $aStmt->execute($sIds);
        $data['appointments'] = $aStmt->fetchAll();
    } else {
        $data['appointments'] = [];
    }

    // Available mentors for appointment requests from parent dashboard.
    $mStmt = $pdo->prepare("SELECT m.mentor_id, u.name as mentor_name FROM Mentors m JOIN Users u ON m.user_id = u.user_id ORDER BY u.name");
    $mStmt->execute();
    $data['available_mentors'] = $mStmt->fetchAll();

    // Current parent link requests submitted by this parent
    $pReqStmt = $pdo->prepare("SELECT plr.request_id, plr.student_id, u.name AS student_name, u.email AS student_email, plr.status, plr.created_at FROM Parent_Link_Requests plr JOIN Students s ON plr.student_id = s.student_id JOIN Users u ON s.user_id = u.user_id WHERE plr.parent_id = ? ORDER BY plr.created_at DESC");
    $pReqStmt->execute([$specific_id]);
    $data['pending_link_requests'] = $pReqStmt->fetchAll();

    jsonSuccess(['data' => $data]);
} else {
    jsonError("Unknown role", 403);
}
?>
