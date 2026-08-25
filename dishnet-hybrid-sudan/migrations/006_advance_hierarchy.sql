-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 006: Cash Advance Hierarchy + Staff Cashbook Allocated Column
-- DishNet Hybrid v4.4.18
--
-- Fixes the double-posting bug: child advances (staff-to-staff transfers)
-- must NOT create cb_ledger entries. Only root advances (parent_advance_id IS NULL)
-- represent real company money leaving the business.
--
-- Changes:
--   cash_advances:
--     + parent_advance_id  INT NULL  — links child → parent advance
--     + root_advance_id    INT NOT NULL DEFAULT 0
--                                    — always points to the top-level root advance
--                                    — equals own id for root advances
--     + children_allocated REAL NOT NULL DEFAULT 0
--                                    — running total of child advance amounts
--                                    — kept in sync by ExpenseAdvanceService
--
--   staff_cashbook_daily:
--     + advances_allocated REAL NOT NULL DEFAULT 0
--                                    — money this staff member gave to sub-staff
--                                    — closing_balance formula:
--                                      opening + advances_received
--                                      - advances_allocated
--                                      - expenses_approved
--                                      - cash_returned
--
-- All ADD COLUMN statements are idempotent via the migration runner's
-- schema_migrations tracking table (runs once, never again).
-- ══════════════════════════════════════════════════════════════════════════════

-- ── cash_advances hierarchy columns ─────────────────────────────────────────
ALTER TABLE cash_advances ADD COLUMN parent_advance_id  INTEGER;
ALTER TABLE cash_advances ADD COLUMN root_advance_id    INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cash_advances ADD COLUMN children_allocated REAL    NOT NULL DEFAULT 0;

-- Back-fill root_advance_id for all existing rows (they are all roots)
UPDATE cash_advances SET root_advance_id = id WHERE root_advance_id = 0;

-- Indexes for hierarchy queries
CREATE INDEX IF NOT EXISTS idx_adv_parent   ON cash_advances(parent_advance_id) WHERE parent_advance_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_adv_root     ON cash_advances(root_advance_id);

-- ── staff_cashbook_daily allocated column ────────────────────────────────────
ALTER TABLE staff_cashbook_daily ADD COLUMN advances_allocated REAL NOT NULL DEFAULT 0;
