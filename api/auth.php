<?php
// api/auth.php
require_once 'config.php'; // This starts the session and loads DB/Helpers

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

/**
 * Ensures a CSRF token exists and sets the XSRF-TOKEN cookie 
 * for AngularJS to read automatically.
 */
function setCsrfCookie()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    setcookie('XSRF-TOKEN', $_SESSION['csrf_token'], [
        'expires' => 0,
        'path' => '/',
        'secure' => true,      // Set to true if using HTTPS
        'httponly' => false,   // MUST be false so AngularJS can read it
        'samesite' => 'Lax'
    ]);
}

// --- 1. REGISTRATION ---
if ($method === 'POST' && $action === 'register') {
    $data = getJsonInput(); // Uses helper to get JSON
    // NEW FIX: Remove manual htmlspecialchars to prevent double-escaping
    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $roleId = isset($data['role_id']) ? intval($data['role_id']) : 0;
    $roleNameInput = trim($data['role'] ?? '');

    // Validations
    if ($name === '')
        jsonError("Full name is required.");
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        jsonError("Invalid email address.");
    if (strlen($password) < 8)
        jsonError("Password must be at least 8 characters long.");

    // Role Verification
    $stmt = $pdo->prepare("SELECT role_id, role_name FROM Roles WHERE role_id = ? OR role_name = ?");
    $stmt->execute([$roleId, $roleNameInput]);
    $role = $stmt->fetch();
    if (!$role)
        jsonError("Invalid role.");

    $roleId = $role['role_id'];
    $roleName = $role['role_name'];

    // Mentor Invite Code Check
    if ($roleName === 'Mentor') {
        $inviteCode = trim($data['invite_code'] ?? '');
        if ($inviteCode !== MENTOR_INVITE_CODE) { // Constant defined in config.php
            jsonError("Invalid mentor invite code.");
        }
    }

    // Check for existing email
    $stmt = $pdo->prepare("SELECT 1 FROM Users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch())
        jsonError("Email already registered.");

    $pdo->beginTransaction();
    try {
        $pwHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO Users (name, email, password, role_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $email, $pwHash, $roleId]);
        $newUserId = $pdo->lastInsertId();

        $specificId = null;
        if ($roleName === 'Student') {
            // NEW FIX: Generate a secure 6-character connection code
            $connCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $sStmt = $pdo->prepare("INSERT INTO Students (user_id, connection_code) VALUES (?, ?)");
            $sStmt->execute([$newUserId, $connCode]);

            $specificId = $pdo->lastInsertId();
            // Initialize student consent record
            $pdo->prepare("INSERT INTO Consent (student_id) VALUES (?)")->execute([$specificId]);
        } else if ($roleName === 'Mentor') {
            $mStmt = $pdo->prepare("INSERT INTO Mentors (user_id) VALUES (?)");
            $mStmt->execute([$newUserId]);
            $specificId = $pdo->lastInsertId();
        } else if ($roleName === 'Parent') {
            $pStmt = $pdo->prepare("INSERT INTO Parents (user_id) VALUES (?)");
            $pStmt->execute([$newUserId]);
            $specificId = $pdo->lastInsertId();
        }

        // Finalize Session
        session_regenerate_id(true); // Prevent Session Fixation
        $_SESSION['user_id'] = $newUserId;
        $_SESSION['role'] = $roleName;
        $_SESSION['specific_id'] = $specificId;
        $_SESSION['name'] = $name;

        $pdo->commit();
        setCsrfCookie();
        jsonSuccess(["role" => $roleName, "name" => $name]);

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError("Registration failed: " . $e->getMessage());
    }

    // --- 2. LOGIN ---
} else if ($method === 'POST' && $action === 'login') {
    $data = getJsonInput();
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    // Rate Limiting
    $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $pdo->prepare("INSERT INTO LoginAttempts (ip_hash) VALUES (?)")->execute([$ipHash]);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM LoginAttempts WHERE ip_hash = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $countStmt->execute([$ipHash]);
    if ((int) $countStmt->fetchColumn() > 10)
        jsonError("Too many attempts. Wait 15 minutes.", 429);

    $stmt = $pdo->prepare("SELECT u.*, r.role_name FROM Users u JOIN Roles r ON u.role_id = r.role_id WHERE u.email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Find Specific Role ID
        $specific_id = null;
        $table = ($user['role_name'] === 'Student') ? 'Students' : (($user['role_name'] === 'Mentor') ? 'Mentors' : 'Parents');
        $idCol = ($user['role_name'] === 'Student') ? 'student_id' : (($user['role_name'] === 'Mentor') ? 'mentor_id' : 'parent_id');

        $sStmt = $pdo->prepare("SELECT $idCol FROM $table WHERE user_id = ?");
        $sStmt->execute([$user['user_id']]);
        $specific_id = $sStmt->fetchColumn();

        if (!$specific_id)
            jsonError("Profile incomplete.", 409);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role'] = $user['role_name'];
        $_SESSION['specific_id'] = $specific_id;
        $_SESSION['name'] = $user['name'];

        setCsrfCookie();
        jsonSuccess(["role" => $user['role_name'], "name" => $user['name']]);
    } else {
        jsonError("Invalid email or password.", 401);
    }

    // --- 3. SESSION CHECK ---
} else if ($method === 'GET' && $action === 'check') {
    if (isset($_SESSION['user_id'])) {
        setCsrfCookie(); // Refresh the CSRF cookie
        jsonSuccess([
            "authenticated" => true,
            "role" => $_SESSION['role'],
            "name" => $_SESSION['name']
        ]);
    } else {
        jsonSuccess(["authenticated" => false]);
    }

    // --- 4. LOGOUT ---
} else if ($method === 'POST' && $action === 'logout') {
    requireCsrf(); // Helper uses X-XSRF-TOKEN header

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    jsonSuccess();
}