-- Migration 016: crm_sync_state (v4.4.31)
--
-- Lightweight sync checkpoint table.
--
-- DishNet's sync direction is PUSH (agent → plugin → CRM), not PULL.
-- This table serves two purposes:
--
--   1. crm_payment_retry worker records its last successful run so we know
--      at a glance when outbound sync last worked.
--
--   2. Any future inbound polling (e.g. CRM webhooks, daily reconcile)
--      has a cursor to resume from without rescanning everything.
--
-- One row per sync entity. Upserted on every worker run.

CREATE TABLE IF NOT EXISTS crm_sync_state (
    entity          TEXT    PRIMARY KEY,  -- 'payment_retry' | 'kyc_sync' | 'jobs_cache'
    last_run_at     TEXT    NOT NULL DEFAULT '',   -- when the worker last completed a cycle
    last_success_at TEXT    NOT NULL DEFAULT '',   -- last run with >=1 successful sync
    last_cursor     TEXT    NOT NULL DEFAULT '',   -- optional: last CRM ID / timestamp processed
    run_count       INTEGER NOT NULL DEFAULT 0,
    success_count   INTEGER NOT NULL DEFAULT 0,
    last_error      TEXT    NOT NULL DEFAULT '',
    updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);
