<?php

declare(strict_types=1);

namespace AWG\Config;

final class Env
{
    private static bool $loaded = false;

    public static function load(string $filePath): void
    {
        if (self::$loaded || !is_file($filePath)) {
            self::$loaded = true;
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            self::$loaded = true;
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);

            if ($name === '') {
                continue;
            }

            $value = self::normalizeValue($value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name . '=' . $value);
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public static function getProfiled(string $key, ?string $default = null): ?string
    {
        $profile = strtoupper((string) self::get('NK_ENV_PROFILE', ''));
        if ($profile !== '') {
            $profiled = self::get($key . '_' . $profile);
            if ($profiled !== null && $profiled !== '') {
                return $profiled;
            }
        }

        return self::get($key, $default);
    }

    private static function normalizeValue(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
