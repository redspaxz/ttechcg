CREATE TABLE IF NOT EXISTS pickup_customer_aliases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_key CHAR(64) NOT NULL,
    alias_name VARCHAR(160) NOT NULL,
    created_by CHAR(24) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX pickup_customer_aliases_name_idx (alias_name),
    INDEX pickup_customer_aliases_customer_idx (customer_key),
    CONSTRAINT pickup_customer_aliases_customer_fk
        FOREIGN KEY (customer_key) REFERENCES pickup_customers(customer_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pickup_customer_merges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    target_customer_key CHAR(64) NOT NULL,
    source_customer_key CHAR(64) NOT NULL,
    target_display_name VARCHAR(160) NOT NULL,
    source_display_name VARCHAR(160) NOT NULL,
    snapshot MEDIUMTEXT NOT NULL,
    merged_by CHAR(24) NOT NULL,
    merged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    undone_by CHAR(24) NULL,
    undone_at DATETIME NULL,
    INDEX pickup_customer_merges_open_idx (undone_at, merged_at),
    INDEX pickup_customer_merges_target_idx (target_customer_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
