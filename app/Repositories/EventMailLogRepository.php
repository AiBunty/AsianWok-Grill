<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class EventMailLogRepository
{
    private const TABLE = 'mail_logs';

    public function create(array $data): ?string
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                event_id, booking_id, transaction_id, recipient_email,
                template, status, error_message, payload_json,
                sent_at, created_at, updated_at
            ) VALUES (
                :event_id, :booking_id, :transaction_id, :recipient_email,
                :template, :status, :error_message, :payload_json,
                :sent_at, NOW(), NOW()
            )'
        );

        $stmt->execute([
            'event_id' => ($data['event_id'] ?? null) !== null ? (string) $data['event_id'] : null,
            'booking_id' => ($data['booking_id'] ?? null) !== null ? (string) $data['booking_id'] : null,
            'transaction_id' => (string) ($data['transaction_id'] ?? ''),
            'recipient_email' => strtolower(trim((string) ($data['recipient_email'] ?? ''))),
            'template' => (string) ($data['template'] ?? 'event_notification'),
            'status' => (string) ($data['status'] ?? 'pending'),
            'error_message' => (string) ($data['error_message'] ?? ''),
            'payload_json' => (string) ($data['payload_json'] ?? '{}'),
            'sent_at' => $data['sent_at'] ?? null,
        ]);

        return $db->lastInsertId();
    }

    public function listByEvent(string $eventId, int $limit = 100): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE event_id = ? ORDER BY id DESC LIMIT ' . max(1, (int) $limit));
        $stmt->execute([$eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function summaryByEvent(string $eventId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) AS total_sent,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) AS total_failed
             FROM ' . self::TABLE . ' WHERE event_id = ?'
        );
        $stmt->execute([$eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'total_sent' => (int) ($row['total_sent'] ?? 0),
            'total_failed' => (int) ($row['total_failed'] ?? 0),
        ];
    }
}
