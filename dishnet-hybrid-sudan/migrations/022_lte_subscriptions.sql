-- Migration 022: LTE Subscriptions
-- Active and historical subscriptions with data usage tracking
-- Replaces lte_subscriptions.json

CREATE TABLE IF NOT EXISTS lte_subscriptions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- References
    subscriber_id   INTEGER NOT NULL,           -- FK to lte_subscribers
    package_id      INTEGER NOT NULL,           -- FK to lte_packages
    
    -- Package Snapshot (denormalized for history)
    package_name    TEXT    DEFAULT NULL,
    package_type    INTEGER DEFAULT 2,          -- 0=data_cap, 2=unlimited
    
    -- Magma Profile
    magma_profile   TEXT    DEFAULT 'default',  -- sub_profile in Magma (silver, gold, etc.)
    
    -- Validity Period
    started_at      TEXT    NOT NULL,           -- When subscription started
    expires_at      TEXT    NOT NULL,           -- When subscription expires
    
    -- Data Limits (for package_type=0)
    bytes_allowed   INTEGER DEFAULT NULL,       -- Total bytes in plan (NULL=unlimited)
    bytes_baseline  INTEGER DEFAULT 0,          -- Prometheus reading at subscription start (for delta calc)
    bytes_used      INTEGER DEFAULT 0,          -- Consumed bytes (from Prometheus - baseline)
    bytes_remaining INTEGER DEFAULT NULL,       -- Calculated: allowed - used
    usage_percent   REAL    DEFAULT 0.0,        -- (used/allowed)*100
    
    -- Usage Alerts Sent
    warned_50       INTEGER DEFAULT 0,          -- 1 = 50% warning sent
    warned_80       INTEGER DEFAULT 0,          -- 1 = 80% warning sent  
    warned_100      INTEGER DEFAULT 0,          -- 1 = exhausted alert sent
    
    -- Usage Sync
    last_usage_sync TEXT    DEFAULT NULL,       -- Last Prometheus query timestamp
    usage_sync_error TEXT   DEFAULT NULL,       -- Last sync error if any
    
    -- Status
    status          TEXT    DEFAULT 'active',   -- active, expired, exhausted, cancelled, upgraded
    expired_at      TEXT    DEFAULT NULL,       -- When status changed to expired
    
    -- Payment
    amount_paid     REAL    DEFAULT 0,
    payment_method  TEXT    DEFAULT 'cash',     -- cash, wallet, mpesa
    
    -- Agent
    agent_id        INTEGER DEFAULT NULL,
    agent_name      TEXT    DEFAULT NULL,
    
    -- Renewal Chain
    renewed_from    INTEGER DEFAULT NULL,       -- Previous subscription ID if renewal
    renewed_to      INTEGER DEFAULT NULL,       -- Next subscription ID if renewed
    
    -- Timestamps
    created_at      TEXT    DEFAULT (datetime('now')),
    updated_at      TEXT    DEFAULT NULL
);

-- Indexes for common queries
CREATE INDEX IF NOT EXISTS idx_lte_subs_subscriber ON lte_subscriptions(subscriber_id);
CREATE INDEX IF NOT EXISTS idx_lte_subs_package ON lte_subscriptions(package_id);
CREATE INDEX IF NOT EXISTS idx_lte_subs_status ON lte_subscriptions(status);
CREATE INDEX IF NOT EXISTS idx_lte_subs_expires ON lte_subscriptions(expires_at);
CREATE INDEX IF NOT EXISTS idx_lte_subs_active ON lte_subscriptions(subscriber_id, status) WHERE status = 'active';

-- Composite index for usage sync cron (data cap plans only)
CREATE INDEX IF NOT EXISTS idx_lte_subs_datacap ON lte_subscriptions(status, package_type) 
    WHERE status = 'active' AND package_type = 0;
