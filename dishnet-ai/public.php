<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';

/**
 * public.php — the plugin's page inside UISP.
 *
 * uCRM does not authenticate this file — its documentation says a plugin's
 * public URL is reachable "without any authentication". So the page asks uCRM
 * who the visitor is: the browser sends uCRM's session cookies, and
 * /current-user turns those into an identity. No login, no password, nothing to
 * configure. An admin already signed into UISP just opens the page.
 *
 *   - Status tab: read-only, renders no secret, safe for anyone to reach.
 *   - Setup tab:  changes configuration and calls Evolution, so it requires a
 *                 signed-in uCRM staff user.
 *
 * If uCRM cannot be reached to answer that question, an optional token set on
 * the Configuration screen unlocks Setup as a fallback, so a network problem
 * cannot lock an operator out of their own plugin.
 *
 * Secrets are never editable here. They stay where uCRM protects them.
 */

require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/AdminGate.php';
require_once __DIR__ . '/lib/EvoWebhookGuard.php';
require_once __DIR__ . '/lib/UcrmUser.php';
require_once __DIR__ . '/lib/EvolutionApiService.php';
require_once __DIR__ . '/lib/DishNetTools.php';

$store  = SqliteStore::create($dataDir);
$config = PluginConfig::load(__DIR__, $dataDir);
$pdo    = $store->getPdo();
$gate   = new AdminGate($config, $dataDir);

// Before a single byte of output: session cookie parameters cannot be set once
// headers are sent, and csrfField() needs a session while rendering.
$gate->startSession();

$flash   = null;   // ['ok'=>bool, 'msg'=>string]
$qrPanel = null;   // set when a pairing QR has just been fetched

// ── Actions ──────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'unlock') {
        list($ok, $msg) = $gate->attemptUnlock((string)($_POST['token'] ?? ''));
        $flash = ['ok' => $ok, 'msg' => $ok ? 'Unlocked.' : $msg];

    } elseif ($action === 'lock') {
        $gate->lock();
        $flash = ['ok' => true, 'msg' => 'Locked.'];

    } elseif (!UcrmUser::isAdmin(__DIR__) && !$gate->isUnlocked()) {
        $flash = ['ok' => false, 'msg' => 'Sign in to UISP as staff to change settings.'];

    } elseif (!$gate->checkCsrf((string)($_POST['_csrf'] ?? ''))) {
        // A stale form after a session expiry lands here.
        $flash = ['ok' => false, 'msg' => 'This form expired. Reload the page and try again.'];

    } else {
        $evoW = new EvolutionApiService($config);

        switch ($action) {
            case 'save_channels':
                $changes = [];
                foreach (EvolutionApiService::CHANNELS as $ch) {
                    $changes['evo_instance_' . $ch] = trim((string)($_POST['instance_' . $ch] ?? ''));
                }
                list($ok, $err) = PluginConfig::saveOverrides($dataDir, $changes);
                $flash = ['ok' => $ok, 'msg' => $ok ? 'WhatsApp numbers saved.' : $err];
                break;

            case 'toggle_ai':
                $on = (string)($_POST['value'] ?? '') === '1';
                list($ok, $err) = PluginConfig::saveOverrides($dataDir, ['ai_enabled' => $on]);
                $flash = ['ok' => $ok, 'msg' => $ok
                    ? ($on ? 'Now answering customers.' : 'Stopped. Messages are still received and stored.')
                    : $err];
                break;

            case 'register_webhook':
                $ch  = (string)($_POST['channel'] ?? '');
                $inst = $evoW->instanceFor($ch);
                $secret = trim((string)($config['evo_webhook_secret'] ?? ''));
                if ($secret === '') $secret = EvoWebhookGuard::autoSecret($dataDir);
                if ($inst === '') {
                    $flash = ['ok' => false, 'msg' => 'No instance is assigned to that number yet.'];
                } elseif ($secret === '') {
                    $flash = ['ok' => false, 'msg' => 'Could not create a webhook secret — the plugin data directory is not writable.'];
                } else {
                    $url = webhookUrl($secret);
                    $r   = $evoW->setWebhook($inst, $url);
                    $flash = ['ok' => $r['ok'], 'msg' => $r['ok']
                        ? "Evolution will now send {$ch} messages to this plugin."
                        : ('Evolution refused: ' . $r['error'])];
                }
                break;

            case 'create_instance':
                $name = trim((string)($_POST['instance_name'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9_-]{3,40}$/', $name)) {
                    $flash = ['ok' => false, 'msg' => 'Use 3-40 letters, numbers, dashes or underscores — no spaces.'];
                } else {
                    $r = $evoW->createInstance($name);
                    $flash = ['ok' => $r['ok'], 'msg' => $r['ok']
                        ? "Created \"{$name}\". Assign it to a number below, then scan its QR code."
                        : ('Evolution refused: ' . $r['error'])];
                }
                break;

            case 'show_qr':
                $ch   = (string)($_POST['channel'] ?? '');
                $inst = $evoW->instanceFor($ch);
                if ($inst === '') {
                    $flash = ['ok' => false, 'msg' => 'Assign an instance to that number first.'];
                } else {
                    $r = $evoW->connect($inst);
                    if ($r['ok'] && ($r['qr'] !== '' || $r['pairing_code'] !== '')) {
                        $qrPanel = ['channel' => $ch, 'instance' => $inst,
                                    'qr' => $r['qr'], 'code' => $r['pairing_code']];
                    } else {
                        $flash = ['ok' => false, 'msg' => $r['ok']
                            ? 'Evolution returned no QR — this number may already be connected.'
                            : ('Evolution refused: ' . $r['error'])];
                    }
                }
                break;

            case 'logout_instance':
                $ch   = (string)($_POST['channel'] ?? '');
                $inst = $evoW->instanceFor($ch);
                if ($inst === '') {
                    $flash = ['ok' => false, 'msg' => 'No instance assigned to that number.'];
                } else {
                    $r = $evoW->logoutInstance($inst);
                    $flash = ['ok' => $r['ok'], 'msg' => $r['ok']
                        ? "Signed {$inst} out of WhatsApp."
                        : ('Evolution refused: ' . $r['error'])];
                }
                break;

            case 'probe_schema':
                $t = new DishNetTools($store, $config, __DIR__);
                $r = $t->describeProductSchema();
                if ($r['ok']) {
                    $gate->startSession();
                    $_SESSION['dishnet_ai_schema'] = $r['data'];
                    $flash = ['ok' => true, 'msg' => 'Read the plan and product fields from uCRM.'];
                } else {
                    $flash = ['ok' => false, 'msg' => (string)$r['error']];
                }
                break;
        }
    }
}

// Who is this? uCRM is the authority; the token is only a fallback for when
// uCRM cannot be asked.
$who          = UcrmUser::current(__DIR__);
$viaUcrm      = !empty($who['is_admin']);
$canUseToken  = empty($who['ok']) && $gate->isConfigured();
$unlocked     = $viaUcrm || ($canUseToken && $gate->isUnlocked());

$evo      = new EvolutionApiService($config);
$tools    = new DishNetTools($store, $config, __DIR__);
$tab      = ($_GET['tab'] ?? '') === 'setup' ? 'setup' : 'status';

/** The URL Evolution must call. Built from the request so it is always right. */
function webhookUrl(string $secret = ''): string
{
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? '');
    $dir    = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    $base   = $scheme . '://' . $host . $dir . '/evo_webhook.php';
    return $secret !== '' ? $base . '?token=' . rawurlencode($secret) : $base;
}

// ── Status ───────────────────────────────────────────────────────────────────
$checks = [];
$add = static function (string $label, bool $ok, string $detail, bool $warnOnly = false) use (&$checks): void {
    $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'warn' => $warnOnly];
};

$aiOn = PluginConfig::toBool($config['ai_enabled'] ?? false);
$add('Answering customers', $aiOn, $aiOn ? 'ON' : 'OFF — messages stored, nothing sent', !$aiOn);
$add('Evolution API URL', PluginConfig::isSet_($config, 'evo_api_url'),
     PluginConfig::isSet_($config, 'evo_api_url')
        ? (string)parse_url((string)$config['evo_api_url'], PHP_URL_HOST) : 'not set');
$add('Evolution API key', PluginConfig::isSet_($config, 'evo_api_key'),
     PluginConfig::isSet_($config, 'evo_api_key') ? 'set' : 'not set');
$whSecret = PluginConfig::isSet_($config, 'evo_webhook_secret')
    ? (string)$config['evo_webhook_secret']
    : EvoWebhookGuard::autoSecret($dataDir);
$add('Webhook secret', $whSecret !== '',
     $whSecret !== ''
        ? (PluginConfig::isSet_($config, 'evo_webhook_secret') ? 'set manually' : 'generated automatically')
        : 'could not be created — is the data directory writable?');

$provider = ($config['ai_provider'] ?? 'claude') === 'openai' ? 'openai' : 'claude';
$keyField = $provider === 'openai' ? 'openai_api_key' : 'claude_api_key';
$add('AI provider key', PluginConfig::isSet_($config, $keyField),
     PluginConfig::isSet_($config, $keyField) ? $provider . ' key set' : 'no key for ' . $provider);

$channels = $evo->configuredChannels();
$add('WhatsApp numbers', $channels !== [],
     $channels ? implode(', ', $channels) : 'none assigned yet');

$crmProbe = $tools->describeProductSchema();
$crmOk    = !empty($crmProbe['ok']);
$add('uCRM API', $crmOk, $crmOk ? 'reachable' : (string)($crmProbe['error'] ?? 'unreachable'));

$spawnOk = function_exists('exec')
    && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
$add('Instant replies', $spawnOk,
     $spawnOk ? 'available' : 'exec() blocked — replies arrive on the 1-minute schedule', true);

$queue = ['pending' => 0, 'failed' => 0, 'dead' => 0, 'done' => 0];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) c FROM events WHERE event_type='ai.reply' GROUP BY status")
                 ->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $queue[(string)$r['status']] = (int)$r['c'];
    }
} catch (\Throwable $e) {}

$convs = [];
try {
    $convs = $pdo->query(
        "SELECT phone, channel, display_name, crm_client_name, state, message_count, last_message_at
         FROM wa_conversations ORDER BY last_message_at DESC LIMIT 15"
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {}

// Live instance list — only when unlocked, and only on the Setup tab.
$liveInstances = null;
$health        = null;
$evoError      = '';
if ($unlocked && $tab === 'setup' && $evo->canReachApi()) {
    $r = $evo->fetchInstances();
    if (!$r['ok']) {
        $e = $evo->getLastError();
        $evoError = trim((string)($e['message'] ?? $r['error'] ?? ''));
        if (!empty($e['http'])) $evoError .= ' (HTTP ' . $e['http'] . ')';
    }
    if ($r['ok'] && is_array($r['data'])) {
        $liveInstances = [];
        foreach ($r['data'] as $i) {
            if (!is_array($i)) continue;
            $inner = $i['instance'] ?? $i;
            $name  = (string)($inner['name'] ?? ($inner['instanceName'] ?? ''));
            if ($name === '') continue;
            $liveInstances[$name] = (string)($inner['connectionStatus'] ?? ($inner['state'] ?? 'unknown'));
        }
        ksort($liveInstances);
    }
    $health = $evo->channelHealth();
}

$blockers = 0;
foreach ($checks as $c) if (!$c['ok'] && empty($c['warn'])) $blockers++;

$schemaReport = ($unlocked && session_status() === PHP_SESSION_ACTIVE)
    ? ($_SESSION['dishnet_ai_schema'] ?? null)
    : null;

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
  h2{font-size:.95rem;margin:26px 0 10px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
  .sub{color:var(--muted);margin:0 0 18px}
  .tabs{display:flex;gap:6px;border-bottom:1px solid var(--rule);margin-bottom:22px}
  .tabs a{padding:9px 16px;text-decoration:none;color:var(--muted);border-bottom:2px solid transparent;font-weight:500}
  .tabs a.on{color:var(--ink);border-bottom-color:var(--ok)}
  .card{background:var(--card);border:1px solid var(--rule);border-radius:4px;overflow:hidden}
  .row{display:flex;gap:14px;align-items:baseline;padding:10px 16px;border-bottom:1px solid var(--rule)}
  .row:last-child{border-bottom:none}
  .row .l{flex:0 0 220px;font-weight:500}
  .row .d{color:var(--muted);font-size:14px}
  .pill{font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
        padding:2px 8px;border-radius:3px;margin-left:auto;white-space:nowrap}
  .ok{background:var(--okbg);color:var(--ok)} .warn{background:var(--warnbg);color:var(--warn)}
  .bad{background:var(--badbg);color:var(--bad)}
  .banner{padding:13px 16px;border-radius:4px;margin-bottom:18px;border:1px solid var(--rule)}
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
  button,.btn{padding:7px 14px;border:1px solid var(--rule);border-radius:4px;background:var(--card);
              color:var(--ink);font:inherit;font-size:14px;cursor:pointer;text-decoration:none;display:inline-block}
  button:hover,.btn:hover{background:#edf1ee}
  button.primary{background:var(--ok);border-color:var(--ok);color:#fff}
  button.danger{background:var(--badbg);border-color:var(--bad);color:var(--bad)}
  input[type=text],input[type=password],select{padding:7px 10px;border:1px solid var(--rule);
       border-radius:4px;font:inherit;font-size:14px;min-width:260px;background:var(--card);color:var(--ink)}
  .note{color:var(--muted);font-size:13.5px;margin-top:9px}
  .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}
  form{margin:0}
  .lockbox{max-width:430px}
</style>

<h1>DishNet AI WhatsApp Platform</h1>
<p class="sub">Sales, Support and Accounts on one AI brain, answering from live uCRM data.</p>

<div class="tabs">
  <a href="?tab=status" class="<?= $tab === 'status' ? 'on' : '' ?>">Status</a>
  <a href="?tab=setup"  class="<?= $tab === 'setup'  ? 'on' : '' ?>">Setup</a>
</div>

<?php if ($flash): ?>
  <div class="banner <?= $flash['ok'] ? 'good' : 'stop' ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($tab === 'status'): ?>

  <?php if ($blockers === 0 && $aiOn): ?>
    <div class="banner good"><b>Running.</b> Incoming WhatsApp messages are being answered.</div>
  <?php elseif ($blockers === 0): ?>
    <div class="banner"><b>Ready, but switched off.</b> Turn it on from the Setup tab when you are ready.</div>
  <?php else: ?>
    <div class="banner stop"><b><?= $blockers ?> item<?= $blockers === 1 ? '' : 's' ?> still to fix.</b>
    Nothing is sent to customers until these pass.</div>
  <?php endif; ?>

  <h2>Setup checks</h2>
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
    <p class="note">Messages under <b>Gave up</b> were retried and never succeeded.
    Check <code>ai_platform.log</code> in the plugin data directory.</p>
  <?php endif; ?>

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

<?php else: /* ── SETUP ─────────────────────────────────────────────────── */ ?>

  <?php if (!$unlocked): ?>

    <?php if (!empty($who['ok']) && empty($who['authenticated'])): ?>
      <div class="banner stop">
        <b>Sign in to UISP first.</b>
        This page does not have its own login — it asks UISP who you are. Open it from the
        UISP menu while signed in as staff and Setup will be available.
      </div>

    <?php elseif (!empty($who['authenticated']) && empty($who['is_admin'])): ?>
      <div class="banner stop">
        <b>Staff access required.</b>
        You are signed in as a client. Setup is limited to UISP staff accounts.
      </div>

    <?php elseif ($canUseToken): ?>
      <div class="banner">
        <b>Could not reach UISP to confirm who you are.</b>
        <?= h($who['reason'] ?? '') ?> Use the fallback token instead.
      </div>
      <div class="card lockbox"><div class="row" style="display:block">
        <form method="post">
          <input type="hidden" name="action" value="unlock">
          <div style="font-weight:500;margin-bottom:8px">Unlock setup</div>
          <input type="password" name="token" placeholder="Fallback token" autocomplete="off" autofocus>
          <button class="primary" type="submit">Unlock</button>
          <div class="note">From <b>Setup tab fallback token</b> on the Configuration screen.
          Five wrong attempts locks this for 15 minutes.</div>
        </form>
      </div></div>

    <?php else: ?>
      <div class="banner stop">
        <b>Could not reach UISP to confirm who you are.</b>
        <?= h($who['reason'] ?? '') ?>
        <div class="note" style="color:inherit">
          Normally this page identifies you from your UISP session automatically. If that keeps
          failing, set a <b>Setup tab fallback token</b> on the plugin's Configuration screen
          and you can unlock it here instead.
        </div>
      </div>
    <?php endif; ?>

  <?php else: ?>

    <h2>Answering</h2>
    <div class="card"><div class="row">
      <span class="l">Answer customers automatically</span>
      <span class="d"><?= $aiOn ? 'ON — replies are being sent' : 'OFF — messages stored, nothing sent' ?></span>
      <form method="post" style="margin-left:auto">
        <?= $gate->csrfField() ?>
        <input type="hidden" name="action" value="toggle_ai">
        <input type="hidden" name="value" value="<?= $aiOn ? '0' : '1' ?>">
        <button class="<?= $aiOn ? 'danger' : 'primary' ?>" type="submit">
          <?= $aiOn ? 'Stop answering' : 'Start answering' ?>
        </button>
      </form>
    </div></div>

    <h2>WhatsApp numbers</h2>
    <form method="post">
      <?= $gate->csrfField() ?>
      <input type="hidden" name="action" value="save_channels">
      <div class="card">
      <?php foreach (EvolutionApiService::CHANNELS as $ch):
              $current = $evo->instanceFor($ch); ?>
        <div class="row">
          <span class="l"><?= h(ucfirst($ch)) ?></span>
          <?php if ($liveInstances !== null): ?>
            <select name="instance_<?= h($ch) ?>">
              <option value="">— not in use —</option>
              <?php foreach ($liveInstances as $name => $state): ?>
                <option value="<?= h($name) ?>" <?= $name === $current ? 'selected' : '' ?>>
                  <?= h($name) ?> (<?= h($state) ?>)
                </option>
              <?php endforeach; ?>
              <?php if ($current !== '' && !isset($liveInstances[$current])): ?>
                <option value="<?= h($current) ?>" selected><?= h($current) ?> (not found in Evolution)</option>
              <?php endif; ?>
            </select>
          <?php else: ?>
            <input type="text" name="instance_<?= h($ch) ?>" value="<?= h($current) ?>"
                   placeholder="Evolution instance name">
          <?php endif; ?>
          <?php if ($current !== '' && isset($health[$ch])): ?>
            <span class="pill <?= $health[$ch]['connected'] ? 'ok' : 'bad' ?>">
              <?= h($health[$ch]['state']) ?>
            </span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
      <div class="actions">
        <button class="primary" type="submit">Save numbers</button>
        <?php if ($liveInstances === null): ?>
          <span class="note" style="margin:0">
            Could not read the instance list from Evolution — type the names by hand.
            <?php if ($evoError !== ''): ?>
              <br><b>Evolution said:</b> <?= h($evoError) ?>
              <?php if (stripos($evoError, 'certificate') !== false || stripos($evoError, 'SSL') !== false): ?>
                <br>That is a certificate problem, not a wrong key.
              <?php elseif (stripos($evoError, 'resolve') !== false || stripos($evoError, 'Connection') !== false): ?>
                <br>uCRM cannot reach that address at all — check the URL, and that this server can resolve it.
              <?php elseif (stripos($evoError, '401') !== false || stripos($evoError, 'unauthor') !== false): ?>
                <br>The API key was rejected.
              <?php endif; ?>
            <?php endif; ?>
          </span>
        <?php else: ?>
          <span class="note" style="margin:0"><?= count($liveInstances) ?> instance<?= count($liveInstances) === 1 ? '' : 's' ?>
          found in Evolution.</span>
        <?php endif; ?>
      </div>
    </form>

    <h2>Connect WhatsApp</h2>
    <?php if ($qrPanel): ?>
      <div class="card"><div class="row" style="display:block;text-align:center">
        <div style="font-weight:600;margin-bottom:4px">
          Scan with the <?= h(ucfirst($qrPanel['channel'])) ?> phone
        </div>
        <div class="note" style="margin:0 0 12px">
          WhatsApp → Settings → Linked devices → Link a device.
          Instance <b><?= h($qrPanel['instance']) ?></b>.
        </div>
        <?php if ($qrPanel['qr'] !== ''): ?>
          <img src="<?= h($qrPanel['qr']) ?>" alt="WhatsApp pairing QR code"
               style="width:264px;height:264px;image-rendering:pixelated;border:1px solid var(--rule);border-radius:4px;background:#fff">
        <?php endif; ?>
        <?php if ($qrPanel['code'] !== ''): ?>
          <div style="margin-top:10px">Or enter this code on the phone:
            <code style="font-size:16px;letter-spacing:.12em"><?= h($qrPanel['code']) ?></code></div>
        <?php endif; ?>
        <div class="note">The code expires after about a minute. If it stops working, press
        <b>Show QR code</b> again for a fresh one.</div>
      </div></div>
    <?php endif; ?>

    <div class="card">
      <?php $anyAssigned = false;
            foreach (EvolutionApiService::CHANNELS as $ch):
              $inst = $evo->instanceFor($ch); if ($inst === '') continue; $anyAssigned = true;
              $state = $health[$ch]['state'] ?? null;
              $connected = ($state === 'open'); ?>
        <div class="row">
          <span class="l"><?= h(ucfirst($ch)) ?></span>
          <span class="d"><?= h($inst) ?><?= $state ? ' — ' . h($state) : '' ?></span>
          <form method="post" style="margin-left:auto;display:flex;gap:8px">
            <?= $gate->csrfField() ?>
            <input type="hidden" name="channel" value="<?= h($ch) ?>">
            <?php if ($connected): ?>
              <button type="submit" name="action" value="logout_instance" class="danger">Disconnect</button>
            <?php else: ?>
              <button type="submit" name="action" value="show_qr" class="primary">Show QR code</button>
            <?php endif; ?>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (!$anyAssigned): ?>
        <div class="row"><span class="d">Assign an instance to a number above first.</span></div>
      <?php endif; ?>

      <div class="row" style="display:block">
        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?= $gate->csrfField() ?>
          <input type="hidden" name="action" value="create_instance">
          <span style="font-weight:500">No instance yet?</span>
          <input type="text" name="instance_name" placeholder="dishnet_sales" pattern="[A-Za-z0-9_-]{3,40}">
          <button type="submit">Create it in Evolution</button>
        </form>
        <div class="note">Creates a new WhatsApp connection. Assign it to a number above, then
        scan its QR code with the phone that owns that number.</div>
      </div>
    </div>

    <h2>Webhook</h2>
    <div class="card">
      <div class="row" style="display:block">
        <div>Evolution must send messages to:</div>
        <div style="margin-top:7px"><code><?= h(webhookUrl()) ?>?token=&lt;webhook secret&gt;</code></div>
        <div class="note">The secret is not shown here. The buttons below send the real URL to
        Evolution for you, so you never need to handle it.</div>
      </div>
      <?php foreach (EvolutionApiService::CHANNELS as $ch):
              $inst = $evo->instanceFor($ch); if ($inst === '') continue; ?>
        <div class="row">
          <span class="l"><?= h(ucfirst($ch)) ?></span>
          <span class="d"><?= h($inst) ?></span>
          <form method="post" style="margin-left:auto">
            <?= $gate->csrfField() ?>
            <input type="hidden" name="action" value="register_webhook">
            <input type="hidden" name="channel" value="<?= h($ch) ?>">
            <button type="submit">Register webhook</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>

    <h2>uCRM data</h2>
    <div class="card"><div class="row" style="display:block">
      <div>Check what your uCRM actually returns for plans and products, so the AI quotes real
      fields rather than assumed ones.</div>
      <div class="actions">
        <form method="post">
          <?= $gate->csrfField() ?>
          <input type="hidden" name="action" value="probe_schema">
          <button type="submit">Read plan &amp; product fields</button>
        </form>
      </div>
      <?php if (is_array($schemaReport)): ?>
        <div style="margin-top:14px">
        <?php foreach ($schemaReport as $resource => $info): ?>
          <div style="margin-bottom:9px">
            <b><?= h($resource) ?></b>
            <?php if (empty($info['reachable'])): ?>
              <span class="pill bad">unreachable</span>
              <div class="note"><?= h($info['error'] ?? '') ?></div>
            <?php else: ?>
              <span class="note">— <?= (int)($info['rows'] ?? 0) ?> row(s)</span>
              <div class="note"><code><?= h(implode(', ', array_keys($info['keys'] ?? []))) ?: 'no fields returned' ?></code></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="note">Send this list to your developer to finish the product mapping.</div>
        </div>
      <?php endif; ?>
    </div></div>

    <div class="actions" style="margin-top:24px">
      <?php if ($viaUcrm): ?>
        <span class="note" style="margin:0">
          Signed in as <b><?= h($who['username'] ?: 'UISP staff') ?></b> via UISP.
          Secrets — API keys and the webhook secret — are only editable on the Configuration
          screen, behind your UISP login.
        </span>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="action" value="lock">
          <button type="submit">Lock setup</button>
        </form>
        <span class="note" style="margin:0">Unlocked with the fallback token. Secrets are only
        editable on the Configuration screen.</span>
      <?php endif; ?>
    </div>

  <?php endif; ?>
<?php endif; ?>
