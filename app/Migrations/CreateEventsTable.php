<?php

declare(strict_types=1);

namespace AWG\Migrations;

use AWG\Support\Database;

final class CreateEventsTable
{
    public function up(): bool
    {
        $db = Database::connection();
        $sql = '
            CREATE TABLE IF NOT EXISTS events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                subtitle VARCHAR(255) DEFAULT NULL,
                description LONGTEXT DEFAULT NULL,
                date DATE NOT NULL,
                time TIME NOT NULL,
                type ENUM("free", "paid") DEFAULT "free",
                ticket_price DECIMAL(10, 2) DEFAULT 0,
                badge_text VARCHAR(100) DEFAULT NULL,
                venue VARCHAR(255) DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_date (date),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ';
        return $db->exec($sql) !== false;
    }

    public function down(): bool
    {
        $db = Database::connection();
        return $db->exec('DROP TABLE IF EXISTS events') !== false;
    }
}
