-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 060 — Overdue Workbench (v4.21.66)
--
-- Adds the human-side counterpart to the existing automated dunning chain
-- (cron_overdue_email.php → overdue_email_log).
--
-- The auto-system sends emails/WhatsApp on a schedule. The workbench is what
-- Aida / Bidal / Rupesh use to actually CALL customers, log promises to pay,
-- assign field collectors, and mark cases that need different handling.
--
-- Each row is keyed by invoice_number (one workbench note per invoice). The
-- table is purely additive — overdue_email_log, sl_suspension_state, cb_ledger,
-- and all UCRM caches remain untouched.
--
-- Schema design notes:
--   * invoice_number is TEXT (UCRM uses INV013187 etc.)
--   * status is the workbench status (NOT the UCRM invoice status)
--   * last_action_at lets us sort "stale" rows to the top of the queue
--   * promised_pay_date lets us filter "promises broken today"
--   * assigned_to is a free-text retailer name (matches retailers.json convention)
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS overdue_workbench (
    invoice_number    TEXT PRIMARY KEY,
    client_id         INTEGER NOT NULL DEFAULT 0,
    client_name       TEXT NOT NULL DEFAULT '',
    amount_due        REAL NOT NULL DEFAULT 0,
    days_overdue      INTEGER NOT NULL DEFAULT 0,

    -- Workbench status. NOT the UCRM invoice status.
    -- 'open'           = needs attention, default for any overdue invoice
    -- 'promised'       = customer promised to pay by promised_pay_date
    -- 'in_field'       = assigned to field collector for in-person visit
    -- 'disputed'       = customer disputes the invoice, finance team to resolve
    -- 'unreachable'    = phone disconnected / WA bounces / email bounces
    -- 'write_off_req'  = recommended for write-off, awaiting CEO approval
    -- 'paused_followup' = paused (e.g. ramadan, sick, travelling) — resume on resume_at
    status            TEXT NOT NULL DEFAULT 'open',

    -- Promise & assignment
    promised_pay_date TEXT,           -- ISO date 'YYYY-MM-DD' or NULL
    promised_amount   REAL,           -- partial promise allowed
    assigned_to       TEXT,           -- retailer name (matches retailers.json)
    pause_until       TEXT,           -- ISO date — for paused_followup status

    -- Free-text trail (last note shown in list view; full history in audit log)
    last_note         TEXT,
    last_action_by    TEXT,           -- retailer name who took last action
    last_action_at    TEXT,           -- ISO datetime

    -- Touch counters (helps spot abandoned cases)
    contact_attempts  INTEGER NOT NULL DEFAULT 0,
    last_contact_at   TEXT,

    -- Lifecycle
    created_at        TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT NOT NULL DEFAULT (datetime('now')),

    -- Once paid, we keep the row for history but mark it closed
    closed_at         TEXT,
    close_reason      TEXT             -- 'paid' | 'written_off' | 'cancelled' | 'merged'
);

CREATE INDEX IF NOT EXISTS idx_owb_status        ON overdue_workbench(status);
CREATE INDEX IF NOT EXISTS idx_owb_assigned      ON overdue_workbench(assigned_to);
CREATE INDEX IF NOT EXISTS idx_owb_promised      ON overdue_workbench(promised_pay_date);
CREATE INDEX IF NOT EXISTS idx_owb_last_action   ON overdue_workbench(last_action_at);
CREATE INDEX IF NOT EXISTS idx_owb_client        ON overdue_workbench(client_id);

-- Audit log — every action taken on the workbench is recorded here.
-- No deletes, ever — this is the forensic trail.
CREATE TABLE IF NOT EXISTS overdue_workbench_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_number  TEXT NOT NULL,
    client_id       INTEGER NOT NULL DEFAULT 0,
    action          TEXT NOT NULL,        -- 'note' | 'promise' | 'assign' | 'status' | 'contact' | 'bulk_assign' | 'bulk_status'
    detail          TEXT,                 -- free text
    old_value       TEXT,                 -- previous value (for status/assignment changes)
    new_value       TEXT,                 -- new value
    by_retailer     TEXT NOT NULL DEFAULT '',
    by_retailer_id  INTEGER,
    at_iso          TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_owbl_invoice ON overdue_workbench_log(invoice_number, at_iso DESC);
CREATE INDEX IF NOT EXISTS idx_owbl_client  ON overdue_workbench_log(client_id, at_iso DESC);
CREATE INDEX IF NOT EXISTS idx_owbl_at      ON overdue_workbench_log(at_iso DESC);
