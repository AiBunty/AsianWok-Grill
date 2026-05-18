CREATE TABLE IF NOT EXISTS crm_whatsapp_push_confirmations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id BIGINT NULL,
  trigger_source VARCHAR(120) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  contact_name VARCHAR(191) NULL,
  crm_endpoint VARCHAR(512) NULL,
  http_code INT NULL,
  response_status VARCHAR(64) NULL,
  response_code VARCHAR(120) NULL,
  response_message VARCHAR(1000) NULL,
  response_json LONGTEXT NULL,
  request_payload_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cwpc_trigger_created (trigger_source, created_at),
  INDEX idx_cwpc_phone_created (phone, created_at),
  INDEX idx_cwpc_lead_created (lead_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
