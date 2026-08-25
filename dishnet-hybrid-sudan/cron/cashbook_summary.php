#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * cron/cashbook_summary.php — DishNet Hybrid Telecom
 *
 * Purpose:
 *   Sends a WhatsApp evening summary of today's cashbook activity to
 *   the admin/accountant (Rupesh) at 18:00 EAT.
 *
 * Message includes:
 *   💵 USD: IN $X  OUT $Y  Balance $Z
 *   🇸🇸 SSP: IN X  OUT Y  Balance Z  (≈$W)
 *   📋 Today's entries: N  |  Pending approvals: N
 *   ⚠️  Pending list (if any)
 *
 * Called by: cron/master.php at 18:00 EAT daily
 * Or manually: php cron/cashbook_summary.php
 */

chdir(dirname(__DIR__));

require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/NotificationService.php';
require_once __DIR__ . '/../lib/CashbookService.php';

require_once dirname(__DIR__) . '/lib/bootstrap_data.php';
$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$notify  = new NotificationService($store, $config);
$cb      = new CashbookService($store, $dataDir);

// ── Find recipient — admin or accountant with phone ────────────────────────
$retailers   = $store->load('retailers.json') ?? [];
$recipients  = [];
foreach ($retailers as $r) {
    $role = $r['role'] ?? 'sales';
    $isAdmin = !empty($r['is_admin']);
    if (($isAdmin || $role === 'accountant') && !empty($r['phone']) && !empty($r['is_active'])) {
        $recipients[] = $r;
    }
}

if (empty($recipients)) {
    cb_log('No admin/accountant with phone found — skipping');
    return; // called via include from master.php
}

// ── Build stats ────────────────────────────────────────────────────────────
$today   = date('Y-m-d');
$balances = $cb->getBothBalances();

$todayUSD = $cb->getSummary('dishnet', $today, $today);
$todaySSP = $cb->getSummary('dishnet', $today, $today); // SSP filtered separately via getBothBalances()
$pending  = $cb->getPendingEntries();

$usdBal    = $balances['USD']['balance'];
$sspBal    = $balances['SSP']['balance'];
$rate      = (float)($balances['exchange_rate'] ?? 1300);
$combined  = $balances['combined_usd'];

// ── Build WhatsApp message ─────────────────────────────────────────────────
$dayOfWeek = date('l'); // Monday, Tuesday...
$dateStr   = date('d M Y');

$lines = [];
$lines[] = "📒 *DishNet Cashbook — {$dateStr}*";
$lines[] = "";

// USD today
$usdInToday  = $todayUSD['total_in'];
$usdOutToday = $todayUSD['total_out'];
$lines[] = "💵 *USD*";
$lines[] = "  Today IN:  +\$" . number_format($usdInToday,  2);
$lines[] = "  Today OUT: −\$" . number_format($usdOutToday, 2);
$lines[] = "  Balance:    \$" . number_format($usdBal,       2);

$lines[] = "";

// SSP today
$sspInToday  = $todaySSP['total_in'];
$sspOutToday = $todaySSP['total_out'];
if ($sspBal > 0 || $sspInToday > 0) {
    $lines[] = "🇸🇸 *SSP*";
    $lines[] = "  Today IN:  +" . number_format($sspInToday,  0) . " SSP";
    $lines[] = "  Today OUT: −" . number_format($sspOutToday, 0) . " SSP";
    $lines[] = "  Balance:   "  . number_format($sspBal,       0) . " SSP";
    $lines[] = "  ≈ \$"         . number_format($sspBal / max(1, $rate), 2) . " USD @ " . number_format($rate, 0);
    $lines[] = "";
}

// Combined
$lines[] = "🏦 *Combined (USD equiv): \$" . number_format($combined, 2) . "*";
$lines[] = "";

// Today's entry count
$todayEntries = $cb->getEntries(['date_from' => $today, 'date_to' => $today]);
$lines[] = "📋 Entries today: " . count($todayEntries);

// Pending approvals
$pendingCount = count($pending);
if ($pendingCount > 0) {
    $lines[] = "⚠️  *Pending approvals: {$pendingCount}*";
    // List up to 5 pending items
    $shown = 0;
    foreach ($pending as $pe) {
        if ($shown >= 5) { $lines[] = "  … and more"; break; }
        $sym  = ($pe['currency'] ?? 'USD') === 'SSP' ? 'SSP ' : '$';
        $cat  = ucfirst($pe['category'] ?? 'entry');
        $who  = $pe['actor_name'] ?? '';
        $amt  = number_format((float)($pe['amount'] ?? 0), 2);
        $dir  = ($pe['direction'] ?? '') === 'out' ? '−' : '+';
        $lines[] = "  • {$cat} {$dir}{$sym}{$amt} by {$who}";
        $shown++;
    }
    $lines[] = "";
    $lines[] = "Review: " . rtrim($config['plugin_public_url'] ?? '', '/') . "?page=dashboard&tab=cashbook&cb_view=pending";
} else {
    $lines[] = "✅ No pending approvals";
}

$lines[] = "";
$lines[] = "_DishNet Hybrid · Auto-summary_";

$message = implode("\n", $lines);

// ── Send to each recipient ─────────────────────────────────────────────────
foreach ($recipients as $rec) {
    try {
        $notify->sendRaw($rec['phone'], $message, 'cashbook_daily_summary');
        cb_log("Sent cashbook summary to {$rec['name']} ({$rec['phone']})");
    } catch (\Throwable $e) {
        cb_log("Failed to send to {$rec['name']}: " . $e->getMessage());
    }
}

function cb_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] [cashbook_summary] ' . $msg . "\n";
}
