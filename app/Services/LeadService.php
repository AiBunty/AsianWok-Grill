<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Config\Env;
use AWG\Config\Database;
use AWG\Repositories\EventRepository;
use AWG\Repositories\AppSettingRepository;
use AWG\Repositories\ContactRepository;
use AWG\Repositories\CrmPushLogRepository;
use AWG\Repositories\LeadRepository;
use AWG\Repositories\QrRedirectRepository;
use AWG\Repositories\QrRedirectSettingsRepository;
use AWG\Repositories\QrScanRepository;
use AWG\Support\Logger;
use RuntimeException;
use Throwable;

final class LeadService
{
    private const COOLDOWN_SECONDS = 86400;

    public function submitLead(array $data): array
    {
        try {
            $connection = Database::connection();
            $repository = new LeadRepository($connection);

            $name = trim((string) ($data['name'] ?? ''));
            $countryCode = $this->normalizeCountryCode((string) ($data['countryCode'] ?? '91'));
            $phoneLocal = $this->normalizePhone((string) ($data['phone'] ?? ''));
            $phone = $countryCode . $phoneLocal;
            $dateOfBirth = $this->normalizeDate((string) ($data['dateOfBirth'] ?? ''));
            $dateOfAnniversary = $this->normalizeDate((string) ($data['dateOfAnniversary'] ?? ''));
            $source = trim((string) ($data['source'] ?? 'spinwheel'));

            if ($name === '' || $this->stringLength($name) < 2) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Please enter a valid name.',
                ];
            }

            if (strlen($phoneLocal) !== 10) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Please enter a valid 10-digit phone number.',
                ];
            }

            $latestCompleted = $repository->findLatestCompletedByPhone($phone);
            if (is_array($latestCompleted) && $this->isCooldownActive($latestCompleted)) {
                $lastCompletedAt = (string) $latestCompleted['spin_completed_at'];
                $retryAfterSeconds = max(0, strtotime($lastCompletedAt) + self::COOLDOWN_SECONDS - time());
                $retryAfterEpochMs = ((time() + $retryAfterSeconds) * 1000);

                return [
                    'ok' => false,
                    'result' => 'cooldown_active',
                    'error' => 'COOLDOWN_ACTIVE',
                    'message' => 'This phone number has already completed a spin in the last 24 hours.',
                    'retryAfterSeconds' => $retryAfterSeconds,
                    'retryAfterEpochMs' => $retryAfterEpochMs,
                    'lastSpinCompletedAt' => $lastCompletedAt,
                ];
            }

            $customerIndex = $repository->countAll() + 1;
            $visitCount = $repository->countByPhone($phone) + 1;
            $spinResult = $this->pickSpinResult($customerIndex);
            $prize = $spinResult['label'];
            $couponCode = $spinResult['hasCoupon']
                ? $this->generateCouponCode($spinResult['couponPrefix'])
                : null;

            $leadId = $repository->create([
                'name' => $name,
                'phone' => $phone,
                'prize' => $prize,
                'date_of_birth' => $dateOfBirth,
                'date_of_anniversary' => $dateOfAnniversary,
                'source' => $source,
                'visit_count' => $visitCount,
                'coupon_code' => $couponCode,
                'crm_sync_status' => 'Pending',
            ]);

            $crmSync = $this->pushLeadToCrm($repository, [
                'lead_id' => $leadId,
                'name' => $name,
                'phone' => $phone,
                'country_code' => $countryCode,
                'date_of_birth' => $dateOfBirth,
                'date_of_anniversary' => $dateOfAnniversary,
                'source' => $source,
                'prize' => $prize,
                'coupon_code' => $couponCode,
            ]);

            return [
                'ok' => true,
                'result' => 'success',
                'leadId' => $leadId,
                'name' => $name,
                'phone' => $phoneLocal,
                'countryCode' => '+' . $countryCode,
                'prize' => $prize,
                'couponCode' => $couponCode,
                'visitCount' => $visitCount,
                'customerIndex' => $customerIndex,
                'milestoneTier' => $spinResult['milestoneTier'] ?? null,
                'crmSyncStatus' => $crmSync['status'],
                'crmSyncCode' => $crmSync['code'],
                'crmSyncMessage' => $crmSync['message'],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'LEAD_SUBMISSION_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function completeSpin(array $data): array
    {
        try {
            $repository = new LeadRepository(Database::connection());
            $leadId = (int) ($data['leadId'] ?? 0);
            $countryCode = $this->normalizeCountryCode((string) ($data['countryCode'] ?? '91'));
            $phoneLocal = $this->normalizePhone((string) ($data['phone'] ?? ''));
            $phone = $countryCode . $phoneLocal;

            if ($leadId <= 0 || strlen($phoneLocal) !== 10) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Lead ID and phone are required.',
                ];
            }

            $lead = $repository->findById($leadId);
            if (!is_array($lead) || (string) $lead['phone'] !== $phone) {
                return [
                    'ok' => false,
                    'error' => 'LEAD_NOT_FOUND',
                    'message' => 'Lead record was not found for this phone number.',
                ];
            }

            if (!empty($lead['spin_completed_at'])) {
                $retryAfterEpochMs = (strtotime((string) $lead['spin_completed_at']) + self::COOLDOWN_SECONDS) * 1000;
                return [
                    'ok' => true,
                    'result' => 'already_completed',
                    'leadId' => $leadId,
                    'spinCompletedAt' => $lead['spin_completed_at'],
                    'nextEligibleSpinAt' => date('Y-m-d H:i:s', strtotime((string) $lead['spin_completed_at']) + self::COOLDOWN_SECONDS),
                    'retryAfterEpochMs' => $retryAfterEpochMs,
                    'prize' => $lead['prize'],
                    'couponCode' => $lead['coupon_code'] ?: null,
                ];
            }

            $repository->markSpinCompleted($leadId);
            $completedAt = date('Y-m-d H:i:s');

            return [
                'ok' => true,
                'result' => 'spin_completed',
                'leadId' => $leadId,
                'spinCompletedAt' => $completedAt,
                'nextEligibleSpinAt' => date('Y-m-d H:i:s', time() + self::COOLDOWN_SECONDS),
                'retryAfterEpochMs' => (time() + self::COOLDOWN_SECONDS) * 1000,
                'prize' => $lead['prize'],
                'couponCode' => $lead['coupon_code'] ?: null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'SPIN_COMPLETION_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function qrRedirectResolve(array $query): array
    {
        try {
            $slug = strtolower(trim((string) ($query['slug'] ?? '')));
            $channel = strtolower(trim((string) ($query['channel'] ?? ($slug === 'admin-portal' ? 'admin' : 'customer'))));
            if (!in_array($channel, ['customer', 'admin'], true)) {
                $channel = 'customer';
            }

            $repo = new QrRedirectRepository();
            $repo->ensureSystemRows();
            $settingsRepo = new QrRedirectSettingsRepository();

            $record = null;
            $fallbackUsed = false;

            if ($slug !== '') {
                $record = $repo->findBySlug($slug);
                if (!is_array($record) || empty($record['is_active'])) {
                    $fallbackUsed = true;
                    $record = null;
                }
            }

            if (!is_array($record)) {
                $settings = $settingsRepo->getByChannel($channel);
                $resolved = $this->resolveFromSettings($channel, $settings);
                return [
                    'ok' => true,
                    'action' => 'qr_redirect_resolve',
                    'qrId' => null,
                    'qrSlug' => $slug,
                    'qrName' => $channel === 'admin' ? 'Admin QR' : 'Guest QR',
                    'destinationMode' => (string) ($resolved['destinationMode'] ?? 'preset'),
                    'destinationKey' => (string) ($resolved['destinationKey'] ?? ($channel === 'admin' ? 'admin' : 'menu')),
                    'destinationLabel' => (string) ($resolved['destinationLabel'] ?? ''),
                    'resolvedUrl' => (string) ($resolved['resolvedUrl'] ?? ($channel === 'admin' ? '/admin/admin-portal.html' : '/menu.html')),
                    'manualUrl' => (string) ($resolved['manualUrl'] ?? ''),
                    'fallbackUsed' => true,
                    'source' => 'channel_settings',
                ];
            }

            $destinationMode = strtolower((string) ($record['redirect_mode'] ?? 'preset'));
            $destinationKey = trim((string) ($record['preset_key'] ?? ''));
            $manualUrl = trim((string) ($record['manual_url'] ?? ''));

            $resolved = $this->resolveDestination($destinationMode, $destinationKey, $manualUrl, $channel);
            if (!empty($resolved['fallbackUsed'])) {
                $fallbackUsed = true;
            }

            return [
                'ok' => true,
                'action' => 'qr_redirect_resolve',
                'qrId' => (int) ($record['id'] ?? 0),
                'qrSlug' => (string) ($record['slug'] ?? ''),
                'qrName' => (string) ($record['name'] ?? $record['slug'] ?? ''),
                'destinationMode' => (string) ($resolved['destinationMode'] ?? $destinationMode),
                'destinationKey' => (string) ($resolved['destinationKey'] ?? $destinationKey),
                'destinationLabel' => (string) ($resolved['destinationLabel'] ?? ''),
                'resolvedUrl' => (string) ($resolved['resolvedUrl'] ?? ''),
                'manualUrl' => (string) ($resolved['manualUrl'] ?? ''),
                'fallbackUsed' => $fallbackUsed,
                'source' => 'slug_registry',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'QR_REDIRECT_RESOLVE_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function qrScanClient(array $body): array
    {
        try {
            $channel = strtolower(trim((string) ($body['channel'] ?? 'customer')));
            if (!in_array($channel, ['customer', 'admin'], true)) {
                $channel = 'customer';
            }

            $scanRepo = new QrScanRepository();
            $scanNumber = $scanRepo->nextScanNumber($channel);
            $scanRepo->create([
                'scan_number' => $scanNumber,
                'channel' => $channel,
                'qr_id' => ($body['qrId'] ?? $body['qr_id'] ?? null),
                'qr_slug' => (string) ($body['qrSlug'] ?? $body['qr_slug'] ?? ''),
                'destination_key' => (string) ($body['destinationKey'] ?? $body['destination_key'] ?? ''),
                'destination_label' => (string) ($body['destinationLabel'] ?? $body['destination_label'] ?? ''),
                'resolved_url' => (string) ($body['resolvedUrl'] ?? $body['resolved_url'] ?? ''),
                'user_agent' => (string) ($body['userAgent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? ''),
                'referer' => (string) ($body['referer'] ?? $_SERVER['HTTP_REFERER'] ?? ''),
                'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'city' => (string) ($body['city'] ?? ''),
                'region' => (string) ($body['region'] ?? ''),
                'country' => (string) ($body['country'] ?? ''),
                'device' => (string) ($body['device'] ?? ''),
                'browser' => (string) ($body['browser'] ?? ''),
                'os' => (string) ($body['os'] ?? ''),
                'language' => (string) ($body['language'] ?? ''),
                'screen' => (string) ($body['screen'] ?? ''),
            ]);

            return [
                'ok' => true,
                'action' => 'qr_scan_client',
                'scanNumber' => $scanNumber,
                'channel' => $channel,
                'qrSlug' => (string) ($body['qrSlug'] ?? $body['qr_slug'] ?? ''),
                'emailTriggerInterval' => 100,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'QR_SCAN_CLIENT_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    public function qrReport(bool $includeRows = false): array
    {
        try {
            $report = (new QrScanRepository())->report(100);
            if (!$includeRows) {
                unset($report['rows']);
            }
            return [
                'ok' => true,
                'action' => 'qr_report',
                'totalScans' => (int) ($report['totalScans'] ?? 0),
                'channelCounts' => $report['channelCounts'] ?? [],
                'qrSummary' => $report['qrSummary'] ?? [],
                'recentScans' => $report['recentScans'] ?? [],
                'source' => 'qr_scans',
                'rows' => $report['rows'] ?? null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'QR_REPORT_FAILED',
                'message' => $this->safeMessage($exception),
            ];
        }
    }

    private function resolveFromSettings(string $channel, ?array $settings): array
    {
        $mode = strtolower((string) ($settings['destination_mode'] ?? 'preset'));
        $key = (string) ($settings['destination_key'] ?? ($channel === 'admin' ? 'admin' : 'menu'));
        $manualUrl = (string) ($settings['manual_url'] ?? '');
        return $this->resolveDestination($mode, $key, $manualUrl, $channel, true);
    }

    private function resolveDestination(string $mode, string $key, string $manualUrl, string $channel, bool $forceFallback = false): array
    {
        $fallbackPreset = $channel === 'admin' ? 'admin' : 'menu';
        $fallbackCatalog = $this->presetCatalog();

        if ($mode === 'manual' && !$forceFallback) {
            if ($this->isValidRecordManualUrl($manualUrl)) {
                return [
                    'destinationMode' => 'manual',
                    'destinationKey' => 'manual',
                    'destinationLabel' => 'Manual URL',
                    'resolvedUrl' => $manualUrl,
                    'manualUrl' => $manualUrl,
                    'fallbackUsed' => false,
                ];
            }
        }

        $catalog = $this->presetCatalog();
        if (isset($catalog[$key]) && !$forceFallback) {
            return [
                'destinationMode' => 'preset',
                'destinationKey' => $key,
                'destinationLabel' => (string) ($catalog[$key]['label'] ?? $key),
                'resolvedUrl' => (string) ($catalog[$key]['url'] ?? '/menu.html'),
                'manualUrl' => $manualUrl,
                'fallbackUsed' => false,
            ];
        }

        return [
            'destinationMode' => 'preset',
            'destinationKey' => $fallbackPreset,
            'destinationLabel' => (string) ($fallbackCatalog[$fallbackPreset]['label'] ?? $fallbackPreset),
            'resolvedUrl' => (string) ($fallbackCatalog[$fallbackPreset]['url'] ?? ($channel === 'admin' ? '/admin/admin-portal.html' : '/menu.html')),
            'manualUrl' => $manualUrl,
            'fallbackUsed' => true,
        ];
    }

    private function presetCatalog(): array
    {
        $catalog = [
            'home' => ['label' => 'Home', 'url' => '/home.html'],
            'menu' => ['label' => 'Food Menu', 'url' => '/menu.html'],
            'cocktail' => ['label' => 'Cocktail Page', 'url' => '/cocktail.html'],
            'admin' => ['label' => 'Admin Portal', 'url' => '/admin/admin-portal.html'],
            'events' => ['label' => 'Events Listing', 'url' => '/events.html'],
            'scanner' => ['label' => 'QR Scanner', 'url' => '/qr.html'],
            'scanner:landing' => ['label' => 'QR Middle Layer', 'url' => '/qr/scan.html'],
        ];

        foreach ($this->discoverPublicPresetPages() as $key => $row) {
            if (!isset($catalog[$key])) {
                $catalog[$key] = $row;
            }
        }

        $events = [];
        try {
            $events = (new EventRepository())->getActive();
        } catch (Throwable $exception) {
            $events = [];
        }
        $today = date('Y-m-d');
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $eventId = trim((string) ($event['event_id'] ?? $event['id'] ?? ''));
            if ($eventId === '') {
                continue;
            }
            $endDate = (string) ($event['end_date'] ?? $event['start_date'] ?? $today);
            if ($endDate < $today) {
                continue;
            }

            $key = 'event:' . $eventId;
            $catalog[$key] = [
                'label' => 'Event: ' . (string) ($event['title'] ?? $eventId),
                'url' => '/events.html?eventId=' . rawurlencode($eventId),
            ];
        }

        return $catalog;
    }

    private function discoverPublicPresetPages(): array
    {
        $publicRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'asianwokandgrill.in';
        if (!is_dir($publicRoot)) {
            return [];
        }

        $directories = ['', 'admin', 'qr'];
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
                if ($entry === '.' || $entry === '..' || substr($entry, -5) !== '.html') {
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

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    private function normalizeCountryCode(string $countryCode): string
    {
        $digits = preg_replace('/\D+/', '', $countryCode) ?? '';
        return $digits !== '' ? $digits : '91';
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private function isCooldownActive(array $lead): bool
    {
        $completedAt = (string) ($lead['spin_completed_at'] ?? '');
        if ($completedAt === '' || strtotime($completedAt) === false) {
            return false;
        }

        return strtotime($completedAt) + self::COOLDOWN_SECONDS > time();
    }

    private function pickSpinResult(int $customerIndex): array
    {
        $milestoneResult = $this->pickMilestoneResult($customerIndex);
        if (is_array($milestoneResult)) {
            return $milestoneResult;
        }

        return $this->pickWeightedSpinResult();
    }

    private function pickMilestoneResult(int $customerIndex): ?array
    {
        if ($customerIndex <= 0) {
            return null;
        }

        $scheme = $this->loadMilestoneScheme();
        if (!is_array($scheme) || empty($scheme['enabled'])) {
            return null;
        }

        $tiers = is_array($scheme['tiers'] ?? null) ? $scheme['tiers'] : [];
        if ($tiers === []) {
            return null;
        }

        usort($tiers, static fn (array $a, array $b): int => ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0)));

        foreach ($tiers as $tier) {
            $interval = (int) ($tier['interval'] ?? 0);
            $label = trim((string) ($tier['label'] ?? ''));
            if ($interval <= 0 || $label === '' || $customerIndex % $interval !== 0) {
                continue;
            }

            $variant = strtolower(trim((string) ($tier['variant'] ?? 'any')));
            if ($variant === 'odd' && $customerIndex % 2 === 0) {
                continue;
            }
            if ($variant === 'even' && $customerIndex % 2 !== 0) {
                continue;
            }

            return [
                'label' => $label,
                'hasCoupon' => !empty($tier['hasCoupon']),
                'couponPrefix' => trim((string) ($tier['couponPrefix'] ?? 'AWG')) ?: 'AWG',
                'milestoneTier' => [
                    'interval' => $interval,
                    'variant' => $variant,
                    'priority' => (int) ($tier['priority'] ?? $interval),
                ],
            ];
        }

        return [
            'label' => 'Try Again',
            'hasCoupon' => false,
            'couponPrefix' => 'AWG',
            'milestoneTier' => null,
        ];
    }

    private function pickWeightedSpinResult(): array
    {
        $offers = $this->loadSpinOffers();
        $scaledOffers = [];
        $totalTickets = 0;

        foreach ($offers as $offer) {
            $weight = (float) ($offer['weight'] ?? 0);
            $tickets = (int) round($weight * 100);
            if ($tickets <= 0) {
                continue;
            }

            $scaledOffers[] = [
                'offer' => $offer,
                'tickets' => $tickets,
            ];
            $totalTickets += $tickets;
        }

        if ($totalTickets <= 0 || $scaledOffers === []) {
            return [
                'label' => 'Try Again',
                'hasCoupon' => false,
                'couponPrefix' => 'AWG',
                'milestoneTier' => null,
            ];
        }

        $threshold = random_int(1, $totalTickets);
        $running = 0;

        foreach ($scaledOffers as $row) {
            $running += (int) ($row['tickets'] ?? 0);
            if ($threshold <= $running) {
                $offer = is_array($row['offer'] ?? null) ? $row['offer'] : [];
                return [
                    'label' => (string) ($offer['label'] ?? 'Try Again'),
                    'hasCoupon' => !empty($offer['hasCoupon']),
                    'couponPrefix' => trim((string) ($offer['couponPrefix'] ?? 'AWG')) ?: 'AWG',
                    'milestoneTier' => null,
                ];
            }
        }

        $lastRow = $scaledOffers[count($scaledOffers) - 1] ?? [];
        $last = is_array($lastRow['offer'] ?? null) ? $lastRow['offer'] : [];
        return [
            'label' => (string) ($last['label'] ?? 'Try Again'),
            'hasCoupon' => !empty($last['hasCoupon']),
            'couponPrefix' => trim((string) ($last['couponPrefix'] ?? 'AWG')) ?: 'AWG',
            'milestoneTier' => null,
        ];
    }

    private function loadMilestoneScheme(): array
    {
        try {
            $repo = new AppSettingRepository(Database::connection());
            $raw = $repo->getValue('app', 'spinMilestoneScheme');
            $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;

            $enabled = true;
            $tiersRaw = [];
            if (is_array($decoded)) {
                $enabled = !array_key_exists('enabled', $decoded) || !empty($decoded['enabled']);
                $tiersRaw = is_array($decoded['tiers'] ?? null) ? $decoded['tiers'] : [];
            }

            $tiers = [];
            foreach ($tiersRaw as $row) {
                $tier = $this->normalizeMilestoneTier($row);
                if (is_array($tier)) {
                    $tiers[] = $tier;
                }
            }

            if ($tiers === []) {
                $tiers = $this->defaultMilestoneTiers();
            }

            return [
                'enabled' => $enabled,
                'tiers' => $tiers,
            ];
        } catch (Throwable $exception) {
            return [
                'enabled' => true,
                'tiers' => $this->defaultMilestoneTiers(),
            ];
        }
    }

    private function normalizeMilestoneTier($row): ?array
    {
        if (!is_array($row)) {
            return null;
        }

        $interval = (int) ($row['interval'] ?? 0);
        $label = trim((string) ($row['label'] ?? ''));
        if ($interval <= 0 || $label === '') {
            return null;
        }

        return [
            'interval' => $interval,
            'label' => $label,
            'hasCoupon' => !empty($row['hasCoupon']),
            'couponPrefix' => trim((string) ($row['couponPrefix'] ?? 'AWG')) ?: 'AWG',
            'variant' => strtolower(trim((string) ($row['variant'] ?? 'any'))),
            'priority' => (int) ($row['priority'] ?? $interval),
        ];
    }

    private function defaultMilestoneTiers(): array
    {
        return [
            ['interval' => 100, 'label' => '5 FREE LUNCH BUFFET', 'hasCoupon' => true, 'couponPrefix' => 'LUNCH', 'variant' => 'any', 'priority' => 100],
            ['interval' => 75, 'label' => '2 FREE DINNER BUFFET', 'hasCoupon' => true, 'couponPrefix' => 'DINNER', 'variant' => 'any', 'priority' => 75],
            ['interval' => 50, 'label' => '2 FREE LUNCH BUFFET', 'hasCoupon' => true, 'couponPrefix' => 'LUNCH', 'variant' => 'any', 'priority' => 50],
            ['interval' => 25, 'label' => '1 FREE APPETIZER', 'hasCoupon' => true, 'couponPrefix' => 'APP', 'variant' => 'any', 'priority' => 25],
            ['interval' => 19, 'label' => '1 MOCKTAIL FREE', 'hasCoupon' => true, 'couponPrefix' => 'MOCK', 'variant' => 'odd', 'priority' => 19],
            ['interval' => 19, 'label' => '10% OFF UPTO 100', 'hasCoupon' => true, 'couponPrefix' => 'DISC', 'variant' => 'even', 'priority' => 19],
        ];
    }

    private function loadSpinOffers(): array
    {
        try {
            $repo = new AppSettingRepository(Database::connection());
            $raw = $repo->getValue('spin_wheel', 'offers');
            $offers = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
            if (!is_array($offers)) {
                return $this->defaultSpinOffers();
            }

            $active = [];
            foreach ($offers as $row) {
                if (!is_array($row) || empty($row['isActive'])) {
                    continue;
                }

                $label = trim((string) ($row['label'] ?? ''));
                $weight = (float) ($row['weight'] ?? 0);
                if ($label === '' || $weight <= 0) {
                    continue;
                }

                $active[] = [
                    'label' => $label,
                    'weight' => $weight,
                    'hasCoupon' => !empty($row['hasCoupon']),
                    'couponPrefix' => (string) ($row['couponPrefix'] ?? 'AWG'),
                ];
            }

            return $active === [] ? $this->defaultSpinOffers() : $active;
        } catch (Throwable $exception) {
            return $this->defaultSpinOffers();
        }
    }

    private function defaultSpinOffers(): array
    {
        return [
            [
                'label' => 'Try Again',
                'weight' => 40,
                'hasCoupon' => false,
                'couponPrefix' => 'AWG',
            ],
            [
                'label' => 'Free Mocktail',
                'weight' => 24,
                'hasCoupon' => true,
                'couponPrefix' => 'MOCK',
            ],
            [
                'label' => '10% Off Main Course',
                'weight' => 21,
                'hasCoupon' => true,
                'couponPrefix' => 'MAIN',
            ],
            [
                'label' => 'Dessert Shot',
                'weight' => 15,
                'hasCoupon' => true,
                'couponPrefix' => 'SWEET',
            ],
        ];
    }

    private function generateCouponCode(string $prefix): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($index = 0; $index < 6; $index++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return strtoupper($prefix . '-' . $code);
    }

    private function pushLeadToCrm(LeadRepository $repository, array $lead): array
    {
        return (new CrmTriggerService())->pushLeadTriggers($repository, $lead);
    }

    private function sendCrmRequest(string $endpoint, array $payload): array
    {
        $queryPayload = array_filter($payload, static fn ($value) => $value !== null && $value !== '');
        $attempts = [];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $result = $this->performCrmRequest($endpoint, $queryPayload);
                $attempts[] = [
                    'attempt' => $attempt,
                    'time' => date('c'),
                    'httpCode' => $result['httpCode'] ?? null,
                    'status' => $result['status'] ?? null,
                    'code' => $result['code'] ?? null,
                    'message' => $result['message'] ?? null,
                ];

                $result['attempt_count'] = $attempt;
                $result['retry_count'] = max(0, $attempt - 1);
                $result['attempts'] = $attempts;

                if (!empty($result['success']) || $attempt === 2) {
                    return $result;
                }
            } catch (Throwable $exception) {
                $attempts[] = [
                    'attempt' => $attempt,
                    'time' => date('c'),
                    'httpCode' => null,
                    'status' => 'Failed',
                    'code' => 'CRM_REQUEST_FAILED',
                    'message' => $exception->getMessage(),
                ];

                if ($attempt === 2) {
                    Logger::error('CRM push failed', [
                        'endpoint' => $endpoint,
                        'message' => $exception->getMessage(),
                        'leadPhone' => $payload['contact_phone'] ?? null,
                    ]);

                    return [
                        'status' => 'Failed',
                        'code' => 'CRM_REQUEST_FAILED',
                        'message' => $exception->getMessage(),
                        'httpCode' => null,
                        'success' => false,
                        'payload' => $queryPayload,
                        'rawBody' => null,
                        'attempt_count' => $attempt,
                        'retry_count' => max(0, $attempt - 1),
                        'attempts' => $attempts,
                    ];
                }
            }
        }

        return [
            'status' => 'Failed',
            'code' => 'CRM_REQUEST_FAILED',
            'message' => 'CRM request failed.',
            'httpCode' => null,
            'success' => false,
            'payload' => $queryPayload,
            'rawBody' => null,
            'attempt_count' => 1,
            'retry_count' => 0,
            'attempts' => $attempts,
        ];
    }

    private function performCrmRequest(string $endpoint, array $queryPayload): array
    {
        $requestUrl = $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($queryPayload);

        if (function_exists('curl_init')) {
            $ch = curl_init($requestUrl);
            if ($ch === false) {
                throw new RuntimeException('Unable to initialize CRM request.');
            }

            $headers = ['Accept: application/json'];
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPGET => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $caBundle = $this->resolveCaBundlePath();
            if ($caBundle !== null) {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            }

            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false || $error !== '') {
                throw new RuntimeException($error !== '' ? $error : 'CRM request failed.');
            }

            return $this->normalizeCrmResponse($httpCode, (string) $body, $queryPayload);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Accept: application/json\r\n",
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($requestUrl, false, $context);
        if ($body === false) {
            throw new RuntimeException('CRM request failed.');
        }

        $httpCode = $this->extractHttpCode($http_response_header ?? []);
        return $this->normalizeCrmResponse($httpCode, $body, $queryPayload);
    }

    private function normalizeCrmResponse(int $httpCode, string $body, array $payload): array
    {
        $decoded = json_decode($body, true);
        $message = null;
        $code = null;
        $success = $httpCode >= 200 && $httpCode < 300;

        if (is_array($decoded)) {
            $message = isset($decoded['message']) ? (string) $decoded['message'] : null;
            $code = isset($decoded['code']) ? (string) $decoded['code'] : null;
            if (isset($decoded['success'])) {
                $success = (bool) $decoded['success'];
            } elseif (isset($decoded['ok'])) {
                $success = (bool) $decoded['ok'];
            }
        }

        return [
            'status' => $success ? 'Success' : 'Failed',
            'code' => $code ?: ($success ? 'CRM_PUSHED' : 'CRM_HTTP_' . $httpCode),
            'message' => $message ?: ($success ? 'Lead pushed to CRM.' : 'CRM request failed.'),
            'httpCode' => $httpCode,
            'success' => $success,
            'payload' => $payload,
            'rawBody' => $body,
        ];
    }

    private function persistCrmResult(LeadRepository $repository, array $lead, array $result): void
    {
        $leadId = (int) $lead['lead_id'];
        $status = (string) $result['status'];
        $code = isset($result['code']) ? (string) $result['code'] : null;
        $message = isset($result['message']) ? (string) $result['message'] : null;
        $pushedAt = !empty($result['success']) ? date('Y-m-d H:i:s') : null;

        try {
            $repository->updateCrmSyncStatus($leadId, $status, $code, $message);
            $contactRepository = new ContactRepository(Database::connection());
            $logRepository = new CrmPushLogRepository(Database::connection());
            $summary = $repository->findLatestSummaryByPhone((string) $lead['phone']) ?: [];
            $attemptedAt = $status === 'Skipped' ? null : date('Y-m-d H:i:s');

            $contactId = $contactRepository->upsert([
                'phone' => $lead['phone'],
                'name' => $lead['name'],
                'date_of_birth' => $lead['date_of_birth'],
                'date_of_anniversary' => $lead['date_of_anniversary'],
                'latest_source' => $lead['source'],
                'latest_lead_id' => $leadId,
                'latest_lead_created_at' => $summary['created_at'] ?? date('Y-m-d H:i:s'),
                'first_seen_at' => $summary['first_seen_at'] ?? ($summary['created_at'] ?? date('Y-m-d H:i:s')),
                'last_seen_at' => $summary['last_seen_at'] ?? ($summary['created_at'] ?? date('Y-m-d H:i:s')),
                'total_submissions' => (int) ($summary['total_submissions'] ?? 1),
                'latest_crm_sync_status' => $status,
                'latest_crm_sync_code' => $code,
                'latest_crm_sync_message' => $message,
                'last_crm_attempted_at' => $attemptedAt,
                'last_crm_pushed_at' => $pushedAt,
            ]);
            $safePayload = is_array($result['payload'] ?? null) ? $result['payload'] : null;
            if (is_array($safePayload)) {
                unset($safePayload['api_token'], $safePayload['token'], $safePayload['auth_token']);
            }

            $logRepository->create([
                'contact_id' => $contactId ?: null,
                'lead_id' => $leadId,
                'phone' => $lead['phone'],
                'contact_name' => $lead['name'],
                'trigger_source' => 'spin_wheel_submit',
                'crm_endpoint' => Env::getProfiled('CRM_API_ENDPOINT', ''),
                'attempted' => $status !== 'Skipped',
                'success' => !empty($result['success']),
                'http_code' => $result['httpCode'] ?? null,
                'retry_count' => (int) ($result['retry_count'] ?? 0),
                'attempt_count' => (int) ($result['attempt_count'] ?? ($status === 'Skipped' ? 0 : 1)),
                'response_message' => $message,
                'request_payload_json' => json_encode($safePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'attempts_json' => json_encode($result['attempts'] ?? [[
                    'time' => date('c'),
                    'httpCode' => $result['httpCode'] ?? null,
                    'status' => $status,
                    'code' => $code,
                    'message' => $message,
                ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $exception) {
            Logger::error('CRM result persistence failed', [
                'leadId' => $leadId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveCaBundlePath(): ?string
    {
        $configured = trim((string) Env::getProfiled('CRM_CA_BUNDLE_PATH', ''));
        if ($configured === '') {
            return null;
        }

        $absolutePath = dirname(__DIR__, 2) . '/' . ltrim(str_replace('\\', '/', $configured), '/');
        return is_file($absolutePath) ? $absolutePath : null;
    }

    private function extractHttpCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string) $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value);
        }

        return strlen($value);
    }

    private function safeMessage(Throwable $exception): string
    {
        if ($exception instanceof RuntimeException) {
            return $exception->getMessage();
        }

        return 'Lead service is unavailable.';
    }

    // ============================================================
    // Menu Blocker / Spin Wheel Actions
    // ============================================================

    public function qr_spin_wheel_get_prize(array $body): array
    {
        try {
            $connection = Database::connection();
            $service = new MenuBlockerService($connection);
            $leadRepository = new LeadRepository($connection);

            $phone = trim((string) ($body['phone'] ?? ''));
            $countryCode = $this->normalizeCountryCode((string) ($body['country_code'] ?? ''));
            $name = trim((string) ($body['name'] ?? ''));
            $dateOfBirth = $this->normalizeDate((string) ($body['date_of_birth'] ?? ''));
            $dateOfAnniversary = $this->normalizeDate((string) ($body['date_of_anniversary'] ?? ''));
            $source = trim((string) ($body['source'] ?? 'spinwheel'));

            if (empty($phone) || empty($countryCode)) {
                return [
                    'ok' => false,
                    'error' => 'INVALID_INPUT',
                    'message' => 'Phone and country code are required.',
                ];
            }

            $result = $service->generatePrize($phone, $countryCode);

            $normalizedPhone = $this->normalizePhone($phone);
            $leadPhone = $normalizedPhone !== '' ? ($countryCode . $normalizedPhone) : '';
            $resolvedName = $name !== '' ? $name : 'Guest';
            $outcome = is_array($result['outcome'] ?? null) ? $result['outcome'] : [];
            $prizeText = trim((string) ($outcome['prizeText'] ?? ''));
            $couponCode = trim((string) ($outcome['couponCode'] ?? ''));
            $isTryAgain = stripos($prizeText, 'try again') !== false;

            if (!empty($result['success']) && $leadPhone !== '') {
                $visitCount = $leadRepository->countByPhone($leadPhone) + 1;
                $leadId = $leadRepository->create([
                    'name' => $resolvedName,
                    'phone' => $leadPhone,
                    'prize' => $prizeText,
                    'date_of_birth' => $dateOfBirth,
                    'date_of_anniversary' => $dateOfAnniversary,
                    'source' => $source !== '' ? $source : 'spinwheel',
                    'visit_count' => max(1, $visitCount),
                    'coupon_code' => $couponCode !== '' ? $couponCode : null,
                    'crm_sync_status' => 'Pending',
                ]);

                // This action already returns a final spin result, so persist completion now.
                $leadRepository->markSpinCompleted($leadId);

                $this->pushLeadToCrm($leadRepository, [
                    'lead_id' => $leadId,
                    'name' => $resolvedName,
                    'phone' => $leadPhone,
                    'date_of_birth' => $dateOfBirth,
                    'date_of_anniversary' => $dateOfAnniversary,
                    'source' => $source !== '' ? $source : 'spinwheel',
                    'prize' => $prizeText,
                    'coupon_code' => $couponCode,
                ]);
            }

            return [
                'ok' => $result['success'] ?? true,
                'action' => 'qr_spin_wheel_get_prize',
                'cooledDown' => $result['cooledDown'] ?? false,
                'message' => $result['message'] ?? null,
                'outcome' => $result['outcome'] ?? null,
                'success' => $result['success'] ?? false,
            ];
        } catch (Throwable $ex) {
            Logger::error('spin_wheel_error', ['error' => $ex->getMessage()]);
            return [
                'ok' => false,
                'error' => 'SPIN_WHEEL_ERROR',
                'message' => 'Failed to generate prize. Please try again later.',
            ];
        }
    }

    public function syncCrmByPhone(array $body): array
    {
        try {
            $phone = $this->normalizePhone((string) ($body['phone'] ?? ''));
            if ($phone === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Phone is required.',
                ];
            }

            $repository = new LeadRepository(Database::connection());
            $lead = $repository->findLatestByPhone($phone) ?: $repository->findLatestByPhone('91' . $phone);
            if (!is_array($lead)) {
                return [
                    'ok' => false,
                    'error' => 'LEAD_NOT_FOUND',
                    'message' => 'No lead was found for this phone number.',
                ];
            }

            $sync = $this->pushLeadToCrm($repository, [
                'lead_id' => (int) $lead['id'],
                'name' => (string) ($lead['name'] ?? ''),
                'phone' => (string) ($lead['phone'] ?? ''),
                'date_of_birth' => $lead['date_of_birth'] ?? null,
                'date_of_anniversary' => $lead['date_of_anniversary'] ?? null,
                'source' => (string) ($lead['source'] ?? 'manual_crm_sync'),
                'prize' => (string) ($lead['prize'] ?? ''),
                'coupon_code' => (string) ($lead['coupon_code'] ?? ''),
            ]);

            $stored = $repository->findById((int) $lead['id']);
            $contact = (new ContactRepository(Database::connection()))->findByPhone((string) ($lead['phone'] ?? ''));

            return [
                'ok' => true,
                'message' => 'CRM sync completed.',
                'lead' => $stored,
                'contact' => $contact,
                'crmSync' => $this->publicCrmResult($sync),
            ];
        } catch (Throwable $ex) {
            Logger::error('sync_crm_by_phone_error', ['error' => $ex->getMessage()]);
            return [
                'ok' => false,
                'error' => 'CRM_SYNC_BY_PHONE_FAILED',
                'message' => 'Failed to sync CRM by phone.',
            ];
        }
    }

    private function publicCrmResult(array $result): array
    {
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : null;
        if (is_array($payload)) {
            unset($payload['api_token'], $payload['token'], $payload['auth_token']);
        }

        return [
            'status' => (string) ($result['status'] ?? ''),
            'code' => $result['code'] ?? null,
            'message' => $result['message'] ?? null,
            'httpCode' => $result['httpCode'] ?? null,
            'success' => !empty($result['success']),
            'attemptCount' => (int) ($result['attempt_count'] ?? 0),
            'retryCount' => (int) ($result['retry_count'] ?? 0),
            'payloadPreview' => $payload,
            'attempts' => is_array($result['attempts'] ?? null) ? $result['attempts'] : [],
        ];
    }
}
