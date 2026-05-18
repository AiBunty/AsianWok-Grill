<?php

declare(strict_types=1);

namespace AWG\Middleware;

use AWG\Config\Env;

final class CorsMiddleware
{
    public static function handle(): void
    {
        $allowedOrigins = array_filter(array_map('trim', explode(',', (string) Env::getProfiled('CORS_ALLOWED_ORIGINS', '*'))));
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');

        if (in_array('*', $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
