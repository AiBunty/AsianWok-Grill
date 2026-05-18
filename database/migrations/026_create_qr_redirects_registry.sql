CREATE TABLE IF NOT EXISTS qr_redirects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    redirect_mode ENUM('preset','manual') NOT NULL DEFAULT 'preset',
    preset_key VARCHAR(190) NULL,
    manual_url VARCHAR(1024) NULL,
    legacy_channel ENUM('customer','admin') NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_qr_redirect_slug (slug),
    UNIQUE KEY uq_qr_redirect_legacy_channel (legacy_channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_redirects' AND COLUMN_NAME = 'name') = 0,
        'ALTER TABLE qr_redirects ADD COLUMN name VARCHAR(190) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_redirects' AND COLUMN_NAME = 'redirect_mode') = 0,
        'ALTER TABLE qr_redirects ADD COLUMN redirect_mode ENUM(''preset'',''manual'') NOT NULL DEFAULT ''preset''',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_redirects' AND COLUMN_NAME = 'preset_key') = 0,
        'ALTER TABLE qr_redirects ADD COLUMN preset_key VARCHAR(190) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_redirects' AND COLUMN_NAME = 'manual_url') = 0,
        'ALTER TABLE qr_redirects ADD COLUMN manual_url VARCHAR(1024) NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_redirects' AND COLUMN_NAME = 'legacy_channel') = 0,
        'ALTER TABLE qr_redirects ADD COLUMN legacy_channel ENUM(''customer'',''admin'') NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_redirects' AND COLUMN_NAME = 'notes') = 0,
        'ALTER TABLE qr_redirects ADD COLUMN notes TEXT NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_redirects' AND COLUMN_NAME = 'is_system') = 0,
        'ALTER TABLE qr_redirects ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_redirects' AND COLUMN_NAME = 'created_by') = 0,
        'ALTER TABLE qr_redirects ADD COLUMN created_by BIGINT UNSIGNED NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
        (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_redirects' AND COLUMN_NAME = 'updated_by') = 0,
        'ALTER TABLE qr_redirects ADD COLUMN updated_by BIGINT UNSIGNED NULL',
        'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE qr_redirects
SET
    name = COALESCE(NULLIF(name, ''), title, slug),
    redirect_mode = CASE WHEN COALESCE(NULLIF(target_url, ''), '') <> '' THEN 'manual' ELSE redirect_mode END,
    manual_url = COALESCE(NULLIF(manual_url, ''), target_url),
    preset_key = CASE WHEN preset_key IS NULL OR preset_key = '' THEN NULL ELSE preset_key END
WHERE 1=1;

INSERT INTO qr_redirects (
    name, title, slug, target_url, redirect_mode, preset_key, manual_url, legacy_channel,
    notes, is_active, is_system, created_at, updated_at
)
VALUES
    ('Guest QR', 'Guest QR', 'guest-menu', '/menu.html', 'preset', 'menu', NULL, 'customer', 'System guest QR redirect', 1, 1, NOW(), NOW()),
    ('Admin QR', 'Admin QR', 'admin-portal', '/admin/', 'preset', 'admin', NULL, 'admin', 'System admin QR redirect', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    title = VALUES(title),
    target_url = VALUES(target_url),
    redirect_mode = VALUES(redirect_mode),
    preset_key = VALUES(preset_key),
    legacy_channel = VALUES(legacy_channel),
    notes = VALUES(notes),
    is_system = VALUES(is_system),
    is_active = VALUES(is_active),
    updated_at = NOW();
