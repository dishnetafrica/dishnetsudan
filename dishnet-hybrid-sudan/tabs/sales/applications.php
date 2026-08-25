
<?php
// 'new' = successfully created in CRM (active customer)
// 'pending_sync' / 'updated' = queued for sync
// 'synced' = explicitly confirmed synced
// 'failed' / 'crm_failed' = error
$activeC = count(array_filter($myApps, fn($a) => in_array($a['status']??'',['new','updated','converted'])));
$pendC   = count(array_filter($myApps, fn($a) => in_array($a['status']??'',['pending_sync','pending'])));
$syncC   = count(array_filter($myApps, fn($a) => ($a['status']??'')==='synced'));
$failC   = count(array_filter($myApps, fn($a) => in_array($a['status']??'',['failed','crm_failed','exhausted'])));
$totalC  = count($myApps);
?>

<style>
.app-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px;}
.app-stat{background:#fff;border-radius:12px;padding:10px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.app-stat-num{font-size:20px;font-weight:800;line-height:1;}
.app-stat-label{font-size:9px;font-weight:700;color:#6b7280;margin-top:3px;text-transform:uppercase;}
.app-card{background:#fff;border-radius:14px;padding:14px 16px;margin-bottom:8px;box-shadow:0 1px 6px rgba(0,0,0,.04);border:1px solid #f1f5f9;}
.app-card-top{display:flex;justify-content:space-between;align-items:start;}
.app-card-name{font-size:15px;font-weight:800;color:#1e293b;}
.app-card-type{font-size:10px;font-weight:700;padding:2px 8px;border-radius:8px;white-space:nowrap;}
.app-card-meta{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:11px;color:#6b7280;margin-top:6px;}
.app-card-meta i{margin-right:2px;color:#9ca3af;}
.app-card-bottom{display:flex;justify-content:space-between;align-items:center;margin-top:10px;padding-top:10px;border-top:1px solid #f8fafc;}
.app-empty{text-align:center;padding:40px 20px;color:#9ca3af;}
.app-empty i{font-size:48px;display:block;margin-bottom:10px;color:#d1d5db;}
@media(max-width:600px){.app-stats{grid-template-columns:repeat(2,1fr);}}
</style>

<!-- Stats -->
<div class="app-stats">
    <div class="app-stat" style="border-bottom:3px solid #D41C1C;"><div class="app-stat-num" style="color:#D41C1C;"><?= $totalC ?></div><div class="app-stat-label">Total</div></div>
    <div class="app-stat" style="border-bottom:3px solid #28a745;"><div class="app-stat-num" style="color:#28a745;"><?= $activeC ?></div><div class="app-stat-label">In CRM ✓</div></div>
    <div class="app-stat" style="border-bottom:3px solid #f39c12;"><div class="app-stat-num" style="color:#f39c12;"><?= $pendC ?></div><div class="app-stat-label">Pending</div></div>
    <div class="app-stat" style="border-bottom:3px solid #dc3545;"><div class="app-stat-num" style="color:#dc3545;"><?= $failC ?></div><div class="app-stat-label">Failed</div></div>
</div>

<!-- Orders (KYC Registrations) -->
<?php if (empty($myApps)): ?>
<div class="app-empty">
    <div style="font-size:56px;margin-bottom:8px;">📋</div>
    <div style="font-size:16px;font-weight:800;color:#1e293b;">No orders yet</div>
    <div style="font-size:13px;color:#64748b;margin-top:6px;max-width:220px;text-align:center;line-height:1.5;">Register your first customer by tapping the button below</div>
    <a href="?page=dashboard&tab=form" style="display:inline-flex;align-items:center;gap:8px;margin-top:18px;background:#D41C1C;color:#fff;padding:13px 28px;border-radius:14px;font-weight:800;text-decoration:none;font-size:15px;box-shadow:0 4px 14px rgba(212,28,28,.3);">
      <i class="bi bi-person-plus-fill"></i> New KYC Registration
    </a>
</div>
<?php else: ?>
<div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:10px;"><?= count($myApps) ?> Orders</div>
<?php foreach ($myApps as $a):
    $statusColors = [
        'new'=>['#E3F2FD','#1565C0','New'],
        'pending_sync'=>['#FFF3E0','#E65100','Pending'],
        'synced'=>['#E8F5E9','#2E7D32','Synced'],
        'updated'=>['#FFF8E1','#F57F17','Updated'],
        'failed'=>['#FFEBEE','#C62828','Failed'],
        'crm_failed'=>['#FFEBEE','#C62828','CRM Failed'],
    ];
    $sc = $statusColors[$a['status']??'new'] ?? $statusColors['new'];
    $typeColors = ['StarLink'=>'#0D47A1','Fiber'=>'#2E7D32'];
    $stype = $a['customer_type'] ?? 'StarLink';
    $tc = $typeColors[$stype] ?? '#6b7280';
?>
<div class="app-card">
    <div class="app-card-top">
        <div>
            <div class="app-card-name"><?= h(($a['firstname']??'').' '.($a['lastname']??'')) ?></div>
        </div>
        <span class="app-card-type" style="background:<?= $sc[0] ?>;color:<?= $sc[1] ?>;"><?= $sc[2] ?></span>
    </div>
    <div class="app-card-meta">
        <span style="color:<?= $tc ?>;font-weight:700;"><?= h($stype) ?></span>
        <span><i class="bi bi-telephone"></i> <?= h($a['mobile']??'-') ?></span>
        <?php if (!empty($a['amount_charged']) && $a['amount_charged'] > 0): ?>
        <span><i class="bi bi-cash"></i> $<?= number_format($a['amount_charged'],2) ?></span>
        <?php endif; ?>
        <?php if (!empty($a['crm_client_id'])): ?>
        <span><i class="bi bi-cloud-check"></i> CRM: <?= h($a['crm_client_id']) ?></span>
        <?php endif; ?>
    </div>
    <?php
    // Document upload status badges
    $hasPhotoFlag = array_key_exists('photo_uploaded', $a);
    $hasIdFlag    = array_key_exists('id_uploaded', $a);
    $photoFailed  = ($hasPhotoFlag && $a['photo_uploaded'] === false);
    $idFailed     = ($hasIdFlag    && $a['id_uploaded']    === false);
    $hasKitFlag   = array_key_exists('kit_image_uploaded', $a);
    $kitFailed    = ($hasKitFlag && $a['kit_image_uploaded'] === false);
    $anyDocFailed = $photoFailed || $idFailed || $kitFailed;
    // Local storage paths
    $photoPath = $a['photo_path'] ?? null;  // set by kyc_save_photo (old: kyc_photos/, new: kyc_uploads/)
    $idPath    = $a['id_path']    ?? null;
    // Also check kyc_uploads directory by crm_client_id (new KycService)
    $crmId4Photo = $a['crm_client_id'] ?? '';
    $appId4Photo = $a['id'] ?? '';
    if (!$photoPath && $crmId4Photo) {
        $month = date('Y-m');
        foreach ([$month, date('Y-m', strtotime('-1 month')), date('Y-m', strtotime('-2 months'))] as $_m) {
            foreach (['jpg','png','webp','heic','jpeg'] as $_e) {
                $_p = $dataDir . '/kyc_uploads/'.$_m.'/crm-'.$crmId4Photo.'/photo.'.$_e;
                if (file_exists($_p)) { $photoPath = 'kyc_uploads/'.$_m.'/crm-'.$crmId4Photo.'/photo.'.$_e; break 2; }
            }
        }
    }
    // Check old kyc_photos/ style (app{id}_customer_photo_*.jpg)
    if (!$photoPath && $appId4Photo) {
        $oldDir = $dataDir . '/kyc_photos/';
        if (is_dir($oldDir)) {
            foreach (glob($oldDir . 'app' . $appId4Photo . '_customer_photo_*') ?: [] as $_f) {
                $photoPath = 'kyc_photos/' . basename($_f); break;
            }
        }
    }
    if (!$idPath && $crmId4Photo) {
        $month = date('Y-m');
        foreach ([$month, date('Y-m', strtotime('-1 month')), date('Y-m', strtotime('-2 months'))] as $_m) {
            foreach (['jpg','png','pdf','webp','jpeg'] as $_e) {
                $_p = $dataDir . '/kyc_uploads/'.$_m.'/crm-'.$crmId4Photo.'/id_proof.'.$_e;
                if (file_exists($_p)) { $idPath = 'kyc_uploads/'.$_m.'/crm-'.$crmId4Photo.'/id_proof.'.$_e; break 2; }
            }
        }
    }
    if (!$idPath && $appId4Photo) {
        $oldDir = $dataDir . '/kyc_photos/';
        if (is_dir($oldDir)) {
            foreach (glob($oldDir . 'app' . $appId4Photo . '_id_*') ?: [] as $_f) {
                $idPath = 'kyc_photos/' . basename($_f); break;
            }
        }
    }
    if ($hasPhotoFlag || $hasIdFlag || $hasKitFlag || $photoPath || $idPath): ?>
    <div style="display:flex;gap:6px;margin-top:7px;flex-wrap:wrap;align-items:center;">
        <span style="font-size:10px;font-weight:700;color:#6b7280;">DOCS:</span>
        <?php if ($photoPath): ?>
        <a href="javascript:void(0)" onclick="dnLbOpen('?page=kyc_photo&f=<?= urlencode($photoPath) ?>')" style="cursor:pointer;"
           style="display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;text-decoration:none;">
            📷✓ Photo
        </a>
        <?php elseif ($hasPhotoFlag): ?>
        <span style="background:<?= $a['photo_uploaded']?'#dcfce7':'#fee2e2' ?>;color:<?= $a['photo_uploaded']?'#166534':'#991b1b' ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;">
            <?= $a['photo_uploaded'] ? '📷✓' : '📷✗' ?> Photo
        </span>
        <?php endif; ?>
        <?php if ($idPath): ?>
        <a href="javascript:void(0)" onclick="dnLbOpen('?page=kyc_photo&f=<?= urlencode($idPath) ?>')"
           style="display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;text-decoration:none;cursor:pointer;">
            🪪✓ ID
        </a>
        <?php elseif ($hasIdFlag): ?>
        <span style="background:<?= $a['id_uploaded']?'#dcfce7':'#fee2e2' ?>;color:<?= $a['id_uploaded']?'#166534':'#991b1b' ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;">
            <?= $a['id_uploaded'] ? '🪪✓' : '🪪✗' ?> ID
        </span>
        <?php endif; ?>
        <?php
        // Kit label photo
        $kitPath = $a['kit_image_path'] ?? null;
        if ($kitPath && !file_exists($dataDir . '/' . $kitPath)) $kitPath = null;
        if (!$kitPath && $crmId4Photo) {
            $month = date('Y-m');
            foreach ([$month, date('Y-m', strtotime('-1 month')), date('Y-m', strtotime('-2 months'))] as $_m) {
                foreach (['jpg','png','webp','jpeg'] as $_e) {
                    $_kp = $dataDir . '/kyc_uploads/'.$_m.'/crm-'.$crmId4Photo.'/kit_label.'.$_e;
                    if (file_exists($_kp)) { $kitPath = 'kyc_uploads/'.$_m.'/crm-'.$crmId4Photo.'/kit_label.'.$_e; break 2; }
                }
            }
        }
        if (!$kitPath && $appId4Photo) {
            $oldDir = $dataDir . '/kyc_photos/';
            if (is_dir($oldDir)) {
                foreach (glob($oldDir . 'app' . $appId4Photo . '_kit_label_*') ?: [] as $_f) {
                    $kitPath = 'kyc_photos/' . basename($_f); break;
                }
            }
        }
        if ($kitPath): ?>
        <a href="javascript:void(0)" onclick="dnLbOpen('?page=kyc_photo&f=<?= urlencode($kitPath) ?>')"
           style="display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;text-decoration:none;cursor:pointer;">
            📦✓ Kit
        </a>
        <?php endif; ?>
        <?php if (!$kitPath && $kitFailed): ?>
        <span style="display:inline-flex;align-items:center;gap:4px;background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;">
            📦✗ Kit missing
        </span>
        <?php elseif (!$kitPath && $hasKitFlag && ($a['kit_image_uploaded'] ?? false)): ?>
        <span style="display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;">
            📦✓ Kit saved
        </span>
        <?php endif; ?>
        <?php if ($anyDocFailed && !empty($a['crm_client_id'])): ?>
        <button onclick="document.getElementById('reupload-<?= $a['id'] ?>').style.display='block';this.style.display='none';"
            style="background:#E65100;color:#fff;border:none;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;min-height:32px;">
            📤 Re-upload docs
        </button>
        <?php endif; ?>
    </div>
    <?php if ($anyDocFailed && !empty($a['crm_client_id'])): ?>
    <div id="reupload-<?= $a['id'] ?>" style="display:none;background:#fff3cd;border:1.5px solid #fbbf24;border-radius:14px;padding:14px 16px;margin-top:10px;">
        <!-- fetch/FormData upload — avoids PWA/Android WebView multipart form bug -->
        <div style="font-size:12px;font-weight:800;color:#92400e;margin-bottom:12px;">📎 Upload missing docs for CRM <?= h($a['crm_client_id']) ?></div>
        <?php if ($photoFailed): ?>
        <div style="margin-bottom:10px;">
            <label for="reup_photo_<?= $a['id'] ?>" style="display:flex;align-items:center;gap:10px;background:#fff;border:2px dashed #fbbf24;border-radius:12px;padding:12px 14px;cursor:pointer;">
                <span style="font-size:28px;">📷</span>
                <div>
                    <div style="font-size:13px;font-weight:700;color:#374151;">Customer Photo</div>
                    <div id="reup_photo_lbl_<?= $a['id'] ?>" style="font-size:11px;color:#6b7280;">Tap to choose or take photo</div>
                </div>
            </label>
            <input type="file" id="reup_photo_<?= $a['id'] ?>" accept="image/*" capture="environment" style="display:none;"
                onchange="document.getElementById('reup_photo_lbl_<?= $a['id'] ?>').textContent=this.files[0]?'✓ '+this.files[0].name:'Tap to choose or take photo'">
        </div>
        <?php endif; ?>
        <?php if ($idFailed): ?>
        <div style="margin-bottom:12px;">
            <label for="reup_id_<?= $a['id'] ?>" style="display:flex;align-items:center;gap:10px;background:#fff;border:2px dashed #fbbf24;border-radius:12px;padding:12px 14px;cursor:pointer;">
                <span style="font-size:28px;">🪪</span>
                <div>
                    <div style="font-size:13px;font-weight:700;color:#374151;">ID Proof</div>
                    <div id="reup_id_lbl_<?= $a['id'] ?>" style="font-size:11px;color:#6b7280;">Tap to choose or photograph ID</div>
                </div>
            </label>
            <input type="file" id="reup_id_<?= $a['id'] ?>" accept="image/*,.pdf" style="display:none;"
                onchange="document.getElementById('reup_id_lbl_<?= $a['id'] ?>').textContent=this.files[0]?'✓ '+this.files[0].name:'Tap to choose or photograph ID'">
        </div>
        <?php endif; ?>
        <?php if ($kitFailed): ?>
        <div style="margin-bottom:12px;">
            <label for="reup_kit_<?= $a['id'] ?>" style="display:flex;align-items:center;gap:10px;background:#fff;border:2px dashed #60a5fa;border-radius:12px;padding:12px 14px;cursor:pointer;">
                <span style="font-size:28px;">📦</span>
                <div>
                    <div style="font-size:13px;font-weight:700;color:#374151;">Starlink Kit Label</div>
                    <div id="reup_kit_lbl_<?= $a['id'] ?>" style="font-size:11px;color:#6b7280;">Tap to photograph kit sticker</div>
                </div>
            </label>
            <input type="file" id="reup_kit_<?= $a['id'] ?>" accept="image/*" capture="environment" style="display:none;"
                onchange="document.getElementById('reup_kit_lbl_<?= $a['id'] ?>').textContent=this.files[0]?'✓ '+this.files[0].name:'Tap to photograph kit sticker'">
        </div>
        <?php endif; ?>
        <div id="reup_status_<?= $a['id'] ?>" style="min-height:18px;font-size:12px;font-weight:700;margin-bottom:8px;text-align:center;display:none;"></div>
        <button type="button" id="reup_btn_<?= $a['id'] ?>"
            onclick="reupDocsUpload(<?= $a['id'] ?>, '<?= h($a['crm_client_id']) ?>', <?= $photoFailed?'true':'false' ?>, <?= $idFailed?'true':'false' ?>, <?= $kitFailed?'true':'false' ?>, '<?= csrfToken() ?>')"
            style="width:100%;background:#15803d;color:#fff;border:none;border-radius:12px;padding:14px;font-size:14px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
            💾 Save Photos
        </button>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php
    // ── Payment posted to CRM badge ─────────────────────────────────────
    $isCashApp      = strtolower($a['sales_type'] ?? $a['payment_type'] ?? 'cash') !== 'credit';
    $amtCharged     = (float)($a['amount_charged'] ?? 0);
    $paymentPosted  = !empty($a['payment_created']) || !empty($a['payment_id']) || !empty($a['crm_payment_id']);
    if ($amtCharged > 0): ?>
    <div style="display:flex;gap:6px;margin-top:5px;flex-wrap:wrap;align-items:center;">
        <span style="font-size:10px;font-weight:700;color:#6b7280;">PAYMENT:</span>
        <?php if (!$isCashApp): ?>
        <span style="background:#f1f5f9;color:#475569;padding:2px 10px;border-radius:8px;font-size:10px;font-weight:700;">
            📋 Credit — invoice on CRM
        </span>
        <?php elseif ($paymentPosted): ?>
        <span style="background:#dcfce7;color:#166534;padding:2px 10px;border-radius:8px;font-size:10px;font-weight:700;">
            💳✓ Posted to CRM<?php $pid = $a['payment_id'] ?? $a['crm_payment_id'] ?? ''; if ($pid): ?> #<?= h($pid) ?><?php endif; ?>
        </span>
        <?php else: ?>
        <span style="background:#fee2e2;color:#991b1b;padding:2px 10px;border-radius:8px;font-size:10px;font-weight:700;">
            💳✗ Not in CRM — add manually
        </span>
        <?php if (!empty($a['crm_client_id'])): ?>
        <a href="https://<?= $_SERVER['HTTP_HOST'] ?>/crm/billing/invoices/new?clientId=<?= h($a['crm_client_id']) ?>"
           target="_blank"
           style="background:#1565C0;color:#fff;padding:2px 10px;border-radius:8px;font-size:10px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:3px;">
            ➕ Add in UCRM
        </a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="app-card-bottom">
        <span style="font-size:11px;color:#9ca3af;"><i class="bi bi-calendar3"></i> <?= h(substr($a['submitted_at']??$a['created_at']??'',0,10)) ?></span>
        <span style="font-size:10px;color:#9ca3af;"><?= h($a['connectivity_type']??'') ?> · <?= h($a['sales_type']??'') ?></span>
    </div>
    <?php if ($isAdmin): ?>
    <div style="display:flex;gap:6px;margin-top:8px;padding-top:8px;border-top:1px solid #f1f5f9;flex-wrap:wrap;">
        <button onclick="openKycEdit(<?= $a['id'] ?>)" 
            style="flex:1;background:#f1f5f9;color:#374151;border:none;border-radius:8px;padding:7px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;">
            ✏️ Edit
        </button>
        <?php if (!empty($a['crm_client_id'])): ?>
        <button onclick="sendQuotePdf(<?= $a['id'] ?>, this)"
            style="flex:1;background:#dcfce7;color:#166534;border:none;border-radius:8px;padding:7px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;">
            📄 Send Quote
        </button>
        <?php endif; ?>
        <?php if (!empty($a['audit_log'])): ?>
        <button onclick="openKycAudit(<?= $a['id'] ?>, <?= htmlspecialchars(json_encode($a['audit_log']), ENT_QUOTES) ?>)"
            style="flex:1;background:#eff6ff;color:#1d4ed8;border:none;border-radius:8px;padding:7px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;">
            📋 Audit (<?= count($a['audit_log']) ?>)
        </button>
        <?php endif; ?>
        <?php if (($a['status']??'') !== 'cancelled'): ?>
        <button onclick="openKycDelete(<?= $a['id'] ?>, '<?= h(addslashes(($a['firstname']??'').' '.($a['lastname']??''))) ?>')"
            style="background:#fee2e2;color:#991b1b;border:none;border-radius:8px;padding:7px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;">
            🗑
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div style="height:80px;"></div>

<?php if ($isAdmin): ?>
<!-- ══ KYC EDIT MODAL ══════════════════════════════════════════════════════ -->
<div id="kycEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;overflow-y:auto;" onclick="if(event.target===this)closeKycEdit()">
  <div style="background:#fff;border-radius:20px;padding:24px;width:100%;max-width:500px;margin:16px auto;box-shadow:0 20px 60px rgba(0,0,0,.25);max-height:90vh;overflow-y:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
      <div style="font-size:16px;font-weight:800;color:#111827;">✏️ Edit Application <span id="kycEditTitle" style="color:#6b7280;font-size:13px;"></span></div>
      <button onclick="closeKycEdit()" style="background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;">✕</button>
    </div>
    <form method="POST" id="kycEditForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="kyc_admin_edit">
      <input type="hidden" name="app_id" id="kycEditAppId">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
        <div>
          <label style="font-size:10px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">First Name</label>
          <input type="text" name="firstname" id="kef_firstname" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Last Name</label>
          <input type="text" name="lastname" id="kef_lastname" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Mobile</label>
          <input type="text" name="mobile" id="kef_mobile" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Email</label>
          <input type="text" name="email" id="kef_email" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;">
        </div>
      </div>
      <div style="margin-bottom:10px;">
        <label style="font-size:10px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Address</label>
        <input type="text" name="address_1" id="kef_address_1" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:10px;">
        <label style="font-size:10px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Company Name</label>
        <input type="text" name="company_name" id="kef_company_name" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
        <div>
          <label style="font-size:10px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Status</label>
          <select name="status" id="kef_status" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;">
            <option value="new">New</option>
            <option value="converted">Converted</option>
            <option value="cancelled">Cancelled</option>
            <option value="pending_sync">Pending Sync</option>
          </select>
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Sales Type</label>
          <select name="sales_type" id="kef_sales_type" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;">
            <option value="Cash">Cash</option>
            <option value="Credit">Credit</option>
          </select>
        </div>
      </div>
      <div style="margin-bottom:16px;">
        <label style="font-size:10px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Internal Note</label>
        <textarea name="note" id="kef_note" rows="2" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;resize:vertical;"></textarea>
      </div>
      <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:8px 12px;font-size:11px;color:#92400e;margin-bottom:14px;">
        ⚠ Name changes will also update the client record in UCRM automatically.
      </div>
      <div style="display:flex;gap:8px;">
        <button type="submit" style="flex:1;background:#D41C1C;color:#fff;border:none;border-radius:10px;padding:11px;font-size:14px;font-weight:800;cursor:pointer;font-family:inherit;">
          💾 Save Changes
        </button>
        <button type="button" onclick="closeKycEdit()" style="background:#f1f5f9;color:#374151;border:none;border-radius:10px;padding:11px 18px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;">
          Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ KYC CANCEL / RETURN MODAL ═══════════════════════════════════════════ -->
<div id="kycDeleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;overflow-y:auto;" onclick="if(event.target===this)closeKycDelete()">
  <div style="background:#fff;border-radius:20px;padding:24px;width:100%;max-width:420px;margin:16px auto;max-height:90vh;overflow-y:auto;">
    <div style="font-size:16px;font-weight:800;color:#991b1b;margin-bottom:6px;">🗑 Cancel / Return</div>
    <div id="kycDeleteName" style="font-size:13px;color:#6b7280;margin-bottom:16px;"></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="kyc_admin_delete">
      <input type="hidden" name="app_id" id="kycDeleteAppId">

      <!-- Reason category -->
      <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:6px;">Reason</label>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;">
        <?php
        $_cancelReasons = [
            'changed_mind'   => ['😐', 'Changed mind'],
            'too_expensive'  => ['💰', 'Too expensive'],
            'moved_away'     => ['🚚', 'Moved away'],
            'equipment_issue'=> ['🔧', 'Equipment issue'],
            'duplicate'      => ['📋', 'Duplicate entry'],
            'wrong_details'  => ['❌', 'Wrong details'],
            'other'          => ['💬', 'Other'],
        ];
        foreach ($_cancelReasons as $_crk => $_crv): ?>
        <label style="display:flex;align-items:center;gap:5px;padding:8px 12px;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;font-size:12px;font-weight:600;color:#374151;">
          <input type="radio" name="cancel_reason_type" value="<?= $_crk ?>" required style="accent-color:#dc2626;"> <?= $_crv[0] ?> <?= $_crv[1] ?>
        </label>
        <?php endforeach; ?>
      </div>

      <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:6px;">Additional notes</label>
      <textarea name="delete_reason" rows="2" placeholder="Details about the cancellation..."
        style="width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;box-sizing:border-box;resize:vertical;margin-bottom:12px;"></textarea>

      <!-- Equipment status -->
      <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:6px;">Equipment / Hardware</label>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;">
        <label style="display:flex;align-items:center;gap:5px;padding:8px 12px;border-radius:8px;border:1.5px solid #dcfce7;cursor:pointer;font-size:12px;font-weight:600;color:#065f46;background:#f0fdf4;">
          <input type="radio" name="equipment_status" value="returned" checked style="accent-color:#059669;"> ✅ Returned
        </label>
        <label style="display:flex;align-items:center;gap:5px;padding:8px 12px;border-radius:8px;border:1.5px solid #fef3c7;cursor:pointer;font-size:12px;font-weight:600;color:#92400e;background:#fffbeb;">
          <input type="radio" name="equipment_status" value="pending_return" style="accent-color:#d97706;"> ⏳ Pending return
        </label>
        <label style="display:flex;align-items:center;gap:5px;padding:8px 12px;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;font-size:12px;font-weight:600;color:#374151;">
          <input type="radio" name="equipment_status" value="customer_keeps" style="accent-color:#6b7280;"> 📦 Customer keeps
        </label>
        <label style="display:flex;align-items:center;gap:5px;padding:8px 12px;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;font-size:12px;font-weight:600;color:#374151;">
          <input type="radio" name="equipment_status" value="not_applicable" style="accent-color:#6b7280;"> N/A
        </label>
      </div>

      <!-- Refund -->
      <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:6px;">Refund required?</label>
      <div style="display:flex;gap:6px;margin-bottom:12px;">
        <label style="display:flex;align-items:center;gap:5px;padding:8px 12px;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;font-size:12px;font-weight:600;color:#374151;">
          <input type="radio" name="refund_needed" value="no" checked onchange="document.getElementById('refundAmtRow').style.display='none'" style="accent-color:#6b7280;"> No refund
        </label>
        <label style="display:flex;align-items:center;gap:5px;padding:8px 12px;border-radius:8px;border:1.5px solid #fef2f2;cursor:pointer;font-size:12px;font-weight:600;color:#991b1b;background:#fef2f2;">
          <input type="radio" name="refund_needed" value="yes" onchange="document.getElementById('refundAmtRow').style.display=''" style="accent-color:#dc2626;"> 💸 Refund needed
        </label>
      </div>
      <div id="refundAmtRow" style="display:none;margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Refund amount (USD)</label>
        <input type="number" name="refund_amount" step="0.01" min="0" placeholder="0.00"
          style="width:100%;padding:10px;border:1.5px solid #fca5a5;border-radius:10px;font-size:16px;font-weight:700;text-align:center;box-sizing:border-box;">
      </div>

      <!-- CRM service action -->
      <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:6px;">CRM service action</label>
      <div style="display:flex;gap:6px;margin-bottom:14px;">
        <label style="display:flex;align-items:center;gap:5px;padding:8px 12px;border-radius:8px;border:1.5px solid #fef2f2;cursor:pointer;font-size:12px;font-weight:600;color:#991b1b;background:#fef2f2;">
          <input type="radio" name="crm_action" value="end_service" checked style="accent-color:#dc2626;"> End service
        </label>
        <label style="display:flex;align-items:center;gap:5px;padding:8px 12px;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;font-size:12px;font-weight:600;color:#374151;">
          <input type="radio" name="crm_action" value="suspend_only" style="accent-color:#d97706;"> Suspend only
        </label>
        <label style="display:flex;align-items:center;gap:5px;padding:8px 12px;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;font-size:12px;font-weight:600;color:#374151;">
          <input type="radio" name="crm_action" value="no_action" style="accent-color:#6b7280;"> No action
        </label>
      </div>

      <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:8px 12px;font-size:11px;color:#991b1b;margin-bottom:14px;">
        This will mark the application as cancelled. Equipment returns are tracked in Stock Hub. Audit trail is preserved.
      </div>
      <div style="display:flex;gap:8px;">
        <button type="submit" style="flex:1;background:#dc2626;color:#fff;border:none;border-radius:10px;padding:11px;font-size:14px;font-weight:800;cursor:pointer;font-family:inherit;">
          Confirm Cancellation
        </button>
        <button type="button" onclick="closeKycDelete()" style="background:#f1f5f9;color:#374151;border:none;border-radius:10px;padding:11px 18px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;">
          Back
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ KYC AUDIT TRAIL MODAL ══════════════════════════════════════════════ -->
<div id="kycAuditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;overflow-y:auto;" onclick="if(event.target===this)closeKycAudit()">
  <div style="background:#fff;border-radius:20px;padding:24px;width:100%;max-width:480px;margin:16px auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <div style="font-size:15px;font-weight:800;color:#111827;">📋 Audit Trail <span id="kycAuditTitle" style="color:#6b7280;font-size:12px;"></span></div>
      <button onclick="closeKycAudit()" style="background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;">✕</button>
    </div>
    <div id="kycAuditBody" style="max-height:60vh;overflow-y:auto;"></div>
  </div>
</div>

<script>
// ── KYC CRUD JS ─────────────────────────────────────────────────────────────
var _kycApps = <?= json_encode(array_values(array_map(fn($a) => [
    'id'           => $a['id'],
    'firstname'    => $a['firstname']    ?? '',
    'lastname'     => $a['lastname']     ?? '',
    'mobile'       => $a['mobile']       ?? '',
    'email'        => $a['email']        ?? '',
    'address_1'    => $a['address_1']    ?? '',
    'company_name' => $a['company_name'] ?? '',
    'note'         => $a['note']         ?? '',
    'status'       => $a['status']       ?? 'new',
    'sales_type'   => $a['sales_type']   ?? 'Cash',
], $myApps))) ?>;

function openKycEdit(id) {
    var a = _kycApps.find(function(x){ return x.id == id; });
    if (!a) return;
    document.getElementById('kycEditAppId').value = id;
    document.getElementById('kycEditTitle').textContent = '— ' + a.firstname + ' ' + a.lastname;
    ['firstname','lastname','mobile','email','address_1','company_name','note'].forEach(function(f){
        var el = document.getElementById('kef_'+f);
        if (el) el.value = a[f] || '';
    });
    document.getElementById('kef_status').value    = a.status    || 'new';
    document.getElementById('kef_sales_type').value = a.sales_type || 'Cash';
    document.body.style.overflow = 'hidden';
    document.getElementById('kycEditModal').style.display = 'flex';
}
function closeKycEdit() { document.getElementById('kycEditModal').style.display = 'none'; document.body.style.overflow = ''; }

function openKycDelete(id, name) {
    document.getElementById('kycDeleteAppId').value = id;
    document.getElementById('kycDeleteName').textContent = 'Application #' + id + ' — ' + name;
    document.body.style.overflow = 'hidden';
    document.getElementById('kycDeleteModal').style.display = 'flex';
}
function closeKycDelete() { document.getElementById('kycDeleteModal').style.display = 'none'; document.body.style.overflow = ''; }

function openKycAudit(id, log) {
    document.getElementById('kycAuditTitle').textContent = '— App #' + id;
    var html = '';
    log.slice().reverse().forEach(function(e) {
        var icon = e.action === 'edit' ? '✏️' : e.action === 'delete' ? '🗑' : '📌';
        var color = e.action === 'delete' ? '#fee2e2' : '#f0f9ff';
        var border = e.action === 'delete' ? '#fca5a5' : '#bae6fd';
        html += '<div style="background:'+color+';border:1px solid '+border+';border-radius:10px;padding:10px 12px;margin-bottom:8px;">';
        html += '<div style="display:flex;justify-content:space-between;margin-bottom:6px;">';
        html += '<span style="font-size:12px;font-weight:800;">'+icon+' '+e.action.toUpperCase()+'</span>';
        html += '<span style="font-size:10px;color:#6b7280;">'+e.ts+' · '+e.by+'</span>';
        html += '</div>';
        if (e.changes) {
            Object.keys(e.changes).forEach(function(f) {
                html += '<div style="font-size:11px;padding:2px 0;">';
                html += '<span style="font-weight:700;color:#374151;">'+f+':</span> ';
                html += '<span style="color:#991b1b;text-decoration:line-through;">'+e.changes[f].from+'</span> → ';
                html += '<span style="color:#166534;font-weight:700;">'+e.changes[f].to+'</span>';
                html += '</div>';
            });
        }
        if (e.reason) html += '<div style="font-size:11px;color:#92400e;margin-top:4px;">Reason: '+e.reason+'</div>';
        if (e.ip) html += '<div style="font-size:10px;color:#9ca3af;margin-top:3px;">IP: '+e.ip+'</div>';
        html += '</div>';
    });
    document.getElementById('kycAuditBody').innerHTML = html || '<div style="color:#9ca3af;font-size:13px;">No audit entries yet.</div>';
    document.body.style.overflow = 'hidden';
    document.getElementById('kycAuditModal').style.display = 'flex';
}
function closeKycAudit() { document.getElementById('kycAuditModal').style.display = 'none'; document.body.style.overflow = ''; }
</script>
<?php endif; ?>
<div id="callModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:flex-end;justify-content:center;" onclick="if(event.target===this)closeCallModal()">
  <div style="background:#fff;border-radius:20px 20px 0 0;padding:0;width:100%;max-width:480px;overflow:hidden;">
    <!-- Dial header -->
    <div style="background:linear-gradient(135deg,#16a34a,#15803d);padding:16px 20px;display:flex;align-items:center;gap:12px;">
      <div style="width:44px;height:44px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;">📞</div>
      <div>
        <div style="font-size:15px;font-weight:800;color:#fff;" id="callModalName">Calling…</div>
        <div style="font-size:12px;color:#bbf7d0;" id="callModalPhone"></div>
      </div>
      <a id="callModalDialBtn" href="#" data-phone="" onclick="dialNumber(this.dataset.phone); return false;" style="margin-left:auto;background:#fff;color:#16a34a;font-weight:900;font-size:15px;padding:12px 22px;border-radius:12px;text-decoration:none;white-space:nowrap;box-shadow:0 4px 12px rgba(0,0,0,.2);">📲 Call</a>
    </div>

    <div style="padding:16px 18px;">
      <div style="font-size:11px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">After Call — Log Outcome</div>

      <!-- Outcome chips -->
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:14px;" id="outcomeChips">
        <?php
        $outcomes = [
          'answered'       => ['✅', 'Answered',        '#dcfce7','#16a34a'],
          'interested'     => ['🔥', 'Interested',      '#fef3c7','#d97706'],
          'callback'       => ['🔄', 'Callback',        '#eff6ff','#1d4ed8'],
          'no_answer'      => ['📵', 'No Answer',       '#f3f4f6','#6b7280'],
          'busy'           => ['📶', 'Busy',            '#fef2f2','#dc2626'],
          'not_interested' => ['❌', 'Not Interested',  '#fef2f2','#dc2626'],
          'voicemail'      => ['📬', 'Voicemail',       '#f5f3ff','#6d28d9'],
        ];
        foreach ($outcomes as $key => [$icon, $label, $bg, $col]): ?>
        <button type="button" onclick="selectOutcome('<?= $key ?>')"
          data-outcome="<?= $key ?>"
          style="background:<?= $bg ?>;color:<?= $col ?>;border:2px solid transparent;border-radius:10px;padding:9px 4px;font-size:11px;font-weight:700;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;transition:border .15s;"
          class="outcome-chip">
          <span style="font-size:18px;"><?= $icon ?></span>
          <?= $label ?>
        </button>
        <?php endforeach; ?>
      </div>

      <input type="hidden" id="callModalLeadId" value="">
      <input type="hidden" id="callModalOutcome" value="no_answer">

      <div style="margin-bottom:10px;">
        <textarea id="callModalNote" placeholder="Call notes — what did they say? (optional)"
          style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:12px;font-family:inherit;resize:vertical;min-height:56px;box-sizing:border-box;"></textarea>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">
        <div>
          <label style="display:block;font-size:10px;font-weight:700;color:#6b7280;margin-bottom:3px;">Move status to</label>
          <select id="callModalStatus" style="width:100%;padding:7px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;box-sizing:border-box;">
            <option value="">Auto (based on outcome)</option>
            <option value="contacted">📞 Contacted</option>
            <option value="interested">💡 Interested</option>
            <option value="quoted">💰 Quoted</option>
            <option value="lost">❌ Lost</option>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:10px;font-weight:700;color:#6b7280;margin-bottom:3px;">Follow-up date</label>
          <input type="date" id="callModalFollowUp" style="width:100%;padding:7px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;box-sizing:border-box;">
        </div>
      </div>

      <button id="callModalSaveBtn" onclick="saveCallOutcome()"
        style="width:100%;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:800;cursor:pointer;margin-bottom:6px;">
        💾 Save Call Log
      </button>
      <button onclick="closeCallModal()" style="width:100%;background:#f1f5f9;color:#64748b;border:none;border-radius:12px;padding:10px;font-size:13px;font-weight:600;cursor:pointer;">
        Close
      </button>
    </div>
  </div>
</div>

<script>
var _callLeadId = 0, _callName = '', _callPhone = '';

// FIX: window.location.href is the only reliable way to open Android WebView dialer
// (tel: href on <a> tags is ignored by Android WebView's shouldOverrideUrlLoading)
function dialNumber(phone) {
  if (!phone) return;
  window.location.href = 'tel:' + phone.replace(/\s/g, '');
}

function openCallModal(leadId, name, phone) {
  _callLeadId = leadId; _callName = name; _callPhone = phone;
  document.getElementById('callModalLeadId').value = leadId;
  document.getElementById('callModalName').textContent  = name;
  document.getElementById('callModalPhone').textContent = phone;
  document.getElementById('callModalDialBtn').dataset.phone = phone.replace(/\s/g,'');
  document.getElementById('callModalNote').value     = '';
  document.getElementById('callModalStatus').value   = '';
  document.getElementById('callModalFollowUp').value = '';
  document.getElementById('callModalOutcome').value  = 'no_answer';
  // Reset chip selection
  document.querySelectorAll('.outcome-chip').forEach(function(c) {
    c.style.border = '2px solid transparent';
    c.style.transform = 'scale(1)';
  });
  document.getElementById('callModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
  // Note: tel: links must be tapped manually — browser security blocks auto-click
}

function selectOutcome(key) {
  document.getElementById('callModalOutcome').value = key;
  document.querySelectorAll('.outcome-chip').forEach(function(c) {
    var selected = c.dataset.outcome === key;
    c.style.border = selected ? '2px solid currentColor' : '2px solid transparent';
    c.style.transform = selected ? 'scale(1.05)' : 'scale(1)';
    c.style.fontWeight = selected ? '900' : '700';
  });
  // Auto-fill follow-up for callback
  if (key === 'callback') {
    var tom = new Date(); tom.setDate(tom.getDate()+1);
    document.getElementById('callModalFollowUp').value = tom.toISOString().slice(0,10);
  }
}

function closeCallModal() {
  document.getElementById('callModal').style.display = 'none';
  document.body.style.overflow = '';
}

function saveCallOutcome() {
  var btn = document.getElementById('callModalSaveBtn');
  btn.disabled = true; btn.textContent = 'Saving…';

  var startTs = Date.now();

  fetch('?page=api&action=log_call', {
          credentials:'same-origin',
          method: 'POST',
    headers: { 'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + (window._apiToken || '') },
    body: JSON.stringify({
      lead_id:          _callLeadId,
      outcome:          document.getElementById('callModalOutcome').value,
      note:             document.getElementById('callModalNote').value.trim(),
      new_status:       document.getElementById('callModalStatus').value,
      follow_up_date:   document.getElementById('callModalFollowUp').value,
      duration_seconds: Math.round((Date.now() - startTs) / 1000),
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if (d.status === 'success' || d.success || (d.data && d.data.success)) {
      closeCallModal();
      location.reload();
    } else {
      alert('❌ ' + (d.message || 'Save failed'));
      btn.disabled = false; btn.textContent = '💾 Save Call Log';
    }
  })
  .catch(function(e){
    alert('Network error: ' + e.message);
    btn.disabled = false; btn.textContent = '💾 Save Call Log';
  });
}

// ── Re-upload docs via API (no CSRF / no form submit issues) ─────────────────
function reupDocsUpload(appId, crmId, needPhoto, needId, needKit, csrf) {
  var btn    = document.getElementById('reup_btn_' + appId);
  var status = document.getElementById('reup_status_' + appId);
  var photoEl = document.getElementById('reup_photo_' + appId);
  var idEl    = document.getElementById('reup_id_'    + appId);
  var kitEl   = document.getElementById('reup_kit_'   + appId);

  var hasPhoto = photoEl && photoEl.files && photoEl.files.length > 0;
  var hasId    = idEl    && idEl.files    && idEl.files.length    > 0;
  var hasKit   = kitEl   && kitEl.files   && kitEl.files.length   > 0;

  if (!hasPhoto && !hasId && !hasKit) {
    status.style.display = 'block';
    status.style.color   = '#dc2626';
    status.textContent   = '⚠ Please select at least one file first';
    return;
  }

  btn.disabled    = true;
  btn.textContent = '⏳ Saving…';
  status.style.display = 'block';
  status.style.color   = '#1d4ed8';
  status.textContent   = '📤 Uploading…';

  var fd = new FormData();
  fd.append('action',  'kyc_reupload_docs');
  fd.append('app_id',  appId);
  if (hasPhoto) fd.append('customer_image', photoEl.files[0]);
  if (hasId)    fd.append('id_document',    idEl.files[0]);
  if (hasKit)   fd.append('kit_image',      kitEl.files[0]);

  // Post to same page — session cookie handles auth, no Bearer/CSRF needed
  fetch(window.location.pathname + window.location.search, {
          credentials:'same-origin',
          method:      'POST',
    credentials: 'same-origin',
    body:        fd
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    if (d.code === 200 || d.status === 'ok') {
      var s = d.data && d.data.saved ? d.data.saved : {};
      var parts = [];
      if (s.photo) parts.push('📷 Photo (' + s.photo.size + ')');
      if (s.id)    parts.push('🪪 ID ('    + s.id.size    + ')');
      if (s.kit)   parts.push('📦 Kit ('   + s.kit.size   + ')');
      status.style.color = '#15803d';
      status.textContent = '✅ Saved: ' + (parts.join(', ') || 'done');
      btn.textContent    = '✅ Saved';
      setTimeout(function() { window.location.reload(); }, 1500);
    } else {
      throw new Error(d.message || 'Server error');
    }
  })
  .catch(function(e) {
    btn.disabled    = false;
    btn.textContent = '💾 Save Photos';
    status.style.color = '#dc2626';
    status.textContent = '❌ ' + e.message;
  });
}
</script>

<script>
// ── Send Quote PDF via WhatsApp ────────────────────────────────
var _QTK = (document.cookie.match(/hybrid_token=([^;]+)/)||[])[1]||'';
function sendQuotePdf(appId, btn) {
    if (!confirm('Send quote PDF via WhatsApp to customer + admin?')) return;
    var orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Sending...';
    fetch('?page=api&action=wa_send_quote_pdf', {
          credentials:'same-origin',
          method: 'POST',
        headers: {'Authorization':'Bearer '+_QTK, 'Content-Type':'application/json'},
        body: JSON.stringify({application_id: appId, cc_admin: true})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        btn.disabled = false;
        if (d.status === 'success') {
            btn.textContent = '✅ Sent!';
            btn.style.background = '#bbf7d0';
            alert('Quote PDF sent!\nQuote: ' + (d.data.quote_number||'') + '\nSent to: ' + (d.data.sent_to||[]).join(', '));
        } else {
            btn.textContent = orig;
            // Auto-lookup failed — ask for quote ID manually
            var qid = prompt('Could not auto-find quote.\n\nEnter the UCRM Quote ID from the URL:\n(e.g. crm/client/quote/649 → enter 649)');
            if (qid && parseInt(qid) > 0) {
                btn.disabled = true; btn.textContent = 'Sending...';
                fetch('?page=api&action=wa_send_quote_pdf', {
          credentials:'same-origin',
          method: 'POST',
                    headers: {'Authorization':'Bearer '+_QTK, 'Content-Type':'application/json'},
                    body: JSON.stringify({application_id: appId, crm_quote_id: parseInt(qid), cc_admin: true})
                })
                .then(function(r2){ return r2.json(); })
                .then(function(d2){
                    btn.disabled = false;
                    if (d2.status === 'success') {
                        btn.textContent = '✅ Sent!';
                        btn.style.background = '#bbf7d0';
                        alert('Quote PDF sent!\nQuote: ' + (d2.data.quote_number||'') + '\nSent to: ' + (d2.data.sent_to||[]).join(', '));
                    } else {
                        btn.textContent = orig;
                        alert('Error: ' + (d2.message||'Unknown'));
                    }
                })
                .catch(function(){ btn.disabled=false; btn.textContent=orig; });
            }
        }
    })
    .catch(function(e){ btn.disabled=false; btn.textContent=orig; alert('Network error'); });
}
</script>
