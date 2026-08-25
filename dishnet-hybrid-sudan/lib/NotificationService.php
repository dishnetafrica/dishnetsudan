<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }

/**
 * NotificationService v2.0
 *
 * Sends WhatsApp messages by calling the wa-whatsappsender UCRM plugin's
 * ?action=send endpoint. Credentials live ONLY in that plugin.
 *
 * For every event, we pass:
 *   - message  : default text built here (used if no template configured)
 *   - event    : the ops_* key (e.g. "ops_kyc_submitted")
 *   - vars     : flat key=>value pairs for %%token%% replacement in templates
 *
 * This means you can override any message from the WASender plugin's
 * Settings page without touching code.
 *
 * Config keys (Settings tab in Hybrid plugin):
 *   wa_plugin_url        — public URL of wa-whatsappsender plugin
 *   wa_app_key           — same App Key as in WASender plugin
 *   wa_auth_key          — same Auth Key as in WASender plugin
 *   whatsapp_admin_phone — internal admin alert number (digits only)
 */
class NotificationService
{
    const LOG_FILE    = 'notification_log.json';
    const TIMEOUT_SEC = 8;
    const SUPPORT     = 'support';
    const ACCOUNTS    = 'accounts';

    // Rate limiter: max messages per window to protect WASender + WhatsApp number
    const RATE_MAX_PER_WINDOW = 10;  // max sends
    const RATE_WINDOW_SEC     = 5;   // per N seconds

    private $store;
    private string $pluginUrl;
    private string $appKey;
    private string $authKey;
    private string $accountsAppKey;
    private bool   $forceAccounts = false;
    private string $adminPhone;
    private bool   $enabled;
    private bool   $dryRunMode = false;
    private bool   $pdfEnabled = true;
    private string $dataDir = '';
    /** @var float[] Timestamps of recent sends for rate limiting */
    private array  $sendTimestamps = [];

    public function __construct($store, array $config)
    {
        $this->store      = $store;
        // wa_plugin_url = base URL of the WhatsApp server
        // e.g. http://wa.dishnetafrica.com  (NOT the UCRM plugin path)
        $this->pluginUrl  = rtrim(trim($config['wa_plugin_url'] ?? ''), '/');
        // Support sender app_key (default / primary WASender app)
        $this->appKey     = trim($config['wa_app_key']  ?? '');
        // Accounts sender app_key (separate WASender app bound to Accounts phone)
        // Falls back to support app_key if not configured
        $this->accountsAppKey = trim($config['wa_accounts_app_key'] ?? '') ?: $this->appKey;
        // auth_key is user-level — same for all apps in WASender
        $this->authKey    = trim($config['wa_auth_key'] ?? '');
        $this->adminPhone = preg_replace('/[^0-9]/', '', trim($config['whatsapp_admin_phone'] ?? ''));
        // Enabled as long as URL + app_key + auth_key are set
        $this->enabled    = !empty($this->pluginUrl) && !empty($this->appKey) && !empty($this->authKey);
        // ── v4.21.114: "Route all via Accounts" emergency toggle ──────────
        // When the Support WhatsApp number gets blocked, flip this ON in
        // Settings → WhatsApp → Config. Every SUPPORT-channel send (and every
        // sendDocument / sendImage call) then uses the Accounts app key, so
        // ALL traffic goes out on the Accounts number until Support recovers.
        // Routing is decided purely by app_key — phone-number fields are labels.
        $this->forceAccounts = ($config['wa_force_accounts'] ?? false) === true
                            || ($config['wa_force_accounts'] ?? '') === '1'
                            || ($config['wa_force_accounts'] ?? '') === 1;
        if ($this->forceAccounts) {
            // Collapse the Support key onto the Accounts key. accountsAppKey
            // already falls back to appKey if unset, so this is safe even if
            // only one key is configured.
            $this->appKey = $this->accountsAppKey;
        }
        // Dry run mode - log but don't send
        $this->dryRunMode = (bool)($config['dry_run_mode'] ?? false);
        // v4.9.20: Global kill-switch for PDF document sending via WhatsApp.
        // Set "wa_send_pdf": false in kyc_config.json to disable all 6 PDF flows
        // (invoice PDF, quote PDF, overdue PDF, manual quote send, etc.)
        // Text-only notifications are NOT affected — only sendDocument() calls.
        $this->pdfEnabled = ($config['wa_send_pdf'] ?? true) !== false;
        // data_dir must be passed via config (set from $dataDir in public.php/cron)
        // The dirname(__DIR__) fallback is the installation dir — wrong after plugin updates
        $this->dataDir    = $config['data_dir'] ?? (defined('PLUGIN_DATA_DIR') ? PLUGIN_DATA_DIR : sys_get_temp_dir());
    }
    
    /**
     * Check if dry run mode is enabled
     */
    public function isDryRunMode(): bool
    {
        return $this->dryRunMode;
    }
    
    /**
     * Set dry run mode dynamically
     */
    public function setDryRunMode(bool $enabled): void
    {
        $this->dryRunMode = $enabled;
    }
    
    /**
     * Log dry run notification (for debugging/audit)
     */
    private function logDryRunNotification(string $phone, string $message, string $event, array $vars = []): void
    {
        $logEntry = [
            'status'    => 'dry_run_skipped',
            'phone'     => $phone,
            'event'     => $event,
            'message'   => substr($message, 0, 200) . (strlen($message) > 200 ? '...' : ''),
            'vars'      => $vars,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        
        // Log to file for debugging
        $logFile = $this->dataDir . '/dry_run_notification_log.json';
        $existing = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
        if (!is_array($existing)) $existing = [];
        $existing[] = $logEntry;
        // Keep last 500 entries
        if (count($existing) > 500) {
            $existing = array_slice($existing, -500);
        }
        @file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));
    }

    // ══════════════════════════════════════════════════════════════════════
    // KYC / ONBOARDING  →  SUPPORT
    // ══════════════════════════════════════════════════════════════════════

    public function kycSubmitted(array $retailer, array $application): void
    {
        $customerName = trim(($application['firstname'] ?? '') . ' ' . ($application['lastname'] ?? ''));
        $type   = $application['customer_type'] ?? $application['connectivity_type'] ?? 'New';
        $amount = number_format((float)($application['amount_charged'] ?? 0), 2);
        $appId  = (string)($application['id'] ?? '-');
        $phone  = preg_replace('/[^0-9+]/', '', $application['mobile'] ?? '');
        
        // Extract area from address
        $area = trim($application['address_2'] ?? $application['area'] ?? '');
        if (empty($area)) {
            // Try to extract from address_1
            $addr1 = $application['address_1'] ?? '';
            $parts = array_filter(array_map('trim', explode(',', $addr1)));
            $area = end($parts) ?: 'Juba';
        }
        
        // Determine source based on lead origin, sales type, AND actual amount
        $fromLeadId = $application['from_lead_id'] ?? null;
        $salesType  = $application['sales_type'] ?? 'Cash';
        $amountNum  = (float)($application['amount_charged'] ?? 0);
        
        if ($fromLeadId) {
            $source = "🎯 From Lead #{$fromLeadId}";
        } elseif (strtolower($salesType) === 'credit') {
            $source = "📝 Credit Sale (Lead)";
        } elseif ($amountNum > 0) {
            $source = "💳 Cash Sale";
        } else {
            // Cash selected but $0 amount = actually a lead (no payment collected)
            $source = "📋 Lead (No Payment)";
        }

        $msg = "📋 *New Registration*\n\n"
             . "👤 {$customerName}\n"
             . "📍 {$area}\n"
             . "📶 {$type} | \${$amount}\n\n"
             . "{$source}\n"
             . "🔖 App #{$appId}\n\n"
             . "👷 Agent: {$retailer['name']}\n"
             . "📱 {$phone}";

        $vars = [
            'agent_name'     => $retailer['name'],
            'customer_name'  => $customerName,
            'customer_phone' => $phone,
            'service_type'   => $type,
            'amount'         => $amount,
            'app_id'         => $appId,
            'area'           => $area,
            'source'         => $source,
        ];

        $this->sendVia(self::SUPPORT, $retailer['phone'] ?? '', $msg, 'ops_kyc_submitted', $vars);
        $this->sendAdmin($msg);
    }

    /**
     * Notify Bidal (support_leader) + admin + agent when an EXISTING CRM customer
     * registers an ADDITIONAL service at a NEW location.
     *
     * Distinct from kycSubmitted() because:
     *   • The customer is already in CRM — DO NOT create a duplicate client
     *   • The new service goes at a NEW physical address — existing site stays untouched
     *   • A CRM scheduling job is auto-created and assigned to Bidal (handled by KycService)
     *
     * Recipients:
     *   • Agent — confirmation that quote was generated
     *   • Bidal — action: new install at new site for existing client
     *   • Admin — visibility
     *
     * @param array $retailer       Agent who submitted the KYC
     * @param array $application    Saved kyc_applications.json record (must have is_additional_service=true)
     * @param array $extras         Optional: ['quote_ref'=>..., 'crm_job_id'=>..., 'new_address'=>...]
     */
    public function kycAdditionalService(array $retailer, array $application, array $extras = []): void
    {
        // ── Compose customer identity ──────────────────────────────────────
        $firstName    = trim($application['firstname'] ?? '');
        $lastName     = trim($application['lastname']  ?? '');
        $fullName     = trim("{$firstName} {$lastName}");
        if ($fullName === '') $fullName = 'Customer';

        $crmClientId  = (string)($application['crm_client_id'] ?? '');
        $appId        = (string)($application['id'] ?? '-');
        $serviceType  = $application['customer_type'] ?? $application['connectivity_type'] ?? 'Service';
        $amount       = number_format((float)($application['amount_charged'] ?? 0), 2);
        $custPhone    = preg_replace('/[^0-9+]/', '', $application['mobile'] ?? '');

        // New service address — prefer extras override, fall back to saved record
        $newAddress = trim($extras['new_address'] ?? $application['new_service_address'] ?? '');
        if ($newAddress === '') {
            $newAddress = trim(($application['address_1'] ?? '') . ', ' . ($application['address_2'] ?? ''));
            $newAddress = rtrim($newAddress, ', ');
        }
        if ($newAddress === '') $newAddress = '(address not specified)';

        $quoteRef  = trim((string)($extras['quote_ref']  ?? $application['quote_ref']  ?? ''));
        $crmJobId  = (int)($extras['crm_job_id'] ?? $application['crm_job_id'] ?? 0);
        $salesType = strtolower($application['sales_type'] ?? 'credit');
        $sourceLine = ($salesType === 'cash' && (float)($application['amount_charged'] ?? 0) > 0)
            ? '💳 Cash Sale'
            : '📝 Credit Sale (Lead)';

        $quoteLine = $quoteRef !== '' ? "📄 Quote: {$quoteRef}\n" : '';
        $jobLine   = $crmJobId  > 0   ? "🗓 CRM Job #{$crmJobId}\n" : '';

        // ── Message for Bidal + Admin (full operational context) ──────────
        $bidalMsg = "🔄 *Additional Service — Existing Customer*\n\n"
                  . "👤 {$fullName}\n"
                  . "🔗 CRM #{$crmClientId} (existing client)\n"
                  . "📍 *NEW SITE:* {$newAddress}\n"
                  . "📶 {$serviceType} | \${$amount}\n\n"
                  . "{$sourceLine}\n"
                  . $quoteLine
                  . $jobLine
                  . "🔖 App #{$appId}\n\n"
                  . "⚠️ This is an additional location for an existing customer.\n"
                  . "Do NOT modify the existing service. Create a new service line at the new site.\n\n"
                  . "👷 Agent: " . ($retailer['name'] ?? '-') . "\n"
                  . "📱 {$custPhone}";

        $vars = [
            'agent_name'     => $retailer['name'] ?? '',
            'customer_name'  => $fullName,
            'customer_phone' => $custPhone,
            'crm_id'         => $crmClientId,
            'service_type'   => $serviceType,
            'amount'         => $amount,
            'app_id'         => $appId,
            'new_address'    => $newAddress,
            'quote_ref'      => $quoteRef,
            'crm_job_id'     => (string)$crmJobId,
            'source'         => $sourceLine,
        ];

        // ── Resolve Bidal (support_leader) phone from retailers.json ──────
        $bidalPhone = '';
        try {
            $retailers = $this->store->load('retailers.json') ?? [];
            foreach ($retailers as $r) {
                if (($r['role'] ?? '') === 'support_leader' && !empty($r['phone'])) {
                    $bidalPhone = preg_replace('/[^0-9+]/', '', $r['phone']);
                    break;
                }
            }
        } catch (\Throwable $e) {
            error_log('[NotificationService] kycAdditionalService: retailers lookup failed: ' . $e->getMessage());
        }

        // ── Send to Bidal (action recipient — full context) ───────────────
        if ($bidalPhone !== '') {
            $this->sendVia(self::SUPPORT, $bidalPhone, $bidalMsg, 'ops_kyc_additional_service', $vars);
        }

        // ── Send to admin (visibility) ────────────────────────────────────
        $this->sendAdmin($bidalMsg, 'ops_kyc_additional_service', $vars);

        // ── Send shorter confirmation to the agent ────────────────────────
        $agentMsg = "✅ *Additional Service Submitted*\n\n"
                  . "👤 {$fullName}\n"
                  . "🔗 CRM #{$crmClientId} (existing)\n"
                  . "📍 New site: {$newAddress}\n"
                  . "📶 {$serviceType} | \${$amount}\n\n"
                  . $quoteLine
                  . $jobLine
                  . "🔖 App #{$appId}\n\n"
                  . "Bidal has been notified to schedule the new install.";

        $this->sendVia(self::SUPPORT, $retailer['phone'] ?? '', $agentMsg, 'ops_kyc_additional_service', $vars);
    }

    public function kycCrmCreated(array $retailer, array $application, string $crmClientId): void
    {
        $firstName    = trim($application['firstname'] ?? '');
        $lastName     = trim($application['lastname']  ?? '');
        $fullName     = trim("{$firstName} {$lastName}");
        $companyName  = trim($application['company_name'] ?? '');
        // Mirror UCRM %%client.companyName%%%%client.firstName%% %%client.lastName%% logic:
        // show company name if set, otherwise first + last
        $salutation   = $companyName !== '' ? $companyName : $fullName;
        $username     = $application['username'] ?? '-';
        $serviceType  = strtolower($application['customer_type'] ?? $application['connectivity_type'] ?? '');
        $isFiber      = str_contains($serviceType, 'fiber') || str_contains($serviceType, 'ftth');
        $isLte        = str_contains($serviceType, 'lte') || str_contains($serviceType, '4g') || str_contains($serviceType, 'private');
        $isSim        = str_contains($serviceType, 'sim') || str_contains($serviceType, 'mobile');
        $isStarlink   = !$isFiber && !$isLte && !$isSim; // default

        // ── Agent notification ──────────────────────────────────────────
        $appId = $application['id'] ?? '-';
        $area = trim($application['address_2'] ?? $application['area'] ?? '');
        if (empty($area)) {
            $addr1 = $application['address_1'] ?? '';
            $parts = array_filter(array_map('trim', explode(',', $addr1)));
            $area = end($parts) ?: '';
        }
        
        // Determine next step based on quote/payment status
        $hasQuote   = !empty($application['quote_id']);
        $hasPaid    = !empty($application['payment_id']) || !empty($application['payment_created']);
        $nextStep   = $hasPaid ? 'Schedule installation' : ($hasQuote ? 'Awaiting payment' : 'Create quote');
        
        $serviceLabel = $application['customer_type'] ?? 'Service';
        $areaLine = $area ? " | {$area}" : '';
        
        $agentMsg = "✅ *CRM Account Created*\n\n"
                  . "👤 {$fullName}\n"
                  . "🔗 CRM #{$crmClientId} | {$username}\n"
                  . "📶 {$serviceLabel}{$areaLine}\n\n"
                  . "📋 App #{$appId} in Plugin\n"
                  . "➡️ Next: {$nextStep}";
        
        $this->sendVia(self::SUPPORT, $retailer['phone'] ?? '', $agentMsg,
            'ops_kyc_crm_created',
            [
                'agent_name'    => $retailer['name'],
                'customer_name' => $fullName,
                'crm_id'        => $crmClientId,
                'username'      => $username,
                'app_id'        => (string)$appId,
                'service_type'  => $serviceLabel,
                'area'          => $area,
                'next_step'     => $nextStep,
            ]
        );

        // ── Customer booking confirmation ──────────────────────────────
        // Skip if delivery PDF + caption was already sent (cash sale with payment).
        // The PDF caption contains the welcome message, next steps, and contacts.
        // Credit/lead customers still get this welcome message.
        $deliveryPdfSent = !empty($application['delivery_pdf_sent']);
        $customerPhone = preg_replace('/[^0-9+]/', '', $application['mobile'] ?? '');
        if ($customerPhone && !$deliveryPdfSent) {
            // Timeline — highlight the customer's actual service
            $fiberLine    = $isFiber
                ? "🔴 *Fiber: 3–5 working days* ← Your service"
                : "🔴 Fiber: 3–5 working days";
            $starlinkLine = $isStarlink
                ? "🛰 *Starlink: 1–2 working days* ← Your service"
                : "🛰 Starlink: 1–2 working days";
            $lteLine      = $isLte
                ? "📶 *DishNet 4G: 2–4 working days* ← Your service"
                : "📶 DishNet 4G: 2–4 working days";
            $simLine      = $isSim
                ? "📱 *SIM Data: same day activation* ← Your service"
                : null; // only show SIM line if relevant

            $timelineLines = "{$fiberLine}\n{$starlinkLine}\n{$lteLine}";
            if ($simLine) $timelineLines .= "\n{$simLine}";

            $msg = "🌟 *DishNet Africa – Request Confirmed!*\n\n"
                 . "Dear {$salutation},\n\n"
                 . "Your request for DishNet services has been successfully booked ✅\n\n"
                 . "🔄 *Next Steps:*\n"
                 . "📞 Our support team will call you shortly to schedule installation.\n\n"
                 . "⏱ *Installation Timeline:*\n"
                 . "{$timelineLines}\n\n"
                 . "📲 Sales: wa.me/211923400000\n"
                 . "🛠 Support: wa.me/211921443002\n\n"
                 . "Thank you for choosing DishNet Africa 🚀";

            $this->sendVia(self::SUPPORT, $customerPhone, $msg,
                'ops_kyc_customer_welcome',
                [
                    'customer_name'  => $fullName,
                    'salutation'     => $salutation,
                    'service_type'   => $application['customer_type'] ?? '',
                    'username'       => $username,
                    'crm_id'         => $crmClientId,
                    'app_id'         => (string)$appId,
                ]
            );
        }
        
        // ── Create lifecycle record for tracking ─────────────────────────────
        try {
            require_once __DIR__ . '/LifecycleService.php';
            $lifecycle = new LifecycleService($this->store->getPdo(), dirname(__DIR__) . '/data');
            $lifecycle->createFromApplication($application, (int)$crmClientId ?: null);
        } catch (Throwable $e) {
            // Silently fail - lifecycle tracking is not critical
        }
    }

    public function kycCrmFailed(array $retailer, array $application, string $lastError, float $newBalance = 0): void
    {
        $customerName = trim(($application['firstname'] ?? '') . ' ' . ($application['lastname'] ?? ''));
        $refunded     = number_format((float)($application['amount_charged'] ?? 0), 2);
        $appId        = $application['id'] ?? '-';
        $serviceType  = $application['customer_type'] ?? $application['connectivity_type'] ?? '';
        $balanceLine  = $newBalance > 0 ? "\n💼 Your balance: \$" . number_format($newBalance, 2) : '';
        $serviceLine  = $serviceType ? " | {$serviceType}" : '';
        
        // Parse common errors into helpful guidance
        $guidance = '';
        $errorLower = strtolower($lastError);
        if (strpos($errorLower, 'duplicate') !== false || strpos($errorLower, 'exists') !== false) {
            $guidance = "1. Search CRM for existing account\n2. Use different email/phone\n3. Resubmit from Applications tab";
        } elseif (strpos($errorLower, 'email') !== false) {
            $guidance = "1. Verify email format is correct\n2. Try a different email\n3. Resubmit from Applications tab";
        } elseif (strpos($errorLower, 'timeout') !== false || strpos($errorLower, 'connection') !== false) {
            $guidance = "1. Wait 5 minutes\n2. Try again from Applications tab\n3. Contact support if it persists";
        } else {
            $guidance = "1. Check the error details\n2. Fix the issue\n3. Resubmit from Applications tab";
        }

        $msg = "⚠️ *CRM Account Failed*\n\n"
             . "👤 {$customerName}\n"
             . "📋 App #{$appId}{$serviceLine}\n\n"
             . "❌ *Error:* {$lastError}\n\n"
             . "💰 *Wallet refunded:* \${$refunded}"
             . $balanceLine . "\n\n"
             . "📋 *Next steps:*\n"
             . $guidance . "\n\n"
             . "❓ Need help? +211 921 443 006";
        
        $vars = [
            'agent_name'      => $retailer['name'],
            'customer_name'   => $customerName,
            'amount_refunded' => $refunded,
            'error'           => $lastError,
            'app_id'          => (string)$appId,
            'service_type'    => $serviceType,
        ];

        $this->sendVia(self::SUPPORT, $retailer['phone'] ?? '', $msg, 'ops_kyc_crm_failed', $vars);
        $this->sendAdmin($msg);
    }

    // ══════════════════════════════════════════════════════════════════════
    // WALLET / RECHARGE  →  SUPPORT
    // ══════════════════════════════════════════════════════════════════════

    public function walletToppedUp(array $retailer, float $amount, float $newBalance, string $note, string $addedBy = 'Finance Team'): void
    {
        $a = number_format($amount, 2);
        $b = number_format($newBalance, 2);
        $ref = 'WC-' . date('Y') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $noteLine = $note ? "\n📝 {$note}" : '';
        
        $msg = "💰 *Wallet Credit Added*\n\n"
             . "Hi {$retailer['name']},\n\n"
             . "➕ Added: *\${$a}*\n"
             . "💼 Balance: *\${$b}*"
             . $noteLine . "\n\n"
             . "👤 By: {$addedBy}\n"
             . "🔖 Ref: {$ref}";
        
        $this->sendVia(self::SUPPORT, $retailer['phone'] ?? '', $msg,
            'ops_wallet_topped_up',
            ['agent_name'=>$retailer['name'],'amount'=>$a,'new_balance'=>$b,'note'=>$note,'added_by'=>$addedBy,'ref'=>$ref]
        );
    }

    public function rechargeSubmitted(array $retailer, float $amount, int $requestId, string $paymentMethod = '', bool $hasScreenshot = false): void
    {
        $a = number_format($amount, 2);
        $methodLine = $paymentMethod ? "\n💳 Method: {$paymentMethod}" : '';
        $screenshotLine = $hasScreenshot ? "\n📎 Screenshot attached" : '';
        $area = $retailer['area'] ?? '';
        $areaLine = $area ? " | {$area}" : '';
        
        $msg = "💳 *Recharge Request*\n\n"
             . "👤 {$retailer['name']}{$areaLine}\n"
             . "💰 Amount: *\${$a}*"
             . $methodLine
             . $screenshotLine . "\n\n"
             . "🔖 Request #{$requestId}\n"
             . "⏰ " . date('M j, g:i A') . "\n\n"
             . "👉 Approve in Plugin → Wallet → Requests";
        
        $this->sendAdminMsg($msg, 'ops_recharge_submitted',
            ['agent_name'=>$retailer['name'],'amount'=>$a,'request_id'=>(string)$requestId,'payment_method'=>$paymentMethod]
        );
    }

    public function rechargeApproved(array $retailer, float $amount, float $newBalance, string $approvedBy, string $invoiceRef = ''): void
    {
        $a = number_format($amount, 2);
        $b = number_format($newBalance, 2);
        $invoiceLine = $invoiceRef ? "\n🧾 Invoice: {$invoiceRef}" : '';
        $this->sendVia(self::SUPPORT, $retailer['phone'] ?? '',
            "✅ *Wallet Recharge Approved — DishNet Africa*\n\n"
            . "Hi {$retailer['name']},\n\n"
            . "Your wallet has been topped up:\n"
            . "💰 Amount Added: *\${$a}*\n"
            . "📊 New Balance: *\${$b}*\n"
            . "👤 Approved by: {$approvedBy}"
            . $invoiceLine . "\n\n"
            . "This credit has been applied to your DishNet account.\n"
            . "— DishNet Finance",
            'ops_recharge_approved',
            ['agent_name'=>$retailer['name'],'amount'=>$a,'new_balance'=>$b,'approved_by'=>$approvedBy,'invoice_ref'=>$invoiceRef]
        );
    }

    public function rechargeRejected(array $retailer, float $amount, string $reason, int $requestId = 0): void
    {
        $a = number_format($amount, 2);
        $refLine = $requestId ? "🔖 Request #{$requestId}\n\n" : '';
        
        $msg = "⚠️ *Recharge Request Declined*\n\n"
             . "Hi {$retailer['name']},\n\n"
             . "💰 Amount: \${$a}\n"
             . $refLine
             . "❌ *Reason:* {$reason}\n\n"
             . "📋 *What to do:*\n"
             . "• Check your payment details\n"
             . "• Ensure screenshot is clear\n"
             . "• Resubmit from Wallet → Recharge\n\n"
             . "❓ Questions? Contact Finance";
        
        $this->sendVia(self::SUPPORT, $retailer['phone'] ?? '', $msg,
            'ops_recharge_rejected',
            ['agent_name'=>$retailer['name'],'amount'=>$a,'reason'=>$reason,'request_id'=>(string)$requestId]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // BILLING  →  ACCOUNTS
    // ══════════════════════════════════════════════════════════════════════

    public function invoiceCreated(string $customerPhone, string $customerName, string $invoiceNum, float $amount, string $dueDate, string $serviceName = ''): void
    {
        $a = number_format($amount, 2);
        $serviceLine = $serviceName ? "\n📶 Service: {$serviceName}" : '';
        
        $msg = "🧾 *New Invoice*\n\n"
             . "Dear {$customerName},\n\n"
             . "Invoice #{$invoiceNum}\n"
             . "💰 Amount: *\${$a}*\n"
             . "📅 Due: {$dueDate}"
             . $serviceLine . "\n\n"
             . "💳 Pay online:\n"
             . "https://dishnetafrica.com/tutorials/index.html\n\n"
             . "❓ Help: +211 921 443 002\n\n"
             . "📱 Manage everything on the DishNet app:\n"
             . "https://dishnetafrica.com/get-the-app.html\n"
             . "— DishNet Accounts";
        
        $this->sendVia(self::ACCOUNTS, $customerPhone, $msg,
            'ops_invoice_created',
            ['customer_name'=>$customerName,'invoice_number'=>$invoiceNum,'amount'=>$a,'due_date'=>$dueDate,'service_name'=>$serviceName]
        );
    }

    /**
     * Invoice fully covered by advance credit — customer owes $0.
     * If $creditRemaining > 0, the leftover credit carries forward.
     */
    public function invoiceAutoPaid(string $customerPhone, string $customerName, string $invoiceNum, float $amount, float $creditRemaining = 0, string $serviceName = ''): void
    {
        $a = number_format($amount, 2);
        $leftoverLine = $creditRemaining > 0
            ? "\n🏦 Remaining credit: *\$" . number_format($creditRemaining, 2) . "* (carried forward)"
            : '';
        $serviceLine = $serviceName ? "\n📶 Service: {$serviceName}" : '';

        $msg = "✅ *Invoice Auto-Paid — DishNet Africa*\n\n"
             . "Dear {$customerName},\n\n"
             . "Your invoice #{$invoiceNum} for *\${$a}* has been automatically covered by your account credit.\n\n"
             . "💰 Invoice total: \${$a}\n"
             . "✅ Covered by credit: \${$a}\n"
             . "💳 You owe: *\$0.00*"
             . $serviceLine
             . $leftoverLine . "\n\n"
             . "No action needed — your service continues uninterrupted.\n\n"
             . "Thank you for paying in advance! 🙏\n"
             . "— DishNet Accounts";

        $this->sendVia(self::ACCOUNTS, $customerPhone, $msg,
            'ops_invoice_auto_paid',
            ['customer_name'=>$customerName,'invoice_number'=>$invoiceNum,'amount'=>$a,'credit_remaining'=>number_format($creditRemaining,2),'service_name'=>$serviceName]
        );
    }

    /**
     * Invoice partially covered by credit — customer still owes the remainder.
     */
    public function invoicePartialCredit(string $customerPhone, string $customerName, string $invoiceNum, float $amount, float $creditApplied, float $remaining, string $dueDate, string $serviceName = ''): void
    {
        $a  = number_format($amount, 2);
        $ca = number_format($creditApplied, 2);
        $r  = number_format($remaining, 2);
        $serviceLine = $serviceName ? "\n📶 Service: {$serviceName}" : '';

        $msg = "🧾 *Invoice — Balance Due — DishNet Africa*\n\n"
             . "Dear {$customerName},\n\n"
             . "Your invoice #{$invoiceNum} is ready.\n\n"
             . "💰 Invoice total: \${$a}\n"
             . "✅ Credit applied: \${$ca}\n"
             . "💳 *Remaining due: \${$r}*\n"
             . "📅 Due date: {$dueDate}"
             . $serviceLine . "\n\n"
             . "Pay the remaining balance:\n"
             . "🔗 https://dishnetafrica.com/tutorials/index.html\n\n"
             . "❓ Help: +211 921 443 002\n"
             . "— DishNet Accounts";

        $this->sendVia(self::ACCOUNTS, $customerPhone, $msg,
            'ops_invoice_partial_credit',
            ['customer_name'=>$customerName,'invoice_number'=>$invoiceNum,'amount'=>$a,'credit_applied'=>$ca,'remaining'=>$r,'due_date'=>$dueDate,'service_name'=>$serviceName]
        );
    }

    public function paymentReceived(string $customerPhone, string $customerName, float $amount, string $txnId, string $invoiceNum = '', float $newBalance = 0): void
    {
        $a = number_format($amount, 2);
        $invoiceLine = $invoiceNum ? "\n🧾 Invoice: #{$invoiceNum}" : '';
        $balanceLine = $newBalance != 0 ? "\n💼 Balance: \$" . number_format($newBalance, 2) : '';
        
        $msg = "✅ *Payment Received*\n\n"
             . "Dear {$customerName},\n\n"
             . "💰 Amount: *\${$a}*\n"
             . "🔖 Ref: {$txnId}"
             . $invoiceLine
             . $balanceLine . "\n\n"
             . "Thank you for your payment! 🙏\n"
             . "— DishNet Accounts";
        
        $this->sendVia(self::ACCOUNTS, $customerPhone, $msg,
            'ops_payment_received',
            ['customer_name'=>$customerName,'amount'=>$a,'txn_id'=>$txnId,'invoice_number'=>$invoiceNum]
        );
    }

    public function lowBalanceWarning(string $customerPhone, string $customerName, float $outstanding, string $dueDate, string $invoiceNum = '', string $serviceName = ''): void
    {
        $o = number_format($outstanding, 2);
        $invoiceLine = $invoiceNum ? "🧾 Invoice: #{$invoiceNum}\n" : '';
        $serviceLine = $serviceName ? "\n⚠️ Service at risk: {$serviceName}" : '';
        
        $msg = "⏰ *Payment Reminder*\n\n"
             . "Dear {$customerName},\n\n"
             . $invoiceLine
             . "💰 Outstanding: *\${$o}*\n"
             . "📅 Due: {$dueDate}"
             . $serviceLine . "\n\n"
             . "Pay now to avoid service interruption:\n"
             . "💳 https://dishnetafrica.com/tutorials/index.html\n\n"
             . "Already paid? Reply with your receipt.\n"
             . "— DishNet Accounts";
        
        $this->sendVia(self::ACCOUNTS, $customerPhone, $msg,
            'ops_low_balance',
            ['customer_name'=>$customerName,'outstanding'=>$o,'due_date'=>$dueDate,'invoice_number'=>$invoiceNum,'service_name'=>$serviceName]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // FIELD AGENT / HANDOVER  →  SUPPORT
    // ══════════════════════════════════════════════════════════════════════

    public function remittanceSubmitted(array $agent, float $amount, string $remittedTo, int $remittanceId, float $cashCollected = 0, int $salesCount = 0): void
    {
        $a = number_format($amount, 2);
        $area = $agent['area'] ?? '';
        $areaLine = $area ? " | 📍 {$area}" : '';
        $salesLine = $salesCount > 0 ? "\n📊 Sales count: {$salesCount}" : '';
        $collectedLine = $cashCollected > 0 ? "\n💵 Cash collected: \$" . number_format($cashCollected, 2) : '';
        
        $msg = "💵 *Cash Handover Submitted*\n\n"
             . "👤 {$agent['name']}{$areaLine}\n"
             . "💰 Amount: *\${$a}*\n"
             . "📤 To: {$remittedTo}"
             . $salesLine
             . $collectedLine . "\n\n"
             . "🔖 Handover #{$remittanceId}\n"
             . "⏰ " . date('M j, g:i A') . "\n\n"
             . "👉 Count and confirm in Plugin → Cashbook";
        
        $this->sendAdminMsg($msg, 'ops_handover_submitted',
            ['agent_name'=>$agent['name'],'amount'=>$a,'remitted_to'=>$remittedTo,'ref'=>(string)$remittanceId]
        );
    }

    public function remittanceApproved(array $agent, float $amount, float $cashBalance, string $approvedBy, int $handoverId = 0): void
    {
        $a = number_format($amount, 2);
        $b = number_format($cashBalance, 2);
        $refLine = $handoverId ? "🔖 Handover #{$handoverId}\n" : '';
        
        $msg = "✅ *Handover Confirmed*\n\n"
             . "Hi {$agent['name']},\n\n"
             . "💰 Confirmed: *\${$a}*\n"
             . "💼 Cash-in-hand: *\${$b}*\n\n"
             . $refLine
             . "👤 By: {$approvedBy}\n"
             . "⏰ " . date('M j, g:i A') . "\n\n"
             . "Your records have been updated. ✅";
        
        $this->sendVia(self::SUPPORT, $agent['phone'] ?? '', $msg,
            'ops_handover_approved',
            ['agent_name'=>$agent['name'],'amount'=>$a,'cash_balance'=>$b,'approved_by'=>$approvedBy,'ref'=>(string)$handoverId]
        );
    }

    public function remittanceRejected(array $agent, float $amount, string $reason, float $discrepancy = 0, int $handoverId = 0): void
    {
        $a = number_format($amount, 2);
        $refLine = $handoverId ? "🔖 Handover #{$handoverId}\n" : '';
        $discLine = $discrepancy != 0 ? "\n📊 Discrepancy: \$" . number_format(abs($discrepancy), 2) : '';
        
        $msg = "⚠️ *Handover Needs Review*\n\n"
             . "Hi {$agent['name']},\n\n"
             . "💰 Submitted: \${$a}"
             . $discLine . "\n\n"
             . $refLine
             . "❌ *Issue:* {$reason}\n\n"
             . "📋 *Next steps:*\n"
             . "• Recount your cash\n"
             . "• Check all receipts\n"
             . "• Resubmit correct amount\n\n"
             . "❓ Contact your supervisor if needed.";
        
        $this->sendVia(self::SUPPORT, $agent['phone'] ?? '', $msg,
            'ops_handover_rejected',
            ['agent_name'=>$agent['name'],'amount'=>$a,'reason'=>$reason,'discrepancy'=>number_format(abs($discrepancy), 2)]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // STAFF CASH RECEIVED  →  ACCOUNTS
    // Called by auto-link in post_cashbook.php and post_field.php when
    // cash (USD or SSP) is disbursed to a staff member.
    // ══════════════════════════════════════════════════════════════════════

    public function staffCashReceived(string $toPhone, string $staffName, string $currency, float $amount, float $sspAmount, string $category, string $fromName, string $cbRef = ''): void
    {
        if ($currency === 'SSP') {
            $amtDisp = number_format($sspAmount, 0) . ' SSP';
        } else {
            $amtDisp = '$' . number_format($amount, 2);
        }
        $refLine = $cbRef ? "\n🔖 Ref: {$cbRef}" : '';

        $msg = "💰 *Cash Received*\n\n"
             . "Hi {$staffName},\n\n"
             . "You have received *{$amtDisp}*\n"
             . "📂 Category: {$category}\n"
             . "👤 From: {$fromName}\n"
             . "⏰ " . date('M j, g:i A')
             . $refLine . "\n\n"
             . "This has been added to your Field Register. ✅";

        $this->sendVia(self::ACCOUNTS, $toPhone, $msg,
            'staff_cash_received',
            ['staff_name'=>$staffName,'amount'=>$amtDisp,'category'=>$category,'from'=>$fromName,'ref'=>$cbRef]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRE-DUE INVOICE REMINDERS  →  ACCOUNTS
    // Called by cron_maintenance.php Task 4a (pre-due scanner)
    // Day -7: friendly heads-up
    // Day -3: firmer reminder
    // Day -1: urgent — due tomorrow
    // ══════════════════════════════════════════════════════════════════════

    public function invoiceDue7Days(string $customerPhone, string $customerName, string $invoiceNum, float $amount, string $currency, string $dueDate, string $serviceName = ''): void
    {
        $a = number_format($amount, 2);
        $svcLine = $serviceName ? "\n📶 Service: {$serviceName}" : '';
        $this->sendVia(self::ACCOUNTS, $customerPhone,
            "📋 *Upcoming Invoice — DishNet Africa*\n\nHi {$customerName},\n\nJust a heads-up: your invoice #{$invoiceNum} of *{$a} {$currency}* is due on *{$dueDate}* (7 days).{$svcLine}\n\n💳 Pay early online:\n🔗 https://dishnetafrica.com/tutorials/index.html\n\n— DishNet Accounts",
            'ops_pre_due_d7',
            ['customer_name' => $customerName, 'amount' => $a, 'currency' => $currency,
             'invoice_number' => $invoiceNum, 'due_date' => $dueDate, 'service_name' => $serviceName]
        );
    }

    public function invoiceDue3Days(string $customerPhone, string $customerName, string $invoiceNum, float $amount, string $currency, string $dueDate, string $serviceName = ''): void
    {
        $a = number_format($amount, 2);
        $svcLine = $serviceName ? "\n📶 Service: {$serviceName}" : '';
        $this->sendVia(self::ACCOUNTS, $customerPhone,
            "⏰ *Due in 3 Days — DishNet Africa*\n\nHi {$customerName},\n\nYour invoice #{$invoiceNum} is due on *{$dueDate}*.\n💰 Amount: *{$a} {$currency}*{$svcLine}\n\nPay now to keep your service running:\n🔗 https://dishnetafrica.com/tutorials/index.html\n\nNeed help? +211 921 443 002\n— DishNet Accounts",
            'ops_pre_due_d3',
            ['customer_name' => $customerName, 'amount' => $a, 'currency' => $currency,
             'invoice_number' => $invoiceNum, 'due_date' => $dueDate, 'service_name' => $serviceName]
        );
    }

    public function invoiceDueTomorrow(string $customerPhone, string $customerName, string $invoiceNum, float $amount, string $currency, string $dueDate, string $serviceName = ''): void
    {
        $a = number_format($amount, 2);
        $svcLine = $serviceName ? "\n📶 Service: {$serviceName}" : '';
        $this->sendVia(self::ACCOUNTS, $customerPhone,
            "🔴 *Due Tomorrow — DishNet Africa*\n\nDear {$customerName},\n\nYour invoice #{$invoiceNum} of *{$a} {$currency}* is due *tomorrow* ({$dueDate}).{$svcLine}\n\nPlease pay today to avoid any service interruption:\n🔗 https://dishnetafrica.com/tutorials/index.html\n\n📞 +211 921 443 002\n— DishNet Accounts",
            'ops_pre_due_d1',
            ['customer_name' => $customerName, 'amount' => $a, 'currency' => $currency,
             'invoice_number' => $invoiceNum, 'due_date' => $dueDate, 'service_name' => $serviceName]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // OVERDUE ESCALATION  →  ACCOUNTS
    // Called by cron_maintenance.php Task 4 (overdue scanner)
    // Day +3: firm warning with exact amount
    // Day +5: final notice — suspension at midnight
    // ══════════════════════════════════════════════════════════════════════

    public function overdueDay1(string $customerPhone, string $customerName, string $invoiceNum, float $amount, string $currency, string $serviceName): void
    {
        $a = number_format($amount, 2);
        $this->sendVia(self::ACCOUNTS, $customerPhone,
            "⏰ *Gentle Reminder — DishNet Africa*\n\nHi {$customerName},\n\nYour invoice #{$invoiceNum} of *{$a} {$currency}* was due yesterday.\n\nPay today to avoid any interruption to your service:\n🔗 https://dishnetafrica.com/tutorials/index.html\n\nNeed help? Reply to this message or call\n📞 +211 921 443 002\n\n— DishNet Accounts",
            'ops_overdue_d1',
            ['customer_name' => $customerName, 'amount' => $a, 'currency' => $currency,
             'invoice_number' => $invoiceNum, 'service_name' => $serviceName]
        );
    }

    public function overdueDay3(string $customerPhone, string $customerName, string $invoiceNum, float $amount, string $currency, string $serviceName): void
    {
        $a = number_format($amount, 2);
        $this->sendVia(self::ACCOUNTS, $customerPhone,
            "🔴 *Account Overdue — DishNet Africa*\n\nDear {$customerName},\n\nYour account has an outstanding balance of *{$a} {$currency}* (Invoice #{$invoiceNum}).\n\nYour service is at risk of suspension.\n\nPay now — takes less than 2 minutes:\n1️⃣ https://dishnetafrica.com/tutorials/index.html\n2️⃣ Click Pay → Pay with Card → Confirm\n\nQuestions? +211 921 443 002\n— DishNet Accounts",
            'ops_overdue_d3',
            ['customer_name' => $customerName, 'amount' => $a, 'currency' => $currency,
             'invoice_number' => $invoiceNum, 'service_name' => $serviceName]
        );
    }

    public function overdueDay5(string $customerPhone, string $customerName, string $invoiceNum, float $amount, string $currency, string $serviceName): void
    {
        $a = number_format($amount, 2);
        $this->sendVia(self::ACCOUNTS, $customerPhone,
            "🚨 *Final Notice — Service Suspending Tonight*\n\nDear {$customerName},\n\nYour DishNet service *{$serviceName}* will be suspended at midnight unless payment is received.\n\n💰 Outstanding: *{$a} {$currency}*\n📋 Invoice: #{$invoiceNum}\n\nPay before midnight:\n🔗 https://dishnetafrica.com/tutorials/index.html\n\nAlready paid? Reply with your transaction ID and we'll restore your service immediately.\n\n📞 +211 921 443 002\n— DishNet Accounts",
            'ops_overdue_d5',
            ['customer_name' => $customerName, 'amount' => $a, 'currency' => $currency,
             'invoice_number' => $invoiceNum, 'service_name' => $serviceName]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // INSTALLATION  →  SUPPORT
    // Triggered manually from public.php (admin UI) when appointment is booked
    // ══════════════════════════════════════════════════════════════════════

    public function installationConfirmed(string $customerPhone, string $customerName, string $serviceType, string $installDate, string $installTime, string $techName): void
    {
        $this->sendVia(self::SUPPORT, $customerPhone,
            "📅 *Installation Confirmed — DishNet Africa*\n\nHi {$customerName},\n\nYour *{$serviceType}* installation is booked! ✅\n\n📆 Date: *{$installDate}*\n🕐 Time window: {$installTime}\n👷 Technician: {$techName}\n\nOur technician will call you *30 minutes* before arrival.\n\nPlease ensure someone is at the location during the scheduled window.\n\nQuestions? 📞 +211 921 443 006\n— DishNet Support",
            'ops_install_confirmed',
            ['customer_name' => $customerName, 'service_type' => $serviceType,
             'install_date' => $installDate, 'install_time' => $installTime, 'tech_name' => $techName]
        );
    }

    public function technicianDispatched(string $customerPhone, string $customerName, string $techName, string $techPhone, string $eta): void
    {
        $this->sendVia(self::SUPPORT, $customerPhone,
            "🚗 *Technician On the Way — DishNet Africa*\n\nHi {$customerName},\n\nOur technician *{$techName}* is heading to your location now.\n\n⏱ ETA: {$eta}\n📞 Technician: {$techPhone}\n\nPlease be available at the installation address.\n\n— DishNet Support",
            'ops_install_dispatched',
            ['customer_name' => $customerName, 'tech_name' => $techName,
             'tech_phone' => $techPhone, 'eta' => $eta]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // OUTAGE / MAINTENANCE  →  SUPPORT
    // Triggered from public.php bulk-send UI or programmatically
    // ══════════════════════════════════════════════════════════════════════

    public function outageAlert(string $customerPhone, string $customerName, string $maintDate, string $maintStart, string $maintEnd): void
    {
        $this->sendVia(self::SUPPORT, $customerPhone,
            "🔧 *Planned Maintenance — DishNet Africa*\n\nDear {$customerName},\n\nWe will be performing maintenance in your area that may temporarily affect your service.\n\n📅 Date: *{$maintDate}*\n🕐 Window: {$maintStart} – {$maintEnd}\n\nService will be fully restored by {$maintEnd}.\nNo action required on your part.\n\nWe apologise for any inconvenience.\n\nQuestions? 📞 +211 921 443 006\n— DishNet Technical Team",
            'ops_outage_alert',
            ['customer_name' => $customerName, 'maint_date' => $maintDate,
             'maint_start' => $maintStart, 'maint_end' => $maintEnd]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // ══════════════════════════════════════════════════════════════════════
    // RENEWAL REMINDER  →  ACCOUNTS
    // Sent ~5 days before next invoice to reduce surprise billing
    // ══════════════════════════════════════════════════════════════════════

    public function renewalReminder(string $customerPhone, string $customerName, string $serviceName, float $amount, string $renewalDate): void
    {
        $a = number_format($amount, 2);
        $msg = "🔄 *Renewal Reminder — DishNet Africa*\n\n"
             . "Dear {$customerName},\n\n"
             . "Your service is coming up for renewal:\n\n"
             . "📶 Service: *{$serviceName}*\n"
             . "💰 Amount: *\${$a}*\n"
             . "📅 Renewal: {$renewalDate}\n\n"
             . "Make sure your account has sufficient balance to avoid interruption.\n\n"
             . "💳 Top up: https://dishnetafrica.com/tutorials/index.html\n\n"
             . "❓ Help: +211 921 443 002\n"
             . "— DishNet Accounts";

        $this->sendVia(self::ACCOUNTS, $customerPhone, $msg,
            'ops_renewal_reminder',
            ['customer_name'=>$customerName,'service_name'=>$serviceName,'amount'=>$a,'renewal_date'=>$renewalDate]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // INSTALLATION SCHEDULED  →  SUPPORT
    // Sent when a technician is assigned or job is scheduled
    // ══════════════════════════════════════════════════════════════════════

    public function installationScheduled(string $customerPhone, string $customerName, string $techName, string $scheduledDate, string $serviceName = '', string $jobRef = ''): void
    {
        $refLine = $jobRef ? "\n🔖 Ref: {$jobRef}" : '';
        $svcLine = $serviceName ? "\n📶 Service: {$serviceName}" : '';

        $msg = "🔧 *Installation Scheduled — DishNet Africa*\n\n"
             . "Dear {$customerName},\n\n"
             . "Great news! Your installation has been scheduled. ✅\n\n"
             . "👷 Technician: *{$techName}*\n"
             . "📅 Date: *{$scheduledDate}*"
             . $svcLine
             . $refLine . "\n\n"
             . "Your technician will contact you before arriving.\n"
             . "Please ensure someone is available at the installation site.\n\n"
             . "🛠 Support: wa.me/211921443002\n"
             . "— DishNet Support";

        $this->sendVia(self::SUPPORT, $customerPhone, $msg,
            'ops_installation_scheduled',
            ['customer_name'=>$customerName,'tech_name'=>$techName,'scheduled_date'=>$scheduledDate,'service_name'=>$serviceName,'job_ref'=>$jobRef]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // WIN-BACK FOLLOW-UP  →  ACCOUNTS
    // Sent 7 days after service ended to try recovering churned customers
    // ══════════════════════════════════════════════════════════════════════

    public function winBackFollowup(string $customerPhone, string $customerName, string $serviceName, string $endedDate): void
    {
        $msg = "👋 *We Miss You — DishNet Africa*\n\n"
             . "Dear {$customerName},\n\n"
             . "We noticed your *{$serviceName}* service ended on {$endedDate}.\n\n"
             . "We'd love to have you back! 🌐\n\n"
             . "🎁 Contact us about our reconnection offers\n"
             . "📞 Call: +211 921 443 002\n"
             . "💬 WhatsApp: wa.me/211921443002\n\n"
             . "We're always improving our network and would value your feedback on how we can serve you better.\n\n"
             . "— DishNet Team";

        $this->sendVia(self::ACCOUNTS, $customerPhone, $msg,
            'ops_win_back',
            ['customer_name'=>$customerName,'service_name'=>$serviceName,'ended_date'=>$endedDate]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // CHURN RECOVERY  →  ACCOUNTS
    // Called by cron_maintenance.php when service.end detected (or directly)
    // ══════════════════════════════════════════════════════════════════════

    public function serviceEnded(string $customerPhone, string $customerName, string $serviceName, string $serviceType = '', float $balance = 0): void
    {
        $typeLine = $serviceType ? "\n📶 Service: {$serviceType}" : '';
        $balanceLine = $balance > 0 ? "\n💰 Account credit: \$" . number_format($balance, 2) : '';
        
        $msg = "👋 *Service Ended — DishNet Africa*\n\n"
             . "Hi {$customerName},\n\n"
             . "Your DishNet service has been disconnected."
             . $typeLine
             . $balanceLine . "\n\n"
             . "🔄 *Want to reconnect?*\n"
             . "We have special offers for returning customers!\n\n"
             . "📞 +211 921 443 006\n"
             . "💬 wa.me/211921443006\n\n"
             . "We'd love to have you back.\n"
             . "— DishNet Team";
        
        $this->sendVia(self::ACCOUNTS, $customerPhone, $msg,
            'event_service_end',
            ['customer_name' => $customerName, 'service_name' => $serviceName, 'service_type' => $serviceType]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // AGENT MANAGEMENT  →  SUPPORT (admin-triggered)
    // ══════════════════════════════════════════════════════════════════════

    public function commissionSummary(array $agent, string $month, int $newCustomers, float $commission, float $bonus, string $payDate): void
    {
        $c     = number_format($commission, 2);
        $b     = number_format($bonus, 2);
        $total = number_format($commission + $bonus, 2);
        $this->sendVia(self::SUPPORT, $agent['phone'] ?? '',
            "💰 *Monthly Commission — DishNet Africa*\n\nHi {$agent['name']},\n\nHere is your commission for *{$month}*:\n\n👥 New customers:  {$newCustomers}\n💵 Commission:     \${$c}\n🎁 Bonus:          \${$b}\n━━━━━━━━━━━━━━━━━━\n💰 Total:          *\${$total}*\n\nPayment by: {$payDate}\n\nKeep up the great work! 🙌\n— DishNet Operations",
            'ops_commission_summary',
            ['agent_name' => $agent['name'], 'month' => $month, 'new_customers' => (string)$newCustomers,
             'commission_amount' => $c, 'bonus' => $b, 'total_payout' => $total, 'pay_date' => $payDate]
        );
    }

    public function agentLeadNudge(array $agent, int $pendingLeads, string $deadline, array $topLeads = []): void
    {
        // Build a preview of top leads if provided
        $leadPreview = '';
        if (!empty($topLeads)) {
            $previewLines = [];
            foreach (array_slice($topLeads, 0, 3) as $l) {
                $name = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
                $pri = $l['priority'] ?? 'medium';
                $icon = $pri === 'high' ? '🔴' : ($pri === 'low' ? '🟢' : '🟡');
                $previewLines[] = "{$icon} {$name}";
            }
            $leadPreview = "\n\n📋 *Top priority:*\n" . implode("\n", $previewLines);
        }
        
        $msg = "⏰ *Lead Follow-up Reminder*\n\n"
             . "Hi {$agent['name']},\n\n"
             . "You have *{$pendingLeads}* lead(s) waiting for follow-up."
             . $leadPreview . "\n\n"
             . "⏳ Deadline: *{$deadline}*\n\n"
             . "💡 Tip: Leads contacted within 24hrs convert 3x better!\n\n"
             . "👉 Open Plugin → Leads tab\n"
             . "— DishNet Operations";
        
        $this->sendVia(self::SUPPORT, $agent['phone'] ?? '', $msg,
            'ops_agent_lead_nudge',
            ['agent_name' => $agent['name'], 'pending_leads' => (string)$pendingLeads, 'deadline' => $deadline]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // SIM / LEADS / FIBER  →  SUPPORT
    // ══════════════════════════════════════════════════════════════════════

    public function simActivated(array $retailer, string $msisdn, string $customerName, float $fee, string $packageName = '', int $validityDays = 0, string $customerPhone = ''): void
    {
        $f = number_format($fee, 2);
        $packageLine = $packageName ? "\n📶 Package: *{$packageName}*" : '';
        $validityLine = $validityDays > 0 ? "\n📅 Valid: {$validityDays} days" : '';
        $custPhoneLine = $customerPhone ? "\n📱 Customer: {$customerPhone}" : '';
        
        $msg = "📡 *SIM Activated*\n\n"
             . "👤 {$customerName}"
             . $custPhoneLine
             . $packageLine
             . $validityLine . "\n\n"
             . "💵 Fee: \${$f}\n"
             . "🔖 MSISDN: {$msisdn}\n\n"
             . "✅ Customer can now connect!";
        
        $this->sendVia(self::SUPPORT, $retailer['phone'] ?? '', $msg,
            'ops_sim_activated',
            ['agent_name'=>$retailer['name'],'customer_name'=>$customerName,'msisdn'=>$msisdn,'fee'=>$f,'package'=>$packageName]
        );
    }

    public function leadAssigned(array $agent, array $lead): void
    {
        $leadName = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
        $phone    = $lead['phone']  ?? '';
        $source   = $lead['source'] ?? '';
        $priority = $lead['priority'] ?? 'medium';
        $service  = $lead['service_type'] ?? $lead['interest'] ?? '';
        $area     = $lead['area'] ?? $lead['location'] ?? '';
        $leadId   = $lead['id'] ?? '';
        
        $priIcon = $priority === 'high' ? '🔴' : ($priority === 'low' ? '🟢' : '🟡');
        $priLabel = ucfirst($priority);
        $phoneLine = $phone ? "\n📱 {$phone}" : '';
        $areaLine = $area ? "\n📍 {$area}" : '';
        $serviceLine = $service ? "\n📶 Interest: {$service}" : '';
        $sourceLine = $source ? "\n🌐 Source: {$source}" : '';
        $refLine = $leadId ? "\n\n📋 Lead #{$leadId}" : '';
        
        // Calculate deadline based on priority
        $deadline = $priority === 'high' ? 'Today' : ($priority === 'low' ? 'This week' : 'Tomorrow');
        
        $msg = "🎯 *New Lead Assigned*\n\n"
             . "Hi {$agent['name']},\n\n"
             . "👤 {$leadName}"
             . $phoneLine
             . $areaLine . "\n\n"
             . "{$priIcon} Priority: *{$priLabel}*"
             . $serviceLine
             . $sourceLine
             . $refLine . "\n\n"
             . "⏰ Follow up by: *{$deadline}*\n\n"
             . "👉 Open Plugin → Leads tab";
        
        $this->sendVia(self::SUPPORT, $agent['phone'] ?? '', $msg,
            'ops_lead_assigned',
            ['agent_name'=>$agent['name'],'lead_name'=>$leadName,'lead_phone'=>$phone,'source'=>$source,'priority'=>$priority,'lead_id'=>(string)$leadId]
        );
    }

    /**
     * Single batch summary instead of N individual notifications.
     * Called when admin assigns multiple leads at once (smart distribute or bulk assign).
     * One WhatsApp per agent no matter how many leads assigned.
     */
    public function leadBatchAssigned(array $agent, array $leads, string $distributionNote = ''): void
    {
        $count    = count($leads);
        if ($count === 0) return;

        // Build a compact lead list (max 5 shown, then "…and X more")
        $lines = [];
        foreach (array_slice($leads, 0, 5) as $i => $l) {
            $name   = trim(($l['customer_name'] ?? ($l['firstname'] ?? '') . ' ' . ($l['lastname'] ?? '')));
            $svc    = $l['service_type'] ?? '';
            $pri    = $l['priority']     ?? 'medium';
            $priIcon = $pri === 'high' ? '🔴' : ($pri === 'low' ? '🟢' : '🟡');
            $lines[] = "{$priIcon} {$name}" . ($svc ? " ({$svc})" : '');
        }
        if ($count > 5) $lines[] = "…and " . ($count - 5) . " more";

        $msg = "📋 *{$count} Leads Assigned to You*\n"
             . "Hi {$agent['name']},\n\n"
             . implode("\n", $lines) . "\n\n"
             . ($distributionNote ? "📝 Note: {$distributionNote}\n\n" : '')
             . "Open the Operations Hub → Leads tab to start following up.\n"
             . "🎯 Tip: Contact each lead within 24 hours for best results.";

        $this->sendVia(self::SUPPORT, $agent['phone'] ?? '', $msg, 'ops_lead_batch_assigned', [
            'agent_name' => $agent['name'],
            'lead_count' => (string)$count,
            'note'       => $distributionNote,
        ]);
    }

    public function fiberBatchDispatched(array $leader, string $batchName, int $created, int $failed, string $partner, string $area = '', string $dateRange = ''): void
    {
        $total = $created + $failed;
        $successRate = $total > 0 ? round(($created / $total) * 100) : 0;
        $areaLine = $area ? "\n📍 Area: {$area}" : '';
        $dateLine = $dateRange ? "\n📅 Period: {$dateRange}" : '';
        $statusIcon = $failed > 0 ? '⚠️' : '✅';
        $statusMsg = $failed > 0 
            ? "\n\n⚠️ {$failed} job(s) failed — check Batches tab"
            : "\n\n✅ All jobs created successfully!";
        
        $msg = "🔧 *Fiber Batch Dispatched*\n\n"
             . "📦 Batch: *{$batchName}*\n"
             . "🤝 Partner: {$partner}"
             . $areaLine
             . $dateLine . "\n\n"
             . "📊 Jobs: {$created}/{$total} created ({$successRate}%)"
             . $statusMsg;
        
        $this->sendVia(self::SUPPORT, $leader['phone'] ?? '', $msg,
            'ops_fiber_batch',
            ['batch_name'=>$batchName,'partner'=>$partner,'created'=>(string)$created,'total'=>(string)$total,'failed'=>(string)$failed,'area'=>$area]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // FTTH INSTALLATION NOTIFICATIONS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Notify engineer their installation has been approved and commissioned.
     *
     * @param array  $engineer  Retailer record of the assigned engineer
     * @param array  $ticket    splynx_tickets.json record
     * @param string $approvedBy  Name of support leader who approved
     * @param string $notes     Commission notes (optional)
     * @param float  $commission Commission amount (optional)
     * @param int    $monthlyJobs Total jobs this month (optional)
     * @param float  $monthlyTotal Total commission this month (optional)
     */
    public function installApproved(array $engineer, array $ticket, string $approvedBy, string $notes = '', float $commission = 0, int $monthlyJobs = 0, float $monthlyTotal = 0): void
    {
        $customerName = $ticket['customer_name'] ?? 'Customer';
        $ticketId     = $ticket['id'] ?? '?';
        $address      = $ticket['address'] ?? '';
        $serviceType  = $ticket['service_type'] ?? $ticket['type'] ?? '';
        
        $addressLine = $address ? "\n📍 {$address}" : '';
        $serviceLine = $serviceType ? "\n📶 {$serviceType}" : '';
        $commLine = $commission > 0 ? "\n💰 *Commission: \$" . number_format($commission, 2) . "*" : '';
        $monthlyLine = ($monthlyJobs > 0 && $monthlyTotal > 0) 
            ? "\n📊 March total: \$" . number_format($monthlyTotal, 2) . " ({$monthlyJobs} jobs)" 
            : '';
        $notesLine = $notes ? "\n📝 {$notes}" : '';
        
        $msg = "✅ *Installation Approved!*\n\n"
             . "👤 {$customerName}"
             . $addressLine
             . $serviceLine . "\n\n"
             . "🎫 Ticket #{$ticketId}\n"
             . "👔 Approved: {$approvedBy}"
             . $commLine
             . $monthlyLine
             . $notesLine . "\n\n"
             . "Great work! 🎉";

        $this->sendVia(
            self::SUPPORT,
            $engineer['phone'] ?? '',
            $msg,
            'install_approved',
            ['ticket_id' => (string)$ticketId, 'customer' => $customerName, 'approved_by' => $approvedBy, 'commission' => number_format($commission, 2)]
        );
    }

    /**
     * Notify engineer their installation has been rejected and must be redone.
     *
     * @param array  $engineer  Retailer record of the assigned engineer
     * @param array  $ticket    splynx_tickets.json record
     * @param string $rejectedBy  Name of support leader who rejected
     * @param string $reason    Reason for rejection
     * @param string $deadline  Deadline to fix (optional)
     */
    public function installRejected(array $engineer, array $ticket, string $rejectedBy, string $reason = '', string $deadline = ''): void
    {
        $customerName = $ticket['customer_name'] ?? 'Customer';
        $ticketId     = $ticket['id'] ?? '?';
        $address      = $ticket['address'] ?? '';
        
        $addressLine = $address ? " | 📍 {$address}" : '';
        $deadlineLine = $deadline ? "\n\n⏰ Fix by: *{$deadline}*" : '';
        $reviewerPhone = '';  // Could be added if available
        $contactLine = $reviewerPhone ? "\n\n📱 Questions? Call {$rejectedBy}" : '';
        
        // Parse reason into actionable items if possible
        $actionItems = "• Review the rejection reason\n• Fix the issues mentioned\n• Resubmit photos/documentation";
        
        $msg = "⚠️ *Installation Needs Fixes*\n\n"
             . "👤 {$customerName}{$addressLine}\n"
             . "🎫 Ticket #{$ticketId}\n\n"
             . "❌ *Issue:* {$reason}\n\n"
             . "📋 *Required:*\n"
             . $actionItems
             . $deadlineLine . "\n\n"
             . "👔 Reviewer: {$rejectedBy}"
             . $contactLine;

        $this->sendVia(
            self::SUPPORT,
            $engineer['phone'] ?? '',
            $msg,
            'install_rejected',
            ['ticket_id' => (string)$ticketId, 'customer' => $customerName, 'reason' => $reason, 'rejected_by' => $rejectedBy]
        );
    }

    /**
     * Send daily morning summary to Bidal (support_leader).
     * Called by cron/bidal_summary.php at 07:00 EAT.
     *
     * @param array $leader    Retailer record of support leader
     * @param array $summary   Keys: pending, in_progress, testing, completed_today, engineers_active
     */
    public function bidalMorningSummary(array $leader, array $summary): void
    {
        $pending       = (int)($summary['pending']          ?? 0);
        $inProgress    = (int)($summary['in_progress']      ?? 0);
        $testing       = (int)($summary['testing']          ?? 0);
        $completedToday = (int)($summary['completed_today'] ?? 0);
        $completedWeek  = (int)($summary['completed_week']  ?? 0);
        $unassigned    = (int)($summary['unassigned']        ?? 0);
        $date          = date('D d M Y');

        $msg  = "📡 *DishNet FTTH Morning Brief*\n";
        $msg .= "_{$date}_\n\n";
        $msg .= "⏳ Pending:       {$pending} jobs\n";
        if ($unassigned > 0)
            $msg .= "⚠️ Unassigned:    {$unassigned} need an engineer\n";
        $msg .= "🔧 In Progress:   {$inProgress} jobs\n";
        $msg .= "🔬 In Testing:    {$testing} awaiting approval\n";
        $msg .= "✅ Done today:    {$completedToday} installs\n";
        $msg .= "📊 Done this wk:  {$completedWeek} installs\n";

        if ($testing > 0) {
            $msg .= "\n👉 {$testing} job(s) waiting for your approval in the Command Center.";
        } elseif ($pending === 0 && $inProgress === 0) {
            $msg .= "\n🎉 All clear — no open jobs!";
        }

        $this->sendVia(
            self::SUPPORT,
            $leader['phone'] ?? '',
            $msg,
            'bidal_morning_summary',
            ['pending' => (string)$pending, 'testing' => (string)$testing, 'done_today' => (string)$completedToday]
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // FIBER COST ALERTS  →  ACCOUNTS
    // ══════════════════════════════════════════════════════════════════════

    public function fiberInvoiceRecorded(string $supplier, string $invoiceNum, float $amount, string $period, string $recordedBy): void
    {
        $a = number_format($amount, 2);
        $this->sendAdmin(
            "🧾 *Fiber Invoice Recorded*\n\n"
            . "📦 Supplier: {$supplier}\n"
            . "🔖 Invoice: #{$invoiceNum}\n"
            . "💰 Amount: *\${$a}*\n"
            . "📅 Period: {$period}\n"
            . "👤 By: {$recordedBy}\n\n"
            . "👉 Review in Plugin → Fiber Costs",
            'fiber_invoice_recorded'
        );
    }

    public function fiberVarianceAlert(string $supplier, string $invoiceNum, float $expected, float $actual, float $variance, float $variancePct): void
    {
        $e = number_format($expected, 2);
        $a = number_format($actual, 2);
        $v = ($variance >= 0 ? '+' : '') . number_format($variance, 2);
        $vp = number_format($variancePct, 1);
        $icon = $variance > 0 ? '🔴' : '🟡';
        $this->sendAdmin(
            "⚠️ *Fiber Cost Variance Alert*\n\n"
            . "📦 {$supplier} — #{$invoiceNum}\n\n"
            . "📊 Expected: \${$e}\n"
            . "{$icon} Invoiced: \${$a}\n"
            . "📈 Variance: *{$v}* ({$vp}%)\n\n"
            . "Please investigate in Plugin → Fiber Costs → Reconcile",
            'fiber_variance_alert'
        );
    }

    public function fiberPriceChangeAlert(array $changes): void
    {
        if (empty($changes)) return;
        $lines = [];
        foreach (array_slice($changes, 0, 5) as $c) {
            $dir = $c['change'] > 0 ? '📈' : '📉';
            $lines[] = "{$dir} {$c['plan']}: \${$c['old_cost']} → \${$c['new_cost']} ({$c['change_pct']}%)";
        }
        if (count($changes) > 5) $lines[] = "…and " . (count($changes) - 5) . " more";
        $this->sendAdmin(
            "📈 *Supplier Price Change Detected*\n\n"
            . implode("\n", $lines) . "\n\n"
            . "Review in Plugin → Fiber Costs → Plan Costs",
            'fiber_price_change'
        );
    }

    public function fiberMissingInvoice(string $period): void
    {
        $this->sendAdmin(
            "⏰ *Missing Fiber Invoice*\n\n"
            . "No supplier invoice has been recorded for *{$period}*.\n\n"
            . "Please check with your fiber supplier(s) and record the invoice in Plugin → Fiber Costs.",
            'fiber_missing_invoice'
        );
    }

    public function fiberInvoicePosted(string $supplier, string $invoiceNum, float $amount, string $period): void
    {
        $a = number_format($amount, 2);
        $this->sendAdmin(
            "✅ *Fiber Invoice Posted to Cashbook*\n\n"
            . "📦 {$supplier} — #{$invoiceNum}\n"
            . "💰 \${$a} | 📅 {$period}\n\n"
            . "Entry created in the main cashbook.",
            'fiber_invoice_posted'
        );
    }

    public function send(string $event, string $toPhone, string $toName, array $data, string $sender = self::SUPPORT): void
    {
        $lines = ['*' . str_replace('_', ' ', strtoupper($event)) . '*', "To: {$toName}"];
        foreach ($data as $k => $v) {
            if (!is_array($v)) $lines[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . $v;
        }
        $this->sendVia($sender, $toPhone, implode("\n", $lines), $event, $data);
    }

    public function sendAdmin(string $message, string $event = '', array $vars = []): void
    {
        $this->sendAdminMsg($message, $event, $vars);
    }

    private function sendAdminMsg(string $message, string $event = '', array $vars = []): void
    {
        if (empty($this->adminPhone)) return;
        $this->sendVia(self::SUPPORT, $this->adminPhone, $message, $event, $vars);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CORE — POST to WASender WhatsApp API
    //
    // Matches the exact format used in the production notification system:
    //   URL  : {wa_plugin_url}/api/whatsapp-web/send-message
    //   Body : multipart/form-data  (NOT JSON)
    //   Fields: app_key, auth_key, to, message, sandbox
    //
    // wa_plugin_url in Settings should be the base URL of the WhatsApp server,
    // e.g. http://wa.dishnetafrica.com
    // ══════════════════════════════════════════════════════════════════════

    /**
     * sendDocument — send a PDF/document via WhatsApp (WhatsML API).
     * 
     * Uses the type=document + url pattern. The URL must be publicly accessible.
     * 
     * @param string $sender   self::SUPPORT or self::ACCOUNTS
     * @param string $toPhone  Recipient phone
     * @param string $publicUrl Public URL to the document file
     * @param string $filename Display filename (e.g. "INV012775.pdf")
     * @param string $caption  Caption text shown below the document
     * @param string $event    Event name for logging
     */
    public function sendDocument(string $sender, string $toPhone, string $publicUrl, string $filename, string $caption = '', string $event = ''): void
    {
        if (!$this->enabled || empty($toPhone) || empty($publicUrl)) return;

        // v4.9.20: Global PDF kill-switch — skip document sends when disabled
        if (!$this->pdfEnabled) {
            $this->writeLog([
                'sender' => $sender, 'event' => $event ?: 'document_skipped',
                'to' => $toPhone, 'filename' => $filename,
                'status' => 'skipped', 'reason' => 'wa_send_pdf=false',
            ]);
            return;
        }

        $to = preg_replace('/[^0-9]/', '', $toPhone);
        if (empty($to)) return;

        if ($this->dryRunMode) {
            $this->logDryRunNotification($to, "[DOC] {$filename}: {$caption}", $event, ['url' => $publicUrl]);
            return;
        }

        // Rate limit — sleep if sending too fast
        $this->rateLimitWait();

        $useAppKey = ($sender === self::ACCOUNTS) ? $this->accountsAppKey : $this->appKey;

        $formData = [
            'app_key'  => $useAppKey,
            'auth_key' => $this->authKey,
            'to'       => $to,
            'type'     => 'document',
            'url'      => $publicUrl,
            'filename' => $filename,
            'fileName' => $filename,
            'message'  => $caption,
        ];

        $endpoint = rtrim($this->pluginUrl, '/') . '/api/whatsapp-web/send-message';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $formData,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR      => 7, // CURL_REDIR_POST_ALL — keep POST across 301/302/303 redirects (e.g. http->https)
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr   = curl_error($ch);
        curl_close($ch);

        $respData = json_decode((string)$response, true); // v4.21.72: coerce — curl_exec returns false on failure
        $success  = !$curlErr && $httpCode >= 200 && $httpCode < 300
                    && isset($respData['success']) && $respData['success'] === true;

        // Track result for retry mode
        $this->_lastSendSuccess = $success;
        $this->_lastHttpCode    = $httpCode;
        $this->_lastError       = $curlErr ?: ($success ? null : mb_substr((string)$response, 0, 500));

        $this->writeLog([
            'sender'   => $sender,
            'event'    => $event ?: 'document_send',
            'to'       => $to,
            'preview'  => "[PDF] {$filename}" . ($caption ? ': ' . mb_substr($caption, 0, 40) : ''),
            'success'  => $success,
            'http_code'=> $httpCode,
            'error'    => $curlErr ?: ($success ? null : mb_substr($response, 0, 200)),
            'sent_at'  => date('Y-m-d H:i:s'),
        ]);

        // Queue failed PDF sends for retry (will retry as text with caption)
        if (!$success && !$this->_retryMode) {
            $retryMsg = $caption ?: "[Document: {$filename}]";
            $this->queueFailed($sender, $to, $retryMsg, $event ?: 'document_send',
                ['_type' => 'document', 'url' => $publicUrl, 'filename' => $filename],
                $httpCode, $curlErr ?: mb_substr((string)$response, 0, 500));
        }
    }

    /**
     * sendImage — send an image via WhatsApp (WhatsML API).
     *
     * Uses the type=image + url pattern. The URL must be publicly accessible.
     *
     * @param string $sender   self::SUPPORT or self::ACCOUNTS
     * @param string $toPhone  Recipient phone
     * @param string $publicUrl Public URL to the image file
     * @param string $caption  Caption text shown below the image
     * @param string $event    Event name for logging
     */
    public function sendImage(string $sender, string $toPhone, string $publicUrl, string $caption = '', string $event = ''): void
    {
        if (!$this->enabled || empty($toPhone) || empty($publicUrl)) return;

        $to = preg_replace('/[^0-9]/', '', $toPhone);
        if (empty($to)) return;

        if ($this->dryRunMode) {
            $this->logDryRunNotification($to, "[IMAGE] {$publicUrl}: {$caption}", $event, ['url' => $publicUrl]);
            return;
        }

        $this->rateLimitWait();

        $useAppKey = ($sender === self::ACCOUNTS) ? $this->accountsAppKey : $this->appKey;

        $formData = [
            'app_key'  => $useAppKey,
            'auth_key' => $this->authKey,
            'to'       => $to,
            'type'     => 'image',
            'url'      => $publicUrl,
            'message'  => $caption,
        ];

        $endpoint = rtrim($this->pluginUrl, '/') . '/api/whatsapp-web/send-message';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $formData,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR      => 7, // CURL_REDIR_POST_ALL — keep POST across 301/302/303 redirects (e.g. http->https)
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr   = curl_error($ch);
        curl_close($ch);

        $respData = json_decode((string)$response, true); // v4.21.72: coerce — curl_exec returns false on failure
        $success  = !$curlErr && $httpCode >= 200 && $httpCode < 300
                    && isset($respData['success']) && $respData['success'] === true;

        $this->_lastSendSuccess = $success;
        $this->_lastHttpCode    = $httpCode;
        $this->_lastError       = $curlErr ?: ($success ? null : mb_substr((string)$response, 0, 500));

        $this->writeLog([
            'sender'   => $sender,
            'event'    => $event ?: 'image_send',
            'to'       => $to,
            'preview'  => '[IMAGE]' . ($caption ? ': ' . mb_substr($caption, 0, 40) : ''),
            'success'  => $success,
            'http_code'=> $httpCode,
            'error'    => $curlErr ?: ($success ? null : mb_substr($response, 0, 200)),
            'sent_at'  => date('Y-m-d H:i:s'),
        ]);

        if (!$success && !$this->_retryMode) {
            $retryMsg = $caption ?: '[Image]';
            $this->queueFailed($sender, $to, $retryMsg, $event ?: 'image_send',
                ['_type' => 'image', 'url' => $publicUrl],
                $httpCode, $curlErr ?: mb_substr((string)$response, 0, 500));
        }
    }

    /**
     * sendRaw — send an arbitrary pre-built message to any phone number.
     * Used by cron scripts that build their own message body.
     */
    public function sendRaw(string $toPhone, string $message, string $event = ''): void
    {
        $this->sendVia(self::SUPPORT, $toPhone, $message, $event, []);
    }

    /**
     * sendWhatsApp — simple alias used by api/index.php and cron scripts.
     * Sends via the Support sender channel.
     */
    public function sendWhatsApp(string $toPhone, string $message, string $event = ''): void
    {
        $this->sendVia(self::SUPPORT, $toPhone, $message, $event, []);
    }

    /**
     * Sliding-window rate limiter — protects WASender and WhatsApp number.
     * Sleeps if the send rate exceeds RATE_MAX_PER_WINDOW in RATE_WINDOW_SEC.
     * Called automatically by sendVia() and sendDocument() before each curl call.
     */
    private function rateLimitWait(): void
    {
        $now = microtime(true);
        $windowStart = $now - self::RATE_WINDOW_SEC;

        // Prune timestamps outside the window
        $this->sendTimestamps = array_values(array_filter(
            $this->sendTimestamps,
            function (float $ts) use ($windowStart): bool { return $ts >= $windowStart; }
        ));

        // If at capacity, sleep until the oldest entry slides out of the window
        if (count($this->sendTimestamps) >= self::RATE_MAX_PER_WINDOW) {
            $sleepUntil = $this->sendTimestamps[0] + self::RATE_WINDOW_SEC;
            $sleepSec   = $sleepUntil - $now;
            if ($sleepSec > 0) {
                usleep((int)($sleepSec * 1_000_000));
            }
            // Re-prune after sleep
            $nowAfter = microtime(true);
            $this->sendTimestamps = array_values(array_filter(
                $this->sendTimestamps,
                function (float $ts) use ($nowAfter): bool { return $ts >= ($nowAfter - self::RATE_WINDOW_SEC); }
            ));
        }

        // Record this send
        $this->sendTimestamps[] = microtime(true);
    }

    public function sendVia(string $sender, string $toPhone, string $message, string $event = '', array $vars = []): void
    {
        if (!$this->enabled || empty($toPhone)) return;

        $to = preg_replace('/[^0-9]/', '', $toPhone);
        if (empty($to)) return;
        
        // DRY RUN GUARD - log but don't send
        if ($this->dryRunMode) {
            $this->logDryRunNotification($to, $message, $event, $vars);
            return;
        }

        // Rate limit — sleep if sending too fast
        $this->rateLimitWait();

        // Build form-data payload — same fields as the production WA system
        // Pick the correct WASender app_key based on sender channel
        // auth_key is user-level (same for all apps in WASender)
        $useAppKey = ($sender === self::ACCOUNTS) ? $this->accountsAppKey : $this->appKey;

        $formData = [
            'app_key'  => $useAppKey,
            'auth_key' => $this->authKey,
            'to'       => $to,
            'message'  => $message,
            'sandbox'  => 'false',
        ];
        // sender channel ('support' / 'accounts') passed as optional extra field
        // WASender ignores unknown fields — safe to include for logging purposes
        if ($sender)  $formData['sender']  = $sender;
        if ($event)   $formData['event']   = $event;

        // Endpoint: base URL + /api/whatsapp-web/send-message
        // e.g. http://wa.dishnetafrica.com/api/whatsapp-web/send-message
        $endpoint = rtrim($this->pluginUrl, '/') . '/api/whatsapp-web/send-message';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $formData,   // multipart/form-data (array, not JSON string)
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR      => 7, // CURL_REDIR_POST_ALL — keep POST across 301/302/303 redirects (e.g. http->https)
            CURLOPT_MAXREDIRS      => 3,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr   = curl_error($ch);
        curl_close($ch);

        $respData = json_decode((string)$response, true); // v4.21.72: coerce — curl_exec returns false on failure
        $success = !$curlErr && $httpCode >= 200 && $httpCode < 300
                   && isset($respData['success']) && $respData['success'] === true;

        // Track result for retry mode
        $this->_lastSendSuccess = $success;
        $this->_lastHttpCode    = $httpCode;
        $this->_lastError       = $curlErr ?: ($success ? null : mb_substr((string)$response, 0, 500));

        $this->writeLog([
            'sender'  => $sender,
            'event'   => $event ?: null,
            'to'      => $to,
            'preview' => mb_substr($message, 0, 70),
            'success' => $success,
            'http_code' => $httpCode,
            'error'   => $curlErr ?: ($success ? null : mb_substr((string)$response, 0, 200)),
            'sent_at' => date('Y-m-d H:i:s'),
        ]);

        // ── Queue failed sends for manual retry (skip if already retrying) ──
        if (!$success && !$this->_retryMode) {
            $this->queueFailed($sender, $to, $message, $event, $vars, $httpCode, $curlErr ?: mb_substr((string)$response, 0, 500));
        }

        // ── Log to conversation store (SQLite) ──────────────────────────
        if ($success) {
            try {
                $convSvcPath = __DIR__ . '/ConversationService.php';
                if (file_exists($convSvcPath)) {
                    require_once $convSvcPath;
                    // Use store's persistent data directory
                    $dataDir = method_exists($this->store, 'getDataDir') ? $this->store->getDataDir() : dirname(__DIR__) . '/data';
                    $convSvc = new ConversationService($dataDir, $this->store->getPdo());
                    $channel = ($sender === self::ACCOUNTS) ? 'accounts' : 'support';
                    $conv    = $convSvc->ensureConversation($to, $channel);
                    $convSvc->storeMessage($conv['id'], [
                        'direction'  => 'out',
                        'role'       => 'agent',
                        'body'       => $message,
                        'event_key'  => $event ?: null,
                        'agent_name' => 'DishNet Plugin',
                        'sent_at'    => date('Y-m-d H:i:s'),
                    ]);
                }
            } catch (\Throwable $e) {
                // Never break message sending for conversation logging
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // FAILED NOTIFICATION QUEUE — stores full message for manual retry
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Queue a failed notification for later manual retry.
     */
    private function queueFailed(string $sender, string $phone, string $message, string $event, array $vars, int $httpCode, string $error): void
    {
        try {
            $pdo = $this->store->getPdo();
            // Auto-create table if migration hasn't run yet
            $pdo->exec("CREATE TABLE IF NOT EXISTS notification_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender TEXT NOT NULL DEFAULT 'support',
                phone TEXT NOT NULL,
                message TEXT NOT NULL,
                event TEXT DEFAULT NULL,
                vars TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'failed',
                http_code INTEGER DEFAULT NULL,
                error TEXT DEFAULT NULL,
                attempts INTEGER NOT NULL DEFAULT 1,
                last_attempt_at TEXT NOT NULL,
                retry_at TEXT DEFAULT NULL,
                retry_by TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )");
            $stmt = $pdo->prepare(
                "INSERT INTO notification_queue (sender, phone, message, event, vars, status, http_code, error, attempts, last_attempt_at)
                 VALUES (?, ?, ?, ?, ?, 'failed', ?, ?, 1, ?)"
            );
            $stmt->execute([
                $sender,
                $phone,
                $message,
                $event ?: null,
                !empty($vars) ? json_encode($vars) : null,
                $httpCode ?: null,
                $error ?: null,
                date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Never break the main flow
        }
    }

    /**
     * Get queued (failed/retrying) notifications for the admin UI.
     *
     * @param string $status  'failed' | 'sent' | 'dismissed' | 'all'
     * @param int    $limit   Max rows
     * @param int    $offset  Pagination offset
     * @return array ['items' => [...], 'total' => int]
     */
    public function getQueue(string $status = 'failed', int $limit = 50, int $offset = 0): array
    {
        try {
            $pdo = $this->store->getPdo();
            // Ensure table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS notification_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender TEXT NOT NULL DEFAULT 'support',
                phone TEXT NOT NULL,
                message TEXT NOT NULL,
                event TEXT DEFAULT NULL,
                vars TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'failed',
                http_code INTEGER DEFAULT NULL,
                error TEXT DEFAULT NULL,
                attempts INTEGER NOT NULL DEFAULT 1,
                last_attempt_at TEXT NOT NULL,
                retry_at TEXT DEFAULT NULL,
                retry_by TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )");

            $where = ($status !== 'all') ? "WHERE status = ?" : "";
            $params = ($status !== 'all') ? [$status] : [];

            // Total count
            $countSql = "SELECT COUNT(*) FROM notification_queue {$where}";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            // Rows
            $sql = "SELECT * FROM notification_queue {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $rowParams = array_merge($params, [$limit, $offset]);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($rowParams);
            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return ['items' => $items, 'total' => $total];
        } catch (\Throwable $e) {
            return ['items' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get summary counts for the queue dashboard.
     * @return array ['failed' => int, 'sent' => int, 'dismissed' => int]
     */
    public function getQueueStats(): array
    {
        try {
            $pdo = $this->store->getPdo();
            $stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM notification_queue GROUP BY status");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $stats = ['failed' => 0, 'sent' => 0, 'dismissed' => 0];
            foreach ($rows as $r) {
                $stats[$r['status']] = (int) $r['cnt'];
            }
            return $stats;
        } catch (\Throwable $e) {
            return ['failed' => 0, 'sent' => 0, 'dismissed' => 0];
        }
    }

    /**
     * Retry a single queued notification by ID.
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function retryOne(int $queueId, string $retryBy = 'Admin'): array
    {
        try {
            $pdo = $this->store->getPdo();
            $stmt = $pdo->prepare("SELECT * FROM notification_queue WHERE id = ? AND status = 'failed'");
            $stmt->execute([$queueId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return ['success' => false, 'error' => 'Not found or already processed'];
            }

            // Mark as retrying
            $pdo->prepare("UPDATE notification_queue SET status = 'retrying', attempts = attempts + 1, last_attempt_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), $queueId]);

            // Re-send: use sendDocument for PDF retries, sendVia for text
            $this->_retryMode = true;
            $vars = json_decode($row['vars'] ?? '{}', true) ?: [];
            $isDoc = ($vars['_type'] ?? '') === 'document' && !empty($vars['url']);
            if ($isDoc) {
                $this->sendDocument($row['sender'], $row['phone'], $vars['url'], $vars['filename'] ?? 'document.pdf', $row['message'], $row['event'] ?? '');
            } else {
                $this->sendVia($row['sender'], $row['phone'], $row['message'], $row['event'] ?? '', $vars);
            }
            $sent = $this->_lastSendSuccess ?? false;
            $this->_retryMode = false;

            if ($sent) {
                $pdo->prepare("UPDATE notification_queue SET status = 'sent', retry_at = ?, retry_by = ? WHERE id = ?")
                    ->execute([date('Y-m-d H:i:s'), $retryBy, $queueId]);
                return ['success' => true, 'error' => null];
            } else {
                $pdo->prepare("UPDATE notification_queue SET status = 'failed', http_code = ?, error = ? WHERE id = ?")
                    ->execute([$this->_lastHttpCode ?? 0, $this->_lastError ?? 'Unknown', $queueId]);
                return ['success' => false, 'error' => $this->_lastError ?? 'Send failed'];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Retry all failed notifications in bulk.
     * @return array ['total' => int, 'sent' => int, 'failed' => int]
     */
    public function retryBulk(string $retryBy = 'Admin', int $maxBatch = 50): array
    {
        $result = ['total' => 0, 'sent' => 0, 'failed' => 0];
        try {
            $pdo = $this->store->getPdo();
            $stmt = $pdo->prepare("SELECT * FROM notification_queue WHERE status = 'failed' ORDER BY created_at ASC LIMIT ?");
            $stmt->execute([$maxBatch]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $result['total'] = count($rows);

            foreach ($rows as $row) {
                $r = $this->retryOne((int)$row['id'], $retryBy);
                if ($r['success']) {
                    $result['sent']++;
                } else {
                    $result['failed']++;
                }
                // Small delay to avoid hammering WASender
                usleep(300000); // 300ms
            }
        } catch (\Throwable $e) {
            // Return partial results
        }
        return $result;
    }

    /**
     * Dismiss a failed notification (mark as dismissed, won't retry).
     */
    public function dismissOne(int $queueId, string $dismissedBy = 'Admin'): bool
    {
        try {
            $pdo = $this->store->getPdo();
            $stmt = $pdo->prepare("UPDATE notification_queue SET status = 'dismissed', retry_by = ? WHERE id = ? AND status = 'failed'");
            $stmt->execute([$dismissedBy, $queueId]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Dismiss all failed notifications in bulk.
     * @return int Number dismissed
     */
    public function dismissAll(string $dismissedBy = 'Admin'): int
    {
        try {
            $pdo = $this->store->getPdo();
            $stmt = $pdo->prepare("UPDATE notification_queue SET status = 'dismissed', retry_by = ? WHERE status = 'failed'");
            $stmt->execute([$dismissedBy]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Purge old resolved items (sent/dismissed older than N days).
     * Called from cron_maintenance.php or manually.
     */
    public function purgeQueue(int $olderThanDays = 30): int
    {
        try {
            $pdo = $this->store->getPdo();
            $stmt = $pdo->prepare(
                "DELETE FROM notification_queue WHERE status IN ('sent','dismissed') AND created_at < datetime('now', ?)"
            );
            $stmt->execute(["-{$olderThanDays} days"]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // HRM PAYSLIP NOTIFICATIONS  →  ACCOUNTS
    // v4.11.0: Replaces "Cash Received" for salary payments.
    // Staff gets a proper payslip instead of a misleading cash-in message.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Send payment confirmation when a partial/full disbursement is made.
     */
    public function payrollDisbursement(string $phone, string $name, float $amount,
        string $component, string $period, float $totalPaid, float $netPay,
        string $voucherRef = ''): void
    {
        $amtDisp = '$' . number_format($amount, 2);
        $totalDisp = '$' . number_format($totalPaid, 2);
        $netDisp   = '$' . number_format($netPay, 2);
        $refLine = $voucherRef ? "\n🔖 Voucher: {$voucherRef}" : '';

        $remaining = $netPay - $totalPaid;
        $statusLine = $remaining <= 0.01
            ? '✅ Fully paid for this month'
            : '⏳ Balance remaining: $' . number_format($remaining, 2);

        $msg = "💼 *Salary Payment*\n\n"
             . "Hi {$name},\n\n"
             . "You have received *{$amtDisp}* ({$component})\n"
             . "📅 Period: {$period}\n"
             . "💰 Total paid so far: {$totalDisp} of {$netDisp}\n"
             . "{$statusLine}"
             . $refLine . "\n\n"
             . "— DishNet Africa HR";

        $this->sendVia(self::ACCOUNTS, $phone, $msg,
            'payroll_disbursement',
            ['staff_name' => $name, 'amount' => $amtDisp, 'period' => $period,
             'total_paid' => $totalDisp, 'net_pay' => $netDisp]
        );
    }

    /**
     * Send full monthly payslip when period is closed or on demand.
     */
    public function payslipMonthly(string $phone, string $name, array $payslipData): void
    {
        $period = $payslipData['period'] ?? '';
        $periodLabel = date('F Y', strtotime(($period ?: date('Y-m')) . '-01'));

        $earnLines = [];
        foreach (($payslipData['earnings'] ?? []) as $label => $amt) {
            if ((float)$amt > 0) {
                $earnLines[] = '   ' . str_pad($label, 22) . '$' . number_format((float)$amt, 2);
            }
        }
        $earnBlock = implode("\n", $earnLines);

        $dedLines = [];
        foreach (($payslipData['deductions'] ?? []) as $label => $amt) {
            if ((float)$amt > 0) {
                $dedLines[] = '   ' . str_pad($label, 22) . '$' . number_format((float)$amt, 2);
            }
        }

        $gross = '$' . number_format((float)($payslipData['gross_pay'] ?? 0), 2);
        $totalD = '$' . number_format((float)($payslipData['total_deductions'] ?? 0), 2);
        $net   = '$' . number_format((float)($payslipData['net_pay'] ?? 0), 2);

        $payLines = [];
        foreach (($payslipData['disbursements'] ?? []) as $d) {
            $dAmt  = '$' . number_format((float)($d['amount'] ?? 0), 2);
            $dDate = $d['date'] ?? '';
            $payLines[] = "   Paid: {$dAmt} ({$dDate})";
        }
        $payBlock = implode("\n", $payLines);

        $status = (float)($payslipData['balance_due'] ?? 0) <= 0.01
            ? '✅ Fully Paid'
            : '⏳ Balance: $' . number_format((float)($payslipData['balance_due'] ?? 0), 2);

        $msg = "💼 *Payslip — {$periodLabel}*\n\n"
             . "Hi {$name},\n\n"
             . "📋 *Earnings*\n{$earnBlock}\n"
             . "   ─────────────────────\n"
             . "   Gross Pay       {$gross}\n";

        if (!empty($dedLines)) {
            $dedBlock = implode("\n", $dedLines);
            $msg .= "\n📉 *Deductions*\n{$dedBlock}\n"
                  . "   ─────────────────────\n"
                  . "   Total Deductions {$totalD}\n";
        }

        $msg .= "\n💰 *Net Pay: {$net}*\n\n";

        if ($payBlock) {
            $msg .= "📊 *Payment History*\n{$payBlock}\n"
                  . "   ─────────────────────\n"
                  . "   {$status}\n\n";
        }

        $msg .= "🔖 Payroll Ref: PR-{$period}\n"
              . "— DishNet Africa HR";

        $this->sendVia(self::ACCOUNTS, $phone, $msg,
            'payslip_monthly',
            ['staff_name' => $name, 'period' => $period, 'net_pay' => $net, 'status' => $status]
        );
    }

    /**
     * Notify admin that a staff member has submitted a leave request.
     */
    public function leaveRequestPending(string $empName, string $leaveType,
        string $startDate, string $endDate, int $days, string $reason = ''): void
    {
        $adminPhone = $this->adminPhone;
        if (!$adminPhone) return;

        $start = date('M j', strtotime($startDate));
        $end   = date('M j, Y', strtotime($endDate));
        $reasonLine = $reason ? "\n📝 Reason: {$reason}" : '';

        $msg = "📋 *Leave Request — Pending Approval*\n\n"
             . "*{$empName}* has requested leave:\n"
             . "📋 Type: {$leaveType}\n"
             . "📅 {$start} — {$end} ({$days} day" . ($days > 1 ? 's' : '') . ")"
             . $reasonLine . "\n\n"
             . "Go to HRM → Leave to approve or reject.\n\n"
             . "— DishNet Hybrid";

        $this->sendVia(self::ACCOUNTS, $adminPhone, $msg,
            'leave_request_pending',
            ['staff_name' => $empName, 'leave_type' => $leaveType, 'days' => $days]
        );
    }

    /**
     * Notify staff of leave request approval.
     */
    public function leaveApproved(string $phone, string $name, string $leaveType,
        string $startDate, string $endDate, int $days): void
    {
        $start = date('M j', strtotime($startDate));
        $end   = date('M j, Y', strtotime($endDate));

        $msg = "✅ *Leave Approved*\n\n"
             . "Hi {$name},\n\n"
             . "Your leave request has been approved:\n"
             . "📋 Type: {$leaveType}\n"
             . "📅 {$start} — {$end} ({$days} day" . ($days > 1 ? 's' : '') . ")\n\n"
             . "— DishNet Africa HR";

        $this->sendVia(self::ACCOUNTS, $phone, $msg,
            'leave_approved',
            ['staff_name' => $name, 'leave_type' => $leaveType, 'days' => $days]
        );
    }

    /**
     * Notify staff of leave request rejection.
     */
    public function leaveRejected(string $phone, string $name, string $leaveType,
        string $reason): void
    {
        $msg = "❌ *Leave Request Declined*\n\n"
             . "Hi {$name},\n\n"
             . "Your {$leaveType} request could not be approved.\n"
             . ($reason ? "📋 Reason: {$reason}\n\n" : "\n")
             . "Please speak with your manager for details.\n\n"
             . "— DishNet Africa HR";

        $this->sendVia(self::ACCOUNTS, $phone, $msg,
            'leave_rejected',
            ['staff_name' => $name, 'leave_type' => $leaveType, 'reason' => $reason]
        );
    }

    // Internal retry tracking (avoids re-queueing during retry)
    private bool $_retryMode = false;
    /** @var bool|null */
    private $_lastSendSuccess = null;
    /** @var int|null */
    private $_lastHttpCode = null;
    /** @var string|null */
    private $_lastError = null;

    /**
     * Ensure the notification_audit_log SQLite table exists.
     * Called lazily on first write/read.
     */
    private bool $_auditTableReady = false;
    private function ensureAuditTable(): void
    {
        if ($this->_auditTableReady) return;
        try {
            $this->store->getPdo()->exec("CREATE TABLE IF NOT EXISTS notification_audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender TEXT,
                event TEXT,
                phone TEXT,
                preview TEXT,
                success INTEGER NOT NULL DEFAULT 0,
                http_code INTEGER,
                error TEXT,
                sent_at TEXT NOT NULL DEFAULT (datetime('now'))
            )");
            // Indexes for common queries (phone lookup, date range, success rate)
            $this->store->getPdo()->exec("CREATE INDEX IF NOT EXISTS idx_nal_sent_at ON notification_audit_log(sent_at)");
            $this->store->getPdo()->exec("CREATE INDEX IF NOT EXISTS idx_nal_phone ON notification_audit_log(phone)");
            $this->store->getPdo()->exec("CREATE INDEX IF NOT EXISTS idx_nal_event ON notification_audit_log(event)");
            $this->_auditTableReady = true;
        } catch (\Throwable $e) { /* never break main flow */ }
    }

    private function writeLog(array $entry): void
    {
        try {
            $this->ensureAuditTable();
            $pdo = $this->store->getPdo();
            $stmt = $pdo->prepare(
                "INSERT INTO notification_audit_log (sender, event, phone, preview, success, http_code, error, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $entry['sender']    ?? null,
                $entry['event']     ?? null,
                $entry['to']        ?? null,
                mb_substr($entry['preview'] ?? '', 0, 200),
                ($entry['success'] ?? false) ? 1 : 0,
                $entry['http_code'] ?? null,
                mb_substr($entry['error'] ?? '', 0, 500) ?: null,
                $entry['sent_at']   ?? date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { /* never break main flow */ }
    }

    /**
     * Get recent audit log entries.
     * Replaces the old 500-entry notification_log.json.
     *
     * @param int    $limit   Max rows (default 50)
     * @param string $since   ISO date filter (e.g. '2026-03-01')
     * @param string $phone   Filter by phone number
     * @return array ['entries' => [...], 'total' => int]
     */
    public function getAuditLog(int $limit = 50, string $since = '', string $phone = ''): array
    {
        try {
            $this->ensureAuditTable();
            $pdo = $this->store->getPdo();

            $where = [];
            $params = [];
            if ($since) {
                $where[]  = "sent_at >= ?";
                $params[] = $since;
            }
            if ($phone) {
                $where[]  = "phone = ?";
                $params[] = preg_replace('/[^0-9]/', '', $phone);
            }
            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notification_audit_log {$whereClause}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $params[] = min(200, max(1, $limit));
            $stmt = $pdo->prepare("SELECT * FROM notification_audit_log {$whereClause} ORDER BY id DESC LIMIT ?");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Cast success back to boolean for API consumers
            foreach ($rows as &$r) {
                $r['success'] = (bool)$r['success'];
            }
            unset($r);

            return ['entries' => $rows, 'total' => $total];
        } catch (\Throwable $e) {
            return ['entries' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get notification stats for dashboard KPIs.
     * @return array ['today' => int, 'month' => int, 'success_rate' => float, 'by_event' => [...]]
     */
    public function getAuditStats(): array
    {
        try {
            $this->ensureAuditTable();
            $pdo = $this->store->getPdo();
            $today     = date('Y-m-d');
            $monthStart = date('Y-m-01');

            $todayCount = (int)$pdo->query(
                "SELECT COUNT(*) FROM notification_audit_log WHERE sent_at >= '{$today}'"
            )->fetchColumn();

            $monthCount = (int)$pdo->query(
                "SELECT COUNT(*) FROM notification_audit_log WHERE sent_at >= '{$monthStart}'"
            )->fetchColumn();

            $monthSuccess = (int)$pdo->query(
                "SELECT COUNT(*) FROM notification_audit_log WHERE sent_at >= '{$monthStart}' AND success = 1"
            )->fetchColumn();

            $successRate = $monthCount > 0 ? round(($monthSuccess / $monthCount) * 100, 1) : 0;

            $byEvent = $pdo->query(
                "SELECT event, COUNT(*) as cnt, SUM(success) as ok
                 FROM notification_audit_log WHERE sent_at >= '{$monthStart}'
                 GROUP BY event ORDER BY cnt DESC LIMIT 20"
            )->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'today'        => $todayCount,
                'month'        => $monthCount,
                'month_ok'     => $monthSuccess,
                'success_rate' => $successRate,
                'by_event'     => $byEvent,
            ];
        } catch (\Throwable $e) {
            return ['today' => 0, 'month' => 0, 'success_rate' => 0, 'by_event' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Purge audit log entries older than N days.
     * Called from cron_maintenance.php.
     */
    public function purgeAuditLog(int $olderThanDays = 90): int
    {
        try {
            $this->ensureAuditTable();
            $pdo = $this->store->getPdo();
            $stmt = $pdo->prepare(
                "DELETE FROM notification_audit_log WHERE sent_at < datetime('now', ?)"
            );
            $stmt->execute(["-{$olderThanDays} days"]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // ATOMIC DEDUP — replaces JSON log files for notification deduplication.
    // Uses SQLite INSERT OR IGNORE on a UNIQUE key for race-safe checks.
    // Replaces: invoice_notify_log.json, payment_notify_log.json,
    //           pre_due_reminder_log.json, overdue_escalation_log.json
    // ══════════════════════════════════════════════════════════════════════

    private bool $_dedupTableReady = false;
    private function ensureDedupTable(): void
    {
        if ($this->_dedupTableReady) return;
        try {
            $this->store->getPdo()->exec("CREATE TABLE IF NOT EXISTS notification_dedup (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                dedup_key TEXT NOT NULL UNIQUE,
                sent_at TEXT NOT NULL DEFAULT (datetime('now'))
            )");
            $this->store->getPdo()->exec("CREATE INDEX IF NOT EXISTS idx_nd_sent ON notification_dedup(sent_at)");
            $this->_dedupTableReady = true;
        } catch (\Throwable $e) { /* safe fallback: caller should proceed */ }
    }

    /**
     * Check if a dedup key has already been recorded.
     * @return bool true if already sent (should skip)
     */
    public function dedupCheck(string $key): bool
    {
        try {
            $this->ensureDedupTable();
            $stmt = $this->store->getPdo()->prepare(
                "SELECT 1 FROM notification_dedup WHERE dedup_key = ?"
            );
            $stmt->execute([$key]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false; // On error, allow send (better to double-send than block)
        }
    }

    /**
     * Mark a dedup key as sent. Returns true if this is the first insert (safe to send),
     * false if the key already existed (duplicate, should skip).
     * Uses INSERT OR IGNORE for atomic check-and-mark.
     */
    public function dedupMark(string $key): bool
    {
        try {
            $this->ensureDedupTable();
            $stmt = $this->store->getPdo()->prepare(
                "INSERT OR IGNORE INTO notification_dedup (dedup_key, sent_at) VALUES (?, ?)"
            );
            $stmt->execute([$key, date('Y-m-d H:i:s')]);
            return $stmt->rowCount() > 0; // 1 = freshly inserted; 0 = already existed
        } catch (\Throwable $e) {
            return true; // On error, allow send
        }
    }

    /**
     * Prune old dedup entries to keep the table lean.
     */
    public function purgeDedupLog(int $olderThanDays = 45): int
    {
        try {
            $this->ensureDedupTable();
            $stmt = $this->store->getPdo()->prepare(
                "DELETE FROM notification_dedup WHERE sent_at < datetime('now', ?)"
            );
            $stmt->execute(["-{$olderThanDays} days"]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
