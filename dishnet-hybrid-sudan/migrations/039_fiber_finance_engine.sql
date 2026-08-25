-- ═══════════════════════════════════════════════════════════════════
-- 039: Fiber Finance Engine — revenue/P&L + service cache + customer mapping
-- v4.10.3 — adds columns to fiber_plan_costs, new tables for sync
-- ═══════════════════════════════════════════════════════════════════

-- Expand plan costs with revenue side
ALTER TABLE fiber_plan_costs ADD COLUMN revenue REAL DEFAULT 0;
ALTER TABLE fiber_plan_costs ADD COLUMN partner_share REAL DEFAULT 0;
ALTER TABLE fiber_plan_costs ADD COLUMN profit_mode TEXT DEFAULT 'fixed';
ALTER TABLE fiber_plan_costs ADD COLUMN crm_plan_name TEXT DEFAULT NULL;

-- Service cache — local copy of Splynx services, enriched with CRM status
CREATE TABLE IF NOT EXISTS fiber_services_cache (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    splynx_service_id   TEXT NOT NULL UNIQUE,
    splynx_customer_id  TEXT NOT NULL,
    customer_name       TEXT DEFAULT '',
    plan_name           TEXT DEFAULT '',
    splynx_status       TEXT DEFAULT '',
    crm_status          TEXT DEFAULT NULL,
    crm_client_id       TEXT DEFAULT NULL,
    ip_address          TEXT DEFAULT '',
    download_speed      TEXT DEFAULT '',
    upload_speed        TEXT DEFAULT '',
    supplier            TEXT DEFAULT '',
    tariff_price        REAL DEFAULT 0,
    last_seen           TEXT DEFAULT NULL,
    status_override     INTEGER DEFAULT 0,
    override_reason     TEXT DEFAULT NULL,
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_fsc_customer ON fiber_services_cache(splynx_customer_id);
CREATE INDEX IF NOT EXISTS idx_fsc_status   ON fiber_services_cache(splynx_status);
CREATE INDEX IF NOT EXISTS idx_fsc_plan     ON fiber_services_cache(plan_name);

-- Customer mapping — Splynx ↔ CRM customer links
CREATE TABLE IF NOT EXISTS fiber_customer_map (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    splynx_customer_id  TEXT NOT NULL UNIQUE,
    splynx_name         TEXT DEFAULT '',
    splynx_email        TEXT DEFAULT '',
    splynx_phone        TEXT DEFAULT '',
    crm_client_id       TEXT DEFAULT NULL,
    crm_name            TEXT DEFAULT '',
    linked_by           TEXT DEFAULT 'unmatched',
    linked_at           TEXT DEFAULT NULL,
    last_sync           TEXT DEFAULT NULL,
    created_at          TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_fcm_crm ON fiber_customer_map(crm_client_id);

-- Sync metadata
CREATE TABLE IF NOT EXISTS fiber_sync_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    sync_type       TEXT NOT NULL DEFAULT 'full',
    started_at      TEXT NOT NULL,
    completed_at    TEXT DEFAULT NULL,
    services_total  INTEGER DEFAULT 0,
    services_new    INTEGER DEFAULT 0,
    services_updated INTEGER DEFAULT 0,
    customers_mapped INTEGER DEFAULT 0,
    customers_unmapped INTEGER DEFAULT 0,
    errors          INTEGER DEFAULT 0,
    log_text        TEXT DEFAULT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
