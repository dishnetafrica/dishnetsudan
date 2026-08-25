-- Migration 048: SSP exchange batch verification states (Phase 3)
-- Tracks when Rupesh physically verifies/closes an exchange batch.
-- Closed batches are hidden from the "open" reconciliation view.
CREATE TABLE IF NOT EXISTS ssp_batch_states (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    exchange_ref TEXT NOT NULL UNIQUE,
    state        TEXT NOT NULL DEFAULT 'open',  -- 'open' | 'closed'
    closed_by    TEXT NOT NULL DEFAULT '',
    closed_at    TEXT,
    note         TEXT NOT NULL DEFAULT '',
    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_ssp_batch_ref ON ssp_batch_states(exchange_ref);
