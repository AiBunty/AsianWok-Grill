<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Config\Database;
use AWG\Config\Env;
use AWG\Support\Logger;
use Throwable;

final class DiagnosticsService
{
    public function serverConnections(): array
    {
        $generatedAt = date('c');
        $database = $this->checkDatabase();
        $ftp = $this->checkFtp();

        $result = [
            'ok' => $database['ok'] && $ftp['ok'],
            'generatedAt' => $generatedAt,
            'database' => $database,
            'ftp' => $ftp,
        ];

        Logger::info('server_connection_diagnostic', $result);
        return $result;
    }

    private function checkDatabase(): array
    {
        $rawHost = (string) Env::getProfiled('DB_HOST', '');
        $host = $rawHost;
        $port = (string) Env::getProfiled('DB_PORT', '3306');

        if (preg_match('/^(.+):(\d+)$/', $rawHost, $matches) === 1) {
            $host = $matches[1];
            $port = $matches[2];
        }

        if ($host === '') {
            return [
                'ok' => false,
                'stage' => 'config',
                'message' => 'Database host is not configured.',
            ];
        }

        $resolved = gethostbyname($host);
        if ($resolved === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return [
                'ok' => false,
                'stage' => 'dns',
                'message' => 'Database hostname could not be resolved.',
            ];
        }

        $socket = @fsockopen($host, (int) $port, $errorNumber, $errorMessage, 5.0);
        if (!is_resource($socket)) {
            return [
                'ok' => false,
                'stage' => 'tcp',
                'message' => 'Database TCP connection failed.',
                'errorNumber' => $errorNumber,
            ];
        }
        fclose($socket);

        try {
            Database::connection();
            return [
                'ok' => true,
                'stage' => 'pdo',
                'message' => 'Database login succeeded.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'stage' => 'pdo',
                'message' => 'Database login failed.',
            ];
        }
    }

    private function checkFtp(): array
    {
        $host = (string) Env::getProfiled('FTP_HOST', '');
        $user = (string) Env::getProfiled('FTP_USER', '');
        $password = (string) Env::getProfiled('FTP_PASS', '');
        $remotePath = (string) Env::getProfiled('FTP_REMOTE_PATH', '/');

        if ($host === '' || $user === '' || $password === '') {
            return [
                'ok' => false,
                'stage' => 'config',
                'message' => 'FTP configuration is incomplete.',
            ];
        }

        $resolved = gethostbyname($host);
        if ($resolved === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return [
                'ok' => false,
                'stage' => 'dns',
                'message' => 'FTP hostname could not be resolved.',
            ];
        }

        $socket = @fsockopen($host, 21, $errorNumber, $errorMessage, 5.0);
        if (!is_resource($socket)) {
            return [
                'ok' => false,
                'stage' => 'tcp',
                'message' => 'FTP TCP connection failed.',
                'errorNumber' => $errorNumber,
            ];
        }
        fclose($socket);

        if (!function_exists('ftp_connect')) {
            return [
                'ok' => false,
                'stage' => 'extension',
                'message' => 'FTP extension is not available in PHP.',
            ];
        }

        $connection = @ftp_connect($host, 21, 8);
        if ($connection === false) {
            return [
                'ok' => false,
                'stage' => 'session',
                'message' => 'FTP session could not be created.',
            ];
        }

        try {
            if (@ftp_login($connection, $user, $password) === false) {
                return [
                    'ok' => false,
                    'stage' => 'login',
                    'message' => 'FTP login failed.',
                ];
            }

            if (@ftp_chdir($connection, $remotePath) === false) {
                return [
                    'ok' => false,
                    'stage' => 'path',
                    'message' => 'FTP remote path validation failed.',
                ];
            }

            return [
                'ok' => true,
                'stage' => 'session',
                'message' => 'FTP login and working-directory check succeeded.',
            ];
        } finally {
            @ftp_close($connection);
        }
    }
}
