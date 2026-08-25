<?php
declare(strict_types=1);

/**
 * RetailerAuth
 *
 * Handles retailer accounts, sessions (web) and API tokens (mobile).
 *
 * Retailer record structure:
 * {
 *   "id":           1,
 *   "name":         "ABC Retail",
 *   "email":        "abc@shop.com",
 *   "phone":        "+234801234567",
 *   "password":     "<bcrypt>",
 *   "api_token":    "<sha256 hex>",     ← used by mobile app (Bearer token)
 *   "wallet":       1500.00,
 *   "is_active":    true,
 *   "is_admin":     false,
 *   "created_at":   "2024-01-01 10:00:00"
 * }
 */
class RetailerAuth
{
    private  $store;
    private const SESSION_KEY = 'kyc_retailer';

    public function __construct( $store)
    {
        $this->store = $store;
    }

    // ══════════════════════════════════════════════════════════════════════
    // WEB SESSION AUTH
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Attempt login, start session.
     * Accepts either email address OR mobile phone number as the identifier.
     * Returns retailer array or null.
     */
    public function webLogin(string $identifier, string $password): ?array
    {
        $identifier = trim($identifier);

        // Try email first; fall back to phone lookup
        $retailer = $this->store->findOne('retailers.json', 'email', strtolower($identifier));
        if (!$retailer) {
            // Normalise phone: strip spaces/dashes, keep leading +
            $phone = preg_replace('/[\s\-\(\)]+/', '', $identifier);
            $all   = $this->store->load('retailers.json');
            foreach ($all as $r) {
                $rPhone = preg_replace('/[\s\-\(\)]+/', '', $r['phone'] ?? '');
                if ($rPhone !== '' && $rPhone === $phone) { $retailer = $r; break; }
            }
        }

        if (!$retailer || !($retailer['is_active'] ?? false)) return null;
        if (!password_verify($password, $retailer['password'])) return null;

        // CRIT-04 FIX: Regenerate session ID on login to prevent session fixation.
        // An attacker who plants a known session ID cookie (e.g. over HTTP before HTTPS
        // redirect) can take over the session after the victim logs in. Regenerating
        // the ID invalidates any externally-known session ID immediately.
        session_regenerate_id(false); // FIX-4: was true — race condition on slow servers deleted old file before browser got new ID

        $_SESSION[self::SESSION_KEY] = [
            'id'              => $retailer['id'],
            'name'            => $retailer['name'],
            'email'           => $retailer['email'],
            'is_admin'        => $retailer['is_admin'] ?? false,
            'is_field_agent'  => $retailer['is_field_agent'] ?? false,
            'role'            => $retailer['role'] ?? 'sales',
            'must_change_pwd' => (bool)($retailer['must_change_pwd'] ?? false),
            'logged_in_at'    => time(),
            'cached_record'   => $retailer, // FIX-2: full record cached — avoids DB hit on every navigation
            'cache_refreshed' => time(),    // FIX-2: tracks when we last refreshed from DB
        ];

        // ── Record login session ──────────────────────────────────────────
        $ip  = $_SERVER['HTTP_X_FORWARDED_FOR']
             ?? $_SERVER['HTTP_X_REAL_IP']
             ?? $_SERVER['REMOTE_ADDR']
             ?? '0.0.0.0';
        $ip  = trim(explode(',', $ip)[0]); // take first IP if forwarded list
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Simple device detection from User-Agent
        $device = 'Desktop';
        if (preg_match('/Android/i', $ua))        $device = 'Android';
        elseif (preg_match('/iPhone|iPad/i', $ua)) $device = 'iPhone/iPad';
        elseif (preg_match('/Mobile/i', $ua))      $device = 'Mobile';

        $browser = 'Unknown';
        if (preg_match('/Chrome\/(\d+)/i', $ua, $m))      $browser = 'Chrome '.$m[1];
        elseif (preg_match('/Firefox\/(\d+)/i', $ua, $m)) $browser = 'Firefox '.$m[1];
        elseif (preg_match('/Safari\/(\d+)/i', $ua, $m))  $browser = 'Safari';
        elseif (preg_match('/MSIE|Trident/i', $ua))       $browser = 'IE';

        $session = [
            'retailer_id'   => $retailer['id'],
            'name'          => $retailer['name'],
            'email'         => $retailer['email'],
            'role'          => $retailer['role'] ?? 'sales',
            'ip'            => $ip,
            'device'        => $device,
            'browser'       => $browser,
            'user_agent'    => substr($ua, 0, 200),
            'logged_in_at'  => date('Y-m-d H:i:s'),
            'status'        => 'success',
        ];
        $this->store->appendWithId('login_sessions.json', $session);

        // B-06 FIX: Use updateOne() (atomic, O(1) indexed write) instead of
        // load() + loop + save() which was an unguarded full-file rewrite that
        // could overwrite concurrent writes from KYC submissions or wallet ops.
        $this->store->updateOne('retailers.json', 'id', (int)$retailer['id'], [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
            'last_device'   => $device,
        ]);

        return $retailer;
    }

    public function webLogout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** Cache TTL for session-stored retailer record (seconds). */
    private const SESSION_CACHE_TTL = 300; // 5 minutes

    /**
     * Get currently logged-in retailer.
     * FIX-2: Cache-first — returns session-cached record for up to 5 min,
     * only hitting DB to refresh. Falls back to cache on DB failure instead
     * of logging the user out (avoids logout under SQLite write contention).
     */
    public function currentRetailer(): ?array
    {
        if (empty($_SESSION[self::SESSION_KEY])) return null;
        $sess = $_SESSION[self::SESSION_KEY];

        // Fast path — cached record is still fresh
        $cacheAge = time() - (int)($sess['cache_refreshed'] ?? 0);
        if ($cacheAge < self::SESSION_CACHE_TTL && !empty($sess['cached_record'])) {
            return $sess['cached_record'];
        }

        // Time to refresh — fetch from DB
        try {
            $fresh = $this->store->findOne('retailers.json', 'id', $sess['id']);
        } catch (\Throwable $e) {
            $fresh = null;
            error_log('RetailerAuth: DB refresh failed — using cached session: ' . $e->getMessage());
        }

        if ($fresh !== null) {
            // Account deactivated — force logout
            if (!($fresh['is_active'] ?? true)) {
                $this->webLogout();
                return null;
            }
            // Update cache
            $_SESSION[self::SESSION_KEY]['cached_record']   = $fresh;
            $_SESSION[self::SESSION_KEY]['cache_refreshed'] = time();
            return $fresh;
        }

        // DB returned null — fall back to cached copy to avoid logout on transient lock
        if (!empty($sess['cached_record'])) {
            error_log('RetailerAuth: DB returned null for id=' . $sess['id'] . ' — using session cache');
            return $sess['cached_record'];
        }

        return null; // no cache, no DB — genuine logout
    }

    public function requireLogin(): array
    {
        $r = $this->currentRetailer();
        if (!$r) {
            header('Location: ?page=login');
            exit;
        }
        return $r;
    }

    public function requireAdmin(): array
    {
        $r = $this->requireLogin();
        if (!($r['is_admin'] ?? false)) {
            http_response_code(403);
            die('<p style="color:red;padding:20px;">Access denied — admin only.</p>');
        }
        return $r;
    }

    /**
     * Require the logged-in retailer to be an accountant OR admin.
     * Rupesh (admin) passes automatically; a dedicated accountant role also passes.
     */
    public function requireAccountant(): array
    {
        $r = $this->requireLogin();
        $role = $r['role'] ?? 'sales';
        if (!($r['is_admin'] ?? false) && $role !== 'accountant') {
            http_response_code(403);
            die('<p style="color:red;padding:20px;">Access denied — accountant access required.</p>');
        }
        return $r;
    }

    // ══════════════════════════════════════════════════════════════════════
    // MOBILE API TOKEN AUTH
    // ══════════════════════════════════════════════════════════════════════

    /** Verify Bearer token from Authorization header. Returns retailer or null. */
    public function tokenAuth(): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
               ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
               ?? '';

        if (!$header && function_exists('apache_request_headers')) {
            $h = apache_request_headers();
            $header = $h['Authorization'] ?? $h['authorization'] ?? '';
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) return null;

        $token = trim($m[1]);
        return $this->findByToken($token);
    }

    // CRIT-05 FIX: Token expiry — 90-day TTL, hard-invalidated on logout/password-change.
    private const TOKEN_TTL_DAYS = 90;

    public function findByToken(string $token): ?array
    {
        $retailer = $this->store->findOne('retailers.json', 'api_token', $token);
        if (!$retailer || !($retailer['is_active'] ?? false)) return null;

        // Enforce token expiry
        $issuedAt = (int)($retailer['token_issued_at'] ?? 0);
        if ($issuedAt > 0 && (time() - $issuedAt) > (self::TOKEN_TTL_DAYS * 86400)) {
            // Token expired — invalidate it and deny access
            $this->store->updateOne('retailers.json', 'id', $retailer['id'], [
                'api_token'       => null,
                'token_issued_at' => null,
            ]);
            return null;
        }

        return $retailer;
    }

    /** Generate a new API token for a retailer and persist it. Token is valid for 90 days. */
    public function regenerateToken(int $retailerId): string
    {
        $token = bin2hex(random_bytes(32)); // 64 char hex
        $this->store->updateOne('retailers.json', 'id', $retailerId, [
            'api_token'       => $token,
            'token_issued_at' => time(),
        ]);
        return $token;
    }

    /** Invalidate the current API token (logout from mobile app). */
    public function revokeToken(int $retailerId): void
    {
        $this->store->updateOne('retailers.json', 'id', $retailerId, [
            'api_token'       => null,
            'token_issued_at' => null,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // RETAILER MANAGEMENT
    // ══════════════════════════════════════════════════════════════════════

    public function createRetailer(array $data): array
    {
        $id    = $this->store->nextId('retailers.json');
        $token = bin2hex(random_bytes(32));

        $password = $data['password'] ?? '123456';
        $record = [
            'id'               => $id,
            'name'             => trim($data['name']),
            'email'            => strtolower(trim($data['email'])),
            'phone'            => trim($data['phone'] ?? ''),
            'password'         => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'api_token'        => $token,
            'token_issued_at'  => time(),
            'wallet'           => (float)($data['wallet'] ?? 0),
            'is_active'        => true,
            'is_admin'         => (bool)($data['is_admin'] ?? false),
            'is_field_agent'   => (bool)($data['is_field_agent'] ?? false),
            'role'             => $data['role'] ?? 'sales',
            'is_employee'      => (bool)($data['is_employee']      ?? true),
            'commission_type'  => $data['commission_type']  ?? 'none', // none | percent | flat
            'commission_rate'  => (float)($data['commission_rate']  ?? 0),
            'must_change_pwd'  => (bool)($data['must_change_pwd']   ?? ($password === '123456')),
            'created_at'       => date('Y-m-d H:i:s'),
        ];

        $this->store->append('retailers.json', $record);
        return $record;
    }

    public function getAllRetailers(): array
    {
        // v4.11.3 PERF: Request-level static cache — retailers.json loaded once per request max
        static $_allCache = null;
        if ($_allCache !== null) return $_allCache;
        $_allCache = array_map(function($r) { return $this->safeRetailer($r); }, $this->store->load('retailers.json'));
        return $_allCache;
    }

    public function getRetailerById(int $id): ?array
    {
        $r = $this->store->findOne('retailers.json', 'id', $id);
        return $r ? $this->safeRetailer($r) : null;
    }

    /**
     * Verify a retailer's current password.
     */
    public function verifyPassword(int $id, string $password): bool
    {
        $r = $this->store->findOne('retailers.json', 'id', $id);
        if (!$r || empty($r['password'])) return false;
        return password_verify($password, $r['password']);
    }

    /**
     * Update a retailer record.
     * @param bool $callerIsAdmin  Pass true only from admin-gated handlers.
     *                              Prevents non-admin callers from escalating privileges.
     */
    public function updateRetailer(int $id, array $updates, bool $callerIsAdmin = false): bool
    {
        if (isset($updates['password']) && $updates['password']) {
            $updates['password'] = password_hash($updates['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            // CRIT-05: Rotate token on password change
            $updates['api_token']       = bin2hex(random_bytes(32));
            $updates['token_issued_at'] = time();
            // Clear forced password change flag once password is updated
            $updates['must_change_pwd'] = false;
        } else {
            unset($updates['password']);
        }

        // MED-07 FIX: Strip privilege-escalation fields unless caller is admin.
        // A sales agent could POST is_admin=1 to upgrade themselves without this guard.
        if (!$callerIsAdmin) {
            unset($updates['is_admin'], $updates['role'], $updates['is_field_agent'],
                  $updates['api_token'], $updates['token_issued_at'], $updates['wallet']);
        }

        return $this->store->updateOne('retailers.json', 'id', $id, $updates);
    }

    /** Strip sensitive fields for output */
    public function safeRetailer(array $r): array
    {
        unset($r['password']);
        return $r;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PASSWORD RESET VIA WHATSAPP
    // ══════════════════════════════════════════════════════════════════════

    private const RESET_TTL_MINUTES = 30;

    /**
     * Find retailer by phone number (normalised).
     */
    public function findByPhone(string $phone): ?array
    {
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);
        if (empty($phone)) return null;
        $all = $this->store->load('retailers.json');
        foreach ($all as $r) {
            $rPhone = preg_replace('/[\s\-\(\)]+/', '', $r['phone'] ?? '');
            if ($rPhone !== '' && $rPhone === $phone) return $r;
        }
        return null;
    }

    /**
     * Generate a secure reset token, persist it. Valid for RESET_TTL_MINUTES.
     */
    public function createResetToken(int $retailerId): string
    {
        $token = bin2hex(random_bytes(24)); // 48-char hex
        $this->store->updateOne('retailers.json', 'id', $retailerId, [
            'pwd_reset_token'      => $token,
            'pwd_reset_expires_at' => time() + (self::RESET_TTL_MINUTES * 60),
        ]);
        return $token;
    }

    /**
     * Validate a reset token. Returns retailer if valid & not expired.
     */
    public function findByResetToken(string $token): ?array
    {
        if (empty($token)) return null;
        $all = $this->store->load('retailers.json');
        foreach ($all as $r) {
            if (($r['pwd_reset_token'] ?? '') === $token) {
                if ((int)($r['pwd_reset_expires_at'] ?? 0) < time()) return null;
                return $r;
            }
        }
        return null;
    }

    /**
     * Consume a reset token: apply new password, clear token, rotate API key.
     */
    public function consumeResetToken(string $token, string $newPassword): bool
    {
        $retailer = $this->findByResetToken($token);
        if (!$retailer) return false;
        $this->store->updateOne('retailers.json', 'id', (int)$retailer['id'], [
            'password'             => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
            'pwd_reset_token'      => null,
            'pwd_reset_expires_at' => null,
            'must_change_pwd'      => false,
            'api_token'            => bin2hex(random_bytes(32)),
            'token_issued_at'      => time(),
        ]);
        return true;
    }
}
