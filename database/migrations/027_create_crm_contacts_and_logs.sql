CREATE TABLE IF NOT EXISTS crm_contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    phone VARCHAR(32) NOT NULL,
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
    UNIQUE KEY uniq_crm_contacts_phone (phone),
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
