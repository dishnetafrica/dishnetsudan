-- Migration 015: Event Active Window (v4.4.30) — safe version
-- Adds ev partitioning to event tables for snapshot rebuild performance

-- Ensure payment_collections exists as SQLite table (may be JSON-backed)
CREATE TABLE IF NOT EXISTS [payment_collections] (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    data TEXT NOT NULL DEFAULT '{}'
);

-- Ensure cash_handovers exists
CREATE TABLE IF NOT EXISTS [cash_handovers] (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    data TEXT NOT NULL DEFAULT '{}'
);

-- payment_collections: VIRTUAL generated column + partial index
ALTER TABLE [payment_collections]
    ADD COLUMN ev INTEGER
    GENERATED ALWAYS AS (COALESCE(json_extract(data,'$.ev'),1)) VIRTUAL;

CREATE INDEX IF NOT EXISTS idx_col_active_rid
    ON [payment_collections] (json_extract(data,'$.retailer_id'))
    WHERE ev = 1;

-- cash_handovers: same pattern
ALTER TABLE [cash_handovers]
    ADD COLUMN ev INTEGER
    GENERATED ALWAYS AS (COALESCE(json_extract(data,'$.ev'),1)) VIRTUAL;

CREATE INDEX IF NOT EXISTS idx_hov_active_from
    ON [cash_handovers] (json_extract(data,'$.from_id'))
    WHERE ev = 1;

-- staff_expenses: real column
ALTER TABLE staff_expenses
    ADD COLUMN ev INTEGER NOT NULL DEFAULT 1;

CREATE INDEX IF NOT EXISTS idx_exp_active_staff
    ON staff_expenses (staff_id)
    WHERE ev = 1;

-- staff_transfers: real column
ALTER TABLE staff_transfers
    ADD COLUMN ev INTEGER NOT NULL DEFAULT 1;

CREATE INDEX IF NOT EXISTS idx_trf_active_from
    ON staff_transfers (from_id)
    WHERE ev = 1;

CREATE INDEX IF NOT EXISTS idx_trf_active_to
    ON staff_transfers (to_id)
    WHERE ev = 1;

-- cash_advances: partial index
ALTER TABLE cash_advances
    ADD COLUMN ev INTEGER NOT NULL DEFAULT 1;

CREATE INDEX IF NOT EXISTS idx_adv_active_recipient
    ON cash_advances (recipient_id, status)
    WHERE ev = 1;
