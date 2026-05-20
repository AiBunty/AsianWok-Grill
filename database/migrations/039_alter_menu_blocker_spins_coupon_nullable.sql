-- Migration: Make menu_blocker_spins.coupon_code nullable
-- Purpose: Try Again prizes (prize_index=8) should not require a coupon code.
--          The NOT NULL constraint was blocking ~17% of spins (Try Again weight is 2/12).
--          MySQL UNIQUE indexes allow multiple NULL values, so existing uniqueness is preserved.

ALTER TABLE `menu_blocker_spins`
  MODIFY COLUMN `coupon_code` VARCHAR(50) NULL;
