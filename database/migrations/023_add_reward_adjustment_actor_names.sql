-- Records who made each reward adjustment by name, so points history shows people rather than
-- account codes. The application adds this column itself when a CRM page loads before this
-- migration runs, so only add it when missing.
SET @pickup_reward_actor_name_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_customer_reward_adjustments' AND COLUMN_NAME = 'actor_name') = 0,
    'ALTER TABLE pickup_customer_reward_adjustments ADD COLUMN actor_name VARCHAR(160) NULL AFTER actor_id',
    'DO 0'
);
PREPARE pickup_reward_actor_name_statement FROM @pickup_reward_actor_name_sql;
EXECUTE pickup_reward_actor_name_statement;
DEALLOCATE PREPARE pickup_reward_actor_name_statement;
