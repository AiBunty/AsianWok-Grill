<?php

declare(strict_types=1);

namespace AWG\Repositories;

use PDO;

final class ContactRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function upsert(array $data): int
    {
        $phone = (string) ($data['phone'] ?? '');
        $leadCreatedAt = $data['latest_lead_created_at'] ?? $data['last_seen_at'] ?? date('Y-m-d H:i:s');
        $firstSeenAt = $data['first_seen_at'] ?? $leadCreatedAt;
        $lastSeenAt = $data['last_seen_at'] ?? $leadCreatedAt;
        $totalSubmissions = max(1, (int) ($data['total_submissions'] ?? 1));

        $statement = $this->connection->prepare(
            'INSERT INTO crm_contacts (
                phone, name, date_of_birth, date_of_anniversary,
                first_seen_at, last_seen_at, latest_source, latest_lead_id,
                latest_lead_created_at, total_submissions, latest_crm_sync_status,
                latest_crm_sync_code, latest_crm_sync_message, last_crm_attempted_at,
                last_crm_pushed_at
            ) VALUES (
                :phone, :name, :date_of_birth, :date_of_anniversary,
                :first_seen_at, :last_seen_at, :latest_source, :latest_lead_id,
                :latest_lead_created_at, :total_submissions, :latest_crm_sync_status,
                :latest_crm_sync_code, :latest_crm_sync_message, :last_crm_attempted_at,
                :last_crm_pushed_at
            )
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                date_of_birth = VALUES(date_of_birth),
                date_of_anniversary = VALUES(date_of_anniversary),
                first_seen_at = LEAST(COALESCE(first_seen_at, VALUES(first_seen_at)), VALUES(first_seen_at)),
                last_seen_at = GREATEST(COALESCE(last_seen_at, VALUES(last_seen_at)), VALUES(last_seen_at)),
                latest_source = VALUES(latest_source),
                latest_lead_id = VALUES(latest_lead_id),
                latest_lead_created_at = VALUES(latest_lead_created_at),
                total_submissions = VALUES(total_submissions),
                latest_crm_sync_status = VALUES(latest_crm_sync_status),
                latest_crm_sync_code = VALUES(latest_crm_sync_code),
                latest_crm_sync_message = VALUES(latest_crm_sync_message),
                last_crm_attempted_at = VALUES(last_crm_attempted_at),
                last_crm_pushed_at = VALUES(last_crm_pushed_at)'
        );

        $statement->execute([
            'phone' => $phone,
            'name' => (string) ($data['name'] ?? ''),
            'date_of_birth' => ($data['date_of_birth'] ?? null) ?: null,
            'date_of_anniversary' => ($data['date_of_anniversary'] ?? null) ?: null,
            'first_seen_at' => $firstSeenAt,
            'last_seen_at' => $lastSeenAt,
            'latest_source' => ($data['latest_source'] ?? null) ?: null,
            'latest_lead_id' => ($data['latest_lead_id'] ?? null) ?: null,
            'latest_lead_created_at' => $leadCreatedAt,
            'total_submissions' => $totalSubmissions,
            'latest_crm_sync_status' => $this->normalizeSyncStatus(($data['latest_crm_sync_status'] ?? null) ?: 'Pending'),
            'latest_crm_sync_code' => ($data['latest_crm_sync_code'] ?? null) ?: null,
            'latest_crm_sync_message' => ($data['latest_crm_sync_message'] ?? null) ?: null,
            'last_crm_attempted_at' => ($data['last_crm_attempted_at'] ?? null) ?: null,
            'last_crm_pushed_at' => ($data['last_crm_pushed_at'] ?? null) ?: null,
        ]);

        return (int) ($this->findByPhone($phone)['id'] ?? 0);
    }

    public function findByPhone(string $phone): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM crm_contacts WHERE phone = :phone LIMIT 1');
        $statement->execute(['phone' => $phone]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function deleteByPhone(string $phone): void
    {
        $statement = $this->connection->prepare('DELETE FROM crm_contacts WHERE phone = :phone');
        $statement->execute(['phone' => $phone]);
    }

    public function count(array $filters = []): int
    {
        $params = [];
        $where = $this->buildWhere($filters, $params);
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM crm_contacts' . $where);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    public function list(array $filters = [], int $offset = 0, int $limit = 50): array
    {
        $params = [];
        $where = $this->buildWhere($filters, $params);
        $statement = $this->connection->prepare(
            'SELECT * FROM crm_contacts' . $where . ' ORDER BY last_seen_at DESC, id DESC LIMIT :offset, :limit'
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
            $clauses[] = '(phone LIKE :search OR name LIKE :search OR latest_source LIKE :search OR latest_crm_sync_code LIKE :search)';
        }

        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '') {
            $params['source'] = '%' . $source . '%';
            $clauses[] = 'latest_source LIKE :source';
        }

        $syncStatus = trim((string) ($filters['syncStatus'] ?? ''));
        if ($syncStatus !== '') {
            $params['syncStatus'] = $syncStatus;
            $clauses[] = 'latest_crm_sync_status = :syncStatus';
        }

        if ($clauses === []) {
            return '';
        }

        return ' WHERE ' . implode(' AND ', $clauses);
    }

    private function normalizeSyncStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === 'pushed' || $normalized === 'success' || $normalized === 'crm_pushed') {
            return 'Success';
        }
        if ($normalized === 'failed') {
            return 'Failed';
        }
        if ($normalized === 'skipped') {
            return 'Skipped';
        }

        return 'Pending';
    }
}
