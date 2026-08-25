<?php
// ═══════════════════════════════════════════════════════════════════════════
// lib/OverdueDunningHelpers.php — v4.21.67
//
// Shared builders for overdue-invoice dunning communications. Used by:
//   - cron_overdue_email.php (the weekly auto-dunning chain)
//   - includes/api/api_crm_misc.php → owb_bulk_send (operator-triggered
//     bulk send from the Overdue Workbench)
//
// Functions are global (not namespaced) and prefixed with _ for back-compat
// with how the cron originally defined them inline. Guarded with
// function_exists() so multiple includes are safe.
//
// All templates and tone choices live here. Edit once, both auto-cron and
// manual bulk-send pick up the change.
//
// Public functions:
//   _buildSubject(stage, invNum, amount, days, cfg)       → string
//   _buildEmail(stage, firstName, fullName, invNum,
//               amount, dueDate, days, invoiceUrl,
//               payUrl, cfg)                              → HTML string
//   _buildWhatsApp(stage, firstName, invNum, amount,
//                  dueDate, days, invoiceUrl)             → string
//   _oel_replace(text, vars)                              → string
//   _sendEmail(smtp, to, subject, html, &error)           → bool
//   _rawSmtp(smtp, to, message, &error)                   → bool
//   _getSmtpSettings(store, config)                       → ?array
//
// Stage selection helper (NEW — was inline in cron's stages array before):
//   _stageForDays(days)  → int 1-9
// ═══════════════════════════════════════════════════════════════════════════

declare(strict_types=1);

// ── Stage selector — single source of truth for "what stage does N days map to" ─
if (!function_exists('_stageForDays')) {
    function _stageForDays(int $daysOverdue): int
    {
        // Same boundaries as cron_overdue_email.php's $stages array.
        // Stages 1-9 cover Day 14 → Day ∞. Days 1-13 not handled by
        // dunning chain (UCRM sends its own Day-1 email, cron_invoice_notify
        // sends a Day-7 WhatsApp, no overlap with this chain).
        if ($daysOverdue < 14)   return 0;        // not in chain
        if ($daysOverdue <= 30)  return 1;
        if ($daysOverdue <= 44)  return 2;
        if ($daysOverdue <= 60)  return 3;
        if ($daysOverdue <= 74)  return 4;
        if ($daysOverdue <= 89)  return 5;
        if ($daysOverdue <= 119) return 6;
        if ($daysOverdue <= 179) return 7;
        if ($daysOverdue <= 209) return 8;
        return 9; // 210+ — monthly recurring
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// EMAIL SUBJECT BUILDER — reads from kyc_config overdue_email_stages,
// falls back to defaults below.
// ═══════════════════════════════════════════════════════════════════════════
if (!function_exists('_buildSubject')) {
    function _buildSubject(int $stage, string $invNum, string $amount, int $days, array $cfg): string
    {
        $saved = $cfg['overdue_email_stages'][$stage] ?? $cfg['overdue_email_stages'][(string)$stage] ?? [];
        $tpl   = $saved['subject'] ?? '';
        if (!$tpl) {
            // All stages: service is already suspended from Day 1 by UCRM automatically.
            // Goal = money recovery. Tone = polite, helpful, focused on reconnection.
            $defaults = [
                1 => 'Your DishNet service is suspended — settle {{invoice_number}} to reconnect',
                2 => 'Still suspended — pay {{amount}} to restore your internet today',
                3 => 'We would love to reconnect you — invoice {{invoice_number}} outstanding',
                4 => 'Your DishNet connection is waiting — {{amount}} to restore',
                5 => 'Still here for you — let\'s resolve your DishNet account',
                6 => 'Final opportunity to restore your DishNet account',
                7 => 'Your DishNet account needs attention — {{amount}} on {{invoice_number}}',
                8 => 'Outstanding {{amount}} — please contact our accounts team',
                // v4.21.66: Stage 9 — monthly recurring for very long overdue. Soft tone,
                // acknowledges time has passed, offers to discuss settlement.
                9 => 'Monthly reminder: {{amount}} outstanding on {{invoice_number}}',
            ];
            $tpl = $defaults[$stage] ?? 'Restore your DishNet service — invoice {{invoice_number}} ({{amount}})';
        }
        return _oel_replace($tpl, ['invoice_number'=>$invNum,'amount'=>$amount,'days_overdue'=>$days]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// EMAIL HTML BUILDER — reads from kyc_config overdue_email_stages
// ═══════════════════════════════════════════════════════════════════════════
if (!function_exists('_buildEmail')) {
    function _buildEmail(int $stage, string $firstName, string $fullName, string $invNum,
                         string $amount, string $dueDate, int $days,
                         string $invoiceUrl, string $payUrl, array $cfg): string
    {
        $saved = $cfg['overdue_email_stages'][$stage] ?? $cfg['overdue_email_stages'][(string)$stage] ?? [];
        $defaults = [
            1 => [
                'para1'  => 'We noticed your DishNet service has been temporarily suspended due to an outstanding balance of {{amount}} on invoice {{invoice_number}}, which was due on {{due_date}}.',
                'para2'  => 'The good news is that your service will be restored automatically as soon as payment is received — no need to call us or wait for manual activation. Simply pay online using the link below.',
                'cta'    => 'Pay {{amount}} & Reconnect Now',
                'footer' => 'If you have already made payment, please send us proof and we will verify immediately. For help, reply to this email or WhatsApp us at {{accounts_phone}}.',
            ],
            2 => [
                'para1'  => 'We are following up on invoice {{invoice_number}} for {{amount}}, which has been outstanding for {{days_overdue}} days. Your DishNet service remains suspended while this balance is unpaid.',
                'para2'  => 'We understand life gets busy — settling this is quick and easy. Once your payment is confirmed, your service is restored automatically, right away. If you need to discuss a payment arrangement, just reply to this email.',
                'cta'    => 'Pay {{amount}} — Instant Reconnection',
                'footer' => 'Questions? Reach our accounts team at {{accounts_phone}} or {{accounts_email}} — we are happy to help.',
            ],
            3 => [
                'para1'  => 'It has been {{days_overdue}} days since invoice {{invoice_number}} for {{amount}} became due, and your DishNet service is still suspended. We genuinely miss having you connected.',
                'para2'  => 'Settling this balance will get your internet back on immediately and automatically — no delays, no extra charges. We want to make this as easy as possible for you. Please reply to this email if you need any assistance or would like to discuss options.',
                'cta'    => 'Settle {{amount}} — Get Back Online',
                'footer' => 'We value your loyalty to DishNet and look forward to reconnecting you. Call or WhatsApp us at {{accounts_phone}} — we are here to help.',
            ],
            4 => [
                'para1'  => 'We are reaching out again regarding invoice {{invoice_number}} for {{amount}}, now {{days_overdue}} days overdue. Your DishNet connection is ready and waiting — we just need to receive your payment.',
                'para2'  => 'Once payment is received, your service reconnects automatically — no need to contact us. We would love to get you back online. Please reach out if there is anything we can do to help you resolve this.',
                'cta'    => 'Pay Now — Reconnect Instantly',
                'footer' => 'Our accounts team is available Monday to Friday at {{accounts_phone}} or {{accounts_email}}. We are always willing to discuss your situation.',
            ],
            5 => [
                'para1'  => 'Your DishNet account has had an outstanding balance of {{amount}} on invoice {{invoice_number}} for {{days_overdue}} days. We want you to know that we are still here and your connection can be restored.',
                'para2'  => 'Paying this balance is the only step needed to get your internet back on. The reconnection happens automatically, the moment payment is confirmed. Please get in touch with us — we want to find a way to resolve this that works for you.',
                'cta'    => 'Restore My Connection',
                'footer' => 'Please contact our accounts team at {{accounts_phone}} or {{accounts_email}} so we can assist you directly.',
            ],
            6 => [
                'para1'  => 'This is our final automated message regarding invoice {{invoice_number}} for {{amount}}, which has been outstanding for {{days_overdue}} days. Your DishNet service can still be restored.',
                'para2'  => 'After this message, your account will be handled directly by our accounts team on a case-by-case basis. We strongly encourage you to contact us before that happens — we are willing to discuss your situation and find a solution together. Your connection is worth saving.',
                'cta'    => 'Contact Accounts Team',
                'footer' => 'Call or WhatsApp: {{accounts_phone}} · Email: {{accounts_email}} · We are available Monday to Friday, 8 AM – 5 PM.',
            ],
            // v4.21.66: Stages 7-9 — previously the cron had subjects for 1-6 only,
            // bodies for 1-6 only. Stages 7 (Day 120-179) and 8 (Day 180-209) used
            // to fall back to template #1, which was wrong messaging. Now have
            // their own copy. Stage 9 is the new perpetual monthly reminder.
            7 => [
                'para1'  => 'We are following up on invoice {{invoice_number}} for {{amount}}, which has now been unpaid for {{days_overdue}} days. We have not heard from you despite several previous messages.',
                'para2'  => 'We genuinely want to find a resolution. If there is a reason we should know about — financial hardship, billing dispute, contact details changed — please reach out so we can help. Our team is open to discussing payment arrangements.',
                'cta'    => 'Reply to discuss your account',
                'footer' => 'WhatsApp or call our accounts team at {{accounts_phone}} · Email: {{accounts_email}}.',
            ],
            8 => [
                'para1'  => 'Invoice {{invoice_number}} for {{amount}} has been outstanding for {{days_overdue}} days. Your DishNet account is now in long-overdue status.',
                'para2'  => 'We will continue to send you a brief reminder once a month until this is resolved. If you would like to discuss a payment plan, settle a portion of the balance, or dispute any of the charges, please contact our accounts team. We would much rather hear from you than keep sending reminders.',
                'cta'    => 'Contact Accounts',
                'footer' => 'WhatsApp: {{accounts_phone}} · Email: {{accounts_email}}',
            ],
            9 => [
                'para1'  => 'This is our monthly reminder about invoice {{invoice_number}} for {{amount}}, which has been outstanding for {{days_overdue}} days.',
                'para2'  => 'We understand life and business circumstances change. If you would like to settle this balance — in full or in installments — or if there is a reason we should know about, please reach out. A short reply or WhatsApp message is all it takes to start a conversation.',
                'cta'    => 'Reach out to settle',
                'footer' => 'WhatsApp: {{accounts_phone}} · Email: {{accounts_email}} · Monday-Friday, 8 AM – 5 PM',
            ],
        ];
        $def = $defaults[$stage] ?? $defaults[1];
        $para1 = $saved['para1']  ?? $def['para1'];
        $para2 = $saved['para2']  ?? $def['para2'];
        $cta   = $saved['cta']    ?? $def['cta'];
        $foot  = $saved['footer'] ?? $def['footer'];

        $fromName  = $cfg['overdue_email_from_name']      ?? 'DishNet Accounts';
        $acctPhone = $cfg['overdue_email_phone']           ?? '+211 921 443 009';
        $acctEmail = $cfg['overdue_email_accounts_email']  ?? 'accounts@dishnetafrica.com';

        $vars = ['first_name'=>$firstName,'full_name'=>$fullName,'invoice_number'=>$invNum,
                 'amount'=>$amount,'due_date'=>$dueDate,'days_overdue'=>$days,
                 'invoice_url'=>$invoiceUrl,'accounts_phone'=>$acctPhone,'accounts_email'=>$acctEmail];

        $colors=[1=>'#2563eb',2=>'#2563eb',3=>'#d97706',4=>'#dc2626',5=>'#dc2626',6=>'#7f1d1d',7=>'#7f1d1d',8=>'#475569',9=>'#475569'];
        $col=$colors[$stage]??'#2563eb';

        $p1  = _oel_replace($para1, $vars);
        $p2  = _oel_replace($para2, $vars);
        $ft  = _oel_replace($foot,  $vars);
        $ctaT= _oel_replace($cta,   $vars);

        return '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>body{margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;}
.wrap{max-width:600px;margin:30px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,.08);}
.hdr{background:'.$col.';padding:28px 32px;color:#fff;}.hdr h1{margin:0;font-size:20px;font-weight:800;}.hdr p{margin:6px 0 0;font-size:13px;opacity:.85;}
.bd{padding:32px;}p{color:#374151;line-height:1.7;font-size:14px;margin:0 0 16px;}
.inv{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin:20px 0;}
.inv table{width:100%;border-collapse:collapse;}.inv td{padding:6px 0;font-size:14px;color:#374151;}.inv .v{font-weight:700;text-align:right;}
.btn{display:inline-block;background:'.$col.';color:#fff !important;padding:13px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;margin:8px 0 20px;}
.ft{background:#f8fafc;padding:20px 32px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;text-align:center;line-height:1.6;}
</style></head><body><div class="wrap">
  <div class="hdr"><h1>'.htmlspecialchars($fromName).'</h1><p>DishNet Africa Ltd — Accounts Department</p></div>
  <div class="bd">
    <p>Dear <strong>'.htmlspecialchars($firstName).'</strong>,</p>
    <p>'.$p1.'</p>
    <div class="inv"><table>
      <tr><td>Invoice</td><td class="v">'.htmlspecialchars($invNum).'</td></tr>
      <tr><td>Amount Due</td><td class="v" style="color:#dc2626;font-size:17px;">'.htmlspecialchars($amount).'</td></tr>
      <tr><td>Due Date</td><td class="v">'.htmlspecialchars($dueDate).'</td></tr>
      <tr><td>Days Overdue</td><td class="v">'.$days.' days</td></tr>
    </table></div>
    <p>'.$p2.'</p>
    <a href="'.htmlspecialchars($payUrl).'" class="btn">'.htmlspecialchars($ctaT).' →</a>
    <p style="font-size:13px;color:#6b7280;">'.$ft.'</p>
  </div>
  <div class="ft">DishNet Africa Ltd · Airport Road, Juba, South Sudan<br>
  📞 '.htmlspecialchars($acctPhone).' · 📧 '.htmlspecialchars($acctEmail).' · 🌐 www.dishnetafrica.com</div>
</div></body></html>';
    }
}

if (!function_exists('_oel_replace')) {
    function _oel_replace(string $text, array $vars): string {
        foreach ($vars as $k=>$v) $text=str_replace('{{'.$k.'}}', (string)$v, $text);
        return $text;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// WHATSAPP MESSAGE BUILDER
// ═══════════════════════════════════════════════════════════════════════════
if (!function_exists('_buildWhatsApp')) {
    function _buildWhatsApp(int $stage, string $firstName, string $invNum, string $amount,
                            string $dueDate, int $days, string $invoiceUrl): string
    {
        // Stage 3 = Day 45 WhatsApp (service suspended ~45 days)
        if ($stage === 3) {
            return "Hi {$firstName} 👋\n\n"
                 . "Your DishNet service is currently suspended due to an outstanding balance of *{$amount}* on invoice *{$invNum}*.\n\n"
                 . "The good news — paying this amount restores your internet *automatically and immediately*. No need to call us.\n\n"
                 . "Pay online here:\n{$invoiceUrl}\n\n"
                 . "If you've already paid, simply send us your payment confirmation and we'll verify right away. 😊\n\n"
                 . "— DishNet Accounts · +211 921 443 009";
        }
        // Stage 5 = Day 75 WhatsApp (service suspended ~75 days)
        if ($stage === 5) {
            return "Hi {$firstName},\n\n"
                 . "We're reaching out again about your suspended DishNet service. Invoice *{$invNum}* for *{$amount}* has been outstanding for *{$days} days*.\n\n"
                 . "We genuinely want to get you back online. Settling this balance is all it takes — your service reconnects automatically the moment payment is confirmed.\n\n"
                 . "Pay here: {$invoiceUrl}\n\n"
                 . "Or reply to this message and we'll help you sort it out. We're here for you.\n\n"
                 . "— DishNet Accounts · +211 921 443 009";
        }
        // v4.21.66: Stage 9 = monthly recurring (Day 210+). Soft, non-pressured tone.
        if ($stage === 9) {
            return "Hi {$firstName},\n\n"
                 . "Just our monthly note about invoice *{$invNum}* — *{$amount}* outstanding ({$days} days).\n\n"
                 . "We understand things change. If you'd like to settle this — full or partial — or if there's something we should know, please reply or call us. No pressure, just keeping the door open.\n\n"
                 . "— DishNet Accounts · +211 921 443 009";
        }
        // v4.21.67: All other stages now have proper WhatsApp copy (was generic
        // before). Email-only stages (1, 2, 4, 6, 7, 8) when sent via bulk-send
        // get a WA companion message in the same tone as the email.
        if ($stage === 1 || $stage === 2) {
            return "Hi {$firstName},\n\n"
                 . "Your DishNet service is suspended — invoice *{$invNum}* for *{$amount}* is outstanding ({$days} days).\n\n"
                 . "Pay online to restore service automatically:\n{$invoiceUrl}\n\n"
                 . "— DishNet Accounts · +211 921 443 009";
        }
        if ($stage === 4) {
            return "Hi {$firstName},\n\n"
                 . "Friendly reminder — invoice *{$invNum}* for *{$amount}* has been overdue for *{$days} days*. Your DishNet service is suspended until this is settled.\n\n"
                 . "Settle here: {$invoiceUrl}\n\n"
                 . "Or reply if you'd like to discuss a payment plan.\n\n"
                 . "— DishNet Accounts · +211 921 443 009";
        }
        if ($stage === 6 || $stage === 7) {
            return "Hi {$firstName},\n\n"
                 . "Invoice *{$invNum}* for *{$amount}* has been unpaid for *{$days} days*. We genuinely want to resolve this with you.\n\n"
                 . "If there's a reason — billing dispute, financial hardship, contact change — please reply so we can help. Otherwise, settle here: {$invoiceUrl}\n\n"
                 . "— DishNet Accounts · +211 921 443 009";
        }
        if ($stage === 8) {
            return "Hi {$firstName},\n\n"
                 . "Long-overdue follow-up on invoice *{$invNum}* — *{$amount}* ({$days} days). We will keep a brief monthly check-in until resolved.\n\n"
                 . "Open to discussing payment plans or partial settlement: {$invoiceUrl}\n\n"
                 . "— DishNet Accounts · +211 921 443 009";
        }
        // Catch-all
        return "Hi {$firstName},\n\n"
             . "We're reaching out about invoice *{$invNum}* for *{$amount}* ({$days} days overdue).\n\n"
             . "Pay here: {$invoiceUrl}\n\n"
             . "Or reply for help.\n\n"
             . "— DishNet Accounts · +211 921 443 009";
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SMTP HELPERS
// ═══════════════════════════════════════════════════════════════════════════
if (!function_exists('_sendEmail')) {
    function _sendEmail(array $smtp, string $to, string $subject, string $html, string &$error): bool
    {
        $msg = "From: DishNet Accounts <{$smtp['from']}>\r\n"
             . "Reply-To: " . ($smtp['reply_to'] ?? $smtp['from']) . "\r\n"
             . "To: {$to}\r\n"
             . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: quoted-printable\r\n"
             . "Date: " . date('r') . "\r\n"
             . "Message-ID: <" . uniqid('dnov_') . "@" . gethostname() . ">\r\n"
             . "X-Mailer: DishNet-Overdue-Notify\r\n"
             . "\r\n"
             . quoted_printable_encode($html);
        return _rawSmtp($smtp, $to, $msg, $error);
    }
}

if (!function_exists('_rawSmtp')) {
    function _rawSmtp(array $s, string $to, string $message, string &$error): bool
    {
        try {
            $sock = @fsockopen(($s['enc'] === 'ssl' ? 'ssl://' : '') . $s['host'], $s['port'], $errno, $errstr, 15);
            if (!$sock) { $error = "Connect failed: {$errstr}"; return false; }
            stream_set_timeout($sock, 15);
            $read = function() use ($sock) { return fgets($sock, 512); };
            $write = function($cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };
            $r = $read(); if (substr($r,0,3) !== '220') { $error="Not ready:{$r}"; fclose($sock); return false; }
            $write("EHLO " . gethostname());
            while (($l=fgets($sock,512))!==false){if(substr($l,3,1)===' ')break;}
            if ($s['enc']==='tls') {
                $write("STARTTLS"); $r=$read();
                if(substr($r,0,3)!=='220'){$error="STARTTLS failed";fclose($sock);return false;}
                @stream_socket_enable_crypto($sock,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                $write("EHLO ".gethostname());
                while(($l=fgets($sock,512))!==false){if(substr($l,3,1)===' ')break;}
            }
            $write("AUTH LOGIN"); $read();
            $write(base64_encode($s['user'])); $read();
            $write(base64_encode($s['pass'])); $r=$read();
            if(substr($r,0,3)!=='235'){$error="Auth failed:{$r}";fclose($sock);return false;}
            $write("MAIL FROM:<{$s['from']}>"); $read();
            $write("RCPT TO:<{$to}>"); $r=$read();
            if(substr($r,0,3)!=='250'){$error="RCPT rejected:{$r}";fclose($sock);return false;}
            $write("DATA"); $read();
            fwrite($sock, $message . "\r\n.\r\n"); $r=$read();
            if(substr($r,0,3)!=='250'){$error="DATA rejected:{$r}";fclose($sock);return false;}
            $write("QUIT"); fclose($sock); return true;
        } catch (\Throwable $e) { $error=$e->getMessage(); return false; }
    }
}

if (!function_exists('_getSmtpSettings')) {
    function _getSmtpSettings($store, array $config): ?array
    {
        // v4.21.70: Read the actual file via file_get_contents(), NOT
        // $store->load(). SqliteStore::load() reads from a blob table; the
        // SMTP Diagnostic tab writes the file directly via file_put_contents(),
        // so the SQLite blob is empty/stale and store->load() returns [] even
        // when email_settings.json exists on disk and contains valid SMTP.
        // This is why the cron reported "No SMTP configured" while the
        // workbench banner correctly showed "SMTP healthy" (MailService also
        // reads the file directly).
        try {
            $dataDir = $store && method_exists($store, 'getDataDir') ? $store->getDataDir() : '';
            if ($dataDir !== '') {
                $efile = $dataDir . '/email_settings.json';
                if (file_exists($efile)) {
                    $eRaw = (string)@file_get_contents($efile);
                    $eFile = json_decode($eRaw, true);
                    if (is_array($eFile) && !empty(trim((string)($eFile['smtp_host'] ?? '')))) {
                        return [
                            'host'     => trim((string)$eFile['smtp_host']),
                            'port'     => (int)($eFile['smtp_port'] ?? 587) ?: 587,
                            'user'     => (string)($eFile['smtp_user'] ?? ''),
                            'pass'     => (string)($eFile['smtp_pass'] ?? ''),
                            'enc'      => (string)($eFile['smtp_enc'] ?? 'tls'),
                            'from'     => (string)($eFile['smtp_from'] ?? '') ?: (string)($eFile['smtp_user'] ?? ''),
                            'reply_to' => (string)($eFile['smtp_reply_to'] ?? $eFile['smtp_from'] ?? $eFile['smtp_user'] ?? ''),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {}

        // Fallback: kyc_config smtp_* keys (legacy)
        $host = trim($config['smtp_host'] ?? '');
        if ($host) {
            return ['host'=>$host,'port'=>(int)($config['smtp_port']??587),
                    'user'=>$config['smtp_user']??'','pass'=>$config['smtp_pass']??'',
                    'enc'=>$config['smtp_enc']??'tls','from'=>$config['smtp_from']??'',
                    'reply_to'=>$config['smtp_reply_to']??$config['smtp_from']??''];
        }
        return null;
    }
}
