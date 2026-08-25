<?php
declare(strict_types=1);

/**
 * SplynxApiClient — DishNet Africa
 *
 * REST client for the Splynx ISP Framework API v2.0.
 *
 * ── Configuration ────────────────────────────────────────────────────────────
 * Settings in kyc_config.json:
 *   splynx_url          Base URL, e.g. https://isp.dishnetafrica.com
 *   splynx_key          API key (from Splynx → Config → Administrators → API keys)
 *   splynx_secret       API secret
 *
 * ── Auth ─────────────────────────────────────────────────────────────────────
 * Splynx v2 uses HMAC-SHA256 signed requests:
 *   Authorization: Splynx-EA (key="<key>", timestamp="<ts>", nonce="<nonce>", signature="<sig>")
 *   Signature = HMAC-SHA256(key + nonce + timestamp, secret)
 */
class SplynxApiClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;
    private array  $lastError = [];

    public function __construct(string $baseUrl, string $apiKey, string $apiSecret)
    {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->apiKey    = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    /**
     * Factory — reads splynx_url / splynx_key / splynx_secret from kyc_config.json.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            trim($config['splynx_url']    ?? ''),
            trim($config['splynx_key']    ?? ''),
            trim($config['splynx_secret'] ?? '')
        );
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '' && $this->apiSecret !== '';
    }

    public function getLastError(): array { return $this->lastError; }

    // ── CRUD wrappers ─────────────────────────────────────────────────────────

    public function get(string $path, array $query = []): ?array
    {
        $url = $this->buildUrl($path, $query);
        return $this->request('GET', $url);
    }

    public function post(string $path, array $payload = []): ?array
    {
        return $this->request('POST', $this->buildUrl($path), $payload);
    }

    public function put(string $path, array $payload = []): ?array
    {
        return $this->request('PUT', $this->buildUrl($path), $payload);
    }

    public function delete(string $path): ?array
    {
        return $this->request('DELETE', $this->buildUrl($path));
    }

    // ── High-level helpers ────────────────────────────────────────────────────

    /** Create or look up a Splynx customer. Returns ['id' => int, ...] or null. */
    public function ensureCustomer(array $data): ?array
    {
        // Search by email
        if (!empty($data['email'])) {
            $found = $this->get('api/2.0/admin/customers/customer', ['email' => $data['email']]);
            if (!empty($found[0]['id'])) return $found[0];
        }
        return $this->post('api/2.0/admin/customers/customer', $data);
    }

    /** Create a ticket in Splynx. */
    public function createTicket(array $data): ?array
    {
        // Try primary path first, fallback to alternate
        $r = $this->post('api/2.0/admin/support/tickets', $data);
        if ($r === null) {
            $r = $this->post('api/2.0/admin/tickets/tickets', $data);
        }
        return $r;
    }

    /** List tickets, optionally filtered. */
    public function getTickets(array $filters = []): array
    {
        // Try paths in order -- different Splynx versions use different paths
        static $workingPath = null;
        if ($workingPath) {
            $r = $this->get($workingPath, $filters);
            if (is_array($r)) return $r;
        }
        foreach (['api/2.0/admin/support/tickets', 'api/2.0/admin/tickets/tickets'] as $p) {
            $r = $this->get($p, $filters);
            if (is_array($r)) { $workingPath = $p; return $r; }
        }
        return [];
    }

    /** Get a single ticket. */
    public function getTicket(int $id): ?array
    {
        static $workingPath = null;
        if (!$workingPath) { $workingPath = 'api/2.0/admin/support/tickets'; }
        $r = $this->get("{$workingPath}/{$id}");
        if ($r === null) {
            $r = $this->get("api/2.0/admin/tickets/tickets/{$id}");
        }
        return $r;
    }

    /** Update a ticket. */
    public function updateTicket(int $id, array $data): ?array
    {
        return $this->put("api/2.0/admin/support/tickets/{$id}", $data);
    }

    /**
     * Add a message/comment to a Splynx ticket.
     * Splynx API: POST api/2.0/admin/support/tickets-messages
     */
    public function addTicketMessage(int $ticketId, string $message, string $adminName = 'DishNet System'): ?array
    {
        return $this->post('api/2.0/admin/support/tickets-messages', [
            'ticket_id' => $ticketId,
            'message'   => $message,
            'admin_name'=> $adminName,
        ]);
    }

    /** Get a single customer by ID. */
    public function getCustomer(int $id): ?array
    {
        return $this->get("api/2.0/admin/customers/customer/{$id}");
    }

    /** Create an internet service for a customer. */
    public function createInternetService(array $data): ?array
    {
        return $this->post('api/2.0/admin/customers/customer-internet-services', $data);
    }

    /** Get internet services for a customer. */
    public function getCustomerServices(int $customerId): array
    {
        // Primary path: filter by customer_id
        $r = $this->get('api/2.0/admin/customers/customer-internet-services', ['customer_id' => $customerId]);
        if (is_array($r)) return $r;

        $lastErr  = $this->getLastError();
        $httpCode = (int)($lastErr['http_code'] ?? 0);

        // 405 = endpoint disabled in Splynx API permissions
        // To fix: Splynx -> Administration -> API -> Roles -> edit role -> enable GET on customer-internet-services
        // Fallback: use customer mrr_total > 0 as proxy for "has active service"
        // Returns a synthetic service record so callers still get usable data
        if ($httpCode === 405) {
            $customer = $this->getCustomer($customerId);
            if ($customer) {
                $mrr = (float)($customer['mrr_total'] ?? 0);
                if ($mrr > 0) {
                    error_log("[SplynxApi] customer-internet-services 405 -- using mrr_total={$mrr} proxy for customer #{$customerId}");
                    return [[
                        'id'          => 0,
                        'customer_id' => $customerId,
                        'status'      => 'active',
                        'description' => $customer['internet_plans'] ?? 'Active Service (mrr proxy)',
                        'price'       => $mrr,
                        '_proxy'      => true, // flag so callers know this is a fallback
                    ]];
                }
                // mrr=0 = no active service yet
                error_log("[SplynxApi] customer-internet-services 405, mrr_total=0 for customer #{$customerId} -- no active service");
            }
            return [];
        }

        // Fallback: try per-ID endpoint
        $r2 = $this->get("api/2.0/admin/customers/customer-internet-services/{$customerId}");
        if (is_array($r2)) return isset($r2[0]) ? $r2 : [$r2];
        return [];
    }

    /** Get ALL internet services (optionally filtered by status). */
    public function getAllServices(string $status = ''): array
    {
        $q = [];
        if ($status) $q['status'] = $status;
        $r = $this->get('api/2.0/admin/customers/customer-internet-services', $q);
        return is_array($r) ? $r : [];
    }

    /** Enable or disable a service. Status: active | disabled | suspended */
    public function setServiceStatus(int $serviceId, string $status): ?array
    {
        return $this->put("api/2.0/customers/customer-internet-services/{$serviceId}", [
            'status' => $status,
        ]);
    }

    /** List available tariff plans. */
    public function getTariffs(): array
    {
        $r = $this->get('api/2.0/admin/tariffs/internet');
        return is_array($r) ? $r : [];
    }

    /** List administrators / engineers. */
    public function getAdmins(): array
    {
        $r = $this->get('api/2.0/admin/administrators');
        return is_array($r) ? $r : [];
    }

    /** Connectivity test. */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Splynx not configured — set splynx_url, splynx_key, splynx_secret in Settings.'];
        }
        $result = $this->get('api/2.0/admin/customers/customer', ['page' => 0, 'limit' => 1]);
        if ($result === null) {
            $e = $this->lastError;
            return ['ok' => false, 'error' => $e['error'] ?? ('HTTP ' . ($e['http_code'] ?? '?'))];
        }
        return ['ok' => true, 'error' => ''];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function buildUrl(string $path, array $query = []): string
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query) $url .= '?' . http_build_query($query);
        return $url;
    }

    /**
     * Splynx uses HTTP Basic Auth: Authorization: Basic base64(key:secret)
     * Confirmed from working fiber finance plugin (SplynxSync.php).
     */
    private function basicAuthHeader(): string
    {
        return 'Authorization: Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret);
    }

    private function request(string $method, string $url, array $payload = [], bool $isRetry = false): ?array
    {
        $headers = [
            $this->basicAuthHeader(),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => (getenv('UCRM_SKIP_SSL_VERIFY') !== '1'),
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($payload && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $this->lastError = ['error' => "cURL: {$err}"];
            error_log("SplynxApiClient [{$method} {$url}]: cURL error — {$err}");
            return null;
        }

        $data = json_decode((string)$raw, true);

        if ($code === 401 && !$isRetry) {
            // Token expired — clear and retry once with fresh token
            $this->accessToken  = null;
            $this->tokenExpires = 0;
            return $this->request($method, $url, $payload, true);
        }
        if ($code >= 400) {
            $this->lastError = ['http_code' => $code, 'response' => $data ?? $raw];
            error_log("SplynxApiClient [{$method} {$url}]: HTTP {$code} — " . substr((string)$raw, 0, 300));
            return null;
        }

        return is_array($data) ? $data : ($raw !== '' ? ['raw' => $raw] : []);
    }
}
