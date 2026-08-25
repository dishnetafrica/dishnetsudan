-- ═══════════════════════════════════════════════════════════════════
-- 042: HRM BookKeeper History — imported employee financial records
-- v4.11.0 — One-time seed from BookKeeper/OMEGA backup
-- ═══════════════════════════════════════════════════════════════════

-- All employee-related vouchers from BookKeeper (2019-2025)
CREATE TABLE IF NOT EXISTS hrm_financial_history (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    retailer_id     INTEGER,                          -- matched HRM employee (NULL if unmatched)
    employee_name   TEXT NOT NULL,                     -- original name from BookKeeper
    bk_account      TEXT NOT NULL DEFAULT '',          -- BookKeeper account name
    category        TEXT NOT NULL,                     -- salary, food, transport, commission, bonus, benefit, loan_given, loan_repaid, other
    txn_date        TEXT NOT NULL,
    amount          REAL NOT NULL DEFAULT 0,
    currency        TEXT DEFAULT 'USD',
    direction       TEXT NOT NULL DEFAULT 'paid',      -- paid (company→employee) or received (employee→company)
    narration       TEXT DEFAULT '',
    voucher_no      TEXT DEFAULT '',
    voucher_type    TEXT DEFAULT '',                   -- Payment, Receipt, Journal, etc.
    bk_debit_acct   TEXT DEFAULT '',
    bk_credit_acct  TEXT DEFAULT '',
    source          TEXT DEFAULT 'bookkeeper',
    imported_at     TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_hrm_fh_rid      ON hrm_financial_history(retailer_id);
CREATE INDEX IF NOT EXISTS idx_hrm_fh_name     ON hrm_financial_history(employee_name);
CREATE INDEX IF NOT EXISTS idx_hrm_fh_cat      ON hrm_financial_history(category);
CREATE INDEX IF NOT EXISTS idx_hrm_fh_date     ON hrm_financial_history(txn_date);
CREATE INDEX IF NOT EXISTS idx_hrm_fh_source   ON hrm_financial_history(source);

-- Computed loan summary per employee (refreshed on import)
CREATE TABLE IF NOT EXISTS hrm_loan_summary (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    retailer_id     INTEGER,
    employee_name   TEXT NOT NULL,
    total_given     REAL DEFAULT 0,
    total_repaid    REAL DEFAULT 0,
    balance         REAL DEFAULT 0,
    currency        TEXT DEFAULT 'USD',
    last_txn_date   TEXT,
    updated_at      TEXT DEFAULT (datetime('now')),
    UNIQUE(employee_name, currency)
);
CREATE INDEX IF NOT EXISTS idx_hrm_ls_rid ON hrm_loan_summary(retailer_id);
