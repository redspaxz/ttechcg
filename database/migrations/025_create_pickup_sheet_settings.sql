-- Holds the collection agent name an administrator assigns in the admin panel. Every new pickup
-- sheet is stamped with this name; sheet creators cannot type or change it. One row (settings_id 1).
CREATE TABLE IF NOT EXISTS pickup_sheet_settings (
    settings_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    collection_agent_name VARCHAR(100) NOT NULL DEFAULT '',
    updated_by CHAR(24) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
