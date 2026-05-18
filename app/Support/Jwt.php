<?php

declare(strict_types=1);

namespace AWG\Support;

use AWG\Config\Env;
use RuntimeException;

final class Jwt
{
    public static function issue(array $claims, int $ttlSeconds = 28800): string
    {
        $secret = (string) Env::getProfiled('JWT_SECRET', '');
        if ($secret === '') {
            throw new RuntimeException('JWT secret is not configured.');
        }

        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ]);

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $headerEncoded = self::base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadEncoded = self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $secret, true);

        return $headerEncoded . '.' . $payloadEncoded . '.' . self::base64UrlEncode($signature);
    }

    public static function parse(string $token): ?array
    {
        $secret = (string) Env::getProfiled('JWT_SECRET', '');
        if ($secret === '' || $token === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
        $expectedSignature = self::base64UrlEncode(hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $secret, true));
        if (!hash_equals($expectedSignature, $signatureEncoded)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($payloadEncoded);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
