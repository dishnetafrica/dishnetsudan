-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 059: Bypass-detection columns for sl_suspension_state
-- DishNet Hybrid v4.21.5
--
-- Purpose: track when staff manually unpause a customer's devices via the
-- Starlink mobile app, bypassing our auto-block system. The extension cron
-- detects these bypasses (a MAC we previously paused that is now unpaused
-- at Starlink) and:
--   1. Re-pauses the device immediately
--   2. Increments bypass_event_count
--   3. Records last_bypass_at timestamp
--   4. After threshold (3 events in 24h) and cooldown (6h since last
--      alert), fires an admin WA alert
--
-- Without this layer, a staff member with Starlink credentials can
-- quietly restore a non-paying customer and the auto-block has no idea.
-- This migration is what makes bypass observable.
--
-- IF NOT EXISTS — safe to re-run via duplicate-column guard in
-- MigrationRunner. SQLite ALTER TABLE ADD COLUMN — no table rebuild.
-- ══════════════════════════════════════════════════════════════════════════════

ALTER TABLE sl_suspension_state ADD COLUMN bypass_event_count INTEGER NOT NULL DEFAULT 0;

ALTER TABLE sl_suspension_state ADD COLUMN last_bypass_at TEXT NULL;

ALTER TABLE sl_suspension_state ADD COLUMN bypass_alerted_at TEXT NULL;
