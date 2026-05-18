<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class QrRedirectSettingsRepository
{
    public function getByChannel(string $channel): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM qr_redirect_settings WHERE channel = :channel LIMIT 1');
        $stmt->execute(['channel' => $channel]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function upsert(array $data): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO qr_redirect_settings (
                channel, destination_mode, destination_key, manual_url, is_active, updated_at, updated_by
             ) VALUES (
                :channel, :destination_mode, :destination_key, :manual_url, :is_active, NOW(), :updated_by
             )
             ON DUPLICATE KEY UPDATE
                destination_mode = VALUES(destination_mode),
                destination_key = VALUES(destination_key),
                manual_url = VALUES(manual_url),
                is_active = VALUES(is_active),
                updated_by = VALUES(updated_by),
                updated_at = NOW()'
        );

        $stmt->execute([
            'channel' => (string) ($data['channel'] ?? 'customer'),
            'destination_mode' => (string) ($data['destination_mode'] ?? 'preset'),
            'destination_key' => (string) ($data['destination_key'] ?? ''),
            'manual_url' => (string) ($data['manual_url'] ?? ''),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_by' => ($data['updated_by'] ?? null) !== null ? (int) $data['updated_by'] : null,
        ]);
    }

    public function listAll(): array
    {
        $db = Database::connection();
        $stmt = $db->query('SELECT * FROM qr_redirect_settings ORDER BY channel ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
