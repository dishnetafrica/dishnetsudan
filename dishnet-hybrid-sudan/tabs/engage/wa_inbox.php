<?php
// Tab: wa_inbox — WhatsApp Unified Inbox
// Uses ConversationService (SQLite) — replaces old WaBotService JSON inbox
require_once $GLOBALS['_PLUGIN_ROOT'] . '/lib/ConversationService.php';
$_cs = new ConversationService($dataDir, $store->getPdo());
$_stats = []; $_totalUnread = 0; $_unreadSupport = 0; $_unreadAccounts = 0; $_needsHuman = 0;
try {
    $_stats = $_cs->getStats();
    $_totalUnread = (int)($_stats['total_unread'] ?? 0);
    $_unreadSupport = (int)($_stats['unread_support'] ?? 0);
    $_unreadAccounts = (int)($_stats['unread_accounts'] ?? 0);
    $_needsHuman = (int)($_stats['needs_human'] ?? 0);
    // Website chats are a channel in the same tables, so the count comes from
    // getStats the same way Support and Accounts do.
    $_unreadWeb  = (int)($_stats['unread_web'] ?? 0);
} catch (Throwable $_e) {
    // Tables may not exist yet — migration 017 not run. That's OK.
}
// Role-based default channel tab
$_defaultChannel = '';
$_userRole = $retailer['role'] ?? '';
if ($_userRole === 'support_leader' || $_userRole === 'support') $_defaultChannel = 'support';
elseif ($_userRole === 'accountant') $_defaultChannel = 'accounts';
// Admin sees all by default

$_syncFile = $dataDir . '/wa_sync_state.json';
$_syncState = file_exists($_syncFile) ? (json_decode(file_get_contents($_syncFile), true) ?: []) : [];
$_syncOk = !empty($_syncState['last_sync_at']) && (time() - strtotime($_syncState['last_sync_at'] ?? '2000-01-01')) < 300;
?>
<style>
.wai-wrap{display:flex;height:calc(100vh - 80px);background:#f0f2f5;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;}
.wai-left{width:360px;min-width:280px;max-width:420px;display:flex;flex-direction:column;background:#fff;border-right:1px solid #e2e8f0;}
.wai-right{flex:1;display:flex;flex-direction:column;background:#efeae2;position:relative;}
.wai-right.empty{align-items:center;justify-content:center;}
.wai-lhdr{padding:12px 14px;background:#fff;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;}
.wai-lhdr h3{margin:0;font-size:15px;font-weight:700;color:#1e293b;}
.wai-badge{background:#25D366;color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:10px;}
.wai-tabs{display:flex;padding:0 10px;border-bottom:1px solid #f1f5f9;background:#fafbfc;}
.wai-tab{padding:9px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;cursor:pointer;border-bottom:2px solid transparent;transition:.15s;}
.wai-tab:hover{color:#1e293b;}.wai-tab.active{color:#25D366;border-bottom-color:#25D366;}
.wai-search{padding:8px 10px;border-bottom:1px solid #f1f5f9;}
.wai-search input{width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;background:#f8fafc;box-sizing:border-box;}
.wai-search input:focus{border-color:#25D366;background:#fff;}
.wai-threads{flex:1;overflow-y:auto;}
.wai-thread{display:flex;padding:12px 14px;cursor:pointer;border-bottom:1px solid #f8fafc;transition:.1s;gap:10px;align-items:flex-start;}
.wai-thread:hover{background:#f0fdf4;}.wai-thread.sel{background:#f0fdf4;border-left:3px solid #25D366;}
.wai-thread .ava{width:42px;height:42px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#64748b;flex-shrink:0;}
.wai-thread .info{flex:1;min-width:0;}
.wai-thread .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;}
.wai-thread .nm{font-size:14px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.wai-thread .tm{font-size:10px;color:#94a3b8;white-space:nowrap;}
.wai-thread .prev{font-size:12px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;}
.wai-thread .unrd{background:#25D366;color:#fff;font-size:10px;font-weight:800;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 5px;}
.wai-thread .ch-pill{font-size:9px;font-weight:700;padding:1px 5px;border-radius:6px;text-transform:uppercase;flex-shrink:0;}
.wai-thread .ch-pill.support{background:#dbeafe;color:#1e40af;}
.wai-thread .ch-pill.accounts{background:#fef3c7;color:#92400e;}
.wai-empty{text-align:center;color:#94a3b8;}.wai-empty .ico{font-size:64px;margin-bottom:12px;}
.wai-empty h3{color:#64748b;margin:0 0 6px;}.wai-empty p{font-size:13px;}
.wai-chdr{padding:10px 16px;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:12px;}
.wai-chdr .ava{width:40px;height:40px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:#64748b;flex-shrink:0;}
.wai-chdr .info{flex:1;min-width:0;}.wai-chdr .nm{font-size:15px;font-weight:700;color:#1e293b;}.wai-chdr .sub{font-size:11px;color:#94a3b8;}
.wai-chdr .acts{display:flex;gap:6px;flex-wrap:wrap;}
.wai-chdr .acts button{padding:5px 10px;font-size:11px;font-weight:600;border:1px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;white-space:nowrap;}
.wai-chdr .acts button:hover{background:#f1f5f9;}
.wai-msgs{flex:1;overflow-y:auto;padding:12px 16px;display:flex;flex-direction:column;gap:3px;background:url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFElEQVQYV2P8+vXrfwY8gHHUKHwKAYCPH/8BhbKgOAAAAABJRU5ErkJggg==') repeat #efeae2;}
.wai-msg{max-width:75%;padding:8px 12px 6px;border-radius:8px;font-size:14.5px;line-height:1.55;word-wrap:break-word;position:relative;box-shadow:0 1px 1px rgba(0,0,0,.08);}
.wai-msg.in{background:#fff;align-self:flex-start;border-top-left-radius:0;margin-left:2px;}
.wai-msg.out{background:#d9fdd3;align-self:flex-end;border-top-right-radius:0;margin-right:2px;}
.wai-msg.sys{background:rgba(255,255,255,.85);align-self:center;font-size:12px;color:#54656f;border-radius:8px;max-width:85%;text-align:center;box-shadow:none;padding:6px 12px;}
.wai-msg .mt{font-size:11px;color:#667781;float:right;margin:4px 0 -2px 10px;position:relative;top:6px;}
.wai-msg .ag{font-size:11px;color:#027eb5;font-weight:600;margin-bottom:1px;}
.wai-msg .media-tag{font-size:12px;color:#6366f1;font-style:italic;}
.wai-date-sep{text-align:center;font-size:12px;color:#54656f;background:rgba(255,255,255,.9);padding:5px 14px;border-radius:8px;align-self:center;margin:10px 0;box-shadow:0 1px 1px rgba(0,0,0,.06);}
.wai-reply{padding:8px 12px;background:#f0f2f5;display:flex;gap:8px;align-items:flex-end;}
.wai-reply textarea{flex:1;border:none;border-radius:20px;padding:10px 14px;font-size:15px;resize:none;max-height:100px;outline:none;font-family:inherit;min-height:42px;box-sizing:border-box;background:#fff;box-shadow:0 1px 1px rgba(0,0,0,.06);}
.wai-reply .sendbtn{background:#00a884;color:#fff;border:none;border-radius:50%;width:42px;height:42px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.wai-reply .sendbtn:hover{background:#008c72;}.wai-reply .sendbtn:disabled{background:#94a3b8;cursor:not-allowed;}
.wai-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:999;display:none;align-items:center;justify-content:center;}
.wai-modal-bg.show{display:flex;}
.wai-modal{background:#fff;border-radius:12px;padding:20px;width:90%;max-width:420px;box-shadow:0 8px 30px rgba(0,0,0,.2);}
.wai-modal h4{margin:0 0 12px;font-size:15px;}
.wai-modal input{width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;margin-bottom:10px;box-sizing:border-box;}
.wai-modal .results{max-height:200px;overflow-y:auto;}
.wai-modal .res-item{padding:8px 10px;cursor:pointer;border-radius:6px;font-size:13px;}
.wai-modal .res-item:hover{background:#f0fdf4;}
.wai-sync{padding:6px 14px;font-size:10px;color:#94a3b8;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;}
.wai-sync .dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px;}
.wai-sync .dot.green{background:#22c55e;}.wai-sync .dot.red{background:#ef4444;}.wai-sync .dot.yellow{background:#eab308;}

/* ── Mobile ─────────────────────────────────────────── */
@media(max-width:700px){
    .wai-wrap{flex-direction:column;height:calc(100vh - 60px);position:relative;border-radius:0;border:none;}
    .wai-left{width:100%;max-width:100%;min-width:100%;border-right:none;flex:1;}
    .wai-right{display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;background:#efeae2;flex-direction:column;}
    .wai-right.mob-show{display:flex !important;}
    .wai-right.empty{display:none !important;}
    .wai-back-btn{display:block !important;font-size:22px;padding:4px 8px;}
    .wai-chdr{padding:8px 12px;background:#008069;color:#fff;}
    .wai-chdr .ava{background:rgba(255,255,255,.2);color:#fff;}
    .wai-chdr .nm{color:#fff;font-size:16px;}
    .wai-chdr .sub{color:rgba(255,255,255,.7);font-size:12px;}
    .wai-chdr .acts button{background:transparent;color:#fff;border-color:rgba(255,255,255,.3);font-size:10px;padding:3px 6px;}
    .wai-msg{max-width:88%;font-size:15px;padding:8px 12px 6px;line-height:1.55;}
    .wai-msgs{padding:8px 10px;}
    .wai-reply{padding:6px 8px;}
    .wai-reply textarea{font-size:16px;padding:10px 14px;min-height:42px;border-radius:20px;}
    .wai-reply .sendbtn{width:44px;height:44px;}
}
@media(min-width:701px){
    .wai-back-btn{display:none !important;}
}
</style>

<?php
// ── Failed notification banner ────────────────────────────────────────────
// Count failed messages in the notification queue so Bidal sees them inline
$_nqFailedCount = 0;
try {
    $_nqFailedCount = (int)$store->getPdo()
        ->query("SELECT COUNT(*) FROM notification_queue WHERE status = 'failed'")
        ->fetchColumn();
} catch (Throwable $_nqe) {}

// Handle inline retry POST from the banner
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_wai_action'] ?? '') === 'retry_failed') {
    try {
        $result = svc('notify')->retryBulk($retailer['name'] ?? 'Staff', 50);
        $sent   = (int)($result['sent']   ?? 0);
        $still  = (int)($result['failed'] ?? 0);
        echo '<div style="background:' . ($still > 0 ? '#451a03' : '#052e16') . ';color:' . ($still > 0 ? '#fed7aa' : '#86efac') . ';padding:10px 16px;border-radius:8px;margin-bottom:8px;font-size:13px;font-weight:600;">';
        if ($still > 0) {
            echo '⚠️ ' . $sent . ' message(s) resent, but ' . $still . ' still failing. Check that the <strong>WA Auth Key</strong> is correct in Settings.';
        } else {
            echo '✅ All failed messages resent successfully!';
        }
        echo '</div>';
        $_nqFailedCount = $still; // update count for banner below
    } catch (Throwable $_re) {
        echo '<div style="background:#450a0a;color:#fca5a5;padding:10px 16px;border-radius:8px;margin-bottom:8px;font-size:13px;">❌ Retry error: ' . htmlspecialchars($_re->getMessage()) . '</div>';
    }
}
?>

<?php if ($_nqFailedCount > 0): ?>
<form method="post" style="margin-bottom:8px;" onsubmit="this.querySelector('button').textContent='Retrying…';this.querySelector('button').disabled=true;">
    <input type="hidden" name="_wai_action" value="retry_failed">
    <?= csrfField() ?>
    <div style="background:#431407;border:1px solid #9a3412;border-radius:10px;padding:11px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span style="font-size:18px;flex-shrink:0;">⚠️</span>
        <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:700;color:#fed7aa;"><?= $_nqFailedCount ?> message<?= $_nqFailedCount > 1 ? 's' : '' ?> failed to send</div>
            <div style="font-size:12px;color:#c2410c;margin-top:2px;">Usually caused by an expired WA Auth Key — update it in Settings, then retry</div>
        </div>
        <button type="submit" style="flex-shrink:0;background:#ea580c;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">🔄 Retry Now</button>
        <a href="?page=dashboard&tab=engage_failed_queue" style="flex-shrink:0;font-size:12px;color:#fb923c;text-decoration:none;white-space:nowrap;">View details →</a>
    </div>
</form>
<?php endif; ?>

<div class="wai-wrap">
    <div class="wai-left">
        <div class="wai-lhdr">
            <h3>&#x1F4AC; Inbox</h3>
            <?php if($_totalUnread > 0): ?><span class="wai-badge"><?= $_totalUnread ?></span><?php endif; ?>
            <?php if($isAdmin): ?>
            <button onclick="waAutoLink()" style="margin-left:auto;font-size:10px;padding:4px 8px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;" title="Auto-link all to CRM">&#x1F517; Auto-Link</button>
            <button onclick="waRunSync()" style="font-size:10px;padding:4px 8px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;" title="Pull messages from WhatsApp now">&#x1F504; Sync Now</button>
            <?php endif; ?>
        </div>
        <div class="wai-tabs" id="waiTabs">
            <div class="wai-tab<?= $_defaultChannel === '' ? ' active' : '' ?>" data-ch="">All<?php if($_totalUnread > 0): ?> <span class="wai-badge" style="background:#64748b;"><?= $_totalUnread ?></span><?php endif; ?></div>
            <div class="wai-tab<?= $_defaultChannel === 'web' ? ' active' : '' ?>" data-ch="web">Website<?php if($_unreadWeb > 0): ?> <span class="wai-badge" style="background:#0891b2;"><?= $_unreadWeb ?></span><?php endif; ?></div>
            <div class="wai-tab<?= $_defaultChannel === 'support' ? ' active' : '' ?>" data-ch="support">Support<?php if($_unreadSupport > 0): ?> <span class="wai-badge" style="background:#2563eb;"><?= $_unreadSupport ?></span><?php endif; ?></div>
            <div class="wai-tab<?= $_defaultChannel === 'accounts' ? ' active' : '' ?>" data-ch="accounts">Accounts<?php if($_unreadAccounts > 0): ?> <span class="wai-badge" style="background:#d97706;"><?= $_unreadAccounts ?></span><?php endif; ?></div>
            <?php if ($_needsHuman > 0): ?><div class="wai-tab" data-ch="__needs_human" style="color:#dc2626;">🔴 Needs Reply <span class="wai-badge" style="background:#dc2626;"><?= $_needsHuman ?></span></div><?php endif; ?>
            <div class="wai-tab" data-ch="__unread">Unread</div>
        </div>
        <div class="wai-search"><input type="text" id="waiSearch" placeholder="Search name, phone, or message..." autocomplete="off"></div>
        <div class="wai-threads" id="waiThreads"><div style="padding:40px;text-align:center;color:#94a3b8;">Loading...</div></div>
        <div class="wai-sync">
            <span id="waiSyncDot"><span class="dot <?= $_syncOk ? 'green' : 'yellow' ?>"></span><span id="waiSyncTxt">Sync: <?= htmlspecialchars($_syncState['last_sync_at'] ?? 'never') ?></span></span>
            <span><?= number_format((int)($_syncState['total_synced'] ?? 0)) ?> msgs <?php if($isAdmin): ?><a href="#" onclick="waForceSync();return false;" style="color:#25D366;font-size:9px;font-weight:700;">↻ Sync now</a> · <a href="#" onclick="waResetSync();return false;" style="color:#ef4444;font-size:9px;">reset</a><?php endif; ?></span>
        </div>
    </div>
    <div class="wai-right empty" id="waiRight">
        <div class="wai-empty" id="waiEmpty"><div class="ico">&#x1F4AC;</div><h3>DishNet WhatsApp Inbox</h3><p>Select a conversation to view messages</p></div>
        <div class="wai-chdr" id="waiChdr" style="display:none;">
            <button onclick="waiBack()" class="wai-back-btn" style="background:none;border:none;cursor:pointer;padding:4px 8px;color:inherit;font-size:22px;">&#x2190;</button>
            <div class="ava" id="waiChdrAva">?</div>
            <div class="info"><div class="nm" id="waiChdrNm">&mdash;</div><div class="sub" id="waiChdrSub">&mdash;</div></div>
            <div class="acts">
                <button onclick="waiLinkModal()">&#x1F517; Link</button>
                <button onclick="waiQuickAction('create_kyc_lead')">&#x1F4CB; Lead</button>
                <button onclick="waiCatMenu()">&#x1F4C2; Cat</button>
                <button onclick="waiCloseThread()">&#x2705;</button>
            </div>
        </div>
        <div class="wai-msgs" id="waiMsgs" style="display:none;"></div>
        <div class="wai-reply" id="waiReply" style="display:none;">
            <textarea id="waiReplyText" placeholder="Type a message..." rows="1"></textarea>
            <button class="sendbtn" id="waiSendBtn" onclick="waiSendReply()">&#x27A4;</button>
        </div>
    </div>
</div>
<div class="wai-modal-bg" id="waiLinkBg">
    <div class="wai-modal">
        <h4>&#x1F517; Link to CRM Client</h4>
        <input type="text" id="waiLinkSearch" placeholder="Search client name or ID..." oninput="waiLinkFilter()">
        <div class="results" id="waiLinkResults"></div>
        <div style="text-align:right;margin-top:10px;">
            <button onclick="document.getElementById('waiLinkBg').classList.remove('show')" style="padding:6px 14px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;">Cancel</button>
        </div>
    </div>
</div>

<script>
(function(){
var TK=(document.cookie.match(/hybrid_token=([^;]+)/)||[])[1]||'';
var HDR={'Authorization':'Bearer '+TK,'Content-Type':'application/json','Accept':'application/json'};
function ap(a,q){return fetch('?page=api&action='+a+(q||''),{credentials:'same-origin',headers:HDR}).then(function(r){return r.json();});}
function pp(a,b){return fetch('?page=api&action='+a,{
          credentials:'same-origin',
          method:'POST',headers:HDR,body:JSON.stringify(b)}).then(function(r){return r.json();});}

var curConvId=0, curConv=null, curChannel=<?= json_encode($_defaultChannel) ?>, curSearch='', pollTimer=null;
var clientIdx=<?= json_encode($store->load('client_search_index.json') ?? []) ?>;

function esc(s){if(!s)return'';var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
function timeAgo(dt){
    if(!dt)return'';
    var s=Math.floor((Date.now()-new Date(dt.replace(' ','T')+'Z').getTime())/1000);
    if(s<0)s=0;
    if(s<60)return'now';if(s<3600)return Math.floor(s/60)+'m';
    if(s<86400)return Math.floor(s/3600)+'h';if(s<604800)return Math.floor(s/86400)+'d';
    return dt.substring(0,10);
}
function formatDate(d){
    var today=new Date().toISOString().substring(0,10);
    var y=new Date(Date.now()-86400000).toISOString().substring(0,10);
    if(d===today)return'Today';if(d===y)return'Yesterday';return d;
}

// ── Load threads ──────────────────────────────────────────
function loadThreads(){
    var q='&limit=80';
    if(curChannel&&curChannel!=='__unread'&&curChannel!=='__needs_human') q+='&channel='+curChannel;
    if(curChannel==='__unread') q+='&unread=1';
    if(curChannel==='__needs_human') q+='&state=needs_human';
    if(curSearch) q+='&search='+encodeURIComponent(curSearch);
    ap('wa_conversations',q).then(function(d){
        if(!d||!d.data)return;
        renderThreads(d.data.conversations||[]);
    });
}

function renderThreads(convs){
    var el=document.getElementById('waiThreads');
    if(!convs.length){el.innerHTML='<div style="padding:40px;text-align:center;color:#94a3b8;">No conversations found</div>';return;}
    var html='';
    for(var i=0;i<convs.length;i++){
        var c=convs[i];
        var unread=parseInt(c.unread_count)||0;
        var ch=c.channel||'support';
        var nm=c.display_name||c.crm_client_name||c.phone;
        var prev=(c.last_message_preview||'').substring(0,55);
        var tm=timeAgo(c.last_message_at||c.updated_at||'');
        var sel=(parseInt(c.id)===curConvId)?'sel':'';
        var ini=(nm&&nm[0])?nm[0].toUpperCase():'?';
        var crm=c.crm_client_id?'<span style="font-size:9px;background:#dbeafe;color:#1e40af;padding:1px 5px;border-radius:4px;">#'+c.crm_client_id+'</span>':'';
        var stateTag='';
        if(c.state==='needs_human') stateTag='<span style="font-size:9px;background:#fef2f2;color:#dc2626;padding:1px 5px;border-radius:4px;font-weight:700;">🔴 Needs Reply</span>';
        else if(c.state==='human_active') stateTag='<span style="font-size:9px;background:#f0fdf4;color:#16a34a;padding:1px 5px;border-radius:4px;">👤 Active</span>';
        html+='<div class="wai-thread '+sel+'" onclick="waiOpen('+c.id+')">'
            +'<div class="ava">'+ini+'</div><div class="info">'
            +'<div class="top"><span class="nm">'+esc(nm)+' '+crm+'</span><span class="tm">'+tm+'</span></div>'
            +'<div style="display:flex;align-items:center;gap:4px;">'
            +'<span class="ch-pill '+ch+'">'+ch+'</span>'
            +stateTag
            +'<span class="prev">'+esc(prev)+'</span>'
            +(unread?'<span class="unrd">'+unread+'</span>':'')
            +'</div></div></div>';
    }
    el.innerHTML=html;
}

// ── Open thread ───────────────────────────────────────────
window.waiOpen=function(id){
    curConvId=id;
    loadThread(id);
    document.getElementById('waiRight').classList.add('mob-show');
};

var _lastMsgCount=0, _lastConvId=0;
function loadThread(id){
    var isNewConv=(id!==_lastConvId);
    ap('wa_thread_messages','&id='+id+'&limit=200').then(function(d){
        if(!d||!d.data)return;
        curConv=d.data.conversation;
        var msgs=d.data.messages||[];
        var newCount=msgs.length;
        var hasNew=(newCount>_lastMsgCount||isNewConv);
        _lastConvId=id; _lastMsgCount=newCount;
        renderChat(curConv, msgs, isNewConv, hasNew);
        loadThreads();
    });
}

function renderChat(conv, msgs, isNewConv, hasNew){
    var right=document.getElementById('waiRight');
    right.classList.remove('empty');
    document.getElementById('waiEmpty').style.display='none';
    document.getElementById('waiChdr').style.display='flex';
    document.getElementById('waiMsgs').style.display='flex';
    document.getElementById('waiReply').style.display='flex';

    var nm=conv.display_name||conv.crm_client_name||conv.phone;
    document.getElementById('waiChdrNm').textContent=nm;
    document.getElementById('waiChdrAva').textContent=(nm&&nm[0])?nm[0].toUpperCase():'?';
    var sub=conv.phone||'';
    if(conv.channel) sub+='  \u2022  <span style="font-weight:700;text-transform:uppercase;font-size:10px;'+(conv.channel==='accounts'?'color:#d97706':'color:#2563eb')+'">'+conv.channel+'</span>';
    if(conv.crm_client_id) sub+='  \u2022  CRM #'+conv.crm_client_id+' ('+esc(conv.crm_client_name||'')+')';
    if(conv.category) sub+='  \u2022  '+conv.category;
    if(conv.state==='needs_human') sub+='  \u2022  <span style="color:#dc2626;font-weight:700;">🔴 Needs Reply</span>';
    else if(conv.state==='human_active') sub+='  \u2022  <span style="color:#16a34a;">👤 You\'re handling this</span>';
    document.getElementById('waiChdrSub').innerHTML=sub;

    // Async: fetch CRM balance if linked
    if(conv.crm_client_id){
        var balEl=document.createElement('span');
        balEl.id='waiCrmBal';
        balEl.style.cssText='margin-left:8px;font-size:10px;color:#94a3b8;';
        balEl.textContent='Loading balance...';
        document.getElementById('waiChdrSub').appendChild(balEl);
        ap('wa_crm_client_info','&client_id='+conv.crm_client_id).then(function(d){
            if(!d||!d.data)return;
            var info=d.data;
            var bal=parseFloat(info.balance||0);
            var parts=[];
            if(bal>0.01) parts.push('<span style="color:#dc2626;font-weight:700;">Owes $'+bal.toFixed(2)+'</span>');
            else if(bal<-0.01) parts.push('<span style="color:#16a34a;">Credit $'+Math.abs(bal).toFixed(2)+'</span>');
            else parts.push('<span style="color:#16a34a;">$0 — clear</span>');
            if(info.services) parts.push(esc(info.services));
            if(info.status) parts.push(info.status);
            var bEl=document.getElementById('waiCrmBal');
            if(bEl) bEl.innerHTML='  \u2022  '+parts.join('  \u2022  ');
        })['catch'](function(){var bEl=document.getElementById('waiCrmBal');if(bEl)bEl.textContent='';});
    }

    var el=document.getElementById('waiMsgs');
    var html='', lastDate='';
    for(var i=0;i<msgs.length;i++){
        var m=msgs[i];
        // Storage is UTC; show the browser's local time. Parsing with a 'Z'
        // suffix is what makes the conversion happen -- without it the string
        // is read as already-local and everything shifts twice.
        var loc=new Date(((m.sent_at||'').replace(' ','T'))+'Z');
        var pad=function(x){return (x<10?'0':'')+x;};
        var d=isNaN(loc)?(m.sent_at||'').substring(0,10)
             :loc.getFullYear()+'-'+pad(loc.getMonth()+1)+'-'+pad(loc.getDate());
        if(d&&d!==lastDate){html+='<div class="wai-date-sep">'+formatDate(d)+'</div>';lastDate=d;}
        var dir=m.direction==='in'?'in':'out';
        var role=m.role||'customer';
        var cls=role==='system'?'sys':dir;
        var time=isNaN(loc)?(m.sent_at||'').substring(11,16):pad(loc.getHours())+':'+pad(loc.getMinutes());
        var body=esc(m.body||'').replace(/\n/g,'<br>');
        if(m.media_type&&m.media_type!=='null') body='<span class="media-tag">['+esc(m.media_type)+']</span> '+body;
        // Who said it, on every line. Reviewing a conversation means asking
        // "did the AI quote the right price, and did a human step in?" -- a
        // faint caption under some bubbles does not answer that at a glance.
        var who, whoCol;
        if(dir==='in'){ who='CUSTOMER'; whoCol='#475569'; }
        else if(role==='assistant'){ who='AI'; whoCol='#7c3aed'; }
        else if(role==='system'){ who='SYSTEM'; whoCol='#94a3b8'; }
        else { who='HUMAN' + (m.agent_name?' \u2014 '+esc(m.agent_name):''); whoCol='#16a34a'; }
        var agent='<div class="ag" style="color:'+whoCol+';font-weight:700;letter-spacing:.4px;">'+who+'</div>';
        html+='<div class="wai-msg '+cls+'">'+agent+body+'<span class="mt">'+time+'</span></div>';
    }
    var wasAtBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
    el.innerHTML=html;
    // Scroll to bottom: always on initial open, or if user was already at bottom
    if(isNewConv || hasNew && wasAtBottom || isNewConv===undefined) el.scrollTop=el.scrollHeight;
}

// ── Send reply ────────────────────────────────────────────
window.waiSendReply=function(){
    var ta=document.getElementById('waiReplyText');
    var text=ta.value.trim();
    if(!text||!curConvId)return;
    var btn=document.getElementById('waiSendBtn');
    btn.disabled=true;
    pp('wa_send_reply',{conversation_id:curConvId,message:text}).then(function(d){
        btn.disabled=false;
        if(d&&d.status==='success'){ta.value='';ta.style.height='auto';loadThread(curConvId);}
        else{alert('Send failed: '+(d&&d.message||'Unknown error'));}
    })['catch'](function(){btn.disabled=false;alert('Network error');});
};

document.getElementById('waiReplyText').addEventListener('keydown',function(e){
    if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();waiSendReply();}
});
document.getElementById('waiReplyText').addEventListener('input',function(){
    this.style.height='auto';this.style.height=Math.min(this.scrollHeight,100)+'px';
});

// ── Quick actions ─────────────────────────────────────────
window.waiCloseThread=function(){
    if(!curConvId||!confirm('Close this conversation?'))return;
    pp('wa_close_thread',{conversation_id:curConvId}).then(function(){loadThreads();waiReset();});
};
window.waiQuickAction=function(action){
    if(!curConvId)return;
    pp('wa_quick_action',{conversation_id:curConvId,action:action}).then(function(d){
        if(d&&d.data&&d.data.lead_id) alert('Lead #'+d.data.lead_id+' created!');
        else if(d&&d.data&&d.data.ticket_id) alert('Ticket #'+d.data.ticket_id+' created!');
        else alert(d&&d.message||'Done');
    });
};
window.waiCatMenu=function(){
    var choice=prompt('Set category:\nbilling, technical, onboarding, general, complaint, marketing');
    if(!choice||!curConvId)return;
    pp('wa_categorise',{conversation_id:curConvId,category:choice.toLowerCase().trim()}).then(function(){loadThread(curConvId);});
};

// ── CRM Link Modal ────────────────────────────────────────
window.waiLinkModal=function(){
    document.getElementById('waiLinkBg').classList.add('show');
    document.getElementById('waiLinkSearch').value='';
    document.getElementById('waiLinkResults').innerHTML='<div style="padding:12px;color:#94a3b8;font-size:12px;">Type to search clients...</div>';
    document.getElementById('waiLinkSearch').focus();
};
window.waiLinkFilter=function(){
    var q=document.getElementById('waiLinkSearch').value.toLowerCase().trim();
    if(q.length<2){document.getElementById('waiLinkResults').innerHTML='';return;}
    var hits=[];
    for(var i=0;i<clientIdx.length&&hits.length<15;i++){
        if((clientIdx[i].search||'').indexOf(q)!==-1) hits.push(clientIdx[i]);
    }
    var el=document.getElementById('waiLinkResults');
    if(!hits.length){el.innerHTML='<div style="padding:8px;color:#94a3b8;font-size:12px;">No matches</div>';return;}
    var html='';
    for(var j=0;j<hits.length;j++){
        var c=hits[j];
        html+='<div class="res-item" onclick="waiDoLink('+c.id+')"><strong>#'+c.id+'</strong> '+esc(c.name||'')
            +(c.phone?' <span style="color:#94a3b8;font-size:11px;">'+esc(c.phone)+'</span>':'')+'</div>';
    }
    el.innerHTML=html;
};
window.waiDoLink=function(clientId){
    if(!curConvId)return;
    pp('wa_link_client',{conversation_id:curConvId,crm_client_id:clientId}).then(function(d){
        document.getElementById('waiLinkBg').classList.remove('show');
        if(d&&d.status==='success'){loadThread(curConvId);loadThreads();}
    });
};
window.waAutoLink=function(){
    if(!confirm('Auto-link all unlinked conversations to CRM clients by phone?'))return;
    pp('wa_auto_link',{}).then(function(d){
        if(d&&d.data) alert('Linked: '+d.data.linked+', Unmatched: '+d.data.unmatched);
        loadThreads();
    });
};
window.waRunSync=function(){
    var btn=event.target;btn.disabled=true;btn.textContent='Syncing...';
    pp('wa_run_sync',{}).then(function(d){
        btn.disabled=false;btn.textContent='\u{1F504} Sync Now';
        if(d&&d.data){
            alert('Sync done!\nStored: '+d.data.stored+'\nSkipped: '+d.data.skipped+'\nErrors: '+(d.data.errors||0)+'\nLinked: '+(d.data.linked||0)+(d.data.last_error?'\n\nLast error: '+d.data.last_error:''));
            loadThreads();
        } else {
            alert('Sync error: '+(d&&d.message||'Unknown'));
        }
    })['catch'](function(e){btn.disabled=false;btn.textContent='\u{1F504} Sync Now';alert('Network error');});
};
window.waResetSync=function(){
    if(!confirm('Reset sync cursor to 0? This will re-sync all 39K+ messages from scratch.'))return;
    pp('wa_sync_reset',{}).then(function(d){
        alert(d&&d.data&&d.data.message||'Reset done');
    });
};

// ── Mobile back ───────────────────────────────────────────
window.waiBack=function(){document.getElementById('waiRight').classList.remove('mob-show');};

function waiReset(){
    curConvId=0;curConv=null;
    var r=document.getElementById('waiRight');
    r.classList.add('empty');r.classList.remove('mob-show');
    document.getElementById('waiEmpty').style.display='';
    document.getElementById('waiChdr').style.display='none';
    document.getElementById('waiMsgs').style.display='none';
    document.getElementById('waiReply').style.display='none';
}

// ── Tab switching ─────────────────────────────────────────
var tabs=document.querySelectorAll('.wai-tab');
for(var t=0;t<tabs.length;t++){
    tabs[t].addEventListener('click',function(){
        for(var x=0;x<tabs.length;x++) tabs[x].classList.remove('active');
        this.classList.add('active');
        curChannel=this.getAttribute('data-ch')||'';
        loadThreads();
    });
}

// ── Search ────────────────────────────────────────────────
var searchTimer;
document.getElementById('waiSearch').addEventListener('input',function(){
    clearTimeout(searchTimer);
    var self=this;
    searchTimer=setTimeout(function(){curSearch=self.value.trim();loadThreads();},300);
});

// ── Force sync on demand ──────────────────────────────────
window.waForceSync = function(){
    var txt = document.getElementById('waiSyncTxt');
    var dot = document.querySelector('#waiSyncDot .dot');
    if(txt) txt.textContent = 'Syncing...';
    if(dot){ dot.className='dot yellow'; }
    ap('wa_trigger_sync','').then(function(d){
        if(txt) txt.textContent = 'Synced just now';
        if(dot){ dot.className='dot green'; }
        loadThreads();
        if(curConvId) loadThread(curConvId);
    })['catch'](function(){
        if(txt) txt.textContent = 'Sync failed';
        if(dot){ dot.className='dot red'; }
    });
};

// ── Live sync clock (updates every 30s) ──────────────────
setInterval(function(){
    ap('wa_sync_status','').then(function(d){
        if(!d||!d.data) return;
        var txt = document.getElementById('waiSyncTxt');
        var dot = document.querySelector('#waiSyncDot .dot');
        if(!txt||!dot) return;
        var lastAt = d.data.last_sync_at || '';
        var fresh  = d.data.is_fresh || false;
        dot.className = 'dot ' + (fresh ? 'green' : 'yellow');
        if(lastAt){
            var ago = Math.round((Date.now() - new Date(lastAt).getTime()) / 1000);
            txt.textContent = ago < 60 ? 'Synced ' + ago + 's ago'
                : ago < 3600 ? 'Synced ' + Math.round(ago/60) + 'm ago'
                : 'Last sync: ' + lastAt.substring(0,16);
        }
    })['catch'](function(){});
}, 30000);
var _pollThreads = setInterval(function(){
    loadThreads();
}, 4000);

var _pollChat = setInterval(function(){
    if(curConvId) loadThread(curConvId);
}, 3000);

// ── Init ──────────────────────────────────────────────────
loadThreads();

})();
</script>
