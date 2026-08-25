<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains'))  { function str_contains(string $h, string $n): bool  { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool  { return $n===''||substr($h,-strlen($n))===$n; } }

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/CrmApiClient.php';

/**
 * QuotationService — DishNet Hybrid v4.4.20
 *
 * Handles all quotation flows:
 *
 *   A) KYC registration  → quote already created in UCRM → send WA to customer
 *   B) Lead quote        → build proforma text + optional UCRM quote → WA customer
 *   C) Cash sale         → instant proforma WA (no CRM client required)
 *   D) Manual trigger    → agent picks customer + items → WA + optional UCRM
 *
 * Quote lifecycle stored in quotes_log.json:
 *   id, quote_ref, type (kyc|lead|cash|manual), customer_name, customer_phone,
 *   crm_client_id, crm_quote_id, items[], total, currency, valid_until,
 *   sent_via_wa, sent_via_crm, sent_by (retailer name), created_at
 *
 * PHP 7.4 compatible.
 */
class QuotationService
{
    const LOG_FILE     = 'quotes_log.json';
    const CURRENCY     = 'USD';
    const VALIDITY_DAYS = 7;

    // DishNet branding for WA proforma
    const COMPANY_NAME  = 'DishNet Africa';
    const COMPANY_PHONE = '+211920000000';  // override via config: quote_company_phone
    const COMPANY_EMAIL = 'info@dishnetafrica.com';

    private \PDO   $pdo;
    private        $store;
    private string $dataDir;
    private array  $config;
    private NotificationService $ns;
    private CrmApiClient $crm;

    public function __construct($store, string $dataDir, array $config = [])
    {
        $this->store   = $store;
        $this->dataDir = rtrim($dataDir, '/');
        // Load config from store if not provided directly
        if (empty($config)) {
            $config = $store->load('kyc_config.json') ?: [];
        }
        $this->config = $config;
        $this->ns  = new NotificationService($store, $config);
        // Use factory method — resolves API URL + key from ucrm.json automatically
        $pluginRoot = dirname($dataDir);  // data/ is inside plugin root
        if (!file_exists($pluginRoot . '/manifest.json')) {
            $pluginRoot = dirname($pluginRoot); // try one more level up
        }
        $this->crm = CrmApiClient::fromUcrm($pluginRoot, $config);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FLOW A — KYC Quote WhatsApp delivery
    // Called right after KycService creates the UCRM quote on registration.
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Send WA proforma to customer after KYC registration.
     * The UCRM quote already exists — we just send WA with the summary.
     *
     * @param array $application  The saved KYC application record
     * @param array $quoteItems   Line items [{label, quantity, price, unit}]
     * @param int   $crmQuoteId   UCRM quote ID
     * @param array $retailer     Logged-in agent
     */
    public function sendKycQuoteWhatsApp(array $application, array $quoteItems, int $crmQuoteId, array $retailer): bool
    {
        $phone = preg_replace('/[^0-9+]/', '', $application['mobile'] ?? $application['phone'] ?? '');
        if (!$phone) return false;

        $name     = trim(($application['firstname'] ?? '') . ' ' . ($application['lastname'] ?? '')) ?: ($application['customer_name'] ?? 'Valued Customer');
        $quoteRef = $application['quote_ref'] ?? ('QUO-' . $crmQuoteId);
        $total    = $this->itemsTotal($quoteItems);

        $msg = $this->buildProformaMessage($quoteRef, $name, $quoteItems, $total, [
            'type'    => 'New Connection',
            'via_crm' => true,
            'agent'   => $retailer['name'] ?? '',
        ]);

        $sent = $this->sendWA($phone, $msg, 'quote_kyc');

        $this->logQuote([
            'type'           => 'kyc',
            'quote_ref'      => $quoteRef,
            'crm_client_id'  => (int)($application['crm_client_id'] ?? 0),
            'crm_quote_id'   => $crmQuoteId,
            'customer_name'  => $name,
            'customer_phone' => $phone,
            'items'          => $quoteItems,
            'total'          => $total,
            'sent_via_wa'    => $sent,
            'sent_via_crm'   => true,
            'sent_by'        => $retailer['name'] ?? '',
            'valid_until'    => date('Y-m-d', strtotime('+' . self::VALIDITY_DAYS . ' days')),
        ]);

        return $sent;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FLOW B — Lead Quote (before KYC — just interest stage)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Send a quotation for a lead (no CRM client yet).
     * Builds proforma from interest_plan + service_type on the lead record.
     * Optionally creates a UCRM quote if lead has a crm_client_id already.
     *
     * @param array  $lead      Lead record from leads.json
     * @param array  $items     Override line items — if empty, auto-built from lead
     * @param array  $retailer  Logged-in agent
     * @param bool   $createCrmQuote  Push to UCRM billing/quotes if crm_client_id exists
     */
    public function sendLeadQuote(array $lead, array $items, array $retailer, bool $createCrmQuote = false): array
    {
        $phone = preg_replace('/[^0-9+]/', '', $lead['phone'] ?? $lead['mobile'] ?? '');
        $name  = $lead['customer_name'] ?? 'Valued Customer';

        // Auto-build items from lead interest if none provided
        if (empty($items)) {
            $items = $this->buildLeadItems($lead);
        }

        if (empty($items)) {
            return ['ok' => false, 'error' => 'No pricing items could be determined for this lead. Please add items manually.'];
        }

        $total    = $this->itemsTotal($items);
        $quoteRef = $this->nextQuoteRef('LEAD');
        $crmQuoteId = null;
        $sentViaCrm = false;

        // Optionally push to UCRM if lead already has a CRM client ID
        $crmClientId = (int)($lead['crm_client_id'] ?? 0);
        if ($createCrmQuote && $crmClientId > 0) {
            $result = $this->createCrmQuote($crmClientId, $items, $quoteRef, $retailer);
            if ($result['ok']) {
                $crmQuoteId = $result['quote_id'];
                $sentViaCrm = true;
                // Use UCRM's number as canonical ref (e.g. "Q-00042")
                if (!empty($result['quote_number'])) {
                    $quoteRef = $result['quote_number'];
                }
            }
        }

        // Send WA to customer
        $waSent = false;
        if ($phone) {
            $msg    = $this->buildProformaMessage($quoteRef, $name, $items, $total, [
                'type'       => 'Quotation — ' . ucfirst($lead['service_type'] ?? 'Service'),
                'via_crm'    => $sentViaCrm,
                'agent'      => $retailer['name'] ?? '',
                'note'       => $lead['notes'] ?? '',
                'follow_up'  => $lead['follow_up_date'] ?? '',
            ]);
            $waSent = $this->sendWA($phone, $msg, 'quote_lead');
        }

        // Update lead status to 'quoted'
        $leads = $this->store->load('leads.json') ?? [];
        foreach ($leads as &$l) {
            if ((int)($l['id'] ?? 0) === (int)($lead['id'] ?? 0)) {
                $l['status']     = 'quoted';
                $l['quote_ref']  = $quoteRef;
                $l['quoted_at']  = date('Y-m-d H:i:s');
                $l['quoted_by']  = $retailer['name'] ?? '';
                if ($crmQuoteId) $l['crm_quote_id'] = $crmQuoteId;
                break;
            }
        }
        unset($l);
        $this->store->save('leads.json', $leads);

        $this->logQuote([
            'type'           => 'lead',
            'quote_ref'      => $quoteRef,
            'crm_client_id'  => $crmClientId,
            'crm_quote_id'   => $crmQuoteId,
            'customer_name'  => $name,
            'customer_phone' => $phone,
            'items'          => $items,
            'total'          => $total,
            'sent_via_wa'    => $waSent,
            'sent_via_crm'   => $sentViaCrm,
            'sent_by'        => $retailer['name'] ?? '',
            'valid_until'    => date('Y-m-d', strtotime('+' . self::VALIDITY_DAYS . ' days')),
        ]);

        return [
            'ok'           => true,
            'quote_ref'    => $quoteRef,
            'sent_via_wa'  => $waSent,
            'sent_via_crm' => $sentViaCrm,
            'crm_quote_id' => $crmQuoteId,
            'total'        => $total,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FLOW C — Cash Sale Proforma (walk-in, instant WA)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Send instant proforma for a cash walk-in sale.
     * No CRM client required — phone number is enough.
     *
     * @param string $customerName
     * @param string $customerPhone
     * @param array  $items          [{label, quantity, price, unit}]
     * @param float  $amountPaid     Cash already collected
     * @param array  $retailer       Logged-in agent
     */
    public function sendCashSaleProforma(
        string $customerName,
        string $customerPhone,
        array  $items,
        float  $amountPaid,
        array  $retailer
    ): array {
        $phone = preg_replace('/[^0-9+]/', '', $customerPhone);
        if (!$phone) return ['ok' => false, 'error' => 'Customer phone number required.'];
        if (empty($items)) return ['ok' => false, 'error' => 'At least one item required.'];

        $total    = $this->itemsTotal($items);
        $balance  = round($total - $amountPaid, 2);
        $quoteRef = $this->nextQuoteRef('CASH');

        $msg = $this->buildProformaMessage($quoteRef, $customerName, $items, $total, [
            'type'        => 'Cash Sale Receipt',
            'amount_paid' => $amountPaid,
            'balance'     => $balance,
            'agent'       => $retailer['name'] ?? '',
        ]);

        $sent = $this->sendWA($phone, $msg, 'quote_cash');

        $this->logQuote([
            'type'           => 'cash',
            'quote_ref'      => $quoteRef,
            'crm_client_id'  => 0,
            'crm_quote_id'   => null,
            'customer_name'  => $customerName,
            'customer_phone' => $phone,
            'items'          => $items,
            'total'          => $total,
            'amount_paid'    => $amountPaid,
            'balance_due'    => $balance,
            'sent_via_wa'    => $sent,
            'sent_via_crm'   => false,
            'sent_by'        => $retailer['name'] ?? '',
            'valid_until'    => date('Y-m-d'),
        ]);

        return [
            'ok'          => true,
            'quote_ref'   => $quoteRef,
            'sent_via_wa' => $sent,
            'total'       => $total,
            'balance_due' => $balance,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FLOW D — Manual Quote (agent triggers for any existing CRM customer)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Agent manually triggers a quote for any customer.
     * Requires: crm_client_id OR customer_phone + customer_name.
     *
     * @param array $data [
     *   'crm_client_id'   => int (optional — fetches phone from CRM if set),
     *   'customer_name'   => string,
     *   'customer_phone'  => string,
     *   'items'           => [{label, quantity, price, unit}],
     *   'note'            => string (optional footer note),
     *   'create_crm_quote'=> bool (default true if crm_client_id set),
     * ]
     * @param array $retailer
     */
    public function sendManualQuote(array $data, array $retailer): array
    {
        $crmClientId   = (int)($data['crm_client_id'] ?? 0);
        $customerName  = trim($data['customer_name'] ?? '');
        $customerPhone = trim($data['customer_phone'] ?? '');
        $items         = $data['items'] ?? [];
        $note          = trim($data['note'] ?? '');
        $createCrm     = ($data['create_crm_quote'] ?? ($crmClientId > 0));

        if (empty($items)) return ['ok' => false, 'error' => 'At least one line item required.'];

        // If CRM client ID given, fetch name/phone from CRM if not provided
        if ($crmClientId > 0 && (!$customerName || !$customerPhone)) {
            try {
                $crmClient = $this->crm->get("clients/{$crmClientId}");
                if ($crmClient) {
                    if (!$customerName)  $customerName  = trim(($crmClient['firstName'] ?? '') . ' ' . ($crmClient['lastName'] ?? ''));
                    if (!$customerPhone) $customerPhone = $crmClient['contacts'][0]['phone'] ?? $crmClient['contacts'][0]['value'] ?? '';
                }
            } catch (\Throwable $e) { /* CRM unreachable — proceed with what we have */ }
        }

        if (!$customerName)  return ['ok' => false, 'error' => 'Customer name required.'];
        if (!$customerPhone) return ['ok' => false, 'error' => 'Customer phone required (or provide crm_client_id to auto-fetch).'];

        $phone    = preg_replace('/[^0-9+]/', '', $customerPhone);
        $total    = $this->itemsTotal($items);
        $quoteRef = $this->nextQuoteRef('MAN');
        $crmQuoteId = null;
        $sentViaCrm = false;

        if ($createCrm && $crmClientId > 0) {
            $result = $this->createCrmQuote($crmClientId, $items, $quoteRef, $retailer, $note);
            if ($result['ok']) {
                $crmQuoteId = $result['quote_id'];
                $sentViaCrm = true;
                // Use UCRM's number as canonical ref
                if (!empty($result['quote_number'])) {
                    $quoteRef = $result['quote_number'];
                }
            }
        }

        $msg    = $this->buildProformaMessage($quoteRef, $customerName, $items, $total, [
            'type'    => 'Quotation',
            'via_crm' => $sentViaCrm,
            'agent'   => $retailer['name'] ?? '',
            'note'    => $note,
        ]);
        $waSent = $this->sendWA($phone, $msg, 'quote_manual');

        $this->logQuote([
            'type'           => 'manual',
            'quote_ref'      => $quoteRef,
            'crm_client_id'  => $crmClientId,
            'crm_quote_id'   => $crmQuoteId,
            'customer_name'  => $customerName,
            'customer_phone' => $phone,
            'items'          => $items,
            'total'          => $total,
            'sent_via_wa'    => $waSent,
            'sent_via_crm'   => $sentViaCrm,
            'sent_by'        => $retailer['name'] ?? '',
            'valid_until'    => date('Y-m-d', strtotime('+' . self::VALIDITY_DAYS . ' days')),
        ]);

        return [
            'ok'           => true,
            'quote_ref'    => $quoteRef,
            'sent_via_wa'  => $waSent,
            'sent_via_crm' => $sentViaCrm,
            'crm_quote_id' => $crmQuoteId,
            'total'        => $total,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SHARED HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Build the WhatsApp proforma message.
     * Produces a clean, structured WA message that renders well on mobile.
     */
    public function buildProformaMessage(
        string $quoteRef,
        string $customerName,
        array  $items,
        float  $total,
        array  $opts = []
    ): string {
        $type       = $opts['type']        ?? 'Quotation';
        $viaCrm     = $opts['via_crm']     ?? false;
        $agent      = $opts['agent']       ?? '';
        $agentPhone = $opts['agent_phone'] ?? '';
        $note       = $opts['note']        ?? '';
        $followUp   = $opts['follow_up']   ?? '';
        $amountPaid = isset($opts['amount_paid']) ? (float)$opts['amount_paid'] : null;
        $balance    = isset($opts['balance'])     ? (float)$opts['balance']     : null;
        $company    = $this->config['quote_company_name']  ?? self::COMPANY_NAME;
        $compPhone  = $this->config['quote_company_phone'] ?? self::COMPANY_PHONE;
        $compEmail  = $this->config['quote_company_email'] ?? self::COMPANY_EMAIL;
        $validDays  = (int)($this->config['kyc_quote_validity_days'] ?? self::VALIDITY_DAYS);
        $validUntil = date('d M Y', strtotime("+{$validDays} days"));

        // ── Header ──────────────────────────────────────────────────────────
        $lines = [];
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "🏢 *{$company}*";
        $lines[] = "📄 *{$type}*";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "";
        $lines[] = "📋 *Ref:* {$quoteRef}";
        $lines[] = "👤 *To:* {$customerName}";
        $lines[] = "📅 *Date:* " . date('d M Y');
        $lines[] = "⏳ *Valid Until:* {$validUntil}";
        $lines[] = "";

        // ── Line items ───────────────────────────────────────────────────────
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "*ITEMS*";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━";
        foreach ($items as $item) {
            $qty       = max(1, (int)($item['quantity'] ?? 1));
            $price     = (float)($item['price'] ?? 0);
            $lineTotal = round($qty * $price, 2);
            $unit      = $item['unit'] ?? '';
            $unitLabel = $unit && $unit !== 'amount' ? " / {$unit}" : '';
            $label     = $item['label'] ?? 'Item';
            if ($qty > 1) {
                $lines[] = "• {$label}";
                $lines[] = "  {$qty} × \${$price}{$unitLabel} = *\${$lineTotal}*";
            } else {
                $lines[] = "• {$label}: *\${$lineTotal}{$unitLabel}*";
            }
        }
        $lines[] = "";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "💰 *TOTAL: \${$total}*";

        // ── Payment info ────────────────────────────────────────────────────
        if ($amountPaid !== null) {
            $lines[] = "✅ *Paid: \${$amountPaid}*";
            if ($balance !== null && $balance > 0) {
                $lines[] = "⚠️ *Balance Due: \${$balance}*";
            } elseif ($balance !== null && $balance <= 0) {
                $lines[] = "✅ *Fully Paid*";
            }
        }
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━";

        // ── Notes / CRM status ──────────────────────────────────────────────
        if ($note) {
            $lines[] = "";
            $lines[] = "📝 " . $note;
        }
        if ($viaCrm) {
            $lines[] = "";
            $lines[] = "📧 _A formal quote has also been sent to your email via our billing system._";
        }
        if ($followUp) {
            $lines[] = "";
            $lines[] = "📞 _We'll follow up with you on " . date('d M Y', strtotime($followUp)) . "._";
        }

        // ── Footer ──────────────────────────────────────────────────────────
        $lines[] = "";
        if ($agent && $agentPhone) {
            $lines[] = "👨‍💼 Agent: {$agent}";
            $lines[] = "📞 {$agentPhone}";
        } elseif ($agent) {
            $lines[] = "👨‍💼 Agent: {$agent}";
            $lines[] = "📞 {$compPhone}";
        } else {
            $lines[] = "📞 {$compPhone}";
        }
        $lines[] = "✉️  {$compEmail}";
        $lines[] = "";
        $lines[] = "_Thank you for choosing {$company}!_ 🌐";

        return implode("\n", $lines);
    }

    /**
     * Push a quote to UCRM billing/quotes and immediately send it via UCRM.
     */
    public function createCrmQuote(int $crmClientId, array $items, string $quoteRef, array $retailer, string $note = ''): array
    {
        $validityDays = (int)($this->config['kyc_quote_validity_days'] ?? self::VALIDITY_DAYS);
        $notesPrefix  = trim($this->config['kyc_quote_notes_prefix']  ?? '');
        $agentNote    = "Ref: {$quoteRef} | Agent: " . ($retailer['name'] ?? 'Agent');
        $fullNote     = trim(($notesPrefix ? $notesPrefix . "\n" : '') . $agentNote . ($note ? "\n" . $note : ''));

        $payload = [
            'clientId'            => $crmClientId,
            'invoiceMaturityDays' => $validityDays,
            'notes'               => $fullNote,
            'adminNotes'          => "Plugin ref: {$quoteRef}",
            'invoiceItems'        => $items,
        ];

        try {
            $resp = $this->crm->post('billing/quotes', $payload);
            if (!empty($resp['id'])) {
                $quoteId    = (int)$resp['id'];
                // Use UCRM's number (e.g. PF003847) — fetch if not in POST response
                $ucrmNumber = $resp['number'] ?? null;
                if (!$ucrmNumber) {
                    $fetched    = $this->crm->get("billing/quotes/{$quoteId}");
                    $ucrmNumber = $fetched['number'] ?? null;
                }
                // Send via UCRM (triggers email to customer)
                $this->crm->patch("billing/quotes/{$quoteId}/send");
                return ['ok' => true, 'quote_id' => $quoteId, 'quote_number' => $ucrmNumber];
            }
            return ['ok' => false, 'error' => 'UCRM did not return quote ID.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Auto-build quote items from a lead record using subscription_plans / kyc_packages.
     */
    public function buildLeadItems(array $lead): array
    {
        $items = [];
        $serviceType  = strtolower($lead['service_type'] ?? 'starlink');
        $interestPlan = trim($lead['interest_plan'] ?? '');

        // Try to match plan by name in subscription_plans.json
        if ($interestPlan) {
            $plans = $this->store->load('subscription_plans.json') ?? [];
            foreach ($plans as $plan) {
                $planName = strtolower($plan['name'] ?? '');
                if (str_contains($planName, strtolower($interestPlan)) ||
                    str_contains(strtolower($interestPlan), $planName)) {
                    $price = (float)($plan['customer_price'] ?? $plan['amount'] ?? 0);
                    if ($price > 0) {
                        $items[] = [
                            'label'    => $plan['name'],
                            'quantity' => 1,
                            'price'    => $price,
                            'unit'     => 'month',
                        ];
                        break;
                    }
                }
            }
        }

        // Fiber: add installation fee
        if ($serviceType === 'fiber' && empty($items)) {
            $installFee = (float)($this->config['fiber_install_fee'] ?? 100);
            $items[] = ['label' => 'Fiber Installation Fee', 'quantity' => 1, 'price' => $installFee, 'unit' => 'amount'];
        }

        // If still empty, add a generic placeholder so the agent sees what to fill
        if (empty($items) && $interestPlan) {
            $items[] = ['label' => ucfirst($serviceType) . ' — ' . $interestPlan, 'quantity' => 1, 'price' => 0, 'unit' => 'month'];
        }

        return $items;
    }

    /**
     * Get quote log with optional filters.
     */
    public function getQuotes(array $f = []): array
    {
        $all = $this->store->load(self::LOG_FILE) ?? [];
        if (!empty($f['type']))  $all = array_values(array_filter($all, fn($q) => ($q['type'] ?? '') === $f['type']));
        if (!empty($f['phone'])) {
            $fp = preg_replace('/[^0-9]/', '', $f['phone']);
            $all = array_values(array_filter($all, fn($q) => str_contains(preg_replace('/[^0-9]/', '', $q['customer_phone'] ?? ''), $fp)));
        }
        usort($all, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return array_slice($all, 0, (int)($f['limit'] ?? 100));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRIVATE
    // ══════════════════════════════════════════════════════════════════════════

    private function sendWA(string $phone, string $message, string $event): bool
    {
        try {
            // Quotes are pre-sale documents — use SUPPORT channel (sales number)
            $this->ns->sendVia(NotificationService::SUPPORT, $phone, $message, $event);
            return true;
        } catch (\Throwable $e) {
            error_log("[QuotationService] WA send failed to {$phone}: " . $e->getMessage());
            return false;
        }
    }

    private function itemsTotal(array $items): float
    {
        return round(array_sum(array_map(
            fn($i) => (float)($i['price'] ?? 0) * max(1, (int)($i['quantity'] ?? 1)),
            $items
        )), 2);
    }

    private function nextQuoteRef(string $prefix = 'QUO'): string
    {
        $year  = date('Y');
        $month = date('m');
        $key   = "{$prefix}-{$year}{$month}-";
        $all   = $this->store->load(self::LOG_FILE) ?? [];
        $max   = 0;
        foreach ($all as $q) {
            $ref = $q['quote_ref'] ?? '';
            if (str_starts_with($ref, $key) && preg_match('/-(\d+)$/', $ref, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return $key . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private function logQuote(array $data): void
    {
        $all = $this->store->load(self::LOG_FILE) ?? [];
        $maxId = empty($all) ? 0 : max(array_map(fn($q) => (int)($q['id'] ?? 0), $all));
        $all[] = array_merge([
            'id'         => $maxId + 1,
            'created_at' => date('Y-m-d H:i:s'),
            'currency'   => self::CURRENCY,
        ], $data);
        if (count($all) > 2000) $all = array_slice($all, -2000);
        $this->store->save(self::LOG_FILE, $all);
    }
}
