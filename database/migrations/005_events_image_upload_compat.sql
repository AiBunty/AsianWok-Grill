-- 005_events_image_upload_compat.sql
-- Purpose: ensure events table has the minimum schema needed for admin event image uploads.
-- Safe to run once through migrate.php.

CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(64) DEFAULT NULL,
    image_url TEXT DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS event_id VARCHAR(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS image_url TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

UPDATE events
SET event_id = CONCAT('evt_', id)
WHERE event_id IS NULL OR event_id = '';
