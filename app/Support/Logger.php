<?php

declare(strict_types=1);

namespace AWG\Support;

final class Logger
{
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $directory = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $record = [
            'time' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        @file_put_contents(
            $directory . '/app.log',
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
