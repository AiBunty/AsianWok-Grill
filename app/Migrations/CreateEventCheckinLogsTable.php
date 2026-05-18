<?php

declare(strict_types=1);

namespace AWG\Migrations;

use AWG\Support\Database;

final class CreateEventCheckinLogsTable
{
    public function up(): bool
    {
        $db = Database::connection();
        $sql = '
            CREATE TABLE IF NOT EXISTS event_checkin_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_id INT NOT NULL,
                booking_id INT NOT NULL,
                guest_count INT DEFAULT 1,
                check_in_method ENUM("qr", "manual", "walk-in") DEFAULT "manual",
                signed_qr LONGTEXT DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
                FOREIGN KEY (booking_id) REFERENCES event_bookings(id) ON DELETE CASCADE,
                INDEX idx_event_id (event_id),
                INDEX idx_booking_id (booking_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ';
        return $db->exec($sql) !== false;
    }

    public function down(): bool
    {
        $db = Database::connection();
        return $db->exec('DROP TABLE IF EXISTS event_checkin_logs') !== false;
    }
}
