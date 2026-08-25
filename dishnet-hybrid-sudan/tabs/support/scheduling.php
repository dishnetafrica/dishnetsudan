<?php
// Tab: scheduling
// Extracted from public.php on 2026-03-15
        $myUcrmId   = (int)($retailer['ucrm_user_id'] ?? 0);
        $apiToken   = h($retailer['api_token'] ?? '');
        $jobDetailId = (int)($_GET['job'] ?? 0);
    ?>

<style>
.sch-hero{background:linear-gradient(135deg,#0D47A1,#1976D2);border-radius:20px;padding:20px;color:#fff;margin-bottom:16px;}
/* Dark-theme job cards matching DishNet field app */
.sch-job{background:#1e293b;border-radius:12px;padding:1rem;margin-bottom:10px;box-shadow:0 4px 12px rgba(0,0,0,.3);cursor:pointer;transition:.15s;border:1px solid #334155;}
.sch-job:hover{box-shadow:0 6px 20px rgba(0,0,0,.4);transform:translateY(-1px);}
.sch-job-header{display:flex;flex-direction:column;gap:0.4rem;}
.sch-job-title{font-size:1rem;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sch-job-meta{display:flex;flex-wrap:wrap;font-size:0.85rem;color:#94a3b8;gap:.4rem 1rem;}
.sch-job-footer{margin-top:0.6rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;font-size:0.85rem;color:#94a3b8;}
.sch-status{display:inline-block;padding:.2rem .7rem;border-radius:20px;font-weight:700;font-size:0.75rem;text-transform:capitalize;white-space:nowrap;flex-shrink:0;}
.sch-status.open{background:#facc15;color:#0f1724;}
.sch-status.pending{background:#3b82f6;color:#fff;}
.sch-status.closed{background:#22c55e;color:#fff;}
.sch-section-hdr{display:flex;align-items:center;justify-content:space-between;cursor:pointer;padding:10px 14px;border-radius:12px;margin-bottom:6px;}
.sch-svc-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;flex-shrink:0;}
.sch-task{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;margin-bottom:4px;background:#0f1724;}
.sch-task.done{opacity:.6;}
.sch-task.done span{text-decoration:line-through;color:#64748b;}
.sch-map-btn{padding:14px 16px;border-radius:14px;font-size:13px;font-weight:700;border:none;cursor:pointer;display:flex;align-items:center;gap:10px;text-decoration:none;width:100%;box-sizing:border-box;margin-bottom:8px;}
/* Detail overlay — full screen on mobile */
#schDetailOverlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:3000;overflow-y:auto;}
#schDetailPanel{background:#f8fafc;max-width:560px;margin:0 auto;min-height:100vh;position:relative;padding-top:max(20px,calc(env(safe-area-inset-top) + 12px));}
@media(min-width:600px){#schDetailPanel{margin:20px auto;border-radius:20px;min-height:auto;overflow:hidden;padding-top:0;}}
/* Survey section */
.srv-card{background:#1e293b;border-radius:14px;padding:14px 16px;margin-bottom:10px;}
.srv-title{font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.srv-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-bottom:8px;}
.srv-opt{border:2px solid #e2e8f0;border-radius:10px;padding:8px 6px;text-align:center;cursor:pointer;font-size:12px;font-weight:700;transition:.15s;background:#f8fafc;color:#6b7280;}
.srv-opt.sel-yes{border-color:#28a745;background:#E8F5E9;color:#2E7D32;}
.srv-opt.sel-no{border-color:#dc3545;background:#FFEBEE;color:#C62828;}
.srv-opt.sel-partial{border-color:#FF9800;background:#FFF3E0;color:#E65100;}
.srv-opt.sel-feasible{border-color:#28a745;background:#E8F5E9;color:#2E7D32;}
.srv-opt.sel-conditional{border-color:#FF9800;background:#FFF3E0;color:#E65100;}
.srv-opt.sel-not_feasible{border-color:#dc3545;background:#FFEBEE;color:#C62828;}
.srv-inp{width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 12px;font-size:13px;box-sizing:border-box;background:#f8fafc;margin-top:6px;}
.srv-inp:focus{outline:none;border-color:#1565C0;background:#fff;}
/* Action buttons */
.sch-act-btn{display:flex;align-items:center;justify-content:center;gap:8px;padding:16px 14px;border-radius:14px;font-size:14px;font-weight:800;border:none;cursor:pointer;width:100%;box-sizing:border-box;margin-bottom:10px;transition:.15s;min-height:52px;-webkit-tap-highlight-color:transparent;}
.sch-act-btn:active{transform:scale(.97);opacity:.9;}
.srv-inp{width:100%;background:#0f1724;color:#e2e8f0;border:1px solid #334155;border-radius:8px;padding:8px 10px;font-size:13px;box-sizing:border-box;margin-top:4px;}
.srv-inp::placeholder{color:#475569;}
.srv-opt{padding:8px 4px;border-radius:8px;font-size:12px;font-weight:700;border:1px solid #334155;background:#0f1724;color:#94a3b8;cursor:pointer;transition:.15s;}
.srv-opt:hover{border-color:#3b82f6;color:#e2e8f0;}
.srv-opt.sel-yes,.srv-opt.sel-feasible{background:#166534;color:#4ade80;border-color:#166534;}
.srv-opt.sel-no,.srv-opt.sel-not_feasible{background:#7f1d1d;color:#fca5a5;border-color:#7f1d1d;}
.srv-opt.sel-partial,.srv-opt.sel-conditional{background:#713f12;color:#fbbf24;border-color:#713f12;}
.sch-task{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;margin-bottom:4px;background:#0f1724;}
.sch-task.done{opacity:.5;}
.sch-task.done span{text-decoration:line-through;color:#475569;}
</style>

<?php
// ── Auto-map ucrm_user_id from seed table (Laravel DB source of truth) ──────
// Confirmed UCRM staff user IDs
// Source: UCRM agenda URL filter params (assigned_user[]=X) — the ground truth
$_ucrmSeedMap = [
    // ── Full staff map — source: Laravel DB users table (login = UCRM staff user ID) ──
    'bhavin@dishnetafrica.com'            => 1,      // Bhavin Madlani
    'hardik@dishnetafrica.com'            => 1124,   // Hardik Parmar
    'mohini.madlani@outlook.com'          => 1078,   // Mohini Madlani
    'accounts@dishnetafrica.com'          => 1929,   // Rupesh (primary email)
    'rupesh@dishnetafrica.com'            => 1929,   // Rupesh (secondary email)
    'dishnetafrica@gmail.com'             => 1224,   // Diko Jeseka (field accountant)
    'atulsmahale007@gmail.com'            => 1368,   // Atul Mahale
    'atul@dishnetafrica.com'              => 1368,   // Atul (alt email)
    'noc@dishnetafrica.com'               => 1400,   // Francis DishNet
    'emmanuellukudualphon@gmail.com'      => 1401,   // Emmanuel DishNet
    'nirav@dishnetss.com'                 => 1548,   // Nirav Panchamatiya
    'madlanib@gmail.com'                  => 1581,   // Madlani
    'vivekbhatt17@gmail.com'              => 1584,   // Vivek
    'vivek.dishnetafrica@outlook.com'     => 1585,   // Vivek DishNet
    'wmensona@gmail.com'                  => 1703,   // Bidal DishNet
    'kamjay285@gmail.com'                 => 1705,   // Kamanda James Amos
    'sokiris744@gmail.com'                => 1718,   // Sokiri DishNet
    'timelessjo30@gmail.com'              => 1729,   // Joel DishNet
    'justus@dishnetss.com'                => 1927,   // Justus DishNet
    'aida@dishnetss.com'                  => 1969,   // Aida DishNet
    'meckylinea@dishnetss.com'            => 1971,   // Meckylinea DishNet
    'amos@dishnetss.com'                  => 1991,   // Amos DishNet
    'ochiti@dishnetss.com'                => 1993,   // Ochiti DishNet
    'deepsolanki7799@gmail.com'           => 2015,   // Deep DishNet
    'geoffrey@dishnetss.com'              => 2024,   // Geoffrey DishNet
    'karan@dishnetss.com'                 => 2026,   // Karan DishNet
    'dhaval@dishnetss.com'                => 2027,   // Dhaval DishNet
    'thomas@dishnetss.com'                => 2028,   // Thomas DishNet
    'tabule@dishnetss.com'                => 2029,   // Tabule DishNet
];
$_rEmail = strtolower(trim($retailer['email'] ?? ''));
// Auto-map OR correct a wrong mapping
$_correctId = $_ucrmSeedMap[$_rEmail] ?? null;
if ($_correctId && (int)($myUcrmId) !== (int)$_correctId) {
    $myUcrmId = (int)$_correctId;
    $store->updateOne('retailers.json', 'id', (int)$retailer['id'], ['ucrm_user_id' => $myUcrmId]);
    $retailer['ucrm_user_id'] = $myUcrmId;
}
?>
<?php if (!$myUcrmId): ?>
<!-- Not linked and not in seed map — show manual picker -->
<div id="schMapBox" style="background:#FFF3E0;border-radius:16px;padding:24px;text-align:center;border:2px dashed #FFB300;">
    <div style="font-size:32px;margin-bottom:12px;">🔗</div>
    <div style="font-size:16px;font-weight:800;color:#E65100;margin-bottom:8px;">UCRM Account Not Linked</div>
    <div style="font-size:13px;color:#6b7280;max-width:340px;margin:0 auto 16px;">
        Your email <strong><?= h($_rEmail) ?></strong> was not found in the staff directory.<br>Ask admin to set your UCRM User ID manually in Manage Retailers.
    </div>
    <?php if ($isAdmin): ?>
    <a href="?page=dashboard&tab=retailers" style="display:inline-block;background:#1565C0;color:#fff;padding:10px 20px;border-radius:10px;font-weight:700;text-decoration:none;">Go to Manage Retailers</a>
    <?php endif; ?>
</div>
<?php else: ?>

<?php if ($jobDetailId): ?>
<!-- ═══════════════════════════════════════════════════════════
     JOB DETAIL PAGE VIEW (full page, no overlay)
     Loaded when ?tab=scheduling&job=ID
     ═══════════════════════════════════════════════════════════ -->
<div style="background:#0f1724;border-radius:16px;padding:0;min-height:60vh;">
    <!-- Back button -->
    <div style="padding:12px 14px;border-bottom:1px solid #1e293b;">
        <a href="?page=dashboard&tab=scheduling" style="display:inline-flex;align-items:center;gap:6px;color:#94a3b8;text-decoration:none;font-size:13px;font-weight:700;">
            ← Back to My Jobs
        </a>
    </div>
    <div id="schJobDetailPage" style="padding:14px;">
        <div style="text-align:center;padding:40px;color:#94a3b8;">
            <span style="font-size:32px;display:block;margin-bottom:8px;">⏳</span>Loading…
        </div>
    </div>
</div>
<script>
(function(){
var TOKEN   = '<?= $apiToken ?>';
var headers = {'Authorization':'Bearer '+TOKEN,'Content-Type':'application/json'};
var jobId   = <?= $jobDetailId ?>;
function apiGet(action,qs){return fetch('?page=api&action='+action+(qs||''),{credentials:'same-origin',headers:headers}).then(function(r){return r.json();}); }
function apiPost(action,body){ return fetch('?page=api&action='+action,{
          credentials:'same-origin',
          method:'POST',headers:headers,body:JSON.stringify(body)}).then(function(r){return r.json();}); }
function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function isClosed(j){return j.status===2||j.status==='closed';}
function statusBadge(s){
    var labels={0:'Pending',1:'Open',2:'Closed'};
    var cls=(s===2||s==='closed')?'closed':(s===0||s==='pending')?'pending':'open';
    return '<span class="sch-status '+cls+'">'+( labels[s]||s )+'</span>';
}
var STATUS_LABEL={0:'Pending',1:'Open',2:'Closed'};
var SVC_STATUS={1:['Active','#22c55e'],2:['Ended','#6b7280'],3:['Suspended','#dc3545'],4:['Prepared','#f59e0b'],5:['Quoted','#a855f7']};
var INV_STATUS={1:['Unpaid','#dc3545'],2:['Partial','#f59e0b']};
var _surveyState={};
var _currentJobId=0;
var _currentClientId=0;

function schSurveyRow(label,field,opts){
    var h='<div style="font-size:12px;font-weight:700;color:#cbd5e1;margin-bottom:6px;">'+label+'</div>';
    h+='<div style="display:grid;grid-template-columns:repeat('+opts.length+',1fr);gap:6px;margin-bottom:8px;">';
    opts.forEach(function(o){
        var sel=(_surveyState[field]===o[0])?' sel-'+o[0]:'';
        h+='<button class=\"srv-opt'+sel+'\" data-f=\"'+o[0]+'\" data-g=\"'+field+'\" onclick=\"(function(b){_surveyState[b.getAttribute(\'data-g\')]=b.getAttribute(\'data-f\');b.parentNode.querySelectorAll(\'.srv-opt\').forEach(function(x){x.className=x.className.replace(/\\bsel-\\S+/g,\'\').trim();});b.className+=\' sel-\'+b.getAttribute(\'data-f\');})(this)\">'+o[1]+'</button>';
    });
    h+='</div>';
    return h;
}

apiGet('scheduling_job_detail','&job_id='+jobId).then(function(resp){
    var el=document.getElementById('schJobDetailPage');
    if(resp.status!=='success'){
        el.innerHTML='<div style="padding:20px;color:#dc3545;text-align:center;">⚠ '+(resp.message||'Failed to load')+'</div>';
        return;
    }
    var data=resp.data;
    var job=data.job||{};
    var cl=data.client||{};
    var tasks=data.tasks||[];
    var gps=data.gps;
    var existingSurvey=data.survey||null;
    _currentJobId=job.id;
    _currentClientId=cl.id||0;
    _surveyState=existingSurvey?Object.assign({},existingSurvey):{poles_available:'unknown',fiber_available:'unknown',feasibility:'feasible',ap_needed:false,ap_count:0};

    var addr=(job.address||cl.address||'').trim();
    var title=escHtml(job.title||addr||'Job #'+job.id);
    var isSurvey=((job.title||'').toLowerCase().indexOf('survey')!==-1||job.jobType==='survey');
    var closed=isClosed(job);
    var dateStr=job.date?new Date(job.date).toLocaleString('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}):'';
    var dur=job.duration?job.duration+' min':'';
    var progressW=job.status===0?'33%':job.status===1?'66%':'100%';
    var progressC=job.status===0?'#3b82f6':job.status===1?'#facc15':'#22c55e';
    var card='background:#1e293b;border-radius:12px;padding:1rem;margin-bottom:12px;';

    var html='';

    // Header
    html+='<div style="background:linear-gradient(135deg,#0D47A1,#1976D2);border-radius:12px;padding:16px 20px;color:#fff;margin-bottom:12px;">';
    html+='<div style="font-size:10px;opacity:.6;text-transform:uppercase;font-weight:700;letter-spacing:.8px;">Job #'+job.id+'</div>';
    html+='<div style="font-size:18px;font-weight:800;margin-top:4px;line-height:1.3;">'+title+'</div>';
    if(job.description) html+='<div style="font-size:11px;opacity:.75;margin-top:4px;">'+escHtml(job.description)+'</div>';
    html+='</div>';

    // Status + progress
    html+='<div style="'+card+'">';
    html+='<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">';
    html+='<span style="font-size:13px;font-weight:700;color:#e2e8f0;">Status</span>';
    html+=statusBadge(job.status);
    html+='</div>';
    html+='<div style="background:#334155;border-radius:8px;height:10px;overflow:hidden;">';
    html+='<div style="background:'+progressC+';height:100%;width:'+progressW+';border-radius:8px;transition:.4s;"></div>';
    html+='</div></div>';

    // Details
    html+='<div style="'+card+'">';
    html+='<div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;">Details</div>';
    if(cl.name||(cl.firstName)){var cn=cl.name||((cl.firstName||'')+' '+(cl.lastName||'')).trim();html+='<div style="margin-bottom:8px;"><span style="color:#94a3b8;font-size:12px;">Client</span><div style="color:#e2e8f0;font-weight:700;">'+escHtml(cn)+(cl.id?' <span style="color:#64748b;font-size:11px;">(#'+cl.id+')</span>':'')+'</div></div>';}
    if(dateStr) html+='<div style="margin-bottom:8px;"><span style="color:#94a3b8;font-size:12px;">Date &amp; Time</span><div style="color:#e2e8f0;">📅 '+dateStr+'</div></div>';
    if(dur)     html+='<div style="margin-bottom:8px;"><span style="color:#94a3b8;font-size:12px;">Duration</span><div style="color:#e2e8f0;">⏱ '+dur+'</div></div>';
    if(addr)    html+='<div style="margin-bottom:8px;"><span style="color:#94a3b8;font-size:12px;">Address</span><div style="color:#e2e8f0;">📍 '+escHtml(addr)+'</div></div>';
    html+='</div>';

    // Navigate + contact
    if(gps||addr){
        var mapsUrl=gps?'https://www.google.com/maps/dir/?api=1&destination='+gps.lat+','+gps.lon:'https://www.google.com/maps/search/?api=1&query='+encodeURIComponent(addr);
        html+='<a href="'+mapsUrl+'" target="_blank" class="sch-map-btn" style="background:linear-gradient(135deg,#1B5E20,#2E7D32);color:#fff;">';
        html+='<span style="font-size:20px;">🗺</span><div style="flex:1;"><div style="font-size:13px;font-weight:800;">Navigate to Site</div>';
        html+='<div style="font-size:11px;opacity:.8;">'+escHtml(addr||'Open in Maps')+'</div></div><span>→</span></a>';
    }
    if(cl.phone){
        var callUrl='tel:'+cl.phone.replace(/\s/g,'');
        var waUrl='https://wa.me/'+cl.phone.replace(/[^0-9]/g,'');
        html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">';
        html+='<a href="'+callUrl+'" class="sch-act-btn" style="background:#22c55e;color:#fff;margin:0;text-decoration:none;">📞 Call</a>';
        html+='<a href="'+waUrl+'" target="_blank" class="sch-act-btn" style="background:#22c55e;color:#fff;margin:0;text-decoration:none;">💬 WhatsApp</a>';
        html+='</div>';
    }

    // Tasks
    if(tasks.length){
        var doneCnt=tasks.filter(function(t){return t.closed;}).length;
        html+='<div style="'+card+'">';
        html+='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;"><span style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">TASKS</span><span style="font-size:12px;color:#64748b;">'+doneCnt+'/'+tasks.length+' done</span></div>';
        html+='<div style="background:#334155;border-radius:6px;height:6px;margin-bottom:10px;overflow:hidden;"><div style="background:#22c55e;height:100%;width:'+Math.round(doneCnt/tasks.length*100)+'%;border-radius:6px;"></div></div>';
        tasks.forEach(function(t){
            html+='<div class="sch-task'+(t.closed?' done':'')+'" id="schTask_'+t.id+'">';
            html+='<input type="checkbox"'+(t.closed?' checked':'')+' onchange="schToggleTask('+t.id+',this.checked,'+job.id+')" style="width:20px;height:20px;cursor:pointer;flex-shrink:0;accent-color:#22c55e;">';
            html+='<span style="font-size:14px;color:#e2e8f0;">'+escHtml(t.name||t.title||'Task #'+t.id)+'</span></div>';
        });
        html+='</div>';
    }

    // Survey (only for survey jobs)
    if(isSurvey){
        html+='<div style="'+card+'border:2px solid #1e40af;">';
        html+='<div style="font-size:11px;font-weight:800;color:#60a5fa;text-transform:uppercase;letter-spacing:.6px;margin-bottom:12px;">🔍 Site Survey Report</div>';
        if(existingSurvey) html+='<div style="background:#1e3a5f;border-radius:8px;padding:6px 10px;font-size:11px;color:#60a5fa;font-weight:700;margin-bottom:10px;">✓ Saved '+(existingSurvey.surveyed_at||'').substring(0,16)+' by '+escHtml(existingSurvey.surveyed_by||'')+'</div>';
        html+=schSurveyRow('① Electric Poles?','poles_available',[['yes','✅ Yes'],['partial','⚠ Partial'],['no','❌ No']]);
        html+=schSurveyRow('② Fiber Available?','fiber_available',[['yes','✅ Yes'],['partial','⚠ Partial'],['no','❌ No']]);
        html+=schSurveyRow('③ Feasibility','feasibility',[['feasible','✅ Feasible'],['conditional','⚠ Conditional'],['not_feasible','❌ Not Feasible']]);
        html+='<textarea class="srv-inp" placeholder="General notes…" rows="3" style="margin:8px 0;" oninput="_surveyState.general_notes=this.value">'+escHtml(_surveyState.general_notes||'')+'</textarea>';
        html+='<button onclick="schSaveSurvey()" class="sch-act-btn" style="background:linear-gradient(135deg,#1e40af,#2563eb);color:#fff;">💾 Save Survey</button>';
        html+='<div id="surveyStatus" style="text-align:center;font-size:12px;color:#94a3b8;min-height:18px;"></div>';
        html+='</div>';
    }

    // Actions
    if(!closed){
        html+='<div style="'+card+'border:2px solid #166534;">';
        html+='<div style="font-size:11px;font-weight:800;color:#4ade80;text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;">⚡ Actions</div>';
        if(job.status===0) html+='<button onclick="schAcceptJob('+job.id+')" data-jobid="'+job.id+'" class="sch-act-btn" style="background:linear-gradient(135deg,#1565C0,#1976D2);color:#fff;font-size:16px;padding:18px;box-shadow:0 4px 16px rgba(21,101,192,.4);">✔ Accept Job</button>';
        if(job.status===1){
            var allDone=tasks.length===0||tasks.every(function(t){return t.closed;});
            if(allDone) html+='<button onclick="schOpenCompleteForm('+job.id+')" class="sch-act-btn" style="background:linear-gradient(135deg,#166534,#16a34a);color:#fff;font-size:15px;">✅ Mark as Completed</button>';
            else{var rem=tasks.filter(function(t){return !t.closed;}).length;html+='<div style="background:#422006;border-radius:10px;padding:10px 12px;text-align:center;margin-bottom:8px;color:#fbbf24;font-size:13px;font-weight:700;">⚠ '+rem+' task'+(rem>1?'s':'')+' remaining</div>';}
        }
        html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">';
        html+='<button onclick="schOpenReschedule('+job.id+')" class="sch-act-btn" style="background:#422006;color:#fb923c;margin:0;">📅 Reschedule</button>';
        html+='<button onclick="schOpenComment('+job.id+')" class="sch-act-btn" style="background:#1e1b4b;color:#a5b4fc;margin:0;">💬 Add Comment</button>';
        html+='</div></div>';
    } else {
        html+='<div style="background:#14532d;border-radius:12px;padding:16px;text-align:center;margin-bottom:8px;"><div style="font-size:28px;">✅</div><div style="font-size:15px;font-weight:800;color:#4ade80;margin-top:4px;">Job Completed</div></div>';
    }

    el.innerHTML=html;
    document.getElementById('schJobDetailPage')._jobData={job:job,client:cl};
}).catch(function(e){
    document.getElementById('schJobDetailPage').innerHTML='<div style="padding:20px;color:#dc3545;text-align:center;">⚠ Network error: '+e.message+'</div>';
});

// ── Action functions for detail page (reload on success) ──────────────────
window.schToggleTask=function(taskId,done,jId){
    var el=document.getElementById('schTask_'+taskId);
    if(el) el.className='sch-task'+(done?' done':'');
    apiPost('scheduling_task_update',{task_id:taskId,done:done,job_id:jId}).then(function(d){
        if(done&&d.status==='success') setTimeout(function(){window.location.reload();},600);
    }).catch(function(){});
};
// ── ACCEPT JOB (job-detail view — mirrors the full scheduling list implementation) ──
window.schAcceptJob = function(jobId) {
    var btn = document.querySelector('[data-jobid="'+jobId+'"]');
    if (btn && btn.dataset.confirming) {
        btn.disabled = true;
        btn.textContent = '⏳ Accepting…';
        apiPost('scheduling_job_update', { job_id: jobId, status: 'open', notify_accept: 1 })
            .then(function(d) {
                if (d.status === 'success') {
                    window.location.reload();
                } else {
                    btn.disabled = false;
                    btn.textContent = '✔ Accept Job';
                    delete btn.dataset.confirming;
                    alert('Failed: ' + (d.message || 'Unknown error'));
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.textContent = '✔ Accept Job';
                delete btn.dataset.confirming;
                alert('Network error — please retry');
            });
    } else {
        if (btn) {
            btn.dataset.confirming = '1';
            btn.style.background = 'linear-gradient(135deg,#b45309,#d97706)';
            btn.textContent = '⚠ Tap again to confirm';
            setTimeout(function() {
                if (btn.dataset.confirming) {
                    delete btn.dataset.confirming;
                    btn.style.background = 'linear-gradient(135deg,#1565C0,#1976D2)';
                    btn.textContent = '✔ Accept Job';
                }
            }, 4000);
        }
    }
};
window.schOpenCompleteForm=function(jId){ window.schOpenCompleteFormImpl ? window.schOpenCompleteFormImpl(jId) : (function(){
    var overlay=document.createElement('div');
    overlay.id='schCompleteOverlay';
    overlay.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:4000;overflow-y:auto;display:flex;align-items:flex-start;justify-content:center;';
    overlay.innerHTML='<div style="background:#f8fafc;max-width:480px;width:100%;margin:20px auto;border-radius:20px;overflow:hidden;">'
        +'<div style="background:linear-gradient(135deg,#1B5E20,#2E7D32);padding:16px 20px;color:#fff;display:flex;justify-content:space-between;align-items:center;">'
        +'<div style="font-size:17px;font-weight:800;">✅ Complete Job #'+jId+'</div>'
        +'<button onclick="document.getElementById(\'schCompleteOverlay\').remove()" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:10px;padding:8px 12px;font-size:18px;cursor:pointer;">✕</button>'
        +'</div><div style="padding:16px;">'
        +'<div style="font-size:12px;font-weight:800;color:#374151;margin-bottom:6px;">💬 Completion Notes</div>'
        +'<textarea id="scComment" rows="3" placeholder="e.g. Service activated, customer briefed…" style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px;font-size:13px;box-sizing:border-box;margin-bottom:12px;"></textarea>'
        +'<div id="scError" style="color:#dc3545;font-size:12px;min-height:14px;margin-bottom:8px;"></div>'
        +'<button onclick="window._scDoComplete('+jId+')" class="sch-act-btn" style="background:linear-gradient(135deg,#1B5E20,#2E7D32);color:#fff;font-size:15px;">✅ Mark as Completed</button>'
        +'</div></div>';
    overlay.addEventListener('click',function(e){if(e.target===overlay)overlay.remove();});
    document.body.appendChild(overlay);
})(); };
window._scDoComplete=function(jId){
    var comment=((document.getElementById('scComment')||{}).value||'').trim();
    apiPost('scheduling_complete',{job_id:jId,comment:comment}).then(function(d){
        if(d.status==='success'){var o=document.getElementById('schCompleteOverlay');if(o)o.remove();window.location.reload();}
        else{var e=document.getElementById('scError');if(e)e.textContent='⚠ '+(d.message||'Failed');}
    }).catch(function(){var e=document.getElementById('scError');if(e)e.textContent='⚠ Network error';});
};
window.schOpenReschedule=function(jId){
    var overlay=document.createElement('div');
    overlay.id='schRescheduleOverlay';
    overlay.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:4000;display:flex;align-items:center;justify-content:center;';
    var def=new Date();def.setDate(def.getDate()+1);def.setHours(9,0,0,0);
    var defStr=def.toISOString().substring(0,16);
    overlay.innerHTML='<div style="background:#f8fafc;max-width:400px;width:94%;border-radius:20px;overflow:hidden;">'
        +'<div style="background:linear-gradient(135deg,#E65100,#F57F17);padding:16px 20px;color:#fff;display:flex;justify-content:space-between;align-items:center;">'
        +'<div style="font-size:17px;font-weight:800;">📅 Reschedule Job #'+jId+'</div>'
        +'<button onclick="document.getElementById(\'schRescheduleOverlay\').remove()" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:10px;padding:8px 12px;font-size:18px;cursor:pointer;">✕</button>'
        +'</div><div style="padding:16px;">'
        +'<div style="font-size:12px;font-weight:800;color:#374151;margin-bottom:6px;">New Date &amp; Time</div>'
        +'<input type="datetime-local" id="rsNewDate" value="'+defStr+'" style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px;font-size:13px;box-sizing:border-box;margin-bottom:12px;">'
        +'<div style="font-size:12px;font-weight:800;color:#374151;margin-bottom:6px;">Reason (optional)</div>'
        +'<textarea id="rsComment" rows="2" placeholder="e.g. Customer not available…" style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px;font-size:13px;box-sizing:border-box;margin-bottom:12px;"></textarea>'
        +'<div id="rsError" style="color:#dc3545;font-size:12px;min-height:14px;margin-bottom:8px;"></div>'
        +'<button onclick="window._rsSubmit('+jId+')" class="sch-act-btn" style="background:linear-gradient(135deg,#E65100,#F57F17);color:#fff;">📅 Confirm Reschedule</button>'
        +'</div></div>';
    overlay.addEventListener('click',function(e){if(e.target===overlay)overlay.remove();});
    document.body.appendChild(overlay);
};
window._rsSubmit=function(jId){
    var d=(document.getElementById('rsNewDate')||{}).value||'';
    var c=((document.getElementById('rsComment')||{}).value||'').trim();
    var errEl=document.getElementById('rsError');
    if(!d){if(errEl)errEl.textContent='⚠ Please select a date';return;}
    apiPost('scheduling_reschedule',{job_id:jId,new_date:d.replace('T',' '),comment:c}).then(function(r){
        if(r.status==='success'){var o=document.getElementById('schRescheduleOverlay');if(o)o.remove();window.location.reload();}
        else{if(errEl)errEl.textContent='⚠ '+(r.message||'Failed');}
    }).catch(function(){if(errEl)errEl.textContent='⚠ Network error';});
};
window.schOpenComment=function(jId){
    var overlay=document.createElement('div');
    overlay.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:4000;display:flex;align-items:center;justify-content:center;';
    overlay.innerHTML='<div style="background:#f8fafc;max-width:400px;width:94%;border-radius:20px;overflow:hidden;">'
        +'<div style="background:linear-gradient(135deg,#283593,#3949AB);padding:16px 20px;color:#fff;display:flex;justify-content:space-between;align-items:center;">'
        +'<div style="font-size:17px;font-weight:800;">💬 Add Comment — Job #'+jId+'</div>'
        +'<button onclick="this.closest(\'[style*=fixed]\').remove()" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:10px;padding:8px 12px;font-size:18px;cursor:pointer;">✕</button>'
        +'</div><div style="padding:16px;">'
        +'<textarea id="cmComment" rows="4" placeholder="Add a note — visible in UCRM for Bidal and admin…" style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px;font-size:13px;box-sizing:border-box;margin-bottom:12px;"></textarea>'
        +'<div id="cmError" style="color:#dc3545;font-size:12px;min-height:14px;margin-bottom:8px;"></div>'
        +'<button onclick="window._cmSubmit('+jId+',this.closest(\'[style*=fixed]\'))" class="sch-act-btn" style="background:linear-gradient(135deg,#283593,#3949AB);color:#fff;">💬 Save Comment to CRM</button>'
        +'</div></div>';
    overlay.addEventListener('click',function(e){if(e.target===overlay)overlay.remove();});
    document.body.appendChild(overlay);
    setTimeout(function(){var el=document.getElementById('cmComment');if(el)el.focus();},100);
};
window._cmSubmit=function(jId,overlayEl){
    var comment=((document.getElementById('cmComment')||{}).value||'').trim();
    var errEl=document.getElementById('cmError');
    if(!comment){if(errEl)errEl.textContent='⚠ Please enter a comment';return;}
    apiPost('scheduling_add_comment',{job_id:jId,comment:comment}).then(function(d){
        if(d.status==='success'){if(overlayEl)overlayEl.remove();alert('✅ Comment saved');}
        else{if(errEl)errEl.textContent='⚠ '+(d.message||'Failed');}
    }).catch(function(){if(errEl)errEl.textContent='⚠ Network error';});
};
window.schSaveSurvey=function(){
    var statusEl=document.getElementById('surveyStatus');
    if(statusEl){statusEl.textContent='💾 Saving…';statusEl.style.color='#1565C0';}
    var payload=Object.assign({},_surveyState,{job_id:_currentJobId,client_id:_currentClientId,surveyed_by:'<?= addslashes(h($retailer['name'] ?? '')) ?>'});
    apiPost('save_survey_result',payload).then(function(d){
        if(statusEl){statusEl.style.color=d.status==='success'?'#28a745':'#dc3545';statusEl.textContent=d.status==='success'?'✅ Survey saved!':'⚠ '+(d.message||'Failed');}
    }).catch(function(){if(statusEl){statusEl.style.color='#dc3545';statusEl.textContent='⚠ Network error';}});
};
})();
</script>
<?php else: ?>

<script>
/* Forward stubs — real implementations defined below after full JS block loads.
   Buttons in the hero are rendered before the <script> block, so we need these
   window-level references to exist immediately. */
var _apiToken = '<?= h($retailer['api_token'] ?? '') ?>';
window._apiToken = _apiToken;
function schLoadJobs(f)  { if(window._schLoadJobsReady) window._schLoadJobsReady(f); }
function schClearCache() { if(window._schClearCacheReady) window._schClearCacheReady(); }
function njOpen()        { if(window._njOpenReady) window._njOpenReady(); }
function njClose()       { if(window._njCloseReady) window._njCloseReady(); }
</script>

<!-- Hero -->
<div class="sch-hero">
    <div style="font-size:11px;opacity:.6;font-weight:700;text-transform:uppercase;letter-spacing:1px;">Field Support</div>
    <div style="font-size:22px;font-weight:800;margin-top:4px;"><?= h($retailer['name']) ?></div>
    <div style="font-size:12px;opacity:.75;margin-top:4px;">Your assigned jobs from UCRM scheduling</div>
    <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;align-items:center;">
        <div id="schStatOpen"  style="background:rgba(255,255,255,.15);border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700;">⏳ Loading…</div>
        <button id="schRefreshBtn" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;">↻ Refresh</button>
        <a href="?page=dashboard&tab=scheduling&refresh=1" style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.7);border-radius:10px;padding:8px 14px;font-size:11px;font-weight:600;text-decoration:none;">⚡ Sync UCRM</a>
        <button id="schClearCacheBtn" style="background:rgba(220,38,38,.4);color:#fff;border:none;border-radius:10px;padding:8px 14px;font-size:11px;font-weight:700;cursor:pointer;">🗑 Clear Cache</button>
        <button id="schNewJobBtn" style="background:#22c55e;color:#fff;border:none;border-radius:10px;padding:8px 16px;font-size:12px;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:6px;">＋ New Job</button>
    </div>
    <div id="schCacheAge" style="font-size:10px;opacity:.5;margin-top:6px;"></div>
    <div style="font-size:10px;opacity:.6;margin-top:4px;font-family:monospace;">
        📧 <?= h($retailer['email'] ?? 'no email') ?> &nbsp;|&nbsp;
        🔑 UCRM ID: <strong><?= (int)($retailer['ucrm_user_id'] ?? 0) ?: '❌ NOT SET' ?></strong>
    </div>
</div>

<!-- Jobs list -->
<div id="schJobsList" style="background:#0f1724;border-radius:16px;padding:12px;min-height:120px;"><div style="text-align:center;padding:40px;color:#94a3b8;"><span style="font-size:32px;display:block;margin-bottom:8px;">📋</span>Loading your jobs…</div></div>

<!-- Job detail overlay (kept for action modals only) -->
<div id="schDetailOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:3000;overflow-y:auto;">
    <div id="schDetailPanel" style="background:#0f1724;max-width:520px;margin:20px auto;border-radius:20px;overflow:hidden;min-height:60vh;">
        <div id="schDetailContent" style="padding:20px;">Loading…</div>
    </div>
</div>

<script>
(function(){
var TOKEN  = '<?= $apiToken ?>';
var headers = { 'Authorization': 'Bearer ' + TOKEN, 'Content-Type': 'application/json' };

// Module-level helpers (available to all functions in this IIFE)
function isClosed(j) { return (j.status === 2 || j.status === 'closed'); }

function apiGet(action, qs) {
    return fetch('?page=api&action=' + action + (qs||''), {credentials:'same-origin', headers: headers }).then(function(r){ return r.json(); });
}
function apiPost(action, body) {
    return fetch('?page=api&action=' + action, {
          credentials:'same-origin',
          method: 'POST', headers: headers, body: JSON.stringify(body) }).then(function(r){ return r.json(); });
}

// ── STATUS LABEL HELPERS ──────────────────────────────────────
// UCRM scheduling status confirmed from agenda URL: 1=Open, 2=Closed, 0=Pending/Draft
var STATUS_LABEL = { 0: 'Pending', 1: 'Open', 2: 'Closed', open: 'Open', pending: 'Pending', closed: 'Closed' };
var SVC_STATUS   = { 1: ['Active','#28a745'], 2: ['Ended','#6b7280'], 3: ['Suspended','#dc3545'], 4: ['Prepared','#FF9800'], 5: ['Quoted','#9b59b6'] };
var INV_STATUS   = { 1: ['Unpaid','#dc3545'], 2: ['Partial','#FF9800'] };

function statusBadge(s) {
    var lbl = STATUS_LABEL[s] || s;
    var cls = (s === 2 || s === 'closed') ? 'closed' : (s === 0 || s === 'pending') ? 'pending' : 'open';
    return '<span class="sch-status ' + cls + '">' + lbl + '</span>';
}

function today() { return new Date().toISOString().substring(0,10); }
function formatDate(d) {
    if (!d) return '';
    var dt = new Date(d);
    var diff = Math.round((dt - new Date()) / 86400000);
    var base = dt.toLocaleDateString('en-GB', {day:'numeric',month:'short'});
    if (diff === 0) return '<strong style="color:#E65100;">Today</strong>';
    if (diff === 1) return '<strong style="color:#D41C1C;">Tomorrow</strong>';
    if (diff < 0)   return '<span style="color:#dc3545;">' + base + ' (overdue)</span>';
    return base;
}

// ── LOAD JOBS LIST ────────────────────────────────────────────
window._schClearCacheReady = window.schClearCache = function() {
    var btn = document.getElementById('schClearCacheBtn');
    if (!btn) return;
    if (!confirm('Clear the jobs cache? Everyone will fetch live from UCRM on next load.')) return;
    btn.disabled = true;
    btn.textContent = '⏳ Clearing…';
    apiPost('scheduling_clear_cache', {}).then(function(d) {
        if (d.status === 'success') {
            btn.textContent = '✅ Cleared';
            btn.style.background = 'rgba(34,197,94,.4)';
            setTimeout(function() { schLoadJobs(true); }, 800);
        } else {
            btn.textContent = '❌ Failed';
            btn.disabled = false;
            setTimeout(function() { btn.textContent = '🗑 Clear Cache'; btn.style.background = 'rgba(220,38,38,.4)'; }, 2000);
        }
    }).catch(function() {
        btn.textContent = '❌ Error';
        btn.disabled = false;
    });
};

window._schLoadJobsReady = window.schLoadJobs = function(forceRefresh) {
    var list   = document.getElementById('schJobsList');
    var statEl = document.getElementById('schStatOpen');
    var ageEl  = document.getElementById('schCacheAge');
    var qs     = forceRefresh ? '&refresh=1' : '';
    list.innerHTML = '<div style="text-align:center;padding:40px;color:#9ca3af;"><span style="font-size:28px;display:block;">⚡</span>' + (forceRefresh ? 'Syncing from UCRM…' : 'Fetching your jobs…<br><span style="font-size:11px;opacity:.6;">First load hits UCRM directly, subsequent loads use cache</span>') + '</div>';

    apiGet('scheduling_jobs', qs).then(function(d) {
        if (d.status !== 'success') {
            list.innerHTML = '<div style="padding:20px;color:#dc3545;text-align:center;">⚠ ' + (d.message||'Failed to load jobs') + '</div>';
            return;
        }
        if (d.data.needs_mapping) {
            list.innerHTML = '<div style="padding:20px;text-align:center;color:#E65100;">UCRM User ID not set — contact admin.</div>';
            return;
        }
        var jobs = d.data.jobs || [];
        if (ageEl) {
            ageEl.textContent = d.data.from_cache
                ? ('⚡ Cached · synced ' + (d.data.last_sync || '').substring(0,16) + ' EAT')
                : '🌐 Live from UCRM';
        }
        var openJobs   = jobs.filter(function(j){ return !isClosed(j); });
        var closedJobs = jobs.filter(function(j){ return  isClosed(j); });

        if (statEl) statEl.textContent = openJobs.length + ' Active · ' + closedJobs.length + ' Done';

        if (!jobs.length) {
            list.innerHTML = '<div style="text-align:center;padding:40px;"><div style="font-size:40px;margin-bottom:12px;">✅</div><div style="font-size:15px;font-weight:700;color:#2E7D32;">No jobs from 2026</div><div style="font-size:12px;color:#9ca3af;margin-top:4px;">New jobs appear here when admin assigns them in UCRM scheduling.</div></div>';
            return;
        }

        // Sort within each day by GPS proximity (nearest first)
        var origin = {lat: 4.8594, lon: 31.5713};
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(pos) {
                origin = {lat: pos.coords.latitude, lon: pos.coords.longitude};
            }, null, {timeout: 3000});
        }

        function dist(j) {
            if (!j.gpsLat || !j.gpsLon) return 99999;
            var dlat = parseFloat(j.gpsLat) - origin.lat;
            var dlon = parseFloat(j.gpsLon) - origin.lon;
            return Math.sqrt(dlat*dlat + dlon*dlon);
        }

        function buildJobCard(j) {
            var title   = escHtml(j.title || 'Job #' + j.id);
            // client may be absent in list response — fall back to job address or blank
            var clientName = '';
            if (j.client) {
                clientName = ((j.client.firstName||'') + ' ' + (j.client.lastName||'')).trim();
                if (j.client.id) clientName += ' (' + j.client.id + ')';
            }
            var addr    = escHtml((j.address||'').trim() || '');
            var dateStr = j.date ? new Date(j.date).toLocaleString('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '';
            var dur     = j.duration ? j.duration + ' min' : '';
            var badge   = statusBadge(j.status);
            var closedStyle = isClosed(j) ? 'opacity:.7;' : '';

            var h = '<div class="sch-job" style="' + closedStyle + '" onclick="schOpenJob(' + j.id + ')">';
            // Row 1: title + badge inline
            h += '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:6px;">';
            h +=   '<div class="sch-job-title" style="flex:1;" title="' + title + '">' + title + '</div>';
            h +=   badge;
            h += '</div>';
            // Row 2: date + duration
            h += '<div class="sch-job-meta">';
            h +=   '<span>📅 ' + dateStr + '</span>';
            if (dur) h += '<span>⏱ ' + dur + '</span>';
            h += '</div>';
            // Row 3: client + address + view link
            h += '<div class="sch-job-footer" style="margin-top:6px;">';
            if (clientName) h += '<span>👤 ' + escHtml(clientName) + '</span>';
            if (addr) h += '<span style="flex:1;text-align:center;">📍 ' + addr + '</span>';
            h +=   '<span style="color:#0ea5a3;font-weight:700;white-space:nowrap;">View →</span>';
            h += '</div>';
            h += '</div>';
            return h;
        }

        function buildSection(sectionJobs, label, color, collapsed) {
            if (!sectionJobs.length) return '';
            // Group by date
            var grouped = {};
            sectionJobs.forEach(function(j) {
                var dk = (j.date||'').substring(0,10) || 'No date';
                if (!grouped[dk]) grouped[dk] = [];
                grouped[dk].push(j);
            });
            // Sort each day by GPS proximity
            Object.keys(grouped).forEach(function(dk) {
                grouped[dk].sort(function(a,b){ return dist(a)-dist(b); });
            });

            var uid = 'schSec_' + label.replace(/\W/g,'');
            var inner = '';
            // For open: newest date first (soonest job); for closed: newest date first
            // Open: ascending (earliest/most overdue first); Closed: descending (newest first)
            var sortedDates = Object.keys(grouped).sort();
            if (collapsed) sortedDates.reverse();  // closed: newest first
            sortedDates.forEach(function(dk) {
                var dlabel = dk === today() ? '📅 Today' : '📅 ' + dk;
                inner += '<div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin:10px 0 4px;padding-left:2px;">' + dlabel + '</div>';
                grouped[dk].forEach(function(j){ inner += buildJobCard(j); });
            });

            var h = '<div style="margin-bottom:16px;">';
            h += '<div onclick="var b=document.getElementById(\'' + uid + '\');b.style.display=b.style.display===\'none\'?\'block\':\'none\';" ';
            h += 'style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;padding:10px 14px;background:' + color + ';border-radius:12px;margin-bottom:6px;">';
            h += '<div style="font-size:13px;font-weight:800;color:#e2e8f0;">' + label + ' <span style="font-size:11px;font-weight:600;color:#94a3b8;">('+sectionJobs.length+')</span></div>';
            h += '<span style="font-size:16px;color:#6b7280;">' + (collapsed ? '▸' : '▾') + '</span>';
            h += '</div>';
            h += '<div id="' + uid + '" style="display:' + (collapsed?'none':'block') + ';">' + inner + '</div>';
            h += '</div>';
            return h;
        }

        var html = '';
        if (openJobs.length) html += buildSection(openJobs, '🟢 Active Jobs', '#0f4c35', false);
        if (closedJobs.length) html += buildSection(closedJobs, '✅ Completed Jobs', '#1e293b', true);
        list.innerHTML = html;
    }).catch(function() {
        list.innerHTML = '<div style="padding:20px;color:#dc3545;text-align:center;">⚠ Network error — check CRM connection</div>';
    });
};

// ── OPEN JOB DETAIL ───────────────────────────────────────────
window.schOpenJob = function(jobId) {
    window.location.href = '?page=dashboard&tab=scheduling&job=' + jobId;
};

// ── Survey state (kept in memory while overlay is open) ──────
var _surveyState = {};
var _currentJobId = 0;
var _currentClientId = 0;

function srvSet(field, val) {
    _surveyState[field] = val;
    // Update button visual
    document.querySelectorAll('[data-srv-field="'+field+'"]').forEach(function(btn) {
        btn.className = btn.className.replace(/\bsel-\S+/g, '').trim();
        if (btn.getAttribute('data-srv-val') === val) btn.className += ' sel-' + val;
    });
}

function renderJobDetail(data) {
    var job   = data.job    || {};
    var cl    = data.client || {};
    var svcs  = data.services || [];
    var invs  = data.pending_invoices || [];
    var tasks = data.tasks  || [];
    var gps   = data.gps;
    var existingSurvey = data.survey || null;
    var existingSig    = data.signature || null;

    _currentJobId    = job.id;
    _currentClientId = cl.id || 0;
    _surveyState = existingSurvey ? Object.assign({}, existingSurvey) : {
        poles_available:'unknown', fiber_available:'unknown', feasibility:'feasible',
        ap_needed: false, ap_count: 0
    };

    var addr     = (job.address || cl.address || '').trim();
    var title    = job.title || addr || 'Job #' + job.id;
    var isSurvey = title.toLowerCase().indexOf('survey') !== -1 || title.toLowerCase().indexOf('fiber survey') !== -1 || job.jobType === 'survey';
    var isClosed2 = isClosed(job);

    // Status label + progress bar
    var statusMap = {0:'Pending', 1:'Open', 2:'Closed'};
    var statusLbl = statusMap[job.status] || 'Open';
    var progressW = job.status === 0 ? '33%' : job.status === 1 ? '66%' : '100%';
    var progressC = job.status === 0 ? '#3b82f6' : job.status === 1 ? '#facc15' : '#22c55e';

    var dateStr = job.date ? new Date(job.date).toLocaleString('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '';
    var dur     = job.duration ? job.duration + ' min' : '';

    var html = '';

    // ── HEADER ────────────────────────────────────────────────────
    html += '<div style="background:linear-gradient(135deg,#0D47A1,#1976D2);padding:16px 20px;color:#fff;position:sticky;top:0;z-index:10;display:flex;justify-content:space-between;align-items:flex-start;">';
    html += '<div style="flex:1;min-width:0;">';
    html += '<div style="font-size:10px;opacity:.6;text-transform:uppercase;font-weight:700;letter-spacing:.8px;">Job #' + job.id + '</div>';
    html += '<div style="font-size:17px;font-weight:800;margin-top:3px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + escHtml(title) + '">' + escHtml(title) + '</div>';
    html += '</div>';
    html += '<button onclick="schCloseDetail()" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:10px;padding:10px 14px;font-size:20px;cursor:pointer;flex-shrink:0;margin-left:10px;">✕</button>';
    html += '</div>';

    html += '<div style="padding:14px 14px 100px;">';

    // ── STATUS + PROGRESS ─────────────────────────────────────────
    var card = 'background:#1e293b;border-radius:12px;padding:1rem;margin-bottom:10px;';
    html += '<div style="' + card + '">';
    html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">';
    html += '<span style="font-size:13px;font-weight:700;color:#e2e8f0;">Status</span>';
    html += statusBadge(job.status);
    html += '</div>';
    html += '<div style="background:#334155;border-radius:8px;height:10px;overflow:hidden;">';
    html += '<div style="background:' + progressC + ';height:100%;width:' + progressW + ';border-radius:8px;transition:.4s;"></div>';
    html += '</div>';
    html += '</div>';

    // ── DETAILS CARD ──────────────────────────────────────────────
    html += '<div style="' + card + '">';
    html += '<div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;">Job Details</div>';
    if (cl.name || cl.firstName) {
        var cname = cl.name || ((cl.firstName||'') + ' ' + (cl.lastName||'')).trim();
        html += '<div style="margin-bottom:6px;"><span style="color:#94a3b8;font-size:12px;">Client</span><div style="color:#e2e8f0;font-size:14px;font-weight:700;">' + escHtml(cname) + (cl.id ? ' <span style="color:#64748b;font-size:11px;">(#' + cl.id + ')</span>' : '') + '</div></div>';
    }
    if (dateStr) html += '<div style="margin-bottom:6px;"><span style="color:#94a3b8;font-size:12px;">Date &amp; Time</span><div style="color:#e2e8f0;font-size:14px;">📅 ' + dateStr + '</div></div>';
    if (dur)     html += '<div style="margin-bottom:6px;"><span style="color:#94a3b8;font-size:12px;">Duration</span><div style="color:#e2e8f0;font-size:14px;">⏱ ' + dur + '</div></div>';
    if (addr)    html += '<div style="margin-bottom:6px;"><span style="color:#94a3b8;font-size:12px;">Address</span><div style="color:#e2e8f0;font-size:14px;">📍 ' + escHtml(addr) + '</div></div>';
    if (job.description) html += '<div><span style="color:#94a3b8;font-size:12px;">Description</span><div style="color:#e2e8f0;font-size:14px;">' + escHtml(job.description) + '</div></div>';
    html += '</div>';

    // ── NAVIGATE ──────────────────────────────────────────────────
    if (gps || addr) {
        var mapsUrl = gps
            ? 'https://www.google.com/maps/dir/?api=1&destination=' + gps.lat + ',' + gps.lon
            : 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(addr);
        html += '<a href="' + mapsUrl + '" target="_blank" class="sch-map-btn" style="background:linear-gradient(135deg,#1B5E20,#2E7D32);color:#fff;">';
        html += '<span style="font-size:20px;">🗺</span><div style="flex:1;"><div style="font-size:13px;font-weight:800;">Navigate to Site</div>';
        html += '<div style="font-size:11px;opacity:.8;">' + escHtml(addr || 'Open in Maps') + '</div></div><span>→</span></a>';
    }

    // ── CUSTOMER CONTACT ──────────────────────────────────────────
    if (cl.phone) {
        var callUrl = 'tel:' + cl.phone.replace(/\s/g,'');
        var waUrl   = 'https://wa.me/' + cl.phone.replace(/[^0-9]/g,'');
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">';
        html += '<a href="' + callUrl + '" class="sch-act-btn" style="background:#22c55e;color:#fff;margin:0;">📞 Call</a>';
        html += '<a href="' + waUrl + '" target="_blank" class="sch-act-btn" style="background:#22c55e;color:#fff;margin:0;">💬 WhatsApp</a>';
        html += '</div>';
    }

    // ── TASKS CHECKLIST ───────────────────────────────────────────
    if (tasks.length) {
        var doneCnt = tasks.filter(function(t){ return t.closed; }).length;
        html += '<div style="' + card + '">';
        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">';
        html += '<span style="font-size:12px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">TASKS</span>';
        html += '<span style="font-size:12px;color:#64748b;">' + doneCnt + '/' + tasks.length + ' done</span>';
        html += '</div>';
        html += '<div style="background:#334155;border-radius:6px;height:6px;margin-bottom:10px;overflow:hidden;">';
        html += '<div style="background:#22c55e;height:100%;width:' + Math.round(doneCnt/tasks.length*100) + '%;border-radius:6px;transition:.3s;"></div></div>';
        tasks.forEach(function(t) {
            html += '<div class="sch-task' + (t.closed?' done':'') + '" id="schTask_' + t.id + '">';
            html += '<input type="checkbox"' + (t.closed?' checked':'') + ' onchange="schToggleTask('+t.id+',this.checked,'+job.id+')" style="width:20px;height:20px;cursor:pointer;flex-shrink:0;accent-color:#22c55e;">';
            html += '<span style="font-size:14px;color:#e2e8f0;">' + escHtml(t.name||t.title||'Task #'+t.id) + '</span>';
            html += '</div>';
        });
        html += '</div>';
    }

    // ── SITE SURVEY (only for survey jobs) ───────────────────────
    if (isSurvey) {
        html += '<div style="' + card + 'border:2px solid #1e40af;">';
        html += '<div style="font-size:11px;font-weight:800;color:#60a5fa;text-transform:uppercase;letter-spacing:.6px;margin-bottom:12px;">🔍 Site Survey Report</div>';
        if (existingSurvey) {
            html += '<div style="background:#1e3a5f;border-radius:8px;padding:6px 10px;font-size:11px;color:#60a5fa;font-weight:700;margin-bottom:10px;">✓ Saved ' + (existingSurvey.surveyed_at||'').substring(0,16) + ' by ' + escHtml(existingSurvey.surveyed_by||'') + '</div>';
        }
        // Poles
        html += schSurveyRow('① Electric Poles?', 'poles_available', [['yes','✅ Yes'],['partial','⚠ Partial'],['no','❌ No']]);
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:12px;">';
        html += '<input class="srv-inp" type="number" placeholder="# poles nearby" min="0" value="' + (_surveyState.poles_count||'') + '" oninput="_surveyState.poles_count=this.value">';
        html += '<input class="srv-inp" type="text" placeholder="Notes" value="' + escHtml(_surveyState.poles_note||'') + '" oninput="_surveyState.poles_note=this.value">';
        html += '</div>';
        // Fiber
        html += schSurveyRow('② Fiber Available?', 'fiber_available', [['yes','✅ Yes'],['partial','⚠ Partial'],['no','❌ No']]);
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:12px;">';
        html += '<input class="srv-inp" type="number" placeholder="Distance to nearest (m)" min="0" value="' + (_surveyState.fiber_distance_m||'') + '" oninput="_surveyState.fiber_distance_m=this.value">';
        html += '<input class="srv-inp" type="text" placeholder="Route notes" value="' + escHtml(_surveyState.fiber_note||'') + '" oninput="_surveyState.fiber_note=this.value">';
        html += '</div>';
        // Feasibility
        html += schSurveyRow('③ Overall Feasibility', 'feasibility', [['feasible','✅ Feasible'],['conditional','⚠ Conditional'],['not_feasible','❌ Not Feasible']]);
        // Notes
        html += '<textarea class="srv-inp" placeholder="General notes, observations…" rows="3" style="margin:8px 0;" oninput="_surveyState.general_notes=this.value">' + escHtml(_surveyState.general_notes||'') + '</textarea>';
        html += '<button onclick="schSaveSurvey()" class="sch-act-btn" style="background:linear-gradient(135deg,#1e40af,#2563eb);color:#fff;">💾 Save Survey</button>';
        html += '<div id="surveyStatus" style="text-align:center;font-size:12px;color:#94a3b8;min-height:18px;"></div>';
        html += '</div>';
    }

    // ── JOB ACTIONS ───────────────────────────────────────────────
    if (!isClosed2) {
        html += '<div style="' + card + 'border:2px solid #166534;">';
        html += '<div style="font-size:11px;font-weight:800;color:#4ade80;text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;">⚡ Actions</div>';
        if (job.status === 0) {  // Pending → accept
            html += '<button onclick="schAcceptJob('+job.id+')" data-jobid="'+job.id+'" class="sch-act-btn" style="background:linear-gradient(135deg,#1565C0,#1976D2);color:#fff;font-size:16px;padding:18px;box-shadow:0 4px 16px rgba(21,101,192,.4);">✔ Accept Job</button>';
        }
        if (job.status === 1) {  // Open/InProgress → complete
            var allDone = tasks.length === 0 || tasks.every(function(t){ return t.closed; });
            if (allDone) {
                html += '<button onclick="schOpenCompleteForm('+job.id+')" class="sch-act-btn" style="background:linear-gradient(135deg,#166534,#16a34a);color:#fff;font-size:15px;">✅ Mark as Completed</button>';
            } else {
                var rem = tasks.filter(function(t){ return !t.closed; }).length;
                html += '<div style="background:#422006;border-radius:10px;padding:10px 12px;text-align:center;margin-bottom:8px;color:#fbbf24;font-size:13px;font-weight:700;">⚠ ' + rem + ' task' + (rem>1?'s':'') + ' remaining</div>';
            }
        }
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">';
        html += '<button onclick="schOpenReschedule('+job.id+')" class="sch-act-btn" style="background:#422006;color:#fb923c;margin:0;">📅 Reschedule</button>';
        html += '<button onclick="schOpenComment('+job.id+')" class="sch-act-btn" style="background:#1e1b4b;color:#a5b4fc;margin:0;">💬 Add Comment</button>';
        html += '</div>';
        html += '</div>';
    } else {
        html += '<div style="background:#14532d;border-radius:12px;padding:16px;text-align:center;margin-bottom:8px;">';
        html += '<div style="font-size:28px;">✅</div>';
        html += '<div style="font-size:15px;font-weight:800;color:#4ade80;margin-top:4px;">Job Completed</div>';
        html += '</div>';
    }

    html += '</div>'; // end padding div
    document.getElementById('schDetailContent').innerHTML = html;
    document.getElementById('schDetailContent')._jobData = {job: job, client: cl};
}

// Helper: survey question row
function schSurveyRow(label, field, opts) {
    var h = '<div style="font-size:12px;font-weight:700;color:#cbd5e1;margin-bottom:6px;">' + label + '</div>';
    h += '<div style="display:grid;grid-template-columns:repeat(' + opts.length + ',1fr);gap:6px;margin-bottom:8px;">';
    opts.forEach(function(o) {
        var sel = (_surveyState[field] === o[0]) ? ' sel-'+o[0] : '';
        h += '<button class="srv-opt'+sel+'" onclick="_surveyState.'+field+'=\''+o[0]+'\';document.querySelectorAll(\'[data-sf=\\\'' + field + '\\\']\').forEach(function(b){b.className=b.className.replace(/\\bsel-\\S+/g,\'\').trim();});this.className+=\' sel-'+o[0]+'\'" data-sf="' + field + '">' + o[1] + '</button>';
    });
    h += '</div>';
    return h;
}

// ── HARDWARE TOGGLE ───────────────────────────────────────────
window.schToggleHardware = function(hw) {
    var inp = document.getElementById('srvExtraHw');
    if (hw === 'None') {
        _surveyState.extra_hardware = '';
        if (inp) inp.value = '';
        ['PoE Switch','Extra Cable','ODF Box','Power Backup','Wall Bracket'].forEach(function(h) {
            var b = document.getElementById('hwBtn_'+h.replace(/\s/g,'_'));
            if (b) b.className = b.className.replace(/\bsel-yes\b/,'').trim();
        });
        return;
    }
    var cur = _surveyState.extra_hardware || '';
    var list = cur ? cur.split(',').map(function(s){ return s.trim(); }).filter(Boolean) : [];
    var idx  = list.indexOf(hw);
    if (idx === -1) list.push(hw); else list.splice(idx,1);
    _surveyState.extra_hardware = list.join(', ');
    if (inp) inp.value = _surveyState.extra_hardware;
    var btn = document.getElementById('hwBtn_'+hw.replace(/\s/g,'_'));
    if (btn) {
        btn.className = btn.className.replace(/\bsel-yes\b/,'').trim();
        if (idx === -1) btn.className += ' sel-yes';
    }
};

// ── SIGNATURE CANVAS ──────────────────────────────────────────
var _sigDrawing = false;
var _sigHasData = false;

function schInitSigCanvas() {
    var canvas = document.getElementById('sigCanvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    ctx.strokeStyle = '#1e293b';
    ctx.lineWidth   = 2.5;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';

    function getPos(e) {
        var r = canvas.getBoundingClientRect();
        var scaleX = canvas.width  / r.width;
        var scaleY = canvas.height / r.height;
        var src = e.touches ? e.touches[0] : e;
        return { x: (src.clientX - r.left) * scaleX, y: (src.clientY - r.top) * scaleY };
    }
    function start(e) {
        e.preventDefault();
        _sigDrawing = true;
        var p = getPos(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        var ph = document.getElementById('sigPlaceholder');
        if (ph) ph.style.display = 'none';
    }
    function move(e) {
        if (!_sigDrawing) return;
        e.preventDefault();
        var p = getPos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        _sigHasData = true;
    }
    function end(e) { _sigDrawing = false; ctx.beginPath(); }

    canvas.addEventListener('mousedown',  start, {passive:false});
    canvas.addEventListener('mousemove',  move,  {passive:false});
    canvas.addEventListener('mouseup',    end);
    canvas.addEventListener('touchstart', start, {passive:false});
    canvas.addEventListener('touchmove',  move,  {passive:false});
    canvas.addEventListener('touchend',   end);
}

window.schClearSigCanvas = function() {
    var canvas = document.getElementById('sigCanvas');
    if (!canvas) return;
    canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
    _sigHasData = false;
    var ph = document.getElementById('sigPlaceholder');
    if (ph) ph.style.display = '';
};

window.schClearSignature = function() {
    // Re-render to show blank canvas
    if (window._lastJobData) renderJobDetail(window._lastJobData);
};

window.schSaveSignature = function(jobId, clientId) {
    var canvas = document.getElementById('sigCanvas');
    var nameEl = document.getElementById('signerName');
    var status = document.getElementById('sigStatus');
    if (!canvas || !_sigHasData) {
        if (status) { status.textContent = '⚠ Please draw the signature first'; status.style.color='#dc3545'; }
        return;
    }
    var sigData = canvas.toDataURL('image/png');
    var name    = nameEl ? nameEl.value.trim() : '';
    if (!name) {
        if (status) { status.textContent = '⚠ Please enter the customer name'; status.style.color='#dc3545'; }
        return;
    }
    if (status) { status.textContent = 'Saving…'; status.style.color='#9ca3af'; }
    apiPost('save_job_signature', {
        job_id: jobId, crm_client_id: clientId,
        signature: sigData, signer_name: name,
    }).then(function(d) {
        if (d.status === 'success') {
            if (status) { status.textContent = '✅ Signature saved at ' + (d.data.signed_at||'').substring(0,16); status.style.color='#2E7D32'; }
            // Show the saved image
            setTimeout(function() {
                var card = canvas.closest('.srv-card');
                if (card) {
                    card.innerHTML = '<div class="srv-title" style="color:#2E7D32;">✍️ Customer Signature</div>'
                        + '<div style="background:#E8F5E9;border-radius:8px;padding:6px 10px;font-size:11px;color:#2E7D32;font-weight:700;margin-bottom:8px;">✅ Signed by ' + escHtml(name) + '</div>'
                        + '<img src="'+sigData+'" style="width:100%;border-radius:8px;border:1px solid #C8E6C9;max-height:140px;object-fit:contain;">';
                }
            }, 800);
        } else {
            if (status) { status.textContent = '⚠ ' + (d.message||'Save failed'); status.style.color='#dc3545'; }
        }
    });
};

// Init canvas after renderJobDetail injects it
var _sigInitTimer = null;
// Wrap renderJobDetail to cache job data (canvas/sig removed, keeping data cache)
var _origRender = renderJobDetail;
renderJobDetail = function(data) {
    window._lastJobData = data;
    _origRender(data);
    // canvas removed from simplified detail — no init needed
};

window.schInitSigCanvas = function() {}; // canvas removed from simplified detail

// ── CHECK IN / CHECK OUT ──────────────────────────────────────
window.schCheckIn = function(jobId) {
    var statusEl = document.getElementById('schCheckinStatus');
    if (statusEl) statusEl.textContent = '📍 Getting your location...';

    // Use native bridge if in DishNet app v2.0
    if (window.dishnetNativeAvailable && typeof DishNetNative !== 'undefined') {
        DishNetNative.checkIn(jobId, '');
        if (statusEl) { statusEl.textContent = '✅ Checked in via app'; statusEl.style.color = '#22c55e'; }
        DishNetNative.setCurrentJob(jobId);
        return;
    }

    // Browser fallback: use navigator.geolocation
    if (!navigator.geolocation) {
        if (statusEl) { statusEl.textContent = '⚠ Geolocation not available'; statusEl.style.color = '#dc3545'; }
        return;
    }
    navigator.geolocation.getCurrentPosition(function(pos) {
        fetch('?page=api&action=install_checkin', {
          credentials:'same-origin',
          method: 'POST',
            headers: {'Authorization': 'Bearer ' + _apiToken, 'Content-Type': 'application/json'},
            body: JSON.stringify({job_id: jobId, lat: pos.coords.latitude, lon: pos.coords.longitude})
        }).then(r => r.json()).then(function(res) {
            if (res.code === 200) {
                if (statusEl) { statusEl.textContent = '✅ Checked in at ' + new Date().toLocaleTimeString(); statusEl.style.color = '#22c55e'; }
                // Start location tracking
                schStartTracking(jobId, pos.coords.latitude, pos.coords.longitude);
            } else {
                if (statusEl) { statusEl.textContent = '⚠ ' + (res.message||'Check-in failed'); statusEl.style.color = '#dc3545'; }
            }
        });
    }, function() {
        if (statusEl) { statusEl.textContent = '⚠ Location access denied'; statusEl.style.color = '#dc3545'; }
    });
};

window.schCheckOut = function(jobId) {
    var statusEl = document.getElementById('schCheckinStatus');
    if (statusEl) statusEl.textContent = '🏁 Checking out...';

    if (window.dishnetNativeAvailable && typeof DishNetNative !== 'undefined') {
        DishNetNative.checkOut(jobId, '');
        if (statusEl) { statusEl.textContent = '🏁 Checked out via app'; statusEl.style.color = '#1976d2'; }
        DishNetNative.setCurrentJob(0);
        return;
    }

    if (!navigator.geolocation) {
        // Post without GPS
        fetch('?page=api&action=install_checkout', {
          credentials:'same-origin',
          method: 'POST',
            headers: {'Authorization': 'Bearer ' + _apiToken, 'Content-Type': 'application/json'},
            body: JSON.stringify({job_id: jobId, lat: 0, lon: 0})
        }).then(r => r.json()).then(function(res) {
            if (statusEl) { statusEl.textContent = '🏁 Checked out'; statusEl.style.color = '#1976d2'; }
        });
        return;
    }

    navigator.geolocation.getCurrentPosition(function(pos) {
        fetch('?page=api&action=install_checkout', {
          credentials:'same-origin',
          method: 'POST',
            headers: {'Authorization': 'Bearer ' + _apiToken, 'Content-Type': 'application/json'},
            body: JSON.stringify({job_id: jobId, lat: pos.coords.latitude, lon: pos.coords.longitude})
        }).then(r => r.json()).then(function(res) {
            if (statusEl) { statusEl.textContent = '🏁 Checked out at ' + new Date().toLocaleTimeString(); statusEl.style.color = '#1976d2'; }
            schStopTracking();
        });
    });
};

// ── BROWSER LOCATION TRACKING (when not in native app) ───────────────────────
var _schTrackInterval = null;
// _apiToken already declared in stub script above hero

function schStartTracking(jobId, lat, lon) {
    // Send first ping immediately
    schSendLocationPing(jobId, lat, lon);
    // Then every 60s
    if (_schTrackInterval) clearInterval(_schTrackInterval);
    _schTrackInterval = setInterval(function() {
        navigator.geolocation && navigator.geolocation.getCurrentPosition(function(pos) {
            schSendLocationPing(jobId, pos.coords.latitude, pos.coords.longitude);
        });
    }, 60000);
}

function schStopTracking() {
    if (_schTrackInterval) { clearInterval(_schTrackInterval); _schTrackInterval = null; }
}

function schSendLocationPing(jobId, lat, lon) {
    var battery = -1;
    // Battery API (Chrome only, but nice to have)
    if (navigator.getBattery) {
        navigator.getBattery().then(function(b) { battery = Math.round(b.level * 100); });
    }
    fetch('?page=api&action=gps_heartbeat', {
          credentials:'same-origin',
          method: 'POST',
        headers: {'Authorization': 'Bearer ' + _apiToken, 'Content-Type': 'application/json'},
        body: JSON.stringify({lat: lat, lon: lon, job_id: jobId, battery: battery})
    }).catch(function(){});  // Silent fail for pings
}

// ── GPS CAPTURE ───────────────────────────────────────────────
window.schCaptureGPS = function() {
    var statusEl = document.getElementById('gpsStatus');
    if (!statusEl) return;
    if (!_currentClientId) { statusEl.textContent = '⚠ No client linked to this job'; statusEl.style.color='#dc3545'; return; }
    if (!navigator.geolocation) { statusEl.textContent = '⚠ Geolocation not supported on this device'; statusEl.style.color='#dc3545'; return; }
    statusEl.textContent = '📡 Getting your GPS location…';
    statusEl.style.color = '#1565C0';
    navigator.geolocation.getCurrentPosition(function(pos) {
        var lat = pos.coords.latitude;
        var lon = pos.coords.longitude;
        var acc = Math.round(pos.coords.accuracy);
        statusEl.textContent = '📍 Got location (' + acc + 'm accuracy) — saving to UCRM…';
        apiPost('update_client_gps', { client_id: _currentClientId, lat: lat, lon: lon })
            .then(function(d) {
                if (d.status === 'success') {
                    statusEl.style.color = '#28a745';
                    statusEl.textContent = '✅ GPS saved! ' + lat.toFixed(5) + ', ' + lon.toFixed(5) + ' (±' + acc + 'm)';
                } else {
                    statusEl.style.color = '#dc3545';
                    statusEl.textContent = '⚠ Save failed: ' + (d.message||'');
                }
            }).catch(function() { statusEl.style.color='#dc3545'; statusEl.textContent='⚠ Network error'; });
    }, function(err) {
        statusEl.style.color = '#dc3545';
        statusEl.textContent = '⚠ GPS error: ' + err.message;
    }, { enableHighAccuracy: true, timeout: 15000 });
};

// ── SAVE SURVEY ───────────────────────────────────────────────
window.schSaveSurvey = function() {
    var statusEl = document.getElementById('surveyStatus');
    if (!_currentJobId) return;
    statusEl.textContent = '💾 Saving…';
    statusEl.style.color = '#1565C0';
    var updateCrm = document.getElementById('srvUpdateCrm') && document.getElementById('srvUpdateCrm').checked;
    var payload = Object.assign({}, _surveyState, {
        job_id     : _currentJobId,
        client_id  : _currentClientId,
        update_crm_note : updateCrm ? 1 : 0,
    });
    apiPost('save_survey_result', payload)
        .then(function(d) {
            if (d.status === 'success') {
                statusEl.style.color = '#28a745';
                statusEl.textContent = '✅ Survey saved' + (updateCrm ? ' & UCRM updated' : '') + '!';
            } else {
                statusEl.style.color = '#dc3545';
                statusEl.textContent = '⚠ ' + (d.message||'Save failed');
            }
        }).catch(function() { statusEl.style.color='#dc3545'; statusEl.textContent='⚠ Network error'; });
};

// ── CREATE TICKET FROM JOB ────────────────────────────────────
window.schCreateTicketFromJob = function() {
    var content = document.getElementById('schDetailContent');
    var jd = content._jobData || {};
    var cl = jd.client || {};
    var subject  = (document.getElementById('tkSubject')||{}).value || '';
    var desc     = (document.getElementById('tkDesc')||{}).value || '';
    var priority = (document.getElementById('tkPriority')||{}).value || 'medium';
    var category = (document.getElementById('tkCategory')||{}).value || 'other';
    var statusEl = document.getElementById('ticketStatus');
    if (!subject.trim()) { statusEl.style.color='#dc3545'; statusEl.textContent='⚠ Subject is required'; return; }
    statusEl.textContent = 'Creating ticket…'; statusEl.style.color='#1565C0';
    apiPost('create_ticket', {
        customer_id   : cl.id || '',
        customer_name : cl.name || 'Unknown',
        subject       : subject,
        note          : desc + (jd.job ? '\n\n[Job #' + jd.job.id + ']' : ''),
        priority      : priority,
        category      : category,
    }).then(function(d) {
        if (d.status === 'success') {
            statusEl.style.color = '#28a745';
            statusEl.textContent = '✅ Ticket #' + (d.data.id||'') + ' created!';
            document.getElementById('tkSubject').value = '';
            document.getElementById('tkDesc').value    = '';
        } else {
            statusEl.style.color = '#dc3545';
            statusEl.textContent = '⚠ ' + (d.message||'Failed');
        }
    }).catch(function() { statusEl.style.color='#dc3545'; statusEl.textContent='⚠ Network error'; });
};

window.schCloseDetail = function() {
    document.getElementById('schDetailOverlay').style.display = 'none';
    document.body.style.overflow = '';
};

// Close on backdrop click
document.getElementById('schDetailOverlay').addEventListener('click', function(e) {
    if (e.target === this) schCloseDetail();
});

window.schToggleTask = function(taskId, done, jobId) {
    var el = document.getElementById('schTask_' + taskId);
    if (el) el.className = 'sch-task' + (done?' done':'');
    apiPost('scheduling_task_update', { task_id: taskId, done: done, job_id: jobId })
        .then(function(d) {
            if (done && d.status === 'success') {
                // Reload detail to update task count + action buttons state
                setTimeout(function() {
                    apiGet('scheduling_job_detail', '&job_id=' + jobId).then(function(resp) {
                        if (resp.status === 'success') renderJobDetail(resp.data);
                    });
                }, 600);
            }
        })
        .catch(function() { alert('Failed to update task — check connection'); });
};

// ── ACCEPT JOB ────────────────────────────────────────────────
window.schAcceptJob = function(jobId) {
    // No window.confirm() — bad UX on mobile PWA, use inline confirmation
    var btn = document.querySelector('[data-jobid="'+jobId+'"]');
    if (btn && btn.dataset.confirming) {
        // Second tap = confirmed
        btn.disabled = true;
        btn.textContent = '⏳ Accepting…';
        apiPost('scheduling_job_update', { job_id: jobId, status: 'open', notify_accept: 1 })
            .then(function(d) {
                if (d.status === 'success') {
                    showToast('✔ Job #' + jobId + ' accepted — you\'re on it!', 'success');
                    apiGet('scheduling_job_detail', '&job_id=' + jobId).then(function(r) {
                        if (r.status === 'success') renderJobDetail(r.data);
                    });
                    schLoadJobs();
                } else {
                    btn.disabled = false;
                    btn.textContent = '✔ Accept Job';
                    delete btn.dataset.confirming;
                    showToast('Failed: ' + (d.message||'Unknown error'), 'error');
                }
            });
    } else {
        // First tap = ask for confirmation inline
        if (btn) {
            btn.dataset.confirming = '1';
            var orig = btn.style.cssText;
            btn.style.background = '#d97706';
            btn.textContent = '⚠ Tap again to confirm';
            setTimeout(function() {
                if (btn.dataset.confirming) {
                    delete btn.dataset.confirming;
                    btn.style.cssText = orig;
                    btn.textContent = '✔ Accept Job';
                }
            }, 3000);
        }
    }
};

// ── COMPLETE JOB FORM ─────────────────────────────────────────
window.schOpenCompleteForm = function(jobId) {
    var overlay = document.createElement('div');
    overlay.id = 'schCompleteOverlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:4000;overflow-y:auto;display:flex;align-items:flex-start;justify-content:center;';
    overlay.innerHTML = '<div style="background:#f8fafc;max-width:520px;width:100%;margin:20px auto;border-radius:20px;overflow:hidden;">'
        + '<div style="background:linear-gradient(135deg,#1B5E20,#2E7D32);padding:16px 20px;color:#fff;display:flex;justify-content:space-between;align-items:center;">'
        + '<div><div style="font-size:11px;opacity:.6;font-weight:700;text-transform:uppercase;">Job #'+jobId+'</div><div style="font-size:17px;font-weight:800;">Complete Job</div></div>'
        + '<button onclick="document.getElementById(\'schCompleteOverlay\').remove()" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:10px;padding:10px 14px;font-size:20px;cursor:pointer;">✕</button>'
        + '</div>'
        + '<div style="padding:16px;">'
        // Photo: ONU/Starlink
        + '<div style="margin-bottom:14px;">'
        + '<div style="font-size:12px;font-weight:800;color:#374151;margin-bottom:6px;">📷 ONU / Starlink Photo <span style="color:#dc3545;">*</span></div>'
        + '<input type="file" id="scPhotoOnu" accept="image/*" capture="environment" onchange="scPreview(this,\'scPreviewOnu\')" style="width:100%;font-size:13px;">'
        + '<div id="scPreviewOnu" style="margin-top:6px;"></div>'
        + '</div>'
        // Photo: Router
        + '<div style="margin-bottom:14px;">'
        + '<div style="font-size:12px;font-weight:800;color:#374151;margin-bottom:6px;">📷 Router / Modem Photo <span style="color:#dc3545;">*</span></div>'
        + '<input type="file" id="scPhotoRouter" accept="image/*" capture="environment" onchange="scPreview(this,\'scPreviewRouter\')" style="width:100%;font-size:13px;">'
        + '<div id="scPreviewRouter" style="margin-top:6px;"></div>'
        + '</div>'
        // Comment
        + '<div style="margin-bottom:14px;">'
        + '<div style="font-size:12px;font-weight:800;color:#374151;margin-bottom:6px;">💬 Completion Notes (optional)</div>'
        + '<textarea id="scComment" rows="3" placeholder="e.g. Service activated, customer briefed on app…" style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px;font-size:13px;box-sizing:border-box;background:#f8fafc;"></textarea>'
        + '</div>'
        // GPS
        + '<div style="background:#E3F2FD;border-radius:10px;padding:10px 12px;margin-bottom:14px;font-size:12px;display:flex;align-items:center;gap:8px;">'
        + '<span style="font-size:18px;">📍</span>'
        + '<div id="scGpsStatus" style="flex:1;color:#1565C0;font-weight:600;">Getting your location…</div>'
        + '</div>'
        + '<input type="hidden" id="scLat"><input type="hidden" id="scLon">'
        // Error
        + '<div id="scError" style="color:#dc3545;font-size:12px;min-height:16px;margin-bottom:8px;"></div>'
        // Submit
        + '<button id="scSubmitBtn" onclick="scSubmit('+jobId+')" class="sch-act-btn" style="background:linear-gradient(135deg,#1B5E20,#2E7D32);color:#fff;font-size:15px;" disabled>✅ Submit &amp; Complete Job</button>'
        + '</div></div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function(e){ if(e.target===overlay) overlay.remove(); });

    // Get GPS
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(pos) {
            document.getElementById('scLat').value = pos.coords.latitude;
            document.getElementById('scLon').value = pos.coords.longitude;
            document.getElementById('scGpsStatus').textContent = '✅ GPS: ' + pos.coords.latitude.toFixed(5) + ', ' + pos.coords.longitude.toFixed(5);
            document.getElementById('scGpsStatus').style.color = '#2E7D32';
            document.getElementById('scSubmitBtn').disabled = false;
        }, function() {
            document.getElementById('scGpsStatus').textContent = '⚠ Location denied — you can still submit without GPS';
            document.getElementById('scGpsStatus').style.color = '#E65100';
            document.getElementById('scSubmitBtn').disabled = false;
        }, {timeout: 8000});
    } else {
        document.getElementById('scGpsStatus').textContent = 'GPS not available on this device';
        document.getElementById('scSubmitBtn').disabled = false;
    }
};

window.scPreview = function(inp, previewId) {
    var el = document.getElementById(previewId);
    if (!el || !inp.files || !inp.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        el.innerHTML = '<img src="'+e.target.result+'" style="max-width:100%;max-height:160px;border-radius:8px;border:1px solid #e2e8f0;">';
    };
    reader.readAsDataURL(inp.files[0]);
};

window.scSubmit = function(jobId) {
    var onuInp    = document.getElementById('scPhotoOnu');
    var routerInp = document.getElementById('scPhotoRouter');
    var comment   = (document.getElementById('scComment')||{}).value || '';
    var lat       = (document.getElementById('scLat')||{}).value || null;
    var lon       = (document.getElementById('scLon')||{}).value || null;
    var errEl     = document.getElementById('scError');
    var btn       = document.getElementById('scSubmitBtn');

    if (!onuInp || !onuInp.files || !onuInp.files[0]) {
        if (errEl) errEl.textContent = '⚠ ONU/Starlink photo is required.'; return;
    }
    if (!routerInp || !routerInp.files || !routerInp.files[0]) {
        if (errEl) errEl.textContent = '⚠ Router/Modem photo is required.'; return;
    }
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Submitting…'; }

    // Read both photos as base64 then submit
    var photos = [];
    var pending = 2;
    function readPhoto(file, type) {
        var r = new FileReader();
        r.onload = function(e) {
            photos.push({ type: type, data_url: e.target.result });
            pending--;
            if (pending === 0) doCompleteSubmit(jobId, photos, comment, lat, lon, btn, errEl);
        };
        r.readAsDataURL(file);
    }
    readPhoto(onuInp.files[0], 'onu');
    readPhoto(routerInp.files[0], 'router');
};

function doCompleteSubmit(jobId, photos, comment, lat, lon, btn, errEl) {
    apiPost('scheduling_complete', {
        job_id: jobId,
        photos: photos,
        comment: comment,
        lat: lat ? parseFloat(lat) : null,
        lon: lon ? parseFloat(lon) : null
    }).then(function(d) {
        var overlay = document.getElementById('schCompleteOverlay');
        if (overlay) overlay.remove();
        if (d.status === 'success') {
            showToast('✅ Job completed! WhatsApp confirmation sent.', 'success');
            schCloseDetail();
            schLoadJobs();
        } else {
            if (btn) { btn.disabled = false; btn.textContent = '✅ Submit & Complete Job'; }
            if (errEl) errEl.textContent = '⚠ ' + (d.message||'Failed');
        }
    }).catch(function() {
        if (btn) { btn.disabled = false; btn.textContent = '✅ Submit & Complete Job'; }
        if (errEl) errEl.textContent = '⚠ Network error — please retry';
    });
}

// ── RESCHEDULE FORM ───────────────────────────────────────────
window.schOpenReschedule = function(jobId) {
    var overlay = document.createElement('div');
    overlay.id = 'schRescheduleOverlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:4000;display:flex;align-items:center;justify-content:center;';
    // Default datetime = tomorrow 09:00
    var def = new Date(); def.setDate(def.getDate()+1); def.setHours(9,0,0,0);
    var defStr = def.toISOString().substring(0,16);
    overlay.innerHTML = '<div style="background:#f8fafc;max-width:420px;width:94%;border-radius:20px;overflow:hidden;">'
        + '<div style="background:linear-gradient(135deg,#E65100,#F57F17);padding:16px 20px;color:#fff;display:flex;justify-content:space-between;align-items:center;">'
        + '<div style="font-size:17px;font-weight:800;">📅 Reschedule Job #'+jobId+'</div>'
        + '<button onclick="document.getElementById(\'schRescheduleOverlay\').remove()" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:10px;padding:8px 12px;font-size:18px;cursor:pointer;">✕</button>'
        + '</div>'
        + '<div style="padding:16px;">'
        + '<div style="font-size:12px;font-weight:800;color:#374151;margin-bottom:6px;">New Date &amp; Time <span style="color:#dc3545;">*</span></div>'
        + '<input type="datetime-local" id="rsNewDate" value="'+defStr+'" style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px;font-size:13px;box-sizing:border-box;background:#f8fafc;margin-bottom:12px;">'
        + '<div style="font-size:12px;font-weight:800;color:#374151;margin-bottom:6px;">Reason (optional)</div>'
        + '<textarea id="rsComment" rows="2" placeholder="e.g. Customer not available, parts delayed…" style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px;font-size:13px;box-sizing:border-box;background:#f8fafc;margin-bottom:12px;"></textarea>'
        + '<div id="rsError" style="color:#dc3545;font-size:12px;min-height:14px;margin-bottom:8px;"></div>'
        + '<button id="rsSubmitBtn" onclick="rsSubmit('+jobId+')" class="sch-act-btn" style="background:linear-gradient(135deg,#E65100,#F57F17);color:#fff;">📅 Confirm Reschedule</button>'
        + '</div></div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function(e){ if(e.target===overlay) overlay.remove(); });
};

window.rsSubmit = function(jobId) {
    var newDate = (document.getElementById('rsNewDate')||{}).value || '';
    var comment = (document.getElementById('rsComment')||{}).value || '';
    var errEl   = document.getElementById('rsError');
    var btn     = document.getElementById('rsSubmitBtn');
    if (!newDate) { if(errEl) errEl.textContent = '⚠ Please select a date and time.'; return; }
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Saving…'; }
    // Convert datetime-local value to "YYYY-MM-DD HH:MM"
    var formatted = newDate.replace('T', ' ');
    apiPost('scheduling_reschedule', { job_id: jobId, new_date: formatted, comment: comment })
        .then(function(d) {
            var overlay = document.getElementById('schRescheduleOverlay');
            if (overlay) overlay.remove();
            if (d.status === 'success') {
                showToast('📅 Rescheduled — CRM updated', 'success');
                apiGet('scheduling_job_detail', '&job_id=' + jobId).then(function(r) {
                    if (r.status === 'success') renderJobDetail(r.data);
                });
                schLoadJobs();
            } else {
                if (btn) { btn.disabled = false; btn.textContent = '📅 Confirm Reschedule'; }
                if (errEl) errEl.textContent = '⚠ ' + (d.message||'Failed');
            }
        }).catch(function() {
            if (btn) { btn.disabled = false; btn.textContent = '📅 Confirm Reschedule'; }
            if (errEl) errEl.textContent = '⚠ Network error';
        });
};

// ── ADD COMMENT ───────────────────────────────────────────────
window.schOpenComment = function(jobId) {
    var overlay = document.createElement('div');
    overlay.id = 'schCommentOverlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:4000;display:flex;align-items:center;justify-content:center;';
    overlay.innerHTML = '<div style="background:#f8fafc;max-width:420px;width:94%;border-radius:20px;overflow:hidden;">'
        + '<div style="background:linear-gradient(135deg,#283593,#3949AB);padding:16px 20px;color:#fff;display:flex;justify-content:space-between;align-items:center;">'
        + '<div style="font-size:17px;font-weight:800;">💬 Add Comment — Job #'+jobId+'</div>'
        + '<button onclick="document.getElementById(\'schCommentOverlay\').remove()" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:10px;padding:8px 12px;font-size:18px;cursor:pointer;">✕</button>'
        + '</div>'
        + '<div style="padding:16px;">'
        + '<div style="font-size:12px;font-weight:800;color:#374151;margin-bottom:6px;">Comment</div>'
        + '<textarea id="cmComment" rows="4" placeholder="Add a note to this job — visible in UCRM for Bidal and admin…" style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px;font-size:13px;box-sizing:border-box;background:#f8fafc;margin-bottom:12px;"></textarea>'
        + '<div id="cmError" style="color:#dc3545;font-size:12px;min-height:14px;margin-bottom:8px;"></div>'
        + '<button id="cmSubmitBtn" onclick="cmSubmit('+jobId+')" class="sch-act-btn" style="background:linear-gradient(135deg,#283593,#3949AB);color:#fff;">💬 Save Comment to CRM</button>'
        + '</div></div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function(e){ if(e.target===overlay) overlay.remove(); });
    setTimeout(function(){ var el = document.getElementById('cmComment'); if(el) el.focus(); }, 100);
};

window.cmSubmit = function(jobId) {
    var comment = ((document.getElementById('cmComment')||{}).value||'').trim();
    var errEl   = document.getElementById('cmError');
    var btn     = document.getElementById('cmSubmitBtn');
    if (!comment) { if(errEl) errEl.textContent = '⚠ Please enter a comment.'; return; }
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Saving…'; }
    apiPost('scheduling_add_comment', { job_id: jobId, comment: comment })
        .then(function(d) {
            var overlay = document.getElementById('schCommentOverlay');
            if (overlay) overlay.remove();
            if (d.status === 'success') {
                showToast('💬 Comment saved to CRM', 'success');
            } else {
                showToast('⚠ ' + (d.message||'Failed to save'), 'error');
            }
        }).catch(function() {
            if (btn) { btn.disabled = false; btn.textContent = '💬 Save Comment to CRM'; }
            if (errEl) errEl.textContent = '⚠ Network error';
        });
};



function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Auto-load on page open — force refresh if ?refresh=1 in URL
var _urlRefresh = new URLSearchParams(window.location.search).get('refresh') === '1';
// ── Wire toolbar buttons (defined here, rendered earlier in DOM) ──────────
document.getElementById('schRefreshBtn')  && (document.getElementById('schRefreshBtn').onclick  = function(){ schLoadJobs(); });
document.getElementById('schClearCacheBtn') && (document.getElementById('schClearCacheBtn').onclick = function(){ schClearCache(); });
document.getElementById('schNewJobBtn')   && (document.getElementById('schNewJobBtn').onclick   = function(){ njOpen(); });

schLoadJobs(_urlRefresh);

// ── Auto-refresh: re-fetch jobs every 2 minutes silently ──────────────────
(function() {
    var _schAutoRefreshTimer = null;
    function _schSilentRefresh() {
        // Only refresh if tab is visible and not a detail view
        if (document.hidden) return;
        if (window.location.search.indexOf('job=') !== -1) return;
        apiGet('scheduling_jobs', '').then(function(d) {
            if (d.status !== 'success') return;
            // Re-render only if jobs list is visible (not inside a modal)
            var overlay = document.getElementById('schDetailOverlay');
            if (overlay && overlay.style.display !== 'none') return;
            // Trigger full re-render quietly (no loading spinner)
            var jobs = d.data && d.data.jobs ? d.data.jobs : [];
            var ageEl = document.getElementById('schCacheAge');
            if (ageEl) {
                ageEl.textContent = d.data.from_cache
                    ? ('⚡ Cached · synced ' + (d.data.last_sync || '').substring(0,16) + ' · auto-refreshes every 2 min')
                    : ('🌐 Live · ' + (d.data.last_sync || '').substring(0,16));
            }
            var statEl = document.getElementById('schStatOpen');
            if (statEl) {
                var open = jobs.filter(function(j){ return !isClosed(j); }).length;
                var done = jobs.filter(function(j){ return  isClosed(j); }).length;
                statEl.textContent = open + ' Active · ' + done + ' Done';
            }
        }).catch(function() { /* silent */ });
    }
    // Start interval after initial load settles
    setTimeout(function() {
        _schAutoRefreshTimer = setInterval(_schSilentRefresh, 120000); // every 2 min
    }, 15000);
    // Also refresh when tab becomes visible again (user switches back)
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            setTimeout(_schSilentRefresh, 1000);
        }
    });
})();

// Close outer scheduling IIFE
})();

/* ═══════════════════════════════════════════════════════════════════
   NEW JOB MODAL — quick job creator, all roles, multi-engineer
   ═══════════════════════════════════════════════════════════════════ */
</script>

<!-- New Job Modal — 3-step wizard (full-screen) -->
<div id="njOverlay" style="display:none;position:fixed;inset:0;background:#0f1724;z-index:4000;overflow-y:auto;">
<div style="max-width:600px;margin:0 auto;min-height:100vh;display:flex;flex-direction:column;">

  <!-- Header -->
  <div style="background:linear-gradient(135deg,#059669,#047857);padding:14px 16px;flex-shrink:0;position:sticky;top:0;z-index:1;">
    <div style="display:flex;justify-content:space-between;align-items:center;">
      <div>
        <div style="font-size:11px;color:rgba(255,255,255,.7);font-weight:700;text-transform:uppercase;letter-spacing:1px;">New Job</div>
        <div id="njStepTitle" style="font-size:18px;font-weight:800;color:#fff;margin-top:1px;">① Job Details</div>
      </div>
      <button onclick="njClose()" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:36px;height:36px;border-radius:50%;font-size:20px;cursor:pointer;line-height:1;flex-shrink:0;">✕</button>
    </div>
    <div style="display:flex;gap:6px;margin-top:10px;">
      <div id="njDot1" style="height:4px;border-radius:2px;background:#fff;flex:1;transition:.3s;"></div>
      <div id="njDot2" style="height:4px;border-radius:2px;background:rgba(255,255,255,.3);flex:1;transition:.3s;"></div>
      <div id="njDot3" style="height:4px;border-radius:2px;background:rgba(255,255,255,.3);flex:1;transition:.3s;"></div>
    </div>
  </div>

  <!-- STEP 1 -->
  <div id="njStep1" style="padding:16px;display:flex;flex-direction:column;gap:16px;flex:1;">
    <div>
      <label style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:6px;">Job Title *</label>
      <input id="njTitle" type="text" placeholder="Type or pick a template below…"
        style="width:100%;background:#1e293b;border:1.5px solid #334155;border-radius:10px;padding:13px 14px;color:#e2e8f0;font-size:15px;box-sizing:border-box;outline:none;"
        onfocus="this.style.borderColor='#22c55e'" onblur="this.style.borderColor='#334155'">
      <div style="margin-top:10px;">
        <div style="font-size:11px;color:#64748b;margin-bottom:7px;font-weight:600;">📋 QUICK TEMPLATES — scroll →</div>
        <div id="njTitleChips" style="display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;-webkit-overflow-scrolling:touch;scrollbar-width:none;">
          <div style="color:#64748b;font-size:12px;white-space:nowrap;">Loading…</div>
        </div>
      </div>
    </div>
    <div>
      <label style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:6px;">Customer <span style="font-weight:400;">(optional)</span></label>
      <input id="njCustSearch" type="text" placeholder="Search by name or CRM ID…"
        style="width:100%;background:#1e293b;border:1.5px solid #334155;border-radius:10px;padding:13px 14px;color:#e2e8f0;font-size:15px;box-sizing:border-box;outline:none;"
        onfocus="this.style.borderColor='#22c55e'" onblur="this.style.borderColor='#334155'"
        oninput="njSearchCust(this.value)">
      <div id="njCustResults" style="display:none;background:#1e293b;border:1px solid #334155;border-radius:10px;margin-top:4px;max-height:200px;overflow-y:auto;"></div>
      <div id="njCustSelected" style="display:none;background:#064e3b;border:1px solid #059669;border-radius:10px;padding:10px 14px;margin-top:6px;font-size:13px;color:#6ee7b7;justify-content:space-between;align-items:center;">
        <span id="njCustName"></span>
        <button onclick="njClearCust()" style="background:none;border:none;color:#6ee7b7;cursor:pointer;font-size:18px;padding:0 4px;">✕</button>
      </div>
      <input type="hidden" id="njCrmClientId" value="0">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div>
        <label style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:6px;">Date *</label>
        <input id="njDate" type="date" style="width:100%;background:#1e293b;border:1.5px solid #334155;border-radius:10px;padding:12px;color:#e2e8f0;font-size:14px;box-sizing:border-box;outline:none;">
      </div>
      <div>
        <label style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:6px;">Time</label>
        <input id="njTime" type="time" value="09:00" style="width:100%;background:#1e293b;border:1.5px solid #334155;border-radius:10px;padding:12px;color:#e2e8f0;font-size:14px;box-sizing:border-box;outline:none;">
      </div>
    </div>
    <div>
      <label style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:6px;">Duration</label>
      <select id="njDuration" style="width:100%;background:#1e293b;border:1.5px solid #334155;border-radius:10px;padding:12px;color:#e2e8f0;font-size:14px;box-sizing:border-box;outline:none;">
        <option value="30">30 min</option><option value="60" selected>1 hr</option>
        <option value="90">1.5 hr</option><option value="120">2 hr</option>
        <option value="180">3 hr</option><option value="240">4 hr</option>
      </select>
    </div>
    <button onclick="njGoStep(2)" style="width:100%;background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;border-radius:14px;padding:16px;font-size:15px;font-weight:800;cursor:pointer;">Next: Assign Engineers →</button>
  </div>

  <!-- STEP 2 -->
  <div id="njStep2" style="display:none;padding:16px;flex-direction:column;gap:16px;flex:1;">
    <div style="font-size:13px;color:#94a3b8;background:#1e293b;border-radius:10px;padding:12px 14px;">
      💡 Tap to select. <strong style="color:#e2e8f0;">One UCRM job per engineer.</strong>
    </div>
    <div>
      <label style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:10px;">Select Engineers *</label>
      <div id="njEngList" style="display:flex;flex-wrap:wrap;gap:10px;min-height:60px;"><div style="color:#64748b;font-size:13px;padding:8px 0;">Loading engineers…</div></div>
    </div>
    <div id="njEngSelSummary" style="display:none;background:#064e3b;border-radius:10px;padding:12px 14px;font-size:13px;color:#6ee7b7;"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:auto;">
      <button onclick="njGoStep(1)" style="background:#1e293b;color:#94a3b8;border:1px solid #334155;border-radius:14px;padding:16px;font-size:15px;font-weight:700;cursor:pointer;">← Back</button>
      <button onclick="njGoStep(3)" style="background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;border-radius:14px;padding:16px;font-size:15px;font-weight:800;cursor:pointer;">Next: Notes →</button>
    </div>
  </div>

  <!-- STEP 3 -->
  <div id="njStep3" style="display:none;padding:16px;flex-direction:column;gap:16px;flex:1;">
    <div id="njSummaryPill" style="background:#1e293b;border:1px solid #334155;border-radius:12px;padding:14px;font-size:13px;color:#94a3b8;line-height:1.8;"></div>
    <div>
      <label style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:6px;">Notes / Description</label>
      <textarea id="njDesc" rows="4" placeholder="Optional notes for the engineer…"
        style="width:100%;background:#1e293b;border:1.5px solid #334155;border-radius:10px;padding:12px 14px;color:#e2e8f0;font-size:14px;box-sizing:border-box;outline:none;resize:vertical;"></textarea>
    </div>
    <div>
      <label style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;display:block;margin-bottom:6px;">Tasks Checklist <span style="font-weight:400;">(optional)</span></label>
      <div id="njTaskList" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;"></div>
      <div style="display:flex;gap:8px;">
        <input id="njTaskInput" type="text" placeholder="Add a task…"
          style="flex:1;background:#1e293b;border:1.5px solid #334155;border-radius:10px;padding:11px 14px;color:#e2e8f0;font-size:14px;outline:none;"
          onkeydown="if(event.key==='Enter'){event.preventDefault();njAddTask();}">
        <button onclick="njAddTask()" style="background:#334155;color:#94a3b8;border:none;border-radius:10px;padding:11px 16px;font-size:13px;font-weight:700;cursor:pointer;">+ Add</button>
      </div>
    </div>
    <label style="display:flex;align-items:center;gap:12px;cursor:pointer;background:#1e293b;border-radius:12px;padding:14px;">
      <input type="checkbox" id="njNotifyWa" checked style="width:20px;height:20px;cursor:pointer;flex-shrink:0;">
      <div>
        <div style="font-size:14px;font-weight:700;color:#e2e8f0;">📱 Send WhatsApp notification</div>
        <div style="font-size:12px;color:#64748b;">Notify assigned engineers immediately</div>
      </div>
    </label>
    <div id="njResult" style="display:none;"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <button onclick="njGoStep(2)" style="background:#1e293b;color:#94a3b8;border:1px solid #334155;border-radius:14px;padding:16px;font-size:15px;font-weight:700;cursor:pointer;">← Back</button>
      <button id="njSubmitBtn" onclick="njSubmit()" style="background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;border-radius:14px;padding:16px;font-size:15px;font-weight:800;cursor:pointer;box-shadow:0 4px 16px rgba(5,150,105,.35);">＋ Create Jobs</button>
    </div>
  </div>

</div>
</div>


<script>
/* ── New Job Modal — 3-step wizard ── */
var _njEngineers = [];
var _njSelected  = {};
var _njTasks     = [];
var _njCustTimer = null;
var _njStep      = 1;
var _njTitles    = [];

var _njStepTitles = {1:'① Job Details', 2:'② Assign Engineers', 3:'③ Review & Create'};

function njLoadTitles() {
  if (_njTitles.length) { njRenderTitleChips(); return; }
  fetch('?page=api&action=job_titles', {credentials:'same-origin',headers:{'Authorization':'Bearer '+_apiToken}})
    .then(function(r){return r.json();}).then(function(d){
      _njTitles = (d.data && d.data.titles) ? d.data.titles : [];
      njRenderTitleChips();
    }).catch(function(){
      document.getElementById('njTitleChips').innerHTML = '<div style="color:#64748b;font-size:12px;">Could not load templates</div>';
    });
}

function njRenderTitleChips() {
  var html = '';
  _njTitles.forEach(function(t) {
    html += '<button onclick="njPickTitle(\''+t.replace(/'/g,"\\'")+'\')" style="'
      +'flex-shrink:0;padding:9px 16px;border-radius:20px;border:1.5px solid #334155;background:#1e293b;'
      +'color:#94a3b8;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;transition:.15s;"'
      +' onmouseover="this.style.borderColor=\'#22c55e\';this.style.color=\'#6ee7b7\';this.style.background=\'#064e3b\'"'
      +' onmouseout="this.style.borderColor=\'#334155\';this.style.color=\'#94a3b8\';this.style.background=\'#1e293b\'">'
      +t+'</button>';
  });
  document.getElementById('njTitleChips').innerHTML = html || '<div style="color:#64748b;font-size:12px;">No templates</div>';
}

function njPickTitle(t) {
  document.getElementById('njTitle').value = t;
}

function njGoStep(n) {
  // Validate before advancing
  if (n > 1) {
    var title = document.getElementById('njTitle').value.trim();
    var date  = document.getElementById('njDate').value;
    if (!title) { alert('Please enter a job title.'); return; }
    if (!date)  { alert('Please select a date.'); return; }
  }
  if (n > 2) {
    if (!Object.keys(_njSelected).length) { alert('Please select at least one engineer.'); return; }
  }
  _njStep = n;
  document.getElementById('njStep1').style.display = n === 1 ? 'flex' : 'none';
  document.getElementById('njStep2').style.display = n === 2 ? 'flex' : 'none';
  document.getElementById('njStep3').style.display = n === 3 ? 'flex' : 'none';
  document.getElementById('njStepTitle').textContent = _njStepTitles[n];
  // Step dots
  [1,2,3].forEach(function(i) {
    document.getElementById('njDot'+i).style.background = i <= n ? '#fff' : 'rgba(255,255,255,.3)';
  });
  if (n === 2) njLoadEngineers();
  if (n === 3) njBuildSummary();
}

function njBuildSummary() {
  var title    = document.getElementById('njTitle').value.trim();
  var date     = document.getElementById('njDate').value;
  var time     = document.getElementById('njTime').value || '09:00';
  var dur      = document.getElementById('njDuration');
  var durLabel = dur.options[dur.selectedIndex].text;
  var engNames = Object.keys(_njSelected).map(function(id) {
    var e = _njEngineers.find(function(x) { return x.ucrm_user_id == id; });
    return e ? e.name : 'ID:'+id;
  });
  var custEl = document.getElementById('njCustName');
  var cust   = custEl ? custEl.textContent : '';
  document.getElementById('njSummaryPill').innerHTML =
    '📋 <strong style="color:#e2e8f0;">' + title + '</strong><br>' +
    '📅 ' + date + ' at ' + time + ' · ' + durLabel + '<br>' +
    '🔧 ' + engNames.join(', ') +
    (cust ? '<br>👤 ' + cust : '');
}

window._njOpenReady = window.njOpen = function njOpen() {
  document.getElementById('njDate').value = new Date().toISOString().split('T')[0];
  document.getElementById('njOverlay').style.display = 'block';
  document.body.style.overflow = 'hidden';
  njLoadTitles();
  // Reset
  document.getElementById('njTitle').value    = '';
  document.getElementById('njDesc').value     = '';
  document.getElementById('njCustSearch').value = '';
  document.getElementById('njCrmClientId').value = '0';
  document.getElementById('njCustResults').style.display = 'none';
  document.getElementById('njCustSelected').style.display = 'none';
  document.getElementById('njNotifyWa').checked = true;
  document.getElementById('njResult').style.display = 'none';
  var sb = document.getElementById('njSubmitBtn');
  sb.disabled = false; sb.textContent = '＋ Create Jobs';
  sb.onclick = njSubmit;
  _njSelected = {}; _njTasks = [];
  njRenderTasks();
  njGoStep(1);
}

window._njCloseReady = window.njClose = function njClose() {
  document.getElementById('njOverlay').style.display = 'none';
  document.body.style.overflow = '';
}

function njLoadEngineers() {
  if (_njEngineers.length) { njRenderEngineers(); return; }
  document.getElementById('njEngList').innerHTML = '<div style="color:#64748b;font-size:13px;padding:8px 0;">Loading…</div>';
  fetch('?page=api&action=support_engineers', {
    headers: {'Authorization': 'Bearer ' + _apiToken}
  }).then(function(r){ return r.json(); }).then(function(d) {
    _njEngineers = (d.data && d.data.agents) ? d.data.agents : [];
    njRenderEngineers();
  }).catch(function() {
    document.getElementById('njEngList').innerHTML = '<div style="color:#ef4444;font-size:12px;">Failed to load engineers</div>';
  });
}

function njRenderEngineers() {
  var html = '';
  _njEngineers.forEach(function(e) {
    var sel = !!_njSelected[e.ucrm_user_id];
    html += '<button onclick="njToggleEng(' + e.ucrm_user_id + ')" id="njEng_' + e.ucrm_user_id + '" style="'
      + 'padding:10px 16px;border-radius:20px;border:2px solid ' + (sel ? '#22c55e' : '#334155') + ';'
      + 'background:' + (sel ? '#064e3b' : '#0f1724') + ';'
      + 'color:' + (sel ? '#6ee7b7' : '#94a3b8') + ';'
      + 'font-size:13px;font-weight:700;cursor:pointer;transition:.15s;">'
      + (sel ? '✓ ' : '') + e.name + '</button>';
  });
  if (!html) html = '<div style="color:#64748b;font-size:12px;">No engineers found with UCRM ID set</div>';
  document.getElementById('njEngList').innerHTML = html;
  njUpdateEngSummary();
}

function njToggleEng(ucrmId) {
  if (_njSelected[ucrmId]) { delete _njSelected[ucrmId]; }
  else { _njSelected[ucrmId] = true; }
  njRenderEngineers();
}

function njUpdateEngSummary() {
  var sel = Object.keys(_njSelected);
  var sumEl = document.getElementById('njEngSelSummary');
  if (!sel.length) { sumEl.style.display = 'none'; return; }
  var names = sel.map(function(id) {
    var e = _njEngineers.find(function(x){ return x.ucrm_user_id == id; });
    return e ? e.name : 'ID:'+id;
  });
  sumEl.style.display = 'block';
  sumEl.textContent = '✓ Selected: ' + names.join(', ') + ' (' + sel.length + ' job' + (sel.length > 1 ? 's' : '') + ' will be created)';
}

function njSearchCust(val) {
  clearTimeout(_njCustTimer);
  if (val.length < 2) { document.getElementById('njCustResults').style.display = 'none'; return; }
  _njCustTimer = setTimeout(function() {
    fetch('?page=api&action=crm_search_customer&q=' + encodeURIComponent(val), {
      headers: {'Authorization': 'Bearer ' + _apiToken}
    }).then(function(r){ return r.json(); }).then(function(d) {
      var clients = Array.isArray(d.data) ? d.data : (Array.isArray(d) ? d : []);
      clients = clients.filter(function(c) { return c.source === 'ucrm' || c.ucrm_id; });
      var html = '';
      clients.slice(0, 8).forEach(function(c) {
        var name = c.name || (c.firstName + ' ' + c.lastName);
        var cid  = c.id || c.ucrm_id;
        html += '<div onclick="njSelectCust(' + cid + ',\'' + name.replace(/'/g,'') + '\')" '
          + 'style="padding:9px 12px;cursor:pointer;border-bottom:1px solid #1e293b;color:#e2e8f0;font-size:13px;" '
          + 'onmouseover="this.style.background=\'#1e293b\'" onmouseout="this.style.background=\'\'">'
          + '<strong>' + name + '</strong>'
          + '<span style="color:#64748b;font-size:11px;margin-left:8px;">CRM #' + cid + '</span>'
          + (c.street1 ? '<br><span style="color:#64748b;font-size:11px;">' + c.street1 + '</span>' : '')
          + '</div>';
      });
      if (!html) html = '<div style="padding:10px 12px;color:#64748b;font-size:12px;">No clients found</div>';
      var res = document.getElementById('njCustResults');
      res.innerHTML = html; res.style.display = 'block';
    });
  }, 350);
}

function njSelectCust(id, name) {
  document.getElementById('njCrmClientId').value = id;
  document.getElementById('njCustName').textContent = '👤 ' + name + ' (CRM #' + id + ')';
  document.getElementById('njCustSelected').style.display = 'flex';
  document.getElementById('njCustResults').style.display = 'none';
  document.getElementById('njCustSearch').value = '';
}

function njClearCust() {
  document.getElementById('njCrmClientId').value = '0';
  document.getElementById('njCustSelected').style.display = 'none';
}

function njAddTask() {
  var inp = document.getElementById('njTaskInput');
  var val = inp.value.trim();
  if (!val) return;
  _njTasks.push(val); inp.value = '';
  njRenderTasks();
}

function njRemoveTask(i) {
  _njTasks.splice(i, 1); njRenderTasks();
}

function njRenderTasks() {
  var html = '';
  _njTasks.forEach(function(t, i) {
    html += '<div style="display:flex;align-items:center;gap:8px;background:#0f1724;border-radius:8px;padding:7px 12px;">'
      + '<span style="color:#94a3b8;font-size:12px;flex:1;">☐ ' + t + '</span>'
      + '<button onclick="njRemoveTask(' + i + ')" style="background:none;border:none;color:#64748b;cursor:pointer;font-size:14px;">✕</button>'
      + '</div>';
  });
  document.getElementById('njTaskList').innerHTML = html;
}

function njSubmit() {
  var title  = document.getElementById('njTitle').value.trim();
  var date   = document.getElementById('njDate').value;
  var time   = document.getElementById('njTime').value || '09:00';
  var engIds = Object.keys(_njSelected).map(Number);

  if (!title || !date || !engIds.length) { alert('Please complete all required fields.'); return; }

  var btn = document.getElementById('njSubmitBtn');
  btn.disabled = true;
  btn.textContent = '⏳ Creating ' + engIds.length + ' job' + (engIds.length > 1 ? 's' : '') + '…';

  var payload = {
    title:         title,
    date:          date,
    time:          time,
    duration:      parseInt(document.getElementById('njDuration').value),
    description:   document.getElementById('njDesc').value.trim(),
    crm_client_id: parseInt(document.getElementById('njCrmClientId').value) || 0,
    engineer_ids:  engIds,
    tasks:         _njTasks,
    notify_wa:     document.getElementById('njNotifyWa').checked ? 1 : 0,
  };

  fetch('?page=api&action=create_job', {
          credentials:'same-origin',
          method: 'POST',
    headers: {'Authorization': 'Bearer ' + _apiToken, 'Content-Type': 'application/json'},
    body: JSON.stringify(payload)
  }).then(function(r){ return r.json(); }).then(function(d) {
    var res = document.getElementById('njResult');
    if (d.code === 200 || d.status === 'ok') {
      var data = d.data || d;
      var html = '<div style="background:#064e3b;border-radius:12px;padding:14px;">'
        + '<div style="font-size:14px;font-weight:800;color:#6ee7b7;margin-bottom:8px;">✅ ' + data.created + ' job' + (data.created !== 1 ? 's' : '') + ' created!</div>';
      (data.jobs || []).forEach(function(j) {
        html += '<div style="font-size:12px;color:#a7f3d0;padding:3px 0;">🔧 Job #' + j.job_id + ' → ' + j.engineer_name + (j.notified ? ' 📱' : '') + '</div>';
      });
      if (data.errors && data.errors.length) {
        data.errors.forEach(function(e) {
          html += '<div style="font-size:12px;color:#fca5a5;padding:3px 0;">❌ ' + e.error + '</div>';
        });
      }
      html += '</div>';
      res.innerHTML = html; res.style.display = 'block';
      btn.textContent = '✅ Done — Close';
      btn.disabled = false;
      btn.onclick = function() { njClose(); schLoadJobs(true); };
      _njSelected = {}; _njTasks = [];
    } else {
      res.innerHTML = '<div style="background:#450a0a;border-radius:10px;padding:12px;color:#fca5a5;font-size:13px;">❌ ' + (d.message || 'Failed') + '</div>';
      res.style.display = 'block';
      btn.disabled = false; btn.textContent = '＋ Create Jobs';
    }
  }).catch(function() {
    document.getElementById('njResult').innerHTML = '<div style="background:#450a0a;border-radius:10px;padding:12px;color:#fca5a5;font-size:13px;">❌ Network error</div>';
    document.getElementById('njResult').style.display = 'block';
    btn.disabled = false; btn.textContent = '＋ Create Jobs';
  });
}

// Full-screen modal — no backdrop tap-to-close
</script>
<?php endif; // if ($jobDetailId) ?>
<?php endif; // else (has ucrm mapping) ?>
<div style="height:80px;"></div>

