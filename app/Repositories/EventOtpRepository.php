<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;
use RuntimeException;

final class EventOtpRepository
{
    private const TABLE = 'event_otp_verifications';

    public function findByEventAndEmail(string $eventId, string $email): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE event_id = ? AND email = ? LIMIT 1');
        $stmt->execute([strtolower(trim($eventId)), strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function upsertVerificationRecord(array $data): bool
    {
        $db = Database::connection();
        $eventId = strtolower(trim((string) ($data['event_id'] ?? '')));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($eventId === '' || $email === '') {
            throw new RuntimeException('event_id and email are required for OTP upsert.');
        }

        $existing = $this->findByEventAndEmail($eventId, $email);
        if ($existing) {
            $stmt = $db->prepare(
                'UPDATE ' . self::TABLE . ' SET
                    customer_name = :customer_name,
                    otp_hash = :otp_hash,
                    attempt_count = :attempt_count,
                    otp_requested_at = :otp_requested_at,
                    resend_allowed_at = :resend_allowed_at,
                    expires_at = :expires_at,
                    verified_at = NULL,
                    verification_token_hash = NULL,
                    verification_expires_at = NULL,
                    updated_at = NOW()
                 WHERE event_id = :event_id AND email = :email'
            );

            return $stmt->execute([
                'customer_name' => (string) ($data['customer_name'] ?? ''),
                'otp_hash' => (string) ($data['otp_hash'] ?? ''),
                'attempt_count' => (int) ($data['attempt_count'] ?? 0),
                'otp_requested_at' => (string) ($data['otp_requested_at'] ?? date('Y-m-d H:i:s')),
                'resend_allowed_at' => (string) ($data['resend_allowed_at'] ?? date('Y-m-d H:i:s')),
                'expires_at' => (string) ($data['expires_at'] ?? date('Y-m-d H:i:s')),
                'event_id' => $eventId,
                'email' => $email,
            ]);
        }

        $stmt = $db->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                event_id, email, customer_name, otp_hash, attempt_count,
                otp_requested_at, resend_allowed_at, expires_at,
                verified_at, verification_token_hash, verification_expires_at,
                created_at, updated_at
            ) VALUES (
                :event_id, :email, :customer_name, :otp_hash, :attempt_count,
                :otp_requested_at, :resend_allowed_at, :expires_at,
                NULL, NULL, NULL,
                NOW(), NOW()
            )'
        );

        return $stmt->execute([
            'event_id' => $eventId,
            'email' => $email,
            'customer_name' => (string) ($data['customer_name'] ?? ''),
            'otp_hash' => (string) ($data['otp_hash'] ?? ''),
            'attempt_count' => (int) ($data['attempt_count'] ?? 0),
            'otp_requested_at' => (string) ($data['otp_requested_at'] ?? date('Y-m-d H:i:s')),
            'resend_allowed_at' => (string) ($data['resend_allowed_at'] ?? date('Y-m-d H:i:s')),
            'expires_at' => (string) ($data['expires_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    public function incrementAttemptsByEventEmail(string $eventId, string $email): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE ' . self::TABLE . ' SET attempt_count = attempt_count + 1, updated_at = NOW() WHERE event_id = ? AND email = ?');
        return $stmt->execute([strtolower(trim($eventId)), strtolower(trim($email))]);
    }

    public function markVerifiedByEventEmail(string $eventId, string $email, string $tokenHash, string $expiresAt): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE ' . self::TABLE . ' SET
                verified_at = NOW(),
                verification_token_hash = :verification_token_hash,
                verification_expires_at = :verification_expires_at,
                updated_at = NOW()
             WHERE event_id = :event_id AND email = :email'
        );

        return $stmt->execute([
            'verification_token_hash' => $tokenHash,
            'verification_expires_at' => $expiresAt,
            'event_id' => strtolower(trim($eventId)),
            'email' => strtolower(trim($email)),
        ]);
    }

    public function clearExpiredByEventEmail(string $eventId, string $email): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE ' . self::TABLE . ' SET
                verification_token_hash = NULL,
                verification_expires_at = NULL,
                updated_at = NOW()
             WHERE event_id = :event_id AND email = :email'
        );

        return $stmt->execute([
            'event_id' => strtolower(trim($eventId)),
            'email' => strtolower(trim($email)),
        ]);
    }

    // Legacy compatibility methods below

    public function findByPhone(string $phone): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM event_otps WHERE phone = ? LIMIT 1');
        $stmt->execute([$phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function upsert(array $data): bool
    {
        $db = Database::connection();
        $phone = (string) ($data['phone'] ?? '');
        if ($phone === '') {
            return false;
        }

        $existing = $this->findByPhone($phone);
        if ($existing) {
            $stmt = $db->prepare('UPDATE event_otps SET otp_hash = ?, email = ?, expires_at = ?, request_count = ?, last_requested_at = ?, attempt_count = 0 WHERE phone = ?');
            return $stmt->execute([
                (string) ($data['otp_hash'] ?? ''),
                (string) ($data['email'] ?? ''),
                (string) ($data['expires_at'] ?? date('Y-m-d H:i:s')),
                (int) ($data['request_count'] ?? 1),
                (string) ($data['last_requested_at'] ?? date('Y-m-d H:i:s')),
                $phone,
            ]);
        }

        $stmt = $db->prepare('INSERT INTO event_otps (phone, email, otp_hash, expires_at, request_count, last_requested_at, attempt_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), NOW())');
        return $stmt->execute([
            $phone,
            (string) ($data['email'] ?? ''),
            (string) ($data['otp_hash'] ?? ''),
            (string) ($data['expires_at'] ?? date('Y-m-d H:i:s')),
            (int) ($data['request_count'] ?? 1),
            (string) ($data['last_requested_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    public function incrementAttempts(string $phone): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE event_otps SET attempt_count = attempt_count + 1 WHERE phone = ?');
        return $stmt->execute([$phone]);
    }

    public function markVerified(string $phone, string $token): bool
    {
        $db = Database::connection();
        $hashedToken = password_hash($token, PASSWORD_BCRYPT);
        $stmt = $db->prepare('UPDATE event_otps SET verified_at = NOW(), verification_token = ? WHERE phone = ?');
        return $stmt->execute([$hashedToken, $phone]);
    }

    public function deleteExpired(): int
    {
        $db = Database::connection();
        $stmt = $db->prepare('DELETE FROM event_otps WHERE expires_at < NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }
}
