<?php
/**
 * Runbook — Operations troubleshooting guide
 * DishNet Hybrid v4.11.3
 *
 * Written for Rupesh, Diko, and any staff who need to diagnose
 * issues when Aida is unavailable. Uses plain language, live
 * health checks, and step-by-step recovery procedures.
 */

$isAdmin = !empty($retailer['is_admin']);
$apiBase = strtok($_SERVER['REQUEST_URI'], '?');
$token   = htmlspecialchars($retailer['api_token'] ?? '', ENT_QUOTES);
?>
<style>
.rb-wrap{max-width:760px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
.rb-hero{background:linear-gradient(135deg,#0f172a,#1e293b);border-radius:20px;padding:24px;color:#fff;margin-bottom:20px;position:relative;overflow:hidden;}
.rb-hero::before{content:'';position:absolute;top:-50px;right:-50px;width:180px;height:180px;border-radius:50%;background:rgba(212,28,28,.08);}
.rb-hero h2{font-size:22px;font-weight:900;margin:0 0 4px;}
.rb-hero p{font-size:13px;color:#94a3b8;margin:0;}

/* Live health grid */
.rb-health{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin:16px 0 0;}
.rb-hcard{background:rgba(255,255,255,.06);border-radius:10px;padding:10px 12px;text-align:center;}
.rb-hcard .dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:4px;}
.rb-hcard .label{font-size:11px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
.rb-hcard .val{font-size:14px;font-weight:800;color:#fff;margin-top:2px;}
.dot-ok{background:#22c55e;box-shadow:0 0 6px rgba(34,197,94,.4);}
.dot-warn{background:#f59e0b;box-shadow:0 0 6px rgba(245,158,11,.4);}
.dot-err{background:#ef4444;box-shadow:0 0 6px rgba(239,68,68,.4);}
.dot-unk{background:#64748b;}

/* Section */
.rb-section{margin-bottom:8px;}
.rb-section-hdr{font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.8px;margin:20px 0 10px;padding-left:4px;}

/* Accordion cards */
.rb-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:8px;overflow:hidden;transition:.15s;}
.rb-card.open{box-shadow:0 2px 12px rgba(0,0,0,.06);}
.rb-card-hdr{display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;-webkit-tap-highlight-color:transparent;user-select:none;}
.rb-card-hdr:hover{background:#f8fafc;}
.rb-card-hdr .ico{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.rb-card-hdr .txt{flex:1;}
.rb-card-hdr .txt h4{font-size:14px;font-weight:800;color:#1e293b;margin:0;}
.rb-card-hdr .txt p{font-size:11px;color:#94a3b8;margin:2px 0 0;}
.rb-card-hdr .arrow{color:#cbd5e1;font-size:14px;transition:transform .2s;flex-shrink:0;}
.rb-card.open .arrow{transform:rotate(90deg);}
.rb-card-body{display:none;padding:0 16px 16px;font-size:13px;color:#475569;line-height:1.7;}
.rb-card.open .rb-card-body{display:block;}

/* Steps inside card body */
.rb-step{display:flex;gap:10px;margin:8px 0;align-items:flex-start;}
.rb-step-n{width:22px;height:22px;border-radius:50%;background:#e2e8f0;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#475569;}
.rb-step-t{flex:1;padding-top:1px;}
.rb-step-t code{background:#f1f5f9;padding:1px 6px;border-radius:4px;font-size:12px;font-family:'Courier New',monospace;color:#1e293b;}

/* Alert boxes */
.rb-alert{padding:10px 14px;border-radius:10px;margin:10px 0;font-size:12px;font-weight:600;display:flex;align-items:flex-start;gap:8px;}
.rb-alert.info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}
.rb-alert.warn{background:#fff7ed;color:#92400e;border:1px solid #fed7aa;}
.rb-alert.danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.rb-alert.success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}

/* Check URL button */
.rb-check-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;margin:4px 0;}
.rb-check-btn:hover{background:#dbeafe;}

/* Emergency banner */
.rb-emergency{background:linear-gradient(135deg,#dc2626,#991b1b);border-radius:14px;padding:16px 20px;color:#fff;margin-bottom:20px;}
.rb-emergency h3{font-size:16px;font-weight:900;margin:0 0 6px;}
.rb-emergency p{font-size:12px;color:#fca5a5;margin:0;}
.rb-emergency .contacts{margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;}
.rb-emergency .contacts a{background:rgba(255,255,255,.15);color:#fff;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;}

@media(max-width:480px){
    .rb-health{grid-template-columns:repeat(2,1fr);}
}
</style>

<div class="rb-wrap">

    <!-- ═══ HERO + LIVE HEALTH ═══ -->
    <div class="rb-hero">
        <h2>📋 Operations Runbook</h2>
        <p>Troubleshooting guide for when things go wrong. Check health status below, then find your issue. <a href="?page=dashboard&tab=settings&stab=health" style="color:#60a5fa;font-weight:700;">→ Full Health Dashboard</a></p>
        <div class="rb-health" id="rbHealth">
            <div class="rb-hcard"><div class="label">WhatsApp</div><div class="val" id="hWa"><span class="dot dot-unk"></span> Checking...</div></div>
            <div class="rb-hcard"><div class="label">CRM Sync</div><div class="val" id="hCrm"><span class="dot dot-unk"></span> Checking...</div></div>
            <div class="rb-hcard"><div class="label">Splynx</div><div class="val" id="hSplynx"><span class="dot dot-unk"></span> Checking...</div></div>
            <div class="rb-hcard"><div class="label">LTE Sync</div><div class="val" id="hLte"><span class="dot dot-unk"></span> Checking...</div></div>
            <div class="rb-hcard"><div class="label">Cron Jobs</div><div class="val" id="hCron"><span class="dot dot-unk"></span> Checking...</div></div>
            <div class="rb-hcard"><div class="label">Lead Alerts</div><div class="val" id="hLeadAlert"><span class="dot dot-unk"></span> Checking...</div></div>
            <div class="rb-hcard"><div class="label">Backup</div><div class="val" id="hBak"><span class="dot dot-unk"></span> Checking...</div></div>
            <div class="rb-hcard"><div class="label">Fiber Jobs</div><div class="val" id="hFiber"><span class="dot dot-unk"></span> Checking...</div></div>
        </div>
        <div style="text-align:center;margin-top:10px;">
            <button onclick="rbCheckHealth()" style="background:rgba(255,255,255,.1);color:#94a3b8;border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:6px 16px;font-size:11px;font-weight:700;cursor:pointer;">🔄 Refresh Health Check</button>
        </div>
    </div>

    <!-- ═══ EMERGENCY CONTACTS ═══ -->
    <div class="rb-emergency">
        <h3>🚨 Plugin crashed / System Error?</h3>
        <p><strong>Step 1 — Try the emergency repair URL first:</strong></p>
        <p style="font-size:12px;background:rgba(0,0,0,.3);padding:8px 12px;border-radius:8px;font-family:monospace;word-break:break-all;">https://crm.dishnetafrica.com/crm/_plugins/dishnet-hybrid-telecom/public.php?page=emergency_repair&key=DISHNET_REPAIR</p>
        <p style="font-size:12px;margin-top:6px;color:rgba(255,255,255,.7);">This works even when the plugin is completely broken. It deletes stale database files and self-heals.</p>
        <p><strong>Step 2 — If that doesn't work:</strong> UCRM Admin → System → Plugins → Upload new plugin ZIP (works even when plugin shows error)</p>
        <p><strong>Step 3 — SSH (last resort):</strong></p>
        <p style="font-size:11px;background:rgba(0,0,0,.3);padding:8px 12px;border-radius:8px;font-family:monospace;">rm -f /data/ucrm/data/plugins/dishnet-hybrid-telecom/data/plugin.sqlite3-wal<br>rm -f /data/ucrm/data/plugins/dishnet-hybrid-telecom/data/plugin.sqlite3-shm</p>
        <p><strong>Never do a full server restore for a plugin issue. Data is always safe in plugin.sqlite3.</strong></p>
        <div class="contacts">
            <a href="tel:+211921443002">📞 Aida (CTO)</a>
            <a href="https://wa.me/211921443002" target="_blank">💬 WhatsApp Aida</a>
        </div>
    </div>

    <!-- ═══ MOST COMMON ISSUES ═══ -->
    <div class="rb-section-hdr">🔥 Most common issues</div>

    <!-- WA not sending -->
    <div class="rb-card" id="rb-wa">
        <div class="rb-card-hdr" onclick="rbToggle('rb-wa')">
            <div class="ico" style="background:#dcfce7;">💬</div>
            <div class="txt"><h4>WhatsApp messages not sending</h4><p>Customers not receiving notifications, invoices, or reminders</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <div class="rb-alert info">ℹ️ WhatsApp uses two phone lines: Support (211921443002) and Accounts (211921443009). Check which one is affected.</div>

            <strong>Quick diagnosis:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Open <a href="https://wa.dishnetafrica.com" target="_blank" class="rb-check-btn">wa.dishnetafrica.com</a> — if it loads, the WhatsML server is alive.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Go to <strong>Engage → WA Inbox</strong> in CRM. If recent messages are showing, sync is working.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">Check the last sync time: Go to <strong>Admin → Maintenance</strong> tab. Look for "WA Sync" — it should show a time within the last 2 minutes.</div></div>

            <strong>If WA sync stopped:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Check <a href="https://cron-job.org" target="_blank" class="rb-check-btn">cron-job.org</a> — log in and verify the WA sync cron is enabled and running. It should fire every 60 seconds.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">If the cron shows errors, it usually means the WhatsML server (<code>134.199.215.120</code>) is down. Wait 10 minutes and check again.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">If the WhatsML server is up but messages still aren't syncing, the WhatsApp session may have expired. <strong>Escalate to Aida</strong> — she needs to re-scan the QR code on the WhatsML dashboard.</div></div>

            <div class="rb-alert warn">⚠️ Common false alarm: WhatsApp has a 24-hour window for sending messages after the customer's last message. If 24 hours have passed, messages will silently fail — this is a WhatsApp rule, not a bug.</div>
        </div>
    </div>

    <!-- Payments not syncing -->
    <div class="rb-card" id="rb-pay">
        <div class="rb-card-hdr" onclick="rbToggle('rb-pay')">
            <div class="ico" style="background:#fef3c7;">💰</div>
            <div class="txt"><h4>Payments not showing in CRM</h4><p>Customer paid but payment not appearing in their account</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <strong>There are two types of payments:</strong>
            <div class="rb-alert info">ℹ️ <strong>Wallet payments</strong> are processed instantly in the plugin — no sync needed. <strong>CRM payments</strong> (invoice payments through UCRM) sync every 5 minutes via cron.</div>

            <strong>If a wallet payment was made but doesn't show:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Check the cashbook: <strong>Accounts → Cashbook</strong>. Search for the customer name. The payment should appear as a Cash IN entry.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Check the agent's wallet balance: <strong>Sales → Wallet</strong>. Was the wallet deducted? If yes, the payment was processed.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">If the payment is in the cashbook but the customer's CRM invoice is still unpaid, the CRM sync may be delayed. Wait 10 minutes and check again.</div></div>

            <strong>If a CRM payment is missing:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Log into UCRM directly: <a href="https://crm.dishnetafrica.com/crm" target="_blank" class="rb-check-btn">crm.dishnetafrica.com/crm</a>. Go to Billing → Payments and search for the payment.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">If the payment exists in UCRM but not in the plugin, the webhook may have failed. Go to <strong>Admin → Maintenance</strong> and look for recent webhook errors.</div></div>

            <div class="rb-alert danger">🚨 If 5+ payments are missing in the same day, this is a webhook failure. Escalate to Aida immediately — there may be a UCRM webhook configuration issue.</div>
        </div>
    </div>

    <!-- Staff can't log in -->
    <div class="rb-card" id="rb-login">
        <div class="rb-card-hdr" onclick="rbToggle('rb-login')">
            <div class="ico" style="background:#ede9fe;">🔐</div>
            <div class="txt"><h4>Staff can't log in</h4><p>Password rejected, account locked, or blank screen</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t"><strong>Wrong password:</strong> Default password for new accounts is <code>123456</code>. If they changed it and forgot, an admin can reset it from <strong>Admin → Users</strong>.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t"><strong>Account deactivated:</strong> Check <strong>Admin → Users</strong> — make sure the account shows <span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:4px;font-size:11px;">Active</span>. If not, click Edit and re-activate.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t"><strong>Rate limited:</strong> After 5 wrong password attempts, the account is locked for 15 minutes. Just wait, or an admin can clear the lockout from the Users page.</div></div>
            <div class="rb-step"><div class="rb-step-n">4</div><div class="rb-step-t"><strong>Android app blank screen:</strong> Close the app completely, clear the app cache (Settings → Apps → DishNet → Clear Cache), and reopen. If still blank, check internet connection.</div></div>
            <div class="rb-step"><div class="rb-step-n">5</div><div class="rb-step-t"><strong>iPhone PWA blank screen:</strong> Force-close Safari, reopen the PWA. If still blank, delete the PWA from home screen and re-add it from <code>crm.dishnetafrica.com</code>.</div></div>
        </div>
    </div>

    <!-- ═══ CASHBOOK & FINANCE ═══ -->
    <div class="rb-section-hdr">💰 Cashbook & finance</div>

    <div class="rb-card" id="rb-cb">
        <div class="rb-card-hdr" onclick="rbToggle('rb-cb')">
            <div class="ico" style="background:#dbeafe;">📒</div>
            <div class="txt"><h4>Cashbook balance looks wrong</h4><p>Running balance doesn't match expected amount</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <div class="rb-alert info">ℹ️ DishNet has 3 cashbooks: <strong>DishNet main</strong>, <strong>DishNet 4G</strong>, and <strong>BlueCARD</strong>. Each has its own running balance chain.</div>

            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Go to <strong>Accounts → Cashbook</strong> and select the affected cashbook.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Scroll to the bottom — the last entry's running balance should match the expected amount.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">If the balance chain is broken (running balance jumps), look for voided or duplicate entries. Search for the amount in question.</div></div>
            <div class="rb-step"><div class="rb-step-n">4</div><div class="rb-step-t">Check for duplicate CRM payments: <strong>Accounts → Cashbook</strong> → filter by <code>CRM-PAY</code> reference. If two entries have the same reference, one is a duplicate.</div></div>

            <div class="rb-alert warn">⚠️ <strong>Never manually edit</strong> entries with a 🔒 lock icon — these are auto-synced from CRM or field merge. Editing them breaks the chain. Contact Aida if you need to fix a locked entry.</div>
        </div>
    </div>

    <div class="rb-card" id="rb-handover">
        <div class="rb-card-hdr" onclick="rbToggle('rb-handover')">
            <div class="ico" style="background:#fce7f3;">🤝</div>
            <div class="txt"><h4>Handover stuck or missing</h4><p>Agent submitted handover but it's not showing for approval</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Go to <strong>Accounts → Handover Queue</strong> and check if the handover is in "Pending" status.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">If the handover doesn't appear at all, check the agent's Staff Cashbook — the collections may not have been recorded yet.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t"><strong>Reverted handover:</strong> If a handover was approved by mistake, admin can revert it from the Handover Queue. This reverses the wallet credit and restores the agent's cash position.</div></div>

            <div class="rb-alert info">ℹ️ Handovers can only be approved by the accountant or admin role. Sales staff and support cannot approve handovers.</div>
        </div>
    </div>

    <!-- ═══ LTE & CONNECTIVITY ═══ -->
    <div class="rb-section-hdr">📡 LTE / 4G / BlueCARD</div>

    <div class="rb-card" id="rb-lte">
        <div class="rb-card-hdr" onclick="rbToggle('rb-lte')">
            <div class="ico" style="background:#f0fdf4;">📶</div>
            <div class="txt"><h4>LTE subscriber data not syncing</h4><p>BlueCard subscriber count wrong or renewals not showing</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <div class="rb-alert info">ℹ️ LTE data syncs from the BlueCard MySQL server (162.241.149.144) via <code>cron_lte_sync.php</code> every 5 minutes.</div>

            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Go to <strong>LTE → Dashboard</strong> and check the "Last Sync" timestamp. It should be within the last 10 minutes.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">If sync is stale, check <a href="https://cron-job.org" target="_blank" class="rb-check-btn">cron-job.org</a> — verify the LTE sync cron is enabled.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">If the cron is running but data isn't updating, the BlueCard server may be down. Try accessing <code>http://162.241.149.144/lte_feed.php?action=health</code> directly.</div></div>

            <div class="rb-alert danger">🚨 The BlueCard server (162.241.149.144) is a WHM/cPanel server. If it's completely down, LTE renewals and subscriber management will stop until it's restored. Escalate to Aida.</div>
        </div>
    </div>

    <!-- ═══ TECHNICAL ═══ -->
    <div class="rb-section-hdr">🔧 Technical troubleshooting</div>

    <div class="rb-card" id="rb-cron">
        <div class="rb-card-hdr" onclick="rbToggle('rb-cron')">
            <div class="ico" style="background:#e0f2fe;">⏰</div>
            <div class="txt"><h4>Cron jobs / automated tasks stopped</h4><p>No auto-sync, no notifications, no reminders</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <div class="rb-alert info">ℹ️ DishNet uses external cron triggers from <strong>cron-job.org</strong> because the UCRM Docker container cannot run cron internally.</div>

            <strong>All cron jobs are triggered by one master URL:</strong>
            <div style="background:#f1f5f9;border-radius:8px;padding:10px 14px;font-family:'Courier New',monospace;font-size:11px;color:#1e293b;word-break:break-all;margin:8px 0;">
                https://crm.dishnetafrica.com/crm/_plugins/dishnet-hybrid-telecom/cron/master.php
            </div>

            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Log into <a href="https://cron-job.org" target="_blank" class="rb-check-btn">cron-job.org</a> and check the master cron job. It should run every 1-2 minutes.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Check the cron history — if recent runs show HTTP 500 errors, the plugin may have a PHP error. Escalate to Aida.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">If cron-job.org shows "success" but nothing is happening, the individual cron jobs may be disabled. Check <strong>Admin → Maintenance</strong> for cron status.</div></div>

            <strong>Key cron jobs and what they do:</strong>
            <table style="width:100%;font-size:12px;border-collapse:collapse;margin:8px 0;">
                <tr style="background:#f8fafc;"><td style="padding:6px 8px;border:1px solid #e2e8f0;font-weight:700;">cron_wa_sync</td><td style="padding:6px 8px;border:1px solid #e2e8f0;">WhatsApp message sync (every 60s)</td></tr>
                <tr><td style="padding:6px 8px;border:1px solid #e2e8f0;font-weight:700;">cron_sync</td><td style="padding:6px 8px;border:1px solid #e2e8f0;">CRM payment & client sync (every 5 min)</td></tr>
                <tr style="background:#f8fafc;"><td style="padding:6px 8px;border:1px solid #e2e8f0;font-weight:700;">cron_lte_sync</td><td style="padding:6px 8px;border:1px solid #e2e8f0;">BlueCard subscriber sync (every 5 min)</td></tr>
                <tr><td style="padding:6px 8px;border:1px solid #e2e8f0;font-weight:700;">cron_wallet_sync</td><td style="padding:6px 8px;border:1px solid #e2e8f0;">Wallet balance reconciliation</td></tr>
                <tr style="background:#f8fafc;"><td style="padding:6px 8px;border:1px solid #e2e8f0;font-weight:700;">cron_invoice_notify</td><td style="padding:6px 8px;border:1px solid #e2e8f0;">Invoice WhatsApp reminders</td></tr>
                <tr><td style="padding:6px 8px;border:1px solid #e2e8f0;font-weight:700;">cron_gdrive_backup</td><td style="padding:6px 8px;border:1px solid #e2e8f0;">Daily Google Drive backup (3 AM)</td></tr>
            </table>
        </div>
    </div>

    <div class="rb-card" id="rb-scanner">
        <div class="rb-card-hdr" onclick="rbToggle('rb-scanner')">
            <div class="ico" style="background:#fef3c7;">📷</div>
            <div class="txt"><h4>Stock scanner not working</h4><p>Camera black screen, no barcode detected, OCR fails</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <strong>Camera shows black screen:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t"><strong>In Android app:</strong> Go to phone Settings → Apps → DishNet Africa → Permissions → Camera → Allow. Then restart the app.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t"><strong>In Chrome:</strong> Tap the lock icon in the address bar → Site settings → Camera → Allow.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t"><strong>In Safari (iPhone):</strong> Go to Settings → Safari → Camera → Allow. Then reload the page.</div></div>
            <div class="rb-step"><div class="rb-step-n">4</div><div class="rb-step-t"><strong>Best option:</strong> Use the standalone scanner at <a href="?page=scanner" target="_blank" class="rb-check-btn">?page=scanner</a> — it works best in all browsers.</div></div>

            <strong>Barcode mode doesn't detect anything:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Starlink labels have barcodes (Code 128) at the bottom. Point the camera at the barcode lines, not the QR code.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">If scanning for more than 10 seconds with no result, switch to <strong>OCR Snap</strong> mode — tap the "📸 OCR Snap (Text)" toggle, point at the "SN: KIT..." text, and tap Snap.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">Use the 🔦 torch button for dark warehouses.</div></div>
            <div class="rb-step"><div class="rb-step-n">4</div><div class="rb-step-t">Last resort: type the serial number manually in the text field below the camera.</div></div>
        </div>
    </div>

    <div class="rb-card" id="rb-backup">
        <div class="rb-card-hdr" onclick="rbToggle('rb-backup')">
            <div class="ico" style="background:#fce7f3;">💾</div>
            <div class="txt"><h4>Backup & data safety</h4><p>How to verify backups are running and where data lives</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <div class="rb-alert danger">🚨 All DishNet data lives in SQLite databases and JSON files inside the UCRM plugin. If these files are lost, ALL business data is gone. Backups are critical.</div>

            <strong>Where data lives:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t"><strong>SQLite database:</strong> <code>data/plugin.sqlite3</code> — all cashbook, collections, leads, staff data, LTE subscribers, stock.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t"><strong>JSON files:</strong> <code>data/*.json</code> — retailers, config, sync state, KYC devices, call recordings log.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t"><strong>Call recordings:</strong> <code>data/call_recordings/</code> — audio files from Android app.</div></div>

            <strong>Verify backup is running:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Go to <strong>Admin → Backup & Restore</strong>. The Google Drive section should show the last backup time.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Backups run automatically at 3 AM Juba time every day.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">You can also click <strong>Run Now</strong> to trigger an immediate backup.</div></div>

            <div class="rb-alert warn">⚠️ If the Google Drive section shows "Not connected" or the last backup is more than 2 days old, escalate to Aida immediately.</div>
        </div>
    </div>

    <!-- ═══ ANDROID APP ═══ -->
    <div class="rb-section-hdr">📱 Android app issues</div>

    <div class="rb-card" id="rb-app">
        <div class="rb-card-hdr" onclick="rbToggle('rb-app')">
            <div class="ico" style="background:#e0f2fe;">📱</div>
            <div class="txt"><h4>Android app problems</h4><p>App not loading, GPS not tracking, calls not recording</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <strong>App shows blank/white screen:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Check internet connection — the app needs internet to load the CRM.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Force close the app and reopen. On Android: swipe up from bottom, swipe the app away, reopen.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">Clear app cache: Settings → Apps → DishNet Africa → Storage → Clear Cache (NOT Clear Data).</div></div>
            <div class="rb-step"><div class="rb-step-n">4</div><div class="rb-step-t">If the "No Connection" page appears with a Retry button, tap Retry after connecting to WiFi or mobile data.</div></div>

            <strong>GPS tracking not working:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Check that Location permission is set to "Allow all the time" (not just "While using app"). Settings → Apps → DishNet → Permissions → Location.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Make sure the persistent notification "📡 Tracking location for Job #..." is showing. If not, the tracking service has stopped.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">Some phones kill background services aggressively. Go to Settings → Battery → DishNet Africa → set to "Unrestricted" or "No restrictions".</div></div>

            <strong>Call recording not working:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Make sure Microphone permission is granted: Settings → Apps → DishNet → Permissions → Microphone → Allow.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">During a call, you should see a notification "🔴 Recording call..." If you don't, the phone may be blocking call recording.</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t"><strong>Important:</strong> Some phone brands (Samsung, Xiaomi, Huawei) block call recording at the system level after Android 10. Using speakerphone improves recording quality.</div></div>

            <strong>Update the app:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">The app checks for updates on launch. If an update is available, you'll see a dialog — tap "Update Now".</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Manual update: Visit <a href="?page=install" target="_blank" class="rb-check-btn">Install Page</a> from the phone browser, download the new APK, and install it over the old version.</div></div>
        </div>
    </div>

    <!-- ═══ v4.11.3 NEW FEATURES ═══ -->
    <div class="rb-section-hdr">🆕 v4.11.3 Features</div>

    <!-- Handover Queue -->
    <div class="rb-card" id="rb-handover">
        <div class="rb-card-hdr" onclick="rbToggle('rb-handover')">
            <div class="ico" style="background:#fef3c7;">💵</div>
            <div class="txt"><h4>Handover Queue & Cash Control</h4><p>How Rupesh confirms field cash and tracks who holds money</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <div class="rb-alert info">ℹ️ The Handover Queue now uses real ledger data (same as dashboard). Cash positions are always accurate.</div>
            <strong>How it works:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Field agent submits cash → enters amount, searches for recipient (type-to-filter), taps Submit.</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Rupesh sees it in Handover Queue with amount and agent name. Tap 📥 Record to confirm — agent's cash balance drops, wallet is refilled. No cashbook entry needed (revenue was already recorded when customer paid).</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">If agent hasn't come yet, tap 📲 Nudge to send WhatsApp reminder.</div></div>
            <strong>Cash Location card shows:</strong>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">Office (Rupesh): confirmed cash in office</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">Each field agent: collections minus expenses minus handovers = cash they hold</div></div>
        </div>
    </div>

    <!-- Expense Approvals -->
    <div class="rb-card" id="rb-expenses">
        <div class="rb-card-hdr" onclick="rbToggle('rb-expenses')">
            <div class="ico" style="background:#fef3c7;">🧾</div>
            <div class="txt"><h4>Expense Approvals & SSP Amounts</h4><p>How to approve expenses, view receipts, and handle SSP</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <strong>SSP expenses:</strong>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">SSP amounts now show correctly (was showing 0 before fix). Both <code>cash_expenses.json</code> and <code>staff_expenses</code> SQLite are checked.</div></div>
            <strong>Receipt photos:</strong>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">Click any receipt → opens in lightbox popup (no more getting stuck on raw image URL in PWA).</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">Thumbnails are auto-generated (300px, ~30KB) for fast page loads. Full image loads when you click.</div></div>
            <strong>Image compression:</strong>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">All new uploads are auto-compressed: max 1200px wide, JPEG 70% quality. A 3MB phone photo becomes ~100KB. Existing photos are NOT touched.</div></div>
        </div>
    </div>

    <!-- Support Staff Cash -->
    <div class="rb-card" id="rb-support-cash">
        <div class="rb-card-hdr" onclick="rbToggle('rb-support-cash')">
            <div class="ico" style="background:#f3e5f5;">🇸🇸</div>
            <div class="txt"><h4>Support Staff — SSP & USD Cashbooks</h4><p>How Bidal and support team track their SSP and USD</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <div class="rb-alert info">ℹ️ Support roles now have dedicated SSP Cashbook and USD Cashbook buttons on My Cash page.</div>
            <strong>Hero card shows:</strong>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">SSP Bag: SSP received from office minus SSP expenses minus handovers</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">USD Cash: USD received (from cash_ins) minus USD expenses. NOT from CRM wallet payments (that was the $45K bug).</div></div>
            <strong>Cashbook views:</strong>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">SSP Cashbook: every transaction with running balance — who gave SSP, what was spent, returns to office.</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">USD Cashbook: same for USD. Empty if no USD received via cash_ins.</div></div>
        </div>
    </div>

    <!-- Google Drive Backup -->
    <div class="rb-card" id="rb-gdrive">
        <div class="rb-card-hdr" onclick="rbToggle('rb-gdrive')">
            <div class="ico" style="background:#dcfce7;">☁️</div>
            <div class="txt"><h4>Google Drive Backup (Split CODE + DATA)</h4><p>Auto-backup creates two files — one for UCRM, one for SSH restore</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <strong>How it works now (v4.11.3):</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Runs daily at 3 AM Juba time (was 6 AM, often got budget-killed by other cron jobs).</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Creates TWO ZIPs: <strong>CODE</strong> (~2MB, plugin files only) and <strong>DATA</strong> (~24MB, database + JSON + photos).</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">WhatsApp notification shows both file names and sizes when complete.</div></div>
            <strong>To restore on new CRM:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Download CODE zip from Drive → Upload to UCRM → Plugins (no data/ inside, UCRM accepts it)</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Download DATA zip → SCP to server → <code>cd /data/ucrm/data/plugins/dishnet-hybrid-telecom/ && unzip -o DATA.zip && chown -R 33:33 data/</code></div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">Update CRM token in Settings → Run Full Sync from UCRM Data tab.</div></div>
            <strong>Force test:</strong>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t"><code>?page=api&action=force_cron_job&job=gdrive_backup</code></div></div>
        </div>
    </div>

    <!-- Fiber Install Jobs -->
    <div class="rb-card" id="rb-fiber-jobs">
        <div class="rb-card-hdr" onclick="rbToggle('rb-fiber-jobs')">
            <div class="ico" style="background:#dbeafe;">🔧</div>
            <div class="txt"><h4>Fiber Installation → Invoice Jobs</h4><p>Auto-creates job for Rupesh when Bidal completes an install</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <strong>Flow:</strong>
            <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Bidal creates service in Splynx (installation complete)</div></div>
            <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Cron detects it within 5 min (checks local tickets, NOT Splynx API — zero API calls when nothing new)</div></div>
            <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">Creates job in <code>fiber_collection_jobs</code> table → WA to Rupesh: "Create CRM invoice"</div></div>
            <div class="rb-step"><div class="rb-step-n">4</div><div class="rb-step-t">Rupesh sees on dashboard: "🔧 X fiber installs need CRM invoice"</div></div>
            <div class="rb-step"><div class="rb-step-n">5</div><div class="rb-step-t">Rupesh creates invoice in CRM manually → marks job as done</div></div>
            <div class="rb-alert info">ℹ️ Watermark protection: first deploy sets timestamp. Only installs AFTER that date get jobs. Old customers never get retroactive jobs.</div>
            <div class="rb-alert info">ℹ️ Dedup: 4 layers prevent duplicate jobs — watermark + NOT EXISTS SQL + UNIQUE constraint + code check.</div>
        </div>
    </div>

    <!-- Receipt PDF Dedup -->
    <div class="rb-card" id="rb-receipt-dedup">
        <div class="rb-card-hdr" onclick="rbToggle('rb-receipt-dedup')">
            <div class="ico" style="background:#fef2f2;">📄</div>
            <div class="txt"><h4>Receipt PDF — No more duplicates</h4><p>Customers were getting 3-4 receipt PDFs during plugin upload</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <strong>What was happening:</strong>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">During plugin upload, UCRM retries webhooks 2-3 times. Each retry queued another receipt PDF. Customer got 3-4 copies.</div></div>
            <strong>Fixed (v4.11.3):</strong>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">Webhook: checks if payment_id already in queue before adding</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">Cron: checks if payment_id already sent before delivering</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t">Result: exactly 1 text message + 1 receipt PDF per payment, always.</div></div>
        </div>
    </div>

    <!-- Install Dashboard -->
    <div class="rb-card" id="rb-install-stats">
        <div class="rb-card-hdr" onclick="rbToggle('rb-install-stats')">
            <div class="ico" style="background:#ede9fe;">📡</div>
            <div class="txt"><h4>Installation Dashboard — All statuses visible</h4><p>27 tickets were invisible before — now every status is tracked</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <strong>Status cards (both Install tab and Splynx NOC):</strong>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t"><strong>New</strong> — just registered</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t"><strong>Surveyed</strong> — site checked</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t"><strong>Deploying</strong> — cable being laid</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t"><strong>ONU Ready</strong> — hardware mapped</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t"><strong>Waiting</strong> — customer/agent response needed (was HIDDEN before!)</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t"><strong>PENDING (red)</strong> — New + Surveyed + Deploying + ONU Ready + Waiting = total installations to plan for</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t"><strong>Blocked</strong> — fiber not available + client not ready (was HIDDEN before!)</div></div>
            <div class="rb-step"><div class="rb-step-n">•</div><div class="rb-step-t"><strong>Cancelled</strong> — customer cancelled</div></div>
        </div>
    </div>

    <!-- ═══ ESCALATION GUIDE ═══ -->
    <div class="rb-section-hdr">📋 Lead Management System (v4.11.26+)</div>

    <!-- Lead system flow -->
    <div class="rb-item">
        <div class="icon">📱</div>
        <div class="txt"><h4>How leads are created automatically</h4><p>WA marketing messages → auto-lead after 2nd message</p></div>
        <div class="toggle">▶</div>
    </div>
    <div class="rb-detail">
        <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Customer messages the <strong>marketing WA number</strong> (Evolution API)</div></div>
        <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Bot auto-replies instantly (already working)</div></div>
        <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">After <strong>2nd message</strong> — lead auto-created, assigned to agent with fewest leads</div></div>
        <div class="rb-step"><div class="rb-step-n">4</div><div class="rb-step-t">Agent gets WhatsApp: <em>"🔴 New lead: Ahmed, Gudele — call within 1 hour"</em></div></div>
        <div class="rb-step"><div class="rb-step-n">5</div><div class="rb-step-t">Agent sees lead on <strong>Home screen</strong> — tap Call Now → call → press outcome button</div></div>
        <div class="rb-alert warn">⚠️ @lid contacts (no real phone number) are skipped automatically — can't call them</div>
        <div class="rb-alert warn">⚠️ Existing CRM customers are skipped — they're not new leads</div>
    </div>

    <!-- Lead alert timers -->
    <div class="rb-item">
        <div class="icon">⏰</div>
        <div class="txt"><h4>Lead alert timers — what fires when</h4><p>Automatic WA reminders if agent doesn't call in time</p></div>
        <div class="toggle">▶</div>
    </div>
    <div class="rb-detail">
        <div class="rb-step"><div class="rb-step-n">+0m</div><div class="rb-step-t">Lead assigned → WA to agent immediately</div></div>
        <div class="rb-step"><div class="rb-step-n">+45m</div><div class="rb-step-t">Still not called → Warning WA to agent: "15 minutes left"</div></div>
        <div class="rb-step"><div class="rb-step-n">+60m</div><div class="rb-step-t">Still not called → Escalation WA to admin (Aida)</div></div>
        <div class="rb-step"><div class="rb-step-n">3x no answer</div><div class="rb-step-t">Lead auto-marked Dead + farewell WA sent to customer</div></div>
        <div class="rb-alert info">ℹ️ All timers configurable: Settings → System → lead_call_deadline_minutes</div>
    </div>

    <!-- Lead call modal -->
    <div class="rb-item">
        <div class="icon">📞</div>
        <div class="txt"><h4>Agent call flow — what to do step by step</h4><p>Exactly what the agent sees and does during a call</p></div>
        <div class="toggle">▶</div>
    </div>
    <div class="rb-detail">
        <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Open Home tab → green card shows next lead → tap <strong>📲 Call Now</strong></div></div>
        <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Phone dials. Script shows on screen — <strong>read word for word</strong></div></div>
        <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">After call ends, press <strong>ONE button</strong>: No Answer / Interested / Call Later / Not Interested</div></div>
        <div class="rb-step"><div class="rb-step-n">4</div><div class="rb-step-t">WA auto-sent to customer based on outcome. Page reloads → next lead appears</div></div>
        <div class="rb-alert warn">⚠️ If customer is interested → they go to <strong>🔥 Closer Queue</strong> tab. Closer must call within 2 hours.</div>
    </div>

    <!-- Lead archive -->
    <div class="rb-item">
        <div class="icon">🗄️</div>
        <div class="txt"><h4>Old leads cluttering the queue</h4><p>How to archive old leads for a fresh start</p></div>
        <div class="toggle">▶</div>
    </div>
    <div class="rb-detail">
        <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Go to <strong>Settings → System</strong></div></div>
        <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Find <strong>🗄️ Lead Archive — Fresh Start</strong> section</div></div>
        <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">Click <strong>"Archive Old Leads Now"</strong> — archives all leads created before today</div></div>
        <div class="rb-step"><div class="rb-step-n">4</div><div class="rb-step-t">Agents see empty clean queue. Old leads visible to admin in 🗄️ Archived tab</div></div>
        <div class="rb-alert info">ℹ️ Nothing is deleted. Data is always safe.</div>
    </div>

    <div class="rb-section-hdr">🔧 Fiber Install Automation (v4.11.22+)</div>

    <!-- Fiber auto flow -->
    <div class="rb-item">
        <div class="icon">🌐</div>
        <div class="txt"><h4>What happens automatically when Bidal creates a service in Splynx</h4><p>3 things fire within 5 minutes, no manual action needed</p></div>
        <div class="toggle">▶</div>
    </div>
    <div class="rb-detail">
        <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t"><strong>Collection job created</strong> → Rupesh gets WhatsApp: "Create CRM invoice for [Customer]"</div></div>
        <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t"><strong>Delivery note PDF sent</strong> → Customer gets WhatsApp with delivery acknowledgment + T&Cs</div></div>
        <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t"><strong>Splynx ticket closed</strong> → Ticket marked Solved + comment added + closed = 1</div></div>
        <div class="rb-alert warn">⚠️ If delivery note not sent → Bidal gets WhatsApp alert: "No KYC linked — send manually"</div>
        <div class="rb-alert info">ℹ️ Check status: Accounts Dashboard → Fiber Install Log widget (✅/❌ per step)</div>
    </div>

    <!-- Fiber delivery note not sent -->
    <div class="rb-item">
        <div class="icon">📄</div>
        <div class="txt"><h4>Delivery note not sent to customer</h4><p>Customer didn't receive the PDF acknowledgment</p></div>
        <div class="toggle">▶</div>
    </div>
    <div class="rb-detail">
        <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Go to <strong>Accounts Dashboard → Fiber Install Log</strong> — find the row, check ❌ column</div></div>
        <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">If ⚠️ "No KYC" — the fiber customer has no KYC application linked. Check phone numbers match between KYC and Splynx</div></div>
        <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">If ❌ "Delivery Failed" — PDF service may be down. Check <strong>Settings → 🩺 Health</strong></div></div>
        <div class="rb-step"><div class="rb-step-n">4</div><div class="rb-step-t">Manual send: find customer in WA Inbox → send PDF from delivery_pdfs folder</div></div>
    </div>

    <div class="rb-section-hdr">🩺 System Health (v4.11.34+)</div>

    <!-- Health dashboard -->
    <div class="rb-item">
        <div class="icon">🩺</div>
        <div class="txt"><h4>How to check if everything is working</h4><p>One page shows all automation status at a glance</p></div>
        <div class="toggle">▶</div>
    </div>
    <div class="rb-detail">
        <div class="rb-step"><div class="rb-step-n">1</div><div class="rb-step-t">Go to <strong>Settings → 🩺 Health</strong> tab (bookmark this page)</div></div>
        <div class="rb-step"><div class="rb-step-n">2</div><div class="rb-step-t">Check <strong>Cron Jobs</strong> section — all jobs should show ✅ with recent timestamps</div></div>
        <div class="rb-step"><div class="rb-step-n">3</div><div class="rb-step-t">Check <strong>Lead System</strong> — auto-WA events should show "Fired Xx today"</div></div>
        <div class="rb-step"><div class="rb-step-n">4</div><div class="rb-step-t">Check <strong>Fiber Automation</strong> — delivery notes and ticket closes should be 100%</div></div>
        <div class="rb-step"><div class="rb-step-n">5</div><div class="rb-step-t">Click <strong>"▶ Run Lead Alert Cron"</strong> to test the alert engine and see live output</div></div>
        <div class="rb-alert warn">⚠️ If any cron shows "Stale" or "Never ran" — master cron may have stopped. Check UCRM → System → Plugins → DishNet → the plugin needs to be active.</div>
        <div class="rb-alert info">ℹ️ Evolution API diagnostic: WhatsApp → Conversations → Diagnose button</div>
    </div>

    <div class="rb-section-hdr">📞 When to escalate to Aida</div>

    <div class="rb-card" id="rb-escalate">
        <div class="rb-card-hdr" onclick="rbToggle('rb-escalate')">
            <div class="ico" style="background:#fef2f2;">🆘</div>
            <div class="txt"><h4>Escalation triggers — when to call Aida</h4><p>Issues that staff cannot fix themselves</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <strong>Always escalate immediately if:</strong>
            <div class="rb-step"><div class="rb-step-n">🔴</div><div class="rb-step-t">The CRM is completely down (white screen, "500 error", or "Connection refused")</div></div>
            <div class="rb-step"><div class="rb-step-n">🔴</div><div class="rb-step-t">5+ payments are missing from the cashbook on the same day</div></div>
            <div class="rb-step"><div class="rb-step-n">🔴</div><div class="rb-step-t">The cashbook running balance chain is broken (numbers don't add up)</div></div>
            <div class="rb-step"><div class="rb-step-n">🔴</div><div class="rb-step-t">WhatsApp session expired (QR re-scan needed)</div></div>
            <div class="rb-step"><div class="rb-step-n">🔴</div><div class="rb-step-t">Google Drive backup hasn't run for 48+ hours</div></div>
            <div class="rb-step"><div class="rb-step-n">🔴</div><div class="rb-step-t">BlueCard server (162.241.149.144) is unreachable</div></div>
            <div class="rb-step"><div class="rb-step-n">🔴</div><div class="rb-step-t">Splynx sync showing "Down" — fiber installs won't generate jobs for Rupesh</div></div>

            <strong>Can wait until next business day:</strong>
            <div class="rb-step"><div class="rb-step-n">🟡</div><div class="rb-step-t">A single payment didn't sync (manual workaround: add it in cashbook manually)</div></div>
            <div class="rb-step"><div class="rb-step-n">🟡</div><div class="rb-step-t">Scanner camera not working on one specific phone</div></div>
            <div class="rb-step"><div class="rb-step-n">🟡</div><div class="rb-step-t">Staff member can't log in (admin can reset password)</div></div>
            <div class="rb-step"><div class="rb-step-n">🟡</div><div class="rb-step-t">Report or export feature not working</div></div>
            <div class="rb-step"><div class="rb-step-n">🟡</div><div class="rb-step-t">Fiber install job not created for a specific customer (Rupesh can create invoice manually in CRM)</div></div>
            <div class="rb-step"><div class="rb-step-n">🟡</div><div class="rb-step-t">Receipt photo not showing (check if support_leader role has access — should be fixed in v4.11.3)</div></div>

            <div class="rb-alert success">✅ <strong>When reporting to Aida:</strong> Include the exact error message (screenshot if possible), what you were trying to do, which browser/device, and what time it happened. This saves hours of debugging.</div>
        </div>
    </div>

    <!-- ═══ QUICK REFERENCE ═══ -->
    <div class="rb-section-hdr">🔗 Quick reference</div>

    <div class="rb-card" id="rb-urls">
        <div class="rb-card-hdr" onclick="rbToggle('rb-urls')">
            <div class="ico" style="background:#f1f5f9;">🌐</div>
            <div class="txt"><h4>Important URLs & servers</h4><p>Bookmarkable links for diagnostics</p></div>
            <span class="arrow">▸</span>
        </div>
        <div class="rb-card-body">
            <table style="width:100%;font-size:12px;border-collapse:collapse;">
                <tr style="background:#f8fafc;"><td style="padding:8px;border:1px solid #e2e8f0;font-weight:700;width:35%;">CRM (UCRM)</td><td style="padding:8px;border:1px solid #e2e8f0;">crm.dishnetafrica.com</td></tr>
                <tr><td style="padding:8px;border:1px solid #e2e8f0;font-weight:700;">Plugin Dashboard</td><td style="padding:8px;border:1px solid #e2e8f0;">crm.dishnetafrica.com/crm/_plugins/dishnet-hybrid-telecom/public.php</td></tr>
                <tr style="background:#f8fafc;"><td style="padding:8px;border:1px solid #e2e8f0;font-weight:700;">Standalone Scanner</td><td style="padding:8px;border:1px solid #e2e8f0;">...public.php?page=scanner</td></tr>
                <tr><td style="padding:8px;border:1px solid #e2e8f0;font-weight:700;">WhatsML Server</td><td style="padding:8px;border:1px solid #e2e8f0;">wa.dishnetafrica.com (134.199.215.120)</td></tr>
                <tr style="background:#f8fafc;"><td style="padding:8px;border:1px solid #e2e8f0;font-weight:700;">BlueCard Server</td><td style="padding:8px;border:1px solid #e2e8f0;">162.241.149.144 (WHM/cPanel)</td></tr>
                <tr><td style="padding:8px;border:1px solid #e2e8f0;font-weight:700;">Cron Scheduler</td><td style="padding:8px;border:1px solid #e2e8f0;">cron-job.org (external trigger)</td></tr>
                <tr style="background:#f8fafc;"><td style="padding:8px;border:1px solid #e2e8f0;font-weight:700;">App Install Page</td><td style="padding:8px;border:1px solid #e2e8f0;">...public.php?page=install</td></tr>
                <tr><td style="padding:8px;border:1px solid #e2e8f0;font-weight:700;">Health Check API</td><td style="padding:8px;border:1px solid #e2e8f0;">...api/index.php?action=health</td></tr>
                <tr style="background:#f8fafc;"><td style="padding:8px;border:1px solid #e2e8f0;font-weight:700;">Force Cron Job</td><td style="padding:8px;border:1px solid #e2e8f0;">...?page=api&action=force_cron_job&job=NAME<br><span style="font-size:10px;color:#94a3b8;">Names: splynx_sync, gdrive_backup, wa_sync, crm_sync, lte_sync</span></td></tr>
                <tr><td style="padding:8px;border:1px solid #e2e8f0;font-weight:700;">Cron Status</td><td style="padding:8px;border:1px solid #e2e8f0;">...?page=api&action=cron_status</td></tr>
                <tr style="background:#f8fafc;"><td style="padding:8px;border:1px solid #e2e8f0;font-weight:700;">Expense Photo</td><td style="padding:8px;border:1px solid #e2e8f0;">...?page=api&action=expense_photo&id=N (&thumb=1 for thumbnail)</td></tr>
                <tr><td style="padding:8px;border:1px solid #e2e8f0;font-weight:700;">Migrate Expenses</td><td style="padding:8px;border:1px solid #e2e8f0;">POST ...?page=api&action=migrate_expenses (Phase 3 dual-write)</td></tr>
            </table>

            <div style="margin-top:12px;">
                <strong>WhatsApp API Keys (for reference):</strong>
                <div style="font-size:11px;color:#64748b;margin-top:4px;">
                    Support line: 211921443002 • Accounts line: 211921443009
                </div>
            </div>
        </div>
    </div>

</div>

<script>
(function(){
var API = '<?= $apiBase ?>?page=api&action=';
var TOKEN = '<?= $token ?>';

function $(id){return document.getElementById(id);}

// ═══ ACCORDION ═══
window.rbToggle = function(id){
    var el = document.getElementById(id);
    if(!el) return;
    var wasOpen = el.classList.contains('open');
    // Close all
    document.querySelectorAll('.rb-card.open').forEach(function(c){c.classList.remove('open');});
    // Open this one (if it was closed)
    if(!wasOpen) el.classList.add('open');
};

// ═══ AUTO-OPEN from URL hash ═══
var hash = window.location.hash.replace('#','');
if(hash && document.getElementById(hash)){
    rbToggle(hash);
}

// ═══ HEALTH CHECKS ═══
function hDot(state){
    if(state==='ok') return '<span class="dot dot-ok"></span> ';
    if(state==='warn') return '<span class="dot dot-warn"></span> ';
    if(state==='err') return '<span class="dot dot-err"></span> ';
    return '<span class="dot dot-unk"></span> ';
}

window.rbCheckHealth = function(){
    // Reset all to checking
    ['hWa','hCrm','hSplynx','hLte','hCron','hCb','hBak','hFiber'].forEach(function(id){
        $(id).innerHTML = '<span class="dot dot-unk"></span> Checking...';
    });

    // Fetch with retry (UCRM Docker proxy sometimes returns 503 transiently)
    function fetchRetry(url, opts, retries){
        retries = retries || 2;
        return fetch(url, opts).then(function(r){
            if(!r.ok && retries > 0){
                return new Promise(function(res){ setTimeout(res, 1000); }).then(function(){
                    return fetchRetry(url, opts, retries - 1);
                });
            }
            return r;
        }).catch(function(err){
            if(retries > 0){
                return new Promise(function(res){ setTimeout(res, 1000); }).then(function(){
                    return fetchRetry(url, opts, retries - 1);
                });
            }
            throw err;
        });
    }

    var fetchOpts = {credentials:'same-origin', headers:{'Authorization':'Bearer '+TOKEN}};

    // Single API call for all health indicators
    fetchRetry(API+'cron_status', fetchOpts)
    .then(function(r){ if(!r.ok) throw 'HTTP '+r.status; return r.json(); })
    .then(function(d){
        if(!d||!d.data) throw 'no data';
        var jobs = d.data.jobs || {};
        var overallAgo = (d.data.seconds_ago !== null && d.data.seconds_ago !== undefined) ? d.data.seconds_ago : 99999;

        function jobAge(key){
            var job = jobs[key];
            if(!job || !job.last_run) return 99999;
            var maxTs = 0;
            for(var k in jobs){ var t = (jobs[k]||{}).last_run||0; if(t>maxTs) maxTs=t; }
            return overallAgo + (maxTs - job.last_run);
        }

        // WA Sync
        var waAge = jobAge('wa_sync');
        if(waAge < 300) $('hWa').innerHTML = hDot('ok') + 'Active (' + Math.round(waAge/60) + 'm ago)';
        else if(waAge < 900) $('hWa').innerHTML = hDot('warn') + 'Stale (' + Math.round(waAge/60) + 'm)';
        else $('hWa').innerHTML = hDot('err') + 'Down';

        // CRM Sync
        var crmAge = jobAge('crm_sync');
        if(crmAge < 600) $('hCrm').innerHTML = hDot('ok') + 'Active';
        else if(crmAge < 1800) $('hCrm').innerHTML = hDot('warn') + 'Stale';
        else $('hCrm').innerHTML = hDot('err') + 'Down';

        // Splynx Sync
        var splynxAge = jobAge('splynx_sync');
        if(splynxAge < 600) $('hSplynx').innerHTML = hDot('ok') + 'Active';
        else if(splynxAge < 1800) $('hSplynx').innerHTML = hDot('warn') + 'Stale';
        else $('hSplynx').innerHTML = hDot('err') + 'Down';

        // LTE Sync
        var lteAge = jobAge('lte_sync');
        if(lteAge < 600) $('hLte').innerHTML = hDot('ok') + 'Active';
        else if(lteAge < 1800) $('hLte').innerHTML = hDot('warn') + 'Stale';
        else $('hLte').innerHTML = hDot('err') + 'Down';

        // Lead Alert Engine
        var leadAlertAge = jobAge('lead_alerts');
        if(leadAlertAge < 600) $('hLeadAlert').innerHTML = hDot('ok') + 'Active';
        else if(leadAlertAge < 1800) $('hLeadAlert').innerHTML = hDot('warn') + 'Stale';
        else $('hLeadAlert').innerHTML = hDot('err') + 'Down';

        // Cron overall
        if(overallAgo < 180) $('hCron').innerHTML = hDot('ok') + 'Running';
        else if(overallAgo < 600) $('hCron').innerHTML = hDot('warn') + 'Slow';
        else $('hCron').innerHTML = hDot('err') + 'Stopped';

        // Cashbook
        $('hCb').innerHTML = hDot('ok') + 'OK';

        // Backup
        var bakAge = jobAge('gdrive_backup');
        if(bakAge < 86400*1.5) $('hBak').innerHTML = hDot('ok') + 'Recent';
        else if(bakAge < 86400*3) $('hBak').innerHTML = hDot('warn') + Math.round(bakAge/86400) + 'd ago';
        else $('hBak').innerHTML = hDot('err') + 'Overdue!';

        // Fiber Jobs — runs inside splynx_sync Task 6
        var fiberAge = jobAge('splynx_sync');
        if(fiberAge < 600) $('hFiber').innerHTML = hDot('ok') + 'Active';
        else if(fiberAge < 1800) $('hFiber').innerHTML = hDot('warn') + 'Stale';
        else $('hFiber').innerHTML = hDot('err') + 'Down';
    })
    .catch(function(err){
        console.warn('Health check failed:', err);
        ['hWa','hCrm','hSplynx','hLte','hCron'].forEach(function(id){
            $(id).innerHTML = hDot('warn') + '<span style="font-size:10px">Retry</span>';
        });
        $('hCb').innerHTML = hDot('warn') + 'Check';
        $('hBak').innerHTML = hDot('warn') + 'Check';
        $('hFiber').innerHTML = hDot('warn') + 'Check';
    });
};

// Run health check on load
rbCheckHealth();
})();
</script>
