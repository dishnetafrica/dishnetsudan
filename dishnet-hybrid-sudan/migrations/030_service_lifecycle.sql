-- Migration 030: Service Lifecycle tracking for customer onboarding & engagement
-- Tracks Starlink, Fiber, LTE, SIM customers from registration through active service

-- ═══════════════════════════════════════════════════════════════════════════════
-- SERVICE LIFECYCLE TABLE
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS service_lifecycle (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- ── Customer Reference ────────────────────────────────────────────────────
    customer_id         INTEGER,                -- UCRM client ID (if exists)
    application_id      TEXT,                   -- KYC application ID
    customer_name       TEXT NOT NULL,
    customer_phone      TEXT,
    customer_email      TEXT,
    
    -- ── Service Info ──────────────────────────────────────────────────────────
    service_type        TEXT NOT NULL,          -- starlink, fiber, lte, sim
    kit_number          TEXT,                   -- KIT00234 or KIT-00234
    plan_name           TEXT,                   -- Package/plan name
    sales_type          TEXT,                   -- Cash, Credit
    amount_paid         REAL DEFAULT 0,
    
    -- ── Current Stage ─────────────────────────────────────────────────────────
    -- Starlink stages:
    --   registered, pending_payment, pending_location, location_received,
    --   activating, active, pending_password, overdue, suspended,
    --   return_requested, returned, cancelled
    -- Fiber stages:
    --   registered, pending_payment, pending_survey, survey_done,
    --   pending_installation, active, overdue, suspended, cancelled
    -- LTE stages:
    --   registered, pending_payment, pending_signal_check,
    --   pending_installation, active, overdue, suspended, cancelled
    -- SIM stages:
    --   registered, active, overdue, suspended, cancelled
    stage               TEXT DEFAULT 'registered',
    stage_entered_at    TEXT,                   -- ISO datetime
    days_in_stage       INTEGER DEFAULT 0,      -- Computed for display
    
    -- ── Stage History (JSON array) ────────────────────────────────────────────
    -- Format: [{"stage":"registered","at":"2026-03-14T10:30:00","by":"system","note":"KYC submitted"}]
    stage_history       TEXT DEFAULT '[]',
    
    -- ── Location Data (Starlink specific) ─────────────────────────────────────
    location_lat        REAL,
    location_lng        REAL,
    location_area       TEXT,                   -- Area name / address
    location_received_at TEXT,
    
    -- ── Activation Data ───────────────────────────────────────────────────────
    activated_at        TEXT,
    first_invoice_id    INTEGER,
    first_invoice_at    TEXT,
    first_payment_at    TEXT,
    
    -- ── WiFi / Password (Starlink specific) ───────────────────────────────────
    wifi_password       TEXT,                   -- Current WiFi password
    password_changed_at TEXT,
    
    -- ── Messages Tracking (prevent duplicates) ────────────────────────────────
    -- JSON: {"1A":"2026-03-14T10:30:00","2A":"2026-03-15T09:00:00"}
    messages_sent       TEXT DEFAULT '{}',
    last_message_id     TEXT,                   -- Last message code sent
    last_message_at     TEXT,
    next_message_id     TEXT,                   -- Next scheduled message
    next_message_at     TEXT,
    
    -- ── Action Flags ──────────────────────────────────────────────────────────
    needs_action        INTEGER DEFAULT 1,      -- 1 = show in action queue
    action_type         TEXT,                   -- send_reminder, activate, follow_up, etc.
    action_priority     INTEGER DEFAULT 0,      -- Higher = more urgent
    
    -- ── Agent / Staff ─────────────────────────────────────────────────────────
    registered_by_id    INTEGER,                -- Retailer ID who registered
    registered_by_name  TEXT,
    assigned_to_id      INTEGER,                -- Support staff assigned
    assigned_to_name    TEXT,
    
    -- ── Notes & Tags ──────────────────────────────────────────────────────────
    notes               TEXT,                   -- Internal notes
    tags                TEXT,                   -- JSON array: ["vip","corporate"]
    
    -- ── Timestamps ────────────────────────────────────────────────────────────
    created_at          TEXT DEFAULT (datetime('now')),
    updated_at          TEXT DEFAULT (datetime('now')),
    deleted_at          TEXT                    -- Soft delete
);

-- ═══════════════════════════════════════════════════════════════════════════════
-- INDEXES
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE INDEX IF NOT EXISTS idx_lifecycle_stage ON service_lifecycle(stage);
CREATE INDEX IF NOT EXISTS idx_lifecycle_service_type ON service_lifecycle(service_type);
CREATE INDEX IF NOT EXISTS idx_lifecycle_needs_action ON service_lifecycle(needs_action);
CREATE INDEX IF NOT EXISTS idx_lifecycle_customer_id ON service_lifecycle(customer_id);
CREATE INDEX IF NOT EXISTS idx_lifecycle_app_id ON service_lifecycle(application_id);
CREATE INDEX IF NOT EXISTS idx_lifecycle_kit ON service_lifecycle(kit_number);
CREATE INDEX IF NOT EXISTS idx_lifecycle_phone ON service_lifecycle(customer_phone);
CREATE INDEX IF NOT EXISTS idx_lifecycle_created ON service_lifecycle(created_at);

-- ═══════════════════════════════════════════════════════════════════════════════
-- LIFECYCLE MESSAGE TEMPLATES TABLE
-- ═══════════════════════════════════════════════════════════════════════════════
-- Stores message templates for each stage/trigger

CREATE TABLE IF NOT EXISTS lifecycle_messages (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- ── Identity ──────────────────────────────────────────────────────────────
    code                TEXT UNIQUE NOT NULL,   -- 1A, 1B, 2A, 3A, etc.
    service_type        TEXT NOT NULL,          -- starlink, fiber, lte, sim, all
    
    -- ── Display ───────────────────────────────────────────────────────────────
    name                TEXT NOT NULL,          -- "Kit Collected"
    description         TEXT,                   -- When/why this is sent
    phase               TEXT,                   -- registration, reminder, activation, etc.
    
    -- ── Trigger ───────────────────────────────────────────────────────────────
    trigger_type        TEXT,                   -- auto, manual, cron
    trigger_stage       TEXT,                   -- Stage that triggers this
    trigger_days        INTEGER,                -- Days in stage before sending
    trigger_condition   TEXT,                   -- JSON conditions
    
    -- ── Template ──────────────────────────────────────────────────────────────
    template            TEXT NOT NULL,          -- Message with {{variables}}
    
    -- ── Flags ─────────────────────────────────────────────────────────────────
    is_active           INTEGER DEFAULT 1,
    is_default          INTEGER DEFAULT 1,      -- 0 if customized
    
    -- ── Timestamps ────────────────────────────────────────────────────────────
    created_at          TEXT DEFAULT (datetime('now')),
    updated_at          TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_lm_code ON lifecycle_messages(code);
CREATE INDEX IF NOT EXISTS idx_lm_service ON lifecycle_messages(service_type);
CREATE INDEX IF NOT EXISTS idx_lm_trigger ON lifecycle_messages(trigger_stage, trigger_days);

-- ═══════════════════════════════════════════════════════════════════════════════
-- LIFECYCLE MESSAGE LOG TABLE
-- ═══════════════════════════════════════════════════════════════════════════════
-- Logs all messages sent through lifecycle system

CREATE TABLE IF NOT EXISTS lifecycle_message_log (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    
    -- ── References ────────────────────────────────────────────────────────────
    lifecycle_id        INTEGER NOT NULL,       -- FK to service_lifecycle
    message_code        TEXT NOT NULL,          -- 1A, 2B, etc.
    
    -- ── Recipient ─────────────────────────────────────────────────────────────
    recipient_phone     TEXT,
    recipient_name      TEXT,
    
    -- ── Message ───────────────────────────────────────────────────────────────
    message_text        TEXT,                   -- Actual message sent
    
    -- ── Status ────────────────────────────────────────────────────────────────
    status              TEXT DEFAULT 'sent',    -- sent, delivered, failed, skipped
    error               TEXT,
    
    -- ── Metadata ──────────────────────────────────────────────────────────────
    sent_by             TEXT DEFAULT 'system',  -- system, manual, cron
    sent_by_id          INTEGER,
    
    -- ── Timestamps ────────────────────────────────────────────────────────────
    created_at          TEXT DEFAULT (datetime('now')),
    
    FOREIGN KEY (lifecycle_id) REFERENCES service_lifecycle(id)
);

CREATE INDEX IF NOT EXISTS idx_lml_lifecycle ON lifecycle_message_log(lifecycle_id);
CREATE INDEX IF NOT EXISTS idx_lml_code ON lifecycle_message_log(message_code);
CREATE INDEX IF NOT EXISTS idx_lml_created ON lifecycle_message_log(created_at);
