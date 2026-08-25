-- Migration 019: WhatsApp Lead Recovery
-- Import contacts from Evolution API IsOnWhatsapp table
-- Cross-reference with CRM to find unconverted leads

CREATE TABLE IF NOT EXISTS wa_known_contacts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    phone       TEXT    NOT NULL,
    country     TEXT    DEFAULT NULL,
    source      TEXT    DEFAULT 'evolution',
    first_seen  TEXT    DEFAULT (datetime('now')),
    created_at  TEXT    DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_known_phone ON wa_known_contacts(phone);

-- Lead status after CRM cross-reference
CREATE TABLE IF NOT EXISTS wa_lead_recovery (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    phone           TEXT    NOT NULL,
    display_name    TEXT    DEFAULT NULL,
    country         TEXT    DEFAULT NULL,
    crm_client_id   INTEGER DEFAULT NULL,
    crm_client_name TEXT    DEFAULT NULL,
    is_customer     INTEGER DEFAULT 0,
    status          TEXT    DEFAULT 'new',
    follow_up_count INTEGER DEFAULT 0,
    last_follow_up  TEXT    DEFAULT NULL,
    last_message_at TEXT    DEFAULT NULL,
    notes           TEXT    DEFAULT NULL,
    created_at      TEXT    DEFAULT (datetime('now')),
    updated_at      TEXT    DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_lead_phone ON wa_lead_recovery(phone);
CREATE INDEX IF NOT EXISTS idx_wa_lead_status ON wa_lead_recovery(status, is_customer);
