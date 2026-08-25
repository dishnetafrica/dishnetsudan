<?php
// Tab: engage_failed_queue
// Sub-tabs: Failed Queue (retry/dismiss) + CRM Events (webhook delivery report)
// v4.10.1

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }


$notify   = svc('notify');
$fqSub    = $_GET['fqsub'] ?? 'queue';
$dataDir  = method_exists($store, 'getDataDir') ? $store->getDataDir() : dirname(__DIR__, 2) . '/data';

// ── Handle POST actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fqAction  = $_POST['fq_action'] ?? '';
    $adminName = $retailer['name'] ?? 'Admin';

    if ($fqAction === 'retry_one') {
        $qId = (int)($_POST['queue_id'] ?? 0);
        if ($qId) {
            $result = $notify->retryOne($qId, $adminName);
            flash($result['success'] ? '✅ Message resent!' : '❌ Retry failed: ' . ($result['error'] ?? 'Unknown'), $result['success'] ? 'success' : 'danger');
        }
        redirect('?page=dashboard&tab=engage_failed_queue&fqsub=queue');
    }
    if ($fqAction === 'retry_bulk') {
        $maxBatch = max(1, min(100, (int)($_POST['max_batch'] ?? 50)));
        $result = $notify->retryBulk($adminName, $maxBatch);
        flash("Bulk retry: {$result['sent']}/{$result['total']} sent, {$result['failed']} still failed", $result['failed'] > 0 ? 'warning' : 'success');
        redirect('?page=dashboard&tab=engage_failed_queue&fqsub=queue');
    }
    if ($fqAction === 'dismiss_one') {
        $qId = (int)($_POST['queue_id'] ?? 0);
        if ($qId) { $notify->dismissOne($qId, $adminName); flash('Notification dismissed.', 'info'); }
        redirect('?page=dashboard&tab=engage_failed_queue&fqsub=queue');
    }
    if ($fqAction === 'dismiss_all') {
        $count = $notify->dismissAll($adminName);
        flash("{$count} notification(s) dismissed.", 'info');
        redirect('?page=dashboard&tab=engage_failed_queue&fqsub=queue');
    }
    if ($fqAction === 'purge_old') {
        $days = max(1, (int)($_POST['purge_days'] ?? 30));
        $count = $notify->purgeQueue($days);
        flash("{$count} old record(s) purged.", 'info');
        redirect('?page=dashboard&tab=engage_failed_queue&fqsub=queue');
    }
}

// ── Shared data ──────────────────────────────────────────────────────────────
$stats = $notify->getQueueStats();

$eventLabels = [
    'ops_kyc_submitted'=>'📋 KYC','ops_kyc_crm_created'=>'✅ CRM','ops_kyc_crm_failed'=>'⚠️ CRM Fail',
    'ops_kyc_additional_service'=>'🔄 Add. Service',
    'ops_kyc_customer_welcome'=>'🌟 Welcome','ops_wallet_topped_up'=>'💰 Wallet',
    'ops_recharge_submitted'=>'💳 Recharge','ops_recharge_approved'=>'✅ Rech. OK',
    'ops_recharge_rejected'=>'❌ Rech. Rej','ops_invoice_created'=>'🧾 Invoice',
    'ops_invoice_auto_paid'=>'✅ Auto-Paid','ops_invoice_partial_credit'=>'🧾 Partial',
    'ops_payment_received'=>'✅ Payment','ops_handover_submitted'=>'💵 Handover',
    'ops_handover_approved'=>'✅ Handover OK','staff_cash_received'=>'💰 Cash In',
    'ops_pre_due_d7'=>'📋 Due 7d','ops_pre_due_d3'=>'⏰ Due 3d','ops_pre_due_d1'=>'🔴 Due 1d',
    'ops_overdue_d1'=>'⏰ OD+1','ops_overdue_d3'=>'🔴 OD+3','ops_overdue_d5'=>'🚨 OD+5',
    'ops_install_confirmed'=>'📅 Install','ops_install_dispatched'=>'🚗 Dispatch',
    'ops_outage_alert'=>'🔧 Outage','ops_lead_assigned'=>'🎯 Lead',
    'install_approved'=>'✅ Inst. OK','install_rejected'=>'⚠️ Inst. Rej',
    'document_send'=>'📄 PDF','lte_safety_alert'=>'🚨 LTE Safety',
    'cash_discrepancy_alert'=>'⚠️ Cash Disc','wa_staff_reply'=>'💬 Reply',
];

function fq_event_label(string $event, array $labels): string {
    if (empty($event)) return '<span style="color:#999;">—</span>';
    return $labels[$event] ?? '<code style="font-size:11px;">' . htmlspecialchars($event) . '</code>';
}
function fq_ago(string $dateStr): string {
    $ts = strtotime($dateStr); if (!$ts) return $dateStr;
    $diff = time() - $ts; if ($diff < 0) return 'just now';
    if ($diff < 60) return $diff . 's ago'; if ($diff < 3600) return (int)($diff/60) . 'm ago';
    if ($diff < 86400) return (int)($diff/3600) . 'h ago'; return (int)($diff/86400) . 'd ago';
}
function fq_classify_webhook(array $entry): string {
    $msg = strtolower($entry['message'] ?? ''); $ev = strtolower($entry['event'] ?? '');
    if (str_contains($msg,'failed') || str_contains($msg,'error') || str_contains($ev,'error')
        || str_contains($ev,'failed') || str_contains($msg,'unauthorized') || str_contains($msg,'invalid')) return 'failed';
    if (str_contains($msg,'skipped') || str_contains($msg,'skip') || str_contains($msg,'no phone')
        || str_contains($msg,'already sent') || str_contains($msg,'already notified')
        || str_contains($msg,'concurrent lock') || str_contains($msg,'no notification')) return 'skipped';
    if (str_contains($msg,'sent ') || str_contains($msg,'sent to') || str_contains($msg,'notification →')
        || str_contains($msg,'welcome sent') || str_contains($msg,'thanks sent')
        || str_contains($msg,'auto-paid') || str_contains($msg,'normal invoice')
        || str_contains($msg,'partial credit') || str_contains($msg,'restoration notice')
        || str_contains($msg,'churn recovery') || str_contains($msg,'suspension whatsapp')
        || str_contains($msg,'pdf send: ok') || str_contains($msg,'activated notification')) return 'sent';
    return 'info';
}
?>
<style>
.fq-subtabs{display:flex;gap:0;margin-bottom:16px;border-bottom:2px solid #334155}
.fq-subtab{padding:10px 20px;font-size:13px;font-weight:600;color:#94a3b8;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s}
.fq-subtab:hover{color:#e2e8f0;background:#1e293b44}.fq-subtab.active{color:#3b82f6;border-bottom-color:#3b82f6}
.fq-subtab .fq-badge{display:inline-block;background:#ef4444;color:#fff;font-size:10px;font-weight:800;padding:1px 6px;border-radius:8px;margin-left:4px}
.fq-stats{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.fq-stat{background:#1e293b;border:1px solid #334155;border-radius:8px;padding:12px 18px;min-width:100px;text-align:center}
.fq-stat-num{font-size:24px;font-weight:700}.fq-stat-label{font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px}
.fq-stat-failed .fq-stat-num{color:#ef4444}.fq-stat-sent .fq-stat-num{color:#22c55e}
.fq-stat-dismissed .fq-stat-num{color:#94a3b8}.fq-stat-skipped .fq-stat-num{color:#f59e0b}
.fq-stat-info .fq-stat-num{color:#60a5fa}
.fq-filters{display:flex;gap:8px;margin-bottom:16px;align-items:center;flex-wrap:wrap}
.fq-filter-btn{padding:6px 14px;border-radius:6px;border:1px solid #334155;background:#1e293b;color:#e2e8f0;cursor:pointer;font-size:13px;text-decoration:none}
.fq-filter-btn:hover{background:#334155;color:#fff}.fq-filter-btn.active{background:#3b82f6;border-color:#3b82f6;color:#fff}
.fq-actions{display:flex;gap:8px;margin-left:auto;flex-wrap:wrap}
.fq-btn{padding:6px 14px;border-radius:6px;border:none;cursor:pointer;font-size:13px;font-weight:600}
.fq-btn-retry{background:#3b82f6;color:#fff}.fq-btn-retry:hover{background:#2563eb}
.fq-btn-dismiss{background:#64748b;color:#fff}.fq-btn-dismiss:hover{background:#475569}
.fq-btn-sm{padding:4px 10px;font-size:11px}
.fq-table{width:100%;border-collapse:collapse;font-size:13px}
.fq-table th{background:#1e293b;color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.5px;padding:8px 10px;text-align:left;border-bottom:1px solid #334155;position:sticky;top:0;z-index:1}
.fq-table td{padding:8px 10px;border-bottom:1px solid #1e293b;vertical-align:top}
.fq-table tr:hover td{background:#1e293b44}
.fq-msg-preview{max-width:350px;white-space:pre-wrap;word-break:break-word;font-size:12px;color:#cbd5e1;max-height:60px;overflow:hidden;transition:max-height .3s;cursor:pointer}
.fq-msg-preview.expanded{max-height:none}
.fq-msg-preview::after{content:'▼ expand';display:block;font-size:10px;color:#64748b;margin-top:2px}
.fq-msg-preview.expanded::after{content:'▲ collapse'}
.fq-phone{font-family:monospace;font-size:12px;color:#60a5fa;white-space:nowrap}
.fq-error{font-size:11px;color:#f87171;max-width:200px;word-break:break-word}
.fq-time{font-size:11px;color:#64748b;white-space:nowrap}
.fq-sender{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase}
.fq-sender-support{background:#25D36622;color:#25D366}.fq-sender-accounts{background:#3b82f622;color:#60a5fa}
.fq-status{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase}
.fq-status-failed{background:#ef444422;color:#ef4444}.fq-status-sent{background:#22c55e22;color:#22c55e}
.fq-status-dismissed{background:#64748b22;color:#94a3b8}.fq-status-retrying{background:#f59e0b22;color:#f59e0b}
.fq-status-skipped{background:#f59e0b22;color:#f59e0b}.fq-status-info{background:#60a5fa22;color:#60a5fa}
.fq-pager{display:flex;gap:6px;justify-content:center;margin-top:16px;align-items:center}
.fq-pager a{padding:4px 10px;background:#1e293b;border:1px solid #334155;border-radius:4px;color:#e2e8f0;text-decoration:none;font-size:12px}
.fq-pager a:hover{background:#334155}.fq-pager span{font-size:12px;color:#64748b}
.fq-empty{text-align:center;padding:40px;color:#64748b}.fq-empty-icon{font-size:48px;margin-bottom:8px}
</style>

<!-- SUB-TAB BAR -->
<div class="fq-subtabs">
    <a href="?page=dashboard&tab=engage_failed_queue&fqsub=queue" class="fq-subtab <?= $fqSub==='queue'?'active':'' ?>">
        🔄 Failed Queue<?php if($stats['failed']>0):?><span class="fq-badge"><?=$stats['failed']?></span><?php endif;?>
    </a>
    <a href="?page=dashboard&tab=engage_failed_queue&fqsub=crm_events" class="fq-subtab <?= $fqSub==='crm_events'?'active':'' ?>">
        📡 CRM Events
    </a>
</div>

<?php if ($fqSub === 'queue'): ?>
<!-- ═══════════════════ FAILED QUEUE ═══════════════════ -->
<?php
    $filterStatus = $_GET['status'] ?? 'failed';
    $pg = max(1,(int)($_GET['pg']??1)); $lim = 25; $off = ($pg-1)*$lim;
    $queue = $notify->getQueue($filterStatus, $lim, $off);
    $items = $queue['items']??[]; $total = $queue['total']??0; $totPg = max(1,(int)ceil($total/$lim));
?>
<div class="fq-stats">
    <div class="fq-stat fq-stat-failed"><div class="fq-stat-num"><?=$stats['failed']?></div><div class="fq-stat-label">Failed</div></div>
    <div class="fq-stat fq-stat-sent"><div class="fq-stat-num"><?=$stats['sent']?></div><div class="fq-stat-label">Retried ✓</div></div>
    <div class="fq-stat fq-stat-dismissed"><div class="fq-stat-num"><?=$stats['dismissed']?></div><div class="fq-stat-label">Dismissed</div></div>
</div>
<div class="fq-filters">
    <?php foreach(['failed'=>'❌ Failed','sent'=>'✅ Retried','dismissed'=>'🗑 Dismissed','all'=>'All'] as $fk=>$fl): ?>
    <a href="?page=dashboard&tab=engage_failed_queue&fqsub=queue&status=<?=$fk?>" class="fq-filter-btn <?=$filterStatus===$fk?'active':''?>"><?=$fl?> (<?=$fk==='all'?array_sum($stats):($stats[$fk]??0)?>)</a>
    <?php endforeach; ?>
    <?php if($filterStatus==='failed' && $stats['failed']>0):?>
    <div class="fq-actions">
        <form method="post" style="display:inline;" onsubmit="return confirm('Retry all <?=$stats['failed']?> failed?')"><input type="hidden" name="fq_action" value="retry_bulk"><input type="hidden" name="max_batch" value="50"><button type="submit" class="fq-btn fq-btn-retry">🔄 Retry All</button></form>
        <form method="post" style="display:inline;" onsubmit="return confirm('Dismiss all?')"><input type="hidden" name="fq_action" value="dismiss_all"><button type="submit" class="fq-btn fq-btn-dismiss">🗑 Dismiss All</button></form>
    </div>
    <?php endif; ?>
    <?php if($stats['sent']+$stats['dismissed']>0):?>
    <div class="fq-actions"><form method="post" style="display:inline;" onsubmit="return confirm('Purge old records?')"><input type="hidden" name="fq_action" value="purge_old"><input type="hidden" name="purge_days" value="30"><button type="submit" class="fq-btn fq-btn-dismiss fq-btn-sm">🧹 Purge</button></form></div>
    <?php endif; ?>
</div>

<?php if(empty($items)):?>
<div class="fq-empty"><div class="fq-empty-icon"><?=$filterStatus==='failed'?'✅':'📭'?></div>
<div style="font-size:16px;font-weight:600;"><?=$filterStatus==='failed'?'No failed notifications!':'Nothing here'?></div>
<div style="font-size:13px;"><?=$filterStatus==='failed'?'All WhatsApp messages are delivering successfully.':'No items match this filter.'?></div></div>
<?php else: ?>
<div style="overflow-x:auto;border:1px solid #334155;border-radius:8px;">
<table class="fq-table"><thead><tr><th>#</th><th>Status</th><th>Phone</th><th>Sender</th><th>Event</th><th>Message</th><th>Error</th><th>Tries</th><th>When</th><th>Actions</th></tr></thead><tbody>
<?php foreach($items as $it): ?>
<tr>
<td style="color:#64748b;"><?=(int)$it['id']?></td>
<td><span class="fq-status fq-status-<?=$it['status']??'failed'?>"><?=htmlspecialchars($it['status']??'failed')?></span></td>
<td class="fq-phone"><?=htmlspecialchars($it['phone']??'')?></td>
<td><span class="fq-sender fq-sender-<?=$it['sender']??'support'?>"><?=htmlspecialchars($it['sender']??'support')?></span></td>
<td><?=fq_event_label($it['event']??'',$eventLabels)?></td>
<td><div class="fq-msg-preview" onclick="this.classList.toggle('expanded')"><?=htmlspecialchars($it['message']??'')?></div></td>
<td class="fq-error"><?=htmlspecialchars($it['error']??'')?><?php if(($it['http_code']??0)>0):?> <span style="color:#64748b;">(HTTP <?=(int)$it['http_code']?>)</span><?php endif;?></td>
<td style="text-align:center;"><?=(int)($it['attempts']??1)?></td>
<td class="fq-time"><?=fq_ago($it['created_at']??'')?><br><span style="font-size:10px;"><?=htmlspecialchars(substr($it['created_at']??'',0,16))?></span>
<?php if($it['status']==='sent'&&!empty($it['retry_at'])):?><br><span style="color:#22c55e;">✓ <?=fq_ago($it['retry_at'])?></span><?php if(!empty($it['retry_by'])):?><br><span style="font-size:10px;">by <?=htmlspecialchars($it['retry_by'])?></span><?php endif; endif;?></td>
<td><?php if($it['status']==='failed'):?>
<form method="post" style="display:inline;"><input type="hidden" name="fq_action" value="retry_one"><input type="hidden" name="queue_id" value="<?=(int)$it['id']?>"><button type="submit" class="fq-btn fq-btn-retry fq-btn-sm" title="Retry">🔄</button></form>
<form method="post" style="display:inline;"><input type="hidden" name="fq_action" value="dismiss_one"><input type="hidden" name="queue_id" value="<?=(int)$it['id']?>"><button type="submit" class="fq-btn fq-btn-dismiss fq-btn-sm" title="Dismiss">🗑</button></form>
<?php elseif($it['status']==='sent'):?><span style="color:#22c55e;font-size:12px;">✅</span><?php else:?><span style="color:#64748b;font-size:12px;">—</span><?php endif;?></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php if($totPg>1):?><div class="fq-pager">
<?php if($pg>1):?><a href="?page=dashboard&tab=engage_failed_queue&fqsub=queue&status=<?=$filterStatus?>&pg=<?=$pg-1?>">← Prev</a><?php endif;?>
<span>Page <?=$pg?>/<?=$totPg?> (<?=$total?>)</span>
<?php if($pg<$totPg):?><a href="?page=dashboard&tab=engage_failed_queue&fqsub=queue&status=<?=$filterStatus?>&pg=<?=$pg+1?>">Next →</a><?php endif;?>
</div><?php endif; endif;?>


<?php elseif ($fqSub === 'crm_events'): ?>
<!-- ═══════════════════ CRM EVENTS ═══════════════════ -->
<?php
    $whLogFile = $dataDir . '/webhook_log.json';
    $whLog = []; if (file_exists($whLogFile)) { $r = json_decode(file_get_contents($whLogFile), true); $whLog = is_array($r) ? $r : []; }

    $ceFilter = $_GET['ce_filter'] ?? 'all';
    $ceSearch = trim($_GET['ce_search'] ?? '');

    $classified = []; $sc = ['sent'=>0,'skipped'=>0,'failed'=>0,'info'=>0];
    foreach ($whLog as $e) {
        $e['_ws'] = fq_classify_webhook($e); $sc[$e['_ws']]++;
        if ($ceFilter !== 'all' && $e['_ws'] !== $ceFilter) continue;
        if ($ceSearch !== '' && strpos(strtolower(($e['event']??'').' '.($e['message']??'').' '.json_encode($e['data']??[])), strtolower($ceSearch)) === false) continue;
        $classified[] = $e;
    }
    $cePg = max(1,(int)($_GET['cepg']??1)); $ceLim = 30; $ceTot = count($classified);
    $ceTotPg = max(1,(int)ceil($ceTot/$ceLim)); $ceItems = array_slice($classified, ($cePg-1)*$ceLim, $ceLim);
?>

<div class="fq-stats">
    <div class="fq-stat fq-stat-sent"><div class="fq-stat-num"><?=$sc['sent']?></div><div class="fq-stat-label">Delivered</div></div>
    <div class="fq-stat fq-stat-skipped"><div class="fq-stat-num"><?=$sc['skipped']?></div><div class="fq-stat-label">Skipped</div></div>
    <div class="fq-stat fq-stat-failed"><div class="fq-stat-num"><?=$sc['failed']?></div><div class="fq-stat-label">Failed</div></div>
    <div class="fq-stat fq-stat-info"><div class="fq-stat-num"><?=$sc['info']?></div><div class="fq-stat-label">Info</div></div>
</div>

<p style="font-size:12px;color:#64748b;margin-bottom:12px;">Last <?=count($whLog)?> CRM webhook events — shows what UCRM/UISP sent and whether the WhatsApp notification was delivered, skipped (no phone / already sent / dedup), or failed.</p>

<div class="fq-filters">
    <?php foreach(['all'=>'All ('.count($whLog).')','sent'=>'✅ Delivered ('.$sc['sent'].')','skipped'=>'⚠️ Skipped ('.$sc['skipped'].')','failed'=>'❌ Failed ('.$sc['failed'].')'] as $fk=>$fl): ?>
    <a href="?page=dashboard&tab=engage_failed_queue&fqsub=crm_events&ce_filter=<?=$fk?><?=$ceSearch?'&ce_search='.urlencode($ceSearch):''?>" class="fq-filter-btn <?=$ceFilter===$fk?'active':''?>"><?=$fl?></a>
    <?php endforeach; ?>
    <form method="get" style="display:flex;gap:4px;margin-left:auto;">
        <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="engage_failed_queue">
        <input type="hidden" name="fqsub" value="crm_events"><input type="hidden" name="ce_filter" value="<?=htmlspecialchars($ceFilter)?>">
        <input type="text" name="ce_search" value="<?=htmlspecialchars($ceSearch)?>" placeholder="Search…" style="padding:6px 10px;border:1px solid #334155;border-radius:6px;background:#1e293b;color:#e2e8f0;font-size:13px;width:180px;">
        <button type="submit" class="fq-filter-btn" style="border:1px solid #3b82f6;">🔍</button>
        <?php if($ceSearch):?><a href="?page=dashboard&tab=engage_failed_queue&fqsub=crm_events&ce_filter=<?=$ceFilter?>" class="fq-filter-btn" style="color:#f87171;">✕</a><?php endif;?>
    </form>
</div>

<?php if(empty($ceItems)):?>
<div class="fq-empty"><div class="fq-empty-icon">📭</div><div style="font-size:16px;font-weight:600;">No events<?=$ceFilter!=='all'?' matching this filter':''?></div></div>
<?php else:?>
<div style="overflow-x:auto;border:1px solid #334155;border-radius:8px;">
<table class="fq-table"><thead><tr><th>WA Status</th><th>CRM Event</th><th>Details</th><th>Data</th><th>When</th></tr></thead><tbody>
<?php foreach($ceItems as $e):
    $ws = $e['_ws'];
    $wsL = ['sent'=>'✅ Delivered','skipped'=>'⚠️ Skipped','failed'=>'❌ Failed','info'=>'ℹ️ Info'][$ws] ?? $ws;
?>
<tr>
<td><span class="fq-status fq-status-<?=$ws?>"><?=$wsL?></span></td>
<td><code style="font-size:12px;color:#e2e8f0;background:#0f172a;padding:2px 6px;border-radius:4px;"><?=htmlspecialchars($e['event']??'—')?></code></td>
<td style="max-width:400px;"><div style="font-size:12px;color:#cbd5e1;word-break:break-word;"><?=htmlspecialchars($e['message']??'')?></div></td>
<td><?php $d=$e['data']??[]; if(!empty($d)):
    $hl=[];
    foreach(['client_id','crm_id','phone','amount','invoice','invoiceNum','entity_id','service_name'] as $k){
        if(isset($d[$k])&&$d[$k]!==''&&$d[$k]!==null) $hl[]='<span style="color:#94a3b8;">'.htmlspecialchars($k).':</span> '.htmlspecialchars((string)$d[$k]);
    }
    if(!empty($hl)):?>
    <div style="font-size:11px;line-height:1.5;"><?=implode('<br>',$hl)?></div>
    <?php else:?>
    <div style="font-size:10px;color:#64748b;cursor:pointer;" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">📋 data</div>
    <div style="display:none;font-size:10px;color:#94a3b8;max-width:200px;word-break:break-word;white-space:pre-wrap;"><?=htmlspecialchars(json_encode($d,JSON_PRETTY_PRINT))?></div>
    <?php endif;endif;?>
</td>
<td class="fq-time"><?=fq_ago($e['received_at']??'')?><br><span style="font-size:10px;"><?=htmlspecialchars(substr($e['received_at']??'',0,16))?></span></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php if($ceTotPg>1):?><div class="fq-pager">
<?php if($cePg>1):?><a href="?page=dashboard&tab=engage_failed_queue&fqsub=crm_events&ce_filter=<?=$ceFilter?>&ce_search=<?=urlencode($ceSearch)?>&cepg=<?=$cePg-1?>">← Prev</a><?php endif;?>
<span>Page <?=$cePg?>/<?=$ceTotPg?> (<?=$ceTot?>)</span>
<?php if($cePg<$ceTotPg):?><a href="?page=dashboard&tab=engage_failed_queue&fqsub=crm_events&ce_filter=<?=$ceFilter?>&ce_search=<?=urlencode($ceSearch)?>&cepg=<?=$cePg+1?>">Next →</a><?php endif;?>
</div><?php endif; endif;?>

<?php endif; ?>
<script>
// Inject CSRF token into all fq_ forms so they pass the global POST gate
(function(){
  var tok = <?= json_encode(csrfToken()) ?>;
  document.querySelectorAll('form[method="post"]').forEach(function(f){
    if(!f.querySelector('[name="_csrf"]')){
      var h=document.createElement('input');
      h.type='hidden'; h.name='_csrf'; h.value=tok;
      f.appendChild(h);
    }
  });
})();
</script>
