<?php
// ══════════════════════════════════════════════════════════════════════════════
// Tab: smtp_diagnostic — admin-only SMTP troubleshooter
// v4.21.8
//
// Purpose: figure out exactly why outbound email is or isn't working.
// Reads UCRM mailer settings (single source of truth — same approach
// as MailService), tests TCP+TLS+AUTH at every step with clear PASS/FAIL
// for each, and on demand sends a real test email.
//
// Why this exists: the Daily Report cron is reportedly not sending email,
// and v4.21.7 added Email-OTP login which depends on the same SMTP. Admin
// needs a focused diagnostic UI rather than grepping cron stderr.
// ══════════════════════════════════════════════════════════════════════════════

if (!isset($retailer)) $retailer = $auth->requireLogin();
$isAdmin = !empty($retailer['is_admin']);
if (!$isAdmin) {
    echo '<div style="padding:40px;text-align:center;color:#666">Admin access required for SMTP diagnostic.</div>';
    return;
}

require_once dirname(__DIR__, 2) . '/lib/MailService.php';
require_once dirname(__DIR__, 2) . '/lib/OtpEmailTemplate.php';

$mailer = new MailService($dataDir);
$cfg = $mailer->getConfig();
$cfgErr = $mailer->lastError();

// v4.21.10: ensure all expected keys exist (PHP 8+ warns on undefined keys
// when getConfig() returned []). Defaults are harmless display fallbacks.
$cfg = array_merge([
    'host' => '',
    'port' => 0,
    'enc'  => '',
    'user' => '',
    'pass' => '',
    'from' => '',
    '_source' => '',
], is_array($cfg) ? $cfg : []);

// v4.21.12: source badge — 'plugin' (email_settings.json) or 'ucrm' (UCRM API)
$cfgSource = !empty($cfg['_source']) ? $cfg['_source'] : 'unset';

// Mask password for display
$maskedCfg = $cfg;
if (!empty($maskedCfg['pass'])) {
    $p = $maskedCfg['pass'];
    $maskedCfg['pass'] = strlen($p) > 4
        ? substr($p, 0, 2) . str_repeat('•', max(4, strlen($p) - 4)) . substr($p, -2)
        : str_repeat('•', strlen($p));
}

$action = $_POST['smtp_action'] ?? '';
$result = null;
$sendTo = trim($_POST['send_to'] ?? '');
$useSampleOtp = !empty($_POST['use_sample_otp']);
$saveMsg = '';

// v4.21.12: save SMTP config to plugin's email_settings.json
if ($action === 'save_config') {
    $emailFile = $dataDir . '/email_settings.json';
    $newCfg = [
        'smtp_host' => trim($_POST['cfg_host'] ?? ''),
        'smtp_port' => (int)($_POST['cfg_port'] ?? 587),
        'smtp_user' => trim($_POST['cfg_user'] ?? ''),
        'smtp_enc'  => in_array($_POST['cfg_enc'] ?? '', ['tls','ssl','none'], true) ? $_POST['cfg_enc'] : 'tls',
        'smtp_from' => trim($_POST['cfg_from'] ?? ''),
        'updated_by' => $retailer['name'] ?? 'admin',
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    // Only update password if user typed a new one (so editing other fields doesn't wipe it)
    $newPass = $_POST['cfg_pass'] ?? '';
    if ($newPass !== '' && $newPass !== '__keep__') {
        $newCfg['smtp_pass'] = $newPass;
    } else {
        // Keep existing password
        $existing = file_exists($emailFile) ? (json_decode(file_get_contents($emailFile), true) ?: []) : [];
        $newCfg['smtp_pass'] = $existing['smtp_pass'] ?? '';
    }
    file_put_contents($emailFile, json_encode($newCfg, JSON_PRETTY_PRINT));
    @chmod($emailFile, 0600); // SMTP password — restrict file perms
    $saveMsg = 'SMTP settings saved. Re-running diagnostic with new values.';

    // Re-load with new config
    $mailer = new MailService($dataDir);
    $cfg = $mailer->getConfig();
    $cfgErr = $mailer->lastError();
    $cfg = array_merge([
        'host' => '', 'port' => 0, 'enc' => '', 'user' => '', 'pass' => '', 'from' => '',
    ], is_array($cfg) ? $cfg : []);
    // Re-mask password
    $maskedCfg = $cfg;
    if (!empty($maskedCfg['pass'])) {
        $p = $maskedCfg['pass'];
        $maskedCfg['pass'] = strlen($p) > 4
            ? substr($p, 0, 2) . str_repeat('•', max(4, strlen($p) - 4)) . substr($p, -2)
            : str_repeat('•', strlen($p));
    }
}

if ($action === 'test_connection') {
    $result = smtpdiag_test_connection($cfg);
} elseif ($action === 'send_test') {
    if ($sendTo === '' || !filter_var($sendTo, FILTER_VALIDATE_EMAIL)) {
        $result = ['ok' => false, 'error' => 'Enter a valid recipient email address', 'log' => []];
    } elseif ($useSampleOtp) {
        // Use the actual OTP template so admin sees what customers will see
        $sampleCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $sampleName = trim($retailer['name'] ?? '') ?: 'Admin';
        $subject = OtpEmailTemplate::subject($sampleCode);
        $html    = OtpEmailTemplate::html(explode(' ', $sampleName)[0], $sampleCode, 15);
        $text    = OtpEmailTemplate::text(explode(' ', $sampleName)[0], $sampleCode, 15);
        $result  = $mailer->send($sendTo, $sampleName, '[TEST] ' . $subject, $html, $text);
        $result['sample_code'] = $sampleCode;
    } else {
        $subject = '[DishNet SMTP Test] Connection working';
        $html = '<p>This is a test email from your DishNet plugin SMTP diagnostic.</p>'
              . '<p>If you received this, your SMTP is working correctly. Sent at ' . htmlspecialchars(date('Y-m-d H:i:s T')) . '.</p>';
        $text = "DishNet SMTP test\n\nIf you received this, your SMTP is working correctly.\nSent at " . date('Y-m-d H:i:s T') . ".\n";
        $result = $mailer->send($sendTo, '', $subject, $html, $text);
    }
}

function smtpdiag_test_connection(array $cfg): array {
    $log = [];
    if (empty($cfg) || ($cfg['host'] ?? '') === '') {
        $log[] = ['step' => 'config', 'ok' => false, 'msg' => 'No SMTP host — UCRM mailer not configured'];
        return ['ok' => false, 'log' => $log];
    }
    $log[] = ['step' => 'config', 'ok' => true, 'msg' =>
        sprintf('host=%s port=%d enc=%s user=%s', $cfg['host'], $cfg['port'], $cfg['enc'] ?: 'none', $cfg['user'] ?: '(none)')];

    $errno = 0; $errstr = '';
    $transport = ($cfg['enc'] === 'ssl') ? 'ssl://' . $cfg['host'] : $cfg['host'];
    $started = microtime(true);
    $fp = @fsockopen($transport, $cfg['port'], $errno, $errstr, 15);
    $ms = (int)((microtime(true) - $started) * 1000);
    if (!$fp) {
        $log[] = ['step' => 'tcp_connect', 'ok' => false, 'msg' => "fail in {$ms}ms: {$errstr} (errno {$errno})"];
        $hint = '';
        if ($errno === 110 || stripos($errstr, 'timed out') !== false) $hint = 'Firewall or wrong port — check egress allows port ' . $cfg['port'] . '.';
        elseif ($errno === 111 || stripos($errstr, 'refused') !== false) $hint = 'Server not listening — wrong host or port.';
        elseif ($errno === -2 || stripos($errstr, 'resolve') !== false) $hint = 'DNS resolution failed for ' . $cfg['host'] . '.';
        if ($hint) $log[] = ['step' => 'hint', 'ok' => false, 'msg' => $hint];
        return ['ok' => false, 'log' => $log];
    }
    $log[] = ['step' => 'tcp_connect', 'ok' => true, 'msg' => "connected in {$ms}ms"];

    stream_set_timeout($fp, 15);
    $read = function() use ($fp) {
        $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (strlen($l) >= 4 && substr($l, 3, 1) === ' ') break; } return rtrim($r);
    };
    $write = function($c) use ($fp) { fputs($fp, $c . "\r\n"); };

    $g = $read();
    if (substr($g, 0, 3) !== '220') { $log[] = ['step' => 'greeting', 'ok' => false, 'msg' => $g]; @fclose($fp); return ['ok' => false, 'log' => $log]; }
    $log[] = ['step' => 'greeting', 'ok' => true, 'msg' => $g];

    $hn = gethostname() ?: 'localhost';
    $write("EHLO {$hn}");
    $e = $read();
    if (substr($e, 0, 3) !== '250') { $log[] = ['step' => 'ehlo', 'ok' => false, 'msg' => $e]; @fclose($fp); return ['ok' => false, 'log' => $log]; }
    $log[] = ['step' => 'ehlo', 'ok' => true, 'msg' => 'server accepts EHLO'];

    if ($cfg['enc'] === 'tls') {
        $write('STARTTLS'); $t = $read();
        if (substr($t, 0, 3) !== '220') { $log[] = ['step' => 'starttls', 'ok' => false, 'msg' => $t]; @fclose($fp); return ['ok' => false, 'log' => $log]; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $log[] = ['step' => 'tls_handshake', 'ok' => false, 'msg' => 'TLS handshake failed — check TLS version compat']; @fclose($fp); return ['ok' => false, 'log' => $log];
        }
        $log[] = ['step' => 'tls_handshake', 'ok' => true, 'msg' => 'TLS established'];
        $write("EHLO {$hn}"); $read();
    }

    if ($cfg['user'] !== '' && $cfg['pass'] !== '') {
        $write('AUTH LOGIN'); $r1 = $read();
        if (substr($r1, 0, 3) !== '334') { $log[] = ['step' => 'auth_login', 'ok' => false, 'msg' => $r1]; @fclose($fp); return ['ok' => false, 'log' => $log]; }
        $write(base64_encode($cfg['user'])); $r2 = $read();
        if (substr($r2, 0, 3) !== '334') { $log[] = ['step' => 'auth_user', 'ok' => false, 'msg' => $r2]; @fclose($fp); return ['ok' => false, 'log' => $log]; }
        $write(base64_encode($cfg['pass'])); $r3 = $read();
        if (substr($r3, 0, 3) !== '235') {
            $log[] = ['step' => 'auth_pass', 'ok' => false, 'msg' => $r3];
            $log[] = ['step' => 'hint', 'ok' => false, 'msg' => 'Password rejected. Check UCRM Admin > Settings > Mailer credentials. Gmail/Outlook with 2FA need an app-specific password, not the regular one.'];
            @fclose($fp); return ['ok' => false, 'log' => $log];
        }
        $log[] = ['step' => 'auth', 'ok' => true, 'msg' => "authenticated as {$cfg['user']}"];
    } else {
        $log[] = ['step' => 'auth', 'ok' => true, 'msg' => 'no credentials — skipped (server may reject MAIL FROM later)'];
    }

    $write('QUIT'); @fclose($fp);
    $log[] = ['step' => 'done', 'ok' => true, 'msg' => 'Connection passed all checks. Click "Send test email" to verify end-to-end delivery.'];
    return ['ok' => true, 'log' => $log];
}
?>

<style>
.smtpd-wrap{max-width:920px;margin:0 auto;padding:20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.smtpd-hero{background:linear-gradient(135deg,#1A1A1A,#2A2A2A);border-radius:18px;padding:22px 26px;color:#fff;margin-bottom:18px}
.smtpd-hero h1{font-size:22px;font-weight:800;margin:0 0 6px;letter-spacing:-0.3px}
.smtpd-hero p{font-size:13px;opacity:0.75;margin:0;line-height:1.55}
.smtpd-card{background:#fff;border-radius:14px;padding:18px 20px;margin-bottom:14px;border:1px solid #e9eef3;box-shadow:0 1px 3px rgba(0,0,0,0.03)}
.smtpd-card h3{font-size:14px;font-weight:700;color:#141414;margin:0 0 12px;text-transform:uppercase;letter-spacing:0.04em}
.smtpd-cfgrow{display:grid;grid-template-columns:120px 1fr 80px;gap:10px;font-size:13px;padding:8px 10px;border-bottom:1px solid #f5f5f5}
.smtpd-cfgrow:last-child{border-bottom:none}
.smtpd-cfgrow .k{font-weight:600;color:#444}
.smtpd-cfgrow .v{font-family:'Menlo','Consolas',monospace;color:#141414;word-break:break-all}
.smtpd-cfgrow .v.empty{color:#bbb;font-style:italic;font-family:inherit}
.smtpd-cfgrow .src{font-size:10px;text-align:right;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;color:#0F6E56;background:#E1F5EE;padding:3px 7px;border-radius:5px;align-self:center;justify-self:end;height:fit-content}
.smtpd-cfgrow .src.unset{color:#A32D2D;background:#FCEBEB}
.smtpd-warn{background:#FFF8E1;border:1px solid #FFE5A0;border-radius:9px;padding:11px 14px;font-size:13px;color:#7A5800;line-height:1.5;margin:8px 0 14px}
.smtpd-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.smtpd-btn{padding:10px 16px;border-radius:9px;border:none;font-weight:700;font-size:13px;cursor:pointer;font-family:inherit}
.smtpd-btn.primary{background:#D41C1C;color:#fff}
.smtpd-btn.primary:hover{background:#B81818}
.smtpd-btn.secondary{background:#1B5E20;color:#fff}
.smtpd-btn.secondary:hover{background:#155018}
.smtpd-btn.outline{background:#fff;color:#141414;border:1.5px solid #ddd}
.smtpd-btn:disabled{opacity:0.5;cursor:not-allowed}
.smtpd-input{width:100%;padding:10px 12px;border:1.5px solid #ddd;border-radius:9px;font-size:14px;font-family:inherit}
.smtpd-input:focus{outline:none;border-color:#D41C1C}
.smtpd-result{margin-top:14px;border-radius:11px;padding:14px 16px;font-size:13px;line-height:1.6}
.smtpd-result.ok{background:#F0FDF4;border:1px solid #86EFAC;color:#14532D}
.smtpd-result.fail{background:#FEF2F2;border:1px solid #FCA5A5;color:#991B1B}
.smtpd-result h4{margin:0 0 8px;font-size:14px;font-weight:700}
.smtpd-log{font-family:'Menlo','Consolas',monospace;font-size:11px;line-height:1.7;background:#1F2937;color:#E5E7EB;padding:12px 14px;border-radius:8px;margin-top:10px;overflow-x:auto;white-space:pre-wrap}
.smtpd-log .ok{color:#86EFAC}
.smtpd-log .fail{color:#FCA5A5}
.smtpd-log .hint{color:#FBD38D;font-style:italic}
.smtpd-step{display:grid;grid-template-columns:14px 110px 1fr;gap:8px;align-items:start;margin-bottom:2px}
.smtpd-step .marker{color:#86EFAC;font-weight:700}
.smtpd-step.fail .marker{color:#FCA5A5}
.smtpd-step .name{color:#9CA3AF;font-size:10px;text-transform:uppercase;letter-spacing:0.05em;padding-top:2px}
.smtpd-step .msg{color:#E5E7EB;word-break:break-word}
.smtpd-step.fail .msg{color:#FCA5A5}
.smtpd-step.hint .marker{color:#FBD38D}
.smtpd-step.hint .msg{color:#FBD38D;font-style:italic}
.smtpd-checkbox{display:flex;align-items:center;gap:8px;font-size:13px;color:#444;margin:8px 0}
.smtpd-checkbox input{width:16px;height:16px;accent-color:#D41C1C}
</style>

<div class="smtpd-wrap">

  <div class="smtpd-hero">
    <h1>📧 SMTP Diagnostic</h1>
    <p>Test the email pipeline that powers Daily Reports, OTP login, and overdue notices. Reads UCRM's mailer settings — the same configuration that sends invoice emails to your customers.</p>
  </div>

  <div class="smtpd-card">
    <h3>📋 Current Configuration</h3>
    <?php if ($cfgErr && !empty($cfg['host'])): ?>
      <div class="smtpd-warn">
        <strong>⚠️ <?= htmlspecialchars($cfgErr) ?></strong>
      </div>
    <?php endif; ?>
    <?php if (!empty($cfg['host'])): ?>
      <div style="font-size:12px;color:#666;margin-bottom:10px">
        Source: <strong style="color:#141414">
        <?php
          if ($cfgSource === 'plugin') echo 'plugin (saved via Settings → Email)';
          elseif ($cfgSource === 'ucrm') echo 'ucrm /api/v1.0/settings (toggle ON, API responded)';
          elseif (strpos($cfgSource, 'fallback') !== false) echo htmlspecialchars($cfgSource) . ' — UCRM toggle is ON but API failed; using plugin SMTP instead';
          else echo htmlspecialchars($cfgSource);
        ?>
        </strong>
      </div>
    <?php endif; ?>
    <div class="smtpd-cfgrow">
      <div class="k">SMTP host</div>
      <div class="v <?= empty($cfg['host']) ? 'empty' : '' ?>"><?= htmlspecialchars($cfg['host'] ?: '— not set —') ?></div>
      <div class="src <?= empty($cfg['host']) ? 'unset' : '' ?>"><?= empty($cfg['host']) ? 'unset' : $cfgSource ?></div>
    </div>
    <div class="smtpd-cfgrow">
      <div class="k">Port</div>
      <div class="v <?= empty($cfg['port']) ? 'empty' : '' ?>"><?= (int)($cfg['port'] ?? 0) ?: '— not set —' ?></div>
      <div class="src <?= empty($cfg['port']) ? 'unset' : '' ?>"><?= empty($cfg['port']) ? 'unset' : $cfgSource ?></div>
    </div>
    <div class="smtpd-cfgrow">
      <div class="k">Encryption</div>
      <div class="v <?= empty($cfg['enc']) ? 'empty' : '' ?>"><?= htmlspecialchars($cfg['enc'] ?: '— none —') ?></div>
      <div class="src <?= empty($cfg['enc']) ? 'unset' : '' ?>"><?= empty($cfg['enc']) ? 'unset' : $cfgSource ?></div>
    </div>
    <div class="smtpd-cfgrow">
      <div class="k">Username</div>
      <div class="v <?= empty($cfg['user']) ? 'empty' : '' ?>"><?= htmlspecialchars($cfg['user'] ?: '— not set —') ?></div>
      <div class="src <?= empty($cfg['user']) ? 'unset' : '' ?>"><?= empty($cfg['user']) ? 'unset' : $cfgSource ?></div>
    </div>
    <div class="smtpd-cfgrow">
      <div class="k">Password</div>
      <div class="v <?= empty($maskedCfg['pass']) ? 'empty' : '' ?>"><?= htmlspecialchars($maskedCfg['pass'] ?: '— not set —') ?></div>
      <div class="src <?= empty($maskedCfg['pass']) ? 'unset' : '' ?>"><?= empty($maskedCfg['pass']) ? 'unset' : $cfgSource ?></div>
    </div>
    <div class="smtpd-cfgrow">
      <div class="k">From address</div>
      <div class="v <?= empty($cfg['from']) ? 'empty' : '' ?>"><?= htmlspecialchars($cfg['from'] ?: '— not set —') ?></div>
      <div class="src <?= empty($cfg['from']) ? 'unset' : '' ?>"><?= empty($cfg['from']) ? 'unset' : $cfgSource ?></div>
    </div>

  </div>

  <?php if ($saveMsg): ?>
    <div class="smtpd-card" style="background:#F0FDF4;border-color:#86EFAC;color:#14532D">
      <strong>✓ <?= htmlspecialchars($saveMsg) ?></strong>
    </div>
  <?php endif; ?>

  <div class="smtpd-card">
    <h3><?= empty($cfg['host']) ? '⚙️ Configure SMTP Settings' : '✏️ Edit SMTP Settings' ?></h3>
    <p style="font-size:13px;color:#666;margin:0 0 14px;line-height:1.55">
      <?php if (empty($cfg['host'])): ?>
        Fill in your SMTP credentials below to enable Daily Reports, OTP login, and overdue notices.
        Saved to plugin's own config file (no UCRM dependency).
      <?php else: ?>
        Saved values shown above. Edit and save to update. Leave password blank to keep the existing one.
      <?php endif; ?>
    </p>
    <form method="POST" autocomplete="off">
      <?= csrfField() ?>
      <input type="hidden" name="smtp_action" value="save_config">
      <div style="display:grid;grid-template-columns:130px 1fr;gap:10px;align-items:center;margin-bottom:10px">
        <label style="font-size:13px;font-weight:600;color:#444">SMTP host</label>
        <input type="text" name="cfg_host" class="smtpd-input"
               value="<?= htmlspecialchars($cfg['host'] ?? '') ?>"
               placeholder="smtp.gmail.com" required>

        <label style="font-size:13px;font-weight:600;color:#444">Port</label>
        <input type="number" name="cfg_port" class="smtpd-input"
               value="<?= (int)($cfg['port'] ?? 587) ?>"
               placeholder="587" min="1" max="65535" required>

        <label style="font-size:13px;font-weight:600;color:#444">Encryption</label>
        <select name="cfg_enc" class="smtpd-input">
          <option value="tls"  <?= ($cfg['enc'] ?? 'tls') === 'tls'  ? 'selected' : '' ?>>TLS (STARTTLS, port 587)</option>
          <option value="ssl"  <?= ($cfg['enc'] ?? '')    === 'ssl'  ? 'selected' : '' ?>>SSL (port 465)</option>
          <option value="none" <?= ($cfg['enc'] ?? '')    === 'none' ? 'selected' : '' ?>>None (plain — not recommended)</option>
        </select>

        <label style="font-size:13px;font-weight:600;color:#444">Username</label>
        <input type="text" name="cfg_user" class="smtpd-input"
               value="<?= htmlspecialchars($cfg['user'] ?? '') ?>"
               placeholder="noreply@dishnetafrica.com" autocomplete="username">

        <label style="font-size:13px;font-weight:600;color:#444">Password</label>
        <input type="password" name="cfg_pass" class="smtpd-input"
               value="<?= !empty($cfg['pass']) ? '__keep__' : '' ?>"
               placeholder="<?= !empty($cfg['pass']) ? '(keeping existing — type new to change)' : 'enter SMTP password' ?>"
               autocomplete="new-password">

        <label style="font-size:13px;font-weight:600;color:#444">From address</label>
        <input type="email" name="cfg_from" class="smtpd-input"
               value="<?= htmlspecialchars($cfg['from'] ?? '') ?>"
               placeholder="noreply@dishnetafrica.com">
      </div>
      <p style="font-size:11px;color:#999;margin:6px 0 12px;line-height:1.55">
        <strong>Gmail tip:</strong> with 2-factor auth, you must use an
        <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener" style="color:#D41C1C;font-weight:600">app-specific password</a>
        — not your regular Gmail password.<br>
        <strong>Common providers:</strong> Gmail = <code>smtp.gmail.com:587 (TLS)</code> · Outlook = <code>smtp-mail.outlook.com:587 (TLS)</code> · SendGrid = <code>smtp.sendgrid.net:587 (TLS)</code>
      </p>
      <button type="submit" class="smtpd-btn primary">💾 Save SMTP Settings</button>
    </form>
  </div>

  <div class="smtpd-card">
    <h3>🔍 Test #1 — Connection check</h3>
    <p style="font-size:13px;color:#666;margin:0 0 10px;line-height:1.5">
      Tries TCP, TLS handshake, and SMTP authentication without sending any email. Fast, safe, no risk of bouncing emails.
    </p>
    <form method="POST" style="display:inline">
      <?= csrfField() ?>
      <input type="hidden" name="smtp_action" value="test_connection">
      <button type="submit" class="smtpd-btn primary" <?= empty($cfg['host']) ? 'disabled' : '' ?>>Run connection test</button>
    </form>

    <?php if ($action === 'test_connection' && $result): ?>
      <div class="smtpd-result <?= $result['ok'] ? 'ok' : 'fail' ?>">
        <h4><?= $result['ok'] ? '✓ Connection passed' : '✗ Connection failed' ?></h4>
        <?php if (!$result['ok'] && !empty($result['error'])): ?><div><?= htmlspecialchars($result['error']) ?></div><?php endif; ?>
      </div>
      <div class="smtpd-log">
        <?php foreach ($result['log'] ?? [] as $entry): ?>
          <div class="smtpd-step <?= empty($entry['ok']) ? 'fail' : '' ?> <?= ($entry['step'] ?? '') === 'hint' ? 'hint' : '' ?>">
            <div class="marker"><?= !empty($entry['ok']) ? '✓' : '✗' ?></div>
            <div class="name"><?= htmlspecialchars($entry['step'] ?? '') ?></div>
            <div class="msg"><?= htmlspecialchars($entry['msg'] ?? '') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="smtpd-card">
    <h3>📨 Test #2 — Send a real email</h3>
    <p style="font-size:13px;color:#666;margin:0 0 12px;line-height:1.5">
      Connects, authenticates, and sends an actual test email. Use your own address and check the inbox (and spam folder) afterward.
    </p>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="smtp_action" value="send_test">
      <input type="email" name="send_to" class="smtpd-input"
             value="<?= htmlspecialchars($sendTo ?: ($retailer['email'] ?? '')) ?>"
             placeholder="recipient@example.com" required>
      <label class="smtpd-checkbox">
        <input type="checkbox" name="use_sample_otp" value="1" <?= $useSampleOtp ? 'checked' : '' ?>>
        Use the OTP login template (see exactly what customers will receive)
      </label>
      <div class="smtpd-actions">
        <button type="submit" class="smtpd-btn secondary" <?= empty($cfg['host']) ? 'disabled' : '' ?>>Send test email</button>
      </div>
    </form>

    <?php if ($action === 'send_test' && $result): ?>
      <div class="smtpd-result <?= !empty($result['ok']) ? 'ok' : 'fail' ?>">
        <h4><?= !empty($result['ok']) ? '✓ Sent' : '✗ Send failed' ?></h4>
        <?php if (!empty($result['ok'])): ?>
          Email queued successfully at SMTP server. Check the recipient inbox at <strong><?= htmlspecialchars($sendTo) ?></strong>
          (and the spam folder).
          <?php if ($useSampleOtp && !empty($result['sample_code'])): ?>
            The sample OTP in this email is <strong><?= htmlspecialchars($result['sample_code']) ?></strong> (test only — not registered with any login session).
          <?php endif; ?>
          <br><br>
          <strong>Important:</strong> "queued at server" doesn't guarantee inbox delivery.
          If the email doesn't arrive within 2 minutes, check the spam folder.
          If still missing, the recipient's mail server may be rejecting due to SPF/DKIM/DMARC
          issues at the sender domain — that's a DNS-level fix outside this plugin.
        <?php else: ?>
          <?= htmlspecialchars($result['error'] ?? 'Unknown error') ?>
        <?php endif; ?>
      </div>
      <?php if (!empty($result['log'])): ?>
        <div class="smtpd-log">
          <?php foreach ($result['log'] as $entry): ?>
            <div class="smtpd-step <?= empty($entry['ok']) ? 'fail' : '' ?>">
              <div class="marker"><?= !empty($entry['ok']) ? '✓' : '✗' ?></div>
              <div class="name"><?= htmlspecialchars($entry['step'] ?? '') ?></div>
              <div class="msg"><?= htmlspecialchars($entry['msg'] ?? '') ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="smtpd-card">
    <h3>💡 Common causes of "send succeeded but email never arrives"</h3>
    <ol style="font-size:13px;color:#444;line-height:1.7;margin:0;padding-left:20px">
      <li><strong>Spam folder.</strong> Check first — easiest fix, just train the inbox to whitelist.</li>
      <li><strong>SPF record missing.</strong> Recipient mail server checks if your SMTP relay is authorized to send for your domain. Ask your DNS admin to add an SPF TXT record for <code><?= htmlspecialchars(parse_url('mailto:' . ($cfg['from'] ?? ''), PHP_URL_PATH) ? explode('@', $cfg['from'] ?? '')[1] ?? '?' : '?') ?></code>.</li>
      <li><strong>DKIM not configured.</strong> Modern providers (Gmail, Outlook) require DKIM signatures — without it, emails go to spam or bounce.</li>
      <li><strong>Sender reputation.</strong> If this domain hasn't sent legitimate email before, providers may grey-list new sends. Build reputation by sending consistently to engaged recipients.</li>
      <li><strong>Recipient's server blocking by IP.</strong> If your SMTP relay's IP is on a public blocklist (rare with reputable hosts), recipients silently reject.</li>
    </ol>
  </div>

</div>
