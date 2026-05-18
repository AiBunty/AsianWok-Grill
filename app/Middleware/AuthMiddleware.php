<?php

declare(strict_types=1);

namespace AWG\Middleware;

use AWG\Support\Jwt;

final class AuthMiddleware
{
    public static function user(): ?array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        return Jwt::parse(trim($matches[1]));
    }
}
