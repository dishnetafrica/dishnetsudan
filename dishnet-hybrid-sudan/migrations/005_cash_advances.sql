-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 005: Field Expense & Cash Advance System
-- DishNet Hybrid v4.5
--
-- Tables:
--   1. cash_advances        — Manager issues cash to staff for field ops
--   2. staff_expenses       — Individual expense records with receipt tracking
--   3. expense_receipts     — File store for receipt photos (multi-photo ready)
--   4. staff_cashbook_daily — Daily reconciliation snapshot per staff member
--
-- All tables use IF NOT EXISTS — safe to re-run on every boot.
-- Designed to slot into the existing CashbookService / cb_ledger ecosystem:
--   approved expenses → posted to cb_ledger as 'out' entries (source='expense_sync')
-- ══════════════════════════════════════════════════════════════════════════════

-- ── 1. Cash Advances ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cash_advances (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    advance_no          TEXT    NOT NULL DEFAULT '',    -- ADV-2026-001

    -- Project scope (dishnet | 4g)
    project             TEXT    NOT NULL DEFAULT 'dishnet',

    -- Who gave the money
    issued_by_id        INTEGER NOT NULL DEFAULT 0,
    issued_by_name      TEXT    NOT NULL DEFAULT '',

    -- Who received the money
    recipient_id        INTEGER NOT NULL DEFAULT 0,
    recipient_name      TEXT    NOT NULL DEFAULT '',

    -- Amount details
    amount              REAL    NOT NULL DEFAULT 0,
    currency            TEXT    NOT NULL DEFAULT 'USD',
    purpose             TEXT    NOT NULL DEFAULT 'misc',  -- fuel|parts|transport|allowance|misc
    description         TEXT    NOT NULL DEFAULT '',

    -- Running totals (kept in sync by ExpenseAdvanceService)
    amount_spent        REAL    NOT NULL DEFAULT 0,   -- sum of approved expenses
    amount_returned     REAL    NOT NULL DEFAULT 0,   -- cash physically returned
    -- balance = amount - amount_spent - amount_returned (computed column via service)

    -- Lifecycle
    status              TEXT    NOT NULL DEFAULT 'active',  -- active|partial|settled|cancelled
    issued_at           TEXT    NOT NULL DEFAULT '',
    expected_settle_at  TEXT,
    settled_at          TEXT,
    settlement_note     TEXT    NOT NULL DEFAULT '',

    -- Audit
    created_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_adv_recipient  ON cash_advances(recipient_id);
CREATE INDEX IF NOT EXISTS idx_adv_issued_by  ON cash_advances(issued_by_id);
CREATE INDEX IF NOT EXISTS idx_adv_status     ON cash_advances(status) WHERE status IN ('active','partial');
CREATE INDEX IF NOT EXISTS idx_adv_project    ON cash_advances(project);
CREATE INDEX IF NOT EXISTS idx_adv_issued_at  ON cash_advances(issued_at);

-- ── 2. Staff Expenses ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS staff_expenses (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    expense_no          TEXT    NOT NULL DEFAULT '',    -- EXP-2026-001

    -- Links
    advance_id          INTEGER,                        -- FK cash_advances.id (nullable)
    staff_id            INTEGER NOT NULL DEFAULT 0,
    staff_name          TEXT    NOT NULL DEFAULT '',
    project             TEXT    NOT NULL DEFAULT 'dishnet',

    -- Expense details
    category            TEXT    NOT NULL DEFAULT 'misc',  -- fuel|parts|transport|allowance|food|other
    amount              REAL    NOT NULL DEFAULT 0,
    currency            TEXT    NOT NULL DEFAULT 'USD',
    description         TEXT    NOT NULL DEFAULT '',
    expense_date        TEXT    NOT NULL DEFAULT '',    -- Y-m-d actual date of spend

    -- Receipt
    receipt_path        TEXT    NOT NULL DEFAULT '',    -- relative path under data/uploads/
    receipt_hash        TEXT    NOT NULL DEFAULT '',    -- sha1 for duplicate detection
    receipt_thumb       TEXT    NOT NULL DEFAULT '',    -- thumbnail path (optional)

    -- Offline / PWA tracking
    offline_uuid        TEXT    NOT NULL DEFAULT '',    -- client-generated UUID for dedup
    submitted_via       TEXT    NOT NULL DEFAULT 'web', -- web|pwa|api

    -- Approval workflow
    status              TEXT    NOT NULL DEFAULT 'pending',  -- pending|approved|rejected
    reviewed_by         TEXT    NOT NULL DEFAULT '',
    reviewed_at         TEXT,
    reject_reason       TEXT    NOT NULL DEFAULT '',

    -- Cashbook integration (set when expense is posted to cb_ledger)
    cashbook_entry_id   INTEGER NOT NULL DEFAULT 0,     -- cb_ledger.id
    cashbook_posted_at  TEXT,

    -- Fraud flags (set by ExpenseAdvanceService::detectFraud)
    flag_duplicate      INTEGER NOT NULL DEFAULT 0,     -- 1 = same hash found elsewhere
    flag_overspend      INTEGER NOT NULL DEFAULT 0,     -- 1 = exceeds advance balance
    flag_no_receipt     INTEGER NOT NULL DEFAULT 0,     -- 1 = submitted without photo

    submitted_at        TEXT    NOT NULL DEFAULT (datetime('now')),
    created_at          TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_exp_staff      ON staff_expenses(staff_id);
CREATE INDEX IF NOT EXISTS idx_exp_advance    ON staff_expenses(advance_id) WHERE advance_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_exp_status     ON staff_expenses(status);
CREATE INDEX IF NOT EXISTS idx_exp_date       ON staff_expenses(expense_date);
CREATE INDEX IF NOT EXISTS idx_exp_project    ON staff_expenses(project);
CREATE INDEX IF NOT EXISTS idx_exp_hash       ON staff_expenses(receipt_hash) WHERE receipt_hash != '';
CREATE UNIQUE INDEX IF NOT EXISTS idx_exp_offline ON staff_expenses(offline_uuid) WHERE offline_uuid != '';

-- ── 3. Expense Receipts (multi-photo, separate from expense row) ─────────────
CREATE TABLE IF NOT EXISTS expense_receipts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    expense_id      INTEGER NOT NULL,
    file_path       TEXT    NOT NULL DEFAULT '',
    file_hash       TEXT    NOT NULL DEFAULT '',    -- sha1 of file bytes
    file_size       INTEGER NOT NULL DEFAULT 0,
    mime_type       TEXT    NOT NULL DEFAULT 'image/jpeg',
    uploaded_by_id  INTEGER NOT NULL DEFAULT 0,
    uploaded_at     TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_receipt_expense ON expense_receipts(expense_id);
CREATE INDEX IF NOT EXISTS idx_receipt_hash    ON expense_receipts(file_hash);

-- ── 4. Staff Daily Cashbook (reconciliation snapshots) ───────────────────────
CREATE TABLE IF NOT EXISTS staff_cashbook_daily (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    staff_id             INTEGER NOT NULL DEFAULT 0,
    staff_name           TEXT    NOT NULL DEFAULT '',
    date                 TEXT    NOT NULL DEFAULT '',   -- Y-m-d

    -- Money flows for this day
    opening_balance      REAL    NOT NULL DEFAULT 0,
    advances_received    REAL    NOT NULL DEFAULT 0,
    expenses_approved    REAL    NOT NULL DEFAULT 0,
    cash_returned        REAL    NOT NULL DEFAULT 0,
    closing_balance      REAL    NOT NULL DEFAULT 0,   -- opening + advances - expenses - returned

    -- Integrity flags
    is_reconciled        INTEGER NOT NULL DEFAULT 0,
    reconciled_by        TEXT    NOT NULL DEFAULT '',
    reconciled_at        TEXT,

    flag_missing_receipts INTEGER NOT NULL DEFAULT 0,  -- expenses with no receipt
    flag_overspend        INTEGER NOT NULL DEFAULT 0,  -- closing < 0
    flag_unreconciled     INTEGER NOT NULL DEFAULT 0,  -- >2 days since last reconcile
    flag_count            INTEGER NOT NULL DEFAULT 0,  -- total active flags

    -- Cashbook sync
    synced_to_cashbook   INTEGER NOT NULL DEFAULT 0,
    synced_at            TEXT,
    cb_entry_ids         TEXT    NOT NULL DEFAULT '[]', -- JSON array of cb_ledger ids

    notes                TEXT    NOT NULL DEFAULT '',
    created_at           TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at           TEXT    NOT NULL DEFAULT (datetime('now')),

    UNIQUE(staff_id, date)
);

CREATE INDEX IF NOT EXISTS idx_scd_staff  ON staff_cashbook_daily(staff_id);
CREATE INDEX IF NOT EXISTS idx_scd_date   ON staff_cashbook_daily(date);
CREATE INDEX IF NOT EXISTS idx_scd_flags  ON staff_cashbook_daily(flag_count) WHERE flag_count > 0;
CREATE INDEX IF NOT EXISTS idx_scd_sync   ON staff_cashbook_daily(synced_to_cashbook) WHERE synced_to_cashbook = 0;
