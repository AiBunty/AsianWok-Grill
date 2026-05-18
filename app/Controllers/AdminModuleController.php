<?php

declare(strict_types=1);

namespace AWG\Controllers;

use AWG\Services\AdminModuleService;

final class AdminModuleController
{
    public function __construct(private readonly AdminModuleService $adminService)
    {
    }

    public function dashboardSummary(): array
    {
        return $this->adminService->dashboardSummary();
    }

    public function verifyPhone(array $query): array
    {
        return $this->adminService->verifyPhone((string) ($query['phone'] ?? ''));
    }

    public function redeemCoupon(array $body): array
    {
        return $this->adminService->redeemCoupon((int) ($body['leadId'] ?? 0));
    }

    public function issueSurpriseCoupon(array $body, int $issuedByUserId): array
    {
        return $this->adminService->issueSurpriseCoupon(
            (int) ($body['leadId'] ?? 0),
            (string) ($body['rewardLabel'] ?? ''),
            $issuedByUserId
        );
    }

    public function listEvents(): array
    {
        return $this->adminService->listEvents();
    }

    public function saveEvent(array $body): array
    {
        return $this->adminService->saveEvent($body);
    }

    public function deleteEvent(array $body): array
    {
        return $this->adminService->deleteEvent((string) ($body['id'] ?? $body['eventId'] ?? $body['event_id'] ?? ''));
    }

    public function cloneEvent(array $body): array
    {
        return $this->adminService->cloneEvent((string) ($body['id'] ?? $body['eventId'] ?? $body['event_id'] ?? ''));
    }

    public function toggleEvent(array $body): array
    {
        return $this->adminService->toggleEvent((string) ($body['id'] ?? $body['eventId'] ?? $body['event_id'] ?? ''), (bool) ($body['isActive'] ?? $body['active'] ?? true));
    }

    public function uploadEventImage(array $body): array
    {
        return $this->adminService->uploadEventImage($body);
    }

    public function generateEventQr(array $body): array
    {
        return $this->adminService->generateEventQr($body);
    }

    public function verifyEventQr(array $body): array
    {
        return $this->adminService->verifyEventQr((string) ($body['eventId'] ?? ''), (string) ($body['guestToken'] ?? $body['qrCode'] ?? ''));
    }

    public function previewEventQr(array $body): array
    {
        return $this->adminService->previewEventQr((string) ($body['eventId'] ?? ''), (string) ($body['guestToken'] ?? $body['qrCode'] ?? ''));
    }

    public function batchCheckinEventQr(array $body): array
    {
        $tokens = is_array($body['tokens'] ?? null) ? $body['tokens'] : [];
        return $this->adminService->batchCheckinEventQr((string) ($body['eventId'] ?? ''), $tokens);
    }

    public function eventGuestReport(array $query): array
    {
        return $this->adminService->eventGuestReport($query);
    }

    public function eventMailLogReport(array $query): array
    {
        return $this->adminService->eventMailLogReport($query);
    }

    public function listPublicLiveEvents(): array
    {
        return $this->adminService->listPublicLiveEvents();
    }

    public function registerPublicEvent(array $body): array
    {
        return $this->adminService->registerPublicEvent($body);
    }

    public function getSpinOffers(): array
    {
        return $this->adminService->getSpinOffers();
    }

    public function setSpinOffers(array $body): array
    {
        return $this->adminService->setSpinOffers($body);
    }

    public function getAppSettings(): array
    {
        return $this->adminService->getAppSettings();
    }

    public function setAppSettings(array $body): array
    {
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : $body;
        return $this->adminService->setAppSettings($settings);
    }

    public function getBlockerSettings(): array
    {
        return $this->adminService->getBlockerSettings();
    }

    public function verifyBlockerPasscode(array $body): array
    {
        return $this->adminService->verifyBlockerPasscode((string) ($body['passcode'] ?? ''));
    }

    public function updateBlockerPages(array $body): array
    {
        return $this->adminService->updateBlockerPages($body);
    }

    public function verifyScannerPasscode(array $body): array
    {
        return $this->adminService->verifyScannerPasscode((string) ($body['passcode'] ?? ''));
    }

    public function getQrRedirectSettings(): array
    {
        return $this->adminService->getQrRedirectSettings();
    }

    public function setQrRedirectSettings(array $body): array
    {
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : $body;
        return $this->adminService->setQrRedirectSettings($settings);
    }

    public function listQrRedirects(): array
    {
        return $this->adminService->listQrRedirects();
    }

    public function saveQrRedirect(array $body): array
    {
        return $this->adminService->saveQrRedirect($body);
    }

    public function setQrRedirectActive(array $body): array
    {
        return $this->adminService->setQrRedirectActive((int) ($body['id'] ?? 0), (bool) ($body['isActive'] ?? $body['active'] ?? false));
    }

    public function deleteQrRedirect(array $body): array
    {
        return $this->adminService->deleteQrRedirect((int) ($body['id'] ?? 0));
    }

    public function resolveQrRedirect(array $query): array
    {
        return $this->adminService->resolveQrRedirect((string) ($query['slug'] ?? ''));
    }

    public function getWhatsappWorkspace(): array
    {
        return $this->adminService->getWhatsappWorkspace();
    }

    public function setWhatsappConfig(array $body): array
    {
        $config = is_array($body['config'] ?? null) ? $body['config'] : $body;
        return $this->adminService->setWhatsappConfig($config);
    }

    public function syncWhatsappTemplates(): array
    {
        return $this->adminService->syncWhatsappTemplates();
    }

    public function saveWhatsappMapping(array $body): array
    {
        $mapping = is_array($body['mapping'] ?? null) ? $body['mapping'] : $body;
        return $this->adminService->saveWhatsappMapping($mapping);
    }

    public function saveWhatsappDraft(array $body): array
    {
        $draft = is_array($body['draft'] ?? null) ? $body['draft'] : $body;
        return $this->adminService->saveWhatsappDraft($draft);
    }

    public function submitWhatsappDraft(array $body): array
    {
        return $this->adminService->submitWhatsappDraft((string) ($body['draftId'] ?? ''));
    }

    public function runWhatsappScheduler(): array
    {
        return $this->adminService->runWhatsappScheduler();
    }

    public function sendWhatsappTest(array $body): array
    {
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : $body;
        return $this->adminService->sendWhatsappTest($payload);
    }

    public function crmPanelStatus(): array
    {
        return $this->adminService->crmPanelStatus();
    }

    public function listCrmTriggerConfigs(): array
    {
        return $this->adminService->listCrmTriggerConfigs();
    }

    public function saveCrmTriggerConfig(array $body): array
    {
        return $this->adminService->saveCrmTriggerConfig($body);
    }

    public function testCrmTrigger(array $body): array
    {
        return $this->adminService->testCrmTrigger($body);
    }

    public function resetCrmTriggerToDefault(array $body): array
    {
        return $this->adminService->resetCrmTriggerToDefault($body);
    }

    public function listCrmContacts(array $payload): array
    {
        return $this->adminService->listCrmContacts($payload);
    }

    public function listCrmPushLogs(array $payload): array
    {
        return $this->adminService->listCrmPushLogs($payload);
    }

    public function backfillCrmContacts(): array
    {
        return $this->adminService->backfillCrmContacts();
    }

    public function exportCrmContacts(array $payload): array
    {
        return $this->adminService->exportCrmContacts($payload);
    }

    public function crmLeadsStatus(array $payload): array
    {
        return $this->adminService->crmLeadsStatus($payload);
    }

    public function listCrmLeads(array $payload): array
    {
        return $this->adminService->listCrmLeads($payload);
    }

    public function exportCrmLeads(array $payload): array
    {
        return $this->adminService->exportCrmLeads($payload);
    }

    public function regenerateCoupon(array $body): array
    {
        return $this->adminService->regenerateCoupon((int) ($body['leadId'] ?? 0));
    }

    public function testCrmSync(array $body): array
    {
        return $this->adminService->testCrmSync($body);
    }

    public function deleteCrmTestLead(array $body): array
    {
        return $this->adminService->deleteCrmTestLead((int) ($body['leadId'] ?? 0));
    }

    public function cashSummary(array $query, array $authUser): array
    {
        return $this->adminService->cashSummary($query, $authUser);
    }

    public function issueCashPaidPass(array $body, array $authUser): array
    {
        return $this->adminService->issueCashPaidPass($body, $authUser);
    }

    public function superadminCashDashboard(array $query): array
    {
        return $this->adminService->superadminCashDashboard($query);
    }

    public function requestCashAction(array $body, array $authUser, string $action): array
    {
        return $this->adminService->requestCashAction($body, $authUser, $action);
    }

    public function resolveCashAction(array $body, array $authUser, string $action): array
    {
        return $this->adminService->resolveCashAction($body, $authUser, $action);
    }
}
