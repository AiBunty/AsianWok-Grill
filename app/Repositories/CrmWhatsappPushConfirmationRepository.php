<?php

declare(strict_types=1);

namespace AWG\Repositories;

use PDO;

final class CrmWhatsappPushConfirmationRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO crm_whatsapp_push_confirmations (
                lead_id, trigger_source, phone, contact_name, crm_endpoint,
                http_code, response_status, response_code, response_message,
                response_json, request_payload_json
            ) VALUES (
                :lead_id, :trigger_source, :phone, :contact_name, :crm_endpoint,
                :http_code, :response_status, :response_code, :response_message,
                :response_json, :request_payload_json
            )'
        );

        $statement->execute([
            'lead_id' => ($data['lead_id'] ?? null) ?: null,
            'trigger_source' => (string) ($data['trigger_source'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'contact_name' => ($data['contact_name'] ?? null) ?: null,
            'crm_endpoint' => ($data['crm_endpoint'] ?? null) ?: null,
            'http_code' => ($data['http_code'] ?? null) ?: null,
            'response_status' => ($data['response_status'] ?? null) ?: null,
            'response_code' => ($data['response_code'] ?? null) ?: null,
            'response_message' => ($data['response_message'] ?? null) ?: null,
            'response_json' => ($data['response_json'] ?? null) ?: null,
            'request_payload_json' => ($data['request_payload_json'] ?? null) ?: null,
        ]);

        return (int) $this->connection->lastInsertId();
    }
}
