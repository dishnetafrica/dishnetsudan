-- Migration 011: staff_cash_position VIEW v2 (v4.4.26)
--
-- Rebuilds the VIEW from migration 008 to add two transfer streams:
--   Stream E: approved transfers_sent     (from_id = agent) → reduces exposure
--   Stream F: approved transfers_received (to_id   = agent) → increases exposure
--
-- Updated formula:
--   cash_exposure =
--       advance_balance
--     + collections
--     - expenses
--     - handovers
--     - transfers_sent        ← NEW
--     + transfers_received    ← NEW
--
-- Conservation law: for any TRF-xxx, sender loses exactly what receiver gains.
-- Total field cash across ALL agents is unchanged by a transfer.
-- Voided transfers (status='voided') are excluded from both streams.

DROP VIEW IF EXISTS staff_cash_position;

CREATE VIEW staff_cash_position AS
SELECT
    sub.staff_id,
    sub.staff_name,
    sub.float_balance,
    sub.advance_balance,
    sub.collections,
    sub.expenses,
    sub.handovers,
    sub.transfers_sent,
    sub.transfers_received,
    ROUND(
          sub.advance_balance
        + sub.collections
        - sub.expenses
        - sub.handovers
        - sub.transfers_sent
        + sub.transfers_received
    , 2) AS cash_exposure
FROM (
    SELECT
        r.id                                                                         AS staff_id,
        json_extract(r.data, '$.name')                                               AS staff_name,
        CAST(COALESCE(json_extract(r.data,'$.wallet'), 0) AS REAL)                  AS float_balance,

        -- Stream A: active root advance balance
        COALESCE((
            SELECT ROUND(SUM(
                ca.amount - ca.amount_spent - ca.amount_returned
                - COALESCE(ca.children_allocated, 0)
            ), 2)
            FROM cash_advances ca
            WHERE ca.recipient_id = r.id
              AND ca.status IN ('active','partial')
              AND (ca.parent_advance_id IS NULL OR ca.parent_advance_id = 0)
        ), 0.0)                                                                      AS advance_balance,

        -- Stream B: all customer collections
        COALESCE((
            SELECT ROUND(SUM(CAST(json_extract(c.data,'$.amount') AS REAL)), 2)
            FROM [payment_collections] c
            WHERE CAST(json_extract(c.data,'$.retailer_id') AS INTEGER) = r.id
        ), 0.0)                                                                      AS collections,

        -- Stream C: approved expenses
        COALESCE((
            SELECT ROUND(SUM(CAST(json_extract(e.data,'$.amount') AS REAL)), 2)
            FROM [cash_expenses] e
            WHERE CAST(json_extract(e.data,'$.collector_id') AS INTEGER) = r.id
              AND json_extract(e.data,'$.status') = 'approved'
        ), 0.0)                                                                      AS expenses,

        -- Stream D: confirmed handovers
        COALESCE((
            SELECT ROUND(SUM(CAST(json_extract(h.data,'$.amount') AS REAL)), 2)
            FROM [cash_handovers] h
            WHERE CAST(json_extract(h.data,'$.from_id') AS INTEGER) = r.id
              AND json_extract(h.data,'$.status') = 'confirmed'
        ), 0.0)                                                                      AS handovers,

        -- Stream E: approved transfers OUT (this agent gave cash to someone)
        COALESCE((
            SELECT ROUND(SUM(t.amount), 2)
            FROM staff_transfers t
            WHERE t.from_id = r.id
              AND t.status = 'approved'
        ), 0.0)                                                                      AS transfers_sent,

        -- Stream F: approved transfers IN (this agent received cash from someone)
        COALESCE((
            SELECT ROUND(SUM(t.amount), 2)
            FROM staff_transfers t
            WHERE t.to_id = r.id
              AND t.status = 'approved'
        ), 0.0)                                                                      AS transfers_received

    FROM [retailers] r
    WHERE json_extract(r.data,'$.is_active') = 1
      AND json_extract(r.data,'$.role') IN ('sales','field_agent','collection','field_accountant')
) sub;
