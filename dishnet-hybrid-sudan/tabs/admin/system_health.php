<?php
/**
 * System health — one page that answers "is this working, and if not, why".
 *
 * Every check reports the real error rather than "could not connect", because
 * a wrong API key, a name that will not resolve and a rejected certificate all
 * need different fixes. No secret is ever printed: keys show as set or not set.
 *
 * Read-only. Nothing here changes configuration.
 */

require_once dirname(__DIR__, 2) . '/lib/PluginConfig.php';
require_once dirname(__DIR__, 2) . '/lib/EvolutionApiService.php';
require_once dirname(__DIR__, 2) . '/lib/EvoWebhookGuard.php';
require_once dirname(__DIR__, 2) . '/lib/DishNetTools.php';
require_once dirname(__DIR__, 2) . '/lib/DishNetAiBrain.php';

$_hRoot = dirname(__DIR__, 2);
$_hCfg  = PluginConfig::load($_hRoot, $GLOBALS['dataDir'] ?? ($_hRoot . '/data'));
$_hEvo  = new EvolutionApiService($_hCfg);
$_hPdo  = $store->getPdo();

$H = [];
$row = static function (string $group, string $name, string $state, string $detail = '') use (&$H): void {
    $H[$group][] = ['name' => $name, 'state' => $state, 'detail' => $detail];
};

// ── Core ─────────────────────────────────────────────────────────────────────
try {
    $_hPdo->query('SELECT 1')->fetchColumn();
    $row('Core', 'Database', 'ok', 'SQLite responding');
} catch (\Throwable $e) {
    $row('Core', 'Database', 'bad', $e->getMessage());
}

$_hTools = new DishNetTools($store, $_hCfg, $_hRoot);
$_hCrm   = $_hTools->describeProductSchema();
if (!empty($_hCrm['ok'])) {
    $reach = [];
    foreach ((array)$_hCrm['data'] as $res => $info) {
        $reach[] = $res . ': ' . (!empty($info['reachable'])
            ? ((int)($info['rows'] ?? 0) . ' rows') : 'unreachable');
    }
    $row('Core', 'uCRM API', 'ok', implode('  ·  ', $reach));
} else {
    $row('Core', 'uCRM API', 'bad', (string)($_hCrm['error'] ?? 'unreachable'));
}

$_hSpawn = function_exists('exec')
    && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
$row('Core', 'Background worker', $_hSpawn ? 'ok' : 'warn',
     $_hSpawn ? 'exec() available — replies are immediate'
              : 'exec() blocked — replies wait for the scheduled run');

// Has the scheduler actually run recently?
$_hSched = $GLOBALS['dataDir'] . '/master_schedule.json';
if (is_file($_hSched)) {
    $age = time() - (int)@filemtime($_hSched);
    $row('Core', 'Scheduler (cron)',
         $age < 600 ? 'ok' : 'warn',
         'last ran ' . ($age < 120 ? 'under 2 minutes' : round($age / 60) . ' minutes') . ' ago');
} else {
    $row('Core', 'Scheduler (cron)', 'warn',
         'has never run — install the master.php crontab entry');
}

// ── AI ───────────────────────────────────────────────────────────────────────
$_hBrain    = new DishNetAiBrain($_hCfg);
$_hProvider = ($_hCfg['ai_provider'] ?? 'claude') === 'openai' ? 'openai' : 'claude';
$row('AI', 'Provider key', $_hBrain->isConfigured() ? 'ok' : 'bad',
     $_hBrain->isConfigured() ? $_hProvider . ' key set' : 'no key set for ' . $_hProvider);
$row('AI', 'Answering customers',
     PluginConfig::toBool($_hCfg['ai_enabled'] ?? false) ? 'ok' : 'warn',
     PluginConfig::toBool($_hCfg['ai_enabled'] ?? false)
        ? 'ON' : 'OFF — messages are stored, nothing is sent');

$_hQ = ['pending' => 0, 'failed' => 0, 'dead' => 0, 'done' => 0];
try {
    foreach ($_hPdo->query("SELECT status, COUNT(*) c FROM events WHERE event_type='ai.reply' GROUP BY status")
                   ->fetchAll(\PDO::FETCH_ASSOC) as $r) { $_hQ[(string)$r['status']] = (int)$r['c']; }
} catch (\Throwable $e) {}
$row('AI', 'Reply queue', ($_hQ['dead'] > 0 ? 'warn' : 'ok'),
     "{$_hQ['pending']} waiting · {$_hQ['failed']} retrying · {$_hQ['dead']} gave up · {$_hQ['done']} answered");

// ── Evolution ────────────────────────────────────────────────────────────────
if (!$_hEvo->canReachApi()) {
    $row('WhatsApp', 'Evolution API', 'bad',
         'URL or key not set — add them on the plugin Configuration screen');
} else {
    $r = $_hEvo->fetchInstances();
    if (!empty($r['ok'])) {
        $row('WhatsApp', 'Evolution API', 'ok', 'reachable');
    } else {
        $e = $_hEvo->getLastError();
        $msg = trim((string)($e['message'] ?? ($r['error'] ?? 'unreachable')));
        if (!empty($e['http'])) $msg .= ' (HTTP ' . $e['http'] . ')';
        // Name the likely cause — these need different fixes.
        if (stripos($msg, 'certificate') !== false || stripos($msg, 'SSL') !== false) {
            $msg .= ' — TLS: the certificate was rejected, not the key.';
        } elseif (stripos($msg, 'resolve') !== false) {
            $msg .= ' — DNS: this server cannot resolve that hostname.';
        } elseif (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false) {
            $msg .= ' — Timeout: reachable name, no answer. Check the port and any firewall.';
        } elseif (!empty($e['http']) && (int)$e['http'] === 401) {
            $msg .= ' — Authentication: the API key was rejected.';
        } elseif (!empty($e['http']) && (int)$e['http'] === 404) {
            $msg .= ' — Endpoint: reached Evolution, but that path does not exist.';
        }
        $row('WhatsApp', 'Evolution API', 'bad', $msg);
    }

    foreach (EvolutionApiService::CHANNELS as $ch) {
        $inst = $_hEvo->instanceFor($ch);
        if ($inst === '') { $row('WhatsApp', ucfirst($ch), 'warn', 'no instance assigned'); continue; }
        $state = $_hEvo->connectionState($inst);
        if ($state === null)      $row('WhatsApp', ucfirst($ch), 'bad',  $inst . ' — instance not found or unreachable');
        elseif ($state === 'open')$row('WhatsApp', ucfirst($ch), 'ok',   $inst . ' — connected');
        else                      $row('WhatsApp', ucfirst($ch), 'warn', $inst . ' — ' . $state . ' (not paired; scan the QR)');
    }
}

$_hSecret = PluginConfig::isSet_($_hCfg, 'evo_webhook_secret')
    ? 'set manually' : (EvoWebhookGuard::autoSecret($GLOBALS['dataDir']) !== '' ? 'generated automatically' : '');
$row('WhatsApp', 'Webhook secret', $_hSecret !== '' ? 'ok' : 'bad',
     $_hSecret !== '' ? $_hSecret : 'could not be created — is the data directory writable?');

$_hPub = rtrim(trim((string)($_hCfg['plugin_public_url'] ?? '')), '/');
if ($_hPub === '') {
    $row('WhatsApp', 'Plugin address', 'warn',
         'not set — paste the Public URL from the plugin page so Evolution can reach the webhook');
} elseif (strpos($_hPub, ':8443') !== false) {
    $row('WhatsApp', 'Plugin address', 'bad',
         $_hPub . ' — port 8443 bypasses Traefik and serves a self-signed certificate; Evolution will refuse it');
} elseif (substr($_hPub, -11) === '/public.php') {
    $row('WhatsApp', 'Plugin address', 'warn',
         $_hPub . ' — drop the /public.php; paste the folder only');
} else {
    $row('WhatsApp', 'Plugin address', 'ok',
         $_hPub . '/public.php?page=evo_webhook');
}

$_hSeen = 0;
try { $_hSeen = (int)$_hPdo->query("SELECT COUNT(*) FROM evo_webhook_seen WHERE received_at > datetime('now','-24 hours')")->fetchColumn(); } catch (\Throwable $e) {}
$row('WhatsApp', 'Inbound in last 24h', $_hSeen > 0 ? 'ok' : 'warn',
     $_hSeen > 0 ? $_hSeen . ' messages received' : 'nothing received — is the webhook registered?');

$_bad  = 0; $_warn = 0;
foreach ($H as $rows) foreach ($rows as $r2) { if ($r2['state']==='bad') $_bad++; elseif ($r2['state']==='warn') $_warn++; }
?>
<style>
 .sh-head{margin:0 0 18px}
 .sh-head h2{margin:0 0 4px;font-size:1.25rem}
 .sh-banner{padding:12px 16px;border-radius:5px;margin-bottom:18px;font-size:14px}
 .sh-good{background:#e2efeb;border:1px solid #0b6b5b;color:#0b6b5b}
 .sh-stop{background:#f8e6e4;border:1px solid #9e2f28;color:#9e2f28}
 .sh-warn{background:#f7ebdc;border:1px solid #a85b0b;color:#a85b0b}
 .sh-grp{margin-bottom:18px;border:1px solid #dce3de;border-radius:5px;overflow:hidden;background:#fff}
 .sh-grp h3{margin:0;padding:9px 15px;background:#edf1ee;font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:#5b6a63}
 .sh-row{display:flex;gap:14px;align-items:baseline;padding:10px 15px;border-top:1px solid #dce3de}
 .sh-row .n{flex:0 0 190px;font-weight:500}
 .sh-row .d{color:#5b6a63;font-size:13.5px;word-break:break-word}
 .sh-pill{margin-left:auto;font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:2px 8px;border-radius:3px;white-space:nowrap}
 .sh-ok{background:#e2efeb;color:#0b6b5b}.sh-w{background:#f7ebdc;color:#a85b0b}.sh-b{background:#f8e6e4;color:#9e2f28}
</style>
<div class="sh-head">
  <h2>System health</h2>
  <div style="color:#5b6a63;font-size:14px">Live checks. Read-only — nothing here changes settings, and no key is ever shown.</div>
</div>

<?php if ($_bad === 0 && $_warn === 0): ?>
  <div class="sh-banner sh-good"><b>All checks passing.</b></div>
<?php elseif ($_bad > 0): ?>
  <div class="sh-banner sh-stop"><b><?= $_bad ?> problem<?= $_bad===1?'':'s' ?></b><?= $_warn ? " and {$_warn} to look at" : '' ?>.</div>
<?php else: ?>
  <div class="sh-banner sh-warn"><b><?= $_warn ?> thing<?= $_warn===1?'':'s' ?> to look at.</b> Nothing is broken.</div>
<?php endif; ?>

<?php foreach ($H as $group => $rows): ?>
  <div class="sh-grp">
    <h3><?= h($group) ?></h3>
    <?php foreach ($rows as $r): ?>
      <div class="sh-row">
        <span class="n"><?= h($r['name']) ?></span>
        <span class="d"><?= h($r['detail']) ?></span>
        <span class="sh-pill <?= $r['state']==='ok'?'sh-ok':($r['state']==='warn'?'sh-w':'sh-b') ?>">
          <?= $r['state']==='ok'?'ok':($r['state']==='warn'?'check':'fix') ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
