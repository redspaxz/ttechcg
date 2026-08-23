CREATE TABLE IF NOT EXISTS inquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(160) NOT NULL,
    company VARCHAR(140) NULL,
    service VARCHAR(40) NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX inquiries_email_idx (email),
    INDEX inquiries_service_idx (service),
    INDEX inquiries_created_at_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

