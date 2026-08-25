<?php
// 
// BlueCard Agent Portal  ?page=bc_portal
// Standalone page for BlueCard retailers/dealers/agents
// Auth: $_SESSION['bc_agent'] = {id, name, role, role_name, email, mobile, wallet}
// 

// BC session helpers
function bcpSession(): array { return $_SESSION['bc_agent'] ?? []; }
function bcpLoggedIn(): bool { return !empty($_SESSION['bc_agent']['id']); }
function bcpLogout(): void { unset($_SESSION['bc_agent']); }

$bcpFeedUrl   = rtrim($config['lte_feed_url']   ?? 'http://162.241.149.144/lte_feed.php', '/');
$bcpFeedToken = $config['lte_feed_token'] ?? 'dishnet_lte_feed_Xk9mP2026';

// Helper: call lte_feed GET
function bcpGet(string $feedUrl, string $feedToken, string $table, array $params = []): ?array {
    $url = $feedUrl . '?table=' . urlencode($table) . '&token=' . urlencode($feedToken);
    foreach ($params as $k => $v) { $url .= '&' . urlencode($k) . '=' . urlencode($v); }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0]);
    $body = curl_exec($ch); curl_close($ch);
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

// Helper: call lte_feed POST
function bcpPost(string $feedUrl, string $feedToken, string $table, array $body): ?array {
    $url = $feedUrl . '?table=' . urlencode($table) . '&token=' . urlencode($feedToken);
    $json = json_encode($body);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Content-Length: '.strlen($json)],CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0]);
    $body2 = curl_exec($ch); curl_close($ch);
    $data = json_decode($body2, true);
    return is_array($data) ? $data : null;
}

//  BlueCard Feed Proxy (avoids HTTPSHTTP mixed content) 
if (($_GET['bcp'] ?? '') === 'proxy') {
    header('Content-Type: application/json');
    $table  = trim($_GET['table'] ?? '');
    if (!$table) { echo json_encode(['ok'=>false,'error'=>'table required']); exit; }
    $url = $bcpFeedUrl . '?table=' . urlencode($table) . '&token=' . urlencode($bcpFeedToken);
    $skip = ['table','token','bcp','page'];
    foreach ($_GET as $k => $v) {
        if (!in_array($k, $skip)) $url .= '&' . urlencode($k) . '=' . urlencode($v);
    }
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $ch = curl_init($url);
    $copts = [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0];
    if ($isPost) {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'multipart/form-data') !== false) {
            // File upload  forward files and form fields
            $copts[CURLOPT_POST]       = true;
            $copts[CURLOPT_POSTFIELDS] = $_FILES + $_POST;
            // Attach actual file
            foreach ($_FILES as $key => $f) {
                if (!empty($f['tmp_name'])) {
                    $copts[CURLOPT_POSTFIELDS][$key] = new CURLFile($f['tmp_name'], $f['type'], $f['name']);
                }
            }
        } else {
            $body = file_get_contents('php://input');
            $copts[CURLOPT_POST]       = true;
            $copts[CURLOPT_POSTFIELDS] = $body;
            $copts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json','Content-Length: '.strlen($body)];
        }
    }
    curl_setopt_array($ch, $copts);
    $resp = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) { echo json_encode(['ok'=>false,'error'=>'Feed error: '.$err]); exit; }
    http_response_code($code ?: 200);
    echo $resp;
    exit;
}

//  Handle Login POST 
$bcpLoginErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['bcp_action'] ?? '') === 'login') {
    $email = trim($_POST['bcp_email'] ?? '');
    $pass  = $_POST['bcp_password'] ?? '';
    if ($email && $pass) {
        $r = bcpPost($bcpFeedUrl, $bcpFeedToken, 'bc_login_check', ['email'=>$email,'password'=>$pass]);
        if ($r && !empty($r['ok']) && !empty($r['data'])) {
            $u = $r['data'];
            $rname = $u['role_name'] ?? 'retailer';
            // Only allow these roles to log in
            if (in_array($rname, ['admin','super-admin','dealer','retailer','franchisee'])) {
                $_SESSION['bc_agent'] = [
                    'id'       => (int)$u['id'],
                    'name'     => trim(($u['firstname']??'').' '.($u['lastname']??'')),
                    'email'    => $u['email'] ?? '',
                    'mobile'   => $u['mobile'] ?? '',
                    'wallet'   => (float)($u['wallet'] ?? 0),
                    'role'     => in_array($rname,['admin','super-admin']) ? 'admin' : $rname,
                    'role_display' => $u['role_display'] ?? ucfirst($rname),
                ];
                header('Location: ?page=bc_portal');
                exit;
            } else {
                $bcpLoginErr = 'Access denied  your role does not have portal access.';
            }
        } else {
            $bcpLoginErr = $r['data']['error'] ?? ($r['error'] ?? 'Invalid email or password.');
        }
    } else {
        $bcpLoginErr = 'Email and password are required.';
    }
}

//  Handle Logout 
if (($_GET['bcp'] ?? '') === 'logout') {
    bcpLogout();
    header('Location: ?page=bc_portal');
    exit;
}

//  Load agent data if logged in 
$bcpAgent = bcpSession();
$bcpTab   = $_GET['bcp'] ?? 'dashboard';
$bcpPage  = max(1, (int)($_GET['bcpg'] ?? 1));
$bcpSt    = $_GET['bcst'] ?? '';
$bcpData  = null;

if (bcpLoggedIn() && $bcpTab !== 'logout') {
    $uid  = $bcpAgent['id'];
    $role = $bcpAgent['role'];

    switch ($bcpTab) {
        case 'dashboard':
            $r = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_agent_stats', ['uid'=>$uid,'role'=>$role]);
            $bcpData = $r['data'] ?? null;
            break;
        case 'loadmoney':
            $r = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_my_loadmoney', ['uid'=>$uid,'role'=>$role,'page'=>$bcpPage,'st'=>$bcpSt]);
            $bcpData = $r['data'] ?? null;
            break;
        case 'customers':
            $q = trim($_GET['bcq'] ?? '');
            $r = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_my_customers', ['uid'=>$uid,'role'=>$role,'page'=>$bcpPage,'q'=>$q]);
            $bcpData = $r['data'] ?? null;
            break;
        case 'passbook':
            $r = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_my_passbook', ['uid'=>$uid,'page'=>$bcpPage,'type'=>$bcpSt]);
            $bcpData = $r['data'] ?? null;
            break;
        case 'customer':
            $cuid  = (int)($_GET['cid'] ?? 0);
            $csub  = $_GET['csub'] ?? 'overview';
            $cpage = max(1,(int)($_GET['bcpg'] ?? 1));
            $r = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_customer_detail', ['uid'=>$cuid]);
            $bcpData = $r['data'] ?? null;
            if ($csub === 'services') {
                $r2 = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_customer_services', ['uid'=>$cuid,'page'=>$cpage]);
                $bcpData['services'] = $r2['data'] ?? null;
            } elseif ($csub === 'passbook') {
                $r2 = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_customer_passbook', ['uid'=>$cuid,'page'=>$cpage]);
                $bcpData['passbook'] = $r2['data'] ?? null;
            } elseif ($csub === 'invoices') {
                $r2 = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_customer_invoices', ['uid'=>$cuid,'page'=>$cpage]);
                $bcpData['invoices'] = $r2['data'] ?? null;
            }
            break;
        case 'kyc':
            // Only show SIMs assigned to THIS agent (from simcard_management)
            $simsR  = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_sims_my_stock', ['uid'=>$uid]);
            $plansR = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_plans_active');
            $bcpData = ['sims' => $simsR['data'] ?? [], 'plans' => $plansR['data'] ?? []];
            break;
        case 'simstock':
            $r = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_sim_stock');
            $bcpData = $r['data'] ?? null;
            break;
        case 'simassign':
            $simsAvailR = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_sims_for_assign');
            $bcpData = ['sims' => $simsAvailR['data'] ?? []];
            break;
        case 'mysims':
            $q2 = trim($_GET['bcq'] ?? '');
            $r = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_sim_management', ['uid'=>$uid,'page'=>$bcpPage,'q'=>$q2,'st'=>$bcpSt]);
            $bcpData = $r['data'] ?? null;
            break;
        case 'simreturn':
            if (in_array($role,['admin','dealer','franchisee'])) {
                $r = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_sim_return_list', ['page'=>$bcpPage]);
            } else {
                $r = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_sim_management', ['uid'=>$uid,'page'=>$bcpPage,'st'=>'In stock']);
            }
            $bcpData = $r['data'] ?? null;
            break;
    }
}

//  Helpers 
function bcp_h($v): string { return htmlspecialchars((string)($v??''), ENT_QUOTES, 'UTF-8'); }
function bcp_usd($v): string { return '$'.number_format((float)$v,2); }
function bcp_date($d): string { if(!$d)return ''; try{return (new DateTime($d))->format('d M Y');}catch(Exception $e){return bcp_h($d);} }
function bcp_dt($d): string { if(!$d)return ''; try{return (new DateTime($d))->format('d M Y H:i');}catch(Exception $e){return bcp_h($d);} }
function bcp_pill(string $s): string {
    $cls=['pending'=>'#F59E0B','approve'=>'#16A34A','rejected'=>'#DC2626','credit'=>'#16A34A','debit'=>'#DC2626','active'=>'#16A34A'][$s2=strtolower($s)] ?? '#64748B';
    return "<span style='display:inline-flex;align-items:center;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;background:{$cls}20;color:{$cls};'>{$s}</span>";
}
function bcp_pager(int $cur, int $total, int $pages, string $tab, string $extra=''): string {
    if($pages<=1)return '<div style="font-size:12px;color:#94a3b8;padding:8px 0;">'.number_format($total).' records</div>';
    $base='?page=bc_portal&bcp='.$tab.$extra;
    $o='<div style="display:flex;gap:6px;align-items:center;padding:12px 0;flex-wrap:wrap;">';
    if($cur>1)$o.='<a href="'.$base.'&bcpg='.($cur-1).'" style="padding:5px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;text-decoration:none;color:#374151;"></a>';
    for($i=max(1,$cur-2);$i<=min($pages,$cur+2);$i++){
        $on=$i===$cur?'background:#1D4ED8;color:#fff;border-color:#1D4ED8;':'';
        $o.='<a href="'.$base.'&bcpg='.$i.'" style="padding:5px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;text-decoration:none;color:#374151;'.$on.'">'.$i.'</a>';
    }
    if($cur<$pages)$o.='<a href="'.$base.'&bcpg='.($cur+1).'" style="padding:5px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;text-decoration:none;color:#374151;"></a>';
    $o.='<span style="font-size:12px;color:#94a3b8;">'.number_format($total).' total  pg '.$cur.'/'.$pages.'</span></div>';
    return $o;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BlueCARD Agent Portal</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#F1F5F9;color:#1e293b;min-height:100vh;}
.bcp-wrap{display:flex;min-height:100vh;}
/* Sidebar */
.bcp-side{width:230px;background:#0F172A;flex-shrink:0;display:flex;flex-direction:column;}
.bcp-logo{padding:20px 18px 16px;border-bottom:1px solid rgba(255,255,255,.1);}
.bcp-logo-title{font-size:18px;font-weight:800;color:#fff;}
.bcp-logo-sub{font-size:11px;color:rgba(255,255,255,.5);margin-top:2px;}
.bcp-agent-info{padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08);}
.bcp-agent-name{font-size:13px;font-weight:700;color:#fff;}
.bcp-agent-role{font-size:11px;color:rgba(255,255,255,.5);margin-top:2px;}
.bcp-agent-wallet{font-size:16px;font-weight:800;color:#22D3EE;margin-top:6px;}
.bcp-nav{padding:12px 0;flex:1;}
.bcp-nav-a{display:flex;align-items:center;gap:10px;padding:10px 18px;font-size:13px;font-weight:600;color:rgba(255,255,255,.6);text-decoration:none;transition:.15s;}
.bcp-nav-a:hover{background:rgba(255,255,255,.06);color:#fff;}
.bcp-nav-a.on{background:rgba(29,78,216,.4);color:#fff;border-right:3px solid #3B82F6;}
.bcp-nav-a i{font-size:15px;width:18px;text-align:center;}
.bcp-logout{padding:12px 16px;border-top:1px solid rgba(255,255,255,.1);}
/* Content */
.bcp-content{flex:1;padding:24px;overflow-x:hidden;}
.bcp-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.bcp-page-title{font-size:22px;font-weight:800;}
/* Cards */
.bcp-card{background:#fff;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;margin-bottom:16px;}
.bcp-card-hd{padding:14px 18px;border-bottom:1px solid #F1F5F9;display:flex;align-items:center;justify-content:space-between;}
.bcp-card-hd-t{font-size:13px;font-weight:700;}
/* Stats */
.bcp-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:20px;}
.bcp-stat{background:#fff;border-radius:14px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.bcp-stat-ic{font-size:24px;margin-bottom:8px;}
.bcp-stat-val{font-size:26px;font-weight:800;line-height:1;}
.bcp-stat-lbl{font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-top:4px;}
/* Table */
.bcp-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.bcp-tbl th{padding:10px 16px;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;background:#F8FAFC;border-bottom:1px solid #F1F5F9;text-align:left;}
.bcp-tbl td{padding:11px 16px;border-bottom:1px solid #F8FAFC;}
.bcp-tbl tr:last-child td{border-bottom:none;}
.bcp-tbl tr:hover td{background:#FAFBFF;}
/* Form */
.bcp-field{margin-bottom:12px;}
.bcp-field label{display:block;font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.bcp-inp{width:100%;border:1.5px solid #E2E8F0;border-radius:10px;padding:10px 13px;font-size:13px;font-family:inherit;outline:none;background:#FAFAFA;}
.bcp-inp:focus{border-color:#1D4ED8;background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.08);}
.bcp-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;text-decoration:none;}
.bcp-btn.primary{background:#1D4ED8;color:#fff;}
.bcp-btn.primary:hover{background:#1E40AF;}
.bcp-btn.ghost{background:#fff;border:1.5px solid #E2E8F0;color:#374151;}
.bcp-btn.success{background:#16A34A;color:#fff;}
.bcp-btn.danger{background:#DC2626;color:#fff;}
.bcp-btn.sm{padding:5px 12px;font-size:11px;}
/* Alert */
.bcp-alert{border-radius:10px;padding:12px 16px;margin-bottom:14px;font-size:13px;}
.bcp-alert.error{background:#FEE2E2;color:#DC2626;}
.bcp-alert.success{background:#DCFCE7;color:#15803D;}
/* Login */
.bcp-login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0F172A 0%,#1E3A5F 100%);}
.bcp-login-box{background:#fff;border-radius:20px;padding:36px 32px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.bcp-login-logo{text-align:center;margin-bottom:28px;}
.bcp-login-logo-title{font-size:26px;font-weight:900;color:#0F172A;}
.bcp-login-logo-sub{font-size:13px;color:#94a3b8;margin-top:4px;}
@media(max-width:768px){.bcp-side{display:none;}.bcp-content{padding:14px;}}
/* Toolbar */
.bcp-toolbar{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;}
.bcp-toolbar input,.bcp-toolbar select{border:1.5px solid #E2E8F0;border-radius:10px;padding:9px 14px;font-size:13px;font-family:inherit;outline:none;}
.bcp-toolbar input{flex:1;min-width:180px;}
</style>
</head>
<body>
<?php if (!bcpLoggedIn()): ?>
<!--  LOGIN PAGE  -->
<div class="bcp-login-wrap">
  <div class="bcp-login-box">
    <div class="bcp-login-logo">
      <div class="bcp-login-logo-title"> BlueCARD</div>
      <div class="bcp-login-logo-sub">Agent & Retailer Portal</div>
    </div>
    <?php if ($bcpLoginErr): ?><div class="bcp-alert error"> <?= bcp_h($bcpLoginErr) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="bcp_action" value="login">
      <div class="bcp-field"><label>Email Address</label><input type="email" name="bcp_email" class="bcp-inp" placeholder="you@example.com" required autofocus></div>
      <div class="bcp-field"><label>Password</label><input type="password" name="bcp_password" class="bcp-inp" placeholder="" required></div>
      <button type="submit" class="bcp-btn primary" style="width:100%;justify-content:center;margin-top:8px;"> Login to Portal</button>
    </form>
    <div style="text-align:center;margin-top:16px;font-size:12px;color:#94a3b8;">
      DishNet Africa  BlueCARD Network<br>
      <a href="?page=dashboard" style="color:#1D4ED8;">Admin Portal </a>
    </div>
  </div>
</div>

<?php else:
$ag = $bcpAgent;
$role = $ag['role'];
$uid  = $ag['id'];
$navItems = [
    ['id'=>'dashboard', 'icon'=>'bi-speedometer2', 'label'=>'Dashboard'],
    ['id'=>'loadmoney', 'icon'=>'bi-cash-coin',    'label'=>'Load Money'],
    ['id'=>'customers', 'icon'=>'bi-people',        'label'=>'My Customers'],
    ['id'=>'passbook',  'icon'=>'bi-journal-text',  'label'=>'Passbook'],
];
if (in_array($role, ['admin','retailer','franchisee'])):
    $navItems[] = ['id'=>'kyc','icon'=>'bi-person-plus','label'=>'New KYC'];
endif;
if (in_array($role, ['admin','dealer','franchisee'])):
    $navItems[] = ['id'=>'simstock',  'icon'=>'bi-sim','label'=>'SIM Stock'];
    $navItems[] = ['id'=>'simassign', 'icon'=>'bi-box-arrow-in-right','label'=>'Assign SIMs'];
endif;
$navItems[] = ['id'=>'mysims',   'icon'=>'bi-collection','label'=>'My SIMs'];
$navItems[] = ['id'=>'simreturn','icon'=>'bi-arrow-return-left','label'=>'Return SIMs'];
?>
<!--  PORTAL APP  -->
<div class="bcp-wrap">
  <!-- Sidebar -->
  <aside class="bcp-side">
    <div class="bcp-logo">
      <div class="bcp-logo-title"> BlueCARD</div>
      <div class="bcp-logo-sub">Agent Portal</div>
    </div>
    <div class="bcp-agent-info">
      <div class="bcp-agent-name"><?= bcp_h($ag['name']) ?></div>
      <div class="bcp-agent-role"><?= bcp_h($ag['role_display'] ?? ucfirst($role)) ?></div>
      <div class="bcp-agent-wallet"><?= bcp_usd($ag['wallet']) ?></div>
    </div>
    <nav class="bcp-nav">
      <?php foreach ($navItems as $ni): $on = $bcpTab===$ni['id']?'on':''; ?>
      <a href="?page=bc_portal&bcp=<?= $ni['id'] ?>" class="bcp-nav-a <?= $on ?>">
        <i class="bi <?= $ni['icon'] ?>"></i> <?= $ni['label'] ?>
      </a>
      <?php endforeach; ?>
    </nav>
    <div class="bcp-logout">
      <a href="?page=bc_portal&bcp=logout" class="bcp-btn ghost" style="width:100%;justify-content:center;font-size:12px;">Logout</a>
    </div>
  </aside>

  <!-- Content -->
  <main class="bcp-content">
    <div class="bcp-top">
      <div class="bcp-page-title">
        <?php $titles=['dashboard'=>'Dashboard','loadmoney'=>'Load Money','customers'=>'My Customers','passbook'=>'Passbook','kyc'=>'Register Customer','simstock'=>'SIM Stock','simassign'=>'Assign SIMs','mysims'=>'My SIM Cards','simreturn'=>'Return SIMs','customer'=>'Customer Detail'];
        echo bcp_h($titles[$bcpTab] ?? 'Portal'); ?>
      </div>
      <div style="font-size:12px;color:#94a3b8;"><?= bcp_h($ag['email']) ?>  <?= bcp_h($ag['role_display'] ?? $role) ?></div>
    </div>

<?php //  DASHBOARD 
if ($bcpTab === 'dashboard' && $bcpData): $d=$bcpData; $agt=$d['agent']??[]; ?>
<div class="bcp-stats">
  <?php if ($role==='admin'): ?>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val"><?= number_format($d['total_customers']??0) ?></div><div class="bcp-stat-lbl">Total Customers</div></div>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val"><?= number_format($d['total_agents']??0) ?></div><div class="bcp-stat-lbl">Total Agents</div></div>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val"><?= number_format($d['pending_lm']??0) ?></div><div class="bcp-stat-lbl">Pending LM</div></div>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val"><?= bcp_usd(($d['monthly_revenue']??0)/100) ?></div><div class="bcp-stat-lbl">Monthly Revenue</div></div>
  <?php elseif (in_array($role,['dealer','franchisee'])): ?>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val"><?= number_format($d['sub_agents']??0) ?></div><div class="bcp-stat-lbl">Sub Agents</div></div>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val"><?= number_format($d['my_customers']??0) ?></div><div class="bcp-stat-lbl">My Customers</div></div>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val"><?= number_format($d['pending_lm']??0) ?></div><div class="bcp-stat-lbl">Pending LM</div></div>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val"><?= bcp_usd($d['total_disbursed']??0) ?></div><div class="bcp-stat-lbl">Total Disbursed</div></div>
  <?php else: // retailer ?>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val"><?= number_format($d['my_customers']??0) ?></div><div class="bcp-stat-lbl">My Customers</div></div>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val"><?= number_format($d['total_requests']??0) ?></div><div class="bcp-stat-lbl">LM Requests</div></div>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val"><?= number_format($d['pending_lm']??0) ?></div><div class="bcp-stat-lbl">Pending</div></div>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val"><?= bcp_usd($d['total_received']??0) ?></div><div class="bcp-stat-lbl">Total Received</div></div>
  <?php endif; ?>
  <div class="bcp-stat"><div class="bcp-stat-ic"></div><div class="bcp-stat-val" style="color:#22D3EE;"><?= bcp_usd($agt['wallet']??0) ?></div><div class="bcp-stat-lbl">My Wallet</div></div>
</div>
<div class="bcp-card">
  <div class="bcp-card-hd"><div class="bcp-card-hd-t"> Account Info</div></div>
  <div style="padding:16px;">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">
      <div><span style="color:#94a3b8;">Name</span><br><strong><?= bcp_h(($agt['firstname']??'').' '.($agt['lastname']??'')) ?></strong></div>
      <div><span style="color:#94a3b8;">Mobile</span><br><strong><?= bcp_h($agt['mobile']??'') ?></strong></div>
      <div><span style="color:#94a3b8;">Email</span><br><strong><?= bcp_h($agt['email']??'') ?></strong></div>
      <div><span style="color:#94a3b8;">LM Commission</span><br><strong><?= number_format((float)($agt['load_money_commission']??0),1) ?>%</strong></div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php //  LOAD MONEY 
if ($bcpTab === 'loadmoney'): $rows=$bcpData['rows']??[]; $total=$bcpData['total']??0; $pages=$bcpData['pages']??1; ?>
<form method="GET" class="bcp-toolbar">
  <input type="hidden" name="page" value="bc_portal"><input type="hidden" name="bcp" value="loadmoney">
  <select name="bcst" onchange="this.form.submit()">
    <option value="">All Status</option>
    <option value="Pending" <?= $bcpSt==='Pending'?'selected':'' ?>>Pending</option>
    <option value="Approve" <?= $bcpSt==='Approve'?'selected':'' ?>>Approved</option>
    <option value="Rejected" <?= $bcpSt==='Rejected'?'selected':'' ?>>Rejected</option>
  </select>
</form>
<div class="bcp-card">
  <div class="bcp-card-hd">
    <div class="bcp-card-hd-t"> Load Money Requests <span style="background:#E2E8F0;border-radius:20px;padding:1px 8px;font-size:11px;"><?= number_format($total) ?></span></div>
    <?php if (in_array($role,['retailer','franchisee'])): ?>
    <button onclick="bcpLmAdd()" class="bcp-btn primary" style="font-size:12px;padding:6px 14px;">+ New Request</button>
    <?php endif; ?>
  </div>
  <?php if (empty($rows)): ?><div style="padding:24px;text-align:center;color:#94a3b8;">No records found.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="bcp-tbl">
    <thead><tr>
      <th>ID</th>
      <?php if (in_array($role,['admin','dealer','franchisee'])): ?><th>Agent</th><?php endif; ?>
      <th>Amount</th><th>Approved</th><th>Commission</th><th>Status</th><th>Date</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td class="mono" style="color:#94a3b8;">#<?= (int)$r['id'] ?></td>
      <?php if (in_array($role,['admin','dealer','franchisee'])): ?>
      <td><div style="font-weight:600;"><?= bcp_h(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?></div><div style="font-size:11px;color:#94a3b8;"><?= bcp_h($r['mobile']??'') ?></div></td>
      <?php endif; ?>
      <td style="font-weight:700;"><?= bcp_usd($r['amount']??0) ?></td>
      <td><?= $r['approve_amount']!==null ? bcp_usd($r['approve_amount']) : '' ?></td>
      <td><?= $r['commission']!==null ? '$'.number_format((float)$r['commission'],2) : '' ?></td>
      <td><?= bcp_pill($r['status']??'') ?></td>
      <td style="color:#94a3b8;font-size:11px;"><?= bcp_dt($r['created_at']??null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= bcp_pager($bcpPage,$total,$pages,'loadmoney',$bcpSt?'&bcst='.urlencode($bcpSt):'') ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php //  CUSTOMERS 
if ($bcpTab === 'customers'): $rows=$bcpData['rows']??[]; $total=$bcpData['total']??0; $pages=$bcpData['pages']??1; $q=trim($_GET['bcq']??''); ?>
<form method="GET" class="bcp-toolbar">
  <input type="hidden" name="page" value="bc_portal"><input type="hidden" name="bcp" value="customers">
  <input type="text" name="bcq" value="<?= bcp_h($q) ?>" placeholder="Search name / mobile">
  <button type="submit" class="bcp-btn primary"> Search</button>
</form>
<div class="bcp-card">
  <div class="bcp-card-hd"><div class="bcp-card-hd-t"> My Customers <span style="background:#E2E8F0;border-radius:20px;padding:1px 8px;font-size:11px;"><?= number_format($total) ?></span></div></div>
  <?php if (empty($rows)): ?><div style="padding:24px;text-align:center;color:#94a3b8;">No customers found.</div>
  <?php else: ?><div style="overflow-x:auto;">
  <table class="bcp-tbl">
    <thead><tr><th>Name</th><th>Mobile</th><th>Plan</th><th>Status</th><th>Expires</th><th>Joined</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td>
        <a href="?page=bc_portal&bcp=customer&cid=<?= (int)$r['id'] ?>" style="font-weight:600;color:#1D4ED8;text-decoration:none;"><?= bcp_h(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?></a>
        <div style="font-size:11px;color:#94a3b8;"><?= bcp_h($r['email']??'') ?></div>
      </td>
      <td style="font-family:monospace;"><?= bcp_h($r['mobile']??'') ?></td>
      <td style="font-size:12px;"><?= bcp_h($r['plan_name']??'') ?></td>
      <td><?= bcp_pill($r['is_active']?'active':'inactive') ?></td>
      <td style="font-size:11px;"><?= bcp_date($r['end_date']??null) ?></td>
      <td style="font-size:11px;color:#94a3b8;"><?= bcp_date($r['created_at']??null) ?></td>
      <td style="white-space:nowrap;">
        <a href="?page=bc_portal&bcp=customer&cid=<?= (int)$r['id'] ?>" class="bcp-btn ghost" style="font-size:11px;padding:4px 9px;" title="View Details"></a>
        <button onclick="bcpEditCustomer(<?= (int)$r['id'] ?>,<?= htmlspecialchars(json_encode(['firstname'=>$r['firstname']??'','lastname'=>$r['lastname']??'','email'=>$r['email']??'','alternateMobileNo'=>$r['alternateMobileNo']??'','gender'=>$r['gender']??'male','date_of_birth'=>$r['date_of_birth']??'','nationality'=>$r['nationality']??'','address'=>$r['address']??'']),ENT_QUOTES) ?>)" class="bcp-btn ghost" style="font-size:11px;padding:4px 9px;" title="Edit"></button>
        <?php if(in_array($role,['admin','dealer'])): ?>
        <button onclick="bcpDeleteCustomer(<?= (int)$r['id'] ?>,'<?= bcp_h(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?>')" class="bcp-btn danger" style="font-size:11px;padding:4px 9px;" title="Delete"></button>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= bcp_pager($bcpPage,$total,$pages,'customers',$q?'&bcq='.urlencode($q):'') ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php //  PASSBOOK 
if ($bcpTab === 'passbook'): $rows=$bcpData['rows']??[]; $total=$bcpData['total']??0; $pages=$bcpData['pages']??1; ?>
<form method="GET" class="bcp-toolbar">
  <input type="hidden" name="page" value="bc_portal"><input type="hidden" name="bcp" value="passbook">
  <select name="bcst" onchange="this.form.submit()">
    <option value="">All Types</option>
    <option value="Credit" <?= $bcpSt==='Credit'?'selected':'' ?>>Credit</option>
    <option value="Debit"  <?= $bcpSt==='Debit'?'selected':''  ?>>Debit</option>
  </select>
</form>
<div class="bcp-card">
  <div class="bcp-card-hd"><div class="bcp-card-hd-t"> Passbook <span style="background:#E2E8F0;border-radius:20px;padding:1px 8px;font-size:11px;"><?= number_format($total) ?></span></div></div>
  <?php if (empty($rows)): ?><div style="padding:24px;text-align:center;color:#94a3b8;">No transactions found.</div>
  <?php else: ?><div style="overflow-x:auto;">
  <table class="bcp-tbl">
    <thead><tr><th>TRX</th><th>Type</th><th>Amount</th><th>Prev Bal</th><th>New Bal</th><th>Description</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td style="font-family:monospace;font-size:11px;color:#94a3b8;"><?= bcp_h($r['trx_no']??'') ?></td>
      <td><?= bcp_pill($r['type']??'') ?></td>
      <td style="font-weight:700;<?= ($r['type']??'')==='Credit'?'color:#16A34A;':'color:#DC2626;' ?>"><?= bcp_usd($r['amount']??0) ?></td>
      <td style="color:#94a3b8;"><?= bcp_usd($r['previous_balance']??0) ?></td>
      <td style="font-weight:600;"><?= bcp_usd($r['current_balance']??0) ?></td>
      <td style="font-size:12px;"><?= bcp_h($r['description']??'') ?></td>
      <td style="font-size:11px;color:#94a3b8;"><?= bcp_dt($r['created_at']??null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= bcp_pager($bcpPage,$total,$pages,'passbook',$bcpSt?'&bcst='.urlencode($bcpSt):'') ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php //  KYC 
if ($bcpTab === 'kyc' && in_array($role,['admin','retailer','franchisee'])): $sims=$bcpData['sims']??[]; $plans=$bcpData['plans']??[]; ?>
<div class="bcp-card" style="max-width:680px;">
  <div class="bcp-card-hd"><div class="bcp-card-hd-t"> Register New Customer</div></div>
  <div style="padding:20px;">
    <div id="bcpKycMsg"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div class="bcp-field"><label>First Name *</label><input type="text" id="pk_fn" class="bcp-inp" placeholder="John"></div>
      <div class="bcp-field"><label>Last Name</label><input type="text" id="pk_ln" class="bcp-inp" placeholder="Doe"></div>
      <div class="bcp-field"><label>Email</label><input type="email" id="pk_em" class="bcp-inp"></div>
      <div class="bcp-field"><label>Alt Mobile</label><input type="text" id="pk_am" class="bcp-inp" placeholder="+211..."></div>
      <div class="bcp-field"><label>WhatsApp</label><input type="text" id="pk_wa" class="bcp-inp" placeholder="+211..."></div>
      <div class="bcp-field"><label>Gender</label><select id="pk_gn" class="bcp-inp"><option value="male">Male</option><option value="female">Female</option></select></div>
      <div class="bcp-field"><label>Date of Birth</label><input type="date" id="pk_db" class="bcp-inp"></div>
      <div class="bcp-field"><label>Nationality</label><input type="text" id="pk_na" class="bcp-inp" value="South Sudanese"></div>
      <div class="bcp-field" style="grid-column:1/-1;"><label>Address</label><input type="text" id="pk_ad" class="bcp-inp"></div>

      <!-- Document Photos -->
      <div class="bcp-field">
        <label>Customer Photo</label>
        <input type="file" id="pk_img_cust" class="bcp-inp" accept="image/*,.pdf" onchange="bcpPreviewImg(this,'pk_prev_cust')">
        <img id="pk_prev_cust" src="" alt="" style="display:none;width:80px;height:80px;object-fit:cover;border-radius:8px;margin-top:6px;border:1.5px solid #E2E8F0;">
      </div>
      <div class="bcp-field">
        <label>ID Card Front</label>
        <input type="file" id="pk_img_af" class="bcp-inp" accept="image/*,.pdf" onchange="bcpPreviewImg(this,'pk_prev_af')">
        <img id="pk_prev_af" src="" alt="" style="display:none;width:80px;height:80px;object-fit:cover;border-radius:8px;margin-top:6px;border:1.5px solid #E2E8F0;">
      </div>
      <div class="bcp-field">
        <label>ID Card Back</label>
        <input type="file" id="pk_img_ab" class="bcp-inp" accept="image/*,.pdf" onchange="bcpPreviewImg(this,'pk_prev_ab')">
        <img id="pk_prev_ab" src="" alt="" style="display:none;width:80px;height:80px;object-fit:cover;border-radius:8px;margin-top:6px;border:1.5px solid #E2E8F0;">
      </div>
      <div class="bcp-field">
        <label>PAN / Other ID</label>
        <input type="file" id="pk_img_pan" class="bcp-inp" accept="image/*,.pdf" onchange="bcpPreviewImg(this,'pk_prev_pan')">
        <img id="pk_prev_pan" src="" alt="" style="display:none;width:80px;height:80px;object-fit:cover;border-radius:8px;margin-top:6px;border:1.5px solid #E2E8F0;">
      </div>

      <div class="bcp-field" style="grid-column:1/-1;">
        <label>SIM Card (Assigned to You) *</label>
        <select id="pk_si" class="bcp-inp">
          <option value="">-- Select from your assigned SIMs --</option>
          <?php foreach ($sims as $s): ?>
          <option value="<?= (int)$s['id'] ?>">MSISDN: <?= bcp_h($s['msisdn']??'') ?>  IMSI: <?= bcp_h($s['imsi']??'') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bcp-field" style="grid-column:1/-1;">
        <label>Plan *</label>
        <select id="pk_pl" class="bcp-inp">
          <option value="" data-amt="0">-- Select Plan --</option>
          <?php foreach ($plans as $p): ?>
          <option value="<?= (int)$p['id'] ?>">
            <?= bcp_h($p['name']??'') ?>  $<?= number_format((float)($p['amount']??0)/100,2) ?> / <?= (int)($p['days']??30) ?> days
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bcp-field"><label>Payment Type</label><select id="pk_pt" class="bcp-inp"><option value="Wallet">Wallet</option><option value="Cash">Cash</option></select></div>
    </div>
    <div style="background:#FEF3C7;border-radius:10px;padding:10px 14px;margin:10px 0;font-size:12px;color:#92400E;">
       Will create customer account, mark SIM as Sold, create service record, debit your wallet.
    </div>
    <button onclick="bcpKycSubmit(this)" class="bcp-btn success"> Register Customer</button>
  </div>
</div>
<?php endif; ?>

<?php //  CUSTOMER DETAIL 
if ($bcpTab === 'customer' && isset($bcpData['user'])):
$cu   = $bcpData['user'] ?? [];
$dm   = $bcpData['data_mgmt'] ?? [];
$sim  = $bcpData['sim'] ?? [];
$bt   = $bcpData['latest_topup'] ?? [];
$pr   = $bcpData['pending_recharge'] ?? [];
$master = $bcpData['master'] ?? [];
$cuid = (int)($_GET['cid'] ?? 0);
$csub = $_GET['csub'] ?? 'overview';
$msisdn = $cu['mobile'] ?? '';
$cSubNav = [
    ['id'=>'overview',  'label'=>' Overview'],
    ['id'=>'services',  'label'=>' Services'],
    ['id'=>'invoices',  'label'=>' Invoices'],
    ['id'=>'passbook',  'label'=>' Passbook'],
    ['id'=>'recharge',  'label'=>' Recharge'],
    ['id'=>'documents', 'label'=>' Documents'],
    ['id'=>'edit',      'label'=>' Edit'],
];
?>
<!-- Back + sub-nav -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
  <a href="?page=bc_portal&bcp=customers" class="bcp-btn ghost" style="font-size:12px;padding:6px 12px;"> Back</a>
  <div style="font-size:18px;font-weight:800;"><?= bcp_h(trim(($cu['firstname']??'').' '.($cu['lastname']??''))) ?></div>
  <?= bcp_pill($cu['is_active']?'active':'inactive') ?>
</div>
<div style="display:flex;gap:0;border-bottom:2px solid #E2E8F0;margin-bottom:18px;overflow-x:auto;">
  <?php foreach($cSubNav as $sn): $son=$csub===$sn['id']?'bcp-nav-a on':'bcp-nav-a'; ?>
  <a href="?page=bc_portal&bcp=customer&cid=<?= $cuid ?>&csub=<?= $sn['id'] ?>" class="<?= $son ?>" style="padding:10px 16px;font-size:13px;border-bottom:none;"><?= $sn['label'] ?></a>
  <?php endforeach; ?>
</div>

<?php // Overview 
if ($csub === 'overview'): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
  <!-- Customer Info -->
  <div class="bcp-card">
    <div class="bcp-card-hd"><div class="bcp-card-hd-t"> Customer Info</div></div>
    <div style="padding:16px;">
      <?php if (!empty($cu['profile'])): ?>
      <img src="<?= bcp_h($cu['profile']) ?>" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid #E2E8F0;margin-bottom:12px;display:block;">
      <?php endif; ?>
      <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <?php $cfields=[['Mobile',$cu['mobile']??''],['Email',$cu['email']??''],['Gender',$cu['gender']??''],['DOB',$cu['date_of_birth']??''],['Nationality',$cu['nationality']??''],['Alt Mobile',$cu['alternateMobileNo']??''],['WhatsApp',$cu['whatsapp_number']??''],['ID No',$cu['aadhar_card_no']??''],['Address',$cu['address']??''],['City',$cu['city']??''],['Retailer',($master?trim(($master['firstname']??'').' '.($master['lastname']??'')):'')]];
        foreach ($cfields as $f): ?>
        <tr style="border-bottom:1px solid #F8FAFC;">
          <td style="padding:5px 0;color:#94a3b8;font-size:11px;font-weight:700;text-transform:uppercase;width:100px;"><?= bcp_h($f[0]) ?></td>
          <td style="padding:5px 0;font-weight:600;"><?= bcp_h($f[1]) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
  <!-- Service Status -->
  <div>
    <div class="bcp-card" style="margin-bottom:14px;">
      <div class="bcp-card-hd"><div class="bcp-card-hd-t"> Active Service</div></div>
      <div style="padding:16px;">
        <?php if ($dm): ?>
        <div style="font-size:22px;font-weight:800;color:#1D4ED8;"><?= bcp_h($dm['plan_name']??'') ?></div>
        <div style="font-size:13px;color:#94a3b8;margin-top:4px;">
          <?php if (($dm['plan_type']??0)==2): ?>
          Unlimited  <?= round((strtotime($dm['end_date'])-time())/86400) ?> days left
          <?php else: $gb=round(($cu['data']??0)/1e9,2); $total=round(($dm['data']??0)/1e9,2); ?>
          <?= $gb ?> GB left of <?= $total ?> GB
          <?php endif; ?>
        </div>
        <div style="margin-top:8px;font-size:12px;">
          <span style="color:#94a3b8;">Expires:</span> <strong><?= bcp_date($dm['end_date']??null) ?></strong>
        </div>
        <?php if ($pr): ?>
        <div style="margin-top:10px;background:#FEF3C7;border-radius:8px;padding:8px 12px;font-size:12px;color:#92400E;">
           Pending advance: <strong><?= bcp_h($pr['offer_name']??'') ?></strong>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div style="color:#94a3b8;font-size:13px;">No active plan</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="bcp-card">
      <div class="bcp-card-hd"><div class="bcp-card-hd-t"> SIM Card</div></div>
      <div style="padding:14px;font-size:13px;">
        <?php if ($sim): ?>
        <div><span style="color:#94a3b8;font-size:11px;">MSISDN</span><br><strong class="mono"><?= bcp_h($sim['msisdn']??'') ?></strong></div>
        <div style="margin-top:8px;"><span style="color:#94a3b8;font-size:11px;">IMSI</span><br><strong class="mono" style="font-size:12px;"><?= bcp_h($sim['imsi']??'') ?></strong></div>
        <div style="margin-top:8px;"><span style="color:#94a3b8;font-size:11px;">Status</span><br><?= bcp_pill($sim['status']??'') ?></div>
        <?php else: ?><div style="color:#94a3b8;">No SIM record</div><?php endif; ?>
      </div>
    </div>
    <!-- Document images -->
    <?php if (!empty($cu['aadhar_card_front_img'])||!empty($cu['aadhar_card_back_img'])||!empty($cu['pan_card_img'])||!empty($cu['profile'])): ?>
    <div class="bcp-card" style="margin-top:14px;">
      <div class="bcp-card-hd"><div class="bcp-card-hd-t"> Documents</div></div>
      <div style="padding:12px;display:flex;gap:10px;flex-wrap:wrap;">
        <?php foreach([['Customer Photo',$cu['profile']??''],['ID Front',$cu['aadhar_card_front_img']??''],['ID Back',$cu['aadhar_card_back_img']??''],['PAN/Other',$cu['pan_card_img']??'']] as $doc): ?>
        <?php if ($doc[1]): ?>
        <div style="text-align:center;">
          <a href="<?= bcp_h($doc[1]) ?>" target="_blank">
            <img src="<?= bcp_h($doc[1]) ?>" style="width:80px;height:80px;object-fit:cover;border-radius:8px;border:1.5px solid #E2E8F0;" onerror="this.style.display='none'">
          </a>
          <div style="font-size:10px;color:#94a3b8;margin-top:3px;"><?= bcp_h($doc[0]) ?></div>
        </div>
        <?php endif; endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; // overview ?>

<?php // Services 
if ($csub === 'services' && isset($bcpData['services'])):
$svcD=$bcpData['services']; $srows=$svcD['rows']??[]; $stotal=$svcD['total']??0; $spages=$svcD['pages']??1; ?>
<div class="bcp-card">
  <div class="bcp-card-hd">
    <div class="bcp-card-hd-t"> Service History <span style="background:#E2E8F0;border-radius:20px;padding:1px 8px;font-size:11px;"><?= number_format($stotal) ?></span></div>
  </div>
  <?php if(empty($srows)): ?><div style="padding:20px;text-align:center;color:#94a3b8;">No service records.</div>
  <?php else: ?><div style="overflow-x:auto;">
  <table class="bcp-tbl">
    <thead><tr><th>ID</th><th>Plan</th><th>Amount</th><th>Agent</th><th>Expires</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach($srows as $sr): $cancelled=!empty($sr['deleted_at']); ?>
    <tr style="<?= $cancelled?'opacity:.5;':''; ?>">
      <td class="mono" style="color:#94a3b8;">#<?= (int)$sr['id'] ?></td>
      <td style="font-weight:600;"><?= bcp_h($sr['plan_name']??$sr['productOffering']??'') ?></td>
      <td><?= bcp_usd(($sr['amount']??0)/100) ?></td>
      <td style="font-size:12px;"><?= bcp_h($sr['agent_name']??'') ?></td>
      <td style="font-size:11px;"><?= bcp_date($sr['end_date']??null) ?></td>
      <td><?= $cancelled ? bcp_pill('cancelled') : bcp_pill('active') ?></td>
      <td>
        <?php if(!$cancelled&&in_array($role,['admin','dealer'])): ?>
        <button onclick="bcpCancelPlan(<?= (int)$sr['id'] ?>)" class="bcp-btn danger" style="font-size:11px;padding:3px 9px;"> Cancel</button>
        <?php else: ?><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= bcp_pager($bcpPage,$stotal,$spages,'customer','&cid='.$cuid.'&csub=services') ?>
  <?php endif; ?>
</div>
<?php endif; // services ?>

<?php // Passbook 
if ($csub === 'passbook' && isset($bcpData['passbook'])):
$pbD=$bcpData['passbook']; $pbrows=$pbD['rows']??[]; $pbtotal=$pbD['total']??0; $pbpages=$pbD['pages']??1; ?>
<div class="bcp-card">
  <div class="bcp-card-hd"><div class="bcp-card-hd-t"> Passbook <span style="background:#E2E8F0;border-radius:20px;padding:1px 8px;font-size:11px;"><?= number_format($pbtotal) ?></span></div></div>
  <?php if(empty($pbrows)): ?><div style="padding:20px;text-align:center;color:#94a3b8;">No passbook entries.</div>
  <?php else: ?><div style="overflow-x:auto;">
  <table class="bcp-tbl">
    <thead><tr><th>TRX</th><th>Type</th><th>Amount</th><th>Balance</th><th>Description</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($pbrows as $pb): ?>
    <tr>
      <td class="mono" style="font-size:11px;color:#94a3b8;"><?= bcp_h($pb['trx_no']??'') ?></td>
      <td><?= bcp_pill($pb['type']??'') ?></td>
      <td style="font-weight:700;<?= ($pb['type']??'')==='Credit'?'color:#16A34A;':'color:#DC2626;' ?>"><?= bcp_usd($pb['amount']??0) ?></td>
      <td class="mono" style="font-size:12px;"><?= bcp_usd($pb['current_balance']??0) ?></td>
      <td style="font-size:12px;"><?= bcp_h($pb['description']??'') ?></td>
      <td style="font-size:11px;color:#94a3b8;"><?= bcp_dt($pb['created_at']??null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= bcp_pager($bcpPage,$pbtotal,$pbpages,'customer','&cid='.$cuid.'&csub=passbook') ?>
  <?php endif; ?>
</div>
<?php endif; // passbook ?>

<?php // Invoices 
if ($csub === 'invoices' && isset($bcpData['invoices'])):
$invD=$bcpData['invoices']; $invrows=$invD['rows']??[]; $invtotal=$invD['total']??0; $invpages=$invD['pages']??1; ?>
<div class="bcp-card">
  <div class="bcp-card-hd"><div class="bcp-card-hd-t"> Invoices / Recharge History <span style="background:#E2E8F0;border-radius:20px;padding:1px 8px;font-size:11px;"><?= number_format($invtotal) ?></span></div></div>
  <?php if(empty($invrows)): ?><div style="padding:20px;text-align:center;color:#94a3b8;">No invoice records.</div>
  <?php else: ?><div style="overflow-x:auto;">
  <table class="bcp-tbl">
    <thead><tr><th>REF NO</th><th>Plan</th><th>Amount</th><th>Recharged By</th><th>Start</th><th>End</th><th>Invoice</th></tr></thead>
    <tbody>
    <?php foreach($invrows as $r): ?>
    <tr>
      <td class="mono" style="font-size:11px;color:#94a3b8;">ReFblueCARD<?= str_pad((int)$r['id'],7,'0',STR_PAD_LEFT) ?></td>
      <td style="font-weight:600;"><?= bcp_h($r['plan_name']??$r['productOffering']??'') ?></td>
      <td><?= bcp_usd(($r['amount']??0)/100) ?></td>
      <td style="font-size:12px;"><?= bcp_h($r['agent_name']??'') ?></td>
      <td style="font-size:11px;"><?= bcp_dt($r['created_at']??null) ?></td>
      <td style="font-size:11px;"><?= bcp_date($r['end_date']??null) ?></td>
      <td>
        <?php if (!empty($r['invoice_file'])): ?>
        <a href="<?= bcp_h($r['invoice_file']) ?>" target="_blank" class="bcp-btn ghost" style="font-size:11px;padding:3px 8px;"> PDF</a>
        <?php else: ?><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= bcp_pager($bcpPage,$invtotal,$invpages,'customer','&cid='.$cuid.'&csub=invoices') ?>
  <?php endif; ?>
</div>
<?php endif; // invoices ?>

<?php // Documents 
if ($csub === 'documents' && isset($bcpData['user'])): $cu2=$bcpData['user']??[]; ?>
<div class="bcp-card" style="max-width:600px;">
  <div class="bcp-card-hd"><div class="bcp-card-hd-t"> KYC Documents</div></div>
  <div style="padding:18px;">
    <?php $docs=[['Customer Photo','profile'],['ID Proof Front','aadhar_card_front_img'],['ID Proof Back','aadhar_card_back_img'],['PAN / Other ID','pan_card_img']];
    $hasDocs=false;
    foreach($docs as $doc):
      if(!empty($cu2[$doc[1]])):$hasDocs=true; ?>
    <div style="margin-bottom:18px;">
      <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:6px;"><?= bcp_h($doc[0]) ?></div>
      <a href="<?= bcp_h($cu2[$doc[1]]) ?>" target="_blank">
        <img src="<?= bcp_h($cu2[$doc[1]]) ?>" style="max-width:100%;max-height:240px;border-radius:10px;border:1.5px solid #E2E8F0;object-fit:contain;" onerror="this.parentNode.innerHTML='<span style=color:#94a3b8;font-size:12px;>Image not accessible</span>'">
      </a>
    </div>
    <?php endif; endforeach;
    if(!$hasDocs): ?><div style="color:#94a3b8;font-size:13px;text-align:center;padding:16px;">No documents uploaded for this customer.</div><?php endif; ?>
  </div>
</div>
<?php endif; // documents ?>

<?php // Edit 
if ($csub === 'edit' && isset($bcpData['user'])): $cu3=$bcpData['user']??[]; ?>
<div class="bcp-card" style="max-width:640px;">
  <div class="bcp-card-hd"><div class="bcp-card-hd-t"> Edit Customer</div></div>
  <div style="padding:20px;">
    <div id="bcpEditMsg"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div class="bcp-field"><label>First Name *</label><input type="text" id="cedit_fn" class="bcp-inp" value="<?= bcp_h($cu3['firstname']??'') ?>"></div>
      <div class="bcp-field"><label>Last Name</label><input type="text" id="cedit_ln" class="bcp-inp" value="<?= bcp_h($cu3['lastname']??'') ?>"></div>
      <div class="bcp-field"><label>Email</label><input type="email" id="cedit_em" class="bcp-inp" value="<?= bcp_h($cu3['email']??'') ?>"></div>
      <div class="bcp-field"><label>Alt Mobile</label><input type="text" id="cedit_am" class="bcp-inp" value="<?= bcp_h($cu3['alternateMobileNo']??'') ?>"></div>
      <div class="bcp-field"><label>WhatsApp</label><input type="text" id="cedit_wa" class="bcp-inp" value="<?= bcp_h($cu3['whatsapp_number']??'') ?>"></div>
      <div class="bcp-field"><label>Gender</label>
        <select id="cedit_gn" class="bcp-inp">
          <option value="male" <?= ($cu3['gender']??'')=='male'?'selected':'' ?>>Male</option>
          <option value="female" <?= ($cu3['gender']??'')=='female'?'selected':'' ?>>Female</option>
        </select>
      </div>
      <div class="bcp-field"><label>Date of Birth</label><input type="date" id="cedit_db" class="bcp-inp" value="<?= bcp_h($cu3['date_of_birth']??'') ?>"></div>
      <div class="bcp-field"><label>Nationality</label><input type="text" id="cedit_na" class="bcp-inp" value="<?= bcp_h($cu3['nationality']??'') ?>"></div>
      <div class="bcp-field"><label>ID Number</label><input type="text" id="cedit_id" class="bcp-inp" value="<?= bcp_h($cu3['aadhar_card_no']??'') ?>"></div>
      <div class="bcp-field"><label>Status</label>
        <select id="cedit_st" class="bcp-inp">
          <option value="1" <?= ($cu3['is_active']??0)?'selected':'' ?>>Active</option>
          <option value="0" <?= !($cu3['is_active']??0)?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="bcp-field" style="grid-column:1/-1;"><label>Address</label><input type="text" id="cedit_ad" class="bcp-inp" value="<?= bcp_h($cu3['address']??'') ?>"></div>
      <div class="bcp-field"><label>City</label><input type="text" id="cedit_ci" class="bcp-inp" value="<?= bcp_h($cu3['city']??'') ?>"></div>
      <div class="bcp-field"><label>State</label><input type="text" id="cedit_st2" class="bcp-inp" value="<?= bcp_h($cu3['state']??'') ?>"></div>
    </div>
    <button onclick="bcpSaveEdit(<?= $cuid ?>,this)" class="bcp-btn primary" style="margin-top:12px;"> Save Changes</button>
    <a href="?page=bc_portal&bcp=customer&cid=<?= $cuid ?>" class="bcp-btn ghost" style="margin-top:12px;margin-left:8px;">Cancel</a>
  </div>
</div>
<?php endif; // edit ?>

<?php // Recharge 
if ($csub === 'recharge' && in_array($role,['admin','dealer','retailer','franchisee'])): ?>
<div class="bcp-card" style="max-width:480px;">
  <div class="bcp-card-hd"><div class="bcp-card-hd-t"> Manual Recharge</div></div>
  <div style="padding:18px;">
    <div id="bcpRechargeMsg"></div>
    <div style="background:#EFF6FF;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#1D4ED8;">
       Customer MSISDN: <strong class="mono"><?= bcp_h($msisdn) ?></strong>
    </div>
    <div class="bcp-field">
      <label>Select Plan *</label>
      <select id="bcpRechargePlan" class="bcp-inp">
        <option value="">-- Select Plan --</option>
        <?php
        $plansR2 = bcpGet($bcpFeedUrl, $bcpFeedToken, 'bc_plans_active');
        foreach ($plansR2['data']??[] as $pl): ?>
        <option value="<?= (int)$pl['id'] ?>" data-amt="<?= (float)($pl['amount']??0) ?>" data-days="<?= (int)($pl['days']??30) ?>">
          <?= bcp_h($pl['name']??'') ?>  $<?= number_format((float)($pl['amount']??0)/100,2) ?> / <?= (int)($pl['days']??30) ?> days
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="bcp-field">
      <label>Payment Type</label>
      <select id="bcpRechargePayType" class="bcp-inp">
        <option value="Wallet">Wallet</option>
        <option value="Cash">Cash</option>
      </select>
    </div>
    <div style="background:#FEF3C7;border-radius:8px;padding:8px 12px;margin-bottom:12px;font-size:12px;color:#92400E;">
       Your wallet will be debited for the plan price.
    </div>
    <button onclick="bcpDoRecharge(this,'<?= bcp_h($msisdn) ?>',<?= (int)($ag['id']??0) ?>)" class="bcp-btn primary"> Recharge Now</button>
  </div>
</div>
<?php endif; // recharge ?>

<?php elseif ($bcpTab === 'customer'): ?>
<div class="bcp-card"><div style="padding:24px;text-align:center;color:#94a3b8;">Customer not found.</div></div>
<?php endif; // customer tab ?>

<?php //  SIM STOCK 
if ($bcpTab === 'simstock' && in_array($role,['admin','dealer','franchisee'])):
$ss=$bcpData??[]; $stats=$ss['stats']??[];
$statDef=['In stock'=>['#1D4ED8',''],'Assigned'=>['#7C3AED',''],'Sold'=>['#16A34A',''],'Returned'=>['#64748B',''],'Returned Request'=>['#F59E0B',''],'Internal usage'=>['#0891B2','']];
?>
<div class="bcp-stats">
  <?php foreach($statDef as $lbl=>[$col,$ic]): $cnt=$stats[$lbl]??0; ?>
  <div class="bcp-stat">
    <div class="bcp-stat-ic" style="font-size:20px;"><?= $ic ?></div>
    <div class="bcp-stat-val" style="color:<?= $col ?>;"><?= number_format($cnt) ?></div>
    <div class="bcp-stat-lbl"><?= bcp_h($lbl) ?></div>
  </div>
  <?php endforeach; ?>
  <div class="bcp-stat">
    <div class="bcp-stat-ic" style="font-size:20px;"></div>
    <div class="bcp-stat-val"><?= number_format($ss['total']??0) ?></div>
    <div class="bcp-stat-lbl">Total SIMs</div>
  </div>
</div>
<div class="bcp-card">
  <div class="bcp-card-hd"><div class="bcp-card-hd-t"> Recent Assignments</div></div>
  <?php $recent=$ss['recent']??[]; if(empty($recent)): ?>
  <div style="padding:20px;text-align:center;color:#94a3b8;">No history yet.</div>
  <?php else: ?><div style="overflow-x:auto;">
  <table class="bcp-tbl">
    <thead><tr><th>MSISDN</th><th>IMSI</th><th>Agent</th><th>Role</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($recent as $r): ?>
    <tr>
      <td style="font-family:monospace;font-weight:700;"><?= bcp_h($r['msisdn']??'') ?></td>
      <td style="font-family:monospace;font-size:11px;color:#94a3b8;"><?= bcp_h($r['imsi']??'') ?></td>
      <td><div style="font-weight:600;"><?= bcp_h(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?></div><div style="font-size:11px;color:#94a3b8;"><?= bcp_h($r['mobile']??'') ?></div></td>
      <td style="font-size:11px;"><?= bcp_h($r['role_display']??'') ?></td>
      <td><?= bcp_pill($r['status']??'') ?></td>
      <td style="font-size:11px;color:#94a3b8;"><?= bcp_date($r['created_at']??null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div><?php endif; ?>
</div>
<?php endif; ?>

<?php //  ASSIGN SIMs 
if ($bcpTab === 'simassign' && in_array($role,['admin','dealer','franchisee'])):
$simsAvail=$bcpData['sims']??[];
?>
<div class="bcp-card" style="max-width:680px;">
  <div class="bcp-card-hd"><div class="bcp-card-hd-t"> Assign SIM Cards to Agent</div></div>
  <div style="padding:20px;">
    <div id="bcpAssignMsg"></div>
    <div class="bcp-field">
      <label>Search Agent *</label>
      <input type="text" id="bcpAssignAgent" class="bcp-inp" placeholder="Type agent name or mobile" oninput="bcpAgentSearch(this.value,'bcpAssignAgentDrop','bcpAssignAgentId','bcpAssignAgentInfo')">
      <input type="hidden" id="bcpAssignAgentId">
      <div id="bcpAssignAgentDrop" style="display:none;border:1.5px solid #E2E8F0;border-radius:10px;margin-top:4px;max-height:180px;overflow-y:auto;background:#fff;box-shadow:0 4px 16px rgba(0,0,0,.1);"></div>
      <div id="bcpAssignAgentInfo" style="display:none;margin-top:6px;background:#F0FDF4;border-radius:8px;padding:8px 12px;font-size:12px;color:#15803D;"></div>
    </div>
    <div class="bcp-field">
      <label>Filter SIMs</label>
      <input type="text" class="bcp-inp" placeholder="MSISDN or IMSI" oninput="bcpFilterSims(this.value)" style="margin-bottom:6px;">
      <div style="border:1.5px solid #E2E8F0;border-radius:10px;max-height:220px;overflow-y:auto;">
        <?php if(empty($simsAvail)): ?>
        <div style="padding:16px;text-align:center;color:#94a3b8;">No SIMs in stock</div>
        <?php else: ?>
        <table class="bcp-tbl" id="bcpSimTable">
          <thead style="position:sticky;top:0;background:#F8FAFC;">
            <tr><th style="width:32px;"><input type="checkbox" id="bcpCheckAll" onchange="bcpToggleAll(this)"></th><th>MSISDN</th><th>IMSI</th><th>Type</th><th>Price</th></tr>
          </thead>
          <tbody>
          <?php foreach($simsAvail as $s): ?>
          <tr class="bcp-sr">
            <td><input type="checkbox" class="bcp-sc" value="<?= (int)$s['id'] ?>" data-price="<?= (float)($s['price']??0) ?>"></td>
            <td style="font-family:monospace;font-weight:700;"><?= bcp_h($s['msisdn']??'') ?></td>
            <td style="font-family:monospace;font-size:11px;color:#94a3b8;"><?= bcp_h($s['imsi']??'') ?></td>
            <td style="font-size:11px;"><?= bcp_h($s['sim_type']??'') ?></td>
            <td><?= ($s['price']??0)?bcp_usd($s['price']):'Free' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <div style="margin-top:6px;font-size:12px;color:#94a3b8;">Selected: <strong id="bcpSelCount">0</strong> SIMs  Total cost: <strong id="bcpSelPrice">$0.00</strong></div>
    </div>
    <div class="bcp-field">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;text-transform:none;">
        <input type="checkbox" id="bcpChargeWallet" checked> Charge agent wallet for SIM cost
      </label>
    </div>
    <button onclick="bcpDoAssign(this)" class="bcp-btn primary"> Assign Selected SIMs</button>
  </div>
</div>
<?php endif; ?>

<?php //  MY SIMs 
if ($bcpTab === 'mysims'):
$rows=$bcpData['rows']??[]; $total=$bcpData['total']??0; $pages=$bcpData['pages']??1;
$q2=trim($_GET['bcq']??'');
?>
<form method="GET" class="bcp-toolbar" style="margin-bottom:14px;">
  <input type="hidden" name="page" value="bc_portal"><input type="hidden" name="bcp" value="mysims">
  <input type="text" name="bcq" value="<?= bcp_h($q2) ?>" placeholder="MSISDN, IMSI" class="bcp-inp" style="flex:1;min-width:160px;">
  <select name="bcst" class="bcp-inp" style="flex:none;" onchange="this.form.submit()">
    <option value="">All Status</option>
    <?php foreach(['In stock','Assigned','Sold','Returned','Returned Request'] as $s): ?>
    <option value="<?= $s ?>" <?= $bcpSt===$s?'selected':'' ?>><?= $s ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="bcp-btn primary"></button>
</form>
<div class="bcp-card">
  <div class="bcp-card-hd"><div class="bcp-card-hd-t"> My SIM Cards <span style="background:#E2E8F0;border-radius:20px;padding:1px 8px;font-size:11px;"><?= number_format($total) ?></span></div></div>
  <?php if(empty($rows)): ?><div style="padding:20px;text-align:center;color:#94a3b8;">No SIM records found.</div>
  <?php else: ?><div style="overflow-x:auto;">
  <table class="bcp-tbl">
    <thead><tr><th>MSISDN</th><th>IMSI</th><th>Status</th><th>Price</th><th>Master</th><th>Date</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach($rows as $r): ?>
    <tr>
      <td style="font-family:monospace;font-weight:700;"><?= bcp_h($r['msisdn']??'') ?></td>
      <td style="font-family:monospace;font-size:11px;color:#94a3b8;"><?= bcp_h($r['imsi']??'') ?></td>
      <td><?= bcp_pill($r['status']??'') ?></td>
      <td><?= ($r['price']??0)?bcp_usd($r['price']):'' ?></td>
      <td style="font-size:12px;"><?= bcp_h($r['master_name']??'') ?></td>
      <td style="font-size:11px;color:#94a3b8;"><?= bcp_date($r['created_at']??null) ?></td>
      <td>
        <?php if (($r['status']??'')==='In stock'): ?>
        <button onclick="bcpReqReturn(<?= (int)$r['sim_id'] ?>, '<?= bcp_h($r['msisdn']??'') ?>')" class="bcp-btn ghost" style="padding:4px 10px;font-size:11px;"> Return</button>
        <?php elseif(($r['status']??'')==='Returned Request'): ?>
        <span style="font-size:11px;color:#F59E0B;font-weight:700;"> Pending</span>
        <?php else: ?><span style="font-size:11px;color:#94a3b8;"></span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= bcp_pager($bcpPage,$total,$pages,'mysims',($q2?'&bcq='.urlencode($q2):'').($bcpSt?'&bcst='.urlencode($bcpSt):'')) ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php //  RETURN SIMs 
if ($bcpTab === 'simreturn'):
$rows=$bcpData['rows']??[]; $total=$bcpData['total']??0; $pages=$bcpData['pages']??1;
$isApprover=in_array($role,['admin','dealer','franchisee']);
?>
<div class="bcp-card">
  <div class="bcp-card-hd">
    <div class="bcp-card-hd-t"> <?= $isApprover?'Pending Return Requests':'My SIMs (select to return)' ?> <span style="background:#E2E8F0;border-radius:20px;padding:1px 8px;font-size:11px;"><?= number_format($total) ?></span></div>
  </div>
  <div id="bcpRetMsg" style="padding:0 16px;"></div>
  <?php if(empty($rows)): ?><div style="padding:20px;text-align:center;color:#94a3b8;"><?= $isApprover?'No pending return requests.':'No in-stock SIMs found.' ?></div>
  <?php else: ?><div style="overflow-x:auto;">
  <table class="bcp-tbl">
    <thead><tr>
      <th style="width:32px;"><input type="checkbox" onchange="bcpRetToggleAll(this)"></th>
      <th>MSISDN</th><th>IMSI</th>
      <?php if($isApprover): ?><th>Agent</th><?php endif; ?>
      <th>Price</th><th>Refund (50%)</th><th>Date</th>
    </tr></thead>
    <tbody>
    <?php foreach($rows as $r): ?>
    <tr>
      <td><input type="checkbox" class="bcp-ret-chk" value="<?= (int)$r['sim_id'] ?>"></td>
      <td style="font-family:monospace;font-weight:700;"><?= bcp_h($r['msisdn']??'') ?></td>
      <td style="font-family:monospace;font-size:11px;color:#94a3b8;"><?= bcp_h($r['imsi']??'') ?></td>
      <?php if($isApprover): ?>
      <td><div style="font-weight:600;"><?= bcp_h(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?></div><div style="font-size:11px;color:#94a3b8;"><?= bcp_h($r['mobile']??'') ?></div></td>
      <?php endif; ?>
      <td><?= ($r['price']??0)?bcp_usd($r['price']):'' ?></td>
      <td style="color:#16A34A;font-weight:700;"><?= ($r['price']??0)?bcp_usd($r['price']/2):'' ?></td>
      <td style="font-size:11px;color:#94a3b8;"><?= bcp_date($r['created_at']??null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div style="padding:12px 16px;border-top:1px solid #F1F5F9;display:flex;gap:10px;align-items:center;">
    <?php if($isApprover): ?>
    <button onclick="bcpRetAccept(this)" class="bcp-btn success"> Accept Selected Returns</button>
    <?php else: ?>
    <button onclick="bcpRetRequest(this)" class="bcp-btn danger"> Request Return for Selected</button>
    <?php endif; ?>
  </div>
  <?= bcp_pager($bcpPage,$total,$pages,'simreturn','') ?>
  <?php endif; ?>
</div>
<?php endif; ?>

  </main>
</div>

<!-- LM Request Modal (for retailer/franchisee) -->
<div id="bcpLmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:18px;padding:24px;width:100%;max-width:420px;">
    <div style="display:flex;justify-content:space-between;margin-bottom:16px;">
      <div style="font-size:16px;font-weight:800;"> New Load Money Request</div>
      <button onclick="document.getElementById('bcpLmModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;"></button>
    </div>
    <div id="bcpLmMsg"></div>
    <div class="bcp-field"><label>Amount (USD)</label><input type="number" id="bcpLmAmt" class="bcp-inp" placeholder="0" min="1"></div>
    <div style="background:#EFF6FF;border-radius:10px;padding:10px;margin-bottom:12px;font-size:12px;color:#1D4ED8;">
       Request goes to your master agent for approval. Commission: <?= bcp_h($ag['role_display']??$role) ?>
    </div>
    <div style="display:flex;gap:10px;">
      <button onclick="bcpLmSubmit(this)" class="bcp-btn primary" style="flex:1;"> Send Request</button>
      <button onclick="document.getElementById('bcpLmModal').style.display='none'" class="bcp-btn ghost">Cancel</button>
    </div>
  </div>
</div>

<script>
var _bcpProxy='?page=bc_portal&bcp=proxy';
var _bcpUid=<?= (int)($ag['id']??0) ?>;
var _bcpRole='<?= bcp_h($ag['role']??'') ?>';

function bcpPost(table, body, cb) {
    fetch(_bcpProxy+'&table='+encodeURIComponent(table),{
        method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)
    }).then(function(r){return r.json();}).then(cb).catch(function(e){cb({ok:false,error:''+e});});
}
function bcpAlert(el,msg,type){el.innerHTML='<div class="bcp-alert '+type+'">'+msg+'</div>';}

function bcpLmAdd(){
    document.getElementById('bcpLmAmt').value='';
    document.getElementById('bcpLmMsg').innerHTML='';
    document.getElementById('bcpLmModal').style.display='flex';
}
function bcpLmSubmit(btn){
    var amt=parseInt(document.getElementById('bcpLmAmt').value)||0;
    var msg=document.getElementById('bcpLmMsg');
    if(amt<=0){bcpAlert(msg,' Enter a valid amount','error');return;}
    btn.disabled=true;btn.textContent=' Sending';
    bcpPost('bc_lm_create',{user_id:_bcpUid,amount:amt,type:'Load Money'},function(d){
        btn.disabled=false;btn.textContent=' Send Request';
        if(d.ok){bcpAlert(msg,' Request sent! Commission: $'+d.commission,'success');setTimeout(function(){location.reload();},1200);}
        else{bcpAlert(msg,' '+(d.error||'Failed'),'error');}
    });
}
function bcpKycSubmit(btn){
    var msg=document.getElementById('bcpKycMsg');
    var p={
        firstname:   document.getElementById('pk_fn').value.trim(),
        lastname:    document.getElementById('pk_ln').value.trim(),
        email:       document.getElementById('pk_em').value.trim(),
        alternateMobileNo: document.getElementById('pk_am').value.trim(),
        whatsapp_number: document.getElementById('pk_wa').value.trim(),
        gender:      document.getElementById('pk_gn').value,
        date_of_birth: document.getElementById('pk_db').value,
        nationality: document.getElementById('pk_na').value.trim(),
        address:     document.getElementById('pk_ad').value.trim(),
        sim_id:      parseInt(document.getElementById('pk_si').value)||0,
        offer_id:    parseInt(document.getElementById('pk_pl').value)||0,
        retailer_id: _bcpUid,
        payment_type:document.getElementById('pk_pt').value,
        company_id:  1
    };
    if(!p.firstname){bcpAlert(msg,' First name is required','error');return;}
    if(!p.sim_id){bcpAlert(msg,' Please select a SIM card','error');return;}
    if(!p.offer_id){bcpAlert(msg,' Please select a plan','error');return;}
    btn.disabled=true;btn.textContent=' Uploading documents';
    // Upload images first, then submit
    var imgFields=[
        {input:'pk_img_cust',field:'customer_img'},
        {input:'pk_img_af',  field:'aadhar_card_front_img'},
        {input:'pk_img_ab',  field:'aadhar_card_back_img'},
        {input:'pk_img_pan', field:'pan_card_img'}
    ];
    bcpUploadImages(imgFields, 0, p, function(payload, uploadErr){
        if(uploadErr){btn.disabled=false;btn.textContent=' Register Customer';bcpAlert(msg,' Image upload failed: '+uploadErr,'error');return;}
        btn.textContent=' Registering';
        bcpPost('bc_kyc_create',payload,function(d){
        btn.disabled=false;btn.textContent=' Register Customer';
        if(d.ok){
            var newWal=d.wallet!==null?'  Your wallet: $'+parseFloat(d.wallet).toFixed(2):'';
            bcpAlert(msg,' Customer registered!<br> MSISDN: <strong>'+d.msisdn+'</strong>  Plan: <strong>'+d.plan+'</strong>  Expires: '+d.end_date+newWal,'success');
            // Save backup locally via plugin API (fire and forget)
            var localPayload=Object.assign({},payload,{user_id:d.user_id,service_id:d.service_id,balance_topup_id:d.balance_topup_id,data_mgmt_id:d.data_mgmt_id,msisdn:d.msisdn,imsi:d.imsi,plan_name:d.plan,plan_price:d.plan_price,end_date:d.end_date,offer_id:d.offer_id,sim_id:d.sim_id});
            fetch('?page=api&action=bc_kyc_save_local',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(localPayload)}).catch(function(){});
            ['pk_si','pk_pl','pk_fn','pk_ln','pk_em','pk_img_cust','pk_img_af','pk_img_ab','pk_img_pan'].forEach(function(id){var el=document.getElementById(id);if(el){el.value='';} });
            ['pk_prev_cust','pk_prev_af','pk_prev_ab','pk_prev_pan'].forEach(function(id){var el=document.getElementById(id);if(el){el.style.display='none';el.src='';}});
        }
        else{bcpAlert(msg,' '+(d.error||'Registration failed'),'error');}
        btn.disabled=false;btn.textContent=' Register Customer';
        });
    });
}

//  SIM Management JS 
var _bcpAgTimer={};
function bcpAgentSearch(val,dropId,hiddenId,infoId){
    var dd=document.getElementById(dropId);
    document.getElementById(hiddenId).value='';
    if(infoId)document.getElementById(infoId).style.display='none';
    clearTimeout(_bcpAgTimer[dropId]);
    if(val.length<2){dd.style.display='none';return;}
    _bcpAgTimer[dropId]=setTimeout(function(){
        bcpFeedGet('bc_agents_search',{q:val},function(d){
            dd.innerHTML='';
            if(!d.ok||!d.data||!d.data.length){dd.innerHTML='<div style="padding:10px;font-size:12px;color:#94a3b8;">No agents found</div>';dd.style.display='';return;}
            d.data.forEach(function(a){
                var el=document.createElement('div');
                el.style.cssText='padding:10px 14px;cursor:pointer;border-bottom:1px solid #F1F5F9;font-size:13px;';
                el.onmouseover=function(){this.style.background='#EFF6FF';};
                el.onmouseout=function(){this.style.background='';};
                el.innerHTML='<strong>'+a.firstname+' '+a.lastname+'</strong> <span style="font-size:11px;color:#1D4ED8;background:#EFF6FF;padding:1px 8px;border-radius:20px;">'+a.role_display+'</span><div style="font-size:11px;color:#94a3b8;">'+a.mobile+'  Wallet: $'+parseFloat(a.wallet||0).toFixed(2)+'</div>';
                el.onclick=(function(agent){return function(){
                    document.getElementById(hiddenId).value=agent.id;
                    document.getElementById(dropId).previousElementSibling.value=agent.firstname+' '+agent.lastname;
                    dd.style.display='none';
                    if(infoId){var inf=document.getElementById(infoId);inf.innerHTML='<strong>'+agent.firstname+' '+agent.lastname+'</strong>  '+agent.role_display+'  Wallet: $'+parseFloat(agent.wallet||0).toFixed(2);inf.style.display='';}
                };})(a);
                dd.appendChild(el);
            });
            dd.style.display='';
        });
    },350);
}
function bcpFeedGet(table,params,cb){
    var qs='?page=bc_portal&bcp=proxy&table='+encodeURIComponent(table);
    Object.keys(params).forEach(function(k){qs+='&'+encodeURIComponent(k)+'='+encodeURIComponent(params[k]);});
    fetch(qs).then(function(r){return r.json();}).then(cb).catch(function(e){cb({ok:false,error:''+e});});
}
function bcpFilterSims(val){
    val=val.toLowerCase();
    document.querySelectorAll('#bcpSimTable tbody .bcp-sr').forEach(function(tr){tr.style.display=(!val||tr.textContent.toLowerCase().indexOf(val)>-1)?'':'none';});
}
function bcpToggleAll(cb){
    document.querySelectorAll('.bcp-sc').forEach(function(c){if(c.closest('tr').style.display!=='none')c.checked=cb.checked;});
    bcpUpdateSelCount();
}
function bcpUpdateSelCount(){
    var chks=document.querySelectorAll('.bcp-sc:checked'),tot=0;
    chks.forEach(function(c){tot+=parseFloat(c.dataset.price||0);});
    var sc=document.getElementById('bcpSelCount'),sp=document.getElementById('bcpSelPrice');
    if(sc)sc.textContent=chks.length;if(sp)sp.textContent='$'+tot.toFixed(2);
}
document.addEventListener('change',function(e){if(e.target&&e.target.classList.contains('bcp-sc'))bcpUpdateSelCount();});
function bcpDoAssign(btn){
    var msg=document.getElementById('bcpAssignMsg');
    var agentId=parseInt(document.getElementById('bcpAssignAgentId').value)||0;
    if(!agentId){bcpAlert(msg,'<i style="color:#DC2626"> Select an agent first</i>','error');return;}
    var ids=[];document.querySelectorAll('.bcp-sc:checked').forEach(function(c){ids.push(parseInt(c.value));});
    if(!ids.length){bcpAlert(msg,'<i style="color:#DC2626"> Select at least one SIM</i>','error');return;}
    var charge=document.getElementById('bcpChargeWallet').checked;
    btn.disabled=true;btn.textContent=' Assigning';
    bcpPost('bc_sim_assign',{sim_ids:ids,agent_id:agentId,master_id:_bcpUid,charge_wallet:charge},function(d){
        btn.disabled=false;btn.textContent=' Assign Selected SIMs';
        if(d.ok){bcpAlert(msg,' '+d.assigned+' SIM(s) assigned!'+(d.charged?' Wallet debited $'+parseFloat(d.total_price||0).toFixed(2):' (no charge)'),'success');setTimeout(function(){location.reload();},1400);}
        else{bcpAlert(msg,' '+(d.error||'Failed'),'error');}
    });
}
function bcpRetToggleAll(cb){document.querySelectorAll('.bcp-ret-chk').forEach(function(c){c.checked=cb.checked;});}
function bcpReqReturn(simId,msisdn){
    if(!confirm('Request return of SIM '+msisdn+'?'))return;
    bcpPost('bc_sim_return_req',{sim_ids:[simId],agent_id:_bcpUid},function(d){
        if(d.ok){location.reload();}else{alert(' '+(d.error||'Failed'));}
    });
}
function bcpRetAccept(btn){
    var ids=[];document.querySelectorAll('.bcp-ret-chk:checked').forEach(function(c){ids.push(parseInt(c.value));});
    var msg=document.getElementById('bcpRetMsg');
    if(!ids.length){bcpAlert(msg,' Select at least one SIM','error');return;}
    if(!confirm('Accept return of '+ids.length+' SIM(s)? Agent gets 50% refund.'))return;
    btn.disabled=true;btn.textContent=' Processing';
    bcpPost('bc_sim_return_accept',{sim_ids:ids},function(d){
        btn.disabled=false;btn.textContent=' Accept Selected Returns';
        if(d.ok){bcpAlert(msg,' '+d.accepted+' return(s) accepted.','success');setTimeout(function(){location.reload();},1200);}
        else{bcpAlert(msg,' '+(d.error||'Failed'),'error');}
    });
}
function bcpEditCustomer(uid, data){
    // navigate to edit sub-tab on detail page
    window.location='?page=bc_portal&bcp=customer&cid='+uid+'&csub=edit';
}
function bcpDeleteCustomer(uid, name){
    if(!confirm('Delete customer '+name+' (#'+uid+')? This cannot be undone.'))return;
    bcpPost('bc_customer_delete',{id:uid},function(d){
        if(d.ok){location.reload();}
        else{alert(' '+(d.error||'Delete failed'));}
    });
}
function bcpSaveEdit(uid, btn){
    var msg=document.getElementById('bcpEditMsg');
    var payload={
        id:uid,
        firstname:document.getElementById('cedit_fn').value.trim(),
        lastname:document.getElementById('cedit_ln').value.trim(),
        email:document.getElementById('cedit_em').value.trim(),
        alternateMobileNo:document.getElementById('cedit_am').value.trim(),
        whatsapp_number:document.getElementById('cedit_wa').value.trim(),
        gender:document.getElementById('cedit_gn').value,
        date_of_birth:document.getElementById('cedit_db').value,
        nationality:document.getElementById('cedit_na').value.trim(),
        aadhar_card_no:document.getElementById('cedit_id').value.trim(),
        is_active:document.getElementById('cedit_st').value,
        address:document.getElementById('cedit_ad').value.trim(),
        city:document.getElementById('cedit_ci').value.trim(),
        state:document.getElementById('cedit_st2').value.trim()
    };
    if(!payload.firstname){bcpAlert(msg,' First name required','error');return;}
    btn.disabled=true;btn.textContent=' Saving';
    bcpPost('bc_customer_update',payload,function(d){
        btn.disabled=false;btn.textContent=' Save Changes';
        if(d.ok){bcpAlert(msg,' Customer updated successfully!','success');setTimeout(function(){window.location='?page=bc_portal&bcp=customer&cid='+uid;},1000);}
        else{bcpAlert(msg,' '+(d.error||'Update failed'),'error');}
    });
}
function bcpDoRecharge(btn, msisdn, agentId){
    var msg=document.getElementById('bcpRechargeMsg');
    var planSel=document.getElementById('bcpRechargePlan');
    var offerId=parseInt(planSel.value)||0;
    var payType=document.getElementById('bcpRechargePayType').value;
    if(!offerId){bcpAlert(msg,' Please select a plan','error');return;}
    var planUsd=parseFloat(planSel.options[planSel.selectedIndex]?.getAttribute('data-amt')||'0')/100||0;
    btn.disabled=true;btn.textContent=' Checking limit';
    // Check outstanding before recharge
    fetch('?page=api&action=bc_check_outstanding',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({agent_id:agentId,amount_usd:planUsd})
    }).then(function(r){return r.json();}).then(function(chk){
        var data=chk.data||chk;
        if(data.blocked){
            btn.disabled=false;btn.textContent=' Recharge Now';
            bcpAlert(msg,' BLOCKED: Your outstanding balance is $'+(data.outstanding||0).toFixed(2)+' (limit $'+(data.limit_usd||500).toFixed(0)+'). BBC must collect payment before you can recharge more customers.','error');
            return;
        }
        btn.textContent=' Recharging';
        bcpPost('bc_customer_recharge',{offer_id:offerId,msisdn:msisdn,agent_id:agentId,payment_type:payType},function(d){
            btn.disabled=false;btn.textContent=' Recharge Now';
            if(d.ok){bcpAlert(msg,' Recharged! Plan: '+d.plan+'  Expires: '+d.end_date+(d.new_wallet!==undefined?'  Wallet: $'+parseFloat(d.new_wallet).toFixed(2):''),'success');}
            else{bcpAlert(msg,' '+(d.error||'Failed'),'error');}
        });
    }).catch(function(){
        // Network error - proceed but warn
        btn.textContent=' Recharging';
        bcpPost('bc_customer_recharge',{offer_id:offerId,msisdn:msisdn,agent_id:agentId,payment_type:payType},function(d){
            btn.disabled=false;btn.textContent=' Recharge Now';
            if(d.ok){bcpAlert(msg,' Recharged! Plan: '+d.plan+'  Expires: '+d.end_date,'success');}
            else{bcpAlert(msg,' '+(d.error||'Failed'),'error');}
        });
    });
}
function bcpCancelPlan(btId){
    if(!confirm('Cancel this plan? If it is the active plan, the customer will be deactivated and the agent refunded.'))return;
    bcpPost('bc_customer_cancel_plan',{balance_topup_id:btId,reason:'Cancelled by agent'},function(d){
        if(d.ok){location.reload();}else{alert(' '+(d.error||'Failed'));}
    });
}
function bcpRetRequest(btn){
    var ids=[];document.querySelectorAll('.bcp-ret-chk:checked').forEach(function(c){ids.push(parseInt(c.value));});
    var msg=document.getElementById('bcpRetMsg');
    if(!ids.length){bcpAlert(msg,' Select at least one SIM','error');return;}
    if(!confirm('Request return of '+ids.length+' SIM(s)?'))return;
    btn.disabled=true;btn.textContent=' Sending';
    bcpPost('bc_sim_return_req',{sim_ids:ids,agent_id:_bcpUid},function(d){
        btn.disabled=false;btn.textContent=' Request Return for Selected';
        if(d.ok){bcpAlert(msg,' Return requested for '+d.requested+' SIM(s).','success');setTimeout(function(){location.reload();},1200);}
        else{bcpAlert(msg,' '+(d.error||'Failed'),'error');}
    });
}

function bcpPreviewImg(inp, prevId){
    var prev=document.getElementById(prevId);
    if(!inp.files||!inp.files[0]){prev.style.display='none';return;}
    var reader=new FileReader();
    reader.onload=function(e){prev.src=e.target.result;prev.style.display='';};
    reader.readAsDataURL(inp.files[0]);
}

function bcpUploadImages(fields, idx, payload, done){
    if(idx>=fields.length){done(payload,null);return;}
    var f=fields[idx];
    var inp=document.getElementById(f.input);
    if(!inp||!inp.files||!inp.files[0]){
        bcpUploadImages(fields,idx+1,payload,done);return;
    }
    var fd=new FormData();
    fd.append('file',inp.files[0]);
    fetch('?page=bc_portal&bcp=proxy&table=bc_upload_img&field='+encodeURIComponent(f.field),{
        method:'POST',body:fd
    }).then(function(r){return r.json();}).then(function(d){
        if(d.ok){payload[f.field]=d.url;}
        else{done(payload,d.error||'Upload failed');return;}
        bcpUploadImages(fields,idx+1,payload,done);
    }).catch(function(e){done(payload,''+e);});
}
</script>
<?php endif; ?>
</body>
</html>
