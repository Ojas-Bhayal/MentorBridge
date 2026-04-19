START TRANSACTION;

-- 1. Performance Table Updates
ALTER TABLE Performance 
    ADD COLUMN IF NOT EXISTS mentor_id INT NULL AFTER student_id,
    MODIFY COLUMN attendance DECIMAL(5,2),
    ADD CONSTRAINT fk_perf_mentor FOREIGN KEY (mentor_id) REFERENCES Mentors(mentor_id) ON DELETE CASCADE;

-- 2. Appointment Table Updates
ALTER TABLE Appointments 
    ADD COLUMN IF NOT EXISTS type VARCHAR(15) DEFAULT 'confidential' AFTER status;

-- 3. Create Authorization Link Table
CREATE TABLE IF NOT EXISTS Mentor_Student (
    mentor_id INT,
    student_id INT,
    PRIMARY KEY (mentor_id, student_id),
    FOREIGN KEY (mentor_id) REFERENCES Mentors(mentor_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES Students(student_id) ON DELETE CASCADE
);

-- 4. Create Rate Limiting Table
CREATE TABLE IF NOT EXISTS LoginAttempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_hash VARCHAR(64) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_hash, attempted_at)
);

-- 5. Seed Default Roles
INSERT IGNORE INTO Roles (role_id, role_name) VALUES (1, 'Student'), (2, 'Mentor'), (3, 'Parent');

-- 6. Optimization Indexes
ALTER TABLE Escalations ADD INDEX IF NOT EXISTS idx_esc_student (student_id);
ALTER TABLE Sessions ADD INDEX IF NOT EXISTS idx_sess_mentor (mentor_id);
ALTER TABLE NotificationQueue ADD INDEX IF NOT EXISTS idx_notif_user_status (user_id, status);

ALTER TABLE Students ADD COLUMN connection_code VARCHAR(8) NULL;

-- Generate random 6-character codes for all existing students
UPDATE Students 
SET connection_code = UPPER(SUBSTRING(MD5(RAND()), 1, 6)) 
WHERE connection_code IS NULL;
COMMIT;