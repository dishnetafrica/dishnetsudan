-- ═══════════════════════════════════════════════════════════════════
-- 035: SSP Advance Chain — per-staff SSP tracking with merge to cb_ledger
-- v4.9.18 — zero changes to existing tables
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS cb_ssp_register (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    staff_id        INTEGER NOT NULL,
    staff_name      TEXT NOT NULL,
    cashbook        TEXT NOT NULL DEFAULT 'dishnet',
    date            TEXT NOT NULL,
    direction       TEXT NOT NULL CHECK(direction IN ('in','out')),
    ssp_amount      INTEGER NOT NULL,
    usd_equivalent  REAL DEFAULT 0,
    ssp_rate        REAL DEFAULT 0,
    category        TEXT NOT NULL DEFAULT '',
    description     TEXT DEFAULT '',
    status          TEXT NOT NULL DEFAULT 'pending',
    approved_by     TEXT DEFAULT '',
    approved_at     TEXT,
    source_type     TEXT NOT NULL DEFAULT 'expense',
    chain_ref       TEXT NOT NULL DEFAULT '',
    merged_to_cb    INTEGER NOT NULL DEFAULT 0,
    merged_at       TEXT,
    cb_ledger_id    INTEGER,
    cb_sr           TEXT DEFAULT '',
    created_at      TEXT NOT NULL,
    created_by      TEXT DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_ssp_reg_staff   ON cb_ssp_register(staff_id, date);
CREATE INDEX IF NOT EXISTS idx_ssp_reg_status  ON cb_ssp_register(status);
CREATE INDEX IF NOT EXISTS idx_ssp_reg_date    ON cb_ssp_register(date);
CREATE INDEX IF NOT EXISTS idx_ssp_reg_source  ON cb_ssp_register(source_type);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ssp_reg_chain ON cb_ssp_register(chain_ref);
