# QR + WhatsApp Validation Checklist

## QR Routing
- [ ] `/qr/guest-menu` loads shared scan page and redirects via backend resolve API.
- [ ] `/qr/admin-portal` resolves to admin destination.
- [ ] Custom slug resolves by `qr_redirects` registry.
- [ ] Inactive slug falls back to default destination.
- [ ] Invalid manual URL falls back safely.
- [ ] Preset destination updates work without regenerating QR image.

## QR Scan Analytics
- [ ] `qr_scan_client` writes scan row with slug/channel/destination metadata.
- [ ] `scan_number` increments sequentially per channel.
- [ ] Device metadata fields are persisted.
- [ ] `qr_report` returns total, channel counts, summary, latest rows.
- [ ] `rows` appears only for authenticated admin requests.

## QR Admin Security
- [ ] `auth_*qr_redirect*` actions reject non-superadmin users.
- [ ] System records (`guest-menu`, `admin-portal`) cannot be deleted.
- [ ] System records cannot be deactivated.

## WhatsApp Workspace
- [ ] Config save updates readiness booleans correctly.
- [ ] `readyForSync` true only when token + business account id exist.
- [ ] `readyForSend` true only when token + phone number id exist.
- [ ] Webhook URL is shown as `...?action=whatsapp_webhook`.

## Template Sync + Mapping
- [ ] Template sync pulls templates from Meta and upserts catalog.
- [ ] Mapping rejects unknown event keys.
- [ ] Mapping enable requires template name + language.
- [ ] Mapping save persists linked version/template uid values.

## Draft Studio
- [ ] Draft save creates/updates local draft rows.
- [ ] Draft submit sends payload to Meta template endpoint.
- [ ] Submit status and rejection reason are persisted.
- [ ] Preview endpoint renders placeholders from sample values.

## Send + Logs
- [ ] Test send creates message log with request/response payload JSON.
- [ ] Disabled mapping logs `MAPPING_DISABLED` skip.
- [ ] Missing provider config logs `WHATSAPP_NOT_CONFIGURED` skip.
- [ ] Successful send stores provider message id.

## Scheduler
- [ ] Scheduler picks only `pending` and `due_at <= now` rows.
- [ ] Attempt count increments for processed jobs.
- [ ] Status/result fields are updated correctly.

## Webhook
- [ ] GET challenge succeeds with valid verify token.
- [ ] GET challenge rejects invalid token with 403.
- [ ] POST status payload updates log `delivery_status` by `provider_message_id`.

## Menu Blocker WhatsApp Draft CTAs
- [ ] Admin Settings saves `hotelWhatsappNo`, `menuBlockerStaffCode`, and `eventEntryPasscode`.
- [ ] Public `settings_get&setting_group=menuBlocker` exposes `hotelWhatsappNo` before blocker interaction.
- [ ] Winner with non-Try Again prize and `couponCode` shows coupon box, `Copy Code`, and `Send to Admin`.
- [ ] Winner state never shows `Ask Captain For Surprise on WhatsApp`.
- [ ] Try Again state hides coupon box, coupon actions, and `Send to Admin`.
- [ ] Try Again state shows only `Ask Captain For Surprise on WhatsApp` as the WhatsApp CTA.
- [ ] `Send to Admin` opens `https://wa.me/{digitsOnlyHotelNumber}?text={encodedMultilineMessage}`.
- [ ] Winner draft contains Name, Mobile, DOB, Anniversary, Prize, Coupon Code, and Requested At.
- [ ] Try Again draft greets the captain, includes customer details, and includes the explicit surprise request lines.
- [ ] Destination number resolves from admin `hotelWhatsappNo`, then footer `tel:` link, then hard fallback.
- [ ] Missing destination number shows `Hotel WhatsApp number is not configured.` when no source is available.
- [ ] Missing/invalid draft data shows `Unable to prepare WhatsApp draft.`
- [ ] CTA flows open correctly on mobile and desktop browsers.

## Final Readiness
- [ ] Env keys set in production:
  - `WHATSAPP_META_ACCESS_TOKEN`
  - `WHATSAPP_META_PHONE_NUMBER_ID`
  - `WHATSAPP_META_BUSINESS_ACCOUNT_ID`
  - `WHATSAPP_META_VERIFY_TOKEN`
- [ ] At least one enabled event mapping exists.
- [ ] One real message send + webhook callback validated.
