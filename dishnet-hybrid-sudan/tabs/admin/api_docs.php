<?php
// Tab: api_docs
// Extracted from public.php on 2026-03-15
    $scheme     = ((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||(!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])&&$_SERVER['HTTP_X_FORWARDED_PROTO']==='https')) ? 'https' : 'http';
    $basePlugin = $scheme.'://'.$_SERVER['HTTP_HOST'].strtok($_SERVER['REQUEST_URI'],'?');
    $baseApi    = $basePlugin.'?page=api&action=';
    $basePwa    = $scheme.'://'.$_SERVER['HTTP_HOST'].preg_replace('#/public\.php$#','',strtok($_SERVER['REQUEST_URI'],'?')).'/public.php?page=api&action=';
    $docsUrl    = $basePlugin.'?page=api_docs';
    $allRetailersFull = $store->load('retailers.json') ?? [];
?>
<style>
.apidoc-hero{background:linear-gradient(135deg,#0f172a,#1e293b);border-radius:14px;padding:24px 28px;margin-bottom:20px;border:1px solid #334155;}
.apidoc-hero h2{color:#f1f5f9;font-size:20px;font-weight:800;margin:0 0 4px;}
.apidoc-hero p{color:#94a3b8;font-size:13px;margin:0;}
.apidoc-url-row{display:flex;align-items:center;gap:10px;background:#0f172a;border:1px solid #334155;border-radius:8px;padding:10px 14px;margin-top:12px;flex-wrap:wrap;}
.apidoc-url-label{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap;}
.apidoc-url-val{font-family:monospace;font-size:12px;color:#2dd4bf;word-break:break-all;flex:1;}
.apidoc-copy{background:#1e293b;border:1px solid #475569;color:#94a3b8;padding:3px 10px;border-radius:5px;font-size:11px;cursor:pointer;white-space:nowrap;}
.apidoc-copy:hover{background:#334155;color:#f1f5f9;}
.apidoc-section{margin-bottom:24px;}
.apidoc-section-title{font-size:13px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.08em;padding:10px 0 8px;border-bottom:1px solid #e2e8f0;margin-bottom:12px;display:flex;align-items:center;gap:8px;}
.apidoc-ep-table{width:100%;border-collapse:collapse;}
.apidoc-ep-table th{background:#f8fafc;text-align:left;padding:7px 12px;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;}
.apidoc-ep-table td{padding:8px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:13px;}
.apidoc-ep-table tr:last-child td{border-bottom:none;}
.apidoc-ep-table tr:hover td{background:#f8fafc;}
.apidoc-action{font-family:monospace;font-size:12px;color:#0369a1;background:#e0f2fe;padding:2px 8px;border-radius:4px;}
.apidoc-badge-get{background:#dbeafe;color:#1d4ed8;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;}
.apidoc-badge-post{background:#dcfce7;color:#15803d;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;}
.apidoc-auth-bearer{background:#fef9c3;color:#854d0e;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:600;}
.apidoc-auth-admin{background:#fee2e2;color:#b91c1c;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:600;}
.apidoc-auth-public{background:#dcfce7;color:#15803d;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:600;}
.apidoc-tag-mobile{background:#ede9fe;color:#6d28d9;padding:2px 6px;border-radius:4px;font-size:10px;}
.apidoc-wa{background:#d1fae5;color:#065f46;padding:2px 6px;border-radius:4px;font-size:10px;}
.retailer-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;margin-top:4px;}
.retailer-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;}
.retailer-card-name{font-size:15px;font-weight:800;color:#0f172a;margin-bottom:2px;}
.retailer-card-email{font-size:12px;color:#64748b;margin-bottom:10px;}
.retailer-card-row{display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-bottom:6px;}
.retailer-card-label{color:#94a3b8;}
.retailer-card-val{font-weight:600;color:#0f172a;font-family:monospace;font-size:11px;}
.retailer-card-token{font-family:monospace;font-size:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;word-break:break-all;color:#475569;margin:8px 0;}
.retailer-card-actions{display:flex;gap:8px;margin-top:10px;}
.btn-impersonate{background:linear-gradient(135deg,#6d28d9,#4f46e5);color:#fff;border:none;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;flex:1;}
.btn-impersonate:hover{opacity:.9;}
.btn-copy-token{background:#f1f5f9;border:1px solid #e2e8f0;color:#475569;padding:7px 12px;border-radius:8px;font-size:12px;cursor:pointer;}
.btn-copy-token:hover{background:#e2e8f0;}
.retailer-badge{display:inline-block;padding:2px 7px;border-radius:20px;font-size:10px;font-weight:700;margin-left:6px;}
.badge-admin{background:#fee2e2;color:#b91c1c;}
.badge-agent{background:#dbeafe;color:#1d4ed8;}
.badge-inactive{background:#f1f5f9;color:#94a3b8;}
.fix-note{background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:12px 16px;font-size:13px;color:#92400e;margin-bottom:20px;}
</style>
<div class="fix-note">
    <strong>🔒 Login Security (v3.6.1):</strong> Each login now rotates the API token — so if "demo" was logged in before and another retailer logs in, the old demo token is automatically invalidated. No more session bleed-through between accounts.
</div>
<div class="apidoc-hero">
    <h2>📡 DishNet Hybrid API Reference <span style="font-size:13px;font-weight:500;color:#64748b;margin-left:8px;"><?php echo $GLOBALS['_PLUGIN_VER']; ?></span></h2>
    <p>Complete endpoint reference for the mobile app, retailer PWA, and external integrations.</p>
    <div class="apidoc-url-row">
        <span class="apidoc-url-label">Public.php API</span>
        <span class="apidoc-url-val" id="adUrl1"><?= h($baseApi) ?></span>
        <button class="apidoc-copy" onclick="adCopy('adUrl1',this)">Copy</button>
        <a href="<?= h($docsUrl) ?>" target="_blank" style="background:#1e3a5f;color:#7dd3fc;border:1px solid #1e40af;padding:3px 10px;border-radius:5px;font-size:11px;text-decoration:none;">Full Docs ↗</a>
    </div>
    <div class="apidoc-url-row" style="margin-top:6px;">
        <span class="apidoc-url-label">Retailer PWA API</span>
        <span class="apidoc-url-val" id="adUrl2"><?= h($basePwa) ?></span>
        <button class="apidoc-copy" onclick="adCopy('adUrl2',this)">Copy</button>
    </div>
</div>
<?php
$epGroups = [
    ['🔑 Auth', [
        ['POST','login','Get API token (rotates on every login — invalidates prior session)','public','mobile'],
        ['GET', 'me','My profile + wallet summary','bearer','mobile'],
        ['POST','logout','Revoke token immediately','bearer','mobile'],
    ]],
    ['📋 KYC', [
        ['POST','kyc_submit','Submit new customer KYC (debits wallet + queues CRM sync)','bearer','mobile','wa:ops_kyc_submitted'],
        ['GET', 'kyc_list','My submitted applications','bearer','mobile'],
        ['GET', 'kyc_view&id=N','Single application detail','bearer','mobile'],
        ['GET', 'customer_360&id=N','Full UCRM customer profile','bearer','admin'],
        ['GET', 'crm_search_customer&q=','Search UCRM clients by name/phone','bearer','admin'],
    ]],
    ['💰 Wallet & Recharge', [
        ['GET', 'wallet_balance','Current balance + summary','bearer','mobile'],
        ['GET', 'wallet_passbook','Transaction ledger (paginated)','bearer','mobile'],
        ['GET', 'wallet_transaction&trx_no=','Single transaction detail','bearer','mobile'],
        ['POST','wallet_topup','Admin — credit retailer wallet','admin','admin','wa:ops_wallet_topped_up'],
        ['POST','wallet_reverse','Admin — reverse a transaction','admin','admin'],
        ['GET', 'admin_wallets','All retailer wallet summaries','admin','admin'],
        ['POST','recharge_submit','Submit recharge request','bearer','mobile','wa:ops_recharge_submitted'],
        ['GET', 'recharge_list','My recharge history (pending/approved/rejected)','bearer','mobile'],
    ]],
    ['📡 SIM', [
        ['GET', 'sim_inventory','SIMs allocated to me','bearer','mobile'],
        ['GET', 'sim_stock','Stock count by status','bearer','mobile'],
        ['GET', 'sim_detail&id=N','Single SIM full detail','bearer','mobile'],
        ['POST','sim_activate','Activate SIM for customer (debits wallet)','bearer','mobile','wa:ops_sim_activated'],
        ['POST','sim_allocate','Admin — allocate SIM to agent','admin','admin'],
        ['POST','sim_return','Admin — return SIM to stock','admin','admin'],
        ['POST','sim_inbound','Admin — add new SIM to inventory','admin','admin'],
        ['POST','sim_status_change','Admin — change SIM status','admin','admin'],
    ]],
    ['💵 Field Agent — Cash', [
        ['POST','agent_collection_log','Log physical cash collected from customer','bearer','mobile'],
        ['GET', 'agent_collections','My collection history','bearer','mobile'],
        ['GET', 'agent_balance','Cash-in-hand balance (collections minus remittances)','bearer','mobile'],
        ['POST','agent_remittance_submit','Submit end-of-day cash handover','bearer','mobile','wa:ops_handover_submitted'],
        ['GET', 'agent_remittances','My handover history','bearer','mobile'],
        ['POST','agent_remittance_approve','Admin — approve handover','admin','admin','wa:ops_handover_approved'],
        ['POST','agent_remittance_reject','Admin — reject handover with reason','admin','admin'],
        ['GET', 'agent_list','All field agents (admin view)','admin','admin'],
    ]],
    ['📶 LTE (Magma)', [
        ['GET', 'lte_subscribers','All LTE subscribers','admin','admin'],
        ['GET', 'lte_subscriber&id=N','Single subscriber + SIM + usage','admin','admin'],
        ['POST','lte_create_subscriber','Create new LTE subscriber','admin','admin'],
        ['POST','lte_renew','Renew subscription + package','admin','admin'],
        ['POST','lte_suspend','Manually suspend (sets INACTIVE on Magma)','admin','admin'],
        ['POST','lte_reactivate','Reactivate (sets ACTIVE on Magma)','admin','admin'],
        ['GET', 'lte_packages','Available data packages','bearer','mobile'],
        ['GET', 'lte_stats','Subscriber counts by status','admin','admin'],
        ['GET', 'lte_network_health','Magma Orc8r network health (cached 5min)','admin','admin'],
        ['GET', 'lte_360&id=N','Full subscriber 360 with live Magma state','admin','admin'],
    ]],
    ['📅 Scheduling & Jobs', [
        ['GET', 'scheduling_jobs','My assigned UCRM jobs (requires ucrm_user_id set)','bearer','mobile'],
        ['GET', 'scheduling_job_detail&job_id=N','Job + client + tasks (only own jobs)','bearer','mobile'],
        ['POST','scheduling_job_update','Update job status / add note','bearer','mobile'],
        ['POST','scheduling_task_update','Tick task done + WhatsApp next-task notification','bearer','mobile'],
        ['POST','scheduling_complete','Complete job: photos + GPS + comment → UCRM + WhatsApp','bearer','mobile'],
        ['POST','scheduling_reschedule','Reschedule job to new date + CRM comment + WhatsApp','bearer','mobile'],
        ['POST','scheduling_add_comment','Add comment to UCRM job','bearer','mobile'],
        ['POST','save_job_signature','Save customer signature (base64 PNG)','bearer','mobile'],
        ['POST','save_survey_result','Post-installation survey answers','bearer','mobile'],
    ]],
    ['🧾 Billing', [
        ['GET', 'customer_invoices&id=N','Customer invoice history from UCRM','bearer','admin'],
        ['GET', 'customer_ledger&cid=N','Customer account ledger (Org-7)','bearer','admin'],
        ['POST','create_ticket','Raise UCRM support ticket','bearer','mobile'],
    ]],
    ['⚙️ System', [
        ['GET', 'health','Health check — no auth required','public',''],
        ['GET', 'unified_stats','Full dashboard stats summary','admin','admin'],
        ['GET', 'ucrm_sync_status','Last UCRM sync timestamps + counts','admin','admin'],
        ['POST','run_wallet_sync','Trigger Org-7 wallet sync now','admin','admin'],
        ['POST','lte_run_cron','Trigger LTE auto-suspend cron now','admin','admin'],
    ]],
];
foreach ($epGroups as [$groupTitle, $eps]):
?>
<div class="apidoc-section">
    <div class="apidoc-section-title"><?= $groupTitle ?> <span style="font-size:10px;color:#cbd5e1;font-weight:400;"><?= count($eps) ?> endpoints</span></div>
    <table class="apidoc-ep-table">
        <thead><tr><th>Method</th><th>Action</th><th>Description</th><th>Auth</th><th>Tag</th></tr></thead>
        <tbody>
        <?php foreach ($eps as $ep):
            [$method,$action,$desc,$auth] = $ep;
            $tag = $ep[4] ?? '';
            $wa  = $ep[5] ?? '';
        ?>
        <tr>
            <td><span class="<?= $method==='POST'?'apidoc-badge-post':'apidoc-badge-get' ?>"><?= $method ?></span></td>
            <td><code class="apidoc-action"><?= h($action) ?></code></td>
            <td><?= h($desc) ?><?php if($wa): ?> <span class="apidoc-wa">📲 <?= h(substr($wa,3)) ?></span><?php endif; ?></td>
            <td><?php
                if($auth==='public') echo '<span class="apidoc-auth-public">🌐 Public</span>';
                elseif($auth==='admin') echo '<span class="apidoc-auth-admin">🔴 Admin</span>';
                else echo '<span class="apidoc-auth-bearer">🔐 Bearer</span>';
            ?></td>
            <td><?php if($tag==='mobile') echo '<span class="apidoc-tag-mobile">📱 Mobile</span>'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>

<div class="apidoc-section" style="margin-top:32px;">
    <div class="apidoc-section-title">👤 Retailer Accounts — Login As</div>
    <p style="font-size:13px;color:#64748b;margin-bottom:16px;">Click <strong>Login As</strong> to view the plugin exactly as that retailer sees it. An orange banner will appear — click <em>Stop impersonating</em> to return to admin.</p>
    <div class="retailer-grid">
    <?php foreach ($allRetailersFull as $r):
        $rActive = !empty($r['is_active']);
        $rAdmin  = !empty($r['is_admin']);
        $rAgent  = !empty($r['is_field_agent']);
        $rToken  = $r['api_token'] ?? '—';
    ?>
    <div class="retailer-card" style="<?= !$rActive?'opacity:.55;':'' ?>">
        <div>
            <span class="retailer-card-name"><?= h($r['name']) ?></span>
            <?php if($rAdmin): ?><span class="retailer-badge badge-admin">Admin</span><?php endif; ?>
            <?php if($rAgent): ?><span class="retailer-badge badge-agent">Field Agent</span><?php endif; ?>
            <?php if(!$rActive): ?><span class="retailer-badge badge-inactive">Inactive</span><?php endif; ?>
        </div>
        <div class="retailer-card-email"><?= h($r['email']) ?></div>
        <div class="retailer-card-row"><span class="retailer-card-label">Wallet</span><span class="retailer-card-val">$<?= number_format($r['wallet']??0,2) ?></span></div>
        <div class="retailer-card-row"><span class="retailer-card-label">Role</span><span class="retailer-card-val"><?= h($r['role']??'sales') ?></span></div>
        <div class="retailer-card-row"><span class="retailer-card-label">API Token</span></div>
        <div class="retailer-card-token" id="rt_<?= $r['id'] ?>"><?= h($rToken) ?></div>
        <div class="retailer-card-actions">
            <?php if($rActive && !$rAdmin): ?>
            <form method="POST" action="?page=dashboard" style="flex:1;margin:0;">
                <input type="hidden" name="action" value="impersonate_retailer">
                <input type="hidden" name="retailer_id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="btn-impersonate">👁 Login As <?= h(explode(' ',$r['name'])[0]) ?></button>
            </form>
            <?php else: ?>
            <div style="flex:1;font-size:11px;color:#94a3b8;padding:7px 0;"><?= $rAdmin?'(Admin — no impersonation)':'(Inactive)' ?></div>
            <?php endif; ?>
            <button class="btn-copy-token" onclick="adCopyText(this.dataset.t,this)" data-t="<?= h(htmlspecialchars($rToken,ENT_QUOTES)) ?>" title="Copy token">📋</button>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<script>
function adCopy(id,btn){navigator.clipboard.writeText(document.getElementById(id).textContent.trim()).then(()=>{var o=btn.textContent;btn.textContent='✓ Copied';btn.style.color='#16a34a';setTimeout(()=>{btn.textContent=o;btn.style.color='';},2000);});}
function adCopyText(text,btn){navigator.clipboard.writeText(text).then(()=>{var o=btn.textContent;btn.textContent='✓';setTimeout(()=>{btn.textContent=o;},2000);});}
</script>
<!-- ════════════════════════════════════════════════════════════════════════
     ADMIN TAB: SETTINGS
     ════════════════════════════════════════════════════════════════════ -->
