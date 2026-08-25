-- Migration 053: BlueCard Local Sync Tables
-- Agents, Passbooks, Load Money synced from BlueCard to local SQLite
-- Enables fully local BlueCard portal queries (no live HTTP calls for reads)

-- ── Agents / Dealers / Retailers ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lte_agents (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    bluecard_id     INTEGER UNIQUE NOT NULL,    -- users.id in BlueCard
    firstname       TEXT    DEFAULT '',
    lastname        TEXT    DEFAULT '',
    name            TEXT    DEFAULT '',          -- firstname + lastname cached
    mobile          TEXT    DEFAULT NULL,
    email           TEXT    DEFAULT NULL,
    role_name       TEXT    DEFAULT 'retailer',  -- retailer, dealer, franchisee, admin
    role_display    TEXT    DEFAULT NULL,
    master_id       INTEGER DEFAULT NULL,        -- bluecard_id of parent agent
    master_name     TEXT    DEFAULT NULL,
    wallet          REAL    DEFAULT 0,
    lm_commission   REAL    DEFAULT 0,           -- load_money_commission %
    is_active       INTEGER DEFAULT 1,
    network         TEXT    DEFAULT 'dishnet_4g',
    created_at      TEXT    DEFAULT NULL,
    updated_at      TEXT    DEFAULT NULL,
    synced_at       TEXT    DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_lte_agents_mobile ON lte_agents(mobile);
CREATE INDEX IF NOT EXISTS idx_lte_agents_role   ON lte_agents(role_name);
CREATE INDEX IF NOT EXISTS idx_lte_agents_master ON lte_agents(master_id);

-- ── Passbooks (agent + customer transaction ledger) ───────────────────────
CREATE TABLE IF NOT EXISTS lte_passbooks (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    bluecard_id     INTEGER UNIQUE NOT NULL,    -- passbooks.id in BlueCard
    bluecard_user_id INTEGER DEFAULT NULL,
    entry_type      TEXT    DEFAULT NULL,        -- Credit, Debit
    amount          REAL    DEFAULT 0,
    prev_balance    REAL    DEFAULT 0,
    curr_balance    REAL    DEFAULT 0,
    trx_no          TEXT    DEFAULT NULL,
    description     TEXT    DEFAULT NULL,
    network         TEXT    DEFAULT 'dishnet_4g',
    created_at      TEXT    DEFAULT NULL,
    synced_at       TEXT    DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_lte_pb_user    ON lte_passbooks(bluecard_user_id);
CREATE INDEX IF NOT EXISTS idx_lte_pb_type    ON lte_passbooks(entry_type);
CREATE INDEX IF NOT EXISTS idx_lte_pb_created ON lte_passbooks(created_at);

-- ── Load Money (wallet top-up requests) ──────────────────────────────────
CREATE TABLE IF NOT EXISTS lte_load_money (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    bluecard_id     INTEGER UNIQUE NOT NULL,    -- load_money.id in BlueCard
    bluecard_user_id INTEGER DEFAULT NULL,       -- the agent receiving money
    master_user_id  INTEGER DEFAULT NULL,        -- the dealer/admin giving money
    amount          REAL    DEFAULT 0,           -- requested amount (dollars)
    approve_amount  REAL    DEFAULT NULL,        -- actually approved amount
    commission      REAL    DEFAULT 0,
    status          TEXT    DEFAULT 'Pending',   -- Pending, Approve, Rejected
    lm_type         TEXT    DEFAULT NULL,
    network         TEXT    DEFAULT 'dishnet_4g',
    created_at      TEXT    DEFAULT NULL,
    updated_at      TEXT    DEFAULT NULL,
    synced_at       TEXT    DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_lte_lm_user    ON lte_load_money(bluecard_user_id);
CREATE INDEX IF NOT EXISTS idx_lte_lm_master  ON lte_load_money(master_user_id);
CREATE INDEX IF NOT EXISTS idx_lte_lm_status  ON lte_load_money(status);
CREATE INDEX IF NOT EXISTS idx_lte_lm_created ON lte_load_money(created_at);
