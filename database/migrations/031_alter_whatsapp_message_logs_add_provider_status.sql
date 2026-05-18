SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_message_logs' AND COLUMN_NAME = 'provider_message_id') = 0,
        'ALTER TABLE whatsapp_message_logs ADD COLUMN provider_message_id VARCHAR(191) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_message_logs' AND COLUMN_NAME = 'delivery_status') = 0,
        'ALTER TABLE whatsapp_message_logs ADD COLUMN delivery_status VARCHAR(64) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_message_logs' AND COLUMN_NAME = 'status_updated_at') = 0,
        'ALTER TABLE whatsapp_message_logs ADD COLUMN status_updated_at DATETIME NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_message_logs' AND INDEX_NAME = 'idx_wa_logs_provider_message_id') = 0,
        'CREATE INDEX idx_wa_logs_provider_message_id ON whatsapp_message_logs (provider_message_id)',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_message_logs' AND INDEX_NAME = 'idx_wa_logs_delivery_status') = 0,
        'CREATE INDEX idx_wa_logs_delivery_status ON whatsapp_message_logs (delivery_status)',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
