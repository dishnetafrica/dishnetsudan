<?php
declare(strict_types=1);

/**
 * DishNetTools — the business capability layer the AI talks to.
 *
 * The AI asks "what services does this customer have?". It does not know that
 * the answer is GET clients/{id}/services on UISP API v2.1, and it must never
 * need to. Everything UCRM-shaped stops here.
 *
 * Every method returns the same envelope:
 *
 *   ['ok' => bool, 'data' => mixed, 'error' => string]
 *
 * Normalised fields use only keys this plugin already reads from live UCRM in
 * production, so nothing here is guessed. Each record also carries '_raw' with
 * the untouched UCRM object, so a field we have not normalised is still
 * reachable without a code change.
 *
 * Where a shape is genuinely unknown — service plans are the case that matters —
 * the method says so via '_schema_verified' => false and
 * describeProductSchema() reports what the live API actually returns.
 *
 * PHP 7.4 compatible.
 */
class DishNetTools
{
    /** Minimum digits that must match for a phone lookup to be trusted. */
    const MIN_PHONE_MATCH_DIGITS = 9;

    private $store;                  // SqliteStore
    private array $config;
    private ?CrmApiClient $crm = null;
    private string $pluginRoot;

    public function __construct($store, array $config, string $pluginRoot)
    {
        $this->store      = $store;
        $this->config     = $config;
        $this->pluginRoot = $pluginRoot;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  CUSTOMER
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Identify a customer from their WhatsApp number.
     *
     * SECURITY — this deliberately differs from WaAutoReplyService::lookupCrmClient().
     * That version matches with:
     *
     *     str_ends_with($storedPhone, $incoming) || str_ends_with($incoming, $storedPhone)
     *
     * The second clause means a short stored number (e.g. "912345") matches
     * EVERY incoming number ending in those digits, and the match then gates
     * balance and invoice disclosure. This version requires at least
     * MIN_PHONE_MATCH_DIGITS of agreement and drops the reverse clause.
     *
     * It also returns 'ambiguous' rather than picking one when several
     * customers match — disclosing the wrong person's billing is worse than
     * asking a question.
     *
     * Set config 'tools_legacy_phone_match' => true to restore the old
     * behaviour if this proves too strict against real data.
     */
    public function identifyCustomerByPhone(string $phone): array
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        if (strlen($digits) < self::MIN_PHONE_MATCH_DIGITS) {
            return $this->err('Phone number too short to identify safely');
        }
        $needle = substr($digits, -self::MIN_PHONE_MATCH_DIGITS);
        $legacy = !empty($this->config['tools_legacy_phone_match']);

        $matches = [];

        // 1. Local search index — fast, rebuilt by the CRM sync cron.
        try {
            foreach (($this->store->load('client_search_index.json') ?? []) as $c) {
                $stored = preg_replace('/[^0-9]/', '', (string)($c['phone'] ?? '')) ?? '';
                if ($stored === '') continue;
                if ($this->phoneMatches($stored, $digits, $needle, $legacy)) {
                    $matches[(int)($c['id'] ?? 0)] = $c;
                }
            }
        } catch (\Throwable $e) { /* index optional */ }

        // 2. Fall back to the CRM API.
        if (!$matches) {
            $crm = $this->crm();
            if ($crm === null) return $this->err('CRM is not configured');
            try {
                $results = $crm->get('clients?phone=' . rawurlencode($needle) . '&limit=10') ?? [];
                if (!$results) {
                    $results = $crm->get('clients?search=' . rawurlencode($needle) . '&limit=10') ?? [];
                }
                foreach ($results as $r) {
                    foreach (($r['contacts'] ?? []) as $ct) {
                        $stored = preg_replace('/[^0-9]/', '', (string)($ct['phone'] ?? '')) ?? '';
                        if ($stored !== '' && $this->phoneMatches($stored, $digits, $needle, $legacy)) {
                            $matches[(int)($r['id'] ?? 0)] = $r;
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                return $this->err('CRM lookup failed: ' . $e->getMessage());
            }
        }

        unset($matches[0]);

        if (!$matches) {
            return $this->ok(['found' => false, 'reason' => 'no_match']);
        }
        if (count($matches) > 1) {
            // Never guess. The caller must ask a verifying question.
            return $this->ok([
                'found'      => false,
                'reason'     => 'ambiguous',
                'match_count'=> count($matches),
            ]);
        }

        $client = array_values($matches)[0];
        $id     = (int)($client['id'] ?? 0);

        // The index row is thin — pull the full record when we can.
        $crm = $this->crm();
        if ($crm !== null && $id > 0) {
            try {
                $full = $crm->get('clients/' . $id);
                if ($full) $client = $full;
            } catch (\Throwable $e) { /* keep the index row */ }
        }

        return $this->ok(['found' => true, 'customer' => $this->normaliseCustomer($client)]);
    }

    public function getCustomer(int $clientId): array
    {
        $crm = $this->crm();
        if ($crm === null) return $this->err('CRM is not configured');
        try {
            $c = $crm->get('clients/' . $clientId);
            if (!$c) return $this->err('Customer not found');
            return $this->ok($this->normaliseCustomer($c));
        } catch (\Throwable $e) {
            return $this->err('CRM lookup failed: ' . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SERVICES
    // ══════════════════════════════════════════════════════════════════════

    public function getCustomerServices(int $clientId): array
    {
        $crm = $this->crm();
        if ($crm === null) return $this->err('CRM is not configured');
        try {
            $rows = $crm->get('clients/' . $clientId . '/services') ?? [];
            $out  = [];
            foreach ($rows as $s) {
                $out[] = [
                    'id'          => isset($s['id']) ? (int)$s['id'] : null,
                    'name'        => $s['name'] ?? ($s['servicePlanName'] ?? null),
                    'plan_name'   => $s['servicePlanName'] ?? null,
                    'plan_id'     => isset($s['servicePlanId']) ? (int)$s['servicePlanId'] : null,
                    'status'      => $s['status'] ?? null,
                    'active_to'   => isset($s['activeTo']) ? substr((string)$s['activeTo'], 0, 10) : null,
                    '_raw'        => $s,
                ];
            }
            return $this->ok($out);
        } catch (\Throwable $e) {
            return $this->err('Service lookup failed: ' . $e->getMessage());
        }
    }

    /**
     * Live line status for fibre customers, from Splynx.
     *
     * This is the plugin's most valuable unique capability: it answers "is the
     * line actually up right now", which neither UCRM nor the AI can know.
     * Delegates to the existing WaAutoReplyService implementation rather than
     * duplicating it.
     */
    public function getLineStatus(string $phone): array
    {
        $file = $this->pluginRoot . '/lib/SplynxApiClient.php';
        if (!file_exists($file)) return $this->ok(['available' => false]);

        try {
            require_once $file;
            $splynx = \SplynxApiClient::fromConfig($this->config);
            if (!$splynx->isConfigured()) return $this->ok(['available' => false]);

            // Splynx stores numbers in several formats. Try the ones this
            // plugin already tries in WaAutoReplyService::getFiberSplynxContext().
            $candidates = [$phone, ltrim($phone, '+')];
            $local = preg_replace('/^\+?211/', '', $phone);
            if ($local !== null && $local !== '' && $local !== $phone) {
                $candidates[] = $local;
                if (substr($local, 0, 1) !== '0') $candidates[] = '0' . $local;
            }

            $customer = null;
            foreach (array_unique($candidates) as $try) {
                $found = $splynx->get('api/2.0/admin/customers/customer', ['phone' => $try]);
                if (!empty($found[0]['id'])) { $customer = $found[0]; break; }
            }
            if ($customer === null) return $this->ok(['available' => false, 'reason' => 'not_in_splynx']);

            $splynxId = (int)$customer['id'];
            $out = [
                'available'       => true,
                'splynx_id'       => $splynxId,
                'customer_status' => $customer['status'] ?? null,
                'customer_name'   => trim((string)($customer['name'] ?? '') . ' ' . (string)($customer['last_name'] ?? '')),
                'service_address' => $customer['street_1'] ?? null,
            ];

            if (method_exists($splynx, 'getCustomerServices')) {
                $out['services'] = $splynx->getCustomerServices($splynxId);
            }
            return $this->ok($out);
        } catch (\Throwable $e) {
            // Never let a Splynx outage break a support conversation.
            return $this->ok(['available' => false, 'reason' => 'splynx_unavailable']);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  ACCOUNT / BILLING
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Account summary: balance, latest unpaid invoice, last payment.
     *
     * Callers must treat this as sensitive. The AI should not volunteer it
     * until identity is settled — see identifyCustomerByPhone()'s 'ambiguous'
     * result and the verification note in the API layer.
     */
    public function getAccount(int $clientId): array
    {
        $crm = $this->crm();
        if ($crm === null) return $this->err('CRM is not configured');

        try {
            $client = $crm->get('clients/' . $clientId);
            if (!$client) return $this->err('Customer not found');

            $balance = isset($client['accountBalance'])
                ? (float)$client['accountBalance']
                : (float)($client['balance'] ?? 0);

            return $this->ok([
                'client_id'    => $clientId,
                'name'         => $this->displayName($client),
                'balance'      => $balance,
                'owes'         => $balance > 0.01,
                'in_credit'    => $balance < -0.01,
                'invoice'      => $this->latestInvoice($clientId),
                'last_payment' => $this->lastPayment($clientId),
            ]);
        } catch (\Throwable $e) {
            return $this->err('Account lookup failed: ' . $e->getMessage());
        }
    }

    public function getInvoices(int $clientId, int $limit = 5): array
    {
        $crm = $this->crm();
        if ($crm === null) return $this->err('CRM is not configured');
        try {
            $rows = $crm->get('invoices?clientId=' . $clientId . '&limit=' . $limit) ?? [];
            $out  = [];
            foreach ($rows as $i) $out[] = $this->normaliseInvoice($i);
            return $this->ok($out);
        } catch (\Throwable $e) {
            return $this->err('Invoice lookup failed: ' . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  PRODUCTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The plans DishNet actually sells, from UCRM.
     *
     * This is the tool that removes the hard-coded price list from the AI
     * prompt. Nothing about the response shape is invented: we normalise the
     * keys we can see and pass '_raw' through untouched.
     *
     * '_schema_verified' is false until someone runs describeProductSchema()
     * against the live API and we confirm which fields exist. Speed, billing
     * period and installation fee are NOT assumed — if UCRM does not return
     * them they stay null, and the AI is told to ask rather than guess.
     */
    public function getProducts(int $limit = 200): array
    {
        $crm = $this->crm();
        if ($crm === null) return $this->err('CRM is not configured');

        try {
            $plans = $crm->get('service-plans?limit=' . $limit) ?? [];
            $out   = [];
            foreach ($plans as $p) {
                if (isset($p['isActive']) && !$p['isActive']) continue;
                $out[] = [
                    'id'             => isset($p['id']) ? (int)$p['id'] : null,
                    'name'           => $p['name'] ?? null,
                    'price'          => isset($p['price']) ? (float)$p['price'] : null,
                    'period_months'  => isset($p['period']) ? (int)$p['period'] : null,
                    'download_speed' => $p['downloadSpeed'] ?? null,   // null when absent
                    'upload_speed'   => $p['uploadSpeed']   ?? null,
                    'data_limit'     => $p['dataUsageLimit'] ?? null,
                    'organization_id'=> isset($p['organizationId']) ? (int)$p['organizationId'] : null,
                    'source'         => 'service-plans',
                    '_raw'           => $p,
                ];
            }
            return $this->ok([
                'products'         => $out,
                'count'            => count($out),
                '_schema_verified' => false,
                '_note'            => 'Fields absent from UCRM are null. Never present a null field as a fact.',
            ]);
        } catch (\Throwable $e) {
            return $this->err('Product lookup failed: ' . $e->getMessage());
        }
    }

    /**
     * Phase 0 probe, shipped as a tool.
     *
     * Reports which keys the live UCRM instance actually returns for
     * service-plans, products and organizations. Run this once against the
     * real server and getProducts() can be finalised against fact instead of
     * inference. Returns key names and types only — no customer data.
     */
    public function describeProductSchema(): array
    {
        $crm = $this->crm();
        if ($crm === null) return $this->err('CRM is not configured');

        $report = [];
        foreach (['service-plans' => 'service-plans?limit=3',
                  'products'      => 'products?limit=3',
                  'organizations' => 'organizations'] as $label => $path) {
            try {
                $rows = $crm->get($path);
                if (!is_array($rows) || !$rows) {
                    $report[$label] = ['reachable' => true, 'rows' => 0, 'keys' => []];
                    continue;
                }
                $keys = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    foreach ($row as $k => $v) {
                        $keys[$k] = is_array($v) ? 'array' : gettype($v);
                    }
                }
                ksort($keys);
                $report[$label] = ['reachable' => true, 'rows' => count($rows), 'keys' => $keys];
            } catch (\Throwable $e) {
                $report[$label] = ['reachable' => false, 'error' => $e->getMessage()];
            }
        }
        return $this->ok($report);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SUPPORT
    // ══════════════════════════════════════════════════════════════════════

    public function createSupportRequest(int $clientId, string $subject, string $body, string $phone = ''): array
    {
        $crm = $this->crm();
        if ($crm === null) return $this->err('CRM is not configured');
        try {
            $payload = ['subject' => $subject, 'clientId' => $clientId ?: null];
            $resp = $crm->post('ticketing/tickets', array_filter($payload, function ($v) {
                return $v !== null;
            }) + ['activity' => [['comment' => $body]]]);

            if (is_array($resp) && !empty($resp['id'])) {
                return $this->ok(['ticket_id' => (int)$resp['id'], 'source' => 'ucrm']);
            }
            return $this->err('Ticket was not created');
        } catch (\Throwable $e) {
            return $this->err('Ticket creation failed: ' . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Internals
    // ══════════════════════════════════════════════════════════════════════

    private function phoneMatches(string $stored, string $incoming, string $needle, bool $legacy): bool
    {
        if ($legacy) {
            return $this->endsWith($stored, $incoming) || $this->endsWith($incoming, $stored);
        }
        // Both numbers must carry at least the comparison length, and their
        // trailing MIN_PHONE_MATCH_DIGITS must agree exactly.
        if (strlen($stored) < self::MIN_PHONE_MATCH_DIGITS) return false;
        return substr($stored, -self::MIN_PHONE_MATCH_DIGITS) === $needle;
    }

    private function endsWith(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }

    private function displayName(array $c): string
    {
        $n = trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''));
        if ($n === '') $n = trim((string)($c['companyName'] ?? ''));
        return $n !== '' ? $n : 'Customer';
    }

    private function normaliseCustomer(array $c): array
    {
        $isLead = !empty($c['isLead']) || (int)($c['clientType'] ?? 2) === 1;
        return [
            'id'          => isset($c['id']) ? (int)$c['id'] : null,
            'name'        => $this->displayName($c),
            'is_lead'     => $isLead,
            'is_active'   => array_key_exists('isActive', $c) ? (bool)$c['isActive'] : null,
            'balance'     => isset($c['accountBalance']) ? (float)$c['accountBalance']
                             : (isset($c['balance']) ? (float)$c['balance'] : null),
            '_raw'        => $c,
        ];
    }

    private function normaliseInvoice(array $i): array
    {
        return [
            'id'          => isset($i['id']) ? (int)$i['id'] : null,
            'number'      => $i['invoiceNumber'] ?? null,
            'total'       => isset($i['total']) ? (float)$i['total'] : null,
            'amount_due'  => isset($i['amountToPay']) ? (float)$i['amountToPay'] : null,
            'due_date'    => isset($i['dueDate']) ? substr((string)$i['dueDate'], 0, 10) : null,
            'created'     => isset($i['createdDate']) ? substr((string)$i['createdDate'], 0, 10) : null,
            'status'      => $i['status'] ?? null,
            '_raw'        => $i,
        ];
    }

    private function latestInvoice(int $clientId): ?array
    {
        $crm = $this->crm();
        if ($crm === null) return null;
        try {
            // statuses 1 and 2 are the unpaid/partially-paid set this plugin
            // already queries in WaAutoReplyService::getLatestInvoice().
            $rows = $crm->get('invoices?clientId=' . $clientId . '&statuses[]=1&statuses[]=2&limit=1') ?? [];
            if (!$rows) $rows = $crm->get('invoices?clientId=' . $clientId . '&limit=1') ?? [];
            return $rows ? $this->normaliseInvoice($rows[0]) : null;
        } catch (\Throwable $e) { return null; }
    }

    private function lastPayment(int $clientId): ?array
    {
        $crm = $this->crm();
        if ($crm === null) return null;
        try {
            $rows = $crm->get('payments?clientId=' . $clientId . '&limit=1') ?? [];
            if (!$rows) return null;
            $p = $rows[0];
            return [
                'amount'  => isset($p['amount']) ? (float)$p['amount'] : null,
                'date'    => isset($p['createdDate']) ? substr((string)$p['createdDate'], 0, 10) : null,
                '_raw'    => $p,
            ];
        } catch (\Throwable $e) { return null; }
    }

    private function crm(): ?CrmApiClient
    {
        if ($this->crm !== null) return $this->crm;
        $file = $this->pluginRoot . '/lib/CrmApiClient.php';
        if (!file_exists($file)) return null;
        require_once $file;
        $c = CrmApiClient::fromUcrm($this->pluginRoot, $this->config);
        if (!$c->isConfigured()) return null;
        $this->crm = $c;
        return $this->crm;
    }

    private function ok($data): array  { return ['ok' => true,  'data' => $data, 'error' => '']; }
    private function err(string $m): array { return ['ok' => false, 'data' => null, 'error' => $m]; }
}
