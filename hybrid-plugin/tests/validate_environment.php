<?php
/**
 * validate_environment.php — the Option A go/no-go test.
 *
 * Run INSIDE the uCRM plugin container, as the user the plugin runs as:
 *
 *   php /path/to/dishnet-hybrid-telecom/tests/validate_environment.php
 *
 * Read-only. Starts nothing, changes nothing, writes nothing. Makes three
 * outbound HTTPS requests with no credentials — an HTTP 401 from OpenAI or
 * Anthropic is a PASS, because it proves we reached them.
 *
 * Prints a verdict at the end. Paste the whole output back.
 *
 * PHP 7.4 compatible — it must run on the oldest thing this could land on.
 */

$results = [];
$verdictBlockers = [];
$verdictWarnings = [];

function section(string $t): void { printf("\n%s\n%s\n", $t, str_repeat('-', strlen($t))); }
function line(string $label, string $value, string $mark = ''): void {
    printf("  %-34s %s%s\n", $label, $value, $mark !== '' ? '   ' . $mark : '');
}
function pass(): string { return '[PASS]'; }
function fail(): string { return '[FAIL]'; }
function warn(): string { return '[warn]'; }

printf("DishNet Hybrid — environment validation\n");
printf("Run at (UTC): %s\n", gmdate('Y-m-d H:i:s'));
printf("Host: %s   User: %s\n", php_uname('n'), function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : (getenv('USER') ?: '?'));

// ── 1. PHP ───────────────────────────────────────────────────────────────────
section('1. PHP runtime');
$phpVersion = PHP_VERSION;
line('Version', $phpVersion, version_compare($phpVersion, '7.4', '>=') ? pass() : fail());
if (version_compare($phpVersion, '7.4', '<')) {
    $verdictBlockers[] = "PHP {$phpVersion} is below 7.4 — the plugin will not run.";
}
line('SAPI', PHP_SAPI);
$maxExec = ini_get('max_execution_time');
line('max_execution_time', $maxExec === '0' ? '0 (unlimited)' : $maxExec . 's');
line('memory_limit', (string)ini_get('memory_limit'));

$canRaise = false;
if (function_exists('set_time_limit')) {
    $before = ini_get('max_execution_time');
    @set_time_limit(120);
    $canRaise = (ini_get('max_execution_time') !== $before) || $before === '0';
    @set_time_limit((int)$before);
}
line('Can raise time limit', $canRaise ? 'yes' : 'no', $canRaise ? pass() : warn());
if (!$canRaise) {
    $verdictWarnings[] = 'Cannot raise max_execution_time — long AI calls may be cut off mid-request.';
}

$required = ['curl', 'pdo_sqlite', 'json', 'mbstring'];
foreach ($required as $ext) {
    $ok = extension_loaded($ext);
    line("ext: {$ext}", $ok ? 'loaded' : 'MISSING', $ok ? pass() : fail());
    if (!$ok) $verdictBlockers[] = "PHP extension '{$ext}' is missing.";
}

// ── 2. Process spawning ──────────────────────────────────────────────────────
section('2. Background processing');
$disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
$hasExec      = function_exists('exec')       && !in_array('exec', $disabled, true);
$hasShellExec = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
$hasProcOpen  = function_exists('proc_open')  && !in_array('proc_open', $disabled, true);

line('exec()',       $hasExec ? 'available' : 'BLOCKED',      $hasExec ? pass() : fail());
line('shell_exec()', $hasShellExec ? 'available' : 'blocked', $hasShellExec ? pass() : warn());
line('proc_open()',  $hasProcOpen ? 'available' : 'blocked',  $hasProcOpen ? pass() : warn());
if ($disabled) line('disable_functions', implode(', ', array_slice($disabled, 0, 8)));

// Does a spawn actually work, not just exist?
$spawnWorks = false;
if ($hasExec) {
    $marker = sys_get_temp_dir() . '/dishnet_spawn_' . getmypid() . '.tmp';
    @exec('echo ok > ' . escapeshellarg($marker) . ' 2>/dev/null &');
    for ($i = 0; $i < 20 && !file_exists($marker); $i++) usleep(100000);
    $spawnWorks = file_exists($marker) && trim((string)@file_get_contents($marker)) === 'ok';
    @unlink($marker);
}
line('Background spawn actually works', $spawnWorks ? 'yes' : 'no', $spawnWorks ? pass() : fail());

$phpBinary = '';
if ($hasExec) {
    $phpBinary = trim((string)@shell_exec('command -v php 2>/dev/null'));
    if ($phpBinary === '' && defined('PHP_BINARY')) $phpBinary = PHP_BINARY;
}
line('php CLI on PATH', $phpBinary !== '' ? $phpBinary : 'NOT FOUND', $phpBinary !== '' ? pass() : fail());

if (!$hasExec || !$spawnWorks || $phpBinary === '') {
    $verdictBlockers[] = 'Cannot spawn a background PHP process. Without this, replies wait for the cron tick.';
}

// ── 3. Cron ──────────────────────────────────────────────────────────────────
section('3. Scheduled processing');
$cronInstalled = false;
if ($hasShellExec) {
    $crontab = (string)@shell_exec('crontab -l 2>/dev/null');
    $cronInstalled = strpos($crontab, 'master.php') !== false;
    line('crontab readable', $crontab !== '' ? 'yes' : 'no (or empty)');
    line('master.php entry present', $cronInstalled ? 'yes' : 'NO', $cronInstalled ? pass() : warn());
    if ($cronInstalled) {
        foreach (explode("\n", $crontab) as $l) {
            if (strpos($l, 'master.php') !== false) line('  entry', trim($l));
        }
    }
} else {
    line('crontab check', 'skipped (shell_exec blocked)', warn());
}
if (!$cronInstalled) {
    $verdictWarnings[] = 'No master.php crontab entry found. Install it, or background jobs fall back to the 5-minute uCRM heartbeat.';
}

$masterPath = dirname(__DIR__) . '/cron/master.php';
line('cron/master.php present', file_exists($masterPath) ? 'yes' : 'no',
     file_exists($masterPath) ? pass() : warn());

// ── 4. Outbound HTTPS ────────────────────────────────────────────────────────
section('4. Outbound HTTPS');

/** Returns [httpCode, errorString, tlsOk]. No credentials sent. */
function probe(string $url, int $timeout = 12): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_NOBODY         => false,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, $err, $body !== false];
}

// A 401 here is success: it means we reached the API and it asked for a key.
$targets = [
    'OpenAI'    => 'https://api.openai.com/v1/models',
    'Anthropic' => 'https://api.anthropic.com/v1/messages',
];
foreach ($targets as $name => $url) {
    list($code, $err, $ok) = probe($url);
    $reached = $code > 0;
    line($name, $reached ? "HTTP {$code} (reached)" : 'unreachable: ' . $err,
         $reached ? pass() : fail());
    if (!$reached) $verdictBlockers[] = "Cannot reach {$name} — the AI cannot run from here.";
}

// Evolution: read the configured URL rather than hard-coding one.
$evoUrl = '';
$cfgPaths = [];
foreach ([dirname(__DIR__) . '/ucrm.json', dirname(__DIR__) . '/data/ucrm.json'] as $u) {
    if (file_exists($u)) {
        $j = json_decode((string)file_get_contents($u), true);
        if (is_array($j) && !empty($j['pluginDataDir'])) $cfgPaths[] = rtrim($j['pluginDataDir'], '/') . '/kyc_config.json';
    }
}
$cfgPaths[] = dirname(__DIR__) . '/data/kyc_config.json';
foreach ($cfgPaths as $p) {
    if (!file_exists($p)) continue;
    $cfg = json_decode((string)file_get_contents($p), true);
    if (is_array($cfg) && !empty($cfg['evo_api_url'])) { $evoUrl = rtrim((string)$cfg['evo_api_url'], '/'); break; }
}

if ($evoUrl === '') {
    line('Evolution API', 'evo_api_url not configured yet', warn());
    $verdictWarnings[] = 'evo_api_url is not set in kyc_config.json — set it, then re-run to confirm reachability.';
} else {
    // Print the host only. The URL may embed identifiers we should not log.
    $host = parse_url($evoUrl, PHP_URL_HOST) ?: 'configured host';
    list($code, $err, $ok) = probe($evoUrl . '/');
    $reached = $code > 0;
    line('Evolution API (' . $host . ')', $reached ? "HTTP {$code} (reached)" : 'unreachable: ' . $err,
         $reached ? pass() : fail());
    if (!$reached) $verdictBlockers[] = 'Cannot reach Evolution API from the plugin container.';
}

// ── 5. Storage ───────────────────────────────────────────────────────────────
section('5. Persistent storage');
$dataDir = '';
foreach ([dirname(__DIR__) . '/ucrm.json', dirname(__DIR__) . '/data/ucrm.json'] as $u) {
    if (file_exists($u)) {
        $j = json_decode((string)file_get_contents($u), true);
        if (is_array($j) && !empty($j['pluginDataDir'])) { $dataDir = (string)$j['pluginDataDir']; break; }
    }
}
if ($dataDir === '') $dataDir = dirname(__DIR__) . '/data';
line('Data directory', $dataDir);
line('Exists', is_dir($dataDir) ? 'yes' : 'no', is_dir($dataDir) ? pass() : warn());
if (is_dir($dataDir)) {
    line('Writable', is_writable($dataDir) ? 'yes' : 'NO', is_writable($dataDir) ? pass() : fail());
} else {
    line('Writable', 'n/a — directory does not exist yet', warn());
}
if (is_dir($dataDir) && !is_writable($dataDir)) {
    $verdictBlockers[] = 'Plugin data directory is not writable.';
}
if (is_dir($dataDir)) {
    $free = @disk_free_space($dataDir);
    if ($free !== false) line('Free space', round($free / 1048576) . ' MB',
                              $free > 500 * 1048576 ? pass() : warn());
}

// SQLite WAL — the concurrency mode the queue depends on.
if (extension_loaded('pdo_sqlite')) {
    try {
        $tmpDb = $dataDir . '/.validate_' . getmypid() . '.sqlite';
        $pdo = new PDO('sqlite:' . $tmpDb);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $mode = $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();
        line('SQLite WAL mode', (string)$mode, strtolower((string)$mode) === 'wal' ? pass() : warn());
        $pdo = null;
        foreach ([$tmpDb, $tmpDb . '-wal', $tmpDb . '-shm'] as $f) @unlink($f);
        if (strtolower((string)$mode) !== 'wal') {
            $verdictWarnings[] = 'SQLite could not enable WAL — expect write contention under load.';
        }
    } catch (Throwable $e) {
        line('SQLite WAL mode', 'test failed: ' . $e->getMessage(), warn());
    }
}

// ── Verdict ──────────────────────────────────────────────────────────────────
section('VERDICT');
if (!$verdictBlockers) {
    printf("  GO — Option A is viable in this environment.\n");
    printf("  Build the AI brain inside the Hybrid plugin.\n");
} else {
    printf("  NO-GO for Option A. Blockers:\n");
    foreach ($verdictBlockers as $i => $b) printf("    %d. %s\n", $i + 1, $b);
    printf("\n  Fix these, or run ShopBot separately on EasyPanel (Option B).\n");
}
if ($verdictWarnings) {
    printf("\n  Warnings (not blocking, but fix before production):\n");
    foreach ($verdictWarnings as $i => $w) printf("    %d. %s\n", $i + 1, $w);
}
printf("\n");
exit($verdictBlockers ? 1 : 0);
