<?php
// ══════ PWA + API ROUTES (before any HTML output) ══════
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }
if ($page === 'retailer') { header('Content-Type: text/html; charset=UTF-8'); readfile(__DIR__.'/retailer/index.html'); exit; }

// ── DishNet Support PWA ─────────────────────────────────────────────────────
// URL: public.php?page=pwa          → serve app shell (support.html)
// URL: public.php?page=pwa_manifest → serve PWA manifest JSON
// URL: public.php?page=pwa_sw       → serve service worker JS
// URL: public.php?page=wa_media     → serve uploaded WA media files
if ($page === 'pwa') {
    header('Content-Type: text/html; charset=UTF-8');
    readfile(__DIR__ . '/../pwa/support.html');
    exit;
}
if ($page === 'pwa_manifest') {
    header('Content-Type: application/manifest+json; charset=UTF-8');
    header('Cache-Control: public, max-age=86400');
    readfile(__DIR__ . '/../pwa/manifest.json');
    exit;
}
if ($page === 'pwa_sw') {
    header('Content-Type: application/javascript; charset=UTF-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache, no-store');
    readfile(__DIR__ . '/../pwa/sw.js');
    exit;
}
if ($page === 'wa_media') {
    $retailer = $auth->requireLogin();
    $f = trim($_GET['f'] ?? '');
    // Strip any path traversal attempts; only allow safe filenames
    $f = basename($f);
    if (!preg_match('/^[\w.\-]+$/', $f) || strpos($f, '..') !== false) {
        http_response_code(400); exit('Invalid filename');
    }
    $full = $dataDir . '/uploads/wa/' . $f;
    if (!file_exists($full)) { http_response_code(404); exit('Not found'); }
    $mime = mime_content_type($full) ?: 'application/octet-stream';
    $inline = in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $f . '"');
    header('Content-Length: ' . filesize($full));
    header('Cache-Control: private, max-age=86400');
    readfile($full);
    exit;
}


// ── Serve photos from local storage (admin/accountant/field staff) ───────
if ($page === 'kyc_photo') {
    $retailer = $auth->requireLogin();
    // Admin, accountant, support_leader, and field_accountant can view all photos
    // Other staff can only view expense photos (their own uploads are filename-matched)
    $allowedRoles = ['admin','accountant','support_leader','field_accountant'];
    $isPrivileged = !empty($retailer['is_admin']) || in_array($retailer['role'] ?? '', $allowedRoles, true);
    $f = trim($_GET['f'] ?? '');
    $f = str_replace(['..', chr(92), chr(0)], '', $f);
    if (!preg_match('/^(kyc_(photos|uploads)|uploads\/(expenses|expense_receipts|install_photos|proof[^\/]*))\/[\w\-\/\.]+$/', $f)) {
        http_response_code(400); exit('Invalid path');
    }
    // KYC photos: privileged only. Expense photos: privileged or own upload (exp-{rid}-*.ext)
    $isExpensePhoto = (strpos($f, 'uploads/expenses/') === 0 || strpos($f, 'uploads/expense_receipts/') === 0);
    $isOwnPhoto = false;
    if ($isExpensePhoto && !$isPrivileged) {
        $rid = (int)($retailer['id'] ?? 0);
        // Expense filenames: exp-{rid}-{timestamp}.ext
        $isOwnPhoto = $rid > 0 && preg_match('/exp-' . $rid . '-/', basename($f));
    }
    if (!$isPrivileged && !$isOwnPhoto) {
        http_response_code(403); exit('Access denied');
    }
    $full = $dataDir . '/' . $f;
    if (!file_exists($full)) { http_response_code(404); exit('Not found'); }
    $mime = mime_content_type($full) ?: 'application/octet-stream';
    $inline = in_array($mime, ['image/jpeg','image/png','image/gif','image/webp']);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename($full) . '"');
    header('Content-Length: ' . filesize($full));
    header('Cache-Control: private, max-age=3600');
    readfile($full);
    exit;
}
// ── Photo Manager API ─────────────────────────────────────────────────────────
if ($page === 'photo_manager') {
    $retailer = $auth->requireLogin();
    if (!($retailer['is_admin'] ?? false)) { http_response_code(403); exit('Admin only'); }
    header('Content-Type: application/json; charset=UTF-8');
    $type    = $_GET['type'] ?? 'all';
    $search  = strtolower(trim($_GET['q'] ?? ''));
    $pg      = max(1, (int)($_GET['pg'] ?? 1));
    $perPage = 48;
    $photos  = [];
    $addPhoto = function(string $rel, string $cat, string $label) use (&$photos, $dataDir) {
        $full = $dataDir . '/' . $rel;
        if (!file_exists($full)) return;
        $photos[] = [
            'path'     => $rel,
            'url'      => '?page=kyc_photo&f=' . urlencode($rel),
            'category' => $cat,
            'label'    => $label,
            'date'     => date('Y-m-d H:i', filemtime($full)),
            'size_kb'  => round(filesize($full) / 1024),
        ];
    };
    if ($type === 'all' || $type === 'kyc') {
        $kycUp = $dataDir . '/kyc_uploads';
        if (is_dir($kycUp)) {
            foreach (glob($kycUp . '/*/*') ?: [] as $dir) {
                if (!is_dir($dir)) continue;
                $crmId = str_replace('crm-', '', basename($dir));
                foreach (glob($dir . '/*.{jpg,png,webp,jpeg,pdf,heic}', GLOB_BRACE) ?: [] as $file) {
                    if (str_ends_with($file, '_meta.json')) continue;
                    $rel = 'kyc_uploads/' . basename(dirname($dir)) . '/crm-' . $crmId . '/' . basename($file);
                    $addPhoto($rel, 'kyc', 'CRM #' . $crmId . ' — ' . pathinfo($file, PATHINFO_FILENAME));
                }
            }
        }
        $kycOld = $dataDir . '/kyc_photos';
        if (is_dir($kycOld)) {
            foreach (glob($kycOld . '/*.{jpg,png,webp,jpeg,pdf}', GLOB_BRACE) ?: [] as $file) {
                $addPhoto('kyc_photos/' . basename($file), 'kyc', basename($file));
            }
        }
    }
    if ($type === 'all' || $type === 'expense') {
        // Structured receipts (ExpenseAdvanceService)
        $rcptDir = $dataDir . '/uploads/expense_receipts';
        if (is_dir($rcptDir)) {
            foreach (glob($rcptDir . '/*.{jpg,png,jpeg,pdf}', GLOB_BRACE) ?: [] as $file) {
                preg_match('/rcpt-(\d+)-/', basename($file), $m2);
                $addPhoto('uploads/expense_receipts/' . basename($file), 'expense', $m2 ? 'Receipt · staff #' . $m2[1] : basename($file));
            }
        }
        // Flat expense photos (cash_expenses.json)
        $expDir = $dataDir . '/uploads/expenses';
        if (is_dir($expDir)) {
            foreach (glob($expDir . '/*.{jpg,png,webp,jpeg,pdf}', GLOB_BRACE) ?: [] as $file) {
                preg_match('/exp-(\d+)-/', basename($file), $m2);
                $addPhoto('uploads/expenses/' . basename($file), 'expense', $m2 ? 'Expense · agent #' . $m2[1] : basename($file));
            }
        }
    }
    if ($type === 'all' || $type === 'install') {
        $instDir = $dataDir . '/uploads/install_photos';
        if (is_dir($instDir)) {
            foreach (glob($instDir . '/*', GLOB_ONLYDIR) ?: [] as $tdir) {
                $tid = basename($tdir);
                foreach (glob($tdir . '/*.{jpg,png,jpeg}', GLOB_BRACE) ?: [] as $file) {
                    $addPhoto('uploads/install_photos/' . $tid . '/' . basename($file), 'install', 'Ticket #' . $tid . ' · ' . basename($file));
                }
            }
        }
    }
    if ($search) {
        $photos = array_values(array_filter($photos, fn($p) =>
            str_contains(strtolower($p['label']), $search) ||
            str_contains(strtolower($p['path']), $search)
        ));
    }
    usort($photos, fn($a, $b) => strcmp($b['date'], $a['date']));
    $total = count($photos);
    echo json_encode(['total'=>$total,'page'=>$pg,'pages'=>max(1,(int)ceil($total/$perPage)),'photos'=>array_slice($photos,($pg-1)*$perPage,$perPage)]);
    exit;
}
if ($page === 'cashbook') { header('Content-Type: text/html; charset=UTF-8'); require __DIR__.'/cashbook_web.php'; exit; }
if ($page === 'crm_debug') {
    header('Content-Type: application/json; charset=UTF-8');
    $crm = svc('crm');

    // GET ?page=crm_debug&action=payment_methods — list all UISP payment method UUIDs
    // POST ?page=crm_debug&action=probe — test payment with real UUID from payment-methods endpoint
    if (($_GET['action'] ?? '') === 'payment_methods' || (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_GET['action'] ?? '') === 'probe')) {
        $clientId = (int)($_GET['client'] ?? 126);

        // Step 1: fetch payment-methods to get real UUIDs
        $methods = $crm->get('payment-methods') ?? [];
        $methodList = [];
        foreach ((array)$methods as $m) {
            if (!empty($m['id'])) $methodList[] = ['id'=>$m['id'],'name'=>$m['name']??'','type'=>$m['type']??''];
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            echo json_encode(['payment_methods'=>$methodList], JSON_PRETTY_PRINT);
            exit;
        }

        // Step 2: find Cash UUID
        $cashId = null;
        foreach ($methodList as $m) {
            $n = strtolower($m['name'].$m['type']);
            if (str_contains($n,'cash')) { $cashId = $m['id']; break; }
        }

        $probe = ['payment_methods_found' => $methodList];

        // Step 3: try variants
        $variants = ['no_method' => ['clientId'=>$clientId,'amount'=>0.01,'currencyCode'=>'USD','note'=>'API probe - ignore']];
        if ($cashId) $variants = ['methodId_uuid' => ['clientId'=>$clientId,'amount'=>0.01,'currencyCode'=>'USD','note'=>'API probe - ignore','methodId'=>$cashId]] + $variants;

        foreach ($variants as $label => $payload) {
            $result = $crm->post('payments', $payload);
            $err    = $crm->getLastError();
            if (!empty($result['id'])) {
                $crm->delete('payments/' . $result['id']);
                $probe['result'] = ['status'=>'SUCCESS','variant'=>$label,'payment_id'=>$result['id'],'voided'=>true,'winning_payload'=>$payload];
                break;
            } else {
                $errMsg = isset($err['http_code'])
                    ? "HTTP {$err['http_code']}: " . json_encode($err['response'] ?? '')
                    : json_encode($err);
                $probe[$label] = ['status'=>'FAIL','error'=>$errMsg];
            }
        }
        echo json_encode($probe, JSON_PRETTY_PRINT);
        exit;
    }

    // POST ?page=crm_debug&action=force_retry — fix and immediately retry all pending
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $retryQ  = $store->load('crm_payment_retry.json') ?? [];
        $results = [];
        foreach ($retryQ as $i => $rq) {
            if (($rq['status'] ?? '') !== 'pending') continue;
            $payload = $rq['payload'] ?? [];
            // Resolve methodId → UUID (handles legacy slugs, ints, display names, pass-through UUIDs)
            $_rawMethod = null;
            foreach (['methodId','method','paymentType'] as $_mk) {
                if (isset($payload[$_mk])) { $_rawMethod = $payload[$_mk]; unset($payload[$_mk]); break; }
            }
            $payload['methodId'] = PaymentUuids::resolve($_rawMethod);
            $result  = $crm->post('payments', $payload);
            $success = !empty($result) && isset($result['id']);
            if ($success) {
                $retryQ[$i]['status']        = 'synced';
                $retryQ[$i]['crm_payment_id']= $result['id'];
                $retryQ[$i]['synced_at']     = date('Y-m-d H:i:s');
                $retryQ[$i]['payload']       = $payload;
                if (!empty($rq['collection_id'])) {
                    $store->updateOne('payment_collections.json', 'id', (int)$rq['collection_id'], [
                        'crm_synced'     => true,
                        'crm_payment_id' => $result['id'],
                    ]);
                }
                $results[] = ['customer'=>$rq['customer_name'],'status'=>'synced','crm_payment_id'=>$result['id']];
            } else {
                $err = $crm->getLastError();
                $errMsg = isset($err['http_code'])
                    ? "HTTP {$err['http_code']}: ".json_encode($err['response']??'')
                    : ($err['curl_error'] ?? json_encode($err));
                $retryQ[$i]['attempts']      = ($rq['attempts'] ?? 1) + 1;
                $retryQ[$i]['last_error']    = $errMsg;
                $retryQ[$i]['payload']       = $payload; // save fixed payload
                $retryQ[$i]['next_retry_at'] = date('Y-m-d H:i:s', time() + 300);
                $results[] = ['customer'=>$rq['customer_name'],'status'=>'failed','error'=>$errMsg];
            }
        }
        $store->save('crm_payment_retry.json', $retryQ);
        echo json_encode(['status'=>'success','results'=>$results], JSON_PRETTY_PRINT);
        exit;
    }

    $retryQ = $store->load('crm_payment_retry.json') ?? [];
    $ucrm   = [];
    foreach ([__DIR__.'/ucrm.json', __DIR__.'/data/ucrm.json'] as $_uf) {
        if (file_exists($_uf)) { $ucrm = json_decode(file_get_contents($_uf), true) ?? []; break; }
    }
    $apiUrl = $ucrm['ucrmLocalUrl'] ?? ($ucrm['ucrmPublicUrl'] ?? 'NOT FOUND');
    $appKey = !empty($ucrm['pluginAppKey']) ? substr($ucrm['pluginAppKey'],0,8).'...' : 'NOT FOUND';
    echo json_encode([
        'crm_base_url'   => rtrim($apiUrl,'/').'/api/v2.1',
        'app_key_prefix' => $appKey,
        'crm_configured' => $crm->isConfigured(),
        'retry_count'    => count($retryQ),
        'hint'           => 'POST to this URL to force-retry all pending payments immediately',
        'recent_errors'  => array_values(array_map(fn($r)=>[
            'customer'  => $r['customer_name'] ?? '',
            'amount'    => $r['payload']['amount'] ?? 0,
            'attempts'  => $r['attempts'] ?? 0,
            'status'    => $r['status'] ?? '',
            'error'     => $r['last_error'] ?? $r['error'] ?? 'none',
            'created'   => $r['created_at'] ?? '',
        ], array_slice(array_reverse(array_values($retryQ)), 0, 5))),
    ], JSON_PRETTY_PRINT);
    exit;
}

// ── Cashbook CSV export — must run before ob_start fills buffer with HTML ──
if (($tab ?? '') === 'cashbook' && !empty($_GET['cb_export']) && $_GET['cb_export'] === 'csv') {
    require_once dirname(__DIR__) . '/lib/CashbookService.php';
    $cb2csv = new CashbookService($store, $dataDir);
    $proj2  = in_array($_GET['cb_proj'] ?? 'dishnet', ['dishnet','4g','bluecard']) ? $_GET['cb_proj'] : 'dishnet';
    // v4.9.18: Currency-aware export — delegate to cashbook.php's CSV handler
    $_csvCurr2 = in_array(strtoupper($_GET['cb_curr'] ?? ''), ['USD','SSP']) ? strtoupper($_GET['cb_curr']) : '';
    $csvF2 = array_filter(['project'=>$proj2, 'date_from'=>$_GET['cb_from']??'', 'date_to'=>$_GET['cb_to']??'', 'currency'=>$_csvCurr2, 'limit'=>9999, 'offset'=>0]);
    $rows2  = $cb2csv->getEntries($csvF2);
    $_isSSP2 = ($_csvCurr2 === 'SSP');
    $_isAll2 = ($_csvCurr2 === '');
    $fname2 = 'cashbook-'.strtoupper($proj2).'-'.($_csvCurr2 ?: 'ALL').'-'.date('Y-m-d').'.csv';
    ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$fname2.'"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out2 = fopen('php://output', 'w');
    if ($_isAll2) {
        fputcsv($out2, ['SR No.','Date','Particulars','Category','Person','Currency','Received USD','Payment USD','USD Balance','Received SSP','Payment SSP','SSP Balance','Ref','Status','Source']);
    } elseif ($_isSSP2) {
        fputcsv($out2, ['SR No.','Date','Particulars','Category','Person','Received SSP','Payment SSP','SSP Balance','Ref','Status','Source']);
    } else {
        fputcsv($out2, ['SR No.','Date','Particulars','Category','Person','Received USD','Payment USD','USD Balance','Ref','Status','Source']);
    }
    foreach ($rows2 as $e2) {
        $isIn2 = $e2['direction'] === 'in';
        $_isSspRow2 = ($e2['currency'] ?? 'USD') === 'SSP';
        $uA2 = (float)$e2['amount']; $sA2 = (float)($e2['ssp_amount'] ?? 0);
        $bl2 = $e2['running_balance'] ?? '';
        if ($_isAll2) {
            fputcsv($out2, [$e2['sr'],$e2['date'],$e2['description'],$e2['category'],$e2['person'],
                $_isSspRow2?'SSP':'USD',
                (!$_isSspRow2&&$isIn2)?$uA2:'', (!$_isSspRow2&&!$isIn2)?$uA2:'', (!$_isSspRow2)?$bl2:'',
                ($_isSspRow2&&$isIn2)?$sA2:'', ($_isSspRow2&&!$isIn2)?$sA2:'', ($_isSspRow2)?$bl2:'',
                $e2['validation_ref'],$e2['validation_status'],$e2['source']??'manual']);
        } elseif ($_isSSP2) {
            fputcsv($out2, [$e2['sr'],$e2['date'],$e2['description'],$e2['category'],$e2['person'],
                $isIn2?$sA2:'', $isIn2?'':$sA2, $bl2, $e2['validation_ref'],$e2['validation_status'],$e2['source']??'manual']);
        } else {
            fputcsv($out2, [$e2['sr'],$e2['date'],$e2['description'],$e2['category'],$e2['person'],
                $isIn2?$uA2:'', $isIn2?'':$uA2, $bl2, $e2['validation_ref'],$e2['validation_status'],$e2['source']??'manual']);
        }
    }
    fclose($out2);
    exit;
}

// ── Collections CSV export — must run before HTML buffer fills ────────────
if (($tab ?? '') === 'all_collections' && !empty($_GET['col_export']) && $_GET['col_export'] === 'csv') {
    $colFDate3  = $_GET['col_from']  ?? '';
    $colTDate3  = $_GET['col_to']    ?? '';
    $colSearch3 = trim($_GET['col_q'] ?? '');
    $colAgent3  = trim($_GET['col_agent'] ?? '');
    $allColsRaw3 = array_reverse($store->load('payment_collections.json') ?? []);
    $allCols3 = array_values(array_filter($allColsRaw3, function($c3) use ($colFDate3,$colTDate3,$colSearch3,$colAgent3) {
        $d3 = substr($c3['created_at']??'',0,10);
        if ($colFDate3 && $d3 < $colFDate3) return false;
        if ($colTDate3 && $d3 > $colTDate3) return false;
        if ($colSearch3 && stripos(($c3['customer_name']??'').($c3['crm_customer_id']??''), $colSearch3)===false) return false;
        if ($colAgent3 && (int)($c3['retailer_id']??0) !== (int)$colAgent3) return false;
        return true;
    }));
    $fname3 = 'collections-' . ($colFDate3 ?: 'all') . '-to-' . ($colTDate3 ?: date('Y-m-d')) . '.csv';
    ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$fname3.'"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out3 = fopen('php://output', 'w');
    fputcsv($out3, ['Retailer','Customer','CRM ID','Amount','Currency','Method','Note','CRM Synced','CRM Payment ID','Source','Date']);
    foreach ($allCols3 as $c3) {
        fputcsv($out3, [
            $c3['retailer_name'] ?? '',
            $c3['customer_name'] ?? '',
            $c3['crm_customer_id'] ?? '',
            (float)($c3['amount'] ?? 0),
            $c3['currency'] ?? 'USD',
            $c3['method'] ?? 'Cash',
            $c3['note'] ?? '',
            !empty($c3['crm_synced']) ? 'Yes' : 'No',
            $c3['crm_payment_id'] ?? '',
            $c3['source'] ?? 'pwa',
            $c3['created_at'] ?? '',
        ]);
    }
    fclose($out3);
    exit;
}

// ── Staff Cashbook CSV export — must run before HTML buffer fills ─────────
if (($tab ?? '') === 'staff_cashbooks' && !empty($_GET['sc_export']) && $_GET['sc_export'] === 'csv') {
    $r3 = $auth->requireLogin();
    $isAcct3 = !empty($r3['is_admin']) || in_array($r3['role'] ?? '', ['accountant','admin']);
    $selId3  = (int)($_GET['sc_staff'] ?? 0);
    if (!$isAcct3 || !$selId3) { header('Location: ?page=dashboard&tab=staff_cashbooks'); exit; }

    // Find staff name
    $allR3 = $store->load('retailers.json') ?? [];
    $staffName3 = 'staff';
    foreach ($allR3 as $s3) { if ((int)($s3['id']??0) === $selId3) { $staffName3 = $s3['name'] ?? 'staff'; break; } }

    // Load data
    $cols3 = array_filter($store->load('payment_collections.json') ?: [], fn($c) => (int)($c['retailer_id']??0)===$selId3 && ($c['status']??'')!=='voided');
    $hovs3 = array_filter($store->load('cash_handovers.json') ?: [], fn($h) => (int)($h['from_id']??0)===$selId3);
    $exps3 = array_filter($store->load('cash_expenses.json') ?: [], fn($e) => (int)($e['collector_id']??0)===$selId3 && !in_array($e['status']??'',['voided','cancelled']));
    $cins3 = array_filter($store->load('cash_ins.json') ?: [], fn($i) => (int)($i['collector_id']??0)===$selId3);

    $led3 = [];
    foreach ($cols3 as $c) { $led3[] = ['date'=>substr($c['collected_at']??$c['created_at']??'',0,10),'dir'=>'IN','cur'=>'USD','usd'=>(float)($c['amount']??0),'ssp'=>0,'cat'=>'Collection','desc'=>$c['customer_name']??'','status'=>empty($c['crm_synced'])?'pending':'approved']; }
    foreach ($cins3 as $i) { $xc=($i['category']??'')==='Exchange'?'SSP':($i['currency']??'SSP'); $led3[]=['date'=>substr($i['created_at']??'',0,10),'dir'=>'IN','cur'=>$xc,'usd'=>(float)($i['amount']??0),'ssp'=>(float)($i['ssp_amount']??0),'cat'=>$i['category']??'SSP Received','desc'=>$i['description']??'','status'=>$i['status']??'approved']; }
    foreach ($exps3 as $e) { $xc=$e['currency']??'USD'; $led3[]=['date'=>substr($e['submitted_at']??$e['created_at']??'',0,10),'dir'=>'OUT','cur'=>$xc,'usd'=>(float)($e['amount']??0),'ssp'=>(float)($e['ssp_amount']??0),'cat'=>$e['category']??'Expense','desc'=>$e['description']??'','status'=>$e['status']??'pending']; }
    foreach ($hovs3 as $h) { $xc=strtoupper($h['currency']??'USD'); $led3[]=['date'=>substr($h['created_at']??'',0,10),'dir'=>'OUT','cur'=>$xc,'usd'=>(float)($h['amount']??0),'ssp'=>(float)($h['ssp_amount']??$h['amount']??0),'cat'=>'Handover','desc'=>'To '.($h['to_name']??'Rupesh'),'status'=>$h['status']??'pending']; }

    // ── Advances received (root only) ───────────────────────────────────
    try {
        $_a3 = $store->getPdo()->prepare(
            "SELECT advance_no, amount, currency, purpose, description, status, issued_at
             FROM cash_advances
             WHERE recipient_id = ? AND status IN ('active','partial','settled')
               AND (parent_advance_id IS NULL OR parent_advance_id = 0)
             ORDER BY issued_at DESC"
        );
        $_a3->execute([$selId3]);
        foreach ($_a3->fetchAll(\PDO::FETCH_ASSOC) as $_av) {
            $_ac = strtoupper($_av['currency'] ?? 'USD');
            $_aa = (float)($_av['amount'] ?? 0);
            $led3[] = ['date'=>substr($_av['issued_at']??'',0,10),'dir'=>'IN','cur'=>$_ac,'usd'=>$_ac==='USD'?$_aa:0,'ssp'=>$_ac==='SSP'?$_aa:0,'cat'=>'Advance Received','desc'=>($_av['advance_no']??'').' — '.($_av['purpose']??'').($_av['description']?' : '.$_av['description']:''),'status'=>$_av['status']??'active'];
        }
    } catch (Throwable $_ae3) {}

    // ── Staff expenses (advance-linked) ─────────────────────────────────
    try {
        $_se3 = $store->getPdo()->prepare(
            "SELECT expense_no, amount, currency, category, description, expense_date, status
             FROM staff_expenses WHERE staff_id = ? AND status = 'approved'
             ORDER BY expense_date DESC"
        );
        $_se3->execute([$selId3]);
        foreach ($_se3->fetchAll(\PDO::FETCH_ASSOC) as $_sx) {
            $_sc = strtoupper($_sx['currency'] ?? 'USD');
            $_sa = (float)($_sx['amount'] ?? 0);
            $led3[] = ['date'=>$_sx['expense_date']??'','dir'=>'OUT','cur'=>$_sc,'usd'=>$_sc==='USD'?$_sa:0,'ssp'=>$_sc==='SSP'?$_sa:0,'cat'=>'Advance Expense','desc'=>($_sx['expense_no']??'').' — '.($_sx['category']??'').($_sx['description']?' : '.$_sx['description']:''),'status'=>'approved'];
        }
    } catch (Throwable $_ae3) {}

    // ── Staff transfers ─────────────────────────────────────────────────
    try {
        $_t3 = $store->getPdo()->prepare(
            "SELECT transfer_no, from_id, from_name, to_id, to_name, amount, currency, description, submitted_at
             FROM staff_transfers WHERE (from_id = ? OR to_id = ?) AND status = 'approved'
             ORDER BY submitted_at DESC"
        );
        $_t3->execute([$selId3, $selId3]);
        foreach ($_t3->fetchAll(\PDO::FETCH_ASSOC) as $_tx) {
            $_tc = strtoupper($_tx['currency'] ?? 'USD');
            $_ta = (float)($_tx['amount'] ?? 0);
            $_isSnd = (int)$_tx['from_id'] === $selId3;
            $_tdesc = ($_tx['transfer_no']??'').' — '.($_isSnd ? 'To '.($_tx['to_name']??'') : 'From '.($_tx['from_name']??'')).($_tx['description']?' ('.$_tx['description'].')':'');
            $led3[] = ['date'=>substr($_tx['submitted_at']??'',0,10),'dir'=>$_isSnd?'OUT':'IN','cur'=>$_tc,'usd'=>$_tc==='USD'?$_ta:0,'ssp'=>$_tc==='SSP'?$_ta:0,'cat'=>$_isSnd?'Transfer Out':'Transfer In','desc'=>$_tdesc,'status'=>'approved'];
        }
    } catch (Throwable $_ae3) {}

    $xFrom3 = $_GET['sc_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $xTo3   = $_GET['sc_to'] ?? date('Y-m-d');
    $xCur3  = strtoupper($_GET['sc_cur'] ?? 'USD');
    $led3 = array_filter($led3, fn($r) => $r['date'] >= $xFrom3 && $r['date'] <= $xTo3 && $r['cur'] === $xCur3);
    usort($led3, fn($a,$b) => strcmp($a['date'], $b['date']));

    $slug3 = preg_replace('/[^a-z0-9]+/', '-', strtolower($staffName3));
    $fn3   = "staff-cashbook-{$slug3}-{$xCur3}-{$xFrom3}-to-{$xTo3}.csv";
    $isSSP3 = ($xCur3 === 'SSP');

    ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$fn3.'"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out3 = fopen('php://output', 'w');
    fputcsv($out3, ['Date','Time','Direction','Category','Description',
        $isSSP3 ? 'Received (SSP)' : 'Received (USD)',
        $isSSP3 ? 'Payment (SSP)' : 'Payment (USD)',
        'Status']);
    foreach ($led3 as $xr3) {
        $isIn3 = $xr3['dir'] === 'IN';
        $amt3  = $isSSP3 ? $xr3['ssp'] : $xr3['usd'];
        $isDead3 = in_array($xr3['status'], ['voided','cancelled','reverted']);
        // v4.9.19: Reverted/voided entries show 0 amount so running balance stays correct
        $csvAmt3 = $isDead3 ? 0 : $amt3;
        $csvDesc3 = $isDead3 ? '['.strtoupper($xr3['status']).'] '.$xr3['desc'] : $xr3['desc'];
        $_xTime3 = isset($xr3['datetime']) && strlen($xr3['datetime'])>10 ? date('H:i', strtotime($xr3['datetime'])) : '';
        fputcsv($out3, [$xr3['date'], $_xTime3, $xr3['dir'], $xr3['cat'], $csvDesc3,
            $isIn3 ? $csvAmt3 : '', $isIn3 ? '' : $csvAmt3, $xr3['status']]);
    }
    fclose($out3);
    exit;
}

// ── Wallet / Field Register CSV export — must run before HTML buffer fills ──
if (($tab ?? '') === 'wallet' && !empty($_GET['fr_export']) && $_GET['fr_export'] === 'csv') {
    $r = $auth->requireLogin();
    $agId = (int)($r['id'] ?? 0);
    $fr_curr = $_GET['fr_curr'] ?? '';
    $fr_from = $_GET['fr_from'] ?? '';
    $fr_to   = $_GET['fr_to']   ?? '';
    $rows = [];
    foreach ($store->load('payment_collections.json') ?: [] as $c) {
        if ((int)($c['retailer_id']??0) !== $agId) continue;
        $dt = substr($c['collected_at']??$c['created_at']??'',0,10);
        if ($fr_from && $dt < $fr_from) continue;
        if ($fr_to   && $dt > $fr_to)   continue;
        if ($fr_curr && $fr_curr !== 'USD') continue;
        $rows[] = [$dt,'IN','USD',$c['amount']??0,0,'Collection',$c['customer_name']??$c['client_name']??'',empty($c['crm_synced'])?'pending':'approved'];
    }
    foreach ($store->load('cash_expenses.json') ?: [] as $e) {
        if ((int)($e['collector_id']??0) !== $agId) continue;
        $dt = substr($e['submitted_at']??$e['created_at']??'',0,10);
        if ($fr_from && $dt < $fr_from) continue;
        if ($fr_to   && $dt > $fr_to)   continue;
        $cur = $e['currency'] ?? 'USD';
        if ($fr_curr && $fr_curr !== $cur) continue;
        $rows[] = [$dt,'OUT',$cur,$e['amount']??0,$e['ssp_amount']??0,$e['category']??'Expense',$e['description']??'',$e['status']??'pending'];
    }
    foreach ($store->load('cash_handovers.json') ?: [] as $h) {
        if ((int)($h['from_id']??0) !== $agId) continue;
        $dt = substr($h['created_at']??'',0,10);
        if ($fr_from && $dt < $fr_from) continue;
        if ($fr_to   && $dt > $fr_to)   continue;
        if ($fr_curr && $fr_curr !== 'USD') continue;
        $rows[] = [$dt,'OUT','USD',$h['amount']??0,0,'Handover',$h['note']??'',$h['status']??'pending'];
    }
    foreach ($store->load('cash_ins.json') ?: [] as $i) {
        if ((int)($i['collector_id']??0) !== $agId) continue;
        $dt = substr($i['created_at']??'',0,10);
        if ($fr_from && $dt < $fr_from) continue;
        if ($fr_to   && $dt > $fr_to)   continue;
        $cur = $i['currency'] ?? 'SSP';
        if ($fr_curr && $fr_curr !== $cur) continue;
        $rows[] = [$dt,'IN',$cur,$i['amount']??0,$i['ssp_amount']??0,$i['category']??'SSP Received',$i['description']??'',$i['status']??'approved'];
    }
    usort($rows, fn($a,$b) => strcmp($a[0],$b[0]));
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="field-register-'.date('Y-m-d').'.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output','w');
    fputcsv($out, ['Date','Direction','Currency','Amount','SSP Amount','Category','Description','Status']);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

// ── Splynx config patch — admin only, run once ────────────────────────────────
if ($page === 'splynx_patch') {
    $r = $auth->requireLogin();
    if (!($r['is_admin'] ?? false)) { http_response_code(403); exit('Admin only.'); }

    $cfgFile = $dataDir . '/kyc_config.json';
    $cfg     = json_decode(file_get_contents($cfgFile), true) ?: [];
    $cfg['splynx_url']    = 'https://my.dishnetafrica.com';
    $cfg['splynx_key']    = '2b10a77dd91668b95b4355200689b0bc';
    $cfg['splynx_secret'] = '9174fd8da2d4b7bf35a369cd1a020d45';
    file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $key   = $cfg['splynx_key'];
    $sec   = $cfg['splynx_secret'];
    $base  = rtrim($cfg['splynx_url'], '/');
    $basic = 'Authorization: Basic ' . base64_encode($key . ':' . $sec);

    header('Content-Type: text/html; charset=UTF-8');
    echo '<pre style="font-family:monospace;font-size:13px;padding:20px;background:#0f172a;color:#e2e8f0;">';
    echo "✅ Credentials saved\n";
    echo "URL:    {$base}\n";
    echo "Basic:  " . base64_encode($key . ':' . $sec) . "\n\n";

    foreach (['/api/2.0/admin/administrators','/api/2.0/admin/customers/customer','/api/2.0/admin/tariffs/internet'] as $path) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $base . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [$basic, 'Accept: application/json'],
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $icon = ($code >= 200 && $code < 300) ? '✅' : ($code === 401 ? '🔑 BAD CREDS' : '❌');
        echo "{$icon} HTTP {$code} — {$path}\n";
        echo "   " . htmlspecialchars(substr((string)$raw, 0, 300)) . "\n\n";
        if ($code >= 200 && $code < 300) { echo "✅ SPLYNX CONNECTED — NOC tab will work now\n"; break; }
    }
    echo '</pre>';
    exit;
}

// ── I-04 FIX: Serve proof image by recharge request ID ───────────────────
// Replaces inline base64 embedding (all proofs loaded on every page render).
// Usage: <img src="?page=serve_proof&id=42">
// Only accessible to authenticated admin or the retailer who owns the request.
if ($page === 'serve_proof') {
    $proofId  = (int)($_GET['id'] ?? 0);
    $retailer = $auth->currentRetailer();
    if (!$retailer) { http_response_code(403); exit('Forbidden'); }
    $req = $store->findOne('wallet_recharge_requests.json', 'id', $proofId);
    if (!$req || empty($req['payment_proof'])) { http_response_code(404); exit('Not found'); }
    // Admins can see all; non-admins only their own
    if (!($retailer['is_admin'] ?? false) && (int)($req['retailer_id'] ?? 0) !== (int)$retailer['id']) {
        http_response_code(403); exit('Forbidden');
    }
    $filePath = $dataDir . '/' . $req['payment_proof'];
    if (!file_exists($filePath)) { http_response_code(404); exit('File not found'); }
    $mime = mime_content_type($filePath) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

// ── PWA Manifest ────────────────────────────────────────────────────────
if ($page === 'app_manifest') {
    header('Content-Type: application/manifest+json');
    $scheme    = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    $host      = $_SERVER['HTTP_HOST'];
    $scriptPath= $_SERVER['SCRIPT_NAME']; // e.g. /crm/_plugins/dishnet-hybrid-telecom/public.php
    $pluginDir = dirname($scriptPath);    // e.g. /crm/_plugins/dishnet-hybrid-telecom
    $pluginUrl = $scheme.'://'.$host.$scriptPath;
    // start_url and scope must be same-origin as the manifest URL (?page=app_manifest)
    // Scope = the directory containing public.php, so all ?page= URLs are in scope
    echo json_encode([
        'name'             => 'DishNet Africa',
        'short_name'       => 'DishNet',
        'description'      => 'DishNet Africa Sales & Operations Hub',
        'start_url'        => $scriptPath.'?page=login',
        'scope'            => $pluginDir.'/',
        'display'          => 'standalone',
        'orientation'      => 'portrait',
        'background_color' => '#0F172A',
        'theme_color'      => '#D41C1C',
        'icons'            => [
            ['src'=>$scriptPath.'?page=app_icon&size=192','sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],
            ['src'=>$scriptPath.'?page=app_icon&size=512','sizes'=>'512x512','type'=>'image/png','purpose'=>'any maskable'],
            ['src'=>$scriptPath.'?page=app_icon',         'sizes'=>'any',    'type'=>'image/svg+xml'],
        ],
        'categories'       => ['business','productivity'],
    ], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
}
if ($page === 'app_icon') {
    $size = (int)($_GET['size'] ?? 0);
    // iOS requires PNG icons — generate PNG when size is requested
    if ($size > 0 && $size <= 1024 && function_exists('imagecreatetruecolor')) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        $img = imagecreatetruecolor($size, $size);
        imagesavealpha($img, true);
        $trans = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $trans);

        // Background: rounded rect via filled circle corners (approximate)
        $bg = imagecolorallocate($img, 15, 23, 42);   // #0F172A
        $red = imagecolorallocate($img, 212, 28, 28); // #D41C1C
        $white = imagecolorallocate($img, 255, 255, 255);

        // Fill background (no true rounded rect in GD — use full fill, iOS clips to rounded)
        imagefilledrectangle($img, 0, 0, $size-1, $size-1, $bg);

        // Circle (signal dish icon) centered at ~37% from top
        $cx = (int)($size * 0.5);
        $cy = (int)($size * 0.38);
        $r  = (int)($size * 0.18);
        imagefilledellipse($img, $cx, $cy, $r*2, $r*2, $red);

        // Signal arc below circle
        $arcR = (int)($size * 0.35);
        $th   = (int)($size * 0.06);
        for ($t = 0; $t < $th; $t++) {
            imagearc($img, $cx, $cy, ($arcR+$t)*2, ($arcR+$t)*2, 200, 340, $red);
        }

        // "DN" text centered lower
        $fs = max(2, (int)($size / 8));
        $tx = (int)($size * 0.5);
        $ty = (int)($size * 0.78);
        // Use built-in font — no TTF needed
        $fontW = imagefontwidth(5) * 2;
        $fontH = imagefontheight(5);
        imagestring($img, 5, $tx - $fontW, $ty - $fontH/2, 'DN', $white);

        imagepng($img);
        imagedestroy($img);
        exit;
    }
    // Fallback: SVG (Android, desktop browsers support it)
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=86400');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">'
       . '<rect width="512" height="512" rx="80" fill="#0F172A"/>'
       . '<circle cx="256" cy="190" r="90" fill="#D41C1C"/>'
       . '<path d="M100 400 Q256 300 412 400" stroke="#D41C1C" stroke-width="32" fill="none" stroke-linecap="round"/>'
       . '<text x="256" y="490" text-anchor="middle" font-family="Arial Black,sans-serif" font-size="56" font-weight="900" fill="#fff">DishNet</text>'
       . '</svg>';
    exit;
}
if ($page === 'app_sw') {
    header('Content-Type: application/javascript');
    header('Service-Worker-Allowed: /');
    // Version bump forces SW update and clears old caches
    $swVer = 'dishnet-v5';
    echo <<<JS
const C = '$swVer';
const SHELL = [
  '?page=login',
  '?page=app_icon&size=192',
  '?page=app_manifest',
];

// Pre-cache app shell on install so login page works offline
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(C).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(ks => Promise.all(ks.filter(k => k !== C).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  // Only handle GET requests
  if (e.request.method !== 'GET') return;

  const url = new URL(e.request.url);
  const pg  = url.searchParams.get('page') || '';

  // NEVER intercept API, cron, or webhook requests — let them go straight to network
  if (pg === 'api' || pg === 'webhook' || pg === 'app_sw' ||
      pg.startsWith('cron') || url.pathname.includes('webhook')) {
    return; // browser handles it natively, no SW involvement
  }

  // Static assets (icons, manifest): cache-first
  if (pg === 'app_icon' || pg === 'app_manifest') {
    e.respondWith(
      caches.match(e.request).then(cached => cached || fetch(e.request).then(r => {
        const rc = r.clone();
        caches.open(C).then(c => c.put(e.request, rc));
        return r;
      }))
    );
    return;
  }

  // Login page: network-first, cache as offline fallback
  if (pg === 'login' || pg === '') {
    e.respondWith(
      fetch(e.request).then(r => {
        const rc = r.clone();
        caches.open(C).then(c => c.put(e.request, rc));
        return r;
      }).catch(() => caches.match(e.request))
    );
    return;
  }

  // All other dashboard pages: network-first, no cache fallback
  // (dashboard requires auth — serving stale cached version causes confusion)
  e.respondWith(
    fetch(e.request).catch(() => new Response('Offline', {status: 503, statusText: 'Offline'}))
  );
});
JS;
    exit;
}

// ── Archived APK Version Download (admin only) ───────────────────────────
if ($page === 'download_apk_version') {
    $admin2 = $auth->getAdmin();
    if (!$admin2) { http_response_code(403); echo 'Admin access required.'; exit; }
    $reqFile = basename($_GET['file'] ?? '');
    if (!$reqFile || !preg_match('/^DishNet-Africa-v[\w._-]+-\d{4}-\d{2}-\d{2}(_\d{6})?\.apk$/', $reqFile)) {
        http_response_code(400); echo 'Invalid file.'; exit;
    }
    $filePath = $dataDir . '/' . $reqFile;
    if (!file_exists($filePath)) { http_response_code(404); echo 'File not found.'; exit; }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="' . $reqFile . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $fp = fopen($filePath, 'rb');
    while (!feof($fp)) { echo fread($fp, 8192); flush(); }
    fclose($fp);
    exit;
}

// ── APK Download ─────────────────────────────────────────────────────────
if ($page === 'download_app') {
    // Force HTTPS — UCRM runs behind reverse proxy, $_SERVER['HTTPS'] may be empty
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
            || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
    if (!$isHttps) {
        $httpsUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header('HTTP/1.1 301 Moved Permanently');
        header('Location: ' . $httpsUrl);
        exit;
    }
    // Resolve APK path: prefer stored_filename from meta, fall back to legacy name
    $_apkMeta2   = $store->load('android_app_meta.json') ?? [];
    $_storedName = $_apkMeta2['stored_filename'] ?? '';
    $apkPath = ($_storedName && file_exists($dataDir.'/'.$_storedName))
        ? $dataDir.'/'.$_storedName
        : ($dataDir.'/dishnet-app.apk');
    if (!file_exists($apkPath)) {
        http_response_code(404);
        echo 'APK not found. Please re-upload via Android App tab.';
        exit;
    }
    $downloadName = $_storedName ?: 'DishNet-Africa.apk';
    // Clean output buffer — prevent any whitespace before binary data
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($apkPath));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('Access-Control-Allow-Origin: *');
    // Stream in chunks — prevents memory issues with large APK
    $fp = fopen($apkPath, 'rb');
    while (!feof($fp)) { echo fread($fp, 8192); flush(); }
    fclose($fp);
    exit;
}

// ── PWA Install Page (shareable, no login required) ─────────────────────
if ($page === 'install') {
    // ══ DishNet App Installer ══
    // Android → APK download (existing working app for KYC/Sales/Collections)
    // iPhone  → Support PWA install (Safari "Add to Home Screen")
    // The PWA is iPhone-only; Android staff use the native APK
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (($_SERVER['SERVER_PORT'] ?? 80) == 443)) ? 'https' : 'http';
    $appUrl   = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
    $apkUrl   = $appUrl . '?page=download_app';
    $supUrl   = $appUrl . '?page=pwa';
    $_apkMeta  = $store->load('android_app_meta.json') ?? [];
    $_apkVer   = $_apkMeta['version'] ?? '1.0';
    $_apkCode  = $_apkMeta['version_code'] ?? '';
    $_storedN2 = $_apkMeta['stored_filename'] ?? '';
    $_apkFile  = ($_storedN2 && file_exists($dataDir.'/'.$_storedN2))
        ? $dataDir.'/'.$_storedN2
        : (file_exists($dataDir.'/dishnet-app.apk') ? $dataDir.'/dishnet-app.apk' : '');
    $apkExists = file_exists($_apkFile);
    $apkSize   = $apkExists ? round(filesize($_apkFile)/1024/1024,1).'MB' : '';
    $qrUrl     = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='.urlencode($apkUrl);
    $waText    = urlencode(
        "📡 *DishNet Africa App*\n\n".
        "Download and install the DishNet app directly:\n\n".
        "👇 *Tap to download APK (Android)*\n".
        $apkUrl."\n\n".
        "*How to install after downloading:*\n".
        "1. Tap the downloaded file\n".
        "2. If blocked: Settings → Allow from this source\n".
        "3. Tap Install ✅\n\n".
        "The DishNet Africa icon will appear on your home screen."
    );
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta name="theme-color" content="#0F172A">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>DishNet Africa — Install App</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
body{background:#0F172A;color:#fff;display:flex;flex-direction:column;align-items:center;padding:28px 16px 48px}
.logo{font-size:52px;margin-bottom:6px}
h1{font-size:26px;font-weight:900;text-align:center}
.sub{font-size:13px;color:#64748B;margin-top:6px;text-align:center}
.badge{display:inline-block;background:#1E293B;border:1px solid #334155;color:#94A3B8;font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;margin-top:8px;text-transform:uppercase;letter-spacing:.5px}

/* ── ANDROID VIEW ── */
#view-android{width:100%;max-width:400px;display:none;flex-direction:column;align-items:center;gap:10px;margin-top:10px}
.dl-card{background:linear-gradient(135deg,#D41C1C,#A81515);border-radius:24px;padding:28px 24px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(212,28,28,.25)}
.dl-card h2{font-size:18px;font-weight:800;margin-bottom:4px}
.dl-card p{font-size:13px;opacity:.85;margin-bottom:20px}
.btn-dl{display:block;background:#fff;color:#1d4ed8;border-radius:14px;padding:16px 24px;font-size:17px;font-weight:900;text-decoration:none;transition:.15s}
.btn-dl:active{transform:scale(.98)}
.btn-dl span{font-size:11px;font-weight:600;color:#64748B;display:block;margin-top:3px}
.btn-wa{display:flex;align-items:center;justify-content:center;gap:10px;background:linear-gradient(135deg,#128C7E,#25D366);color:#fff;border-radius:14px;padding:14px 24px;font-size:15px;font-weight:800;text-decoration:none;width:100%}
.steps-card{background:#1E293B;border-radius:20px;padding:22px;width:100%}
.steps-card h3{font-size:12px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px}
.step{display:flex;gap:12px;align-items:flex-start;margin-bottom:14px}
.step-n{width:28px;height:28px;border-radius:50%;background:#0EA5E9;font-size:12px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff}
.step-t{font-size:13px;font-weight:700;color:#F1F5F9;padding-top:4px}
.step-d{font-size:11px;color:#64748B;margin-top:2px;line-height:1.55}
.warn{background:#1a1200;border:1px solid #854d0e;border-radius:12px;padding:12px 14px;font-size:12px;color:#fbbf24;line-height:1.6;margin-top:4px}
.qr-card{background:#1E293B;border-radius:20px;padding:20px;width:100%;text-align:center}
.qr-card h3{font-size:12px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:1px;margin-bottom:14px}
.qr-card img{border-radius:12px;background:#fff;padding:8px}
.qr-url{font-size:10px;color:#475569;word-break:break-all;margin-top:10px;font-family:monospace}

/* ── iPHONE VIEW ── */
#view-iphone{width:100%;max-width:400px;display:none;flex-direction:column;align-items:center;gap:12px;margin-top:10px}
.pwa-hero{background:linear-gradient(135deg,#16213e,#0d2137);border:1px solid #25D366;border-radius:24px;padding:28px 24px;width:100%;text-align:center}
.pwa-hero-icon{font-size:52px;margin-bottom:10px}
.pwa-hero h2{font-size:20px;font-weight:800;margin-bottom:6px}
.pwa-hero p{font-size:13px;color:#94A3B8;margin-bottom:20px;line-height:1.55}
.btn-open{display:block;background:#25D366;color:#fff;border-radius:14px;padding:15px 24px;font-size:16px;font-weight:800;text-decoration:none;margin-bottom:10px}
.btn-open:active{background:#128C7E}
.btn-open span{font-size:11px;font-weight:600;opacity:.85;display:block;margin-top:3px}
.iphone-steps{background:#1E293B;border-radius:20px;padding:22px;width:100%}
.iphone-steps h3{font-size:12px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px;text-align:center}
.iphone-step{display:flex;gap:12px;align-items:flex-start;margin-bottom:14px}
.iphone-step-n{width:28px;height:28px;border-radius:50%;background:#25D366;font-size:12px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff;flex-shrink:0}
.iphone-step-t{font-size:13px;font-weight:700;color:#F1F5F9;padding-top:4px}
.iphone-step-d{font-size:12px;color:#64748B;margin-top:3px;line-height:1.55}
.share-btn{display:inline-flex;align-items:center;gap:4px;background:#007AFF;color:#fff;border-radius:8px;padding:3px 10px;font-size:12px;font-weight:700;vertical-align:middle}
.share-arrow{display:inline-block;background:#007AFF;color:#fff;border-radius:6px;padding:2px 7px;font-size:13px;font-weight:700;margin:0 3px}
.tip-box{background:#0d2d0d;border:1px solid #166534;border-radius:12px;padding:13px 15px;font-size:12px;color:#86efac;line-height:1.65}
.safari-warning{background:#1a1f2e;border:1px solid #334155;border-radius:12px;padding:13px 15px;font-size:12px;color:#94A3B8;line-height:1.65}
.safari-warning strong{color:#F1F5F9}

/* ── FALLBACK (desktop / unknown) ── */
#view-fallback{width:100%;max-width:400px;display:none;flex-direction:column;align-items:center;gap:10px;margin-top:10px}
.fallback-card{background:#1E293B;border-radius:20px;padding:24px;width:100%;text-align:center}
.fallback-card h2{font-size:18px;font-weight:700;margin-bottom:12px}
.fallback-row{display:flex;gap:10px;margin-top:8px}
.fallback-btn{flex:1;padding:13px 10px;border-radius:12px;font-size:14px;font-weight:700;text-decoration:none;text-align:center;display:block}
.fallback-btn.android{background:#D41C1C;color:#fff}
.fallback-btn.iphone{background:#25D366;color:#fff}
.footer-note{font-size:11px;color:#475569;margin-top:28px;text-align:center;line-height:1.7}
</style>
</head>
<body>
  <div class="logo">📡</div>
  <h1>DishNet Africa</h1>
  <p class="sub">Staff app installer</p>
  <div class="badge">v' . $_apkVer . ($_apkCode ? ' (build '.$_apkCode.')' : '') . ($apkSize ? ' · '.$apkSize : '') . '</div>

  <!-- ── ANDROID VIEW: APK download ── -->
  <div id="view-android">
    <div class="dl-card">
      <h2>📲 Download Latest Version</h2>
      <p>v' . $_apkVer . ' — Official DishNet app for Android</p>
      <a href="' . $apkUrl . '" class="btn-dl">
        ⬇️ Download DishNet App
        <span>Tap · Install · Done — always the latest version</span>
      </a>
    </div>

    <a href="https://wa.me/?text=' . $waText . '" class="btn-wa">
      💬 Share Download Link via WhatsApp
    </a>

    <div class="steps-card">
      <h3>📋 How to Install the APK</h3>
      <div class="step">
        <div class="step-n">1</div>
        <div>
          <div class="step-t">Tap "Download DishNet App" above</div>
          <div class="step-d">Chrome will download the APK file to your phone</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">2</div>
        <div>
          <div class="step-t">Open the downloaded file</div>
          <div class="step-d">Pull down your notification shade and tap the downloaded file, or open your Downloads folder</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">3</div>
        <div>
          <div class="step-t">Allow installation if prompted</div>
          <div class="step-d">Android may say "Install blocked". Tap <strong>Settings</strong> → turn on <strong>"Allow from this source"</strong> → go back and tap Install</div>
        </div>
      </div>
      <div class="step">
        <div class="step-n">4</div>
        <div>
          <div class="step-t">Tap Install — done! ✅</div>
          <div class="step-d">The DishNet Africa icon appears on your home screen. Tap it to log in.</div>
        </div>
      </div>
      <div class="warn">⚠️ If Chrome says "This type of file can harm your device" — tap <strong>Download anyway</strong>. This is a normal warning for APK files outside the Play Store.</div>
    </div>

    <div class="qr-card">
      <h3>📷 Scan QR to Download on Phone</h3>
      <img src="' . $qrUrl . '" width="200" height="200" alt="QR Code">
      <div class="qr-url">' . $apkUrl . '</div>
    </div>
  </div>

  <!-- ── iPHONE VIEW: Support PWA ── -->
  <div id="view-iphone">
    <div class="pwa-hero">
      <div class="pwa-hero-icon">💬</div>
      <h2>DishNet Support App</h2>
      <p>WhatsApp inbox, CRM lookup, and tickets — fully installed on your iPhone home screen</p>
      <a href="' . $supUrl . '" class="btn-open">
        Open &amp; Install the App
        <span>Then tap Share ↑ → Add to Home Screen</span>
      </a>
    </div>

    <div class="iphone-steps">
      <h3>📲 Add to Home Screen</h3>
      <div class="iphone-step">
        <div class="iphone-step-n">1</div>
        <div>
          <div class="iphone-step-t">Open the app in Safari</div>
          <div class="iphone-step-d">Tap <strong>"Open &amp; Install the App"</strong> above. Must be Safari — not Chrome or other browsers.</div>
        </div>
      </div>
      <div class="iphone-step">
        <div class="iphone-step-n">2</div>
        <div>
          <div class="iphone-step-t">Tap the Share button <span class="share-arrow">↑</span></div>
          <div class="iphone-step-d">It is the box-with-arrow icon in the <strong>middle of the bottom toolbar</strong> in Safari</div>
        </div>
      </div>
      <div class="iphone-step">
        <div class="iphone-step-n">3</div>
        <div>
          <div class="iphone-step-t">Tap "Add to Home Screen"</div>
          <div class="iphone-step-d">Scroll through the share sheet until you see <strong>Add to Home Screen</strong> with a plus icon. Tap it.</div>
        </div>
      </div>
      <div class="iphone-step">
        <div class="iphone-step-n">4</div>
        <div>
          <div class="iphone-step-t">Tap "Add" to confirm</div>
          <div class="iphone-step-d">The DishNet Support icon appears on your iPhone home screen. Tap it to open full-screen.</div>
        </div>
      </div>
      <div class="tip-box">✅ The app works full-screen with no Safari toolbar — just like a native app. You can receive WhatsApp conversations, view CRM balances, and create tickets.</div>
    </div>

    <div class="safari-warning">
      <strong>⚠️ Must use Safari on iPhone</strong><br>
      Apple only allows "Add to Home Screen" from Safari. If you opened this page in Chrome or another browser, tap the link address, copy it, open Safari, and paste it.
    </div>
  </div>

  <!-- ── FALLBACK (desktop / unknown) ── -->
  <div id="view-fallback">
    <div class="fallback-card">
      <h2>Choose your device</h2>
      <p style="font-size:13px;color:#94A3B8;margin-bottom:16px">Open this page on your phone for the correct install instructions</p>
      <div class="fallback-row">
        <a class="fallback-btn android" onclick="showView(\'android\');return false" href="#">🤖 I have Android</a>
        <a class="fallback-btn iphone" onclick="showView(\'iphone\');return false" href="#">🍎 I have iPhone</a>
      </div>
    </div>
    <div class="footer-note">
      Android staff: download the APK from the link above<br>
      iPhone staff: use the Support PWA (Add to Home Screen)
    </div>
  </div>

<script>
function showView(v) {
  ["android","iphone","fallback"].forEach(function(x){
    var el = document.getElementById("view-"+x);
    el.style.display = (x===v) ? "flex" : "none";
  });
}
(function autoDetect(){
  var ua = navigator.userAgent;
  var isIOS     = /iPhone|iPad|iPod/.test(ua);
  var isAndroid = /Android/.test(ua);
  if (isAndroid)    showView("android");
  else if (isIOS)   showView("iphone");
  else              showView("fallback");

  // Android Chrome: native install prompt for the Operations PWA if available
  window.addEventListener("beforeinstallprompt", function(e){
    e.preventDefault();
    // Silently capture — don\'t confuse staff with a second prompt
    window._deferredInstall = e;
  });
})();
</script>
</body>
</html>';
    exit;
}

// Receipt PDF download
if ($page === 'receipt') {
    $colId = (int)($_GET['id'] ?? 0);
    $retailer = $auth->requireLogin();
    $cols = $store->load('payment_collections.json');
    $col = null;
    foreach ($cols as $c2) { if ((int)($c2['id']??0)===$colId) { $col=$c2; break; } }
    if (!$col) { flash('Receipt not found.','danger'); redirect('?page=dashboard&tab=collect_payment'); }
    // Generate simple HTML receipt
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Receipt #'.$col['id'].'</title>
    <style>*{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
    body{padding:40px;max-width:400px;margin:0 auto;}
    .hdr{text-align:center;border-bottom:2px solid #333;padding-bottom:16px;margin-bottom:16px;}
    .hdr h1{font-size:18px;}
    .hdr p{font-size:11px;color:#666;margin-top:4px;}
    .row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px;}
    .row.total{border-top:2px solid #333;margin-top:12px;padding-top:12px;font-weight:800;font-size:16px;}
    .footer{text-align:center;margin-top:24px;font-size:10px;color:#999;border-top:1px dashed #ccc;padding-top:12px;}
    @media print{body{padding:20px;}}
    </style></head><body>
    <div class="hdr"><h1>DishNet Africa Ltd</h1><p>Payment Receipt</p></div>
    <div class="row"><span>Receipt #</span><span>'.$col['id'].'</span></div>
    <div class="row"><span>Date</span><span>'.h($col['created_at']??'').'</span></div>
    <div class="row"><span>Customer</span><span>'.h($col['customer_name']??'').'</span></div>
    <div class="row"><span>CRM ID</span><span>'.h($col['crm_customer_id']??'-').'</span></div>
    <div class="row"><span>Method</span><span>'.h($col['method']??'Cash').'</span></div>
    <div class="row"><span>Collected By</span><span>'.h($col['retailer_name']??'').'</span></div>
    <div class="row total"><span>Amount Paid</span><span>$'.number_format($col['amount']??0, 2).'</span></div>
    '.(!empty($col['note']) ? '<div class="row"><span>Note</span><span>'.h($col['note']).'</span></div>' : '').'
    <div class="footer">
        <p>Thank you for your payment!</p>
        <p style="margin-top:6px;">DishNet Africa Ltd | crm.dishnetafrica.com</p>
    </div>
    <script>window.onload=function(){window.print();}</script>
    </body></html>';
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// STOCK MANAGEMENT API
// URL: public.php?page=stock_api&action=stock_categories (etc.)
// ══════════════════════════════════════════════════════════════════════════════
if ($page === 'stock_api') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

    // Auth: try Bearer token first (PWA), then session (webview)
    $retailer = $auth->tokenAuth();
    if (!$retailer) {
        $retailer = $auth->requireLogin();
    }
    if (!$retailer) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }

    $act  = $_GET['action'] ?? '';
    $met  = $_SERVER['REQUEST_METHOD'];
    $body = ($met === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $rid  = (int)($retailer['id'] ?? 0);
    $isAdmin = !empty($retailer['is_admin']);
    $ok2  = function($d,$m='OK',$c=200){ http_response_code($c); echo json_encode(['status'=>'success','message'=>$m,'data'=>$d]); exit; };
    $er2  = function($m,$c=400){ http_response_code($c); echo json_encode(['status'=>'error','message'=>$m]); exit; };

    require_once dirname(__DIR__) . '/lib/StockService.php';
    try {
        $_stockSvc = StockService::fromStore($store, $dataDir);
        $_stockSvc->ensureTables();
    } catch (\Throwable $e) {
        $er2('Stock init failed: ' . $e->getMessage(), 500);
    }

    $_stockRoles = ['admin','accountant','support_leader','field_accountant','sales','sales_staff','field_agent','collection','support'];
    $_stockIsPriv = $isAdmin || in_array($retailer['role'] ?? '', $_stockRoles, true);

    try {
        require dirname(__DIR__) . '/includes/api/api_stock.php';
    } catch (\Throwable $_stockEx) {
        $er2('Stock API error: ' . $_stockEx->getMessage(), 500);
    }
    $er2("Unknown stock action: {$act}", 404);
}

// ══════════════════════════════════════════════════════════════════════════════
// WEBHOOK HANDLER - Route to webhook.php
// URL: public.php?page=webhook (or public.php?page=webhook&source=splynx)
// ══════════════════════════════════════════════════════════════════════════════
if ($page === 'webhook') {
    // Include and run webhook.php directly
    require dirname(__DIR__) . '/webhook.php';
    exit;
}
