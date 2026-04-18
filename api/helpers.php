<?php
// api/helpers.php

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    jsonResponse(['status' => 'error', 'message' => $message], $code);
}

function jsonSuccess(array $payload = []): void {
    jsonResponse(array_merge(['status' => 'success'], $payload));
}

function fail(string $message, int $code = 400): void {
    jsonError($message, $code);
}

function requirePostMethod(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Method not allowed', 405);
    }
}

function getJsonInput(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function requireCsrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonError('Invalid CSRF token', 403);
    }
}

function requirePositiveInt($value, string $fieldName): int {
    $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($result === false) {
        jsonError("{$fieldName} must be a positive integer.");
    }
    return $result;
}

function requireNotEmpty($value, string $fieldName): string {
    $value = trim((string)$value);
    if ($value === '') {
        jsonError("{$fieldName} is required.");
    }
    return $value;
}

function validateEnum($value, array $allowed, string $message): void {
    if (!in_array($value, $allowed, true)) {
        jsonError($message);
    }
}

function updateNotificationStatus(PDO $pdo, int $notificationId, int $userId, string $status): void {
    validateEnum($status, ['pending', 'read', 'archived'], 'Invalid notification status.');
    $stmt = $pdo->prepare('UPDATE NotificationQueue SET status = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$status, $notificationId, $userId]);
    if ($stmt->rowCount() === 0) {
        jsonError('Notification not found.', 404);
    }
}

function sendNotification(PDO $pdo, int $user_id, string $message, string $type = 'message', string $status = 'pending'): void {
    $stmt = $pdo->prepare('INSERT INTO NotificationQueue (user_id, message, type, status) VALUES (?, ?, ?, ?)');
    $stmt->execute([$user_id, $message, $type, $status]);
}

function getStudentByEmail(PDO $pdo, string $email) {
    $stmt = $pdo->prepare('SELECT s.student_id, u.user_id, u.name FROM Users u JOIN Students s ON u.user_id = s.user_id WHERE u.email = ?');
    $stmt->execute([$email]);
    return $stmt->fetch();
}

function requireMentorStudentLink(PDO $pdo, int $mentorId, int $studentId): void {
    $stmt = $pdo->prepare('SELECT 1 FROM Students WHERE student_id = ?');
    $stmt->execute([$studentId]);
    if (!$stmt->fetchColumn()) {
        jsonError('Student not found.', 404);
    }

    $stmt = $pdo->prepare('SELECT 1 FROM Mentor_Student WHERE mentor_id = ? AND student_id = ? LIMIT 1');
    $stmt->execute([$mentorId, $studentId]);
    if (!$stmt->fetchColumn()) {
        jsonError('Mentor is not assigned to this student.', 403);
    }
}

function requireParentStudentLink(PDO $pdo, int $parentId, int $studentId): void {
    $stmt = $pdo->prepare('SELECT 1 FROM Parent_Student WHERE parent_id = ? AND student_id = ?');
    $stmt->execute([$parentId, $studentId]);
    if (!$stmt->fetchColumn()) {
        jsonError('Parent is not linked to this student.', 403);
    }
}
