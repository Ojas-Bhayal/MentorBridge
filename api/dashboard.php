<?php
// api/dashboard.php
require_once 'config.php';

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

    // NEW FIX: Fetch the name of the mentor who submitted the performance record
    $stmt = $pdo->prepare("
    SELECT p.*, COALESCE(u.name, 'General') as mentor_name 
    FROM Performance p 
    LEFT JOIN Mentors m ON p.mentor_id = m.mentor_id 
    LEFT JOIN Users u ON m.user_id = u.user_id 
    WHERE p.student_id = ? 
    ORDER BY p.recorded_at ASC
");
    $stmt->execute([$specific_id]);
    $data['performance_history'] = $stmt->fetchAll();
    $data['performance'] = count($data['performance_history']) > 0 ? end($data['performance_history']) : null;

    $stmt = $pdo->prepare("SELECT status FROM StudentStatus WHERE student_id = ? ORDER BY CASE status WHEN 'red' THEN 1 WHEN 'yellow' THEN 2 WHEN 'green' THEN 3 END LIMIT 1");
    $stmt->execute([$specific_id]);
    $data['status'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM Goals WHERE student_id = ? ORDER BY deadline ASC");
    $stmt->execute([$specific_id]);
    $data['goals'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT a.*, u.name as mentor_name FROM Appointments a JOIN Mentors m ON a.mentor_id = m.mentor_id JOIN Users u ON m.user_id = u.user_id WHERE a.student_id = ?");
    $stmt->execute([$specific_id]);
    $data['appointments'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT s.*, u.name as mentor_name FROM Sessions s JOIN Mentors m ON s.mentor_id = m.mentor_id JOIN Users u ON m.user_id = u.user_id WHERE s.student_id = ? ORDER BY scheduled_at DESC");
    $stmt->execute([$specific_id]);
    $data['sessions'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT f.*, u.name as mentor_name FROM Feedback f JOIN Mentors m ON f.mentor_id = m.mentor_id JOIN Users u ON m.user_id = u.user_id WHERE f.student_id = ? ORDER BY created_at DESC");
    $stmt->execute([$specific_id]);
    $data['feedback'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM Escalations WHERE student_id = ? ORDER BY triggered_at DESC");
    $stmt->execute([$specific_id]);
    $data['escalations'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM Reports WHERE student_id = ? ORDER BY created_at DESC");
    $stmt->execute([$specific_id]);
    $data['reports'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM Consent WHERE student_id = ? ORDER BY consent_id DESC LIMIT 1");
    $stmt->execute([$specific_id]);
    $data['consent'] = $stmt->fetch();
    if (!$data['consent']) {
        $insertConsent = $pdo->prepare("INSERT INTO Consent (student_id) VALUES (?)");
        $insertConsent->execute([$specific_id]);
        $stmt->execute([$specific_id]);
        $data['consent'] = $stmt->fetch();
    }

    // FIXED: Notification table name
    $stmt = $pdo->prepare("SELECT * FROM NotificationQueue WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$user_id]);
    $data['notifications'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT plr.request_id, plr.parent_id, u.name AS parent_name, u.email AS parent_email, plr.created_at FROM Parent_Link_Requests plr JOIN Parents p ON plr.parent_id = p.parent_id JOIN Users u ON p.user_id = u.user_id WHERE plr.student_id = ? AND plr.status = 'pending' ORDER BY plr.created_at DESC");
    $stmt->execute([$specific_id]);
    $data['parent_link_requests'] = $stmt->fetchAll();

    // NEW FIX: Only fetch mentors explicitly linked to this specific student
    $stmt = $pdo->prepare("
        SELECT m.mentor_id, u.name as mentor_name 
        FROM Mentors m 
        JOIN Users u ON m.user_id = u.user_id 
        JOIN Mentor_Student ms ON m.mentor_id = ms.mentor_id 
        WHERE ms.student_id = ?
    ");
    $stmt->execute([$specific_id]);
    $data['available_mentors'] = $stmt->fetchAll();
    // NEW FIX: Fetch the connection code to display on the dashboard
    $stmt = $pdo->prepare("SELECT connection_code FROM Students WHERE student_id = ?");
    $stmt->execute([$specific_id]);
    $data['connection_code'] = $stmt->fetchColumn() ?: 'N/A';
    jsonSuccess(['data' => $data]);

} else if ($role === 'Mentor') {
    $data = array_merge($commonData, ['students' => [], 'appointments' => [], 'sessions' => [], 'escalations' => []]);

    $stmt = $pdo->prepare("SELECT s.student_id, u.name FROM Students s JOIN Users u ON s.user_id = u.user_id JOIN Mentor_Student ms ON s.student_id = ms.student_id WHERE ms.mentor_id = ? ORDER BY u.name");
    $stmt->execute([$specific_id]);
    $roster = $stmt->fetchAll();

    foreach ($roster as &$student) {
        $pStmt = $pdo->prepare("SELECT gpa, attendance, exam_score FROM Performance WHERE student_id = ? ORDER BY recorded_at DESC LIMIT 1");
        $pStmt->execute([$student['student_id']]);
        $perf = $pStmt->fetch();
        $student['performance'] = $perf ? $perf : ['gpa' => 'N/A', 'attendance' => 'N/A', 'exam_score' => 'N/A'];

        $statStmt = $pdo->prepare("SELECT status FROM StudentStatus WHERE student_id = ? AND mentor_id = ? LIMIT 1");
        $statStmt->execute([$student['student_id'], $specific_id]);
        $student['status'] = $statStmt->fetchColumn();

        $cStmt = $pdo->prepare("SELECT allow_session_notes, allow_feedback FROM Consent WHERE student_id = ?");
        $cStmt->execute([$student['student_id']]);
        $student['consent'] = $cStmt->fetch() ?: ['allow_session_notes' => 0, 'allow_feedback' => 1];
    }
    $data['students'] = $roster;

    $stmt = $pdo->prepare("SELECT a.*, u.name as student_name FROM Appointments a JOIN Students s ON a.student_id = s.student_id JOIN Users u ON s.user_id = u.user_id WHERE a.mentor_id = ?");
    $stmt->execute([$specific_id]);
    $data['appointments'] = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT ses.*, u.name as student_name FROM Sessions ses JOIN Students s ON ses.student_id = s.student_id JOIN Users u ON s.user_id = u.user_id WHERE ses.mentor_id = ?");
    $stmt->execute([$specific_id]);
    $data['sessions'] = $stmt->fetchAll();

    // NEW FIX: Fetch escalations and their parent-acknowledgement status
    $eStmt = $pdo->prepare("
        SELECT e.*, u.name as student_name, pu.name as acknowledged_by_name
        FROM Escalations e 
        JOIN Students s ON e.student_id = s.student_id 
        JOIN Users u ON s.user_id = u.user_id 
        JOIN Mentor_Student ms ON s.student_id = ms.student_id
        LEFT JOIN Users pu ON e.acknowledged_by = pu.user_id
        WHERE ms.mentor_id = ?
        ORDER BY e.triggered_at DESC
        LIMIT 20
    ");
    $eStmt->execute([$specific_id]);
    $data['escalations'] = $eStmt->fetchAll();
    // FIXED: Notification table name
    $nStmt = $pdo->prepare("SELECT * FROM NotificationQueue WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $nStmt->execute([$user_id]);
    $data['notifications'] = $nStmt->fetchAll();

    $allStudentsStmt = $pdo->prepare("SELECT s.student_id, u.name FROM Students s JOIN Users u ON s.user_id = u.user_id ORDER BY u.name");
    $allStudentsStmt->execute();
    $data['all_students'] = $allStudentsStmt->fetchAll();

    jsonSuccess(['data' => $data]);

} else if ($role === 'Parent') {
    $data = array_merge($commonData, ['students' => []]);

    $stmt = $pdo->prepare("SELECT s.student_id, u.name FROM Parent_Student ps JOIN Students s ON ps.student_id = s.student_id JOIN Users u ON s.user_id = u.user_id WHERE ps.parent_id = ?");
    $stmt->execute([$specific_id]);
    $linked_students = $stmt->fetchAll();

    foreach ($linked_students as &$student) {
        $sid = $student['student_id'];

        $pStmt = $pdo->prepare("
    SELECT p.*, COALESCE(u.name, 'General') as mentor_name 
    FROM Performance p 
    LEFT JOIN Mentors m ON p.mentor_id = m.mentor_id 
    LEFT JOIN Users u ON m.user_id = u.user_id 
    WHERE p.student_id = ? 
    ORDER BY p.recorded_at ASC
");
        $pStmt->execute([$sid]);
        $student['performance_history'] = $pStmt->fetchAll();
        $student['performance'] = count($student['performance_history']) > 0 ? end($student['performance_history']) : null;

        $cStmt = $pdo->prepare("SELECT allow_session_notes, allow_feedback FROM Consent WHERE student_id = ?");
        $cStmt->execute([$sid]);
        $student['consent'] = $cStmt->fetch() ?: ['allow_session_notes' => 0, 'allow_feedback' => 1];

        // NEW FIX: Enforce the student's consent toggle before fetching session notes
        if (!empty($student['consent']['allow_session_notes'])) {
            // Sessions: Fetching only parent-shared type
            $sStmt = $pdo->prepare("
                SELECT s.*, um.name as mentor_name 
                FROM Sessions s
                JOIN Mentors m ON s.mentor_id = m.mentor_id
                JOIN Users um ON m.user_id = um.user_id
                WHERE s.student_id = ? AND s.status != 'cancelled' AND s.type = 'parent' 
                ORDER BY s.scheduled_at DESC
            ");
            $sStmt->execute([$sid]);
            $student['sessions'] = $sStmt->fetchAll();
        } else {
            // If consent is denied (or not explicitly true), return an empty array
            $student['sessions'] = [];
        }

        $rStmt = $pdo->prepare("SELECT * FROM Reports WHERE student_id = ? ORDER BY month DESC");
        $rStmt->execute([$sid]);
        $student['reports'] = $rStmt->fetchAll();

        if ($student['consent']['allow_feedback']) {
            $fStmt = $pdo->prepare("SELECT f.*, u.name as mentor_name FROM Feedback f JOIN Mentors m ON f.mentor_id = m.mentor_id JOIN Users u ON m.user_id = u.user_id WHERE f.student_id = ? ORDER BY f.created_at DESC");
            $fStmt->execute([$sid]);
            $student['feedback'] = $fStmt->fetchAll();
        } else {
            $student['feedback'] = [];
        }

        $eStmt = $pdo->prepare("SELECT * FROM Escalations WHERE student_id = ? ORDER BY triggered_at DESC");
        $eStmt->execute([$sid]);
        $student['escalations'] = $eStmt->fetchAll();
    }
    $data['students'] = $linked_students;

    // FIXED: Notification table name
    $stmt = $pdo->prepare("SELECT * FROM NotificationQueue WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$user_id]);
    $data['notifications'] = $stmt->fetchAll();

    if (count($linked_students) > 0) {
        $sIds = array_column($linked_students, 'student_id');
        $ph = implode(',', array_fill(0, count($sIds), '?'));
        // NEW FIX: Filter out 'confidential' student appointments
        $aStmt = $pdo->prepare("
            SELECT a.*, us.name as student_name, um.name as mentor_name
            FROM Appointments a
            JOIN Students s ON a.student_id = s.student_id
            JOIN Users us ON s.user_id = us.user_id
            JOIN Mentors m ON a.mentor_id = m.mentor_id
            JOIN Users um ON m.user_id = um.user_id
            WHERE a.student_id IN ($ph) 
              AND a.status NOT IN ('rejected', 'cancelled')
              AND a.type = 'parent'  -- This is the crucial addition
            ORDER BY a.requested_time DESC
        ");
        $aStmt->execute($sIds);
        $data['appointments'] = $aStmt->fetchAll();
        // NEW FIX: Fetch upcoming sessions officially scheduled by the Mentor
        $uStmt = $pdo->prepare("
            SELECT s.session_id, s.scheduled_at, us.name as student_name, um.name as mentor_name
            FROM Sessions s
            JOIN Students st ON s.student_id = st.student_id
            JOIN Users us ON st.user_id = us.user_id
            JOIN Mentors m ON s.mentor_id = m.mentor_id
            JOIN Users um ON m.user_id = um.user_id
            WHERE s.student_id IN ($ph) AND s.status = 'scheduled' AND s.type = 'parent'
            ORDER BY s.scheduled_at ASC
        ");
        $uStmt->execute($sIds);
        $data['upcoming_sessions'] = $uStmt->fetchAll();
    } else {
        $data['appointments'] = [];
        $data['upcoming_sessions'] = []; // Ensure empty array if no students linked
    }

    // NEW FIX: Only fetch mentors linked to the parent's children
    $mStmt = $pdo->prepare("
        SELECT DISTINCT m.mentor_id, u.name as mentor_name 
        FROM Mentors m 
        JOIN Users u ON m.user_id = u.user_id 
        JOIN Mentor_Student ms ON m.mentor_id = ms.mentor_id 
        JOIN Parent_Student ps ON ms.student_id = ps.student_id 
        WHERE ps.parent_id = ? 
        ORDER BY u.name
    ");
    $mStmt->execute([$specific_id]);
    $data['available_mentors'] = $mStmt->fetchAll();

    $pReqStmt = $pdo->prepare("SELECT plr.request_id, u.name AS student_name, u.email AS student_email, plr.status, plr.created_at FROM Parent_Link_Requests plr JOIN Students s ON plr.student_id = s.student_id JOIN Users u ON s.user_id = u.user_id WHERE plr.parent_id = ? ORDER BY plr.created_at DESC");
    $pReqStmt->execute([$specific_id]);
    $data['pending_link_requests'] = $pReqStmt->fetchAll();

    jsonSuccess(['data' => $data]);
} else {
    jsonError("Unknown role", 403);
}
?>