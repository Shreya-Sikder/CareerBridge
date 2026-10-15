-- CareerBridge — part 6: profile photo, projects, achievements,
-- and the community feed.
--
-- Run AFTER database-part5.sql. Safe to re-run.
--
-- Nothing existing is dropped or altered beyond adding two columns.
-- The spec mentions `candidates` and `companies` tables; this project
-- has candidate_profiles and company_profiles keyed on users.id, so
-- those are what the new tables reference. There is still exactly one
-- row of profile data per candidate, which is what keeps a company's
-- view of a candidate automatically current.

USE careerbridge;

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

CALL cb_add_column('candidate_profiles', 'photo_path', 'VARCHAR(500) DEFAULT NULL');
CALL cb_add_column('candidate_profiles', 'photo_name', 'VARCHAR(255) DEFAULT NULL');

DROP PROCEDURE IF EXISTS cb_add_column;


-- ================================================================
-- Projects
--
-- video_url holds a YouTube link rather than an uploaded file.
-- Hosting video on XAMPP means multi-hundred-megabyte uploads,
-- transcoding and streaming, none of which this project needs.
-- ================================================================
CREATE TABLE IF NOT EXISTS candidate_projects (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT NOT NULL,
  title        VARCHAR(255) NOT NULL,
  description  TEXT,
  technologies VARCHAR(500) DEFAULT NULL,
  project_url  VARCHAR(500) DEFAULT NULL,
  video_url    VARCHAR(500) DEFAULT NULL,
  image_path   VARCHAR(500) DEFAULT NULL,
  position     SMALLINT NOT NULL DEFAULT 1,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_candidate (candidate_id),
  FOREIGN KEY (candidate_id) REFERENCES users(id) ON DELETE CASCADE
);


-- ================================================================
-- Achievements
-- ================================================================
CREATE TABLE IF NOT EXISTS candidate_achievements (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT NOT NULL,
  title        VARCHAR(255) NOT NULL,
  organization VARCHAR(255) DEFAULT NULL,
  description  TEXT,
  achieved_on  DATE DEFAULT NULL,
  image_path   VARCHAR(500) DEFAULT NULL,
  position     SMALLINT NOT NULL DEFAULT 1,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_candidate (candidate_id),
  FOREIGN KEY (candidate_id) REFERENCES users(id) ON DELETE CASCADE
);


-- ================================================================
-- Community feed
--
-- author_id points at users.id, so candidates and companies post to
-- the same feed and the author's name comes from whichever profile
-- table matches their role. No second user system.
--
-- kind decides which extra column matters:
--   text   body only
--   image  body + image_path
--   video  body + video_url (YouTube)
--   job    body + job_id, rendered as a job card
-- ================================================================
CREATE TABLE IF NOT EXISTS community_posts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  author_id  INT NOT NULL,
  kind       ENUM('text','image','video','job') NOT NULL DEFAULT 'text',
  body       TEXT,
  image_path VARCHAR(500) DEFAULT NULL,
  video_url  VARCHAR(500) DEFAULT NULL,
  job_id     INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_author (author_id),
  FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (job_id)    REFERENCES jobs(id)  ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS post_comments (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  post_id    INT NOT NULL,
  author_id  INT NOT NULL,
  body       TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_post (post_id),
  KEY idx_author (author_id),
  FOREIGN KEY (post_id)   REFERENCES community_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (author_id) REFERENCES users(id)           ON DELETE CASCADE
);

-- The composite primary key is what makes a second like from the
-- same person impossible, rather than relying on the UI.
CREATE TABLE IF NOT EXISTS post_reactions (
  post_id    INT NOT NULL,
  user_id    INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id),
  KEY idx_user (user_id),
  FOREIGN KEY (post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id)           ON DELETE CASCADE
);