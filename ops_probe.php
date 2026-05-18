<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/app/Config/Database.php';

use AWG\Config\Database;
use AWG\Config\Env;

header('Content-Type: application/json; charset=UTF-8');

$expectedToken = (string) Env::getProfiled('MIGRATION_TOKEN', '');
$providedToken = (string) ($_SERVER['HTTP_X_MIGRATION_TOKEN'] ?? ($_GET['token'] ?? ''));

if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'FORBIDDEN',
        'message' => 'Probe token is missing or invalid.',
    ], JSON_UNESCAPED_SLASHES);
    exit(1);
}

$mode = trim((string) ($_GET['mode'] ?? ''));

try {
    $connection = Database::connection();

    if ($mode === 'auth') {
        $userCount = (int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $statement = $connection->prepare('SELECT COUNT(*) FROM users WHERE role = :role');
        $statement->execute(['role' => 'superadmin']);
        $superadminCount = (int) $statement->fetchColumn();

        echo json_encode([
            'ok' => true,
            'mode' => 'auth',
            'phpVersion' => PHP_VERSION,
            'userCount' => $userCount,
            'superadminCount' => $superadminCount,
        ], JSON_UNESCAPED_SLASHES);
        exit(0);
    }

    if ($mode === 'lead') {
        $leadCount = (int) $connection->query('SELECT COUNT(*) FROM leads')->fetchColumn();

        echo json_encode([
            'ok' => true,
            'mode' => 'lead',
            'phpVersion' => PHP_VERSION,
            'leadCount' => $leadCount,
        ], JSON_UNESCAPED_SLASHES);
        exit(0);
    }

    if ($mode === 'opcache_reset') {
        $reset = function_exists('opcache_reset') ? opcache_reset() : false;

        echo json_encode([
            'ok' => true,
            'mode' => 'opcache_reset',
            'phpVersion' => PHP_VERSION,
            'opcacheAvailable' => function_exists('opcache_reset'),
            'resetResult' => $reset,
        ], JSON_UNESCAPED_SLASHES);
        exit(0);
    }

    echo json_encode([
        'ok' => true,
        'mode' => 'ping',
        'phpVersion' => PHP_VERSION,
        'message' => 'Probe is reachable.',
    ], JSON_UNESCAPED_SLASHES);
    exit(0);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'PROBE_FAILED',
        'mode' => $mode,
        'phpVersion' => PHP_VERSION,
        'exceptionClass' => get_class($exception),
        'message' => $exception->getMessage(),
        'file' => basename($exception->getFile()),
        'line' => $exception->getLine(),
    ], JSON_UNESCAPED_SLASHES);
    exit(1);
}