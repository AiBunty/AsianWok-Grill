<?php
/**
 * Menu Blocker Repository
 * Database layer for spin wheel entries, cooldown tracking, and statistics
 */

namespace AWG\Repositories;

class MenuBlockerRepository
{
    private $db;
    private $table = 'menu_blocker_spins';

    public function __construct($db)
    {
        $this->db = $db;
        $this->ensureTable();
    }

    /**
     * Create the table if it does not yet exist on this server.
     * Runs on every instantiation but is fast (no-op when table exists).
     */
    private function ensureTable(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `menu_blocker_spins` (
                `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `phone` VARCHAR(20) NOT NULL,
                `country_code` VARCHAR(10) NOT NULL,
                `prize_index` INT NOT NULL,
                `prize_label` VARCHAR(50) NOT NULL,
                `coupon_code` VARCHAR(50) NULL,
                `status` ENUM('active','redeemed','expired') DEFAULT 'active',
                `redeemed_at` TIMESTAMP NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_phone_country` (`phone`, `country_code`),
                KEY `idx_created_at` (`created_at`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Create a new spin entry
     */
    public function createSpinEntry(array $data): int
    {
        $sql = "INSERT INTO {$this->table} (phone, country_code, prize_index, prize_label, coupon_code, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['phone'],
            $data['country_code'],
            $data['prize_index'],
            $data['prize_label'],
            $data['coupon_code'],
            $data['status'] ?? 'active',
            $data['created_at'] ?? date('Y-m-d H:i:s'),
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Get the last spin for a phone number (for cooldown check)
     */
    public function getLastSpin(string $phone, string $countryCode): ?array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE phone = ? AND country_code = ?
                ORDER BY created_at DESC
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$phone, $countryCode]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get all spins for a phone number
     */
    public function getPhoneSpins(string $phone): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE phone = ?
                ORDER BY created_at DESC
                LIMIT 50";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$phone]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get statistics for a date range
     */
    public function getSpinStats(string $startDate, string $endDate): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_spins,
                    SUM(CASE WHEN prize_index != 8 THEN 1 ELSE 0 END) as winners,
                    SUM(CASE WHEN prize_index = 8 THEN 1 ELSE 0 END) as try_again,
                    COUNT(DISTINCT phone) as unique_players,
                    prize_label,
                    COUNT(*) as prize_count
                FROM {$this->table}
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY prize_label
                ORDER BY prize_count DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$startDate, $endDate]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get coupon entry by code
     */
    public function getCouponByCode(string $couponCode): ?array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE coupon_code = ? AND status = 'active'";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$couponCode]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Redeem a coupon
     */
    public function redeemCoupon(string $couponCode): bool
    {
        $sql = "UPDATE {$this->table}
                SET status = 'redeemed', redeemed_at = NOW()
                WHERE coupon_code = ? AND status = 'active'";

        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([$couponCode]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get prize distribution pie chart data
     */
    public function getPrizeDistribution(string $startDate = null, string $endDate = null): array
    {
        if (!$startDate) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        $sql = "SELECT prize_label, COUNT(*) as count
                FROM {$this->table}
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY prize_label
                ORDER BY count DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$startDate, $endDate]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get hourly spin trends
     */
    public function getHourlyTrends(string $date = null): array
    {
        if (!$date) {
            $date = date('Y-m-d');
        }

        $sql = "SELECT HOUR(created_at) as hour, COUNT(*) as spins
                FROM {$this->table}
                WHERE DATE(created_at) = ?
                GROUP BY HOUR(created_at)
                ORDER BY hour";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$date]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
