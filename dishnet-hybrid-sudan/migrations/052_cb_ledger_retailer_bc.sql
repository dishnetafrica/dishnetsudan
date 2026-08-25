-- Migration 052: Add retailer_bc_id and status columns to cb_ledger
-- Needed for BlueCard Retailer Outstanding tracking
-- retailer_bc_id: BlueCard user_id of the retailer who collected the cash
-- status: 'approved' | 'voided' for filtering valid collections

ALTER TABLE cb_ledger ADD COLUMN retailer_bc_id INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cb_ledger ADD COLUMN status TEXT NOT NULL DEFAULT 'approved';

CREATE INDEX IF NOT EXISTS idx_cb_ledger_retailer ON cb_ledger(retailer_bc_id, project, direction);
