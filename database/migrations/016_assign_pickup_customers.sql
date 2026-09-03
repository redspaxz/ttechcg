ALTER TABLE pickup_customers
    ADD COLUMN assigned_role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER source;

UPDATE pickup_customers
SET country_code = 'CM', assigned_role = 'admin';

ALTER TABLE pickup_customers
    MODIFY COLUMN country_code CHAR(2) NOT NULL DEFAULT 'CM';
