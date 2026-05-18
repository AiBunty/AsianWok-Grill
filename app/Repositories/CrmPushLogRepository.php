<?php

declare(strict_types=1);

namespace AWG\Repositories;

use PDO;

final class CrmPushLogRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO crm_push_logs (
                contact_id, lead_id, phone, contact_name, trigger_source, crm_endpoint,
                attempted, success, http_code, retry_count, attempt_count, response_message,
                request_payload_json, debug_payload_json, attempts_json
            ) VALUES (
                :contact_id, :lead_id, :phone, :contact_name, :trigger_source, :crm_endpoint,
                :attempted, :success, :http_code, :retry_count, :attempt_count, :response_message,
                :request_payload_json, :debug_payload_json, :attempts_json
            )'
        );

        $statement->execute([
            'contact_id' => ($data['contact_id'] ?? null) ?: null,
            'lead_id' => ($data['lead_id'] ?? null) ?: null,
            'phone' => (string) ($data['phone'] ?? ''),
            'contact_name' => ($data['contact_name'] ?? null) ?: null,
            'trigger_source' => (string) ($data['trigger_source'] ?? ''),
            'crm_endpoint' => ($data['crm_endpoint'] ?? null) ?: null,
            'attempted' => !empty($data['attempted']) ? 1 : 0,
            'success' => !empty($data['success']) ? 1 : 0,
            'http_code' => ($data['http_code'] ?? null) ?: null,
            'retry_count' => max(0, (int) ($data['retry_count'] ?? 0)),
            'attempt_count' => max(0, (int) ($data['attempt_count'] ?? 0)),
            'response_message' => ($data['response_message'] ?? null) ?: null,
            'request_payload_json' => ($data['request_payload_json'] ?? null) ?: null,
            'debug_payload_json' => ($data['debug_payload_json'] ?? null) ?: null,
            'attempts_json' => ($data['attempts_json'] ?? null) ?: null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function count(array $filters = []): int
    {
        $params = [];
        $where = $this->buildWhere($filters, $params);
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM crm_push_logs' . $where);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    public function list(array $filters = [], int $offset = 0, int $limit = 50): array
    {
        $params = [];
        $where = $this->buildWhere($filters, $params);
        $statement = $this->connection->prepare(
            'SELECT * FROM crm_push_logs' . $where . ' ORDER BY created_at DESC, id DESC LIMIT :offset, :limit'
        );

        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    private function buildWhere(array $filters, array &$params): string
    {
        $clauses = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $params['search'] = '%' . $search . '%';
            $clauses[] = '(phone LIKE :search OR contact_name LIKE :search OR trigger_source LIKE :search OR response_message LIKE :search)';
        }

        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '') {
            $params['source'] = '%' . $source . '%';
            $clauses[] = 'trigger_source LIKE :source';
        }

        $result = strtolower(trim((string) ($filters['result'] ?? '')));
        if ($result === 'success') {
            $clauses[] = 'success = 1';
        } elseif ($result === 'failed') {
            $clauses[] = 'attempted = 1 AND success = 0';
        } elseif ($result === 'skipped') {
            $clauses[] = 'attempted = 0';
        }

        if ($clauses === []) {
            return '';
        }

        return ' WHERE ' . implode(' AND ', $clauses);
    }
}
