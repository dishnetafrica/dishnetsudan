<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';

/**
 * public.php — the plugin's admin page, shown in an iframe inside UISP.
 *
 * Read-only status. It answers one question: is this thing working, and if
 * not, what is stopping it. No secret is ever rendered — secrets show as
 * "set" or "not set" only.
 */

require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/EvolutionApiService.php';
require_once __DIR__ . '/lib/DishNetTools.php';

$store  = SqliteStore::create($dataDir);
$config = PluginConfig::load(__DIR__, $dataDir);
$pdo    = $store->getPdo();

$evo    = new EvolutionApiService($config);
$tools  = new DishNetTools($store, $config, __DIR__);
$action = (string)($_GET['action'] ?? '');

// ── Checks ───────────────────────────────────────────────────────────────────
$checks = [];
$add = static function (string $label, bool $ok, string $detail, bool $warnOnly = false) use (&$checks): void {
    $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'warn' => $warnOnly];
};

$aiOn = PluginConfig::toBool($config['ai_enabled'] ?? false);
$add('Answering customers', $aiOn, $aiOn ? 'ON' : 'OFF — messages are stored but no reply is sent', !$aiOn);

$add('Evolution API URL', PluginConfig::isSet_($config, 'evo_api_url'),
     PluginConfig::isSet_($config, 'evo_api_url')
        ? (string)parse_url((string)$config['evo_api_url'], PHP_URL_HOST)
        : 'not set');
$add('Evolution API key', PluginConfig::isSet_($config, 'evo_api_key'),
     PluginConfig::isSet_($config, 'evo_api_key') ? 'set' : 'not set');
$add('Webhook secret', PluginConfig::isSet_($config, 'evo_webhook_secret'),
     PluginConfig::isSet_($config, 'evo_webhook_secret')
        ? 'set' : 'NOT SET — the webhook rejects every request until this is filled in');

$provider = ($config['ai_provider'] ?? 'claude') === 'openai' ? 'openai' : 'claude';
$keyField = $provider === 'openai' ? 'openai_api_key' : 'claude_api_key';
$add('AI provider key', PluginConfig::isSet_($config, $keyField),
     PluginConfig::isSet_($config, $keyField) ? $provider . ' key set' : 'no key for ' . $provider);

$channels = $evo->configuredChannels();
$add('WhatsApp numbers configured', $channels !== [],
     $channels ? implode(', ', $channels) : 'none — set at least one instance name');

// uCRM reachability, via the same client the tools use.
$crmProbe = $tools->describeProductSchema();
$crmOk    = !empty($crmProbe['ok']);
$add('uCRM API', $crmOk, $crmOk ? 'reachable' : (string)($crmProbe['error'] ?? 'unreachable'));

$spawnOk = function_exists('exec')
    && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
$add('Instant replies (background spawn)', $spawnOk,
     $spawnOk ? 'available' : 'exec() blocked — replies arrive on the 1-minute schedule instead', true);

// ── Queue ────────────────────────────────────────────────────────────────────
$queue = ['pending' => 0, 'failed' => 0, 'dead' => 0, 'done' => 0];
try {
    $rows = $pdo->query("SELECT status, COUNT(*) c FROM events WHERE event_type='ai.reply' GROUP BY status")
                ->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $r) $queue[(string)$r['status']] = (int)$r['c'];
} catch (\Throwable $e) { /* table appears on first migration run */ }

// ── Conversations ────────────────────────────────────────────────────────────
$convs = [];
try {
    $convs = $pdo->query(
        "SELECT phone, channel, display_name, crm_client_name, state, message_count, last_message_at
         FROM wa_conversations ORDER BY last_message_at DESC LIMIT 15"
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) { /* none yet */ }

$health = ($action === 'health' && $evo->isConfigured()) ? $evo->channelHealth() : null;

$blockers = 0;
foreach ($checks as $c) if (!$c['ok'] && empty($c['warn'])) $blockers++;

$webhookBase = 'https://<your-uisp-host>/crm/_plugins/dishnet-ai/evo_webhook.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DishNet AI</title>
<style>
  :root{--ink:#16201c;--muted:#5b6a63;--rule:#dce3de;--bg:#f5f7f5;--card:#fff;
        --ok:#0b6b5b;--okbg:#e2efeb;--warn:#a85b0b;--warnbg:#f7ebdc;--bad:#9e2f28;--badbg:#f8e6e4;}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
       font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:22px}
  h1{font-size:1.35rem;margin:0 0 3px}
  h2{font-size:1rem;margin:26px 0 10px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
  .sub{color:var(--muted);margin:0 0 20px}
  .card{background:var(--card);border:1px solid var(--rule);border-radius:4px;overflow:hidden}
  .row{display:flex;gap:14px;align-items:baseline;padding:10px 16px;border-bottom:1px solid var(--rule)}
  .row:last-child{border-bottom:none}
  .row .l{flex:0 0 240px;font-weight:500}
  .row .d{color:var(--muted);font-size:14px}
  .pill{font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
        padding:2px 8px;border-radius:3px;margin-left:auto;white-space:nowrap}
  .ok{background:var(--okbg);color:var(--ok)} .warn{background:var(--warnbg);color:var(--warn)}
  .bad{background:var(--badbg);color:var(--bad)}
  .banner{padding:14px 16px;border-radius:4px;margin-bottom:20px;border:1px solid var(--rule)}
  .banner.good{background:var(--okbg);border-color:var(--ok)}
  .banner.stop{background:var(--badbg);border-color:var(--bad)}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:9px 16px;background:#edf1ee;color:var(--muted);
     font-size:11px;letter-spacing:.08em;text-transform:uppercase}
  td{padding:9px 16px;border-top:1px solid var(--rule)}
  code{background:#edf1ee;border:1px solid var(--rule);padding:1px 5px;border-radius:3px;
       font-size:13px;word-break:break-all}
  .stats{display:flex;gap:2px;background:var(--rule);border:1px solid var(--rule);border-radius:4px;overflow:hidden}
  .stat{flex:1;background:var(--card);padding:13px 16px}
  .stat b{display:block;font-size:1.5rem;line-height:1.1}
  .stat span{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.07em}
  a.btn{display:inline-block;padding:7px 14px;border:1px solid var(--rule);border-radius:4px;
        background:var(--card);color:var(--ink);text-decoration:none;font-size:14px}
  .note{color:var(--muted);font-size:13.5px;margin-top:10px}
</style>

<h1>DishNet AI WhatsApp Platform</h1>
<p class="sub">Sales, Support and Accounts on one AI brain, grounded in live uCRM data.</p>

<?php if ($blockers === 0 && $aiOn): ?>
  <div class="banner good"><b>Running.</b> Incoming WhatsApp messages are being answered.</div>
<?php elseif ($blockers === 0): ?>
  <div class="banner"><b>Ready, but switched off.</b> Setup is complete. Turn on
  <em>Answer customers automatically</em> in the plugin settings when you want it live.</div>
<?php else: ?>
  <div class="banner stop"><b><?= $blockers ?> item<?= $blockers === 1 ? '' : 's' ?> still to fix.</b>
  Nothing is sent to customers until these pass.</div>
<?php endif; ?>

<h2>Setup</h2>
<div class="card">
<?php foreach ($checks as $c): ?>
  <div class="row">
    <span class="l"><?= h($c['label']) ?></span>
    <span class="d"><?= h($c['detail']) ?></span>
    <span class="pill <?= $c['ok'] ? 'ok' : ($c['warn'] ? 'warn' : 'bad') ?>">
      <?= $c['ok'] ? 'ok' : ($c['warn'] ? 'note' : 'fix') ?>
    </span>
  </div>
<?php endforeach; ?>
</div>

<h2>Queue</h2>
<div class="stats">
  <div class="stat"><b><?= (int)$queue['pending'] ?></b><span>Waiting</span></div>
  <div class="stat"><b><?= (int)$queue['failed'] ?></b><span>Retrying</span></div>
  <div class="stat"><b><?= (int)$queue['dead'] ?></b><span>Gave up</span></div>
  <div class="stat"><b><?= (int)$queue['done'] ?></b><span>Answered</span></div>
</div>
<?php if ((int)$queue['dead'] > 0): ?>
  <p class="note">Messages under <b>Gave up</b> were retried and never succeeded. Check
  <code>ai_platform.log</code> in the plugin data directory.</p>
<?php endif; ?>

<h2>WhatsApp numbers</h2>
<div class="card">
<?php if (!$channels): ?>
  <div class="row"><span class="d">No instances configured yet. Add them in the plugin settings.</span></div>
<?php else: foreach ($channels as $ch):
        $state = $health[$ch]['state'] ?? null; ?>
  <div class="row">
    <span class="l"><?= h(ucfirst($ch)) ?></span>
    <span class="d"><?= h($evo->instanceFor($ch)) ?><?= $state ? ' — ' . h($state) : '' ?></span>
    <?php if ($state !== null): ?>
      <span class="pill <?= $state === 'open' ? 'ok' : 'bad' ?>"><?= $state === 'open' ? 'connected' : 'down' ?></span>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>
</div>
<p class="note">
  <a class="btn" href="?action=health">Check connection status</a>
  &nbsp; Live check against Evolution API. Not run automatically — it makes one call per number.
</p>

<h2>Webhook</h2>
<div class="card"><div class="row" style="display:block">
  <div>Point each Evolution instance at this URL, appending your webhook secret:</div>
  <div style="margin-top:8px"><code><?= h($webhookBase) ?>?token=&lt;webhook secret&gt;</code></div>
  <div class="note">The secret is not shown here on purpose — copy it from the plugin settings.
  Subscribe to <code>MESSAGES_UPSERT</code>. The URL must be HTTPS: Evolution does not sign
  its webhooks, so that token is the only thing proving a request is genuine.</div>
</div></div>

<h2>Recent conversations</h2>
<div class="card">
<?php if (!$convs): ?>
  <div class="row"><span class="d">Nothing yet. Conversations appear here as messages arrive.</span></div>
<?php else: ?>
  <table>
    <tr><th>Customer</th><th>Number</th><th>Channel</th><th>State</th><th>Msgs</th><th>Last</th></tr>
    <?php foreach ($convs as $c): ?>
    <tr>
      <td><?= h($c['crm_client_name'] ?: ($c['display_name'] ?: 'Unknown')) ?></td>
      <td><?= h($c['phone']) ?></td>
      <td><?= h($c['channel']) ?></td>
      <td><?= h($c['state']) ?></td>
      <td><?= (int)$c['message_count'] ?></td>
      <td><?= h($c['last_message_at']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
</div>
