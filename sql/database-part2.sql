-- CareerBridge — part 2: skills, candidate profile detail, jobs,
-- assessments, applications, attempts and grooming.
--
-- Run AFTER database.sql.
-- Safe to re-run: part-2 tables are dropped first, and the
-- candidate_profiles columns are added only if they are missing.
--
-- Portability: this file uses no CHECK constraints, no generated
-- columns and no MariaDB-only syntax, so it imports on any MySQL or
-- MariaDB version. The rules those constraints used to enforce are
-- listed at the bottom of this file and live in PHP instead.

USE careerbridge;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS grooming_progress;
DROP TABLE IF EXISTS grooming_resources;
DROP TABLE IF EXISTS attempt_answers;
DROP TABLE IF EXISTS attempts;
DROP TABLE IF EXISTS applications;
DROP TABLE IF EXISTS question_options;
DROP TABLE IF EXISTS questions;
DROP TABLE IF EXISTS assessments;
DROP TABLE IF EXISTS job_skills;
DROP TABLE IF EXISTS candidate_skills;
DROP TABLE IF EXISTS candidate_education;
DROP TABLE IF EXISTS candidate_experience;
DROP TABLE IF EXISTS jobs;
DROP TABLE IF EXISTS skills;
SET FOREIGN_KEY_CHECKS = 1;


-- ================================================================
-- Shared vocabulary
-- Every skill reference in the system points here, so a job's
-- required skill and a grooming resource can never fail to match on
-- spelling. Populate it from seed.sql.
-- ================================================================
CREATE TABLE skills (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
);


-- ================================================================
-- Candidate profile detail
-- candidate_profiles already exists from part 1 with user_id and
-- full_name. These are the fields a company needs to see once a
-- candidate qualifies.
--
-- MySQL has no "ADD COLUMN IF NOT EXISTS", so this procedure checks
-- information_schema first. That keeps the file re-runnable without
-- wiping the profiles you have already registered.
-- ================================================================
DROP PROCEDURE IF EXISTS cb_add_column;

DELIMITER //

CREATE PROCEDURE cb_add_column(
  IN tbl  VARCHAR(64),
  IN col  VARCHAR(64),
  IN spec VARCHAR(255)
)
BEGIN
  DECLARE col_exists INT DEFAULT 0;

  SELECT COUNT(*) INTO col_exists
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = tbl
    AND COLUMN_NAME  = col;

  IF col_exists = 0 THEN
    SET @cb_sql = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', spec);
    PREPARE cb_stmt FROM @cb_sql;
    EXECUTE cb_stmt;
    DEALLOCATE PREPARE cb_stmt;
  END IF;
END //

DELIMITER ;

CALL cb_add_column('candidate_profiles', 'headline',       'VARCHAR(255) DEFAULT NULL');
CALL cb_add_column('candidate_profiles', 'phone',          'VARCHAR(30) DEFAULT NULL');
CALL cb_add_column('candidate_profiles', 'location',       'VARCHAR(255) DEFAULT NULL');
CALL cb_add_column('candidate_profiles', 'summary',        'TEXT');
CALL cb_add_column('candidate_profiles', 'resume_path',    'VARCHAR(500) DEFAULT NULL');
CALL cb_add_column('candidate_profiles', 'expected_title', 'VARCHAR(255) DEFAULT NULL');

DROP PROCEDURE IF EXISTS cb_add_column;


CREATE TABLE candidate_skills (
  candidate_id INT NOT NULL,
  skill_id     INT NOT NULL,
  PRIMARY KEY (candidate_id, skill_id),
  KEY idx_skill (skill_id),
  FOREIGN KEY (candidate_id) REFERENCES users(id)  ON DELETE CASCADE,
  FOREIGN KEY (skill_id)     REFERENCES skills(id) ON DELETE CASCADE
);

CREATE TABLE candidate_education (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT NOT NULL,
  institution  VARCHAR(255) NOT NULL,
  degree       VARCHAR(255) NOT NULL,
  start_year   SMALLINT DEFAULT NULL,
  end_year     SMALLINT DEFAULT NULL,
  KEY idx_candidate (candidate_id),
  FOREIGN KEY (candidate_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE candidate_experience (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT NOT NULL,
  company      VARCHAR(255) NOT NULL,
  job_title    VARCHAR(255) NOT NULL,
  start_date   DATE DEFAULT NULL,
  end_date     DATE DEFAULT NULL,
  description  TEXT,
  KEY idx_candidate (candidate_id),
  FOREIGN KEY (candidate_id) REFERENCES users(id) ON DELETE CASCADE
);


-- ================================================================
-- Jobs
-- cooldown_days and attempt_window_hours drive the "can this
-- candidate still finish attempt 2" check at apply time.
-- ================================================================
CREATE TABLE jobs (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  company_id           INT NOT NULL,
  title                VARCHAR(255) NOT NULL,
  job_type             ENUM('full-time','part-time','internship','contract') NOT NULL DEFAULT 'full-time',
  location             VARCHAR(255) NOT NULL,
  experience_min       TINYINT NOT NULL DEFAULT 0,
  experience_max       TINYINT NOT NULL DEFAULT 0,
  description          TEXT,
  deadline             DATETIME NOT NULL,
  cooldown_days        TINYINT NOT NULL DEFAULT 3,
  attempt_window_hours SMALLINT NOT NULL DEFAULT 24,
  status               ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
  created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status_deadline (status, deadline),
  KEY idx_company (company_id),
  FOREIGN KEY (company_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE job_skills (
  job_id   INT NOT NULL,
  skill_id INT NOT NULL,
  PRIMARY KEY (job_id, skill_id),
  KEY idx_skill (skill_id),
  FOREIGN KEY (job_id)   REFERENCES jobs(id)   ON DELETE CASCADE,
  FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
);


-- ================================================================
-- Assessment
-- One per job. locked_at is NULL while the assessment is still
-- editable; it is stamped on the first attempt submission and never
-- cleared, so scores stay comparable.
-- ================================================================
CREATE TABLE assessments (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  job_id             INT NOT NULL UNIQUE,
  time_limit_minutes SMALLINT NOT NULL DEFAULT 30,
  passing_score      TINYINT NOT NULL DEFAULT 60,
  locked_at          DATETIME DEFAULT NULL,
  FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
);

CREATE TABLE questions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  assessment_id INT NOT NULL,
  question_text TEXT NOT NULL,
  points        TINYINT NOT NULL DEFAULT 5,
  position      SMALLINT NOT NULL DEFAULT 1,
  KEY idx_assessment (assessment_id),
  FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE
);

CREATE TABLE question_options (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  option_text VARCHAR(500) NOT NULL,
  is_correct  TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_question (question_id),
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);


-- ================================================================
-- Applications and attempts
-- status drives every screen: the candidate's application badges,
-- the grooming unlock, and the company's applicant list are all
-- filters on this one column.
--
--   applied   -> sitting attempt 1, or not started
--   grooming  -> failed attempt 1, working through resources
--   qualified -> passed, profile visible to the company
--   rejected  -> failed attempt 2, this job is closed to them
-- ================================================================
CREATE TABLE applications (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  job_id       INT NOT NULL,
  candidate_id INT NOT NULL,
  status       ENUM('applied','grooming','qualified','rejected') NOT NULL DEFAULT 'applied',
  applied_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY one_application_per_job (job_id, candidate_id),
  KEY idx_candidate_status (candidate_id, status),
  FOREIGN KEY (job_id)       REFERENCES jobs(id)  ON DELETE CASCADE,
  FOREIGN KEY (candidate_id) REFERENCES users(id) ON DELETE CASCADE
);

-- expires_at is written when the attempt starts and is never
-- extended, so closing the tab does not pause the clock.
CREATE TABLE attempts (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  attempt_no     TINYINT NOT NULL,
  started_at     DATETIME NOT NULL,
  expires_at     DATETIME NOT NULL,
  submitted_at   DATETIME DEFAULT NULL,
  score_percent  DECIMAL(5,2) DEFAULT NULL,
  passed         TINYINT(1) DEFAULT NULL,
  UNIQUE KEY one_row_per_attempt (application_id, attempt_no),
  FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
);

-- Answers are saved as the candidate picks them, so an abandoned
-- attempt can still be scored from whatever was stored.
CREATE TABLE attempt_answers (
  attempt_id  INT NOT NULL,
  question_id INT NOT NULL,
  option_id   INT DEFAULT NULL,
  PRIMARY KEY (attempt_id, question_id),
  KEY idx_question (question_id),
  KEY idx_option (option_id),
  FOREIGN KEY (attempt_id)  REFERENCES attempts(id)         ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id)        ON DELETE CASCADE,
  FOREIGN KEY (option_id)   REFERENCES question_options(id) ON DELETE CASCADE
);


-- ================================================================
-- Grooming
-- Resources are matched to a failed candidate through the job's
-- required skills. Progress is tracked per resource, so
-- min_watch_seconds is actually enforceable.
--
-- Grooming is complete when every resource matched to the job has a
-- completed_at row for that application — there is no single
-- "grooming done" flag to drift out of sync.
-- ================================================================
CREATE TABLE grooming_resources (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  skill_id          INT NOT NULL,
  title             VARCHAR(255) NOT NULL,
  video_url         VARCHAR(500) NOT NULL,
  min_watch_seconds SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY one_video_per_skill (skill_id, video_url),
  FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
);

CREATE TABLE grooming_progress (
  application_id  INT NOT NULL,
  resource_id     INT NOT NULL,
  watched_seconds SMALLINT NOT NULL DEFAULT 0,
  completed_at    DATETIME DEFAULT NULL,
  PRIMARY KEY (application_id, resource_id),
  KEY idx_resource (resource_id),
  FOREIGN KEY (application_id) REFERENCES applications(id)       ON DELETE CASCADE,
  FOREIGN KEY (resource_id)    REFERENCES grooming_resources(id) ON DELETE CASCADE
);


-- ================================================================
-- Rules the database no longer enforces
--
-- These were CHECK constraints and one generated column. Neither is
-- portable across MySQL versions, so they live in PHP now. Every one
-- is already implemented — do not remove these guards:
--
--   experience_max >= experience_min
--       company-job-create.php, form validation
--
--   passing_score between 1 and 100
--       company-quiz-builder.php, form validation
--
--   points > 0
--       helpers.php, validate_questions()
--
--   attempt_no is 1 or 2
--       helpers.php, start_attempt()
--
--   exactly one correct option per question
--       helpers.php, validate_questions()
--       The UNIQUE index that used to guarantee this is gone, so the
--       quiz builder MUST call validate_questions() before saving.
-- ================================================================
