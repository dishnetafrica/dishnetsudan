-- Migration 034: SSP exchange rate history (v4.9.4)
-- Logs every rate change so Rupesh can see what rate was used on any given day.

CREATE TABLE IF NOT EXISTS ssp_rate_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    rate        REAL    NOT NULL,
    effective_date TEXT NOT NULL,        -- date this rate applies from (YYYY-MM-DD)
    set_by      TEXT    NOT NULL DEFAULT '',
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_ssp_rate_date ON ssp_rate_history(effective_date DESC);
