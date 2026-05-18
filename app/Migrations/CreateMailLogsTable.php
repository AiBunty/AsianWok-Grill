<?php

declare(strict_types=1);

namespace AWG\Migrations;

use AWG\Support\Database;

final class CreateMailLogsTable
{
    public function up(): bool
    {
        $db = Database::connection();
        $sql = '
            CREATE TABLE IF NOT EXISTS mail_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_id INT DEFAULT NULL,
                booking_id INT DEFAULT NULL,
                recipient_email VARCHAR(255) NOT NULL,
                template VARCHAR(100) NOT NULL,
                status ENUM("pending", "sent", "failed") DEFAULT "pending",
                error_message LONGTEXT DEFAULT NULL,
                sent_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
                FOREIGN KEY (booking_id) REFERENCES event_bookings(id) ON DELETE SET NULL,
                INDEX idx_event_id (event_id),
                INDEX idx_booking_id (booking_id),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ';
        return $db->exec($sql) !== false;
    }

    public function down(): bool
    {
        $db = Database::connection();
        return $db->exec('DROP TABLE IF EXISTS mail_logs') !== false;
    }
}
