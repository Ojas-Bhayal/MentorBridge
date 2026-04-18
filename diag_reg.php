<?php
require 'api/db.php';

$name = 'T';
$email = 'test_' . time() . '@test.com';
$pwHash = 'pass';
$roleId = 1; // Student
$roleName = 'Student';

$pdo->beginTransaction();
try {
    echo "Inserting user...\n";
    $stmt = $pdo->prepare("INSERT INTO Users (name, email, password, role_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $email, $pwHash, $roleId]);
    $newUserId = $pdo->lastInsertId();
    echo "New user id: $newUserId\n";

    $specificId = null;
    if ($roleName === 'Student') {
        echo "Inserting student...\n";
        $studentStmt = $pdo->prepare("INSERT INTO Students (user_id) VALUES (?)");
        $studentStmt->execute([$newUserId]);
        $specificId = $pdo->lastInsertId();
        echo "Specific id: $specificId\n";
        
        echo "Inserting consent...\n";
        $consentStmt = $pdo->prepare("INSERT INTO Consent (student_id) VALUES (?)");
        $consentStmt->execute([$specificId]);
        
        echo "Inserting status...\n";
        $statusStmt = $pdo->prepare("INSERT INTO StudentStatus (student_id, status) VALUES (?, 'green')");
        $statusStmt->execute([$specificId]);
    }

    if (!$specificId) {
        throw new Exception('Unable to initialize role profile.');
    }

    $pdo->commit();
    echo "Success!\n";
} catch (\Exception $e) {
    $pdo->rollBack();
    echo "Exception: " . $e->getMessage() . "\n";
}
