CREATE TABLE IF NOT EXISTS pickup_security_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(100) NOT NULL,
    outcome VARCHAR(30) NOT NULL,
    actor_id CHAR(24) NULL,
    target_id CHAR(24) NULL,
    resource_id CHAR(24) NULL,
    role VARCHAR(20) NULL,
    identity_provider VARCHAR(32) NULL,
    request_id VARCHAR(100) NOT NULL,
    client_id CHAR(64) NOT NULL,
    request_method VARCHAR(10) NOT NULL,
    request_path VARCHAR(190) NOT NULL,
    context_json LONGTEXT NULL,
    occurred_at DATETIME NOT NULL,
    INDEX pickup_security_events_time_idx (occurred_at),
    INDEX pickup_security_events_actor_time_idx (actor_id, occurred_at),
    INDEX pickup_security_events_event_outcome_idx (event_name, outcome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
