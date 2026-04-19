-- migration_001.sql
START TRANSACTION;

-- Fix 1: Add mentor_id to Performance (if you chose Option A)
ALTER TABLE Performance 
    ADD COLUMN mentor_id INT NULL AFTER student_id,
    ADD FOREIGN KEY fk_perf_mentor (mentor_id) 
        REFERENCES Mentors(mentor_id) ON DELETE SET NULL;

-- Fix 2: Fix attendance type
ALTER TABLE Performance 
    MODIFY COLUMN attendance DECIMAL(5,2);

-- Fix 3: Add indexes on frequently queried FK columns
ALTER TABLE Escalations ADD INDEX idx_esc_student (student_id);
ALTER TABLE Sessions ADD INDEX idx_sess_mentor (mentor_id), ADD INDEX idx_sess_student (student_id);
ALTER TABLE Appointments ADD INDEX idx_apt_mentor (mentor_id), ADD INDEX idx_apt_student (student_id);
ALTER TABLE NotificationQueue ADD INDEX idx_notif_user_status (user_id, status);
ALTER TABLE Mentor_Student ADD INDEX idx_ms_student (student_id);

-- Fix 4: Rate limiting table (from Fix 8 above)
CREATE TABLE IF NOT EXISTS LoginAttempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_hash VARCHAR(64) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_hash, attempted_at)
);

COMMIT;