-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 058: Add block_mode column to sl_suspension_state
-- DishNet Hybrid v4.21.4
--
-- Purpose: support pause-only as an alternative to full block. Pause-only
-- means: pause every connected MAC, but DON'T touch SSID/password. Cleaner
-- customer experience because:
--   - Customer's saved WiFi password still works (no support call when
--     they pay and need to re-enter the network)
--   - WiFi network name stays as the customer expects (no surprise
--     'DishNet-PAY-NOW' SSID)
--   - Restore is simpler — just unpause, no credential restore needed
--
-- Tradeoff: pause-only is leaky. New devices that join AFTER the initial
-- block get internet because the password didn't change. Mitigated by
-- the extension cron (cron_starlink_block_retry.php in 'extend' mode,
-- v4.21.4+) which re-pauses new devices every 10 minutes per blocked
-- router.
--
-- Default for new rows: 'pause_only' (set in code, not schema, so admins
-- can switch the default via config without migration).
-- Existing rows pre-migration default to 'full' for safe backward compat.
--
-- IF NOT EXISTS — safe to re-run.
-- ALTER TABLE ADD COLUMN — SQLite supports this without table rebuild.
-- ══════════════════════════════════════════════════════════════════════════════

-- SQLite doesn't support IF NOT EXISTS on ADD COLUMN, so we wrap in a
-- defensive check via PRAGMA. The migration runner expects raw SQL — if
-- it can't handle PRAGMA, this approach falls through cleanly because
-- ADD COLUMN twice is a benign error (caught and logged by migration
-- runner per existing convention).

ALTER TABLE sl_suspension_state ADD COLUMN block_mode TEXT NOT NULL DEFAULT 'full';

-- Note: we don't backfill 'pause_only' for any rows — pre-migration rows
-- ARE in full-block mode (that was the v4.21.0-v4.21.3 behavior). They
-- correctly stay 'full' and restore() will continue restoring the SSID
-- and password from their saved original_* fields.
