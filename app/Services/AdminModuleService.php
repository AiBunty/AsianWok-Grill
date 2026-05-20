<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Config\Database;
use AWG\Config\Env;
use AWG\Repositories\AppSettingRepository;
use AWG\Repositories\ContactRepository;
use AWG\Repositories\CrmPushLogRepository;
use AWG\Repositories\EventRepository;
use AWG\Repositories\LeadRepository;
use AWG\Repositories\MenuRepository;
use DateTimeImmutable;
use PDO;
use Throwable;

final class AdminModuleService
{
    private const EVENTS_GROUP = 'events';
    private const EVENTS_KEY = 'items';
    private const WHATSAPP_GROUP = 'whatsapp';
    private const WHATSAPP_TEMPLATES_KEY = 'templates';
    private const WHATSAPP_MAPPINGS_KEY = 'mappings';
    private const WHATSAPP_DRAFTS_KEY = 'drafts';
    private const WHATSAPP_LOGS_KEY = 'logs';
    private const WHATSAPP_SCHEDULES_KEY = 'schedules';
    private const QR_SETTINGS_GROUP = 'qr_redirect';
    private const QR_SETTINGS_KEY = 'settings';
    private const RESERVED_QR_REDIRECT_SLUGS = ['guest-login', 'admin-login'];
    private const CASH_GROUP = 'cash';
    private const CASH_REQUESTS_KEY = 'requests';
    private const CASH_TRANSACTIONS_KEY = 'transactions';
    private const CASH_HANDOVERS_KEY = 'handover_requests';
    private const CASH_CANCEL_REQUESTS_KEY = 'cancel_requests';
    private const SPIN_GROUP = 'spin_wheel';
    private const SPIN_OFFERS_KEY = 'offers';
    private const BLOCKER_GROUP = 'app';
    private const BLOCKER_PAGES_KEY = 'menuBlockerPages';
    private const BLOCKER_CONFIG_KEY = 'spinBlockerConfig';
    private const BLOCKER_CONTENT_KEY = 'spinBlockerContent';
    private const SPIN_MILESTONE_SCHEME_KEY = 'spinMilestoneScheme';

    public function dashboardSummary(): array
    {
        try {
            $leadRepository = new LeadRepository(Database::connection());
            $totalLeads = $leadRepository->countAll();
            $redeemed = $leadRepository->countRedeemed();
            $recentLeads = $leadRepository->listRecent(20);

            return [
                'ok' => true,
                'summary' => [
                    'totalLeads' => $totalLeads,
                    'redeemedLeads' => $redeemed,
                    'unredeemedLeads' => max(0, $totalLeads - $redeemed),
                ],
                'recentLeads' => array_map([$this, 'mapLeadForAdmin'], $recentLeads),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'ADMIN_DASHBOARD_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function verifyPhone(string $phone): array
    {
        try {
            $normalizedPhone = $this->normalizePhone($phone);
            if (strlen($normalizedPhone) !== 10) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Enter a valid 10-digit phone number.',
                ];
            }

            $repository = new LeadRepository(Database::connection());
            $lead = $repository->findLatestByPhone('91' . $normalizedPhone)
                ?? $repository->findLatestByPhone($normalizedPhone);

            if (!is_array($lead)) {
                return [
                    'ok' => false,
                    'error' => 'LEAD_NOT_FOUND',
                    'message' => 'No lead found for this phone number.',
                ];
            }

            $activeReward = $this->activeRewardLabel($lead);
            $activeCoupon = $this->activeCouponCode($lead);
            $canIssueSurprise = strtoupper((string) ($lead['prize'] ?? '')) === 'TRY AGAIN' && $activeCoupon === null;

            return [
                'ok' => true,
                'lead' => [
                    'id' => (int) ($lead['id'] ?? 0),
                    'name' => (string) ($lead['name'] ?? ''),
                    'phone' => $this->tailPhone((string) ($lead['phone'] ?? '')),
                    'originalPrize' => (string) ($lead['prize'] ?? ''),
                    'activeRewardLabel' => $activeReward,
                    'couponCode' => $activeCoupon,
                    'status' => (string) ($lead['status'] ?? ''),
                    'source' => (string) ($lead['source'] ?? ''),
                    'createdAt' => (string) ($lead['created_at'] ?? ''),
                    'canRedeem' => ($lead['status'] ?? '') !== 'Redeemed' && $activeCoupon !== null,
                    'canIssueSurprise' => $canIssueSurprise,
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'VERIFY_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function redeemCoupon(int $leadId): array
    {
        try {
            if ($leadId <= 0) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Lead id is required.',
                ];
            }

            $repository = new LeadRepository(Database::connection());
            $lead = $repository->findById($leadId);
            if (!is_array($lead)) {
                return [
                    'ok' => false,
                    'error' => 'LEAD_NOT_FOUND',
                    'message' => 'Lead not found.',
                ];
            }

            $activeCoupon = $this->activeCouponCode($lead);
            if ($activeCoupon === null) {
                return [
                    'ok' => false,
                    'error' => 'NO_ACTIVE_COUPON',
                    'message' => 'No active coupon available for this lead.',
                ];
            }

            $repository->markRedeemed($leadId);

            return [
                'ok' => true,
                'message' => 'Coupon redeemed successfully.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'REDEEM_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function issueSurpriseCoupon(int $leadId, string $rewardLabel, int $issuedByUserId): array
    {
        try {
            $rewardLabel = trim($rewardLabel);
            if ($leadId <= 0 || $rewardLabel === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Lead id and reward label are required.',
                ];
            }

            $repository = new LeadRepository(Database::connection());
            $lead = $repository->findById($leadId);
            if (!is_array($lead)) {
                return [
                    'ok' => false,
                    'error' => 'LEAD_NOT_FOUND',
                    'message' => 'Lead not found.',
                ];
            }

            $couponCode = $this->generateCouponCode('AWG');
            $repository->issueSurpriseReward($leadId, $rewardLabel, $couponCode, $issuedByUserId);

            return [
                'ok' => true,
                'message' => 'Surprise coupon issued successfully.',
                'couponCode' => $couponCode,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'SURPRISE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function regenerateCoupon(int $leadId): array
    {
        try {
            if ($leadId <= 0) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Lead id is required.',
                ];
            }

            $repository = new LeadRepository(Database::connection());
            $lead = $repository->findById($leadId);
            if (!is_array($lead)) {
                return [
                    'ok' => false,
                    'error' => 'LEAD_NOT_FOUND',
                    'message' => 'Lead not found.',
                ];
            }

            $activeReward = strtolower(trim((string) $this->activeRewardLabel($lead)));
            if ($activeReward === '' || strpos($activeReward, 'try again') !== false) {
                return [
                    'ok' => false,
                    'error' => 'COUPON_NOT_ALLOWED',
                    'message' => 'Coupon regeneration is only available for winning rewards.',
                ];
            }

            $couponCode = $this->generateCouponCode('AWG');
            $repository->replaceCouponCode($leadId, $couponCode);

            return [
                'ok' => true,
                'message' => 'Coupon regenerated successfully.',
                'couponCode' => $couponCode,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'REGENERATE_COUPON_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function testCrmSync(array $payload): array
    {
        try {
            $leadPayload = is_array($payload['lead'] ?? null) ? $payload['lead'] : $payload;
            $phone = $this->tailPhone((string) ($leadPayload['phone'] ?? ''));
            if (strlen($phone) !== 10) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Please provide a valid 10-digit test phone number.',
                ];
            }

            $name = trim((string) ($leadPayload['name'] ?? 'CRM Test Lead'));
            if ($name === '') {
                $name = 'CRM Test Lead';
            }

            $repository = new LeadRepository(Database::connection());
            $leadId = $repository->create([
                'name' => $name,
                'phone' => '91' . $phone,
                'prize' => 'CRM Test Lead',
                'status' => 'Unredeemed',
                'date_of_birth' => $this->normalizeCrmUiDate((string) ($leadPayload['dob'] ?? $leadPayload['dateOfBirth'] ?? '')),
                'date_of_anniversary' => $this->normalizeCrmUiDate((string) ($leadPayload['doa'] ?? $leadPayload['dateOfAnniversary'] ?? '')),
                'source' => 'crm_controlled_test',
                'visit_count' => $repository->countByPhone('91' . $phone) + 1,
                'coupon_code' => null,
                'crm_sync_status' => 'Pending',
            ]);

            $sync = (new LeadService())->syncCrmByPhone(['phone' => '91' . $phone]);
            $storedLead = $repository->findById($leadId);
            $contact = (new ContactRepository(Database::connection()))->findByPhone('91' . $phone);

            $crmPayload = [
                'contact_name' => $name,
                'contact_phone' => '+91' . $phone,
                'date_of_birth' => $storedLead['date_of_birth'] ?? null,
                'anniversary_date' => $storedLead['date_of_anniversary'] ?? null,
            ];

            return [
                'ok' => true,
                'message' => 'Controlled CRM test sync completed.',
                'leadId' => $leadId,
                'dataReceived' => [
                    'name' => $name,
                    'phone' => $phone,
                    'dob' => (string) ($leadPayload['dob'] ?? $leadPayload['dateOfBirth'] ?? ''),
                    'doa' => (string) ($leadPayload['doa'] ?? $leadPayload['dateOfAnniversary'] ?? ''),
                ],
                'crmPayloadPreview' => $crmPayload,
                'storedLead' => is_array($storedLead) ? $this->mapCrmLeadRow($storedLead) : null,
                'canonicalContact' => $contact,
                'crmSyncConfirmation' => $sync['crmSync'] ?? $sync,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_TEST_SYNC_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function deleteCrmTestLead(int $leadId): array
    {
        try {
            if ($leadId <= 0) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Lead id is required.',
                ];
            }

            $repository = new LeadRepository(Database::connection());
            $lead = $repository->findById($leadId);
            if (!is_array($lead)) {
                return [
                    'ok' => false,
                    'error' => 'LEAD_NOT_FOUND',
                    'message' => 'Test lead was not found.',
                ];
            }

            $source = strtolower(trim((string) ($lead['source'] ?? '')));
            $allowedSources = ['crm_controlled_test', 'home_popup_crm_test', 'home_popup_test'];
            if (!in_array($source, $allowedSources, true)) {
                return [
                    'ok' => false,
                    'error' => 'DELETE_NOT_ALLOWED',
                    'message' => 'Only approved CRM test leads can be deleted.',
                ];
            }

            $deletedLead = $repository->deleteLead($leadId);
            $phone = (string) ($deletedLead['phone'] ?? '');
            $contactRepository = new ContactRepository(Database::connection());
            $remaining = $phone !== '' ? $repository->findLatestSummaryByPhone($phone) : null;
            if (is_array($remaining)) {
                $contactRepository->upsert([
                    'phone' => $phone,
                    'name' => (string) ($remaining['name'] ?? ''),
                    'date_of_birth' => $remaining['date_of_birth'] ?? null,
                    'date_of_anniversary' => $remaining['date_of_anniversary'] ?? null,
                    'first_seen_at' => $remaining['first_seen_at'] ?? $remaining['created_at'] ?? date('Y-m-d H:i:s'),
                    'last_seen_at' => $remaining['last_seen_at'] ?? $remaining['created_at'] ?? date('Y-m-d H:i:s'),
                    'latest_source' => $remaining['source'] ?? null,
                    'latest_lead_id' => (int) ($remaining['id'] ?? 0),
                    'latest_lead_created_at' => $remaining['created_at'] ?? null,
                    'total_submissions' => (int) ($remaining['total_submissions'] ?? 1),
                    'latest_crm_sync_status' => $remaining['crm_sync_status'] ?? 'Pending',
                    'latest_crm_sync_code' => $remaining['crm_sync_code'] ?? null,
                    'latest_crm_sync_message' => $remaining['crm_sync_message'] ?? null,
                    'last_crm_attempted_at' => null,
                    'last_crm_pushed_at' => null,
                ]);
            } elseif ($phone !== '') {
                $contactRepository->deleteByPhone($phone);
            }

            return [
                'ok' => true,
                'message' => 'CRM test lead deleted.',
                'deleted' => $deletedLead ? 1 : 0,
                'phone' => $phone,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_TEST_DELETE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function listEvents(): array
    {
        try {
            $settings = new AppSettingRepository(Database::connection());
            $events = $this->ensureDefaultEvents($settings);

            return [
                'ok' => true,
                'events' => $events,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'EVENTS_LIST_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function listPublicLiveEvents(): array
    {
        try {
            $settings = new AppSettingRepository(Database::connection());
            $events = $this->ensureDefaultEvents($settings);
            $events = array_map(function (array $event): array {
                $eventId = (string) ($event['id'] ?? '');
                $existingSlug = trim((string) ($event['slug'] ?? $event['eventSlug'] ?? $event['event_slug'] ?? ''));
                $slug = $this->buildEventSlug($existingSlug !== '' ? $existingSlug : (string) ($event['title'] ?? ''), $eventId);
                $event['slug'] = $slug;
                $event['eventSlug'] = $slug;
                $event['event_slug'] = $slug;
                return $event;
            }, $events);
            $nowTs = time();
            $liveEvents = array_values(array_filter($events, function (array $event) use ($nowTs): bool {
                // Active flag check
                if (array_key_exists('isActive', $event)) {
                    if (!$this->toBooleanSetting($event['isActive'])) {
                        return false;
                    }
                } elseif (array_key_exists('is_active', $event)) {
                    if (!$this->toBooleanSetting($event['is_active'])) {
                        return false;
                    }
                }

                // Expiry check: hide events whose date has already passed
                $endDate = trim((string) ($event['endDate'] ?? $event['end_date'] ?? ''));
                $startDate = trim((string) ($event['startDate'] ?? $event['start_date'] ?? $event['date'] ?? ''));
                $date = $endDate !== '' ? $endDate : $startDate;
                if ($date !== '') {
                    $time = $endDate !== ''
                        ? trim((string) ($event['endTime'] ?? $event['end_time'] ?? '23:59:59'))
                        : '23:59:59';
                    if ($time === '') {
                        $time = '23:59:59';
                    }
                    $ts = strtotime($date . ' ' . $time);
                    if ($ts !== false && $ts < $nowTs) {
                        return false;
                    }
                }

                return true;
            }));

            $customLiveEvents = array_values(array_filter(
                $liveEvents,
                static fn (array $event): bool => empty($event['isDemo'])
            ));
            if ($customLiveEvents !== []) {
                $liveEvents = $customLiveEvents;
            }

            usort($liveEvents, static function (array $a, array $b): int {
                $aDate = (string) ($a['startDate'] ?? $a['start_date'] ?? $a['date'] ?? '');
                $aTime = (string) ($a['startTime'] ?? $a['start_time'] ?? $a['time'] ?? '00:00:00');
                $bDate = (string) ($b['startDate'] ?? $b['start_date'] ?? $b['date'] ?? '');
                $bTime = (string) ($b['startTime'] ?? $b['start_time'] ?? $b['time'] ?? '00:00:00');

                $aTs = strtotime(trim($aDate . ' ' . $aTime));
                $bTs = strtotime(trim($bDate . ' ' . $bTime));

                if ($aTs !== false || $bTs !== false) {
                    return (int) ($bTs ?: 0) <=> (int) ($aTs ?: 0);
                }

                $aUpdated = strtotime((string) ($a['updatedAt'] ?? $a['updated_at'] ?? ''));
                $bUpdated = strtotime((string) ($b['updatedAt'] ?? $b['updated_at'] ?? ''));

                return (int) ($bUpdated ?: 0) <=> (int) ($aUpdated ?: 0);
            });

            return [
                'ok' => true,
                'events' => $liveEvents,
                'count' => count($liveEvents),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'PUBLIC_EVENTS_LIST_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function registerPublicEvent(array $payload): array
    {
        try {
            $eventId = trim((string) ($payload['eventId'] ?? ''));
            $name = trim((string) ($payload['name'] ?? ''));
            $phone = $this->normalizePhone((string) ($payload['phone'] ?? ''));
            $email = trim((string) ($payload['email'] ?? ''));
            $guestCount = max(1, (int) ($payload['guestCount'] ?? 1));
            $notes = trim((string) ($payload['notes'] ?? ''));

            if ($eventId === '' || $name === '' || strlen($phone) !== 10) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Event id, name and valid 10-digit phone are required.',
                ];
            }

            $settings = new AppSettingRepository(Database::connection());
            $events = $this->decodeEvents($settings->getValue(self::EVENTS_GROUP, self::EVENTS_KEY));
            $event = null;
            foreach ($events as $row) {
                if ((string) ($row['id'] ?? '') === $eventId) {
                    $event = $row;
                    break;
                }
            }

            if (!is_array($event) && strpos($eventId, 'evt_demo_') !== 0) {
                return [
                    'ok' => false,
                    'error' => 'EVENT_NOT_FOUND',
                    'message' => 'Selected event was not found.',
                ];
            }

            $key = 'event_registrations_' . $eventId;
            $registrations = $this->decodeJsonArray($settings->getValue(self::EVENTS_GROUP, $key));
            $registrationId = 'reg_' . bin2hex(random_bytes(5));
            $guestToken = 'g_' . strtoupper(bin2hex(random_bytes(4)));

            $registrations[] = [
                'id' => $registrationId,
                'eventId' => $eventId,
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'guestCount' => $guestCount,
                'notes' => $notes,
                'guestToken' => $guestToken,
                'createdAt' => (new DateTimeImmutable('now'))->format('c'),
            ];

            $settings->upsert(self::EVENTS_GROUP, $key, json_encode($registrations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => true,
                'message' => 'Event registration completed.',
                'registrationId' => $registrationId,
                'guestToken' => $guestToken,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'EVENT_REGISTRATION_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function getSpinOffers(): array
    {
        try {
            $repository = new AppSettingRepository(Database::connection());
            $offers = $this->sanitizeSpinOffers($this->decodeJsonArray($repository->getValue(self::SPIN_GROUP, self::SPIN_OFFERS_KEY)));

            if ($offers === []) {
                $offers = $this->defaultSpinOffers();
                $repository->upsert(self::SPIN_GROUP, self::SPIN_OFFERS_KEY, json_encode($offers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $totalWeight = array_reduce($offers, static fn (float $carry, array $item): float => $carry + (float) ($item['weight'] ?? 0), 0.0);
            $chart = array_map(static function (array $item) use ($totalWeight): array {
                $weight = (float) ($item['weight'] ?? 0);
                return [
                    'id' => (string) ($item['id'] ?? ''),
                    'label' => (string) ($item['label'] ?? ''),
                    'weight' => $weight,
                    'percentage' => $totalWeight > 0 ? round(($weight / $totalWeight) * 100, 2) : 0,
                    'color' => (string) ($item['color'] ?? '#B89355'),
                ];
            }, $offers);

            return [
                'ok' => true,
                'offers' => $offers,
                'totalWeight' => $totalWeight,
                'chart' => $chart,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'SPIN_OFFERS_FETCH_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function setSpinOffers(array $payload): array
    {
        try {
            $rows = is_array($payload['offers'] ?? null) ? $payload['offers'] : $payload;
            if (!is_array($rows)) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Offers payload must be an array.',
                ];
            }

            $offers = $this->sanitizeSpinOffers($rows);
            if ($offers === []) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'At least one active offer is required.',
                ];
            }

            $repository = new AppSettingRepository(Database::connection());
            $repository->upsert(self::SPIN_GROUP, self::SPIN_OFFERS_KEY, json_encode($offers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => true,
                'message' => 'Spin offers saved successfully.',
                'offers' => $offers,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'SPIN_OFFERS_SAVE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function saveEvent(array $payload): array
    {
        try {
            $title = trim((string) ($payload['title'] ?? ''));
            $date = trim((string) ($payload['date'] ?? ''));
            $time = trim((string) ($payload['time'] ?? ''));
            $description = trim((string) ($payload['description'] ?? ''));
            $hasActiveFlag = array_key_exists('isActive', $payload)
                || array_key_exists('is_active', $payload)
                || array_key_exists('active', $payload);
            $isActiveRaw = $payload['isActive'] ?? $payload['is_active'] ?? $payload['active'] ?? true;
            $isActive = $hasActiveFlag ? $this->toBooleanSetting($isActiveRaw) : true;
            $subtitle = trim((string) ($payload['subtitle'] ?? ''));
            $eventType = strtolower(trim((string) ($payload['eventType'] ?? $payload['event_type'] ?? 'free')));
            $eventType = $eventType === 'paid' ? 'paid' : 'free';
            $ticketPrice = max(0, (float) ($payload['ticketPrice'] ?? $payload['ticket_price'] ?? 0));
            $badgeText = trim((string) ($payload['badgeText'] ?? $payload['badge_text'] ?? ''));
            $venue = trim((string) ($payload['venue'] ?? ''));
            $imageUrl = trim((string) ($payload['imageUrl'] ?? $payload['image_url'] ?? ''));
            $videoUrl = trim((string) ($payload['videoUrl'] ?? $payload['video_url'] ?? ''));
            $slugFromPayload = trim((string) ($payload['slug'] ?? $payload['eventSlug'] ?? $payload['event_slug'] ?? ''));
            $startDate = trim((string) ($payload['startDate'] ?? $payload['start_date'] ?? $date));
            $startTime = trim((string) ($payload['startTime'] ?? $payload['start_time'] ?? $time));
            $endDate = trim((string) ($payload['endDate'] ?? $payload['end_date'] ?? $startDate));
            $endTime = trim((string) ($payload['endTime'] ?? $payload['end_time'] ?? $startTime));

            if ($title === '' || $date === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Event title and date are required.',
                ];
            }

            $repository = new AppSettingRepository(Database::connection());
            $events = $this->ensureDefaultEvents($repository);

            $eventId = trim((string) ($payload['id'] ?? ''));
            if ($eventId === '') {
                $eventId = 'evt_' . bin2hex(random_bytes(5));
                $eventSlug = $this->buildEventSlug($slugFromPayload !== '' ? $slugFromPayload : $title, $eventId);
                $events[] = [
                    'id' => $eventId,
                    'slug' => $eventSlug,
                    'eventSlug' => $eventSlug,
                    'event_slug' => $eventSlug,
                    'title' => $title,
                    'date' => $date,
                    'time' => $time,
                    'description' => $description,
                    'subtitle' => $subtitle,
                    'eventType' => $eventType,
                    'ticketPrice' => $ticketPrice,
                    'badgeText' => $badgeText,
                    'venue' => $venue,
                    'imageUrl' => $imageUrl,
                    'image_url' => $imageUrl,
                    'videoUrl' => $videoUrl,
                    'video_url' => $videoUrl,
                    'startDate' => $startDate,
                    'start_date' => $startDate,
                    'startTime' => $startTime,
                    'start_time' => $startTime,
                    'endDate' => $endDate,
                    'end_date' => $endDate,
                    'endTime' => $endTime,
                    'end_time' => $endTime,
                    'isActive' => $isActive,
                    'isDemo' => false,
                    'updatedAt' => (new DateTimeImmutable('now'))->format('c'),
                ];
            } else {
                $updated = false;
                foreach ($events as &$event) {
                    if ((string) ($event['id'] ?? '') !== $eventId) {
                        continue;
                    }

                    $existingSlug = trim((string) ($event['slug'] ?? $event['eventSlug'] ?? $event['event_slug'] ?? ''));
                    $eventSlug = $this->buildEventSlug(
                        $slugFromPayload !== '' ? $slugFromPayload : ($existingSlug !== '' ? $existingSlug : $title),
                        $eventId
                    );

                    $event['slug'] = $eventSlug;
                    $event['eventSlug'] = $eventSlug;
                    $event['event_slug'] = $eventSlug;
                    $event['title'] = $title;
                    $event['date'] = $date;
                    $event['time'] = $time;
                    $event['description'] = $description;
                    $event['subtitle'] = $subtitle;
                    $event['eventType'] = $eventType;
                    $event['ticketPrice'] = $ticketPrice;
                    $event['badgeText'] = $badgeText;
                    $event['venue'] = $venue;
                    $event['imageUrl'] = $imageUrl;
                    $event['image_url'] = $imageUrl;
                    $event['videoUrl'] = $videoUrl;
                    $event['video_url'] = $videoUrl;
                    $event['startDate'] = $startDate;
                    $event['start_date'] = $startDate;
                    $event['startTime'] = $startTime;
                    $event['start_time'] = $startTime;
                    $event['endDate'] = $endDate;
                    $event['end_date'] = $endDate;
                    $event['endTime'] = $endTime;
                    $event['end_time'] = $endTime;
                    $event['isActive'] = $isActive;
                    $event['isDemo'] = false;
                    $event['updatedAt'] = (new DateTimeImmutable('now'))->format('c');
                    $updated = true;
                    break;
                }
                unset($event);

                if (!$updated) {
                    $eventSlug = $this->buildEventSlug($slugFromPayload !== '' ? $slugFromPayload : $title, $eventId);
                    $events[] = [
                        'id' => $eventId,
                        'slug' => $eventSlug,
                        'eventSlug' => $eventSlug,
                        'event_slug' => $eventSlug,
                        'title' => $title,
                        'date' => $date,
                        'time' => $time,
                        'description' => $description,
                        'subtitle' => $subtitle,
                        'eventType' => $eventType,
                        'ticketPrice' => $ticketPrice,
                        'badgeText' => $badgeText,
                        'venue' => $venue,
                        'imageUrl' => $imageUrl,
                        'image_url' => $imageUrl,
                        'videoUrl' => $videoUrl,
                        'video_url' => $videoUrl,
                        'startDate' => $startDate,
                        'start_date' => $startDate,
                        'startTime' => $startTime,
                        'start_time' => $startTime,
                        'endDate' => $endDate,
                        'end_date' => $endDate,
                        'endTime' => $endTime,
                        'end_time' => $endTime,
                        'isActive' => $isActive,
                        'isDemo' => false,
                        'updatedAt' => (new DateTimeImmutable('now'))->format('c'),
                    ];
                }
            }

            $repository->upsert(self::EVENTS_GROUP, self::EVENTS_KEY, json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => true,
                'message' => 'Event saved successfully.',
                'events' => $events,
                'slugSyncOk' => false,
                'slugSyncError' => 'Automatic event QR redirect sync is disabled. Use Create Event QR manually.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'EVENT_SAVE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function deleteEvent(string $eventId): array
    {
        try {
            $eventId = trim($eventId);
            if ($eventId === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Event id is required.',
                ];
            }

            $repository = new AppSettingRepository(Database::connection());
            $events = $this->ensureDefaultEvents($repository);
            $filtered = array_values(array_filter($events, static fn (array $event): bool => (string) ($event['id'] ?? '') !== $eventId));

            $repository->upsert(self::EVENTS_GROUP, self::EVENTS_KEY, json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => true,
                'message' => 'Event deleted successfully.',
                'events' => $filtered,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'EVENT_DELETE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function cloneEvent(string $eventId): array
    {
        try {
            $eventId = trim($eventId);
            if ($eventId === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Event id is required.',
                ];
            }

            $repository = new AppSettingRepository(Database::connection());
            $events = $this->ensureDefaultEvents($repository);
            $sourceEvent = null;

            foreach ($events as $event) {
                if ((string) ($event['id'] ?? '') === $eventId) {
                    $sourceEvent = $event;
                    break;
                }
            }

            if (!is_array($sourceEvent)) {
                return [
                    'ok' => false,
                    'error' => 'EVENT_NOT_FOUND',
                    'message' => 'Event was not found.',
                ];
            }

            $sourceEvent['id'] = 'evt_' . bin2hex(random_bytes(5));
            $sourceEventSlugBase = (string) ($sourceEvent['slug'] ?? $sourceEvent['eventSlug'] ?? $sourceEvent['event_slug'] ?? $sourceEvent['title'] ?? 'event');
            $sourceEventSlug = $this->buildEventSlug($sourceEventSlugBase, (string) $sourceEvent['id']);
            $sourceEvent['slug'] = $sourceEventSlug;
            $sourceEvent['eventSlug'] = $sourceEventSlug;
            $sourceEvent['event_slug'] = $sourceEventSlug;
            $sourceEvent['updatedAt'] = (new DateTimeImmutable('now'))->format('c');
            $events[] = $sourceEvent;

            $repository->upsert(self::EVENTS_GROUP, self::EVENTS_KEY, json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => true,
                'message' => 'Event cloned successfully.',
                'event' => $sourceEvent,
                'events' => $events,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'EVENT_CLONE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function toggleEvent(string $eventId, bool $isActive): array
    {
        try {
            $eventId = trim($eventId);
            if ($eventId === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Event id is required.',
                ];
            }

            $repository = new AppSettingRepository(Database::connection());
            $events = $this->ensureDefaultEvents($repository);
            $updated = false;
            foreach ($events as &$event) {
                if ((string) ($event['id'] ?? '') !== $eventId) {
                    continue;
                }

                $event['isActive'] = $isActive;
                $event['updatedAt'] = (new DateTimeImmutable('now'))->format('c');
                $updated = true;
                break;
            }
            unset($event);

            if (!$updated) {
                return [
                    'ok' => false,
                    'error' => 'EVENT_NOT_FOUND',
                    'message' => 'Event was not found.',
                ];
            }

            $repository->upsert(self::EVENTS_GROUP, self::EVENTS_KEY, json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => true,
                'message' => 'Event status updated.',
                'events' => $events,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'EVENT_TOGGLE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function uploadEventImage(array $payload): array
    {
        try {
            $eventId = trim((string) ($payload['event_id'] ?? $payload['eventId'] ?? $payload['id'] ?? ''));
            if ($eventId === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'event_id is required.',
                ];
            }

            $upload = null;
            foreach (['eventImage', 'image', 'file'] as $fileKey) {
                if (isset($_FILES[$fileKey]) && is_array($_FILES[$fileKey])) {
                    $upload = $_FILES[$fileKey];
                    break;
                }
            }

            if (!is_array($upload)) {
                return [
                    'ok' => false,
                    'error' => 'UPLOAD_REQUIRED',
                    'message' => 'Select an image file to upload.',
                ];
            }

            $errorCode = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($errorCode !== UPLOAD_ERR_OK) {
                $errorMap = [
                    UPLOAD_ERR_INI_SIZE => 'Server upload limit exceeded.',
                    UPLOAD_ERR_FORM_SIZE => 'Uploaded file is too large.',
                    UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Please retry.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory is missing.',
                    UPLOAD_ERR_CANT_WRITE => 'Server could not write uploaded file.',
                    UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension.',
                ];
                return [
                    'ok' => false,
                    'error' => 'UPLOAD_FAILED',
                    'message' => $errorMap[$errorCode] ?? 'Image upload failed.',
                ];
            }

            $tmpPath = (string) ($upload['tmp_name'] ?? '');
            if ($tmpPath === '' || (!is_uploaded_file($tmpPath) && !is_file($tmpPath))) {
                return [
                    'ok' => false,
                    'error' => 'UPLOAD_INVALID',
                    'message' => 'Uploaded file is invalid.',
                ];
            }

            $size = (int) ($upload['size'] ?? 0);
            $maxBytes = 8 * 1024 * 1024;
            if ($size <= 0 || $size > $maxBytes) {
                return [
                    'ok' => false,
                    'error' => 'UPLOAD_TOO_LARGE',
                    'message' => 'Image must be smaller than 8 MB.',
                ];
            }

            $ext = '';
            if (class_exists('finfo')) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $finfo->file($tmpPath);
                $ext = match ($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    default => '',
                };
            }

            if ($ext === '') {
                $nameExt = strtolower((string) pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
                if (in_array($nameExt, ['jpg', 'jpeg'], true)) {
                    $ext = 'jpg';
                } elseif (in_array($nameExt, ['png', 'webp'], true)) {
                    $ext = $nameExt;
                }
            }

            if ($ext === '') {
                return [
                    'ok' => false,
                    'error' => 'UPLOAD_TYPE_NOT_ALLOWED',
                    'message' => 'Allowed formats: JPG, PNG, WEBP.',
                ];
            }

            $baseDir = dirname(__DIR__, 2) . '/asianwokandgrill.in/assets/event-images';
            if (!is_dir($baseDir) && !@mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
                return [
                    'ok' => false,
                    'error' => 'UPLOAD_STORAGE_UNAVAILABLE',
                    'message' => 'Unable to create image directory.',
                ];
            }

            $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $eventId);
            $fileName = 'evt-' . ($safeId !== '' ? $safeId : 'event') . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
            $targetPath = $baseDir . '/' . $fileName;
            $stored = move_uploaded_file($tmpPath, $targetPath);
            if (!$stored) {
                $stored = @rename($tmpPath, $targetPath);
            }
            if (!$stored && is_file($tmpPath)) {
                $stored = @copy($tmpPath, $targetPath);
                if ($stored) {
                    @unlink($tmpPath);
                }
            }
            if (!$stored) {
                return [
                    'ok' => false,
                    'error' => 'UPLOAD_MOVE_FAILED',
                    'message' => 'Unable to store uploaded image.',
                ];
            }

            $imageUrl = '/assets/event-images/' . $fileName;

            $repository = new AppSettingRepository(Database::connection());
            $events = $this->ensureDefaultEvents($repository);
            $updated = false;

            foreach ($events as &$event) {
                $candidateIds = [
                    (string) ($event['id'] ?? ''),
                    (string) ($event['event_id'] ?? ''),
                    (string) ($event['eventId'] ?? ''),
                ];
                if (!in_array($eventId, $candidateIds, true)) {
                    continue;
                }

                $event['imageUrl'] = $imageUrl;
                $event['image_url'] = $imageUrl;
                $event['updatedAt'] = (new DateTimeImmutable('now'))->format('c');
                $updated = true;
                break;
            }
            unset($event);

            if (!$updated) {
                $eventRepo = new EventRepository();
                $updated = $eventRepo->updateImageUrl($eventId, $imageUrl);
            }

            if (!$updated) {
                return [
                    'ok' => false,
                    'error' => 'EVENT_NOT_FOUND',
                    'message' => 'Event not found for image update.',
                ];
            }

            if (!empty($events)) {
                $repository->upsert(self::EVENTS_GROUP, self::EVENTS_KEY, json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return [
                'ok' => true,
                'message' => 'Event image updated.',
                'event_id' => $eventId,
                'image_url' => $imageUrl,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'EVENT_IMAGE_UPLOAD_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function verifyEventQr(string $eventId, string $guestToken): array
    {
        return $this->checkinEventQr($eventId, $guestToken, true);
    }

    public function generateEventQr(array $payload): array
    {
        try {
            $eventId = trim((string) ($payload['eventId'] ?? $payload['id'] ?? ''));
            if ($eventId === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Event id is required.',
                ];
            }

            $repository = new AppSettingRepository(Database::connection());
            $events = $this->ensureDefaultEvents($repository);
            $event = null;
            foreach ($events as $row) {
                if ((string) ($row['id'] ?? '') === $eventId) {
                    $event = $row;
                    break;
                }
            }

            if (!is_array($event)) {
                return [
                    'ok' => false,
                    'error' => 'EVENT_NOT_FOUND',
                    'message' => 'Event was not found.',
                ];
            }

            $slug = 'event-' . $eventId;
            $title = trim((string) ($event['title'] ?? 'Event')) . ' QR';
            $targetUrl = '/events.html?eventId=' . rawurlencode($eventId);

            $redirectResult = $this->saveQrRedirect([
                'slug' => $slug,
                'title' => $title,
                'targetUrl' => $targetUrl,
                'isActive' => true,
            ]);

            if (($redirectResult['ok'] ?? false) !== true) {
                return $redirectResult;
            }

            return [
                'ok' => true,
                'message' => 'Event QR generated.',
                'eventId' => $eventId,
                'slug' => $slug,
                'title' => $title,
                'targetUrl' => $targetUrl,
                'redirectId' => $redirectResult['id'] ?? null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'EVENT_QR_GENERATE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function previewEventQr(string $eventId, string $guestToken): array
    {
        return $this->checkinEventQr($eventId, $guestToken, false);
    }

    public function batchCheckinEventQr(string $eventId, array $tokens): array
    {
        $results = [];
        foreach ($tokens as $token) {
            $results[] = $this->checkinEventQr($eventId, (string) $token, true);
        }

        return [
            'ok' => true,
            'message' => 'Batch check-in completed.',
            'results' => $results,
        ];
    }

    public function eventGuestReport(array $query): array
    {
        try {
            $eventId = trim((string) ($query['eventId'] ?? ''));
            if ($eventId === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Event id is required.',
                ];
            }

            $search = strtolower(trim((string) ($query['search'] ?? '')));
            $statusFilter = strtolower(trim((string) ($query['status'] ?? 'all')));

            $repository = new AppSettingRepository(Database::connection());
            $events = $this->decodeEvents($repository->getValue(self::EVENTS_GROUP, self::EVENTS_KEY));
            $event = null;
            foreach ($events as $row) {
                if ((string) ($row['id'] ?? '') === $eventId) {
                    $event = $row;
                    break;
                }
            }

            $registrations = $this->decodeJsonArray($repository->getValue(self::EVENTS_GROUP, 'event_registrations_' . $eventId));
            $scans = $this->decodeJsonArray($repository->getValue(self::EVENTS_GROUP, 'event_guests_' . $eventId));

            $scanByToken = [];
            foreach ($scans as $scan) {
                $token = trim((string) ($scan['token'] ?? ''));
                if ($token === '') {
                    continue;
                }
                $scanByToken[$token] = $scan;
            }

            $guests = [];
            foreach ($registrations as $registration) {
                $token = trim((string) ($registration['guestToken'] ?? ''));
                $scan = $token !== '' && array_key_exists($token, $scanByToken) ? $scanByToken[$token] : null;
                $checkedIn = is_array($scan) && !empty($scan['checkedIn']);

                $guest = [
                    'id' => (string) ($registration['id'] ?? ''),
                    'eventId' => $eventId,
                    'name' => (string) ($registration['name'] ?? ''),
                    'phone' => (string) ($registration['phone'] ?? ''),
                    'email' => (string) ($registration['email'] ?? ''),
                    'guestCount' => max(1, (int) ($registration['guestCount'] ?? 1)),
                    'notes' => (string) ($registration['notes'] ?? ''),
                    'token' => $token,
                    'source' => 'registration',
                    'status' => $checkedIn ? 'checked-in' : 'registered',
                    'registeredAt' => (string) ($registration['createdAt'] ?? ''),
                    'checkedInAt' => is_array($scan) ? (string) ($scan['checkedInAt'] ?? '') : '',
                ];

                $guests[] = $guest;
            }

            $knownTokens = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['token'] ?? ''), $guests), static fn (string $token): bool => $token !== ''));

            foreach ($scans as $scan) {
                $token = trim((string) ($scan['token'] ?? ''));
                if ($token !== '' && in_array($token, $knownTokens, true)) {
                    continue;
                }

                $guests[] = [
                    'id' => 'walkin_' . substr(hash('sha256', $token . (string) ($scan['checkedInAt'] ?? '')), 0, 12),
                    'eventId' => $eventId,
                    'name' => (string) ($scan['name'] ?? 'Walk-in Guest'),
                    'phone' => '',
                    'email' => '',
                    'guestCount' => 1,
                    'notes' => '',
                    'token' => $token,
                    'source' => 'walk-in',
                    'status' => !empty($scan['checkedIn']) ? 'checked-in' : 'registered',
                    'registeredAt' => '',
                    'checkedInAt' => (string) ($scan['checkedInAt'] ?? ''),
                ];
            }

            $filtered = array_values(array_filter($guests, static function (array $guest) use ($search, $statusFilter): bool {
                if ($statusFilter !== 'all' && (string) ($guest['status'] ?? '') !== $statusFilter) {
                    return false;
                }

                if ($search !== '') {
                    $haystack = strtolower(
                        (string) ($guest['name'] ?? '') . ' ' .
                        (string) ($guest['phone'] ?? '') . ' ' .
                        (string) ($guest['email'] ?? '') . ' ' .
                        (string) ($guest['token'] ?? '')
                    );
                    if (!str_contains($haystack, $search)) {
                        return false;
                    }
                }

                return true;
            }));

            usort($filtered, static fn (array $a, array $b): int => strcmp((string) ($b['checkedInAt'] ?? $b['registeredAt'] ?? ''), (string) ($a['checkedInAt'] ?? $a['registeredAt'] ?? '')));

            $checkedInCount = count(array_filter($guests, static fn (array $guest): bool => (string) ($guest['status'] ?? '') === 'checked-in'));
            $walkInCount = count(array_filter($guests, static fn (array $guest): bool => (string) ($guest['source'] ?? '') === 'walk-in'));
            $registeredCount = count(array_filter($guests, static fn (array $guest): bool => (string) ($guest['source'] ?? '') === 'registration'));

            return [
                'ok' => true,
                'event' => [
                    'id' => $eventId,
                    'title' => is_array($event) ? (string) ($event['title'] ?? $eventId) : $eventId,
                    'date' => is_array($event) ? (string) ($event['date'] ?? '') : '',
                    'time' => is_array($event) ? (string) ($event['time'] ?? '') : '',
                ],
                'summary' => [
                    'totalGuests' => count($guests),
                    'registeredGuests' => $registeredCount,
                    'checkedInGuests' => $checkedInCount,
                    'pendingGuests' => max(0, $registeredCount - ($checkedInCount - $walkInCount)),
                    'walkInGuests' => $walkInCount,
                ],
                'guests' => $filtered,
                'reconciliation' => [
                    'registeredWithoutCheckin' => max(0, $registeredCount - ($checkedInCount - $walkInCount)),
                    'walkIns' => $walkInCount,
                    'scanRecords' => count($scans),
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'EVENT_GUEST_REPORT_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function eventMailLogReport(array $query): array
    {
        try {
            $eventId = trim((string) ($query['eventId'] ?? ''));
            if ($eventId === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Event id is required.',
                ];
            }

            $limit = max(10, min(500, (int) ($query['limit'] ?? 100)));
            $statusFilter = strtolower(trim((string) ($query['status'] ?? 'all')));

            $repository = new AppSettingRepository(Database::connection());
            $logs = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_LOGS_KEY));
            $filtered = array_values(array_filter($logs, static function (array $log) use ($eventId, $statusFilter): bool {
                if ((string) ($log['eventId'] ?? '') !== $eventId) {
                    return false;
                }

                if ($statusFilter !== 'all') {
                    $result = strtolower((string) ($log['result'] ?? ''));
                    if ($result !== $statusFilter) {
                        return false;
                    }
                }

                return true;
            }));

            usort($filtered, static fn (array $a, array $b): int => strcmp((string) ($b['time'] ?? ''), (string) ($a['time'] ?? '')));
            $rows = array_slice($filtered, 0, $limit);

            return [
                'ok' => true,
                'eventId' => $eventId,
                'summary' => [
                    'total' => count($filtered),
                    'sent' => count(array_filter($filtered, static fn (array $log): bool => strtolower((string) ($log['result'] ?? '')) === 'sent')),
                    'failed' => count(array_filter($filtered, static fn (array $log): bool => strtolower((string) ($log['result'] ?? '')) === 'failed')),
                    'skipped' => count(array_filter($filtered, static fn (array $log): bool => strtolower((string) ($log['result'] ?? '')) === 'skipped')),
                ],
                'logs' => $rows,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'EVENT_MAIL_LOG_REPORT_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function getAppSettings(): array
    {
        try {
            $repository = new AppSettingRepository(Database::connection());
            $group = $repository->getGroup('app');

            $rawPages = $this->decodeJsonObject($group[self::BLOCKER_PAGES_KEY]['value'] ?? null);
            $rawBlockerConfig = $this->decodeJsonObject($group[self::BLOCKER_CONFIG_KEY]['value'] ?? null);
            $rawBlockerContent = $this->decodeJsonObject($group[self::BLOCKER_CONTENT_KEY]['value'] ?? null);
            $rawMilestoneScheme = $this->decodeJsonObject($group[self::SPIN_MILESTONE_SCHEME_KEY]['value'] ?? null);

            return [
                'ok' => true,
                'settings' => [
                    'hotelWhatsappNo' => (string) (($group['hotelWhatsappNo']['value'] ?? '') ?: ''),
                    'menuBlockerStaffCode' => (string) (($group['menuBlockerStaffCode']['value'] ?? '') ?: ''),
                    'eventEntryPasscode' => (string) (($group['eventEntryPasscode']['value'] ?? '') ?: ''),
                    'menuBlockerPages' => $this->normalizeBlockerPages($rawPages),
                    'spinBlockerConfig' => $this->normalizeBlockerConfig($rawBlockerConfig),
                    'spinBlockerContent' => $this->normalizeBlockerContent($rawBlockerContent),
                    'spinMilestoneScheme' => $this->normalizeSpinMilestoneScheme($rawMilestoneScheme),
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'SETTINGS_FETCH_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function setAppSettings(array $settings): array
    {
        try {
            $repository = new AppSettingRepository(Database::connection());

            $whatsapp = array_key_exists('hotelWhatsappNo', $settings)
                ? trim((string) ($settings['hotelWhatsappNo'] ?? ''))
                : null;
            $staffCode = array_key_exists('menuBlockerStaffCode', $settings)
                ? strtoupper(trim((string) ($settings['menuBlockerStaffCode'] ?? '')))
                : null;
            $eventPasscode = array_key_exists('eventEntryPasscode', $settings)
                ? strtoupper(trim((string) ($settings['eventEntryPasscode'] ?? '')))
                : null;

            if ($staffCode !== null && $staffCode !== '' && strlen($staffCode) < 4) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'menuBlockerStaffCode must be at least 4 characters.',
                ];
            }

            if ($eventPasscode !== null && $eventPasscode !== '' && strlen($eventPasscode) < 4) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'eventEntryPasscode must be at least 4 characters.',
                ];
            }

            if ($whatsapp !== null) {
                $repository->upsert('app', 'hotelWhatsappNo', $whatsapp, false);
            }
            if ($staffCode !== null) {
                $repository->upsert('app', 'menuBlockerStaffCode', $staffCode, true);
            }
            if ($eventPasscode !== null) {
                $repository->upsert('app', 'eventEntryPasscode', $eventPasscode, true);
            }

            if (array_key_exists('menuBlockerPages', $settings)) {
                $pages = $this->normalizeBlockerPages(is_array($settings['menuBlockerPages'] ?? null) ? $settings['menuBlockerPages'] : []);
                $repository->upsert(self::BLOCKER_GROUP, self::BLOCKER_PAGES_KEY, json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            if (array_key_exists('spinBlockerConfig', $settings)) {
                $config = $this->normalizeBlockerConfig(is_array($settings['spinBlockerConfig'] ?? null) ? $settings['spinBlockerConfig'] : []);
                $repository->upsert(self::BLOCKER_GROUP, self::BLOCKER_CONFIG_KEY, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            if (array_key_exists('spinBlockerContent', $settings)) {
                $content = $this->normalizeBlockerContent(is_array($settings['spinBlockerContent'] ?? null) ? $settings['spinBlockerContent'] : []);
                $repository->upsert(self::BLOCKER_GROUP, self::BLOCKER_CONTENT_KEY, json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            if (array_key_exists('spinMilestoneScheme', $settings)) {
                $scheme = $this->normalizeSpinMilestoneScheme(is_array($settings['spinMilestoneScheme'] ?? null) ? $settings['spinMilestoneScheme'] : []);
                $repository->upsert(self::BLOCKER_GROUP, self::SPIN_MILESTONE_SCHEME_KEY, json_encode($scheme, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $updated = $this->getAppSettings();
            if (($updated['ok'] ?? false) !== true) {
                return $updated;
            }

            return [
                'ok' => true,
                'message' => 'Settings saved successfully.',
                'settings' => $updated['settings'],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'SETTINGS_SAVE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function getBlockerSettings(): array
    {
        try {
            $settingsPayload = $this->getAppSettings();
            if (($settingsPayload['ok'] ?? false) !== true) {
                return $settingsPayload;
            }

            $settings = is_array($settingsPayload['settings'] ?? null) ? $settingsPayload['settings'] : [];
            $pages = $this->normalizeBlockerPages(is_array($settings['menuBlockerPages'] ?? null) ? $settings['menuBlockerPages'] : []);
            $config = $this->normalizeBlockerConfig(is_array($settings['spinBlockerConfig'] ?? null) ? $settings['spinBlockerConfig'] : []);
            $content = $this->normalizeBlockerContent(is_array($settings['spinBlockerContent'] ?? null) ? $settings['spinBlockerContent'] : []);

            return [
                'ok' => true,
                'enabledPages' => $pages,
                'settings' => [
                    'menuBlockerPages' => $pages,
                    'spinBlockerConfig' => $config,
                    'spinBlockerContent' => $content,
                    'globalDisable' => !empty($config['globalDisable']),
                    'cooldownHours' => (int) ($config['cooldownHours'] ?? 24),
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'BLOCKER_SETTINGS_FETCH_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function verifyBlockerPasscode(string $passcode): array
    {
        $provided = strtoupper(trim($passcode));
        if ($provided === '') {
            return [
                'ok' => false,
                'error' => 'PASSCODE_REQUIRED',
                'message' => 'Passcode is required.',
            ];
        }

        try {
            $repository = new AppSettingRepository(Database::connection());
            $stored = strtoupper(trim((string) ($repository->getValue('app', 'menuBlockerStaffCode') ?? '')));

            if ($stored === '') {
                return [
                    'ok' => false,
                    'error' => 'PASSCODE_NOT_CONFIGURED',
                    'message' => 'Bypass passcode is not configured yet.',
                ];
            }

            if (!hash_equals($stored, $provided)) {
                return [
                    'ok' => false,
                    'error' => 'INVALID_PASSCODE',
                    'message' => 'Invalid passcode.',
                ];
            }

            return [
                'ok' => true,
                'result' => 'passcode_verified',
                'message' => 'Passcode verified.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'PASSCODE_VERIFY_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function verifyScannerPasscode(string $passcode): array
    {
        $provided = strtoupper(trim($passcode));
        if ($provided === '') {
            return [
                'ok' => false,
                'error' => 'PASSCODE_REQUIRED',
                'message' => 'Passcode is required.',
            ];
        }

        try {
            $repository = new AppSettingRepository(Database::connection());
            $stored = strtoupper(trim((string) ($repository->getValue('app', 'eventEntryPasscode') ?? '')));

            if ($stored === '') {
                return [
                    'ok' => false,
                    'error' => 'PASSCODE_NOT_CONFIGURED',
                    'message' => 'Event scanner passcode is not configured yet.',
                ];
            }

            if (!hash_equals($stored, $provided)) {
                return [
                    'ok' => false,
                    'error' => 'INVALID_PASSCODE',
                    'message' => 'Invalid passcode.',
                ];
            }

            return [
                'ok' => true,
                'verified' => true,
                'result' => 'passcode_verified',
                'message' => 'Passcode verified.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'PASSCODE_VERIFY_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function updateBlockerPages(array $payload): array
    {
        try {
            $settings = [];
            if (is_array($payload['pages'] ?? null)) {
                $settings['menuBlockerPages'] = $payload['pages'];
            }
            if (is_array($payload['spinBlockerConfig'] ?? null)) {
                $settings['spinBlockerConfig'] = $payload['spinBlockerConfig'];
            }
            if (is_array($payload['spinBlockerContent'] ?? null)) {
                $settings['spinBlockerContent'] = $payload['spinBlockerContent'];
            }

            if ($settings === []) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'No blocker settings payload was provided.',
                ];
            }

            $save = $this->setAppSettings($settings);
            if (($save['ok'] ?? false) !== true) {
                return $save;
            }

            return [
                'ok' => true,
                'message' => 'Blocker placement settings updated.',
                'settings' => $save['settings'] ?? [],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'BLOCKER_SETTINGS_SAVE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function getQrRedirectSettings(): array
    {
        try {
            $repository = new AppSettingRepository(Database::connection());
            $raw = $repository->getValue(self::QR_SETTINGS_GROUP, self::QR_SETTINGS_KEY);
            $settings = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
            if (!is_array($settings)) {
                $settings = [];
            }

            return [
                'ok' => true,
                'settings' => [
                    'defaultTargetUrl' => (string) ($settings['defaultTargetUrl'] ?? ''),
                    'fallbackSlug' => (string) ($settings['fallbackSlug'] ?? ''),
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'QR_SETTINGS_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function setQrRedirectSettings(array $settings): array
    {
        try {
            $payload = [
                'defaultTargetUrl' => trim((string) ($settings['defaultTargetUrl'] ?? '')),
                'fallbackSlug' => trim((string) ($settings['fallbackSlug'] ?? '')),
            ];

            $repository = new AppSettingRepository(Database::connection());
            $repository->upsert(self::QR_SETTINGS_GROUP, self::QR_SETTINGS_KEY, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => true,
                'message' => 'QR redirect settings saved.',
                'settings' => $payload,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'QR_SETTINGS_SAVE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function listQrRedirects(): array
    {
        try {
            $connection = Database::connection();
            $this->ensureDefaultQrRedirects($connection);
            $statement = $connection->query('SELECT id, slug, title, target_url, is_active, created_at, updated_at FROM qr_redirects ORDER BY id DESC');
            $rows = $statement->fetchAll();
            $redirects = array_map(static function (array $row): array {
                $row['isSystem'] = in_array((string) ($row['slug'] ?? ''), self::RESERVED_QR_REDIRECT_SLUGS, true);
                return $row;
            }, is_array($rows) ? $rows : []);

            return [
                'ok' => true,
                'redirects' => $redirects,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'QR_REDIRECTS_LIST_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function saveQrRedirect(array $data): array
    {
        try {
            $id = (int) ($data['id'] ?? 0);
            $slug = trim((string) ($data['slug'] ?? ''));
            $title = trim((string) ($data['title'] ?? ''));
            $targetUrl = trim((string) ($data['targetUrl'] ?? $data['target_url'] ?? ''));
            $isActive = !empty($data['isActive']) || !empty($data['is_active']);

            if ($slug === '' || $title === '' || $targetUrl === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Slug, title, and target URL are required.',
                ];
            }

            $connection = Database::connection();
            $this->ensureDefaultQrRedirects($connection);
            if ($id <= 0) {
                $existing = $this->findQrRedirectBySlug($slug);
                if (is_array($existing) && (int) ($existing['id'] ?? 0) > 0) {
                    $id = (int) ($existing['id'] ?? 0);
                }
            }

            if ($id > 0) {
                $statement = $connection->prepare('UPDATE qr_redirects SET slug = :slug, title = :title, target_url = :target_url, is_active = :is_active WHERE id = :id');
                $statement->execute([
                    'id' => $id,
                    'slug' => $slug,
                    'title' => $title,
                    'target_url' => $targetUrl,
                    'is_active' => $isActive ? 1 : 0,
                ]);
            } else {
                $statement = $connection->prepare('INSERT INTO qr_redirects (slug, title, target_url, is_active) VALUES (:slug, :title, :target_url, :is_active)');
                $statement->execute([
                    'slug' => $slug,
                    'title' => $title,
                    'target_url' => $targetUrl,
                    'is_active' => $isActive ? 1 : 0,
                ]);
                $id = (int) $connection->lastInsertId();
            }

            return [
                'ok' => true,
                'message' => 'QR redirect saved.',
                'id' => $id,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'QR_REDIRECT_SAVE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function setQrRedirectActive(int $id, bool $isActive): array
    {
        try {
            if ($id <= 0) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Valid redirect id is required.',
                ];
            }

            $row = $this->findQrRedirectById($id);
            if (is_array($row) && $this->isReservedQrSlug((string) ($row['slug'] ?? ''))) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Default QR redirects stay active.',
                ];
            }

            $statement = Database::connection()->prepare('UPDATE qr_redirects SET is_active = :is_active WHERE id = :id');
            $statement->execute([
                'id' => $id,
                'is_active' => $isActive ? 1 : 0,
            ]);

            return [
                'ok' => true,
                'message' => 'QR redirect status updated.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'QR_REDIRECT_STATUS_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function deleteQrRedirect(int $id): array
    {
        try {
            if ($id <= 0) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Valid redirect id is required.',
                ];
            }

            $row = $this->findQrRedirectById($id);
            if (is_array($row) && $this->isReservedQrSlug((string) ($row['slug'] ?? ''))) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Default QR redirects cannot be deleted.',
                ];
            }

            $statement = Database::connection()->prepare('DELETE FROM qr_redirects WHERE id = :id');
            $statement->execute(['id' => $id]);

            return [
                'ok' => true,
                'message' => 'QR redirect deleted.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'QR_REDIRECT_DELETE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function resolveQrRedirect(string $slug): array
    {
        try {
            $slug = trim($slug);
            $connection = Database::connection();
            $this->ensureDefaultQrRedirects($connection);

            if ($slug !== '') {
                $statement = $connection->prepare('SELECT * FROM qr_redirects WHERE slug = :slug AND is_active = 1 LIMIT 1');
                $statement->execute(['slug' => $slug]);
                $row = $statement->fetch();
                if (is_array($row)) {
                    $this->logQrScan($connection, (int) ($row['id'] ?? 0));
                    return [
                        'ok' => true,
                        'slug' => $slug,
                        'targetUrl' => (string) ($row['target_url'] ?? ''),
                        'title' => (string) ($row['title'] ?? ''),
                    ];
                }
            }

            $fallback = $this->getQrRedirectSettings();
            $settings = is_array($fallback['settings'] ?? null) ? $fallback['settings'] : [];
            return [
                'ok' => true,
                'slug' => $slug,
                'targetUrl' => (string) ($settings['defaultTargetUrl'] ?? ''),
                'title' => 'Fallback redirect',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'QR_REDIRECT_RESOLVE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function getWhatsappWorkspace(): array
    {
        try {
            $repository = new AppSettingRepository(Database::connection());
            $group = $repository->getGroup(self::WHATSAPP_GROUP);
            $events = $this->decodeEvents($repository->getValue(self::EVENTS_GROUP, self::EVENTS_KEY));
            $templates = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_TEMPLATES_KEY));
            $mappings = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_MAPPINGS_KEY));
            $drafts = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_DRAFTS_KEY));
            $logs = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_LOGS_KEY));
            $schedules = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_SCHEDULES_KEY));

            return [
                'ok' => true,
                'workspace' => [
                    'config' => [
                        'phoneNumberId' => (string) (($group['phoneNumberId']['value'] ?? '') ?: ''),
                        'businessAccountId' => (string) (($group['businessAccountId']['value'] ?? '') ?: ''),
                        'verifyToken' => (string) (($group['verifyToken']['value'] ?? '') ?: ''),
                        'accessTokenSet' => ((string) (($group['accessToken']['value'] ?? '') ?: '')) !== '',
                    ],
                    'events' => $events,
                    'templates' => $templates,
                    'mappings' => $mappings,
                    'drafts' => $drafts,
                    'logs' => $logs,
                    'schedules' => $schedules,
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'WHATSAPP_WORKSPACE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function setWhatsappConfig(array $config): array
    {
        try {
            $phoneNumberId = trim((string) ($config['phoneNumberId'] ?? ''));
            $businessAccountId = trim((string) ($config['businessAccountId'] ?? ''));
            $verifyToken = trim((string) ($config['verifyToken'] ?? ''));
            $accessToken = trim((string) ($config['accessToken'] ?? ''));

            $repository = new AppSettingRepository(Database::connection());
            $repository->upsert(self::WHATSAPP_GROUP, 'phoneNumberId', $phoneNumberId, false);
            $repository->upsert(self::WHATSAPP_GROUP, 'businessAccountId', $businessAccountId, false);
            $repository->upsert(self::WHATSAPP_GROUP, 'verifyToken', $verifyToken, true);

            if ($accessToken !== '') {
                $repository->upsert(self::WHATSAPP_GROUP, 'accessToken', $accessToken, true);
            }

            return [
                'ok' => true,
                'message' => 'WhatsApp configuration saved successfully.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'WHATSAPP_CONFIG_SAVE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function syncWhatsappTemplates(): array
    {
        try {
            $repository = new AppSettingRepository(Database::connection());
            $existing = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_TEMPLATES_KEY));

            $seedTemplates = [
                [
                    'uid' => 'tmpl_evt_confirm',
                    'name' => 'event_registration_confirmation',
                    'language' => 'en',
                    'category' => 'UTILITY',
                    'status' => 'APPROVED',
                    'quality' => 'GREEN',
                    'syncedAt' => (new DateTimeImmutable('now'))->format('c'),
                ],
                [
                    'uid' => 'tmpl_evt_reminder',
                    'name' => 'event_visit_reminder',
                    'language' => 'en',
                    'category' => 'UTILITY',
                    'status' => 'APPROVED',
                    'quality' => 'GREEN',
                    'syncedAt' => (new DateTimeImmutable('now'))->format('c'),
                ],
            ];

            $templates = $this->mergeTemplates($existing, $seedTemplates);
            $this->persistWhatsappArray($repository, self::WHATSAPP_TEMPLATES_KEY, $templates);

            return [
                'ok' => true,
                'message' => 'Templates synchronized successfully.',
                'templates' => $templates,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'WHATSAPP_SYNC_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function saveWhatsappMapping(array $mapping): array
    {
        try {
            $eventId = trim((string) ($mapping['eventId'] ?? ''));
            $templateUid = trim((string) ($mapping['templateUid'] ?? ''));
            $enabled = !empty($mapping['enabled']);
            $isTest = $this->isLikelyTestRecord([
                'eventId' => $eventId,
                'templateUid' => $templateUid,
            ]);

            if ($eventId === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Event is required for mapping.',
                ];
            }

            $repository = new AppSettingRepository(Database::connection());
            $mappings = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_MAPPINGS_KEY));
            $updated = false;

            foreach ($mappings as &$item) {
                if ((string) ($item['eventId'] ?? '') !== $eventId) {
                    continue;
                }

                $item['templateUid'] = $templateUid;
                $item['enabled'] = $enabled;
                $item['isTest'] = $isTest;
                $item['updatedAt'] = (new DateTimeImmutable('now'))->format('c');
                $updated = true;
                break;
            }
            unset($item);

            if (!$updated) {
                $mappings[] = [
                    'eventId' => $eventId,
                    'templateUid' => $templateUid,
                    'enabled' => $enabled,
                    'isTest' => $isTest,
                    'updatedAt' => (new DateTimeImmutable('now'))->format('c'),
                ];
            }

            $this->persistWhatsappArray($repository, self::WHATSAPP_MAPPINGS_KEY, $mappings);

            return [
                'ok' => true,
                'message' => 'Mapping saved successfully.',
                'mappings' => $mappings,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'WHATSAPP_MAPPING_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function saveWhatsappDraft(array $draft): array
    {
        try {
            $name = trim((string) ($draft['name'] ?? ''));
            $templateName = trim((string) ($draft['templateName'] ?? ''));
            $bodyText = trim((string) ($draft['bodyText'] ?? ''));
            $isTest = $this->isLikelyTestRecord([
                'name' => $name,
                'templateName' => $templateName,
                'bodyText' => $bodyText,
            ]);

            if ($name === '' || $templateName === '' || $bodyText === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Draft label, template name, and body text are required.',
                ];
            }

            $repository = new AppSettingRepository(Database::connection());
            $drafts = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_DRAFTS_KEY));
            $draftId = trim((string) ($draft['id'] ?? ''));
            $saved = false;

            foreach ($drafts as &$item) {
                if ($draftId === '' || (string) ($item['id'] ?? '') !== $draftId) {
                    continue;
                }

                $item['name'] = $name;
                $item['templateName'] = $templateName;
                $item['bodyText'] = $bodyText;
                $item['isTest'] = $isTest;
                $item['status'] = (string) ($item['status'] ?? 'draft');
                $item['updatedAt'] = (new DateTimeImmutable('now'))->format('c');
                $saved = true;
                break;
            }
            unset($item);

            if (!$saved) {
                $draftId = 'drf_' . bin2hex(random_bytes(5));
                $drafts[] = [
                    'id' => $draftId,
                    'name' => $name,
                    'templateName' => $templateName,
                    'bodyText' => $bodyText,
                    'isTest' => $isTest,
                    'status' => 'draft',
                    'updatedAt' => (new DateTimeImmutable('now'))->format('c'),
                ];
            }

            $this->persistWhatsappArray($repository, self::WHATSAPP_DRAFTS_KEY, $drafts);

            return [
                'ok' => true,
                'message' => 'Draft saved successfully.',
                'draftId' => $draftId,
                'drafts' => $drafts,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'WHATSAPP_DRAFT_SAVE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function submitWhatsappDraft(string $draftId): array
    {
        try {
            $draftId = trim($draftId);
            if ($draftId === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Draft id is required.',
                ];
            }

            $repository = new AppSettingRepository(Database::connection());
            $drafts = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_DRAFTS_KEY));
            $templates = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_TEMPLATES_KEY));

            $targetDraft = null;
            foreach ($drafts as &$item) {
                if ((string) ($item['id'] ?? '') !== $draftId) {
                    continue;
                }

                $item['status'] = 'submitted';
                $item['updatedAt'] = (new DateTimeImmutable('now'))->format('c');
                $targetDraft = $item;
                break;
            }
            unset($item);

            if (!is_array($targetDraft)) {
                return [
                    'ok' => false,
                    'error' => 'DRAFT_NOT_FOUND',
                    'message' => 'Draft was not found.',
                ];
            }

            $templates[] = [
                'uid' => 'tmpl_' . bin2hex(random_bytes(4)),
                'name' => (string) ($targetDraft['templateName'] ?? 'custom_template'),
                'language' => 'en',
                'category' => 'UTILITY',
                'status' => 'PENDING_REVIEW',
                'quality' => 'UNKNOWN',
                'isTest' => !empty($targetDraft['isTest']) || $this->isLikelyTestRecord($targetDraft),
                'syncedAt' => (new DateTimeImmutable('now'))->format('c'),
            ];

            $this->persistWhatsappArray($repository, self::WHATSAPP_DRAFTS_KEY, $drafts);
            $this->persistWhatsappArray($repository, self::WHATSAPP_TEMPLATES_KEY, $templates);

            return [
                'ok' => true,
                'message' => 'Draft submitted to Meta queue.',
                'drafts' => $drafts,
                'templates' => $templates,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'WHATSAPP_DRAFT_SUBMIT_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function runWhatsappScheduler(): array
    {
        try {
            $repository = new AppSettingRepository(Database::connection());
            $schedules = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_SCHEDULES_KEY));
            $logs = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_LOGS_KEY));

            $now = new DateTimeImmutable('now');
            $processed = 0;
            foreach ($schedules as &$schedule) {
                if (($schedule['status'] ?? 'pending') !== 'pending') {
                    continue;
                }

                $schedule['status'] = 'sent';
                $schedule['processedAt'] = $now->format('c');
                $processed++;

                $logs[] = [
                    'id' => 'log_' . bin2hex(random_bytes(4)),
                    'time' => $now->format('c'),
                    'eventId' => (string) ($schedule['eventId'] ?? ''),
                    'phone' => (string) ($schedule['phone'] ?? ''),
                    'templateUid' => (string) ($schedule['templateUid'] ?? ''),
                    'result' => 'sent',
                    'status' => 'ok',
                    'message' => 'Scheduled reminder sent.',
                    'isTest' => $this->isLikelyTestRecord($schedule),
                ];
            }
            unset($schedule);

            $this->persistWhatsappArray($repository, self::WHATSAPP_SCHEDULES_KEY, $schedules);
            $this->persistWhatsappArray($repository, self::WHATSAPP_LOGS_KEY, $logs);

            return [
                'ok' => true,
                'message' => 'Scheduler run complete.',
                'processed' => $processed,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'WHATSAPP_SCHEDULER_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function sendWhatsappTest(array $payload): array
    {
        try {
            $phone = $this->normalizePhone((string) ($payload['phone'] ?? ''));
            $eventId = trim((string) ($payload['eventId'] ?? ''));
            if (strlen($phone) < 10) {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Valid phone is required for test send.',
                ];
            }

            $repository = new AppSettingRepository(Database::connection());
            $mappings = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_MAPPINGS_KEY));
            $logs = $this->decodeJsonArray($repository->getValue(self::WHATSAPP_GROUP, self::WHATSAPP_LOGS_KEY));

            $mapping = null;
            foreach ($mappings as $item) {
                if ((string) ($item['eventId'] ?? '') === $eventId) {
                    $mapping = $item;
                    break;
                }
            }

            $enabled = is_array($mapping) && !empty($mapping['enabled']) && trim((string) ($mapping['templateUid'] ?? '')) !== '';
            $result = $enabled ? 'sent' : 'skipped';
            $message = $enabled ? 'Test WhatsApp message queued.' : 'No enabled mapping for selected event.';

            $logs[] = [
                'id' => 'log_' . bin2hex(random_bytes(4)),
                'time' => (new DateTimeImmutable('now'))->format('c'),
                'eventId' => $eventId,
                'phone' => substr($phone, -10),
                'templateUid' => is_array($mapping) ? (string) ($mapping['templateUid'] ?? '') : '',
                'result' => $result,
                'status' => $enabled ? 'ok' : 'warning',
                'message' => '[TEST] ' . $message,
                'isTest' => true,
            ];

            $this->persistWhatsappArray($repository, self::WHATSAPP_LOGS_KEY, $logs);

            return [
                'ok' => true,
                'message' => $message,
                'result' => $result,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'WHATSAPP_TEST_SEND_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function crmPanelStatus(): array
    {
        try {
            $connection = Database::connection();
            $contacts = (new ContactRepository($connection))->list([], 0, 1);
            $logs = (new CrmPushLogRepository($connection))->list([], 0, 1);
            $triggerPayload = (new CrmTriggerService())->listConfigs();
            $triggers = is_array($triggerPayload['triggers'] ?? null) ? $triggerPayload['triggers'] : [];
            $endpoint = trim((string) Env::getProfiled('CRM_API_ENDPOINT', ''));
            $token = trim((string) Env::getProfiled('CRM_API_TOKEN', ''));

            return [
                'ok' => true,
                'status' => $endpoint !== '' && $token !== '' ? 'ready' : 'not_configured',
                'configuration' => [
                    'endpointConfigured' => $endpoint !== '',
                    'tokenConfigured' => $token !== '',
                    'endpointHost' => $endpoint !== '' ? (string) (parse_url($endpoint, PHP_URL_HOST) ?: $endpoint) : '',
                ],
                'deployment' => [
                    'apiBase' => '/index.php?action=',
                    'panelAction' => 'admin_crm_panel_status',
                    'testAction' => 'admin_test_crm_sync',
                    'syncAction' => 'sync_crm_by_phone',
                ],
                'summary' => [
                    'contacts' => $this->countTable('crm_contacts'),
                    'pushLogs' => $this->countTable('crm_push_logs'),
                    'leads' => $this->countTable('leads'),
                    'triggers' => count($triggers),
                    'enabledTriggers' => count(array_filter($triggers, static fn ($trigger) => !empty($trigger['enabled']))),
                ],
                'contactsCount' => $this->countTable('crm_contacts'),
                'logsCount' => $this->countTable('crm_push_logs'),
                'latestContact' => $contacts[0] ?? null,
                'latestLog' => $logs[0] ?? null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_PANEL_STATUS_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function listCrmTriggerConfigs(): array
    {
        try {
            return (new CrmTriggerService())->listConfigs();
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_TRIGGER_CONFIGS_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function saveCrmTriggerConfig(array $payload): array
    {
        try {
            return (new CrmTriggerService())->saveConfig($payload);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_TRIGGER_CONFIG_SAVE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function testCrmTrigger(array $payload): array
    {
        try {
            return (new CrmTriggerService())->testTrigger($payload);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_TRIGGER_TEST_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function resetCrmTriggerToDefault(array $payload): array
    {
        try {
            return (new CrmTriggerService())->resetConfig((string) ($payload['triggerKey'] ?? $payload['key'] ?? ''));
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_TRIGGER_RESET_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function listCrmContacts(array $payload): array
    {
        try {
            $repository = new ContactRepository(Database::connection());
            $filters = $this->extractCrmWorkspaceFilters($payload);
            $page = max(1, (int) ($payload['page'] ?? 1));
            $pageSize = min(200, max(1, (int) ($payload['pageSize'] ?? $payload['limit'] ?? 25)));
            $total = $repository->count($filters);
            $pages = max(1, (int) ceil($total / $pageSize));
            $page = min($page, $pages);
            $contacts = $repository->list($filters, ($page - 1) * $pageSize, $pageSize);

            return [
                'ok' => true,
                'contacts' => $contacts,
                'count' => count($contacts),
                'pagination' => [
                    'page' => $page,
                    'pages' => $pages,
                    'total' => $total,
                    'pageSize' => $pageSize,
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_CONTACTS_LIST_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function listCrmPushLogs(array $payload): array
    {
        try {
            $repository = new CrmPushLogRepository(Database::connection());
            $filters = $this->extractCrmWorkspaceFilters($payload);
            $page = max(1, (int) ($payload['page'] ?? 1));
            $pageSize = min(200, max(1, (int) ($payload['pageSize'] ?? $payload['limit'] ?? 25)));
            $total = $repository->count($filters);
            $pages = max(1, (int) ceil($total / $pageSize));
            $page = min($page, $pages);
            $logs = $repository->list($filters, ($page - 1) * $pageSize, $pageSize);

            return [
                'ok' => true,
                'logs' => $logs,
                'count' => count($logs),
                'pagination' => [
                    'page' => $page,
                    'pages' => $pages,
                    'total' => $total,
                    'pageSize' => $pageSize,
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_LOGS_LIST_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function backfillCrmContacts(): array
    {
        try {
            $connection = Database::connection();
            $repository = new LeadRepository($connection);
            $contactRepository = new ContactRepository($connection);
            $leads = $repository->listLeadsForCrmBackfill(5000);
            $processed = 0;

            foreach ($leads as $lead) {
                if (!is_array($lead)) {
                    continue;
                }

                $phone = (string) ($lead['phone'] ?? '');
                if ($phone === '') {
                    continue;
                }

                $contactRepository->upsert([
                    'phone' => $phone,
                    'name' => (string) ($lead['name'] ?? ''),
                    'date_of_birth' => $lead['date_of_birth'] ?? null,
                    'date_of_anniversary' => $lead['date_of_anniversary'] ?? null,
                    'first_seen_at' => $lead['first_seen_at'] ?? $lead['created_at'] ?? date('Y-m-d H:i:s'),
                    'last_seen_at' => $lead['last_seen_at'] ?? $lead['created_at'] ?? date('Y-m-d H:i:s'),
                    'latest_source' => $lead['source'] ?? null,
                    'latest_lead_id' => (int) ($lead['id'] ?? 0),
                    'latest_lead_created_at' => $lead['created_at'] ?? null,
                    'total_submissions' => (int) ($lead['total_submissions'] ?? 1),
                    'latest_crm_sync_status' => $lead['crm_sync_status'] ?? null,
                    'latest_crm_sync_code' => $lead['crm_sync_code'] ?? null,
                    'latest_crm_sync_message' => $lead['crm_sync_message'] ?? null,
                    'last_crm_attempted_at' => null,
                    'last_crm_pushed_at' => null,
                ]);

                $processed++;
            }

            return [
                'ok' => true,
                'message' => 'CRM contacts backfill completed.',
                'processed' => $processed,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_BACKFILL_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function exportCrmContacts(array $payload): array
    {
        try {
            $repository = new ContactRepository(Database::connection());
            $rows = $repository->list($this->extractCrmWorkspaceFilters($payload), 0, 10000);
            $file = (new CrmContactExportService())->build($rows);

            return [
                'ok' => true,
                'rows' => $rows,
                'count' => count($rows),
                ...$file,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_EXPORT_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function crmLeadsStatus(array $payload): array
    {
        try {
            $repository = new LeadRepository(Database::connection());
            $summary = $repository->summarizeCrmLeads($this->extractCrmLeadFilters($payload));

            return [
                'ok' => true,
                'summary' => $summary,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_LEADS_STATUS_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function listCrmLeads(array $payload): array
    {
        try {
            $repository = new LeadRepository(Database::connection());
            $filters = $this->extractCrmLeadFilters($payload);

            $page = max(1, (int) ($payload['page'] ?? 1));
            $pageSize = min(200, max(1, (int) ($payload['pageSize'] ?? 50)));

            $total = $repository->countCrmLeads($filters);
            $pages = max(1, (int) ceil($total / $pageSize));
            $page = min($page, $pages);
            $offset = ($page - 1) * $pageSize;

            $rows = $repository->listCrmLeads($filters, $offset, $pageSize);

            return [
                'ok' => true,
                'leads' => array_map(fn (array $row): array => $this->mapCrmLeadRow($row), $rows),
                'pagination' => [
                    'page' => $page,
                    'pages' => $pages,
                    'total' => $total,
                    'pageSize' => $pageSize,
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_LEADS_LIST_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function exportCrmLeads(array $payload): array
    {
        try {
            $repository = new LeadRepository(Database::connection());
            $filters = $this->extractCrmLeadFilters($payload);
            $rows = $repository->listCrmLeads($filters, 0, 5000);
            $mappedRows = array_map(fn (array $row): array => $this->mapCrmLeadRow($row), $rows);
            $file = (new CrmLeadExportService())->build($mappedRows);

            return [
                'ok' => true,
                'rows' => $mappedRows,
                'count' => count($rows),
                ...$file,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CRM_LEADS_EXPORT_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function cashSummary(array $query, array $authUser): array
    {
        try {
            $repository = new AppSettingRepository(Database::connection());
            $transactions = $this->decodeJsonArray($repository->getValue(self::CASH_GROUP, self::CASH_TRANSACTIONS_KEY));
            $handoverRequests = $this->decodeJsonArray($repository->getValue(self::CASH_GROUP, self::CASH_HANDOVERS_KEY));
            $cancelRequests = $this->decodeJsonArray($repository->getValue(self::CASH_GROUP, self::CASH_CANCEL_REQUESTS_KEY));

            $ledgerDate = trim((string) ($query['ledgerDate'] ?? date('Y-m-d')));
            $eventId = trim((string) ($query['eventId'] ?? ''));
            $cashierUsername = strtolower(trim((string) ($authUser['username'] ?? $authUser['email'] ?? '')));

            $filteredTransactions = array_values(array_filter($transactions, static function (array $tx) use ($ledgerDate, $eventId, $cashierUsername): bool {
                if ($ledgerDate !== '' && (string) ($tx['ledgerDate'] ?? '') !== $ledgerDate) {
                    return false;
                }

                if ($eventId !== '' && (string) ($tx['eventId'] ?? '') !== $eventId) {
                    return false;
                }

                $issuedBy = strtolower((string) ($tx['issuedBy'] ?? ''));
                if ($cashierUsername !== '' && $issuedBy !== '' && $issuedBy !== $cashierUsername) {
                    return false;
                }

                return true;
            }));

            usort($filteredTransactions, static fn (array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));

            $todaySales = array_reduce($filteredTransactions, static function (float $carry, array $tx): float {
                if ((string) ($tx['status'] ?? 'active') !== 'active') {
                    return $carry;
                }

                return $carry + (float) ($tx['amount'] ?? 0);
            }, 0.0);

            $activeVisitors = array_reduce($filteredTransactions, static fn (int $carry, array $tx): int => $carry + max(1, (int) ($tx['qty'] ?? 1)), 0);
            $recentTransactions = array_slice($filteredTransactions, 0, 200);

            $pendingHandovers = array_values(array_filter($handoverRequests, static function (array $row) use ($ledgerDate, $cashierUsername): bool {
                if ((string) ($row['status'] ?? '') !== 'requested') {
                    return false;
                }

                if ($ledgerDate !== '' && (string) ($row['ledgerDate'] ?? '') !== $ledgerDate) {
                    return false;
                }

                $adminUsername = strtolower((string) ($row['adminUsername'] ?? ''));
                if ($cashierUsername !== '' && $adminUsername !== '' && $adminUsername !== $cashierUsername) {
                    return false;
                }

                return true;
            }));

            $pendingHandoverAmount = array_reduce($pendingHandovers, static fn (float $carry, array $row): float => $carry + (float) ($row['amount'] ?? 0), 0.0);

            $pendingCancelRequests = array_values(array_filter($cancelRequests, static function (array $row) use ($ledgerDate, $cashierUsername): bool {
                if ((string) ($row['status'] ?? '') !== 'requested') {
                    return false;
                }

                if ($ledgerDate !== '' && (string) ($row['ledgerDate'] ?? '') !== $ledgerDate) {
                    return false;
                }

                $adminUsername = strtolower((string) ($row['adminUsername'] ?? ''));
                if ($cashierUsername !== '' && $adminUsername !== '' && $adminUsername !== $cashierUsername) {
                    return false;
                }

                return true;
            }));

            $handoverHistory = array_values(array_filter($handoverRequests, static function (array $row) use ($cashierUsername): bool {
                if ((string) ($row['status'] ?? '') === 'requested') {
                    return false;
                }

                $adminUsername = strtolower((string) ($row['adminUsername'] ?? ''));
                if ($cashierUsername !== '' && $adminUsername !== '' && $adminUsername !== $cashierUsername) {
                    return false;
                }

                return true;
            }));

            usort($handoverHistory, static fn (array $a, array $b): int => strcmp((string) ($b['resolvedAt'] ?? $b['requestedAt'] ?? ''), (string) ($a['resolvedAt'] ?? $a['requestedAt'] ?? '')));

            return [
                'ok' => true,
                'summary' => [
                    'todaySales' => round($todaySales, 2),
                    'activeVisitors' => $activeVisitors,
                    'pendingHandoverAmount' => round($pendingHandoverAmount, 2),
                    'pendingCancelCount' => count($pendingCancelRequests),
                ],
                'recentTransactions' => $recentTransactions,
                'pendingHandovers' => $pendingHandovers,
                'pendingCancelRequests' => $pendingCancelRequests,
                'handoverHistory' => array_slice($handoverHistory, 0, 200),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CASH_SUMMARY_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function issueCashPaidPass(array $body, array $authUser): array
    {
        try {
            $eventId = trim((string) ($body['eventId'] ?? ''));
            $qty = max(1, (int) ($body['qty'] ?? 1));
            $customerName = trim((string) ($body['customerName'] ?? ''));
            $customerPhone = trim((string) ($body['customerPhone'] ?? ''));
            $customerEmail = trim((string) ($body['customerEmail'] ?? ''));
            $ledgerDate = trim((string) ($body['ledgerDate'] ?? date('Y-m-d')));
            $attendeeNamesRaw = trim((string) ($body['attendeeNames'] ?? ''));
            $notes = trim((string) ($body['notes'] ?? ''));

            if ($eventId === '' || $customerName === '' || $customerPhone === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Event, customer name, and customer phone are required.',
                ];
            }

            $repository = new AppSettingRepository(Database::connection());
            $events = $this->decodeEvents($repository->getValue(self::EVENTS_GROUP, self::EVENTS_KEY));
            $event = null;
            foreach ($events as $row) {
                if ((string) ($row['id'] ?? '') === $eventId) {
                    $event = $row;
                    break;
                }
            }

            if (!is_array($event)) {
                return [
                    'ok' => false,
                    'error' => 'EVENT_NOT_FOUND',
                    'message' => 'Selected event was not found.',
                ];
            }

            $ticketPrice = (float) ($event['ticketPrice'] ?? $body['ticketPrice'] ?? 0);
            $amount = round($ticketPrice * $qty, 2);
            $now = (new DateTimeImmutable('now'))->format('c');

            $attendees = [];
            if ($attendeeNamesRaw !== '') {
                $parts = preg_split('/\r\n|\r|\n|,/', $attendeeNamesRaw) ?: [];
                foreach ($parts as $part) {
                    $name = trim((string) $part);
                    if ($name !== '') {
                        $attendees[] = $name;
                    }
                }
            }

            $issuedBy = strtolower(trim((string) ($authUser['username'] ?? $authUser['email'] ?? '')));
            $transactions = $this->decodeJsonArray($repository->getValue(self::CASH_GROUP, self::CASH_TRANSACTIONS_KEY));
            $transaction = [
                'transactionId' => 'tx_' . bin2hex(random_bytes(5)),
                'eventId' => $eventId,
                'eventTitle' => (string) ($event['title'] ?? ''),
                'ledgerDate' => $ledgerDate,
                'customerName' => $customerName,
                'customerPhone' => $this->normalizePhone($customerPhone),
                'customerEmail' => $customerEmail,
                'qty' => $qty,
                'attendees' => $attendees,
                'notes' => $notes,
                'amount' => $amount,
                'status' => 'active',
                'handoverStatus' => 'none',
                'cancelStatus' => 'none',
                'issuedBy' => $issuedBy,
                'issuedByName' => trim((string) ($authUser['name'] ?? $authUser['username'] ?? '')),
                'issuedByUserId' => (int) ($authUser['id'] ?? 0),
                'createdAt' => $now,
            ];

            $transactions[] = $transaction;
            $repository->upsert(self::CASH_GROUP, self::CASH_TRANSACTIONS_KEY, json_encode($transactions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => true,
                'message' => 'Cash paid pass issued successfully.',
                'transaction' => $transaction,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CASH_ISSUE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function requestCashAction(array $body, array $authUser, string $action): array
    {
        try {
            $repository = new AppSettingRepository(Database::connection());
            $transactions = $this->decodeJsonArray($repository->getValue(self::CASH_GROUP, self::CASH_TRANSACTIONS_KEY));
            $handoverRequests = $this->decodeJsonArray($repository->getValue(self::CASH_GROUP, self::CASH_HANDOVERS_KEY));
            $cancelRequests = $this->decodeJsonArray($repository->getValue(self::CASH_GROUP, self::CASH_CANCEL_REQUESTS_KEY));

            $username = strtolower(trim((string) ($authUser['username'] ?? $authUser['email'] ?? '')));
            $ledgerDate = trim((string) ($body['ledgerDate'] ?? date('Y-m-d')));

            if ($action === 'admin_request_cash_handover') {
                $eligible = [];
                $total = 0.0;
                foreach ($transactions as $transaction) {
                    if ((string) ($transaction['ledgerDate'] ?? '') !== $ledgerDate) {
                        continue;
                    }

                    $issuedBy = strtolower((string) ($transaction['issuedBy'] ?? ''));
                    if ($username !== '' && $issuedBy !== '' && $issuedBy !== $username) {
                        continue;
                    }

                    if ((string) ($transaction['status'] ?? 'active') !== 'active') {
                        continue;
                    }

                    if ((string) ($transaction['handoverStatus'] ?? 'none') !== 'none') {
                        continue;
                    }

                    $eligible[] = (string) ($transaction['transactionId'] ?? '');
                    $total += (float) ($transaction['amount'] ?? 0);
                }

                if ($eligible === []) {
                    return [
                        'ok' => false,
                        'error' => 'NO_ELIGIBLE_TRANSACTIONS',
                        'message' => 'No eligible transactions found for handover.',
                    ];
                }

                $batchKey = 'hnd_' . bin2hex(random_bytes(4));
                $handoverRequests[] = [
                    'batchKey' => $batchKey,
                    'adminUsername' => $username,
                    'ledgerDate' => $ledgerDate,
                    'amount' => round($total, 2),
                    'transactionIds' => $eligible,
                    'status' => 'requested',
                    'requestedAt' => (new DateTimeImmutable('now'))->format('c'),
                    'requestedByUserId' => (int) ($authUser['id'] ?? 0),
                    'requestedByName' => trim((string) ($authUser['name'] ?? $authUser['username'] ?? '')),
                ];

                foreach ($transactions as &$transaction) {
                    if (!in_array((string) ($transaction['transactionId'] ?? ''), $eligible, true)) {
                        continue;
                    }

                    $transaction['handoverStatus'] = 'requested';
                    $transaction['handoverBatchKey'] = $batchKey;
                }
                unset($transaction);

                $repository->upsert(self::CASH_GROUP, self::CASH_TRANSACTIONS_KEY, json_encode($transactions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $repository->upsert(self::CASH_GROUP, self::CASH_HANDOVERS_KEY, json_encode($handoverRequests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return [
                    'ok' => true,
                    'message' => 'Cash handover request submitted.',
                    'batchKey' => $batchKey,
                    'amount' => round($total, 2),
                ];
            }

            $transactionId = trim((string) ($body['transactionId'] ?? ''));
            $reason = trim((string) ($body['reason'] ?? $body['note'] ?? ''));
            if ($transactionId === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Transaction id is required.',
                ];
            }

            $target = null;
            foreach ($transactions as &$transaction) {
                if ((string) ($transaction['transactionId'] ?? '') !== $transactionId) {
                    continue;
                }

                $transaction['cancelStatus'] = 'requested';
                $target = $transaction;
                break;
            }
            unset($transaction);

            if (!is_array($target)) {
                return [
                    'ok' => false,
                    'error' => 'TRANSACTION_NOT_FOUND',
                    'message' => 'Transaction was not found.',
                ];
            }

            $cancelRequests[] = [
                'id' => 'ccr_' . bin2hex(random_bytes(5)),
                'transactionId' => $transactionId,
                'eventId' => (string) ($target['eventId'] ?? ''),
                'eventTitle' => (string) ($target['eventTitle'] ?? ''),
                'ledgerDate' => (string) ($target['ledgerDate'] ?? $ledgerDate),
                'amount' => (float) ($target['amount'] ?? 0),
                'reason' => $reason,
                'status' => 'requested',
                'adminUsername' => strtolower((string) ($target['issuedBy'] ?? $username)),
                'requestedAt' => (new DateTimeImmutable('now'))->format('c'),
                'requestedByUserId' => (int) ($authUser['id'] ?? 0),
                'requestedByName' => trim((string) ($authUser['name'] ?? $authUser['username'] ?? '')),
            ];

            $repository->upsert(self::CASH_GROUP, self::CASH_TRANSACTIONS_KEY, json_encode($transactions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $repository->upsert(self::CASH_GROUP, self::CASH_CANCEL_REQUESTS_KEY, json_encode($cancelRequests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => true,
                'message' => 'Cancel request submitted.',
                'transactionId' => $transactionId,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CASH_REQUEST_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function resolveCashAction(array $body, array $authUser, string $action): array
    {
        try {
            $repository = new AppSettingRepository(Database::connection());
            $transactions = $this->decodeJsonArray($repository->getValue(self::CASH_GROUP, self::CASH_TRANSACTIONS_KEY));
            $handoverRequests = $this->decodeJsonArray($repository->getValue(self::CASH_GROUP, self::CASH_HANDOVERS_KEY));
            $cancelRequests = $this->decodeJsonArray($repository->getValue(self::CASH_GROUP, self::CASH_CANCEL_REQUESTS_KEY));

            $resolverName = trim((string) ($authUser['name'] ?? $authUser['username'] ?? ''));
            $resolverId = (int) ($authUser['id'] ?? 0);
            $now = (new DateTimeImmutable('now'))->format('c');

            if ($action === 'superadmin_approve_cash_handover') {
                $batchKey = trim((string) ($body['batchKey'] ?? ''));
                $adminUsername = strtolower(trim((string) ($body['adminUsername'] ?? '')));
                $ledgerDate = trim((string) ($body['ledgerDate'] ?? date('Y-m-d')));
                $updated = false;

                foreach ($handoverRequests as &$request) {
                    if ($batchKey !== '' && (string) ($request['batchKey'] ?? '') !== $batchKey) {
                        continue;
                    }

                    if ($batchKey === '' && $adminUsername !== '' && strtolower((string) ($request['adminUsername'] ?? '')) !== $adminUsername) {
                        continue;
                    }

                    if ($batchKey === '' && $ledgerDate !== '' && (string) ($request['ledgerDate'] ?? '') !== $ledgerDate) {
                        continue;
                    }

                    if ((string) ($request['status'] ?? '') !== 'requested') {
                        continue;
                    }

                    $request['status'] = 'approved';
                    $request['resolvedAt'] = $now;
                    $request['resolvedByUserId'] = $resolverId;
                    $request['resolvedByName'] = $resolverName;
                    $updated = true;

                    $ids = is_array($request['transactionIds'] ?? null) ? $request['transactionIds'] : [];
                    foreach ($transactions as &$tx) {
                        if (!in_array((string) ($tx['transactionId'] ?? ''), $ids, true)) {
                            continue;
                        }

                        $tx['handoverStatus'] = 'approved';
                    }
                    unset($tx);

                    break;
                }
                unset($request);

                if (!$updated) {
                    return [
                        'ok' => false,
                        'error' => 'REQUEST_NOT_FOUND',
                        'message' => 'No pending handover request found.',
                    ];
                }

                $repository->upsert(self::CASH_GROUP, self::CASH_TRANSACTIONS_KEY, json_encode($transactions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $repository->upsert(self::CASH_GROUP, self::CASH_HANDOVERS_KEY, json_encode($handoverRequests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return [
                    'ok' => true,
                    'message' => 'Handover approved successfully.',
                ];
            }

            $transactionId = trim((string) ($body['transactionId'] ?? ''));
            $decision = strtolower(trim((string) ($body['decision'] ?? 'reject')));
            $note = trim((string) ($body['note'] ?? ''));
            if ($transactionId === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Transaction id is required.',
                ];
            }

            $updatedCancel = false;
            foreach ($cancelRequests as &$request) {
                if ((string) ($request['transactionId'] ?? '') !== $transactionId) {
                    continue;
                }

                if ((string) ($request['status'] ?? '') !== 'requested') {
                    continue;
                }

                $request['status'] = $decision === 'approve' ? 'approved' : 'rejected';
                $request['resolvedAt'] = $now;
                $request['resolvedByUserId'] = $resolverId;
                $request['resolvedByName'] = $resolverName;
                $request['resolveNote'] = $note;
                $updatedCancel = true;
                break;
            }
            unset($request);

            if (!$updatedCancel) {
                return [
                    'ok' => false,
                    'error' => 'REQUEST_NOT_FOUND',
                    'message' => 'Pending cancel request was not found.',
                ];
            }

            foreach ($transactions as &$transaction) {
                if ((string) ($transaction['transactionId'] ?? '') !== $transactionId) {
                    continue;
                }

                if ($decision === 'approve') {
                    $transaction['status'] = 'cancelled';
                }
                $transaction['cancelStatus'] = $decision === 'approve' ? 'approved' : 'rejected';
                break;
            }
            unset($transaction);

            $repository->upsert(self::CASH_GROUP, self::CASH_TRANSACTIONS_KEY, json_encode($transactions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $repository->upsert(self::CASH_GROUP, self::CASH_CANCEL_REQUESTS_KEY, json_encode($cancelRequests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => true,
                'message' => $decision === 'approve' ? 'Cancel request approved.' : 'Cancel request rejected.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CASH_RESOLVE_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function superadminCashDashboard(array $query): array
    {
        try {
            $repository = new AppSettingRepository(Database::connection());
            $handoverRequests = $this->decodeJsonArray($repository->getValue(self::CASH_GROUP, self::CASH_HANDOVERS_KEY));
            $cancelRequests = $this->decodeJsonArray($repository->getValue(self::CASH_GROUP, self::CASH_CANCEL_REQUESTS_KEY));

            $ledgerDate = trim((string) ($query['ledgerDate'] ?? date('Y-m-d')));
            $pendingHandovers = array_values(array_filter($handoverRequests, static function (array $row) use ($ledgerDate): bool {
                if ((string) ($row['status'] ?? '') !== 'requested') {
                    return false;
                }

                if ($ledgerDate !== '' && (string) ($row['ledgerDate'] ?? '') !== $ledgerDate) {
                    return false;
                }

                return true;
            }));

            $pendingCancelRequests = array_values(array_filter($cancelRequests, static function (array $row) use ($ledgerDate): bool {
                if ((string) ($row['status'] ?? '') !== 'requested') {
                    return false;
                }

                if ($ledgerDate !== '' && (string) ($row['ledgerDate'] ?? '') !== $ledgerDate) {
                    return false;
                }

                return true;
            }));

            $recentApprovals = array_values(array_filter($handoverRequests, static fn (array $row): bool => (string) ($row['status'] ?? '') === 'approved'));
            usort($recentApprovals, static fn (array $a, array $b): int => strcmp((string) ($b['resolvedAt'] ?? ''), (string) ($a['resolvedAt'] ?? '')));

            return [
                'ok' => true,
                'summary' => [
                    'pendingHandoverCount' => count($pendingHandovers),
                    'pendingCancelCount' => count($pendingCancelRequests),
                    'approvedTodayCount' => count(array_filter($recentApprovals, static fn (array $row): bool => strpos((string) ($row['resolvedAt'] ?? ''), date('Y-m-d')) === 0)),
                ],
                'pendingHandovers' => $pendingHandovers,
                'pendingCancelRequests' => $pendingCancelRequests,
                'recentApprovals' => array_slice($recentApprovals, 0, 200),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'CASH_DASHBOARD_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function checkinEventQr(string $eventId, string $guestToken, bool $markCheckedIn): array
    {
        try {
            $eventId = trim($eventId);
            $guestToken = trim($guestToken);
            if ($eventId === '' || $guestToken === '') {
                return [
                    'ok' => false,
                    'error' => 'VALIDATION_ERROR',
                    'message' => 'Event id and guest token are required.',
                ];
            }

            $repository = new AppSettingRepository(Database::connection());
            $key = 'event_guests_' . $eventId;
            $guests = $this->decodeJsonArray($repository->getValue(self::EVENTS_GROUP, $key));
            $guest = null;

            foreach ($guests as &$item) {
                if ((string) ($item['token'] ?? '') !== $guestToken) {
                    continue;
                }

                if ($markCheckedIn) {
                    $item['checkedIn'] = true;
                    $item['checkedInAt'] = (new DateTimeImmutable('now'))->format('c');
                }

                $guest = $item;
                break;
            }
            unset($item);

            if (!is_array($guest)) {
                $guest = [
                    'token' => $guestToken,
                    'name' => 'Walk-in Guest',
                    'checkedIn' => $markCheckedIn,
                    'checkedInAt' => $markCheckedIn ? (new DateTimeImmutable('now'))->format('c') : null,
                ];
                $guests[] = $guest;
            }

            $repository->upsert(self::EVENTS_GROUP, $key, json_encode($guests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => true,
                'eventId' => $eventId,
                'guest' => $guest,
                'message' => $markCheckedIn ? 'Guest checked in.' : 'Guest QR preview ready.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => 'EVENT_QR_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function logQrScan(PDO $connection, int $redirectId): void
    {
        if ($redirectId <= 0) {
            return;
        }

        $statement = $connection->prepare('INSERT INTO qr_scans (qr_redirect_id, ip_address, user_agent, referer_url) VALUES (:qr_redirect_id, :ip_address, :user_agent, :referer_url)');
        $statement->execute([
            'qr_redirect_id' => $redirectId,
            'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'referer_url' => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
        ]);
    }

    private function countTable(string $table): int
    {
        $statement = Database::connection()->query('SELECT COUNT(*) AS total FROM ' . $table);
        return (int) $statement->fetchColumn();
    }

    private function ensureDefaultEvents(AppSettingRepository $repository): array
    {
        $raw = $repository->getValue(self::EVENTS_GROUP, self::EVENTS_KEY);
        $events = $this->sanitizeEvents($this->decodeEvents($raw));

        if ($events !== []) {
            return $events;
        }

        if (is_string($raw) && trim($raw) !== '' && trim($raw) === '[]') {
            // Persisted but intentionally empty list should remain empty.
            return [];
        }

        $events = $this->defaultEvents();
        $repository->upsert(self::EVENTS_GROUP, self::EVENTS_KEY, json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $events;
    }

    private function defaultEvents(): array
    {
        return $this->mergeSettingsDemoEvents([]);
    }

    private function sanitizeEvents(array $events): array
    {
        $filtered = array_values(array_filter($events, static function (array $event): bool {
            $title = strtolower(trim((string) ($event['title'] ?? '')));
            if ($title === '') {
                return true;
            }

            if (str_contains($title, 'dummy test event')) {
                return false;
            }

            if (preg_match('/(^|[^a-z])(alpha|beta)([^a-z]|$)/i', $title) === 1) {
                return false;
            }

            return true;
        }));

        return $filtered;
    }

    private function mergeSettingsDemoEvents(array $events): array
    {
        $merged = $events;
        $seedById = [];
        foreach ($this->settingsSeasonalDemoEvents() as $seed) {
            $seedById[(string) ($seed['id'] ?? '')] = $seed;
        }

        foreach ($seedById as $id => $seed) {
            if ($id === '') {
                continue;
            }

            $exists = false;
            foreach ($merged as $existing) {
                if ((string) ($existing['id'] ?? '') === $id) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $merged[] = $seed;
            }
        }

        return $merged;
    }

    private function settingsSeasonalDemoEvents(): array
    {
        $year = (int) (new DateTimeImmutable('now'))->format('Y');
        $updatedAt = (new DateTimeImmutable('now'))->format('c');

        return [
            [
                'id' => 'evt_live_wok_fire_fest',
                'slug' => 'wok-fire-fest-evt-live-wok-fire-fest',
                'eventSlug' => 'wok-fire-fest-evt-live-wok-fire-fest',
                'event_slug' => 'wok-fire-fest-evt-live-wok-fire-fest',
                'title' => 'Wok Fire Fest',
                'date' => sprintf('%04d-05-18', $year),
                'time' => '19:00',
                'description' => 'High-heat wok theatrics, chef tasting platters, and live music sets.',
                'subtitle' => 'May Nights Special',
                'eventType' => 'free',
                'ticketPrice' => 0,
                'badgeText' => 'MAY SPECIAL',
                'venue' => 'Asian Wok & Grill, Gangapur Rd, Nashik',
                'imageUrl' => 'https://storage.files-vault.com/uploads/1768797343-HN4FZc9djJ.webp',
                'image_url' => 'https://storage.files-vault.com/uploads/1768797343-HN4FZc9djJ.webp',
                'isActive' => true,
                'isDemo' => true,
                'updatedAt' => $updatedAt,
            ],
            [
                'id' => 'evt_live_dimsum_brunch_club',
                'slug' => 'dimsum-brunch-club-evt-live-dimsum-brunch-club',
                'eventSlug' => 'dimsum-brunch-club-evt-live-dimsum-brunch-club',
                'event_slug' => 'dimsum-brunch-club-evt-live-dimsum-brunch-club',
                'title' => 'Dim Sum Brunch Club',
                'date' => sprintf('%04d-05-26', $year),
                'time' => '12:30',
                'description' => 'Weekend dim sum baskets, tea pairings, and family combo tasting menu.',
                'subtitle' => 'Sunday Brunch Edition',
                'eventType' => 'free',
                'ticketPrice' => 0,
                'badgeText' => 'BRUNCH',
                'venue' => 'Asian Wok & Grill, Gangapur Rd, Nashik',
                'imageUrl' => 'https://storage.files-vault.com/uploads/1768797431-U8tZph1H4Q.webp',
                'image_url' => 'https://storage.files-vault.com/uploads/1768797431-U8tZph1H4Q.webp',
                'isActive' => true,
                'isDemo' => true,
                'updatedAt' => $updatedAt,
            ],
            [
                'id' => 'evt_live_monsoon_mocktail_lab',
                'slug' => 'monsoon-mocktail-lab-evt-live-monsoon-mocktail-lab',
                'eventSlug' => 'monsoon-mocktail-lab-evt-live-monsoon-mocktail-lab',
                'event_slug' => 'monsoon-mocktail-lab-evt-live-monsoon-mocktail-lab',
                'title' => 'Monsoon Mocktail Lab',
                'date' => sprintf('%04d-06-08', $year),
                'time' => '18:30',
                'description' => 'Signature mocktail flights with small-plate pairings and bartender demos.',
                'subtitle' => 'June Flavor Lab',
                'eventType' => 'free',
                'ticketPrice' => 0,
                'badgeText' => 'JUNE LIVE',
                'venue' => 'Asian Wok & Grill, Gangapur Rd, Nashik',
                'imageUrl' => 'https://storage.files-vault.com/uploads/1768797625-EllydznoIM.JPG',
                'image_url' => 'https://storage.files-vault.com/uploads/1768797625-EllydznoIM.JPG',
                'isActive' => true,
                'isDemo' => true,
                'updatedAt' => $updatedAt,
            ],
            [
                'id' => 'evt_live_sushi_social_evening',
                'slug' => 'sushi-social-evening-evt-live-sushi-social-evening',
                'eventSlug' => 'sushi-social-evening-evt-live-sushi-social-evening',
                'event_slug' => 'sushi-social-evening-evt-live-sushi-social-evening',
                'title' => 'Sushi Social Evening',
                'date' => sprintf('%04d-06-22', $year),
                'time' => '20:00',
                'description' => 'Curated sushi rolls, chef interaction counter, and plated tasting rounds.',
                'subtitle' => 'Chef Showcase Night',
                'eventType' => 'free',
                'ticketPrice' => 0,
                'badgeText' => 'LIVE EVENT',
                'venue' => 'Asian Wok & Grill, Gangapur Rd, Nashik',
                'imageUrl' => 'https://storage.files-vault.com/uploads/1768797743-s1dqwybMab.webp',
                'image_url' => 'https://storage.files-vault.com/uploads/1768797743-s1dqwybMab.webp',
                'isActive' => true,
                'isDemo' => true,
                'updatedAt' => $updatedAt,
            ],
        ];
    }

    private function upsertEventSlugRedirects(array $events): void
    {
        $seenSlugs = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $isActive = !array_key_exists('isActive', $event) || !empty($event['isActive']);
            if (!$isActive) {
                continue;
            }

            $eventId = trim((string) ($event['id'] ?? ''));
            if ($eventId === '') {
                continue;
            }

            $eventTitle = trim((string) ($event['title'] ?? 'Event'));
            $existingSlug = trim((string) ($event['slug'] ?? $event['eventSlug'] ?? $event['event_slug'] ?? ''));
            $slug = $this->buildEventSlug($existingSlug !== '' ? $existingSlug : $eventTitle, $eventId);
            if (in_array($slug, $seenSlugs, true)) {
                continue;
            }
            $seenSlugs[] = $slug;
            $targetUrl = '/events.html?eventSlug=' . rawurlencode($slug) . '#register';

            $result = $this->saveQrRedirect([
                'slug' => $slug,
                'title' => $eventTitle !== '' ? ($eventTitle . ' Event Page') : 'Event Page',
                'targetUrl' => $targetUrl,
                'isActive' => true,
            ]);

            if (($result['ok'] ?? false) !== true) {
                throw new \RuntimeException((string) ($result['message'] ?? 'Failed to sync event slug redirect.'));
            }
        }
    }

    private function buildEventSlug(string $raw, string $eventId = ''): string
    {
        $source = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $source) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'event';
        }

        $eventSuffix = strtolower(trim($eventId));
        $eventSuffix = preg_replace('/[^a-z0-9]+/i', '-', $eventSuffix) ?? '';
        $eventSuffix = trim($eventSuffix, '-');
        if ($eventSuffix !== '' && !str_ends_with($slug, $eventSuffix)) {
            $slug .= '-' . $eventSuffix;
        }

        return substr($slug, 0, 180);
    }

    private function ensureDefaultQrRedirects(PDO $connection): void
    {
        foreach ($this->defaultQrRedirects() as $row) {
            $statement = $connection->prepare('INSERT INTO qr_redirects (slug, title, target_url, is_active) VALUES (:slug, :title, :target_url, :is_active) ON DUPLICATE KEY UPDATE title = VALUES(title), target_url = VALUES(target_url), is_active = VALUES(is_active)');
            $statement->execute([
                'slug' => $row['slug'],
                'title' => $row['title'],
                'target_url' => $row['target_url'],
                'is_active' => $row['is_active'] ? 1 : 0,
            ]);
        }
    }

    private function defaultQrRedirects(): array
    {
        return [
            [
                'slug' => 'guest-login',
                'title' => 'Guest Login QR',
                'target_url' => '/menu.html',
                'is_active' => true,
            ],
            [
                'slug' => 'admin-login',
                'title' => 'Admin Login QR',
                'target_url' => '/admin/login.html',
                'is_active' => true,
            ],
        ];
    }

    private function isReservedQrSlug(string $slug): bool
    {
        return in_array(trim($slug), self::RESERVED_QR_REDIRECT_SLUGS, true);
    }

    private function findQrRedirectById(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT id, slug, title, target_url, is_active FROM qr_redirects WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function findQrRedirectBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $statement = Database::connection()->prepare('SELECT id, slug, title, target_url, is_active FROM qr_redirects WHERE slug = :slug LIMIT 1');
        $statement->execute(['slug' => $slug]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function sanitizeSpinOffers(array $rows): array
    {
        $offers = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $weight = (float) ($row['weight'] ?? 0);
            $isActive = !array_key_exists('isActive', $row) || !empty($row['isActive']);
            if ($label === '' || $weight <= 0 || !$isActive) {
                continue;
            }

            $offers[] = [
                'id' => trim((string) ($row['id'] ?? '')) !== '' ? (string) $row['id'] : 'off_' . bin2hex(random_bytes(4)),
                'label' => $label,
                'weight' => round($weight, 2),
                'hasCoupon' => !empty($row['hasCoupon']),
                'couponPrefix' => trim((string) ($row['couponPrefix'] ?? 'AWG')),
                'color' => trim((string) ($row['color'] ?? '#B89355')),
                'isActive' => true,
            ];
        }

        return array_values($offers);
    }

    private function defaultSpinOffers(): array
    {
        return [
            [
                'id' => 'off_try_again',
                'label' => 'Try Again',
                'weight' => 40,
                'hasCoupon' => false,
                'couponPrefix' => 'AWG',
                'color' => '#8C3B3B',
                'isActive' => true,
            ],
            [
                'id' => 'off_mocktail',
                'label' => 'Free Mocktail',
                'weight' => 24,
                'hasCoupon' => true,
                'couponPrefix' => 'MOCK',
                'color' => '#D09752',
                'isActive' => true,
            ],
            [
                'id' => 'off_maincourse',
                'label' => '10% Off Main Course',
                'weight' => 21,
                'hasCoupon' => true,
                'couponPrefix' => 'MAIN',
                'color' => '#C7A46B',
                'isActive' => true,
            ],
            [
                'id' => 'off_dessert',
                'label' => 'Dessert Shot',
                'weight' => 15,
                'hasCoupon' => true,
                'couponPrefix' => 'SWEET',
                'color' => '#6D2B2B',
                'isActive' => true,
            ],
        ];
    }

    private function decodeEvents(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn ($event): bool => is_array($event)));
    }

    private function decodeJsonObject($raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeBlockerPages(array $pages): array
    {
        return [
            'home' => array_key_exists('home', $pages) ? $this->toBooleanSetting($pages['home']) : true,
            'menu' => array_key_exists('menu', $pages) ? $this->toBooleanSetting($pages['menu']) : true,
            'cocktail' => array_key_exists('cocktail', $pages) ? $this->toBooleanSetting($pages['cocktail']) : true,
        ];
    }

    private function toBooleanSetting($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'off' || $normalized === 'no') {
                return false;
            }

            if ($normalized === '1' || $normalized === 'true' || $normalized === 'on' || $normalized === 'yes') {
                return true;
            }
        }

        return !empty($value);
    }

    private function normalizeBlockerConfig(array $config): array
    {
        return [
            'globalDisable' => !empty($config['globalDisable']),
            'staffBypassEnabled' => array_key_exists('staffBypassEnabled', $config) ? !empty($config['staffBypassEnabled']) : true,
            'cooldownHours' => max(1, min(72, (int) ($config['cooldownHours'] ?? 24))),
        ];
    }

    private function normalizeBlockerContent(array $content): array
    {
        return [
            'heading' => trim((string) ($content['heading'] ?? 'Spin & Unlock Your Offer')),
            'subheading' => trim((string) ($content['subheading'] ?? 'Complete a quick form and spin once every 24 hours.')),
            'submitButtonText' => trim((string) ($content['submitButtonText'] ?? 'Continue to Spin')),
            'spinButtonText' => trim((string) ($content['spinButtonText'] ?? 'Spin Now')),
            'continueButtonText' => trim((string) ($content['continueButtonText'] ?? 'Continue')),
        ];
    }

    private function normalizeSpinMilestoneScheme(array $scheme): array
    {
        $enabled = !array_key_exists('enabled', $scheme) || !empty($scheme['enabled']);
        $tiersRaw = is_array($scheme['tiers'] ?? null) ? $scheme['tiers'] : [];
        $tiers = [];

        foreach ($tiersRaw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $interval = (int) ($row['interval'] ?? 0);
            $label = trim((string) ($row['label'] ?? ''));
            if ($interval <= 0 || $label === '') {
                continue;
            }

            $tiers[] = [
                'interval' => $interval,
                'label' => $label,
                'hasCoupon' => !empty($row['hasCoupon']),
                'couponPrefix' => trim((string) ($row['couponPrefix'] ?? 'AWG')) ?: 'AWG',
                'variant' => strtolower(trim((string) ($row['variant'] ?? 'any'))),
                'priority' => (int) ($row['priority'] ?? $interval),
            ];
        }

        if ($tiers === []) {
            $tiers = [
                ['interval' => 100, 'label' => '5 FREE LUNCH BUFFET', 'hasCoupon' => true, 'couponPrefix' => 'LUNCH', 'variant' => 'any', 'priority' => 100],
                ['interval' => 75, 'label' => '2 FREE DINNER BUFFET', 'hasCoupon' => true, 'couponPrefix' => 'DINNER', 'variant' => 'any', 'priority' => 75],
                ['interval' => 50, 'label' => '2 FREE LUNCH BUFFET', 'hasCoupon' => true, 'couponPrefix' => 'LUNCH', 'variant' => 'any', 'priority' => 50],
                ['interval' => 25, 'label' => '1 FREE APPETIZER', 'hasCoupon' => true, 'couponPrefix' => 'APP', 'variant' => 'any', 'priority' => 25],
                ['interval' => 19, 'label' => '1 MOCKTAIL FREE', 'hasCoupon' => true, 'couponPrefix' => 'MOCK', 'variant' => 'odd', 'priority' => 19],
                ['interval' => 19, 'label' => '10% OFF UPTO 100', 'hasCoupon' => true, 'couponPrefix' => 'DISC', 'variant' => 'even', 'priority' => 19],
            ];
        }

        usort($tiers, static function (array $a, array $b): int {
            return (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0);
        });

        return [
            'enabled' => $enabled,
            'tiers' => array_values($tiers),
        ];
    }

    private function decodeJsonArray(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn ($row): bool => is_array($row)));
    }

    private function persistWhatsappArray(AppSettingRepository $repository, string $key, array $data): void
    {
        $repository->upsert(
            self::WHATSAPP_GROUP,
            $key,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            false
        );
    }

    private function isLikelyTestRecord(array $row): bool
    {
        $fields = [
            (string) ($row['eventId'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['templateName'] ?? ''),
            (string) ($row['templateUid'] ?? ''),
            (string) ($row['bodyText'] ?? ''),
            (string) ($row['message'] ?? ''),
            (string) ($row['phone'] ?? ''),
        ];

        foreach ($fields as $field) {
            $value = strtolower(trim($field));
            if ($value === '') {
                continue;
            }

            if ($value === '9876543210' || $value === '9999999999') {
                return true;
            }

            if (preg_match('/(^|[^a-z])(test|smoke|qa|demo)([^a-z]|$)/i', $value) === 1) {
                return true;
            }
        }

        return !empty($row['isTest']);
    }

    private function mergeTemplates(array $existing, array $incoming): array
    {
        $byUid = [];
        foreach ($existing as $template) {
            $uid = trim((string) ($template['uid'] ?? ''));
            if ($uid === '') {
                continue;
            }

            $byUid[$uid] = $template;
        }

        foreach ($incoming as $template) {
            $uid = trim((string) ($template['uid'] ?? ''));
            if ($uid === '') {
                continue;
            }

            $byUid[$uid] = $template;
        }

        return array_values($byUid);
    }

    private function mapLeadForAdmin(array $lead): array
    {
        return [
            'id' => (int) ($lead['id'] ?? 0),
            'name' => (string) ($lead['name'] ?? ''),
            'phone' => $this->tailPhone((string) ($lead['phone'] ?? '')),
            'prize' => (string) ($lead['prize'] ?? ''),
            'status' => (string) ($lead['status'] ?? ''),
            'source' => (string) ($lead['source'] ?? ''),
            'createdAt' => (string) ($lead['created_at'] ?? ''),
            'activeRewardLabel' => $this->activeRewardLabel($lead),
            'couponCode' => $this->activeCouponCode($lead),
        ];
    }

    private function mapCrmLeadRow(array $lead): array
    {
        $prize = trim((string) ($lead['prize'] ?? ''));

        return [
            'id' => (int) ($lead['id'] ?? 0),
            'createdAt' => (string) ($lead['created_at'] ?? ''),
            'phone' => $this->tailPhone((string) ($lead['phone'] ?? '')),
            'name' => (string) ($lead['name'] ?? ''),
            'prize' => $prize,
            'outcomeBadge' => $this->resolveOutcomeBadge($prize),
            'couponCode' => $this->activeCouponCode($lead) ?? '',
            'status' => (string) ($lead['status'] ?? ''),
            'redeemedAt' => (string) ($lead['redeemed_at'] ?? ''),
            'source' => (string) ($lead['source'] ?? ''),
            'dateOfBirth' => (string) ($lead['date_of_birth'] ?? ''),
            'dateOfAnniversary' => (string) ($lead['date_of_anniversary'] ?? ''),
            'visitCount' => (int) ($lead['visit_count'] ?? 0),
            'crmSyncStatus' => trim((string) ($lead['crm_sync_status'] ?? '')) !== ''
                ? (string) ($lead['crm_sync_status'] ?? '')
                : 'Pending',
            'crmSyncCode' => (string) ($lead['crm_sync_code'] ?? ''),
            'crmSyncMessage' => (string) ($lead['crm_sync_message'] ?? ''),
        ];
    }

    private function resolveOutcomeBadge(?string $rewardLabel): string
    {
        $normalized = strtolower(trim((string) $rewardLabel));
        if ($normalized === '') {
            return 'Pending';
        }

        if (strpos($normalized, 'try again') !== false) {
            return 'Try Again';
        }

        return 'Won';
    }

    private function extractCrmLeadFilters(array $payload): array
    {
        return [
            'search' => trim((string) ($payload['search'] ?? '')),
            'source' => trim((string) ($payload['source'] ?? '')),
            'outcome' => trim((string) ($payload['outcome'] ?? '')),
            'leadStatus' => trim((string) ($payload['leadStatus'] ?? '')),
            'syncStatus' => trim((string) ($payload['syncStatus'] ?? '')),
            'fromDate' => trim((string) ($payload['fromDate'] ?? '')),
            'toDate' => trim((string) ($payload['toDate'] ?? '')),
        ];
    }

    private function extractCrmWorkspaceFilters(array $payload): array
    {
        return [
            'search' => trim((string) ($payload['search'] ?? '')),
            'source' => trim((string) ($payload['source'] ?? '')),
            'syncStatus' => trim((string) ($payload['syncStatus'] ?? '')),
            'result' => trim((string) ($payload['result'] ?? '')),
        ];
    }

    private function activeRewardLabel(array $lead): ?string
    {
        $surprise = trim((string) ($lead['surprise_reward_label'] ?? ''));
        if ($surprise !== '') {
            return $surprise;
        }

        $prize = trim((string) ($lead['prize'] ?? ''));
        return $prize !== '' ? $prize : null;
    }

    private function activeCouponCode(array $lead): ?string
    {
        $surpriseCoupon = trim((string) ($lead['surprise_coupon_code'] ?? ''));
        if ($surpriseCoupon !== '') {
            return $surpriseCoupon;
        }

        $baseCoupon = trim((string) ($lead['coupon_code'] ?? ''));
        return $baseCoupon !== '' ? $baseCoupon : null;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function normalizeCrmUiDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches) === 1) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }

        return null;
    }

    private function tailPhone(string $phone): string
    {
        $digits = $this->normalizePhone($phone);
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
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
}
