CREATE TABLE IF NOT EXISTS event_otp_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(64) NOT NULL,
    email VARCHAR(255) NOT NULL,
    customer_name VARCHAR(255) DEFAULT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    attempt_count INT NOT NULL DEFAULT 0,
    otp_requested_at DATETIME NOT NULL,
    resend_allowed_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME DEFAULT NULL,
    verification_token_hash VARCHAR(255) DEFAULT NULL,
    verification_expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event_email (event_id, email),
    INDEX idx_event_otp_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
