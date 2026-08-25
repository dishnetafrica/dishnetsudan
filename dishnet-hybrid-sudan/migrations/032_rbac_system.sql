-- ═══════════════════════════════════════════════════════════════════════════════
-- MIGRATION 031: RBAC (Role-Based Access Control) System
-- ═══════════════════════════════════════════════════════════════════════════════
-- 
-- Purpose: Granular permission system with custom roles
-- Maps directly to existing $ALL_MODULES in public.php
-- 
-- Tables:
--   - roles: Define roles (Admin, Dealer, Sales Staff, etc.)
--   - permissions: Define all available permissions (matches ALL_MODULES)
--   - role_permissions: Map roles to permissions (many-to-many)
--
-- Key Fields:
--   - roles.is_staff: If true, cash KYC creates cb_ledger entries
--   - roles.is_system: If true, role cannot be deleted
--
-- ═══════════════════════════════════════════════════════════════════════════════

-- ── Roles Table ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS roles (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL,
    slug            TEXT NOT NULL UNIQUE,
    description     TEXT,
    color           TEXT DEFAULT '#6b7280',
    icon            TEXT DEFAULT '👤',
    is_system       INTEGER DEFAULT 0,
    is_staff        INTEGER DEFAULT 0,
    is_active       INTEGER DEFAULT 1,
    created_at      TEXT DEFAULT (datetime('now')),
    updated_at      TEXT DEFAULT (datetime('now')),
    created_by      TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_roles_slug ON roles(slug);

-- ── Permissions Table ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS permissions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    module          TEXT NOT NULL,
    action          TEXT NOT NULL,
    label           TEXT NOT NULL,
    description     TEXT,
    sort_order      INTEGER DEFAULT 0,
    UNIQUE(module, action)
);

CREATE INDEX IF NOT EXISTS idx_permissions_module ON permissions(module);

-- ── Role-Permissions Junction Table ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS role_permissions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    role_id         INTEGER NOT NULL,
    permission_id   INTEGER NOT NULL,
    created_at      TEXT DEFAULT (datetime('now')),
    UNIQUE(role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_role_perms_role ON role_permissions(role_id);
CREATE INDEX IF NOT EXISTS idx_role_perms_perm ON role_permissions(permission_id);

-- ═══════════════════════════════════════════════════════════════════════════════
-- SEED DATA: ALL Permissions (matches $ALL_MODULES exactly)
-- ═══════════════════════════════════════════════════════════════════════════════

-- ── Sales Module ───────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO permissions (module, action, label, description, sort_order) VALUES
    ('sales', 'kyc', 'KYC / Add Customer', 'Register new customers via KYC form', 1),
    ('sales', 'leads', 'My Leads', 'View and manage sales leads', 2),
    ('sales', 'send_quote', 'Send Quotation', 'Send quotes to customers', 3),
    ('sales', 'quote_logs', 'Quote History', 'View quote history', 4),
    ('sales', 'collect_payment', 'Collect Payment', 'Collect payments from customers', 5),
    ('sales', 'wallet', 'My Wallet & Passbook', 'View and use own wallet', 6),
    ('sales', 'wallet_recharge', 'Request Wallet Recharge', 'Request wallet top-ups', 7),
    ('sales', 'applications', 'My Applications', 'View own submitted applications', 8);

-- ── Support Module ─────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO permissions (module, action, label, description, sort_order) VALUES
    ('support', 'support_dash', 'Support Dashboard', 'View support dashboard', 1),
    ('support', 'scheduling', 'My Jobs (Scheduling)', 'View scheduled jobs', 2),
    ('support', 'bulk_dispatch', 'Bulk Job Dispatch', 'Dispatch jobs in bulk', 3),
    ('support', 'live_map', 'Live Staff Map', 'View staff locations on map', 4),
    ('support', 'splynx_noc', 'Splynx NOC Dashboard', 'View Splynx NOC dashboard', 5),
    ('support', 'splynx_my_jobs', 'My Install Jobs (Splynx)', 'View Splynx installation jobs', 6),
    ('support', 'route_manager', 'Route Manager', 'Manage installation routes', 7),
    ('support', 'support_leader_manual', 'Support Leader Manual', 'View support leader manual', 8),
    ('support', 'field_expenses', 'Field Expenses', 'Submit field expenses', 9),
    ('support', 'customer_lookup', 'Customer Lookup', 'Search and view customer details', 10),
    ('support', 'service_status', 'Service Status Check', 'Check service status', 11),
    ('support', 'tickets', 'Support Tickets', 'Manage support tickets', 12);

-- ── Accounts Module ────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO permissions (module, action, label, description, sort_order) VALUES
    ('accounts', 'cash_declaration', 'Cash Declaration', 'Submit cash declarations', 1),
    ('accounts', 'cashbook', 'Cashbook (USD & SSP)', 'View own cashbook', 2),
    ('accounts', 'accounts_dash', 'Accounts Dashboard', 'View accounts dashboard', 3),
    ('accounts', 'balance_identity', 'Balance Identity', 'View balance identity', 4),
    ('accounts', 'collections', 'All Collections Report', 'View all collections', 5),
    ('accounts', 'ledger', 'Retailer Ledger', 'View retailer ledger', 6),
    ('accounts', 'commissions', 'Commission Reports', 'View commission reports', 7),
    ('accounts', 'settlement', 'Daily Settlement', 'View daily settlement', 8),
    ('accounts', 'handover_queue', 'Handover Queue', 'Process cash handovers', 9),
    ('accounts', 'staff_cash_control', 'Staff Cash Control', 'Monitor staff cash', 10),
    ('accounts', 'staff_transfers', 'Staff Transfers', 'Process staff transfers', 11),
    ('accounts', 'invoice_queue', 'Invoice Queue', 'Process invoice queue', 12),
    ('accounts', 'acc_recharges', 'Recharge History (View)', 'View recharge history', 13);

-- ── Engage Module ──────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO permissions (module, action, label, description, sort_order) VALUES
    ('engage', 'lifecycle', 'Customer Lifecycle', 'View customer lifecycle dashboard', 1),
    ('engage', 'conversations', 'Conversations', 'View WhatsApp conversations', 2),
    ('engage', 'message_log', 'Message Log', 'View sent message history', 3),
    ('engage', 'engage_wa_leads', 'WA Leads (Engage)', 'View WhatsApp leads', 4),
    ('engage', 'engage_settings', 'Engage Settings', 'Configure WhatsApp integration', 5);

-- ── Admin Module ───────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO permissions (module, action, label, description, sort_order) VALUES
    ('admin', 'all_leads', 'All Leads (Admin View)', 'View all leads', 1),
    ('admin', 'wa_leads', 'WA Leads', 'View WhatsApp leads', 2),
    ('admin', 'ceo_dashboard', 'CEO Dashboard', 'Executive overview dashboard', 3),
    ('admin', 'all_apps', 'All Applications', 'View all KYC applications', 4),
    ('admin', 'retailers_mgmt', 'Manage Retailers / Staff', 'Manage users', 5),
    ('admin', 'wallet_admin', 'Wallet Top-up & Admin', 'Approve wallet top-ups', 6),
    ('admin', 'recharge_req', 'Approve Recharge Requests', 'Approve recharge requests', 7),
    ('admin', 'daily_report', 'Daily Report', 'View daily report', 8),
    ('admin', 'activity_log', 'Activity Log', 'View activity log', 9),
    ('admin', 'access_log', 'Access Log / Login History', 'View login history', 10),
    ('admin', 'sync_queue', 'CRM Sync Queue', 'View sync queue', 11),
    ('admin', 'maintenance', 'System Maintenance', 'System maintenance', 12),
    ('admin', 'ucrm_data', 'UCRM Data Sync', 'Sync UCRM data', 13),
    ('admin', 'plans', 'Subscription Plans', 'Manage subscription plans', 14),
    ('admin', 'hardware', 'Hardware Catalog', 'Manage hardware catalog', 15),
    ('admin', 'wa_inbox', 'WA Inbox & Bot', 'WhatsApp inbox and bot', 16),
    ('admin', 'updater', 'Plugin Updater', 'Update plugin', 17),
    ('admin', 'settings', 'System Settings', 'Configure system settings', 18),
    ('admin', 'backup', 'Backup & Restore', 'Backup and restore data', 19),
    ('admin', 'android_app', 'Android App Manager', 'Manage Android app', 20),
    ('admin', 'all_collections', 'All Collections (Admin)', 'View all collections', 21);

-- ── LTE Module ─────────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO permissions (module, action, label, description, sort_order) VALUES
    ('lte', 'lte_dashboard', 'LTE Dashboard', 'View LTE module dashboard', 1),
    ('lte', 'lte_subscribers', 'LTE Subscribers', 'Manage LTE subscribers', 2),
    ('lte', 'lte_renewal', 'LTE Renewal Queue', 'Process LTE renewals', 3),
    ('lte', 'lte_sims', 'SIM Inventory', 'Manage LTE SIM inventory', 4),
    ('lte', 'lte_hardware', 'CPE / Hardware', 'Manage LTE hardware', 5),
    ('lte', 'lte_packages', 'LTE Packages', 'Manage LTE packages', 6);

-- ═══════════════════════════════════════════════════════════════════════════════
-- SEED DATA: Default Roles
-- ═══════════════════════════════════════════════════════════════════════════════

INSERT OR IGNORE INTO roles (name, slug, description, color, icon, is_system, is_staff) VALUES
    ('Admin', 'admin', 'Full system access', '#D41C1C', '🛡️', 1, 1),
    ('Support Leader', 'support_leader', 'Support team lead with management access', '#7c3aed', '👑', 1, 1),
    ('Support', 'support', 'Customer support staff', '#8b5cf6', '🎧', 1, 1),
    ('Sales Staff', 'sales_staff', 'DishNet sales employees - cash creates cashbook entries', '#22c55e', '💼', 1, 1),
    ('Dealer', 'dealer', 'Independent retailers - self-funded wallet', '#3b82f6', '🏪', 1, 0),
    ('Field Agent', 'field_agent', 'Field sales agents', '#14b8a6', '🚗', 1, 1),
    ('Field Accountant', 'field_accountant', 'Field finance staff', '#f59e0b', '📋', 1, 1),
    ('Collection Agent', 'collection', 'Payment collection agents', '#ec4899', '💵', 1, 1),
    ('Accountant', 'accountant', 'Finance and accounts team', '#f59e0b', '📊', 1, 1);

-- ═══════════════════════════════════════════════════════════════════════════════
-- SEED DATA: Role-Permission Mappings (matches existing $ALL_MODULES roles)
-- ═══════════════════════════════════════════════════════════════════════════════

-- Admin gets ALL permissions
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = 'admin';

-- Support Leader
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.slug = 'support_leader' AND (
    (p.module = 'support') OR
    (p.module = 'engage' AND p.action IN ('lifecycle', 'conversations', 'message_log')) OR
    (p.module = 'accounts' AND p.action IN ('cash_declaration', 'cashbook')) OR
    (p.module = 'lte' AND p.action IN ('lte_dashboard', 'lte_subscribers', 'lte_renewal', 'lte_sims', 'lte_hardware'))
);

-- Support
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.slug = 'support' AND (
    (p.module = 'support' AND p.action IN ('support_dash', 'scheduling', 'splynx_my_jobs', 'field_expenses', 'customer_lookup', 'service_status', 'tickets')) OR
    (p.module = 'engage' AND p.action IN ('lifecycle', 'conversations')) OR
    (p.module = 'accounts' AND p.action IN ('cash_declaration', 'cashbook')) OR
    (p.module = 'admin' AND p.action = 'wa_inbox') OR
    (p.module = 'lte' AND p.action IN ('lte_subscribers', 'lte_renewal'))
);

-- Sales Staff (DishNet employees) - same as old 'sales' role
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.slug = 'sales_staff' AND (
    (p.module = 'sales' AND p.action IN ('kyc', 'leads', 'send_quote', 'collect_payment', 'wallet', 'wallet_recharge', 'applications')) OR
    (p.module = 'support' AND p.action = 'scheduling') OR
    (p.module = 'accounts' AND p.action IN ('cash_declaration', 'cashbook')) OR
    (p.module = 'lte' AND p.action = 'lte_renewal')
);

-- Dealer (Independent retailers) - same as old 'sales' but NOT staff
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.slug = 'dealer' AND (
    (p.module = 'sales' AND p.action IN ('kyc', 'leads', 'send_quote', 'collect_payment', 'wallet', 'wallet_recharge', 'applications')) OR
    (p.module = 'support' AND p.action = 'scheduling') OR
    (p.module = 'accounts' AND p.action IN ('cash_declaration', 'cashbook')) OR
    (p.module = 'lte' AND p.action = 'lte_renewal')
);

-- Field Agent
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.slug = 'field_agent' AND (
    (p.module = 'sales' AND p.action IN ('kyc', 'leads', 'collect_payment', 'wallet', 'wallet_recharge', 'applications')) OR
    (p.module = 'support' AND p.action IN ('scheduling', 'field_expenses')) OR
    (p.module = 'accounts' AND p.action IN ('cash_declaration', 'cashbook'))
);

-- Field Accountant
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.slug = 'field_accountant' AND (
    (p.module = 'sales' AND p.action IN ('kyc', 'collect_payment', 'wallet')) OR
    (p.module = 'support' AND p.action = 'scheduling') OR
    (p.module = 'accounts' AND p.action IN ('cash_declaration', 'cashbook'))
);

-- Collection Agent
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.slug = 'collection' AND (
    (p.module = 'sales' AND p.action IN ('collect_payment', 'wallet', 'wallet_recharge', 'applications')) OR
    (p.module = 'support' AND p.action = 'scheduling') OR
    (p.module = 'accounts' AND p.action = 'cash_declaration')
);

-- Accountant
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p 
WHERE r.slug = 'accountant' AND (
    (p.module = 'sales' AND p.action IN ('send_quote', 'quote_logs', 'collect_payment')) OR
    (p.module = 'support' AND p.action = 'scheduling') OR
    (p.module = 'accounts') OR
    (p.module = 'admin' AND p.action = 'all_collections') OR
    (p.module = 'engage' AND p.action IN ('lifecycle', 'message_log'))
);
