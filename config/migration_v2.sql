-- ============================================================
-- Migration v2: Branches, Registration pending status, 
-- Group multi-time schedules, Video fixes
-- Run this on existing databases
-- ============================================================

-- 1. Add branches column to centers (if not exists)
ALTER TABLE centers 
  ADD COLUMN IF NOT EXISTS branches JSON COMMENT 'Array of {name,address,phone} objects' AFTER address;

-- 2. Add 'pending' to centers status enum (if not already present)
ALTER TABLE centers 
  MODIFY COLUMN status ENUM('active','suspended','trial','pending') DEFAULT 'pending';

-- 3. Add 'pending' to subscriptions status enum
ALTER TABLE subscriptions 
  MODIFY COLUMN status ENUM('active','expired','cancelled','trial','pending') DEFAULT 'pending';

-- 4. Ensure video_views table has first_watched column
ALTER TABLE video_views 
  ADD COLUMN IF NOT EXISTS first_watched TIMESTAMP NULL DEFAULT NULL;

-- Update first_watched trigger behavior via default
UPDATE video_views SET first_watched = last_watched WHERE first_watched IS NULL;

