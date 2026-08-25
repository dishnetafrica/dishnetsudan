<?php
/**
 * cron_lte_sync.php  BlueCard MySQL to Plugin SQLite Sync
 *
 * Runs every 5 minutes via cron/master.php
 * Polls lte_feed.php (HTTP bridge on WHM 162.241.149.144) via curl
 * Upserts into EXISTING plugin SQLite tables (migrations 020-026)
 *
 * Key data facts (confirmed from BlueCard dashboard):
 *   - users: company_id=1 (NOT type=3), type column is NULL for customers
 *   - services.state: contains IMSI, not a status string
 *   - product_offering.amount: in CENTS (4000 = $40)  divide by 100
 *   - balance_topup.amount: in DOLLARS (40.00 = $40)  do NOT divide
 *   - 152 active SIMs, 6478 deactivated, 5304 in stock
 *   - ~8 active retailers doing topups
 */

set_time_limit(300);

$pluginRoot = realpath(__DIR__);

// Use proper UCRM persistent data directory (not __DIR__/data)
require_once $pluginRoot . '/lib/bootstrap_data.php';
$dataDir = getDataDir($pluginRoot);

$lockFile = $dataDir . '/lte_sync.lock';
$lockFp   = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    return;
}

$configFile = $dataDir . '/kyc_config.json';
if (!file_exists($configFile)) {
    flock($lockFp, LOCK_UN); fclose($lockFp); return;
}
$config = json_decode(file_get_contents($configFile), true);

// Auto-enable sync if feed URL is configured
if (empty($config['lte_feed_url'])) {
    // No URL configured at all  use hardcoded default
    $config['lte_feed_url']   = 'https://dishnetss.com/lte_feed.php';
    $config['lte_feed_token'] = 'dN4g-LtEfEeD-2026-sEcReT';
}
// Always run sync if URL is set (lte_sync_enabled flag no longer required)

$feedUrl   = rtrim($config['lte_feed_url'] ?? 'https://162.241.149.144/lte_feed.php', '/');
$feedToken = $config['lte_feed_token'] ?? 'dN4g-LtEfEeD-2026-sEcReT';

if (!$feedUrl) {
    _lsLog('ERROR', 'Feed URL is empty');
    flock($lockFp, LOCK_UN); fclose($lockFp); return;
}

$dbFile = $dataDir . '/plugin.sqlite3';
if (!file_exists($dbFile)) {
    _lsLog('ERROR', 'SQLite database not found');
    flock($lockFp, LOCK_UN); fclose($lockFp); return;
}

try {
    $lsDb = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $lsDb->exec('PRAGMA journal_mode=WAL');
    $lsDb->exec('PRAGMA busy_timeout=5000');
} catch (PDOException $e) {
    _lsLog('ERROR', 'SQLite failed: ' . $e->getMessage());
    flock($lockFp, LOCK_UN); fclose($lockFp); return;
}

_lsEnsureSchema($lsDb);

$stateFile = $dataDir . '/lte_sync_state.json';
$lsState = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
if (!is_array($lsState)) { $lsState = []; }

$lsStats = ['started_at' => date('Y-m-d H:i:s'), 'tables' => []];

// 1. Packages
$lsStats['tables']['packages'] = _lsSyncPackages($lsDb, $feedUrl, $feedToken);

// 2. Subscribers
$cursor = isset($lsState['users_max_id']) ? (int)$lsState['users_max_id'] : 0;
$r = _lsSyncSubscribers($lsDb, $feedUrl, $feedToken, $cursor);
$lsStats['tables']['subscribers'] = $r;
if ($r['max_id'] > 0) { $lsState['users_max_id'] = $r['max_id']; }

// 3. SIMs
$cursor = isset($lsState['sims_max_id']) ? (int)$lsState['sims_max_id'] : 0;
$r = _lsSyncSims($lsDb, $feedUrl, $feedToken, $cursor);
$lsStats['tables']['sims'] = $r;
if ($r['max_id'] > 0) { $lsState['sims_max_id'] = $r['max_id']; }

// 3b. Items (warehouse stock SIMs)  also into lte_sims
$cursor = isset($lsState['items_max_id']) ? (int)$lsState['items_max_id'] : 0;
$r = _lsSyncItems($lsDb, $feedUrl, $feedToken, $cursor);
$lsStats['tables']['items'] = $r;
if ($r['max_id'] > 0) { $lsState['items_max_id'] = $r['max_id']; }

// 4. Renewals
$cursor = isset($lsState['topup_max_id']) ? (int)$lsState['topup_max_id'] : 0;
$r = _lsSyncRenewals($lsDb, $feedUrl, $feedToken, $cursor);
$lsStats['tables']['renewals'] = $r;
if ($r['max_id'] > 0) { $lsState['topup_max_id'] = $r['max_id']; }

// 5. Data mgmt
$cursor = isset($lsState['datamgmt_max_id']) ? (int)$lsState['datamgmt_max_id'] : 0;
$r = _lsSyncDataMgmt($lsDb, $feedUrl, $feedToken, $cursor);
$lsStats['tables']['data_mgmt'] = $r;
if ($r['max_id'] > 0) { $lsState['datamgmt_max_id'] = $r['max_id']; }

// 5b. Usages (per-session octets from Magma billing)
$cursor = isset($lsState['usages_max_id']) ? (int)$lsState['usages_max_id'] : 0;
$r = _lsSyncUsages($lsDb, $feedUrl, $feedToken, $cursor);
$lsStats['tables']['usages'] = $r;
if ($r['max_id'] > 0) { $lsState['usages_max_id'] = $r['max_id']; }

// 6. Counts cache
$countsFeed = _lsFetchFeed($feedUrl, $feedToken, 'counts');
if ($countsFeed && isset($countsFeed['data'])) {
    $lsState['bluecard_counts'] = $countsFeed['data'];
}

// 7. Revenue dashboard data
$revFeed = _lsFetchFeed($feedUrl, $feedToken, 'revenue');
if ($revFeed && isset($revFeed['data'])) {
    $lsState['lte_revenue'] = $revFeed['data'];
}

// 8. Tag retailers in lte_subscribers (role_id=4 from user_management)
$retFeed = _lsFetchFeed($feedUrl, $feedToken, 'retailers');
if ($retFeed && !empty($retFeed['data'])) {
    $tagStmt = $lsDb->prepare("UPDATE lte_subscribers SET service_type='retailer', agent_name=:dealer WHERE bluecard_id=:bc_id");
    foreach ($retFeed['data'] as $ret) {
        $tagStmt->execute([
            ':dealer' => 'dealer_' . ($ret['master_user_id'] ?? ''),
            ':bc_id'  => (int)$ret['id'],
        ]);
    }
    $lsStats['tables']['retailers_tagged'] = count($retFeed['data']);
}

// 9b. Sync agents (retailers/dealers/franchisees)
$r = _lsSyncAgents($lsDb, $feedUrl, $feedToken);
$lsStats['tables']['agents'] = $r;

// 9c. Sync passbooks (incremental by since_id)
$cursor = isset($lsState['passbook_max_id']) ? (int)$lsState['passbook_max_id'] : 0;
$r = _lsSyncPassbooks($lsDb, $feedUrl, $feedToken, $cursor);
$lsStats['tables']['passbooks'] = $r;
if (!empty($r['max_id'])) { $lsState['passbook_max_id'] = $r['max_id']; }

// 9d. Sync load money (incremental by since_id)
$cursor = isset($lsState['loadmoney_max_id']) ? (int)$lsState['loadmoney_max_id'] : 0;
$r = _lsSyncLoadMoney($lsDb, $feedUrl, $feedToken, $cursor);
$lsStats['tables']['load_money'] = $r;
if (!empty($r['max_id'])) { $lsState['loadmoney_max_id'] = $r['max_id']; }

// 9. Repair renewals  full re-sync with correct subscriber mapping
//    Also re-runs if amount_fix not yet applied (v4.9.17: removed erroneous 100)
$renewalRepairDone = !empty($lsState['renewal_repair_done']);
$amountFixDone     = !empty($lsState['renewal_amount_fix_done']);
$renewalCount = 0;
try { $renewalCount = (int)$lsDb->query("SELECT COUNT(*) FROM lte_renewals")->fetchColumn(); } catch (\Exception $e) {}
if (!$renewalRepairDone || !$amountFixDone || $renewalCount < 100) {
    try {
    $subMap2 = [];
    $smRows = $lsDb->query("SELECT id, bluecard_id FROM lte_subscribers WHERE bluecard_id IS NOT NULL AND bluecard_id > 0")->fetchAll();
    foreach ($smRows as $sr) { $subMap2[(int)$sr['bluecard_id']] = (int)$sr['id']; }

    $pkgMap2 = [];
    $pmRows = $lsDb->query("SELECT id, bluecard_id FROM lte_packages WHERE bluecard_id IS NOT NULL")->fetchAll();
    foreach ($pmRows as $pr) { $pkgMap2[(int)$pr['bluecard_id']] = (int)$pr['id']; }

    // Safety check: verify API is reachable BEFORE wiping the table.
    // If the feed returns nothing on the first page, abort — never delete
    // all renewals when the source is unreachable or empty.
    $_preCheck = _lsFetchFeed($feedUrl, $feedToken, 'balance_topup', ['since_id' => 0, 'limit' => 1]);
    if (!$_preCheck || !isset($_preCheck['data'])) {
        _lsLog('WARN', 'Renewal repair aborted — feed pre-check returned no data. lte_renewals unchanged.');
        throw new \Exception('Feed pre-check failed — lte_renewals NOT deleted');
    }

    // Clear old renewals and re-sync
    $lsDb->exec("DELETE FROM lte_renewals");

    $rStmt = $lsDb->prepare("INSERT INTO lte_renewals (
        bluecard_topup_id, subscriber_id, package_id,
        amount_paid, is_addon, topup_end_date,
        cancelled_by, cancel_reason, network, created_at, synced_at
    ) VALUES (
        :bc_id, :sub, :pkg,
        :amt, :addon, :end,
        :cancel_by, :reason, :net, :cat, :synced
    )");

    $rMaxId = 0;
    $rSynced = 0;
    $rErrors = [];
    for ($rp = 0; $rp < 40; $rp++) {
        $rFeed = _lsFetchFeed($feedUrl, $feedToken, 'balance_topup', ['since_id'=>$rMaxId, 'limit'=>500]);
        if (!$rFeed || empty($rFeed['data'])) { break; }

        $lsDb->beginTransaction();
        foreach ($rFeed['data'] as $tr) {
            $tid = (int)$tr['id'];
            $bcUser  = $tr['user_id'] ? (int)$tr['user_id'] : null;
            $bcOffer = $tr['offer_id'] ? (int)$tr['offer_id'] : null;
            try {
                $rStmt->execute([
                    ':bc_id'     => $tid,
                    ':sub'       => $bcUser ? ($subMap2[$bcUser] ?? 0) : 0,
                    ':pkg'       => $bcOffer ? ($pkgMap2[$bcOffer] ?? 0) : 0,
                    ':amt'       => round((float)($tr['amount'] ?? 0), 2),  // already dollars
                    ':addon'     => (int)($tr['is_addon'] ?? 0),
                    ':end'       => $tr['end_date'] ?? null,
                    ':cancel_by' => $tr['cancelled_by'] ?? null,
                    ':reason'    => $tr['reasons'] ?? null,
                    ':net'       => 'dishnet_4g',
                    ':cat'       => $tr['created_at'] ?? null,
                    ':synced'    => date('Y-m-d H:i:s'),
                ]);
                $rSynced++;
            } catch (\Exception $re) {
                if (count($rErrors) < 5) { $rErrors[] = 'id='.$tid.': '.$re->getMessage(); }
            }
            if ($tid > $rMaxId) { $rMaxId = $tid; }
        }
        $lsDb->commit();
        if (count($rFeed['data']) < 500) { break; }
    }
    $lsState['topup_max_id'] = $rMaxId;
    if ($rSynced > 0) {
        $lsState['renewal_repair_done'] = true;
        $lsState['renewal_amount_fix_done'] = true;
    }
    $lsStats['tables']['renewals_repair'] = ['synced' => $rSynced, 'max_id' => $rMaxId, 'errors' => $rErrors];
} catch (\Exception $e) {
    _lsLog('WARN', 'Renewal repair: ' . $e->getMessage());
}
} // end renewal repair

// 10. Recalculate subscriber active/suspended based on unexpired topups
try {
    // Mark all as suspended first
    $lsDb->exec("UPDATE lte_subscribers SET status='suspended', magma_state='INACTIVE' WHERE deleted_at IS NULL");

    // Then mark active: anyone with an unexpired topup.
    // Use date('now') not datetime('now')  BlueCard stores end_date as YYYY-MM-DD (no time component).
    // Comparing '2026-03-16' >= '2026-03-16 14:30:00' is FALSE in SQLite string comparison,
    // causing subscribers whose plan expires TODAY to appear suspended. date('now') = '2026-03-16' fixes this.
    $lsDb->exec("UPDATE lte_subscribers SET status='active', magma_state='ACTIVE'
        WHERE id IN (
            SELECT DISTINCT r.subscriber_id FROM lte_renewals r
            WHERE r.subscriber_id > 0
              AND r.topup_end_date IS NOT NULL
              AND date(r.topup_end_date) >= date('now')
              AND r.cancelled_by IS NULL
        ) AND deleted_at IS NULL");

    $activeCount = (int)$lsDb->query("SELECT COUNT(*) FROM lte_subscribers WHERE status='active' AND deleted_at IS NULL")->fetchColumn();
    $lsStats['tables']['active_recalc'] = $activeCount;
} catch (\Exception $e) {
    _lsLog('WARN', 'Active recalc: ' . $e->getMessage());
}

// 10b. Rebuild lte_subscriptions table from renewals
//      Latest non-cancelled renewal per subscriber = current plan.
//      This table is used by LteSqliteService for dashboard JOINs,
//      usage sync (data-cap tracking), and renewal queue queries.
try {
    // Safety: only wipe subscriptions if lte_renewals has data to rebuild from.
    $renewalCheck = (int)$lsDb->query("SELECT COUNT(*) FROM lte_renewals")->fetchColumn();
    if ($renewalCheck === 0) {
        _lsLog('WARN', 'lte_subscriptions rebuild skipped — lte_renewals is empty. Table unchanged.');
        throw new \Exception('lte_renewals empty — subscriptions NOT wiped');
    }

    $lsDb->exec("DELETE FROM lte_subscriptions");

    // Get latest renewal per subscriber (ORDER BY r.id DESC, first seen wins)
    $subRows = $lsDb->query("SELECT r.subscriber_id, r.package_id,
        COALESCE(p.name, 'Plan #' || r.package_id) as package_name,
        COALESCE(p.type, 2) as package_type,
        COALESCE(p.magma_profile, p.sub_profile, 'default') as magma_profile,
        r.topup_end_date as expires_at, r.created_at as started_at,
        r.amount_paid,
        CASE
            WHEN r.topup_end_date IS NOT NULL AND date(r.topup_end_date) >= date('now') THEN 'active'
            WHEN r.topup_end_date IS NOT NULL AND date(r.topup_end_date) < date('now') THEN 'expired'
            ELSE 'unknown'
        END as status,
        p.bytes_allowed, p.duration_days, p.price
        FROM lte_renewals r
        LEFT JOIN lte_packages p ON p.id = r.package_id
        WHERE r.subscriber_id > 0
          AND r.cancelled_by IS NULL
        ORDER BY r.id DESC")->fetchAll();

    $subInsert = $lsDb->prepare("INSERT INTO lte_subscriptions (
        subscriber_id, package_id, package_name, package_type, magma_profile,
        started_at, expires_at, bytes_allowed, status, amount_paid, created_at, updated_at
    ) VALUES (
        :sub, :pkg, :pname, :ptype, :profile,
        :start, :expires, :bytes, :status, :amt, :start, :now
    )");

    $subSeen2 = [];
    $subCount = 0;
    $now2 = date('Y-m-d H:i:s');
    $lsDb->beginTransaction();
    foreach ($subRows as $sr) {
        $sid = (int)($sr['subscriber_id'] ?? 0);
        if ($sid <= 0 || isset($subSeen2[$sid])) { continue; }
        $subSeen2[$sid] = true;
        $subInsert->execute([
            ':sub'     => $sid,
            ':pkg'     => (int)($sr['package_id'] ?? 0),
            ':pname'   => $sr['package_name'] ?? '',
            ':ptype'   => (int)($sr['package_type'] ?? 2),
            ':profile' => $sr['magma_profile'] ?? 'default',
            ':start'   => $sr['started_at'] ?? null,
            ':expires' => $sr['expires_at'] ?? null,
            ':bytes'   => $sr['bytes_allowed'] ?? null,
            ':status'  => $sr['status'] ?? 'unknown',
            ':amt'     => (float)($sr['amount_paid'] ?? 0),
            ':now'     => $now2,
        ]);
        $subCount++;
    }
    $lsDb->commit();
    $lsStats['tables']['subscriptions_rebuilt'] = $subCount;
    _lsLog('OK', "Rebuilt lte_subscriptions: {$subCount} rows");
} catch (\Exception $e) {
    if ($lsDb->inTransaction()) { $lsDb->rollBack(); }
    _lsLog('WARN', 'Subscriptions rebuild: ' . $e->getMessage());
}

// 11. Export SQLite  JSON files so existing LteService dashboard works
_lsExportToJson($lsDb, $dataDir);

$lsStats['finished_at'] = date('Y-m-d H:i:s');
$lsState['last_sync']   = date('Y-m-d H:i:s');
$lsState['last_stats']  = $lsStats;
file_put_contents($stateFile, json_encode($lsState, JSON_PRETTY_PRINT));

_lsLog('OK', 'Sync complete', $lsStats);

flock($lockFp, LOCK_UN);
fclose($lockFp);


// 
function _lsFetchFeed($feedUrl, $feedToken, $table, $params = [])
{
    $url = $feedUrl . '?table=' . urlencode($table);
    foreach ($params as $k => $v) {
        $url .= '&' . urlencode($k) . '=' . urlencode($v);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['X-Feed-Token: ' . $feedToken, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) { _lsLog('WARN', "Curl ({$table}): {$err}"); return null; }
    if ($code !== 200) { _lsLog('WARN', "HTTP {$code} for {$table}"); return null; }
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['ok'])) { _lsLog('WARN', "Bad JSON for {$table}"); return null; }
    return $data;
}

// 
function _lsSyncPackages($db, $feedUrl, $feedToken)
{
    $feed = _lsFetchFeed($feedUrl, $feedToken, 'product_offering');
    if (!$feed) { return ['status' => 'error', 'synced' => 0]; }
    $rows = $feed['data'] ?? [];
    $synced = 0;

    $sqlU = "UPDATE lte_packages SET
        name=:name, description=:desc, price=:price, price_cents=:cents,
        bytes_allowed=:bytes, duration_days=:days, type=:type,
        sub_profile=:profile, is_active=:active, is_popular=:popular,
        sort_order=:sort, network=:net, synced_at=:synced, updated_at=:upd
        WHERE bluecard_id=:bc_id";
    $stU = $db->prepare($sqlU);

    $sqlI = "INSERT OR IGNORE INTO lte_packages (
        bluecard_id, name, description, price, price_cents, bytes_allowed,
        duration_days, type, type_label, magma_profile, active_apns,
        active_policies, is_active, is_popular, sort_order, network,
        synced_at, created_at
    ) VALUES (
        :bc_id, :name, :desc, :price, :cents, :bytes,
        :days, :type, :tlabel, :profile, :apns,
        :policies, :active, :popular, :sort, :net,
        :synced, :cat
    )";
    $stI = $db->prepare($sqlI);

    foreach ($rows as $row) {
        $bcId  = (int)$row['id'];
        $cents = (int)($row['amount'] ?? 0);
        $price = round($cents / 100, 2);
        $type  = (int)($row['type'] ?? 2);
        $now   = date('Y-m-d H:i:s');
        $prof  = $row['sub_profile'] ?? 'default';

        $stU->execute([':name'=>$row['name']??'', ':desc'=>$row['description']??'',
            ':price'=>$price, ':cents'=>$cents, ':bytes'=>($row['Bytes'] !== null ? (int)$row['Bytes'] : null),
            ':days'=>(int)($row['days']??0), ':type'=>$type, ':profile'=>$prof,
            ':active'=>(int)($row['is_active']??0), ':popular'=>(int)($row['is_popular']??0),
            ':sort'=>(int)($row['sorting_no']??0), ':net'=>'dishnet_4g',
            ':synced'=>$now, ':upd'=>$row['updated_at']??$now, ':bc_id'=>$bcId]);

        if ($stU->rowCount() === 0) {
            $stI->execute([':bc_id'=>$bcId, ':name'=>$row['name']??'', ':desc'=>$row['description']??'',
                ':price'=>$price, ':cents'=>$cents, ':bytes'=>($row['Bytes'] !== null ? (int)$row['Bytes'] : null),
                ':days'=>(int)($row['days']??0), ':type'=>$type, ':tlabel'=>($type===0?'data_cap':'unlimited'),
                ':profile'=>$prof, ':apns'=>$row['active_apns']??'cmnet',
                ':policies'=>$row['active_policies']??'omni',
                ':active'=>(int)($row['is_active']??0), ':popular'=>(int)($row['is_popular']??0),
                ':sort'=>(int)($row['sorting_no']??0), ':net'=>'dishnet_4g',
                ':synced'=>$now, ':cat'=>$row['created_at']??$now]);
        }
        $synced++;
    }
    return ['status' => 'ok', 'synced' => $synced];
}

// 
function _lsSyncSubscribers($db, $feedUrl, $feedToken, $cursor)
{
    $total = 0; $maxId = $cursor;
    $sqlUpdateByBc = "UPDATE lte_subscribers SET
        name=:name, phone=:phone, email=:email, address=:addr, area=:area,
        imsi=:imsi, msisdn=:msisdn, status=:status, magma_state=:magma,
        gps_lat=:lat, gps_lon=:lon, wallet_balance=:wallet, offer_id=:offer,
        end_date=:end, agent_id=:agent, bluecard_id=:bc_id,
        updated_at=:upd, synced_at=:synced
        WHERE bluecard_id=:bc_id2";
    $stUBc = $db->prepare($sqlUpdateByBc);

    // Fallback: update by IMSI if bluecard_id not set yet on old records
    $sqlUpdateByImsi = "UPDATE lte_subscribers SET
        name=:name, phone=:phone, email=:email, address=:addr, area=:area,
        msisdn=:msisdn, status=:status, magma_state=:magma,
        gps_lat=:lat, gps_lon=:lon, wallet_balance=:wallet, offer_id=:offer,
        end_date=:end, agent_id=:agent, bluecard_id=:bc_id,
        updated_at=:upd, synced_at=:synced
        WHERE imsi=:imsi AND (bluecard_id IS NULL OR bluecard_id=0)";
    $stUIm = $db->prepare($sqlUpdateByImsi);

    $sqlInsert = "INSERT INTO lte_subscribers (
        bluecard_id, name, phone, email, address, area,
        imsi, msisdn, status, magma_state,
        gps_lat, gps_lon, wallet_balance, offer_id, end_date,
        company_id, network, registered_by, agent_id,
        created_at, updated_at, synced_at
    ) VALUES (
        :bc_id, :name, :phone, :email, :addr, :area,
        :imsi, :msisdn, :status, :magma,
        :lat, :lon, :wallet, :offer, :end,
        :comp, :net, :reg, :agent,
        :cat, :upd, :synced
    )";
    $stI = $db->prepare($sqlInsert);

    for ($pg = 0; $pg < 20; $pg++) {
        $feed = _lsFetchFeed($feedUrl, $feedToken, 'users', ['since_id'=>$maxId, 'limit'=>500]);
        if (!$feed || empty($feed['data'])) { break; }
        $rows = $feed['data'];
        $db->beginTransaction();
        foreach ($rows as $r) {
            $id   = (int)$r['id'];
            $imsi = trim($r['imsi'] ?? '');
            $name = trim(($r['firstname']??'').' '.($r['lastname']??''));
            $phone = trim($r['whatsapp_number'] ?? '');
            if (!$phone || substr($phone,0,3)==='460') {
                $m = trim($r['mobile'] ?? '');
                if ($m && substr($m,0,3)!=='460') { $phone = $m; }
            }
            $isDe = (int)($r['is_deactive']??0);
            $isAc = (int)($r['is_active']??0);
            $sts  = ($isDe || !$isAc) ? 'suspended' : 'active';
            $mag  = ($isDe || !$isAc) ? 'INACTIVE' : 'ACTIVE';
            $now  = date('Y-m-d H:i:s');

            $common = [':name'=>$name, ':phone'=>$phone,
                ':email'=>$r['email']??'', ':addr'=>$r['address']??'', ':area'=>$r['area']??'',
                ':msisdn'=>$imsi, ':status'=>$sts, ':magma'=>$mag,
                ':lat'=>($r['latitude'] ? (float)$r['latitude'] : null),
                ':lon'=>($r['longitude'] ? (float)$r['longitude'] : null),
                ':wallet'=>(float)($r['wallet']??0),
                ':offer'=>($r['offer_id'] ? (int)$r['offer_id'] : null),
                ':end'=>$r['end_date']??null,
                ':agent'=>($r['ref_id'] ? (int)$r['ref_id'] : null),
                ':upd'=>$r['updated_at']??null, ':synced'=>$now,
                ':bc_id'=>$id];

            try {
                // 1. Try update by bluecard_id
                $p1 = $common; $p1[':imsi'] = $imsi; $p1[':bc_id2'] = $id;
                $stUBc->execute($p1);

                if ($stUBc->rowCount() === 0) {
                    if ($imsi) {
                        // 2. Try update by IMSI (old record without bluecard_id)
                        $p2 = $common; $p2[':imsi'] = $imsi;
                        $stUIm->execute($p2);
                    }

                    if (!$imsi || $stUIm->rowCount() === 0) {
                        // 3. Insert new record (skip if IMSI already exists)
                        try {
                            $p3 = $common;
                            $p3[':imsi'] = $imsi ?: ('no_imsi_' . $id);
                            $p3[':comp'] = ($r['company_id'] ? (int)$r['company_id'] : null);
                            $p3[':net']  = 'dishnet_4g';
                            $p3[':reg']  = 'bulk_import';
                            $p3[':cat']  = $r['created_at'] ?? null;
                            $stI->execute($p3);
                        } catch (\Exception $ei) {
                            // Duplicate IMSI  skip silently
                        }
                    }
                }
            } catch (\Exception $e) {
                // Skip this row, continue with next
            }

            if ($id > $maxId) { $maxId = $id; }
            $total++;
        }
        $db->commit();
        if (count($rows) < 500) { break; }
    }
    return ['status'=>'ok', 'synced'=>$total, 'max_id'=>$maxId];
}

// 
function _lsSyncSims($db, $feedUrl, $feedToken, $cursor)
{
    $total = 0; $maxId = $cursor;
    $sql = "INSERT INTO lte_sims (
        imsi, auth_key, auth_opc, auth_algo, msisdn,
        status, bluecard_sim_id, vendor, network, created_at, updated_at, synced_at
    ) VALUES (
        :imsi, :key, :opc, :algo, :msisdn,
        :sts, :bc_id, :vendor, :net, :cat, :upd, :synced
    ) ON CONFLICT(imsi) DO UPDATE SET
        auth_key=excluded.auth_key, auth_opc=excluded.auth_opc,
        status=excluded.status, updated_at=excluded.updated_at, synced_at=excluded.synced_at";
    $st = $db->prepare($sql);

    for ($pg = 0; $pg < 15; $pg++) {
        $feed = _lsFetchFeed($feedUrl, $feedToken, 'sim_cards', ['since_id'=>$maxId, 'limit'=>500]);
        if (!$feed || empty($feed['data'])) { break; }
        $rows = $feed['data'];
        $db->beginTransaction();
        try {
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $imsi = trim($r['imsi'] ?? '');
                if (!$imsi) { if ($id>$maxId){$maxId=$id;} continue; }
                $st->execute([':imsi'=>$imsi, ':key'=>$r['auth_key']??'',
                    ':opc'=>$r['opc_value']??'', ':algo'=>$r['algo']??'Milenage',
                    ':msisdn'=>$r['msisdn']??$imsi, ':sts'=>$r['status']??'stock',
                    ':bc_id'=>$id, ':vendor'=>$r['partner']??'DishNet',
                    ':net'=>'dishnet_4g', ':cat'=>$r['created_at']??null,
                    ':upd'=>$r['updated_at']??null, ':synced'=>date('Y-m-d H:i:s')]);
                if ($id>$maxId){$maxId=$id;}
                $total++;
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            _lsLog('ERROR', 'Sims pg'.$pg.': '.$e->getMessage());
            break;
        }
        if (count($rows) < 500) { break; }
    }
    return ['status'=>'ok', 'synced'=>$total, 'max_id'=>$maxId];
}

// 
function _lsSyncItems($db, $feedUrl, $feedToken, $cursor)
{
    $total = 0; $maxId = $cursor;
    // Items = warehouse stock, same schema as sim_cards but different table
    // Insert into lte_sims with status='stock', ON CONFLICT(imsi) skip
    $sql = "INSERT INTO lte_sims (
        imsi, auth_key, auth_opc, auth_algo, msisdn,
        status, bluecard_sim_id, vendor, network, created_at, updated_at, synced_at
    ) VALUES (
        :imsi, :key, :opc, :algo, :msisdn,
        :sts, :bc_id, :vendor, :net, :cat, :upd, :synced
    ) ON CONFLICT(imsi) DO UPDATE SET
        synced_at=excluded.synced_at";
    $st = $db->prepare($sql);

    for ($pg = 0; $pg < 30; $pg++) {
        $feed = _lsFetchFeed($feedUrl, $feedToken, 'items', ['since_id'=>$maxId, 'limit'=>500]);
        if (!$feed || empty($feed['data'])) { break; }
        $rows = $feed['data'];
        $db->beginTransaction();
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $imsi = trim($r['imsi'] ?? '');
            if (!$imsi) { if ($id>$maxId){$maxId=$id;} continue; }
            try {
                $st->execute([':imsi'=>$imsi, ':key'=>$r['auth_key']??'',
                    ':opc'=>$r['opc_value']??'', ':algo'=>$r['algo']??'Milenage',
                    ':msisdn'=>$r['msisdn']??$imsi, ':sts'=>'stock',
                    ':bc_id'=>$id, ':vendor'=>$r['partner']??'DishNet',
                    ':net'=>'dishnet_4g', ':cat'=>$r['created_at']??null,
                    ':upd'=>$r['updated_at']??null, ':synced'=>date('Y-m-d H:i:s')]);
                $total++;
            } catch (\Exception $e) { /* duplicate IMSI  skip */ }
            if ($id>$maxId){$maxId=$id;}
        }
        $db->commit();
        if (count($rows) < 500) { break; }
    }
    return ['status'=>'ok', 'synced'=>$total, 'max_id'=>$maxId];
}

// 
function _lsSyncRenewals($db, $feedUrl, $feedToken, $cursor)
{
    $total = 0; $maxId = $cursor;

    // Build lookup maps: bluecard_id  local id
    $subMap = []; $pkgMap = [];
    foreach ($db->query("SELECT id,bluecard_id FROM lte_subscribers WHERE bluecard_id IS NOT NULL")->fetchAll() as $r) {
        $subMap[(int)$r['bluecard_id']] = (int)$r['id'];
    }
    foreach ($db->query("SELECT id,bluecard_id FROM lte_packages WHERE bluecard_id IS NOT NULL")->fetchAll() as $r) {
        $pkgMap[(int)$r['bluecard_id']] = (int)$r['id'];
    }

    $sql = "INSERT OR IGNORE INTO lte_renewals (
        bluecard_topup_id, subscriber_id, package_id,
        amount_paid, is_addon, topup_end_date,
        cancelled_by, cancel_reason, network, created_at, synced_at
    ) VALUES (
        :bc_id, :sub, :pkg,
        :amt, :addon, :end,
        :cancel_by, :reason, :net, :cat, :synced
    )";
    $st = $db->prepare($sql);

    for ($pg = 0; $pg < 40; $pg++) {
        $feed = _lsFetchFeed($feedUrl, $feedToken, 'balance_topup', ['since_id'=>$maxId, 'limit'=>500]);
        if (!$feed || empty($feed['data'])) { break; }
        $rows = $feed['data'];
        $db->beginTransaction();
        try {
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $bcUser  = $r['user_id'] ? (int)$r['user_id'] : null;
                $bcOffer = $r['offer_id'] ? (int)$r['offer_id'] : null;
                $st->execute([
                    ':bc_id'     => $id,
                    ':sub'       => $bcUser ? ($subMap[$bcUser] ?? 0) : 0,
                    ':pkg'       => $bcOffer ? ($pkgMap[$bcOffer] ?? 0) : 0,
                    ':amt'       => round((float)($r['amount'] ?? 0), 2),  // already dollars
                    ':addon'     => (int)($r['is_addon'] ?? 0),
                    ':end'       => $r['end_date'] ?? null,
                    ':cancel_by' => $r['cancelled_by'] ?? null,
                    ':reason'    => $r['reasons'] ?? null,
                    ':net'       => 'dishnet_4g',
                    ':cat'       => $r['created_at'] ?? null,
                    ':synced'    => date('Y-m-d H:i:s'),
                ]);
                if ($id>$maxId){$maxId=$id;}
                $total++;
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            _lsLog('ERROR', 'Renewals pg'.$pg.': '.$e->getMessage());
            break;
        }
        if (count($rows) < 500) { break; }
    }
    return ['status'=>'ok', 'synced'=>$total, 'max_id'=>$maxId];
}

// 
function _lsSyncDataMgmt($db, $feedUrl, $feedToken, $cursor)
{
    $total = 0; $maxId = $cursor;
    $sql = "INSERT INTO lte_data_mgmt (
        bluecard_id, imsi, offer_id, bytes_used, prv_data, prv_data2,
        start_date, end_date, is_deactive, type, ref_id, notification,
        network, created_at, updated_at, synced_at
    ) VALUES (
        :bc_id, :imsi, :offer, :bytes, :prv, :prv2,
        :start, :end, :deact, :type, :ref, :notif,
        :net, :cat, :upd, :synced
    ) ON CONFLICT(bluecard_id) DO UPDATE SET
        bytes_used=excluded.bytes_used, prv_data=excluded.prv_data,
        prv_data2=excluded.prv_data2, end_date=excluded.end_date,
        is_deactive=excluded.is_deactive, notification=excluded.notification,
        updated_at=excluded.updated_at, synced_at=excluded.synced_at";
    $st = $db->prepare($sql);

    for ($pg = 0; $pg < 15; $pg++) {
        $feed = _lsFetchFeed($feedUrl, $feedToken, 'data_mgmt', ['since_id'=>$maxId, 'limit'=>500]);
        if (!$feed || empty($feed['data'])) { break; }
        $rows = $feed['data'];
        $db->beginTransaction();
        try {
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $st->execute([':bc_id'=>$id, ':imsi'=>$r['mobile']??'',
                    ':offer'=>($r['offer_id']?(int)$r['offer_id']:null),
                    ':bytes'=>(int)($r['data']??0), ':prv'=>(int)($r['prv_data']??0),
                    ':prv2'=>(int)($r['prv_data2']??0),
                    ':start'=>$r['start_date']??null, ':end'=>$r['end_date']??null,
                    ':deact'=>(int)($r['is_deactive']??0), ':type'=>(int)($r['type']??0),
                    ':ref'=>($r['ref_id']?(int)$r['ref_id']:null),
                    ':notif'=>$r['notification']??null,
                    ':net'=>'dishnet_4g', ':cat'=>$r['created_at']??null,
                    ':upd'=>$r['updated_at']??null, ':synced'=>date('Y-m-d H:i:s')]);
                if ($id>$maxId){$maxId=$id;}
                $total++;
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            _lsLog('ERROR', 'DataMgmt pg'.$pg.': '.$e->getMessage());
            break;
        }
        if (count($rows) < 500) { break; }
    }
    return ['status'=>'ok', 'synced'=>$total, 'max_id'=>$maxId];
}

// 
// 
// EXPORT SQLite  JSON (bridge for existing LteService dashboard)
// 

function _lsExportToJson($db, $dataDir)
{
    try {
        //  lte_subscribers.json 
        $rows = $db->query("SELECT * FROM lte_subscribers WHERE deleted_at IS NULL ORDER BY id ASC")->fetchAll();
        _lsSaveJson($dataDir . '/lte_subscribers.json', $rows);

        //  lte_packages.json 
        $rows = $db->query("SELECT * FROM lte_packages ORDER BY sort_order ASC")->fetchAll();
        _lsSaveJson($dataDir . '/lte_packages.json', $rows);

        //  lte_sims.json 
        $rows = $db->query("SELECT * FROM lte_sims WHERE deleted_at IS NULL ORDER BY id ASC")->fetchAll();
        _lsSaveJson($dataDir . '/lte_sims.json', $rows);

        //  lte_renewals.json (all  no cap) 
        $rows = $db->query("SELECT * FROM lte_renewals ORDER BY id DESC")->fetchAll();
        _lsSaveJson($dataDir . '/lte_renewals.json', $rows);

        //  lte_subscriptions.json (latest topup per subscriber = current plan) 
        $rows = $db->query("SELECT r.subscriber_id, r.package_id,
            COALESCE(p.name, 'Plan #' || r.package_id) as package_name,
            COALESCE(p.type, 2) as package_type,
            COALESCE(p.magma_profile, p.sub_profile, 'default') as magma_profile,
            r.topup_end_date as expires_at, r.created_at as started_at,
            r.amount_paid,
            CASE
                WHEN r.topup_end_date IS NOT NULL AND date(r.topup_end_date) >= date('now') THEN 'active'
                WHEN r.topup_end_date IS NOT NULL AND date(r.topup_end_date) < date('now') THEN 'expired'
                ELSE 'unknown'
            END as status,
            p.bytes_allowed, p.duration_days, p.price
            FROM lte_renewals r
            LEFT JOIN lte_packages p ON p.id = r.package_id
            WHERE r.subscriber_id > 0
              AND r.cancelled_by IS NULL
            ORDER BY r.id DESC")->fetchAll();

        $subSeen = [];
        $subs = [];
        foreach ($rows as $r) {
            $sid = (int)($r['subscriber_id'] ?? 0);
            if ($sid <= 0 || isset($subSeen[$sid])) { continue; }
            $subSeen[$sid] = true;
            $r['subscriber_id'] = $sid;
            $r['id'] = count($subs) + 1;
            $subs[] = $r;
        }
        _lsSaveJson($dataDir . '/lte_subscriptions.json', $subs);

    } catch (\Exception $e) {
        _lsLog('WARN', 'JSON export failed: ' . $e->getMessage());
    }
}

function _lsSyncUsages($db, $feedUrl, $feedToken, $sinceId = 0)
{
    $st = $db->prepare("INSERT OR IGNORE INTO lte_usages (
        bluecard_usage_id, imsi, input_octets, output_octets, total_octets,
        start_time, end_time, network, created_at, synced_at
    ) VALUES (
        :bc_id, :imsi, :input, :output, :total,
        :start, :end, :net, :cat, :synced
    )");
    $total = 0;
    $maxId = $sinceId;
    for ($pg = 0; $pg < 100; $pg++) {
        $feed = _lsFetchFeed($feedUrl, $feedToken, 'usages', ['since_id' => $maxId, 'limit' => 500]);
        if (!$feed || empty($feed['data'])) { break; }
        $rows = $feed['data'];
        try {
            $db->beginTransaction();
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $st->execute([
                    ':bc_id'  => $id,
                    ':imsi'   => $r['service_id'] ?? '',
                    ':input'  => (int)($r['inputOctets']  ?? $r['input_octets']  ?? 0),
                    ':output' => (int)($r['outputOctets'] ?? $r['output_octets'] ?? 0),
                    ':total'  => (int)($r['totalOctets']  ?? $r['total_octets']  ?? 0),
                    ':start'  => $r['startDateTime'] ?? $r['start_time'] ?? null,
                    ':end'    => $r['endDateTime']   ?? $r['end_time']   ?? null,
                    ':net'    => 'dishnet_4g',
                    ':cat'    => $r['created_at'] ?? null,
                    ':synced' => date('Y-m-d H:i:s'),
                ]);
                if ($id > $maxId) { $maxId = $id; }
                $total++;
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            _lsLog('ERROR', 'Usages pg' . $pg . ': ' . $e->getMessage());
            break;
        }
        if (count($rows) < 500) { break; }
    }
    return ['status' => 'ok', 'synced' => $total, 'max_id' => $maxId];
}

function _lsSaveJson($path, $data)
{
    $json = json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) { return; }
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) !== false) {
        rename($tmp, $path);
    } else {
        @unlink($tmp);
    }
}


// 
// _lsSyncAgents  full sync of agents/dealers/retailers
// Small table (~50-200 rows), do full replace each run
// 
function _lsSyncAgents($db, $feedUrl, $feedToken)
{
    $feed = _lsFetchFeed($feedUrl, $feedToken, 'agents');
    if (!$feed) { return ['status' => 'error', 'synced' => 0]; }
    $rows = $feed['data'] ?? [];
    $synced = 0;
    $now = date('Y-m-d H:i:s');

    $stU = $db->prepare(
        "UPDATE lte_agents SET
            firstname=:fn, lastname=:ln, name=:name, mobile=:mobile, email=:email,
            role_name=:role, role_display=:rdisplay, master_id=:master_id,
            master_name=:master_name, wallet=:wallet, lm_commission=:lm_comm,
            is_active=:active, updated_at=:upd, synced_at=:synced
         WHERE bluecard_id=:bc_id"
    );
    $stI = $db->prepare(
        "INSERT OR IGNORE INTO lte_agents
            (bluecard_id, firstname, lastname, name, mobile, email,
             role_name, role_display, master_id, master_name, wallet,
             lm_commission, is_active, network, created_at, synced_at)
         VALUES
            (:bc_id, :fn, :ln, :name, :mobile, :email,
             :role, :rdisplay, :master_id, :master_name, :wallet,
             :lm_comm, :active, 'dishnet_4g', :cat, :synced)"
    );

    $db->beginTransaction();
    foreach ($rows as $row) {
        $bcId     = (int)($row['id'] ?? 0);
        if (!$bcId) continue;
        $fn       = $row['firstname'] ?? '';
        $ln       = $row['lastname']  ?? '';
        $name     = trim("$fn $ln");
        $wallet   = round((float)($row['wallet'] ?? 0), 2);
        $lmComm   = round((float)($row['lm_commission'] ?? $row['load_money_commission'] ?? 0), 4);
        $masterName = null;
        if (!empty($row['master_fname']) || !empty($row['master_lname'])) {
            $masterName = trim(($row['master_fname'] ?? '') . ' ' . ($row['master_lname'] ?? ''));
        }
        $p = [
            ':bc_id'       => $bcId,
            ':fn'          => $fn,
            ':ln'          => $ln,
            ':name'        => $name,
            ':mobile'      => $row['mobile'] ?? null,
            ':email'       => $row['email'] ?? null,
            ':role'        => $row['role_name'] ?? 'retailer',
            ':rdisplay'    => $row['role_display'] ?? null,
            ':master_id'   => !empty($row['master_user_id']) ? (int)$row['master_user_id'] : null,
            ':master_name' => $masterName,
            ':wallet'      => $wallet,
            ':lm_comm'     => $lmComm,
            ':active'      => (int)($row['is_active'] ?? 1),
            ':upd'         => $row['updated_at'] ?? $now,
            ':synced'      => $now,
        ];
        $stU->execute($p);
        if ($stU->rowCount() === 0) {
            $p[':cat'] = $row['created_at'] ?? $now;
            $stI->execute($p);
        }
        $synced++;
    }
    $db->commit();
    return ['status' => 'ok', 'synced' => $synced];
}

// 
// _lsSyncPassbooks  incremental sync by since_id
// 
function _lsSyncPassbooks($db, $feedUrl, $feedToken, $cursor)
{
    $total = 0; $maxId = $cursor; $errors = 0;

    $stI = $db->prepare(
        "INSERT OR IGNORE INTO lte_passbooks
            (bluecard_id, bluecard_user_id, entry_type, amount,
             prev_balance, curr_balance, trx_no, description,
             network, created_at, synced_at)
         VALUES
            (:bc_id, :uid, :type, :amt,
             :prev, :curr, :trx, :desc,
             'dishnet_4g', :cat, :synced)"
    );

    $now = date('Y-m-d H:i:s');
    for ($page = 0; $page < 30; $page++) {
        $feed = _lsFetchFeed($feedUrl, $feedToken, 'passbooks', [
            'since_id' => $maxId, 'limit' => 500
        ]);
        if (!$feed || empty($feed['data'])) break;
        $rows = $feed['data'];
        $db->beginTransaction();
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if ($id > $maxId) $maxId = $id;
            try {
                $stI->execute([
                    ':bc_id'  => $id,
                    ':uid'    => !empty($row['user_id']) ? (int)$row['user_id'] : null,
                    ':type'   => $row['type'] ?? null,
                    ':amt'    => round((float)($row['amount'] ?? 0), 2),
                    ':prev'   => round((float)($row['previous_balance'] ?? 0), 2),
                    ':curr'   => round((float)($row['current_balance'] ?? 0), 2),
                    ':trx'    => $row['trx_no'] ?? null,
                    ':desc'   => $row['description'] ?? null,
                    ':cat'    => $row['created_at'] ?? null,
                    ':synced' => $now,
                ]);
                $total++;
            } catch (PDOException $e) { $errors++; }
        }
        $db->commit();
        if (count($rows) < 500) break;
    }
    return ['status' => 'ok', 'synced' => $total, 'max_id' => $maxId, 'errors' => $errors];
}

// 
// _lsSyncLoadMoney  incremental sync by since_id
// 
function _lsSyncLoadMoney($db, $feedUrl, $feedToken, $cursor)
{
    $total = 0; $maxId = $cursor; $errors = 0;

    $stI = $db->prepare(
        "INSERT OR IGNORE INTO lte_load_money
            (bluecard_id, bluecard_user_id, master_user_id, amount,
             approve_amount, commission, status, lm_type,
             network, created_at, updated_at, synced_at)
         VALUES
            (:bc_id, :uid, :master, :amt,
             :approve, :comm, :status, :type,
             'dishnet_4g', :cat, :upd, :synced)"
    );
    $stU = $db->prepare(
        "UPDATE lte_load_money SET
            status=:status, approve_amount=:approve, updated_at=:upd, synced_at=:synced
         WHERE bluecard_id=:bc_id"
    );

    $now = date('Y-m-d H:i:s');
    for ($page = 0; $page < 20; $page++) {
        $feed = _lsFetchFeed($feedUrl, $feedToken, 'load_money_sync', [
            'since_id' => $maxId, 'limit' => 500
        ]);
        if (!$feed || empty($feed['data'])) break;
        $rows = $feed['data'];
        $db->beginTransaction();
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if ($id > $maxId) $maxId = $id;
            try {
                // Try update first (status may change after initial insert)
                $stU->execute([
                    ':bc_id'   => $id,
                    ':status'  => $row['status'] ?? 'Pending',
                    ':approve' => isset($row['approve_amount']) ? round((float)$row['approve_amount'], 2) : null,
                    ':upd'     => $row['updated_at'] ?? $now,
                    ':synced'  => $now,
                ]);
                if ($stU->rowCount() === 0) {
                    $stI->execute([
                        ':bc_id'   => $id,
                        ':uid'     => !empty($row['user_id']) ? (int)$row['user_id'] : null,
                        ':master'  => !empty($row['master_user_id']) ? (int)$row['master_user_id'] : null,
                        ':amt'     => round((float)($row['amount'] ?? 0), 2),
                        ':approve' => isset($row['approve_amount']) ? round((float)$row['approve_amount'], 2) : null,
                        ':comm'    => round((float)($row['commission'] ?? 0), 2),
                        ':status'  => $row['status'] ?? 'Pending',
                        ':type'    => $row['type'] ?? null,
                        ':cat'     => $row['created_at'] ?? null,
                        ':upd'     => $row['updated_at'] ?? $now,
                        ':synced'  => $now,
                    ]);
                }
                $total++;
            } catch (PDOException $e) { $errors++; }
        }
        $db->commit();
        if (count($rows) < 500) break;
    }
    return ['status' => 'ok', 'synced' => $total, 'max_id' => $maxId, 'errors' => $errors];
}

function _lsEnsureSchema($db)
{
    $alters = [
        "ALTER TABLE lte_subscribers ADD COLUMN network TEXT DEFAULT 'dishnet_4g'",
        "ALTER TABLE lte_subscribers ADD COLUMN synced_at TEXT",
        "ALTER TABLE lte_subscribers ADD COLUMN wallet_balance REAL DEFAULT 0",
        "ALTER TABLE lte_subscribers ADD COLUMN offer_id INTEGER",
        "ALTER TABLE lte_subscribers ADD COLUMN end_date TEXT",
        "ALTER TABLE lte_subscribers ADD COLUMN company_id INTEGER",
        "ALTER TABLE lte_sims ADD COLUMN bluecard_sim_id INTEGER",
        "ALTER TABLE lte_sims ADD COLUMN network TEXT DEFAULT 'dishnet_4g'",
        "ALTER TABLE lte_sims ADD COLUMN synced_at TEXT",
        "ALTER TABLE lte_packages ADD COLUMN network TEXT DEFAULT 'dishnet_4g'",
        "ALTER TABLE lte_packages ADD COLUMN synced_at TEXT",
        "ALTER TABLE lte_packages ADD COLUMN sub_profile TEXT DEFAULT 'default'",
        "ALTER TABLE lte_renewals ADD COLUMN bluecard_topup_id INTEGER",
        "ALTER TABLE lte_renewals ADD COLUMN topup_end_date TEXT",
        "ALTER TABLE lte_renewals ADD COLUMN cancelled_by TEXT",
        "ALTER TABLE lte_renewals ADD COLUMN cancel_reason TEXT",
        "ALTER TABLE lte_renewals ADD COLUMN network TEXT DEFAULT 'dishnet_4g'",
        "ALTER TABLE lte_renewals ADD COLUMN synced_at TEXT",
    ];
    $indexes = [
        // Drop old non-unique index, replace with UNIQUE for ON CONFLICT
        "DROP INDEX IF EXISTS idx_lte_sub_bluecard",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_lte_sub_bluecard_u ON lte_subscribers(bluecard_id) WHERE bluecard_id IS NOT NULL",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_lte_ren_bc_topup ON lte_renewals(bluecard_topup_id) WHERE bluecard_topup_id IS NOT NULL",
        "CREATE INDEX IF NOT EXISTS idx_lte_sub_network ON lte_subscribers(network)",
        "CREATE INDEX IF NOT EXISTS idx_lte_sims_network ON lte_sims(network)",
        "CREATE INDEX IF NOT EXISTS idx_lte_ren_network ON lte_renewals(network)",
    ];
    $newTables = [
        "CREATE TABLE IF NOT EXISTS lte_data_mgmt (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bluecard_id INTEGER UNIQUE, imsi TEXT DEFAULT '', offer_id INTEGER,
            bytes_used INTEGER DEFAULT 0, prv_data INTEGER DEFAULT 0, prv_data2 INTEGER DEFAULT 0,
            start_date TEXT, end_date TEXT, is_deactive INTEGER DEFAULT 0, type INTEGER DEFAULT 0,
            ref_id INTEGER, notification TEXT, network TEXT DEFAULT 'dishnet_4g',
            created_at TEXT, updated_at TEXT, synced_at TEXT
        )",
        "CREATE INDEX IF NOT EXISTS idx_lte_dm_imsi ON lte_data_mgmt(imsi)",
        // lte_usages  per-session data from BlueCard usages table
        "CREATE TABLE IF NOT EXISTS lte_usages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bluecard_usage_id INTEGER UNIQUE,
            imsi TEXT NOT NULL,
            input_octets INTEGER DEFAULT 0,
            output_octets INTEGER DEFAULT 0,
            total_octets INTEGER DEFAULT 0,
            start_time TEXT,
            end_time TEXT,
            network TEXT DEFAULT 'dishnet_4g',
            created_at TEXT,
            synced_at TEXT
        )",
        "CREATE INDEX IF NOT EXISTS idx_lte_usages_imsi ON lte_usages(imsi)",
        "CREATE INDEX IF NOT EXISTS idx_lte_usages_start ON lte_usages(start_time)",
    ];
    // New tables from migration 053 (lte_agents, lte_passbooks, lte_load_money)
    $migration053 = dirname(__DIR__) . '/migrations/053_lte_bluecard_local.sql';
    if (file_exists($migration053)) {
        $sql053 = file_get_contents($migration053);
        $stmts053 = array_filter(array_map('trim', explode(';', $sql053)));
        foreach ($stmts053 as $s) {
            if ($s && strpos(ltrim($s), '--') !== 0) {
                try { $db->exec($s); } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        _lsLog('WARN', 'Migration053: ' . $e->getMessage());
                    }
                }
            }
        }
    }
    foreach ($alters as $s) { try { $db->exec($s); } catch (PDOException $e) {} }
    foreach ($indexes as $s) { try { $db->exec($s); } catch (PDOException $e) {} }
    foreach ($newTables as $s) { try { $db->exec($s); } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') === false) { _lsLog('WARN','DDL:'.$e->getMessage()); }
    }}
}

function _lsLog($level, $msg, $extra = null)
{
    $line = date('Y-m-d H:i:s') . " [{$level}] {$msg}";
    if ($extra) { $line .= ' ' . json_encode($extra); }
    @file_put_contents('/tmp/lte_sync.log', $line . "\n", FILE_APPEND);
}
