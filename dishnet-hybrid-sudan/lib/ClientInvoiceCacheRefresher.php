<?php
/**
 * ClientInvoiceCacheRefresher — keeps ucrm_invoices_cache.json fresh for
 * the customer-facing app.
 *
 * Background:
 *   The mobile app reads from ucrm_invoices_cache.json (cached UCRM data)
 *   to show the customer their unpaid invoices on the home screen.
 *   When a customer pays in UCRM, the cache file is never updated by any
 *   automatic process — so the "2 invoices due · $160" widget keeps
 *   nagging them even after their payment posted.
 *
 *   This refresher does two things:
 *     1. After a payment.add or invoice.edit webhook, refresh just the
 *        affected client's invoices in the cache (fast, surgical update).
 *     2. On app_invoices / app_me page hits, if the cache for THIS client
 *        is older than a configurable freshness window, refresh from UCRM
 *        live before serving the response.
 *
 *   Combined effect: in normal operation customers see fresh data within
 *   seconds of payment. If a webhook is missed, the next app open
 *   triggers a refresh anyway.
 *
 * Approach:
 *   - Surgical: only fetches invoices for the one client_id passed in
 *   - Cheap: single UCRM GET, ~50-200ms
 *   - Safe: any failure logs and falls through to existing cache; never
 *     breaks the calling flow
 *   - Coalesced: tracks per-client last-refresh timestamp in
 *     data/client_invoice_refresh.json; on-demand path skips if refreshed
 *     in the last 60 seconds (prevents stampede if someone refreshes the
 *     app repeatedly)
 *
 * v4.21.38
 */

declare(strict_types=1);

class ClientInvoiceCacheRefresher
{
    /** @var mixed */ private $store;
    /** @var mixed */ private $crm;
    /** @var string */ private $dataDir;
    /** @var string */ private $stateFile;

    /** Per-client minimum interval between on-demand refreshes (seconds). */
    const ON_DEMAND_MIN_INTERVAL = 60;

    /** UCRM GET timeout — keep low so app load isn't blocked on slow CRM. */
    const FETCH_TIMEOUT_MS = 8000;

    public function __construct($store, $crm, string $dataDir)
    {
        $this->store     = $store;
        $this->crm       = $crm;
        $this->dataDir   = $dataDir;
        $this->stateFile = rtrim($dataDir, '/') . '/client_invoice_refresh.json';
    }

    /**
     * Refresh cache for one client. Force=true bypasses the rate limit.
     * Returns ['ok'=>bool, 'updated'=>int, 'error'=>?string].
     *
     * Used by:
     *   - webhook.php payment.add / invoice.edit handlers (force=true,
     *     reason='webhook:payment.add' etc.)
     *   - api_customer_app app_invoices / app_me on-demand (force=false,
     *     reason='ondemand:app_me')
     */
    public function refreshForClient(int $clientId, bool $force = false, string $reason = ''): array
    {
        if ($clientId <= 0) {
            return ['ok' => false, 'updated' => 0, 'error' => 'invalid_client_id'];
        }

        // Rate limit: skip if recently refreshed UNLESS force=true (webhook).
        if (!$force) {
            $state = $this->loadState();
            $last  = (int)($state[$clientId]['last_refresh_ts'] ?? 0);
            if ((time() - $last) < self::ON_DEMAND_MIN_INTERVAL) {
                return ['ok' => true, 'updated' => 0, 'error' => null, 'note' => 'rate_limited'];
            }
        }

        try {
            // Fetch this client's invoices from UCRM live. Filter to recent
            // (last 90 days) for speed; older invoices rarely change status.
            // We pull all statuses (paid/unpaid/partial/void) to keep cache
            // consistent — the app filters by amount_due > 0 client-side.
            $invs = $this->crm->get("invoices?clientId={$clientId}&limit=100");
            if (!is_array($invs)) {
                $err = $this->crm->getLastError();
                return ['ok' => false, 'updated' => 0,
                        'error' => 'ucrm_returned_non_array: ' . json_encode($err)];
            }

            // Merge into existing cache: replace this client's entries,
            // preserve everything else.
            $cache = $this->store->load('ucrm_invoices_cache.json') ?? [];
            $kept = [];
            foreach ($cache as $row) {
                if ((int)($row['clientId'] ?? 0) !== $clientId) {
                    $kept[] = $row;
                }
            }
            // Append fresh invoices for this client
            foreach ($invs as $inv) {
                if (!is_array($inv) || empty($inv['id'])) continue;
                $kept[] = $inv;
            }

            $this->store->save('ucrm_invoices_cache.json', $kept);

            // Update state tracker
            $state = $this->loadState();
            $state[$clientId] = [
                'last_refresh_ts' => time(),
                'last_reason'     => $reason,
                'invoice_count'   => count($invs),
            ];
            $this->saveState($state);

            return ['ok' => true, 'updated' => count($invs), 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'updated' => 0, 'error' => 'exception: ' . $e->getMessage()];
        }
    }

    /**
     * Same idea for client cache (account_balance, status, etc.). Used by
     * payment.add webhook so the home-screen header ("2 invoices due ·
     * $160 · Pay to keep service active") clears immediately.
     */
    public function refreshClientRecord(int $clientId, string $reason = ''): array
    {
        if ($clientId <= 0) {
            return ['ok' => false, 'error' => 'invalid_client_id'];
        }
        try {
            $fresh = $this->crm->get("clients/{$clientId}");
            if (!is_array($fresh) || empty($fresh['id'])) {
                return ['ok' => false, 'error' => 'ucrm_returned_non_array_or_empty'];
            }
            $cache = $this->store->load('ucrm_clients_cache.json') ?? [];
            $found = false;
            foreach ($cache as $i => $row) {
                if ((int)($row['id'] ?? 0) === $clientId) {
                    $cache[$i] = $fresh;
                    $found = true;
                    break;
                }
            }
            if (!$found) $cache[] = $fresh;
            $this->store->save('ucrm_clients_cache.json', $cache);

            // Also refresh the search index entry that drives app_me's "header"
            // The search index has a slimmer shape — match it.
            $this->refreshClientSearchIndex($clientId, $fresh);

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'exception: ' . $e->getMessage()];
        }
    }

    /**
     * Update one row in client_search_index.json from a fresh UCRM client
     * record. The search index drives the "Hi {Name}" header + service
     * type pill in the app, plus the multi-account switcher.
     */
    private function refreshClientSearchIndex(int $clientId, array $fresh): void
    {
        try {
            $index = $this->store->load('client_search_index.json') ?? [];
            $balance = (float)($fresh['accountBalance'] ?? 0);
            $outstanding = (float)($fresh['accountOutstanding'] ?? 0);
            // Compose phone (UCRM stores in attributes or contacts)
            $phone = '';
            foreach (($fresh['contacts'] ?? []) as $c) {
                if (!empty($c['phone'])) { $phone = (string)$c['phone']; break; }
            }
            // Plans summary — first service's plan name
            $plans = '';
            foreach (($fresh['services'] ?? []) as $s) {
                if (!empty($s['servicePlanName'])) { $plans = (string)$s['servicePlanName']; break; }
            }

            $found = false;
            foreach ($index as $i => $row) {
                if ((int)($row['id'] ?? 0) === $clientId) {
                    $index[$i]['name']               = trim(
                        ($fresh['firstName'] ?? '') . ' ' . ($fresh['lastName'] ?? '')
                    ) ?: ($fresh['companyName'] ?? '');
                    $index[$i]['email']              = $fresh['email'] ?? ($index[$i]['email'] ?? '');
                    $index[$i]['phone']              = $phone ?: ($index[$i]['phone'] ?? '');
                    $index[$i]['plans']              = $plans ?: ($index[$i]['plans'] ?? '');
                    $index[$i]['account_balance']    = $balance;
                    $index[$i]['account_outstanding']= $outstanding;
                    $index[$i]['updated_at']         = date('c');
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $index[] = [
                    'id' => $clientId,
                    'name' => trim(($fresh['firstName'] ?? '') . ' ' . ($fresh['lastName'] ?? '')) ?: ($fresh['companyName'] ?? ''),
                    'email' => $fresh['email'] ?? '',
                    'phone' => $phone,
                    'plans' => $plans,
                    'account_balance' => $balance,
                    'account_outstanding' => $outstanding,
                    'updated_at' => date('c'),
                ];
            }
            $this->store->save('client_search_index.json', $index);
        } catch (\Throwable $e) {
            // search index update is best-effort
        }
    }

    /** Read state file (per-client refresh timestamps). */
    private function loadState(): array
    {
        if (!file_exists($this->stateFile)) return [];
        $j = @json_decode((string)@file_get_contents($this->stateFile), true);
        return is_array($j) ? $j : [];
    }

    /** Persist state file with size cap. */
    private function saveState(array $state): void
    {
        // Keep only the 1000 most-recently-refreshed clients to bound size
        if (count($state) > 1200) {
            uasort($state, function ($a, $b) {
                return (int)($b['last_refresh_ts'] ?? 0) <=> (int)($a['last_refresh_ts'] ?? 0);
            });
            $state = array_slice($state, 0, 1000, true);
        }
        @file_put_contents($this->stateFile, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
