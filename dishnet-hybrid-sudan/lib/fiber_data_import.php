<?php
/**
 * fiber_data_import.php — Import Fiber Finance plugin data into Hybrid SQLite tables
 *
 * Usage: Run once from Plugin → Fiber Costs → Dashboard → "Import Fiber Data" button
 * Or via API: ?page=api&action=fiber_import_data
 *
 * Reads from: data/fiber_backup/ (uploaded backup files)
 * Writes to: fiber_services_cache, fiber_customer_map, fiber_plan_costs,
 *            fiber_status_changes, fiber_supplier_invoices, fiber_cost_snapshots
 *
 * Safe: uses INSERT OR IGNORE / ON CONFLICT — won't duplicate existing data.
 */
declare(strict_types=1);

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

function importFiberData(\PDO $db, string $sourceDir): array
{
    $log = [];
    $stats = ['services' => 0, 'customers' => 0, 'plans' => 0, 'status_log' => 0, 'invoices' => 0, 'snapshots' => 0, 'errors' => 0];

    $log[] = 'Import started at ' . date('Y-m-d H:i:s');
    $log[] = "Source: {$sourceDir}";

    // Bootstrap tables if they don't exist (safe — uses IF NOT EXISTS)
    $db->exec("CREATE TABLE IF NOT EXISTS fiber_plan_costs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, supplier TEXT NOT NULL, plan_name TEXT NOT NULL,
        cost_per_unit REAL NOT NULL, revenue REAL DEFAULT 0, partner_share REAL DEFAULT 0,
        profit_mode TEXT DEFAULT 'fixed', crm_plan_name TEXT DEFAULT NULL,
        effective_from TEXT NOT NULL, effective_to TEXT DEFAULT NULL,
        source TEXT DEFAULT 'manual', created_at TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS fiber_services_cache (
        id INTEGER PRIMARY KEY AUTOINCREMENT, splynx_service_id TEXT NOT NULL UNIQUE,
        splynx_customer_id TEXT NOT NULL, customer_name TEXT DEFAULT '', plan_name TEXT DEFAULT '',
        splynx_status TEXT DEFAULT '', crm_status TEXT DEFAULT NULL, crm_client_id TEXT DEFAULT NULL,
        ip_address TEXT DEFAULT '', download_speed TEXT DEFAULT '', upload_speed TEXT DEFAULT '',
        supplier TEXT DEFAULT '', tariff_price REAL DEFAULT 0, last_seen TEXT DEFAULT NULL,
        status_override INTEGER DEFAULT 0, override_reason TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS fiber_customer_map (
        id INTEGER PRIMARY KEY AUTOINCREMENT, splynx_customer_id TEXT NOT NULL UNIQUE,
        splynx_name TEXT DEFAULT '', splynx_email TEXT DEFAULT '', splynx_phone TEXT DEFAULT '',
        crm_client_id TEXT DEFAULT NULL, crm_name TEXT DEFAULT '', linked_by TEXT DEFAULT 'unmatched',
        linked_at TEXT DEFAULT NULL, last_sync TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS fiber_status_changes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, splynx_service_id TEXT NOT NULL,
        old_status TEXT NOT NULL, new_status TEXT NOT NULL,
        changed_at TEXT NOT NULL DEFAULT '', source TEXT DEFAULT 'splynx'
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS fiber_supplier_invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT, supplier TEXT NOT NULL, invoice_number TEXT NOT NULL,
        invoice_date TEXT NOT NULL, billing_period TEXT NOT NULL, total_amount REAL NOT NULL,
        currency TEXT NOT NULL DEFAULT 'USD', expected_amount REAL DEFAULT NULL,
        variance REAL DEFAULT NULL, variance_pct REAL DEFAULT NULL, line_items TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'received', verified_by TEXT DEFAULT NULL, verified_at TEXT DEFAULT NULL,
        approved_by TEXT DEFAULT NULL, approved_at TEXT DEFAULT NULL, paid_at TEXT DEFAULT NULL,
        payment_ref TEXT DEFAULT NULL, cb_entry_id INTEGER DEFAULT NULL, notes TEXT DEFAULT NULL,
        attachment_path TEXT DEFAULT NULL, created_by TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS fiber_cost_snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT, billing_period TEXT NOT NULL UNIQUE,
        snapshot_date TEXT NOT NULL, active_services INTEGER NOT NULL DEFAULT 0,
        expected_cost REAL NOT NULL DEFAULT 0, actual_cost REAL DEFAULT NULL, variance REAL DEFAULT NULL,
        plan_breakdown TEXT DEFAULT NULL, supplier_invoice_id INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT ''
    )");

    // ── 1. Plans (fiber_plans.json → fiber_plan_costs) ──────────────────
    $plansFile = $sourceDir . '/fiber_plans.json';
    if (file_exists($plansFile)) {
        $plans = json_decode(file_get_contents($plansFile), true) ?: [];
        $log[] = 'Plans: ' . count($plans) . ' found';
        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO fiber_plan_costs
                (supplier, plan_name, cost_per_unit, revenue, partner_share, profit_mode, crm_plan_name, effective_from, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'import')"
        );
        foreach ($plans as $p) {
            try {
                $stmt->execute([
                    $p['supplier'] ?? 'Fiber Provider',
                    $p['splynx_plan_name'] ?? '',
                    (float)($p['purchase_cost'] ?? 0),
                    (float)($p['revenue'] ?? 0),
                    (float)($p['partner_share'] ?? 0),
                    $p['profit_mode'] ?? 'fixed',
                    $p['crm_plan_name'] ?? $p['splynx_plan_name'] ?? '',
                    $p['updated_at'] ?? date('Y-m-d'),
                ]);
                $stats['plans']++;
            } catch (\Throwable $e) { $stats['errors']++; }
        }
        $log[] = "Plans imported: {$stats['plans']}";
    }

    // ── 2. Customer mapping (fiber_customer_mapping.json → fiber_customer_map) ──
    $custFile = $sourceDir . '/fiber_customer_mapping.json';
    if (file_exists($custFile)) {
        $custs = json_decode(file_get_contents($custFile), true) ?: [];
        $log[] = 'Customers: ' . count($custs) . ' found';
        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO fiber_customer_map
                (splynx_customer_id, splynx_name, splynx_email, splynx_phone, crm_client_id, crm_name, linked_by, linked_at, last_sync)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($custs as $c) {
            try {
                $crmId = $c['crm_customer_id'] ?? '';
                $stmt->execute([
                    (string)($c['splynx_customer_id'] ?? ''),
                    $c['splynx_name'] ?? '',
                    $c['splynx_email'] ?? '',
                    $c['splynx_phone'] ?? $c['phone'] ?? '',
                    $crmId ?: null,
                    $c['crm_name'] ?? '',
                    $c['linked_by'] ?? ($crmId ? 'import' : 'unmatched'),
                    $c['linked_at'] ?? ($crmId ? date('Y-m-d H:i:s') : null),
                    $c['last_sync'] ?? date('Y-m-d H:i:s'),
                ]);
                $stats['customers']++;
            } catch (\Throwable $e) { $stats['errors']++; }
        }
        $log[] = "Customers imported: {$stats['customers']}";
    }

    // ── 3. Services (fiber_services.json → fiber_services_cache) ────────
    $svcsFile = $sourceDir . '/fiber_services.json';
    if (file_exists($svcsFile)) {
        $svcs = json_decode(file_get_contents($svcsFile), true) ?: [];
        $log[] = 'Services: ' . count($svcs) . ' found';
        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO fiber_services_cache
                (splynx_service_id, splynx_customer_id, customer_name, plan_name, splynx_status,
                 crm_status, crm_client_id, ip_address, download_speed, upload_speed,
                 supplier, tariff_price, last_seen, status_override, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        // Build a status map for Splynx→CRM
        $statusMap = ['active'=>'Active','stopped'=>'Suspended','disabled'=>'Cancelled','hidden'=>'Cancelled','pending'=>'Pending','blocked'=>'Blocked'];

        foreach ($svcs as $s) {
            try {
                $splynxSt = strtolower($s['splynx_status'] ?? $s['status'] ?? '');
                $crmStatus = $s['status'] ?? ($statusMap[$splynxSt] ?? 'Suspended');
                $stmt->execute([
                    (string)($s['service_id'] ?? $s['splynx_id'] ?? ''),
                    (string)($s['customer_id'] ?? ''),
                    $s['customer_name'] ?? '',
                    $s['plan_name'] ?? '',
                    $splynxSt,
                    $crmStatus,
                    (string)($s['crm_customer_id'] ?? ''),
                    $s['ip_address'] ?? '',
                    $s['download_speed'] ?? '',
                    $s['upload_speed'] ?? '',
                    $s['provider'] ?? 'Fiber Provider',
                    (float)($s['real_price_total'] ?? $s['tariff_price'] ?? 0),
                    $s['last_seen'] ?? date('Y-m-d H:i:s'),
                    (int)($s['status_override'] ?? 0),
                    $s['created_at'] ?? date('Y-m-d'),
                    date('Y-m-d H:i:s'),
                ]);
                $stats['services']++;
            } catch (\Throwable $e) { $stats['errors']++; }
        }
        $log[] = "Services imported: {$stats['services']}";
    }

    // ── 4. Status log (fiber_status_log.json → fiber_status_changes) ────
    $statusFile = $sourceDir . '/fiber_status_log.json';
    if (file_exists($statusFile)) {
        $slog = json_decode(file_get_contents($statusFile), true) ?: [];
        $log[] = 'Status log: ' . count($slog) . ' entries found';
        $stmt = $db->prepare(
            "INSERT INTO fiber_status_changes (splynx_service_id, old_status, new_status, changed_at, source)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($slog as $sl) {
            try {
                $stmt->execute([
                    (string)($sl['service_id'] ?? ''),
                    $sl['old_status'] ?? '',
                    $sl['new_status'] ?? '',
                    $sl['changed_at'] ?? date('Y-m-d H:i:s'),
                    $sl['source'] ?? 'import',
                ]);
                $stats['status_log']++;
            } catch (\Throwable $e) { $stats['errors']++; }
        }
        $log[] = "Status changes imported: {$stats['status_log']}";
    }

    // ── 5. Supplier invoices (fiber_supplier_invoices.json → fiber_supplier_invoices) ──
    $invFile = $sourceDir . '/fiber_supplier_invoices.json';
    if (file_exists($invFile)) {
        $invData = json_decode(file_get_contents($invFile), true) ?: [];
        $log[] = 'Invoice periods: ' . count($invData) . ' found';
        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO fiber_supplier_invoices
                (supplier, invoice_number, invoice_date, billing_period, total_amount, currency,
                 line_items, status, notes, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'import', ?)"
        );

        foreach ($invData as $period => $inv) {
            try {
                // Calculate real total from line_total (total_cost is 0 in this data)
                $lineItems = $inv['line_items'] ?? [];
                $total = 0;
                $cleanLines = [];
                foreach ($lineItems as $li) {
                    $lineTotal = (float)($li['line_total'] ?? $li['total_cost'] ?? 0);
                    $total += $lineTotal;
                    $cleanLines[] = [
                        'plan'      => $li['plan_name'] ?? '',
                        'qty'       => 1,
                        'unit_cost' => (float)($li['monthly_rate'] ?? $li['unit_cost'] ?? 0),
                        'total'     => $lineTotal,
                        'customer'  => $li['customer_name'] ?? '',
                        'service_id'=> $li['service_id'] ?? '',
                        'status'    => $li['service_status'] ?? '',
                        'days'      => (int)($li['active_days'] ?? $li['days_active'] ?? 0),
                        'proration' => (float)($li['proration_rate'] ?? 1),
                    ];
                }

                $stmt->execute([
                    '4G Telecom',  // Default supplier from config
                    $inv['invoice_number'] ?? 'SUPP-' . $period,
                    $inv['period_start'] ?? $period . '-01',
                    $period,
                    round($total, 2),
                    $inv['currency'] ?? 'USD',
                    json_encode($cleanLines),
                    $inv['status'] ?? 'received',
                    count($lineItems) . ' service lines imported',
                    date('Y-m-d H:i:s'),
                ]);
                $stats['invoices']++;
            } catch (\Throwable $e) { $stats['errors']++; }
        }
        $log[] = "Invoices imported: {$stats['invoices']}";
    }

    // ── 6. Snapshots (fiber_snapshots.json → fiber_cost_snapshots) ──────
    $snapFile = $sourceDir . '/fiber_snapshots.json';
    if (file_exists($snapFile)) {
        $snaps = json_decode(file_get_contents($snapFile), true) ?: [];
        $log[] = 'Snapshots: ' . count($snaps) . ' found';
        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO fiber_cost_snapshots
                (billing_period, snapshot_date, active_services, expected_cost, plan_breakdown)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($snaps as $period => $snap) {
            if (!is_array($snap)) continue;
            try {
                $stmt->execute([
                    $period,
                    $snap['date'] ?? $period . '-01',
                    (int)($snap['active_services'] ?? 0),
                    (float)($snap['cost'] ?? $snap['expected_cost'] ?? 0),
                    isset($snap['by_plan']) ? json_encode($snap['by_plan']) : null,
                ]);
                $stats['snapshots']++;
            } catch (\Throwable $e) { $stats['errors']++; }
        }
        $log[] = "Snapshots imported: {$stats['snapshots']}";
    }

    $log[] = 'Import completed at ' . date('Y-m-d H:i:s');
    $log[] = "Totals: {$stats['services']} services, {$stats['customers']} customers, {$stats['plans']} plans, " .
             "{$stats['status_log']} status changes, {$stats['invoices']} invoices, {$stats['snapshots']} snapshots, " .
             "{$stats['errors']} errors";

    return ['ok' => true, 'stats' => $stats, 'log' => $log];
}
