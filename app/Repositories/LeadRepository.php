<?php

declare(strict_types=1);

namespace AWG\Repositories;

use PDO;
use Throwable;

final class LeadRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function findLatestCompletedByPhone(string $phone): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM leads WHERE phone = :phone AND spin_completed_at IS NOT NULL ORDER BY spin_completed_at DESC, id DESC LIMIT 1'
        );
        $statement->execute(['phone' => $phone]);
        $lead = $statement->fetch();
        return is_array($lead) ? $lead : null;
    }

    public function countByPhone(string $phone): int
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM leads WHERE phone = :phone');
        $statement->execute(['phone' => $phone]);
        return (int) $statement->fetchColumn();
    }

    public function create(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO leads (name, phone, prize, status, date_of_birth, date_of_anniversary, source, visit_count, coupon_code, crm_sync_status) VALUES (:name, :phone, :prize, :status, :date_of_birth, :date_of_anniversary, :source, :visit_count, :coupon_code, :crm_sync_status)'
        );

        $statement->execute([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'prize' => $data['prize'],
            'status' => $data['status'] ?? 'Unredeemed',
            'date_of_birth' => $data['date_of_birth'] ?: null,
            'date_of_anniversary' => $data['date_of_anniversary'] ?: null,
            'source' => $data['source'] ?: null,
            'visit_count' => (int) $data['visit_count'],
            'coupon_code' => $data['coupon_code'] ?: null,
            'crm_sync_status' => $data['crm_sync_status'] ?? 'Pending',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function findById(int $leadId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM leads WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $leadId]);
        $lead = $statement->fetch();
        return is_array($lead) ? $lead : null;
    }

    public function findLatestByPhone(string $phone): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM leads WHERE phone = :phone ORDER BY id DESC LIMIT 1');
        $statement->execute(['phone' => $phone]);
        $lead = $statement->fetch();
        return is_array($lead) ? $lead : null;
    }

    public function findLatestSummaryByPhone(string $phone): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT latest.*, stats.first_seen_at, stats.last_seen_at, stats.total_submissions
             FROM leads latest
             INNER JOIN (
                SELECT phone, MIN(created_at) AS first_seen_at, MAX(created_at) AS last_seen_at, COUNT(*) AS total_submissions
                FROM leads
                WHERE phone = :stats_phone
                GROUP BY phone
             ) stats ON stats.phone = latest.phone
             WHERE latest.phone = :phone
             ORDER BY latest.created_at DESC, latest.id DESC
             LIMIT 1'
        );
        $statement->execute([
            'stats_phone' => $phone,
            'phone' => $phone,
        ]);
        $lead = $statement->fetch();
        return is_array($lead) ? $lead : null;
    }

    public function listLatestSummariesByPhone(int $limit = 5000): array
    {
        $statement = $this->connection->prepare(
            'SELECT latest.*, stats.first_seen_at, stats.last_seen_at, stats.total_submissions
             FROM leads latest
             INNER JOIN (
                SELECT phone, MAX(id) AS latest_id, MIN(created_at) AS first_seen_at, MAX(created_at) AS last_seen_at, COUNT(*) AS total_submissions
                FROM leads
                WHERE phone IS NOT NULL AND phone <> ""
                GROUP BY phone
             ) stats ON stats.latest_id = latest.id
             ORDER BY stats.last_seen_at DESC, latest.id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function deleteLead(int $leadId): ?array
    {
        $lead = $this->findById($leadId);
        if (!is_array($lead)) {
            return null;
        }

        $statement = $this->connection->prepare('DELETE FROM leads WHERE id = :id');
        $statement->execute(['id' => $leadId]);

        return $lead;
    }

    public function markSpinCompleted(int $leadId): void
    {
        $statement = $this->connection->prepare('UPDATE leads SET spin_completed_at = NOW() WHERE id = :id AND spin_completed_at IS NULL');
        $statement->execute(['id' => $leadId]);
    }

    public function markRedeemed(int $leadId): void
    {
        $statement = $this->connection->prepare('UPDATE leads SET status = :status, redeemed_at = NOW() WHERE id = :id');
        $statement->execute([
            'id' => $leadId,
            'status' => 'Redeemed',
        ]);
    }

    public function replaceCouponCode(int $leadId, string $couponCode): void
    {
        $statement = $this->connection->prepare('UPDATE leads SET coupon_code = :coupon_code WHERE id = :id');
        $statement->execute([
            'id' => $leadId,
            'coupon_code' => $couponCode,
        ]);
    }

    public function issueSurpriseReward(int $leadId, string $rewardLabel, string $couponCode, int $issuedByUserId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE leads
             SET surprise_reward_label = :surprise_reward_label,
                 surprise_coupon_code = :surprise_coupon_code,
                 surprise_issued_at = NOW(),
                 surprise_issued_by = :surprise_issued_by
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $leadId,
            'surprise_reward_label' => $rewardLabel,
            'surprise_coupon_code' => $couponCode,
            'surprise_issued_by' => $issuedByUserId,
        ]);
    }

    public function listRecent(int $limit = 25): array
    {
        $statement = $this->connection->prepare('SELECT * FROM leads ORDER BY id DESC LIMIT :limit');
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function countAll(): int
    {
        $statement = $this->connection->query('SELECT COUNT(*) FROM leads');
        return (int) $statement->fetchColumn();
    }

    public function countRedeemed(): int
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM leads WHERE status = :status');
        $statement->execute(['status' => 'Redeemed']);
        return (int) $statement->fetchColumn();
    }

    public function updateCrmSyncStatus(int $leadId, string $status, ?string $code, ?string $message): void
    {
        $statement = $this->connection->prepare(
            'UPDATE leads SET crm_sync_status = :crm_sync_status, crm_sync_code = :crm_sync_code, crm_sync_message = :crm_sync_message WHERE id = :id'
        );

        $statement->execute([
            'id' => $leadId,
            'crm_sync_status' => $status,
            'crm_sync_code' => $code,
            'crm_sync_message' => $message,
        ]);
    }

    public function upsertCrmContact(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO crm_contacts (
                phone,
                name,
                date_of_birth,
                date_of_anniversary,
                first_seen_at,
                last_seen_at,
                latest_source,
                latest_lead_id,
                latest_lead_created_at,
                total_submissions,
                latest_crm_sync_status,
                latest_crm_sync_code,
                latest_crm_sync_message,
                last_crm_attempted_at,
                last_crm_pushed_at
            ) VALUES (
                :phone,
                :name,
                :date_of_birth,
                :date_of_anniversary,
                NOW(),
                NOW(),
                :latest_source,
                :latest_lead_id,
                NOW(),
                1,
                :latest_crm_sync_status,
                :latest_crm_sync_code,
                :latest_crm_sync_message,
                NOW(),
                :last_crm_pushed_at
            )
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                date_of_birth = VALUES(date_of_birth),
                date_of_anniversary = VALUES(date_of_anniversary),
                last_seen_at = NOW(),
                latest_source = VALUES(latest_source),
                latest_lead_id = VALUES(latest_lead_id),
                latest_lead_created_at = NOW(),
                total_submissions = total_submissions + 1,
                latest_crm_sync_status = VALUES(latest_crm_sync_status),
                latest_crm_sync_code = VALUES(latest_crm_sync_code),
                latest_crm_sync_message = VALUES(latest_crm_sync_message),
                last_crm_attempted_at = NOW(),
                last_crm_pushed_at = VALUES(last_crm_pushed_at)'
        );

        $statement->execute([
            'phone' => $data['phone'],
            'name' => $data['name'],
            'date_of_birth' => $data['date_of_birth'] ?: null,
            'date_of_anniversary' => $data['date_of_anniversary'] ?: null,
            'latest_source' => $data['latest_source'] ?: null,
            'latest_lead_id' => (int) $data['latest_lead_id'],
            'latest_crm_sync_status' => $data['latest_crm_sync_status'] ?: null,
            'latest_crm_sync_code' => $data['latest_crm_sync_code'] ?: null,
            'latest_crm_sync_message' => $data['latest_crm_sync_message'] ?: null,
            'last_crm_pushed_at' => ($data['last_crm_pushed_at'] ?? null) ?: null,
        ]);
    }

    public function createCrmPushLog(array $data): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO crm_push_logs (
                contact_id,
                lead_id,
                phone,
                contact_name,
                trigger_source,
                crm_endpoint,
                attempted,
                success,
                http_code,
                retry_count,
                attempt_count,
                response_message,
                request_payload_json,
                attempts_json
            ) VALUES (
                :contact_id,
                :lead_id,
                :phone,
                :contact_name,
                :trigger_source,
                :crm_endpoint,
                :attempted,
                :success,
                :http_code,
                :retry_count,
                :attempt_count,
                :response_message,
                :request_payload_json,
                :attempts_json
            )'
        );

        $statement->execute([
            'contact_id' => $data['contact_id'] ?? null,
            'lead_id' => (int) ($data['lead_id'] ?? 0),
            'phone' => $data['phone'],
            'contact_name' => $data['contact_name'] ?? null,
            'trigger_source' => $data['trigger_source'],
            'crm_endpoint' => $data['crm_endpoint'] ?? null,
            'attempted' => !empty($data['attempted']) ? 1 : 0,
            'success' => !empty($data['success']) ? 1 : 0,
            'http_code' => $data['http_code'] ?? null,
            'retry_count' => (int) ($data['retry_count'] ?? 0),
            'attempt_count' => (int) ($data['attempt_count'] ?? 1),
            'response_message' => $data['response_message'] ?? null,
            'request_payload_json' => $data['request_payload_json'] ?? null,
            'attempts_json' => $data['attempts_json'] ?? null,
        ]);
    }

    public function listCrmContacts(int $limit = 100): array
    {
        $statement = $this->connection->prepare('SELECT * FROM crm_contacts ORDER BY id DESC LIMIT :limit');
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function listCrmPushLogs(int $limit = 100): array
    {
        $statement = $this->connection->prepare('SELECT * FROM crm_push_logs ORDER BY id DESC LIMIT :limit');
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function listLeadsForCrmBackfill(int $limit = 5000): array
    {
        return $this->listLatestSummariesByPhone($limit);
    }

    public function countCrmLeads(array $filters = []): int
    {
        $params = [];
        $whereSql = $this->buildCrmLeadWhereClause($filters, $params);
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM leads' . $whereSql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    public function summarizeCrmLeads(array $filters = []): array
    {
        $params = [];
        $whereSql = $this->buildCrmLeadWhereClause($filters, $params);

        $statement = $this->connection->prepare(
            'SELECT
                COUNT(*) AS total_leads,
                SUM(CASE WHEN LOWER(COALESCE(prize, "")) LIKE :tryAgainPattern1 THEN 1 ELSE 0 END) AS total_try_again,
                SUM(CASE WHEN LOWER(COALESCE(prize, "")) NOT LIKE :tryAgainPattern2 AND COALESCE(prize, "") <> "" THEN 1 ELSE 0 END) AS total_won,
                SUM(CASE WHEN status = :redeemedStatus THEN 1 ELSE 0 END) AS total_redeemed
             FROM leads' . $whereSql
        );

        $statement->execute(array_merge($params, [
            'tryAgainPattern1' => '%try again%',
            'tryAgainPattern2' => '%try again%',
            'redeemedStatus' => 'Redeemed',
        ]));

        $row = $statement->fetch();
        if (!is_array($row)) {
            return [
                'totalLeads' => 0,
                'totalTryAgain' => 0,
                'totalWon' => 0,
                'totalRedeemed' => 0,
            ];
        }

        return [
            'totalLeads' => (int) ($row['total_leads'] ?? 0),
            'totalTryAgain' => (int) ($row['total_try_again'] ?? 0),
            'totalWon' => (int) ($row['total_won'] ?? 0),
            'totalRedeemed' => (int) ($row['total_redeemed'] ?? 0),
        ];
    }

    public function listCrmLeads(array $filters = [], int $offset = 0, int $limit = 100): array
    {
        $params = [];
        $whereSql = $this->buildCrmLeadWhereClause($filters, $params);

        $statement = $this->connection->prepare(
            'SELECT * FROM leads' . $whereSql . ' ORDER BY id DESC LIMIT :offset, :limit'
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

    private function buildCrmLeadWhereClause(array $filters, array &$params): string
    {
        $clauses = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $params['search'] = '%' . $search . '%';
            $clauses[] = '(
                phone LIKE :search
                OR name LIKE :search
                OR source LIKE :search
                OR coupon_code LIKE :search
                OR surprise_coupon_code LIKE :search
                OR prize LIKE :search
            )';
        }

        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '') {
            $params['source'] = '%' . $source . '%';
            $clauses[] = 'source LIKE :source';
        }

        $outcome = strtolower(trim((string) ($filters['outcome'] ?? '')));
        if ($outcome === 'won') {
            $clauses[] = 'LOWER(COALESCE(prize, "")) NOT LIKE "%try again%" AND COALESCE(prize, "") <> ""';
        } elseif ($outcome === 'try again' || $outcome === 'try_again') {
            $clauses[] = 'LOWER(COALESCE(prize, "")) LIKE "%try again%"';
        }

        $leadStatus = trim((string) ($filters['leadStatus'] ?? ''));
        if ($leadStatus !== '') {
            $params['leadStatus'] = $leadStatus;
            $clauses[] = 'status = :leadStatus';
        }

        $syncStatus = trim((string) ($filters['syncStatus'] ?? ''));
        if ($syncStatus !== '') {
            $params['syncStatus'] = $syncStatus;
            $clauses[] = 'COALESCE(crm_sync_status, "") = :syncStatus';
        }

        $fromDate = trim((string) ($filters['fromDate'] ?? ''));
        if ($fromDate !== '') {
            $params['fromDate'] = $fromDate;
            $clauses[] = 'DATE(created_at) >= :fromDate';
        }

        $toDate = trim((string) ($filters['toDate'] ?? ''));
        if ($toDate !== '') {
            $params['toDate'] = $toDate;
            $clauses[] = 'DATE(created_at) <= :toDate';
        }

        if ($clauses === []) {
            return '';
        }

        return ' WHERE ' . implode(' AND ', $clauses);
    }
}
