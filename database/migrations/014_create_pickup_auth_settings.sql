CREATE TABLE IF NOT EXISTS pickup_auth_settings (
    settings_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    local_login_enabled TINYINT(1) NOT NULL DEFAULT 1,
    jumpcloud_login_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_by CHAR(24) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO pickup_auth_settings
    (settings_id, local_login_enabled, jumpcloud_login_enabled, updated_by)
VALUES
    (1, 1, 1, NULL);
