-- Migration 008: staff_cash_position VIEW (v4.4.24)
--
-- Creates a live SQL VIEW that computes each field agent's true cash exposure
-- by combining two cash streams across mixed storage (SQLite native tables +
-- JSON-blob record tables):
--
--   cash_exposure =
--     advance_balance   (active root advances: amount − spent − returned − allocated)
--     + collections     (all customer payments received by this agent)
--     − expenses        (daily cash expenses approved, from cash_expenses.json)
--     − handovers       (cash confirmed handed to Rupesh, from cash_handovers.json)
--
-- This VIEW is the single source of truth for carry-limit checks, the
-- Staff Cash Control dashboard, and the nightly reconcile worker.
--
-- Only agents with role IN ('sales','field_agent','collection') and is_active=1
-- are included. json_extract reads the JSON data column in record tables.

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
    ROUND(sub.advance_balance + sub.collections - sub.expenses - sub.handovers, 2) AS cash_exposure
FROM (
    SELECT
        r.id                                                                              AS staff_id,
        json_extract(r.data, '$.name')                                                    AS staff_name,
        CAST(COALESCE(json_extract(r.data, '$.wallet'), 0) AS REAL)                      AS float_balance,

        -- Stream A: active advance balance (native SQLite table)
        COALESCE((
            SELECT ROUND(SUM(
                ca.amount
                - ca.amount_spent
                - ca.amount_returned
                - COALESCE(ca.children_allocated, 0)
            ), 2)
            FROM cash_advances ca
            WHERE ca.recipient_id = r.id
              AND ca.status IN ('active', 'partial')
              AND (ca.parent_advance_id IS NULL OR ca.parent_advance_id = 0)
        ), 0.0)                                                                           AS advance_balance,

        -- Stream B: all customer collections (JSON records)
        COALESCE((
            SELECT ROUND(SUM(CAST(json_extract(c.data, '$.amount') AS REAL)), 2)
            FROM [payment_collections] c
            WHERE CAST(json_extract(c.data, '$.retailer_id') AS INTEGER) = r.id
        ), 0.0)                                                                           AS collections,

        -- Stream C: approved daily cash expenses (JSON records)
        COALESCE((
            SELECT ROUND(SUM(CAST(json_extract(e.data, '$.amount') AS REAL)), 2)
            FROM [cash_expenses] e
            WHERE CAST(json_extract(e.data, '$.collector_id') AS INTEGER) = r.id
              AND json_extract(e.data, '$.status') = 'approved'
        ), 0.0)                                                                           AS expenses,

        -- Stream D: confirmed handovers (JSON records)
        COALESCE((
            SELECT ROUND(SUM(CAST(json_extract(h.data, '$.amount') AS REAL)), 2)
            FROM [cash_handovers] h
            WHERE CAST(json_extract(h.data, '$.from_id') AS INTEGER) = r.id
              AND json_extract(h.data, '$.status') = 'confirmed'
        ), 0.0)                                                                           AS handovers

    FROM [retailers] r
    WHERE json_extract(r.data, '$.is_active') = 1
      AND json_extract(r.data, '$.role') IN ('sales', 'field_agent', 'collection')
) sub;
