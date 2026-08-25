<?php
// ── Admin: Overdue Email Templates ───────────────────────────────────────────
if (!$isAdmin) { echo '<div style="color:red;padding:20px;">Admin only.</div>'; return; }

// ── Default templates (used when nothing saved yet) ──────────────────────────
function _oel_defaults(): array {
    // All stages: service already suspended by UCRM from Day 1 (delay=0).
    // Goal = money recovery. Every email focuses on restoration.
    // Payment auto-restores service. No reconnection fee charged.
    return [
        '1' => [
            'subject' => 'Your DishNet service is suspended — settle {{invoice_number}} to reconnect',
            'para1'   => 'We noticed your DishNet service has been temporarily suspended due to an outstanding balance of {{amount}} on invoice {{invoice_number}}, which was due on {{due_date}}.',
            'para2'   => 'The good news is that your service will be restored automatically as soon as payment is received — no need to call us or wait for manual activation. Simply pay online using the link below.',
            'cta'     => 'Pay {{amount}} & Reconnect Now',
            'footer'  => 'If you have already made payment, please send us proof and we will verify immediately. For help, reply to this email or WhatsApp us at {{accounts_phone}}.',
        ],
        '2' => [
            'subject' => 'Still suspended — pay {{amount}} to restore your internet today',
            'para1'   => 'We are following up on invoice {{invoice_number}} for {{amount}}, which has been outstanding for {{days_overdue}} days. Your DishNet service remains suspended while this balance is unpaid.',
            'para2'   => 'We understand life gets busy — settling this is quick and easy. Once your payment is confirmed, your service is restored automatically, right away. If you need to discuss a payment arrangement, just reply to this email.',
            'cta'     => 'Pay {{amount}} — Instant Reconnection',
            'footer'  => 'Questions? Reach our accounts team at {{accounts_phone}} or {{accounts_email}} — we are happy to help.',
        ],
        '3' => [
            'subject' => 'We would love to reconnect you — invoice {{invoice_number}} outstanding',
            'para1'   => 'It has been {{days_overdue}} days since invoice {{invoice_number}} for {{amount}} became due, and your DishNet service is still suspended. We genuinely miss having you connected.',
            'para2'   => 'Settling this balance will get your internet back on immediately and automatically — no delays, no extra charges. We want to make this as easy as possible for you. Please reply to this email if you need any assistance or would like to discuss options.',
            'cta'     => 'Settle {{amount}} — Get Back Online',
            'footer'  => 'We value your loyalty to DishNet and look forward to reconnecting you. Call or WhatsApp us at {{accounts_phone}} — we are here to help.',
        ],
        '4' => [
            'subject' => 'Your DishNet connection is waiting — {{amount}} to restore',
            'para1'   => 'We are reaching out again regarding invoice {{invoice_number}} for {{amount}}, now {{days_overdue}} days overdue. Your DishNet connection is ready and waiting — we just need to receive your payment.',
            'para2'   => 'Once payment is received, your service reconnects automatically — no need to contact us. We would love to get you back online. Please reach out if there is anything we can do to help you resolve this.',
            'cta'     => 'Pay Now — Reconnect Instantly',
            'footer'  => 'Our accounts team is available Monday to Friday at {{accounts_phone}} or {{accounts_email}}. We are always willing to discuss your situation.',
        ],
        '5' => [
            'subject' => 'Still here for you — let\'s resolve your DishNet account',
            'para1'   => 'Your DishNet account has had an outstanding balance of {{amount}} on invoice {{invoice_number}} for {{days_overdue}} days. We want you to know that we are still here and your connection can be restored.',
            'para2'   => 'Paying this balance is the only step needed to get your internet back on. The reconnection happens automatically the moment payment is confirmed. Please get in touch — we want to find a way to resolve this that works for you.',
            'cta'     => 'Restore My Connection',
            'footer'  => 'Please contact our accounts team at {{accounts_phone}} or {{accounts_email}} so we can assist you directly.',
        ],
        '6' => [
            'subject' => 'Final opportunity to restore your DishNet account',
            'para1'   => 'This is our final automated message regarding invoice {{invoice_number}} for {{amount}}, which has been outstanding for {{days_overdue}} days. Your DishNet service can still be restored.',
            'para2'   => 'After this message, your account will be handled directly by our accounts team on a case-by-case basis. We strongly encourage you to contact us — we are willing to discuss your situation and find a solution together. Your connection is worth saving.',
            'cta'     => 'Contact Accounts Team',
            'footer'  => 'Call or WhatsApp: {{accounts_phone}} · Email: {{accounts_email}} · We are available Monday to Friday, 8 AM – 5 PM.',
        ],
    ];
}

// ── Stage metadata ────────────────────────────────────────────────────────────
$stageMeta = [
    '1' => ['label'=>'Email #1 — Service suspended, pay to restore',  'when'=>'Day 7',   'color'=>'#2563eb', 'type'=>'📧 Email'],
    '2' => ['label'=>'Email #2 — Still suspended, instant restore',   'when'=>'Day 14',  'color'=>'#2563eb', 'type'=>'📧 Email'],
    '3' => ['label'=>'Email #3 — We miss you, easy to reconnect',     'when'=>'Day 31',  'color'=>'#d97706', 'type'=>'📧 Email'],
    '4' => ['label'=>'Email #4 — Connection waiting, just pay',       'when'=>'Day 61',  'color'=>'#d97706', 'type'=>'📧 Email'],
    '5' => ['label'=>'Email #5 — Still here, let us resolve this',   'when'=>'Day 90',  'color'=>'#dc2626', 'type'=>'📧 Email'],
    '6' => ['label'=>'Email #6 — Final chance to restore',            'when'=>'Day 120', 'color'=>'#7f1d1d', 'type'=>'📧 Email'],
];

// ── Load current config ───────────────────────────────────────────────────────
$cfg     = $store->load('kyc_config.json') ?: [];
$saved   = $cfg['overdue_email_stages'] ?? [];
$defaults = _oel_defaults();
$flash   = '';
$flashType = 'success';

// Merge saved with defaults
$templates = [];
foreach ($stageMeta as $sid => $meta) {
    $templates[$sid] = array_merge($defaults[$sid] ?? [], $saved[$sid] ?? []);
}

// Global settings
$globalFrom   = $cfg['overdue_email_from_name']  ?? 'DishNet Accounts';
$globalReply  = $cfg['overdue_email_reply_to']    ?? 'accounts@dishnetafrica.com';
$globalPhone  = $cfg['overdue_email_phone']       ?? '+211 921 443 009';
$globalEmail  = $cfg['overdue_email_accounts_email'] ?? 'accounts@dishnetafrica.com';

// ── Handle save ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tpl_action'] ?? '') === 'save') {
    $saveStage = $_POST['save_stage'] ?? '';

    if ($saveStage === 'global') {
        $cfg['overdue_email_from_name']      = trim($_POST['global_from']   ?? $globalFrom);
        $cfg['overdue_email_reply_to']       = trim($_POST['global_reply']  ?? $globalReply);
        $cfg['overdue_email_phone']          = trim($_POST['global_phone']  ?? $globalPhone);
        $cfg['overdue_email_accounts_email'] = trim($_POST['global_email']  ?? $globalEmail);
        $store->save('kyc_config.json', $cfg);
        $flash = '✅ Global settings saved.';
        $globalFrom  = $cfg['overdue_email_from_name'];
        $globalReply = $cfg['overdue_email_reply_to'];
        $globalPhone = $cfg['overdue_email_phone'];
        $globalEmail = $cfg['overdue_email_accounts_email'];

    } elseif (isset($stageMeta[$saveStage])) {
        $cfg['overdue_email_stages'][$saveStage] = [
            'subject' => trim($_POST['subject'] ?? ''),
            'para1'   => trim($_POST['para1']   ?? ''),
            'para2'   => trim($_POST['para2']   ?? ''),
            'cta'     => trim($_POST['cta']     ?? ''),
            'footer'  => trim($_POST['footer']  ?? ''),
        ];
        $store->save('kyc_config.json', $cfg);
        $templates[$saveStage] = $cfg['overdue_email_stages'][$saveStage];
        $flash = '✅ Stage ' . $saveStage . ' template saved.';

    } elseif ($saveStage === 'reset_all') {
        unset($cfg['overdue_email_stages']);
        $store->save('kyc_config.json', $cfg);
        $templates = $defaults;
        $flash = '✅ All templates reset to defaults.';
    }
}

// ── Handle preview — returns raw HTML as JSON for JS preview panel ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tpl_action'] ?? '') === 'preview') {
    $pvStage = $_POST['save_stage'] ?? '1';
    $pvTpl   = [
        'subject' => $_POST['subject'] ?? '',
        'para1'   => $_POST['para1']   ?? '',
        'para2'   => $_POST['para2']   ?? '',
        'cta'     => $_POST['cta']     ?? '',
        'footer'  => $_POST['footer']  ?? '',
    ];
    $pvStageDays = [1=>7, 2=>14, 3=>31, 4=>61, 5=>90, 6=>120];
    $pvStageSubs = [
        1 => 'Your DishNet service is suspended. Pay now to reconnect instantly.',
        2 => 'Still suspended after 14 days — one payment gets you back online.',
        3 => 'We genuinely miss having you connected. Easy to fix.',
        4 => 'Your connection is ready and waiting — just needs your payment.',
        5 => 'We are still here and your connection can still be restored.',
        6 => 'Your DishNet service can still be restored — final notice.',
    ];
    $pvDays = $pvStageDays[(int)$pvStage] ?? 14;
    $pvSub  = $pvStageSubs[(int)$pvStage] ?? 'Your DishNet service is currently suspended.';

    $previewHtml = _oel_render($pvTpl, [
        'first_name'     => 'Moses',
        'full_name'      => 'Moses Mwangi Nderitu',
        'invoice_number' => 'INV012910',
        'amount'         => '$50.00',
        'due_date'       => '9 Apr 2026',
        'days_overdue'   => (string)$pvDays,
        'invoice_url'    => 'https://crm.dishnetafrica.com/crm/client/1260',
        'accounts_phone' => $globalPhone,
        'accounts_email' => $globalEmail,
        '_hero_sub'      => $pvSub,
    ], $pvStage, $globalFrom);

    // Clean any output already buffered by public.php wrapper
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['html' => $previewHtml, 'subject' => $_POST['subject'] ?? ''], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Template variable substitution ───────────────────────────────────────────
function _oel_replace(string $text, array $vars): string {
    foreach ($vars as $k => $v) {
        $text = str_replace('{{' . $k . '}}', htmlspecialchars((string)$v), $text);
    }
    return $text;
}

function _oel_render(array $tpl, array $vars, string $stage, string $fromName): string {
    // Hero gradient colours per stage
    $heroColors = ['1'=>'linear-gradient(135deg,#D41C1C 0%,#8b0000 100%)',
                   '2'=>'linear-gradient(135deg,#1d4ed8 0%,#1e3a8a 100%)',
                   '3'=>'linear-gradient(135deg,#d97706 0%,#92400e 100%)',
                   '4'=>'linear-gradient(135deg,#D41C1C 0%,#7f1d1d 100%)',
                   '5'=>'linear-gradient(135deg,#374151 0%,#111827 100%)',
                   '6'=>'linear-gradient(135deg,#374151 0%,#111827 100%)'];
    // CTA block colours per stage
    $ctaBg  = ['1'=>'#059669','2'=>'#059669','3'=>'#d97706','4'=>'#D41C1C','5'=>'#D41C1C','6'=>'#374151'];
    $ctaBox = ['1'=>['#F0FDF4','#BBF7D0','#065f46'],'2'=>['#F0FDF4','#BBF7D0','#065f46'],
               '3'=>['#fffbeb','#fde68a','#92400e'],'4'=>['#fef2f2','#fecaca','#7f1d1d'],
               '5'=>['#fef2f2','#fecaca','#7f1d1d'],'6'=>['#f8fafc','#e2e8f0','#475569']];
    // Left border colour for paragraphs
    $brdClr = ['1'=>'#D41C1C','2'=>'#1d4ed8','3'=>'#d97706','4'=>'#D41C1C','5'=>'#374151','6'=>'#374151'];

    $heroBg  = $heroColors[$stage] ?? $heroColors['1'];
    $ctaBtnC = $ctaBg[$stage]  ?? '#059669';
    $box     = $ctaBox[$stage] ?? $ctaBox['1'];
    $brd     = $brdClr[$stage] ?? '#D41C1C';

    $p1   = _oel_replace($tpl['para1']  ?? '', $vars);
    $p2   = _oel_replace($tpl['para2']  ?? '', $vars);
    $foot = _oel_replace($tpl['footer'] ?? '', $vars);
    $cta  = _oel_replace($tpl['cta']    ?? 'Pay Now', $vars);
    $fn   = htmlspecialchars($vars['first_name']     ?? 'Customer');
    $invN = htmlspecialchars($vars['invoice_number'] ?? '');
    $amt  = htmlspecialchars($vars['amount']         ?? '');
    $due  = htmlspecialchars($vars['due_date']       ?? '');
    $days = htmlspecialchars((string)($vars['days_overdue'] ?? ''));
    $url  = htmlspecialchars($vars['invoice_url']    ?? '#');
    $ph   = htmlspecialchars($vars['accounts_phone'] ?? '+211 921 443 009');
    $em   = htmlspecialchars($vars['accounts_email'] ?? 'accounts@dishnetafrica.com');

    return '<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#f5f5f5;font-family:Helvetica,Arial,sans-serif;}
.em{max-width:600px;margin:0 auto;background:#fff;}
.hdr{background:#fff;border-bottom:3px solid #D41C1C;padding:15px 24px;display:table;width:100%;}
.hdr-l{display:table-cell;vertical-align:bottom;}
.hdr-r{display:table-cell;vertical-align:bottom;text-align:right;}
.logo{font-size:26px;font-weight:900;color:#D41C1C;letter-spacing:-.5px;line-height:1;}
.logo-bar{height:4px;width:110px;background:#D41C1C;margin-top:3px;}
.logo-tag{font-size:8px;color:#aaa;margin-top:4px;letter-spacing:.3px;}
.hdr-lbl{font-size:9px;text-transform:uppercase;letter-spacing:2px;color:#bbb;}
.hdr-date{font-size:11px;color:#888;margin-top:3px;}
.hero{padding:28px 24px 24px;position:relative;overflow:hidden;}
.hc{position:absolute;top:-40px;right:-40px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;}
.h-eye{font-size:9px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:8px;}
.h-greet{font-family:Georgia,serif;font-size:24px;font-weight:400;color:#fff;line-height:1.2;margin-bottom:6px;}
.h-sub{font-size:13px;color:rgba(255,255,255,.72);line-height:1.55;max-width:300px;}
.h-amt{position:absolute;right:24px;top:50%;transform:translateY(-50%);text-align:right;}
.h-amt-num{font-size:36px;font-weight:900;color:#fff;letter-spacing:-2px;line-height:1;}
.h-amt-lbl{font-size:8px;color:rgba(255,255,255,.5);font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin-top:3px;}
.bd{background:#fff;padding:22px 24px 18px;}
.meta{width:100%;border-collapse:collapse;margin-bottom:18px;}
.meta td{padding:8px 10px;border:1px solid #e0e0e0;width:25%;vertical-align:top;}
.ml{font-size:7px;text-transform:uppercase;letter-spacing:1px;color:#bbb;font-weight:700;margin-bottom:3px;}
.mv{font-size:12px;font-weight:700;color:#141414;line-height:1.2;}
.mv.red{color:#D41C1C;font-size:16px;}
.msg{font-family:Georgia,serif;font-size:13px;color:#444;line-height:1.8;padding-left:13px;margin-bottom:18px;}
.cta-blk{padding:14px;text-align:center;margin-bottom:12px;}
.cta-lbl{font-size:9px;margin-bottom:9px;font-weight:600;letter-spacing:.3px;}
.cta-a{display:inline-block;color:#fff;font-size:13px;font-weight:800;padding:12px 32px;border-radius:4px;text-decoration:none;letter-spacing:.4px;text-transform:uppercase;}
.note{padding:7px 11px;font-size:8px;color:#555;line-height:1.65;margin-bottom:12px;}
.note b{color:#141414;}
.cgen{font-size:8px;color:#bbb;font-style:italic;}
.help{background:#f8f8f8;border-top:1px solid #f0f0f0;padding:16px 24px;display:flex;align-items:center;gap:12px;}
.hi{width:38px;height:38px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.ht{font-size:12px;font-weight:800;color:#141414;margin-bottom:2px;}
.hd{font-size:11px;color:#888;line-height:1.5;}
.fty{background:#fff;padding:0 24px;}
.fty table{width:100%;border-collapse:collapse;border-top:1px solid #e8e8e8;}
.fty td{padding:10px 0;vertical-align:middle;border:none;}
.ft1{font-size:11px;font-weight:700;color:#141414;}
.ft2{font-size:8px;color:#aaa;margin-top:2px;}
.ft3{font-size:8px;color:#888;}
.redbar{height:2px;background:#D41C1C;}
.strip table{width:100%;border-collapse:collapse;background:#fff;}
.strip td{padding:6px 24px;font-size:8px;color:#aaa;border:none;}
.strip .oc{font-size:9px;color:#D41C1C;text-align:center;font-style:italic;font-weight:600;}
.strip .rr{text-align:right;}
.dkft{background:#141414;padding:18px 24px;}
.sr{display:flex;gap:7px;margin-bottom:13px;}
.sc{width:28px;height:28px;border-radius:4px;background:#222;color:#555;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;text-decoration:none;}
.fl{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:11px;}
.fl a{font-size:10px;color:#555;text-decoration:none;font-weight:600;}
.fl a.u{color:#D41C1C;}
.lg{font-size:10px;color:#3a3a3a;line-height:1.7;}
.lg a{color:#555;text-decoration:underline;}
</style></head><body>
<div class="em">

  <div class="hdr">
    <div class="hdr-l">
      <div class="logo">DishNet</div>
      <div class="logo-bar"></div>
      <div class="logo-tag">Internet Service Provider</div>
    </div>
    <div class="hdr-r">
      <div class="hdr-lbl">Service Notice</div>
      <div class="hdr-date">' . date('d M Y') . '</div>
    </div>
  </div>

  <div class="hero" style="background:' . $heroBg . ';">
    <div class="hc"></div>
    <div class="h-eye">Stage ' . $stage . ' — Day ' . $days . ' Preview</div>
    <div class="h-greet">Hi <strong>' . $fn . '</strong>,</div>
    <div class="h-sub">' . htmlspecialchars($vars['_hero_sub'] ?? 'Your DishNet service is currently suspended.') . '</div>
    <div class="h-amt">
      <div class="h-amt-num">' . $amt . '</div>
      <div class="h-amt-lbl">to restore</div>
    </div>
  </div>

  <div class="bd">
    <table class="meta">
      <tr>
        <td><div class="ml">Invoice</div><div class="mv">' . $invN . '</div></td>
        <td><div class="ml">Amount Due</div><div class="mv red">' . $amt . '</div></td>
        <td><div class="ml">Due Date</div><div class="mv">' . $due . '</div></td>
        <td><div class="ml">Status</div><div class="mv" style="color:#D41C1C;font-size:11px;">SUSPENDED</div><div style="font-size:9px;color:#bbb;margin-top:1px;">' . $days . ' days overdue</div></td>
      </tr>
    </table>

    <p class="msg" style="border-left:3px solid ' . $brd . ';">' . $p1 . '</p>
    <p class="msg" style="border-left:3px solid transparent;">' . $p2 . '</p>

    <div class="cta-blk" style="border:1px solid ' . $box[1] . ';background:' . $box[0] . ';">
      <div class="cta-lbl" style="color:' . $box[2] . ';">Pay this invoice to restore your service instantly</div>
      <a href="' . $url . '" class="cta-a" style="background:' . $ctaBtnC . ';">' . $cta . ' →</a>
    </div>

    <div class="note" style="border:1px solid #e0e0e0;border-left:3px solid ' . $brd . ';">
      <b>Please note:</b> ' . $foot . '
    </div>

    <div class="cgen">This is a computer generated notice — no signature required.</div>
  </div>

  <div class="help">
    <div class="hi">&#x1F4AC;</div>
    <div>
      <div class="ht">Need help getting reconnected?</div>
      <div class="hd">Reply to this email &nbsp;&middot;&nbsp; WhatsApp ' . $ph . ' &nbsp;&middot;&nbsp; Call +211 921 443 002</div>
    </div>
  </div>

  <div class="fty">
    <table>
      <tr>
        <td><div class="ft1">Thank you for your business.</div><div class="ft2">For queries, contact us at ' . $ph . ' or ' . $em . '</div></td>
        <td style="text-align:right;"><div class="ft3">DishNet Africa Ltd.</div></td>
      </tr>
    </table>
  </div>
  <div class="redbar"></div>
  <div class="strip">
    <table>
      <tr>
        <td>DishNet Africa Ltd &middot; South Sudan</td>
        <td class="oc">Of course we can ...</td>
        <td class="rr">www.dishnetafrica.com</td>
      </tr>
    </table>
  </div>

  <div class="dkft">
    <div class="sr">
      <a href="#" class="sc">f</a>
      <a href="#" class="sc">in</a>
      <a href="#" class="sc">X</a>
      <a href="#" class="sc">wa</a>
    </div>
    <div class="fl">
      <a href="#">View Invoice</a>
      <a href="#">Payment History</a>
      <a href="#">Support</a>
      <a href="#">dishnetafrica.com</a>
      <a href="#" class="u">Unsubscribe</a>
    </div>
    <div class="lg">
      &copy; ' . date('Y') . ' DishNet Africa Ltd &middot; South Sudan<br>
      You are receiving this because you have an active account with DishNet Africa.<br>
      <a href="mailto:' . $em . '?subject=Unsubscribe">Unsubscribe from billing reminders</a>
    </div>
  </div>

</div>
</body></html>';
}

?>

<style>
.tpl-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;margin-bottom:14px;overflow:hidden;}
.tpl-hdr{display:flex;align-items:center;gap:12px;padding:14px 18px;cursor:pointer;user-select:none;}
.tpl-hdr:hover{background:#f8fafc;}
.tpl-body{padding:0 18px 18px;display:none;}
.tpl-body.open{display:block;}
.tpl-field{margin-bottom:14px;}
.tpl-field label{display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.tpl-field input,.tpl-field textarea{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;font-family:inherit;resize:vertical;}
.tpl-field input:focus,.tpl-field textarea:focus{outline:none;border-color:#2563eb;}
.var-pill{display:inline-block;background:#eff6ff;color:#1d4ed8;border-radius:5px;padding:2px 8px;font-size:11px;font-weight:700;margin:2px;cursor:pointer;border:1px solid #bfdbfe;}
.var-pill:hover{background:#dbeafe;}
.btn-save{background:#059669;color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;}
.btn-preview{background:#6366f1;color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;margin-left:8px;}
.btn-reset{background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;border-radius:8px;padding:9px 14px;font-size:12px;cursor:pointer;}
</style>

<div style="max-width:860px;margin:0 auto;">

<?php if ($flash): ?>
<div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#166534;font-weight:700;">
    <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
    <div>
        <h2 style="margin:0;font-size:18px;font-weight:800;color:#1e293b;">✉️ Overdue Email Templates</h2>
        <div style="font-size:12px;color:#64748b;margin-top:2px;">Customize the content of each follow-up email. Use <code>{{variable}}</code> for dynamic values.</div>
    </div>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="tpl_action" value="save">
        <input type="hidden" name="save_stage" value="reset_all">
        <button type="submit" class="btn-reset" onclick="return confirm('Reset all templates to defaults?')">↺ Reset All to Defaults</button>
    </form>
</div>

<!-- Available variables reference -->
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;margin-bottom:20px;">
    <div style="font-size:11px;font-weight:800;color:#1d4ed8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Available variables — click to copy</div>
    <div>
        <?php foreach ([
            '{{first_name}}','{{full_name}}','{{invoice_number}}','{{amount}}',
            '{{due_date}}','{{days_overdue}}','{{invoice_url}}',
            '{{accounts_phone}}','{{accounts_email}}'
        ] as $v): ?>
        <span class="var-pill" onclick="navigator.clipboard.writeText('<?= $v ?>')"><?= $v ?></span>
        <?php endforeach; ?>
    </div>
</div>

<!-- Global settings -->
<div class="tpl-card" style="border-color:#7c3aed40;">
    <div class="tpl-hdr" onclick="toggleTpl('global')">
        <span style="font-size:20px;">⚙️</span>
        <div style="flex:1;">
            <div style="font-weight:800;color:#1e293b;">Global Settings</div>
            <div style="font-size:12px;color:#64748b;">Sender name, reply-to email, contact details</div>
        </div>
        <span id="arr-global" style="color:#94a3b8;font-size:18px;">▸</span>
    </div>
    <div id="body-global" class="tpl-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="tpl_action" value="save">
            <input type="hidden" name="save_stage" value="global">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="tpl-field">
                    <label>From Name (appears in inbox)</label>
                    <input type="text" name="global_from" value="<?= h($globalFrom) ?>" placeholder="DishNet Accounts">
                </div>
                <div class="tpl-field">
                    <label>Reply-To Email</label>
                    <input type="email" name="global_reply" value="<?= h($globalReply) ?>" placeholder="accounts@dishnetafrica.com">
                </div>
                <div class="tpl-field">
                    <label>Accounts Phone (for {{accounts_phone}})</label>
                    <input type="text" name="global_phone" value="<?= h($globalPhone) ?>" placeholder="+211 921 443 009">
                </div>
                <div class="tpl-field">
                    <label>Accounts Email (for {{accounts_email}})</label>
                    <input type="email" name="global_email" value="<?= h($globalEmail) ?>" placeholder="accounts@dishnetafrica.com">
                </div>
            </div>
            <button type="submit" class="btn-save">💾 Save Global Settings</button>
        </form>
    </div>
</div>

<!-- Preview panel — populated by JS when Preview button clicked -->
<div id="tplPreviewPanel" style="display:none;background:#1e293b;border-radius:14px;padding:16px;margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <div style="color:#fff;font-weight:700;font-size:14px;">📧 Email Preview — sample data (Moses, INV012910, $50)</div>
        <button onclick="document.getElementById('tplPreviewPanel').style.display='none'" style="background:#475569;color:#fff;border:none;border-radius:6px;padding:4px 12px;cursor:pointer;">✕ Close</button>
    </div>
    <iframe id="tplPreviewFrame" style="width:100%;height:520px;border:none;border-radius:8px;background:#fff;"></iframe>
</div>

<!-- Stage templates -->
<?php foreach ($stageMeta as $sid => $meta):
    $tpl = $templates[$sid] ?? $defaults[$sid] ?? [];
?>
<div class="tpl-card">
    <div class="tpl-hdr" onclick="toggleTpl('<?= $sid ?>')">
        <span style="background:<?= $meta['color'] ?>20;color:<?= $meta['color'] ?>;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:800;"><?= $meta['when'] ?></span>
        <div style="flex:1;">
            <div style="font-weight:800;color:#1e293b;"><?= $meta['type'] ?> — <?= $meta['label'] ?></div>
            <div style="font-size:12px;color:#94a3b8;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:500px;">
                <?= h($tpl['subject'] ?? '') ?>
            </div>
        </div>
        <span id="arr-<?= $sid ?>" style="color:#94a3b8;font-size:18px;">▸</span>
    </div>
    <div id="body-<?= $sid ?>" class="tpl-body">
        <div class="tpl-field">
            <label>Email Subject</label>
            <input type="text" id="subj-<?= $sid ?>" value="<?= h($tpl['subject'] ?? '') ?>">
        </div>
        <div class="tpl-field">
            <label>Paragraph 1 — The situation</label>
            <textarea id="para1-<?= $sid ?>" rows="3"><?= h($tpl['para1'] ?? '') ?></textarea>
        </div>
        <div class="tpl-field">
            <label>Paragraph 2 — The ask</label>
            <textarea id="para2-<?= $sid ?>" rows="3"><?= h($tpl['para2'] ?? '') ?></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="tpl-field">
                <label>Button Text</label>
                <input type="text" id="cta-<?= $sid ?>" value="<?= h($tpl['cta'] ?? '') ?>">
            </div>
            <div class="tpl-field">
                <label>Footer Note</label>
                <input type="text" id="footer-<?= $sid ?>" value="<?= h($tpl['footer'] ?? '') ?>">
            </div>
        </div>
        <!-- Hidden save form -->
        <form id="saveform-<?= $sid ?>" method="POST" style="display:none;">
            <?= csrfField() ?>
            <input type="hidden" name="tpl_action" value="save">
            <input type="hidden" name="save_stage" value="<?= $sid ?>">
            <input type="hidden" name="subject" id="hsubj-<?= $sid ?>">
            <input type="hidden" name="para1"   id="hpara1-<?= $sid ?>">
            <input type="hidden" name="para2"   id="hpara2-<?= $sid ?>">
            <input type="hidden" name="cta"     id="hcta-<?= $sid ?>">
            <input type="hidden" name="footer"  id="hfooter-<?= $sid ?>">
        </form>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
            <button type="button" class="btn-save" onclick="tplSave('<?= $sid ?>')">💾 Save Stage <?= $sid ?></button>
            <button type="button" class="btn-preview" onclick="tplPreview('<?= $sid ?>')">👁 Preview</button>
        </div>
    </div>
</div>
<?php endforeach; ?>

</div><!-- /max-width -->

<script>
function toggleTpl(id) {
    var body = document.getElementById('body-' + id);
    var arr  = document.getElementById('arr-' + id);
    if (!body) return;
    var open = body.classList.contains('open');
    body.classList.toggle('open', !open);
    if (arr) arr.textContent = open ? '▸' : '▾';
}

function _tplCollect(sid) {
    return {
        subject: (document.getElementById('subj-'   + sid) || {}).value || '',
        para1:   (document.getElementById('para1-'  + sid) || {}).value || '',
        para2:   (document.getElementById('para2-'  + sid) || {}).value || '',
        cta:     (document.getElementById('cta-'    + sid) || {}).value || '',
        footer:  (document.getElementById('footer-' + sid) || {}).value || '',
    };
}

function tplSave(sid) {
    var d = _tplCollect(sid);
    document.getElementById('hsubj-'   + sid).value = d.subject;
    document.getElementById('hpara1-'  + sid).value = d.para1;
    document.getElementById('hpara2-'  + sid).value = d.para2;
    document.getElementById('hcta-'    + sid).value = d.cta;
    document.getElementById('hfooter-' + sid).value = d.footer;
    document.getElementById('saveform-' + sid).submit();
}

function tplPreview(sid) {
    var d = _tplCollect(sid);
    var panel = document.getElementById('tplPreviewPanel');
    var frame = document.getElementById('tplPreviewFrame');
    panel.style.display = 'block';
    frame.srcdoc = '<p style="padding:40px;text-align:center;color:#64748b;font-family:Arial;">Loading preview...</p>';
    panel.scrollIntoView({behavior:'smooth', block:'start'});

    fetch('?page=api&action=overdue_email_preview', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'Authorization': 'Bearer <?= h($retailer['api_token'] ?? '') ?>'},
        body: JSON.stringify({stage: parseInt(sid), subject: d.subject, para1: d.para1, para2: d.para2, cta: d.cta, footer: d.footer})
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.data && data.data.html) {
            frame.srcdoc = data.data.html;
        } else {
            frame.srcdoc = '<p style="padding:20px;color:red;">Preview error: ' + (data.message || 'Unknown error') + '</p>';
        }
    })
    .catch(function(e) {
        frame.srcdoc = '<p style="padding:20px;color:red;">Preview failed: ' + e.message + '</p>';
    });
}

// Auto-open saved stage
<?php if ($flash && preg_match('/Stage (\d+)/', $flash, $m)): ?>
toggleTpl('<?= $m[1] ?>');
<?php elseif ($flash && str_contains($flash, 'Global')): ?>
toggleTpl('global');
<?php endif; ?>
</script>
