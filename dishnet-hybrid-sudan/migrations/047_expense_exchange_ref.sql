-- Migration 047: Link staff_expenses to exchange batch
-- Phase 2 of SSP accounting — batch reconciliation
-- Allows an expense to record which exchange (EXCH-xxx) funded its SSP
ALTER TABLE staff_expenses ADD COLUMN exchange_ref TEXT NOT NULL DEFAULT '';
ALTER TABLE staff_expenses ADD COLUMN exchange_rate REAL;

-- Index for batch lookup
CREATE INDEX IF NOT EXISTS idx_exp_exchange_ref ON staff_expenses(exchange_ref)
  WHERE exchange_ref != '';
