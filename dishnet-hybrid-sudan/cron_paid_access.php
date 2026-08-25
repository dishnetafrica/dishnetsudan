<?php
/**
 * cron_paid_access.php — Auto-pause devices whose time-based access has expired.
 * DishNet Hybrid v4.20.0
 *
 * LAZY POLLING ARCHITECTURE
 * -------------------------
 * Most DishNet customers will NEVER use time-based access on Starlink.
 * Hammering all 44 routers every minute would be 63,360 API calls a day
 * for zero benefit. Instead this cron:
 *
 *   1. SELECT DISTINCT router_id FROM hotspot_paid_access WHERE status='active'
 *      → returns 0 rows for the common case → exit in <10ms.
 *   2. For each router with at least one active grant, fetch live device list
 *      via dr_wifi_get_status. This is the ONLY moment we touch Starlink for
 *      this feature.
 *   3. For each row whose expires_at < now: pause the device, mark grant
 *      'expired', close the session log row.
 *
 * WHY THIS IS THE RIGHT TRADE-OFF
 * --------------------------------
 * - Zero baseline cost: no active grants → cron is essentially a no-op.
 * - Cost scales with usage: each customer using time-based control adds at
 *   most one Starlink API call per minute. A cybercafé with 10 active timers
 *   on one router still only costs 1 API call/min — we batch by router.
 * - Late expiry by up to 60s is acceptable: Wi-Fi access control is not
 *   subsecond-critical.
 *
 * HONEST LIMITS
 * --------------
 * - Pause is not a "block" — see SAFETY.md and the manifest. A determined
 *   user can MAC-spoof to dodge. For real adversarial use, customer needs
 *   DishNet Fiber + MikroTik or HotSpot Platform v3.1.
 * - If router is offline at expiry time, we mark grant 'expired' but the
 *   device wasn't actually paused. Once the router comes online the customer
 *   would need to manually pause OR rotate password if abuse is suspected.
 *
 * RUNS VIA: cron/master.php → 'paid_access' every 60s
 */
chdir(__DIR__);
date_default_timezone_set('Africa/Juba');

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

// PHP 7.4 polyfills (consistent with other crons)
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}

$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) {
    echo "[paid_access] No data dir.\n";
    return;
}

$store = \SqliteStore::create($dataDir);
$pdo   = $store->getPdo();

// Defensive — if the table doesn't exist yet (first run after upgrade,
// before any web request triggered ca_init_tables), exit cleanly.
try {
    $pdo->query("SELECT 1 FROM hotspot_paid_access LIMIT 1");
} catch (\Throwable $e) {
    // Table doesn't exist — first deploy; web request will create it later.
    return;
}

$now = time();

// ── LAZY POLL GUARD ───────────────────────────────────────────────────
// Find routers that ACTUALLY have active grants. If none, exit now —
// no Starlink API calls, no work, no log noise.
$routersStmt = $pdo->query("
    SELECT DISTINCT router_id
    FROM hotspot_paid_access
    WHERE status = 'active'
");
$routers = $routersStmt->fetchAll(\PDO::FETCH_COLUMN);
if (empty($routers)) {
    // The common case. Don't even log — keeps master.php output clean.
    return;
}

echo "[paid_access] Checking " . count($routers) . " router(s) with active grants\n";

$expiredCount = 0;
$pauseFailures = 0;

// Curl helper for talking to dishnet-data-report's pause endpoint.
// Mirrors ca_hotspot_dr_call() but is duplicated here so the cron has
// no dependency on the web-only api_customer_app.php.
$drCall = function (string $action, array $params, string $method = 'POST'): ?array {
    $base = 'https://crm.dishnetafrica.com/crm/_plugins/dishnet-data-report/public.php';
    $ch = null;
    try {
        if ($method === 'POST') {
            $url = $base . '?action=' . urlencode($action);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        } else {
            $qs = http_build_query(array_merge(['action' => $action], $params));
            $ch = curl_init($base . '?' . $qs);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) return null;
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    } catch (\Throwable $e) {
        if ($ch) { @curl_close($ch); }
        return null;
    }
};

// Per-router work
foreach ($routers as $routerId) {
    // Find expired grants on this router. We don't NEED dr_wifi_get_status
    // to pause — the pause endpoint takes router_id + fingerprint and
    // doesn't care whether the device is currently connected. Skipping the
    // status call keeps this cron cheap. If you want to verify the device
    // is actually online before pausing (avoiding noise on offline devices),
    // re-enable the status fetch — added cost is one curl per active router.
    $expStmt = $pdo->prepare("
        SELECT id, fingerprint, started_at, expires_at, amount_ssp, amount_usd
        FROM hotspot_paid_access
        WHERE router_id = ? AND status = 'active' AND expires_at <= ?
    ");
    $expStmt->execute([$routerId, $now]);
    $expired = $expStmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($expired)) continue;

    foreach ($expired as $g) {
        $grantId = (int)$g['id'];
        $fp      = $g['fingerprint'];

        // Step 1: pause the device. Best-effort — we mark expired in the DB
        // even if the pause call fails so we don't keep retrying every minute.
        $pauseResp = $drCall('dr_wifi_pause_client', [
            'router_id' => $routerId,
            'client_id' => $fp,
            'by'        => 'paid_access_expired',
        ], 'POST');
        $pauseOk = !empty($pauseResp['ok']);
        if (!$pauseOk) {
            $pauseFailures++;
            error_log("[paid_access] pause failed router=$routerId fp=$fp grant=$grantId");
        }

        // Step 2: mark grant expired
        try {
            $pdo->prepare("
                UPDATE hotspot_paid_access
                SET status = 'expired', expired_at = ?
                WHERE id = ? AND status = 'active'
            ")->execute([$now, $grantId]);

            // Step 3: close session log row
            $pdo->prepare("
                UPDATE hotspot_session_log
                SET session_end = ?, total_minutes = (? - session_start) / 60
                WHERE linked_paid_id = ? AND session_end IS NULL
            ")->execute([$now, $now, $grantId]);

            $expiredCount++;
        } catch (\Throwable $e) {
            error_log("[paid_access] DB update failed grant=$grantId: " . $e->getMessage());
        }
    }
}

if ($expiredCount > 0 || $pauseFailures > 0) {
    echo "[paid_access] Done — expired=$expiredCount, pause_failures=$pauseFailures\n";
}
