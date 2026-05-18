<?php

declare(strict_types=1);

namespace AWG\Migrations;

use AWG\Support\Database;

final class CreateEventBookingsTable
{
    public function up(): bool
    {
        $db = Database::connection();
        $sql = '
            CREATE TABLE IF NOT EXISTS event_bookings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_id INT NOT NULL,
                phone VARCHAR(20) NOT NULL,
                email VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                guest_count INT DEFAULT 1,
                status ENUM("registered", "checked-in", "cancelled", "walk-in") DEFAULT "registered",
                source ENUM("web", "qr", "manual", "walk-in") DEFAULT "web",
                token VARCHAR(255) UNIQUE NOT NULL,
                notes LONGTEXT DEFAULT NULL,
                checked_in_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
                INDEX idx_event_id (event_id),
                INDEX idx_phone (phone),
                INDEX idx_token (token),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ';
        return $db->exec($sql) !== false;
    }

    public function down(): bool
    {
        $db = Database::connection();
        return $db->exec('DROP TABLE IF EXISTS event_bookings') !== false;
    }
}
