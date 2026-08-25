<?php
declare(strict_types=1);

/**
 * migrate_tickets_data.php — One-time data migration
 * DishNet Hybrid v3.8 — Phase 2
 *
 * Populates the normalized `tickets` table from the existing
 * splynx_tickets.json blob stored in SqliteStore.
 *
 * ── When to run ──────────────────────────────────────────────────────────
 *   After deploying v3.8 (which creates the tickets table via migration 003).
 *   Run ONCE from the server:
 *     php /path/to/plugin/migrations/migrate_tickets_data.php
 *
 *   Or trigger via the admin maintenance tab.
 *
 * ── Safety ───────────────────────────────────────────────────────────────
 *   - Uses INSERT OR REPLACE: safe to run multiple times
 *   - Does NOT delete the JSON blob (dual-write continues)
 *   - Wraps in a transaction for atomicity
 *   - Reports how many records were migrated
 *
 * PHP 7.4 compatible.
 */

chdir(dirname(__DIR__));

require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/SqliteStore.php';

$dataDir = __DIR__ . '/../data';
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();

echo "DishNet Hybrid — Ticket Data Migration\n";
echo "═══════════════════════════════════════\n\n";

// ── Check that the tickets table exists ─────────────────────────────────
$tableExists = $pdo->query(
    "SELECT name FROM sqlite_master WHERE type='table' AND name='tickets'"
)->fetch();

if (!$tableExists) {
    echo "ERROR: 'tickets' table does not exist.\n";
    echo "Run migration 003_tickets_table.sql first.\n";
    exit(1);
}

// ── Load existing ticket data from JSON blob ────────────────────────────
$tickets = $store->load('splynx_tickets.json');
if (empty($tickets)) {
    echo "No tickets found in splynx_tickets.json. Nothing to migrate.\n";
    exit(0);
}

echo "Found " . count($tickets) . " tickets in splynx_tickets.json\n";

// ── Prepare the upsert statement ────────────────────────────────────────
$stmt = $pdo->prepare('
    INSERT OR REPLACE INTO tickets (
        id, app_id, customer_id, crm_client_id,
        customer_name, crm_client_name, address, phone, phone_source, area,
        status, status_label, priority, subject, ftth_number,
        engineer, assigned_engineer_id, assigned_engineer_name, assigned_at,
        install_complete, install_complete_at,
        onu_serial, olt_port, signal_db, install_notes, install_data_at, install_data_by,
        testing_status, commissioned_by, commission_notes,
        ready_at, ready_submitted_by,
        rejected_by, rejection_notes, rejected_at,
        photos, gps_lat, gps_lng,
        status_changed_at, status_changed_by, cancelled,
        crm_enriched_at, crm_enrich_attempted,
        splynx_imported, splynx_ticket_id, splynx_service_id,
        created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?,
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?,
        ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?,
        ?, ?,
        ?, ?, ?,
        ?, ?
    )
');

// ── Migrate in a transaction ────────────────────────────────────────────
$migrated = 0;
$errors   = 0;

$pdo->beginTransaction();

foreach ($tickets as $t) {
    $id = (int)($t['id'] ?? 0);
    if (!$id) { $errors++; continue; }

    try {
        $stmt->execute([
            $id,
            (int)($t['app_id'] ?? 0),
            (int)($t['customer_id'] ?? 0),
            !empty($t['crm_client_id']) ? (int)$t['crm_client_id'] : null,

            $t['customer_name'] ?? '',
            $t['crm_client_name'] ?? '',
            $t['address'] ?? '',
            $t['phone'] ?? '',
            $t['phone_source'] ?? '',
            $t['area'] ?? 'Unknown',

            (int)($t['status'] ?? 1),
            $t['status_label'] ?? 'new',
            $t['priority'] ?? '',
            $t['subject'] ?? '',
            $t['ftth_number'] ?? '',

            $t['engineer'] ?? '',
            $t['assigned_engineer_id'] ?? '',
            $t['assigned_engineer_name'] ?? '',
            $t['assigned_at'] ?? null,

            empty($t['install_complete']) ? 0 : 1,
            $t['install_complete_at'] ?? null,

            $t['onu_serial'] ?? '',
            $t['olt_port'] ?? '',
            isset($t['signal_db']) ? (float)$t['signal_db'] : null,
            $t['install_notes'] ?? '',
            $t['install_data_at'] ?? null,
            $t['install_data_by'] ?? '',

            $t['testing_status'] ?? '',
            $t['commissioned_by'] ?? '',
            $t['commission_notes'] ?? '',
            $t['ready_at'] ?? null,
            $t['ready_submitted_by'] ?? '',
            $t['rejected_by'] ?? '',
            $t['rejection_notes'] ?? '',
            $t['rejected_at'] ?? null,

            is_array($t['photos'] ?? null) ? json_encode($t['photos']) : ($t['photos'] ?? '[]'),
            isset($t['gps_lat']) ? (float)$t['gps_lat'] : null,
            isset($t['gps_lng']) ? (float)$t['gps_lng'] : null,

            $t['status_changed_at'] ?? null,
            $t['status_changed_by'] ?? '',
            empty($t['cancelled']) ? 0 : 1,

            $t['crm_enriched_at'] ?? null,
            $t['crm_enrich_attempted'] ?? null,

            empty($t['splynx_imported']) ? 0 : 1,
            (int)($t['splynx_ticket_id'] ?? $t['id'] ?? 0),
            !empty($t['splynx_service_id']) ? (int)$t['splynx_service_id'] : null,

            $t['created_at'] ?? date('Y-m-d H:i:s'),
            $t['updated_at'] ?? date('Y-m-d H:i:s'),
        ]);
        $migrated++;
    } catch (\Exception $e) {
        echo "  ERROR ticket #{$id}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

$pdo->commit();

// ── Report ──────────────────────────────────────────────────────────────
echo "\nResults:\n";
echo "  Migrated:  {$migrated}\n";
echo "  Errors:    {$errors}\n";
echo "  Total:     " . count($tickets) . "\n\n";

// Verify row count
$count = (int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
echo "Verification: tickets table now has {$count} rows.\n";

if ($migrated > 0 && $errors === 0) {
    echo "\n✅ Migration successful. JSON blob preserved for dual-write period.\n";
} elseif ($errors > 0) {
    echo "\n⚠️ Migration completed with {$errors} errors. Check output above.\n";
}
