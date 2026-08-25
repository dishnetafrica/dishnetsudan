-- Migration 055: Overdue invoice email escalation tracking
-- Tracks which escalation stage has been sent per invoice.
-- Prevents duplicate emails and enables admin visibility.

CREATE TABLE IF NOT EXISTS overdue_email_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_number  TEXT    NOT NULL,
    client_id       INTEGER NOT NULL DEFAULT 0,
    client_name     TEXT    NOT NULL DEFAULT '',
    client_email    TEXT    NOT NULL DEFAULT '',
    stage           INTEGER NOT NULL DEFAULT 1,  -- 1,2,3,4
    stage_label     TEXT    NOT NULL DEFAULT '',  -- gentle/firm/final/suspension
    amount_due      REAL    NOT NULL DEFAULT 0,
    days_overdue    INTEGER NOT NULL DEFAULT 0,
    sent_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    sent_by         TEXT    NOT NULL DEFAULT 'cron',
    success         INTEGER NOT NULL DEFAULT 1,
    error           TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_oel_inv_stage
    ON overdue_email_log(invoice_number, stage);

CREATE INDEX IF NOT EXISTS idx_oel_sent_at
    ON overdue_email_log(sent_at);

CREATE INDEX IF NOT EXISTS idx_oel_client
    ON overdue_email_log(client_id);
