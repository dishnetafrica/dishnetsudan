-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 001: Event Queue
-- DishNet Hybrid v3.8 — Phase 2
--
-- Purpose: Replace inline API calls with async event processing.
-- Producers: webhook.php (Splynx/UCRM events), noc_update_status, install_*
-- Consumer:  cron/event_processor.php (runs every 10s via master.php)
--
-- Event lifecycle: pending → processing → done | failed → dead
-- Retry: exponential backoff (10s, 30s, 5m, 30m, 2h) up to max_attempts
-- ══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type     TEXT    NOT NULL,       -- e.g. ticket.status_changed, install.completed, splynx.ticket.updated
    entity_type    TEXT    NOT NULL,       -- ticket, customer, service, payment
    entity_id      INTEGER NOT NULL,       -- ID of the affected entity
    payload        TEXT,                   -- JSON: full event data for the handler
    status         TEXT    NOT NULL DEFAULT 'pending',   -- pending, processing, done, failed, dead
    priority       INTEGER NOT NULL DEFAULT 5,           -- 1=critical (billing), 5=normal, 9=low (cleanup)
    attempts       INTEGER NOT NULL DEFAULT 0,
    max_attempts   INTEGER NOT NULL DEFAULT 5,
    locked_by      TEXT,                   -- PID of the worker processing this event
    locked_at      TEXT,                   -- When the lock was acquired
    next_retry_at  TEXT    DEFAULT (datetime('now')),     -- Next eligible processing time
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    processed_at   TEXT,                   -- When it reached done/dead status
    error          TEXT,                   -- Last error message (for debugging)
    created_by     TEXT                    -- IP or 'system' or 'webhook' (audit trail)
);

-- Primary consumption index: workers SELECT WHERE status+next_retry_at
CREATE INDEX IF NOT EXISTS idx_events_pending
    ON events(status, next_retry_at)
    WHERE status IN ('pending', 'failed');

-- Entity lookup: "show me all events for ticket #252"
CREATE INDEX IF NOT EXISTS idx_events_entity
    ON events(entity_type, entity_id);

-- Dead letter monitoring: admin dashboard checks for stuck events
CREATE INDEX IF NOT EXISTS idx_events_dead
    ON events(status)
    WHERE status = 'dead';

-- Cleanup: prune old completed events (run weekly in maintenance cron)
CREATE INDEX IF NOT EXISTS idx_events_completed_at
    ON events(processed_at)
    WHERE status = 'done';
