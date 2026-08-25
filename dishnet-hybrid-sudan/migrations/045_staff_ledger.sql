-- Migration 045: Unified Staff Ledger
-- DishNet Hybrid v4.11.3
--
-- PURPOSE: Eliminate the 5-source cash balance divergence problem.
-- Every cash movement affecting a field agent's position gets ONE row here.
-- Balance = SUM(in) - SUM(out) WHERE status NOT IN ('voided','cancelled')
--
-- CATEGORIES:
--   collection      → agent collected cash from customer (in)
--   advance         → agent received advance from company (in)
--   advance_return  → agent returned unspent advance cash (out)
--   expense         → agent spent on field ops (out)
--   handover        → agent handed cash to accountant (out)
--   transfer_out    → agent sent cash to another agent (out)
--   transfer_in     → agent received cash from another agent (in)
--
-- SOURCE TYPES (traceability back to origin):
--   payment_collections  → from payment_collections.json
--   cash_advances        → from cash_advances SQLite
--   staff_expenses       → from staff_expenses SQLite (advance-linked)
--   cash_expenses        → from cash_expenses.json (field expenses)
--   cash_handovers       → from cash_handovers.json
--   staff_transfers      → from staff_transfers SQLite
--   cash_ins             → from cash_ins.json (SSP exchanges etc.)

CREATE TABLE IF NOT EXISTS staff_ledger (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Who
    staff_id          INTEGER NOT NULL,
    staff_name        TEXT    NOT NULL DEFAULT '',

    -- What direction: 'in' = cash flowing TO agent, 'out' = cash flowing FROM agent
    direction         TEXT    NOT NULL CHECK(direction IN ('in','out')),

    -- Money
    currency          TEXT    NOT NULL DEFAULT 'USD',
    amount            REAL    NOT NULL CHECK(amount >= 0),
    ssp_amount        REAL    NOT NULL DEFAULT 0,       -- original SSP if converted
    ssp_rate          REAL    NOT NULL DEFAULT 0,        -- rate at time of entry

    -- Classification
    category          TEXT    NOT NULL,                   -- collection|advance|expense|handover|transfer_out|transfer_in|advance_return
    subcategory       TEXT    NOT NULL DEFAULT '',        -- e.g. fuel, transport, food (for expenses)
    description       TEXT    NOT NULL DEFAULT '',

    -- Lifecycle
    status            TEXT    NOT NULL DEFAULT 'active',  -- active|voided|cancelled
    voided_by         TEXT    NOT NULL DEFAULT '',
    voided_at         TEXT,
    void_reason       TEXT    NOT NULL DEFAULT '',

    -- Traceability
    source_type       TEXT    NOT NULL,                   -- payment_collections|cash_advances|staff_expenses|cash_expenses|cash_handovers|staff_transfers
    source_id         TEXT    NOT NULL DEFAULT '',        -- id from source table/json
    idempotency_key   TEXT    NOT NULL DEFAULT '',        -- prevents double-insert: e.g. COL-123, ADV-45, EXP-67

    -- Counterparty (customer for collections, other agent for transfers, accountant for handovers)
    counterparty_id   INTEGER NOT NULL DEFAULT 0,
    counterparty_name TEXT    NOT NULL DEFAULT '',

    -- CRM linkage
    crm_payment_id    INTEGER NOT NULL DEFAULT 0,
    crm_client_id     INTEGER NOT NULL DEFAULT 0,

    -- Timestamps
    event_date        TEXT    NOT NULL DEFAULT '',        -- business date of the event
    created_at        TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Primary query: balance per staff + currency
CREATE INDEX IF NOT EXISTS idx_sl_staff_currency
    ON staff_ledger(staff_id, currency, status);

-- Idempotency guard: prevent duplicate inserts
CREATE UNIQUE INDEX IF NOT EXISTS idx_sl_idempotency
    ON staff_ledger(idempotency_key) WHERE idempotency_key != '';

-- Source traceability: find ledger entry from source record
CREATE INDEX IF NOT EXISTS idx_sl_source
    ON staff_ledger(source_type, source_id);

-- Date range queries (reports, monthly summaries)
CREATE INDEX IF NOT EXISTS idx_sl_date
    ON staff_ledger(event_date, staff_id);

-- Category breakdown queries
CREATE INDEX IF NOT EXISTS idx_sl_category
    ON staff_ledger(category, staff_id, currency);

-- CRM payment linkage (for webhook void cascades)
CREATE INDEX IF NOT EXISTS idx_sl_crm_payment
    ON staff_ledger(crm_payment_id) WHERE crm_payment_id > 0;

-- Active balance fast path (covering index for the balance query)
CREATE INDEX IF NOT EXISTS idx_sl_balance
    ON staff_ledger(staff_id, currency, direction, status, amount);
