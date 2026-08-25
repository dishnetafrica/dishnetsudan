<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains'))  { function str_contains(string $h, string $n): bool  { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

/**
 * QuotePdfService — DishNet Hybrid v4.10.3+
 *
 * Generates branded, professional PDF quotations from the plugin's own
 * HTML template — independent of UCRM's generic PDF.
 *
 * Flow:
 *   1. build() → receives quote data (items, customer, agent)
 *   2. Renders HTML template with placeholder replacement
 *   3. Converts to PDF via wkhtmltopdf
 *   4. Saves to quote_pdfs/ directory (permanent)
 *   5. Returns path + public URL (HMAC-signed for WASender)
 *
 * Integration points:
 *   - QuotationService calls buildAndSave() after creating a quote
 *   - wa_send_quote_pdf API uses getPublicUrl() to send via WASender
 *   - Customer Lookup shows "DishNet PDF" button linking to serve_quote_pdf
 *
 * PHP 7.4 compatible.
 */
class QuotePdfService
{
    private $store;
    private string $dataDir;
    private array  $config;

    const PDF_DIR      = 'quote_pdfs';
    const TEMPLATE_DIR = 'templates';
    const TEMPLATE     = 'quote_dishnet.html';

    // ── Starlink T&C (14 points) ─────────────────────────────────────────
    const STARLINK_TERMS = [
        ['1. Currency & Pricing', 'All prices in USD. Includes 26% Excise Duty per local regulations. Subject to change with 30 days notice.'],
        ['2. Payment', 'Monthly fees due by the 1st. Late payment = 5% penalty. Service suspended after 7 days. Reconnection fee: $25.'],
        ['3. Installation', 'Starlink Kit setup included. Roof/pole mounting extra if required. Clear sky view needed. Completed 2–5 days after payment.'],
        ['4. Equipment', 'All devices are DishNet property (leased). Must be returned within 7 days of service termination.'],
        ['5. Kit Ownership & Transfer', 'Kit transfer available only after 120 days (6 months) of active service. Transfer fee: $150 USD. Processing: 14–30 days.'],
        ['6. Uptime', '99.5% uptime guarantee. 24/7 support: +211 921 443 009.'],
        ['7. Fair Use', 'Service for personal/business use only. No illegal content or unauthorized reselling permitted.'],
        ['8. Contract', 'Minimum 6-month (120 days) commitment. Early termination requires 30 days written notice + applicable exit fee.'],
        ['9. Refunds', 'Installation fees non-refundable once work begins. Deposits refundable upon equipment return.'],
        ['10. Relocation', 'Relocation fee: $75 USD. Minimum 7 days advance notice required.'],
        ['11. Liability', 'DishNet is not liable for indirect damages exceeding 3 months of service fees.'],
        ['12. Force Majeure', 'Not liable for disruptions caused by natural disasters, war, or infrastructure failures.'],
        ['13. Customer Responsibility', 'Provide accurate information, maintain safe environment, protect equipment, report faults within 24 hours.'],
        ['14. Governing Law', 'All matters governed by the laws of the Republic of South Sudan.'],
    ];

    // ── Fiber T&C (13 points) ────────────────────────────────────────────
    const FIBER_TERMS = [
        ['1. Currency & Pricing', 'All prices in USD. Includes 26% Excise Duty per local regulations.'],
        ['2. Payment', 'Monthly subscription fees must be paid in advance. Late payment = 5% penalty + automatic disconnection.'],
        ['3. Installation', 'Standard installation within serviceable area. Non-standard installations (extra wiring, poles) may incur additional charges. Customer must provide suitable ONT/router location. Missed appointments without 24hr notice = rescheduling fee.'],
        ['4. Leased Equipment', 'All devices (routers, ONTs) are DishNet property. Must be returned in working condition within 7 days of termination.'],
        ['5. Uptime', '99.5% uptime guarantee. 24/7 support: +211 921 443 009.'],
        ['6. Fair Use', 'Service for personal/business use only. No illegal content or unauthorized reselling.'],
        ['7. Contract', 'Minimum 3-month commitment. Early termination requires 30 days written notice + applicable exit fee.'],
        ['8. Refunds', 'Installation fees non-refundable once work begins. Deposits refundable upon equipment return.'],
        ['9. Relocation', 'Relocation fee: $75 USD. Minimum 7 days advance notice.'],
        ['10. Liability', 'DishNet is not liable for indirect damages exceeding 3 months of service fees.'],
        ['11. Force Majeure', 'Not liable for disruptions caused by natural disasters, war, or infrastructure failures.'],
        ['12. Customer Responsibility', 'Provide accurate info, maintain safe environment, protect equipment, report faults within 24 hours.'],
        ['13. Governing Law', 'All matters governed by the laws of the Republic of South Sudan.'],
    ];

    // ── General/LTE T&C (fallback — 10 points) ──────────────────────────
    const GENERAL_TERMS = [
        ['1. Currency', 'All prices in USD. Includes applicable taxes per local regulations.'],
        ['2. Payment', 'Fees must be paid in advance. Late payment may result in service interruption.'],
        ['3. Equipment', 'All devices are DishNet property. Must be returned within 7 days of termination.'],
        ['4. Uptime', '99.5% uptime guarantee. 24/7 support: +211 921 443 009.'],
        ['5. Fair Use', 'Personal/business use only. No illegal content or reselling.'],
        ['6. Contract', 'Early termination requires 30 days written notice.'],
        ['7. Refunds', 'Service fees non-refundable once provisioned.'],
        ['8. Liability', 'DishNet is not liable for indirect damages exceeding 3 months of service fees.'],
        ['9. Force Majeure', 'Not liable for disruptions caused by natural disasters, war, or infrastructure failures.'],
        ['10. Governing Law', 'All matters governed by the laws of the Republic of South Sudan.'],
    ];

    public function __construct($store, string $dataDir, array $config = [])
    {
        $this->store   = $store;
        $this->dataDir = rtrim($dataDir, '/');
        $this->config  = $config;
    }

    /**
     * Build PDF from quote data and save to disk.
     *
     * @param array $quoteData {
     *   quote_ref:      string   "QUO-2026-0042"
     *   quote_type:     string   "Quotation" | "Proforma Invoice" | "Cash Sale"
     *   status:         string   "QUOTATION" | "PROFORMA" | "ACCEPTED"
     *   customer_name:  string
     *   customer_phone: string
     *   customer_email: string
     *   customer_company: string  (optional)
     *   customer_location: string (optional)
     *   items: array [{
     *     label: string, description?: string, quantity: int, price: float, unit?: string
     *   }]
     *   subtotal:       float
     *   discount:       float    (optional, 0 = hide)
     *   tax:            float    (optional, 0 = hide)
     *   total:          float
     *   amount_paid:    float    (optional)
     *   balance:        float    (optional)
     *   validity_days:  int      (default 7)
     *   notes:          string   (optional)
     *   agent_name:     string
     *   agent_role:     string   (optional)
     * }
     *
     * @return array ['ok'=>bool, 'pdf_path'=>string, 'filename'=>string, 'public_url'=>string, 'error'=>string]
     */
    public function buildAndSave(array $quoteData): array
    {
        $ref = $quoteData['quote_ref'] ?? ('QUO-' . date('Ymd') . '-' . mt_rand(100, 999));

        // ── 1. Load HTML template ─────────────────────────────────────────
        $pluginRoot = $this->findPluginRoot();
        $tplPath    = $pluginRoot . '/' . self::TEMPLATE_DIR . '/' . self::TEMPLATE;

        if (!file_exists($tplPath)) {
            return ['ok' => false, 'error' => "Template not found: {$tplPath}"];
        }

        $html = file_get_contents($tplPath);

        // ── 2. Build item rows HTML ───────────────────────────────────────
        $items = $quoteData['items'] ?? [];
        $itemsHtml = '';
        $rowNum = 0;
        foreach ($items as $item) {
            $rowNum++;
            $qty       = max(1, (int)($item['quantity'] ?? 1));
            $price     = (float)($item['price'] ?? 0);
            $lineTotal = round($qty * $price, 2);
            $label     = htmlspecialchars($item['label'] ?? 'Item');
            $desc      = htmlspecialchars($item['description'] ?? '');
            $unit      = $item['unit'] ?? '';
            $unitLabel = ($unit && $unit !== 'amount') ? " / {$unit}" : '';

            $itemsHtml .= '<tr>';
            $itemsHtml .= '<td style="color:#6b7280;font-weight:600;">' . $rowNum . '</td>';
            $itemsHtml .= '<td>';
            $itemsHtml .= '<div class="in">' . $label . '</div>';
            if ($desc) {
                $itemsHtml .= '<div class="id">' . $desc . '</div>';
            }
            $itemsHtml .= '</td>';
            $itemsHtml .= '<td>' . $qty . '</td>';
            $itemsHtml .= '<td>$' . number_format($price, 2) . $unitLabel . '</td>';
            $itemsHtml .= '<td style="font-weight:700;">$' . number_format($lineTotal, 2) . '</td>';
            $itemsHtml .= '</tr>';
        }

        // ── 3. Build optional lines ───────────────────────────────────────
        $discount = (float)($quoteData['discount'] ?? 0);
        $discountLine = '';
        if ($discount > 0) {
            $discountLine = '<div class="tl s"><span class="lb">Discount</span><span class="vl" style="color:#059669;">-$' . number_format($discount, 2) . '</span></div>';
        }

        $tax = (float)($quoteData['tax'] ?? 0);
        $taxLine = '';
        if ($tax > 0) {
            $taxLine = '<div class="tl s"><span class="lb">Tax</span><span class="vl">$' . number_format($tax, 2) . '</span></div>';
        }

        $amountPaid = isset($quoteData['amount_paid']) ? (float)$quoteData['amount_paid'] : 0;
        $paidLine = '';
        if ($amountPaid > 0) {
            $paidLine = '<div class="tl s"><span class="lb" style="color:#059669;">Paid</span><span class="vl" style="color:#059669;">$' . number_format($amountPaid, 2) . '</span></div>';
        }

        $balance = isset($quoteData['balance']) ? (float)$quoteData['balance'] : 0;
        $balanceLine = '';
        if ($amountPaid > 0 && $balance > 0) {
            $balanceLine = '<div class="tl" style="background:#fef2f2;"><span class="lb" style="color:#dc2626;font-weight:800;">Balance Due</span><span class="vl" style="color:#dc2626;font-weight:900;">$' . number_format($balance, 2) . '</span></div>';
        }

        $notes = trim($quoteData['notes'] ?? '');
        $notesBlock = '';
        if ($notes) {
            $notesBlock = '<div class="nt"><div class="nt-t">Notes</div><div class="nt-b">' . nl2br(htmlspecialchars($notes)) . '</div></div>';
        }

        $validDays  = (int)($quoteData['validity_days'] ?? (int)($this->config['kyc_quote_validity_days'] ?? 7));
        $validUntil = date('d M Y', strtotime("+{$validDays} days"));

        $customerCompanyLine = '';
        $company = trim($quoteData['customer_company'] ?? '');
        if ($company) {
            $customerCompanyLine = '<div class="bl" style="font-weight:600;color:#6b7280;">' . htmlspecialchars($company) . '</div>';
        }

        // Quote type label and status
        $quoteType  = $quoteData['quote_type'] ?? 'Quotation';
        $statusText = strtoupper($quoteData['status'] ?? $quoteType);

        // ── Service type detection (for terms + header pill) ─────────────
        $serviceType = strtolower(trim($quoteData['service_type'] ?? ''));
        if (!$serviceType) {
            // Auto-detect from items
            $allLabels = strtolower(implode(' ', array_column($items, 'label')));
            if (str_contains($allLabels, 'starlink') || str_contains($allLabels, 'kit')) {
                $serviceType = 'starlink';
            } elseif (str_contains($allLabels, 'fiber') || str_contains($allLabels, 'ftth') || str_contains($allLabels, 'ont')) {
                $serviceType = 'fiber';
            } elseif (str_contains($allLabels, 'lte') || str_contains($allLabels, '4g')) {
                $serviceType = 'lte';
            }
        }

        // Service pill for header
        $serviceClassMap = [
            'starlink' => 'starlink', 'fiber' => 'fiber', 'ftth' => 'fiber',
            'lte' => 'lte', '4g' => 'lte',
        ];
        $serviceLabelMap = [
            'starlink' => 'Starlink', 'fiber' => 'Fiber', 'ftth' => 'Fiber',
            'lte' => '4G LTE', '4g' => '4G LTE',
        ];
        $serviceClass = $serviceClassMap[$serviceType] ?? 'starlink';
        $serviceLabel = $serviceLabelMap[$serviceType] ?? '';

        // ── Build terms HTML ─────────────────────────────────────────────
        $termsArr = ($serviceType === 'fiber' || $serviceType === 'ftth')
            ? self::FIBER_TERMS
            : (($serviceType === 'starlink' || $serviceType === '')
                ? self::STARLINK_TERMS
                : self::GENERAL_TERMS);

        $termsHtml = '<div class="tg"><div class="tc">';
        $third = (int)ceil(count($termsArr) / 3);
        foreach ($termsArr as $i => $term) {
            if ($i === $third || $i === $third * 2) {
                $termsHtml .= '</div><div class="tc">';
            }
            $termsHtml .= '<div class="ti"><strong>' . htmlspecialchars($term[0]) . ':</strong> ' . htmlspecialchars($term[1]) . '</div>';
        }
        $termsHtml .= '</div></div>';

        // ── 4. Replace placeholders ───────────────────────────────────────
        $replacements = [
            '{{QUOTE_REF}}'            => htmlspecialchars($ref),
            '{{QUOTE_TYPE}}'           => htmlspecialchars($quoteType),
            '{{STATUS_TEXT}}'          => htmlspecialchars($statusText),
            '{{DATE_FULL}}'            => date('d M Y'),
            '{{SERVICE_CLASS}}'        => $serviceClass,
            '{{SERVICE_LABEL}}'        => $serviceLabel,
            '{{CUSTOMER_NAME}}'        => htmlspecialchars($quoteData['customer_name'] ?? ''),
            '{{CUSTOMER_COMPANY_LINE}}'=> $customerCompanyLine,
            '{{CUSTOMER_PHONE}}'       => htmlspecialchars($quoteData['customer_phone'] ?? ''),
            '{{CUSTOMER_EMAIL}}'       => htmlspecialchars($quoteData['customer_email'] ?? ''),
            '{{CUSTOMER_LOCATION}}'    => htmlspecialchars($quoteData['customer_location'] ?? 'Juba, South Sudan'),
            '{{ITEMS_ROWS}}'           => $itemsHtml,
            '{{SUBTOTAL}}'             => number_format((float)($quoteData['subtotal'] ?? $quoteData['total'] ?? 0), 2),
            '{{DISCOUNT_LINE}}'        => $discountLine,
            '{{TAX_LINE}}'             => $taxLine,
            '{{GRAND_TOTAL}}'          => number_format((float)($quoteData['total'] ?? 0), 2),
            '{{PAID_LINE}}'            => $paidLine,
            '{{BALANCE_LINE}}'         => $balanceLine,
            '{{VALIDITY_DAYS}}'        => (string)$validDays,
            '{{VALID_UNTIL}}'          => $validUntil,
            '{{NOTES_BLOCK}}'          => $notesBlock,
            '{{TERMS_HTML}}'           => $termsHtml,
            '{{AGENT_NAME}}'           => htmlspecialchars($quoteData['agent_name'] ?? 'DishNet Staff'),
            '{{AGENT_ROLE}}'           => htmlspecialchars($quoteData['agent_role'] ?? 'Sales'),
        ];

        $html = str_replace(array_keys($replacements), array_values($replacements), $html);

        // ── 5. Convert to PDF ─────────────────────────────────────────────
        $pdfDir = $this->dataDir . '/' . self::PDF_DIR;
        if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

        $safeRef  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $ref);
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $quoteData['customer_name'] ?? 'Customer');
        $pdfFile  = "DishNet_Quote_{$safeRef}_{$safeName}.pdf";
        $pdfPath  = $pdfDir . '/' . $pdfFile;

        // Write temp HTML
        $tmpHtml = $this->dataDir . '/temp_quote_' . md5($ref) . '.html';
        file_put_contents($tmpHtml, $html);

        $wk = $this->findWkhtmltopdf();
        if ($wk) {
            $cmd = escapeshellarg($wk)
                 . ' --quiet --print-media-type --page-size A4'
                 . ' --margin-top 0mm --margin-bottom 0mm'
                 . ' --margin-left 0mm --margin-right 0mm'
                 . ' --encoding UTF-8'
                 . ' --disable-smart-shrinking'
                 . ' ' . escapeshellarg($tmpHtml)
                 . ' ' . escapeshellarg($pdfPath)
                 . ' 2>&1';
            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0 || !file_exists($pdfPath)) {
                // Fallback: save HTML directly
                copy($tmpHtml, $pdfPath);
            }
        } else {
            copy($tmpHtml, $pdfPath);
        }
        @unlink($tmpHtml);

        if (!file_exists($pdfPath)) {
            return ['ok' => false, 'error' => 'PDF generation failed'];
        }

        // ── 6. Create HMAC token for public URL ──────────────────────────
        $secret   = $this->config['webhook_secret'] ?? 'dishnet';
        $pdfToken = hash_hmac('sha256', $pdfFile . date('Ymd'), $secret);

        // Save meta for serve_quote_pdf endpoint
        file_put_contents($pdfPath . '.meta', json_encode([
            'token'       => $pdfToken,
            'created'     => time(),
            'quote_ref'   => $ref,
            'customer'    => $quoteData['customer_name'] ?? '',
            'total'       => (float)($quoteData['total'] ?? 0),
            'agent'       => $quoteData['agent_name'] ?? '',
            'filename'    => str_replace('_', '-', $pdfFile),
        ], JSON_PRETTY_PRINT));

        // ── 7. Build public URL ──────────────────────────────────────────
        $siteUrl  = rtrim($this->config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/');
        $siteUrl  = preg_replace('#/api/v[0-9.]+$#', '', $siteUrl);
        $siteUrl  = preg_replace('#/crm$#', '', $siteUrl);
        $publicUrl = $siteUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php'
                   . '?page=api&action=serve_quote_pdf'
                   . '&file=' . urlencode($pdfFile)
                   . '&token=' . urlencode($pdfToken);

        // ── 8. Log to quotes ─────────────────────────────────────────────
        try {
            $log = $this->store->load('quote_pdfs_log.json') ?? [];
            $log[] = [
                'quote_ref'  => $ref,
                'pdf_file'   => $pdfFile,
                'customer'   => $quoteData['customer_name'] ?? '',
                'total'      => (float)($quoteData['total'] ?? 0),
                'agent'      => $quoteData['agent_name'] ?? '',
                'created_at' => date('Y-m-d H:i:s'),
            ];
            // Keep last 500
            if (count($log) > 500) $log = array_slice($log, -500);
            $this->store->save('quote_pdfs_log.json', $log);
        } catch (\Throwable $e) {
            // Non-fatal
        }

        return [
            'ok'         => true,
            'pdf_path'   => self::PDF_DIR . '/' . $pdfFile,
            'pdf_file'   => $pdfFile,
            'filename'   => str_replace('_', '-', $pdfFile),
            'public_url' => $publicUrl,
            'token'      => $pdfToken,
        ];
    }

    /**
     * Quick helper: build from QuotationService log entry + items.
     * Bridges the existing quotation flow to PDF generation.
     */
    public function buildFromQuoteLog(array $quoteLog, array $retailer): array
    {
        $items = [];
        foreach ($quoteLog['items'] ?? [] as $item) {
            $items[] = [
                'label'       => $item['label'] ?? $item['name'] ?? 'Item',
                'description' => $item['description'] ?? '',
                'quantity'    => (int)($item['quantity'] ?? 1),
                'price'       => (float)($item['price'] ?? 0),
                'unit'        => $item['unit'] ?? '',
            ];
        }

        return $this->buildAndSave([
            'quote_ref'         => $quoteLog['quote_ref'] ?? '',
            'quote_type'        => ucfirst($quoteLog['type'] ?? 'Quotation'),
            'status'            => strtoupper($quoteLog['type'] ?? 'Quotation'),
            'service_type'      => $quoteLog['service_type'] ?? '',
            'customer_name'     => $quoteLog['customer_name'] ?? '',
            'customer_phone'    => $quoteLog['customer_phone'] ?? '',
            'customer_email'    => $quoteLog['customer_email'] ?? '',
            'customer_company'  => $quoteLog['company_name'] ?? '',
            'customer_location' => $quoteLog['location'] ?? '',
            'items'             => $items,
            'subtotal'          => (float)($quoteLog['total'] ?? 0),
            'total'             => (float)($quoteLog['total'] ?? 0),
            'amount_paid'       => (float)($quoteLog['amount_paid'] ?? 0),
            'balance'           => (float)($quoteLog['balance'] ?? 0),
            'notes'             => $quoteLog['notes'] ?? '',
            'agent_name'        => $retailer['name'] ?? 'DishNet Staff',
            'agent_role'        => ucfirst(str_replace('_', ' ', $retailer['role'] ?? 'Sales')),
        ]);
    }

    /**
     * Serve a quote PDF with HMAC verification.
     * Called from API endpoint: serve_quote_pdf
     */
    public function servePdf(string $file, string $token): void
    {
        $safe = basename($file);
        $path = $this->dataDir . '/' . self::PDF_DIR . '/' . $safe;

        if (!file_exists($path)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            return;
        }

        // Verify HMAC
        $metaPath = $path . '.meta';
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true);
            if (($meta['token'] ?? '') !== $token) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid token']);
                return;
            }
        }

        // Serve file
        $displayName = str_replace('_', '-', $safe);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $displayName . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }

    /**
     * Get list of recent quote PDFs.
     */
    public function getRecentPdfs(int $limit = 20): array
    {
        $log = $this->store->load('quote_pdfs_log.json') ?? [];
        return array_slice(array_reverse($log), 0, $limit);
    }

    // ── Internal helpers ─────────────────────────────────────────────────

    private function findPluginRoot(): string
    {
        $dir = dirname($this->dataDir);
        if (file_exists($dir . '/manifest.json')) return $dir;
        $dir = dirname($dir);
        if (file_exists($dir . '/manifest.json')) return $dir;
        return dirname(__DIR__);
    }

    private function findWkhtmltopdf(): ?string
    {
        $paths = ['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf'];
        foreach ($paths as $p) {
            if (file_exists($p) && is_executable($p)) return $p;
        }
        $which = trim(shell_exec('which wkhtmltopdf 2>/dev/null') ?: '');
        return ($which && file_exists($which)) ? $which : null;
    }
}
