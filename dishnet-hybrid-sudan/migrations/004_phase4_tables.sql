-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 004: Phase 4 tables — Installations, Engineers, Wallet Transactions
-- DishNet Hybrid v4.0
--
-- Purpose:
--   1. installations: Full 7-step install workflow with GPS, ONU, signal, photos
--   2. engineers: Denormalized engineer profile for fast assignment queries
--   3. wallet_transactions: Normalized passbook replacing passbook.json
--   4. _rate_limits: API rate limiting state
--
-- All tables use IF NOT EXISTS — safe to re-run.
-- ══════════════════════════════════════════════════════════════════════════════

-- ── 1. Installations ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS installations (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id               INTEGER NOT NULL,           -- Links to tickets.id
    app_id                  INTEGER DEFAULT 0,          -- KYC application ID

    -- ── Customer ──────────────────────────────────────────────────────────
    customer_id             INTEGER,
    customer_name           TEXT DEFAULT '',
    address                 TEXT DEFAULT '',
    area                    TEXT DEFAULT 'Unknown',
    phone                   TEXT DEFAULT '',

    -- ── Assignment ────────────────────────────────────────────────────────
    engineer_id             INTEGER,
    engineer_name           TEXT DEFAULT '',
    assigned_at             TEXT,
    accepted_at             TEXT,

    -- ── Step 1: GPS validation ────────────────────────────────────────────
    gps_lat                 REAL,
    gps_lng                 REAL,
    gps_valid               INTEGER DEFAULT 0,
    gps_submitted_at        TEXT,

    -- ── Step 2: ONU + signal ──────────────────────────────────────────────
    onu_serial              TEXT DEFAULT '',
    olt_port                TEXT DEFAULT '',
    signal_db               REAL,
    signal_valid            INTEGER DEFAULT 0,          -- -8 to -28 dBm
    onu_submitted_at        TEXT,

    -- ── Step 3: Speed test ────────────────────────────────────────────────
    speed_down_mbps         REAL,
    speed_up_mbps           REAL,
    speed_test_at           TEXT,

    -- ── Step 4: Photos ────────────────────────────────────────────────────
    photos                  TEXT DEFAULT '[]',           -- JSON array
    photo_count             INTEGER DEFAULT 0,

    -- ── Step 5: Notes ─────────────────────────────────────────────────────
    install_notes           TEXT DEFAULT '',

    -- ── Step 6: Customer signature ────────────────────────────────────────
    signature_data          TEXT,                        -- base64 PNG of signature
    signature_name          TEXT DEFAULT '',
    signed_at               TEXT,

    -- ── Step 7: Completion ────────────────────────────────────────────────
    status                  TEXT DEFAULT 'assigned',     -- assigned,accepted,in_progress,ready,commissioned,rejected,cancelled
    completed_at            TEXT,
    commissioned_by         TEXT DEFAULT '',
    commission_notes        TEXT DEFAULT '',
    rejected_by             TEXT DEFAULT '',
    rejection_reason        TEXT DEFAULT '',
    rejected_at             TEXT,

    -- ── Splynx service activation ─────────────────────────────────────────
    splynx_service_id       INTEGER,
    service_activated       INTEGER DEFAULT 0,
    service_activated_at    TEXT,

    -- ── Metadata ──────────────────────────────────────────────────────────
    created_at              TEXT DEFAULT (datetime('now')),
    updated_at              TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_install_ticket ON installations(ticket_id);
CREATE INDEX IF NOT EXISTS idx_install_engineer ON installations(engineer_id) WHERE status NOT IN ('commissioned','cancelled');
CREATE INDEX IF NOT EXISTS idx_install_status ON installations(status) WHERE status NOT IN ('commissioned','cancelled');
CREATE INDEX IF NOT EXISTS idx_install_area ON installations(area) WHERE status NOT IN ('commissioned','cancelled');

-- ── 2. Engineers (denormalized from retailers for fast queries) ──────────
CREATE TABLE IF NOT EXISTS engineers (
    id                      INTEGER PRIMARY KEY,        -- Same as retailers.id
    name                    TEXT NOT NULL,
    phone                   TEXT DEFAULT '',
    email                   TEXT DEFAULT '',
    role                    TEXT DEFAULT 'support',
    is_active               INTEGER DEFAULT 1,
    on_leave                INTEGER DEFAULT 0,

    -- ── Performance metrics ───────────────────────────────────────────────
    total_installs          INTEGER DEFAULT 0,
    active_jobs             INTEGER DEFAULT 0,
    avg_completion_hours    REAL,
    last_job_at             TEXT,

    -- ── GPS tracking ─────────────────────────────────────────────────────
    last_lat                REAL,
    last_lng                REAL,
    last_gps_at             TEXT,

    synced_at               TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_eng_active ON engineers(is_active) WHERE is_active = 1 AND on_leave = 0;

-- ── 3. Wallet Transactions ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    retailer_id             INTEGER NOT NULL,
    type                    TEXT NOT NULL,               -- credit, debit
    amount                  REAL NOT NULL,
    curr_balance            REAL NOT NULL,
    description             TEXT DEFAULT '',
    reference               TEXT DEFAULT '',
    category                TEXT DEFAULT '',             -- topup, commission, order_payment, reversal, etc.
    crm_customer_id         TEXT DEFAULT '',
    idempotency_key         TEXT,
    created_by              TEXT DEFAULT '',
    created_at              TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_wtx_retailer ON wallet_transactions(retailer_id, created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wtx_idem ON wallet_transactions(idempotency_key) WHERE idempotency_key IS NOT NULL AND idempotency_key != '';

-- ── 4. Rate limits (API v2) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS _rate_limits (
    key                     TEXT PRIMARY KEY,
    count                   INTEGER DEFAULT 0,
    window_start            INTEGER
);
