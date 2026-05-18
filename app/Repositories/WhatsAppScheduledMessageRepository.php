<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class WhatsAppScheduledMessageRepository
{
    public function listDue(int $limit): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM whatsapp_scheduled_messages
             WHERE status = "pending" AND due_at <= NOW()
             ORDER BY due_at ASC, id ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markProcessed(int $id, array $result): void
    {
        $db = Database::connection();
        $status = !empty($result['success']) ? 'sent' : 'failed';
        $stmt = $db->prepare(
            'UPDATE whatsapp_scheduled_messages
             SET status = :status,
                 attempt_count = attempt_count + 1,
                 last_result_code = :last_result_code,
                 last_result_message = :last_result_message,
                 sent_at = CASE WHEN :status = "sent" THEN NOW() ELSE sent_at END,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'last_result_code' => (string) ($result['code'] ?? ''),
            'last_result_message' => (string) ($result['message'] ?? ''),
        ]);
    }
}
