<?php
// ── Commission Cleanup — Reverse employee commission credits ────────────
// Scans passbook.json for commission credits to is_employee=true staff.
// Uses WalletService::reverse() which is idempotent (safe to run twice).
// v4.9.21
// ────────────────────────────────────────────────────────────────────────

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

// ── Access: admin only ──────────────────────────────────────────────────
if (!($retailer['is_admin'] ?? false)) {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied — admin only.</div>';
    return;
}

require_once __DIR__ . '/../../lib/WalletService.php';
$wallet = new WalletService($store);

// ── Build employee lookup ───────────────────────────────────────────────
$allRetailers = $store->load('retailers.json') ?: [];
$employeeIds = []; // id => name
$staffNames  = [];
foreach ($allRetailers as $r) {
    $rid = (int)($r['id'] ?? 0);
    $staffNames[$rid] = $r['name'] ?? 'Unknown';
    if (!empty($r['is_employee'])) {
        $employeeIds[$rid] = $r['name'] ?? 'Unknown';
    }
}

// ── Scan passbook for employee commission credits ───────────────────────
$allPassbook = $store->load('passbook.json') ?: [];

// Also build lookup of existing reversals
$reversedTrxNos = [];
foreach ($allPassbook as $p) {
    if (($p['trx_type'] ?? '') === 'reversal') {
        $orig = $p['original_trx_no'] ?? '';
        if ($orig !== '') $reversedTrxNos[$orig] = true;
    }
}

$empCommissions = []; // entries to reverse
$totalWrongComm = 0;
$alreadyReversed = 0;
$alreadyReversedAmt = 0;

foreach ($allPassbook as $p) {
    if (($p['trx_type'] ?? '') !== 'commission') continue;
    if (($p['entry_type'] ?? '') !== 'credit') continue;
    $rid = (int)($p['retailer_id'] ?? 0);
    if (!isset($employeeIds[$rid])) continue; // not an employee — skip

    $trxNo = $p['trx_no'] ?? '';
    $amt   = (float)($p['amount'] ?? 0);

    if (isset($reversedTrxNos[$trxNo])) {
        $alreadyReversed++;
        $alreadyReversedAmt += $amt;
        continue;
    }

    $empCommissions[] = $p;
    $totalWrongComm += $amt;
}

// ── Per-employee summary ────────────────────────────────────────────────
$empSummary = [];
foreach ($empCommissions as $p) {
    $rid = (int)$p['retailer_id'];
    if (!isset($empSummary[$rid])) {
        $empSummary[$rid] = ['name' => $employeeIds[$rid] ?? 'Unknown', 'count' => 0, 'total' => 0];
    }
    $empSummary[$rid]['count']++;
    $empSummary[$rid]['total'] += (float)($p['amount'] ?? 0);
}
uasort($empSummary, function($a, $b) { return $b['total'] <=> $a['total']; });

// ── Handle reverse action ───────────────────────────────────────────────
$actionMsg = '';
$actionOk  = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cc_action'] ?? '') === 'reverse_all' && csrfCheck()) {
    $reversed = 0;
    $revTotal = 0;
    $errors   = [];

    foreach ($empCommissions as $p) {
        $trxNo = $p['trx_no'] ?? '';
        if ($trxNo === '') continue;
        try {
            $wallet->reverse($trxNo, 'Commission cleanup — employee not eligible', $retailer['name']);
            $reversed++;
            $revTotal += (float)($p['amount'] ?? 0);
        } catch (Throwable $e) {
            $errors[] = $trxNo . ': ' . $e->getMessage();
        }
    }

    if (function_exists('logActivity')) {
        logActivity($dataDir, 'commission_cleanup',
            "Reversed {$reversed} employee commission credits totaling \${$revTotal}", '');
    }

    $actionOk = empty($errors);
    $actionMsg = "✅ Reversed {$reversed} entries — \$" . number_format($revTotal, 2) . " clawed back from employee wallets.";
    if (!empty($errors)) {
        $actionMsg .= " ⚠️ " . count($errors) . " failed: " . implode('; ', array_slice($errors, 0, 3));
    }

    // Reload data after reversal
    header('Location: ?page=dashboard&tab=commission_cleanup&done=' . $reversed . '&amt=' . round($revTotal, 2));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cc_action'] ?? '') === 'reverse_one' && csrfCheck()) {
    $trxNo = trim($_POST['trx_no'] ?? '');
    if ($trxNo !== '') {
        try {
            $result = $wallet->reverse($trxNo, 'Commission cleanup — employee not eligible', $retailer['name']);
            header('Location: ?page=dashboard&tab=commission_cleanup&done=1&amt=' . round((float)($result['amount'] ?? 0), 2));
            exit;
        } catch (Throwable $e) {
            $actionMsg = "❌ Failed to reverse {$trxNo}: " . $e->getMessage();
        }
    }
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&display=swap');
.cc{font-family:'DM Sans',-apple-system,sans-serif;max-width:1100px;margin:0 auto;padding-bottom:60px;}
.cc *{box-sizing:border-box;}
.cc-hdr h2{font-size:22px;font-weight:900;color:#0f0f0f;margin:0 0 3px;display:flex;align-items:center;gap:10px;}
.cc-hdr-sub{font-size:11px;color:#94a3b8;}
.cc-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:16px 0;}
.cc-card{background:#fff;border-radius:12px;border:1.5px solid #ececec;padding:12px 14px;position:relative;overflow:hidden;}
.cc-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;border-radius:12px 0 0 12px;}
.cc-card.red::before{background:#dc2626;}.cc-card.green::before{background:#16a34a;}
.cc-card.amber::before{background:#d97706;}.cc-card.slate::before{background:#64748b;}
.cc-card-v{font-size:22px;font-weight:900;line-height:1;letter-spacing:-.3px;}
.cc-card-l{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#b0b0b0;margin-top:4px;}
.cc-tbl-wrap{overflow-x:auto;border-radius:14px;border:1.5px solid #ececec;background:#fff;margin:12px 0;}
.cc-tbl{width:100%;border-collapse:collapse;min-width:500px;}
.cc-tbl th{background:#f8f8f8;padding:8px 12px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;border-bottom:1.5px solid #ececec;text-align:left;}
.cc-tbl td{padding:8px 12px;font-size:12px;border-bottom:1px solid #f3f4f6;}
.cc-tbl tr:last-child td{border-bottom:none;}
.cc-tbl tr:hover td{background:#fafafa;}
.cc-tbl tfoot td{font-weight:800;background:#f8f8f8;border-top:2px solid #e2e8f0;}
.cc-sec{font-size:14px;font-weight:800;color:#0f0f0f;margin:22px 0 8px;display:flex;align-items:center;gap:8px;}
.cc-btn{background:#dc2626;color:#fff;border:none;padding:8px 18px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;}
.cc-btn:hover{background:#b91c1c;}
.cc-btn-sm{background:#ef4444;color:#fff;border:none;padding:3px 10px;border-radius:5px;font-size:10px;font-weight:700;cursor:pointer;}
.cc-btn-sm:hover{background:#dc2626;}
.cc-ok{background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:12px;padding:14px;margin:14px 0;font-size:12px;color:#166534;font-weight:600;}
.cc-warn{background:#fef2f2;border:1.5px solid #fecaca;border-radius:12px;padding:14px;margin:14px 0;font-size:12px;color:#7f1d1d;}
@media(max-width:640px){.cc-cards{grid-template-columns:repeat(2,1fr);}}
</style>

<div class="cc">
<div class="cc-hdr">
    <h2>🧹 Commission Cleanup</h2>
    <div class="cc-hdr-sub">Find and reverse commission credits wrongly given to employees (is_employee = true)</div>
</div>

<?php if (isset($_GET['done'])): ?>
<div class="cc-ok">
    ✅ Reversed <?php echo (int)$_GET['done']; ?> commission entries — $<?php echo number_format((float)($_GET['amt'] ?? 0), 2); ?> clawed back. Wallet balances updated.
</div>
<?php endif; ?>

<?php if ($actionMsg): ?>
<div class="<?php echo $actionOk ? 'cc-ok' : 'cc-warn'; ?>"><?php echo htmlspecialchars($actionMsg); ?></div>
<?php endif; ?>

<!-- ── Summary Cards ───────────────────────────────────── -->
<div class="cc-cards">
    <div class="cc-card red">
        <div class="cc-card-v" style="color:#dc2626;">$<?php echo number_format($totalWrongComm, 2); ?></div>
        <div class="cc-card-l">Wrong Commission</div>
    </div>
    <div class="cc-card amber">
        <div class="cc-card-v"><?php echo count($empCommissions); ?></div>
        <div class="cc-card-l">Entries to Reverse</div>
    </div>
    <div class="cc-card slate">
        <div class="cc-card-v"><?php echo count($empSummary); ?></div>
        <div class="cc-card-l">Employees Affected</div>
    </div>
    <div class="cc-card green">
        <div class="cc-card-v"><?php echo $alreadyReversed; ?></div>
        <div class="cc-card-l">Already Reversed</div>
    </div>
</div>

<?php if (empty($empCommissions)): ?>
<div class="cc-ok" style="text-align:center;padding:30px;">
    <span style="font-size:24px;">✅</span><br>
    <strong style="font-size:14px;">All clean — no unreversed employee commissions found.</strong>
    <?php if ($alreadyReversed > 0): ?>
    <br><span style="font-size:11px;color:#64748b;"><?php echo $alreadyReversed; ?> entries were previously reversed ($<?php echo number_format($alreadyReversedAmt, 2); ?>).</span>
    <?php endif; ?>
</div>
<?php else: ?>

<!-- ── Reverse All Button ──────────────────────────────── -->
<form method="POST" style="margin:14px 0;" onsubmit="return confirm('This will DEBIT $<?php echo number_format($totalWrongComm, 2); ?> back from <?php echo count($empSummary); ?> employee wallets (<?php echo count($empCommissions); ?> entries).\n\nThis is safe — uses idempotent reversal, cannot double-debit.\n\nWallet balances will decrease.\n\nContinue?');">
    <?php echo csrfField(); ?>
    <input type="hidden" name="cc_action" value="reverse_all">
    <button type="submit" class="cc-btn">🔄 Reverse All <?php echo count($empCommissions); ?> Commission Credits — $<?php echo number_format($totalWrongComm, 2); ?></button>
</form>

<!-- ── Per-Employee Summary ─────────────────────────────── -->
<div class="cc-sec">👥 Per-Employee Summary</div>
<div class="cc-tbl-wrap">
<table class="cc-tbl">
<thead><tr><th>Employee</th><th style="text-align:center;">Entries</th><th style="text-align:right;">Total to Claw Back</th></tr></thead>
<tbody>
<?php foreach ($empSummary as $eid => $es): ?>
<tr>
    <td style="font-weight:700;"><?php echo htmlspecialchars($es['name']); ?></td>
    <td style="text-align:center;"><?php echo $es['count']; ?></td>
    <td style="text-align:right;font-weight:700;color:#dc2626;">$<?php echo number_format($es['total'], 2); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr>
    <td>TOTAL</td>
    <td style="text-align:center;"><?php echo count($empCommissions); ?></td>
    <td style="text-align:right;color:#dc2626;">$<?php echo number_format($totalWrongComm, 2); ?></td>
</tr>
</tfoot>
</table>
</div>

<!-- ── Detail List ──────────────────────────────────────── -->
<div class="cc-sec">📋 All Employee Commission Credits (<?php echo count($empCommissions); ?>)</div>
<div class="cc-tbl-wrap">
<table class="cc-tbl">
<thead><tr><th>Date</th><th>Employee</th><th>Description</th><th style="text-align:right;">Amount</th><th>TRX No</th><th></th></tr></thead>
<tbody>
<?php foreach ($empCommissions as $p):
    $trxNo = $p['trx_no'] ?? '';
?>
<tr>
    <td style="font-size:11px;white-space:nowrap;"><?php echo substr($p['created_at'] ?? '', 0, 10); ?></td>
    <td style="font-weight:600;"><?php echo htmlspecialchars($staffNames[(int)($p['retailer_id'] ?? 0)] ?? 'Unknown'); ?></td>
    <td style="font-size:11px;color:#64748b;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($p['description'] ?? ''); ?></td>
    <td style="text-align:right;font-weight:700;color:#dc2626;">$<?php echo number_format((float)($p['amount'] ?? 0), 2); ?></td>
    <td style="font-family:monospace;font-size:9px;color:#94a3b8;"><?php echo htmlspecialchars(substr($trxNo, 0, 20)); ?></td>
    <td>
        <form method="POST" style="margin:0;display:inline;" onsubmit="return confirm('Reverse this $<?php echo number_format((float)($p['amount'] ?? 0), 2); ?> commission?');">
            <?php echo csrfField(); ?>
            <input type="hidden" name="cc_action" value="reverse_one">
            <input type="hidden" name="trx_no" value="<?php echo htmlspecialchars($trxNo); ?>">
            <button type="submit" class="cc-btn-sm">Reverse</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

</div>
