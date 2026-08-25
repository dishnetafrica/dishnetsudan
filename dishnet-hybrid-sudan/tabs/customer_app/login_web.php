<?php
/**
 * Customer Login — Web-based OTP flow for PWA access
 *
 * Flow: Enter phone → OTP via WhatsApp → Verify → (if first time) Accept T&C → Redirect to portal
 * v4.12.19 — adds consent step, country hint, version badge, help link.
 */
declare(strict_types=1);

$baseUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$apiUrl = $baseUrl . '?page=api';

// Current app version — read dynamically from manifest.json so it stays
// in sync with deployed plugin version automatically (no more hardcoded drift).
$appVersion = '?';
$_manifestFile = dirname(__DIR__, 2) . '/manifest.json';
if (file_exists($_manifestFile)) {
    $_m = json_decode((string)@file_get_contents($_manifestFile), true);
    if (is_array($_m) && !empty($_m['information']['version'])) {
        $appVersion = (string)$_m['information']['version'];
    }
}

// Pull current legal versions (safe to include, pure functions)
require_once dirname(__DIR__, 2) . '/lib/LegalContent.php';
$legalVer = dnLegalVersion();

// Check if already logged in via cookie
$existingToken = $_COOKIE['dn_customer_token'] ?? '';
if ($existingToken) {
    // Validate token is still good
    $parts = explode('.', $existingToken);
    if (count($parts) === 3) {
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if ($payload && ($payload['exp'] ?? 0) > time()) {
            // Token still valid — redirect to portal
            header('Location: ' . $baseUrl . '?page=customer_portal&view=home&token=' . urlencode($existingToken));
            exit;
        }
    }
    // Token expired — clear cookie
    setcookie('dn_customer_token', '', time() - 3600, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>DishNet Africa</title>
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#141414">
<link rel="icon" type="image/png" sizes="192x192" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%23D41C1C'/><text x='50' y='72' font-family='Arial Black' font-size='50' font-weight='900' fill='white' text-anchor='middle'>DN</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@800;900&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#D41C1C;--dark:#141414;--gray:#888;--line:#e5e5e5;--swoosh:linear-gradient(110deg,#D41C1C 0%,#E8521A 60%,#FF7A35 100%)}
*{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100vh;background:#fff;font-family:'Barlow',sans-serif;color:var(--dark);-webkit-font-smoothing:antialiased}
body{display:flex;flex-direction:column;min-height:100vh}
.wrap{max-width:400px;margin:0 auto;padding:60px 28px 24px;flex:1;width:100%}

.logo{font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:28px;letter-spacing:-.5px;color:var(--dark);position:relative;display:inline-block;margin-bottom:8px}
.logo::after{content:'';position:absolute;bottom:-3px;left:0;right:0;height:3px;background:var(--swoosh);border-radius:2px}
.tag{display:inline-block;background:var(--dark);color:#fff;font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:10px;letter-spacing:.12em;padding:4px 10px;border-radius:4px;margin-left:10px;vertical-align:4px}
.title{font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:26px;letter-spacing:-.3px;margin:36px 0 8px;color:var(--dark)}
.sub{font-size:14px;color:#888;line-height:1.6;margin-bottom:28px}
.field{margin-bottom:18px}
.field label{display:block;font-size:12px;font-weight:600;color:var(--dark);margin-bottom:6px}
.field input{width:100%;padding:14px 16px;border:1.5px solid #ddd;border-radius:12px;font-size:16px;font-family:inherit;outline:none;transition:border-color .15s}
.field input:focus{border-color:var(--red)}
.field input::placeholder{color:#bbb}

/* Phone input with country hint */
.phone-wrap{position:relative}
.phone-hint{position:absolute;top:calc(100% + 6px);left:2px;font-size:11px;color:#aaa;font-family:'Barlow',sans-serif}
.phone-hint strong{color:var(--dark);font-weight:600}

.btn{width:100%;padding:15px;background:var(--red);color:#fff;border:none;border-radius:12px;font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:16px;letter-spacing:.02em;cursor:pointer;transition:background .15s}
.btn:hover{background:#b81818}
.btn:disabled{background:#ccc;cursor:not-allowed}
.err{background:#fff0f0;color:#c00;padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px;display:none}
.err.show{display:block}

.note{text-align:center;font-size:12px;color:#aaa;margin-top:16px;line-height:1.6}
.note a{color:var(--red);text-decoration:none;font-weight:600}
.back{display:inline-flex;align-items:center;gap:4px;font-size:13px;color:#888;text-decoration:none;margin-bottom:24px;cursor:pointer;border:none;background:none;font-family:inherit;padding:0}
.back:hover{color:var(--dark)}
.otp-input{letter-spacing:8px;text-align:center;font-size:24px;font-family:'Barlow Condensed',sans-serif;font-weight:800}

#step-phone,#step-otp,#step-consent{display:none}
#step-phone.active,#step-otp.active,#step-consent.active{display:block}

.spin-small{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;vertical-align:-2px;margin-right:6px}
@keyframes spin{to{transform:rotate(360deg)}}

/* v4.12.19 — Consent step */
.consent-intro{font-size:14px;color:#555;line-height:1.55;margin-bottom:20px}
.consent-box{background:#fafafa;border:1.5px solid var(--line);border-radius:12px;padding:16px 18px;margin-bottom:18px}
.consent-row{display:flex;gap:12px;align-items:flex-start;cursor:pointer;user-select:none}
.consent-row input[type=checkbox]{flex-shrink:0;margin-top:3px;width:20px;height:20px;accent-color:var(--red);cursor:pointer}
.consent-row-text{font-size:14px;line-height:1.5;color:var(--dark)}
.consent-row-text a{color:var(--red);text-decoration:none;font-weight:600;border-bottom:1px dotted var(--red)}
.consent-row-text a:hover{border-bottom-style:solid}
.consent-meta{font-size:11px;color:#aaa;margin-top:14px;line-height:1.5}
.consent-meta strong{color:var(--dark);font-weight:600}

/* v4.21.7: Login tabs (Phone / Email) */
.login-tabs{display:flex;gap:2px;margin:0 0 18px;background:#f5f5f5;border-radius:10px;padding:4px}
.login-tab{flex:1;background:transparent;border:none;border-radius:8px;padding:10px 6px;font-family:'Barlow',sans-serif;font-weight:600;font-size:13px;color:#888;cursor:pointer;transition:all .15s}
.login-tab:hover{color:var(--dark)}
.login-tab.active{background:#fff;color:var(--dark);box-shadow:0 1px 3px rgba(0,0,0,.08)}
.login-pane{display:none}
.login-pane.active{display:block}
.login-fallback{font-size:11px;color:#888;background:#fafafa;border-radius:8px;padding:9px 12px;margin-top:14px;line-height:1.5;border:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;gap:8px}
.login-fallback a{color:var(--red);font-weight:600;text-decoration:none;flex-shrink:0;cursor:pointer}
.login-fallback a:hover{text-decoration:underline}

/* Footer */
.login-foot{border-top:1px solid var(--line);padding:20px 28px 24px;text-align:center;font-family:'Barlow',sans-serif}
.login-foot-links{display:inline-flex;gap:14px;flex-wrap:wrap;justify-content:center;margin-bottom:10px;font-size:12px}
.login-foot-links a{color:#888;text-decoration:none;transition:color .15s}
.login-foot-links a:hover{color:var(--red)}
.login-foot-meta{font-family:'JetBrains Mono','Menlo',monospace;font-size:10px;color:#c5c5c5;letter-spacing:.05em}
.login-foot-meta span{display:inline-block;padding:0 8px}
.login-foot-meta span+span{border-left:1px solid var(--line)}
</style>
</head>
<body>

<div class="wrap">
  <div><span class="logo">DishNet</span><span class="tag">AFRICA</span></div>

  <!-- Step 1: Phone or Email entry (v4.21.7+) -->
  <!--
    Two-tab login: customers without WhatsApp on their registered DishNet
    phone can choose Email instead. The Email tab does an email-based
    lookup against UCRM (same security model — must match a registered
    customer record). One OTP, two channels, customer picks one.
  -->
  <div id="step-phone" class="active">
    <div class="title">Log in to DishNet</div>
    <div class="sub">Choose how you'd like to receive your code</div>

    <!-- Tab switcher -->
    <div class="login-tabs" role="tablist">
      <button class="login-tab active" id="tab-phone" role="tab" onclick="switchTab('phone')">📱 Phone</button>
      <button class="login-tab" id="tab-email" role="tab" onclick="switchTab('email')">✉️ Email</button>
    </div>

    <div class="err" id="err-phone"></div>

    <!-- Phone input pane -->
    <div class="login-pane active" id="pane-phone">
      <div class="field">
        <label>Phone number</label>
        <div class="phone-wrap">
          <input type="tel" id="phone" placeholder="+211 9XX XXX XXX" autofocus>
          <div class="phone-hint">Include country code. South Sudan: <strong>+211</strong></div>
        </div>
      </div>
      <button class="btn" id="btn-phone" onclick="sendOtp()" style="margin-top:22px">Send code via WhatsApp</button>
      <div class="note">We'll send a 6-digit code to your WhatsApp.</div>
      <div class="login-fallback">
        <span>No WhatsApp on this number?</span>
        <a onclick="switchTab('email')">Use Email instead</a>
      </div>
    </div>

    <!-- Email input pane -->
    <div class="login-pane" id="pane-email">
      <div class="field">
        <label>Email address</label>
        <input type="email" id="email" placeholder="you@example.com" autocomplete="email">
        <div class="phone-hint">Use the email you registered with DishNet.</div>
      </div>
      <button class="btn" id="btn-email" onclick="sendOtp()" style="margin-top:22px">Send code via Email</button>
      <div class="note">
        If not received within 2 minutes, check your spam folder.
      </div>
      <div class="login-fallback">
        <span>Wrong email?</span>
        <a onclick="switchTab('phone')">Try Phone instead</a>
      </div>
    </div>
  </div>

  <!-- Step 2: OTP verification -->
  <div id="step-otp">
    <button class="back" onclick="showStep('phone')">← Back</button>
    <div class="title">Enter verification code</div>
    <div class="sub">We sent a 6-digit code to <b id="otp-phone"></b></div>
    <div class="err" id="err-otp"></div>
    <div class="field">
      <input type="text" id="otp" class="otp-input" placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
    </div>
    <button class="btn" id="btn-otp" onclick="verifyOtp()">Verify</button>
    <div class="note">
      <span id="otp-countdown" style="color:#999">&nbsp;</span>
      &nbsp;·&nbsp;
      <a href="#" onclick="sendOtp();return false">Resend code</a>
    </div>
  </div>

  <!-- Step 3 (v4.12.19): Consent (shown only on first login or version bump) -->
  <div id="step-consent">
    <div class="title">One quick thing</div>
    <div class="consent-intro">
      Before we sign you in, please review and accept our Terms of Service and
      Privacy Policy. This is a one-time step.
    </div>
    <div class="err" id="err-consent"></div>
    <div class="consent-box">
      <label class="consent-row">
        <input type="checkbox" id="consent-check">
        <span class="consent-row-text">
          I agree to DishNet's
          <a href="?page=terms" target="_blank" rel="noopener">Terms of Service</a>
          and
          <a href="?page=privacy" target="_blank" rel="noopener">Privacy Policy</a>.
        </span>
      </label>
      <div class="consent-meta">
        Terms <strong>v<?= htmlspecialchars($legalVer['tos']) ?></strong>
        &middot; Privacy <strong>v<?= htmlspecialchars($legalVer['privacy']) ?></strong>
        &middot; Effective <strong><?= htmlspecialchars($legalVer['dated']) ?></strong>
      </div>
    </div>
    <button class="btn" id="btn-consent" onclick="recordConsent()" disabled>Accept &amp; continue</button>
    <div class="note">
      Not ready?
      <a href="#" onclick="cancelConsent();return false">Go back</a>
      — we won't record anything.
    </div>
  </div>
</div>

<!-- Footer (v4.12.19) — help link + version -->
<div class="login-foot">
  <div class="login-foot-links">
    <a href="https://wa.me/211921443002" target="_blank" rel="noopener">Need help? WhatsApp us</a>
    <a href="?page=terms" target="_blank" rel="noopener">Terms</a>
    <a href="?page=privacy" target="_blank" rel="noopener">Privacy</a>
  </div>
  <div class="login-foot-meta">
    <span>DishNet Africa Ltd.</span>
    <span>v<?= htmlspecialchars($appVersion) ?></span>
    <span>Juba, South Sudan</span>
  </div>
</div>

<script>
var apiUrl = <?= json_encode($apiUrl) ?>;
var baseUrl = <?= json_encode($baseUrl) ?>;
var currentLegal = <?= json_encode($legalVer) ?>;
var phone = '';
var loginMode = 'phone';   // v4.21.7: 'phone' | 'email' — which tab is active
var loginIdentifier = '';  // v4.21.7: phone number or email, whichever they used
var pendingToken = ''; // JWT held back until consent recorded

// v4.12.20: Countdown state — driven by server time, not client.
// client clock drift ≠ user's problem.
var otpExpireAt = 0;    // unix ts (server time) when the code dies
var otpTimerIv = null;

function showStep(s) {
  document.getElementById('step-phone').classList.toggle('active', s === 'phone');
  document.getElementById('step-otp').classList.toggle('active', s === 'otp');
  document.getElementById('step-consent').classList.toggle('active', s === 'consent');
}

function showErr(id, msg) {
  var el = document.getElementById(id);
  el.textContent = msg; el.classList.toggle('show', !!msg);
}

// v4.21.7: switch between Phone and Email tabs
function switchTab(mode) {
  loginMode = mode;
  document.getElementById('tab-phone').classList.toggle('active', mode === 'phone');
  document.getElementById('tab-email').classList.toggle('active', mode === 'email');
  document.getElementById('pane-phone').classList.toggle('active', mode === 'phone');
  document.getElementById('pane-email').classList.toggle('active', mode === 'email');
  showErr('err-phone', '');
  // Focus the active input for fast keyboard typing
  setTimeout(function() {
    var fld = document.getElementById(mode === 'email' ? 'email' : 'phone');
    if (fld) fld.focus();
  }, 50);
}

function sendOtp() {
  // v4.21.7: read from whichever tab is active. Field IDs are 'phone' and 'email'.
  var btn, identifier, body, btnLabel;
  if (loginMode === 'email') {
    identifier = document.getElementById('email').value.trim();
    if (!identifier || identifier.indexOf('@') < 1) {
      showErr('err-phone', 'Please enter a valid email address');
      return;
    }
    btn = document.getElementById('btn-email');
    body = JSON.stringify({email: identifier});
    btnLabel = 'Send code via Email';
  } else {
    identifier = document.getElementById('phone').value.trim();
    if (!identifier || identifier.length < 8) {
      showErr('err-phone', 'Please enter a valid phone number');
      return;
    }
    btn = document.getElementById('btn-phone');
    body = JSON.stringify({phone: identifier});
    btnLabel = 'Send code via WhatsApp';
  }

  // Save for verify step + resend
  loginIdentifier = identifier;
  // Legacy var 'phone' still used below for display in OTP step
  phone = identifier;

  btn.disabled = true; btn.innerHTML = '<span class="spin-small"></span>Sending...';
  showErr('err-phone', '');

  fetch(apiUrl + '&action=app_send_otp', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: body
  })
  .then(function(r) { return r.text(); })
  .then(function(raw) {
    btn.disabled = false; btn.textContent = btnLabel;
    // API may return HTML warnings before JSON — extract JSON portion
    var jsonStr = raw;
    var jsonStart = raw.indexOf('{');
    if (jsonStart > 0) jsonStr = raw.substring(jsonStart);
    var d;
    try { d = JSON.parse(jsonStr); } catch(e) { d = null; }
    if (d && (d.ok || d.status === 'success')) {
      document.getElementById('otp-phone').textContent = identifier;
      // v4.12.20: compute countdown from server clock
      var dd = d.data || d;
      var ttl = dd.expires_in || 900;
      var srv = dd.server_time || Math.floor(Date.now()/1000);
      var localOffset = Math.floor(Date.now()/1000) - srv;
      otpExpireAt = srv + ttl + localOffset;
      startOtpCountdown();
      showStep('otp');
      document.getElementById('otp').focus();
    } else {
      showErr('err-phone', (d && (d.error || d.message)) ? (d.error || d.message) : 'Failed to send code. Please try again.');
    }
  })
  .catch(function(e) {
    btn.disabled = false; btn.textContent = btnLabel;
    showErr('err-phone', 'Network error. Please try again.');
  });
}

function verifyOtp() {
  var code = document.getElementById('otp').value.trim();
  if (!code || code.length !== 6) { showErr('err-otp', 'Enter the 6-digit code'); return; }
  var btn = document.getElementById('btn-otp');
  btn.disabled = true; btn.innerHTML = '<span class="spin-small"></span>Verifying...';
  showErr('err-otp', '');

  // v4.21.7: send the right field based on which tab they used.
  // Backend accepts either 'phone' or 'email' on app_verify_otp.
  var verifyBody = (loginMode === 'email')
    ? {email: loginIdentifier, code: code}
    : {phone: loginIdentifier, code: code};

  fetch(apiUrl + '&action=app_verify_otp', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(verifyBody)
  })
  .then(function(r) { return r.text(); })
  .then(function(raw) {
    btn.disabled = false; btn.textContent = 'Verify';
    var jsonStr = raw;
    var jsonStart = raw.indexOf('{');
    if (jsonStart > 0) jsonStr = raw.substring(jsonStart);
    var d;
    try { d = JSON.parse(jsonStr); } catch(e) { d = null; }
    var token = d ? (d.token || (d.data && d.data.token)) : null;
    var needsConsent = d && d.data && d.data.needs_consent === true;
    if (d && (d.ok || d.status === 'success') && token) {
      if (needsConsent) {
        // v4.12.19 — hold the token until the user accepts
        pendingToken = token;
        showStep('consent');
      } else {
        completeLogin(token);
      }
    } else {
      showErr('err-otp', (d && (d.error || d.message)) ? (d.error || d.message) : 'Invalid code. Please try again.');
    }
  })
  .catch(function(e) {
    btn.disabled = false; btn.textContent = 'Verify';
    showErr('err-otp', 'Network error. Please try again.');
  });
}

// v4.12.19 — Consent handlers
document.getElementById('consent-check').addEventListener('change', function() {
  document.getElementById('btn-consent').disabled = !this.checked;
});

function recordConsent() {
  var check = document.getElementById('consent-check');
  if (!check.checked) {
    showErr('err-consent', 'Please tick the box to continue.');
    return;
  }
  var btn = document.getElementById('btn-consent');
  btn.disabled = true; btn.innerHTML = '<span class="spin-small"></span>Recording...';
  showErr('err-consent', '');

  fetch(apiUrl + '&action=app_record_consent', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(
      loginMode === 'email'
        ? { email: loginIdentifier, tos_version: currentLegal.tos, privacy_version: currentLegal.privacy }
        : { phone: loginIdentifier, tos_version: currentLegal.tos, privacy_version: currentLegal.privacy }
    )
  })
  .then(function(r) { return r.text(); })
  .then(function(raw) {
    var jsonStart = raw.indexOf('{');
    var jsonStr = jsonStart > 0 ? raw.substring(jsonStart) : raw;
    var d;
    try { d = JSON.parse(jsonStr); } catch(e) { d = null; }
    if (d && (d.ok || d.status === 'success')) {
      // Proceed with the held-back token
      completeLogin(pendingToken);
    } else {
      btn.disabled = false; btn.textContent = 'Accept & continue';
      showErr('err-consent', (d && (d.error || d.message)) || 'Could not save your acceptance. Try again.');
    }
  })
  .catch(function() {
    btn.disabled = false; btn.textContent = 'Accept & continue';
    showErr('err-consent', 'Network error. Please try again.');
  });
}

function cancelConsent() {
  // Discard the pending token and go back to phone step
  pendingToken = '';
  document.getElementById('consent-check').checked = false;
  document.getElementById('btn-consent').disabled = true;
  showErr('err-consent', '');
  showStep('phone');
}

function completeLogin(token) {
  document.cookie = 'dn_customer_token=' + encodeURIComponent(token) + ';path=/;max-age=' + (30*86400) + ';SameSite=Lax';
  window.location.href = baseUrl + '?page=customer_portal&view=home&token=' + encodeURIComponent(token);
}

// v4.12.20: server-clock-driven countdown on the OTP screen. Updates every
// second. When time runs out, displays "Expired — tap Resend code."
function startOtpCountdown() {
  if (otpTimerIv) clearInterval(otpTimerIv);
  var el = document.getElementById('otp-countdown');
  function tick() {
    var now = Math.floor(Date.now()/1000);
    var remaining = otpExpireAt - now;
    if (remaining <= 0) {
      el.innerHTML = '<span style="color:#c00;font-weight:600">Code expired</span>';
      clearInterval(otpTimerIv);
      otpTimerIv = null;
      return;
    }
    var m = Math.floor(remaining / 60);
    var s = remaining % 60;
    el.textContent = 'Code valid for ' + m + ':' + (s < 10 ? '0' : '') + s;
  }
  tick();
  otpTimerIv = setInterval(tick, 1000);
}

// Auto-submit OTP when 6 digits entered
document.getElementById('otp').addEventListener('input', function() {
  if (this.value.length === 6) verifyOtp();
});

// Enter key submits
document.getElementById('phone').addEventListener('keydown', function(e) { if (e.key === 'Enter') sendOtp(); });
document.getElementById('otp').addEventListener('keydown', function(e) { if (e.key === 'Enter') verifyOtp(); });
</script>
</body>
</html>
