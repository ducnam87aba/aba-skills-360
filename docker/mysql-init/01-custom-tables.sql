-- ABA Skills 360 - Database Initial Schema
-- Aligned with docs/database-schema.md and AGENTS.md

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

-- 1. Schools
CREATE TABLE IF NOT EXISTS wp_aba_schools (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    province VARCHAR(120) NULL,
    district VARCHAR(120) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_school_code (code),
    KEY idx_school_status (status),
    KEY idx_school_province (province)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Classes
CREATE TABLE IF NOT EXISTS wp_aba_classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    grade_level TINYINT UNSIGNED NOT NULL,
    grade_group VARCHAR(20) NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_class_school_code (school_id, code),
    KEY idx_class_school (school_id),
    KEY idx_class_grade_level (grade_level),
    KEY idx_class_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Students
CREATE TABLE IF NOT EXISTS wp_aba_students (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NULL,
    student_code VARCHAR(50) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    dob DATE NULL,
    gender VARCHAR(20) NULL,
    grade_level TINYINT UNSIGNED NOT NULL,
    grade_group VARCHAR(20) NOT NULL,
    total_xp INT UNSIGNED NOT NULL DEFAULT 0,
    current_streak INT UNSIGNED NOT NULL DEFAULT 0,
    longest_streak INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    last_active_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_student_code (student_code),
    UNIQUE KEY uk_student_user (user_id),
    KEY idx_student_school_class (school_id, class_id),
    KEY idx_student_grade_group (grade_group),
    KEY idx_student_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Parent - Student Mapping
CREATE TABLE IF NOT EXISTS wp_aba_parent_student (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_user_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    relationship VARCHAR(30) NOT NULL DEFAULT 'parent',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_parent_student (parent_user_id, student_id),
    KEY idx_ps_parent (parent_user_id),
    KEY idx_ps_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Assessment Sessions
CREATE TABLE IF NOT EXISTS wp_aba_assessment_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    school_id BIGINT UNSIGNED NOT NULL,
    assessment_version VARCHAR(20) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'in_progress',
    scoring_strategy VARCHAR(50) NOT NULL DEFAULT 'standard',
    current_step INT UNSIGNED NOT NULL DEFAULT 0,
    total_steps INT UNSIGNED NOT NULL DEFAULT 0,
    raw_score DECIMAL(8,2) NULL,
    normalized_score DECIMAL(5,2) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_session_student (student_id),
    KEY idx_session_assessment (assessment_id),
    KEY idx_session_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Assessment Responses
CREATE TABLE IF NOT EXISTS wp_aba_assessment_responses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    response_value TEXT NOT NULL,
    score_value DECIMAL(8,2) NULL,
    latency_ms INT UNSIGNED NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_session_question (session_id, question_id),
    KEY idx_resp_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Skill Scores
CREATE TABLE IF NOT EXISTS wp_aba_skill_scores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    skill_code VARCHAR(50) NOT NULL,
    skill_category VARCHAR(50) NOT NULL,
    score DECIMAL(5,2) NOT NULL DEFAULT 0,
    level VARCHAR(30) NOT NULL DEFAULT 'beginning',
    last_assessment_session_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_student_skill (student_id, skill_code),
    KEY idx_skill_student (student_id),
    KEY idx_skill_category (skill_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Skill Snapshots
CREATE TABLE IF NOT EXISTS wp_aba_skill_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    assessment_session_id BIGINT UNSIGNED NOT NULL,
    scores_json LONGTEXT NOT NULL,
    snapshot_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_snapshot_student (student_id),
    KEY idx_snapshot_date (snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Lesson Progress
CREATE TABLE IF NOT EXISTS wp_aba_lesson_progress (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    node_id VARCHAR(100) NOT NULL,
    course_id BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'in_progress',
    score DECIMAL(5,2) NULL,
    xp_awarded INT UNSIGNED NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_student_node (student_id, node_id),
    KEY idx_progress_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. XP Transactions Ledger
CREATE TABLE IF NOT EXISTS wp_aba_xp_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    amount INT NOT NULL,
    balance_after INT UNSIGNED NOT NULL,
    source_type VARCHAR(50) NOT NULL, -- lesson, streak, mission, assessment
    source_id VARCHAR(100) NULL,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_xp_student (student_id),
    KEY idx_xp_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Streaks
CREATE TABLE IF NOT EXISTS wp_aba_streaks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    activity_date DATE NOT NULL,
    streak_count INT UNSIGNED NOT NULL DEFAULT 1,
    freeze_used TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_student_date (student_id, activity_date),
    KEY idx_streak_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Mission Progress
CREATE TABLE IF NOT EXISTS wp_aba_mission_progress (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    mission_id BIGINT UNSIGNED NOT NULL,
    current_progress INT UNSIGNED NOT NULL DEFAULT 0,
    target_progress INT UNSIGNED NOT NULL DEFAULT 1,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    period_date DATE NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_student_mission_period (student_id, mission_id, period_date),
    KEY idx_mission_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Student Badges
CREATE TABLE IF NOT EXISTS wp_aba_student_badges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    badge_code VARCHAR(50) NOT NULL,
    awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_student_badge (student_id, badge_code),
    KEY idx_badge_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Reports
CREATE TABLE IF NOT EXISTS wp_aba_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    report_type VARCHAR(50) NOT NULL DEFAULT 'skills_summary',
    title VARCHAR(255) NOT NULL,
    data_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_report_student (student_id),
    KEY idx_report_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Events (Audit & Analytics)
CREATE TABLE IF NOT EXISTS wp_aba_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    student_id BIGINT UNSIGNED NULL,
    event_name VARCHAR(100) NOT NULL,
    payload_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_event_name (event_name),
    KEY idx_event_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
