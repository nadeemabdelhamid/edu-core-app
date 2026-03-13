-- ============================================================
-- EduCore — Videos Module Additions Schema
-- Run AFTER schema_videos.sql
-- Adds: PDF attachments, playlists, timestamp comments
-- ============================================================

-- ── 1. PDF attachments ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS video_attachments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    video_id     INT NOT NULL,
    center_id    INT NOT NULL,
    filename     VARCHAR(255) NOT NULL,   -- stored filename on disk
    original_name VARCHAR(255) NOT NULL,  -- original upload name shown to students
    file_size    INT UNSIGNED DEFAULT 0,  -- bytes
    uploaded_by  INT NOT NULL,            -- users.id
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (video_id)    REFERENCES videos(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    INDEX idx_video (video_id)
);

-- ── 2. Video playlists ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS video_playlists (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    center_id   INT NOT NULL,
    group_id    INT DEFAULT NULL,
    title       VARCHAR(255) NOT NULL,
    description TEXT,
    is_visible  TINYINT(1) DEFAULT 1,
    created_by  INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (center_id)  REFERENCES centers(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id)   REFERENCES `groups`(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_center (center_id)
);

-- ── 3. Playlist <-> video pivot (ordered) ───────────────────
CREATE TABLE IF NOT EXISTS playlist_videos (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    playlist_id  INT NOT NULL,
    video_id     INT NOT NULL,
    sort_order   SMALLINT DEFAULT 0,

    UNIQUE KEY uq_pv (playlist_id, video_id),
    FOREIGN KEY (playlist_id) REFERENCES video_playlists(id) ON DELETE CASCADE,
    FOREIGN KEY (video_id)    REFERENCES videos(id)          ON DELETE CASCADE
);

-- ── 4. Timestamp comments / Q&A ─────────────────────────────
CREATE TABLE IF NOT EXISTS video_comments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    video_id     INT NOT NULL,
    center_id    INT NOT NULL,
    parent_id    INT DEFAULT NULL,           -- NULL = top-level; set = reply
    author_id    INT NOT NULL,               -- users.id (teacher or student)
    author_role  ENUM('teacher','owner','assistant','student') NOT NULL,
    timestamp_sec INT DEFAULT NULL,          -- video position in seconds (NULL = general)
    body         TEXT NOT NULL,
    is_deleted   TINYINT(1) DEFAULT 0,       -- soft-delete
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (video_id)  REFERENCES videos(id)         ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES video_comments(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id),
    INDEX idx_video    (video_id),
    INDEX idx_parent   (parent_id)
);
