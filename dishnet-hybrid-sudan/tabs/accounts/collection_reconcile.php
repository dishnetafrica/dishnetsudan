<?php
// ── Collection Reconcile — CRM vs Cashbook real-time check ──────────────
// Fetches ALL payments from CRM API, cross-matches against:
//   1. cb_ledger (cashbook) via validation_ref = PAY-{crmId}
//   2. payment_collections.json via crm_payment_id
// Shows what's in CRM but missing from cashbook, and vice versa.
// Covers Starlink, Fiber, cash, bank — everything.
// v4.9.21
// ────────────────────────────────────────────────────────────────────────

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

// ── Access: admin + accountant only ─────────────────────────────────────
if (!($retailer['is_admin'] ?? false) && !in_array($retailer['role'] ?? '', ['admin','accountant'])) {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied — admin/accountant only.</div>';
    return;
}

require_once __DIR__ . '/../../lib/CrmApiClient.php';
require_once __DIR__ . '/../../lib/CashbookService.php';
require_once __DIR__ . '/../../lib/PaymentUuids.php';

$cb = new CashbookService($store, $dataDir);

// ── Date range ──────────────────────────────────────────────────────────
$dateFrom = $_GET['rc_from'] ?? date('Y-m-d', strtotime('-1 day'));
$dateTo   = $_GET['rc_to']   ?? date('Y-m-d');
$doRun    = !empty($_GET['rc_run']);

// ── Staff lookup ────────────────────────────────────────────────────────
$allRetailers = $store->load('retailers.json') ?: [];
$staffById = [];
foreach ($allRetailers as $r) {
    $staffById[(int)$r['id']] = $r['name'] ?? 'Unknown';
}

$results = null;
$error   = '';

if ($doRun) {
    // ═══════════════════════════════════════════════════════════════════
    // 1. LOAD CASHBOOK ENTRIES for date range
    // ═══════════════════════════════════════════════════════════════════
    $cbRows = $cb->query(
        "SELECT id, sr, date, direction, amount, currency, ssp_amount, ssp_rate,
                category, person, description, validation_ref, source, status, created_at
         FROM cb_ledger
         WHERE date >= ? AND date <= ? AND direction='in'
         ORDER BY date DESC, id DESC",
        [$dateFrom, $dateTo]
    );

    // Build cashbook lookup by normalized ref: PAY-{crmId}
    $cbByRef = []; $cbBySr = [];
    foreach ($cbRows as $row) {
        $vr = $row['validation_ref'] ?? '';
        $norm = str_contains($vr, 'CRM-PAY-') ? str_replace('CRM-PAY-', 'PAY-', $vr) : $vr;
        if ($norm !== '') $cbByRef[$norm] = $row;
        $sr = $row['sr'] ?? '';
        if ($sr !== '') $cbBySr[$sr] = $row;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. LOAD LOCAL COLLECTIONS for date range
    // ═══════════════════════════════════════════════════════════════════
    $allCols = $store->load('payment_collections.json') ?: [];
    $localByCrmId = []; $voidedCols = [];
    foreach ($allCols as $c) {
        $d = substr($c['collected_at'] ?? $c['created_at'] ?? '', 0, 10);
        if ($d < $dateFrom || $d > $dateTo) continue;
        if (($c['status'] ?? '') === 'voided') { $voidedCols[] = $c; continue; }
        $crmPayId = (int)($c['crm_payment_id'] ?? 0);
        if ($crmPayId > 0) $localByCrmId[$crmPayId] = $c;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. FETCH ALL CRM PAYMENTS (ALL methods)
    // ═══════════════════════════════════════════════════════════════════
    $apiPayments = [];
    try {
        $crm = CrmApiClient::fromUcrm(dirname(__DIR__, 2), $config);
        $page = 1; $pageSize = 200;
        do {
            $endpoint = 'payments?limit=' . $pageSize
                      . '&offset=' . (($page - 1) * $pageSize)
                      . '&createdDateFrom=' . urlencode($dateFrom . 'T00:00:00')
                      . '&createdDateTo=' . urlencode($dateTo . 'T23:59:59')
                      . '&order=createdDate&direction=DESC';
            $batch = $crm->get($endpoint);
            if (!is_array($batch) || empty($batch)) break;
            foreach ($batch as $pay) $apiPayments[] = $pay;
            $page++;
            if (count($batch) < $pageSize) break;
        } while (count($apiPayments) < 5000);
    } catch (Throwable $e) {
        $error = 'CRM API error: ' . $e->getMessage();
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4. THREE-WAY MATCH: CRM → Cashbook → Collections
    // ═══════════════════════════════════════════════════════════════════
    $matched = []; $crmOnly = []; $cbOnly = [];
    $crmTotal = 0; $matchedTotal = 0; $crmOnlyTotal = 0;
    $crmIdsSeen = []; $methodTotals = [];

    foreach ($apiPayments as $pay) {
        $crmId = (int)($pay['id'] ?? 0);
        $amount = (float)($pay['amount'] ?? 0);
        $payDate = substr($pay['createdDate'] ?? '', 0, 10);
        $clientId = (int)($pay['clientId'] ?? 0);
        $method = $pay['methodName'] ?? 'Unknown';
        $methodId = $pay['methodId'] ?? '';
        $note = $pay['note'] ?? '';
        $clientName = trim(($pay['clientFirstName'] ?? '') . ' ' . ($pay['clientLastName'] ?? ''));

        $crmIdsSeen[$crmId] = true;
        $crmTotal += $amount;

        if (!isset($methodTotals[$method])) {
            $methodTotals[$method] = ['crm' => 0, 'cb' => 0, 'count' => 0, 'matched' => 0, 'method_id' => $methodId];
        }
        $methodTotals[$method]['crm'] += $amount;
        $methodTotals[$method]['count']++;

        $ref = 'PAY-' . $crmId;
        $sr  = 'CRM-' . $crmId;
        $cbMatch = $cbByRef[$ref] ?? $cbBySr[$sr] ?? null;
        $colMatch = $localByCrmId[$crmId] ?? null;
        $agentId = $colMatch ? (int)($colMatch['retailer_id'] ?? 0) : 0;
        $agentName = $colMatch ? ($colMatch['retailer_name'] ?? '') : '';

        $row = [
            'crm_id' => $crmId, 'amount' => $amount, 'date' => $payDate,
            'client_id' => $clientId, 'client_name' => $clientName ?: ('CRM #' . $clientId),
            'method' => $method, 'method_id' => $methodId, 'note' => $note,
            'in_cashbook' => (bool)$cbMatch,
            'cb_amount' => $cbMatch ? (float)($cbMatch['amount'] ?? 0) : 0,
            'cb_source' => $cbMatch ? ($cbMatch['source'] ?? '') : '',
            'in_collection' => (bool)$colMatch,
            'agent_id' => $agentId,
            'agent_name' => $agentName ?: ($agentId > 0 ? ($staffById[$agentId] ?? 'ID#'.$agentId) : ''),
        ];

        if ($cbMatch) {
            $matched[] = $row;
            $matchedTotal += $amount;
            $methodTotals[$method]['matched']++;
            $methodTotals[$method]['cb'] += (float)($cbMatch['amount'] ?? 0);
        } else {
            $crmOnly[] = $row;
            $crmOnlyTotal += $amount;
        }
    }

    // Cashbook entries referencing CRM IDs not found in CRM
    foreach ($cbRows as $row) {
        $vr = $row['validation_ref'] ?? '';
        if (!str_contains($vr, 'PAY-') && !str_contains($vr, 'CRM-PAY-')) continue;
        $norm = str_contains($vr, 'CRM-PAY-') ? str_replace('CRM-PAY-', 'PAY-', $vr) : $vr;
        $crmIdFromRef = (int)str_replace('PAY-', '', $norm);
        if ($crmIdFromRef > 0 && !isset($crmIdsSeen[$crmIdFromRef])) $cbOnly[] = $row;
    }

    $cbAllInTotal = 0;
    foreach ($cbRows as $row) {
        if (($row['status'] ?? '') !== 'voided_reconcile') $cbAllInTotal += (float)($row['amount'] ?? 0);
    }

    arsort($methodTotals);

    $results = [
        'crm_count' => count($apiPayments), 'crm_total' => $crmTotal,
        'cb_all_in' => $cbAllInTotal, 'matched_count' => count($matched),
        'matched_total' => $matchedTotal, 'crm_only' => $crmOnly,
        'crm_only_total' => $crmOnlyTotal, 'cb_only' => $cbOnly,
        'voided' => $voidedCols, 'methods' => $methodTotals,
    ];
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&display=swap');
.rc{font-family:'DM Sans',-apple-system,sans-serif;max-width:1200px;margin:0 auto;padding-bottom:60px;}
.rc *{box-sizing:border-box;}
.rc-hdr h2{font-size:22px;font-weight:900;color:#0f0f0f;margin:0 0 3px;display:flex;align-items:center;gap:10px;}
.rc-hdr-sub{font-size:11px;color:#94a3b8;}
.rc-form{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:16px;margin:16px 0;display:flex;flex-wrap:wrap;gap:10px;align-items:end;}
.rc-form label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;display:block;margin-bottom:3px;}
.rc-form input[type=date]{padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;}
.rc-form button{background:#2563eb;color:#fff;border:none;padding:8px 20px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;}
.rc-form button:hover{background:#1d4ed8;}
.rc-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px;margin:16px 0;}
.rc-card{background:#fff;border-radius:12px;border:1.5px solid #ececec;padding:12px 14px;position:relative;overflow:hidden;}
.rc-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;border-radius:12px 0 0 12px;}
.rc-card.blue::before{background:#2563eb;}.rc-card.green::before{background:#16a34a;}
.rc-card.red::before{background:#dc2626;}.rc-card.amber::before{background:#d97706;}
.rc-card.slate::before{background:#64748b;}.rc-card.purple::before{background:#7c3aed;}
.rc-card-v{font-size:20px;font-weight:900;line-height:1;letter-spacing:-.3px;}
.rc-card-l{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#b0b0b0;margin-top:4px;}
.rc-card-s{font-size:10px;color:#94a3b8;margin-top:2px;}
.rc-tbl-wrap{overflow-x:auto;border-radius:14px;border:1.5px solid #ececec;background:#fff;margin:12px 0;}
.rc-tbl{width:100%;border-collapse:collapse;min-width:500px;}
.rc-tbl th{background:#f8f8f8;padding:8px 12px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;border-bottom:1.5px solid #ececec;text-align:left;}
.rc-tbl td{padding:9px 12px;font-size:12px;border-bottom:1px solid #f3f4f6;}
.rc-tbl tr:last-child td{border-bottom:none;}
.rc-tbl tr:hover td{background:#fafafa;}
.rc-sec{font-size:14px;font-weight:800;color:#0f0f0f;margin:22px 0 8px;display:flex;align-items:center;gap:8px;}
.rc-chip{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:700;}
.rc-chip-ok{background:#f0fdf4;color:#16a34a;}.rc-chip-miss{background:#fef2f2;color:#dc2626;}
.rc-chip-void{background:#fef3c7;color:#92400e;}
.rc-chip-cash{background:#f0fdf4;color:#16a34a;}.rc-chip-bank{background:#eff6ff;color:#1d4ed8;}
.rc-err{background:#fef2f2;border:1.5px solid #fecaca;border-radius:12px;padding:14px;color:#7f1d1d;font-size:12px;margin:12px 0;}
@media(max-width:640px){.rc-cards{grid-template-columns:repeat(2,1fr);}}
</style>

<div class="rc">
<div class="rc-hdr">
    <h2>🔍 Collection Reconcile</h2>
    <div class="rc-hdr-sub">CRM payments vs Cashbook — Starlink, Fiber, Cash, Bank — all income verified in real-time</div>
</div>

<form class="rc-form" method="GET">
    <input type="hidden" name="page" value="dashboard">
    <input type="hidden" name="tab" value="collection_reconcile">
    <input type="hidden" name="rc_run" value="1">
    <div><label>From</label><input type="date" name="rc_from" value="<?php echo htmlspecialchars($dateFrom); ?>"></div>
    <div><label>To</label><input type="date" name="rc_to" value="<?php echo htmlspecialchars($dateTo); ?>"></div>
    <button type="submit">🔍 Run Reconciliation</button>
    <?php if ($doRun && !$error): ?>
    <span style="font-size:11px;color:#16a34a;font-weight:600;">✅ <?php echo htmlspecialchars($dateFrom); ?> → <?php echo htmlspecialchars($dateTo); ?> — <?php echo count($apiPayments); ?> CRM payments scanned</span>
    <?php endif; ?>
</form>

<?php if ($error): ?><div class="rc-err">❌ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<?php if ($results): ?>
<?php $isClean = count($results['crm_only']) === 0 && count($results['cb_only']) === 0; ?>

<div class="rc-cards">
    <div class="rc-card blue">
        <div class="rc-card-v">$<?php echo number_format($results['crm_total'], 2); ?></div>
        <div class="rc-card-l">CRM Total</div>
        <div class="rc-card-s"><?php echo $results['crm_count']; ?> payments (all methods)</div>
    </div>
    <div class="rc-card green">
        <div class="rc-card-v">$<?php echo number_format($results['matched_total'], 2); ?></div>
        <div class="rc-card-l">In Cashbook</div>
        <div class="rc-card-s"><?php echo $results['matched_count']; ?> matched</div>
    </div>
    <div class="rc-card <?php echo count($results['crm_only']) > 0 ? 'red' : 'green'; ?>">
        <div class="rc-card-v" style="color:<?php echo count($results['crm_only']) > 0 ? '#dc2626' : '#16a34a'; ?>;"><?php echo count($results['crm_only']); ?></div>
        <div class="rc-card-l">Missing from Cashbook</div>
        <div class="rc-card-s">$<?php echo number_format($results['crm_only_total'], 2); ?></div>
    </div>
    <div class="rc-card slate">
        <div class="rc-card-v">$<?php echo number_format($results['cb_all_in'], 2); ?></div>
        <div class="rc-card-l">Cashbook All IN</div>
        <div class="rc-card-s">Including exchanges, manual</div>
    </div>
    <div class="rc-card amber">
        <div class="rc-card-v"><?php echo count($results['voided']); ?></div>
        <div class="rc-card-l">Voided Collections</div>
        <div class="rc-card-s">CRM payment deleted</div>
    </div>
    <div class="rc-card purple">
        <div class="rc-card-v"><?php echo count($results['cb_only']); ?></div>
        <div class="rc-card-l">Cashbook Orphans</div>
        <div class="rc-card-s">CRM payment gone</div>
    </div>
</div>

<?php if ($isClean): ?>
<div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:12px;padding:16px;text-align:center;margin:16px 0;">
    <span style="font-size:18px;">✅</span>
    <span style="font-size:14px;font-weight:800;color:#16a34a;margin-left:8px;">All CRM payments are in the cashbook — no gaps found</span>
</div>
<?php endif; ?>

<?php if (!empty($results['methods'])): ?>
<div class="rc-sec">💳 By Payment Method</div>
<div class="rc-tbl-wrap">
<table class="rc-tbl">
<thead><tr><th>Method</th><th style="text-align:right;">CRM Total</th><th style="text-align:center;">Payments</th><th style="text-align:center;">In Cashbook</th><th style="text-align:center;">Missing</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($results['methods'] as $mName => $m):
    $mMissing = $m['count'] - $m['matched'];
    $isCash = ($m['method_id'] === PaymentUuids::CASH);
?>
<tr>
    <td style="font-weight:700;"><span class="rc-chip <?php echo $isCash ? 'rc-chip-cash' : 'rc-chip-bank'; ?>"><?php echo htmlspecialchars($mName); ?></span></td>
    <td style="text-align:right;font-weight:700;">$<?php echo number_format($m['crm'], 2); ?></td>
    <td style="text-align:center;"><?php echo $m['count']; ?></td>
    <td style="text-align:center;color:#16a34a;font-weight:700;"><?php echo $m['matched']; ?></td>
    <td style="text-align:center;color:<?php echo $mMissing > 0 ? '#dc2626' : '#16a34a'; ?>;font-weight:700;"><?php echo $mMissing; ?></td>
    <td><?php echo $mMissing > 0 ? '<span class="rc-chip rc-chip-miss">'.$mMissing.' MISSING</span>' : '<span class="rc-chip rc-chip-ok">ALL SYNCED</span>'; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php if (!empty($results['crm_only'])): ?>
<div class="rc-sec"><span style="color:#dc2626;">❌</span> In CRM but NOT in Cashbook (<?php echo count($results['crm_only']); ?> — $<?php echo number_format($results['crm_only_total'], 2); ?>)</div>
<div style="font-size:10px;color:#94a3b8;margin:-4px 0 8px;">These CRM payments haven't posted to the cashbook yet. Nightly sync may catch them, or they need manual entry.</div>
<div class="rc-tbl-wrap">
<table class="rc-tbl">
<thead><tr><th>CRM ID</th><th>Date</th><th>Customer</th><th>Method</th><th style="text-align:right;">Amount</th><th>Agent</th><th>Note</th></tr></thead>
<tbody>
<?php foreach ($results['crm_only'] as $co): ?>
<tr>
    <td style="font-family:monospace;font-size:11px;">#<?php echo $co['crm_id']; ?></td>
    <td style="font-size:11px;"><?php echo $co['date']; ?></td>
    <td style="font-weight:600;"><?php echo htmlspecialchars($co['client_name']); ?></td>
    <td><span class="rc-chip <?php echo ($co['method_id'] === PaymentUuids::CASH) ? 'rc-chip-cash' : 'rc-chip-bank'; ?>"><?php echo htmlspecialchars($co['method']); ?></span></td>
    <td style="text-align:right;font-weight:700;color:#dc2626;">$<?php echo number_format($co['amount'], 2); ?></td>
    <td style="font-size:11px;"><?php echo htmlspecialchars($co['agent_name'] ?: '—'); ?></td>
    <td style="font-size:10px;color:#64748b;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars(substr($co['note'], 0, 50)); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php if (!empty($results['cb_only'])): ?>
<div class="rc-sec"><span style="color:#d97706;">⚠️</span> In Cashbook but NOT in CRM (<?php echo count($results['cb_only']); ?>)</div>
<div style="font-size:10px;color:#94a3b8;margin:-4px 0 8px;">Cashbook has these as CRM payments but the payment no longer exists in CRM. May have been deleted.</div>
<div class="rc-tbl-wrap">
<table class="rc-tbl">
<thead><tr><th>SR</th><th>Date</th><th>Person</th><th>Category</th><th style="text-align:right;">Amount</th><th>Ref</th><th>Source</th></tr></thead>
<tbody>
<?php foreach ($results['cb_only'] as $co): ?>
<tr style="background:#fefce8;">
    <td style="font-family:monospace;font-size:11px;"><?php echo htmlspecialchars($co['sr'] ?? ''); ?></td>
    <td style="font-size:11px;"><?php echo $co['date'] ?? ''; ?></td>
    <td style="font-weight:600;"><?php echo htmlspecialchars($co['person'] ?? ''); ?></td>
    <td><?php echo htmlspecialchars($co['category'] ?? ''); ?></td>
    <td style="text-align:right;font-weight:700;">$<?php echo number_format((float)($co['amount'] ?? 0), 2); ?></td>
    <td style="font-family:monospace;font-size:10px;"><?php echo htmlspecialchars($co['validation_ref'] ?? ''); ?></td>
    <td style="font-size:10px;"><?php echo htmlspecialchars($co['source'] ?? ''); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php if (!empty($results['voided'])): ?>
<div class="rc-sec"><span style="color:#92400e;">🚫</span> Voided Collections (<?php echo count($results['voided']); ?>)</div>
<div style="font-size:10px;color:#94a3b8;margin:-4px 0 8px;">CRM payment deleted — collection auto-voided and removed from agent's Field Register.</div>
<div class="rc-tbl-wrap">
<table class="rc-tbl">
<thead><tr><th>Col ID</th><th>Date</th><th>Agent</th><th>Customer</th><th style="text-align:right;">Amount</th><th>Voided By</th><th>Voided At</th></tr></thead>
<tbody>
<?php foreach ($results['voided'] as $v): ?>
<tr style="background:#fefce8;">
    <td style="font-family:monospace;font-size:11px;">COL-<?php echo $v['id'] ?? ''; ?></td>
    <td style="font-size:11px;"><?php echo substr($v['collected_at'] ?? $v['created_at'] ?? '', 0, 10); ?></td>
    <td style="font-weight:600;"><?php echo htmlspecialchars($v['retailer_name'] ?? ''); ?></td>
    <td><?php echo htmlspecialchars($v['customer_name'] ?? ''); ?></td>
    <td style="text-align:right;font-weight:700;color:#dc2626;text-decoration:line-through;">$<?php echo number_format((float)($v['amount'] ?? 0), 2); ?></td>
    <td style="font-size:11px;color:#64748b;"><?php echo htmlspecialchars($v['voided_by'] ?? ''); ?></td>
    <td style="font-size:11px;color:#64748b;"><?php echo $v['voided_at'] ?? ''; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php endif; ?>

<?php if (!$doRun && !$error): ?>
<div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:14px;padding:40px;text-align:center;margin-top:20px;">
    <div style="font-size:32px;margin-bottom:10px;">🔍</div>
    <div style="font-size:14px;font-weight:700;color:#0f0f0f;margin-bottom:4px;">Select a date range and click Run</div>
    <div style="font-size:11px;color:#94a3b8;">Compares ALL CRM payments (Starlink, Fiber, Cash, Bank) against the cashbook.<br>Default: yesterday to today. Use wider ranges to find older gaps.</div>
</div>
<?php endif; ?>
</div>
