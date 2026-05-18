CREATE TABLE IF NOT EXISTS qr_redirect_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('customer','admin') NOT NULL,
    destination_mode ENUM('preset','manual') NOT NULL DEFAULT 'preset',
    destination_key VARCHAR(190) NULL,
    manual_url VARCHAR(1024) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_qr_redirect_settings_channel (channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'scan_number') = 0,
        'ALTER TABLE qr_scans ADD COLUMN scan_number BIGINT UNSIGNED NOT NULL DEFAULT 0',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'channel') = 0,
        'ALTER TABLE qr_scans ADD COLUMN channel ENUM(''customer'',''admin'') NOT NULL DEFAULT ''customer''',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'qr_id') = 0,
        'ALTER TABLE qr_scans ADD COLUMN qr_id BIGINT UNSIGNED NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'qr_slug') = 0,
        'ALTER TABLE qr_scans ADD COLUMN qr_slug VARCHAR(190) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'destination_key') = 0,
        'ALTER TABLE qr_scans ADD COLUMN destination_key VARCHAR(190) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'destination_label') = 0,
        'ALTER TABLE qr_scans ADD COLUMN destination_label VARCHAR(255) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'resolved_url') = 0,
        'ALTER TABLE qr_scans ADD COLUMN resolved_url VARCHAR(1024) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'city') = 0,
        'ALTER TABLE qr_scans ADD COLUMN city VARCHAR(120) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'region') = 0,
        'ALTER TABLE qr_scans ADD COLUMN region VARCHAR(120) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'country') = 0,
        'ALTER TABLE qr_scans ADD COLUMN country VARCHAR(120) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'device') = 0,
        'ALTER TABLE qr_scans ADD COLUMN device VARCHAR(120) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'browser') = 0,
        'ALTER TABLE qr_scans ADD COLUMN browser VARCHAR(120) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'os') = 0,
        'ALTER TABLE qr_scans ADD COLUMN os VARCHAR(120) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'language') = 0,
        'ALTER TABLE qr_scans ADD COLUMN language VARCHAR(40) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'screen') = 0,
        'ALTER TABLE qr_scans ADD COLUMN screen VARCHAR(40) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND COLUMN_NAME = 'referer') = 0,
        'ALTER TABLE qr_scans ADD COLUMN referer VARCHAR(1024) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND INDEX_NAME = 'idx_qr_scans_channel') = 0,
        'CREATE INDEX idx_qr_scans_channel ON qr_scans (channel)',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND INDEX_NAME = 'idx_qr_scans_channel_scanned_at') = 0,
        'CREATE INDEX idx_qr_scans_channel_scanned_at ON qr_scans (channel, scanned_at)',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND INDEX_NAME = 'idx_qr_scans_qr_id') = 0,
        'CREATE INDEX idx_qr_scans_qr_id ON qr_scans (qr_id)',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_scans' AND INDEX_NAME = 'idx_qr_scans_qr_slug') = 0,
        'CREATE INDEX idx_qr_scans_qr_slug ON qr_scans (qr_slug)',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO qr_redirect_settings (channel, destination_mode, destination_key, manual_url, is_active, updated_at)
VALUES
    ('customer', 'preset', 'menu', NULL, 1, NOW()),
    ('admin', 'preset', 'admin', NULL, 1, NOW())
ON DUPLICATE KEY UPDATE
    destination_mode = VALUES(destination_mode),
    destination_key = VALUES(destination_key),
    manual_url = VALUES(manual_url),
    is_active = VALUES(is_active),
    updated_at = NOW();
