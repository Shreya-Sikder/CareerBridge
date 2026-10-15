
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
 
CALL cb_add_column('candidate_profiles', 'resume_name', 'VARCHAR(255) DEFAULT NULL');
 
DROP PROCEDURE IF EXISTS cb_add_column;
 