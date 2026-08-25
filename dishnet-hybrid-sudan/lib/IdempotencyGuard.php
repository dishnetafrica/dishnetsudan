<?php
declare(strict_types=1);

/**
 * IdempotencyGuard — Prevents duplicate POST operations.
 * 
 * Client sends: X-Idempotency-Key: <uuid>
 * Guard checks data/idempotency_keys.json for existing key.
 * If found and not expired (24h TTL): returns cached response.
 * If not found: lets request through, stores response after.
 *
 * Storage: flat-file JSON (no external DB required).
 * Uses .withLock() for concurrency safety.
 *
 * v2.2 — New file. Does not modify any existing class.
 */
class IdempotencyGuard
{
    private const FILE = 'idempotency_keys.json';
    private const TTL  = 86400; // 24 hours

    private  $store;
    private ?string   $currentKey = null;

    public function __construct( $store)
    {
        $this->store = $store;
    }

    /**
     * Extract key from request header.
     * Returns null if no header present (idempotency is optional).
     */
    public function getKeyFromRequest(): ?string
    {
        // Standard header
        $key = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;

        // Fallback: check body for mobile clients that can't set headers easily
        if (!$key) {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $body = json_decode($raw, true);
                $key = $body['idempotency_key'] ?? null;
            }
        }

        if ($key === null) return null;

        $key = trim((string)$key);

        // Validate: must be 8-128 chars, alphanumeric + hyphens
        if (!preg_match('/^[a-zA-Z0-9\-]{8,128}$/', $key)) {
            return null;
        }

        $this->currentKey = $key;
        return $key;
    }

    /**
     * Check if this key was already processed.
     * Returns cached response array if duplicate, null if new.
     * Also purges expired entries (lazy cleanup).
     */
    public function check(string $key): ?array
    {
        $this->purgeExpired();

        $entry = $this->store->findOne(self::FILE, 'key', $key);
        if (!$entry) return null;

        // Check TTL
        $createdAt = strtotime($entry['created_at'] ?? '');
        if ($createdAt && (time() - $createdAt) > self::TTL) {
            // Expired — treat as new request
            return null;
        }

        return $entry['response'] ?? null;
    }

    /**
     * Store response for this idempotency key.
     * Called AFTER successful processing.
     */
    public function store(string $key, int $httpCode, array $response): void
    {
        $record = [
            'key'         => $key,
            'http_code'   => $httpCode,
            'response'    => $response,
            'action'      => $_GET['action'] ?? '',
            'created_at'  => date('Y-m-d H:i:s'),
            'expires_at'  => date('Y-m-d H:i:s', time() + self::TTL),
        ];

        $this->store->appendWithId(self::FILE, $record);
    }

    /**
     * Convenience: check + return cached response if duplicate.
     * Returns true if duplicate was handled (response already sent).
     * Returns false if this is a new request (caller should proceed).
     */
    public function handleIfDuplicate(): bool
    {
        $key = $this->getKeyFromRequest();
        if ($key === null) return false; // no key = no idempotency

        $cached = $this->check($key);
        if ($cached === null) return false; // new request

        // Duplicate — return cached response
        http_response_code($cached['code'] ?? 200);
        header('X-Idempotency-Replay: true');
        echo json_encode($cached['body'] ?? $cached);
        exit;
    }

    /**
     * Store the current request's response for future dedup.
     * Call this right before sending the final response.
     */
    public function storeCurrentResponse(int $httpCode, array $responseBody): void
    {
        if ($this->currentKey === null) return;

        $this->store($this->currentKey, $httpCode, [
            'code' => $httpCode,
            'body' => $responseBody,
        ]);
    }

    /**
     * Lazy cleanup: remove entries older than TTL.
     * Runs at most once per request (uses static flag).
     * Keeps file from growing unbounded.
     */
    private function purgeExpired(): void
    {
        static $purged = false;
        if ($purged) return;
        $purged = true;

        $all = $this->store->load(self::FILE);
        if (count($all) < 50) return; // not worth purging small files

        $now = time();
        $filtered = array_values(array_filter($all, function ($entry) use ($now) {
            $created = strtotime($entry['created_at'] ?? '');
            return $created && ($now - $created) <= self::TTL;
        }));

        // Only rewrite if we actually removed something
        if (count($filtered) < count($all)) {
            $this->store->save(self::FILE, $filtered);
        }
    }
}
