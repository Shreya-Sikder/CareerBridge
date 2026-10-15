-- CareerBridge — part 4.
--
-- Run AFTER database-part3.sql. Safe to re-run.
--
-- Adds the company profile fields. company_profiles held only
-- user_id, company_name and website, which means a candidate
-- deciding whether to apply could see nothing about the employer
-- beyond a name.
--
-- logo_path is the random on-disk name, logo_name the name the
-- company uploaded, for display only. Same split as the candidate CV:
-- the uploaded name is never used to build a path.

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

CALL cb_add_column('company_profiles', 'industry',      'VARCHAR(120) DEFAULT NULL');
CALL cb_add_column('company_profiles', 'company_size',  "ENUM('1-10','11-50','51-200','201-1000','1000+') DEFAULT NULL");
CALL cb_add_column('company_profiles', 'location',      'VARCHAR(255) DEFAULT NULL');
CALL cb_add_column('company_profiles', 'founded_year',  'SMALLINT DEFAULT NULL');
CALL cb_add_column('company_profiles', 'about',         'TEXT');
CALL cb_add_column('company_profiles', 'contact_email', 'VARCHAR(255) DEFAULT NULL');
CALL cb_add_column('company_profiles', 'logo_path',     'VARCHAR(500) DEFAULT NULL');
CALL cb_add_column('company_profiles', 'logo_name',     'VARCHAR(255) DEFAULT NULL');

DROP PROCEDURE IF EXISTS cb_add_column;