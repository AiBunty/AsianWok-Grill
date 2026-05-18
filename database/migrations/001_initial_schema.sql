CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(150) NOT NULL,
    role ENUM('admin', 'superadmin') NOT NULL DEFAULT 'admin',
    password_hash VARCHAR(255) NOT NULL,
    password_salt VARCHAR(255) NOT NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    force_password_change TINYINT(1) NOT NULL DEFAULT 0,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    lockout_until DATETIME NULL,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(64) NULL,
    permissions JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    INDEX idx_users_role (role),
    INDEX idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    spin_completed_at DATETIME NULL,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    prize VARCHAR(150) NOT NULL,
    status ENUM('Unredeemed', 'Redeemed') NOT NULL DEFAULT 'Unredeemed',
    date_of_birth DATE NULL,
    date_of_anniversary DATE NULL,
    source VARCHAR(150) NULL,
    visit_count INT UNSIGNED NOT NULL DEFAULT 1,
    coupon_code VARCHAR(64) NULL,
    surprise_reward_label VARCHAR(150) NULL,
    surprise_coupon_code VARCHAR(64) NULL,
    surprise_issued_at DATETIME NULL,
    surprise_issued_by INT UNSIGNED NULL,
    surprise_redeemed_at DATETIME NULL,
    crm_sync_status VARCHAR(32) NULL,
    crm_sync_code VARCHAR(32) NULL,
    crm_sync_message VARCHAR(255) NULL,
    redeemed_at DATETIME NULL,
    INDEX idx_leads_phone (phone),
    INDEX idx_leads_coupon_code (coupon_code),
    INDEX idx_leads_status (status),
    INDEX idx_leads_spin_completed_at (spin_completed_at),
    INDEX idx_leads_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    phone VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    date_of_birth DATE NULL,
    date_of_anniversary DATE NULL,
    first_seen_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    latest_source VARCHAR(150) NULL,
    latest_lead_id BIGINT UNSIGNED NULL,
    latest_lead_created_at DATETIME NULL,
    total_submissions INT UNSIGNED NOT NULL DEFAULT 0,
    latest_crm_sync_status ENUM('Pending','Success','Failed','Skipped') NOT NULL DEFAULT 'Pending',
    latest_crm_sync_code VARCHAR(64) NULL,
    latest_crm_sync_message VARCHAR(255) NULL,
    last_crm_attempted_at DATETIME NULL,
    last_crm_pushed_at DATETIME NULL,
    INDEX idx_crm_contacts_latest_source (latest_source),
    INDEX idx_crm_contacts_latest_crm_sync_status (latest_crm_sync_status),
    INDEX idx_crm_contacts_last_seen_at (last_seen_at),
    INDEX idx_crm_contacts_latest_lead_id (latest_lead_id),
    CONSTRAINT fk_crm_contacts_latest_lead
        FOREIGN KEY (latest_lead_id) REFERENCES leads(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_push_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    contact_id BIGINT UNSIGNED NULL,
    lead_id BIGINT UNSIGNED NULL,
    phone VARCHAR(32) NOT NULL,
    contact_name VARCHAR(150) NULL,
    trigger_source VARCHAR(64) NOT NULL,
    crm_endpoint VARCHAR(255) NULL,
    attempted TINYINT(1) NOT NULL DEFAULT 0,
    success TINYINT(1) NOT NULL DEFAULT 0,
    http_code INT NULL,
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    response_message VARCHAR(255) NULL,
    request_payload_json LONGTEXT NULL,
    attempts_json LONGTEXT NULL,
    INDEX idx_crm_push_logs_contact_id (contact_id),
    INDEX idx_crm_push_logs_lead_id (lead_id),
    INDEX idx_crm_push_logs_phone (phone),
    INDEX idx_crm_push_logs_success (success),
    INDEX idx_crm_push_logs_created_at (created_at),
    CONSTRAINT fk_crm_push_logs_contact
        FOREIGN KEY (contact_id) REFERENCES crm_contacts(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_crm_push_logs_lead
        FOREIGN KEY (lead_id) REFERENCES leads(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    username VARCHAR(100) NULL,
    action VARCHAR(64) NOT NULL,
    ip_address VARCHAR(64) NULL,
    details_json LONGTEXT NULL,
    INDEX idx_auth_audit_logs_created_at (created_at),
    INDEX idx_auth_audit_logs_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_group VARCHAR(100) NOT NULL,
    setting_key VARCHAR(150) NOT NULL,
    setting_value LONGTEXT NULL,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_app_settings_group_key (setting_group, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qr_redirects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(150) NOT NULL UNIQUE,
    title VARCHAR(150) NOT NULL,
    target_url VARCHAR(500) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_qr_redirects_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qr_scans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    qr_redirect_id BIGINT UNSIGNED NOT NULL,
    scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(500) NULL,
    referer_url VARCHAR(500) NULL,
    INDEX idx_qr_scans_redirect_id (qr_redirect_id),
    INDEX idx_qr_scans_scanned_at (scanned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
