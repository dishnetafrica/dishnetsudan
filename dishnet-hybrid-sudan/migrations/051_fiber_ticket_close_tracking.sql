-- Migration 051: fiber_collection_jobs — ticket close tracking
-- Tracks whether the Splynx installation ticket was auto-closed.
-- ticket_closed: 1 = closed successfully in Splynx, 0/NULL = not yet / failed
-- ticket_closed_at: timestamp when closed

ALTER TABLE fiber_collection_jobs ADD COLUMN ticket_closed     INTEGER NOT NULL DEFAULT 0;
ALTER TABLE fiber_collection_jobs ADD COLUMN ticket_closed_at  TEXT;
