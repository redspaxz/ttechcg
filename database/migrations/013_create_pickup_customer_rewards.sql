CREATE TABLE IF NOT EXISTS pickup_customer_reward_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_key CHAR(64) NOT NULL,
    points_delta INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    actor_id CHAR(24) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX pickup_customer_rewards_customer_time_idx (customer_key, created_at),
    INDEX pickup_customer_rewards_actor_time_idx (actor_id, created_at),
    CONSTRAINT pickup_customer_rewards_customer_fk
        FOREIGN KEY (customer_key) REFERENCES pickup_customers(customer_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
