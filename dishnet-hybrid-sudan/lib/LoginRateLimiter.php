<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }

/**
 * LoginRateLimiter
 *
 * Brute-force protection for the web login form and API /login endpoint.
 *
 * ── Rules ────────────────────────────────────────────────────────────────
 *
 *   • 5 failed attempts within 10 minutes → account locked for 15 minutes
 *   • Lock key = SHA256( strtolower(email) + "|" + client_ip )
 *     Using the combined key means:
 *       - Same email from different IPs each get their own counter
 *         (doesn't lock a valid user just because an attacker tries from one IP)
 *       - Same IP attacking different accounts accumulates separate counters
 *         per account
 *   • Successful login clears the counter for that email+IP pair
 *   • Stale entries (older than 1 hour) are pruned on every write to keep
 *     the file small
 *   • All timing is server-side (cannot be manipulated by the client)
 *
 * ── Storage ──────────────────────────────────────────────────────────────
 *
 *   data/login_attempts.json  — keyed by hash, never stores raw email/IP
 *   Structure per entry:
 *   {
 *     "key":       "sha256hex",      ← hash of email|ip
 *     "count":     3,                ← failed attempts in current window
 *     "window_start": 1710000000,    ← Unix timestamp of first failure
 *     "locked_until": null|1710001200 ← null = not locked, timestamp = locked
 *   }
 *
 * ── Usage ─────────────────────────────────────────────────────────────────
 *
 *   // In public.php login handler:
 *   $limiter = new LoginRateLimiter($store);
 *
 *   // Check before attempting password verify:
 *   $lockInfo = $limiter->check($email, $_SERVER['REMOTE_ADDR']);
 *   if ($lockInfo['locked']) {
 *       flash("Too many failed attempts. Try again in {$lockInfo['retry_in_minutes']} minutes.", 'danger');
 *       redirect('?page=login');
 *   }
 *
 *   // On failed login:
 *   $limiter->recordFailure($email, $_SERVER['REMOTE_ADDR']);
 *
 *   // On successful login:
 *   $limiter->recordSuccess($email, $_SERVER['REMOTE_ADDR']);
 */
class LoginRateLimiter
{
    // Configurable thresholds
    const MAX_ATTEMPTS   = 5;    // failures before lockout
    const WINDOW_SECONDS = 600;  // 10-minute sliding window
    const LOCKOUT_SECONDS= 900;  // 15-minute lockout

    const STORE_FILE     = 'login_attempts.json';
    const PRUNE_AFTER    = 3600; // remove entries older than 1 hour

    private $store;  // JsonStore|SqliteStore — duck-typed, both share the same interface

    public function __construct($store)
    {
        $this->store = $store;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Check if this email+IP combination is currently rate-limited.
     *
     * Returns array:
     *   [
     *     'locked'              => bool,
     *     'retry_in_seconds'    => int,   // 0 if not locked
     *     'retry_in_minutes'    => int,   // ceil, 0 if not locked
     *     'attempts_remaining'  => int,   // how many more failures before lock
     *   ]
     */
    public function check(string $email, string $ip): array
    {
        $key   = $this->key($email, $ip);
        $entry = $this->findEntry($key);
        $now   = time();

        // No entry at all — clean slate
        if (!$entry) {
            return $this->cleanResult();
        }

        // Active lockout
        if (!empty($entry['locked_until']) && $entry['locked_until'] > $now) {
            $remaining = $entry['locked_until'] - $now;
            return [
                'locked'             => true,
                'retry_in_seconds'   => $remaining,
                'retry_in_minutes'   => (int)ceil($remaining / 60),
                'attempts_remaining' => 0,
            ];
        }

        // Lockout expired — treat as clean
        if (!empty($entry['locked_until']) && $entry['locked_until'] <= $now) {
            $this->clearEntry($key);
            return $this->cleanResult();
        }

        // Within window — check count
        if (
            !empty($entry['window_start']) &&
            ($now - $entry['window_start']) < self::WINDOW_SECONDS
        ) {
            $remaining_attempts = max(0, self::MAX_ATTEMPTS - (int)$entry['count']);
            return [
                'locked'             => false,
                'retry_in_seconds'   => 0,
                'retry_in_minutes'   => 0,
                'attempts_remaining' => $remaining_attempts,
            ];
        }

        // Window expired — clean
        $this->clearEntry($key);
        return $this->cleanResult();
    }

    /**
     * Record a failed login attempt. May trigger lockout.
     * Call this AFTER password_verify() returns false.
     *
     * Returns updated check result (so you can show "X attempts remaining").
     */
    public function recordFailure(string $email, string $ip): array
    {
        $key   = $this->key($email, $ip);
        $now   = time();

        $this->store->withLock(self::STORE_FILE, function (array $entries) use ($key, $now) {
            $idx = null;
            foreach ($entries as $i => $e) {
                if (($e['key'] ?? '') === $key) { $idx = $i; break; }
            }

            if ($idx === null) {
                // First failure — create entry
                $entries[] = [
                    'key'          => $key,
                    'count'        => 1,
                    'window_start' => $now,
                    'locked_until' => null,
                    'last_attempt' => $now,
                ];
            } else {
                $entry  = &$entries[$idx];
                $inWindow = ($now - ($entry['window_start'] ?? 0)) < self::WINDOW_SECONDS;

                if ($inWindow) {
                    $entry['count']++;
                    $entry['last_attempt'] = $now;
                    // Trigger lockout if threshold reached
                    if ($entry['count'] >= self::MAX_ATTEMPTS) {
                        $entry['locked_until'] = $now + self::LOCKOUT_SECONDS;
                    }
                } else {
                    // Window expired — reset counter
                    $entry['count']        = 1;
                    $entry['window_start'] = $now;
                    $entry['locked_until'] = null;
                    $entry['last_attempt'] = $now;
                }
                unset($entry);
            }

            // Prune stale entries on every write
            $cutoff  = $now - self::PRUNE_AFTER;
            $entries = array_values(array_filter($entries, function($e) use ($cutoff) {
                return ($e['last_attempt'] ?? 0) > $cutoff
                    || (!empty($e['locked_until']) && $e['locked_until'] > time());
            }));

            return ['records' => $entries, 'result' => null];
        });

        return $this->check($email, $ip);
    }

    /**
     * Clear all failure records for this email+IP on successful login.
     * Call this AFTER a successful password_verify().
     */
    public function recordSuccess(string $email, string $ip): void
    {
        $this->clearEntry($this->key($email, $ip));
    }

    /**
     * Admin: clear lockout for a specific email (all IPs).
     * Useful if a legitimate user is locked out.
     */
    public function adminUnlock(string $email): int
    {
        $emailLower = strtolower(trim($email));
        $cleared    = 0;

        $this->store->withLock(self::STORE_FILE, function (array $entries) use ($emailLower, &$cleared) {
            // We can't reverse the hash, so we use a prefix marker.
            // In recordFailure() we store the email hash prefix for admin unlock.
            // Filter by email hash prefix (first 16 chars of sha256(email))
            $emailPrefix = substr(hash('sha256', $emailLower), 0, 16);
            $filtered = array_values(array_filter($entries, function($e) use ($emailPrefix, &$cleared) {
                if (str_starts_with($e['key'] ?? '', $emailPrefix)) {
                    $cleared++;
                    return false;
                }
                return true;
            }));
            return ['records' => $filtered, 'result' => null];
        });

        return $cleared;
    }

    /**
     * Get lockout status for admin display.
     * Returns list of currently locked entries (anonymised — no raw email/IP).
     */
    public function getLockedAccounts(): array
    {
        $now     = time();
        $entries = $this->store->load(self::STORE_FILE);
        $locked  = [];

        foreach ($entries as $e) {
            if (!empty($e['locked_until']) && $e['locked_until'] > $now) {
                $locked[] = [
                    'key_prefix'       => substr($e['key'], 0, 8) . '...',
                    'attempts'         => $e['count'],
                    'locked_until'     => date('Y-m-d H:i:s', $e['locked_until']),
                    'retry_in_minutes' => (int)ceil(($e['locked_until'] - $now) / 60),
                ];
            }
        }

        return $locked;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Derive the storage key from email + IP.
     * Format: emailHash(16) + ipHash(16) — keeps file small, non-reversible.
     * The email hash prefix is also used for adminUnlock().
     */
    private function key(string $email, string $ip): string
    {
        $emailHash = substr(hash('sha256', strtolower(trim($email))), 0, 16);
        $ipHash    = substr(hash('sha256', $ip), 0, 16);
        return $emailHash . $ipHash;
    }

    private function findEntry(string $key): ?array
    {
        foreach ($this->store->load(self::STORE_FILE) as $e) {
            if (($e['key'] ?? '') === $key) return $e;
        }
        return null;
    }

    private function clearEntry(string $key): void
    {
        $this->store->withLock(self::STORE_FILE, function (array $entries) use ($key) {
            $entries = array_values(array_filter(
                $entries, fn($e) => ($e['key'] ?? '') !== $key
            ));
            return ['records' => $entries, 'result' => null];
        });
    }

    private function cleanResult(): array
    {
        return [
            'locked'             => false,
            'retry_in_seconds'   => 0,
            'retry_in_minutes'   => 0,
            'attempts_remaining' => self::MAX_ATTEMPTS,
        ];
    }
}
