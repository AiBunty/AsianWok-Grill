# Other Project Admin Panel Implementation Prompt

Use this file as the source prompt/spec when recreating the same admin-panel and spinwheel system in another project. It is written so an engineer or coding agent can implement the full flow without guessing missing pieces.

## How To Use

Give the prompt in the next section to the team or agent working on the other project.

- Replace brand names, URLs, field labels, and reward labels with the new project's values.
- Keep the architectural requirements, security rules, database shape, and admin workflow parity unless there is a deliberate product decision to change them.
- If the target project uses a different framework, preserve the same behavior and data contracts even if file structure differs.

## Implementation Prompt

```md
Implement a complete admin panel and spinwheel lead-management system in this project with full parity to the reference implementation.

Do not build a partial version. Deliver the complete working system: frontend, backend, middleware, authentication, permissions, database schema, exports, diagnostics, and deployment readiness.

The output must be production-ready, not a demo.

---

# 1. Primary Goal

Build a full admin workspace that includes:

1. Admin authentication with role-based access control.
2. Spinwheel lead capture and 24-hour cooldown enforcement.
3. Winner coupon issuance and completion tracking.
4. Admin verification panel for searching a user by phone.
5. Coupon redemption.
6. Winner coupon regeneration.
7. Surprise reward issuance for try-again users.
8. Canonical CRM contact syncing for form-driven leads.
9. CRM logs, exports, and controlled CRM testing from admin.
10. User management and permission management.
11. Settings/configuration management for API secrets and app settings.
12. QR redirect/settings support.
13. Server connectivity diagnostics for database and FTP.
14. A proper admin shell so all modules live in one secured panel.

This must be implemented as a real operations panel, not only as CRUD screens.

---

# 2. Required Architecture

Implement the system with these layers.

## 2.1 Frontend

Create a secured admin SPA shell with:

- Login screen.
- Token-aware authenticated session bootstrap.
- Sidebar navigation.
- Permission-aware module visibility.
- Shared design system and utility helpers.
- Standalone admin pages only where needed, but prefer one portal shell.

Recommended structure:

- `public/admin/admin-portal.html`
- `public/js/admin-modules/base.js`
- `public/js/admin-modules/*.js`
- shared admin CSS theme

The admin shell should dynamically load modules and preserve a clear separation between:

- admin shell/layout
- auth/bootstrap utilities
- per-module rendering and actions

## 2.2 Middleware

Implement middleware for:

- token authorization
- role checks (`admin`, `superadmin`)
- permission checks per module/action
- CORS handling
- request normalization and input validation

Required behavior:

- Missing token must return `UNAUTHORIZED`.
- Disabled users must be blocked.
- Superadmin-only actions must reject non-superadmins.
- Module-sensitive actions must require explicit permission unless the user is superadmin.

## 2.3 Backend

Implement a routed backend with controllers + services + repositories.

Backend stack requirement:

- Use PHP for backend implementation.
- Use MySQL as the primary relational database.
- Use PDO or an equivalent safe DB layer with prepared statements for all database operations.
- Keep business logic in PHP services, not inside frontend code or raw route files.

Recommended layers:

- `Controllers` handle request parsing and transport concerns.
- `Services` own business logic.
- `Repositories` own SQL and persistence.
- `Config` owns environment/bootstrap settings.
- `Support` owns logger, validation, URL helpers, shared helpers.

The routing style can be action-based or REST-based, but the business capability must be equivalent.

Database implementation requirements:

- Write database migrations in SQL or PHP migration files, but target MySQL syntax and behavior.
- Use UTF-8 storage (`utf8mb4`) for all text fields.
- Add indexes for operational lookup fields like `phone`, `coupon_code`, status fields, and created timestamps.
- Keep repository methods narrowly focused so list/export/reporting queries do not get mixed into auth or controller logic.

---

# 3. Admin Modules To Implement

Implement these modules with actual working behavior.

## 3.1 Dashboard

The dashboard must show at minimum:

- total leads
- total try-again leads
- total winning coupons
- total redeemed rewards
- recent activity summary
- optional date filters

## 3.2 Verification / Spin Reward Operations

This is the most important parity module.

Required features:

- search by 10-digit mobile number
- fetch latest lead by phone
- show name, prize, original prize, active reward label, reward source, visit count, dates, source, created timestamp
- show winner coupon code and surprise coupon code separately when relevant
- show whether reward can be redeemed
- show whether try-again user can receive surprise reward
- show whether winner coupon can be regenerated
- redeem action
- regenerate winner coupon action
- issue surprise reward action for try-again user

Reward rules:

- Winner flow uses the primary `coupon_code`.
- Try-again flow can receive a manual surprise reward with `surprise_reward_label` and `surprise_coupon_code`.
- Redemption must apply to the currently active reward.
- Already-redeemed rewards must be blocked.

## 3.3 CRM Workspace

Implement a CRM workspace with:

- configuration status view
- workspace summary
- canonical contacts table with filters and pagination
- CRM push logs table with filters and pagination
- Excel export for contacts
- contact backfill from existing leads
- controlled CRM test lead submission
- delete last CRM test lead
- stored lead preview
- canonical contact preview
- CRM request payload preview
- CRM sync result preview

Important behavior:

- CRM sync should be triggered for lead form submissions and approved admin CRM tests.
- CRM must not be triggered by reward redemption, reward regeneration, or surprise coupon issuance.
- Reward-related responses should not leak CRM-only metadata to the frontend.

## 3.4 User Management

Implement:

- list users
- create user
- enable/disable user
- reset password
- force password change support
- role assignment (`admin` or `superadmin`)
- per-permission toggles
- audit-friendly updates

Required permission keys:

- `dashboard`
- `cashier`
- `verification`
- `eventGuests`
- `eventScanner`
- `eventManagement`
- `menuEditor`
- `cashApprovals`
- `userManagement`

If the other project has fewer modules, keep the permission system extensible and still implement permission-based gating.

## 3.5 Settings / App Configuration

Implement secured admin settings for:

- app settings
- secret/API settings
- QR redirect settings
- WhatsApp/notification settings if used by the new project

Sensitive values must be stored server-side and never exposed publicly.

## 3.6 QR Redirect / QR Operations

Implement:

- QR redirect settings
- QR redirect list
- create/update QR redirect
- activate/deactivate QR redirect
- delete QR redirect
- QR scan logging if the target project needs campaign tracking

## 3.7 Server Connection Diagnostic

Implement a secured admin diagnostic page and backend endpoint that performs non-destructive checks for:

- database DNS resolution
- database TCP reachability
- database login
- FTP DNS resolution
- FTP TCP reachability
- FTP login
- FTP remote path validation

The diagnostic should log a structured JSON record server-side for support/debugging.

## 3.8 Cashier / Event / Menu / Import Modules

If the target project needs complete admin parity, also implement the broader admin shell capability for:

- cashier desk
- superadmin cash approvals
- event management
- event guests
- event entry scanner
- menu editor
- menu category designer
- data import
- settings standalone pages if needed for operational support

If the target project does not need these domain-specific modules, still keep the admin shell extensible enough so they can be added using the same architecture.

---

# 4. Spinwheel Business Flow

Implement the full lead and reward lifecycle.

## 4.1 Lead Submission

On lead submission:

- accept name, phone, country code if applicable, DOB, anniversary, source
- validate name and phone
- normalize phone to canonical digits-only format
- check whether the same phone already completed a spin within the cooldown window
- if cooldown active, return structured cooldown response
- otherwise create a new lead row
- compute `visit_count`
- compute lead number / sequence if needed
- assign prize using the configured prize engine
- if winning prize, generate coupon code immediately
- persist lead as `Unredeemed`
- set CRM sync state to `Pending`
- upsert canonical CRM contact
- sync to CRM if this flow is CRM-enabled

Suggested response shape:

- `ok`
- `result`
- `row` or `leadId`
- `name`
- `prize`
- `leadNumber`
- `visitCount`
- `phone`
- `countryCode`
- `couponCode`

## 4.2 Complete Spin

Implement a second completion step after the user actually finishes the wheel interaction.

Rules:

- requires `leadId` and `phone`
- verify record belongs to that phone
- if already completed, return `already_completed`
- otherwise set `spin_completed_at`
- calculate retry window using 24-hour cooldown
- if winner coupon exists, trigger the winner notification flow

## 4.3 Verify Reward State

Provide a read operation that returns the latest lead state by phone for admin verification.

It must compute:

- active reward source: `winner`, `surprise`, or `none`
- active reward label
- active coupon code
- redeemable state
- surprise-issuable state
- winner-regeneration state

## 4.4 Redeem Reward

Redeem flow requirements:

- admin auth required
- verification permission required
- find latest lead by phone
- determine active reward
- block if none exists
- block if already redeemed
- redeem either winner reward or surprise reward depending on active state
- set redemption timestamps
- send redemption notification if configured

Do not run CRM sync from this action.

## 4.5 Regenerate Winner Coupon

Requirements:

- admin auth required
- verification permission required
- only for winning prizes
- generate a fresh coupon code
- persist it to `coupon_code`
- return updated code

Do not run CRM sync from this action.

## 4.6 Issue Surprise Reward For Try-Again User

Requirements:

- admin auth required
- verification permission required
- only allowed when latest lead is eligible for surprise issuance
- accept selected reward label
- generate surprise coupon code
- save `surprise_reward_label`, `surprise_coupon_code`, `surprise_issued_at`, `surprise_issued_by`
- clear previous redemption state if needed for the newly-issued surprise reward
- send notification if configured

Do not run CRM sync from this action.

## 4.7 Detailed End-To-End Flow Examples

Use the following examples as implementation guides. The exact field names may be adapted for the new project, but the behavior must remain the same.

### Example A: Lead submission for a winning user

Frontend request:

```json
{
  "action": "submit_lead",
  "name": "Aarav Sharma",
  "phone": "9876543210",
  "countryCode": "+91",
  "dateOfBirth": "1997-08-15",
  "dateOfAnniversary": "",
  "source": "spinwheel_landing"
}
```

Backend flow:

1. PHP controller receives request and normalizes body/query.
2. PHP service validates required fields and cleans phone to digits-only form.
3. Repository checks MySQL for the latest completed spin by the same phone.
4. If cooldown does not block, repository inserts new lead row.
5. Prize engine decides the prize.
6. If prize is winning, service generates `coupon_code`.
7. Repository stores lead as active unredeemed reward.
8. Canonical CRM contact is inserted or updated.
9. CRM sync is attempted and log entry is stored.
10. API returns success payload to frontend.

Example success response:

```json
{
  "ok": true,
  "result": "success",
  "leadId": 1542,
  "name": "Aarav Sharma",
  "phone": "9876543210",
  "countryCode": "+91",
  "prize": "Free Mocktail",
  "couponCode": "NK-7F3KQ2",
  "visitCount": 3,
  "crmSyncStatus": "success"
}
```

MySQL effect:

- one new row added in `leads`
- one upsert in `crm_contacts`
- one insert in `crm_push_logs`

### Example B: Lead submission blocked by cooldown

Example blocked response:

```json
{
  "ok": false,
  "result": "cooldown_active",
  "message": "This phone number has already completed a spin in the last 24 hours.",
  "retryAfterSeconds": 43120,
  "lastSpinCompletedAt": "2026-05-08 10:15:00"
}
```

Behavior rules:

- do not create a new lead row
- do not generate a coupon
- do not run CRM sync
- frontend should show cooldown message and block wheel start

### Example C: Complete spin after wheel animation ends

Frontend request:

```json
{
  "action": "complete_spin",
  "leadId": 1542,
  "phone": "9876543210"
}
```

Expected backend work:

1. Verify `leadId` belongs to the same phone.
2. If `spin_completed_at` is empty, update it in MySQL.
3. Calculate next eligible spin time.
4. If lead is a winner, trigger winner notification workflow.

Example response:

```json
{
  "ok": true,
  "result": "spin_completed",
  "leadId": 1542,
  "spinCompletedAt": "2026-05-08 21:40:10",
  "nextEligibleSpinAt": "2026-05-09 21:40:10"
}
```

### Example D: Admin verifies user by phone

Frontend request:

```json
{
  "action": "verify",
  "phone": "9876543210"
}
```

Example response for winner state:

```json
{
  "ok": true,
  "name": "Aarav Sharma",
  "phone": "9876543210",
  "prize": "Free Mocktail",
  "activeRewardSource": "winner",
  "activeRewardLabel": "Free Mocktail",
  "activeCouponCode": "NK-7F3KQ2",
  "canRedeem": true,
  "canRegenerateWinnerCoupon": true,
  "canIssueSurpriseReward": false,
  "status": "Unredeemed",
  "createdAt": "2026-05-08 21:38:55"
}
```

Example response for try-again state before surprise reward:

```json
{
  "ok": true,
  "name": "Diya Mehta",
  "phone": "9990001234",
  "prize": "Try Again",
  "activeRewardSource": "none",
  "activeRewardLabel": null,
  "activeCouponCode": null,
  "canRedeem": false,
  "canRegenerateWinnerCoupon": false,
  "canIssueSurpriseReward": true,
  "status": "Unredeemed"
}
```

### Example E: Admin redeems active reward

Frontend request:

```json
{
  "action": "redeem",
  "phone": "9876543210",
  "token": "ADMIN_AUTH_TOKEN"
}
```

Expected backend work:

1. Auth middleware validates token.
2. Permission middleware requires `verification` or superadmin.
3. Service loads latest lead by phone.
4. Service detects whether active reward is winner or surprise reward.
5. Matching redemption column is updated in MySQL.
6. Notification can be sent.
7. CRM must not run.

Example response:

```json
{
  "ok": true,
  "result": "redeemed",
  "phone": "9876543210",
  "rewardSource": "winner",
  "rewardLabel": "Free Mocktail",
  "couponCode": "NK-7F3KQ2",
  "redeemedAt": "2026-05-08 22:10:00"
}
```

### Example F: Admin regenerates winner coupon

Frontend request:

```json
{
  "action": "regen_coupon",
  "phone": "9876543210",
  "token": "ADMIN_AUTH_TOKEN"
}
```

Example response:

```json
{
  "ok": true,
  "result": "coupon_regenerated",
  "phone": "9876543210",
  "couponCode": "NK-9P4TR8"
}
```

Behavior rules:

- allowed only for a winning lead
- update `coupon_code` in MySQL
- optional notification allowed
- CRM must not run

### Example G: Admin issues surprise reward to try-again user

Frontend request:

```json
{
  "action": "redeem",
  "phone": "9990001234",
  "rewardLabel": "Dessert Shot",
  "mode": "issue_surprise_reward",
  "token": "ADMIN_AUTH_TOKEN"
}
```

Recommended implementation note:

- You may expose this as a dedicated action such as `issue_surprise_reward` if that is cleaner in the new project.
- Even if route names differ, the business behavior must stay the same.

Example response:

```json
{
  "ok": true,
  "result": "surprise_reward_issued",
  "phone": "9990001234",
  "rewardSource": "surprise",
  "rewardLabel": "Dessert Shot",
  "couponCode": "SUR-4L8M2Q"
}
```

MySQL effect:

- update `surprise_reward_label`
- update `surprise_coupon_code`
- update `surprise_issued_at`
- update `surprise_issued_by`

### Example H: CRM admin test flow

Frontend request:

```json
{
  "action": "admin_test_crm_sync",
  "name": "CRM Test User",
  "phone": "9000011111",
  "source": "admin_crm_test",
  "token": "ADMIN_AUTH_TOKEN"
}
```

Expected backend work:

1. Require admin auth.
2. Create or simulate a clean test lead.
3. Upsert canonical contact.
4. Build outbound CRM payload.
5. Attempt CRM sync.
6. Save push log.
7. Return request/response preview data for admin troubleshooting.

Example response:

```json
{
  "ok": true,
  "result": "crm_test_completed",
  "leadStored": true,
  "contactStored": true,
  "crmAttempted": true,
  "crmSuccess": true,
  "httpCode": 200
}
```

### Example I: Server diagnostic flow

Diagnostic backend should run this exact order:

1. Read PHP environment variables.
2. Resolve database hostname.
3. Open TCP socket to MySQL host and port.
4. Attempt PDO login to MySQL.
5. Resolve FTP hostname.
6. Open TCP socket to FTP port 21.
7. Attempt FTP login.
8. Attempt to change directory to configured remote path.
9. Save structured JSON log file.
10. Return a safe admin-facing response.

Example response:

```json
{
  "ok": true,
  "generatedAt": "2026-05-08T22:25:00+05:30",
  "database": {
    "ok": true,
    "stage": "pdo",
    "message": "Database login succeeded."
  },
  "ftp": {
    "ok": true,
    "stage": "session",
    "message": "FTP login and working-directory check succeeded."
  }
}
```

### Example J: PHP + MySQL implementation pattern

Use PHP services and repositories similar to this pattern:

```php
final class LeadService
{
    public function submitLead(array $data): array
    {
        $phone = $this->normalizePhone((string) ($data['phone'] ?? ''));
        $latestCompleted = $this->leadRepository->findLatestCompletedByPhone($phone);

        if ($this->isCooldownActive($latestCompleted)) {
            return [
                'ok' => false,
                'result' => 'cooldown_active',
            ];
        }

        $prize = $this->prizeEngine->pickPrize();
        $couponCode = $prize->isWinner() ? $this->couponGenerator->generate() : null;

        $leadId = $this->leadRepository->create([
            'name' => $data['name'],
            'phone' => $phone,
            'prize' => $prize->label(),
            'coupon_code' => $couponCode,
        ]);

        $this->crmContactRepository->upsertFromLead($leadId);
        $this->crmService->syncLeadById($leadId);

        return [
            'ok' => true,
            'leadId' => $leadId,
            'couponCode' => $couponCode,
        ];
    }
}
```

Implementation rules for this pattern:

- controller receives request and delegates to service
- service owns business decisions
- repository owns MySQL queries
- middleware owns auth and permission checks
- frontend only renders and triggers API calls

---

# 5. Data Model Requirements

Implement at least these tables or equivalent models.

## 5.1 `users`

Fields:

- `id`
- `username`
- `display_name`
- `role`
- `password_hash`
- `password_salt`
- `status`
- `force_password_change`
- `failed_attempts`
- `lockout_until`
- `last_login_at`
- `last_login_ip`
- `created_at`
- `created_by`
- `updated_at`
- `updated_by`
- `permissions` JSON

## 5.2 `leads`

Fields:

- `id`
- `created_at`
- `spin_completed_at`
- `name`
- `phone`
- `prize`
- `status` (`Unredeemed` / `Redeemed`)
- `date_of_birth`
- `date_of_anniversary`
- `source`
- `visit_count`
- `coupon_code`
- `surprise_reward_label`
- `surprise_coupon_code`
- `surprise_issued_at`
- `surprise_issued_by`
- `surprise_redeemed_at`
- `crm_sync_status`
- `crm_sync_code`
- `crm_sync_message`
- `redeemed_at`

Indexes:

- phone
- coupon_code
- status
- spin_completed_at

## 5.3 `crm_contacts`

Fields:

- `id`
- `created_at`
- `updated_at`
- `phone`
- `name`
- `date_of_birth`
- `date_of_anniversary`
- `first_seen_at`
- `last_seen_at`
- `latest_source`
- `latest_lead_id`
- `latest_lead_created_at`
- `total_submissions`
- `latest_crm_sync_status`
- `latest_crm_sync_code`
- `latest_crm_sync_message`
- `last_crm_attempted_at`
- `last_crm_pushed_at`

## 5.4 `crm_push_logs`

Fields:

- `id`
- `created_at`
- `contact_id`
- `lead_id`
- `phone`
- `contact_name`
- `trigger_source`
- `crm_endpoint`
- `attempted`
- `success`
- `http_code`
- `retry_count`
- `attempt_count`
- `response_message`
- `request_payload_json`
- `attempts_json`

## 5.5 Supporting tables

If parity is required, also create equivalents for:

- auth audit logs
- app settings
- QR redirect settings
- QR redirects
- QR scans
- event/cash/menu/import domain tables used by the other project's scope

---

# 6. Backend Endpoints / Actions

Implement endpoints equivalent to the following capabilities.

## 6.1 Auth

- `auth_login`
- `auth_logout`
- `auth_me`
- `auth_bootstrap_status`
- `auth_list_users`
- `auth_create_user`
- `auth_set_user_status`
- `auth_delete_user`
- `auth_reset_password`
- `auth_set_user_permissions`
- `auth_get_api_settings`
- `auth_set_api_settings`
- `auth_get_app_settings`
- `auth_set_app_settings`
- `auth_get_qr_redirect_settings`
- `auth_set_qr_redirect_settings`
- `auth_list_qr_redirects`
- `auth_save_qr_redirect`
- `auth_set_qr_redirect_active`
- `auth_delete_qr_redirect`

## 6.2 Lead / Spinwheel

- `submit_lead`
- `complete_spin`
- `verify`
- `redeem`
- `regen_coupon`
- `counter`
- `admin_dashboard_stats`
- `add_test_lead`
- `add_test_25_coupon`

## 6.3 CRM workspace

- `admin_crm_panel_status`
- `admin_test_crm_sync`
- `admin_delete_crm_test_lead`
- `admin_list_crm_contacts`
- `admin_list_crm_push_logs`
- `admin_backfill_crm_contacts`
- `admin_export_crm_contacts`
- `admin_crm_leads_status`
- `admin_list_crm_leads`
- `admin_export_crm_leads`
- `sync_crm_by_phone`

## 6.4 Diagnostics

- secured endpoint equivalent to `server-connection-diagnostic.php`

Use consistent structured JSON responses with:

- `ok`
- `error` when failed
- `message`
- operation-specific payload fields

---

# 7. Authentication And Security Requirements

Implement these rules.

## 7.1 Login security

- lockout after repeated failures
- password policy with minimum length
- audit login success/failure
- force password change support
- active/disabled status support

## 7.2 Token model

- signed auth token
- token expiry window
- authenticated user context fetch (`me`)
- logout invalidation or stateless expiration strategy

## 7.3 Permission model

- superadmin bypass for all permission checks
- admin users limited by explicit permission JSON
- frontend navigation must hide modules without permission
- backend must still enforce permission checks even if UI hides buttons

## 7.4 Input validation

Validate on both client and server:

- phone
- names
- dates
- source strings
- reward labels
- pagination params
- exports/filters
- IDs and booleans

## 7.5 Security boundaries

- never expose DB or FTP credentials in error responses
- never expose full secret values back to public frontend
- never trust frontend permissions alone
- keep logs server-side
- keep `.env` server-side and authoritative in production

---

# 8. Frontend UX Requirements

The admin UI must feel like a real operations product.

Required UX characteristics:

- clear sidebar grouping
- status messages per action
- empty states
- disabled states while requests run
- pagination for large lists
- export buttons where reporting exists
- readable audit/sync result previews
- role/permission aware rendering
- reusable shared helpers for API calls and formatting

The verification module should be optimized for fast staff operations.

The CRM workspace should be optimized for troubleshooting and data confidence.

The server diagnostic page should clearly separate:

- DNS
- TCP/socket
- login
- path validation

---

# 9. Notifications / External Integrations

If the other project supports messaging, keep the event-driven approach.

Suggested events:

- winner coupon issued
- try-again surprise issued
- coupon redeemed

The integration layer should:

- build event-specific payloads
- log attempts
- support skipped state when configuration is missing
- support admin configuration screens when relevant

CRM integration rules:

- CRM sync belongs to lead/contact flows
- reward operational flows must not trigger CRM automatically

---

# 10. ServerByt Hosting And Connection Guidance

This implementation will be hosted on ServerByt.

Build the deployment/configuration so the server can be wired using standard environment variables.

## 10.1 Environment variable format

Support these keys in `.env`:

```env
APP_ENV=production
APP_URL=https://your-domain.example.com/backend/
APP_PUBLIC_SITE_URL=https://your-domain.example.com/
APP_TIMEZONE=Asia/Kolkata
CORS_ALLOWED_ORIGINS=https://your-domain.example.com,https://www.your-domain.example.com

DB_HOST=your-mysql-host
DB_PORT=3306
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASS=your_database_password

FTP_HOST=ftp.your-domain.example.com
FTP_USER=your_ftp_username
FTP_PASS=your_ftp_password
FTP_REMOTE_PATH=/public_html/backend

NK_ENV_PROFILE=live

APP_ENV_LIVE=production
APP_URL_LIVE=https://your-domain.example.com/backend/
APP_PUBLIC_SITE_URL_LIVE=https://your-domain.example.com/
DB_HOST_LIVE=your-live-db-host
DB_PORT_LIVE=3306
DB_NAME_LIVE=your_live_db_name
DB_USER_LIVE=your_live_db_user
DB_PASS_LIVE=your_live_db_password
FTP_HOST_LIVE=ftp.your-domain.example.com
FTP_USER_LIVE=your_live_ftp_user
FTP_PASS_LIVE=your_live_ftp_password
FTP_REMOTE_PATH_LIVE=/public_html/backend
```

Important implementation detail:

- Support both `DB_HOST=host` and `DB_HOST=host:port`.
- If host includes a port suffix, parse it correctly and override `DB_PORT`.

## 10.2 Database connection format for ServerByt

Implement database configuration so the operator can fill values from the ServerByt panel in this format:

- Host: `hostname` or `hostname:3306`
- Port: `3306` unless ServerByt provides a custom port
- Database name: exact DB name from hosting panel
- Username: exact DB username from hosting panel
- Password: exact DB password from hosting panel

Connection DSN format:

```text
mysql:host=HOST;port=PORT;dbname=DB_NAME;charset=utf8mb4
```

## 10.3 FTP connection format for ServerByt

Implement FTP config and diagnostics expecting:

- FTP host
- FTP username
- FTP password
- remote deploy path

Expected format:

```text
FTP_HOST=ftp.your-domain.example.com
FTP_USER=your_ftp_user
FTP_PASS=your_ftp_password
FTP_REMOTE_PATH=/public_html/backend
```

The diagnostic must validate:

- DNS resolution of FTP host
- TCP connectivity to port `21`
- login success
- `CWD` into configured remote path

If ServerByt provides SFTP instead of plain FTP, prefer SFTP operationally for deployment, but still keep the FTP diagnostic contract if the hosting team depends on FTP credentials.

## 10.4 ServerByt operator instructions to include in docs

Add deployment notes telling the operator to collect these values from ServerByt:

### Database

- MySQL hostname
- MySQL port
- database name
- database username
- database password

### FTP

- FTP hostname
- FTP username
- FTP password
- target upload directory

### Recommended remote paths

Examples only, to be replaced by actual ServerByt panel values:

- `/public_html/`
- `/public_html/backend/`
- `/domains/your-domain/public_html/`

Never hardcode these path guesses into logic. They must come from configuration.

---

# 11. File And Folder Recommendation For The Target Project

If the target project does not already have a strong structure, use this baseline:

```text
app/
  Config/
  Controllers/
  Middleware/
  Models/
  Repositories/
  Routes/
  Services/
  Support/
bootstrap/
database/
  migrations/
docs/
  admin/
  deployment/
public/
  admin/
  js/admin-modules/
  css/
storage/
  logs/
  tmp/
vendor/
index.php
router.php
migrate.php
```

---

# 12. Acceptance Criteria

Do not mark implementation complete until all of these are true.

## 12.1 Auth

- admin can log in
- disabled user is blocked
- superadmin restrictions work
- permission gating works in backend and frontend

## 12.2 Spinwheel

- new valid lead can be created
- cooldown blocks repeat spin completion within 24 hours
- winning lead gets coupon code
- complete-spin sets `spin_completed_at`
- verify returns correct reward state
- redeem works only when active reward is redeemable
- winner regen works
- surprise reward issue works for try-again users

## 12.3 CRM

- lead submission updates canonical contact
- CRM test from admin works
- CRM logs are stored
- contacts table filters work
- export works
- reward flows do not trigger CRM automatically

## 12.4 Admin shell

- sidebar navigation works
- modules load correctly
- unauthorized modules are hidden and blocked server-side

## 12.5 Server readiness

- `.env` driven config works
- DB connection works with ServerByt-provided values
- FTP diagnostic works with ServerByt-provided values
- production secrets stay server-side

## 12.6 Documentation

- add setup steps
- add migration steps
- add deployment steps
- add ServerByt env template
- add admin user bootstrap instructions

---

# 13. Delivery Constraints

- Do not ship placeholder endpoints.
- Do not leave TODO-only modules.
- Do not build mock admin data when production persistence is required.
- Do not skip middleware/permissions just because the frontend hides actions.
- Do not couple CRM sync with coupon redeem/regenerate flows.
- Do not expose secrets or raw credentials in API responses.

Implement the complete system end to end.
```

## Source Notes From The Reference Project

This prompt is based on the current reference implementation characteristics below.

### Admin/auth shape

- The admin layer uses a token-based auth model with `admin` and `superadmin` roles.
- Permission keys are explicit and backend-enforced.
- The admin portal is a JS module-based SPA shell.

### Spinwheel/backend shape

- Lead logic is owned by a dedicated service and repository.
- The system distinguishes `submit_lead` from `complete_spin`.
- Cooldown is enforced on completed spins, not only submissions.
- Winner rewards and surprise rewards are treated as separate reward sources.

### CRM behavior

- Form/contact flows sync CRM.
- Reward operational flows do not.
- CRM has canonical contacts + push logs + export + test tools.

### Deployment/config shape

- The backend reads `.env` into `$_ENV`.
- Profile-specific overrides are supported through `*_LIVE` and `*_LOCAL` suffixed variables.
- DB config supports `DB_HOST=host:port`.
- FTP config is already modeled as `FTP_HOST`, `FTP_USER`, `FTP_PASS`, `FTP_REMOTE_PATH`.
- A diagnostic endpoint checks both MySQL and FTP connectivity non-destructively.

## Recommended Next Step

Use this file as the implementation brief for the other project, then adapt the domain labels, URLs, and reward definitions before coding.
