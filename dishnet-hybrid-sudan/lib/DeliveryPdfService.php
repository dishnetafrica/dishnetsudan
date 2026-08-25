<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains'))  { function str_contains(string $h, string $n): bool  { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

/**
 * DeliveryPdfService — DishNet Hybrid v4.9.20+
 *
 * Generates Delivery Acknowledgment + T&C PDF for customers after KYC.
 * Sends via WASender sendDocument() with welcome caption.
 *
 * Flow:
 *   1. KYC submitted (cash sale, amount > 0) → generateAndSend()
 *   2. Auto-detects Starlink vs Fiber from customer_type
 *   3. Builds HTML from template, converts to PDF via wkhtmltopdf
 *   4. Saves to delivery_pdfs/ (permanent — legal documents)
 *   5. Sends via WASender with welcome + next steps as caption
 *   6. Stores terms_pdf_path + terms_sent_at on KYC record
 *
 * PHP 7.4 compatible.
 */
class DeliveryPdfService
{
    private $store;
    private string $dataDir;
    private array  $config;

    const PDF_DIR       = 'delivery_pdfs';
    const TEMPLATE_DIR  = 'templates';

    public function __construct($store, string $dataDir, array $config = [])
    {
        $this->store   = $store;
        $this->dataDir = rtrim($dataDir, '/');
        $this->config  = $config;
    }

    /**
     * Generate PDF and send to customer via WhatsApp.
     *
     * @param array  $app        KYC application data (merged with POST)
     * @param array  $retailer   Agent who submitted KYC
     * @param string $crmClientId  CRM client ID (may be empty)
     * @return array ['ok'=>bool, 'pdf_path'=>string, 'filename'=>string, 'error'=>string]
     */
    public function generateAndSend(array $app, array $retailer, string $crmClientId = ''): array
    {
        // ── 1. Determine service type ──────────────────────────────────────
        $serviceType = strtolower(trim($app['customer_type'] ?? $app['connectivity_type'] ?? ''));
        $isFiber   = str_contains($serviceType, 'fiber') || str_contains($serviceType, 'ftth');
        $isLte     = str_contains($serviceType, 'lte') || str_contains($serviceType, '4g');
        $template  = $isFiber ? 'fiber' : 'starlink';

        // ── 2. Build template data ─────────────────────────────────────────
        $firstName = trim($app['firstname'] ?? '');
        $lastName  = trim($app['lastname']  ?? '');
        $company   = trim($app['company_name'] ?? '');
        $fullName  = $company !== '' ? $company : trim("{$firstName} {$lastName}");
        $phone     = trim($app['mobile'] ?? '');
        $appId     = (int)($app['id'] ?? 0);
        $kycRef    = 'KYC-' . date('Y') . '-' . str_pad((string)$appId, 4, '0', STR_PAD_LEFT);

        // Location: prefer address_2 (area), fall back to address_1
        $location  = trim($app['address_2'] ?? $app['area'] ?? '');
        if (empty($location)) {
            $addr1 = $app['address_1'] ?? '';
            $parts = array_filter(array_map('trim', explode(',', $addr1)));
            $location = !empty($parts) ? end($parts) : 'Juba';
        }

        $amount    = number_format((float)($app['amount_charged'] ?? 0), 2);
        $staffName = $retailer['name'] ?? 'DishNet Staff';
        $staffRole = ucfirst(str_replace('_', ' ', $retailer['role'] ?? 'Staff'));

        // Starlink-specific
        $kitSerial = trim($app['kit_serial'] ?? $app['starlink_serial'] ?? $app['device_serial'] ?? '');
        if (empty($kitSerial)) $kitSerial = 'To be assigned';

        // Plan name
        $planName  = trim($app['plan_name'] ?? $app['subscription_plan'] ?? '');
        if (empty($planName)) $planName = $isFiber ? 'Fiber Plan' : 'Starlink Standard';

        // Fiber-specific
        $installFee = number_format((float)($app['installation_fee'] ?? $app['amount_charged'] ?? 0), 2);
        $monthlyFee = number_format((float)($app['monthly_fee'] ?? $app['subscription_amount'] ?? 0), 2);

        // ── Build items table from hw_cart (physical items only) ──────────
        $cartItems = [];
        $hwCart = !empty($app['hw_cart_json']) ? json_decode($app['hw_cart_json'], true) : [];
        if (!is_array($hwCart)) $hwCart = [];

        // Add hardware items from cart (skip subscriptions/monthly plans)
        foreach ($hwCart as $hw) {
            $hwPrice = (float)preg_replace('/[^0-9.]/', '', (string)($hw['price'] ?? '0'));
            $hwUnit  = strtolower(trim($hw['unit'] ?? $hw['billing'] ?? ''));
            // Skip monthly/recurring items — those start after activation
            if (in_array($hwUnit, ['month', 'monthly', '/mo', 'recurring'])) continue;
            $hwTitle = strtolower($hw['title'] ?? '');
            if (strpos($hwTitle, '/mo') !== false || strpos($hwTitle, 'plan') !== false
                || strpos($hwTitle, 'subscription') !== false) continue;

            if ($hwPrice > 0 || !empty($hw['title'])) {
                $cartItems[] = [
                    'title' => $hw['title'] ?? 'Hardware',
                    'qty'   => max(1, (int)($hw['qty'] ?? 1)),
                    'price' => $hwPrice,
                ];
            }
        }

        // If no cart items, add single device as fallback
        if (empty($cartItems) && !empty($kitSerial) && $kitSerial !== 'To be assigned') {
            $devPrice = (float)($app['device_price'] ?? 0);
            if ($devPrice <= 0) {
                // Use total minus subscription as hardware price estimate
                $subAmt = (float)($app['subscription_amount'] ?? $app['monthly_fee'] ?? 0);
                $devPrice = max(0, (float)($app['amount_charged'] ?? 0) - $subAmt);
            }
            if ($devPrice > 0) {
                $devTitle = trim($app['device_name'] ?? '');
                if (empty($devTitle)) $devTitle = $isFiber ? 'Fiber Equipment' : 'Starlink Kit';
                $cartItems[] = ['title' => $devTitle, 'qty' => 1, 'price' => $devPrice];
            }
        }

        // Build HTML items table
        $itemsHtml = '';
        if (!empty($cartItems)) {
            $itemsHtml .= '<table style="width:100%;border-collapse:collapse;margin-bottom:10px;">';
            $itemsHtml .= '<tr><th style="text-align:left;font-size:8px;color:#999;padding:4px 0;border-bottom:1px solid #e0e0e0;width:10%;">Qty</th>'
                        . '<th style="text-align:left;font-size:8px;color:#999;padding:4px 0;border-bottom:1px solid #e0e0e0;width:60%;">Item</th>'
                        . '<th style="text-align:right;font-size:8px;color:#999;padding:4px 0;border-bottom:1px solid #e0e0e0;width:30%;">Price</th></tr>';
            $grandTotal = 0;
            foreach ($cartItems as $ci) {
                $lineTotal = $ci['price'] * $ci['qty'];
                $grandTotal += $lineTotal;
                $itemsHtml .= '<tr>'
                    . '<td style="font-size:10px;padding:5px 0;border-bottom:1px solid #f0f0f0;">' . $ci['qty'] . '</td>'
                    . '<td style="font-size:10px;font-weight:600;padding:5px 0;border-bottom:1px solid #f0f0f0;">' . htmlspecialchars($ci['title']) . '</td>'
                    . '<td style="font-size:10px;font-weight:700;text-align:right;padding:5px 0;border-bottom:1px solid #f0f0f0;">USD ' . number_format($lineTotal, 2) . '</td>'
                    . '</tr>';
            }
            $itemsHtml .= '<tr><td></td><td style="font-size:10px;font-weight:800;padding:6px 0;">Total</td>'
                        . '<td style="font-size:12px;font-weight:800;color:#059669;text-align:right;padding:6px 0;">USD ' . number_format($grandTotal, 2) . '</td></tr>';
            $itemsHtml .= '</table>';
        }

        $dateStr   = date('d.m.Y');
        $dateTime  = date('d M Y, g:i A');

        // ── 3. Render HTML template ────────────────────────────────────────
        // __DIR__ = plugin_root/lib/ → dirname(__DIR__) = plugin_root (reliable on UCRM)
        $pluginRoot   = dirname(__DIR__);
        $templatePath = $pluginRoot . '/' . self::TEMPLATE_DIR . '/delivery_' . $template . '.html';
        if (!file_exists($templatePath)) {
            return ['ok' => false, 'error' => "Template not found: {$templatePath}"];
        }

        $html = file_get_contents($templatePath);
        $replacements = [
            '{{KYC_REF}}'      => $kycRef,
            '{{DATE}}'         => $dateStr,
            '{{DATE_TIME}}'    => $dateTime,
            '{{FULL_NAME}}'    => htmlspecialchars($fullName),
            '{{PHONE}}'        => htmlspecialchars($phone),
            '{{LOCATION}}'     => htmlspecialchars($location),
            '{{KIT_SERIAL}}'   => htmlspecialchars($kitSerial),
            '{{PLAN_NAME}}'    => htmlspecialchars($planName),
            '{{AMOUNT}}'       => $amount,
            '{{INSTALL_FEE}}'  => $installFee,
            '{{MONTHLY_FEE}}'  => $monthlyFee,
            '{{STAFF_NAME}}'   => htmlspecialchars($staffName),
            '{{STAFF_ROLE}}'   => htmlspecialchars($staffRole),
            '{{ITEMS_TABLE}}'  => $itemsHtml,
        ];
        $html = str_replace(array_keys($replacements), array_values($replacements), $html);

        // ── 4. Convert to PDF ──────────────────────────────────────────────
        $pdfDir = $this->dataDir . '/' . self::PDF_DIR;
        if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

        $slug     = preg_replace('/[^a-zA-Z0-9]/', '_', $fullName);
        $pdfFile  = "DishNet_{$template}_{$kycRef}_{$slug}.pdf";
        $pdfPath  = $pdfDir . '/' . $pdfFile;

        // Write temp HTML
        $tmpHtml = $this->dataDir . '/temp_delivery_' . $appId . '.html';
        file_put_contents($tmpHtml, $html);

        // ── Convert HTML → PDF via Chromium PDF service ──
        $pdfServiceUrl = $this->config['pdf_service_url']
            ?? 'https://n8n-dishnet-pdf-service.4zz82b.easypanel.host/forms/chromium/convert/html';

        $cfile = new \CURLFile($tmpHtml, 'text/html', 'index.html');
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
        @unlink($tmpHtml);

        if ($httpCode === 200 && $pdfContent && strlen($pdfContent) > 100) {
            file_put_contents($pdfPath, $pdfContent);
        } else {
            // Fallback: try wkhtmltopdf locally
            $wk = $this->findWkhtmltopdf();
            if ($wk) {
                file_put_contents($tmpHtml, $html);
                $cmd = escapeshellarg($wk)
                     . ' --quiet --page-size A4 --margin-top 10mm --margin-bottom 10mm'
                     . ' --margin-left 12mm --margin-right 12mm'
                     . ' ' . escapeshellarg($tmpHtml)
                     . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
                exec($cmd);
                @unlink($tmpHtml);
            }
            if (!file_exists($pdfPath)) {
                return ['ok' => false, 'error' => "PDF service failed: HTTP {$httpCode} {$curlErr}"];
            }
        }

        if (!file_exists($pdfPath)) {
            return ['ok' => false, 'error' => 'PDF generation failed'];
        }

        // ── 5. Create HMAC token for public serving ────────────────────────
        $secret   = $this->config['webhook_secret'] ?? 'dishnet';
        $pdfToken = hash_hmac('sha256', $pdfFile . date('Ymd'), $secret);

        // Save metadata for serve_delivery_pdf endpoint
        file_put_contents($pdfPath . '.meta', json_encode([
            'token'      => $pdfToken,
            'created'    => time(),
            'kyc_ref'    => $kycRef,
            'customer'   => $fullName,
            'filename'   => str_replace('_', '-', $pdfFile),
            'template'   => $template,
        ], JSON_PRETTY_PRINT));

        // ── 6. Build public URL ────────────────────────────────────────────
        $siteUrl = rtrim($this->config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/');
        $siteUrl = preg_replace('#/api/v[0-9.]+$#', '', $siteUrl);
        $siteUrl = preg_replace('#/crm$#', '', $siteUrl);
        $pdfUrl  = $siteUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php'
                 . '?page=api&action=serve_delivery_pdf'
                 . '&file=' . urlencode($pdfFile)
                 . '&token=' . urlencode($pdfToken);

        // ── 7. Build caption (welcome + next steps) ────────────────────────
        $caption = $this->buildCaption($template, $fullName, $app, $staffName);

        // ── 8. Send via WASender ───────────────────────────────────────────
        $sent = false;
        $customerPhone = preg_replace('/[^0-9+]/', '', $phone);
        if ($customerPhone) {
            try {
                require_once __DIR__ . '/NotificationService.php';
                $notify = new NotificationService($this->store, $this->config);
                $displayName = "DishNet_" . ucfirst($template) . "_{$kycRef}.pdf";
                $notify->sendDocument(
                    NotificationService::SUPPORT,
                    $customerPhone,
                    $pdfUrl,
                    $displayName,
                    $caption,
                    'delivery_terms_' . $template
                );
                $sent = true;
            } catch (\Throwable $e) {
                // Non-fatal — PDF is saved, can be resent
            }
        }

        // ── 9. Update KYC application with terms info ──────────────────────
        try {
            $apps = $this->store->load('kyc_applications.json') ?: [];
            foreach ($apps as &$a) {
                if ((int)($a['id'] ?? 0) === $appId) {
                    $a['terms_pdf_path']   = self::PDF_DIR . '/' . $pdfFile;
                    $a['terms_sent_at']    = $sent ? date('Y-m-d H:i:s') : null;
                    $a['terms_template']   = $template;
                    $a['terms_kyc_ref']    = $kycRef;
                    $a['delivery_pdf_sent'] = $sent;
                    break;
                }
            }
            unset($a);
            $this->store->save('kyc_applications.json', $apps);
        } catch (\Throwable $e) {
            // Non-fatal — PDF exists regardless
        }

        return [
            'ok'       => true,
            'pdf_path' => self::PDF_DIR . '/' . $pdfFile,
            'filename' => $pdfFile,
            'sent'     => $sent,
            'kyc_ref'  => $kycRef,
        ];
    }

    /**
     * Build caption text for the PDF document.
     * This replaces the separate kycCrmCreated() customer welcome message.
     */
    private function buildCaption(string $template, string $fullName, array $app, string $staffName): string
    {
        $firstName = trim($app['firstname'] ?? $fullName);
        // Use first name for friendlier tone; fall back to full name
        $salutation = $firstName ?: $fullName;
        $company    = trim($app['company_name'] ?? '');
        if ($company !== '') $salutation = $company;

        if ($template === 'starlink') {
            $kitSerial = trim($app['kit_serial'] ?? $app['starlink_serial'] ?? $app['device_serial'] ?? 'pending');
            $amount    = number_format((float)($app['amount_charged'] ?? 0), 2);

            return "\xF0\x9F\x8C\x9F *DishNet Africa \xe2\x80\x94 Starlink confirmed!*\n\n"
                 . "Dear {$salutation},\n\n"
                 . "Your Starlink purchase is confirmed \xe2\x9c\x85\n\n"
                 . "\xF0\x9F\x93\xA6 KIT: {$kitSerial}\n"
                 . "\xF0\x9F\x92\xB0 Paid: \${$amount}\n"
                 . "\xF0\x9F\x91\xA4 Staff: {$staffName}\n\n"
                 . "\xF0\x9F\x94\x84 *What happens next:*\n"
                 . "\xF0\x9F\x93\x9E Our team will call you within 24hrs to schedule setup\n"
                 . "\xF0\x9F\x9B\xB0 Starlink setup: 1\xe2\x80\x932 working days after scheduling\n"
                 . "\xF0\x9F\x93\xB6 We'll activate your service and confirm via WhatsApp\n\n"
                 . "\xF0\x9F\x93\x84 *Your delivery document is attached above* \xe2\x80\x94 it contains your full terms and conditions. Please save it for your records.\n\n"
                 . "\xF0\x9F\x93\xB2 Sales: wa.me/211923400000\n"
                 . "\xF0\x9F\x9B\xA0 Support: wa.me/211921443002\n\n"
                 . "Thank you for choosing DishNet Africa \xF0\x9F\x9A\x80";
        }

        // Fiber
        $planName   = trim($app['plan_name'] ?? $app['subscription_plan'] ?? 'Fiber Plan');
        $installFee = number_format((float)($app['installation_fee'] ?? $app['amount_charged'] ?? 0), 2);
        $monthlyFee = number_format((float)($app['monthly_fee'] ?? $app['subscription_amount'] ?? 0), 2);

        // Post-installation: service is already active — different message from pre-install KYC
        if (!empty($app['installation_done'])) {
            return "\xF0\x9F\x9F\xA2 *DishNet Africa \xe2\x80\x94 Your Fiber is LIVE!*\n\n"
                 . "Dear {$salutation},\n\n"
                 . "Your DishNet Fiber connection is now active \xe2\x9c\x85\n\n"
                 . "\xF0\x9F\x8C\x90 Plan: {$planName}\n"
                 . "\xF0\x9F\x92\xB0 Monthly: \${$monthlyFee}/mo\n"
                 . "\xF0\x9F\x91\xA4 Installed by: {$staffName}\n\n"
                 . "\xF0\x9F\x93\x84 *Your delivery acknowledgment is attached* \xe2\x80\x94 it confirms the equipment received and your service terms. Please save it for your records.\n\n"
                 . "Need help? We're here 24/7:\n"
                 . "\xF0\x9F\x9B\xA0 Support: wa.me/211921443002\n"
                 . "\xF0\x9F\x93\xB2 Sales: wa.me/211923400000\n\n"
                 . "Welcome to DishNet Fiber \xF0\x9F\x9A\x80";
        }

        // Pre-installation (KYC stage): installation not yet done
        return "\xF0\x9F\x8C\x9F *DishNet Africa \xe2\x80\x94 Fiber confirmed!*\n\n"
             . "Dear {$salutation},\n\n"
             . "Your DishNet Fiber service is confirmed \xe2\x9c\x85\n\n"
             . "\xF0\x9F\x8C\x90 Plan: {$planName}\n"
             . "\xF0\x9F\x92\xB0 Install: \${$installFee} | Monthly: \${$monthlyFee}/mo\n"
             . "\xF0\x9F\x91\xA4 Staff: {$staffName}\n\n"
             . "\xF0\x9F\x94\x84 *What happens next:*\n"
             . "\xF0\x9F\x93\x9E Our team will call you within 24hrs to schedule installation\n"
             . "\xF0\x9F\x94\xB4 Fiber installation: 3\xe2\x80\x935 working days after scheduling\n"
             . "\xF0\x9F\x93\xB6 We'll confirm the date and technician details via WhatsApp\n\n"
             . "\xF0\x9F\x93\x84 *Your service document is attached above* \xe2\x80\x94 it contains your full terms and conditions. Please save it for your records.\n\n"
             . "\xF0\x9F\x93\xB2 Sales: wa.me/211923400000\n"
             . "\xF0\x9F\x9B\xA0 Support: wa.me/211921443002\n\n"
             . "Thank you for choosing DishNet Africa \xF0\x9F\x9A\x80";
    }

    /**
     * Find wkhtmltopdf binary.
     */
    private function findWkhtmltopdf(): ?string
    {
        $paths = ['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf'];
        foreach ($paths as $p) {
            if (file_exists($p) && is_executable($p)) return $p;
        }
        // Try PATH
        $which = trim(shell_exec('which wkhtmltopdf 2>/dev/null') ?: '');
        return ($which && file_exists($which)) ? $which : null;
    }
}
