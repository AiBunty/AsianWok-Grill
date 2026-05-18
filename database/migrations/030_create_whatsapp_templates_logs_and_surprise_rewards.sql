CREATE TABLE IF NOT EXISTS whatsapp_message_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_uid VARCHAR(191) NOT NULL,
    template_name VARCHAR(191) NOT NULL,
    language_code VARCHAR(20) NOT NULL,
    category VARCHAR(64) NULL,
    status VARCHAR(64) NULL,
    quality_score VARCHAR(64) NULL,
    components_json LONGTEXT NULL,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_whatsapp_template_uid (template_uid),
    UNIQUE KEY uq_whatsapp_template_name_lang (template_name, language_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_event_mappings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(191) NOT NULL,
    template_name VARCHAR(191) NULL,
    language_code VARCHAR(20) NULL,
    mapped_version_id BIGINT UNSIGNED NULL,
    mapped_template_uid VARCHAR(191) NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_whatsapp_event_key (event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_message_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id BIGINT NULL,
    event_key VARCHAR(191) NULL,
    phone VARCHAR(40) NULL,
    template_name VARCHAR(191) NULL,
    language_code VARCHAR(20) NULL,
    provider_message_id VARCHAR(191) NULL,
    delivery_status VARCHAR(64) NULL,
    status_updated_at DATETIME NULL,
    attempted TINYINT(1) NOT NULL DEFAULT 0,
    success TINYINT(1) NOT NULL DEFAULT 0,
    http_code INT NULL,
    response_message VARCHAR(255) NULL,
    request_payload_json LONGTEXT NULL,
    response_payload_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wa_logs_event_key (event_key),
    INDEX idx_wa_logs_phone (phone),
    INDEX idx_wa_logs_provider_message_id (provider_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
