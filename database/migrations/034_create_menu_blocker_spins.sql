-- Migration: Create Menu Blocker Spins Table
-- Purpose: Track spin wheel entries, prizes, coupons, and cooldown management

CREATE TABLE IF NOT EXISTS `menu_blocker_spins` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `phone` VARCHAR(20) NOT NULL,
  `country_code` VARCHAR(10) NOT NULL,
  `prize_index` INT NOT NULL,
  `prize_label` VARCHAR(50) NOT NULL,
  `coupon_code` VARCHAR(50) NOT NULL UNIQUE,
  `status` ENUM('active', 'redeemed', 'expired') DEFAULT 'active',
  `redeemed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  KEY `idx_phone_country` (`phone`, `country_code`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_coupon_code` (`coupon_code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes for analytics queries
CREATE INDEX `idx_prize_created` ON `menu_blocker_spins` (`prize_label`, `created_at`);
CREATE INDEX `idx_phone_created` ON `menu_blocker_spins` (`phone`, `created_at`);
