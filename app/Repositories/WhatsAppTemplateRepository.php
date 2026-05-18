<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class WhatsAppTemplateRepository
{
    public function upsert(array $data): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO whatsapp_message_templates (
                template_uid, template_name, language_code, category,
                status, quality_score, components_json, last_synced_at,
                created_at, updated_at
            ) VALUES (
                :template_uid, :template_name, :language_code, :category,
                :status, :quality_score, :components_json, :last_synced_at,
                NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                template_name = VALUES(template_name),
                language_code = VALUES(language_code),
                category = VALUES(category),
                status = VALUES(status),
                quality_score = VALUES(quality_score),
                components_json = VALUES(components_json),
                last_synced_at = VALUES(last_synced_at),
                updated_at = NOW()'
        );
        $stmt->execute([
            'template_uid' => (string) ($data['template_uid'] ?? ''),
            'template_name' => (string) ($data['template_name'] ?? ''),
            'language_code' => (string) ($data['language_code'] ?? 'en'),
            'category' => (string) ($data['category'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
            'quality_score' => (string) ($data['quality_score'] ?? ''),
            'components_json' => (string) ($data['components_json'] ?? '[]'),
            'last_synced_at' => (string) ($data['last_synced_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    public function listAll(): array
    {
        $db = Database::connection();
        $stmt = $db->query('SELECT * FROM whatsapp_message_templates ORDER BY template_name ASC, language_code ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findByNameLanguage(string $templateName, string $languageCode): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM whatsapp_message_templates WHERE template_name = :template_name AND language_code = :language_code LIMIT 1');
        $stmt->execute([
            'template_name' => $templateName,
            'language_code' => $languageCode,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
