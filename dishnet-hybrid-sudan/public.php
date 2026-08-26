<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/error_handler.php'; // Global fatal/exception catch  prevents blank pages
ob_start(); // buffer all output -- ensures redirect() works even if a warning fires early
chdir(__DIR__);

//  PHP 7.4 polyfills (production server is PHP 7.4, these are PHP 8.0+) 
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}


// Plugin version  read once from manifest, used throughout
// Read manifest version (store not available yet here - cached after $store init)
$GLOBALS['_PLUGIN_VER'] = 'v' . (json_decode(@file_get_contents(__DIR__.'/manifest.json'), true)['information']['version'] ?? '4');
// Timezone  South Sudan is EAT (UTC+3), no DST
date_default_timezone_set('Africa/Juba');

//  Session hardening (FIX-1: SameSite=Lax for UCRM iframe; FIX-3: 8h lifetime) 
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');   // FIX-1: was Strict  blocked cookie in UCRM iframe
ini_set('session.use_strict_mode',  '1');
ini_set('session.gc_maxlifetime',   '28800'); // FIX-3: 8h (PHP default 1440s = 24 min)
ini_set('session.cookie_lifetime',  '0');     // session cookie  expires on browser close
ini_set('session.gc_probability',   '1');
ini_set('session.gc_divisor',       '100');   // 1% GC chance per request (PHP default)

// Isolate session storage so other PHP apps on the same server cannot
// run GC with a shorter gc_maxlifetime and kill our sessions
$_sessionSavePath = __DIR__ . '/data/sessions';
if (!is_dir($_sessionSavePath)) @mkdir($_sessionSavePath, 0700, true);
session_save_path($_sessionSavePath);

// Detect HTTPS behind reverse proxy (UCRM always runs behind nginx)
$_isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])   && $_SERVER['HTTP_X_FORWARDED_SSL']   === 'on')
    || (($_SERVER['SERVER_PORT'] ?? 80) == 443);

//  CRITICAL: UCRM serves plugins in an iframe. PHP defaults SameSite=Lax,
// which means browsers won't send session cookies on iframe form POSTs.
// This causes CSRF "Security token mismatch" on every form submit.
// Fix: SameSite=None (requires Secure=true).
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,       // UCRM always runs HTTPS  safe to hardcode
    'httponly' => true,
    'samesite' => 'None',     // MUST be None for iframe  Lax blocks POST cookies
]);

session_start();

// Allow camera access (for barcode scanner in stock management)
header('Permissions-Policy: camera=*');

// CSRF token helper
//  STATELESS CSRF 
// Token = HMAC_SHA256(pluginSecret, date:uid) encoded as hex.
// uid + date are embedded in the token value itself so POST verification
// needs NO session, NO cookies  works in iframe, PWA, any context.
// Secret stored in data/csrf_secret.key (persists across plugin updates).
// Called after $dataDir is defined (line ~140 below).

function _csrfSecret(): string {
    global $dataDir;
    $f = rtrim($dataDir ?? sys_get_temp_dir(), '/') . '/csrf_secret.key';
    if (is_file($f)) {
        $s = trim(file_get_contents($f));
        if (strlen($s) === 64) return $s;
    }
    $s = bin2hex(random_bytes(32));
    @file_put_contents($f, $s, LOCK_EX);
    return $s;
}

function csrfToken(): string {
    // Pure date + secret HMAC  no uid, no session dependency.
    // uid caused mismatches: session empty on POST  uid=0 on POST, uid=5 on GET  never match.
    $day = date('Ymd');
    $sig = substr(hash_hmac('sha256', 'csrf:' . $day, _csrfSecret()), 0, 40);
    return $day . ':' . $sig;
}

function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

function csrfCheck(): bool {
    $t = $_POST['_csrf'] ?? '';
    if (!$t) return false;
    $parts = explode(':', $t, 2);
    if (count($parts) !== 2) return false;
    [$day, $sig] = $parts;
    // Accept today and yesterday only
    $today = date('Ymd');
    $yday  = date('Ymd', strtotime('-1 day'));
    if ($day !== $today && $day !== $yday) return false;
    $expected = substr(hash_hmac('sha256', 'csrf:' . $day, _csrfSecret()), 0, 40);
    return hash_equals($expected, $sig);
}


//  EMERGENCY REPAIR  see block below 
// (handler used to live here, but it referenced $dataDir before it was defined,
// causing 'Undefined variable $dataDir' warnings + 'unable to open database file'
// errors. Moved to AFTER bootstrap_data.php on line ~200. v4.21.16.)


//  PERSISTENT DATA DIRECTORY 
// UCRM provides `pluginDataDir` in ucrm.json for persistent storage that
// survives plugin updates. We MUST use this to prevent data loss.
require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);

//  EMERGENCY REPAIR  runs before SqliteStore::create() so a corrupt DB
// can't crash this endpoint before we get a chance to repair it.
// If the plugin is completely broken (e.g. corrupted WAL file or one
// table with a malformed b-tree) this endpoint lets you fix it without
// SSH or UCRM interface.
//
// Access:  /public.php?page=emergency_repair&key=DISHNET_REPAIR
// Replace DISHNET_REPAIR with the value in data/emergency_repair_key.txt
// (file is optional — defaults to 'DISHNET_REPAIR' if absent).
//
// Optional second confirmation for destructive actions:
//   &confirm=YES_DROP_<TABLE_NAME>   only required when action=drop_table
//
// Actions (via &action=...):
//   diagnose       (default)  — read-only: integrity check + per-table probe
//   clear_wal                 — delete stale -wal/-shm files (safe, idempotent)
//   drop_table&t=NAME         — DESTRUCTIVE: drop one table, requires confirm
//   vacuum                    — VACUUM the DB (rebuilds file, can recover space)
//
// All output is plain text. Safe to run repeatedly.
//
if (($_GET['page'] ?? '') === 'emergency_repair') {
    header('Content-Type: text/plain; charset=UTF-8');

    // Key check  read from a simple flat file to avoid needing SqliteStore
    $keyFile = $dataDir . '/emergency_repair_key.txt';
    $storedKey = file_exists($keyFile) ? trim(file_get_contents($keyFile)) : 'DISHNET_REPAIR';
    if (($_GET['key'] ?? '') !== $storedKey) {
        http_response_code(403);
        echo "403 Unauthorized  wrong key\n";
        echo "Set your key in: data/emergency_repair_key.txt (one line, no whitespace)\n";
        exit;
    }

    $action = (string)($_GET['action'] ?? 'diagnose');
    $dbPath = $dataDir . '/plugin.sqlite3';

    echo "=== DishNet Emergency Repair ===\n";
    echo "Time:    " . date('Y-m-d H:i:s') . " (server)\n";
    echo "Action:  " . $action . "\n";
    echo "DB:      " . $dbPath . "\n";
    echo "Exists:  " . (file_exists($dbPath) ? 'yes' : 'NO') . "\n";
    echo "Size:    " . (file_exists($dbPath) ? round(filesize($dbPath) / 1024 / 1024, 2) . ' MB' : 'n/a') . "\n";
    echo str_repeat('-', 60) . "\n\n";

    // ── ACTION: clear_wal ────────────────────────────────────────────────
    if ($action === 'clear_wal') {
        $removed = [];
        foreach (['-wal', '-shm'] as $ext) {
            $f = $dbPath . $ext;
            if (file_exists($f)) {
                $sz = filesize($f);
                if (@unlink($f)) {
                    $removed[] = basename($f) . ' (' . round(($sz ?: 0) / 1024, 1) . ' KB)';
                } else {
                    echo "WARNING: Could not delete " . basename($f) . " (check file permissions)\n";
                }
            }
        }
        if ($removed) {
            echo "Deleted stale files:\n  " . implode("\n  ", $removed) . "\n";
        } else {
            echo "No stale WAL/SHM files found.\n";
        }
        echo "\nNext step: rerun ?action=diagnose to confirm the DB opens cleanly.\n";
        echo "\n=== Done ===\n";
        exit;
    }

    // ── Open DB for read-only / destructive actions ──────────────────────
    try {
        $repairPdo = new \PDO('sqlite:' . $dbPath, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $repairPdo->exec("PRAGMA busy_timeout = 3000");
        $repairPdo->exec("PRAGMA journal_mode = WAL");
    } catch (\Throwable $repairEx) {
        echo "✗ CANNOT OPEN DATABASE: " . $repairEx->getMessage() . "\n";
        echo "  → Try ?action=clear_wal first (deletes stale write-ahead-log files).\n";
        echo "  → If that fails, restore from Google Drive backup.\n";
        echo "\n=== Done ===\n";
        exit;
    }

    // ── ACTION: drop_table ───────────────────────────────────────────────
    if ($action === 'drop_table') {
        $tName = (string)($_GET['t'] ?? '');
        $confirm = (string)($_GET['confirm'] ?? '');
        // Whitelist: must be alnum/underscore + not a money/customer table
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tName)) {
            echo "✗ Invalid table name (alnum + underscore only): " . htmlspecialchars($tName) . "\n";
            echo "\n=== Done ===\n";
            exit;
        }
        $forbidden = [
            'cb_ledger', 'cb_ssp_register', 'staff_ledger', 'staff_expenses',
            'wallet_topups', 'cash_handovers', 'payment_collections', 'payments',
            'kyc_applications', 'leads', 'lte_subscribers', 'fiber_purchases',
            'fiber_invoices', 'sl_kits', 'duplicate_confirmations',
        ];
        if (in_array($tName, $forbidden, true)) {
            echo "✗ REFUSED: '{$tName}' contains money/customer/audit data — never droppable here.\n";
            echo "  If you really need to drop this, do it manually via sqlite3 CLI after a backup.\n";
            echo "\n=== Done ===\n";
            exit;
        }
        $expectedConfirm = 'YES_DROP_' . $tName;
        if ($confirm !== $expectedConfirm) {
            echo "✗ Missing confirmation. To proceed, append:\n";
            echo "    &confirm=" . $expectedConfirm . "\n";
            echo "  This is a destructive action. The table will be dropped, NOT renamed.\n";
            echo "\n=== Done ===\n";
            exit;
        }
        // Safety check: table must currently be in sqlite_master
        $exists = $repairPdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $exists->execute([$tName]);
        if (!$exists->fetch()) {
            echo "✗ Table '{$tName}' does not exist (nothing to drop).\n";
            echo "\n=== Done ===\n";
            exit;
        }
        echo "Dropping table: {$tName} ...\n";
        try {
            $repairPdo->exec("DROP TABLE \"{$tName}\"");
            echo "✓ Dropped successfully.\n";
            echo "\n  → Table will be recreated on next plugin boot via migrations\n";
            echo "    (only if a CREATE TABLE IF NOT EXISTS migration covers it).\n";
            echo "  → master_schedule, sync_queue, etc. are bookkeeping tables —\n";
            echo "    they rebuild themselves from cron/maintenance code paths.\n";
        } catch (\Throwable $dropEx) {
            echo "✗ Drop failed: " . $dropEx->getMessage() . "\n";
        }
        echo "\n=== Done ===\n";
        exit;
    }

    // ── ACTION: vacuum ───────────────────────────────────────────────────
    if ($action === 'vacuum') {
        echo "Running VACUUM (this rebuilds the database file)...\n";
        echo "Be patient — can take 10-60s on a few-hundred-MB DB.\n\n";
        $t0 = microtime(true);
        try {
            $repairPdo->exec('VACUUM');
            $sec = round(microtime(true) - $t0, 1);
            echo "✓ VACUUM completed in {$sec}s.\n";
            echo "  New size: " . round(filesize($dbPath) / 1024 / 1024, 2) . " MB\n";
        } catch (\Throwable $vacEx) {
            echo "✗ VACUUM failed: " . $vacEx->getMessage() . "\n";
            echo "  Localized corruption may need targeted DROP TABLE first.\n";
        }
        echo "\n=== Done ===\n";
        exit;
    }

    // ── ACTION: diagnose (default) ───────────────────────────────────────
    // Goal: pinpoint WHICH table(s) are corrupt, so the operator can decide
    // whether to drop+recreate one bookkeeping table or restore from backup.

    echo "STEP 1 — Basic counts\n";
    try {
        $tableCount = (int)$repairPdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
        $pageCount  = (int)$repairPdo->query("PRAGMA page_count")->fetchColumn();
        $pageSize   = (int)$repairPdo->query("PRAGMA page_size")->fetchColumn();
        echo "  Tables:    {$tableCount}\n";
        echo "  Pages:     {$pageCount}  ({$pageSize} bytes each)\n";
    } catch (\Throwable $e) {
        echo "  ✗ Failed to read sqlite_master: " . $e->getMessage() . "\n";
    }
    echo "\n";

    echo "STEP 2 — Full integrity check (PRAGMA integrity_check)\n";
    try {
        $rows = $repairPdo->query("PRAGMA integrity_check")->fetchAll(\PDO::FETCH_COLUMN);
        if (count($rows) === 1 && ($rows[0] ?? '') === 'ok') {
            echo "  ✓ ok (no corruption detected)\n";
            $allOk = true;
        } else {
            echo "  ✗ Integrity check returned " . count($rows) . " issue(s):\n";
            foreach (array_slice($rows, 0, 30) as $r) {
                echo "    - " . $r . "\n";
            }
            if (count($rows) > 30) echo "    (+ " . (count($rows) - 30) . " more)\n";
            $allOk = false;
        }
    } catch (\Throwable $e) {
        echo "  ✗ integrity_check threw: " . $e->getMessage() . "\n";
        $allOk = false;
    }
    echo "\n";

    echo "STEP 3 — Per-table probe (read 1 row from each)\n";
    try {
        $tables = $repairPdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $bad = [];
        foreach ($tables as $t) {
            try {
                $repairPdo->query("SELECT * FROM \"{$t}\" LIMIT 1")->fetch();
            } catch (\Throwable $tex) {
                $msg = $tex->getMessage();
                $bad[] = ['table' => $t, 'err' => $msg];
            }
        }
        if (empty($bad)) {
            echo "  ✓ All " . count($tables) . " tables readable.\n";
        } else {
            echo "  ✗ " . count($bad) . " table(s) failed read probe:\n";
            foreach ($bad as $b) {
                echo "    - {$b['table']}: " . substr($b['err'], 0, 120) . "\n";
            }
            echo "\n  → If only bookkeeping tables are corrupt (master_schedule,\n";
            echo "    sync_queue_log, etc.), drop+recreate is safe:\n";
            foreach ($bad as $b) {
                echo "      ?page=emergency_repair&key={$storedKey}&action=drop_table&t={$b['table']}&confirm=YES_DROP_{$b['table']}\n";
            }
        }
    } catch (\Throwable $e) {
        echo "  ✗ Could not enumerate tables: " . $e->getMessage() . "\n";
    }
    echo "\n";

    echo "STEP 4 — WAL / SHM file status\n";
    foreach (['-wal', '-shm'] as $ext) {
        $f = $dbPath . $ext;
        if (file_exists($f)) {
            echo "  " . basename($f) . ": " . round(filesize($f) / 1024, 1) . " KB\n";
        } else {
            echo "  " . basename($f) . ": (absent)\n";
        }
    }
    echo "\n";

    echo "Available actions:\n";
    echo "  ?page=emergency_repair&key={$storedKey}&action=clear_wal\n";
    echo "  ?page=emergency_repair&key={$storedKey}&action=drop_table&t=NAME&confirm=YES_DROP_NAME\n";
    echo "  ?page=emergency_repair&key={$storedKey}&action=vacuum\n";
    echo "\n=== Done ===\n";
    $repairPdo = null;
    exit;
}

$uploadDir = $dataDir . '/uploads';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

// Remove placeholder APK from plugin root  it gets re-created on every UCRM
// plugin install/update (it's in the zip) but must never be served or interfere
// with real APKs stored in $dataDir. Safe to delete on every request.
if (file_exists(__DIR__ . '/dishnet-app.apk')) {
    @unlink(__DIR__ . '/dishnet-app.apk');
}

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/RetailerAuth.php';
// Store plugin root globally  used by Services.php lazy loader and api_handlers
$GLOBALS['_PLUGIN_ROOT'] = __DIR__;
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/LoginRateLimiter.php';
require_once __DIR__ . '/lib/RbacService.php';

//  Lazy Service Container 
// All non-core services (CRM, Splynx, LTE, Notifications, KYC, Wallet, etc.)
// are loaded on first access via svc('name'). This cuts ~10,000 lines of PHP
// parse time from every request on PHP 7.4 without OPcache.
require_once __DIR__ . '/lib/Services.php';

//  Storage backend 
// SQLite activated. SqliteStore is a drop-in replacement for JsonStore.
// On first boot it auto-migrates all *.json files  plugin.sqlite3 and
// renames originals to *.json.migrated as read-only backups.
// To revert: swap back to new JsonStore($dataDir)  zero other changes needed.
$store = SqliteStore::create($dataDir);

//  Login rate limiter (I-02) 
$limiter  = new LoginRateLimiter($store);
$auth     = new RetailerAuth($store);
$rbac     = new RbacService($store->getPdo());

$config = $store->load('kyc_config.json');
if (empty($config)) {
    $config = [
        'crm_base_url'   => 'https://crm.dishnetafrica.com/crm/api/v2.1',
        'crm_auth_token' => '',
        'magma_host'             => '',
        'magma_network_id'       => '',
        'magma_client_cert_path' => '',
        'magma_client_key_path'  => '',
        'magma_ca_cert_path'     => '',
        'sales_persons'  => ['BBC', 'Friend', 'Social Media', 'Walk-in', 'Other'],
        'accessories'    => ['Router', 'Cables', 'Mounting Kit', 'Power Adapter'],
        'commission_rate' => 5,
        'commission_on_collection' => true,
        'commission_on_kyc' => true,
        'retailer_targets' => ['default' => 5000],
    ];
    $store->save('kyc_config.json', $config);
}
// Ensure defaults for existing configs
if (!isset($config['commission_rate']))            $config['commission_rate'] = 5;
if (!isset($config['lte_commission_rate']))        $config['lte_commission_rate'] = 5;
if (!isset($config['starlink_commission_rate']))   $config['starlink_commission_rate'] = $config['commission_rate'];
if (!isset($config['fiber_commission_rate']))      $config['fiber_commission_rate'] = $config['commission_rate'];
if (!isset($config['lte_suspend_grace_days']))     $config['lte_suspend_grace_days'] = 0;
if (!isset($config['lte_cron_path']))              $config['lte_cron_path'] = __DIR__ . '/cron_lte.php';
if (!isset($config['commission_on_collection']))   $config['commission_on_collection'] = true;

//  ONE-TIME MIGRATION: tag all existing retailers as employees with commission_type=none 
if (empty($config['employee_migration_done'])) {
    $rmAll = $store->load('retailers.json') ?? [];
    $rmChanged = false;
    foreach ($rmAll as &$rmR) {
        if (!isset($rmR['is_employee'])) {
            $rmR['is_employee']     = true;
            $rmR['commission_type'] = 'none';
            $rmR['commission_rate'] = 0;
            $rmChanged = true;
        }
        if (!isset($rmR['must_change_pwd'])) {
            $rmR['must_change_pwd'] = false; // existing users already know their password
            $rmChanged = true;
        }
    }
    unset($rmR);
    if ($rmChanged) $store->save('retailers.json', $rmAll);
    $config['employee_migration_done'] = true;
    $store->save('kyc_config.json', $config);
}
if (!isset($config['retailer_targets']))           $config['retailer_targets'] = ['default' => 5000];
// Two-org CRM defaults
if (!isset($config['crm_dishnet_org_id']))         $config['crm_dishnet_org_id'] = 2;   // DishNet Africa Ltd
if (!isset($config['crm_ftth_org_id']))            $config['crm_ftth_org_id']    = 7;   // FTTH Project (retailers)
// FTTH custom attribute IDs  set these to match your UCRM Org 7 custom attributes
if (!isset($config['ftth_attr_wallet_balance']))   $config['ftth_attr_wallet_balance'] = 101;
if (!isset($config['ftth_attr_retailer_id']))      $config['ftth_attr_retailer_id']    = 102;
if (!isset($config['ftth_attr_retailer_role']))    $config['ftth_attr_retailer_role']  = 103;
// WhatsApp  via wa-whatsappsender UCRM plugin (?action=send endpoint)
if (!isset($config['wa_plugin_url']))          $config['wa_plugin_url']          = '';
if (!isset($config['wa_app_key']))             $config['wa_app_key']             = '';
if (!isset($config['wa_auth_key']))            $config['wa_auth_key']            = '';
if (!isset($config['whatsapp_admin_phone']))   $config['whatsapp_admin_phone']   = '';
// Two sender numbers: support (onboarding) and accounts (billing)
if (!isset($config['wa_support_number']))      $config['wa_support_number']      = ''; // e.g. 211921443006
if (!isset($config['wa_accounts_number']))     $config['wa_accounts_number']     = ''; // e.g. 211921443009
// Legacy fields kept for backwards compat  no longer used
if (!isset($config['whatsapp_webhook_url']))   $config['whatsapp_webhook_url']   = '';
if (!isset($config['whatsapp_webhook_secret'])) $config['whatsapp_webhook_secret'] = '';
//  WhatsApp Bot (Auto-Reply + Ticket) 
if (!isset($config['wa_bot_enabled']))          $config['wa_bot_enabled']          = false;
if (!isset($config['wa_bot_timeout_minutes']))  $config['wa_bot_timeout_minutes']  = 15;
if (!isset($config['wa_bot_busy_message']))     $config['wa_bot_busy_message']     = '';
if (!isset($config['wa_webhook_secret']))       $config['wa_webhook_secret']       = '';
if (!isset($config['wa_bot_cron_path']))        $config['wa_bot_cron_path']        = __DIR__ . '/cron_wa_bot.php';
if (!isset($config['auto_sync_interval']))         $config['auto_sync_interval']  = 60;   // seconds; 0 = off
if (!isset($config['cron_sync_path']))             $config['cron_sync_path']      = __DIR__ . '/cron_sync.php';
if (!isset($config['cron_maintenance_path']))      $config['cron_maintenance_path'] = __DIR__ . '/cron_maintenance.php';
// Splynx ISP Framework integration
if (empty($config['splynx_url']))                  $config['splynx_url']                = 'https://my.dishnetafrica.com';
if (empty($config['splynx_key']))                  $config['splynx_key']                = '2b10a77dd91668b95b4355200689b0bc';
if (empty($config['splynx_secret']))               $config['splynx_secret']             = '9174fd8da2d4b7bf35a369cd1a020d45';
// Persist to file so cron scripts (which load config directly) also see credentials
if (empty($store->load('kyc_config.json')['splynx_key'])) {
    $store->save('kyc_config.json', $config);
}
if (!isset($config['splynx_auto_provision']))      $config['splynx_auto_provision']     = false;
if (!isset($config['splynx_sync_interval_minutes']))$config['splynx_sync_interval_minutes'] = 5;
if (!isset($config['splynx_default_tariff_id']))   $config['splynx_default_tariff_id']  = 1;
if (!isset($config['splynx_default_router_id']))   $config['splynx_default_router_id']  = 0;
if (!isset($config['splynx_fiber_admin_id']))      $config['splynx_fiber_admin_id']     = 0;
if (!isset($config['splynx_tariff_map']))          $config['splynx_tariff_map']         = [];
if (!isset($config['splynx_cron_path']))           $config['splynx_cron_path']          = __DIR__ . '/cron_splynx_sync.php';

//  Services are lazy-loaded via svc('name') 
// CRM, LTE, Splynx, Notifications, KYC, Wallet, etc. are created on first
// access. Tabs and handlers use: $crm = svc('crm'); $notify = svc('notify');
// See lib/Services.php for the full registry.

function h(string|int|float|null $s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/**
 * v4.11.3 PERF: Invalidate specific nav badge cache keys in plugin_kv.
 * Call after mutations that affect badge counts (KYC, expense, leads, etc.)
 * Keys: 'nav_badges', 'ij_pending_count', 'ledger_mismatch_count', 'leads_badge_{id}'
 */
function invalidateNavCache(\PDO $pdo, array $keys = []): void {
    if (empty($keys)) {
        // Invalidate all badge caches
        $keys = ['nav_badges', 'ij_pending_count', 'ledger_mismatch_count'];
    }
    try {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $pdo->prepare("DELETE FROM plugin_kv WHERE key IN ({$placeholders})")->execute($keys);
    } catch (\Throwable $e) {}
}
function flash(string $msg, string $type = 'success'): void { $_SESSION['kyc_flash'] = ['msg'=>$msg,'type'=>$type]; }
function getFlash(): ?array {
    if (!empty($_SESSION['kyc_flash'])) { $f=$_SESSION['kyc_flash']; unset($_SESSION['kyc_flash']); return $f; }
    return null;
}
function redirect(string $url): void { session_write_close(); ob_end_clean(); header("Location: {$url}"); exit; }


//  SMTP email (no PHPMailer needed)  ported from Starlink Finance 
function sendSmtpEmail(string $host,int $port,string $user,string $pass,string $enc,
    string $fromName,string $fromEmail,string $toList,string $subject,string $htmlBody,string &$error):bool{
    $error='';
    try{
        $sock=@fsockopen(($enc==='ssl'?'ssl://':'').$host,$port,$errno,$errstr,10);
        if(!$sock){$error="Cannot connect to $host:$port  $errstr";return false;}
        stream_set_timeout($sock,10);
        $r=fgets($sock,512);if(substr($r,0,3)!=='220'){$error="Not ready: $r";fclose($sock);return false;}
        fwrite($sock,"EHLO ".gethostname()."\r\n");
        while($l=fgets($sock,512)){if(substr($l,3,1)===' ')break;}
        if($enc==='tls'){
            fwrite($sock,"STARTTLS\r\n");$r=fgets($sock,512);
            if(substr($r,0,3)!=='220'){$error="STARTTLS failed: $r";fclose($sock);return false;}
            $ok=stream_socket_enable_crypto($sock,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if(!$ok)$ok=stream_socket_enable_crypto($sock,true,STREAM_CRYPTO_METHOD_ANY_CLIENT);
            if(!$ok){$error="TLS handshake failed";fclose($sock);return false;}
            fwrite($sock,"EHLO ".gethostname()."\r\n");
            while($l=fgets($sock,512)){if(substr($l,3,1)===' ')break;}
        }
        fwrite($sock,"AUTH LOGIN\r\n");$r=fgets($sock,512);
        if(substr($r,0,3)!=='334'){$error="AUTH not supported: $r";fclose($sock);return false;}
        fwrite($sock,base64_encode($user)."\r\n");$r=fgets($sock,512);
        if(substr($r,0,3)!=='334'){$error="Username rejected";fclose($sock);return false;}
        fwrite($sock,base64_encode($pass)."\r\n");$r=fgets($sock,512);
        if(substr($r,0,3)!=='235'){$error="Auth failed: $r";fclose($sock);return false;}
        fwrite($sock,"MAIL FROM:<$fromEmail>\r\n");$r=fgets($sock,512);
        if(substr($r,0,3)!=='250'){$error="MAIL FROM rejected: $r";fclose($sock);return false;}
        foreach(array_filter(array_map('trim',explode(',',$toList))) as $rcpt){
            fwrite($sock,"RCPT TO:<$rcpt>\r\n");$r=fgets($sock,512);
            if(substr($r,0,3)!=='250'&&substr($r,0,3)!=='251'){$error="Rcpt rejected ($rcpt): $r";fclose($sock);return false;}
        }
        fwrite($sock,"DATA\r\n");$r=fgets($sock,512);
        if(substr($r,0,3)!=='354'){$error="DATA failed: $r";fclose($sock);return false;}
        $msg ="From: $fromName <$fromEmail>\r\nTo: $toList\r\nSubject: $subject\r\n";
        $msg.="MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $msg.="Date: ".date('r')."\r\nMessage-ID: <".uniqid()."@".gethostname().">\r\n\r\n";
        $msg.=$htmlBody."\r\n.\r\n";
        fwrite($sock,$msg);$r=fgets($sock,512);
        fwrite($sock,"QUIT\r\n");fclose($sock);
        if(substr($r,0,3)==='250')return true;
        $error="Send failed: $r";return false;
    }catch(\Exception $e){$error="SMTP: ".$e->getMessage();return false;}
}

//  Send via UCRM built-in mailer (reads SMTP config from UCRM API settings) 
function sendUcrmEmail(string $apiUrl,string $appKey,string $toList,string $subject,string $htmlBody,string &$error):bool{
    if(!$apiUrl||!$appKey){$error='UCRM API not configured';return false;}
    $ch=curl_init();
    curl_setopt_array($ch,[CURLOPT_URL=>rtrim($apiUrl,'/').'/../api/v1.0/settings',
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>(getenv("UCRM_SKIP_SSL_VERIFY")==="1"?false:true),
        CURLOPT_HTTPHEADER=>['X-Auth-App-Key: '.$appKey,'Content-Type: application/json']]);
    $resp=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($code!==200||!$resp){$error="Cannot read UCRM settings (HTTP $code)";return false;}
    $settings=json_decode($resp,true);if(!is_array($settings)){$error='Invalid UCRM settings';return false;}
    $host='';$port=587;$user='';$pass='';$enc='tls';$from='';$fromName='DishNet';
    foreach($settings as $s){
        $k=$s['key']??'';$v=$s['value']??'';
        if(in_array($k,['MAILER_HOST','mailerHost']))$host=$v;
        if(in_array($k,['MAILER_PORT','mailerPort']))$port=(int)$v;
        if(in_array($k,['MAILER_USERNAME','mailerUsername']))$user=$v;
        if(in_array($k,['MAILER_PASSWORD','mailerPassword']))$pass=$v;
        if(in_array($k,['MAILER_ENCRYPTION','mailerEncryption']))$enc=$v;
        if(in_array($k,['MAILER_SENDER','mailerSenderEmail']))$from=$v;
    }
    if(!$host){$error='UCRM mailer not configured (no SMTP host)';return false;}
    if(!$from)$from=$user;
    return sendSmtpEmail($host,$port,$user,$pass,$enc,$fromName,$from,$toList,$subject,$htmlBody,$error);
}

//  Atomic JSON helpers (Starlink Finance pattern) 
function human_time_diff(int $from, int $to = 0): string {
    if (!$to) $to = time();
    $diff = abs($to - $from);
    if ($diff < 60)    return $diff . 's ago';
    if ($diff < 3600)  return round($diff/60) . 'm ago';
    if ($diff < 86400) return round($diff/3600) . 'h ago';
    return round($diff/86400) . 'd ago';
}

function saveDataFile(string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) { error_log("saveDataFile: encode failed for $path  ".json_last_error_msg()); return; }
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) { error_log("saveDataFile: write failed $tmp"); @unlink($tmp); return; }
    if (file_exists($path)) @copy($path, $path . '.bak'); // keep one backup
    rename($tmp, $path);
}

//  Activity log (Starlink Finance pattern) 
function logActivity(string $dataDir, string $action, string $title, string $detail = ''): void {
    $logFile = $dataDir . '/activity_log.json';
    $log = file_exists($logFile) ? (json_decode(file_get_contents($logFile), true) ?: []) : [];
    array_unshift($log, [
        'action' => $action, 'title' => $title, 'detail' => $detail,
        'time'   => date('Y-m-d H:i:s'), 'date' => date('Y-m-d'),
    ]);
    $log = array_slice($log, 0, 500); // keep last 500
    saveDataFile($logFile, $log);
}

$page  = $_GET['page']  ?? 'login';
$tab   = $_GET['tab']   ?? '';

include __DIR__ . '/includes/routes.php';

//  WhatsApp Webhook (WASender incoming messages) 
// URL: public.php?page=wa_webhook
// This routes WASender POSTs through public.php since UCRM doesn't expose
// standalone PHP files. WASender Webhook URL should be:
//   https://crm.dishnetafrica.com/crm/_plugins/dishnet-hybrid-telecom/public.php?page=wa_webhook
if ($page === 'wa_webhook') {
    while (ob_get_level() > 0) ob_end_clean();
    require __DIR__ . '/wa_webhook.php';
    exit;
}

// ── DishNet AI tool API (Sudan edition) ──────────────────────────
// URL: public.php?page=ai_tools&tool=products
// Same reason as the webhook below: uCRM serves only public.php from a plugin
// directory, so ai_tools.php cannot be reached at its own path.
if ($page === 'ai_tools') {
    while (ob_get_level() > 0) ob_end_clean();
    require __DIR__ . '/ai_tools.php';
    exit;
}

// ── Website chat: the AI assistant on dishnetsudan.com ──────────────
// Public and unauthenticated by design -- it answers catalogue questions for
// anonymous visitors. Everything that makes that safe (origin allowlist, rate
// limits, spend ceiling, no account data) is inside web_chat.php.
if ($page === 'web_chat') {
    while (ob_get_level() > 0) ob_end_clean();
    require __DIR__ . '/web_chat.php';
    exit;
}

//  Evolution API Webhook 
// URL: public.php?page=evo_webhook
if ($page === 'evo_webhook') {
    while (ob_get_level() > 0) ob_end_clean();
    require __DIR__ . '/evo_webhook.php';
    exit;
}

// ── Customer Login: web-based OTP login for PWA access ───────────
if ($page === 'customer_login') {
    while (ob_get_level() > 0) ob_end_clean();
    require __DIR__ . '/tabs/customer_app/login_web.php';
    exit;
}

// ── Public legal pages (v4.12.19) — Terms of Service & Privacy Policy ──
// No auth required. Accessible to anyone at ?page=terms or ?page=privacy.
// Content and version lives in lib/LegalContent.php.
if ($page === 'terms' || $page === 'privacy') {
    while (ob_get_level() > 0) ob_end_clean();
    require __DIR__ . '/tabs/customer_app/legal_page.php';
    exit;
}

// ── Customer Portal: HTML PWA served to native app WebView ──────────
if ($page === 'customer_portal') {
    while (ob_get_level() > 0) ob_end_clean();
    // Supply shared services the portal needs
    $notify = svc('notify');
    require __DIR__ . '/tabs/customer_app/portal.php';
    exit;
}

if ($page === 'api') {
    // Discard any HTML warnings that fired during boot (ob_start is at line 3,
    // so PHP warnings from require/include above land in that buffer as HTML).
    // Clean it out and start a fresh buffer before api_handlers takes over.
    while (ob_get_level() > 0) ob_end_clean();
    ob_start();
    set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
        if (!(error_reporting() & $errno)) return false;
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status'=>'error','message'=>"PHP [{$errno}]: {$errstr}",'file'=>basename($errfile),'line'=>$errline]);
        exit;
    });
    register_shutdown_function(function() {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level() > 0) ob_end_clean();
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status'=>'error','message'=>"Fatal: {$e['message']}",'file'=>basename($e['file']),'line'=>$e['line']]);
        }
    });
    // Supply lazy service variables for API domain handlers
    $wallet   = svc('wallet');
    $recharge = svc('recharge');
    $crm      = svc('crm');
    $queue    = svc('queue');
    $sim      = svc('sim');
    $ftthCrm  = svc('ftthCrm');
    $notify   = svc('notify');
    $kyc      = svc('kyc');
    $waBot    = svc('waBot');
    $lte      = svc('lte');
    $magma    = svc('magma');
    $splynx   = svc('splynx');
    $splynxTickets = svc('splynxTickets');
    $splynxCusts   = svc('splynxCusts');
    require __DIR__ . '/includes/api_handlers.php';
}

//  POST Handlers (only parse on POST  saves ~4,700 lines on GET requests) 
if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($_GET['action']) || !empty($_GET['cb_export']) || !empty($_GET['fr_export'])) {
    // Supply service variables that post handlers expect as globals
    $wallet   = svc('wallet');
    $recharge = svc('recharge');
    $crm      = svc('crm');
    $queue    = svc('queue');
    $sim      = svc('sim');
    $ftthCrm  = svc('ftthCrm');
    $notify   = svc('notify');
    $kyc      = svc('kyc');
    $waBot    = svc('waBot');
    $splynx   = svc('splynx');
    $splynxTickets = svc('splynxTickets');
    $splynxCusts   = svc('splynxCusts');
    require __DIR__ . '/includes/post_handlers.php';
}

// Ensure $flash is always defined (post_handlers.php sets it, but safety net)
if (!isset($flash)) $flash = getFlash();

// ==============================================================================
// SUDAN EDITION - FIRST RUN
//
// No accounts are seeded, so the first visit has to create one. Until an
// administrator exists, every page is the setup page.
//
// Who is allowed to do that? uCRM serves this file without authentication, so
// "whoever gets here first" would let a stranger claim the install. Instead we
// ask uCRM who the visitor is - the browser is already carrying its session
// cookies - and only a signed-in staff user may create the first account.
// ==============================================================================
$_setupError = '';
$_firstRun   = empty($store->load('retailers.json'));

if ($_firstRun) {
    require_once __DIR__ . '/lib/UcrmUser.php';
    $_setupWho = UcrmUser::current(__DIR__);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'first_run_admin') {
        if (empty($_setupWho['is_admin'])) {
            $_setupError = 'Sign in to UISP as staff before creating the administrator.';
        } else {
            $_n = trim((string)($_POST['name'] ?? ''));
            $_e = trim((string)($_POST['email'] ?? ''));
            $_p = (string)($_POST['password'] ?? '');
            $_c = (string)($_POST['password2'] ?? '');

            if ($_n === '' || $_e === '') {
                $_setupError = 'Name and email are both required.';
            } elseif (!filter_var($_e, FILTER_VALIDATE_EMAIL)) {
                $_setupError = 'That email address is not valid.';
            } elseif (strlen($_p) < 10) {
                $_setupError = 'Use at least 10 characters for the password.';
            } elseif ($_p !== $_c) {
                $_setupError = 'The two passwords do not match.';
            } else {
                try {
                    $auth->createRetailer([
                        'name'     => $_n,
                        'email'    => $_e,
                        'phone'    => trim((string)($_POST['phone'] ?? '')),
                        'password' => $_p,
                        'wallet'   => 0,
                        'is_admin' => true,
                        'role'     => 'admin',
                    ]);
                    $store->save('install_state.json', [[
                        'id' => 1, 'installed_at' => date('Y-m-d H:i:s'),
                        'edition' => 'sudan', 'setup_done' => true,
                        'first_admin' => $_e,
                    ]]);
                    redirect('?page=login');
                } catch (\Throwable $e) {
                    $_setupError = 'Could not create the account: ' . $e->getMessage();
                }
            }
        }
    }
    $page = 'first_run';
}


//  Logout (GET ?page=logout)  must be before login/dashboard gates 
if ($page === 'logout') { $auth->webLogout(); redirect('?page=login'); }

if($page==='login'&&$auth->currentRetailer()){
    $r=$auth->currentRetailer();
    $role=$r['role']??'sales';
    if(in_array($role,['support','support_engineer','support_leader'],true)) redirect('?page=dashboard&tab=support_dashboard');
    elseif(in_array($role,['accountant','field_accountant'],true)||!empty($r['is_admin'])) redirect('?page=dashboard&tab=ceo_dashboard');
    else redirect('?page=dashboard&tab=form');
}
if($page==='dashboard')$retailer=$auth->requireLogin();

// 
// STANDALONE SCANNER  public.php?page=scanner
// Opens in a new tab outside the UCRM iframe so camera/getUserMedia works.
// 
if ($page === 'scanner') {
    $retailer = $auth->requireLogin();
    $apiBase = strtok($_SERVER['REQUEST_URI'], '?');
    $apiToken = htmlspecialchars($retailer['api_token'] ?? '', ENT_QUOTES);
    $userName = htmlspecialchars($retailer['name'] ?? 'Staff', ENT_QUOTES);
    header('Permissions-Policy: camera=*');
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>DishNet Scanner</title>
<link rel="prefetch" href="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js" as="script">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
.hdr{background:#1e293b;padding:12px 16px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #334155}
.hdr h1{font-size:16px;font-weight:700;flex:1}
.hdr .badge{background:#22c55e22;color:#22c55e;padding:2px 10px;border-radius:12px;font-size:11px}
.container{max-width:480px;margin:0 auto;padding:16px}
.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:16px;margin-bottom:12px}
.card h2{font-size:14px;font-weight:600;margin-bottom:10px}
select,input{width:100%;padding:10px 12px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#e2e8f0;font-size:14px;margin-bottom:8px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;width:100%;justify-content:center;-webkit-tap-highlight-color:transparent}
.btn:active{transform:scale(0.97)}
.btn-blue{background:#2563eb;color:#fff}.btn-blue:hover{background:#1d4ed8}
.btn-red{background:#ef4444;color:#fff}.btn-red:hover{background:#dc2626}
.btn-green{background:#22c55e;color:#fff}.btn-green:hover{background:#16a34a}
.btn-purple{background:#8b5cf6;color:#fff}
.btn-sm{padding:6px 12px;font-size:12px;width:auto}
.scanned{display:flex;align-items:center;gap:8px;padding:8px;background:#0f172a;border-radius:8px;margin:4px 0;font-family:monospace;font-size:13px}
.scanned .x{color:#ef4444;cursor:pointer;font-size:16px;padding:0 6px}
.count{background:#8b5cf622;color:#8b5cf6;padding:4px 12px;border-radius:8px;font-size:20px;font-weight:700;text-align:center;margin:8px 0}
.flash{padding:10px;border-radius:8px;font-size:13px;margin:8px 0;display:none}
.flash.ok{display:block;background:#22c55e22;color:#22c55e;border:1px solid #22c55e44}
.flash.err{display:block;background:#ef444422;color:#ef4444;border:1px solid #ef444444}
.result{padding:10px;border-radius:8px;font-size:13px;margin:6px 0;text-align:center}
.result.ok{background:#22c55e22;border:1px solid #22c55e44;color:#22c55e}
.result.warn{background:#f59e0b22;border:1px solid #f59e0b44;color:#f59e0b}
.result.busy{background:#3b82f622;border:1px solid #3b82f644;color:#3b82f6}
video{width:100%;border-radius:8px;background:#000}
canvas{display:none}
/* Mode toggle */
.mode-toggle{display:flex;background:#334155;border-radius:8px;padding:3px;margin-bottom:10px}
.mode-btn{flex:1;padding:8px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;text-align:center;background:transparent;color:#94a3b8;transition:all .15s}
.mode-btn.active{background:#0f172a;color:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3)}
/* Status bar */
.status-bar{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;margin:8px 0;font-size:12px;font-weight:600;transition:all .3s}
.status-bar .dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
.dot-pulse{animation:pulse 1.2s ease-in-out infinite}
/* Toolbar row */
.toolbar{display:flex;gap:8px;margin:8px 0}
.toolbar .btn{width:auto;flex:1}
</style></head><body>
<div class="hdr"><h1> DishNet Stock Scanner</h1><span class="badge"><?=$userName?></span>
<a href="?page=dashboard" style="color:#94a3b8;font-size:12px;text-decoration:none;"> Back</a></div>
<div class="container">

<div class="card">
<h2>1. Select Category</h2>
<select id="catSelect"><option value="">Loading...</option></select>
<div style="display:flex;gap:8px;margin-top:4px;">
<input id="costInput" type="number" step="0.01" placeholder="Cost $" style="width:50%">
<input id="locInput" type="text" value="DishNet UNMISS" placeholder="Location" style="width:50%">
</div>
</div>

<div class="card">
<h2>2. Scan</h2>

<!-- Mode toggle -->
<div class="mode-toggle">
<button class="mode-btn active" id="modeBarcode" onclick="switchMode('barcode')"> Barcode (Auto)</button>
<button class="mode-btn" id="modeOcr" onclick="switchMode('ocr')"> OCR Snap (Text)</button>
</div>

<!-- Barcode scanner container (html5-qrcode) -->
<div id="barcodeReader" style="border-radius:8px;overflow:hidden;display:none;min-height:240px;background:#000"></div>

<!-- OCR video (direct camera) -->
<video id="video" autoplay playsinline muted style="display:none"></video>
<canvas id="canvas"></canvas>

<!-- Live status bar -->
<div class="status-bar" id="statusBar" style="display:none;background:#1e3a5f;border:1px solid #2563eb44">
  <div class="dot" id="statusDot" style="background:#2563eb"></div>
  <span id="statusText" style="flex:1">Ready</span>
  <span id="statusFps" style="font-size:10px;color:#64748b;font-family:monospace"></span>
</div>

<!-- Auto-suggest OCR banner -->
<div id="suggestOcr" style="display:none;background:#f59e0b22;border:1px solid #f59e0b44;border-radius:8px;padding:10px;margin:6px 0;font-size:12px">
  <div style="display:flex;align-items:center;gap:8px">
    <span></span>
    <div style="flex:1"><b id="suggestText">No barcode found</b><br><span style="color:#94a3b8">Label might not have a barcode</span></div>
    <button class="btn btn-purple btn-sm" onclick="switchMode('ocr')" style="width:auto;white-space:nowrap">Switch to OCR</button>
  </div>
</div>

<div id="ocrResult" class="result" style="display:none"></div>

<!-- Controls -->
<div class="toolbar">
<button class="btn btn-blue" id="btnStart" onclick="startScanner()"> Start Camera</button>
<button class="btn btn-purple" id="btnSnap" onclick="snapAndOCR()" style="display:none"> Snap & Read</button>
<button class="btn btn-red btn-sm" id="btnStop" onclick="stopScanner()" style="display:none"> Stop</button>
<button class="btn btn-sm" id="btnTorch" onclick="toggleTorch()" style="display:none;background:#f59e0b;color:#000;width:auto;padding:6px 10px" title="Flashlight"></button>
</div>

<div style="display:flex;gap:8px;margin:8px 0">
<input id="manualInput" type="text" placeholder="Or type serial & press Enter" style="margin:0;flex:1" onkeydown="if(event.key==='Enter'){event.preventDefault();addManual();}">
<button class="btn btn-sm btn-blue" onclick="addManual()" style="width:auto">+ Add</button>
</div>
<div class="count" id="countBadge">0 scanned</div>
<div id="scannedList"></div>
</div>

<div class="card">
<h2>3. Submit to Stock</h2>
<button class="btn btn-green" onclick="submitAll()" id="btnSubmit"> Save All to Stock</button>
<div class="flash" id="flashMsg"></div>
</div>

</div>
<script>
var API='<?=$apiBase?>?page=stock_api&action=';
var TOKEN='<?=$apiToken?>';
var scanned=[],cats=[],stream=null,scanner=null,scanning=false;
var mode='barcode'; // 'barcode' | 'ocr'
var scanElapsed=0,scanAttempts=0,scanTimer=null;
var torchOn=false,torchTrack=null;
var lastScanTime=0;

var RX_KIT=/KIT[A-Z0-9]{8,16}/gi;
var RX_DISH=/(2ABC|HPCP|HPBA)[A-Z0-9]{12,14}/gi;
var RX_SN=/\bSN[:\s]*([A-Z0-9]{12,20})/gi;

function api(action,opts){
  opts=opts||{};
  var url=API+action;
  if(opts.params){var p=new URLSearchParams(opts.params);url+='&'+p.toString();}
  var h={'Authorization':'Bearer '+TOKEN,'Content-Type':'application/json'};
  var cfg={method:opts.method||'GET',headers:h};
  if(opts.body)cfg.body=JSON.stringify(opts.body);
  return fetch(url,cfg).then(function(r){return r.json();}).catch(function(){return null;});
}

function $(id){return document.getElementById(id);}

//  CATEGORIES 
api('stock_categories',{params:{active_only:1}}).then(function(r){
  cats=(r&&r.data)||[];
  var sel=$('catSelect');
  sel.innerHTML='<option value="">-- Select category --</option>';
  cats.forEach(function(c){
    sel.innerHTML+='<option value="'+c.id+'" data-cost="'+(c.buy_price||0)+'">'+(c.title||c.name||'Unknown')+'</option>';
  });
  sel.onchange=function(){
    var opt=sel.options[sel.selectedIndex];
    if(opt&&opt.dataset.cost)$('costInput').value=opt.dataset.cost;
  };
  if(!cats.length){
    sel.innerHTML='<option value="">No categories found  add in Stock Management</option>';
  }
});

//  MODE SWITCH 
function switchMode(m){
  stopScanner();
  mode=m;
  $('modeBarcode').className='mode-btn'+(m==='barcode'?' active':'');
  $('modeOcr').className='mode-btn'+(m==='ocr'?' active':'');
  $('suggestOcr').style.display='none';
  $('ocrResult').style.display='none';
}

//  STATUS BAR 
function showStatus(state, text, fps){
  var bar=$('statusBar'), dot=$('statusDot'), txt=$('statusText'), fpsEl=$('statusFps');
  bar.style.display='flex';
  txt.textContent=text||'';
  fpsEl.textContent=fps||'';
  dot.className='dot';
  if(state==='searching'){
    bar.style.background='#1e3a5f';bar.style.borderColor='#2563eb44';
    dot.style.background='#2563eb';dot.className='dot dot-pulse';
    txt.style.color='#93c5fd';
  } else if(state==='found'){
    bar.style.background='#14532d';bar.style.borderColor='#22c55e44';
    dot.style.background='#22c55e';txt.style.color='#86efac';
  } else if(state==='error'){
    bar.style.background='#450a0a';bar.style.borderColor='#ef444444';
    dot.style.background='#ef4444';txt.style.color='#fca5a5';
  } else {
    bar.style.background='#334155';bar.style.borderColor='#47556944';
    dot.style.background='#64748b';txt.style.color='#94a3b8';
  }
}
function hideStatus(){$('statusBar').style.display='none';}

function startScanTimer(){
  scanElapsed=0;scanAttempts=0;
  if(scanTimer)clearInterval(scanTimer);
  scanTimer=setInterval(function(){
    scanElapsed++;
    if(mode==='barcode'&&scanning){
      var fps=scanAttempts>0?Math.round(scanAttempts/Math.max(scanElapsed,1))+'fps':'...';
      showStatus('searching',' Scanning for barcodes '+scanElapsed+'s',fps);
      // Auto-suggest OCR after 10s with no results
      if(scanElapsed>=10&&scanned.length===0){
        $('suggestOcr').style.display='block';
        $('suggestText').textContent='No barcode found after '+scanElapsed+'s';
      }
    }
  },1000);
}
function stopScanTimer(){if(scanTimer){clearInterval(scanTimer);scanTimer=null;}}

//  BEEP + VIBRATE 
function playBeep(ok){
  try{var ctx=new(window.AudioContext||window.webkitAudioContext)();var o=ctx.createOscillator();var g=ctx.createGain();o.connect(g);g.connect(ctx.destination);o.frequency.value=ok?800:300;g.gain.value=0.15;o.start();o.stop(ctx.currentTime+(ok?0.12:0.25));}catch(e){}
}
function vibrate(ms){try{navigator.vibrate&&navigator.vibrate(ms||50);}catch(e){}}

//  BARCODE SCAN HANDLER 
function onBarcodeScan(text){
  text=text.trim();
  if(!text)return;
  // Reject URLs
  if(text.match(/^https?:\/\//i)||text.match(/\.(com|org|net|go|id)\//i))return;
  // Extract known patterns
  var kitMatch=text.match(/KIT[A-Z0-9]{8,16}/i);
  if(kitMatch)text=kitMatch[0].toUpperCase();
  else{
    var dishMatch=text.match(/(2ABC|HPCP|HPBA)[A-Z0-9]{12,14}/i);
    if(dishMatch)text=dishMatch[0].toUpperCase();
    else{
      var clean=text.replace(/[^A-Z0-9]/gi,'');
      if(clean.length>=10&&clean.length<=25)text=clean.toUpperCase();
      else return;
    }
  }
  // Debounce
  var now=Date.now();
  if(now-lastScanTime<2000&&text===scanned[scanned.length-1])return;
  lastScanTime=now;
  addToList(text);
  showStatus('found',' Found: '+text);
  setTimeout(function(){if(scanning)showStatus('searching',' Scanning for barcodes');},1500);
}

//  START/STOP SCANNER 
function startScanner(){
  $('suggestOcr').style.display='none';
  $('ocrResult').style.display='none';
  if(mode==='barcode') startBarcode();
  else startOcrCamera();
}
function stopScanner(){
  if(mode==='barcode') stopBarcode();
  else stopOcrCamera();
  stopScanTimer();
  hideStatus();
  $('btnStart').style.display='';
  $('btnSnap').style.display='none';
  $('btnStop').style.display='none';
  $('btnTorch').style.display='none';
  scanning=false;
}

//  BARCODE: html5-qrcode real-time 
function startBarcode(){
  if(!window.Html5Qrcode){
    showStatus('idle','Loading barcode library...');
    var s=document.createElement('script');
    s.src='https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js';
    s.onload=function(){startBarcode();};
    s.onerror=function(){showStatus('error','Failed to load barcode library');};
    document.head.appendChild(s);return;
  }
  showStatus('idle','Starting camera...');
  $('barcodeReader').style.display='block';
  $('video').style.display='none';
  // Code 39/93/128 + DataMatrix  NO QR (Starlink QRs contain certification URLs)
  var FMT=[3,4,5,7];
  scanner=new Html5Qrcode("barcodeReader",{formatsToSupport:FMT});
  scanner.start({facingMode:"environment"},{fps:15,qrbox:{width:280,height:140},aspectRatio:1.6},
    function(text){onBarcodeScan(text);},
    function(){scanAttempts++;}
  ).then(function(){
    scanning=true;
    showStatus('searching',' Scanning for barcodes');
    startScanTimer();
    $('btnStart').style.display='none';
    $('btnStop').style.display='';
    $('btnTorch').style.display='';
    // Try to get torch track for flashlight
    try{
      var videoEl=$('barcodeReader').querySelector('video');
      if(videoEl&&videoEl.srcObject){
        var tracks=videoEl.srcObject.getVideoTracks();
        if(tracks.length)torchTrack=tracks[0];
      }
    }catch(e){}
  }).catch(function(e){
    var msg=String(e);
    if(msg.indexOf('NotAllowed')!==-1||msg.indexOf('Permission')!==-1){
      showStatus('error','Camera permission denied  check browser settings');
    } else {
      showStatus('error','Camera error: '+msg.substring(0,60));
    }
  });
}

function stopBarcode(){
  if(scanner&&scanning){
    scanner.stop().then(function(){try{scanner.clear();}catch(e){}}).catch(function(){});
  }
  $('barcodeReader').style.display='none';
  torchTrack=null;torchOn=false;
}

//  OCR: direct camera + Tesseract snap 
function startOcrCamera(){
  showStatus('idle','Starting camera...');
  navigator.mediaDevices.getUserMedia({
    video:{facingMode:'environment',width:{ideal:1920},height:{ideal:1080}}
  }).then(function(s){
    stream=s;
    var v=$('video');
    v.srcObject=s;v.style.display='block';
    $('barcodeReader').style.display='none';
    scanning=true;
    showStatus('searching',' Camera ready  tap Snap & Read');
    startScanTimer();
    $('btnStart').style.display='none';
    $('btnSnap').style.display='';
    $('btnStop').style.display='';
    $('btnTorch').style.display='';
    // Get torch track
    var tracks=s.getVideoTracks();
    if(tracks.length)torchTrack=tracks[0];
  }).catch(function(e){
    showStatus('error','Camera error: '+e);
  });
}

function stopOcrCamera(){
  if(stream){stream.getTracks().forEach(function(t){t.stop();});stream=null;}
  $('video').style.display='none';
  torchTrack=null;torchOn=false;
}

//  TORCH / FLASHLIGHT 
function toggleTorch(){
  if(!torchTrack)return;
  torchOn=!torchOn;
  try{
    torchTrack.applyConstraints({advanced:[{torch:torchOn}]});
    $('btnTorch').style.background=torchOn?'#fbbf24':'#f59e0b';
    $('btnTorch').textContent=torchOn?' ON':'';
  }catch(e){
    // Torch not supported on this device
    $('btnTorch').style.display='none';
  }
}

//  OCR SNAP 
async function snapAndOCR(){
  var v=$('video'),c=$('canvas'),res=$('ocrResult');
  c.width=v.videoWidth;c.height=v.videoHeight;
  var ctx=c.getContext('2d');ctx.drawImage(v,0,0);

  res.style.display='block';res.className='result busy';
  res.innerHTML=' Reading text... hold steady';
  $('btnSnap').disabled=true;$('btnSnap').textContent='Reading...';
  showStatus('searching',' OCR processing...');

  // Load Tesseract if not already loaded
  if(!window.Tesseract){
    res.innerHTML='Loading OCR engine (first time ~5s)...';
    var s=document.createElement('script');
    s.src='https://cdn.jsdelivr.net/npm/tesseract.js@4/dist/tesseract.min.js';
    await new Promise(function(ok,no){s.onload=ok;s.onerror=no;document.head.appendChild(s);});
  }

  try{
    var result=await Tesseract.recognize(c,'eng',{
      logger:function(m){
        if(m.status==='recognizing text'){
          var pct=Math.round((m.progress||0)*100);
          res.innerHTML=' Reading... '+pct+'%';
          showStatus('searching',' OCR reading... '+pct+'%');
        }
      }
    });
    var text=result.data.text;

    // Extract identifiers
    var found=[];
    var kitRx=/KIT[A-Z0-9]{8,16}/gi,m;
    while((m=kitRx.exec(text))!==null) found.push({type:'KIT',value:m[0].toUpperCase()});
    var dishRx=/(2ABC|HPCP|HPBA)[A-Z0-9]{12,14}/gi;
    while((m=dishRx.exec(text))!==null){var v2=m[0].toUpperCase();if(!found.some(function(f){return f.value===v2;}))found.push({type:'Dish',value:v2});}
    var snRx=/\bSN[:\s]*([A-Z0-9]{12,20})/gi;
    while((m=snRx.exec(text))!==null){var v3=m[1].toUpperCase();if(!found.some(function(f){return f.value===v3;}))found.push({type:'SN',value:v3});}

    if(found.length>0){
      var html=' Found '+found.length+' identifier(s):<br>';
      found.forEach(function(f){
        var already=scanned.indexOf(f.value)!==-1;
        html+='<div style="margin:4px 0;display:flex;align-items:center;gap:8px;justify-content:center">';
        html+='<b style="font-family:monospace;font-size:15px">'+f.value+'</b>';
        html+='<span style="font-size:10px;background:#334155;padding:1px 6px;border-radius:4px">'+f.type+'</span>';
        if(already){html+='<span style="font-size:11px;color:#64748b">already added</span>';}
        else{html+="<button onclick=\"addToList('"+f.value+"');this.textContent='Added ';this.disabled=true;this.style.background='#22c55e'\" style=\"padding:4px 12px;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:12px;cursor:pointer\">+ Add</button>";}
        html+='</div>';
      });
      res.className='result ok';res.innerHTML=html;
      showStatus('found',' Found '+found.length+' identifier(s)');
      // Auto-add first KIT
      var firstKit=found.find(function(f){return f.type==='KIT';});
      if(firstKit&&scanned.indexOf(firstKit.value)===-1)addToList(firstKit.value);
    } else {
      var cleanText=text.replace(/\n/g,' ').substring(0,200);
      res.className='result warn';
      res.innerHTML=' No KIT/SN found. OCR read:<br><span style="font-size:11px;font-family:monospace;word-break:break-all">'+cleanText+'</span><br><span style="font-size:11px">Try moving closer to the "SN: KIT..." text</span>';
      showStatus('error',' No identifier found  try again');
    }
  }catch(e){
    res.className='result warn';
    res.innerHTML='OCR error: '+(e.message||e||'unknown')+'  try again or type manually';
    showStatus('error','OCR error');
  }

  $('btnSnap').disabled=false;$('btnSnap').textContent=' Snap & Read';
}

//  LIST MANAGEMENT 
function addToList(text){
  if(scanned.indexOf(text)!==-1)return;
  scanned.push(text);renderList();
  playBeep(true);
  vibrate(50);
}
function addManual(){
  var inp=$('manualInput');
  var v=inp.value.trim().toUpperCase();
  if(v&&scanned.indexOf(v)===-1){addToList(v);inp.value='';}
}
function removeItem(i){scanned.splice(i,1);renderList();}
function renderList(){
  $('countBadge').textContent=scanned.length+' scanned';
  var html='';
  scanned.forEach(function(s,i){
    html+='<div class="scanned"><span style="flex:1">'+s+'</span><span class="x" onclick="removeItem('+i+')"></span></div>';
  });
  $('scannedList').innerHTML=html;
}

//  SUBMIT 
function submitAll(){
  var catId=$('catSelect').value;
  if(!catId){alert('Select a category first');return;}
  if(!scanned.length){alert('Scan at least one item');return;}
  var cost=parseFloat($('costInput').value)||0;
  var loc=$('locInput').value.trim()||'DishNet UNMISS';
  var btn=$('btnSubmit');
  btn.disabled=true;btn.textContent='Saving...';
  var ok=0,errs=[];
  var chain=Promise.resolve();
  scanned.forEach(function(serial){
    chain=chain.then(function(){
      return api('stock_unit_save',{method:'POST',body:{category_id:catId,serial_number:serial,purchase_cost:cost,location_name:loc}}).then(function(r){
        if(r&&r.status==='success')ok++;
        else errs.push(serial+': '+(r&&r.message||'Error'));
      });
    });
  });
  chain.then(function(){
    btn.disabled=false;btn.textContent=' Save All to Stock';
    var fl=$('flashMsg');
    if(errs.length===0){
      fl.className='flash ok';fl.textContent=' All '+ok+' items saved to stock!';
      scanned=[];renderList();
      vibrate(200);
    } else {
      fl.className='flash err';fl.textContent='Saved '+ok+', errors: '+errs.join('; ');
    }
  });
}

//  BACKGROUND PRELOAD (barcode lib + Tesseract) 
setTimeout(function(){
  if(!window.Html5Qrcode){
    var s=document.createElement('script');s.async=true;
    s.src='https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js';
    document.head.appendChild(s);
  }
},1500);
setTimeout(function(){
  if(!window.Tesseract){
    var s=document.createElement('script');s.async=true;
    s.src='https://cdn.jsdelivr.net/npm/tesseract.js@4/dist/tesseract.min.js';
    document.head.appendChild(s);
  }
},4000);
</script></body></html><?php
    exit;
}


//  Role-based default tab (when no ?tab= in URL) 
if ($page === 'dashboard' && $tab === '' && !empty($retailer)) {
    $_defRole = $retailer['role'] ?? 'sales';
    if (in_array($_defRole, ['support', 'support_engineer', 'support_leader'], true)) {
        $tab = 'support_dashboard';
    } elseif (!empty($retailer['is_admin'])) {
        $tab = 'ceo_dashboard';
    } elseif ($_defRole === 'accountant' || $_defRole === 'field_accountant') {
        $tab = 'cashbook';
    } else {
        $tab = 'sales_dashboard';
    }
}

//  Auto-clear jobs cache on plugin version upgrade 
if (!empty($retailer) && isset($store)) {
    $_pluginVer  = $GLOBALS['_PLUGIN_VER'] ?? 'unknown';
    $_cacheMeta  = $store->load('scheduling_cache_meta.json') ?? [];
    $_cachedVer  = $_cacheMeta['plugin_version'] ?? '';
    if ($_cachedVer !== $_pluginVer) {
        // Version changed  wipe stale cache and bulk-reapply all ucrm_user_id mappings
        $store->save('scheduling_jobs_cache.json', []);
        $store->save('scheduling_cache_meta.json', [
            'last_sync'      => 0,
            'last_sync_ts'   => '',
            'plugin_version' => $_pluginVer,
            'auto_cleared'   => date('Y-m-d H:i:s'),
        ]);
        // Bulk-correct all retailer ucrm_user_id from seed map
        $_seedMapUpgrade = [
            'bhavin@dishnetafrica.com'            => 1,
            'hardik@dishnetafrica.com'            => 1124,
            'mohini.madlani@outlook.com'          => 1078,
            'accounts@dishnetafrica.com'          => 1929,
            'rupesh@dishnetafrica.com'            => 1929,
            'dishnetafrica@gmail.com'             => 1224,
            'atulsmahale007@gmail.com'            => 1368,
            'atul@dishnetafrica.com'              => 1368,
            'noc@dishnetafrica.com'               => 1400,
            'emmanuellukudualphon@gmail.com'      => 1401,
            'nirav@dishnetss.com'                 => 1548,
            'madlanib@gmail.com'                  => 1581,
            'vivekbhatt17@gmail.com'              => 1584,
            'vivek.dishnetafrica@outlook.com'     => 1585,
            'wmensona@gmail.com'                  => 1703,
            'kamjay285@gmail.com'                 => 1705,
            'sokiris744@gmail.com'                => 1718,
            'timelessjo30@gmail.com'              => 1729,
            'justus@dishnetss.com'                => 1927,
            'aida@dishnetss.com'                  => 1969,
            'meckylinea@dishnetss.com'            => 1971,
            'amos@dishnetss.com'                  => 1991,
            'ochiti@dishnetss.com'                => 1993,
            'deepsolanki7799@gmail.com'           => 2015,
            'geoffrey@dishnetss.com'              => 2024,
            'karan@dishnetss.com'                 => 2026,
            'dhaval@dishnetss.com'                => 2027,
            'thomas@dishnetss.com'                => 2028,
            'tabule@dishnetss.com'                => 2029,
        ];
        $_allRetailers = $store->load('retailers.json') ?? [];
        $_changed = false;
        foreach ($_allRetailers as &$_r) {
            $_em = strtolower(trim($_r['email'] ?? ''));
            $_cid = $_seedMapUpgrade[$_em] ?? null;
            if ($_cid && (int)($_r['ucrm_user_id'] ?? 0) !== (int)$_cid) {
                $_r['ucrm_user_id'] = (int)$_cid;
                $_changed = true;
            }
        }
        unset($_r);
        if ($_changed) $store->save('retailers.json', $_allRetailers);
    }
}

//  Auto-map ucrm_user_id from seed table (fires on every page load) 
if (!empty($retailer)) {
    $_ucrmSeedMapGlobal = [
        //  Core staff  confirmed from Laravel DB + UCRM agenda URLs 
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
    $_gEmail     = strtolower(trim($retailer['email'] ?? ''));
    $_gCorrectId = $_ucrmSeedMapGlobal[$_gEmail] ?? null;
    if ($_gCorrectId && (int)($retailer['ucrm_user_id'] ?? 0) !== (int)$_gCorrectId) {
        $store->updateOne('retailers.json', 'id', (int)$retailer['id'], ['ucrm_user_id' => (int)$_gCorrectId]);
        $retailer['ucrm_user_id'] = (int)$_gCorrectId;
    }
}

$devices=$store->load('kyc_devices.json');
$packages=$store->load('kyc_packages.json');

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="DishNet">
<meta name="theme-color" content="#D41C1C">
<?php if (!empty($retailer['api_token'])): ?>
<meta name="dishnet-token"  content="<?= h($retailer['api_token']) ?>">
<meta name="dishnet-server" content="<?= h(rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/')) ?>">
<meta name="dishnet-agent"  content="<?= h($retailer['name'] ?? '') ?>" data-agent-name="<?= h($retailer['name'] ?? '') ?>">
<?php endif; ?>
<link rel="manifest" href="?page=app_manifest">
<link rel="apple-touch-icon" sizes="180x180" href="?page=app_icon&size=180">
<title>DishNet Africa <?php echo $GLOBALS['_PLUGIN_VER'] ?? 'v4'; ?></title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<?php include __DIR__ . '/includes/styles.php'; ?>
</head>
<body>
<div id="toastContainer"></div>

<?php if (!empty($retailer['must_change_pwd'])): ?>
<!--  FORCED PASSWORD CHANGE MODAL  -->
<div id="forcePwdModal" style="position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:999999;display:flex;align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:20px;padding:28px 24px;max-width:400px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="text-align:center;margin-bottom:20px;">
      <div style="font-size:48px;margin-bottom:8px;"></div>
      <div style="font-size:18px;font-weight:800;color:#1e293b;">Set Your Password</div>
      <div style="font-size:13px;color:#6b7280;margin-top:6px;">Your account was created with a default password.<br>Please set a new password to continue.</div>
    </div>
    <div id="forcePwdError" style="display:none;background:#FEE2E2;border-radius:8px;padding:8px 12px;font-size:13px;color:#dc2626;margin-bottom:12px;"></div>
    <div style="margin-bottom:12px;">
      <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">New Password</label>
      <input type="password" id="fpNewPwd" placeholder="Min 8 characters"
        style="width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;"
        oninput="fpCheck()">
    </div>
    <div style="margin-bottom:18px;">
      <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Confirm Password</label>
      <input type="password" id="fpConfPwd" placeholder="Repeat new password"
        style="width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;"
        oninput="fpCheck()">
    </div>
    <div id="fpStrength" style="height:4px;border-radius:4px;background:#e2e8f0;margin-bottom:14px;transition:.3s;">
      <div id="fpStrBar" style="height:100%;border-radius:4px;width:0;transition:.3s;"></div>
    </div>
    <button id="fpSaveBtn" onclick="fpSave()" disabled
      style="width:100%;padding:14px;background:#D41C1C;color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;opacity:.5;transition:.2s;">
      Set Password &amp; Continue 
    </button>
    <div style="text-align:center;margin-top:12px;">
      <a href="?page=logout" style="font-size:12px;color:#9ca3af;text-decoration:none;">Logout instead</a>
    </div>
  </div>
</div>
<script>
function fpCheck() {
  var p = document.getElementById('fpNewPwd').value;
  var c = document.getElementById('fpConfPwd').value;
  var btn = document.getElementById('fpSaveBtn');
  var bar = document.getElementById('fpStrBar');
  // Strength
  var str = 0;
  if (p.length >= 8) str++;
  if (/[A-Z]/.test(p)) str++;
  if (/[0-9]/.test(p)) str++;
  if (/[^A-Za-z0-9]/.test(p)) str++;
  var colors = ['#ef4444','#f97316','#eab308','#22c55e'];
  bar.style.width = (str * 25) + '%';
  bar.style.background = colors[str-1] || '#e2e8f0';
  btn.disabled = !(p.length >= 8 && p === c);
  btn.style.opacity = btn.disabled ? '.5' : '1';
}
async function fpSave() {
  var p = document.getElementById('fpNewPwd').value;
  var c = document.getElementById('fpConfPwd').value;
  var errEl = document.getElementById('forcePwdError');
  errEl.style.display = 'none';
  if (p !== c) { errEl.textContent = 'Passwords do not match.'; errEl.style.display='block'; return; }
  if (p.length < 8) { errEl.textContent = 'Minimum 8 characters required.'; errEl.style.display='block'; return; }
  var btn = document.getElementById('fpSaveBtn');
  btn.textContent = 'Saving';
  btn.disabled = true;
  try {
    var res = await fetch('?page=api&action=change_password', {
          credentials:'same-origin',
          method: 'POST',
      headers: {'Content-Type':'application/json','Authorization':'Bearer <?= htmlspecialchars($retailer["api_token"] ?? "", ENT_QUOTES) ?>'},
      body: JSON.stringify({new_password: p, confirm_password: c})
    }).then(function(r) { return r.json(); });
    if (res.status === 'success') {
      document.getElementById('forcePwdModal').remove();
      showToast(' Password updated successfully!', 'success');
    } else {
      errEl.textContent = res.message || 'Failed to save. Try again.';
      errEl.style.display = 'block';
      btn.textContent = 'Set Password & Continue ';
      btn.disabled = false;
    }
  } catch(e) {
    errEl.textContent = 'Network error. Try again.';
    errEl.style.display = 'block';
    btn.textContent = 'Set Password & Continue ';
    btn.disabled = false;
  }
}
</script>
<?php endif; // must_change_pwd ?>

<div id="kycSpinner">
    <div class="spin-box">
        <div class="spinner-border"></div>
        <div class="spin-msg" id="spinMsg">Submitting KYC...</div>
    </div>
</div>

<?php
if ($page === 'bc_portal') {
    require_once __DIR__ . '/tabs/lte/bc_portal.php';
    ob_end_flush();
    exit;
}

if ($page === 'reset_password'):
    $rpTok     = trim($_GET['token'] ?? '');
    $rpValid   = $rpTok ? $auth->findByResetToken($rpTok) : null;
?>
<style>
body.login-body-page{font-family:'Barlow',sans-serif;}
</style>
<script>document.body.classList.add('login-body-page');</script>
<div style="min-height:100vh;background:#111;display:flex;align-items:center;justify-content:center;padding:20px;">
  <div style="background:#1A1A1A;border-radius:20px;width:100%;max-width:400px;padding:32px;border:1px solid #2A2A2A;box-shadow:0 20px 60px rgba(0,0,0,.5);">
    <div style="text-align:center;margin-bottom:24px;">
      <div style="font-size:2.5rem;margin-bottom:8px;"></div>
      <h2 style="margin:0 0 4px;font-size:22px;font-weight:900;color:#fff;font-family:'Barlow Condensed',sans-serif;letter-spacing:-.2px;">Set New Password</h2>
      <p style="font-size:13px;color:#666;margin:0;">DishNet Africa  Account Recovery</p>
    </div>
    <?php if ($flash): ?>
    <div style="padding:11px 14px;border-radius:10px;margin-bottom:18px;font-size:13px;font-weight:600;
      background:<?= $flash['type']==='danger'?'rgba(212,28,28,.15)':'rgba(34,197,94,.15)' ?>;
      color:<?= $flash['type']==='danger'?'#ff6b6b':'#4ade80' ?>;
      border:1px solid <?= $flash['type']==='danger'?'rgba(212,28,28,.3)':'rgba(34,197,94,.3)' ?>;">
      <?= $flash['type']==='danger' ? ' ' : ' ' ?><?= h($flash['msg']) ?>
    </div>
    <?php endif; ?>
    <?php if ($rpValid): ?>
    <div style="background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);border-radius:10px;padding:10px 14px;margin-bottom:18px;font-size:12px;color:#4ade80;">
       Resetting password for <strong><?= h($rpValid['name']) ?></strong>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="token" value="<?= h($rpTok) ?>">
      <label style="display:block;font-size:10px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.9px;margin-bottom:6px;">New Password (min 6 characters)</label>
      <input type="password" name="new_password" required minlength="6" placeholder="" autocomplete="new-password"
        style="width:100%;background:#262626;border:1.5px solid #333;border-radius:12px;padding:13px 16px;font-size:15px;color:#fff;outline:none;box-sizing:border-box;margin-bottom:14px;"
        onfocus="this.style.borderColor='#D41C1C'" onblur="this.style.borderColor='#333'">
      <label style="display:block;font-size:10px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.9px;margin-bottom:6px;">Confirm New Password</label>
      <input type="password" name="confirm_password" required minlength="6" placeholder="" autocomplete="new-password"
        style="width:100%;background:#262626;border:1.5px solid #333;border-radius:12px;padding:13px 16px;font-size:15px;color:#fff;outline:none;box-sizing:border-box;margin-bottom:18px;"
        onfocus="this.style.borderColor='#D41C1C'" onblur="this.style.borderColor='#333'">
      <button type="submit" style="width:100%;background:#D41C1C;color:#fff;border:none;border-radius:12px;padding:14px;font-family:'Barlow Condensed',sans-serif;font-size:18px;font-weight:800;cursor:pointer;margin-bottom:12px;">
         Save New Password
      </button>
    </form>
    <?php else: ?>
    <div style="text-align:center;padding:20px 0;">
      <div style="font-size:2rem;margin-bottom:12px;"></div>
      <p style="color:#ff6b6b;font-weight:600;font-size:15px;margin-bottom:8px;">Link Expired or Invalid</p>
      <p style="color:#555;font-size:13px;margin-bottom:20px;">This reset link has expired (valid 30 min) or has already been used.</p>
      <a href="?page=login&m=forgot" style="display:inline-block;background:#D41C1C;color:#fff;border-radius:10px;padding:11px 22px;font-size:14px;font-weight:700;text-decoration:none;">Request New Link</a>
    </div>
    <?php endif; ?>
    <a href="?page=login" style="display:block;text-align:center;font-size:13px;color:#555;text-decoration:none;margin-top:12px;"> Back to Login</a>
  </div>
</div>
<?php
elseif ($page === 'first_run'):
?>
<style>
 body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f5f7f5;margin:0;padding:40px 20px;color:#16201c}
 .fr{max-width:480px;margin:0 auto;background:#fff;border:1px solid #dce3de;border-radius:6px;padding:32px}
 .fr h1{font-size:1.4rem;margin:0 0 6px}
 .fr p.sub{color:#5b6a63;margin:0 0 24px;font-size:15px;line-height:1.5}
 .fr label{display:block;font-weight:500;margin:14px 0 5px;font-size:14px}
 .fr input{width:100%;padding:9px 11px;border:1px solid #dce3de;border-radius:4px;font-size:15px;box-sizing:border-box}
 .fr button{margin-top:22px;width:100%;padding:12px;background:#0b6b5b;color:#fff;border:0;border-radius:4px;font-size:15px;font-weight:600;cursor:pointer}
 .fr .err{background:#f8e6e4;border:1px solid #9e2f28;color:#9e2f28;padding:11px 14px;border-radius:4px;margin-bottom:18px;font-size:14px}
 .fr .blocked{background:#f7ebdc;border:1px solid #a85b0b;color:#a85b0b;padding:13px 15px;border-radius:4px;font-size:14px;line-height:1.5}
 .fr .hint{color:#5b6a63;font-size:13px;margin-top:5px}
</style>
<div class="fr">
  <h1>Set up DishNet Sudan</h1>
  <p class="sub">This installation is empty &mdash; no customers, no staff, no accounting records.
  Create the first administrator to begin.</p>

  <?php if (!empty($_setupError)): ?><div class="err"><?= h($_setupError) ?></div><?php endif; ?>
  <?php
    // The global CSRF gate in post_handlers.php redirects with a flash before
    // this page's own handler runs. Without rendering it, a rejected submit
    // looked like the button simply did nothing.
    if (!empty($flash) && is_array($flash) && !empty($flash['msg'])):
  ?>
    <div class="err"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <?php if (empty($_setupWho['is_admin'])): ?>
    <div class="blocked">
      <b>Sign in to UISP first.</b><br>
      This page has no login of its own &mdash; it asks UISP who you are. Open it from the
      UISP plugin menu while signed in as staff, and the form will appear.
      <?php if (!empty($_setupWho['reason'])): ?><br><br><?= h($_setupWho['reason']) ?><?php endif; ?>
    </div>
  <?php else: ?>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="first_run_admin">
      <label>Full name</label>
      <input type="text" name="name" required autofocus value="<?= h($_POST['name'] ?? '') ?>">
      <label>Email</label>
      <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>">
      <label>Phone <span style="font-weight:400;color:#5b6a63">(optional)</span></label>
      <input type="text" name="phone" value="<?= h($_POST['phone'] ?? '') ?>">
      <label>Password</label>
      <input type="password" name="password" required minlength="10">
      <div class="hint">At least 10 characters. Nothing is pre-set &mdash; this edition ships with no default password.</div>
      <label>Confirm password</label>
      <input type="password" name="password2" required minlength="10">
      <button type="submit">Create administrator</button>
    </form>
  <?php endif; ?>
</div>
<?php

elseif ($page === 'login'):

?>
<!-- Google Fonts for brand typography -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
/*  DishNet Login Page Overrides  */
body.login-body-page{font-family:'Barlow',sans-serif;}
.dn-logo-login{
  display:inline-flex;align-items:flex-end;gap:0;
  margin-bottom:6px;
}
.dn-wm{
  font-family:'Barlow Condensed',sans-serif;font-weight:900;
  color:#FFFFFF;font-size:42px;letter-spacing:-.5px;line-height:1;
  position:relative;
}
.dn-wm::after{
  content:'';position:absolute;
  bottom:-4px;left:0;right:0;height:3px;
  background:linear-gradient(110deg,#D41C1C 0%,#E8521A 60%,#FF7A35 100%);
  border-radius:2px;
}
.dn-login-tagline{
  font-family:'Barlow',sans-serif;font-size:12px;font-weight:500;
  color:rgba(255,255,255,.35);letter-spacing:.3px;margin-bottom:40px;margin-top:8px;
}
.dn-login-title{
  font-family:'Barlow Condensed',sans-serif;font-weight:800;
  font-size:28px;color:#FFFFFF;letter-spacing:-.2px;
  margin-bottom:4px;
}
.dn-login-sub{font-size:13px;color:#555;margin-bottom:28px;}
.dn-lbl{
  display:block;font-family:'Barlow',sans-serif;
  font-size:10px;font-weight:700;color:#555;
  text-transform:uppercase;letter-spacing:.9px;margin-bottom:6px;
}
.dn-inp{
  width:100%;background:#262626;border:1.5px solid #333;
  border-radius:12px;padding:13px 16px;
  font-family:'Barlow',sans-serif;font-size:15px;color:#fff;
  outline:none;transition:border-color .2s,box-shadow .2s;
  margin-bottom:16px;box-sizing:border-box;
}
.dn-inp::placeholder{color:#444;}
.dn-inp:focus{border-color:#D41C1C;box-shadow:0 0 0 4px rgba(212,28,28,.12);}
.dn-submit{
  width:100%;background:#D41C1C;color:#fff;border:none;
  border-radius:12px;padding:15px;
  font-family:'Barlow Condensed',sans-serif;
  font-size:18px;font-weight:800;letter-spacing:.3px;
  cursor:pointer;margin-top:6px;position:relative;overflow:hidden;
  transition:transform .15s,box-shadow .15s;
}
.dn-submit::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(180deg,rgba(255,255,255,.1) 0%,transparent 50%);
}
.dn-submit:hover{transform:translateY(-1px);box-shadow:0 8px 28px rgba(212,28,28,.4);}
.dn-submit:active{transform:translateY(0);}
.dn-create-link{
  display:block;text-align:center;
  font-size:13px;color:#555;text-decoration:none;
  margin-top:16px;font-weight:500;
  transition:color .2s;
}
.dn-create-link:hover{color:#D41C1C;}
.dn-login-footer{
  text-align:center;margin-top:40px;padding-top:24px;
  border-top:1px solid #242424;
}
.dn-service-pills{display:flex;gap:6px;justify-content:center;margin-bottom:10px;flex-wrap:wrap;}
.dn-pill{
  font-family:'Barlow Condensed',sans-serif;font-weight:800;
  font-size:10px;letter-spacing:1px;text-transform:uppercase;
  padding:3px 10px;border-radius:20px;
}
.dn-pill.f{background:rgba(212,28,28,.15);color:#D41C1C;border:1px solid rgba(212,28,28,.25);}
.dn-pill.s{background:rgba(255,255,255,.06);color:#888;border:1px solid rgba(255,255,255,.1);}
.dn-pill.l{background:rgba(26,77,181,.2);color:#6699ff;border:1px solid rgba(26,77,181,.3);}
.dn-footer-txt{font-size:10px;color:#333;letter-spacing:.3px;}
</style>
<script>document.body.classList.add('login-body-page');</script>

<div class="login-wrap">
    <div class="login-card">

        <!-- Logo -->
        <div class="dn-logo-login">
            <span class="dn-wm">DishNet</span>
        </div>
        <div class="dn-login-tagline">Field Operations Platform  South Sudan</div>

        <!-- Flash message -->
        <?php if ($flash): ?>
        <div style="background:<?= $flash['type']==='danger'?'rgba(212,28,28,.15)':'rgba(34,197,94,.15)' ?>;
            border:1px solid <?= $flash['type']==='danger'?'rgba(212,28,28,.3)':'rgba(34,197,94,.3)' ?>;
            color:<?= $flash['type']==='danger'?'#ff6b6b':'#4ade80' ?>;
            border-radius:10px;padding:11px 14px;font-size:13px;font-weight:600;margin-bottom:20px;">
            <?= $flash['type']==='danger' ? ' ' : ' ' ?><?= h($flash['msg']) ?>
        </div>
        <?php endif; ?>

        <div class="dn-login-title">Welcome Back</div>
        <div class="dn-login-sub">Sign in to your DishNet account</div>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="do_login">
            <label class="dn-lbl">Email or Phone Number</label>
            <input type="text" name="identifier" class="dn-inp" placeholder="your@email.com or +211..." required autofocus autocomplete="username" inputmode="email">
            <label class="dn-lbl" style="display:flex;justify-content:space-between;align-items:center;">
                Password
                <a href="?page=login&m=forgot" style="font-size:11px;color:#888;font-weight:500;text-transform:none;letter-spacing:0;text-decoration:none;" onmouseover="this.style.color='#D41C1C'" onmouseout="this.style.color='#888'">Forgot password?</a>
            </label>
            <input type="password" name="password" class="dn-inp" placeholder="" required autocomplete="current-password">
            <button type="submit" class="dn-submit">Sign In </button>
        </form>

        <!-- Hidden: Create Agent Account link removed from login page -->
        <!-- <a href="?page=login&m=create" class="dn-create-link">+ Create Agent Account</a> -->

        <!-- Footer -->
        <div class="dn-login-footer">
            <div class="dn-service-pills">
                <span class="dn-pill f">Fiber</span>
                <span class="dn-pill s">Starlink</span>
                <span class="dn-pill l">4G LTE</span>
            </div>
            <div class="dn-footer-txt">DishNet Africa Ltd  <?php echo $GLOBALS['_PLUGIN_VER']; ?>  Secure Connection</div>
        </div>

    </div>
</div>

<?php if (($_GET['m']??'')==='create'): ?>
<!--  Create Agent Account Modal  -->
<div style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:20px;width:100%;max-width:420px;padding:28px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="text-align:center;margin-bottom:20px;">
      <i class="bi bi-person-plus-fill" style="font-size:2.5rem;color:#D41C1C;"></i>
      <h3 style="margin:8px 0 4px;font-size:18px;font-weight:800;color:#1e293b;">Create Agent Account</h3>
      <p style="font-size:12px;color:#9ca3af;margin:0;">Requires admin password to authorise</p>
    </div>
    <?php if ($flash): ?>
    <div style="padding:10px 14px;border-radius:10px;margin-bottom:16px;font-size:13px;font-weight:600;
      background:<?= $flash['type']==='danger'?'#FFEBEE':($flash['type']==='warning'?'#FFF3E0':'#E8F5E9') ?>;
      color:<?= $flash['type']==='danger'?'#c0392b':($flash['type']==='warning'?'#E65100':'#2E7D32') ?>;">
      <?= h($flash['msg']) ?>
    </div>
    <?php endif; ?>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_agent">
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Full Name *</label>
        <input type="text" name="agent_name" required placeholder="e.g. Aida Mohamed"
          style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Email Address *</label>
        <input type="email" name="agent_email" required placeholder="aida@dishnetss.com"
          style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Phone</label>
        <input type="tel" name="agent_phone" placeholder="+211 ..."
          style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Role *</label>
        <select name="agent_role" style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:10px;font-size:14px;font-weight:600;background:#fff;box-sizing:border-box;">
          <option value="sales"> Sales Agent</option>
          <option value="support"> Support Technician</option>
          <option value="accountant"> Accountant</option>
        </select>
      </div>
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Password for new account * (min 6 chars)</label>
        <input type="password" name="agent_password" required minlength="6" placeholder="Set a strong password"
          style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:20px;background:#FFF3E0;border-radius:10px;padding:12px 14px;">
        <label style="font-size:11px;font-weight:700;color:#E65100;display:block;margin-bottom:4px;"> Admin Password (to authorise)</label>
        <input type="password" name="admin_password" required placeholder="Enter YOUR admin password"
          style="width:100%;padding:10px 12px;border:2px solid #FED7AA;border-radius:10px;font-size:14px;box-sizing:border-box;background:#fff;">
        <div style="font-size:10px;color:#9ca3af;margin-top:5px;">This is the DishNet Admin password, not the new agent's password.</div>
      </div>
      <button type="submit" style="width:100%;padding:13px;background:#1A1A1A;color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:800;cursor:pointer;margin-bottom:10px;">
         Create Account
      </button>
      <a href="?page=login" style="display:block;text-align:center;font-size:13px;color:#9ca3af;text-decoration:none;">
         Back to Login
      </a>
    </form>
  </div>
</div>
<?php endif; ?>
<?php if (($_GET['m']??'')==='forgot'): ?>
<!--  Forgot Password Modal  -->
<div style="position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;">
  <div style="background:#1A1A1A;border-radius:20px;width:100%;max-width:400px;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,.5);border:1px solid #2A2A2A;">
    <div style="text-align:center;margin-bottom:20px;">
      <div style="font-size:2.5rem;margin-bottom:8px;"></div>
      <h3 style="margin:0 0 4px;font-size:20px;font-weight:800;color:#FFFFFF;font-family:'Barlow Condensed',sans-serif;">Forgot Password?</h3>
      <p style="font-size:13px;color:#666;margin:0;">Enter your registered phone number. We'll send a reset link to your WhatsApp.</p>
    </div>
    <?php if ($flash): ?>
    <div style="padding:10px 14px;border-radius:10px;margin-bottom:16px;font-size:13px;font-weight:600;
      background:<?= $flash['type']==='danger'?'rgba(212,28,28,.15)':('rgba(34,197,94,.15)') ?>;
      color:<?= $flash['type']==='danger'?'#ff6b6b':'#4ade80' ?>;
      border:1px solid <?= $flash['type']==='danger'?'rgba(212,28,28,.3)':('rgba(34,197,94,.3)') ?>;">
      <?= $flash['type']==='danger' ? ' ' : ' ' ?><?= h($flash['msg']) ?>
    </div>
    <?php endif; ?>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="forgot_password">
      <label style="display:block;font-size:10px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.9px;margin-bottom:6px;">WhatsApp Phone Number</label>
      <input type="tel" name="phone" required placeholder="+211 912 345 678"
        style="width:100%;background:#262626;border:1.5px solid #333;border-radius:12px;padding:13px 16px;font-size:15px;color:#fff;outline:none;box-sizing:border-box;margin-bottom:16px;"
        onfocus="this.style.borderColor='#D41C1C'" onblur="this.style.borderColor='#333'">
      <button type="submit" style="width:100%;background:#D41C1C;color:#fff;border:none;border-radius:12px;padding:14px;font-family:'Barlow Condensed',sans-serif;font-size:17px;font-weight:800;cursor:pointer;margin-bottom:12px;">
         Send Reset Link via WhatsApp
      </button>
      <a href="?page=login" style="display:block;text-align:center;font-size:13px;color:#555;text-decoration:none;"> Back to Login</a>
    </form>
  </div>
</div>
<?php endif; ?>
<?php
else:
    // Defensive: if $retailer wasn't set (e.g. unexpected $page value), force login
    if (empty($retailer)) { $retailer = $auth->requireLogin(); }
    $isAdmin           = $retailer['is_admin'] ?? false;
    $userRole          = $retailer['role'] ?? ($isAdmin ? 'admin' : 'sales');

    //  PIGGYBACK CRON: Run critical jobs on admin page load 
    // Fallback for when server cron isn't configured. Runs max once per 5 minutes.
    // Piggyback cron  runs AFTER page output (async-style)
    // Check here, but actual execution happens at end of script via register_shutdown_function
    $runPiggybackCron = false;
    if ($isAdmin) {
        $piggybackFile = $dataDir . '/piggyback_last_run.txt';
        $piggybackLast = file_exists($piggybackFile) ? (int)file_get_contents($piggybackFile) : 0;
        if (time() - $piggybackLast > 300) { // 5 minutes
            file_put_contents($piggybackFile, time());
            $runPiggybackCron = true;
        }
    }
    
    // Register to run cron AFTER page is sent to browser
    if ($runPiggybackCron) {
        $masterPath = __DIR__ . '/cron/master.php';
        register_shutdown_function(function() use ($masterPath) {
            // Flush output to browser first (page loads immediately)
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                // Fallback: flush output buffers
                while (ob_get_level()) ob_end_flush();
                flush();
            }
            
            // Now run cron in background (user won't wait)
            ignore_user_abort(true);
            set_time_limit(120);
            
            if (file_exists($masterPath)) {
                ob_start();
                @include $masterPath;
                ob_end_clean();
            }
        });
    }
    $isSales           = in_array($userRole, ['sales','sales_staff','employee','field_accountant','field_agent','collection']);
    $isSupport         = in_array($userRole, ['support','support_leader']);
    $isAccountant      = $userRole === 'accountant';
    $isFieldAccountant = $userRole === 'field_accountant';

    //  Module Permissions 
    // All available modules. 'roles' = which base roles get this by default
    // when no custom per-retailer permissions have been set yet.
    $ALL_MODULES = [
        // Sales group
        ['id'=>'sales_dashboard','label'=>'My Dashboard',             'icon'=>'[Pipeline]', 'group'=>'Sales',      'roles'=>['sales','sales_staff','field_agent','field_accountant','collection','admin']],
        ['id'=>'kyc',            'label'=>'KYC / Add Customer',      'icon'=>'[Pipeline]', 'group'=>'Sales',      'roles'=>['sales','sales_staff','field_agent','field_accountant','admin']],
        ['id'=>'leads',          'label'=>'My Leads',                 'icon'=>'[Pipeline]', 'group'=>'Sales',      'roles'=>['sales','sales_staff','field_agent','admin']],
        ['id'=>'send_quote',       'label'=>'Send Quotation',           'icon'=>'[Pipeline]', 'group'=>'Sales',      'roles'=>['sales','sales_staff','accountant','admin']],
        ['id'=>'quote_logs',       'label'=>'Quote History',            'icon'=>'[Pipeline]', 'group'=>'Sales',      'roles'=>['accountant','admin']],
        ['id'=>'product_mapping',  'label'=>'UCRM Product Mapping',     'icon'=>'[Pipeline]', 'group'=>'Sales',      'roles'=>['admin']],
        ['id'=>'collect_payment','label'=>'Collect Payment',          'icon'=>'[Pipeline]', 'group'=>'Sales',      'roles'=>['sales','sales_staff','field_agent','field_accountant','collection','accountant','support_leader','support','admin']],
        ['id'=>'wallet',         'label'=>'My Wallet & Passbook',     'icon'=>'[Pipeline]', 'group'=>'Sales',      'roles'=>['sales','sales_staff','field_agent','field_accountant','collection','support_leader','support','admin']],
        ['id'=>'wallet_recharge','label'=>'Load Money',               'icon'=>'[Pipeline]', 'group'=>'Sales',      'roles'=>['sales','sales_staff','field_agent','collection','support_leader','support','admin']],
        ['id'=>'applications',   'label'=>'My Orders',                'icon'=>'[Pipeline]', 'group'=>'Sales',      'roles'=>['sales','sales_staff','field_agent','collection','admin']],
        // Support group
        ['id'=>'support_dash',   'label'=>'Support Dashboard',        'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['support','support_leader','admin']],
        ['id'=>'scheduling',     'label'=>'My Jobs (Scheduling)',       'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['support','support_leader','accountant','field_accountant','sales','sales_staff','field_agent','collection','admin']],
        ['id'=>'bulk_dispatch',  'label'=>'Bulk Job Dispatch',          'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['support_leader','admin']],
        ['id'=>'live_map',       'label'=>'Live Staff Map',             'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['support_leader','admin']],
        ['id'=>'splynx_noc',     'label'=>'Splynx NOC Dashboard',       'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['support_leader','admin']],
        ['id'=>'fiber_pipeline', 'label'=>'Fiber Installation Pipeline', 'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['support_leader','admin']],
        ['id'=>'splynx_my_jobs', 'label'=>'My Install Jobs (Splynx)',   'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['support','support_leader','admin']],
        ['id'=>'route_manager',  'label'=>'Route Manager',              'icon'=>'[Pipeline]',  'group'=>'Support',    'roles'=>['support_leader','admin']],
        ['id'=>'support_leader_manual','label'=>'My User Manual',            'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['support_leader']],
        ['id'=>'field_expenses', 'label'=>'Field Expenses',            'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['support_leader','support','field_agent','admin']],
        ['id'=>'cash_declaration','label'=>'Cash Declaration',          'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['sales','sales_staff','field_agent','collection','field_accountant','support','support_leader','accountant','admin']],
        ['id'=>'customer_lookup','label'=>'Customer 360',             'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['support','support_leader','sales','sales_staff','field_agent','field_accountant','accountant','admin']],
        ['id'=>'service_status', 'label'=>'Service Status Check',     'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['support','support_leader','admin']],
        ['id'=>'tickets',        'label'=>'Support Tickets',          'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['support','support_leader','admin']],
        // Accounts group
        ['id'=>'cashbook',       'label'=>'Cashbook (USD & SSP)',     'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['accountant','admin','sales','sales_staff','field_agent','field_accountant']],
        ['id'=>'ssp_imprest',    'label'=>'SSP Imprest (Company View)', 'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['accountant','admin']],
        ['id'=>'accounts_dash',       'label'=>'Accounts Dashboard',      'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['accountant','admin']],
        ['id'=>'balance_identity',    'label'=>'Balance Identity',         'icon'=>'[Pipeline]',  'group'=>'Accounts',   'roles'=>['accountant','admin']],
        ['id'=>'collections',    'label'=>'All Collections Report',   'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['accountant','admin']],
        ['id'=>'ledger',         'label'=>'Retailer Ledger',          'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['accountant','admin']],
        ['id'=>'commissions',    'label'=>'Commission Reports',        'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['accountant','admin']],
        ['id'=>'settlement',     'label'=>'Daily Settlement',         'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['accountant','admin']],
        ['id'=>'field_handover',    'label'=>'Field Cash Chain',        'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['field_accountant','accountant','admin']],
        ['id'=>'handover_queue',    'label'=>'Handover Queue',          'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['accountant','admin']],
        ['id'=>'staff_cash_control','label'=>'Staff Cash Control',      'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['accountant','admin']],
        ['id'=>'staff_transfers',   'label'=>'Staff Transfers',          'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['accountant','admin']],
        ['id'=>'invoice_queue',     'label'=>'Invoice Queue',           'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['accountant','admin']],
        ['id'=>'acc_recharges',  'label'=>'Recharge History (View)',  'icon'=>'[Pipeline]', 'group'=>'Accounts',   'roles'=>['accountant','admin']],
        // HRM group (v4.11.0)
        ['id'=>'hrm_dashboard',  'label'=>'HR Dashboard',             'icon'=>'[Pipeline]', 'group'=>'HRM',        'roles'=>['accountant','admin']],
        ['id'=>'hrm_employees',  'label'=>'Employees',                'icon'=>'[Pipeline]', 'group'=>'HRM',        'roles'=>['accountant','admin']],
        ['id'=>'hrm_payroll',    'label'=>'Payroll',                  'icon'=>'[Pipeline]', 'group'=>'HRM',        'roles'=>['accountant','admin']],
        ['id'=>'hrm_leave',      'label'=>'Leave Management',         'icon'=>'[Pipeline]', 'group'=>'HRM',        'roles'=>['accountant','admin']],
        // Admin group
        ['id'=>'all_leads',      'label'=>'All Leads (Admin View)',   'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'wa_leads',       'label'=>'WA Leads',                'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'ceo_dashboard',  'label'=>'CEO Dashboard',           'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'all_apps',       'label'=>'All Orders',               'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'retailers_mgmt', 'label'=>'Manage Retailers / Staff', 'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'wallet_admin',   'label'=>'Wallet Top-up & Admin',    'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'recharge_req',   'label'=>'Approve Recharge Requests','icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'daily_report',   'label'=>'Daily Report',             'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'activity_log',   'label'=>'Activity Log',             'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'access_log',     'label'=>'Access Log / Login History', 'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'app_logins',     'label'=>'Customer App Logins',       'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'starlink_suspensions','label'=>'Starlink Suspensions',    'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'starlink_pauses', 'label'=>'Starlink Block Manager',     'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin','accountant','support_leader']],
        ['id'=>'sync_queue',     'label'=>'CRM Sync Queue',           'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'duplicate_log',  'label'=>'Duplicate Review',         'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin','accountant']],
        ['id'=>'overdue_email_log','label'=>'Overdue Emails',          'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin','accountant']],
        ['id'=>'overdue_email_tpl','label'=>'Overdue Templates',        'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'overdue_workbench','label'=>'Overdue Workbench',        'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin','accountant','field_accountant']],
        ['id'=>'maintenance',    'label'=>'System Maintenance',       'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'photo_manager',  'label'=>'Photo Manager',            'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'stock_dashboard','label'=>'Stock Management',          'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin','accountant','support_leader']],
        ['id'=>'my_equipment',   'label'=>'My Equipment',              'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['admin','support_leader','support','field_accountant','sales_agent']],
        ['id'=>'stock_hub',      'label'=>'Stock Hub',                 'icon'=>'[Pipeline]', 'group'=>'Support',    'roles'=>['admin','support_leader','support','field_accountant','sales_agent']],

        ['id'=>'ucrm_data',      'label'=>'UCRM Data Sync',            'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        // ['id'=>'sim_cards', 'label'=>'SIM Card Management', 'icon'=>'[Pipeline]', 'group'=>'Admin', 'roles'=>['admin']], // hidden
        ['id'=>'plans',          'label'=>'Subscription Plans',       'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'hardware',       'label'=>'Hardware Catalog',         'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'wa_inbox',       'label'=>'WA Inbox & Bot',           'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin','support']],
        ['id'=>'updater',        'label'=>'Plugin Updater',           'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'settings',       'label'=>'System Settings',          'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'backup',         'label'=>'Backup & Restore',         'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        ['id'=>'android_app',    'label'=>'Android App Manager',      'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
        // LTE group
        ['id'=>'lte_dashboard',  'label'=>'LTE Dashboard',             'icon'=>'[Pipeline]', 'group'=>'LTE',        'roles'=>['admin','support_leader']],
        ['id'=>'lte_subscribers','label'=>'LTE Subscribers',           'icon'=>'[Pipeline]', 'group'=>'LTE',        'roles'=>['admin','support_leader','support']],
        ['id'=>'lte_renewal',    'label'=>'LTE Renewal Queue',         'icon'=>'[Pipeline]', 'group'=>'LTE',        'roles'=>['admin','support_leader','support','sales','sales_staff']],
        ['id'=>'lte_sims',       'label'=>'SIM Inventory',             'icon'=>'[Pipeline]', 'group'=>'LTE',        'roles'=>['admin','support_leader']],
        ['id'=>'lte_hardware',   'label'=>'CPE / Hardware',            'icon'=>'[Pipeline]', 'group'=>'LTE',        'roles'=>['admin','support_leader']],
        ['id'=>'lte_packages',   'label'=>'LTE Packages',              'icon'=>'[Pipeline]', 'group'=>'LTE',        'roles'=>['admin']],
        ['id'=>'all_collections','label'=>'All Collections (Admin)',  'icon'=>'[Pipeline]', 'group'=>'Admin',      'roles'=>['admin']],
    ];

    //  can($mod)  the single permission gate 
    // Admin always passes. First checks RBAC system (new), then falls back to 
    // legacy role-based defaults if RBAC tables don't exist yet.
    $can = function(string $mod) use ($retailer, $isAdmin, $userRole, $ALL_MODULES, $rbac): bool {
        if ($isAdmin) return true;
        
        // Try RBAC system first (new)
        try {
            $roleIdOrSlug = $retailer['role_id'] ?? $retailer['role'] ?? $userRole;
            if ($rbac->canLegacy($roleIdOrSlug, $mod)) {
                return true;
            }
        } catch (Throwable $e) {
            // RBAC tables may not exist yet - fall through to legacy
        }
        
        // Custom per-retailer overrides
        if (isset($retailer['modules']) && is_array($retailer['modules'])) {
            return in_array($mod, $retailer['modules'], true);
        }
        // Legacy role-based default
        foreach ($ALL_MODULES as $m) {
            if ($m['id'] === $mod) {
                return in_array($userRole, $m['roles'], true);
            }
        }
        return false;
    };
    
    //  isStaff()  check if user is company staff (for cashbook entries) 
    $isStaff = function() use ($retailer, $userRole, $rbac): bool {
        try {
            $roleIdOrSlug = $retailer['role_id'] ?? $retailer['role'] ?? $userRole;
            return $rbac->isStaff($roleIdOrSlug);
        } catch (Throwable $e) {
            // Legacy: these roles are company staff (not independent dealers)
            $staffRoles = ['admin', 'support_leader', 'support', 'accountant', 'sales_staff', 'field_agent', 'field_accountant', 'collection', 'employee'];
            return in_array($userRole, $staffRoles, true);
        }
    };

    if (!isset($_GET['tab']) && $isSupport) $tab = $userRole === 'support_leader' ? 'splynx_noc' : 'support_dashboard';
    // LTE tab needs its own access check
    if (str_starts_with($tab, 'lte_') && !$can('lte_dashboard') && !$can('lte_subscribers') && !$can('lte_renewal') && !$can('lte_sims') && !$isAdmin) $tab = 'form';
    if ($tab === 'ops_hub' && !$isAdmin && !$can('lte_dashboard') && !$can('accounts_dash')) $tab = 'form';
    if (!isset($_GET['tab']) && $isAccountant) $tab = 'accounts_dashboard';
    if (!isset($_GET['tab']) && $isFieldAccountant) $tab = 'my_account';
    if (!isset($_GET['tab']) && $isAdmin) $tab = 'ceo_dashboard';
    $retailerId = (int)$retailer['id'];

    // v4.11.38: Fast pre-load -- SQL COUNT queries only for nav badges.
    // Full datasets are lazy-loaded below only when the active tab needs them.
    $myWallet = svc('wallet')->getSummary($retailerId); // now SQL-aggregated, fast

    $_pdo = $store->getPdo();

    // Nav badge: KYC apps count (COUNT only, no full load)
    $myAppsCount = 0;
    try {
        if ($isAdmin) {
            $myAppsCount = (int)$_pdo->query("SELECT COUNT(*) FROM [kyc_applications]")->fetchColumn();
        } else {
            $_s = $_pdo->prepare("SELECT COUNT(*) FROM [kyc_applications] WHERE retailer_id = ?");
            $_s->execute([$retailerId]);
            $myAppsCount = (int)$_s->fetchColumn();
        }
    } catch (\Throwable $_e) {}

    // Nav badge: pending recharges count (COUNT only)
    $myPendingRechargesCount = 0;
    try {
        $_s = $_pdo->prepare("SELECT COUNT(*) FROM [wallet_recharge_requests] WHERE retailer_id = ? AND status = 'pending'");
        $_s->execute([$retailerId]);
        $myPendingRechargesCount = (int)$_s->fetchColumn();
    } catch (\Throwable $_e) {}

    // Nav badge: admin counts (COUNT only)
    $pendingCount    = 0;
    $pendingColCount = 0;
    if ($isAdmin) {
        try { $pendingCount    = (int)$_pdo->query("SELECT COUNT(*) FROM [wallet_recharge_requests] WHERE status = 'pending'")->fetchColumn(); } catch (\Throwable $_e) {}
        try { $pendingColCount = (int)$_pdo->query("SELECT COUNT(*) FROM [pending_collections] WHERE json_extract(data,'$.status') = 'pending_approval'")->fetchColumn(); } catch (\Throwable $_e) {}
    }

    // Lazy-load full datasets only for the tabs that actually need them
    // Derived from grep of all tab files -- update if a new tab uses these vars
    $_tabsNeedApps      = ['applications', 'sales_dashboard', 'all_apps', 'all_collections'];
    $_tabsNeedRecharges = ['wallet_recharge'];
    // allRetailers: only tabs that READ it from pre-load (not ones that define their own)
    $_tabsNeedRetailers = ['retailers', 'wallet_admin', 'ucrm_data', 'all_apps', 'all_collections',
                           'collection_reconcile', 'handover_queue', 'daily_report', 'accounts_commissions'];

    $myPassbook   = []; // not used in any tab via pre-load (tabs load their own passbook data)
    // v4.21.113: honor the RBAC 'all_apps' permission, not just admin. A role like
    // accountant with "All Applications / View all apps" ticked now sees every order.
    $canViewAllApps = $isAdmin || $can('all_apps');
    $myApps       = in_array($tab, $_tabsNeedApps)      ? svc('kyc')->getApplications($retailerId, $canViewAllApps) : [];
    $myRecharges  = in_array($tab, $_tabsNeedRecharges) ? svc('recharge')->getForRetailer($retailerId)       : [];
    $allRetailers = ($isAdmin && in_array($tab, $_tabsNeedRetailers)) ? $auth->getAllRetailers()              : [];
?>

<?php include __DIR__ . '/includes/onboarding.php'; ?>

<!-- LAYOUT -->
<div class="kyc-layout">
<?php
// v4.11.38: Nav badge reads from shared dashboard cache (written by fiber_pipeline live call)
// Falls back to SQLite count if cache is empty
$kpiPipeline = 0;
if ($isAdmin || ($retailer['role'] ?? '') === 'support_leader') {
    $nocCache = $store->load('splynx_dashboard_cache.json') ?: [];
    $kpiPipeline = (int)($nocCache['total_pending'] ?? 0);
    if ($kpiPipeline === 0) {
        try {
            $kpiPipeline = (int)$store->getPdo()->query(
                "SELECT COUNT(*) FROM tickets WHERE install_complete=0 AND cancelled=0"
            )->fetchColumn();
        } catch (\Throwable $e) {}
    }
}
?>
<?php include __DIR__ . '/includes/navigation.php'; ?>

<div class="kyc-main"><div id="kyc-content">


<?php if ($isSales && in_array($tab, ['form','applications','wallet_recharge','more_menu'])): ?>
<!-- MOBILE WALLET HERO (visible only on mobile) -->
<div class="mobile-wallet-hero">
    <div class="mwh-card">
        <div class="mwh-label">Wallet Balance</div>
        <div class="mwh-amount">$<?= number_format($myWallet['balance'] ?? 0, 2) ?></div>
        <div class="mwh-stats">
            <span><i class="bi bi-arrow-down-circle"></i> In: $<?= number_format($myWallet['total_credit'] ?? 0, 2) ?></span>
            <span><i class="bi bi-arrow-up-circle"></i> Out: $<?= number_format($myWallet['total_debit'] ?? 0, 2) ?></span>
        </div>
    </div>
    <div class="mwh-quick">
        <a href="?page=dashboard&tab=form" class="mwh-btn"><i class="bi bi-plus-circle"></i> New KYC</a>
        <a href="?page=dashboard&tab=collect_payment" class="mwh-btn"><i class="bi bi-cash-coin"></i> Collect Payment</a>
        <a href="?page=dashboard&tab=wallet_recharge" class="mwh-btn"><i class="bi bi-cash-stack"></i> Load Money</a>
        <a href="?page=dashboard&tab=applications" class="mwh-btn"><i class="bi bi-folder"></i> Orders</a>
    </div>
</div>
<?php endif; ?>

<?php if ($flash): ?>
<div class="kyc-alert <?= h($flash['type']) ?>">
    <?= $flash['type']==='success'?'&#9989;':($flash['type']==='warning'?'&#9888;&#65039;':($flash['type']==='info'?'&#8505;&#65039;':'&#10060;')) ?> <?= $flash['msg'] ?>
</div>
<?php endif; ?>
<?php if (!empty($_SESSION['impersonating_as'])): ?>
<div style="background:linear-gradient(135deg,#7c3aed,#4f46e5);color:#fff;border-radius:10px;padding:10px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <span style="font-size:13px;font-weight:700;"> Viewing as <strong><?= h($me['name'] ?? 'Retailer') ?></strong>  you are seeing exactly what this retailer sees.</span>
    <a href="?page=dashboard&action=stop_impersonate" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4);padding:5px 14px;border-radius:7px;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap;"> Stop &amp; Return to Admin</a>
</div>
<?php endif; ?>
<?php
//  Persistent cash warning banner  every page for agents over carry limit 
$_cwShow = false;
if (!($isAdmin ?? false) && !in_array($userRole ?? '', ['accountant']) && ($isSales || $isSupport)) {
    $_cwLimit = (float)(($retailer['carry_limit'] ?? null) ?: ($config['advance_carry_limit'] ?? null) ?: 100);
    $_cwExposure = 0;
    try {
        if (!class_exists('SnapshotService')) require_once __DIR__ . '/lib/SnapshotService.php';
        $_cwPos = (new SnapshotService($store->getPdo(), $store))->get($retailerId);
        $_cwExposure = (float)($_cwPos['cash_exposure'] ?? 0);
    } catch (\Throwable $e) {}
    if ($_cwExposure > $_cwLimit) $_cwShow = true;
}
if ($_cwShow):
?>
<div style="background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:12px;padding:12px 18px;margin-bottom:12px;display:flex;align-items:center;gap:12px;box-shadow:0 4px 14px rgba(220,38,38,.3);">
    <span style="font-size:24px;"></span>
    <div style="flex:1;font-size:12px;line-height:1.5;">
        <strong>You are holding $<?= number_format($_cwExposure, 2) ?> in company cash</strong> (limit $<?= number_format($_cwLimit, 2) ?>).<br>
        Hand over to office immediately. Collections are blocked.
    </div>
    <a href="?page=dashboard&tab=my_account&v=handover" style="background:#fff;color:#dc2626;font-weight:800;padding:8px 14px;border-radius:10px;font-size:11px;text-decoration:none;white-space:nowrap;">Hand Over</a>
</div>
<?php endif; ?>
<?php
//  Quote + Payment success banners (shown once after KYC registration) 
$_lastQuoteId      = $_SESSION['last_kyc_quote_id']       ?? null;
$_lastKycCrmId     = $_SESSION['last_kyc_crm_id']         ?? null;
$_quoteCreated     = $_SESSION['last_kyc_quote_created']   ?? false;
$_lastPaymentId    = $_SESSION['last_kyc_payment_id']      ?? null;
$_paymentCreated   = $_SESSION['last_kyc_payment_created'] ?? false;
$_photoUploaded    = $_SESSION['last_kyc_photo_uploaded']  ?? null;
$_idUploaded       = $_SESSION['last_kyc_id_uploaded']     ?? null;
$_lastKycAppId     = $_SESSION['last_kyc_app_id']          ?? null;
if ($_quoteCreated && $_lastQuoteId && $flash && $flash['type'] === 'success'):
    unset($_SESSION['last_kyc_quote_id'], $_SESSION['last_kyc_crm_id'], $_SESSION['last_kyc_quote_created'],
          $_SESSION['last_kyc_payment_id'], $_SESSION['last_kyc_payment_created'],
          $_SESSION['last_kyc_photo_uploaded'], $_SESSION['last_kyc_id_uploaded']);
    $_crmHostQ = rtrim(preg_replace('#(/crm)?/api/v[^/]*/?$#','',rtrim($config['crm_base_url']??'https://crm.dishnetafrica.com','/')),'/');
?>
<div style="background:linear-gradient(135deg,#1B5E20,#2E7D32);color:#fff;border-radius:14px;
            padding:14px 18px;margin-bottom:12px;display:flex;align-items:center;gap:14px;">
  <div style="font-size:30px;"></div>
  <div style="flex:1;min-width:0;">
    <div style="font-size:14px;font-weight:800;">Quote #<?= h($_lastQuoteId) ?> Created &amp; Sent </div>
    <div style="font-size:12px;opacity:.85;margin-top:2px;">
      Proforma invoice auto-sent to customer email via UCRM.
    </div>
  </div>
  <a href="<?= h($_crmHostQ) ?>/crm/client/<?= h($_lastKycCrmId) ?>" target="_blank"
     style="background:rgba(255,255,255,.18);color:#fff;text-decoration:none;
            border-radius:8px;padding:8px 14px;font-size:12px;font-weight:700;flex-shrink:0;">
    View CRM 
  </a>
</div>
<?php if ($_paymentCreated && $_lastPaymentId): ?>
<div style="background:linear-gradient(135deg,#1A1A1A,#2A2A2A);color:#fff;border-radius:14px;
            padding:14px 18px;margin-bottom:12px;display:flex;align-items:center;gap:14px;">
  <div style="font-size:30px;"></div>
  <div style="flex:1;min-width:0;">
    <div style="font-size:14px;font-weight:800;">Payment #<?= h($_lastPaymentId) ?> Posted to UCRM </div>
    <div style="font-size:12px;opacity:.85;margin-top:2px;">
      Cash payment auto-recorded in UCRM billing. No manual entry needed.
    </div>
  </div>
  <a href="<?= h($_crmHostQ) ?>/crm/billing/payments" target="_blank"
     style="background:rgba(255,255,255,.18);color:#fff;text-decoration:none;
            border-radius:8px;padding:8px 14px;font-size:12px;font-weight:700;flex-shrink:0;">
    View Payments 
  </a>
</div>
<?php endif; ?>
<?php elseif ($flash && $flash['type']==='success' && ($_SESSION['last_kyc_quote_created'] ?? false) === false): ?>
<?php // Quote was attempted but failed  silent (main registration still succeeded) ?>
<?php endif; ?>

<?php
//  Photo upload status banners  shown regardless of quote/payment 
// These banners are unset separately so they persist even if quote failed
if ($flash && $flash['type'] === 'success' && array_key_exists('last_kyc_photo_uploaded', $_SESSION)):
    $_photoStatus = $_SESSION['last_kyc_photo_uploaded'];
    $_idStatus    = $_SESSION['last_kyc_id_uploaded'];
    $_photoCrmId  = $_SESSION['last_kyc_crm_id'] ?? '';
    unset($_SESSION['last_kyc_photo_uploaded'], $_SESSION['last_kyc_id_uploaded']);

    $photoWasAttempted = ($_photoStatus !== null);
    $idWasAttempted    = ($_idStatus !== null);
    $photoOk   = ($photoStatus  ?? false);
    $idOk      = ($_idStatus    ?? false);
    $anyFailed = ($photoWasAttempted && !$_photoStatus) || ($idWasAttempted && !$_idStatus);
    $allOk     = (!$photoWasAttempted || $_photoStatus) && (!$idWasAttempted || $_idStatus);

    if ($photoWasAttempted || $idWasAttempted):
?>
<div style="background:<?= $allOk ? 'linear-gradient(135deg,#1B5E20,#2E7D32)' : 'linear-gradient(135deg,#E65100,#F57C00)' ?>;color:#fff;border-radius:14px;padding:14px 18px;margin-bottom:12px;">
    <div style="font-size:13px;font-weight:800;margin-bottom:8px;">
        <?= $allOk ? ' Documents Uploaded to UCRM ' : ' Some Documents Failed to Upload' ?>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:<?= $anyFailed?'12':'0' ?>px;">
        <?php if ($photoWasAttempted): ?>
        <span style="background:rgba(255,255,255,.15);border-radius:8px;padding:5px 12px;font-size:12px;font-weight:700;">
            <?= $_photoStatus ? '' : '' ?> Customer Photo
        </span>
        <?php endif; ?>
        <?php if ($idWasAttempted): ?>
        <span style="background:rgba(255,255,255,.15);border-radius:8px;padding:5px 12px;font-size:12px;font-weight:700;">
            <?= $_idStatus ? '' : '' ?> ID Proof
        </span>
        <?php endif; ?>
    </div>
    <?php if ($anyFailed && $_photoCrmId): ?>
    <div style="background:rgba(0,0,0,.15);border-radius:10px;padding:12px 14px;margin-top:4px;">
        <div style="font-size:12px;font-weight:700;margin-bottom:10px;">Re-upload failed documents directly to CRM <?= h($_photoCrmId) ?>:</div>
        <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:8px;">
            <?= csrfField() ?>
            <input type="hidden" name="action"        value="kyc_reupload_docs">
            <input type="hidden" name="crm_client_id" value="<?= h($_photoCrmId) ?>">
            <input type="hidden" name="app_id"         value="<?= (int)($_lastKycAppId ?? 0) ?>">
            <?php if ($photoWasAttempted && !$_photoStatus): ?>
            <div style="display:flex;align-items:center;gap:8px;">
                <label style="font-size:12px;font-weight:700;min-width:120px;"> Customer Photo:</label>
                <input type="file" name="customer_image" accept="image/*" capture="environment"
                       style="flex:1;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.3);border-radius:8px;padding:5px 8px;color:#fff;font-size:12px;">
            </div>
            <?php endif; ?>
            <?php if ($idWasAttempted && !$_idStatus): ?>
            <div style="display:flex;align-items:center;gap:8px;">
                <label style="font-size:12px;font-weight:700;min-width:120px;"> ID Proof:</label>
                <input type="file" name="id_document" accept="image/*,.pdf"
                       style="flex:1;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.3);border-radius:8px;padding:5px 8px;color:#fff;font-size:12px;">
            </div>
            <?php endif; ?>
            <button type="submit" style="background:rgba(255,255,255,.25);color:#fff;border:none;border-radius:8px;padding:8px 18px;font-size:13px;font-weight:800;cursor:pointer;align-self:flex-start;margin-top:4px;">
                 Re-upload to UCRM
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<!-- 
     TAB: KYC FORM
      -->
<?php
// 
// TAB ROUTER  Unified dispatch table
// All 78 tabs routed via $_tabFiles (path) + $_tabPerms (permission).
// Permission values:
//   null / true       no check (any logged-in user)
//   'perm_name'       $can('perm_name')
//   '*admin'          $isAdmin only
//   ['a','b']         $can('a') || $can('b')  (OR logic)
//   '*support_leader'  support_leader or admin role
// 
$_tabFiles = [
    //  Sales 
    'form'               => 'tabs/sales/kyc_form.php',
    'sales_dashboard'    => 'tabs/sales/sales_dashboard.php',
    'more_menu'          => 'tabs/sales/more_menu.php',
    'leads'              => 'tabs/sales/leads.php',
    'wa_leads'           => 'tabs/sales/wa_leads.php',
    'collect_payment'    => 'tabs/sales/collect_payment.php',
    'applications'       => 'tabs/sales/applications.php',
    'wallet'             => 'tabs/sales/wallet.php',
    'wallet_recharge'    => 'tabs/sales/wallet_recharge.php',
    'send_quote'         => 'tabs/sales/send_quote.php',
    'quote_logs'         => 'tabs/sales/quote_logs.php',
    'product_mapping'    => 'tabs/sales/product_mapping.php',
    'all_leads'          => 'tabs/sales/all_leads.php',
    'all_collections'    => 'tabs/sales/all_collections.php',
    'all_apps'           => 'tabs/sales/all_apps.php',
    'sim_cards'          => 'tabs/sales/sim_cards.php',
    'my_account'         => 'tabs/sales/my_account.php',
    'subscription_plans' => 'tabs/sales/subscription_plans.php',
    'hardware'           => 'tabs/sales/hardware.php',

    //  Support 
    'support_leader_manual' => 'tabs/support/manual_support_leader.php',
    'support_dashboard'  => 'tabs/support/support_dashboard.php',
    'scheduling'         => 'tabs/support/scheduling.php',
    'bulk_dispatch'      => 'tabs/support/bulk_dispatch.php',
    'live_map'           => 'tabs/support/live_map.php',
    'splynx_noc'         => 'tabs/support/splynx_noc.php',
    'fiber_pipeline'     => 'tabs/support/fiber_pipeline.php',
    'splynx_my_jobs'     => 'tabs/support/splynx_my_jobs.php',
    'route_manager'      => 'tabs/support/route_manager.php',
    'field_expenses'     => 'tabs/support/field_expenses.php',
    'customer_lookup'    => 'tabs/support/customer_lookup.php',
    'service_status'     => 'tabs/support/service_status.php',
    'support_tickets'    => 'tabs/support/support_tickets.php',

    //  Accounts 
    'cashbook'             => 'tabs/accounts/cashbook.php',
    'accounts_dashboard'   => 'tabs/accounts/accounts_dashboard.php',
    'cash_declaration'     => 'tabs/accounts/cash_declaration.php',
    'field_handover'       => 'tabs/accounts/field_handover.php',
    'handover_queue'       => 'tabs/accounts/handover_queue.php',
    'staff_cash_control'   => 'tabs/accounts/staff_cash_control.php',
    'staff_transfers'      => 'tabs/accounts/staff_transfers.php',
    'balance_identity'     => 'tabs/accounts/balance_identity.php',
    'invoice_queue'        => 'tabs/accounts/invoice_queue.php',
    'cash_advances'        => 'tabs/accounts/cash_advances.php',
    'expense_approvals'    => 'tabs/accounts/expense_approvals.php',
    'staff_cashbooks'      => 'tabs/accounts/staff_cashbooks.php',
    'ssp_overview'         => 'tabs/accounts/ssp_overview.php',
    'ssp_cashbook'         => 'tabs/accounts/ssp_cashbook.php',
    'ssp_imprest'          => 'tabs/accounts/ssp_imprest.php',
    'collection_reconcile' => 'tabs/accounts/collection_reconcile.php',
    'commission_cleanup'   => 'tabs/accounts/commission_cleanup.php',
    'recharge_requests'    => 'tabs/accounts/recharge_requests.php',
    'wallet_admin'         => 'tabs/accounts/wallet_admin.php',
    'daily_report'         => 'tabs/accounts/daily_report.php',
    'accounts_collections' => 'tabs/accounts/accounts_collections.php',
    'accounts_wallets'     => 'tabs/accounts/accounts_wallets.php',
    'accounts_commissions' => 'tabs/accounts/accounts_commissions.php',
    'accounts_recharges'   => 'tabs/accounts/accounts_recharges.php',
    'accounts_ledger'      => 'tabs/accounts/accounts_ledger.php',
    'accounts_settlement'  => 'tabs/accounts/accounts_settlement.php',
    'ops_daily_report'     => 'tabs/accounts/ops_daily_report.php',
    'ops_settlement'       => 'tabs/accounts/ops_settlement.php',
    'ops_sync_health'      => 'tabs/accounts/ops_sync_health.php',
    'fiber_costs'          => 'tabs/accounts/fiber_costs.php',

    //  HRM (v4.11.0) 
    'hrm_dashboard'        => 'tabs/hrm/hrm_dashboard.php',
    'hrm_employees'        => 'tabs/hrm/hrm_employees.php',
    'hrm_payroll'          => 'tabs/hrm/hrm_payroll.php',
    'hrm_leave'            => 'tabs/hrm/hrm_leave.php',

    //  LTE 
    'lte_dashboard'    => 'tabs/lte/lte_dashboard.php',
    'lte_commissions'  => 'tabs/lte/lte_commissions.php',
    'lte_reminders'    => 'tabs/lte/lte_reminders.php',
    'lte_autosuspend'  => 'tabs/lte/lte_autosuspend.php',
    'lte_bluecard'     => 'tabs/lte/lte_bluecard.php',
    'bc_my_retailers'  => 'tabs/lte/bc_my_retailers.php',

    //  Engage 
    'lifecycle'            => 'tabs/engage/lifecycle.php',
    'engage_wa_leads'      => 'tabs/sales/wa_leads.php',
    'engage_failed_queue'  => 'tabs/engage/failed_queue.php',
    'whatsapp'             => 'tabs/engage/whatsapp.php',
    'wa_inbox'             => 'tabs/engage/wa_inbox.php',

    //  Admin 
    'ceo_dashboard'    => 'tabs/admin/ceo_dashboard.php',
    'retailers'        => 'tabs/admin/retailers.php',
    'roles'            => 'tabs/admin/roles.php',
    'photo_manager'    => 'tabs/admin/photo_manager.php',
    'stock_dashboard'  => 'tabs/admin/stock_dashboard.php',
    'my_equipment'     => 'tabs/support/my_equipment.php',
    'stock_hub'        => 'tabs/support/stock_hub.php',
    'api_docs'         => 'tabs/admin/api_docs.php',
    'activity_log'     => 'tabs/admin/activity_log.php',
    'access_log'       => 'tabs/admin/access_log.php',
    'app_logins'       => 'tabs/admin/app_logins.php',
    'sync_queue'       => 'tabs/admin/sync_queue.php',
    'duplicate_log'    => 'tabs/admin/duplicate_log.php',
    'overdue_email_log'=> 'tabs/admin/overdue_email_log.php',
    'overdue_email_tpl'=> 'tabs/admin/overdue_email_tpl.php',
    'overdue_workbench'=> 'tabs/admin/overdue_workbench.php',
    'ucrm_data'        => 'tabs/admin/ucrm_data.php',
    'smtp_diagnostic'  => 'tabs/admin/smtp_diagnostic.php',
    'starlink_suspensions' => 'tabs/admin/starlink_suspensions.php',
    'starlink_pauses'  => 'tabs/admin/starlink_pauses.php',
    'maintenance'      => 'tabs/admin/maintenance.php',
    'settings'         => 'tabs/admin/settings.php',
    'system_health'    => 'tabs/admin/system_health.php',   // Sudan edition
    'wa_ai_setup'      => 'tabs/engage/wa_ai_setup.php',    // Sudan edition
    'android_app'      => 'tabs/admin/android_app.php',
    'updater'          => 'tabs/admin/updater.php',
    'backup'           => 'tabs/admin/backup.php',
    'changelog'        => 'tabs/admin/changelog.php',
    'ledger_health'    => 'tabs/admin/ledger_health.php',
    'ops_hub'          => 'tabs/admin/ops_hub.php',

    //  Help 
    'training'         => 'tabs/help/training.php',
    'knowledge_base'   => 'tabs/help/knowledge_base.php',
    'faq'              => 'tabs/help/faq.php',
    'runbook'          => 'tabs/help/runbook.php',
];

// Permission map  only entries that need a gate. Absent = any logged-in user.
$_tabPerms = [
    // Sales
    'all_leads'          => 'all_leads',
    'all_collections'    => 'all_collections',
    'all_apps'           => 'all_apps',
    'sim_cards'          => 'sim_cards',
    'subscription_plans' => 'plans',
    'hardware'           => 'hardware',
    // Support
    'support_leader_manual' => '*support_leader',
    'support_dashboard'  => 'support_dash',
    'scheduling'         => 'scheduling',
    'bulk_dispatch'      => 'bulk_dispatch',
    'live_map'           => 'live_map',
    'splynx_noc'         => 'splynx_noc',
    'fiber_pipeline'     => 'fiber_pipeline',
    'splynx_my_jobs'     => 'splynx_my_jobs',
    'route_manager'      => 'route_manager',
    'customer_lookup'    => 'customer_lookup',
    'service_status'     => 'service_status',
    'support_tickets'    => 'tickets',
    // Accounts
    'cashbook'             => 'cashbook',
    'recharge_requests'    => 'recharge_req',
    'wallet_admin'         => 'wallet_admin',
    'daily_report'         => 'daily_report',
    'accounts_dashboard'   => 'accounts_dash',
    'field_handover'       => 'field_handover',
    'handover_queue'       => 'handover_queue',
    'staff_cash_control'   => 'staff_cash_control',
    'staff_transfers'      => 'staff_transfers',
    'balance_identity'     => 'balance_identity',
    'invoice_queue'        => 'invoice_queue',
    'cash_advances'        => ['accounts_dash', '*admin'],
    'expense_approvals'    => ['accounts_dash', '*admin'],
    'staff_cashbooks'      => ['accounts_dash', '*admin'],
    'ssp_overview'         => ['accounts_dash', '*admin'],
    'ssp_cashbook'         => ['accounts_dash', '*admin'],
    'ssp_imprest'          => ['accounts_dash', '*admin'],
    'collection_reconcile' => ['accounts_dash', '*admin'],
    'commission_cleanup'   => '*admin',
    'accounts_collections' => 'collections',
    'accounts_wallets'     => 'ledger',
    'accounts_commissions' => 'commissions',
    'accounts_recharges'   => 'acc_recharges',
    'accounts_ledger'      => 'ledger',
    'accounts_settlement'  => 'settlement',
    'ops_daily_report'     => ['*admin', 'accounts_dash'],
    'ops_settlement'       => '*admin',
    'ops_sync_health'      => '*admin',
    // HRM (v4.11.0)
    'hrm_dashboard'        => ['accounts_dash', '*admin'],
    'hrm_employees'        => ['accounts_dash', '*admin'],
    'hrm_payroll'          => ['accounts_dash', '*admin'],
    'hrm_leave'            => ['accounts_dash', '*admin'],
    // LTE
    'lte_dashboard'    => ['lte_dashboard', '*admin'],
    'lte_commissions'  => ['commissions', '*admin'],
    'lte_reminders'    => ['lte_renewal', '*admin'],
    'lte_autosuspend'  => '*admin',
    'lte_bluecard'      => '*admin',
    'bc_my_retailers'   => '*admin',
    // Engage
    'whatsapp'             => '*admin',
    'wa_inbox'             => 'customer_lookup',
    'engage_failed_queue'  => ['support_dash', '*admin'],
    'engage_wa_leads'      => ['support_dash', '*admin'],
    'lifecycle'            => ['support_dash', '*admin'],
    // Admin
    'ceo_dashboard'    => '*admin',
    'retailers'        => 'retailers_mgmt',
    'api_docs'         => '*admin',
    'activity_log'     => 'activity_log',
    'access_log'       => 'access_log',
    'sync_queue'       => 'sync_queue',
    'ucrm_data'        => 'ucrm_data',
    'maintenance'      => 'maintenance',
    'settings'         => 'settings',
    'runbook'          => 'settings',
    'api_docs'         => 'settings',
    'engage_settings'  => 'settings',
    'android_app'      => 'android_app',
    'updater'          => '*admin',
    'backup'           => 'backup',
    'changelog'        => '*admin',
    'ledger_health'    => '*admin',
    'overdue_workbench'=> ['*admin', 'accounts_dash', 'support_dash'],
    'stock_dashboard'  => ['accounts_dash', 'support_dash', '*admin'],
    'my_equipment'     => true,  // all authenticated users
    'stock_hub'        => true,  // all authenticated users
    'ops_hub'          => ['*admin', 'lte_dashboard', 'accounts_dash'],
];

//  ENGAGE tab redirects 
if ($tab === 'engage_conversations' && ($isAdmin || $can('support_dash'))) {
    header('Location: ?page=dashboard&tab=whatsapp&subtab=conversations');
    exit;
}
if ($tab === 'engage_message_log' && ($isAdmin || $can('support_dash'))) {
    header('Location: ?page=dashboard&tab=whatsapp&subtab=log');
    exit;
}
if ($tab === 'engage_settings' && $isAdmin) {
    header('Location: ?page=dashboard&tab=whatsapp&subtab=config');
    exit;
}

//  Lazy service bindings for tab scope 
// v4.11.3: Services are created on-demand by svc()  NOT force-loaded here.
// Each tab calls svc('name') when it needs a service. This saves ~200ms per
// page load by not initializing LTE/Magma/Splynx on every KYC form open.
// Legacy variables kept as lazy references for backward compat with tab files.
$crm      = null; // use svc('crm')  auto-created on first access
$wallet   = null;
$recharge = null;
$queue    = null;
$sim      = null;
$ftthCrm  = null;
$notify   = null;
$kyc      = null;
$waBot    = null;
$lte      = null;
$magma    = null;
$splynx   = null;
$splynxTickets = null;
$splynxCusts   = null;
// Tab files that use $crm/$wallet directly will get null  they should use svc('crm').
// For backward compat, force-load only the 3 most common services:
$crm      = svc('crm');
$wallet   = svc('wallet');
$notify   = svc('notify');

//  Unified tab dispatcher 
if (isset($_tabFiles[$tab])) {
    // Permission check
    $perm = $_tabPerms[$tab] ?? null;
    $allowed = true;
    if ($perm !== null) {
        if ($perm === true) {
            $allowed = true; // all authenticated users
        } elseif ($perm === '*admin') {
            $allowed = $isAdmin;
        } elseif ($perm === '*support_leader') {
            $allowed = in_array($userRole, ['support_leader', 'admin']);
        } elseif (is_array($perm)) {
            $allowed = false;
            foreach ($perm as $p) {
                if ($p === '*admin' && $isAdmin) { $allowed = true; break; }
                if ($p !== '*admin' && $can($p)) { $allowed = true; break; }
            }
        } else {
            $allowed = $can($perm);
        }
    }
    if ($allowed) {
        //  Mobile page header  back button + title for tabs accessed via More menu 
        // Bottom nav tabs don't need back button (user can tap nav to switch)
        $bottomNavTabs = ['sales_dashboard','leads','collect_payment','form','my_account','more_menu',
                          'splynx_noc','field_expenses','scheduling','splynx_my_jobs','fiber_pipeline',
                          'support_dashboard','customer_lookup','support_tickets',
                          'accounts_dashboard','accounts_collections','cashbook',
                          'staff_cash_control','field_handover'];
        $_tabTitles = [
            'applications'=>'Orders','wallet'=>'Field Register','wallet_recharge'=>'Load Money',
            'send_quote'=>'Send Quotation','cashbook'=>'Cashbook','cash_declaration'=>'Cash Count',
            'field_expenses'=>'Field Expenses','scheduling'=>'My Jobs','customer_lookup'=>'Customers',
            'my_account'=>'My Cash','leads'=>'Leads','collect_payment'=>'Collect Payment',
            'form'=>'New KYC Registration','hardware'=>'Hardware','subscription_plans'=>'Plans',
            'sim_cards'=>'SIM Cards','quote_logs'=>'Quote Logs','all_leads'=>'All Leads',
            'all_collections'=>'All Collections','all_apps'=>'All Orders','wa_leads'=>'WA Leads',
            'support_tickets'=>'Tickets','service_status'=>'Service Status',
            'splynx_noc'=>'NOC Dashboard','splynx_my_jobs'=>'Install Jobs',
            'live_map'=>'Live Staff Map','route_manager'=>'Route Manager',
            'training'=>'Training Hub','knowledge_base'=>'Help Guide','faq'=>'FAQ','runbook'=>'Runbook',
        ];
        $showMobileBack = !$isAdmin && !in_array($tab, $bottomNavTabs);
        $mobileTitle = $_tabTitles[$tab] ?? ucwords(str_replace('_', ' ', $tab));
        if ($showMobileBack): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:0 0 12px;margin-bottom:4px;">
            <a href="?page=dashboard&tab=more_menu" style="display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;background:#f1f5f9;color:#374151;text-decoration:none;font-size:16px;flex-shrink:0;"><i class="bi bi-arrow-left"></i></a>
            <div style="font-size:17px;font-weight:800;color:#1e293b;"><?= h($mobileTitle) ?></div>
            <span style="margin-left:auto;font-size:9px;font-weight:800;color:#94a3b8;letter-spacing:.5px;"><?= $GLOBALS['_PLUGIN_VER'] ?></span>
        </div>
        <?php endif;

        // Version watermark  only when mobile back header isn't shown (it already has version)
        if (!$showMobileBack) {
            echo '<div style="text-align:right;font-size:9px;color:#cbd5e1;font-weight:700;letter-spacing:.5px;margin-bottom:4px;user-select:none;">'
               . htmlspecialchars($GLOBALS['_PLUGIN_VER'] ?? '') . '  ' . date('d M Y')
               . '</div>';
        }

        // v4.11.3: Auto-load services only when the tab actually needs them
        $_tabSvcMap = [
            'recharge_requests' => ['recharge'],
            'sync_queue'        => ['queue'],
            'ucrm_data'         => ['queue'],
            'invoice_queue'     => ['queue'],
            'failed_queue'      => ['queue'],
            'retailers'         => ['ftthCrm'],
            'all_apps'          => ['kyc'],
            'accounts_ledger'   => ['kyc'],
            'settings'          => ['splynx'],
            'splynx_noc'        => ['splynx','splynxTickets','splynxCusts'],
            'splynx_my_jobs'    => ['splynx','splynxTickets'],
            'whatsapp'          => ['splynx'],
            'form'              => ['kyc','recharge'],
            'sales_dashboard'   => ['wallet'],
            'collect_payment'   => ['wallet'],
            'lte_dashboard'     => ['lte','magma'],
            'lte_commissions'   => ['lte'],
            'lte_reminders'     => ['lte'],
            'lte_autosuspend'   => ['lte','magma'],
        ];
        foreach ($_tabSvcMap[$tab] ?? [] as $_sn) {
            if ($$_sn === null) $$_sn = svc($_sn);
        }

        //  Ledger mismatch banner (admin-only, silent for everyone else) 
        try {
            require_once __DIR__ . '/lib/DualReadCashPosition.php';
            echo DualReadCashPosition::renderBanner($store, $store->getPdo(), $retailer, $dataDir ?? '');
        } catch (\Throwable $_lhBannerErr) { /* never crash the page */ }

        require __DIR__ . '/' . $_tabFiles[$tab];
    }
}
?>

</div><!-- /kyc-content -->
</div><!-- /kyc-main -->

<?php if (!$isAdmin): ?>
<!-- MOBILE BOTTOM NAV (retailers only) -->
<div class="mobile-nav">
<?php if ($isSupport): ?>
<?php if ($userRole === 'support_leader'): ?>
    <a href="?page=dashboard&tab=splynx_noc" class="<?= $tab==='splynx_noc'?'active':'' ?>"><i class="bi bi-hdd-network-fill"></i><span>NOC</span></a>
    <a href="?page=dashboard&tab=my_account" class="<?= $tab==='my_account'?'active':'' ?>"><i class="bi bi-wallet2"></i><span>My Cash</span></a>
    <a href="?page=dashboard&tab=scheduling" class="<?= $tab==='scheduling'?'active':'' ?>" style="position:relative;">
        <i class="bi bi-calendar-check-fill" style="font-size:22px;background:#D41C1C;color:#fff;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;margin-top:-22px;box-shadow:0 4px 14px rgba(212,28,28,.4);"></i>
        <span style="margin-top:2px;">My Jobs</span></a>
    <a href="?page=dashboard&tab=splynx_my_jobs" class="<?= $tab==='splynx_my_jobs'?'active':'' ?>"><i class="bi bi-tools"></i><span>Install</span></a>
    <a href="?page=dashboard&tab=more_menu" class="<?= $tab==='more_menu'?'active':'' ?>"><i class="bi bi-grid-3x3-gap"></i><span>More</span></a>
<?php else: ?>
    <a href="?page=dashboard&tab=support_dashboard" class="<?= $tab==='support_dashboard'?'active':'' ?>"><i class="bi bi-speedometer2"></i><span>Home</span></a>
    <a href="?page=dashboard&tab=customer_lookup" class="<?= $tab==='customer_lookup'?'active':'' ?>"><i class="bi bi-search"></i><span>Lookup</span></a>
    <a href="?page=dashboard&tab=scheduling" class="<?= $tab==='scheduling'?'active':'' ?>" style="position:relative;">
        <i class="bi bi-calendar-check" style="font-size:24px;background:#D41C1C;color:#fff;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;margin-top:-22px;box-shadow:0 4px 14px rgba(21,101,192,.4);"></i>
        <span style="margin-top:2px;">My Jobs</span></a>
    <a href="?page=dashboard&tab=my_account" class="<?= $tab==='my_account'?'active':'' ?>"><i class="bi bi-wallet2"></i><span>My Cash</span></a>
    <a href="?page=dashboard&tab=more_menu" class="<?= $tab==='more_menu'?'active':'' ?>"><i class="bi bi-grid-3x3-gap"></i><span>More</span></a>
<?php endif; ?>
<?php elseif ($isAccountant):
    $_allR4Nav  = $auth->getAllRetailers();
    $_debtCount = count(array_filter($_allR4Nav, fn($_r) => ((float)($_r['wallet']??0)) > 0.005));
    $_debtTotal = array_sum(array_map(fn($_r) => (float)($_r['wallet']??0), $_allR4Nav));
    if (!isset($cbPendCount)) {
        require_once __DIR__.'/lib/CashbookService.php';
        $_cbNav = new CashbookService($store, $dataDir);
        $_cbNavPend = $_cbNav->getPendingDisbursements('dishnet');
        $_cbNavPend4g = $_cbNav->getPendingDisbursements('4g');
        $_cbNavPendBc = $_cbNav->getPendingDisbursements('bluecard');
        $cbPendCount = count($_cbNavPend) + count($_cbNavPend4g) + count($_cbNavPendBc);
    }
?>
    <a href="?page=dashboard&tab=accounts_dashboard" class="<?= $tab==='accounts_dashboard'?'active':'' ?>"><i class="bi bi-speedometer2"></i><span>Home</span></a>
    <a href="?page=dashboard&tab=staff_cash_control" class="<?= $tab==='staff_cash_control'?'active':'' ?>"><i class="bi bi-people-fill"></i><span>Staff</span></a>
    <a href="?page=dashboard&tab=collect_payment" class="<?= $tab==='collect_payment'?'active':'' ?>" style="position:relative;">
        <i class="bi bi-plus-circle-fill" style="font-size:24px;background:#16a34a;color:#fff;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;margin-top:-22px;box-shadow:0 4px 14px rgba(22,163,74,.4);"></i>
        <span style="margin-top:2px;">Collect</span></a>
    <a href="?page=dashboard&tab=cashbook" class="<?= $tab==='cashbook'?'active':'' ?>" style="position:relative;">
        <i class="bi bi-journal-bookmark-fill"></i>
        <?php if($cbPendCount??0 > 0): ?>
        <span style="position:absolute;top:2px;right:4px;background:#ff6b6b;color:#fff;font-size:9px;font-weight:800;border-radius:10px;padding:1px 5px;min-width:16px;text-align:center;"><?= $cbPendCount??'' ?></span>
        <?php endif; ?>
        <span>Cashbook</span></a>
    <a href="?page=dashboard&tab=more_menu" class="<?= $tab==='more_menu'?'active':'' ?>"><i class="bi bi-grid-3x3-gap"></i><span>More</span></a>
<?php elseif ($isFieldAccountant): ?>
    <a href="?page=dashboard&tab=collect_payment" class="<?= $tab==='collect_payment'?'active':'' ?>"><i class="bi bi-cash-coin"></i><span>Collect</span></a>
    <a href="?page=dashboard&tab=my_account" class="<?= $tab==='my_account'?'active':'' ?>"><i class="bi bi-wallet2"></i><span>My Cash</span></a>
    <a href="?page=dashboard&tab=field_handover" class="<?= $tab==='field_handover'?'active':'' ?>" style="position:relative;">
        <i class="bi bi-arrow-left-right" style="font-size:22px;background:#6366f1;color:#fff;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;margin-top:-22px;box-shadow:0 4px 14px rgba(99,102,241,.4);"></i>
        <?php
            $_dikoId   = (int)($retailer['id'] ?? 0);
            $_dikoHovs = $store->load('cash_handovers.json') ?? [];
            $_dikoPend = count(array_filter($_dikoHovs, fn($h) =>
                (int)($h['to_id'] ?? 0) === $_dikoId && ($h['status'] ?? '') === 'pending'
            ));
        ?>
        <?php if ($_dikoPend > 0): ?>
        <span style="position:absolute;top:2px;right:4px;background:#ef4444;color:#fff;font-size:9px;font-weight:800;border-radius:10px;padding:1px 5px;min-width:16px;text-align:center;"><?= $_dikoPend ?></span>
        <?php endif; ?>
        <span style="margin-top:2px;">Chain</span>
    </a>
    <a href="?page=dashboard&tab=wallet" class="<?= $tab==='wallet'?'active':'' ?>"><i class="bi bi-journal-text"></i><span>Register</span></a>
    <a href="?page=dashboard&tab=more_menu" class="<?= $tab==='more_menu'?'active':'' ?>"><i class="bi bi-grid-3x3-gap"></i><span>More</span></a>
<?php else: ?>
    <?php $_hasJobs = !empty($retailer['ucrm_user_id']); ?>
    <a href="?page=dashboard&tab=sales_dashboard" class="<?= $tab==='sales_dashboard'?'active':'' ?>"><i class="bi bi-house-fill"></i><span>Home</span></a>
    <a href="?page=dashboard&tab=collect_payment" class="<?= $tab==='collect_payment'?'active':'' ?>"><i class="bi bi-cash-coin"></i><span>Collect</span></a>
    <?php if ($_hasJobs): ?>
    <a href="?page=dashboard&tab=scheduling" class="<?= $tab==='scheduling'?'active':'' ?>" style="position:relative;">
        <i class="bi bi-calendar-check-fill" style="font-size:22px;background:#1565C0;color:#fff;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;margin-top:-22px;box-shadow:0 4px 14px rgba(21,101,192,.4);"></i>
        <span style="margin-top:2px;">My Jobs</span>
    </a>
    <?php else: ?>
    <a href="?page=dashboard&tab=form" class="<?= $tab==='form'?'active':'' ?>" style="position:relative;">
        <i class="bi bi-plus-circle-fill" style="font-size:22px;background:#D41C1C;color:#fff;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;margin-top:-22px;box-shadow:0 4px 14px rgba(212,28,28,.35);"></i>
        <span style="margin-top:2px;">KYC</span></a>
    <?php endif; ?>
    <a href="?page=dashboard&tab=my_account" class="<?= $tab==='my_account'?'active':'' ?>"><i class="bi bi-wallet2"></i><span>My Cash</span></a>
    <a href="?page=dashboard&tab=more_menu" class="<?= $tab==='more_menu'?'active':'' ?>"><i class="bi bi-grid-3x3-gap"></i><span>More</span></a>
<?php endif; ?>
</div>
<?php endif; ?>

</div><!-- /kyc-layout -->

<script>
// N-06: Nav loading spinner  prevents double-tap on slow connections
(function(){
  var navLinks = document.querySelectorAll('.mobile-nav a[href]');
  navLinks.forEach(function(a){
    a.addEventListener('click', function(){
      // Don't show spinner if it's already the active tab
      if (a.classList.contains('active')) return;
      var s = document.createElement('div');
      s.id = 'navSpinner';
      s.style.cssText = 'position:fixed;inset:0;background:rgba(255,255,255,.55);z-index:99999;display:flex;align-items:center;justify-content:center;pointer-events:none;';
      s.innerHTML = '<div style="width:32px;height:32px;border:3px solid #f1f5f9;border-top-color:#D41C1C;border-radius:50%;animation:navSpin .6s linear infinite;"></div>';
      document.body.appendChild(s);
    });
  });
  // Remove spinner on page show (back/forward cache)
  window.addEventListener('pageshow', function(){
    var s = document.getElementById('navSpinner');
    if (s) s.remove();
  });
})();
</script>
<style>@keyframes navSpin{to{transform:rotate(360deg);}}</style>
<script>
// G-01: Global double-submit prevention  disables submit buttons on POST forms
(function(){
  document.addEventListener('submit', function(e){
    var form = e.target;
    if (form.method && form.method.toUpperCase() === 'POST') {
      // Skip GET filter forms
      var btns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
      btns.forEach(function(btn){
        if (btn.dataset.submitted) { e.preventDefault(); return; }
        btn.dataset.submitted = '1';
        btn.disabled = true;
        var orig = btn.textContent || btn.value;
        if (btn.tagName === 'BUTTON') { btn.dataset.origText = orig; btn.textContent = 'Processing...'; }
        else { btn.dataset.origValue = btn.value; btn.value = 'Processing...'; }
      });
      // Re-enable after 8s (in case of network error / redirect fail)
      setTimeout(function(){
        btns.forEach(function(btn){
          btn.disabled = false; delete btn.dataset.submitted;
          if (btn.dataset.origText) { btn.textContent = btn.dataset.origText; delete btn.dataset.origText; }
          if (btn.dataset.origValue) { btn.value = btn.dataset.origValue; delete btn.dataset.origValue; }
        });
      }, 8000);
    }
  }, true);
})();
</script>
<script>
function runWalletSync(btn) {
  var orig = btn.textContent;
  btn.disabled = true; btn.textContent = ' Running...';
  var TK = document.querySelector('meta[name=api-token]')?.content || '';
  fetch('?page=api&action=run_wallet_sync', {
          credentials:'same-origin',
          method:'POST', headers:{'Authorization':'Bearer '+TK}})
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.success) {
        btn.textContent = ' Done';
        btn.style.background = '#2E7D32';
        // Show output in a toast
        var lines = (d.output||'').split('\n').filter(Boolean);
        var last  = lines[lines.length-1] || 'Sync complete';
        var t = document.createElement('div');
        t.style.cssText='position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#1B5E20;color:#fff;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:99999;max-width:90vw;text-align:center;';
        t.textContent = last;
        document.body.appendChild(t);
        setTimeout(function(){ t.remove(); location.reload(); }, 2500);
      } else {
        btn.textContent = ' Failed';
        btn.style.background = '#c0392b';
        alert('Wallet sync failed:\n' + (d.output||'Unknown error'));
        setTimeout(function(){ btn.disabled=false; btn.textContent=orig; btn.style.background=''; }, 3000);
      }
    })
    .catch(function(e){
      btn.textContent = ' Error'; btn.disabled = false;
      alert('Request failed: ' + e.message);
    });
}
</script>

<script>
//  PWA Install prompt handling 
var _deferredPrompt = null;

// Dismissal memory  'forever' = never show again, 'snooze' = hide for 7 days
function _pwaDismissed() {
  try {
    var v = sessionStorage.getItem('pwa_dismiss');
    if (v === 'forever') return true;
    if (v) {
      // snooze: stored as timestamp in sessionStorage isn't persistent enough 
      // use a cookie instead for the 7-day snooze
    }
    var c = document.cookie.split(';').find(function(x){ return x.trim().startsWith('pwa_snooze='); });
    if (c) return true;
    return false;
  } catch(e) { return false; }
}

function _pwaHideBanner() {
  var btn = document.getElementById('pwaInstallBtn');
  var banner = document.getElementById('pwaInstallBanner');
  if (btn) btn.style.display = 'none';
  if (banner) banner.style.display = 'none';
}

function pwaDismiss(forever) {
  if (forever) {
    try { document.cookie = 'pwa_snooze=1; max-age=' + (365*24*3600) + '; path=/'; } catch(e){}
  } else {
    // Snooze 7 days
    try { document.cookie = 'pwa_snooze=1; max-age=' + (7*24*3600) + '; path=/'; } catch(e){}
  }
  _pwaHideBanner();
}

window.addEventListener('beforeinstallprompt', function(e) {
  e.preventDefault();
  _deferredPrompt = e;
  if (_pwaDismissed()) return; // user already said no  respect it
  var btn = document.getElementById('pwaInstallBtn');
  var banner = document.getElementById('pwaInstallBanner');
  if (btn) btn.style.display = 'flex';
  if (banner) banner.style.display = 'flex';
});

window.addEventListener('appinstalled', function() {
  _pwaHideBanner();
  _deferredPrompt = null;
  // Mark as permanently dismissed  it's installed now
  try { document.cookie = 'pwa_snooze=1; max-age=' + (365*24*3600) + '; path=/'; } catch(e){}
});

function pwaInstall() {
  if (_deferredPrompt) {
    _deferredPrompt.prompt();
    _deferredPrompt.userChoice.then(function(r) {
      _deferredPrompt = null;
      _pwaHideBanner();
      if (r.outcome === 'dismissed') {
        // They saw the prompt and said no  snooze 7 days
        try { document.cookie = 'pwa_snooze=1; max-age=' + (7*24*3600) + '; path=/'; } catch(e){}
      }
    });
  } else {
    document.getElementById('pwaManualModal').style.display = 'flex';
  }
}

// Service Worker registration
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('?page=app_sw')
      .then(function(reg) { console.log('SW registered'); })
      .catch(function(e) { console.log('SW failed:', e); });
  });
}

// Show banner after 3s  only if not dismissed
setTimeout(function() {
  if (!_deferredPrompt) return;
  if (_pwaDismissed()) return;
  var banner = document.getElementById('pwaInstallBanner');
  if (banner && banner.style.display === 'none') banner.style.display = 'flex';
}, 3000);
</script>

<!--  PWA Install Banner (bottom, shown by JS)  -->
<div id="pwaInstallBanner" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:8888;
  background:linear-gradient(135deg,#0F172A,#1E293B);border-top:2px solid #25D366;
  padding:14px 20px;align-items:center;gap:12px;box-shadow:0 -4px 20px rgba(0,0,0,.4);">
  <div style="font-size:28px;"></div>
  <div style="flex:1;min-width:0;">
    <div style="font-size:14px;font-weight:800;color:#fff;">Install DishNet App</div>
    <div style="font-size:11px;color:#94A3B8;margin-top:1px;">Add to your home screen for faster access</div>
  </div>
  <button onclick="pwaInstall()" style="background:linear-gradient(135deg,#128C7E,#25D366);color:#fff;border:none;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;">
    Install
  </button>
  <div style="display:flex;flex-direction:column;align-items:center;gap:4px;">
    <button onclick="pwaDismiss(false)"
      style="background:none;border:none;color:#64748B;font-size:22px;cursor:pointer;padding:0 4px;line-height:1;" title="Hide for 7 days">&times;</button>
    <button onclick="pwaDismiss(true)"
      style="background:none;border:none;color:#475569;font-size:9px;cursor:pointer;padding:0;white-space:nowrap;text-decoration:underline;" title="Never show again">Don't show</button>
  </div>
</div>

<!--  Manual Install Instructions Modal  -->
<div id="pwaManualModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);align-items:flex-end;justify-content:center;">
  <div style="background:#fff;border-radius:24px 24px 0 0;width:100%;max-width:500px;padding:28px 24px 40px;max-height:90vh;overflow-y:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <strong style="font-size:18px;color:#1e293b;"> Install DishNet App</strong>
      <button onclick="document.getElementById('pwaManualModal').style.display='none'"
        style="background:none;border:none;font-size:26px;cursor:pointer;color:#9ca3af;line-height:1;">&times;</button>
    </div>

    <!-- Android Chrome steps -->
    <div style="background:#E3F2FD;border-radius:14px;padding:16px;margin-bottom:16px;">
      <div style="font-size:13px;font-weight:800;color:#1565C0;margin-bottom:12px;"> Android (Chrome)</div>
      <div style="display:flex;flex-direction:column;gap:10px;font-size:13px;color:#1e293b;">
        <div style="display:flex;gap:10px;align-items:flex-start;">
          <span style="background:#D41C1C;color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0;">1</span>
          <span>Tap the <strong> three-dot menu</strong> at the top right of Chrome</span>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-start;">
          <span style="background:#D41C1C;color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0;">2</span>
          <span>Tap <strong>"Add to Home screen"</strong></span>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-start;">
          <span style="background:#D41C1C;color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0;">3</span>
          <span>Tap <strong>"Add"</strong> in the popup  done! </span>
        </div>
      </div>
    </div>

    <!-- Samsung Browser note -->
    <div style="background:#FFF3E0;border-radius:14px;padding:14px;margin-bottom:16px;">
      <div style="font-size:12px;font-weight:800;color:#E65100;margin-bottom:6px;"> Not working?</div>
      <div style="font-size:12px;color:#7c3aed;">Make sure you are using <strong>Google Chrome</strong>, not Samsung Internet or another browser. The install option only works in Chrome.</div>
    </div>

    <!-- Share the install page -->
    <div style="background:#F0FDF4;border-radius:14px;padding:14px;margin-bottom:20px;">
      <div style="font-size:12px;font-weight:800;color:#15803d;margin-bottom:6px;"> Share install link</div>
      <div style="font-size:12px;color:#374151;margin-bottom:10px;">Copy this link and open it in Chrome:</div>
      <div id="installUrlBox" style="background:#fff;border:1px solid #d1fae5;border-radius:8px;padding:10px;font-size:11px;color:#1565C0;word-break:break-all;font-family:monospace;margin-bottom:8px;">
        <?= (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'].'?page=install' ?>
      </div>
      <button onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('installUrlBox').textContent.trim()).then(function(){this.textContent=' Copied!';}.bind(this))"
        style="width:100%;padding:10px;background:#15803d;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
         Copy Link
      </button>
    </div>

    <a href="<?= (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'] ?>?page=install" target="_blank"
      style="display:block;text-align:center;padding:13px;background:linear-gradient(135deg,#128C7E,#25D366);color:#fff;border-radius:12px;font-size:14px;font-weight:800;text-decoration:none;">
       Open Install Guide Page
    </a>
  </div>
</div>

<!--  Global Native Scanner Utility + App Version Check (v2.4+)  -->
<script>
window.dishnetScan = function(callbackId, onResult) {
  var hasNative = window.dishnetNativeAvailable && typeof DishNetNative !== 'undefined' &&
      typeof DishNetNative.hasBarcodeScanner === 'function' && DishNetNative.hasBarcodeScanner();
  if (!hasNative) { if (onResult) onResult('', 'NO_NATIVE', callbackId); return false; }
  window._nativeScanCallback = function(value, format, id) {
    window._nativeScanCallback = null;
    if (onResult) onResult(value || '', format || 'CANCELLED', id || callbackId);
  };
  DishNetNative.scanBarcode(callbackId);
  return true;
};
window.hasNativeScanner = function() {
  return window.dishnetNativeAvailable && typeof DishNetNative !== 'undefined' &&
      typeof DishNetNative.hasBarcodeScanner === 'function' && DishNetNative.hasBarcodeScanner();
};

//  App Version Check 
// Runs only inside Android app. Compares native version against server min_version.
// Shows a sticky banner if outdated  agent cannot miss it.
(function(){
  if (typeof DishNetNative === 'undefined') return;
  var appVer = '0';
  try { appVer = DishNetNative.getAppVersion(); } catch(e){ return; }
  // Store for other components to read
  window._dishnetAppVersion = appVer;

  // Fetch server-side requirements
  var xhr = new XMLHttpRequest();
  xhr.open('GET', location.pathname + '?page=api&action=app_version', true);
  xhr.timeout = 5000;
  xhr.onload = function() {
    try {
      var d = JSON.parse(xhr.responseText);
      var sv = (d.data || d);
      var minVer = sv.min_version || '2.4';
      var latestVer = sv.latest_version || minVer;
      var updateUrl = sv.update_url || '';

      if (_isOlderVersion(appVer, minVer)) {
        _showAppUpdateBanner(appVer, latestVer, updateUrl, true);
      } else if (_isOlderVersion(appVer, latestVer)) {
        _showAppUpdateBanner(appVer, latestVer, updateUrl, false);
      }
    } catch(e) { console.log('[DishNet] Version check parse error:', e); }
  };
  xhr.send();

  function _isOlderVersion(current, required) {
    var c = current.split('.').map(Number);
    var r = required.split('.').map(Number);
    var len = Math.max(c.length, r.length);
    for (var i = 0; i < len; i++) {
      var cv = c[i] || 0, rv = r[i] || 0;
      if (rv > cv) return true;
      if (rv < cv) return false;
    }
    return false;
  }

  function _showAppUpdateBanner(current, latest, url, isRequired) {
    var hasNativeUpdate = window.dishnetNativeAvailable && typeof DishNetNative !== 'undefined' && typeof DishNetNative.updateApp === 'function';
    var bar = document.createElement('div');
    bar.id = 'dn-update-bar';
    bar.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:99999;padding:14px 16px;' +
      'background:' + (isRequired ? '#7f1d1d' : '#1e3a5f') + ';color:#fff;font-size:13px;' +
      'display:flex;align-items:center;gap:10px;flex-wrap:wrap;box-shadow:0 -4px 20px rgba(0,0,0,.4);';
    var icon = isRequired ? '\u26a0\ufe0f' : '\u2b06\ufe0f';
    var msg = isRequired
      ? icon + ' <strong>Update required.</strong> You have v' + current + ', minimum v' + latest + ' needed.'
      : icon + ' <strong>Update available.</strong> v' + latest + ' ready.';
    var btnId = 'dn-update-btn';
    if (hasNativeUpdate) {
      bar.innerHTML = '<div style="flex:1;"><span id="dn-update-msg">' + msg + '</span>' +
        '<div id="dn-update-progress" style="display:none;margin-top:6px;background:rgba(255,255,255,.2);border-radius:4px;height:6px;overflow:hidden;">' +
        '<div id="dn-update-pbar" style="height:100%;background:#4ade80;width:0%;transition:width .3s;border-radius:4px;"></div></div></div>' +
        '<button id="' + btnId + '" onclick="dnDoNativeUpdate()" style="background:#fff;color:#141414;padding:8px 16px;border:none;border-radius:8px;' +
        'font-weight:800;font-size:12px;cursor:pointer;white-space:nowrap;">Update Now</button>' +
        (isRequired ? '' : '<button onclick="this.parentElement.remove();document.body.style.paddingBottom=\'0\'" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer;padding:0 4px;">\u2715</button>');
    } else {
      bar.innerHTML = '<div style="flex:1;">' + msg + '</div>' +
        '<a href="' + url + '" target="_blank" style="background:#fff;color:#141414;padding:8px 16px;border-radius:8px;' +
        'font-weight:800;font-size:12px;text-decoration:none;white-space:nowrap;">Update Now</a>' +
        (isRequired ? '' : '<button onclick="this.parentElement.remove();document.body.style.paddingBottom=\'0\'" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer;padding:0 4px;">\u2715</button>');
    }
    document.body.appendChild(bar);
    document.body.style.paddingBottom = '70px';
  }
})();

function dnDoNativeUpdate() {
  var btn = document.getElementById('dn-update-btn');
  var msg = document.getElementById('dn-update-msg');
  var prog = document.getElementById('dn-update-progress');
  if (btn) { btn.disabled = true; btn.textContent = 'Downloading...'; btn.style.opacity = '0.6'; }
  if (prog) prog.style.display = 'block';
  if (msg) msg.innerHTML = '\u2b07\ufe0f Downloading update...';
  DishNetNative.updateApp();
}

window._onAppUpdateProgress = function(state, percent) {
  var btn = document.getElementById('dn-update-btn');
  var msg = document.getElementById('dn-update-msg');
  var pbar = document.getElementById('dn-update-pbar');
  if (state === 'downloading') {
    if (pbar) pbar.style.width = percent + '%';
    if (msg) msg.innerHTML = '\u2b07\ufe0f Downloading... ' + percent + '%';
  } else if (state === 'installing') {
    if (msg) msg.innerHTML = '\u2705 Download complete \u2014 opening installer...';
    if (btn) { btn.textContent = 'Installing...'; }
    if (pbar) pbar.style.width = '100%';
  } else if (state === 'error') {
    if (msg) msg.innerHTML = '\u274c Download failed. Please try again.';
    if (btn) { btn.disabled = false; btn.textContent = 'Retry'; btn.style.opacity = '1'; }
  }
};
</script>

</body>
</html>
<?php
include __DIR__ . '/includes/notify_actions.php';

endif; // end of if ($page === 'reset_password') / elseif (login) / else (dashboard)

ob_end_flush(); // send buffered output
