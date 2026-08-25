<?php
// 
// LTE ADMIN / MIGRATION
// 


    //  BlueCard Feed Proxy  routes JS fetch() through PHP to avoid HTTPSHTTP mixed content 
    if ($act === 'bc_proxy') {
        require_once dirname(__DIR__, 2) . '/lib/BlueCardDb.php';
        $feedUrl   = rtrim($config['lte_feed_url']   ?? 'http://162.241.149.144/lte_feed.php', '/');
        $feedToken = $config['lte_feed_token'] ?? 'dishnet_lte_feed_Xk9mP2026';
        $table     = trim($_GET['table'] ?? $_POST['table'] ?? '');

        if (!$table) { while(ob_get_level()>0)ob_end_clean(); http_response_code(400); echo json_encode(['ok'=>false,'error'=>'table required']); exit; }

        $isPost = ($met === 'POST');
        $url    = $feedUrl . '?table=' . urlencode($table) . '&token=' . urlencode($feedToken);

        // Forward GET params (page, q, st, uid, etc.)  excluding table/token already set
        $skip = ['table','token','action','hybrid_token','page'];
        foreach ($_GET as $k => $v) {
            if (!in_array($k, $skip)) $url .= '&' . urlencode($k) . '=' . urlencode($v);
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];
        if ($isPost) {
            $ct = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($ct, 'multipart/form-data') !== false) {
                // File upload  forward files directly
                $fields = $_POST;
                foreach ($_FILES as $key => $f) {
                    if (!empty($f['tmp_name'])) {
                        $fields[$key] = new CURLFile($f['tmp_name'], $f['type'], $f['name']);
                    }
                }
                $opts[CURLOPT_POST]       = true;
                $opts[CURLOPT_POSTFIELDS] = $fields;
            } else {
                $body = file_get_contents('php://input');
                $opts[CURLOPT_POST]       = true;
                $opts[CURLOPT_POSTFIELDS] = $body;
                $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Content-Length: ' . strlen($body)];
            }
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json');
        if ($err) { http_response_code(502); echo json_encode(['ok'=>false,'error'=>'Feed error: '.$err]); exit; }
        http_response_code($code ?: 200);
        echo $resp;
        exit;
    }

    //  Save KYC locally to SQLite (backup after BlueCard write) 
    if ($act === 'bc_kyc_save_local' && $met === 'POST') {
        require_once dirname(__DIR__, 2) . '/lib/MigrationRunner.php';
        $pdo3 = $store->getPdo();
        // Run migration 040 if not yet applied
        try {
            $runner = new MigrationRunner($pdo3, dirname(__DIR__, 2) . '/migrations');
            $runner->run();
        } catch (Throwable $e) { /* already migrated */ }

        $d = json_decode(file_get_contents('php://input'), true) ?? [];

        // Resolve retailer name from session if not supplied
        $retailerName = trim(($d['retailer_name'] ?? '') ?: (($retailer['first_name'] ?? '') . ' ' . ($retailer['last_name'] ?? '')));

        try {
            $pdo3->prepare(
                "INSERT OR IGNORE INTO bc_kyc_records (
                    bc_user_id, bc_service_id, bc_balance_topup_id, bc_data_mgmt_id,
                    firstname, lastname, email, mobile, whatsapp_number, alternateMobileNo,
                    gender, date_of_birth, nationality,
                    address, house_no, landmark, pincode, city, area, district, state,
                    aadhar_card_no, relativeName, isFather, POA, POI, POAsamePOI,
                    customer_img, aadhar_card_front_img, aadhar_card_back_img, pan_card_img,
                    sim_id, imsi, msisdn, offer_id, plan_name, plan_price, end_date, payment_type,
                    retailer_id, retailer_name, company_id,
                    sync_status, synced_at, created_at
                ) VALUES (
                    ?,?,?,?,
                    ?,?,?,?,?,?,
                    ?,?,?,
                    ?,?,?,?,?,?,?,?,
                    ?,?,?,?,?,?,
                    ?,?,?,?,
                    ?,?,?,?,?,?,?,?,
                    ?,?,?,
                    'synced',datetime('now'),datetime('now')
                )"
            )->execute([
                (int)($d['user_id']          ?? 0) ?: null,
                (int)($d['service_id']        ?? 0) ?: null,
                (int)($d['balance_topup_id']  ?? 0) ?: null,
                (int)($d['data_mgmt_id']      ?? 0) ?: null,
                trim($d['firstname']          ?? ''),
                trim($d['lastname']           ?? ''),
                trim($d['email']              ?? ''),
                trim($d['mobile']             ?? $d['msisdn'] ?? ''),
                trim($d['whatsapp_number']    ?? ''),
                trim($d['alternateMobileNo']  ?? ''),
                trim($d['gender']             ?? 'male'),
                trim($d['date_of_birth']      ?? '') ?: null,
                trim($d['nationality']        ?? ''),
                trim($d['address']            ?? ''),
                trim($d['house_no']           ?? ''),
                trim($d['landmark']           ?? ''),
                trim($d['pincode']            ?? ''),
                trim($d['city']               ?? ''),
                trim($d['area']               ?? ''),
                trim($d['district']           ?? ''),
                trim($d['state']              ?? ''),
                trim($d['aadhar_card_no']     ?? '') ?: null,
                trim($d['relativeName']       ?? '') ?: null,
                (int)($d['isFather']          ?? 0) ?: null,
                trim($d['POA']                ?? '') ?: null,
                trim($d['POI']                ?? '') ?: null,
                trim($d['POAsamePOI']         ?? '') ?: null,
                trim($d['customer_img']       ?? '') ?: null,
                trim($d['aadhar_card_front_img'] ?? '') ?: null,
                trim($d['aadhar_card_back_img']  ?? '') ?: null,
                trim($d['pan_card_img']       ?? '') ?: null,
                (int)($d['sim_id']            ?? 0) ?: null,
                trim($d['imsi']               ?? '') ?: null,
                trim($d['msisdn']             ?? '') ?: null,
                (int)($d['offer_id']          ?? 0) ?: null,
                trim($d['plan']               ?? $d['plan_name'] ?? ''),
                (float)($d['plan_price']      ?? 0) ?: null,
                trim($d['end_date']           ?? '') ?: null,
                trim($d['payment_type']       ?? 'Wallet'),
                (int)($d['retailer_id']       ?? 0) ?: null,
                $retailerName ?: null,
                (int)($d['company_id']        ?? 1),
            ]);
            $localId = (int)$pdo3->lastInsertId();
            $ok2(['local_id' => $localId, 'saved' => true]);
        } catch (Throwable $e) {
            $er2('Local save failed: ' . $e->getMessage(), 500);
        }
    }
    //  Export KYC backup 
    if ($act === 'bc_kyc_export' && $met === 'GET') {
        if (!$isAdmin) $er2('Admin only', 403);
        $pdo3 = $store->getPdo();
        $fmt  = $_GET['fmt'] ?? 'json';
        $rows = $pdo3->query("SELECT * FROM bc_kyc_records WHERE deleted_at IS NULL ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $ts   = date('Y-m-d_His');
        while (ob_get_level() > 0) ob_end_clean();
        if ($fmt === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="kyc_backup_'.$ts.'.csv"');
            if (!empty($rows)) {
                $out = fopen('php://output','w');
                fputcsv($out, array_keys($rows[0]));
                foreach ($rows as $r) fputcsv($out, $r);
                fclose($out);
            }
        } else {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="kyc_backup_'.$ts.'.json"');
            echo json_encode(['exported_at'=>date('c'),'total'=>count($rows),'records'=>$rows], JSON_PRETTY_PRINT);
        }
        exit;
    }

    //  Import KYC backup from JSON 
    if ($act === 'bc_kyc_import' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only', 403);
        $pdo3 = $store->getPdo();
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        $records = $data['records'] ?? (isset($data[0]) ? $data : null);
        if (!$records || !is_array($records)) $er2('Invalid backup  expected JSON with records array', 422);
        $cols = ['bc_user_id','bc_service_id','bc_balance_topup_id','bc_data_mgmt_id',
                 'firstname','lastname','email','mobile','whatsapp_number','alternateMobileNo',
                 'gender','date_of_birth','nationality',
                 'address','house_no','landmark','pincode','city','area','district','state',
                 'aadhar_card_no','customer_img','aadhar_card_front_img','aadhar_card_back_img','pan_card_img',
                 'sim_id','imsi','msisdn','offer_id','plan_name','plan_price','end_date','payment_type',
                 'retailer_id','retailer_name','company_id','sync_status','created_at'];
        $ph  = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT OR IGNORE INTO bc_kyc_records ('.implode(',',$cols).") VALUES ($ph)";
        $stmt = $pdo3->prepare($sql);
        $inserted = 0; $skipped = 0;
        $pdo3->beginTransaction();
        try {
            foreach ($records as $r) {
                $vals = array_map(function($c) use ($r){ return $r[$c] ?? null; }, $cols);
                $stmt->execute($vals);
                $stmt->rowCount() > 0 ? $inserted++ : $skipped++;
            }
            $pdo3->commit();
            $ok2(['imported'=>$inserted,'skipped'=>$skipped,'total'=>count($records)]);
        } catch (Throwable $e) { $pdo3->rollBack(); $er2('Import failed: '.$e->getMessage(), 500); }
    }



    //  List plugin retailers (for merge UI) 
    if ($act === 'bc_list_retailers' && $met === 'GET') {
        if (!$isAdmin) $er2('Admin only', 403);
        $all = $store->load('retailers.json');
        $out = [];
        foreach ($all as $r) {
            $out[] = [
                'id'               => $r['id'],
                'name'             => $r['name'] ?? '',
                'email'            => $r['email'] ?? '',
                'phone'            => $r['phone'] ?? '',
                'role'             => $r['role'] ?? 'sales',
                'is_active'        => $r['is_active'] ?? true,
                'wallet'           => $r['wallet'] ?? 0,
                'bluecard_user_id' => $r['bluecard_user_id'] ?? null,
                'bc_synced_at'     => $r['bc_synced_at'] ?? null,
                'bluecard_role'    => $r['bluecard_role'] ?? null,
            ];
        }
        $ok2(['retailers' => $out, 'total' => count($out)]);
    }

    //  Manual link: connect a BC agent to an existing plugin retailer 
    if ($act === 'bc_agent_link' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only', 403);
        $d       = json_decode(file_get_contents('php://input'), true) ?? [];
        $bcUid   = (int)($d['bc_user_id']    ?? 0);
        $pluginId= (int)($d['plugin_id']      ?? 0);
        if (!$bcUid || !$pluginId) $er2('bc_user_id and plugin_id required', 422);
        $r = $store->findOne('retailers.json', 'id', $pluginId);
        if (!$r) $er2('Plugin retailer not found', 404);
        $store->updateOne('retailers.json', 'id', $pluginId, [
            'bluecard_user_id' => $bcUid,
            'bc_synced_at'     => date('Y-m-d H:i:s'),
        ]);
        $ok2(['linked' => true, 'plugin_id' => $pluginId, 'bc_user_id' => $bcUid]);
    }

    //  Unlink a plugin retailer from BlueCard 
    if ($act === 'bc_agent_unlink' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only', 403);
        $d       = json_decode(file_get_contents('php://input'), true) ?? [];
        $pluginId= (int)($d['plugin_id'] ?? 0);
        if (!$pluginId) $er2('plugin_id required', 422);
        $store->updateOne('retailers.json', 'id', $pluginId, [
            'bluecard_user_id' => null,
            'bluecard_role'    => null,
            'bc_synced_at'     => null,
        ]);
        $ok2(['unlinked' => true]);
    }

    //  Sync BlueCard agent  plugin retailer login 
    if ($act === 'bc_agent_sync' && ($met === 'POST' || $met === 'GET')) {
        if (!$isAdmin) $er2('Admin only', 403);

        $input   = $met === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : $_GET;
        $bcUid   = (int)($input['bc_user_id'] ?? 0);
        $syncAll = !empty($input['sync_all']);

        // Build list of agents to sync
        $agentsToSync = [];
        if ($syncAll) {
            $cfg2 = $config;
            $feedUrl   = rtrim($cfg2['lte_feed_url']   ?? 'http://162.241.149.144/lte_feed.php', '/');
            $feedToken = $cfg2['lte_feed_token'] ?? 'dishnet_lte_feed_Xk9mP2026';
            $page = 1; $allAgents = [];
            do {
                $url = $feedUrl . '?table=bc_agents&token=' . urlencode($feedToken) . '&page=' . $page;
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>false]);
                $resp = curl_exec($ch); curl_close($ch);
                $rd = json_decode($resp, true);
                $rows = $rd['data']['rows'] ?? [];
                $allAgents = array_merge($allAgents, $rows);
                $pages = $rd['data']['pages'] ?? 1;
                $page++;
            } while ($page <= $pages && $page <= 20);
            $agentsToSync = $allAgents;
        } elseif ($bcUid) {
            $cfg2 = $config;
            $feedUrl   = rtrim($cfg2['lte_feed_url']   ?? 'http://162.241.149.144/lte_feed.php', '/');
            $feedToken = $cfg2['lte_feed_token'] ?? 'dishnet_lte_feed_Xk9mP2026';
            $url = $feedUrl . '?table=bc_agents&token=' . urlencode($feedToken) . '&q=' . urlencode($bcUid) . '&page=1';
            $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_SSL_VERIFYPEER=>false]);
            $resp = curl_exec($ch); curl_close($ch);
            $rd = json_decode($resp, true);
            // Find exact match
            foreach ($rd['data']['rows'] ?? [] as $row) {
                if ((int)$row['id'] === $bcUid) { $agentsToSync[] = $row; break; }
            }
        }

        if (empty($agentsToSync)) $er2('No agents found to sync', 404);

        // Role map: BlueCard  plugin
        $roleMap = ['admin'=>'admin', 'dealer'=>'sales', 'retailer'=>'sales', 'franchisee'=>'sales_staff'];

        $created = 0; $updated = 0; $skipped = 0; $results = [];

        foreach ($agentsToSync as $ag) {
            $bcId   = (int)($ag['id'] ?? 0);
            $name   = trim(($ag['firstname'] ?? '') . ' ' . ($ag['lastname'] ?? ''));
            $email  = strtolower(trim($ag['email'] ?? ''));
            $phone  = trim($ag['mobile'] ?? '');
            $wallet = round((float)($ag['wallet'] ?? 0), 2);
            $bcRole = $ag['role_name'] ?? 'retailer';
            $pluginRole = $roleMap[$bcRole] ?? 'sales';
            $isAdmin2 = $bcRole === 'admin';

            if (!$name || (!$email && !$phone)) { $skipped++; continue; }

            // Load all retailers and find existing by email or phone or bluecard_user_id
            $all = $store->load('retailers.json');
            $existing = null;
            foreach ($all as $r) {
                if (($r['bluecard_user_id'] ?? 0) === $bcId) { $existing = $r; break; }
                if ($email && ($r['email'] ?? '') === $email) { $existing = $r; break; }
                if ($phone && ($r['phone'] ?? '') === $phone) { $existing = $r; break; }
            }

            if ($existing) {
                // Update existing record
                $store->updateOne('retailers.json', 'id', $existing['id'], [
                    'name'              => $name,
                    'email'             => $email ?: ($existing['email'] ?? ''),
                    'phone'             => $phone,
                    'wallet'            => $wallet,
                    'role'              => $pluginRole,
                    'is_admin'          => $isAdmin2,
                    'is_active'         => (bool)($ag['is_active'] ?? true),
                    'bluecard_user_id'  => $bcId,
                    'bluecard_role'     => $bcRole,
                    'bc_synced_at'      => date('Y-m-d H:i:s'),
                ]);
                $updated++;
                $results[] = ['bc_id'=>$bcId, 'action'=>'updated', 'name'=>$name, 'plugin_id'=>$existing['id']];
            } else {
                // Create new plugin retailer
                $newId = $store->nextId('retailers.json');
                $token = bin2hex(random_bytes(32));
                $defaultPwd = $phone ?: '123456';
                $record = [
                    'id'               => $newId,
                    'name'             => $name,
                    'email'            => $email,
                    'phone'            => $phone,
                    'password'         => password_hash($defaultPwd, PASSWORD_BCRYPT, ['cost'=>12]),
                    'api_token'        => $token,
                    'token_issued_at'  => time(),
                    'wallet'           => $wallet,
                    'is_active'        => (bool)($ag['is_active'] ?? true),
                    'is_admin'         => $isAdmin2,
                    'is_field_agent'   => false,
                    'role'             => $pluginRole,
                    'is_employee'      => false,
                    'commission_type'  => 'percent',
                    'commission_rate'  => (float)($ag['lm_commission'] ?? 0),
                    'must_change_pwd'  => true,
                    'bluecard_user_id' => $bcId,
                    'bluecard_role'    => $bcRole,
                    'bc_synced_at'     => date('Y-m-d H:i:s'),
                    'created_at'       => date('Y-m-d H:i:s'),
                ];
                $store->append('retailers.json', $record);
                $created++;
                $results[] = ['bc_id'=>$bcId, 'action'=>'created', 'name'=>$name, 'plugin_id'=>$newId, 'default_pwd'=>$defaultPwd];
            }
        }

        $ok2([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total'   => count($agentsToSync),
            'results' => $results,
        ]);
    }


    //  Retailer Ledger: combined outstanding view 
    if ($act === 'bc_retailer_ledger' && $met === 'GET') {
        if (!$isAdmin) $er2('Admin only', 403);
        // Run migration to ensure table exists
        try {
            $pdo3 = $store->getPdo();
            $runner = new MigrationRunner($pdo3, dirname(__DIR__, 2) . '/migrations');
            $runner->run();
        } catch (Throwable $e) {}
        $pdo3 = $store->getPdo();

        // Auto-create bc_retailer_limits table if missing
        try {
            $pdo3->exec("CREATE TABLE IF NOT EXISTS bc_retailer_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bc_user_id INTEGER NOT NULL UNIQUE,
                bc_name TEXT DEFAULT '',
                bc_mobile TEXT DEFAULT '',
                limit_usd REAL DEFAULT 500.0,
                is_blocked INTEGER DEFAULT 0,
                notes TEXT DEFAULT '',
                updated_at TEXT DEFAULT (datetime('now'))
            )");
        } catch (\Throwable $e) {}

        // Get all limits from SQLite
        $limRows = $pdo3->query("SELECT * FROM bc_retailer_limits")->fetchAll(PDO::FETCH_ASSOC);
        $limits = [];
        foreach ($limRows as $lr) $limits[(int)$lr['bc_user_id']] = $lr;

        // Get all collected (4G cashbook Cash IN with retailer_bc_id set)
        $colRows = $pdo3->query(
            "SELECT retailer_bc_id, SUM(amount) AS collected
             FROM cb_ledger
             WHERE direction='in' AND project='4g' AND retailer_bc_id > 0
               AND status='approved' AND validation_status NOT IN ('voided')
             GROUP BY retailer_bc_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $collected = [];
        foreach ($colRows as $cr) $collected[(int)$cr['retailer_bc_id']] = (float)$cr['collected'];

        // Get BlueCard agents + their recharged totals via feed
        $cfg2 = $config;
        $feedUrl   = rtrim($cfg2['lte_feed_url']   ?? 'http://162.241.149.144/lte_feed.php', '/');
        $feedToken = $cfg2['lte_feed_token'] ?? 'dishnet_lte_feed_Xk9mP2026';
        $url = $feedUrl . '?table=bc_agent_recharged&token=' . urlencode($feedToken);
        $ch = curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>false]);
        $resp = curl_exec($ch); curl_close($ch);
        $rd = json_decode($resp, true);
        $agentRows = $rd['data']['rows'] ?? [];

        $result = [];
        foreach ($agentRows as $ag) {
            $bcId      = (int)$ag['user_id'];
            $recharged = round((float)$ag['recharged_usd'], 2);
            $coll      = round($collected[$bcId] ?? 0, 2);
            $outstanding = round($recharged - $coll, 2);
            $limit     = $limits[$bcId]['limit_usd'] ?? 500.0;
            $isBlocked = ($limits[$bcId]['is_blocked'] ?? 0) || ($outstanding >= $limit);
            $result[] = [
                'bc_user_id'   => $bcId,
                'name'         => $ag['name'],
                'mobile'       => $ag['mobile'] ?? '',
                'role'         => $ag['role_name'] ?? '',
                'wallet'       => round((float)($ag['wallet']??0), 2),
                'recharged'    => $recharged,
                'collected'    => $coll,
                'outstanding'  => $outstanding,
                'limit_usd'    => (float)$limit,
                'is_blocked'   => $isBlocked,
                'manual_block' => (bool)($limits[$bcId]['is_blocked'] ?? 0),
                'pct_used'     => $limit > 0 ? round($outstanding / $limit * 100, 1) : 0,
            ];
        }
        // Sort by outstanding desc
        usort($result, function($a,$b){ return $b['outstanding'] <=> $a['outstanding']; });
        $ok2(['rows' => $result, 'total' => count($result)]);
    }

    //  Set retailer limit 
    if ($act === 'bc_set_retailer_limit' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only', 403);
        // Auto-create table if missing
        try {
            $store->getPdo()->exec("CREATE TABLE IF NOT EXISTS bc_retailer_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bc_user_id INTEGER NOT NULL UNIQUE,
                bc_name TEXT DEFAULT '',
                bc_mobile TEXT DEFAULT '',
                limit_usd REAL DEFAULT 500.0,
                is_blocked INTEGER DEFAULT 0,
                notes TEXT DEFAULT '',
                updated_at TEXT DEFAULT (datetime('now'))
            )");
        } catch (\Throwable $e) {}
        $d       = json_decode(file_get_contents('php://input'), true) ?? [];
        $bcUid   = (int)($d['bc_user_id'] ?? 0);
        $limit   = (float)($d['limit_usd'] ?? 500);
        $blocked = (int)($d['is_blocked'] ?? 0);
        $notes   = trim($d['notes'] ?? '');
        if (!$bcUid) $er2('bc_user_id required', 422);
        if ($limit < 0) $er2('limit_usd must be >= 0', 422);
        $pdo3 = $store->getPdo();
        $pdo3->prepare(
            "INSERT INTO bc_retailer_limits (bc_user_id, bc_name, bc_mobile, limit_usd, is_blocked, notes, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
             ON CONFLICT(bc_user_id) DO UPDATE SET limit_usd=excluded.limit_usd, is_blocked=excluded.is_blocked, notes=excluded.notes, updated_at=excluded.updated_at"
        )->execute([$bcUid, trim($d['bc_name']??''), trim($d['bc_mobile']??''), $limit, $blocked, $notes ?: null]);
        $ok2(['saved' => true, 'bc_user_id' => $bcUid, 'limit_usd' => $limit]);
    }

    //  BBC view: get MY retailers outstanding 
    if ($act === 'bc_my_retailer_outstanding' && $met === 'GET') {
        // Available to admin AND BBC (sales role with bluecard_user_id)
        $pdo3 = $store->getPdo();
        $bcUid = (int)($retailer['bluecard_user_id'] ?? 0);

        // Get agents under BBC from BlueCard
        $cfg2 = $config;
        $feedUrl   = rtrim($cfg2['lte_feed_url']   ?? 'http://162.241.149.144/lte_feed.php', '/');
        $feedToken = $cfg2['lte_feed_token'] ?? 'dishnet_lte_feed_Xk9mP2026';
        $url = $feedUrl . '?table=bc_agent_recharged&token=' . urlencode($feedToken);
        $ch = curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>false]);
        $resp = curl_exec($ch); curl_close($ch);
        $rd = json_decode($resp, true);
        $agentRows = $rd['data']['rows'] ?? [];

        try {
            $pdo3->exec("CREATE TABLE IF NOT EXISTS bc_retailer_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bc_user_id INTEGER NOT NULL UNIQUE,
                bc_name TEXT DEFAULT '',
                bc_mobile TEXT DEFAULT '',
                limit_usd REAL DEFAULT 500.0,
                is_blocked INTEGER DEFAULT 0,
                notes TEXT DEFAULT '',
                updated_at TEXT DEFAULT (datetime('now'))
            )");
        } catch (\Throwable $e) {}
        $limRows = $pdo3->query("SELECT * FROM bc_retailer_limits")->fetchAll(PDO::FETCH_ASSOC);
        $limits = [];
        foreach ($limRows as $lr) $limits[(int)$lr['bc_user_id']] = $lr;

        $colRows = $pdo3->query(
            "SELECT retailer_bc_id, SUM(amount) AS collected FROM cb_ledger
             WHERE direction='in' AND project='4g' AND retailer_bc_id > 0 AND status='approved'
             GROUP BY retailer_bc_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $collected = [];
        foreach ($colRows as $cr) $collected[(int)$cr['retailer_bc_id']] = (float)$cr['collected'];

        $result = [];
        foreach ($agentRows as $ag) {
            $bcId = (int)$ag['user_id'];
            $recharged = round((float)$ag['recharged_usd'], 2);
            $coll = round($collected[$bcId] ?? 0, 2);
            $outstanding = round($recharged - $coll, 2);
            $limit = $limits[$bcId]['limit_usd'] ?? 500.0;
            $result[] = [
                'bc_user_id'  => $bcId,
                'name'        => $ag['name'],
                'mobile'      => $ag['mobile']??'',
                'recharged'   => $recharged,
                'collected'   => $coll,
                'outstanding' => $outstanding,
                'limit_usd'   => (float)$limit,
                'is_blocked'  => $outstanding >= $limit,
            ];
        }
        usort($result, function($a,$b){ return $b['outstanding'] <=> $a['outstanding']; });
        $ok2(['rows' => $result, 'total_outstanding' => array_sum(array_column($result,'outstanding'))]);
    }

    //  Recharge outstanding check (called before forwarding to feed) 
    if ($act === 'bc_check_outstanding' && $met === 'POST') {
        $d       = json_decode(file_get_contents('php://input'), true) ?? [];
        $agentId = (int)($d['agent_id'] ?? 0);
        $amount  = (float)($d['amount_usd'] ?? 0);
        if (!$agentId) $er2('agent_id required', 422);
        $pdo3 = $store->getPdo();
        $limitRow = $pdo3->prepare("SELECT * FROM bc_retailer_limits WHERE bc_user_id=? LIMIT 1");
        $limitRow->execute([$agentId]); $lr = $limitRow->fetch(PDO::FETCH_ASSOC);
        $limitUsd = $lr ? (float)$lr['limit_usd'] : 500.0;
        $isManualBlocked = $lr ? (bool)$lr['is_blocked'] : false;
        // Get collected
        $colSt = $pdo3->prepare("SELECT COALESCE(SUM(amount),0) FROM cb_ledger WHERE direction='in' AND project='4g' AND retailer_bc_id=? AND status='approved'");
        $colSt->execute([$agentId]); $collected = (float)$colSt->fetchColumn();
        // Get recharged from feed
        $cfg2 = $config;
        $feedUrl   = rtrim($cfg2['lte_feed_url']   ?? 'http://162.241.149.144/lte_feed.php', '/');
        $feedToken = $cfg2['lte_feed_token'] ?? 'dishnet_lte_feed_Xk9mP2026';
        $url = $feedUrl . '?table=bc_agent_recharged&token='.urlencode($feedToken).'&uid='.$agentId;
        $ch = curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false]);
        $resp = curl_exec($ch); curl_close($ch);
        $rd = json_decode($resp, true);
        $recharged = (float)($rd['data']['recharged_usd'] ?? 0);
        $outstanding = round($recharged - $collected, 2);
        $newOutstanding = round($outstanding + $amount, 2);
        $wouldBlock = $isManualBlocked || $newOutstanding > $limitUsd;
        $ok2([
            'agent_id'       => $agentId,
            'recharged'      => $recharged,
            'collected'      => $collected,
            'outstanding'    => $outstanding,
            'limit_usd'      => $limitUsd,
            'this_recharge'  => $amount,
            'new_outstanding'=> $newOutstanding,
            'blocked'        => $wouldBlock,
            'manual_block'   => $isManualBlocked,
        ]);
    }

        //  LTE RESET & FRESH RESYNC 
    // Wipes all synced LTE data and resets cursors so cron_lte_sync.php
    // pulls everything fresh from BlueCard. Schema is preserved.
    if ($act === 'lte_reset_sync' && $met === 'POST') {
        $isAdmin = $me2['is_admin'] ?? false;
        if (!$isAdmin) $er2('Admin only', 403);

        $pdo2 = $store->getPdo();
        $results = ['tables_cleared' => [], 'json_cleared' => [], 'errors' => []];

        //  STEP 1: Clear all LTE data tables (keep schema + indexes) 
        $tables = [
            'lte_subscribers', 'lte_sims', 'lte_subscriptions',
            'lte_renewals', 'lte_packages', 'lte_data_mgmt', 'lte_usages',
        ];
        foreach ($tables as $tbl) {
            try {
                $before = (int)$pdo2->query("SELECT COUNT(*) FROM {$tbl}")->fetchColumn();
                $pdo2->exec("DELETE FROM {$tbl}");
                $pdo2->exec("DELETE FROM sqlite_sequence WHERE name='{$tbl}'");
                $results['tables_cleared'][$tbl] = $before;
            } catch (\Throwable $e) {
                $results['errors'][] = "{$tbl}: {$e->getMessage()}";
            }
        }

        //  STEP 2: Clear staging tables if they exist 
        foreach (['bluecard_users','bluecard_sims','bluecard_plans','bluecard_services','bluecard_import_log'] as $stg) {
            try {
                $pdo2->exec("DELETE FROM {$stg}");
                $results['tables_cleared'][$stg] = 'cleared';
            } catch (\Throwable $e) {
                // Staging table may not exist  that's OK
            }
        }

        //  STEP 3: Reset sync cursors 
        // Keys must match exactly what cron_lte_sync.php reads:
        //   users_max_id, sims_max_id, items_max_id, topup_max_id,
        //   datamgmt_max_id (NOT data_mgmt_max_id), usages_max_id, renewal_repair_done
        $stateFile = $dataDir . '/lte_sync_state.json';
        $freshState = [
            'users_max_id'        => 0,
            'sims_max_id'         => 0,
            'items_max_id'        => 0,
            'topup_max_id'        => 0,
            'datamgmt_max_id'     => 0,
            'usages_max_id'       => 0,
            'renewal_repair_done' => false,
            'reset_at'            => date('Y-m-d H:i:s'),
            'reset_reason'        => 'Manual fresh resync via API',
        ];
        file_put_contents($stateFile, json_encode($freshState, JSON_PRETTY_PRINT));
        $results['sync_state'] = 'all cursors reset to 0';

        //  STEP 4: Force immediate trigger by clearing master_schedule entries 
        // master.php checks last_run timestamp  if we don't clear it, sync waits up to 5 min.
        $masterSched = $store->load('master_schedule.json') ?? [];
        // Clear lte_sync so it runs on the very next master.php cycle (~1 min)
        if (isset($masterSched['lte_sync'])) {
            $masterSched['lte_sync']['last_run'] = 0;
            $masterSched['lte_sync']['last_run_at'] = 'reset';
        }
        // Also clear lte_cron  it reads subscriber data that's now empty
        if (isset($masterSched['lte_cron'])) {
            $masterSched['lte_cron']['last_run'] = 0;
            $masterSched['lte_cron']['last_run_at'] = 'reset';
        }
        $store->save('master_schedule.json', $masterSched);
        $results['master_schedule'] = 'lte_sync + lte_cron reset to trigger immediately';

        //  STEP 5: Clear JSON export files (rebuilt by next cron_lte_sync run) 
        $jsonFiles = [
            'lte_subscribers.json', 'lte_sims.json', 'lte_packages.json',
            'lte_renewals.json', 'lte_subscriptions.json',
            'lte_hardware.json', 'lte_usage_cache.json',
            'lte_network_health.json', 'lte_auto_suspend_log.json',
            'lte_auto_reactivate_log.json', 'lte_settlement_snapshots.json',
        ];
        foreach ($jsonFiles as $jf) {
            $path = $dataDir . '/' . $jf;
            if (file_exists($path)) {
                file_put_contents($path, '[]');
                $results['json_cleared'][] = $jf;
            }
        }

        //  STEP 6: Remove seeded flag 
        $flagFile = $dataDir . '/.lte_seeded';
        if (file_exists($flagFile)) { @unlink($flagFile); $results['seeded_flag'] = 'removed'; }

        //  STEP 7: Remove sync lock files so cron starts cleanly 
        foreach (['lte_sync.lock', 'cron_lte.lock', 'cron_lte_usage.lock'] as $lf) {
            $lockPath = $dataDir . '/' . $lf;
            if (file_exists($lockPath)) { @unlink($lockPath); }
        }
        $results['locks'] = 'cleared';

        //  STEP 8: Log the reset 
        try {
            $actLog = $store->load('activity_log.json') ?? [];
            array_unshift($actLog, [
                'action' => 'lte_reset',
                'title'  => 'LTE data reset for fresh resync',
                'detail' => json_encode($results['tables_cleared']),
                'time'   => date('Y-m-d H:i:s'),
                'date'   => date('Y-m-d'),
            ]);
            $store->save('activity_log.json', array_slice($actLog, 0, 500));
        } catch (\Throwable $e) {}

        $results['next_steps'] = [
            '1. master.php will trigger cron_lte_sync.php within ~60 seconds',
            '2. Full sync pulls all packages, subscribers, SIMs, renewals from BlueCard',
            '3. Active status recalculated from unexpired topups',
            '4. JSON files rebuilt for dashboard',
            '5. Expected duration: 2-5 minutes for full sync',
        ];
        $ok2($results, 'LTE data reset complete. Fresh sync will start within ~60 seconds.');
    }

    //  LTE Diagnostics 
    if ($act === 'lte_diag') {
        $isAdmin = $me2['is_admin'] ?? false;
        if (!$isAdmin) $er2('Admin only', 403);
        $pdo2 = $store->getPdo();
        $diag = ['php' => PHP_VERSION, 'dataDir' => $dataDir, 'errors' => []];
        $migrDir = dirname(__DIR__) . '/migrations';
        $diag['migrations_dir'] = $migrDir;
        $diag['migrations_dir_exists'] = is_dir($migrDir);
        $diag['migration_files'] = is_dir($migrDir) ? count(glob($migrDir . '/*.sql') ?: []) : 0;
        try {
            $applied = $pdo2->query("SELECT filename FROM _migrations ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
            $diag['migrations_applied'] = $applied;
        } catch (\Throwable $e) {
            $diag['errors'][] = '_migrations: ' . $e->getMessage();
            $diag['migrations_applied'] = [];
        }
        $lteTables = ['lte_subscribers','lte_sims','lte_subscriptions','lte_renewals','lte_packages'];
        $diag['lte_tables'] = [];
        foreach ($lteTables as $tbl) {
            try {
                $cnt = (int)$pdo2->query("SELECT COUNT(*) FROM {$tbl}")->fetchColumn();
                $diag['lte_tables'][$tbl] = $cnt;
            } catch (\Throwable $e) {
                $diag['lte_tables'][$tbl] = 'MISSING: ' . $e->getMessage();
                $diag['errors'][] = $tbl . ' missing';
            }
        }
        $ok2($diag);
    }

    //  Force-run migrations 
    if ($act === 'lte_run_migrations' && $met === 'POST') {
        $isAdmin = $me2['is_admin'] ?? false;
        if (!$isAdmin) $er2('Admin only', 403);
        try {
            $migrDir = dirname(__DIR__) . '/migrations';
            if (!is_dir($migrDir)) $er2('Migrations dir not found: ' . $migrDir . ' (cwd=' . getcwd() . ', __DIR__=' . __DIR__ . ')', 500);
            require_once dirname(__DIR__, 2) . '/lib/MigrationRunner.php';
            $runner  = new \MigrationRunner($store->getPdo(), $migrDir);
            $results = $runner->run();
            $status  = $runner->getStatus();
            $ok2(['results' => $results, 'status' => $status]);
        } catch (\Throwable $e) {
            $er2('lte_run_migrations error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(), 500);
        }
    }

    //  Force re-seed LTE data from data/seed/ 
    // GET|POST ?page=api&action=lte_reseed            seed only if tables empty
    // GET|POST ?page=api&action=lte_reseed&force=1    DROP tables, recreate schema, re-seed
    if ($act === 'lte_reseed') {
        $isAdmin = $me2['is_admin'] ?? false;
        if (!$isAdmin) $er2('Admin only', 403);
        try {
            $pluginRoot = $GLOBALS['_PLUGIN_ROOT'] ?? dirname(__DIR__);
            $seedDir    = $pluginRoot . '/seed';
            $pdo2       = $store->getPdo();
            // SAFETY: force mode MUST come from POST body + confirmation phrase only.
            // Never accept via $_GET — a stray browser URL like ?force=1 must not drop tables.
            $_body2     = ($met === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
            $forceMode  = !empty($_body2['force']) && ($_body2['confirm'] ?? '') === 'RESET_LTE_DATA';
            $results    = ['_seed_dir' => $seedDir, '_seed_exists' => is_dir($seedDir), '_force' => $forceMode];

            if (!is_dir($seedDir)) {
                $er2('Seed directory not found: ' . $seedDir, 500);
            }

            //  Force mode: DROP blob tables so proper schema can be created 
            // This fixes the bug where SqliteStore::ensureTable() created
            // lte_* as blob tables (id+data) before SQL migrations could create
            // them with proper columns. DROP + recreate fixes the schema.
            $lteTables = ['lte_subscribers','lte_sims','lte_subscriptions','lte_renewals','lte_packages'];
            if ($forceMode) {
                $results['_dropped'] = [];
                foreach ($lteTables as $tbl) {
                    try {
                        // Check if table is blob-format (only id + data columns)
                        $cols = $pdo2->query("PRAGMA table_info({$tbl})")->fetchAll(\PDO::FETCH_COLUMN, 1);
                        $isBlobTable = (count($cols) <= 2 && in_array('data', $cols));
                        if ($isBlobTable || $forceMode) {
                            $pdo2->exec("DROP TABLE IF EXISTS {$tbl}");
                            $results['_dropped'][] = $tbl . ($isBlobTable ? ' (was blob)' : ' (force)');
                        }
                    } catch (\Throwable $_de) {
                        $results['_dropped'][] = $tbl . ': ' . $_de->getMessage();
                    }
                }
                // Also remove seeded flag so seedLteData() doesn't skip
                $flagFile = $store->getDataDir() . '/.lte_seeded';
                if (file_exists($flagFile)) { @unlink($flagFile); $results['_flag_removed'] = true; }
            }

            //  Ensure LTE tables exist (run migrations 020-024) 
            // After force-drop, these will CREATE the tables with proper columns.
            $migrDir = $pluginRoot . '/migrations';
            $lteMigrations = ['020_lte_subscribers.sql','021_lte_sims.sql',
                              '022_lte_subscriptions.sql','023_lte_renewals.sql',
                              '024_lte_packages.sql'];
            $results['_migrations'] = [];
            foreach ($lteMigrations as $mf) {
                $mpath = $migrDir . '/' . $mf;
                if (!file_exists($mpath)) { $results['_migrations'][$mf] = 'file missing'; continue; }
                try {
                    $pdo2->exec(file_get_contents($mpath));
                    $results['_migrations'][$mf] = 'ok';
                } catch (\Throwable $me) {
                    $results['_migrations'][$mf] = strpos($me->getMessage(),'already exists')!==false ? 'exists' : $me->getMessage();
                }
            }

            //  Verify schema: confirm tables have proper columns 
            $results['_schema_check'] = [];
            foreach (['lte_subscribers' => 'name', 'lte_sims' => 'imsi', 'lte_subscriptions' => 'subscriber_id'] as $tbl => $mustHaveCol) {
                $cols = $pdo2->query("PRAGMA table_info({$tbl})")->fetchAll(\PDO::FETCH_COLUMN, 1);
                $hasProperCols = in_array($mustHaveCol, $cols);
                $results['_schema_check'][$tbl] = $hasProperCols
                    ? 'ok (' . count($cols) . ' cols)'
                    : 'WRONG SCHEMA  only has: ' . implode(', ', $cols);
                // If schema is STILL wrong (shouldn't happen after force), try again
                if (!$hasProperCols && $forceMode) {
                    $pdo2->exec("DROP TABLE IF EXISTS {$tbl}");
                    $mf = ['lte_subscribers'=>'020_lte_subscribers.sql','lte_sims'=>'021_lte_sims.sql','lte_subscriptions'=>'022_lte_subscriptions.sql'][$tbl] ?? '';
                    if ($mf && file_exists($migrDir.'/'.$mf)) { $pdo2->exec(file_get_contents($migrDir.'/'.$mf)); }
                    $results['_schema_check'][$tbl] .= '  fixed';
                }
            }

            //  Helper: insert rows in batches inside a single transaction 
            $batchInsert = function(string $table, array $rows, callable $toParams, array $cols) use ($pdo2, $forceMode): int {
                $existing = (int)$pdo2->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
                if ($existing > 0 && !$forceMode) return -1; // already seeded
                if ($existing > 0 && $forceMode)  $pdo2->exec("DELETE FROM {$table}");
                $ph   = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
                $sql  = "INSERT OR IGNORE INTO {$table} (" . implode(',', $cols) . ") VALUES {$ph}";
                $stmt = $pdo2->prepare($sql);
                $pdo2->beginTransaction();
                $count = 0;
                foreach ($rows as $row) {
                    $params = $toParams($row);
                    if ($params === null) continue;
                    $stmt->execute($params);
                    $count++;
                }
                $pdo2->commit();
                return $count;
            };

            //  1. PACKAGES 
            $pkgData = json_decode(file_get_contents($seedDir . '/lte_packages.json'), true) ?: [];
            $n = $batchInsert('lte_packages', $pkgData, function($r) {
                return [
                    $r['id']                           ?? null,
                    $r['name']                         ?? '',
                    $r['description']                  ?? '',
                    (float)($r['price']                ?? 0),
                    (int)($r['price_cents']            ?? 0),
                    (int)($r['type']                   ?? 2),
                    $r['type_label']                   ?? 'unlimited',
                    $r['bytes_allowed'] !== null ? (int)$r['bytes_allowed'] : null,
                    $r['bytes_display']                ?? 'Unlimited',
                    (int)($r['days']                   ?? 31),
                    $r['days_display']                 ?? '1 Month',
                    $r['magma_profile']                ?? 'default',
                    $r['active_apns']                  ?? 'cmnet',
                    (int)($r['is_active']              ?? 1),
                    (int)($r['is_popular']             ?? 0),
                    (int)($r['sort_order']             ?? 999),
                    $r['lifecycle_status']             ?? 'active',
                    (int)($r['bluecard_id']            ?? 0),
                    $r['created_at']                   ?? date('Y-m-d H:i:s'),
                ];
            }, ['id','name','description','price','price_cents','type','type_label',
                'bytes_allowed','bytes_display','duration_days','days_display','magma_profile',
                'active_apns','is_active','is_popular','sort_order','lifecycle_status',
                'bluecard_id','created_at']);
            $results['lte_packages'] = $n < 0 ? 'skipped (already has data)' : "seeded {$n} records";

            //  2. SIMS 
            $simData = json_decode(file_get_contents($seedDir . '/lte_sims.json'), true) ?: [];
            $n = $batchInsert('lte_sims', $simData, function($r) {
                $ak = trim($r['auth_key'] ?? '');
                if (!($r['imsi'] ?? '') || strlen($ak) < 32) return null;
                return [
                    $r['id']                               ?? null,
                    $r['imsi'],
                    $r['msisdn']                           ?? $r['imsi'],
                    $r['iccid']                            ?? null,
                    $ak,
                    $r['auth_opc']                         ?? '',
                    $r['algo']                             ?? 'Milenage',
                    ($r['status'] ?? 'in_stock') === 'assigned' ? 'assigned' : 'stock',
                    $r['subscriber_id']                    ?? null,
                    $r['created_at']                       ?? date('Y-m-d H:i:s'),
                ];
            }, ['id','imsi','msisdn','iccid','auth_key','auth_opc','auth_algo',
                'status','subscriber_id','created_at']);
            $results['lte_sims'] = $n < 0 ? 'skipped (already has data)' : "seeded {$n} records";

            //  3. SUBSCRIBERS 
            $subData = json_decode(file_get_contents($seedDir . '/lte_subscribers.json'), true) ?: [];
            $n = $batchInsert('lte_subscribers', $subData, function($r) {
                if (!($r['name'] ?? '') || !($r['phone'] ?? '')) return null;
                return [
                    $r['id']                               ?? null,
                    $r['name'],
                    $r['phone'],
                    $r['email']                            ?? null,
                    $r['address']                          ?? null,
                    $r['area']                             ?? null,
                    $r['imsi']                             ?? null,
                    $r['msisdn']                           ?? null,
                    $r['sim_id']                           ?? null,
                    $r['status']                           ?? 'active',
                    $r['agent_id']                         ?? 1,
                    $r['agent_name']                       ?? 'Import',
                    $r['registered_by']                    ?? 'bulk_import',
                    (int)($r['bluecard_id']                ?? 0) ?: null,
                    $r['notes']                            ?? null,
                    $r['created_at']                       ?? date('Y-m-d H:i:s'),
                ];
            }, ['id','name','phone','email','address','area','imsi','msisdn','sim_id',
                'status','agent_id','agent_name','registered_by','bluecard_id','notes','created_at']);
            $results['lte_subscribers'] = $n < 0 ? 'skipped (already has data)' : "seeded {$n} records";

            //  4. SUBSCRIPTIONS 
            $subsData = json_decode(file_get_contents($seedDir . '/lte_subscriptions.json'), true) ?: [];
            $n = $batchInsert('lte_subscriptions', $subsData, function($r) {
                if (!($r['subscriber_id'] ?? 0)) return null;
                $started = $r['started_at'] ?? date('Y-m-d');
                $expires = $r['expires_at'] ?? date('Y-m-d', strtotime('+30 days'));
                return [
                    $r['id']                               ?? null,
                    (int)$r['subscriber_id'],
                    (int)($r['package_id']                 ?? 0) ?: null,
                    $r['package_name']                     ?? null,
                    (int)($r['package_type']               ?? 2),
                    $r['magma_profile']                    ?? 'default',
                    $started,
                    $expires,
                    $r['bytes_allowed']                    ?? null,
                    (int)($r['bytes_used']                 ?? 0),
                    $r['bytes_remaining']                  ?? null,
                    (float)($r['usage_percent']            ?? 0),
                    $r['status']                           ?? 'active',
                    (float)($r['amount_paid']              ?? 0),
                    $r['payment_method']                   ?? 'import',
                    $r['agent_id']                         ?? 1,
                    $r['agent_name']                       ?? 'Import',
                    $r['created_at']                       ?? date('Y-m-d H:i:s'),
                ];
            }, ['id','subscriber_id','package_id','package_name','package_type','magma_profile',
                'started_at','expires_at','bytes_allowed','bytes_used','bytes_remaining',
                'usage_percent','status','amount_paid','payment_method','agent_id','agent_name','created_at']);
            $results['lte_subscriptions'] = $n < 0 ? 'skipped (already has data)' : "seeded {$n} records";

            //  5. RENEWALS 
            $renData = json_decode(file_get_contents($seedDir . '/lte_renewals.json'), true) ?: [];
            $n = $batchInsert('lte_renewals', $renData, function($r) {
                if (!($r['subscriber_id'] ?? 0)) return null;
                return [
                    $r['id']                               ?? null,
                    (int)$r['subscriber_id'],
                    (int)($r['package_id']                 ?? 0) ?: null,
                    $r['package_name']                     ?? null,
                    (float)($r['package_price']            ?? 0),
                    (float)($r['amount_paid']              ?? 0),
                    $r['payment_method']                   ?? 'import',
                    $r['agent_id']                         ?? 1,
                    $r['agent_name']                       ?? 'Import',
                    $r['created_at']                       ?? date('Y-m-d H:i:s'),
                ];
            }, ['id','subscriber_id','package_id','package_name','package_price',
                'amount_paid','payment_method','agent_id','agent_name','created_at']);
            $results['lte_renewals'] = $n < 0 ? 'skipped (already has data)' : "seeded {$n} records";

            $ok2($results);
        } catch (\Throwable $e) {
            $er2('lte_reseed error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(), 500);
        }
    }

    /* 
       LTE BLUECARD MIGRATION ENDPOINTS
     */

    //  Chunked SQL file upload 
    // Handles large files (31MB+) by splitting into ~1MB chunks client-side.
    // Each POST sends: chunk (base64 or raw), chunk_index, total_chunks, filename.
    // Final chunk triggers an integrity check and returns the saved filename.
    if ($act === 'lte_upload_chunk' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only', 403);

        $sqlUploadDir = $uploadDir . '/lte_sql/';
        if (!is_dir($sqlUploadDir)) @mkdir($sqlUploadDir, 0755, true);

        $filename    = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_POST['filename'] ?? 'dump.sql'));
        $chunkIdx    = (int)($_POST['chunk_index']  ?? 0);
        $totalChunks = (int)($_POST['total_chunks'] ?? 1);
        $chunkData   = $_POST['chunk_data'] ?? '';

        if (!$filename || !$chunkData) $er2('Missing chunk data or filename', 400);

        // Decode base64 chunk
        $rawBytes = base64_decode($chunkData, true);
        if ($rawBytes === false) $er2('Invalid base64 chunk data', 400);

        // Write chunk to temp file
        $tmpPath  = $sqlUploadDir . $filename . '.part';
        $mode     = ($chunkIdx === 0) ? 'wb' : 'ab';
        $fp = fopen($tmpPath, $mode);
        if (!$fp) $er2('Cannot write to upload directory', 500);
        fwrite($fp, $rawBytes);
        fclose($fp);

        // Final chunk  rename to final filename + basic validation
        if ($chunkIdx === $totalChunks - 1) {
            $finalPath = $sqlUploadDir . $filename;
            rename($tmpPath, $finalPath);
            $fileSize = filesize($finalPath);
            $fileSizeMb = round($fileSize / 1048576, 2);

            // Quick sanity check  must contain SQL
            $head = file_get_contents($finalPath, false, null, 0, 512);
            $looksLikeSql = str_contains($head, 'CREATE') || str_contains($head, 'INSERT') || str_contains($head, '-- MySQL') || str_contains($head, 'mysqldump');

            if (!$looksLikeSql) {
                unlink($finalPath);
                $er2('File does not appear to be a valid MySQL dump. Check the file and try again.', 422);
            }

            $ok2([
                'filename'   => $filename,
                'path'       => 'lte_sql/' . $filename,
                'size_mb'    => $fileSizeMb,
                'size_bytes' => $fileSize,
                'complete'   => true,
            ], 'Upload complete  ready to import');
        } else {
            $ok2([
                'chunk_index'   => $chunkIdx,
                'total_chunks'  => $totalChunks,
                'received_bytes'=> strlen($rawBytes),
                'complete'      => false,
            ], 'Chunk received');
        }
    }

    //  List uploaded SQL files 
    if ($act === 'lte_list_uploads' && $met === 'GET') {
        if (!$isAdmin) $er2('Admin only', 403);
        $sqlUploadDir = $uploadDir . '/lte_sql/';
        $files = [];
        if (is_dir($sqlUploadDir)) {
            foreach (scandir($sqlUploadDir) as $f) {
                if ($f === '.' || $f === '..' || str_ends_with($f, '.part')) continue;
                $path = $sqlUploadDir . $f;
                $files[] = [
                    'filename'   => $f,
                    'size_mb'    => round(filesize($path) / 1048576, 2),
                    'size_bytes' => filesize($path),
                    'modified'   => date('Y-m-d H:i:s', filemtime($path)),
                ];
            }
        }
        // Also check staging table row counts
        $pdo2 = $store->getPdo();
        $staging = [];
        foreach (['bluecard_users','bluecard_sims','bluecard_services','bluecard_plans'] as $tbl) {
            try {
                $staging[$tbl] = (int)$pdo2->query("SELECT COUNT(*) FROM {$tbl}")->fetchColumn();
            } catch (\Throwable $e) {
                $staging[$tbl] = null;
            }
        }
        $ok2(['files' => $files, 'staging' => $staging]);
    }

    //  Delete uploaded SQL file 
    if ($act === 'lte_delete_upload' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only', 403);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($body['filename'] ?? ''));
        if (!$filename) $er2('Filename required', 400);
        $path = $uploadDir . '/lte_sql/' . $filename;
        if (file_exists($path)) unlink($path);
        $ok2(['deleted' => $filename]);
    }

    // Import SQL dump to staging tables
    if ($act === 'lte_import_to_staging' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only', 403);
        
        $filename = $_POST['filename'] ?? '';
        if (!$filename) $er2('Missing filename', 400);
        
        $uploadPath = $uploadDir . '/lte_sql/' . basename($filename);
        if (!file_exists($uploadPath)) $er2('File not found', 404);
        
        require_once dirname(__DIR__, 2) . '/lib/BlueCardDumpParser.php';
        
        try {
            $result = BlueCardDumpParser::importToStagingTables($store, $uploadPath);
            $ok2($result, 'Import to staging complete');
        } catch (Exception $e) {
            $er2('Import failed: ' . $e->getMessage(), 500);
        }
    }

    // Refresh mapping - auto-map BlueCard users to Hybrid subscribers
    if ($act === 'lte_refresh_mapping' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only', 403);
        
        $pdo = $store->getPdo();
        $mapped = 0;
        
        try {
            // Map by phone match
            $pdo->exec("
                UPDATE bluecard_users SET 
                    mapped_to_hybrid = 1,
                    hybrid_subscriber_id = (
                        SELECT id FROM lte_subscribers 
                        WHERE lte_subscribers.phone = bluecard_users.phone 
                        AND lte_subscribers.deleted_at IS NULL
                        LIMIT 1
                    )
                WHERE mapped_to_hybrid = 0 
                AND EXISTS (
                    SELECT 1 FROM lte_subscribers 
                    WHERE lte_subscribers.phone = bluecard_users.phone
                    AND lte_subscribers.deleted_at IS NULL
                )
            ");
            $mapped += $pdo->query("SELECT changes()")->fetchColumn();
            
            // Map by IMSI match
            $pdo->exec("
                UPDATE bluecard_sims SET 
                    mapped_to_hybrid = 1,
                    hybrid_sim_id = (
                        SELECT id FROM lte_sims 
                        WHERE lte_sims.imsi = bluecard_sims.imsi 
                        AND lte_sims.deleted_at IS NULL
                        LIMIT 1
                    )
                WHERE mapped_to_hybrid = 0 
                AND EXISTS (
                    SELECT 1 FROM lte_sims 
                    WHERE lte_sims.imsi = bluecard_sims.imsi
                    AND lte_sims.deleted_at IS NULL
                )
            ");
            $mapped += $pdo->query("SELECT changes()")->fetchColumn();
            
            // Map plans by exact name match
            $pdo->exec("
                UPDATE bluecard_plans SET 
                    mapped_to_hybrid = 1,
                    hybrid_package_id = (
                        SELECT id FROM lte_packages 
                        WHERE lte_packages.name = bluecard_plans.name 
                        LIMIT 1
                    )
                WHERE mapped_to_hybrid = 0 
                AND EXISTS (
                    SELECT 1 FROM lte_packages 
                    WHERE lte_packages.name = bluecard_plans.name
                )
            ");
            $mapped += $pdo->query("SELECT changes()")->fetchColumn();
            
            $ok2(['mapped' => $mapped], 'Mapping refreshed');
        } catch (Exception $e) {
            $er2('Mapping failed: ' . $e->getMessage(), 500);
        }
    }

    // Auto-map plans - create missing packages if needed
    if ($act === 'lte_auto_map_plans' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only', 403);
        
        $pdo = $store->getPdo();
        $created = 0;
        $mapped = 0;
        
        try {
            // Get unmapped plans
            $unmapped = $store->query(
                "SELECT * FROM bluecard_plans WHERE mapped_to_hybrid = 0"
            );
            
            foreach ($unmapped as $plan) {
                // Check if package with same name exists
                $existing = $pdo->query(
                    "SELECT id FROM lte_packages WHERE name = " . $pdo->quote($plan['name'])
                )->fetchColumn();
                
                if ($existing) {
                    // Map to existing
                    $pdo->exec("UPDATE bluecard_plans SET mapped_to_hybrid = 1, hybrid_package_id = {$existing} WHERE id = {$plan['id']}");
                    $mapped++;
                } else {
                    // Create new package
                    $stmt = $pdo->prepare("
                        INSERT INTO lte_packages (name, description, price, type, days, bytes_allowed, magma_profile, is_active, bluecard_id, created_at)
                        VALUES (:name, :desc, :price, :type, :days, :bytes, :profile, 1, :bcid, datetime('now'))
                    ");
                    $stmt->execute([
                        ':name' => $plan['name'],
                        ':desc' => $plan['description'] ?? $plan['name'],
                        ':price' => $plan['price'] ?? 0,
                        ':type' => $plan['type'] ?? 0,
                        ':days' => $plan['days'] ?? 30,
                        ':bytes' => $plan['bytes_allowed'] ?? null,
                        ':profile' => $plan['magma_profile'] ?? 'default',
                        ':bcid' => $plan['id'],
                    ]);
                    $newId = $pdo->lastInsertId();
                    $pdo->exec("UPDATE bluecard_plans SET mapped_to_hybrid = 1, hybrid_package_id = {$newId} WHERE id = {$plan['id']}");
                    $created++;
                }
            }
            
            $ok2(['created' => $created, 'mapped' => $mapped], 'Plans auto-mapped');
        } catch (Exception $e) {
            $er2('Auto-map failed: ' . $e->getMessage(), 500);
        }
    }

    // Validate migration - check for issues before executing
    if ($act === 'lte_validate_migration' && $met === 'GET') {
        if (!$isAdmin) $er2('Admin only', 403);
        
        $pdo = $store->getPdo();
        $issues = [];
        
        try {
            // Check for unmapped plans
            $unmappedPlans = (int)$pdo->query("SELECT COUNT(*) FROM bluecard_plans WHERE mapped_to_hybrid = 0")->fetchColumn();
            if ($unmappedPlans > 0) {
                $issues[] = ['type' => 'error', 'message' => "{$unmappedPlans} plans not mapped - migration will fail"];
            }
            
            // Check for SIMs without auth keys
            $noAuthKey = (int)$pdo->query("SELECT COUNT(*) FROM bluecard_sims WHERE auth_key IS NULL OR auth_key = ''")->fetchColumn();
            if ($noAuthKey > 0) {
                $issues[] = ['type' => 'warning', 'message' => "{$noAuthKey} SIMs missing auth_key"];
            }
            
            // Check for duplicate IMSIs
            $dupImsi = (int)$pdo->query("
                SELECT COUNT(*) FROM (
                    SELECT imsi FROM bluecard_sims GROUP BY imsi HAVING COUNT(*) > 1
                )
            ")->fetchColumn();
            if ($dupImsi > 0) {
                $issues[] = ['type' => 'error', 'message' => "{$dupImsi} duplicate IMSIs found"];
            }
            
            // Check for users without phone
            $noPhone = (int)$pdo->query("SELECT COUNT(*) FROM bluecard_users WHERE phone IS NULL OR phone = ''")->fetchColumn();
            if ($noPhone > 0) {
                $issues[] = ['type' => 'warning', 'message' => "{$noPhone} users missing phone number"];
            }
            
            $ok2([
                'valid' => count(array_filter($issues, fn($i) => $i['type'] === 'error')) === 0,
                'issues' => $issues,
            ], 'Validation complete');
        } catch (Exception $e) {
            $er2('Validation failed: ' . $e->getMessage(), 500);
        }
    }

    // Execute migration - move staging data to production tables
    if ($act === 'lte_execute_migration' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only', 403);
        
        // Check dry run mode
        if (!empty($config['dry_run_mode'])) {
            $er2('Migration blocked: dry_run_mode is enabled. Disable it in kyc_config.json first.', 400);
        }
        
        $pdo = $store->getPdo();
        $stats = ['subscribers' => 0, 'sims' => 0, 'subscriptions' => 0];
        
        try {
            $pdo->beginTransaction();
            
            // Migrate SIMs first (subscribers depend on them)
            $sims = $store->query("SELECT * FROM bluecard_sims WHERE mapped_to_hybrid = 0");
            foreach ($sims as $sim) {
                $stmt = $pdo->prepare("
                    INSERT INTO lte_sims (imsi, msisdn, iccid, auth_key, auth_opc, status, bluecard_id, created_at)
                    VALUES (:imsi, :msisdn, :iccid, :key, :opc, 'stock', :bcid, datetime('now'))
                ");
                $stmt->execute([
                    ':imsi' => $sim['imsi'],
                    ':msisdn' => $sim['msisdn'] ?? $sim['imsi'],
                    ':iccid' => $sim['iccid'] ?? '',
                    ':key' => $sim['auth_key'] ?? '',
                    ':opc' => $sim['auth_opc'] ?? '',
                    ':bcid' => $sim['id'],
                ]);
                $newSimId = $pdo->lastInsertId();
                $pdo->exec("UPDATE bluecard_sims SET mapped_to_hybrid = 1, hybrid_sim_id = {$newSimId} WHERE id = {$sim['id']}");
                $stats['sims']++;
            }
            
            // Migrate subscribers
            $users = $store->query("SELECT * FROM bluecard_users WHERE mapped_to_hybrid = 0");
            foreach ($users as $user) {
                // Find matching SIM
                $simId = null;
                if (!empty($user['sim_id'])) {
                    $simId = $pdo->query("SELECT hybrid_sim_id FROM bluecard_sims WHERE id = {$user['sim_id']}")->fetchColumn();
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO lte_subscribers (name, phone, email, address, area, imsi, msisdn, sim_id, status, bluecard_id, registered_by, created_at)
                    VALUES (:name, :phone, :email, :addr, :area, :imsi, :msisdn, :simid, :status, :bcid, 'migration', datetime('now'))
                ");
                $stmt->execute([
                    ':name' => $user['name'] ?? 'Unknown',
                    ':phone' => $user['phone'] ?? '',
                    ':email' => $user['email'] ?? '',
                    ':addr' => $user['address'] ?? '',
                    ':area' => $user['area'] ?? '',
                    ':imsi' => $user['imsi'] ?? '',
                    ':msisdn' => $user['msisdn'] ?? $user['imsi'] ?? '',
                    ':simid' => $simId,
                    ':status' => $user['status'] ?? 'active',
                    ':bcid' => $user['id'],
                ]);
                $newSubId = $pdo->lastInsertId();
                $pdo->exec("UPDATE bluecard_users SET mapped_to_hybrid = 1, hybrid_subscriber_id = {$newSubId} WHERE id = {$user['id']}");
                
                // Update SIM to assigned
                if ($simId) {
                    $pdo->exec("UPDATE lte_sims SET status = 'assigned', subscriber_id = {$newSubId} WHERE id = {$simId}");
                }
                
                $stats['subscribers']++;
            }
            
            // Migrate active services as subscriptions
            $services = $store->query("
                SELECT s.*, u.hybrid_subscriber_id, p.hybrid_package_id 
                FROM bluecard_services s
                JOIN bluecard_users u ON u.id = s.user_id
                JOIN bluecard_plans p ON p.id = s.plan_id
                WHERE s.mapped_to_hybrid = 0 AND u.hybrid_subscriber_id IS NOT NULL AND p.hybrid_package_id IS NOT NULL
            ");
            foreach ($services as $svc) {
                // Get package details
                $pkg = $pdo->query("SELECT * FROM lte_packages WHERE id = {$svc['hybrid_package_id']}")->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $pdo->prepare("
                    INSERT INTO lte_subscriptions (subscriber_id, package_id, package_name, package_type, package_price, magma_profile,
                        bytes_allowed, bytes_used, started_at, expires_at, status, amount_paid, payment_method, bluecard_id, created_at)
                    VALUES (:subid, :pkgid, :name, :type, :price, :profile, :bytes, :used, :start, :exp, :status, :paid, 'migration', :bcid, datetime('now'))
                ");
                $stmt->execute([
                    ':subid' => $svc['hybrid_subscriber_id'],
                    ':pkgid' => $svc['hybrid_package_id'],
                    ':name' => $pkg['name'] ?? '',
                    ':type' => $pkg['type'] ?? 0,
                    ':price' => $pkg['price'] ?? 0,
                    ':profile' => $pkg['magma_profile'] ?? 'default',
                    ':bytes' => $pkg['bytes_allowed'] ?? null,
                    ':used' => $svc['bytes_used'] ?? 0,
                    ':start' => $svc['started_at'] ?? date('Y-m-d'),
                    ':exp' => $svc['expires_at'] ?? date('Y-m-d', strtotime('+30 days')),
                    ':status' => $svc['status'] ?? 'active',
                    ':paid' => $svc['amount_paid'] ?? $pkg['price'] ?? 0,
                    ':bcid' => $svc['id'],
                ]);
                $pdo->exec("UPDATE bluecard_services SET mapped_to_hybrid = 1 WHERE id = {$svc['id']}");
                $stats['subscriptions']++;
            }
            
            $pdo->commit();
            
            // Log migration
            $pdo->exec("INSERT INTO bluecard_import_log (action, status, details, started_at, completed_at) VALUES (
                'execute_migration', 'completed', '" . json_encode($stats) . "', datetime('now'), datetime('now')
            )");
            
            $ok2($stats, 'Migration completed successfully');
        } catch (Exception $e) {
            $pdo->rollBack();
            $er2('Migration failed: ' . $e->getMessage(), 500);
        }
    }

    // Get migration summary
    if ($act === 'lte_migration_summary' && $met === 'GET') {
        if (!$isAdmin) $er2('Admin only', 403);
        
        $pdo = $store->getPdo();
        $summary = [];
        
        try {
            // BlueCard staging counts
            $summary['bluecard'] = [
                'users' => (int)$pdo->query("SELECT COUNT(*) FROM bluecard_users")->fetchColumn(),
                'users_mapped' => (int)$pdo->query("SELECT COUNT(*) FROM bluecard_users WHERE mapped_to_hybrid = 1")->fetchColumn(),
                'sims' => (int)$pdo->query("SELECT COUNT(*) FROM bluecard_sims")->fetchColumn(),
                'sims_mapped' => (int)$pdo->query("SELECT COUNT(*) FROM bluecard_sims WHERE mapped_to_hybrid = 1")->fetchColumn(),
                'plans' => (int)$pdo->query("SELECT COUNT(*) FROM bluecard_plans")->fetchColumn(),
                'plans_mapped' => (int)$pdo->query("SELECT COUNT(*) FROM bluecard_plans WHERE mapped_to_hybrid = 1")->fetchColumn(),
                'services' => (int)$pdo->query("SELECT COUNT(*) FROM bluecard_services")->fetchColumn(),
                'services_mapped' => (int)$pdo->query("SELECT COUNT(*) FROM bluecard_services WHERE mapped_to_hybrid = 1")->fetchColumn(),
            ];
            
            // Hybrid production counts
            $summary['hybrid'] = [
                'subscribers' => (int)$pdo->query("SELECT COUNT(*) FROM lte_subscribers WHERE deleted_at IS NULL")->fetchColumn(),
                'sims' => (int)$pdo->query("SELECT COUNT(*) FROM lte_sims WHERE deleted_at IS NULL")->fetchColumn(),
                'packages' => (int)$pdo->query("SELECT COUNT(*) FROM lte_packages")->fetchColumn(),
                'subscriptions' => (int)$pdo->query("SELECT COUNT(*) FROM lte_subscriptions")->fetchColumn(),
            ];
            
            $ok2($summary, 'Summary loaded');
        } catch (Exception $e) {
            $er2('Summary failed: ' . $e->getMessage(), 500);
        }
    }

    /* 
       TEST / DEBUG ENDPOINTS
       Access: Admin session OR ?debug_key=<webhook_secret>
     */



// BlueCard: local table row counts
if ($act === 'lte_local_counts' && $met === 'GET') {
    if (!($me2['is_admin'] ?? false)) $er2('Admin only', 403);
    $pdo2 = $store->getPdo();
    $counts = [];
    $tables = ['lte_subscribers','lte_sims','lte_packages','lte_renewals',
               'lte_data_mgmt','lte_agents','lte_passbooks','lte_load_money'];
    foreach ($tables as $t) {
        try {
            $counts[$t] = (int)$pdo2->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        } catch (Throwable $e) {
            $counts[$t] = 'missing';
        }
    }
    // Also get feed status
    require_once dirname(__DIR__, 2) . '/lib/BlueCardDb.php';
    $feedAlive = BlueCardDb::isConnected($config);
    $ok2(['counts' => $counts, 'feed_connected' => $feedAlive,
          'last_sync' => $store->load('lte_sync_state.json')['last_sync'] ?? 'never']);
}

// BlueCard: force full re-sync (clears cursors)
if ($act === 'lte_sync_force' && $met === 'POST') {
    if (!($me2['is_admin'] ?? false)) $er2('Admin only', 403);
    $stateFile = $dataDir . '/lte_sync_state.json';
    // Clear all cursors so next cron run syncs everything from scratch
    $state = ['users_max_id'=>0,'sims_max_id'=>0,'topup_max_id'=>0,
              'datamgmt_max_id'=>0,'passbook_max_id'=>0,'loadmoney_max_id'=>0,
              'usages_max_id'=>0,'items_max_id'=>0];
    file_put_contents($stateFile, json_encode($state));
    // Also reset repair flags so renewals re-sync
    $ok2(['reset' => true, 'message' => 'Cursors cleared. Next cron run will do full re-sync. Wait 5 minutes.']);
}



// lte_sync_inline REMOVED - too dangerous (causes SQLite lock conflicts)
// Use SSH: docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-telecom/cron_lte_sync.php

// BlueCard: manual sync trigger (runs sync inline, returns result)
if ($act === 'lte_sync_run' && $met === 'POST') {
    if (!($me2['is_admin'] ?? false)) $er2('Admin only', 403);

    // Make sure sync is enabled
    $config['lte_sync_enabled'] = true;
    $store->save('kyc_config.json', $config);

    $feedUrl   = rtrim($config['lte_feed_url']   ?? '', '/');
    $feedToken = $config['lte_feed_token'] ?? '';

    if (!$feedUrl) $er2('Feed URL not configured', 422);

    // Test the feed first
    $testUrl = $feedUrl . '?table=bc_ping&token=' . urlencode($feedToken);
    $ch = curl_init($testUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0]);
    $pingBody = curl_exec($ch);
    $pingCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $pingErr  = curl_error($ch);
    curl_close($ch);

    if ($pingErr || $pingCode !== 200) {
        $er2("Feed unreachable: HTTP {$pingCode} | curl: {$pingErr}", 500);
    }

    // Test users endpoint
    $usersUrl = $feedUrl . '?table=users&since_id=0&limit=5&token=' . urlencode($feedToken);
    $ch2 = curl_init($usersUrl);
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0]);
    $usersBody = curl_exec($ch2);
    $usersCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    $usersData = json_decode($usersBody, true);

    // Test agents endpoint
    $agentsUrl = $feedUrl . '?table=agents&token=' . urlencode($feedToken);
    $ch3 = curl_init($agentsUrl);
    curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0]);
    $agentsBody = curl_exec($ch3);
    $agentsCode = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
    curl_close($ch3);
    $agentsData = json_decode($agentsBody, true);

    // Check state file
    $stateFile = $dataDir . '/lte_sync_state.json';
    $state = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];

    // Check cron master registration
    $masterFile = $dataDir . '/../cron/master.php';
    $masterExists = file_exists($masterFile);
    $cronRegistered = $masterExists ? (strpos(file_get_contents($masterFile), 'lte_sync') !== false) : false;

    $ok2([
        'ping'         => ['code' => $pingCode, 'body' => substr($pingBody, 0, 200)],
        'users_test'   => ['code' => $usersCode, 'count' => count($usersData['data'] ?? []), 'ok' => !empty($usersData['ok']), 'error' => $usersData['error'] ?? null],
        'agents_test'  => ['code' => $agentsCode, 'count' => count($agentsData['data'] ?? []), 'ok' => !empty($agentsData['ok']), 'error' => $agentsData['error'] ?? null],
        'state_file'   => $stateFile,
        'state'        => $state,
        'sync_enabled' => $config['lte_sync_enabled'] ?? false,
        'cron_registered' => $cronRegistered,
        'data_dir'     => $dataDir,
    ]);
}

// BlueCard: save feed config
if ($act === 'bc_save_config' && $met === 'POST') {
    if (!($me2['is_admin'] ?? false)) $er2('Admin only', 403);
    $feedUrl   = trim($body['feed_url']   ?? '');
    $feedToken = trim($body['feed_token'] ?? '');
    if (!$feedUrl) $er2('feed_url is required', 422);
    $config['lte_feed_url']   = rtrim($feedUrl, '/');
    if ($feedToken !== '') $config['lte_feed_token'] = $feedToken;
    $config['lte_sync_enabled'] = true;
    $store->save('kyc_config.json', $config);
    $ok2(['saved' => true, 'feed_url' => $config['lte_feed_url']]);
}

// BlueCard: test connection via bc_ping
if ($act === 'bc_test_config' && $met === 'POST') {
    if (!($me2['is_admin'] ?? false)) $er2('Admin only', 403);
    if (!empty($body['feed_url']))   $config['lte_feed_url']   = rtrim(trim($body['feed_url']), '/');
    if (!empty($body['feed_token'])) $config['lte_feed_token'] = trim($body['feed_token']);
    require_once dirname(__DIR__, 2) . '/lib/BlueCardDb.php';
    BlueCardDb::reset();
    $result = BlueCardDb::test($config);
    $ok2($result);
}
