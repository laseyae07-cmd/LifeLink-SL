SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role ENUM('admin','hospital','donor') NOT NULL,
    hospital_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(20) NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('pending_verification','pending_password','active','suspended') NOT NULL DEFAULT 'pending_verification',
    email_verified_at DATETIME NULL,
    phone_verified_at DATETIME NULL,
    mfa_enabled TINYINT(1) NOT NULL DEFAULT 1,
    organ_access_enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_status (role, status),
    INDEX idx_users_hospital (hospital_id)
) ENGINE=InnoDB;

CREATE TABLE donor_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    district VARCHAR(50) NOT NULL,
    date_of_birth DATE NOT NULL,
    last_donation_date DATE NULL,
    availability ENUM('available','unavailable') NOT NULL DEFAULT 'available',
    eligibility_status ENUM('pending','eligible','ineligible') NOT NULL DEFAULT 'pending',
    eligibility_verified_at DATETIME NULL,
    consent_status ENUM('granted','withdrawn') NOT NULL DEFAULT 'granted',
    consent_at DATETIME NOT NULL,
    consent_updated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_donor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_donor_matching (blood_group, district, availability, eligibility_status, consent_status)
) ENGINE=InnoDB;

CREATE TABLE donor_notification_preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id BIGINT UNSIGNED NOT NULL UNIQUE,
    emergency_alerts TINYINT(1) NOT NULL DEFAULT 1,
    same_district_only TINYINT(1) NOT NULL DEFAULT 0,
    sms_alerts TINYINT(1) NOT NULL DEFAULT 1,
    email_alerts TINYINT(1) NOT NULL DEFAULT 1,
    campaign_alerts TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pref_donor FOREIGN KEY (donor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE hospitals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    name VARCHAR(180) NOT NULL,
    registration_no VARCHAR(100) NULL,
    district VARCHAR(50) NOT NULL,
    address VARCHAR(255) NOT NULL,
    contact_phone VARCHAR(20) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    approved_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hospital_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_hospital_district (district),
    INDEX idx_hospital_status (status)
) ENGINE=InnoDB;

CREATE TABLE email_verifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_email_verification_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE phone_verifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider_reference VARCHAR(190) NOT NULL,
    otp_hash CHAR(64) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_phone_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_phone_verification_ref (provider_reference),
    INDEX idx_phone_verification_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE mfa_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    otp_hash CHAR(64) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mfa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_mfa_expiry (user_id, expires_at)
) ENGINE=InnoDB;

CREATE TABLE password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_password_reset_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE emergency_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hospital_id BIGINT UNSIGNED NOT NULL,
    blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_needed INT UNSIGNED NOT NULL,
    required_donors INT UNSIGNED NOT NULL,
    urgency ENUM('urgent','critical') NOT NULL,
    district VARCHAR(50) NOT NULL,
    location_details VARCHAR(255) NULL,
    notes TEXT NULL,
    status ENUM('open','fulfilled','closed','cancelled','expired') NOT NULL DEFAULT 'open',
    expires_at DATETIME NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fulfilled_at DATETIME NULL,
    closed_at DATETIME NULL,
    CONSTRAINT fk_emergency_hospital FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_emergency_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_emergency_status_created (status, created_at),
    INDEX idx_emergency_match (blood_group, district, status),
    INDEX idx_emergency_expiry (status, expires_at)
) ENGINE=InnoDB;

CREATE TABLE emergency_invites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    emergency_id BIGINT UNSIGNED NOT NULL,
    donor_id BIGINT UNSIGNED NOT NULL,
    escalation_level TINYINT UNSIGNED NOT NULL,
    response_token_hash CHAR(64) NOT NULL UNIQUE,
    status ENUM('pending','accepted','declined','expired') NOT NULL DEFAULT 'pending',
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    responded_at DATETIME NULL,
    CONSTRAINT fk_invite_emergency FOREIGN KEY (emergency_id) REFERENCES emergency_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_invite_donor FOREIGN KEY (donor_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_emergency_donor (emergency_id, donor_id),
    INDEX idx_invite_status (emergency_id, status),
    INDEX idx_invite_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE emergency_escalations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    emergency_id BIGINT UNSIGNED NOT NULL,
    level TINYINT UNSIGNED NOT NULL,
    candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    triggered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_escalation_emergency FOREIGN KEY (emergency_id) REFERENCES emergency_requests(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_emergency_level (emergency_id, level)
) ENGINE=InnoDB;

CREATE TABLE hospital_referrals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    emergency_id BIGINT UNSIGNED NOT NULL,
    sender_hospital_id BIGINT UNSIGNED NOT NULL,
    target_hospital_id BIGINT UNSIGNED NOT NULL,
    message VARCHAR(500) NULL,
    status ENUM('pending','accepted','declined','closed') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    CONSTRAINT fk_referral_emergency FOREIGN KEY (emergency_id) REFERENCES emergency_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_referral_sender FOREIGN KEY (sender_hospital_id) REFERENCES hospitals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_referral_target FOREIGN KEY (target_hospital_id) REFERENCES hospitals(id) ON DELETE RESTRICT,
    UNIQUE KEY uniq_referral_target (emergency_id, target_hospital_id),
    INDEX idx_referral_target_status (target_hospital_id, status)
) ENGINE=InnoDB;

CREATE TABLE organ_interests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id BIGINT UNSIGNED NOT NULL,
    organ_type VARCHAR(50) NOT NULL,
    status ENUM('active','withdrawn') NOT NULL DEFAULT 'active',
    consent_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_organ_donor FOREIGN KEY (donor_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_donor_organ (donor_id, organ_type),
    INDEX idx_organ_status (organ_type, status)
) ENGINE=InnoDB;

CREATE TABLE organ_coordination_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hospital_id BIGINT UNSIGNED NOT NULL,
    organ_type VARCHAR(50) NOT NULL,
    urgency ENUM('routine','urgent') NOT NULL DEFAULT 'routine',
    district VARCHAR(50) NOT NULL,
    coordination_note VARCHAR(500) NULL,
    status ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
    expires_at DATETIME NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    CONSTRAINT fk_organ_request_hospital FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_organ_request_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_organ_request_status (hospital_id, status, expires_at),
    INDEX idx_organ_request_type (organ_type, district, status)
) ENGINE=InnoDB;

CREATE TABLE blood_inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hospital_id BIGINT UNSIGNED NOT NULL,
    blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_available INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_hospital FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_hospital_blood (hospital_id, blood_group)
) ENGINE=InnoDB;

CREATE TABLE blood_transfer_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_hospital_id BIGINT UNSIGNED NOT NULL,
    to_hospital_id BIGINT UNSIGNED NOT NULL,
    blood_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_requested INT UNSIGNED NOT NULL,
    urgency ENUM('routine','urgent','critical') NOT NULL DEFAULT 'urgent',
    notes VARCHAR(500) NULL,
    status ENUM('pending','accepted','declined','completed','cancelled') NOT NULL DEFAULT 'pending',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    completed_at DATETIME NULL,
    CONSTRAINT fk_transfer_from FOREIGN KEY (from_hospital_id) REFERENCES hospitals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_transfer_to FOREIGN KEY (to_hospital_id) REFERENCES hospitals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_transfer_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_transfer_from_status (from_hospital_id, status),
    INDEX idx_transfer_to_status (to_hospital_id, status)
) ENGINE=InnoDB;

CREATE TABLE donation_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hospital_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    district VARCHAR(50) NOT NULL,
    location VARCHAR(255) NOT NULL,
    event_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    capacity INT UNSIGNED NOT NULL,
    notes VARCHAR(500) NULL,
    status ENUM('active','closed','cancelled') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_campaign_hospital FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_campaign_date_status (event_date, status)
) ENGINE=InnoDB;

CREATE TABLE campaign_registrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    donor_id BIGINT UNSIGNED NOT NULL,
    appointment_time TIME NOT NULL,
    status ENUM('booked','cancelled') NOT NULL DEFAULT 'booked',
    attendance_status ENUM('pending','attended','no_show') NOT NULL DEFAULT 'pending',
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_registration_campaign FOREIGN KEY (campaign_id) REFERENCES donation_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_registration_donor FOREIGN KEY (donor_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_campaign_donor (campaign_id, donor_id),
    INDEX idx_registration_campaign (campaign_id, status)
) ENGINE=InnoDB;

CREATE TABLE notification_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    emergency_invite_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('email','sms','in_app') NOT NULL,
    notification_type VARCHAR(50) NOT NULL,
    provider_message_id VARCHAR(255) NULL,
    delivery_status VARCHAR(50) NOT NULL DEFAULT 'submitted',
    payload_excerpt VARCHAR(255) NULL,
    error_message VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_invite FOREIGN KEY (emergency_invite_id) REFERENCES emergency_invites(id) ON DELETE SET NULL,
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notification_provider (provider_message_id),
    INDEX idx_notification_user (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE qr_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_qr_donor FOREIGN KEY (donor_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_qr_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(64) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempt_window (email, ip_address, created_at)
) ENGINE=InnoDB;

CREATE TABLE security_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    email_attempt VARCHAR(190) NULL,
    event_type VARCHAR(80) NOT NULL,
    severity ENUM('info','warning','high') NOT NULL DEFAULT 'info',
    ip_address VARCHAR(64) NULL,
    description VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_security_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_security_created (created_at),
    INDEX idx_security_severity (severity, created_at)
) ENGINE=InnoDB;

CREATE TABLE system_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB;

INSERT INTO system_settings(setting_key,setting_value) VALUES
('stage2_minutes','10'),
('stage3_minutes','20'),
('default_required_donors','2'),
('default_request_expiry_hours','12')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
