<?php
// Tab: lte_reminders
// Extracted from public.php on 2026-03-15
?>
<?php $apiTok2 = h($retailer['api_token'] ?? ""); $waConfigured = !empty($config['wa_plugin_url']) && !empty($config['wa_app_key']) && !empty($config['wa_auth_key']); ?>
<style>
.rem-card{background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;box-shadow:var(--shadow-sm);margin-bottom:14px;}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div>
        <div style="font-size:18px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:8px;"><i class="bi bi-whatsapp" style="color:#25D366;"></i>WhatsApp Renewal Reminders</div>
        <div style="font-size:12px;color:var(--text-3);margin-top:2px;">Send renewal alerts to LTE subscribers via WhatsApp webhook</div>
    </div>
</div>

<?php if(!$waConfigured): ?>
<div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:12px;padding:16px 18px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
    <i class="bi bi-exclamation-triangle-fill" style="color:#D97706;font-size:22px;flex-shrink:0;"></i>
    <div><div style="font-size:13px;font-weight:700;color:#92400E;">WhatsApp webhook not configured</div>
    <div style="font-size:12px;color:#92400E;margin-top:2px;">Go to <a href="?page=dashboard&tab=settings" style="color:#92400E;font-weight:700;">Settings → WhatsApp / n8n Webhook URL</a> to connect your WhatsApp sender.</div></div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
    <!-- Bulk reminder panel -->
    <div class="rem-card">
        <div style="padding:14px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:800;color:var(--text);display:flex;align-items:center;gap:6px;"><i class="bi bi-broadcast" style="color:#25D366;"></i>Bulk Send</div>
        <div style="padding:16px;">
            <div style="font-size:12px;color:var(--text-2);margin-bottom:14px;">Send reminders to all subscribers matching the selected criteria at once.</div>
            <div style="display:grid;gap:10px;">
                <div class="lte-field"><label>Message Type</label>
                    <select id="bulk-type">
                        <option value="reminder">⏰ Expiring Soon Reminder</option>
                        <option value="expired">🚫 Expired Notice</option>
                    </select>
                </div>
                <div class="lte-field" id="bulk-days-row"><label>Notify subscribers expiring within</label>
                    <select id="bulk-days">
                        <option value="1">1 day</option>
                        <option value="3" selected>3 days</option>
                        <option value="7">7 days</option>
                    </select>
                </div>
            </div>
            <div id="bulk-preview" style="font-size:12px;color:var(--text-3);margin:10px 0;min-height:18px;"></div>
            <button onclick="doBulkRemind()" class="lte-btn primary" style="width:100%;justify-content:center;" id="bulk-send-btn" <?= !$waConfigured?'disabled':'' ?>>
                <i class="bi bi-whatsapp"></i> Send Bulk Reminders
            </button>
            <div id="bulk-result" style="margin-top:10px;font-size:12px;"></div>
        </div>
    </div>

    <!-- Single subscriber panel -->
    <div class="rem-card">
        <div style="padding:14px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:800;color:var(--text);display:flex;align-items:center;gap:6px;"><i class="bi bi-person-fill" style="color:var(--primary);"></i>Send to Subscriber</div>
        <div style="padding:16px;">
            <div style="font-size:12px;color:var(--text-2);margin-bottom:14px;">Search a subscriber and send a targeted message.</div>
            <div class="lte-field" style="margin-bottom:10px;"><label>Search Subscriber</label>
                <input id="rem-search" type="text" placeholder="Name, phone, MSISDN…" oninput="remSearch(this.value)">
            </div>
            <div id="rem-search-results" style="margin-bottom:10px;max-height:160px;overflow-y:auto;"></div>
            <div class="lte-field" style="margin-bottom:10px;"><label>Message Type</label>
                <select id="rem-type">
                    <option value="reminder">⏰ Expiring Soon</option>
                    <option value="expired">🚫 Expired</option>
                    <option value="suspended">🔒 Suspended</option>
                    <option value="renewed">✅ Renewed Confirmation</option>
                </select>
            </div>
            <button onclick="doSingleRemind()" class="lte-btn success" style="width:100%;justify-content:center;" id="single-send-btn" <?= !$waConfigured?'disabled':'' ?> disabled>
                <i class="bi bi-whatsapp"></i> Send Message
            </button>
            <div id="single-result" style="margin-top:8px;font-size:12px;"></div>
        </div>
    </div>
</div>

<!-- Reminder log -->
<div class="rem-card">
    <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:13px;font-weight:800;color:var(--text);display:flex;align-items:center;gap:6px;"><i class="bi bi-clock-history" style="color:var(--primary);"></i>Reminder Log <span style="font-weight:400;color:var(--text-3);">(last 200)</span></span>
        <button onclick="loadRemLog()" class="lte-btn ghost sm"><i class="bi bi-arrow-repeat"></i></button>
    </div>
    <div id="rem-log-body"><div style="padding:24px;text-align:center;color:var(--text-3);">Loading…</div></div>
</div>

<script>
(function(){
var TK='<?= $apiTok2 ?>';
var selSubId=null;
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function fmt(d){return d?d.substring(0,16).replace('T',' '):'';}

// Bulk type toggle
document.getElementById('bulk-type').addEventListener('change',function(){
    document.getElementById('bulk-days-row').style.display=this.value==='expired'?'none':'';
});

window.doBulkRemind = function(){
    var type=document.getElementById('bulk-type').value;
    var days=parseInt(document.getElementById('bulk-days').value)||3;
    var btn=document.getElementById('bulk-send-btn');
    var res=document.getElementById('bulk-result');
    if(!confirm('Send '+type+' messages to all qualifying subscribers?')) return;
    btn.disabled=true;btn.innerHTML='<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;"></i> Sending…';
    fetch('?page=api&action=lte_bulk_remind',{
          credentials:'same-origin',
          method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+TK},
        body:JSON.stringify({type:type,days:days})
    }).then(r=>r.json()).then(function(d){
        btn.disabled=false;btn.innerHTML='<i class="bi bi-whatsapp"></i> Send Bulk Reminders';
        if(d.status==='success'){
            res.innerHTML='<span style="color:var(--green);font-weight:700;">✓ Sent: '+d.data.sent+'</span> · Skipped: '+d.data.skipped;
            loadRemLog();
        } else {
            res.innerHTML='<span style="color:var(--red);">⚠ '+esc(d.message||'Failed')+'</span>';
        }
    }).catch(function(){btn.disabled=false;res.innerHTML='<span style="color:var(--red);">Network error</span>';});
};

var remTimer=null;
window.remSearch = function(q){
    clearTimeout(remTimer);
    if(q.length<2){document.getElementById('rem-search-results').innerHTML='';return;}
    remTimer=setTimeout(function(){
        fetch('?page=api&action=lte_subscribers&search='+encodeURIComponent(q),{credentials:'same-origin',headers:{'Authorization':'Bearer '+TK}})
        .then(r=>r.json()).then(function(d){
            var res=document.getElementById('rem-search-results');
            if(d.status!=='success'||!d.data.length){res.innerHTML='<div style="font-size:12px;color:var(--text-3);padding:4px 0;">No results</div>';return;}
            res.innerHTML=d.data.slice(0,5).map(function(s){
                return '<div onclick="remSelect('+s.id+',\''+esc(s.name)+'\')" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:12px;margin-bottom:4px;background:var(--surface);" onmouseover="this.style.background=\'var(--primary-lt)\'" onmouseout="this.style.background=\'var(--surface)\'">'
                    +'<strong>'+esc(s.name)+'</strong> · '+esc(s.phone||'')
                    +(s.expires_at?'<span style="float:right;color:var(--text-3);">exp: '+s.expires_at.substring(0,10)+'</span>':'')
                    +'</div>';
            }).join('');
        });
    },300);
};

window.remSelect = function(id,name){
    selSubId=id;
    document.getElementById('rem-search').value=name;
    document.getElementById('rem-search-results').innerHTML='<div style="font-size:11px;color:var(--green);padding:2px 0;">✓ Selected: '+esc(name)+'</div>';
    document.getElementById('single-send-btn').disabled=false;
};

window.doSingleRemind = function(){
    if(!selSubId){alert('Select a subscriber first');return;}
    var type=document.getElementById('rem-type').value;
    var btn=document.getElementById('single-send-btn');
    var res=document.getElementById('single-result');
    btn.disabled=true;btn.innerHTML='<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;"></i>';
    fetch('?page=api&action=lte_send_reminder',{
          credentials:'same-origin',
          method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+TK},
        body:JSON.stringify({subscriber_id:selSubId,type:type})
    }).then(r=>r.json()).then(function(d){
        btn.disabled=false;btn.innerHTML='<i class="bi bi-whatsapp"></i> Send Message';
        if(d.status==='success'){
            res.innerHTML='<span style="color:var(--green);font-weight:700;">✓ Sent to '+esc(d.data.to)+'</span>';
            loadRemLog();
        } else {
            res.innerHTML='<span style="color:var(--red);">⚠ '+esc(d.message||'Failed')+'</span>';
        }
    }).catch(function(){btn.disabled=false;res.innerHTML='<span style="color:var(--red);">Network error</span>';});
};

window.loadRemLog = function(){
    var body=document.getElementById('rem-log-body');
    fetch('?page=api&action=lte_reminder_log',{credentials:'same-origin',headers:{'Authorization':'Bearer '+TK}})
    .then(r=>r.json()).then(function(d){
        if(d.status!=='success'||!d.data.length){
            body.innerHTML='<div style="padding:24px;text-align:center;color:var(--text-3);">No reminders sent yet</div>';return;
        }
        var typePills={reminder:'⏰ Reminder',expired:'🚫 Expired',suspended:'🔒 Suspended',renewed:'✅ Renewed'};
        var h='<table class="lte-tbl"><thead><tr><th>Sent At</th><th>Subscriber</th><th>Phone</th><th>Type</th><th>Plan</th><th>Expires</th><th>Sent By</th></tr></thead><tbody>';
        d.data.forEach(function(r){
            h+='<tr>';
            h+='<td style="font-size:11px;color:var(--text-3);">'+fmt(r.sent_at)+'</td>';
            h+='<td style="font-weight:600;">'+esc(r.name)+'</td>';
            h+='<td style="font-size:11px;font-family:monospace;">'+esc(r.phone)+'</td>';
            h+='<td><span style="font-size:11px;background:var(--surface);border:1px solid var(--border);border-radius:5px;padding:2px 7px;">'+esc(typePills[r.type]||r.type)+'</span></td>';
            h+='<td style="font-size:11px;">'+esc(r.plan||'—')+'</td>';
            h+='<td style="font-size:11px;">'+esc(r.expires||'—')+'</td>';
            h+='<td style="font-size:11px;color:var(--text-3);">'+esc(r.sent_by)+'</td>';
            h+='</tr>';
        });
        h+='</tbody></table>';
        body.innerHTML=h;
    });
};
loadRemLog();
})();
</script>

