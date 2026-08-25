<?php
// 
// ADMIN (retailers, settings, plugins, APKs, catalog)
// 

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='update_crm_username'){
    $admin=$auth->requireAdmin();
    $result=$kyc->updateCrmUsername((int)($_POST['app_id']??0),trim($_POST['crm_username']??''),$admin);
    flash($result['message'],$result['success']?'success':'danger');
    redirect('?page=dashboard&tab=all_apps');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='create_retailer'){
    $admin=$auth->requireAdmin();
    if($store->findOne('retailers.json','email',strtolower($_POST['email']??''))){
        flash('Email already exists.','danger');
    } else {
        $_rawPwd = trim($_POST['password'] ?? '');
        $newRetailerData = [
            'name'            => trim($_POST['name']  ?? ''),
            'email'           => trim($_POST['email'] ?? ''),
            'phone'           => trim($_POST['phone'] ?? ''),
            'password'        => $_rawPwd ?: '123456',
            'wallet'          => (float)($_POST['wallet'] ?? 0),
            'is_admin'        => !empty($_POST['is_admin']),
            'role'            => trim($_POST['role'] ?? 'sales'),
            'is_employee'     => ($_POST['is_employee'] ?? '1') === '1',
            'commission_type' => ($_POST['is_employee']??'1')==='0' ? trim($_POST['commission_type']??'percent') : 'none',
            'commission_rate' => ($_POST['is_employee']??'1')==='0' ? (float)($_POST['commission_rate']??0) : 0,
            'must_change_pwd' => true,
        ];
        $auth->createRetailer($newRetailerData);
        // Auto-create or link the retailer in Org 7 (FTTH Project)
        $newRetailer = $store->findOne('retailers.json','email',strtolower($newRetailerData['email']));
        if($newRetailer) $ftthCrm->ensureRetailerClient($newRetailer);
        flash('Retailer created and synced to FTTH CRM (Org 7).','success');
    }
    redirect('?page=dashboard&tab=retailers');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='import_crm_staff'){
    $admin=$auth->requireAdmin();
    $crmEmail = strtolower(trim($_POST['crm_email'] ?? ''));
    $crmName = trim($_POST['crm_name'] ?? '');
    $crmPhone = trim($_POST['crm_phone'] ?? '');
    $crmId = trim($_POST['crm_id'] ?? '');
    $importRole = $_POST['import_role'] ?? 'sales';
    if (!$crmEmail && !$crmName) { flash('No name or email from CRM.','danger'); redirect('?page=dashboard&tab=retailers'); }
    // Use email or generate one from CRM ID
    if (!$crmEmail) $crmEmail = 'crm' . $crmId . '@dishnetafrica.com';
    if ($store->findOne('retailers.json','email',$crmEmail)) {
        flash('Staff with email '.$crmEmail.' already exists.','warning');
    } else {
        $tempPw = 'DishNet' . $crmId . '!';
        $auth->createRetailer([
            'name'     => $crmName,
            'email'    => $crmEmail,
            'phone'    => $crmPhone,
            'password' => $tempPw,
            'wallet'   => 0,
            'is_admin' => ($importRole === 'admin'),
            'role'     => $importRole,
            'crm_id'   => $crmId,
        ]);
        flash("Imported {$crmName} as {$importRole}. Temp password: {$tempPw}",'success');
    }
    redirect('?page=dashboard&tab=retailers');
}

//  Plugin Safe-Update Handler 
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='plugin_update') {
    $auth->requireAdmin();
    if (!csrfCheck()) { flash('Invalid request.','danger'); redirect('?page=dashboard&tab=updater'); }

    if (empty($_FILES['update_zip']['tmp_name'])) {
        flash('No ZIP file selected.','danger');
        redirect('?page=dashboard&tab=updater');
    }

    $zipFile = $_FILES['update_zip']['tmp_name'];
    $zipName = $_FILES['update_zip']['name'];

    // Validate ZIP
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        flash('Invalid or corrupt ZIP file.','danger');
        redirect('?page=dashboard&tab=updater');
    }
    // Must have manifest.json at root level
    if ($zip->locateName('manifest.json') === false) {
        $zip->close();
        flash('Plugin manifest.json not found in ZIP. This does not look like a valid plugin update.','danger');
        redirect('?page=dashboard&tab=updater');
    }
    // Read new version from manifest
    $newManifest = json_decode($zip->getFromName('manifest.json'), true);
    $newVersion  = $newManifest['information']['version'] ?? 'unknown';
    $curManifest = json_decode(file_get_contents(__DIR__.'/manifest.json'), true);
    $curVersion  = $curManifest['information']['version'] ?? 'unknown';
    $zip->close();

    //  PRE-UPDATE SELF-TEST 
    // Run test suite against CURRENT code before touching anything.
    // If tests fail, block the update so we know the baseline is broken.
    $skipTests = isset($_POST['skip_tests']) && $_POST['skip_tests'] === '1';
    if (!$skipTests) {
        require_once __DIR__ . '/lib/DishNetTestSuite.php';
        // Load helper functions needed by tests
        if (!function_exists('human_time_diff')) {
            $publicSrc = file_get_contents(__DIR__.'/public.php');
            if (preg_match('/function human_time_diff\(.*?
\}/s', $publicSrc, $hm)) {
                eval($hm[0]);
            }
        }
        $testSuite   = new DishNetTestSuite($dataDir);
        $testResults = $testSuite->run();

        // Save test results to data/ for display
        file_put_contents($dataDir.'/last_test_run.json', json_encode(array_merge($testResults, [
            'ran_at'    => date('Y-m-d H:i:s'),
            'triggered' => 'pre_update',
            'zip'       => $zipName,
        ]), JSON_PRETTY_PRINT));

        if (!$testResults['ok']) {
            $nFailed = $testResults['failed'];
            flash(" Update BLOCKED  {$nFailed} test(s) failed. Fix issues before updating. Check Test Results tab.", 'danger');
            redirect('?page=dashboard&tab=updater&wsv=tests');
        }
    }

    //  Step 1: Auto-backup current plugin to data/backups/ 
    $backupDir = $dataDir . '/plugin_backups';
    if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
    $backupName = $backupDir . '/backup_v' . preg_replace('/[^a-zA-Z0-9._-]/','_',$curVersion) . '_' . date('Ymd_His') . '.zip';

    $backupZip = new ZipArchive();
    if ($backupZip->open($backupName, ZipArchive::CREATE) === true) {
        $pluginDir = __DIR__;
        $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginDir));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($pluginDir) + 1);
                // Skip data/ dir (it's already persistent), skip backup zips
                if (str_starts_with($relative, 'data/')) continue;
                $backupZip->addFile($file->getPathname(), $relative);
            }
        }
        $backupZip->close();
    }

    //  Step 2: Extract new ZIP over plugin directory 
    // Preserve data/ directory  only overwrite PHP/JS/HTML/JSON (not data files)
    $updateZip = new ZipArchive();
    $updateZip->open($zipFile);
    $extractErrors = [];
    for ($i = 0; $i < $updateZip->numFiles; $i++) {
        $entry    = $updateZip->getNameIndex($i);
        $destFile = __DIR__ . '/' . $entry;
        // NEVER overwrite data directory files
        if (str_starts_with($entry, 'data/') || str_starts_with($entry, 'data\\')) continue;
        // Create directories
        if (str_ends_with($entry, '/') || str_ends_with($entry, '\\')) {
            if (!is_dir($destFile)) @mkdir($destFile, 0755, true);
            continue;
        }
        @mkdir(dirname($destFile), 0755, true);
        $bytes = file_put_contents($destFile, $updateZip->getFromIndex($i));
        if ($bytes === false) $extractErrors[] = $entry;
    }
    $updateZip->close();

    // Log the update
    $updateLog = $dataDir . '/update_log.json';
    $logEntries = file_exists($updateLog) ? (json_decode(file_get_contents($updateLog), true) ?? []) : [];
    array_unshift($logEntries, [
        'from_version' => $curVersion,
        'to_version'   => $newVersion,
        'zip_name'     => $zipName,
        'backup_file'  => basename($backupName),
        'errors'       => $extractErrors,
        'applied_by'    => $retailer['name'] ?? 'admin',
        'applied_at'    => date('Y-m-d H:i:s'),
        'test_results'  => isset($testResults) ? ['passed'=>$testResults['passed'],'failed'=>$testResults['failed'],'skipped'=>$testResults['skipped'],'ok'=>$testResults['ok']] : ['skipped_by_user'=>true],
    ]);
    $logEntries = array_slice($logEntries, 0, 30);
    file_put_contents($updateLog, json_encode($logEntries, JSON_PRETTY_PRINT));

    if (!empty($extractErrors)) {
        flash('Update applied with ' . count($extractErrors) . ' file error(s). Backup saved as: ' . basename($backupName) . '. Check update log.', 'warning');
    } else {
        flash(" Updated v{$curVersion}  v{$newVersion} successfully. Backup saved. Reload to see changes.", 'success');
    }
    redirect('?page=dashboard&tab=updater');
}

//  Rollback to previous backup 
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='rollback') {
    $auth->requireAdmin();
    if (!csrfCheck()) { flash('Invalid request.','danger'); redirect('?page=dashboard&tab=updater'); }
    $backupFile = basename(trim($_POST['backup_file'] ?? ''));
    $backupPath = $dataDir . '/plugin_backups/' . $backupFile;
    if (!$backupFile || !file_exists($backupPath)) {
        flash('Backup file not found.', 'danger');
        redirect('?page=dashboard&tab=updater');
    }
    $rbZip = new ZipArchive();
    if ($rbZip->open($backupPath) !== true) {
        flash('Could not open backup ZIP.', 'danger');
        redirect('?page=dashboard&tab=updater');
    }
    for ($i = 0; $i < $rbZip->numFiles; $i++) {
        $entry    = $rbZip->getNameIndex($i);
        $destFile = __DIR__ . '/' . $entry;
        if (str_starts_with($entry, 'data/') || str_ends_with($entry,'/')) continue;
        @mkdir(dirname($destFile), 0755, true);
        file_put_contents($destFile, $rbZip->getFromIndex($i));
    }
    $rbZip->close();
    flash(" Rolled back to {$backupFile} successfully. Reload the page.", 'success');
    redirect('?page=dashboard&tab=updater');
}

//  Set Minimum App Version 
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='set_min_app_version') {
    $admin = $auth->requireAdmin();
    if (!csrfCheck()) { flash('Invalid request.','danger'); redirect('?page=dashboard&tab=android_app'); }
    $minVer = trim($_POST['min_version'] ?? '');
    if (!preg_match('/^\d+\.\d+(\.\d+)?$/', $minVer)) {
        flash('Invalid version format. Use X.Y or X.Y.Z (e.g. 2.4)', 'danger');
        redirect('?page=dashboard&tab=android_app');
    }
    $meta = $store->load('android_app_meta.json') ?? [];
    $meta['min_version'] = $minVer;
    $store->save('android_app_meta.json', $meta);
    logActivity($dataDir, 'app_min_version', "Minimum app version set to $minVer", $admin['name'] ?? 'admin');
    flash("Minimum app version set to $minVer. Agents with older versions will see an update banner.", 'success');
    redirect('?page=dashboard&tab=android_app');
}

//  Android APK Upload 
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='upload_apk') {
    $admin = $auth->requireAdmin();
    if (empty($_FILES['apk_file']['tmp_name'])) {
        flash('No APK file selected.', 'danger');
        redirect('?page=dashboard&tab=android_app');
    }
    $file    = $_FILES['apk_file'];
    $tmpPath = $file['tmp_name'];
    $origName= $file['name'];
    $size    = $file['size'];

    // Validate: must be .apk and under 200 MB
    if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'apk') {
        flash('Only .apk files are allowed.', 'danger');
        redirect('?page=dashboard&tab=android_app');
    }
    if ($size > 200 * 1024 * 1024) {
        flash('APK file exceeds 200 MB limit.', 'danger');
        redirect('?page=dashboard&tab=android_app');
    }

    // Read version from uploaded output-metadata.json if provided
    $apkVersion   = trim($_POST['apk_version'] ?? '') ?: '1.0';
    $apkChangelog = trim($_POST['apk_changelog'] ?? '');
    $apkVersionCode = '';
    $apkBuildVariant = '';
    if (!empty($_FILES['apk_metadata']['tmp_name']) && $_FILES['apk_metadata']['error'] === UPLOAD_ERR_OK) {
        $metaRaw = file_get_contents($_FILES['apk_metadata']['tmp_name']);
        $metaJson = $metaRaw ? json_decode($metaRaw, true) : null;
        if (is_array($metaJson)) {
            $el = $metaJson['elements'][0] ?? [];
            if (!empty($el['versionName'])) $apkVersion  = $el['versionName'];
            if (!empty($el['versionCode'])) $apkVersionCode = (string)$el['versionCode'];
            $apkBuildVariant = $metaJson['variantName'] ?? '';
        }
    }
    $safeVersion  = preg_replace('/[^a-zA-Z0-9._-]/', '', $apkVersion);
    // Include time in filename  guarantees uniqueness even for same version uploaded twice same day
    $generatedName = 'DishNet-Africa-v' . $safeVersion . '-' . date('Y-m-d_His') . '.apk';
    $destPath      = $dataDir . '/' . $generatedName;

    // Archive previous APK entry into history (keep the file, just update meta pointer)
    $prevMeta = $store->load('android_app_meta.json') ?? [];
    if (!empty($prevMeta['stored_filename'])) {
        $prevFilePath = $dataDir . '/' . $prevMeta['stored_filename'];
        if (file_exists($prevFilePath)) { // always a distinct file now (timestamp in name)
            // Add to history log
            $apkHistory = $store->load('android_app_history.json') ?: [];
            if (!is_array($apkHistory)) $apkHistory = [];
            array_unshift($apkHistory, [
                'version'         => $prevMeta['version']         ?? '',
                'version_code'    => $prevMeta['version_code']    ?? '',
                'build_variant'   => $prevMeta['build_variant']   ?? '',
                'stored_filename' => $prevMeta['stored_filename'],
                'size_bytes'      => $prevMeta['size_bytes']       ?? 0,
                'uploaded_at'     => $prevMeta['uploaded_at']      ?? '',
                'uploaded_by'     => $prevMeta['uploaded_by']      ?? '',
                'archived_at'     => date('Y-m-d H:i:s'),
            ]);
            // Keep only last 10 versions in history
            $apkHistory = array_slice($apkHistory, 0, 10);
            $store->save('android_app_history.json', $apkHistory);
        }
    }
    // Remove placeholder APK from plugin root if present (shipped in zip, must not interfere with data/ versions)
    if (file_exists(__DIR__ . '/dishnet-app.apk')) {
        @unlink(__DIR__ . '/dishnet-app.apk');
    }

    if (!move_uploaded_file($tmpPath, $destPath)) {
        flash('Failed to save APK. Check server write permissions.', 'danger');
        redirect('?page=dashboard&tab=android_app');
    }

    // Save metadata  stored_filename is the key used everywhere to locate the file
    $apkMeta = [
        'version'         => $apkVersion,
        'version_code'    => $apkVersionCode,
        'build_variant'   => $apkBuildVariant,
        'changelog'       => $apkChangelog,
        'filename'        => $generatedName,
        'stored_filename' => $generatedName,
        'original_name'   => $origName,
        'size_bytes'      => $size,
        'uploaded_at'     => date('Y-m-d H:i:s'),
        'uploaded_by'     => $admin['name'] ?? $admin['username'] ?? 'admin',
    ];
    $store->save('android_app_meta.json', $apkMeta);

    logActivity($dataDir, 'apk_uploaded', "APK uploaded: v{$apkVersion} ({$generatedName})", 'Size: '.round($size/1024/1024,1).'MB');
    flash("\u2705 APK uploaded \u2014 v{$apkVersion} \u00b7 {$generatedName} (".round($size/1024/1024,1)."MB)");
    redirect('?page=dashboard&tab=android_app');
}

//  Set Any Archived Version as Current 
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='set_current_apk') {
    $admin = $auth->requireAdmin();
    if (!csrfCheck()) { flash('Invalid request.', 'danger'); redirect('?page=dashboard&tab=android_app'); }

    $targetFile = basename($_POST['target_filename'] ?? '');
    if (!$targetFile || !preg_match('/^DishNet-Africa-v[\w._-]+-\d{4}-\d{2}-\d{2}(_\d{6})?\.apk$/', $targetFile)) {
        flash('Invalid filename.', 'danger');
        redirect('?page=dashboard&tab=android_app');
    }

    $targetPath = $dataDir . '/' . $targetFile;
    if (!file_exists($targetPath)) {
        flash('APK file not found on server.', 'danger');
        redirect('?page=dashboard&tab=android_app');
    }

    // Load history to find the full metadata for the target version
    $allHistory  = $store->load('android_app_history.json') ?: [];
    $currentMeta = $store->load('android_app_meta.json') ?? [];
    $targetMeta  = null;
    $targetIndex = null;

    foreach ($allHistory as $i => $hv) {
        if (($hv['stored_filename'] ?? '') === $targetFile) {
            $targetMeta  = $hv;
            $targetIndex = $i;
            break;
        }
    }

    if ($targetMeta === null) {
        flash('Version not found in history.', 'danger');
        redirect('?page=dashboard&tab=android_app');
    }

    // Move current  history (if there is one)
    if (!empty($currentMeta['stored_filename'])) {
        $newHistory = array_values(array_filter($allHistory, fn($h) => ($h['stored_filename'] ?? '') !== $targetFile));
        array_unshift($newHistory, [
            'version'         => $currentMeta['version']      ?? '',
            'version_code'    => $currentMeta['version_code'] ?? '',
            'build_variant'   => $currentMeta['build_variant']?? '',
            'stored_filename' => $currentMeta['stored_filename'],
            'size_bytes'      => $currentMeta['size_bytes']   ?? 0,
            'uploaded_at'     => $currentMeta['uploaded_at']  ?? '',
            'uploaded_by'     => $currentMeta['uploaded_by']  ?? '',
            'archived_at'     => date('Y-m-d H:i:s'),
        ]);
        $store->save('android_app_history.json', array_slice($newHistory, 0, 10));
    } else {
        // No current  just remove target from history, nothing to push back
        $newHistory = array_values(array_filter($allHistory, fn($h) => ($h['stored_filename'] ?? '') !== $targetFile));
        $store->save('android_app_history.json', $newHistory);
    }

    // Promote target to current
    $newCurrentMeta = [
        'version'         => $targetMeta['version']         ?? '',
        'version_code'    => $targetMeta['version_code']    ?? '',
        'build_variant'   => $targetMeta['build_variant']   ?? '',
        'changelog'       => $targetMeta['changelog']       ?? '',
        'filename'        => $targetFile,
        'stored_filename' => $targetFile,
        'original_name'   => $targetMeta['original_name']   ?? $targetFile,
        'size_bytes'      => $targetMeta['size_bytes']      ?? filesize($targetPath),
        'uploaded_at'     => $targetMeta['uploaded_at']     ?? '',
        'uploaded_by'     => $targetMeta['uploaded_by']     ?? '',
        'set_current_at'  => date('Y-m-d H:i:s'),
        'set_current_by'  => $admin['name'] ?? $admin['username'] ?? 'admin',
    ];
    $store->save('android_app_meta.json', $newCurrentMeta);

    $ver = $newCurrentMeta['version'];
    logActivity($dataDir, 'apk_set_current', "APK set as current: v{$ver} ({$targetFile})", 'By: '.($admin['name']??'admin'));
    flash(" v{$ver} is now the active download  all download links updated.");
    redirect('?page=dashboard&tab=android_app');
}

//  Android APK Delete 
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete_apk') {
    $admin = $auth->requireAdmin();
    if (!csrfCheck()) { flash('Invalid request.', 'danger'); redirect('?page=dashboard&tab=android_app'); }
    $delMeta  = $store->load('android_app_meta.json') ?? [];
    $delFile  = !empty($delMeta['stored_filename']) ? $dataDir.'/'.$delMeta['stored_filename'] : $dataDir.'/dishnet-app.apk';
    if (file_exists($delFile)) {
        @rename($delFile, $delFile . '.bak');
        $store->save('android_app_meta.json', []);
        logActivity($dataDir, 'apk_deleted', 'APK file deleted by admin', '');
        flash('APK deleted. The download page will show "not available" until a new APK is uploaded.');
    } else {
        flash('No APK found to delete.', 'danger');
    }
    redirect('?page=dashboard&tab=android_app');
}

//  Import Leads from CSV 
// Accepts the same CSV format exported from the calling system.
// Duplicate detection: skip rows where email OR phone already exists in leads.json.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='import_leads_csv') {
    $admin = $auth->requireAdmin();
    if (empty($_FILES['leads_csv']['tmp_name'])) { flash('No file uploaded.','danger'); redirect('?page=dashboard&tab=all_leads'); }

    $tmpPath = $_FILES['leads_csv']['tmp_name'];
    $handle  = fopen($tmpPath, 'r');
    if (!$handle) { flash('Could not read file.','danger'); redirect('?page=dashboard&tab=all_leads'); }

    // Read header row (strip BOM)
    $rawHeader = fgetcsv($handle);
    $header    = array_map(fn($h) => trim(preg_replace('/^\xEF\xBB\xBF/','',$h)), $rawHeader);
    $colIdx    = array_flip(array_map('strtolower', $header));

    $statusMap  = ['new'=>'open','contacted'=>'contacted','quoted'=>'quoted','qualified'=>'qualified','converted'=>'won','lost'=>'lost'];
    $serviceMap = ['starlink'=>'starlink','fiber'=>'fiber','sim'=>'sim','hardware'=>'starlink','unknown'=>'starlink'];
    $sourceMap  = ['BBC'=>'BBC','Aida'=>'Cold Call','Justus'=>'Cold Call','Mecklyne'=>'Cold Call',
                   'meckline'=>'Cold Call','1234'=>'Cold Call','Sales'=>'Cold Call','Admin'=>'Cold Call','Bhavin'=>'Cold Call'];

    $existing = $store->load('leads.json');
    $existingPhones = array_column($existing, 'phone');
    $existingEmails = array_filter(array_column($existing, 'email'));
    $nextId = $store->nextId('leads.json');

    $added = 0; $skipped = 0; $rows = [];

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 3) { $skipped++; continue; }
        $r = [];
        foreach ($header as $i => $col) $r[strtolower($col)] = $row[$i] ?? '';

        $name  = trim($r['name'] ?? '');
        $phone = trim($r['phone'] ?? '');
        $email = strtolower(trim($r['email'] ?? ''));

        if (!$name && !$phone) { $skipped++; continue; }

        // Dedup by phone or email
        if ($phone && in_array($phone, $existingPhones)) { $skipped++; continue; }
        if ($email && in_array($email, $existingEmails)) { $skipped++; continue; }

        $parts   = explode(' ', $name, 2);
        $status  = $statusMap[strtolower($r['status'] ?? 'new')] ?? 'open';
        $service = $serviceMap[strtolower($r['interest'] ?? 'starlink')] ?? 'starlink';
        $agent   = $r['agent'] ?? '';
        $source  = $sourceMap[$agent] ?? ($agent ? 'Cold Call' : 'Social Media');

        // Phone normalisation
        if (ctype_digit($phone) && strlen($phone)===9 && $phone[0]==='9') $phone = '+211'.$phone;
        elseif (ctype_digit($phone) && strlen($phone)===12 && substr($phone,0,3)==='211') $phone = '+'.$phone;

        $calls = (int)($r['total calls'] ?? $r['calls'] ?? 0);
        $priority = $calls >= 5 ? 'high' : ($calls >= 2 ? 'medium' : 'low');

        $lead = [
            'id'              => $nextId++,
            'retailer_id'     => (int)$admin['id'],
            'retailer_name'   => $admin['name'],
            'customer_name'   => $name,
            'firstname'       => $parts[0],
            'lastname'        => $parts[1] ?? '',
            'phone'           => $phone,
            'email'           => $email,
            'address'         => $r['location'] ?? '',
            'service_type'    => $service,
            'source'          => $source,
            'source_detail'   => 'CSV Import' . ($agent ? "  Agent: {$agent}" : ''),
            'priority'        => $priority,
            'status'          => $status,
            'qualified'       => $status === 'qualified',
            'sales_type'      => 'Cash',
            'connectivity_type' => 'New Connection',
            'notes'           => $calls > 0 ? "Total calls: {$calls}" : '',
            'created_at'      => $r['created'] ?? date('Y-m-d H:i:s'),
            'updated_at'      => $r['updated'] ?? date('Y-m-d H:i:s'),
            'csv_id'          => $r['id'] ?? '',
        ];
        if ($status === 'won') $lead['won_at'] = $r['updated'] ?? date('Y-m-d H:i:s');
        if ($lead['qualified']) { $lead['qualified_by'] = 'CSV Import'; $lead['qualified_at'] = $lead['created_at']; }

        $existing[] = $lead;
        $existingPhones[] = $phone;
        if ($email) $existingEmails[] = $email;
        $added++;
    }
    fclose($handle);

    if ($added > 0) {
        $store->save('leads.json', $existing);
        flash(" Imported {$added} leads. Skipped {$skipped} (duplicates or empty rows).", 'success');
    } else {
        flash("No new leads to import. All {$skipped} rows were duplicates or empty.", 'warning');
    }
    redirect('?page=dashboard&tab=all_leads');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='topup_wallet'){
    $admin=$auth->requireAdmin();
    $rId=(int)($_POST['retailer_id']??0);$amt=(float)($_POST['amount']??0);$note=trim($_POST['note']??'Admin top-up');
    if($amt>0&&$rId){
        // 1. Credit plugin wallet (idempotent)
        $balBeforeTopup = $wallet->getBalance($rId);
        $trx = $wallet->credit($rId,$amt,$note,$admin['name'],'TOPUP-ADMIN-'.$rId.'-'.date('YmdHis'));
        $newBalance = $trx['curr_balance'] ?? $wallet->getBalance($rId);
        // Audit trail  before/after
        logActivity($dataDir,'wallet_topup','Wallet topped up',
            'Retailer #'.$rId.' | $'.number_format($balBeforeTopup,2).'  $'.number_format($newBalance,2)
            .' (+$'.number_format($amt,2).') by '.$admin['name'].'  '.$note);
        $store->appendWithId('activity_log.json',[
            'event'         => 'wallet_topup',
            'actor'         => $admin['name'],
            'actor_id'      => $rId,
            'action'        => 'CREDIT',
            'entity'        => 'wallet',
            'entity_id'     => $rId,
            'amount'        => $amt,
            'balance_before'=> round($balBeforeTopup,2),
            'balance_after' => round($newBalance,2),
            'note'          => $note,
            'detail'        => 'Top-up $'.number_format($amt,2).' | $'.number_format($balBeforeTopup,2).'  $'.number_format($newBalance,2),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        $retailer   = $store->findOne('retailers.json','id',$rId);

        // 2. Sync balance to Org 7 CRM custom attribute + create CRM invoice
        if($retailer){
            $ftthCrm->syncWalletBalance($retailer, $newBalance);
            $ftthCrm->createTopupInvoice($retailer, $amt, $note, $admin['name']);
            // 3. WhatsApp notification to retailer
            $notify->walletToppedUp($retailer, $amt, $newBalance, $note);
        }

        flash('$'.number_format($amt,2).' credited. CRM invoice created & WhatsApp sent.','success');
    } else flash('Invalid amount.','danger');
    redirect('?page=dashboard&tab=wallet_admin');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save_settings'){
    $auth->requireAdmin();
    $config['crm_base_url']=trim($_POST['crm_base_url']??$config['crm_base_url']);
    $config['crm_auth_token']=trim($_POST['crm_auth_token']??'');
    $config['auto_sync_interval']=(int)($_POST['auto_sync_interval']??60);
    $config['cron_sync_path']=trim($_POST['cron_sync_path']??(__DIR__.'/cron_sync.php'));
    $config['wallet_sync_interval_minutes']=(int)($_POST['wallet_sync_interval_minutes']??360);
    $config['ucrm_auto_pull_enabled'] = ($_POST['ucrm_auto_pull_enabled']??'1') === '1';
    $config['kyc_auto_quote_enabled'] = ($_POST['kyc_auto_quote_enabled']??'1') === '1';
    $config['kyc_quote_validity_days'] = max(1, min(90,(int)($_POST['kyc_quote_validity_days']??7)));
    $config['kyc_quote_notes_prefix']  = trim($_POST['kyc_quote_notes_prefix'] ?? '');
    // Max amount for auto-quote: if cart total exceeds this, skip auto-quote (agent creates manually)
    $config['kyc_auto_quote_max_amount'] = max(0, (float)($_POST['kyc_auto_quote_max_amount'] ?? 0));
    // Username sequence starting points (so new plugin continues from old system's last number)
    $config['star_seq_start'] = max(0, (int)($_POST['star_seq_start'] ?? $config['star_seq_start'] ?? 0));
    $config['ftth_seq_start'] = max(0, (int)($_POST['ftth_seq_start'] ?? $config['ftth_seq_start'] ?? 0));
    // Quote reference number config
    $rawQPrefix = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', trim($_POST['quote_prefix'] ?? 'QUO')));
    $config['quote_prefix']    = $rawQPrefix ?: 'QUO';
    $config['quote_seq_start'] = max(0, (int)($_POST['quote_seq_start'] ?? $config['quote_seq_start'] ?? 0));

    //  Finance Audit Controls 
    $config['advance_carry_limit']          = max(0,    (float)($_POST['advance_carry_limit']         ?? 100));
    $config['advance_receipt_grace_hours']  = max(1,    (int)  ($_POST['advance_receipt_grace_hours'] ?? 24));
    $config['advance_drift_tolerance']      = max(0,    (float)($_POST['advance_drift_tolerance']     ?? 0.01));
    $config['agent_float_warn_threshold']   = max(0,    (float)($_POST['agent_float_warn_threshold']  ?? 50));
    $config['advance_reconcile_project']    = in_array($_POST['advance_reconcile_project'] ?? '', ['dishnet','4g'], true)
        ? $_POST['advance_reconcile_project'] : 'dishnet';
    // Per-purpose aging hours
    $agingPurposes = ['fuel','transport','allowance','site_work','misc','parts','equipment'];
    $agingHoursSave = [];
    foreach ($agingPurposes as $agP) {
        $posted = (int)($_POST['aging_' . $agP] ?? 0);
        if ($posted > 0) $agingHoursSave[$agP] = $posted;
    }
    if (!empty($agingHoursSave)) $config['advance_aging_hours'] = $agingHoursSave;
    $config['ucrm_auto_pull_hour']    = max(0, min(23,(int)($_POST['ucrm_auto_pull_hour']??3)));
    $config['wallet_sync_cron_path']=trim($_POST['wallet_sync_cron_path']??(__DIR__.'/cron_wallet_sync.php'));
    $config['cron_maintenance_path'] = trim($_POST['cron_maintenance_path'] ?? (__DIR__.'/cron_maintenance.php'));
    // Parse sales_persons: one per line, trim and filter blanks
    $rawSP = $_POST['sales_persons_text'] ?? '';
    $spList = array_values(array_filter(array_map('trim', explode("\n", $rawSP))));
    if (!empty($spList)) $config['sales_persons'] = $spList;
    // Parse accessories: one per line, trim and filter blanks
    $rawAcc = $_POST['accessories_text'] ?? '';
    $accList = array_values(array_filter(array_map('trim', explode("\n", $rawAcc))));
    if (!empty($accList)) $config['accessories'] = $accList;
    $config['commission_rate'] = (float)($_POST['commission_rate'] ?? 5);
    // 4G BlueCard feed settings
    if (!empty($_POST['lte_feed_url']))  $config['lte_feed_url']   = trim($_POST['lte_feed_url']);
    if (!empty($_POST['lte_feed_token'])) $config['lte_feed_token'] = trim($_POST['lte_feed_token']);
    if (isset($_POST['magma_url']))   $config['magma_url']   = trim($_POST['magma_url']);
    if (isset($_POST['magma_token'])) $config['magma_token'] = trim($_POST['magma_token']);
    $config['lte_suspend_grace_days']    = (int)($_POST['lte_suspend_grace_days'] ?? ($config['lte_suspend_grace_days'] ?? 0));
    $config['lte_commission_rate']      = (float)($_POST['lte_commission_rate']      ?? $config['commission_rate']);
    $config['starlink_commission_rate'] = (float)($_POST['starlink_commission_rate'] ?? $config['commission_rate']);
    $config['fiber_commission_rate']    = (float)($_POST['fiber_commission_rate']    ?? $config['commission_rate']);
    $config['lte_suspend_grace_days']   = (int)($_POST['lte_suspend_grace_days']     ?? 0);
    $config['lte_cron_path']            = trim($_POST['lte_cron_path']               ?? (__DIR__.'/cron_lte.php'));
    $config['commission_on_collection'] = isset($_POST['commission_on_collection']);
    $config['commission_on_kyc'] = isset($_POST['commission_on_kyc']);
    // Large transaction approval threshold
    if (isset($_POST['large_txn_threshold'])) {
        $config['large_txn_threshold'] = max(0, (float)$_POST['large_txn_threshold']);
    }
    if (!isset($config['retailer_targets'])) $config['retailer_targets'] = [];
    $config['retailer_targets']['default'] = (float)($_POST['default_target'] ?? 0);
    // FTTH Org 7 attribute IDs
    $config['ftth_attr_wallet_balance'] = (int)($_POST['ftth_attr_wallet_balance'] ?? 101);
    $config['ftth_attr_retailer_id']    = (int)($_POST['ftth_attr_retailer_id']    ?? 102);
    $config['ftth_attr_retailer_role']  = (int)($_POST['ftth_attr_retailer_role']  ?? 103);
    // WhatsApp  via wa-whatsappsender plugin endpoint
    $config['wa_plugin_url']           = trim($_POST['wa_plugin_url']        ?? '');
    $config['wa_app_key']              = trim($_POST['wa_app_key']           ?? '');
    $config['wa_accounts_app_key']     = trim($_POST['wa_accounts_app_key']  ?? '');
    $config['evo_accounts_instance_name'] = trim($_POST['evo_accounts_instance_name'] ?? '');
    $config['wa_auth_key']             = trim($_POST['wa_auth_key']          ?? '');
    $config['whatsapp_admin_phone']    = trim($_POST['whatsapp_admin_phone'] ?? '');
    $config['wa_support_number']       = trim($_POST['wa_support_number']    ?? '');
    $config['wa_accounts_number']      = trim($_POST['wa_accounts_number']   ?? '');
    // v4.21.114: emergency "route all via Accounts" toggle (Support number blocked)
    if (isset($_POST['wa_force_accounts'])) {
        $config['wa_force_accounts'] = ($_POST['wa_force_accounts'] === '1' || $_POST['wa_force_accounts'] === 'true');
    }
    // v4.9.20: PDF document sending toggle (false = text-only notifications)
    if (isset($_POST['wa_send_pdf'])) {
        $config['wa_send_pdf'] = ($_POST['wa_send_pdf'] === '1' || $_POST['wa_send_pdf'] === 'true');
    }
    // Evolution API
    //
    // SUDAN EDITION: the URL, key and instance names are NOT saved from here
    // any more. WhatsApp -> WhatsApp AI owns them, and it is the screen with
    // instance detection, QR pairing and webhook registration.
    //
    // Both screens used to write these same keys into kyc_config.json, so
    // saving this page would silently overwrite a working Evolution setup --
    // and 'evo_instance_name' means the SUPPORT number here while the field on
    // this page is labelled Sales, so the two disagreed about which channel it
    // was. Existing values are preserved untouched.
    $config['evo_channel_name']        = trim($_POST['evo_channel_name']       ?? $config['evo_channel_name']       ?? 'marketing');
    $config['evo_channel_name']        = trim($_POST['evo_channel_name']       ?? $config['evo_channel_name']       ?? 'marketing');
    $config['evo_auto_reply_enabled']  = isset($_POST['evo_auto_reply_enabled']) ? 1 : (int)($config['evo_auto_reply_enabled'] ?? 0);
    // Legacy fields kept so old saved values are preserved
    $config['whatsapp_webhook_url']    = trim($_POST['whatsapp_webhook_url']    ?? $config['whatsapp_webhook_url']    ?? '');
    $config['whatsapp_webhook_secret'] = trim($_POST['whatsapp_webhook_secret'] ?? $config['whatsapp_webhook_secret'] ?? '');
    // Magma / LTE config
    $config['magma_host']             = trim($_POST['magma_host']             ?? '');
    $config['magma_network_id']       = trim($_POST['magma_network_id']       ?? '');
    $config['magma_client_cert_path'] = trim($_POST['magma_client_cert_path'] ?? '');
    $config['magma_client_key_path']  = trim($_POST['magma_client_key_path']  ?? '');
    $config['magma_ca_cert_path']     = trim($_POST['magma_ca_cert_path']     ?? '');
    $config['webhook_secret']         = trim($_POST['webhook_secret']          ?? $config['webhook_secret'] ?? '');
    //  Splynx ISP Framework settings 
    $config['splynx_url']                    = trim($_POST['splynx_url']                    ?? '');
    $config['splynx_key']                    = trim($_POST['splynx_key']                    ?? '');
    $config['splynx_secret']                 = trim($_POST['splynx_secret']                 ?? '');
    $config['splynx_auto_provision']         = isset($_POST['splynx_auto_provision']);
    $config['splynx_sync_interval_minutes']  = max(1, (int)($_POST['splynx_sync_interval_minutes'] ?? 5));
    $config['splynx_default_tariff_id']      = (int)($_POST['splynx_default_tariff_id'] ?? 1);
    $config['splynx_default_router_id']      = (int)($_POST['splynx_default_router_id'] ?? 0);
    $config['splynx_fiber_admin_id']         = (int)($_POST['splynx_fiber_admin_id']    ?? 0);
    $config['accountant_ucrm_user_id']       = (int)($_POST['accountant_ucrm_user_id']  ?? 0);
    // Tariff map: "PlanName:TariffId,PlanName2:TariffId2"
    $tariffMapRaw = trim($_POST['splynx_tariff_map_raw'] ?? '');
    $tariffMap = [];
    foreach (array_filter(explode(',', $tariffMapRaw)) as $pair) {
        $parts = explode(':', trim($pair), 2);
        if (count($parts) === 2 && trim($parts[0]) !== '') {
            $tariffMap[trim($parts[0])] = (int)trim($parts[1]);
        }
    }
    $config['splynx_tariff_map'] = $tariffMap;
    //  WhatsApp Bot settings (only update if the WA bot form field is present) 
    if (array_key_exists('wa_bot_timeout_minutes', $_POST) || array_key_exists('wa_bot_enabled', $_POST)) {
        $config['wa_bot_enabled']                  = isset($_POST['wa_bot_enabled']);
        $config['wa_accounts_autoreply_enabled']   = isset($_POST['wa_accounts_autoreply_enabled']);
        $config['wa_bot_timeout_minutes'] = max(1, min(120, (int)($_POST['wa_bot_timeout_minutes'] ?? 15)));
        $config['wa_bot_busy_message']    = trim($_POST['wa_bot_busy_message']    ?? '');
    }
    if (array_key_exists('wa_webhook_secret', $_POST)) {
        $config['wa_webhook_secret']      = trim($_POST['wa_webhook_secret']      ?? $config['wa_webhook_secret'] ?? '');
    }
    // Claude AI key — only update if submitted non-blank
    if (array_key_exists('claude_api_key', $_POST)) {
        $newKey = trim($_POST['claude_api_key'] ?? '');
        if ($newKey !== '') $config['claude_api_key'] = $newKey;
    }
    // OpenAI GPT key
    if (array_key_exists('openai_api_key', $_POST)) {
        $newGptKey = trim($_POST['openai_api_key'] ?? '');
        if ($newGptKey !== '') $config['openai_api_key'] = $newGptKey;
    }
    // AI provider + custom instructions
    if (array_key_exists('ai_provider', $_POST)) {
        $prov = trim($_POST['ai_provider'] ?? 'claude');
        if (in_array($prov, ['claude','openai'], true)) $config['ai_provider'] = $prov;
    }
    if (array_key_exists('bot_custom_instructions', $_POST)) {
        $config['bot_custom_instructions'] = trim($_POST['bot_custom_instructions'] ?? '');
    }
    if (array_key_exists('bot_instructions_mode', $_POST)) {
        $mode = trim($_POST['bot_instructions_mode'] ?? 'append');
        if (in_array($mode, ['append','override'], true)) $config['bot_instructions_mode'] = $mode;
    }
    $config['wa_bot_cron_path']       = trim($_POST['wa_bot_cron_path']       ?? $config['wa_bot_cron_path']  ?? (__DIR__.'/cron_wa_bot.php'));
    $store->save('kyc_config.json',$config);flash('Settings saved.','success');
    redirect('?page=dashboard&tab=settings');
}
//  FTTH Org 7  Bulk sync all retailers 
//  CRM: Trigger manual sync 
//  CRM: In-process sync engine (no CLI/exec needed  works inside UCRM) 
// Shared function used by both AJAX sync_now and the page-reload path
function runInProcessSync(
    CrmQueue $queue, CrmApiClient $crm, $store,
    WalletService $wallet, $notify, int $batchSize = 10
): array {
    $log     = [];
    $jobs    = $queue->getPendingJobs($batchSize);
    $ts      = fn() => date('H:i:s');

    if (empty($jobs)) {
        $log[] = $ts() . '  No pending jobs. Queue is clean ';
        return ['log' => $log, 'processed' => 0, 'success' => 0, 'failed' => 0];
    }

    $processed = $success = $failed = 0;

    foreach ($jobs as $job) {
        $jobId = (int)$job['id'];
        $name  = trim(($job['crm_payload']['firstName'] ?? '') . ' ' . ($job['crm_payload']['lastName'] ?? ''));
        $queue->markProcessing($jobId);
        $processed++;
        $log[] = $ts() . "  Processing job #{$jobId}: {$name}";

        try {
            // Step 1: Create CRM client
            $crmResponse = $crm->post('clients', $job['crm_payload']);

            if (!$crmResponse || empty($crmResponse['id'])) {
                $error = json_encode($crm->getLastError());
                $queue->markFailed($jobId, $error);
                $log[] = $ts() . "    CRM rejected: {$error}";

                // Check if exhausted  reverse wallet
                $updated = $store->findOne('crm_queue.json', 'id', $jobId);
                if (($updated['status'] ?? '') === 'exhausted') {
                    $amount     = (float)($job['amount_charged'] ?? 0);
                    $retailerId = (int)($job['retailer_id'] ?? 0);
                    if ($amount > 0 && $retailerId > 0) {
                        $wallet->credit($retailerId, $amount,
                            "REVERSAL  CRM sync exhausted ({$name})", 'System');
                        $log[] = $ts() . "    Wallet reversed \${$amount} for retailer #{$retailerId}";
                    }
                    $store->updateOne('kyc_applications.json', 'id',
                        $job['application_id'] ?? 0,
                        ['status' => 'crm_failed', 'updated_at' => date('Y-m-d H:i:s')]);
                    $queue->markReversed($jobId, "Exhausted: {$error}");
                    $app      = $store->findOne('kyc_applications.json', 'id', $job['application_id'] ?? 0);
                    $retailer = $app ? $store->findOne('retailers.json', 'id', (int)($app['retailer_id'] ?? 0)) : null;
                    if ($retailer && $app) $notify->kycCrmFailed($retailer, $app, $error);
                }
                $failed++;
                continue;
            }

            $crmClientId = (string)$crmResponse['id'];
            $log[] = $ts() . "    CRM client created: #{$crmClientId}";

            // Step 2: Upload customer image (if staged)
            $imgPath = $job['customer_image_path'] ?? ($job['files']['customer_image'] ?? null);
            if ($imgPath && file_exists($imgPath)) {
                $crm->post('documents', [
                    'clientId' => (int)$crmClientId,
                    'name'     => 'Customer Photo',
                    'file'     => base64_encode(file_get_contents($imgPath)),
                ]);
                $log[] = $ts() . "    Customer photo uploaded";
            }

            // Step 3: Upload ID proof (if staged)
            $idPath = $job['id_proof_path'] ?? ($job['files']['id_proof'] ?? null);
            if ($idPath && file_exists($idPath)) {
                $crm->post('documents', [
                    'clientId' => (int)$crmClientId,
                    'name'     => 'ID Proof',
                    'file'     => base64_encode(file_get_contents($idPath)),
                ]);
                $log[] = $ts() . "    ID proof uploaded";
            }

            // Step 4: Work order document
            $connectivity = $job['connectivity_type'] ?? 'New Connection';
            $tplId = ($connectivity === 'New Connection') ? 2 : 3;
            $crm->post('documents', [
                'clientId'   => (int)$crmClientId,
                'name'       => 'Work Order',
                'templateId' => $tplId,
            ]);

            // Step 5: Tag
            $tagMap3 = ['Ownership Change'=>54,'Shifting Connection'=>53];
            $tagId = $tagMap3[$connectivity] ?? 52;
            $crm->patch("clients/{$crmClientId}/add-tag/{$tagId}");

            // Step 6: Update local records
            $queue->markCompleted($jobId, $crmClientId);
            $store->updateOne('kyc_applications.json', 'id', $job['application_id'] ?? 0, [
                'status'        => 'new',
                'crm_client_id' => $crmClientId,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

            // Update passbook reference
            $passbook   = $store->load('passbook.json');
            $retailerId = $job['retailer_id'] ?? 0;
            for ($i = count($passbook) - 1; $i >= 0; $i--) {
                if (($passbook[$i]['retailer_id'] ?? 0) == $retailerId
                    && str_starts_with($passbook[$i]['reference'] ?? '', 'KYC-APP-')) {
                    $passbook[$i]['reference']      = "KYC-{$crmClientId}";
                    $passbook[$i]['crm_client_id']  = $crmClientId;
                    $passbook[$i]['description']    = "KYC  CRM client #{$crmClientId} ({$name})";
                    break;
                }
            }
            $store->save('passbook.json', $passbook);

            // Step 7: WhatsApp notification
            $app      = $store->findOne('kyc_applications.json', 'id', $job['application_id'] ?? 0);
            $retailer = $app ? $store->findOne('retailers.json', 'id', (int)($app['retailer_id'] ?? 0)) : null;
            if ($retailer && $app) $notify->kycCrmCreated($retailer, $app, $crmClientId);

            $log[] = $ts() . "    Job #{$jobId} complete  CRM #{$crmClientId}";
            $success++;

        } catch (\Throwable $e) {
            $queue->markFailed($jobId, $e->getMessage());
            $log[] = $ts() . "    Exception: " . $e->getMessage();
            $failed++;
        }
    }

    $log[] = $ts() . "  Done. {$success} synced, {$failed} failed out of {$processed} processed.";
    return ['log' => $log, 'processed' => $processed, 'success' => $success, 'failed' => $failed];
}

//  AJAX endpoint: sync_now (returns JSON, no page reload) 
if (($_GET['page']??'')==='api' && ($_GET['action']??'')==='sync_now_ajax') {
    ob_end_clean();
    header('Content-Type: application/json');
    $sess = $_SESSION['kyc_retailer_id'] ?? null;
    $retailerCheck = $sess ? $store->findOne('retailers.json', 'id', (int)$sess) : null;
    if (!$retailerCheck || !($retailerCheck['is_admin'] ?? false)) {
        echo json_encode(['status' => 'error', 'message' => 'Admin access required']);
        exit;
    }
    set_time_limit(120); // allow up to 2 minutes for a large batch
    $result = runInProcessSync($queue, $crm, $store, $wallet, $notify, 20);
    $store->save('sync_last_run.json', [
        'ran_at'    => date('Y-m-d H:i:s'),
        'ran_by'    => $retailerCheck['name'] ?? 'Admin',
        'lines'     => array_slice($result['log'], -20),
        'exit_code' => $result['failed'] > 0 && $result['success'] === 0 ? 1 : 0,
        'success'   => $result['success'],
        'failed'    => $result['failed'],
        'processed' => $result['processed'],
    ]);
    echo json_encode([
        'status'    => 'ok',
        'log'       => $result['log'],
        'summary'   => $queue->getSummary(),
        'processed' => $result['processed'],
        'success'   => $result['success'],
        'failed'    => $result['failed'],
    ]);
    exit;
}
