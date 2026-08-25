-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 057: Starlink Auto-Suspension State + Log
-- DishNet Hybrid v4.21.0
--
-- Purpose: support automated device-blocking when UCRM fires service.suspend
-- for a Starlink customer. Two tables:
--
--   sl_suspension_state — one row per (client_id, router_id) currently in
--     suspended state. Holds the saved original SSID/password we'll restore
--     on payment + the list of MACs we paused. Driven by a state machine:
--       suspending → suspended → restoring → (row deleted)
--                  → partial_suspend_failed → cron retry
--                  → error_manual_required (5 retries failed)
--
--   sl_suspension_log — append-only audit trail of every gRPC call we made,
--     for support and post-mortems. Never deleted (small rows, low volume).
--
-- Why state lives in a table not a JSON file:
--   - Concurrent webhook fires (UCRM double-fires) need atomic state
--   - We need to query "all rows in 'suspending' state, last_attempt < N"
--     for the cron retry loop
--   - Customer support needs to see "is this client currently blocked?"
--     joined against UCRM client list
--
-- IF NOT EXISTS — safe to re-run on every boot.
-- ══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS sl_suspension_state (
    id                          INTEGER PRIMARY KEY AUTOINCREMENT,

    -- Identity
    client_id                   INTEGER NOT NULL,
    crm_service_id              INTEGER NOT NULL DEFAULT 0,
    kit_serial                  TEXT    NOT NULL DEFAULT '',
    router_id                   TEXT    NOT NULL,            -- e.g. "Router-AB12CD34"
    account_number              TEXT    NOT NULL DEFAULT '', -- Starlink account, e.g. "ACC-1234567"

    -- Saved original config (read via wifi_get_config before we changed anything)
    original_ssid_24            TEXT    NOT NULL DEFAULT '',
    original_ssid_5             TEXT    NOT NULL DEFAULT '',
    original_pass_24            TEXT    NOT NULL DEFAULT '',
    original_pass_5             TEXT    NOT NULL DEFAULT '',
    original_auth_type          TEXT    NOT NULL DEFAULT 'wpa2',

    -- Suspension config (what we set the router to)
    suspension_ssid             TEXT    NOT NULL DEFAULT 'DishNet-PAY-NOW',
    suspension_pass             TEXT    NOT NULL DEFAULT '',

    -- Device-pause tracking
    paused_macs_json            TEXT    NOT NULL DEFAULT '[]',  -- MACs we paused; will unpause on restore
    pre_existing_paused_json    TEXT    NOT NULL DEFAULT '[]',  -- MACs already paused before we touched anything (don't unpause these)

    -- State machine
    -- suspending | suspended | partial_suspend_failed | restoring | error_manual_required
    state                       TEXT    NOT NULL DEFAULT 'suspending',

    -- Bypass-mode tracking (Starlink in bypass = no WiFi to change, partial block only)
    is_bypass_mode              INTEGER NOT NULL DEFAULT 0,    -- 0=normal, 1=bypassed (partial block applied)

    -- Audit
    suspended_at                TEXT    NOT NULL DEFAULT (datetime('now')),
    suspended_by                TEXT    NOT NULL DEFAULT 'webhook',  -- 'webhook' | 'manual:<retailer_id>' | 'cron_retry'
    triggered_by_event          TEXT    NOT NULL DEFAULT '',         -- e.g. 'service.suspend'

    -- Retry / error tracking
    last_attempt_at             TEXT    NOT NULL DEFAULT (datetime('now')),
    attempt_count               INTEGER NOT NULL DEFAULT 1,
    last_error                  TEXT    NOT NULL DEFAULT '',

    -- Restore tracking (set when we kick off restore, cleared row when restore completes)
    restore_started_at          TEXT,
    restore_triggered_by        TEXT,                                -- 'webhook' | 'payment.add' | 'manual:<retailer_id>'

    UNIQUE(client_id, router_id)
);

CREATE INDEX IF NOT EXISTS idx_sl_susp_state_state
    ON sl_suspension_state(state, last_attempt_at);
CREATE INDEX IF NOT EXISTS idx_sl_susp_state_client
    ON sl_suspension_state(client_id);


CREATE TABLE IF NOT EXISTS sl_suspension_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    ts              TEXT    NOT NULL DEFAULT (datetime('now')),
    client_id       INTEGER NOT NULL,
    router_id       TEXT    NOT NULL DEFAULT '',
    -- Action: save_state | change_password | pause_device | restore_password
    --         | unpause_device | skip_vip | skip_bypass | retry | abort
    --         | webhook_received | webhook_skipped
    action          TEXT    NOT NULL,
    success         INTEGER NOT NULL DEFAULT 0,    -- 0/1
    grpc_status     INTEGER,                       -- e.g. 0 OK, 7 PERMISSION_DENIED
    grpc_message    TEXT    NOT NULL DEFAULT '',
    detail          TEXT    NOT NULL DEFAULT '',   -- free-form context (MAC, SSID, etc.)
    attempt_number  INTEGER NOT NULL DEFAULT 1
);

CREATE INDEX IF NOT EXISTS idx_sl_susp_log_client_ts
    ON sl_suspension_log(client_id, ts DESC);
CREATE INDEX IF NOT EXISTS idx_sl_susp_log_router_ts
    ON sl_suspension_log(router_id, ts DESC);
