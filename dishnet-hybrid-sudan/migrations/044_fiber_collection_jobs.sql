-- Migration 044: Fiber Collection Jobs
-- Tracks collection tasks created when Splynx service goes active.
-- CRM handles invoicing — this just tracks the "go collect" job for accountant.

CREATE TABLE IF NOT EXISTS fiber_collection_jobs (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Splynx links
    splynx_customer_id      INTEGER NOT NULL,
    splynx_service_id       INTEGER NOT NULL UNIQUE,          -- one job per service
    ticket_id               INTEGER DEFAULT 0,                -- links to tickets table

    -- Customer info
    customer_name           TEXT    NOT NULL DEFAULT '',
    phone                   TEXT    NOT NULL DEFAULT '',
    area                    TEXT    NOT NULL DEFAULT '',

    -- KYC / CRM link
    kyc_app_id              INTEGER DEFAULT 0,
    crm_client_id           INTEGER DEFAULT 0,

    -- Plan details
    plan_name               TEXT    NOT NULL DEFAULT '',
    amount                  REAL    NOT NULL DEFAULT 0,
    currency                TEXT    NOT NULL DEFAULT 'USD',

    -- Status: pending → invoiced / cancelled
    status                  TEXT    NOT NULL DEFAULT 'pending',
    invoiced_at            TEXT,
    invoiced_by            TEXT    NOT NULL DEFAULT '',
    payment_collection_id   INTEGER DEFAULT 0,
    cancel_reason           TEXT    NOT NULL DEFAULT '',

    -- Notifications
    wa_sent_accountant      INTEGER NOT NULL DEFAULT 0,
    wa_sent_installer       INTEGER NOT NULL DEFAULT 0,

    -- Timestamps
    created_at              TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at              TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_fcj_status ON fiber_collection_jobs(status);
CREATE INDEX IF NOT EXISTS idx_fcj_splynx_cust ON fiber_collection_jobs(splynx_customer_id);
