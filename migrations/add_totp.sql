-- ============================================================
-- EduCore — TOTP 2FA Migration
-- Run this once on your database before deploying.
-- ============================================================

-- Add TOTP columns to the users table.
-- Only super_admin will ever have these set; all other roles remain NULL.

ALTER TABLE `users`
  ADD COLUMN `totp_secret`  varchar(64)  DEFAULT NULL
    COMMENT 'Base32 TOTP secret (null = 2FA not configured)',
  ADD COLUMN `totp_enabled` tinyint(1)   NOT NULL DEFAULT 0
    COMMENT '1 = TOTP required on login for this user';

-- Index for fast lookup on login (only super_admin rows will ever be 1)
ALTER TABLE `users`
  ADD KEY `idx_totp_enabled` (`totp_enabled`);
