-- Event Evaluation and Feedback Management System
-- Import this file into the database that you already created in InfinityFree/cPanel.
-- Do NOT add CREATE DATABASE or USE statements on InfinityFree.

SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NOT NULL DEFAULT 'Administrator',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  title VARCHAR(180) NOT NULL,
  event_type VARCHAR(60) NOT NULL DEFAULT 'Activity',
  theme VARCHAR(255) NULL,
  description TEXT NULL,
  event_date DATE NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  venue VARCHAR(180) NULL,
  organizer VARCHAR(180) NULL,
  speaker VARCHAR(255) NULL,
  target_participants VARCHAR(255) NULL,
  expected_participants INT UNSIGNED NULL,
  status ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
  evaluation_start DATETIME NULL,
  evaluation_deadline DATETIME NULL,
  allow_anonymous TINYINT(1) NOT NULL DEFAULT 1,
  collect_email TINYINT(1) NOT NULL DEFAULT 0,
  google_form_id VARCHAR(180) NULL,
  google_form_url TEXT NULL,
  google_form_edit_url TEXT NULL,
  google_form_summary_url TEXT NULL,
  google_sheet_id VARCHAR(180) NULL,
  google_sheet_url TEXT NULL,
  google_generated_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_events_status_date (status,event_date),
  INDEX idx_events_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS participant_fields (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  field_key VARCHAR(80) NOT NULL,
  label VARCHAR(160) NOT NULL,
  field_type ENUM('text','email','number','select','radio','checkbox','textarea') NOT NULL DEFAULT 'text',
  placeholder VARCHAR(255) NULL,
  options_json TEXT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  hide_when_anonymous TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_participant_field_key (event_id,field_key),
  INDEX idx_participant_fields_event_sort (event_id,is_active,sort_order),
  CONSTRAINT fk_participant_field_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  description VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 1,
  CONSTRAINT fk_category_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX idx_categories_event_sort (event_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NULL,
  question_text TEXT NOT NULL,
  question_type ENUM('likert','multiple_choice','checkbox','yes_no','dropdown','short_answer','paragraph') NOT NULL DEFAULT 'likert',
  likert_style ENUM('quality','agreement') NOT NULL DEFAULT 'quality',
  options_json TEXT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_question_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_question_category FOREIGN KEY (category_id) REFERENCES evaluation_categories(id) ON DELETE SET NULL,
  INDEX idx_questions_event_sort (event_id,sort_order),
  INDEX idx_questions_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS respondents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NULL,
  email VARCHAR(180) NULL,
  department VARCHAR(180) NULL,
  program VARCHAR(180) NULL,
  year_level VARCHAR(60) NULL,
  participant_type VARCHAR(80) NULL,
  is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
  submission_token CHAR(64) NOT NULL,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_respondent_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  UNIQUE KEY uq_submission_token (submission_token),
  INDEX idx_respondents_event_date (event_id,submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS respondent_profile_answers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  respondent_id BIGINT UNSIGNED NOT NULL,
  field_id INT UNSIGNED NOT NULL,
  answer_text TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_profile_answer (respondent_id,field_id),
  INDEX idx_profile_answers_field (field_id),
  CONSTRAINT fk_profile_answer_respondent FOREIGN KEY (respondent_id) REFERENCES respondents(id) ON DELETE CASCADE,
  CONSTRAINT fk_profile_answer_field FOREIGN KEY (field_id) REFERENCES participant_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS response_answers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  respondent_id BIGINT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  answer_text TEXT NULL,
  answer_numeric DECIMAL(8,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_answer_respondent FOREIGN KEY (respondent_id) REFERENCES respondents(id) ON DELETE CASCADE,
  CONSTRAINT fk_answer_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
  INDEX idx_answers_respondent (respondent_id),
  INDEX idx_answers_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key,setting_value) VALUES
('site_title','Event Evaluation and Feedback Management System'),
('organization_name','Your Institution'),
('primary_color','#0b6e8e'),
('welcome_text','Your Feedback Helps Us Improve')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

-- Default account: admin / admin123
INSERT INTO admins (username,password_hash,display_name)
SELECT 'admin','$2y$12$QElQy7PbCBBK4zkLIgt69.O8RCm6TDoUiLijaTs1yafbnGKGptmRC','System Administrator'
WHERE NOT EXISTS (SELECT 1 FROM admins WHERE username='admin');
