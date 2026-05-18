<?php

declare(strict_types=1);

namespace AWG\Migrations;

use AWG\Support\Database;

final class CreateEventTransactionsTable
{
    public function up(): bool
    {
        $db = Database::connection();
        $sql = '
            CREATE TABLE IF NOT EXISTS event_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_id INT NOT NULL,
                booking_id INT NOT NULL,
                amount DECIMAL(10, 2) NOT NULL,
                currency VARCHAR(3) DEFAULT "INR",
                status ENUM("pending", "completed", "failed", "refunded") DEFAULT "pending",
                payment_method VARCHAR(50) DEFAULT "razorpay",
                razorpay_order_id VARCHAR(255) DEFAULT NULL,
                razorpay_payment_id VARCHAR(255) DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
                FOREIGN KEY (booking_id) REFERENCES event_bookings(id) ON DELETE CASCADE,
                INDEX idx_event_id (event_id),
                INDEX idx_booking_id (booking_id),
                INDEX idx_status (status),
                INDEX idx_razorpay_order_id (razorpay_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ';
        return $db->exec($sql) !== false;
    }

    public function down(): bool
    {
        $db = Database::connection();
        return $db->exec('DROP TABLE IF EXISTS event_transactions') !== false;
    }
}
