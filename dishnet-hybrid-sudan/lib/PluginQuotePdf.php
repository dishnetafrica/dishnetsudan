<?php
/**
 * PluginQuotePdf — Generates branded DishNet quote PDFs directly in plugin.
 * Uses wkhtmltopdf (already on UCRM server) to convert HTML → PDF.
 * PHP 7.4 compatible.
 */

if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}

class PluginQuotePdf
{
    private string $dataDir;
    private array  $config;

    public function __construct(string $dataDir, array $config = [])
    {
        $this->dataDir = $dataDir;
        $this->config  = $config;
    }

    /**
     * Generate a branded PDF from UCRM quote data.
     *
     * @param array $quote    UCRM quote object (from API)
     * @param array $client   UCRM client object
     * @param array $org      Organization info (name, phone, email, etc.)
     * @return array ['pdf_path' => string, 'pdf_url' => string, 'filename' => string] or ['error' => string]
     */
    public function generate(array $quote, array $client, array $org = []): array
    {
        $quoteId  = (int)($quote['id'] ?? 0);
        $quoteNum = $quote['number'] ?? ('Q-' . $quoteId);
        $items    = $quote['items'] ?? [];

        // ── Detect service type ──
        $isStarlink = false;
        $isFiber    = false;
        foreach ($items as $item) {
            $label = $item['label'] ?? '';
            if (preg_match('/starlink|kit/i', $label))          $isStarlink = true;
            if (preg_match('/fiber|ftth|ont|onu|optical/i', $label)) $isFiber = true;
        }

        // ── Build items HTML ──
        $itemsHtml = '';
        $rowNum = 1;
        foreach ($items as $i => $item) {
            $bg = ($rowNum % 2 === 0) ? 'background:#fafafa;' : '';
            $label = htmlspecialchars($item['label'] ?? '');
            $qty   = $item['quantity'] ?? 1;
            $unit  = $item['unit'] ?? '';
            $type  = $item['type'] ?? '';
            $price = '$' . number_format((float)($item['price'] ?? 0), 2);
            $total = '$' . number_format((float)($item['total'] ?? ((float)($item['quantity'] ?? 1) * (float)($item['price'] ?? 0))), 2);

            $unitLine = '';
            if ($unit) {
                $unitLine = '<br><span style="font-size:8px;color:#9B9B9B;text-transform:uppercase;letter-spacing:0.5px;">'
                    . htmlspecialchars($type) . ' - ' . htmlspecialchars($unit) . '</span>';
            }

            // Format quantity with unit
            $qtyStr = $qty;
            if ($unit && !is_numeric($qty)) {
                $qtyStr = $qty;
            } elseif ($unit) {
                $qtyStr = $qty . ' ' . $unit;
            }

            $itemsHtml .= '<tr style="' . $bg . '">'
                . '<td style="padding:10px;font-size:11px;border-bottom:1px solid #f0f0f0;color:#9B9B9B;font-weight:600;">' . $rowNum . '</td>'
                . '<td style="padding:10px;font-size:11px;border-bottom:1px solid #f0f0f0;"><strong style="font-size:12px;">' . $label . '</strong>' . $unitLine . '</td>'
                . '<td style="padding:10px;font-size:11px;border-bottom:1px solid #f0f0f0;text-align:right;">' . htmlspecialchars((string)$qtyStr) . '</td>'
                . '<td style="padding:10px;font-size:11px;border-bottom:1px solid #f0f0f0;text-align:right;">' . $price . '</td>'
                . '<td style="padding:10px;font-size:11px;border-bottom:1px solid #f0f0f0;text-align:right;font-weight:700;">' . $total . '</td>'
                . '</tr>';
            $rowNum++;
        }

        // ── Calculate totals ──
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += (float)($item['total'] ?? ((float)($item['quantity'] ?? 1) * (float)($item['price'] ?? 0)));
        }
        $total = (float)($quote['total'] ?? $quote['totalUntaxed'] ?? $subtotal);

        // ── Client info ──
        $clientName    = htmlspecialchars(trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? '')) ?: ($client['companyName'] ?? 'Customer'));
        $companyName   = htmlspecialchars($client['companyName'] ?? '');
        $clientAddress = $this->buildAddress($client);
        $clientPhone   = '';
        $clientEmail   = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!$clientPhone && !empty($c['phone'])) $clientPhone = htmlspecialchars($c['phone']);
            if (!$clientEmail && !empty($c['email'])) $clientEmail = htmlspecialchars($c['email']);
        }

        // ── Sales person ──
        $salesPerson = 'Yash Madlani';
        foreach (($client['attributes'] ?? []) as $attr) {
            if (($attr['key'] ?? '') === 'salesPerson' && !empty($attr['value'])) {
                $salesPerson = htmlspecialchars($attr['value']);
                break;
            }
        }

        // ── Organization ──
        $orgName    = htmlspecialchars($org['name'] ?? 'DishNet Africa Ltd');
        $orgStreet  = htmlspecialchars($org['street1'] ?? 'OPP Ministries, Airport Road');
        $orgCity    = htmlspecialchars($org['city'] ?? 'Juba');
        $orgPhone   = htmlspecialchars($org['phone'] ?? '0925831111');
        $orgEmail   = htmlspecialchars($org['email'] ?? 'info@dishnetafrica.com');
        $orgWebsite = htmlspecialchars($org['website'] ?? 'www.dishnetafrica.com');

        // ── Service pill ──
        $pillHtml = '';
        if ($isStarlink) {
            $pillHtml = '<span style="display:inline-block;background:#333;border:1px solid #555;color:#fff;font-size:8px;font-weight:800;letter-spacing:1.5px;padding:3px 8px 2px;border-radius:3px;text-transform:uppercase;margin-left:10px;vertical-align:8px;">STARLINK</span>';
        } elseif ($isFiber) {
            $pillHtml = '<span style="display:inline-block;background:#D41C1C;color:#fff;font-size:8px;font-weight:800;letter-spacing:1.5px;padding:3px 8px 2px;border-radius:3px;text-transform:uppercase;margin-left:10px;vertical-align:8px;">FIBER</span>';
        }

        // ── Terms ──
        $termsHtml = $this->buildTerms($isStarlink, $isFiber);

        // ── Quote date ──
        $createdDate = '';
        if (!empty($quote['createdDate'])) {
            $createdDate = htmlspecialchars($quote['createdDate']);
        } else {
            $createdDate = date('j M Y');
        }

        // ── Notes ──
        $notesHtml = '';
        if (!empty($quote['notes'])) {
            $notesHtml = '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;"><tr>'
                . '<td style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:9px 14px;">'
                . '<div style="font-size:8px;text-transform:uppercase;letter-spacing:1px;color:#92400e;font-weight:700;margin-bottom:3px;">NOTES</div>'
                . '<div style="font-size:10px;color:#78350f;line-height:1.5;">' . htmlspecialchars($quote['notes']) . '</div>'
                . '</td></tr></table>';
        }

        // ── Totals rows ──
        $totalsHtml = '<tr><td style="padding:6px 14px;font-size:11px;color:#6B6B6B;border-bottom:1px solid #eee;">Subtotal</td>'
            . '<td style="padding:6px 14px;font-size:11px;font-weight:700;text-align:right;border-bottom:1px solid #eee;">$' . number_format($subtotal, 2) . '</td></tr>';

        if (abs($total - $subtotal) > 0.01 && $total < $subtotal) {
            $discount = $subtotal - $total;
            $totalsHtml .= '<tr><td style="padding:6px 14px;font-size:11px;color:#059669;border-bottom:1px solid #eee;">Discount</td>'
                . '<td style="padding:6px 14px;font-size:11px;font-weight:700;text-align:right;color:#059669;border-bottom:1px solid #eee;">-$' . number_format($discount, 2) . '</td></tr>';
        }

        $totalsHtml .= '<tr><td style="padding:9px 14px;font-size:15px;font-weight:800;color:#fff;background:#141414;">Total Due</td>'
            . '<td style="padding:9px 14px;font-size:15px;font-weight:800;text-align:right;color:#fff;background:#141414;">$' . number_format($total, 2) . '</td></tr>';

        // ── Company line ──
        $companyLine = $companyName ? '<div style="font-size:10px;color:#6B6B6B;font-weight:600;">' . $companyName . '</div>' : '';

        // ── Build full HTML ──
        $html = $this->getTemplate();
        $replacements = [
            '{{QUOTE_NUM}}'      => htmlspecialchars($quoteNum),
            '{{CREATED_DATE}}'   => $createdDate,
            '{{PILL_HTML}}'      => $pillHtml,
            '{{CLIENT_NAME}}'    => $clientName,
            '{{COMPANY_LINE}}'   => $companyLine,
            '{{CLIENT_ADDRESS}}' => $clientAddress,
            '{{CLIENT_PHONE}}'   => $clientPhone ? '<div style="font-size:10px;color:#6B6B6B;">' . $clientPhone . '</div>' : '',
            '{{CLIENT_EMAIL}}'   => $clientEmail ? '<div style="font-size:10px;color:#6B6B6B;">' . $clientEmail . '</div>' : '',
            '{{ORG_NAME}}'       => $orgName,
            '{{ORG_STREET}}'     => $orgStreet,
            '{{ORG_CITY}}'       => $orgCity,
            '{{ORG_PHONE}}'      => $orgPhone,
            '{{ORG_EMAIL}}'      => $orgEmail,
            '{{ORG_WEBSITE}}'    => $orgWebsite,
            '{{ITEMS_HTML}}'     => $itemsHtml,
            '{{TOTALS_HTML}}'    => $totalsHtml,
            '{{NOTES_HTML}}'     => $notesHtml,
            '{{TERMS_HTML}}'     => $termsHtml,
            '{{AGENT_NAME}}'     => htmlspecialchars($salesPerson),
        ];

        foreach ($replacements as $key => $val) {
            $html = str_replace($key, $val, $html);
        }

        // ── Write HTML temp file ──
        $tmpDir = $this->dataDir . '/quote_pdfs';
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

        $safeRef  = preg_replace('/[^A-Za-z0-9\-]/', '', $quoteNum);
        $htmlFile = $tmpDir . "/quote_{$quoteId}_{$safeRef}.html";
        $pdfFile  = $tmpDir . "/quote_{$quoteId}_{$safeRef}.pdf";
        $filename = "Quote-{$quoteNum}.pdf";

        file_put_contents($htmlFile, $html);

        // ── Convert HTML → PDF via Chromium PDF service ──
        $pdfServiceUrl = $this->config['pdf_service_url']
            ?? 'https://n8n-dishnet-pdf-service.4zz82b.easypanel.host/forms/chromium/convert/html';

        $cfile = new \CURLFile($htmlFile, 'text/html', 'index.html');
        $ch = curl_init($pdfServiceUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['files' => $cfile],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $pdfContent = curl_exec($ch);
        $httpCode   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr    = curl_error($ch);
        curl_close($ch);
        @unlink($htmlFile);

        if ($httpCode !== 200 || !$pdfContent || strlen($pdfContent) < 100) {
            return ['error' => "Chromium PDF service failed: HTTP {$httpCode} {$curlErr}"];
        }

        file_put_contents($pdfFile, $pdfContent);

        // ── Generate serve URL ──
        $secret   = ($this->config['webhook_secret'] ?? 'dishnet');
        $pdfToken = hash_hmac('sha256', basename($pdfFile) . date('Ymd'), $secret);
        file_put_contents($pdfFile . '.meta', json_encode([
            'token' => $pdfToken, 'created' => time(), 'quote' => $quoteNum, 'filename' => $filename,
        ]));

        $siteUrl = rtrim($this->config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/');
        $siteUrl = preg_replace('#/api/v[0-9.]+$#', '', $siteUrl);
        $siteUrl = preg_replace('#/crm$#', '', $siteUrl);
        $pdfUrl  = $siteUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php'
            . '?page=api&action=serve_quote_pdf'
            . '&file=' . urlencode(basename($pdfFile))
            . '&token=' . urlencode($pdfToken);

        return [
            'pdf_path' => $pdfFile,
            'pdf_url'  => $pdfUrl,
            'filename' => $filename,
        ];
    }

    private function findWkhtmltopdf(): string
    {
        // Common paths including UCRM Docker container locations
        $paths = [
            '/usr/local/bin/wkhtmltopdf',
            '/usr/bin/wkhtmltopdf',
            '/opt/wkhtmltopdf/bin/wkhtmltopdf',
            '/opt/wkhtmltox/bin/wkhtmltopdf',
            '/app/vendor/bin/wkhtmltopdf',
            '/home/app/vendor/bin/wkhtmltopdf',
            '/usr/sbin/wkhtmltopdf',
            '/snap/bin/wkhtmltopdf',
        ];
        foreach ($paths as $p) {
            if (file_exists($p) && is_executable($p)) return $p;
        }
        // Try `which`
        $which = trim(shell_exec('which wkhtmltopdf 2>/dev/null') ?? '');
        if ($which) return $which;
        // Try `find` in common dirs
        $find = trim(shell_exec('find /usr /opt /app /home -name wkhtmltopdf -type f 2>/dev/null | head -1') ?? '');
        if ($find && is_executable($find)) return $find;
        return '';
    }

    private function buildAddress(array $client): string
    {
        $parts = [];
        $useSame = !empty($client['invoiceAddressSameAsContact']);
        $s1 = $useSame ? ($client['street1'] ?? '') : ($client['invoiceStreet1'] ?? $client['street1'] ?? '');
        $s2 = $useSame ? ($client['street2'] ?? '') : ($client['invoiceStreet2'] ?? $client['street2'] ?? '');
        $city = $useSame ? ($client['city'] ?? '') : ($client['invoiceCity'] ?? $client['city'] ?? '');
        $state = $useSame ? ($client['state'] ?? '') : ($client['invoiceState'] ?? $client['state'] ?? '');
        $country = $useSame ? ($client['country'] ?? '') : ($client['invoiceCountry'] ?? $client['country'] ?? '');

        $lines = [];
        $line1 = $s1;
        if ($s2) $line1 .= ', ' . $s2;
        if ($line1) $lines[] = htmlspecialchars($line1);
        $line2 = $city;
        if ($state) $line2 .= ', ' . $state;
        if ($line2) $lines[] = htmlspecialchars($line2);
        if ($country) $lines[] = htmlspecialchars($country);

        return '<div style="font-size:10px;color:#6B6B6B;line-height:1.5;">' . implode('<br>', $lines) . '</div>';
    }

    private function buildTerms(bool $isStarlink, bool $isFiber): string
    {
        if ($isStarlink) {
            $title = 'STARLINK SERVICE — TERMS &amp; CONDITIONS';
            $terms = [
                ['1. Currency &amp; Pricing:', 'All prices USD. Includes 26% Excise Duty. Subject to change with 30 days notice.'],
                ['2. Payment:', 'Monthly fees due 1st. Late = 5% penalty. Suspended after 7 days. Reconnection: $25.'],
                ['3. Installation:', 'Kit setup included. Roof/pole extra. Clear sky needed. 2-5 days after payment.'],
                ['4. Equipment:', 'All devices DishNet property (leased). Return within 7 days of termination.'],
                ['5. Kit Transfer:', 'Available after 180 days (6 months). Fee: $150. Processing: 14-30 days.'],
                ['6. Uptime:', '99.5% guarantee. 24/7 support: +211 921 443 002.'],
                ['7. Fair Use:', 'Personal/business only. No illegal content or reselling.'],
                ['8. Contract:', '6-month (180 days) min. Early exit: 30 days notice + fee.'],
                ['9. Refunds:', 'Install fees non-refundable once begun. Deposits refundable on equipment return.'],
                ['10. Relocation:', '$75 fee. 7 days advance notice required.'],
                ['11. Liability:', 'Not liable for indirect damages exceeding 3 months of fees.'],
                ['12. Force Majeure:', 'Not liable for disruptions from natural disasters, war, or infrastructure failures.'],
                ['13. Customer Duty:', 'Accurate info, safe environment, protect equipment, report faults within 24hrs.'],
                ['14. Governing Law:', 'Republic of South Sudan law and jurisdiction.'],
            ];
        } elseif ($isFiber) {
            $title = 'FTTH SERVICE — TERMS &amp; CONDITIONS';
            $terms = [
                ['1. Currency &amp; Pricing:', 'All prices USD. Includes 26% Excise Duty.'],
                ['2. Payment:', 'Monthly subscription in advance. Late = 5% penalty + auto disconnection.'],
                ['3. Installation:', 'Standard area. Non-standard (extra wiring/poles) extra. Customer provides ONT location.'],
                ['4. Equipment:', 'Routers/ONTs are DishNet property. Return in working condition within 7 days.'],
                ['5. Uptime:', '99.5% guarantee. 24/7 support: +211 921 443 002.'],
                ['6. Fair Use:', 'Personal/business only. No illegal content or reselling.'],
                ['7. Contract:', '3-month min. Early exit: 30 days notice + fee.'],
                ['8. Refunds:', 'Install fees non-refundable once begun. Deposits refundable on return.'],
                ['9. Relocation:', '$75 fee. 7 days advance notice.'],
                ['10. Liability:', 'Not liable for indirect damages exceeding 3 months of fees.'],
                ['11. Force Majeure:', 'Not liable for disruptions from natural disasters, war, or infrastructure failures.'],
                ['12. Customer Duty:', 'Accurate info, safe environment, protect equipment, report faults within 24hrs.'],
                ['13. Governing Law:', 'Republic of South Sudan law and jurisdiction.'],
            ];
        } else {
            $title = 'TERMS &amp; CONDITIONS';
            $terms = [
                ['1. Currency &amp; Pricing:', 'All prices USD. Includes 26% Excise Duty. Subject to change with 30 days notice.'],
                ['2. Payment:', 'Monthly fees due 1st. Late = 5% penalty. Suspended after 7 days. Reconnection: $25.'],
                ['3. Installation:', 'Standard area included. Non-standard may incur additional charges.'],
                ['4. Equipment:', 'All devices DishNet property (leased). Return within 7 days of termination.'],
                ['5. Uptime:', '99.5% guarantee. 24/7 support: +211 921 443 002.'],
                ['6. Fair Use:', 'Personal/business only. No illegal content or reselling.'],
                ['7. Contract:', 'Minimum commitment applies. Early exit: 30 days notice + fee.'],
                ['8. Refunds:', 'Install fees non-refundable once begun. Deposits refundable on equipment return.'],
                ['9. Liability:', 'Not liable for indirect damages exceeding 3 months of fees.'],
                ['10. Force Majeure:', 'Not liable for disruptions from natural disasters, war, or infrastructure failures.'],
                ['11. Customer Duty:', 'Accurate info, safe environment, protect equipment, report faults within 24hrs.'],
                ['12. Governing Law:', 'Republic of South Sudan law and jurisdiction.'],
            ];
        }

        $third = (int)ceil(count($terms) / 3);
        $cols = [[], [], []];
        foreach ($terms as $i => $t) {
            $colIdx = (int)floor($i / $third);
            if ($colIdx > 2) $colIdx = 2;
            $cols[$colIdx][] = $t;
        }

        $html = '<div style="font-size:9px;text-transform:uppercase;letter-spacing:2px;color:#9B9B9B;font-weight:700;margin-top:6px;margin-bottom:5px;">' . $title . '</div>';
        $html .= '<table style="width:100%;border-collapse:collapse;"><tr>';
        foreach ($cols as $ci => $col) {
            $pad = $ci === 0 ? '0 5px 0 0' : ($ci === 1 ? '0 5px' : '0 0 0 5px');
            $html .= '<td style="vertical-align:top;padding:' . $pad . ';width:33%;font-size:9px;color:#444;line-height:1.45;">';
            foreach ($col as $t) {
                $html .= '<div style="padding:2px 0;"><b style="color:#D41C1C;">*</b> <b style="color:#141414;">' . $t[0] . '</b> ' . $t[1] . '</div>';
            }
            $html .= '</td>';
        }
        $html .= '</tr></table>';

        return $html;
    }

    private function getTemplate(): string
    {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
@page{margin:0;size:A4}
body{font-family:Helvetica,Arial,sans-serif;font-size:12px;color:#141414;line-height:1.45;margin:0;padding:0}
*{box-sizing:border-box}table{border-collapse:collapse}td,th{border:none}
</style></head><body>

<!-- HEADER -->
<table style="width:100%;background:#141414;border-collapse:collapse;" cellpadding="0" cellspacing="0"><tr>
<td style="padding:18px 28px 14px;vertical-align:middle;">
<span style="font-size:30px;font-weight:900;color:#fff;font-family:Helvetica,Arial,sans-serif;letter-spacing:-0.5px;">DishNet</span>{{PILL_HTML}}
<div style="height:3px;width:115px;background:linear-gradient(to right,#D41C1C,#E8521A,#FF7A35);border-radius:2px;margin-top:2px;"></div>
<div style="font-size:9px;color:#777;margin-top:4px;">Internet Service Provider &middot; Juba, South Sudan</div>
</td>
<td style="padding:18px 28px 14px;text-align:right;vertical-align:middle;">
<div style="font-size:10px;text-transform:uppercase;letter-spacing:2px;color:#666;">QUOTATION</div>
<div style="font-size:24px;font-weight:900;color:#fff;font-family:Helvetica,Arial,sans-serif;">{{QUOTE_NUM}}</div>
<div style="font-size:9px;color:#555;">{{CREATED_DATE}}</div>
</td></tr></table>
<div style="height:3px;background:linear-gradient(to right,#D41C1C,#E8521A,#FF7A35);"></div>

<!-- CONTENT -->
<div style="padding:16px 28px 45px;">

<table style="width:100%;border-collapse:separate;border-spacing:10px 0;margin-bottom:16px;"><tr>
<td style="width:50%;background:#f8f8f8;border-radius:6px;padding:12px 14px;vertical-align:top;border-left:3px solid #D41C1C;">
<div style="font-size:8px;text-transform:uppercase;letter-spacing:1.5px;color:#9B9B9B;font-weight:700;margin-bottom:4px;">BILL TO</div>
<div style="font-size:14px;font-weight:700;color:#141414;margin-bottom:3px;">{{CLIENT_NAME}}</div>
{{COMPANY_LINE}}{{CLIENT_ADDRESS}}{{CLIENT_PHONE}}{{CLIENT_EMAIL}}
</td>
<td style="width:50%;background:#f8f8f8;border-radius:6px;padding:12px 14px;vertical-align:top;border-left:3px solid #141414;">
<div style="font-size:8px;text-transform:uppercase;letter-spacing:1.5px;color:#9B9B9B;font-weight:700;margin-bottom:4px;">FROM</div>
<div style="font-size:14px;font-weight:700;color:#141414;margin-bottom:3px;">{{ORG_NAME}}</div>
<div style="font-size:10px;color:#6B6B6B;line-height:1.5;">{{ORG_STREET}}<br>{{ORG_CITY}}<br>{{ORG_PHONE}}<br>{{ORG_EMAIL}}<br>{{ORG_WEBSITE}}</div>
</td></tr></table>

<div style="font-size:9px;text-transform:uppercase;letter-spacing:2px;color:#9B9B9B;font-weight:700;margin-bottom:5px;">QUOTATION DETAILS</div>
<table style="width:100%;border-collapse:collapse;margin-bottom:4px;">
<thead><tr>
<th style="background:#141414;color:#fff;font-size:9px;text-transform:uppercase;letter-spacing:0.8px;padding:9px 10px;font-weight:700;text-align:left;width:5%;">#</th>
<th style="background:#141414;color:#fff;font-size:9px;text-transform:uppercase;letter-spacing:0.8px;padding:9px 10px;font-weight:700;text-align:left;width:43%;">Description</th>
<th style="background:#141414;color:#fff;font-size:9px;text-transform:uppercase;letter-spacing:0.8px;padding:9px 10px;font-weight:700;text-align:right;width:12%;">Qty</th>
<th style="background:#141414;color:#fff;font-size:9px;text-transform:uppercase;letter-spacing:0.8px;padding:9px 10px;font-weight:700;text-align:right;width:18%;">Unit Price</th>
<th style="background:#141414;color:#fff;font-size:9px;text-transform:uppercase;letter-spacing:0.8px;padding:9px 10px;font-weight:700;text-align:right;width:22%;">Amount (USD)</th>
</tr></thead>
<tbody>{{ITEMS_HTML}}</tbody></table>

<table style="width:100%;border-collapse:collapse;margin-bottom:14px;"><tr>
<td style="width:58%;border:none;"></td>
<td style="width:42%;border:none;padding:0;">
<table style="width:100%;border-collapse:collapse;background:#f8f8f8;border-radius:6px;">
{{TOTALS_HTML}}
</table></td></tr></table>

<table style="width:100%;border-collapse:collapse;margin-bottom:12px;"><tr>
<td style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:9px 14px;font-size:10px;color:#065f46;">
<b style="color:#059669;font-size:12px;margin-right:6px;">V</b> Valid for <strong>7 days</strong> from date of issue. Prices subject to change after expiry.
</td></tr></table>

{{NOTES_HTML}}
{{TERMS_HTML}}

<div style="font-size:8px;font-weight:700;color:#141414;margin-top:5px;">By accepting this quotation, you agree to the above Terms &amp; Conditions.</div>

<table style="width:100%;border-collapse:collapse;margin-top:14px;border-top:1px solid #eee;"><tr>
<td style="width:45%;padding:8px 0 0;vertical-align:top;border:none;">
<div style="font-size:8px;text-transform:uppercase;letter-spacing:1px;color:#9B9B9B;font-weight:700;">PREPARED BY</div>
<div style="border-top:1.5px solid #141414;padding-top:4px;margin-top:16px;">
<span style="font-size:10px;font-weight:700;">{{AGENT_NAME}}</span>
<span style="font-size:9px;color:#6B6B6B;float:right;">DishNet Africa Ltd</span>
</div></td>
<td style="width:10%;border:none;"></td>
<td style="width:45%;padding:8px 0 0;vertical-align:top;border:none;">
<div style="font-size:8px;text-transform:uppercase;letter-spacing:1px;color:#9B9B9B;font-weight:700;">ACCEPTED BY (CUSTOMER)</div>
<div style="border-top:1.5px solid #141414;padding-top:4px;margin-top:16px;">
<span style="font-size:10px;font-weight:700;">______________________________</span>
<span style="font-size:9px;color:#6B6B6B;float:right;">Date: ____________</span>
</div></td></tr></table>

</div>

<div style="height:3px;background:linear-gradient(to right,#D41C1C,#E8521A,#FF7A35);margin-top:10px;"></div>
<table style="width:100%;background:#141414;border-collapse:collapse;"><tr>
<td style="padding:8px 28px;font-size:9px;color:#777;">{{ORG_NAME}} &middot; Juba, South Sudan</td>
<td style="padding:8px 28px;font-size:10px;color:#555;text-align:center;font-style:italic;font-weight:600;">Of course we can ...</td>
<td style="padding:8px 28px;font-size:9px;color:#999;text-align:right;">{{ORG_WEBSITE}}</td>
</tr></table>

</body></html>';
    }
}
