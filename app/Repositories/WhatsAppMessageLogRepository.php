<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class WhatsAppMessageLogRepository
{
    public function create(array $data): int
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO whatsapp_message_logs (
                lead_id, event_key, phone, template_name, language_code,
                provider_message_id, delivery_status, status_updated_at,
                attempted, success, http_code, response_message,
                request_payload_json, response_payload_json, created_at
            ) VALUES (
                :lead_id, :event_key, :phone, :template_name, :language_code,
                :provider_message_id, :delivery_status, :status_updated_at,
                :attempted, :success, :http_code, :response_message,
                :request_payload_json, :response_payload_json, NOW()
            )'
        );

        $stmt->execute([
            'lead_id' => ($data['lead_id'] ?? null) !== null ? (int) $data['lead_id'] : null,
            'event_key' => (string) ($data['event_key'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'template_name' => (string) ($data['template_name'] ?? ''),
            'language_code' => (string) ($data['language_code'] ?? ''),
            'provider_message_id' => (string) ($data['provider_message_id'] ?? ''),
            'delivery_status' => (string) ($data['delivery_status'] ?? ''),
            'status_updated_at' => $data['status_updated_at'] ?? null,
            'attempted' => !empty($data['attempted']) ? 1 : 0,
            'success' => !empty($data['success']) ? 1 : 0,
            'http_code' => ($data['http_code'] ?? null) !== null ? (int) $data['http_code'] : null,
            'response_message' => (string) ($data['response_message'] ?? ''),
            'request_payload_json' => (string) ($data['request_payload_json'] ?? '{}'),
            'response_payload_json' => (string) ($data['response_payload_json'] ?? '{}'),
        ]);

        return (int) $db->lastInsertId();
    }

    public function updateDeliveryStatusByProviderMessageId(string $providerMessageId, string $deliveryStatus): int
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE whatsapp_message_logs
             SET delivery_status = :delivery_status,
                 status_updated_at = NOW()
             WHERE provider_message_id = :provider_message_id'
        );
        $stmt->execute([
            'provider_message_id' => $providerMessageId,
            'delivery_status' => $deliveryStatus,
        ]);
        return $stmt->rowCount();
    }

    public function listRecent(int $limit = 100): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM whatsapp_message_logs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
