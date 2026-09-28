-- Lets an administrator confirm a merge as final ("Ignore" in Recent merges). The application adds
-- these columns itself when a CRM page loads before this migration runs, so only add them when missing.
SET @pickup_customer_merge_dismissal_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_customer_merges' AND COLUMN_NAME = 'dismissed_at') = 0,
    'ALTER TABLE pickup_customer_merges ADD COLUMN dismissed_by CHAR(24) NULL AFTER undone_at, ADD COLUMN dismissed_at DATETIME NULL AFTER dismissed_by',
    'DO 0'
);
PREPARE pickup_customer_merge_dismissal_statement FROM @pickup_customer_merge_dismissal_sql;
EXECUTE pickup_customer_merge_dismissal_statement;
DEALLOCATE PREPARE pickup_customer_merge_dismissal_statement;
