-- Migration 010: staff_transfers (v4.4.26)
--
-- Models: Diko collects $200 → gives $80 cash to Bidal for field work
-- → Bidal spends it → Diko hands remaining $120 to Rupesh.
--
-- Auto-split (collections first, advance covers rest):
--   from_collections + from_advance = amount  (always)
--
-- Auto-approved: status is always 'approved' on INSERT.
-- Rupesh can void if something is wrong → reverses both exposures.
--
-- Phantom cash guards (enforced before INSERT in StaffTransferService):
--   Guard 1 — sender:   cash_exposure >= amount
--   Guard 2 — receiver: cash_exposure + amount <= carry_limit
--
-- Conservation: transfers_sent / transfers_received are zero-sum across
-- all agents. staff_cash_position VIEW v2 (migration 011) reads both.

CREATE TABLE IF NOT EXISTS staff_transfers (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    transfer_no      TEXT NOT NULL UNIQUE,          -- TRF-YYYYMM-NNN
    from_id          INTEGER NOT NULL,
    from_name        TEXT    NOT NULL DEFAULT '',
    to_id            INTEGER NOT NULL,
    to_name          TEXT    NOT NULL DEFAULT '',
    amount           REAL    NOT NULL CHECK(amount > 0),
    currency         TEXT    NOT NULL DEFAULT 'USD',
    -- Auto-split breakdown (label only — Option A)
    from_collections REAL    NOT NULL DEFAULT 0,   -- portion from collections
    from_advance     REAL    NOT NULL DEFAULT 0,   -- portion from advance
    purpose          TEXT    NOT NULL DEFAULT 'field_work',
    description      TEXT    NOT NULL DEFAULT '',
    status           TEXT    NOT NULL DEFAULT 'approved'
                             CHECK(status IN ('approved','voided')),
    -- Phantom-cash audit snapshot (what balances were at submit time)
    sender_exposure_before   REAL NOT NULL DEFAULT 0,
    receiver_exposure_before REAL NOT NULL DEFAULT 0,
    carry_limit_at_time      REAL NOT NULL DEFAULT 0,
    submitted_at     TEXT NOT NULL DEFAULT (datetime('now')),
    -- Void fields
    voided_by        TEXT NOT NULL DEFAULT '',
    voided_at        TEXT,
    void_reason      TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_strf_from   ON staff_transfers (from_id, status, submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_strf_to     ON staff_transfers (to_id,   status, submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_strf_date   ON staff_transfers (submitted_at DESC);
