<?php

declare(strict_types=1);

namespace AWG\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function countUsers(): int
    {
        $statement = $this->connection->query('SELECT COUNT(*) FROM users');
        return (int) $statement->fetchColumn();
    }

    public function countSuperadmins(): int
    {
        $statement = $this->connection->prepare("SELECT COUNT(*) FROM users WHERE role = :role");
        $statement->execute(['role' => 'superadmin']);
        return (int) $statement->fetchColumn();
    }

    public function findByUsername(string $username): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    public function listUsers(): array
    {
        $statement = $this->connection->query('SELECT * FROM users ORDER BY id DESC');
        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function create(array $data): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO users (username, display_name, role, password_hash, password_salt, status, force_password_change, permissions) VALUES (:username, :display_name, :role, :password_hash, :password_salt, :status, :force_password_change, :permissions)'
        );

        $statement->execute([
            'username' => $data['username'],
            'display_name' => $data['display_name'],
            'role' => $data['role'],
            'password_hash' => $data['password_hash'],
            'password_salt' => $data['password_salt'],
            'status' => $data['status'] ?? 'active',
            'force_password_change' => (int) ($data['force_password_change'] ?? 0),
            'permissions' => json_encode($data['permissions'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function updateLoginSuccess(int $userId, string $ipAddress): void
    {
        $statement = $this->connection->prepare(
            'UPDATE users SET failed_attempts = 0, lockout_until = NULL, last_login_at = NOW(), last_login_ip = :last_login_ip WHERE id = :id'
        );

        $statement->execute([
            'id' => $userId,
            'last_login_ip' => $ipAddress,
        ]);
    }

    public function recordFailedLogin(int $userId, int $failedAttempts, ?string $lockoutUntil): void
    {
        $statement = $this->connection->prepare(
            'UPDATE users SET failed_attempts = :failed_attempts, lockout_until = :lockout_until WHERE id = :id'
        );

        $statement->execute([
            'id' => $userId,
            'failed_attempts' => $failedAttempts,
            'lockout_until' => $lockoutUntil,
        ]);
    }

    public function setStatus(int $userId, string $status, ?int $updatedBy = null): void
    {
        $statement = $this->connection->prepare(
            'UPDATE users SET status = :status, updated_by = :updated_by WHERE id = :id'
        );

        $statement->execute([
            'id' => $userId,
            'status' => $status,
            'updated_by' => $updatedBy,
        ]);
    }

    public function setPermissions(int $userId, array $permissions, ?int $updatedBy = null): void
    {
        $statement = $this->connection->prepare(
            'UPDATE users SET permissions = :permissions, updated_by = :updated_by WHERE id = :id'
        );

        $statement->execute([
            'id' => $userId,
            'permissions' => json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_by' => $updatedBy,
        ]);
    }

    public function updatePassword(int $userId, string $passwordHash, string $passwordSalt, bool $forcePasswordChange = false, ?int $updatedBy = null): void
    {
        $statement = $this->connection->prepare(
            'UPDATE users
             SET password_hash = :password_hash,
                 password_salt = :password_salt,
                 force_password_change = :force_password_change,
                 failed_attempts = 0,
                 lockout_until = NULL,
                 updated_by = :updated_by
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $userId,
            'password_hash' => $passwordHash,
            'password_salt' => $passwordSalt,
            'force_password_change' => $forcePasswordChange ? 1 : 0,
            'updated_by' => $updatedBy,
        ]);
    }

    public function deleteById(int $userId): void
    {
        $statement = $this->connection->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }
}
