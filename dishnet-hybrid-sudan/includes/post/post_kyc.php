<?php
// ═══════════════════════════════════════════════════════════════
// KYC / REGISTRATION
// ═══════════════════════════════════════════════════════════════

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='kyc_submit'){
    $retailer=$auth->requireLogin();
    // Use agent's personal UCRM app key so KYC activation payment shows their name
    // as "Created By" in UCRM and triggers customer email/WhatsApp under their identity.
    $kycForAgent = !empty($retailer['ucrm_app_key'])
        ? $kyc->withCrm(new CrmApiClient(rtrim($crm->getBaseUrl(), '/'), $retailer['ucrm_app_key'], 'X-Auth-App-Key'))
        : $kyc;
    $result=$kycForAgent->process($_POST,$_FILES,$retailer);
    if ($result['success']) {
        $app = $result['data'] ?? [];
        // Merge POST fields into $app so NotificationService has firstname/lastname/mobile/customer_type
        $app = array_merge([
            'firstname'     => trim($_POST['firstname'] ?? ''),
            'lastname'      => trim($_POST['lastname']  ?? ''),
            'mobile'        => trim($_POST['mobile']    ?? ''),
            'company_name'  => trim($_POST['company_name'] ?? ''),
            'customer_type' => trim($_POST['customer_type'] ?? ''),
            'connectivity_type' => trim($_POST['connectivity_type'] ?? ''),
        ], $app);

        // Branch: additional-service-for-existing-customer flow has its own
        // dedicated notification + CRM job (created in KycService::handleExisting).
        // Skip the generic kycSubmitted/kycCrmCreated to avoid duplicate WA traffic
        // and avoid the misleading "New Registration" headline for what is
        // actually a second site for an existing CRM client.
        $isAdditionalService = !empty($app['is_additional_service']);

        if ($isAdditionalService) {
            // Internal alert to agent + Bidal + admin (existing-customer flow)
            $notify->kycAdditionalService($retailer, $app);
        } else {
            // Internal alert to support team — KYC submitted by agent
            $notify->kycSubmitted($retailer, $app);
        }

        // ── Delivery PDF + T&C for cash customers ──────────────────────────
        // Cash sale with actual payment → customer took equipment home.
        // Send delivery acknowledgment PDF with full T&C via WhatsApp.
        // Credit/lead sales skip this — nothing was delivered yet.
        $isCashWithPayment = (($app['sales_type'] ?? '') === 'Cash')
            && (float)($app['amount_charged'] ?? 0) > 0;
        if ($isCashWithPayment) {
            try {
                require_once dirname(__DIR__, 2) . '/lib/DeliveryPdfService.php';
                $pdfSvc = new DeliveryPdfService($store, $dataDir, $config);
                $pdfResult = $pdfSvc->generateAndSend($app, $retailer, (string)($app['crm_client_id'] ?? ''));
                if (!empty($pdfResult['ok'])) {
                    $app['delivery_pdf_sent'] = true;
                    $app['terms_pdf_path']    = $pdfResult['pdf_path'] ?? '';
                }
            } catch (\Throwable $pdfErr) {
                // Non-fatal — KYC succeeds even if PDF fails
                logActivity($dataDir, 'delivery_pdf_error',
                    'Failed to generate delivery PDF for KYC #' . ($app['id'] ?? '?'),
                    $pdfErr->getMessage());
            }
        }

        // Customer-facing booking confirmation (sent as soon as CRM client created).
        // Skip for additional-service flow — the customer already has a CRM account
        // and a "welcome" message would be confusing. The agent-facing summary is
        // already covered by kycAdditionalService() above.
        $crmClientId = $app['crm_client_id'] ?? '';
        if ($crmClientId && !$isAdditionalService) {
            $notify->kycCrmCreated($retailer, $app, (string)$crmClientId);
        }

        // Store quote info in session for success screen display
        if (!empty($app['quote_id'])) {
            $_SESSION['last_kyc_quote_id']      = $app['quote_id'];
            $_SESSION['last_kyc_crm_id']        = $app['crm_client_id'] ?? '';
            $_SESSION['last_kyc_quote_created']  = true;
        } else {
            $_SESSION['last_kyc_quote_id']      = null;
            $_SESSION['last_kyc_quote_created']  = false;
        }
        $_SESSION['last_kyc_payment_id']      = $app['payment_id'] ?? null;
        $_SESSION['last_kyc_payment_created'] = $app['payment_created'] ?? false;
        // Photo upload status — shown on success screen
        $_SESSION['last_kyc_photo_uploaded']  = $app['photo_uploaded'] ?? null;
        $_SESSION['last_kyc_id_uploaded']     = $app['id_uploaded'] ?? null;
        $_SESSION['last_kyc_crm_id']          = $app['crm_client_id'] ?? '';
        $_SESSION['last_kyc_app_id']          = $app['id'] ?? null;
    }
    flash($result['message'],$result['success']?'success':'danger');
    redirect('?page=dashboard&tab=form');
}
// ── Re-upload failed KYC photos directly to an existing CRM client ───────────
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='kyc_reupload_docs'){
    // Session-based auth — works reliably with multipart/form-data (no Bearer header needed)
    $retailer = $auth->requireLogin();
    $appId    = (int)($_POST['app_id'] ?? 0);

    // JSON response helper
    $jsonOk  = function($d) { header('Content-Type: application/json'); echo json_encode(['status'=>'ok','data'=>$d]); exit; };
    $jsonErr = function($m) { header('Content-Type: application/json'); http_response_code(400); echo json_encode(['status'=>'error','message'=>$m]); exit; };

    if (!$appId) $jsonErr('Missing app_id');

    $kycPhotoDir = $dataDir . '/kyc_photos/';
    if (!is_dir($kycPhotoDir)) @mkdir($kycPhotoDir, 0755, true);

    $saveFile = function(string $tmpSrc, string $label, int $appId) use ($kycPhotoDir): array {
        $slug     = preg_replace('/[^a-z0-9]/', '_', strtolower($label));
        $fileName = 'app' . $appId . '_' . $slug . '_' . date('Ymd_His') . '.jpg';
        $savePath = $kycPhotoDir . $fileName;
        $saved    = false;

        if (function_exists('imagecreatefromstring')) {
            $raw = @file_get_contents($tmpSrc);
            $img = $raw ? @imagecreatefromstring($raw) : false;
            if ($img) {
                $w = imagesx($img); $h = imagesy($img); $max = 1200;
                if ($w > $max || $h > $max) {
                    $r = min($max/$w, $max/$h);
                    $nw = (int)round($w*$r); $nh = (int)round($h*$r);
                    $thumb = imagecreatetruecolor($nw, $nh);
                    imagecopyresampled($thumb,$img,0,0,0,0,$nw,$nh,$w,$h);
                    imagedestroy($img); $img = $thumb;
                }
                $saved = imagejpeg($img, $savePath, 78);
                imagedestroy($img);
            }
        }
        if (!$saved) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmpSrc);
            finfo_close($finfo);
            $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif',
                       'image/webp'=>'webp','image/heic'=>'heic','application/pdf'=>'pdf'];
            $ext      = $extMap[$mime] ?? 'jpg';
            $fileName = 'app' . $appId . '_' . $slug . '_' . date('Ymd_His') . '.' . $ext;
            $savePath = $kycPhotoDir . $fileName;
            $saved    = copy($tmpSrc, $savePath);
        }
        if (!$saved) return ['ok'=>false, 'msg'=>"Failed to save {$label}"];
        require_once dirname(__DIR__, 2) . '/lib/ImageCompressor.php';
        compressImage($savePath);
        return ['ok'=>true, 'path'=>'kyc_photos/'.$fileName, 'size'=>round(filesize($savePath)/1024).'KB'];
    };

    $saved = []; $errors = [];
    foreach ([
        'customer_image' => ['label'=>'Customer Photo', 'field'=>'photo'],
        'id_document'    => ['label'=>'ID Proof',       'field'=>'id'],
        'id_proof'       => ['label'=>'ID Proof',       'field'=>'id'],
        'kit_image'      => ['label'=>'Kit Label',      'field'=>'kit'],
    ] as $fileKey => $meta) {
        if (!empty($_FILES[$fileKey]['tmp_name']) && is_uploaded_file($_FILES[$fileKey]['tmp_name'])) {
            $res = $saveFile($_FILES[$fileKey]['tmp_name'], $meta['label'], $appId);
            if ($res['ok']) {
                $saved[$meta['field']] = $res;
                if ($meta['field'] === 'photo') {
                    $store->updateOne('kyc_applications.json', 'id', $appId, ['photo_uploaded'=>true, 'photo_path'=>$res['path']]);
                } elseif ($meta['field'] === 'id') {
                    $store->updateOne('kyc_applications.json', 'id', $appId, ['id_uploaded'=>true, 'id_path'=>$res['path']]);
                } elseif ($meta['field'] === 'kit') {
                    $store->updateOne('kyc_applications.json', 'id', $appId, ['kit_image_uploaded'=>true, 'kit_image_path'=>$res['path']]);
                }
            } else {
                $errors[] = $res['msg'];
            }
        }
    }

    if (empty($saved)) $jsonErr(empty($errors) ? 'No files received' : implode('; ', $errors));
    $jsonOk(['saved'=>$saved, 'warnings'=>$errors]);
}

// ── KYC Admin Edit ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='kyc_admin_edit') {
    $admin = $auth->requireAdmin();
    csrfCheck();
    $appId = (int)($_POST['app_id'] ?? 0);
    if (!$appId) { flash('Invalid application.', 'danger'); redirect('?page=dashboard&tab=applications'); }

    $app = $store->findOne('kyc_applications.json', 'id', $appId);
    if (!$app) { flash('Application not found.', 'danger'); redirect('?page=dashboard&tab=applications'); }

    // Fields admin can correct
    $allowed = ['firstname','lastname','mobile','email','address_1','company_name',
                'sales_type','connectivity_type','customer_type','note','status'];
    $changes = [];
    foreach ($allowed as $f) {
        if (!isset($_POST[$f])) continue;
        $new = trim($_POST[$f]);
        $old = trim((string)($app[$f] ?? ''));
        if ($new !== $old) $changes[$f] = ['from'=>$old,'to'=>$new];
    }

    if (empty($changes)) {
        flash('No changes detected.', 'info');
        redirect('?page=dashboard&tab=applications');
    }

    // Build update payload
    $update = [];
    foreach ($changes as $f => $v) $update[$f] = $v['to'];
    $update['updated_at'] = date('Y-m-d H:i:s');
    $update['status']     = $update['status'] ?? $app['status'] ?? 'new';

    // Append audit log entry
    $auditLog   = $app['audit_log'] ?? [];
    $auditLog[] = [
        'ts'      => date('Y-m-d H:i:s'),
        'by'      => $admin['name'] ?? $admin['username'] ?? 'Admin',
        'action'  => 'edit',
        'changes' => $changes,
        'ip'      => function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? ''),
    ];
    $update['audit_log'] = $auditLog;

    $store->updateOne('kyc_applications.json', 'id', $appId, $update);

    // If CRM client exists and name changed — update CRM too
    $crmClientId = $app['crm_client_id'] ?? '';
    $crmUpdated  = false;
    if ($crmClientId && (isset($changes['firstname']) || isset($changes['lastname']))) {
        $crmPatch = [];
        if (isset($changes['firstname'])) $crmPatch['firstName'] = $update['firstname'];
        if (isset($changes['lastname']))  $crmPatch['lastName']  = $update['lastname'];
        $res = $crm->patch('clients/'.$crmClientId, $crmPatch);
        $crmUpdated = !empty($res);
    }

    logActivity($dataDir, 'kyc_edit',
        "KYC #{$appId} edited by ".($admin['name']??'admin'),
        implode(', ', array_map(fn($f,$v) => "{$f}: '{$v['from']}' → '{$v['to']}'", array_keys($changes), $changes))
    );

    $msg = '✅ Application #'.$appId.' updated ('.count($changes).' field'.( count($changes)>1?'s':'' ).' changed).';
    if ($crmUpdated) $msg .= ' Name updated in UCRM too.';
    flash($msg, 'success');
    redirect('?page=dashboard&tab=applications');
}

// ── KYC Admin Delete (soft delete — marks cancelled, keeps audit) ─────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='kyc_admin_delete') {
    $admin = $auth->requireAdmin();
    csrfCheck();
    $appId  = (int)($_POST['app_id'] ?? 0);
    $reason = trim($_POST['delete_reason'] ?? '');
    $reasonType    = trim($_POST['cancel_reason_type'] ?? 'other');
    $equipStatus   = trim($_POST['equipment_status'] ?? 'not_applicable');
    $refundNeeded  = ($_POST['refund_needed'] ?? 'no') === 'yes';
    $refundAmount  = $refundNeeded ? round((float)($_POST['refund_amount'] ?? 0), 2) : 0;
    $crmAction     = trim($_POST['crm_action'] ?? 'no_action');

    if (!$appId) { flash('Invalid application.', 'danger'); redirect('?page=dashboard&tab=applications'); }

    $app = $store->findOne('kyc_applications.json', 'id', $appId);
    if (!$app) { flash('Not found.', 'danger'); redirect('?page=dashboard&tab=applications'); }

    $crmClientId = (int)($app['crm_client_id'] ?? 0);
    $custName = trim(($app['customer_name'] ?? $app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? ''));
    $reasonLabels = ['changed_mind'=>'Changed mind','too_expensive'=>'Too expensive','moved_away'=>'Moved away',
        'equipment_issue'=>'Equipment issue','duplicate'=>'Duplicate entry','wrong_details'=>'Wrong details','other'=>'Other'];
    $fullReason = ($reasonLabels[$reasonType] ?? $reasonType) . ($reason ? " - {$reason}" : '');

    // 1. Mark cancelled with full audit
    $auditLog = $app['audit_log'] ?? [];
    $auditLog[] = [
        'ts' => date('Y-m-d H:i:s'), 'by' => $admin['name'] ?? 'Admin', 'action' => 'cancel',
        'reason' => $fullReason, 'reason_type' => $reasonType, 'equipment_status' => $equipStatus,
        'refund_amount' => $refundAmount, 'crm_action' => $crmAction, 'ip' => function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? ''),
    ];
    $store->updateOne('kyc_applications.json', 'id', $appId, [
        'status' => 'cancelled', 'cancel_reason' => $fullReason, 'cancel_reason_type' => $reasonType,
        'equipment_status' => $equipStatus, 'refund_amount' => $refundAmount, 'crm_action' => $crmAction,
        'deleted_at' => date('Y-m-d H:i:s'), 'deleted_by' => $admin['name'] ?? 'Admin',
        'delete_reason' => $fullReason, 'audit_log' => $auditLog, 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $flashParts = ["App #{$appId} cancelled."];

    // 2. Equipment return tracking
    if (in_array($equipStatus, ['returned', 'pending_return'])) {
        $store->appendWithId('stock_returns.json', [
            'app_id' => $appId, 'crm_client_id' => $crmClientId, 'customer_name' => $custName,
            'customer_type' => $app['customer_type'] ?? 'Starlink', 'equipment_status' => $equipStatus,
            'cancel_reason' => $fullReason, 'returned_by' => $admin['name'] ?? 'Admin',
            'created_at' => date('Y-m-d H:i:s'), 'status' => $equipStatus === 'returned' ? 'received' : 'pending',
        ]);
        $flashParts[] = $equipStatus === 'returned' ? 'Equipment return recorded.' : 'Return marked pending.';
    }

    // 3. Refund to cashbook
    if ($refundAmount > 0) {
        try {
            require_once __DIR__ . '/../../lib/CashbookService.php';
            $cbSvc = new CashbookService($store, $dataDir);
            $cbSvc->createEntry([
                'direction' => 'out', 'amount' => $refundAmount, 'currency' => 'USD',
                'category' => 'Customer Refund', 'description' => "Refund cancelled KYC #{$appId} - {$custName}",
                'project' => 'dishnet', 'status' => 'approved', 'posted_by' => $admin['name'] ?? 'Admin',
            ]);
            $flashParts[] = "Refund \${$refundAmount} posted.";
        } catch (\Throwable $e) { $flashParts[] = "Refund failed: " . $e->getMessage(); }
    }

    // 4. CRM service end/suspend
    if ($crmClientId > 0 && $crmAction !== 'no_action') {
        try {
            require_once __DIR__ . '/../../lib/CrmApiClient.php';
            $crmApi = CrmApiClient::fromUcrm(__DIR__ . '/../..', $config);
            $svcList = $crmApi->get("/clients/{$crmClientId}/services") ?? [];
            foreach ($svcList as $cs) {
                $svcId = (int)($cs['id'] ?? 0); $st = (int)($cs['status'] ?? 0);
                if ($svcId > 0) {
                    if ($crmAction === 'end_service' && $st !== 5)
                        $crmApi->patch("/clients/services/{$svcId}", ['status' => 5, 'activeTo' => date('Y-m-d')]);
                    elseif ($crmAction === 'suspend_only' && $st === 1)
                        $crmApi->patch("/clients/services/{$svcId}", ['status' => 2]);
                }
            }
            $flashParts[] = $crmAction === 'end_service' ? 'CRM services ended.' : 'CRM services suspended.';
        } catch (\Throwable $e) { $flashParts[] = "CRM: " . $e->getMessage(); }
    }

    // 5. WhatsApp to admin
    try {
        if (!class_exists('NotificationService')) require_once __DIR__ . '/../../lib/NotificationService.php';
        $notif = new NotificationService($store, $config);
        $notif->sendAdmin("Cancellation - App #{$appId} - {$custName}\nReason: {$fullReason}\nEquipment: {$equipStatus}"
            . ($refundAmount > 0 ? "\nRefund: \${$refundAmount}" : '') . "\nCRM: {$crmAction}", 'kyc_cancelled');
    } catch (\Throwable $e) {}

    logActivity($dataDir, 'kyc_cancel', "KYC #{$appId} cancelled - {$custName}",
        "Reason: {$fullReason} | Equipment: {$equipStatus} | Refund: \${$refundAmount} | CRM: {$crmAction}");
    flash(implode(' ', $flashParts), 'warning');
    redirect('?page=dashboard&tab=applications');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='submit_recharge'){
    $retailer=$auth->requireLogin();
    $result=$recharge->submit($_POST,$_FILES,$retailer);
    if($result['success']){
        $requestId = $result['data']['id'] ?? 0;
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $autoApproveLimit = (float)($config['recharge_auto_approve_limit'] ?? 3000);

        // Auto-approve if under limit
        if ($amount > 0 && $amount <= $autoApproveLimit && $requestId > 0) {
            $systemAdmin = ['id' => 0, 'name' => 'Auto-Approved (System)', 'is_admin' => true];
            $approveResult = $recharge->approve($requestId, $systemAdmin);
            if ($approveResult['success']) {
                $newBal = $wallet->getBalance((int)$retailer['id']);
                try { $notify->rechargeApproved($retailer, $amount, $newBal, 'System (Auto)'); } catch (Throwable $e) {}
                flash("$$amount auto-approved and credited to your wallet.", 'success');
            } else {
                try { $notify->rechargeSubmitted($retailer, $amount, $requestId); } catch (Throwable $e) {}
                flash($result['message'], 'success');
            }
        } else {
            try { $notify->rechargeSubmitted($retailer, $amount, $requestId); } catch (Throwable $e) {}
            flash($result['message'], 'success');
        }
    } else {
        flash($result['message'], 'danger');
    }
    redirect('?page=dashboard&tab=wallet_recharge');
}
