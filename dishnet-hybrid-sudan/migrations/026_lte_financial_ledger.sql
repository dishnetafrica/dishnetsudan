-- Migration 026: LTE Financial Ledger
-- Dedicated accounting layer for LTE transactions (as recommended)
-- Separate from UCRM billing - for reconciliation, agent settlement, revenue reporting

CREATE TABLE IF NOT EXISTS lte_financial_ledger (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- Transaction Identity
    trx_ref         TEXT    NOT NULL,           -- Unique reference: LTE-{date}-{seq}
    
    -- Parties
    subscriber_id   INTEGER DEFAULT NULL,       -- FK to lte_subscribers
    subscriber_name TEXT    DEFAULT NULL,
    agent_id        INTEGER DEFAULT NULL,       -- Retailer/staff who processed
    agent_name      TEXT    DEFAULT NULL,
    
    -- Transaction Type
    trx_type        TEXT    NOT NULL,           -- sale, renewal, refund, adjustment, commission
    
    -- Amount
    amount          REAL    NOT NULL DEFAULT 0,
    currency        TEXT    DEFAULT 'USD',
    
    -- Payment Details
    payment_method  TEXT    DEFAULT 'cash',     -- cash, wallet, mpesa
    wallet_deducted INTEGER DEFAULT 0,          -- 1 = deducted from agent wallet
    
    -- Related Records
    subscription_id INTEGER DEFAULT NULL,       -- FK to lte_subscriptions
    renewal_id      INTEGER DEFAULT NULL,       -- FK to lte_renewals
    package_id      INTEGER DEFAULT NULL,
    package_name    TEXT    DEFAULT NULL,
    
    -- Description
    description     TEXT    DEFAULT NULL,
    notes           TEXT    DEFAULT NULL,
    
    -- Settlement
    settled         INTEGER DEFAULT 0,          -- 1 = settled with agent
    settled_at      TEXT    DEFAULT NULL,
    settled_by      INTEGER DEFAULT NULL,
    
    -- Timestamps
    created_at      TEXT    DEFAULT (datetime('now')),
    transaction_date TEXT   DEFAULT (date('now'))
);

-- Indexes for reporting
CREATE INDEX IF NOT EXISTS idx_lte_fin_date ON lte_financial_ledger(transaction_date);
CREATE INDEX IF NOT EXISTS idx_lte_fin_agent ON lte_financial_ledger(agent_id);
CREATE INDEX IF NOT EXISTS idx_lte_fin_subscriber ON lte_financial_ledger(subscriber_id);
CREATE INDEX IF NOT EXISTS idx_lte_fin_type ON lte_financial_ledger(trx_type);
CREATE INDEX IF NOT EXISTS idx_lte_fin_settled ON lte_financial_ledger(settled, agent_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_lte_fin_ref ON lte_financial_ledger(trx_ref);

-- ══════════════════════════════════════════════════════════════════════════════
-- SERVICE LINKS TABLE
-- Cross-reference between LTE subscribers and UCRM clients (when same person)
-- More flexible than storing ucrm_id directly in lte_subscribers
-- ══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS lte_service_links (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- LTE Side
    lte_subscriber_id INTEGER NOT NULL,         -- FK to lte_subscribers
    
    -- UCRM Side  
    ucrm_client_id  INTEGER NOT NULL,           -- UCRM client ID
    
    -- Service Type (what UCRM service they have)
    ucrm_service_type TEXT DEFAULT NULL,        -- fiber, starlink, etc.
    
    -- Link Metadata
    linked_by       INTEGER DEFAULT NULL,       -- Staff who created link
    linked_at       TEXT    DEFAULT (datetime('now')),
    notes           TEXT    DEFAULT NULL,
    
    UNIQUE(lte_subscriber_id, ucrm_client_id)
);

CREATE INDEX IF NOT EXISTS idx_lte_link_sub ON lte_service_links(lte_subscriber_id);
CREATE INDEX IF NOT EXISTS idx_lte_link_ucrm ON lte_service_links(ucrm_client_id);

-- ══════════════════════════════════════════════════════════════════════════════
-- REPORTING VIEWS
-- ══════════════════════════════════════════════════════════════════════════════

-- Daily revenue summary
CREATE VIEW IF NOT EXISTS v_lte_daily_revenue AS
SELECT 
    transaction_date,
    COUNT(*) as transactions,
    SUM(CASE WHEN trx_type = 'sale' THEN amount ELSE 0 END) as new_sales,
    SUM(CASE WHEN trx_type = 'renewal' THEN amount ELSE 0 END) as renewals,
    SUM(CASE WHEN trx_type = 'refund' THEN amount ELSE 0 END) as refunds,
    SUM(amount) as total_revenue,
    COUNT(DISTINCT agent_id) as agents_active
FROM lte_financial_ledger
WHERE trx_type IN ('sale', 'renewal', 'refund')
GROUP BY transaction_date
ORDER BY transaction_date DESC;

-- Agent settlement summary (unsettled amounts)
CREATE VIEW IF NOT EXISTS v_lte_agent_settlement AS
SELECT 
    agent_id,
    agent_name,
    COUNT(*) as unsettled_count,
    SUM(amount) as unsettled_amount,
    MIN(transaction_date) as oldest_unsettled,
    MAX(transaction_date) as latest_unsettled
FROM lte_financial_ledger
WHERE settled = 0 AND trx_type IN ('sale', 'renewal')
GROUP BY agent_id, agent_name
ORDER BY unsettled_amount DESC;

-- Multi-service customers (LTE + Fiber/Starlink)
CREATE VIEW IF NOT EXISTS v_lte_multi_service_customers AS
SELECT 
    s.id as lte_subscriber_id,
    s.name,
    s.phone,
    s.imsi,
    l.ucrm_client_id,
    l.ucrm_service_type,
    l.linked_at
FROM lte_subscribers s
JOIN lte_service_links l ON l.lte_subscriber_id = s.id
WHERE s.deleted_at IS NULL;
