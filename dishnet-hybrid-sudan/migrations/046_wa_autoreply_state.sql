-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 046: WhatsApp Auto-Reply State Columns
-- DishNet Hybrid v4.11.3 — Unified WhatsApp System (Phase 1-3)
--
-- Adds state machine columns to wa_conversations for channel-aware auto-reply:
--   state:               new|support_menu_sent|accounts_menu_sent|support_collecting_name|
--                        support_collecting_issue|ticket_created|needs_human|human_active|closed
--   bot_replied_at:      dedup — skip if last reply <30s ago
--   last_human_reply_at: cooldown — bot re-engages after 24h
--   collected_name:      captured during ticket flow
--   collected_issue:     captured during ticket flow
-- ══════════════════════════════════════════════════════════════════════════════

ALTER TABLE wa_conversations ADD COLUMN state TEXT NOT NULL DEFAULT 'new';
ALTER TABLE wa_conversations ADD COLUMN bot_replied_at TEXT;
ALTER TABLE wa_conversations ADD COLUMN last_human_reply_at TEXT;
ALTER TABLE wa_conversations ADD COLUMN collected_name TEXT;
ALTER TABLE wa_conversations ADD COLUMN collected_issue TEXT;

CREATE INDEX IF NOT EXISTS idx_wa_conv_state ON wa_conversations(state);

-- ── Support tickets created by WhatsApp bot ─────────────────────────────────
CREATE TABLE IF NOT EXISTS support_tickets (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    subject         TEXT    NOT NULL,
    description     TEXT,
    phone           TEXT,
    customer_phone  TEXT,
    customer_name   TEXT,
    crm_client_id   INTEGER,
    status          TEXT    NOT NULL DEFAULT 'open',  -- open, in_progress, resolved, closed
    priority        TEXT    DEFAULT 'normal',          -- low, normal, high, urgent
    assigned_to     TEXT,
    source          TEXT    DEFAULT 'manual',           -- manual, whatsapp_bot, webhook
    resolved_at     TEXT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_tickets_phone ON support_tickets(phone);
CREATE INDEX IF NOT EXISTS idx_tickets_status ON support_tickets(status);

