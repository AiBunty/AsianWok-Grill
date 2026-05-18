<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class EventBookingRepository
{
    private const TABLE = 'event_bookings';

    public function create(array $data): ?string
    {
        $db = Database::connection();
        $stmt = $db->prepare('
            INSERT INTO ' . self::TABLE . ' 
            (event_id, phone, email, name, guest_count, status, source, token, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $data['event_id'] ?? '',
            $data['phone'] ?? '',
            $data['email'] ?? '',
            $data['name'] ?? '',
            $data['guest_count'] ?? 1,
            $data['status'] ?? 'registered', // registered, checked-in, walk-in
            $data['source'] ?? 'web', // web, qr, walk-in
            $data['token'] ?? bin2hex(random_bytes(32)),
            $data['notes'] ?? '',
        ]);
        return $db->lastInsertId();
    }

    public function findByToken(string $token): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByEventAndPhone(string $eventId, string $phone): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('
            SELECT * FROM ' . self::TABLE . ' 
            WHERE event_id = ? AND phone = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ');
        $stmt->execute([$eventId, $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByEventAndEmail(string $eventId, string $email): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE event_id = ? AND email = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$eventId, strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getByEvent(string $eventId, ?string $status = null): array
    {
        $db = Database::connection();
        if ($status) {
            $stmt = $db->prepare('
                SELECT * FROM ' . self::TABLE . ' 
                WHERE event_id = ? AND status = ? 
                ORDER BY created_at DESC
            ');
            $stmt->execute([$eventId, $status]);
        } else {
            $stmt = $db->prepare('
                SELECT * FROM ' . self::TABLE . ' 
                WHERE event_id = ? 
                ORDER BY created_at DESC
            ');
            $stmt->execute([$eventId]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function updateStatus(string $bookingId, string $status, ?string $checkedInAt = null): bool
    {
        $db = Database::connection();
        if ($checkedInAt) {
            $stmt = $db->prepare('
                UPDATE ' . self::TABLE . ' 
                SET status = ?, checked_in_at = ? 
                WHERE id = ?
            ');
            return $stmt->execute([$status, $checkedInAt, $bookingId]);
        } else {
            $stmt = $db->prepare('UPDATE ' . self::TABLE . ' SET status = ? WHERE id = ?');
            return $stmt->execute([$status, $bookingId]);
        }
    }

    public function countByEvent(string $eventId): int
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM ' . self::TABLE . ' WHERE event_id = ?');
        $stmt->execute([$eventId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['count'] ?? 0);
    }

    public function countByEventAndStatus(string $eventId, string $status): int
    {
        $db = Database::connection();
        $stmt = $db->prepare('
            SELECT COUNT(*) as count FROM ' . self::TABLE . ' 
            WHERE event_id = ? AND status = ?
        ');
        $stmt->execute([$eventId, $status]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['count'] ?? 0);
    }

    public function getSummary(string $eventId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('
            SELECT 
                COUNT(*) as total_guests,
                SUM(CASE WHEN status = "registered" THEN 1 ELSE 0 END) as registered_guests,
                SUM(CASE WHEN status = "checked-in" THEN 1 ELSE 0 END) as checked_in_guests,
                SUM(CASE WHEN status = "walk-in" THEN 1 ELSE 0 END) as walk_in_guests,
                SUM(CASE WHEN status = "registered" AND checked_in_at IS NULL THEN 1 ELSE 0 END) as pending_guests,
                SUM(guest_count) as total_guest_count
            FROM ' . self::TABLE . ' 
            WHERE event_id = ?
        ');
        $stmt->execute([$eventId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return array_map('intval', $result ?: []);
    }
}
