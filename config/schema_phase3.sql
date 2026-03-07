-- ============================================================
-- PHASE 3 — Attendance System Schema Additions
-- Run this on top of the Phase 1/2 schema
-- ============================================================

USE educore_saas;

-- Add session_date auto-set via NOW() and ensure index on session code
ALTER TABLE attendance_sessions
    ADD COLUMN IF NOT EXISTS notes TEXT AFTER topic,
    ADD INDEX IF NOT EXISTS idx_session_code (session_code),
    ADD INDEX IF NOT EXISTS idx_center_date (center_id, session_date),
    ADD INDEX IF NOT EXISTS idx_group_date (group_id, session_date);

-- Add index for fast per-student lookups
ALTER TABLE attendance_records
    ADD INDEX IF NOT EXISTS idx_student_session (student_id, session_id),
    ADD INDEX IF NOT EXISTS idx_center_student (center_id, student_id),
    ADD INDEX IF NOT EXISTS idx_session_status (session_id, status);

-- Trusted networks per center (optional IP restriction feature)
CREATE TABLE IF NOT EXISTS attendance_trusted_networks (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    center_id  INT NOT NULL,
    label      VARCHAR(100),
    ip_range   VARCHAR(100) NOT NULL COMMENT 'CIDR or single IP',
    is_active  TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE CASCADE
);

-- Attendance summary cache (refreshed on session close)
-- Optional: skip if you prefer to compute on the fly
CREATE TABLE IF NOT EXISTS attendance_summary (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    center_id     INT NOT NULL,
    student_id    INT NOT NULL,
    group_id      INT NOT NULL,
    total_sessions INT DEFAULT 0,
    present_count  INT DEFAULT 0,
    absent_count   INT DEFAULT 0,
    late_count     INT DEFAULT 0,
    excused_count  INT DEFAULT 0,
    rate           DECIMAL(5,2) DEFAULT 0,
    last_updated   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_summary (center_id, student_id, group_id),
    FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
);
