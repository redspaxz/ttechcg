CREATE TABLE IF NOT EXISTS pickup_sheets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agent_name VARCHAR(100) NOT NULL,
    collection_date DATE NOT NULL,
    shipment_count SMALLINT UNSIGNED NOT NULL,
    total_cash_received_xaf BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'XAF',
    privacy_consent_at DATETIME NOT NULL,
    privacy_notice_version VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX pickup_sheets_collection_date_idx (collection_date),
    INDEX pickup_sheets_agent_name_idx (agent_name),
    INDEX pickup_sheets_created_at_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pickup_shipments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pickup_sheet_id BIGINT UNSIGNED NOT NULL,
    line_number SMALLINT UNSIGNED NOT NULL,
    consignor VARCHAR(160) NOT NULL,
    awb_number VARCHAR(20) NOT NULL,
    destination CHAR(3) NOT NULL,
    amount_xaf BIGINT UNSIGNED NOT NULL,
    pieces SMALLINT UNSIGNED NOT NULL,
    weight_kg DECIMAL(10,3) UNSIGNED NOT NULL,
    collection_time TIME NOT NULL,
    checked_by VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pickup_shipments_sheet_fk FOREIGN KEY (pickup_sheet_id) REFERENCES pickup_sheets (id) ON DELETE CASCADE,
    UNIQUE INDEX pickup_shipments_line_idx (pickup_sheet_id, line_number),
    INDEX pickup_shipments_awb_idx (awb_number),
    INDEX pickup_shipments_destination_idx (destination)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
