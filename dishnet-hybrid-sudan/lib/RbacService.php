<?php
/**
 * RbacService — Role-Based Access Control
 * 
 * Manages roles, permissions, and access checks.
 * 
 * Key Features:
 * - Dynamic roles with granular permissions
 * - is_staff flag for company employees (creates cashbook on cash KYC)
 * - Permission caching for performance
 * 
 * @package DishNet Hybrid Telecom
 * @since v4.8.56
 */

class RbacService
{
    private PDO $pdo;
    private array $permissionCache = [];
    private array $roleCache = [];
    
    // ═══════════════════════════════════════════════════════════════════════════
    // CONSTRUCTOR
    // ═══════════════════════════════════════════════════════════════════════════
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }
    
    /**
     * Run migration if tables don't exist
     */
    private function ensureTables(): void
    {
        try {
            $this->pdo->query("SELECT 1 FROM roles LIMIT 1");
            return; // Tables exist
        } catch (Throwable $e) {
            // Tables don't exist - create them
        }
        
        // Create roles table
        $this->pdo->exec("
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
            )
        ");
        
        // Create permissions table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS permissions (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                module          TEXT NOT NULL,
                action          TEXT NOT NULL,
                label           TEXT NOT NULL,
                description     TEXT,
                sort_order      INTEGER DEFAULT 0,
                UNIQUE(module, action)
            )
        ");
        
        // Create role_permissions junction table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS role_permissions (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                role_id         INTEGER NOT NULL,
                permission_id   INTEGER NOT NULL,
                created_at      TEXT DEFAULT (datetime('now')),
                UNIQUE(role_id, permission_id),
                FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
            )
        ");
        
        // Create indexes
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_roles_slug ON roles(slug)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_permissions_module ON permissions(module)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_role_perms_role ON role_permissions(role_id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_role_perms_perm ON role_permissions(permission_id)");
        
        // Seed default roles
        $this->seedRoles();
        
        // Seed permissions
        $this->seedPermissions();
        
        // Seed role-permission mappings
        $this->seedRolePermissions();
    }
    
    /**
     * Seed default roles
     */
    private function seedRoles(): void
    {
        $roles = [
            ['Admin', 'admin', 'Full system access', '#D41C1C', '🛡️', 1, 1],
            ['Support Leader', 'support_leader', 'Support team lead with management access', '#7c3aed', '👑', 1, 1],
            ['Support', 'support', 'Customer support staff', '#8b5cf6', '🎧', 1, 1],
            ['Sales Staff', 'sales_staff', 'DishNet sales employees - cash creates cashbook entries', '#22c55e', '💼', 1, 1],
            ['Dealer', 'dealer', 'Independent retailers - self-funded wallet', '#3b82f6', '🏪', 1, 0],
            ['Field Agent', 'field_agent', 'Field sales agents', '#14b8a6', '🚗', 1, 1],
            ['Field Accountant', 'field_accountant', 'Field finance staff', '#f59e0b', '📋', 1, 1],
            ['Collection Agent', 'collection', 'Payment collection agents', '#ec4899', '💵', 1, 1],
            ['Accountant', 'accountant', 'Finance and accounts team', '#f59e0b', '📊', 1, 1],
        ];
        
        $stmt = $this->pdo->prepare("
            INSERT OR IGNORE INTO roles (name, slug, description, color, icon, is_system, is_staff) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($roles as $r) {
            $stmt->execute($r);
        }
    }
    
    /**
     * Seed all permissions (matches $ALL_MODULES)
     */
    private function seedPermissions(): void
    {
        $perms = [
            // Sales
            ['sales', 'kyc', 'KYC / Add Customer', 'Register new customers', 1],
            ['sales', 'leads', 'My Leads', 'View and manage leads', 2],
            ['sales', 'send_quote', 'Send Quotation', 'Send quotes', 3],
            ['sales', 'quote_logs', 'Quote History', 'View quote history', 4],
            ['sales', 'collect_payment', 'Collect Payment', 'Collect payments', 5],
            ['sales', 'wallet', 'My Wallet & Passbook', 'View wallet', 6],
            ['sales', 'wallet_recharge', 'Request Wallet Recharge', 'Request top-ups', 7],
            ['sales', 'applications', 'My Applications', 'View applications', 8],
            
            // Support
            ['support', 'support_dash', 'Support Dashboard', 'View support dashboard', 1],
            ['support', 'scheduling', 'My Jobs (Scheduling)', 'View scheduled jobs', 2],
            ['support', 'bulk_dispatch', 'Bulk Job Dispatch', 'Dispatch jobs in bulk', 3],
            ['support', 'live_map', 'Live Staff Map', 'View staff locations', 4],
            ['support', 'splynx_noc', 'Splynx NOC Dashboard', 'View Splynx NOC', 5],
            ['support', 'splynx_my_jobs', 'My Install Jobs (Splynx)', 'View Splynx jobs', 6],
            ['support', 'route_manager', 'Route Manager', 'Manage routes', 7],
            ['support', 'support_leader_manual', 'Support Leader Manual', 'View manual', 8],
            ['support', 'field_expenses', 'Field Expenses', 'Submit expenses', 9],
            ['support', 'customer_lookup', 'Customer Lookup', 'Search customers', 10],
            ['support', 'service_status', 'Service Status Check', 'Check status', 11],
            ['support', 'tickets', 'Support Tickets', 'Manage tickets', 12],
            
            // Accounts
            ['accounts', 'cash_declaration', 'Cash Declaration', 'Submit declarations', 1],
            ['accounts', 'cashbook', 'Cashbook (USD & SSP)', 'View cashbook', 2],
            ['accounts', 'accounts_dash', 'Accounts Dashboard', 'View dashboard', 3],
            ['accounts', 'balance_identity', 'Balance Identity', 'View balance', 4],
            ['accounts', 'collections', 'All Collections Report', 'View collections', 5],
            ['accounts', 'ledger', 'Retailer Ledger', 'View ledger', 6],
            ['accounts', 'commissions', 'Commission Reports', 'View commissions', 7],
            ['accounts', 'settlement', 'Daily Settlement', 'View settlement', 8],
            ['accounts', 'handover_queue', 'Handover Queue', 'Process handovers', 9],
            ['accounts', 'staff_cash_control', 'Staff Cash Control', 'Monitor cash', 10],
            ['accounts', 'staff_transfers', 'Staff Transfers', 'Process transfers', 11],
            ['accounts', 'invoice_queue', 'Invoice Queue', 'Process invoices', 12],
            ['accounts', 'acc_recharges', 'Recharge History (View)', 'View history', 13],
            
            // Engage
            ['engage', 'lifecycle', 'Customer Lifecycle', 'View lifecycle', 1],
            ['engage', 'conversations', 'Conversations', 'View conversations', 2],
            ['engage', 'message_log', 'Message Log', 'View messages', 3],
            ['engage', 'engage_wa_leads', 'WA Leads (Engage)', 'View WA leads', 4],
            ['engage', 'engage_settings', 'Engage Settings', 'Configure settings', 5],
            
            // Admin
            ['admin', 'all_leads', 'All Leads (Admin View)', 'View all leads', 1],
            ['admin', 'wa_leads', 'WA Leads', 'View WA leads', 2],
            ['admin', 'ceo_dashboard', 'CEO Dashboard', 'Executive overview', 3],
            ['admin', 'all_apps', 'All Applications', 'View all apps', 4],
            ['admin', 'retailers_mgmt', 'Manage Retailers / Staff', 'Manage users', 5],
            ['admin', 'wallet_admin', 'Wallet Top-up & Admin', 'Wallet admin', 6],
            ['admin', 'recharge_req', 'Approve Recharge Requests', 'Approve recharges', 7],
            ['admin', 'daily_report', 'Daily Report', 'View report', 8],
            ['admin', 'activity_log', 'Activity Log', 'View activity', 9],
            ['admin', 'access_log', 'Access Log / Login History', 'View logins', 10],
            ['admin', 'sync_queue', 'CRM Sync Queue', 'View sync queue', 11],
            ['admin', 'maintenance', 'System Maintenance', 'Maintenance', 12],
            ['admin', 'ucrm_data', 'UCRM Data Sync', 'Sync data', 13],
            ['admin', 'plans', 'Subscription Plans', 'Manage plans', 14],
            ['admin', 'hardware', 'Hardware Catalog', 'Manage hardware', 15],
            ['admin', 'wa_inbox', 'WA Inbox & Bot', 'WA inbox', 16],
            ['admin', 'updater', 'Plugin Updater', 'Update plugin', 17],
            ['admin', 'settings', 'System Settings', 'Configure settings', 18],
            ['admin', 'backup', 'Backup & Restore', 'Backup data', 19],
            ['admin', 'android_app', 'Android App Manager', 'Manage app', 20],
            ['admin', 'all_collections', 'All Collections (Admin)', 'View all collections', 21],
            
            // LTE
            ['lte', 'lte_dashboard', 'LTE Dashboard', 'View LTE dashboard', 1],
            ['lte', 'lte_subscribers', 'LTE Subscribers', 'Manage subscribers', 2],
            ['lte', 'lte_renewal', 'LTE Renewal Queue', 'Process renewals', 3],
            ['lte', 'lte_sims', 'SIM Inventory', 'Manage SIMs', 4],
            ['lte', 'lte_hardware', 'CPE / Hardware', 'Manage hardware', 5],
            ['lte', 'lte_packages', 'LTE Packages', 'Manage packages', 6],
        ];
        
        $stmt = $this->pdo->prepare("
            INSERT OR IGNORE INTO permissions (module, action, label, description, sort_order) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($perms as $p) {
            $stmt->execute($p);
        }
    }
    
    /**
     * Seed role-permission mappings
     */
    private function seedRolePermissions(): void
    {
        // Define which permissions each role gets
        $mappings = [
            'admin' => '*', // All permissions
            
            'support_leader' => [
                'support' => '*', // All support
                'engage' => ['lifecycle', 'conversations', 'message_log'],
                'accounts' => ['cash_declaration', 'cashbook'],
                'lte' => ['lte_dashboard', 'lte_subscribers', 'lte_renewal', 'lte_sims', 'lte_hardware'],
            ],
            
            'support' => [
                'support' => ['support_dash', 'scheduling', 'splynx_my_jobs', 'field_expenses', 'customer_lookup', 'service_status', 'tickets'],
                'engage' => ['lifecycle', 'conversations'],
                'accounts' => ['cash_declaration', 'cashbook'],
                'admin' => ['wa_inbox'],
                'lte' => ['lte_subscribers', 'lte_renewal'],
            ],
            
            'sales_staff' => [
                'sales' => ['kyc', 'leads', 'send_quote', 'collect_payment', 'wallet', 'wallet_recharge', 'applications'],
                'support' => ['scheduling'],
                'accounts' => ['cash_declaration', 'cashbook'],
                'lte' => ['lte_renewal'],
            ],
            
            'dealer' => [
                'sales' => ['kyc', 'leads', 'send_quote', 'collect_payment', 'wallet', 'wallet_recharge', 'applications'],
                'support' => ['scheduling'],
                'accounts' => ['cash_declaration', 'cashbook'],
                'lte' => ['lte_renewal'],
            ],
            
            'field_agent' => [
                'sales' => ['kyc', 'leads', 'collect_payment', 'wallet', 'wallet_recharge', 'applications'],
                'support' => ['scheduling', 'field_expenses'],
                'accounts' => ['cash_declaration', 'cashbook'],
            ],
            
            'field_accountant' => [
                'sales' => ['kyc', 'collect_payment', 'wallet'],
                'support' => ['scheduling'],
                'accounts' => ['cash_declaration', 'cashbook'],
            ],
            
            'collection' => [
                'sales' => ['collect_payment', 'wallet', 'wallet_recharge', 'applications'],
                'support' => ['scheduling'],
                'accounts' => ['cash_declaration'],
            ],
            
            'accountant' => [
                'sales' => ['send_quote', 'quote_logs', 'collect_payment'],
                'support' => ['scheduling'],
                'accounts' => '*', // All accounts
                'admin' => ['all_collections'],
                'engage' => ['lifecycle', 'message_log'],
            ],
        ];
        
        foreach ($mappings as $roleSlug => $modules) {
            $stmtRole = $this->pdo->prepare("SELECT id FROM roles WHERE slug = ?");
            $stmtRole->execute([$roleSlug]);
            $roleId = $stmtRole->fetchColumn();
            if (!$roleId) continue;
            $roleId = (int)$roleId;
            
            if ($modules === '*') {
                // Grant all permissions
                $stmtAll = $this->pdo->prepare("
                    INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                    SELECT ?, id FROM permissions
                ");
                $stmtAll->execute([$roleId]);
            } else {
                foreach ($modules as $module => $actions) {
                    if ($actions === '*') {
                        // Grant all permissions in this module
                        $stmtMod = $this->pdo->prepare("
                            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                            SELECT ?, id FROM permissions WHERE module = ?
                        ");
                        $stmtMod->execute([$roleId, $module]);
                    } else {
                        // Grant specific actions
                        $placeholders = implode(',', array_fill(0, count($actions), '?'));
                        $stmtAct = $this->pdo->prepare("
                            INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
                            SELECT ?, id FROM permissions WHERE module = ? AND action IN ({$placeholders})
                        ");
                        $stmtAct->execute(array_merge([$roleId, $module], $actions));
                    }
                }
            }
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // PERMISSION CHECKS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Check if a role has a specific permission
     * 
     * @param int|string $roleIdOrSlug Role ID or slug
     * @param string $module Module name (e.g., 'sales')
     * @param string $action Action name (e.g., 'kyc_form')
     * @return bool
     */
    public function can($roleIdOrSlug, string $module, string $action): bool
    {
        $roleId = $this->resolveRoleId($roleIdOrSlug);
        if (!$roleId) return false;
        
        // Check cache
        $cacheKey = "{$roleId}:{$module}:{$action}";
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }
        
        $sql = "
            SELECT COUNT(*) FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = ? AND p.module = ? AND p.action = ?
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$roleId, $module, $action]);
        $result = (int)$stmt->fetchColumn() > 0;
        
        $this->permissionCache[$cacheKey] = $result;
        return $result;
    }
    
    /**
     * Check permission using legacy format (module ID from $ALL_MODULES)
     * Maps old module IDs to new module:action format
     * 
     * @param int|string $roleIdOrSlug
     * @param string $legacyPermission
     * @return bool
     */
    public function canLegacy($roleIdOrSlug, string $legacyPermission): bool
    {
        // Map legacy permission names to module:action
        // This matches exactly the 'id' field in $ALL_MODULES
        $mapping = [
            // Sales
            'kyc'             => ['sales', 'kyc'],
            'leads'           => ['sales', 'leads'],
            'send_quote'      => ['sales', 'send_quote'],
            'quote_logs'      => ['sales', 'quote_logs'],
            'collect_payment' => ['sales', 'collect_payment'],
            'wallet'          => ['sales', 'wallet'],
            'wallet_recharge' => ['sales', 'wallet_recharge'],
            'applications'    => ['sales', 'applications'],
            
            // Support
            'support_dash'    => ['support', 'support_dash'],
            'scheduling'      => ['support', 'scheduling'],
            'bulk_dispatch'   => ['support', 'bulk_dispatch'],
            'live_map'        => ['support', 'live_map'],
            'splynx_noc'      => ['support', 'splynx_noc'],
            'splynx_my_jobs'  => ['support', 'splynx_my_jobs'],
            'route_manager'   => ['support', 'route_manager'],
            'support_leader_manual' => ['support', 'support_leader_manual'],
            'field_expenses'  => ['support', 'field_expenses'],
            'customer_lookup' => ['support', 'customer_lookup'],
            'service_status'  => ['support', 'service_status'],
            'tickets'         => ['support', 'tickets'],
            
            // Accounts
            'cash_declaration'   => ['accounts', 'cash_declaration'],
            'cashbook'           => ['accounts', 'cashbook'],
            'accounts_dash'      => ['accounts', 'accounts_dash'],
            'balance_identity'   => ['accounts', 'balance_identity'],
            'collections'        => ['accounts', 'collections'],
            'ledger'             => ['accounts', 'ledger'],
            'commissions'        => ['accounts', 'commissions'],
            'settlement'         => ['accounts', 'settlement'],
            'handover_queue'     => ['accounts', 'handover_queue'],
            'staff_cash_control' => ['accounts', 'staff_cash_control'],
            'staff_transfers'    => ['accounts', 'staff_transfers'],
            'invoice_queue'      => ['accounts', 'invoice_queue'],
            'acc_recharges'      => ['accounts', 'acc_recharges'],
            
            // Engage
            'lifecycle'       => ['engage', 'lifecycle'],
            'conversations'   => ['engage', 'conversations'],
            'message_log'     => ['engage', 'message_log'],
            'engage_wa_leads' => ['engage', 'engage_wa_leads'],
            'engage_settings' => ['engage', 'engage_settings'],
            
            // Admin
            'all_leads'       => ['admin', 'all_leads'],
            'wa_leads'        => ['admin', 'wa_leads'],
            'ceo_dashboard'   => ['admin', 'ceo_dashboard'],
            'all_apps'        => ['admin', 'all_apps'],
            'retailers_mgmt'  => ['admin', 'retailers_mgmt'],
            'wallet_admin'    => ['admin', 'wallet_admin'],
            'recharge_req'    => ['admin', 'recharge_req'],
            'daily_report'    => ['admin', 'daily_report'],
            'activity_log'    => ['admin', 'activity_log'],
            'access_log'      => ['admin', 'access_log'],
            'sync_queue'      => ['admin', 'sync_queue'],
            'maintenance'     => ['admin', 'maintenance'],
            'ucrm_data'       => ['admin', 'ucrm_data'],
            'plans'           => ['admin', 'plans'],
            'hardware'        => ['admin', 'hardware'],
            'wa_inbox'        => ['admin', 'wa_inbox'],
            'updater'         => ['admin', 'updater'],
            'settings'        => ['admin', 'settings'],
            'backup'          => ['admin', 'backup'],
            'android_app'     => ['admin', 'android_app'],
            'all_collections'      => ['admin', 'all_collections'],
            
            // Starlink Block Manager — accountant + support_leader get view+unblock
            'starlink_pauses'      => ['admin', 'starlink_pauses'],
            'starlink_suspensions' => ['admin', 'starlink_suspensions'],
            
            // LTE
            'lte_dashboard'   => ['lte', 'lte_dashboard'],
            'lte_subscribers' => ['lte', 'lte_subscribers'],
            'lte_renewal'     => ['lte', 'lte_renewal'],
            'lte_sims'        => ['lte', 'lte_sims'],
            'lte_hardware'    => ['lte', 'lte_hardware'],
            'lte_packages'    => ['lte', 'lte_packages'],
            
            // Legacy aliases (old names that might still be used)
            'kyc_form'        => ['sales', 'kyc'],
            'leads_mgmt'      => ['sales', 'leads'],
            'quotes'          => ['sales', 'send_quote'],
            'handover'        => ['accounts', 'handover_queue'],
            'all_cashbooks'   => ['accounts', 'staff_cash_control'],
        ];
        
        if (!isset($mapping[$legacyPermission])) {
            return false;
        }
        
        // If roleIdOrSlug is a legacy role string, map it to new RBAC slug
        if (!is_numeric($roleIdOrSlug)) {
            $roleIdOrSlug = $this->mapLegacyRole($roleIdOrSlug);
        }
        
        [$module, $action] = $mapping[$legacyPermission];
        return $this->can($roleIdOrSlug, $module, $action);
    }
    
    /**
     * Get all permissions for a role (for UI display)
     * 
     * @param int|string $roleIdOrSlug
     * @return array [module => [action => true, ...], ...]
     */
    public function getPermissions($roleIdOrSlug): array
    {
        $roleId = $this->resolveRoleId($roleIdOrSlug);
        if (!$roleId) return [];
        
        $sql = "
            SELECT p.module, p.action FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = ?
            ORDER BY p.module, p.sort_order
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$roleId]);
        
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['module']][$row['action']] = true;
        }
        return $result;
    }
    
    /**
     * Check if a role is staff (company employee)
     * Staff roles create cb_ledger entries on cash KYC
     * 
     * @param int|string $roleIdOrSlug
     * @return bool
     */
    public function isStaff($roleIdOrSlug): bool
    {
        // If it's a legacy role string, map it first
        if (!is_numeric($roleIdOrSlug)) {
            $mapped = $this->mapLegacyRole($roleIdOrSlug);
            $role = $this->getRole($mapped);
            
            // If role not in DB, use legacy definition
            if (!$role) {
                $legacyRoles = $this->getLegacyRoles();
                return $legacyRoles[$roleIdOrSlug]['is_staff'] ?? false;
            }
        } else {
            $role = $this->getRole($roleIdOrSlug);
        }
        
        return $role && (bool)($role['is_staff'] ?? false);
    }
    
    /**
     * Check if a role is admin
     * 
     * @param int|string $roleIdOrSlug
     * @return bool
     */
    public function isAdmin($roleIdOrSlug): bool
    {
        // If it's a legacy role string, check directly
        if (!is_numeric($roleIdOrSlug)) {
            if ($roleIdOrSlug === 'admin') return true;
            $mapped = $this->mapLegacyRole($roleIdOrSlug);
            if ($mapped === 'admin') return true;
        }
        
        $role = $this->getRole($roleIdOrSlug);
        return $role && $role['slug'] === 'admin';
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // ROLE CRUD
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Get all roles
     */
    public function getAllRoles(bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM roles";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY is_system DESC, name ASC";
        
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get single role by ID or slug
     */
    public function getRole($idOrSlug): ?array
    {
        // Check cache
        $cacheKey = (string)$idOrSlug;
        if (isset($this->roleCache[$cacheKey])) {
            return $this->roleCache[$cacheKey];
        }
        
        if (is_numeric($idOrSlug)) {
            $stmt = $this->pdo->prepare("SELECT * FROM roles WHERE id = ?");
            $stmt->execute([(int)$idOrSlug]);
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM roles WHERE slug = ?");
            $stmt->execute([$idOrSlug]);
        }
        
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($role) {
            $this->roleCache[$cacheKey] = $role;
            $this->roleCache[(string)$role['id']] = $role;
            $this->roleCache[$role['slug']] = $role;
        }
        return $role ?: null;
    }
    
    /**
     * Resolve role ID from ID or slug
     */
    private function resolveRoleId($idOrSlug): ?int
    {
        if (is_numeric($idOrSlug)) {
            return (int)$idOrSlug;
        }
        $role = $this->getRole($idOrSlug);
        return $role ? (int)$role['id'] : null;
    }
    
    /**
     * Create a new role
     */
    public function createRole(array $data): ?int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO roles (name, slug, description, color, icon, is_system, is_staff, is_active, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
        ");
        
        $slug = $data['slug'] ?? $this->slugify($data['name']);
        
        $stmt->execute([
            $data['name'],
            $slug,
            $data['description'] ?? '',
            $data['color'] ?? '#6b7280',
            $data['icon'] ?? '👤',
            0, // is_system - custom roles are not system
            $data['is_staff'] ?? 0,
            1, // is_active
            $data['created_by'] ?? 'system',
        ]);
        
        $roleId = (int)$this->pdo->lastInsertId();
        
        // Set permissions if provided
        if (!empty($data['permissions'])) {
            $this->setPermissions($roleId, $data['permissions']);
        }
        
        $this->clearCache();
        return $roleId;
    }
    
    /**
     * Update a role
     */
    public function updateRole(int $id, array $data): bool
    {
        $role = $this->getRole($id);
        if (!$role) return false;
        
        // Cannot change slug of system roles
        $slug = $role['is_system'] ? $role['slug'] : ($data['slug'] ?? $role['slug']);
        
        $stmt = $this->pdo->prepare("
            UPDATE roles SET
                name = ?,
                slug = ?,
                description = ?,
                color = ?,
                icon = ?,
                is_staff = ?,
                is_active = ?,
                updated_at = datetime('now')
            WHERE id = ?
        ");
        
        $stmt->execute([
            $data['name'] ?? $role['name'],
            $slug,
            $data['description'] ?? $role['description'],
            $data['color'] ?? $role['color'],
            $data['icon'] ?? $role['icon'],
            $data['is_staff'] ?? $role['is_staff'],
            $data['is_active'] ?? $role['is_active'],
            $id,
        ]);
        
        // Update permissions if provided
        if (isset($data['permissions'])) {
            $this->setPermissions($id, $data['permissions']);
        }
        
        $this->clearCache();
        return true;
    }
    
    /**
     * Delete a role (only non-system roles)
     */
    public function deleteRole(int $id): bool
    {
        $role = $this->getRole($id);
        if (!$role || $role['is_system']) {
            return false;
        }
        
        $stmt = $this->pdo->prepare("DELETE FROM roles WHERE id = ? AND is_system = 0");
        $stmt->execute([$id]);
        
        // Also delete role_permissions
        $stmt = $this->pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $stmt->execute([$id]);
        
        $this->clearCache();
        return true;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // PERMISSIONS MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Get all available permissions grouped by module
     */
    public function getAllPermissions(): array
    {
        $sql = "SELECT * FROM permissions ORDER BY module, sort_order";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['module']][] = $row;
        }
        return $grouped;
    }
    
    /**
     * Set permissions for a role (replaces existing)
     * 
     * @param int $roleId
     * @param array $permissions Array of permission IDs or [module => [action, action, ...]]
     */
    public function setPermissions(int $roleId, array $permissions): void
    {
        // Clear existing permissions
        $stmt = $this->pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $stmt->execute([$roleId]);
        
        if (empty($permissions)) return;
        
        // Check format: flat array of IDs or nested module => actions
        $firstKey = array_key_first($permissions);
        
        if (is_string($firstKey)) {
            // Nested format: ['sales' => ['kyc_form', 'leads'], 'support' => ['dashboard']]
            $permIds = [];
            foreach ($permissions as $module => $actions) {
                foreach ((array)$actions as $action) {
                    $stmt = $this->pdo->prepare("SELECT id FROM permissions WHERE module = ? AND action = ?");
                    $stmt->execute([$module, $action]);
                    $permId = $stmt->fetchColumn();
                    if ($permId) $permIds[] = (int)$permId;
                }
            }
        } else {
            // Flat format: [1, 2, 3, 4] (permission IDs)
            $permIds = array_map('intval', $permissions);
        }
        
        // Insert new permissions
        $stmt = $this->pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($permIds as $permId) {
            $stmt->execute([$roleId, $permId]);
        }
        
        $this->clearCache();
    }
    
    /**
     * Add a single permission to a role
     */
    public function grantPermission(int $roleId, string $module, string $action): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM permissions WHERE module = ? AND action = ?");
        $stmt->execute([$module, $action]);
        $permId = $stmt->fetchColumn();
        
        if (!$permId) return false;
        
        $stmt = $this->pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        $stmt->execute([$roleId, $permId]);
        
        $this->clearCache();
        return true;
    }
    
    /**
     * Remove a single permission from a role
     */
    public function revokePermission(int $roleId, string $module, string $action): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM permissions WHERE module = ? AND action = ?");
        $stmt->execute([$module, $action]);
        $permId = $stmt->fetchColumn();
        
        if (!$permId) return false;
        
        $stmt = $this->pdo->prepare("DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?");
        $stmt->execute([$roleId, $permId]);
        
        $this->clearCache();
        return true;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // ROLE MAPPING (Legacy → New)
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Map old role string to new role slug
     * Covers all roles from existing $ALL_MODULES
     */
    public function mapLegacyRole(string $oldRole): string
    {
        $mapping = [
            'admin'           => 'admin',
            'support_leader'  => 'support_leader',
            'support'         => 'support',
            'sales'           => 'dealer',  // Default: assume independent retailer
            'sales_staff'     => 'sales_staff',
            'field_agent'     => 'field_agent',
            'field_accountant'=> 'field_accountant',
            'collection'      => 'collection',
            'accountant'      => 'accountant',
            'employee'        => 'sales_staff',  // Map employee to sales_staff
            'dealer'          => 'dealer',
        ];
        
        return $mapping[$oldRole] ?? 'dealer';
    }
    
    /**
     * Get role ID for legacy role string
     */
    public function getRoleIdForLegacy(string $oldRole): ?int
    {
        $slug = $this->mapLegacyRole($oldRole);
        $role = $this->getRole($slug);
        return $role ? (int)$role['id'] : null;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // USER COUNTS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Count users per role (from retailers.json)
     * Requires store instance to be passed
     */
    public function getUserCountsByRole($store): array
    {
        $retailers = $store->load('retailers.json') ?? [];
        $counts = [];
        
        foreach ($retailers as $r) {
            $roleId = $r['role_id'] ?? null;
            $roleSlug = $r['role'] ?? 'sales';
            
            // Use role_id if set, otherwise map from legacy role
            if ($roleId) {
                $key = "id:{$roleId}";
            } else {
                $key = "slug:" . $this->mapLegacyRole($roleSlug);
            }
            
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        
        // Resolve to role names
        $result = [];
        foreach ($this->getAllRoles(false) as $role) {
            $idKey = "id:{$role['id']}";
            $slugKey = "slug:{$role['slug']}";
            $count = ($counts[$idKey] ?? 0) + ($counts[$slugKey] ?? 0);
            $result[$role['id']] = [
                'role' => $role,
                'count' => $count,
            ];
        }
        
        return $result;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Generate slug from name
     */
    private function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        return $slug;
    }
    
    /**
     * Clear permission cache
     */
    public function clearCache(): void
    {
        $this->permissionCache = [];
        $this->roleCache = [];
    }
    
    /**
     * Get module labels for UI
     */
    public function getModuleLabels(): array
    {
        return [
            'sales'    => ['label' => 'Sales', 'icon' => '💰', 'color' => '#22c55e'],
            'support'  => ['label' => 'Support', 'icon' => '🎧', 'color' => '#8b5cf6'],
            'accounts' => ['label' => 'Accounts', 'icon' => '📊', 'color' => '#f59e0b'],
            'engage'   => ['label' => 'Engage', 'icon' => '💬', 'color' => '#25D366'],
            'admin'    => ['label' => 'Admin', 'icon' => '🛡️', 'color' => '#D41C1C'],
            'lte'      => ['label' => 'LTE', 'icon' => '📶', 'color' => '#7c3aed'],
        ];
    }
    
    /**
     * Get all legacy roles for compatibility
     */
    public function getLegacyRoles(): array
    {
        return [
            'admin'           => ['label' => 'Admin', 'is_staff' => true],
            'support_leader'  => ['label' => 'Support Leader', 'is_staff' => true],
            'support'         => ['label' => 'Support', 'is_staff' => true],
            'sales'           => ['label' => 'Sales / Retailer', 'is_staff' => false],
            'sales_staff'     => ['label' => 'Sales Staff', 'is_staff' => true],
            'field_agent'     => ['label' => 'Field Agent', 'is_staff' => true],
            'field_accountant'=> ['label' => 'Field Accountant', 'is_staff' => true],
            'collection'      => ['label' => 'Collection Agent', 'is_staff' => true],
            'accountant'      => ['label' => 'Accountant', 'is_staff' => true],
            'dealer'          => ['label' => 'Dealer', 'is_staff' => false],
        ];
    }
}
