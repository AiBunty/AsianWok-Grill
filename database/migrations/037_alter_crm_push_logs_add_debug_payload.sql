-- Migration 037: Add debug_payload_json column to crm_push_logs
-- Stores the clean nested payload shape (no flat dotted keys, no api_token)
-- for quick side-by-side comparison of failed vs successful CRM sends.

ALTER TABLE `crm_push_logs`
    ADD COLUMN `debug_payload_json` TEXT NULL AFTER `request_payload_json`;
