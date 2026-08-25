-- Migration 029: BlueCard Staging Tables for Safe Import/Mapping
-- These tables store imported BlueCard MySQL data for inspection
-- WITHOUT affecting production LTE tables or triggering any external actions

-- ══════════════════════════════════════════════════════════════════════════════
-- STAGING TABLES (imported BlueCard data)
-- ══════════════════════════════════════════════════════════════════════════════

-- BlueCard users table (LTE subscribers in BlueCard)
CREATE TABLE IF NOT EXISTS bluecard_users (
    id                  INTEGER PRIMARY KEY,
    name                TEXT,
    phone               TEXT,
    email               TEXT,
    address             TEXT,
    area                TEXT,
    type                INTEGER DEFAULT 3,      -- 3 = LTE subscriber in BlueCard
    status              INTEGER DEFAULT 1,      -- 1 = active
    created_at          TEXT,
    updated_at          TEXT,
    
    -- Mapping status
    mapped_to_hybrid    INTEGER DEFAULT 0,      -- 1 = already migrated to lte_subscribers
    hybrid_subscriber_id INTEGER,               -- FK to lte_subscribers.id if mapped
    mapping_notes       TEXT,
    mapped_at           TEXT
);

CREATE INDEX IF NOT EXISTS idx_bc_users_phone ON bluecard_users(phone);
CREATE INDEX IF NOT EXISTS idx_bc_users_status ON bluecard_users(status);
CREATE INDEX IF NOT EXISTS idx_bc_users_mapped ON bluecard_users(mapped_to_hybrid);

-- BlueCard SIM cards
CREATE TABLE IF NOT EXISTS bluecard_sims (
    id                  INTEGER PRIMARY KEY,
    imsi                TEXT UNIQUE,
    msisdn              TEXT,
    iccid               TEXT,
    auth_key            TEXT,                   -- K value (hex)
    auth_opc            TEXT,                   -- OPc value (hex)
    algo                TEXT DEFAULT 'Milenage',
    status              INTEGER DEFAULT 1,      -- 1 = active
    user_id             INTEGER,                -- FK to bluecard_users
    created_at          TEXT,
    
    -- Mapping status
    mapped_to_hybrid    INTEGER DEFAULT 0,
    hybrid_sim_id       INTEGER,
    mapping_notes       TEXT,
    mapped_at           TEXT
);

CREATE INDEX IF NOT EXISTS idx_bc_sims_imsi ON bluecard_sims(imsi);
CREATE INDEX IF NOT EXISTS idx_bc_sims_user ON bluecard_sims(user_id);
CREATE INDEX IF NOT EXISTS idx_bc_sims_mapped ON bluecard_sims(mapped_to_hybrid);

-- BlueCard services (active subscriptions)
CREATE TABLE IF NOT EXISTS bluecard_services (
    id                  INTEGER PRIMARY KEY,
    user_id             INTEGER,                -- FK to bluecard_users
    plan_id             INTEGER,                -- FK to bluecard_plans
    plan_name           TEXT,
    price               REAL,
    start_date          TEXT,
    expiry_date         TEXT,
    status              INTEGER DEFAULT 1,      -- 1 = active
    magma_profile       TEXT,
    created_at          TEXT,
    updated_at          TEXT,
    
    -- Mapping status
    mapped_to_hybrid    INTEGER DEFAULT 0,
    hybrid_subscription_id INTEGER,
    mapping_notes       TEXT,
    mapped_at           TEXT
);

CREATE INDEX IF NOT EXISTS idx_bc_services_user ON bluecard_services(user_id);
CREATE INDEX IF NOT EXISTS idx_bc_services_status ON bluecard_services(status);
CREATE INDEX IF NOT EXISTS idx_bc_services_expiry ON bluecard_services(expiry_date);
CREATE INDEX IF NOT EXISTS idx_bc_services_mapped ON bluecard_services(mapped_to_hybrid);

-- BlueCard product offerings (plans)
CREATE TABLE IF NOT EXISTS bluecard_plans (
    id                  INTEGER PRIMARY KEY,
    name                TEXT,
    description         TEXT,
    price               REAL,
    type                INTEGER,                -- 0 = data_cap, 2 = unlimited
    bytes_allowed       INTEGER,                -- NULL for unlimited
    days                INTEGER,
    speed_mbps          REAL,
    magma_profile       TEXT,
    status              INTEGER DEFAULT 1,
    created_at          TEXT,
    
    -- Mapping status
    mapped_to_hybrid    INTEGER DEFAULT 0,
    hybrid_package_id   INTEGER,
    mapping_notes       TEXT
);

CREATE INDEX IF NOT EXISTS idx_bc_plans_status ON bluecard_plans(status);
CREATE INDEX IF NOT EXISTS idx_bc_plans_mapped ON bluecard_plans(mapped_to_hybrid);

-- BlueCard balance topups (renewals)
CREATE TABLE IF NOT EXISTS bluecard_topups (
    id                  INTEGER PRIMARY KEY,
    user_id             INTEGER,
    plan_id             INTEGER,
    plan_name           TEXT,
    amount              REAL,
    payment_method      TEXT,
    agent_id            INTEGER,
    agent_name          TEXT,
    created_at          TEXT,
    
    -- Mapping status
    mapped_to_hybrid    INTEGER DEFAULT 0,
    hybrid_renewal_id   INTEGER,
    mapping_notes       TEXT
);

CREATE INDEX IF NOT EXISTS idx_bc_topups_user ON bluecard_topups(user_id);
CREATE INDEX IF NOT EXISTS idx_bc_topups_date ON bluecard_topups(created_at);
CREATE INDEX IF NOT EXISTS idx_bc_topups_mapped ON bluecard_topups(mapped_to_hybrid);

-- BlueCard data_mgmt (usage tracking)
CREATE TABLE IF NOT EXISTS bluecard_usage (
    id                  INTEGER PRIMARY KEY,
    user_id             INTEGER,
    imsi                TEXT,
    bytes_allowed       INTEGER,
    bytes_used          INTEGER,
    prv_data            INTEGER,                -- Previous reading
    plan_type           INTEGER,
    last_sync           TEXT,
    created_at          TEXT,
    updated_at          TEXT,
    
    -- Mapping status
    mapped_to_hybrid    INTEGER DEFAULT 0,
    mapping_notes       TEXT
);

CREATE INDEX IF NOT EXISTS idx_bc_usage_user ON bluecard_usage(user_id);
CREATE INDEX IF NOT EXISTS idx_bc_usage_imsi ON bluecard_usage(imsi);

-- ══════════════════════════════════════════════════════════════════════════════
-- MAPPING VIEWS (compare BlueCard → Hybrid)
-- ══════════════════════════════════════════════════════════════════════════════

-- View: All BlueCard users with their mapping status
CREATE VIEW IF NOT EXISTS v_bluecard_user_mapping AS
SELECT 
    bu.id AS bluecard_id,
    bu.name AS bluecard_name,
    bu.phone AS bluecard_phone,
    bu.status AS bluecard_status,
    bu.mapped_to_hybrid,
    bu.hybrid_subscriber_id,
    ls.id AS hybrid_id,
    ls.name AS hybrid_name,
    ls.phone AS hybrid_phone,
    ls.status AS hybrid_status,
    CASE 
        WHEN bu.mapped_to_hybrid = 1 AND ls.id IS NOT NULL THEN 'mapped'
        WHEN bu.mapped_to_hybrid = 1 AND ls.id IS NULL THEN 'orphaned'
        ELSE 'pending'
    END AS mapping_status
FROM bluecard_users bu
LEFT JOIN lte_subscribers ls ON bu.hybrid_subscriber_id = ls.id;

-- View: All BlueCard SIMs with mapping status
CREATE VIEW IF NOT EXISTS v_bluecard_sim_mapping AS
SELECT 
    bs.id AS bluecard_id,
    bs.imsi AS bluecard_imsi,
    bs.user_id AS bluecard_user_id,
    bs.mapped_to_hybrid,
    bs.hybrid_sim_id,
    lsim.id AS hybrid_id,
    lsim.imsi AS hybrid_imsi,
    lsim.subscriber_id AS hybrid_subscriber_id,
    CASE 
        WHEN bs.mapped_to_hybrid = 1 AND lsim.id IS NOT NULL THEN 'mapped'
        WHEN bs.mapped_to_hybrid = 1 AND lsim.id IS NULL THEN 'orphaned'
        WHEN lsim.id IS NOT NULL AND lsim.imsi = bs.imsi THEN 'exists_in_hybrid'
        ELSE 'pending'
    END AS mapping_status
FROM bluecard_sims bs
LEFT JOIN lte_sims lsim ON bs.hybrid_sim_id = lsim.id OR bs.imsi = lsim.imsi;

-- View: BlueCard services with mapping status
CREATE VIEW IF NOT EXISTS v_bluecard_service_mapping AS
SELECT 
    bsvc.id AS bluecard_id,
    bsvc.user_id AS bluecard_user_id,
    bsvc.plan_name AS bluecard_plan,
    bsvc.price AS bluecard_price,
    bsvc.expiry_date AS bluecard_expiry,
    bsvc.status AS bluecard_status,
    bsvc.mapped_to_hybrid,
    bsvc.hybrid_subscription_id,
    lsub.id AS hybrid_id,
    lsub.package_name AS hybrid_package,
    lsub.amount_paid AS hybrid_price,
    lsub.expires_at AS hybrid_expiry,
    lsub.status AS hybrid_status,
    CASE 
        WHEN bsvc.mapped_to_hybrid = 1 AND lsub.id IS NOT NULL THEN 'mapped'
        WHEN bsvc.mapped_to_hybrid = 1 AND lsub.id IS NULL THEN 'orphaned'
        ELSE 'pending'
    END AS mapping_status
FROM bluecard_services bsvc
LEFT JOIN lte_subscriptions lsub ON bsvc.hybrid_subscription_id = lsub.id;

-- View: BlueCard plans vs Hybrid packages
CREATE VIEW IF NOT EXISTS v_bluecard_plan_mapping AS
SELECT 
    bp.id AS bluecard_id,
    bp.name AS bluecard_name,
    bp.price AS bluecard_price,
    bp.type AS bluecard_type,
    bp.days AS bluecard_days,
    bp.magma_profile AS bluecard_profile,
    bp.mapped_to_hybrid,
    bp.hybrid_package_id,
    lp.id AS hybrid_id,
    lp.name AS hybrid_name,
    lp.price AS hybrid_price,
    lp.type AS hybrid_type,
    lp.days AS hybrid_days,
    lp.magma_profile AS hybrid_profile,
    CASE 
        WHEN bp.mapped_to_hybrid = 1 AND lp.id IS NOT NULL THEN 'mapped'
        WHEN bp.mapped_to_hybrid = 1 AND lp.id IS NULL THEN 'orphaned'
        WHEN lp.id IS NOT NULL AND lp.name = bp.name THEN 'auto_matched'
        ELSE 'pending'
    END AS mapping_status
FROM bluecard_plans bp
LEFT JOIN lte_packages lp ON bp.hybrid_package_id = lp.id OR bp.name = lp.name;

-- ══════════════════════════════════════════════════════════════════════════════
-- SUMMARY VIEWS
-- ══════════════════════════════════════════════════════════════════════════════

-- View: Migration summary statistics
CREATE VIEW IF NOT EXISTS v_migration_summary AS
SELECT 
    'users' AS entity,
    (SELECT COUNT(*) FROM bluecard_users) AS bluecard_count,
    (SELECT COUNT(*) FROM bluecard_users WHERE mapped_to_hybrid = 1) AS mapped_count,
    (SELECT COUNT(*) FROM bluecard_users WHERE mapped_to_hybrid = 0) AS pending_count,
    (SELECT COUNT(*) FROM lte_subscribers) AS hybrid_count
UNION ALL
SELECT 
    'sims' AS entity,
    (SELECT COUNT(*) FROM bluecard_sims) AS bluecard_count,
    (SELECT COUNT(*) FROM bluecard_sims WHERE mapped_to_hybrid = 1) AS mapped_count,
    (SELECT COUNT(*) FROM bluecard_sims WHERE mapped_to_hybrid = 0) AS pending_count,
    (SELECT COUNT(*) FROM lte_sims) AS hybrid_count
UNION ALL
SELECT 
    'services' AS entity,
    (SELECT COUNT(*) FROM bluecard_services) AS bluecard_count,
    (SELECT COUNT(*) FROM bluecard_services WHERE mapped_to_hybrid = 1) AS mapped_count,
    (SELECT COUNT(*) FROM bluecard_services WHERE mapped_to_hybrid = 0) AS pending_count,
    (SELECT COUNT(*) FROM lte_subscriptions) AS hybrid_count
UNION ALL
SELECT 
    'plans' AS entity,
    (SELECT COUNT(*) FROM bluecard_plans) AS bluecard_count,
    (SELECT COUNT(*) FROM bluecard_plans WHERE mapped_to_hybrid = 1) AS mapped_count,
    (SELECT COUNT(*) FROM bluecard_plans WHERE mapped_to_hybrid = 0) AS pending_count,
    (SELECT COUNT(*) FROM lte_packages) AS hybrid_count
UNION ALL
SELECT 
    'topups' AS entity,
    (SELECT COUNT(*) FROM bluecard_topups) AS bluecard_count,
    (SELECT COUNT(*) FROM bluecard_topups WHERE mapped_to_hybrid = 1) AS mapped_count,
    (SELECT COUNT(*) FROM bluecard_topups WHERE mapped_to_hybrid = 0) AS pending_count,
    (SELECT COUNT(*) FROM lte_renewals) AS hybrid_count;

-- ══════════════════════════════════════════════════════════════════════════════
-- IMPORT METADATA
-- ══════════════════════════════════════════════════════════════════════════════

-- Track import batches
CREATE TABLE IF NOT EXISTS bluecard_import_log (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    filename            TEXT,
    file_size           INTEGER,
    import_type         TEXT,                   -- 'full', 'partial', 'incremental'
    started_at          TEXT,
    completed_at        TEXT,
    status              TEXT DEFAULT 'pending', -- pending, running, completed, failed
    users_imported      INTEGER DEFAULT 0,
    sims_imported       INTEGER DEFAULT 0,
    services_imported   INTEGER DEFAULT 0,
    plans_imported      INTEGER DEFAULT 0,
    topups_imported     INTEGER DEFAULT 0,
    usage_imported      INTEGER DEFAULT 0,
    errors              TEXT,                   -- JSON array of errors
    notes               TEXT
);

CREATE INDEX IF NOT EXISTS idx_bc_import_status ON bluecard_import_log(status);
CREATE INDEX IF NOT EXISTS idx_bc_import_date ON bluecard_import_log(started_at);
