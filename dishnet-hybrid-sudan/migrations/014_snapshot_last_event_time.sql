-- Migration 014: add last_event_time to staff_position_snapshot (v4.4.29)
-- Safe: column may already exist if this was applied manually
ALTER TABLE staff_position_snapshot
    ADD COLUMN last_event_time TEXT NOT NULL DEFAULT '';
