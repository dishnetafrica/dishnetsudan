-- Migration 020: LTE Subscribers
-- Customer records for Private LTE (Magma/Baicells)
-- Replaces lte_subscribers.json

CREATE TABLE IF NOT EXISTS lte_subscribers (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- Identity
    name            TEXT    NOT NULL,
    phone           TEXT    DEFAULT NULL,       -- WhatsApp number (real phone)
    email           TEXT    DEFAULT NULL,
    address         TEXT    DEFAULT NULL,
    area            TEXT    DEFAULT NULL,       -- Juba neighborhood
    
    -- ID Document
    id_type         TEXT    DEFAULT NULL,       -- national_id, passport, etc.
    id_number       TEXT    DEFAULT NULL,
    
    -- GPS Location
    gps_lat         REAL    DEFAULT NULL,
    gps_lon         REAL    DEFAULT NULL,
    
    -- LTE Credentials (linked from SIM card)
    imsi            TEXT    DEFAULT NULL,       -- e.g., "460000000005238"
    msisdn          TEXT    DEFAULT NULL,       -- Same as IMSI for DishNet
    sim_id          INTEGER DEFAULT NULL,       -- FK to lte_sims
    
    -- Hardware (CPE)
    hardware_id     INTEGER DEFAULT NULL,       -- FK to lte_hardware (JSON for now)
    
    -- UCRM Link (OPTIONAL - manual reference only, NO auto-sync)
    ucrm_id         INTEGER DEFAULT NULL,       -- Optional link to UCRM client (if exists)
    
    -- Status
    status          TEXT    DEFAULT 'active',   -- active, suspended, terminated
    magma_state     TEXT    DEFAULT NULL,       -- ACTIVE, INACTIVE (from Magma)
    suspend_reason  TEXT    DEFAULT NULL,       -- non_payment, data_exhausted, auto_expired, manual
    suspended_at    TEXT    DEFAULT NULL,
    
    -- Agent/Registration
    agent_id        INTEGER DEFAULT NULL,
    agent_name      TEXT    DEFAULT NULL,
    registered_by   TEXT    DEFAULT 'staff',    -- staff, self, bulk_import
    
    -- BlueCard Migration
    bluecard_id     INTEGER DEFAULT NULL,       -- Original ID from BlueCard
    
    -- Notes
    notes           TEXT    DEFAULT NULL,
    service_type    TEXT    DEFAULT 'lte',
    
    -- Timestamps
    created_at      TEXT    DEFAULT (datetime('now')),
    updated_at      TEXT    DEFAULT NULL,
    deleted_at      TEXT    DEFAULT NULL        -- Soft delete
);

-- Indexes for fast lookups
CREATE UNIQUE INDEX IF NOT EXISTS idx_lte_sub_imsi ON lte_subscribers(imsi) WHERE imsi IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_lte_sub_phone ON lte_subscribers(phone);
CREATE INDEX IF NOT EXISTS idx_lte_sub_status ON lte_subscribers(status);
CREATE INDEX IF NOT EXISTS idx_lte_sub_agent ON lte_subscribers(agent_id);
CREATE INDEX IF NOT EXISTS idx_lte_sub_ucrm ON lte_subscribers(ucrm_id) WHERE ucrm_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_lte_sub_bluecard ON lte_subscribers(bluecard_id) WHERE bluecard_id IS NOT NULL;
