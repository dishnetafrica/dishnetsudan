-- ═══════════════════════════════════════════════════════════════════
-- 041: HRM Module — employee profiles, payroll, leave management
-- v4.11.0 — Phase 1A
-- ═══════════════════════════════════════════════════════════════════

-- ── 1. Employee HR profiles (extends retailers.json) ────────────────
CREATE TABLE IF NOT EXISTS hrm_employees (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    retailer_id     INTEGER NOT NULL UNIQUE,
    employee_code   TEXT DEFAULT '',
    designation     TEXT DEFAULT '',
    department      TEXT DEFAULT 'operations',
    employment_type TEXT DEFAULT 'full_time',
    join_date       TEXT,
    contract_end    TEXT,
    probation_end   TEXT,
    bank_name       TEXT DEFAULT '',
    bank_account    TEXT DEFAULT '',
    bank_branch     TEXT DEFAULT '',
    mobile_money_no TEXT DEFAULT '',
    emergency_name  TEXT DEFAULT '',
    emergency_phone TEXT DEFAULT '',
    emergency_rel   TEXT DEFAULT '',
    id_type         TEXT DEFAULT '',
    id_number       TEXT DEFAULT '',
    nationality     TEXT DEFAULT 'South Sudanese',
    status          TEXT DEFAULT 'active',
    termination_date TEXT,
    termination_reason TEXT,
    notes           TEXT DEFAULT '',
    created_at      TEXT DEFAULT (datetime('now')),
    updated_at      TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_hrm_emp_status ON hrm_employees(status);
CREATE INDEX IF NOT EXISTS idx_hrm_emp_dept   ON hrm_employees(department);

-- ── 2. Salary structure per employee (components) ───────────────────
CREATE TABLE IF NOT EXISTS hrm_salary_structures (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    retailer_id     INTEGER NOT NULL,
    component       TEXT NOT NULL,
    label           TEXT NOT NULL,
    amount          REAL NOT NULL DEFAULT 0,
    currency        TEXT DEFAULT 'USD',
    is_active       INTEGER DEFAULT 1,
    effective_from  TEXT NOT NULL,
    effective_to    TEXT,
    notes           TEXT DEFAULT '',
    created_at      TEXT DEFAULT (datetime('now')),
    updated_at      TEXT DEFAULT (datetime('now')),
    UNIQUE(retailer_id, component, effective_from)
);
CREATE INDEX IF NOT EXISTS idx_hrm_sal_rid ON hrm_salary_structures(retailer_id);

-- ── 3. Payroll periods (monthly cycles) ─────────────────────────────
CREATE TABLE IF NOT EXISTS hrm_payroll_periods (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    period          TEXT NOT NULL UNIQUE,
    period_start    TEXT NOT NULL,
    period_end      TEXT NOT NULL,
    status          TEXT DEFAULT 'draft',
    calculated_at   TEXT,
    calculated_by   TEXT,
    approved_at     TEXT,
    approved_by     TEXT,
    closed_at       TEXT,
    total_gross     REAL DEFAULT 0,
    total_deductions REAL DEFAULT 0,
    total_net       REAL DEFAULT 0,
    total_disbursed REAL DEFAULT 0,
    employee_count  INTEGER DEFAULT 0,
    notes           TEXT DEFAULT '',
    created_at      TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_hrm_pp_status ON hrm_payroll_periods(status);

-- ── 4. Payroll lines (per employee per period) ──────────────────────
CREATE TABLE IF NOT EXISTS hrm_payroll_lines (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    period_id       INTEGER NOT NULL,
    retailer_id     INTEGER NOT NULL,
    employee_name   TEXT NOT NULL,
    employee_code   TEXT DEFAULT '',
    base_salary     REAL DEFAULT 0,
    transport       REAL DEFAULT 0,
    food            REAL DEFAULT 0,
    housing         REAL DEFAULT 0,
    bonus           REAL DEFAULT 0,
    other_earnings  REAL DEFAULT 0,
    gross_pay       REAL DEFAULT 0,
    advance_deduct  REAL DEFAULT 0,
    loan_deduct     REAL DEFAULT 0,
    penalty_deduct  REAL DEFAULT 0,
    leave_deduct    REAL DEFAULT 0,
    other_deduct    REAL DEFAULT 0,
    total_deductions REAL DEFAULT 0,
    net_pay         REAL DEFAULT 0,
    currency        TEXT DEFAULT 'USD',
    total_disbursed REAL DEFAULT 0,
    balance_due     REAL DEFAULT 0,
    disbursement_status TEXT DEFAULT 'pending',
    days_present    INTEGER DEFAULT 0,
    days_absent     INTEGER DEFAULT 0,
    days_leave      INTEGER DEFAULT 0,
    notes           TEXT DEFAULT '',
    created_at      TEXT DEFAULT (datetime('now')),
    updated_at      TEXT DEFAULT (datetime('now')),
    UNIQUE(period_id, retailer_id)
);
CREATE INDEX IF NOT EXISTS idx_hrm_pl_period ON hrm_payroll_lines(period_id);
CREATE INDEX IF NOT EXISTS idx_hrm_pl_rid    ON hrm_payroll_lines(retailer_id);
CREATE INDEX IF NOT EXISTS idx_hrm_pl_dstatus ON hrm_payroll_lines(disbursement_status);

-- ── 5. Disbursements (individual salary payments, supports partial) ──
CREATE TABLE IF NOT EXISTS hrm_disbursements (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    payroll_line_id INTEGER NOT NULL,
    period_id       INTEGER NOT NULL,
    retailer_id     INTEGER NOT NULL,
    employee_name   TEXT NOT NULL,
    amount          REAL NOT NULL,
    currency        TEXT DEFAULT 'USD',
    component       TEXT DEFAULT 'salary',
    payment_type    TEXT DEFAULT 'salary',
    payment_method  TEXT DEFAULT 'cash',
    description     TEXT DEFAULT '',
    cb_sr           TEXT DEFAULT '',
    cb_posted       INTEGER DEFAULT 0,
    cb_posted_at    TEXT,
    paid_by_id      INTEGER,
    paid_by_name    TEXT DEFAULT '',
    voucher_ref     TEXT DEFAULT '',
    payslip_sent    INTEGER DEFAULT 0,
    payslip_sent_at TEXT,
    status          TEXT DEFAULT 'paid',
    approved_by     TEXT,
    approved_at     TEXT,
    paid_at         TEXT DEFAULT (datetime('now')),
    created_at      TEXT DEFAULT (datetime('now')),
    updated_at      TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_hrm_disb_plid   ON hrm_disbursements(payroll_line_id);
CREATE INDEX IF NOT EXISTS idx_hrm_disb_period ON hrm_disbursements(period_id);
CREATE INDEX IF NOT EXISTS idx_hrm_disb_rid    ON hrm_disbursements(retailer_id);
CREATE INDEX IF NOT EXISTS idx_hrm_disb_cbsr   ON hrm_disbursements(cb_sr);

-- ── 6. Leave types (configurable) ───────────────────────────────────
CREATE TABLE IF NOT EXISTS hrm_leave_types (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL UNIQUE,
    code            TEXT NOT NULL UNIQUE,
    days_per_year   INTEGER DEFAULT 0,
    is_paid         INTEGER DEFAULT 1,
    requires_doc    INTEGER DEFAULT 0,
    carry_forward   INTEGER DEFAULT 0,
    max_carry       INTEGER DEFAULT 0,
    is_active       INTEGER DEFAULT 1,
    color           TEXT DEFAULT '#3B82F6',
    created_at      TEXT DEFAULT (datetime('now'))
);

-- Default leave types (South Sudan standard)
INSERT OR IGNORE INTO hrm_leave_types (name, code, days_per_year, is_paid, requires_doc, carry_forward, max_carry, color) VALUES
    ('Annual Leave',        'AL', 21, 1, 0, 1, 5,  '#3B82F6'),
    ('Sick Leave',          'SL', 14, 1, 1, 0, 0,  '#EF4444'),
    ('Emergency Leave',     'EL',  5, 1, 0, 0, 0,  '#F59E0B'),
    ('Maternity Leave',     'ML', 90, 1, 0, 0, 0,  '#EC4899'),
    ('Paternity Leave',     'PL', 14, 1, 0, 0, 0,  '#8B5CF6'),
    ('Unpaid Leave',        'UL',  0, 0, 0, 0, 0,  '#6B7280'),
    ('Compassionate Leave', 'CL',  5, 1, 0, 0, 0,  '#14B8A6');

-- ── 7. Leave balances (per employee per year) ───────────────────────
CREATE TABLE IF NOT EXISTS hrm_leave_balances (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    retailer_id     INTEGER NOT NULL,
    leave_type_id   INTEGER NOT NULL,
    year            INTEGER NOT NULL,
    entitlement     INTEGER DEFAULT 0,
    carried         INTEGER DEFAULT 0,
    taken           INTEGER DEFAULT 0,
    pending         INTEGER DEFAULT 0,
    available       INTEGER DEFAULT 0,
    updated_at      TEXT DEFAULT (datetime('now')),
    UNIQUE(retailer_id, leave_type_id, year)
);
CREATE INDEX IF NOT EXISTS idx_hrm_lb_rid ON hrm_leave_balances(retailer_id);

-- ── 8. Leave requests ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hrm_leave_requests (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    retailer_id     INTEGER NOT NULL,
    employee_name   TEXT NOT NULL,
    leave_type_id   INTEGER NOT NULL,
    leave_type_name TEXT NOT NULL,
    start_date      TEXT NOT NULL,
    end_date        TEXT NOT NULL,
    days            INTEGER NOT NULL,
    reason          TEXT DEFAULT '',
    document_path   TEXT DEFAULT '',
    status          TEXT DEFAULT 'pending',
    approved_by     TEXT,
    approved_at     TEXT,
    rejection_reason TEXT,
    created_at      TEXT DEFAULT (datetime('now')),
    updated_at      TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_hrm_lr_rid    ON hrm_leave_requests(retailer_id);
CREATE INDEX IF NOT EXISTS idx_hrm_lr_status ON hrm_leave_requests(status);
CREATE INDEX IF NOT EXISTS idx_hrm_lr_dates  ON hrm_leave_requests(start_date, end_date);

-- ── 9. Add payroll_ref to cb_ledger ─────────────────────────────────
ALTER TABLE cb_ledger ADD COLUMN payroll_ref TEXT DEFAULT '';
