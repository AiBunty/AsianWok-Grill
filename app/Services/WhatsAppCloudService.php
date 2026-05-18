<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Config\Env;
use AWG\Repositories\AppSettingRepository;
use AWG\Repositories\WhatsAppEventMappingRepository;
use AWG\Repositories\WhatsAppEventMessageVersionRepository;
use AWG\Repositories\WhatsAppMessageLogRepository;
use AWG\Repositories\WhatsAppScheduledMessageRepository;
use AWG\Repositories\WhatsAppTemplateDraftRepository;
use AWG\Repositories\WhatsAppTemplateRepository;
use AWG\Config\Database;
use Throwable;

final class WhatsAppCloudService
{
    private const APP_GROUP = 'app';
    private const WHATSAPP_GROUP = 'whatsapp_cloud';
    private const EVENT_KEYS = [
        'lead_created',
        'winner_announced',
        'event_booking_created',
        'event_booking_paid',
        'event_booking_checkin',
        'event_reminder_due',
    ];

    private AppSettingRepository $settings;
    private WhatsAppTemplateRepository $templates;
    private WhatsAppEventMappingRepository $mappings;
    private WhatsAppMessageLogRepository $logs;
    private WhatsAppTemplateDraftRepository $drafts;
    private WhatsAppScheduledMessageRepository $schedules;
    private WhatsAppEventMessageVersionRepository $versions;

    public function __construct()
    {
        $db = Database::connection();
        $this->settings = new AppSettingRepository($db);
        $this->templates = new WhatsAppTemplateRepository();
        $this->mappings = new WhatsAppEventMappingRepository();
        $this->logs = new WhatsAppMessageLogRepository();
        $this->drafts = new WhatsAppTemplateDraftRepository();
        $this->schedules = new WhatsAppScheduledMessageRepository();
        $this->versions = new WhatsAppEventMessageVersionRepository();
    }

    public function workspace(): array
    {
        $config = $this->getProviderConfig();
        return [
            'ok' => true,
            'action' => 'auth_get_whatsapp_workspace',
            'workspace' => [
                'config' => $this->safeConfig($config),
                'readiness' => $this->readiness($config),
                'eventKeys' => self::EVENT_KEYS,
                'templates' => $this->templates->listAll(),
                'mappings' => $this->mappings->listAll(),
                'drafts' => $this->drafts->listAll(),
                'logs' => $this->logs->listRecent(200),
            ],
        ];
    }

    public function saveConfig(array $input, ?int $updatedBy): array
    {
        $token = trim((string) ($input['accessToken'] ?? $input['access_token'] ?? ''));
        $phoneId = trim((string) ($input['phoneNumberId'] ?? $input['phone_number_id'] ?? ''));
        $businessId = trim((string) ($input['businessAccountId'] ?? $input['business_account_id'] ?? ''));
        $verifyToken = trim((string) ($input['verifyToken'] ?? $input['verify_token'] ?? ''));

        if ($token !== '') {
            $this->settings->upsert(self::WHATSAPP_GROUP, 'access_token', $token, true);
        }
        if ($phoneId !== '') {
            $this->settings->upsert(self::WHATSAPP_GROUP, 'phone_number_id', $phoneId, false);
        }
        if ($businessId !== '') {
            $this->settings->upsert(self::WHATSAPP_GROUP, 'business_account_id', $businessId, false);
        }
        if ($verifyToken !== '') {
            $this->settings->upsert(self::WHATSAPP_GROUP, 'verify_token', $verifyToken, true);
        }

        if ($updatedBy !== null) {
            $this->settings->upsert(self::WHATSAPP_GROUP, 'updated_by', (string) $updatedBy, false);
        }

        return [
            'ok' => true,
            'action' => 'auth_save_whatsapp_config',
            'message' => 'WhatsApp config saved.',
            'readiness' => $this->readiness($this->getProviderConfig()),
        ];
    }

    public function syncTemplates(): array
    {
        $config = $this->getProviderConfig();
        $ready = $this->readiness($config);
        if (empty($ready['readyForSync'])) {
            return [
                'ok' => false,
                'error' => 'WHATSAPP_NOT_CONFIGURED',
                'message' => 'Access token and business account id are required for template sync.',
            ];
        }

        $endpoint = '/v22.0/' . rawurlencode($config['businessAccountId']) . '/message_templates';
        $response = $this->metaRequest('GET', $endpoint, [], $config['accessToken']);
        if (($response['ok'] ?? false) !== true) {
            return $response;
        }

        $items = is_array($response['data']['data'] ?? null) ? $response['data']['data'] : [];
        $synced = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $uid = (string) ($item['id'] ?? $item['name'] ?? '');
            if ($uid === '') {
                continue;
            }
            $this->templates->upsert([
                'template_uid' => $uid,
                'template_name' => (string) ($item['name'] ?? ''),
                'language_code' => (string) ($item['language'] ?? ''),
                'category' => (string) ($item['category'] ?? ''),
                'status' => (string) ($item['status'] ?? ''),
                'quality_score' => (string) (($item['quality_score']['score'] ?? '') ?: ''),
                'components_json' => json_encode($item['components'] ?? [], JSON_UNESCAPED_SLASHES),
                'last_synced_at' => date('Y-m-d H:i:s'),
            ]);
            $synced++;
        }

        return [
            'ok' => true,
            'action' => 'auth_sync_whatsapp_templates',
            'syncedCount' => $synced,
            'templates' => $this->templates->listAll(),
        ];
    }

    public function saveMapping(array $input, ?int $updatedBy): array
    {
        $eventKey = trim((string) ($input['eventKey'] ?? $input['event_key'] ?? ''));
        $templateName = trim((string) ($input['templateName'] ?? $input['template_name'] ?? ''));
        $languageCode = trim((string) ($input['languageCode'] ?? $input['language_code'] ?? ''));
        $mappedVersionId = ($input['mappedVersionId'] ?? $input['mapped_version_id'] ?? null);
        $mappedTemplateUid = trim((string) ($input['mappedTemplateUid'] ?? $input['mapped_template_uid'] ?? ''));
        $isEnabled = !empty($input['isEnabled']) || !empty($input['is_enabled']);

        if (!in_array($eventKey, self::EVENT_KEYS, true)) {
            return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'Unknown event key.'];
        }

        if ($isEnabled && ($templateName === '' || $languageCode === '')) {
            return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'Template name and language are required when enabling mapping.'];
        }

        $this->mappings->upsert([
            'event_key' => $eventKey,
            'template_name' => $templateName,
            'language_code' => $languageCode,
            'mapped_version_id' => $mappedVersionId !== null && $mappedVersionId !== '' ? (int) $mappedVersionId : null,
            'mapped_template_uid' => $mappedTemplateUid,
            'is_enabled' => $isEnabled ? 1 : 0,
            'updated_by' => $updatedBy,
        ]);

        return [
            'ok' => true,
            'action' => 'auth_save_whatsapp_mapping',
            'message' => 'Mapping saved.',
            'mappings' => $this->mappings->listAll(),
        ];
    }

    public function sendTestTemplate(array $input): array
    {
        $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? '')) ?? '';
        $eventKey = trim((string) ($input['eventKey'] ?? $input['event_key'] ?? ''));

        if (strlen($phone) < 10 || $eventKey === '') {
            return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => 'Phone and event key are required.'];
        }

        return $this->sendByEventKey($eventKey, [
            'phone' => $phone,
            'customerName' => (string) ($input['customerName'] ?? 'Guest'),
            'variables' => is_array($input['variables'] ?? null) ? $input['variables'] : [],
        ], true);
    }

    public function saveTemplateDraft(array $input, ?int $userId): array
    {
        $id = $this->drafts->save([
            'id' => (int) ($input['id'] ?? 0),
            'draft_name' => (string) ($input['draft_name'] ?? $input['draftName'] ?? ''),
            'template_name' => (string) ($input['template_name'] ?? $input['templateName'] ?? ''),
            'category' => (string) ($input['category'] ?? 'UTILITY'),
            'language_code' => (string) ($input['language_code'] ?? $input['languageCode'] ?? 'en'),
            'header_type' => (string) ($input['header_type'] ?? $input['headerType'] ?? ''),
            'header_text' => (string) ($input['header_text'] ?? $input['headerText'] ?? ''),
            'body_text' => (string) ($input['body_text'] ?? $input['bodyText'] ?? ''),
            'footer_text' => (string) ($input['footer_text'] ?? $input['footerText'] ?? ''),
            'buttons_json' => json_encode($input['buttons'] ?? $input['buttons_json'] ?? [], JSON_UNESCAPED_SLASHES),
            'sample_variables_json' => json_encode($input['sample_variables'] ?? $input['sampleVariables'] ?? [], JSON_UNESCAPED_SLASHES),
            'example_media_handle' => (string) ($input['example_media_handle'] ?? $input['exampleMediaHandle'] ?? ''),
            'status' => (string) ($input['status'] ?? 'draft'),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return [
            'ok' => true,
            'action' => 'auth_save_whatsapp_template_draft',
            'draftId' => $id,
            'drafts' => $this->drafts->listAll(),
        ];
    }

    public function submitTemplateDraft(int $draftId, ?int $userId): array
    {
        $draft = $this->drafts->findById($draftId);
        if (!is_array($draft)) {
            return ['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'Draft not found.'];
        }

        $config = $this->getProviderConfig();
        $ready = $this->readiness($config);
        if (empty($ready['readyForSync'])) {
            return ['ok' => false, 'error' => 'WHATSAPP_NOT_CONFIGURED', 'message' => 'Provider credentials missing.'];
        }

        $payload = [
            'name' => (string) $draft['template_name'],
            'category' => (string) $draft['category'],
            'language' => (string) $draft['language_code'],
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => (string) ($draft['body_text'] ?? ''),
                ],
            ],
        ];

        $endpoint = '/v22.0/' . rawurlencode($config['businessAccountId']) . '/message_templates';
        $response = $this->metaRequest('POST', $endpoint, $payload, $config['accessToken']);
        if (($response['ok'] ?? false) !== true) {
            $this->drafts->markSubmitted($draftId, '', 'rejected', (string) ($response['message'] ?? 'Submit failed'));
            return $response;
        }

        $metaTemplateId = (string) (($response['data']['id'] ?? '') ?: '');
        $status = (string) (($response['data']['status'] ?? 'PENDING_REVIEW') ?: 'PENDING_REVIEW');
        $this->drafts->markSubmitted($draftId, $metaTemplateId, strtolower($status), null);

        $versionId = $this->versions->create([
            'event_key' => 'event_booking_created',
            'source_draft_id' => $draftId,
            'version_label' => 'draft-' . $draftId . '-' . date('YmdHis'),
            'template_name' => (string) $draft['template_name'],
            'language_code' => (string) $draft['language_code'],
            'category' => (string) $draft['category'],
            'header_type' => (string) ($draft['header_type'] ?? ''),
            'header_text' => (string) ($draft['header_text'] ?? ''),
            'body_text' => (string) ($draft['body_text'] ?? ''),
            'footer_text' => (string) ($draft['footer_text'] ?? ''),
            'buttons_json' => (string) ($draft['buttons_json'] ?? '[]'),
            'sample_variables_json' => (string) ($draft['sample_variables_json'] ?? '{}'),
            'meta_template_uid' => $metaTemplateId,
            'meta_status' => $status,
            'is_current' => 0,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return [
            'ok' => true,
            'action' => 'auth_submit_whatsapp_template_draft',
            'draftId' => $draftId,
            'versionId' => $versionId,
            'metaTemplateId' => $metaTemplateId,
            'status' => $status,
        ];
    }

    public function previewTemplate(array $input): array
    {
        $templateName = trim((string) ($input['templateName'] ?? $input['template_name'] ?? ''));
        $languageCode = trim((string) ($input['languageCode'] ?? $input['language_code'] ?? 'en'));
        $sampleVars = is_array($input['sampleVariables'] ?? null) ? $input['sampleVariables'] : [];

        $sourceText = '';
        if (!empty($input['draftId'])) {
            $draft = $this->drafts->findById((int) $input['draftId']);
            $sourceText = (string) ($draft['body_text'] ?? '');
        } else {
            $template = $this->templates->findByNameLanguage($templateName, $languageCode);
            $components = json_decode((string) ($template['components_json'] ?? '[]'), true);
            $sourceText = (string) (($components[0]['text'] ?? '') ?: '');
        }

        $rendered = $sourceText;
        foreach (array_values($sampleVars) as $index => $value) {
            $rendered = str_replace('{{' . ($index + 1) . '}}', (string) $value, $rendered);
        }

        return [
            'ok' => true,
            'action' => 'auth_preview_whatsapp_template',
            'preview' => [
                'templateName' => $templateName,
                'languageCode' => $languageCode,
                'renderedBody' => $rendered,
                'sourceBody' => $sourceText,
            ],
        ];
    }

    public function runScheduler(int $limit = 50): array
    {
        $due = $this->schedules->listDue($limit);
        $processed = 0;
        $sent = 0;
        $failed = 0;

        foreach ($due as $job) {
            $result = $this->sendByEventKey((string) ($job['event_key'] ?? ''), [
                'phone' => (string) ($job['phone'] ?? ''),
                'customerName' => (string) ($job['customer_name'] ?? 'Guest'),
                'variables' => json_decode((string) ($job['payload_json'] ?? '{}'), true) ?: [],
            ], false, (int) ($job['lead_id'] ?? 0));

            $this->schedules->markProcessed((int) ($job['id'] ?? 0), [
                'success' => !empty($result['ok']) && !empty($result['success']),
                'code' => (string) ($result['code'] ?? ($result['error'] ?? 'UNKNOWN')),
                'message' => (string) ($result['message'] ?? ''),
            ]);

            $processed++;
            if (!empty($result['ok']) && !empty($result['success'])) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return [
            'ok' => true,
            'action' => 'auth_run_whatsapp_scheduler',
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    public function verifyWebhookChallenge(array $query): array
    {
        $mode = (string) ($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');

        $config = $this->getProviderConfig();
        if ($mode === 'subscribe' && $token !== '' && hash_equals((string) $config['verifyToken'], $token)) {
            return [
                'ok' => true,
                'action' => 'whatsapp_webhook',
                'challenge' => $challenge,
            ];
        }

        return [
            'ok' => false,
            'error' => 'FORBIDDEN',
            'message' => 'Invalid verify token.',
        ];
    }

    public function processWebhookPayload(array $payload): array
    {
        $updated = 0;
        $statuses = [];

        $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];
        foreach ($entries as $entry) {
            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
            foreach ($changes as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $statusItems = is_array($value['statuses'] ?? null) ? $value['statuses'] : [];
                foreach ($statusItems as $statusRow) {
                    if (!is_array($statusRow)) {
                        continue;
                    }
                    $messageId = (string) ($statusRow['id'] ?? '');
                    $status = (string) ($statusRow['status'] ?? 'unknown');
                    if ($messageId === '') {
                        continue;
                    }
                    $updated += $this->logs->updateDeliveryStatusByProviderMessageId($messageId, $status);
                    $statuses[] = ['providerMessageId' => $messageId, 'status' => $status];
                }
            }
        }

        return [
            'ok' => true,
            'action' => 'whatsapp_webhook',
            'ack' => true,
            'updatedCount' => $updated,
            'statuses' => $statuses,
        ];
    }

    public function sendByEventKey(string $eventKey, array $context, bool $isTest = false, ?int $leadId = null): array
    {
        $mapping = $this->mappings->findByEventKey($eventKey);
        if (!is_array($mapping) || empty($mapping['is_enabled'])) {
            $this->logs->create([
                'lead_id' => $leadId,
                'event_key' => $eventKey,
                'phone' => (string) ($context['phone'] ?? ''),
                'attempted' => 1,
                'success' => 0,
                'response_message' => 'Mapping disabled for event key.',
                'request_payload_json' => json_encode($context, JSON_UNESCAPED_SLASHES),
                'response_payload_json' => json_encode(['code' => 'MAPPING_DISABLED'], JSON_UNESCAPED_SLASHES),
            ]);
            return ['ok' => true, 'success' => false, 'code' => 'MAPPING_DISABLED', 'message' => 'Mapping disabled.'];
        }

        $config = $this->getProviderConfig();
        $ready = $this->readiness($config);
        if (empty($ready['readyForSend'])) {
            $this->logs->create([
                'lead_id' => $leadId,
                'event_key' => $eventKey,
                'phone' => (string) ($context['phone'] ?? ''),
                'template_name' => (string) ($mapping['template_name'] ?? ''),
                'language_code' => (string) ($mapping['language_code'] ?? ''),
                'attempted' => 1,
                'success' => 0,
                'response_message' => 'Provider not configured for send.',
                'request_payload_json' => json_encode($context, JSON_UNESCAPED_SLASHES),
                'response_payload_json' => json_encode(['code' => 'WHATSAPP_NOT_CONFIGURED'], JSON_UNESCAPED_SLASHES),
            ]);
            return ['ok' => true, 'success' => false, 'code' => 'WHATSAPP_NOT_CONFIGURED', 'message' => 'Provider not configured.'];
        }

        $variables = is_array($context['variables'] ?? null) ? $context['variables'] : [];
        $components = [];
        if ($variables !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(static fn ($v): array => [
                    'type' => 'text',
                    'text' => (string) $v,
                ], array_values($variables)),
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => (string) ($context['phone'] ?? ''),
            'type' => 'template',
            'template' => [
                'name' => (string) ($mapping['template_name'] ?? ''),
                'language' => ['code' => (string) ($mapping['language_code'] ?? 'en')],
            ],
        ];
        if ($components !== []) {
            $payload['template']['components'] = $components;
        }

        $endpoint = '/v22.0/' . rawurlencode($config['phoneNumberId']) . '/messages';
        $response = $this->metaRequest('POST', $endpoint, $payload, $config['accessToken']);

        $providerMessageId = '';
        if (($response['ok'] ?? false) === true) {
            $messages = is_array($response['data']['messages'] ?? null) ? $response['data']['messages'] : [];
            $providerMessageId = (string) (($messages[0]['id'] ?? '') ?: '');
        }

        $this->logs->create([
            'lead_id' => $leadId,
            'event_key' => $eventKey,
            'phone' => (string) ($context['phone'] ?? ''),
            'template_name' => (string) ($mapping['template_name'] ?? ''),
            'language_code' => (string) ($mapping['language_code'] ?? ''),
            'provider_message_id' => $providerMessageId,
            'delivery_status' => ($response['ok'] ?? false) ? 'accepted' : 'failed',
            'status_updated_at' => date('Y-m-d H:i:s'),
            'attempted' => 1,
            'success' => ($response['ok'] ?? false) ? 1 : 0,
            'http_code' => (int) ($response['httpCode'] ?? 0),
            'response_message' => (string) ($response['message'] ?? ''),
            'request_payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'response_payload_json' => json_encode($response['data'] ?? ['error' => $response['message'] ?? ''], JSON_UNESCAPED_SLASHES),
        ]);

        return [
            'ok' => true,
            'success' => (bool) ($response['ok'] ?? false),
            'action' => $isTest ? 'auth_send_test_whatsapp_template' : 'whatsapp_send',
            'code' => ($response['ok'] ?? false) ? 'SENT' : 'SEND_FAILED',
            'message' => (string) ($response['message'] ?? ''),
            'providerMessageId' => $providerMessageId,
        ];
    }

    private function getProviderConfig(): array
    {
        $accessToken = $this->settings->getValue(self::WHATSAPP_GROUP, 'access_token')
            ?: (string) Env::getProfiled('WHATSAPP_META_ACCESS_TOKEN', '');
        $phoneNumberId = $this->settings->getValue(self::WHATSAPP_GROUP, 'phone_number_id')
            ?: (string) Env::getProfiled('WHATSAPP_META_PHONE_NUMBER_ID', '');
        $businessAccountId = $this->settings->getValue(self::WHATSAPP_GROUP, 'business_account_id')
            ?: (string) Env::getProfiled('WHATSAPP_META_BUSINESS_ACCOUNT_ID', '');
        $verifyToken = $this->settings->getValue(self::WHATSAPP_GROUP, 'verify_token')
            ?: (string) Env::getProfiled('WHATSAPP_META_VERIFY_TOKEN', '');

        return [
            'accessToken' => trim((string) $accessToken),
            'phoneNumberId' => trim((string) $phoneNumberId),
            'businessAccountId' => trim((string) $businessAccountId),
            'verifyToken' => trim((string) $verifyToken),
        ];
    }

    private function readiness(array $config): array
    {
        $accessTokenConfigured = $config['accessToken'] !== '';
        $phoneNumberIdConfigured = $config['phoneNumberId'] !== '';
        $businessAccountIdConfigured = $config['businessAccountId'] !== '';
        $verifyTokenConfigured = $config['verifyToken'] !== '';

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return [
            'accessTokenConfigured' => $accessTokenConfigured,
            'phoneNumberIdConfigured' => $phoneNumberIdConfigured,
            'businessAccountIdConfigured' => $businessAccountIdConfigured,
            'verifyTokenConfigured' => $verifyTokenConfigured,
            'readyForSync' => $accessTokenConfigured && $businessAccountIdConfigured,
            'readyForSend' => $accessTokenConfigured && $phoneNumberIdConfigured,
            'webhookUrl' => $scheme . '://' . $host . '/?action=whatsapp_webhook',
        ];
    }

    private function safeConfig(array $config): array
    {
        return [
            'accessTokenConfigured' => $config['accessToken'] !== '',
            'phoneNumberId' => $config['phoneNumberId'],
            'businessAccountId' => $config['businessAccountId'],
            'verifyTokenConfigured' => $config['verifyToken'] !== '',
        ];
    }

    private function metaRequest(string $method, string $path, array $payload, string $accessToken): array
    {
        try {
            $url = 'https://graph.facebook.com' . $path;
            $headers = [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            if (strtoupper($method) !== 'GET') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
            }

            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($raw === false || $curlError !== '') {
                return [
                    'ok' => false,
                    'httpCode' => $httpCode,
                    'message' => $curlError !== '' ? $curlError : 'Meta request failed.',
                ];
            }

            $decoded = json_decode($raw, true);
            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'ok' => true,
                    'httpCode' => $httpCode,
                    'message' => 'Meta request success.',
                    'data' => is_array($decoded) ? $decoded : [],
                ];
            }

            $errorMessage = is_array($decoded) ? (string) (($decoded['error']['message'] ?? '') ?: 'Meta request failed.') : 'Meta request failed.';
            return [
                'ok' => false,
                'httpCode' => $httpCode,
                'message' => $errorMessage,
                'data' => is_array($decoded) ? $decoded : [],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'httpCode' => 0,
                'message' => $exception->getMessage(),
            ];
        }
    }
}
