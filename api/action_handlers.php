<?php
// api/action_handlers.php

function dispatchAction(PDO $pdo, string $role, int $user_id, int $specific_id, string $action, array $data): void
{
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

function handleStudentAction(PDO $pdo, int $user_id, int $studentId, string $action, array $data): void
{
    if ($action === 'update_consent') {
        $sn = !empty($data['allow_session_notes']) ? 1 : 0;
        $fb = !empty($data['allow_feedback']) ? 1 : 0;

        $stmt = $pdo->prepare("INSERT INTO Consent (student_id, allow_session_notes, allow_feedback) 
            VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE 
            allow_session_notes = VALUES(allow_session_notes), 
            allow_feedback = VALUES(allow_feedback)");
        $stmt->execute([$studentId, $sn, $fb]);
        jsonSuccess();
    } else if ($action === 'add_goal') {
        if (empty(trim($data['title'] ?? '')) || empty($data['deadline'])) {
            fail('Title and deadline are required.');
        }
        $stmt = $pdo->prepare("INSERT INTO Goals (student_id, title, description, status, deadline) VALUES (?, ?, ?, 'pending', ?)");
        $stmt->execute([
            $studentId,
            h(trim($data['title'])),
            h(trim($data['description'] ?? '')),
            $data['deadline']
        ]);
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
        // FIXED: Added h() sanitization for edit_goal
        $stmt = $pdo->prepare("UPDATE Goals SET title = ?, description = ? WHERE goal_id = ? AND student_id = ?");
        $stmt->execute([h(trim($data['title'])), h(trim($data['description'] ?? '')), $data['goal_id'], $studentId]);
        if ($stmt->rowCount() === 0)
            fail('Goal not found.', 404);
        jsonSuccess();
    } else if ($action === 'delete_goal') {
        if (empty($data['goal_id']))
            fail('Goal ID is required.');
        $stmt = $pdo->prepare("DELETE FROM Goals WHERE goal_id = ? AND student_id = ?");
        $stmt->execute([$data['goal_id'], $studentId]);
        jsonSuccess();
    } else if ($action === 'respond_parent_link') {
        $requestId = (int) ($data['request_id'] ?? 0);
        $decision = $data['decision'] ?? '';
        if ($requestId <= 0 || !in_array($decision, ['approve', 'deny'], true)) {
            fail('Invalid parent link response.');
        }
        $requestStmt = $pdo->prepare("SELECT plr.parent_id, plr.student_id, u.user_id AS parent_user_id FROM Parent_Link_Requests plr JOIN Parents p ON plr.parent_id = p.parent_id JOIN Users u ON p.user_id = u.user_id WHERE plr.request_id = ? AND plr.student_id = ? AND plr.status = 'pending'");
        $requestStmt->execute([$requestId, $studentId]);
        $request = $requestStmt->fetch();
        if (!$request)
            fail('Link request not found.', 404);

        $newStatus = $decision === 'approve' ? 'approved' : 'denied';
        $update = $pdo->prepare("UPDATE Parent_Link_Requests SET status = ? WHERE request_id = ?");
        $update->execute([$newStatus, $requestId]);

        if ($decision === 'approve') {
            $linkStmt = $pdo->prepare("INSERT IGNORE INTO Parent_Student (parent_id, student_id) VALUES (?, ?)");
            $linkStmt->execute([$request['parent_id'], $request['student_id']]);
            sendNotification($pdo, $request['parent_user_id'], 'Your request to connect was approved.', 'link_response');
        } else {
            sendNotification($pdo, $request['parent_user_id'], 'Your request to connect was denied.', 'link_response');
        }
        jsonSuccess(['decision' => $newStatus]);
    } else if ($action === 'request_appointment') {
        if (empty($data['mentor_id']) || empty($data['requested_time']))
            fail('Required fields missing.');
        if (strtotime($data['requested_time']) <= time())
            fail('Time must be in the future.');
        $stmt = $pdo->prepare('INSERT INTO Appointments (student_id, mentor_id, requested_time, status, type) VALUES (?, ?, ?, "pending", "confidential")');
        $stmt->execute([$studentId, $data['mentor_id'], $data['requested_time']]);
        jsonSuccess();
    } else if ($action === 'update_notification_status') {
        requireNotEmpty($data['notification_id'] ?? '', 'Notification id');
        requireNotEmpty($data['status'] ?? '', 'Notification status');
        updateNotificationStatus($pdo, (int) $data['notification_id'], $user_id, $data['status']);
        jsonSuccess();
    } else if ($action === 'reschedule_appointment') {
        if (empty($data['appointment_id']) || empty($data['requested_time']))
            fail('Required fields missing.');
        if (strtotime($data['requested_time']) <= time())
            fail('Time must be in the future.');
        $stmt = $pdo->prepare('UPDATE Appointments SET requested_time = ?, status = "rescheduled" WHERE appointment_id = ? AND student_id = ?');
        $stmt->execute([$data['requested_time'], (int) $data['appointment_id'], $studentId]);
        jsonSuccess();
    } else if ($action === 'cancel_appointment') {
        if (empty($data['appointment_id']))
            fail('Appointment id is required.');
        $stmt = $pdo->prepare('UPDATE Appointments SET status = "cancelled" WHERE appointment_id = ? AND student_id = ?');
        $stmt->execute([(int) $data['appointment_id'], $studentId]);
        jsonSuccess();
    } else {
        fail('Invalid action.');
    }
}

function handleMentorAction(PDO $pdo, int $user_id, int $mentorId, string $action, array $data): void
{
    if ($action === 'add_feedback') {
        $studentId = (int) ($data['student_id'] ?? 0);
        $snippet = h(trim($data['snippet'] ?? ''));
        if ($studentId <= 0 || $snippet === '')
            fail('Required fields missing.');
        requireMentorStudentLink($pdo, $mentorId, $studentId);
        $consentStmt = $pdo->prepare('SELECT allow_feedback FROM Consent WHERE student_id = ?');
        $consentStmt->execute([$studentId]);
        if ((int) $consentStmt->fetchColumn() !== 1)
            fail('Student has disabled feedback consent.', 403);
        $stmt = $pdo->prepare('INSERT INTO Feedback (student_id, mentor_id, snippet) VALUES (?, ?, ?)');
        $stmt->execute([$studentId, $mentorId, $snippet]);
        jsonSuccess();
    } else if ($action === 'add_session') {
        $studentId = (int) ($data['student_id'] ?? 0);
        $type = $data['type'] ?? 'confidential';
        if ($studentId <= 0 || empty($data['scheduled_at']) || !in_array($type, ['confidential', 'parent'])) {
            fail('Invalid session payload.');
        }

        // 1. Verify Authorization
        requireMentorStudentLink($pdo, $mentorId, $studentId);

        // 2. Create the Session
        $stmt = $pdo->prepare('INSERT INTO Sessions (mentor_id, student_id, scheduled_at, status, type) VALUES (?, ?, ?, "scheduled", ?)');
        $stmt->execute([$mentorId, $studentId, $data['scheduled_at'], $type]);
        jsonSuccess();

    } else if ($action === 'complete_session') {
        if (empty($data['session_id']) || empty(trim($data['notes'] ?? ''))) {
            fail('Session ID and notes are required.');
        }
        $stmt = $pdo->prepare('UPDATE Sessions SET status = "completed", notes = ? WHERE session_id = ? AND mentor_id = ?');
        $stmt->execute([trim($data['notes']), (int) $data['session_id'], $mentorId]);
        jsonSuccess();

    } else if ($action === 'cancel_session') {
        if (empty($data['session_id']))
            fail('Session ID is required.');
        $stmt = $pdo->prepare('UPDATE Sessions SET status = "cancelled" WHERE session_id = ? AND mentor_id = ?');
        $stmt->execute([(int) $data['session_id'], $mentorId]);
        jsonSuccess();
    } else if ($action === 'add_escalation') {
        $studentId = (int) ($data['student_id'] ?? 0);
        $severity = $data['severity'] ?? 'Medium';
        if ($studentId <= 0 || empty(trim($data['trigger_type'] ?? '')) || empty(trim($data['reason'] ?? '')))
            fail('Invalid payload.');
        requireMentorStudentLink($pdo, $mentorId, $studentId);
        $stmt = $pdo->prepare('INSERT INTO Escalations (student_id, trigger_type, reason, severity) VALUES (?, ?, ?, ?)');
        $stmt->execute([$studentId, h(trim($data['trigger_type'])), h(trim($data['reason'])), $severity]);
        jsonSuccess();
    } else if ($action === 'add_performance') {
        $studentId = (int) ($data['student_id'] ?? 0);
        $gpa = floatval($data['gpa'] ?? -1);
        $attendance = floatval($data['attendance'] ?? -1);
        if ($studentId <= 0 || $gpa < 0 || $attendance < 0)
            fail('Invalid metrics.');
        requireMentorStudentLink($pdo, $mentorId, $studentId);

        $stmt = $pdo->prepare('INSERT INTO Performance (student_id, mentor_id, gpa, attendance, exam_score) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$studentId, $mentorId, $gpa, $attendance, $data['exam_score'] ?? null]);

        $newStatus = 'green';
        if ($attendance < RISK_THRESHOLD_ATTENDANCE || $gpa < RISK_THRESHOLD_GPA) {
            $newStatus = 'red';
        } else if ($attendance < WARNING_THRESHOLD_ATTENDANCE || $gpa < WARNING_THRESHOLD_GPA) {
            $newStatus = 'yellow';
        }
        $pdo->prepare('INSERT INTO StudentStatus (student_id, mentor_id, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)')->execute([$studentId, $mentorId, $newStatus]);

        if ($newStatus === 'red') {
            $pStmt = $pdo->prepare('SELECT p.user_id FROM Parent_Student ps JOIN Parents p ON ps.parent_id = p.parent_id WHERE ps.student_id = ?');
            $pStmt->execute([$studentId]);
            $uids = $pStmt->fetchAll(PDO::FETCH_COLUMN);
            $mentorName = h($_SESSION['name'] ?? 'A Mentor');
            foreach ($uids as $pid) {
                $msg = "AUTOMATED ALERT from $mentorName: Child risk level flagged (GPA: $gpa, Attendance: $attendance%).";
                sendNotification($pdo, $pid, $msg, 'alert');
            }
        }
        jsonSuccess();
    } else if ($action === 'send_broadcast') {
        $studentId = (int) ($data['student_id'] ?? 0);
        $target = $data['target'] ?? '';
        $message = trim($data['message'] ?? '');
        if ($studentId <= 0 || $message === '')
            fail('Invalid broadcast payload.');
        requireMentorStudentLink($pdo, $mentorId, $studentId);

        $target_ids = ($target === 'student')
            ? $pdo->prepare('SELECT user_id FROM Students WHERE student_id = ?')
            : $pdo->prepare('SELECT p.user_id FROM Parent_Student ps JOIN Parents p ON ps.parent_id = p.parent_id WHERE ps.student_id = ?');
        $target_ids->execute([$studentId]);
        $uids = $target_ids->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($uids)) {
            $mentorName = h($_SESSION['name'] ?? 'A Mentor');
            $safeMessage = h($message);
            $fullMsg = 'Message from ' . $mentorName . ': ' . $safeMessage;
            $stmt = $pdo->prepare('INSERT INTO NotificationQueue (user_id, message, type, status) VALUES (?, ?, "message", "pending")');
            foreach ($uids as $tuid) {
                // FIXED: Now using the fullMsg which contains sanitized safeMessage
                $stmt->execute([$tuid, $fullMsg]);
            }
            jsonSuccess();
        }
        fail('No target found.');
    } else if ($action === 'add_report') {
        $studentId = (int) ($data['student_id'] ?? 0);
        $summary = h(trim($data['summary'] ?? ''));

        if ($studentId <= 0 || empty($summary) || empty($data['file_path'])) {
            fail('Invalid report payload.');
        }

        // FIX: Verify the mentor is authorized to file a report for this student
        requireMentorStudentLink($pdo, $mentorId, $studentId);

        $stmt = $pdo->prepare('INSERT INTO Reports (student_id, generated_by, month, summary, file_path) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$studentId, $user_id, $data['month'], $summary, $data['file_path']]);
        jsonSuccess();
    } else if ($action === 'approve_appointment' || $action === 'reject_appointment') {
        $appointmentId = (int) ($data['appointment_id'] ?? 0);
        if ($appointmentId <= 0)
            fail('Appointment ID required.');

        $status = ($action === 'approve_appointment') ? 'approved' : 'rejected';

        // Verify ownership: Ensure this appointment is actually assigned to this mentor
        $stmt = $pdo->prepare('UPDATE Appointments SET status = ? WHERE appointment_id = ? AND mentor_id = ?');
        $stmt->execute([$status, $appointmentId, $mentorId]);
        jsonSuccess();

    } else if ($action === 'reschedule_appointment') {
        $appointmentId = (int) ($data['appointment_id'] ?? 0);
        $newTime = $data['requested_time'] ?? '';
        if ($appointmentId <= 0 || empty($newTime))
            fail('Invalid payload.');
        if (strtotime($newTime) <= time())
            fail('Reschedule time must be in the future.');

        // Update status to 'rescheduled' and set the new time
        $stmt = $pdo->prepare('UPDATE Appointments SET status = "rescheduled", requested_time = ? WHERE appointment_id = ? AND mentor_id = ?');
        $stmt->execute([$newTime, $appointmentId, $mentorId]);
        jsonSuccess();
    } else {
        fail('Invalid action.');
    }
}

function handleParentAction(PDO $pdo, int $user_id, int $parentId, string $action, array $data): void
{
    if ($action === 'request_parent_link') {
        $studentEmail = trim(strtolower($data['student_email'] ?? ''));
        $student = getStudentByEmail($pdo, $studentEmail);
        if (!$student)
            fail('Student not found.', 404);

        $existing = $pdo->prepare('SELECT 1 FROM Parent_Student WHERE parent_id = ? AND student_id = ?');
        $existing->execute([$parentId, $student['student_id']]);
        if ($existing->fetch())
            fail('Already linked.');

        $insert = $pdo->prepare('INSERT INTO Parent_Link_Requests (parent_id, student_id, status) VALUES (?, ?, "pending")');
        $insert->execute([$parentId, $student['student_id']]);

        // FIXED: Sanitized parent name for notification
        $parentName = h($_SESSION['name'] ?? 'A parent');
        sendNotification($pdo, $student['user_id'], "A parent ($parentName) has requested to connect.", 'link_request');
        jsonSuccess();
    } else if ($action === 'request_consent_change') {
        $studentId = (int) ($data['student_id'] ?? 0);
        $changes = trim($data['changes'] ?? '');
        if ($studentId <= 0 || empty($changes))
            fail('Invalid request.');
        requireParentStudentLink($pdo, $parentId, $studentId);

        $sStmt = $pdo->prepare('SELECT user_id FROM Students WHERE student_id = ?');
        $sStmt->execute([$studentId]);
        $uid = $sStmt->fetchColumn();

        // FIXED: Sanitized changes for notification
        sendNotification($pdo, $uid, 'Parent requested consent changes: ' . h($changes), 'consent_request');
        jsonSuccess();
    } else if ($action === 'acknowledge_escalation') {
        $escalationId = (int) ($data['escalation_id'] ?? 0);
        if ($escalationId <= 0) {
            fail('Escalation ID required.');
        }

        // FIX: Verify that the escalation belongs to a student linked to this parent
        $verifyStmt = $pdo->prepare("
            SELECT 1 
            FROM Escalations e 
            JOIN Parent_Student ps ON e.student_id = ps.student_id 
            WHERE e.escalation_id = ? AND ps.parent_id = ?
        ");
        $verifyStmt->execute([$escalationId, $parentId]); // $specific_id is the parentId

        if (!$verifyStmt->fetchColumn()) {
            fail('Unauthorized: This escalation does not belong to your child.', 403);
        }

        // Proceed only if authorized
        $stmt = $pdo->prepare('UPDATE Escalations SET acknowledged_at = NOW(), acknowledged_by = ? WHERE escalation_id = ?');
        $stmt->execute([$user_id, $escalationId]);
        jsonSuccess();
    } else if ($action === 'request_appointment') {
        $childId = (int) ($data['student_id'] ?? 0);
        $mentorId = (int) ($data['mentor_id'] ?? 0);
        if ($childId <= 0 || $mentorId <= 0 || empty($data['requested_time']))
            fail('Required fields missing.');
        if (strtotime($data['requested_time']) <= time())
            fail('Time must be in the future.');
        requireParentStudentLink($pdo, $parentId, $childId); // Authorization check
        $stmt = $pdo->prepare('INSERT INTO Appointments (student_id, mentor_id, requested_time, status, type) VALUES (?, ?, ?, "pending", "parent")');
        $stmt->execute([$childId, $mentorId, $data['requested_time']]);
        jsonSuccess();
    } else if ($action === 'reschedule_appointment' || $action === 'cancel_appointment') {
        $appointmentId = (int) ($data['appointment_id'] ?? 0);
        $childId = (int) ($data['student_id'] ?? 0);
        if ($appointmentId <= 0 || $childId <= 0)
            fail('Required fields missing.');
        requireParentStudentLink($pdo, $parentId, $childId); // Authorization check

        if ($action === 'cancel_appointment') {
            $stmt = $pdo->prepare('UPDATE Appointments SET status = "cancelled" WHERE appointment_id = ? AND student_id = ?');
            $stmt->execute([$appointmentId, $childId]);
        } else {
            $stmt = $pdo->prepare('UPDATE Appointments SET requested_time = ?, status = "rescheduled" WHERE appointment_id = ? AND student_id = ?');
            $stmt->execute([$data['requested_time'], $appointmentId, $childId]);
        }
        jsonSuccess();
    } else {
        fail('Invalid action.');
    }
}