<?php
/**
 * KYC Lifecycle Funnel Widget
 * Include from any dashboard. Requires $store and $pdo in scope.
 *
 * Usage: include __DIR__ . '/../includes/widgets/kyc_funnel.php';
 */
require_once dirname(__DIR__, 2) . '/lib/KycFunnelService.php';
$_funnelSvc = new KycFunnelService($store->getPdo(), $store);
$_funnel = $_funnelSvc->getFunnel();
$_fc = $_funnel['counts'];
$_ff = $_funnel['fiber'];
$_fs = $_funnel['starlink'];
$_fTotal = $_funnel['total'];
$_fMaxBar = max(1, $_fc['kyc']); // for bar width calculation
$_fExcluded = $_funnel['excluded'] ?? [];
$_fIsAdmin = !empty($retailer['is_admin']);

// Pre-compute stuck-at counts
$_fStuck = [
    'kyc'         => $_fc['kyc'] - $_fc['crm'],
    'crm'         => $_fc['crm'] - $_fc['provisioned'],
    'provisioned' => $_fc['provisioned'] - $_fc['installed'],
    'installed'   => $_fc['installed'] - $_fc['invoiced'],
    'invoiced'    => $_fc['invoiced'] - $_fc['paid'],
];
?>
<style>
.kyc-funnel{background:#fff;border-radius:16px;border:1px solid #eee;padding:16px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.kyc-funnel-title{font-size:15px;font-weight:800;color:#1e293b;margin-bottom:12px;}
.kyc-funnel-row{display:flex;align-items:center;gap:10px;margin-bottom:6px;cursor:pointer;padding:4px 6px;border-radius:8px;transition:background .15s;}
.kyc-funnel-row:hover{background:#f8fafc;}
.kyc-funnel-bar{height:26px;border-radius:6px;display:flex;align-items:center;padding:0 10px;font-size:12px;font-weight:700;color:#fff;min-width:32px;transition:width .5s ease;}
.kyc-funnel-label{font-size:12px;font-weight:600;color:#64748b;width:80px;flex-shrink:0;text-align:right;}
.kyc-funnel-count{font-size:14px;font-weight:800;color:#1e293b;width:30px;text-align:right;flex-shrink:0;}
.kyc-funnel-stuck{font-size:10px;color:#ef4444;font-weight:700;margin-left:4px;}
.kyc-funnel-split{display:flex;gap:16px;margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9;}
.kyc-funnel-split-item{font-size:11px;color:#64748b;font-weight:600;}
.kyc-funnel-split-item span{font-weight:800;color:#1e293b;}

/* Detail panel */
.kyc-funnel-detail{display:none;background:#f8fafc;border-radius:10px;padding:10px 12px;margin:4px 0 8px;font-size:12px;}
.kyc-funnel-detail.open{display:block;}
.kyc-funnel-detail table{width:100%;border-collapse:collapse;}
.kyc-funnel-detail th{text-align:left;font-size:10px;color:#94a3b8;text-transform:uppercase;padding:4px 6px;border-bottom:1px solid #e2e8f0;}
.kyc-funnel-detail td{padding:5px 6px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#334155;}
.kyc-funnel-detail .badge{display:inline-block;padding:1px 6px;border-radius:6px;font-size:10px;font-weight:700;}
.kyc-funnel-detail .badge-fiber{background:#dbeafe;color:#1d4ed8;}
.kyc-funnel-detail .badge-starlink{background:#fef3c7;color:#92400e;}
</style>

<div class="kyc-funnel">
    <div class="kyc-funnel-title">📊 KYC Lifecycle Funnel</div>

    <?php
    $stages = [
        ['key'=>'kyc',         'label'=>'KYC',       'color'=>'#94a3b8', 'icon'=>'📋'],
        ['key'=>'crm',         'label'=>'In CRM',    'color'=>'#3b82f6', 'icon'=>'🏢'],
        ['key'=>'provisioned', 'label'=>'Ticket',     'color'=>'#8b5cf6', 'icon'=>'🎫'],
        ['key'=>'installed',   'label'=>'Installed',  'color'=>'#f59e0b', 'icon'=>'🔧'],
        ['key'=>'invoiced',    'label'=>'Invoiced',   'color'=>'#10b981', 'icon'=>'🧾'],
        ['key'=>'paid',        'label'=>'Paid',       'color'=>'#22c55e', 'icon'=>'✅'],
    ];
    foreach ($stages as $s):
        $count = (int)$_fc[$s['key']];
        $pct = $_fMaxBar > 0 ? max(8, round(($count / $_fMaxBar) * 100)) : 8;
        $stuck = max(0, (int)($_fStuck[$s['key']] ?? 0));
    ?>
    <div class="kyc-funnel-row" onclick="kycFunnelToggle('<?= $s['key'] ?>')">
        <div class="kyc-funnel-label"><?= $s['icon'] ?> <?= $s['label'] ?></div>
        <div style="flex:1;">
            <div class="kyc-funnel-bar" style="width:<?= $pct ?>%;background:<?= $s['color'] ?>;"><?= $count ?></div>
        </div>
        <div class="kyc-funnel-count"><?= $count ?></div>
        <?php if ($stuck > 0 && $s['key'] !== 'paid'): ?>
        <div class="kyc-funnel-stuck">▼<?= $stuck ?> stuck</div>
        <?php endif; ?>
    </div>
    <div class="kyc-funnel-detail" id="kycFunnel_<?= $s['key'] ?>">
        <table>
            <tr><th>Customer</th><th>Type</th><th>Status</th><th>Detail</th><?php if ($_fIsAdmin): ?><th></th><?php endif; ?></tr>
            <?php
            // Show customers AT this stage (reached it but not next)
            foreach ($_funnel['customers'] as $cust):
                $hasStage = $cust['s' . (array_search($s['key'], array_column($stages, 'key')) + 1) . '_' . $s['key']] ?? false;
                // For display: show customers whose highest stage is this one
                if ($cust['stage'] !== (array_search($s['key'], array_column($stages, 'key')) + 1)) continue;
            ?>
            <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($cust['name'] ?: 'DN-'.$cust['app_id']) ?></td>
                <td><span class="badge badge-<?= strtolower($cust['type']) ?>"><?= $cust['type'] ?></span></td>
                <td><?= htmlspecialchars($cust['kyc_status']) ?></td>
                <td style="font-size:11px;color:#64748b;">
                    <?php if ($cust['ticket_id']): ?>T#<?= $cust['ticket_id'] ?> <?= $cust['ticket_status'] ?><?php endif; ?>
                    <?php if ($cust['invoice_count']): ?> <?= $cust['invoice_count'] ?> inv<?php endif; ?>
                    <?php if ($cust['unpaid_count']): ?> (<?= $cust['unpaid_count'] ?> unpaid)<?php endif; ?>
                    <?php if ($cust['area'] && $cust['area'] !== 'Unknown'): ?> · <?= htmlspecialchars($cust['area']) ?><?php endif; ?>
                </td>
                <?php if ($_fIsAdmin): ?>
                <td style="white-space:nowrap;">
                    <select onchange="kycFunnelExclude(<?= $cust['app_id'] ?>, this.value, this)" style="font-size:10px;padding:2px 4px;border:1px solid #e2e8f0;border-radius:4px;color:#64748b;background:#fff;">
                        <option value="">—</option>
                        <option value="cancelled">❌ Cancelled</option>
                        <option value="demo">🧪 Demo</option>
                        <option value="duplicate">📋 Duplicate</option>
                    </select>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endforeach; ?>

    <div class="kyc-funnel-split">
        <div class="kyc-funnel-split-item">🔌 Fiber: <span><?= $_ff['kyc'] ?></span> KYC → <span><?= $_ff['installed'] ?></span> installed → <span><?= $_ff['paid'] ?></span> paid</div>
        <div class="kyc-funnel-split-item">📡 Starlink: <span><?= $_fs['kyc'] ?></span> KYC → <span><?= $_fs['installed'] ?></span> installed → <span><?= $_fs['paid'] ?></span> paid</div>
    </div>

    <?php if ($_fIsAdmin && !empty($_fExcluded)): ?>
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9;">
        <div style="font-size:11px;font-weight:700;color:#94a3b8;cursor:pointer;" onclick="kycFunnelToggle('excluded')">
            🚫 <?= count($_fExcluded) ?> excluded (cancelled/demo/duplicate) ▾
        </div>
        <div class="kyc-funnel-detail" id="kycFunnel_excluded">
            <table>
                <tr><th>Customer</th><th>Type</th><th>Reason</th><th></th></tr>
                <?php foreach ($_fExcluded as $ex): ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($ex['name'] ?: 'DN-'.$ex['app_id']) ?></td>
                    <td><span class="badge badge-<?= strtolower($ex['type']) ?>"><?= $ex['type'] ?></span></td>
                    <td style="font-size:11px;"><?= $ex['reason'] === 'demo' ? '🧪 Demo' : ($ex['reason'] === 'duplicate' ? '📋 Duplicate' : '❌ Cancelled') ?></td>
                    <td><button onclick="kycFunnelRestore(<?= $ex['app_id'] ?>)" style="font-size:10px;padding:2px 8px;border:1px solid #d1d5db;border-radius:4px;background:#fff;cursor:pointer;color:#3b82f6;font-weight:700;">Restore</button></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function kycFunnelToggle(key) {
    var el = document.getElementById('kycFunnel_' + key);
    if (!el) return;
    var wasOpen = el.classList.contains('open');
    document.querySelectorAll('.kyc-funnel-detail.open').forEach(function(d) { d.classList.remove('open'); });
    if (!wasOpen) el.classList.add('open');
}

function kycFunnelExclude(appId, reason, selectEl) {
    if (!reason) return;
    if (!confirm('Exclude DN-' + appId + ' as ' + reason + '?')) { selectEl.value = ''; return; }
    var fd = new FormData();
    fd.append('app_id', appId);
    fd.append('reason', reason);
    fetch('?page=api&action=kyc_exclude', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.status === 'success') location.reload();
        else alert('Error: ' + (d.message || '?'));
    })
    .catch(function() { alert('Network error'); });
}

function kycFunnelRestore(appId) {
    if (!confirm('Restore DN-' + appId + ' back into funnel?')) return;
    var fd = new FormData();
    fd.append('app_id', appId);
    fetch('?page=api&action=kyc_restore', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.status === 'success') location.reload();
        else alert('Error: ' + (d.message || '?'));
    })
    .catch(function() { alert('Network error'); });
}
</script>
