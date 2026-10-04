CREATE DATABASE IF NOT EXISTS qrcam CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE qrcam;

CREATE TABLE IF NOT EXISTS students (
    id CHAR(36) PRIMARY KEY,
    student_no VARCHAR(80) NOT NULL UNIQUE,
    full_name VARCHAR(200) NOT NULL,
    class_section VARCHAR(120) NOT NULL DEFAULT '',
    qr_token CHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance (
    id CHAR(36) PRIMARY KEY,
    student_id CHAR(36) NOT NULL,
    attendance_date DATE NOT NULL,
    attended_at DATETIME NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'present',
    note VARCHAR(500) NOT NULL DEFAULT '',
    UNIQUE KEY attendance_once_per_day (student_id, attendance_date),
    CONSTRAINT attendance_student_fk FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id CHAR(36) PRIMARY KEY,
    actor VARCHAR(120) NOT NULL,
    action VARCHAR(40) NOT NULL,
    record_id CHAR(36) NOT NULL,
    before_data JSON NULL,
    after_data JSON NOT NULL,
    created_at DATETIME NOT NULL,
    KEY audit_record_created (record_id, created_at)
) ENGINE=InnoDB;
