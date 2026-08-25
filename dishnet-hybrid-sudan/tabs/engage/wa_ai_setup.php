<?php
/**
 * WhatsApp AI setup — instances, QR pairing, webhooks, and the on/off switch.
 *
 * Lives inside the authenticated dashboard, so access control is whatever
 * brought the operator here. Secrets are never rendered; the webhook secret is
 * sent straight to Evolution by the register button so nobody handles it.
 *
 * Sudan edition.
 */

require_once dirname(__DIR__, 2) . '/lib/PluginConfig.php';
require_once dirname(__DIR__, 2) . '/lib/EvolutionApiService.php';
require_once dirname(__DIR__, 2) . '/lib/EvoWebhookGuard.php';

/**
 * Where Evolution should send messages.
 *
 * Deriving this from the request is unreliable: this page is normally loaded
 * inside the UISP admin iframe, so SCRIPT_NAME is not the plugin's own public
 * path, and UISP's public-URL layout differs between installs (/\_plugins/...
 * on one host, /crm/\_plugins/... on another). UISP prints the correct address
 * on the plugin's page in Settings, so we let the operator paste it and treat
 * that as authoritative. The derived value is only ever a suggestion.
 */
function wa_ai_public_base(array $cfg): string
{
    $saved = rtrim(trim((string)($cfg['plugin_public_url'] ?? '')), '/');
    if ($saved !== '') return $saved;

    // uCRM terminates TLS and proxies to the plugin over plain HTTP, so
    // $_SERVER['HTTPS'] is unset here even when the browser is on https.
    // Trust the forwarded header, and otherwise assume https -- an http guess
    // is always wrong for a uCRM install.
    $fwd    = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = $fwd !== '' ? $fwd
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https');
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '')
         . rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
}

/**
 * The address Evolution must POST to.
 *
 * uCRM does NOT serve arbitrary PHP files from a plugin directory -- only
 * public.php. Asking for evo_webhook.php directly returns uCRM's own "Page not
 * found", which is why webhook registration appeared to work and then nothing
 * ever arrived. The original Hybrid plugin solved this the same way and says so
 * in public.php: routes go through public.php?page=... instead.
 */
function wa_ai_webhook_url(array $cfg, string $secret): string
{
    return wa_ai_public_base($cfg)
         . '/public.php?page=evo_webhook&token=' . rawurlencode($secret);
}

$_wRoot = dirname(__DIR__, 2);
$_wData = $GLOBALS['dataDir'] ?? ($_wRoot . '/data');
$_wCfg  = PluginConfig::load($_wRoot, $_wData);
$_wEvo  = new EvolutionApiService($_wCfg);

$_wMsg = null;      // ['ok'=>bool,'text'=>string]
$_wQr  = null;      // ['channel','instance','qr','code']

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['wa_action'] ?? '') !== '') {
    if (function_exists('csrfCheck')) csrfCheck();
    $act = (string)$_POST['wa_action'];
    $ch  = (string)($_POST['channel'] ?? '');

    if ($act === 'save_channels') {
        $changes = [];
        foreach (EvolutionApiService::CHANNELS as $c) {
            $changes['evo_instance_' . $c] = trim((string)($_POST['instance_' . $c] ?? ''));
        }
        list($ok, $err) = PluginConfig::saveOverrides($_wData, $changes);
        $_wMsg = ['ok' => $ok, 'text' => $ok ? 'WhatsApp numbers saved.' : $err];
        $_wCfg = PluginConfig::load($_wRoot, $_wData);
        $_wEvo = new EvolutionApiService($_wCfg);

    } elseif ($act === 'toggle_ai') {
        $on = (string)($_POST['value'] ?? '') === '1';
        list($ok, $err) = PluginConfig::saveOverrides($_wData, ['ai_enabled' => $on]);
        $_wMsg = ['ok' => $ok, 'text' => $ok
            ? ($on ? 'Now answering customers on WhatsApp.' : 'Stopped. Messages are still received and stored.')
            : $err];
        $_wCfg = PluginConfig::load($_wRoot, $_wData);

    } elseif ($act === 'create_instance') {
        $name = trim((string)($_POST['instance_name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{3,40}$/', $name)) {
            $_wMsg = ['ok' => false, 'text' => 'Use 3-40 letters, numbers, dashes or underscores — no spaces.'];
        } else {
            $r = $_wEvo->createInstance($name);
            $_wMsg = ['ok' => $r['ok'], 'text' => $r['ok']
                ? 'Created "' . $name . '". Assign it to a number, then scan its QR code.'
                : 'Evolution refused: ' . $r['error']];
        }

    } elseif ($act === 'show_qr') {
        $inst = $_wEvo->instanceFor($ch);
        if ($inst === '') {
            $_wMsg = ['ok' => false, 'text' => 'Assign an instance to that number first.'];
        } else {
            $r = $_wEvo->connect($inst);
            if (!empty($r['ok']) && ($r['qr'] !== '' || $r['pairing_code'] !== '')) {
                $_wQr = ['channel' => $ch, 'instance' => $inst, 'qr' => $r['qr'], 'code' => $r['pairing_code']];
            } else {
                $_wMsg = ['ok' => false, 'text' => !empty($r['ok'])
                    ? 'Evolution returned no QR — this number may already be connected.'
                    : 'Evolution refused: ' . $r['error']];
            }
        }

    } elseif ($act === 'logout_instance') {
        $inst = $_wEvo->instanceFor($ch);
        $r = $inst !== '' ? $_wEvo->logoutInstance($inst) : ['ok' => false, 'error' => 'no instance'];
        $_wMsg = ['ok' => !empty($r['ok']), 'text' => !empty($r['ok'])
            ? 'Signed ' . $inst . ' out of WhatsApp.' : 'Evolution refused: ' . ($r['error'] ?? '')];

    } elseif ($act === 'assign_instance') {
        $inst = trim((string)($_POST['instance'] ?? ''));
        if ($ch === '' || !in_array($ch, EvolutionApiService::CHANNELS, true)) {
            $_wMsg = ['ok' => false, 'text' => 'Unknown channel.'];
        } elseif ($inst === '') {
            $_wMsg = ['ok' => false, 'text' => 'No instance given.'];
        } else {
            list($ok, $err) = PluginConfig::saveOverrides($_wData, ['evo_instance_' . $ch => $inst]);
            $_wMsg = ['ok' => $ok, 'text' => $ok
                ? $inst . ' is now the ' . $ch . ' number.'
                : $err];
            $_wCfg = PluginConfig::load($_wRoot, $_wData);
            $_wEvo = new EvolutionApiService($_wCfg);
        }

    } elseif ($act === 'save_public_url') {
        $u = trim((string)($_POST['public_url'] ?? ''));
        $u = rtrim($u, '/');
        if ($u !== '' && !preg_match('~^https?://~i', $u)) {
            $_wMsg = ['ok' => false, 'text' => 'Paste the full address, starting with https://'];
        } else {
            // Accept either the plugin folder or the public.php inside it.
            $u = preg_replace('~/public\.php$~i', '', $u);
            list($ok, $err) = PluginConfig::saveOverrides($_wData, ['plugin_public_url' => $u]);
            $_wMsg = ['ok' => $ok, 'text' => $ok
                ? ($u === '' ? 'Cleared — the address will be worked out from your browser again.'
                             : 'Saved. Register the webhook now.')
                : $err];
            $_wCfg = PluginConfig::load($_wRoot, $_wData);
        }

    } elseif ($act === 'register_webhook') {
        $inst   = $_wEvo->instanceFor($ch);
        $secret = PluginConfig::isSet_($_wCfg, 'evo_webhook_secret')
            ? (string)$_wCfg['evo_webhook_secret'] : EvoWebhookGuard::autoSecret($_wData);
        if ($inst === '') {
            $_wMsg = ['ok' => false, 'text' => 'No instance assigned to that number.'];
        } elseif ($secret === '') {
            $_wMsg = ['ok' => false, 'text' => 'Could not create a webhook secret — is the data directory writable?'];
        } else {
            $r = $_wEvo->setWebhook($inst, wa_ai_webhook_url($_wCfg, $secret));
            $_wMsg = ['ok' => $r['ok'], 'text' => $r['ok']
                ? 'Evolution will now send ' . $ch . ' messages to this plugin.'
                : 'Evolution refused: ' . $r['error']];
        }
    }
}

$_wLive = null; $_wErr = ''; $_wDetected = [];
if ($_wEvo->canReachApi()) {
    $r = $_wEvo->fetchInstances();
    if (!empty($r['ok']) && is_array($r['data'])) {
        $_wDetected = $_wEvo->listInstances();
        $_wLive = [];
        foreach ($_wDetected as $d) $_wLive[$d['name']] = $d['state'];
        ksort($_wLive);
    } else {
        $e = $_wEvo->getLastError();
        $_wErr = trim((string)($e['message'] ?? ($r['error'] ?? '')));
        if (!empty($e['http'])) $_wErr .= ' (HTTP ' . $e['http'] . ')';
    }
}
$_wHealth = $_wEvo->isConfigured() ? $_wEvo->channelHealth() : [];
$_wNoCreds = !$_wEvo->canReachApi();
$_wOn     = PluginConfig::toBool($_wCfg['ai_enabled'] ?? false);
$_csrf    = function_exists('csrfField') ? csrfField() : '';
?>
<style>
 .wa-card{background:#fff;border:1px solid #dce3de;border-radius:5px;margin-bottom:18px;overflow:hidden}
 .wa-card h3{margin:0;padding:9px 15px;background:#edf1ee;font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:#5b6a63}
 .wa-row{display:flex;gap:14px;align-items:center;padding:11px 15px;border-top:1px solid #dce3de;flex-wrap:wrap}
 .wa-row .n{flex:0 0 120px;font-weight:600}
 .wa-row .d{color:#5b6a63;font-size:13.5px}
 .wa-pill{margin-left:auto;font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:2px 8px;border-radius:3px}
 .wa-ok{background:#e2efeb;color:#0b6b5b}.wa-w{background:#f7ebdc;color:#a85b0b}.wa-b{background:#f8e6e4;color:#9e2f28}
 .wa-msg{padding:11px 15px;border-radius:5px;margin-bottom:16px;font-size:14px}
 .wa-good{background:#e2efeb;border:1px solid #0b6b5b;color:#0b6b5b}
 .wa-bad{background:#f8e6e4;border:1px solid #9e2f28;color:#9e2f28}
 .wa-note{color:#5b6a63;font-size:13px;padding:0 15px 12px}
 .wa-btn{padding:7px 13px;border:1px solid #dce3de;border-radius:4px;background:#fff;font:inherit;font-size:14px;cursor:pointer}
 .wa-btn.p{background:#0b6b5b;border-color:#0b6b5b;color:#fff}
 .wa-btn.d{background:#f8e6e4;border-color:#9e2f28;color:#9e2f28}
 .wa-card select,.wa-card input[type=text]{padding:7px 10px;border:1px solid #dce3de;border-radius:4px;font:inherit;font-size:14px;min-width:230px}
</style>

<h2 style="margin:0 0 4px;font-size:1.25rem">WhatsApp AI</h2>
<p style="color:#5b6a63;margin:0 0 18px;font-size:14px">
  Sales, Support and Accounts on one AI brain, answering from live uCRM data.</p>

<?php if ($_wMsg): ?>
  <div class="wa-msg <?= $_wMsg['ok'] ? 'wa-good' : 'wa-bad' ?>"><?= h($_wMsg['text']) ?></div>
<?php endif; ?>

<?php if ($_wQr): ?>
<div class="wa-card">
  <h3>Scan with the <?= h($_wQr['channel']) ?> phone</h3>
  <div style="padding:18px 15px;text-align:center">
    <div class="wa-note" style="padding:0 0 12px">
      WhatsApp &rarr; Settings &rarr; Linked devices &rarr; Link a device. Instance <b><?= h($_wQr['instance']) ?></b>.
    </div>
    <?php if ($_wQr['qr'] !== ''): ?>
      <img src="<?= h($_wQr['qr']) ?>" alt="WhatsApp pairing QR code"
           style="width:264px;height:264px;image-rendering:pixelated;border:1px solid #dce3de;border-radius:4px;background:#fff">
    <?php endif; ?>
    <?php if ($_wQr['code'] !== ''): ?>
      <div style="margin-top:10px">Or enter this code on the phone:
        <code style="font-size:16px;letter-spacing:.12em"><?= h($_wQr['code']) ?></code></div>
    <?php endif; ?>
    <div class="wa-note" style="padding:10px 0 0">Expires after about a minute — press
    <b>Show QR code</b> again for a fresh one.</div>
  </div>
</div>
<?php endif; ?>

<div class="wa-card">
  <h3>Answering</h3>
  <div class="wa-row">
    <span class="n">Status</span>
    <span class="d"><?= $_wOn ? 'ON — replies are being sent' : 'OFF — messages stored, nothing sent' ?></span>
    <form method="post" style="margin-left:auto"><?= $_csrf ?>
      <input type="hidden" name="wa_action" value="toggle_ai">
      <input type="hidden" name="value" value="<?= $_wOn ? '0' : '1' ?>">
      <button class="wa-btn <?= $_wOn ? 'd' : 'p' ?>" type="submit"><?= $_wOn ? 'Stop answering' : 'Start answering' ?></button>
    </form>
  </div>
</div>

<?php if ($_wDetected): ?>
<div class="wa-card">
  <h3>Found in Evolution</h3>
  <?php foreach ($_wDetected as $d):
        $assigned = '';
        foreach (EvolutionApiService::CHANNELS as $c) if ($_wEvo->instanceFor($c) === $d['name']) $assigned = $c; ?>
  <div class="wa-row">
    <span class="n"><?= h($d['name']) ?></span>
    <span class="d">
      <?= h($d['phone'] !== '' ? '+' . $d['phone'] : 'no number yet') ?>
      <?= $d['profile'] !== '' ? ' &middot; ' . h($d['profile']) : '' ?>
    </span>
    <span class="wa-pill <?= $d['connected'] ? 'wa-ok' : 'wa-w' ?>" style="margin-left:12px">
      <?= $d['connected'] ? 'connected' : h($d['state']) ?>
    </span>
    <span style="margin-left:auto">
      <?php if ($assigned !== ''): ?>
        <span class="wa-pill wa-ok">in use as <?= h($assigned) ?></span>
      <?php else: ?>
        <?php foreach (EvolutionApiService::CHANNELS as $c): ?>
          <form method="post" style="display:inline"><?= $_csrf ?>
            <input type="hidden" name="wa_action" value="assign_instance">
            <input type="hidden" name="instance" value="<?= h($d['name']) ?>">
            <input type="hidden" name="channel" value="<?= h($c) ?>">
            <button class="wa-btn <?= $c === 'sales' ? 'p' : '' ?>" type="submit">Use for <?= h($c) ?></button>
          </form>
        <?php endforeach; ?>
      <?php endif; ?>
    </span>
  </div>
  <?php endforeach; ?>
  <div class="wa-note">Detected live from Evolution. One click assigns a number &mdash; no typing.</div>
</div>
<?php endif; ?>

<form method="post"><?= $_csrf ?>
<input type="hidden" name="wa_action" value="save_channels">
<div class="wa-card">
  <h3>Numbers</h3>
  <?php foreach (EvolutionApiService::CHANNELS as $ch): $cur = $_wEvo->instanceFor($ch); ?>
  <div class="wa-row">
    <span class="n"><?= h(ucfirst($ch)) ?></span>
    <?php if ($_wLive !== null): ?>
      <select name="instance_<?= h($ch) ?>">
        <option value="">— not in use —</option>
        <?php foreach ($_wLive as $n => $st): ?>
          <option value="<?= h($n) ?>" <?= $n === $cur ? 'selected' : '' ?>><?= h($n) ?> (<?= h($st) ?>)</option>
        <?php endforeach; ?>
        <?php if ($cur !== '' && !isset($_wLive[$cur])): ?>
          <option value="<?= h($cur) ?>" selected><?= h($cur) ?> (not found in Evolution)</option>
        <?php endif; ?>
      </select>
    <?php else: ?>
      <input type="text" name="instance_<?= h($ch) ?>" value="<?= h($cur) ?>" placeholder="Evolution instance name">
    <?php endif; ?>
    <?php if ($cur !== '' && isset($_wHealth[$ch])): ?>
      <span class="wa-pill <?= $_wHealth[$ch]['connected'] ? 'wa-ok' : 'wa-w' ?>"><?= h($_wHealth[$ch]['state']) ?></span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <div class="wa-row"><button class="wa-btn p" type="submit">Save numbers</button></div>
  <?php if ($_wLive !== null && !$_wLive): ?>
    <div class="wa-note"><b>Connected to Evolution, but it has no instances yet.</b>
    Create one below, or in the Evolution manager, then reload this page.</div>
  <?php elseif ($_wLive !== null): ?>
    <div class="wa-note"><?= count($_wLive) ?> instance<?= count($_wLive) === 1 ? '' : 's' ?> found in Evolution.</div>
  <?php endif; ?>
  <?php if ($_wNoCreds): ?>
    <div class="wa-note"><b>Evolution API URL and key are not set.</b>
    Add them in UISP &rarr; Plugins &rarr; DishNet Sudan &rarr; the gear icon (Configuration),
    then reload this page and the instance list will appear here as a dropdown.</div>
  <?php elseif ($_wLive === null && $_wErr !== ''): ?>
    <div class="wa-note"><b>Evolution said:</b> <?= h($_wErr) ?>
      <?php if (stripos($_wErr,'certificate')!==false || stripos($_wErr,'SSL')!==false): ?>
        <br>That is a certificate problem, not a wrong key.
      <?php elseif (stripos($_wErr,'resolve')!==false): ?>
        <br>This server cannot resolve that hostname.
      <?php elseif (stripos($_wErr,'401')!==false): ?>
        <br>The API key was rejected.
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
</form>

<div class="wa-card">
  <h3>Plugin address</h3>
  <div class="wa-row" style="display:block">
    <div class="wa-note" style="padding:0 0 8px">
      Evolution has to be able to reach this plugin. Copy the <b>Public URL</b> shown on this
      plugin's page in UISP &rarr; Settings &rarr; Plugins, and paste it here. It differs between
      installs, and this page cannot work it out reliably from inside the UISP frame.
    </div>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><?= $_csrf ?>
      <input type="hidden" name="wa_action" value="save_public_url">
      <input type="text" name="public_url" style="min-width:460px"
             value="<?= h((string)($_wCfg['plugin_public_url'] ?? '')) ?>"
             placeholder="https://crm.dishnetsudan.com/_plugins/dishnet-hybrid-sudan">
      <button class="wa-btn p" type="submit">Save address</button>
    </form>
    <div class="wa-note" style="padding:8px 0 0">
      Currently sending Evolution to:<br>
      <code style="word-break:break-all"><?= h(wa_ai_public_base($_wCfg)) ?>/public.php?page=evo_webhook</code>
      <?php if (empty($_wCfg['plugin_public_url'])): ?>
        <br><b>That is a guess</b> from your browser address, and is very likely wrong while this
        page is open inside UISP. Paste the real one above.
      <?php endif; ?>
      <?php if (strpos(wa_ai_public_base($_wCfg), ':8443') !== false): ?>
        <br><b>Warning:</b> that address uses port 8443, which bypasses Traefik and serves UISP's
        self-signed certificate. Evolution will refuse it. Use the address without a port.
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="wa-card">
  <h3>Connect &amp; webhooks</h3>
  <?php $any = false; foreach (EvolutionApiService::CHANNELS as $ch):
        $inst = $_wEvo->instanceFor($ch); if ($inst === '') continue; $any = true;
        $connected = ($_wHealth[$ch]['connected'] ?? false); ?>
  <div class="wa-row">
    <span class="n"><?= h(ucfirst($ch)) ?></span>
    <span class="d"><?= h($inst) ?></span>
    <form method="post" style="margin-left:auto;display:flex;gap:8px"><?= $_csrf ?>
      <input type="hidden" name="channel" value="<?= h($ch) ?>">
      <?php if ($connected): ?>
        <button class="wa-btn d" type="submit" name="wa_action" value="logout_instance">Disconnect</button>
      <?php else: ?>
        <button class="wa-btn p" type="submit" name="wa_action" value="show_qr">Show QR code</button>
      <?php endif; ?>
      <button class="wa-btn" type="submit" name="wa_action" value="register_webhook">Register webhook</button>
    </form>
  </div>
  <?php endforeach; ?>
  <?php if (!$any): ?><div class="wa-row"><span class="d">Assign an instance to a number first.</span></div><?php endif; ?>
  <div class="wa-row">
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><?= $_csrf ?>
      <input type="hidden" name="wa_action" value="create_instance">
      <span style="font-weight:600">No instance yet?</span>
      <input type="text" name="instance_name" placeholder="dishnet_sales" pattern="[A-Za-z0-9_-]{3,40}">
      <button class="wa-btn" type="submit">Create it in Evolution</button>
    </form>
  </div>
  <div class="wa-note">Register webhook sends Evolution the address with the secret already in it,
  so nobody has to handle the secret.</div>
</div>
