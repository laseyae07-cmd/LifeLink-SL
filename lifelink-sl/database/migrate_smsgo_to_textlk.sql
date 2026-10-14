-- Run this only if you already imported the original LifeLink SL schema.
-- It adds secure local OTP verification fields needed by the Text.lk integration.
ALTER TABLE phone_verifications
    ADD COLUMN otp_hash CHAR(64) NULL AFTER provider_reference,
    ADD COLUMN attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_hash;
