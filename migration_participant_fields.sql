-- Editable Participant Information Fields migration
-- Safe to import into an existing Evaluation System database.
SET NAMES utf8mb4;

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
