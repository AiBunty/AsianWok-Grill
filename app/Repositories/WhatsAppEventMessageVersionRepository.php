<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class WhatsAppEventMessageVersionRepository
{
    public function create(array $data): int
    {
        $db = Database::connection();

        if (!empty($data['is_current']) && !empty($data['event_key'])) {
            $unsetStmt = $db->prepare('UPDATE whatsapp_event_message_versions SET is_current = 0 WHERE event_key = :event_key');
            $unsetStmt->execute(['event_key' => (string) $data['event_key']]);
        }

        $stmt = $db->prepare(
            'INSERT INTO whatsapp_event_message_versions (
                event_key, source_draft_id, version_label, template_name,
                language_code, category, header_type, header_text,
                body_text, footer_text, buttons_json, sample_variables_json,
                source_template_uid, meta_template_uid, meta_status,
                is_current, created_by, updated_by, created_at, updated_at
            ) VALUES (
                :event_key, :source_draft_id, :version_label, :template_name,
                :language_code, :category, :header_type, :header_text,
                :body_text, :footer_text, :buttons_json, :sample_variables_json,
                :source_template_uid, :meta_template_uid, :meta_status,
                :is_current, :created_by, :updated_by, NOW(), NOW()
            )'
        );
        $stmt->execute([
            'event_key' => (string) ($data['event_key'] ?? ''),
            'source_draft_id' => ($data['source_draft_id'] ?? null) !== null ? (int) $data['source_draft_id'] : null,
            'version_label' => (string) ($data['version_label'] ?? ''),
            'template_name' => (string) ($data['template_name'] ?? ''),
            'language_code' => (string) ($data['language_code'] ?? ''),
            'category' => (string) ($data['category'] ?? ''),
            'header_type' => (string) ($data['header_type'] ?? ''),
            'header_text' => (string) ($data['header_text'] ?? ''),
            'body_text' => (string) ($data['body_text'] ?? ''),
            'footer_text' => (string) ($data['footer_text'] ?? ''),
            'buttons_json' => (string) ($data['buttons_json'] ?? '[]'),
            'sample_variables_json' => (string) ($data['sample_variables_json'] ?? '{}'),
            'source_template_uid' => (string) ($data['source_template_uid'] ?? ''),
            'meta_template_uid' => (string) ($data['meta_template_uid'] ?? ''),
            'meta_status' => (string) ($data['meta_status'] ?? ''),
            'is_current' => !empty($data['is_current']) ? 1 : 0,
            'created_by' => ($data['created_by'] ?? null) !== null ? (int) $data['created_by'] : null,
            'updated_by' => ($data['updated_by'] ?? null) !== null ? (int) $data['updated_by'] : null,
        ]);

        return (int) $db->lastInsertId();
    }

    public function listByEventKey(string $eventKey): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM whatsapp_event_message_versions WHERE event_key = :event_key ORDER BY id DESC');
        $stmt->execute(['event_key' => $eventKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
