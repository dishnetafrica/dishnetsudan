-- Migration 054: Move critical JSON state files to SQLite
-- Fixes race condition in duplicate_confirmations.json, wa_pusher_processed.json

-- ── duplicate_confirmations ───────────────────────────────────────────────────
-- Previously written as flat JSON file with no locking (race condition under
-- concurrent KYC submissions). Now a proper SQLite table with atomic INSERTs.
CREATE TABLE IF NOT EXISTS duplicate_confirmations (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    staff_id        INTEGER NOT NULL DEFAULT 0,
    staff_name      TEXT    NOT NULL DEFAULT '',
    phone           TEXT    NOT NULL DEFAULT '',
    existing_name   TEXT    NOT NULL DEFAULT '',
    existing_crm_id INTEGER NOT NULL DEFAULT 0,
    note            TEXT    NOT NULL DEFAULT '',
    review_status   TEXT    NOT NULL DEFAULT 'pending',
    reviewed_by     TEXT,
    reviewed_at     TEXT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_dup_conf_status   ON duplicate_confirmations(review_status);
CREATE INDEX IF NOT EXISTS idx_dup_conf_staff    ON duplicate_confirmations(staff_id);
CREATE INDEX IF NOT EXISTS idx_dup_conf_phone    ON duplicate_confirmations(phone);

-- ── wa_pusher_processed ───────────────────────────────────────────────────────
-- Previously a JSON array of processed pkIds written without locking.
-- Now a SQLite table — INSERT OR IGNORE prevents duplicates atomically.
CREATE TABLE IF NOT EXISTS wa_pusher_processed (
    pkid       INTEGER PRIMARY KEY,  -- the WhatsML Message.pkId
    processed_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ── wa_sync_cursor ────────────────────────────────────────────────────────────
-- Stores the last synced pkId and sync metadata for cron_wa_sync.php
-- Replaces wa_sync_state.json for the cursor/pkId fields.
-- wa_sync_state.json remains for non-critical display fields (last_sync_at, etc.)
CREATE TABLE IF NOT EXISTS wa_sync_cursor (
    id              INTEGER PRIMARY KEY DEFAULT 1,  -- single row
    last_pkid       INTEGER NOT NULL DEFAULT 0,
    total_synced    INTEGER NOT NULL DEFAULT 0,
    last_sync_at    TEXT,
    last_batch_size INTEGER NOT NULL DEFAULT 0
);
INSERT OR IGNORE INTO wa_sync_cursor (id, last_pkid, total_synced) VALUES (1, 0, 0);

-- ── client_search_index ───────────────────────────────────────────────────────
-- Previously client_search_index.json — a full JSON blob loaded and linearly
-- scanned on every KYC phone check, payment search, and customer lookup.
-- At 660 clients = fine. At 2000+ clients = 200-500ms delay per check.
-- Now a proper table with indexed phone_norm column = O(1) lookup.
CREATE TABLE IF NOT EXISTS client_search_index (
    id          INTEGER PRIMARY KEY,   -- CRM client ID
    name        TEXT    NOT NULL DEFAULT '',
    phone       TEXT    NOT NULL DEFAULT '',
    phone_norm  TEXT    NOT NULL DEFAULT '',  -- last 9 digits, digits only
    service     TEXT    NOT NULL DEFAULT '',
    updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_csi_phone_norm ON client_search_index(phone_norm);
CREATE INDEX IF NOT EXISTS idx_csi_name       ON client_search_index(name COLLATE NOCASE);
