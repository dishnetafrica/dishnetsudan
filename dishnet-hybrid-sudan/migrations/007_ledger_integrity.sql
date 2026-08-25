-- ── Migration 007: Ledger integrity + advance aging ─────────────────────────
-- Safe version: creates cb_ledger stub if missing, then adds indexes/columns

-- Ensure cb_ledger table exists (created by firstBootMigration on fresh DB,
-- this stub covers migration on existing DBs that upgraded from JSON store)
CREATE TABLE IF NOT EXISTS cb_ledger (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    project        TEXT NOT NULL DEFAULT 'dishnet',
    trx_no         TEXT,
    category       TEXT,
    direction      TEXT,
    amount         REAL NOT NULL DEFAULT 0,
    balance        REAL NOT NULL DEFAULT 0,
    description    TEXT,
    validation_ref TEXT,
    staff_id       INTEGER,
    staff_name     TEXT,
    created_at     TEXT,
    updated_at     TEXT
);

-- Deduplicate validation_ref before adding unique index.
-- Uses empty string '' for duplicates (avoids NOT NULL constraint if column has it).
UPDATE cb_ledger
SET    validation_ref = ''
WHERE  validation_ref IS NOT NULL
  AND  validation_ref != ''
  AND  id NOT IN (
           SELECT MIN(id)
           FROM   cb_ledger
           WHERE  validation_ref IS NOT NULL
             AND  validation_ref != ''
           GROUP  BY validation_ref
       );

-- UNIQUE index on validation_ref — only enforces on non-empty values
CREATE UNIQUE INDEX IF NOT EXISTS idx_cb_validation_ref_unique
    ON cb_ledger(validation_ref)
    WHERE validation_ref IS NOT NULL AND validation_ref != '';

-- Advance aging columns (safe — MigrationRunner skips duplicate-column errors)
ALTER TABLE cash_advances ADD COLUMN is_overdue       INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cash_advances ADD COLUMN overdue_since    TEXT;
ALTER TABLE cash_advances ADD COLUMN overdue_notified INTEGER NOT NULL DEFAULT 0;

-- Global ledger reconciliation snapshots
CREATE TABLE IF NOT EXISTS ledger_reconcile_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    run_at       TEXT NOT NULL,
    project      TEXT NOT NULL DEFAULT 'dishnet',
    cb_in        REAL NOT NULL DEFAULT 0,
    cb_out       REAL NOT NULL DEFAULT 0,
    cb_net       REAL NOT NULL DEFAULT 0,
    advances_out REAL NOT NULL DEFAULT 0,
    expenses_out REAL NOT NULL DEFAULT 0,
    returns_in   REAL NOT NULL DEFAULT 0,
    outstanding  REAL NOT NULL DEFAULT 0,
    drift        REAL NOT NULL DEFAULT 0,
    drift_ok     INTEGER NOT NULL DEFAULT 1,
    notes        TEXT NOT NULL DEFAULT ''
);

-- Staff carry limit alerts table
CREATE TABLE IF NOT EXISTS staff_carry_alerts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    staff_id     INTEGER NOT NULL,
    staff_name   TEXT NOT NULL DEFAULT '',
    cash_in_hand REAL NOT NULL DEFAULT 0,
    carry_limit  REAL NOT NULL DEFAULT 100,
    flagged_at   TEXT NOT NULL,
    resolved     INTEGER NOT NULL DEFAULT 0,
    resolved_at  TEXT
);
CREATE INDEX IF NOT EXISTS idx_carry_staff ON staff_carry_alerts(staff_id, resolved);
