<?php
// api/action_handlers.php

function dispatchAction(PDO $pdo, string $role, int $user_id, int $specific_id, string $action, array $data): void {
    if ($role === 'Student') {
        handleStudentAction($pdo, $user_id, $specific_id, $action, $data);
    } else if ($role === 'Mentor') {
        handleMentorAction($pdo, $user_id, $specific_id, $action, $data);
    } else if ($role === 'Parent') {
        handleParentAction($pdo, $user_id, $specific_id, $action, $data);
    } else {
        fail('Invalid role.', 403);
    }
}

function handleStudentAction(PDO $pdo, int $user_id, int $studentId, string $action, array $data): void {
    if ($action === 'update_consent') {
        $pn = !empty($data['allow_personal_notes']) ? 1 : 0;
        $sn = !empty($data['allow_session_notes']) ? 1 : 0;
        $fb = !empty($data['allow_feedback']) ? 1 : 0;

        $stmt = $pdo->prepare("INSERT INTO Consent (student_id, allow_personal_notes, allow_session_notes, allow_feedback) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE allow_personal_notes = VALUES(allow_personal_notes), allow_session_notes = VALUES(allow_session_notes), allow_feedback = VALUES(allow_feedback)");
        $stmt->execute([$studentId, $pn, $sn, $fb]);
        jsonSuccess();
    } else if ($action === 'add_goal') {
        if (empty(trim($data['title'] ?? '')) || empty($data['deadline'])) {
            fail('Title and deadline are required.');
        }
        $stmt = $pdo->prepare("INSERT INTO Goals (student_id, title, description, status, deadline) VALUES (?, ?, ?, 'pending', ?)");
        $stmt->execute([$studentId, trim($data['title']), trim($data['description'] ?? ''), $data['deadline']]);
        jsonSuccess();
    } else if ($action === 'update_goal') {
        $allowed = ['pending', 'in_progress', 'completed'];
        if (!in_array($data['status'] ?? '', $allowed, true)) {
            fail('Invalid goal status.');
        }
        $currStmt = $pdo->prepare("SELECT status FROM Goals WHERE goal_id = ? AND student_id = ?");
        $currStmt->execute([$data['goal_id'], $studentId]);
        if ($currStmt->fetchColumn() === 'completed') {
            fail('Cannot change status of a completed goal.', 403);
        }
        $stmt = $pdo->prepare("UPDATE Goals SET status = ? WHERE goal_id = ? AND student_id = ?");
        $stmt->execute([$data['status'], $data['goal_id'], $studentId]);
        jsonSuccess();
    } else if ($action === 'edit_goal') {
        if (empty(trim($data['title'] ?? '')) || empty($data['goal_id'])) {
            fail('Title and goal ID are required.');
        }
        $stmt = $pdo->prepare("UPDATE Goals SET title = ?, description = ? WHERE goal_id = ? AND student_id = ?");
        $stmt->execute([trim($data['title']), trim($data['description'] ?? ''), $data['goal_id'], $studentId]);
        if ($stmt->rowCount() === 0) fail('Goal not found.', 404);
        jsonSuccess();
    } else if ($action === 'delete_goal') {
        if (empty($data['goal_id'])) fail('Goal ID is required.');
        $stmt = $pdo->prepare("DELETE FROM Goals WHERE goal_id = ? AND student_id = ?");
        $stmt->execute([$data['goal_id'], $studentId]);
        if ($stmt->rowCount() === 0) fail('Goal not found.', 404);
        jsonSuccess();
    } else if ($action === 'respond_parent_link') {
        $requestId = (int)($data['request_id'] ?? 0);
        $decision = $data['decision'] ?? '';
        if ($requestId <= 0 || !in_array($decision, ['approve', 'deny'], true)) {
            fail('Invalid parent link response.');
        }

        $requestStmt = $pdo->prepare("SELECT plr.parent_id, plr.student_id, u.user_id AS parent_user_id FROM Parent_Link_Requests plr JOIN Parents p ON plr.parent_id = p.parent_id JOIN Users u ON p.user_id = u.user_id WHERE plr.request_id = ? AND plr.student_id = ? AND plr.status = 'pending'");
        $requestStmt->execute([$requestId, $studentId]);
        $request = $requestStmt->fetch();
        if (!$request) {
            fail('Link request not found.', 404);
        }

        $newStatus = $decision === 'approve' ? 'approved' : 'denied';
        $update = $pdo->prepare("UPDATE Parent_Link_Requests SET status = ? WHERE request_id = ?");
        $update->execute([$newStatus, $requestId]);

        if ($decision === 'approve') {
            $linkStmt = $pdo->prepare("INSERT IGNORE INTO Parent_Student (parent_id, student_id) VALUES (?, ?)");
            $linkStmt->execute([$request['parent_id'], $request['student_id']]);
            sendNotification($pdo, $request['parent_user_id'], 'Your request to connect with your child was approved.', 'link_response', 'pending');
        } else {
            sendNotification($pdo, $request['parent_user_id'], 'Your request to connect with your child was denied.', 'link_response', 'pending');
        }

        jsonSuccess(['decision' => $newStatus]);
    } else if ($action === 'request_appointment') {
        if (empty($data['mentor_id']) || empty($data['requested_time'])) {
            fail('Mentor and time are required.');
        }
        if (strtotime($data['requested_time']) <= time()) {
            fail('Requested time must be in the future.');
        }
        $mentorExists = $pdo->prepare('SELECT 1 FROM Mentors WHERE mentor_id = ?');
        $mentorExists->execute([$data['mentor_id']]);
        if (!$mentorExists->fetchColumn()) {
            fail('Selected mentor does not exist.');
        }
        $stmt = $pdo->prepare('INSERT INTO Appointments (student_id, mentor_id, requested_time, status) VALUES (?, ?, ?, "pending")');
        $stmt->execute([$studentId, $data['mentor_id'], $data['requested_time']]);
        jsonSuccess();
    } else if ($action === 'reschedule_appointment') {
        if (empty($data['appointment_id']) || empty($data['requested_time'])) {
            fail('Appointment id and new time are required.');
        }
        $stmt = $pdo->prepare('UPDATE Appointments SET requested_time = ?, status = "rescheduled" WHERE appointment_id = ? AND student_id = ?');
        $stmt->execute([$data['requested_time'], $data['appointment_id'], $studentId]);
        jsonSuccess();
    } else if ($action === 'cancel_appointment') {
        if (empty($data['appointment_id'])) {
            fail('Appointment id is required.');
        }
        $stmt = $pdo->prepare('UPDATE Appointments SET status = "cancelled" WHERE appointment_id = ? AND student_id = ?');
        $stmt->execute([$data['appointment_id'], $studentId]);
        jsonSuccess();
    } else if ($action === 'update_notification_status') {
        requireNotEmpty($data['notification_id'] ?? '', 'Notification id');
        requireNotEmpty($data['status'] ?? '', 'Notification status');
        updateNotificationStatus($pdo, (int)$data['notification_id'], $user_id, $data['status']);
        jsonSuccess();
    } else {
        fail('Invalid action.');
    }
}

function handleMentorAction(PDO $pdo, int $user_id, int $mentorId, string $action, array $data): void {
    if ($action === 'add_feedback') {
        $studentId = (int)($data['student_id'] ?? 0);
        $snippet = trim($data['snippet'] ?? '');
        if ($studentId <= 0 || $snippet === '') {
            fail('Student and feedback text are required.');
        }
        requireMentorStudentLink($pdo, $mentorId, $studentId);
        $consentStmt = $pdo->prepare('SELECT allow_feedback FROM Consent WHERE student_id = ?');
        $consentStmt->execute([$studentId]);
        if ((int)$consentStmt->fetchColumn() !== 1) {
            fail('Student has disabled feedback consent.', 403);
        }
        $stmt = $pdo->prepare('INSERT INTO Feedback (student_id, mentor_id, snippet) VALUES (?, ?, ?)');
        $stmt->execute([$studentId, $mentorId, $snippet]);
        jsonSuccess();
    } else if ($action === 'add_escalation') {
        $studentId = (int)($data['student_id'] ?? 0);
        $severity = $data['severity'] ?? '';
        $allowedSev = ['Low', 'Medium', 'High', 'Critical'];
        if ($studentId <= 0 || empty(trim($data['trigger_type'] ?? '')) || empty(trim($data['reason'] ?? '')) || !in_array($severity, $allowedSev, true)) {
            fail('Invalid escalation payload.');
        }
        requireMentorStudentLink($pdo, $mentorId, $studentId);
        $stmt = $pdo->prepare('INSERT INTO Escalations (student_id, trigger_type, reason, severity) VALUES (?, ?, ?, ?)');
        $stmt->execute([$studentId, trim($data['trigger_type']), trim($data['reason']), $severity]);
        jsonSuccess();
    } else if ($action === 'update_appointment') {
        $allowedStatuses = ['approved', 'rescheduled', 'rejected'];
        $status = $data['status'] ?? '';
        if (!in_array($status, $allowedStatuses, true)) {
            fail('Invalid appointment status.');
        }

        if ($status === 'rescheduled') {
            if (empty($data['requested_time'])) {
                fail('Requested time is required when rescheduling.');
            }
            if (strtotime($data['requested_time']) <= time()) {
                fail('Rescheduled time must be in the future.');
            }
            $stmt = $pdo->prepare('UPDATE Appointments SET requested_time = ?, status = ? WHERE appointment_id = ? AND mentor_id = ?');
            $stmt->execute([$data['requested_time'], $status, $data['appointment_id'], $mentorId]);
        } else {
            // Allow approving both 'pending' and 'rescheduled' appointments
            $stmt = $pdo->prepare('UPDATE Appointments SET status = ? WHERE appointment_id = ? AND mentor_id = ? AND status IN ("pending", "rescheduled")');
            $stmt->execute([$status, $data['appointment_id'], $mentorId]);
        }

        if ($stmt->rowCount() === 0) {
            fail('Appointment not found or already handled.', 404);
        }

        // AUTO-CREATE SESSION: When a mentor approves an appointment, automatically
        // create a corresponding Session record so the mentor does not need to
        // manually schedule it again from the Sessions tab.
        if ($status === 'approved') {
            $aptStmt = $pdo->prepare('SELECT student_id, requested_time FROM Appointments WHERE appointment_id = ?');
            $aptStmt->execute([$data['appointment_id']]);
            $apt = $aptStmt->fetch();
            if ($apt) {
                // Only create a session if one does not already exist for this exact appointment time.
                $dupCheck = $pdo->prepare(
                    'SELECT 1 FROM Sessions WHERE mentor_id = ? AND student_id = ? AND scheduled_at = ? AND status != "cancelled" LIMIT 1'
                );
                $dupCheck->execute([$mentorId, $apt['student_id'], $apt['requested_time']]);
                if (!$dupCheck->fetchColumn()) {
                    $sessStmt = $pdo->prepare(
                        'INSERT INTO Sessions (mentor_id, student_id, scheduled_at, status, type) VALUES (?, ?, ?, "scheduled", "confidential")'
                    );
                    $sessStmt->execute([$mentorId, $apt['student_id'], $apt['requested_time']]);
                }
            }
        }

        jsonSuccess();
    } else if ($action === 'add_report') {
        $studentId = (int)($data['student_id'] ?? 0);
        $month = $data['month'] ?? '';
        $summary = trim($data['summary'] ?? '');
        $filePath = trim($data['file_path'] ?? '');
        // Note: $filePath is validated here for being non-empty. 
        // FILTER_VALIDATE_URL was rejected relative paths like 'uploads/...'
        if ($studentId <= 0 || $month === '' || $summary === '' || $filePath === '') {
            fail('Invalid report payload.');
        }
        requireMentorStudentLink($pdo, $mentorId, $studentId);
        $stmt = $pdo->prepare('INSERT INTO Reports (student_id, generated_by, month, summary, file_path) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$studentId, $user_id, $month, $summary, $filePath]);
        jsonSuccess();
    } else if ($action === 'add_performance') {
        $studentId = (int)($data['student_id'] ?? 0);
        $gpa = floatval($data['gpa'] ?? -1);
        $attendance = floatval($data['attendance'] ?? -1);
        $examScore = isset($data['exam_score']) && $data['exam_score'] !== '' ? floatval($data['exam_score']) : null;
        if ($studentId <= 0 || $gpa < 0 || $gpa > 4.0 || $attendance < 0 || $attendance > 100 || ($examScore !== null && ($examScore < 0 || $examScore > 100))) {
            fail('Invalid performance metrics.');
        }
        requireMentorStudentLink($pdo, $mentorId, $studentId);
        $stmt = $pdo->prepare('INSERT INTO Performance (student_id, gpa, attendance, exam_score) VALUES (?, ?, ?, ?)');
        $stmt->execute([$studentId, $gpa, $attendance, $examScore]);

        $newStatus = 'green';
        if ($attendance < 80 || $gpa < 2.0) {
            $newStatus = 'red';
        } else if ($attendance < 90 || $gpa < 3.0) {
            $newStatus = 'yellow';
        }

        $statusStmt = $pdo->prepare('UPDATE StudentStatus SET status = ? WHERE student_id = ?');
        $statusStmt->execute([$newStatus, $studentId]);

        if ($newStatus === 'red') {
            $pStmt = $pdo->prepare('SELECT p.user_id FROM Parent_Student ps JOIN Parents p ON ps.parent_id = p.parent_id WHERE ps.student_id = ?');
            $pStmt->execute([$studentId]);
            $parent_user_ids = $pStmt->fetchAll(PDO::FETCH_COLUMN);

            $nStmt = $pdo->prepare('INSERT INTO NotificationQueue (user_id, message, type, status) VALUES (?, ?, "alert", "pending")');
            foreach ($parent_user_ids as $puid) {
                $msg = "AUTOMATED ALERT: Your child's attendance ({$attendance}%) or GPA ({$gpa}) has critically dropped. High Risk level triggered.";
                $nStmt->execute([$puid, $msg]);
            }
        }

        jsonSuccess();
    } else if ($action === 'send_broadcast') {
        $studentId = (int)($data['student_id'] ?? 0);
        $target = $data['target'] ?? '';
        $message = trim($data['message'] ?? '');
        if ($studentId <= 0 || !in_array($target, ['student', 'parent'], true) || $message === '') {
            fail('Invalid broadcast payload.');
        }
        requireMentorStudentLink($pdo, $mentorId, $studentId);
        $target_user_ids = [];
        if ($target === 'student') {
            $sStmt = $pdo->prepare('SELECT user_id FROM Students WHERE student_id = ?');
            $sStmt->execute([$studentId]);
            $target_user_ids = $sStmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $pStmt = $pdo->prepare('SELECT p.user_id FROM Parent_Student ps JOIN Parents p ON ps.parent_id = p.parent_id WHERE ps.student_id = ?');
            $pStmt->execute([$studentId]);
            $target_user_ids = $pStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        if (!empty($target_user_ids)) {
            $stmt = $pdo->prepare('INSERT INTO NotificationQueue (user_id, message, type, status) VALUES (?, ?, "message", "pending")');
            foreach ($target_user_ids as $tuid) {
                $stmt->execute([$tuid, 'MENTOR MESSAGE: ' . $message]);
            }
            jsonSuccess();
        }
        fail('Target user not found or not linked.');
    } else if ($action === 'complete_session') {
        if (empty($data['session_id']) || empty(trim($data['notes'] ?? ''))) {
            fail('Session id and notes are required.');
        }
        $stmt = $pdo->prepare('UPDATE Sessions SET status = "completed", notes = ? WHERE session_id = ? AND mentor_id = ?');
        $stmt->execute([trim($data['notes']), $data['session_id'], $mentorId]);
        jsonSuccess();
    } else if ($action === 'cancel_session') {
        if (empty($data['session_id'])) {
            fail('Session id is required.');
        }
        $stmt = $pdo->prepare('UPDATE Sessions SET status = "cancelled" WHERE session_id = ? AND mentor_id = ?');
        $stmt->execute([$data['session_id'], $mentorId]);
        if ($stmt->rowCount() === 0) {
            fail('Session not found.', 404);
        }
        jsonSuccess();
    } else if ($action === 'update_notification_status') {
        requireNotEmpty($data['notification_id'] ?? '', 'Notification id');
        requireNotEmpty($data['status'] ?? '', 'Notification status');
        updateNotificationStatus($pdo, (int)$data['notification_id'], $user_id, $data['status']);
        jsonSuccess();
    } else if ($action === 'add_session') {
        $studentId = (int)($data['student_id'] ?? 0);
        $type = $data['type'] ?? '';
        if ($studentId <= 0 || empty($data['scheduled_at']) || !in_array($type, ['confidential', 'parent'], true)) {
            fail('Invalid session payload.');
        }
        if (strtotime($data['scheduled_at']) <= time()) {
            fail('Scheduled time must be in the future.');
        }
        $stmt = $pdo->prepare('INSERT INTO Sessions (mentor_id, student_id, scheduled_at, status, type) VALUES (?, ?, ?, "scheduled", ?)');
        $stmt->execute([$mentorId, $studentId, $data['scheduled_at'], $type]);
        jsonSuccess();
    } else {
        fail('Invalid action.');
    }
}

function handleParentAction(PDO $pdo, int $user_id, int $parentId, string $action, array $data): void {
    if ($action === 'request_parent_link') {
        $studentEmail = trim(strtolower($data['student_email'] ?? ''));
        if ($studentEmail === '') {
            fail('Student email is required to request a parent connection.');
        }
        $student = getStudentByEmail($pdo, $studentEmail);
        if (!$student) {
            fail('Student not found.', 404);
        }

        $existingLink = $pdo->prepare('SELECT 1 FROM Parent_Student WHERE parent_id = ? AND student_id = ?');
        $existingLink->execute([$parentId, $student['student_id']]);
        if ($existingLink->fetchColumn()) {
            fail('You are already linked with this student.');
        }

        $existingRequest = $pdo->prepare('SELECT 1 FROM Parent_Link_Requests WHERE parent_id = ? AND student_id = ? AND status = "pending"');
        $existingRequest->execute([$parentId, $student['student_id']]);
        if ($existingRequest->fetchColumn()) {
            fail('A pending connection request already exists.');
        }

        $insert = $pdo->prepare('INSERT INTO Parent_Link_Requests (parent_id, student_id, status) VALUES (?, ?, "pending")');
        $insert->execute([$parentId, $student['student_id']]);

        $safeName = htmlspecialchars($_SESSION['name'] ?? 'A parent', ENT_QUOTES, 'UTF-8');
        sendNotification($pdo, $student['user_id'], 'A parent (' . $safeName . ') has requested to connect with you.', 'link_request', 'pending');
        jsonSuccess();
    } else if ($action === 'request_appointment') {
        $studentId = (int)($data['student_id'] ?? 0);
        $mentorId = (int)($data['mentor_id'] ?? 0);
        if ($studentId <= 0 || $mentorId <= 0 || empty($data['requested_time'])) {
            fail('Student, mentor and requested time are required.');
        }
        if (strtotime($data['requested_time']) <= time()) {
            fail('Requested time must be in the future.');
        }
        requireParentStudentLink($pdo, $parentId, $studentId);
        $mentorExists = $pdo->prepare('SELECT 1 FROM Mentors WHERE mentor_id = ?');
        $mentorExists->execute([$mentorId]);
        if (!$mentorExists->fetchColumn()) {
            fail('Selected mentor does not exist.');
        }
        $stmt = $pdo->prepare('INSERT INTO Appointments (student_id, mentor_id, requested_time, status) VALUES (?, ?, ?, "pending")');
        $stmt->execute([$studentId, $mentorId, $data['requested_time']]);
        jsonSuccess();
    } else if ($action === 'reschedule_appointment') {
        $appointmentId = (int)($data['appointment_id'] ?? 0);
        $studentId = (int)($data['student_id'] ?? 0);
        if ($appointmentId <= 0 || $studentId <= 0 || empty($data['requested_time'])) {
            fail('Appointment id, child and new time are required.');
        }
        requireParentStudentLink($pdo, $parentId, $studentId);
        $stmt = $pdo->prepare('UPDATE Appointments SET requested_time = ?, status = "rescheduled" WHERE appointment_id = ? AND student_id = ?');
        $stmt->execute([$data['requested_time'], $appointmentId, $studentId]);
        if ($stmt->rowCount() === 0) {
            fail('Appointment not found.', 404);
        }
        jsonSuccess();
    } else if ($action === 'cancel_appointment') {
        $appointmentId = (int)($data['appointment_id'] ?? 0);
        $studentId = (int)($data['student_id'] ?? 0);
        if ($appointmentId <= 0 || $studentId <= 0) {
            fail('Appointment id and child are required.');
        }
        requireParentStudentLink($pdo, $parentId, $studentId);
        $stmt = $pdo->prepare('UPDATE Appointments SET status = "cancelled" WHERE appointment_id = ? AND student_id = ?');
        $stmt->execute([$appointmentId, $studentId]);
        if ($stmt->rowCount() === 0) {
            fail('Appointment not found.', 404);
        }
        jsonSuccess();
    } else if ($action === 'request_consent_change') {
        $studentId = (int)($data['student_id'] ?? 0);
        $changes = $data['changes'] ?? '';
        if ($studentId <= 0 || empty($changes)) {
            fail('Student id and changes are required.');
        }
        requireParentStudentLink($pdo, $parentId, $studentId);
        $studentUserStmt = $pdo->prepare('SELECT user_id FROM Students WHERE student_id = ?');
        $studentUserStmt->execute([$studentId]);
        $studentUserId = $studentUserStmt->fetchColumn();
        if (!$studentUserId) {
            fail('Student not found.', 404);
        }
        $message = 'Parent requested consent changes: ' . $changes;
        sendNotification($pdo, $studentUserId, $message, 'consent_request');
        jsonSuccess();
    } else if ($action === 'update_notification_status') {
        requireNotEmpty($data['notification_id'] ?? '', 'Notification id');
        requireNotEmpty($data['status'] ?? '', 'Notification status');
        updateNotificationStatus($pdo, (int)$data['notification_id'], $user_id, $data['status']);
        jsonSuccess();
    } else if ($action === 'acknowledge_escalation') {
        $escalationId = (int)($data['escalation_id'] ?? 0);
        if ($escalationId <= 0) fail('Escalation ID required.');
        // Verify the escalation belongs to a linked student
        $eStmt = $pdo->prepare('SELECT e.student_id FROM Escalations e JOIN Parent_Student ps ON e.student_id = ps.student_id WHERE e.escalation_id = ? AND ps.parent_id = ?');
        $eStmt->execute([$escalationId, $parentId]);
        if (!$eStmt->fetchColumn()) fail('Escalation not found or not linked to your child.', 404);
        $stmt = $pdo->prepare('UPDATE Escalations SET acknowledged_at = NOW(), acknowledged_by = ? WHERE escalation_id = ?');
        $stmt->execute([$user_id, $escalationId]);
        jsonSuccess();
    } else {
        fail('Invalid action.');
    }
}
