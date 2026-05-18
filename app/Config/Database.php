<?php

declare(strict_types=1);

namespace AWG\Config;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $database = (string) Env::getProfiled('DB_NAME', '');
        $username = (string) Env::getProfiled('DB_USER', '');
        $password = (string) Env::getProfiled('DB_PASS', '');

        if ($database === '' || $username === '') {
            throw new RuntimeException('Database configuration is incomplete.');
        }

        $attempts = self::resolveConnectionAttempts();
        $lastException = null;

        foreach ($attempts as [$host, $port]) {
            if ($host === '') {
                continue;
            }

            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);

            try {
                self::$connection = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                return self::$connection;
            } catch (PDOException $exception) {
                $lastException = $exception;
            }
        }

        throw new RuntimeException('Database connection failed.', 0, $lastException);
    }

    private static function resolveConnectionAttempts(): array
    {
        $attempts = [];

        $primaryHost = (string) Env::getProfiled('DB_HOST', '127.0.0.1');
        $primaryPort = (string) Env::getProfiled('DB_PORT', '3306');
        $attempts[] = self::splitHostAndPort($primaryHost, $primaryPort);

        $fallbackHost = (string) Env::getProfiled('DB_HOST_FALLBACK', '');
        $fallbackPort = (string) Env::getProfiled('DB_PORT_FALLBACK', '3306');
        if ($fallbackHost !== '') {
            $attempts[] = self::splitHostAndPort($fallbackHost, $fallbackPort);
        }

        return $attempts;
    }

    private static function splitHostAndPort(string $rawHost, string $port): array
    {
        if (preg_match('/^(.+):(\d+)$/', $rawHost, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [$rawHost, $port];
    }
}
