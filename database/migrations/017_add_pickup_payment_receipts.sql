ALTER TABLE pickup_sheets
    ADD COLUMN payment_receipt_number VARCHAR(64) NULL AFTER paid_by;
