<?php

declare(strict_types=1);

namespace AWG\Repositories;

use PDO;

final class AppSettingRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function getGroup(string $group): array
    {
        $statement = $this->connection->prepare(
            'SELECT setting_key, setting_value, is_secret, updated_at
             FROM app_settings
             WHERE setting_group = :setting_group'
        );
        $statement->execute(['setting_group' => $group]);

        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if ($key === '') {
                continue;
            }

            $result[$key] = [
                'value' => $row['setting_value'] ?? null,
                'isSecret' => (int) ($row['is_secret'] ?? 0) === 1,
                'updatedAt' => $row['updated_at'] ?? null,
            ];
        }

        return $result;
    }

    public function getValue(string $group, string $key): ?string
    {
        $statement = $this->connection->prepare(
            'SELECT setting_value
             FROM app_settings
             WHERE setting_group = :setting_group AND setting_key = :setting_key
             LIMIT 1'
        );

        $statement->execute([
            'setting_group' => $group,
            'setting_key' => $key,
        ]);

        $value = $statement->fetchColumn();
        return is_string($value) || $value === null ? $value : null;
    }

    public function upsert(string $group, string $key, ?string $value, bool $isSecret = false): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO app_settings (setting_group, setting_key, setting_value, is_secret)
             VALUES (:setting_group, :setting_key, :setting_value, :is_secret)
             ON DUPLICATE KEY UPDATE
               setting_value = VALUES(setting_value),
               is_secret = VALUES(is_secret),
               updated_at = CURRENT_TIMESTAMP'
        );

        $statement->execute([
            'setting_group' => $group,
            'setting_key' => $key,
            'setting_value' => $value,
            'is_secret' => $isSecret ? 1 : 0,
        ]);
    }
}
