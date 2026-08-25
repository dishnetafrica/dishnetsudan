<?php
// Tab: customer_lookup — Customer 360° (UCRM Replica)
// Background-synced data → instant table load → click for 360° profile
// PHP 7.4 compatible
        $apiToken = h($retailer['api_token'] ?? "");
        $showFin  = $isAdmin || $can('customer_financials');
        $crmBase  = trim($config['crm_base_url'] ?? '');
        if (!$crmBase) {
            foreach ([__DIR__ . '/../../ucrm.json', __DIR__ . '/../../data/ucrm.json'] as $_p) {
                if (file_exists($_p)) { $_uc = json_decode(file_get_contents($_p), true); if (!empty($_uc['ucrmPublicUrl'])) { $crmBase = rtrim($_uc['ucrmPublicUrl'], '/'); break; } }
            }
        }
        $crmBase = $crmBase ? rtrim($crmBase, '/') : '';

        // ── Build enriched index ──
        $uIdx = $store->load('client_search_index.json') ?? [];
        $fullCache = $store->load('ucrm_clients_cache.json') ?? [];
        $enrichMap = [];
        foreach ($fullCache as $c) {
            $cid = (int)($c['id'] ?? 0); if (!$cid) continue;
            $tags = [];
            foreach ($c['tags'] ?? [] as $t) { $tags[] = is_array($t) ? ($t['name'] ?? '') : (string)$t; }
            $enrichMap[$cid] = [
                'org'  => trim($c['organizationName'] ?? ''),
                'tags' => $tags,
                'isLead' => (bool)($c['isLead'] ?? false),
            ];
        }
        foreach ($uIdx as &$r) {
            $e = $enrichMap[(int)$r['id']] ?? null;
            $r['org']    = $e ? $e['org'] : '';
            $r['tags']   = $e ? $e['tags'] : [];
            $r['isLead'] = $e ? $e['isLead'] : false;
            // Normalize status: UCRM API returns 1=active as int, sometimes string
            $st = $r['status'] ?? '';
            if ($st === 1 || $st === '1' || $st === 'active') $r['_st'] = 'active';
            elseif ($st === 2 || $st === '2' || $st === 'suspended') $r['_st'] = 'suspended';
            else $r['_st'] = 'other';
        }
        unset($r);

        $totalAll    = count($uIdx);
        $totalActive = count(array_filter($uIdx, function($r) { return ($r['_st'] ?? '') === 'active' && !($r['isLead'] ?? false); }));
        $totalLeads  = count(array_filter($uIdx, function($r) { return !empty($r['isLead']); }));
        $totalSusp   = count(array_filter($uIdx, function($r) { return ($r['_st'] ?? '') === 'suspended'; }));
?>
<style>
/* ═══════════════════════════════════════════════════════════
   UCRM REPLICA — CLIENT LIST + 360° PROFILE
   ═══════════════════════════════════════════════════════════ */
:root{--u-bg:#f5f6f8;--u-card:#fff;--u-border:#e4e7eb;--u-text:#2c3e50;--u-text2:#7c8798;--u-text3:#b0b8c4;--u-blue:#0193d7;--u-green:#4caf50;--u-red:#e53935;--u-yellow:#f9a825;--u-radius:4px}

.u-wrap{max-width:1420px;margin:0 auto}

/* ── Page header ── */
.u-page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.u-page-head h1{font-size:24px;font-weight:400;color:var(--u-text);margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.u-search-wrap{position:relative}
.u-search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--u-text3);font-size:14px}
.u-search{width:220px;font-size:13px;padding:7px 12px 7px 32px;border:1px solid var(--u-border);border-radius:var(--u-radius);outline:none;background:var(--u-card);color:var(--u-text)}
.u-search:focus{border-color:var(--u-blue);box-shadow:0 0 0 2px rgba(1,147,215,.15)}

/* ── Tabs (UCRM-style underline tabs) ── */
.u-tabs{display:flex;border-bottom:2px solid var(--u-border);margin-bottom:16px;gap:0}
.u-tab{padding:12px 20px;font-size:14px;color:var(--u-text2);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;font-weight:500;transition:color .15s;user-select:none}
.u-tab:hover{color:var(--u-text)}
.u-tab.active{color:var(--u-blue);border-bottom-color:var(--u-blue);font-weight:600}
.u-tab-n{font-weight:400;margin-left:2px}

/* ── Count label ── */
.u-count{font-size:13px;color:var(--u-text2);margin-bottom:12px;font-weight:500}

/* ── Client table (UCRM replica) ── */
.u-tbl{width:100%;border-collapse:collapse;background:var(--u-card);border:1px solid var(--u-border);border-radius:var(--u-radius)}
.u-tbl th{padding:10px 16px;font-size:12px;font-weight:600;color:var(--u-text2);text-transform:uppercase;letter-spacing:.3px;text-align:left;border-bottom:2px solid var(--u-border);background:#fafbfc;cursor:pointer;user-select:none;white-space:nowrap}
.u-tbl th:hover{color:var(--u-text)}
.u-tbl th .si{font-size:8px;margin-left:4px;color:var(--u-text3)}
.u-tbl th.sorted .si{color:var(--u-blue)}
.u-tbl td{padding:11px 16px;font-size:14px;color:var(--u-text);border-bottom:1px solid var(--u-border);vertical-align:middle}
.u-tbl tr:last-child td{border-bottom:none}
.u-tbl tbody tr{cursor:pointer;transition:background .1s}
.u-tbl tbody tr:hover{background:#f0f7ff}
.u-tbl .c-id{width:70px;color:var(--u-text2);font-size:13px}
.u-tbl .c-name{font-size:14px}
.u-tbl .c-name .nm{font-weight:600;color:var(--u-text)}
.u-tbl .c-name .un{font-weight:400;color:var(--u-text2);font-size:13px}
.u-tbl .c-bal{width:100px;text-align:right;font-weight:600;font-variant-numeric:tabular-nums;font-size:14px}
.u-tbl .c-plans{width:160px;color:var(--u-text2);font-size:13px}
.u-tbl .c-org{width:180px;color:var(--u-text2);font-size:13px}
.u-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:8px;vertical-align:middle}
.u-tag{display:inline-block;font-size:11px;font-weight:600;padding:2px 10px;border-radius:3px;background:#b7e281;color:#444;margin-left:8px;vertical-align:middle;line-height:1.4}
.u-dash{color:var(--u-text3);letter-spacing:3px}

/* ── Pagination ── */
.u-pager{display:flex;align-items:center;justify-content:flex-end;padding:10px 16px;gap:8px;font-size:13px;color:var(--u-text2);border-top:1px solid var(--u-border);background:#fafbfc}
.u-pg-btn{padding:4px 8px;border:1px solid var(--u-border);border-radius:3px;background:var(--u-card);cursor:pointer;color:var(--u-text2);font-size:14px;line-height:1}
.u-pg-btn:hover{background:#f0f7ff}
.u-pg-btn.off{opacity:.3;pointer-events:none}

/* ═══════════════════════════════════════════════════════════
   360° PROFILE — UCRM REPLICA
   ═══════════════════════════════════════════════════════════ */
.u-profile{display:none;margin-top:0}
.u-profile.open{display:block}
.u-back{color:var(--u-text2);font-size:14px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;margin-bottom:20px;padding:6px 0;font-weight:500}
.u-back:hover{color:var(--u-text)}

/* Header card */
.u-hdr{background:var(--u-card);border:1px solid var(--u-border);border-radius:var(--u-radius);padding:24px;display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap;margin-bottom:0;border-bottom:none;border-radius:var(--u-radius) var(--u-radius) 0 0}
.u-avatar{width:72px;height:72px;border-radius:50%;background:#3949ab;color:#fff;font-size:24px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;letter-spacing:1px}
.u-hdr-info{flex:1;min-width:0}
.u-hdr-name{font-size:22px;font-weight:600;color:var(--u-text);margin-bottom:4px}
.u-hdr-sub{font-size:13px;color:var(--u-text2);margin-bottom:6px}
.u-hdr-contact{display:flex;flex-wrap:wrap;gap:16px;font-size:13px;color:var(--u-text2);margin-top:8px}
.u-hdr-contact i{margin-right:4px;font-size:12px}
.u-hdr-note{margin-top:8px;font-size:13px;color:var(--u-text2);font-style:italic;border-left:3px solid var(--u-border);padding-left:10px}
.u-hdr-bal{text-align:right;flex-shrink:0}
.u-hdr-bal-amt{font-size:28px;font-weight:700}
.u-hdr-bal-label{font-size:11px;color:var(--u-text2);text-transform:uppercase;letter-spacing:.3px}

/* Balance bar */
.u-bal-bar{display:grid;grid-template-columns:repeat(3,1fr);background:var(--u-card);border:1px solid var(--u-border);border-top:none;text-align:center}
.u-bal-bar>div{padding:16px;border-right:1px solid var(--u-border)}
.u-bal-bar>div:last-child{border-right:none}
.u-bal-label{font-size:11px;font-weight:600;color:var(--u-text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.u-bal-val{font-size:22px;font-weight:700}

/* Action bar */
.u-acts{display:flex;flex-wrap:wrap;gap:0;background:var(--u-card);border:1px solid var(--u-border);border-top:none;border-radius:0 0 var(--u-radius) var(--u-radius);padding:14px 20px;margin-bottom:20px}
.u-act{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-size:13px;font-weight:500;color:var(--u-text);cursor:pointer;text-decoration:none;border-radius:var(--u-radius);transition:background .12s}
.u-act:hover{background:rgba(1,147,215,.08)}
.u-act-primary{background:var(--u-blue);color:#fff;border-radius:var(--u-radius);margin-right:8px}
.u-act-primary:hover{background:#017ab5}

/* Stats bar */
.u-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:12px;margin-bottom:20px}
.u-stat{background:var(--u-card);border:1px solid var(--u-border);border-radius:var(--u-radius);padding:14px 8px;text-align:center}
.u-stat-v{font-size:20px;font-weight:700;margin-bottom:2px}
.u-stat-l{font-size:10px;font-weight:600;color:var(--u-text2);text-transform:uppercase;letter-spacing:.3px}

/* Two-column grid */
.u-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:900px){.u-grid{grid-template-columns:1fr}}

/* Cards */
.u-card{background:var(--u-card);border:1px solid var(--u-border);border-radius:var(--u-radius);margin-bottom:16px;overflow:hidden}
.u-card-head{padding:12px 16px;border-bottom:1px solid var(--u-border);display:flex;align-items:center;justify-content:space-between;background:#fafbfc}
.u-card-title{font-size:13px;font-weight:600;color:var(--u-text)}
.u-card-body{padding:12px 16px}
.u-card-empty{padding:24px;text-align:center;color:var(--u-text3);font-size:13px}
.u-card-link{font-size:12px;color:var(--u-blue);text-decoration:none;font-weight:500}
.u-card-link:hover{text-decoration:underline}

/* Detail rows */
.u-kv{font-size:13px}
.u-kv-row{display:flex;padding:6px 0;border-bottom:1px solid #f0f2f5}
.u-kv-row:last-child{border-bottom:none}
.u-kv-k{width:120px;flex-shrink:0;color:var(--u-text2);font-weight:500}
.u-kv-v{color:var(--u-text);font-weight:400;word-break:break-word}
.u-kv-v a{color:var(--u-blue);text-decoration:none}

/* Table rows inside cards */
.u-row{display:grid;padding:8px 0;border-bottom:1px solid #f0f2f5;font-size:13px;align-items:center}
.u-row:last-child{border-bottom:none}
.u-row-h{font-size:11px;font-weight:600;color:var(--u-text2);text-transform:uppercase;letter-spacing:.3px}
.u-svc{grid-template-columns:10px 1fr 90px}
.u-inv{grid-template-columns:90px 1fr 80px 80px}
.u-pay{grid-template-columns:90px 1fr 80px}
.u-job{grid-template-columns:90px 1fr 80px}
.u-pill{font-size:10px;font-weight:600;padding:2px 8px;border-radius:3px;display:inline-block}
.u-pill-g{background:#e8f5e9;color:#2e7d32}
.u-pill-r{background:#ffebee;color:#c62828}
.u-pill-y{background:#fff8e1;color:#f57f17}
.u-pill-b{background:#e3f2fd;color:#1565c0}
.u-pill-x{background:#f5f5f5;color:#757575}
.u-wa{padding:6px 0;border-bottom:1px solid #f0f2f5;font-size:13px}
.u-wa:last-child{border-bottom:none}
.u-wa-t{font-size:10px;color:var(--u-text3);margin-top:1px}
@keyframes u-spin{to{transform:rotate(360deg)}}
.u-spin{animation:u-spin .7s linear infinite;display:inline-block}
</style>

<div class="u-wrap">
<div class="u-page-head">
    <h1>Clients</h1>
    <div class="u-search-wrap"><i class="bi bi-search"></i><input id="clQ" class="u-search" type="text" placeholder="Search" autocomplete="off" oninput="CL.filter()" onkeydown="if(event.key==='Escape'){this.value='';CL.filter()}"></div>
</div>
<div class="u-tabs" id="clTabs">
    <div class="cl-tab u-tab active" data-f="all" onclick="CL.tab('all')">All<span class="u-tab-n">(<?= $totalAll ?>)</span></div>
    <div class="cl-tab u-tab" data-f="active" onclick="CL.tab('active')">Active clients<span class="u-tab-n">(<?= $totalActive ?>)</span></div>
    <div class="cl-tab u-tab" data-f="lead" onclick="CL.tab('lead')">Leads<span class="u-tab-n">(<?= $totalLeads ?>)</span></div>
    <div class="cl-tab u-tab" data-f="suspended" onclick="CL.tab('suspended')">Suspended<span class="u-tab-n">(<?= $totalSusp ?>)</span></div>
</div>

<div id="clTableWrap">
<div class="u-count" id="clN"></div>
<table class="u-tbl">
<thead><tr>
<th class="c-id" onclick="CL.sort('id')">ID <span class="si">▼</span></th>
<th class="c-name" onclick="CL.sort('name')">NAME <span class="si">▼</span></th>
<th class="c-bal" onclick="CL.sort('bal')">BALANCE <span class="si">▼</span></th>
<th class="c-plans">SERVICE PLANS</th>
<th class="c-org">ORGANIZATION</th>
</tr></thead>
<tbody id="clTB"></tbody>
</table>
<div class="u-pager" id="clPG"></div>
</div>

<div id="clPW" class="u-profile">
<div class="u-back" onclick="CL.back()"><i class="bi bi-arrow-left"></i> Back to list</div>
<div id="clP"></div>
</div>
</div>

<script>
(function(){
var TK='<?= $apiToken ?>',FIN=<?= $showFin?'true':'false' ?>,CRM='<?= h($crmBase) ?>';
var H={'Authorization':'Bearer '+TK,'Content-Type':'application/json'};
function api(a,q){return fetch('?page=api&action='+a+(q||''),{credentials:'same-origin',headers:H}).then(function(r){return r.json()})}
function e(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}
function d(v){return v?String(v).slice(0,10):'—'}
function $(v){return '$'+parseFloat(v||0).toFixed(2)}
function I(n){return(n||'').split(' ').map(function(w){return w[0]||''}).slice(0,2).join('').toUpperCase()||'?'}
function ta(v){if(!v)return'';var s=Math.floor((Date.now()-new Date(v).getTime())/1000);if(s<60)return s+'s';if(s<3600)return Math.floor(s/60)+'m';if(s<86400)return Math.floor(s/3600)+'h';return Math.floor(s/86400)+'d'}

var D=<?= json_encode($uIdx, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
var S={t:'all',q:'',sb:'id',sd:'desc',p:1,pp:25};

window.CL={
tab:function(t){S.t=t;S.p=1;document.querySelectorAll('.u-tab').forEach(function(x){x.classList.toggle('active',x.dataset.f===t)});this.render()},
filter:function(){S.q=document.getElementById('clQ').value.trim().toLowerCase();S.p=1;this.render()},
sort:function(c){if(S.sb===c)S.sd=S.sd==='asc'?'desc':'asc';else{S.sb=c;S.sd=c==='name'?'asc':'desc'}S.p=1;this.render()},
pg:function(p){S.p=p;this.render()},
gf:function(){
  var r=D;
  if(S.t==='active')r=r.filter(function(x){return x._st==='active'&&!x.isLead});
  if(S.t==='lead')r=r.filter(function(x){return x.isLead});
  if(S.t==='suspended')r=r.filter(function(x){return x._st==='suspended'});
  if(S.q){var w=S.q.split(/\s+/);r=r.filter(function(x){return w.every(function(v){return x.search.indexOf(v)!==-1})})}
  var c=S.sb,dr=S.sd==='asc'?1:-1;
  r.sort(function(a,b){var va=a[c]||'',vb=b[c]||'';if(c==='id'||c==='bal'){va=parseFloat(va)||0;vb=parseFloat(vb)||0;return(va-vb)*dr}return String(va).localeCompare(String(vb))*dr});
  return r;
},
render:function(){
  var r=this.gf(),tot=r.length,pgs=Math.max(1,Math.ceil(tot/S.pp));
  if(S.p>pgs)S.p=pgs;
  var s0=(S.p-1)*S.pp,sl=r.slice(s0,s0+S.pp);
  document.getElementById('clN').textContent=tot+' client'+(tot!==1?'s':'');

  var h='';
  sl.forEach(function(r){
    var bc=r.bal<0?'var(--u-red)':r.bal>0?'var(--u-green)':'var(--u-text2)';
    var dc=r._st==='active'?'var(--u-green)':r._st==='suspended'?'var(--u-red)':'var(--u-yellow)';
    h+='<tr onclick="CL.open('+r.id+')">';
    h+='<td class="c-id">'+r.id+'</td>';
    h+='<td class="c-name"><span class="u-dot" style="background:'+dc+'"></span><span class="nm">'+e(r.name)+'</span>';
    if(r.username)h+=' <span class="un">('+e(r.username)+')</span>';
    if(r.tags&&r.tags.length){r.tags.slice(0,2).forEach(function(t){h+='<span class="u-tag">'+e(t)+'</span>'})}
    h+='</td>';
    h+='<td class="c-bal" style="color:'+bc+'">'+$(r.bal)+'</td>';
    h+='<td class="c-plans">'+(r.plans?e(r.plans):'<span class="u-dash">——</span>')+'</td>';
    h+='<td class="c-org">'+e(r.org)+'</td>';
    h+='</tr>';
  });
  document.getElementById('clTB').innerHTML=h||'<tr><td colspan="5" style="text-align:center;padding:40px;color:var(--u-text3)">No clients match</td></tr>';

  // Sort indicators
  document.querySelectorAll('.u-tbl th').forEach(function(th){
    try{var c=th.onclick.toString().match(/'(\w+)'/)[1];th.classList.toggle('sorted',c===S.sb);var i=th.querySelector('.si');if(i)i.textContent=(c===S.sb&&S.sd==='asc')?'▲':'▼'}catch(ex){}
  });

  // Pager
  var ph=s0+1+'-'+Math.min(s0+S.pp,tot)+' of '+tot+' ';
  ph+='<span class="u-pg-btn'+(S.p<=1?' off':'')+'" onclick="CL.pg('+(S.p-1)+')">‹</span> ';
  ph+='<span class="u-pg-btn'+(S.p>=pgs?' off':'')+'" onclick="CL.pg('+(S.p+1)+')">›</span>';
  document.getElementById('clPG').innerHTML=ph;
},

// ═══════════════════════════════════════════
// 360° PROFILE
// ═══════════════════════════════════════════
open:function(cid){
  document.getElementById('clTableWrap').style.display='none';
  var w=document.getElementById('clPW');w.style.display='';w.className='u-profile open';
  var P=document.getElementById('clP');
  P.innerHTML='<div style="padding:80px;text-align:center;color:var(--u-text2)"><i class="bi bi-arrow-repeat u-spin" style="font-size:28px;display:block;margin-bottom:12px"></i>Loading…</div>';
  api('customer_360','&cid='+cid).then(function(r){if(r.status!=='success'){P.innerHTML='<div class="u-card"><div class="u-card-body" style="color:var(--u-red)">'+e(r.message||'Error')+'</div></div>';return}R(r.data,cid)}).catch(function(){P.innerHTML='<div class="u-card"><div class="u-card-body" style="color:var(--u-red)">Network error</div></div>'})
},
back:function(){
  document.getElementById('clPW').style.display='none';
  document.getElementById('clPW').className='u-profile';
  document.getElementById('clTableWrap').style.display='';
}
};

// ═══════════════════════════════════════════
// RENDER 360° — UCRM-REPLICA
// ═══════════════════════════════════════════
function R(D,cid){
var c=D.client||{},sv=D.services||[],iv=D.invoices||[],py=D.payments||[],qt=D.quotes||[],jb=D.jobs||[],tk=D.tickets||[],ky=D.kyc_apps||[],wC=D.wa_convs||[],wM=D.wa_messages||[],Z=D.summary||{};
var nm=((c.firstName||'')+' '+(c.lastName||'')).trim()||c.companyName||'#'+cid;
var ph='',em='',ad='';
(c.contacts||[]).forEach(function(t){if(!ph&&t.phone)ph=t.phone;if(!em&&t.email)em=t.email});
if(!ph)ph=c.phone||'';if(!em)em=c.email||'';
ad=((c.street1||'')+', '+(c.city||'')).replace(/^,\s*/,'').replace(/,\s*$/,'')||'';
var bl=parseFloat(c.accountBalance||c.accountStandingsBalance||0);
var tg=(c.tags||[]).map(function(t){return t.name||t});
var nt=c.note||'',un=c.username||'';
var O='';

// ── HEADER (UCRM replica) ──
O+='<div class="u-hdr">';
O+='<div class="u-avatar">'+I(nm)+'</div>';
O+='<div class="u-hdr-info">';
O+='<div class="u-hdr-name">'+e(nm)+'</div>';
O+='<div class="u-hdr-sub">CRM #'+cid+(un?' · '+e(un):'')+'</div>';
if(tg.length){tg.forEach(function(t){O+='<span class="u-tag">'+e(t)+'</span>'})}
O+='<div class="u-hdr-contact">';
if(ph)O+='<span><i class="bi bi-telephone"></i>'+e(ph)+'</span>';
if(em)O+='<span><i class="bi bi-envelope"></i>'+e(em)+'</span>';
if(ad)O+='<span><i class="bi bi-geo-alt"></i>'+e(ad)+'</span>';
O+='</div>';
if(nt)O+='<div class="u-hdr-note">'+e(nt)+'</div>';
O+='</div>';
if(FIN){var bc2=bl<0?'var(--u-red)':bl>0?'var(--u-green)':'var(--u-text2)';O+='<div class="u-hdr-bal"><div class="u-hdr-bal-amt" style="color:'+bc2+'">'+$(bl)+'</div><div class="u-hdr-bal-label">account balance</div></div>'}
O+='</div>';

// ── BALANCE BAR ──
if(FIN){O+='<div class="u-bal-bar"><div><div class="u-bal-label">Account Balance</div><div class="u-bal-val" style="color:'+(bl>=0?'var(--u-green)':'var(--u-red)')+'">'+$(bl)+'</div></div><div><div class="u-bal-label">Credit</div><div class="u-bal-val" style="color:var(--u-green)">'+$(Math.max(0,bl))+'</div></div><div><div class="u-bal-label">Outstanding</div><div class="u-bal-val" style="color:'+(Z.total_owed>0?'var(--u-red)':'var(--u-text2)')+'">'+$(Z.total_owed||0)+'</div></div></div>'}

// ── ACTIONS ──
O+='<div class="u-acts">';
if(CRM)O+='<a href="'+CRM+'/crm/client/'+cid+'" target="_blank" class="u-act u-act-primary"><i class="bi bi-box-arrow-up-right"></i> Open in UCRM</a>';
O+='<a href="?page=dashboard&tab=collect_payment&client_id='+cid+'" class="u-act"><i class="bi bi-cash-coin"></i> Collect Payment</a>';
O+='<a href="?page=dashboard&tab=send_quote&client_id='+cid+'" class="u-act"><i class="bi bi-file-earmark-text"></i> Send Quote</a>';
if(wC.length)O+='<a href="?page=dashboard&tab=wa_inbox&conv_id='+wC[0].id+'" class="u-act"><i class="bi bi-whatsapp"></i> WhatsApp</a>';
O+='</div>';

// ── STATS ──
O+='<div class="u-stats">';
[{v:Z.active_svcs||0,l:'Services',c:'var(--u-blue)'},{v:Z.open_invoices||0,l:'Open Inv',c:Z.open_invoices?'var(--u-red)':'var(--u-text3)'},{v:Z.total_payments||0,l:'Payments',c:'var(--u-green)'},{v:Z.total_quotes||0,l:'Quotes',c:'#7c3aed'},{v:Z.total_tickets||0,l:'Tickets',c:'var(--u-blue)'},{v:Z.wa_threads||0,l:'WA',c:Z.wa_unread?'var(--u-red)':'var(--u-text3)'},{v:Z.total_jobs||0,l:'Jobs',c:'var(--u-yellow)'},{v:Z.kyc_count||0,l:'KYC',c:'#0ea5e9'}].forEach(function(s){O+='<div class="u-stat"><div class="u-stat-v" style="color:'+s.c+'">'+s.v+'</div><div class="u-stat-l">'+s.l+'</div></div>'});
O+='</div>';

// ── TWO-COLUMN ──
O+='<div class="u-grid"><div>';

// Services
O+='<div class="u-card"><div class="u-card-head"><span class="u-card-title">Services ('+sv.length+')</span></div>';
if(sv.length){O+='<div class="u-card-body">';sv.forEach(function(s){var st=s.status===1?{c:'var(--u-green)',l:'Active'}:s.status===5?{c:'var(--u-red)',l:'Suspended'}:{c:'var(--u-text3)',l:'Other'};O+='<div class="u-row u-svc"><div class="u-dot" style="background:'+st.c+'"></div><div><b>'+e(s.servicePlanName||'#'+s.id)+'</b></div><div style="text-align:right"><span class="u-pill" style="background:'+st.c+'18;color:'+st.c+'">'+st.l+'</span></div></div>'});O+='</div>'}else{O+='<div class="u-card-empty">No services</div>'}
O+='</div>';

// Invoices
if(FIN&&iv.length){O+='<div class="u-card"><div class="u-card-head"><span class="u-card-title">Invoices ('+iv.length+')</span>'+(CRM?'<a href="'+CRM+'/crm/client/'+cid+'/invoices" target="_blank" class="u-card-link">View all</a>':'')+'</div><div class="u-card-body">';
iv.slice(0,6).forEach(function(i){var du=Math.max(0,parseFloat(i.total||0)-parseFloat(i.amountPaid||0));var sp=i.status===3?'u-pill-g':i.status===1?'u-pill-r':i.status===2?'u-pill-y':'u-pill-x';var sl=i.status===3?'Paid':i.status===1?'Unpaid':i.status===2?'Partial':'Other';O+='<div class="u-row u-inv"><span>'+d(i.createdDate)+'</span><span><b>'+e(i.number||'#'+i.id)+'</b> <span class="u-pill '+sp+'">'+sl+'</span></span><span style="text-align:right">'+$(i.total)+'</span><span style="text-align:right;font-weight:600;color:'+(du>0?'var(--u-red)':'var(--u-green)')+'">'+$(du)+'</span></div>'});
O+='</div></div>'}

// Jobs
O+='<div class="u-card"><div class="u-card-head"><span class="u-card-title">Jobs ('+jb.length+')</span></div>';
if(jb.length){O+='<div class="u-card-body">';jb.slice(0,5).forEach(function(j){O+='<div class="u-row u-job"><span>'+d(j.date||j.dateFrom)+'</span><span><b>'+e(j.title||'#'+j.id)+'</b></span><span style="text-align:right"><span class="u-pill u-pill-b">'+e(j.status||'—')+'</span></span></div>'});O+='</div>'}else{O+='<div class="u-card-empty">All jobs finished</div>'}
O+='</div>';

// WhatsApp
O+='<div class="u-card"><div class="u-card-head"><span class="u-card-title">WhatsApp ('+(Z.wa_threads||0)+')</span>';
if(Z.wa_unread)O+='<span class="u-pill u-pill-r">'+Z.wa_unread+' unread</span>';
O+='</div>';
if(wM.length){O+='<div class="u-card-body" style="max-height:200px;overflow-y:auto">';wM.forEach(function(m){var ii=m.direction==='in';O+='<div class="u-wa"><div style="font-weight:'+(ii?'600':'400')+'">'+(ii?'← ':'→ ')+e((m.body||'').substring(0,120))+'</div><div class="u-wa-t">'+ta(m.sent_at||m.created_at)+' · '+(m.role||m.direction)+'</div></div>'});O+='</div>';if(wC.length)O+='<div style="padding:10px 16px;border-top:1px solid var(--u-border)"><a href="?page=dashboard&tab=wa_inbox&conv_id='+wC[0].id+'" class="u-card-link">Open full inbox →</a></div>'}else{O+='<div class="u-card-empty">No messages</div>'}
O+='</div>';

O+='</div><div>';

// Details
O+='<div class="u-card"><div class="u-card-head"><span class="u-card-title">Details</span>'+(CRM?'<a href="'+CRM+'/crm/client/'+cid+'/edit" target="_blank" class="u-card-link">✎ Edit</a>':'')+'</div><div class="u-card-body"><div class="u-kv">';
var kv=[['ID',cid],['Name',nm]];
if(em)kv.push(['Email','<a href="mailto:'+e(em)+'">'+e(em)+'</a>']);
if(ph)kv.push(['Phone',e(ph)]);
if(un)kv.push(['Username',e(un)]);
if(ad)kv.push(['Address',e(ad)]);
kv.push(['Registered',d(c.registrationDate||c.createdDate||'')]);
if(c.organizationName)kv.push(['Organization',e(c.organizationName)]);
(c.attributes||[]).forEach(function(a){var v=a.value||'';if(!v||v==='0')return;kv.push([e(a.key||a.name||''),e(v)])});
kv.forEach(function(r){O+='<div class="u-kv-row"><div class="u-kv-k">'+r[0]+'</div><div class="u-kv-v">'+r[1]+'</div></div>'});
O+='</div></div></div>';

// Payments
if(FIN&&py.length){O+='<div class="u-card"><div class="u-card-head"><span class="u-card-title">Payments ('+py.length+')</span>'+(CRM?'<a href="'+CRM+'/crm/client/'+cid+'/payments" target="_blank" class="u-card-link">View all</a>':'')+'</div><div class="u-card-body">';
py.slice(0,6).forEach(function(p){O+='<div class="u-row u-pay"><span>'+d(p.createdDate)+'</span><span>'+e(p.method||'Cash')+'</span><span style="text-align:right;font-weight:600;color:var(--u-green)">'+$(p.amount)+'</span></div>'});
O+='</div></div>'}

// KYC
if(ky.length){O+='<div class="u-card"><div class="u-card-head"><span class="u-card-title">KYC Applications ('+ky.length+')</span></div><div class="u-card-body">';ky.forEach(function(a){O+='<div style="padding:6px 0;border-bottom:1px solid #f0f2f5;font-size:13px;display:flex;justify-content:space-between"><span>#'+e(a.id)+' · '+e(a.connectivity_type||'')+'</span><span class="u-pill '+(a.status==='approved'?'u-pill-g':'u-pill-y')+'">'+e(a.status||'')+'</span></div>'});O+='</div></div>'}

// Quotes — with Send WA + PDF download
if(qt.length){O+='<div class="u-card"><div class="u-card-head"><span class="u-card-title">Quotes ('+qt.length+')</span>'+(CRM?'<a href="'+CRM+'/crm/client/'+cid+'/proforma-invoices" target="_blank" class="u-card-link">View all</a>':'')+'</div><div class="u-card-body">';
qt.slice(0,8).forEach(function(q){
  var qid=q.id||0,qn=q.number||'#'+qid,qTotal=$(q.total||0);
  var sp=q.status===2?'u-pill-g':q.status===0?'u-pill-x':q.status===1?'u-pill-b':'u-pill-y';
  var sl=q.status===2?'Accepted':q.status===0?'Draft':q.status===1?'Sent':'Other';
  O+='<div style="padding:8px 0;border-bottom:1px solid #f0f2f5;font-size:13px">';
  O+='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">';
  O+='<span><b>'+e(qn)+'</b> <span class="u-pill '+sp+'">'+sl+'</span></span>';
  O+='<span style="font-weight:700">'+qTotal+'</span>';
  O+='</div>';
  O+='<div style="display:flex;gap:8px;margin-top:4px">';
  if(CRM)O+='<a href="'+CRM+'/crm/client/quote/'+qid+'" target="_blank" style="font-size:11px;color:var(--u-blue);text-decoration:none;font-weight:500"><i class="bi bi-eye"></i> View</a>';
  O+='<a href="#" data-qsend="'+qid+'" onclick="sendQuoteWA('+qid+',\''+e(ph)+'\','+cid+');return false" style="font-size:11px;color:#25D366;text-decoration:none;font-weight:600"><i class="bi bi-whatsapp"></i> Send via WhatsApp</a>';
  if(CRM)O+='<a href="'+CRM+'/crm/billing/quotes/'+qid+'/pdf" target="_blank" style="font-size:11px;color:var(--u-text2);text-decoration:none;font-weight:500"><i class="bi bi-file-earmark-pdf"></i> PDF</a>';
  O+='</div></div>';
});
O+='</div></div>'}

// Tickets
if(tk.length){O+='<div class="u-card"><div class="u-card-head"><span class="u-card-title">Tickets ('+tk.length+')</span></div><div class="u-card-body">';tk.slice(0,5).forEach(function(t){O+='<div style="padding:6px 0;border-bottom:1px solid #f0f2f5;font-size:13px;display:flex;justify-content:space-between"><span>#'+e(t.id)+' '+e(t.subject||t.title||'')+'</span><span class="u-pill '+(t.status==='open'?'u-pill-b':'u-pill-g')+'">'+e(t.status||'')+'</span></div>'});O+='</div></div>'}

O+='</div></div>';
document.getElementById('clP').innerHTML=O;
}

document.addEventListener('keydown',function(ev){if((ev.ctrlKey||ev.metaKey)&&ev.key==='k'){ev.preventDefault();document.getElementById('clQ').focus()}});

// ── Send Quote via WhatsApp ──
window.sendQuoteWA=function(quoteId,phone,clientId){
  if(!phone){alert('No phone number for this client.');return}
  if(!confirm('Send Quote #'+quoteId+' via WhatsApp to '+phone+'?'))return;
  var el=document.querySelector('[data-qsend="'+quoteId+'"]');
  if(el){el.innerHTML='<i class="bi bi-hourglass-split"></i> Sending...';el.style.color='var(--u-text3)'}
  fetch('?page=api&action=wa_send_quote_pdf',{
          credentials:'same-origin',
          method:'POST',
    headers:H,
    body:JSON.stringify({crm_quote_id:quoteId,phone:phone,cc_admin:true})
  }).then(function(r){return r.json()}).then(function(r){
    if(r.status==='success'){
      var method=r.data&&r.data.method==='text_message'?'(text)':'(PDF)';
      if(el){el.innerHTML='<i class="bi bi-check-circle"></i> Sent! '+method;el.style.color='var(--u-green)'}
    }else{
      if(el){el.innerHTML='<i class="bi bi-exclamation-triangle"></i> Failed';el.style.color='var(--u-red)'}
      alert('Failed: '+(r.message||'Unknown error'));
    }
  }).catch(function(err){
    if(el){el.innerHTML='<i class="bi bi-exclamation-triangle"></i> Error';el.style.color='var(--u-red)'}
    alert('Network error: '+err.message);
  });
};

CL.render();
})();
</script>
