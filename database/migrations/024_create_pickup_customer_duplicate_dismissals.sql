-- Pairs an administrator marked as different customers ("Ignore" in Possible duplicate customers).
-- The two keys are stored sorted, so a pair has one row whichever profile was listed first.
-- Deleting or merging away either profile removes the row.
CREATE TABLE IF NOT EXISTS pickup_customer_duplicate_dismissals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    first_customer_key CHAR(64) NOT NULL,
    second_customer_key CHAR(64) NOT NULL,
    dismissed_by CHAR(24) NOT NULL,
    dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX pickup_customer_duplicate_dismissals_pair_idx (first_customer_key, second_customer_key),
    INDEX pickup_customer_duplicate_dismissals_second_idx (second_customer_key),
    CONSTRAINT pickup_customer_duplicate_dismissals_first_fk
        FOREIGN KEY (first_customer_key) REFERENCES pickup_customers(customer_key) ON DELETE CASCADE,
    CONSTRAINT pickup_customer_duplicate_dismissals_second_fk
        FOREIGN KEY (second_customer_key) REFERENCES pickup_customers(customer_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
