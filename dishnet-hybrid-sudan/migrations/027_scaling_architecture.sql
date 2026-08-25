-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 027: 50K Subscriber Scaling Architecture
-- ═══════════════════════════════════════════════════════════════════════════
-- 
-- Implements telecom-grade scaling patterns:
-- 1. Event-driven processing with next_action_at
-- 2. Time-series usage history table
-- 3. Unified network event log
-- 4. System cache for dashboard performance
-- 5. Critical indexes for query optimization
--
-- Target: 50,000+ LTE subscribers on single server
-- ═══════════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Add next_action_at to subscriptions for event-driven cron
-- ─────────────────────────────────────────────────────────────────────────────
-- Instead of scanning 50K rows, cron only processes subscriptions with
-- next_action_at <= NOW(). Massive performance gain.

ALTER TABLE lte_subscriptions ADD COLUMN next_action_at TEXT;
ALTER TABLE lte_subscriptions ADD COLUMN action_type TEXT;  -- 'usage_check', 'expiry_warning', 'suspend', 'renewal_reminder'

-- Initialize: set next_action_at based on current state
UPDATE lte_subscriptions 
SET next_action_at = datetime(expires_at, '-3 days'),
    action_type = 'expiry_warning'
WHERE status = 'active' AND next_action_at IS NULL;

-- Critical index for event-driven cron
CREATE INDEX IF NOT EXISTS idx_lte_subs_next_action 
ON lte_subscriptions(next_action_at) 
WHERE status = 'active';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Time-Series Usage History (for graphs, analytics, AI)
-- ─────────────────────────────────────────────────────────────────────────────
-- At 50K users with 5-min samples: 14.4M records/day
-- Partition by month, auto-cleanup after 90 days

CREATE TABLE IF NOT EXISTS lte_usage_history (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    subscription_id INTEGER NOT NULL,
    subscriber_id   INTEGER NOT NULL,
    imsi            TEXT NOT NULL,
    
    -- Usage snapshot
    bytes_used      INTEGER NOT NULL DEFAULT 0,    -- Cumulative from Prometheus
    bytes_delta     INTEGER NOT NULL DEFAULT 0,    -- Change since last sample
    
    -- Context
    plan_type       INTEGER DEFAULT 0,             -- 0=data_cap, 2=unlimited
    bytes_allowed   INTEGER,                       -- From subscription at time of sample
    usage_percent   REAL,                          -- Calculated: (used/allowed)*100
    
    -- Metadata
    recorded_at     TEXT NOT NULL,                 -- ISO timestamp
    source          TEXT DEFAULT 'prometheus',    -- 'prometheus', 'manual', 'import'
    
    -- Partition key (for future SQLite optimization or Postgres migration)
    partition_key   TEXT GENERATED ALWAYS AS (strftime('%Y_%m', recorded_at)) STORED
);

-- Indexes for efficient querying
CREATE INDEX IF NOT EXISTS idx_usage_hist_sub_time 
ON lte_usage_history(subscription_id, recorded_at DESC);

CREATE INDEX IF NOT EXISTS idx_usage_hist_partition 
ON lte_usage_history(partition_key);

CREATE INDEX IF NOT EXISTS idx_usage_hist_imsi_time 
ON lte_usage_history(imsi, recorded_at DESC);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Unified Network Event Log (replaces scattered _log tables)
-- ─────────────────────────────────────────────────────────────────────────────
-- Every telecom system needs a single event stream for:
-- - Audit trails
-- - Troubleshooting
-- - Analytics
-- - Compliance

CREATE TABLE IF NOT EXISTS lte_network_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- References
    subscriber_id   INTEGER,
    subscription_id INTEGER,
    sim_id          INTEGER,
    imsi            TEXT,
    
    -- Event
    event_type      TEXT NOT NULL,  -- See event types below
    event_category  TEXT NOT NULL,  -- 'lifecycle', 'usage', 'network', 'billing', 'system'
    severity        TEXT DEFAULT 'info',  -- 'debug', 'info', 'warning', 'error', 'critical'
    
    -- Details (JSON for flexibility)
    details         TEXT,           -- JSON: {"old_status": "active", "new_status": "suspended", ...}
    
    -- Actor
    actor_type      TEXT,           -- 'system', 'cron', 'admin', 'agent', 'customer', 'magma'
    actor_id        INTEGER,
    actor_name      TEXT,
    
    -- Timestamps
    created_at      TEXT DEFAULT (datetime('now')),
    
    -- Partition key
    partition_key   TEXT GENERATED ALWAYS AS (strftime('%Y_%m', created_at)) STORED
);

-- Event types:
-- lifecycle: subscriber_created, subscription_started, subscription_renewed, 
--            subscription_expired, subscription_suspended, subscription_reactivated,
--            sim_assigned, sim_swapped, profile_changed
-- usage:     usage_synced, usage_warning_50, usage_warning_80, usage_exhausted
-- network:   magma_provisioned, magma_suspended, magma_activated, magma_error
-- billing:   payment_received, wallet_debited, commission_paid
-- system:    cron_started, cron_completed, import_completed, error_occurred

CREATE INDEX IF NOT EXISTS idx_events_sub_time 
ON lte_network_events(subscriber_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_events_type_time 
ON lte_network_events(event_type, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_events_category 
ON lte_network_events(event_category, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_events_partition 
ON lte_network_events(partition_key);

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. System Cache Table (Dashboard Performance)
-- ─────────────────────────────────────────────────────────────────────────────
-- Dashboards should read from cache, not scan tables.
-- Cache is updated by background workers every 5 minutes.

CREATE TABLE IF NOT EXISTS system_cache (
    cache_key       TEXT PRIMARY KEY,
    cache_value     TEXT NOT NULL,       -- JSON blob
    updated_at      TEXT NOT NULL,
    expires_at      TEXT,                -- Optional TTL
    category        TEXT DEFAULT 'general'  -- 'lte', 'fiber', 'starlink', 'wallet', 'general'
);

-- Pre-populate LTE dashboard cache keys
INSERT OR IGNORE INTO system_cache (cache_key, cache_value, updated_at, category) VALUES
('lte_subscriber_counts', '{"active":0,"suspended":0,"total":0}', datetime('now'), 'lte'),
('lte_usage_summary', '{"total_gb":0,"avg_per_user":0}', datetime('now'), 'lte'),
('lte_revenue_today', '{"amount":0,"count":0}', datetime('now'), 'lte'),
('lte_expiring_soon', '{"3_days":0,"7_days":0,"today":0}', datetime('now'), 'lte'),
('lte_network_health', '{"magma":"unknown","prometheus":"unknown","last_check":null}', datetime('now'), 'lte'),
('lte_usage_alerts', '{"warning_50":0,"warning_80":0,"exhausted":0}', datetime('now'), 'lte');

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. LTE Job Queue (Async Processing)
-- ─────────────────────────────────────────────────────────────────────────────
-- We already have job_queue from migration 002, but add LTE-specific view

CREATE VIEW IF NOT EXISTS v_lte_pending_jobs AS
SELECT 
    j.*,
    json_extract(j.payload, '$.subscription_id') AS subscription_id,
    json_extract(j.payload, '$.subscriber_id') AS subscriber_id,
    json_extract(j.payload, '$.imsi') AS imsi
FROM job_queue j
WHERE j.job_type LIKE 'lte_%'
  AND j.status = 'pending'
ORDER BY j.run_after ASC, j.created_at ASC;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. Enhanced Subscriber Indexes
-- ─────────────────────────────────────────────────────────────────────────────

-- For active subscriber queries (dashboard, API)
CREATE INDEX IF NOT EXISTS idx_lte_subs_status 
ON lte_subscribers(status);

-- For lookups by IMSI (most common in LTE operations)
CREATE INDEX IF NOT EXISTS idx_lte_subs_imsi 
ON lte_subscribers(imsi);

-- For phone/WhatsApp lookups
CREATE INDEX IF NOT EXISTS idx_lte_subs_phone 
ON lte_subscribers(phone) 
WHERE phone IS NOT NULL;

-- ─────────────────────────────────────────────────────────────────────────────
-- 7. Enhanced Subscription Indexes  
-- ─────────────────────────────────────────────────────────────────────────────

-- For active subscription queries
CREATE INDEX IF NOT EXISTS idx_lte_subscriptions_status 
ON lte_subscriptions(status);

-- For expiry-based queries (renewal queue, auto-suspend)
CREATE INDEX IF NOT EXISTS idx_lte_subscriptions_expires 
ON lte_subscriptions(expires_at) 
WHERE status = 'active';

-- For subscriber lookup (most common JOIN)
CREATE INDEX IF NOT EXISTS idx_lte_subscriptions_subscriber 
ON lte_subscriptions(subscriber_id);

-- Compound index for usage tracking (data cap plans only)
CREATE INDEX IF NOT EXISTS idx_lte_subscriptions_usage 
ON lte_subscriptions(status, package_type) 
WHERE package_type = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 8. Add last_activity_at for smart sync
-- ─────────────────────────────────────────────────────────────────────────────
-- Only sync usage for subscribers active in last 24-48 hours

ALTER TABLE lte_subscribers ADD COLUMN last_activity_at TEXT;
ALTER TABLE lte_subscribers ADD COLUMN is_online INTEGER DEFAULT 0;

-- Index for "active recently" queries
CREATE INDEX IF NOT EXISTS idx_lte_subs_activity 
ON lte_subscribers(last_activity_at DESC) 
WHERE status = 'active';

-- ─────────────────────────────────────────────────────────────────────────────
-- 9. Views for Dashboard Performance
-- ─────────────────────────────────────────────────────────────────────────────

-- Real-time subscriber summary (updated by cache worker)
CREATE VIEW IF NOT EXISTS v_lte_subscriber_summary AS
SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended,
    SUM(CASE WHEN status = 'terminated' THEN 1 ELSE 0 END) AS terminated,
    SUM(CASE WHEN last_activity_at >= datetime('now', '-24 hours') THEN 1 ELSE 0 END) AS active_24h,
    SUM(CASE WHEN last_activity_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS active_7d
FROM lte_subscribers;

-- Subscription expiry summary
CREATE VIEW IF NOT EXISTS v_lte_expiry_summary AS
SELECT 
    SUM(CASE WHEN expires_at <= datetime('now') THEN 1 ELSE 0 END) AS expired,
    SUM(CASE WHEN expires_at BETWEEN datetime('now') AND datetime('now', '+1 day') THEN 1 ELSE 0 END) AS expires_today,
    SUM(CASE WHEN expires_at BETWEEN datetime('now', '+1 day') AND datetime('now', '+3 days') THEN 1 ELSE 0 END) AS expires_3_days,
    SUM(CASE WHEN expires_at BETWEEN datetime('now', '+3 days') AND datetime('now', '+7 days') THEN 1 ELSE 0 END) AS expires_7_days
FROM lte_subscriptions
WHERE status = 'active';

-- Usage alerts summary
CREATE VIEW IF NOT EXISTS v_lte_usage_alert_summary AS
SELECT 
    SUM(CASE WHEN usage_percent >= 50 AND usage_percent < 80 THEN 1 ELSE 0 END) AS warning_50,
    SUM(CASE WHEN usage_percent >= 80 AND usage_percent < 95 THEN 1 ELSE 0 END) AS warning_80,
    SUM(CASE WHEN usage_percent >= 95 THEN 1 ELSE 0 END) AS critical,
    SUM(CASE WHEN bytes_used >= bytes_allowed THEN 1 ELSE 0 END) AS exhausted
FROM lte_subscriptions
WHERE status = 'active' AND package_type = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 10. Cleanup jobs for historical data
-- ─────────────────────────────────────────────────────────────────────────────

-- Usage history older than 90 days can be archived/deleted
-- Network events older than 180 days can be archived/deleted
-- This will be handled by a maintenance worker

-- Record migration completion
INSERT INTO lte_network_events (event_type, event_category, severity, details, actor_type)
VALUES ('migration_completed', 'system', 'info', 
        '{"migration":"027_scaling_architecture","description":"50K subscriber scaling patterns"}',
        'system');
