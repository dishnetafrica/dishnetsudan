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
require_once dirname(__DIR__, 2) . '/lib/DishNetAiBrain.php';

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

$_wMsg    = null;   // ['ok'=>bool,'text'=>string]
$_wAiTest = null;   // result of an isolated AI test
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

    } elseif ($act === 'save_ai') {
        list($ok, $err) = PluginConfig::saveAiSettings(
            $_wData,
            (string)($_POST['ai_provider'] ?? 'claude'),
            (string)($_POST['ai_api_key'] ?? ''),
            (string)($_POST['bot_custom_instructions'] ?? '')
        );
        $_wMsg = ['ok' => $ok, 'text' => $ok ? 'AI settings saved.' : $err];
        $_wCfg = PluginConfig::load($_wRoot, $_wData);

    } elseif ($act === 'save_alerts') {
        list($ok, $err) = PluginConfig::saveOverrides($_wData, [
            'alert_whatsapp'         => trim((string)($_POST['alert_whatsapp'] ?? '')),
            'alert_hours_from'       => trim((string)($_POST['alert_hours_from'] ?? '')),
            'alert_hours_to'         => trim((string)($_POST['alert_hours_to'] ?? '')),
            'alert_patience_minutes' => trim((string)($_POST['alert_patience_minutes'] ?? '')),
        ]);
        $_wMsg = ['ok' => $ok, 'text' => $ok ? 'Alert settings saved.' : $err];
        $_wCfg = PluginConfig::load($_wRoot, $_wData);

    } elseif ($act === 'save_stock') {
        list($ok, $err) = PluginConfig::saveOverrides($_wData,
            ['stock_statement' => trim((string)($_POST['stock_statement'] ?? ''))]);
        $_wMsg = ['ok' => $ok, 'text' => $ok ? 'Saved.' : $err];
        $_wCfg = PluginConfig::load($_wRoot, $_wData);

    } elseif ($act === 'save_handover') {
        list($ok, $err) = PluginConfig::saveOverrides($_wData,
            ['wa_human_cooldown_minutes' => trim((string)($_POST['wa_human_cooldown_minutes'] ?? ''))]);
        $_wMsg = ['ok' => $ok, 'text' => $ok ? 'Saved.' : $err];
        $_wCfg = PluginConfig::load($_wRoot, $_wData);

    } elseif ($act === 'save_currency') {
        list($ok, $err) = PluginConfig::saveOverrides($_wData,
            ['ai_currency' => trim((string)($_POST['ai_currency'] ?? ''))]);
        $_wMsg = ['ok' => $ok, 'text' => $ok ? 'Currency saved.' : $err];
        $_wCfg = PluginConfig::load($_wRoot, $_wData);

    } elseif ($act === 'forget_lead') {
        // A deletion request must not require someone to open SQLite. Removes
        // the contact details AND the conversation, because keeping a
        // transcript that names the person defeats the purpose.
        require_once $_wRoot . '/lib/WebChatGuard.php';
        require_once $_wRoot . '/lib/StoreInterface.php';
        require_once $_wRoot . '/lib/SqliteStore.php';
        $sess = trim((string)($_POST['session'] ?? ''));
        try {
            $st = $GLOBALS['store'] ?? SqliteStore::create($_wData);
            $r  = (new WebChatGuard($st, $_wCfg))->forget($sess);
            $done = array_keys(array_filter($r));
            $_wMsg = ['ok' => (bool)$done, 'text' => $done
                ? 'Deleted: ' . implode(' and ', $done) . '.'
                : 'Nothing found for that visitor.'];
            @file_put_contents($_wData . '/ai_platform.log', sprintf(
                "[%s] web_chat: operator erased visitor %s (lead=%s, transcript=%s)\n",
                gmdate('c'), substr($sess, 0, 8) . '…',
                $r['lead'] ? 'yes' : 'no', $r['session'] ? 'yes' : 'no'), FILE_APPEND);
        } catch (\Throwable $e) {
            $_wMsg = ['ok' => false, 'text' => 'Could not delete: ' . $e->getMessage()];
        }

    } elseif ($act === 'save_web_chat') {
        // None of these are secrets, so they go through the ordinary override
        // path. The provider key is shared with WhatsApp and is set above.
        $changes = [
            'web_chat_enabled'       => (($_POST['web_chat_enabled'] ?? '') === '1'),
            'web_chat_whatsapp'      => trim((string)($_POST['web_chat_whatsapp'] ?? '')),
            'web_chat_origins'       => trim((string)($_POST['web_chat_origins'] ?? '')),
            'web_chat_daily_max'     => trim((string)($_POST['web_chat_daily_max'] ?? '')),
            'web_chat_session_max'   => trim((string)($_POST['web_chat_session_max'] ?? '')),
            'web_chat_ip_max'        => trim((string)($_POST['web_chat_ip_max'] ?? '')),
            'web_chat_monthly_usd'   => trim((string)($_POST['web_chat_monthly_usd'] ?? '')),
            'web_chat_usd_per_1m_in' => trim((string)($_POST['web_chat_usd_per_1m_in'] ?? '')),
            'web_chat_usd_per_1m_out'=> trim((string)($_POST['web_chat_usd_per_1m_out'] ?? '')),
            'web_chat_lead_mode'     => trim((string)($_POST['web_chat_lead_mode'] ?? 'after')),
            'web_chat_teaser'        => trim((string)($_POST['web_chat_teaser'] ?? '')),
            'web_chat_teaser_delay'  => trim((string)($_POST['web_chat_teaser_delay'] ?? '')),
            'web_chat_retention_days'=> trim((string)($_POST['web_chat_retention_days'] ?? '')),
        ];
        list($ok, $err) = PluginConfig::saveOverrides($_wData, $changes);
        $_wMsg = ['ok' => $ok, 'text' => $ok ? 'Website chat settings saved.' : $err];
        $_wCfg = PluginConfig::load($_wRoot, $_wData);

    } elseif ($act === 'test_ai') {
        // Isolation test: the brain only, with no WhatsApp involved. If this
        // works and a real message does not, the fault is in the pipeline
        // rather than the AI.
        $brain = new DishNetAiBrain($_wCfg);
        if (!$brain->isConfigured()) {
            $_wMsg = ['ok' => false, 'text' => 'No API key set for the selected provider.'];
        } else {
            $res = $brain->reply([
                'channel'         => 'sales',
                'customer_phone'  => '000000000000',
                'message'         => 'Hello',
                'conversation_id' => 0,
                'history'         => [],
            ]);
            $_wAiTest = $res;
            $usage    = $brain->getLastUsage();
            $_wMsg = ['ok' => trim((string)$res['reply']) !== '',
                      'text' => trim((string)$res['reply']) !== ''
                        ? 'AI replied' . ($usage ? sprintf(' (%d in / %d out tokens, %s)',
                             $usage['input_tokens'] ?? 0, $usage['output_tokens'] ?? 0, $usage['model'] ?? '?') : '')
                        : 'AI produced no reply: ' . (string)$res['escalate_reason']];
        }

    } elseif ($act === 'save_connection') {
        list($ok, $err) = PluginConfig::saveEvolutionCredentials(
            $_wData,
            (string)($_POST['evo_api_url'] ?? ''),
            (string)($_POST['evo_api_key'] ?? '')
        );
        $_wMsg = ['ok' => $ok, 'text' => $ok ? 'Evolution connection saved.' : $err];
        $_wCfg = PluginConfig::load($_wRoot, $_wData);
        $_wEvo = new EvolutionApiService($_wCfg);

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

<div class="wa-card">
  <h3>Evolution connection</h3>
  <form method="post"><?= $_csrf ?>
    <input type="hidden" name="wa_action" value="save_connection">
    <div class="wa-row">
      <span class="n">API URL</span>
      <input type="text" name="evo_api_url" style="min-width:420px"
             value="<?= h((string)($_wCfg['evo_api_url'] ?? '')) ?>"
             placeholder="https://evo-evolution-api.xxxx.easypanel.host">
    </div>
    <div class="wa-row">
      <span class="n">API key</span>
      <input type="password" name="evo_api_key" style="min-width:420px" autocomplete="off"
             placeholder="<?= PluginConfig::isSet_($_wCfg,'evo_api_key') ? 'stored — leave blank to keep it' : 'paste your Evolution API key' ?>">
      <span class="wa-pill <?= PluginConfig::isSet_($_wCfg,'evo_api_key') ? 'wa-ok' : 'wa-b' ?>">
        <?= PluginConfig::isSet_($_wCfg,'evo_api_key') ? 'set' : 'not set' ?>
      </span>
    </div>
    <div class="wa-row"><button class="wa-btn p" type="submit">Save connection</button></div>
  </form>
  <div class="wa-note">This is the only place these are set. The old
  <b>Settings &rarr; Evolution API</b> section no longer saves them &mdash; both screens wrote the
  same keys, so saving there could overwrite a working setup.</div>
</div>

<?php
  $_aiProv = ($_wCfg['ai_provider'] ?? 'claude') === 'openai' ? 'openai' : 'claude';
  $_aiKeyF = $_aiProv === 'openai' ? 'openai_api_key' : 'claude_api_key';
  $_aiSet  = PluginConfig::isSet_($_wCfg, $_aiKeyF);
?>
<div class="wa-card">
  <h3>AI</h3>
  <form method="post"><?= $_csrf ?>
    <input type="hidden" name="wa_action" value="save_ai">
    <div class="wa-row">
      <span class="n">Provider</span>
      <select name="ai_provider">
        <option value="claude" <?= $_aiProv === 'claude' ? 'selected' : '' ?>>Anthropic Claude</option>
        <option value="openai" <?= $_aiProv === 'openai' ? 'selected' : '' ?>>OpenAI</option>
      </select>
    </div>
    <div class="wa-row">
      <span class="n">API key</span>
      <input type="password" name="ai_api_key" style="min-width:420px" autocomplete="off"
             placeholder="<?= $_aiSet ? 'stored — leave blank to keep it' : 'paste your ' . h($_aiProv) . ' key' ?>">
      <span class="wa-pill <?= $_aiSet ? 'wa-ok' : 'wa-b' ?>"><?= $_aiSet ? 'set' : 'not set' ?></span>
    </div>
    <div class="wa-row" style="display:block">
      <span class="n" style="display:block;margin-bottom:6px">Extra instructions</span>
      <textarea name="bot_custom_instructions" rows="3" style="width:100%;padding:8px 10px;border:1px solid #dce3de;border-radius:4px;font:inherit;font-size:14px"
        placeholder="Office hours, payment methods, locations. Cannot override the rules that stop the AI inventing prices."><?= h((string)($_wCfg['bot_custom_instructions'] ?? '')) ?></textarea>
    </div>
    <div class="wa-row"><button class="wa-btn p" type="submit">Save AI settings</button></div>
  </form>
  <form method="post"><?= $_csrf ?>
    <input type="hidden" name="wa_action" value="save_alerts">
    <div class="wa-row">
      <span class="n">Alert a human on</span>
      <input type="text" name="alert_whatsapp" style="min-width:200px" placeholder="+249XXXXXXXXX"
             value="<?= h((string)($_wCfg['alert_whatsapp'] ?? '')) ?>">
      <label style="font-size:13px">hours
        <input type="number" min="0" max="23" name="alert_hours_from" style="width:60px"
               placeholder="7" value="<?= h((string)($_wCfg['alert_hours_from'] ?? '')) ?>">&ndash;<input
               type="number" min="1" max="24" name="alert_hours_to" style="width:60px"
               placeholder="21" value="<?= h((string)($_wCfg['alert_hours_to'] ?? '')) ?>"></label>
      <label style="font-size:13px">patience
        <input type="number" min="2" max="240" name="alert_patience_minutes" style="width:65px"
               placeholder="10" value="<?= h((string)($_wCfg['alert_patience_minutes'] ?? '')) ?>">min</label>
      <button class="wa-btn p" type="submit">Save</button>
    </div>
    <div class="wa-row">
      <span class="n"></span>
      <span style="color:#5a6b60;font-size:12px;max-width:75ch">
        This number gets a WhatsApp message when the AI hands a conversation to a human, and
        when any customer &mdash; website or WhatsApp &mdash; has waited longer than the patience
        window with no reply, checked every 15 minutes inside the hours above (Sudan time).
        <strong>Use a personal number, not one connected to the bot</strong> &mdash; alerts sent
        to a bot-connected number would be answered by the bot. Blank switches alerts off, and
        the preflight will keep reminding you.
      </span>
    </div>
  </form>

  <form method="post"><?= $_csrf ?>
    <input type="hidden" name="wa_action" value="save_stock">
    <div class="wa-row" style="display:block">
      <span class="n" style="display:block;margin-bottom:6px">Stock &amp; availability</span>
      <input type="text" name="stock_statement" style="width:100%"
             placeholder="Both Starlink kits are in stock."
             value="<?= h((string)($_wCfg['stock_statement'] ?? '')) ?>">
      <div style="color:#5a6b60;font-size:12px;margin-top:4px;max-width:75ch">
        What the AI may say when someone asks if a kit is available. Written here, it is your
        statement rather than a guess &mdash; and it is one field to change the day it stops
        being true. <strong>Leave it blank and the AI says it will check</strong>, which is what
        it does now. It will not invent quantities or delivery dates either way, so keep this to
        availability only.
      </div>
      <div style="margin-top:8px"><button class="wa-btn p" type="submit">Save availability</button></div>
    </div>
  </form>

  <form method="post"><?= $_csrf ?>
    <input type="hidden" name="wa_action" value="save_handover">
    <div class="wa-row">
      <span class="n">After a colleague replies</span>
      <?php $_cd = (string)($_wCfg['wa_human_cooldown_minutes'] ?? ''); ?>
      <input type="number" min="0" max="10080" name="wa_human_cooldown_minutes" style="width:90px"
             placeholder="1440" value="<?= h($_cd) ?>">
      <span style="font-size:13px">minutes of silence</span>
      <button class="wa-btn" type="submit">Save</button>
    </div>
    <div class="wa-row">
      <span class="n"></span>
      <span style="color:#5a6b60;font-size:12px;max-width:70ch">
        Two answers to one question &mdash; one from a person, one from the AI, at the same
        moment &mdash; is worse than a slow answer, so the AI pauses after a colleague replies.
        Default 1440 (24 hours), which is far longer than anyone is still typing.
        <strong>0 means the AI never stands down</strong>: it reads what the colleague wrote,
        keeps any promise in it, and carries on. Whatever you choose, the colleague&rsquo;s
        message is always part of what the AI sees.
      </span>
    </div>
  </form>

  <form method="post"><?= $_csrf ?>
    <input type="hidden" name="wa_action" value="save_currency">
    <div class="wa-row">
      <span class="n">Currency</span>
      <input type="text" name="ai_currency" style="width:110px" placeholder="e.g. USD"
             value="<?= h((string)($_wCfg['ai_currency'] ?? '')) ?>">
      <button class="wa-btn" type="submit">Save</button>
      <span style="color:#5a6b60;font-size:12px;max-width:60ch">
        uCRM does not tell us what its prices are denominated in. Left blank the AI gives the
        number and names no currency; fill it in and every price is quoted with it. The website
        quotes $, so leaving this blank means the two do not match.
      </span>
    </div>
  </form>
  <div class="wa-row">
    <span class="n">Test</span>
    <span class="d">Ask the AI "Hello" directly, with no WhatsApp involved.</span>
    <form method="post" style="margin-left:auto"><?= $_csrf ?>
      <input type="hidden" name="wa_action" value="test_ai">
      <button class="wa-btn" type="submit">Test AI now</button>
    </form>
  </div>
  <?php if ($_wAiTest !== null): ?>
    <div class="wa-row" style="display:block;background:#f8fbfa">
      <div class="wa-note" style="padding:0 0 6px"><b>AI replied:</b></div>
      <div style="white-space:pre-wrap;font-size:14px"><?= h((string)$_wAiTest['reply']) ?: '<em>(nothing)</em>' ?></div>
      <?php if (!empty($_wAiTest['escalate'])): ?>
        <div class="wa-note" style="padding:6px 0 0">Handover requested: <?= h((string)$_wAiTest['escalate_reason']) ?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php
  require_once $_wRoot . '/lib/WebChatGuard.php';
  // This tab has no store of its own; public.php built one before rendering.
  // Fall back to opening the same database rather than showing no counters.
  $_wcStore = $GLOBALS['store'] ?? null;
  if (!$_wcStore) {
      require_once $_wRoot . '/lib/StoreInterface.php';
      require_once $_wRoot . '/lib/SqliteStore.php';
      try { $_wcStore = SqliteStore::create($_wData); } catch (\Throwable $e) { $_wcStore = null; }
  }
  $_wcGuard = new WebChatGuard($_wcStore, $_wCfg);
  $_wcStats = $_wcStore ? $_wcGuard->stats() : null;
  $_wcOn    = PluginConfig::toBool($_wCfg['web_chat_enabled'] ?? false);
?>
<div class="wa-card">
  <h3>Website chat</h3>
  <p style="margin:0 0 12px;color:#5a6b60;font-size:13px;max-width:70ch">
    The same assistant, answering on dishnetsudan.com for visitors who have not
    opened WhatsApp. It uses the same uCRM catalogue, so the two cannot quote
    different prices. A website visitor is anonymous, so it never sees or
    discusses account details &mdash; those go to the portal or WhatsApp.
  </p>
  <?php if ($_wcStats): ?>
  <div class="wa-row">
    <span class="n">This month</span>
    <span><?= (int)$_wcStats['month'] ?> message(s),
      <?= number_format((int)$_wcStats['tokens_in_month'] + (int)$_wcStats['tokens_out_month']) ?> tokens<?php
      if ($_wcStats['spent_month_usd'] !== null): ?>,
        $<?= number_format((float)$_wcStats['spent_month_usd'], 2) ?> of
        $<?= number_format((float)$_wcStats['budget_usd'], 2) ?><?php endif; ?>.
      <?= (int)$_wcStats['today'] ?> today.</span>
  </div>
  <?php endif; ?>
  <form method="post"><?= $_csrf ?>
    <input type="hidden" name="wa_action" value="save_web_chat">
    <div class="wa-row">
      <span class="n">Chat on the website</span>
      <label><input type="checkbox" name="web_chat_enabled" value="1" <?= $_wcOn ? 'checked' : '' ?>>
        enabled</label>
      <span class="wa-pill <?= $_wcOn ? 'wa-ok' : 'wa-b' ?>"><?= $_wcOn ? 'on' : 'off' ?></span>
    </div>
    <div class="wa-row">
      <span class="n">Hand off to</span>
      <input type="text" name="web_chat_whatsapp" style="min-width:220px"
             placeholder="+249900083481"
             value="<?= h((string)($_wCfg['web_chat_whatsapp'] ?? '')) ?>">
      <span style="color:#5a6b60;font-size:12px">the number the chat sends buyers to</span>
    </div>
    <div class="wa-row" style="display:block">
      <span class="n" style="display:block;margin-bottom:6px">Allowed websites</span>
      <input type="text" name="web_chat_origins" style="width:100%"
             placeholder="https://dishnetsudan.com,https://www.dishnetsudan.com"
             value="<?= h((string)($_wCfg['web_chat_origins'] ?? '')) ?>">
      <span style="color:#5a6b60;font-size:12px">Comma separated. Only these pages may use the
        assistant &mdash; this is what stops another site spending your budget.</span>
    </div>
    <div class="wa-row">
      <span class="n">Limits</span>
      <label style="font-size:13px">per IP / 10 min
        <input type="number" min="0" name="web_chat_ip_max" style="width:80px"
               placeholder="8" value="<?= h((string)($_wCfg['web_chat_ip_max'] ?? '')) ?>"></label>
      <label style="font-size:13px">per visitor / day
        <input type="number" min="0" name="web_chat_session_max" style="width:80px"
               placeholder="30" value="<?= h((string)($_wCfg['web_chat_session_max'] ?? '')) ?>"></label>
      <label style="font-size:13px">whole site / day
        <input type="number" min="0" name="web_chat_daily_max" style="width:90px"
               placeholder="600" value="<?= h((string)($_wCfg['web_chat_daily_max'] ?? '')) ?>"></label>
    </div>
    <div class="wa-row">
      <span class="n">Monthly budget</span>
      <label style="font-size:13px">$
        <input type="number" min="0" step="0.01" name="web_chat_monthly_usd" style="width:100px"
               placeholder="25" value="<?= h((string)($_wCfg['web_chat_monthly_usd'] ?? '')) ?>"></label>
      <label style="font-size:13px">$/1M in
        <input type="number" min="0" step="0.01" name="web_chat_usd_per_1m_in" style="width:90px"
               value="<?= h((string)($_wCfg['web_chat_usd_per_1m_in'] ?? '')) ?>"></label>
      <label style="font-size:13px">$/1M out
        <input type="number" min="0" step="0.01" name="web_chat_usd_per_1m_out" style="width:90px"
               value="<?= h((string)($_wCfg['web_chat_usd_per_1m_out'] ?? '')) ?>"></label>
    </div>
    <div class="wa-row">
      <span class="n"></span>
      <span style="color:#5a6b60;font-size:12px;max-width:70ch">
        The budget can only be enforced once both token rates are filled in from your
        provider's pricing page &mdash; we will not guess them.
        <?= $_wcGuard->ratesConfigured()
              ? 'Rates are set, so the budget is enforced.'
              : 'Until then the three message limits above are the ceiling.' ?>
      </span>
    </div>
    <div class="wa-row">
      <span class="n">Ask for contact details</span>
      <?php $_lm = (string)($_wCfg['web_chat_lead_mode'] ?? 'after'); ?>
      <select name="web_chat_lead_mode">
        <option value="after"  <?= $_lm === 'after'  ? 'selected' : '' ?>>After the first answer (recommended)</option>
        <option value="before" <?= $_lm === 'before' ? 'selected' : '' ?>>Before the chat starts</option>
        <option value="off"    <?= $_lm === 'off'    ? 'selected' : '' ?>>Never ask</option>
      </select>
    </div>
    <div class="wa-row">
      <span class="n"></span>
      <span style="color:#5a6b60;font-size:12px;max-width:70ch">
        Asking first captures more numbers per conversation but starts fewer conversations:
        a visitor who will not give a number before asking anything is exactly the one this
        channel exists to keep. Either way the visitor can skip it.
      </span>
    </div>
    <div class="wa-row">
      <span class="n">Pop-up message</span>
      <input type="text" name="web_chat_teaser" style="min-width:340px"
             placeholder="Question about Starlink? Ask me."
             value="<?= h((string)($_wCfg['web_chat_teaser'] ?? '')) ?>">
      <label style="font-size:13px">after
        <input type="number" min="0" max="120" name="web_chat_teaser_delay" style="width:70px"
               placeholder="6" value="<?= h((string)($_wCfg['web_chat_teaser_delay'] ?? '')) ?>">s</label>
    </div>
    <div class="wa-row">
      <span class="n"></span>
      <span style="color:#5a6b60;font-size:12px;max-width:70ch">
        Shown once per visit and never again once dismissed. Leave the message blank to use
        the default; set the delay to 0 to show it immediately.
      </span>
    </div>
    <div class="wa-row">
      <span class="n">Keep contact details for</span>
      <input type="number" min="0" max="3650" name="web_chat_retention_days" style="width:90px"
             placeholder="90" value="<?= h((string)($_wCfg['web_chat_retention_days'] ?? '')) ?>">
      <span style="font-size:13px">days</span>
      <span style="color:#5a6b60;font-size:12px;max-width:60ch">
        Leads and their conversations are deleted automatically after this. Default 90.
        0 keeps everything forever &mdash; a decision worth making deliberately, and it must
        match what privacy.html tells visitors.
      </span>
    </div>
    <div class="wa-row"><button class="wa-btn p" type="submit">Save website chat</button></div>
  </form>

  <?php
    $_leads = [];
    try { $_leads = $_wcStore ? $_wcStore->load('web_chat_leads.json') : []; } catch (\Throwable $e) {}
    $_leads = array_slice(array_reverse($_leads), 0, 15);
  ?>
  <div class="wa-row" style="display:block;margin-top:10px">
    <span class="n" style="display:block;margin-bottom:6px">Contact details left in the chat</span>
    <p style="margin:0 0 8px;color:#5a6b60;font-size:12.5px;max-width:70ch">
      This is contact details only. To read what visitors actually asked and what the AI
      answered, open <strong>WhatsApp &rarr; Inbox</strong> and choose the
      <strong>Website</strong> tab &mdash; website chats and WhatsApp conversations are in the
      same place. Deleting below removes the contact details <em>and</em> the conversation.
    </p>
    <?php if (!$_leads): ?>
      <span style="color:#5a6b60;font-size:13px">Nothing yet.</span>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:13.5px">
      <tr style="text-align:left;border-bottom:1px solid #dce3de">
        <th style="padding:5px 8px 5px 0">When</th><th style="padding:5px 8px">Name</th>
        <th style="padding:5px 8px">Phone</th><th style="padding:5px 8px">Email</th><th></th>
      </tr>
      <?php foreach ($_leads as $L): ?>
      <tr style="border-bottom:1px solid #f0f3f1">
        <td style="padding:5px 8px 5px 0;white-space:nowrap"><?= h(substr((string)($L['created'] ?? $L['updated'] ?? ''), 0, 16)) ?></td>
        <td style="padding:5px 8px"><?= h((string)($L['name'] ?? '')) ?></td>
        <td style="padding:5px 8px"><?= h((string)($L['phone'] ?? '')) ?></td>
        <td style="padding:5px 8px"><?= h((string)($L['email'] ?? '')) ?></td>
        <td style="padding:5px 8px;text-align:right">
          <form method="post" style="display:inline"
                onsubmit="return confirm('Delete this person\'s contact details and their conversation? This cannot be undone.')">
            <?= $_csrf ?>
            <input type="hidden" name="wa_action" value="forget_lead">
            <input type="hidden" name="session" value="<?= h((string)($L['session'] ?? '')) ?>">
            <button class="wa-btn" type="submit" style="padding:3px 10px;font-size:12px">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
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
      <?php
        // A generic placeholder is worse than none here: plugin paths differ
        // between a standalone uCRM (/_plugins/...) and the UISP bundle
        // (/crm/_plugins/...), and a wrong base means the webhook is registered
        // against a 404 and WhatsApp silently never works. uCRM already knows
        // the right one, so show that -- and offer the port-less form when a
        // proxy makes it available, since it is the better address to give out.
        $_ucrmJson = @json_decode((string)@file_get_contents($_wRoot . '/ucrm.json'), true);
        $_pubHint  = rtrim(preg_replace('~/public\.php.*$~', '',
                       (string)($_ucrmJson['pluginPublicUrl'] ?? '')), '/');
        $_noPort   = $_pubHint !== '' ? preg_replace('~^(https?://[^/:]+):\d+~', '$1', $_pubHint) : '';
      ?>
      <input type="text" name="public_url" style="min-width:460px"
             value="<?= h((string)($_wCfg['plugin_public_url'] ?? '')) ?>"
             placeholder="<?= h($_pubHint ?: 'https://your-crm/_plugins/dishnet-hybrid-sudan') ?>">
      <?php if ($_pubHint !== ''): ?>
        <div style="color:#5a6b60;font-size:12px;margin-top:4px;max-width:70ch">
          uCRM reports <code><?= h($_pubHint) ?></code>.
          <?php if ($_noPort !== $_pubHint): ?>
            If a reverse proxy serves this host without the port, prefer
            <code><?= h($_noPort) ?></code> &mdash; it is the address customers
            and Evolution should use. Leave blank to work it out from your browser,
            which gets it wrong when you reach the plugin by IP.
          <?php endif; ?>
        </div>
      <?php endif; ?>
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
