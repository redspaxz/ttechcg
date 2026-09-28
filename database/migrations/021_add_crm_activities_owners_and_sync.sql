-- Contact history: calls, visits, emails, meetings, and notes logged against a customer.
CREATE TABLE IF NOT EXISTS pickup_customer_activities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_key CHAR(64) NOT NULL,
    activity_type VARCHAR(20) NOT NULL,
    occurred_on DATE NOT NULL,
    summary TEXT NOT NULL,
    actor_id CHAR(24) NOT NULL,
    actor_name VARCHAR(160) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX pickup_customer_activities_customer_idx (customer_key, occurred_on),
    CONSTRAINT pickup_customer_activities_customer_fk
        FOREIGN KEY (customer_key) REFERENCES pickup_customers(customer_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The user who owns each customer relationship. The application adds these columns itself when a
-- CRM page loads before this migration runs, so only add them when they are missing.
SET @pickup_customer_owner_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_customers' AND COLUMN_NAME = 'assigned_actor_id') = 0,
    'ALTER TABLE pickup_customers ADD COLUMN assigned_actor_id CHAR(24) NULL AFTER assigned_role, ADD COLUMN assigned_name VARCHAR(160) NULL AFTER assigned_actor_id, ADD INDEX pickup_customers_assigned_actor_idx (assigned_actor_id)',
    'DO 0'
);
PREPARE pickup_customer_owner_statement FROM @pickup_customer_owner_sql;
EXECUTE pickup_customer_owner_statement;
DEALLOCATE PREPARE pickup_customer_owner_statement;

-- Remembers the last shipment row turned into customer profiles, so CRM pages only read new rows.
CREATE TABLE IF NOT EXISTS pickup_crm_sync_state (
    sync_name VARCHAR(40) NOT NULL PRIMARY KEY,
    last_shipment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lets profile totals, shipment history, and renames look up a customer's shipments directly.
ALTER TABLE pickup_shipments
    ADD INDEX pickup_shipments_consignor_idx (consignor);
