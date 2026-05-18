# CRM Admin API Contract

All CRM admin actions require an authenticated admin bearer token.

## Workspace Actions

POST `admin_crm_panel_status`
- Returns CRM configuration status, deployment action names, workspace counts, latest contact, and latest push log.

POST `admin_list_crm_contacts`
- Body: `search`, `source`, `syncStatus`, `page`, `pageSize`
- Returns canonical contacts and pagination.

POST `admin_list_crm_push_logs`
- Body: `search`, `source`, `result`, `page`, `pageSize`
- `result=Success`: `success = 1`
- `result=Failed`: `attempted = 1 AND success = 0`
- `result=Skipped`: `attempted = 0`

POST `admin_test_crm_sync`
- Body: `lead: { name, phone, dob, doa }`
- Creates a controlled test lead with source `crm_controlled_test`, syncs it to CRM, and returns Data Received, CRM Payload Preview, Stored Lead, Canonical Contact, and CRM Push Confirmation.

POST `admin_delete_crm_test_lead`
- Body: `leadId`
- Deletes only approved test-source leads and rebuilds or removes the canonical contact for the phone.

POST `admin_backfill_crm_contacts`
- Rebuilds canonical contacts from the latest lead summary per phone.

POST `admin_export_crm_contacts`
- Body accepts contact filters.
- Returns XLSX metadata: `fileName`, `mimeType`, `base64`, plus `count`.

## Leads Actions

POST `admin_crm_leads_status`
- Body: `search`, `source`, `outcome`, `leadStatus`, `syncStatus`, `fromDate`, `toDate`
- Returns total leads, won, Try Again, and redeemed counts.

POST `admin_list_crm_leads`
- Body: lead filters plus `page`, `pageSize`
- Returns filtered leads and pagination.

POST `admin_export_crm_leads`
- Body accepts lead filters.
- Returns XLSX metadata: `fileName`, `mimeType`, `base64`, plus `count`.

## Utility

POST `sync_crm_by_phone`
POST `sync-crm-by-phone`
- Body: `phone`
- Finds the latest lead for the phone, pushes it to CRM, upserts canonical contact, logs the push attempt, and returns sanitized sync details.
