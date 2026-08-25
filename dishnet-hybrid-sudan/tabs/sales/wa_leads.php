<?php
// ══════════════════════════════════════════════════════════════════════
// WA Leads — Self-contained Lead Recovery Tab
// Everything in one place: upload, CRM match, follow-up, status
// Included from public.php — $store, $config, $isAdmin, csrfField(), h() available
// ══════════════════════════════════════════════════════════════════════

require_once dirname(__DIR__, 2) . '/lib/LeadRecoveryService.php';
$leadSvc = new LeadRecoveryService($store->getPdo());

// ── Handle POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['wl_action'])) {
    $act = $_POST['wl_action'];

    if ($act === 'import') {
        if (!empty($_FILES['lead_file']['tmp_name'])) {
            $raw = file_get_contents($_FILES['lead_file']['tmp_name']);
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data)) {
                file_put_contents($dataDir . '/wa_leads_import.json', $raw);
                $count = $leadSvc->importContacts($data);
                flash("Imported {$count} WhatsApp contacts.", 'success');
            } else {
                flash('Invalid JSON file.', 'danger');
            }
        } else {
            flash('No file selected.', 'danger');
        }
        redirect('?page=dashboard&tab=wa_leads');
    }

    if ($act === 'crm_match') {
        $crmClients = $store->load('ucrm_clients_cache.json') ?? [];
        $searchIdx  = $store->load('ucrm_search_index.json') ?? [];
        if (empty($crmClients) && empty($searchIdx)) {
            flash('CRM client cache is empty. Run a Data Sync from Settings first.', 'danger');
        } else {
            $crmPhoneMap = [];
            foreach ($searchIdx as $entry) {
                $p = preg_replace('/[^0-9]/', '', $entry['phone'] ?? '');
                if ($p && !empty($entry['id'])) $crmPhoneMap[$p] = ['id' => $entry['id'], 'name' => $entry['name'] ?? ''];
            }
            foreach ($crmClients as $client) {
                $cid = $client['id'] ?? null;
                $name = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''));
                if (!$name) $name = $client['companyName'] ?? '';
                foreach ($client['contacts'] ?? [] as $ct) {
                    $p = preg_replace('/[^0-9]/', '', $ct['phone'] ?? '');
                    if ($p && $cid) $crmPhoneMap[$p] = ['id' => $cid, 'name' => $name];
                }
                $p = preg_replace('/[^0-9]/', '', $client['phone'] ?? '');
                if ($p && $cid) $crmPhoneMap[$p] = ['id' => $cid, 'name' => $name];
            }
            $result = $leadSvc->crossReferenceLocal($crmPhoneMap);
            flash("Matched against " . count($crmPhoneMap) . " CRM phones. Found {$result['leads']} leads, {$result['customers']} customers.", 'success');
        }
        redirect('?page=dashboard&tab=wa_leads');
    }

    if ($act === 'send_followup') {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $phone  = trim($_POST['lead_phone'] ?? '');
        $msg    = trim($_POST['followup_msg'] ?? '');
        if ($leadId && $phone && $msg) {
            $sent = false;
            $evoUrl  = trim($config['evo_api_url'] ?? '');
            $evoKey  = trim($config['evo_api_key'] ?? '');
            $evoInst = trim($config['evo_instance_name'] ?? '');
            if ($evoUrl && $evoKey && $evoInst) {
                require_once dirname(__DIR__, 2) . '/lib/EvolutionApiClient.php';
                $evo = new EvolutionApiClient($evoUrl, $evoKey, $evoInst);
                $result = $evo->sendText($phone, $msg);
                $sent = empty($result['error']);
            }
            if (!$sent) {
                $notif = svc('notify');
                $sent = $notif->sendVia('support', $phone, $msg);
            }
            if ($sent) {
                $leadSvc->markFollowedUp($leadId, "Sent: " . mb_substr($msg, 0, 80));
                flash("Follow-up sent to {$phone}", 'success');
            } else {
                flash("Failed to send to {$phone}", 'danger');
            }
        }
        redirect('?page=dashboard&tab=wa_leads');
    }

    if ($act === 'update_status') {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $status = trim($_POST['lead_status'] ?? '');
        if ($leadId && $status) $leadSvc->updateStatus($leadId, $status);
        redirect('?page=dashboard&tab=wa_leads');
    }

    if ($act === 'import_messages') {
        if (!empty($_FILES['msg_file']['tmp_name'])) {
            $raw = file_get_contents($_FILES['msg_file']['tmp_name']);
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data)) {
                $count = $leadSvc->importLastMessages($data);
                flash("Attached last message to {$count} leads.", 'success');
            } else {
                flash('Invalid JSON.', 'danger');
            }
        }
        redirect('?page=dashboard&tab=wa_leads');
    }
}

// ── Load data ───────────────────────────────────────────────────────
$leadStats   = $leadSvc->getStats();
$leadFilter  = $_GET['lf'] ?? '';
$leadSearch  = $_GET['ls'] ?? '';
$leadCountry = $_GET['country'] ?? '';
$leads = [];
try {
    $filters = [];
    if ($leadFilter)  $filters['status']  = $leadFilter;
    if ($leadCountry) $filters['country'] = $leadCountry;
    if ($leadSearch)  $filters['search']  = $leadSearch;
    $perPage = 50;
    $page = max(1, (int)($_GET['p'] ?? 1));
    $result = $leadSvc->getLeads($filters, $perPage, ($page - 1) * $perPage);
    $leads = $result['rows'] ?? [];
    $totalLeads2 = $result['total'] ?? 0;
    $totalPages = max(1, (int)ceil($totalLeads2 / $perPage));
} catch (Throwable $e) {}

$hasContacts = ($leadStats['total_contacts'] ?? 0) > 0;
$hasLeads    = ($leadStats['total_leads'] ?? 0) > 0;
$statusColors = [
    'new'=>['#fee2e2','#991b1b'], 'followed_up'=>['#dbeafe','#1d4ed8'],
    'interested'=>['#dcfce7','#15803d'], 'not_interested'=>['#f1f5f9','#6b7280'],
    'converted'=>['#d1fae5','#065f46'], 'customer'=>['#dbeafe','#1d4ed8'],
];
$countryFlags = ['SS'=>'🇸🇸','IN'=>'🇮🇳','AE'=>'🇦🇪','UG'=>'🇺🇬','KE'=>'🇰🇪','SD'=>'🇸🇩','ET'=>'🇪🇹','US'=>'🇺🇸','OTHER'=>'🌍'];
$tagColors = ['STARLINK'=>['#f5f3ff','#7C3AED'], 'FIBER'=>['#dbeafe','#1d4ed8'], 'PRICE'=>['#fef3c7','#92400e'], 'INTERNET'=>['#d1fae5','#065f46'], 'INSTALL'=>['#fee2e2','#991b1b']];
?>

<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
    <div style="font-size:28px;">🎯</div>
    <div>
        <div style="font-size:18px;font-weight:800;color:#1e293b;">WA Lead Recovery</div>
        <div style="font-size:12px;color:#6b7280;">People who contacted sales WhatsApp but never became customers</div>
    </div>
</div>

<?php if (!$hasContacts): ?>
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:40px;text-align:center;">
    <div style="font-size:15px;font-weight:800;color:#1e293b;margin-bottom:6px;">Step 1: Upload WhatsApp Contacts</div>
    <div style="font-size:12px;color:#6b7280;margin-bottom:20px;max-width:480px;margin-left:auto;margin-right:auto;">
        Export contacts from your Evolution API database and upload the JSON file here.
    </div>
    <form method="POST" action="?page=dashboard&tab=wa_leads" enctype="multipart/form-data" style="display:inline-flex;flex-direction:column;gap:12px;align-items:center;">
        <?= csrfField() ?>
        <input type="hidden" name="wl_action" value="import">
        <label style="background:#f8fafc;border:2px dashed #d1d5db;border-radius:12px;padding:24px 48px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:8px;" onmouseover="this.style.borderColor='#7C3AED'" onmouseout="this.style.borderColor='#d1d5db'">
            <span style="font-size:32px;">📄</span>
            <span style="font-size:13px;font-weight:700;color:#374151;">Click to select JSON file</span>
            <span style="font-size:11px;color:#9ca3af;">Query export with phone, name, interests</span>
            <input type="file" name="lead_file" accept=".json" required style="display:none;" onchange="this.closest('form').querySelector('.fn').textContent=this.files[0]?.name||'';this.closest('form').querySelector('.btn').style.display='inline-block'">
        </label>
        <span class="fn" style="font-size:12px;color:#7C3AED;font-weight:700;"></span>
        <button type="submit" class="btn" style="display:none;background:#7C3AED;color:#fff;border:none;border-radius:10px;padding:10px 28px;font-size:13px;font-weight:700;cursor:pointer;">📥 Import Contacts</button>
    </form>
</div>

<?php elseif (!$hasLeads): ?>
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:40px;text-align:center;">
    <div style="font-size:15px;font-weight:800;color:#1e293b;margin-bottom:6px;"><?= number_format($leadStats['total_contacts'] ?? 0) ?> contacts imported</div>
    <div style="font-size:12px;color:#6b7280;margin-bottom:20px;">Now match against your CRM to find unconverted leads.</div>
    <form method="POST" action="?page=dashboard&tab=wa_leads">
        <?= csrfField() ?>
        <input type="hidden" name="wl_action" value="crm_match">
        <button type="submit" style="background:#1d4ed8;color:#fff;border:none;border-radius:10px;padding:10px 28px;font-size:13px;font-weight:700;cursor:pointer;" onclick="this.disabled=true;this.innerHTML='⏳ Matching…';this.form.submit();">🔍 Match Against CRM</button>
    </form>
</div>

<?php else: ?>

<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:16px;">
    <div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:12px;text-align:center;"><div style="font-size:10px;font-weight:700;color:#6b7280;">CONTACTS</div><div style="font-size:20px;font-weight:900;color:#1e293b;"><?= number_format($leadStats['total_contacts'] ?? 0) ?></div></div>
    <div style="background:#dcfce7;border-radius:12px;padding:12px;text-align:center;"><div style="font-size:10px;font-weight:700;color:#15803d;">CUSTOMERS</div><div style="font-size:20px;font-weight:900;color:#15803d;"><?= number_format($leadStats['total_customers'] ?? 0) ?></div></div>
    <div style="background:#fee2e2;border-radius:12px;padding:12px;text-align:center;"><div style="font-size:10px;font-weight:700;color:#991b1b;">UNCONVERTED</div><div style="font-size:20px;font-weight:900;color:#991b1b;"><?= number_format($leadStats['total_leads'] ?? 0) ?></div></div>
    <div style="background:#dbeafe;border-radius:12px;padding:12px;text-align:center;"><div style="font-size:10px;font-weight:700;color:#1d4ed8;">FOLLOWED UP</div><div style="font-size:20px;font-weight:900;color:#1d4ed8;"><?= number_format($leadStats['followed_up'] ?? 0) ?></div></div>
    <div style="background:#d1fae5;border-radius:12px;padding:12px;text-align:center;"><div style="font-size:10px;font-weight:700;color:#065f46;">CONVERTED</div><div style="font-size:20px;font-weight:900;color:#065f46;"><?= number_format($leadStats['converted'] ?? 0) ?></div></div>
    <div style="background:#fef3c7;border-radius:12px;padding:12px;text-align:center;"><div style="font-size:10px;font-weight:700;color:#92400e;">FRESH</div><div style="font-size:20px;font-weight:900;color:#92400e;"><?= number_format($leadStats['new'] ?? 0) ?></div></div>
</div>

<?php if (!empty($leadStats['by_country'])): ?>
<div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;">
    <?php foreach ($leadStats['by_country'] as $cc): ?>
    <a href="?page=dashboard&tab=wa_leads&country=<?= h($cc['country']) ?>" style="background:<?= $leadCountry===$cc['country']?'#1e293b':'#f8fafc' ?>;color:<?= $leadCountry===$cc['country']?'#fff':'#374151' ?>;border:1px solid #e2e8f0;border-radius:8px;padding:4px 10px;font-size:11px;font-weight:700;text-decoration:none;"><?= $countryFlags[$cc['country']] ?? '🌍' ?> <?= h($cc['country']) ?> <span style="opacity:.6"><?= $cc['cnt'] ?></span></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div style="display:flex;gap:6px;margin-bottom:14px;align-items:center;flex-wrap:wrap;">
    <a href="?page=dashboard&tab=wa_leads" style="background:<?= !$leadFilter?'#1e293b':'#f1f5f9' ?>;color:<?= !$leadFilter?'#fff':'#374151' ?>;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:700;text-decoration:none;">All</a>
    <a href="?page=dashboard&tab=wa_leads&lf=new" style="background:<?= $leadFilter==='new'?'#ef4444':'#f1f5f9' ?>;color:<?= $leadFilter==='new'?'#fff':'#374151' ?>;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:700;text-decoration:none;">🆕 New</a>
    <a href="?page=dashboard&tab=wa_leads&lf=followed_up" style="background:<?= $leadFilter==='followed_up'?'#1d4ed8':'#f1f5f9' ?>;color:<?= $leadFilter==='followed_up'?'#fff':'#374151' ?>;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:700;text-decoration:none;">📞 Called</a>
    <a href="?page=dashboard&tab=wa_leads&lf=interested" style="background:<?= $leadFilter==='interested'?'#15803d':'#f1f5f9' ?>;color:<?= $leadFilter==='interested'?'#fff':'#374151' ?>;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:700;text-decoration:none;">🔥 Hot</a>
    <a href="?page=dashboard&tab=wa_leads&lf=converted" style="background:<?= $leadFilter==='converted'?'#065f46':'#f1f5f9' ?>;color:<?= $leadFilter==='converted'?'#fff':'#374151' ?>;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:700;text-decoration:none;">✅ Won</a>
    <a href="?page=dashboard&tab=wa_leads&lf=not_interested" style="background:<?= $leadFilter==='not_interested'?'#6b7280':'#f1f5f9' ?>;color:<?= $leadFilter==='not_interested'?'#fff':'#374151' ?>;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:700;text-decoration:none;">❌ Dead</a>
    <div style="margin-left:auto;display:flex;gap:4px;">
        <form method="POST" action="?page=dashboard&tab=wa_leads" style="margin:0;"><input type="hidden" name="wl_action" value="crm_match"><?= csrfField() ?><button type="submit" style="background:#f1f5f9;color:#374151;border:1px solid #e2e8f0;border-radius:8px;padding:4px 10px;font-size:10px;font-weight:700;cursor:pointer;">🔄 CRM</button></form>
        <form method="POST" action="?page=dashboard&tab=wa_leads" enctype="multipart/form-data" style="margin:0;"><?= csrfField() ?><input type="hidden" name="wl_action" value="import"><label style="background:#f1f5f9;color:#374151;border:1px solid #e2e8f0;border-radius:8px;padding:4px 10px;font-size:10px;font-weight:700;cursor:pointer;">📥 Leads<input type="file" name="lead_file" accept=".json" style="display:none;" onchange="this.form.submit()"></label></form>
        <form method="POST" action="?page=dashboard&tab=wa_leads" enctype="multipart/form-data" style="margin:0;"><?= csrfField() ?><input type="hidden" name="wl_action" value="import_messages"><label style="background:#f5f3ff;color:#7C3AED;border:1px solid #ddd6fe;border-radius:8px;padding:4px 10px;font-size:10px;font-weight:700;cursor:pointer;">💬 Msgs<input type="file" name="msg_file" accept=".json" style="display:none;" onchange="this.form.submit()"></label></form>
        <form method="GET" style="margin:0;display:flex;gap:3px;"><input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="wa_leads"><input type="text" name="ls" value="<?= h($leadSearch) ?>" placeholder="Search..." style="padding:4px 8px;border:1px solid #e2e8f0;border-radius:8px;font-size:10px;width:120px;"><button type="submit" style="background:#1e293b;color:#fff;border:none;border-radius:8px;padding:4px 8px;font-size:10px;cursor:pointer;">🔍</button></form>
    </div>
</div>

<!-- Follow-up modal -->
<div id="fuM" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.4);z-index:9999;align-items:center;justify-content:center;">
    <form method="POST" action="?page=dashboard&tab=wa_leads" style="background:#fff;border-radius:16px;padding:24px;width:440px;max-width:90vw;">
        <?= csrfField() ?>
        <input type="hidden" name="wl_action" value="send_followup">
        <input type="hidden" name="lead_id" id="fu_id">
        <input type="hidden" name="lead_phone" id="fu_ph">
        <div style="font-size:15px;font-weight:800;color:#1e293b;margin-bottom:12px;">📤 Send Follow-up</div>
        <div style="font-size:12px;color:#6b7280;margin-bottom:8px;">To: <strong id="fu_nm"></strong></div>
        <textarea name="followup_msg" id="fu_tx" rows="6" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;font-family:inherit;resize:vertical;box-sizing:border-box;"></textarea>
        <div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('fuM').style.display='none'" style="background:#f1f5f9;color:#374151;border:none;border-radius:8px;padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;">Cancel</button>
            <button type="submit" style="background:#15803d;color:#fff;border:none;border-radius:8px;padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;">📤 Send</button>
        </div>
    </form>
</div>
<script>
var _t={STARLINK:'Hello {{n}}! Starlink Mini now available from DishNet Africa.\n\n🛰️ Kit: $299 | Monthly: $80\n\nReply YES to order.',FIBER:'Hello {{n}}! Fiber internet now in more Juba areas.\n\n📡 Install: $150 | Monthly: $50\n\nReply YES to check your area.',PRICE:'Hello {{n}}! DishNet packages:\n\n🛰️ Starlink: $299+$80/mo\n📡 Fiber: $150+$50/mo\n\nReply STARLINK or FIBER.',DEFAULT:'Hello {{n}}! DishNet Africa has new internet offers.\n\nReply YES to learn more.'};
function fu(id,ph,nm,tg){document.getElementById('fu_id').value=id;document.getElementById('fu_ph').value=ph;document.getElementById('fu_nm').textContent=nm||ph;var k='DEFAULT';if(tg&&tg.indexOf('STARLINK')>=0)k='STARLINK';else if(tg&&tg.indexOf('FIBER')>=0)k='FIBER';else if(tg&&tg.indexOf('PRICE')>=0)k='PRICE';document.getElementById('fu_tx').value=_t[k].replace(/\{\{n\}\}/g,nm||'there');document.getElementById('fuM').style.display='flex';}
</script>

<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;">
<table style="width:100%;border-collapse:collapse;font-size:12px;">
<thead><tr style="background:#f8fafc;">
    <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;">PHONE</th>
    <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;">NAME</th>
    <th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;color:#6b7280;">🌍</th>
    <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;">STATUS</th>
    <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;">INTERESTS</th>
    <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;">LAST CONTACT</th>
    <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;">LAST MESSAGE</th>
    <th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;color:#6b7280;">ACTIONS</th>
</tr></thead>
<tbody>
<?php foreach ($leads as $i => $ld):
    $notes = $ld['notes'] ?? '';
    $interests = '';
    if (preg_match('/Interest: ([A-Z, ]+)/', $notes, $im)) $interests = $im[1];
    $msgCount = 0;
    if (preg_match('/^(\d+) msgs/', $notes, $mm)) $msgCount = (int)$mm[1];
    $sc = $statusColors[$ld['status']] ?? ['#f1f5f9','#374151'];
?>
<tr style="border-bottom:1px solid #f1f5f9;background:<?= $i%2?'#fafafa':'#fff' ?>;">
    <td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#1e293b;font-size:11px;"><?= h($ld['phone']) ?></td>
    <td style="padding:7px 10px;font-weight:600;color:#1e293b;"><?= h($ld['display_name'] ?? '') ?></td>
    <td style="padding:7px 10px;text-align:center;"><?= $countryFlags[$ld['country']] ?? '🌍' ?></td>
    <td style="padding:7px 10px;"><span style="background:<?= $sc[0] ?>;color:<?= $sc[1] ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:800;"><?= h(str_replace('_',' ',ucfirst($ld['status']))) ?></span></td>
    <td style="padding:7px 10px;">
        <?php foreach (array_filter(array_map('trim', explode(',', $interests))) as $tag):
            $tc = $tagColors[$tag] ?? ['#f1f5f9','#374151']; ?>
            <span style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;padding:1px 6px;border-radius:6px;font-size:9px;font-weight:800;"><?= h($tag) ?></span>
        <?php endforeach; ?>
        <?php if ($msgCount): ?><span style="color:#9ca3af;font-size:9px;"><?= $msgCount ?>msg</span><?php endif; ?>
    </td>
    <td style="padding:7px 10px;color:#9ca3af;font-size:11px;"><?= h(substr($ld['last_message_at'] ?? '', 0, 10)) ?></td>
    <td style="padding:7px 10px;color:#64748b;font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($ld['last_message'] ?? '') ?>"><?= h(mb_substr($ld['last_message'] ?? '—', 0, 40)) ?></td>
    <td style="padding:7px 10px;text-align:center;">
        <div style="display:flex;gap:3px;justify-content:center;">
            <button onclick="fu(<?= $ld['id'] ?>,'<?= h($ld['phone']) ?>','<?= h($ld['display_name'] ?? '') ?>','<?= h($interests) ?>')" style="background:#15803d;color:#fff;border:none;border-radius:6px;padding:3px 8px;font-size:10px;font-weight:700;cursor:pointer;">📤</button>
            <form method="POST" action="?page=dashboard&tab=wa_leads" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="wl_action" value="update_status">
                <input type="hidden" name="lead_id" value="<?= $ld['id'] ?>">
                <select name="lead_status" onchange="this.form.submit()" style="border:1px solid #e2e8f0;border-radius:6px;padding:2px;font-size:9px;width:65px;">
                    <option value="">set ▾</option>
                    <option value="interested" <?= $ld['status']==='interested'?'selected':'' ?>>🔥 Hot</option>
                    <option value="followed_up" <?= $ld['status']==='followed_up'?'selected':'' ?>>📞 Called</option>
                    <option value="not_interested" <?= $ld['status']==='not_interested'?'selected':'' ?>>❌ Dead</option>
                    <option value="converted" <?= $ld['status']==='converted'?'selected':'' ?>>✅ Won</option>
                </select>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($leads)): ?>
<tr><td colspan="8" style="padding:30px;text-align:center;color:#9ca3af;">No leads match filters.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:4px;margin-top:14px;align-items:center;">
    <?php if ($page > 1): ?>
    <a href="?page=dashboard&tab=wa_leads&p=<?= $page-1 ?>&lf=<?= h($leadFilter) ?>&country=<?= h($leadCountry) ?>&ls=<?= h($leadSearch) ?>" style="background:#f1f5f9;color:#374151;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:700;text-decoration:none;">← Prev</a>
    <?php endif; ?>
    <span style="font-size:11px;color:#6b7280;">Page <?= $page ?> of <?= $totalPages ?> (<?= number_format($totalLeads2) ?> leads)</span>
    <?php if ($page < $totalPages): ?>
    <a href="?page=dashboard&tab=wa_leads&p=<?= $page+1 ?>&lf=<?= h($leadFilter) ?>&country=<?= h($leadCountry) ?>&ls=<?= h($leadSearch) ?>" style="background:#f1f5f9;color:#374151;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:700;text-decoration:none;">Next →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>
