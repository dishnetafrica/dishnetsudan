-- Migration 009: wallet_events table (v4.4.25)
--
-- Replaces the two-step wallet audit trail (update retailers.json balance,
-- then append passbook.json entry) with a single native SQLite table.
--
-- Problem it solves:
--   WalletService::debit/credit currently does:
--     1. withLock → update retailers.json (balance)       — atomic ✓
--     2. appendWithId → passbook.json (audit trail)        — separate op ✗
--   A crash between step 1 and step 2 leaves a balance change with no
--   passbook record. The balance and the trail go out of sync silently.
--
-- With this table: WalletService writes to wallet_events in the SAME
-- SQLite transaction as the retailers.json balance update (via withLock).
-- One commit = balance change + audit entry. Crash-safe by definition.
--
-- Replaces: passbook.json (kept for backward compat — legacy reads still work)
-- New writes go to wallet_events; passbook kept as read-only history.

CREATE TABLE IF NOT EXISTS wallet_events (
    id                INTEGER  PRIMARY KEY AUTOINCREMENT,
    event_no          TEXT     NOT NULL UNIQUE,          -- WE-YYYYMMDD-NNNN
    retailer_id       INTEGER  NOT NULL,
    retailer_name     TEXT     NOT NULL DEFAULT '',
    direction         TEXT     NOT NULL                  -- 'debit' | 'credit'
                               CHECK(direction IN ('debit','credit')),
    amount            REAL     NOT NULL CHECK(amount > 0),
    prev_balance      REAL     NOT NULL DEFAULT 0,
    curr_balance      REAL     NOT NULL DEFAULT 0,
    trx_type          TEXT     NOT NULL DEFAULT 'other', -- 'collection'|'handover'|'advance'|'recharge'|'commission'|'expense'|'order_payment'|'other'
    description       TEXT     NOT NULL DEFAULT '',
    reference         TEXT     NOT NULL DEFAULT '',      -- COL-xxx, HOV-xxx, ADV-xxx, etc.
    idempotency_key   TEXT     NOT NULL DEFAULT '',
    created_by        TEXT     NOT NULL DEFAULT 'system',
    created_at        TEXT     NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_wallet_events_retailer ON wallet_events (retailer_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_wallet_events_trx_type ON wallet_events (trx_type, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_wallet_events_idem     ON wallet_events (idempotency_key) WHERE idempotency_key != '';
CREATE INDEX IF NOT EXISTS idx_wallet_events_ref      ON wallet_events (reference)       WHERE reference != '';
