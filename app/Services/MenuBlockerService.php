<?php
/**
 * Menu Blocker Service
 * Handles spin wheel logic, prize generation, cooldown management, and WhatsApp integration
 */

namespace AWG\Services;

use AWG\Repositories\AppSettingRepository;
use AWG\Repositories\MenuBlockerRepository;

class MenuBlockerService
{
    private $menuBlockerRepo;
    private $db;

    private const COOLDOWN_HOURS = 24;
    private const PRIZE_POOL = [
        ['index' => 0, 'label' => 'Free Dessert', 'code' => 'DESSERT', 'weight' => 1],
        ['index' => 1, 'label' => 'Free Mocktail', 'code' => 'MOCKTAIL', 'weight' => 1],
        ['index' => 2, 'label' => 'Free Aerated Drink', 'code' => 'AERATED', 'weight' => 1],
        ['index' => 3, 'label' => 'Free Starter', 'code' => 'STARTER', 'weight' => 1],
        ['index' => 4, 'label' => '10% Discount', 'code' => 'DISC10', 'weight' => 1],
        ['index' => 5, 'label' => '15% Discount', 'code' => 'DISC15', 'weight' => 1],
        ['index' => 6, 'label' => '20% Discount', 'code' => 'DISC20', 'weight' => 1],
        ['index' => 7, 'label' => '25% Discount', 'code' => 'DISC25', 'weight' => 1],
        ['index' => 8, 'label' => 'Try Again', 'code' => 'TRYAGAIN', 'weight' => 2],
    ];

    public function __construct($db)
    {
        $this->db = $db;
        $this->menuBlockerRepo = new MenuBlockerRepository($db);
    }

    /**
     * Get prize based on backend logic (prevents client-side manipulation)
     * Weighted randomization ensures fairness while respecting try-again frequency
     */
    public function generatePrize(string $phone, string $countryCode): array
    {
        // Check cooldown
        $cooldownCheck = $this->checkSpinCooldown($phone, $countryCode);
        if ($cooldownCheck['cooledDown']) {
            return [
                'success' => false,
                'cooledDown' => true,
                'message' => 'Please wait before spinning again. 24-hour cooldown active.',
            ];
        }

        // Generate weighted random prize
        $prize = $this->selectRandomPrize();
        
        // Generate coupon code only for winning prizes (format: AWG-PRIZETYPE-RANDOMHEX).
        $couponCode = $prize['index'] === 8
            ? null
            : 'AWG-' . $prize['code'] . '-' . strtoupper(bin2hex(random_bytes(4)));

        // Log spin entry
        $spinEntry = [
            'phone' => $phone,
            'country_code' => $countryCode,
            'prize_index' => $prize['index'],
            'prize_label' => $prize['label'],
            'coupon_code' => $couponCode,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $this->menuBlockerRepo->createSpinEntry($spinEntry);

        return [
            'success' => true,
            'outcome' => [
                'prizeIndex' => $prize['index'],
                'prizeText' => $prize['label'],
                'couponCode' => $couponCode,
                'message' => $this->getPrizeMessage($prize['label']),
            ],
        ];
    }

    /**
     * Check if customer can spin (respects 24-hour cooldown per unique phone)
     */
    private function checkSpinCooldown(string $phone, string $countryCode): array
    {
        $lastSpin = $this->menuBlockerRepo->getLastSpin($phone, $countryCode);

        if (!$lastSpin) {
            return ['cooledDown' => false];
        }

        $lastSpinTime = strtotime($lastSpin['created_at']);
        $cooldownExpiry = $lastSpinTime + (self::COOLDOWN_HOURS * 3600);
        $now = time();

        if ($now < $cooldownExpiry) {
            return [
                'cooledDown' => true,
                'remainingSeconds' => $cooldownExpiry - $now,
            ];
        }

        return ['cooledDown' => false];
    }

    /**
     * Weighted random selection
     */
    private function selectRandomPrize(): array
    {
        $totalWeight = 0;
        foreach (self::PRIZE_POOL as $prize) {
            $totalWeight += $prize['weight'];
        }

        $random = rand(1, $totalWeight);
        $current = 0;

        foreach (self::PRIZE_POOL as $prize) {
            $current += $prize['weight'];
            if ($random <= $current) {
                return $prize;
            }
        }

        // Fallback (should never reach)
        return self::PRIZE_POOL[array_rand(self::PRIZE_POOL)];
    }

    /**
     * Get contextual message for prize
     */
    private function getPrizeMessage(string $prizeLabel): string
    {
        $messages = [
            'Free Dessert' => 'Indulge in a complimentary dessert of your choice!',
            'Free Mocktail' => 'Enjoy a refreshing mocktail on us!',
            'Free Aerated Drink' => 'Stay hydrated with a free drink!',
            'Free Starter' => 'Start your meal with a free appetizer!',
            '10% Discount' => 'Save 10% on your entire order!',
            '15% Discount' => 'Save 15% on your entire order!',
            '20% Discount' => 'Save 20% on your entire order!',
            '25% Discount' => 'Save 25% on your entire order!',
            'Try Again' => 'Better luck next time! Come back soon.',
        ];

        return $messages[$prizeLabel] ?? 'Thank you for spinning!';
    }

    /**
     * Get admin settings for menu blocker
     */
    public function getSettings(): array
    {
        $appSettings = new AppSettingRepository($this->db);
        $pages = $this->decodeJsonObject($appSettings->getValue('app', 'menuBlockerPages'));

        return [
            'menuBlockerPages' => is_array($pages) ? $pages : [],
            'menuBlockerStaffCode' => (string) ($appSettings->getValue('app', 'menuBlockerStaffCode') ?? ''),
            'hotelWhatsappNo' => (string) ($appSettings->getValue('app', 'hotelWhatsappNo') ?? ''),
            'eventEntryPasscode' => (string) ($appSettings->getValue('app', 'eventEntryPasscode') ?? ''),
            'enabled' => true,
        ];
    }

    /**
     * Update admin settings for menu blocker
     */
    public function updateSettings(array $settings): array
    {
        $result = [];
        $appSettings = new AppSettingRepository($this->db);

        if (isset($settings['menuBlockerPages'])) {
            $appSettings->upsert('app', 'menuBlockerPages', json_encode($settings['menuBlockerPages'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $result['menuBlockerPages'] = $settings['menuBlockerPages'];
        }

        if (isset($settings['menuBlockerStaffCode'])) {
            $appSettings->upsert('app', 'menuBlockerStaffCode', trim((string) $settings['menuBlockerStaffCode']), true);
            $result['menuBlockerStaffCode'] = $settings['menuBlockerStaffCode'];
        }

        if (isset($settings['hotelWhatsappNo'])) {
            $appSettings->upsert('app', 'hotelWhatsappNo', trim((string) $settings['hotelWhatsappNo']), false);
            $result['hotelWhatsappNo'] = $settings['hotelWhatsappNo'];
        }

        if (isset($settings['eventEntryPasscode'])) {
            $appSettings->upsert('app', 'eventEntryPasscode', trim((string) $settings['eventEntryPasscode']), true);
            $result['eventEntryPasscode'] = $settings['eventEntryPasscode'];
        }

        if (isset($settings['enabled'])) {
            $result['enabled'] = $settings['enabled'];
        }

        return ['success' => true, 'settings' => $result];
    }

    private function decodeJsonObject(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get spin statistics for admin dashboard
     */
    public function getStatistics(string $startDate = null, string $endDate = null): array
    {
        if (!$startDate) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        return $this->menuBlockerRepo->getSpinStats($startDate, $endDate);
    }

    /**
     * Get spin history for a phone number
     */
    public function getPhoneHistory(string $phone): array
    {
        return $this->menuBlockerRepo->getPhoneSpins($phone);
    }
}
