<?php

declare(strict_types=1);

namespace AWG\Middleware;

use AWG\Config\Database;
use AWG\Repositories\UserRepository;
use Throwable;

final class PermissionMiddleware
{
    public static function requireAuthenticatedUser(): array
    {
        $claims = AuthMiddleware::user();
        if (!is_array($claims) || !isset($claims['sub'])) {
            return [
                'ok' => false,
                'error' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid token.',
            ];
        }

        try {
            $repository = new UserRepository(Database::connection());
            $user = $repository->findById((int) $claims['sub']);
            if (!is_array($user)) {
                return [
                    'ok' => false,
                    'error' => 'UNAUTHORIZED',
                    'message' => 'Authenticated user was not found.',
                ];
            }

            if (($user['status'] ?? 'disabled') !== 'active') {
                return [
                    'ok' => false,
                    'error' => 'USER_DISABLED',
                    'message' => 'This user is disabled.',
                ];
            }

            return [
                'ok' => true,
                'claims' => $claims,
                'user' => $user,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'AUTH_CONTEXT_UNAVAILABLE',
                'message' => 'Authenticated user context could not be loaded.',
            ];
        }
    }

    public static function requirePermission(string $permission, bool $superadminOnly = false): array
    {
        $auth = self::requireAuthenticatedUser();
        if (($auth['ok'] ?? false) !== true) {
            return $auth;
        }

        $user = $auth['user'];
        $role = (string) ($user['role'] ?? 'admin');
        if ($role === 'superadmin') {
            return $auth;
        }

        if ($superadminOnly) {
            return [
                'ok' => false,
                'error' => 'FORBIDDEN',
                'message' => 'This action is restricted to superadmin users.',
            ];
        }

        $permissions = json_decode((string) ($user['permissions'] ?? '[]'), true);
        if (!is_array($permissions) || empty($permissions[$permission])) {
            return [
                'ok' => false,
                'error' => 'FORBIDDEN',
                'message' => 'You do not have permission to perform this action.',
            ];
        }

        return $auth;
    }
}
