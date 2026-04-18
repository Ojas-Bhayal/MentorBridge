<?php
// api/roles.php
require 'db.php';
require 'helpers.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT role_id, role_name FROM Roles ORDER BY role_id");
    $stmt->execute();
    $roles = $stmt->fetchAll();

    if (empty($roles)) {
        $defaultRoles = [
            ['role_name' => 'Student'],
            ['role_name' => 'Mentor'],
            ['role_name' => 'Parent'],
        ];
        $insert = $pdo->prepare("INSERT INTO Roles (role_name) VALUES (?)");
        foreach ($defaultRoles as $role) {
            $insert->execute([$role['role_name']]);
        }

        $stmt->execute();
        $roles = $stmt->fetchAll();
    }

    jsonSuccess(["roles" => $roles]);
} catch (Exception $e) {
    jsonError("Unable to load roles.", 500);
}
