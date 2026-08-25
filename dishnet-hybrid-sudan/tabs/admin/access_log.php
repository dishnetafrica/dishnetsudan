<?php
// Tab: access_log
// Extracted from public.php on 2026-03-15
    $allSessions = array_reverse($store->load('login_sessions.json') ?: []);
    $sessionLimit= 200;
    $filterStatus= trim($_GET['ls_status'] ?? '');
    $filterUser  = strtolower(trim($_GET['ls_user'] ?? ''));
    $filterDate  = trim($_GET['ls_date'] ?? '');
    if ($filterStatus) $allSessions = array_values(array_filter($allSessions, fn($s)=>($s['status']??'')===$filterStatus));
    if ($filterUser)   $allSessions = array_values(array_filter($allSessions, fn($s)=>str_contains(strtolower($s['email']??''),$filterUser)||str_contains(strtolower($s['name']??''),$filterUser)));
    if ($filterDate)   $allSessions = array_values(array_filter($allSessions, fn($s)=>str_starts_with($s['logged_in_at']??'',$filterDate)));
    $totalSessions  = count($allSessions);
    $allSessions    = array_slice($allSessions, 0, $sessionLimit);

    // Stats
    $statSuccess = count(array_filter($allSessions, fn($s)=>($s['status']??'')==='success'));
    $statFailed  = count(array_filter($allSessions, fn($s)=>($s['status']??'')==='failed'));
    $uniqueIPs   = count(array_unique(array_column($allSessions,'ip')));
    $uniqueUsers = count(array_unique(array_filter(array_column($allSessions,'email'))));

    // Per-user last-seen summary
    $userSummary = [];
    foreach (array_reverse($allSessions) as $ls) {
        $em = $ls['email']??''; if (!$em || ($ls['status']??'')==='failed') continue;
        if (!isset($userSummary[$em])) $userSummary[$em] = ['name'=>$ls['name']??$em,'role'=>$ls['role']??'','last_seen'=>$ls['logged_in_at']??'','last_ip'=>$ls['ip']??'','last_device'=>$ls['device']??'','logins'=>0];
        $userSummary[$em]['logins']++;
        if (($ls['logged_in_at']??'') > $userSummary[$em]['last_seen']) {
            $userSummary[$em]['last_seen']   = $ls['logged_in_at'];
            $userSummary[$em]['last_ip']     = $ls['ip']??'';
            $userSummary[$em]['last_device'] = $ls['device']??'';
        }
    }
    usort($userSummary, fn($a,$b)=>strcmp($b['last_seen'],$a['last_seen']));
    ?>

<!-- KPI row -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;">
  <div style="background:#E8F5E9;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:#2E7D32;"><?= $statSuccess ?></div>
    <div style="font-size:10px;font-weight:700;color:#2E7D32;text-transform:uppercase;letter-spacing:.5px;">Successful</div>
  </div>
  <div style="background:#FFEBEE;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:#c0392b;"><?= $statFailed ?></div>
    <div style="font-size:10px;font-weight:700;color:#c0392b;text-transform:uppercase;letter-spacing:.5px;">Failed</div>
  </div>
  <div style="background:#E3F2FD;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:#D41C1C;"><?= $uniqueUsers ?></div>
    <div style="font-size:10px;font-weight:700;color:#D41C1C;text-transform:uppercase;letter-spacing:.5px;">Users</div>
  </div>
  <div style="background:#F3E5F5;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:#6A1B9A;"><?= $uniqueIPs ?></div>
    <div style="font-size:10px;font-weight:700;color:#6A1B9A;text-transform:uppercase;letter-spacing:.5px;">Unique IPs</div>
  </div>
</div>

<!-- Per-user last-seen summary -->
<?php if (!empty($userSummary)): ?>
<div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:16px;margin-bottom:16px;">
  <div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
    <i class="bi bi-people-fill" style="color:#D41C1C;"></i> Who's Been Active
    <span style="font-size:10px;color:#9ca3af;font-weight:600;margin-left:auto;">Last seen · all time</span>
  </div>
  <div style="overflow-x:auto;">
  <table style="width:100%;border-collapse:collapse;font-size:12px;">
  <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
    <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Name / Email</th>
    <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Role</th>
    <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Last Seen</th>
    <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Last IP</th>
    <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Device</th>
    <th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Logins</th>
  </tr></thead>
  <tbody>
  <?php foreach ($userSummary as $us):
    $roleBg = ['admin'=>'#FFEBEE','accountant'=>'#E8F5E9','sales'=>'#E3F2FD','support'=>'#FFF3E0'][$us['role']] ?? '#f1f5f9';
    $roleTx = ['admin'=>'#c0392b','accountant'=>'#2E7D32','sales'=>'#1565C0','support'=>'#E65100'][$us['role']] ?? '#374151';
    $devIconMap = ['Android'=>'📱','iPhone/iPad'=>'🍎','Mobile'=>'📱']; $devIcon = $devIconMap[$us['last_device']] ?? '💻';
    $minsAgo = $us['last_seen'] ? round((time()-strtotime($us['last_seen']))/60) : null;
    $timeLabel = $minsAgo===null?'Never':($minsAgo<2?'Just now':($minsAgo<60?$minsAgo.'m ago':(round($minsAgo/60).'h ago')));
  ?>
  <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
    <td style="padding:9px 10px;">
      <div style="font-weight:700;color:#1e293b;"><?= h($us['name']) ?></div>
      <div style="font-size:10px;color:#9ca3af;"><?= h(array_search($us, $userSummary)!==false?array_search($us,$userSummary):'') ?></div>
    </td>
    <td style="padding:9px 10px;"><span style="background:<?= $roleBg ?>;color:<?= $roleTx ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;"><?= ucfirst($us['role']) ?></span></td>
    <td style="padding:9px 10px;">
      <div style="font-weight:700;color:#1e293b;"><?= $timeLabel ?></div>
      <div style="font-size:10px;color:#9ca3af;"><?= h(substr($us['last_seen'],0,16)) ?></div>
    </td>
    <td style="padding:9px 10px;font-family:monospace;font-size:11px;color:#374151;"><?= h($us['last_ip']) ?></td>
    <td style="padding:9px 10px;"><?= $devIcon ?> <?= h($us['last_device']) ?></td>
    <td style="padding:9px 10px;text-align:right;font-weight:800;color:#D41C1C;"><?= $us['logins'] ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- Filter bar -->
<div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:14px;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
  <form method="GET" style="display:contents;">
    <input type="hidden" name="page" value="dashboard">
    <input type="hidden" name="tab"  value="access_log">
    <input type="text"   name="ls_user"   placeholder="🔍 Name or email"   value="<?= h($filterUser) ?>"
      style="padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;flex:1;min-width:120px;">
    <input type="date"   name="ls_date"   value="<?= h($filterDate) ?>"
      style="padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
    <select name="ls_status" style="padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;background:#fff;">
      <option value="">All Status</option>
      <option value="success" <?= $filterStatus==='success'?'selected':'' ?>>✅ Success</option>
      <option value="failed"  <?= $filterStatus==='failed' ?'selected':'' ?>>❌ Failed</option>
    </select>
    <button type="submit" style="padding:8px 14px;background:#D41C1C;color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">Filter</button>
    <?php if ($filterStatus||$filterUser||$filterDate): ?>
    <a href="?page=dashboard&tab=access_log" style="padding:8px 12px;color:#c0392b;font-size:12px;font-weight:700;text-decoration:none;">✕ Clear</a>
    <?php endif; ?>
    <span style="font-size:11px;color:#9ca3af;margin-left:auto;">Showing <?= count($allSessions) ?> of <?= $totalSessions ?> entries</span>
  </form>
</div>

<!-- Login log table -->
<div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;">
<div style="overflow-x:auto;">
<table style="width:100%;border-collapse:collapse;font-size:12px;">
<thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
  <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Date / Time</th>
  <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">User</th>
  <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Status</th>
  <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">IP Address</th>
  <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Device</th>
  <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Browser</th>
</tr></thead>
<tbody>
<?php foreach ($allSessions as $ls):
  $isOk    = ($ls['status']??'')==='success';
  $devIconMap2 = ['Android'=>'📱','iPhone/iPad'=>'🍎','Mobile'=>'📱']; $devIcon = $devIconMap2[$ls['device']??''] ?? '💻';
  $roleBg  = ['admin'=>'#FFEBEE','accountant'=>'#E8F5E9','sales'=>'#E3F2FD','support'=>'#FFF3E0'][$ls['role']??''] ?? '#f1f5f9';
  $roleTx  = ['admin'=>'#c0392b','accountant'=>'#2E7D32','sales'=>'#1565C0','support'=>'#E65100'][$ls['role']??''] ?? '#374151';
?>
<tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
  <td style="padding:9px 12px;white-space:nowrap;">
    <div style="font-weight:700;color:#1e293b;"><?= h(substr($ls['logged_in_at']??'',0,10)) ?></div>
    <div style="font-size:10px;color:#9ca3af;"><?= h(substr($ls['logged_in_at']??'',11,8)) ?></div>
  </td>
  <td style="padding:9px 12px;">
    <div style="font-weight:700;color:#1e293b;"><?= h($ls['name']??$ls['email']??'—') ?></div>
    <div style="font-size:10px;display:flex;gap:4px;align-items:center;margin-top:2px;">
      <?php if ($ls['role']&&$ls['role']!=='unknown'): ?>
      <span style="background:<?= $roleBg ?>;color:<?= $roleTx ?>;padding:1px 6px;border-radius:6px;font-weight:700;"><?= ucfirst($ls['role']) ?></span>
      <?php endif; ?>
      <span style="color:#9ca3af;"><?= h($ls['email']??'') ?></span>
    </div>
  </td>
  <td style="padding:9px 12px;">
    <?php if ($isOk): ?>
    <span style="background:#E8F5E9;color:#2E7D32;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:700;">✅ Success</span>
    <?php else: ?>
    <span style="background:#FFEBEE;color:#c0392b;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:700;">❌ Failed</span>
    <?php endif; ?>
  </td>
  <td style="padding:9px 12px;font-family:monospace;font-size:11px;color:#374151;"><?= h($ls['ip']??'—') ?></td>
  <td style="padding:9px 12px;"><?= $devIcon ?> <?= h($ls['device']??'—') ?></td>
  <td style="padding:9px 12px;color:#64748b;"><?= h($ls['browser']??'—') ?></td>
</tr>
<?php endforeach; ?>
<?php if (empty($allSessions)): ?>
<tr><td colspan="6" style="padding:32px;text-align:center;color:#9ca3af;">
  <i class="bi bi-shield-lock" style="font-size:32px;display:block;margin-bottom:8px;color:#d1d5db;"></i>
  No login records yet. Records appear after the first login.
</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<div style="height:80px;"></div>

