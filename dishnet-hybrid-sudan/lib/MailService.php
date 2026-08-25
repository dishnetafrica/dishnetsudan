<?php
/**
 * MailService — single source of truth for outbound email.
 *
 * v4.21.8 — replaces ad-hoc SMTP config reading scattered across
 * api_customer_app.php (OTP email), DailyReportService.php (daily reports),
 * cron_overdue_email.php (dunning), and public.php (one-off sends).
 *
 * Why a single service:
 *
 *   1. UCRM already has working SMTP configured (it sends invoice emails
 *      to customers daily). We should reuse that, not require admin to
 *      configure plugin-level smtp_* keys redundantly.
 *
 *   2. When SMTP password changes, admin updates it in UCRM once — every
 *      caller of MailService picks it up automatically. Previously each
 *      caller cached its own copy.
 *
 *   3. When troubleshooting "why aren't emails arriving", there's exactly
 *      one place to look. The smtp_diagnostic admin tab calls MailService
 *      so the diagnostic results match what real sends do.
 *
 * Source priority (highest → lowest):
 *   - UCRM /api/v1.0/settings (the mailerHost/mailerPort/etc. set in
 *     UCRM Admin > System > Settings > Mailer)
 *
 * That's the only source. No plugin config keys, no env vars, no hard-coded
 * defaults. If UCRM SMTP isn't configured, send fails with a clear error
 * pointing admin to UCRM settings — rather than silently using an empty
 * config that times out later.
 */
declare(strict_types=1);

class MailService
{
    /** @var array Last-resolved config, cached for the request */
    private $cfg = null;
    private $cfgError = '';
    private $dataDir;

    /**
     * @param string $dataDir Plugin data dir (used to find ucrm.json one
     *                        or two levels up). Convention matches existing
     *                        code in DailyReportService and cron_overdue_email.
     */
    public function __construct(string $dataDir)
    {
        $this->dataDir = $dataDir;
    }

    /**
     * Returns ['host'=>, 'port'=>, 'user'=>, 'pass'=>, 'enc'=>, 'from'=>].
     * Cached per instance. On miss, queries UCRM API /settings.
     *
     * Returns empty array on failure; lastError() has the reason.
     */
    public function getConfig(): array
    {
        if ($this->cfg !== null) return $this->cfg;

        // v4.21.13: Honor use_ucrm_email toggle from email_settings.json.
        // Mirrors Starlink Finance flow:
        //   - toggle ON  → try UCRM API first, fall back to plugin SMTP
        //   - toggle OFF → use plugin SMTP directly
        $emailFile = $this->dataDir . '/email_settings.json';
        $ec = file_exists($emailFile)
            ? (json_decode((string)@file_get_contents($emailFile), true) ?: [])
            : [];
        $useUcrm = !empty($ec['use_ucrm_email']);

        // Build the plugin-SMTP config (if filled in) — used as primary or fallback
        $pluginCfg = null;
        if (!empty(trim($ec['smtp_host'] ?? ''))) {
            $pluginCfg = [
                'host' => trim($ec['smtp_host'] ?? ''),
                'port' => (int)($ec['smtp_port'] ?? 587) ?: 587,
                'user' => trim($ec['smtp_user'] ?? ''),
                'pass' => trim($ec['smtp_pass'] ?? ''),
                'enc'  => trim($ec['smtp_enc']  ?? 'tls'),
                'from' => trim($ec['smtp_from'] ?? '') ?: trim($ec['smtp_user'] ?? ''),
                '_source' => 'plugin',
            ];
        }

        // Path A: toggle ON → try UCRM API first
        if ($useUcrm) {
            $ucrmCfg = $this->tryReadUcrmMailerSettings();
            if ($ucrmCfg !== null) {
                // UCRM API responded with mailer settings — use them
                $this->cfg = $ucrmCfg;
                return $ucrmCfg;
            }
            // UCRM API failed → fall back to plugin SMTP if configured
            if ($pluginCfg !== null) {
                $pluginCfg['_source'] = 'plugin (UCRM API fallback)';
                $this->cfg = $pluginCfg;
                return $pluginCfg;
            }
            // Neither worked
            $this->cfgError = 'UCRM API failed (' . ($this->cfgError ?: 'unknown') . ') AND no plugin SMTP fallback configured. Go to Settings → System → Email Settings.';
            $this->cfg = [];
            return [];
        }

        // Path B: toggle OFF → use plugin SMTP directly
        if ($pluginCfg !== null) {
            $this->cfg = $pluginCfg;
            return $pluginCfg;
        }

        $this->cfgError = 'No SMTP configured. Go to Settings → System → Email Settings and fill in the SMTP host/port/user/password.';
        $this->cfg = [];
        return [];
    }

    /**
     * Attempt to read SMTP settings from UCRM /api/v1.0/settings.
     * Returns config array on success, null on failure (sets cfgError).
     * Tries multiple URL patterns to handle different UCRM/UISP install variants.
     */
    private function tryReadUcrmMailerSettings(): ?array
    {
        $ucrmFile = dirname($this->dataDir) . '/ucrm.json';
        if (!file_exists($ucrmFile)) $ucrmFile = dirname(dirname($this->dataDir)) . '/ucrm.json';
        if (!file_exists($ucrmFile)) {
            $this->cfgError = 'ucrm.json not found';
            return null;
        }

        $ucrm = json_decode((string)@file_get_contents($ucrmFile), true) ?: [];
        $localUrl  = trim($ucrm['ucrmLocalUrl']  ?? '');
        $publicUrl = trim($ucrm['ucrmPublicUrl'] ?? '');
        $appKey    = $ucrm['pluginAppKey'] ?? '';

        if (!$appKey || (!$localUrl && !$publicUrl)) {
            $this->cfgError = 'ucrm.json missing pluginAppKey or URLs';
            return null;
        }

        $candidates = [];
        if ($localUrl)  $candidates[] = rtrim($localUrl, '/')  . '/api/v1.0/settings';
        if ($publicUrl) $candidates[] = rtrim($publicUrl, '/') . '/../api/v1.0/settings';
        if ($publicUrl) $candidates[] = rtrim($publicUrl, '/') . '/api/v1.0/settings';

        $resp = null; $httpCode = 0; $curlErr = ''; $triedUrl = '';
        foreach ($candidates as $url) {
            $triedUrl = $url;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => (getenv('UCRM_SKIP_SSL_VERIFY') === '1' ? false : true),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER     => ['X-Auth-App-Key: ' . $appKey],
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if ($httpCode === 200) break;
        }

        if ($curlErr) {
            $this->cfgError = "UCRM /settings curl error: {$curlErr}";
            return null;
        }
        if ($httpCode !== 200) {
            $this->cfgError = "UCRM /settings returned HTTP {$httpCode} (last URL: {$triedUrl})";
            return null;
        }

        $settings = json_decode((string)$resp, true);
        if (!is_array($settings)) {
            $this->cfgError = 'UCRM /settings returned non-JSON';
            return null;
        }

        // Normalize: UCRM returns either {key:value} or [{key,value}] depending on version
        $kv = [];
        if (isset($settings[0]) && is_array($settings[0]) && array_key_exists('key', $settings[0])) {
            foreach ($settings as $s) {
                if (isset($s['key'])) $kv[$s['key']] = $s['value'] ?? '';
            }
        } else {
            $kv = $settings;
        }

        $cfg = [
            'host' => trim((string)($kv['mailerHost'] ?? $kv['MAILER_HOST'] ?? '')),
            'port' => (int)($kv['mailerPort'] ?? $kv['MAILER_PORT'] ?? 587) ?: 587,
            'user' => trim((string)($kv['mailerUsername'] ?? $kv['MAILER_USERNAME'] ?? '')),
            'pass' => trim((string)($kv['mailerPassword'] ?? $kv['MAILER_PASSWORD'] ?? '')),
            'enc'  => trim((string)($kv['mailerEncryption'] ?? $kv['MAILER_ENCRYPTION'] ?? 'tls')),
            'from' => trim((string)($kv['mailerSenderAddress'] ?? $kv['MAILER_SENDER_ADDRESS'] ?? '')),
            '_source' => 'ucrm',
        ];
        if ($cfg['from'] === '') $cfg['from'] = $cfg['user'];

        if ($cfg['host'] === '') {
            $this->cfgError = 'UCRM /settings returned 200 but no mailerHost set';
            return null;
        }

        return $cfg;
    }

    public function lastError(): string
    {
        return $this->cfgError;
    }

    /**
     * Send a multipart (HTML + plain-text) email.
     *
     * @param string $toEmail   Recipient email (validated by caller).
     * @param string $toName    Display name for the To: header (optional).
     * @param string $subject   Subject line (raw, no encoding needed for ASCII).
     * @param string $htmlBody  Full HTML body (just the body content; we
     *                          wrap with proper email-safe doctype).
     * @param string $textBody  Plain-text fallback for clients that don't
     *                          render HTML. Required — if empty, we strip
     *                          tags from $htmlBody.
     * @param array  $extraHeaders  Optional extra MIME headers (e.g.
     *                              ['Reply-To' => 'support@dishnetafrica.com']).
     *
     * @return array{ok:bool, error?:string, log?:array}
     *   On success: ['ok' => true, 'log' => [...steps...]]
     *   On failure: ['ok' => false, 'error' => 'human readable', 'log' => [...]]
     */
    public function send(string $toEmail, string $toName, string $subject,
                         string $htmlBody, string $textBody = '',
                         array $extraHeaders = []): array
    {
        $log = [];
        $cfg = $this->getConfig();
        if (empty($cfg) || $cfg['host'] === '') {
            return ['ok' => false, 'error' => $this->cfgError ?: 'No SMTP configured', 'log' => [
                ['step' => 'config', 'ok' => false, 'msg' => $this->cfgError]
            ]];
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid recipient email', 'log' => [
                ['step' => 'validate', 'ok' => false, 'msg' => 'Bad email format']
            ]];
        }

        if ($textBody === '') {
            $textBody = trim(html_entity_decode(strip_tags(
                preg_replace('/<br\s*\/?>/i', "\n", $htmlBody) ?? ''
            ), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Build multipart/alternative MIME
        $boundary = '=_DishNet_' . bin2hex(random_bytes(8));
        $fromHeader = $cfg['from'];
        // If sender doesn't already have a display name, add "DishNet Africa"
        if (strpos($fromHeader, '<') === false) {
            $fromHeader = 'DishNet Africa <' . $cfg['from'] . '>';
        }
        $toHeader = $toName !== ''
            ? sprintf('"%s" <%s>', addslashes($toName), $toEmail)
            : $toEmail;

        $headers = [
            'From' => $fromHeader,
            'To' => $toHeader,
            'Subject' => $subject,
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
            'Date' => date('r'),
            'Message-ID' => '<dn_' . bin2hex(random_bytes(8)) . '@' . (gethostname() ?: 'dishnetafrica.com') . '>',
            'X-Mailer' => 'DishNet-Hybrid/4.21.8',
        ];
        foreach ($extraHeaders as $k => $v) $headers[$k] = $v;

        $headerStr = '';
        foreach ($headers as $k => $v) $headerStr .= "{$k}: {$v}\r\n";

        $mimeBody =
            "--{$boundary}\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: 8bit\r\n\r\n"
          . $textBody . "\r\n\r\n"
          . "--{$boundary}\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: 8bit\r\n\r\n"
          . $htmlBody . "\r\n\r\n"
          . "--{$boundary}--\r\n";

        $rawMessage = $headerStr . "\r\n" . $mimeBody;

        // Open SMTP
        $errno = 0; $errstr = '';
        $transport = ($cfg['enc'] === 'ssl') ? 'ssl://' . $cfg['host'] : $cfg['host'];
        $started = microtime(true);
        $fp = @fsockopen($transport, $cfg['port'], $errno, $errstr, 15);
        $connectMs = (int)((microtime(true) - $started) * 1000);
        if (!$fp) {
            $log[] = ['step' => 'tcp_connect', 'ok' => false, 'msg' => "fail in {$connectMs}ms: {$errstr} ({$errno})"];
            return ['ok' => false, 'error' => "TCP connect failed: {$errstr}", 'log' => $log];
        }
        $log[] = ['step' => 'tcp_connect', 'ok' => true, 'msg' => "connected in {$connectMs}ms"];

        stream_set_timeout($fp, 15);
        $read = function() use ($fp) {
            $r = '';
            while (($l = fgets($fp, 515)) !== false) {
                $r .= $l;
                if (strlen($l) >= 4 && substr($l, 3, 1) === ' ') break;
            }
            return rtrim($r);
        };
        $write = function($c) use ($fp) { fputs($fp, $c . "\r\n"); };

        $expect = function($code, $stepName) use (&$log, $read, $fp) {
            $resp = $read();
            $ok = (substr($resp, 0, 3) === $code);
            $log[] = ['step' => $stepName, 'ok' => $ok, 'msg' => $resp];
            if (!$ok) @fclose($fp);
            return $ok;
        };

        if (!$expect('220', 'greeting')) return ['ok' => false, 'error' => 'Server greeting failed', 'log' => $log];

        $hn = gethostname() ?: 'localhost';
        $write("EHLO {$hn}");
        if (!$expect('250', 'ehlo')) return ['ok' => false, 'error' => 'EHLO rejected', 'log' => $log];

        if ($cfg['enc'] === 'tls') {
            $write('STARTTLS');
            if (!$expect('220', 'starttls')) return ['ok' => false, 'error' => 'STARTTLS rejected', 'log' => $log];
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $log[] = ['step' => 'tls_handshake', 'ok' => false, 'msg' => 'crypto enable failed'];
                @fclose($fp);
                return ['ok' => false, 'error' => 'TLS handshake failed', 'log' => $log];
            }
            $log[] = ['step' => 'tls_handshake', 'ok' => true, 'msg' => 'TLS established'];
            $write("EHLO {$hn}"); $read();
        }

        if ($cfg['user'] !== '' && $cfg['pass'] !== '') {
            $write('AUTH LOGIN');
            if (!$expect('334', 'auth_init')) return ['ok' => false, 'error' => 'AUTH LOGIN rejected', 'log' => $log];
            $write(base64_encode($cfg['user']));
            if (!$expect('334', 'auth_user')) return ['ok' => false, 'error' => 'Username rejected', 'log' => $log];
            $write(base64_encode($cfg['pass']));
            if (!$expect('235', 'auth_pass')) return ['ok' => false, 'error' => 'Password rejected — check UCRM mailer credentials', 'log' => $log];
        }

        // MAIL FROM
        $envelopeFrom = $cfg['from'];
        if (preg_match('/<(.+?)>/', $envelopeFrom, $m)) $envelopeFrom = $m[1];
        $write("MAIL FROM:<{$envelopeFrom}>");
        if (!$expect('250', 'mail_from')) return ['ok' => false, 'error' => 'MAIL FROM rejected', 'log' => $log];

        $write("RCPT TO:<{$toEmail}>");
        if (!$expect('250', 'rcpt_to')) return ['ok' => false, 'error' => 'RCPT TO rejected — recipient invalid or relay denied', 'log' => $log];

        $write('DATA');
        if (!$expect('354', 'data_init')) return ['ok' => false, 'error' => 'DATA rejected', 'log' => $log];

        // Dot-stuff lines starting with .
        $dotStuffed = preg_replace('/^\./m', '..', $rawMessage);
        $write($dotStuffed . "\r\n.");
        if (!$expect('250', 'data_accept')) return ['ok' => false, 'error' => 'Message body rejected', 'log' => $log];

        $write('QUIT');
        @$read();
        @fclose($fp);

        $log[] = ['step' => 'sent', 'ok' => true, 'msg' => "Email queued at SMTP server for {$toEmail}"];
        return ['ok' => true, 'log' => $log];
    }
}
