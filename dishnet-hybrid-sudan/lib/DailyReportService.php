<?php
declare(strict_types=1);

/**
 * DailyReportService — morning cashbook summary + CSV for all projects.
 * Sends via SMTP with CSV attachment at 07:00 daily.
 */
class DailyReportService
{
    private $cashbook; // CashbookService
    private string $dataDir;
    private array $config;

    const PROJECTS = ['dishnet', '4g', 'bluecard'];
    const PROJECT_NAMES = [
        'dishnet'  => 'DishNet Africa',
        '4g'       => 'DishNet 4G',
        'bluecard' => 'BlueCard',
    ];
    const DEFAULT_RECIPIENTS = 'accounts@dishnetafrica.com,bhavin@dishnetafrica.com';

    public function __construct($cashbook, string $dataDir, array $config = [])
    {
        $this->cashbook = $cashbook;
        $this->dataDir  = rtrim($dataDir, '/');
        $this->config   = $config;
    }

    public function sendDailyReport(string $date = ''): array
    {
        $result = ['sent' => false, 'error' => '', 'stats' => []];
        if (!$date) $date = date('Y-m-d', strtotime('-1 day'));

        $allEntries = [];
        $projectStats = [];

        foreach (self::PROJECTS as $project) {
            $entries = $this->cashbook->getEntries([
                'project'   => $project,
                'date_from' => $date,
                'date_to'   => $date,
                'limit'     => 9999,
                'offset'    => 0,
            ]);

            $totalIn = $totalOut = 0;
            $totalInSSP = $totalOutSSP = 0;
            foreach ($entries as $e) {
                $amt    = (float)($e['amount'] ?? 0);
                $sspAmt = (float)($e['ssp_amount'] ?? 0);
                if (($e['direction'] ?? '') === 'in') {
                    $totalIn += $amt;
                    $totalInSSP += $sspAmt;
                } else {
                    $totalOut += $amt;
                    $totalOutSSP += $sspAmt;
                }
            }

            $allEntries[$project] = $entries;
            $projectStats[$project] = [
                'name'        => self::PROJECT_NAMES[$project] ?? $project,
                'entries'     => count($entries),
                'total_in'    => $totalIn,
                'total_out'   => $totalOut,
                'net'         => $totalIn - $totalOut,
                'ssp_in'      => $totalInSSP,
                'ssp_out'     => $totalOutSSP,
                'ssp_net'     => $totalInSSP - $totalOutSSP,
            ];
        }

        $result['stats'] = $projectStats;

        $csvContent  = $this->generateCsv($allEntries, $date);
        $csvFilename = "DishNet-Cashbook-{$date}.csv";
        $htmlBody    = $this->generateHtml($projectStats, $date);

        $recipients = trim($this->config['daily_report_recipients'] ?? self::DEFAULT_RECIPIENTS);
        $subject    = "DishNet Daily Cashbook Report - {$date}";

        $sent = $this->sendEmailWithAttachment(
            $recipients, $subject, $htmlBody,
            $csvContent, $csvFilename, 'text/csv',
            $result['error']
        );
        $result['sent'] = $sent;

        // Log
        $logFile = $this->dataDir . '/daily_report_log.json';
        $log = file_exists($logFile) ? (json_decode(file_get_contents($logFile), true) ?: []) : [];
        $log[] = [
            'date' => $date, 'sent' => $sent, 'sent_at' => date('Y-m-d H:i:s'),
            'recipients' => $recipients, 'error' => $result['error'],
            'stats' => $projectStats,
        ];
        if (count($log) > 90) $log = array_slice($log, -90);
        file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT));

        return $result;
    }

    private function generateCsv(array $allEntries, string $date): string
    {
        $csv = "DishNet Daily Cashbook Report - {$date}\n\n";

        foreach (self::PROJECTS as $project) {
            $entries = $allEntries[$project] ?? [];
            $pName   = self::PROJECT_NAMES[$project] ?? $project;

            $csv .= "PROJECT: {$pName}\n";
            $csv .= "SR,Date,Time,Direction,Amount (USD),Amount (SSP),Currency,Category,Sub-Category,Person,Description,Validation Status,CRM Ref\n";

            if (empty($entries)) {
                $csv .= "No entries\n";
            } else {
                $tIn = $tOut = $tInSSP = $tOutSSP = 0;
                foreach ($entries as $e) {
                    $sr       = $e['sr'] ?? '';
                    $eDate    = $e['date'] ?? '';
                    $eTime    = $e['created_at'] ?? '';
                    $dir      = $e['direction'] ?? '';
                    $amt      = number_format((float)($e['amount'] ?? 0), 2);
                    $sspAmt   = number_format((float)($e['ssp_amount'] ?? 0), 0);
                    $currency = $e['currency'] ?? 'USD';
                    $cat      = str_replace(',', ';', $e['category'] ?? '');
                    $subcat   = str_replace(',', ';', $e['subcategory'] ?? '');
                    $person   = str_replace(',', ';', $e['person'] ?? '');
                    $desc     = str_replace(',', ';', $e['description'] ?? '');
                    $valStat  = $e['validation_status'] ?? '';
                    $crmRef   = $e['crm_payment_id'] ?? '';

                    $csv .= "{$sr},{$eDate},{$eTime},{$dir},{$amt},{$sspAmt},{$currency},{$cat},{$subcat},{$person},{$desc},{$valStat},{$crmRef}\n";

                    if ($dir === 'in') { $tIn += (float)($e['amount'] ?? 0); $tInSSP += (float)($e['ssp_amount'] ?? 0); }
                    else { $tOut += (float)($e['amount'] ?? 0); $tOutSSP += (float)($e['ssp_amount'] ?? 0); }
                }
                $csv .= ",,TOTAL IN (USD)," . number_format($tIn, 2) . "\n";
                $csv .= ",,TOTAL OUT (USD)," . number_format($tOut, 2) . "\n";
                $csv .= ",,NET (USD)," . number_format($tIn - $tOut, 2) . "\n";
                if ($tInSSP > 0 || $tOutSSP > 0) {
                    $csv .= ",,TOTAL IN (SSP)," . number_format($tInSSP, 0) . "\n";
                    $csv .= ",,TOTAL OUT (SSP)," . number_format($tOutSSP, 0) . "\n";
                    $csv .= ",,NET (SSP)," . number_format($tInSSP - $tOutSSP, 0) . "\n";
                }
            }
            $csv .= "\n";
        }
        return $csv;
    }

    private function generateHtml(array $projectStats, string $date): string
    {
        $dateFmt = date('l, d M Y', strtotime($date));
        $gIn = $gOut = $gNet = 0; $tEntries = 0;

        $rows = '';
        foreach ($projectStats as $s) {
            $gIn += $s['total_in']; $gOut += $s['total_out']; $gNet += $s['net'];
            $tEntries += $s['entries'];
            $nc = $s['net'] >= 0 ? '#059669' : '#EF4444';
            $sspLine = ($s['ssp_in'] > 0 || $s['ssp_out'] > 0)
                ? "<br><span style='font-size:11px;color:#888;'>SSP " . number_format($s['ssp_net'], 0) . "</span>" : '';
            $rows .= "<tr>"
                . "<td style='padding:10px 14px;border-bottom:1px solid #eee;font-weight:600;'>{$s['name']}</td>"
                . "<td style='padding:10px 14px;border-bottom:1px solid #eee;text-align:center;'>{$s['entries']}</td>"
                . "<td style='padding:10px 14px;border-bottom:1px solid #eee;text-align:right;color:#059669;font-weight:600;'>$" . number_format($s['total_in'], 2) . "</td>"
                . "<td style='padding:10px 14px;border-bottom:1px solid #eee;text-align:right;color:#EF4444;font-weight:600;'>$" . number_format($s['total_out'], 2) . "</td>"
                . "<td style='padding:10px 14px;border-bottom:1px solid #eee;text-align:right;color:{$nc};font-weight:700;'>$" . number_format($s['net'], 2) . "{$sspLine}</td>"
                . "</tr>";
        }

        $gnc = $gNet >= 0 ? '#059669' : '#EF4444';

        return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='font-family:Helvetica,Arial,sans-serif;margin:0;padding:0;background:#f5f5f5;'>
<div style='max-width:600px;margin:20px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);'>

<div style='background:#141414;padding:20px 24px;'>
<span style='font-size:24px;font-weight:900;color:#D41C1C;'>DishNet</span>
<div style='height:3px;width:80px;background:#D41C1C;margin-top:4px;'></div>
<div style='font-size:12px;color:#999;margin-top:6px;'>Daily Cashbook Report</div>
</div>

<div style='padding:16px 24px;background:#f8f8f8;border-bottom:1px solid #eee;'>
<span style='font-size:14px;color:#333;font-weight:600;'>{$dateFmt}</span>
<span style='float:right;font-size:12px;color:#999;'>{$tEntries} transactions</span>
</div>

<div style='padding:16px 24px;'>
<table style='width:100%;border-collapse:collapse;font-size:13px;'>
<thead><tr style='background:#f5f5f5;'>
<th style='padding:10px 14px;text-align:left;font-size:11px;color:#666;text-transform:uppercase;'>Project</th>
<th style='padding:10px 14px;text-align:center;font-size:11px;color:#666;text-transform:uppercase;'>Entries</th>
<th style='padding:10px 14px;text-align:right;font-size:11px;color:#666;text-transform:uppercase;'>Cash In</th>
<th style='padding:10px 14px;text-align:right;font-size:11px;color:#666;text-transform:uppercase;'>Cash Out</th>
<th style='padding:10px 14px;text-align:right;font-size:11px;color:#666;text-transform:uppercase;'>Net</th>
</tr></thead>
<tbody>{$rows}
<tr style='background:#f8f8f8;font-weight:800;'>
<td style='padding:12px 14px;border-top:2px solid #D41C1C;'>TOTAL</td>
<td style='padding:12px 14px;border-top:2px solid #D41C1C;text-align:center;'>{$tEntries}</td>
<td style='padding:12px 14px;border-top:2px solid #D41C1C;text-align:right;color:#059669;'>$" . number_format($gIn, 2) . "</td>
<td style='padding:12px 14px;border-top:2px solid #D41C1C;text-align:right;color:#EF4444;'>$" . number_format($gOut, 2) . "</td>
<td style='padding:12px 14px;border-top:2px solid #D41C1C;text-align:right;color:{$gnc};font-size:15px;'>$" . number_format($gNet, 2) . "</td>
</tr></tbody></table></div>

<div style='padding:14px 24px;background:#f8f8f8;border-top:1px solid #eee;font-size:11px;color:#999;'>
Full cashbook data attached as CSV<br>
Generated at " . date('H:i') . " | DishNet Africa Ltd
</div>
<div style='height:3px;background:#D41C1C;'></div>
</div></body></html>";
    }

    private function sendEmailWithAttachment(
        string $toList, string $subject, string $htmlBody,
        string $attachContent, string $attachFilename, string $attachMime,
        string &$error
    ): bool {
        // Get SMTP settings — try plugin config first, then UCRM settings
        $smtpHost = trim($this->config['smtp_host'] ?? '');
        $smtpPort = (int)($this->config['smtp_port'] ?? 587);
        $smtpUser = trim($this->config['smtp_user'] ?? '');
        $smtpPass = trim($this->config['smtp_pass'] ?? '');
        $smtpEnc  = trim($this->config['smtp_enc'] ?? 'tls');
        $fromEmail = trim($this->config['smtp_from'] ?? $smtpUser);

        if (!$smtpHost) {
            // Try UCRM SMTP
            $ucrmSmtp = $this->getUcrmSmtp();
            if ($ucrmSmtp) {
                $smtpHost  = $ucrmSmtp['host'];
                $smtpPort  = $ucrmSmtp['port'];
                $smtpUser  = $ucrmSmtp['user'];
                $smtpPass  = $ucrmSmtp['pass'];
                $smtpEnc   = $ucrmSmtp['enc'];
                $fromEmail = $ucrmSmtp['from'];
            }
        }
        if (!$smtpHost) { $error = 'No SMTP configured'; return false; }
        if (!$fromEmail) $fromEmail = $smtpUser;

        $boundary = '----=_DishNet_' . md5(uniqid((string)time()));

        $msg = "From: DishNet Africa <{$fromEmail}>\r\n"
             . "To: {$toList}\r\n"
             . "Subject: {$subject}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n"
             . "Date: " . date('r') . "\r\n"
             . "Message-ID: <" . uniqid('dnr_') . "@" . gethostname() . ">\r\n"
             . "\r\n"
             . "--{$boundary}\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 7bit\r\n\r\n"
             . $htmlBody . "\r\n\r\n"
             . "--{$boundary}\r\n"
             . "Content-Type: {$attachMime}; name=\"{$attachFilename}\"\r\n"
             . "Content-Disposition: attachment; filename=\"{$attachFilename}\"\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($attachContent)) . "\r\n"
             . "--{$boundary}--\r\n";

        return $this->rawSmtp($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpEnc, $fromEmail, $toList, $msg, $error);
    }

    private function getUcrmSmtp(): ?array
    {
        $ucrmFile = dirname($this->dataDir) . '/ucrm.json';
        if (!file_exists($ucrmFile)) $ucrmFile = dirname(dirname($this->dataDir)) . '/ucrm.json';
        if (!file_exists($ucrmFile)) return null;

        $ucrm = json_decode(file_get_contents($ucrmFile), true) ?: [];
        $apiUrl = rtrim($ucrm['ucrmPublicUrl'] ?? '', '/');
        $appKey = $ucrm['pluginAppKey'] ?? '';
        if (!$apiUrl || !$appKey) return null;

        $ch = curl_init($apiUrl . '/api/v1.0/settings');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['X-Auth-App-Key: ' . $appKey],
        ]);
        $resp = curl_exec($ch); curl_close($ch);
        $settings = json_decode($resp, true);
        if (!is_array($settings)) return null;

        $r = ['host'=>'','port'=>587,'user'=>'','pass'=>'','enc'=>'tls','from'=>''];
        foreach ($settings as $s) {
            $k = $s['key'] ?? ''; $v = $s['value'] ?? '';
            if (in_array($k, ['MAILER_HOST','mailerHost'])) $r['host'] = $v;
            if (in_array($k, ['MAILER_PORT','mailerPort'])) $r['port'] = (int)$v;
            if (in_array($k, ['MAILER_USERNAME','mailerUsername'])) $r['user'] = $v;
            if (in_array($k, ['MAILER_PASSWORD','mailerPassword'])) $r['pass'] = $v;
            if (in_array($k, ['MAILER_ENCRYPTION','mailerEncryption'])) $r['enc'] = $v;
            if (in_array($k, ['MAILER_SENDER','mailerSenderEmail'])) $r['from'] = $v;
        }
        return $r['host'] ? $r : null;
    }

    private function rawSmtp(
        string $host, int $port, string $user, string $pass,
        string $enc, string $from, string $toList, string $message, string &$error
    ): bool {
        try {
            $sock = @fsockopen(($enc === 'ssl' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 15);
            if (!$sock) { $error = "Connect failed: {$errstr}"; return false; }
            stream_set_timeout($sock, 15);

            $r = fgets($sock, 512);
            if (substr($r, 0, 3) !== '220') { $error = "Not ready: {$r}"; fclose($sock); return false; }

            fwrite($sock, "EHLO " . gethostname() . "\r\n");
            while (($l = fgets($sock, 512)) !== false) { if (substr($l, 3, 1) === ' ') break; }

            if ($enc === 'tls') {
                fwrite($sock, "STARTTLS\r\n"); $r = fgets($sock, 512);
                if (substr($r, 0, 3) !== '220') { $error = "STARTTLS failed"; fclose($sock); return false; }
                $ok = @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                if (!$ok) $ok = @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_ANY_CLIENT);
                if (!$ok) { $error = "TLS failed"; fclose($sock); return false; }
                fwrite($sock, "EHLO " . gethostname() . "\r\n");
                while (($l = fgets($sock, 512)) !== false) { if (substr($l, 3, 1) === ' ') break; }
            }

            fwrite($sock, "AUTH LOGIN\r\n"); $r = fgets($sock, 512);
            if (substr($r, 0, 3) !== '334') { $error = "AUTH rejected"; fclose($sock); return false; }
            fwrite($sock, base64_encode($user) . "\r\n"); fgets($sock, 512);
            fwrite($sock, base64_encode($pass) . "\r\n"); $r = fgets($sock, 512);
            if (substr($r, 0, 3) !== '235') { $error = "Auth failed"; fclose($sock); return false; }

            fwrite($sock, "MAIL FROM:<{$from}>\r\n"); fgets($sock, 512);
            foreach (array_filter(array_map('trim', explode(',', $toList))) as $rcpt) {
                fwrite($sock, "RCPT TO:<{$rcpt}>\r\n"); fgets($sock, 512);
            }
            fwrite($sock, "DATA\r\n"); $r = fgets($sock, 512);
            if (substr($r, 0, 3) !== '354') { $error = "DATA rejected"; fclose($sock); return false; }

            fwrite($sock, $message . "\r\n.\r\n");
            $r = fgets($sock, 512);
            fwrite($sock, "QUIT\r\n"); fclose($sock);
            if (substr($r, 0, 3) === '250') return true;
            $error = "Send failed: {$r}"; return false;
        } catch (\Exception $e) { $error = $e->getMessage(); return false; }
    }
}
