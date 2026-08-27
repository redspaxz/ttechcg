ALTER TABLE pickup_records_users
    ADD COLUMN first_name VARCHAR(49) NULL AFTER username,
    ADD COLUMN last_name VARCHAR(49) NULL AFTER first_name;

UPDATE pickup_records_users
SET first_name = LEFT(username, 49), last_name = 'User'
WHERE first_name IS NULL OR first_name = '' OR last_name IS NULL OR last_name = '';

ALTER TABLE pickup_records_users
    MODIFY COLUMN first_name VARCHAR(49) NOT NULL,
    MODIFY COLUMN last_name VARCHAR(49) NOT NULL;
