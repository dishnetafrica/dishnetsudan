-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 062: AI Platform — Evolution multi-instance + webhook hardening
--
-- Additive only. No existing table is altered and no data is moved, so this is
-- safe to apply while the current WhatsApp bot keeps running.
-- ══════════════════════════════════════════════════════════════════════════════

-- Webhook idempotency. evo_webhook_v2.php claims a message id here BEFORE doing
-- anything with side effects, so two concurrent deliveries of the same message
-- cannot both produce a reply. EvoWebhookGuard also creates this at runtime;
-- having it in a migration means a fresh install has it before first traffic.
CREATE TABLE IF NOT EXISTS evo_webhook_seen (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id  TEXT NOT NULL,
    instance    TEXT,
    event       TEXT,
    received_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- The UNIQUE index is what makes the claim atomic. Without it the dedup is a
-- race, not a guarantee.
CREATE UNIQUE INDEX IF NOT EXISTS idx_evo_seen_msgid ON evo_webhook_seen(message_id);
CREATE INDEX IF NOT EXISTS idx_evo_seen_time  ON evo_webhook_seen(received_at);

-- Unified customer view across the three numbers.
-- wa_conversations is unique on (phone, channel), so one customer contacting
-- sales then support has two rows. crm_client_id is already populated and
-- already indexed on its own; this composite index makes "every conversation
-- for this customer, by channel" cheap for the admin inbox.
CREATE INDEX IF NOT EXISTS idx_wa_conv_client_channel
    ON wa_conversations(crm_client_id, channel)
    WHERE crm_client_id IS NOT NULL;

-- Escalation queue lookups: "which conversations are waiting for a human?"
CREATE INDEX IF NOT EXISTS idx_wa_conv_needs_human
    ON wa_conversations(state, updated_at DESC)
    WHERE state = 'needs_human';
