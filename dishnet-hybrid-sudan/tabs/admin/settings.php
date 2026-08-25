<?php
// Tab: settings
// Extracted from public.php on 2026-03-15
?>
<?php
$stab = $_GET['stab'] ?? 'crm';
$allowed_stabs = ['crm','splynx','registration','commissions','system','webhook','health','4g'];
if (!in_array($stab, $allowed_stabs)) $stab = 'crm';

// Pre-compute for display
$ucrmJsonPath = $dataDir . '/ucrm.json';
$credSource   = $crm->getSource($GLOBALS['_PLUGIN_ROOT'] ?? dirname(__DIR__, 2), $config);
$splynxStatus = $splynx->isConfigured() ? $splynx->testConnection() : ['ok' => false, 'error' => ''];
$tariffMapDisplay = implode(', ', array_map(
    fn($k,$v) => "{$k}:{$v}",
    array_keys($config['splynx_tariff_map'] ?? []),
    array_values($config['splynx_tariff_map'] ?? [])
));
$allAppsSeq   = $store->load('kyc_applications.json') ?? [];
$starApps     = array_filter($allAppsSeq, fn($a) => ($a['customer_type']??'') !== 'Fiber');
$ftthApps     = array_filter($allAppsSeq, fn($a) => ($a['customer_type']??'') === 'Fiber');
$maxStarInDB  = empty($starApps) ? 0 : max(array_map(fn($a)=>(int)($a['username_seq']??0),$starApps));
$maxFtthInDB  = empty($ftthApps) ? 0 : max(array_map(fn($a)=>(int)($a['username_seq']??0),$ftthApps));
$effStarStart = max($maxStarInDB, (int)($config['star_seq_start']??0));
$effFtthStart = max($maxFtthInDB, (int)($config['ftth_seq_start']??0));
$wsLastRun    = file_exists($dataDir.'/wallet_sync_last_run.txt')
    ? date('d M H:i', (int)file_get_contents($dataDir.'/wallet_sync_last_run.txt')) : null;
$wsi          = (int)($config['wallet_sync_interval_minutes'] ?? 360);
$eSettings    = $store->load('email_settings.json') ?? [];

//  Edit Collection (Admin CRUD) 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_collection') {
    $auth->requireAdmin();
    csrfCheck();
    $colId     = (int)($_POST['collection_id'] ?? 0);
    $newMethod = trim($_POST['method']  ?? '');
    $newAmount = (float)($_POST['amount'] ?? 0);
    $newNote   = trim($_POST['note']    ?? '');
    if (!$colId || !$newMethod || $newAmount <= 0) {
        flash('Invalid edit data.', 'danger');
        redirect('?page=dashboard&tab=all_collections');
    }
    $allowed = ['Cash','Mobile Money','Bank Transfer','Cheque','Other'];
    if (!in_array($newMethod, $allowed, true)) {
        flash('Invalid payment method.', 'danger');
        redirect('?page=dashboard&tab=all_collections');
    }
    $cols = $store->load('payment_collections.json') ?? [];
    $orig = null;
    foreach ($cols as $c) { if ((int)($c['id'] ?? 0) === $colId) { $orig = $c; break; } }
    if (!$orig) { flash('Collection not found.', 'danger'); redirect('?page=dashboard&tab=all_collections'); }

    $custId      = trim($orig['crm_customer_id'] ?? '');
    $invoiceId   = trim($orig['invoice_id'] ?? '');
    $crmSyncMsg  = '';
    $newCrmSynced   = !empty($orig['crm_synced']);
    $newCrmPaymentId = $orig['crm_payment_id'] ?? null;

    //  CRM sync 
    if ($crm->isConfigured() && $custId) {
        $crmPayload = [
            'clientId'     => (int)$custId,
            'methodId'     => PaymentUuids::resolve($newMethod),
            'amount'       => $newAmount,
            'currencyCode' => 'USD',
            'note'         => "Collected by {$orig['retailer_name']} [EDITED by admin]  {$newNote}",
            'applyToInvoicesAutomatically' => true,
        ];
        if ($invoiceId) {
            $crmPayload['note'] .= ' | Invoice: ' . $invoiceId;
        }

        // Case 1: already synced  delete old payment in UCRM, re-post corrected one
        if (!empty($orig['crm_synced']) && !empty($orig['crm_payment_id'])) {
            $delResp = $crm->delete('payments/' . (int)$orig['crm_payment_id']);
            // delete returns empty body on 204 success; null or error on failure
            $lastErr = $crm->getLastError();
            $delOk   = empty($lastErr['http_code']) || $lastErr['http_code'] === 204 || $lastErr['http_code'] === 200;
            if (!$delOk) {
                $crmSyncMsg = '  Could not delete old UCRM payment #'.(int)$orig['crm_payment_id'].'. Check UCRM manually.';
                logActivity($dataDir, 'crm_payment_delete_failed', "Failed to delete old UCRM payment #{$orig['crm_payment_id']} for collection #{$colId}", json_encode($lastErr));
            }
            // Always re-post even if delete uncertain  admin was warned
            $crmResult  = $crm->post('payments', $crmPayload);
            $crmSuccess = !empty($crmResult) && isset($crmResult['id']);
            if ($crmSuccess) {
                $newCrmSynced    = true;
                $newCrmPaymentId = $crmResult['id'];
                $crmSyncMsg     .= ' UCRM payment updated (new ID #'.$crmResult['id'].').';
            } else {
                $lastErr2   = $crm->getLastError();
                $errMsg2    = isset($lastErr2['http_code']) ? "HTTP {$lastErr2['http_code']}: ".json_encode($lastErr2['response']??'') : ($lastErr2['curl_error']??json_encode($lastErr2));
                $newCrmSynced    = false;
                $newCrmPaymentId = null;
                $crmSyncMsg     .= '  Re-post to UCRM failed: '.$errMsg2;
                $store->appendWithId('crm_payment_retry.json', [
                    'customer_name' => $orig['customer_name'],
                    'crm_client_id' => $custId,
                    'collection_id' => $colId,
                    'payload'       => $crmPayload,
                    'error'         => $errMsg2,
                    'attempts'      => 1,
                    'next_retry_at' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
                    'created_at'    => date('Y-m-d H:i:s'),
                    'status'        => 'pending',
                ]);
                logActivity($dataDir, 'crm_payment_failed', "CRM re-post failed after edit for collection #{$colId}", $errMsg2);
            }

        // Case 2: never synced  try first-time post now
        } else {
            $crmResult  = $crm->post('payments', $crmPayload);
            $crmSuccess = !empty($crmResult) && isset($crmResult['id']);
            if ($crmSuccess) {
                $newCrmSynced    = true;
                $newCrmPaymentId = $crmResult['id'];
                $crmSyncMsg      = '  Synced to UCRM (payment ID #'.$crmResult['id'].').';
            } else {
                $lastErr3   = $crm->getLastError();
                $errMsg3    = isset($lastErr3['http_code']) ? "HTTP {$lastErr3['http_code']}: ".json_encode($lastErr3['response']??'') : ($lastErr3['curl_error']??json_encode($lastErr3));
                $crmSyncMsg  = '  UCRM sync failed  queued for retry. '.$errMsg3;
                $store->appendWithId('crm_payment_retry.json', [
                    'customer_name' => $orig['customer_name'],
                    'crm_client_id' => $custId,
                    'collection_id' => $colId,
                    'payload'       => $crmPayload,
                    'error'         => $errMsg3,
                    'attempts'      => 1,
                    'next_retry_at' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
                    'created_at'    => date('Y-m-d H:i:s'),
                    'status'        => 'pending',
                ]);
                logActivity($dataDir, 'crm_payment_failed', "CRM first-sync failed after edit for collection #{$colId}", $errMsg3);
            }
        }
    } else {
        $crmSyncMsg = $crm->isConfigured() ? ' (No CRM customer ID  local edit only.)' : ' (CRM not configured  local edit only.)';
    }

    //  Save updated record 
    $updated = $store->updateOne('payment_collections.json', 'id', $colId, [
        'method'          => $newMethod,
        'amount'          => $newAmount,
        'note'            => $newNote,
        'crm_synced'      => $newCrmSynced,
        'crm_payment_id'  => $newCrmPaymentId,
        'edited_by'       => $adminUser['username'] ?? 'admin',
        'edited_at'       => date('Y-m-d H:i:s'),
        'edit_note'       => "Was: \${$orig['amount']} / {$orig['method']}",
    ]);
    logActivity($dataDir, 'edit_collection',
        "Collection #{$colId} edited",
        "Customer: {$orig['customer_name']} | Amount: \${$orig['amount']}\${$newAmount} | Method: {$orig['method']}{$newMethod} | By: ".($adminUser['username']??'admin')
    );
    flash(($updated ? "Collection #{$colId} updated." : "Update failed.").$crmSyncMsg, $updated ? 'success' : 'danger');
    redirect('?page=dashboard&tab=all_collections');
}

//  Delete Collection (Admin CRUD) 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_collection') {
    $auth->requireAdmin();
    csrfCheck();
    $colId = (int)($_POST['collection_id'] ?? 0);
    if (!$colId) { flash('Invalid request.', 'danger'); redirect('?page=dashboard&tab=all_collections'); }
    $cols = $store->load('payment_collections.json') ?? [];
    $orig = null;
    foreach ($cols as $c) { if ((int)($c['id'] ?? 0) === $colId) { $orig = $c; break; } }
    if (!$orig) { flash('Collection not found.', 'danger'); redirect('?page=dashboard&tab=all_collections'); }
    if (!empty($orig['crm_synced'])) {
        flash("Cannot delete  Collection #{$colId} is already synced to CRM. Reverse it in UCRM first.", 'danger');
        redirect('?page=dashboard&tab=all_collections');
    }
    $newCols = array_values(array_filter($cols, fn($c) => (int)($c['id'] ?? 0) !== $colId));
    $store->save('payment_collections.json', $newCols);
    logActivity($dataDir, 'delete_collection',
        "Collection #{$colId} deleted",
        "Customer: {$orig['customer_name']} | Amount: \${$orig['amount']} | Method: {$orig['method']} | By: ".($adminUser['username']??'admin')
    );
    flash("Collection #{$colId} deleted.", 'success');
    redirect('?page=dashboard&tab=all_collections');
}

//  Mark all pending collections as synced (already entered in CRM manually) 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_synced') {
    $auth->requireAdmin();
    csrfCheck();
    $cols = $store->load('payment_collections.json') ?? [];
    $marked = 0;
    foreach ($cols as &$c) {
        if (empty($c['crm_synced'])) {
            $c['crm_synced']    = true;
            $c['crm_synced_at'] = date('Y-m-d H:i:s');
            $c['crm_synced_by'] = 'manual_admin';
            $marked++;
        }
    }
    unset($c);
    $store->save('payment_collections.json', $cols);
    logActivity($dataDir, 'bulk_mark_synced', "Bulk marked {$marked} collections as synced", 'By: '.($adminUser['username']??'admin'));
    flash(" {$marked} collections marked as synced.", 'success');
    redirect('?page=dashboard&tab=all_collections');
}

//  Push pending collections to CRM + sync cashbook 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'push_pending_to_crm') {
    $auth->requireAdmin();
    csrfCheck();
    require_once __DIR__ . '/../lib/CashbookService.php';
    $cb   = new CashbookService($store, $dataDir);
    $cols = $store->load('payment_collections.json') ?? [];
    $pushed = 0; $failed = 0; $alreadySynced = 0;

    foreach ($cols as &$c) {
        if (!empty($c['crm_synced'])) { $alreadySynced++; continue; }

        $custId = (int)($c['crm_customer_id'] ?? 0);
        $amount = (float)($c['amount'] ?? 0);
        if ($amount <= 0) continue;

        // Build CRM payload
        $payload = [
            'clientId'     => $custId,
            'methodId'     => PaymentUuids::resolve($c['method'] ?? 'Cash'),
            'amount'       => $amount,
            'currencyCode' => 'USD',
            'note'         => 'Collected by '.($c['retailer_name']??'agent').' via DishNet PWA'
                            . ($c['invoice_id'] ? ' (Inv #'.$c['invoice_id'].')' : '')
                            . ($c['note'] ? '  '.$c['note'] : ''),
        ];
        if (!empty($c['invoice_id'])) {
            $payload['applyToInvoicesAutomatically'] = true;
        } else {
            $payload['applyToInvoicesAutomatically'] = true;
        }

        $result   = $crm->post('payments', $payload); // leadclient auto-handled in CrmApiClient::post()
        $success  = !empty($result) && isset($result['id']);

        if ($success) {
            $crmPayId = $result['id'];
            $c['crm_synced']     = true;
            $c['crm_payment_id'] = $crmPayId;
            $c['crm_synced_at']  = date('Y-m-d H:i:s');

            // Post to cashbook (idempotent via SR)
            $colSr = 'COL-'.($c['id'] ?? uniqid());
            try {
                $cb->addEntryRaw([
                    'sr'                => $colSr,
                    'project'           => 'dishnet',
                    'date'              => substr($c['created_at'] ?? date('Y-m-d'), 0, 10),
                    'direction'         => 'in',
                    'amount'            => $amount,
                    'currency'          => 'USD',
                    'category'          => 'Receipt',
                    'category_raw'      => 'Receipt',
                    'person'            => $c['retailer_name'] ?? '',
                    'description'       => 'Cash collected from '.($c['customer_name']??'').($custId?' (CRM #'.$custId.')':''),
                    'validation_ref'    => 'PAY-'.$crmPayId,
                    'validation_status' => 'na',
                    'status'            => 'approved',
                    'approved_by'       => 'CRM Push',
                    'crm_payment_id'    => $crmPayId,
                    'crm_client_id'     => $custId,
                    'source'            => 'collect_payment',
                    'created_at'        => ($c['created_at'] ?? date('Y-m-d H:i:s')),
                ]);
            } catch (\Throwable $_) {}
            $pushed++;
        } else {
            $err = $crm->getLastError();
            $c['last_crm_error'] = isset($err['http_code'])
                ? "HTTP {$err['http_code']}: ".json_encode($err['response']??'')
                : ($err['curl_error'] ?? json_encode($err));
            $failed++;
        }
    }
    unset($c);
    $store->save('payment_collections.json', $cols);
    logActivity($dataDir, 'push_to_crm', "Pushed {$pushed} collections to CRM ({$failed} failed)", 'By: '.($adminUser['username']??'admin'));

    $msg = " {$pushed} pushed to CRM + cashbook.";
    if ($failed)       $msg .= "  {$failed} failed  check CRM connection.";
    if ($alreadySynced) $msg .= " {$alreadySynced} already synced (skipped).";
    flash($msg, $failed ? 'warning' : 'success');
    redirect('?page=dashboard&tab=all_collections');
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_email_settings') {
    $eNew = [
        'recipients'      => trim($_POST['email_recipients'] ?? ''),
        'use_ucrm_email'  => !empty($_POST['use_ucrm_email']),
        'smtp_preset'     => trim($_POST['smtp_preset'] ?? ''),
        'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
        'smtp_port'       => (int)($_POST['smtp_port'] ?? 587),
        'smtp_user'       => trim($_POST['smtp_user'] ?? ''),
        'smtp_enc'        => $_POST['smtp_enc'] ?? 'tls',
        'smtp_from'       => trim($_POST['smtp_from'] ?? ''),
    ];
    if (!empty($_POST['smtp_pass'])) $eNew['smtp_pass'] = trim($_POST['smtp_pass']);
    else { $existing = $store->load('email_settings.json'); $eNew['smtp_pass'] = $existing['smtp_pass'] ?? ''; }
    $store->save('email_settings.json', $eNew);
    flash(' Email settings saved.', 'success');
    redirect('?page=dashboard&tab=settings&stab=system');
}
?>
<style>
.st-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:20px;overflow-x:auto;}
.st-tab{padding:10px 18px;font-size:13px;font-weight:700;color:#6b7280;text-decoration:none;border-bottom:3px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:.15s;}
.st-tab:hover{color:#374151;background:#f8fafc;}
.st-tab.active{color:#D41C1C;border-bottom-color:#D41C1C;}
.st-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px 22px;margin-bottom:16px;}
.st-card-title{font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.6px;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.st-row{display:grid;gap:14px;margin-bottom:14px;}
.st-row.cols2{grid-template-columns:1fr 1fr;}
.st-row.cols3{grid-template-columns:1fr 1fr 1fr;}
.st-hint{font-size:11px;color:#9ca3af;margin-top:3px;line-height:1.4;}
.st-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:8px;font-size:11px;font-weight:700;}
.st-badge.ok{background:#d1fae5;color:#065f46;}
.st-badge.warn{background:#fef3c7;color:#92400e;}
.st-badge.err{background:#fee2e2;color:#991b1b;}
.st-save{background:#D41C1C;color:#fff;border:none;border-radius:10px;padding:10px 28px;font-size:13px;font-weight:700;cursor:pointer;margin-top:4px;}
.st-save:hover{background:#A81515;}
@media(max-width:600px){.st-row.cols2,.st-row.cols3{grid-template-columns:1fr;}}
</style>

<div class="st-tabs">
    <a class="st-tab <?= $stab==='crm'?'active':'' ?>" href="?page=dashboard&tab=settings&stab=crm"> CRM & Sync</a>
    <a class="st-tab <?= $stab==='splynx'?'active':'' ?>" href="?page=dashboard&tab=settings&stab=splynx"> Splynx</a>
    <a class="st-tab <?= $stab==='registration'?'active':'' ?>" href="?page=dashboard&tab=settings&stab=registration"> Registration</a>
    <a class="st-tab <?= $stab==='commissions'?'active':'' ?>" href="?page=dashboard&tab=settings&stab=commissions"> Commissions</a>
    <a class="st-tab <?= $stab==='system'?'active':'' ?>" href="?page=dashboard&tab=settings&stab=system"> System</a>
    <a class="st-tab <?= $stab==='webhook'?'active':'' ?>" href="?page=dashboard&tab=settings&stab=webhook"> Webhooks</a>
    <a class="st-tab <?= $stab==='4g'?'active':'' ?>" href="?page=dashboard&tab=settings&stab=4g" style="color:<?= $stab!=='4g'?'#1D4ED8':'' ?>;font-weight:700;">4G BlueCard</a>
    <a class="st-tab <?= $stab==='health'?'active':'' ?>" href="?page=dashboard&tab=settings&stab=health" style="color:<?= $stab!=='health'?'#16a34a':'' ?>;font-weight:800;"> Health</a>
</div>

<?php /*  CRM & SYNC TAB  */ ?>
<?php if ($stab === 'crm'): ?>
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="action" value="save_settings">
<?php /* passthrough all wa_ fields so they don't get wiped */ ?>
<input type="hidden" name="wa_plugin_url"        value="<?= h($config['wa_plugin_url']??'') ?>">
<input type="hidden" name="wa_app_key"           value="<?= h($config['wa_app_key']??'') ?>">
<input type="hidden" name="wa_accounts_app_key"  value="<?= h($config['wa_accounts_app_key']??'') ?>">
<input type="hidden" name="wa_auth_key"          value="<?= h($config['wa_auth_key']??'') ?>">
<input type="hidden" name="wa_send_pdf" value="<?= ($config['wa_send_pdf'] ?? true) !== false ? '1' : '0' ?>">
<input type="hidden" name="wa_support_number"    value="<?= h($config['wa_support_number']??'') ?>">
<input type="hidden" name="wa_accounts_number"   value="<?= h($config['wa_accounts_number']??'') ?>">
<input type="hidden" name="whatsapp_admin_phone" value="<?= h($config['whatsapp_admin_phone']??'') ?>">
<input type="hidden" name="whatsapp_webhook_url" value="<?= h($config['whatsapp_webhook_url']??'') ?>">
<input type="hidden" name="fiber_install_fee"    value="<?= h((string)($config['fiber_install_fee']??100)) ?>">
<input type="hidden" name="auto_quote_enabled"   value="<?= h((string)($config['auto_quote_enabled']??1)) ?>">
<input type="hidden" name="auto_quote_validity_days" value="<?= h((string)($config['auto_quote_validity_days']??7)) ?>">
<input type="hidden" name="large_txn_threshold"  value="<?= h((string)($config['large_txn_threshold']??500)) ?>">

<div class="st-card">
    <div class="st-card-title"> UCRM Connection
        <?php if ($credSource === 'auto'): ?>
        <span class="st-badge ok"> Auto-detected</span>
        <?php elseif ($credSource === 'manual'): ?>
        <span class="st-badge warn"> Manual override</span>
        <?php else: ?>
        <span class="st-badge err"> Not configured</span>
        <?php endif; ?>
    </div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">CRM Base URL <small class="text-muted">(leave blank for auto)</small></label>
            <input type="url" name="crm_base_url" class="form-control" value="<?= h($config['crm_base_url']??'') ?>" placeholder="Auto from ucrm.json">
        </div>
        <div>
            <label class="form-label">Admin Auth Token <small style="color:#D97706;"> Required for Quotes</small></label>
            <input type="password" name="crm_auth_token" class="form-control" value="<?= h($config['crm_auth_token']??'') ?>" placeholder="Paste UCRM admin API token here">
            <div style="font-size:11px;color:#64748B;margin-top:4px;">UCRM  My Profile  API tokens  Create. Plugin key cannot create quotes/invoices.</div>
        </div>
    </div>
    <button type="button" onclick="testCrmConn()"
        style="background:#D41C1C;color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;">
         Test CRM Connection
    </button>
    <span id="crmTestResult" style="margin-left:10px;font-size:12px;font-weight:600;"></span>
</div>

<div class="st-card">
    <div class="st-card-title"> Sync & Pull</div>
    <div class="st-row cols3">
        <div>
            <label class="form-label">KYC Queue Auto-Refresh</label>
            <select name="auto_sync_interval" class="form-control">
                <option value="0"  <?= ($config['auto_sync_interval']??60)==0  ?'selected':'' ?>>Off</option>
                <option value="30" <?= ($config['auto_sync_interval']??60)==30 ?'selected':'' ?>>30 sec</option>
                <option value="60" <?= ($config['auto_sync_interval']??60)==60 ?'selected':'' ?>>60 sec</option>
                <option value="120"<?= ($config['auto_sync_interval']??60)==120?'selected':'' ?>>2 min</option>
                <option value="300"<?= ($config['auto_sync_interval']??60)==300?'selected':'' ?>>5 min</option>
            </select>
        </div>
        <div>
            <label class="form-label">Wallet Sync Interval</label>
            <select name="wallet_sync_interval_minutes" class="form-control">
                <?php $opts=[0=>'Every ~5 min',60=>'1 hr',180=>'3 hrs',360=>'6 hrs',720=>'12 hrs',1440=>'24 hrs'];
                foreach($opts as $v=>$l): ?>
                <option value="<?= $v ?>" <?= $wsi===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($wsLastRun): ?><div class="st-hint">Last run: <?= $wsLastRun ?></div><?php endif; ?>
        </div>
        <div>
            <label class="form-label">UCRM Auto-Pull Hour (023)</label>
            <select name="ucrm_auto_pull_hour" class="form-control">
                <?php for($h=0;$h<24;$h++): ?>
                <option value="<?= $h ?>" <?= ((int)($config['ucrm_auto_pull_hour']??3))===$h?'selected':'' ?>><?= str_pad((string)$h,2,'0',STR_PAD_LEFT) ?>:00<?= $h===3?' (default)':'' ?></option>
                <?php endfor; ?>
            </select>
        </div>
    </div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">UCRM Auto-Pull</label>
            <select name="ucrm_auto_pull_enabled" class="form-control">
                <option value="1" <?= ($config['ucrm_auto_pull_enabled']??true)?'selected':'' ?>> Nightly automatic</option>
                <option value="0" <?= ($config['ucrm_auto_pull_enabled']??true)?'':'selected' ?>> Manual only</option>
            </select>
        </div>
        <div>
            <label class="form-label">cron_sync.php Path</label>
            <input type="text" name="cron_sync_path" class="form-control" value="<?= h($config['cron_sync_path'] ?? (__DIR__.'/cron_sync.php')) ?>">
            <div class="st-hint">Used by Sync Now button. Also add to crontab: <code>* * * * * php [path] >> /tmp/sync.log 2>&1</code></div>
        </div>
    </div>
</div>

<button type="submit" class="st-save"> Save CRM & Sync Settings</button>
</form>

<script>
function testCrmConn() {
    var btn = event.target; var res = document.getElementById('crmTestResult');
    btn.disabled=true; btn.textContent=''; res.textContent='';
    fetch('?page=api&action=test_crm_connection',{credentials:'same-origin',headers:{'Authorization':'Bearer <?= h($retailer['api_token']??"") ?>'}})
    .then(r=>r.json()).then(d=>{
        var r=d&&d.data?d.data:d;
        if(r.ok){res.style.color='#10b981';res.textContent=' Connected  HTTP '+r.http_code;}
        else{res.style.color='#ef4444';res.textContent=' '+(r.error||d.message||'Failed');}
    }).catch(()=>{res.style.color='#ef4444';res.textContent=' Network error';})
    .finally(()=>{btn.disabled=false;btn.textContent=' Test CRM Connection';});
}
</script>

<?php /*  SPLYNX TAB  */ ?>
<?php elseif ($stab === 'splynx'): ?>
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="action" value="save_settings">
<input type="hidden" name="wa_plugin_url"        value="<?= h($config['wa_plugin_url']??'') ?>">
<input type="hidden" name="wa_app_key"           value="<?= h($config['wa_app_key']??'') ?>">
<input type="hidden" name="wa_accounts_app_key"  value="<?= h($config['wa_accounts_app_key']??'') ?>">
<input type="hidden" name="wa_auth_key"          value="<?= h($config['wa_auth_key']??'') ?>">
<input type="hidden" name="wa_send_pdf" value="<?= ($config['wa_send_pdf'] ?? true) !== false ? '1' : '0' ?>">
<input type="hidden" name="wa_support_number"    value="<?= h($config['wa_support_number']??'') ?>">
<input type="hidden" name="wa_accounts_number"   value="<?= h($config['wa_accounts_number']??'') ?>">
<input type="hidden" name="whatsapp_admin_phone" value="<?= h($config['whatsapp_admin_phone']??'') ?>">
<input type="hidden" name="whatsapp_webhook_url" value="<?= h($config['whatsapp_webhook_url']??'') ?>">
<input type="hidden" name="fiber_install_fee"    value="<?= h((string)($config['fiber_install_fee']??100)) ?>">

<div class="st-card">
    <div class="st-card-title"> Splynx ISP Framework
        <?php if ($splynxStatus['ok'] ?? false): ?>
        <span class="st-badge ok"> Connected</span>
        <?php elseif ($splynx->isConfigured()): ?>
        <span class="st-badge err"> <?= h(substr($splynxStatus['error'] ?? 'Failed', 0, 60)) ?></span>
        <?php else: ?>
        <span class="st-badge warn"> Not configured</span>
        <?php endif; ?>
    </div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">Splynx URL</label>
            <input type="url" name="splynx_url" class="form-control" value="<?= h($config['splynx_url']??'') ?>" placeholder="https://isp.dishnetafrica.com">
        </div>
        <div>
            <label class="form-label">Sync Interval (minutes)</label>
            <input type="number" name="splynx_sync_interval_minutes" class="form-control" value="<?= (int)($config['splynx_sync_interval_minutes']??5) ?>" min="1" max="60">
        </div>
    </div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">API Key</label>
            <input type="text" name="splynx_key" class="form-control" value="<?= h($config['splynx_key']??'') ?>" placeholder="From Splynx  Config  Administrators  API keys">
        </div>
        <div>
            <label class="form-label">API Secret</label>
            <input type="password" name="splynx_secret" class="form-control" value="<?= h($config['splynx_secret']??'') ?>">
            <?php if (!empty($config['splynx_key'])): ?>
            <div class="st-hint"> Configured: <strong><?= h($config['splynx_url']) ?></strong></div>
            <?php endif; ?>
        </div>
    </div>    <div class="st-row cols3">
        <div>
            <label class="form-label">Default Tariff ID</label>
            <input type="number" name="splynx_default_tariff_id" class="form-control" value="<?= (int)($config['splynx_default_tariff_id']??1) ?>" min="1">
            <div class="st-hint">Splynx tariff plan ID for new fiber services</div>
        </div>
        <div>
            <label class="form-label">Fiber Admin ID</label>
            <input type="number" name="splynx_fiber_admin_id" class="form-control" value="<?= (int)($config['splynx_fiber_admin_id']??0) ?>" min="0">
            <div class="st-hint">Auto-assign tickets (0 = unassigned)</div>
        </div>
        <div>
            <label class="form-label">Accountant UCRM User ID <span style="background:#dcfce7;color:#166534;font-size:10px;padding:1px 6px;border-radius:4px;font-weight:700;">Rupesh</span></label>
            <input type="number" name="accountant_ucrm_user_id" class="form-control" value="<?= (int)($config['accountant_ucrm_user_id']??0) ?>" min="0">
            <div class="st-hint">UCRM user ID for Rupesh  fiber install jobs will be assigned to him in CRM Scheduling. Find it at CRM  My Profile  API.</div>
        </div>
        <div>
            <label class="form-label">Plan  Tariff Map</label>
            <input type="text" name="splynx_tariff_map_raw" class="form-control" value="<?= h($tariffMapDisplay) ?>" placeholder="10Mbps Fiber:5, 20Mbps Fiber:6">
            <div class="st-hint">PlanName:TariffId, comma-separated</div>
        </div>
    </div>
    <div style="margin:10px 0 14px;display:flex;align-items:center;gap:8px;">
        <input class="form-check-input" type="checkbox" name="splynx_auto_provision" value="1"
               id="splynxAP" <?= !empty($config['splynx_auto_provision'])?'checked':'' ?>>
        <label for="splynxAP" style="font-size:13px;font-weight:700;margin:0;">Auto-provision Fiber KYC approvals in Splynx</label>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
        <button type="button" onclick="splynxTestConn()"
            style="background:#1565C0;color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;">
             Test Splynx Connection
        </button>
        <span id="splynxTestResult" style="font-size:12px;font-weight:600;"></span>
    </div>
</div>

<div class="st-card">
    <div class="st-card-title"> FTTH Custom Attribute IDs</div>
    <div class="st-row cols3">
        <div>
            <label class="form-label">Wallet Balance Attr ID</label>
            <input type="number" name="ftth_attr_wallet_balance" class="form-control" value="<?= (int)($config['ftth_attr_wallet_balance']??0) ?>" min="0">
        </div>
        <div>
            <label class="form-label">Retailer ID Attr ID</label>
            <input type="number" name="ftth_attr_retailer_id" class="form-control" value="<?= (int)($config['ftth_attr_retailer_id']??0) ?>" min="0">
        </div>
        <div>
            <label class="form-label">Retailer Role Attr ID</label>
            <input type="number" name="ftth_attr_retailer_role" class="form-control" value="<?= (int)($config['ftth_attr_retailer_role']??0) ?>" min="0">
        </div>
    </div>
    <div class="st-hint" style="margin-top:-6px;">UCRM custom attribute IDs  used when syncing retailer data to Org-7. Find these in UCRM  System  Custom Attributes.</div>
</div>

<button type="submit" class="st-save"> Save Splynx Settings</button>
</form>

<script>
function splynxTestConn() {
    var res = document.getElementById('splynxTestResult');
    res.textContent = ' Testing'; res.style.color = '#6b7280';
    fetch('?page=api&action=splynx_test', {credentials:'same-origin',headers:{'Authorization':'Bearer <?= h($retailer['api_token']??"") ?>'}})
    .then(r=>r.json()).then(d=>{
        var ok = d.data?.ok ?? d.ok ?? false;
        if(ok){res.style.color='#10b981';res.textContent=' Connected';}
        else{res.style.color='#ef4444';res.textContent=' '+(d.data?.error||d.message||'Failed');}
    }).catch(()=>{res.style.color='#ef4444';res.textContent=' Network error';});
}
</script>

<?php /*  REGISTRATION TAB  */ ?>
<?php elseif ($stab === 'registration'): ?>
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="action" value="save_settings">
<input type="hidden" name="wa_plugin_url"        value="<?= h($config['wa_plugin_url']??'') ?>">
<input type="hidden" name="wa_app_key"           value="<?= h($config['wa_app_key']??'') ?>">
<input type="hidden" name="wa_accounts_app_key"  value="<?= h($config['wa_accounts_app_key']??'') ?>">
<input type="hidden" name="wa_auth_key"          value="<?= h($config['wa_auth_key']??'') ?>">
<input type="hidden" name="wa_send_pdf" value="<?= ($config['wa_send_pdf'] ?? true) !== false ? '1' : '0' ?>">
<input type="hidden" name="wa_support_number"    value="<?= h($config['wa_support_number']??'') ?>">
<input type="hidden" name="wa_accounts_number"   value="<?= h($config['wa_accounts_number']??'') ?>">
<input type="hidden" name="whatsapp_admin_phone" value="<?= h($config['whatsapp_admin_phone']??'') ?>">
<input type="hidden" name="whatsapp_webhook_url" value="<?= h($config['whatsapp_webhook_url']??'') ?>">
<input type="hidden" name="fiber_install_fee"    value="<?= h((string)($config['fiber_install_fee']??100)) ?>">

<div class="st-card">
    <div class="st-card-title"> Form Dropdowns</div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">Sales Persons / Referral Sources <span style="background:#17a2b8;color:#fff;font-size:10px;padding:1px 6px;border-radius:6px;">one per line</span></label>
            <textarea name="sales_persons_text" class="form-control" rows="7" style="font-family:monospace;font-size:13px;"><?= h(implode("\n", $config['sales_persons']??[])) ?></textarea>
            <div class="st-hint">Appears in "Sales Person" and "How Do You Know" dropdowns on the KYC form</div>
        </div>
        <div>
            <label class="form-label">Accessories List <span style="background:#17a2b8;color:#fff;font-size:10px;padding:1px 6px;border-radius:6px;">one per line</span></label>
            <textarea name="accessories_text" class="form-control" rows="7" style="font-family:monospace;font-size:13px;"><?= h(implode("\n", $config['accessories']??[])) ?></textarea>
            <div class="st-hint">Appears in "Add Accessory" dropdown on the KYC form</div>
        </div>
    </div>
</div>

<div class="st-card">
    <div class="st-card-title"> Username Sequence Numbering</div>
    <div style="background:#E3F2FD;border-radius:8px;padding:9px 12px;font-size:12px;color:#1565C0;margin-bottom:12px;">
        Set the <strong>last used number</strong> from your old system  new registrations continue from +1.
        Current DB max  Starlink: <strong style="font-family:monospace;"><?= $maxStarInDB ?></strong> | Fiber: <strong style="font-family:monospace;"><?= $maxFtthInDB ?></strong>
    </div>
    <div class="st-row cols2">
        <div>
            <label class="form-label"> Starlink  last used #</label>
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-family:monospace;font-weight:700;color:#0D47A1;background:#E3F2FD;padding:5px 10px;border-radius:6px;font-size:13px;">STAR</span>
                <input type="number" name="star_seq_start" class="form-control" value="<?= (int)($config['star_seq_start']??0) ?>" min="0" max="999999" style="font-family:monospace;font-weight:700;max-width:110px;"
                    oninput="document.getElementById('starPrev').textContent='STAR'+String(Math.max(parseInt(this.value||0),<?= $maxStarInDB ?>)+1).padStart(6,'0')">
                <span style="font-size:12px;color:#6b7280;"> <strong id="starPrev" style="font-family:monospace;color:#D41C1C;">STAR<?= str_pad((string)($effStarStart+1),6,'0',STR_PAD_LEFT) ?></strong></span>
            </div>
        </div>
        <div>
            <label class="form-label"> Fiber  last used #</label>
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-family:monospace;font-weight:700;color:#2E7D32;background:#E8F5E9;padding:5px 10px;border-radius:6px;font-size:13px;">FTTH</span>
                <input type="number" name="ftth_seq_start" class="form-control" value="<?= (int)($config['ftth_seq_start']??0) ?>" min="0" max="999999" style="font-family:monospace;font-weight:700;max-width:110px;"
                    oninput="document.getElementById('ftthPrev').textContent='FTTH'+String(Math.max(parseInt(this.value||0),<?= $maxFtthInDB ?>)+1).padStart(6,'0')">
                <span style="font-size:12px;color:#6b7280;"> <strong id="ftthPrev" style="font-family:monospace;color:#2E7D32;">FTTH<?= str_pad((string)($effFtthStart+1),6,'0',STR_PAD_LEFT) ?></strong></span>
            </div>
        </div>
    </div>
</div>

<div class="st-card">
    <div class="st-card-title"> Auto-Quote on Registration
        <?php if (!empty($config['crm_auth_token'])): ?>
        <span class="st-badge ok"> Admin token set</span>
        <?php else: ?>
        <span class="st-badge err"> No admin token  quotes will fail</span>
        <?php endif; ?>
    </div>
    <?php if (empty($config['crm_auth_token'])): ?>
    <div style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:10px;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#92400E;">
        <strong> Admin API Token Required</strong><br>
        Quotes need an admin-level token. The plugin app key can create clients and payments, but UCRM blocks quotes/invoices without admin auth.<br>
        <strong>How to fix:</strong> Go to UCRM  click your avatar (top-right)  My Profile  scroll to API tokens  Create new token  paste it in the "Admin Auth Token" field above  Save.
    </div>
    <?php endif; ?>
    <div class="st-row cols2">
        <div>
            <label class="form-label">Create proforma quote on every KYC</label>
            <select name="kyc_auto_quote_enabled" class="form-control">
                <option value="1" <?= ($config['kyc_auto_quote_enabled']??true)?'selected':'' ?>> Yes  auto-create in UCRM</option>
                <option value="0" <?= ($config['kyc_auto_quote_enabled']??true)?'':'selected' ?>> No  skip</option>
            </select>
        </div>
        <div>
            <label class="form-label">Quote validity (days)</label>
            <input type="number" name="kyc_quote_validity_days" class="form-control" value="<?= (int)($config['kyc_quote_validity_days']??7) ?>" min="1" max="90">
        </div>
    </div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">Max cart total for auto-quote ($) <span style="font-weight:400;color:#9ca3af;">(0 = no limit)</span></label>
            <input type="number" name="kyc_auto_quote_max_amount" class="form-control" value="<?= (float)($config['kyc_auto_quote_max_amount']??0) ?>" min="0" step="10">
            <div class="st-hint">Suppress auto-quote if cart exceeds this  e.g. set 300 to skip non-standard hardware bundles</div>
        </div>
        <div>
            <label class="form-label">Quote notes prefix</label>
            <input type="text" name="kyc_quote_notes_prefix" class="form-control" value="<?= h($config['kyc_quote_notes_prefix']??'') ?>" placeholder="e.g. Valid 7 days. Call +211...">
        </div>
    </div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">Quote prefix (letters/numbers)</label>
            <input type="text" name="quote_prefix" class="form-control" value="<?= h($config['quote_prefix']??'QUO') ?>" maxlength="10" style="font-family:monospace;text-transform:uppercase;"
                oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9\-]/g,'');updateQP()">
        </div>
        <div>
            <label class="form-label">Last used quote seq #</label>
            <div style="display:flex;align-items:center;gap:8px;">
                <input type="number" name="quote_seq_start" class="form-control" value="<?= (int)($config['quote_seq_start']??0) ?>" min="0" max="99999" style="font-family:monospace;" oninput="updateQP()">
                <span style="font-size:12px;color:#6b7280;"> <strong id="qPrev" style="font-family:monospace;color:#7c3aed;"><?= h(($config['quote_prefix']??'QUO').'-'.date('Y').'-'.str_pad((string)(($config['quote_seq_start']??0)+1),4,'0',STR_PAD_LEFT)) ?></strong></span>
            </div>
        </div>
    </div>
    <script>
    function updateQP(){
        var p=document.querySelector('[name=quote_prefix]')?.value||'QUO';
        var s=parseInt(document.querySelector('[name=quote_seq_start]')?.value||0)+1;
        var el=document.getElementById('qPrev');
        if(el)el.textContent=p+'-'+new Date().getFullYear()+'-'+String(s).padStart(4,'0');
    }
    </script>
    <div style="margin-top:12px;display:flex;align-items:center;gap:10px;">
        <button type="button" onclick="testQuoteApi()"
            style="background:#7c3aed;color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;">
             Test Quote API
        </button>
        <span id="quoteTestResult" style="font-size:12px;font-weight:600;"></span>
    </div>
    <script>
    function testQuoteApi(){
        var el=document.getElementById('quoteTestResult');
        el.textContent='Testing...';el.style.color='#64748B';
        var xhr=new XMLHttpRequest();
        xhr.open('POST','?page=api&action=test_quote_api',true);
        xhr.setRequestHeader('Content-Type','application/json');
        xhr.onload=function(){
            try{var r=JSON.parse(xhr.responseText);
                if(r.status==='success'){el.textContent=' '+r.message;el.style.color='#059669';}
                else{el.textContent=' '+r.message;el.style.color='#DC2626';}
            }catch(e){el.textContent=' Parse error';el.style.color='#DC2626';}
        };
        xhr.onerror=function(){el.textContent=' Network error';el.style.color='#DC2626';};
        xhr.send('{}');
    }
    </script>
</div>

<!--  Finance Audit Controls  -->
<div class="st-card" style="margin-top:20px;">
    <div class="st-card-title"> Finance Audit Controls (Nightly  23:00 EAT)</div>
    <p style="font-size:0.83rem;color:#6b7280;margin:0 0 16px;">All 5 controls run nightly and send a consolidated WhatsApp report to the accountant. Set value to 0 to disable a control.</p>

    <div class="st-row cols2">
        <div>
            <label class="form-label">Cash Carry Limit ($)</label>
            <input type="number" name="advance_carry_limit" class="form-control"
                   value="<?= number_format((float)($config['advance_carry_limit'] ?? 100), 2, '.', '') ?>"
                   min="0" step="10">
            <div class="st-hint">Max cash a field agent may hold at any time (collections + advances combined). 0 = disabled. Exceeding blocks new advances.</div>
        </div>
        <div>
            <label class="form-label">Agent Float Warning Threshold ($)</label>
            <input type="number" name="agent_float_warn_threshold" class="form-control"
                   value="<?= number_format((float)($config['agent_float_warn_threshold'] ?? 50), 2, '.', '') ?>"
                   min="0" step="10">
            <div class="st-hint">Alert Rupesh when an agent's wallet float drops below this amount. Prevents Diko running out of float mid-day. 0 = disabled.</div>
        </div>
    </div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">Receipt Grace Period (hours)</label>
            <input type="number" name="advance_receipt_grace_hours" class="form-control"
                   value="<?= (int)($config['advance_receipt_grace_hours'] ?? 24) ?>"
                   min="1" max="168">
            <div class="st-hint">Approved expenses with no receipt photo are flagged after this many hours.</div>
        </div>
        <div>
            <label class="form-label">Ledger Drift Tolerance ($)</label>
            <input type="number" name="advance_drift_tolerance" class="form-control"
                   value="<?= number_format((float)($config['advance_drift_tolerance'] ?? 0.01), 4, '.', '') ?>"
                   min="0" step="0.01">
            <div class="st-hint">Alert Rupesh if advance-system math vs cb_ledger differ by more than this. Recommend $0.01.</div>
        </div>
    </div>
    <div class="st-row cols2">
        <div>
        <div>
            <label class="form-label">Reconcile Project</label>
            <select name="advance_reconcile_project" class="form-control">
                <option value="dishnet" <?= ($config['advance_reconcile_project'] ?? 'dishnet') === 'dishnet' ? 'selected' : '' ?>>dishnet</option>
                <option value="4g"      <?= ($config['advance_reconcile_project'] ?? 'dishnet') === '4g'      ? 'selected' : '' ?>>4g</option>
            </select>
        </div>
    </div>
    <div class="st-row">
        <label class="form-label">Advance Aging Limits  hours per purpose</label>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-top:6px;">
        <?php
        $defaultAging = ['fuel'=>24,'transport'=>24,'allowance'=>48,'site_work'=>48,'misc'=>48,'parts'=>72,'equipment'=>72];
        $savedAging   = is_array($config['advance_aging_hours'] ?? null)
            ? array_merge($defaultAging, $config['advance_aging_hours'])
            : $defaultAging;
        foreach ($defaultAging as $agPurpose => $agDefault):
            $agVal = (int)($savedAging[$agPurpose] ?? $agDefault);
        ?>
        <div>
            <label style="font-size:0.78rem;font-weight:600;color:#374151;display:block;margin-bottom:3px;"><?= ucfirst(str_replace('_', ' ', $agPurpose)) ?></label>
            <div style="display:flex;align-items:center;gap:5px;">
                <input type="number" name="aging_<?= $agPurpose ?>" class="form-control" value="<?= $agVal ?>" min="1" max="720" style="max-width:80px;">
                <span style="font-size:0.8rem;color:#9ca3af;">h</span>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<button type="submit" class="st-save"> Save Registration Settings</button>
</form>

<?php /*  COMMISSIONS TAB  */ ?>
<?php elseif ($stab === 'commissions'): ?>
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="action" value="save_settings">
<input type="hidden" name="wa_plugin_url"        value="<?= h($config['wa_plugin_url']??'') ?>">
<input type="hidden" name="wa_app_key"           value="<?= h($config['wa_app_key']??'') ?>">
<input type="hidden" name="wa_accounts_app_key"  value="<?= h($config['wa_accounts_app_key']??'') ?>">
<input type="hidden" name="wa_auth_key"          value="<?= h($config['wa_auth_key']??'') ?>">
<input type="hidden" name="wa_send_pdf" value="<?= ($config['wa_send_pdf'] ?? true) !== false ? '1' : '0' ?>">
<input type="hidden" name="wa_support_number"    value="<?= h($config['wa_support_number']??'') ?>">
<input type="hidden" name="wa_accounts_number"   value="<?= h($config['wa_accounts_number']??'') ?>">
<input type="hidden" name="whatsapp_admin_phone" value="<?= h($config['whatsapp_admin_phone']??'') ?>">
<input type="hidden" name="whatsapp_webhook_url" value="<?= h($config['whatsapp_webhook_url']??'') ?>">
<input type="hidden" name="fiber_install_fee"    value="<?= h((string)($config['fiber_install_fee']??100)) ?>">
<input type="hidden" name="commission_rate"      value="<?= h((string)($config['commission_rate']??5)) ?>">

<div class="st-card">
    <div class="st-card-title"> Commission Rates</div>
    <div class="st-row cols3">
        <div>
            <label class="form-label"> Starlink (%)</label>
            <input type="number" name="starlink_commission_rate" class="form-control" value="<?= h((string)($config['starlink_commission_rate']??$config['commission_rate']??5)) ?>" min="0" max="100" step="0.5">
        </div>
        <div>
            <label class="form-label"> Fiber (%)</label>
            <input type="number" name="fiber_commission_rate" class="form-control" value="<?= h((string)($config['fiber_commission_rate']??$config['commission_rate']??5)) ?>" min="0" max="100" step="0.5">
        </div>
        <div>
            <label class="form-label"> LTE (%)</label>
            <input type="number" name="lte_commission_rate" class="form-control" value="<?= h((string)($config['lte_commission_rate']??$config['commission_rate']??5)) ?>" min="0" max="100" step="0.5">
        </div>
    </div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">Monthly Collection Target ($)</label>
            <input type="number" name="default_target" class="form-control" value="<?= h((string)($config['retailer_targets']['default']??0)) ?>" min="0" step="100">
            <div class="st-hint">Shown on retailer dashboard as target</div>
        </div>
        <div style="padding-top:30px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px;">
                <input type="checkbox" name="commission_on_collection" <?= !empty($config['commission_on_collection'])?'checked':'' ?>>
                <span style="font-size:13px;font-weight:600;">Commission on Collections</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="commission_on_kyc" <?= !empty($config['commission_on_kyc'])?'checked':'' ?>>
                <span style="font-size:13px;font-weight:600;">Commission on KYC Submissions</span>
            </label>
        </div>
    </div>
</div>

<button type="submit" class="st-save"> Save Commission Settings</button>
</form>

<?php /*  SYSTEM TAB  */ ?>
<?php elseif ($stab === 'system'): ?>

<?php
//  Lead archive stats for the button 
$_allLeadsForArchive = $store->load('leads.json') ?? [];
$_archiveToday = date('Y-m-d');
$_archivableCount = count(array_filter($_allLeadsForArchive, function($l) use ($_archiveToday) {
    return empty($l['archived'])
        && substr($l['created_at'] ?? '9999', 0, 10) < $_archiveToday;
}));
$_alreadyArchived = count(array_filter($_allLeadsForArchive, fn($l) => !empty($l['archived'])));
?>

<!--  Lead Assignees  -->
<?php
$_allRetailersA = $store->load('retailers.json') ?? [];
$_salesRolesA   = ['sales','field_agent','sales_staff'];
$_salesRetsA    = array_values(array_filter($_allRetailersA, function($r) use ($_salesRolesA) {
    return !empty($r['is_active']) && in_array($r['role'] ?? '', $_salesRolesA, true);
}));
$_currentIds    = array_filter(array_map('intval', explode(',', $config['lead_assignee_ids'] ?? '')));
?>
<div class="st-card" style="border-left:4px solid #16a34a;">
    <div class="st-card-title"> Lead Assignees  Who Receives New Leads</div>
    <p style="font-size:13px;color:#475569;margin:0 0 14px;">
        Choose which agents get auto-assigned leads from WhatsApp marketing and manual conversion.
        Leave none selected to use <strong>all active sales agents</strong>.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
    <?php foreach ($_salesRetsA as $_sr):
        $_sel = !empty($_currentIds) && in_array((int)$_sr['id'], $_currentIds, true); ?>
        <label style="display:flex;align-items:center;gap:8px;background:<?= $_sel?'#dcfce7':'#f1f5f9' ?>;border:2px solid <?= $_sel?'#16a34a':'#e2e8f0' ?>;border-radius:10px;padding:9px 16px;cursor:pointer;font-size:13px;font-weight:<?= $_sel?'800':'600' ?>;">
            <input type="checkbox" name="la_cb[]" value="<?= (int)$_sr['id'] ?>"
                   <?= $_sel?'checked':'' ?> onchange="laUpdate()"
                   style="width:16px;height:16px;cursor:pointer;accent-color:#16a34a;">
            <?= htmlspecialchars($_sr['name'] ?? '') ?>
            <span style="font-size:10px;color:#64748b;font-weight:400;">(<?= htmlspecialchars(ucfirst($_sr['role']??'')) ?>)</span>
        </label>
    <?php endforeach; ?>
    <?php if (empty($_salesRetsA)): ?>
        <span style="color:#94a3b8;font-size:13px;">No active sales agents found.</span>
    <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
        <button onclick="laSave()" style="background:#16a34a;color:#fff;border:none;border-radius:10px;padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;"> Save</button>
        <span id="laMsg" style="font-size:12px;display:none;"></span>
        <span id="laCnt" style="font-size:12px;color:#94a3b8;"><?= empty($_currentIds)?'All agents':count($_currentIds).' selected' ?></span>
    </div>
</div>
<script>
function laUpdate(){
    var n=document.querySelectorAll('input[name="la_cb[]"]:checked').length;
    document.getElementById('laCnt').textContent=n===0?'All agents (everyone)':n+' selected';
    document.querySelectorAll('input[name="la_cb[]"]').forEach(function(cb){
        var l=cb.closest('label');
        l.style.background=cb.checked?'#dcfce7':'#f1f5f9';
        l.style.borderColor=cb.checked?'#16a34a':'#e2e8f0';
        l.style.fontWeight=cb.checked?'800':'600';
    });
}
function laSave(){
    var ids=Array.from(document.querySelectorAll('input[name="la_cb[]"]:checked')).map(function(c){return c.value;});
    var m=document.getElementById('laMsg');
    fetch('?page=api&action=save_lead_assignees',{
        credentials:'same-origin',method:'POST',
        headers:{'Content-Type':'application/json','Authorization':'Bearer <?= htmlspecialchars($retailer['api_token']??'') ?>'},
        body:JSON.stringify({ids:ids})
    }).then(function(r){return r.json();}).then(function(d){
        m.style.display='inline';
        if(d.status==='success'){m.style.color='#16a34a';m.textContent=' Saved! '+(ids.length===0?'All agents will receive leads.':ids.length+' agents set.');}
        else{m.style.color='#dc2626';m.textContent=' '+(d.message||'Error');}
        setTimeout(function(){m.style.display='none';},3000);
    }).catch(function(){m.style.display='inline';m.style.color='#dc2626';m.textContent=' Network error';});
}
</script>

<!--  Lead Archive  clean slate  -->
<div class="st-card" style="border-left:4px solid #D41C1C;">
    <div class="st-card-title"> Lead Archive  Fresh Start</div>
    <p style="font-size:13px;color:#475569;margin:0 0 14px;">
        Archive all leads created <strong>before today</strong> (<?= $_archiveToday ?>).
        Archived leads are hidden from agents permanently and only visible to admin.
        Your data is safe  nothing is deleted.
    </p>

    <?php if ($_archivableCount > 0): ?>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;gap:12px;">
        <span style="font-size:28px;"></span>
        <div>
            <div style="font-size:16px;font-weight:800;color:#dc2626;"><?= number_format($_archivableCount) ?> leads will be archived</div>
            <div style="font-size:12px;color:#991b1b;margin-top:2px;">All leads created before <?= $_archiveToday ?>  Agents will see a clean empty queue</div>
        </div>
    </div>
    <button onclick="confirmArchiveLeads(<?= $_archivableCount ?>)"
            style="background:#dc2626;color:#fff;border:none;border-radius:10px;padding:12px 24px;font-size:14px;font-weight:800;cursor:pointer;">
         Archive <?= number_format($_archivableCount) ?> Old Leads Now
    </button>
    <?php else: ?>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;font-size:13px;color:#166534;">
         No leads to archive  all existing leads are already archived or created today.
        <?php if ($_alreadyArchived > 0): ?>
        <span style="color:#64748b;"> (<?= number_format($_alreadyArchived) ?> already archived)</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($_alreadyArchived > 0): ?>
    <div style="margin-top:10px;font-size:11px;color:#94a3b8;">
        <?= number_format($_alreadyArchived) ?> leads already archived 
        <a href="?page=dashboard&tab=leads&f=archived" style="color:#D41C1C;text-decoration:none;font-weight:700;">View archived leads </a>
    </div>
    <?php endif; ?>
</div>

<script>
function confirmArchiveLeads(count) {
    if (!confirm(
        ' Archive ' + count + ' old leads?\n\n' +
        ' All leads created before today will be hidden from agents\n' +
        ' Data is NOT deleted  you can see them as admin\n' +
        ' This cannot be undone automatically\n\n' +
        'Proceed?'
    )) return;

    var btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Archiving';

    fetch('?page=api&action=bulk_archive_leads', {
        credentials: 'same-origin',
        method: 'POST',
        headers: {'Content-Type':'application/json', 'Authorization':'Bearer <?= h($retailer['api_token']??'') ?>'},
        body: JSON.stringify({before_date: '<?= $_archiveToday ?>'})
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') {
            alert(' Done! ' + (d.data?.archived ?? 0) + ' leads archived.\n\nAgents now have a clean queue.');
            location.reload();
        } else {
            alert(' Error: ' + (d.message || 'Unknown'));
            btn.disabled = false;
            btn.textContent = ' Archive Old Leads Now';
        }
    })
    .catch(e => {
        alert(' Network error: ' + e);
        btn.disabled = false;
        btn.textContent = ' Archive Old Leads Now';
    });
}
</script>
<form method="POST" style="margin-bottom:20px;">
<?= csrfField() ?>
<input type="hidden" name="action" value="save_settings">
<input type="hidden" name="wa_plugin_url"        value="<?= h($config['wa_plugin_url']??'') ?>">
<input type="hidden" name="wa_app_key"           value="<?= h($config['wa_app_key']??'') ?>">
<input type="hidden" name="wa_accounts_app_key"  value="<?= h($config['wa_accounts_app_key']??'') ?>">
<input type="hidden" name="wa_auth_key"          value="<?= h($config['wa_auth_key']??'') ?>">
<input type="hidden" name="wa_send_pdf" value="<?= ($config['wa_send_pdf'] ?? true) !== false ? '1' : '0' ?>">
<input type="hidden" name="wa_support_number"    value="<?= h($config['wa_support_number']??'') ?>">
<input type="hidden" name="wa_accounts_number"   value="<?= h($config['wa_accounts_number']??'') ?>">
<input type="hidden" name="whatsapp_admin_phone" value="<?= h($config['whatsapp_admin_phone']??'') ?>">
<input type="hidden" name="whatsapp_webhook_url" value="<?= h($config['whatsapp_webhook_url']??'') ?>">
<input type="hidden" name="fiber_install_fee"    value="<?= h((string)($config['fiber_install_fee']??100)) ?>">

<div class="st-card">
    <div class="st-card-title"> Cron Paths</div>
    <div class="st-row cols2">
        <div>
            <label class="form-label"> cron_lte.php Path</label>
            <input type="text" name="lte_cron_path" class="form-control" value="<?= h($config['lte_cron_path']??(__DIR__.'/cron_lte.php')) ?>">
        </div>
        <div>
            <label class="form-label"> cron_maintenance.php Path</label>
            <input type="text" name="cron_maintenance_path" class="form-control" value="<?= h($config['cron_maintenance_path']??(__DIR__.'/cron_maintenance.php')) ?>">
        </div>
    </div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">LTE Suspend Grace Period (days)</label>
            <input type="number" name="lte_suspend_grace_days" class="form-control" value="<?= h((string)($config['lte_suspend_grace_days']??0)) ?>" min="0" max="30">
            <div class="st-hint">Days after overdue before auto-suspend kicks in (0 = immediate)</div>
        </div>
    </div>
    <button type="submit" class="st-save"> Save System Settings</button>
</div>
</form>

<?php /*  CRM Payment Reconciliation card  */ ?>
<?php
$reconcileReport = null;
$reconcileReportFile = $dataDir . '/payment_reconcile_last_report.json';
if (file_exists($reconcileReportFile)) {
    $reconcileReport = json_decode(file_get_contents($reconcileReportFile), true);
}
?>
<div class="st-card" id="crm-reconcile-card">
    <div class="st-card-title"> CRM Payment Reconciliation
        <span style="font-size:11px;font-weight:400;color:#6b7280;margin-left:8px;">Catches payments entered directly in UCRM</span>
    </div>

    <?php if ($reconcileReport): ?>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px;">
        <div style="background:#e8f5e9;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#2e7d32;"><?= (int)($reconcileReport['inserted'] ?? 0) ?></div>
            <div style="font-size:11px;color:#6b7280;font-weight:700;">Inserted</div>
        </div>
        <div style="background:#fff3e0;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#e65100;"><?= (int)($reconcileReport['unassigned'] ?? 0) ?></div>
            <div style="font-size:11px;color:#6b7280;font-weight:700;">Needs Review</div>
        </div>
        <div style="background:#f3f4f6;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#374151;"><?= (int)($reconcileReport['skipped'] ?? 0) ?></div>
            <div style="font-size:11px;color:#6b7280;font-weight:700;">Already Known</div>
        </div>
        <div style="background:#eff6ff;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#1d4ed8;"><?= (int)($reconcileReport['crm_fetched'] ?? 0) ?></div>
            <div style="font-size:11px;color:#6b7280;font-weight:700;">CRM Payments</div>
        </div>
    </div>
    <div style="font-size:11px;color:#9ca3af;margin-bottom:12px;">
        Last run: <strong><?= h($reconcileReport['ran_at'] ?? 'Never') ?></strong>
        &nbsp;&nbsp; Lookback: <strong><?= (int)($reconcileReport['lookback_days'] ?? 7) ?> days</strong>
        <?php if (($reconcileReport['unassigned'] ?? 0) > 0): ?>
        &nbsp;&nbsp; <span style="color:#e65100;font-weight:700;"> <?= (int)$reconcileReport['unassigned'] ?> payment(s) need manual agent assignment</span>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="font-size:13px;color:#9ca3af;margin-bottom:14px;">Never run  click below to scan now.</div>
    <?php endif; ?>

    <button id="btnRunReconcile" onclick="runPaymentReconcile()"
        style="background:#1d4ed8;color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">
        <span id="reconcileSpinner" style="display:none;"></span>
        <span id="reconcileBtnLabel"> Run Payment Reconciliation Now</span>
    </button>
    <span style="font-size:11px;color:#9ca3af;margin-left:10px;">Scans last 7 days  Bypasses interval guard</span>

    <!-- Diagnostic Log Panel -->
    <div id="reconcileLogWrap" style="display:none;margin-top:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
            <div style="font-size:12px;font-weight:700;color:#374151;"> Diagnostic Log</div>
            <div style="display:flex;gap:8px;">
                <button onclick="reconcileToggleView()" id="reconcileViewBtn"
                    style="font-size:11px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;padding:3px 10px;cursor:pointer;">
                    Show Full Log
                </button>
                <button onclick="document.getElementById('reconcileLogWrap').style.display='none'"
                    style="font-size:11px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;padding:3px 10px;cursor:pointer;">
                     Close
                </button>
            </div>
        </div>
        <!-- Summary view (default) -->
        <div id="reconcileSummaryView">
            <div id="reconcileSummaryStats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px;"></div>
            <div id="reconcileLinesList"
                style="background:#0f172a;border-radius:8px;padding:12px;font-family:monospace;font-size:12px;color:#e2e8f0;max-height:280px;overflow-y:auto;white-space:pre-wrap;"></div>
        </div>
        <!-- Full log view (toggled) -->
        <div id="reconcileFullView" style="display:none;">
            <textarea id="reconcileFullLog" readonly
                style="width:100%;height:320px;background:#0f172a;color:#94a3b8;border:none;border-radius:8px;padding:12px;font-family:monospace;font-size:11px;resize:vertical;"></textarea>
        </div>
    </div>
</div>

<!-- Handover reconcile moved to Cashbook tab (Accounts section) -->

<script>
var reconcileShowFull = false;

function reconcileToggleView() {
    reconcileShowFull = !reconcileShowFull;
    document.getElementById('reconcileSummaryView').style.display = reconcileShowFull ? 'none' : 'block';
    document.getElementById('reconcileFullView').style.display    = reconcileShowFull ? 'block' : 'none';
    document.getElementById('reconcileViewBtn').textContent       = reconcileShowFull ? 'Show Summary' : 'Show Full Log';
}

function runPaymentReconcile() {
    var btn     = document.getElementById('btnRunReconcile');
    var spinner = document.getElementById('reconcileSpinner');
    var label   = document.getElementById('reconcileBtnLabel');
    var wrap    = document.getElementById('reconcileLogWrap');
    var lines   = document.getElementById('reconcileLinesList');
    var fullLog = document.getElementById('reconcileFullLog');
    var stats   = document.getElementById('reconcileSummaryStats');

    btn.disabled = true;
    spinner.style.display = 'inline';
    label.textContent = 'Running';
    wrap.style.display = 'block';
    lines.textContent = ' Connecting to UCRM and fetching payments';
    stats.innerHTML = '';
    // Reset to summary view
    reconcileShowFull = false;
    document.getElementById('reconcileSummaryView').style.display = 'block';
    document.getElementById('reconcileFullView').style.display    = 'none';
    document.getElementById('reconcileViewBtn').textContent       = 'Show Full Log';

    var TK = <?= json_encode($retailer['api_token'] ?? '') ?>;

    fetch('?page=api&action=run_payment_reconcile', {
          credentials:'same-origin',
          method: 'POST',
        headers: { 'Authorization': 'Bearer ' + TK }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        d = d&&d.data?d.data:d;
        //  Full log 
        fullLog.value = d.output || '(no output)';

        //  Summary lines (reconcile-specific) 
        var rlines = d.reconcile_lines || [];
        if (rlines.length === 0) {
            lines.textContent = d.success
                ? ' Ran successfully  no new CRM-direct payments found.'
                : ' Script ran with errors. Switch to Full Log view for details.';
        } else {
            lines.textContent = rlines.join('\n');
        }

        //  Stats tiles 
        var rpt = d.report;
        if (rpt) {
            var tiles = [
                { val: rpt.inserted   || 0, label: 'Inserted',    bg: '#dcfce7', col: '#15803d' },
                { val: rpt.unassigned || 0, label: 'Needs Review', bg: '#fff7ed', col: '#c2410c' },
                { val: rpt.skipped    || 0, label: 'Already Known',bg: '#f3f4f6', col: '#374151' },
                { val: rpt.crm_fetched|| 0, label: 'CRM Payments', bg: '#eff6ff', col: '#1d4ed8' },
            ];
            stats.innerHTML = tiles.map(function(t) {
                return '<div style="background:'+t.bg+';border-radius:8px;padding:10px;text-align:center;">'
                     + '<div style="font-size:22px;font-weight:800;color:'+t.col+';">'+t.val+'</div>'
                     + '<div style="font-size:11px;color:#6b7280;font-weight:700;">'+t.label+'</div>'
                     + '</div>';
            }).join('');
        }

        //  Status bar 
        label.textContent = d.success ? ' Done  Reconciliation Complete' : ' Completed with errors';
        label.style.color = d.success ? '#10b981' : '#ef4444';
    })
    .catch(function(err) {
        lines.textContent = ' Request failed: ' + err.toString();
        label.textContent = ' Failed';
        label.style.color = '#ef4444';
    })
    .finally(function() {
        btn.disabled = false;
        spinner.style.display = 'none';
    });
}
</script>

<?php /* WhatsApp card - link to dedicated tab */ ?>
<div class="st-card">
    <div class="st-card-title"> WhatsApp Notifications</div>
    <?php if (!empty($config['wa_app_key'])): ?>
    <div class="st-badge ok" style="margin-bottom:10px;"> Configured</div>
    <?php else: ?>
    <div class="st-badge warn" style="margin-bottom:10px;"> Not configured</div>
    <?php endif; ?>
    <div style="font-size:13px;color:#6b7280;margin-bottom:12px;">WhatsApp settings are managed in the dedicated WhatsApp tab.</div>
    <a href="?page=dashboard&tab=whatsapp" class="btn btn-success" style="background:#2e7d32;border:none;font-weight:700;padding:8px 18px;">
        <i class="bi bi-whatsapp"></i> Open WhatsApp Settings
    </a>
</div>

<?php /* Email card — v4.21.13 — mirrors Starlink Finance UX (UCRM toggle + SMTP fallback) */ ?>
<?php
// Pre-compute UCRM API connection status for the indicator badge
$_emUcrmJson = $dataDir . '/ucrm.json';
if (!file_exists($_emUcrmJson)) $_emUcrmJson = dirname($dataDir) . '/ucrm.json';
$_emUcrm = file_exists($_emUcrmJson) ? (json_decode((string)@file_get_contents($_emUcrmJson), true) ?: []) : [];
$_emApiUrl = trim($_emUcrm['ucrmLocalUrl'] ?? $_emUcrm['ucrmPublicUrl'] ?? '');
$_emAppKey = trim($_emUcrm['pluginAppKey'] ?? '');
$_emUcrmConnected = ($_emApiUrl !== '' && $_emAppKey !== '');
?>
<div class="st-card">
    <div class="st-card-title"> Email Notifications</div>
    <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_email_settings">

    <div class="st-row cols1">
        <div>
            <label class="form-label">Daily Summary Recipients</label>
            <input type="text" name="email_recipients" class="form-control" value="<?= h($eSettings['recipients']??'') ?>" placeholder="admin@dishnetafrica.com, ops@...">
            <div class="st-hint">Comma-separated email addresses</div>
        </div>
    </div>

    <!-- UCRM Mailer toggle (recommended path) -->
    <div style="border:1px solid #10B981;border-radius:8px;padding:14px;margin-top:12px;background:#F0FDF4;">
        <label style="font-size:11px;font-weight:700;color:#059669;letter-spacing:0.04em;">
            📨 USE UCRM MAILER
            <span style="background:#10B981;color:#fff;font-size:9px;padding:2px 7px;border-radius:4px;margin-left:6px;font-weight:700;">RECOMMENDED</span>
        </label>
        <div style="font-size:12px;color:#666;margin:4px 0 10px;">
            Reads SMTP settings from UCRM (System &rarr; Mailer). No extra config needed &mdash; uses the same email setup your CRM already has.
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:600;">
            <input type="checkbox" name="use_ucrm_email" value="1" <?= !empty($eSettings['use_ucrm_email']) ? 'checked' : '' ?> onchange="document.getElementById('emailSmtpBlock').style.display = this.checked ? 'none' : 'block';">
            Send emails via UCRM's configured mailer
        </label>
        <?php if ($_emUcrmConnected): ?>
        <div style="font-size:11px;color:#059669;margin-top:6px;font-weight:600;">✅ UCRM API connected &mdash; will read mailer settings from UCRM</div>
        <?php else: ?>
        <div style="font-size:11px;color:#dc2626;margin-top:6px;font-weight:600;">❌ UCRM API not configured &mdash; check plugin config</div>
        <?php endif; ?>
    </div>

    <!-- SMTP fallback (used when UCRM toggle is off OR when UCRM API call fails) -->
    <div id="emailSmtpBlock" style="border:1px solid #fbbf24;border-radius:8px;padding:14px;margin-top:12px;background:#FFFBEB;<?= !empty($eSettings['use_ucrm_email']) ? 'display:none;' : '' ?>">
        <label style="font-size:11px;font-weight:700;color:#92400e;letter-spacing:0.04em;">
            📡 SMTP SETTINGS
            <span style="background:#f59e0b;color:#fff;font-size:9px;padding:2px 7px;border-radius:4px;margin-left:6px;font-weight:700;">FALLBACK</span>
        </label>
        <div style="font-size:12px;color:#666;margin:4px 0 10px;">
            Used if UCRM email is disabled or fails. PHP's built-in mail() doesn't work in Docker.
        </div>

        <div class="st-row cols1">
            <div>
                <label class="form-label" style="font-size:11px;">SMTP Preset</label>
                <select class="form-control" name="smtp_preset" onchange="dnSmtpPreset(this.value)">
                    <option value="">Custom SMTP</option>
                    <option value="gmail"   <?= ($eSettings['smtp_preset'] ?? '') === 'gmail'   ? 'selected' : '' ?>>Gmail / Google Workspace</option>
                    <option value="outlook" <?= ($eSettings['smtp_preset'] ?? '') === 'outlook' ? 'selected' : '' ?>>Outlook / Office 365</option>
                    <option value="zoho"    <?= ($eSettings['smtp_preset'] ?? '') === 'zoho'    ? 'selected' : '' ?>>Zoho Mail</option>
                </select>
            </div>
        </div>

        <div class="st-row cols2">
            <div>
                <label class="form-label">SMTP Host</label>
                <input type="text" name="smtp_host" id="dnSmtpHost" class="form-control" value="<?= h($eSettings['smtp_host']??'') ?>" placeholder="smtp.office365.com">
            </div>
            <div>
                <label class="form-label">Port</label>
                <input type="number" name="smtp_port" id="dnSmtpPort" class="form-control" value="<?= (int)($eSettings['smtp_port']??587) ?>">
            </div>
        </div>

        <div class="st-row cols2">
            <div>
                <label class="form-label">Encryption</label>
                <select name="smtp_enc" id="dnSmtpEnc" class="form-control">
                    <option value="tls" <?= ($eSettings['smtp_enc']??'tls')==='tls'?'selected':'' ?>>TLS</option>
                    <option value="ssl" <?= ($eSettings['smtp_enc']??'')==='ssl'?'selected':'' ?>>SSL</option>
                    <option value=""    <?= (($eSettings['smtp_enc']??'')==='none'||($eSettings['smtp_enc']??'')==='') ? 'selected' : '' ?>>None</option>
                </select>
            </div>
            <div>
                <label class="form-label">From Address</label>
                <input type="text" name="smtp_from" class="form-control" value="<?= h($eSettings['smtp_from']??'') ?>" placeholder="noreply@dishnetafrica.com">
            </div>
        </div>

        <div class="st-row cols2">
            <div>
                <label class="form-label">SMTP Username</label>
                <input type="text" name="smtp_user" class="form-control" value="<?= h($eSettings['smtp_user']??'') ?>" placeholder="accounts@dishnetafrica.com">
            </div>
            <div>
                <label class="form-label">SMTP Password <small class="text-muted">(leave blank to keep)</small></label>
                <input type="password" name="smtp_pass" class="form-control" value="" placeholder="App password (Outlook 2FA / Gmail App Password)">
            </div>
        </div>
    </div>

    <button type="submit" class="st-save" style="margin-top:14px;"> Save Email Settings</button>
    </form>

    <script>
    function dnSmtpPreset(p) {
        var presets = {
            'gmail':   { host: 'smtp.gmail.com',     port: 587, enc: 'tls' },
            'outlook': { host: 'smtp.office365.com', port: 587, enc: 'tls' },
            'zoho':    { host: 'smtp.zoho.com',      port: 587, enc: 'tls' }
        };
        if (!presets[p]) return;
        document.getElementById('dnSmtpHost').value = presets[p].host;
        document.getElementById('dnSmtpPort').value = presets[p].port;
        document.getElementById('dnSmtpEnc').value  = presets[p].enc;
    }
    </script>
</div>

<?php /* Security panel */ ?>
<div class="st-card">
    <div class="st-card-title"> Security  Login Rate Limiter</div>
    <?php
    $lockedAccounts = $limiter->getLockedAccounts();
    $attemptsData   = $store->load('login_attempts.json') ?? [];
    ?>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px;">
        <div style="background:#e8f5e9;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#2e7d32;"><?= count($lockedAccounts) ?></div>
            <div style="font-size:11px;color:#6b7280;font-weight:700;">Locked Now</div>
        </div>
        <div style="background:#fff3e0;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#e65100;"><?= count(array_filter($attemptsData, fn($e)=>!empty($e['count'])&&empty($e['locked_until']))) ?></div>
            <div style="font-size:11px;color:#6b7280;font-weight:700;">Active Fail Windows</div>
        </div>
        <div style="background:#f3e5f5;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#7b1fa2;"><?= count($attemptsData) ?></div>
            <div style="font-size:11px;color:#6b7280;font-weight:700;">Tracked IPs</div>
        </div>
    </div>
    <?php if (!empty($lockedAccounts)): ?>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:8px 10px;font-size:12px;">
        <strong style="color:#dc2626;"> Locked (<?= count($lockedAccounts) ?>):</strong>
        <?php foreach($lockedAccounts as $la): ?>
        <span style="font-family:monospace;margin-left:8px;"><?= h($la['key_prefix']) ?> (<?= $la['retry_in_minutes'] ?>min)</span>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="color:#2e7d32;font-size:12px;"> No accounts locked. Rules: 5 failures / 10 min  15 min lockout.</div>
    <?php endif; ?>
</div>

<?php /* SQLite Migration */ ?>
<div class="st-card">
    <div class="st-card-title"> Storage  SQLite Backend</div>
    <?php
    $sqliteReady  = file_exists($dataDir . '/plugin.sqlite3');
    $jsonFiles    = glob($dataDir . '/*.json') ?: [];
    $jsonMigrated = glob($dataDir . '/*.json.migrated') ?: [];
    $sqliteLog    = file_exists($dataDir . '/sqlite_migration.log') ? file_get_contents($dataDir . '/sqlite_migration.log') : null;
    ?>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px;">
        <div style="background:<?= $sqliteReady?'#e8f5e9':'#fff3e0' ?>;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:14px;font-weight:800;color:<?= $sqliteReady?'#2e7d32':'#e65100' ?>;"><?= $sqliteReady?' SQLite':' JSON' ?></div>
            <div style="font-size:11px;color:#6b7280;">Backend</div>
        </div>
        <div style="background:#e3f2fd;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#1565c0;"><?= count($jsonFiles) ?></div>
            <div style="font-size:11px;color:#6b7280;">JSON Active</div>
        </div>
        <div style="background:#f3e5f5;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:22px;font-weight:800;color:#7b1fa2;"><?= count($jsonMigrated) ?></div>
            <div style="font-size:11px;color:#6b7280;">Migrated</div>
        </div>
    </div>
    <div style="font-size:12px;color:#374151;line-height:1.6;">To switch to SQLite: in <code>public.php</code>, <code>api/index.php</code>, and <code>cron_sync.php</code>, swap <code>new JsonStore</code>  <code>SqliteStore::create</code>. First request auto-migrates all data.</div>
    <?php if ($sqliteLog): ?>
    <details style="margin-top:8px;"><summary style="cursor:pointer;font-size:12px;font-weight:700;">Migration log </summary>
    <pre style="font-size:11px;background:#f8fafc;padding:8px;border-radius:6px;margin-top:4px;overflow-x:auto;"><?= h($sqliteLog) ?></pre></details>
    <?php endif; ?>
</div>

</div>

<?php elseif ($stab === 'webhook'): ?>
<?php
// Fetch webhook status
$webhooks = $crm->getWebhooks();
$ourWebhook = null;
$otherWebhooks = [];
foreach ($webhooks as $wh) {
    if (strpos($wh['url'] ?? '', 'dishnet-hybrid-telecom') !== false) {
        $ourWebhook = $wh;
    } else {
        $otherWebhooks[] = $wh;
    }
}

// Get expected URL
$ucrmConfig = [];
foreach ([__DIR__ . '/ucrm.json', __DIR__ . '/data/ucrm.json', $dataDir . '/ucrm.json'] as $path) {
    if (file_exists($path)) {
        $c = json_decode(file_get_contents($path), true);
        if (is_array($c) && !empty($c)) { $ucrmConfig = $c; break; }
    }
}
$expectedUrl = rtrim($ucrmConfig['ucrmPublicUrl'] ?? '', '/') . '/_plugins/dishnet-hybrid-telecom/public.php?page=webhook';
$urlCorrect = $ourWebhook && ($ourWebhook['url'] ?? '') === $expectedUrl;

// Recent webhook log
$webhookLogFile = $dataDir . '/webhook_log.json';
$webhookLog = file_exists($webhookLogFile) ? json_decode(file_get_contents($webhookLogFile), true) : [];
$webhookLog = is_array($webhookLog) ? array_slice($webhookLog, 0, 15) : [];

// Setup log
$setupLogFile = $dataDir . '/webhook_setup_log.json';
$setupLog = file_exists($setupLogFile) ? json_decode(file_get_contents($setupLogFile), true) : null;
?>

<div class="st-card">
    <div class="st-card-title"> UCRM Webhook Status</div>
    
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
        <div style="background:<?= $ourWebhook ? '#dcfce7' : '#fee2e2' ?>;border-radius:10px;padding:14px;text-align:center;">
            <div style="font-size:24px;"><?= $ourWebhook ? '' : '' ?></div>
            <div style="font-size:12px;font-weight:700;color:<?= $ourWebhook ? '#166534' : '#991b1b' ?>;">
                <?= $ourWebhook ? 'Configured' : 'Not Found' ?>
            </div>
        </div>
        <div style="background:<?= ($ourWebhook['isActive'] ?? false) ? '#dcfce7' : '#fef3c7' ?>;border-radius:10px;padding:14px;text-align:center;">
            <div style="font-size:24px;"><?= ($ourWebhook['isActive'] ?? false) ? '' : '' ?></div>
            <div style="font-size:12px;font-weight:700;color:<?= ($ourWebhook['isActive'] ?? false) ? '#166534' : '#92400e' ?>;">
                <?= ($ourWebhook['isActive'] ?? false) ? 'Active' : 'Inactive' ?>
            </div>
        </div>
        <div style="background:<?= $urlCorrect ? '#dcfce7' : '#fee2e2' ?>;border-radius:10px;padding:14px;text-align:center;">
            <div style="font-size:24px;"><?= $urlCorrect ? '' : '' ?></div>
            <div style="font-size:12px;font-weight:700;color:<?= $urlCorrect ? '#166534' : '#991b1b' ?>;">
                <?= $urlCorrect ? 'URL Correct' : 'URL Wrong' ?>
            </div>
        </div>
    </div>

    <?php if ($ourWebhook): ?>
    <div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:12px;">
        <div style="font-size:11px;color:#6b7280;margin-bottom:4px;">Webhook ID: <strong><?= $ourWebhook['id'] ?? '?' ?></strong></div>
        <div style="font-size:12px;font-family:monospace;word-break:break-all;color:#374151;"><?= h($ourWebhook['url'] ?? '') ?></div>
        <?php if (!$urlCorrect): ?>
        <div style="margin-top:8px;padding:8px;background:#fef2f2;border-radius:6px;font-size:11px;color:#991b1b;">
            <strong>Expected:</strong> <?= h($expectedUrl) ?>
        </div>
        <?php endif; ?>
        <div style="margin-top:8px;font-size:11px;color:#6b7280;">
            Events: <?php 
            $events = $ourWebhook['eventTypes'] ?? [];
            echo empty($events) ? '<em>All events</em>' : h(implode(', ', $events));
            ?>
        </div>
    </div>
    <?php else: ?>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px;margin-bottom:12px;">
        <div style="font-size:13px;color:#991b1b;font-weight:600;"> No webhook configured for this plugin</div>
        <div style="font-size:12px;color:#7f1d1d;margin-top:4px;">Click "Setup Webhook" below to automatically create it.</div>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <form method="POST" action="?page=api&action=webhook_setup" id="webhookSetupForm" style="display:inline;">
            <button type="button" onclick="setupWebhook()" class="st-save" style="background:#2563eb;">
                 <?= $ourWebhook ? 'Re-Setup Webhook' : 'Setup Webhook' ?>
            </button>
        </form>
        <?php if ($ourWebhook): ?>
        <a href="<?= h(rtrim($ucrmConfig['ucrmPublicUrl'] ?? '', '/')) ?>/system/webhooks/endpoints/<?= $ourWebhook['id'] ?>" 
           target="_blank" class="st-save" style="background:#6b7280;text-decoration:none;">
             View in UCRM
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="st-card">
    <div class="st-card-title"> Webhook Secret</div>
    <div style="font-size:12px;color:#6b7280;margin-bottom:10px;">
        This secret is used to verify webhook requests from UCRM. It's auto-generated on first setup.
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <input type="text" id="webhookSecretDisplay" value="<?= h($config['webhook_secret'] ?? '') ?>" 
               readonly class="form-control" style="font-family:monospace;flex:1;">
        <button type="button" onclick="copySecret()" class="btn btn-outline-secondary" style="white-space:nowrap;">
             Copy
        </button>
    </div>
    <?php if (empty($config['webhook_secret'])): ?>
    <div style="margin-top:8px;font-size:11px;color:#dc2626;"> No secret set. Click "Setup Webhook" to generate one.</div>
    <?php endif; ?>
</div>

<div class="st-card">
    <div class="st-card-title"> Recent Webhook Events (Last 15)</div>
    <?php if (empty($webhookLog)): ?>
    <div style="color:#6b7280;font-size:13px;padding:20px;text-align:center;">
        No webhook events received yet. Try creating an invoice or payment in UCRM.
    </div>
    <?php else: ?>
    <div style="max-height:400px;overflow-y:auto;">
        <table style="width:100%;font-size:12px;border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc;position:sticky;top:0;">
                    <th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Time</th>
                    <th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Event</th>
                    <th style="padding:8px;text-align:left;border-bottom:1px solid #e2e8f0;">Message</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($webhookLog as $entry): ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:8px;color:#6b7280;white-space:nowrap;"><?= h($entry['received_at'] ?? '') ?></td>
                    <td style="padding:8px;">
                        <span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:4px;font-weight:600;font-size:11px;">
                            <?= h($entry['event'] ?? '?') ?>
                        </span>
                    </td>
                    <td style="padding:8px;color:#374151;"><?= h(substr($entry['message'] ?? '', 0, 80)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($setupLog): ?>
<div class="st-card">
    <div class="st-card-title"> Last Setup Attempt</div>
    <pre style="font-size:11px;background:#f8fafc;padding:10px;border-radius:6px;overflow-x:auto;margin:0;"><?= h(json_encode($setupLog, JSON_PRETTY_PRINT)) ?></pre>
</div>
<?php endif; ?>

<script>
function setupWebhook() {
    if (!confirm('This will create or update the UCRM webhook for this plugin. Continue?')) return;
    
    fetch('?page=api&action=webhook_setup', {
          credentials:'same-origin',
          method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') {
            alert(' ' + d.message + '\n\nWebhook URL: ' + (d.data?.url || 'N/A'));
            location.reload();
        } else {
            alert(' Setup failed: ' + d.message);
        }
    })
    .catch(e => alert(' Error: ' + e));
}

function copySecret() {
    var input = document.getElementById('webhookSecretDisplay');
    input.select();
    document.execCommand('copy');
    alert('Secret copied to clipboard!');
}
</script>

<?php elseif ($stab === '4g'): ?>
<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="action" value="save_settings">
<div class="st-card">
    <div class="st-card-title">4G BlueCard Feed Settings</div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">LTE Feed URL</label>
            <input type="text" name="lte_feed_url" class="form-control" value="<?= h($config['lte_feed_url'] ?? 'https://162.241.149.144/lte_feed.php') ?>" placeholder="https://162.241.149.144/lte_feed.php">
            <div class="st-hint">URL of the lte_feed.php endpoint on the BlueCard server</div>
        </div>
        <div>
            <label class="form-label">LTE Feed Token</label>
            <input type="text" name="lte_feed_token" class="form-control" value="<?= h($config['lte_feed_token'] ?? '') ?>" placeholder="dN4g-LtEfEeD-2026-sEcReT">
            <div class="st-hint">Shared secret token for lte_feed.php authentication</div>
        </div>
    </div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">Magma AGW URL</label>
            <input type="text" name="magma_url" class="form-control" value="<?= h($config['magma_url'] ?? '') ?>" placeholder="https://your-magma-host:9443">
            <div class="st-hint">Magma Access Gateway REST API base URL</div>
        </div>
        <div>
            <label class="form-label">Magma API Token</label>
            <input type="text" name="magma_token" class="form-control" value="<?= h($config['magma_token'] ?? '') ?>" placeholder="Bearer token">
        </div>
    </div>
    <div class="st-row cols2">
        <div>
            <label class="form-label">Auto-Suspend Grace Days</label>
            <input type="number" name="lte_suspend_grace_days" class="form-control" value="<?= (int)($config['lte_suspend_grace_days'] ?? 0) ?>" min="0" max="30">
            <div class="st-hint">Days after expiry before auto-suspend (0 = immediate)</div>
        </div>
        <div>
            <label class="form-label">LTE Commission Rate (%)</label>
            <input type="number" name="lte_commission_rate" class="form-control" value="<?= h((string)($config['lte_commission_rate'] ?? $config['commission_rate'] ?? 5)) ?>" min="0" max="100" step="0.5">
        </div>
    </div>
    <div style="margin-top:10px;">
        <button type="button" onclick="testBcConn()" class="btn btn-outline-primary btn-sm">Test BlueCard Connection</button>
        <span id="bc-test-result" style="margin-left:10px;font-size:12px;font-weight:600;"></span>
    </div>
    <script>
    function testBcConn() {
        var el = document.getElementById('bc-test-result');
        el.textContent = 'Testing...'; el.style.color = '#64748b';
        fetch('?page=api&action=bc_test_config', {
            method: 'POST',
            headers: {'Content-Type':'application/json','Authorization':'Bearer <?= h($retailer["api_token"] ?? "") ?>'},
            body: JSON.stringify({feed_url: document.querySelector('[name=lte_feed_url]').value, feed_token: document.querySelector('[name=lte_feed_token]').value})
        }).then(function(r){return r.json();}).then(function(d){
            var ok = d.data && d.data.ok;
            el.textContent = ok ? 'Connected (DB: '+(d.data.db||'?')+')' : 'FAILED: '+(d.data && d.data.error ? d.data.error : 'Error');
            el.style.color = ok ? '#16a34a' : '#dc2626';
        }).catch(function(){ el.textContent = 'Network error'; el.style.color = '#dc2626'; });
    }
    </script>
</div>
<button type="submit" class="btn btn-primary" style="margin-top:12px;">Save 4G Settings</button>
</form>

<?php elseif ($stab === 'health'): ?>
<?php
//  Collect health data 
$pdo = $store->getPdo();
$now = time();

// 1. Cron schedule  last run times
$schedule  = $store->load('master_schedule.json') ?? [];
$cronJobs  = [
    'lead_alerts'  => ['name' => 'Lead Alert Engine',         'interval' => 300,   'icon' => ''],
    'evo_sync'     => ['name' => 'Evolution API Sync',        'interval' => 300,   'icon' => ''],
    'wa_bot'       => ['name' => 'WhatsApp Bot',              'interval' => 300,   'icon' => ''],
    'splynx_sync'  => ['name' => 'Splynx/Fiber Sync',        'interval' => 300,   'icon' => ''],
    'leads'        => ['name' => 'Lead Auto-Assign',          'interval' => 14400, 'icon' => ''],
    'crm_sync'     => ['name' => 'CRM Sync',                  'interval' => 60,    'icon' => ''],
];

// 2. Lead system stats
$allLeads     = $store->load('leads.json') ?? [];
$activeLeads  = array_filter($allLeads, fn($l) => empty($l['archived']) && !in_array($l['status'] ?? '', ['won','lost']));
$leadsTotal   = count($activeLeads);
$leadsNoPhone = count(array_filter($activeLeads, fn($l) => empty($l['phone'])));
$leadsOverdue = count(array_filter($activeLeads, function($l) {
    $ts = !empty($l['assigned_at']) ? strtotime($l['assigned_at']) : 0;
    $called = !empty($l['last_call_at']) && substr($l['last_call_at'],0,10) === date('Y-m-d');
    return $ts && !$called && (time()-$ts) > 3600;
}));
$leadsNew24h  = count(array_filter($allLeads, fn($l) => empty($l['archived']) && substr($l['created_at']??'',0,10) >= date('Y-m-d', strtotime('-1 day'))));
$leadsAutoWA  = count(array_filter($allLeads, fn($l) => ($l['source']??'') === 'whatsapp_marketing'));

// 3. Fiber install log stats (last 7 days)
$fiberStats = ['jobs'=>0,'delivery_sent'=>0,'ticket_closed'=>0,'invoiced'=>0,'no_kyc'=>0];
try {
    $stmt = $pdo->query("SELECT * FROM fiber_collection_jobs WHERE created_at >= datetime('now','-7 days') ORDER BY created_at DESC");
    $fiberJobs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $fiberStats['jobs']           = count($fiberJobs);
    $fiberStats['delivery_sent']  = count(array_filter($fiberJobs, fn($j) => !empty($j['delivery_note_sent'])));
    $fiberStats['ticket_closed']  = count(array_filter($fiberJobs, fn($j) => !empty($j['ticket_closed'])));
    $fiberStats['invoiced']       = count(array_filter($fiberJobs, fn($j) => ($j['status']??'') === 'invoiced'));
    $fiberStats['no_kyc']         = count(array_filter($fiberJobs, fn($j) => empty($j['kyc_app_id']) && empty($j['delivery_note_sent'])));
} catch (\Throwable $e) {}

// 4. WA conversations stats
$waStats = ['marketing'=>0,'today'=>0,'auto_leads_24h'=>0];
try {
    $waStats['marketing']    = (int)$pdo->query("SELECT COUNT(*) FROM wa_conversations WHERE channel='marketing'")->fetchColumn();
    $waStats['today']        = (int)$pdo->query("SELECT COUNT(*) FROM wa_conversations WHERE date(last_message_at)=date('now')")->fetchColumn();
    $waStats['auto_leads_24h'] = count(array_filter($allLeads, fn($l) => ($l['source']??'')===  'whatsapp_marketing' && substr($l['created_at']??'',0,10) >= date('Y-m-d')));
} catch (\Throwable $e) {}

// 5. Auto-WA events (from notification log)
$waEvents = [];
try {
    $stmt2 = $pdo->prepare("SELECT event, COUNT(*) as cnt, MAX(sent_at) as last_sent FROM payment_notify_log WHERE event IN ('lead_outcome_auto_wa','ops_lead_assigned_immediate','ops_lead_45min_warning','ops_lead_60min_escalation','fiber_delivery_no_kyc') AND date(sent_at) >= date('now','-1 day') GROUP BY event");
    $stmt2->execute();
    foreach ($stmt2->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $waEvents[$r['event']] = $r;
    }
} catch (\Throwable $e) {}

function cronStatus(array $job, array $schedule, int $now): array {
    $key     = $job['key'] ?? '';
    $lastRun = $schedule[$key]['last_run_at'] ?? 'Never';
    $interval = $job['interval'];
    if ($lastRun === 'Never' || $lastRun === 'never') {
        return ['status'=>'warn', 'label'=>'Never ran', 'detail'=>'Cron has not executed yet'];
    }
    $age = $now - strtotime($lastRun);
    $maxAge = $interval * 3; // 3x interval = stale
    $ageLabel = $age < 120 ? $age.'s ago' : ($age < 3600 ? round($age/60).'m ago' : round($age/3600,1).'h ago');
    if ($age > $maxAge) {
        return ['status'=>'fail', 'label'=>" Stale ({$ageLabel})", 'detail'=>"Last ran: {$lastRun}"];
    }
    return ['status'=>'ok', 'label'=>" {$ageLabel}", 'detail'=>"Last ran: {$lastRun}"];
}

function hBadge(string $status, string $label): string {
    $colors = ['ok'=>['#f0fdf4','#16a34a'], 'warn'=>['#fffbeb','#d97706'], 'fail'=>['#fef2f2','#dc2626']];
    [$bg,$fg] = $colors[$status] ?? $colors['warn'];
    return "<span style=\"background:{$bg};color:{$fg};padding:2px 10px;border-radius:8px;font-size:11px;font-weight:800;\">{$label}</span>";
}
?>

<div style="font-size:18px;font-weight:900;color:#1e293b;margin-bottom:16px;"> System Health Dashboard</div>

<!--  CRON STATUS  -->
<div class="st-card">
    <div class="st-card-title"> Background Jobs (Cron)</div>
    <p style="font-size:12px;color:#64748b;margin:0 0 14px;">Each job runs on schedule via master.php. If "stale"  master cron may have stopped.</p>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
    <thead><tr style="background:#f8fafc;">
        <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#64748b;">JOB</th>
        <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#64748b;">INTERVAL</th>
        <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#64748b;">STATUS</th>
        <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#64748b;">LAST RUN</th>
    </tr></thead>
    <tbody>
    <?php foreach ($cronJobs as $key => $job):
        $job['key'] = $key;
        $cs = cronStatus($job, $schedule, $now);
    ?>
    <tr style="border-bottom:1px solid #f1f5f9;">
        <td style="padding:9px 12px;font-weight:700;"><?= $job['icon'] ?> <?= $job['name'] ?></td>
        <td style="padding:9px 12px;color:#64748b;"><?= $job['interval'] >= 3600 ? round($job['interval']/3600).'h' : round($job['interval']/60).'m' ?></td>
        <td style="padding:9px 12px;"><?= hBadge($cs['status'], $cs['label']) ?></td>
        <td style="padding:9px 12px;color:#94a3b8;font-size:11px;"><?= htmlspecialchars($cs['detail']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
</div>

<!--  LEAD SYSTEM  -->
<div class="st-card">
    <div class="st-card-title"> Lead System  Today</div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px;">
        <div style="background:#f8fafc;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:900;color:#1e293b;"><?= $leadsTotal ?></div>
            <div style="font-size:10px;color:#64748b;font-weight:700;">ACTIVE LEADS</div>
        </div>
        <div style="background:<?= $leadsOverdue > 0 ? '#fef2f2' : '#f0fdf4' ?>;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:900;color:<?= $leadsOverdue > 0 ? '#dc2626' : '#16a34a' ?>;"><?= $leadsOverdue ?></div>
            <div style="font-size:10px;color:#64748b;font-weight:700;">OVERDUE (1h+)</div>
        </div>
        <div style="background:#eff6ff;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:900;color:#1d4ed8;"><?= $leadsNew24h ?></div>
            <div style="font-size:10px;color:#64748b;font-weight:700;">NEW LAST 24H</div>
        </div>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
    <?php
    $leadChecks = [
        ['Auto-WA: new assignment', 'ops_lead_assigned_immediate', 'WA sent to agent when lead assigned'],
        ['Auto-WA: 45-min warning',  'ops_lead_45min_warning',      'Agent warned 15 min before deadline'],
        ['Auto-WA: 60-min escalation','ops_lead_60min_escalation',  'Supervisor alerted when deadline missed'],
        ['Auto-WA: call outcome',    'lead_outcome_auto_wa',        'WA sent to customer after each call'],
    ];
    foreach ($leadChecks as [$label, $event, $desc]):
        $ev = $waEvents[$event] ?? null;
        $status = $ev ? 'ok' : 'warn';
        $detail = $ev ? "Fired {$ev['cnt']}x today  Last: " . substr($ev['last_sent']??'',11,5) : 'Not fired today';
    ?>
    <tr style="border-bottom:1px solid #f1f5f9;">
        <td style="padding:8px 12px;font-weight:700;"><?= $label ?></td>
        <td style="padding:8px 12px;"><?= hBadge($status, $status==='ok'?' Active':' Pending') ?></td>
        <td style="padding:8px 12px;color:#94a3b8;font-size:11px;"><?= $detail ?></td>
    </tr>
    <?php endforeach; ?>
    <tr style="border-bottom:1px solid #f1f5f9;">
        <td style="padding:8px 12px;font-weight:700;">Auto-leads from WA marketing</td>
        <td style="padding:8px 12px;"><?= hBadge($leadsAutoWA > 0 ? 'ok' : 'warn', $leadsAutoWA > 0 ? " {$leadsAutoWA} total" : ' None yet') ?></td>
        <td style="padding:8px 12px;color:#94a3b8;font-size:11px;">Leads created from Evolution API messages  Today: <?= $waStats['auto_leads_24h'] ?></td>
    </tr>
    </table>
</div>

<!--  FIBER AUTOMATION  -->
<div class="st-card">
    <div class="st-card-title"> Fiber Install Automation  Last 7 Days</div>
    <?php if ($fiberStats['jobs'] === 0): ?>
    <div style="color:#94a3b8;font-size:13px;">No fiber installs in the last 7 days.</div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px;">
        <div style="background:#f8fafc;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:900;color:#1e293b;"><?= $fiberStats['jobs'] ?></div>
            <div style="font-size:10px;color:#64748b;font-weight:700;">INSTALLS</div>
        </div>
        <div style="background:<?= $fiberStats['delivery_sent']===$fiberStats['jobs']?'#f0fdf4':'#fffbeb' ?>;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:900;color:<?= $fiberStats['delivery_sent']===$fiberStats['jobs']?'#16a34a':'#d97706' ?>;"><?= $fiberStats['delivery_sent'] ?>/<?= $fiberStats['jobs'] ?></div>
            <div style="font-size:10px;color:#64748b;font-weight:700;">DELIVERY NOTES</div>
        </div>
        <div style="background:<?= $fiberStats['ticket_closed']===$fiberStats['jobs']?'#f0fdf4':'#fffbeb' ?>;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:900;color:<?= $fiberStats['ticket_closed']===$fiberStats['jobs']?'#16a34a':'#d97706' ?>;"><?= $fiberStats['ticket_closed'] ?>/<?= $fiberStats['jobs'] ?></div>
            <div style="font-size:10px;color:#64748b;font-weight:700;">TICKETS CLOSED</div>
        </div>
        <div style="background:<?= $fiberStats['invoiced']===$fiberStats['jobs']?'#f0fdf4':'#fffbeb' ?>;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:900;color:<?= $fiberStats['invoiced']===$fiberStats['jobs']?'#16a34a':'#d97706' ?>;"><?= $fiberStats['invoiced'] ?>/<?= $fiberStats['jobs'] ?></div>
            <div style="font-size:10px;color:#64748b;font-weight:700;">INVOICED</div>
        </div>
    </div>
    <?php if ($fiberStats['no_kyc'] > 0): ?>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:12px;color:#991b1b;">
         <strong><?= $fiberStats['no_kyc'] ?> install(s)</strong> could not send delivery note  no KYC record linked.
        Bidal should have received a WhatsApp alert for each one.
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!--  WA MARKETING CHANNEL  -->
<div class="st-card">
    <div class="st-card-title"> Evolution API  Marketing Channel</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
        <div style="background:#f5f3ff;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:900;color:#7c3aed;"><?= number_format($waStats['marketing']) ?></div>
            <div style="font-size:10px;color:#64748b;font-weight:700;">TOTAL CONVERSATIONS</div>
        </div>
        <div style="background:#f5f3ff;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:900;color:#7c3aed;"><?= $waStats['today'] ?></div>
            <div style="font-size:10px;color:#64748b;font-weight:700;">ACTIVE TODAY</div>
        </div>
        <div style="background:<?= $waStats['auto_leads_24h']>0?'#f0fdf4':'#f5f3ff' ?>;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:22px;font-weight:900;color:<?= $waStats['auto_leads_24h']>0?'#16a34a':'#7c3aed' ?>;"><?= $waStats['auto_leads_24h'] ?></div>
            <div style="font-size:10px;color:#64748b;font-weight:700;">AUTO-LEADS TODAY</div>
        </div>
    </div>
    <div style="margin-top:12px;font-size:12px;color:#64748b;">
        <a href="?page=dashboard&tab=whatsapp&subtab=conversations&diagnose=1" style="color:#7c3aed;font-weight:700;text-decoration:none;"> Run full Evolution API diagnostic </a>
        &nbsp;&nbsp;
        <a href="?page=dashboard&tab=whatsapp&subtab=conversations&cf=marketing" style="color:#7c3aed;font-weight:700;text-decoration:none;"> View marketing conversations </a>
    </div>
</div>

<!--  QUICK ACTIONS  -->
<div class="st-card">
    <div class="st-card-title"> Quick Tests</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="?page=dashboard&tab=settings&stab=health&test_cron=lead_alerts" style="background:#16a34a;color:#fff;padding:9px 16px;border-radius:10px;font-size:12px;font-weight:700;text-decoration:none;"> Run Lead Alert Cron</a>
        <a href="?page=dashboard&tab=leads&f=all" style="background:#1d4ed8;color:#fff;padding:9px 16px;border-radius:10px;font-size:12px;font-weight:700;text-decoration:none;"> View All Leads</a>
        <a href="?page=dashboard&tab=whatsapp&subtab=conversations&cf=marketing" style="background:#7c3aed;color:#fff;padding:9px 16px;border-radius:10px;font-size:12px;font-weight:700;text-decoration:none;"> Marketing Inbox</a>
        <a href="?page=dashboard&tab=settings&stab=system" style="background:#475569;color:#fff;padding:9px 16px;border-radius:10px;font-size:12px;font-weight:700;text-decoration:none;"> Archive Old Leads</a>
    </div>
    <div style="margin-top:10px;font-size:11px;color:#94a3b8;">Page auto-refreshes on load. Bookmark: Settings   Health</div>
</div>

<?php
// Handle test_cron action
if (isset($_GET['test_cron']) && $_GET['test_cron'] === 'lead_alerts') {
    $cronPath = dirname(__DIR__, 2) . '/cron_lead_alerts.php';
    if (file_exists($cronPath)) {
        ob_start();
        include $cronPath;
        $cronOut = ob_get_clean();
        echo '<div class="st-card"><div class="st-card-title"> Lead Alert Cron Output</div>';
        echo '<pre style="font-size:11px;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow-x:auto;line-height:1.6;">' . htmlspecialchars($cronOut) . '</pre></div>';
    }
}
?>

<?php endif; /* end stab if/elseif chain */ ?>

