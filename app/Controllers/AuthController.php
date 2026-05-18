<?php

declare(strict_types=1);

namespace AWG\Controllers;

use AWG\Middleware\AuthMiddleware;
use AWG\Services\AuthService;

final class AuthController
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function bootstrapStatus(): array
    {
        return $this->authService->bootstrapStatus();
    }

    public function bootstrapSuperadmin(): array
    {
        return $this->authService->bootstrapSuperadmin();
    }

    public function login(array $body): array
    {
        return $this->authService->login(
            (string) ($body['username'] ?? ''),
            (string) ($body['password'] ?? '')
        );
    }

    public function me(): array
    {
        return $this->authService->me(AuthMiddleware::user());
    }

    public function logout(): array
    {
        return $this->authService->logout(AuthMiddleware::user());
    }

    public function changePassword(array $body): array
    {
        return $this->authService->changePassword(AuthMiddleware::user(), $body);
    }

    public function listUsers(): array
    {
        return $this->authService->listUsers();
    }

    public function createUser(array $body): array
    {
        $claims = AuthMiddleware::user();
        return $this->authService->createUser($body, (int) ($claims['sub'] ?? 0));
    }

    public function setUserStatus(array $body): array
    {
        $claims = AuthMiddleware::user();
        return $this->authService->setUserStatus($body, (int) ($claims['sub'] ?? 0));
    }

    public function setUserPermissions(array $body): array
    {
        $claims = AuthMiddleware::user();
        return $this->authService->setUserPermissions($body, (int) ($claims['sub'] ?? 0));
    }

    public function resetPassword(array $body): array
    {
        $claims = AuthMiddleware::user();
        return $this->authService->resetPassword($body, (int) ($claims['sub'] ?? 0));
    }

    public function deleteUser(array $body): array
    {
        $claims = AuthMiddleware::user();
        return $this->authService->deleteUser($body, (int) ($claims['sub'] ?? 0));
    }

    public function getQrRedirectSettings(): array
    {
        return $this->authService->getQrRedirectSettings();
    }

    public function setQrRedirectSettings(array $body): array
    {
        $claims = AuthMiddleware::user();
        return $this->authService->setQrRedirectSettings($body, isset($claims['sub']) ? (int) $claims['sub'] : null);
    }

    public function listQrRedirects(): array
    {
        return $this->authService->listQrRedirects();
    }

    public function saveQrRedirect(array $body): array
    {
        $claims = AuthMiddleware::user();
        return $this->authService->saveQrRedirect($body, isset($claims['sub']) ? (int) $claims['sub'] : null);
    }

    public function setQrRedirectActive(array $body): array
    {
        $claims = AuthMiddleware::user();
        return $this->authService->setQrRedirectActive($body, isset($claims['sub']) ? (int) $claims['sub'] : null);
    }

    public function deleteQrRedirect(array $body): array
    {
        return $this->authService->deleteQrRedirect($body);
    }

    public function getWhatsappWorkspace(): array
    {
        return $this->authService->getWhatsappWorkspace();
    }

    public function saveWhatsappConfig(array $body): array
    {
        $claims = AuthMiddleware::user();
        return $this->authService->saveWhatsappConfig($body, isset($claims['sub']) ? (int) $claims['sub'] : null);
    }

    public function syncWhatsappTemplates(): array
    {
        return $this->authService->syncWhatsappTemplates();
    }

    public function saveWhatsappMapping(array $body): array
    {
        $claims = AuthMiddleware::user();
        return $this->authService->saveWhatsappMapping($body, isset($claims['sub']) ? (int) $claims['sub'] : null);
    }

    public function sendTestWhatsappTemplate(array $body): array
    {
        return $this->authService->sendTestWhatsappTemplate($body);
    }

    public function saveWhatsappTemplateDraft(array $body): array
    {
        $claims = AuthMiddleware::user();
        return $this->authService->saveWhatsappTemplateDraft($body, isset($claims['sub']) ? (int) $claims['sub'] : null);
    }

    public function submitWhatsappTemplateDraft(array $body): array
    {
        $claims = AuthMiddleware::user();
        return $this->authService->submitWhatsappTemplateDraft($body, isset($claims['sub']) ? (int) $claims['sub'] : null);
    }

    public function previewWhatsappTemplate(array $body): array
    {
        return $this->authService->previewWhatsappTemplate($body);
    }

    public function runWhatsappScheduler(array $body): array
    {
        return $this->authService->runWhatsappScheduler($body);
    }

    public function getMenuBlockerSettings(): array
    {
        return $this->authService->getMenuBlockerSettings([]);
    }

    public function updateMenuBlockerSettings(array $body): array
    {
        return $this->authService->updateMenuBlockerSettings($body);
    }

    public function getMenuBlockerStats(array $body = []): array
    {
        return $this->authService->getMenuBlockerStats($body);
    }

    public function getMenuBlockerPhoneHistory(array $body = []): array
    {
        return $this->authService->getMenuBlockerPhoneHistory($body);
    }
}
