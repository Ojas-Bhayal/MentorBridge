<?php
// api/setup_db.php
$trustedHosts = ['127.0.0.1', '::1', 'localhost'];
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if ((getenv('MENTORBRIDGE_ALLOW_SETUP') ?: '0') !== '1' || !in_array($remoteAddr, $trustedHosts, true)) {
    http_response_code(403);
    echo "Setup endpoint is disabled or unavailable from this host. Enable it only for local provisioning.";
    exit;
}

$host = getenv('MENTORBRIDGE_DB_HOST') ?: '127.0.0.1';
$user = getenv('MENTORBRIDGE_DB_USER') ?: 'root';
$pass = getenv('MENTORBRIDGE_DB_PASS') ?: '';
$db = getenv('MENTORBRIDGE_DB_NAME') ?: 'mentorbridge';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db`");
    $pdo->exec("USE `$db`");

    echo "Database created/selected successfully.<br>";

    $sqlContent = file_get_contents(__DIR__ . '/../MentorBridge_MySQL.sql');
    if ($sqlContent) {
        $pdo->exec($sqlContent);
        echo "Tables created successfully.<br>";
    } else {
        echo "Could not read MentorBridge_MySQL.sql<br>";
    }

    $pdo->exec("INSERT IGNORE INTO Roles (role_id, role_name) VALUES (1, 'Student'), (2, 'Mentor'), (3, 'Parent')");
    $pwHash = password_hash('password', PASSWORD_DEFAULT);

    $users = [
        ['John Student', 'student@test.com', 1],
        ['Dr. Mentor', 'mentor@test.com', 2],
        ['Jane Parent', 'parent@test.com', 3]
    ];

    $userStmt = $pdo->prepare("INSERT IGNORE INTO Users (name, email, password, role_id) VALUES (?, ?, ?, ?)");
    $lookupUser = $pdo->prepare("SELECT user_id FROM Users WHERE email = ?");

    foreach ($users as $userData) {
        $userStmt->execute([$userData[0], $userData[1], $pwHash, $userData[2]]);
    }

    $lookupUser->execute(['student@test.com']);
    $studentUserId = $lookupUser->fetchColumn();
    if ($studentUserId) {
        $pdo->exec("INSERT IGNORE INTO Students (user_id) VALUES ($studentUserId)");
        $studentId = $pdo->lastInsertId();
        if (!$studentId) {
            $stmt = $pdo->prepare("SELECT student_id FROM Students WHERE user_id = ?");
            $stmt->execute([$studentUserId]);
            $studentId = $stmt->fetchColumn();
        }
        if ($studentId) {
            $pdo->exec("INSERT IGNORE INTO Consent (student_id) VALUES ($studentId)");
            $checkStatus = $pdo->prepare("SELECT 1 FROM StudentStatus WHERE student_id = ?");
            $checkStatus->execute([$studentId]);
            if (!$checkStatus->fetchColumn()) {
                $pdo->exec("INSERT INTO StudentStatus (student_id, status) VALUES ($studentId, 'green')");
            }
        }
    }

    $lookupUser->execute(['mentor@test.com']);
    $mentorUserId = $lookupUser->fetchColumn();
    if ($mentorUserId) {
        $pdo->exec("INSERT IGNORE INTO Mentors (user_id) VALUES ($mentorUserId)");
        $mentorId = $pdo->lastInsertId();
        if (!$mentorId) {
            $stmt = $pdo->prepare("SELECT mentor_id FROM Mentors WHERE user_id = ?");
            $stmt->execute([$mentorUserId]);
            $mentorId = $stmt->fetchColumn();
        }
    }

    $lookupUser->execute(['parent@test.com']);
    $parentUserId = $lookupUser->fetchColumn();
    if ($parentUserId) {
        $pdo->exec("INSERT IGNORE INTO Parents (user_id) VALUES ($parentUserId)");
        $parentId = $pdo->lastInsertId();
        if (!$parentId) {
            $stmt = $pdo->prepare("SELECT parent_id FROM Parents WHERE user_id = ?");
            $stmt->execute([$parentUserId]);
            $parentId = $stmt->fetchColumn();
        }
    }

    if (!empty($parentId) && !empty($studentId)) {
        $pdo->exec("INSERT IGNORE INTO Parent_Student (parent_id, student_id) VALUES ($parentId, $studentId)");
    }
    // NEW: Establish the mandatory authorization link for dummy data
    if (!empty($mentorId) && !empty($studentId)) {
        $pdo->exec("INSERT IGNORE INTO Mentor_Student (mentor_id, student_id) VALUES ($mentorId, $studentId)");
    }
    if (!empty($studentId)) {
        $checkPerf = $pdo->prepare("SELECT 1 FROM Performance WHERE student_id = ? AND gpa = 3.8 AND attendance = 95 AND exam_score = 88.5");
        $checkPerf->execute([$studentId]);
        if (!$checkPerf->fetchColumn()) {
            // UPDATED: Added mentor_id to the seeding logic
            $perfStmt = $pdo->prepare("INSERT INTO Performance (student_id, mentor_id, gpa, attendance, exam_score) VALUES (?, ?, 3.8, 95, 88.5)");
            $perfStmt->execute([$studentId, $mentorId]);
        }

        $checkGoal = $pdo->prepare("SELECT 1 FROM Goals WHERE student_id = ? AND title = 'Improve Math Grades'");
        $checkGoal->execute([$studentId]);
        if (!$checkGoal->fetchColumn()) {
            $goalStmt = $pdo->prepare("INSERT INTO Goals (student_id, title, description, status, deadline) VALUES (?, 'Improve Math Grades', 'Focus on calculus chapters', 'in_progress', '2026-05-01')");
            $goalStmt->execute([$studentId]);
        }

        if (!empty($mentorId)) {
            $checkApt = $pdo->prepare("SELECT 1 FROM Appointments WHERE student_id = ? AND mentor_id = ? AND requested_time = '2026-04-20 10:00:00' AND status = 'approved'");
            $checkApt->execute([$studentId, $mentorId]);
            if (!$checkApt->fetchColumn()) {
                $aptStmt = $pdo->prepare("INSERT INTO Appointments (student_id, mentor_id, requested_time, status) VALUES (?, ?, '2026-04-20 10:00:00', 'approved')");
                $aptStmt->execute([$studentId, $mentorId]);
            }
        }
    }

    echo "Dummy data seeded. You can login with student@test.com, mentor@test.com, parent@test.com and password 'password'.";
} catch (\PDOException $e) {
    echo "Setup failed: " . $e->getMessage();
}
?>