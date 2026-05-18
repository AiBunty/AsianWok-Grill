# QR Routing + WhatsApp Cloud API Docs

## Base
- All actions are served via `/?action={actionName}`.
- `GET` actions use query params.
- `POST` actions expect JSON body with `action` and payload fields.

## Admin Actions (superadmin)

### `auth_get_whatsapp_workspace` (POST)
- Request: `{ "action": "auth_get_whatsapp_workspace" }`
- Response:
  - `workspace.config`
  - `workspace.readiness`
  - `workspace.eventKeys`
  - `workspace.templates`
  - `workspace.mappings`
  - `workspace.drafts`
  - `workspace.logs`

### `auth_save_whatsapp_config` (POST)
- Request fields:
  - `config.accessToken`
  - `config.phoneNumberId`
  - `config.businessAccountId`
  - `config.verifyToken`

### `auth_sync_whatsapp_templates` (POST)
- Reads templates from Meta Graph API and upserts local catalog.
- Response includes `syncedCount` and `templates`.

### `auth_save_whatsapp_mapping` (POST)
- Request fields:
  - `mapping.eventKey`
  - `mapping.templateName`
  - `mapping.languageCode`
  - `mapping.mappedTemplateUid`
  - `mapping.mappedVersionId` (optional)
  - `mapping.isEnabled`

### `auth_send_test_whatsapp_template` (POST)
- Request fields:
  - `payload.phone`
  - `payload.eventKey`
  - `payload.variables` (optional array)

### `auth_save_whatsapp_template_draft` (POST)
- Request fields in `draft`:
  - `id` (optional for edit)
  - `draftName`
  - `templateName`
  - `category`
  - `languageCode`
  - `headerType`, `headerText`, `bodyText`, `footerText`
  - `buttons`, `sampleVariables`, `exampleMediaHandle`

### `auth_submit_whatsapp_template_draft` (POST)
- Request: `{ "draftId": <id> }`
- Submits draft to Meta and records local version snapshot.

### `auth_preview_whatsapp_template` (POST)
- Request fields:
  - `templateName`, `languageCode`
  - `sampleVariables`
  - `draftId` (optional)
- Response includes rendered preview text.

### `auth_run_whatsapp_scheduler` (POST)
- Optional: `limit`
- Processes due pending jobs.

### `auth_get_qr_redirect_settings` (GET)
- Response:
  - `settings` (legacy compatibility)
  - `settingsByChannel`
  - `rows`

### `auth_set_qr_redirect_settings` (POST)
- Strict channel payload:
  - `channel` (`customer`|`admin`)
  - `destinationMode` (`preset`|`manual`)
  - `destinationKey`
  - `manualUrl`
  - `isActive`
- Legacy compatibility payload also accepted:
  - `settings.defaultTargetUrl`, `settings.fallbackSlug`

### `auth_list_qr_redirects` (GET)
- Returns all redirect registry rows.

### `auth_save_qr_redirect` (POST)
- Request fields:
  - `id` (optional)
  - `name`
  - `slug`
  - `redirectMode` (`preset`|`manual`)
  - `presetKey`
  - `manualUrl`
  - `notes`
  - `isActive`

### `auth_set_qr_redirect_active` (POST)
- Request: `{ "id": <id>, "isActive": true|false }`

### `auth_delete_qr_redirect` (POST)
- Request: `{ "id": <id> }`
- System records cannot be deleted.

## QR Runtime Actions

### `qr_scan_client` (POST)
- Input:
  - `channel`, `qrSlug`, `qrId`
  - `destinationKey`, `destinationLabel`, `resolvedUrl`
  - user/device metadata fields
- Output:
  - `ok`
  - `action=qr_scan_client`
  - `scanNumber`
  - `channel`
  - `qrSlug`
  - `emailTriggerInterval`

### `qr_redirect_resolve` (GET)
- Query:
  - `slug` (optional)
  - `channel` (optional)
- Output:
  - `qrId`, `qrSlug`, `qrName`
  - `destinationMode`, `destinationKey`, `destinationLabel`
  - `resolvedUrl`, `manualUrl`, `fallbackUsed`

### `qr_report` (GET)
- Output:
  - `totalScans`
  - `channelCounts`
  - `qrSummary`
  - `recentScans`
  - `source`
  - `rows` only when authenticated admin token is valid

## Webhook

### `whatsapp_webhook` (GET)
- Query:
  - `hub.mode` (`subscribe`)
  - `hub.verify_token`
  - `hub.challenge`
- Behavior:
  - valid token => returns plain text challenge
  - invalid token => `403` plain text

### `whatsapp_webhook` (POST)
- Parses `entry[].changes[].value.statuses[]` and updates:
  - `whatsapp_message_logs.delivery_status`
  - `whatsapp_message_logs.status_updated_at`

## Runtime Route
- Stable slug path: `/qr/{slug}`
- Served by shared page: `asianwokandgrill.in/qr/scan.html`
- Flow: resolve -> log scan -> redirect with timeout fallback
