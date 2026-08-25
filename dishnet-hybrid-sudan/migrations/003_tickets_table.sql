-- ══════════════════════════════════════════════════════════════════════════════
-- Migration 003: Normalized Tickets Table
-- DishNet Hybrid v3.8 — Phase 2
--
-- Purpose: Replace the splynx_tickets.json blob with a proper relational table.
-- Currently, every ticket operation does:
--   1. $store->load('splynx_tickets.json')  — deserialize ALL tickets into PHP array
--   2. Loop through to find the one ticket
--   3. Modify it
--   4. $store->save('splynx_tickets.json')  — serialize ALL tickets back
--
-- With this table:
--   1. UPDATE tickets SET status=? WHERE id=?  — O(log n) via index
--
-- All 36 fields from SplynxTicketService are included.
-- The JSON blob continues to be written (dual-write) during the transition period.
--
-- Data migration: run migrations/migrate_tickets_data.php AFTER this migration.
-- ══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS tickets (
    -- ── Identity ────────────────────────────────────────────────────────────
    id                      INTEGER PRIMARY KEY,        -- Splynx ticket ID (same as blob 'id')
    app_id                  INTEGER DEFAULT 0,          -- KYC application ID (links to kyc_applications.json)
    customer_id             INTEGER DEFAULT 0,          -- Splynx customer ID
    crm_client_id           INTEGER,                    -- UCRM client ID (from enrichment)

    -- ── Customer info (denormalized for NOC dispatch speed) ─────────────────
    customer_name           TEXT DEFAULT '',
    crm_client_name         TEXT DEFAULT '',
    address                 TEXT DEFAULT '',
    phone                   TEXT DEFAULT '',
    phone_source            TEXT DEFAULT '',             -- ucrm_contacts, ucrm_phone, ucrm_note
    area                    TEXT DEFAULT 'Unknown',

    -- ── Ticket status ───────────────────────────────────────────────────────
    status                  INTEGER DEFAULT 1,          -- Splynx status ID (1=new, 2=wip, 7=survey...)
    status_label            TEXT DEFAULT 'new',
    priority                TEXT DEFAULT '',
    subject                 TEXT DEFAULT '',
    ftth_number             TEXT DEFAULT '',

    -- ── Engineer assignment ─────────────────────────────────────────────────
    engineer                TEXT DEFAULT '',             -- Legacy field (Splynx assigned_to)
    assigned_engineer_id    TEXT DEFAULT '',             -- DishNet staff ID
    assigned_engineer_name  TEXT DEFAULT '',             -- DishNet staff name
    assigned_at             TEXT,                        -- When engineer was assigned

    -- ── Installation workflow ───────────────────────────────────────────────
    install_complete        INTEGER DEFAULT 0,           -- Boolean: 0 or 1
    install_complete_at     TEXT,
    onu_serial              TEXT DEFAULT '',
    olt_port                TEXT DEFAULT '',
    signal_db               REAL,
    install_notes           TEXT DEFAULT '',
    install_data_at         TEXT,
    install_data_by         TEXT DEFAULT '',

    -- ── Testing / commissioning ─────────────────────────────────────────────
    testing_status          TEXT DEFAULT '',             -- pending, ready, approved, rejected
    commissioned_by         TEXT DEFAULT '',
    commission_notes        TEXT DEFAULT '',
    ready_at                TEXT,
    ready_submitted_by      TEXT DEFAULT '',
    rejected_by             TEXT DEFAULT '',
    rejection_notes         TEXT DEFAULT '',
    rejected_at             TEXT,

    -- ── Photos ──────────────────────────────────────────────────────────────
    photos                  TEXT DEFAULT '[]',           -- JSON array of photo records

    -- ── GPS (Phase 4: engineer location validation) ─────────────────────────
    gps_lat                 REAL,
    gps_lng                 REAL,

    -- ── Status change tracking ──────────────────────────────────────────────
    status_changed_at       TEXT,
    status_changed_by       TEXT DEFAULT '',
    cancelled               INTEGER DEFAULT 0,           -- Boolean flag

    -- ── CRM enrichment ──────────────────────────────────────────────────────
    crm_enriched_at         TEXT,
    crm_enrich_attempted    TEXT,

    -- ── Sync metadata ───────────────────────────────────────────────────────
    splynx_imported         INTEGER DEFAULT 0,           -- Was this imported from Splynx (vs created locally)?
    splynx_ticket_id        INTEGER,                     -- Redundant with id, but kept for clarity
    splynx_service_id       INTEGER,                     -- Linked Splynx internet service (for activation)
    synced_at               TEXT,                        -- Last successful Splynx sync

    -- ── Timestamps ──────────────────────────────────────────────────────────
    created_at              TEXT,
    updated_at              TEXT
);

-- ── Indexes for the NOC dispatch grid (Bidal's primary workflow) ────────────
-- "Show me all open tickets in Jebel" = WHERE area='Jebel' AND install_complete=0
CREATE INDEX IF NOT EXISTS idx_tickets_area
    ON tickets(area)
    WHERE install_complete = 0;

-- "Show me unassigned tickets" = WHERE assigned_engineer_name='' AND install_complete=0
CREATE INDEX IF NOT EXISTS idx_tickets_unassigned
    ON tickets(assigned_engineer_name)
    WHERE install_complete = 0 AND assigned_engineer_name = '';

-- Status filter (open tickets by status)
CREATE INDEX IF NOT EXISTS idx_tickets_status
    ON tickets(status)
    WHERE install_complete = 0;

-- Engineer workload ("how many jobs does Tech A have?")
CREATE INDEX IF NOT EXISTS idx_tickets_engineer
    ON tickets(assigned_engineer_name)
    WHERE install_complete = 0;

-- Phone lookup for CRM enrichment
CREATE INDEX IF NOT EXISTS idx_tickets_phone
    ON tickets(phone)
    WHERE phone != '';

-- Completion tracking
CREATE INDEX IF NOT EXISTS idx_tickets_complete
    ON tickets(install_complete, install_complete_at);

-- CRM client ID for enrichment reverse-lookup
CREATE INDEX IF NOT EXISTS idx_tickets_crm_client
    ON tickets(crm_client_id)
    WHERE crm_client_id IS NOT NULL;

-- Testing queue (ready for QA)
CREATE INDEX IF NOT EXISTS idx_tickets_testing
    ON tickets(testing_status)
    WHERE install_complete = 0 AND testing_status = 'ready';
