<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class WhatsAppTemplateDraftRepository
{
    public function save(array $data): int
    {
        $db = Database::connection();
        $id = (int) ($data['id'] ?? 0);

        if ($id > 0) {
            $stmt = $db->prepare(
                'UPDATE whatsapp_template_drafts
                 SET draft_name = :draft_name,
                     template_name = :template_name,
                     category = :category,
                     language_code = :language_code,
                     header_type = :header_type,
                     header_text = :header_text,
                     body_text = :body_text,
                     footer_text = :footer_text,
                     buttons_json = :buttons_json,
                     sample_variables_json = :sample_variables_json,
                     example_media_handle = :example_media_handle,
                     status = :status,
                     rejection_reason = :rejection_reason,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'draft_name' => (string) ($data['draft_name'] ?? ''),
                'template_name' => (string) ($data['template_name'] ?? ''),
                'category' => (string) ($data['category'] ?? 'UTILITY'),
                'language_code' => (string) ($data['language_code'] ?? 'en'),
                'header_type' => (string) ($data['header_type'] ?? ''),
                'header_text' => (string) ($data['header_text'] ?? ''),
                'body_text' => (string) ($data['body_text'] ?? ''),
                'footer_text' => (string) ($data['footer_text'] ?? ''),
                'buttons_json' => (string) ($data['buttons_json'] ?? '[]'),
                'sample_variables_json' => (string) ($data['sample_variables_json'] ?? '{}'),
                'example_media_handle' => (string) ($data['example_media_handle'] ?? ''),
                'status' => (string) ($data['status'] ?? 'draft'),
                'rejection_reason' => (string) ($data['rejection_reason'] ?? ''),
                'updated_by' => ($data['updated_by'] ?? null) !== null ? (int) $data['updated_by'] : null,
            ]);
            return $id;
        }

        $stmt = $db->prepare(
            'INSERT INTO whatsapp_template_drafts (
                draft_name, template_name, category, language_code,
                header_type, header_text, body_text, footer_text,
                buttons_json, sample_variables_json, example_media_handle,
                status, created_by, updated_by, created_at, updated_at
            ) VALUES (
                :draft_name, :template_name, :category, :language_code,
                :header_type, :header_text, :body_text, :footer_text,
                :buttons_json, :sample_variables_json, :example_media_handle,
                :status, :created_by, :updated_by, NOW(), NOW()
            )'
        );
        $stmt->execute([
            'draft_name' => (string) ($data['draft_name'] ?? ''),
            'template_name' => (string) ($data['template_name'] ?? ''),
            'category' => (string) ($data['category'] ?? 'UTILITY'),
            'language_code' => (string) ($data['language_code'] ?? 'en'),
            'header_type' => (string) ($data['header_type'] ?? ''),
            'header_text' => (string) ($data['header_text'] ?? ''),
            'body_text' => (string) ($data['body_text'] ?? ''),
            'footer_text' => (string) ($data['footer_text'] ?? ''),
            'buttons_json' => (string) ($data['buttons_json'] ?? '[]'),
            'sample_variables_json' => (string) ($data['sample_variables_json'] ?? '{}'),
            'example_media_handle' => (string) ($data['example_media_handle'] ?? ''),
            'status' => (string) ($data['status'] ?? 'draft'),
            'created_by' => ($data['created_by'] ?? null) !== null ? (int) $data['created_by'] : null,
            'updated_by' => ($data['updated_by'] ?? null) !== null ? (int) $data['updated_by'] : null,
        ]);

        return (int) $db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM whatsapp_template_drafts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function listAll(): array
    {
        $db = Database::connection();
        $stmt = $db->query('SELECT * FROM whatsapp_template_drafts ORDER BY updated_at DESC, id DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markSubmitted(int $id, string $metaTemplateId, string $status, ?string $rejectionReason): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE whatsapp_template_drafts
             SET status = :status,
                 meta_template_id = :meta_template_id,
                 submitted_at = NOW(),
                 last_synced_at = NOW(),
                 rejection_reason = :rejection_reason,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'meta_template_id' => $metaTemplateId,
            'rejection_reason' => $rejectionReason,
        ]);
    }
}
