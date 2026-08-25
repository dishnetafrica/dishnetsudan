<?php
// ── Sales Dashboard — Staff Home Screen v4.11.3 ─────────────────────────
// For: sales, sales_staff, field_agent, collection roles
// Shows: today's performance, targets, wallet, quick actions

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

$rid       = (int)$retailer['id'];
$agentName = $retailer['name'] ?? 'Agent';
$today     = date('Y-m-d');
$month     = date('Y-m');

// ── Load data ──
$allCols   = $store->load('payment_collections.json') ?? [];
$allApps   = $store->load('kyc_applications.json') ?? [];
$allHovs   = $store->load('cash_handovers.json') ?? [];
$walletBal = round($wallet->getBalance($rid), 2);

// ── My collections ──
$myCols = array_filter($allCols, fn($c) => (int)($c['retailer_id'] ?? 0) === $rid);
$myTodayCols  = array_filter($myCols, fn($c) => substr($c['created_at'] ?? '', 0, 10) === $today);
$myMonthCols  = array_filter($myCols, fn($c) => substr($c['created_at'] ?? '', 0, 7) === $month);
$todayAmount  = round(array_sum(array_column(array_values($myTodayCols), 'amount')), 2);
$monthAmount  = round(array_sum(array_column(array_values($myMonthCols), 'amount')), 2);
$todayCount   = count($myTodayCols);
$monthCount   = count($myMonthCols);

// ── My KYC registrations ──
$myApps = array_filter($allApps, fn($a) => ($a['retailer_name'] ?? '') === $agentName || (int)($a['retailer_id'] ?? 0) === $rid);
$myTodayApps = array_filter($myApps, fn($a) => substr($a['created_at'] ?? $a['submitted_at'] ?? '', 0, 10) === $today);
$myMonthApps = array_filter($myApps, fn($a) => substr($a['created_at'] ?? $a['submitted_at'] ?? '', 0, 7) === $month);

// ── Cash in hand ──
$myUsdCols = round(array_sum(array_column(array_values(array_filter($myCols, fn($c) => ($c['status'] ?? '') !== 'voided')), 'amount')), 2);
$myUsdHovs = round(array_sum(array_column(array_values(array_filter($allHovs, fn($h) => (int)($h['from_id'] ?? 0) === $rid && ($h['status'] ?? '') === 'confirmed' && strtoupper($h['currency'] ?? 'USD') === 'USD')), 'amount')), 2);
$cashInHand = round($myUsdCols - $myUsdHovs, 2);

// ── Pending handovers ──
$myPendHovs = array_filter($allHovs, fn($h) => (int)($h['from_id'] ?? 0) === $rid && ($h['status'] ?? '') === 'pending');
$pendHovAmt = round(array_sum(array_column(array_values($myPendHovs), 'amount')), 2);

// ── Pending CRM syncs ──
$pendSync = count(array_filter($myCols, fn($c) => empty($c['crm_synced']) && !empty($c['crm_customer_id'])));

// ── Recent collections (last 5) ──
$recentCols = array_slice(array_reverse(array_values($myCols)), 0, 5);

// ── Greeting ──
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

// ── Monthly target (configurable, default $5000) ──
$monthTarget = (float)($config['sales_monthly_target'] ?? 5000);
$targetPct   = $monthTarget > 0 ? min(100, round(($monthAmount / $monthTarget) * 100)) : 0;
?>

<style>
.sd-hero{background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:20px;padding:20px 18px;color:#fff;position:relative;overflow:hidden;margin-bottom:14px;}
.sd-hero::before{content:'';position:absolute;top:-50px;right:-50px;width:150px;height:150px;border-radius:50%;background:rgba(212,28,28,.15);}
.sd-greet{font-size:12px;color:rgba(255,255,255,.5);font-weight:600;}
.sd-name{font-size:20px;font-weight:800;margin:2px 0 12px;}
.sd-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.sd-stat{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:12px;}
.sd-stat-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:rgba(255,255,255,.4);}
.sd-stat-val{font-size:24px;font-weight:900;line-height:1.1;margin-top:2px;}
.sd-stat-sub{font-size:10px;color:rgba(255,255,255,.35);margin-top:1px;}
.sd-target{margin-top:12px;background:rgba(255,255,255,.06);border-radius:10px;padding:10px 12px;}
.sd-target-bar{height:6px;background:rgba(255,255,255,.1);border-radius:3px;overflow:hidden;margin-top:6px;}
.sd-target-fill{height:100%;border-radius:3px;transition:width .5s;}
.sd-quick{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;}
.sd-quick a{display:flex;flex-direction:column;align-items:center;gap:6px;padding:16px 10px;background:#fff;border-radius:14px;text-decoration:none;color:#1e293b;font-size:12px;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1px solid #f1f5f9;-webkit-tap-highlight-color:transparent;}
.sd-quick a:active{background:#f8fafc;}
.sd-quick a i{font-size:24px;}
.sd-card{background:#fff;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1px solid #f1f5f9;margin-bottom:12px;overflow:hidden;}
.sd-card-hd{padding:12px 16px;font-size:13px;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:8px;border-bottom:1px solid #f1f5f9;}
.sd-card-body{padding:12px 16px;}
.sd-alert{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;margin-bottom:8px;text-decoration:none;-webkit-tap-highlight-color:transparent;}
.sd-col-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f8fafc;}
.sd-col-row:last-child{border-bottom:none;}
</style>

<?php
// ── Next call queue — load assigned uncalled leads for this agent ──────────
$_allLeadsSD  = $store->load('leads.json') ?? [];
$_sdMaxAttempts = (int)($config['lead_max_attempts'] ?? 3);
$_sdDeadlineMin = (int)($config['lead_call_deadline_minutes'] ?? 60);

// Active, non-archived, assigned to me today, not yet called today
$_myNextLeads = [];
foreach ($_allLeadsSD as $_sl) {
    if (!empty($_sl['archived'])) continue;
    if (in_array($_sl['status'] ?? '', ['won','lost'], true)) continue;
    if ((int)($_sl['assigned_to'] ?? 0) !== $rid) continue;
    if (($_sl['daily_assign_date'] ?? '') !== $today) continue;
    $calledToday = !empty($_sl['last_call_at']) && substr($_sl['last_call_at'], 0, 10) === $today;
    if ($calledToday) continue;
    $_myNextLeads[] = $_sl;
}
// Sort: overdue first, then by priority, then arrival time
usort($_myNextLeads, function($a, $b) {
    $pa = ['high'=>0,'medium'=>1,'low'=>2][$a['priority'] ?? 'medium'] ?? 1;
    $pb = ['high'=>0,'medium'=>1,'low'=>2][$b['priority'] ?? 'medium'] ?? 1;
    if ($pa !== $pb) return $pa - $pb;
    return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
});
$_nextLead = !empty($_myNextLeads) ? $_myNextLeads[0] : null;
$_queueCount = count($_myNextLeads);
?>

<!-- ── NEXT CALL CARD ── -->
<?php if ($_nextLead): ?>
<?php
    $_nl       = $_nextLead;
    $_nlPhone  = preg_replace('/[^0-9+]/', '', $_nl['phone'] ?? '');
    $_nlName   = $_nl['customer_name'] ?? '';
    $_nlArea   = $_nl['address'] ?? '';
    $_nlAttempt = (int)($_nl['total_calls'] ?? 0) + 1;
    $_nlRefTs  = !empty($_nl['assigned_at']) ? strtotime($_nl['assigned_at']) : strtotime($_nl['created_at'] ?? 'now');
    $_nlMinsAgo = (int)round((time() - $_nlRefTs) / 60);
    $_nlOverdue = $_nlMinsAgo >= $_sdDeadlineMin;
    $_nlWarning = $_nlMinsAgo >= ($_sdDeadlineMin - 15) && !$_nlOverdue;
    $_nlWaNum  = ltrim($_nlPhone, '+');
?>
<div style="background:<?= $_nlOverdue ? 'linear-gradient(135deg,#dc2626,#991b1b)' : 'linear-gradient(135deg,#16a34a,#15803d)' ?>;border-radius:16px;padding:16px 18px;margin-bottom:14px;box-shadow:0 4px 20px rgba(0,0,0,.2);">
  <!-- Top row: name + call button -->
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
    <div style="width:48px;height:48px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;">📞</div>
    <div style="flex:1;min-width:0;">
      <div style="font-size:16px;font-weight:900;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($_nlName) ?></div>
      <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:1px;">
        <?= htmlspecialchars($_nl['phone'] ?? '') ?>
        <?php if ($_nlArea): ?> · <?= htmlspecialchars(substr($_nlArea, 0, 25)) ?><?php endif; ?>
      </div>
      <div style="font-size:10px;color:rgba(255,255,255,.55);margin-top:2px;">
        Attempt <?= $_nlAttempt ?> of <?= $_sdMaxAttempts ?>
        <?php if ($_nlOverdue): ?>
          · <span style="color:#fca5a5;font-weight:700;">⚠️ <?= $_nlMinsAgo ?>m overdue</span>
        <?php elseif ($_nlWarning): ?>
          · <span style="color:#fef08a;">⏱️ <?= $_sdDeadlineMin - $_nlMinsAgo ?>m left</span>
        <?php else: ?>
          · <span style="color:rgba(255,255,255,.5);">⏱️ <?= $_nlMinsAgo ?>m ago</span>
        <?php endif; ?>
      </div>
    </div>
    <a href="tel:<?= htmlspecialchars($_nlPhone) ?>"
       style="background:#fff;color:<?= $_nlOverdue ? '#dc2626' : '#16a34a' ?>;font-weight:900;font-size:15px;padding:12px 20px;border-radius:12px;text-decoration:none;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.2);flex-shrink:0;">
      📲 Call Now
    </a>
  </div>

  <!-- Queue count + WA link -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
    <span style="font-size:11px;color:rgba(255,255,255,.6);">
      <?= $_queueCount > 1 ? "📋 {$_queueCount} leads in your queue" : "📋 Last lead for today" ?>
    </span>
    <a href="https://wa.me/<?= $_nlWaNum ?>" target="_blank"
       style="font-size:11px;color:rgba(255,255,255,.7);text-decoration:none;background:rgba(255,255,255,.15);padding:4px 10px;border-radius:8px;">
      <i class="bi bi-whatsapp"></i> Message
    </a>
  </div>

  <!-- Outcome buttons — inline, no modal needed -->
  <div style="font-size:10px;color:rgba(255,255,255,.6);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">After the call:</div>
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:6px;" id="sdOutcomeBar">
    <button onclick="sdLogOutcome('no_answer')"
            style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:10px;padding:8px 4px;font-size:10px;font-weight:700;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:3px;">
      <span style="font-size:16px;">📵</span>No Answer
    </button>
    <button onclick="sdLogOutcome('interested')"
            style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:10px;padding:8px 4px;font-size:10px;font-weight:700;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:3px;">
      <span style="font-size:16px;">🔥</span>Interested
    </button>
    <button onclick="sdLogOutcome('callback')"
            style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:10px;padding:8px 4px;font-size:10px;font-weight:700;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:3px;">
      <span style="font-size:16px;">📅</span>Call Later
    </button>
    <button onclick="sdLogOutcome('not_interested')"
            style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:10px;padding:8px 4px;font-size:10px;font-weight:700;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:3px;">
      <span style="font-size:16px;">❌</span>Not Int.
    </button>
  </div>
  <div id="sdOutcomeMsg" style="display:none;text-align:center;padding:8px;font-size:12px;font-weight:700;color:#fff;"></div>
</div>

<script>
(function(){
var _sdLeadId  = <?= (int)($_nextLead['id'] ?? 0) ?>;
var _sdToken   = '<?= htmlspecialchars($retailer['api_token'] ?? '') ?>';

window.sdLogOutcome = function(outcome) {
    var bar = document.getElementById('sdOutcomeBar');
    var msg = document.getElementById('sdOutcomeMsg');
    if (bar) { bar.style.opacity = '.5'; bar.style.pointerEvents = 'none'; }
    if (msg) { msg.style.display = 'block'; msg.textContent = 'Saving…'; }

    fetch('?page=api&action=log_call', {
        credentials: 'same-origin',
        method: 'POST',
        headers: {'Content-Type':'application/json','Authorization':'Bearer '+_sdToken},
        body: JSON.stringify({
            lead_id: _sdLeadId,
            outcome: outcome,
            note: '',
            new_status: '',
            follow_up_date: '',
            duration_seconds: 0
        })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.status === 'success' || d.success) {
            var labels = {
                no_answer: '📵 No answer logged — WA sent',
                interested: '🔥 Marked interested — specialist notified',
                callback: '📅 Callback scheduled — WA sent',
                not_interested: '❌ Marked not interested — WA sent'
            };
            if (msg) msg.textContent = labels[outcome] || '✅ Saved';
            // Reload after 1.5s to show next lead
            setTimeout(function(){ location.reload(); }, 1500);
        } else {
            if (msg) msg.textContent = '❌ Error — try again';
            if (bar) { bar.style.opacity = '1'; bar.style.pointerEvents = ''; }
        }
    })
    .catch(function(){
        if (msg) msg.textContent = '❌ Network error';
        if (bar) { bar.style.opacity = '1'; bar.style.pointerEvents = ''; }
    });
};
})();
</script>

<?php else: ?>
<!-- No leads — clean state -->
<div style="background:linear-gradient(135deg,#16a34a,#15803d);border-radius:16px;padding:18px;margin-bottom:14px;text-align:center;">
  <div style="font-size:28px;margin-bottom:6px;">✅</div>
  <div style="font-size:15px;font-weight:800;color:#fff;">All caught up!</div>
  <div style="font-size:12px;color:rgba(255,255,255,.7);margin-top:4px;">No leads assigned for today. Check back later.</div>
  <a href="?page=dashboard&tab=leads" style="display:inline-block;margin-top:10px;background:rgba(255,255,255,.2);color:#fff;text-decoration:none;padding:8px 18px;border-radius:10px;font-size:12px;font-weight:700;">View All Leads →</a>
</div>
<?php endif; ?>

<!-- ── HERO ── -->
<div class="sd-hero">
  <div class="sd-greet"><?= $greeting ?>, <?= date('d M') ?></div>
  <div class="sd-name"><?= htmlspecialchars($agentName) ?></div>

  <div class="sd-stats">
    <div class="sd-stat">
      <div class="sd-stat-lbl">Today Collected</div>
      <div class="sd-stat-val" style="color:#4ade80;">$<?= number_format($todayAmount, 0) ?></div>
      <div class="sd-stat-sub"><?= $todayCount ?> payment<?= $todayCount !== 1 ? 's' : '' ?></div>
    </div>
    <div class="sd-stat">
      <div class="sd-stat-lbl">Cash in Hand</div>
      <div class="sd-stat-val" style="color:<?= $cashInHand > 0 ? '#f87171' : '#4ade80' ?>;">$<?= number_format(max(0, $cashInHand), 0) ?></div>
      <div class="sd-stat-sub"><?= $cashInHand > 0 ? 'Needs handover' : 'All clear' ?></div>
    </div>
    <div class="sd-stat">
      <div class="sd-stat-lbl">This Month</div>
      <div class="sd-stat-val" style="color:#60a5fa;">$<?= number_format($monthAmount, 0) ?></div>
      <div class="sd-stat-sub"><?= $monthCount ?> payments</div>
    </div>
    <div class="sd-stat">
      <div class="sd-stat-lbl">Wallet Float</div>
      <div class="sd-stat-val" style="color:<?= $walletBal > 100 ? '#fbbf24' : '#f87171' ?>;">$<?= number_format($walletBal, 0) ?></div>
      <div class="sd-stat-sub"><?= $walletBal < 100 ? 'Low — recharge!' : 'Ready' ?></div>
    </div>
  </div>

  <!-- Monthly target -->
  <?php if ($monthTarget > 0): ?>
  <div class="sd-target">
    <div style="display:flex;justify-content:space-between;font-size:11px;">
      <span style="color:rgba(255,255,255,.5);font-weight:700;">Monthly Target</span>
      <span style="color:#fff;font-weight:800;">$<?= number_format($monthAmount, 0) ?> / $<?= number_format($monthTarget, 0) ?></span>
    </div>
    <div class="sd-target-bar">
      <div class="sd-target-fill" style="width:<?= $targetPct ?>%;background:<?= $targetPct >= 100 ? '#4ade80' : ($targetPct >= 50 ? '#fbbf24' : '#f87171') ?>;"></div>
    </div>
    <div style="text-align:right;font-size:10px;color:rgba(255,255,255,.35);margin-top:3px;">
      <?= $targetPct ?>% — <?= $targetPct >= 100 ? 'Target reached!' : ($monthTarget - $monthAmount > 0 ? '$'.number_format($monthTarget - $monthAmount, 0).' to go' : '') ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- KYC count -->
  <div style="display:flex;gap:12px;margin-top:10px;font-size:11px;color:rgba(255,255,255,.4);">
    <span>📋 <?= count($myTodayApps) ?> KYC today</span>
    <span>📋 <?= count($myMonthApps) ?> KYC this month</span>
  </div>
</div>

<!-- ── ALERTS ── -->
<?php if ($cashInHand > 50): ?>
<a href="?page=dashboard&tab=wallet" class="sd-alert" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;">
  <span style="font-size:20px;">💵</span>
  <div style="flex:1;font-size:13px;font-weight:700;">$<?= number_format($cashInHand, 2) ?> cash in hand — hand over to Diko or Rupesh</div>
  <i class="bi bi-chevron-right" style="color:#fca5a5;"></i>
</a>
<?php endif; ?>

<?php if ($walletBal < 100): ?>
<a href="?page=dashboard&tab=wallet_recharge" class="sd-alert" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">
  <span style="font-size:20px;">⚠️</span>
  <div style="flex:1;font-size:13px;font-weight:700;">Wallet low ($<?= number_format($walletBal, 2) ?>) — recharge to continue collecting</div>
  <i class="bi bi-chevron-right" style="color:#fcd34d;"></i>
</a>
<?php endif; ?>

<?php if (count($myPendHovs) > 0): ?>
<a href="?page=dashboard&tab=wallet" class="sd-alert" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;">
  <span style="font-size:20px;">⏳</span>
  <div style="flex:1;font-size:13px;font-weight:700;"><?= count($myPendHovs) ?> handover<?= count($myPendHovs) > 1 ? 's' : '' ?> pending ($<?= number_format($pendHovAmt, 2) ?>)</div>
  <i class="bi bi-chevron-right" style="color:#93c5fd;"></i>
</a>
<?php endif; ?>

<?php if ($pendSync > 0): ?>
<div class="sd-alert" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">
  <span style="font-size:20px;">🔄</span>
  <div style="flex:1;font-size:13px;font-weight:700;"><?= $pendSync ?> payment<?= $pendSync > 1 ? 's' : '' ?> syncing to CRM...</div>
</div>
<?php endif; ?>

<!-- ── QUICK ACTIONS ── -->
<div class="sd-quick">
  <a href="?page=dashboard&tab=collect_payment">
    <i class="bi bi-cash-coin" style="color:#28a745;"></i>
    <span>Collect Payment</span>
  </a>
  <a href="?page=dashboard&tab=form">
    <i class="bi bi-plus-circle" style="color:#D41C1C;"></i>
    <span>New KYC</span>
  </a>
  <a href="?page=dashboard&tab=leads">
    <i class="bi bi-people-fill" style="color:#f39c12;"></i>
    <span>My Leads</span>
    <?php $leadCount = count(array_filter($store->load('leads.json') ?? [], fn($l) => ($l['agent_id'] ?? 0) == $rid && !in_array($l['status'] ?? '', ['won','lost']))); ?>
    <?php if ($leadCount > 0): ?><span style="background:#f39c12;color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700;"><?= $leadCount ?></span><?php endif; ?>
  </a>
  <a href="?page=dashboard&tab=wallet">
    <i class="bi bi-wallet2" style="color:#7C3AED;"></i>
    <span>My Wallet</span>
  </a>
</div>

<!-- ── KYC LIFECYCLE FUNNEL + FIBER STATUS — admin/sales managers only ── -->
<?php if (!in_array($userRole ?? '', ['sales_staff', 'collection', 'employee'], true)): ?>
<?php include __DIR__ . '/../../includes/widgets/kyc_funnel.php'; ?>

<!-- ── FIBER SERVICE STATUS ── -->
<?php include __DIR__ . '/../../includes/widgets/fiber_status.php'; ?>
<?php endif; ?>

<!-- ── RECENT COLLECTIONS ── -->
<?php if (!empty($recentCols)): ?>
<div class="sd-card">
  <div class="sd-card-hd">
    <i class="bi bi-clock-history" style="color:#D41C1C;"></i> Recent Collections
    <a href="?page=dashboard&tab=wallet" style="margin-left:auto;font-size:11px;color:#D41C1C;text-decoration:none;font-weight:700;">View all →</a>
  </div>
  <div class="sd-card-body" style="padding:8px 16px;">
    <?php foreach ($recentCols as $rc):
      $rcDate = substr($rc['created_at'] ?? '', 0, 10);
      $rcIsToday = $rcDate === $today;
      $rcSynced = !empty($rc['crm_synced']);
    ?>
    <div class="sd-col-row">
      <div style="width:36px;height:36px;border-radius:50%;background:<?= $rcSynced ? '#dcfce7' : '#fef3c7' ?>;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;">
        <?= $rcSynced ? '✅' : '⏳' ?>
      </div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:700;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($rc['customer_name'] ?? 'Customer') ?></div>
        <div style="font-size:11px;color:#94a3b8;"><?= $rcIsToday ? 'Today' : date('d M', strtotime($rcDate)) ?> · <?= $rc['method'] ?? 'Cash' ?></div>
      </div>
      <div style="font-size:16px;font-weight:900;color:#059669;flex-shrink:0;">$<?= number_format((float)($rc['amount'] ?? 0), 2) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── MY KYC ACTIVATION TRACKER ── -->
<?php
// Use client_search_index (always fresh) + services cache as fallback
$_searchIdx = $store->load('client_search_index.json') ?? [];
$_svcCache = $store->load('ucrm_services_cache.json') ?? [];

// Build set of activated client IDs
// Method 1: client has status=1 (active customer in CRM)
$_activeClientIds = [];
foreach ($_searchIdx as $_si) {
    $cid = (int)($_si['id'] ?? 0);
    $st  = $_si['status'] ?? '';
    // CRM client status: 1=active (has services), not a lead
    if ($cid > 0 && ($st == 1 || $st === 'active') && empty($_si['isLead'])) {
        $_activeClientIds[$cid] = true;
    }
}
// Method 2: supplement with services cache (client has active/suspended service)
foreach ($_svcCache as $_svc) {
    $st = (int)($_svc['status'] ?? 0);
    if ($st === 1 || $st === 2) {
        $cid = (int)($_svc['clientId'] ?? $_svc['_clientId'] ?? 0);
        if ($cid > 0) $_activeClientIds[$cid] = true;
    }
}

$_mySubmitted = count($myMonthApps);
$_mySynced    = 0;
$_myActivated = 0;
$_myNeedFollow = []; // Customers who need a call

foreach ($myMonthApps as $_ma) {
    $crmId = (int)($_ma['crm_client_id'] ?? 0);
    $syncStatus = $_ma['crm_sync_status'] ?? $_ma['status'] ?? '';

    if ($crmId > 0) {
        $_mySynced++;
        if (isset($_activeClientIds[$crmId])) {
            $_myActivated++;
        } else {
            // Synced to CRM but NOT activated — needs follow-up
            $_myNeedFollow[] = [
                'name'   => trim(($_ma['firstname'] ?? '') . ' ' . ($_ma['lastname'] ?? '')) ?: 'Customer',
                'phone'  => $_ma['mobile'] ?? $_ma['phone'] ?? '',
                'date'   => substr($_ma['created_at'] ?? $_ma['submitted_at'] ?? '', 0, 10),
                'crm_id' => $crmId,
                'type'   => $_ma['customer_type'] ?? 'Starlink',
                'status' => 'Not activated',
                'app_id' => $_ma['id'] ?? 0,
            ];
        }
    } elseif ($syncStatus === 'failed') {
        $_myNeedFollow[] = [
            'name'   => trim(($_ma['firstname'] ?? '') . ' ' . ($_ma['lastname'] ?? '')) ?: 'Customer',
            'phone'  => $_ma['mobile'] ?? $_ma['phone'] ?? '',
            'date'   => substr($_ma['created_at'] ?? $_ma['submitted_at'] ?? '', 0, 10),
            'crm_id' => 0,
            'type'   => $_ma['customer_type'] ?? 'Starlink',
            'status' => 'CRM sync failed',
            'app_id' => $_ma['id'] ?? 0,
        ];
    } elseif ($syncStatus === 'pending' || empty($crmId)) {
        // Pending sync — not urgent but track it
        $_myNeedFollow[] = [
            'name'   => trim(($_ma['firstname'] ?? '') . ' ' . ($_ma['lastname'] ?? '')) ?: 'Customer',
            'phone'  => $_ma['mobile'] ?? $_ma['phone'] ?? '',
            'date'   => substr($_ma['created_at'] ?? $_ma['submitted_at'] ?? '', 0, 10),
            'crm_id' => 0,
            'type'   => $_ma['customer_type'] ?? 'Starlink',
            'status' => 'Pending sync',
            'app_id' => $_ma['id'] ?? 0,
        ];
    }
}
$_myRate = $_mySynced > 0 ? round(($_myActivated / $_mySynced) * 100) : 0;
?>

<?php if ($_mySubmitted > 0): ?>
<div class="sd-card">
  <div class="sd-card-hd">
    <i class="bi bi-funnel-fill" style="color:#0891b2;"></i> My Activations
    <span style="margin-left:auto;font-size:11px;color:#94a3b8;"><?= date('M Y') ?></span>
  </div>
  <div class="sd-card-body">
    <!-- Funnel -->
    <div style="display:flex;gap:4px;margin-bottom:10px;">
      <div style="flex:1;text-align:center;padding:10px 4px;background:#eff6ff;border-radius:10px;">
        <div style="font-size:22px;font-weight:900;color:#1d4ed8;"><?= $_mySubmitted ?></div>
        <div style="font-size:9px;font-weight:700;color:#6b7280;">Submitted</div>
      </div>
      <div style="display:flex;align-items:center;color:#d1d5db;">→</div>
      <div style="flex:1;text-align:center;padding:10px 4px;background:#fef3c7;border-radius:10px;">
        <div style="font-size:22px;font-weight:900;color:#d97706;"><?= $_mySynced ?></div>
        <div style="font-size:9px;font-weight:700;color:#6b7280;">In CRM</div>
      </div>
      <div style="display:flex;align-items:center;color:#d1d5db;">→</div>
      <div style="flex:1;text-align:center;padding:10px 4px;background:#f0fdf4;border-radius:10px;">
        <div style="font-size:22px;font-weight:900;color:#059669;"><?= $_myActivated ?></div>
        <div style="font-size:9px;font-weight:700;color:#6b7280;">Active</div>
      </div>
    </div>

    <!-- Rate -->
    <div style="display:flex;justify-content:space-between;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;">
      <span>Activation Rate</span>
      <span style="color:<?= $_myRate >= 70 ? '#059669' : '#d97706' ?>;"><?= $_myRate ?>%</span>
    </div>
    <div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;margin-bottom:6px;">
      <div style="height:100%;width:<?= $_myRate ?>%;background:<?= $_myRate >= 70 ? '#059669' : '#d97706' ?>;border-radius:3px;"></div>
    </div>
    <div style="font-size:10px;color:#94a3b8;"><?= $_myActivated ?> of <?= $_mySynced ?> customers have active internet</div>
  </div>
</div>
<?php endif; ?>

<!-- ── NEEDS FOLLOW-UP ── -->
<?php if (!empty($_myNeedFollow)): ?>
<div class="sd-card">
  <div class="sd-card-hd" style="background:#fef2f2;border-bottom-color:#fecaca;">
    <span style="font-size:16px;">📞</span> Needs Follow-up
    <span style="margin-left:auto;background:#dc2626;color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:800;"><?= count($_myNeedFollow) ?></span>
  </div>
  <div class="sd-card-body" style="padding:8px 16px;">
    <div style="font-size:11px;color:#6b7280;margin-bottom:8px;">These customers registered but are not active yet. Call them!</div>
    <?php foreach ($_myNeedFollow as $_nf):
      $phone = preg_replace('/[^0-9+]/', '', $_nf['phone']);
      $statusColor = $_nf['status'] === 'Not activated' ? '#d97706' : ($_nf['status'] === 'CRM sync failed' ? '#dc2626' : '#6b7280');
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f8fafc;">
      <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:700;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
          <?= htmlspecialchars($_nf['name']) ?>
        </div>
        <div style="font-size:10px;color:#94a3b8;">
          <?= htmlspecialchars($_nf['type']) ?> · <?= $_nf['date'] ?>
          <span style="color:<?= $statusColor ?>;font-weight:700;"> · <?= $_nf['status'] ?></span>
        </div>
      </div>
      <?php if ($phone): ?>
      <a href="tel:<?= htmlspecialchars($phone) ?>" style="display:flex;align-items:center;justify-content:center;width:38px;height:38px;background:#dcfce7;border-radius:50%;text-decoration:none;flex-shrink:0;">
        <i class="bi bi-telephone-fill" style="color:#059669;font-size:16px;"></i>
      </a>
      <?php endif; ?>
      <?php if ($phone): ?>
      <a href="https://wa.me/<?= preg_replace('/^\+?/', '', $phone) ?>" target="_blank" style="display:flex;align-items:center;justify-content:center;width:38px;height:38px;background:#f0fdf4;border-radius:50%;text-decoration:none;flex-shrink:0;">
        <i class="bi bi-whatsapp" style="color:#25D366;font-size:16px;"></i>
      </a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── MORE LINKS ── -->
<div class="sd-card">
  <div class="sd-card-hd"><i class="bi bi-grid-3x3-gap" style="color:#64748b;"></i> More</div>
  <div class="sd-card-body" style="padding:8px 16px;">
    <a href="?page=dashboard&tab=applications" style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f8fafc;text-decoration:none;color:#1e293b;">
      <i class="bi bi-folder2-open" style="font-size:18px;color:#D41C1C;width:24px;text-align:center;"></i>
      <span style="flex:1;font-size:13px;font-weight:600;">My Orders</span>
      <span style="font-size:12px;color:#94a3b8;"><?= count($myApps) ?></span>
      <i class="bi bi-chevron-right" style="color:#d1d5db;"></i>
    </a>
    <a href="?page=dashboard&tab=customer_lookup" style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f8fafc;text-decoration:none;color:#1e293b;">
      <i class="bi bi-search" style="font-size:18px;color:#0891b2;width:24px;text-align:center;"></i>
      <span style="flex:1;font-size:13px;font-weight:600;">Customer 360°</span>
      <i class="bi bi-chevron-right" style="color:#d1d5db;"></i>
    </a>
    <a href="?page=dashboard&tab=scheduling" style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f8fafc;text-decoration:none;color:#1e293b;">
      <i class="bi bi-calendar-check" style="font-size:18px;color:#1565C0;width:24px;text-align:center;"></i>
      <span style="flex:1;font-size:13px;font-weight:600;">My Jobs</span>
      <i class="bi bi-chevron-right" style="color:#d1d5db;"></i>
    </a>
    <a href="?page=dashboard&tab=stock_hub" style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f8fafc;text-decoration:none;color:#1e293b;">
      <span style="font-size:18px;width:24px;text-align:center;">📦</span>
      <span style="flex:1;font-size:13px;font-weight:600;">Stock Hub</span>
      <i class="bi bi-chevron-right" style="color:#d1d5db;"></i>
    </a>
    <a href="?page=dashboard&tab=training" style="display:flex;align-items:center;gap:12px;padding:10px 0;text-decoration:none;color:#1e293b;">
      <span style="font-size:18px;width:24px;text-align:center;">🎓</span>
      <span style="flex:1;font-size:13px;font-weight:600;">Training Hub</span>
      <i class="bi bi-chevron-right" style="color:#d1d5db;"></i>
    </a>
  </div>
</div>

<div style="height:80px;"></div>
