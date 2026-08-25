-- Migration 013: staff_position_snapshot (v4.4.28)
--
-- Materialized position table. Replaces the 6-subquery correlated VIEW on every
-- dashboard load with a single SELECT. For N agents, the VIEW ran 6N subqueries;
-- this table runs 1 query regardless of transaction volume.
--
-- UPDATE strategy: each financial event calls SnapshotService::rebuild(agentId).
-- rebuild() recomputes from source tables (one agent, not all) and writes here.
-- This is safe because:
--   (a) rebuild is idempotent — can be called multiple times safely
--   (b) CashbookReconcileWorker calls rebuildAll() nightly as a verification pass
--   (c) The VIEW (migration 011) still exists as a live fallback / audit source
--
-- Conservation: transfers are zero-sum. When Diko→Bidal transfer is recorded,
-- rebuild(Diko) and rebuild(Bidal) are both called. Total snapshot exposure
-- across all agents is unchanged.
--
-- Drift detection: nightly worker compares snapshot vs VIEW for every agent.
-- Any mismatch triggers a rebuild + Rupesh WA alert.

CREATE TABLE IF NOT EXISTS staff_position_snapshot (
    staff_id        INTEGER PRIMARY KEY,          -- FK → retailers.id
    staff_name      TEXT    NOT NULL DEFAULT '',

    -- Running totals (all-time, not scoped to a period)
    collections     REAL    NOT NULL DEFAULT 0,
    advance_balance REAL    NOT NULL DEFAULT 0,
    expenses        REAL    NOT NULL DEFAULT 0,
    handovers       REAL    NOT NULL DEFAULT 0,
    transfers_sent  REAL    NOT NULL DEFAULT 0,
    transfers_recv  REAL    NOT NULL DEFAULT 0,

    -- Derived: always = collections + advance_balance - expenses - handovers
    --                       - transfers_sent + transfers_recv
    -- Stored for fast reads. Recomputed on every rebuild().
    cash_exposure   REAL    NOT NULL DEFAULT 0,

    -- Audit trail
    last_event_type TEXT    NOT NULL DEFAULT '',  -- 'collection' | 'advance' | 'expense' | 'handover' | 'transfer'
    last_event_ref  TEXT    NOT NULL DEFAULT '',  -- e.g. 'COL-123', 'ADV-2026-001', 'TRF-202603-001'
    last_event_time TEXT    NOT NULL DEFAULT '',  -- timestamp of the triggering financial event (not rebuild time)
                                                  -- snapshot_delay = strtotime(now) - strtotime(last_event_time)
    updated_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    rebuild_count   INTEGER NOT NULL DEFAULT 0    -- incremented on every rebuild for diagnostics
);

-- Fast lookup by exposure for dashboard ordering
CREATE INDEX IF NOT EXISTS idx_sps_exposure ON staff_position_snapshot (cash_exposure DESC);
CREATE INDEX IF NOT EXISTS idx_sps_updated  ON staff_position_snapshot (updated_at DESC);
