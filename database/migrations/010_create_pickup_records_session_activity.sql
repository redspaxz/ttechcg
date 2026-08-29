CREATE TABLE IF NOT EXISTS pickup_records_session_activity (
    activity_id CHAR(32) PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role VARCHAR(20) NOT NULL,
    identity_provider VARCHAR(32) NOT NULL,
    logged_in_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    logged_out_at DATETIME NULL,
    INDEX pickup_records_session_user_login_idx (username, logged_in_at),
    INDEX pickup_records_session_active_idx (logged_out_at, last_seen_at),
    INDEX pickup_records_session_login_idx (logged_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
