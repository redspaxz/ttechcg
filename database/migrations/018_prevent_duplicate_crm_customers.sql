ALTER TABLE pickup_customers
    DROP INDEX pickup_customers_name_idx,
    ADD UNIQUE INDEX pickup_customers_name_unique_idx (display_name);
