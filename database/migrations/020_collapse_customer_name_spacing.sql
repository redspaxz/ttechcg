-- Consignors used to be stored with repeated inner spaces exactly as typed, so "Mboa  Logistics"
-- and "Mboa Logistics" became separate CRM customers and a rename reached only one of them.
-- Collapse the spacing on shipments, fold the customer profiles that become equal, and collapse
-- the remaining profile and alias names.
UPDATE pickup_shipments
SET consignor = TRIM(REGEXP_REPLACE(consignor, '[[:space:]]+', ' '))
WHERE BINARY consignor <> BINARY TRIM(REGEXP_REPLACE(consignor, '[[:space:]]+', ' '));

CREATE TEMPORARY TABLE pickup_customer_spacing_duplicates (
    source_key CHAR(64) NOT NULL PRIMARY KEY,
    target_key CHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pickup_customer_spacing_duplicates (source_key, target_key)
SELECT duplicate_customer.customer_key, retained_customer.customer_key
FROM pickup_customers duplicate_customer
INNER JOIN (
    SELECT TRIM(REGEXP_REPLACE(display_name, '[[:space:]]+', ' ')) AS collapsed_name, MIN(id) AS retained_id
    FROM pickup_customers
    GROUP BY TRIM(REGEXP_REPLACE(display_name, '[[:space:]]+', ' '))
    HAVING COUNT(*) > 1
) duplicate_group ON duplicate_group.collapsed_name = TRIM(REGEXP_REPLACE(duplicate_customer.display_name, '[[:space:]]+', ' '))
INNER JOIN pickup_customers retained_customer ON retained_customer.id = duplicate_group.retained_id
WHERE duplicate_customer.id <> duplicate_group.retained_id;

UPDATE pickup_customers retained_customer
INNER JOIN pickup_customer_spacing_duplicates duplicate_map ON duplicate_map.target_key = retained_customer.customer_key
INNER JOIN pickup_customers duplicate_customer ON duplicate_customer.customer_key = duplicate_map.source_key
SET retained_customer.contact_name = COALESCE(NULLIF(retained_customer.contact_name, ''), duplicate_customer.contact_name),
    retained_customer.email = COALESCE(NULLIF(retained_customer.email, ''), duplicate_customer.email),
    retained_customer.phone = COALESCE(NULLIF(retained_customer.phone, ''), duplicate_customer.phone),
    retained_customer.address = COALESCE(NULLIF(retained_customer.address, ''), duplicate_customer.address),
    retained_customer.city = COALESCE(NULLIF(retained_customer.city, ''), duplicate_customer.city),
    retained_customer.notes = CASE
        WHEN COALESCE(duplicate_customer.notes, '') = '' THEN retained_customer.notes
        WHEN LENGTH(CONCAT_WS('\n\n', NULLIF(retained_customer.notes, ''), CONCAT('Merged from ', duplicate_customer.display_name, ': Notes: ', duplicate_customer.notes))) > 2000 THEN retained_customer.notes
        ELSE CONCAT_WS('\n\n', NULLIF(retained_customer.notes, ''), CONCAT('Merged from ', duplicate_customer.display_name, ': Notes: ', duplicate_customer.notes))
    END;

UPDATE pickup_customer_reward_adjustments reward_adjustment
INNER JOIN pickup_customer_spacing_duplicates duplicate_map ON duplicate_map.source_key = reward_adjustment.customer_key
SET reward_adjustment.customer_key = duplicate_map.target_key;

UPDATE pickup_customer_aliases alias_entry
INNER JOIN pickup_customer_spacing_duplicates duplicate_map ON duplicate_map.source_key = alias_entry.customer_key
SET alias_entry.customer_key = duplicate_map.target_key;

DELETE duplicate_customer
FROM pickup_customers duplicate_customer
INNER JOIN pickup_customer_spacing_duplicates duplicate_map ON duplicate_map.source_key = duplicate_customer.customer_key;

DROP TEMPORARY TABLE pickup_customer_spacing_duplicates;

UPDATE pickup_customers
SET display_name = TRIM(REGEXP_REPLACE(display_name, '[[:space:]]+', ' '))
WHERE BINARY display_name <> BINARY TRIM(REGEXP_REPLACE(display_name, '[[:space:]]+', ' '));

UPDATE IGNORE pickup_customer_aliases
SET alias_name = TRIM(REGEXP_REPLACE(alias_name, '[[:space:]]+', ' '))
WHERE BINARY alias_name <> BINARY TRIM(REGEXP_REPLACE(alias_name, '[[:space:]]+', ' '));
