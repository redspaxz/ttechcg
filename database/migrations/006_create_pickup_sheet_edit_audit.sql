CREATE TABLE IF NOT EXISTS pickup_sheet_edit_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pickup_sheet_id BIGINT UNSIGNED NOT NULL,
    reference_number VARCHAR(48) NOT NULL,
    actor_id CHAR(24) NOT NULL,
    before_snapshot LONGTEXT NOT NULL,
    after_snapshot LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX pickup_sheet_edit_audit_reference_idx (reference_number, created_at),
    CONSTRAINT pickup_sheet_edit_audit_sheet_fk
        FOREIGN KEY (pickup_sheet_id) REFERENCES pickup_sheets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
