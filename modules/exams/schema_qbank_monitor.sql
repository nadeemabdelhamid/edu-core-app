-- ============================================================
-- EduCore: Question Bank v2 + Exam Controls + Focus Monitor
-- Safe to run multiple times (IF NOT EXISTS on everything)
-- ============================================================
USE educore_saas;

-- ─── Question Bank: difficulty + tags ─────────────────────────────────────
ALTER TABLE question_bank
  ADD COLUMN IF NOT EXISTS difficulty ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
  ADD COLUMN IF NOT EXISTS tags       VARCHAR(500) NULL DEFAULT NULL;

-- ─── Exams: visibility and access control ─────────────────────────────────
ALTER TABLE exams
  ADD COLUMN IF NOT EXISTS is_hidden TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = hidden from student list',
  ADD COLUMN IF NOT EXISTS is_open   TINYINT(1) NOT NULL DEFAULT 1
      COMMENT '1 = students can enter, 0 = entry locked';

-- ─── Focus log table ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS exam_focus_log (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exam_id       INT UNSIGNED NOT NULL,
  submission_id INT UNSIGNED NOT NULL,
  student_id    INT UNSIGNED NOT NULL,
  center_id     INT UNSIGNED NOT NULL,
  event         ENUM('blur','focus') NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exam    (exam_id),
  INDEX idx_student (student_id, exam_id),
  INDEX idx_sub     (submission_id),
  INDEX idx_center  (center_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
