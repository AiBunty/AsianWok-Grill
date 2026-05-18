<?php

declare(strict_types=1);

namespace AWG\Repositories;

use PDO;

final class AuthAuditRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function log(?string $username, string $action, ?string $ipAddress, array $details = []): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO auth_audit_logs (username, action, ip_address, details_json) VALUES (:username, :action, :ip_address, :details_json)'
        );

        $statement->execute([
            'username' => $username,
            'action' => $action,
            'ip_address' => $ipAddress,
            'details_json' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
