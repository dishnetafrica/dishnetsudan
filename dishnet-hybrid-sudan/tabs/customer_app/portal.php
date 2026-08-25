<?php
// ════════════════════════════════════════════════════════════════════
// Customer Portal — Entry Point / Router
// ════════════════════════════════════════════════════════════════════
// Route: public.php?page=customer_portal&view=<home|plans|invoices|support|account>
// Auth:  Bearer JWT in Authorization header (same tokens as app_me / app_plan)
//
// Architecture (v4.13.0):
//   This file is the CONTROLLER. It:
//     1. Requires portal_data.php — the shared data loader
//     2. Renders auth-error page if $portalAuthError is set
//     3. Picks desktop vs mobile template based on UA (or ?view_mode=)
//     4. For desktop — requires views/{$view}.php (new, v4.13.0)
//     5. For mobile — renders inline HTML (byte-identical to pre-v4.13.0)
//
//   The mobile HTML below is the original 3,800+ line rendering that
//   ships today. DO NOT modify it in this turn — we will split it into
//   views_mobile/ in a future turn. For now, it stays inline so the
//   mobile portal output is guaranteed byte-identical.
//
// Native Android app (shell) embeds this in a WebView, one page per tab.
// Native provides JWT via setRequestHeader.
// Native exposes window.DishNet.* for: biometric, logout, openWhatsApp,
// openWifi, share, shake (haptic).
//
// ════════════════════════════════════════════════════════════════════

// ── Load customer data (auth + all $portal* vars). ──
// On success: sets $portalCustomer, $portalInvoices, $portalSites, etc.
// On failure: sets $portalAuthError.
require __DIR__ . '/portal_data.php';

// ── If auth failed, show the error page and return. ──
// Note: portalJsonLoad, pe(), pm() are defined in portal_data.php.
if ($portalAuthError) {
    http_response_code(401);
    setcookie('dn_customer_token', '', time() - 3600, '/');
    ?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Session expired</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font-family:'Barlow',-apple-system,sans-serif;padding:40px 20px;color:#141414;background:#F5F5F5;text-align:center}
h1{font-size:18px;margin:0 0 8px}p{color:#6B6B6B;margin:0}
.btn{display:inline-block;margin-top:20px;padding:12px 24px;background:#D41C1C;color:#fff;border-radius:12px;text-decoration:none;font-weight:700;font-size:14px}
</style>
</head><body>
<h1>Session expired</h1>
<p><?= pe($portalAuthError) ?></p>
<a class="btn" href="<?= htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?page=customer_login') ?>">Sign in again</a>
<p style="margin-top:16px;font-size:11px;color:#9B9B9B">Redirecting in 3 seconds...</p>
<script>
document.cookie = 'dn_customer_token=;path=/;max-age=0;SameSite=Lax';
setTimeout(function(){
    location.href = '<?= htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?page=customer_login') ?>';
}, 3000);
</script>
</body></html>
    <?php
    return;
}

// ════════════════════════════════════════════════════════════════════
// DESKTOP vs MOBILE DISPATCH
// ════════════════════════════════════════════════════════════════════
// Desktop view is opt-in for v4.13.0 — only served when:
//   (a) ?view_mode=desktop is in the query, OR
//   (b) user-agent is NOT a phone AND a desktop view file exists for $view
//
// Mobile UAs ALWAYS get the existing mobile template (zero regression risk).
// Native Android WebView UA contains "Mobile" → always mobile template.
// Rollout plan: enable desktop broadly in v4.14.x once the desktop views
// have been validated with real customers via ?view_mode=desktop.
// ════════════════════════════════════════════════════════════════════

$portalUA = $_SERVER['HTTP_USER_AGENT'] ?? '';
$portalIsMobileUA = (bool)preg_match('/(Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini)/i', $portalUA);

$portalViewModeOverride = $_GET['view_mode'] ?? '';
$portalRenderDesktop = false;
if ($portalViewModeOverride === 'desktop') {
    $portalRenderDesktop = true;
} elseif ($portalViewModeOverride === 'mobile') {
    $portalRenderDesktop = false;
} else {
    // Auto: desktop for non-mobile UA, mobile for mobile UA
    $portalRenderDesktop = !$portalIsMobileUA;
}

// Only render desktop if the view file actually exists — otherwise fall
// back to mobile template (graceful degradation during rollout).
if ($portalRenderDesktop) {
    $portalDesktopView = __DIR__ . '/views/' . basename($view) . '.php';
    if (is_file($portalDesktopView)) {
        require $portalDesktopView;
        return;
    }
    // No desktop view for this $view yet — fall through to mobile.
}

// ════════════════════════════════════════════════════════════════════
// MOBILE RENDER (original, byte-identical to pre-v4.13.0)
// ════════════════════════════════════════════════════════════════════
// The entire mobile HTML+CSS+JS template follows. Do NOT modify in this
// turn — the contract for v4.13.0 is that mobile output is unchanged.
// Future turn will extract these views into views_mobile/*.php.
// ════════════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════════
// PAGE RENDER
// ══════════════════════════════════════════════════════════════════
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#141414">
<title>DishNet · <?= pe(ucfirst($view)) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Barlow:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --red:#D41C1C;--red-dark:#A81515;
  --dark:#141414;--dark-2:#222;
  --gray:#6B6B6B;--gray-2:#9B9B9B;--gray-3:#C4C4C4;
  --gray-light:#EBEBEB;--off-white:#F5F5F5;--white:#fff;
  --swoosh:linear-gradient(110deg,#D41C1C 0%,#E8521A 60%,#FF7A35 100%);
  --blue:#1A4DB5;--blue-light:#E6F1FB;
  --green:#22C55E;--green-mid:#0F7A3D;--green-light:#E7F8EF;
  --amber:#EF9F27;--amber-dark:#854F0B;--amber-light:#FEF6E6;--amber-border:#F4DBB0;
  --danger-light:#FCEBEB;--danger-text:#A32D2D;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{overflow-x:hidden;overflow-y:auto}
body{font-family:'Barlow',-apple-system,sans-serif;background:var(--off-white);color:var(--dark);min-height:100vh;line-height:1.4;padding-bottom:24px}
button{font-family:inherit;cursor:pointer}

/* Dark hero header */
.scr-head{background:var(--dark);padding:14px 22px 42px;position:relative;overflow:hidden;color:#fff}
.scr-head::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 60% 80% at 85% 50%,rgba(212,28,28,.18),transparent 65%)}
.scr-head-row{display:flex;justify-content:space-between;align-items:center;position:relative;z-index:2;gap:12px;margin-bottom:12px}
.scr-btn{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;color:#fff;border:none}
.scr-title{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:18px;color:#fff}
.home-logo{font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:22px;letter-spacing:-.5px;position:relative;z-index:2}
.home-logo::after{content:'';position:absolute;bottom:-3px;left:0;right:0;height:2px;background:var(--swoosh);border-radius:2px}
.home-hello{font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:24px;letter-spacing:-.4px;position:relative;z-index:2;margin-top:14px}
.home-hello-sub{font-size:12px;color:rgba(255,255,255,.55);margin-top:2px;position:relative;z-index:2}

.scr-body{padding:0 20px 30px}

/* Home balance card */
.home-bal{background:#fff;border-radius:16px;padding:18px;margin-top:-28px;box-shadow:0 2px 8px rgba(0,0,0,.06);position:relative;z-index:3}
.home-bal-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px}
.home-bal-k{font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:1px}
.home-bal-svc{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:10px;letter-spacing:1.1px;padding:3px 8px 2px;background:var(--off-white);border-radius:4px;color:var(--dark);text-transform:uppercase}
.home-bal-main{display:flex;align-items:baseline;gap:6px}
.home-bal-num{font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:46px;color:var(--dark);letter-spacing:-1.5px;line-height:1}
.home-bal-of{font-size:14px;color:var(--gray-2);font-weight:600}
.home-bal-unlim{font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:30px;color:var(--dark);letter-spacing:-.8px;line-height:1}
.home-bal-sub{font-size:11px;color:var(--gray);margin-top:6px}
.home-bal-sub b{color:var(--dark);font-weight:700}
.home-bal-prog{height:6px;background:var(--off-white);border-radius:3px;margin-top:12px;overflow:hidden}
.home-bal-fill{height:100%;background:var(--swoosh);border-radius:3px}
.home-bal-foot{display:flex;justify-content:space-between;margin-top:10px;font-size:11px;color:var(--gray)}
.home-bal-foot b{color:var(--dark);font-weight:700}

/* v4.21.52 — Service-type pill toggle for hybrid customers (mobile) */
.svc-toggle{
  display:flex;background:var(--gray-light);border-radius:12px;
  padding:4px;margin:-14px 0 14px;position:relative;z-index:4;
}
.svc-pill{
  flex:1;padding:9px 10px;text-align:center;border-radius:9px;
  font-family:'Barlow Condensed',sans-serif;font-weight:800;
  font-size:13px;letter-spacing:.4px;color:var(--gray);
  cursor:pointer;transition:all .15s;
  display:flex;align-items:center;justify-content:center;gap:5px;
  border:none;background:transparent;text-transform:none;
}
.svc-pill.active{
  background:#fff;color:var(--dark);
  box-shadow:0 1px 3px rgba(0,0,0,.08);
}
.svc-pill .ic{width:13px;height:13px;stroke:currentColor;stroke-width:2;fill:none}

/* v4.21.52 — Fiber service badge (blue accent) */
.home-bal-svc.fiber{background:#E6F1FB;color:#1A4DB5}

/* v4.21.52 — Fiber weekly download/upload split */
.home-bal-fiber-split{margin-top:14px;padding-top:12px;border-top:1px solid var(--off-white)}
.home-bal-fiber-split .row{
  display:flex;justify-content:space-between;align-items:center;
  padding:6px 0;font-size:12px;color:var(--gray);
}
.home-bal-fiber-split .lbl{
  display:flex;align-items:center;gap:8px;font-weight:600;color:var(--dark);
}
.home-bal-fiber-split .dot{
  display:inline-block;width:10px;height:10px;border-radius:50%;
}
.home-bal-fiber-split .gb{font-weight:700;color:var(--gray)}

/* Quick actions */
.home-acts{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}
.home-acts.cols-2{grid-template-columns:repeat(2,1fr)}
.home-act{background:#fff;border-radius:14px;padding:14px 10px;display:flex;flex-direction:column;align-items:center;gap:8px;border:1px solid rgba(0,0,0,.04);cursor:pointer}
.home-act:active{transform:scale(.97)}
.home-act-ic{width:36px;height:36px;border-radius:10px;background:var(--off-white);display:flex;align-items:center;justify-content:center;color:var(--dark)}
.home-act-ic.red{background:var(--red);color:#fff}
.home-act-l{font-size:11px;font-weight:700;color:var(--dark);text-align:center}
.ic{width:18px;height:18px;display:block}

/* Due banner */
.due-banner{background:var(--amber-light);border:1px solid var(--amber-border);border-radius:12px;padding:12px 14px;display:flex;gap:10px;align-items:center;margin-top:14px;cursor:pointer}
.due-banner-ic{width:32px;height:32px;border-radius:8px;background:var(--amber);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.due-banner-t{flex:1}
.due-banner-tt{font-size:12px;font-weight:700;color:var(--amber-dark)}
.due-banner-ts{font-size:11px;color:var(--amber-dark)}

/* Section label */
.sec-lbl{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--gray-2);margin:22px 0 10px 2px;display:flex;justify-content:space-between;align-items:baseline}
.sec-lbl-meta{font-size:11px;font-weight:700;color:var(--red);text-transform:none;letter-spacing:0;cursor:pointer}

/* List card */
.list-card{background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,.04);overflow:hidden}
.list-row{display:flex;align-items:center;gap:12px;padding:13px 16px;border-bottom:1px solid var(--off-white);cursor:pointer}
.list-row:last-child{border-bottom:none}
.list-row:active{background:var(--off-white)}
.list-ic{width:34px;height:34px;border-radius:9px;background:var(--off-white);color:var(--dark);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.list-t{flex:1;min-width:0}
.list-tt{font-size:13px;font-weight:600;color:var(--dark);line-height:1.2}
.list-ts{font-size:11px;font-weight:500;color:var(--gray-2);margin-top:2px}
.list-r{flex-shrink:0}
.list-v{font-size:12px;font-weight:700;color:var(--dark)}
.chev{color:var(--gray-3);font-weight:900;font-size:18px;line-height:1;margin-left:4px}

/* Pills */
.pill{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:9px;letter-spacing:1.2px;text-transform:uppercase;padding:2px 7px 1px;border-radius:3px;display:inline-flex;align-items:center;gap:5px}
.pill.green{background:var(--green-light);color:var(--green-mid)}
.pill.amber{background:var(--amber-light);color:var(--amber-dark)}
.pill.red{background:var(--danger-light);color:var(--danger-text)}
.pill.gray{background:var(--off-white);color:var(--gray)}

/* CTAs */
.cta-red{background:var(--red);color:#fff;border:none;border-radius:14px;padding:14px;font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:13px;letter-spacing:1.1px;text-transform:uppercase;box-shadow:0 8px 24px rgba(212,28,28,.25);display:flex;align-items:center;justify-content:center;gap:7px;width:100%;margin-top:14px}
.cta-red:active{transform:scale(.98)}
.cta-alt{background:#fff;border:1px solid var(--gray-light);color:var(--dark);border-radius:14px;padding:13px;font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:7px;width:100%;margin-top:10px}

/* Plans screen */
.plan-card{background:#fff;border-radius:16px;padding:18px;margin-top:12px;border:1px solid rgba(0,0,0,.04)}
.plan-card.current{border:2px solid var(--dark)}
.plan-card.popular{border:2px solid var(--red);box-shadow:0 4px 12px rgba(212,28,28,.1)}
.plan-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.plan-name{font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:22px;letter-spacing:-.5px;color:var(--dark)}
.plan-desc{font-size:11px;color:var(--gray);margin-top:2px}
.plan-price{text-align:right}
.plan-price-v{display:flex;align-items:baseline;justify-content:flex-end;gap:2px}
.plan-price-num{font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:28px;color:var(--dark);letter-spacing:-.6px}
.plan-price-cur{font-size:14px;font-weight:700;color:var(--gray)}
.plan-price-per{font-size:10px;color:var(--gray-2);font-weight:600}

/* Invoice row */
.inv-row{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--off-white);cursor:pointer}
.inv-row:last-child{border-bottom:none}
.inv-ic{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.inv-ic.pending{background:var(--amber-light);color:var(--amber-dark)}
.inv-ic.paid{background:var(--green-light);color:var(--green-mid)}
.inv-ic.overdue{background:var(--danger-light);color:var(--danger-text)}
.inv-t{flex:1;min-width:0}
.inv-tt{font-size:13px;font-weight:600;color:var(--dark)}
.inv-ts{font-size:11px;color:var(--gray-2);margin-top:2px}
.inv-r{text-align:right;flex-shrink:0}
.inv-amt{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:18px;color:var(--dark)}

/* Account */
.acc-profile{background:#fff;border-radius:16px;padding:20px;display:flex;align-items:center;gap:14px;margin-top:-28px;box-shadow:0 2px 8px rgba(0,0,0,.06);position:relative;z-index:3}
.acc-avatar{width:54px;height:54px;border-radius:50%;background:var(--swoosh);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:22px;flex-shrink:0}
.acc-name{font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:20px;color:var(--dark);letter-spacing:-.3px}
.acc-sub{font-size:12px;color:var(--gray);margin-top:2px}

.empty{text-align:center;padding:40px 20px;color:var(--gray)}
.empty h3{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:16px;color:var(--dark);margin-bottom:6px}
.empty p{font-size:12px}

/* Support */
.wa-card{background:#25D366;color:#fff;border-radius:14px;padding:16px;display:flex;align-items:center;gap:12px;margin-top:14px;cursor:pointer}
.wa-card:active{transform:scale(.98)}
.wa-card-ic{width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.wa-card-t{flex:1}
.wa-card-tt{font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:16px;letter-spacing:-.3px}
.wa-card-ts{font-size:12px;color:rgba(255,255,255,.85);margin-top:2px}

/* Native-style toggle */
.tog{width:38px;height:22px;border-radius:11px;background:var(--gray-3);position:relative;transition:background .2s;flex-shrink:0}
.tog.on{background:var(--dark)}
.tog::after{content:'';position:absolute;top:2px;width:18px;height:18px;border-radius:50%;background:#fff;transition:all .2s}
.tog.on::after{right:2px}
.tog.off::after{left:2px}

/* Bottom tab bar (PWA only — hidden when Android native shell is present) */
.btm-nav{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid var(--gray-light);display:flex;justify-content:space-around;align-items:stretch;z-index:100;padding-bottom:env(safe-area-inset-bottom,0)}
.btm-nav.hide-native{display:none}
.btm-tab{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;padding:8px 0 6px;cursor:pointer;text-decoration:none;-webkit-tap-highlight-color:transparent;border:none;background:none;font-family:inherit}
.btm-tab svg{width:20px;height:20px;color:var(--gray-2);transition:color .15s}
.btm-tab-lbl{font-size:10px;font-weight:600;color:var(--gray-2);transition:color .15s}
.btm-tab.active svg{color:var(--red)}
.btm-tab.active .btm-tab-lbl{color:var(--red);font-weight:700}
/* Add padding to body so content isn't hidden behind fixed nav */
body{padding-bottom:68px !important}

/* ═══ Connected Devices view ═══ */
.scr-eyebrow{font-size:11px;color:rgba(255,255,255,.55);position:relative;z-index:2;margin-top:-4px}
.scr-btn-ph{width:32px;height:32px;flex-shrink:0}

.chip-row{display:flex;gap:6px;margin-top:-24px;position:relative;z-index:3;overflow-x:auto;scrollbar-width:none;padding-bottom:4px}
.chip-row::-webkit-scrollbar{display:none}
.chip{font-family:'Barlow Condensed',sans-serif;font-weight:700;font-size:11px;letter-spacing:.8px;text-transform:uppercase;padding:7px 12px 6px;border-radius:10px;background:#fff;color:var(--gray);border:1px solid var(--gray-light);white-space:nowrap;flex-shrink:0;cursor:pointer;transition:background .15s,color .15s,border-color .15s}
.chip.on{background:var(--dark);color:#fff;border-color:var(--dark)}
.chip .cnt{font-family:'Barlow',sans-serif;font-size:9px;margin-left:3px;opacity:.55}
.chip.on .cnt{opacity:.7}

.cd-row{display:flex;align-items:center;gap:12px;padding:13px 16px;border-bottom:1px solid var(--off-white);cursor:pointer}
.cd-row:last-child{border-bottom:none}
.cd-row-ic{width:38px;height:38px;border-radius:11px;background:var(--off-white);color:var(--dark);display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative}
.cd-row-ic.me{background:var(--danger-light);color:var(--danger-text)}
.cd-row-ic.blocked{background:#F1EFE8;color:var(--gray-2)}
.cd-dot{position:absolute;bottom:-2px;right:-2px;width:10px;height:10px;border-radius:50%;border:2px solid #fff}
.cd-dot.good{background:var(--green)}
.cd-dot.weak{background:var(--amber)}
.cd-dot.poor{background:var(--red)}
.cd-t{flex:1;min-width:0}
.cd-top{display:flex;align-items:baseline;gap:6px;margin-bottom:2px;flex-wrap:wrap}
.cd-name{font-size:13px;font-weight:700;color:var(--dark);line-height:1.2;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cd-tag{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:8px;letter-spacing:1.2px;text-transform:uppercase;padding:2px 6px 1px;border-radius:3px}
.cd-tag.me{background:var(--red);color:#fff}
.cd-tag.wired{background:var(--off-white);color:var(--gray)}
.cd-tag.band24{background:rgba(239,159,39,.12);color:var(--amber-dark)}
.cd-tag.band5{background:var(--green-light);color:var(--green-mid)}
.cd-sub{font-size:11px;color:var(--gray-2);display:flex;align-items:center;gap:6px}
.cd-right{text-align:right;flex-shrink:0;min-width:60px;display:flex;align-items:center;gap:6px}
.cd-bw{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:13px;color:var(--dark);line-height:1}
.cd-bw .u{font-family:'Barlow',sans-serif;font-size:9px;color:var(--gray-2);margin-left:2px}
.cd-bw-sub{font-size:9px;font-weight:600;color:var(--gray-2);margin-top:3px;text-transform:uppercase;letter-spacing:.5px}
.cd-bw-sub.heavy{color:var(--red)}
.cd-bw-sub.active{color:var(--green-mid)}
.cd-row.blocked .cd-name{text-decoration:line-through;color:var(--gray-2)}
.cd-hide{width:22px;height:22px;border-radius:50%;background:transparent;color:var(--gray-2);display:flex;align-items:center;justify-content:center;border:none;font-size:14px;opacity:.55;transition:opacity .15s,background .15s}
.cd-hide:hover,.cd-hide:active{opacity:1;background:var(--off-white)}
.cd-sys-note{text-align:center;font-size:10px;color:var(--gray-2);padding:8px;font-style:italic}

/* ═══ v4.15.0: Hotspot UI ═══ */

/* "Pause" button — sits next to .cd-hide, real network pause via dr_wifi_pause_client */
.cd-pause{width:22px;height:22px;border-radius:50%;background:transparent;color:var(--gray-2);display:flex;align-items:center;justify-content:center;border:none;cursor:pointer;opacity:.65;transition:opacity .15s,background .15s,color .15s;padding:0}
.cd-pause:hover,.cd-pause:active{opacity:1;background:var(--amber-light);color:var(--amber-dark)}
.cd-pause svg{width:13px;height:13px}
.cd-pause.is-paused{opacity:1;background:var(--amber-light);color:var(--amber-dark)}
.cd-row.is-paused{background:rgba(245,158,11,.04)}
.cd-row.is-paused .cd-name{color:var(--gray-2)}
.cd-tag.paused-tag{background:var(--amber-light);color:var(--amber-dark)}
/* v4.19.0 — NEW badge for first-time-seen devices. Red tint = "pay attention",
   matches the brand-red cue used elsewhere for important state. */
.cd-tag.new-tag{background:rgba(212,28,28,.12);color:var(--brand-red);animation:new-pulse 2s ease-in-out infinite}
@keyframes new-pulse{0%,100%{opacity:1}50%{opacity:.6}}
/* Subtle pink tint on the row so the eye lands on new devices when scanning */
.cd-row.is-new{background:rgba(212,28,28,.03)}
/* Ack button (checkmark) — sits left of the pause button. Tap = "I know this device" */
.cd-ack{width:22px;height:22px;border-radius:50%;background:var(--green-light);color:var(--green-mid);display:flex;align-items:center;justify-content:center;border:none;cursor:pointer;transition:background .15s,color .15s,transform .12s;padding:0}
.cd-ack:hover{background:var(--green);color:#fff}
.cd-ack:active{transform:scale(.9)}
.cd-ack svg{width:13px;height:13px}
/* v4.20.0 — Time-based access controls */
/* Grant button: clock icon, sits left of pause */
.cd-grant{width:22px;height:22px;border-radius:50%;background:transparent;color:var(--gray-2);display:flex;align-items:center;justify-content:center;border:none;cursor:pointer;opacity:.55;transition:opacity .15s,background .15s,color .15s;padding:0}
.cd-grant:hover,.cd-grant:active{opacity:1;background:var(--blue-light,#E9F0FB);color:var(--blue-dark,#1d4eb8)}
.cd-grant.active{opacity:1;background:var(--green-light);color:var(--green-mid)}
.cd-grant svg{width:13px;height:13px}
/* Countdown badge in row title */
.cd-timer{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:9px;letter-spacing:.6px;text-transform:uppercase;padding:2px 7px 1px;border-radius:8px;background:var(--green-light);color:var(--green-mid);display:inline-flex;align-items:center;line-height:1.2}
.cd-timer.warn{background:var(--amber-light);color:var(--amber-dark)}
.cd-timer.crit{background:rgba(212,28,28,.12);color:var(--brand-red);animation:new-pulse 1s ease-in-out infinite}
/* Row tint when device has an active grant */
.cd-row.has-grant{background:rgba(34,197,94,.03)}
/* Grant sheet chips */
.grant-chip{padding:14px 8px;border:1px solid var(--gray-light);border-radius:10px;background:#fff;color:var(--dark);font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:13px;letter-spacing:.5px;text-transform:uppercase;cursor:pointer;transition:all .15s}
.grant-chip:hover{border-color:var(--brand-red);color:var(--brand-red)}
.grant-chip:active{transform:scale(.97)}
.grant-chip:disabled{opacity:.5;cursor:not-allowed}

/* Hotspot mode card on Site Detail (entry point) */
.hs-entry{background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,.04);padding:16px;margin-top:14px;display:flex;align-items:center;gap:14px}
.hs-entry-ic{width:42px;height:42px;border-radius:11px;background:var(--dark);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.hs-entry-ic.on{background:var(--green);color:#fff}
.hs-entry-t{flex:1;min-width:0}
.hs-entry-tt{font-size:13px;font-weight:700;color:var(--dark);line-height:1.2;display:flex;align-items:center;gap:7px}
.hs-entry-ts{font-size:11px;color:var(--gray-2);margin-top:3px;line-height:1.4}
.hs-entry-cta{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:10px;letter-spacing:.8px;text-transform:uppercase;color:var(--red);background:var(--danger-light);border:none;padding:7px 11px;border-radius:8px;cursor:pointer;flex-shrink:0}
.hs-entry-cta.on{color:#fff;background:var(--green)}
/* v4.18.2 — pulse highlight when arriving at Site Detail from Hotspot tile.
   Card briefly glows red so the eye lands on it after auto-scroll. */
.hs-entry.hs-entry-pulse{animation:hs-entry-pulse 2.2s ease-out 1}
@keyframes hs-entry-pulse{
  0%  {box-shadow:0 0 0 0 rgba(220,38,38,.0); border-color:rgba(0,0,0,.04)}
  20% {box-shadow:0 0 0 6px rgba(220,38,38,.12); border-color:rgba(220,38,38,.4)}
  100%{box-shadow:0 0 0 0 rgba(220,38,38,0); border-color:rgba(0,0,0,.04)}
}

/* Hotspot dashboard (s-hotspot) — hero pill + cards */
.hs-hero-row{display:flex;align-items:baseline;gap:10px;position:relative;z-index:2;margin-top:6px}
.hs-hello{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:22px;color:#fff;letter-spacing:-.3px}
.hs-live{display:inline-flex;align-items:center;gap:7px;padding:4px 10px;background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);border-radius:20px}
.hs-live::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--green);box-shadow:0 0 0 3px rgba(34,197,94,.2)}
.hs-live span{font-size:11px;font-weight:700;color:var(--green);letter-spacing:.3px}

/* Today's WiFi card */
.sl-pw-card{margin-top:-24px;background:#fff;border-radius:18px;padding:18px 20px;box-shadow:0 2px 12px rgba(0,0,0,.07);border:1px solid rgba(0,0,0,.03);position:relative;z-index:3}
.sl-pw-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px}
.sl-pw-ssid{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:20px;color:var(--dark);letter-spacing:-.3px}
.sl-pw-k{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--gray-2);margin-bottom:4px}
.sl-pw-code{background:var(--off-white);border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:10px}
.sl-pw-code-t{flex:1;min-width:0}
.sl-pw-code-k{font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--gray-2);margin-bottom:3px}
.sl-pw-code-v{font-family:'Barlow',monospace;font-size:16px;font-weight:700;color:var(--dark);letter-spacing:2px;cursor:pointer;user-select:all;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sl-pw-code-v.bullets{letter-spacing:3px;color:var(--gray-2)}
.sl-pw-code-btn{width:30px;height:30px;border-radius:8px;background:#fff;color:var(--dark);display:flex;align-items:center;justify-content:center;cursor:pointer;border:1px solid var(--gray-light);flex-shrink:0;padding:0}
.sl-pw-code-btn:active{transform:scale(.92)}

/* Hotspot stats strip (Connected / Paused) */
.hs-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:14px}
.hs-stat{background:#fff;border:1px solid rgba(0,0,0,.05);border-radius:14px;padding:14px}
/* v4.18.4 — tappable stat card: subtle press shrink + slight border darken on hover */
.hs-stat-tap{transition:transform .12s ease, border-color .15s ease}
.hs-stat-tap:hover{border-color:rgba(0,0,0,.12)}
.hs-stat-tap:active{transform:scale(.97)}
.hs-stat-top{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.hs-stat-ic{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.hs-stat-ic.blue{background:var(--blue-light,#E9F0FB);color:var(--blue-dark,#1d4eb8)}
.hs-stat-ic.green{background:var(--green-light);color:var(--green-mid)}
.hs-stat-ic.amber{background:var(--amber-light);color:var(--amber-dark)}
.hs-stat-k{font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--gray-2)}
.hs-stat-main{display:flex;align-items:baseline;gap:5px}
.hs-stat-num{font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:26px;color:var(--dark);letter-spacing:-.6px;line-height:1}
.hs-stat-unit{font-size:11px;font-weight:600;color:var(--gray-2)}
.hs-stat-sub{font-size:11px;color:var(--gray);margin-top:4px}

/* Action grid */
.hs-acts{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:14px}
.hs-act{background:#fff;border:1px solid rgba(0,0,0,.05);border-radius:14px;padding:14px;display:flex;align-items:center;gap:10px;cursor:pointer;text-align:left;font-family:inherit;width:100%}
.hs-act:active{transform:scale(.97)}
.hs-act-ic{width:34px;height:34px;border-radius:9px;background:var(--off-white);color:var(--dark);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.hs-act-ic.red{background:var(--red);color:#fff}
.hs-act-ic.amber{background:var(--amber-light);color:var(--amber-dark)}
.hs-act-t{flex:1;min-width:0}
.hs-act-tt{font-size:12px;font-weight:700;color:var(--dark);line-height:1.15}
.hs-act-ts{font-size:10px;color:var(--gray-2);margin-top:2px}

/* Honest disclaimer banner */
.hs-honest{background:var(--blue-light,#E9F0FB);border:1px solid var(--blue-border,#BBD2F0);border-radius:12px;padding:12px 14px;margin-top:18px;display:flex;gap:10px;align-items:flex-start}
.hs-honest-t{flex:1;font-size:11px;color:var(--blue-dark,#1d4eb8);line-height:1.55}
.hs-honest-t b{color:#042C53;font-weight:700;display:block;margin-bottom:4px}
.hs-honest-cta{margin-top:10px;background:#fff;color:var(--blue-dark,#1d4eb8);border:1px solid var(--blue-border,#BBD2F0);border-radius:8px;padding:7px 12px;font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:10px;letter-spacing:.8px;text-transform:uppercase;cursor:pointer}

/* Tech tag (blue Starlink badge in eyebrow) */
.tech-tag{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:9px;letter-spacing:1.2px;padding:2px 7px 1px;border-radius:3px;line-height:1.4;text-transform:uppercase;display:inline-flex;align-items:center}
.tech-tag.sl-dark{background:#1D3A66;color:#9CC3F0;border:1px solid rgba(95,151,213,.3)}

/* Rotation card on s-hotspot-pw */
.rot-card{margin-top:-24px;background:#fff;border-radius:16px;padding:18px;position:relative;z-index:3;box-shadow:0 2px 12px rgba(0,0,0,.07);border:1px solid rgba(0,0,0,.03)}
.rot-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px}
.rot-k{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--gray-2)}
.rot-window{background:var(--off-white);border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:12px;margin-bottom:12px}
.rot-ic{width:32px;height:32px;border-radius:9px;background:#fff;color:var(--dark);display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid var(--gray-light)}
.rot-t{flex:1;min-width:0}
.rot-tt{font-size:13px;font-weight:700;color:var(--dark);margin-bottom:3px}
.rot-ts{font-size:11px;color:var(--gray);line-height:1.4}
.rot-choices{display:flex;gap:5px;margin-bottom:4px}
.rot-choice{flex:1;padding:8px 3px;text-align:center;font-family:'Barlow Condensed',sans-serif;font-weight:700;font-size:10px;color:var(--gray-2);background:var(--off-white);border-radius:9px;border:1.5px solid transparent;cursor:pointer;position:relative;letter-spacing:.4px;text-transform:uppercase}
.rot-choice.on{background:#fff;color:var(--dark);border-color:var(--dark)}
.rot-choice.disabled{opacity:.55;cursor:not-allowed;background:var(--off-white)}
.rot-choice.disabled .soon{display:block;font-size:7px;color:var(--gray-2);font-weight:700;letter-spacing:.5px;margin-top:2px;text-transform:uppercase}
.rot-choice .soon{display:none}

/* Confirm sheets (slide up from bottom) */
.hs-sheet-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;display:none;align-items:flex-end;justify-content:center}
.hs-sheet-bg.show{display:flex}
.hs-sheet{background:#fff;width:100%;max-width:480px;border-top-left-radius:20px;border-top-right-radius:20px;padding:22px 22px calc(28px + env(safe-area-inset-bottom,0));animation:hs-sheet-up .22s ease-out}
@keyframes hs-sheet-up{from{transform:translateY(100%)}to{transform:translateY(0)}}
@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}
.hs-sheet h3{font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:20px;color:var(--dark);letter-spacing:-.3px;margin:0 0 8px}
.hs-sheet p{font-size:13px;color:var(--gray);line-height:1.5;margin:0 0 14px}
.hs-sheet input[type=text]{width:100%;padding:12px 14px;border:1px solid var(--gray-light);border-radius:10px;font-size:14px;font-family:inherit;outline:none;box-sizing:border-box;margin-bottom:12px}
.hs-sheet input[type=text]:focus{border-color:var(--red)}

/* QR modal */
.hs-qr-card{background:#fff;border-radius:16px;padding:20px;text-align:center;max-width:320px;margin:0 auto}
.hs-qr-card .qr-svg{width:240px;height:240px;margin:14px auto;background:#fff;border-radius:12px;border:1px solid var(--off-white);padding:8px}
.hs-qr-ssid{font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:18px;color:var(--dark);margin-top:6px}
.hs-qr-pw{font-family:'Barlow',monospace;font-size:13px;color:var(--gray-2);margin-top:4px;letter-spacing:1.5px}

/* "Coming soon" inline badge */
.coming-soon-badge{font-family:'Barlow Condensed',sans-serif;font-weight:700;font-size:8px;letter-spacing:1px;text-transform:uppercase;background:var(--off-white);color:var(--gray-2);padding:2px 6px;border-radius:3px;display:inline-block}
</style>

<!-- SVG icon sprite -->
<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
<symbol id="i-back" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6"/></symbol>
<symbol id="i-chev-down" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></symbol>
<symbol id="i-bell" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></symbol>
<symbol id="i-plus" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M12 5v14M5 12h14"/></symbol>
<symbol id="i-speed" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></symbol>
<symbol id="i-support" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z"/></symbol>
<symbol id="i-wifi" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 12.5a10 10 0 0 1 14 0M2 8.5a15 15 0 0 1 20 0M8.5 16.5a5 5 0 0 1 7 0M12 20h.01"/></symbol>
<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 7v5l3 2"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M20 6L9 17l-5-5"/></symbol>
<symbol id="i-warn" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M10.3 3L2 17a2 2 0 0 0 1.7 3h16.6A2 2 0 0 0 22 17L13.7 3a2 2 0 0 0-3.4 0zM12 9v4.12.207h.01"/></symbol>
<symbol id="i-receipt" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 2v20l3-2 3 2 3-2 3 2 3-2 1 2V2zM8 7h8M8 11h8M8 15h6"/></symbol>
<symbol id="i-card" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M2 10h20M6 15h2M11 15h3"/></symbol>
<symbol id="i-arrow" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/></symbol>
<symbol id="i-wa" viewBox="0 0 24 24"><path fill="currentColor" d="M17.5 14c-.3-.2-1.7-.8-2-.9s-.5-.2-.7.1-.8 1-1 1.2-.3.2-.6 0-1.3-.5-2.4-1.5c-.9-.8-1.5-1.8-1.7-2.1s0-.4.1-.6c.1-.1.3-.3.4-.5s.2-.3.3-.5.1-.4 0-.5-.7-1.6-.9-2.2c-.2-.6-.5-.5-.7-.5H7.6c-.2 0-.5.1-.8.4s-1 1-1 2.4 1.1 2.8 1.2 3 2.1 3.2 5.1 4.5c.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.7-.7 2-1.4.2-.7.2-1.2.2-1.4-.1-.2-.3-.3-.6-.4M12 2C6.5 2 2 6.5 2 12c0 1.8.5 3.4 1.3 4.9L2 22l5.3-1.3c1.4.8 3 1.2 4.7 1.2 5.5 0 10-4.5 10-10S17.5 2 12 2"/></symbol>
<symbol id="i-phone" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.8.6 2.6a2 2 0 0 1-.5 2.1L8 9.6a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.8.3 1.7.5 2.6.6a2 2 0 0 1 1.7 2z"/></symbol>
<symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 21a8 8 0 0 1 16 0"/></symbol>
<symbol id="i-lock" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M7 11V7a5 5 0 0 1 10 0v4"/></symbol>
<symbol id="i-gear" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 0 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 0 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 0 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3 1.6 1.6 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 0 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8 1.6 1.6 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/></symbol>
<symbol id="i-mail" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M2 6l10 7 10-7"/></symbol>
<symbol id="i-tv" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M7 20h10M12 16v4"/></symbol>
<symbol id="i-laptop" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M8 21h8M12 17v4"/></symbol>
<symbol id="i-router" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 12h.01M10 12h.01M14 12h.01M18 12h.01"/></symbol>
<symbol id="i-block" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2.2"/><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M4.9 4.9l14.2 14.2"/></symbol>
<symbol id="i-ether" viewBox="0 0 24 24"><rect x="6" y="9" width="12" height="10" rx="1" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 9V6M15 9V6M12 9V5"/></symbol>
<!-- v4.15.0: hotspot UI icons -->
<symbol id="i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></symbol>
<symbol id="i-qr" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3z"/><path fill="currentColor" d="M14 14h2v2h-2zM18 14h3v2h-3zM14 18h2v3h-2zM18 18h3v3h-3z"/></symbol>
<symbol id="i-pause" viewBox="0 0 24 24"><rect x="6" y="4" width="4" height="16" rx="1" fill="currentColor"/><rect x="14" y="4" width="4" height="16" rx="1" fill="currentColor"/></symbol>
<symbol id="i-play" viewBox="0 0 24 24"><path fill="currentColor" d="M7 4l13 8-13 8z"/></symbol>
<symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 16v-4M12 8h.01"/></symbol>
<symbol id="i-refresh" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5"/></symbol>
<symbol id="i-power" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M18.4 6.6a9 9 0 1 1-12.8 0M12 2v10"/></symbol>
<symbol id="i-users" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></symbol>
<symbol id="i-eye" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
<symbol id="i-eye-off" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"/></symbol>
</svg>

</head>
<body>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: HOME
// ══════════════════════════════════════════════════════════════════
if ($view === 'home'):
    $isStarlink = $portalServiceType === 'starlink';
    // v4.21.52 — hybrid-aware service label. For multi-service customers,
    // header reads "Starlink + Fiber" regardless of which pill is selected.
    if (!empty($portalIsHybrid)) {
        $serviceLabel = implode(' + ', array_map('ucfirst', $portalServiceTypes ?? ['starlink']));
    } else {
        $serviceLabel = ucfirst($portalServiceType);
        if ($portalPlanName) $serviceLabel = $portalPlanName;
    }
?>
<div class="scr-head" style="padding-bottom:50px">
  <div style="display:flex;justify-content:space-between;align-items:center;position:relative;z-index:2">
    <span class="home-logo">DishNet</span>
    <button class="scr-btn" onclick="DishNet.openNotifications()"><svg class="ic" style="width:14px;height:14px"><use href="#i-bell"/></svg></button>
  </div>
  <div class="home-hello">Hi <?= pe($portalFirstName) ?> 👋</div>
  <div class="home-hello-sub"><?= pe($serviceLabel) ?> · <?= pe($portalLocation) ?></div>
  <?php
  // v4.12.20 — show active-account pill if user has multiple accounts on this phone.
  // Tapping opens the Account page where the switcher lives.
  $_hbAccounts = $portalClaims['accounts'] ?? [];
  if (is_array($_hbAccounts) && count($_hbAccounts) >= 2):
      $_hbActiveName = '';
      foreach ($_hbAccounts as $_a) {
          if ((int)($_a['id'] ?? 0) === $portalCustomerId) {
              $_hbActiveName = trim($_a['name'] ?? '') ?: ('Account #' . $portalCustomerId);
              break;
          }
      }
      if (!$_hbActiveName) $_hbActiveName = 'Account #' . $portalCustomerId;
  ?>
  <div onclick="DishNet.go('account')" style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.12);backdrop-filter:blur(4px);border:1px solid rgba(255,255,255,.18);border-radius:20px;padding:4px 12px;margin-top:8px;font-size:11px;color:rgba(255,255,255,.85);cursor:pointer;position:relative;z-index:2;max-width:100%">
    <svg class="ic" style="width:11px;height:11px;flex-shrink:0;opacity:.7"><use href="#i-user"/></svg>
    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= pe($_hbActiveName) ?></span>
    <span style="opacity:.6;font-size:10px;flex-shrink:0">· <?= count($_hbAccounts) ?> accounts · switch ›</span>
  </div>
  <?php endif; ?>
</div>

<div class="scr-body">

  <?php if (!empty($portalIsHybrid)):
    // ── Service-type pill toggle — v4.21.52 ─────────────────────────────
    // Renders only when customer has 2+ service types. Default 'Starlink'
    // (preserves existing UX for hybrid customers' first visit). Tap a
    // pill → JS writes cookie 'dn_svc_pref' → reload. The card below
    // re-renders based on $portalSelectedSvc which has flowed into
    // $portalServiceType so existing if-branches still work.
  ?>
  <div class="svc-toggle" role="tablist" aria-label="Service type">
    <?php foreach ($portalServiceTypes as $svc):
      $isActive = ($svc === $portalSelectedSvc);
    ?>
      <button type="button"
              class="svc-pill <?= $isActive ? 'active' : '' ?>"
              role="tab"
              aria-selected="<?= $isActive ? 'true' : 'false' ?>"
              data-svc="<?= pe($svc) ?>"
              onclick="dnSetSvc('<?= pe($svc) ?>')">
        <?php if ($svc === 'starlink'): ?>
          <svg class="ic" viewBox="0 0 24 24"><path d="M5 12.55a11 11 0 0 1 14.08 0M1.42 9a16 16 0 0 1 21.16 0M8.53 16.11a6 6 0 0 1 6.95 0M12 20h.01"/></svg>
        <?php elseif ($svc === 'fiber'): ?>
          <svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>
        <?php else: ?>
          <svg class="ic" viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/></svg>
        <?php endif; ?>
        <?= pe(ucfirst($svc)) ?>
      </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="home-bal">
    <?php if ($portalServiceType === 'fiber' && !empty($portalFiberUsage)):
      // ── Fiber card — v4.21.52 ──────────────────────────────────────
      // Reads dishnet-fiber-finance/data/fiber_usage_cache.json (loaded
      // into $portalFiberUsage by portal_data.php). Always shows today,
      // month, weekly download/upload split — even at zero — so the card
      // doesn't visually collapse and confuse layout.
      $fbToday  = (float)($portalFiberUsage['today_in_bytes']  ?? 0)
                + (float)($portalFiberUsage['today_out_bytes'] ?? 0);
      $fbMonth  = (float)($portalFiberUsage['month_in_bytes']  ?? 0)
                + (float)($portalFiberUsage['month_out_bytes'] ?? 0);
      $fbWeekIn = (float)($portalFiberUsage['week_in_bytes']  ?? 0);
      $fbWeekOut= (float)($portalFiberUsage['week_out_bytes'] ?? 0);
      $svcCount = (int)($portalFiberUsage['service_count'] ?? 0);
      $fbFmt = function ($b) {
          if ($b <= 0) return '0';
          if ($b > 1099511627776) return number_format($b / 1099511627776, 1) . ' TB';
          if ($b > 1073741824)    return number_format($b / 1073741824, 0)    . ' GB';
          if ($b > 1048576)       return number_format($b / 1048576, 0)       . ' MB';
          return number_format($b / 1024, 0) . ' KB';
      };
    ?>
    <div class="home-bal-top">
      <div class="home-bal-k">This Month</div>
      <div class="home-bal-svc fiber">FIBER</div>
    </div>
    <div class="home-bal-main">
      <?php if ($fbMonth > 1099511627776): ?>
        <span class="home-bal-num"><?= number_format($fbMonth / 1099511627776, 1) ?></span>
        <span class="home-bal-of">TB used</span>
      <?php elseif ($fbMonth > 0): ?>
        <span class="home-bal-num"><?= number_format($fbMonth / 1073741824, 0) ?></span>
        <span class="home-bal-of">GB used</span>
      <?php else: ?>
        <span class="home-bal-num">0</span>
        <span class="home-bal-of">GB this month</span>
      <?php endif; ?>
    </div>
    <div class="home-bal-sub">
      <b><?= pe($fbFmt($fbToday)) ?></b> today
      <?php if ($svcCount > 0): ?>
        · <b><?= (int)$svcCount ?></b> service<?= $svcCount !== 1 ? 's' : '' ?>
      <?php endif; ?>
    </div>
    <div class="home-bal-fiber-split">
      <div class="row">
        <span class="lbl"><span class="dot" style="background:#1A4DB5"></span>Download this week</span>
        <span class="gb"><?= pe($fbFmt($fbWeekIn)) ?></span>
      </div>
      <div class="row">
        <span class="lbl"><span class="dot" style="background:#22C55E"></span>Upload this week</span>
        <span class="gb"><?= pe($fbFmt($fbWeekOut)) ?></span>
      </div>
    </div>
    <div class="home-bal-foot" style="margin-top:10px">
      <span style="color:var(--red);font-weight:700;cursor:pointer" onclick="DishNet.goInternal('fiber_usage')">Usage details →</span>
      <?php if (!empty($portalFiberUsageStale)): ?>
        <span style="color:var(--gray-2);font-size:11px;">
          Updated <?= pe(date('g:i a', strtotime((string)($portalFiberUsage['updated_at'] ?? '')))) ?>
        </span>
      <?php endif; ?>
    </div>

    <?php elseif (!empty($portalSites)): ?>
    <!-- Multi-site summary -->
    <div class="home-bal-top">
      <div class="home-bal-k"><?= $portalActiveCount ?> active site<?= $portalActiveCount !== 1 ? 's' : '' ?></div>
      <div class="home-bal-svc"><?= pe(strtoupper($portalServiceType)) ?></div>
    </div>
    <?php if ($portalTotalUsageGb > 0): ?>
    <div class="home-bal-main">
      <span class="home-bal-num"><?= number_format($portalTotalUsageGb, 0) ?></span>
      <span class="home-bal-of">GB total this cycle</span>
    </div>
    <div class="home-bal-sub"><?= count($portalSites) ?> site<?= count($portalSites) !== 1 ? 's' : '' ?> · <?= $portalActiveCount ?> active · <?= count($portalSites) - $portalActiveCount ?> inactive</div>
    <?php else: ?>
    <div class="home-bal-main">
      <span class="home-bal-unlim"><?= count($portalSites) ?> Sites</span>
    </div>
    <div class="home-bal-sub"><?= $portalActiveCount ?> active · <?= count($portalSites) - $portalActiveCount ?> inactive</div>
    <?php endif; ?>
    <!-- Top sites by usage -->
    <?php
      $topSites = array_filter($portalSites, function($s) { return $s['usage_gb'] !== null && $s['usage_gb'] > 0; });
      usort($topSites, function($a, $b) { return $b['usage_gb'] - $a['usage_gb']; });
      $topSites = array_slice($topSites, 0, 3);
      if (!empty($topSites)):
    ?>
    <div style="margin-top:14px;border-top:1px solid var(--off-white);padding-top:12px">
      <?php foreach ($topSites as $ts): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:12px" onclick="DishNet.goInternal('site_detail',{kit:'<?= pe($ts['kit_number']) ?>'})">
        <span style="color:var(--dark);font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= pe($ts['location']) ?></span>
        <span style="color:var(--gray);font-weight:700;margin-left:8px;flex-shrink:0"><?= number_format($ts['usage_gb'], 0) ?> GB</span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="home-bal-foot" style="margin-top:10px">
      <span style="color:var(--red);font-weight:700;cursor:pointer" onclick="DishNet.goInternal('sites')">View all sites →</span>
      <span style="color:var(--red);font-weight:700;cursor:pointer" onclick="DishNet.openDataReport()">Usage details →</span>
    </div>
    <?php elseif ($portalUsage): ?>
    <!-- Single-service usage (non-fleet customer) -->
    <div class="home-bal-top">
      <div class="home-bal-k">Data used this cycle</div>
      <div class="home-bal-svc"><?= pe(strtoupper($portalServiceType)) ?></div>
    </div>
    <div class="home-bal-main">
      <span class="home-bal-num"><?= $portalUsage['total_gb'] ?></span>
      <span class="home-bal-of"><?php if ($portalUsage['unlimited']): ?>GB used<?php else: ?>/ <?= $portalUsage['limit_gb'] ?> GB<?php endif; ?></span>
    </div>
    <div class="home-bal-foot" style="margin-top:10px">
      <span style="color:var(--red);font-weight:700;cursor:pointer" onclick="DishNet.openDataReport()">See details →</span>
    </div>
    <?php elseif ($portalService): ?>
    <div class="home-bal-top">
      <div class="home-bal-k">Current plan</div>
      <div class="home-bal-svc"><?= pe(strtoupper($portalServiceType)) ?></div>
    </div>
    <div class="home-bal-main"><span class="home-bal-unlim"><?= pe($portalPlanName ?: 'Active') ?></span></div>
    <?php else: ?>
    <div class="home-bal-top">
      <div class="home-bal-k">No active service</div>
      <div class="home-bal-svc"><?= pe(strtoupper($portalServiceType)) ?></div>
    </div>
    <div class="home-bal-sub">Contact DishNet to activate your service.</div>
    <?php endif; ?>
  </div>

  <?php if ($portalUnpaidCount > 0): ?>
  <div class="due-banner" onclick="DishNet.go('invoices')">
    <div class="due-banner-ic"><svg class="ic"><use href="#i-clock"/></svg></div>
    <div class="due-banner-t">
      <div class="due-banner-tt"><?= $portalUnpaidCount ?> invoice<?= $portalUnpaidCount > 1 ? 's' : '' ?> due</div>
      <div class="due-banner-ts">$<?= number_format($portalUnpaidTotal, 0) ?> · Pay to keep service active</div>
    </div>
    <span class="chev">›</span>
  </div>
  <?php endif; ?>

  <?php
    // v4.21.52 — quick-action grid is hybrid-aware. "Change WiFi" only
    // applies to Starlink (we control the dish's built-in router). For
    // fiber-selected view, the tile is hidden and grid collapses to 2-col
    // (My Sites + Hotspot). Per Bhavin: "fiber customers cannot change
    // anything router-related from app".
    //
    // v4.21.56 — pure fiber-only customers (no Starlink at all, NOT hybrid):
    // My Sites and Hotspot tiles both don't apply — they have no Starlink
    // fleet and no Starlink router to hotspot. Replace the tile row with a
    // single Usage Details tile that fits the actual product.
    $isPureFiberOnly = (!$portalIsHybrid && $portalServiceType === 'fiber');
    $showWifiTile = ($portalServiceType === 'starlink');
    $tileGridClass = $showWifiTile ? 'home-acts' : 'home-acts cols-2';
  ?>
  <?php if ($isPureFiberOnly): ?>
    <!-- v4.21.56 — Single fiber-styled tile for pure fiber-only customers -->
    <div class="home-acts cols-2" style="grid-template-columns:1fr">
      <div class="home-act" onclick="DishNet.goInternal('fiber_usage')">
        <div class="home-act-ic" style="background:#E6F1FB;color:#1A4DB5">
          <svg class="ic"><use href="#i-speed"/></svg>
        </div>
        <div class="home-act-l">Usage Details</div>
      </div>
    </div>
  <?php else: ?>
  <div class="<?= pe($tileGridClass) ?>">
    <div class="home-act" onclick="DishNet.goInternal('sites')">
      <div class="home-act-ic red"><svg class="ic"><use href="#i-wifi"/></svg></div>
      <div class="home-act-l">My Sites</div>
    </div>
    <?php if ($showWifiTile): ?>
    <div class="home-act" onclick="DishNet.goInternal('wifi_change')">
      <div class="home-act-ic"><svg class="ic"><use href="#i-lock"/></svg></div>
      <div class="home-act-l">Change WiFi</div>
    </div>
    <?php endif; ?>
    <div class="home-act" id="home-hotspot-tile" onclick="DishNet.goInternal('s_hotspot_picker')" style="position:relative">
      <div class="home-act-ic" id="home-hotspot-ic"><svg class="ic"><use href="#i-power"/></svg></div>
      <div class="home-act-l">Hotspot</div>
      <span id="home-hotspot-badge" style="display:none;position:absolute;top:8px;right:8px;font-size:9px;font-weight:800;color:#fff;background:var(--green);padding:2px 6px;border-radius:8px;letter-spacing:.3px;line-height:1.4"></span>
    </div>
  </div>
  <?php endif; ?>
  <?php
  // v4.18.2 — collect router IDs for the home Hotspot status badge.
  // Fired in parallel from JS below; tally drives the green "ON" pill on
  // the Hotspot tile when one or more routers have hotspot mode active.
  $homeHotspotRouters = [];
  foreach ($portalSites as $_hhs) {
      if (!empty($_hhs['has_router']) && !empty($_hhs['router_id'])) {
          $homeHotspotRouters[] = $_hhs['router_id'];
      }
  }
  if (empty($homeHotspotRouters) && !empty($portalRouter['router_id_full'])) {
      $homeHotspotRouters[] = $portalRouter['router_id_full'];
  }
  ?>
  <?php if (!empty($homeHotspotRouters)): ?>
  <script>
  (function(){
    // Wait for DishNet.apiFetch to be ready
    function ready(cb) {
      if (window.DishNet && typeof window.DishNet.apiFetch === 'function') { cb(); return; }
      var n = 0, iv = setInterval(function(){
        n += 50;
        if (window.DishNet && typeof window.DishNet.apiFetch === 'function') { clearInterval(iv); cb(); }
        else if (n > 3000) { clearInterval(iv); }
      }, 50);
    }
    var routers = <?= json_encode(array_values($homeHotspotRouters)) ?>;
    if (!routers || !routers.length) return;

    ready(function(){
      // Fire all status fetches in parallel; tally hotspot_mode === true.
      // Don't block the page on slow rows — Promise.all with rejection
      // tolerance via .catch on each individual fetch.
      var promises = routers.map(function(rid){
        return DishNet.apiFetch(location.pathname + '?page=api&action=app_hotspot_status&router_id=' + encodeURIComponent(rid))
          .then(function(r){ return r.json(); })
          .then(function(resp){
            return !!(resp && resp.status === 'success' && resp.data && resp.data.hotspot_mode);
          })
          .catch(function(){ return false; });
      });
      Promise.all(promises).then(function(results){
        var onCount = results.filter(function(b){ return b; }).length;
        var badge = document.getElementById('home-hotspot-badge');
        var ic    = document.getElementById('home-hotspot-ic');
        if (!badge || !ic) return;
        if (onCount > 0) {
          // Show count if multi-site; just "ON" if single-site customer.
          // Single is the common case so keep it short.
          badge.textContent = (routers.length > 1 ? (onCount + ' ON') : 'ON');
          badge.style.display = '';
          // Also tint the icon background green so the tile reads as
          // "active feature" at a glance, not just a tiny corner pill.
          ic.style.background = 'var(--green-light)';
          ic.style.color      = 'var(--green-mid)';
        } else {
          // Leave default (off-white icon, no badge). Customer can still
          // tap to reach the picker and turn it on.
        }
      });
    });
  })();
  </script>
  <?php endif; ?>

  <div class="sec-lbl">Quick access</div>
  <div class="list-card">
    <?php if ($isPureFiberOnly): ?>
    <!-- v4.21.56 — fiber-only Quick Access: replaces "Active Sites" with
         "Fiber Usage". $portalFiberUsage is guaranteed non-empty when
         $isPureFiberOnly is true (set in portal_data.php detection). -->
    <?php
      $_qaFbMonth = (float)($portalFiberUsage['month_in_bytes']  ?? 0)
                  + (float)($portalFiberUsage['month_out_bytes'] ?? 0);
      $_qaFbSvc   = (int)($portalFiberUsage['service_count'] ?? 0);
      $_qaFbLabel = $_qaFbMonth > 1099511627776
          ? number_format($_qaFbMonth / 1099511627776, 1) . ' TB this month'
          : ($_qaFbMonth > 0
              ? number_format($_qaFbMonth / 1073741824, 0) . ' GB this month'
              : 'Tap to view detailed usage');
    ?>
    <div class="list-row" onclick="DishNet.goInternal('fiber_usage')">
      <div class="list-ic" style="background:#E6F1FB;color:#1A4DB5"><svg class="ic"><use href="#i-speed"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Fiber Usage</div>
        <div class="list-ts"><?= pe($_qaFbLabel) ?><?php if ($_qaFbSvc > 0): ?> · <?= (int)$_qaFbSvc ?> service<?= $_qaFbSvc !== 1 ? 's' : '' ?><?php endif; ?></div>
      </div>
      <span class="chev">›</span>
    </div>
    <?php else: ?>
    <div class="list-row" onclick="DishNet.goInternal('sites')">
      <div class="list-ic" style="background:var(--green-light);color:var(--green-mid)"><svg class="ic"><use href="#i-wifi"/></svg></div>
      <div class="list-t">
        <div class="list-tt"><?= $portalActiveCount ?> Active Site<?= $portalActiveCount !== 1 ? 's' : '' ?></div>
        <div class="list-ts"><?= count($portalSites) ?> total · Tap to manage</div>
      </div>
      <span class="chev">›</span>
    </div>
    <?php endif; ?>
    <div class="list-row" onclick="DishNet.go('invoices')">
      <div class="list-ic"><svg class="ic"><use href="#i-receipt"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Invoices</div>
        <div class="list-ts">
          <?= $portalTotalInvoiceCount ?> invoice<?= $portalTotalInvoiceCount !== 1 ? 's' : '' ?><?php if ($portalUnpaidCount): ?> · <span style="color:#FAC775"><?= $portalUnpaidCount ?> unpaid · $<?= number_format($portalUnpaidTotal, 0) ?></span><?php endif; ?>
        </div>
      </div>
      <span class="chev">›</span>
    </div>
  </div>
</div>

<?php if (!empty($portalIsHybrid)): ?>
<script>
// v4.21.52 — service-type pill toggle for hybrid customers (mobile portal).
// Sets cookie 'dn_svc_pref' (180-day max-age, path=/) then reloads so PHP
// picks up the new selection. Full reload (vs in-place swap) keeps every
// piece of the UI in sync with $portalServiceType — header subtitle, card
// markup, quick-action visibility, etc. — without mirroring PHP logic in JS.
function dnSetSvc(svc) {
  if (svc !== 'starlink' && svc !== 'fiber' && svc !== 'lte') return;
  document.cookie = 'dn_svc_pref=' + encodeURIComponent(svc) +
    '; max-age=' + (60 * 60 * 24 * 180) + '; path=/; SameSite=Lax';
  document.querySelectorAll('.svc-pill').forEach(function (el) {
    el.classList.toggle('active', el.dataset.svc === svc);
    el.setAttribute('aria-selected', el.dataset.svc === svc ? 'true' : 'false');
  });
  setTimeout(function () { window.location.reload(); }, 80);
}
</script>
<?php endif; ?>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: PLANS
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'plans'): ?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.go('home')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Plans</div>
    <div style="width:32px"></div>
  </div>
  <div style="font-size:12px;color:rgba(255,255,255,.55);position:relative;z-index:2">Upgrade or change your plan</div>
</div>

<div class="scr-body">
  <?php if ($portalService): ?>
  <div class="plan-card current">
    <div class="plan-top">
      <div>
        <div class="plan-name"><?= pe($portalPlanName ?: 'Current') ?></div>
        <div class="plan-desc">Your current plan</div>
      </div>
      <div class="plan-price">
        <div class="plan-price-v">
          <span class="plan-price-cur">$</span>
          <span class="plan-price-num"><?= number_format($portalPrice, 0) ?></span>
        </div>
        <div class="plan-price-per">/ month</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="sec-lbl" style="margin-top:20px">Other plans</div>
  <p style="font-size:13px;color:var(--gray);padding:14px 2px">
    To change your plan, contact DishNet support. We'll help you pick the right fit for your needs.
  </p>

  <button class="cta-red" onclick="DishNet.openWhatsApp('+211921443002', 'Hi DishNet, I want to change my plan.')">
    <svg class="ic" style="width:16px;height:16px"><use href="#i-wa"/></svg>
    Chat with support on WhatsApp
  </button>
</div>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: INVOICES
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'invoices'): ?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.go('home')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Invoices</div>
    <div style="width:32px"></div>
  </div>
  <div style="font-size:12px;color:rgba(255,255,255,.55);position:relative;z-index:2">
    <?= $portalTotalInvoiceCount ?> invoice<?= $portalTotalInvoiceCount === 1 ? '' : 's' ?>
    <?php if ($portalUnpaidCount): ?> · <span style="color:#FAC775"><?= $portalUnpaidCount ?> unpaid · $<?= number_format($portalUnpaidTotal, 0) ?></span><?php endif; ?>
  </div>
</div>

<div class="scr-body">
  <?php if (empty($portalInvoices)): ?>
    <div class="empty">
      <h3>No invoices yet</h3>
      <p>Your invoices will appear here.</p>
    </div>
  <?php else: ?>
    <?php if ($portalUnpaidCount > 0): ?>
      <div class="sec-lbl">Unpaid · $<?= number_format($portalUnpaidTotal, 0) ?></div>
      <div class="list-card">
        <?php foreach ($portalInvoices as $inv): if ($inv['status'] === 'paid') continue; ?>
          <div class="inv-row" onclick="DishNet.openInvoice(<?= $inv['id'] ?>)">
            <div class="inv-ic <?= pe($inv['status']) ?>">
              <svg class="ic"><use href="#i-<?= $inv['status'] === 'overdue' ? 'warn' : 'clock' ?>"/></svg>
            </div>
            <div class="inv-t">
              <div class="inv-tt"><?= pe($inv['number']) ?></div>
              <div class="inv-ts">
                <?= pe($inv['description']) ?>
                <?php if ($inv['due_date']): ?> · Due <?= date('d M', strtotime($inv['due_date'])) ?><?php endif; ?>
              </div>
            </div>
            <div class="inv-r">
              <div class="inv-amt">$<?= number_format($inv['due'], 0) ?></div>
              <span class="pill <?= $inv['status'] === 'overdue' ? 'red' : 'amber' ?>" style="font-size:9px;padding:2px 6px;margin-top:3px"><?= pe($inv['status']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php
    $hasPaid = count(array_filter($portalInvoices, function($i) { return $i['status'] === 'paid'; })) > 0;
    if ($hasPaid): ?>
      <div class="sec-lbl">Paid</div>
      <div class="list-card">
        <?php foreach ($portalInvoices as $inv): if ($inv['status'] !== 'paid') continue; ?>
          <div class="inv-row" onclick="DishNet.openInvoice(<?= $inv['id'] ?>)">
            <div class="inv-ic paid"><svg class="ic"><use href="#i-check"/></svg></div>
            <div class="inv-t">
              <div class="inv-tt"><?= pe($inv['number']) ?></div>
              <div class="inv-ts">
                <?= pe($inv['description']) ?>
                <?php if ($inv['created']): ?> · <?= date('d M Y', strtotime($inv['created'])) ?><?php endif; ?>
              </div>
            </div>
            <div class="inv-r">
              <div class="inv-amt" style="color:var(--gray)">$<?= number_format($inv['total'], 0) ?></div>
              <span class="pill green">paid</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: SUPPORT
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'support'): ?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.go('home')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Support</div>
    <div style="width:32px"></div>
  </div>
  <div style="font-size:12px;color:rgba(255,255,255,.55);position:relative;z-index:2">We respond within minutes</div>
</div>

<div class="scr-body">
  <div class="wa-card" onclick="DishNet.openWhatsApp('+211921443002', 'Hi DishNet, I need help with my service.')">
    <div class="wa-card-ic"><svg class="ic" style="width:22px;height:22px"><use href="#i-wa"/></svg></div>
    <div class="wa-card-t">
      <div class="wa-card-tt">WhatsApp Support</div>
      <div class="wa-card-ts">Fastest way to reach us</div>
    </div>
    <span class="chev" style="color:rgba(255,255,255,.6)">›</span>
  </div>

  <div class="sec-lbl" style="margin-top:18px">Other ways to reach us</div>
  <div class="list-card">
    <div class="list-row" onclick="DishNet.openPhone('+211921443002')">
      <div class="list-ic" style="background:var(--blue-light);color:var(--blue)"><svg class="ic"><use href="#i-phone"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Call us</div>
        <div class="list-ts">+211 921 443 005</div>
      </div>
      <span class="chev">›</span>
    </div>
    <div class="list-row" onclick="DishNet.openEmail('support@dishnetafrica.com')">
      <div class="list-ic"><svg class="ic"><use href="#i-mail"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Email</div>
        <div class="list-ts">support@dishnetafrica.com</div>
      </div>
      <span class="chev">›</span>
    </div>
  </div>

  <div class="sec-lbl" style="margin-top:18px">Common issues</div>
  <div class="list-card">
    <div class="list-row" onclick="DishNet.openWhatsApp('+211921443002', 'My internet is slow or disconnected.')">
      <div class="list-ic" style="background:var(--amber-light);color:var(--amber-dark)"><svg class="ic"><use href="#i-warn"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Internet slow or down</div>
      </div>
      <span class="chev">›</span>
    </div>
    <div class="list-row" onclick="DishNet.goInternal('wifi_change')">
      <div class="list-ic"><svg class="ic"><use href="#i-wifi"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Change Wi-Fi password</div>
        <div class="list-ts">Self-service</div>
      </div>
      <span class="chev">›</span>
    </div>
    <div class="list-row" onclick="DishNet.openWhatsApp('+211921443002', 'I want to pay my invoice.')">
      <div class="list-ic"><svg class="ic"><use href="#i-receipt"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Help paying invoice</div>
      </div>
      <span class="chev">›</span>
    </div>
    <div class="list-row" onclick="DishNet.goInternal('service_status')">
      <div class="list-ic" style="background:var(--green-light);color:var(--green-mid)"><svg class="ic"><use href="#i-check"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Service status</div>
        <div class="list-ts">Check if there's an outage in your area</div>
      </div>
      <span class="chev">›</span>
    </div>
  </div>
</div>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: ACCOUNT
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'account'):
    $initials = '';
    foreach (preg_split('/\s+/', $portalCustomerName) as $p) {
        if ($p) $initials .= mb_substr($p, 0, 1);
        if (mb_strlen($initials) >= 2) break;
    }
    $initials = strtoupper($initials ?: '?');
?>
<div class="scr-head" style="padding-bottom:50px">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.go('home')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Account</div>
    <div style="width:32px"></div>
  </div>
</div>

<div class="scr-body">
  <div class="acc-profile">
    <div class="acc-avatar"><?= pe($initials) ?></div>
    <div>
      <div class="acc-name"><?= pe($portalCustomerName) ?></div>
      <div class="acc-sub"><?= pe($portalClaims['phone'] ?? '') ?></div>
    </div>
  </div>

  <?php
  // v4.12.20 — Multi-account switcher. Only renders when user has 2+ CRM accounts
  // bound to the same phone. List pulled from JWT accounts claim, refreshed against
  // current client_search_index so status badges stay accurate.
  $portalAccountsList = $portalClaims['accounts'] ?? [];
  if (is_array($portalAccountsList) && count($portalAccountsList) >= 2):
      // Sort: active first, then by id
      usort($portalAccountsList, function($a, $b) {
          $as = strtolower($a['status'] ?? '') === 'active' ? 0 : 1;
          $bs = strtolower($b['status'] ?? '') === 'active' ? 0 : 1;
          if ($as !== $bs) return $as - $bs;
          return (int)($a['id'] ?? 0) - (int)($b['id'] ?? 0);
      });
  ?>
  <div class="sec-lbl" style="margin-top:18px">My Accounts (<?= count($portalAccountsList) ?>)</div>
  <div class="list-card" id="acc-switcher">
    <?php foreach ($portalAccountsList as $acct):
        $aid = (int)($acct['id'] ?? 0);
        $aname = trim($acct['name'] ?? '') ?: ('Account #' . $aid);
        $astatus = strtolower($acct['status'] ?? '');
        $aplans = trim($acct['plans'] ?? '');
        $isPrimary = ($aid === $portalCustomerId);
        // Status badge color
        $statusColor = $astatus === 'active' ? 'var(--green-mid)' :
                       ($astatus === 'suspended' ? 'var(--danger-text)' :
                       ($astatus === 'terminated' ? 'var(--gray-2)' : 'var(--amber-dark)'));
    ?>
    <div class="list-row acc-switch-row" data-account-id="<?= $aid ?>" onclick="DishNet.setActiveAccount(<?= $aid ?>)" style="cursor:pointer">
      <div class="list-ic" style="background:var(--red-light);color:var(--red)"><svg class="ic"><use href="#i-user"/></svg></div>
      <div class="list-t">
        <div class="list-tt"><?= pe($aname) ?><?php if ($aplans): ?> <span style="font-size:10px;color:var(--gray-2);font-weight:400">· <?= pe($aplans) ?></span><?php endif; ?></div>
        <div class="list-ts">
          #<?= $aid ?>
          · <span style="color:<?= $statusColor ?>;font-weight:600;text-transform:capitalize"><?= pe($astatus ?: 'unknown') ?></span>
        </div>
      </div>
      <div class="acc-switch-check" data-account-id="<?= $aid ?>" style="width:22px;height:22px;border-radius:50%;border:2px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--red);"></div>
    </div>
    <?php endforeach; ?>
  </div>
  <script>
    // Mark the currently-active account with a ✓
    (function() {
      var activeId = (window.DishNet && DishNet.activeAccountId()) || 0;
      document.querySelectorAll('.acc-switch-check').forEach(function(el) {
        if (parseInt(el.dataset.accountId, 10) === activeId) {
          el.innerHTML = '✓';
          el.style.background = 'var(--red)';
          el.style.color = '#fff';
          el.style.borderColor = 'var(--red)';
          var row = el.closest('.acc-switch-row');
          if (row) row.style.background = 'var(--red-light)';
        }
      });
    })();
  </script>
  <?php endif; ?>

  <div class="sec-lbl" style="margin-top:18px">Account</div>
  <div class="list-card">
    <div class="list-row">
      <div class="list-ic"><svg class="ic"><use href="#i-user"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Client ID</div>
        <div class="list-ts">#<?= $portalCustomerId ?></div>
      </div>
    </div>
    <?php if (!empty($portalCustomer['email'])): ?>
    <div class="list-row">
      <div class="list-ic"><svg class="ic"><use href="#i-mail"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Email</div>
        <div class="list-ts"><?= pe($portalCustomer['email']) ?></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="sec-lbl" style="margin-top:18px">Security</div>
  <div class="list-card">
    <div class="list-row" id="bio-toggle-row">
      <div class="list-ic"><svg class="ic"><use href="#i-lock"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Require biometric at app open</div>
        <div class="list-ts" id="bio-status">Loading...</div>
      </div>
      <div class="tog off" id="bio-toggle"></div>
    </div>
  </div>

  <div class="sec-lbl" style="margin-top:18px"></div>
  <div class="list-card">
    <div class="list-row" onclick="DishNet.confirmLogout()">
      <div class="list-ic" style="background:var(--danger-light);color:var(--danger-text)"><svg class="ic"><use href="#i-back"/></svg></div>
      <div class="list-t"><div class="list-tt" style="color:var(--danger-text)">Log out</div></div>
    </div>
  </div>

  <div style="text-align:center;color:var(--gray-2);font-size:11px;margin-top:30px">
    DishNet Africa · v4.12.20<br>
    <?= pe($portalClaims['phone'] ?? '') ?><br>
    <span style="cursor:pointer;color:var(--gray-3)" onclick="DishNet.goInternal('debug_panel')">Diagnostics</span>
  </div>
</div>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: INVOICE DETAIL
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'invoice_detail'):
    $inv = $portalInvoiceDetail;
    if (!$inv):
?>
<div class="scr-head">
  <div class="scr-head-row"><button class="scr-btn" onclick="DishNet.go('invoices')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button><div class="scr-title">Invoice</div><div style="width:32px"></div></div>
</div>
<div class="scr-body"><div class="empty"><h3>Invoice not found</h3><p>It may have been removed.</p></div></div>
<?php else: ?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.go('invoices')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title"><?= pe($inv['number']) ?></div>
    <div style="width:32px"></div>
  </div>
  <div style="font-size:12px;color:rgba(255,255,255,.55);position:relative;z-index:2">
    <?php if ($inv['created']): ?>Issued <?= date('d M Y', strtotime($inv['created'])) ?><?php endif; ?>
  </div>
</div>

<div class="scr-body">
  <!-- Status + amount hero -->
  <div style="background:#fff;border-radius:16px;padding:20px;margin-top:-28px;box-shadow:0 2px 8px rgba(0,0,0,.06);position:relative;z-index:3;text-align:center">
    <span class="pill <?= $inv['status'] === 'paid' ? 'green' : ($inv['status'] === 'overdue' ? 'red' : 'amber') ?>" style="font-size:11px;padding:4px 12px">
      <?= pe(strtoupper($inv['status'])) ?>
    </span>
    <div style="font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:42px;color:var(--dark);margin-top:12px;letter-spacing:-1px">
      $<?= number_format($inv['status'] === 'paid' ? $inv['total'] : $inv['due'], 0) ?>
    </div>
    <div style="font-size:12px;color:var(--gray);margin-top:4px">
      <?php if ($inv['status'] === 'paid'): ?>
        Paid in full
      <?php elseif ($inv['due_date']): ?>
        Due <?= date('d M Y', strtotime($inv['due_date'])) ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Line items -->
  <div class="sec-lbl" style="margin-top:18px">Items</div>
  <div class="list-card">
    <?php foreach ($portalInvoiceItems as $item): ?>
    <div style="padding:13px 16px;border-bottom:1px solid var(--off-white)">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:600;color:var(--dark)"><?= pe($item['label']) ?></div>
          <?php if ($item['qty'] != 1): ?>
          <div style="font-size:11px;color:var(--gray-2);margin-top:2px"><?= $item['qty'] ?> × $<?= number_format($item['price'], 2) ?></div>
          <?php endif; ?>
        </div>
        <div style="font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:16px;color:var(--dark)">$<?= number_format($item['total'], 2) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($portalInvoiceItems)): ?>
    <div style="padding:16px;text-align:center;color:var(--gray);font-size:13px">No line items</div>
    <?php endif; ?>
  </div>

  <!-- Totals -->
  <div class="list-card" style="margin-top:12px">
    <?php if ($inv['subtotal'] != $inv['total']): ?>
    <div style="padding:10px 16px;display:flex;justify-content:space-between;border-bottom:1px solid var(--off-white)">
      <span style="font-size:13px;color:var(--gray)">Subtotal</span>
      <span style="font-size:13px;font-weight:600">$<?= number_format($inv['subtotal'], 2) ?></span>
    </div>
    <?php if ($inv['tax'] > 0): ?>
    <div style="padding:10px 16px;display:flex;justify-content:space-between;border-bottom:1px solid var(--off-white)">
      <span style="font-size:13px;color:var(--gray)">Tax</span>
      <span style="font-size:13px;font-weight:600">$<?= number_format($inv['tax'], 2) ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <div style="padding:12px 16px;display:flex;justify-content:space-between;border-bottom:1px solid var(--off-white)">
      <span style="font-size:14px;font-weight:700;color:var(--dark)">Total</span>
      <span style="font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:20px;color:var(--dark)">$<?= number_format($inv['total'], 2) ?></span>
    </div>
    <?php if ($inv['paid'] > 0 && $inv['status'] !== 'paid'): ?>
    <div style="padding:10px 16px;display:flex;justify-content:space-between;border-bottom:1px solid var(--off-white)">
      <span style="font-size:13px;color:var(--green-mid)">Paid</span>
      <span style="font-size:13px;font-weight:600;color:var(--green-mid)">-$<?= number_format($inv['paid'], 2) ?></span>
    </div>
    <div style="padding:12px 16px;display:flex;justify-content:space-between">
      <span style="font-size:14px;font-weight:700;color:var(--danger-text)">Amount due</span>
      <span style="font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:20px;color:var(--danger-text)">$<?= number_format($inv['due'], 2) ?></span>
    </div>
    <?php endif; ?>
  </div>

  <!-- v4.12.20 — Invoice PDF + WhatsApp send -->
  <div class="sec-lbl" style="margin-top:18px">Invoice document</div>
  <div class="list-card" style="padding:4px">
    <div style="display:flex;gap:8px;padding:8px">
      <button onclick="DishNet.viewPdf('<?= pe(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) ?>?page=api&action=app_invoice_pdf_download&inv_id=<?= $inv['id'] ?>&account_id=<?= $portalCustomerId ?>', 'Invoice <?= pe($inv['number']) ?>')"
         style="flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 10px;background:#fff;border:1px solid var(--border);border-radius:10px;font-size:12px;font-weight:700;color:var(--dark);cursor:pointer">
        <svg class="ic" style="width:16px;height:16px"><use href="#i-receipt"/></svg>
        <span>View PDF</span>
      </button>
      <button onclick="DishNet.sendInvoiceToWA(<?= $inv['id'] ?>, '<?= pe($inv['number']) ?>')"
         id="inv-wa-btn-<?= $inv['id'] ?>"
         style="flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 10px;background:var(--red);border:none;border-radius:10px;font-size:12px;font-weight:700;color:#fff;cursor:pointer">
        <svg class="ic" style="width:16px;height:16px"><use href="#i-wa"/></svg>
        <span>Send to WhatsApp</span>
      </button>
    </div>
    <div id="inv-wa-status-<?= $inv['id'] ?>" style="font-size:11px;color:var(--gray-2);padding:0 12px 10px;display:none"></div>
  </div>

  <?php if ($inv['status'] === 'paid'): ?>
  <!-- v4.12.20 — Payment receipts (only on paid invoices) -->
  <div class="sec-lbl" style="margin-top:18px">Payment receipts</div>
  <div class="list-card" id="inv-receipts-<?= $inv['id'] ?>">
    <div style="padding:14px 16px;text-align:center;font-size:12px;color:var(--gray)" id="inv-receipts-loading-<?= $inv['id'] ?>">
      Loading receipts…
    </div>
  </div>
  <script>
    (function(invId) {
      var url = '<?= pe(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) ?>?page=api&action=app_invoice_receipts_list&inv_id=' + invId;
      DishNet.apiFetch(url)
        .then(function(r) { return r.json(); })
        .then(function(resp) {
          var host = document.getElementById('inv-receipts-' + invId);
          if (!host) return;
          if (resp.status !== 'success' || !resp.data) {
            host.innerHTML = '<div style="padding:14px 16px;text-align:center;font-size:12px;color:var(--gray)">Receipts unavailable</div>';
            return;
          }
          var pays = resp.data.payments || [];
          if (pays.length === 0) {
            host.innerHTML = '<div style="padding:14px 16px;text-align:center;font-size:12px;color:var(--gray)">No receipts on file yet. Check back after a few minutes if you just paid.</div>';
            return;
          }
          var html = '';
          pays.forEach(function(p) {
            var d = p.createdDate ? new Date(p.createdDate) : null;
            var dStr = d ? d.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : '';
            var amt = (p.amount || 0).toFixed(2);
            var method = p.method || 'Payment';
            var pdfUrl = '<?= pe(strtok($_SERVER['REQUEST_URI'] ?? '', '?')) ?>?page=api&action=app_payment_receipt_pdf&payment_id=' + p.id + '&account_id=<?= $portalCustomerId ?>';
            var titleText = 'Receipt #' + p.id;
            html += '<div style="padding:12px 16px;border-bottom:1px solid var(--off-white);display:flex;justify-content:space-between;align-items:center;gap:10px">';
            html +=   '<div style="flex:1;min-width:0">';
            html +=     '<div style="font-size:13px;font-weight:600;color:var(--dark)">$' + amt + ' <span style="font-size:11px;color:var(--gray-2);font-weight:400">· ' + DishNet._esc(method) + '</span></div>';
            html +=     '<div style="font-size:11px;color:var(--gray-2);margin-top:2px">' + DishNet._esc(dStr) + ' · Payment #' + p.id + '</div>';
            html +=   '</div>';
            html +=   '<button onclick="DishNet.viewPdf(\'' + pdfUrl.replace(/\'/g, "\\'") + '\', \'' + titleText + '\')" style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;background:var(--off-white);border-radius:6px;font-size:11px;font-weight:700;color:var(--red);border:none;cursor:pointer">';
            html +=     '<svg class="ic" style="width:12px;height:12px"><use href="#i-receipt"/></svg>';
            html +=     '<span>Receipt</span>';
            html +=   '</button>';
            html += '</div>';
          });
          host.innerHTML = html;
        })
        .catch(function() {
          var host = document.getElementById('inv-receipts-' + invId);
          if (host) host.innerHTML = '<div style="padding:14px 16px;text-align:center;font-size:12px;color:var(--gray)">Receipts unavailable</div>';
        });
    })(<?= $inv['id'] ?>);
  </script>
  <?php endif; ?>

  <?php if ($inv['status'] !== 'paid'): ?>
  <!-- Pay actions -->
  <div class="sec-lbl" style="margin-top:18px">Payment</div>
  <div class="list-card">
    <div style="padding:16px">
      <div style="font-size:13px;color:var(--dark);font-weight:600;margin-bottom:6px">Bank transfer</div>
      <div style="font-size:12px;color:var(--gray);line-height:1.6">
        Account: <b>DishNet Africa Ltd</b><br>
        Bank: <b>Stanbic Bank / Equity Bank</b><br>
        Reference: <b><?= pe($inv['number']) ?></b><br>
        Amount: <b>$<?= number_format($inv['due'], 0) ?> USD</b>
      </div>
    </div>
  </div>

  <button class="cta-red" onclick="DishNet.notifyPayment(<?= $inv['id'] ?>, '<?= pe($inv['number']) ?>', <?= $inv['due'] ?>)">
    I've paid this invoice
    <svg class="ic" style="width:14px;height:14px"><use href="#i-arrow"/></svg>
  </button>
  <p style="text-align:center;font-size:11px;color:var(--gray);margin-top:8px">This will notify our accounts team via WhatsApp</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: WIFI CHANGE
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'wifi_change'):
    // If ?router= passed (from site detail), use that specific router
    $specificRouter = trim($_GET['router'] ?? '');
    $specificKit = trim($_GET['kit'] ?? '');

    // Collect all routers for this customer (for picker)
    $allRouters = [];
    foreach ($portalSites as $ss) {
        if ($ss['has_router']) {
            $allRouters[] = [
                'kit' => $ss['kit_number'],
                'router_id' => $ss['router_id'],
                'location' => $ss['location'],
                'is_active' => $ss['is_active'],
            ];
        }
    }
    // Fallback: if multi-site router matching found nothing but legacy single-router exists,
    // add it so the user has at least one option
    if (empty($allRouters) && !empty($portalRouter)) {
        $allRouters[] = [
            'kit' => $portalRouter['kit_serial'] ?? '',
            'router_id' => $portalRouter['router_id_full'] ?? '',
            'location' => $portalRouter['sl_nickname'] ?? $portalRouter['ut_nickname'] ?? 'Starlink Router',
            'is_active' => true,
        ];
    }
    $hasMultipleRouters = count($allRouters) > 1;

    // Find the specific router or fall back to first found
    $wifiRouter = null;
    $wifiKitSerial = '';
    $wifiNick = '';
    if ($specificRouter) {
        // Came from site detail with a specific router
        $wifiRouter = ['router_id_full' => $specificRouter];
        $wifiKitSerial = $specificKit;
        // Find nick from sites
        foreach ($portalSites as $ss) {
            if ($ss['kit_number'] === $specificKit) { $wifiNick = $ss['location']; break; }
        }
    } elseif (!$hasMultipleRouters && !empty($portalRouter)) {
        // Single-router customer — use the legacy router directly
        $wifiRouter = $portalRouter;
        $wifiKitSerial = $portalRouter['kit_serial'] ?? '';
        $wifiNick = $portalRouter['sl_nickname'] ?? $portalRouter['ut_nickname'] ?? '';
    } elseif (!$hasMultipleRouters && count($allRouters) === 1) {
        // Single site found from portalSites — use it directly
        $wifiRouter = ['router_id_full' => $allRouters[0]['router_id']];
        $wifiKitSerial = $allRouters[0]['kit'] ?? '';
        $wifiNick = $allRouters[0]['location'] ?? '';
    }
    // If hasMultipleRouters and no specificRouter → wifiRouter stays null → picker shown

    $hasAnyRouter = !empty($allRouters) || !empty($wifiRouter);
?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="history.back()"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Wi-Fi Settings</div>
    <div style="width:32px"></div>
  </div>
  <div style="font-size:12px;color:rgba(255,255,255,.55);position:relative;z-index:2">
    <?= $wifiNick ? pe($wifiNick) : ($hasAnyRouter ? 'Select a site to change WiFi' : 'Change your WiFi password') ?>
  </div>
</div>

<div class="scr-body">
  <?php if (!$hasAnyRouter): ?>
    <div class="empty" style="margin-top:20px">
      <h3>No routers found</h3>
      <p>We couldn't find any Starlink routers for your account. Contact support to set up remote WiFi management.</p>
      <button class="cta-alt" onclick="DishNet.openWhatsApp('+211921443002', 'I want to change my WiFi password but the app says no routers found.')" style="margin-top:16px">Contact support</button>
    </div>
  <?php else: ?>
    <?php if ($hasMultipleRouters && !$specificRouter): ?>
    <!-- Router picker for multi-site customers -->
    <div class="sec-lbl" style="margin-top:4px">Select site</div>
    <div class="list-card">
      <?php foreach ($allRouters as $ar): ?>
      <div class="list-row" onclick="DishNet.goInternal('wifi_change',{kit:'<?= pe($ar['kit']) ?>',router:'<?= pe($ar['router_id']) ?>'})">
        <div class="list-ic" style="background:<?= $ar['is_active'] ? 'var(--green-light)' : 'var(--off-white)' ?>;color:<?= $ar['is_active'] ? 'var(--green-mid)' : 'var(--gray)' ?>"><svg class="ic"><use href="#i-wifi"/></svg></div>
        <div class="list-t">
          <div class="list-tt"><?= pe($ar['location']) ?></div>
          <div class="list-ts"><?= pe($ar['kit']) ?></div>
        </div>
        <span class="chev">›</span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="list-card" style="margin-top:-28px;position:relative;z-index:3;padding:18px">
      <div style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">Change WiFi Password</div>

      <!-- v4.12.21: Read-only SSID display shown by default (simple mode).
           Customers almost always just want to change password. The editable
           SSID field is hidden inside the "Advanced" expandable section below
           for the rare case a customer wants to rename their network.
           Submit handler still reads #wifi-ssid — in simple mode it's a hidden
           input containing the current SSID; in advanced mode it becomes
           visible and editable. -->
      <div id="wifi-name-display" style="background:var(--off-white);border-radius:10px;padding:12px 14px;margin-bottom:14px;display:flex;align-items:center;gap:12px">
        <svg class="ic" style="width:18px;height:18px;color:var(--gray);flex-shrink:0"><use href="#i-wifi"/></svg>
        <div style="flex:1;min-width:0">
          <div style="font-size:11px;color:var(--gray);margin-bottom:2px">Network name</div>
          <div id="wifi-name-display-value" style="font-size:14px;font-weight:600;color:var(--dark);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">Loading…</div>
        </div>
      </div>

      <!-- Hidden by default — becomes visible when "Advanced" is tapped.
           Note: input always exists in DOM so submitWifiChange() keeps working. -->
      <div id="wifi-ssid-advanced" style="display:none;margin-bottom:14px">
        <label style="font-size:12px;font-weight:600;color:var(--dark);display:block;margin-bottom:6px">New network name (SSID)</label>
        <input type="text" id="wifi-ssid" placeholder="e.g. DishNet-<?= pe($portalFirstName) ?>"
          style="width:100%;padding:12px 14px;border:1px solid var(--gray-light);border-radius:10px;font-size:14px;font-family:inherit;outline:none;box-sizing:border-box"
          onfocus="this.style.borderColor='var(--red)'" onblur="this.style.borderColor='var(--gray-light)'">
        <div style="font-size:11px;color:var(--warning-text,#b36b00);margin-top:6px">⚠ Changing the name means every device must reconnect.</div>
      </div>

      <div style="margin-bottom:14px">
        <label style="font-size:12px;font-weight:600;color:var(--dark);display:block;margin-bottom:6px">New password <span style="color:var(--gray-2);font-weight:400">(8-32 characters)</span></label>
        <div style="position:relative">
          <input type="password" id="wifi-pass" placeholder="Enter new password" minlength="8" maxlength="32"
            style="width:100%;padding:12px 14px;padding-right:44px;border:1px solid var(--gray-light);border-radius:10px;font-size:14px;font-family:inherit;outline:none;box-sizing:border-box"
            onfocus="this.style.borderColor='var(--red)'" onblur="this.style.borderColor='var(--gray-light)'">
          <button onclick="var p=document.getElementById('wifi-pass');p.type=p.type==='password'?'text':'password'" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--gray);font-size:18px;cursor:pointer;padding:4px">👁</button>
        </div>
      </div>

      <div style="margin-bottom:14px">
        <label style="font-size:12px;font-weight:600;color:var(--dark);display:block;margin-bottom:6px">Confirm new password</label>
        <input type="password" id="wifi-pass2" placeholder="Re-enter password"
          style="width:100%;padding:12px 14px;border:1px solid var(--gray-light);border-radius:10px;font-size:14px;font-family:inherit;outline:none;box-sizing:border-box"
          onfocus="this.style.borderColor='var(--red)'" onblur="this.style.borderColor='var(--gray-light)'">
      </div>

      <!-- Advanced toggle — lets power users expand to edit network name -->
      <div id="wifi-advanced-toggle" onclick="DishNet.toggleWifiAdvanced()"
        style="display:flex;align-items:center;gap:6px;color:var(--blue,#2563eb);font-size:13px;cursor:pointer;margin-bottom:14px;user-select:none">
        <span id="wifi-advanced-arrow">▸</span>
        <span id="wifi-advanced-label">Advanced (change network name)</span>
      </div>

      <div id="wifi-error" style="display:none;background:var(--danger-light);color:var(--danger-text);padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:14px"></div>
      <div id="wifi-success" style="display:none;background:var(--green-light);color:var(--green-mid);padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:14px"></div>

      <button class="cta-red" id="wifi-submit" onclick="DishNet.submitWifiChange()">
        <svg class="ic" style="width:16px;height:16px"><use href="#i-lock"/></svg>
        Change password
      </button>
      <p style="text-align:center;font-size:11px;color:var(--gray);margin-top:8px">
        Your devices will need the new password to reconnect. May take up to 30 seconds.
      </p>
    </div>

    <div class="sec-lbl" style="margin-top:18px">Router info</div>
    <div class="list-card">
      <div class="list-row">
        <div class="list-ic"><svg class="ic"><use href="#i-wifi"/></svg></div>
        <div class="list-t">
          <div class="list-tt"><?= pe($wifiNick ?: 'Starlink Router') ?></div>
          <div class="list-ts"><?= pe($wifiKitSerial) ?></div>
        </div>
      </div>
    </div>
  <?php endif; // end hasMultipleRouters / form ?>
  <?php endif; // end hasAnyRouter ?>

  <?php if ($wifiRouter): ?>
  <script>
  (function(){
    var routerId = '<?= pe($wifiRouter['router_id_full'] ?? '') ?>';
    var kit = '<?= pe($wifiKitSerial) ?>';
    var displayEl = document.getElementById('wifi-name-display-value');
    var inp = document.getElementById('wifi-ssid');
    if (!routerId && !kit) {
      // v4.12.21: nothing to load — show placeholder + auto-expand advanced so
      // user can at least type a new name manually.
      if (displayEl) displayEl.textContent = 'Unknown';
      // v4.12.28: toggleWifiAdvanced lives on DishNet too — defer until loaded.
      runWhenDishNetReady(function(){ DishNet.toggleWifiAdvanced(true); });
      return;
    }
    var baseUrl = location.pathname + '?page=api&action=app_wifi_get&router_id=' + encodeURIComponent(routerId) + '&kit=' + encodeURIComponent(kit);

    // v4.12.28: DEFER the fetch until window.DishNet is defined. The DishNet
    // object is defined at ~line 3165 of portal.php but this inline <script>
    // runs at ~line 1723 — BEFORE the browser has parsed the DishNet block.
    // Previously `(window.DishNet ? DishNet.apiFetch(baseUrl) : fetch(baseUrl))`
    // fell through to raw fetch() on first paint, which omits the Authorization
    // header → 401 Missing Bearer token. The v4.12.27 $pdo fix exposed this
    // because before v4.12.27 the handler 500'd before checking auth, masking
    // this second bug. Defer-until-ready closes the gap without moving code.
    function runWhenDishNetReady(cb) {
      if (window.DishNet && typeof window.DishNet.apiFetch === 'function') { cb(); return; }
      var waited = 0;
      var iv = setInterval(function(){
        waited += 50;
        if (window.DishNet && typeof window.DishNet.apiFetch === 'function') {
          clearInterval(iv); cb();
        } else if (waited > 3000) {
          clearInterval(iv); cb(); // give up waiting, let cb run (will show Unknown)
        }
      }, 50);
    }

    runWhenDishNetReady(function(){
      DishNet.apiFetch(baseUrl)
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.status === 'success' && d.data && d.data.ssid) {
          // v4.12.21: populate hidden input (so submit still works) AND the
          // read-only display card.
          if (inp && !inp.value) inp.value = d.data.ssid;
          if (displayEl) displayEl.textContent = d.data.ssid;
        } else {
          // No cached SSID — auto-expand Advanced so user can type one
          if (displayEl) displayEl.textContent = 'Not set';
          DishNet.toggleWifiAdvanced(true);
        }
      })
      .catch(function(){
        if (displayEl) displayEl.textContent = 'Unknown';
      });
    });
  })();
  </script>
  <?php endif; ?>
</div>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: FIBER_USAGE (fiber Usage Details page) — v4.21.53
// ══════════════════════════════════════════════════════════════════
// Reads $portalFiberUsage (from dishnet-fiber-finance/data/fiber_usage_cache.json,
// loaded by portal_data.php). Requires Fiber Finance v2.5.4+ for the rich
// daily_bytes / per_service_bytes / recent_sessions fields. If those are
// absent (FF still on v2.5.3), the page degrades to time-window summary
// only — no chart, no per-service breakdown, no sessions list.
elseif ($view === 'fiber_usage'):
    $fu = is_array($portalFiberUsage) ? $portalFiberUsage : [];

    // ─── Debug dump (?debug=1) ──────────────────────────────────────
    // Renders inline before the normal page so you can see what the
    // cache contains for THIS customer + cache freshness. Already
    // gated by JWT (you must be logged in to reach this view).
    $fuDebug = !empty($_GET['debug']);

    // Bytes formatter (mirrors home-card formatter, 0-safe)
    $fuFmt = function ($b) {
        $b = (float)$b;
        if ($b <= 0) return '0';
        if ($b > 1099511627776) return number_format($b / 1099511627776, 2) . ' TB';
        if ($b > 1073741824)    return number_format($b / 1073741824, 1)    . ' GB';
        if ($b > 1048576)       return number_format($b / 1048576, 0)       . ' MB';
        return number_format($b / 1024, 0) . ' KB';
    };

    // Pull rich fields with safe defaults
    $fuDaily      = is_array($fu['daily_bytes']       ?? null) ? $fu['daily_bytes']       : [];
    $fuPerService = is_array($fu['per_service_bytes'] ?? null) ? $fu['per_service_bytes'] : [];
    $fuSessions   = is_array($fu['recent_sessions']   ?? null) ? $fu['recent_sessions']   : [];

    // Chart math: max-bar normalisation. Daily total = in + out for bar height.
    $fuMaxDay = 0;
    foreach ($fuDaily as $d) {
        $tot = (float)($d['in'] ?? 0) + (float)($d['out'] ?? 0);
        if ($tot > $fuMaxDay) $fuMaxDay = $tot;
    }
?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.go('home')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Fiber Usage</div>
    <div style="width:32px"></div>
  </div>
  <div style="font-size:12px;color:rgba(255,255,255,.55);position:relative;z-index:2">
    <?php if (!empty($fu['latest_session'])): ?>
      Last session <?= pe($fu['latest_session']) ?>
    <?php else: ?>
      Detailed traffic
    <?php endif; ?>
  </div>
</div>

<div class="scr-body">
  <?php if ($fuDebug):
    // Probe the cache file directly so we can show freshness independent
    // of $portalFiberUsage (which might be empty if cache is missing).
    $fuDbgCacheFile = dirname(dirname(dirname(__DIR__))) . '/dishnet-fiber-finance/data/fiber_usage_cache.json';
    $fuDbgMetaFile  = dirname(dirname(dirname(__DIR__))) . '/dishnet-fiber-finance/data/fiber_usage_meta.json';
    $fuDbgCacheExists = is_file($fuDbgCacheFile);
    $fuDbgCacheMtime  = $fuDbgCacheExists ? date('Y-m-d H:i:s', filemtime($fuDbgCacheFile)) : '—';
    $fuDbgCacheSize   = $fuDbgCacheExists ? filesize($fuDbgCacheFile) : 0;
    $fuDbgMeta = is_file($fuDbgMetaFile) ? @json_decode(@file_get_contents($fuDbgMetaFile), true) : [];

    // Field presence audit on this customer's row
    $fuDbgFieldStatus = [];
    foreach (['today_in_bytes','today_out_bytes','week_in_bytes','week_out_bytes',
              'month_in_bytes','month_out_bytes','d14_in_bytes','d14_out_bytes',
              'all_in_bytes','all_out_bytes','session_count','service_ids',
              'service_count','daily_bytes','per_service_bytes','recent_sessions',
              'updated_at','earliest_session','latest_session'] as $_f) {
        if (!array_key_exists($_f, $fu)) {
            $fuDbgFieldStatus[$_f] = ['status' => 'MISSING', 'value' => null];
        } elseif (is_array($fu[$_f])) {
            $fuDbgFieldStatus[$_f] = ['status' => 'OK', 'value' => 'array(' . count($fu[$_f]) . ')'];
        } else {
            $fuDbgFieldStatus[$_f] = ['status' => 'OK', 'value' => (string)$fu[$_f]];
        }
    }
    $fuDbgRichOk = isset($fu['daily_bytes']) && isset($fu['per_service_bytes']) && isset($fu['recent_sessions']);
  ?>
  <div style="background:#1a1a1a;color:#0f0;border-radius:10px;padding:12px;margin-top:-20px;font-family:Menlo,Monaco,monospace;font-size:11px;line-height:1.5;position:relative;z-index:5">
    <div style="color:#ff0;font-weight:700;margin-bottom:8px;font-size:12px">🔧 FIBER USAGE DEBUG</div>

    <div style="color:#ff0;margin-top:6px">── Customer ──</div>
    <div>customer_id (JWT): <?= (int)$portalCustomerId ?></div>
    <div>name: <?= pe($portalCustomerName ?? '?') ?></div>

    <div style="color:#ff0;margin-top:6px">── Cache file ──</div>
    <div>path: …/dishnet-fiber-finance/data/fiber_usage_cache.json</div>
    <div>exists: <?= $fuDbgCacheExists ? 'YES' : '<span style="color:#f55">NO</span>' ?></div>
    <?php if ($fuDbgCacheExists): ?>
      <div>mtime: <?= pe($fuDbgCacheMtime) ?></div>
      <div>size:  <?= number_format($fuDbgCacheSize) ?> bytes</div>
    <?php endif; ?>

    <div style="color:#ff0;margin-top:6px">── Sync meta ──</div>
    <?php if (!empty($fuDbgMeta)): ?>
      <div>last_run:  <?= pe($fuDbgMeta['last_run'] ?? '?') ?></div>
      <div>fetched:   <?= (int)($fuDbgMeta['fetched']           ?? 0) ?></div>
      <div>errors:    <?= (int)($fuDbgMeta['errors']            ?? 0) ?></div>
      <div>linked:    <?= (int)($fuDbgMeta['linked_in_mapping'] ?? 0) ?> / <?= (int)($fuDbgMeta['total_in_mapping'] ?? 0) ?></div>
      <div>elapsed_s: <?= (float)($fuDbgMeta['elapsed_seconds'] ?? 0) ?></div>
    <?php else: ?>
      <div style="color:#f55">fiber_usage_meta.json missing — FF cron has not run since v2.5.4 deploy</div>
    <?php endif; ?>

    <div style="color:#ff0;margin-top:6px">── This customer's row ──</div>
    <?php if (empty($fu)): ?>
      <div style="color:#f55">$portalFiberUsage is EMPTY — customer not in cache (mapping issue or sync hasn't run)</div>
    <?php else: ?>
      <div>splynx_customer_id: <?= pe($fu['splynx_customer_id'] ?? '?') ?></div>
      <div>crm_customer_id:    <?= pe($fu['crm_customer_id']    ?? '?') ?></div>
      <div>splynx_login:       <?= pe($fu['splynx_login']       ?? '?') ?></div>
      <div>updated_at:         <?= pe($fu['updated_at']         ?? '?') ?></div>

      <div style="color:#ff0;margin-top:6px">── v2.5.4 rich fields ──</div>
      <?php if ($fuDbgRichOk): ?>
        <div style="color:#0f0">✓ ALL THREE present — page will render full</div>
        <div>daily_bytes:       <?= count($fu['daily_bytes']) ?> entries</div>
        <div>per_service_bytes: <?= count($fu['per_service_bytes']) ?> services</div>
        <div>recent_sessions:   <?= count($fu['recent_sessions']) ?> sessions</div>
      <?php else: ?>
        <div style="color:#f55">✗ MISSING rich fields — FF still on v2.5.3 OR sync has not run yet</div>
        <div>daily_bytes:       <?= isset($fu['daily_bytes'])       ? 'present' : 'MISSING' ?></div>
        <div>per_service_bytes: <?= isset($fu['per_service_bytes']) ? 'present' : 'MISSING' ?></div>
        <div>recent_sessions:   <?= isset($fu['recent_sessions'])   ? 'present' : 'MISSING' ?></div>
      <?php endif; ?>

      <div style="color:#ff0;margin-top:6px">── All fields ──</div>
      <?php foreach ($fuDbgFieldStatus as $_fn => $_fs):
        $_color = $_fs['status'] === 'MISSING' ? '#f55' : '#0f0';
      ?>
        <div><span style="color:<?= $_color ?>">[<?= $_fs['status'] ?>]</span> <?= pe($_fn) ?>: <?= pe((string)$_fs['value']) ?></div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div style="color:#888;margin-top:8px;font-size:10px;border-top:1px solid #333;padding-top:6px">
      Plugin v<?= pe($GLOBALS['_dn_plugin_version'] ?? '?') ?> · Remove ?debug=1 from URL to hide
    </div>
  </div>
  <?php endif; ?>

  <?php if (empty($fu)): ?>
    <div class="empty" style="margin-top:20px">
      <h3>No usage data yet</h3>
      <p>Fiber usage syncs every 60 minutes. If you've recently connected, please check back shortly.</p>
    </div>
  <?php else: ?>

    <!-- Hero card: this month + cumulative -->
    <div class="home-bal" style="text-align:left">
      <div class="home-bal-top">
        <div class="home-bal-k">This Month</div>
        <div class="home-bal-svc fiber">FIBER</div>
      </div>
      <?php
        $fuMonthIn  = (float)($fu['month_in_bytes']  ?? 0);
        $fuMonthOut = (float)($fu['month_out_bytes'] ?? 0);
        $fuMonthTot = $fuMonthIn + $fuMonthOut;
      ?>
      <div class="home-bal-main">
        <?php if ($fuMonthTot > 1099511627776): ?>
          <span class="home-bal-num"><?= number_format($fuMonthTot / 1099511627776, 1) ?></span>
          <span class="home-bal-of">TB total</span>
        <?php elseif ($fuMonthTot > 0): ?>
          <span class="home-bal-num"><?= number_format($fuMonthTot / 1073741824, 0) ?></span>
          <span class="home-bal-of">GB total</span>
        <?php else: ?>
          <span class="home-bal-num">0</span>
          <span class="home-bal-of">GB this month</span>
        <?php endif; ?>
      </div>
      <div class="home-bal-fiber-split" style="margin-top:14px">
        <div class="row">
          <span class="lbl"><span class="dot" style="background:#1A4DB5"></span>Download</span>
          <span class="gb"><?= pe($fuFmt($fuMonthIn)) ?></span>
        </div>
        <div class="row">
          <span class="lbl"><span class="dot" style="background:#22C55E"></span>Upload</span>
          <span class="gb"><?= pe($fuFmt($fuMonthOut)) ?></span>
        </div>
      </div>
    </div>

    <!-- Time-window breakdown -->
    <div style="background:#fff;border-radius:14px;padding:16px;margin-top:14px;box-shadow:0 2px 8px rgba(0,0,0,.04)">
      <div style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">
        Breakdown
      </div>
      <?php
        $windows = [
          ['Today',        (float)($fu['today_in_bytes']  ?? 0) + (float)($fu['today_out_bytes']  ?? 0)],
          ['This week',    (float)($fu['week_in_bytes']   ?? 0) + (float)($fu['week_out_bytes']   ?? 0)],
          ['This month',   $fuMonthTot],
          ['Last 14 days', (float)($fu['d14_in_bytes']    ?? 0) + (float)($fu['d14_out_bytes']    ?? 0)],
          ['All time',     (float)($fu['all_in_bytes']    ?? 0) + (float)($fu['all_out_bytes']    ?? 0)],
        ];
        foreach ($windows as $w):
      ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--off-white);font-size:13px">
        <span style="color:var(--dark);font-weight:600"><?= pe($w[0]) ?></span>
        <span style="color:var(--gray);font-weight:700;font-variant-numeric:tabular-nums"><?= pe($fuFmt($w[1])) ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <?php
    // ── v4.21.75: date filter + chart view toggle ──────────────────
    // fu_range: 7 | 14 | 30 (days for chart), default 30
    // fu_spage: session page number (1-based), 5 per page
    $fuRangeRaw = (int)($_GET['fu_range'] ?? 30);
    $fuRange  = in_array($fuRangeRaw, [7, 14, 30]) ? $fuRangeRaw : 30;
    $fuSPage  = max(1, (int)($_GET['fu_spage'] ?? 1));
    $fuSPerPage = 5;
    $fuChartView = ($_GET['fu_chart'] ?? 'bar') === 'area' ? 'area' : 'bar'; // reserved

    // Slice daily data to selected range
    $fuDailySlice = array_slice($fuDaily, 30 - $fuRange);

    // Recompute chart max on sliced data
    $fuMaxIn  = 0; $fuMaxOut = 0;
    foreach ($fuDailySlice as $d) {
        if ((float)($d['in']  ?? 0) > $fuMaxIn)  $fuMaxIn  = (float)$d['in'];
        if ((float)($d['out'] ?? 0) > $fuMaxOut)  $fuMaxOut = (float)$d['out'];
    }
    $fuMaxBar = max($fuMaxIn, 1);

    // Filter sessions by date range
    $fuCutoff  = date('Y-m-d', strtotime("-{$fuRange} days"));
    $fuSessFiltered = array_values(array_filter($fuSessions, function($s) use ($fuCutoff) {
        return (string)($s['start_date'] ?? '') >= $fuCutoff;
    }));
    $fuSessTotal = count($fuSessFiltered);
    $fuSessPages = max(1, (int)ceil($fuSessTotal / $fuSPerPage));
    if ($fuSPage > $fuSessPages) $fuSPage = $fuSessPages;
    $fuSessPage  = array_slice($fuSessFiltered, ($fuSPage - 1) * $fuSPerPage, $fuSPerPage);

    // URL builder helper (preserves token + view)
    $fuUrl = function(array $params) use ($fuRange, $fuSPage) {
        // v4.21.77: must include page=customer_portal so public.php routes to
        // the customer portal, not the staff app. Without it, any link click
        // (7D/14D/30D, pagination) lands on the staff interface.
        $base = '?page=customer_portal&view=fiber_usage';
        if (!empty($_GET['token'])) $base .= '&token=' . urlencode($_GET['token']);
        $merged = array_merge(['fu_range' => $fuRange, 'fu_spage' => $fuSPage], $params);
        foreach ($merged as $k => $v) $base .= '&' . $k . '=' . urlencode((string)$v);
        return $base;
    };

    // Peak day within slice
    $fuPeakDate = ''; $fuPeakIn = 0; $fuPeakOut = 0;
    foreach ($fuDailySlice as $d) {
        if ((float)($d['in'] ?? 0) > $fuPeakIn) {
            $fuPeakIn   = (float)$d['in'];
            $fuPeakOut  = (float)($d['out'] ?? 0);
            $fuPeakDate = (string)($d['date'] ?? '');
        }
    }
    // Range totals from slice
    $fuRangeIn = 0; $fuRangeOut = 0;
    foreach ($fuDailySlice as $d) { $fuRangeIn += (float)($d['in'] ?? 0); $fuRangeOut += (float)($d['out'] ?? 0); }
    ?>

    <!-- ── Date range selector ── -->
    <?php if (!empty($fuDaily)): ?>
    <div style="background:#fff;border-radius:14px;padding:14px 16px;margin-top:14px;box-shadow:0 2px 8px rgba(0,0,0,.04)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0">
        <div style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:1px">Period</div>
        <div style="display:flex;gap:6px">
          <?php foreach ([7 => '7D', 14 => '14D', 30 => '30D'] as $rv => $rl): ?>
          <a href="<?= $fuUrl(['fu_range' => $rv, 'fu_spage' => 1]) ?>"
             style="padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;text-decoration:none;
                    <?= $fuRange === $rv ? 'background:#1A4DB5;color:#fff;' : 'background:var(--off-white);color:var(--gray);' ?>"><?= $rl ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Two-tone daily chart ── v4.21.75 -->
    <?php if (!empty($fuDailySlice) && $fuMaxBar > 0): ?>
    <div style="background:#fff;border-radius:14px;padding:16px;margin-top:10px;box-shadow:0 2px 8px rgba(0,0,0,.04)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:1px">
          Daily Usage · <?= $fuRange ?>-Day
        </div>
        <div style="display:flex;align-items:center;gap:10px;font-size:10px;color:var(--gray)">
          <span><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#1A4DB5;margin-right:3px"></span>Down</span>
          <span><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:#22C55E;margin-right:3px"></span>Up</span>
        </div>
      </div>

      <!-- Two-tone stacked bars -->
      <div style="display:flex;align-items:flex-end;gap:<?= $fuRange > 14 ? '2' : '4' ?>px;height:130px;padding:0 2px" id="fuChartBars">
        <?php foreach ($fuDailySlice as $d):
          $dIn  = (float)($d['in']  ?? 0);
          $dOut = (float)($d['out'] ?? 0);
          $hIn  = $fuMaxBar > 0 ? max($dIn > 0 ? 3 : 0,  round(($dIn  / $fuMaxBar) * 120)) : 0;
          $hOut = $fuMaxBar > 0 ? max($dOut > 0 ? 2 : 0, round(($dOut / $fuMaxBar) * 120)) : 0;
          $tip  = pe($fuFmt($dIn)) . ' ↓  ' . pe($fuFmt($dOut)) . ' ↑  on ' . pe($d['date'] ?? '');
          $isPeak = ($d['date'] ?? '') === $fuPeakDate;
        ?>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;min-width:<?= $fuRange > 14 ? '4' : '8' ?>px;cursor:default"
               title="<?= $tip ?>"
               onclick="fuBarTap(this,'<?= pe($d['date'] ?? '') ?>','<?= pe($fuFmt($dIn)) ?>','<?= pe($fuFmt($dOut)) ?>')">
            <?php if ($dOut > 0): ?>
              <div style="width:100%;height:<?= $hOut ?>px;background:#22C55E;border-radius:2px 2px 0 0"></div>
            <?php endif; ?>
            <div style="width:100%;height:<?= max($dIn > 0 ? 3 : 0, $hIn - $hOut) ?>px;background:<?= $dIn > 0 ? ($isPeak ? '#0F3A9E' : '#1A4DB5') : '#E5E7EB' ?>;<?= $dOut > 0 ? '' : 'border-radius:2px 2px 0 0;' ?>"></div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- X-axis labels -->
      <div style="display:flex;justify-content:space-between;font-size:9px;color:var(--gray);margin-top:5px;padding:0 2px">
        <span><?= pe(date('M j', strtotime($fuDailySlice[0]['date'] ?? 'now'))) ?></span>
        <?php if ($fuRange >= 14): ?>
        <span><?= pe(date('M j', strtotime($fuDailySlice[(int)floor(count($fuDailySlice)/2)]['date'] ?? 'now'))) ?></span>
        <?php endif; ?>
        <span><?= pe(date('M j', strtotime(end($fuDailySlice)['date'] ?? 'now'))) ?></span>
      </div>

      <!-- Tap tooltip -->
      <div id="fuBarTip" style="display:none;margin-top:10px;padding:8px 12px;background:var(--off-white);border-radius:8px;font-size:11px">
        <span id="fuBarTipDate" style="font-weight:700;color:var(--dark)"></span>
        <span id="fuBarTipIn" style="margin-left:10px;color:#1A4DB5;font-weight:600"></span>
        <span id="fuBarTipOut" style="margin-left:8px;color:#22C55E;font-weight:600"></span>
      </div>

      <!-- Range summary row -->
      <div style="display:flex;gap:8px;margin-top:12px">
        <div style="flex:1;background:#EFF6FF;border-radius:8px;padding:8px 10px;text-align:center">
          <div style="font-size:9px;color:#1A4DB5;text-transform:uppercase;font-weight:700;letter-spacing:.5px">↓ <?= $fuRange ?>d Down</div>
          <div style="font-size:14px;font-weight:800;color:#1A4DB5;margin-top:2px;font-variant-numeric:tabular-nums"><?= pe($fuFmt($fuRangeIn)) ?></div>
        </div>
        <div style="flex:1;background:#F0FDF4;border-radius:8px;padding:8px 10px;text-align:center">
          <div style="font-size:9px;color:#16A34A;text-transform:uppercase;font-weight:700;letter-spacing:.5px">↑ <?= $fuRange ?>d Up</div>
          <div style="font-size:14px;font-weight:800;color:#16A34A;margin-top:2px;font-variant-numeric:tabular-nums"><?= pe($fuFmt($fuRangeOut)) ?></div>
        </div>
        <?php if ($fuPeakDate): ?>
        <div style="flex:1;background:#FFFBEB;border-radius:8px;padding:8px 10px;text-align:center">
          <div style="font-size:9px;color:#D97706;text-transform:uppercase;font-weight:700;letter-spacing:.5px">🏆 Peak</div>
          <div style="font-size:11px;font-weight:800;color:#D97706;margin-top:2px"><?= pe(date('M j', strtotime($fuPeakDate))) ?></div>
          <div style="font-size:10px;color:#92400E;font-variant-numeric:tabular-nums"><?= pe($fuFmt($fuPeakIn)) ?></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Avg speed badge if available -->
      <?php if (!empty($fu['avg_speed_mbps']) && (float)$fu['avg_speed_mbps'] > 0): ?>
      <div style="margin-top:8px;text-align:center">
        <span style="display:inline-block;background:var(--off-white);border-radius:20px;padding:4px 14px;font-size:11px;color:var(--gray);font-weight:600">
          Avg speed: <b style="color:var(--dark)"><?= number_format((float)$fu['avg_speed_mbps'], 2) ?> Mbps</b>
        </span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Per-service breakdown ── v4.21.78 -->
    <?php if (!empty($fuPerService)): ?>
    <?php
      // Build service filter options for chart (JS) - all + each individual service
      $fuSvcCount = count($fuPerService);
      $fuMultiSvc = $fuSvcCount > 1;
      // Pre-compute per-service daily bytes for JS chart filter
      // Group daily session data by service_id (available in recent_sessions; daily is aggregate)
      // We'll pass per_service_bytes to JS and let it filter recent_sessions for the chart
    ?>
    <div style="background:#fff;border-radius:14px;padding:16px;margin-top:14px;box-shadow:0 2px 8px rgba(0,0,0,.04)">
      <div style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px">
        Services · <?= $fuSvcCount ?> active
      </div>

      <?php foreach ($fuPerService as $svcIdx => $svc):
        $svcId    = (int)($svc['service_id'] ?? 0);
        $svcIn    = (float)($svc['in_bytes']  ?? 0);
        $svcOut   = (float)($svc['out_bytes'] ?? 0);
        $svcSess  = (int)($svc['sessions']    ?? 0);
        $svcTotal = $svcIn + $svcOut;
        $allIn    = max((float)($fu['all_in_bytes'] ?? 1), 1);
        $svcPct   = (int)round($svcIn / $allIn * 100);
        // Enrich from service index (v4.21.78)
        $svcMeta  = $portalFiberServiceIndex[(string)$svcId] ?? [];
        $svcPlan  = (string)($svcMeta['plan_name']   ?? '');
        $svcLogin = (string)($svcMeta['login']       ?? '');
        $svcIp    = (string)($svcMeta['ip_address']  ?? '');
        $svcDesc  = (string)($svcMeta['description'] ?? '');
        $svcStat  = strtolower((string)($svcMeta['status'] ?? 'active'));
        $svcStart = (string)($svcMeta['start_date']  ?? '');
        $statColor = $svcStat === 'active' ? '#16A34A' : ($svcStat === 'stopped' ? '#D97706' : '#9CA3AF');
        $statLabel = $svcStat !== '' ? ucfirst($svcStat) : 'Active';
        // Accent colour per service (cycle through palette for multi-service customers)
        $palette = ['#1A4DB5','#7C3AED','#0891B2','#D97706'];
        $accent  = $palette[$svcIdx % count($palette)];
      ?>
      <div style="border:1px solid var(--off-white);border-radius:10px;padding:14px;margin-bottom:10px">

        <!-- Service header -->
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
          <div>
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px">
              <span style="font-weight:800;color:<?= $accent ?>;font-size:13px"><?= $svcPlan !== '' ? pe($svcPlan) : 'Service #'.$svcId ?></span>
              <span style="font-size:10px;font-weight:700;color:<?= $statColor ?>;background:<?= $statColor ?>1a;padding:2px 7px;border-radius:10px"><?= pe($statLabel) ?></span>
            </div>
            <?php if ($svcLogin !== '' || $svcDesc !== ''): ?>
            <div style="font-size:11px;color:var(--gray)"><?= pe($svcLogin ?: $svcDesc) ?></div>
            <?php endif; ?>
            <?php if ($svcIp !== ''): ?>
            <div style="font-size:10px;color:var(--gray-2);margin-top:2px;font-family:monospace"><?= pe($svcIp) ?></div>
            <?php endif; ?>
          </div>
          <div style="text-align:right">
            <div style="font-weight:800;color:var(--dark);font-size:15px;font-variant-numeric:tabular-nums"><?= pe($fuFmt($svcTotal)) ?></div>
            <div style="font-size:10px;color:var(--gray);margin-top:2px"><?= $svcSess ?> session<?= $svcSess !== 1 ? 's' : '' ?></div>
          </div>
        </div>

        <!-- Share bar -->
        <div style="height:5px;background:var(--off-white);border-radius:3px;margin-bottom:8px;overflow:hidden">
          <div style="height:100%;width:<?= min(100, max(2, $svcPct)) ?>%;background:<?= $accent ?>;border-radius:3px"></div>
        </div>

        <!-- Down / Up split -->
        <div style="display:flex;gap:8px">
          <div style="flex:1;background:#EFF6FF;border-radius:6px;padding:6px 8px;text-align:center">
            <div style="font-size:9px;color:#1A4DB5;font-weight:700;text-transform:uppercase">↓ Down</div>
            <div style="font-size:12px;font-weight:800;color:#1A4DB5;font-variant-numeric:tabular-nums"><?= pe($fuFmt($svcIn)) ?></div>
          </div>
          <div style="flex:1;background:#F0FDF4;border-radius:6px;padding:6px 8px;text-align:center">
            <div style="font-size:9px;color:#16A34A;font-weight:700;text-transform:uppercase">↑ Up</div>
            <div style="font-size:12px;font-weight:800;color:#16A34A;font-variant-numeric:tabular-nums"><?= pe($fuFmt($svcOut)) ?></div>
          </div>
          <div style="flex:1;background:var(--off-white);border-radius:6px;padding:6px 8px;text-align:center">
            <div style="font-size:9px;color:var(--gray);font-weight:700;text-transform:uppercase">Share</div>
            <div style="font-size:12px;font-weight:800;color:var(--dark)"><?= $svcPct ?>%</div>
          </div>
        </div>

        <?php if ($fuMultiSvc && !empty($fuDailySlice)): ?>
        <!-- Per-service mini chart: filter daily_bytes by this service's sessions -->
        <div style="margin-top:10px">
          <div style="font-size:9px;color:var(--gray);text-transform:uppercase;font-weight:700;margin-bottom:6px">Daily · <?= $fuRange ?>d (this service)</div>
          <div id="fuSvcChart_<?= $svcId ?>" style="height:40px;display:flex;align-items:flex-end;gap:1px">
            <!-- Rendered by JS below -->
          </div>
        </div>
        <?php endif; ?>

        <?php if ($svcStart !== ''): ?>
        <div style="margin-top:8px;font-size:10px;color:var(--gray-2)">Active since <?= pe(date('M j, Y', strtotime($svcStart))) ?></div>
        <?php endif; ?>

      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Per-service mini chart JS (v4.21.78) -->
    <?php if (!empty($fuPerService) && count($fuPerService) > 1 && !empty($fuSessions)): ?>
    <script>
    (function(){
      // Build per-service daily bytes from recent_sessions
      // recent_sessions has start_date + service_id + in_bytes + out_bytes
      var sessions = <?= json_encode($fuSessions, JSON_UNESCAPED_UNICODE) ?>;
      var dailySlice = <?= json_encode($fuDailySlice, JSON_UNESCAPED_UNICODE) ?>;
      var range = <?= $fuRange ?>;
      var cutoff = dailySlice.length > 0 ? dailySlice[0].date : '';

      // Build date list from dailySlice
      var dates = dailySlice.map(function(d){ return d.date; });

      // Per-service accumulator: svcId -> {date -> {in, out}}
      var svcDaily = {};
      sessions.forEach(function(s){
        var sid = String(s.service_id || 0);
        var sd  = (s.start_date || '').substr(0,10);
        if (!sd || sd < cutoff) return;
        if (!svcDaily[sid]) svcDaily[sid] = {};
        if (!svcDaily[sid][sd]) svcDaily[sid][sd] = {in:0, out:0};
        svcDaily[sid][sd].in  += (s.in_bytes  || 0);
        svcDaily[sid][sd].out += (s.out_bytes || 0);
      });

      // Render mini charts per service
      <?php foreach ($fuPerService as $svcIdx => $svc):
        $svcId = (int)($svc['service_id'] ?? 0);
        $palette = ['#1A4DB5','#7C3AED','#0891B2','#D97706'];
        $accent  = $palette[$svcIdx % count($palette)];
      ?>
      (function(){
        var el = document.getElementById('fuSvcChart_<?= $svcId ?>');
        if (!el) return;
        var sid = '<?= $svcId ?>';
        var color = '<?= $accent ?>';
        var dayMap = svcDaily[sid] || {};
        var vals = dates.map(function(d){ return (dayMap[d] ? dayMap[d].in : 0); });
        var maxV = Math.max.apply(null, vals) || 1;
        el.innerHTML = vals.map(function(v, i){
          var h = v > 0 ? Math.max(3, Math.round(v/maxV*38)) : 1;
          var bg = v > 0 ? color : '#E5E7EB';
          return '<div style="flex:1;height:'+h+'px;background:'+bg+';border-radius:1px 1px 0 0;min-width:2px" title="'+dates[i]+': '+(v>0?(v/1073741824).toFixed(2)+' GB':'0')+'"></div>';
        }).join('');
      })();
      <?php endforeach; ?>
    })();
    </script>
    <?php endif; ?>

    <!-- ── Sessions with date filter + pagination ── v4.21.75 -->
    <?php if (!empty($fuSessions)): ?>
    <div style="background:#fff;border-radius:14px;padding:16px;margin-top:14px;box-shadow:0 2px 8px rgba(0,0,0,.04)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:1px">
          Sessions
        </div>
        <div style="font-size:11px;color:var(--gray)">
          <?= $fuSessTotal ?> in <?= $fuRange ?>d
          <?php if ($fuSessPages > 1): ?>
          · p.<?= $fuSPage ?>/<?= $fuSessPages ?>
          <?php endif; ?>
        </div>
      </div>

      <?php if (empty($fuSessPage)): ?>
        <div style="text-align:center;padding:16px 0;font-size:12px;color:var(--gray)">No sessions in this period.</div>
      <?php else: ?>
        <?php foreach ($fuSessPage as $s):
          $sd      = (string)($s['start_date'] ?? '');
          $ed      = (string)($s['end_date']   ?? '');
          $svcId   = (int)($s['service_id'] ?? 0);
          $sIn     = (float)($s['in_bytes']  ?? 0);
          $sOut    = (float)($s['out_bytes'] ?? 0);
          $sTotal  = $sIn + $sOut;
          $sdTs    = $sd ? strtotime($sd) : 0;
          $sdLabel = $sdTs > 0 ? date('M j · g:i A', $sdTs) : ($sd ?: '—');
          // Duration: prefer cached duration_secs (v2.5.8+), fallback to end_date diff
          $durSecs = (int)($s['duration_secs'] ?? 0);
          if ($durSecs <= 0 && $sd && $ed && $ed !== '0000-00-00 00:00:00') {
              $durSecs = max(0, strtotime($ed) - strtotime($sd));
          }
          $duration = '';
          if ($durSecs > 0) {
              $dh = floor($durSecs / 3600);
              $dm = floor(($durSecs % 3600) / 60);
              $duration = $dh > 0 ? "{$dh}h {$dm}m" : "{$dm}m";
          }
          // Speed for this session
          $sSpeed = '';
          if ($durSecs > 0 && $sIn > 0) {
              $mbps = round(($sIn * 8) / $durSecs / 1000000, 1);
              if ($mbps > 0) $sSpeed = $mbps . ' Mbps';
          }
        ?>
        <div style="padding:10px 0;border-bottom:1px solid var(--off-white)">
          <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:3px">
            <span style="font-weight:600;color:var(--dark);font-size:12px"><?= pe($sdLabel) ?></span>
            <span style="font-weight:700;color:var(--dark);font-size:12px;font-variant-numeric:tabular-nums"><?= pe($fuFmt($sTotal)) ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--gray)">
            <span>
              Svc #<?= $svcId ?>
              <?php if ($duration): ?> · <?= pe($duration) ?><?php endif; ?>
              <?php if ($sSpeed): ?> · <span style="color:#1A4DB5;font-weight:600"><?= pe($sSpeed) ?></span><?php endif; ?>
            </span>
            <span>↓ <?= pe($fuFmt($sIn)) ?> · ↑ <?= pe($fuFmt($sOut)) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Pagination -->
      <?php if ($fuSessPages > 1): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
        <?php if ($fuSPage > 1): ?>
          <a href="<?= $fuUrl(['fu_spage' => $fuSPage - 1]) ?>"
             style="padding:6px 16px;background:var(--off-white);border-radius:20px;font-size:12px;font-weight:700;color:var(--dark);text-decoration:none">← Newer</a>
        <?php else: ?>
          <span style="padding:6px 16px;font-size:12px;color:var(--gray)"></span>
        <?php endif; ?>
        <span style="font-size:11px;color:var(--gray)"><?= $fuSPage ?> / <?= $fuSessPages ?></span>
        <?php if ($fuSPage < $fuSessPages): ?>
          <a href="<?= $fuUrl(['fu_spage' => $fuSPage + 1]) ?>"
             style="padding:6px 16px;background:var(--off-white);border-radius:20px;font-size:12px;font-weight:700;color:var(--dark);text-decoration:none">Older →</a>
        <?php else: ?>
          <span style="padding:6px 16px;font-size:12px;color:var(--gray)"></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Footer: freshness + stale warning ── -->
    <div style="margin-top:14px;text-align:center;font-size:11px;color:var(--gray-2);padding:0 8px 8px">
      <?php if (!empty($fu['updated_at'])): ?>
        <?php
          $fuUpdTs  = strtotime((string)$fu['updated_at']);
          $fuAgoMin = (int)round((time() - $fuUpdTs) / 60);
          $fuAgoStr = $fuAgoMin < 2 ? 'just now'
                    : ($fuAgoMin < 60 ? $fuAgoMin . ' min ago'
                    : ($fuAgoMin < 1440 ? floor($fuAgoMin/60) . 'h ago'
                    : floor($fuAgoMin/1440) . 'd ago'));
        ?>
        <div>Updated <?= pe($fuAgoStr) ?>
          <?php if ($portalFiberUsageStale): ?>
            · <span style="color:var(--amber);font-weight:600">⚠ Sync overdue</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($fu['session_count'])): ?>
        <div style="margin-top:3px"><?= (int)$fu['session_count'] ?> total sessions · since <?= pe($fu['earliest_session'] ?? '') ?></div>
      <?php endif; ?>
      <?php if (empty($fuDaily) && empty($fuPerService) && empty($fuSessions)): ?>
        <div style="margin-top:8px;color:var(--amber)">Detailed breakdown unavailable. Fiber Finance v2.5.4+ required.</div>
      <?php endif; ?>
    </div>

    <!-- Bar tap JS -->
    <script>
    function fuBarTap(el, date, dlStr, ulStr) {
      var tip = document.getElementById('fuBarTip');
      document.getElementById('fuBarTipDate').textContent = date;
      document.getElementById('fuBarTipIn').textContent  = '↓ ' + dlStr;
      document.getElementById('fuBarTipOut').textContent = '↑ ' + ulStr;
      tip.style.display = 'block';
      // Highlight tapped bar
      document.querySelectorAll('#fuChartBars > div').forEach(function(b) { b.style.opacity = '0.5'; });
      el.style.opacity = '1';
    }
    </script>

  <?php endif; ?>
</div>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: USAGE (detailed usage page)
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'usage'): ?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.go('home')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Data Usage</div>
    <div style="width:32px"></div>
  </div>
  <div style="font-size:12px;color:rgba(255,255,255,.55);position:relative;z-index:2">
    <?php if ($portalUsage): ?><?= pe($portalUsage['cycle_label']) ?> cycle<?php else: ?>Usage details<?php endif; ?>
  </div>
</div>

<div class="scr-body">
  <?php if (!$portalUsage): ?>
    <div class="empty" style="margin-top:20px">
      <h3>No usage data available</h3>
      <p>Usage tracking is not yet available for your service. This will update automatically once data syncs.</p>
    </div>
  <?php else: ?>
    <!-- Big usage number -->
    <div style="background:#fff;border-radius:16px;padding:24px;margin-top:-28px;box-shadow:0 2px 8px rgba(0,0,0,.06);position:relative;z-index:3;text-align:center">
      <div style="font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:52px;color:var(--dark);letter-spacing:-1.5px;line-height:1">
        <?= $portalUsage['total_gb'] ?>
      </div>
      <div style="font-size:14px;color:var(--gray);margin-top:4px">
        <?php if ($portalUsage['unlimited']): ?>
          GB used · Unlimited plan
        <?php else: ?>
          / <?= $portalUsage['limit_gb'] ?> GB used
        <?php endif; ?>
      </div>
      <?php if ($portalUsage['pct'] !== null): ?>
      <div class="home-bal-prog" style="margin-top:16px"><div class="home-bal-fill" style="width:<?= min(100, $portalUsage['pct']) ?>%"></div></div>
      <div style="font-size:12px;color:var(--gray);margin-top:8px"><?= $portalUsage['pct'] ?>% of allowance used</div>
      <?php endif; ?>
      <div style="font-size:11px;color:var(--gray-2);margin-top:12px">
        Cycle: <?= pe($portalUsage['cycle_start']) ?> – <?= pe($portalUsage['cycle_end']) ?>
        <?php if ($portalUsage['updated']): ?><br>Last updated: <?= date('d M Y H:i', strtotime($portalUsage['updated'])) ?><?php endif; ?>
      </div>
    </div>

    <!-- Daily usage chart -->
    <?php if (!empty($portalUsage['daily'])): ?>
    <div class="sec-lbl" style="margin-top:18px">Daily usage</div>
    <div class="list-card" style="padding:18px">
      <div style="display:flex;align-items:flex-end;gap:3px;height:120px">
        <?php
          $daily = $portalUsage['daily'];
          $maxD = max(1, max($daily));
          foreach ($daily as $i => $d):
            $h = max(2, (int)round($d / $maxD * 110));
            $isLast = ($i === count($daily) - 1);
            $isZero = ($d == 0);
        ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px">
          <div style="font-size:8px;color:var(--gray-2);transform:rotate(-45deg);white-space:nowrap"><?= $d > 0 ? round($d, 1) : '' ?></div>
          <div style="width:100%;height:<?= $h ?>px;border-radius:2px;background:<?= $isLast ? 'var(--red)' : ($isZero ? 'var(--off-white)' : 'var(--gray-light)') ?>;min-width:4px"></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:10px;color:var(--gray-2)">
        <span>Day 1</span>
        <span>Day <?= count($daily) ?></span>
      </div>
    </div>

    <!-- Stats -->
    <div class="sec-lbl" style="margin-top:18px">Stats</div>
    <div class="list-card">
      <div class="list-row">
        <div class="list-ic" style="background:var(--blue-light);color:var(--blue)"><svg class="ic"><use href="#i-speed"/></svg></div>
        <div class="list-t"><div class="list-tt">Total this cycle</div><div class="list-ts"><?= $portalUsage['total_gb'] ?> GB</div></div>
      </div>
      <div class="list-row">
        <div class="list-ic"><svg class="ic"><use href="#i-clock"/></svg></div>
        <div class="list-t">
          <div class="list-tt">Daily average</div>
          <div class="list-ts"><?php
            $nonZeroDays = count(array_filter($daily, function($d) { return $d > 0; }));
            echo $nonZeroDays > 0 ? round($portalUsage['total_gb'] / $nonZeroDays, 1) : '0';
          ?> GB/day</div>
        </div>
      </div>
      <div class="list-row">
        <div class="list-ic" style="background:var(--green-light);color:var(--green-mid)"><svg class="ic"><use href="#i-check"/></svg></div>
        <div class="list-t">
          <div class="list-tt">Peak day</div>
          <div class="list-ts"><?= round(max($daily), 1) ?> GB (Day <?= array_search(max($daily), $daily) + 1 ?>)</div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: SITES (all KITs for this customer)
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'sites'): ?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.go('home')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">My Sites</div>
    <div style="width:32px"></div>
  </div>
  <div style="font-size:12px;color:rgba(255,255,255,.55);position:relative;z-index:2">
    <?= count($portalSites) ?> site<?= count($portalSites) !== 1 ? 's' : '' ?>
    · <?= $portalActiveCount - count($portalPausedSites) ?> active<?php
       if (!empty($portalPausedSites)): ?> · <?= count($portalPausedSites) ?> paused<?php endif; ?>
  </div>
</div>
<div class="scr-body">
  <?php
    // v4.21.42: 3-way split. Build a paused-KIT lookup for fast matching.
    $_pausedKitLookup = [];
    foreach (($portalPausedKits ?? []) as $_pk) $_pausedKitLookup[strtoupper($_pk)] = true;
    $_isPausedSite = function($site) use ($_pausedKitLookup) {
        $k = strtoupper(trim((string)($site['kit_number'] ?? '')));
        return $k !== '' && isset($_pausedKitLookup[$k]);
    };
    // v4.21.106: customer portal only shows CRM-derived status, not the
    // full Starlink taxonomy. Reasoning: the customer's relationship is
    // with DishNet, and CRM (UCRM) is the source of truth for that
    // relationship. Showing supplier-side state (Starlink pending /
    // suspended / ending DD/MM) would alarm customers about things they
    // can't act on and we should be handling internally. The rich
    // taxonomy still flows into portal_data.php (status_source,
    // starlink_paused, starlink_ending_soon, etc.) and is consumed by
    // the new admin Service Health view at tabs/admin/service_health.php
    // for ops-level CRM-vs-Starlink discrepancy detection.
    $_pausedSitesCount = 0;
    foreach ($portalSites as $_s) if ($_isPausedSite($_s)) $_pausedSitesCount++;
    $_activeNotPausedCount = $portalActiveCount - $_pausedSitesCount;
    $_inactiveCount = count($portalSites) - $portalActiveCount;
  ?>
  <?php if (empty($portalSites)): ?>
    <div class="empty"><h3>No sites found</h3><p>Your Starlink sites will appear here.</p></div>
  <?php else: ?>

    <?php if ($_pausedSitesCount > 0): ?>
    <div class="sec-lbl">Paused — pay to restore</div>
    <div class="list-card">
      <?php foreach ($portalSites as $site):
        if (!$_isPausedSite($site)) continue;
      ?>
      <div class="list-row" onclick="DishNet.goInternal('site_detail',{kit:'<?= pe($site['kit_number']) ?>'})">
        <div class="list-ic" style="background:#FFEBEE;color:#D41C1C"><svg class="ic"><use href="#i-clock"/></svg></div>
        <div class="list-t">
          <div class="list-tt"><?= pe($site['location']) ?></div>
          <div class="list-ts" style="color:#7A1010"><?= pe($site['kit_number']) ?> · Paused</div>
        </div>
        <div class="list-r">
          <span class="pill" style="background:#FFEBEE;color:#7A1010;border:1px solid #FFA8A8">Paused</span>
          <span class="chev">›</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($_activeNotPausedCount > 0): ?>
    <div class="sec-lbl">Active</div>
    <div class="list-card">
      <?php foreach ($portalSites as $site):
        if (!$site['is_active']) continue;
        if ($_isPausedSite($site)) continue;
      ?>
      <div class="list-row" onclick="DishNet.goInternal('site_detail',{kit:'<?= pe($site['kit_number']) ?>'})">
        <div class="list-ic" style="background:var(--green-light);color:var(--green-mid)"><svg class="ic"><use href="#i-wifi"/></svg></div>
        <div class="list-t">
          <div class="list-tt"><?= pe($site['location']) ?></div>
          <div class="list-ts"><?= pe($site['kit_number']) ?><?php if ($site['usage_gb'] !== null): ?> · <?= number_format($site['usage_gb'], 0) ?> GB<?php endif; ?></div>
        </div>
        <div class="list-r">
          <?php if ($site['has_router']): ?><span style="font-size:10px;color:var(--green-mid)">WiFi</span><?php endif; ?>
          <span class="chev">›</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($_inactiveCount > 0): ?>
    <div class="sec-lbl">Inactive</div>
    <div class="list-card">
      <?php foreach ($portalSites as $site):
        if ($site['is_active']) continue;
      ?>
      <div class="list-row" onclick="DishNet.goInternal('site_detail',{kit:'<?= pe($site['kit_number']) ?>'})">
        <div class="list-ic"><svg class="ic"><use href="#i-wifi"/></svg></div>
        <div class="list-t">
          <div class="list-tt"><?= pe($site['location']) ?></div>
          <div class="list-ts"><?= pe($site['kit_number']) ?></div>
        </div>
        <div class="list-r">
          <span class="pill gray">Inactive</span>
          <span class="chev">›</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  <?php endif; ?>
</div>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: SITE DETAIL (single KIT)
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'site_detail'):
    $siteKit = trim($_GET['kit'] ?? '');
    $site = null;
    foreach ($portalSites as $s) {
        if ($s['kit_number'] === $siteKit) { $site = $s; break; }
    }
    if (!$site):
?>
<div class="scr-head">
  <div class="scr-head-row"><button class="scr-btn" onclick="DishNet.goInternal('sites')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button><div class="scr-title">Site</div><div style="width:32px"></div></div>
</div>
<div class="scr-body"><div class="empty"><h3>Site not found</h3></div></div>
<?php else: ?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.goInternal('sites')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title"><?= pe($site['location']) ?></div>
    <div style="width:32px"></div>
  </div>
  <div style="font-size:12px;color:rgba(255,255,255,.55);position:relative;z-index:2">
    <?= pe($site['kit_number']) ?> · <span style="color:<?= $site['is_active'] ? '#4ade80' : 'rgba(255,255,255,.3)' ?>"><?= $site['is_active'] ? 'Active' : 'Inactive' ?></span>
  </div>
</div>
<div class="scr-body">
  <!-- Usage card -->
  <div class="home-bal" style="margin-top:-28px;position:relative;z-index:3">
    <?php if ($site['usage_gb'] !== null): ?>
    <div class="home-bal-top">
      <div class="home-bal-k">Data used this cycle</div>
      <div class="home-bal-svc"><?= $site['is_active'] ? 'ACTIVE' : 'INACTIVE' ?></div>
    </div>
    <div class="home-bal-main">
      <span class="home-bal-num"><?= number_format($site['usage_gb'], 0) ?></span>
      <span class="home-bal-of">GB</span>
    </div>
    <div class="home-bal-sub"><?= pe($site['usage_cycle']) ?><?php if ($site['usage_updated']): ?> · Updated <?= date('d M H:i', strtotime($site['usage_updated'])) ?><?php endif; ?></div>
    <?php else: ?>
    <div class="home-bal-top">
      <div class="home-bal-k">No usage data</div>
      <div class="home-bal-svc"><?= $site['is_active'] ? 'ACTIVE' : 'INACTIVE' ?></div>
    </div>
    <div class="home-bal-sub">Usage will appear once the billing cycle syncs.</div>
    <?php endif; ?>
  </div>

  <!-- ═══ Dish Status card (v4.12.29, reworked v4.12.31) ═══ -->
  <!-- Loaded async from app_site_diagnostics. Three states:
       online (green) — live or fresh cache, dish is reachable
       offline (red) — live call got genuine dish_unreachable error
       unavailable (grey) — our-side issue (auth/infra/rate limit)
       Hidden until the fetch completes so there's no flash of empty card. -->
  <div id="site-dish-status" style="display:none;background:#fff;border-radius:14px;margin-top:14px;padding:16px;border:1px solid rgba(0,0,0,.04)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <div style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:1px">Dish status</div>
      <div id="site-dish-age" style="font-size:11px;color:var(--gray-2)"></div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
      <div id="site-dish-dot" style="width:12px;height:12px;border-radius:50%;background:var(--gray-3);flex-shrink:0"></div>
      <div id="site-dish-label" style="font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:24px;color:var(--dark);letter-spacing:-.4px">—</div>
    </div>
    <div id="site-dish-hint" style="font-size:11px;color:var(--gray-2);margin-top:4px"></div>
    <!-- v4.12.31: Refresh button — hidden during in-flight fetch to prevent double-tap -->
    <div style="margin-top:12px;display:flex;align-items:center;gap:8px">
      <button id="site-dish-refresh-btn" type="button"
              onclick="dishRefresh()"
              style="background:var(--off-white);border:1px solid rgba(0,0,0,.06);border-radius:999px;padding:6px 14px;font-size:12px;font-weight:700;color:var(--dark);cursor:pointer;display:inline-flex;align-items:center;gap:6px">
        <span id="site-dish-refresh-ic">↻</span>
        <span id="site-dish-refresh-lbl">Refresh</span>
      </button>
    </div>
  </div>

  <!-- Quick actions for this site -->
  <div class="home-acts" style="grid-template-columns:repeat(2,1fr);margin-top:14px">
    <div class="home-act" onclick="DishNet.openDataReport('<?= pe($site['kit_number']) ?>')">
      <div class="home-act-ic"><svg class="ic"><use href="#i-speed"/></svg></div>
      <div class="home-act-l">Usage Details</div>
    </div>
    <div class="home-act" onclick="DishNet.goInternal('wifi_site',{kit:'<?= pe($site['kit_number']) ?>',router:'<?= pe($site['router_id'] ?: ($portalRouter['router_id_full'] ?? '')) ?>'})">
      <div class="home-act-ic"><svg class="ic"><use href="#i-wifi"/></svg></div>
      <div class="home-act-l">Change WiFi</div>
    </div>
  </div>
  <script>window._siteRouterId = '<?= pe($site['router_id'] ?: ($portalRouter['router_id_full'] ?? '')) ?>'; window._siteLocation = '<?= pe($site['location'] ?? '') ?>';</script>

  <!-- Site info -->
  <div class="sec-lbl">Site info</div>
  <div class="list-card">
    <div class="list-row">
      <div class="list-ic"><svg class="ic"><use href="#i-wifi"/></svg></div>
      <div class="list-t"><div class="list-tt">KIT Number</div><div class="list-ts"><?= pe($site['kit_number']) ?></div></div>
    </div>
    <?php if ($site['service_line']): ?>
    <div class="list-row">
      <div class="list-ic"><svg class="ic"><use href="#i-speed"/></svg></div>
      <div class="list-t"><div class="list-tt">Service Line</div><div class="list-ts"><?= pe($site['service_line']) ?></div></div>
    </div>
    <?php endif; ?>
    <?php if ($site['plan']): ?>
    <div class="list-row">
      <div class="list-ic"><svg class="ic"><use href="#i-receipt"/></svg></div>
      <div class="list-t"><div class="list-tt">Plan</div><div class="list-ts"><?= pe($site['plan']) ?></div></div>
    </div>
    <?php endif; ?>
    <?php if ($site['has_router']): ?>
    <div class="list-row">
      <div class="list-ic" style="background:var(--green-light);color:var(--green-mid)"><svg class="ic"><use href="#i-lock"/></svg></div>
      <div class="list-t"><div class="list-tt">WiFi Router</div><div class="list-ts"><?= pe($site['router_nick'] ?: 'Connected') ?></div></div>
    </div>
    <?php endif; ?>
    <!-- v4.12.29: Hardware/software info (populated async from diagnostics) -->
    <div class="list-row" id="site-hw-row" style="display:none">
      <div class="list-ic"><svg class="ic"><use href="#i-gear"/></svg></div>
      <div class="list-t"><div class="list-tt">Hardware</div><div class="list-ts" id="site-hw-label">—</div></div>
    </div>
  </div>

  <!-- v4.12.29: Load dish diagnostics async. Fetches app_site_diagnostics,
       populates dish status card + hardware row. Fails silently (keeps the
       card hidden) if the endpoint returns an error — customer doesn't need
       to see "diagnostics unavailable" noise when the core Site Detail is
       working fine. -->
  <script>
  (function(){
    var kit = '<?= pe($site['kit_number']) ?>';
    if (!kit) return;

    function runWhenDishNetReady(cb) {
      if (window.DishNet && typeof window.DishNet.apiFetch === 'function') { cb(); return; }
      var waited = 0;
      var iv = setInterval(function(){
        waited += 50;
        if (window.DishNet && typeof window.DishNet.apiFetch === 'function') {
          clearInterval(iv); cb();
        } else if (waited > 3000) {
          clearInterval(iv);
        }
      }, 50);
    }

    function formatAge(seconds) {
      if (seconds === null || seconds === undefined) return '';
      if (seconds < 60) return 'just now';
      if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
      if (seconds < 86400) return Math.round(seconds / 3600 * 10) / 10 + 'h ago';
      return Math.round(seconds / 86400) + 'd ago';
    }

    runWhenDishNetReady(function(){
      DishNet.apiFetch(location.pathname + '?page=api&action=app_site_diagnostics&kit=' + encodeURIComponent(kit))
        .then(function(r){ return r.json(); })
        .then(function(resp){
          if (!resp || resp.status !== 'success' || !resp.data) return;
          renderDishStatus(resp.data);

          // ── Hardware row ──
          if (resp.data.device && resp.data.device.hardware_version) {
            var hwRow = document.getElementById('site-hw-row');
            var hwLbl = document.getElementById('site-hw-label');
            if (hwLbl) hwLbl.textContent = resp.data.device.hardware_version;
            if (hwRow) hwRow.style.display = 'flex';
          }
        })
        .catch(function(){ /* silent — site detail still works without diagnostics */ });
    });

    // ── v4.12.31: Three-state dish status renderer ──
    // state values from backend (ca_site_dish_resolve):
    //   online      — call succeeded OR cache < 15 min
    //   offline     — live fetch returned dish_unreachable (genuine dish problem)
    //   unavailable — our-side failure (auth/infra/rate_limited) — never say "offline"
    // source values: cache | live | rate_limited | no_map_entry
    window.renderDishStatus = function(data) {
      if (!data || !data.dish || !data.dish.state) return;
      var state  = data.dish.state;
      var source = data.dish.source || '';
      var errorType = data.dish.error_type || null;

      var stateMap = {
        online:      { label: 'Online',             color: '#22C55E', hint: 'Your dish is connected.' },
        offline:     { label: 'Offline',            color: '#D41C1C', hint: 'Dish is not responding. Check power and cabling.' },
        unavailable: { label: 'Status unavailable', color: '#9B9B9B', hint: 'We couldn\u2019t reach your dish just now. Try again in a few minutes.' }
      };
      var info = stateMap[state] || stateMap.unavailable;

      var card = document.getElementById('site-dish-status');
      var dot  = document.getElementById('site-dish-dot');
      var lbl  = document.getElementById('site-dish-label');
      var age  = document.getElementById('site-dish-age');
      var hint = document.getElementById('site-dish-hint');

      if (dot)  dot.style.background = info.color;
      if (lbl)  { lbl.textContent = info.label; lbl.style.color = (state === 'offline') ? '#D41C1C' : 'var(--dark)'; }

      // Age display: "just now" for live, "Xm ago" otherwise. Only shown for online.
      if (age) {
        if (state === 'online') {
          var ageSec = (data.dish.age_s !== null && data.dish.age_s !== undefined) ? data.dish.age_s : null;
          age.textContent = 'Checked ' + formatAge(ageSec);
        } else {
          age.textContent = '';
        }
      }
      if (hint) hint.textContent = info.hint;

      // Rate-limit messaging (manual refresh was blocked)
      if (source === 'rate_limited' && hint) {
        hint.textContent = 'Refreshed recently. Try again in a few minutes.';
      }

      if (card) card.style.display = 'block';
    };

    // ── v4.12.31: Manual Refresh — POST to app_site_refresh ──
    window.dishRefresh = function() {
      var k = '<?= pe($site['kit_number']) ?>';
      if (!k) return;
      var btn = document.getElementById('site-dish-refresh-btn');
      var icEl  = document.getElementById('site-dish-refresh-ic');
      var lblEl = document.getElementById('site-dish-refresh-lbl');
      if (btn) btn.disabled = true;
      if (icEl) icEl.textContent = '⟳';
      if (lblEl) lblEl.textContent = 'Checking…';

      DishNet.apiFetch(location.pathname + '?page=api&action=app_site_refresh', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ kit: k })
      })
        .then(function(r){ return r.json(); })
        .then(function(resp){
          if (resp && resp.status === 'success' && resp.data) {
            // Server returns a flat dish-status object for the refresh endpoint.
            // Wrap it into the shape renderDishStatus expects.
            renderDishStatus({ dish: resp.data });
          }
        })
        .catch(function(){ /* silent */ })
        .then(function(){
          if (btn) btn.disabled = false;
          if (icEl) icEl.textContent = '↻';
          if (lblEl) lblEl.textContent = 'Refresh';
        });
    };
  })();
  </script>

  <!-- ═══ Network Tools ═══ -->
  <div class="sec-lbl">Network tools</div>
  <div class="list-card">
    <div class="list-row" onclick="DishNet.goInternal('speed_test',{kit:'<?= pe($site['kit_number']) ?>'})">
      <div class="list-ic" style="background:var(--off-white);color:var(--dark)"><svg class="ic"><use href="#i-speed"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Speed test</div>
        <div class="list-ts">Test download, upload, and latency</div>
      </div>
      <span class="chev">›</span>
    </div>
    <div class="list-row" onclick="DishNet.goInternal('devices',{kit:'<?= pe($site['kit_number']) ?>',router:'<?= pe($site['router_id'] ?: ($portalRouter['router_id_full'] ?? '')) ?>'})">
      <div class="list-ic" style="background:var(--off-white);color:var(--dark)"><svg class="ic"><use href="#i-wifi"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Connected devices</div>
        <div class="list-ts">See who's on your network right now</div>
      </div>
      <span class="chev">›</span>
    </div>
  </div>

  <!-- ═══ v4.15.0: Hotspot mode entry card ═══
       Async-loaded from app_hotspot_status. Hidden until the fetch
       completes so we don't show a flicker of stale state.
       Tapping when off → opens enable sheet (asks for SSID).
       Tapping when on  → navigates to s-hotspot dashboard. -->
  <?php if ($site['has_router'] && ($site['router_id'] || ($portalRouter['router_id_full'] ?? ''))): ?>
  <div class="sec-lbl">Hotspot mode</div>
  <div class="hs-entry" id="hs-entry-card" style="display:none">
    <div class="hs-entry-ic" id="hs-entry-ic"><svg class="ic"><use href="#i-power"/></svg></div>
    <div class="hs-entry-t">
      <div class="hs-entry-tt" id="hs-entry-tt">Hotspot mode</div>
      <div class="hs-entry-ts" id="hs-entry-ts">Loading…</div>
    </div>
    <button class="hs-entry-cta" id="hs-entry-cta" onclick="dnHotspotEntryClick()" style="display:none">Enable</button>
  </div>
  <script>
  (function(){
    var routerId = '<?= pe($site['router_id'] ?: ($portalRouter['router_id_full'] ?? '')) ?>';
    var kit      = '<?= pe($site['kit_number']) ?>';
    if (!routerId) return;

    function ready(cb) {
      if (window.DishNet && typeof window.DishNet.apiFetch === 'function') { cb(); return; }
      var n = 0, iv = setInterval(function(){
        n += 50;
        if (window.DishNet && typeof window.DishNet.apiFetch === 'function') {
          clearInterval(iv); cb();
        } else if (n > 3000) { clearInterval(iv); }
      }, 50);
    }

    window._dnHotspotState = { router_id: routerId, kit: kit, mode: false, ssid: '' };

    function applyState(data) {
      var card = document.getElementById('hs-entry-card');
      var ic   = document.getElementById('hs-entry-ic');
      var tt   = document.getElementById('hs-entry-tt');
      var ts   = document.getElementById('hs-entry-ts');
      var cta  = document.getElementById('hs-entry-cta');
      if (!card) return;
      window._dnHotspotState.mode = !!(data && data.hotspot_mode);
      window._dnHotspotState.ssid = (data && data.ssid_on_enable) || '';
      // v4.18.0: also stash the live Wi-Fi credentials so the entry card
      // can display the password (tap to reveal). This makes the password
      // accessible even if the customer's phone is offline and they need
      // to reconnect — they can pull it up from the entry card.
      window._dnHotspotState.wifi_ssid     = (data && data.wifi_ssid)     || '';
      window._dnHotspotState.wifi_password = (data && data.wifi_password) || '';
      if (window._dnHotspotState.mode) {
        ic.classList.add('on');
        tt.textContent = 'Hotspot mode is on';
        // v4.18.0: entry-card subtitle now packs three pieces of info:
        // Location (the typed nickname), Wi-Fi (the real broadcast SSID),
        // and Password (bullets, tap to reveal). When hotspot is on this
        // is the ONE place where the customer can find their current
        // password without having to navigate into the dashboard.
        var pwHtml = '';
        if (window._dnHotspotState.wifi_password) {
          pwHtml = '<br>Password: <b id="hs-entry-pw" data-pw="' + escapeHtml(window._dnHotspotState.wifi_password) + '" style="cursor:pointer;font-family:Barlow,monospace;letter-spacing:1.5px" onclick="event.stopPropagation();dnHotspotEntryRevealPw()">\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022</b> <span id="hs-entry-pw-copy" style="font-size:10px;color:var(--brand-red);font-weight:600;text-transform:uppercase;letter-spacing:.4px;cursor:pointer;margin-left:4px" onclick="event.stopPropagation();dnHotspotEntryCopyPw()">Copy</span>';
        }
        ts.innerHTML = 'Pause devices, share Wi-Fi, manage your hotspot.' +
                       (window._dnHotspotState.ssid     ? '<br>Location: <b>' + escapeHtml(window._dnHotspotState.ssid) + '</b>' : '') +
                       (window._dnHotspotState.wifi_ssid ? '<br>Wi-Fi: <b>'    + escapeHtml(window._dnHotspotState.wifi_ssid) + '</b>' : '') +
                       pwHtml;
        cta.classList.add('on');
        cta.textContent = 'Open';
      } else {
        ic.classList.remove('on');
        tt.textContent = 'Hotspot mode';
        ts.textContent = 'Quick-pause unwanted devices, share Wi-Fi via QR, and manage who\u2019s on your network.';
        cta.classList.remove('on');
        cta.textContent = 'Enable';
      }
      cta.style.display = '';
      card.style.display = 'flex';
    }

    // v4.18.0 — entry-card password reveal/copy. Stops the click from
    // bubbling up to the entry-card "Open" handler.
    window.dnHotspotEntryRevealPw = function() {
      var el = document.getElementById('hs-entry-pw');
      if (!el) return;
      var pw = el.dataset.pw || '';
      var revealed = el.dataset.shown === '1';
      if (revealed) {
        el.textContent = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';
        el.dataset.shown = '';
      } else {
        el.textContent = pw;
        el.dataset.shown = '1';
      }
    };
    window.dnHotspotEntryCopyPw = function() {
      var el = document.getElementById('hs-entry-pw');
      if (!el) return;
      var pw = el.dataset.pw || '';
      if (!pw) return;
      var lbl = document.getElementById('hs-entry-pw-copy');
      var done = function(){
        if (lbl) {
          var orig = lbl.textContent;
          lbl.textContent = 'Copied!';
          setTimeout(function(){ lbl.textContent = orig; }, 1500);
        }
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(pw).then(done).catch(function(){
          try { var ta = document.createElement('textarea'); ta.value = pw; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); done(); }
          catch (e) {}
        });
      } else {
        try { var ta = document.createElement('textarea'); ta.value = pw; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); done(); }
        catch (e) {}
      }
    };

    function escapeHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    window._hsEsc = escapeHtml;

    ready(function(){
      DishNet.apiFetch(location.pathname + '?page=api&action=app_hotspot_status&router_id=' + encodeURIComponent(routerId))
        .then(function(r){ return r.json(); })
        .then(function(resp){
          if (resp && resp.status === 'success' && resp.data) applyState(resp.data);
          else applyState({ hotspot_mode: false });
        })
        .catch(function(){ applyState({ hotspot_mode: false }); })
        .then(function(){
          // v4.18.2: arrived from the home Hotspot tile? Scroll the
          // entry card into view + brief highlight pulse so the customer
          // who tapped "Hotspot" lands on the thing they wanted to see,
          // not on KIT info / Service status / Change WiFi tiles above
          // it. The from=hotspot query param is set by s_hotspot_picker
          // when it routes off-state customers here.
          var qs = (location.search || '').toLowerCase();
          if (qs.indexOf('from=hotspot') === -1) return;
          var card = document.getElementById('hs-entry-card');
          if (!card || card.style.display === 'none') return;
          // Small delay so the card is laid out before we scroll
          setTimeout(function(){
            try {
              card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (e) {
              // Older Safari: synchronous fallback
              card.scrollIntoView();
            }
            // Pulse: temporary outline + soft red glow that fades out
            card.classList.add('hs-entry-pulse');
            setTimeout(function(){ card.classList.remove('hs-entry-pulse'); }, 2200);
          }, 80);
        });
    });

    // Click handler — open dashboard if on, else show enable sheet.
    window.dnHotspotEntryClick = function() {
      if (window._dnHotspotState.mode) {
        DishNet.goInternal('s_hotspot', { kit: kit, router: routerId });
      } else {
        dnHotspotShowEnableSheet();
      }
    };

    // Enable sheet — asks for SSID, then POSTs app_hotspot_toggle_mode.
    window.dnHotspotShowEnableSheet = function() {
      var bg = document.getElementById('hs-enable-sheet');
      if (!bg) {
        bg = document.createElement('div');
        bg.id = 'hs-enable-sheet';
        bg.className = 'hs-sheet-bg';
        bg.innerHTML =
          '<div class="hs-sheet">' +
          '  <h3>Turn on hotspot mode</h3>' +
          '  <p>Give this site a short nickname so you can tell your hotspots apart. We\u2019ll show you a fresh Wi-Fi password on the next screen.</p>' +
          '  <input type="text" id="hs-enable-ssid" placeholder="e.g. Cinema Caf\u00e9" maxlength="32">' +
          '  <button class="cta-red" id="hs-enable-go" onclick="dnHotspotConfirmEnable()">' +
          '    <svg class="ic" style="width:14px;height:14px"><use href="#i-power"/></svg> Continue' +
          '  </button>' +
          '  <button class="cta-alt" onclick="dnHotspotCloseSheet()">Cancel</button>' +
          '  <p style="font-size:11px;color:var(--gray-2);margin-top:14px;line-height:1.5;margin-bottom:0">' +
          '    Your network name stays the same. Only the Wi-Fi password is rotated.' +
          '  </p>' +
          '</div>';
        document.body.appendChild(bg);
        bg.addEventListener('click', function(e){ if (e.target === bg) dnHotspotCloseSheet(); });
      }
      bg.classList.add('show');
      setTimeout(function(){ var i = document.getElementById('hs-enable-ssid'); if (i) i.focus(); }, 80);
    };
    window.dnHotspotCloseSheet = function() {
      var bg = document.getElementById('hs-enable-sheet'); if (bg) bg.classList.remove('show');
      var bg2= document.getElementById('hs-disable-sheet');if (bg2)bg2.classList.remove('show');
    };
    window.dnHotspotConfirmEnable = function() {
      var input = document.getElementById('hs-enable-ssid');
      var ssid  = input ? input.value.trim() : '';
      var go    = document.getElementById('hs-enable-go');
      if (!ssid) {
        if (input) {
          input.focus();
          input.style.borderColor = 'var(--brand-red)';
          setTimeout(function(){ if (input) input.style.borderColor = ''; }, 1200);
        }
        return;
      }

      // v4.18.0: TWO-STEP enable flow.
      // Step 1 (this function): call app_hotspot_prepare to get a fresh
      // password from the server WITHOUT pushing it to the router yet.
      // Then show the password to the customer in a second sheet.
      // Step 2 (in dnHotspotApplyEnable): customer taps "I've saved it,
      // apply now" — only THEN do we push to the router. This avoids
      // locking the customer out of their own Wi-Fi mid-flow.
      if (go) {
        go.disabled = true;
        go.innerHTML = '<svg class="ic" style="width:14px;height:14px;animation:spin 1s linear infinite"><use href="#i-refresh"/></svg> Checking router\u2026';
      }
      var httpStatus = 0;
      DishNet.apiFetch(location.pathname + '?page=api&action=app_hotspot_prepare', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ router_id: routerId })
      })
        .then(function(r){ httpStatus = r.status; return r.text(); })
        .then(function(rawText){
          var resp = null;
          try { resp = JSON.parse(rawText); } catch (e) { resp = null; }
          if (resp && resp.status === 'success' && resp.data && resp.data.wifi_password) {
            // Got the password — close nickname sheet, open password sheet
            dnHotspotCloseSheet();
            dnHotspotShowPasswordSheet({
              ssid: resp.data.wifi_ssid,
              password: resp.data.wifi_password,
              nickname: ssid,
            });
          } else {
            if (go) { go.disabled = false; go.innerHTML = '<svg class="ic" style="width:14px;height:14px"><use href="#i-power"/></svg> Turn on hotspot mode'; }
            var msg = (resp && (resp.message || resp.error))
                   || ('Server returned ' + httpStatus + (rawText ? (': ' + rawText.substring(0, 200)) : ''))
                   || 'Could not prepare hotspot. Make sure your router is online.';
            alert(msg);
            try { console.error('[DishNet hotspot prepare]', httpStatus, rawText); } catch (e) {}
          }
        })
        .catch(function(err){
          if (go) { go.disabled = false; go.innerHTML = '<svg class="ic" style="width:14px;height:14px"><use href="#i-power"/></svg> Turn on hotspot mode'; }
          alert('Network error: ' + (err && err.message ? err.message : 'unknown'));
        });
    };

    // v4.18.0 — Step 2 of the enable flow: show the new password to the
    // customer BEFORE we apply it to the router. The customer's own device
    // is on the same Wi-Fi we're about to change; if we apply first and
    // tell them later, they get disconnected and can't see the message.
    // Show first, get acknowledgment, then apply.
    window.dnHotspotShowPasswordSheet = function(prep) {
      var bg = document.getElementById('hs-pw-sheet');
      if (bg) bg.remove(); // always rebuild — content depends on prep
      bg = document.createElement('div');
      bg.id = 'hs-pw-sheet';
      bg.className = 'hs-sheet-bg';
      // The big password is the focal point. Copy button right under it.
      // Then the warning, then the apply button.
      bg.innerHTML =
        '<div class="hs-sheet">' +
        '  <h3>Save your new password</h3>' +
        '  <p>Write this down or copy it now. Once you tap apply, your devices will need this password to reconnect to the Wi-Fi.</p>' +
        '  <div style="background:#f5f5f5;border-radius:12px;padding:18px 14px;margin:14px 0;text-align:center">' +
        '    <div style="font-size:10px;font-weight:700;letter-spacing:1.4px;color:var(--gray-2);text-transform:uppercase;margin-bottom:6px">New Wi-Fi password</div>' +
        '    <div id="hs-pw-newval" style="font-family:\'Barlow\',monospace;font-weight:800;font-size:32px;letter-spacing:6px;color:var(--dark);margin-bottom:8px;word-break:break-all">' + escHtmlLocal(prep.password) + '</div>' +
        '    <button id="hs-pw-copy-btn" class="cta-alt" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-size:13px"><svg class="ic" style="width:14px;height:14px"><use href="#i-copy"/></svg> <span id="hs-pw-copy-lbl">Copy password</span></button>' +
        '  </div>' +
        '  <div style="background:var(--amber-light);border-radius:8px;padding:10px 12px;margin:0 0 14px;font-size:11px;color:var(--amber-dark);line-height:1.5">' +
        '    <b>Important:</b> when you tap Apply, every device currently on your Wi-Fi will be kicked off and need to reconnect with this new password. The Wi-Fi name (<b>' + escHtmlLocal(prep.ssid) + '</b>) stays the same.' +
        '  </div>' +
        '  <button class="cta-red" id="hs-pw-apply-go" onclick="dnHotspotApplyEnable()">' +
        '    <svg class="ic" style="width:14px;height:14px"><use href="#i-power"/></svg> I\u2019ve saved it, apply now' +
        '  </button>' +
        '  <button class="cta-alt" onclick="dnHotspotCancelPasswordSheet()">Cancel</button>' +
        '</div>';
      document.body.appendChild(bg);
      bg.addEventListener('click', function(e){ if (e.target === bg) dnHotspotCancelPasswordSheet(); });
      bg.classList.add('show');

      // Stash for the apply step
      window._dnHotspotPrep = {
        ssid:     prep.ssid,
        password: prep.password,
        nickname: prep.nickname,
      };

      // Wire copy button
      var copyBtn = document.getElementById('hs-pw-copy-btn');
      var copyLbl = document.getElementById('hs-pw-copy-lbl');
      if (copyBtn) {
        copyBtn.onclick = function() {
          var pw = (window._dnHotspotPrep && window._dnHotspotPrep.password) || '';
          if (!pw) return;
          var done = function(){ if (copyLbl) { copyLbl.textContent = 'Copied!'; setTimeout(function(){ copyLbl.textContent = 'Copy password'; }, 1500); } };
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(pw).then(done).catch(function(){
              try { var ta = document.createElement('textarea'); ta.value = pw; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); done(); }
              catch (e) { alert('Could not copy. Long-press the password to select it.'); }
            });
          } else {
            try { var ta = document.createElement('textarea'); ta.value = pw; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); done(); }
            catch (e) { alert('Could not copy. Long-press the password to select it.'); }
          }
        };
      }
    };
    function escHtmlLocal(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

    window.dnHotspotCancelPasswordSheet = function() {
      var bg = document.getElementById('hs-pw-sheet'); if (bg) bg.classList.remove('show');
      window._dnHotspotPrep = null;
      // Re-enable the original Enable button so the customer can retry
      var go = document.getElementById('hs-entry-cta');
      if (go) {
        go.disabled = false;
        go.innerHTML = 'Enable';
      }
    };

    // v4.18.0 — Step 3 of the enable flow: customer has acknowledged the
    // password and tapped "Apply now". Push to the router with the SAME
    // password we showed them.
    window.dnHotspotApplyEnable = function() {
      if (!window._dnHotspotPrep) {
        alert('Session expired. Please try again.');
        dnHotspotCancelPasswordSheet();
        return;
      }
      var prep = window._dnHotspotPrep;
      var go = document.getElementById('hs-pw-apply-go');
      if (go) {
        go.disabled = true;
        go.innerHTML = '<svg class="ic" style="width:14px;height:14px;animation:spin 1s linear infinite"><use href="#i-refresh"/></svg> Applying password\u2026';
      }
      var httpStatus = 0;
      DishNet.apiFetch(location.pathname + '?page=api&action=app_hotspot_toggle_mode', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          router_id: routerId,
          enable:    true,
          ssid:      prep.nickname,
          password:  prep.password,
        })
      })
        .then(function(r){ httpStatus = r.status; return r.text(); })
        .then(function(rawText){
          var resp = null;
          try { resp = JSON.parse(rawText); } catch (e) { resp = null; }
          if (resp && resp.status === 'success') {
            dnHotspotCancelPasswordSheet();
            window._dnHotspotState.mode = true;
            window._dnHotspotState.ssid = prep.nickname;
            if (resp.data) {
              window._dnHotspotState.wifi_ssid     = resp.data.wifi_ssid     || prep.ssid;
              window._dnHotspotState.wifi_password = resp.data.wifi_password || prep.password;
            }
            DishNet.goInternal('s_hotspot', { kit: kit, router: routerId });
          } else {
            if (go) { go.disabled = false; go.innerHTML = '<svg class="ic" style="width:14px;height:14px"><use href="#i-power"/></svg> I\u2019ve saved it, apply now'; }
            var msg = (resp && (resp.message || resp.error))
                   || ('Server returned ' + httpStatus + (rawText ? (': ' + rawText.substring(0, 200)) : ''))
                   || 'Could not apply password.';
            alert(msg);
            try { console.error('[DishNet hotspot apply]', httpStatus, rawText); } catch (e) {}
          }
        })
        .catch(function(err){
          if (go) { go.disabled = false; go.innerHTML = '<svg class="ic" style="width:14px;height:14px"><use href="#i-power"/></svg> I\u2019ve saved it, apply now'; }
          alert('Network error: ' + (err && err.message ? err.message : 'unknown'));
        });
    };
  })();
  </script>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: HOTSPOT DASHBOARD (s_hotspot) — v4.15.0
// ══════════════════════════════════════════════════════════════════
// Customer-facing hotspot mode dashboard. Shown when hotspot_mode is
// enabled for a router. Data is loaded async from app_hotspot_status
// (current SSID, mode flag) and dr_wifi_get_status (live network info,
// including the current Wi-Fi password from networks[0] and the live
// device count). Paused-device count comes from dr_wifi_list_paused_clients.
//
// Out of scope this release: rotate-now, peak-time stats, activity
// classification (Streaming/Heavy/Light) — show only what the
// data-report API returns directly.
// ══════════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════════
// VIEW: s_hotspot_picker  v4.18.1
// ══════════════════════════════════════════════════════════════════
// Multi-site router picker for the Hotspot home tile. Same pattern as
// wifi_change's picker: shows all routers belonging to this customer,
// each with current hotspot status (ON / OFF). Tapping a row routes:
//   - if hotspot mode is ON for that router → s_hotspot dashboard
//   - if hotspot mode is OFF              → site_detail (where the
//                                            entry card handles enable)
// Single-router customers are routed straight through, no picker shown.
//
// We don't fetch live status server-side here (would require N curl
// calls). Instead, each row fires app_hotspot_status from JS on load
// and updates its pill — same-origin, parallel, fast. Matches what
// the Site Detail entry card already does.
// ══════════════════════════════════════════════════════════════════
elseif ($view === 's_hotspot_picker'):
    // Collect all routers for this customer
    $hpRouters = [];
    foreach ($portalSites as $ss) {
        if ($ss['has_router'] && $ss['router_id']) {
            $hpRouters[] = [
                'kit'       => $ss['kit_number'],
                'router_id' => $ss['router_id'],
                'location'  => $ss['location'],
                'is_active' => $ss['is_active'],
            ];
        }
    }
    // Single-router auto-redirect happens client-side (JS at bottom of view)
    // so back-button history works correctly.
    $hpHasRouters = !empty($hpRouters);
?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="history.back()"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Hotspot</div>
    <div class="scr-btn-ph"></div>
  </div>
  <div style="font-size:12px;color:rgba(255,255,255,.55);position:relative;z-index:2">
    <?= $hpHasRouters ? (count($hpRouters) === 1 ? 'Opening your site&hellip;' : 'Choose a site to manage') : 'No routers found' ?>
  </div>
</div>

<div class="scr-body">
  <?php if (!$hpHasRouters): ?>
    <div class="empty" style="margin-top:20px">
      <h3>No routers found</h3>
      <p>We couldn't find any Starlink routers on your account. Contact support if you think this is wrong.</p>
      <button class="cta-alt" onclick="DishNet.openWhatsApp('+211921443002', 'Hotspot mode says no routers found.')" style="margin-top:16px">Contact support</button>
    </div>
  <?php else: ?>
    <div class="sec-lbl" style="margin-top:4px"><?= count($hpRouters) === 1 ? 'Your site' : 'Your sites' ?></div>
    <div class="list-card">
      <?php foreach ($hpRouters as $hpR): ?>
      <div class="list-row" id="hp-row-<?= pe($hpR['kit']) ?>" data-kit="<?= pe($hpR['kit']) ?>" data-router="<?= pe($hpR['router_id']) ?>" data-active="<?= $hpR['is_active'] ? '1' : '0' ?>" style="cursor:pointer">
        <div class="list-ic" style="background:<?= $hpR['is_active'] ? 'var(--green-light)' : 'var(--off-white)' ?>;color:<?= $hpR['is_active'] ? 'var(--green-mid)' : 'var(--gray)' ?>"><svg class="ic"><use href="#i-power"/></svg></div>
        <div class="list-t">
          <div class="list-tt"><?= pe($hpR['location']) ?></div>
          <div class="list-ts"><span class="hp-pill" id="hp-pill-<?= pe($hpR['kit']) ?>" style="display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--gray-2)"><span style="width:5px;height:5px;border-radius:50%;background:var(--gray-light);display:inline-block"></span>Checking&hellip;</span></div>
        </div>
        <span class="chev">›</span>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="hs-honest" style="margin-top:16px">
      <div style="display:flex;gap:10px;align-items:flex-start">
        <svg class="ic" style="width:14px;height:14px;color:var(--blue);flex-shrink:0;margin-top:2px"><use href="#i-info"/></svg>
        <div style="font-size:12px;color:var(--dark);line-height:1.5">
          <b>What is hotspot mode?</b><br>
          A simple dashboard for managing your Wi-Fi: see who's connected, pause unwanted devices, share Wi-Fi via QR code. Turning it on rotates your Wi-Fi password &mdash; we'll show it to you first so you can save it before it applies.
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  // For each router row, fetch hotspot status and route the click.
  function setPill(kit, isOn) {
    var pill = document.getElementById('hp-pill-' + kit);
    if (!pill) return;
    if (isOn) {
      pill.innerHTML = '<span style="width:5px;height:5px;border-radius:50%;background:var(--green-mid);display:inline-block"></span><span style="color:var(--green-mid)">Hotspot on</span>';
    } else {
      pill.innerHTML = '<span style="width:5px;height:5px;border-radius:50%;background:var(--gray-light);display:inline-block"></span><span style="color:var(--gray-2)">Tap to enable</span>';
    }
  }
  function ready(cb) {
    if (window.DishNet && typeof window.DishNet.apiFetch === 'function') { cb(); return; }
    var n = 0, iv = setInterval(function(){
      n += 50;
      if (window.DishNet && typeof window.DishNet.apiFetch === 'function') { clearInterval(iv); cb(); }
      else if (n > 3000) { clearInterval(iv); }
    }, 50);
  }
  ready(function(){
    var rows = document.querySelectorAll('[id^="hp-row-"]');
    // Track per-row hotspot mode so click handler can route correctly.
    var modeByKit = {};

    Array.prototype.forEach.call(rows, function(row){
      var kit    = row.dataset.kit;
      var router = row.dataset.router;
      // Fetch status — same endpoint the entry card uses.
      DishNet.apiFetch(location.pathname + '?page=api&action=app_hotspot_status&router_id=' + encodeURIComponent(router))
        .then(function(r){ return r.json(); })
        .then(function(resp){
          var isOn = !!(resp && resp.status === 'success' && resp.data && resp.data.hotspot_mode);
          modeByKit[kit] = isOn;
          setPill(kit, isOn);
        })
        .catch(function(){
          // Network or auth error — leave pill default but allow tapping
          // anyway so the customer can still navigate to Site Detail.
          var pill = document.getElementById('hp-pill-' + kit);
          if (pill) pill.innerHTML = '<span style="color:var(--gray-2)">Tap to manage</span>';
        });

      row.onclick = function() {
        var isOn = modeByKit[kit];
        if (isOn) {
          // Hotspot is on — go straight to dashboard
          DishNet.goInternal('s_hotspot', { kit: kit, router: router });
        } else {
          // Hotspot off (or unknown) — go to Site Detail where the
          // entry card handles enable. v4.18.2: pass from=hotspot so the
          // Site Detail page knows to scroll the hotspot card into view
          // with a highlight pulse — otherwise the card sits below KIT
          // info / Service status / Change WiFi tiles and the customer
          // who just tapped "Hotspot" lands on a screen where the thing
          // they wanted is below the fold.
          DishNet.goInternal('site_detail', { kit: kit, from: 'hotspot' });
        }
      };
    });

    // v4.18.1: single-site auto-redirect. If there's exactly one router,
    // skip the picker entirely and route the customer straight to where
    // they want to be. We do it client-side so the user's browser
    // history works (back button returns to home, not to picker).
    if (rows.length === 1) {
      var only = rows[0];
      var kit    = only.dataset.kit;
      var router = only.dataset.router;
      DishNet.apiFetch(location.pathname + '?page=api&action=app_hotspot_status&router_id=' + encodeURIComponent(router))
        .then(function(r){ return r.json(); })
        .then(function(resp){
          var isOn = !!(resp && resp.status === 'success' && resp.data && resp.data.hotspot_mode);
          if (isOn) {
            DishNet.goInternal('s_hotspot', { kit: kit, router: router });
          } else {
            // v4.18.2: same from=hotspot hint as multi-site path
            DishNet.goInternal('site_detail', { kit: kit, from: 'hotspot' });
          }
        })
        .catch(function(){
          DishNet.goInternal('site_detail', { kit: kit, from: 'hotspot' });
        });
    }
  });
})();
</script>

<?php

elseif ($view === 's_hotspot'):
    $hsKit    = trim($_GET['kit'] ?? '');
    $hsRouter = trim($_GET['router'] ?? ($portalRouter['router_id_full'] ?? ''));
    $hsLocation = '';
    foreach ($portalSites as $ss) {
        if ($ss['kit_number'] === $hsKit) { $hsLocation = $ss['location']; break; }
    }
    if (!$hsRouter):
?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="history.back()"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Hotspot</div>
    <div class="scr-btn-ph"></div>
  </div>
</div>
<div class="scr-body"><div class="empty"><h3>No router for this site</h3><p>Hotspot mode needs a Starlink router connected to your account.</p></div></div>
<?php else: ?>
<div class="scr-head" style="padding-bottom:36px">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="history.back()"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Hotspot</div>
    <button class="scr-btn" onclick="DishNet.goInternal('s_hotspot_pw',{kit:'<?= pe($hsKit) ?>',router:'<?= pe($hsRouter) ?>'})"><svg class="ic" style="width:14px;height:14px"><use href="#i-gear"/></svg></button>
  </div>
  <div class="scr-eyebrow"><?= pe($hsLocation ?: $hsKit) ?> <span class="tech-tag sl-dark">Starlink router</span></div>
  <div class="hs-hero-row"><span class="hs-hello" id="hs-hello">Hotspot mode</span><span class="hs-live" id="hs-live"><span>LIVE</span></span></div>
  <!-- v4.18.4: continuous-uptime label. Reads from enabled_at on the
       hotspot_config record. Format: "Active for 3 days" or "Active for
       2 hours". Hidden until the value loads. -->
  <div id="hs-active-for" style="font-size:11px;color:rgba(255,255,255,.5);margin-top:4px;letter-spacing:.2px;position:relative;z-index:2;display:none"></div>
</div>
<div class="scr-body">

  <!-- Location + Wi-Fi card (hero) — top: customer's location label, bottom: real
       Wi-Fi network credentials (real SSID + password) for QR share + copy.
       v4.16.0: split into two distinct rows so customers don't conflate the
       location nickname they typed with the actual broadcast SSID. -->
  <div class="sl-pw-card">
    <div class="sl-pw-top">
      <div>
        <div class="sl-pw-k">Location</div>
        <div class="sl-pw-ssid" id="hs-ssid">Loading&hellip;</div>
      </div>
      <span class="pill green" id="hs-status-pill" style="font-size:9px;letter-spacing:1.2px"><span style="width:4px;height:4px;border-radius:50%;background:currentColor;display:inline-block;margin-right:5px"></span>Active</span>
    </div>
    <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--gray-bd)">
      <div class="sl-pw-code-k" style="margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;gap:10px">
        <span>Wi-Fi network</span>
        <span style="display:flex;gap:12px;align-items:center">
          <a href="#" id="hs-resync-link" style="font-size:10px;color:var(--gray-2);text-decoration:none;font-weight:600;letter-spacing:.4px;text-transform:uppercase" title="Re-sync from router">Refresh</a>
          <a href="#" id="hs-rename-link" style="font-size:10px;color:var(--brand-red);text-decoration:none;font-weight:600;letter-spacing:.4px;text-transform:uppercase">Rename</a>
        </span>
      </div>
      <div class="sl-pw-ssid" id="hs-real-ssid" style="font-size:18px;margin-bottom:10px">Loading&hellip;</div>
      <div class="sl-pw-code">
        <div class="sl-pw-code-t">
          <div class="sl-pw-code-k">Password</div>
          <div class="sl-pw-code-v bullets" id="hs-pw" onclick="dnHotspotTogglePw()">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</div>
        </div>
        <button class="sl-pw-code-btn" onclick="dnHotspotTogglePw()" title="Show password" id="hs-eye-btn"><svg class="ic" style="width:14px;height:14px"><use href="#i-eye"/></svg></button>
        <button class="sl-pw-code-btn" onclick="dnHotspotCopyPw()" title="Copy password" id="hs-copy-btn"><svg class="ic" style="width:14px;height:14px"><use href="#i-copy"/></svg></button>
        <button class="sl-pw-code-btn" onclick="dnHotspotShowQr()" title="Share via QR"><svg class="ic" style="width:14px;height:14px"><use href="#i-qr"/></svg></button>
      </div>
      <!-- v4.18.4: rotation freshness indicator. Reads from wifi_synced_at
           which is set whenever the enable flow or app_hotspot_resync
           writes the password to hotspot_config.json. Builds confidence
           that the password the customer is looking at is current. -->
      <div id="hs-pw-rotated" style="font-size:10px;color:var(--gray-2);margin-top:8px;letter-spacing:.3px;display:none">
        Last rotated <span id="hs-pw-rotated-rel">just now</span>
      </div>
    </div>
  </div>

  <!-- v4.19.0 — NEW devices banner. Surfaces unrecognized fingerprints
       so customers see at a glance if someone has shared their password.
       Tap routes to the devices view where per-row Mark Known and
       per-banner Rotate Password actions live. Hidden when count is 0. -->
  <div id="hs-dash-new-banner" onclick="DishNet.goInternal('devices',{kit:'<?= pe($hsKit) ?>',router:'<?= pe($hsRouter) ?>'})" style="display:none;cursor:pointer;background:#fff;border:1px solid rgba(212,28,28,.25);border-radius:14px;padding:12px 14px;margin-bottom:14px;box-shadow:0 1px 3px rgba(212,28,28,.08);align-items:center;gap:11px;display:flex">
    <div style="width:34px;height:34px;border-radius:10px;background:rgba(212,28,28,.08);color:var(--brand-red);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px">🆕</div>
    <div style="flex:1;min-width:0">
      <div id="hs-dash-new-label" style="font-size:13px;font-weight:700;color:var(--dark);line-height:1.3">0 new devices connected</div>
      <div style="font-size:11px;color:var(--gray-2);line-height:1.45;margin-top:1px">Tap to review</div>
    </div>
    <span style="color:var(--gray-2);flex-shrink:0;font-size:18px;line-height:1">›</span>
  </div>

  <!-- Stats strip — Connected count + Paused count, both real numbers.
       v4.18.4: tappable. Both route to the connected-devices view, which
       lists all clients (controllers, paused, active) with per-row pause/
       unpause controls. Lighter visual treatment than the Manage tiles
       below — these are at-a-glance numbers that happen to be a shortcut. -->
  <div class="hs-stats">
    <div class="hs-stat hs-stat-tap" onclick="DishNet.goInternal('devices',{kit:'<?= pe($hsKit) ?>',router:'<?= pe($hsRouter) ?>'})" role="button" style="cursor:pointer">
      <div class="hs-stat-top"><div class="hs-stat-ic blue"><svg class="ic" style="width:14px;height:14px"><use href="#i-users"/></svg></div><div class="hs-stat-k">Connected</div></div>
      <div class="hs-stat-main"><span class="hs-stat-num" id="hs-connected-count">&mdash;</span><span class="hs-stat-unit" id="hs-connected-unit">devices</span></div>
      <div class="hs-stat-sub" id="hs-connected-sub">Right now</div>
    </div>
    <div class="hs-stat hs-stat-tap" onclick="DishNet.goInternal('devices',{kit:'<?= pe($hsKit) ?>',router:'<?= pe($hsRouter) ?>'})" role="button" style="cursor:pointer">
      <div class="hs-stat-top"><div class="hs-stat-ic amber"><svg class="ic" style="width:14px;height:14px"><use href="#i-pause"/></svg></div><div class="hs-stat-k">Paused</div></div>
      <div class="hs-stat-main"><span class="hs-stat-num" id="hs-paused-count">&mdash;</span><span class="hs-stat-unit" id="hs-paused-unit">devices</span></div>
      <div class="hs-stat-sub" id="hs-paused-sub">By you</div>
    </div>
  </div>

  <!-- Action grid — Connected devices, Share Wi-Fi, Manage password, Disable hotspot.
       v4.15.0 design decision: NO "Rotate password" tile here — rotation backend
       is not shipped, so we don't even tease the action. The "Manage password"
       tile takes the user to s_hotspot_pw which has the schedule chooser
       (mostly disabled, with a clear "Coming soon" treatment). -->
  <div class="sec-lbl">Manage</div>
  <div class="hs-acts">
    <button class="hs-act" onclick="DishNet.goInternal('devices',{kit:'<?= pe($hsKit) ?>',router:'<?= pe($hsRouter) ?>'})">
      <div class="hs-act-ic"><svg class="ic" style="width:16px;height:16px"><use href="#i-users"/></svg></div>
      <div class="hs-act-t"><div class="hs-act-tt">Connected devices</div><div class="hs-act-ts" id="hs-act-devs-sub">See who's online</div></div>
    </button>
    <button class="hs-act" onclick="dnHotspotShowQr()">
      <div class="hs-act-ic"><svg class="ic" style="width:16px;height:16px"><use href="#i-qr"/></svg></div>
      <div class="hs-act-t"><div class="hs-act-tt">Share Wi-Fi</div><div class="hs-act-ts">Show a QR code</div></div>
    </button>
    <button class="hs-act" onclick="DishNet.goInternal('s_hotspot_pw',{kit:'<?= pe($hsKit) ?>',router:'<?= pe($hsRouter) ?>'})">
      <div class="hs-act-ic"><svg class="ic" style="width:16px;height:16px"><use href="#i-lock"/></svg></div>
      <div class="hs-act-t"><div class="hs-act-tt">Password &amp; access</div><div class="hs-act-ts">Reveal, copy, manage</div></div>
    </button>
    <button class="hs-act" onclick="dnHotspotShowDisableSheet()">
      <div class="hs-act-ic amber"><svg class="ic" style="width:16px;height:16px"><use href="#i-power"/></svg></div>
      <div class="hs-act-t"><div class="hs-act-tt">Disable hotspot</div><div class="hs-act-ts">Turn off the dashboard</div></div>
    </button>
  </div>

  <!-- Honest disclaimer banner — the soul of the feature.
       This is what differentiates DishNet from operators who promise
       Starlink can do MikroTik things. We are upfront about the limits.
       v4.18.4: collapsed by default (eats less real estate on every load).
       Tap to expand. Expand state remembered via localStorage so once a
       customer has seen it, it stays collapsed unless they tap again. -->
  <div class="hs-honest" id="hs-honest-box" onclick="dnHotspotToggleHonest()" style="cursor:pointer">
    <svg class="ic" style="width:14px;height:14px;color:var(--blue-dark,#1d4eb8);flex-shrink:0;margin-top:1px"><use href="#i-info"/></svg>
    <div class="hs-honest-t">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
        <b style="margin-bottom:0">Want speed caps and voucher codes?</b>
        <svg id="hs-honest-chev" class="ic" style="width:12px;height:12px;color:var(--blue-dark,#1d4eb8);flex-shrink:0;transition:transform .2s"><use href="#i-chev-down"/></svg>
      </div>
      <div id="hs-honest-body" style="display:none;margin-top:6px">
        Starlink doesn't support per-device speed limits or voucher-based access. For those, you'll need DishNet Fiber with MikroTik.
        <button class="hs-honest-cta" onclick="event.stopPropagation();dnHotspotCheckFiber()">Check fiber availability</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // v4.18.4: collapsible disclosure. Restore last-known state on load.
  var STORE_KEY = 'dn_hotspot_honest_open';
  window.dnHotspotToggleHonest = function() {
    var body = document.getElementById('hs-honest-body');
    var chev = document.getElementById('hs-honest-chev');
    if (!body) return;
    var open = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    if (chev) chev.style.transform = open ? '' : 'rotate(180deg)';
    try { localStorage.setItem(STORE_KEY, open ? '0' : '1'); } catch (e) {}
  };
  // Restore — but DEFAULT to collapsed for new customers (no stored state)
  try {
    if (localStorage.getItem(STORE_KEY) === '1') {
      // Was open last time — re-open
      var body0 = document.getElementById('hs-honest-body');
      var chev0 = document.getElementById('hs-honest-chev');
      if (body0) body0.style.display = 'block';
      if (chev0) chev0.style.transform = 'rotate(180deg)';
    }
  } catch (e) {}
})();
</script>

<script>
(function(){
  window._hsKit      = '<?= pe($hsKit) ?>';
  window._hsRouter   = '<?= pe($hsRouter) ?>';
  window._hsLocation = '<?= pe($hsLocation) ?>';
  window._hsSsid     = '';   // v4.16.0: location label (typed by customer when enabling)
  window._hsRealSsid = '';   // v4.16.0: real Wi-Fi SSID broadcast by the router
  window._hsPassword = '';
  window._hsPwRevealed = false;

  function ready(cb) {
    if (window.DishNet && typeof window.DishNet.apiFetch === 'function') { cb(); return; }
    var n = 0, iv = setInterval(function(){
      n += 50;
      if (window.DishNet && typeof window.DishNet.apiFetch === 'function') { clearInterval(iv); cb(); }
      else if (n > 3000) { clearInterval(iv); cb(); }
    }, 50);
  }

  function escHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

  // Build endpoint to data-report plugin. We reuse the same construction the
  // existing devices view uses — split off /_plugins/ from the URL so we
  // hit the data-report public.php at the same UCRM origin.
  function drUrl(action, qs) {
    var base = location.href.split('/_plugins/')[0] + '/_plugins/dishnet-data-report/public.php';
    var u = base + '?action=' + encodeURIComponent(action);
    if (qs) for (var k in qs) if (Object.prototype.hasOwnProperty.call(qs, k)) {
      u += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(qs[k]);
    }
    return u;
  }

  // ── Load hotspot config + live wifi status in parallel ──
  ready(function(){
    // v4.16.0: Wire the "Rename" link in the Wi-Fi network row to the
    // existing wifi_change flow. This is the actual SSID rename — it
    // pushes a Starlink-cloud Wi-Fi config change. Distinct from the
    // location label (which is a customer-app-only nickname).
    var renameLink = document.getElementById('hs-rename-link');
    if (renameLink) {
      renameLink.onclick = function(e) {
        e.preventDefault();
        DishNet.goInternal('wifi_change', { kit: window._hsKit, router: window._hsRouter });
        return false;
      };
    }

    // v4.17.0 — Refresh link: re-fetch SSID+password from Starlink cloud
    // and update our store. Useful after the customer changes credentials
    // outside the hotspot flow (e.g. via Change WiFi).
    var resyncLink = document.getElementById('hs-resync-link');
    if (resyncLink) {
      resyncLink.onclick = function(e) {
        e.preventDefault();
        var orig = resyncLink.textContent;
        resyncLink.textContent = 'Syncing\u2026';
        resyncLink.style.pointerEvents = 'none';
        DishNet.apiFetch(location.pathname + '?page=api&action=app_hotspot_resync', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ router_id: window._hsRouter })
        })
          .then(function(r){ return r.text().then(function(t){ return [r.status, t]; }); })
          .then(function(arr){
            var status = arr[0]; var raw = arr[1];
            var resp = null; try { resp = JSON.parse(raw); } catch (e) {}
            if (resp && resp.status === 'success' && resp.data) {
              if (resp.data.wifi_ssid) {
                window._hsRealSsid = resp.data.wifi_ssid;
                var realEl = document.getElementById('hs-real-ssid');
                if (realEl) realEl.textContent = resp.data.wifi_ssid;
              }
              if (resp.data.wifi_password) {
                window._hsPassword = resp.data.wifi_password;
                // Refresh visible password if currently revealed
                if (window._hsPwRevealed) {
                  var pwEl = document.getElementById('hs-pw');
                  if (pwEl) pwEl.textContent = resp.data.wifi_password;
                }
              }
              // v4.18.4: refresh "Last rotated" label since we just pulled
              // current credentials from the cloud.
              if (resp.data.synced_at) {
                var rotEl = document.getElementById('hs-pw-rotated');
                var rotRel = document.getElementById('hs-pw-rotated-rel');
                if (rotEl && rotRel) {
                  rotRel.textContent = relTime(resp.data.synced_at);
                  rotEl.style.display = '';
                }
              }
              resyncLink.textContent = 'Synced \u2713';
              setTimeout(function(){ resyncLink.textContent = orig; resyncLink.style.pointerEvents = ''; }, 1500);
            } else {
              var msg = (resp && (resp.message || resp.error)) || ('Server error ' + status);
              alert(msg);
              resyncLink.textContent = orig;
              resyncLink.style.pointerEvents = '';
            }
          })
          .catch(function(err){
            alert('Network error: ' + (err && err.message ? err.message : 'unknown'));
            resyncLink.textContent = orig;
            resyncLink.style.pointerEvents = '';
          });
        return false;
      };
    }

    // ── v4.18.4: relative-time helpers ────────────────────────────────
    // Both take an ISO 8601 string and produce a short, human-friendly
    // label. relTime is past-tense ("5 minutes ago"), durationSince is
    // a duration ("5 minutes"). Both clamp to "just now" / "a few seconds"
    // for very recent values to avoid the "rotated 0 minutes ago" weirdness.
    function relTime(iso) {
      try {
        var t = new Date(iso).getTime();
        if (!t || isNaN(t)) return '';
        var sec = Math.max(0, Math.floor((Date.now() - t) / 1000));
        if (sec < 30)         return 'just now';
        if (sec < 60)         return 'less than a minute ago';
        var min = Math.floor(sec / 60);
        if (min < 60)         return min + ' minute' + (min === 1 ? '' : 's') + ' ago';
        var hr = Math.floor(min / 60);
        if (hr < 24)          return hr + ' hour' + (hr === 1 ? '' : 's') + ' ago';
        var day = Math.floor(hr / 24);
        if (day < 30)         return day + ' day' + (day === 1 ? '' : 's') + ' ago';
        var mo = Math.floor(day / 30);
        if (mo < 12)          return mo + ' month' + (mo === 1 ? '' : 's') + ' ago';
        var yr = Math.floor(mo / 12);
        return yr + ' year' + (yr === 1 ? '' : 's') + ' ago';
      } catch (e) { return ''; }
    }
    function durationSince(iso) {
      try {
        var t = new Date(iso).getTime();
        if (!t || isNaN(t)) return '';
        var sec = Math.max(0, Math.floor((Date.now() - t) / 1000));
        if (sec < 60)         return 'a few seconds';
        var min = Math.floor(sec / 60);
        if (min < 60)         return min + ' minute' + (min === 1 ? '' : 's');
        var hr = Math.floor(min / 60);
        if (hr < 24)          return hr + ' hour' + (hr === 1 ? '' : 's');
        var day = Math.floor(hr / 24);
        if (day < 30)         return day + ' day' + (day === 1 ? '' : 's');
        var mo = Math.floor(day / 30);
        if (mo < 12)          return mo + ' month' + (mo === 1 ? '' : 's');
        var yr = Math.floor(mo / 12);
        return yr + ' year' + (yr === 1 ? '' : 's');
      } catch (e) { return ''; }
    }

    // ── v4.17.0: Single source of truth for SSID + password ─────────────
    // The enable flow now generates a fresh password server-side, pushes it
    // to Starlink, verifies it, and stores both SSID + password in
    // hotspot_config.json. The dashboard reads from there. No more cloud
    // round-trips on every dashboard load. No more bullets-vs-real-password
    // race conditions. The QR is guaranteed to work because we encode
    // exactly what the server pushed.
    //
    // Special case: if the user just came from the enable sheet, the new
    // password is already in window._dnHotspotState (carried forward from
    // the toggle response). Use it immediately so the dashboard renders
    // with credentials on the very first paint.
    if (window._dnHotspotState && window._dnHotspotState.wifi_password) {
      window._hsRealSsid = window._dnHotspotState.wifi_ssid     || '';
      window._hsPassword = window._dnHotspotState.wifi_password || '';
      var realElEarly = document.getElementById('hs-real-ssid');
      if (realElEarly && window._hsRealSsid) realElEarly.textContent = window._hsRealSsid;
    }

    DishNet.apiFetch(location.pathname + '?page=api&action=app_hotspot_status&router_id=' + encodeURIComponent(window._hsRouter))
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if (!resp || resp.status !== 'success' || !resp.data) return;

        // Location label (the customer-typed nickname)
        window._hsSsid = resp.data.ssid_on_enable || '';
        var ssidEl = document.getElementById('hs-ssid');
        if (ssidEl) ssidEl.textContent = window._hsSsid || (window._hsLocation || 'Hotspot');

        // Real Wi-Fi credentials — straight from our store, populated by
        // the enable flow (or by app_hotspot_resync via the Refresh button).
        if (resp.data.wifi_ssid) {
          window._hsRealSsid = resp.data.wifi_ssid;
          var realEl = document.getElementById('hs-real-ssid');
          if (realEl) realEl.textContent = window._hsRealSsid;
        } else {
          // No stored SSID — likely an old config from before v4.17.0.
          // Fall back to app_wifi_cache (last known from Change WiFi).
          loadCachedSsidPassword();
        }
        if (resp.data.wifi_password) {
          window._hsPassword = resp.data.wifi_password;
        }

        // v4.18.4: render relative-time labels from stored timestamps.
        // wifi_synced_at → "Last rotated 5 minutes ago"
        // enabled_at    → "Active for 2 hours"
        if (resp.data.wifi_synced_at) {
          var rotEl = document.getElementById('hs-pw-rotated');
          var rotRel = document.getElementById('hs-pw-rotated-rel');
          if (rotEl && rotRel) {
            rotRel.textContent = relTime(resp.data.wifi_synced_at);
            rotEl.style.display = '';
          }
        }
        if (resp.data.enabled_at) {
          var afEl = document.getElementById('hs-active-for');
          if (afEl) {
            afEl.textContent = 'Active for ' + durationSince(resp.data.enabled_at);
            afEl.style.display = '';
          }
        }

        // If hotspot mode flipped off behind our back (staff toggle),
        // bounce the user back to Site Detail.
        if (!resp.data.hotspot_mode) {
          history.back();
        }
      })
      .catch(function(){
        // app_hotspot_status failed — try cache as last resort.
        loadCachedSsidPassword();
      });

    // Live connected-clients count from data-report. v4.17.0: this is the
    // only thing we need from dr_wifi_get_status now. SSID and password
    // come from our own store. We still ignore the password field this
    // endpoint returns (it's redacted to bullets).
    fetch(drUrl('dr_wifi_get_status', { router_id: window._hsRouter }))
      .then(function(r){ return r.json(); })
      .then(function(data){
        var clients = (data && data.ok && Array.isArray(data.clients)) ? data.clients : [];
        var connected = 0;
        var liveFps = [];
        for (var i = 0; i < clients.length; i++) {
          var c = clients[i];
          if (c.is_controller) continue;
          var fp = c.fingerprint || c.mac;
          if (fp) liveFps.push(fp);
          if (c.paused) continue;
          connected++;
        }
        var ccEl = document.getElementById('hs-connected-count');
        if (ccEl) ccEl.textContent = connected;

        // v4.19.0: also record sightings + cross-reference with seen log
        // to surface NEW count on the dashboard. Fires only after we have
        // a non-empty client list.
        if (liveFps.length > 0) {
          // Fire record_seen first (best-effort), then fetch seen log
          var devs = [];
          for (var k = 0; k < clients.length; k++) {
            var cc = clients[k];
            if (cc.is_controller) continue;
            var ffp = cc.fingerprint || cc.mac;
            if (!ffp) continue;
            // v4.20.0: also send IP for forensic history
            devs.push({ fingerprint: ffp, hostname: cc.name || '', ip: cc.ip || cc.ip_address || '' });
          }
          DishNet.apiFetch(location.pathname + '?page=api&action=app_devices_record_seen', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ router_id: window._hsRouter, devices: devs })
          })
            .catch(function(){ /* non-fatal */ })
            .then(function(){
              // Now read back the seen log to count NEW currently-connected
              return DishNet.apiFetch(location.pathname + '?page=api&action=app_devices_get_seen&router_id=' + encodeURIComponent(window._hsRouter));
            })
            .then(function(r){ return r ? r.json() : null; })
            .then(function(resp){
              if (!resp || resp.status !== 'success' || !resp.data) return;
              var seenMap = {};
              for (var m = 0; m < resp.data.devices.length; m++) {
                var sd = resp.data.devices[m];
                if (sd.fingerprint) seenMap[sd.fingerprint] = sd;
              }
              var newCount = 0;
              for (var n = 0; n < liveFps.length; n++) {
                var info = seenMap[liveFps[n]];
                if (info && info.is_new) newCount++;
              }
              _renderDashboardNewBanner(newCount);
            })
            .catch(function(){});
        }
      })
      .catch(function(){ /* leave em-dash */ });

    // v4.19.0: NEW devices banner on the dashboard. Hidden if 0.
    // Tap → routes to devices view where the per-row controls live.
    function _renderDashboardNewBanner(newCount) {
      var banner = document.getElementById('hs-dash-new-banner');
      if (!banner) return;
      if (!newCount || newCount < 1) {
        banner.style.display = 'none';
        return;
      }
      var label = document.getElementById('hs-dash-new-label');
      if (label) label.textContent = newCount + ' new device' + (newCount === 1 ? '' : 's') + ' connected';
      banner.style.display = '';
    }

    // Cached SSID + password loader — fallback for old configs that
    // pre-date v4.17.0 (i.e. customers who enabled hotspot in v4.16.x and
    // haven't toggled since). After Disable→Re-enable on v4.17.0, the
    // store is fully populated and this path is never taken.
    function loadCachedSsidPassword() {
      var cacheUrl = location.pathname + '?page=api&action=app_wifi_get'
                   + '&router_id=' + encodeURIComponent(window._hsRouter)
                   + '&kit='       + encodeURIComponent(window._hsKit);
      DishNet.apiFetch(cacheUrl)
        .then(function(r){ return r.json(); })
        .then(function(cd){
          if (cd && cd.status === 'success' && cd.data) {
            if (cd.data.ssid && !window._hsRealSsid) {
              window._hsRealSsid = cd.data.ssid;
              var realEl = document.getElementById('hs-real-ssid');
              if (realEl) realEl.textContent = cd.data.ssid;
            } else if (!cd.data.ssid && !window._hsRealSsid) {
              var realEl2 = document.getElementById('hs-real-ssid');
              if (realEl2) realEl2.textContent = 'Network not available';
            }
            if (cd.data.password && !isRedactedPassword(cd.data.password) && !window._hsPassword) {
              window._hsPassword = cd.data.password;
            }
          } else if (!window._hsRealSsid) {
            var realEl3 = document.getElementById('hs-real-ssid');
            if (realEl3 && realEl3.textContent.indexOf('Loading') !== -1) {
              realEl3.textContent = 'Tap Refresh to sync';
            }
          }
        })
        .catch(function(){
          if (!window._hsRealSsid) {
            var realEl = document.getElementById('hs-real-ssid');
            if (realEl && realEl.textContent.indexOf('Loading') !== -1) {
              realEl.textContent = 'Tap Refresh to sync';
            }
          }
        });
    }

    function isRedactedPassword(p) {
      if (!p) return true;
      return /^[\u2022•*]+$/.test(p);
    }

    // Paused count — live from data-report
    fetch(drUrl('dr_wifi_list_paused_clients', { router_id: window._hsRouter }))
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data || !data.ok) return;
        var list = data.clients || data.paused || [];
        var n = Array.isArray(list) ? list.length : 0;
        var pcEl = document.getElementById('hs-paused-count');
        if (pcEl) pcEl.textContent = n;
        var sub = document.getElementById('hs-paused-sub');
        if (sub) sub.textContent = n === 0 ? 'None paused' : 'By you';
      })
      .catch(function(){
        // Fallback: derive from get_status if the dedicated endpoint isn't there
        fetch(drUrl('dr_wifi_get_status', { router_id: window._hsRouter }))
          .then(function(r){ return r.json(); })
          .then(function(d){
            if (!d || !d.ok) return;
            var n = 0; var cs = d.clients || [];
            for (var i = 0; i < cs.length; i++) if (cs[i].paused) n++;
            var pcEl = document.getElementById('hs-paused-count');
            if (pcEl) pcEl.textContent = n;
            var sub = document.getElementById('hs-paused-sub');
            if (sub) sub.textContent = n === 0 ? 'None paused' : 'By you';
          })
          .catch(function(){});
      });
  });

  // ── Reveal-on-tap password ──
  // v4.18.3: also flip the eye icon and the button title between
  // i-eye/Show and i-eye-off/Hide so the customer sees clear state.
  window.dnHotspotTogglePw = function() {
    var el  = document.getElementById('hs-pw');
    var btn = document.getElementById('hs-eye-btn');
    if (!el) return;
    var setIcon = function(iconId, title) {
      if (!btn) return;
      var use = btn.querySelector('use');
      if (use) use.setAttribute('href', iconId);
      btn.title = title;
    };
    if (window._hsPwRevealed) {
      el.textContent = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';
      el.classList.add('bullets');
      window._hsPwRevealed = false;
      setIcon('#i-eye', 'Show password');
    } else {
      if (!window._hsPassword) {
        el.textContent = 'Not set yet';
        el.classList.remove('bullets');
        return;
      }
      el.textContent = window._hsPassword;
      el.classList.remove('bullets');
      window._hsPwRevealed = true;
      setIcon('#i-eye-off', 'Hide password');
    }
  };

  // ── Copy password (with light feedback) ──
  window.dnHotspotCopyPw = function() {
    if (!window._hsPassword) {
      alert('Password not loaded yet. Try again in a moment.');
      return;
    }
    var done = function(){
      var btn = document.getElementById('hs-copy-btn');
      if (btn) {
        var orig = btn.innerHTML;
        btn.innerHTML = '<svg class="ic" style="width:14px;height:14px;color:var(--green)"><use href="#i-check"/></svg>';
        setTimeout(function(){ btn.innerHTML = orig; }, 1200);
      }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(window._hsPassword).then(done).catch(function(){
        // Fallback to legacy
        try {
          var ta = document.createElement('textarea');
          ta.value = window._hsPassword; document.body.appendChild(ta);
          ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
          done();
        } catch (e) { alert('Could not copy. Long-press the password to select it.'); }
      });
    } else {
      try {
        var ta = document.createElement('textarea');
        ta.value = window._hsPassword; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        done();
      } catch (e) { alert('Could not copy. Long-press the password to select it.'); }
    }
  };

  // ── QR code modal ──
  // Uses qrcode-svg (pwa/vendor/qrcode.min.js) which exposes window.QRCode.
  // The library is inlined in a <script> block at the END of this view (see
  // below the closing of this IIFE), so it's available synchronously once
  // the page has parsed. We just verify the global is present.
  function loadQrLib(cb) {
    if (window.QRCode) { cb(); return; }
    // Library failed to inline (rare — only if PHP couldn't read the file).
    // Tell the user instead of failing silently.
    alert('QR code library is not loaded. Please refresh the page and try again.');
  }

  window.dnHotspotShowQr = function() {
    // v4.16.0: gate on REAL SSID + password (what the QR encodes), not the
    // location label. Without the real SSID, the QR would either be empty
    // or unscannable.
    // v4.16.2: tell the user what's actually missing instead of a generic
    // "still loading" — a customer with an offline router needs different
    // guidance than one with a slow first load.
    if (!window._hsRealSsid && !window._hsPassword) {
      alert('Wi-Fi details could not be loaded. Make sure your router is online, then refresh.');
      return;
    }
    if (!window._hsRealSsid) {
      alert('Wi-Fi network name could not be loaded. Make sure your router is online, then refresh.');
      return;
    }
    if (!window._hsPassword) {
      alert('Wi-Fi password could not be loaded. Try opening Change Wi-Fi to reset it, then come back here.');
      return;
    }
    var bg = document.getElementById('hs-qr-modal');
    if (!bg) {
      bg = document.createElement('div');
      bg.id = 'hs-qr-modal';
      bg.className = 'hs-sheet-bg';
      bg.innerHTML =
        '<div class="hs-qr-card">' +
        '  <div style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--gray-2)">Scan to connect</div>' +
        '  <div id="hs-qr-svg-wrap" class="qr-svg" style="display:flex;align-items:center;justify-content:center;color:var(--gray-2);font-size:12px">Generating\u2026</div>' +
        '  <div class="hs-qr-ssid" id="hs-qr-ssid"></div>' +
        '  <div id="hs-qr-ctx" style="font-size:11px;color:var(--gray-2);margin-top:-4px;margin-bottom:6px"></div>' +
        '  <div class="hs-qr-pw" id="hs-qr-pw"></div>' +
        '  <button class="cta-alt" style="margin-top:14px" onclick="dnHotspotCloseQr()">Done</button>' +
        '</div>';
      document.body.appendChild(bg);
      bg.addEventListener('click', function(e){ if (e.target === bg) dnHotspotCloseQr(); });
    }
    bg.classList.add('show');
    // v4.16.0: QR encodes the REAL Wi-Fi SSID (broadcast by the router) — NOT
    // the location label. Phones look for the network on the air using the
    // SSID name, so the label would cause "network not found" failures.
    // The label is shown in smaller text above as context ("Sharing X's Wi-Fi").
    var realSsid = window._hsRealSsid || '';
    var label    = window._hsSsid || '';
    var qrSsidEl = document.getElementById('hs-qr-ssid');
    if (qrSsidEl) qrSsidEl.textContent = realSsid || 'Wi-Fi network';
    var qrCtxEl = document.getElementById('hs-qr-ctx');
    if (qrCtxEl) qrCtxEl.textContent = label ? ('From: ' + label) : '';
    document.getElementById('hs-qr-pw').textContent = window._hsPassword;

    loadQrLib(function(){
      try {
        // Standard Wi-Fi QR format: WIFI:T:WPA;S:<ssid>;P:<pw>;;
        // CRITICAL: must use the REAL broadcast SSID, not the customer's label.
        var content = 'WIFI:T:WPA;S:' + realSsid.replace(/([\\;,":])/g, '\\$1')
                    + ';P:' + (window._hsPassword || '').replace(/([\\;,":])/g, '\\$1') + ';;';
        var qr = new QRCode({
          content: content,
          padding: 2,
          width: 240,
          height: 240,
          color: '#141414',
          background: '#ffffff',
          ecl: 'M',
          join: true,
          container: 'svg-viewbox',
          xmlDeclaration: false
        });
        var svg = qr.svg();
        document.getElementById('hs-qr-svg-wrap').innerHTML = svg;
      } catch (e) {
        document.getElementById('hs-qr-svg-wrap').textContent = 'Could not generate QR.';
      }
    });
  };
  window.dnHotspotCloseQr = function() {
    var bg = document.getElementById('hs-qr-modal'); if (bg) bg.classList.remove('show');
  };

  // ── Disable confirm sheet ──
  window.dnHotspotShowDisableSheet = function() {
    var bg = document.getElementById('hs-disable-sheet');
    if (!bg) {
      bg = document.createElement('div');
      bg.id = 'hs-disable-sheet';
      bg.className = 'hs-sheet-bg';
      bg.innerHTML =
        '<div class="hs-sheet">' +
        '  <h3>Turn off hotspot mode?</h3>' +
        '  <p>This hides the hotspot dashboard. Your Wi-Fi network and password stay the same \u2014 nothing on the router changes. You can turn hotspot mode back on any time from Site Detail.</p>' +
        '  <button class="cta-red" id="hs-disable-go" onclick="dnHotspotConfirmDisable()">Turn off hotspot mode</button>' +
        '  <button class="cta-alt" onclick="dnHotspotCloseDisableSheet()">Cancel</button>' +
        '</div>';
      document.body.appendChild(bg);
      bg.addEventListener('click', function(e){ if (e.target === bg) dnHotspotCloseDisableSheet(); });
    }
    bg.classList.add('show');
  };
  window.dnHotspotCloseDisableSheet = function() {
    var bg = document.getElementById('hs-disable-sheet'); if (bg) bg.classList.remove('show');
  };
  window.dnHotspotConfirmDisable = function() {
    var go = document.getElementById('hs-disable-go');
    if (go) { go.disabled = true; go.textContent = 'Turning off…'; }
    var httpStatus = 0;
    DishNet.apiFetch(location.pathname + '?page=api&action=app_hotspot_toggle_mode', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ router_id: window._hsRouter, enable: false })
    })
      .then(function(r){ httpStatus = r.status; return r.text(); })
      .then(function(rawText){
        var resp = null;
        try { resp = JSON.parse(rawText); } catch (e) { resp = null; }
        if (resp && resp.status === 'success') {
          dnHotspotCloseDisableSheet();
          // Bounce back to Site Detail — the entry card will re-fetch and show "off"
          DishNet.goInternal('site_detail', { kit: window._hsKit });
        } else {
          if (go) { go.disabled = false; go.textContent = 'Turn off hotspot mode'; }
          var msg = (resp && (resp.message || resp.error))
                 || ('Server returned ' + httpStatus + (rawText ? (': ' + rawText.substring(0, 200)) : ''))
                 || 'Could not turn off hotspot mode.';
          alert(msg);
          try { console.error('[DishNet hotspot disable]', httpStatus, rawText); } catch (e) {}
        }
      })
      .catch(function(err){
        if (go) { go.disabled = false; go.textContent = 'Turn off hotspot mode'; }
        alert('Network error: ' + (err && err.message ? err.message : 'unknown'));
      });
  };

  // ── Check fiber availability — opens support page prefilled with kit ──
  window.dnHotspotCheckFiber = function() {
    var msg = 'Hi DishNet, I have a Starlink kit (' + window._hsKit + ') at ' +
              (window._hsLocation || 'my site') +
              '. Please check if DishNet Fiber is available at this location \u2014 ' +
              'I\'d like per-device speed caps and voucher access for my hotspot.';
    if (window.DishNet && typeof DishNet.openWhatsApp === 'function') {
      DishNet.openWhatsApp('+211921443002', msg);
    } else {
      // Fallback: route to internal support tab
      DishNet.go('support');
    }
  };
})();
</script>

<!-- ═══ v4.15.0: Inlined qrcode-svg library (MIT, 18.7KB minified) ═══
     We inline the library here rather than serving it as a static asset
     because UCRM plugins have no static-asset route in public.php and
     adding one is a bigger surface change than this small payload. The
     library exposes window.QRCode, which is consumed by dnHotspotShowQr()
     above. The script tag is rendered ONLY for this view, not portal-wide. -->
<script>
<?php
$qrLibPath = dirname(__DIR__, 2) . '/pwa/vendor/qrcode.min.js';
if (is_readable($qrLibPath)) {
    // file_get_contents is fine here — it's a static, plugin-local file
    // not user-supplied. No data-path concerns.
    echo file_get_contents($qrLibPath);
} else {
    // Defensive: if the file went missing, leave a clear console error and
    // let dnHotspotShowQr show its alert.
    echo "console.error('DishNet: qrcode.min.js missing from plugin/pwa/vendor/');";
}
?>
</script>
<?php endif; ?>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: HOTSPOT PASSWORD & ACCESS (s_hotspot_pw) — v4.15.0
// ══════════════════════════════════════════════════════════════════
// Detail screen for password management + connected devices with
// per-row pause/unpause. Shipped without:
//   - "Rotate now" button (rotation backend not built — HIDDEN entirely
//     per decision locked in brief, NOT shown as disabled)
//   - Activity classification labels (Streaming/Heavy/Light)
// Schedule chooser shows all five options but only "Manual" is
// selectable; others are visually present with "Soon" badges.
// ══════════════════════════════════════════════════════════════════
elseif ($view === 's_hotspot_pw'):
    $hsKit    = trim($_GET['kit'] ?? '');
    $hsRouter = trim($_GET['router'] ?? ($portalRouter['router_id_full'] ?? ''));
    $hsLocation = '';
    foreach ($portalSites as $ss) {
        if ($ss['kit_number'] === $hsKit) { $hsLocation = $ss['location']; break; }
    }
    if (!$hsRouter):
?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="history.back()"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Password &amp; access</div>
    <div class="scr-btn-ph"></div>
  </div>
</div>
<div class="scr-body"><div class="empty"><h3>No router found</h3></div></div>
<?php else: ?>
<div class="scr-head" style="padding-bottom:42px">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="history.back()"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Password &amp; access</div>
    <div class="scr-btn-ph"></div>
  </div>
  <div class="scr-eyebrow"><?= pe($hsLocation ?: $hsKit) ?> <span class="tech-tag sl-dark">Starlink router</span></div>
</div>
<div class="scr-body">

  <!-- Current password card (reveal on tap, copy button) -->
  <div class="rot-card">
    <div class="rot-top">
      <span class="rot-k">Current password</span>
      <span class="pill gray" id="hspw-source-pill">From router</span>
    </div>
    <div class="rot-window">
      <div class="rot-ic"><svg class="ic" style="width:16px;height:16px"><use href="#i-lock"/></svg></div>
      <div class="rot-t">
        <div class="rot-tt" id="hspw-pw" onclick="dnHspwToggle()" style="cursor:pointer;font-family:'Barlow',monospace;letter-spacing:2px">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</div>
        <div class="rot-ts">Tap to reveal. Long-press to select.</div>
      </div>
      <button class="sl-pw-code-btn" onclick="dnHspwCopy()" id="hspw-copy-btn" title="Copy"><svg class="ic" style="width:14px;height:14px"><use href="#i-copy"/></svg></button>
    </div>

    <!-- Rotation schedule chooser. Only "Manual" is selectable. The other
         four are visually present so customers know what's coming, but
         disabled with a "Soon" sub-label. The big "Rotate password now"
         button is INTENTIONALLY ABSENT (decision locked: hide entirely,
         not disabled-with-tooltip) until rotation backend ships. -->
    <div style="font-size:10px;color:var(--gray-2);font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:8px">Schedule</div>
    <div class="rot-choices">
      <div class="rot-choice on">Manual</div>
      <div class="rot-choice disabled" title="Coming soon">Hourly<span class="soon">Soon</span></div>
      <div class="rot-choice disabled" title="Coming soon">6h<span class="soon">Soon</span></div>
      <div class="rot-choice disabled" title="Coming soon">Daily<span class="soon">Soon</span></div>
      <div class="rot-choice disabled" title="Coming soon">Weekly<span class="soon">Soon</span></div>
    </div>
    <p style="font-size:11px;color:var(--gray-2);margin:14px 0 0;line-height:1.5">
      Use <a style="color:var(--red);font-weight:700" onclick="DishNet.goInternal('wifi_change',{kit:'<?= pe($hsKit) ?>',router:'<?= pe($hsRouter) ?>'});return false" href="#">Change Wi-Fi</a> to set a new password manually. Auto-rotation is coming soon.
    </p>
  </div>

  <!-- Connected devices, with per-row pause/unpause buttons -->
  <div class="sec-lbl">Connected devices <span class="sec-lbl-meta" id="hspw-dev-meta"></span></div>
  <div class="list-card" id="hspw-dev-list" style="position:relative;z-index:3">
    <div style="padding:24px;text-align:center;color:var(--gray);font-size:11px">Loading devices…</div>
  </div>

  <div class="hs-honest" style="margin-top:14px">
    <svg class="ic" style="width:14px;height:14px;color:var(--blue-dark,#1d4eb8);flex-shrink:0;margin-top:1px"><use href="#i-info"/></svg>
    <div class="hs-honest-t">
      <b>Pause cuts a device's internet right away.</b>
      Pausing kicks the device off your Wi-Fi until you unpause it. It works on every device on your dish &mdash; phones, laptops, the lot.
    </div>
  </div>

</div>

<script>
(function(){
  window._hspwKit      = '<?= pe($hsKit) ?>';
  window._hspwRouter   = '<?= pe($hsRouter) ?>';
  window._hspwPassword = '';
  window._hspwRevealed = false;
  window._hspwPaused   = {}; // fingerprint -> true when paused

  function ready(cb) {
    if (window.DishNet && typeof window.DishNet.apiFetch === 'function') { cb(); return; }
    var n = 0, iv = setInterval(function(){
      n += 50;
      if (window.DishNet && typeof window.DishNet.apiFetch === 'function') { clearInterval(iv); cb(); }
      else if (n > 3000) { clearInterval(iv); cb(); }
    }, 50);
  }
  function escHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

  function drUrl(action) {
    return location.href.split('/_plugins/')[0] + '/_plugins/dishnet-data-report/public.php?action=' + encodeURIComponent(action);
  }

  function deviceIcon(name) {
    var n = (name || '').toLowerCase();
    if (n.indexOf('iphone') !== -1 || n.indexOf('ipad') !== -1 || n.indexOf('apple') !== -1)
      return { icon:'#i-phone', bg:'rgba(212,28,28,.08)', color:'var(--red)' };
    if (n.indexOf('macbook') !== -1 || n.indexOf('laptop') !== -1 || n.indexOf('windows') !== -1 || n.indexOf('thinkpad') !== -1 || n.indexOf('dell') !== -1)
      return { icon:'#i-laptop', bg:'rgba(57,124,215,.12)', color:'#2b6fc4' };
    if (n.indexOf('samsung') !== -1 || n.indexOf('galaxy') !== -1 || n.indexOf('android') !== -1 || n.indexOf('xiaomi') !== -1 || n.indexOf('redmi') !== -1 || n.indexOf('tecno') !== -1 || n.indexOf('infinix') !== -1)
      return { icon:'#i-phone', bg:'var(--green-light)', color:'var(--green-mid)' };
    if (n.indexOf('tv') !== -1 || n.indexOf('roku') !== -1 || n.indexOf('chromecast') !== -1) return { icon:'#i-tv', bg:'rgba(139,92,246,.12)', color:'#7c3aed' };
    return { icon:'#i-wifi', bg:'var(--off-white)', color:'var(--dark)' };
  }

  function subtitleFor(c) {
    // Brief lock: subtitles show only IP/MAC/signal — no invented activity labels
    var parts = [];
    if (c.band === 'wired') {
      if (c.ip) parts.push(c.ip);
      parts.push('Wired');
      return parts.join(' \u00b7 ');
    }
    if (c.ip) parts.push(c.ip);
    if (c.signal_dbm) parts.push(c.signal_dbm + ' dBm');
    else if (c.signal_bucket === 'good') parts.push('Strong signal');
    else if (c.signal_bucket === 'weak') parts.push('Weak signal');
    else if (c.signal_bucket === 'poor') parts.push('Poor signal');
    return parts.join(' \u00b7 ') || 'Wi-Fi';
  }

  function renderRow(c) {
    var ico = deviceIcon(c.name);
    // Match prototype's pause-row treatment: muted bg, struck-through name, paused tag
    var paused = !!c.paused;
    var rowCls = 'cd-row' + (paused ? ' is-paused' : '');
    var icCls  = 'cd-row-ic' + (paused ? ' paused' : '');
    var dotCls = paused ? 'paused' : (c.signal_bucket === 'weak' ? 'weak' : (c.signal_bucket === 'poor' ? 'poor' : 'good'));
    var dotHtml = (c.band !== 'wired') ? ('<span class="cd-dot ' + dotCls + '"></span>') : '';
    var bandTag = '';
    if (paused) bandTag = '<span class="cd-tag paused-tag">Paused</span>';
    else if (c.band === '5')   bandTag = '<span class="cd-tag band5">5 GHz</span>';
    else if (c.band === '2.4') bandTag = '<span class="cd-tag band24">2.4 GHz</span>';
    else if (c.band === 'wired') bandTag = '<span class="cd-tag wired"><svg class="ic" style="width:8px;height:8px;vertical-align:-1px;margin-right:2px"><use href="#i-ether"/></svg>Wired</span>';

    // Use fingerprint (Starlink redacts MAC), fall back to MAC if needed
    var fp = c.fingerprint || c.mac || '';
    var fpJs = fp.replace(/'/g, "\\'");

    var btnHtml;
    if (paused) {
      btnHtml = '<button class="cd-pause is-paused" title="Unpause" onclick="event.stopPropagation();dnHspwUnpause(\'' + fpJs + '\')"><svg class="ic"><use href="#i-play"/></svg></button>';
    } else {
      btnHtml = '<button class="cd-pause" title="Pause this device" onclick="event.stopPropagation();dnHspwShowPauseConfirm(\'' + fpJs + '\',\'' + escHtml(c.name||'').replace(/'/g,"\\'") + '\')"><svg class="ic"><use href="#i-pause"/></svg></button>';
    }

    return '<div class="' + rowCls + '">'
      + '<div class="' + icCls + '" style="' + (paused ? '' : 'background:' + ico.bg + ';color:' + ico.color) + '"><svg class="ic"><use href="' + ico.icon + '"/></svg>' + dotHtml + '</div>'
      + '<div class="cd-t">'
      +   '<div class="cd-top"><span class="cd-name">' + escHtml(c.name) + '</span>' + bandTag + '</div>'
      +   '<div class="cd-sub">' + escHtml(subtitleFor(c)) + '</div>'
      + '</div>'
      + '<div class="cd-right">' + btnHtml + '</div>'
      + '</div>';
  }

  function fetchAndRender() {
    // v4.18.5: log on failure so we can see what's actually wrong. The
    // dashboard's stats card uses this same endpoint successfully — so
    // when this view shows "Network error" while the dashboard shows
    // a real connected count, something specific to this view's fetch
    // is wrong. Logging helps diagnose without another release cycle.
    var url = drUrl('dr_wifi_get_status') + '&router_id=' + encodeURIComponent(window._hspwRouter);
    var httpStatus = 0;
    fetch(url, { credentials: 'same-origin' })
      .then(function(r){ httpStatus = r.status; return r.text(); })
      .then(function(rawText){
        var data = null;
        try { data = JSON.parse(rawText); } catch (e) {
          try { console.error('[hspw] JSON parse failed', { url: url, status: httpStatus, body: rawText.substring(0, 500) }); } catch (_) {}
          throw new Error('Bad JSON from server (HTTP ' + httpStatus + ')');
        }
        var listEl = document.getElementById('hspw-dev-list');
        var meta   = document.getElementById('hspw-dev-meta');
        if (!listEl) return;
        if (!data || !data.ok) {
          var why = (data && data.error) ? data.error : 'Router offline';
          listEl.innerHTML = '<div style="padding:24px;text-align:center;color:var(--gray);font-size:11px">' + escHtml(why) + ' \u2014 can\u2019t list devices right now.</div>';
          if (meta) meta.textContent = '';
          return;
        }
        var clients = data.clients || [];
        // v4.16.0: do NOT store the password from dr_wifi_get_status — it's
        // redacted to bullet characters. We fetch the real password via
        // get_config (cloud-live) below, with a cached fallback. Same fix as
        // the s_hotspot dashboard.

        // Filter out controllers (router management, not user devices)
        var users = [];
        var pausedCount = 0;
        for (var i = 0; i < clients.length; i++) {
          if (clients[i].is_controller) continue;
          users.push(clients[i]);
          if (clients[i].paused) pausedCount++;
          if (clients[i].fingerprint) window._hspwPaused[clients[i].fingerprint] = !!clients[i].paused;
        }
        // Sort: active first, then paused
        users.sort(function(a, b){
          if (!!a.paused !== !!b.paused) return a.paused ? 1 : -1;
          return (a.name || '').localeCompare(b.name || '');
        });

        if (users.length === 0) {
          listEl.innerHTML = '<div style="padding:24px;text-align:center;color:var(--gray);font-size:11px">No devices connected right now.</div>';
        } else {
          var html = '';
          for (var j = 0; j < users.length; j++) html += renderRow(users[j]);
          listEl.innerHTML = html;
        }
        var active = users.length - pausedCount;
        if (meta) meta.textContent = active + ' active \u00b7 ' + pausedCount + ' paused';
      })
      .catch(function(err){
        try { console.error('[hspw] fetchAndRender failed', { url: url, status: httpStatus, error: (err && err.message) || err }); } catch (_) {}
        var listEl = document.getElementById('hspw-dev-list');
        if (listEl) {
          var msg = 'Network error. Pull down to retry.';
          if (err && err.message && err.message.indexOf('Bad JSON') !== -1) {
            msg = err.message + '. Pull down to retry.';
          }
          listEl.innerHTML = '<div style="padding:24px;text-align:center;color:var(--gray);font-size:11px">' + escHtml(msg) + '</div>';
        }
      });
  }

  // ── Pause confirm sheet (per brief: pause needs light confirm to prevent mis-clicks) ──
  window.dnHspwShowPauseConfirm = function(fingerprint, name) {
    var bg = document.getElementById('hspw-pause-sheet');
    if (!bg) {
      bg = document.createElement('div');
      bg.id = 'hspw-pause-sheet';
      bg.className = 'hs-sheet-bg';
      bg.innerHTML =
        '<div class="hs-sheet">' +
        '  <h3 id="hspw-pause-h">Pause this device?</h3>' +
        '  <p id="hspw-pause-p">This cuts the device off Wi-Fi right away. You can unpause any time.</p>' +
        '  <button class="cta-red" id="hspw-pause-go">Pause now</button>' +
        '  <button class="cta-alt" onclick="dnHspwClosePauseSheet()">Cancel</button>' +
        '</div>';
      document.body.appendChild(bg);
      bg.addEventListener('click', function(e){ if (e.target === bg) dnHspwClosePauseSheet(); });
    }
    var safeName = name || 'this device';
    document.getElementById('hspw-pause-h').textContent = 'Pause ' + safeName + '?';
    document.getElementById('hspw-pause-p').textContent = safeName + ' will be cut off your Wi-Fi straight away. You can unpause any time.';
    var go = document.getElementById('hspw-pause-go');
    go.disabled = false;
    go.textContent = 'Pause now';
    go.onclick = function(){ dnHspwPause(fingerprint); };
    bg.classList.add('show');
  };
  window.dnHspwClosePauseSheet = function() {
    var bg = document.getElementById('hspw-pause-sheet'); if (bg) bg.classList.remove('show');
  };
  window.dnHspwPause = function(fingerprint) {
    var go = document.getElementById('hspw-pause-go');
    if (go) { go.disabled = true; go.textContent = 'Pausing…'; }
    fetch(drUrl('dr_wifi_pause_client'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ router_id: window._hspwRouter, client_id: fingerprint, by: 'customer' })
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data && data.ok) {
          dnHspwClosePauseSheet();
          fetchAndRender();
        } else {
          if (go) { go.disabled = false; go.textContent = 'Try again'; }
          alert((data && data.error) || 'Could not pause. Try again.');
        }
      })
      .catch(function(){
        if (go) { go.disabled = false; go.textContent = 'Try again'; }
        alert('Network error. Try again.');
      });
  };
  // Unpause is direct — no confirm (reversible action)
  window.dnHspwUnpause = function(fingerprint) {
    fetch(drUrl('dr_wifi_unpause_client'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ router_id: window._hspwRouter, client_id: fingerprint, by: 'customer' })
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data && data.ok) fetchAndRender();
        else alert((data && data.error) || 'Could not unpause. Try again.');
      })
      .catch(function(){ alert('Network error. Try again.'); });
  };

  // ── Password reveal/copy (top of screen) ──
  window.dnHspwToggle = function() {
    var el = document.getElementById('hspw-pw');
    if (!el) return;
    if (window._hspwRevealed) {
      el.textContent = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';
      window._hspwRevealed = false;
    } else {
      if (!window._hspwPassword) { el.textContent = 'Not loaded'; return; }
      el.textContent = window._hspwPassword;
      window._hspwRevealed = true;
    }
  };
  window.dnHspwCopy = function() {
    if (!window._hspwPassword) { alert('Password not loaded yet. Try again in a moment.'); return; }
    var done = function(){
      var btn = document.getElementById('hspw-copy-btn');
      if (btn) {
        var orig = btn.innerHTML;
        btn.innerHTML = '<svg class="ic" style="width:14px;height:14px;color:var(--green)"><use href="#i-check"/></svg>';
        setTimeout(function(){ btn.innerHTML = orig; }, 1200);
      }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(window._hspwPassword).then(done).catch(function(){ alert('Could not copy.'); });
    } else {
      try {
        var ta = document.createElement('textarea');
        ta.value = window._hspwPassword; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        done();
      } catch (e) { alert('Could not copy.'); }
    }
  };

  // ── Real password loader (separate from device list).
  // v4.17.0: prefer the password from app_hotspot_status (our own store,
  // populated on enable). Fall back to dr_wifi_get_config (live cloud) and
  // then app_wifi_get (cached) for legacy configs that pre-date v4.17.0.
  function isRedactedPassword(p) {
    if (!p) return true;
    return /^[\u2022\u2022*]+$/.test(p);
  }
  function setRevealed(pw) {
    window._hspwPassword = pw;
    if (window._hspwRevealed) {
      var el = document.getElementById('hspw-pw');
      if (el) el.textContent = pw;
    }
  }
  function loadRealPassword() {
    // Path A — our own store (v4.17.0+ populated by enable flow).
    DishNet.apiFetch(location.pathname + '?page=api&action=app_hotspot_status&router_id=' + encodeURIComponent(window._hspwRouter))
      .then(function(r){ return r.json(); })
      .then(function(resp){
        if (resp && resp.status === 'success' && resp.data && resp.data.wifi_password
            && !isRedactedPassword(resp.data.wifi_password)) {
          setRevealed(resp.data.wifi_password);
          return;
        }
        return Promise.reject(new Error('store-empty'));
      })
      .catch(function(){
        // Path B — live cloud fetch
        fetch(drUrl('dr_wifi_get_config') + '&router_id=' + encodeURIComponent(window._hspwRouter))
          .then(function(r){ return r.json(); })
          .then(function(data){
            if (data && data.ok && data.networks && data.networks.length > 0) {
              var pw = data.networks[0].password || '';
              if (!isRedactedPassword(pw)) { setRevealed(pw); return; }
            }
            return Promise.reject(new Error('cloud-empty'));
          })
          .catch(function(){
            // Path C — cached
            var cacheUrl = location.pathname + '?page=api&action=app_wifi_get'
                         + '&router_id=' + encodeURIComponent(window._hspwRouter)
                         + '&kit='       + encodeURIComponent(window._hspwKit);
            DishNet.apiFetch(cacheUrl)
              .then(function(r){ return r.json(); })
              .then(function(cd){
                if (cd && cd.status === 'success' && cd.data && cd.data.password
                    && !isRedactedPassword(cd.data.password)) {
                  setRevealed(cd.data.password);
                }
              })
              .catch(function(){ /* leave empty */ });
          });
      });
  }

  // ── Kick off ──
  ready(function(){
    fetchAndRender();
    loadRealPassword();
  });

  // Auto-refresh every 20s while page is visible (slower than the main devices
  // view because this screen is interactive — refreshing too often makes the
  // pause/unpause buttons re-render mid-tap).
  var pollId = setInterval(function(){
    if (document.visibilityState === 'hidden') return;
    fetchAndRender();
  }, 20000);
  window.addEventListener('pagehide', function(){ clearInterval(pollId); });
})();
</script>
<?php endif; ?>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: DEBUG PANEL
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'debug_panel'):
?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.go('account')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Diagnostics</div>
    <div style="width:32px"></div>
  </div>
</div>
<div class="scr-body" style="padding-top:0">
  <div style="background:#fff;border-radius:14px;margin-top:-24px;padding:18px;position:relative;z-index:3;box-shadow:0 2px 8px rgba(0,0,0,.06)">
    <div style="font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:16px;color:var(--dark);margin-bottom:10px">Device Information</div>
    <div id="debug-device" style="font-size:11px;color:var(--gray);line-height:2;font-family:monospace"></div>
  </div>

  <div class="sec-lbl" style="margin-top:16px">Network</div>
  <div class="list-card">
    <div id="debug-network" style="padding:14px;font-size:11px;color:var(--gray);line-height:2;font-family:monospace"></div>
  </div>

  <div class="sec-lbl">Portal</div>
  <div class="list-card">
    <div id="debug-portal" style="padding:14px;font-size:11px;color:var(--gray);line-height:2;font-family:monospace"></div>
  </div>

  <div class="sec-lbl">API Health</div>
  <div class="list-card">
    <div id="debug-api" style="padding:14px;font-size:11px;color:var(--gray);line-height:2;font-family:monospace">
      <span style="color:var(--amber)">Testing...</span>
    </div>
  </div>

  <div class="sec-lbl">Console Log</div>
  <div class="list-card">
    <div id="debug-log" style="padding:14px;font-size:10px;color:var(--gray);line-height:1.8;font-family:monospace;max-height:200px;overflow-y:auto"></div>
  </div>

  <button class="cta-red" onclick="DishNet.sendDebugReport()" id="debug-send-btn" style="margin-top:16px">
    <svg class="ic" style="width:14px;height:14px"><use href="#i-wa"/></svg>
    Send debug report via WhatsApp
  </button>
  <div id="debug-send-result" style="display:none;margin-top:10px;padding:10px 14px;border-radius:8px;font-size:12px"></div>
</div>

<script>
(function(){
  var logBuffer = [];

  // Capture console.log/error/warn
  var origLog = console.log, origErr = console.error, origWarn = console.warn;
  function addLog(type, args) {
    var msg = Array.prototype.slice.call(args).map(function(a){ return typeof a === 'object' ? JSON.stringify(a) : String(a); }).join(' ');
    logBuffer.push({ t: new Date().toLocaleTimeString(), type: type, msg: msg });
    if (logBuffer.length > 50) logBuffer.shift();
    var el = document.getElementById('debug-log');
    if (el) {
      var color = type === 'error' ? 'var(--danger-text)' : (type === 'warn' ? 'var(--amber-dark)' : 'var(--gray)');
      el.innerHTML += '<div style="color:' + color + '">[' + type + '] ' + msg.replace(/</g,'&lt;').substring(0,200) + '</div>';
      el.scrollTop = el.scrollHeight;
    }
  }
  console.log = function(){ addLog('log', arguments); origLog.apply(console, arguments); };
  console.error = function(){ addLog('err', arguments); origErr.apply(console, arguments); };
  console.warn = function(){ addLog('warn', arguments); origWarn.apply(console, arguments); };

  // Capture unhandled errors
  window.addEventListener('error', function(e) {
    addLog('err', ['Uncaught: ' + e.message + ' at ' + (e.filename||'?') + ':' + (e.lineno||'?')]);
  });

  // Device info
  var dev = document.getElementById('debug-device');
  if (dev) {
    var isNative = !!window.Android;
    var lines = [
      'User Agent: ' + navigator.userAgent,
      'Platform: ' + (navigator.platform || '?'),
      'Screen: ' + screen.width + 'x' + screen.height + ' @' + (window.devicePixelRatio||1) + 'x',
      'Viewport: ' + window.innerWidth + 'x' + window.innerHeight,
      'Language: ' + (navigator.language || '?'),
      'Online: ' + navigator.onLine,
      'Native app: ' + (isNative ? 'Yes (Android WebView)' : 'No (PWA/Browser)'),
      'Cookies enabled: ' + navigator.cookieEnabled,
      'Touch: ' + ('ontouchstart' in window ? 'Yes' : 'No'),
    ];
    if (isNative && window.Android.getAppVersion) lines.push('App version: ' + window.Android.getAppVersion());
    if (isNative && window.Android.getBiometricState) {
      try { var bs = JSON.parse(window.Android.getBiometricState()); lines.push('Biometric: ' + (bs.available ? 'Available' : 'Not available') + ', ' + (bs.enabled ? 'Enabled' : 'Disabled')); } catch(e){}
    }
    dev.innerHTML = lines.join('<br>');
  }

  // Network info
  var net = document.getElementById('debug-network');
  if (net) {
    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    var netLines = ['Online: ' + navigator.onLine];
    if (conn) {
      netLines.push('Type: ' + (conn.effectiveType || '?'));
      netLines.push('Downlink: ' + (conn.downlink || '?') + ' Mbps');
      netLines.push('RTT: ' + (conn.rtt || '?') + ' ms');
      netLines.push('Save-Data: ' + (conn.saveData ? 'Yes' : 'No'));
    } else {
      netLines.push('Network Info API: Not available');
    }
    // WiFi SSID detection (only works in native)
    if (window.Android && window.Android.getWifiSsid) {
      netLines.push('Connected WiFi: ' + window.Android.getWifiSsid());
    } else {
      netLines.push('WiFi SSID: Not detectable (browser limitation)');
    }
    net.innerHTML = netLines.join('<br>');
  }

  // Portal info
  var portal = document.getElementById('debug-portal');
  if (portal) {
    var token = window.DishNet ? DishNet._token : '';
    var claims = {};
    if (token) {
      try { claims = JSON.parse(atob(token.split('.')[1])); } catch(e){}
    }
    var pLines = [
      'Version: v4.12.20',
      'Client ID: ' + (claims.sub || '?'),
      'Phone: ' + (claims.phone || '?'),
      'Name: ' + (claims.name || '?'),
      'Token expires: ' + (claims.exp ? new Date(claims.exp * 1000).toLocaleString() : '?'),
      'Token issued: ' + (claims.iat ? new Date(claims.iat * 1000).toLocaleString() : '?'),
      'URL: ' + location.href.substring(0, 100),
      'Cookie: ' + (document.cookie ? document.cookie.substring(0, 60) + '...' : 'none'),
    ];
    portal.innerHTML = pLines.join('<br>');
  }

  // API health check
  var apiEl = document.getElementById('debug-api');
  if (apiEl) {
    var apiUrl = location.pathname + '?page=api&action=app_health';
    var token = window.DishNet ? DishNet._token : '';
    var t0 = performance.now();
    fetch(apiUrl, { headers: { 'Authorization': 'Bearer ' + token } })
      .then(function(r) {
        var ms = Math.round(performance.now() - t0);
        return r.text().then(function(txt) {
          var apiLines = [
            'Status: ' + r.status + ' ' + r.statusText,
            'Response time: ' + ms + 'ms',
            'Response: ' + txt.substring(0, 200),
          ];
          apiEl.innerHTML = apiLines.join('<br>');
          if (r.status === 200) apiEl.style.color = 'var(--green-mid)';
          else apiEl.style.color = 'var(--danger-text)';
        });
      })
      .catch(function(e) {
        apiEl.innerHTML = '<span style="color:var(--danger-text)">API unreachable: ' + e.message + '</span>';
      });
  }

  // Store debug data for sending
  window._debugData = function() {
    var conn = navigator.connection || {};
    var token = window.DishNet ? DishNet._token : '';
    var claims = {};
    if (token) { try { claims = JSON.parse(atob(token.split('.')[1])); } catch(e){} }

    // WiFi SSID from native
    var wifiSsid = '?';
    if (window.Android && window.Android.getWifiSsid) { try { wifiSsid = window.Android.getWifiSsid(); } catch(e){ wifiSsid = 'error'; } }

    // Battery
    var battery = null;
    if (navigator.getBattery) { try { navigator.getBattery().then(function(b){ battery = { level: Math.round(b.level*100), charging: b.charging }; }); } catch(e){} }

    // Current view from URL
    var urlParams = new URLSearchParams(location.search);
    var currentView = urlParams.get('view') || 'home';

    return {
      timestamp: new Date().toISOString(),
      device: {
        userAgent: navigator.userAgent,
        platform: navigator.platform,
        screen: screen.width + 'x' + screen.height,
        viewport: window.innerWidth + 'x' + window.innerHeight,
        dpr: window.devicePixelRatio,
        language: navigator.language,
        online: navigator.onLine,
        isNative: !!window.Android,
        touch: 'ontouchstart' in window,
        memory: navigator.deviceMemory || '?',
        cores: navigator.hardwareConcurrency || '?',
      },
      network: {
        type: conn.effectiveType || null,
        downlink: conn.downlink || null,
        rtt: conn.rtt || null,
        saveData: conn.saveData || false,
        wifiSsid: wifiSsid,
      },
      portal: {
        version: 'v4.12.20',
        url: location.href,
        view: currentView,
        clientId: claims.sub || '?',
        phone: claims.phone || '?',
        name: claims.name || '?',
        tokenExpires: claims.exp ? new Date(claims.exp * 1000).toISOString() : '?',
        cookiePresent: !!document.cookie,
      },
      battery: battery,
      logs: logBuffer.slice(-20),
    };
  };
})();
</script>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: SERVICE STATUS
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'service_status'):
?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.go('support')"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Service status</div>
    <div style="width:32px"></div>
  </div>
  <div style="font-size:12px;color:rgba(255,255,255,.55);position:relative;z-index:2">
    Juba, South Sudan · Updated just now
  </div>
</div>
<div class="scr-body" style="padding-top:0">
  <!-- Overall status banner — v4.21.42: reflects actual paused state -->
  <?php if (!empty($portalIsPaused)): ?>
    <div onclick="DishNet.go('invoices')" style="background:#FFEBEE;border:1px solid #FFA8A8;border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:10px;margin-top:-24px;position:relative;z-index:3;box-shadow:0 2px 6px rgba(212,28,28,.08);cursor:pointer">
      <div style="width:32px;height:32px;border-radius:8px;background:#D41C1C;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg class="ic" style="width:16px;height:16px"><use href="#i-clock"/></svg>
      </div>
      <div style="flex:1;font-size:13px;color:#7A1010;font-weight:600;line-height:1.4">
        Your internet is paused.
        <?php if (($portalUnpaidTotal ?? 0) > 0): ?>
        Pay <b>$<?= number_format((float)$portalUnpaidTotal, 0) ?></b> to restore service — reconnects automatically within seconds.
        <?php else: ?>
        Pay your outstanding balance to restore service — reconnects automatically within seconds.
        <?php endif; ?>
      </div>
      <span style="font-size:22px;color:#7A1010;opacity:.5">›</span>
    </div>
  <?php else: ?>
    <div style="background:var(--green-light);border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:10px;margin-top:-24px;position:relative;z-index:3">
      <div style="width:32px;height:32px;border-radius:8px;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg class="ic" style="width:16px;height:16px"><use href="#i-check"/></svg>
      </div>
      <div style="flex:1;font-size:13px;color:var(--green-mid);font-weight:600">All services operational</div>
    </div>
  <?php endif; ?>

  <!-- Starlink -->
  <div class="sec-lbl" style="margin-top:18px">Network services</div>
  <div class="list-card">
    <div style="padding:16px">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <div style="width:36px;height:36px;border-radius:9px;background:var(--dark);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg class="ic" style="width:16px;height:16px"><use href="#i-wifi"/></svg>
        </div>
        <div style="flex:1">
          <div style="font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:15px;color:var(--dark)">Starlink</div>
          <div style="font-size:11px;color:var(--gray)">
            <?php if (!empty($portalIsPaused)): ?>
              Your service · paused
            <?php else: ?>
              All regions
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($portalIsPaused)): ?>
          <span class="pill" style="background:#FFEBEE;color:#7A1010;border:1px solid #FFA8A8">Paused</span>
        <?php else: ?>
          <span class="pill green">Operational</span>
        <?php endif; ?>
      </div>
      <!-- 24h uptime bars — show red ticks for the paused tail when paused -->
      <div style="display:flex;gap:2px;margin-bottom:8px">
        <?php for ($i = 0; $i < 24; $i++): ?>
        <?php $isLastFew = !empty($portalIsPaused) && $i >= 20; ?>
        <div style="flex:1;height:16px;background:<?= $isLastFew ? '#D41C1C' : 'var(--green)' ?>;border-radius:2px;opacity:.7"></div>
        <?php endfor; ?>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--gray-2)">
        <?php if (!empty($portalIsPaused)): ?>
          <span>Currently paused — pay to restore</span>
          <span>Updated just now</span>
        <?php else: ?>
          <span>24h uptime <b style="color:var(--green-mid)">100%</b></span>
          <span>24 hours ago → now</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Fiber -->
  <div class="list-card" style="margin-top:10px">
    <div style="padding:16px">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <div style="width:36px;height:36px;border-radius:9px;background:var(--blue);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg class="ic" style="width:16px;height:16px"><use href="#i-speed"/></svg>
        </div>
        <div style="flex:1">
          <div style="font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:15px;color:var(--dark)">Fiber</div>
          <div style="font-size:11px;color:var(--gray)">Juba metro areas</div>
        </div>
        <span class="pill green">Operational</span>
      </div>
      <div style="display:flex;gap:2px;margin-bottom:8px">
        <?php for ($i = 0; $i < 24; $i++): ?>
        <div style="flex:1;height:16px;background:var(--green);border-radius:2px;opacity:.7"></div>
        <?php endfor; ?>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--gray-2)">
        <span>24h uptime <b style="color:var(--green-mid)">99.8%</b></span>
        <span>24 hours ago → now</span>
      </div>
    </div>
  </div>

  <!-- LTE -->
  <div class="list-card" style="margin-top:10px">
    <div style="padding:16px">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <div style="width:36px;height:36px;border-radius:9px;background:var(--amber);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg class="ic" style="width:16px;height:16px"><use href="#i-speed"/></svg>
        </div>
        <div style="flex:1">
          <div style="font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:15px;color:var(--dark)">4G LTE</div>
          <div style="font-size:11px;color:var(--gray)">Juba, Yei, Wau</div>
        </div>
        <span class="pill green">Operational</span>
      </div>
      <div style="display:flex;gap:2px;margin-bottom:8px">
        <?php for ($i = 0; $i < 24; $i++): ?>
        <div style="flex:1;height:16px;background:var(--green);border-radius:2px;opacity:.7"></div>
        <?php endfor; ?>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--gray-2)">
        <span>24h uptime <b style="color:var(--green-mid)">99.5%</b></span>
        <span>24 hours ago → now</span>
      </div>
    </div>
  </div>

  <!-- Report issue -->
  <div class="sec-lbl" style="margin-top:20px">Experiencing issues?</div>
  <div class="list-card">
    <div class="list-row" onclick="DishNet.openWhatsApp('+211921443002', 'My internet is down. Service: Starlink. Location: Juba.')">
      <div class="list-ic" style="background:var(--danger-light);color:var(--danger-text)"><svg class="ic"><use href="#i-warn"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Report an outage</div>
        <div class="list-ts">Let us know if your service is down</div>
      </div>
      <span class="chev">›</span>
    </div>
    <div class="list-row" onclick="DishNet.goInternal('speed_test')">
      <div class="list-ic"><svg class="ic"><use href="#i-speed"/></svg></div>
      <div class="list-t">
        <div class="list-tt">Run a speed test</div>
        <div class="list-ts">Check your connection right now</div>
      </div>
      <span class="chev">›</span>
    </div>
  </div>

  <!-- Info -->
  <div style="margin-top:16px;padding:14px 16px;background:#fff;border-radius:12px;border:1px solid rgba(0,0,0,.04)">
    <div style="font-size:11px;color:var(--gray);line-height:1.6">
      Status data is refreshed automatically. If you're experiencing issues but the status shows operational, it may be specific to your location. Contact support for help.
    </div>
  </div>
</div>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: WIFI SITE (full-page WiFi change for a specific site)
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'wifi_site'):
    $wsKit = trim($_GET['kit'] ?? '');
    $wsRouter = trim($_GET['router'] ?? ($portalRouter['router_id_full'] ?? ''));
    $wsLocation = '';
    foreach ($portalSites as $ss) {
        if ($ss['kit_number'] === $wsKit) { $wsLocation = $ss['location']; break; }
    }
    if (!$wsLocation) $wsLocation = $portalCustomerName;
?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.goInternal('site_detail',{kit:'<?= pe($wsKit) ?>'})"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Change WiFi</div>
    <div style="width:32px"></div>
  </div>
  <div style="font-size:12px;color:rgba(255,255,255,.55);position:relative;z-index:2">
    <?= pe($wsLocation) ?> · <?= pe($wsKit) ?>
  </div>
</div>
<script>window._siteRouterId = '<?= pe($wsRouter) ?>'; window._siteLocation = '<?= pe($wsLocation) ?>'; window._siteKit = '<?= pe($wsKit) ?>';</script>

<div class="scr-body">
  <?php
    // v4.21.102: Banner shown when this site's KIT is paused (auto-blocked).
    // Backend dr_wifi_change_password rejects submissions while paused — this
    // banner just makes the reason visible upfront so customer doesn't bother.
    $thisKitPaused = !empty($portalIsPaused) && in_array(strtoupper(trim($wsKit)), array_map('strtoupper', $portalPausedKits ?? []), true);
    if ($thisKitPaused):
  ?>
    <div onclick="DishNet.go('invoices')" style="background:#FFEBEE;border:1px solid #FFA8A8;border-radius:14px;padding:16px;margin-top:-24px;margin-bottom:16px;position:relative;z-index:3;cursor:pointer;box-shadow:0 2px 6px rgba(212,28,28,.08)">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <div style="width:32px;height:32px;border-radius:8px;background:#D41C1C;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg class="ic" style="width:16px;height:16px"><use href="#i-lock"/></svg>
        </div>
        <div style="font-weight:700;color:#7A1010;font-size:14px">Service paused — WiFi changes disabled</div>
      </div>
      <div style="font-size:12px;color:#7A1010;line-height:1.5">
        Your internet for <b><?= pe($wsLocation) ?></b> (<?= pe($wsKit) ?>) is currently paused due to an outstanding balance.
        <?php if (($portalUnpaidTotal ?? 0) > 0): ?>
        <br><br><b>Pay $<?= number_format((float)$portalUnpaidTotal, 0) ?></b> to restore service — your dish will reconnect automatically within seconds.
        <?php else: ?>
        <br><br>Tap here to view your invoices.
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
  <!-- WiFi icon hero -->
  <div style="text-align:center;padding:24px;background:#fff;border-radius:16px;margin-top:-24px;box-shadow:0 2px 8px rgba(0,0,0,.06);position:relative;z-index:3">
    <div style="width:56px;height:56px;border-radius:50%;background:var(--off-white);display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
      <svg class="ic" style="width:26px;height:26px;color:var(--dark)"><use href="#i-wifi"/></svg>
    </div>
    <div style="font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:18px;color:var(--dark);margin-bottom:4px"><?= pe($wsLocation) ?></div>
    <div style="font-size:12px;color:var(--gray)">Changes are sent via Starlink cloud and take up to 30 seconds</div>
  </div>

  <!-- Current WiFi (loaded from cache) -->
  <div id="wifi-current-card" style="display:none;margin-top:14px">
    <div class="list-card" style="padding:16px">
      <div style="font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--gray-2);margin-bottom:10px">Current WiFi</div>
      <div style="display:flex;align-items:center;gap:12px">
        <div style="width:40px;height:40px;border-radius:10px;background:var(--green-light);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg class="ic" style="color:var(--green-mid)"><use href="#i-wifi"/></svg>
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:16px;color:var(--dark)" id="wifi-cached-ssid">—</div>
          <div style="font-size:12px;color:var(--gray);margin-top:2px;display:flex;align-items:center;gap:6px">
            <span id="wifi-cached-pass" style="font-family:monospace;letter-spacing:1px;cursor:pointer" onclick="var s=this;if(s.dataset.show){s.textContent='••••••••';delete s.dataset.show}else{s.textContent=s.dataset.pw;s.dataset.show='1'}">••••••••</span>
            <span style="color:var(--red);font-size:11px;font-weight:600;cursor:pointer" onclick="var pw=document.getElementById('wifi-cached-pass').dataset.pw;navigator.clipboard.writeText(pw);this.textContent='Copied!';var t=this;setTimeout(function(){t.textContent='Copy'},1500)">Copy</span>
          </div>
        </div>
      </div>
      <div style="font-size:10px;color:var(--gray-2);margin-top:8px" id="wifi-cached-when"></div>
    </div>
  </div>

  <!-- Form -->
  <div class="sec-lbl" style="margin-top:20px">Update WiFi</div>
  <div class="list-card" style="padding:18px">

    <!-- v4.12.22: Read-only SSID display (simple mode). Customers who only want
         to change the password should NOT see an editable SSID field — it
         confuses them into thinking they have to retype a network name.
         Input stays in the DOM (hidden) so submitSiteWifi() keeps working. -->
    <div id="site-wifi-name-display" style="background:var(--off-white);border-radius:10px;padding:12px 14px;margin-bottom:16px;display:flex;align-items:center;gap:12px">
      <svg class="ic" style="width:18px;height:18px;color:var(--gray);flex-shrink:0"><use href="#i-wifi"/></svg>
      <div style="flex:1;min-width:0">
        <div style="font-size:11px;color:var(--gray);margin-bottom:2px">Network name</div>
        <div id="site-wifi-name-display-value" style="font-size:14px;font-weight:600;color:var(--dark);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">Loading…</div>
      </div>
    </div>

    <!-- Hidden by default — reveals editable SSID input when "Advanced" is tapped. -->
    <div id="site-wifi-ssid-advanced" style="display:none;margin-bottom:16px">
      <label style="font-size:12px;font-weight:600;color:var(--dark);display:block;margin-bottom:6px">New network name (SSID)</label>
      <input type="text" id="site-wifi-ssid" placeholder="e.g. DishNet-<?= pe(preg_replace('/[^a-zA-Z0-9]/', '', $wsLocation)) ?>"
        style="width:100%;padding:13px 14px;border:1px solid var(--gray-light);border-radius:10px;font-size:14px;font-family:inherit;outline:none;box-sizing:border-box"
        onfocus="this.style.borderColor='var(--red)'" onblur="this.style.borderColor='var(--gray-light)'">
      <div style="font-size:11px;color:var(--warning-text,#b36b00);margin-top:6px">⚠ Changing the name means every device must reconnect.</div>
    </div>

    <div style="margin-bottom:16px">
      <label style="font-size:12px;font-weight:600;color:var(--dark);display:block;margin-bottom:6px">New Password <span style="color:var(--gray-2);font-weight:400">(8-32 characters)</span></label>
      <div style="position:relative">
        <input type="password" id="site-wifi-pass" placeholder="Enter new password" minlength="8" maxlength="32"
          style="width:100%;padding:13px 14px;padding-right:44px;border:1px solid var(--gray-light);border-radius:10px;font-size:14px;font-family:inherit;outline:none;box-sizing:border-box"
          onfocus="this.style.borderColor='var(--red)'" onblur="this.style.borderColor='var(--gray-light)'">
        <button onclick="var p=document.getElementById('site-wifi-pass');p.type=p.type==='password'?'text':'password';this.textContent=p.type==='password'?'Show':'Hide'" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--red);font-size:12px;font-weight:600;cursor:pointer;padding:4px;font-family:inherit">Show</button>
      </div>
    </div>
    <div style="margin-bottom:16px">
      <label style="font-size:12px;font-weight:600;color:var(--dark);display:block;margin-bottom:6px">Confirm Password</label>
      <input type="password" id="site-wifi-pass2" placeholder="Re-enter password"
        style="width:100%;padding:13px 14px;border:1px solid var(--gray-light);border-radius:10px;font-size:14px;font-family:inherit;outline:none;box-sizing:border-box"
        onfocus="this.style.borderColor='var(--red)'" onblur="this.style.borderColor='var(--gray-light)'">
    </div>

    <!-- Advanced toggle -->
    <div id="site-wifi-advanced-toggle" onclick="DishNet.toggleSiteWifiAdvanced()"
      style="display:flex;align-items:center;gap:6px;color:var(--blue,#2563eb);font-size:13px;cursor:pointer;margin-bottom:14px;user-select:none">
      <span id="site-wifi-advanced-arrow">▸</span>
      <span id="site-wifi-advanced-label">Advanced (change network name)</span>
    </div>

    <div id="site-wifi-error" style="display:none;background:var(--danger-light);color:var(--danger-text);padding:12px 14px;border-radius:10px;font-size:12px;margin-bottom:14px"></div>
    <div id="site-wifi-success" style="display:none;background:var(--green-light);color:var(--green-mid);padding:12px 14px;border-radius:10px;font-size:12px;margin-bottom:14px"></div>

    <button class="cta-red" id="site-wifi-submit" onclick="DishNet.submitSiteWifi()" style="margin-top:4px">
      <svg class="ic" style="width:16px;height:16px"><use href="#i-lock"/></svg>
      Change WiFi password
    </button>
  </div>

  <!-- Info note -->
  <div style="margin-top:16px;padding:14px 16px;background:#fff;border-radius:12px;border:1px solid rgba(0,0,0,.04)">
    <div style="font-size:11px;color:var(--gray);line-height:1.6">
      <b style="color:var(--dark)">How it works:</b> <span id="site-wifi-howitworks">The new password is sent to your Starlink router via the cloud. Both 2.4 GHz and 5 GHz bands will be updated. Your devices will need the new password to reconnect.</span>
    </div>
  </div>

  <!-- Support fallback -->
  <button class="cta-alt" style="margin-top:12px" onclick="DishNet.openWhatsApp('+211921443002','I need help changing my WiFi password for <?= pe($wsLocation) ?> (<?= pe($wsKit) ?>)')">
    <svg class="ic" style="width:14px;height:14px"><use href="#i-support"/></svg>
    Need help? Contact support
  </button>

  <!-- Auto-fetch cached WiFi on page load -->
  <script>
  (function(){
    var routerId = '<?= pe($wsRouter) ?>';
    var kit = '<?= pe($wsKit) ?>';
    var baseUrl = location.pathname + '?page=api&action=app_wifi_get&router_id=' + encodeURIComponent(routerId) + '&kit=' + encodeURIComponent(kit);

    var displayEl = document.getElementById('site-wifi-name-display-value');
    var hiddenInp = document.getElementById('site-wifi-ssid');

    // v4.12.28: DEFER the fetch until window.DishNet is defined. DishNet is
    // declared at ~line 3190 of portal.php — this inline <script> runs at
    // ~line 2527, BEFORE the browser parses the DishNet block. The previous
    // `(window.DishNet ? DishNet.apiFetch(baseUrl) : fetch(baseUrl))` fell
    // through to raw fetch() without the Authorization header → 401 Missing
    // Bearer token. v4.12.27's $pdo fix exposed this pre-existing bug because
    // before v4.12.27 the handler 500'd on $pdo before reaching the auth check.
    function runWhenDishNetReady(cb) {
      if (window.DishNet && typeof window.DishNet.apiFetch === 'function') { cb(); return; }
      var waited = 0;
      var iv = setInterval(function(){
        waited += 50;
        if (window.DishNet && typeof window.DishNet.apiFetch === 'function') {
          clearInterval(iv); cb();
        } else if (waited > 3000) {
          clearInterval(iv); cb();
        }
      }, 50);
    }

    runWhenDishNetReady(function(){
      DishNet.apiFetch(baseUrl)
      .then(function(r){ return r.json(); })
      .then(function(d){
        var ssid = (d && d.status === 'success' && d.data && d.data.ssid) ? d.data.ssid : '';
        if (ssid) {
          // v4.12.22: Populate BOTH hidden input AND read-only display card.
          // Hidden input keeps submitSiteWifi() working unchanged — it reads
          // this value even when the input is hidden from the user.
          if (hiddenInp) hiddenInp.value = ssid;
          if (displayEl) displayEl.textContent = ssid;

          // Show current card (kept from v4.12.20 for backwards visibility)
          var card = document.getElementById('wifi-current-card');
          var ssidEl = document.getElementById('wifi-cached-ssid');
          var passEl = document.getElementById('wifi-cached-pass');
          var whenEl = document.getElementById('wifi-cached-when');
          if (card && ssidEl) {
            card.style.display = 'block';
            ssidEl.textContent = ssid;
            if (passEl && d.data.password) {
              passEl.dataset.pw = d.data.password;
            }
            if (whenEl && d.data.updated_at) {
              var ago = Math.round((Date.now() - new Date(d.data.updated_at).getTime()) / 3600000);
              var srcLabel = '';
              if (d.data.source === 'live_config') srcLabel = ' · from live config';
              else if (d.data.source === 'data-report-cron') srcLabel = ' · from router cache';
              whenEl.textContent = 'Last changed ' + (ago < 1 ? 'just now' : ago + 'h ago') + srcLabel;
            }
          }
        } else {
          // v4.12.22: No cached SSID anywhere — show a friendly message in the
          // display card and auto-expand Advanced so the editable SSID field
          // is visible (user MUST type something in this edge case).
          if (displayEl) displayEl.textContent = 'Not set — tap Advanced below';
          if (window.DishNet && DishNet.toggleSiteWifiAdvanced) {
            DishNet.toggleSiteWifiAdvanced(true);
          }
        }
      })
      .catch(function(){
        if (displayEl) displayEl.textContent = 'Could not load — tap Advanced below';
        if (window.DishNet && DishNet.toggleSiteWifiAdvanced) {
          DishNet.toggleSiteWifiAdvanced(true);
        }
      });
    });
  })();
  </script>
</div>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: SPEED TEST (full page)
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'speed_test'):
    $stKit = trim($_GET['kit'] ?? '');
    $stLocation = '';
    foreach ($portalSites as $ss) {
        if ($ss['kit_number'] === $stKit) { $stLocation = $ss['location']; break; }
    }
    if (!$stLocation) $stLocation = $portalCustomerName;
?>
<div class="scr-head">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="DishNet.goInternal('site_detail',{kit:'<?= pe($stKit) ?>'})"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Speed test</div>
    <div style="width:32px"></div>
  </div>
</div>
<div class="scr-body" style="padding-top:0">
  <script>window._siteLocation = '<?= pe($stLocation) ?>';</script>

  <!-- Idle state -->
  <div id="speed-idle">
    <div style="text-align:center;padding:28px;background:#fff;border-radius:16px;margin-top:-24px;box-shadow:0 2px 8px rgba(0,0,0,.06);position:relative;z-index:3">
      <div style="width:60px;height:60px;border-radius:50%;background:var(--off-white);display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
        <svg class="ic" style="width:28px;height:28px;color:var(--dark)"><use href="#i-speed"/></svg>
      </div>
      <div style="font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:20px;color:var(--dark);margin-bottom:6px">Test your connection</div>
      <div style="font-size:12px;color:var(--gray);margin-bottom:6px"><?= pe($stLocation) ?></div>
      <div style="font-size:11px;color:var(--gray-2);margin-bottom:18px">Measures download, upload, and latency</div>
      <button class="cta-red" onclick="DishNet.runSpeedTest()" style="margin-top:0;max-width:280px;margin-left:auto;margin-right:auto">
        <svg class="ic" style="width:16px;height:16px"><use href="#i-speed"/></svg>
        Start speed test
      </button>
    </div>
  </div>

  <!-- Running state -->
  <div id="speed-running" style="display:none">
    <div style="text-align:center;padding:28px;background:#fff;border-radius:16px;margin-top:-24px;box-shadow:0 2px 8px rgba(0,0,0,.06);position:relative;z-index:3">
      <div style="font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--red);margin-bottom:16px;display:inline-flex;align-items:center;gap:6px">
        <span style="width:6px;height:6px;border-radius:50%;background:var(--red);animation:st-pulse 1s infinite"></span>
        <span id="speed-phase">Testing latency...</span>
      </div>
      <div style="position:relative;width:220px;height:132px;margin:0 auto 8px">
        <svg viewBox="0 0 200 120" style="width:100%;height:100%">
          <path d="M20 100 A80 80 0 0 1 180 100" fill="none" stroke="var(--gray-light)" stroke-width="10" stroke-linecap="round"/>
          <path id="speed-arc-fill" d="M20 100 A80 80 0 0 1 20 100" fill="none" stroke="var(--dark)" stroke-width="10" stroke-linecap="round"/>
        </svg>
        <div style="position:absolute;top:48px;left:0;right:0;text-align:center;font-size:10px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:var(--gray-2)" id="speed-arc-label">Download</div>
        <div style="position:absolute;top:62px;left:0;right:0;text-align:center;font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:64px;color:var(--dark);letter-spacing:-2.5px;line-height:.95" id="speed-arc-num">—</div>
        <div style="position:absolute;top:122px;left:0;right:0;text-align:center;font-size:11px;font-weight:600;color:var(--gray)">Mbps</div>
      </div>
    </div>
  </div>

  <!-- Result state -->
  <div id="speed-result" style="display:none">
    <div style="padding:0;overflow:hidden;background:#fff;border-radius:16px;margin-top:-24px;box-shadow:0 2px 8px rgba(0,0,0,.06);position:relative;z-index:3">
      <div style="text-align:center;padding:24px 22px 20px">
        <div style="font-size:10px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:var(--green-mid);margin-bottom:10px;display:inline-flex;align-items:center;gap:6px" id="speed-verdict-badge">
          <span style="width:6px;height:6px;border-radius:50%;background:var(--green)"></span>
          <span id="speed-verdict">Test complete</span>
        </div>
        <div style="font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:24px;color:var(--dark);letter-spacing:-.3px;line-height:1.1;margin-bottom:4px" id="speed-headline">Your speed is good</div>
        <div style="font-size:12px;color:var(--gray);margin-bottom:18px"><?= pe($stLocation) ?> · Starlink</div>
        <div style="position:relative;width:220px;height:132px;margin:0 auto 8px">
          <svg viewBox="0 0 200 120" style="width:100%;height:100%">
            <path d="M20 100 A80 80 0 0 1 180 100" fill="none" stroke="var(--gray-light)" stroke-width="10" stroke-linecap="round"/>
            <path id="speed-result-arc" d="M20 100 A80 80 0 0 1 180 100" fill="none" stroke="var(--dark)" stroke-width="10" stroke-linecap="round"/>
          </svg>
          <div style="position:absolute;top:48px;left:0;right:0;text-align:center;font-size:10px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:var(--gray-2)">Download</div>
          <div style="position:absolute;top:62px;left:0;right:0;text-align:center;font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:64px;color:var(--dark);letter-spacing:-2.5px;line-height:.95" id="speed-result-num">—</div>
          <div style="position:absolute;top:122px;left:0;right:0;text-align:center;font-size:11px;font-weight:600;color:var(--gray)">Mbps</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px">
          <div style="background:var(--off-white);border-radius:12px;padding:14px 16px">
            <div style="display:flex;align-items:center;gap:6px;font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--gray-2);margin-bottom:4px">
              <svg class="ic" style="width:12px;height:12px"><use href="#i-arrow"/></svg>Upload
            </div>
            <div style="display:flex;align-items:baseline;gap:4px">
              <span style="font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:26px;color:var(--dark);letter-spacing:-.4px;line-height:1" id="speed-r-up">—</span>
              <span style="font-size:10px;font-weight:600;color:var(--gray)">Mbps</span>
            </div>
          </div>
          <div style="background:var(--off-white);border-radius:12px;padding:14px 16px">
            <div style="display:flex;align-items:center;gap:6px;font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--gray-2);margin-bottom:4px">
              <svg class="ic" style="width:12px;height:12px"><use href="#i-clock"/></svg>Ping
            </div>
            <div style="display:flex;align-items:baseline;gap:4px">
              <span style="font-family:'Barlow Condensed',sans-serif;font-weight:900;font-size:26px;color:var(--dark);letter-spacing:-.4px;line-height:1" id="speed-r-ping">—</span>
              <span style="font-size:10px;font-weight:600;color:var(--gray)">ms</span>
            </div>
          </div>
        </div>
      </div>
      <!-- Diagnosis -->
      <div style="border-top:1px solid var(--off-white)" id="speed-diagnosis"></div>
    </div>

    <!-- Actions -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px">
      <button class="cta-red" onclick="DishNet.runSpeedTest()" style="margin-top:0">
        <svg class="ic" style="width:14px;height:14px"><use href="#i-speed"/></svg> Run again
      </button>
      <button class="cta-alt" onclick="DishNet.shareSpeedResult()" style="margin-top:0">
        <svg class="ic" style="width:14px;height:14px"><use href="#i-support"/></svg> Share result
      </button>
    </div>

    <!-- Disclaimer -->
    <div style="margin-top:14px;padding:12px 14px;background:#fff;border-radius:10px;border:1px solid rgba(0,0,0,.04)">
      <div style="font-size:10px;color:var(--gray);line-height:1.6">
        <b style="color:var(--dark)">About this test:</b> Speed is measured by downloading a 5 MB file from Cloudflare's nearest server. Results may vary depending on the time of day, number of connected devices, and distance from the Starlink ground station. For the most accurate result, close other apps and test when fewer devices are connected.
      </div>
    </div>
  </div>
  <style>@keyframes st-pulse{0%,100%{opacity:1}50%{opacity:.3}}</style>
</div>

<?php
// ══════════════════════════════════════════════════════════════════
// VIEW: CONNECTED DEVICES (v4.12.20 — matches v3 prototype)
// ══════════════════════════════════════════════════════════════════
elseif ($view === 'devices'):
    $devKit    = trim($_GET['kit'] ?? '');
    $devRouter = trim($_GET['router'] ?? ($portalRouter['router_id_full'] ?? ''));
    $devLocation = '';
    foreach ($portalSites as $ss) {
        if ($ss['kit_number'] === $devKit) { $devLocation = $ss['location']; break; }
    }
?>

<div class="scr-head" style="padding-bottom:42px">
  <div class="scr-head-row">
    <button class="scr-btn" onclick="history.back()"><svg class="ic" style="width:14px;height:14px"><use href="#i-back"/></svg></button>
    <div class="scr-title">Connected devices</div>
    <button class="scr-btn" id="dev-refresh-btn" onclick="_fetchDevices(true)"><svg class="ic" style="width:14px;height:14px"><use href="#i-speed"/></svg></button>
  </div>
  <div class="scr-eyebrow" id="dev-eyebrow"><?= pe($devLocation ?: $devKit) ?> · Loading…</div>
</div>

<div class="scr-body" style="padding-top:0">
  <!-- v4.20.0 — Time-based access banner. Shows total active timers +
       today's session/revenue summary. Hidden when there are no active
       timers AND no sessions today. -->
  <div id="dev-timed-banner" style="display:none;margin-top:12px;position:relative;z-index:3;background:#fff;border:1px solid rgba(34,197,94,.25);border-radius:14px;padding:14px;box-shadow:0 1px 3px rgba(34,197,94,.08)">
    <div style="display:flex;align-items:center;gap:11px">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--green-light);color:var(--green-mid);display:flex;align-items:center;justify-content:center;flex-shrink:0"><svg class="ic" style="width:16px;height:16px"><use href="#i-clock"/></svg></div>
      <div style="flex:1;min-width:0">
        <div id="dev-timed-line1" style="font-size:13px;font-weight:700;color:var(--dark);line-height:1.3">No active timers</div>
        <div id="dev-timed-line2" style="font-size:11px;color:var(--gray-2);line-height:1.45;margin-top:1px">Today: 0 sessions</div>
      </div>
    </div>
  </div>

  <!-- v4.19.0 — NEW devices banner. Hidden until we know there are new
       (unacknowledged) devices currently connected. Customer can tap
       "Mark all known" to acknowledge them in bulk, or "Rotate password"
       to kick everyone off and start fresh. -->
  <div id="dev-new-banner" style="display:none;margin-top:12px;position:relative;z-index:3;background:#fff;border:1px solid rgba(212,28,28,.25);border-radius:14px;padding:14px;box-shadow:0 1px 3px rgba(212,28,28,.08)">
    <div style="display:flex;align-items:flex-start;gap:11px">
      <div style="width:34px;height:34px;border-radius:10px;background:rgba(212,28,28,.08);color:var(--brand-red);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px">🆕</div>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:700;color:var(--dark);line-height:1.3"><span id="dev-new-count">0</span> new device<span id="dev-new-plural">s</span> connected</div>
        <div style="font-size:11px;color:var(--gray-2);line-height:1.45;margin-top:2px">If you don't recognize a device, rotate your Wi-Fi password to kick everyone off.</div>
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:11px;padding-left:45px">
      <button onclick="_ackAllNew()" style="background:var(--off-white);color:var(--dark);border:none;border-radius:8px;padding:7px 12px;font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:10px;letter-spacing:.6px;text-transform:uppercase;cursor:pointer">Mark all known</button>
      <button onclick="_showRotateConfirm()" style="background:var(--brand-red);color:#fff;border:none;border-radius:8px;padding:7px 12px;font-family:'Barlow Condensed',sans-serif;font-weight:800;font-size:10px;letter-spacing:.6px;text-transform:uppercase;cursor:pointer">Rotate password</button>
    </div>
  </div>

  <!-- Filter chips (rendered by JS once we have data) -->
  <div class="chip-row" id="dev-chips"></div>

  <!-- Loading state -->
  <div id="dev-loading" style="margin-top:12px;position:relative;z-index:3">
    <div class="list-card" style="padding:30px;text-align:center">
      <div style="font-size:28px;margin-bottom:8px">📡</div>
      <div style="font-size:13px;font-weight:600;color:var(--dark)">Scanning network…</div>
      <div style="font-size:11px;color:var(--gray);margin-top:4px">Fetching connected devices from Starlink cloud</div>
    </div>
  </div>

  <!-- Error state -->
  <div id="dev-error" style="display:none;margin-top:12px;position:relative;z-index:3">
    <div class="list-card" style="padding:24px;text-align:center">
      <div style="font-size:28px;margin-bottom:8px">📡</div>
      <div id="dev-error-msg" style="font-size:13px;font-weight:600;color:var(--danger-text)">Router offline</div>
      <div style="font-size:11px;color:var(--gray);margin-top:6px">The router must be online and connected to Starlink to see devices.</div>
      <button class="cta-red" style="margin-top:14px;max-width:200px;margin-left:auto;margin-right:auto" onclick="_fetchDevices(true)">Try again</button>
    </div>
  </div>

  <!-- Content -->
  <div id="dev-content" style="display:none;position:relative;z-index:3">
    <div id="dev-sec-wifi-head" class="sec-lbl" style="display:none">WiFi devices</div>
    <div class="list-card" id="dev-sec-wifi" style="display:none"></div>

    <div id="dev-sec-wired-head" class="sec-lbl" style="display:none;margin-top:16px">Wired devices</div>
    <div class="list-card" id="dev-sec-wired" style="display:none"></div>

    <div id="dev-sec-blocked-head" class="sec-lbl" style="display:none;margin-top:16px">Hidden</div>
    <div class="list-card" id="dev-sec-blocked" style="display:none"></div>

    <div id="dev-sys-note" class="cd-sys-note" style="display:none"></div>

    <!-- WiFi networks (unchanged from v4.12.20) -->
    <div class="sec-lbl" style="margin-top:16px">Wi-Fi networks</div>
    <div class="list-card" id="dev-networks"></div>
  </div>
</div>

<script>
(function(){
window._devRouter   = '<?= pe($devRouter) ?>';
window._devKit      = '<?= pe($devKit) ?>';
window._devLocation = '<?= pe($devLocation) ?>';
window._devFilter   = 'all';
window._devPayload  = null;
window._devPollId   = null;
window._devLastFetch = 0;

// ──── Blocklist — v4.12.20: server-backed with localStorage fallback ────
// Previously: localStorage-only, per-device. Now: stored on server per-customer,
// synced to localStorage as a write-through cache so the UI renders instantly
// on subsequent loads without waiting for the server round-trip.
//
// Flow:
//   1. Page load → instant render using localStorage snapshot
//   2. Async fetch /app_device_blocklist_get → overwrites cache with server truth
//   3. On first migration: if server is empty but localStorage has entries, push them up
//   4. On hide/unhide: optimistic localStorage update + async POST to server
//   5. If server POST fails: revert local state and show a subtle warning (TODO)
var BLOCK_KEY = 'dn_blocked_macs';
var BLOCK_MIGRATED_KEY = 'dn_blocklist_migrated_v1';
function _blockList() {
  try {
    var raw = (typeof localStorage !== 'undefined') ? localStorage.getItem(BLOCK_KEY) : null;
    return raw ? (JSON.parse(raw) || []) : [];
  } catch (e) { return []; }
}
function _blockSave(list) {
  try {
    if (typeof localStorage !== 'undefined') localStorage.setItem(BLOCK_KEY, JSON.stringify(list));
  } catch (e) { /* quota / privacy mode — silently ignore */ }
}

// Build full API URL from portal URL by replacing the `page` query parameter
function _apiUrl(action) {
  var base = location.href.split('?')[0]; // strips query
  return base + '?page=api&action=' + encodeURIComponent(action);
}

// Read auth token — uses existing DishNet._token pattern (set by native WebView),
// with PHP-embedded fallback for web PWA.
function _authHeader() {
  var tok = (window.DishNet && window.DishNet._token) ? window.DishNet._token : '<?= pe($token) ?>';
  return tok ? { 'Authorization': 'Bearer ' + tok } : {};
}

// Fetch server blocklist and merge into local cache. Called once on page load.
function _blockSyncFromServer() {
  try {
    fetch(_apiUrl('app_device_blocklist_get'), { headers: _authHeader() })
      .then(function(r) { return r.ok ? r.json() : null; })
      .then(function(resp) {
        if (!resp || resp.status !== 'success') return;
        var serverMacs = (resp.data && resp.data.macs) || [];
        var localMacs  = _blockList();

        // One-time migration: if server is empty but local has entries, push up
        var migrated = false;
        try { migrated = localStorage.getItem(BLOCK_MIGRATED_KEY) === '1'; } catch (e) {}
        if (!migrated && serverMacs.length === 0 && localMacs.length > 0) {
          fetch(_apiUrl('app_device_blocklist_toggle'), {
            method: 'POST',
            headers: Object.assign({'Content-Type':'application/json'}, _authHeader()),
            body: JSON.stringify({ action: 'bulk_set', macs: localMacs })
          }).then(function() {
            try { localStorage.setItem(BLOCK_MIGRATED_KEY, '1'); } catch (e) {}
          }).catch(function() {});
          // Keep local list as-is; migration runs async in background
          return;
        }

        // Normal case: overwrite local with server truth
        _blockSave(serverMacs);
        try { localStorage.setItem(BLOCK_MIGRATED_KEY, '1'); } catch (e) {}
        // Re-render if the list changed
        if (JSON.stringify(serverMacs) !== JSON.stringify(localMacs)) {
          if (typeof _renderAll === 'function') _renderAll();
        }
      })
      .catch(function() { /* offline or 401 — keep using localStorage */ });
  } catch (e) { /* URL building failed — use localStorage only */ }
}

// Optimistically update local, then POST to server. If server rejects, no revert
// for now (user's change stays local until next sync). This is fine because the
// hide action is visual-only anyway.
function _blockToggle(mac, shouldBlock) {
  var list = _blockList();
  var idx = list.indexOf(mac);
  if (shouldBlock && idx === -1) list.push(mac);
  if (!shouldBlock && idx !== -1) list.splice(idx, 1);
  _blockSave(list);

  // Push to server (fire-and-forget)
  try {
    fetch(_apiUrl('app_device_blocklist_toggle'), {
      method: 'POST',
      headers: Object.assign({'Content-Type':'application/json'}, _authHeader()),
      body: JSON.stringify({ action: shouldBlock ? 'block' : 'unblock', mac: mac })
    }).catch(function() {});
  } catch (e) {}
}

// ──── Icon detection by device name ────
function _iconFor(name) {
  var n = (name || '').toLowerCase();
  if (n.indexOf('iphone') !== -1 || n.indexOf('ipad') !== -1 || n.indexOf('apple') !== -1)
    return { icon:'#i-phone', bg:'rgba(212,28,28,.08)', color:'var(--red)' };
  if (n.indexOf('samsung') !== -1 || n.indexOf('galaxy') !== -1 || n.indexOf('-s23') !== -1 || n.indexOf('-s24') !== -1 || n.indexOf('oneplus') !== -1 || n.indexOf('oppo') !== -1 || n.indexOf('xiaomi') !== -1 || n.indexOf('infinix') !== -1 || n.indexOf('tecno') !== -1 || n.indexOf('redmi') !== -1 || n.indexOf('pixel') !== -1)
    return { icon:'#i-phone', bg:'var(--green-light)', color:'var(--green-mid)' };
  if (n.indexOf('macbook') !== -1 || n.indexOf('laptop') !== -1 || n.indexOf('desktop') !== -1 || n.indexOf('windows') !== -1 || n.indexOf('lenovo') !== -1 || n.indexOf('hp-') !== -1 || n.indexOf('dell') !== -1 || n.indexOf('thinkpad') !== -1)
    return { icon:'#i-laptop', bg:'rgba(57,124,215,.12)', color:'#2b6fc4' };
  if (n.indexOf('tv') !== -1 || n.indexOf('webos') !== -1 || n.indexOf('roku') !== -1 || n.indexOf('chromecast') !== -1 || n.indexOf('firetv') !== -1)
    return { icon:'#i-tv', bg:'rgba(139,92,246,.12)', color:'#7c3aed' };
  if (n.indexOf('controller') !== -1 || n.indexOf('uap') !== -1 || n.indexOf('mikrotik') !== -1 || n.indexOf('routerboard') !== -1 || n.indexOf('ap-') !== -1)
    return { icon:'#i-router', bg:'var(--off-white)', color:'var(--gray)' };
  return { icon:'#i-wifi', bg:'var(--off-white)', color:'var(--dark)' };
}

// ──── Subtitle builder ────
function _uptimeHuman(s) {
  if (!s || s < 60) return (s || 0) + 's';
  if (s < 3600)    return Math.floor(s/60) + 'm';
  if (s < 86400)   return Math.floor(s/3600) + 'h ' + Math.floor((s%3600)/60) + 'm';
  return Math.floor(s/86400) + 'd ' + Math.floor((s%86400)/3600) + 'h';
}
function _activityLabel(act) {
  if (act === 'heavy')   return 'Heavy';
  if (act === 'active')  return 'Active';
  if (act === 'light')   return 'Light';
  if (act === 'idle')    return 'Idle';
  if (act === 'sleeping')return '';
  return '';
}
function _subtitleFor(c) {
  // Wired: show IP + uptime
  if (c.band === 'wired') {
    var parts = [];
    if (c.ip) parts.push(c.ip);
    if (c.uptime_s) parts.push('Connected ' + _uptimeHuman(c.uptime_s));
    return parts.join(' · ') || 'Wired';
  }
  // WiFi: signal description + connected time
  var sigLabel = 'Signal ok';
  if (c.signal_bucket === 'good') sigLabel = 'Strong signal';
  if (c.signal_bucket === 'weak') sigLabel = 'Weak signal';
  if (c.signal_bucket === 'poor') sigLabel = 'Poor signal';
  var p2 = [sigLabel];
  if (c.uptime_s) p2.push(_uptimeHuman(c.uptime_s));
  return p2.join(' · ');
}

// ──── Row renderer ────
function _esc(s) { return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/'/g,'&#39;').replace(/"/g,'&quot;'); }

function _renderRow(c, opts) {
  opts = opts || {};
  var ico = _iconFor(c.name);
  var isPaused = !!c.paused;  // v4.15.0: real Starlink-side pause state from dr_wifi_get_status

  // v4.19.0: NEW badge + per-device acknowledge action.
  // Cross-reference with window._devSeenMap (populated by _fetchSeenDevices)
  // which is keyed by fingerprint and tells us whether we've seen this
  // device before and whether the customer has acknowledged it.
  var fp = c.fingerprint || c.mac || '';
  var seenInfo = (window._devSeenMap && fp) ? window._devSeenMap[fp] : null;
  // is_new = we know about it AND it's not acknowledged. If we have no
  // record at all we don't show NEW until record_seen runs (avoids flashing
  // NEW on every device on first dashboard load before history seeds).
  var isNew = seenInfo && seenInfo.is_new === true;

  var dotClass = 'good';
  if (c.band !== 'wired') {
    if (c.signal_bucket === 'weak') dotClass = 'weak';
    else if (c.signal_bucket === 'poor') dotClass = 'poor';
  }
  var bandTag = '';
  // v4.19.0: NEW badge takes precedence over band tag — important new info
  if (isNew && !opts.blocked) {
    bandTag = '<span class="cd-tag new-tag">NEW</span>';
  } else if (isPaused && !opts.blocked) {
    bandTag = '<span class="cd-tag paused-tag">Paused</span>';
  } else if (c.band === '5')        bandTag = '<span class="cd-tag band5">5 GHz</span>';
  else if (c.band === '2.4') bandTag = '<span class="cd-tag band24">2.4 GHz</span>';
  else if (c.band === 'wired') bandTag = '<span class="cd-tag wired"><svg class="ic" style="width:8px;height:8px;vertical-align:-1px;margin-right:2px"><use href="#i-ether"/></svg>Wired</span>';

  var dotHtml = '';
  if (!opts.blocked) dotHtml = '<span class="cd-dot ' + (isPaused ? 'paused' : dotClass) + '"></span>';

  var speedHtml = '';
  var displaySpeed = c.link_speed_mbps;
  if (!displaySpeed && c.band !== 'wired' && c.link_cap_mbps) displaySpeed = c.link_cap_mbps;
  if (!opts.blocked && !isPaused && displaySpeed) {
    var label = _activityLabel(c.activity);
    var subCls = '';
    if (c.activity === 'heavy')  subCls = ' heavy';
    if (c.activity === 'active') subCls = ' active';
    speedHtml = '<div style="text-align:right">'
      + '<div class="cd-bw">' + Math.round(displaySpeed) + '<span class="u">Mbps link</span></div>'
      + (label ? ('<div class="cd-bw-sub' + subCls + '">' + label + '</div>') : '')
      + '</div>';
  }

  var pauseBtn = '';
  var hideBtn = '';
  var ackBtn = '';
  var grantBtn = '';
  var pauseKey = c.fingerprint || c.mac || '';

  // v4.20.0: paid grant state for this device
  var grant = (pauseKey && window._devPaidMap) ? window._devPaidMap[pauseKey] : null;
  var hasActiveGrant = !!grant;
  var timerBadge = '';
  if (hasActiveGrant) {
    var sec = Math.max(0, grant.expires_at - Math.floor(Date.now()/1000));
    var crit = sec < 60 ? ' crit' : (sec < 600 ? ' warn' : '');
    timerBadge = '<span class="cd-timer' + crit + '"><svg class="ic" style="width:9px;height:9px;vertical-align:-1px;margin-right:3px"><use href="#i-clock"/></svg>' + _formatCountdown(sec) + '</span>';
  }

  if (!opts.blocked && pauseKey) {
    if (isPaused) {
      pauseBtn = '<button class="cd-pause is-paused" title="Unpause this device" onclick="event.stopPropagation(); _unpauseDevice(\'' + _esc(pauseKey) + '\')"><svg class="ic"><use href="#i-play"/></svg></button>';
    } else {
      pauseBtn = '<button class="cd-pause" title="Pause this device on the network" onclick="event.stopPropagation(); _confirmPauseDevice(\'' + _esc(pauseKey) + '\', \'' + _esc(c.name || 'this device') + '\')"><svg class="ic"><use href="#i-pause"/></svg></button>';
    }
  }

  // v4.20.0: Grant time button. If no active grant → "Grant" (clock icon).
  // If active grant → row already shows countdown; tapping +1h extends.
  // Long-press / separate revoke is via the dropdown when grant is active.
  if (!opts.blocked && pauseKey) {
    if (hasActiveGrant) {
      grantBtn = '<button class="cd-grant active" title="Extend +1 hour (long-press to revoke)" onclick="event.stopPropagation(); _extendGrant(\'' + _esc(pauseKey) + '\', 60)" oncontextmenu="event.preventDefault(); event.stopPropagation(); _revokeGrant(\'' + _esc(pauseKey) + '\', \'' + _esc(c.name || 'this device') + '\'); return false"><svg class="ic"><use href="#i-clock"/></svg></button>';
    } else {
      grantBtn = '<button class="cd-grant" title="Grant timed access" onclick="event.stopPropagation(); _showGrantSheet(\'' + _esc(pauseKey) + '\', \'' + _esc(c.name || 'this device') + '\')"><svg class="ic"><use href="#i-clock"/></svg></button>';
    }
  }

  // v4.19.0: ack button (checkmark) shown when the device is flagged NEW.
  if (isNew && !opts.blocked && fp) {
    ackBtn = '<button class="cd-ack" title="Mark as known" onclick="event.stopPropagation(); _ackDevice(\'' + _esc(fp) + '\', \'' + _esc(c.name || 'this device') + '\')"><svg class="ic"><use href="#i-check"/></svg></button>';
  }
  if (!opts.blocked && c.mac) {
    hideBtn = '<button class="cd-hide" title="Hide this device from your list (visual only)" onclick="event.stopPropagation(); _blockDevice(\'' + _esc(c.mac) + '\')">×</button>';
  } else if (opts.blocked && c.mac) {
    hideBtn = '<button class="cd-hide" title="Unhide this device" onclick="event.stopPropagation(); _unblockDevice(\'' + _esc(c.mac) + '\')" style="opacity:1">↺</button>';
  }

  var rowCls = 'cd-row' + (opts.blocked ? ' blocked' : (isPaused ? ' is-paused' : (isNew ? ' is-new' : (hasActiveGrant ? ' has-grant' : ''))));
  var icCls  = 'cd-row-ic' + (opts.blocked ? ' blocked' : (isPaused ? ' paused' : ''));

  return '<div class="' + rowCls + '" data-fp="' + _esc(pauseKey) + '">'
    +   '<div class="' + icCls + '" style="' + (opts.blocked || isPaused ? '' : 'background:' + ico.bg + ';color:' + ico.color) + '">'
    +     '<svg class="ic"><use href="' + ico.icon + '"/></svg>'
    +     dotHtml
    +   '</div>'
    +   '<div class="cd-t">'
    +     '<div class="cd-top"><span class="cd-name">' + _esc(c.name) + '</span>' + bandTag + timerBadge + '</div>'
    +     '<div class="cd-sub">' + _esc(_subtitleFor(c)) + '</div>'
    +   '</div>'
    +   '<div class="cd-right">'
    +     speedHtml
    +     ackBtn
    +     grantBtn
    +     pauseBtn
    +     hideBtn
    +   '</div>'
    + '</div>';
}

// ──── Render the whole view from current _devPayload + filter ────
function _renderAll() {
  var data = window._devPayload;
  if (!data) return;

  var clients  = data.clients || [];
  var summary  = data.summary || {};
  var networks = data.networks || [];
  var blocked  = _blockList();

  // Partition
  var wifi = [], wired = [], controllers = [], hidden = [];
  for (var i = 0; i < clients.length; i++) {
    var c = clients[i];
    if (blocked.indexOf(c.mac) !== -1) { hidden.push(c); continue; }
    if (c.is_controller)    controllers.push(c);
    else if (c.band === 'wired') wired.push(c);
    else wifi.push(c);
  }

  var wifiCount  = wifi.length;
  var wiredCount = wired.length;
  var hiddenCount = hidden.length;
  var totalShown = wifiCount + wiredCount;

  // Eyebrow — total visible devices + aggregate WiFi link speed
  var eyebrow = (window._devLocation || window._devKit || '') + ' · ';
  if (totalShown === 0 && hiddenCount === 0) {
    eyebrow += 'No devices online';
  } else {
    eyebrow += totalShown + ' device' + (totalShown !== 1 ? 's' : '');
    // v4.12.20: prefer total_speed_mbps (real PHY peaks); fall back to total_cap_mbps.
    var totMbps = summary.total_speed_mbps || summary.total_cap_mbps;
    if (totMbps) eyebrow += ' · ' + Math.round(totMbps) + ' Mbps WiFi link';
  }
  document.getElementById('dev-eyebrow').textContent = eyebrow;

  // Chips — All / WiFi / Wired / Blocked (if any)
  var chips = [
    { id:'all',     label:'All',     count: totalShown },
    { id:'wifi',    label:'WiFi',    count: wifiCount  },
    { id:'wired',   label:'Wired',   count: wiredCount }
  ];
  if (hiddenCount > 0) chips.push({ id:'blocked', label:'Hidden', count: hiddenCount });
  var chipHtml = '';
  for (var j = 0; j < chips.length; j++) {
    var ch = chips[j];
    chipHtml += '<div class="chip' + (window._devFilter === ch.id ? ' on' : '') + '" onclick="_setFilter(\'' + ch.id + '\')">'
              + ch.label + ' <span class="cnt">' + ch.count + '</span></div>';
  }
  document.getElementById('dev-chips').innerHTML = chipHtml;

  // Render visible sections based on filter
  var f = window._devFilter;
  function _setSection(id, head, show, rows) {
    var elH = document.getElementById(head);
    var elC = document.getElementById(id);
    if (show && rows.length > 0) {
      elH.style.display = '';
      elC.style.display = '';
      var html = '';
      for (var k = 0; k < rows.length; k++) html += _renderRow(rows[k], { blocked: id === 'dev-sec-blocked' });
      elC.innerHTML = html;
    } else {
      elH.style.display = 'none';
      elC.style.display = 'none';
    }
  }
  _setSection('dev-sec-wifi',    'dev-sec-wifi-head',    (f === 'all' || f === 'wifi'),    wifi);
  _setSection('dev-sec-wired',   'dev-sec-wired-head',   (f === 'all' || f === 'wired'),   wired);
  _setSection('dev-sec-blocked', 'dev-sec-blocked-head', (f === 'blocked'),                hidden);

  // Controller footer (hidden by default)
  var sysNote = document.getElementById('dev-sys-note');
  if (controllers.length > 0 && f === 'all') {
    sysNote.style.display = 'block';
    sysNote.textContent = '+ ' + controllers.length + ' system device' + (controllers.length !== 1 ? 's' : '') + ' (router management)';
  } else {
    sysNote.style.display = 'none';
  }

  // Empty state for current filter
  if (f === 'all' && totalShown === 0 && hiddenCount === 0) {
    document.getElementById('dev-sec-wifi-head').style.display = '';
    document.getElementById('dev-sec-wifi').style.display = '';
    document.getElementById('dev-sec-wifi-head').textContent = 'Devices';
    document.getElementById('dev-sec-wifi').innerHTML = '<div style="padding:24px;text-align:center;color:var(--gray);font-size:11px">No devices connected right now</div>';
  } else {
    document.getElementById('dev-sec-wifi-head').textContent = 'WiFi devices';
  }

  // Networks
  var netHtml = '';
  for (var m = 0; m < networks.length; m++) {
    var net = networks[m];
    if (net.disabled) continue;
    var bandLabel = net.band || 'WiFi';
    netHtml += '<div class="list-row">'
      + '<div class="list-ic" style="background:var(--green-light);color:var(--green-mid)"><svg class="ic"><use href="#i-wifi"/></svg></div>'
      + '<div class="list-t">'
      +   '<div class="list-tt">' + _esc(net.ssid) + '</div>'
      +   '<div class="list-ts">' + bandLabel + ' · ' + _esc(net.auth_type || 'open') + '</div>'
      + '</div></div>';
  }
  document.getElementById('dev-networks').innerHTML = netHtml || '<div style="padding:16px;text-align:center;color:var(--gray);font-size:11px">No networks found</div>';
}

window._setFilter = function(f) {
  window._devFilter = f;
  _renderAll();
};
window._blockDevice = function(mac) {
  if (!mac) return;
  _blockToggle(mac, true);
  _renderAll();
};
window._unblockDevice = function(mac) {
  if (!mac) return;
  _blockToggle(mac, false);
  _renderAll();
};

// ──── v4.15.0: REAL pause/unpause via dr_wifi_pause_client (data-report plugin) ────
// Keyed by fingerprint (Starlink redacts MAC with XX). The brief locks: pause
// gets a light confirm sheet to prevent mis-clicks on small phones; unpause is
// direct (reversible action, matches Starlink app pattern).
//
// On success, we optimistically mark the local _devPayload entry as paused and
// re-render so the row updates instantly without waiting for the next 30s poll.
// The server will confirm on the next dr_wifi_get_status fetch.
function _drUrlForDevices(action) {
  return location.href.split('/_plugins/')[0] + '/_plugins/dishnet-data-report/public.php?action=' + encodeURIComponent(action);
}
function _markPausedInPayload(fingerprint, paused) {
  if (!window._devPayload || !window._devPayload.clients) return;
  var cs = window._devPayload.clients;
  for (var i = 0; i < cs.length; i++) {
    if (cs[i].fingerprint === fingerprint || cs[i].mac === fingerprint) {
      cs[i].paused = paused;
      break;
    }
  }
}
window._confirmPauseDevice = function(fingerprint, name) {
  if (!fingerprint) return;
  // Build/show the confirm sheet (singleton — reused across taps)
  var bg = document.getElementById('dev-pause-sheet');
  if (!bg) {
    bg = document.createElement('div');
    bg.id = 'dev-pause-sheet';
    bg.className = 'hs-sheet-bg';
    bg.innerHTML =
      '<div class="hs-sheet">' +
      '  <h3 id="dev-pause-h">Pause this device?</h3>' +
      '  <p id="dev-pause-p">This cuts the device off Wi-Fi straight away. You can unpause any time.</p>' +
      '  <button class="cta-red" id="dev-pause-go">Pause now</button>' +
      '  <button class="cta-alt" onclick="_closeDevPauseSheet()">Cancel</button>' +
      '</div>';
    document.body.appendChild(bg);
    bg.addEventListener('click', function(e){ if (e.target === bg) _closeDevPauseSheet(); });
  }
  var safeName = name || 'this device';
  document.getElementById('dev-pause-h').textContent = 'Pause ' + safeName + '?';
  document.getElementById('dev-pause-p').textContent = safeName + ' will be cut off your Wi-Fi straight away. You can unpause any time.';
  var go = document.getElementById('dev-pause-go');
  go.disabled = false;
  go.textContent = 'Pause now';
  go.onclick = function(){ _doPauseDevice(fingerprint); };
  bg.classList.add('show');
};
window._closeDevPauseSheet = function() {
  var bg = document.getElementById('dev-pause-sheet'); if (bg) bg.classList.remove('show');
};
window._doPauseDevice = function(fingerprint) {
  var go = document.getElementById('dev-pause-go');
  if (go) { go.disabled = true; go.textContent = 'Pausing…'; }
  fetch(_drUrlForDevices('dr_wifi_pause_client'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ router_id: window._devRouter, client_id: fingerprint, by: 'customer' })
  })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (data && data.ok) {
        _markPausedInPayload(fingerprint, true);
        _closeDevPauseSheet();
        _renderAll();
      } else {
        if (go) { go.disabled = false; go.textContent = 'Try again'; }
        alert((data && data.error) || 'Could not pause device. Try again.');
      }
    })
    .catch(function(){
      if (go) { go.disabled = false; go.textContent = 'Try again'; }
      alert('Network error. Try again.');
    });
};
window._unpauseDevice = function(fingerprint) {
  if (!fingerprint) return;
  fetch(_drUrlForDevices('dr_wifi_unpause_client'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ router_id: window._devRouter, client_id: fingerprint, by: 'customer' })
  })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (data && data.ok) {
        _markPausedInPayload(fingerprint, false);
        _renderAll();
      } else {
        alert((data && data.error) || 'Could not unpause device. Try again.');
      }
    })
    .catch(function(){ alert('Network error. Try again.'); });
};

// ──── Fetch ────
// ──── v4.19.0: persistent device sighting log ────
// Tracks which fingerprints have ever been on this router so we can flag
// genuinely new devices (NEW badge) and let the customer acknowledge them.
window._devSeenMap = {};  // keyed by fingerprint → { is_new, first_seen_at, ... }

window._fetchSeenDevices = function() {
  var routerId = window._devRouter;
  if (!routerId) return;
  DishNet.apiFetch(location.pathname + '?page=api&action=app_devices_get_seen&router_id=' + encodeURIComponent(routerId))
    .then(function(r){ return r.json(); })
    .then(function(resp){
      if (!resp || resp.status !== 'success' || !resp.data || !Array.isArray(resp.data.devices)) return;
      var map = {};
      for (var i = 0; i < resp.data.devices.length; i++) {
        var d = resp.data.devices[i];
        if (d.fingerprint) map[d.fingerprint] = d;
      }
      window._devSeenMap = map;
      // Re-render so NEW badges appear/disappear based on fresh ack state
      if (window._devPayload) _renderAll();
      _updateNewBanner();
    })
    .catch(function(){ /* leave map empty — view still works without NEW badges */ });
};

window._recordSeen = function(clients) {
  var routerId = window._devRouter;
  if (!routerId || !clients || !clients.length) return;
  // Build a compact device list from the live payload
  var devs = [];
  for (var i = 0; i < clients.length; i++) {
    var c = clients[i];
    if (c.is_controller) continue;  // don't track router internals
    var fp = c.fingerprint || c.mac;
    if (!fp) continue;
    // v4.20.0: include IP for forensic history (last 5 IPs, last 5 hostnames)
    devs.push({ fingerprint: fp, hostname: c.name || '', ip: c.ip || c.ip_address || '' });
  }
  if (!devs.length) return;
  DishNet.apiFetch(location.pathname + '?page=api&action=app_devices_record_seen', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ router_id: routerId, devices: devs })
  })
    .then(function(r){ return r.json(); })
    .then(function(resp){
      // If the server tells us new devices were just inserted, refresh the
      // seen map so they immediately get the NEW badge on next render.
      if (resp && resp.status === 'success' && resp.data && resp.data.new_count > 0) {
        window._fetchSeenDevices();
      }
    })
    .catch(function(){ /* sighting failure is non-fatal */ });
};

// ──── v4.20.0: time-based access (paid grants) ────
// Per-fingerprint map of active grants. Populated by _fetchPaidAccess
// every 30s. Drives the countdown badge and grant/extend buttons in the
// device row.
window._devPaidMap = {};   // fingerprint → { id, expires_at, seconds_left, ... }
window._devPaidToday = null; // { sessions, total_minutes, revenue_ssp, ... }

window._fetchPaidAccess = function() {
  var routerId = window._devRouter;
  if (!routerId) return;
  DishNet.apiFetch(location.pathname + '?page=api&action=app_paid_access_list&router_id=' + encodeURIComponent(routerId))
    .then(function(r){ return r.json(); })
    .then(function(resp){
      if (!resp || resp.status !== 'success' || !resp.data) return;
      var map = {};
      var active = resp.data.active || [];
      for (var i = 0; i < active.length; i++) {
        if (active[i].fingerprint) map[active[i].fingerprint] = active[i];
      }
      window._devPaidMap = map;
      window._devPaidToday = resp.data.today || null;
      _updateTimedBanner();
      // Re-render device rows so countdown badges appear/update.
      // Rather than full re-render, just refresh the badges in place
      // (cheaper and avoids flicker). Fall back to full render if needed.
      _refreshTimedBadges();
    })
    .catch(function(){ /* non-fatal */ });
};

window._showGrantSheet = function(fingerprint, deviceName) {
  if (!fingerprint) return;
  var bg = document.getElementById('dev-grant-sheet');
  if (bg) bg.remove();
  bg = document.createElement('div');
  bg.id = 'dev-grant-sheet';
  bg.className = 'hs-sheet-bg';
  // 30 min, 1 hr, 4 hr, 24 hr, Custom — same chips proposed in spec
  bg.innerHTML =
    '<div class="hs-sheet">' +
    '  <h3>Grant time access</h3>' +
    '  <p>Give <b>' + (deviceName || 'this device') + '</b> internet for a set time. After the timer expires the device will be auto-paused.</p>' +
    '  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin:14px 0">' +
    '    <button class="grant-chip" data-mins="30">30 min</button>' +
    '    <button class="grant-chip" data-mins="60">1 hour</button>' +
    '    <button class="grant-chip" data-mins="240">4 hours</button>' +
    '    <button class="grant-chip" data-mins="1440">24 hours</button>' +
    '  </div>' +
    '  <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px">' +
    '    <label style="font-size:11px;color:var(--gray-2);font-weight:600;letter-spacing:.3px;text-transform:uppercase">Custom</label>' +
    '    <input type="number" id="grant-custom-mins" min="1" max="10080" placeholder="minutes" style="flex:1;padding:8px 10px;border:1px solid var(--gray-light);border-radius:8px;font-size:13px;font-family:Barlow,sans-serif">' +
    '    <button class="grant-chip" data-mins="0" id="grant-custom-go">Go</button>' +
    '  </div>' +
    '  <div style="border-top:1px solid var(--off-white);margin:14px 0;padding-top:14px">' +
    '    <div style="font-size:10px;color:var(--gray-2);font-weight:700;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:8px">Cash collected (optional)</div>' +
    '    <div style="display:flex;gap:8px">' +
    '      <input type="number" id="grant-amount-ssp" placeholder="SSP" min="0" step="100" style="flex:1;padding:8px 10px;border:1px solid var(--gray-light);border-radius:8px;font-size:13px">' +
    '      <input type="text" id="grant-note" placeholder="Note (e.g. Table 4)" maxlength="60" style="flex:2;padding:8px 10px;border:1px solid var(--gray-light);border-radius:8px;font-size:13px">' +
    '    </div>' +
    '  </div>' +
    '  <button class="cta-alt" onclick="_cancelGrantSheet()">Cancel</button>' +
    '</div>';
  document.body.appendChild(bg);
  bg.addEventListener('click', function(e){ if (e.target === bg) _cancelGrantSheet(); });
  bg.classList.add('show');
  window._devGrantTarget = { fingerprint: fingerprint, name: deviceName || '' };

  // Wire chips
  var chips = bg.querySelectorAll('.grant-chip');
  Array.prototype.forEach.call(chips, function(ch){
    ch.onclick = function(){
      var mins = parseInt(ch.dataset.mins, 10);
      if (!mins || mins < 1) {
        // Custom — read from input
        var inp = document.getElementById('grant-custom-mins');
        mins = parseInt(inp.value, 10);
        if (!mins || mins < 1) {
          inp.style.borderColor = 'var(--brand-red)';
          setTimeout(function(){ inp.style.borderColor = ''; }, 1200);
          return;
        }
      }
      _applyGrant(mins);
    };
  });
};

window._cancelGrantSheet = function() {
  var bg = document.getElementById('dev-grant-sheet');
  if (bg) bg.classList.remove('show');
  window._devGrantTarget = null;
};

window._applyGrant = function(minutes) {
  if (!window._devGrantTarget) return;
  var t = window._devGrantTarget;
  var amountSsp = parseFloat(document.getElementById('grant-amount-ssp').value) || null;
  var note = (document.getElementById('grant-note').value || '').trim();

  // Disable all chips during request
  var chips = document.querySelectorAll('.grant-chip');
  Array.prototype.forEach.call(chips, function(ch){ ch.disabled = true; });

  DishNet.apiFetch(location.pathname + '?page=api&action=app_paid_access_grant', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      router_id:   window._devRouter,
      fingerprint: t.fingerprint,
      hostname:    t.name,
      minutes:     minutes,
      amount_ssp:  amountSsp,
      note:        note,
    })
  })
    .then(function(r){ return r.text().then(function(tx){ return [r.status, tx]; }); })
    .then(function(arr){
      var s = arr[0]; var raw = arr[1];
      var resp = null; try { resp = JSON.parse(raw); } catch (e) {}
      if (resp && resp.status === 'success' && resp.data) {
        _cancelGrantSheet();
        // Optimistic update — show countdown immediately
        window._devPaidMap[t.fingerprint] = {
          id: resp.data.id,
          fingerprint: t.fingerprint,
          started_at: resp.data.started_at,
          expires_at: resp.data.expires_at,
          seconds_left: Math.max(0, resp.data.expires_at - Math.floor(Date.now()/1000)),
          amount_ssp: amountSsp,
          note: note,
        };
        _refreshTimedBadges();
        _updateTimedBanner();
        // Also re-fetch full state shortly
        setTimeout(_fetchPaidAccess, 1500);
      } else {
        var msg = (resp && (resp.message || resp.error)) || ('Server error ' + s);
        alert(msg);
        Array.prototype.forEach.call(chips, function(ch){ ch.disabled = false; });
      }
    })
    .catch(function(err){
      alert('Network error: ' + (err && err.message ? err.message : 'unknown'));
      Array.prototype.forEach.call(chips, function(ch){ ch.disabled = false; });
    });
};

window._extendGrant = function(fingerprint, minutes) {
  if (!fingerprint || !minutes) return;
  DishNet.apiFetch(location.pathname + '?page=api&action=app_paid_access_extend', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      router_id:   window._devRouter,
      fingerprint: fingerprint,
      minutes:     minutes,
    })
  })
    .then(function(r){ return r.json(); })
    .then(function(resp){
      if (resp && resp.status === 'success') {
        _fetchPaidAccess();
      } else {
        // 404 = no active grant — fallback to fresh grant
        if (resp && resp.message && resp.message.indexOf('No active grant') !== -1) {
          // Find device name and open the grant sheet
          var clients = (window._devPayload && window._devPayload.clients) || [];
          var name = '';
          for (var i = 0; i < clients.length; i++) {
            if ((clients[i].fingerprint || clients[i].mac) === fingerprint) {
              name = clients[i].name; break;
            }
          }
          _showGrantSheet(fingerprint, name);
        } else {
          alert((resp && resp.message) || 'Could not extend.');
        }
      }
    })
    .catch(function(err){ alert('Network error: ' + (err && err.message ? err.message : 'unknown')); });
};

window._revokeGrant = function(fingerprint, deviceName) {
  if (!fingerprint) return;
  if (!confirm('Cut off ' + (deviceName || 'this device') + ' immediately? They will be paused.')) return;
  DishNet.apiFetch(location.pathname + '?page=api&action=app_paid_access_revoke', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      router_id:   window._devRouter,
      fingerprint: fingerprint,
    })
  })
    .then(function(r){ return r.json(); })
    .then(function(resp){
      if (resp && resp.status === 'success') {
        delete window._devPaidMap[fingerprint];
        _refreshTimedBadges();
        _updateTimedBanner();
        _fetchPaidAccess();
        _fetchDevices(true);  // refresh pause state too
      } else {
        alert((resp && resp.message) || 'Could not revoke.');
      }
    })
    .catch(function(err){ alert('Network error: ' + (err && err.message ? err.message : 'unknown')); });
};

// Update countdown labels in-place. Called from a 1s interval to make
// countdowns tick down smoothly without re-rendering the whole list.
window._refreshTimedBadges = function() {
  var rows = document.querySelectorAll('[data-fp]');
  Array.prototype.forEach.call(rows, function(row){
    var fp = row.dataset.fp;
    var grant = window._devPaidMap[fp];
    var badge = row.querySelector('.cd-timer');
    if (grant && grant.expires_at) {
      var sec = Math.max(0, grant.expires_at - Math.floor(Date.now()/1000));
      var label = _formatCountdown(sec);
      if (badge) {
        badge.textContent = label;
        // Color shift: green > 10 min, amber 1-10 min, red < 1 min
        badge.classList.remove('warn', 'crit');
        if (sec < 60) badge.classList.add('crit');
        else if (sec < 600) badge.classList.add('warn');
      }
    } else if (badge) {
      // No active grant — remove the badge from the DOM so the row can
      // reflow without a stale countdown.
      badge.remove();
    }
  });
};

function _formatCountdown(sec) {
  if (sec < 60)        return sec + 's left';
  var min = Math.floor(sec / 60);
  if (min < 60)        return min + 'm left';
  var hr = Math.floor(min / 60);
  var rem = min % 60;
  return hr + 'h ' + rem + 'm left';
}

// Banner above the device list: shows total active timers + today's
// session/revenue summary. Hidden when there are no active timers AND
// no sessions today.
window._updateTimedBanner = function() {
  var banner = document.getElementById('dev-timed-banner');
  if (!banner) return;
  var activeCount = Object.keys(window._devPaidMap || {}).length;
  var today = window._devPaidToday;
  if (activeCount === 0 && (!today || today.sessions === 0)) {
    banner.style.display = 'none';
    return;
  }
  banner.style.display = '';
  var line1El = document.getElementById('dev-timed-line1');
  var line2El = document.getElementById('dev-timed-line2');
  if (line1El) {
    line1El.textContent = activeCount === 0
      ? 'No active timers'
      : (activeCount + ' active timer' + (activeCount === 1 ? '' : 's'));
  }
  if (line2El && today) {
    var hrs = Math.floor((today.total_minutes || 0) / 60);
    var mins = (today.total_minutes || 0) % 60;
    var dur = hrs ? (hrs + 'h ' + mins + 'm') : (mins + 'm');
    var rev = '';
    if (today.revenue_ssp > 0) rev = ' · ' + today.revenue_ssp.toLocaleString() + ' SSP';
    line2El.textContent = 'Today: ' + (today.sessions || 0) + ' sessions · ' + dur + rev;
  }
};

window._ackDevice = function(fingerprint, name) {
  var routerId = window._devRouter;
  if (!routerId || !fingerprint) return;
  // Optimistic update — flip is_new locally so the badge disappears
  // immediately. Server call confirms; on failure we re-fetch to revert.
  if (window._devSeenMap[fingerprint]) {
    window._devSeenMap[fingerprint].is_new = false;
    window._devSeenMap[fingerprint].acknowledged_at = Math.floor(Date.now() / 1000);
  } else {
    window._devSeenMap[fingerprint] = { is_new: false, acknowledged_at: Math.floor(Date.now() / 1000) };
  }
  if (window._devPayload) _renderAll();
  _updateNewBanner();

  DishNet.apiFetch(location.pathname + '?page=api&action=app_devices_acknowledge', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      router_id: routerId,
      fingerprint: fingerprint,
      label: 'Mark as known',
    })
  })
    .then(function(r){ return r.json(); })
    .then(function(resp){
      if (!resp || resp.status !== 'success') {
        window._fetchSeenDevices();
        alert('Could not mark as known. Try again.');
      }
    })
    .catch(function(){
      window._fetchSeenDevices();
    });
};

// v4.19.0: bulk acknowledge — used by "Mark all known" on the NEW banner.
// Fires individual ack calls in parallel; UI updates optimistically.
window._ackAllNew = function() {
  if (!window._devPayload || !window._devPayload.clients) return;
  var routerId = window._devRouter;
  if (!routerId) return;
  var newFps = [];
  for (var i = 0; i < window._devPayload.clients.length; i++) {
    var c = window._devPayload.clients[i];
    if (c.is_controller) continue;
    var fp = c.fingerprint || c.mac;
    if (!fp) continue;
    var info = window._devSeenMap[fp];
    if (info && info.is_new) newFps.push(fp);
  }
  if (newFps.length === 0) return;
  // Optimistic update
  for (var j = 0; j < newFps.length; j++) {
    if (window._devSeenMap[newFps[j]]) {
      window._devSeenMap[newFps[j]].is_new = false;
    }
  }
  _renderAll();
  _updateNewBanner();
  // Fire all acks in parallel — no need to block UI
  for (var k = 0; k < newFps.length; k++) {
    (function(fp){
      DishNet.apiFetch(location.pathname + '?page=api&action=app_devices_acknowledge', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ router_id: routerId, fingerprint: fp, label: 'Mark all known' })
      }).catch(function(){ /* will catch up on next _fetchSeenDevices */ });
    })(newFps[k]);
  }
};

// v4.19.0: rotate password (boot all devices). Same prepare→show→apply
// pattern as the enable flow — show the new password before pushing so
// the customer can save it before their own device gets disconnected.
window._showRotateConfirm = function() {
  var routerId = window._devRouter;
  if (!routerId) return;
  // Step 1: prepare (no router push yet)
  DishNet.apiFetch(location.pathname + '?page=api&action=app_hotspot_rotate_password', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ router_id: routerId, stage: 'prepare' })
  })
    .then(function(r){ return r.text().then(function(t){ return [r.status, t]; }); })
    .then(function(arr){
      var status = arr[0]; var raw = arr[1];
      var resp = null; try { resp = JSON.parse(raw); } catch (e) {}
      if (resp && resp.status === 'success' && resp.data && resp.data.wifi_password) {
        _showRotatePasswordSheet(resp.data);
      } else {
        var msg = (resp && (resp.message || resp.error)) || ('Server error ' + status);
        alert(msg);
      }
    })
    .catch(function(err){
      alert('Network error: ' + (err && err.message ? err.message : 'unknown'));
    });
};

window._showRotatePasswordSheet = function(prep) {
  var bg = document.getElementById('dev-rotate-sheet');
  if (bg) bg.remove();
  bg = document.createElement('div');
  bg.id = 'dev-rotate-sheet';
  bg.className = 'hs-sheet-bg';
  bg.innerHTML =
    '<div class="hs-sheet">' +
    '  <h3>Boot all devices?</h3>' +
    '  <p>This rotates your Wi-Fi password. Every device currently connected will be kicked off and need to reconnect with the new password.</p>' +
    '  <div style="background:#f5f5f5;border-radius:12px;padding:18px 14px;margin:14px 0;text-align:center">' +
    '    <div style="font-size:10px;font-weight:700;letter-spacing:1.4px;color:var(--gray-2);text-transform:uppercase;margin-bottom:6px">New Wi-Fi password</div>' +
    '    <div style="font-family:Barlow,monospace;font-weight:800;font-size:32px;letter-spacing:6px;color:var(--dark);margin-bottom:8px;word-break:break-all">' + (prep.wifi_password || '') + '</div>' +
    '    <button id="dev-rotate-copy" class="cta-alt" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-size:13px"><svg class="ic" style="width:14px;height:14px"><use href="#i-copy"/></svg> <span id="dev-rotate-copy-lbl">Copy password</span></button>' +
    '  </div>' +
    '  <div style="background:var(--amber-light);border-radius:8px;padding:10px 12px;margin:0 0 14px;font-size:11px;color:var(--amber-dark);line-height:1.5">' +
    '    <b>Important:</b> save this password before applying. Your own device will be disconnected and need to reconnect with this password. The Wi-Fi name (<b>' + (prep.wifi_ssid || '') + '</b>) stays the same.' +
    '  </div>' +
    '  <button class="cta-red" id="dev-rotate-go">' +
    '    <svg class="ic" style="width:14px;height:14px"><use href="#i-power"/></svg> I\u2019ve saved it, boot them off' +
    '  </button>' +
    '  <button class="cta-alt" onclick="_cancelRotateSheet()">Cancel</button>' +
    '</div>';
  document.body.appendChild(bg);
  bg.addEventListener('click', function(e){ if (e.target === bg) _cancelRotateSheet(); });
  bg.classList.add('show');

  window._devRotatePrep = prep;

  // Wire copy
  var copyBtn = document.getElementById('dev-rotate-copy');
  var copyLbl = document.getElementById('dev-rotate-copy-lbl');
  if (copyBtn) {
    copyBtn.onclick = function() {
      var pw = window._devRotatePrep && window._devRotatePrep.wifi_password;
      if (!pw) return;
      var done = function(){ if (copyLbl) { copyLbl.textContent = 'Copied!'; setTimeout(function(){ copyLbl.textContent = 'Copy password'; }, 1500); } };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(pw).then(done).catch(function(){
          try { var ta = document.createElement('textarea'); ta.value = pw; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); done(); } catch (e) {}
        });
      }
    };
  }
  // Wire apply
  var goBtn = document.getElementById('dev-rotate-go');
  if (goBtn) goBtn.onclick = _applyRotate;
};

window._cancelRotateSheet = function() {
  var bg = document.getElementById('dev-rotate-sheet');
  if (bg) bg.classList.remove('show');
  window._devRotatePrep = null;
};

window._applyRotate = function() {
  if (!window._devRotatePrep) return;
  var prep = window._devRotatePrep;
  var go = document.getElementById('dev-rotate-go');
  if (go) {
    go.disabled = true;
    go.innerHTML = '<svg class="ic" style="width:14px;height:14px;animation:spin 1s linear infinite"><use href="#i-refresh"/></svg> Rotating password\u2026';
  }
  DishNet.apiFetch(location.pathname + '?page=api&action=app_hotspot_rotate_password', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      router_id: window._devRouter,
      stage: 'apply',
      password: prep.wifi_password,
    })
  })
    .then(function(r){ return r.text().then(function(t){ return [r.status, t]; }); })
    .then(function(arr){
      var status = arr[0]; var raw = arr[1];
      var resp = null; try { resp = JSON.parse(raw); } catch (e) {}
      if (resp && resp.status === 'success') {
        _cancelRotateSheet();
        // After rotation, all devices will need to reconnect. We don't auto-clear
        // the seen log — once the (same) devices reconnect they'll be recognized
        // as known again. But any NEW device that reconnects with the new password
        // will get flagged again, which is exactly what we want.
        alert('Password rotated. All devices have been disconnected.');
        window._fetchDevices(true);
      } else {
        if (go) { go.disabled = false; go.innerHTML = '<svg class="ic" style="width:14px;height:14px"><use href="#i-power"/></svg> I\u2019ve saved it, boot them off'; }
        var msg = (resp && (resp.message || resp.error)) || ('Server error ' + status);
        alert(msg);
      }
    })
    .catch(function(err){
      if (go) { go.disabled = false; go.innerHTML = '<svg class="ic" style="width:14px;height:14px"><use href="#i-power"/></svg> I\u2019ve saved it, boot them off'; }
      alert('Network error: ' + (err && err.message ? err.message : 'unknown'));
    });
};

// v4.19.0: count NEW devices among CURRENTLY-CONNECTED clients and
// update the banner. Hidden if zero. Visible with count if > 0.
window._updateNewBanner = function() {
  var banner = document.getElementById('dev-new-banner');
  if (!banner) return;
  if (!window._devPayload || !window._devPayload.clients) {
    banner.style.display = 'none';
    return;
  }
  var count = 0;
  for (var i = 0; i < window._devPayload.clients.length; i++) {
    var c = window._devPayload.clients[i];
    if (c.is_controller) continue;
    var fp = c.fingerprint || c.mac;
    if (!fp) continue;
    var info = window._devSeenMap[fp];
    if (info && info.is_new) count++;
  }
  if (count === 0) {
    banner.style.display = 'none';
    return;
  }
  banner.style.display = '';
  var ce = document.getElementById('dev-new-count');
  if (ce) ce.textContent = count;
  var pe = document.getElementById('dev-new-plural');
  if (pe) pe.textContent = count === 1 ? '' : 's';
};

window._fetchDevices = function(manual) {
  var loading = document.getElementById('dev-loading');
  var error   = document.getElementById('dev-error');
  var content = document.getElementById('dev-content');

  // Show loading only on first fetch or manual refresh — silent refresh otherwise
  var isFirst = !window._devPayload;
  if (isFirst || manual) {
    loading.style.display = 'block';
    error.style.display = 'none';
    content.style.display = 'none';
  }
  window._devLastFetch = Date.now();

  var routerId = window._devRouter;
  if (!routerId) {
    loading.style.display = 'none';
    error.style.display = 'block';
    document.getElementById('dev-error-msg').textContent = 'No router found for this site';
    return;
  }

  var endpoint = location.href.split('/_plugins/')[0] + '/_plugins/dishnet-data-report/public.php?action=dr_wifi_get_status&router_id=' + encodeURIComponent(routerId);
  fetch(endpoint)
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.ok) {
        // Only swap to error state on the first failed fetch; on silent refresh keep existing data visible
        if (isFirst) {
          loading.style.display = 'none';
          error.style.display = 'block';
          var msg = data.error || 'Failed to connect';
          if (msg.indexOf('TARGETID') !== -1)    msg = 'Router is offline or unreachable';
          if (msg.indexOf('token_expired') !== -1) msg = 'Session expired — try again';
          document.getElementById('dev-error-msg').textContent = msg;
        }
        return;
      }
      loading.style.display = 'none';
      error.style.display = 'none';
      content.style.display = 'block';
      window._devPayload = data;
      // v4.19.0: record sightings + render. Fire-and-forget — the view
      // renders immediately, sighting log catches up in the background.
      _recordSeen(data.clients || []);
      _renderAll();
      _updateNewBanner();
    })
    .catch(function(err) {
      if (isFirst) {
        loading.style.display = 'none';
        error.style.display = 'block';
        document.getElementById('dev-error-msg').textContent = 'Network error: ' + err.message;
      }
    });
};

// ──── Auto-refresh every 30s, paused when tab hidden ────
function _startPolling() {
  if (window._devPollId) return;
  window._devPollId = setInterval(function(){
    if (document.visibilityState === 'hidden') return;
    // Skip if last fetch was very recent (e.g. manual click)
    if (Date.now() - window._devLastFetch < 10000) return;
    window._fetchDevices(false);
  }, 30000);
}
function _stopPolling() {
  if (window._devPollId) { clearInterval(window._devPollId); window._devPollId = null; }
}
document.addEventListener('visibilitychange', function(){
  if (document.visibilityState === 'visible') {
    // Refresh if it's been > 20s since last
    if (Date.now() - window._devLastFetch > 20000) window._fetchDevices(false);
  }
});
window.addEventListener('pagehide', _stopPolling);

// Kick off
window._fetchDevices(true);
window._fetchSeenDevices();
// v4.20.0: paid access state + ticker
window._fetchPaidAccess();
window._paidPollId = setInterval(window._fetchPaidAccess, 30000);
// 1s tick to update countdown labels in-place. Cheap — just text swap.
window._timerTickId = setInterval(window._refreshTimedBadges, 1000);
window.addEventListener('pagehide', function(){
  if (window._paidPollId) clearInterval(window._paidPollId);
  if (window._timerTickId) clearInterval(window._timerTickId);
});
_blockSyncFromServer();
_startPolling();
})();
</script>

<?php endif; // end view dispatch ?>

<script>
// ══════════════════════════════════════════════════════════════════
// JavaScript bridge — talks to Android native shell via window.Android
// Fallbacks to browser equivalents when not in WebView (for dev testing).
// ══════════════════════════════════════════════════════════════════
window.DishNet = {
  // JWT token for in-page navigation (WebView only sends Authorization header on initial load)
  _token: '<?= pe($token) ?>',

  // v4.12.20 — Multi-account state.
  // _accounts: full list from JWT accounts claim (may have 1 or many)
  // _activeAccountId: which one the user is currently viewing (persisted in localStorage)
  // _primaryId: the default account (sub claim)
  _accounts: <?= json_encode(array_map(function($a) {
      return [
          'id'     => (int)($a['id'] ?? 0),
          'name'   => (string)($a['name'] ?? ''),
          'status' => (string)($a['status'] ?? ''),
          'plans'  => (string)($a['plans'] ?? ''),
      ];
  }, $portalClaims['accounts'] ?? []), JSON_UNESCAPED_UNICODE) ?>,
  _primaryId: <?= (int)($portalClaims['sub'] ?? 0) ?>,
  _activeAccountId: null,  // set below after DOM ready

  // Resolve which account should be active right now
  activeAccountId: function() {
    if (this._activeAccountId) return this._activeAccountId;
    try {
      var stored = parseInt(localStorage.getItem('dn_active_account_id') || '0', 10);
      if (stored && this._accounts.some(function(a) { return a.id === stored; })) {
        this._activeAccountId = stored;
        // Ensure cookie is synced so server-side renders match
        try {
          document.cookie = 'dn_active_account_id=' + stored
            + '; path=/; max-age=2592000; samesite=Lax';
        } catch (e) {}
        return stored;
      }
    } catch (e) {}
    this._activeAccountId = this._primaryId;
    return this._primaryId;
  },

  // Switch active account; triggers full-page reload to refetch all views
  setActiveAccount: function(accountId) {
    accountId = parseInt(accountId, 10);
    if (!accountId || !this._accounts.some(function(a) { return a.id === accountId; })) return;
    try { localStorage.setItem('dn_active_account_id', String(accountId)); } catch (e) {}
    // v4.12.20 — also set cookie so server-side PHP render respects the switch
    // on the next page load. Cookie path is root so it applies across the plugin.
    // 30-day lifetime matches JWT default; same-site=Lax works in WebView and browser.
    try {
      document.cookie = 'dn_active_account_id=' + accountId
        + '; path=/; max-age=2592000; samesite=Lax';
    } catch (e) {}
    this._activeAccountId = accountId;
    // Hard reload so every cached server-side view refreshes for the new account.
    // Keeps the token in the URL for WebView scenarios.
    var u = new URL(location.href);
    u.searchParams.set('view', 'home');
    if (this._token) u.searchParams.set('token', this._token);
    location.href = u.toString();
  },

  // Wrapper around fetch() that injects the active-account header + bearer token.
  // All portal fetches should use this instead of raw fetch() to respect the switcher.
  apiFetch: function(url, opts) {
    opts = opts || {};
    var headers = Object.assign({}, opts.headers || {});
    if (this._token && !headers['Authorization']) headers['Authorization'] = 'Bearer ' + this._token;
    var aid = this.activeAccountId();
    if (aid) headers['X-Account-Id'] = String(aid);
    opts.headers = headers;
    return fetch(url, opts);
  },

  // Navigate to another tab via native
  go(view) {
    if (window.Android && window.Android.navigateTab) {
      window.Android.navigateTab(view);
    } else {
      var u = new URL(location.href);
      u.searchParams.set('view', view);
      if (this._token) u.searchParams.set('token', this._token);
      location.href = u.toString();
    }
  },
  // Navigate within the same WebView (for sub-pages like invoice detail, wifi change, usage)
  goInternal(view, extraParams) {
    var u = new URL(location.href);
    u.searchParams.set('view', view);
    if (this._token) u.searchParams.set('token', this._token);
    if (extraParams) {
      for (var k in extraParams) u.searchParams.set(k, extraParams[k]);
    }
    location.href = u.toString();
  },
  openWifi() {
    if (window.Android && window.Android.openWifi) {
      window.Android.openWifi();
    } else {
      alert('Wi-Fi screen (native only)');
    }
  },
  openWhatsApp(phone, msg) {
    if (window.Android && window.Android.openWhatsApp) {
      window.Android.openWhatsApp(phone, msg || '');
    } else {
      const url = 'https://wa.me/' + phone.replace(/[^0-9]/g, '') + (msg ? '?text=' + encodeURIComponent(msg) : '');
      window.open(url, '_blank');
    }
  },
  openPhone(phone) {
    if (window.Android && window.Android.openPhone) {
      window.Android.openPhone(phone);
    } else {
      location.href = 'tel:' + phone;
    }
  },
  openEmail(email) {
    if (window.Android && window.Android.openEmail) {
      window.Android.openEmail(email);
    } else {
      location.href = 'mailto:' + email;
    }
  },
  openInvoice(id) {
    // Navigate to invoice detail view — carry token for auth
    var u = new URL(location.href);
    u.searchParams.set('view', 'invoice_detail');
    u.searchParams.set('inv_id', id);
    if (this._token) u.searchParams.set('token', this._token);
    location.href = u.toString();
  },
  notifyPayment(id, number, amount) {
    var name = '<?= pe($portalCustomerName) ?>';
    var clientId = <?= $portalCustomerId ?>;
    var msg = '✅ *Payment Notification*\n\n'
      + 'Customer: *' + name + '* (#' + clientId + ')\n'
      + 'Invoice: *' + number + '*\n'
      + 'Amount: *$' + Math.round(amount) + ' USD*\n\n'
      + 'The customer says they have paid. Please verify and confirm.';
    // Send to Bidal's number
    DishNet.openWhatsApp('+211921443002', msg);
  },
  // v4.12.20 — In-app PDF viewer. Fetches the PDF with auth, displays in a
  // full-screen modal with an explicit close button. Avoids the iOS WebView
  // issue where opening a PDF in the same view leaves no way to navigate back.
  viewPdf(url, title) {
    var self = this;
    // Build overlay the first time
    var overlay = document.getElementById('dn-pdf-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'dn-pdf-overlay';
      overlay.style.cssText = 'position:fixed;inset:0;background:#000;z-index:9999;display:none;flex-direction:column';
      overlay.innerHTML =
        '<div style="flex-shrink:0;background:#141414;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:calc(env(safe-area-inset-top, 0px) + 10px) calc(env(safe-area-inset-right, 0px) + 14px) 10px calc(env(safe-area-inset-left, 0px) + 14px);gap:10px">' +
        '  <button id="dn-pdf-close" style="background:transparent;border:1px solid rgba(255,255,255,.2);color:#fff;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;flex-shrink:0">' +
        '    <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2.5;stroke-linecap:round"><path d="M15 18l-6-6 6-6"/></svg>' +
        '    <span>Back</span>' +
        '  </button>' +
        '  <div id="dn-pdf-title" style="flex:1;text-align:center;font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0"></div>' +
        '  <button id="dn-pdf-open-ext" title="Open in new tab" style="background:transparent;border:1px solid rgba(255,255,255,.2);color:#fff;padding:8px 10px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;flex-shrink:0">↗</button>' +
        '</div>' +
        '<div id="dn-pdf-loading" style="flex:1;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px">Loading…</div>' +
        '<div id="dn-pdf-frame-wrap" style="flex:1;display:none;overflow:auto;-webkit-overflow-scrolling:touch;background:#525659">' +
        '  <iframe id="dn-pdf-frame" style="border:none;background:#fff;display:block;width:100%;height:100%" title="PDF"></iframe>' +
        '</div>' +
        '<div id="dn-pdf-error" style="flex:1;display:none;align-items:center;justify-content:center;flex-direction:column;color:#fff;padding:20px;text-align:center">' +
        '  <div style="font-size:14px;margin-bottom:12px">Preview not available on this device.</div>' +
        '  <a id="dn-pdf-fallback-dl" target="_blank" rel="noopener" style="background:#D41C1C;color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none">Open PDF</a>' +
        '</div>';
      document.body.appendChild(overlay);
      document.getElementById('dn-pdf-close').onclick = function() { self.closePdf(); };
      document.getElementById('dn-pdf-open-ext').onclick = function() {
        if (self._pdfBlobUrl) window.open(self._pdfBlobUrl, '_blank');
      };
    }

    var titleEl  = document.getElementById('dn-pdf-title');
    var frame    = document.getElementById('dn-pdf-frame');
    var frameWrap = document.getElementById('dn-pdf-frame-wrap');
    var loading  = document.getElementById('dn-pdf-loading');
    var errorEl  = document.getElementById('dn-pdf-error');
    titleEl.textContent = title || 'Document';
    if (frameWrap) frameWrap.style.display = 'none';
    errorEl.style.display = 'none';
    loading.style.display = 'flex';
    loading.textContent = 'Loading…';
    overlay.style.display = 'flex';

    // Fetch with auth — then display as blob URL
    self._pdfBlobUrl = null;
    DishNet.apiFetch(url)
      .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.blob();
      })
      .then(function(blob) {
        // Verify it's actually a PDF (not a JSON error)
        if (blob.type && blob.type.indexOf('pdf') === -1 && blob.type.indexOf('octet-stream') === -1) {
          throw new Error('Server returned ' + blob.type);
        }
        var blobUrl = URL.createObjectURL(blob);
        self._pdfBlobUrl = blobUrl;
        loading.style.display = 'none';
        // Set src with PDF URL fragments that ask the viewer to fit width and
        // hide toolbar: #toolbar=0&view=FitH keeps the A4 page from overflowing
        // horizontally on phone screens. Supported by iOS Safari PDF preview,
        // Chrome, and most Android WebViews. Ignored by unsupporting viewers.
        frame.src = blobUrl + '#toolbar=0&view=FitH';
        if (frameWrap) frameWrap.style.display = 'block';
        var dlLink = document.getElementById('dn-pdf-fallback-dl');
        if (dlLink) dlLink.href = blobUrl;
      })
      .catch(function(err) {
        loading.style.display = 'none';
        errorEl.style.display = 'flex';
        // Use the original URL with token as fallback so the user can still open it
        var dlLink = document.getElementById('dn-pdf-fallback-dl');
        if (dlLink) dlLink.href = url + '&token=' + encodeURIComponent(DishNet._token || '');
        console.warn('[viewPdf] failed:', err && err.message);
      });
  },
  closePdf() {
    var overlay = document.getElementById('dn-pdf-overlay');
    if (!overlay) return;
    overlay.style.display = 'none';
    var frame = document.getElementById('dn-pdf-frame');
    if (frame) frame.src = 'about:blank';
    if (this._pdfBlobUrl) {
      try { URL.revokeObjectURL(this._pdfBlobUrl); } catch (e) {}
      this._pdfBlobUrl = null;
    }
  },
  // v4.12.20 — Send invoice PDF to customer's own WhatsApp
  sendInvoiceToWA(invId, invNumber) {
    var btn = document.getElementById('inv-wa-btn-' + invId);
    var status = document.getElementById('inv-wa-status-' + invId);
    if (!btn) return;
    var origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span>Sending…</span>';
    if (status) { status.style.display = 'block'; status.style.color = 'var(--gray-2)'; status.textContent = 'Preparing PDF…'; }

    var url = location.pathname + '?page=api&action=app_invoice_send_whatsapp';
    DishNet.apiFetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ inv_id: invId })
    })
    .then(function(r) { return r.json().then(function(j) { return {ok: r.ok, status: r.status, body: j}; }); })
    .then(function(res) {
      btn.disabled = false;
      btn.innerHTML = origHtml;
      if (!res.ok || res.body.status !== 'success') {
        if (status) {
          status.style.display = 'block';
          status.style.color = 'var(--danger-text)';
          status.textContent = '⚠️ ' + (res.body.message || 'Failed to send. Please try again.');
        }
        return;
      }
      var phone = (res.body.data && res.body.data.sent_to) || '';
      if (status) {
        status.style.display = 'block';
        status.style.color = 'var(--green-mid)';
        status.innerHTML = '✓ Sent to ' + (phone ? phone : 'your WhatsApp');
      }
      setTimeout(function() {
        if (status) status.style.display = 'none';
      }, 8000);
    })
    .catch(function(err) {
      btn.disabled = false;
      btn.innerHTML = origHtml;
      if (status) {
        status.style.display = 'block';
        status.style.color = 'var(--danger-text)';
        status.textContent = '⚠️ Network error — please try again.';
      }
    });
  },
  openNotifications() {
    DishNet.openWhatsApp('+211921443002', 'Hi, I want to check my DishNet notifications and updates.');
  },
  // ── Live WiFi Config fetch ─────────────────────────────────────────────
  fetchLiveWifi() {
    var routerId = window._siteRouterId || '';
    var loadDiv = document.getElementById('live-wifi-loading');
    var dataDiv = document.getElementById('live-wifi-data');
    var errDiv = document.getElementById('live-wifi-error');
    var btn = document.getElementById('wifi-refresh-btn');
    if (loadDiv) loadDiv.innerHTML = '<div style="padding:20px;text-align:center;color:var(--gray)"><div style="font-size:12px">Fetching live WiFi config...</div></div>';
    if (dataDiv) dataDiv.style.display = 'none';
    if (errDiv) errDiv.style.display = 'none';
    if (btn) btn.textContent = 'Loading...';

    var endpoint = location.href.split('/_plugins/')[0] + '/_plugins/dishnet-data-report/public.php?action=dr_wifi_get_config&router_id=' + encodeURIComponent(routerId);
    fetch(endpoint)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (btn) btn.textContent = 'Refresh ↻';
        if (!data.ok || !data.networks || !data.networks.length) {
          // Live config failed — try cached credentials instead
          var siteKit = window._siteKit || '<?= pe($wsKit ?? '') ?>';
          var cacheUrl = location.pathname + '?page=api&action=app_wifi_get&router_id=' + encodeURIComponent(routerId) + '&kit=' + encodeURIComponent(siteKit);
          (window.DishNet ? DishNet.apiFetch(cacheUrl) : fetch(cacheUrl))
            .then(function(r) { return r.json(); })
            .then(function(cd) {
              if (cd.status === 'success' && cd.data && cd.data.ssid) {
                // Show cached data with a note
                if (loadDiv) loadDiv.innerHTML = '<div style="padding:12px;text-align:center;font-size:11px;color:var(--amber-dark);background:var(--amber-light);border-radius:8px;margin:8px 0">Router is offline — showing last known WiFi config</div>';
                if (dataDiv) dataDiv.style.display = 'block';
                var html = '<div class="list-row">';
                html += '<div class="list-ic" style="background:var(--amber-light);color:var(--amber-dark)"><svg class="ic"><use href="#i-wifi"/></svg></div>';
                html += '<div class="list-t">';
                html += '<div class="list-tt">' + DishNet._esc(cd.data.ssid) + ' <span style="font-size:10px;color:var(--gray-2);font-weight:400">Last known</span></div>';
                if (cd.data.password) {
                  html += '<div class="list-ts" style="font-family:monospace;letter-spacing:1px">';
                  html += '<span id="wifi-pw-cached" style="cursor:pointer" onclick="var s=this;if(s.dataset.show){s.textContent=\'••••••••\';delete s.dataset.show}else{s.textContent=\'' + DishNet._esc(cd.data.password) + '\';s.dataset.show=1}">••••••••</span>';
                  html += ' <span style="cursor:pointer;color:var(--red);font-size:10px;font-family:Barlow,sans-serif" onclick="navigator.clipboard.writeText(\'' + DishNet._esc(cd.data.password) + '\');this.textContent=\'Copied!\';var t=this;setTimeout(function(){t.textContent=\'Copy\'},1500)">Copy</span>';
                  html += '</div>';
                }
                html += '</div></div>';
                document.getElementById('live-wifi-networks').innerHTML = html;
                // Pre-fill SSID from cache (hidden input)
                var inp = document.getElementById('site-wifi-ssid');
                if (inp && !inp.value) inp.value = cd.data.ssid;
                // v4.12.22: also populate read-only display card
                var dispVal = document.getElementById('site-wifi-name-display-value');
                if (dispVal && cd.data.ssid) dispVal.textContent = cd.data.ssid;
              } else {
                if (loadDiv) loadDiv.innerHTML = '<div style="padding:20px;text-align:center;color:var(--gray)"><div style="font-size:12px;color:var(--danger-text)">Router is offline</div><div style="font-size:11px;color:var(--gray);margin-top:6px">Cannot read current WiFi. Enter new SSID and password manually below.</div></div>';
              }
            })
            .catch(function() {
              if (loadDiv) loadDiv.innerHTML = '<div style="padding:20px;text-align:center;color:var(--gray)"><div style="font-size:12px;color:var(--danger-text)">Router is offline</div><div style="font-size:11px;color:var(--gray);margin-top:6px">Enter new SSID and password manually below.</div></div>';
            });
          return;
        }
        if (loadDiv) loadDiv.style.display = 'none';
        if (dataDiv) dataDiv.style.display = 'block';

        var html = '';
        var firstSsid = '';
        var firstPass = '';
        data.networks.forEach(function(net, i) {
          var bandLabel = net.band === 5 ? '5 GHz' : (net.band === 2 ? '2.4 GHz' : 'WiFi');
          if (i === 0) { firstSsid = net.ssid; firstPass = net.password || ''; }
          html += '<div class="list-row">';
          html += '<div class="list-ic" style="background:var(--green-light);color:var(--green-mid)"><svg class="ic"><use href="#i-wifi"/></svg></div>';
          html += '<div class="list-t">';
          html += '<div class="list-tt">' + DishNet._esc(net.ssid) + ' <span style="font-size:10px;color:var(--gray-2);font-weight:400">' + bandLabel + '</span></div>';
          if (net.password) {
            html += '<div class="list-ts" style="font-family:monospace;letter-spacing:1px">';
            html += '<span id="wifi-pw-' + i + '" style="cursor:pointer" onclick="var s=this;if(s.dataset.show){s.textContent=\'••••••••\';delete s.dataset.show}else{s.textContent=\'' + DishNet._esc(net.password) + '\';s.dataset.show=1}">••••••••</span>';
            html += ' <span style="cursor:pointer;color:var(--red);font-size:10px;font-family:Barlow,sans-serif" onclick="navigator.clipboard.writeText(\'' + DishNet._esc(net.password) + '\');this.textContent=\'Copied!\';var t=this;setTimeout(function(){t.textContent=\'Copy\'},1500)">Copy</span>';
            html += '</div>';
          }
          html += '</div></div>';
        });
        document.getElementById('live-wifi-networks').innerHTML = html;

        // v4.12.22: Pre-fill hidden SSID input + display card from live config
        var inp2 = document.getElementById('site-wifi-ssid');
        if (inp2 && !inp2.value && firstSsid) inp2.value = firstSsid;
        var dispVal2 = document.getElementById('site-wifi-name-display-value');
        if (dispVal2 && firstSsid) dispVal2.textContent = firstSsid;

        // Generate QR code
        if (firstSsid && firstPass) {
          DishNet._generateWifiQR(firstSsid, firstPass);
        }
      })
      .catch(function(err) {
        if (btn) btn.textContent = 'Retry ↻';
        if (loadDiv) loadDiv.innerHTML = '<div style="padding:20px;text-align:center;color:var(--danger-text);font-size:12px">Network error: ' + err.message + '</div>';
      });
  },

  // ── QR Code Generator (pure JS, no library) ──────────────────────────
  _generateWifiQR(ssid, password) {
    var qrSection = document.getElementById('wifi-qr-section');
    var canvas = document.getElementById('wifi-qr-canvas');
    var label = document.getElementById('wifi-qr-label');
    if (!qrSection || !canvas) return;

    // WiFi QR format: WIFI:T:WPA;S:<ssid>;P:<password>;;
    var qrData = 'WIFI:T:WPA;S:' + ssid + ';P:' + password + ';;';

    // Simple QR using a 2D barcode approach — for production, use a library
    // For now, create a visual representation customers can use
    qrSection.style.display = 'block';
    if (label) label.textContent = ssid + ' · ' + password;

    // Use the browser's built-in QR via a free API (works offline-first with cache)
    var img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = function() {
      canvas.width = 180;
      canvas.height = 180;
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, 180, 180);
      ctx.drawImage(img, 10, 10, 160, 160);
    };
    img.onerror = function() {
      // Fallback: show text-based credentials
      canvas.style.display = 'none';
    };
    img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' + encodeURIComponent(qrData);
  },

  // ── Speed Test (browser-based, prototype-matching UI) ──────────────
  _speedResult: null,
  runSpeedTest() {
    var idle = document.getElementById('speed-idle');
    var running = document.getElementById('speed-running');
    var result = document.getElementById('speed-result');
    var phase = document.getElementById('speed-phase');
    var arcNum = document.getElementById('speed-arc-num');
    if (idle) idle.style.display = 'none';
    if (result) result.style.display = 'none';
    if (running) running.style.display = 'block';
    if (phase) phase.textContent = 'Testing latency...';
    if (arcNum) arcNum.textContent = '—';

    var dlSpeed = 0, ulSpeed = 0, latency = 0;
    var self = this;

    // Step 1: Latency — average of 3 pings
    var pings = [];
    var doPing = function(cb) {
      var t = performance.now();
      var img = new Image();
      img.onload = img.onerror = function() { pings.push(Math.round(performance.now() - t)); cb(); };
      img.src = 'https://www.cloudflare.com/favicon.ico?t=' + Date.now() + Math.random();
    };
    if (phase) phase.textContent = 'Measuring latency...';
    doPing(function(){ doPing(function(){ doPing(function(){
      // Use median ping (most stable)
      pings.sort(function(a,b){return a-b;});
      latency = pings[1] || pings[0];
      if (arcNum) arcNum.textContent = latency;
      if (phase) phase.textContent = 'Testing download...';

      // Step 2: Download — use 5MB file for more accurate measurement
      var dlStart = performance.now();
      fetch('https://speed.cloudflare.com/__down?bytes=5000000', { cache: 'no-store' })
        .then(function(r) { return r.blob(); })
        .then(function(blob) {
          var dlTime = (performance.now() - dlStart) / 1000;
          dlSpeed = Math.round((blob.size * 8) / (dlTime * 1000000) * 10) / 10;
          if (arcNum) arcNum.textContent = dlSpeed;
          if (phase) phase.textContent = 'Testing upload...';

          // Step 3: Upload — use 1MB payload
          var ulData = new Blob([new ArrayBuffer(1000000)]);
          var ulStart = performance.now();
          fetch('https://speed.cloudflare.com/__up', { method: 'POST', body: ulData, cache: 'no-store' })
          .then(function() {
            var ulTime = (performance.now() - ulStart) / 1000;
            ulSpeed = Math.round((1000000 * 8) / (ulTime * 1000000) * 10) / 10;
            self._showSpeedResult(dlSpeed, ulSpeed, latency);
          })
          .catch(function() { self._showSpeedResult(dlSpeed, 0, latency); });
        })
        .catch(function() { self._showSpeedResult(0, 0, latency); });
    }); }); });
  },

  _showSpeedResult(dl, ul, ping) {
    this._speedResult = { dl: dl, ul: ul, ping: ping, time: new Date().toLocaleString() };

    var running = document.getElementById('speed-running');
    var result = document.getElementById('speed-result');
    if (running) running.style.display = 'none';
    if (result) result.style.display = 'block';

    // Fill values
    var rNum = document.getElementById('speed-result-num');
    var rUp = document.getElementById('speed-r-up');
    var rPing = document.getElementById('speed-r-ping');
    if (rNum) rNum.textContent = dl || '—';
    if (rUp) rUp.textContent = ul || '—';
    if (rPing) rPing.textContent = ping || '—';

    // Verdict
    var verdict = 'Test complete';
    var headline = 'Your speed is good';
    var badgeColor = 'var(--green-mid)';
    var dotColor = 'var(--green)';
    if (dl >= 50) { headline = 'Excellent connection'; }
    else if (dl >= 20) { headline = 'Good connection'; }
    else if (dl >= 5) { headline = 'Fair connection'; badgeColor = 'var(--amber-dark)'; dotColor = 'var(--amber)'; verdict = 'Could be better'; }
    else if (dl > 0) { headline = 'Slow connection'; badgeColor = 'var(--danger-text)'; dotColor = 'var(--red)'; verdict = 'Below average'; }
    else { headline = 'Test failed'; badgeColor = 'var(--danger-text)'; dotColor = 'var(--red)'; verdict = 'Unable to measure'; }

    var vBadge = document.getElementById('speed-verdict-badge');
    var vText = document.getElementById('speed-verdict');
    var hText = document.getElementById('speed-headline');
    if (vBadge) vBadge.style.color = badgeColor;
    if (vBadge) vBadge.querySelector('span').style.background = dotColor;
    if (vText) vText.textContent = verdict;
    if (hText) hText.textContent = headline;

    // Arc gauge — map dl speed to arc angle (0-180 degrees, max at 150 Mbps)
    var pct = Math.min(1, dl / 150);
    var angle = pct * 160; // degrees of the arc to fill
    // SVG arc path: start at (20,100), sweep to angle position
    var rad = (angle * Math.PI) / 180;
    var endX = 100 - 80 * Math.cos(rad);
    var endY = 100 - 80 * Math.sin(rad);
    var largeArc = angle > 180 ? 1 : 0;
    var arcPath = 'M20 100 A80 80 0 ' + largeArc + ' 1 ' + endX.toFixed(1) + ' ' + endY.toFixed(1);
    var resultArc = document.getElementById('speed-result-arc');
    if (resultArc) resultArc.setAttribute('d', arcPath);

    // Diagnosis cards
    var diagHtml = '';
    // Network check
    if (ping < 100) {
      diagHtml += '<div class="list-row"><div class="list-ic" style="background:var(--green-light);color:var(--green-mid)"><svg class="ic"><use href="#i-check"/></svg></div><div class="list-t"><div class="list-tt">Network latency</div><div class="list-ts">Good · ' + ping + 'ms round trip</div></div></div>';
    } else if (ping < 300) {
      diagHtml += '<div class="list-row"><div class="list-ic" style="background:var(--amber-light);color:var(--amber-dark)"><svg class="ic"><use href="#i-warn"/></svg></div><div class="list-t"><div class="list-tt">Network latency</div><div class="list-ts">Fair · ' + ping + 'ms — video calls may lag</div></div></div>';
    } else {
      diagHtml += '<div class="list-row"><div class="list-ic" style="background:var(--danger-light);color:var(--danger-text)"><svg class="ic"><use href="#i-warn"/></svg></div><div class="list-t"><div class="list-tt">High latency</div><div class="list-ts">' + ping + 'ms — may cause buffering</div></div></div>';
    }
    // Download check
    if (dl >= 25) {
      diagHtml += '<div class="list-row"><div class="list-ic" style="background:var(--green-light);color:var(--green-mid)"><svg class="ic"><use href="#i-check"/></svg></div><div class="list-t"><div class="list-tt">Download speed</div><div class="list-ts">Good for streaming, video calls, and gaming</div></div></div>';
    } else if (dl >= 5) {
      diagHtml += '<div class="list-row"><div class="list-ic" style="background:var(--amber-light);color:var(--amber-dark)"><svg class="ic"><use href="#i-warn"/></svg></div><div class="list-t"><div class="list-tt">Download speed</div><div class="list-ts">OK for browsing — streaming may buffer in HD</div></div></div>';
    } else if (dl > 0) {
      diagHtml += '<div class="list-row"><div class="list-ic" style="background:var(--danger-light);color:var(--danger-text)"><svg class="ic"><use href="#i-warn"/></svg></div><div class="list-t"><div class="list-tt">Slow download</div><div class="list-ts">Try moving closer to the router or check for obstructions</div></div></div>';
    }
    // Upload check
    if (ul >= 5) {
      diagHtml += '<div class="list-row"><div class="list-ic" style="background:var(--green-light);color:var(--green-mid)"><svg class="ic"><use href="#i-check"/></svg></div><div class="list-t"><div class="list-tt">Upload speed</div><div class="list-ts">Good for video calls and file sharing</div></div></div>';
    } else if (ul > 0) {
      diagHtml += '<div class="list-row"><div class="list-ic" style="background:var(--amber-light);color:var(--amber-dark)"><svg class="ic"><use href="#i-warn"/></svg></div><div class="list-t"><div class="list-tt">Upload speed</div><div class="list-ts">Video call quality may be reduced</div></div></div>';
    }
    var diagEl = document.getElementById('speed-diagnosis');
    if (diagEl) diagEl.innerHTML = diagHtml;
  },

  shareSpeedResult() {
    var r = this._speedResult;
    if (!r) return;
    var text = 'DishNet Speed Test\n'
      + '━━━━━━━━━━━━━━━\n'
      + 'Download: ' + r.dl + ' Mbps\n'
      + 'Upload: ' + r.ul + ' Mbps\n'
      + 'Latency: ' + r.ping + ' ms\n'
      + '━━━━━━━━━━━━━━━\n'
      + (window._siteLocation || 'DishNet') + '\n'
      + 'Tested: ' + r.time + '\n'
      + 'Powered by DishNet Africa';

    if (navigator.share) {
      navigator.share({ title: 'DishNet Speed Test', text: text }).catch(function(){});
    } else if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(function() { alert('Speed test result copied!'); });
    } else {
      DishNet.openWhatsApp('+211921443002', text);
    }
  },

  _esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/'/g,'&#39;').replace(/"/g,'&quot;'); },

  sendDebugReport() {
    var btn = document.getElementById('debug-send-btn');
    var res = document.getElementById('debug-send-result');
    if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }
    var data = window._debugData ? window._debugData() : { timestamp: new Date().toISOString(), note: 'debugData not available' };

    // Build WhatsApp-friendly text
    var d = data.device || {};
    var n = data.network || {};
    var p = data.portal || {};
    var logs = (data.logs || []).slice(-5);
    var text = '*DishNet Debug Report*\n'
      + '━━━━━━━━━━━━━━━\n'
      + '*👤 User*\n'
      + 'Name: ' + (p.name || '?') + '\n'
      + 'Phone: ' + (p.phone || '?') + '\n'
      + 'Client ID: ' + (p.clientId || '?') + '\n'
      + '\n*📱 Device*\n'
      + (d.userAgent || '?').substring(0, 100) + '\n'
      + 'Screen: ' + (d.screen || '?') + ' · Viewport: ' + (d.viewport || '?') + '\n'
      + 'DPR: ' + (d.dpr || 1) + ' · Cores: ' + (d.cores || '?') + ' · RAM: ' + (d.memory || '?') + ' GB\n'
      + 'App: ' + (d.isNative ? 'Native Android' : 'PWA/Browser') + '\n'
      + (data.battery ? 'Battery: ' + data.battery.level + '% ' + (data.battery.charging ? '⚡' : '🔋') + '\n' : '')
      + '\n*🌐 Network*\n'
      + 'Connection: ' + (n.type || '?') + (n.wifiSsid && n.wifiSsid !== '?' ? ' (WiFi: ' + n.wifiSsid + ')' : '') + '\n'
      + 'Est. speed: ~' + (n.downlink || '?') + ' Mbps (browser estimate)\n'
      + 'Online: ' + (d.online ? 'Yes ✓' : 'No ✗') + '\n'
      + '\n*⚙️ Portal*\n'
      + 'Version: ' + (p.version || '?') + '\n'
      + 'Current view: ' + (p.view || '?') + '\n'
      + 'Token expires: ' + (p.tokenExpires || '?') + '\n'
      + 'Cookie: ' + (p.cookiePresent ? 'Present' : 'Missing') + '\n';
    if (logs.length) {
      text += '\n*📋 Recent logs (' + logs.length + ')*\n';
      logs.forEach(function(l) { text += '[' + (l.type || '?') + '] ' + (l.msg || '').substring(0, 120) + '\n'; });
    }
    text += '\n⏰ ' + (data.timestamp || new Date().toISOString());

    // Also try to save to API (best effort)
    var url = location.pathname + '?page=api&action=app_debug_report';
    fetch(url, {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + DishNet._token, 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    }).catch(function(){});

    // Open WhatsApp with the report
    if (btn) { btn.disabled = false; btn.textContent = 'Send debug report to DishNet'; }
    DishNet.openWhatsApp('+211921443002', text);

    if (res) {
      res.style.display = 'block';
      res.style.background = 'var(--green-light)';
      res.style.color = 'var(--green-mid)';
      res.textContent = 'Report opened in WhatsApp. Also saved to server.';
    }
  },

  _wifiErrorMsg(rawErr, errDiv) {
    if (rawErr.indexOf('TARGETID_NOT_FOUND') !== -1) {
      errDiv.innerHTML = '<b>Router not reachable</b><br>Starlink cannot find your router right now. This usually means the dish is powered off or the router was recently replaced.<br><br>Try again in a few minutes or <span style="text-decoration:underline;cursor:pointer" onclick="DishNet.openWhatsApp(\'+211921443002\',\'WiFi change failed — router not found\')">contact support</span>.';
    } else if (rawErr.indexOf('PERMISSION_DENIED') !== -1) {
      errDiv.innerHTML = '<b>Permission denied</b><br>Our system does not have permission to change this router\'s settings. <span style="text-decoration:underline;cursor:pointer" onclick="DishNet.openWhatsApp(\'+211921443002\',\'WiFi change permission denied\')">Contact support</span>.';
    } else if (rawErr.indexOf('UNAVAILABLE') !== -1 || rawErr.indexOf('DEADLINE_EXCEEDED') !== -1) {
      errDiv.innerHTML = '<b>Router is offline</b><br>Your Starlink router is not responding. Make sure the dish is powered on and connected.';
    } else {
      errDiv.textContent = rawErr;
    }
    
  },

  submitSiteWifi() {
    var ssid = document.getElementById('site-wifi-ssid').value.trim();
    var pass = document.getElementById('site-wifi-pass').value;
    var pass2 = document.getElementById('site-wifi-pass2').value;
    var errDiv = document.getElementById('site-wifi-error');
    var okDiv = document.getElementById('site-wifi-success');
    var btn = document.getElementById('site-wifi-submit');
    // v4.12.22: detect mode — if Advanced is expanded, user is renaming SSID.
    var adv = document.getElementById('site-wifi-ssid-advanced');
    var isRenaming = adv && adv.style.display !== 'none';
    var restoreBtnLabel = isRenaming ? 'Apply changes' : 'Change WiFi password';
    errDiv.style.display = 'none'; okDiv.style.display = 'none';

    if (!ssid) {
      // Hidden input was never filled (cache empty + user didn't expand Advanced).
      errDiv.textContent = 'Tap "Advanced" and enter a network name, or contact support.';
      errDiv.style.display = 'block';
      return;
    }
    if (pass.length < 8) { errDiv.textContent = 'Password must be at least 8 characters'; errDiv.style.display = 'block'; return; }
    if (pass !== pass2) { errDiv.textContent = 'Passwords do not match'; errDiv.style.display = 'block'; return; }

    btn.disabled = true; btn.textContent = 'Changing...';
    // Use the site's router_id if available, otherwise fall back to legacy
    var routerId = window._siteRouterId || '<?= pe($portalRouter['router_id_full'] ?? '') ?>';
    var endpoint = location.href.split('/_plugins/')[0] + '/_plugins/dishnet-data-report/public.php?action=dr_wifi_change_password';

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        router_id: routerId,
        ssid: ssid, password: pass,
        ssid_5ghz: ssid, password_5ghz: pass,
        auth_type: 'wpa2'
      })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      btn.disabled = false;
      btn.innerHTML = '<svg class="ic" style="width:16px;height:16px"><use href="#i-lock"/></svg> ' + restoreBtnLabel;
      if (data.ok) {
        var successIntro = isRenaming
          ? '<b>WiFi changed!</b> SSID: <b>' + ssid + '</b><br>Verifying with Starlink cloud...'
          : '<b>Password changed!</b> Verifying with Starlink cloud...';
        okDiv.innerHTML = successIntro;
        okDiv.style.display = 'block';

        // Verify: fetch live config from cloud after 3s
        setTimeout(function() {
          var verifyUrl = location.href.split('/_plugins/')[0] + '/_plugins/dishnet-data-report/public.php?action=dr_wifi_get_config&router_id=' + encodeURIComponent(routerId);
          fetch(verifyUrl)
            .then(function(r) { return r.json(); })
            .then(function(vd) {
              if (vd.ok && vd.networks && vd.networks.length) {
                var cloudSsid = vd.networks[0].ssid || '';
                if (cloudSsid === ssid) {
                  okDiv.innerHTML = isRenaming
                    ? '✅ <b>Verified!</b> Starlink confirms:<br>SSID: <b>' + cloudSsid + '</b><br>All devices will need to reconnect with the new password.'
                    : '✅ <b>Verified!</b> Password updated on <b>' + cloudSsid + '</b>.<br>Your devices will need the new password.';
                } else {
                  okDiv.innerHTML = '⚠ <b>Change sent</b> — cloud still shows: <b>' + cloudSsid + '</b><br>Router may take up to 30 seconds. Try refreshing.';
                  okDiv.style.background = 'var(--amber-light)';
                  okDiv.style.color = 'var(--amber-dark)';
                }
              } else {
                okDiv.innerHTML = isRenaming
                  ? '✅ <b>WiFi change sent!</b> SSID: <b>' + ssid + '</b><br>All devices will need to reconnect with the new password.'
                  : '✅ <b>Password change sent!</b> Your devices will need the new password.';
              }
            })
            .catch(function() {
              okDiv.innerHTML = isRenaming
                ? '✅ <b>WiFi change sent!</b> SSID: <b>' + ssid + '</b><br>All devices will need to reconnect with the new password.'
                : '✅ <b>Password change sent!</b> Your devices will need the new password.';
            });
        }, 3000);

        // Save credentials to cache for pre-fill next time
        var saveUrl = location.pathname + '?page=api&action=app_wifi_save';
        fetch(saveUrl, {
          method: 'POST',
          headers: { 'Authorization': 'Bearer ' + DishNet._token, 'Content-Type': 'application/json' },
          body: JSON.stringify({
            router_id: routerId,
            kit: new URLSearchParams(location.search).get('kit') || '',
            ssid: ssid, password: pass,
            ssid_5ghz: ssid, password_5ghz: pass,
            source: 'change'
          })
        }).catch(function(){});

        // Update the current WiFi card if visible
        var cachedSsid = document.getElementById('wifi-cached-ssid');
        var cachedPass = document.getElementById('wifi-cached-pass');
        var cachedCard = document.getElementById('wifi-current-card');
        var cachedWhen = document.getElementById('wifi-cached-when');
        if (cachedSsid) cachedSsid.textContent = ssid;
        if (cachedPass) { cachedPass.dataset.pw = pass; cachedPass.textContent = '••••••••'; delete cachedPass.dataset.show; }
        if (cachedCard) cachedCard.style.display = 'block';
        if (cachedWhen) cachedWhen.textContent = 'Last changed just now';

        // v4.12.22: Also update the read-only display card (so the customer
        // sees the new SSID immediately if they just renamed the network).
        var dispVal = document.getElementById('site-wifi-name-display-value');
        if (dispVal) dispVal.textContent = ssid;

        // Clear only passwords (keep SSID so display stays accurate)
        document.getElementById('site-wifi-pass').value = '';
        document.getElementById('site-wifi-pass2').value = '';
      } else {
        DishNet._wifiErrorMsg(data.error || 'Failed to change WiFi. Try again or contact support.', errDiv);

      }
    })
    .catch(function(err) {
      btn.disabled = false;
      btn.innerHTML = '<svg class="ic" style="width:16px;height:16px"><use href="#i-lock"/></svg> ' + restoreBtnLabel;
      errDiv.textContent = 'Network error: ' + (err.message || 'Try again');

    });
  },
  openDataReport(kitNumber) {
    // Open the Data Report plugin's client view with this customer's CRM ID.
    // v4.12.29: also pass the JWT token so Data Report can (a) authenticate
    // the customer and (b) construct a working "Back to Portal" return URL
    // that carries the token back. Without this, clicking Back to Portal in
    // Data Report landed the user on the Hybrid login page because no auth
    // context was available in the return URL. The token is sensitive but
    // it's already in the URL for WebView scenarios (see this.go() below),
    // so the exposure profile is unchanged.
    var clientId = <?= $portalCustomerId ?>;
    var url = location.href.split('/_plugins/')[0] + '/_plugins/dishnet-data-report/public.php?clientId=' + clientId;
    if (kitNumber) url += '&kit=' + encodeURIComponent(kitNumber);
    if (this._token) url += '&token=' + encodeURIComponent(this._token);
    location.href = url;
  },
  // v4.12.21: toggle the editable SSID field. Called when user taps the
  // "Advanced (change network name)" link. Accepts optional forceShow to
  // auto-expand when SSID cache is empty.
  toggleWifiAdvanced(forceShow) {
    var adv = document.getElementById('wifi-ssid-advanced');
    var display = document.getElementById('wifi-name-display');
    var arrow = document.getElementById('wifi-advanced-arrow');
    var label = document.getElementById('wifi-advanced-label');
    var btn = document.getElementById('wifi-submit');
    if (!adv) return;
    var isOpen = forceShow === true ? true : (adv.style.display === 'none');
    if (isOpen) {
      adv.style.display = 'block';
      if (display) display.style.display = 'none';
      if (arrow) arrow.textContent = '▾';
      if (label) label.textContent = 'Hide advanced';
      if (btn) btn.childNodes[btn.childNodes.length - 1].nodeValue = ' Apply changes';
    } else {
      adv.style.display = 'none';
      if (display) display.style.display = 'flex';
      if (arrow) arrow.textContent = '▸';
      if (label) label.textContent = 'Advanced (change network name)';
      if (btn) btn.childNodes[btn.childNodes.length - 1].nodeValue = ' Change password';
    }
  },
  // v4.12.22: twin of toggleWifiAdvanced for the site-detail Change WiFi form.
  // Elements differ by 'site-' prefix. Button wording + hint text swap the same
  // way. Separate function instead of sharing: forms use different DishNet
  // submit handlers (submitSiteWifi vs submitWifiChange) and different button
  // IDs, and the site form also has an adjacent "How it works" sentence that
  // updates based on mode.
  toggleSiteWifiAdvanced(forceShow) {
    var adv = document.getElementById('site-wifi-ssid-advanced');
    var display = document.getElementById('site-wifi-name-display');
    var arrow = document.getElementById('site-wifi-advanced-arrow');
    var label = document.getElementById('site-wifi-advanced-label');
    var btn = document.getElementById('site-wifi-submit');
    var howText = document.getElementById('site-wifi-howitworks');
    if (!adv) return;
    var isOpen = forceShow === true ? true : (adv.style.display === 'none');
    if (isOpen) {
      adv.style.display = 'block';
      if (display) display.style.display = 'none';
      if (arrow) arrow.textContent = '▾';
      if (label) label.textContent = 'Hide advanced';
      if (btn) btn.childNodes[btn.childNodes.length - 1].nodeValue = ' Apply changes';
      if (howText) howText.textContent = 'The new SSID and password are sent to your Starlink router via the cloud. Both 2.4 GHz and 5 GHz bands will be updated. All connected devices will need to reconnect.';
    } else {
      adv.style.display = 'none';
      if (display) display.style.display = 'flex';
      if (arrow) arrow.textContent = '▸';
      if (label) label.textContent = 'Advanced (change network name)';
      if (btn) btn.childNodes[btn.childNodes.length - 1].nodeValue = ' Change WiFi password';
      if (howText) howText.textContent = 'The new password is sent to your Starlink router via the cloud. Both 2.4 GHz and 5 GHz bands will be updated. Your devices will need the new password to reconnect.';
    }
  },
  submitWifiChange() {
    var ssid = document.getElementById('wifi-ssid').value.trim();
    var pass = document.getElementById('wifi-pass').value;
    var pass2 = document.getElementById('wifi-pass2').value;
    var errDiv = document.getElementById('wifi-error');
    var okDiv = document.getElementById('wifi-success');
    var btn = document.getElementById('wifi-submit');
    errDiv.style.display = 'none';
    okDiv.style.display = 'none';

    // v4.12.21: SSID is always pre-filled from cache in simple mode. If it's
    // still empty, the customer has expanded Advanced but not entered a name —
    // or the cache was empty to begin with.
    if (!ssid) { errDiv.textContent = 'Tap "Advanced" and enter a network name, or contact support.'; errDiv.style.display = 'block'; return; }
    if (pass.length < 8) { errDiv.textContent = 'Password must be at least 8 characters'; errDiv.style.display = 'block'; return; }
    if (pass !== pass2) { errDiv.textContent = 'Passwords do not match'; errDiv.style.display = 'block'; return; }

    // Biometric confirmation before sending
    if (window.Android && window.Android.confirmBiometricForWifi) {
      window.Android.confirmBiometricForWifi(ssid, pass);
      return;
    }

    // Fallback: proceed directly
    DishNet._doWifiChange(ssid, pass);
  },
  _doWifiChange(ssid, pass) {
    var errDiv = document.getElementById('wifi-error');
    var okDiv = document.getElementById('wifi-success');
    var btn = document.getElementById('wifi-submit');
    btn.disabled = true;
    btn.textContent = 'Changing...';

    var routerId = '<?= pe($wifiRouter['router_id_full'] ?? $portalRouter['router_id_full'] ?? '') ?>';
    var endpoint = location.href.split('/_plugins/')[0] + '/_plugins/dishnet-data-report/public.php?action=dr_wifi_change_password';

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        router_id: routerId,
        ssid: ssid,
        password: pass,
        ssid_5ghz: ssid,
        password_5ghz: pass,
        auth_type: 'wpa2'
      })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      btn.disabled = false;
      // v4.12.21: restore correct button label based on current mode
      var adv = document.getElementById('wifi-ssid-advanced');
      var isAdvanced = adv && adv.style.display === 'block';
      btn.innerHTML = '<svg class="ic" style="width:16px;height:16px"><use href="#i-lock"/></svg> ' + (isAdvanced ? 'Apply changes' : 'Change password');
      if (data.ok) {
        okDiv.innerHTML = '✅ <b>Password changed!</b> Verifying with Starlink cloud…';
        okDiv.style.display = 'block';
        // v4.12.21: clear only password fields; keep SSID so the display card
        // stays accurate. Update the read-only display too in case name changed.
        document.getElementById('wifi-pass').value = '';
        document.getElementById('wifi-pass2').value = '';
        var displayEl = document.getElementById('wifi-name-display-value');
        if (displayEl) displayEl.textContent = ssid;

        // Verify: fetch live config from cloud after 3 seconds (give router time to apply)
        setTimeout(function() {
          var verifyUrl = location.href.split('/_plugins/')[0] + '/_plugins/dishnet-data-report/public.php?action=dr_wifi_get_config&router_id=' + encodeURIComponent(routerId);
          fetch(verifyUrl)
            .then(function(r) { return r.json(); })
            .then(function(vd) {
              if (vd.ok && vd.networks && vd.networks.length) {
                var cloudSsid = vd.networks[0].ssid || '';
                var cloudPass = vd.networks[0].password || '';
                if (cloudSsid === ssid) {
                  okDiv.innerHTML = '✅ <b>Confirmed!</b> Your devices will need the new password to reconnect.<br><span style="font-size:11px">Network: <b>' + cloudSsid + '</b></span>';
                } else {
                  okDiv.innerHTML = '⚠ <b>Change sent</b> but cloud still shows old name: <b>' + cloudSsid + '</b><br>The router may take up to 30 seconds. Try refreshing.';
                  okDiv.style.background = 'var(--amber-light)';
                  okDiv.style.color = 'var(--amber-dark)';
                }
              } else {
                okDiv.innerHTML = '✅ <b>Change sent!</b> Reconnect your devices with the new password.';
              }
            })
            .catch(function() {
              okDiv.innerHTML = '✅ <b>Change sent!</b> Reconnect your devices with the new password.';
            });
        }, 3000);
      } else {
        DishNet._wifiErrorMsg(data.error || 'Failed to change WiFi. Try again or contact support.', errDiv);
        
      }
    })
    .catch(function(err) {
      btn.disabled = false;
      // v4.12.21: restore correct button label based on current mode
      var adv = document.getElementById('wifi-ssid-advanced');
      var isAdvanced = adv && adv.style.display === 'block';
      btn.innerHTML = '<svg class="ic" style="width:16px;height:16px"><use href="#i-lock"/></svg> ' + (isAdvanced ? 'Apply changes' : 'Change password');
      errDiv.textContent = 'Network error: ' + (err.message || 'Try again');
      
    });
  },
  confirmLogout() {
    if (window.Android && window.Android.confirmLogout) {
      // Native will prompt biometric + logout if confirmed
      window.Android.confirmLogout();
    } else {
      if (confirm('Log out of DishNet?')) {
        // 1. Call logout API to blacklist the JWT token
        var token = this._token;
        if (token) {
          var u = new URL(location.href);
          var logoutUrl = u.origin + u.pathname + '?page=api&action=app_logout';
          fetch(logoutUrl, {
            method: 'POST',
            headers: {
              'Authorization': 'Bearer ' + token,
              'Content-Type': 'application/json'
            },
            body: '{}'
          }).catch(function() { /* best-effort */ });
        }

        // 2. Clear the dn_customer_token cookie
        document.cookie = 'dn_customer_token=;path=/;max-age=0;SameSite=Lax';
        document.cookie = 'dn_customer_token=;path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT;SameSite=Lax';

        // 3. Redirect to customer login page
        var loginUrl = location.pathname + '?page=customer_login';
        location.href = loginUrl;
      }
    }
  },
  toggleBiometric(enabled) {
    if (window.Android && window.Android.setBiometricRequired) {
      return window.Android.setBiometricRequired(!!enabled);
    }
    return false;
  },
  // Called BY native TO the web side to tell us current bio state
  _setBioState(enabled, available, statusText) {
    const tog = document.getElementById('bio-toggle');
    const status = document.getElementById('bio-status');
    if (tog) {
      tog.classList.toggle('on', !!enabled);
      tog.classList.toggle('off', !enabled);
    }
    if (status) status.textContent = statusText || (enabled ? 'On' : 'Off');
  }
};

// v4.12.20 — Close PDF overlay on Android hardware back button or Esc key.
// Android WebView emits popstate when the user taps back; we push a state when
// the overlay opens so that back becomes "close PDF" without leaving the page.
(function() {
  function pdfOpen() {
    var o = document.getElementById('dn-pdf-overlay');
    return o && o.style.display === 'flex';
  }
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && pdfOpen()) DishNet.closePdf();
  });
  // Push a history entry when the overlay opens so back button closes it
  var origView = DishNet.viewPdf;
  DishNet.viewPdf = function(url, title) {
    origView.call(DishNet, url, title);
    try { history.pushState({pdfOverlay: true}, '', location.href); } catch (e) {}
  };
  var origClose = DishNet.closePdf;
  DishNet.closePdf = function() {
    origClose.call(DishNet);
    try {
      if (history.state && history.state.pdfOverlay) history.back();
    } catch (e) {}
  };
  window.addEventListener('popstate', function(e) {
    if (pdfOpen()) {
      // User pressed back while viewing PDF — close overlay without another history move
      var overlay = document.getElementById('dn-pdf-overlay');
      if (overlay) overlay.style.display = 'none';
      var frame = document.getElementById('dn-pdf-frame');
      if (frame) frame.src = 'about:blank';
      if (DishNet._pdfBlobUrl) {
        try { URL.revokeObjectURL(DishNet._pdfBlobUrl); } catch (ex) {}
        DishNet._pdfBlobUrl = null;
      }
    }
  });
})();
document.addEventListener('click', function(e) {
  const tog = e.target.closest('#bio-toggle, #bio-toggle-row');
  if (!tog) return;
  const sw = document.getElementById('bio-toggle');
  if (!sw) return;
  const willBeOn = !sw.classList.contains('on');
  const ok = DishNet.toggleBiometric(willBeOn);
  if (ok !== false) {
    sw.classList.toggle('on', willBeOn);
    sw.classList.toggle('off', !willBeOn);
    const status = document.getElementById('bio-status');
    if (status) status.textContent = willBeOn ? 'On — fingerprint or PIN required' : 'Off — tap to enable';
  }
});

// Ask native for current biometric state on load (Account only)
if (window.Android && window.Android.getBiometricState) {
  try {
    const s = window.Android.getBiometricState();
    if (typeof s === 'string') {
      const parsed = JSON.parse(s);
      DishNet._setBioState(parsed.enabled, parsed.available, parsed.status);
    }
  } catch (e) { /* ignore */ }
}
</script>

<?php
// Bottom tab bar — only for PWA/browser, hidden in native Android WebView.
// v4.21.56: pure fiber-only customers swap "Sites" for "Usage" since they
// have no Starlink fleet. Active-tab routing also updated for the new view.
$navTabs = [
    'home'     => ['icon' => 'i-speed',   'label' => 'Home'],
    'sites'    => ['icon' => 'i-wifi',    'label' => 'Sites'],
    'invoices' => ['icon' => 'i-receipt', 'label' => 'Invoices'],
    'support'  => ['icon' => 'i-support', 'label' => 'Support'],
    'account'  => ['icon' => 'i-user',   'label' => 'Account'],
];
if (!$portalIsHybrid && $portalServiceType === 'fiber') {
    // Replace the Sites tab with Usage for pure fiber-only customers.
    // Keys preserve order via PHP array assignment quirks — rebuild fresh.
    $navTabs = [
        'home'        => ['icon' => 'i-speed',   'label' => 'Home'],
        'fiber_usage' => ['icon' => 'i-wifi',    'label' => 'Usage'],
        'invoices'    => ['icon' => 'i-receipt', 'label' => 'Invoices'],
        'support'     => ['icon' => 'i-support', 'label' => 'Support'],
        'account'     => ['icon' => 'i-user',   'label' => 'Account'],
    ];
}
// Determine active tab from current view
$activeTab = $view;
if (in_array($view, ['site_detail', 'wifi_change', 'usage', 'speed_test', 'wifi_site'], true)) $activeTab = 'sites';
if ($view === 'fiber_usage') $activeTab = 'fiber_usage';
if ($view === 'invoice_detail') $activeTab = 'invoices';
if ($view === 'service_status') $activeTab = 'support';
?>
<nav class="btm-nav" id="btmNav">
  <?php foreach ($navTabs as $tabView => $tab): ?>
  <button class="btm-tab<?= $activeTab === $tabView ? ' active' : '' ?>" onclick="DishNet.<?= in_array($tabView, ['sites', 'wifi_change', 'fiber_usage']) ? 'goInternal' : 'go' ?>('<?= $tabView ?>')">
    <svg><use href="#<?= $tab['icon'] ?>"/></svg>
    <span class="btm-tab-lbl"><?= $tab['label'] ?></span>
  </button>
  <?php endforeach; ?>
</nav>

<script>
// Hide bottom nav in native Android WebView (native app has its own bottom tabs)
if (window.Android) {
  var nav = document.getElementById('btmNav');
  if (nav) { nav.style.display = 'none'; document.body.style.paddingBottom = '24px'; }
}
</script>
</body>
</html>
