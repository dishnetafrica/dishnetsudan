#!/usr/bin/env php
<?php
// ^ shebang on line 1 is correct — PHP ignores it when run via CLI
// Note: No strict_types — this file is included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * CRM Background Sync Worker
 *
 * v2.2: Processes queued CRM sync jobs from crm_queue.json.
 * Run via cron: * * * * * php /path/to/cron_sync.php >> /path/to/sync.log 2>&1
 *
 * Each job executes Steps 3-7 from KycService::handleNew():
 *   3. POST /clients to CRM
 *   4. Upload customer image
 *   5. Upload ID proof
 *   6. Generate work order document
 *   7. Add CRM tag
 *
 * On success: updates application record with crm_client_id + passbook ref.
 * On exhausted retries: reverses wallet debit, marks application as failed.
 */

chdir(__DIR__);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/CrmApiClient.php';
require_once __DIR__ . '/lib/WalletService.php';
require_once __DIR__ . '/lib/CrmQueue.php';
require_once __DIR__ . '/lib/NotificationService.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PaymentUuids.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

$dataDir = getDataDir(__DIR__);  // Use UCRM's persistent data directory
$store   = SqliteStore::create($dataDir); // SQLite — shares same plugin.sqlite3 as public.php
$config  = $store->load('kyc_config.json');
$crm     = CrmApiClient::fromUcrm(__DIR__, $config);
$wallet  = new WalletService($store);
$queue   = new CrmQueue($store);
$notify  = new NotificationService($store, $config);

// Prevent overlapping runs (single-instance lock)
$lockFile = $dataDir . '/cron_sync.lock';
$lockFp   = fopen($lockFile, 'w+');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    return; // another instance running
}

$jobs = $queue->getPendingJobs(5); // process up to 5 per run
$processed = 0;

foreach ($jobs as $job) {
    $jobId = (int)$job['id'];
    $queue->markProcessing($jobId);
    $processed++;

    log_msg("Processing job #{$jobId}: {$job['firstname']} {$job['lastname']}");

    try {
        // ── Step 3: Create CRM client ─────────────────────────────────
        $crmResponse = $crm->post('clients', $job['crm_payload']);

        if (!$crmResponse || empty($crmResponse['id'])) {
            $error = json_encode($crm->getLastError());
            log_msg("  CRM rejected job #{$jobId}: {$error}");
            $queue->markFailed($jobId, $error);

            // If exhausted all retries → reverse wallet + notify retailer
            $updated = $store->findOne('crm_queue.json', 'id', $jobId);
            if (($updated['status'] ?? '') === 'exhausted' && ($job['wallet_debited'] ?? false)) {
                reverseWallet($wallet, $store, $job);
                $queue->markReversed($jobId, "CRM sync failed after max retries: {$error}");
                updateApplication($store, $job['application_id'], 'crm_failed');
                log_msg("  Wallet reversed for job #{$jobId}");
                // Notify retailer: CRM failed, wallet refunded
                $app      = $store->findOne('kyc_applications.json', 'id', $job['application_id'] ?? 0);
                $retailer = $app ? $store->findOne('retailers.json', 'id', (int)($app['retailer_id'] ?? 0)) : null;
                if ($retailer && $app) $notify->kycCrmFailed($retailer, $app, $error);
            }
            continue;
        }

        $crmClientId = (string)$crmResponse['id'];
        log_msg("  CRM client created: #{$crmClientId}");

        // ── Step 4: Upload customer image ─────────────────────────────
        if (!empty($job['files']['customer_image'])) {
            $filePath = $job['files']['customer_image'];
            if (file_exists($filePath)) {
                // UCRM /documents requires multipart/form-data — NOT base64 JSON
                $crm->upload($filePath, ['name' => 'Customer Photo'], $crmClientId);
            }
        }

        // ── Step 5: Upload ID proof ───────────────────────────────────
        if (!empty($job['files']['id_proof'])) {
            $filePath = $job['files']['id_proof'];
            if (file_exists($filePath)) {
                $crm->upload($filePath, ['name' => 'ID Proof'], $crmClientId);
            }
        }

        // ── Step 6: Generate work order ───────────────────────────────
        $connectivity = $job['connectivity_type'] ?? 'New Connection';
        $tplId = ($connectivity === 'New Connection') ? 2 : 3; // TPL_WORK_ORDER_NEW : TPL_WORK_ORDER_OTHER
        $crm->post('documents', [
            'clientId'   => (int)$crmClientId,
            'name'       => 'Work Order',
            'templateId' => $tplId,
        ]);

        // ── Step 7: Add CRM tag ───────────────────────────────────────
        if ($connectivity === 'Ownership Change') { $tagId = 54; }
        elseif ($connectivity === 'Shifting Connection') { $tagId = 53; }
        else { $tagId = 52; }
        $crm->patch("clients/{$crmClientId}/add-tag/{$tagId}");

        // ── Step 8: Update local records ──────────────────────────────
        $queue->markCompleted($jobId, $crmClientId);
        updateApplication($store, $job['application_id'], 'new', $crmClientId);
        updatePassbookRef($store, $job, $crmClientId);

        // ── Step 9: WhatsApp — notify retailer + welcome customer ─────
        $app      = $store->findOne('kyc_applications.json', 'id', $job['application_id'] ?? 0);
        $retailer = $app ? $store->findOne('retailers.json', 'id', (int)($app['retailer_id'] ?? 0)) : null;
        if ($retailer && $app) $notify->kycCrmCreated($retailer, $app, $crmClientId);

        log_msg("  Job #{$jobId} completed. CRM client: #{$crmClientId}. WhatsApp sent.");

    } catch (\Throwable $e) {
        log_msg("  Exception on job #{$jobId}: {$e->getMessage()}");
        $queue->markFailed($jobId, $e->getMessage());
    }
}

// Cleanup
flock($lockFp, LOCK_UN);
fclose($lockFp);

if ($processed > 0) {
    log_msg("Run complete: {$processed} jobs processed.");
}

// ── Nightly: CashbookReconcileWorker (runs once per day at 23:00 Juba time) ──
// UCRM calls main.php every ~5 min which includes cron_sync.php.
// We use a daily-window lock so the worker fires once per day.
$reconcileLock = $dataDir . '/reconcile_daily.lock';
$runReconcile  = false;
$targetHour    = 23; // 23:00 Juba (UTC+3)
$currentHour   = (int)date('G'); // 0-23
if ($currentHour === $targetHour) {
    // Check if already run today
    $lastRun = file_exists($reconcileLock) ? (int)@file_get_contents($reconcileLock) : 0;
    if (date('Y-m-d', $lastRun) !== date('Y-m-d')) {
        $runReconcile = true;
        @file_put_contents($reconcileLock, (string)time());
    }
}

if ($runReconcile) {
    log_msg("Running CashbookReconcileWorker...");
    try {
        require_once __DIR__ . '/workers/CashbookReconcileWorker.php';
        $reconciler = new CashbookReconcileWorker($store, $dataDir);
        $recResult  = $reconciler->run(7);
        log_msg("CashbookReconcileWorker done: " .
            "staff_days={$recResult['staff_days_computed']} " .
            "flags={$recResult['flags_raised']} " .
            "fixed={$recResult['expense_postings_fixed']} " .
            "settled={$recResult['advances_auto_settled']} " .
            "{$recResult['duration_ms']}ms");
    } catch (\Throwable $e) {
        log_msg("CashbookReconcileWorker error: " . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════
// DELTA CLIENT SYNC  (runs every cron cycle ~1 min)
// Fetches clients modified in the last 2 hours from UCRM.
// Keeps search cache fresh without a daily 3am full pull.
// ══════════════════════════════════════════════════════════════════
if ($crm->isConfigured()) {
    $deltaMetaKey = 'client_delta_sync_meta.json';
    $deltaMeta    = $store->load($deltaMetaKey) ?? [];
    $lastDelta    = (int)($deltaMeta['last_run_ts'] ?? 0);
    $nowTs        = time();

    // Run delta at most every 2 minutes to avoid hammering UCRM API
    if ($nowTs - $lastDelta >= 120) {
        log_msg('Delta client sync: starting...');
        $modifiedSince = date('Y-m-d\TH:i:s', $nowTs - 7200); // last 2 hours
        $deltaData     = $crm->get("clients?direction=DESC&limit=100");
        $deltaCount    = 0;

        if (is_array($deltaData) && count($deltaData)) {
            // Merge into existing cache
            $existing = $store->load('ucrm_clients_cache.json') ?? [];
            $map = [];
            foreach ($existing as $c) { if (!empty($c['id'])) $map[(int)$c['id']] = $c; }
            foreach ($deltaData as $c) {
                if (!empty($c['id'])) { $map[(int)$c['id']] = $c; $deltaCount++; }
            }
            $store->save('ucrm_clients_cache.json', array_values($map));

            // Rebuild compact search index
            buildSearchIndex($store, array_values($map));

            log_msg("Delta client sync: merged {$deltaCount} clients. Cache total: " . count($map));
        } else {
            log_msg('Delta client sync: no data returned (CRM may be unreachable).');
        }

        $store->save($deltaMetaKey, ['last_run_ts' => $nowTs, 'last_run' => date('Y-m-d H:i:s'), 'count' => $deltaCount]);
    }
}

// ══════════════════════════════════════════════════════════════════
// CRM PAYMENT RETRY  (runs every cron cycle)
// Retries failed payment posts from payment_collections flow.
// ══════════════════════════════════════════════════════════════════
if ($crm->isConfigured()) {
    $retryQueue = $store->load('crm_payment_retry.json') ?? [];
    $retryUpdated = false;
    // Pre-load retailers for personal app key lookup
    $allRetailers = $store->load('retailers.json') ?? [];
    $retailerById = [];
    foreach ($allRetailers as $r) { if (!empty($r['id'])) $retailerById[(int)$r['id']] = $r; }

    foreach ($retryQueue as $i => $rq) {
        if (($rq['status'] ?? '') !== 'pending') continue;
        if (($rq['attempts'] ?? 0) >= 5) {
            $retryQueue[$i]['status'] = 'failed';
            $retryQueue[$i]['last_error'] = 'Max retries exhausted';
            $retryUpdated = true;
            log_msg("Payment retry #{$rq['id']}: exhausted after 5 attempts for {$rq['customer_name']}");
            continue;
        }
        if (!empty($rq['next_retry_at']) && strtotime($rq['next_retry_at']) > time()) continue;

        log_msg("Payment retry #{$rq['id']}: attempt {$rq['attempts']} for {$rq['customer_name']} \${$rq['payload']['amount']}");

        // Resolve methodId → UUID (handles legacy slugs, ints, display names, pass-through UUIDs)
        {
            $payload = $rq['payload'];
            $_rawMethod = null;
            foreach (['methodId','method','paymentType'] as $_mk) {
                if (isset($payload[$_mk])) { $_rawMethod = $payload[$_mk]; unset($payload[$_mk]); break; }
            }
            $payload['methodId'] = PaymentUuids::resolve($_rawMethod);
            if ($payload !== $rq['payload']) {
                $retryQueue[$i]['payload'] = $payload;
                $rq = $retryQueue[$i];
                $retryUpdated = true;
            }
        }

        // Use agent's personal UCRM app key if available so Created By shows agent name
        $retryRetailer = !empty($rq['retailer_id']) ? ($retailerById[(int)$rq['retailer_id']] ?? null) : null;
        $crmForRetry = ($retryRetailer && !empty($retryRetailer['ucrm_app_key']))
            ? new CrmApiClient(rtrim($crm->getBaseUrl(), '/'), $retryRetailer['ucrm_app_key'], 'X-Auth-App-Key')
            : $crm;

        $result  = $crmForRetry->post('payments', $rq['payload']);
        $success = !empty($result) && isset($result['id']);

        if ($success) {
            $retryQueue[$i]['status']     = 'synced';
            $retryQueue[$i]['crm_payment_id'] = $result['id'];
            $retryQueue[$i]['synced_at']  = date('Y-m-d H:i:s');
            $retryUpdated = true;
            // Mark collection as synced
            if (!empty($rq['collection_id'])) {
                $store->updateOne('payment_collections.json', 'id', (int)$rq['collection_id'], [
                    'crm_synced'     => true,
                    'crm_payment_id' => $result['id'],
                ]);
            }
            log_msg("  ✓ Payment synced to CRM #{$result['id']}");
        } else {
            $err = $crmForRetry->getLastError();
            $errMsg = isset($err['http_code'])
                ? "HTTP {$err['http_code']}: " . json_encode($err['response'] ?? '')
                : ($err['curl_error'] ?? json_encode($err));
            $retryQueue[$i]['attempts']++;
            $retryQueue[$i]['last_error']   = $errMsg;
            $retryQueue[$i]['next_retry_at'] = date('Y-m-d H:i:s', time() + (300 * $retryQueue[$i]['attempts'])); // exp backoff
            $retryUpdated = true;
            log_msg("  ✗ Retry failed: {$errMsg}");
        }
    }

    if ($retryUpdated) $store->save('crm_payment_retry.json', $retryQueue);
}

// ══════════════════════════════════════════════════════════════════
// CRM AUTO-HEAL  (runs every cron cycle)
// Finds collections with no crm_customer_id, auto-matches from
// client_search_index.json, and posts to CRM if matched.
// ══════════════════════════════════════════════════════════════════
if ($crm->isConfigured()) {
    // Reuse same auto-match logic as post_handlers
    function _cronAutoMatch(string $name, \StoreInterface $store): ?array {
        if (!$name) return null;
        $idx = $store->load('client_search_index.json') ?? [];
        if (empty($idx)) return null;
        $needle = strtolower(preg_replace('/\s+/', ' ', trim($name)));
        $needleWords = array_filter(explode(' ', $needle));
        $best = null; $bestScore = 0;
        foreach ($idx as $c) {
            $haystack = strtolower($c['search'] ?? $c['name'] ?? '');
            $cname    = strtolower($c['name'] ?? '');
            if ($cname === $needle) return ['id'=>(int)$c['id'],'name'=>$c['name'],'score'=>100];
            $wordMatches = 0;
            foreach ($needleWords as $w) {
                if (strlen($w) >= 3 && strpos($haystack, $w) !== false) $wordMatches++;
            }
            if (count($needleWords) >= 2 && $wordMatches === count($needleWords)) {
                $score = 80 + $wordMatches;
                if ($score > $bestScore) { $bestScore = $score; $best = ['id'=>(int)$c['id'],'name'=>$c['name'],'score'=>$score]; }
            }
            if (count($needleWords) >= 2) {
                $first = $needleWords[array_key_first($needleWords)];
                $last  = $needleWords[array_key_last($needleWords)];
                if (strlen($first) >= 4 && strlen($last) >= 4
                    && strpos($cname, $first) !== false && strpos($cname, $last) !== false) {
                    $score = 75;
                    if ($score > $bestScore) { $bestScore = $score; $best = ['id'=>(int)$c['id'],'name'=>$c['name'],'score'=>$score]; }
                }
            }
        }
        return ($bestScore >= 75) ? $best : null;
    }

    $allCols = $store->load('payment_collections.json') ?? [];
    $healCount = 0;
    foreach ($allCols as $col) {
        // Skip already synced
        if (!empty($col['crm_synced'])) continue;

        $custName = $col['customer_name'] ?? '';
        $custId   = trim($col['crm_customer_id'] ?? '');

        // Case A: has CRM ID but never synced (collected before retry queue existed)
        // Case B: no CRM ID — try to auto-match from index
        if (!$custId) {
            if (!$custName) continue;
            $match = _cronAutoMatch($custName, $store);
            if (!$match) continue;
            $custId = (string)$match['id'];
            $matchNote = " [auto-matched]";
        } else {
            $matchNote = ' [retry: had CRM ID, was never posted]';
        }

        $retailerRec = !empty($col['retailer_id']) ? ($retailerById[(int)$col['retailer_id']] ?? null) : null;
        $crmForHeal = ($retailerRec && !empty($retailerRec['ucrm_app_key']))
            ? new CrmApiClient(rtrim($crm->getBaseUrl(), '/'), $retailerRec['ucrm_app_key'], 'X-Auth-App-Key')
            : $crm;

        $payload = [
            'clientId'     => (int)$custId,
            'methodId'     => PaymentUuids::resolve($col['method'] ?? 'Cash'),
            'amount'       => (float)($col['amount'] ?? 0),
            'currencyCode' => 'USD',
            'note'         => "Collected by {$col['retailer_name']} via DishNet{$matchNote}",
            'applyToInvoicesAutomatically' => true,
        ];
        if (!empty($col['invoice_id'])) {
            $payload['note'] .= ' | Invoice: ' . $col['invoice_id'];
        }

        log_msg("Auto-heal: posting collection #{$col['id']} for '{$custName}' CRM #{$custId}");
        $result  = $crmForHeal->post('payments', $payload);
        $success = !empty($result) && isset($result['id']);

        if ($success) {
            $store->updateOne('payment_collections.json', 'id', (int)$col['id'], [
                'crm_customer_id'  => $custId,
                'crm_synced'       => true,
                'crm_payment_id'   => $result['id'],
                'crm_auto_matched' => true,
            ]);
            $healCount++;
            log_msg("  ✓ Auto-healed collection #{$col['id']} → CRM payment #{$result['id']}");
        } else {
            $err = $crmForHeal->getLastError();
            log_msg("  ✗ Auto-heal failed for collection #{$col['id']}: " . json_encode($err));
        }
    }
    if ($healCount > 0) log_msg("Auto-heal: fixed {$healCount} collection(s)");
}

// ══════════════════════════════════════════════════════════════════
// CASHBOOK AUTO-PULL  (runs once per day ~midnight Juba time)
// Pulls all Cash UUID payments from UCRM into cb_ledger.
// Idempotent — deduplicates by PAY-{id}. Safe to run every minute.
// ══════════════════════════════════════════════════════════════════
$cbMeta    = $store->load('cashbook_meta_v2.json') ?? [];
$lastPull  = $cbMeta['cron_pull_at'] ?? '';
$nowHour   = (int)date('H');
// Run once per day: between 23:00–23:59 Juba time, or if never run today
$todayPull = substr($lastPull, 0, 10);
$shouldPull = ($todayPull !== date('Y-m-d')) && ($nowHour >= 22);

if ($shouldPull && $crm->isConfigured()) {
    require_once __DIR__ . '/lib/CashbookService.php';
    require_once __DIR__ . '/lib/PaymentUuids.php';
    $cb = new CashbookService($store, $dataDir);

    // Pull from the day after the last cashbook entry
    $result = $cb->syncFromCrmApi($crm, '', []);

    $cbMeta['cron_pull_at']      = date('Y-m-d H:i:s');
    $cbMeta['cron_pull_imported'] = $result['imported'];
    $cbMeta['cron_pull_cutoff']  = $result['cutoff'];
    file_put_contents($dataDir . '/cashbook_meta_v2.json',
        json_encode($cbMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    log_msg("Cashbook pull: {$result['imported']} imported, {$result['skipped']} skipped (from {$result['cutoff']})");
}

/**
 *
 * Fields included (superset of all consumer needs):
 *   id, name, phone, email, bal, plans, username, status, search
 *
 * Consumers:
 *   - collect_payment  → id, name, phone, bal, search
 *   - customer_lookup  → id, name, phone, email, plans, search  (replaces UCRM_IDX)
 *   - NOC dispatch     → id, name (enrichment)
 *   - admin dashboard  → count only
 *   - bg_client_sync   → serves this via API
 *
 * Called after every sync (delta or full). ~10x smaller than ucrm_clients_cache.
 */
function buildSearchIndex(StoreInterface $store, array $clients): void
{
    // Load plans + services for plan name lookup (best-effort — not fatal if missing)
    $planNames  = [];
    $svcByClient = [];
    try {
        $plans = $store->load('ucrm_plans_cache.json') ?? [];
        foreach ($plans as $p) {
            if (!empty($p['id'])) $planNames[(int)$p['id']] = $p['name'] ?? '';
        }
        $services = $store->load('ucrm_services_cache.json') ?? [];
        foreach ($services as $s) {
            $cid = (int)($s['clientId'] ?? $s['_clientId'] ?? 0);
            if ($cid) $svcByClient[$cid][] = $planNames[(int)($s['servicePlanId'] ?? 0)] ?? '';
        }
    } catch (\Throwable $e) { /* non-fatal */ }

    $idx = [];
    foreach ($clients as $c) {
        $cid = (int)($c['id'] ?? 0);
        if (!$cid) continue;

        // Name
        $fname = trim($c['firstName'] ?? $c['first_name'] ?? '');
        $lname = trim($c['lastName']  ?? $c['last_name']  ?? '');
        $name  = trim("$fname $lname");
        if (!$name) $name = trim($c['companyName'] ?? $c['company_name'] ?? $c['username'] ?? '');

        // Phone, email, extra contact names
        $phone = ''; $email = ''; $extraNames = [];
        foreach ($c['contacts'] ?? [] as $ct) {
            if (!$phone) {
                if (!empty($ct['phone'])) $phone = $ct['phone'];
                elseif (!empty($ct['phones'][0]['number'])) $phone = $ct['phones'][0]['number'];
            }
            if (!$email && !empty($ct['email'])) $email = $ct['email'];
            if (!empty($ct['name'])) $extraNames[] = $ct['name'];
        }
        if (!$phone) $phone = trim($c['phone'] ?? $c['mobile'] ?? '');
        if (!$email) $email = trim($c['email'] ?? '');

        $username  = trim($c['username'] ?? '');
        $company   = trim($c['companyName'] ?? $c['company_name'] ?? '');
        $plans     = implode(' ', array_filter($svcByClient[$cid] ?? []));
        $extraStr  = implode(' ', $extraNames);
        $status    = $c['status'] ?? '';

        $phoneNorm = preg_replace('/[^0-9]/', '', $phone);
        $phoneNorm = strlen($phoneNorm) >= 9 ? substr($phoneNorm, -9) : $phoneNorm;

        $idx[] = [
            'id'       => $cid,
            'name'     => $name,
            'phone'    => $phone,
            'email'    => $email,
            'bal'      => (float)($c['accountBalance'] ?? 0),
            'plans'    => $plans,
            'username' => $username,
            'status'   => $status,
            'search'   => strtolower("$name $phone $email $cid $plans $extraStr $username $company"),
        ];
    }
    // ── Write to JSON blob (backward compat) ──────────────────────────────
    $store->save('client_search_index.json', $idx);

    // ── Write to SQLite table (Fix #5 — O(1) phone lookup) ───────────────
    // Uses REPLACE INTO so re-running sync updates existing rows atomically.
    try {
        $pdo = $store->getPdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS client_search_index (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL DEFAULT '',
            phone TEXT NOT NULL DEFAULT '',
            phone_norm TEXT NOT NULL DEFAULT '',
            service TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_csi_phone_norm ON client_search_index(phone_norm)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_csi_name ON client_search_index(name COLLATE NOCASE)");

        $replStmt = $pdo->prepare(
            "REPLACE INTO client_search_index (id, name, phone, phone_norm, service, updated_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'))"
        );
        $pdo->beginTransaction();
        foreach ($idx as $row) {
            $pn = preg_replace('/[^0-9]/', '', $row['phone'] ?? '');
            $pn = strlen($pn) >= 9 ? substr($pn, -9) : $pn;
            $replStmt->execute([
                (int)$row['id'],
                $row['name']  ?? '',
                $row['phone'] ?? '',
                $pn,
                $row['plans'] ?? '',
            ]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[cron_sync] client_search_index SQLite write failed: ' . $e->getMessage());
        // Non-fatal — JSON blob is the fallback
    }
}

function updateApplication(StoreInterface $store, int $appId, string $status, string $crmClientId = ''): void
{
    $updates = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
    if ($crmClientId) {
        $updates['crm_client_id'] = $crmClientId;
    }
    $store->updateOne('kyc_applications.json', 'id', $appId, $updates);
}

function updatePassbookRef(StoreInterface $store, array $job, string $crmClientId): void
{
    if (empty($job['wallet_debited'])) return;

    $appId      = (int)($job['application_id'] ?? 0);
    $retailerId = (int)($job['retailer_id']    ?? 0);
    $firstname  = $job['crm_payload']['firstName'] ?? ($job['firstname'] ?? '');
    $lastname   = $job['crm_payload']['lastName']  ?? ($job['lastname']  ?? '');

    if (!$appId || !$retailerId) return;

    // B-10 FIX: WalletService no longer writes 'KYC-PENDING' as the reference.
    // The debit reference is 'KYC-APP-{appId}'. Match by application_id which
    // is the stable, reliable key — works regardless of reference string format.
    $store->withLock('passbook.json', function (array $passbook) use ($appId, $retailerId, $crmClientId, $firstname, $lastname) {
        for ($i = count($passbook) - 1; $i >= 0; $i--) {
            $isMatch = (int)($passbook[$i]['retailer_id']    ?? 0) === $retailerId
                    && (int)($passbook[$i]['application_id'] ?? 0) === $appId
                    && ($passbook[$i]['entry_type'] ?? '') === 'debit';

            if ($isMatch) {
                $passbook[$i]['reference']    = "KYC-{$crmClientId}";
                $passbook[$i]['crm_client_id']= $crmClientId;
                $passbook[$i]['description']  = "KYC – CRM client #{$crmClientId} ({$firstname} {$lastname})";
                break;
            }
        }
        return ['records' => $passbook, 'result' => null];
    });
}

function reverseWallet(WalletService $wallet, StoreInterface $store, array $job): void
{
    $amount     = (float)($job['amount_charged'] ?? 0);
    $retailerId = (int)($job['retailer_id'] ?? 0);
    if ($amount <= 0 || $retailerId <= 0) return;

    $wallet->credit(
        $retailerId,
        $amount,
        "REVERSAL – CRM sync exhausted ({$job['firstname']} {$job['lastname']})",
        'System'
    );
}

function log_msg(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
}
