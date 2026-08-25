-- Migration 012: cash_aging_alerts table (v4.4.27)
--
-- Stores the nightly Cash Aging results from Control 6 in CashbookReconcileWorker.
-- One row per agent per run. Resolved automatically when exposure drops to zero.
--
-- Cash Aging answers: "How long has this agent been holding company money?"
-- This is distinct from Advance Aging (Control 1) which tracks individual advances.
-- This tracks the ENTIRE exposure clock — oldest inflow event still outstanding.
--
-- Tiers (configurable via cash_aging_tiers in kyc_config.json):
--   light  = exposure < $50   → 48h max
--   medium = $50–$200         → 24h max
--   heavy  = $200+            → 12h max
--
-- Oldest event sources (inflows only — outflows reset the clock but don't "start" it):
--   Stream A: MIN(payment_collections.created_at)  for this agent
--   Stream B: MIN(cash_advances.issued_at)          for active root advances
--   Stream C: MIN(staff_transfers.submitted_at)     for approved transfers received

CREATE TABLE IF NOT EXISTS cash_aging_alerts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Agent
    staff_id        INTEGER NOT NULL,
    staff_name      TEXT    NOT NULL DEFAULT '',

    -- Snapshot at time of alert
    cash_exposure   REAL    NOT NULL DEFAULT 0,
    oldest_event_at TEXT    NOT NULL DEFAULT '',   -- ISO timestamp of oldest inflow
    age_hours       REAL    NOT NULL DEFAULT 0,    -- hours since oldest_event_at
    tier            TEXT    NOT NULL DEFAULT '',   -- 'light' | 'medium' | 'heavy'
    limit_hours     REAL    NOT NULL DEFAULT 24,   -- applicable limit for tier
    over_by_hours   REAL    NOT NULL DEFAULT 0,    -- age_hours - limit_hours

    -- Source that produced oldest_event_at
    oldest_source   TEXT    NOT NULL DEFAULT '',   -- 'collection' | 'advance' | 'transfer_received'

    -- State
    resolved        INTEGER NOT NULL DEFAULT 0,
    resolved_at     TEXT,
    notified        INTEGER NOT NULL DEFAULT 0,    -- 1 = included in WA alert this run

    flagged_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_caa_staff    ON cash_aging_alerts (staff_id, resolved, flagged_at DESC);
CREATE INDEX IF NOT EXISTS idx_caa_flagged  ON cash_aging_alerts (flagged_at DESC);
CREATE INDEX IF NOT EXISTS idx_caa_resolved ON cash_aging_alerts (resolved);
