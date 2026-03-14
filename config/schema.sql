-- EduCore SaaS Platform - Database Schema
-- Phase 1: Foundation

CREATE DATABASE IF NOT EXISTS educore_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE educore_saas;

-- ============================================================
-- PLANS & SUBSCRIPTIONS (Platform level)
-- ============================================================
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    billing_cycle ENUM('monthly','yearly','lifetime') DEFAULT 'monthly',
    max_students INT DEFAULT 50,
    max_teachers INT DEFAULT 3,
    max_groups INT DEFAULT 10,
    features JSON,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO plans (name, slug, price, billing_cycle, max_students, max_teachers, max_groups, features) VALUES
('Starter', 'starter', 0.00, 'monthly', 30, 1, 5, '{"attendance":true,"exams":false,"payments":false,"reports":false,"export":false}'),
('Pro', 'pro', 29.00, 'monthly', 200, 10, 30, '{"attendance":true,"exams":true,"payments":true,"reports":true,"export":false}'),
('Enterprise', 'enterprise', 79.00, 'monthly', 999999, 999999, 999999, '{"attendance":true,"exams":true,"payments":true,"reports":true,"export":true}');

-- ============================================================
-- CENTERS (Tenants)
-- ============================================================
CREATE TABLE IF NOT EXISTS centers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    phone VARCHAR(50),
    address TEXT,
    branches JSON COMMENT 'Array of branch objects with name/address/phone',
    logo VARCHAR(255),
    timezone VARCHAR(100) DEFAULT 'UTC',
    locale VARCHAR(10) DEFAULT 'en',
    settings JSON,
    status ENUM('active','suspended','trial','pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- SUBSCRIPTIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    center_id INT NOT NULL,
    plan_id INT NOT NULL,
    status ENUM('active','expired','cancelled','trial') DEFAULT 'trial',
    starts_at DATETIME NOT NULL,
    expires_at DATETIME,
    trial_ends_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id)
);

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    center_id INT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('super_admin','owner','teacher','assistant','accountant','student') NOT NULL DEFAULT 'teacher',
    avatar VARCHAR(255),
    phone VARCHAR(50),
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME,
    remember_token VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE CASCADE
);

-- Super admin (platform level, no center_id)
INSERT INTO users (center_id, name, email, password, role) VALUES
(NULL, 'Super Admin', 'admin@educore.io', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin');

-- ============================================================
-- STUDENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    center_id INT NOT NULL,
    user_id INT,
    student_code VARCHAR(50),
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(50),
    parent_phone VARCHAR(50),
    date_of_birth DATE,
    gender ENUM('male','female','other'),
    address TEXT,
    branches JSON COMMENT 'Array of branch objects with name/address/phone',
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- GROUPS
-- ============================================================
CREATE TABLE IF NOT EXISTS groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    center_id INT NOT NULL,
    teacher_id INT,
    name VARCHAR(255) NOT NULL,
    subject VARCHAR(255),
    description TEXT,
    capacity INT DEFAULT 30,
    schedule JSON,
    start_date DATE,
    end_date DATE,
    monthly_fee DECIMAL(10,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================================
-- GROUP STUDENTS (Enrollment)
-- ============================================================
CREATE TABLE IF NOT EXISTS group_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    student_id INT NOT NULL,
    center_id INT NOT NULL,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active','inactive','completed') DEFAULT 'active',
    UNIQUE KEY unique_enrollment (group_id, student_id),
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE CASCADE
);

-- ============================================================
-- ATTENDANCE SESSIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS attendance_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    center_id INT NOT NULL,
    group_id INT NOT NULL,
    teacher_id INT NOT NULL,
    session_code VARCHAR(10) NOT NULL,
    code_expires_at DATETIME NOT NULL,
    session_date DATE NOT NULL,
    topic VARCHAR(255),
    status ENUM('open','closed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- ATTENDANCE RECORDS
-- ============================================================
CREATE TABLE IF NOT EXISTS attendance_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    student_id INT NOT NULL,
    center_id INT NOT NULL,
    status ENUM('present','absent','late','excused') DEFAULT 'present',
    submitted_at DATETIME,
    ip_address VARCHAR(45),
    notes TEXT,
    UNIQUE KEY unique_attendance (session_id, student_id),
    FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE CASCADE
);

-- ============================================================
-- PAYMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    center_id INT NOT NULL,
    student_id INT NOT NULL,
    group_id INT,
    amount DECIMAL(10,2) NOT NULL,
    paid_amount DECIMAL(10,2) DEFAULT 0,
    due_date DATE,
    paid_date DATE,
    month_year VARCHAR(7),
    payment_method ENUM('cash','bank_transfer','card','other') DEFAULT 'cash',
    status ENUM('paid','partial','unpaid','overdue') DEFAULT 'unpaid',
    notes TEXT,
    receipt_number VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE SET NULL
);

-- ============================================================
-- AUDIT LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    center_id INT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100),
    entity_id INT,
    details JSON,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
