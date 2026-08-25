-- Migration 024: LTE Packages
-- Data plan catalog
-- Replaces lte_packages.json

CREATE TABLE IF NOT EXISTS lte_packages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- Identity
    name            TEXT    NOT NULL,           -- e.g., "Silver-40", "15GB-5"
    description     TEXT    DEFAULT NULL,
    
    -- Pricing
    price           REAL    NOT NULL DEFAULT 0,
    price_cents     INTEGER DEFAULT NULL,       -- Price in cents for precision
    currency        TEXT    DEFAULT 'USD',
    
    -- Plan Type: 0=data_cap (limited bytes), 2=unlimited (speed-capped)
    type            INTEGER NOT NULL DEFAULT 2,
    type_label      TEXT    DEFAULT 'unlimited', -- data_cap, unlimited
    
    -- Data Limits (for type=0)
    bytes_allowed   INTEGER DEFAULT NULL,       -- Total bytes (NULL=unlimited)
    data_gb         REAL    DEFAULT NULL,       -- GB for display
    bytes_display   TEXT    DEFAULT 'Unlimited', -- Human readable
    
    -- Speed Limits (for type=2)
    speed_mbps      REAL    DEFAULT NULL,       -- Max speed in Mbps
    
    -- Duration
    duration_days   INTEGER NOT NULL DEFAULT 30,
    days_display    TEXT    DEFAULT '1 Month',
    
    -- Magma Profile
    magma_profile   TEXT    DEFAULT 'default',  -- sub_profile name in Magma
    active_apns     TEXT    DEFAULT 'cmnet',    -- Comma-separated APNs
    active_policies TEXT    DEFAULT 'omni',     -- Comma-separated policies
    
    -- Display
    is_active       INTEGER DEFAULT 1,          -- Show in UI
    is_popular      INTEGER DEFAULT 0,          -- Featured/popular flag
    sort_order      INTEGER DEFAULT 999,        -- Display order
    
    -- Lifecycle
    lifecycle_status TEXT   DEFAULT 'active',   -- draft, active, deprecated
    
    -- BlueCard Migration
    bluecard_id     INTEGER DEFAULT NULL,       -- Original ID from BlueCard
    
    -- Timestamps
    created_at      TEXT    DEFAULT (datetime('now')),
    updated_at      TEXT    DEFAULT NULL
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_lte_pkg_active ON lte_packages(is_active, sort_order);
CREATE INDEX IF NOT EXISTS idx_lte_pkg_type ON lte_packages(type);
CREATE INDEX IF NOT EXISTS idx_lte_pkg_bluecard ON lte_packages(bluecard_id) WHERE bluecard_id IS NOT NULL;

-- Insert default packages from BlueCard
INSERT OR IGNORE INTO lte_packages (id, name, description, price, type, type_label, bytes_allowed, data_gb, bytes_display, speed_mbps, duration_days, days_display, magma_profile, is_active, is_popular, sort_order, bluecard_id) VALUES
-- Unlimited Plans (type=2)
(1, 'Silver-40', 'Silver 1 month 40 USD - Up to 2 Mbps', 40.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 2, 31, '1 Month', 'silver', 1, 1, 1, 1),
(2, 'Gold-80', 'Gold 1 month 80 USD - Up to 4 Mbps', 80.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 4, 31, '1 Month', 'gold', 1, 1, 2, 2),
(3, 'Platinum-110', 'Platinum 1 month 110 USD - Up to 6 Mbps', 110.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 6, 31, '1 Month', 'platinum', 1, 0, 3, 3),
(4, 'Diamond-250', 'Diamond 1 month 250 USD - Up to 10 Mbps', 250.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 10, 31, '1 Month', 'diamond', 1, 0, 4, 4),
(8, 'Silver-120', 'Silver 100 days 120 USD - Up to 2 Mbps', 120.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 2, 100, '3 Months', 'silver', 1, 0, 5, 8),
(9, 'Silver-25', 'Silver 16 days 25 USD - Up to 2 Mbps', 25.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 2, 16, '16 Days', 'silver', 1, 0, 6, 9),
(10, 'Silver-200', 'Silver 6 months 200 USD - Up to 2 Mbps', 200.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 2, 185, '6 Months', 'silver', 1, 0, 7, 10),

-- Data Cap Plans (type=0)
(5, '15GB-5', '15GB Validity 1 Month', 5.00, 0, 'data_cap', 16106127360, 15.0, '15 GB', NULL, 30, '1 Month', 'default', 1, 1, 10, 5),
(6, '25GB-10', '25GB Validity 1 Month', 10.00, 0, 'data_cap', 26843545600, 25.0, '25 GB', NULL, 30, '1 Month', 'default', 1, 1, 11, 6),
(7, '50GB-25', '50GB Validity 1 Month', 25.00, 0, 'data_cap', 53687091200, 50.0, '50 GB', NULL, 30, '1 Month', 'default', 1, 0, 12, 7),

-- Demo/Trial Plans
(11, 'Silver-Demo-3', 'Silver Demo 3 days - Staff/Trial', 0.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 2, 3, '3 Days', 'silver', 1, 0, 100, 11),
(12, 'Silver-Demo-7', 'Silver Demo 7 days - Staff/Trial', 0.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 2, 7, '7 Days', 'silver', 1, 0, 101, 12),
(13, 'Silver-Demo-15', 'Silver Demo 15 days - Staff/Trial', 0.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 2, 15, '15 Days', 'silver', 1, 0, 102, 13),
(14, 'Gold-Demo-3', 'Gold Demo 3 days - Staff/Trial', 0.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 4, 3, '3 Days', 'gold', 1, 0, 103, 14),
(15, 'Gold-Demo-7', 'Gold Demo 7 days - Staff/Trial', 0.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 4, 7, '7 Days', 'gold', 1, 0, 104, 15),
(16, 'Platinum-Demo-3', 'Platinum Demo 3 days - Staff/Trial', 0.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 6, 3, '3 Days', 'platinum', 1, 0, 105, 16),
(17, 'Platinum-Demo-7', 'Platinum Demo 7 days - Staff/Trial', 0.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 6, 7, '7 Days', 'platinum', 1, 0, 106, 17),
(18, 'Staff-6-Months', 'Staff Plan 6 months - Internal Use', 0.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 4, 185, '6 Months', 'gold', 1, 0, 200, 18),
(19, 'Platinum-Demo-15', 'Platinum Demo 15 days - Staff/Trial', 0.00, 2, 'unlimited', NULL, NULL, 'Unlimited', 6, 15, '15 Days', 'platinum', 1, 0, 107, 19);
