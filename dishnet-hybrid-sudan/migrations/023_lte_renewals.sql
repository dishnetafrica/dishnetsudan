-- Migration 023: LTE Renewals
-- Renewal/recharge history
-- Replaces lte_renewals.json

CREATE TABLE IF NOT EXISTS lte_renewals (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- References
    subscriber_id   INTEGER NOT NULL,           -- FK to lte_subscribers
    subscription_id INTEGER DEFAULT NULL,       -- FK to lte_subscriptions (new one created)
    previous_sub_id INTEGER DEFAULT NULL,       -- Previous subscription (if renewal)
    package_id      INTEGER NOT NULL,           -- FK to lte_packages
    
    -- Snapshot
    subscriber_name TEXT    DEFAULT NULL,
    package_name    TEXT    DEFAULT NULL,
    package_price   REAL    DEFAULT 0,
    duration_days   INTEGER DEFAULT NULL,
    
    -- Payment
    amount_paid     REAL    NOT NULL,
    payment_method  TEXT    DEFAULT 'cash',     -- cash, wallet, mpesa
    wallet_deducted INTEGER DEFAULT 0,          -- 1 = deducted from retailer wallet
    
    -- Wallet Transaction Link
    passbook_id     INTEGER DEFAULT NULL,       -- FK to wallet_passbook if wallet payment
    collection_id   INTEGER DEFAULT NULL,       -- FK to payment_collections if applicable
    
    -- Validity
    started_at      TEXT    DEFAULT NULL,
    expires_at      TEXT    DEFAULT NULL,
    
    -- Agent
    agent_id        INTEGER DEFAULT NULL,
    agent_name      TEXT    DEFAULT NULL,
    
    -- Magma Sync
    magma_synced    INTEGER DEFAULT 0,          -- 1 = pushed to Magma
    magma_profile   TEXT    DEFAULT NULL,
    magma_error     TEXT    DEFAULT NULL,
    
    -- Type
    is_addon        INTEGER DEFAULT 0,          -- 1 = data top-up (add to existing)
    is_upgrade      INTEGER DEFAULT 0,          -- 1 = plan upgrade
    is_new          INTEGER DEFAULT 0,          -- 1 = first-time activation
    
    -- Timestamps
    created_at      TEXT    DEFAULT (datetime('now'))
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_lte_ren_subscriber ON lte_renewals(subscriber_id);
CREATE INDEX IF NOT EXISTS idx_lte_ren_package ON lte_renewals(package_id);
CREATE INDEX IF NOT EXISTS idx_lte_ren_agent ON lte_renewals(agent_id);
CREATE INDEX IF NOT EXISTS idx_lte_ren_created ON lte_renewals(created_at);
CREATE INDEX IF NOT EXISTS idx_lte_ren_date ON lte_renewals(date(created_at));
