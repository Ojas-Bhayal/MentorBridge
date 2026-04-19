-- 1. USERS + RBAC
CREATE TABLE IF NOT EXISTS Roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(20) UNIQUE
);

CREATE TABLE IF NOT EXISTS Users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE,
    password VARCHAR(255) NOT NULL,
    role_id INT,
    FOREIGN KEY (role_id) REFERENCES Roles(role_id) ON DELETE SET NULL
);

-- 2. FLEXIBLE FAMILY STRUCTURE
CREATE TABLE IF NOT EXISTS Parents (
    parent_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Mentors (
    mentor_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Parent_Student (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    student_id INT NOT NULL,
    UNIQUE KEY uniq_parent_student (parent_id, student_id),
    FOREIGN KEY (parent_id) REFERENCES Parents(parent_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES Students(student_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Parent_Link_Requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    student_id INT NOT NULL,
    status VARCHAR(15) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES Parents(parent_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES Students(student_id) ON DELETE CASCADE,
    UNIQUE KEY uniq_parent_student_pending (parent_id, student_id, status)
);

-- 3. STUDENT STATUS
CREATE TABLE IF NOT EXISTS StudentStatus (
    status_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    mentor_id INT,
    status VARCHAR(10) CHECK (status IN ('red', 'yellow', 'green')),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES Students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (mentor_id) REFERENCES Mentors(mentor_id) ON DELETE CASCADE,
    UNIQUE KEY uniq_student_mentor_status (student_id, mentor_id)
);

-- 4. PERFORMANCE
CREATE TABLE IF NOT EXISTS Performance (
    
    performance_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    gpa DECIMAL(3,2),
    attendance INT,
    exam_score DECIMAL(5,2),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES Students(student_id) ON DELETE CASCADE
);

-- 5. CONSENT
CREATE TABLE IF NOT EXISTS Consent (
    consent_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNIQUE,
    allow_session_notes TINYINT(1) DEFAULT 0,
    allow_feedback TINYINT(1) DEFAULT 1,
    FOREIGN KEY (student_id) REFERENCES Students(student_id) ON DELETE CASCADE
);

-- 6. SESSIONS
CREATE TABLE IF NOT EXISTS Sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    mentor_id INT,
    student_id INT,
    scheduled_at TIMESTAMP,
    status VARCHAR(15) CHECK (status IN ('scheduled', 'completed', 'cancelled')),
    type VARCHAR(15) CHECK (type IN ('confidential', 'parent')),
    notes TEXT,
    FOREIGN KEY (mentor_id) REFERENCES Mentors(mentor_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES Students(student_id) ON DELETE CASCADE
);

-- 7. APPOINTMENTS
CREATE TABLE IF NOT EXISTS Appointments (
    appointment_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    mentor_id INT,
    requested_time TIMESTAMP,
    status VARCHAR(15) CHECK (status IN ('pending', 'approved', 'rescheduled', 'rejected', 'cancelled')),
    FOREIGN KEY (student_id) REFERENCES Students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (mentor_id) REFERENCES Mentors(mentor_id) ON DELETE CASCADE
);

-- 8. GOALS
CREATE TABLE IF NOT EXISTS Goals (
    goal_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    title VARCHAR(255),
    description TEXT,
    status VARCHAR(15) CHECK (status IN ('pending', 'in_progress', 'completed')),
    deadline DATE,
    FOREIGN KEY (student_id) REFERENCES Students(student_id) ON DELETE CASCADE
);

-- 9. FEEDBACK
CREATE TABLE IF NOT EXISTS Feedback (
    feedback_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    mentor_id INT,
    snippet TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES Students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (mentor_id) REFERENCES Mentors(mentor_id) ON DELETE CASCADE
);

-- 10. ESCALATIONS
CREATE TABLE IF NOT EXISTS Escalations (
    escalation_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    trigger_type VARCHAR(50),
    reason TEXT,
    severity VARCHAR(10),
    triggered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at TIMESTAMP NULL DEFAULT NULL,
    acknowledged_by INT NULL DEFAULT NULL,
    FOREIGN KEY (student_id) REFERENCES Students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (acknowledged_by) REFERENCES Users(user_id) ON DELETE SET NULL
);

-- 11. NOTIFICATIONS
CREATE TABLE IF NOT EXISTS NotificationQueue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    message TEXT,
    type VARCHAR(20),
    status VARCHAR(10),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
);

-- 12. REPORTS
CREATE TABLE IF NOT EXISTS Reports (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    generated_by INT,
    month DATE,
    summary TEXT,
    file_path TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES Students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (generated_by) REFERENCES Users(user_id) ON DELETE SET NULL
);