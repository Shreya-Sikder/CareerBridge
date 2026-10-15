-- CareerBridge — part 7: notifications, and file attachments on
-- community posts.
--
-- Run AFTER database-part6.sql. Safe to re-run.

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

-- One post can now carry text, an image, a video and a file at the
-- same time, so the composer no longer asks which kind it is.
-- `kind` stays and is still written, derived from what was attached,
-- so nothing that reads it breaks.
CALL cb_add_column('community_posts', 'file_path', 'VARCHAR(500) DEFAULT NULL');
CALL cb_add_column('community_posts', 'file_name', 'VARCHAR(255) DEFAULT NULL');

DROP PROCEDURE IF EXISTS cb_add_column;


-- ================================================================
-- Notifications
--
-- user_id is the RECIPIENT, and it is the only thing that decides
-- who sees a row. Since candidates and companies are different rows
-- in users, a candidate's notifications can never surface in a
-- company account.
--
-- actor_id is who caused it, nullable because some notifications
-- come from the system rather than a person.
--
-- url is where clicking the notification goes. Storing it here keeps
-- the linking logic at the point the event happened, where the ids
-- are already known.
--
-- read_at NULL means unread. A timestamp rather than a flag so you
-- can tell when it was read.
-- ================================================================
CREATE TABLE IF NOT EXISTS notifications (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  actor_id   INT DEFAULT NULL,
  kind       VARCHAR(40) NOT NULL,
  title      VARCHAR(255) NOT NULL,
  body       VARCHAR(500) DEFAULT NULL,
  url        VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  read_at    DATETIME DEFAULT NULL,
  KEY idx_user_unread (user_id, read_at),
  KEY idx_user_created (user_id, created_at),
  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
);