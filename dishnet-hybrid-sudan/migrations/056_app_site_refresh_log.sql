-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 056: Customer App Site-Refresh Rate Limit Log
-- DishNet Hybrid v4.12.31
--
-- Purpose: enforce per-customer rate limit on the live dish-status fetch
-- fired from the customer-facing Site Detail page (app_site_refresh endpoint
-- and the auto-fetch path in app_site_diagnostics when cache age > 15min).
--
-- Why a table and not plugin_kv:
--   - Need to query "most recent fetch for this client/kit" efficiently
--   - Retention/cleanup is easier with a structured table
--   - Small size (rows expire naturally after 24h)
--
-- Rate limit rule enforced in code: 1 live fetch per (client_id, kit_number)
-- per 5 minutes. If limit hit, fall back to cached data from wifi_router_map.
--
-- IF NOT EXISTS — safe to re-run on every boot.
-- ══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS app_site_refresh_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id       INTEGER NOT NULL,
    kit_number      TEXT    NOT NULL,
    router_id       TEXT    NOT NULL DEFAULT '',
    -- Outcome classification (matches drWifiFetchStatus error_type)
    outcome         TEXT    NOT NULL,  -- online | dish_unreachable | auth_failed | infrastructure | no_session | invalid_input
    -- When the live fetch was attempted
    fetched_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    -- Trigger: auto (cache expired) or manual (refresh button)
    trigger_kind    TEXT    NOT NULL DEFAULT 'auto'  -- auto | manual
);

CREATE INDEX IF NOT EXISTS idx_asrl_client_kit ON app_site_refresh_log(client_id, kit_number, fetched_at);
CREATE INDEX IF NOT EXISTS idx_asrl_fetched_at ON app_site_refresh_log(fetched_at);
