<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/app/Config/Database.php';
require_once __DIR__ . '/app/Config/Env.php';

use AWG\Config\Env;
use AWG\Config\Database;

function sendHttpResponse(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit($statusCode >= 400 ? 1 : 0);
}

if (PHP_SAPI !== 'cli') {
    $expectedToken = (string) Env::getProfiled('MIGRATION_TOKEN', '');
    $providedToken = (string) ($_SERVER['HTTP_X_MIGRATION_TOKEN'] ?? ($_GET['token'] ?? ''));

    if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        sendHttpResponse(403, [
            'ok' => false,
            'error' => 'FORBIDDEN',
            'message' => 'Migration token is missing or invalid.',
        ]);
    }
}

try {
    $connection = Database::connection();
    $migrationDirectory = __DIR__ . '/database/migrations';
    $applied = [];

    if (!is_dir($migrationDirectory)) {
        throw new RuntimeException('Migration directory not found.');
    }

    $connection->exec('CREATE TABLE IF NOT EXISTS migrations (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $existing = $connection->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $existingMap = array_fill_keys($existing ?: [], true);

    $files = glob($migrationDirectory . '/*.sql') ?: [];
    sort($files);

    foreach ($files as $file) {
        $name = basename($file);
        if (isset($existingMap[$name])) {
            continue;
        }

        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            continue;
        }

        $connection->beginTransaction();

        try {
            $connection->exec($sql);
            $statement = $connection->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
            $statement->execute(['migration' => $name]);
            if ($connection->inTransaction()) {
                $connection->commit();
            }
            $applied[] = $name;

            if (PHP_SAPI === 'cli') {
                fwrite(STDOUT, "Applied {$name}\n");
            }
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, 'Done. Applied ' . count($applied) . " migration(s).\n");
        exit(0);
    }

    sendHttpResponse(200, [
        'ok' => true,
        'result' => 'migrations_applied',
        'appliedCount' => count($applied),
        'applied' => $applied,
    ]);
} catch (Throwable $exception) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . "\n");
        exit(1);
    }

    sendHttpResponse(500, [
        'ok' => false,
        'error' => 'MIGRATION_FAILED',
        'message' => $exception instanceof RuntimeException ? $exception->getMessage() : 'Migration failed.',
    ]);
}
