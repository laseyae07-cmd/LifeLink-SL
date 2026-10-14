-- Upgrade the original LifeLink SL schema to the aligned final prototype.
-- Back up the database first. Run each section once against the original database.

ALTER TABLE users ADD COLUMN hospital_id BIGINT UNSIGNED NULL AFTER role;
ALTER TABLE users ADD COLUMN mfa_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER phone_verified_at;
ALTER TABLE users ADD COLUMN organ_access_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER mfa_enabled;
CREATE INDEX idx_users_hospital ON users(hospital_id);

ALTER TABLE donor_profiles ADD COLUMN consent_status ENUM('granted','withdrawn') NOT NULL DEFAULT 'granted' AFTER eligibility_verified_at;
ALTER TABLE donor_profiles ADD COLUMN consent_updated_at DATETIME NULL AFTER consent_at;

ALTER TABLE phone_verifications ADD COLUMN otp_hash CHAR(64) NOT NULL AFTER provider_reference;
ALTER TABLE phone_verifications ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_hash;

ALTER TABLE hospitals ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER contact_phone;

ALTER TABLE emergency_requests ADD COLUMN expires_at DATETIME NULL AFTER status;
UPDATE emergency_requests SET expires_at=DATE_ADD(created_at, INTERVAL 12 HOUR) WHERE expires_at IS NULL;
ALTER TABLE emergency_requests MODIFY expires_at DATETIME NOT NULL;
ALTER TABLE emergency_requests MODIFY status ENUM('open','fulfilled','closed','cancelled','expired') NOT NULL DEFAULT 'open';

ALTER TABLE notification_logs MODIFY channel ENUM('email','sms','in_app') NOT NULL;
ALTER TABLE notification_logs MODIFY notification_type VARCHAR(50) NOT NULL;

CREATE TABLE IF NOT EXISTS donor_notification_preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, donor_id BIGINT UNSIGNED NOT NULL UNIQUE,
    emergency_alerts TINYINT(1) NOT NULL DEFAULT 1, same_district_only TINYINT(1) NOT NULL DEFAULT 0,
    sms_alerts TINYINT(1) NOT NULL DEFAULT 1, email_alerts TINYINT(1) NOT NULL DEFAULT 1,
    campaign_alerts TINYINT(1) NOT NULL DEFAULT 1, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pref_donor FOREIGN KEY (donor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT IGNORE INTO donor_notification_preferences(donor_id) SELECT id FROM users WHERE role='donor';

CREATE TABLE IF NOT EXISTS mfa_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, otp_hash CHAR(64) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL, used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mfa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_mfa_expiry (user_id,expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hospital_referrals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, emergency_id BIGINT UNSIGNED NOT NULL,
    sender_hospital_id BIGINT UNSIGNED NOT NULL, target_hospital_id BIGINT UNSIGNED NOT NULL,
    message VARCHAR(500) NULL, status ENUM('pending','accepted','declined','closed') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, responded_at DATETIME NULL,
    CONSTRAINT fk_referral_emergency FOREIGN KEY (emergency_id) REFERENCES emergency_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_referral_sender FOREIGN KEY (sender_hospital_id) REFERENCES hospitals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_referral_target FOREIGN KEY (target_hospital_id) REFERENCES hospitals(id) ON DELETE RESTRICT,
    UNIQUE KEY uniq_referral_target (emergency_id,target_hospital_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS organ_interests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, donor_id BIGINT UNSIGNED NOT NULL, organ_type VARCHAR(50) NOT NULL,
    status ENUM('active','withdrawn') NOT NULL DEFAULT 'active', consent_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_organ_donor FOREIGN KEY (donor_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_donor_organ (donor_id,organ_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS organ_coordination_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, hospital_id BIGINT UNSIGNED NOT NULL, organ_type VARCHAR(50) NOT NULL,
    urgency ENUM('routine','urgent') NOT NULL DEFAULT 'routine', district VARCHAR(50) NOT NULL, coordination_note VARCHAR(500) NULL,
    status ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open', expires_at DATETIME NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME NULL,
    CONSTRAINT fk_organ_request_hospital FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_organ_request_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_organ_request_status (hospital_id,status,expires_at), INDEX idx_organ_request_type (organ_type,district,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blood_inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, hospital_id BIGINT UNSIGNED NOT NULL,
    blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL, units_available INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_hospital FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_hospital_blood (hospital_id,blood_group)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blood_transfer_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, from_hospital_id BIGINT UNSIGNED NOT NULL, to_hospital_id BIGINT UNSIGNED NOT NULL,
    blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL, units_requested INT UNSIGNED NOT NULL,
    urgency ENUM('routine','urgent','critical') NOT NULL DEFAULT 'urgent', notes VARCHAR(500) NULL,
    status ENUM('pending','accepted','declined','completed','cancelled') NOT NULL DEFAULT 'pending', created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, responded_at DATETIME NULL, completed_at DATETIME NULL,
    CONSTRAINT fk_transfer_from FOREIGN KEY (from_hospital_id) REFERENCES hospitals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_transfer_to FOREIGN KEY (to_hospital_id) REFERENCES hospitals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_transfer_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS donation_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, hospital_id BIGINT UNSIGNED NOT NULL, title VARCHAR(160) NOT NULL,
    district VARCHAR(50) NOT NULL, location VARCHAR(255) NOT NULL, event_date DATE NOT NULL, start_time TIME NOT NULL,
    end_time TIME NOT NULL, capacity INT UNSIGNED NOT NULL, notes VARCHAR(500) NULL,
    status ENUM('active','closed','cancelled') NOT NULL DEFAULT 'active', created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_campaign_hospital FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS campaign_registrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, campaign_id BIGINT UNSIGNED NOT NULL, donor_id BIGINT UNSIGNED NOT NULL,
    appointment_time TIME NOT NULL, status ENUM('booked','cancelled') NOT NULL DEFAULT 'booked',
    attendance_status ENUM('pending','attended','no_show') NOT NULL DEFAULT 'pending', registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_registration_campaign FOREIGN KEY (campaign_id) REFERENCES donation_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_registration_donor FOREIGN KEY (donor_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_campaign_donor (campaign_id,donor_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, email_attempt VARCHAR(190) NULL,
    event_type VARCHAR(80) NOT NULL, severity ENUM('info','warning','high') NOT NULL DEFAULT 'info', ip_address VARCHAR(64) NULL,
    description VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_security_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(80) PRIMARY KEY, setting_value VARCHAR(255) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO system_settings(setting_key,setting_value) VALUES
('stage2_minutes','10'),('stage3_minutes','20'),('default_required_donors','2'),('default_request_expiry_hours','12')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

UPDATE users u JOIN hospitals h ON h.user_id=u.id SET u.hospital_id=h.id WHERE u.role='hospital' AND u.hospital_id IS NULL;
