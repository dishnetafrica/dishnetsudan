-- Migration 050: fiber_collection_jobs — delivery note tracking
-- Tracks whether the auto delivery note PDF was sent to the customer.
-- delivery_note_sent: 1 = sent successfully, 0/NULL = not yet sent
-- delivery_note_sent_at: timestamp when sent

ALTER TABLE fiber_collection_jobs ADD COLUMN delivery_note_sent     INTEGER NOT NULL DEFAULT 0;
ALTER TABLE fiber_collection_jobs ADD COLUMN delivery_note_sent_at  TEXT;
