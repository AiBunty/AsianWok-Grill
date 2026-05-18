-- 004_namastemenu_source_migration.sql
-- Purpose: migrate legacy source key `namaste_chef` to `namastemenu` for Menu C.
-- Safe to run multiple times.

START TRANSACTION;

-- Rename legacy source key if it exists.
UPDATE menu_sources
SET source_key = 'namastemenu',
    source_name = 'Namaste Menu',
    is_active = 1,
    updated_at = NOW()
WHERE source_key = 'namaste_chef';

-- Ensure primary source exists for environments where legacy row was absent.
INSERT INTO menu_sources (source_key, source_name, source_type, source_url, source_sheet_id, is_active, created_at, updated_at)
SELECT
    'namastemenu',
    'Namaste Menu',
    'gviz',
    'https://docs.google.com/spreadsheets/d/1BbxQ-HN-QsknQAXGp75IpaGqfnD6b1acLPnMUdi5hAg/gviz/tq?tqx=out:json;reqId:1&tq=select%20*&headers=1',
    '1BbxQ-HN-QsknQAXGp75IpaGqfnD6b1acLPnMUdi5hAg',
    1,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1
    FROM menu_sources
    WHERE source_key = 'namastemenu'
);

COMMIT;
