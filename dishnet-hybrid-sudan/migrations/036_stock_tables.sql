-- ═══════════════════════════════════════════════════════════════════
-- 036: Stock Management — unified equipment tracking across all services
-- v4.10.0 — zero changes to existing tables
-- ═══════════════════════════════════════════════════════════════════

-- Product catalog: each row = a type of equipment
CREATE TABLE IF NOT EXISTS stock_categories (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT NOT NULL,
    sku             TEXT UNIQUE,
    service_type    TEXT NOT NULL DEFAULT 'general',
    track_mode      TEXT NOT NULL DEFAULT 'serial',
    buy_price       REAL DEFAULT 0,
    sell_price      REAL DEFAULT 0,
    min_stock       INTEGER DEFAULT 0,
    unit            TEXT DEFAULT 'piece',
    is_active       INTEGER DEFAULT 1,
    description     TEXT DEFAULT '',
    created_at      TEXT NOT NULL
);

-- Individual tracked items (serial-number level)
CREATE TABLE IF NOT EXISTS stock_units (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id     INTEGER NOT NULL REFERENCES stock_categories(id),
    serial_number   TEXT,
    secondary_serial TEXT DEFAULT '',
    status          TEXT NOT NULL DEFAULT 'in_stock',
    location_type   TEXT DEFAULT 'warehouse',
    location_ref    TEXT DEFAULT '',
    location_name   TEXT DEFAULT '',
    condition_grade TEXT DEFAULT 'new',
    purchase_ref    TEXT DEFAULT '',
    purchase_cost   REAL DEFAULT 0,
    crm_client_id   INTEGER,
    crm_service_id  INTEGER,
    job_id          INTEGER,
    assigned_to_rid INTEGER,
    notes           TEXT DEFAULT '',
    starlink_account TEXT DEFAULT '',
    starlink_status  TEXT DEFAULT '',
    lte_imsi        TEXT DEFAULT '',
    lte_msisdn      TEXT DEFAULT '',
    created_at      TEXT NOT NULL,
    updated_at      TEXT
);

CREATE INDEX IF NOT EXISTS idx_su_serial      ON stock_units(serial_number);
CREATE INDEX IF NOT EXISTS idx_su_cat_status   ON stock_units(category_id, status);
CREATE INDEX IF NOT EXISTS idx_su_assigned     ON stock_units(assigned_to_rid);
CREATE INDEX IF NOT EXISTS idx_su_crm_client   ON stock_units(crm_client_id);
CREATE INDEX IF NOT EXISTS idx_su_status       ON stock_units(status);

-- Bulk stock levels for consumables (track_mode='quantity')
CREATE TABLE IF NOT EXISTS stock_quantities (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id     INTEGER NOT NULL REFERENCES stock_categories(id),
    location_type   TEXT DEFAULT 'warehouse',
    location_ref    TEXT DEFAULT '',
    location_name   TEXT DEFAULT '',
    qty_on_hand     INTEGER NOT NULL DEFAULT 0,
    qty_reserved    INTEGER DEFAULT 0,
    updated_at      TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_sq_cat_loc ON stock_quantities(category_id, location_type, location_ref);

-- Immutable audit trail — every stock change = one row
CREATE TABLE IF NOT EXISTS stock_movements (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id     INTEGER NOT NULL REFERENCES stock_categories(id),
    unit_id         INTEGER,
    movement_type   TEXT NOT NULL,
    quantity         INTEGER DEFAULT 1,
    from_location_type TEXT DEFAULT '',
    from_location_ref  TEXT DEFAULT '',
    from_location_name TEXT DEFAULT '',
    to_location_type   TEXT NOT NULL DEFAULT '',
    to_location_ref    TEXT NOT NULL DEFAULT '',
    to_location_name   TEXT DEFAULT '',
    reference_type  TEXT DEFAULT '',
    reference_id    TEXT DEFAULT '',
    performed_by    INTEGER NOT NULL,
    performed_by_name TEXT DEFAULT '',
    note            TEXT DEFAULT '',
    photo_path      TEXT DEFAULT '',
    created_at      TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sm_cat_date    ON stock_movements(category_id, created_at);
CREATE INDEX IF NOT EXISTS idx_sm_type_date   ON stock_movements(movement_type, created_at);
CREATE INDEX IF NOT EXISTS idx_sm_performer   ON stock_movements(performed_by, created_at);
CREATE INDEX IF NOT EXISTS idx_sm_unit        ON stock_movements(unit_id);

-- Purchase orders / supplier receipts
CREATE TABLE IF NOT EXISTS stock_purchases (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    supplier        TEXT NOT NULL,
    invoice_number  TEXT DEFAULT '',
    purchase_date   TEXT NOT NULL,
    total_cost      REAL DEFAULT 0,
    currency        TEXT DEFAULT 'USD',
    ssp_rate        REAL,
    payment_method  TEXT DEFAULT 'cash',
    received_by     INTEGER,
    received_by_name TEXT DEFAULT '',
    status          TEXT DEFAULT 'received',
    notes           TEXT DEFAULT '',
    photo_path      TEXT DEFAULT '',
    cb_ledger_id    INTEGER,
    created_at      TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sp_date ON stock_purchases(purchase_date);
