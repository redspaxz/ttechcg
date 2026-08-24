ALTER TABLE pickup_sheets
    ADD COLUMN reference_number VARCHAR(48) NULL AFTER id;

UPDATE pickup_sheets
SET reference_number = CONCAT('PS-', DATE_FORMAT(collection_date, '%Y%m%d'), '-LEGACY-', id)
WHERE reference_number IS NULL;

ALTER TABLE pickup_sheets
    MODIFY reference_number VARCHAR(48) NOT NULL,
    ADD UNIQUE INDEX pickup_sheets_reference_idx (reference_number);
