-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 017: WhatsApp Conversation Store + AI Training
-- DishNet Hybrid v4.5 — Conversation Intelligence
--
-- Purpose:
--   1. Store all WhatsApp conversations (Support + Accounts + Marketing channels)
--   2. Store individual messages with full metadata
--   3. Auto-reply templates (keyword → response mapping)
--   4. Training dataset for future AI upgrade
--
-- Data sources:
--   - Real-time: wa_webhook.php captures WASender incoming messages
--   - Real-time: evo_webhook.php captures Evolution API messages
--   - Real-time: NotificationService logs all outgoing messages
--   - Import:    WASender DB dump (one-time historical import)
--   - Import:    Evolution API /chat/findMessages (one-time pull)
-- ══════════════════════════════════════════════════════════════════════════════

-- ── Conversations ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wa_conversations (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    phone           TEXT    NOT NULL,
    channel         TEXT    NOT NULL DEFAULT 'support',  -- support, accounts, marketing
    display_name    TEXT,
    crm_client_id   INTEGER,
    crm_client_name TEXT,
    status          TEXT    NOT NULL DEFAULT 'active',   -- active, closed, archived
    category        TEXT,                                -- billing, technical, onboarding, general, complaint, marketing
    last_message_at TEXT,
    last_customer_at TEXT,
    last_agent_at   TEXT,
    message_count   INTEGER NOT NULL DEFAULT 0,
    unread_count    INTEGER NOT NULL DEFAULT 0,
    tags            TEXT,                                -- JSON array
    source          TEXT    DEFAULT 'webhook',           -- webhook, import, manual
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_conv_phone_channel
    ON wa_conversations(phone, channel);

CREATE INDEX IF NOT EXISTS idx_wa_conv_crm
    ON wa_conversations(crm_client_id) WHERE crm_client_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_wa_conv_active
    ON wa_conversations(status, last_message_at DESC) WHERE status = 'active';

CREATE INDEX IF NOT EXISTS idx_wa_conv_category
    ON wa_conversations(category) WHERE category IS NOT NULL;


-- ── Messages ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wa_messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL REFERENCES wa_conversations(id) ON DELETE CASCADE,
    direction       TEXT    NOT NULL,       -- in (customer→us) or out (us→customer)
    role            TEXT    NOT NULL,       -- customer, agent, bot, system
    body            TEXT    NOT NULL,
    media_type      TEXT,                   -- null, image, document, audio, video, location
    media_url       TEXT,
    agent_name      TEXT,
    wa_message_id   TEXT,                   -- WASender/Evolution internal msg ID (dedup)
    event_key       TEXT,                   -- Linked plugin event (ops_kyc_submitted etc)
    metadata        TEXT,                   -- JSON
    sent_at         TEXT    NOT NULL DEFAULT (datetime('now')),
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_wa_msg_conv_time
    ON wa_messages(conversation_id, sent_at);

CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_msg_wamid
    ON wa_messages(wa_message_id) WHERE wa_message_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_wa_msg_direction
    ON wa_messages(direction, sent_at);


-- ── Auto-Reply Templates ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wa_reply_templates (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT    NOT NULL,
    channel         TEXT    NOT NULL DEFAULT 'both',     -- support, accounts, marketing, both
    match_type      TEXT    NOT NULL DEFAULT 'keyword',  -- keyword, regex, exact, ai (future)
    match_pattern   TEXT    NOT NULL,                    -- Comma-separated keywords or regex
    response_body   TEXT    NOT NULL,                    -- Template (supports {{vars}})
    category        TEXT,                                -- Auto-tag conversation when matched
    priority        INTEGER NOT NULL DEFAULT 5,          -- 1=first checked, 10=last
    enabled         INTEGER NOT NULL DEFAULT 1,
    hit_count       INTEGER NOT NULL DEFAULT 0,
    last_hit_at     TEXT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_wa_tpl_enabled
    ON wa_reply_templates(enabled, priority) WHERE enabled = 1;


-- ── Training Dataset ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wa_training_data (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    channel         TEXT    NOT NULL DEFAULT 'support',
    category        TEXT,
    question        TEXT    NOT NULL,       -- Customer message
    answer          TEXT    NOT NULL,       -- Agent/bot response
    quality         TEXT    DEFAULT 'unreviewed',  -- unreviewed, approved, rejected
    source_conv_id  INTEGER,
    source_msg_id   INTEGER,
    reviewed_by     TEXT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_wa_train_quality
    ON wa_training_data(quality, channel);


-- ── Seed Default Templates ───────────────────────────────────────────────────
INSERT OR IGNORE INTO wa_reply_templates (id, name, channel, match_type, match_pattern, response_body, category, priority) VALUES
(1, 'Greeting', 'both', 'keyword',
 'hello,hi,hey,good morning,good afternoon,good evening,jambo,salam,salaam',
 '👋 Welcome to DishNet Africa!\n\nHow can we help you?\n1️⃣ Internet not working\n2️⃣ Billing / Payment\n3️⃣ New connection\n4️⃣ Speak to an agent\n\nReply with a number or describe your issue.',
 'general', 1),

(2, 'Internet Down', 'support', 'keyword',
 'internet not working,no internet,wifi down,no connection,disconnected,offline,slow internet,buffering,network problem',
 '📡 We''re sorry to hear your internet is down.\n\nPlease try:\n1. Power-cycle your router (unplug 30 sec, plug back)\n2. Check if the Starlink dish has clear sky view\n3. Restart your device\n\nIf the issue persists, reply *AGENT* and we''ll connect you.\n\n🔧 DishNet Support',
 'technical', 2),

(3, 'Payment Inquiry', 'accounts', 'keyword',
 'payment,pay,invoice,bill,balance,amount due,how much,outstanding,receipt,mpesa,bank',
 '💰 For payment inquiries:\n\nCheck your balance & pay online:\n🔗 https://crm.dishnetafrica.com/crm/login\n\nOr contact our accounts team:\n📞 +211 921 443 009\n\nReply *AGENT* to speak to someone now.',
 'billing', 2),

(4, 'New Connection', 'support', 'keyword',
 'new connection,sign up,subscribe,new customer,join,register,get internet,starlink,fiber,want internet',
 '🌐 Great! We offer:\n\n📡 *Starlink* — Satellite internet anywhere\n🔌 *Fiber (FTTH)* — Ultra-fast fiber to home\n📱 *LTE* — 4G private network\n\nReply with your preferred service and our sales team will contact you!\n\n— DishNet Sales',
 'onboarding', 2),

(5, 'Agent Request', 'both', 'keyword',
 'agent,human,person,speak to someone,talk to,representative,help me,support,staff',
 '🙋 Connecting you to a DishNet agent. Someone will respond shortly.\n\nOur support hours: 8 AM – 8 PM (EAT)\n\nThank you for your patience!',
 'general', 1),

(6, 'Thank You', 'both', 'keyword',
 'thank you,thanks,thankyou,thx,shukran,appreciated,great help',
 '😊 You''re welcome! Is there anything else we can help with?\n\nHave a great day!\n— DishNet Team',
 'general', 9),

(7, 'Operating Hours', 'both', 'keyword',
 'hours,open,working hours,available,when,office hours,time',
 '🕐 DishNet Support Hours:\n\nMonday – Saturday: 8:00 AM – 8:00 PM (EAT)\nSunday: Emergency support only\n\n📞 Support: +211 921 443 006\n💼 Accounts: +211 921 443 009',
 'general', 5);
