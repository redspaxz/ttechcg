ALTER TABLE pickup_sheets
    ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'open' AFTER created_at,
    ADD COLUMN paid_at DATETIME NULL AFTER status,
    ADD COLUMN paid_by CHAR(24) NULL AFTER paid_at,
    ADD COLUMN deleted_at DATETIME NULL AFTER paid_by,
    ADD COLUMN deleted_by CHAR(24) NULL AFTER deleted_at,
    ADD INDEX pickup_sheets_status_idx (status, deleted_at),
    ADD INDEX pickup_sheets_deleted_at_idx (deleted_at);

CREATE TABLE IF NOT EXISTS pickup_sheet_lifecycle_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pickup_sheet_id BIGINT UNSIGNED NOT NULL,
    reference_number VARCHAR(48) NOT NULL,
    actor_id CHAR(24) NOT NULL,
    action VARCHAR(20) NOT NULL,
    before_snapshot LONGTEXT NOT NULL,
    after_snapshot LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX pickup_sheet_lifecycle_audit_reference_idx (reference_number, created_at),
    INDEX pickup_sheet_lifecycle_audit_action_idx (action, created_at),
    CONSTRAINT pickup_sheet_lifecycle_audit_sheet_fk
        FOREIGN KEY (pickup_sheet_id) REFERENCES pickup_sheets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
