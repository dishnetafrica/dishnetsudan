-- Migration 043: Unify expenses into single staff_expenses table
-- Phase 3 of accounting consolidation (v4.11.3)
-- Adds columns needed for field expenses (previously in cash_expenses.json)

-- Source tracking: 'field' = from Field Register, 'advance' = from ExpenseAdvanceService
ALTER TABLE staff_expenses ADD COLUMN source TEXT NOT NULL DEFAULT 'advance';

-- Field expense columns
ALTER TABLE staff_expenses ADD COLUMN ssp_amount REAL NOT NULL DEFAULT 0;
ALTER TABLE staff_expenses ADD COLUMN is_staff_payment INTEGER NOT NULL DEFAULT 0;
ALTER TABLE staff_expenses ADD COLUMN auto_approved INTEGER NOT NULL DEFAULT 0;
ALTER TABLE staff_expenses ADD COLUMN approved_by TEXT NOT NULL DEFAULT '';
ALTER TABLE staff_expenses ADD COLUMN approved_at TEXT;
ALTER TABLE staff_expenses ADD COLUMN voided_by TEXT NOT NULL DEFAULT '';
ALTER TABLE staff_expenses ADD COLUMN voided_at TEXT;
ALTER TABLE staff_expenses ADD COLUMN void_reason TEXT NOT NULL DEFAULT '';
ALTER TABLE staff_expenses ADD COLUMN prev_status TEXT NOT NULL DEFAULT '';
ALTER TABLE staff_expenses ADD COLUMN edited_by TEXT NOT NULL DEFAULT '';
ALTER TABLE staff_expenses ADD COLUMN edited_at TEXT;

-- Staff payment details
ALTER TABLE staff_expenses ADD COLUMN staff_payment_to_id INTEGER NOT NULL DEFAULT 0;
ALTER TABLE staff_expenses ADD COLUMN staff_payment_to_name TEXT NOT NULL DEFAULT '';
ALTER TABLE staff_expenses ADD COLUMN staff_payment_type TEXT NOT NULL DEFAULT '';

-- Cashbook link for auto-approved entries
ALTER TABLE staff_expenses ADD COLUMN cb_ref TEXT NOT NULL DEFAULT '';

-- Legacy ID from cash_expenses.json (for dedup during migration)
ALTER TABLE staff_expenses ADD COLUMN legacy_json_id INTEGER NOT NULL DEFAULT 0;

-- Index for source filtering
CREATE INDEX IF NOT EXISTS idx_exp_source ON staff_expenses(source);
CREATE INDEX IF NOT EXISTS idx_exp_legacy ON staff_expenses(legacy_json_id) WHERE legacy_json_id > 0;
