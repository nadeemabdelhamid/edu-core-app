-- ============================================================
-- EduCore — Videos Module Schema
-- Run this in phpMyAdmin on your educore_saas database
-- ============================================================

-- ── 1. videos table ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS videos (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    center_id        INT NOT NULL,
    group_id         INT DEFAULT NULL,                        -- NULL = center-wide
    title            VARCHAR(255) NOT NULL,
    description      TEXT,
    video_provider   ENUM('youtube','vimeo') NOT NULL DEFAULT 'youtube',
    video_id         VARCHAR(100) NOT NULL,                   -- e.g. dQw4w9WgXcQ
    duration_min     SMALLINT UNSIGNED DEFAULT 0,             -- minutes
    thumbnail_url    VARCHAR(500),
    is_visible       TINYINT(1) DEFAULT 1,
    available_from   DATETIME DEFAULT NULL,                   -- NULL = always available
    available_until  DATETIME DEFAULT NULL,                   -- NULL = never expires
    sort_order       SMALLINT DEFAULT 0,
    created_by       INT NOT NULL,                            -- users.id
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (center_id)  REFERENCES centers(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id)   REFERENCES `groups`(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id),

    INDEX idx_center_group (center_id, group_id),
    INDEX idx_visible      (center_id, is_visible, available_from, available_until)
);

-- ── 2. video_views table (Enterprise analytics) ─────────────
CREATE TABLE IF NOT EXISTS video_views (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    video_id       INT NOT NULL,
    student_id     INT NOT NULL,
    center_id      INT NOT NULL,
    watch_seconds  INT DEFAULT 0,            -- seconds watched in this session
    last_position  INT DEFAULT 0,            -- seconds from start (for resume)
    completed      TINYINT(1) DEFAULT 0,     -- 1 when student watched >= 80%
    first_watched  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_watched   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_video_student (video_id, student_id),

    FOREIGN KEY (video_id)   REFERENCES videos(id)   ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,

    INDEX idx_video (video_id)
);

-- ── 3. Plan feature flags ────────────────────────────────────
-- Add "videos" flag to Pro and Enterprise plans
UPDATE plans
SET features = JSON_SET(features, '$.videos', TRUE)
WHERE slug IN ('pro', 'enterprise');

-- Add "video_analytics" flag to Enterprise only
UPDATE plans
SET features = JSON_SET(features, '$.video_analytics', TRUE)
WHERE slug = 'enterprise';

-- Verify results
SELECT slug, features FROM plans;
