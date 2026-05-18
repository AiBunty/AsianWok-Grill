#!/usr/bin/env php
<?php

declare(strict_types=1);

use AWG\Repositories\EventMailLogRepository;
use AWG\Repositories\EventRepository;
use AWG\Repositories\EventTransactionRepository;
use AWG\Services\MailerService;
use AWG\Config\Database;

$rootDir = __DIR__;

require_once $rootDir . '/bootstrap/app.php';
require_once $rootDir . '/app/Config/Database.php';
require_once $rootDir . '/app/Repositories/EventRepository.php';
require_once $rootDir . '/app/Repositories/EventTransactionRepository.php';
require_once $rootDir . '/app/Repositories/EventMailLogRepository.php';
require_once $rootDir . '/app/Services/MailerService.php';

$profile = 'LOCAL';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--profile=')) {
        $profile = strtoupper(trim((string) substr($arg, 10)));
    }
}

$_ENV['NK_ENV_PROFILE'] = $profile;
$_SERVER['NK_ENV_PROFILE'] = $profile;
putenv('NK_ENV_PROFILE=' . $profile);

function hasColumn(PDO $db, string $tableName, string $columnName): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);

    return ((int) ($stmt->fetchColumn() ?: 0)) > 0;
}

function ensureColumn(PDO $db, string $tableName, string $columnName, string $definition): void
{
    if (!hasColumn($db, $tableName, $columnName)) {
        $db->exec('ALTER TABLE ' . $tableName . ' ADD COLUMN ' . $columnName . ' ' . $definition);
    }
}

function ensureSeedSchema(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(64) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            subtitle VARCHAR(255) NULL,
            description LONGTEXT NULL,
            image_url VARCHAR(1000) NULL,
            video_url VARCHAR(1000) NULL,
            show_video TINYINT(1) NOT NULL DEFAULT 0,
            cta_text VARCHAR(255) NULL,
            cta_url VARCHAR(1000) NULL,
            badge_text VARCHAR(255) NULL,
            start_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_date DATE NULL,
            end_time TIME NULL,
            time_display_format VARCHAR(16) NOT NULL DEFAULT "12h",
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 0,
            popup_enabled TINYINT(1) NOT NULL DEFAULT 0,
            show_once_per_session TINYINT(1) NOT NULL DEFAULT 1,
            popup_delay_hours INT NOT NULL DEFAULT 0,
            popup_cooldown_hours INT NOT NULL DEFAULT 24,
            event_type VARCHAR(16) NOT NULL DEFAULT "free",
            ticket_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT "INR",
            max_tickets INT NOT NULL DEFAULT 0,
            payment_enabled TINYINT(1) NOT NULL DEFAULT 0,
            cancellation_policy LONGTEXT NULL,
            refund_policy LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_active_date (is_active, start_date),
            INDEX idx_event_start (start_date, start_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS event_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id VARCHAR(64) NOT NULL UNIQUE,
            event_id VARCHAR(64) NOT NULL,
            event_title VARCHAR(255) NOT NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_email VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(32) NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT "INR",
            gateway VARCHAR(32) NOT NULL DEFAULT "free",
            order_id VARCHAR(100) NULL,
            payment_id VARCHAR(100) NULL,
            status VARCHAR(32) NOT NULL DEFAULT "pending",
            qr_url VARCHAR(1000) NULL,
            qr_payload LONGTEXT NULL,
            email_status VARCHAR(32) NOT NULL DEFAULT "pending",
            email_sent_at DATETIME NULL,
            checkin_status VARCHAR(32) NOT NULL DEFAULT "not_checked_in",
            checked_in_at DATETIME NULL,
            checked_in_count INT NOT NULL DEFAULT 0,
            verified_by VARCHAR(120) NULL,
            attendee_details LONGTEXT NULL,
            guest_passes_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_transactions_event (event_id),
            INDEX idx_event_transactions_email (customer_email),
            INDEX idx_event_transactions_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS mail_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(64) NULL,
            booking_id VARCHAR(64) NULL,
            transaction_id VARCHAR(64) NULL,
            recipient_email VARCHAR(255) NOT NULL,
            template VARCHAR(120) NOT NULL,
            status VARCHAR(32) NOT NULL,
            error_message LONGTEXT NULL,
            payload_json LONGTEXT NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mail_logs_event (event_id),
            INDEX idx_mail_logs_tx (transaction_id),
            INDEX idx_mail_logs_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    ensureColumn($db, 'events', 'event_type', 'VARCHAR(16) NOT NULL DEFAULT "free"');
    ensureColumn($db, 'events', 'ticket_price', 'DECIMAL(10,2) NOT NULL DEFAULT 0');
    ensureColumn($db, 'events', 'payment_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');

    ensureColumn($db, 'event_transactions', 'transaction_id', 'VARCHAR(64) NOT NULL UNIQUE');
    ensureColumn($db, 'event_transactions', 'event_id', 'VARCHAR(64) NOT NULL');
    ensureColumn($db, 'event_transactions', 'event_title', 'VARCHAR(255) NOT NULL DEFAULT ""');
    ensureColumn($db, 'event_transactions', 'customer_name', 'VARCHAR(255) NOT NULL DEFAULT ""');
    ensureColumn($db, 'event_transactions', 'customer_email', 'VARCHAR(255) NOT NULL DEFAULT ""');
    ensureColumn($db, 'event_transactions', 'customer_phone', 'VARCHAR(32) NOT NULL DEFAULT ""');
    ensureColumn($db, 'event_transactions', 'qty', 'INT NOT NULL DEFAULT 1');
    ensureColumn($db, 'event_transactions', 'gateway', 'VARCHAR(32) NOT NULL DEFAULT "free"');
    ensureColumn($db, 'event_transactions', 'order_id', 'VARCHAR(100) NULL');
    ensureColumn($db, 'event_transactions', 'payment_id', 'VARCHAR(100) NULL');
    ensureColumn($db, 'event_transactions', 'qr_url', 'VARCHAR(1000) NULL');
    ensureColumn($db, 'event_transactions', 'qr_payload', 'LONGTEXT NULL');
    ensureColumn($db, 'event_transactions', 'email_status', 'VARCHAR(32) NOT NULL DEFAULT "pending"');
    ensureColumn($db, 'event_transactions', 'email_sent_at', 'DATETIME NULL');
    ensureColumn($db, 'event_transactions', 'checkin_status', 'VARCHAR(32) NOT NULL DEFAULT "not_checked_in"');
    ensureColumn($db, 'event_transactions', 'checked_in_count', 'INT NOT NULL DEFAULT 0');
    ensureColumn($db, 'event_transactions', 'attendee_details', 'LONGTEXT NULL');
    ensureColumn($db, 'event_transactions', 'guest_passes_json', 'LONGTEXT NULL');
}

$email = 'aibuntysystems@gmail.com';

$eventRepo = new EventRepository();
$txRepo = new EventTransactionRepository();
$mailLogRepo = new EventMailLogRepository();
$mailerService = new MailerService($mailLogRepo);

$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));

$eventSeeds = [
    [
        'title' => 'Dummy Test Event Alpha',
        'subtitle' => 'Flow Validation Event',
        'description' => 'Auto-seeded event for registration, QR, and email flow testing.',
        'image_url' => 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=1200&q=80',
        'start_date' => $today->modify('+3 day')->format('Y-m-d'),
        'start_time' => '19:30:00',
        'end_date' => $today->modify('+3 day')->format('Y-m-d'),
        'end_time' => '22:00:00',
        'event_type' => 'free',
        'is_active' => 1,
        'priority' => 90,
        'qty' => 3,
        'name' => 'Test Guest Alpha',
        'phone' => '9000000001',
    ],
    [
        'title' => 'Dummy Test Event Beta',
        'subtitle' => 'Email Link Check-in Demo',
        'description' => 'Auto-seeded event for passcode check-in and guest history validation.',
        'image_url' => 'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?auto=format&fit=crop&w=1200&q=80',
        'start_date' => $today->modify('+5 day')->format('Y-m-d'),
        'start_time' => '20:00:00',
        'end_date' => $today->modify('+5 day')->format('Y-m-d'),
        'end_time' => '23:00:00',
        'event_type' => 'free',
        'is_active' => 1,
        'priority' => 85,
        'qty' => 2,
        'name' => 'Test Guest Beta',
        'phone' => '9000000002',
    ],
];

$results = [];

try {
    $db = Database::connection();
    ensureSeedSchema($db);

    foreach ($eventSeeds as $index => $seed) {
        $eventRowId = $eventRepo->create($seed);
        if ($eventRowId === null) {
            throw new RuntimeException('Failed to create seeded event at index ' . $index);
        }

        $event = $eventRepo->findById((string) $eventRowId);
        if (!$event) {
            throw new RuntimeException('Failed to load newly created event at index ' . $index);
        }

        $eventId = (string) ($event['event_id'] ?? '');
        if ($eventId === '') {
            throw new RuntimeException('Created event missing event_id at index ' . $index);
        }

        $transactionId = 'txn_seed_' . strtolower(bin2hex(random_bytes(6)));
        $qty = (int) $seed['qty'];

        $guestPasses = [];
        for ($i = 1; $i <= $qty; $i++) {
            $guestPasses[] = [
                'guest_id' => $transactionId . '_g' . $i,
                'label' => 'Guest ' . $i,
            ];
        }

        $txRepo->create([
            'transaction_id' => $transactionId,
            'event_id' => $eventId,
            'event_title' => (string) ($seed['title'] ?? ''),
            'customer_name' => (string) ($seed['name'] ?? 'Test Guest'),
            'customer_email' => $email,
            'customer_phone' => (string) ($seed['phone'] ?? ''),
            'qty' => $qty,
            'amount' => 0,
            'currency' => 'INR',
            'gateway' => 'free',
            'order_id' => '',
            'payment_id' => '',
            'status' => 'free_confirmed',
            'email_status' => 'pending',
            'attendee_details' => json_encode([
                [
                    'name' => (string) ($seed['name'] ?? 'Test Guest'),
                    'email' => $email,
                    'phone' => (string) ($seed['phone'] ?? ''),
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'guest_passes_json' => json_encode($guestPasses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $verificationUrl = 'https://asianwokandgrill.in/events/verification.html?transactionId=' . rawurlencode($transactionId);
        $qrUrl = 'https://asianwokandgrill.in/verify-event-qr?payload=' . rawurlencode(base64_encode(json_encode([
            'tx' => $transactionId,
            'eventId' => $eventId,
            'guestId' => 'all',
            'sig' => 'seed',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        $txRepo->updateQr($transactionId, 'seeded-payload-' . $transactionId, $qrUrl);

        $emailOk = $mailerService->sendEventRegistrationConfirmation($email, [
            'event_id' => $eventId,
            'event_title' => (string) ($seed['title'] ?? ''),
            'customer_name' => (string) ($seed['name'] ?? 'Test Guest'),
            'qty' => $qty,
            'transaction_id' => $transactionId,
            'transactionId' => $transactionId,
            'verificationUrl' => $verificationUrl,
            'qr_url' => $qrUrl,
            'qrUrl' => $qrUrl,
        ]);
        $txRepo->updateEmailStatus($transactionId, $emailOk ? 'sent' : 'failed');

        $results[] = [
            'event_row_id' => $eventRowId,
            'event_id' => $eventId,
            'event_title' => $seed['title'],
            'transaction_id' => $transactionId,
            'guest_count' => $qty,
            'email' => $email,
            'email_result' => [
                'ok' => $emailOk,
                'message' => $emailOk ? 'Confirmation email sent.' : 'Confirmation email failed.',
            ],
        ];
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Dummy events, guest entries, and test emails processed.',
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
