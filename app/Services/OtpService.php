<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Repositories\EventOtpRepository;
use function password_hash;
use function password_verify;
use function random_int;

final class OtpService
{
    private const OTP_LENGTH = 6;
    private const OTP_TTL_SECONDS = 600;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;
    private const VERIFY_TOKEN_TTL_SECONDS = 1800;

    public function __construct(private readonly EventOtpRepository $repository)
    {
    }

    public function sendEventOtp(string $eventId, string $email, string $customerName = ''): array
    {
        $eventId = trim($eventId);
        $email = strtolower(trim($email));

        if ($eventId === '' || $email === '') {
            return [
                'ok' => false,
                'error' => 'INVALID_OTP_REQUEST',
                'message' => 'event_id and email are required.',
            ];
        }

        $existing = $this->repository->findByEventAndEmail($eventId, $email);
        if ($existing) {
            $resendAllowedAt = strtotime((string) ($existing['resend_allowed_at'] ?? ''));
            if ($resendAllowedAt > time()) {
                $retryAfter = max(1, $resendAllowedAt - time());
                return [
                    'ok' => false,
                    'error' => 'OTP_RESEND_COOLDOWN',
                    'message' => 'Please wait before requesting another OTP.',
                'retry_after_seconds' => $retryAfter,
                'retryAfterSeconds' => $retryAfter,
                ];
            }
        }

        $otp = str_pad((string) random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);
        $now = time();
        $expiresAt = date('Y-m-d H:i:s', $now + self::OTP_TTL_SECONDS);
        $resendAllowedAt = date('Y-m-d H:i:s', $now + self::RESEND_COOLDOWN_SECONDS);

        $this->repository->upsertVerificationRecord([
            'event_id' => $eventId,
            'email' => $email,
            'customer_name' => $customerName,
            'otp_hash' => password_hash($otp, PASSWORD_BCRYPT),
            'attempt_count' => 0,
            'otp_requested_at' => date('Y-m-d H:i:s', $now),
            'resend_allowed_at' => $resendAllowedAt,
            'expires_at' => $expiresAt,
        ]);

        return [
            'ok' => true,
            'message' => 'OTP sent successfully.',
            'otp' => $otp,
            'expires_in_seconds' => self::OTP_TTL_SECONDS,
            'expiresInSeconds' => self::OTP_TTL_SECONDS,
            'resend_allowed_in_seconds' => self::RESEND_COOLDOWN_SECONDS,
            'resendAllowedInSeconds' => self::RESEND_COOLDOWN_SECONDS,
        ];
    }

    public function verifyEventOtp(string $eventId, string $email, string $otp): array
    {
        $eventId = trim($eventId);
        $email = strtolower(trim($email));
        $otp = trim($otp);

        if (!preg_match('/^\d{6}$/', $otp)) {
            return [
                'ok' => false,
                'error' => 'OTP_INVALID_FORMAT',
                'message' => 'Enter the 6 digit OTP sent to your email.',
            ];
        }

        $record = $this->repository->findByEventAndEmail($eventId, $email);
        if (!$record) {
            return [
                'ok' => false,
                'error' => 'OTP_NOT_REQUESTED',
                'message' => 'No OTP request found.',
            ];
        }

        if (time() > strtotime((string) ($record['expires_at'] ?? ''))) {
            return [
                'ok' => false,
                'error' => 'OTP_EXPIRED',
                'message' => 'OTP expired. Request a new one.',
            ];
        }

        $attemptCount = (int) ($record['attempt_count'] ?? 0);
        if ($attemptCount >= self::MAX_ATTEMPTS) {
            return [
                'ok' => false,
                'error' => 'OTP_MAX_ATTEMPTS_EXCEEDED',
                'message' => 'Maximum attempts exceeded. Request a new OTP.',
            ];
        }

        if (!password_verify($otp, (string) ($record['otp_hash'] ?? ''))) {
            $this->repository->incrementAttemptsByEventEmail($eventId, $email);
            return [
                'ok' => false,
                'error' => 'OTP_INVALID',
                'message' => 'Invalid OTP.',
                'attempts_remaining' => max(0, self::MAX_ATTEMPTS - ($attemptCount + 1)),
                'attemptsRemaining' => max(0, self::MAX_ATTEMPTS - ($attemptCount + 1)),
            ];
        }

        $verifyToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($verifyToken, PASSWORD_BCRYPT);
        $verifyExpiresAt = date('Y-m-d H:i:s', time() + self::VERIFY_TOKEN_TTL_SECONDS);
        $this->repository->markVerifiedByEventEmail($eventId, $email, $tokenHash, $verifyExpiresAt);

        return [
            'ok' => true,
            'message' => 'OTP verified.',
            'verification_token' => $verifyToken,
            'verificationToken' => $verifyToken,
            'verification_expires_in_seconds' => self::VERIFY_TOKEN_TTL_SECONDS,
            'verificationExpiresInSeconds' => self::VERIFY_TOKEN_TTL_SECONDS,
        ];
    }

    public function validateVerificationToken(string $eventId, string $email, string $token): bool
    {
        $record = $this->repository->findByEventAndEmail($eventId, $email);
        if (!$record) {
            return false;
        }

        $tokenHash = (string) ($record['verification_token_hash'] ?? '');
        $tokenExpiresAt = (string) ($record['verification_expires_at'] ?? '');
        if ($tokenHash === '' || $tokenExpiresAt === '') {
            return false;
        }

        if (time() > strtotime($tokenExpiresAt)) {
            $this->repository->clearExpiredByEventEmail($eventId, $email);
            return false;
        }

        return password_verify($token, $tokenHash);
    }

    // Legacy compatibility wrappers
    public function requestOtp(string $phone, string $email): array
    {
        return [
            'ok' => false,
            'error' => 'LEGACY_OTP_DEPRECATED',
            'message' => 'Use send_event_otp with event_id and email.',
        ];
    }

    public function verifyOtp(string $phone, string $otp): array
    {
        return [
            'ok' => false,
            'error' => 'LEGACY_OTP_DEPRECATED',
            'message' => 'Use verify_event_otp with event_id and email.',
        ];
    }

    public function validateToken(string $phone, string $token): bool
    {
        return false;
    }

    public function clearExpiredOtps(): int
    {
        return 0;
    }
}
