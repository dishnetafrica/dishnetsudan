-- Migration 021: LTE SIM Cards
-- SIM card inventory with authentication credentials
-- Replaces lte_sims.json

CREATE TABLE IF NOT EXISTS lte_sims (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- SIM Identity
    imsi            TEXT    NOT NULL,           -- e.g., "460000000005238"
    msisdn          TEXT    DEFAULT NULL,       -- Usually same as IMSI for DishNet
    iccid           TEXT    DEFAULT NULL,       -- Physical SIM card ID (20 digits)
    
    -- Authentication (from SIM vendor - SENSITIVE!)
    auth_key        TEXT    NOT NULL,           -- K value (hex, 32 chars)
    auth_opc        TEXT    NOT NULL,           -- OPc value (hex, 32 chars)
    auth_algo       TEXT    DEFAULT 'Milenage', -- MILENAGE algorithm
    
    -- Inventory Status
    status          TEXT    DEFAULT 'stock',    -- stock, assigned, active, suspended, retired, lost
    subscriber_id   INTEGER DEFAULT NULL,       -- FK to lte_subscribers when assigned
    
    -- Holder (for stock distribution to dealers/retailers)
    holder_id       INTEGER DEFAULT NULL,       -- Retailer/dealer holding this SIM
    holder_type     TEXT    DEFAULT NULL,       -- admin, dealer, retailer
    holder_name     TEXT    DEFAULT NULL,
    
    -- Provenance
    batch           TEXT    DEFAULT NULL,       -- Import batch reference
    vendor          TEXT    DEFAULT 'DishNet',
    purchase_date   TEXT    DEFAULT NULL,
    purchase_cost   REAL    DEFAULT NULL,
    
    -- Magma State
    magma_provisioned INTEGER DEFAULT 0,        -- 1 = exists in Magma
    magma_state     TEXT    DEFAULT NULL,       -- ACTIVE/INACTIVE
    magma_synced_at TEXT    DEFAULT NULL,
    
    -- Assignment tracking
    assigned_at     TEXT    DEFAULT NULL,
    assigned_by     INTEGER DEFAULT NULL,
    
    -- Notes
    notes           TEXT    DEFAULT NULL,
    
    -- Timestamps
    created_at      TEXT    DEFAULT (datetime('now')),
    updated_at      TEXT    DEFAULT NULL,
    deleted_at      TEXT    DEFAULT NULL
);

-- Indexes
CREATE UNIQUE INDEX IF NOT EXISTS idx_lte_sim_imsi ON lte_sims(imsi);
CREATE UNIQUE INDEX IF NOT EXISTS idx_lte_sim_iccid ON lte_sims(iccid) WHERE iccid IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_lte_sim_status ON lte_sims(status);
CREATE INDEX IF NOT EXISTS idx_lte_sim_subscriber ON lte_sims(subscriber_id) WHERE subscriber_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_lte_sim_holder ON lte_sims(holder_id, holder_type);
CREATE INDEX IF NOT EXISTS idx_lte_sim_batch ON lte_sims(batch) WHERE batch IS NOT NULL;
