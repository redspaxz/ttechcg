CREATE TABLE IF NOT EXISTS pickup_local_mfa (
    subject_id VARCHAR(128) NOT NULL PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    secret_envelope TEXT NOT NULL,
    recovery_code_hashes LONGTEXT NOT NULL,
    last_used_step BIGINT NULL,
    enabled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by CHAR(24) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX pickup_local_mfa_username_idx (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
