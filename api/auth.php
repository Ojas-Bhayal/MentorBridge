<?php
// api/auth.php
ini_set('session.gc_maxlifetime', 3600); // 1 hour - must be before session_start
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
require 'security_headers.php'; sendSecurityHeaders();

define('MENTOR_INVITE_CODE', 'MENTOR-INVITE-2026');
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function getOrCreateCsrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requireLogoutCsrf()
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonError("Invalid CSRF token", 403);
    }
}

if ($method === 'POST' && $action === 'register') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $roleId = isset($data['role_id']) ? intval($data['role_id']) : 0;
    $roleName = trim($data['role'] ?? '');
    $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $window = date('Y-m-d H:i:s', strtotime('-15 minutes'));

    // Count recent attempts
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM LoginAttempts WHERE ip_hash = ? AND attempted_at > ?"
    );
    $countStmt->execute([$ipHash, $window]);
    if ((int)$countStmt->fetchColumn() >= 10) {
        jsonError("Too many login attempts. Please wait 15 minutes.", 429);
    }

    // Record this attempt
    $pdo->prepare("INSERT INTO LoginAttempts (ip_hash) VALUES (?)")->execute([$ipHash]);

    // ... existing password check ...
    if ($name === '') {
        jsonError("Full name is required.");
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError("Please enter a valid email address.");
    }
    if (strlen($password) < 8) {
        jsonError("Password must be at least 8 characters long.");
    }

    if ($roleId <= 0 && $roleName === '') {
        jsonError("Please select a valid role.");
    }

    // Verify email doesn't exist
    $stmt = $pdo->prepare("SELECT user_id FROM Users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonError("Email already registered.");
    }



    $roleNameInput = trim($data['role'] ?? '');
    $pwHash = password_hash($password, PASSWORD_DEFAULT);

    if ($roleId > 0) {
        $rStmt = $pdo->prepare("SELECT role_name FROM Roles WHERE role_id = ?");
        $rStmt->execute([$roleId]);
        $roleName = $rStmt->fetchColumn();
    }

    if (!$roleName && $roleNameInput !== '') {
        $rStmt = $pdo->prepare("SELECT role_id, role_name FROM Roles WHERE role_name = ?");
        $rStmt->execute([$roleNameInput]);
        $roleRecord = $rStmt->fetch();
        if ($roleRecord) {
            $roleId = intval($roleRecord['role_id']);
            $roleName = $roleRecord['role_name'];
        }
    }

    if (!$roleName) {
        jsonError("Invalid role.");
    }

    if ($roleName === 'Mentor') {
        $inviteCode = trim($data['invite_code'] ?? '');
        if ($inviteCode !== MENTOR_INVITE_CODE) {
            jsonError("Invalid or missing mentor invite code.");
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO Users (name, email, password, role_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $email, $pwHash, $roleId]);
        $newUserId = $pdo->lastInsertId();

        $specificId = null;
        if ($roleName === 'Student') {
            $studentStmt = $pdo->prepare("INSERT INTO Students (user_id) VALUES (?)");
            $studentStmt->execute([$newUserId]);
            $specificId = $pdo->lastInsertId();
            $consentStmt = $pdo->prepare("INSERT INTO Consent (student_id) VALUES (?)");
            $consentStmt->execute([$specificId]);
        } else if ($roleName === 'Mentor') {
            $mentorStmt = $pdo->prepare("INSERT INTO Mentors (user_id) VALUES (?)");
            $mentorStmt->execute([$newUserId]);
            $specificId = $pdo->lastInsertId();
        } else if ($roleName === 'Parent') {
            $parentStmt = $pdo->prepare("INSERT INTO Parents (user_id) VALUES (?)");
            $parentStmt->execute([$newUserId]);
            $specificId = $pdo->lastInsertId();
            // Note: Parents require manual linkage to Student via Parent_Student table.
        }

        if (!$specificId) {
            throw new Exception('Unable to initialize role profile.');
        }

        $_SESSION['user_id'] = $newUserId;
        $_SESSION['role'] = $roleName;
        $_SESSION['specific_id'] = $specificId;
        $_SESSION['name'] = $name;

        $pdo->commit();
        echo json_encode([
            "status" => "success",
            "role" => $roleName,
            "name" => $name,
            "csrf_token" => getOrCreateCsrfToken()
        ]);
    } catch (\Exception $e) {
        $pdo->rollBack();
        jsonError("Registration fail.");
    }

} else if ($method === 'POST' && $action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError("Please enter a valid email address.");
    }
    if ($password === '') {
        jsonError("Password is required.");
    }

    $stmt = $pdo->prepare("SELECT u.*, r.role_name FROM Users u JOIN Roles r ON u.role_id = r.role_id WHERE u.email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['name'] = $user['name'];
        // Find their specific role ID
        $specific_id = null;
        if ($user['role_name'] === 'Student') {
            $sStmt = $pdo->prepare("SELECT student_id FROM Students WHERE user_id = ?");
            $sStmt->execute([$user['user_id']]);
            $specific_id = $sStmt->fetchColumn();
        } else if ($user['role_name'] === 'Mentor') {
            $mStmt = $pdo->prepare("SELECT mentor_id FROM Mentors WHERE user_id = ?");
            $mStmt->execute([$user['user_id']]);
            $specific_id = $mStmt->fetchColumn();
        } else if ($user['role_name'] === 'Parent') {
            $pStmt = $pdo->prepare("SELECT parent_id FROM Parents WHERE user_id = ?");
            $pStmt->execute([$user['user_id']]);
            $specific_id = $pStmt->fetchColumn();
        }
        if (!$specific_id) {
            jsonError("Account role profile is incomplete. Contact support.", 409);
        }

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role'] = $user['role_name'];
        $_SESSION['specific_id'] = $specific_id;

        echo json_encode([
            "status" => "success",
            "role" => $user['role_name'],
            "name" => $user['name'],
            "csrf_token" => getOrCreateCsrfToken()
        ]);
    } else {
        jsonError("Invalid credentials");
    }
} else if ($method === 'GET' && $action === 'check') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            "authenticated" => true,
            "user_id" => $_SESSION['user_id'],
            "role" => $_SESSION['role'],
            "name" => $_SESSION['name'] ?? null,
            "csrf_token" => getOrCreateCsrfToken()
        ]);
    } else {
        echo json_encode(["authenticated" => false]);
    }
} else if ($method === 'POST' && $action === 'logout') {
    requireLogoutCsrf();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    echo json_encode(["status" => "success"]);
}
?>