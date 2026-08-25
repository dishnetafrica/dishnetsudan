-- ═══════════════════════════════════════════════════════════════════
-- 037: Notification Queue — stores failed WA sends for manual retry
-- v4.10.1 — zero changes to existing tables
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS notification_queue (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    sender          TEXT NOT NULL DEFAULT 'support',    -- 'support' or 'accounts'
    phone           TEXT NOT NULL,                      -- recipient digits only
    message         TEXT NOT NULL,                      -- full message body
    event           TEXT DEFAULT NULL,                  -- event key e.g. ops_kyc_submitted
    vars            TEXT DEFAULT NULL,                  -- JSON vars for template
    status          TEXT NOT NULL DEFAULT 'failed',     -- failed | retrying | sent | dismissed
    http_code       INTEGER DEFAULT NULL,
    error           TEXT DEFAULT NULL,                  -- curl error or response body
    attempts        INTEGER NOT NULL DEFAULT 1,         -- total send attempts
    last_attempt_at TEXT NOT NULL,                      -- ISO datetime of last attempt
    retry_at        TEXT DEFAULT NULL,                  -- ISO datetime of successful retry
    retry_by        TEXT DEFAULT NULL,                  -- who retried (admin name)
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_nq_status ON notification_queue(status);
CREATE INDEX IF NOT EXISTS idx_nq_event  ON notification_queue(event);
CREATE INDEX IF NOT EXISTS idx_nq_phone  ON notification_queue(phone);
