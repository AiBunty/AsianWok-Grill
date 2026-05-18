<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class EventRepository
{
    private const TABLE = 'events';

    public function listAll(bool $includeInactive = true): array
    {
        $db = Database::connection();
        if ($includeInactive) {
            $stmt = $db->query('SELECT * FROM ' . self::TABLE . ' ORDER BY priority DESC, start_date ASC, start_time ASC');
        } else {
            $stmt = $db->query('SELECT * FROM ' . self::TABLE . ' WHERE is_active = 1 ORDER BY priority DESC, start_date ASC, start_time ASC');
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function findByEventId(string $eventId): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE event_id = ? LIMIT 1');
        $stmt->execute([$eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): ?string
    {
        $db = Database::connection();
        $eventId = trim((string) ($data['event_id'] ?? $data['id'] ?? ''));
        if ($eventId === '') {
            $eventId = 'evt_' . bin2hex(random_bytes(6));
        }

        $startDate = (string) ($data['start_date'] ?? $data['date'] ?? date('Y-m-d'));
        $startTime = (string) ($data['start_time'] ?? $data['time'] ?? '19:00:00');
        $endDate = (string) ($data['end_date'] ?? $startDate);
        $endTime = (string) ($data['end_time'] ?? $startTime);
        $eventType = strtolower((string) ($data['event_type'] ?? $data['type'] ?? $data['eventType'] ?? 'free'));
        if ($eventType !== 'paid') {
            $eventType = 'free';
        }

        $stmt = $db->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                event_id, title, subtitle, description,
                venue,
                image_url, video_url, show_video,
                cta_text, cta_url, badge_text,
                start_date, start_time, end_date, end_time, time_display_format,
                is_active, priority,
                popup_enabled, show_once_per_session, popup_delay_hours, popup_cooldown_hours,
                event_type, ticket_price, currency, max_tickets, payment_enabled,
                cancellation_policy, refund_policy,
                created_at, updated_at
            ) VALUES (
                :event_id, :title, :subtitle, :description,
                :venue,
                :image_url, :video_url, :show_video,
                :cta_text, :cta_url, :badge_text,
                :start_date, :start_time, :end_date, :end_time, :time_display_format,
                :is_active, :priority,
                :popup_enabled, :show_once_per_session, :popup_delay_hours, :popup_cooldown_hours,
                :event_type, :ticket_price, :currency, :max_tickets, :payment_enabled,
                :cancellation_policy, :refund_policy,
                NOW(), NOW()
            )'
        );

        $stmt->execute([
            'event_id' => $eventId,
            'title' => (string) ($data['title'] ?? ''),
            'subtitle' => (string) ($data['subtitle'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'venue' => (string) ($data['venue'] ?? ''),
            'image_url' => (string) ($data['image_url'] ?? ''),
            'video_url' => (string) ($data['video_url'] ?? ''),
            'show_video' => !empty($data['show_video']) ? 1 : 0,
            'cta_text' => (string) ($data['cta_text'] ?? 'Register now'),
            'cta_url' => (string) ($data['cta_url'] ?? ''),
            'badge_text' => (string) ($data['badge_text'] ?? $data['badgeText'] ?? ''),
            'start_date' => $startDate,
            'start_time' => $startTime,
            'end_date' => $endDate,
            'end_time' => $endTime,
            'time_display_format' => (string) ($data['time_display_format'] ?? '12h'),
            'is_active' => !array_key_exists('is_active', $data)
                ? (!array_key_exists('isActive', $data) || !empty($data['isActive']) ? 1 : 0)
                : (!empty($data['is_active']) ? 1 : 0),
            'priority' => (int) ($data['priority'] ?? 0),
            'popup_enabled' => !empty($data['popup_enabled']) ? 1 : 0,
            'show_once_per_session' => !array_key_exists('show_once_per_session', $data) ? 1 : (!empty($data['show_once_per_session']) ? 1 : 0),
            'popup_delay_hours' => (int) ($data['popup_delay_hours'] ?? 0),
            'popup_cooldown_hours' => (int) ($data['popup_cooldown_hours'] ?? 24),
            'event_type' => $eventType,
            'ticket_price' => (float) ($data['ticket_price'] ?? $data['ticketPrice'] ?? 0),
            'currency' => strtoupper((string) ($data['currency'] ?? 'INR')),
            'max_tickets' => (int) ($data['max_tickets'] ?? 0),
            'payment_enabled' => !array_key_exists('payment_enabled', $data)
                ? ($eventType === 'paid' ? 1 : 0)
                : (!empty($data['payment_enabled']) ? 1 : 0),
            'cancellation_policy' => (string) ($data['cancellation_policy'] ?? ''),
            'refund_policy' => (string) ($data['refund_policy'] ?? ''),
        ]);

        return $db->lastInsertId();
    }

    public function findById(string $eventId): ?array
    {
        if (ctype_digit($eventId)) {
            $db = Database::connection();
            $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
            $stmt->execute([$eventId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        return $this->findByEventId($eventId);
    }

    public function getAll(): array
    {
        return $this->listAll(true);
    }

    public function all(): array
    {
        return $this->listAll(true);
    }

    public function getActive(): array
    {
        return $this->listAll(false);
    }

    public function update(string $eventId, array $data): bool
    {
        $db = Database::connection();
        $existing = $this->findById($eventId);
        if (!$existing) {
            return false;
        }

        $eventType = strtolower((string) ($data['event_type'] ?? $data['type'] ?? $data['eventType'] ?? ($existing['event_type'] ?? 'free')));
        if ($eventType !== 'paid') {
            $eventType = 'free';
        }

        $stmt = $db->prepare(
            'UPDATE ' . self::TABLE . ' SET
                title = :title,
                subtitle = :subtitle,
                description = :description,
                venue = :venue,
                image_url = :image_url,
                video_url = :video_url,
                show_video = :show_video,
                cta_text = :cta_text,
                cta_url = :cta_url,
                badge_text = :badge_text,
                start_date = :start_date,
                start_time = :start_time,
                end_date = :end_date,
                end_time = :end_time,
                time_display_format = :time_display_format,
                is_active = :is_active,
                priority = :priority,
                popup_enabled = :popup_enabled,
                show_once_per_session = :show_once_per_session,
                popup_delay_hours = :popup_delay_hours,
                popup_cooldown_hours = :popup_cooldown_hours,
                event_type = :event_type,
                ticket_price = :ticket_price,
                currency = :currency,
                max_tickets = :max_tickets,
                payment_enabled = :payment_enabled,
                cancellation_policy = :cancellation_policy,
                refund_policy = :refund_policy,
                updated_at = NOW()
             WHERE id = :id'
        );

        return $stmt->execute([
            'title' => (string) ($data['title'] ?? $existing['title'] ?? ''),
            'subtitle' => (string) ($data['subtitle'] ?? $existing['subtitle'] ?? ''),
            'description' => (string) ($data['description'] ?? $existing['description'] ?? ''),
            'venue' => (string) ($data['venue'] ?? $existing['venue'] ?? ''),
            'image_url' => (string) ($data['image_url'] ?? $existing['image_url'] ?? ''),
            'video_url' => (string) ($data['video_url'] ?? $existing['video_url'] ?? ''),
            'show_video' => array_key_exists('show_video', $data) ? (!empty($data['show_video']) ? 1 : 0) : (int) ($existing['show_video'] ?? 0),
            'cta_text' => (string) ($data['cta_text'] ?? $existing['cta_text'] ?? 'Register now'),
            'cta_url' => (string) ($data['cta_url'] ?? $existing['cta_url'] ?? ''),
            'badge_text' => (string) ($data['badge_text'] ?? $data['badgeText'] ?? $existing['badge_text'] ?? ''),
            'start_date' => (string) ($data['start_date'] ?? $data['date'] ?? $existing['start_date'] ?? date('Y-m-d')),
            'start_time' => (string) ($data['start_time'] ?? $data['time'] ?? $existing['start_time'] ?? '19:00:00'),
            'end_date' => (string) ($data['end_date'] ?? $existing['end_date'] ?? $existing['start_date'] ?? date('Y-m-d')),
            'end_time' => (string) ($data['end_time'] ?? $existing['end_time'] ?? $existing['start_time'] ?? '19:00:00'),
            'time_display_format' => (string) ($data['time_display_format'] ?? $existing['time_display_format'] ?? '12h'),
            'is_active' => array_key_exists('is_active', $data)
                ? (!empty($data['is_active']) ? 1 : 0)
                : (array_key_exists('isActive', $data) ? (!empty($data['isActive']) ? 1 : 0) : (int) ($existing['is_active'] ?? 1)),
            'priority' => (int) ($data['priority'] ?? $existing['priority'] ?? 0),
            'popup_enabled' => array_key_exists('popup_enabled', $data) ? (!empty($data['popup_enabled']) ? 1 : 0) : (int) ($existing['popup_enabled'] ?? 0),
            'show_once_per_session' => array_key_exists('show_once_per_session', $data) ? (!empty($data['show_once_per_session']) ? 1 : 0) : (int) ($existing['show_once_per_session'] ?? 1),
            'popup_delay_hours' => (int) ($data['popup_delay_hours'] ?? $existing['popup_delay_hours'] ?? 0),
            'popup_cooldown_hours' => (int) ($data['popup_cooldown_hours'] ?? $existing['popup_cooldown_hours'] ?? 24),
            'event_type' => $eventType,
            'ticket_price' => (float) ($data['ticket_price'] ?? $data['ticketPrice'] ?? $existing['ticket_price'] ?? 0),
            'currency' => strtoupper((string) ($data['currency'] ?? $existing['currency'] ?? 'INR')),
            'max_tickets' => (int) ($data['max_tickets'] ?? $existing['max_tickets'] ?? 0),
            'payment_enabled' => array_key_exists('payment_enabled', $data)
                ? (!empty($data['payment_enabled']) ? 1 : 0)
                : ($eventType === 'paid' ? 1 : (int) ($existing['payment_enabled'] ?? 0)),
            'cancellation_policy' => (string) ($data['cancellation_policy'] ?? $existing['cancellation_policy'] ?? ''),
            'refund_policy' => (string) ($data['refund_policy'] ?? $existing['refund_policy'] ?? ''),
            'id' => (int) $existing['id'],
        ]);
    }

    public function updateImageUrl(string $eventId, string $imageUrl): bool
    {
        $db = Database::connection();

        if (ctype_digit($eventId)) {
            $stmt = $db->prepare('UPDATE ' . self::TABLE . ' SET image_url = :image_url WHERE id = :id');
            $ok = $stmt->execute([
                'image_url' => $imageUrl,
                'id' => (int) $eventId,
            ]);

            if (!$ok) {
                return false;
            }

            if ($stmt->rowCount() > 0) {
                return true;
            }

            $existsStmt = $db->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
            $existsStmt->execute(['id' => (int) $eventId]);
            if ($existsStmt->fetchColumn() !== false) {
                return true;
            }
        }

        try {
            $stmt = $db->prepare('UPDATE ' . self::TABLE . ' SET image_url = :image_url WHERE event_id = :event_id');
            $ok = $stmt->execute([
                'image_url' => $imageUrl,
                'event_id' => $eventId,
            ]);

            if (!$ok) {
                return false;
            }

            if ($stmt->rowCount() > 0) {
                return true;
            }

            $existsStmt = $db->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE event_id = :event_id LIMIT 1');
            $existsStmt->execute(['event_id' => $eventId]);
            return $existsStmt->fetchColumn() !== false;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public function delete(string $eventId): bool
    {
        $db = Database::connection();
        $existing = $this->findById($eventId);
        if (!$existing) {
            return false;
        }

        $stmt = $db->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        return $stmt->execute([(int) $existing['id']]);
    }

    public function toggleActive(string $eventId, bool $isActive): bool
    {
        $db = Database::connection();
        $existing = $this->findById($eventId);
        if (!$existing) {
            return false;
        }

        $stmt = $db->prepare('UPDATE ' . self::TABLE . ' SET is_active = ?, updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$isActive ? 1 : 0, (int) $existing['id']]);
    }
}
