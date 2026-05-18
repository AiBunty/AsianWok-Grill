# CRM Admin Test Checklist

## CRM Workspace
- [ ] `crm-panel` loads configuration status, deployment actions, contacts count, and push logs count.
- [ ] Contacts search filters by mobile, name, source, or sync code.
- [ ] Contacts source and sync status filters work with pagination.
- [ ] Contacts table columns render Mobile, Name, DOB, DOA, Total Entries, Last Seen, Source, Sync, Code.
- [ ] Push logs search filters by phone, name, source, or response message.
- [ ] Push log result filters match Success, Failed, and Skipped semantics.
- [ ] Push logs table columns render When, Mobile, Name, Source, Result, HTTP, Attempts.

## Controlled CRM Test
- [ ] Run CRM Test accepts name, phone, DOB, and DOA.
- [ ] Test creates a lead with source `crm_controlled_test`.
- [ ] Test output renders Data Received.
- [ ] Test output renders CRM Payload Preview without CRM auth token.
- [ ] Test output renders Lead Stored In Database.
- [ ] Test output renders Canonical Contact.
- [ ] Test output renders CRM Push Confirmation.
- [ ] Delete Last Test Lead deletes only the last approved test lead.
- [ ] Delete Last Test Lead refuses non-test sources.
- [ ] Canonical contact rebuilds from remaining leads or is deleted when no leads remain for that phone.

## Backfill
- [ ] Backfill Contacts processes one latest lead summary per unique phone.
- [ ] Canonical contacts remain unique by phone after repeated backfills.
- [ ] first_seen_at stays earliest and last_seen_at stays latest.
- [ ] latest lead metadata is overwritten from the latest lead.

## CRM Leads
- [ ] `crm-leads` summary renders total leads, won, Try Again, and redeemed.
- [ ] Search, source, outcome, leadStatus, syncStatus, fromDate, and toDate filters combine correctly.
- [ ] Leads table columns render When, Mobile, Name, Prize, Outcome, Coupon, Status, Redeemed, Source, CRM.
- [ ] Outcome badge is Try Again when prize contains Try Again.
- [ ] Outcome badge is Won when prize is non-empty and not Try Again.
- [ ] Outcome badge is Pending when prize is empty.

## Exports
- [ ] Download Contacts Excel returns XLSX with the exact required contacts columns.
- [ ] Download Leads Excel returns XLSX with the exact required leads columns.
- [ ] Export payloads contain `fileName`, `mimeType`, and `base64`.

## Security
- [ ] Unauthenticated requests to every CRM admin action are rejected.
- [ ] `sync_crm_by_phone` and `sync-crm-by-phone` require admin auth.
- [ ] CRM auth token never appears in UI payload previews or push log request payload JSON.
