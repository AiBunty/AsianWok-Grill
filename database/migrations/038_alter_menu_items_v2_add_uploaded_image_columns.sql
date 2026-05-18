-- Migration 038: Add database-backed uploaded image support for menu editor.
-- Uploaded WEBP is stored separately from image_url so both sources can coexist.

ALTER TABLE `menu_items_v2`
    ADD COLUMN `uploaded_image_webp` LONGBLOB NULL AFTER `image_url`,
    ADD COLUMN `uploaded_image_mime` VARCHAR(32) NULL AFTER `uploaded_image_webp`,
    ADD COLUMN `uploaded_image_updated_at` DATETIME NULL AFTER `uploaded_image_mime`;
