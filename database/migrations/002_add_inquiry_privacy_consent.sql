ALTER TABLE inquiries
    ADD COLUMN privacy_consent_at DATETIME NULL AFTER message,
    ADD COLUMN privacy_notice_version VARCHAR(20) NULL AFTER privacy_consent_at;
