<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class WhatsAppEventMappingRepository
{
    public function upsert(array $data): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO whatsapp_event_mappings (
                event_key, template_name, language_code, mapped_version_id,
                mapped_template_uid, is_enabled, updated_by, updated_at, created_at
            ) VALUES (
                :event_key, :template_name, :language_code, :mapped_version_id,
                :mapped_template_uid, :is_enabled, :updated_by, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                template_name = VALUES(template_name),
                language_code = VALUES(language_code),
                mapped_version_id = VALUES(mapped_version_id),
                mapped_template_uid = VALUES(mapped_template_uid),
                is_enabled = VALUES(is_enabled),
                updated_by = VALUES(updated_by),
                updated_at = NOW()'
        );
        $stmt->execute([
            'event_key' => (string) ($data['event_key'] ?? ''),
            'template_name' => (string) ($data['template_name'] ?? ''),
            'language_code' => (string) ($data['language_code'] ?? ''),
            'mapped_version_id' => ($data['mapped_version_id'] ?? null) !== null ? (int) $data['mapped_version_id'] : null,
            'mapped_template_uid' => (string) ($data['mapped_template_uid'] ?? ''),
            'is_enabled' => !empty($data['is_enabled']) ? 1 : 0,
            'updated_by' => ($data['updated_by'] ?? null) !== null ? (int) $data['updated_by'] : null,
        ]);
    }

    public function listAll(): array
    {
        $db = Database::connection();
        $stmt = $db->query('SELECT * FROM whatsapp_event_mappings ORDER BY event_key ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findByEventKey(string $eventKey): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM whatsapp_event_mappings WHERE event_key = :event_key LIMIT 1');
        $stmt->execute(['event_key' => $eventKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
