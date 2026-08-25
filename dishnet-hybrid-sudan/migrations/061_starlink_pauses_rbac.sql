-- ═══════════════════════════════════════════════════════════════════════════════
-- MIGRATION 061: Add starlink_pauses + starlink_suspensions to RBAC
-- ═══════════════════════════════════════════════════════════════════════════════
-- Purpose: Allow accountant + support_leader to access Starlink Block Manager.
--          Previously admin-only. accountant/support_leader get view + unblock.
--          Block buttons remain admin-only (enforced in PHP/JS layer).
-- Safe to re-run: all INSERT OR IGNORE.
-- ═══════════════════════════════════════════════════════════════════════════════

-- ── 1. Register the permissions (idempotent) ──────────────────────────────────
INSERT OR IGNORE INTO permissions (module, action, label, description, sort_order) VALUES
    ('admin', 'starlink_pauses',      'Starlink Block Manager',  'View paused dishes, unblock customers after payment', 22),
    ('admin', 'starlink_suspensions', 'Starlink Suspensions',    'View full suspension history and audit log', 23);

-- ── 2. Grant to accountant ────────────────────────────────────────────────────
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r, permissions p
WHERE r.slug = 'accountant'
  AND p.module = 'admin'
  AND p.action IN ('starlink_pauses', 'starlink_suspensions');

-- ── 3. Grant to support_leader ────────────────────────────────────────────────
INSERT OR IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r, permissions p
WHERE r.slug = 'support_leader'
  AND p.module = 'admin'
  AND p.action IN ('starlink_pauses', 'starlink_suspensions');
