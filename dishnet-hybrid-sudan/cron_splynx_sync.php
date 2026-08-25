<?php
// Note: No strict_types - included from master.php

/**
 * cron_splynx_sync.php — DishNet Hybrid Telecom
 *
 * Synchronises data between DishNet CRM and Splynx ISP Framework.
 *
 * ── Tasks (runs every 5 minutes via UCRM cron) ───────────────────────────────
 *   1. Sync open installation tickets from Splynx
 *   2. Detect completed installations → notify admin
 *   3. Sync CRM billing statuses → Splynx service enable/disable
 *   4. Update area breakdown stats cache
 *   5. Heartbeat log
 *
 * ── How to register ──────────────────────────────────────────────────────────
 *   UCRM calls main.php every ~5 minutes. To also run this cron:
 *   In kyc_config.json set:
 *     "splynx_cron_path": "<absolute_path_to>/cron_splynx_sync.php"
 *   Then in main.php add:
 *     if (!empty($kycConfig['splynx_cron_path']) && file_exists($kycConfig['splynx_cron_path']))
 *         include $kycConfig['splynx_cron_path'];
 *
 *   OR register as a separate server cron:
 *     * /5 * * * * php /path/to/plugin/cron_splynx_sync.php >> /tmp/splynx_cron.log 2>&1
 */

chdir(__DIR__);
require_once __DIR__ . '/lib/bootstrap_data.php';

$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
$logFile = $dataDir . '/splynx_cron.log';

function splynxLog(string $msg): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    // Trim to last 300 lines
    $lines = file($logFile);
    if (count($lines) > 300) file_put_contents($logFile, implode('', array_slice($lines, -200)));
}

splynxLog('Splynx sync: starting.');

// ── Load dependencies ─────────────────────────────────────────────────────────

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/NotificationService.php';
require_once __DIR__ . '/lib/SplynxApiClient.php';
require_once __DIR__ . '/lib/SplynxTicketService.php';
require_once __DIR__ . '/lib/SplynxCustomerService.php';

$store  = SqliteStore::create($dataDir);
$config = $store->load('kyc_config.json') ?: [];

// ── Ensure Splynx credentials are set (seeded here in case config predates setup) ──
if (empty($config['splynx_url']))     $config['splynx_url']    = 'https://my.dishnetafrica.com';
if (empty($config['splynx_key']))     $config['splynx_key']    = '2b10a77dd91668b95b4355200689b0bc';
if (empty($config['splynx_secret']))  $config['splynx_secret'] = '9174fd8da2d4b7bf35a369cd1a020d45';

// ── Check if Splynx is configured ────────────────────────────────────────────

if (empty($config['splynx_url']) || empty($config['splynx_key'])) {
    splynxLog('Splynx sync: not configured — skipping.');
    return;
}

// ── Throttle: respect sync interval (default 5 min) ─────────────────────────

$syncInterval = max(1, (int)($config['splynx_sync_interval_minutes'] ?? 5));
$lastRunFile  = $dataDir . '/splynx_sync_last_run.txt';
$lastRun      = file_exists($lastRunFile) ? (int)file_get_contents($lastRunFile) : 0;
$elapsedMin   = (time() - $lastRun) / 60;

if ($elapsedMin < $syncInterval) {
    splynxLog("Splynx sync: skipping — next in " . ceil($syncInterval - $elapsedMin) . "m.");
    return;
}

file_put_contents($lastRunFile, (string)time());

// ── Bootstrap services ────────────────────────────────────────────────────────

$splynxClient  = SplynxApiClient::fromConfig($config);
$notify        = new NotificationService($store, $config);
$ticketSvc     = new SplynxTicketService($splynxClient, $store, $notify, $config);
$customerSvc   = new SplynxCustomerService($splynxClient, $ticketSvc, $store, $config);

// ════════════════════════════════════════════════════════════════════════════════
// TASK 1: SYNC INSTALLATION TICKETS
// ════════════════════════════════════════════════════════════════════════════════

splynxLog('Task 1: Syncing installation tickets...');

$ticketSync = $ticketSvc->syncTickets();

if (!$ticketSync['ok']) {
    splynxLog("  ERROR: " . ($ticketSync['error'] ?? 'unknown'));
} else {
    splynxLog("  Synced: {$ticketSync['synced']} tickets, Completed: {$ticketSync['completed']}, Errors: {$ticketSync['errors']}");
}

// ════════════════════════════════════════════════════════════════════════════════
// TASK 2: BILLING STATUS SYNC (CRM → Splynx)
// ════════════════════════════════════════════════════════════════════════════════

splynxLog('Task 2: Syncing billing statuses...');

$billingSync = $customerSvc->syncBillingStatuses();
splynxLog("  Synced: {$billingSync['synced']}, Failed: {$billingSync['failed']}, Skipped: {$billingSync['skipped']}");

// ════════════════════════════════════════════════════════════════════════════════
// TASK 3: AREA STATS CACHE
// ════════════════════════════════════════════════════════════════════════════════

splynxLog('Task 3: Updating area stats cache...');

$areas = $ticketSvc->getAreaBreakdown();
$store->save('splynx_area_stats.json', [
    'updated_at' => date('Y-m-d H:i:s'),
    'areas'      => $areas,
]);
splynxLog("  Area breakdown: " . count($areas) . " areas.");

// ════════════════════════════════════════════════════════════════════════════════
// TASK 3.5: CRM ENRICHMENT — populate ticket address/phone/area from UCRM
// ════════════════════════════════════════════════════════════════════════════════

splynxLog('Task 3.5: CRM enrichment of ticket addresses...');

require_once __DIR__ . '/lib/CrmApiClient.php';
$crmClient = CrmApiClient::fromUcrm(__DIR__, $config);

if ($crmClient->isConfigured()) {
    $enrichResult = $ticketSvc->enrichFromCrm($crmClient, 30);
    splynxLog("  Enriched: {$enrichResult['enriched']}, Skipped: {$enrichResult['skipped']}, Failed: {$enrichResult['failed']}");

    // Rebuild area stats after enrichment (areas may have changed)
    if ($enrichResult['enriched'] > 0) {
        $areas = $ticketSvc->getAreaBreakdown();
        $store->save('splynx_area_stats.json', ['updated_at' => date('Y-m-d H:i:s'), 'areas' => $areas]);
        splynxLog("  Area stats rebuilt after enrichment.");
    }
} else {
    splynxLog('  CRM not configured — skipping enrichment.');
}

// ════════════════════════════════════════════════════════════════════════════════
// TASK 4: DASHBOARD SUMMARY CACHE
// ════════════════════════════════════════════════════════════════════════════════

splynxLog('Task 4: Updating dashboard summary cache...');

$summary = $ticketSvc->getSummary();
$store->save('splynx_dashboard_cache.json', array_merge($summary, [
    'updated_at' => date('Y-m-d H:i:s'),
]));
splynxLog("  Summary — Pending: {$summary['pending']}, In-progress: {$summary['in_progress']}, Completed: {$summary['completed']}, Today: {$summary['today']}");

// ════════════════════════════════════════════════════════════════════════════════
// TASK 5: AUTO-PROVISION NEW FIBER KYC APPLICATIONS
// ════════════════════════════════════════════════════════════════════════════════

$autoProvision = (bool)($config['splynx_auto_provision'] ?? false);

if ($autoProvision) {
    splynxLog('Task 5: Auto-provisioning new Fiber KYC applications...');

    $apps = $store->load('kyc_applications.json') ?? [];
    $provisioned = $failed = $skipped = 0;

    foreach ($apps as $app) {
        // Only Fiber apps that are approved and not yet provisioned in Splynx
        if (($app['customer_type'] ?? '') !== 'Fiber') continue;
        if (!in_array($app['status'] ?? '', ['approved', 'synced'], true)) continue;
        if (!empty($app['splynx_provisioned_at'])) { $skipped++; continue; }

        $result = $customerSvc->provisionFromKyc($app);
        if ($result['ok']) {
            $provisioned++;
            splynxLog("  Provisioned app DN-{$app['id']}: customer={$result['customer_id']}, service={$result['service_id']}, ticket={$result['ticket_id']}");
        } else {
            $failed++;
            splynxLog("  FAILED app DN-{$app['id']}: " . $result['error']);
        }
    }

    splynxLog("  Provisioned: {$provisioned}, Failed: {$failed}, Skipped: {$skipped}");
} else {
    splynxLog('Task 5: Auto-provision disabled (set splynx_auto_provision=true to enable).');
}

// ════════════════════════════════════════════════════════════════════════════════
// TASK 6: FIBER INSTALL INVOICES — detect new active services → invoice + job
// ════════════════════════════════════════════════════════════════════════════════
// PERFORMANCE: Local SQLite check first — 0 API calls when nothing new.
// SAFETY: Watermark ensures old installs never get invoiced retroactively.
//   First run → sets watermark to NOW → skips all existing installs
//   Future runs → only processes installs completed AFTER watermark

splynxLog('Task 6: Checking for new fiber installs needing invoices...');

try {
    // Ensure migration 044 (fiber_collection_jobs table) is applied
    try { $pdo->query("SELECT id FROM fiber_collection_jobs LIMIT 0"); }
    catch (\Throwable $e) {
        $migFile = __DIR__ . '/migrations/044_fiber_collection_jobs.sql';
        if (file_exists($migFile)) {
            foreach (explode(';', file_get_contents($migFile)) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt && strpos($stmt, '--') !== 0) {
                    try { $pdo->exec($stmt); } catch (\Throwable $e2) {}
                }
            }
            splynxLog('  Migration 044 applied.');
        }
    }

    // Ensure migration 048 (crm_job_id column) is applied
    try { $pdo->query("SELECT crm_job_id FROM fiber_collection_jobs LIMIT 0"); }
    catch (\Throwable $e) {
        $mig048 = __DIR__ . '/migrations/048_fiber_crm_job_id.sql';
        if (file_exists($mig048)) {
            foreach (explode(';', file_get_contents($mig048)) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt && strpos($stmt, '--') !== 0) {
                    try { $pdo->exec($stmt); } catch (\Throwable $e2) {}
                }
            }
            splynxLog('  Migration 048 applied (crm_job_id).');
        }
    }

    // ── Watermark: only process installs completed AFTER this timestamp ──
    // First run: set watermark to NOW → all existing installs are grandfathered
    // Config key: fiber_invoice_watermark (ISO datetime)
    $watermark = trim($config['fiber_invoice_watermark'] ?? '');
    if (!$watermark) {
        // First run — set watermark to current time
        $watermark = date('Y-m-d H:i:s');
        $config['fiber_invoice_watermark'] = $watermark;
        // Save to kyc_config
        $kycConf = $store->load('kyc_config.json') ?? [];
        $kycConf['fiber_invoice_watermark'] = $watermark;
        $store->save('kyc_config.json', $kycConf);
        splynxLog("  Watermark set to {$watermark} — existing installs will NOT get invoices.");
        // Nothing to process on first run
    } else {
        require_once __DIR__ . '/lib/FiberInstallService.php';
        $fiberInvSvc = new FiberInstallService($pdo, $store, $config, $dataDir);

        // ── LOCAL CHECK: completed tickets AFTER watermark, without jobs yet ──
        // Uses crm_client_id (populated on 110+ tickets) instead of customer_id (always 0)
        // Also matches by ticket ID to catch tickets without CRM link
        $pendingStmt = $pdo->prepare("
            SELECT t.id, t.customer_id, t.crm_client_id, t.customer_name, t.phone, t.area,
                   t.splynx_service_id, t.install_complete_at
            FROM tickets t
            WHERE t.install_complete = 1
              AND t.cancelled = 0
              AND t.install_complete_at > ?
              AND (t.crm_client_id > 0 OR t.customer_id > 0)
              AND NOT EXISTS (
                  SELECT 1 FROM fiber_collection_jobs fj
                  WHERE fj.ticket_id = t.id
              )
            ORDER BY t.install_complete_at ASC
            LIMIT 5
        ");
        $pendingStmt->execute([$watermark]);
        $pendingTickets = $pendingStmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($pendingTickets)) {
            splynxLog('  No new installs since ' . substr($watermark, 0, 10) . '.');

            // -- TASK 6b: Active-service cross-check --
            // Bidal may create a service in Splynx WITHOUT closing the ticket.
            // This path catches those cases: open ticket + active Splynx service
            // -> auto-mark install_complete + fire delivery note + close ticket.
            // Runs only when normal path finds nothing. Checks up to 10 open tickets
            // that have a Splynx customer ID and no collection job yet.
            // Cap: 3 Splynx API calls per cron run to stay within rate limits.
            splynxLog('  Task 6b: Checking open tickets for active Splynx services...');
            try {
                $openStmt = $pdo->prepare("
                    SELECT t.id, t.customer_id, t.crm_client_id, t.customer_name,
                           t.phone, t.area, t.ftth_number, t.splynx_service_id,
                           t.created_at
                    FROM tickets t
                    WHERE t.install_complete = 0
                      AND t.cancelled = 0
                      AND (t.customer_id > 0 OR t.crm_client_id > 0)
                      AND NOT EXISTS (
                          SELECT 1 FROM fiber_collection_jobs fj
                          WHERE fj.ticket_id = t.id
                      )
                    ORDER BY t.created_at DESC
                    LIMIT 10
                ");
                $openStmt->execute();
                $openTickets = $openStmt->fetchAll(\PDO::FETCH_ASSOC);
                $t6bChecked = 0; $t6bFound = 0; $t6bApiCalls = 0;

                foreach ($openTickets as $ot) {
                    if ($t6bApiCalls >= 3) break; // rate limit guard

                    // Resolve Splynx customer ID
                    $custId = (int)$ot['customer_id'];
                    if ($custId <= 0 && (int)$ot['crm_client_id'] > 0) {
                        $mapStmt = $pdo->prepare("SELECT splynx_customer_id FROM fiber_customer_map WHERE crm_client_id = ? LIMIT 1");
                        $mapStmt->execute([(string)$ot['crm_client_id']]);
                        $mapped = $mapStmt->fetchColumn();
                        if ($mapped) $custId = (int)$mapped;
                    }
                    if ($custId <= 0) continue;

                    // Check Splynx for active service
                    $t6bApiCalls++;
                    $services = $splynxClient->getCustomerServices($custId);
                    $activeSvc = null;
                    foreach ($services as $svc) {
                        if (($svc['status'] ?? '') === 'active') { $activeSvc = $svc; break; }
                    }
                    $t6bChecked++;

                    if (!$activeSvc) {
                    splynxLog("  Task6b: ticket #" . $ot['id'] . " (" . $ot['customer_name'] . ") -- no active service yet");
                        continue;
                    }

                    // Active service found! Mark ticket as complete then run normal flow
                    splynxLog("  Task6b: ticket #" . $ot['id'] . " (" . $ot['customer_name'] . ") -- active service found (ID " . $activeSvc['id'] . ") -- auto-completing");

                    // Mark install_complete in tickets table
                    $pdo->prepare(
                        "UPDATE tickets SET install_complete=1, install_complete_at=datetime('now'),
                         splynx_service_id=? WHERE id=?"
                    )->execute([(int)$activeSvc['id'], (int)$ot['id']]);

                    // Get customer details
                    $customer = $splynxClient->getCustomer($custId);
                    if (!$customer) {
                        $customer = ['id' => $custId, 'name' => $ot['customer_name'], 'phone' => $ot['phone']];
                    }

                    // Build ticket array matching expected shape
                    $ticketForInstall = array_merge($ot, [
                        'customer_id'         => $custId,
                        'install_complete'    => 1,
                        'install_complete_at' => date('Y-m-d H:i:s'),
                    ]);

                    // Run full install flow: creates job, sends delivery note, closes ticket
                    $result = $fiberInvSvc->processNewInstall($activeSvc, $customer, $ticketForInstall);

                    if ($result['ok'] && $result['action'] === 'created') {
                        $t6bFound++;
                        splynxLog("  Task6b COMPLETE: ticket #{$ot['id']} -- {$ot['customer_name']} -- job created, delivery note sent, ticket closed");
                    } elseif ($result['action'] === 'exists') {
                        splynxLog("  Task6b: ticket #{$ot['id']} -- job already exists");
                    } else {
                        splynxLog("  Task6b ERROR ticket #{$ot['id']}: " . ($result['error'] ?? 'unknown'));
                    }
                }
                splynxLog("  Task6b done: checked={$t6bChecked}, completed={$t6bFound}, api_calls={$t6bApiCalls}");
            } catch (\Throwable $t6bErr) {
                splynxLog('  Task6b ERROR: ' . $t6bErr->getMessage());
            }
        } else {
            splynxLog('  Found ' . count($pendingTickets) . ' new install(s) after watermark.');
            $newCount = 0; $errCount = 0;

            foreach ($pendingTickets as $ticket) {
                // Resolve Splynx customer ID — try ticket.customer_id first, then fiber_customer_map via CRM ID
                $custId = (int)$ticket['customer_id'];
                if ($custId <= 0 && (int)$ticket['crm_client_id'] > 0) {
                    $mapStmt = $pdo->prepare("SELECT splynx_customer_id FROM fiber_customer_map WHERE crm_client_id = ? LIMIT 1");
                    $mapStmt->execute([(string)$ticket['crm_client_id']]);
                    $mappedId = $mapStmt->fetchColumn();
                    if ($mappedId) $custId = (int)$mappedId;
                }

                if ($custId <= 0) {
                    splynxLog("  SKIP ticket #{$ticket['id']}: no Splynx customer ID (CRM #{$ticket['crm_client_id']})");
                    $errCount++;
                    continue;
                }

                // Only NOW call Splynx API — just for this one customer
                $services = $splynxClient->getCustomerServices($custId);
                if (empty($services)) {
                    splynxLog("  SKIP ticket #{$ticket['id']}: no services for Splynx customer #{$custId}");
                    $errCount++;
                    continue;
                }

                // Find the active service
                $activeSvc = null;
                foreach ($services as $svc) {
                    if (($svc['status'] ?? '') === 'active') { $activeSvc = $svc; break; }
                }
                if (!$activeSvc) {
                    splynxLog("  SKIP ticket #{$ticket['id']}: no active service for #{$custId}");
                    continue;
                }

                // Get customer details from Splynx
                $customer = $splynxClient->getCustomer($custId);
                if (!$customer) {
                    $customer = ['id' => $custId, 'name' => $ticket['customer_name'], 'phone' => $ticket['phone']];
                }

                $result = $fiberInvSvc->processNewInstall($activeSvc, $customer, $ticket);

                if ($result['ok'] && $result['action'] === 'created') {
                    $newCount++;
                    splynxLog("  INVOICE: {$result['invoice_no']} — {$ticket['customer_name']} — \${$result['amount']} — {$ticket['area']}");
                } elseif ($result['action'] !== 'exists') {
                    $errCount++;
                    splynxLog("  ERROR ticket #{$ticket['id']}: " . ($result['error'] ?? 'unknown'));
                }
            }
            splynxLog("  Result: {$newCount} new invoices, {$errCount} errors");
        }
    }
} catch (\Throwable $e) {
    splynxLog('  Task 6 ERROR: ' . $e->getMessage());
}

// ════════════════════════════════════════════════════════════════════════════════

splynxLog('Splynx sync: complete.');
