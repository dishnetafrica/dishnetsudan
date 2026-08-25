#!/usr/bin/env php
<?php
/**
 * cron/cash_carry_reminder.php — DishNet Hybrid Telecom
 *
 * Daily WhatsApp reminder to agents over carry limit.
 * Also notifies admin (Rupesh) when any agent crosses threshold.
 *
 * Schedule: every 4 hours via master.php (sends max once per day per agent)
 * PHP 7.4 compatible.
 */
date_default_timezone_set('Africa/Juba');
chdir(dirname(__DIR__));

require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/NotificationService.php';
require_once __DIR__ . '/../lib/SnapshotService.php';
require_once __DIR__ . '/../lib/bootstrap_data.php';

$dataDir = getDataDir(dirname(__DIR__));

// ── Lock ──
$lockFile = $dataDir . '/cash_carry_reminder.lock';
$lockFp   = fopen($lockFile, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) { fclose($lockFp); return; }

$store  = SqliteStore::create($dataDir);
$config = $store->load('kyc_config.json') ?? [];
$notify = new NotificationService($store, $config);
$snap   = new SnapshotService($store->getPdo(), $store);

$globalCarryLimit = (float)(($config['advance_carry_limit'] ?? null) ?: 100);
$today      = date('Y-m-d');

// ── Load tracking state (one reminder per agent per day) ──
$stateFile  = $dataDir . '/cash_carry_reminder_state.json';
$state      = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
if (($state['date'] ?? '') !== $today) {
    $state = ['date' => $today, 'reminded' => [], 'admin_notified' => []];
}

// ── Rebuild all snapshots for accuracy ──
try { $snap->rebuildAll(); } catch (\Throwable $e) { _cclog('rebuildAll failed: ' . $e->getMessage()); }

$allRetailers = $store->load('retailers.json') ?? [];
$adminPhone   = trim($config['whatsapp_admin_phone'] ?? '');
$overLimit    = [];
$reminded     = 0;

foreach ($allRetailers as $r) {
    if (empty($r['is_active'])) continue;
    if (in_array($r['role'] ?? '', ['accountant', 'admin'])) continue;

    $agentId = (int)($r['id'] ?? 0);
    $phone   = preg_replace('/[^0-9+]/', '', $r['phone'] ?? '');
    $name    = $r['name'] ?? 'Agent';
    if (!$agentId || !$phone) continue;

    $pos      = $snap->get($agentId);
    $exposure = (float)($pos['cash_exposure'] ?? 0);
    $agentLimit = (float)(($r['carry_limit'] ?? null) ?: $globalCarryLimit);

    if ($exposure <= $agentLimit) continue;

    $overLimit[] = [
        'name'     => $name,
        'phone'    => $phone,
        'exposure' => $exposure,
        'agent_id' => $agentId,
    ];

    // ── Send agent reminder (once per day) ──
    if (!in_array($agentId, $state['reminded'] ?? [])) {
        $firstName = explode(' ', $name)[0];
        $msg  = "Hi *{$firstName}*, this is DishNet Africa.\n\n";
        $msg .= "You are currently holding *\$" . number_format($exposure, 2) . "* in company cash.\n";
        $msg .= "The carry limit is *\$" . number_format($agentLimit, 2) . "*.\n\n";
        $msg .= "Please hand over to the office today.\n";
        $msg .= "Your collection access will be blocked until you do.\n\n";
        $msg .= "If you have already handed over, please ignore this message.\n\n";
        $msg .= "_DishNet Africa_";

        try {
            $notify->sendRaw($phone, $msg, 'cash_carry_reminder');
            $state['reminded'][] = $agentId;
            $reminded++;
            _cclog("Reminded {$name} ({$phone}): \${$exposure} exposure");
        } catch (\Throwable $e) {
            _cclog("Failed to remind {$name}: " . $e->getMessage());
        }
    }

    // ── Notify admin when agent FIRST crosses limit (once per day per agent) ──
    if ($adminPhone && !in_array($agentId, $state['admin_notified'] ?? [])) {
        $adminMsg  = "Staff Cash Alert\n\n";
        $adminMsg .= "*{$name}* is holding *\$" . number_format($exposure, 2) . "*\n";
        $adminMsg .= "Carry limit: \$" . number_format($agentLimit, 2) . "\n";
        $adminMsg .= "Excess: \$" . number_format($exposure - $agentLimit, 2) . "\n\n";

        // Find last handover date
        $lastHov = '';
        $hovs = $store->load('cash_handovers.json') ?? [];
        foreach (array_reverse($hovs) as $h) {
            if ((int)($h['from_id'] ?? 0) === $agentId && ($h['status'] ?? '') === 'confirmed') {
                $lastHov = substr($h['confirmed_at'] ?? $h['created_at'] ?? '', 0, 10);
                break;
            }
        }
        if ($lastHov) {
            $daysSince = max(0, (int)((time() - strtotime($lastHov)) / 86400));
            $adminMsg .= "Last handover: {$lastHov} ({$daysSince} day" . ($daysSince !== 1 ? 's' : '') . " ago)\n";
        } else {
            $adminMsg .= "Last handover: Never\n";
        }
        $adminMsg .= "\nThis agent's collection access is blocked until they hand over.";

        try {
            $notify->sendRaw($adminPhone, $adminMsg, 'cash_carry_admin_alert');
            $state['admin_notified'][] = $agentId;
            _cclog("Admin notified about {$name}");
        } catch (\Throwable $e) {
            _cclog("Failed to notify admin about {$name}: " . $e->getMessage());
        }
    }
}

// ── Save state ──
file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));

// ── Summary ──
$total = count($overLimit);
_cclog("Done. {$total} agent(s) over limit, {$reminded} reminded today.");

flock($lockFp, LOCK_UN);
fclose($lockFp);

function _cclog(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] [cash_carry] ' . $msg . "\n";
}

// ══════════════════════════════════════════════════════════════════════════
// SSP BATCH AUTO-CLOSE + STALE CHECK — Phase 3
// 1. Auto-close any fully-spent batches (no discrepancy → no manual verify needed)
// 2. Alert Rupesh only for batches with genuinely unspent SSP sitting too long
// Threshold: > 7 days old AND > 50,000 SSP remaining after auto-close sweep.
// ══════════════════════════════════════════════════════════════════════════
if ($adminPhone) {
    try {
        require_once dirname(__DIR__) . '/lib/CashbookService.php';
        $cbSvc   = new CashbookService($store, getDataDir(dirname(__DIR__)));
        $cashIns = $store->load('cash_ins.json') ?? [];

        // One-time backfill: link pre-upload SSP expenses to exchange batches
        $cbSvc->backfillExchangeRefs($cashIns);

        // Sweep: auto-close batches that are fully spent
        $autoClosed = $cbSvc->autoCloseCompletedBatches($cashIns);
        if ($autoClosed > 0) {
            _cclog("Auto-closed {$autoClosed} fully-spent SSP batch(es).");
        }

        $batches = $cbSvc->getAllExchangeBatchSummary($cashIns, 60, false); // open only

        $staleBatches = array_filter($batches, function($b) {
            return $b['days_open'] > 7 && $b['ssp_remaining'] > 50000;
        });

        if (!empty($staleBatches)) {
            // Only send alert once per day (reuse state file)
            $lastSspAlert = $state['ssp_batch_alert_sent'] ?? '';
            if ($lastSspAlert !== $today) {
                $msg  = "🟡 *SSP Batch Alert — DishNet*\n\n";
                $msg .= count($staleBatches) . " exchange batch(es) have unspent SSP:\n\n";
                foreach ($staleBatches as $b) {
                    $msg .= "• *{$b['staff_name']}* — {$b['exchange_ref']}\n";
                    $msg .= "  " . number_format($b['ssp_remaining'], 0) . " SSP remaining";
                    $msg .= " (" . $b['days_open'] . " days old)\n";
                    if (!empty($b['expenses'])) {
                        $msg .= "  " . count($b['expenses']) . " linked expense(s)\n";
                    }
                    $msg .= "\n";
                }
                $msg .= "Please verify physical SSP or close the batch in SSP Overview.";
                $notify->sendRaw($adminPhone, $msg, 'ssp_batch_stale_alert');
                $state['ssp_batch_alert_sent'] = $today;
                _cclog("SSP batch alert sent for " . count($staleBatches) . " stale batch(es)");
            }
        }
    } catch (\Throwable $e) {
        _cclog("SSP batch check failed: " . $e->getMessage());
    }
}
