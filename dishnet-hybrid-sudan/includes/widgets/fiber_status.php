<?php
/**
 * Fiber Service Status Widget
 * Shows ALL fiber customers (from invoice_cache tables) with their billing status.
 * Source of truth: CRM invoice_cache — covers legacy + KYC customers.
 *
 * Include from any dashboard. Requires $store in scope.
 */
$_fsPdo = $store->getPdo();

// Get all invoice_cache tables
$_fsTables = $_fsPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'invoice_cache_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);

$_fsClients = [];
$_fsCounts = ['active' => 0, 'inactive' => 0, 'invoiced_paid' => 0, 'invoiced_unpaid' => 0, 'no_invoice' => 0, 'no_crm_link' => 0];
$_fsTotalDue = 0;
$_fsMissingInv = 0;

foreach ($_fsTables as $_fsTbl) {
    $crmId = (int)str_replace('invoice_cache_', '', $_fsTbl);
    if ($crmId <= 0) continue;

    try {
        $row = $_fsPdo->query("SELECT data FROM {$_fsTbl} LIMIT 1")->fetchColumn();
        if (!$row) continue;
        $data = json_decode($row, true);
        if (!$data || empty($data['data'])) continue;

        $client   = $data['data']['client'] ?? [];
        $invoices = $data['data']['invoices'] ?? [];
        $unpaid   = $data['data']['invoices_unpaid'] ?? [];
        $totalDue = (float)($data['data']['total_due'] ?? 0);

        $name = $client['companyName'] ?: trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''));
        $isActive = !empty($client['isActive']);
        $phone = '';
        foreach ($client['contacts'] ?? [] as $ct) {
            if (!empty($ct['phone'])) { $phone = $ct['phone']; break; }
        }

        // Get splynxId from attributes
        $splynxId = '';
        foreach ($client['attributes'] ?? [] as $attr) {
            if (($attr['key'] ?? '') === 'splynxId') { $splynxId = $attr['value'] ?? ''; break; }
        }

        // Determine billing status
        $hasInvoices = count($invoices) > 0;
        $hasUnpaid = count($unpaid) > 0;
        if ($hasInvoices && !$hasUnpaid) {
            $billingStatus = 'paid';
            $_fsCounts['invoiced_paid']++;
        } elseif ($hasUnpaid) {
            $billingStatus = 'unpaid';
            $_fsCounts['invoiced_unpaid']++;
            $_fsTotalDue += $totalDue;
        } else {
            $billingStatus = 'no_invoice';
            $_fsCounts['no_invoice']++;
        }

        if ($isActive) $_fsCounts['active']++;
        else $_fsCounts['inactive']++;

        // Estimate missing invoice revenue from plan costs
        if ($billingStatus === 'no_invoice' && $isActive) {
            $_fsMissingInv += 50; // conservative $50 per untracked active service
        }

        $_fsClients[] = [
            'crm_id'        => $crmId,
            'name'          => $name,
            'phone'         => $phone,
            'splynx_id'     => $splynxId,
            'is_active'     => $isActive,
            'invoice_count' => count($invoices),
            'unpaid_count'  => count($unpaid),
            'total_due'     => $totalDue,
            'billing_status'=> $billingStatus,
        ];
    } catch (\Throwable $e) {
        continue;
    }
}

$_fsTotal = count($_fsClients);
$_fsIsAdmin = !empty($retailer['is_admin']);

// Sort: unpaid first, then no_invoice, then paid
usort($_fsClients, function($a, $b) {
    $order = ['unpaid' => 0, 'no_invoice' => 1, 'paid' => 2];
    $diff = ($order[$a['billing_status']] ?? 9) - ($order[$b['billing_status']] ?? 9);
    if ($diff !== 0) return $diff;
    return $b['total_due'] - $a['total_due'];
});
?>
<style>
.fs-wrap{background:#fff;border-radius:16px;border:1px solid #eee;padding:16px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.fs-title{font-size:15px;font-weight:800;color:#1e293b;margin-bottom:12px;}
.fs-kpi-row{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;}
.fs-kpi{flex:1;min-width:80px;background:#f8fafc;border-radius:10px;padding:10px;text-align:center;border:1px solid #f1f5f9;}
.fs-kpi-val{font-size:22px;font-weight:900;line-height:1;}
.fs-kpi-lbl{font-size:10px;color:#64748b;font-weight:600;text-transform:uppercase;margin-top:4px;}
.fs-bar-row{display:flex;align-items:center;gap:10px;margin-bottom:6px;cursor:pointer;padding:4px 6px;border-radius:8px;transition:background .15s;}
.fs-bar-row:hover{background:#f8fafc;}
.fs-bar{height:24px;border-radius:6px;display:flex;align-items:center;padding:0 10px;font-size:11px;font-weight:700;color:#fff;min-width:28px;}
.fs-bar-label{font-size:12px;font-weight:600;color:#64748b;width:110px;flex-shrink:0;text-align:right;}
.fs-bar-count{font-size:13px;font-weight:800;color:#1e293b;width:30px;text-align:right;flex-shrink:0;}
.fs-detail{display:none;background:#f8fafc;border-radius:10px;padding:10px 12px;margin:4px 0 8px;font-size:12px;max-height:300px;overflow-y:auto;}
.fs-detail.open{display:block;}
.fs-detail table{width:100%;border-collapse:collapse;}
.fs-detail th{text-align:left;font-size:10px;color:#94a3b8;text-transform:uppercase;padding:4px 6px;border-bottom:1px solid #e2e8f0;position:sticky;top:0;background:#f8fafc;}
.fs-detail td{padding:5px 6px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#334155;}
.fs-badge{display:inline-block;padding:1px 6px;border-radius:6px;font-size:10px;font-weight:700;}
.fs-badge-paid{background:#dcfce7;color:#166534;}
.fs-badge-unpaid{background:#fef2f2;color:#991b1b;}
.fs-badge-noinv{background:#fef3c7;color:#92400e;}
.fs-badge-active{background:#dbeafe;color:#1d4ed8;}
.fs-badge-inactive{background:#f1f5f9;color:#64748b;}
</style>

<div class="fs-wrap">
    <div class="fs-title">🔌 Fiber Service Status</div>

    <!-- KPI Row -->
    <div class="fs-kpi-row">
        <div class="fs-kpi">
            <div class="fs-kpi-val" style="color:#3b82f6;"><?= $_fsTotal ?></div>
            <div class="fs-kpi-lbl">Total Clients</div>
        </div>
        <div class="fs-kpi">
            <div class="fs-kpi-val" style="color:#10b981;"><?= $_fsCounts['active'] ?></div>
            <div class="fs-kpi-lbl">Active</div>
        </div>
        <div class="fs-kpi">
            <div class="fs-kpi-val" style="color:#ef4444;">$<?= number_format($_fsTotalDue, 0) ?></div>
            <div class="fs-kpi-lbl">Total Due</div>
        </div>
        <div class="fs-kpi">
            <div class="fs-kpi-val" style="color:#f59e0b;"><?= $_fsCounts['no_invoice'] ?></div>
            <div class="fs-kpi-lbl">No Invoice</div>
        </div>
    </div>

    <!-- Status Bars -->
    <?php
    $barMax = max(1, $_fsTotal);
    $bars = [
        ['key'=>'paid',    'label'=>'✅ Invoiced & Paid',  'color'=>'#22c55e', 'count'=>$_fsCounts['invoiced_paid']],
        ['key'=>'unpaid',  'label'=>'⚠️ Unpaid Invoices', 'color'=>'#ef4444', 'count'=>$_fsCounts['invoiced_unpaid']],
        ['key'=>'noinv',   'label'=>'🔴 No Invoice',       'color'=>'#f59e0b', 'count'=>$_fsCounts['no_invoice']],
        ['key'=>'inactive','label'=>'⏸ Inactive',          'color'=>'#94a3b8', 'count'=>$_fsCounts['inactive']],
    ];
    foreach ($bars as $b):
        $pct = max(6, round(($b['count'] / $barMax) * 100));
    ?>
    <div class="fs-bar-row" onclick="fsToggle('<?= $b['key'] ?>')">
        <div class="fs-bar-label"><?= $b['label'] ?></div>
        <div style="flex:1;"><div class="fs-bar" style="width:<?= $pct ?>%;background:<?= $b['color'] ?>;"><?= $b['count'] ?></div></div>
        <div class="fs-bar-count"><?= $b['count'] ?></div>
    </div>
    <div class="fs-detail" id="fsDetail_<?= $b['key'] ?>">
        <table>
            <tr><th>Customer</th><th>CRM</th><th>Status</th><th>Invoices</th><th>Due</th></tr>
            <?php foreach ($_fsClients as $fc):
                // Filter by bar type
                if ($b['key'] === 'paid' && $fc['billing_status'] !== 'paid') continue;
                if ($b['key'] === 'unpaid' && $fc['billing_status'] !== 'unpaid') continue;
                if ($b['key'] === 'noinv' && $fc['billing_status'] !== 'no_invoice') continue;
                if ($b['key'] === 'inactive' && $fc['is_active']) continue;
            ?>
            <tr>
                <td style="font-weight:600;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($fc['name']) ?>"><?= htmlspecialchars(substr($fc['name'], 0, 30)) ?></td>
                <td style="font-size:11px;">#<?= $fc['crm_id'] ?></td>
                <td><span class="fs-badge <?= $fc['is_active'] ? 'fs-badge-active' : 'fs-badge-inactive' ?>"><?= $fc['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td style="font-size:11px;"><?= $fc['invoice_count'] ?> inv<?= $fc['unpaid_count'] > 0 ? " ({$fc['unpaid_count']} unpaid)" : '' ?></td>
                <td style="font-weight:700;color:<?= $fc['total_due'] > 0 ? '#dc2626' : '#10b981' ?>;"><?= $fc['total_due'] > 0 ? '$'.number_format($fc['total_due'], 0) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endforeach; ?>

    <?php if ($_fsTotalDue > 0): ?>
    <div style="margin-top:10px;padding:10px 14px;background:#fef2f2;border-radius:10px;border:1px solid #fecaca;">
        <span style="font-size:13px;font-weight:800;color:#991b1b;">💰 Revenue at Risk: $<?= number_format($_fsTotalDue, 2) ?></span>
        <span style="font-size:11px;color:#dc2626;margin-left:8px;">(<?= $_fsCounts['invoiced_unpaid'] ?> clients with unpaid invoices)</span>
    </div>
    <?php endif; ?>
</div>

<script>
function fsToggle(key) {
    var el = document.getElementById('fsDetail_' + key);
    if (!el) return;
    var wasOpen = el.classList.contains('open');
    document.querySelectorAll('.fs-detail.open').forEach(function(d) { d.classList.remove('open'); });
    if (!wasOpen) el.classList.add('open');
}
</script>
