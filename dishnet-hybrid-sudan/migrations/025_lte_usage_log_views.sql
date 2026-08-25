-- Migration 025: LTE Usage Sync Log & Views
-- Track usage sync history and create useful views

-- Usage sync log (cron job runs)
CREATE TABLE IF NOT EXISTS lte_usage_sync_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    synced_at       TEXT    NOT NULL,
    subscriptions   INTEGER DEFAULT 0,          -- Count synced
    warnings_sent   INTEGER DEFAULT 0,          -- 50%/80% warnings
    suspended       INTEGER DEFAULT 0,          -- Exhausted suspensions
    errors          INTEGER DEFAULT 0,
    duration_ms     INTEGER DEFAULT NULL,
    error_details   TEXT    DEFAULT NULL,       -- JSON array of errors
    created_at      TEXT    DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_lte_sync_at ON lte_usage_sync_log(synced_at);

-- Auto-suspend log (expiry + data exhausted)
CREATE TABLE IF NOT EXISTS lte_auto_suspend_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    subscriber_id   INTEGER NOT NULL,
    name            TEXT    DEFAULT NULL,
    phone           TEXT    DEFAULT NULL,
    imsi            TEXT    DEFAULT NULL,
    reason          TEXT    NOT NULL,           -- auto_expired, data_exhausted
    package_name    TEXT    DEFAULT NULL,
    expires_at      TEXT    DEFAULT NULL,
    bytes_used      INTEGER DEFAULT NULL,
    bytes_allowed   INTEGER DEFAULT NULL,
    magma_synced    INTEGER DEFAULT 0,
    suspended_at    TEXT    DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_lte_suspend_sub ON lte_auto_suspend_log(subscriber_id);
CREATE INDEX IF NOT EXISTS idx_lte_suspend_at ON lte_auto_suspend_log(suspended_at);

-- Auto-reactivate log
CREATE TABLE IF NOT EXISTS lte_auto_reactivate_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    subscriber_id   INTEGER NOT NULL,
    name            TEXT    DEFAULT NULL,
    imsi            TEXT    DEFAULT NULL,
    expires_at      TEXT    DEFAULT NULL,
    magma_synced    INTEGER DEFAULT 0,
    reactivated_at  TEXT    DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_lte_react_sub ON lte_auto_reactivate_log(subscriber_id);

-- ══════════════════════════════════════════════════════════════════════
-- VIEWS FOR REPORTING
-- ══════════════════════════════════════════════════════════════════════

-- Active subscribers with current subscription
CREATE VIEW IF NOT EXISTS v_lte_active_subscribers AS
SELECT 
    s.id,
    s.name,
    s.phone,
    s.imsi,
    s.area,
    s.status,
    sub.package_name,
    sub.package_type,
    sub.expires_at,
    sub.bytes_allowed,
    sub.bytes_used,
    sub.usage_percent,
    sub.last_usage_sync,
    p.speed_mbps,
    p.magma_profile,
    CASE 
        WHEN sub.package_type = 0 THEN 'Data Cap'
        ELSE 'Unlimited'
    END as plan_type_label,
    CASE 
        WHEN sub.expires_at < date('now') THEN 'Expired'
        WHEN sub.usage_percent >= 100 THEN 'Exhausted'
        WHEN sub.usage_percent >= 80 THEN 'Critical'
        WHEN sub.usage_percent >= 50 THEN 'Warning'
        ELSE 'OK'
    END as usage_status
FROM lte_subscribers s
LEFT JOIN lte_subscriptions sub ON sub.subscriber_id = s.id AND sub.status = 'active'
LEFT JOIN lte_packages p ON p.id = sub.package_id
WHERE s.status = 'active' AND s.deleted_at IS NULL;

-- Data cap subscribers needing attention (>=80%)
CREATE VIEW IF NOT EXISTS v_lte_usage_alerts AS
SELECT 
    s.id as subscriber_id,
    s.name,
    s.phone,
    s.imsi,
    sub.id as subscription_id,
    sub.package_name,
    sub.bytes_allowed,
    sub.bytes_used,
    sub.bytes_remaining,
    sub.usage_percent,
    sub.expires_at,
    sub.last_usage_sync
FROM lte_subscribers s
JOIN lte_subscriptions sub ON sub.subscriber_id = s.id
WHERE sub.status = 'active'
  AND sub.package_type = 0
  AND sub.usage_percent >= 80
ORDER BY sub.usage_percent DESC;

-- Daily renewal summary
CREATE VIEW IF NOT EXISTS v_lte_daily_renewals AS
SELECT 
    date(created_at) as renewal_date,
    COUNT(*) as renewal_count,
    SUM(amount_paid) as total_revenue,
    COUNT(DISTINCT subscriber_id) as unique_customers,
    COUNT(DISTINCT agent_id) as agents
FROM lte_renewals
GROUP BY date(created_at)
ORDER BY renewal_date DESC;

-- Agent performance summary
CREATE VIEW IF NOT EXISTS v_lte_agent_performance AS
SELECT 
    agent_id,
    agent_name,
    COUNT(*) as total_renewals,
    SUM(amount_paid) as total_revenue,
    COUNT(DISTINCT subscriber_id) as unique_customers,
    MAX(created_at) as last_renewal
FROM lte_renewals
WHERE created_at >= date('now', '-30 days')
GROUP BY agent_id, agent_name
ORDER BY total_revenue DESC;

-- Package popularity
CREATE VIEW IF NOT EXISTS v_lte_package_stats AS
SELECT 
    p.id,
    p.name,
    p.price,
    p.type_label,
    p.duration_days,
    COUNT(sub.id) as active_subscribers,
    SUM(CASE WHEN r.created_at >= date('now', '-30 days') THEN 1 ELSE 0 END) as renewals_30d,
    SUM(CASE WHEN r.created_at >= date('now', '-30 days') THEN r.amount_paid ELSE 0 END) as revenue_30d
FROM lte_packages p
LEFT JOIN lte_subscriptions sub ON sub.package_id = p.id AND sub.status = 'active'
LEFT JOIN lte_renewals r ON r.package_id = p.id
WHERE p.is_active = 1
GROUP BY p.id
ORDER BY active_subscribers DESC;
