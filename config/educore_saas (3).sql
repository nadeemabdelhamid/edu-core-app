-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 15, 2026 at 08:47 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `educore_saas`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance_records`
--

CREATE TABLE `attendance_records` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `status` enum('present','absent','late','excused') DEFAULT 'present',
  `submitted_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_sessions`
--

CREATE TABLE `attendance_sessions` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `session_code` varchar(10) NOT NULL,
  `code_expires_at` datetime NOT NULL,
  `session_date` date NOT NULL,
  `topic` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('open','closed') DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_summary`
--

CREATE TABLE `attendance_summary` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `total_sessions` int(11) DEFAULT 0,
  `present_count` int(11) DEFAULT 0,
  `absent_count` int(11) DEFAULT 0,
  `late_count` int(11) DEFAULT 0,
  `excused_count` int(11) DEFAULT 0,
  `rate` decimal(5,2) DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_trusted_networks`
--

CREATE TABLE `attendance_trusted_networks` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `ip_range` varchar(100) NOT NULL COMMENT 'CIDR or single IP',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `center_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(100) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `centers`
--

CREATE TABLE `centers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `branches` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of {name,address,phone} objects' CHECK (json_valid(`branches`)),
  `logo` varchar(255) DEFAULT NULL,
  `timezone` varchar(100) DEFAULT 'UTC',
  `locale` varchar(10) DEFAULT 'en',
  `language` enum('en','ar') NOT NULL DEFAULT 'en',
  `center_type` varchar(50) DEFAULT NULL,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `status` enum('active','suspended','trial','pending') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `center_branches`
--

CREATE TABLE `center_branches` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `is_main` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exams`
--

CREATE TABLE `exams` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `group_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `type` enum('exam','quiz','practice') DEFAULT 'exam',
  `status` enum('draft','scheduled','active','closed','archived') DEFAULT 'draft',
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT 60,
  `total_points` decimal(8,2) DEFAULT 0.00,
  `passing_score` decimal(5,2) DEFAULT 50.00,
  `shuffle_questions` tinyint(1) DEFAULT 0,
  `shuffle_options` tinyint(1) DEFAULT 0,
  `show_results` enum('immediately','after_close','manual') DEFAULT 'after_close',
  `allow_review` tinyint(1) DEFAULT 1,
  `max_attempts` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Hide exam from student list (0=visible, 1=hidden)',
  `is_open` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Allow students to enter exam (1=open, 0=locked)',
  `exam_password` varchar(100) DEFAULT NULL COMMENT 'If set, students must enter this before starting',
  `force_fullscreen` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Force student into fullscreen during exam',
  `block_copy_paste` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = disable right-click and Ctrl+C/V during exam'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_answers`
--

CREATE TABLE `exam_answers` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `exam_question_id` int(11) NOT NULL,
  `student_answer` text DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `score_awarded` decimal(5,2) DEFAULT 0.00,
  `teacher_comment` text DEFAULT NULL,
  `answered_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_bookmarks`
--

CREATE TABLE `exam_bookmarks` (
  `id` int(10) UNSIGNED NOT NULL,
  `submission_id` int(10) UNSIGNED NOT NULL,
  `question_id` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_focus_log`
--

CREATE TABLE `exam_focus_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `exam_id` int(10) UNSIGNED NOT NULL,
  `submission_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `center_id` int(10) UNSIGNED NOT NULL,
  `event` enum('blur','focus') NOT NULL COMMENT 'blur=left page, focus=returned',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_questions`
--

CREATE TABLE `exam_questions` (
  `id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `question_id` int(11) DEFAULT NULL,
  `type` enum('mcq','true_false','short_answer') NOT NULL DEFAULT 'mcq',
  `question` text NOT NULL,
  `option_a` text DEFAULT NULL,
  `option_b` text DEFAULT NULL,
  `option_c` text DEFAULT NULL,
  `option_d` text DEFAULT NULL,
  `correct_answer` varchar(10) DEFAULT NULL,
  `points` decimal(5,2) NOT NULL DEFAULT 1.00,
  `explanation` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `question_image` varchar(500) DEFAULT NULL COMMENT 'URL to uploaded image for this question',
  `is_multi_select` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = student can pick multiple correct answers',
  `correct_answers_multi` varchar(20) DEFAULT NULL COMMENT 'Comma-separated correct options e.g. a,c for multi-select'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_reopens`
--

CREATE TABLE `exam_reopens` (
  `id` int(10) UNSIGNED NOT NULL,
  `exam_id` int(10) UNSIGNED NOT NULL,
  `student_id` int(10) UNSIGNED NOT NULL,
  `center_id` int(10) UNSIGNED NOT NULL,
  `granted_by` int(10) UNSIGNED NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_submissions`
--

CREATE TABLE `exam_submissions` (
  `id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `attempt_number` int(11) DEFAULT 1,
  `status` enum('in_progress','submitted','graded','cancelled') DEFAULT 'in_progress',
  `started_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL,
  `auto_score` decimal(8,2) DEFAULT 0.00,
  `manual_score` decimal(8,2) DEFAULT 0.00,
  `total_score` decimal(8,2) DEFAULT 0.00,
  `percentage` decimal(5,2) DEFAULT 0.00,
  `passed` tinyint(1) DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `time_spent_sec` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `session_token` varchar(64) DEFAULT NULL COMMENT 'Unique token for active session — second login invalidates first'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `expense_date` date NOT NULL,
  `receipt_ref` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `groups`
--

CREATE TABLE `groups` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `capacity` int(11) DEFAULT 30,
  `schedule` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`schedule`)),
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `monthly_fee` decimal(10,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `session_duration_minutes` int(11) DEFAULT 90 COMMENT 'Duration in minutes for auto-closing attendance sessions'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_students`
--

CREATE TABLE `group_students` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive','completed') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `center_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_requests`
--

CREATE TABLE `password_reset_requests` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `requested_at` datetime DEFAULT current_timestamp(),
  `status` enum('pending','resolved','dismissed') DEFAULT 'pending',
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `group_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) DEFAULT 0.00,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `paid_date` date DEFAULT NULL,
  `month_year` varchar(7) DEFAULT NULL,
  `payment_method` enum('cash','bank_transfer','card','other') DEFAULT 'cash',
  `status` enum('paid','partial','unpaid','overdue') DEFAULT 'unpaid',
  `notes` text DEFAULT NULL,
  `receipt_number` varchar(100) DEFAULT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `plans`
--

CREATE TABLE `plans` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `plan_type` enum('individual','academy') NOT NULL DEFAULT 'academy',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `billing_cycle` enum('monthly','yearly','lifetime') DEFAULT 'monthly',
  `max_students` int(11) DEFAULT 50,
  `max_teachers` int(11) DEFAULT 3,
  `max_groups` int(11) DEFAULT 10,
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features`)),
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `playlist_videos`
--

CREATE TABLE `playlist_videos` (
  `id` int(11) NOT NULL,
  `playlist_id` int(11) NOT NULL,
  `video_id` int(11) NOT NULL,
  `sort_order` smallint(6) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `question_bank`
--

CREATE TABLE `question_bank` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `topic` varchar(255) DEFAULT NULL,
  `type` enum('mcq','true_false','short_answer') NOT NULL DEFAULT 'mcq',
  `question` text NOT NULL,
  `option_a` text DEFAULT NULL,
  `option_b` text DEFAULT NULL,
  `option_c` text DEFAULT NULL,
  `option_d` text DEFAULT NULL,
  `correct_answer` varchar(10) DEFAULT NULL,
  `points` decimal(5,2) NOT NULL DEFAULT 1.00,
  `explanation` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `difficulty` enum('easy','medium','hard') NOT NULL DEFAULT 'medium' COMMENT 'Question difficulty level',
  `tags` varchar(500) DEFAULT NULL COMMENT 'Comma-separated tags for filtering',
  `question_image` varchar(500) DEFAULT NULL COMMENT 'URL to uploaded image for this question'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL,
  `identifier` varchar(100) NOT NULL,
  `action` varchar(50) NOT NULL,
  `attempts` int(11) DEFAULT 1,
  `window_start` datetime NOT NULL,
  `last_attempt` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_invitations`
--

CREATE TABLE `staff_invitations` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `invited_by` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` enum('teacher','assistant','accountant') NOT NULL DEFAULT 'teacher',
  `token` varchar(100) NOT NULL,
  `status` enum('pending','accepted','expired') DEFAULT 'pending',
  `expires_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `student_code` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `parent_phone` varchar(50) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_teachers`
--

CREATE TABLE `student_teachers` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `status` enum('active','expired','cancelled','trial','pending') DEFAULT 'pending',
  `starts_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `trial_ends_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscription_history`
--

CREATE TABLE `subscription_history` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `from_plan` int(11) DEFAULT NULL,
  `to_plan` int(11) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `center_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','owner','teacher','assistant','accountant','student') NOT NULL DEFAULT 'teacher',
  `avatar` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `videos`
--

CREATE TABLE `videos` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `group_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `video_provider` enum('youtube','vimeo') NOT NULL DEFAULT 'youtube',
  `video_id` varchar(100) NOT NULL,
  `duration_min` smallint(5) UNSIGNED DEFAULT 0,
  `thumbnail_url` varchar(500) DEFAULT NULL,
  `is_visible` tinyint(1) DEFAULT 1,
  `available_from` datetime DEFAULT NULL,
  `available_until` datetime DEFAULT NULL,
  `sort_order` smallint(6) DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `owner_teacher_id` int(11) DEFAULT NULL,
  `is_global` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `video_attachments`
--

CREATE TABLE `video_attachments` (
  `id` int(11) NOT NULL,
  `video_id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_size` int(10) UNSIGNED DEFAULT 0,
  `uploaded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `video_comments`
--

CREATE TABLE `video_comments` (
  `id` int(11) NOT NULL,
  `video_id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `author_id` int(11) NOT NULL,
  `author_role` enum('teacher','owner','assistant','student') NOT NULL,
  `timestamp_sec` int(11) DEFAULT NULL,
  `body` text NOT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `video_playlists`
--

CREATE TABLE `video_playlists` (
  `id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `group_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_visible` tinyint(1) DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `video_views`
--

CREATE TABLE `video_views` (
  `id` int(11) NOT NULL,
  `video_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `center_id` int(11) NOT NULL,
  `watch_seconds` int(11) DEFAULT 0,
  `last_position` int(11) DEFAULT 0,
  `completed` tinyint(1) DEFAULT 0,
  `first_watched` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_watched` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`session_id`,`student_id`),
  ADD KEY `idx_student_session` (`student_id`,`session_id`),
  ADD KEY `idx_center_student` (`center_id`,`student_id`),
  ADD KEY `idx_session_status` (`session_id`,`status`);

--
-- Indexes for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `idx_session_code` (`session_code`),
  ADD KEY `idx_center_date` (`center_id`,`session_date`),
  ADD KEY `idx_group_date` (`group_id`,`session_date`),
  ADD KEY `idx_att_sessions_status_date` (`status`,`session_date`);

--
-- Indexes for table `attendance_summary`
--
ALTER TABLE `attendance_summary`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_summary` (`center_id`,`student_id`,`group_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `group_id` (`group_id`);

--
-- Indexes for table `attendance_trusted_networks`
--
ALTER TABLE `attendance_trusted_networks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `center_id` (`center_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `center_id` (`center_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `centers`
--
ALTER TABLE `centers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `center_branches`
--
ALTER TABLE `center_branches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_center` (`center_id`);

--
-- Indexes for table `exams`
--
ALTER TABLE `exams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `center_id` (`center_id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `exam_answers`
--
ALTER TABLE `exam_answers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_answer` (`submission_id`,`exam_question_id`),
  ADD KEY `exam_question_id` (`exam_question_id`);

--
-- Indexes for table `exam_bookmarks`
--
ALTER TABLE `exam_bookmarks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sub_q` (`submission_id`,`question_id`);

--
-- Indexes for table `exam_focus_log`
--
ALTER TABLE `exam_focus_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_exam` (`exam_id`),
  ADD KEY `idx_student` (`student_id`,`exam_id`),
  ADD KEY `idx_sub` (`submission_id`),
  ADD KEY `idx_center` (`center_id`);

--
-- Indexes for table `exam_questions`
--
ALTER TABLE `exam_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `exam_id` (`exam_id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `exam_reopens`
--
ALTER TABLE `exam_reopens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_exam_student` (`exam_id`,`student_id`);

--
-- Indexes for table `exam_submissions`
--
ALTER TABLE `exam_submissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_attempt` (`exam_id`,`student_id`,`attempt_number`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `center_id` (`center_id`),
  ADD KEY `graded_by` (`graded_by`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_e_center_date` (`center_id`,`expense_date`),
  ADD KEY `idx_e_center_category` (`center_id`,`category`);

--
-- Indexes for table `groups`
--
ALTER TABLE `groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `center_id` (`center_id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `idx_groups_active_schedule` (`is_active`,`center_id`);

--
-- Indexes for table `group_students`
--
ALTER TABLE `group_students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_enrollment` (`group_id`,`student_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `center_id` (`center_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_center` (`center_id`,`created_at`);

--
-- Indexes for table `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `idx_p_center_status` (`center_id`,`status`),
  ADD KEY `idx_p_student` (`student_id`,`center_id`),
  ADD KEY `idx_p_paid_date` (`center_id`,`paid_date`),
  ADD KEY `idx_p_due_date` (`center_id`,`due_date`,`status`);

--
-- Indexes for table `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `playlist_videos`
--
ALTER TABLE `playlist_videos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pv` (`playlist_id`,`video_id`),
  ADD KEY `video_id` (`video_id`);

--
-- Indexes for table `question_bank`
--
ALTER TABLE `question_bank`
  ADD PRIMARY KEY (`id`),
  ADD KEY `center_id` (`center_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ident_action` (`identifier`,`action`);

--
-- Indexes for table `staff_invitations`
--
ALTER TABLE `staff_invitations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `invited_by` (`invited_by`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_center_email` (`center_id`,`email`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD KEY `center_id` (`center_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `student_teachers`
--
ALTER TABLE `student_teachers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_student_teacher` (`student_id`,`teacher_id`),
  ADD KEY `idx_center` (`center_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_teacher` (`teacher_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `center_id` (`center_id`),
  ADD KEY `plan_id` (`plan_id`);

--
-- Indexes for table `subscription_history`
--
ALTER TABLE `subscription_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `center_id` (`center_id`),
  ADD KEY `from_plan` (`from_plan`),
  ADD KEY `to_plan` (`to_plan`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_center_role` (`center_id`,`role`);

--
-- Indexes for table `videos`
--
ALTER TABLE `videos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_center_group` (`center_id`,`group_id`),
  ADD KEY `idx_visible` (`center_id`,`is_visible`,`available_from`,`available_until`);

--
-- Indexes for table `video_attachments`
--
ALTER TABLE `video_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `idx_video` (`video_id`);

--
-- Indexes for table `video_comments`
--
ALTER TABLE `video_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `author_id` (`author_id`),
  ADD KEY `idx_video` (`video_id`),
  ADD KEY `idx_parent` (`parent_id`);

--
-- Indexes for table `video_playlists`
--
ALTER TABLE `video_playlists`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_center` (`center_id`);

--
-- Indexes for table `video_views`
--
ALTER TABLE `video_views`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_video_student` (`video_id`,`student_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `idx_video` (`video_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance_records`
--
ALTER TABLE `attendance_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_summary`
--
ALTER TABLE `attendance_summary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_trusted_networks`
--
ALTER TABLE `attendance_trusted_networks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `centers`
--
ALTER TABLE `centers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `center_branches`
--
ALTER TABLE `center_branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exams`
--
ALTER TABLE `exams`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_answers`
--
ALTER TABLE `exam_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_bookmarks`
--
ALTER TABLE `exam_bookmarks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_focus_log`
--
ALTER TABLE `exam_focus_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_questions`
--
ALTER TABLE `exam_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_reopens`
--
ALTER TABLE `exam_reopens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_submissions`
--
ALTER TABLE `exam_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `groups`
--
ALTER TABLE `groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_students`
--
ALTER TABLE `group_students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plans`
--
ALTER TABLE `plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `playlist_videos`
--
ALTER TABLE `playlist_videos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `question_bank`
--
ALTER TABLE `question_bank`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_invitations`
--
ALTER TABLE `staff_invitations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_teachers`
--
ALTER TABLE `student_teachers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscription_history`
--
ALTER TABLE `subscription_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `videos`
--
ALTER TABLE `videos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `video_attachments`
--
ALTER TABLE `video_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `video_comments`
--
ALTER TABLE `video_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `video_playlists`
--
ALTER TABLE `video_playlists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `video_views`
--
ALTER TABLE `video_views`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD CONSTRAINT `attendance_records_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_records_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_records_ibfk_3` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD CONSTRAINT `attendance_sessions_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_sessions_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_sessions_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_summary`
--
ALTER TABLE `attendance_summary`
  ADD CONSTRAINT `attendance_summary_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_summary_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_summary_ibfk_3` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_trusted_networks`
--
ALTER TABLE `attendance_trusted_networks`
  ADD CONSTRAINT `attendance_trusted_networks_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `audit_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `center_branches`
--
ALTER TABLE `center_branches`
  ADD CONSTRAINT `center_branches_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exams`
--
ALTER TABLE `exams`
  ADD CONSTRAINT `exams_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exams_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `exams_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exam_answers`
--
ALTER TABLE `exam_answers`
  ADD CONSTRAINT `exam_answers_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `exam_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exam_answers_ibfk_2` FOREIGN KEY (`exam_question_id`) REFERENCES `exam_questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exam_questions`
--
ALTER TABLE `exam_questions`
  ADD CONSTRAINT `exam_questions_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exam_questions_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `question_bank` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `exam_submissions`
--
ALTER TABLE `exam_submissions`
  ADD CONSTRAINT `exam_submissions_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exam_submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exam_submissions_ibfk_3` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exam_submissions_ibfk_4` FOREIGN KEY (`graded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `expenses_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `groups`
--
ALTER TABLE `groups`
  ADD CONSTRAINT `groups_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `groups_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `group_students`
--
ALTER TABLE `group_students`
  ADD CONSTRAINT `group_students_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_students_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_students_ibfk_3` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `playlist_videos`
--
ALTER TABLE `playlist_videos`
  ADD CONSTRAINT `playlist_videos_ibfk_1` FOREIGN KEY (`playlist_id`) REFERENCES `video_playlists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `playlist_videos_ibfk_2` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `question_bank`
--
ALTER TABLE `question_bank`
  ADD CONSTRAINT `question_bank_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `question_bank_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_invitations`
--
ALTER TABLE `staff_invitations`
  ADD CONSTRAINT `staff_invitations_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `staff_invitations_ibfk_2` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `students_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscriptions_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`);

--
-- Constraints for table `subscription_history`
--
ALTER TABLE `subscription_history`
  ADD CONSTRAINT `subscription_history_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscription_history_ibfk_2` FOREIGN KEY (`from_plan`) REFERENCES `plans` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `subscription_history_ibfk_3` FOREIGN KEY (`to_plan`) REFERENCES `plans` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscription_history_ibfk_4` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `videos`
--
ALTER TABLE `videos`
  ADD CONSTRAINT `videos_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `videos_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `videos_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `video_attachments`
--
ALTER TABLE `video_attachments`
  ADD CONSTRAINT `video_attachments_ibfk_1` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `video_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `video_comments`
--
ALTER TABLE `video_comments`
  ADD CONSTRAINT `video_comments_ibfk_1` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `video_comments_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `video_comments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `video_comments_ibfk_3` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `video_playlists`
--
ALTER TABLE `video_playlists`
  ADD CONSTRAINT `video_playlists_ibfk_1` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `video_playlists_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `video_playlists_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `video_views`
--
ALTER TABLE `video_views`
  ADD CONSTRAINT `video_views_ibfk_1` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `video_views_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
