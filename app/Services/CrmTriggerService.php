<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Config\Database;
use AWG\Config\Env;
use AWG\Repositories\AppSettingRepository;
use AWG\Repositories\ContactRepository;
use AWG\Repositories\CrmPushLogRepository;
use AWG\Repositories\CrmWhatsappPushConfirmationRepository;
use AWG\Repositories\LeadRepository;
use AWG\Support\Logger;
use RuntimeException;
use Throwable;

final class CrmTriggerService
{
    private const GROUP = 'crm_triggers';
    private const CONFIGS_KEY = 'configs';
    // Only CRM-approved outbound keys from the accepted-variable master list.
    // Keep one outbound key per logical field so test pushes can isolate which
    // CRM variable path actually stores a value.
    private const CUSTOM_VALUE_OUTBOUND_PARAMS = [
        'qrcodeimage'         => ['{%contact.qrcodeimage%}'],
        'event_name'          => ['{%contact.event_name%}'],
        'event_date'          => ['{%contact.event_date%}'],
        'entry_time'          => ['{%contact.entry_time%}'],
        'entry_date'          => ['{%contact.entry_date%}'],
        'no_of_guest'         => ['{%contact.no_of_guest%}'],
        'prize'               => ['{%contact.prize%}'],
        'outcome'             => ['{%contact.outcome%}'],
        'transaction_id'      => ['{%contact.transaction_id%}'],
        'ticket_qty'          => ['{%contact.ticket_qty%}'],
        'registration_status' => ['{%contact.registration_status%}'],
        'qr_url'              => ['{%contact.qr_url%}'],
        'remaining_passes'    => ['{%contact.remaining_passes%}'],
        'coupon_code'         => ['{%contact.coupon_code%}'],
        'try_again'           => ['{%contact.try_again%}'],
    ];

    private const TRIGGERS = [
        'menu_blocker_spin' => [
            'label' => 'Menu Blocker Spin',
            'description' => 'Pushes every menu blocker form submission and spin result.',
            'fields' => [
                'contact_name' => ['label' => 'Name', 'param' => 'contact_name', 'source' => 'name'],
                'contact_phone' => ['label' => 'Mobile', 'param' => 'contact_phone', 'source' => 'phone'],
                'prize' => ['label' => 'Prize', 'param' => 'custom_values.prize', 'source' => 'prize'],
                'coupon_code' => ['label' => 'Coupon Code', 'param' => 'custom_values.coupon_code', 'source' => 'coupon_code'],
                'try_again' => ['label' => 'Try Again', 'param' => 'custom_values.try_again', 'source' => 'try_again'],
                'dob' => ['label' => 'DOB', 'param' => 'custom_values.dob', 'source' => 'date_of_birth'],
                'anniversary' => ['label' => 'Anniversary', 'param' => 'custom_values.anniversary', 'source' => 'date_of_anniversary'],
                'source' => ['label' => 'Source', 'param' => 'custom_values.source', 'source' => 'source'],
                'requested_at' => ['label' => 'Requested At', 'param' => 'custom_values.requested_at', 'source' => 'requested_at'],
            ],
        ],
        'menu_blocker_coupon_won' => [
            'label' => 'Menu Blocker Coupon Won',
            'description' => 'Pushes winner details when a coupon exists.',
            'fields' => [
                'contact_name' => ['label' => 'Name', 'param' => 'contact_name', 'source' => 'name'],
                'contact_phone' => ['label' => 'Mobile', 'param' => 'contact_phone', 'source' => 'phone'],
                'coupon_code' => ['label' => 'Coupon Code', 'param' => 'custom_values.coupon_code', 'source' => 'coupon_code'],
                'prize' => ['label' => 'Prize', 'param' => 'custom_values.prize', 'source' => 'prize'],
                'outcome' => ['label' => 'Outcome', 'param' => 'custom_values.outcome', 'source' => 'outcome'],
            ],
        ],
        'menu_blocker_try_again' => [
            'label' => 'Menu Blocker Try Again',
            'description' => 'Pushes surprise request data when the prize is Try Again.',
            'fields' => [
                'contact_name' => ['label' => 'Name', 'param' => 'contact_name', 'source' => 'name'],
                'contact_phone' => ['label' => 'Mobile', 'param' => 'contact_phone', 'source' => 'phone'],
                'try_again' => ['label' => 'Try Again', 'param' => 'custom_values.try_again', 'source' => 'try_again'],
                'outcome' => ['label' => 'Outcome', 'param' => 'custom_values.outcome', 'source' => 'outcome'],
            ],
        ],
        'event_registration_confirmed' => [
            'label' => 'Event Registration Confirmed',
            'description' => 'Pushes confirmed free registrations and successful paid registrations.',
            'fields' => [
                'contact_name' => ['label' => 'Name', 'param' => 'contact_name', 'source' => 'name'],
                'contact_email' => ['label' => 'Email', 'param' => 'contact_email', 'source' => 'email'],
                'contact_phone' => ['label' => 'Mobile', 'param' => 'contact_phone', 'source' => 'phone'],
                'event_name' => ['label' => 'Event Name', 'param' => 'custom_values.event_name', 'source' => 'event_name'],
                'event_date' => ['label' => 'Event Date', 'param' => 'custom_values.event_date', 'source' => 'event_date'],
                'event_time' => ['label' => 'Event Time', 'param' => 'custom_values.event_time', 'source' => 'event_time'],
                'transaction_id' => ['label' => 'Transaction ID', 'param' => 'custom_values.transaction_id', 'source' => 'transaction_id'],
                'ticket_qty' => ['label' => 'Ticket Qty', 'param' => 'custom_values.ticket_qty', 'source' => 'ticket_qty'],
                'registration_status' => ['label' => 'Registration Status', 'param' => 'custom_values.registration_status', 'source' => 'registration_status'],
                'qrcodeimage' => ['label' => 'QR Code Image', 'param' => 'custom_values.qrcodeimage', 'source' => 'qrcodeimage'],
                'qr_url' => ['label' => 'QR URL', 'param' => 'custom_values.qr_url', 'source' => 'qr_url'],
            ],
        ],
        'event_scan_entry' => [
            'label' => 'Event Scan Entry',
            'description' => 'Pushes QR scan entry data after successful check-in.',
            'fields' => [
                'contact_name' => ['label' => 'Name', 'param' => 'contact_name', 'source' => 'name'],
                'contact_email' => ['label' => 'Email', 'param' => 'contact_email', 'source' => 'email'],
                'contact_phone' => ['label' => 'Mobile', 'param' => 'contact_phone', 'source' => 'phone'],
                'event_name' => ['label' => 'Event Name', 'param' => 'custom_values.event_name', 'source' => 'event_name'],
                'event_date' => ['label' => 'Event Date', 'param' => 'custom_values.event_date', 'source' => 'event_date'],
                'entry_date' => ['label' => 'Entry Date', 'param' => 'custom_values.entry_date', 'source' => 'entry_date'],
                'entry_time' => ['label' => 'Entry Time', 'param' => 'custom_values.entry_time', 'source' => 'entry_time'],
                'no_of_guest' => ['label' => 'No. Of Guest Entered', 'param' => 'custom_values.no_of_guest', 'source' => 'no_of_guest'],
                'transaction_id' => ['label' => 'Transaction ID', 'param' => 'custom_values.transaction_id', 'source' => 'transaction_id'],
                'remaining_passes' => ['label' => 'Remaining Passes', 'param' => 'custom_values.remaining_passes', 'source' => 'remaining_passes'],
            ],
        ],
    ];

    public function listConfigs(): array
    {
        $configs = $this->storedConfigs();
        $rows = [];
        foreach (self::TRIGGERS as $key => $definition) {
            $config = $this->resolveConfig($key, $configs[$key] ?? []);
            $rows[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'enabled' => $config['enabled'],
                'endpoint' => $config['endpoint'],
                'endpointConfigured' => $config['endpoint'] !== '',
                'tokenConfigured' => $this->resolveToken($key) !== '',
                'retryCount' => $config['retryCount'],
                'selectedFields' => $config['selectedFields'],
                'fields' => $this->publicFields($key),
            ];
        }

        return [
            'ok' => true,
            'triggers' => $rows,
        ];
    }

    public function saveConfig(array $payload): array
    {
        $triggerKey = (string) ($payload['triggerKey'] ?? $payload['key'] ?? '');
        $this->assertKnownTrigger($triggerKey);

        $configs = $this->storedConfigs();
        $current = $this->resolveConfig($triggerKey, $configs[$triggerKey] ?? []);
        $selectedFields = $this->sanitizeSelectedFields($triggerKey, $payload['selectedFields'] ?? $current['selectedFields']);

        $configs[$triggerKey] = [
            'enabled' => !empty($payload['enabled']),
            'endpoint' => trim((string) ($payload['endpoint'] ?? '')),
            'selectedFields' => $selectedFields,
            'retryCount' => max(0, min(3, (int) ($payload['retryCount'] ?? 1))),
        ];

        $repo = $this->settingsRepository();
        $repo->upsert(self::GROUP, self::CONFIGS_KEY, json_encode($configs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), false);

        $token = trim((string) ($payload['token'] ?? ''));
        if ($token !== '') {
            $repo->upsert(self::GROUP, $this->tokenKey($triggerKey), $token, true);
        }

        return $this->listConfigs();
    }

    public function resetConfig(string $triggerKey): array
    {
        $this->assertKnownTrigger($triggerKey);
        $configs = $this->storedConfigs();
        unset($configs[$triggerKey]);
        $this->settingsRepository()->upsert(self::GROUP, self::CONFIGS_KEY, json_encode($configs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), false);
        return $this->listConfigs();
    }

    public function testTrigger(array $payload): array
    {
        $triggerKey = (string) ($payload['triggerKey'] ?? $payload['key'] ?? 'menu_blocker_spin');
        $context = $this->sampleContext($triggerKey);

        $name = trim((string) ($payload['name'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        if ($name !== '') {
            $context['name'] = $name;
        }
        if ($phone !== '') {
            $context['phone'] = $phone;
        }

        $result = $this->push($triggerKey, $context, ['reference' => 'admin_test']);

        return [
            'ok' => true,
            'triggerKey' => $triggerKey,
            'payloadPreview' => $this->sanitizePayload($result['payload'] ?? []),
            'crmResult' => $this->publicResult($result),
        ];
    }

    public function push(string $triggerKey, array $context, array $meta = []): array
    {
        try {
            $this->assertKnownTrigger($triggerKey);
            $config = $this->resolveConfig($triggerKey, $this->storedConfigs()[$triggerKey] ?? []);
            $context = $this->hydrateIdentityContext($triggerKey, $context);
            $token = $this->resolveToken($triggerKey);
            $payload = $this->buildPayload($triggerKey, $context, $config['selectedFields'], $token);

            if (empty($config['enabled'])) {
                return $this->logResult($triggerKey, $context, $meta, [
                    'status' => 'Skipped',
                    'code' => 'CRM_TRIGGER_DISABLED',
                    'message' => 'CRM trigger is disabled.',
                    'httpCode' => null,
                    'success' => false,
                    'payload' => $payload,
                    'attempt_count' => 0,
                    'retry_count' => 0,
                    'attempts' => [],
                ], '');
            }

            if ($config['endpoint'] === '' || $token === '') {
                return $this->logResult($triggerKey, $context, $meta, [
                    'status' => 'Skipped',
                    'code' => 'CRM_TRIGGER_NOT_CONFIGURED',
                    'message' => 'CRM trigger endpoint or token is not configured.',
                    'httpCode' => null,
                    'success' => false,
                    'payload' => $payload,
                    'attempt_count' => 0,
                    'retry_count' => 0,
                    'attempts' => [],
                ], $config['endpoint']);
            }

            $result = $this->sendRequest($config['endpoint'], $payload, $config['retryCount']);
            return $this->logResult($triggerKey, $context, $meta, $result, $config['endpoint']);
        } catch (Throwable $exception) {
            Logger::error('CRM trigger push failed', [
                'triggerKey' => $triggerKey,
                'message' => $exception->getMessage(),
            ]);

            return [
                'status' => 'Failed',
                'code' => 'CRM_TRIGGER_FAILED',
                'message' => $exception->getMessage(),
                'httpCode' => null,
                'success' => false,
                'payload' => null,
                'attempt_count' => 0,
                'retry_count' => 0,
                'attempts' => [],
            ];
        }
    }

    public function pushLeadTriggers(LeadRepository $repository, array $lead): array
    {
        $context = [
            'name' => (string) ($lead['name'] ?? ''),
            'phone' => $this->formatPhone((string) ($lead['phone'] ?? '')),
            'date_of_birth' => (string) ($lead['date_of_birth'] ?? ''),
            'date_of_anniversary' => (string) ($lead['date_of_anniversary'] ?? ''),
            'source' => (string) ($lead['source'] ?? 'spinwheel'),
            'prize' => (string) ($lead['prize'] ?? ''),
            'coupon_code' => (string) ($lead['coupon_code'] ?? ''),
            'try_again' => stripos((string) ($lead['prize'] ?? ''), 'try again') !== false ? 'Try Again after 24hrs' : '',
            'outcome' => stripos((string) ($lead['prize'] ?? ''), 'try again') !== false ? 'Try Again' : 'Won',
            'requested_at' => date('Y-m-d H:i:s'),
        ];

        $leadId = (int) ($lead['lead_id'] ?? $lead['id'] ?? 0);
        $meta = ['lead_id' => $leadId];
        $spin = $this->push('menu_blocker_spin', $context, $meta);
        $this->persistLeadStatus($repository, $lead, $spin);

        if (strcasecmp((string) $context['outcome'], 'Try Again') === 0) {
            $this->push('menu_blocker_try_again', $context, $meta);
        } elseif ((string) $context['coupon_code'] !== '') {
            $this->push('menu_blocker_coupon_won', $context, $meta);
        }

        return $spin;
    }

    public function pushEventRegistration(array $transaction): void
    {
        $qrUrl = $this->absoluteUrl((string) ($transaction['qr_url'] ?? ''));
        $context = [
            'name' => (string) ($transaction['customer_name'] ?? ''),
            'email' => (string) ($transaction['customer_email'] ?? ''),
            'phone' => $this->formatPhone((string) ($transaction['customer_phone'] ?? '')),
            'event_name' => (string) ($transaction['event_title'] ?? ''),
            'event_date' => $this->eventDate((string) ($transaction['event_id'] ?? '')),
            'event_time' => $this->eventTime((string) ($transaction['event_id'] ?? '')),
            'transaction_id' => (string) ($transaction['transaction_id'] ?? ''),
            'ticket_qty' => (string) ($transaction['qty'] ?? '1'),
            'registration_status' => (string) ($transaction['status'] ?? ''),
            'qr_url' => $qrUrl,
            'qrcodeimage' => $this->qrImageUrl($qrUrl),
        ];

        $this->push('event_registration_confirmed', $context, ['reference' => (string) ($transaction['transaction_id'] ?? '')]);
    }

    public function pushEventScanEntry(array $transaction, int $admittedCount, int $remaining): void
    {
        $now = time();
        $context = [
            'name' => (string) ($transaction['customer_name'] ?? ''),
            'email' => (string) ($transaction['customer_email'] ?? ''),
            'phone' => $this->formatPhone((string) ($transaction['customer_phone'] ?? '')),
            'event_name' => (string) ($transaction['event_title'] ?? ''),
            'event_date' => $this->eventDate((string) ($transaction['event_id'] ?? '')),
            'entry_date' => date('Y-m-d', $now),
            'entry_time' => date('H:i:s', $now),
            'no_of_guest' => (string) max(1, $admittedCount),
            'transaction_id' => (string) ($transaction['transaction_id'] ?? ''),
            'remaining_passes' => (string) max(0, $remaining),
        ];

        $this->push('event_scan_entry', $context, ['reference' => (string) ($transaction['transaction_id'] ?? '')]);
    }

    private function storedConfigs(): array
    {
        $json = (string) ($this->settingsRepository()->getValue(self::GROUP, self::CONFIGS_KEY) ?? '');
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function resolveConfig(string $triggerKey, array $stored): array
    {
        $defaults = [
            'enabled' => true,
            'endpoint' => trim((string) Env::getProfiled('CRM_API_ENDPOINT', '')),
            'selectedFields' => array_keys(self::TRIGGERS[$triggerKey]['fields']),
            'retryCount' => 1,
        ];

        return [
            'enabled' => array_key_exists('enabled', $stored) ? !empty($stored['enabled']) : $defaults['enabled'],
            'endpoint' => trim((string) ($stored['endpoint'] ?? $defaults['endpoint'])),
            'selectedFields' => $this->sanitizeSelectedFields($triggerKey, $stored['selectedFields'] ?? $defaults['selectedFields']),
            'retryCount' => max(0, min(3, (int) ($stored['retryCount'] ?? $defaults['retryCount']))),
        ];
    }

    private function resolveToken(string $triggerKey): string
    {
        $stored = trim((string) ($this->settingsRepository()->getValue(self::GROUP, $this->tokenKey($triggerKey)) ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        return trim((string) Env::getProfiled('CRM_API_TOKEN', ''));
    }

    private function buildPayload(string $triggerKey, array $context, array $selectedFields, string $token): array
    {
        $payload = ['api_token' => $token];
        $fields = self::TRIGGERS[$triggerKey]['fields'];
        foreach ($selectedFields as $fieldKey) {
            if (!isset($fields[$fieldKey])) {
                continue;
            }
            $field = $fields[$fieldKey];
            $value = $context[$field['source']] ?? '';

            if ($field['param'] === 'contact_phone') {
                $value = $this->formatPhone((string) $value);
                if ($value === '') {
                    continue;
                }
            }

            if ($field['param'] === 'contact_name') {
                $value = trim((string) $value);
                if ($value === '') {
                    $value = 'Guest';
                }
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (str_starts_with($field['param'], 'custom_values.')) {
                $customKey = substr($field['param'], strlen('custom_values.'));
                if ($customKey !== '') {
                    $fallbackTargets = [$customKey];
                    $targets = self::CUSTOM_VALUE_OUTBOUND_PARAMS[$customKey] ?? $fallbackTargets;
                    foreach ($targets as $targetParam) {
                        if ($targetParam === '') {
                            continue;
                        }
                        $payload[$targetParam] = (string) $value;
                    }
                }
                continue;
            }

            $payload[$field['param']] = (string) $value;
        }

        return $payload;
    }

    private function sendRequest(string $endpoint, array $payload, int $retryCount): array
    {
        $attempts = [];
        $maxAttempts = max(1, $retryCount + 1);
        $payload = array_filter($payload, static fn ($value) => $value !== null && $value !== '');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $result = $this->performRequest($endpoint, $payload);
                $attempts[] = [
                    'attempt' => $attempt,
                    'time' => date('c'),
                    'httpCode' => $result['httpCode'] ?? null,
                    'status' => $result['status'] ?? null,
                    'code' => $result['code'] ?? null,
                    'message' => $result['message'] ?? null,
                ];
                $result['payload'] = $payload;
                $result['attempt_count'] = $attempt;
                $result['retry_count'] = max(0, $attempt - 1);
                $result['attempts'] = $attempts;
                if (!empty($result['success']) || $attempt === $maxAttempts) {
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
                if ($attempt === $maxAttempts) {
                    return [
                        'status' => 'Failed',
                        'code' => 'CRM_REQUEST_FAILED',
                        'message' => $exception->getMessage(),
                        'httpCode' => null,
                        'success' => false,
                        'payload' => $payload,
                        'attempt_count' => $attempt,
                        'retry_count' => max(0, $attempt - 1),
                        'attempts' => $attempts,
                    ];
                }
            }
        }

        throw new RuntimeException('CRM request failed.');
    }

    private function performRequest(string $endpoint, array $payload): array
    {
        // Match working Apps Script format: POST JSON body directly to endpoint.
        $requestUrl = $endpoint;
        $jsonBody = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $transport = $this->resolveTransportOptions();

        if (function_exists('curl_init')) {
            $ch = curl_init($requestUrl);
            if ($ch === false) {
                throw new RuntimeException('Unable to initialize CRM request.');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonBody,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => $transport['verifyPeer'],
                CURLOPT_SSL_VERIFYHOST => $transport['verifyHost'],
            ]);

            if ($transport['caInfo'] !== '') {
                curl_setopt($ch, CURLOPT_CAINFO, $transport['caInfo']);
            }

            if ($transport['caPath'] !== '') {
                curl_setopt($ch, CURLOPT_CAPATH, $transport['caPath']);
            }

            $responseBody = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($responseBody === false || $error !== '') {
                throw new RuntimeException($error !== '' ? $error : 'CRM request failed.');
            }

            return $this->normalizeResponse($httpCode, (string) $responseBody);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $jsonBody,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $transport['verifyPeer'],
                'verify_peer_name' => $transport['verifyHost'] !== 0,
                'allow_self_signed' => !$transport['verifyPeer'],
                'cafile' => $transport['caInfo'] !== '' ? $transport['caInfo'] : null,
                'capath' => $transport['caPath'] !== '' ? $transport['caPath'] : null,
            ],
        ]);
        $responseBody = @file_get_contents($requestUrl, false, $context);
        if ($responseBody === false) {
            throw new RuntimeException('CRM request failed.');
        }

        return $this->normalizeResponse($this->extractHttpCode($http_response_header ?? []), (string) $responseBody);
    }

    private function normalizeResponse(int $httpCode, string $body): array
    {
        $decoded = json_decode($body, true);
        $success = $httpCode >= 200 && $httpCode < 300;
        $message = null;
        $code = null;

        if (is_array($decoded)) {
            $message = isset($decoded['message']) ? (string) $decoded['message'] : null;
            $code = isset($decoded['code']) ? (string) $decoded['code'] : null;
            if (array_key_exists('success', $decoded)) {
                $success = (bool) $decoded['success'];
            } elseif (array_key_exists('ok', $decoded)) {
                $success = (bool) $decoded['ok'];
            }
        }

        $rejectedVariables = $this->extractRejectedVariables(is_array($decoded) ? $decoded : null, $message);

        return [
            'status' => $success ? 'Success' : 'Failed',
            'code' => $code ?: ($success ? 'CRM_PUSHED' : 'CRM_HTTP_' . $httpCode),
            'message' => $message ?: ($success ? 'CRM trigger pushed.' : 'CRM request failed.'),
            'httpCode' => $httpCode,
            'success' => $success,
            'rawBody' => $body,
            'responseData' => is_array($decoded) ? $decoded : null,
            'rejected_variables' => $rejectedVariables,
        ];
    }

    private function extractRejectedVariables(?array $responseData, ?string $message): array
    {
        $collector = [];
        if (is_array($responseData)) {
            $this->collectRejectedVariables($responseData, null, $collector);
        }

        if (is_string($message) && $message !== '' && $this->looksLikeFieldErrorText($message)) {
            foreach ($this->extractVariableTokens($message) as $token) {
                $collector[$token] = true;
            }
        }

        $vars = array_keys($collector);
        sort($vars);
        return $vars;
    }

    private function collectRejectedVariables(mixed $value, ?string $path, array &$collector): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $segment = is_string($key) ? strtolower($key) : null;
                $nextPath = $segment ?? $path;

                if (is_string($segment) && $this->isRejectedHintKey($segment)) {
                    if (is_string($item)) {
                        foreach ($this->extractVariableTokens($item) as $token) {
                            $collector[$token] = true;
                        }
                    } elseif (is_array($item)) {
                        foreach ($item as $nestedKey => $nestedValue) {
                            if (is_string($nestedKey) && $this->looksLikeVariableName($nestedKey)) {
                                $collector[strtolower($nestedKey)] = true;
                            }
                            if (is_string($nestedValue)) {
                                foreach ($this->extractVariableTokens($nestedValue) as $token) {
                                    $collector[$token] = true;
                                }
                            }
                        }
                    }
                }

                $this->collectRejectedVariables($item, $nextPath, $collector);
            }
            return;
        }

        if (is_string($value) && is_string($path) && $this->isRejectedHintKey($path)) {
            foreach ($this->extractVariableTokens($value) as $token) {
                $collector[$token] = true;
            }
        }
    }

    private function isRejectedHintKey(string $key): bool
    {
        return str_contains($key, 'reject')
            || str_contains($key, 'invalid')
            || str_contains($key, 'unknown_field')
            || str_contains($key, 'unknownfield')
            || str_contains($key, 'field_error')
            || str_contains($key, 'fielderror')
            || str_contains($key, 'missing_field')
            || str_contains($key, 'missingfield');
    }

    private function looksLikeFieldErrorText(string $text): bool
    {
        $lower = strtolower($text);
        return str_contains($lower, 'field')
            || str_contains($lower, 'variable')
            || str_contains($lower, 'invalid')
            || str_contains($lower, 'unknown')
            || str_contains($lower, 'rejected')
            || str_contains($lower, 'not allowed');
    }

    private function extractVariableTokens(string $text): array
    {
        $tokens = [];
        if (preg_match_all('/(?:custom_values\.)?[a-z][a-z0-9_]{1,63}/i', $text, $matches)) {
            foreach ($matches[0] as $raw) {
                $candidate = strtolower(trim((string) $raw, " \t\n\r\0\x0B{}%"));
                if ($this->looksLikeVariableName($candidate)) {
                    $tokens[$candidate] = true;
                }
            }
        }

        $vars = array_keys($tokens);
        sort($vars);
        return $vars;
    }

    private function looksLikeVariableName(string $name): bool
    {
        if ($name === '' || strlen($name) > 80) {
            return false;
        }

        $ignore = [
            'success', 'status', 'code', 'message', 'automation', 'executed', 'triggered',
            'invalid', 'unknown', 'field', 'fields', 'variable', 'variables', 'required',
            'missing', 'not', 'allowed', 'crm', 'request', 'failed', 'error',
        ];
        if (in_array($name, $ignore, true)) {
            return false;
        }

        return (bool) preg_match('/^(?:custom_values\.)?[a-z][a-z0-9_]{1,63}$/', $name);
    }

    private function logResult(string $triggerKey, array $context, array $meta, array $result, string $endpoint): array
    {
        try {
            $safePayload = $this->sanitizePayload(is_array($result['payload'] ?? null) ? $result['payload'] : []);

            // Build a clean nested-only debug snapshot: strip flat dotted keys, keep only
            // top-level scalars (contact_name, contact_phone) plus nested custom_values object.
            // No api_token — sanitizePayload() already removed it above.
            $debugPayload = [];
            foreach ($safePayload as $k => $v) {
                if (!str_contains((string) $k, '.')) {
                    $debugPayload[$k] = $v;
                }
            }

            (new CrmPushLogRepository(Database::connection()))->create([
                'contact_id' => null,
                'lead_id' => (int) ($meta['lead_id'] ?? 0) ?: null,
                'phone' => preg_replace('/\D+/', '', (string) ($context['phone'] ?? '')),
                'contact_name' => (string) ($context['name'] ?? ''),
                'trigger_source' => $triggerKey,
                'crm_endpoint' => $endpoint,
                'attempted' => ($result['status'] ?? '') !== 'Skipped',
                'success' => !empty($result['success']),
                'http_code' => $result['httpCode'] ?? null,
                'retry_count' => (int) ($result['retry_count'] ?? 0),
                'attempt_count' => (int) ($result['attempt_count'] ?? 0),
                'response_message' => $result['message'] ?? null,
                'request_payload_json' => json_encode($safePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'debug_payload_json' => json_encode($debugPayload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                'attempts_json' => json_encode($result['attempts'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            if ($this->isWhatsappPushConfirmationSuccess($result)) {
                (new CrmWhatsappPushConfirmationRepository(Database::connection()))->create([
                    'lead_id' => (int) ($meta['lead_id'] ?? 0) ?: null,
                    'trigger_source' => $triggerKey,
                    'phone' => preg_replace('/\D+/', '', (string) ($context['phone'] ?? '')),
                    'contact_name' => (string) ($context['name'] ?? ''),
                    'crm_endpoint' => $endpoint,
                    'http_code' => $result['httpCode'] ?? null,
                    'response_status' => (string) ($result['status'] ?? ''),
                    'response_code' => isset($result['code']) ? (string) $result['code'] : null,
                    'response_message' => isset($result['message']) ? (string) $result['message'] : null,
                    'response_json' => json_encode($result['responseData'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'request_payload_json' => json_encode($safePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            }
        } catch (Throwable $exception) {
            Logger::error('CRM trigger log failed', [
                'triggerKey' => $triggerKey,
                'message' => $exception->getMessage(),
            ]);
        }

        return $result;
    }

    private function persistLeadStatus(LeadRepository $repository, array $lead, array $result): void
    {
        $leadId = (int) ($lead['lead_id'] ?? $lead['id'] ?? 0);
        if ($leadId <= 0) {
            return;
        }

        try {
            $status = (string) ($result['status'] ?? 'Failed');
            $code = isset($result['code']) ? (string) $result['code'] : null;
            $message = isset($result['message']) ? (string) $result['message'] : null;
            $repository->updateCrmSyncStatus($leadId, $status, $code, $message);

            $summary = $repository->findLatestSummaryByPhone((string) ($lead['phone'] ?? '')) ?: [];
            (new ContactRepository(Database::connection()))->upsert([
                'phone' => (string) ($lead['phone'] ?? ''),
                'name' => (string) ($lead['name'] ?? ''),
                'date_of_birth' => $lead['date_of_birth'] ?? null,
                'date_of_anniversary' => $lead['date_of_anniversary'] ?? null,
                'latest_source' => (string) ($lead['source'] ?? ''),
                'latest_lead_id' => $leadId,
                'latest_lead_created_at' => $summary['created_at'] ?? date('Y-m-d H:i:s'),
                'first_seen_at' => $summary['first_seen_at'] ?? ($summary['created_at'] ?? date('Y-m-d H:i:s')),
                'last_seen_at' => $summary['last_seen_at'] ?? ($summary['created_at'] ?? date('Y-m-d H:i:s')),
                'total_submissions' => (int) ($summary['total_submissions'] ?? 1),
                'latest_crm_sync_status' => $status,
                'latest_crm_sync_code' => $code,
                'latest_crm_sync_message' => $message,
                'last_crm_attempted_at' => $status === 'Skipped' ? null : date('Y-m-d H:i:s'),
                'last_crm_pushed_at' => !empty($result['success']) ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (Throwable $exception) {
            Logger::error('CRM lead trigger persistence failed', [
                'leadId' => $leadId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function sanitizePayload(array $payload): array
    {
        unset($payload['api_token'], $payload['token'], $payload['auth_token']);
        return $payload;
    }

    private function publicResult(array $result): array
    {
        $rejected = is_array($result['rejected_variables'] ?? null) ? $result['rejected_variables'] : [];
        $failed = !empty($result['status']) && strcasecmp((string) $result['status'], 'Success') !== 0;

        return [
            'status' => (string) ($result['status'] ?? ''),
            'code' => $result['code'] ?? null,
            'message' => $result['message'] ?? null,
            'httpCode' => $result['httpCode'] ?? null,
            'success' => !empty($result['success']),
            'rejectedVariables' => $rejected,
            'rejectedVariablesHint' => $failed && $rejected === []
                ? 'CRM did not explicitly return rejected variable names in this response.'
                : null,
            'attemptCount' => (int) ($result['attempt_count'] ?? 0),
            'retryCount' => (int) ($result['retry_count'] ?? 0),
            'attempts' => is_array($result['attempts'] ?? null) ? $result['attempts'] : [],
        ];
    }

    private function isWhatsappPushConfirmationSuccess(array $result): bool
    {
        if (empty($result['success'])) {
            return false;
        }

        $response = $result['responseData'] ?? null;
        if (!is_array($response)) {
            return false;
        }

        $status = strtolower(trim((string) ($response['status'] ?? '')));
        $message = strtolower(trim((string) ($response['message'] ?? '')));

        if ($status === 'success') {
            return true;
        }

        return str_contains($message, 'automation triggered successfully');
    }

    private function publicFields(string $triggerKey): array
    {
        $fields = [];
        foreach (self::TRIGGERS[$triggerKey]['fields'] as $key => $field) {
            $fields[] = [
                'key' => $key,
                'label' => $field['label'],
                'param' => $field['param'],
            ];
        }
        return $fields;
    }

    private function sanitizeSelectedFields(string $triggerKey, mixed $fields): array
    {
        $valid = array_keys(self::TRIGGERS[$triggerKey]['fields']);
        if (!is_array($fields)) {
            $fields = $valid;
        }

        $selected = array_values(array_intersect($valid, array_map('strval', $fields)));
        if ($selected === []) {
            $selected = $valid;
        }

        if (in_array('contact_name', $valid, true) && !in_array('contact_name', $selected, true)) {
            $selected[] = 'contact_name';
        }

        if (in_array('contact_phone', $valid, true) && !in_array('contact_phone', $selected, true)) {
            $selected[] = 'contact_phone';
        }

        return $selected;
    }

    private function hydrateIdentityContext(string $triggerKey, array $context): array
    {
        $fields = self::TRIGGERS[$triggerKey]['fields'] ?? [];
        $needsName = isset($fields['contact_name']);
        $needsPhone = isset($fields['contact_phone']);
        if (!$needsName && !$needsPhone) {
            return $context;
        }

        $rawPhone = (string) ($context['phone'] ?? '');
        $formattedPhone = $this->formatPhone($rawPhone);
        if ($formattedPhone !== '') {
            $context['phone'] = $formattedPhone;
        }

        $name = trim((string) ($context['name'] ?? ''));
        if ($name !== '' && (!$needsPhone || $formattedPhone !== '')) {
            return $context;
        }

        $digits = preg_replace('/\D+/', '', $formattedPhone !== '' ? $formattedPhone : $rawPhone) ?? '';
        if ($digits === '') {
            if ($needsName && $name === '') {
                $context['name'] = 'Guest';
            }
            return $context;
        }

        $candidates = [$digits];
        if (strlen($digits) === 10) {
            $candidates[] = '91' . $digits;
        } elseif (strlen($digits) > 10) {
            $candidates[] = substr($digits, -10);
        }

        $candidates = array_values(array_unique(array_filter($candidates, static fn (string $value): bool => $value !== '')));

        try {
            $connection = Database::connection();
            $contactRepo = new ContactRepository($connection);
            $leadRepo = new LeadRepository($connection);

            foreach ($candidates as $candidate) {
                $contact = $contactRepo->findByPhone($candidate);
                if (is_array($contact)) {
                    $foundName = trim((string) ($contact['name'] ?? ''));
                    if ($name === '' && $foundName !== '') {
                        $name = $foundName;
                    }
                    if ($formattedPhone === '') {
                        $formattedPhone = $this->formatPhone((string) ($contact['phone'] ?? $candidate));
                    }
                    break;
                }

                $lead = $leadRepo->findLatestByPhone($candidate);
                if (is_array($lead)) {
                    $foundName = trim((string) ($lead['name'] ?? ''));
                    if ($name === '' && $foundName !== '') {
                        $name = $foundName;
                    }
                    if ($formattedPhone === '') {
                        $formattedPhone = $this->formatPhone((string) ($lead['phone'] ?? $candidate));
                    }
                }
            }
        } catch (Throwable) {
            // Keep original context if lookup fails.
        }

        if ($needsName) {
            $context['name'] = $name !== '' ? $name : 'Guest';
        }

        if ($needsPhone && $formattedPhone !== '') {
            $context['phone'] = $formattedPhone;
        }

        return $context;
    }

    private function sampleContext(string $triggerKey): array
    {
        $this->assertKnownTrigger($triggerKey);
        $context = [
            'name' => 'CRM Test Lead',
            'email' => 'test@example.com',
            'phone' => '+919999999999',
            'date_of_birth' => '1990-01-01',
            'date_of_anniversary' => '2020-01-01',
            'source' => 'admin_crm_trigger_test',
            'prize' => 'Dessert Shot',
            'coupon_code' => 'AWG-TEST01',
            'try_again' => 'Try Again after 24hrs',
            'outcome' => 'Won',
            'requested_at' => date('Y-m-d H:i:s'),
            'event_name' => 'AWG Test Event',
            'event_date' => date('Y-m-d'),
            'event_time' => date('H:i:s'),
            'transaction_id' => 'txn_test',
            'ticket_qty' => '2',
            'registration_status' => 'free_confirmed',
            'qr_url' => $this->absoluteUrl('/verify-event-qr?payload=test'),
            'qrcodeimage' => $this->qrImageUrl($this->absoluteUrl('/verify-event-qr?payload=test')),
            'entry_date' => date('Y-m-d'),
            'entry_time' => date('H:i:s'),
            'no_of_guest' => '1',
            'remaining_passes' => '1',
        ];

        if ($triggerKey === 'menu_blocker_try_again') {
            $context['prize'] = 'Try Again';
            $context['coupon_code'] = '';
            $context['outcome'] = 'Try Again';
        }

        return $context;
    }

    private function eventDate(string $eventId): string
    {
        try {
            $event = (new \AWG\Repositories\EventRepository())->findById($eventId);
            return is_array($event) ? (string) ($event['start_date'] ?? '') : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function eventTime(string $eventId): string
    {
        try {
            $event = (new \AWG\Repositories\EventRepository())->findById($eventId);
            return is_array($event) ? (string) ($event['start_time'] ?? '') : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function qrImageUrl(string $qrUrl): string
    {
        if ($qrUrl === '') {
            return '';
        }
        return $this->absoluteUrl('/?action=event_qr_image&data=' . rawurlencode($qrUrl));
    }

    private function absoluteUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        $base = rtrim((string) Env::getProfiled('APP_PUBLIC_SITE_URL', Env::getProfiled('APP_URL', '')), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $base . '/' . ltrim($url, '/');
    }

    private function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        }
        return '+' . $digits;
    }

    private function tokenKey(string $triggerKey): string
    {
        return 'crm_trigger_token_' . $triggerKey;
    }

    private function settingsRepository(): AppSettingRepository
    {
        return new AppSettingRepository(Database::connection());
    }

    private function assertKnownTrigger(string $triggerKey): void
    {
        if (!isset(self::TRIGGERS[$triggerKey])) {
            throw new RuntimeException('Unknown CRM trigger.');
        }
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

    private function resolveTransportOptions(): array
    {
        $isLocal = $this->isLocalRuntime();
        $verifyPeer = true;
        $verifyHost = 2;

        if ($isLocal) {
            $verifyPeer = $this->envBool('CRM_SSL_VERIFY_PEER', true);
            $verifyHost = $this->envBool('CRM_SSL_VERIFY_HOST', true) ? 2 : 0;
        }

        return [
            'verifyPeer' => $verifyPeer,
            'verifyHost' => $verifyHost,
            'caInfo' => $this->resolveCaInfoPath(),
            'caPath' => $this->resolveCaPath(),
        ];
    }

    private function resolveCaInfoPath(): string
    {
        $candidates = [
            Env::getProfiled('CRM_CAINFO_LOCAL', ''),
            Env::getProfiled('CRM_CA_BUNDLE_PATH_LOCAL', ''),
            Env::getProfiled('CRM_CAINFO', ''),
            Env::getProfiled('CRM_CA_BUNDLE_PATH', ''),
        ];

        foreach ($candidates as $path) {
            $normalized = trim((string) $path);
            if ($normalized === '') {
                continue;
            }

            if (is_file($normalized)) {
                return $normalized;
            }

            $rooted = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($normalized, '/\\');
            if (is_file($rooted)) {
                return $rooted;
            }
        }

        return '';
    }

    private function resolveCaPath(): string
    {
        $path = trim((string) Env::getProfiled('CRM_CAPATH_LOCAL', Env::getProfiled('CRM_CAPATH', '')));
        if ($path === '') {
            return '';
        }

        if (is_dir($path)) {
            return $path;
        }

        $rooted = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
        return is_dir($rooted) ? $rooted : '';
    }

    private function envBool(string $key, bool $default): bool
    {
        $value = Env::getProfiled($key, null);
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function isLocalRuntime(): bool
    {
        $profile = strtolower(trim((string) Env::get('NK_ENV_PROFILE', '')));
        if (in_array($profile, ['local', 'dev', 'development', 'test'], true)) {
            return true;
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        return str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');
    }
}
