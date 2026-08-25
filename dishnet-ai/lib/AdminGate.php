<?php
declare(strict_types=1);

/**
 * AdminGate — protects the plugin page's write actions.
 *
 * uCRM does NOT authenticate public.php. The plugin documentation is explicit:
 * files served from a plugin's public URL are reachable "without any
 * authentication". So anything on that page that changes configuration, calls
 * Evolution, or reveals settings has to gate itself.
 *
 * The gate is a token the admin sets on the uCRM Configuration screen — which
 * IS behind uCRM's admin login. Entering it here once per browser session
 * unlocks the Setup tab. That keeps every real secret in the place uCRM already
 * protects, while the day-to-day operations live where the feedback is.
 *
 * Deliberately NOT a user database: no accounts, no password reset, no email.
 * One token, rotated by editing the uCRM settings form.
 *
 * PHP 7.4 compatible.
 */
class AdminGate
{
    const SESSION_KEY   = 'dishnet_ai_admin';
    const MAX_ATTEMPTS  = 5;
    const LOCKOUT_SECS  = 900;   // 15 minutes
    const SESSION_TTL   = 43200; // 12 hours

    private string $token;
    private string $stateFile;

    public function __construct(array $config, string $dataDir)
    {
        $this->token     = trim((string)($config['admin_token'] ?? ''));
        $this->stateFile = $dataDir . '/admin_gate.json';
    }

    /** No token configured means the Setup tab simply does not open. */
    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        // Cookie parameters can only be set before output begins. Callers
        // should have started the session at the top of the request; if output
        // is already underway, start it without touching the cookie params
        // rather than emitting a warning into the middle of the page.
        if (headers_sent()) {
            @session_start();
            return;
        }

        // The page runs in an iframe on the same origin as uCRM, so Lax is
        // sufficient and avoids requiring a valid certificate for None.
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => !empty($_SERVER['HTTPS']),
            ]);
        } else {
            session_set_cookie_params(0, '/', '', !empty($_SERVER['HTTPS']), true);
        }
        @session_start();
    }

    public function isUnlocked(): bool
    {
        if (!$this->isConfigured()) return false;
        $this->startSession();

        $at = (int)($_SESSION[self::SESSION_KEY]['at'] ?? 0);
        if ($at <= 0) return false;

        if (time() - $at > self::SESSION_TTL) {
            unset($_SESSION[self::SESSION_KEY]);
            return false;
        }
        // Rotating the token in uCRM invalidates open sessions immediately.
        $fp = (string)($_SESSION[self::SESSION_KEY]['fp'] ?? '');
        return $fp !== '' && hash_equals($this->fingerprint(), $fp);
    }

    /**
     * Try to unlock. Returns [ok, message].
     * Rate limited by IP-independent counter: this page has one admin, and a
     * per-IP counter would just invite rotation.
     */
    public function attemptUnlock(string $presented): array
    {
        if (!$this->isConfigured()) {
            return [false, 'No admin token is set. Add one on the uCRM Configuration screen first.'];
        }

        $state = $this->readState();
        $now   = time();

        if ($state['locked_until'] > $now) {
            $mins = (int)ceil(($state['locked_until'] - $now) / 60);
            return [false, "Too many attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.'];
        }

        // Reset the counter once the window has passed.
        if ($now - $state['first_attempt_at'] > self::LOCKOUT_SECS) {
            $state['attempts'] = 0;
            $state['first_attempt_at'] = $now;
        }

        if ($presented === '' || !hash_equals($this->token, $presented)) {
            $state['attempts']++;
            if ($state['first_attempt_at'] === 0) $state['first_attempt_at'] = $now;
            if ($state['attempts'] >= self::MAX_ATTEMPTS) {
                $state['locked_until'] = $now + self::LOCKOUT_SECS;
                $state['attempts']     = 0;
            }
            $this->writeState($state);
            error_log('[AdminGate] failed unlock from ' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
            return [false, 'Incorrect token.'];
        }

        $this->writeState(['attempts' => 0, 'first_attempt_at' => 0, 'locked_until' => 0]);
        $this->startSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = ['at' => $now, 'fp' => $this->fingerprint()];
        return [true, ''];
    }

    public function lock(): void
    {
        $this->startSession();
        unset($_SESSION[self::SESSION_KEY]);
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    public function csrfToken(): string
    {
        $this->startSession();
        if (empty($_SESSION['dishnet_ai_csrf'])) {
            $_SESSION['dishnet_ai_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['dishnet_ai_csrf'];
    }

    public function checkCsrf(string $presented): bool
    {
        $this->startSession();
        $expected = (string)($_SESSION['dishnet_ai_csrf'] ?? '');
        return $expected !== '' && hash_equals($expected, $presented);
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="'
             . htmlspecialchars($this->csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** Ties a session to the current token without storing the token. */
    private function fingerprint(): string
    {
        return hash('sha256', 'dishnet-ai-gate|' . $this->token);
    }

    private function readState(): array
    {
        $default = ['attempts' => 0, 'first_attempt_at' => 0, 'locked_until' => 0];
        if (!is_file($this->stateFile)) return $default;
        $d = json_decode((string)@file_get_contents($this->stateFile), true);
        return is_array($d) ? array_merge($default, $d) : $default;
    }

    private function writeState(array $state): void
    {
        @file_put_contents($this->stateFile, json_encode($state), LOCK_EX);
        @chmod($this->stateFile, 0600);
    }
}
