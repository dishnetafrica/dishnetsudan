<?php
/**
 * tabs/support/fiber_pipeline.php
 * DishNet Hybrid Telecom v4.11.3+
 *
 * Fiber Activation Pipeline -- tracks installations in progress and recent activations.
 * Data sources: SQLite tickets table, fiber_services_cache, fiber_collection_jobs, fiber_customer_map
 * No live Splynx API calls -- reads from local SQLite only (fast).
 *
 * Roles: support_leader, admin
 */

if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'support_leader') {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied.</div>';
    return;
}

$pdo   = $store->getPdo();
$today      = date('Y-m-d');
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');
$days30Ago  = date('Y-m-d', strtotime('-30 days'));
$days60Ago  = date('Y-m-d', strtotime('-60 days'));

// ── 1. ACTIVE PIPELINE — Live from Splynx ─────────────────────────────────
// Same rule as Fiber Finance plugin:
//   closed=1              → NOT pipeline
//   status=3 (Resolved)   → Completed, NOT pipeline
//   status=10/11/12       → Cancelled, NOT pipeline
//   EVERYTHING ELSE       → IN PIPELINE (customer waiting for installation)
// This means status 13 or any unknown future status is treated as open.
// No stale cache — we call Splynx live so the count always matches Splynx.

$completedStatuses = [3];
$cancelledStatuses = [10, 11, 12];
$statusLabels = [
    1  => 'New',
    2  => 'Work in Progress',
    3  => 'Resolved',
    4  => 'Waiting Your Answer',
    5  => 'Waiting on Agent',
    7  => 'Survey Done',
    8  => 'Fiber Deployment in Progress',
    9  => 'Ready ONU Mapped',
    10 => 'Cancel by Customer',
    11 => 'Fiber Not Available',
    12 => 'Client Not Ready',
    13 => 'Status-13',
];

$splynxApiError = '';
$pipelineRows   = [];
$liveTickets    = [];

require_once dirname(__DIR__, 2) . '/lib/SplynxApiClient.php';
$_splynxCfg = $store->load('kyc_config.json') ?: [];
$_splynx = SplynxApiClient::fromConfig($_splynxCfg);

if (!$_splynx->isConfigured()) {
    $splynxApiError = 'Splynx not configured — showing cached data.';
} else {
    $liveTickets = $_splynx->getTickets(['page' => 0, 'limit' => 500]);
    if (empty($liveTickets)) {
        $liveTickets = $_splynx->getTickets([]);
    }
    $err = $_splynx->getLastError();
    if (!empty($err)) {
        $splynxApiError = 'Splynx API: ' . ($err['message'] ?? json_encode($err));
    }
}

// Build local enrichment map from SQLite (CRM client ID, phone, area, engineer, plan)
$localMap = [];
try {
    $localRows = $pdo->query("
        SELECT t.id, t.crm_client_id, t.phone, t.area,
               t.assigned_engineer_name,
               fcj.plan_name, fcj.amount, fcj.status as job_status, fcj.id as job_id
        FROM tickets t
        LEFT JOIN fiber_collection_jobs fcj ON fcj.ticket_id = t.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($localRows as $lr) {
        $localMap[(int)$lr['id']] = $lr;
    }
} catch (\Throwable $e) {}

// Apply the simple open/closed rule to each live Splynx ticket
foreach ($liveTickets as $t) {
    $tid     = (int)($t['id'] ?? 0);
    $status  = (int)($t['status_id'] ?? $t['status'] ?? 0);
    $isClosed = ($t['closed'] ?? '0') === '1' || ($t['closed'] ?? 0) === 1;

    // Skip: closed flag, resolved, or cancelled
    if ($isClosed) continue;
    if (in_array($status, $completedStatuses)) continue;
    if (in_array($status, $cancelledStatuses)) continue;

    // Extract customer name from subject
    $subject  = $t['subject'] ?? '';
    $custName = preg_replace('/^(DishNet |Dishnet )?(New GPON |Fiber )?(Installation|Installtion)\s*[-—]?\s*/i', '', $subject);
    $custName = trim($custName) ?: $subject;

    // Extract FTTH number from subject
    $ftthNumber = '';
    if (preg_match('/D-FTTH[- ]?(\d+)/i', $subject, $fm)) {
        $ftthNumber = 'D-FTTH' . $fm[1];
    }

    $statusLbl = $statusLabels[$status] ?? ('Status-' . $status);

    // Merge with local SQLite enrichment (CRM ID, area, engineer, plan)
    $local = $localMap[$tid] ?? [];

    $pipelineRows[] = [
        'id'                    => $tid,
        'customer_name'         => $custName,
        'ftth_number'           => $ftthNumber,
        'status_label'          => $statusLbl,
        'crm_client_id'         => $local['crm_client_id'] ?? null,
        'phone'                 => $local['phone'] ?? ($t['customer_email'] ?? ''),
        'area'                  => $local['area'] ?? '',
        'assigned_engineer_name'=> $local['assigned_engineer_name'] ?? '',
        'plan_name'             => $local['plan_name'] ?? '',
        'amount'                => $local['amount'] ?? null,
        'job_status'            => $local['job_status'] ?? '',
        'job_id'                => $local['job_id'] ?? null,
        'created_at'            => $t['date_add'] ?? $t['created_at'] ?? '',
        'splynx_status'         => $status,
        'install_complete'      => 0,
        'install_complete_at'   => null,
    ];
}

// Sort by created_at ascending (oldest waiting first)
usort($pipelineRows, function($a, $b) {
    return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
});

//  2. RECENTLY COMPLETED: install_complete=1, last 60 days 
try {
    $completedRows = $pdo->query("
        SELECT t.id, t.customer_name, t.crm_client_id, t.phone, t.area,
               t.ftth_number, t.install_complete_at, t.assigned_engineer_name,
               fcj.plan_name, fcj.amount, fcj.status as job_status, fcj.id as job_id
        FROM tickets t
        LEFT JOIN fiber_collection_jobs fcj ON fcj.ticket_id = t.id
        WHERE t.install_complete = 1
          AND t.install_complete_at >= '{$days60Ago}'
        ORDER BY t.install_complete_at DESC
        LIMIT 60
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $completedRows = [];
}

//  3. RECENT ACTIVATIONS: from fiber_services_cache (Splynx services) 
$recentActivations = [];
$dailyCounts = [];
try {
    $actRows = $pdo->query("
        SELECT fsc.splynx_service_id, fsc.splynx_customer_id, fsc.customer_name,
               fsc.plan_name, fsc.splynx_status, fsc.created_at,
               fcm.crm_client_id, fcm.crm_name
        FROM fiber_services_cache fsc
        LEFT JOIN fiber_customer_map fcm ON fcm.splynx_customer_id = fsc.splynx_customer_id
        WHERE fsc.created_at >= '{$days60Ago}'
          AND fsc.splynx_status = 'active'
        ORDER BY fsc.created_at DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($actRows as $row) {
        $day = substr($row['created_at'] ?? '', 0, 10);
        if (!$day || $day < $days60Ago || $day > $today) continue;
        $recentActivations[] = $row;
        $dailyCounts[$day] = ($dailyCounts[$day] ?? 0) + 1;
    }
} catch (\Throwable $e) {}

//  4. KPI COUNTS 
$kpiPipeline   = count($pipelineRows);
$kpiCompleted  = count($completedRows);
$kpiToday      = count(array_filter($completedRows, fn($r) => substr($r['install_complete_at'] ?? '', 0, 10) === $today));
$kpiThisWeek   = count(array_filter($completedRows, fn($r) => substr($r['install_complete_at'] ?? '', 0, 10) >= $weekStart));
$kpiThisMonth  = count(array_filter($completedRows, fn($r) => substr($r['install_complete_at'] ?? '', 0, 10) >= $monthStart));
$kpiActivToday = count(array_filter($recentActivations, fn($r) => substr($r['created_at'] ?? '', 0, 10) === $today));
$kpiActiv30    = count(array_filter($recentActivations, fn($r) => substr($r['created_at'] ?? '', 0, 10) >= $days30Ago));

// Avg days between activations
$activDates = array_unique(array_map(fn($r) => substr($r['created_at'] ?? '', 0, 10), $recentActivations));
sort($activDates);
$avgGap = 0;
if (count($activDates) > 1) {
    $gaps = [];
    for ($i = 1; $i < count($activDates); $i++) {
        $gaps[] = (strtotime($activDates[$i]) - strtotime($activDates[$i - 1])) / 86400;
    }
    $avgGap = round(array_sum($gaps) / count($gaps), 1);
}

// Invoice pending count
$invPending = 0;
try {
    $invPending = (int)$pdo->query("SELECT COUNT(*) FROM fiber_collection_jobs WHERE status='pending'")->fetchColumn();
} catch (\Throwable $e) {}

// Status label colors
$statusColors = [
    'new'              => ['bg' => '#dbeafe', 'cl' => '#1e40af'],
    'work in progress' => ['bg' => '#fef3c7', 'cl' => '#92400e'],
    'survey done'      => ['bg' => '#ede9fe', 'cl' => '#6d28d9'],
    'fiber deployment' => ['bg' => '#ffedd5', 'cl' => '#9a3412'],
    'deploying'        => ['bg' => '#ffedd5', 'cl' => '#9a3412'],
    'ready onu mapped' => ['bg' => '#d1fae5', 'cl' => '#065f46'],
    'waiting'          => ['bg' => '#e0e7ff', 'cl' => '#3730a3'],
    'assigned'         => ['bg' => '#fce7f3', 'cl' => '#9d174d'],
];
$getStatusStyle = function(string $label) use ($statusColors): array {
    $key = strtolower(trim($label));
    foreach ($statusColors as $k => $v) {
        if (str_contains($key, $k)) return $v;
    }
    return ['bg' => '#f3f4f6', 'cl' => '#6b7280'];
};

// lastSync = time of this page load (we just called Splynx live above)
$lastSync = $splynxApiError ? '' : date('Y-m-d H:i:s');

// ── Write live count back to shared cache so NOC/My Jobs/nav badge stay in sync ──
if (!$splynxApiError) {
    try {
        $cachedSummary = $store->load('splynx_dashboard_cache.json') ?: [];
        $cachedSummary['total_pending'] = $kpiPipeline;
        $cachedSummary['live_pipeline_count'] = $kpiPipeline;
        $cachedSummary['live_updated_at'] = $lastSync;
        $store->save('splynx_dashboard_cache.json', $cachedSummary);
    } catch (\Throwable $e) {}
}
?>

<style>
.fp-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:20px;}
.fp-kpi{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;text-align:center;border-top:3px solid #d41c1c;}
.fp-kpi.green{border-top-color:#059669;}
.fp-kpi.blue{border-top-color:#3b82f6;}
.fp-kpi.purple{border-top-color:#7c3aed;}
.fp-kpi.orange{border-top-color:#ea580c;}
.fp-kpi.gray{border-top-color:#9ca3af;}
.fp-kpi-val{font-size:28px;font-weight:900;line-height:1;margin:4px 0;}
.fp-kpi-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;}
.fp-kpi-sub{font-size:10px;color:#9ca3af;margin-top:2px;}
.fp-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:16px;overflow:hidden;}
.fp-card-hd{padding:12px 16px;font-weight:700;font-size:13px;background:#f9fafb;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:8px;}
.fp-table{width:100%;border-collapse:collapse;font-size:12px;}
.fp-table th{padding:8px 10px;background:#f1f5f9;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:#64748b;border-bottom:2px solid #e2e8f0;white-space:nowrap;}
.fp-table td{padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.fp-table tr:hover td{background:#f8faff;}
.fp-badge{display:inline-block;padding:2px 9px;border-radius:10px;font-weight:700;font-size:10px;white-space:nowrap;}
.fp-days{display:inline-block;padding:2px 8px;border-radius:10px;font-weight:800;font-size:11px;}
.fp-chart-bar{display:flex;align-items:flex-end;gap:2px;height:100px;padding:0 4px;}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
    <div>
        <h5 style="margin:0;font-weight:800;font-size:16px;"> Fiber Installation Pipeline</h5>
        <?php if ($splynxApiError): ?>
        <span style="font-size:11px;color:#dc2626;font-weight:700;">⚠️ <?= htmlspecialchars($splynxApiError) ?></span>
        <?php else: ?>
        <span style="font-size:11px;color:#059669;font-weight:600;">● Live from Splynx — <?= htmlspecialchars(substr($lastSync, 0, 16)) ?></span>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;">
        <?php if ($invPending > 0): ?>
        <a href="?page=dashboard&tab=invoice_queue" style="background:#d41c1c;color:#fff;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;text-decoration:none;">
             <?= $invPending ?> Invoice<?= $invPending > 1 ? 's' : '' ?> Pending
        </a>
        <?php endif; ?>
        <a href="?page=dashboard&tab=splynx_noc" style="background:#f1f5f9;color:#374151;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;text-decoration:none;border:1px solid #e2e8f0;">
             Splynx NOC
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="fp-kpi-grid">
    <div class="fp-kpi" style="border-top-color:#d41c1c;">
        <div class="fp-kpi-lbl">In Pipeline</div>
        <div class="fp-kpi-val" style="color:#d41c1c;"><?= $kpiPipeline ?></div>
        <div class="fp-kpi-sub">open installs</div>
    </div>
    <div class="fp-kpi green">
        <div class="fp-kpi-lbl">Done Today</div>
        <div class="fp-kpi-val" style="color:#059669;"><?= $kpiToday ?></div>
        <div class="fp-kpi-sub">completed</div>
    </div>
    <div class="fp-kpi blue">
        <div class="fp-kpi-lbl">This Week</div>
        <div class="fp-kpi-val" style="color:#3b82f6;"><?= $kpiThisWeek ?></div>
        <div class="fp-kpi-sub">installs done</div>
    </div>
    <div class="fp-kpi purple">
        <div class="fp-kpi-lbl">This Month</div>
        <div class="fp-kpi-val" style="color:#7c3aed;"><?= $kpiThisMonth ?></div>
        <div class="fp-kpi-sub"><?= date('F') ?></div>
    </div>
    <div class="fp-kpi orange">
        <div class="fp-kpi-lbl">Avg Gap</div>
        <div class="fp-kpi-val" style="color:#ea580c;"><?= $avgGap ?: '--' ?></div>
        <div class="fp-kpi-sub">days/activation</div>
    </div>
    <div class="fp-kpi gray">
        <div class="fp-kpi-lbl">Active 30d</div>
        <div class="fp-kpi-val" style="color:#6b7280;"><?= $kpiActiv30 ?></div>
        <div class="fp-kpi-sub">Splynx services</div>
    </div>
</div>

<!-- Daily Activations Chart -->
<?php if (!empty($dailyCounts)): ?>
<div class="fp-card" style="margin-bottom:16px;">
    <div class="fp-card-hd"> Daily Activations -- Last 30 Days</div>
    <div style="padding:16px;">
        <div class="fp-chart-bar">
        <?php
        $maxCount = max(1, max(array_values($dailyCounts)));
        for ($d = 29; $d >= 0; $d--):
            $day = date('Y-m-d', strtotime("-{$d} days"));
            $cnt = $dailyCounts[$day] ?? 0;
            $h   = $cnt > 0 ? max(8, round(($cnt / $maxCount) * 90)) : 2;
            $bg  = $cnt > 0 ? ($day === $today ? '#d41c1c' : '#3b82f6') : '#e5e7eb';
            $isLabel = ($d % 7 === 0 || $day === $today);
        ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;" title="<?= date('D d M', strtotime($day)) ?>: <?= $cnt ?> activation<?= $cnt != 1 ? 's' : '' ?>">
            <?php if ($cnt > 0): ?><span style="font-size:9px;font-weight:700;color:#374151;"><?= $cnt ?></span><?php endif; ?>
            <div style="width:100%;height:<?= $h ?>px;background:<?= $bg ?>;border-radius:3px 3px 0 0;min-width:6px;"></div>
            <?php if ($isLabel): ?><span style="font-size:8px;color:#9ca3af;white-space:nowrap;"><?= date('d/m', strtotime($day)) ?></span><?php endif; ?>
        </div>
        <?php endfor; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Active Pipeline -->
<div class="fp-card" style="border:2px solid #d41c1c;">
    <div class="fp-card-hd" style="background:linear-gradient(135deg,#7f1d1d,#d41c1c);color:#fff;">
         Active Pipeline -- <?= $kpiPipeline ?> Installation<?= $kpiPipeline != 1 ? 's' : '' ?> Pending
        <span style="margin-left:auto;font-size:10px;font-weight:400;opacity:.8;">Tickets not yet completed</span>
    </div>
    <?php if (empty($pipelineRows)): ?>
    <div style="padding:32px;text-align:center;color:#9ca3af;font-size:13px;">
         No pending installations -- all tickets are complete or cancelled.
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="fp-table">
        <thead>
            <tr>
                <th>#</th>
                <th>CUSTOMER</th>
                <th>FTTH No.</th>
                <th>STATUS</th>
                <th>AREA</th>
                <th>ENGINEER</th>
                <th>PLAN</th>
                <th>WAITING</th>
                <th>ACTIONS</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pipelineRows as $idx => $row):
            $created     = substr($row['created_at'] ?? '', 0, 10);
            $daysWaiting = $created ? max(0, (int)round((strtotime($today) - strtotime($created)) / 86400)) : 0;
            $daysBg      = $daysWaiting > 14 ? '#fee2e2' : ($daysWaiting > 7 ? '#fef3c7' : '#d1fae5');
            $daysCl      = $daysWaiting > 14 ? '#991b1b' : ($daysWaiting > 7 ? '#92400e' : '#065f46');
            $stStyle     = $getStatusStyle($row['status_label'] ?? '');
            $rowBg       = $daysWaiting > 14 ? 'background:#fff7ed;' : '';
        ?>
        <tr style="<?= $rowBg ?>">
            <td style="color:#9ca3af;font-weight:700;"><?= $idx + 1 ?></td>
            <td>
                <strong style="font-size:12px;"><?= htmlspecialchars($row['customer_name'] ?? '--') ?></strong>
                <?php if ($row['crm_client_id']): ?>
                <br><a href="?page=dashboard&tab=customer_lookup&crm_id=<?= (int)$row['crm_client_id'] ?>" style="font-size:10px;color:#3b82f6;">CRM #<?= (int)$row['crm_client_id'] ?></a>
                <?php endif; ?>
                <?php if ($row['phone']): ?>
                <br><span style="font-size:10px;color:#6b7280;"><?= htmlspecialchars($row['phone']) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($row['ftth_number']): ?>
                <span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:6px;font-weight:700;font-size:11px;"><?= htmlspecialchars($row['ftth_number']) ?></span>
                <?php else: ?>
                <span style="color:#d1d5db;">--</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="fp-badge" style="background:<?= $stStyle['bg'] ?>;color:<?= $stStyle['cl'] ?>;">
                    <?= htmlspecialchars(ucwords($row['status_label'] ?? 'New')) ?>
                </span>
            </td>
            <td style="color:#374151;font-size:11px;"><?= htmlspecialchars($row['area'] ?? '--') ?></td>
            <td style="font-size:11px;">
                <?php if ($row['assigned_engineer_name']): ?>
                <span style="color:#059669;font-weight:600;"> <?= htmlspecialchars($row['assigned_engineer_name']) ?></span>
                <?php else: ?>
                <span style="color:#dc2626;font-weight:600;"> Unassigned</span>
                <?php endif; ?>
            </td>
            <td style="font-size:11px;color:#374151;">
                <?php if ($row['plan_name']): ?>
                <?= htmlspecialchars($row['plan_name']) ?>
                <?php if ($row['amount']): ?>
                <br><span style="color:#059669;font-weight:700;">$<?= number_format((float)$row['amount'], 0) ?>/mo</span>
                <?php endif; ?>
                <?php else: ?><span style="color:#d1d5db;">--</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="fp-days" style="background:<?= $daysBg ?>;color:<?= $daysCl ?>;">
                    <?= $daysWaiting ?>d
                </span>
                <br><span style="font-size:9px;color:#9ca3af;"><?= $created ?: '--' ?></span>
            </td>
            <td>
                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                    <a href="?page=dashboard&tab=splynx_noc&highlight=<?= (int)$row['id'] ?>"
                       style="font-size:10px;background:#f1f5f9;color:#374151;padding:3px 8px;border-radius:6px;text-decoration:none;border:1px solid #e2e8f0;white-space:nowrap;">
                         NOC
                    </a>
                    <?php if ($row['crm_client_id']): ?>
                    <a href="https://crm.dishnetafrica.com/crm/client/<?= (int)$row['crm_client_id'] ?>/detail"
                       target="_blank"
                       style="font-size:10px;background:#dbeafe;color:#1e40af;padding:3px 8px;border-radius:6px;text-decoration:none;white-space:nowrap;">
                        CRM 
                    </a>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Recently Completed Installations -->
<div class="fp-card">
    <div class="fp-card-hd" style="background:linear-gradient(135deg,#064e3b,#059669);color:#fff;">
         Recently Completed -- <?= $kpiCompleted ?> Installation<?= $kpiCompleted != 1 ? 's' : '' ?> (Last 60 Days)
        <span style="margin-left:auto;font-size:10px;font-weight:400;opacity:.8;">Newest first</span>
    </div>
    <?php if (empty($completedRows)): ?>
    <div style="padding:32px;text-align:center;color:#9ca3af;font-size:13px;">No completed installations in the last 60 days.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="fp-table">
        <thead>
            <tr>
                <th>COMPLETED</th>
                <th>CUSTOMER</th>
                <th>FTTH No.</th>
                <th>AREA</th>
                <th>ENGINEER</th>
                <th>PLAN</th>
                <th>INVOICE</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($completedRows as $row):
            $compDate = substr($row['install_complete_at'] ?? '', 0, 10);
            $isToday  = $compDate === $today;
        ?>
        <tr<?= $isToday ? ' style="background:#f0fdf4;"' : '' ?>>
            <td>
                <strong style="color:<?= $isToday ? '#059669' : '#374151' ?>;"><?= htmlspecialchars($compDate ?: '--') ?></strong>
                <?php if ($isToday): ?><br><span style="font-size:10px;background:#d1fae5;color:#065f46;padding:1px 6px;border-radius:4px;font-weight:700;">TODAY</span><?php endif; ?>
            </td>
            <td>
                <strong style="font-size:12px;"><?= htmlspecialchars($row['customer_name'] ?? '--') ?></strong>
                <?php if ($row['crm_client_id']): ?>
                <br><a href="?page=dashboard&tab=customer_lookup&crm_id=<?= (int)$row['crm_client_id'] ?>" style="font-size:10px;color:#3b82f6;">CRM #<?= (int)$row['crm_client_id'] ?></a>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($row['ftth_number']): ?>
                <span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:6px;font-weight:700;font-size:11px;"><?= htmlspecialchars($row['ftth_number']) ?></span>
                <?php else: ?><span style="color:#d1d5db;">--</span><?php endif; ?>
            </td>
            <td style="font-size:11px;"><?= htmlspecialchars($row['area'] ?? '--') ?></td>
            <td style="font-size:11px;">
                <?php if ($row['assigned_engineer_name']): ?>
                <span style="color:#059669;"> <?= htmlspecialchars($row['assigned_engineer_name']) ?></span>
                <?php else: ?><span style="color:#9ca3af;">--</span><?php endif; ?>
            </td>
            <td style="font-size:11px;">
                <?= htmlspecialchars($row['plan_name'] ?? '--') ?>
                <?php if ($row['amount']): ?>
                <span style="color:#059669;font-weight:700;"> $<?= number_format((float)$row['amount'], 0) ?>/mo</span>
                <?php endif; ?>
            </td>
            <td>
                <?php
                $jStatus = $row['job_status'] ?? '';
                if ($jStatus === 'invoiced'):
                ?><span class="fp-badge" style="background:#d1fae5;color:#065f46;"> Invoiced</span>
                <?php elseif ($jStatus === 'pending'): ?>
                <a href="?page=dashboard&tab=invoice_queue" class="fp-badge" style="background:#fee2e2;color:#991b1b;text-decoration:none;"> Pending</a>
                <?php elseif ($jStatus === 'skipped'): ?>
                <span class="fp-badge" style="background:#f3f4f6;color:#6b7280;"> Skipped</span>
                <?php else: ?>
                <span style="color:#d1d5db;font-size:11px;">--</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Recent Splynx Service Activations -->
<?php if (!empty($recentActivations)): ?>
<div class="fp-card">
    <div class="fp-card-hd">
         Splynx Service Activations -- Last 60 Days (<?= count($recentActivations) ?> total)
        <span style="margin-left:auto;font-size:10px;font-weight:400;color:#6b7280;">From fiber_services_cache</span>
    </div>
    <div style="overflow-x:auto;">
    <table class="fp-table">
        <thead>
            <tr><th>DATE</th><th>CUSTOMER</th><th>PLAN</th><th>STATUS</th><th>CRM LINKED</th></tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($recentActivations, 0, 50) as $act):
            $actDate = substr($act['created_at'] ?? '', 0, 10);
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($actDate) ?></strong><br><span style="font-size:10px;color:#9ca3af;"><?= date('D', strtotime($actDate ?: 'today')) ?></span></td>
            <td>
                <strong><?= htmlspecialchars($act['customer_name'] ?? '--') ?></strong>
                <br><span style="font-size:10px;color:#6b7280;">Splynx #<?= htmlspecialchars($act['splynx_customer_id'] ?? '') ?></span>
            </td>
            <td style="font-size:11px;"><?= htmlspecialchars($act['plan_name'] ?? '--') ?></td>
            <td><span class="fp-badge" style="background:#d1fae5;color:#065f46;"><?= htmlspecialchars(ucfirst($act['splynx_status'] ?? '')) ?></span></td>
            <td>
                <?php if ($act['crm_client_id']): ?>
                <a href="?page=dashboard&tab=customer_lookup&crm_id=<?= (int)$act['crm_client_id'] ?>" style="font-size:11px;color:#3b82f6;font-weight:600;">CRM #<?= (int)$act['crm_client_id'] ?></a>
                <?php else: ?>
                <span style="color:#f59e0b;font-size:10px;font-weight:700;"> Not linked</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
