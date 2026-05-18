<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Config\Env;
use AWG\Config\Database;
use AWG\Repositories\AuthAuditRepository;
use AWG\Repositories\EventRepository;
use AWG\Repositories\QrRedirectRepository;
use AWG\Repositories\QrRedirectSettingsRepository;
use AWG\Repositories\UserRepository;
use AWG\Support\Jwt;
use AWG\Support\PasswordHasher;
use RuntimeException;
use Throwable;

final class AuthService
{
    public function bootstrapStatus(): array
    {
        try {
            $repository = new UserRepository(Database::connection());
            $userCount = $repository->countUsers();
            $superadminCount = $repository->countSuperadmins();

            return [
                'ok' => true,
                'result' => 'bootstrap_status',
                'databaseReady' => true,
                'userCount' => $userCount,
                'superadminCount' => $superadminCount,
                'bootstrapRequired' => $userCount === 0 || $superadminCount === 0,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'AUTH_BOOTSTRAP_UNAVAILABLE',
                'message' => $this->safeMessage($exception),
                'databaseReady' => false,
                'bootstrapRequired' => true,
            ];
        }
    }

    public function bootstrapSuperadmin(): array
    {
        try {
            $connection = Database::connection();
            $repository = new UserRepository($connection);
            $audit = new AuthAuditRepository($connection);

            if ($repository->countSuperadmins() > 0) {
                return [
                    'ok' => true,
                    'result' => 'bootstrap_skipped',
                    'message' => 'Superadmin already exists.',
                ];
            }

            $username = (string) Env::getProfiled('BOOTSTRAP_SUPERADMIN_MOBILE', '');
            $password = (string) Env::getProfiled('BOOTSTRAP_SUPERADMIN_PASSWORD', '');
            if ($username === '' || $password === '') {
                throw new RuntimeException('Bootstrap superadmin credentials are not configured.');
            }

            $passwordData = PasswordHasher::make($password);
            $userId = $repository->create([
                'username' => $username,
                'display_name' => 'Superadmin',
                'role' => 'superadmin',
                'password_hash' => $passwordData['hash'],
                'password_salt' => $passwordData['salt'],
                'force_password_change' => 1,
                'permissions' => [],
            ]);

            $audit->log($username, 'bootstrap_superadmin_created', $this->clientIp(), ['userId' => $userId]);

            return [
                'ok' => true,
                'result' => 'bootstrap_created',
                'userId' => $userId,
                'username' => $username,
                'forcePasswordChange' => true,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'AUTH_BOOTSTRAP_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function login(string $username, string $password): array
    {
        try {
            $connection = Database::connection();
            $repository = new UserRepository($connection);
            $audit = new AuthAuditRepository($connection);
            $user = $repository->findByUsername(trim($username));

            if (!is_array($user)) {
                $audit->log($username, 'login_failed_unknown_user', $this->clientIp());
                return [
                    'ok' => false,
                    'error' => 'INVALID_CREDENTIALS',
                    'message' => 'Invalid username or password.',
                ];
            }

            if (($user['status'] ?? 'disabled') !== 'active') {
                $audit->log((string) $user['username'], 'login_blocked_disabled', $this->clientIp(), ['userId' => (int) $user['id']]);
                return [
                    'ok' => false,
                    'error' => 'USER_DISABLED',
                    'message' => 'This user is disabled.',
                ];
            }

            $lockoutUntil = (string) ($user['lockout_until'] ?? '');
            if ($lockoutUntil !== '' && strtotime($lockoutUntil) !== false && strtotime($lockoutUntil) > time()) {
                return [
                    'ok' => false,
                    'error' => 'ACCOUNT_LOCKED',
                    'message' => 'Account is temporarily locked.',
                    'lockoutUntil' => $lockoutUntil,
                ];
            }

            if (!PasswordHasher::verify($password, (string) $user['password_salt'], (string) $user['password_hash'])) {
                $failedAttempts = (int) ($user['failed_attempts'] ?? 0) + 1;
                $lockoutValue = $failedAttempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
                $repository->recordFailedLogin((int) $user['id'], $failedAttempts, $lockoutValue);
                $audit->log((string) $user['username'], 'login_failed_bad_password', $this->clientIp(), ['userId' => (int) $user['id'], 'failedAttempts' => $failedAttempts]);

                return [
                    'ok' => false,
                    'error' => 'INVALID_CREDENTIALS',
                    'message' => 'Invalid username or password.',
                    'remainingAttempts' => max(0, 5 - $failedAttempts),
                ];
            }

            $repository->updateLoginSuccess((int) $user['id'], $this->clientIp());
            $audit->log((string) $user['username'], 'login_success', $this->clientIp(), ['userId' => (int) $user['id']]);

            $permissions = json_decode((string) ($user['permissions'] ?? '[]'), true);
            $token = Jwt::issue([
                'sub' => (int) $user['id'],
                'username' => (string) $user['username'],
                'role' => (string) $user['role'],
                'permissions' => is_array($permissions) ? $permissions : [],
            ]);

            return [
                'ok' => true,
                'result' => 'login_success',
                'token' => $token,
                'user' => $this->presentUser($repository->findById((int) $user['id'])),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'AUTH_LOGIN_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function me(?array $claims): array
    {
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
                'result' => 'me',
                'user' => $this->presentUser($user),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'AUTH_ME_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function logout(?array $claims): array
    {
        if (!is_array($claims) || !isset($claims['sub'])) {
            return [
                'ok' => false,
                'error' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid token.',
            ];
        }

        return [
            'ok' => true,
            'result' => 'logout_success',
            'message' => 'Logged out successfully.',
        ];
    }

    public function changePassword(?array $claims, array $body): array
    {
        if (!is_array($claims) || !isset($claims['sub'])) {
            return [
                'ok' => false,
                'error' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid token.',
            ];
        }

        $oldPassword = (string) ($body['oldPassword'] ?? $body['currentPassword'] ?? '');
        $newPassword = (string) ($body['newPassword'] ?? '');
        if ($newPassword === '' || strlen($newPassword) < 8) {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'New password must be at least 8 characters long.',
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

            if (!PasswordHasher::verify($oldPassword, (string) $user['password_salt'], (string) $user['password_hash'])) {
                return [
                    'ok' => false,
                    'error' => 'INVALID_CREDENTIALS',
                    'message' => 'Current password is incorrect.',
                ];
            }

            $passwordData = PasswordHasher::make($newPassword);
            $repository->updatePassword((int) $user['id'], $passwordData['hash'], $passwordData['salt'], false, (int) $user['id']);

            return [
                'ok' => true,
                'result' => 'password_changed',
                'message' => 'Password changed successfully.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'AUTH_CHANGE_PASSWORD_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function listUsers(): array
    {
        try {
            $repository = new UserRepository(Database::connection());
            $users = $repository->listUsers();

            return [
                'ok' => true,
                'users' => array_values(array_filter(array_map(fn (array $user) => $this->presentUser($user), $users))),
                'count' => count($users),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'AUTH_LIST_USERS_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function createUser(array $body, int $createdBy): array
    {
        $username = trim((string) ($body['username'] ?? ''));
        $displayName = trim((string) ($body['displayName'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $role = strtolower(trim((string) ($body['role'] ?? 'admin')));
        $permissions = is_array($body['permissions'] ?? null) ? $body['permissions'] : [];

        if ($username === '' || $displayName === '' || $password === '') {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Username, display name, and password are required.',
            ];
        }

        if (!in_array($role, ['admin', 'superadmin'], true)) {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Role must be admin or superadmin.',
            ];
        }

        try {
            $repository = new UserRepository(Database::connection());
            if ($repository->findByUsername($username) !== null) {
                return [
                    'ok' => false,
                    'error' => 'DUPLICATE_USER',
                    'message' => 'A user with this username already exists.',
                ];
            }

            $passwordData = PasswordHasher::make($password);
            $userId = $repository->create([
                'username' => $username,
                'display_name' => $displayName,
                'role' => $role,
                'password_hash' => $passwordData['hash'],
                'password_salt' => $passwordData['salt'],
                'force_password_change' => 1,
                'permissions' => $permissions,
            ]);

            return [
                'ok' => true,
                'message' => 'User created successfully.',
                'user' => $this->presentUser($repository->findById($userId)),
                'createdBy' => $createdBy,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'AUTH_CREATE_USER_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function setUserStatus(array $body, int $updatedBy): array
    {
        $userId = (int) ($body['userId'] ?? $body['id'] ?? 0);
        $status = strtolower(trim((string) ($body['status'] ?? '')));
        if ($userId <= 0 || !in_array($status, ['active', 'disabled'], true)) {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Valid user id and status are required.',
            ];
        }

        try {
            $repository = new UserRepository(Database::connection());
            $user = $repository->findById($userId);
            if (!is_array($user)) {
                return [
                    'ok' => false,
                    'error' => 'USER_NOT_FOUND',
                    'message' => 'User was not found.',
                ];
            }

            $repository->setStatus($userId, $status, $updatedBy);
            return [
                'ok' => true,
                'message' => 'User status updated.',
                'user' => $this->presentUser($repository->findById($userId)),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'AUTH_SET_USER_STATUS_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function setUserPermissions(array $body, int $updatedBy): array
    {
        $userId = (int) ($body['userId'] ?? $body['id'] ?? 0);
        $permissions = is_array($body['permissions'] ?? null) ? $body['permissions'] : [];
        if ($userId <= 0) {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Valid user id is required.',
            ];
        }

        try {
            $repository = new UserRepository(Database::connection());
            if ($repository->findById($userId) === null) {
                return [
                    'ok' => false,
                    'error' => 'USER_NOT_FOUND',
                    'message' => 'User was not found.',
                ];
            }

            $repository->setPermissions($userId, $permissions, $updatedBy);
            return [
                'ok' => true,
                'message' => 'User permissions updated.',
                'user' => $this->presentUser($repository->findById($userId)),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'AUTH_SET_USER_PERMISSIONS_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function resetPassword(array $body, int $updatedBy): array
    {
        $userId = (int) ($body['userId'] ?? $body['id'] ?? 0);
        $newPassword = (string) ($body['newPassword'] ?? $body['password'] ?? '');
        if ($userId <= 0 || strlen($newPassword) < 8) {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Valid user id and a password with at least 8 characters are required.',
            ];
        }

        try {
            $repository = new UserRepository(Database::connection());
            if ($repository->findById($userId) === null) {
                return [
                    'ok' => false,
                    'error' => 'USER_NOT_FOUND',
                    'message' => 'User was not found.',
                ];
            }

            $passwordData = PasswordHasher::make($newPassword);
            $repository->updatePassword($userId, $passwordData['hash'], $passwordData['salt'], true, $updatedBy);

            return [
                'ok' => true,
                'message' => 'Password reset successfully.',
                'user' => $this->presentUser($repository->findById($userId)),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'AUTH_RESET_PASSWORD_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function deleteUser(array $body, int $updatedBy): array
    {
        $userId = (int) ($body['userId'] ?? $body['id'] ?? 0);
        if ($userId <= 0) {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Valid user id is required.',
            ];
        }

        if ($userId === $updatedBy) {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'You cannot delete your own user account.',
            ];
        }

        try {
            $repository = new UserRepository(Database::connection());
            $user = $repository->findById($userId);
            if (!is_array($user)) {
                return [
                    'ok' => false,
                    'error' => 'USER_NOT_FOUND',
                    'message' => 'User was not found.',
                ];
            }

            $repository->deleteById($userId);
            return [
                'ok' => true,
                'message' => 'User deleted successfully.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'AUTH_DELETE_USER_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function getQrRedirectSettings(): array
    {
        $repo = new QrRedirectSettingsRepository();
        $rows = $repo->listAll();
        $settingsByChannel = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $settingsByChannel[(string) ($row['channel'] ?? '')] = $row;
        }

        $customer = $settingsByChannel['customer'] ?? [];
        $legacyDefaultTarget = (string) ($customer['manual_url'] ?? '');
        if ($legacyDefaultTarget === '' && (string) ($customer['destination_mode'] ?? '') === 'preset') {
            $legacyDefaultTarget = '/menu.html';
        }

        return [
            'ok' => true,
            'action' => 'auth_get_qr_redirect_settings',
            'settings' => [
                'defaultTargetUrl' => $legacyDefaultTarget,
                'fallbackSlug' => (string) ($customer['destination_key'] ?? 'menu'),
            ],
            'presetCatalog' => $this->buildQrPresetCatalog(),
            'settingsByChannel' => $settingsByChannel,
            'rows' => $rows,
        ];
    }

    public function setQrRedirectSettings(array $body, ?int $updatedBy): array
    {
        $payload = is_array($body['settings'] ?? null) ? $body['settings'] : $body;
        $channel = strtolower(trim((string) ($payload['channel'] ?? '')));
        $destinationMode = strtolower(trim((string) ($payload['destinationMode'] ?? $payload['destination_mode'] ?? 'preset')));
        $destinationKey = trim((string) ($payload['destinationKey'] ?? $payload['destination_key'] ?? 'menu'));
        $manualUrl = trim((string) ($payload['manualUrl'] ?? $payload['manual_url'] ?? ''));
        $isActive = !array_key_exists('isActive', $payload) || !empty($payload['isActive']) || !empty($payload['is_active']);

        if ($channel === '' && array_key_exists('defaultTargetUrl', $payload)) {
            $legacyUrl = trim((string) ($payload['defaultTargetUrl'] ?? ''));
            $legacyFallbackSlug = trim((string) ($payload['fallbackSlug'] ?? 'menu'));
            if ($legacyUrl !== '' && preg_match('/^https:\/\/.+/i', $legacyUrl) !== 1 && str_starts_with($legacyUrl, '/') !== true) {
                return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'Settings URL must be https URL or site-relative path.'];
            }
            $repo = new QrRedirectSettingsRepository();
            $repo->upsert([
                'channel' => 'customer',
                'destination_mode' => $legacyUrl !== '' ? 'manual' : 'preset',
                'destination_key' => $legacyFallbackSlug !== '' ? $legacyFallbackSlug : 'menu',
                'manual_url' => $legacyUrl,
                'is_active' => $isActive ? 1 : 0,
                'updated_by' => $updatedBy,
            ]);

            return [
                'ok' => true,
                'action' => 'auth_set_qr_redirect_settings',
                'message' => 'QR redirect settings updated.',
                'settings' => $repo->listAll(),
            ];
        }

        if (!in_array($channel, ['customer', 'admin'], true)) {
            return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'channel must be customer or admin.'];
        }

        if (!in_array($destinationMode, ['preset', 'manual'], true)) {
            return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'destination_mode must be preset or manual.'];
        }

        if ($destinationMode === 'manual') {
            $isValid = preg_match('/^https:\/\/.+/i', $manualUrl) === 1;
            if (!$isValid) {
                return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'Settings manual URL must be absolute https URL.'];
            }
        }

        $repo = new QrRedirectSettingsRepository();
        $repo->upsert([
            'channel' => $channel,
            'destination_mode' => $destinationMode,
            'destination_key' => $destinationKey,
            'manual_url' => $manualUrl,
            'is_active' => $isActive ? 1 : 0,
            'updated_by' => $updatedBy,
        ]);

        return [
            'ok' => true,
            'action' => 'auth_set_qr_redirect_settings',
            'message' => 'QR redirect settings updated.',
            'settings' => $repo->listAll(),
        ];
    }

    public function listQrRedirects(): array
    {
        $repo = new QrRedirectRepository();
        $repo->ensureSystemRows();
        $redirects = array_map(function (array $row): array {
            $isProtected = $this->isProtectedQrRow($row);
            $row['is_system'] = $isProtected ? 1 : 0;
            $row['isSystem'] = $isProtected;
            return $row;
        }, $repo->listAll());

        return [
            'ok' => true,
            'action' => 'auth_list_qr_redirects',
            'redirects' => $redirects,
        ];
    }

    public function saveQrRedirect(array $body, ?int $updatedBy): array
    {
        $payload = is_array($body['record'] ?? null) ? $body['record'] : $body;
        $id = (int) ($payload['id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? $payload['title'] ?? ''));
        $slug = trim((string) ($payload['slug'] ?? ''));
        $mode = strtolower(trim((string) ($payload['redirectMode'] ?? $payload['redirect_mode'] ?? $payload['mode'] ?? 'preset')));
        $presetKey = trim((string) ($payload['presetKey'] ?? $payload['preset_key'] ?? 'menu'));
        $manualUrl = trim((string) ($payload['manualUrl'] ?? $payload['manual_url'] ?? $payload['targetUrl'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));
        $isActive = !array_key_exists('isActive', $payload) || !empty($payload['isActive']) || !empty($payload['is_active']);
        $targetUrl = '';

        if ($name === '' || $slug === '' || !preg_match('/^[a-z0-9][a-z0-9\-]{1,180}$/', $slug)) {
            return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'Valid name and slug are required.'];
        }

        if (!in_array($mode, ['preset', 'manual'], true)) {
            return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'redirect_mode must be preset or manual.'];
        }

        if ($mode === 'manual') {
            if (!$this->isValidRecordManualUrl($manualUrl)) {
                return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'Invalid manual URL.'];
            }

            $targetUrl = $manualUrl;
        } else {
            $catalog = $this->buildQrPresetCatalog();
            if ($presetKey === '' || !isset($catalog[$presetKey])) {
                return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'Invalid preset key.'];
            }

            $targetUrl = trim((string) ($catalog[$presetKey]['url'] ?? ''));
            $manualUrl = '';

            if ($targetUrl === '') {
                return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'Preset URL could not be resolved.'];
            }
        }

        $repo = new QrRedirectRepository();
        $repo->ensureSystemRows();

        if ($id > 0) {
            $existing = $repo->findById($id);
            if (!is_array($existing)) {
                return ['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'QR redirect not found.'];
            }
            if ($this->isProtectedQrRow($existing)) {
                // Lock slug for system QRs — silently restore original slug so callers don't break
                $slug = (string) ($existing['slug'] ?? $slug);
                if (!empty($payload['legacyChannel']) && (string) $payload['legacyChannel'] !== (string) ($existing['legacy_channel'] ?? '')) {
                    return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'System QR legacy channel cannot be changed.'];
                }
            }
        }

        $savedId = $repo->save([
            'id' => $id,
            'name' => $name,
            'title' => $name,
            'slug' => $slug,
            'target_url' => $targetUrl,
            'redirect_mode' => $mode,
            'preset_key' => $presetKey,
            'manual_url' => $manualUrl,
            'notes' => $notes,
            'is_active' => $isActive ? 1 : 0,
            'updated_by' => $updatedBy,
            'created_by' => $updatedBy,
        ]);

        return [
            'ok' => true,
            'action' => 'auth_save_qr_redirect',
            'id' => $savedId,
            'redirect' => $repo->findById($savedId),
        ];
    }

    public function setQrRedirectActive(array $body, ?int $updatedBy): array
    {
        $id = (int) ($body['id'] ?? 0);
        $isActive = !empty($body['isActive']) || !empty($body['is_active']) || !empty($body['active']);
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'Valid id is required.'];
        }

        $repo = new QrRedirectRepository();
        $repo->ensureSystemRows();
        $row = $repo->findById($id);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'QR redirect not found.'];
        }

        if ($this->isProtectedQrRow($row) && !$isActive) {
            return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'System QR records cannot be deactivated.'];
        }

        $repo->setActive($id, $isActive, $updatedBy);
        return [
            'ok' => true,
            'action' => 'auth_set_qr_redirect_active',
            'message' => 'QR redirect status updated.',
            'redirect' => $repo->findById($id),
        ];
    }

    public function deleteQrRedirect(array $body): array
    {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'Valid id is required.'];
        }

        $repo = new QrRedirectRepository();
        $repo->ensureSystemRows();
        $row = $repo->findById($id);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'QR redirect not found.'];
        }

        if ($this->isProtectedQrRow($row)) {
            return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'System QR records cannot be deleted.'];
        }

        $repo->delete($id);
        return [
            'ok' => true,
            'action' => 'auth_delete_qr_redirect',
            'message' => 'QR redirect deleted.',
        ];
    }

    public function getWhatsappWorkspace(): array
    {
        return (new WhatsAppCloudService())->workspace();
    }

    public function saveWhatsappConfig(array $body, ?int $updatedBy): array
    {
        $payload = is_array($body['config'] ?? null) ? $body['config'] : $body;
        return (new WhatsAppCloudService())->saveConfig($payload, $updatedBy);
    }

    public function syncWhatsappTemplates(): array
    {
        return (new WhatsAppCloudService())->syncTemplates();
    }

    public function saveWhatsappMapping(array $body, ?int $updatedBy): array
    {
        $payload = is_array($body['mapping'] ?? null) ? $body['mapping'] : $body;
        return (new WhatsAppCloudService())->saveMapping($payload, $updatedBy);
    }

    public function sendTestWhatsappTemplate(array $body): array
    {
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : $body;
        return (new WhatsAppCloudService())->sendTestTemplate($payload);
    }

    public function saveWhatsappTemplateDraft(array $body, ?int $userId): array
    {
        $payload = is_array($body['draft'] ?? null) ? $body['draft'] : $body;
        return (new WhatsAppCloudService())->saveTemplateDraft($payload, $userId);
    }

    public function submitWhatsappTemplateDraft(array $body, ?int $userId): array
    {
        $draftId = (int) ($body['draftId'] ?? $body['draft_id'] ?? 0);
        return (new WhatsAppCloudService())->submitTemplateDraft($draftId, $userId);
    }

    public function previewWhatsappTemplate(array $body): array
    {
        $payload = is_array($body['preview'] ?? null) ? $body['preview'] : $body;
        return (new WhatsAppCloudService())->previewTemplate($payload);
    }

    private function isProtectedQrRow(array $row): bool
    {
        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
        if (in_array($slug, ['guest-menu', 'admin-portal'], true)) {
            return true;
        }

        $legacyChannel = strtolower(trim((string) ($row['legacy_channel'] ?? '')));
        return $legacyChannel !== '';
    }

    public function runWhatsappScheduler(array $body): array
    {
        $limit = (int) ($body['limit'] ?? 50);
        return (new WhatsAppCloudService())->runScheduler($limit);
    }

    private function isValidRecordManualUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $isHttps = preg_match('/^https:\/\/.+/i', $url) === 1;
        $isLocalhost = preg_match('/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?(\/|$)/i', $url) === 1;
        return $isHttps || $isLocalhost;
    }

    private function buildQrPresetCatalog(): array
    {
        $catalog = [
            'home' => ['label' => 'Home', 'url' => '/home.html'],
            'menu' => ['label' => 'AWG Menu', 'url' => '/menu.html'],
            'namastemenu' => ['label' => 'Namaste Menu', 'url' => '/namastemenu.html'],
            'cocktail' => ['label' => 'Cocktail Menu', 'url' => '/cocktail.html'],
            'events' => ['label' => 'Events Page', 'url' => '/events.html'],
            'admin' => ['label' => 'Admin Portal', 'url' => '/admin/admin-portal.html'],
            'scanner' => ['label' => 'QR Scanner', 'url' => '/qr.html'],
            'scanner-exclusive' => ['label' => 'QR Scanner Exclusive Page', 'url' => '/qr.html'],
            'scanner:landing' => ['label' => 'QR Middle Layer', 'url' => '/qr/scan.html'],
        ];

        $discovered = $this->discoverPublicPresetPages();
        foreach ($discovered as $key => $row) {
            if (!isset($catalog[$key])) {
                $catalog[$key] = $row;
            }
        }

        $this->syncCorePresetUrlsFromDiscovered($catalog, $discovered);

        $events = [];
        try {
            $events = (new EventRepository())->getActive();
        } catch (Throwable $exception) {
            $events = [];
        }
        $today = date('Y-m-d');
        $hasActiveEvents = false;
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $eventId = trim((string) ($event['event_id'] ?? $event['id'] ?? ''));
            if ($eventId === '') {
                continue;
            }

            $endDate = (string) ($event['end_date'] ?? $event['start_date'] ?? $today);
            if ($endDate !== '' && $endDate < $today) {
                continue;
            }

            $hasActiveEvents = true;

            $catalog['event:' . $eventId] = [
                'label' => 'Event: ' . (string) ($event['title'] ?? $eventId),
                'url' => '/events.html?eventId=' . rawurlencode($eventId),
            ];
        }

        $this->appendActiveEventPresetsFromSettings($catalog);

        if (!$hasActiveEvents && $this->hasAnyActiveEventPreset($catalog)) {
            $hasActiveEvents = true;
        }

        if ($hasActiveEvents && !isset($catalog['events-active'])) {
            $catalog['events-active'] = [
                'label' => 'Active Events (Live)',
                'url' => '/events.html',
            ];
        }

        return $catalog;
    }

    private function hasAnyActiveEventPreset(array $catalog): bool
    {
        foreach (array_keys($catalog) as $key) {
            if (str_starts_with((string) $key, 'event-active:') || str_starts_with((string) $key, 'event:')) {
                return true;
            }
        }

        return false;
    }

    private function syncCorePresetUrlsFromDiscovered(array &$catalog, array $discovered): void
    {
        $homeUrl = $this->findDiscoveredUrl($discovered, ['/home.html', '/index.html']);
        if ($homeUrl !== null) {
            $catalog['home']['url'] = $homeUrl;
        }

        $menuUrl = $this->findDiscoveredUrl($discovered, ['/menu.html']);
        if ($menuUrl !== null) {
            $catalog['menu']['url'] = $menuUrl;
        }

        $namasteMenuUrl = $this->findDiscoveredUrl($discovered, ['/namastemenu.html']);
        if ($namasteMenuUrl !== null) {
            $catalog['namastemenu']['url'] = $namasteMenuUrl;
        }

        $eventsUrl = $this->findDiscoveredUrl($discovered, ['/events.html']);
        if ($eventsUrl !== null) {
            $catalog['events']['url'] = $eventsUrl;
        }

        $adminUrl = $this->findDiscoveredUrl($discovered, ['/admin/admin-portal.html', '/admin/index.html', '/admin/login.html']);
        if ($adminUrl !== null) {
            $catalog['admin']['url'] = $adminUrl;
        }

        $scannerUrl = $this->findDiscoveredUrl($discovered, ['/qr.html', '/qr/scan.html']);
        if ($scannerUrl !== null) {
            $catalog['scanner']['url'] = $scannerUrl;
            $catalog['scanner-exclusive']['url'] = $scannerUrl;
        }
    }

    private function findDiscoveredUrl(array $discovered, array $preferredUrls): ?string
    {
        foreach ($preferredUrls as $preferredUrl) {
            $needle = strtolower(trim((string) $preferredUrl));
            foreach ($discovered as $row) {
                $candidate = strtolower(trim((string) ($row['url'] ?? '')));
                if ($candidate !== '' && $candidate === $needle) {
                    return (string) ($row['url'] ?? '');
                }
            }
        }

        return null;
    }

    private function appendActiveEventPresetsFromSettings(array &$catalog): void
    {
        try {
            $statement = Database::connection()->prepare('SELECT setting_value FROM app_settings WHERE setting_group = :group_name AND setting_key = :key_name LIMIT 1');
            $statement->execute([
                'group_name' => 'events',
                'key_name' => 'items',
            ]);

            $raw = (string) ($statement->fetchColumn() ?: '');
            if ($raw === '') {
                return;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return;
            }

            foreach ($decoded as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $eventId = trim((string) ($event['id'] ?? ''));
                if ($eventId === '') {
                    continue;
                }

                $isActive = true;
                if (array_key_exists('isActive', $event)) {
                    $isActive = !empty($event['isActive']);
                } elseif (array_key_exists('is_active', $event)) {
                    $isActive = !empty($event['is_active']);
                }

                if (!$isActive) {
                    continue;
                }

                $label = trim((string) ($event['title'] ?? ''));
                if ($label === '') {
                    $label = 'Event ' . $eventId;
                }

                $key = 'event-active:' . $eventId;
                if (!isset($catalog[$key])) {
                    $catalog[$key] = [
                        'label' => 'Active Event: ' . $label,
                        'url' => '/events.html?eventId=' . rawurlencode($eventId),
                    ];
                }
            }
        } catch (Throwable $_exception) {
            // Ignore optional preset enrichment failures.
        }
    }

    private function discoverPublicPresetPages(): array
    {
        $publicRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'asianwokandgrill.in';
        if (!is_dir($publicRoot)) {
            return [];
        }

        $directories = [
            '',
            'admin',
            'qr',
        ];

        $catalog = [];
        foreach ($directories as $relative) {
            $dirPath = $relative === '' ? $publicRoot : $publicRoot . DIRECTORY_SEPARATOR . $relative;
            if (!is_dir($dirPath)) {
                continue;
            }

            $entries = scandir($dirPath);
            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (substr($entry, -5) !== '.html') {
                    continue;
                }

                $lower = strtolower($entry);
                if (str_contains($lower, 'old') || str_contains($lower, 'instruction')) {
                    continue;
                }

                $urlPath = '/' . ltrim(($relative === '' ? '' : ($relative . '/')) . $entry, '/');
                $slugBase = trim(strtolower(str_replace('.html', '', $entry)));
                if ($slugBase === '') {
                    continue;
                }

                $key = $relative === '' ? ('page:' . $slugBase) : ('page:' . $relative . ':' . $slugBase);
                $catalog[$key] = [
                    'label' => $this->formatPresetLabel($relative, $slugBase),
                    'url' => $urlPath,
                ];
            }
        }

        return $catalog;
    }

    private function formatPresetLabel(string $relative, string $slugBase): string
    {
        $words = preg_replace('/[^a-z0-9]+/i', ' ', $slugBase) ?? $slugBase;
        $title = ucwords(trim($words));
        if ($title === '') {
            $title = 'Page';
        }

        if ($relative === '') {
            return $title;
        }

        return strtoupper($relative) . ': ' . $title;
    }

    private function safeMessage(Throwable $exception): string
    {
        if ($exception instanceof RuntimeException) {
            return $exception->getMessage();
        }

        return 'Auth bootstrap status is unavailable.';
    }

    private function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    private function presentUser(?array $user): ?array
    {
        if (!is_array($user)) {
            return null;
        }

        $permissions = json_decode((string) ($user['permissions'] ?? '[]'), true);

        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'displayName' => (string) $user['display_name'],
            'role' => (string) $user['role'],
            'status' => (string) $user['status'],
            'forcePasswordChange' => (bool) $user['force_password_change'],
            'permissions' => is_array($permissions) ? $permissions : [],
            'lastLoginAt' => $user['last_login_at'] ?: null,
        ];
    }

    // ============================================================
    // Menu Blocker Admin Methods
    // ============================================================

    public function getMenuBlockerSettings(array $body): array
    {
        try {
            $service = new MenuBlockerService(Database::connection());
            $settings = $service->getSettings();
            return [
                'ok' => true,
                'action' => 'auth_get_menu_blocker_settings',
                'settings' => $settings,
            ];
        } catch (Throwable $ex) {
            return [
                'ok' => false,
                'error' => 'MENU_BLOCKER_ERROR',
                'message' => $this->safeMessage($ex),
            ];
        }
    }

    public function updateMenuBlockerSettings(array $body): array
    {
        try {
            $service = new MenuBlockerService(Database::connection());
            $result = $service->updateSettings($body['settings'] ?? []);
            return [
                'ok' => true,
                'action' => 'auth_update_menu_blocker_settings',
                'result' => $result,
            ];
        } catch (Throwable $ex) {
            return [
                'ok' => false,
                'error' => 'MENU_BLOCKER_ERROR',
                'message' => $this->safeMessage($ex),
            ];
        }
    }

    public function getMenuBlockerStats(array $body): array
    {
        try {
            $service = new MenuBlockerService(Database::connection());
            $startDate = $body['startDate'] ?? null;
            $endDate = $body['endDate'] ?? null;
            $stats = $service->getStatistics($startDate, $endDate);

            return [
                'ok' => true,
                'action' => 'auth_get_menu_blocker_stats',
                'stats' => $stats,
            ];
        } catch (Throwable $ex) {
            return [
                'ok' => false,
                'error' => 'MENU_BLOCKER_ERROR',
                'message' => $this->safeMessage($ex),
            ];
        }
    }

    public function getMenuBlockerPhoneHistory(array $body): array
    {
        try {
            $phone = $body['phone'] ?? '';
            if (empty($phone)) {
                return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'Phone number required.'];
            }

            $service = new MenuBlockerService(Database::connection());
            $history = $service->getPhoneHistory($phone);

            return [
                'ok' => true,
                'action' => 'auth_get_menu_blocker_phone_history',
                'phone' => $phone,
                'history' => $history,
            ];
        } catch (Throwable $ex) {
            return [
                'ok' => false,
                'error' => 'MENU_BLOCKER_ERROR',
                'message' => $this->safeMessage($ex),
            ];
        }
    }
}
