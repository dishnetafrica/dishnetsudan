<?php
declare(strict_types=1);

/**
 * KycService
 *
 * Handles KYC form processing — retailer-aware.
 * Matches original kyc_store() controller logic exactly.
 */
class KycService
{
    // CRM Custom Attribute IDs
    const ATTR_PRIORITY    = 36;
    const ATTR_SALES_PERSON= 1;
    const ATTR_REF         = 43;
    const ATTR_DEVICE_ID   = 42;
    const ATTR_PACKAGE     = 41;
    const ATTR_KIT_NUMBER  = 37;
    const ATTR_KIT_QTY     = 38;
    const ATTR_KIT_UNIT    = 40;
    const ATTR_KIT_NAME    = 39;

    // Document template IDs
    const TPL_DELIVERY_NOTE    = 4;
    const TPL_WORK_ORDER_NEW   = 2;
    const TPL_WORK_ORDER_OTHER = 3;
    const QUOTE_MATURITY_DAYS  = 7;   // Quote valid for 7 days

    // CRM tag IDs
    const TAG_NEW_CONNECTION   = 52;
    const TAG_SHIFTING         = 53;
    const TAG_OWNERSHIP_CHANGE = 54;

    // Fiber fixed pricing
    const FIBER_PRICES = [
        'Premium (60 Mbps $ 100/Month)' => 100,
        'Standard (40 Mbps $ 75/Month)' => 75,
    ];

    private CrmApiClient  $crm;
    private      $store;
    private WalletService $wallet;
    private CrmQueue      $queue;
    private string        $dataDir;

    public function __construct(CrmApiClient $crm,  $store, WalletService $wallet, CrmQueue $queue, string $dataDir = '')
    {
        $this->crm     = $crm;
        $this->store   = $store;
        $this->wallet  = $wallet;
        $this->queue   = $queue;
        $this->dataDir = $dataDir ?: (dirname(__DIR__) . '/data');
    }

    /**
     * Return a clone of this service using a different CRM client.
     * Used to post KYC payments under the agent's personal UCRM app key
     * so "Created By" in UCRM shows the agent's name.
     */
    public function withCrm(CrmApiClient $crm): self
    {
        $clone = clone $this;
        $clone->crm = $crm;
        return $clone;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PUBLIC — called by web form & mobile API
    // ══════════════════════════════════════════════════════════════════════

    /**
     * @param array  $post       Validated form/POST data
     * @param array  $files      $_FILES array (or [] for API with base64)
     * @param array  $retailer   Full retailer record
     * @return array ['success'=>bool, 'message'=>string, 'data'=>array]
     */
    public function process(array $post, array $files, array $retailer): array
    {
        // MED-02 FIX: Idempotency key on form submission prevents double-register
        // from a double-tap on slow mobile networks (agent taps Submit, sees spinner,
        // taps again). The form embeds a UUID generated at page-load time.
        // If we've already processed this exact submission key, return cached result.
        $submissionKey = trim($post['submission_key'] ?? '');
        if ($submissionKey !== '') {
            $existing = $this->store->findOne('kyc_applications.json', 'submission_key', $submissionKey);
            if ($existing !== null) {
                return [
                    'success' => true,
                    'message' => 'Application already submitted (duplicate request ignored).',
                    'data'    => $existing,
                ];
            }
        }

        // Route: existing customer or new?
        if (!empty(trim($post['customer_id'] ?? ''))) {
            return $this->handleExisting($post, $retailer);
        }
        return $this->handleNew($post, $files, $retailer);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CASE 1 — EXISTING CUSTOMER  (PATCH)
    // ══════════════════════════════════════════════════════════════════════

    private function handleExisting(array $post, array $retailer): array
    {
        $cfg         = $this->store->load('kyc_config.json') ?: [];
        $customerId  = trim($post['customer_id']);
        $salesPerson = $this->resolveSalesPerson($post, $retailer);

        // ── Resolve plan + hardware ───────────────────────────────────────
        $device = $this->store->findOne('kyc_devices.json', 'id', (int)($post['device_id'] ?? 0));
        $offer  = $this->store->findOne('subscription_plans.json', 'id', (int)($post['package_choice'] ?? 0));
        if (!$offer) $offer = $this->store->findOne('kyc_packages.json', 'id', (int)($post['package_choice'] ?? 0));

        if (!$offer) {
            return ['success' => false, 'message' => 'Please select a valid service plan.'];
        }

        // ── Resolve amount ────────────────────────────────────────────────
        [$checkAmount, , ] = $this->resolveAmount($post);
        $isCash = (($post['sales_type'] ?? '') === 'Cash');

        // ── Wallet check for Cash sales ───────────────────────────────────
        // handleExisting creates a quote + optionally posts payment to UCRM.
        // For Cash: agent must have sufficient wallet balance before proceeding.
        // For Credit: no wallet deduction — quote only.
        if ($isCash && $checkAmount > 0) {
            if (!$this->wallet->hasSufficientBalance($retailer['id'], $checkAmount)) {
                $bal = $this->wallet->getBalance($retailer['id']);
                return [
                    'success' => false,
                    'message' => "Insufficient wallet balance. Required: \$" . number_format($checkAmount, 2) .
                                 ", Available: \$" . number_format($bal, 2) . ".",
                ];
            }
        }

        // ── New service address (key info for admin in quote notes) ───────
        $newAddress  = trim(($post['address_1'] ?? '') . ', ' . ($post['address_2'] ?? ''));
        $newAddress  = rtrim($newAddress, ', ');
        if (($post['customer_type'] ?? '') === 'Fiber' && !empty($post['fiber_area'])) {
            $newAddress = ($post['fiber_area'] ?? '') . ($newAddress ? ' — ' . $newAddress : '');
        }
        $connectivity = trim($post['connectivity_type'] ?? 'Additional Service');

        // ── Build quote items ─────────────────────────────────────────────
        $quoteItems = [];

        // Service plan
        $planItem = [
            'label'    => $offer['name'] ?? 'Service Package',
            'quantity' => 1,
            'price'    => (float)($offer['customer_price'] ?? $offer['amount'] ?? 0),
            'unit'     => 'month',
        ];
        if (!empty($offer['ucrm_product_id'])) {
            $planItem['productId'] = (int)$offer['ucrm_product_id'];
        }
        $quoteItems[] = $planItem;

        // Hardware cart
        $hwCart = [];
        if (!empty($post['hw_cart_json'])) {
            $hwCart = json_decode($post['hw_cart_json'], true) ?? [];
        }
        if (!empty($hwCart)) {
            foreach ($hwCart as $hwItem) {
                $hwPrice = (float)preg_replace('/[^0-9.]/', '', (string)($hwItem['price'] ?? '0'));
                if ($hwPrice > 0) {
                    $item = [
                        'label'    => $hwItem['title'] ?? 'Hardware',
                        'quantity' => max(1, (int)($hwItem['qty'] ?? 1)),
                        'price'    => $hwPrice,
                        'unit'     => 'piece',
                    ];
                    if (!empty($hwItem['ucrm_product_id'])) {
                        $item['productId'] = (int)$hwItem['ucrm_product_id'];
                    }
                    $quoteItems[] = $item;
                }
            }
        } elseif (!empty($device) && !empty($device['price'])) {
            $devPrice = (float)preg_replace('/[^0-9.]/', '', $device['price'] ?? '0');
            if ($devPrice > 0) {
                $item = [
                    'label'    => $device['title'] ?? 'Hardware / Kit',
                    'quantity' => max(1, (int)($post['kitQty'] ?? 1)),
                    'price'    => $devPrice,
                    'unit'     => 'piece',
                ];
                if (!empty($device['ucrm_product_id'])) {
                    $item['productId'] = (int)$device['ucrm_product_id'];
                }
                $quoteItems[] = $item;
            }
        }

        // Fiber installation fee
        if (($post['customer_type'] ?? '') === 'Fiber') {
            $installFee     = (float)($cfg['fiber_install_fee'] ?? 100);
            $installProduct = (int)($cfg['fiber_install_product_id'] ?? 244);
            if ($installFee > 0) {
                $item = [
                    'label'    => 'Installation Fee',
                    'quantity' => 1,
                    'price'    => $installFee,
                    'unit'     => 'amount',
                ];
                if ($installProduct > 0) $item['productId'] = $installProduct;
                $quoteItems[] = $item;
            }
        }

        // ── Save application locally first (generates quote_ref) ──────────
        $appId = $this->saveApplication([
            'connectivity_type'     => $connectivity,
            'customer_type'         => $post['customer_type'] ?? '',
            'date'                  => $post['date'] ?? date('Y-m-d'),
            'crm_client_id'         => $customerId,
            'crm_doc_id'            => null,
            'firstname'             => $post['firstname'] ?? '',
            'lastname'              => $post['lastname'] ?? '',
            'mobile'                => $post['mobile'] ?? '',
            'email'                 => $post['email'] ?? '',
            'contacts'              => $this->buildContacts($post),
            'address_1'             => $post['address_1'] ?? '',
            'address_2'             => $post['address_2'] ?? '',
            'fiber_area'            => $post['fiber_area'] ?? '',
            'device_id'             => $post['device_id'] ?? '',
            'package_choice'        => $post['package_choice'] ?? '',
            'priority'              => $post['priority'] ?? '',
            'sales_person'          => $salesPerson,
            'sales_type'            => $post['sales_type'] ?? 'Credit',
            'ref'                   => $post['ref'] ?? '',
            'latitude'              => $post['latitude'] ?? '',
            'longitude'             => $post['longitude'] ?? '',
            'retailer_id'           => $retailer['id'],
            'retailer_name'         => $retailer['name'] ?? '',
            'status'                => 'pending_sync',
            'sync_status'           => 'pending_sync',
            'amount_charged'        => 0,
            'is_lead'               => true,
            'crm_client_type'       => 'existing',
            'is_additional_service' => true,
            'new_service_address'   => $newAddress,
            'quote_id'              => null,
            'quote_created'         => false,
        ]);

        // ── Post quote to CRM ─────────────────────────────────────────────
        $quoteId      = null;
        $quoteCreated = false;
        $qRef         = '';

        $savedApp = $this->store->findOne('kyc_applications.json', 'id', $appId);
        $qRef     = $savedApp['quote_ref'] ?? '';

        $_quoteCfg   = $cfg;
        $_quoteToken = trim($_quoteCfg['crm_auth_token'] ?? '');
        $_quoteUrl   = trim($_quoteCfg['crm_base_url'] ?? '');
        $quoteCrm = ($_quoteToken !== '')
            ? new CrmApiClient(
                $_quoteUrl !== '' ? $_quoteUrl : rtrim($this->crm->getBaseUrl(), '/'),
                $_quoteToken,
                'x-auth-token'
              )
            : $this->crm;

        // Notes include new service address prominently so admin knows where to install
        $quoteNotes = "ADDITIONAL SERVICE — EXISTING CUSTOMER\n"
                    . "New service address: {$newAddress}\n"
                    . "Service type: " . ($post['customer_type'] ?? '') . "\n"
                    . "Connection: {$connectivity}\n"
                    . "Priority: " . ($post['priority'] ?? 'Medium') . "\n"
                    . "Sales: {$salesPerson}\n"
                    . ($qRef ? "Ref: {$qRef}" : '');

        $quotePayload = [
            'notes'      => $quoteNotes,
            'adminNotes' => 'Additional service for existing client. Plugin ref: ' . $qRef,
            'items'      => $quoteItems,
        ];

        $quoteResponse = $quoteCrm->post("clients/{$customerId}/quotes", $quotePayload);

        if (!empty($quoteResponse['id'])) {
            $quoteId      = $quoteResponse['id'];
            $quoteCreated = true;

            $ucrmQuoteNumber = $quoteResponse['number'] ?? null;
            if (!$ucrmQuoteNumber) {
                $fetched         = $quoteCrm->get("billing/quotes/{$quoteId}");
                $ucrmQuoteNumber = $fetched['number'] ?? null;
            }
            if ($ucrmQuoteNumber) $qRef = $ucrmQuoteNumber;

            $quoteCrm->patch("billing/quotes/{$quoteId}/send");

            $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                'quote_id'             => $quoteId,
                'quote_ref'            => $qRef,
                'quote_created'        => true,
                'sync_status'          => 'synced',
                'status'               => 'synced',
                'wa_quote_pending'     => true,
                'wa_quote_phone'       => preg_replace('/[^0-9+]/', '', $post['mobile'] ?? ''),
                'wa_quote_deferred_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            error_log('[KycService] Additional service quote failed for CRM #' . $customerId . ': ' . json_encode($quoteCrm->getLastError()));
            $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                'quote_error' => json_encode($quoteCrm->getLastError()),
            ]);
        }

        // ── Create UCRM scheduling job assigned to Bidal (v4.20.2+) ───────
        // This is the operational handoff — Bidal sees a clearly-titled job
        // in CRM → Scheduling that flags this as a SECOND site for an
        // EXISTING customer, so he doesn't accidentally modify the original
        // service. Job creation failure is non-fatal: the quote stands.
        $crmJobId = $this->createCrmJobForBidal(
            $customerId,
            $post,
            $newAddress,
            $qRef,
            $appId
        );
        if ($crmJobId > 0) {
            $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                'crm_job_id'         => $crmJobId,
                'crm_job_created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // ── Debit wallet for Cash sales ───────────────────────────────────
        $walletDebited = false;
        if ($isCash && $checkAmount > 0) {
            try {
                $this->wallet->debit(
                    $retailer['id'],
                    $checkAmount,
                    "Additional service — CRM #{$customerId} — {$newAddress}",
                    'additional_service_' . $appId
                );
                $walletDebited = true;
                $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                    'amount_charged' => $checkAmount,
                    'wallet_debited' => true,
                ]);
            } catch (\Throwable $e) {
                error_log('[KycService] Wallet debit failed for handleExisting appId=' . $appId . ': ' . $e->getMessage());
            }
        }

        $successMsg = $quoteCreated
            ? "Quote #{$qRef} created for additional service at: {$newAddress}. Admin will review and activate."
            : "Application saved. Please notify admin to create the service manually.";

        if ($walletDebited) {
            $successMsg .= " Wallet debited \$" . number_format($checkAmount, 2) . ".";
        }

        return [
            'success' => true,
            'message' => $successMsg,
            'data'    => [
                'crm_client_id'         => $customerId,
                'application_id'        => $appId,
                'id'                    => $appId,            // alias: callers expect 'id'
                'quote_id'              => $quoteId,
                'quote_ref'             => $qRef,
                'quote_created'         => $quoteCreated,
                'new_address'           => $newAddress,
                'new_service_address'   => $newAddress,
                'wallet_debited'        => $walletDebited,
                'amount_charged'        => $isCash ? $checkAmount : 0,
                'is_additional_service' => true,
                'crm_client_type'       => 'existing',
                'crm_job_id'            => $crmJobId,
                'firstname'             => $post['firstname']     ?? '',
                'lastname'              => $post['lastname']      ?? '',
                'mobile'                => $post['mobile']        ?? '',
                'customer_type'         => $post['customer_type'] ?? '',
                'connectivity_type'     => $connectivity,
                'sales_type'            => $post['sales_type']    ?? 'Credit',
            ],
        ];
    }


    // ══════════════════════════════════════════════════════════════════════
    // CASE 2 — NEW CUSTOMER  (POST)
    // ══════════════════════════════════════════════════════════════════════

    private function handleNew(array $post, array $files, array $retailer): array
    {
        // I-02 FIX: Load kyc_config.json once and reuse throughout this method.
        // Previously it was loaded 4-5 times as $cfg2/$cfg3/$cfg4 in different steps.
        $cfg = $this->store->load('kyc_config.json') ?: [];

        $salesPerson  = $this->resolveSalesPerson($post, $retailer);
        $connectivity = trim($post['connectivity_type'] ?? 'New Connection');

        // ── Duplicate phone check ─────────────────────────────────────────
        // Uses local files only (< 50ms). Live CRM only if index is stale > 10 min.
        // Skips check if: customer_id already set (existing customer flow)
        //                 duplicate_confirmed = 1 (staff confirmed different customer)
        //                 only match is a failed/voided app
        $mobileRaw  = trim($post['mobile'] ?? '');
        $custIdSet  = trim($post['customer_id'] ?? '') !== '';
        $confirmed  = ($post['duplicate_confirmed'] ?? '0') === '1';
        $mobileNorm = preg_replace('/[^0-9]/', '', $mobileRaw);
        $last9      = strlen($mobileNorm) >= 9 ? substr($mobileNorm, -9) : $mobileNorm;

        if ($mobileRaw !== '' && $last9 !== '' && !$custIdSet) {

            $dupMatch    = null;  // first non-failed match found
            $failedOnly  = true;  // true if only failed apps matched

            // ── Check 1: local kyc_applications.json ─────────────────────
            $apps = $this->store->load('kyc_applications.json') ?? [];
            foreach ($apps as $app) {
                $appPhone = preg_replace('/[^0-9]/', '', $app['mobile'] ?? '');
                $appLast9 = strlen($appPhone) >= 9 ? substr($appPhone, -9) : $appPhone;
                if ($appLast9 !== $last9 || $appLast9 === '') continue;
                $appStatus = $app['sync_status'] ?? $app['status'] ?? '';
                if (in_array($appStatus, ['failed','voided','rejected','cancelled'])) continue;
                $failedOnly = false;
                $dupMatch = [
                    'name'   => trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? ''))
                              ?: ($app['company_name'] ?? 'Customer'),
                    'crm_id' => (int)($app['crm_client_id'] ?? 0),
                    'source' => 'plugin_app',
                ];
                break;
            }

            // ── Check 2: client_search_index (SQLite table, O(1) — JSON fallback) ──
            if (!$dupMatch) {
                $csiFound = null;
                try {
                    // Fast path: SQLite indexed O(1) lookup
                    $csiStmt = $this->store->getPdo()->prepare(
                        "SELECT id, name, phone FROM client_search_index WHERE phone_norm = ? LIMIT 1"
                    );
                    $csiStmt->execute([$last9]);
                    $csiRow = $csiStmt->fetch(\PDO::FETCH_ASSOC);
                    if ($csiRow) $csiFound = $csiRow;
                } catch (\Throwable $e) {
                    $csiFound = null; // table not ready yet
                }

                if ($csiFound) {
                    $failedOnly = false;
                    $dupMatch = [
                        'name'   => $csiFound['name'] ?? 'Customer',
                        'crm_id' => (int)($csiFound['id'] ?? 0),
                        'source' => 'index',
                    ];
                } else {
                    // Fallback: JSON blob scan (or live CRM if stale > 10 min)
                    $index    = $this->store->load('client_search_index.json') ?? [];
                    $indexMeta = $this->store->load('client_index_meta.json') ?? [];
                    $indexAge  = isset($indexMeta['last_sync']) ? (time() - (int)$indexMeta['last_sync']) : 0;
                    $useIndex  = !empty($index) && ($indexAge < 600 || empty($this->crm));

                    if ($useIndex) {
                        foreach ($index as $cl) {
                            $clPhone = preg_replace('/[^0-9]/', '', $cl['phone'] ?? '');
                            $clLast9 = strlen($clPhone) >= 9 ? substr($clPhone, -9) : $clPhone;
                            if ($clLast9 !== $last9 || $clLast9 === '') continue;
                            $failedOnly = false;
                            $dupMatch = [
                                'name'   => $cl['name'] ?? 'Customer',
                                'crm_id' => (int)($cl['id'] ?? 0),
                                'source' => 'index',
                            ];
                            break;
                        }
                    } elseif ($this->crm && $this->crm->isConfigured()) {
                        // Stale index — one live CRM call as safety net
                        $crmRes = $this->crm->get('clients?search=' . urlencode($mobileRaw) . '&limit=5') ?? [];
                        foreach ($crmRes as $cl) {
                            foreach ($cl['contacts'] ?? [] as $c) {
                                $cPhone = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
                                $cLast9 = strlen($cPhone) >= 9 ? substr($cPhone, -9) : $cPhone;
                                if ($cLast9 !== $last9) continue;
                                $failedOnly = false;
                                $dupMatch = [
                                    'name'   => trim(($cl['firstName']??'').' '.($cl['lastName']??''))
                                              ?: ($cl['companyName'] ?? 'Customer'),
                                    'crm_id' => (int)($cl['id'] ?? 0),
                                    'source' => 'live_crm',
                                ];
                                break 2;
                            }
                        }
                    }
                }
            }

            // ── Decision ─────────────────────────────────────────────────
            if ($dupMatch && !$failedOnly) {
                $name   = $dupMatch['name'];
                $crmId  = $dupMatch['crm_id'];

                if ($confirmed) {
                    // Staff confirmed it's a different customer — log it and allow through
                    try {
                        $this->store->getPdo()->prepare(
                            "INSERT INTO duplicate_confirmations
                             (staff_id, staff_name, phone, existing_name, existing_crm_id, note, review_status)
                             VALUES (?, ?, ?, ?, ?, ?, 'pending')"
                        )->execute([
                            (int)($retailer['id'] ?? 0),
                            $retailer['name'] ?? 'Unknown',
                            $mobileRaw,
                            $name,
                            $crmId,
                            $post['duplicate_note'] ?? 'confirmed-different',
                        ]);
                    } catch (\Throwable $e) {
                        error_log('[KycService] Failed to log duplicate confirmation: ' . $e->getMessage());
                        // Non-fatal — log failure doesn't block the registration
                    }
                    // Allow through — fall to normal registration below

                } else {
                    // No confirmation — block
                    $crmHint = $crmId ? " (CRM ID: {$crmId})" : '';
                    return [
                        'success' => false,
                        'message' => "⚠ Phone {$mobileRaw} is already registered under: {$name}{$crmHint}. "
                                   . ($crmId
                                        ? "To add a new service for this customer use 'Existing Customer' and enter CRM ID {$crmId}."
                                        : "If this is a different customer, please use a different phone number."),
                    ];
                }
            }
            // If $failedOnly or no match — allow through silently
        }

        // ── Step 1: Resolve amount & wallet check ────────────────────────
        [$checkAmount, $offer, $device] = $this->resolveAmount($post);
        if ($checkAmount === null) {
            return ['success' => false, 'message' => 'Please select a valid package/plan.'];
        }

        $packageId = (int)($post['package_choice'] ?? 0);
        if ($packageId <= 0) {
            return ['success' => false, 'message' => 'Please select a service plan before submitting.'];
        }

        // Ensure quote will have actual pricing — $0 quotes are not useful
        if ($checkAmount <= 0 && (($post['sales_type'] ?? '') !== 'Cash')) {
            return ['success' => false, 'message' => 'Selected plan has no pricing. Please select a valid plan with pricing or contact admin to update plan prices.'];
        }

        $isCash = (($post['sales_type'] ?? '') === 'Cash');
        // Cash payment with actual amount → Regular Customer in UCRM (isLead=false)
        // Cash with $0 OR Credit sale → Lead in UCRM (isLead=true, payment not yet received)
        $isLead = !$isCash || $checkAmount <= 0;

        if ($isCash && !$this->wallet->hasSufficientBalance($retailer['id'], $checkAmount)) {
            $bal = $this->wallet->getBalance($retailer['id']);
            return [
                'success' => false,
                'message' => "Insufficient wallet balance. Required: $" . number_format($checkAmount, 2) .
                             ", Available: $" . number_format($bal, 2),
            ];
        }

        // ── Step 2: Resolve username ──────────────────────────────────────
        $manualUsername = trim($post['crm_username'] ?? '');
        if ($manualUsername !== '') {
            if (!preg_match('/^[A-Za-z0-9_\-]{3,50}$/', $manualUsername)) {
                return ['success' => false, 'message' => 'CRM Username may only contain letters, numbers, hyphens and underscores (3–50 chars).'];
            }
            if (!$this->isCrmUsernameUnique($manualUsername)) {
                return ['success' => false, 'message' => "CRM Username \"{$manualUsername}\" is already in use. Please choose another."];
            }
            $username = $manualUsername;
        } else {
            $username = $this->generateUsername($post['customer_type'] ?? 'StarLink');
        }

        // ── Step 3: POST to CRM /clients (synchronous — like Starlink plugin) ──
        // Files are available right now in $_FILES. We call CRM immediately,
        // upload files immediately, and return crm_client_id to the agent in
        // the same HTTP response. No queue, no cron, no staging needed.
        $crmPayload = [
            'clientType'     => 1,
            'isLead'         => $isLead,
            'firstName'      => $post['firstname'] ?? '',
            'lastName'       => $post['lastname'] ?? '',
            'street1'        => $post['address_1'] ?? '',
            'street2'        => $post['address_2'] ?? '',
            'city'           => ($post['customer_type'] ?? '') === 'Fiber' ? ($post['fiber_area'] ?? '') : '',
            'countryId'      => null,
            'organizationId' => 2,
            'stateId'        => null,
            'note'           => ($device['title'] ?? '') . ' / ' . ($offer['name'] ?? ''),
            'zipCode'        => '',
            'username'       => $username,
            'contacts'       => $this->buildContacts($post),
            'attributes'     => $this->buildAttributes($post, $retailer),
        ];

        // ── Retry loop: if CRM rejects username as taken, auto-increment and retry ──
        $maxRetries   = 5;
        $crmResponse  = null;
        $lastError    = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $crmPayload['username'] = $username;
            $crmResponse = $this->crm->post('clients', $crmPayload);

            if ($crmResponse && !empty($crmResponse['id'])) {
                break; // Success
            }

            $lastError = $this->crm->getLastError();
            $errJson   = json_encode($lastError);

            // Only retry on "username already taken" — any other error is fatal
            $isUsernameTaken = false;
            if (isset($lastError['http_code']) && (int)$lastError['http_code'] === 422) {
                $resp = $lastError['response'] ?? [];
                $errs = $resp['errors'] ?? [];
                if (isset($errs['username']) || (function_exists('str_contains')
                    ? str_contains($errJson, 'already taken')
                    : strpos($errJson, 'already taken') !== false)) {
                    $isUsernameTaken = true;
                }
            }

            if (!$isUsernameTaken || $attempt >= $maxRetries) {
                // Non-username error or exhausted retries — save locally, sync later
                $retryNote = $attempt > 1 ? " (tried {$attempt} usernames)" : '';
                error_log("[KycService] CRM client creation failed{$retryNote}: {$errJson} — saving locally for cron retry");
                $crmResponse = null;
                break;
            }

            // Increment username and retry
            error_log("[KycService] Username '{$username}' taken in CRM — attempt {$attempt}/{$maxRetries}, generating next");
            $username = $this->generateNextUsername($username);
        }

        $crmClientId = ($crmResponse && !empty($crmResponse['id'])) ? (string)$crmResponse['id'] : null;
        $crmFailed = ($crmClientId === null);
        // Use temp ID for local storage paths when CRM client hasn't been created yet
        $storageId = $crmClientId ?: ('PENDING_' . date('YmdHis') . '_' . ($retailer['id'] ?? 0));

        // ── Step 3b: Save images to structured storage, compress, generate thumbnails ──
        $proofKey = isset($files['id_document']) ? 'id_document'
                  : (isset($files['id_proof'])   ? 'id_proof'
                  : (isset($files['doctument'])  ? 'doctument'
                  : null));

        $savedPhoto = null; $savedProof = null; $savedKit = null;

        // ── Save customer photo ───────────────────────────────────────────
        if (!empty($files['customer_image']['tmp_name']) && is_uploaded_file($files['customer_image']['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $files['customer_image']['tmp_name']);
            finfo_close($finfo);
            $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','application/pdf'=>'pdf'];
            if (isset($extMap[$mime])) {
                $origSize = filesize($files['customer_image']['tmp_name']);
                $sp = $this->buildStoragePath($storageId, 'photo', $extMap[$mime]);
                if (move_uploaded_file($files['customer_image']['tmp_name'], $sp['path'])) {
                    $savedPhoto = $sp['path'];
                    $this->compressImageFile($savedPhoto);
                    if (!file_exists($savedPhoto)) $savedPhoto = preg_replace('/\.[a-z]+$/', '.jpg', $savedPhoto);
                    $imgInfo   = @getimagesize($savedPhoto) ?: [0, 0];
                    $thumbPath = $this->generateThumbnail($savedPhoto);
                    $this->saveImageMeta($sp['meta_path'], [
                        'doc_type'           => 'photo',
                        'crm_client_id'      => $crmClientId,
                        'original_size_kb'   => (int)round($origSize / 1024),
                        'compressed_size_kb' => (int)round(filesize($savedPhoto) / 1024),
                        'width_px'           => $imgInfo[0],
                        'height_px'          => $imgInfo[1],
                        'mime'               => mime_content_type($savedPhoto),
                        'thumb_path'         => $thumbPath,
                    ]);
                }
            }
        }

        // ── Save ID proof ─────────────────────────────────────────────────
        if ($proofKey && !empty($files[$proofKey]['tmp_name']) && is_uploaded_file($files[$proofKey]['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $files[$proofKey]['tmp_name']);
            finfo_close($finfo);
            $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','application/pdf'=>'pdf'];
            if (isset($extMap[$mime])) {
                $origSize = filesize($files[$proofKey]['tmp_name']);
                $sp = $this->buildStoragePath($storageId, 'id_proof', $extMap[$mime]);
                if (move_uploaded_file($files[$proofKey]['tmp_name'], $sp['path'])) {
                    $savedProof = $sp['path'];
                    if ($mime !== 'application/pdf') {
                        $this->compressImageFile($savedProof);
                        if (!file_exists($savedProof)) $savedProof = preg_replace('/\.[a-z]+$/', '.jpg', $savedProof);
                    }
                    $this->saveImageMeta($sp['meta_path'], [
                        'doc_type'           => 'id_proof',
                        'crm_client_id'      => $crmClientId,
                        'original_size_kb'   => (int)round($origSize / 1024),
                        'compressed_size_kb' => (int)round(filesize($savedProof) / 1024),
                        'mime'               => mime_content_type($savedProof),
                    ]);
                }
            }
        }

        // ── Save kit label image ──────────────────────────────────────────
        $savedKit = null;
        if (!empty($files['kit_image']['tmp_name']) && is_uploaded_file($files['kit_image']['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $files['kit_image']['tmp_name']);
            finfo_close($finfo);
            $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            if (isset($extMap[$mime])) {
                $origSize = filesize($files['kit_image']['tmp_name']);
                $sp = $this->buildStoragePath($storageId, 'kit_label', $extMap[$mime]);
                if (move_uploaded_file($files['kit_image']['tmp_name'], $sp['path'])) {
                    $savedKit = $sp['path'];
                    $this->compressImageFile($savedKit, 1600, 85); // higher quality for OCR readability
                    if (!file_exists($savedKit)) $savedKit = preg_replace('/\.[a-z]+$/', '.jpg', $savedKit);
                    $this->saveImageMeta($sp['meta_path'], [
                        'doc_type'           => 'kit_label',
                        'crm_client_id'      => $crmClientId,
                        'original_size_kb'   => (int)round($origSize / 1024),
                        'compressed_size_kb' => (int)round(filesize($savedKit) / 1024),
                        'mime'               => mime_content_type($savedKit),
                    ]);
                }
            }
        }

        // ── Step 4: Photos stored locally only (CRM upload disabled) ──────
        // Photos are saved in data/kyc_photos/{crm_client_id}/ with thumbnails
        // and metadata JSON. No upload to CRM — saves bandwidth and storage.
        $photoUploaded = false;
        $idUploaded    = false;
        $kitLabel      = 'Starlink Kit Label';
        $kitSn         = trim($post['kitName'] ?? '');
        if ($kitSn !== '') $kitLabel .= ' — ' . $kitSn;

        // Mark as "uploaded" (locally stored) so UI shows success
        if ($savedPhoto && file_exists($savedPhoto)) {
            $photoUploaded = true;
            $sp = $this->buildStoragePath($storageId, 'photo', 'jpg');
            if (file_exists($sp['meta_path'])) {
                $m = json_decode(file_get_contents($sp['meta_path']), true) ?? [];
                $m['stored_locally'] = true;
                $m['stored_at'] = date('Y-m-d H:i:s');
                file_put_contents($sp['meta_path'], json_encode($m, JSON_PRETTY_PRINT));
            }
        }

        // ── Step 5: ID proof stored locally ───────────────────────────────
        if ($savedProof && file_exists($savedProof)) {
            $idUploaded = true;
            $sp = $this->buildStoragePath($storageId, 'id', pathinfo($savedProof, PATHINFO_EXTENSION));
            if (file_exists($sp['meta_path'])) {
                $m = json_decode(file_get_contents($sp['meta_path']), true) ?? [];
                $m['stored_locally'] = true;
                $m['stored_at'] = date('Y-m-d H:i:s');
                file_put_contents($sp['meta_path'], json_encode($m, JSON_PRETTY_PRINT));
            }
        }

        // ── Step 5b: Kit label stored locally ─────────────────────────────
        $kitImageUploaded = false;
        if ($savedKit && file_exists($savedKit)) {
            $kitImageUploaded = true;
            $sp = $this->buildStoragePath($storageId, 'kit', pathinfo($savedKit, PATHINFO_EXTENSION));
            if (file_exists($sp['meta_path'])) {
                $m = json_decode(file_get_contents($sp['meta_path']), true) ?? [];
                $m['stored_locally'] = true;
                $m['stored_at'] = date('Y-m-d H:i:s');
                file_put_contents($sp['meta_path'], json_encode($m, JSON_PRETTY_PRINT));
            }
        }

        // ── No retry queue needed — photos stored locally ────────────────
        // (Retry queue disabled since photos are not uploaded to CRM)

        // ── Step 6: Generate Work Order document (template-based, no file) ─
        if ($crmClientId) {
        $tplId = ($connectivity === 'New Connection') ? self::TPL_WORK_ORDER_NEW : self::TPL_WORK_ORDER_OTHER;
        $this->crm->post('documents', [
            'clientId'   => (int)$crmClientId,
            'name'       => 'Work Order',
            'templateId' => $tplId,
        ]);
        }

        // ── Step 6.5: Create Quote/Proforma in UCRM ─────────────────────
        // Mirrors what POS apps do: auto-generate a quote immediately on
        // registration so customer receives a proforma via UCRM email.
        // Quote = package monthly fee + device/kit price (if any).
        // Controlled by kyc_config.json → kyc_auto_quote_enabled (default: true)
        $autoQuoteEnabled  = ($cfg['kyc_auto_quote_enabled'] ?? true) !== false;
        $quoteValidityDays = max(1, (int)($cfg['kyc_quote_validity_days'] ?? self::QUOTE_MATURITY_DAYS));
        // ── Build quote items with UCRM productId when available ──
        // If package/device has ucrm_product_id, link to UCRM product for inventory tracking
        $quoteItems = [];
        if ($offer) {
            $item = [
                'label'    => $offer['name'] ?? 'Service Package',
                'quantity' => 1,
                'price'    => (float)($offer['customer_price'] ?? $offer['amount'] ?? 0),
                'unit'     => 'month',
            ];
            // Link to UCRM product if mapped
            if (!empty($offer['ucrm_product_id'])) {
                $item['productId'] = (int)$offer['ucrm_product_id'];
            }
            $quoteItems[] = $item;
        }
        
        // Multi-item cart (hw_cart_json) takes priority over single device_id
        $hwCart = [];
        if (!empty($post['hw_cart_json'])) {
            $hwCart = json_decode($post['hw_cart_json'], true) ?? [];
        }
        if (!empty($hwCart)) {
            foreach ($hwCart as $hwItem) {
                $hwPrice = (float)preg_replace('/[^0-9.]/', '', (string)($hwItem['price'] ?? '0'));
                if ($hwPrice > 0) {
                    $item = [
                        'label'    => $hwItem['title'] ?? 'Hardware',
                        'quantity' => max(1, (int)($hwItem['qty'] ?? 1)),
                        'price'    => $hwPrice,
                        'unit'     => 'piece',
                    ];
                    // Link to UCRM product if mapped
                    if (!empty($hwItem['ucrm_product_id'])) {
                        $item['productId'] = (int)$hwItem['ucrm_product_id'];
                    }
                    $quoteItems[] = $item;
                }
            }
        } elseif (!empty($device) && !empty($device['price'])) {
            // Fallback: single device
            $devPrice = (float)preg_replace('/[^0-9.]/', '', $device['price'] ?? '0');
            if ($devPrice > 0) {
                $item = [
                    'label'    => $device['title'] ?? 'Hardware / Kit',
                    'quantity' => max(1, (int)($post['kitQty'] ?? 1)),
                    'price'    => $devPrice,
                    'unit'     => 'piece',
                ];
                // Link to UCRM product if mapped
                if (!empty($device['ucrm_product_id'])) {
                    $item['productId'] = (int)$device['ucrm_product_id'];
                }
                $quoteItems[] = $item;
            }
        }
        
        // Fiber: add installation fee as separate line
        // Try to use UCRM product ID if configured
        if (($post['customer_type'] ?? '') === 'Fiber') {
            $installFee     = (float)($cfg['fiber_install_fee'] ?? 100);
            $installProduct = (int)($cfg['fiber_install_product_id'] ?? 244); // Default UCRM product ID
            if ($installFee > 0) {
                $item = [
                    'label'    => 'Installation Fee',
                    'quantity' => 1,
                    'price'    => $installFee,
                    'unit'     => 'amount',
                ];
                if ($installProduct > 0) {
                    $item['productId'] = $installProduct;
                }
                $quoteItems[] = $item;
            }
        }
        $quoteCreated = false;
        $quoteId      = null;
        $qSeq         = null;
        $qRef         = null;
        $qPrefix      = '';
        $qSeqStart    = 0;
        $qNotesPrefix = '';
        // Max-amount guard: if cart total exceeds threshold, skip auto-quote
        // (agent will generate the quotation manually for non-standard hardware bundles)
        $maxAutoQuoteAmount = (float)($cfg['kyc_auto_quote_max_amount'] ?? 0);
        if ($autoQuoteEnabled && $maxAutoQuoteAmount > 0) {
            $cartTotal = array_sum(array_map(
                fn($item) => (float)($item['price'] ?? 0) * max(1, (int)($item['quantity'] ?? 1)),
                $quoteItems
            ));
            if ($cartTotal > $maxAutoQuoteAmount) {
                $autoQuoteEnabled = false; // suppress — too complex for auto-quote
                error_log("KycService: auto-quote suppressed for CRM #{$crmClientId} — cart \${$cartTotal} exceeds max \${$maxAutoQuoteAmount}");
            }
        }
        if ($autoQuoteEnabled && !empty($quoteItems)) {
            // ── B-03 FIX: quote_seq is now computed atomically inside saveApplication()'s
            // withLock. We only prepare the prefix/start/year here. The actual
            // $qSeq and $qRef are populated AFTER saveApplication() returns,
            // then we POST the quote to UCRM with the guaranteed-unique ref.
            $qPrefix      = trim($cfg['quote_prefix']     ?? 'QUO');
            $qSeqStart    = max(0, (int)($cfg['quote_seq_start'] ?? 0));
            $qNotesPrefix = trim($cfg['kyc_quote_notes_prefix'] ?? '');
        }

        // ── Step 7a: Auto-create payment in UCRM for Cash sales ─────────────
        // Agent already collected cash. Post immediately as account credit.
        // Invoice will be created later (after installation) — UCRM will
        // auto-apply this credit to it when the invoice is issued.
        $paymentCreated = false;
        $paymentId      = null;
        $autoPayEnabled = ($cfg['kyc_auto_payment_enabled'] ?? true) !== false;

        if ($autoPayEnabled && $isCash && $checkAmount > 0 && $crmClientId) {
            // Resolve to UCRM UUID — UCRM rejects slugs/integers with HTTP 422
            if (!class_exists('PaymentUuids')) {
                require_once __DIR__ . '/PaymentUuids.php';
            }
            $ucrm_method = PaymentUuids::resolve($post['sales_type'] ?? 'Cash');

            // Unique reference for duplicate detection
            $uniqueRef = 'KYC-' . $crmClientId;

            // Post as credit — no invoiceId needed. UCRM holds it as
            // account balance and auto-applies when invoice is created.
            $paymentPayload = [
                'clientId'     => (int)$crmClientId,
                'amount'       => (float)$checkAmount,
                'currencyCode' => 'USD',
                'methodId'     => $ucrm_method,
                'note'         => 'Cash collected at registration — DishNet Sales Hub'
                                . ' | Agent: ' . ($retailer['name'] ?? '')
                                . ' | ' . $connectivity
                                . ' | Ref: ' . $uniqueRef,
            ];
            
            // ── DUPLICATE PREVENTION: Check UCRM before creating ──
            $payResult = $this->crm->createPaymentSafe($paymentPayload, $uniqueRef);
            if (!empty($payResult['success']) && !empty($payResult['id'])) {
                $paymentId      = $payResult['id'];
                $paymentCreated = true;
                if (!empty($payResult['duplicate'])) {
                    error_log("[KycService] Payment already existed for KYC-{$crmClientId}, using existing ID: {$paymentId}");
                }
            }
        }

        // ── Step 7: Add CRM tag ───────────────────────────────────────────
        if ($crmClientId) {
        $tagMap = ['Ownership Change'=>self::TAG_OWNERSHIP_CHANGE,'Shifting Connection'=>self::TAG_SHIFTING];
        $tagId = $tagMap[$connectivity] ?? self::TAG_NEW_CONNECTION;
        $this->crm->patch("clients/{$crmClientId}/add-tag/{$tagId}");
        }

        // ── Step 7b: Persist new area if not in built-in list ───────────────
        if (($post['customer_type'] ?? '') === 'Fiber' && !empty($post['fiber_area'])) {
            $submittedArea = trim($post['fiber_area']);
            $builtInAreas  = \SplynxTicketService::getJubaAreas();
            if (!in_array($submittedArea, $builtInAreas, true)) {
                // New area — add to custom areas store if not already there
                $customAreas = $this->store->load('kyc_custom_areas.json') ?? [];
                $exists = array_search($submittedArea, array_column($customAreas, 'name'));
                if ($exists === false) {
                    $customAreas[] = [
                        'name'       => $submittedArea,
                        'added_by'   => $retailer['name'] ?? 'Unknown',
                        'added_at'   => date('Y-m-d H:i:s'),
                        'use_count'  => 1,
                    ];
                } else {
                    $customAreas[$exists]['use_count'] = ($customAreas[$exists]['use_count'] ?? 0) + 1;
                }
                $this->store->save('kyc_custom_areas.json', $customAreas);
            }
        }

        // ── Step 8: Save application locally ─────────────────────────────
        $appId = $this->saveApplication([
            'connectivity_type' => $connectivity,
            'customer_type'     => $post['customer_type'] ?? '',
            'date'              => $post['date'] ?? date('Y-m-d'),
            'crm_client_id'     => $crmClientId,   // null if CRM failed
            'crm_sync_status'   => $crmFailed ? 'pending' : 'synced',
            'crm_sync_payload'  => $crmFailed ? json_encode($crmPayload) : null,
            'firstname'         => $post['firstname'] ?? '',
            'lastname'          => $post['lastname'] ?? '',
            'mobile'            => $post['mobile'] ?? '',
            'email'             => $post['email'] ?? '',
            'contacts'          => $this->buildContacts($post),
            'address_1'         => $post['address_1'] ?? '',
            'address_2'         => $post['address_2'] ?? '',
            'device_id'         => $post['device_id'] ?? '',
            'device_title'      => $device['title'] ?? null,
            'device_price'      => $device['price'] ?? null,
            'package_choice'    => $post['package_choice'] ?? '',
            'offer_name'        => $offer['name'] ?? null,
            'offer_price'       => $offer['customer_price'] ?? $offer['amount'] ?? null,
            'kitName'           => $post['kitName'] ?? '',
            'kitQty'            => $post['kitQty'] ?? '1',
            'kitUnit'           => $post['kitUnit'] ?? '',
            'kitNumber'         => $post['kitNumber'] ?? '',
            'accessories'       => json_encode($post['accessories'] ?? []),
            'priority'          => $post['priority'] ?? 'Low',
            'sales_person'      => $salesPerson,
            'sales_type'        => $post['sales_type'] ?? 'Cash',
            'ref'               => $post['ref'] ?? '',
            'latitude'          => $post['latitude'] ?? '',
            'longitude'         => $post['longitude'] ?? '',
            'fiber_area'        => ($post['customer_type'] ?? '') === 'Fiber' ? ($post['fiber_area'] ?? '') : '',
            'username'          => $username,
            'retailer_id'       => $retailer['id'],
            'retailer_name'     => $retailer['name'],
            'status'            => 'new',
            'amount_charged'    => $isCash ? $checkAmount : 0,
            'is_lead'           => $isLead,
            'crm_client_type'   => $isLead ? 'lead' : 'regular',
            'hw_cart_json'      => $post['hw_cart_json'] ?? null,
            // B-03 FIX: quote_seq/quote_ref computed atomically inside saveApplication().
            // Pass sentinel fields so the withLock callback can assign them race-free.
            '_quote_prefix'     => ($autoQuoteEnabled && !empty($quoteItems)) ? $qPrefix : null,
            '_quote_seq_start'  => ($autoQuoteEnabled && !empty($quoteItems)) ? $qSeqStart : null,
            '_quote_year'       => date('Y'),
            'quote_id'          => null,         // filled after quote is POSTed below
            'quote_seq'         => null,         // filled by saveApplication() lock
            'quote_ref'         => null,         // filled by saveApplication() lock
            'quote_created'     => false,        // updated below after UCRM POST
            'payment_id'        => $paymentId,
            'payment_created'   => $paymentCreated,
            'photo_uploaded'    => $photoUploaded,
            'id_uploaded'       => $idUploaded,
            'kit_image_uploaded'=> $kitImageUploaded,
        ]);

        // ── Step 8b: Now that appId + atomic quote_seq are assigned, POST quote to UCRM ──
        if ($autoQuoteEnabled && !empty($quoteItems) && $crmClientId) {
            error_log("[KycService] Auto-quote ENABLED for CRM #{$crmClientId} — " . count($quoteItems) . " items, total: $" . array_sum(array_map(fn($i) => (float)($i['price'] ?? 0) * (int)($i['quantity'] ?? 1), $quoteItems)));
            $savedApp = $this->store->findOne('kyc_applications.json', 'id', $appId);
            $qRef     = $savedApp['quote_ref'] ?? '';

            // v4.9.18 FIX #3: gate on qRef instead of qSeq (quote_seq is always 0 now)
            if ($qRef !== '' && $qRef !== null) {

                // Try the main plugin CRM client first (works on UCRM 4.x+)
                // Falls back to dedicated admin token if configured
                $_quoteCfg   = $this->store->load('kyc_config.json') ?? [];
                $_quoteToken = trim($_quoteCfg['crm_auth_token'] ?? '');
                $_quoteUrl   = trim($_quoteCfg['crm_base_url'] ?? '');

                $quoteCrm = ($_quoteToken !== '')
                    ? new CrmApiClient(
                        $_quoteUrl !== '' ? $_quoteUrl : rtrim($this->crm->getBaseUrl(), '/'),
                        $_quoteToken,
                        'x-auth-token'
                      )
                    : $this->crm;  // No admin token → plugin key (quotes will fail)

                    $autoNotes    = $qRef . ' | Auto-generated on KYC registration. Sales: ' . $salesPerson
                                   . ' | Connection: ' . $connectivity
                                   . ' | Priority: ' . ($post['priority'] ?? 'Medium');
                    $quotePayload = [
                        // v4.11.3 FIX: removed 'maturityDays' — UISP 4.5.33 rejects it with 422
                        'notes'               => ($qNotesPrefix ? $qNotesPrefix . "\n" : '') . $autoNotes,
                        'adminNotes'          => 'Plugin ref: ' . $qRef,
                        'items'               => $quoteItems,
                    ];
                    // Use client-specific endpoint: POST /clients/{id}/quotes
                    error_log("[KycService] Attempting quote POST for CRM #{$crmClientId} with " . count($quoteItems) . " items, total: $" . array_sum(array_map(fn($i) => (float)($i['price'] ?? 0) * (int)($i['quantity'] ?? 1), $quoteItems)));
                    $quoteResponse = $quoteCrm->post("clients/{$crmClientId}/quotes", $quotePayload);
                    error_log("[KycService] Quote POST response: " . json_encode($quoteResponse));
                    if (!empty($quoteResponse['id'])) {
                        $quoteId      = $quoteResponse['id'];
                        $quoteCreated = true;

                        // ── Use UCRM's own quote number as the reference ──────────
                        // UCRM auto-increments its own sequence (e.g. PF003847).
                        // If not in POST response, fetch the quote to get the number.
                        $ucrmQuoteNumber = $quoteResponse['number'] ?? null;
                        if (!$ucrmQuoteNumber) {
                            $fetchedQuote    = $quoteCrm->get("billing/quotes/{$quoteId}");
                            $ucrmQuoteNumber = $fetchedQuote['number'] ?? null;
                        }
                        if ($ucrmQuoteNumber) {
                            $qRef = $ucrmQuoteNumber;
                            $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                                'quote_ref' => $ucrmQuoteNumber,
                            ]);
                        }

                        $quoteCrm->patch("billing/quotes/{$quoteId}/send");
                        // Write the UCRM quote_id back to the local record
                        $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                            'quote_id'      => $quoteId,
                            'quote_created' => true,
                        ]);

                        // ── Defer WA to cron_quote_wa — it will fetch PDF + send text + PDF ──
                        // Store the phone directly so cron doesn't need another UCRM API call.
                        // Cron waits 3 min to ensure UCRM has generated the PDF before fetching.
                        $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                            'wa_quote_pending'     => true,
                            'wa_quote_phone'       => preg_replace('/[^0-9+]/', '', $post['mobile'] ?? ''),
                            'wa_quote_deferred_at' => date('Y-m-d H:i:s'),
                        ]);
                        error_log("[KycService] Quote #{$quoteId} ({$qRef}) deferred to cron_quote_wa with PDF");
                    } else {
                        // Quote POST failed - log for debugging
                        $quoteError = $quoteCrm->getLastError();
                        error_log("[KycService] Quote POST failed for CRM #{$crmClientId}: " . json_encode($quoteError));
                        // Save failure info to application
                        $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                            'quote_error' => json_encode($quoteError),
                        ]);
                    }
            } else {
                error_log("[KycService] Quote BLOCKED — quote_ref is empty for app #{$appId}. savedApp keys: " . implode(',', array_keys($savedApp ?? [])));
                $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                    'quote_debug' => 'quote_ref was empty — saveApplication may not have set _quote_prefix. qRef="' . ($qRef ?? 'NULL') . '"',
                ]);
            }
        } else {
            error_log("[KycService] Auto-quote SKIPPED for CRM #{$crmClientId} — enabled=" . ($autoQuoteEnabled ? 'yes' : 'no') . " items=" . count($quoteItems));
            // Save debug info to help troubleshoot
            $this->store->updateOne('kyc_applications.json', 'id', $appId, [
                'quote_debug' => 'Quote skipped: enabled=' . ($autoQuoteEnabled ? 'yes' : 'no')
                              . ', items=' . count($quoteItems)
                              . ', crmId=' . ($crmClientId ?: 'NONE')
                              . ', offer=' . ($offer ? ($offer['name'] ?? 'unnamed') . ' $' . ($offer['customer_price'] ?? '0') : 'NULL'),
            ]);
        }

        // ── Step 9: Wallet debit + passbook ─────────────────────────────
        // v4.11.3: Wallet debited regardless of CRM outcome — staff collected real money.
        if ($isCash && $checkAmount > 0) {
            $_debitLabel = $crmClientId
                ? "KYC – CRM client #{$crmClientId} ({$post['firstname']} {$post['lastname']})"
                : "KYC – {$post['firstname']} {$post['lastname']} (CRM pending)";
            $_debitRef = $crmClientId ? "KYC-{$crmClientId}" : "KYC-APP-{$appId}";
            $this->wallet->debit(
                $retailer['id'],
                $checkAmount,
                $_debitLabel,
                $_debitRef,
                $appId,
                $crmClientId ?: ''
            );

            // ── Step 9a: Write to payment_collections so Collections tab shows it ─
            $customerFullName = trim(($post['firstname'] ?? '') . ' ' . ($post['lastname'] ?? ''));
            $svcType = strtolower($post['customer_type'] ?? $post['connectivity_type'] ?? 'starlink');
            $_kycColRecord = $this->store->appendWithId('payment_collections.json', [
                'retailer_id'       => $retailer['id'],
                'retailer_name'     => $retailer['name'] ?? '',
                'customer_name'     => $customerFullName,
                'crm_customer_id'   => (string)($crmClientId ?: ''),
                'amount'            => $checkAmount,
                'method'            => 'Cash',
                'service_type'      => $svcType,
                'note'              => 'KYC Registration — ' . ($post['connectivity_type'] ?? 'New Connection'),
                'source'            => 'kyc_cash_sale',
                'kyc_app_id'        => $appId,
                'crm_payment_id'    => $paymentId ?: null,
                'crm_synced'        => $paymentCreated,
                'commission'        => 0,
                'comm_rate'         => 0,
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
            // Dual-write: staff_ledger
            require_once dirname(__FILE__) . '/StaffLedgerWriter.php';
            StaffLedgerWriter::onCollection($this->store->getPdo(), array_merge($_kycColRecord, [
                'client_name' => $customerFullName,
                'collected_at' => date('Y-m-d H:i:s'),
            ]));

            // ── Step 9b: Cashbook entry for STAFF members ────────────────────
            // Staff (company employees) collect cash that must be handed over.
            // Independent retailers (dealers) use their own wallet funds.
            if ($this->isStaffRetailer($retailer)) {
                $this->createCashbookEntry($retailer, $checkAmount, $crmClientId ?: $appId, $post);
            }
        }

        if ($crmFailed) {
            $successMsg = "Customer saved! CRM sync pending — will be created automatically. Username: {$username}.";
        } else {
            $successMsg = "Customer registered! CRM ID: {$crmClientId}. Username: {$username}.";
            if ($quoteCreated)   $successMsg .= " Quote #{$quoteId} created.";
            if ($paymentCreated) $successMsg .= " Payment #{$paymentId} posted to UCRM.";
            elseif ($isCash && $checkAmount > 0) $successMsg .= " (Payment could not be auto-posted — add manually in UCRM.)";
        }

        // Photo upload status — agent must know if they need to re-upload manually
        $hadPhoto = !empty($files['customer_image']['tmp_name']) || $savedPhoto;
        $hadId    = !empty($files[$proofKey ?? '']['tmp_name'] ?? '') || $savedProof;
        $hadKit   = $savedKit !== null || !empty($files['kit_image']['tmp_name']);
        if ($hadPhoto && !$photoUploaded) $successMsg .= " ⚠ Customer photo upload failed — please add manually in UCRM Files tab.";
        if ($hadId    && !$idUploaded)   $successMsg .= " ⚠ ID proof upload failed — please add manually in UCRM Files tab.";
        if ($hadKit   && !$kitImageUploaded) $successMsg .= " ⚠ Kit label photo upload failed — saved locally, will retry.";

        return [
            'success' => true,
            'message' => $successMsg,
            'data'    => [
                'crm_client_id'      => $crmClientId,
                'crm_sync_status'    => $crmFailed ? 'pending' : 'synced',
                'application_id'     => $appId,
                'username'           => $username,
                'amount_charged'     => $isCash ? $checkAmount : 0,
                'status'             => 'new',
                'quote_id'           => $quoteId,
                'quote_created'      => $quoteCreated,
                'payment_id'         => $paymentId,
                'payment_created'    => $paymentCreated,
                'photo_uploaded'     => $photoUploaded,
                'id_uploaded'        => $idUploaded,
                'kit_image_uploaded' => $kitImageUploaded,
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    // ── REQ-02: PHP GD backend compression (safety net) ──────────────────
    /**
     * Compress an image file in-place using PHP GD.
     * Scales to $maxPx on longest side, saves as JPEG at $quality.
     * PDFs and unsupported types are skipped (returns false).
     */
    private function compressImageFile(string $filePath, int $maxPx = 1280, int $quality = 82): bool
    {
        if (!function_exists('imagecreatefromjpeg')) return false;
        $mime = mime_content_type($filePath);
        $creators = [
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png'  => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            'image/gif'  => 'imagecreatefromgif',
        ];
        if (!isset($creators[$mime])) return false;
        $src = $creators[$mime]($filePath);
        if (!$src) return false;
        $origW = imagesx($src); $origH = imagesy($src);
        if ($origW <= $maxPx && $origH <= $maxPx) {
            $newW = $origW; $newH = $origH;
        } elseif ($origW >= $origH) {
            $newW = $maxPx; $newH = (int)round($origH * $maxPx / $origW);
        } else {
            $newH = $maxPx; $newW = (int)round($origW * $maxPx / $origH);
        }
        $dst = imagecreatetruecolor($newW, $newH);
        if ($mime === 'image/png') {
            imagealphablending($dst, false); imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($src);
        $newPath = preg_replace('/\.[a-zA-Z0-9]+$/', '.jpg', $filePath);
        $ok = imagejpeg($dst, $newPath, $quality);
        imagedestroy($dst);
        if ($ok && $newPath !== $filePath) @unlink($filePath);
        return $ok;
    }

    // ── REQ-07: Admin review thumbnail ───────────────────────────────────
    /**
     * Generate a 120px thumbnail saved as {filename}_thumb.jpg.
     * Kept permanently even after CRM upload for admin review.
     */
    private function generateThumbnail(string $filePath, int $thumbPx = 120): ?string
    {
        if (!function_exists('imagecreatefromjpeg')) return null;
        $mime = mime_content_type($filePath);
        $creators = [
            'image/jpeg'=>'imagecreatefromjpeg','image/png'=>'imagecreatefrompng',
            'image/webp'=>'imagecreatefromwebp','image/gif'=>'imagecreatefromgif',
        ];
        if (!isset($creators[$mime])) return null;
        $src = $creators[$mime]($filePath);
        if (!$src) return null;
        $w = imagesx($src); $h = imagesy($src);
        $scale = min($thumbPx / $w, $thumbPx / $h);
        $tw = (int)round($w * $scale); $th = (int)round($h * $scale);
        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($src);
        $thumbPath = preg_replace('/\.[a-zA-Z]+$/', '', $filePath) . '_thumb.jpg';
        imagejpeg($dst, $thumbPath, 75);
        imagedestroy($dst);
        return $thumbPath;
    }

    // ── REQ-03: Structured storage paths ─────────────────────────────────
    /**
     * Build structured path: data/kyc_uploads/YYYY-MM/crm-{id}/{docType}.{ext}
     * Returns ['dir', 'path', 'meta_path'].
     */
    private function buildStoragePath(string $crmClientId, string $docType, string $ext): array
    {
        $base  = rtrim($this->dataDir, '/') . '/kyc_uploads';
        $month = date('Y-m');
        $dir   = "{$base}/{$month}/crm-{$crmClientId}";
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $names     = ['photo' => 'photo', 'id_proof' => 'id_proof', 'kit_label' => 'kit_label'];
        $baseName  = $names[$docType] ?? $docType;
        $path      = "{$dir}/{$baseName}.{$ext}";
        $metaPath  = "{$dir}/{$baseName}_meta.json";
        return ['dir' => $dir, 'path' => $path, 'meta_path' => $metaPath];
    }

    // ── REQ-04: Metadata sidecar ──────────────────────────────────────────
    /**
     * Write JSON sidecar with image metadata alongside the file.
     */
    private function saveImageMeta(string $metaPath, array $data): void
    {
        $meta = array_merge([
            'saved_at'           => date('Y-m-d H:i:s'),
            'original_size_kb'   => 0,
            'compressed_size_kb' => 0,
            'width_px'           => 0,
            'height_px'          => 0,
            'mime'               => '',
            'crm_uploaded'       => false,
            'crm_uploaded_at'    => null,
            'retry_count'        => 0,
        ], $data);
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT));
    }

    private function resolveAmount(array $post): array
    {
        $customerType = $post['customer_type'] ?? '';
        $packageId    = (int)($post['package_choice'] ?? 0);

        // Look up plan — check subscription_plans.json first (new wizard form),
        // fall back to kyc_packages.json (old form / legacy submissions).
        $offer = $this->store->findOne('subscription_plans.json', 'id', $packageId);
        if (!$offer) {
            $offer = $this->store->findOne('kyc_packages.json', 'id', $packageId);
        }

        $device = $this->store->findOne('kyc_devices.json', 'id', (int)($post['device_id'] ?? 0));

        if (!$offer) return [null, null, null];

        if ($customerType === 'Fiber') {
            // HIGH-03 FIX: Installation fee is now configurable in Settings (fiber_install_fee).
            // Defaults to $100 if not set, so existing behaviour is preserved.
            // This avoids a code deploy every time the fee changes.
            $config      = $this->store->load('kyc_config.json');
            $installFee  = (float)($config['fiber_install_fee'] ?? 100);
            // v4.11.3 FIX: Include device/router price in cash collection amount
            // Previously: only offer_price + installFee ($150), missing router ($50 = $200 total)
            $devicePrice = (float)preg_replace('/[^0-9.]/', '', $device['price'] ?? '0');
            $amount      = (float)($offer['customer_price'] ?? $offer['amount'] ?? 50) + $installFee + $devicePrice;
            return [$amount, $offer, $device ?: []];
        }

        // StarLink: package amount + device price
        $devicePrice = (float)preg_replace('/[^0-9.]/', '', $device['price'] ?? '0');
        $amount      = (float)($offer['customer_price'] ?? $offer['amount'] ?? 0) + $devicePrice;

        return [$amount, $offer, $device ?: []];
    }

    /**
     * Build UCRM contacts array from form POST data.
     * Supports multi-contact form (contacts[0][name], contacts[0][phone], etc.)
     * Falls back to legacy single email/mobile fields for backward compatibility.
     *
     * @return array  Array of ['name'=>..., 'email'=>..., 'phone'=>...] objects
     */
    private function buildContacts(array $post): array
    {
        $contacts = [];

        // New multi-contact form: contacts[0][name], contacts[0][phone], contacts[0][email]
        if (!empty($post['contacts']) && is_array($post['contacts'])) {
            foreach ($post['contacts'] as $c) {
                if (!is_array($c)) continue;
                $name  = trim($c['name']  ?? '');
                $phone = trim($c['phone'] ?? '');
                $email = trim($c['email'] ?? '');
                // Skip completely empty rows
                if ($name === '' && $phone === '' && $email === '') continue;
                // If name empty, fall back to first name from main form
                if ($name === '') $name = trim($post['firstname'] ?? '');
                $contacts[] = [
                    'name'  => $name,
                    'email' => $email,
                    'phone' => $phone,
                ];
            }
        }

        // Fallback: legacy single email/mobile fields (backward compat)
        if (empty($contacts)) {
            $contacts[] = [
                'name'  => trim($post['firstname'] ?? ''),
                'email' => trim($post['email'] ?? ''),
                'phone' => trim($post['mobile'] ?? ''),
            ];
        }

        return $contacts;
    }

    private function buildAttributes(array $post, array $retailer = []): array
    {
        $salesPerson = $this->resolveSalesPerson($post, $retailer);
        return [
            ['value' => $post['priority'] ?? '',       'customAttributeId' => self::ATTR_PRIORITY],
            ['value' => $salesPerson,                  'customAttributeId' => self::ATTR_SALES_PERSON],
            ['value' => $post['ref'] ?? '',            'customAttributeId' => self::ATTR_REF],
            ['value' => $post['device_id'] ?? '',      'customAttributeId' => self::ATTR_DEVICE_ID],
            ['value' => $post['package_choice'] ?? '', 'customAttributeId' => self::ATTR_PACKAGE],
            ['value' => $post['kitNumber'] ?? '',      'customAttributeId' => self::ATTR_KIT_NUMBER],
            ['value' => $post['kitQty'] ?? '',         'customAttributeId' => self::ATTR_KIT_QTY],
            ['value' => $post['kitUnit'] ?? '',        'customAttributeId' => self::ATTR_KIT_UNIT],
            ['value' => $post['kitName'] ?? '',        'customAttributeId' => self::ATTR_KIT_NAME],
        ];
    }

    private function resolveSalesPerson(array $post, array $retailer = []): string
    {
        $sp = trim($post['sales_person'] ?? '');
        if ($sp === 'Other') $sp = trim($post['sales_person_other'] ?? '');
        // If still empty (agent didn't manually select / FTTH form), use the
        // logged-in retailer's name — this ensures Sales Person in UCRM always
        // matches the agent who submitted the KYC, not the form dropdown value.
        if ($sp === '' && !empty($retailer['name'])) {
            $sp = trim($retailer['name']);
        }
        return $sp;
    }

    // ──────────────────────────────────────────────────────────────────────
    // CRM USERNAME MANAGEMENT
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Check if a given CRM username is not yet used in local application records.
     * (CRM-side uniqueness is enforced by the CRM itself; this is a local pre-check.)
     */
    public function isCrmUsernameUnique(string $username): bool
    {
        // I-03 FIX: Use findOne() (O(log n) indexed lookup) instead of
        // loading all applications and doing a PHP foreach scan.
        // findOne does a case-sensitive match; we normalise to lowercase first.
        $found = $this->store->findOne('kyc_applications.json', 'username', strtolower($username));
        if ($found !== null) return false;
        // Also check original-case in case old records were stored mixed-case
        $found2 = $this->store->findOne('kyc_applications.json', 'username', $username);
        return $found2 === null;
    }

    /**
     * Update CRM username for an existing (not yet provisioned) application.
     * Returns ['success'=>bool, 'message'=>string].
     */
    public function updateCrmUsername(int $appId, string $newUsername, array $admin): array
    {
        $apps  = $this->store->load('kyc_applications.json');
        $found = null;
        foreach ($apps as $a) {
            if ((int)($a['id'] ?? 0) === $appId) { $found = $a; break; }
        }

        if (!$found) {
            return ['success' => false, 'message' => 'Application not found.'];
        }
        if (($found['status'] ?? '') === 'updated') {
            return ['success' => false, 'message' => 'Cannot change username after CRM provisioning.'];
        }

        $newUsername = trim($newUsername);
        if (!preg_match('/^[A-Za-z0-9_\-]{3,50}$/', $newUsername)) {
            return ['success' => false, 'message' => 'Invalid username format.'];
        }

        // Uniqueness check (exclude current app)
        foreach ($apps as $a) {
            if ((int)($a['id'] ?? 0) !== $appId && strcasecmp(trim($a['username'] ?? ''), $newUsername) === 0) {
                return ['success' => false, 'message' => "Username \"{$newUsername}\" is already in use."];
            }
        }

        $oldUsername = $found['username'] ?? '';
        $this->store->updateOne('kyc_applications.json', 'id', $appId, [
            'username'    => $newUsername,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // Log the change
        $logEntry = [
            'id'         => $this->store->nextId('activity_log.json'),
            'event'      => 'crm_username_changed',
            'actor'      => $admin['name'],
            'detail'     => "App #{$appId}: CRM username changed from \"{$oldUsername}\" to \"{$newUsername}\"",
            'ref_id'     => $appId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->store->append('activity_log.json', $logEntry);

        return ['success' => true, 'message' => "CRM username updated to \"{$newUsername}\" successfully."];
    }

    private function generateUsername(string $customerType): string
    {
        $apps     = $this->store->load('kyc_applications.json');
        $isFiber  = ($customerType === 'Fiber');
        $filtered = array_filter($apps, fn($a) => $isFiber
            ? (($a['customer_type'] ?? '') === 'Fiber')
            : (($a['customer_type'] ?? '') !== 'Fiber')
        );
        $maxSeq = empty($filtered) ? 0 : max(array_map(fn($a) => (int)($a['username_seq'] ?? 0), $filtered));

        // Respect configurable start sequence so new installs continue from the
        // old system's last number (e.g. STAR000051 → next = 52, FTTH000237 → next = 238)
        $cfg      = $this->store->load('kyc_config.json') ?? [];
        $seqStart = $isFiber
            ? max(0, (int)($cfg['ftth_seq_start'] ?? 0))
            : max(0, (int)($cfg['star_seq_start'] ?? 0));

        $seq    = max($maxSeq, $seqStart) + 1;
        $prefix = $isFiber ? 'FTTH' : 'STAR';
        return $prefix . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Increment a username like STAR000052 → STAR000053 or FTTH000237 → FTTH000238.
     * Used when CRM rejects a username as "already taken" (created directly in CRM,
     * not tracked in our local kyc_applications.json sequence).
     */
    private function generateNextUsername(string $current): string
    {
        if (preg_match('/^([A-Za-z]+)(\d+)$/', $current, $m)) {
            $prefix = $m[1];
            $seq    = (int)$m[2] + 1;
            $padLen = max(6, strlen($m[2]));
            return $prefix . str_pad((string)$seq, $padLen, '0', STR_PAD_LEFT);
        }
        // Manual username or unexpected format — append _2, _3, etc.
        if (preg_match('/_(\d+)$/', $current, $m)) {
            $base = substr($current, 0, -strlen($m[0]));
            return $base . '_' . ((int)$m[1] + 1);
        }
        return $current . '_2';
    }

    /**
     * Upload a file from $_FILES to UCRM under the correct client.
     *
     * UPLOAD FIX (v3.6.1):
     *   - clientId now passed as URL path param (/clients/{id}/documents) ← was wrong endpoint
     *   - document name uses safe human-readable name ← was showing PHP tmpfile basename
     *   - upload result logged to application record for visibility
     *   - errors written to error_log so admin can diagnose
     *
     * B-06 FIX: MIME type validated against allowlist using finfo (server-side),
     * NOT the browser-supplied filename or Content-Type header.
     * Only jpeg/png/gif/webp/pdf accepted — anything else is silently dropped.
     */
    private function uploadFileToCrm(string $crmClientId, array $file, string $docName = 'document'): bool
    {
        $allowedMimes = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/gif'       => 'gif',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
        ];

        $tmpPath = $file['tmp_name'] ?? '';
        if ($tmpPath === '' || !file_exists($tmpPath)) {
            error_log("uploadFileToCrm: tmp_name missing or file gone for [{$file['name']}] — client [{$crmClientId}]");
            return false;
        }

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if (!isset($allowedMimes[$realMime])) {
            error_log("uploadFileToCrm: rejected MIME [{$realMime}] for [{$file['name']}] — client [{$crmClientId}]");
            return false;
        }

        // Build safe, human-readable filename with correct extension
        $safeExt  = $allowedMimes[$realMime];
        $safeName = pathinfo($docName, PATHINFO_FILENAME) . '.' . $safeExt;
        // e.g. "Customer Photo.jpg", "ID Proof.pdf"

        $result = $this->crm->upload($tmpPath, ['name' => $safeName], $crmClientId);

        if ($result === null) {
            $err = $this->crm->getLastError();
            error_log("uploadFileToCrm: FAILED [{$safeName}] on client [{$crmClientId}] — " . json_encode($err));
            return false;
        }

        return true;
    }

    private function saveApplication(array $data): int
    {
        // BUG FIX: Use appendWithId() for atomic ID generation under a single
        // exclusive lock, preventing race conditions when concurrent requests
        // both read the same max(id) and produce duplicate IDs.
        // username_seq is computed inside the lock in appendWithId's callback.
        $isFiber = ($data['customer_type'] ?? '') === 'Fiber';

        $record = array_merge($data, [
            'submitted_at'   => date('Y-m-d H:i:s'),
            // MED-02: submission_key is set by caller (from form UUID); stored for dedup
            'submission_key' => $data['submission_key'] ?? null,
        ]);

        // appendWithId acquires LOCK_EX, assigns 'id', appends atomically.
        // We compute username_seq inside a withLock callback so seq is also
        // assigned atomically with the same lock cycle.
        $savedId       = null;
        $savedQuoteSeq = null;
        $this->store->withLock('kyc_applications.json', function (array $apps) use (&$record, &$savedId, &$savedQuoteSeq, $isFiber): array {
            $id = empty($apps) ? 1 : max(array_map(fn($a) => (int)($a['id'] ?? 0), $apps)) + 1;

            $filtered = array_filter($apps, fn($a) => $isFiber
                ? (($a['customer_type'] ?? '') === 'Fiber')
                : (($a['customer_type'] ?? '') !== 'Fiber')
            );
            $maxSeq = empty($filtered) ? 0 : max(array_map(fn($a) => (int)($a['username_seq'] ?? 0), $filtered));

            // Respect seq_start offset (same logic as generateUsername)
            $cfgLk      = $this->store->load('kyc_config.json') ?? [];
            $seqStartLk = $isFiber
                ? max(0, (int)($cfgLk['ftth_seq_start'] ?? 0))
                : max(0, (int)($cfgLk['star_seq_start'] ?? 0));

            $record['id']           = $id;
            $record['username_seq'] = max($maxSeq, $seqStartLk) + 1;

            // Quote ref is now assigned by UCRM after the POST (Option A).
            // We store a temporary placeholder here; KycService::process() overwrites
            // it with the real UCRM number (e.g. PF003847) after the quote is created.
            // This ensures WA and UCRM email always show the same number.
            if (!empty($record['_quote_prefix'])) {
                $record['quote_ref'] = 'PENDING';   // overwritten after UCRM POST
                $record['quote_seq'] = 0;            // not used anymore
                unset($record['_quote_prefix'], $record['_quote_seq_start'], $record['_quote_year']);
            }

            $apps[]  = $record;
            $savedId = $id;
            // withLock requires ['records' => $array, 'result' => $returnValue]
            return ['records' => $apps, 'result' => $id];
        });

        return (int)$savedId;
    }

    // ── Getters for listing ───────────────────────────────────────────────

    /** Get applications for one retailer (or all if admin) */
    public function getApplications(int $retailerId, bool $canViewAll = false): array
    {
        $all = $this->store->load('kyc_applications.json');
        if ($canViewAll) return array_reverse($all);
        $filtered = array_filter($all, fn($a) => (int)($a['retailer_id'] ?? 0) === $retailerId);
        return array_reverse(array_values($filtered));
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // STAFF / CASHBOOK HELPERS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Check if retailer is a company staff member (creates cashbook entries)
     * 
     * Staff = company employees who collect cash that must be handed over.
     * Non-staff = independent retailers/dealers who use their own funds.
     */
    private function isStaffRetailer(array $retailer): bool
    {
        // Check RBAC role_id first (new system)
        $roleId = $retailer['role_id'] ?? null;
        if ($roleId) {
            try {
                $pdo = $this->store->getPdo();
                $stmt = $pdo->prepare("SELECT is_staff FROM roles WHERE id = ?");
                $stmt->execute([$roleId]);
                $isStaff = $stmt->fetchColumn();
                if ($isStaff !== false) {
                    return (bool)$isStaff;
                }
            } catch (Throwable $e) {
                // Fall through to legacy check
            }
        }
        
        // Legacy: check role slug against known staff roles
        $role = $retailer['role'] ?? 'sales';
        $staffRoles = ['admin', 'support_leader', 'support', 'accountant', 'sales_staff', 'field_agent', 'field_accountant', 'collection', 'employee'];
        return in_array($role, $staffRoles, true);
    }
    
    /**
     * Create cashbook (cb_ledger) entry for cash collected by staff
     */
    private function createCashbookEntry(array $retailer, float $amount, $crmClientId, array $post): void
    {
        try {
            $pdo = $this->store->getPdo();

            // Ensure cb_ledger table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS cb_ledger (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project TEXT DEFAULT 'dishnet',
                date TEXT, direction TEXT, amount REAL, currency TEXT DEFAULT 'USD',
                category TEXT, category_raw TEXT, person TEXT, description TEXT,
                validation_ref TEXT, validation_status TEXT DEFAULT 'pending',
                status TEXT DEFAULT 'approved', approved_by TEXT,
                crm_client_id TEXT, source TEXT, created_at TEXT
            )");

            $customerName = trim(($post['firstname'] ?? '') . ' ' . ($post['lastname'] ?? ''));
            $serviceType  = $post['customer_type'] ?? $post['service_type'] ?? 'Starlink';
            $staffName    = $retailer['name'] ?? 'Unknown';

            $stmt = $pdo->prepare(
                "INSERT INTO cb_ledger (project, date, direction, amount, currency, category, category_raw,
                    person, description, validation_ref, validation_status, status, approved_by,
                    crm_client_id, source, created_at)
                 VALUES (?, ?, 'in', ?, 'USD', 'Receipt', 'KYC Cash Sale',
                    ?, ?, ?, 'pending', 'approved', 'Auto-KYC',
                    ?, 'kyc_cash_sale', ?)"
            );
            $stmt->execute([
                'dishnet',
                date('Y-m-d'),
                $amount,
                $staffName,
                "Cash collected for {$serviceType} registration: {$customerName}",
                "CRM#{$crmClientId}",
                (string)$crmClientId,
                date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Log but don't fail the KYC if cashbook fails
            error_log("KycService: Failed to create cashbook entry: " . $e->getMessage());
        }
    }

    /**
     * Create a UCRM scheduling job for an ADDITIONAL SERVICE at a NEW SITE
     * for an existing customer. Auto-assigned to Bidal (support_leader).
     *
     * Title pattern is intentionally descriptive so Bidal sees at a glance:
     *   "Additional Service — LATJOR KANG (1227) — New Site: Jabel"
     *
     * NEVER throws. Returns the new job ID on success, or 0 on any failure
     * (logged via error_log). Failure here MUST NOT block KYC submission —
     * the quote is the legal commitment; the job is operational scaffolding.
     *
     * @return int  New CRM scheduling job ID, or 0 on failure.
     */
    private function createCrmJobForBidal(
        string $customerId,
        array $post,
        string $newAddress,
        string $quoteRef,
        int $appId
    ): int {
        try {
            // ── Resolve Bidal's UCRM user ID ──────────────────────────────
            $cfg         = $this->store->load('kyc_config.json') ?: [];
            $retailers   = $this->store->load('retailers.json') ?? [];
            $bidalUcrmId = (int)($cfg['bidal_ucrm_user_id'] ?? 0);

            // Prefer role=support_leader retailer with a ucrm_user_id
            foreach ($retailers as $r) {
                if (($r['role'] ?? '') === 'support_leader' && !empty($r['ucrm_user_id'])) {
                    $bidalUcrmId = (int)$r['ucrm_user_id'];
                    break;
                }
            }
            // Soft-fallback: name match (handles legacy retailers without role set)
            if ($bidalUcrmId <= 0) {
                foreach ($retailers as $r) {
                    if (stripos($r['name'] ?? '', 'bidal') !== false && !empty($r['ucrm_user_id'])) {
                        $bidalUcrmId = (int)$r['ucrm_user_id'];
                        break;
                    }
                }
            }

            // ── Build human-friendly customer display ─────────────────────
            $firstName = trim($post['firstname'] ?? '');
            $lastName  = trim($post['lastname']  ?? '');
            $custDisplay = trim("{$firstName} {$lastName}");
            if ($custDisplay === '') $custDisplay = "Customer #{$customerId}";

            // Truncate area for title (UCRM job titles render in narrow columns)
            $areaShort = mb_substr(trim($newAddress), 0, 40);
            if ($areaShort === '') $areaShort = 'New site';

            $serviceType  = trim($post['customer_type'] ?? '');
            $connectivity = trim($post['connectivity_type'] ?? 'Additional Service');
            $priority     = trim($post['priority'] ?? 'Medium');
            $salesPerson  = $this->resolveSalesPerson($post, []);
            $custPhone    = preg_replace('/[^0-9+]/', '', $post['mobile'] ?? '');

            // ── Resolve plan + amount for description ─────────────────────
            $offer = $this->store->findOne('subscription_plans.json', 'id', (int)($post['package_choice'] ?? 0));
            if (!$offer) $offer = $this->store->findOne('kyc_packages.json', 'id', (int)($post['package_choice'] ?? 0));
            $planName = trim($offer['name'] ?? '-');
            $planAmt  = (float)($offer['customer_price'] ?? $offer['amount'] ?? 0);

            // ── Title: bold, scannable, identifies existing customer ──────
            $title = "Additional Service — {$custDisplay} ({$customerId}) — New Site: {$areaShort}";

            // ── Description: full operational brief ───────────────────────
            $descLines = [
                "⚠️ ADDITIONAL SERVICE FOR EXISTING CUSTOMER",
                "",
                "Existing CRM Client : #{$customerId} — {$custDisplay}",
                "New Site Address    : {$newAddress}",
                "Service Type        : {$serviceType}",
                "Connection          : {$connectivity}",
                "Plan                : {$planName} (\${$planAmt}/mo)",
                "Priority            : {$priority}",
                "Customer Phone      : {$custPhone}",
                "Sales Person        : {$salesPerson}",
                "Quote Ref           : {$quoteRef}",
                "Plugin App ID       : {$appId}",
                "",
                "ACTION:",
                "1. Verify new site address & GPS",
                "2. Create a NEW service line on existing client #{$customerId}",
                "3. DO NOT modify existing service(s) — leave current site intact",
                "4. Schedule install per priority",
            ];
            $description = implode("\n", $descLines);

            // ── Build payload ─────────────────────────────────────────────
            // Schedule date = tomorrow 09:00 (Bidal can re-schedule from CRM)
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $payload = [
                'title'       => $title,
                'date'        => $tomorrow . 'T09:00:00.000Z',
                'duration'    => 60,
                'description' => $description,
                'status'      => 1, // Open
                'clientId'    => (int)$customerId,
            ];
            if ($bidalUcrmId > 0) {
                $payload['assignedUserId'] = $bidalUcrmId;
            } else {
                error_log("[KycService] createCrmJobForBidal: no support_leader ucrm_user_id found — job will be unassigned (appId={$appId})");
            }
            if ($newAddress !== '') $payload['address'] = $newAddress;

            $lat = trim((string)($post['latitude']  ?? ''));
            $lon = trim((string)($post['longitude'] ?? ''));
            if ($lat !== '' && $lon !== '' && is_numeric($lat) && is_numeric($lon)) {
                $payload['gpsLat'] = (float)$lat;
                $payload['gpsLon'] = (float)$lon;
            }

            // ── POST to UCRM scheduling endpoint ──────────────────────────
            // Use the same auth pattern as the quote creation above:
            // prefer config-supplied user token (admin-level), fall back to
            // $this->crm (which may be the agent's UCRM app key via withCrm()).
            // Agents with valid app keys can create scheduling jobs.
            $jobToken = trim($cfg['crm_auth_token'] ?? '');
            $jobUrl   = trim($cfg['crm_base_url']   ?? '');
            $jobCrm   = ($jobToken !== '')
                ? new CrmApiClient(
                    $jobUrl !== '' ? $jobUrl : rtrim($this->crm->getBaseUrl(), '/'),
                    $jobToken,
                    'x-auth-token'
                  )
                : $this->crm;

            $newJob = $jobCrm->post('scheduling/jobs', $payload);
            if (!$newJob || empty($newJob['id'])) {
                error_log('[KycService] createCrmJobForBidal: CRM job creation failed (appId=' . $appId . ') — ' . json_encode($jobCrm->getLastError()));
                return 0;
            }
            $newJobId = (int)$newJob['id'];

            // ── Add a checklist task to the job ───────────────────────────
            try {
                $jobCrm->post("scheduling/jobs/{$newJobId}/job-tasks", [
                    'name' => "Verify site, create new service line on client #{$customerId}, schedule install",
                ]);
            } catch (\Throwable $e) {
                // Non-fatal — task is cosmetic
                error_log('[KycService] createCrmJobForBidal: task add failed for job #' . $newJobId . ': ' . $e->getMessage());
            }

            error_log("[KycService] CRM scheduling job #{$newJobId} created (additional service, appId={$appId}, client #{$customerId}, assigned uid:{$bidalUcrmId})");
            return $newJobId;

        } catch (\Throwable $e) {
            error_log('[KycService] createCrmJobForBidal exception: ' . $e->getMessage());
            return 0;
        }
    }
}
