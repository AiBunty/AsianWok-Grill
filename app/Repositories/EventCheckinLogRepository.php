<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class EventCheckinLogRepository
{
    private const TABLE = 'event_checkin_logs';

    public function create(array $data): ?string
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                transaction_id, event_id, admitted_count, guest_names_json,
                verified_by, source, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            (string) ($data['transaction_id'] ?? ''),
            (string) ($data['event_id'] ?? ''),
            max(1, (int) ($data['admitted_count'] ?? $data['guest_count'] ?? 1)),
            (string) ($data['guest_names_json'] ?? '[]'),
            (string) ($data['verified_by'] ?? ''),
            (string) ($data['source'] ?? $data['check_in_method'] ?? 'qr'),
        ]);
        return $db->lastInsertId();
    }

    public function getByTransaction(string $transactionId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE transaction_id = ? ORDER BY id DESC');
        $stmt->execute([$transactionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function getByEvent(string $eventId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE event_id = ? ORDER BY id DESC');
        $stmt->execute([$eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function countByEvent(string $eventId): int
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM ' . self::TABLE . ' WHERE event_id = ?');
        $stmt->execute([$eventId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['count'] ?? 0);
    }

    public function getTotalGuestCountByEvent(string $eventId): int
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT SUM(admitted_count) as total FROM ' . self::TABLE . ' WHERE event_id = ?');
        $stmt->execute([$eventId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['total'] ?? 0);
    }

    public function getSummaryByEvent(string $eventId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT
                COUNT(*) AS total_logs,
                SUM(admitted_count) AS total_admitted,
                MAX(created_at) AS last_checkin_at
             FROM ' . self::TABLE . ' WHERE event_id = ?'
        );
        $stmt->execute([$eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_logs' => (int) ($row['total_logs'] ?? 0),
            'total_admitted' => (int) ($row['total_admitted'] ?? 0),
            'last_checkin_at' => (string) ($row['last_checkin_at'] ?? ''),
        ];
    }
}
