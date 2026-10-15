-- CareerBridge — part 5: role-based grooming.
--
-- Run AFTER database-part4.sql. Safe to re-run.
--
-- The grooming URLs live in grooming_roles, one row per role. They
-- are NOT written into any PHP file, so changing a playlist later is
-- an UPDATE, not a code edit.
--
-- Matching a job title to a role is data too, in
-- grooming_role_aliases. Adding "Backend Platform Engineer" later is
-- one INSERT, not another branch in an if/else chain.
--
-- The skill-based grooming_resources and grooming_progress tables
-- from part 2 are left alone. They are no longer what gates a second
-- attempt, but nothing is dropped, so no data is lost.

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

-- suggested = resolve from the role mapping at display time, so a
--             later playlist change reaches every job at once
-- custom    = use grooming_url, stored against this job only
-- none      = show nothing
CALL cb_add_column('jobs', 'grooming_type', "ENUM('suggested','custom','none') NOT NULL DEFAULT 'suggested'");
CALL cb_add_column('jobs', 'grooming_url',  'VARCHAR(500) DEFAULT NULL');

DROP PROCEDURE IF EXISTS cb_add_column;


-- ================================================================
-- The roles and their default content
-- ================================================================
CREATE TABLE IF NOT EXISTS grooming_roles (
  role_key  VARCHAR(40) PRIMARY KEY,
  label     VARCHAR(120) NOT NULL,
  title     VARCHAR(255) NOT NULL,
  url       VARCHAR(500) NOT NULL
);

-- ON DUPLICATE KEY UPDATE so re-running refreshes a changed URL
-- instead of failing.
INSERT INTO grooming_roles (role_key, label, title, url) VALUES
  ('backend',  'Backend Engineer',  'Backend Engineering Preparation',
   'https://youtube.com/playlist?list=PLWKjhJtqVAbn21gs5UnLhCQ82f923WCgM'),

  ('frontend', 'Frontend Engineer', 'Frontend Engineering Preparation',
   'https://youtube.com/playlist?list=PLWKjhJtqVAblKO_wHAvlpZwAYvYPbHKOp'),

  ('devops',   'DevOps Engineer',   'DevOps Engineering Preparation',
   'https://youtube.com/playlist?list=PLWKjhJtqVAbkzvvpY12KkfiIGso9A_Ixs'),

  ('mlops',    'MLOps Engineer',    'MLOps Engineering Preparation',
   'https://youtube.com/playlist?list=PLkDaE6sCZn6GMoA0wbpJLi3t34Gd8l0aK'),

  ('ai',       'AI Engineer',       'AI Engineering Preparation',
   'https://youtu.be/68FcZUpgC7w'),

  ('fullstack','Full Stack Development', 'Full Stack Development Preparation',
   'https://youtu.be/LzMnsfqjzkA'),

  ('software', 'Software Engineering',   'Software Engineering Preparation',
   'https://youtube.com/playlist?list=PLmXKhU9FNesTrw7n8ouPsSLEcduRlENHr'),

  ('security', 'Cybersecurity',          'Cybersecurity Preparation',
   'https://youtube.com/playlist?list=PL5OhSdfH4uDuCcQ-NZ4lYa3S07FQTFtrY')
ON DUPLICATE KEY UPDATE
  label = VALUES(label), title = VALUES(title), url = VALUES(url);


-- ================================================================
-- Title matching
--
-- alias is already normalised: lower case, no punctuation, single
-- spaces. PHP normalises the job title the same way, then looks for
-- the first alias contained in it.
--
-- priority decides which wins when a title contains two aliases.
-- "Backend Software Engineer" contains both "backend" and "software
-- engineer"; the specialisation must win, so specialisations are
-- priority 10 and the generic software role is 1. Within one
-- priority, the longer alias wins.
-- ================================================================
CREATE TABLE IF NOT EXISTS grooming_role_aliases (
  alias    VARCHAR(120) PRIMARY KEY,
  role_key VARCHAR(40) NOT NULL,
  priority TINYINT NOT NULL DEFAULT 10,
  FOREIGN KEY (role_key) REFERENCES grooming_roles(role_key) ON DELETE CASCADE
);

INSERT INTO grooming_role_aliases (alias, role_key, priority) VALUES
  -- Backend
  ('backend engineer', 'backend', 10),
  ('backend developer', 'backend', 10),
  ('backend development engineer', 'backend', 10),
  ('backend software engineer', 'backend', 10),
  ('back end engineer', 'backend', 10),
  ('back end developer', 'backend', 10),
  ('server side developer', 'backend', 10),
  ('api developer', 'backend', 10),
  ('backend', 'backend', 9),

  -- Frontend
  ('frontend engineer', 'frontend', 10),
  ('frontend developer', 'frontend', 10),
  ('frontend development engineer', 'frontend', 10),
  ('frontend software engineer', 'frontend', 10),
  ('front end engineer', 'frontend', 10),
  ('front end developer', 'frontend', 10),
  ('ui developer', 'frontend', 10),
  ('web developer', 'frontend', 9),
  ('frontend', 'frontend', 9),

  -- Full stack. Higher than backend and frontend so that
  -- "Full Stack Backend Engineer" lands on full stack.
  ('full stack engineer', 'fullstack', 12),
  ('full stack developer', 'fullstack', 12),
  ('full stack development', 'fullstack', 12),
  ('full stack software engineer', 'fullstack', 12),
  ('fullstack engineer', 'fullstack', 12),
  ('fullstack developer', 'fullstack', 12),
  ('full stack', 'fullstack', 11),
  ('fullstack', 'fullstack', 11),

  -- DevOps
  ('devops engineer', 'devops', 10),
  ('devops developer', 'devops', 10),
  ('dev ops engineer', 'devops', 10),
  ('site reliability engineer', 'devops', 10),
  ('platform engineer', 'devops', 10),
  ('infrastructure engineer', 'devops', 10),
  ('cloud engineer', 'devops', 10),
  ('devops', 'devops', 9),

  -- MLOps. Above AI so "MLOps AI Engineer" lands on MLOps.
  ('mlops engineer', 'mlops', 12),
  ('ml ops engineer', 'mlops', 12),
  ('machine learning operations engineer', 'mlops', 12),
  ('machine learning infrastructure engineer', 'mlops', 12),
  ('mlops', 'mlops', 11),

  -- AI and ML
  ('ai engineer', 'ai', 10),
  ('artificial intelligence engineer', 'ai', 10),
  ('machine learning engineer', 'ai', 10),
  ('ml engineer', 'ai', 10),
  ('deep learning engineer', 'ai', 10),
  ('data scientist', 'ai', 9),
  ('ai developer', 'ai', 10),

  -- Cybersecurity
  ('cybersecurity engineer', 'security', 10),
  ('cybersecurity analyst', 'security', 10),
  ('cyber security engineer', 'security', 10),
  ('cyber security analyst', 'security', 10),
  ('security engineer', 'security', 10),
  ('security analyst', 'security', 10),
  ('information security', 'security', 10),
  ('penetration tester', 'security', 10),
  ('cybersecurity', 'security', 9),
  ('cyber security', 'security', 9),

  -- Generic software, lowest so a specialisation always wins
  ('software engineer', 'software', 1),
  ('software developer', 'software', 1),
  ('software engineering', 'software', 1),
  ('software development engineer', 'software', 1),
  ('programmer', 'software', 1)
ON DUPLICATE KEY UPDATE
  role_key = VALUES(role_key), priority = VALUES(priority);


-- Sanity check. Should return zero rows: every alias points at a
-- role that exists.
SELECT a.alias, a.role_key AS alias_with_no_role
FROM grooming_role_aliases a
LEFT JOIN grooming_roles r ON r.role_key = a.role_key
WHERE r.role_key IS NULL;