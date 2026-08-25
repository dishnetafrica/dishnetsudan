<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * cron/staff_ssp_report.php — DishNet Hybrid Telecom
 *
 * Sends a daily end-of-day WhatsApp balance report to every active field
 * staff member who has any cash activity. Runs at 18:00 EAT via master.php.
 *
 * Message shows: SSP balance, USD balance, pending expenses count,
 * and a prompt to hand over if cash is still in hand.
 *
 * Skips staff with zero SSP + zero USD + zero pending to avoid noise.
 */

chdir(dirname(__DIR__));

require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/NotificationService.php';
require_once __DIR__ . '/../lib/DualReadCashPosition.php';
require_once __DIR__ . '/../lib/StaffLedgerService.php';
require_once __DIR__ . '/../lib/bootstrap_data.php';

$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$notify  = new NotificationService($store, $config);
$pdo     = $store->getPdo();
$today   = date('d M Y');

$fieldRoles = ['sales', 'sales_staff', 'support', 'support_leader', 'field_agent', 'collection'];
$retailers  = $store->load('retailers.json') ?? [];
$sent = 0; $skipped = 0;

foreach ($retailers as $r) {
    if (empty($r['is_active'])) { $skipped++; continue; }
    if (!in_array($r['role'] ?? '', $fieldRoles, true)) { $skipped++; continue; }
    $phone = trim($r['phone'] ?? '');
    if (empty($phone)) { $skipped++; continue; }

    $staffId   = (int)($r['id'] ?? 0);
    $staffName = $r['name'] ?? 'Staff';

    // ── SSP balance from staff_ledger ─────────────────────────────────────
    $sspBal = 0.0;
    try {
        $s = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN direction='in'  THEN ssp_amount ELSE 0 END),0)
                  - COALESCE(SUM(CASE WHEN direction='out' THEN ssp_amount ELSE 0 END),0)
             FROM staff_ledger
             WHERE staff_id=? AND currency='SSP' AND status NOT IN ('voided','cancelled')"
        );
        $s->execute([$staffId]);
        $sspBal = max(0.0, round((float)$s->fetchColumn(), 0));
    } catch (\Throwable $e) {}

    // ── USD balance from DualReadCashPosition ─────────────────────────────
    $usdExposure = 0.0;
    try {
        $dcp = new DualReadCashPosition($store, $pdo, $dataDir);
        $pos = $dcp->getPosition($staffId);
        $usdExposure = round((float)($pos['cash_exposure'] ?? 0), 2);
    } catch (\Throwable $e) {}

    // ── Pending expenses ──────────────────────────────────────────────────
    $pendingCount = 0;
    try {
        $p = $pdo->prepare("SELECT COUNT(*) FROM staff_expenses WHERE staff_id=? AND status='pending'");
        $p->execute([$staffId]);
        $pendingCount = (int)$p->fetchColumn();
    } catch (\Throwable $e) {}

    // Skip staff with zero activity
    if ($sspBal <= 0 && $usdExposure <= 0 && $pendingCount === 0) {
        $skipped++;
        continue;
    }

    // ── Build message ─────────────────────────────────────────────────────
    // SSP = expense advance (fuel, parts, travel) — staff SPEND it, not return it.
    // USD = customer collections or cash advances — must be handed over to office.
    $sspLine = $sspBal > 0
        ? "⛽ SSP Expense Fund:  *" . number_format($sspBal, 0) . " SSP* remaining"
        : "⛽ SSP Expense Fund:  0 SSP (fully spent)";

    $usdLine = $usdExposure > 0
        ? "💵 USD Cash in hand:  *\$" . number_format($usdExposure, 2) . "* — please hand over"
        : "💵 USD Cash:  \$0.00 (settled ✅)";

    $msg  = "📊 *Daily Cash Report*\n";
    $msg .= date('l, d M Y') . "\n";
    $msg .= "Staff: *{$staffName}*\n";
    $msg .= "────────────────────\n";
    $msg .= $sspLine . "\n";
    $msg .= $usdLine . "\n";
    if ($pendingCount > 0) {
        $msg .= "⏳ *{$pendingCount}* expense(s) awaiting approval\n";
    }
    $msg .= "────────────────────\n";
    if ($usdExposure > 0) {
        $msg .= "📲 Please hand over your USD cash to the office today.\n";
    } elseif ($sspBal > 0) {
        $msg .= "✅ USD settled. Use remaining SSP for field expenses as needed.\n";
    } else {
        $msg .= "✅ All clear — great work today!\n";
    }
    $msg .= "_DishNet Africa — " . date('H:i') . " EAT_";

    // ── Send via support WA channel ───────────────────────────────────────
    try {
        $notify->sendVia('support', $phone, $msg, 'staff_daily_report');
        $sent++;
        error_log("[staff_ssp_report] Sent to {$staffName} ({$phone})  SSP={$sspBal}  USD={$usdExposure}");
    } catch (\Throwable $e) {
        error_log("[staff_ssp_report] Failed for {$staffName}: " . $e->getMessage());
        $skipped++;
    }
}

echo "[staff_ssp_report] Done. Sent: {$sent} | Skipped: {$skipped}\n";
