-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 002: Job Queue
-- DishNet Hybrid v3.8 — Phase 2
--
-- Purpose: Replace CrmQueue's crm_queue.json with a proper SQLite table.
-- This gives us atomic dequeue (no double-processing), proper indexing,
-- and visibility timeout for stale locks.
--
-- The CrmQueue class continues to work — its methods are updated to
-- read/write this table instead of the JSON blob. The JSON file becomes
-- a backup-only artifact.
--
-- Job lifecycle: pending → processing → completed | failed → exhausted → reversed
-- Retry: matches CrmQueue's existing backoff (1m, 5m, 30m)
-- ══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS job_queue (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    job_type       TEXT    NOT NULL,       -- crm_sync, wa_send, photo_upload, lte_check, wallet_sync
    payload        TEXT    NOT NULL,       -- JSON: all data needed to execute the job
    priority       INTEGER NOT NULL DEFAULT 5,   -- 1=critical (billing), 5=normal, 9=low
    status         TEXT    NOT NULL DEFAULT 'pending',  -- pending, processing, completed, failed, exhausted, reversed
    attempts       INTEGER NOT NULL DEFAULT 0,
    max_attempts   INTEGER NOT NULL DEFAULT 3,
    locked_by      TEXT,                   -- Worker PID (prevents double-processing)
    locked_at      TEXT,                   -- Lock timestamp (detect stale locks > 5 min)
    next_retry_at  TEXT    DEFAULT (datetime('now')),
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    completed_at   TEXT,
    error          TEXT,                   -- Last error message
    result         TEXT,                   -- JSON: result data (e.g. crm_client_id)

    -- CrmQueue-specific fields (preserved for backward compat)
    application_id INTEGER,               -- Links back to kyc_applications
    crm_client_id  TEXT                    -- Set on completion by CRM sync worker
);

-- Atomic dequeue: find next eligible job for processing
-- Worker does: UPDATE job_queue SET status='processing', locked_by=PID
--              WHERE id = (SELECT id FROM job_queue WHERE ... LIMIT 1)
CREATE INDEX IF NOT EXISTS idx_jobs_dequeue
    ON job_queue(status, priority, next_retry_at)
    WHERE status IN ('pending', 'failed');

-- Application lookup: "what's the sync status for KYC app #1234?"
CREATE INDEX IF NOT EXISTS idx_jobs_app_id
    ON job_queue(application_id)
    WHERE application_id IS NOT NULL;

-- Stale lock detection: find jobs locked > 5 minutes ago
CREATE INDEX IF NOT EXISTS idx_jobs_stale
    ON job_queue(status, locked_at)
    WHERE status = 'processing';

-- Admin dashboard: count by status
CREATE INDEX IF NOT EXISTS idx_jobs_status
    ON job_queue(status);
