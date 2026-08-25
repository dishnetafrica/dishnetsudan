<?php
/**
 * Customer Lifecycle Dashboard
 * 
 * Unified view for tracking customers from registration through active service.
 * Supports: Starlink, Fiber, LTE, SIM
 * 
 * @package DishNet Hybrid Telecom
 * @since v4.8.56
 */

// ── Access check ─────────────────────────────────────────────────────────────
if (!$isAdmin && !$can('support_dash') && !$can('customer_lookup')) {
    echo '<div class="kyc-alert error">Access denied</div>';
    return;
}

// ── Ensure migration has run ─────────────────────────────────────────────────
try {
    $store->getPdo()->query("SELECT 1 FROM service_lifecycle LIMIT 1");
} catch (Throwable $e) {
    // Run migration if table doesn't exist
    $migrationSql = file_get_contents(__DIR__ . '/../../migrations/030_service_lifecycle.sql');
    if ($migrationSql) {
        $statements = array_filter(array_map('trim', explode(';', $migrationSql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt) && stripos($stmt, '--') !== 0) {
                try {
                    $store->getPdo()->exec($stmt);
                } catch (Throwable $ex) {
                    // Ignore errors (table may already exist)
                }
            }
        }
    }
}

// ── Get filter parameters ────────────────────────────────────────────────────
$serviceFilter = $_GET['service'] ?? 'all';
$stageFilter   = $_GET['stage'] ?? 'all';
$searchQuery   = trim($_GET['q'] ?? '');

// ── Stage definitions per service ────────────────────────────────────────────
$stageDefinitions = [
    'starlink' => [
        'registered'        => ['label' => 'Registered', 'icon' => '📋', 'color' => '#6b7280'],
        'pending_payment'   => ['label' => 'Awaiting Payment', 'icon' => '💰', 'color' => '#f59e0b'],
        'pending_location'  => ['label' => 'Pending Location', 'icon' => '📍', 'color' => '#ef4444'],
        'location_received' => ['label' => 'Location Received', 'icon' => '✅', 'color' => '#3b82f6'],
        'activating'        => ['label' => 'Activating', 'icon' => '⏳', 'color' => '#8b5cf6'],
        'active'            => ['label' => 'Active', 'icon' => '🟢', 'color' => '#22c55e'],
        'overdue'           => ['label' => 'Overdue', 'icon' => '⚠️', 'color' => '#ef4444'],
        'suspended'         => ['label' => 'Suspended', 'icon' => '🔴', 'color' => '#dc2626'],
        'return_requested'  => ['label' => 'Return Requested', 'icon' => '📦', 'color' => '#6b7280'],
        'returned'          => ['label' => 'Returned', 'icon' => '↩️', 'color' => '#9ca3af'],
        'cancelled'         => ['label' => 'Cancelled', 'icon' => '❌', 'color' => '#9ca3af'],
    ],
    'fiber' => [
        'registered'           => ['label' => 'Registered', 'icon' => '📋', 'color' => '#6b7280'],
        'pending_payment'      => ['label' => 'Awaiting Payment', 'icon' => '💰', 'color' => '#f59e0b'],
        'pending_survey'       => ['label' => 'Survey Pending', 'icon' => '🔍', 'color' => '#f59e0b'],
        'survey_done'          => ['label' => 'Survey Complete', 'icon' => '✅', 'color' => '#3b82f6'],
        'pending_installation' => ['label' => 'Installation Pending', 'icon' => '🔧', 'color' => '#8b5cf6'],
        'active'               => ['label' => 'Active', 'icon' => '🟢', 'color' => '#22c55e'],
        'overdue'              => ['label' => 'Overdue', 'icon' => '⚠️', 'color' => '#ef4444'],
        'suspended'            => ['label' => 'Suspended', 'icon' => '🔴', 'color' => '#dc2626'],
        'cancelled'            => ['label' => 'Cancelled', 'icon' => '❌', 'color' => '#9ca3af'],
    ],
    'lte' => [
        'registered'            => ['label' => 'Registered', 'icon' => '📋', 'color' => '#6b7280'],
        'pending_payment'       => ['label' => 'Awaiting Payment', 'icon' => '💰', 'color' => '#f59e0b'],
        'pending_signal_check'  => ['label' => 'Signal Check', 'icon' => '📶', 'color' => '#f59e0b'],
        'pending_installation'  => ['label' => 'Installation', 'icon' => '🔧', 'color' => '#8b5cf6'],
        'active'                => ['label' => 'Active', 'icon' => '🟢', 'color' => '#22c55e'],
        'overdue'               => ['label' => 'Overdue', 'icon' => '⚠️', 'color' => '#ef4444'],
        'suspended'             => ['label' => 'Suspended', 'icon' => '🔴', 'color' => '#dc2626'],
        'cancelled'             => ['label' => 'Cancelled', 'icon' => '❌', 'color' => '#9ca3af'],
    ],
    'sim' => [
        'registered' => ['label' => 'Registered', 'icon' => '📋', 'color' => '#6b7280'],
        'active'     => ['label' => 'Active', 'icon' => '🟢', 'color' => '#22c55e'],
        'overdue'    => ['label' => 'Overdue', 'icon' => '⚠️', 'color' => '#ef4444'],
        'suspended'  => ['label' => 'Suspended', 'icon' => '🔴', 'color' => '#dc2626'],
        'cancelled'  => ['label' => 'Cancelled', 'icon' => '❌', 'color' => '#9ca3af'],
    ],
];

// ── Get stage counts ─────────────────────────────────────────────────────────
$stageCounts = [];
$totalCount = 0;
$needsActionCount = 0;

try {
    $pdo = $store->getPdo();
    
    // Count by service type and stage
    $sql = "SELECT service_type, stage, COUNT(*) as cnt, 
                   SUM(CASE WHEN needs_action = 1 THEN 1 ELSE 0 END) as action_cnt
            FROM service_lifecycle 
            WHERE deleted_at IS NULL
            GROUP BY service_type, stage";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $row) {
        $svc = strtolower($row['service_type']);
        $stg = $row['stage'];
        if (!isset($stageCounts[$svc])) {
            $stageCounts[$svc] = [];
        }
        $stageCounts[$svc][$stg] = (int)$row['cnt'];
        $totalCount += (int)$row['cnt'];
        $needsActionCount += (int)$row['action_cnt'];
    }
} catch (Throwable $e) {
    // Table may not exist yet
}

// ── Get customers needing action ─────────────────────────────────────────────
$actionQueue = [];
try {
    $sql = "SELECT * FROM service_lifecycle 
            WHERE needs_action = 1 AND deleted_at IS NULL";
    $params = [];
    
    if ($serviceFilter !== 'all') {
        $sql .= " AND LOWER(service_type) = ?";
        $params[] = strtolower($serviceFilter);
    }
    if ($stageFilter !== 'all') {
        $sql .= " AND stage = ?";
        $params[] = $stageFilter;
    }
    if ($searchQuery !== '') {
        $sql .= " AND (customer_name LIKE ? OR customer_phone LIKE ? OR kit_number LIKE ?)";
        $params[] = "%{$searchQuery}%";
        $params[] = "%{$searchQuery}%";
        $params[] = "%{$searchQuery}%";
    }
    
    $sql .= " ORDER BY action_priority DESC, stage_entered_at ASC LIMIT 50";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $actionQueue = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate days in stage
    foreach ($actionQueue as &$row) {
        if (!empty($row['stage_entered_at'])) {
            $entered = new DateTime($row['stage_entered_at']);
            $now = new DateTime();
            $row['days_in_stage'] = $entered->diff($now)->days;
        } else {
            $row['days_in_stage'] = 0;
        }
    }
    unset($row);
} catch (Throwable $e) {
    // Table may not exist yet
}

// ── Get recent activity ──────────────────────────────────────────────────────
$recentActivity = [];
try {
    $sql = "SELECT l.*, m.message_code, m.status as msg_status, m.created_at as msg_at
            FROM service_lifecycle l
            LEFT JOIN lifecycle_message_log m ON m.lifecycle_id = l.id
            WHERE l.deleted_at IS NULL
            ORDER BY COALESCE(m.created_at, l.updated_at) DESC
            LIMIT 20";
    $recentActivity = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Tables may not exist
}

// ── Service totals for filter buttons ────────────────────────────────────────
$serviceTotals = [
    'starlink' => array_sum($stageCounts['starlink'] ?? []),
    'fiber'    => array_sum($stageCounts['fiber'] ?? []),
    'lte'      => array_sum($stageCounts['lte'] ?? []),
    'sim'      => array_sum($stageCounts['sim'] ?? []),
];

// ── Message templates for next action ────────────────────────────────────────
$messageTemplates = [
    'starlink' => [
        'pending_location' => [
            '2A' => 'Day 1: Send Location Reminder',
            '2B' => 'Day 3: Need Help?',
            '2C' => 'Day 7: Escalation',
        ],
        'pending_payment' => [
            '1C_reminder' => 'Payment Reminder',
        ],
    ],
];
?>

<style>
/* ── Lifecycle Dashboard Styles ─────────────────────────────────────────────── */
.lc-header { display:flex; align-items:center; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
.lc-title { font-size:22px; font-weight:800; color:var(--c-text1); display:flex; align-items:center; gap:10px; }
.lc-title i { color:#25D366; }

.lc-filters { display:flex; gap:8px; flex-wrap:wrap; }
.lc-filter-btn {
    padding:8px 16px; border-radius:20px; font-size:12px; font-weight:700;
    background:var(--c-card); border:1px solid var(--c-border); color:var(--c-text2);
    cursor:pointer; text-decoration:none; display:flex; align-items:center; gap:6px;
    transition:all .15s;
}
.lc-filter-btn:hover { background:var(--c-hover); color:var(--c-text1); }
.lc-filter-btn.active { background:#D41C1C; color:#fff; border-color:#D41C1C; }
.lc-filter-btn .badge {
    background:rgba(255,255,255,.2); padding:2px 8px; border-radius:10px; font-size:10px;
}
.lc-filter-btn.active .badge { background:rgba(255,255,255,.3); }

.lc-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:24px; }
.lc-stat {
    background:var(--c-card); border:1px solid var(--c-border); border-radius:12px;
    padding:16px; text-align:center;
}
.lc-stat-value { font-size:28px; font-weight:800; color:var(--c-text1); }
.lc-stat-label { font-size:11px; color:var(--c-text3); text-transform:uppercase; letter-spacing:.5px; margin-top:4px; }
.lc-stat.alert .lc-stat-value { color:#ef4444; }

.lc-pipeline { background:var(--c-card); border:1px solid var(--c-border); border-radius:14px; padding:20px; margin-bottom:24px; }
.lc-pipeline-title { font-size:14px; font-weight:700; color:var(--c-text1); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
.lc-pipeline-stages { display:flex; gap:8px; overflow-x:auto; padding-bottom:8px; }
.lc-stage {
    flex:1; min-width:100px; background:var(--c-bg); border:1px solid var(--c-border);
    border-radius:10px; padding:12px; text-align:center; cursor:pointer; transition:all .15s;
}
.lc-stage:hover { border-color:#D41C1C; }
.lc-stage.active { border-color:#D41C1C; background:rgba(212,28,28,.05); }
.lc-stage-icon { font-size:20px; margin-bottom:4px; }
.lc-stage-count { font-size:22px; font-weight:800; color:var(--c-text1); }
.lc-stage-label { font-size:10px; color:var(--c-text3); margin-top:2px; }
.lc-stage.needs-action { border-color:#ef4444; background:rgba(239,68,68,.05); }
.lc-stage.needs-action .lc-stage-count { color:#ef4444; }

.lc-section { background:var(--c-card); border:1px solid var(--c-border); border-radius:14px; margin-bottom:20px; }
.lc-section-header {
    padding:16px 20px; border-bottom:1px solid var(--c-border);
    display:flex; align-items:center; justify-content:space-between;
}
.lc-section-title { font-size:14px; font-weight:700; color:var(--c-text1); display:flex; align-items:center; gap:8px; }
.lc-section-badge { background:#ef4444; color:#fff; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:700; }

.lc-table { width:100%; border-collapse:collapse; }
.lc-table th {
    text-align:left; padding:12px 16px; font-size:10px; font-weight:700;
    color:var(--c-text3); text-transform:uppercase; letter-spacing:.5px;
    border-bottom:1px solid var(--c-border);
}
.lc-table td { padding:14px 16px; border-bottom:1px solid var(--c-border); font-size:13px; color:var(--c-text1); }
.lc-table tr:last-child td { border-bottom:none; }
.lc-table tr:hover { background:var(--c-hover); }

.lc-customer { display:flex; flex-direction:column; gap:2px; }
.lc-customer-name { font-weight:700; }
.lc-customer-phone { font-size:11px; color:var(--c-text3); }

.lc-service-badge {
    display:inline-flex; align-items:center; gap:4px; padding:4px 10px;
    border-radius:6px; font-size:11px; font-weight:700;
}
.lc-service-badge.starlink { background:#1a1a2e; color:#fff; }
.lc-service-badge.fiber { background:#dc2626; color:#fff; }
.lc-service-badge.lte { background:#7c3aed; color:#fff; }
.lc-service-badge.sim { background:#0ea5e9; color:#fff; }

.lc-stage-badge {
    display:inline-flex; align-items:center; gap:4px; padding:4px 10px;
    border-radius:6px; font-size:11px; font-weight:600;
}

.lc-days { font-size:12px; font-weight:700; }
.lc-days.urgent { color:#ef4444; }
.lc-days.warning { color:#f59e0b; }
.lc-days.ok { color:#22c55e; }

.lc-action-btn {
    padding:6px 14px; border-radius:6px; font-size:11px; font-weight:700;
    border:none; cursor:pointer; transition:all .15s;
}
.lc-action-btn.primary { background:#D41C1C; color:#fff; }
.lc-action-btn.primary:hover { background:#b91c1c; }
.lc-action-btn.secondary { background:var(--c-bg); color:var(--c-text1); border:1px solid var(--c-border); }
.lc-action-btn.secondary:hover { background:var(--c-hover); }

.lc-empty {
    padding:40px; text-align:center; color:var(--c-text3);
}
.lc-empty-icon { font-size:48px; margin-bottom:12px; }
.lc-empty-title { font-size:16px; font-weight:700; color:var(--c-text2); margin-bottom:8px; }
.lc-empty-desc { font-size:13px; }

.lc-search {
    display:flex; gap:8px; margin-left:auto;
}
.lc-search input {
    padding:8px 14px; border-radius:8px; border:1px solid var(--c-border);
    background:var(--c-bg); color:var(--c-text1); font-size:13px; width:200px;
}
.lc-search button {
    padding:8px 16px; border-radius:8px; background:#D41C1C; color:#fff;
    border:none; font-weight:700; font-size:12px; cursor:pointer;
}

/* ── Responsive ─────────────────────────────────────────────────────────────── */
@media (max-width:768px) {
    .lc-header { flex-direction:column; align-items:stretch; }
    .lc-filters { overflow-x:auto; flex-wrap:nowrap; }
    .lc-search { width:100%; }
    .lc-search input { flex:1; }
    .lc-pipeline-stages { flex-direction:column; }
    .lc-stage { min-width:auto; }
    .lc-table { display:block; overflow-x:auto; }
}
</style>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- HEADER                                                                      -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<div class="lc-header">
    <div class="lc-title">
        <i class="bi bi-diagram-3-fill"></i>
        Customer Lifecycle
    </div>
    
    <div class="lc-filters">
        <a href="?page=dashboard&tab=lifecycle&service=all" 
           class="lc-filter-btn <?= $serviceFilter === 'all' ? 'active' : '' ?>">
            All <span class="badge"><?= $totalCount ?></span>
        </a>
        <a href="?page=dashboard&tab=lifecycle&service=starlink" 
           class="lc-filter-btn <?= $serviceFilter === 'starlink' ? 'active' : '' ?>">
            🛰 Starlink <span class="badge"><?= $serviceTotals['starlink'] ?></span>
        </a>
        <a href="?page=dashboard&tab=lifecycle&service=fiber" 
           class="lc-filter-btn <?= $serviceFilter === 'fiber' ? 'active' : '' ?>">
            🔌 Fiber <span class="badge"><?= $serviceTotals['fiber'] ?></span>
        </a>
        <a href="?page=dashboard&tab=lifecycle&service=lte" 
           class="lc-filter-btn <?= $serviceFilter === 'lte' ? 'active' : '' ?>">
            📶 LTE <span class="badge"><?= $serviceTotals['lte'] ?></span>
        </a>
        <a href="?page=dashboard&tab=lifecycle&service=sim" 
           class="lc-filter-btn <?= $serviceFilter === 'sim' ? 'active' : '' ?>">
            📱 SIM <span class="badge"><?= $serviceTotals['sim'] ?></span>
        </a>
    </div>
    
    <form class="lc-search" method="GET">
        <input type="hidden" name="page" value="dashboard">
        <input type="hidden" name="tab" value="lifecycle">
        <input type="hidden" name="service" value="<?= h($serviceFilter) ?>">
        <input type="text" name="q" placeholder="Search name, phone, kit..." value="<?= h($searchQuery) ?>">
        <button type="submit"><i class="bi bi-search"></i></button>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- STATS                                                                       -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<div class="lc-stats">
    <div class="lc-stat">
        <div class="lc-stat-value"><?= $totalCount ?></div>
        <div class="lc-stat-label">Total Customers</div>
    </div>
    <div class="lc-stat alert">
        <div class="lc-stat-value"><?= $needsActionCount ?></div>
        <div class="lc-stat-label">Needs Action</div>
    </div>
    <div class="lc-stat">
        <div class="lc-stat-value"><?= array_sum(array_map(fn($s) => $s['active'] ?? 0, $stageCounts)) ?></div>
        <div class="lc-stat-label">Active Services</div>
    </div>
    <div class="lc-stat">
        <div class="lc-stat-value"><?= array_sum(array_map(fn($s) => ($s['pending_location'] ?? 0) + ($s['pending_payment'] ?? 0), $stageCounts)) ?></div>
        <div class="lc-stat-label">Pending Setup</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- STAGE PIPELINE                                                              -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<?php 
$currentStages = $serviceFilter !== 'all' && isset($stageDefinitions[$serviceFilter]) 
    ? $stageDefinitions[$serviceFilter] 
    : $stageDefinitions['starlink']; // Default to Starlink stages
$currentCounts = $stageCounts[$serviceFilter] ?? ($serviceFilter === 'all' ? [] : []);

// For "all" view, aggregate counts across services
if ($serviceFilter === 'all') {
    $currentCounts = [];
    foreach ($stageCounts as $svc => $stages) {
        foreach ($stages as $stage => $cnt) {
            $currentCounts[$stage] = ($currentCounts[$stage] ?? 0) + $cnt;
        }
    }
}
?>

<div class="lc-pipeline">
    <div class="lc-pipeline-title">
        <?php if ($serviceFilter === 'starlink'): ?>🛰 Starlink Pipeline
        <?php elseif ($serviceFilter === 'fiber'): ?>🔌 Fiber Pipeline
        <?php elseif ($serviceFilter === 'lte'): ?>📶 LTE Pipeline
        <?php elseif ($serviceFilter === 'sim'): ?>📱 SIM Pipeline
        <?php else: ?>📊 All Services Pipeline
        <?php endif; ?>
    </div>
    
    <div class="lc-pipeline-stages">
        <?php foreach ($currentStages as $stageId => $stageDef): 
            $count = $currentCounts[$stageId] ?? 0;
            $needsAction = in_array($stageId, ['pending_location', 'pending_payment', 'pending_survey', 'pending_signal_check', 'overdue']);
        ?>
        <a href="?page=dashboard&tab=lifecycle&service=<?= h($serviceFilter) ?>&stage=<?= h($stageId) ?>" 
           class="lc-stage <?= $stageFilter === $stageId ? 'active' : '' ?> <?= $needsAction && $count > 0 ? 'needs-action' : '' ?>">
            <div class="lc-stage-icon"><?= $stageDef['icon'] ?></div>
            <div class="lc-stage-count"><?= $count ?></div>
            <div class="lc-stage-label"><?= h($stageDef['label']) ?></div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- ACTION QUEUE                                                                -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<div class="lc-section">
    <div class="lc-section-header">
        <div class="lc-section-title">
            ⚠️ Needs Action
            <?php if (count($actionQueue) > 0): ?>
            <span class="lc-section-badge"><?= count($actionQueue) ?></span>
            <?php endif; ?>
        </div>
        <?php if (count($actionQueue) > 0): ?>
        <button class="lc-action-btn secondary" onclick="alert('Coming soon: Send all reminders')">
            <i class="bi bi-send"></i> Send All Reminders
        </button>
        <?php endif; ?>
    </div>
    
    <?php if (empty($actionQueue)): ?>
    <div class="lc-empty">
        <div class="lc-empty-icon">✅</div>
        <div class="lc-empty-title">All caught up!</div>
        <div class="lc-empty-desc">No customers need action right now.</div>
    </div>
    <?php else: ?>
    <table class="lc-table">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Service</th>
                <th>Kit / Ref</th>
                <th>Stage</th>
                <th>Days</th>
                <th>Next Message</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($actionQueue as $row): 
                $svcType = strtolower($row['service_type'] ?? 'starlink');
                $stage = $row['stage'] ?? 'registered';
                $stageDef = $stageDefinitions[$svcType][$stage] ?? ['label' => ucfirst($stage), 'icon' => '📋', 'color' => '#6b7280'];
                $days = $row['days_in_stage'] ?? 0;
                $daysClass = $days >= 7 ? 'urgent' : ($days >= 3 ? 'warning' : 'ok');
                
                // Determine next message based on stage and days
                $nextMsg = '-';
                if ($svcType === 'starlink' && $stage === 'pending_location') {
                    if ($days >= 7) $nextMsg = '2C: Escalation';
                    elseif ($days >= 3) $nextMsg = '2B: Check-in';
                    else $nextMsg = '2A: Location Reminder';
                } elseif ($stage === 'pending_payment') {
                    $nextMsg = 'Payment Reminder';
                }
            ?>
            <tr>
                <td>
                    <div class="lc-customer">
                        <span class="lc-customer-name"><?= h($row['customer_name'] ?? 'Unknown') ?></span>
                        <span class="lc-customer-phone"><?= h($row['customer_phone'] ?? '-') ?></span>
                    </div>
                </td>
                <td>
                    <span class="lc-service-badge <?= $svcType ?>">
                        <?php 
                        $svcIcons = ['starlink' => '🛰', 'fiber' => '🔌', 'lte' => '📶', 'sim' => '📱'];
                        echo $svcIcons[$svcType] ?? '📋';
                        ?> <?= ucfirst($svcType) ?>
                    </span>
                </td>
                <td style="font-family:monospace;font-size:12px;">
                    <?= h($row['kit_number'] ?: ($row['application_id'] ? "App #{$row['application_id']}" : '-')) ?>
                </td>
                <td>
                    <span class="lc-stage-badge" style="background:<?= $stageDef['color'] ?>20;color:<?= $stageDef['color'] ?>;">
                        <?= $stageDef['icon'] ?> <?= h($stageDef['label']) ?>
                    </span>
                </td>
                <td>
                    <span class="lc-days <?= $daysClass ?>"><?= $days ?> days</span>
                </td>
                <td style="font-size:12px;color:var(--c-text2);">
                    <?= h($nextMsg) ?>
                </td>
                <td>
                    <button class="lc-action-btn primary" 
                            onclick="alert('Coming soon: Send message to <?= h($row['customer_name']) ?>')">
                        <i class="bi bi-send"></i> Send
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- GETTING STARTED (shown when empty)                                          -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<?php if ($totalCount === 0): ?>
<div class="lc-section" style="background:linear-gradient(135deg,#1a1a2e,#16213e);border:none;">
    <div style="padding:40px;text-align:center;color:#fff;">
        <div style="font-size:48px;margin-bottom:16px;">🚀</div>
        <h2 style="font-size:24px;font-weight:800;margin-bottom:12px;">Welcome to Customer Lifecycle</h2>
        <p style="font-size:14px;opacity:.8;max-width:500px;margin:0 auto 24px;">
            Track customers from registration through active service. 
            Send automated reminders and never lose a sale.
        </p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
            <div style="background:rgba(255,255,255,.1);border-radius:12px;padding:16px 24px;text-align:left;">
                <div style="font-size:20px;margin-bottom:8px;">1️⃣</div>
                <div style="font-weight:700;margin-bottom:4px;">Register Customers</div>
                <div style="font-size:12px;opacity:.7;">Use Add Customer in Sales</div>
            </div>
            <div style="background:rgba(255,255,255,.1);border-radius:12px;padding:16px 24px;text-align:left;">
                <div style="font-size:20px;margin-bottom:8px;">2️⃣</div>
                <div style="font-weight:700;margin-bottom:4px;">Auto-Track Progress</div>
                <div style="font-size:12px;opacity:.7;">System monitors each stage</div>
            </div>
            <div style="background:rgba(255,255,255,.1);border-radius:12px;padding:16px 24px;text-align:left;">
                <div style="font-size:20px;margin-bottom:8px;">3️⃣</div>
                <div style="font-weight:700;margin-bottom:4px;">Send Reminders</div>
                <div style="font-size:12px;opacity:.7;">One-click WhatsApp messages</div>
            </div>
        </div>
        <p style="font-size:12px;opacity:.6;margin-top:24px;">
            💡 Existing KYC applications will be imported automatically via cron.
        </p>
    </div>
</div>
<?php endif; ?>

<script>
// ── Quick actions ────────────────────────────────────────────────────────────
function lcSendMessage(lifecycleId, messageCode) {
    if (!confirm('Send this message now?')) return;
    
    fetch('?page=api&action=lifecycle_send_message', {
          credentials:'same-origin',
          method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({lifecycle_id: lifecycleId, message_code: messageCode})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Message sent successfully!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Failed: ' + (data.error || 'Unknown error'), 'error');
        }
    })
    .catch(err => {
        showToast('Error: ' + err.message, 'error');
    });
}

// ── Toast helper ─────────────────────────────────────────────────────────────
function showToast(msg, type) {
    const toast = document.createElement('div');
    toast.className = 'dn-toast ' + type;
    toast.innerHTML = (type === 'success' ? '✅' : '❌') + ' ' + msg;
    document.getElementById('toastContainer')?.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}
</script>
