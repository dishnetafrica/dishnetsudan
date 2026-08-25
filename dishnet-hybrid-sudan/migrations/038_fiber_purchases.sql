-- ═══════════════════════════════════════════════════════════════════
-- 038: Fiber Purchasing Reconciliation — supplier invoice tracking
-- v4.10.2 — zero changes to existing tables
-- ═══════════════════════════════════════════════════════════════════

-- Supplier invoices — what the supplier actually billed us
CREATE TABLE IF NOT EXISTS fiber_supplier_invoices (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier        TEXT NOT NULL,
    invoice_number  TEXT NOT NULL,
    invoice_date    TEXT NOT NULL,
    billing_period  TEXT NOT NULL,
    total_amount    REAL NOT NULL,
    currency        TEXT NOT NULL DEFAULT 'USD',
    expected_amount REAL DEFAULT NULL,
    variance        REAL DEFAULT NULL,
    variance_pct    REAL DEFAULT NULL,
    line_items      TEXT DEFAULT NULL,
    status          TEXT NOT NULL DEFAULT 'received',
    verified_by     TEXT DEFAULT NULL,
    verified_at     TEXT DEFAULT NULL,
    approved_by     TEXT DEFAULT NULL,
    approved_at     TEXT DEFAULT NULL,
    paid_at         TEXT DEFAULT NULL,
    payment_ref     TEXT DEFAULT NULL,
    cb_entry_id     INTEGER DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    attachment_path TEXT DEFAULT NULL,
    created_by      TEXT DEFAULT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_fsi_supplier ON fiber_supplier_invoices(supplier);
CREATE INDEX IF NOT EXISTS idx_fsi_period   ON fiber_supplier_invoices(billing_period);
CREATE INDEX IF NOT EXISTS idx_fsi_status   ON fiber_supplier_invoices(status);

-- Plan cost history — tracks supplier price changes over time
CREATE TABLE IF NOT EXISTS fiber_plan_costs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier        TEXT NOT NULL,
    plan_name       TEXT NOT NULL,
    cost_per_unit   REAL NOT NULL,
    effective_from  TEXT NOT NULL,
    effective_to    TEXT DEFAULT NULL,
    source          TEXT DEFAULT 'manual',
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_fpc_active ON fiber_plan_costs(supplier, plan_name, effective_from);

-- Monthly cost snapshots — immutable expected vs actual record
CREATE TABLE IF NOT EXISTS fiber_cost_snapshots (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    billing_period        TEXT NOT NULL UNIQUE,
    snapshot_date         TEXT NOT NULL,
    active_services       INTEGER NOT NULL DEFAULT 0,
    expected_cost         REAL NOT NULL DEFAULT 0,
    actual_cost           REAL DEFAULT NULL,
    variance              REAL DEFAULT NULL,
    plan_breakdown        TEXT DEFAULT NULL,
    supplier_invoice_id   INTEGER DEFAULT NULL,
    created_at            TEXT NOT NULL DEFAULT (datetime('now'))
);
