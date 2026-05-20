<?php

declare(strict_types=1);

namespace AWG\Controllers;

use AWG\Services\LeadService;

final class LeadController
{
    public function __construct(private readonly LeadService $leadService)
    {
    }

    public function submitLead(array $body): array
    {
        return $this->leadService->submitLead($body);
    }

    public function completeSpin(array $body): array
    {
        return $this->leadService->completeSpin($body);
    }

    public function qrScanClient(array $body): array
    {
        return $this->leadService->qrScanClient($body);
    }

    public function qrRedirectResolve(array $query): array
    {
        return $this->leadService->qrRedirectResolve($query);
    }

    public function qrReport(bool $includeRows): array
    {
        return $this->leadService->qrReport($includeRows);
    }

    public function qrSpinWheelGetPrize(array $body): array
    {
        return $this->leadService->qr_spin_wheel_get_prize($body);
    }

    public function getMenuBlockerSettings(): array
    {
        try {
            $service = new \AWG\Services\MenuBlockerService(\AWG\Config\Database::connection());
            return [
                'ok' => true,
                'success' => true,
                'settings' => $service->getSettings(),
            ];
        } catch (\Throwable $ex) {
            return [
                'ok' => false,
                'error' => 'SETTINGS_UNAVAILABLE',
                'message' => 'Settings temporarily unavailable.',
            ];
        }
    }

    public function syncCrmByPhone(array $body): array
    {
        return $this->leadService->syncCrmByPhone($body);
    }
}
