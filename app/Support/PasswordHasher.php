<?php

declare(strict_types=1);

namespace AWG\Support;

final class PasswordHasher
{
    public static function make(string $plainPassword): array
    {
        $salt = bin2hex(random_bytes(16));
        $hash = password_hash($plainPassword . $salt, PASSWORD_DEFAULT);

        return [
            'salt' => $salt,
            'hash' => $hash,
        ];
    }

    public static function verify(string $plainPassword, string $salt, string $hash): bool
    {
        if ($salt === '' || $hash === '') {
            return false;
        }

        return password_verify($plainPassword . $salt, $hash);
    }
}
