<?php
// ═══════════════════════════════════════════════════════════════════
// CUSTOMER APP API  (app_* actions)
// ═══════════════════════════════════════════════════════════════════
// Endpoints for the DishNet customer mobile app (Kotlin/Compose).
//
// Routing: all requests hit /public.php?page=api&action=app_*
// Auth:    OTP via WASender (NotificationService SUPPORT sender)
//          → issues JWT (JwtAuth) with sub=crm_client_id, kind='app'
//          → Bearer token on all protected endpoints
//
// Data:    reads client_search_index.json, ucrm_services_cache.json,
//          ucrm_invoices_cache.json — no live UCRM API calls on hot path
//
// Tables:  all new tables prefixed app_*
//            app_otp_pending   — active OTP codes
//            app_otp_rate      — rate-limit ledger (sends per phone per hour)
//            app_jwt_blacklist — invalidated tokens (for logout)
//            app_audit_log     — auth events
//
// Pre-auth actions: app_send_otp, app_verify_otp, app_health_app
// Bearer-auth actions: app_me, app_plan, app_usage, app_invoices, app_invoice, app_logout
// ═══════════════════════════════════════════════════════════════════

// ── Bootstrap auxiliary classes ─────────────────────────────────────
require_once dirname(__DIR__, 2) . '/lib/JwtAuth.php';

// ── Helpers (scoped with ca_ prefix to avoid collisions) ────────────

/**
 * Normalize phone to canonical form: last 9 digits (SS local number).
 * Handles all variants: "+211 92 345 6789", "0923456789", "923456789", etc.
 */
if (!function_exists('ca_phone_normalize')) {
    function ca_phone_normalize($raw) {
        $digits = preg_replace('/[^0-9]/', '', (string)$raw);
        $digits = ltrim($digits, '0');
        return substr($digits, -9);
    }
}

/**
 * International form with + prefix for WA delivery.
 */
if (!function_exists('ca_phone_intl')) {
    function ca_phone_intl($raw) {
        $digits = preg_replace('/[^0-9]/', '', (string)$raw);
        $digits = ltrim($digits, '0');
        if (strlen($digits) >= 11 && substr($digits, 0, 3) === '211') {
            return '+' . $digits;
        }
        return '+211' . ca_phone_normalize($raw);
    }
}

/**
 * Auto-create app_* tables on first use. Idempotent.
 */
if (!function_exists('ca_init_tables')) {
    function ca_init_tables($pdo) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_otp_pending (
                phone TEXT PRIMARY KEY,
                code TEXT NOT NULL,
                expires_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                attempts INTEGER DEFAULT 0,
                crm_client_id INTEGER
            );
            CREATE TABLE IF NOT EXISTS app_otp_rate (
                phone TEXT NOT NULL,
                sent_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_app_otp_rate_phone ON app_otp_rate(phone, sent_at);

            CREATE TABLE IF NOT EXISTS app_jwt_blacklist (
                jti TEXT PRIMARY KEY,
                expires_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS app_audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                crm_client_id INTEGER,
                action TEXT NOT NULL,
                phone TEXT,
                ip TEXT,
                details TEXT,
                at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS app_fcm_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                crm_client_id INTEGER NOT NULL,
                token TEXT NOT NULL UNIQUE,
                platform TEXT DEFAULT 'android',
                registered_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_fcm_client ON app_fcm_tokens(crm_client_id);

            CREATE TABLE IF NOT EXISTS app_push_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                crm_client_id INTEGER NOT NULL,
                event TEXT NOT NULL,
                title TEXT,
                body TEXT,
                success INTEGER DEFAULT 0,
                error TEXT,
                sent_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS app_wifi_cache (
                router_id TEXT NOT NULL,
                crm_client_id INTEGER NOT NULL,
                kit_number TEXT,
                ssid TEXT,
                password TEXT,
                ssid_5ghz TEXT,
                password_5ghz TEXT,
                source TEXT DEFAULT 'change',
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (router_id)
            );
        ");
        // v4.12.14 — cooldown for WA document sends (invoices/receipts) to prevent spam
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_wa_send_cooldown (
                key TEXT PRIMARY KEY,
                last_sent_at INTEGER NOT NULL
            );
        ");
        // v4.12.19 — customer acceptance of Terms of Service and Privacy Policy.
        // Keyed by phone + version so we can tell who has accepted which doc version
        // and re-prompt only when a version bumps. ip captured for audit trail only;
        // not used for display.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_tos_consent (
                phone TEXT NOT NULL,
                tos_version TEXT NOT NULL,
                privacy_version TEXT NOT NULL,
                accepted_at INTEGER NOT NULL,
                ip TEXT,
                crm_client_id INTEGER,
                PRIMARY KEY (phone, tos_version, privacy_version)
            );
            CREATE INDEX IF NOT EXISTS idx_consent_phone ON app_tos_consent(phone);
        ");
        // v4.12.31 — rate-limit log for the live dish-status refresh path
        // (app_site_diagnostics auto-fetch + app_site_refresh manual). One row
        // per live fetch attempt; the most recent row per (client_id, kit_number)
        // decides whether the 5-min rate limit allows a new live call. See
        // migrations/056_app_site_refresh_log.sql for the authoritative schema.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_site_refresh_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                kit_number TEXT NOT NULL,
                router_id TEXT NOT NULL DEFAULT '',
                outcome TEXT NOT NULL,
                fetched_at TEXT NOT NULL DEFAULT (datetime('now')),
                trigger_kind TEXT NOT NULL DEFAULT 'auto'
            );
            CREATE INDEX IF NOT EXISTS idx_asrl_client_kit ON app_site_refresh_log(client_id, kit_number, fetched_at);
            CREATE INDEX IF NOT EXISTS idx_asrl_fetched_at ON app_site_refresh_log(fetched_at);
        ");
        // v4.19.0 — hotspot device sighting log. One row per fingerprint per
        // router. Lets the dashboard tell the customer "this device is new"
        // (never been on this Wi-Fi before) vs "you've seen this one for
        // weeks." Customer can acknowledge a device to clear the NEW badge.
        // hostname_last keeps the most recent self-reported name for diff
        // tracking (e.g. someone connects, then renames their phone).
        // v4.20.0 — added ip_last and ip_history (JSON array of last 5 IPs)
        // and hostname_history (JSON array of last 5 hostnames) for forensic
        // context. Helps operators see "this device's identifiers are
        // changing rapidly" — does NOT claim to defeat MAC spoofing, but
        // gives real data for investigation.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS hotspot_seen_devices (
                router_id TEXT NOT NULL,
                fingerprint TEXT NOT NULL,
                crm_client_id INTEGER NOT NULL,
                first_seen_at INTEGER NOT NULL,
                last_seen_at INTEGER NOT NULL,
                hostname_last TEXT,
                hostname_history TEXT,
                ip_last TEXT,
                ip_history TEXT,
                acknowledged_at INTEGER,
                ack_label TEXT,
                notes TEXT,
                PRIMARY KEY (router_id, fingerprint)
            );
            CREATE INDEX IF NOT EXISTS idx_hsd_router ON hotspot_seen_devices(router_id);
            CREATE INDEX IF NOT EXISTS idx_hsd_client ON hotspot_seen_devices(crm_client_id);
            CREATE INDEX IF NOT EXISTS idx_hsd_first_seen ON hotspot_seen_devices(first_seen_at);
        ");
        // v4.20.0 — schema migration: add IP and history columns to existing
        // installs. SQLite's ALTER TABLE ADD COLUMN is idempotent only via
        // try/catch — the columns already exist on fresh installs.
        $hsdMigrations = [
            "ALTER TABLE hotspot_seen_devices ADD COLUMN ip_last TEXT",
            "ALTER TABLE hotspot_seen_devices ADD COLUMN ip_history TEXT",
            "ALTER TABLE hotspot_seen_devices ADD COLUMN hostname_history TEXT",
        ];
        foreach ($hsdMigrations as $migrSql) {
            try { $pdo->exec($migrSql); } catch (\Throwable $e) { /* column exists */ }
        }

        // v4.20.0 — time-based access grants. One row per "you have N hours
        // of internet" grant. Cron polls routers with at least one active
        // grant (lazy polling — we don't touch routers without timers) and
        // pauses devices whose expires_at has passed.
        //
        // amount_ssp / amount_usd are operator-typed cash records, not
        // payment integration. note is free-text for "Customer #4 / Table 7"
        // style operator labels.
        //
        // status transitions:
        //   active   → granted, timer running
        //   expired  → cron paused the device (terminal)
        //   extended → superseded by a new active grant on same fingerprint
        //   revoked  → operator cut it short (terminal)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS hotspot_paid_access (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                router_id TEXT NOT NULL,
                fingerprint TEXT NOT NULL,
                crm_client_id INTEGER NOT NULL,
                started_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                amount_ssp REAL,
                amount_usd REAL,
                note TEXT,
                created_by TEXT NOT NULL DEFAULT 'customer',
                created_at INTEGER NOT NULL,
                expired_at INTEGER,
                revoked_at INTEGER
            );
            CREATE INDEX IF NOT EXISTS idx_hpa_active ON hotspot_paid_access(status, expires_at);
            CREATE INDEX IF NOT EXISTS idx_hpa_router_fp ON hotspot_paid_access(router_id, fingerprint);
            CREATE INDEX IF NOT EXISTS idx_hpa_client ON hotspot_paid_access(crm_client_id);
        ");

        // v4.20.0 — session log. Each paid_access grant produces one session
        // row. Lets us answer 'how many minutes did device X get this week'
        // and 'how much SSP did this router collect today' without scanning
        // paid_access for date ranges.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS hotspot_session_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                router_id TEXT NOT NULL,
                fingerprint TEXT NOT NULL,
                hostname_at_start TEXT,
                session_start INTEGER NOT NULL,
                session_end INTEGER,
                total_minutes INTEGER,
                linked_paid_id INTEGER,
                amount_ssp REAL,
                amount_usd REAL
            );
            CREATE INDEX IF NOT EXISTS idx_hsl_router_start ON hotspot_session_log(router_id, session_start);
            CREATE INDEX IF NOT EXISTS idx_hsl_paid ON hotspot_session_log(linked_paid_id);
        ");
    }
}

/**
 * Find a CRM client by phone using client_search_index.json (fast, indexed).
 * Returns the client row from the index or null.
 */
if (!function_exists('ca_find_client_by_phone')) {
    function ca_find_client_by_phone($store, $rawPhone) {
        $needle = ca_phone_normalize($rawPhone);
        if (strlen($needle) < 8) return null;

        $idx = $store->load('client_search_index.json') ?? [];
        foreach ($idx as $row) {
            $candidate = ca_phone_normalize($row['phone'] ?? '');
            if ($candidate !== '' && $candidate === $needle) {
                return $row;
            }
        }
        return null;
    }
}

/**
 * Audit an event.
 */
if (!function_exists('ca_audit')) {
    function ca_audit($pdo, $clientId, $action, $phone = null, $details = null) {
        try {
            // v4.21.20: capture real client IP via the getClientIp() helper
            // (lib/bootstrap_data.php). UCRM is behind a Docker reverse proxy,
            // so REMOTE_ADDR is always an internal 172.x address.
            $stmt = $pdo->prepare("
                INSERT INTO app_audit_log (crm_client_id, action, phone, ip, details, at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $clientId, $action, $phone,
                function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? ''),
                is_array($details) ? json_encode($details) : $details,
                time(),
            ]);
        } catch (\Throwable $e) {
            // Audit failure should never break auth flow
            error_log('[app] audit failed: ' . $e->getMessage());
        }
    }
}

/**
 * Verify a customer-app Bearer JWT. Returns claims array or exits with 401.
 */
if (!function_exists('ca_require_auth')) {
    function ca_require_auth($config, $pdo, $er2) {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (empty($hdr) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $rawToken = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) {
            $rawToken = trim($m[1]);
        }
        // v4.12.14 — allow ?token= query param fallback so that direct-link PDF
        // downloads (opened in a new tab / system viewer) don't need a Bearer
        // header. Only accepted for GET because body-based endpoints always
        // have their auth header. Limited to the same customer-app kind via
        // the kind check below.
        if ($rawToken === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_GET['token'])) {
            $rawToken = trim($_GET['token']);
        }
        if ($rawToken === '') {
            $er2('Missing Bearer token.', 401);
        }
        try {
            $jwt = JwtAuth::fromConfig($config);
            $claims = $jwt->verify($rawToken);
        } catch (\RuntimeException $e) {
            $er2('Invalid or expired token.', 401);
        }

        // Must be a customer-app token (kind='app'), not a retailer token
        if (($claims['kind'] ?? '') !== 'app') {
            $er2('Wrong token type.', 401);
        }

        // Check blacklist
        if (!empty($claims['jti'])) {
            $stmt = $pdo->prepare("SELECT 1 FROM app_jwt_blacklist WHERE jti = ? LIMIT 1");
            $stmt->execute([$claims['jti']]);
            if ($stmt->fetchColumn()) {
                $er2('Token revoked.', 401);
            }
        }

        return $claims;
    }
}

/**
 * v4.12.13 — Multi-account resolution.
 *
 * Customers with the same phone on multiple CRM clients get a JWT whose `accounts`
 * claim lists all of them. Endpoints call ca_resolve_active_client_id() instead of
 * reading $claims['sub'] directly. The client chooses which account is active via
 * the X-Account-Id header (or ?account_id= query param) on every request.
 *
 * If no header is provided, falls back to sub (primary account). If the header
 * is set but doesn't match any account in the JWT's accounts claim, returns 403
 * — the JWT only grants access to the phone-scoped set.
 *
 * @return int client_id of the resolved active account
 */
if (!function_exists('ca_resolve_active_client_id')) {
    function ca_resolve_active_client_id(array $claims, $er2): int {
        $sub = (int)($claims['sub'] ?? 0);
        if ($sub <= 0) $er2('Invalid token (no sub).', 401);

        // Explicit account selection via header (preferred) or query param
        $requested = 0;
        $hdr = $_SERVER['HTTP_X_ACCOUNT_ID'] ?? '';
        if ($hdr !== '') $requested = (int)$hdr;
        elseif (!empty($_GET['account_id'])) $requested = (int)$_GET['account_id'];

        if ($requested <= 0) return $sub;
        if ($requested === $sub) return $sub;

        // Must be in the JWT's accounts allow-list
        $allowed = [];
        foreach (($claims['accounts'] ?? []) as $a) {
            $aid = (int)($a['id'] ?? 0);
            if ($aid > 0) $allowed[] = $aid;
        }
        if (in_array($requested, $allowed, true)) return $requested;

        $er2('Not authorized for that account.', 403);
        return $sub; // unreachable — $er2 exits
    }
}

/**
 * v4.12.13 — Find ALL CRM clients with a matching phone (not just the first).
 * Returns array of client rows from client_search_index.json.
 * Empty array if nothing matches.
 */
if (!function_exists('ca_find_clients_by_phone')) {
    function ca_find_clients_by_phone($store, $rawPhone): array {
        $needle = ca_phone_normalize($rawPhone);
        if (strlen($needle) < 8) return [];

        $idx = $store->load('client_search_index.json') ?? [];
        $matches = [];
        foreach ($idx as $row) {
            $candidate = ca_phone_normalize($row['phone'] ?? '');
            if ($candidate !== '' && $candidate === $needle) {
                $matches[] = $row;
            }
        }
        // Sort: active first, then by id ascending (stable "primary" = lowest active id)
        usort($matches, function ($a, $b) {
            $aActive = strtolower($a['status'] ?? '') === 'active' ? 0 : 1;
            $bActive = strtolower($b['status'] ?? '') === 'active' ? 0 : 1;
            if ($aActive !== $bActive) return $aActive - $bActive;
            return (int)($a['id'] ?? 0) - (int)($b['id'] ?? 0);
        });
        return $matches;
    }
}

// v4.21.7: Email-based lookup mirroring phone-based one. Used when
// customer logs in via the Email tab. Returns matching CRM clients
// from client_search_index.json (refreshed daily + on every client.edit
// webhook, so up-to-date with admin edits in CRM).
//
// Email matching is case-insensitive and ignores leading/trailing
// whitespace. Same multi-account treatment as phone — if multiple
// CRM clients share the email (e.g. shared NGO inbox), all are
// returned, primary is lowest-active-id.
if (!function_exists('ca_find_clients_by_email')) {
    function ca_find_clients_by_email($store, string $rawEmail): array {
        $needle = strtolower(trim($rawEmail));
        if ($needle === '' || strpos($needle, '@') === false) return [];

        $idx = $store->load('client_search_index.json') ?? [];
        $matches = [];
        foreach ($idx as $row) {
            $candidate = strtolower(trim((string)($row['email'] ?? '')));
            if ($candidate !== '' && $candidate === $needle) {
                $matches[] = $row;
            }
        }
        usort($matches, function ($a, $b) {
            $aActive = strtolower($a['status'] ?? '') === 'active' ? 0 : 1;
            $bActive = strtolower($b['status'] ?? '') === 'active' ? 0 : 1;
            if ($aActive !== $bActive) return $aActive - $bActive;
            return (int)($a['id'] ?? 0) - (int)($b['id'] ?? 0);
        });
        return $matches;
    }
}

// v4.21.8: Send OTP via email — now a thin wrapper around MailService
// (UCRM SMTP) + OtpEmailTemplate (branded HTML). Plugin-level smtp_*
// config keys are no longer read; UCRM mailer settings are the single
// source of truth. If UCRM SMTP isn't configured, send fails with a
// clear error pointing admin to UCRM Admin > System > Settings > Mailer.
//
// Returns ['ok' => bool, 'error' => string]. Caller decides whether
// failure is fatal (currently: log + audit but DON'T fail the whole
// OTP request — WhatsApp succeeded, email is a bonus channel).
if (!function_exists('ca_send_otp_email')) {
    function ca_send_otp_email(array $config, string $dataDir, string $toEmail, string $name, string $code, int $ttlMinutes): array {
        require_once dirname(__DIR__, 2) . '/lib/MailService.php';
        require_once dirname(__DIR__, 2) . '/lib/OtpEmailTemplate.php';

        $firstName = explode(' ', trim($name))[0] ?: '';
        $subject = OtpEmailTemplate::subject($code);
        $html    = OtpEmailTemplate::html($firstName, $code, $ttlMinutes);
        $text    = OtpEmailTemplate::text($firstName, $code, $ttlMinutes);

        $mailer = new MailService($dataDir);
        $result = $mailer->send($toEmail, $name, $subject, $html, $text, [
            'Reply-To' => 'support@dishnetafrica.com',
        ]);

        if (!empty($result['ok'])) {
            return ['ok' => true, 'error' => ''];
        }
        return [
            'ok'    => false,
            'error' => $result['error'] ?? 'Unknown SMTP error',
            'log'   => $result['log'] ?? [],
        ];
    }
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_health_app  (public — no auth)
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_health_app' && $met === 'GET') {
    $ok2([
        'plugin'  => 'dishnet-hybrid-telecom',
        'feature' => 'customer-app-api',
        'version' => 'v4.11.45+debug',
        'time'    => gmdate('c'),
    ], 'Customer App API is alive.');
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_debug_lookup  (public — no auth)
// GET ?page=api&action=app_debug_lookup&phone=%2B211923456789
//
// Diagnostic tool — tells you EXACTLY what happens when a phone tries
// to log in. Safe to call, doesn't send anything. Returns:
//   - does phone match a CRM client (using fuzzy last-9-digits match)?
//   - what client id?
//   - how many OTPs sent in last hour (rate limit)?
//   - is WASender configured (app_key/auth_key/url set)?
//   - is dry-run mode on?
//   - is the phone cache built / fresh?
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_debug_lookup' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $rawPhone = trim($_GET['phone'] ?? '');
    $normalized = ca_phone_normalize($rawPhone);
    $phoneIntl = ca_phone_intl($rawPhone);

    // Check client index
    $indexSize = 0;
    $indexSample = null;
    $matchedClient = null;
    try {
        $idx = $store->load('client_search_index.json') ?? [];
        $indexSize = count($idx);
        // Sample: first entry
        if (!empty($idx)) {
            $indexSample = [
                'id'    => $idx[0]['id'] ?? null,
                'name'  => $idx[0]['name'] ?? null,
                'phone' => $idx[0]['phone'] ?? null,
                'normalized' => ca_phone_normalize($idx[0]['phone'] ?? ''),
            ];
        }
        // Scan for match
        foreach ($idx as $row) {
            $candidate = ca_phone_normalize($row['phone'] ?? '');
            if (strlen($normalized) >= 8 && $candidate === $normalized) {
                $matchedClient = [
                    'id' => (int)($row['id'] ?? 0),
                    'name' => $row['name'] ?? '',
                    'phone_in_cache' => $row['phone'] ?? '',
                    'phone_normalized' => $candidate,
                ];
                break;
            }
        }
    } catch (\Throwable $e) {}

    // Rate limit check
    $cutoff = time() - 3600;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM app_otp_rate WHERE phone = ? AND sent_at > ?");
    $stmt->execute([$phoneIntl, $cutoff]);
    $recentSends = (int) $stmt->fetchColumn();

    // WASender config
    $senderEnabled = !empty($config['wa_plugin_url'])
                  && !empty($config['wa_app_key'])
                  && !empty($config['wa_auth_key']);

    // Pending OTP (if any)
    $stmt = $pdo->prepare("SELECT expires_at, created_at, attempts FROM app_otp_pending WHERE phone = ?");
    $stmt->execute([$phoneIntl]);
    $pending = $stmt->fetch(\PDO::FETCH_ASSOC);

    $ok2([
        'input' => [
            'raw' => $rawPhone,
            'normalized_last9' => $normalized,
            'intl_with_plus' => $phoneIntl,
        ],
        'client_index' => [
            'size' => $indexSize,
            'first_entry_sample' => $indexSample,
        ],
        'match' => $matchedClient ?: ['found' => false],
        'rate_limit' => [
            'sent_last_hour' => $recentSends,
            'max_per_hour' => 3,
            'allowed' => $recentSends < 3,
        ],
        'wa_config' => [
            'wa_plugin_url_set' => !empty($config['wa_plugin_url']),
            'wa_app_key_set'    => !empty($config['wa_app_key']),
            'wa_auth_key_set'   => !empty($config['wa_auth_key']),
            'dry_run_mode'      => (bool)($config['dry_run_mode'] ?? false),
            'sender_enabled'    => $senderEnabled,
        ],
        'pending_otp' => $pending ? [
            'exists' => true,
            'expires_in_seconds' => max(0, (int)$pending['expires_at'] - time()),
            'attempts' => (int)$pending['attempts'],
        ] : ['exists' => false],
        'what_send_otp_would_do' => $matchedClient
            ? ($senderEnabled
                ? ($recentSends < 3 ? 'Send OTP via WhatsApp' : 'Rate limited (3/hr)')
                : 'Fail — WASender not configured')
            : 'Fail — No account with that phone',
    ], 'Diagnostic complete.');
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_debug_log  (public — debug only)
// GET ?page=api&action=app_debug_log&phone=%2B211927797217&limit=10
//
// Shows last N entries from notification_log table for this phone.
// Also shows the failed-send queue if anything is stuck.
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_debug_log' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $rawPhone = trim($_GET['phone'] ?? '');
    $phoneDigits = preg_replace('/[^0-9]/', '', $rawPhone);
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));

    $result = [
        'phone_digits' => $phoneDigits,
        'notification_log' => [],
        'failed_queue' => [],
        'audit_log' => [],
    ];

    // Notification log — every sendVia() call
    try {
        if ($phoneDigits) {
            $stmt = $pdo->prepare("
                SELECT sender, event, phone, preview, success, http_code, error, sent_at
                FROM notification_audit_log
                WHERE phone = ? OR phone LIKE ?
                ORDER BY sent_at DESC LIMIT ?
            ");
            $stmt->execute([$phoneDigits, '%' . substr($phoneDigits, -9), $limit]);
        } else {
            $stmt = $pdo->prepare("
                SELECT sender, event, phone, preview, success, http_code, error, sent_at
                FROM notification_audit_log
                ORDER BY sent_at DESC LIMIT ?
            ");
            $stmt->execute([$limit]);
        }
        $result['notification_log'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $result['notification_log_error'] = $e->getMessage();
    }

    // Failed-send queue
    try {
        $stmt = $pdo->prepare("
            SELECT id, sender, phone, event, status, http_code, error, attempts, last_attempt_at
            FROM notification_queue
            WHERE phone LIKE ? OR phone = ?
            ORDER BY id DESC LIMIT 10
        ");
        $stmt->execute(['%' . substr($phoneDigits, -9), $phoneDigits]);
        $result['failed_queue'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $result['failed_queue_error'] = $e->getMessage();
    }

    // Our own app_audit_log
    try {
        $stmt = $pdo->prepare("
            SELECT action, phone, details, at
            FROM app_audit_log
            WHERE phone LIKE ? OR phone = ?
            ORDER BY at DESC LIMIT ?
        ");
        $stmt->execute(['%' . substr($phoneDigits, -9), $phoneDigits, $limit]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['at_iso'] = gmdate('c', (int)$r['at']);
        }
        $result['audit_log'] = $rows;
    } catch (\Throwable $e) {
        $result['audit_log_error'] = $e->getMessage();
    }

    $ok2($result, 'Debug log retrieved.');
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_debug_schema  (public — debug only)
// GET ?page=api&action=app_debug_schema
// GET ?page=api&action=app_debug_schema&plugin=dishnet-data-report
// GET ?page=api&action=app_debug_schema&plugin=dishnet-data-report&table=client_usage
//
// Inspects sibling plugin SQLite databases to discover table/column
// schemas. Used to wire real usage data into app_usage without guessing.
// Safe, read-only.
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_debug_schema' && $met === 'GET') {
    $targetPlugin = trim($_GET['plugin'] ?? '');
    $targetTable = trim($_GET['table'] ?? '');

    $pluginRoot = dirname(__DIR__, 2);
    $pluginsDir = dirname($pluginRoot);
    if (!is_dir($pluginsDir)) $er2('Cannot locate UCRM plugins directory', 500);

    // ── Mode 1: list plugins + their SQLite files ─────────────────────
    if (empty($targetPlugin)) {
        $plugins = [];
        foreach (scandir($pluginsDir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.') continue;
            $path = $pluginsDir . '/' . $name;
            if (!is_dir($path)) continue;

            $sqliteFiles = [];
            foreach (['.', 'data'] as $sub) {
                $scanDir = $path . '/' . $sub;
                if (!is_dir($scanDir)) continue;
                foreach (scandir($scanDir) ?: [] as $f) {
                    if (preg_match('/\.(sqlite3?|db)$/i', $f)) {
                        $full = $scanDir . '/' . $f;
                        if (is_file($full)) {
                            $sqliteFiles[] = [
                                'file' => ($sub === '.' ? '' : $sub . '/') . $f,
                                'size_bytes' => filesize($full),
                                'modified' => date('c', filemtime($full)),
                            ];
                        }
                    }
                }
            }
            $plugins[] = ['name' => $name, 'sqlite_files' => $sqliteFiles];
        }
        $ok2([
            'plugins_dir' => $pluginsDir,
            'plugins' => $plugins,
            'usage_hint' => 'Call again with &plugin=<name> to inspect tables',
        ], 'Plugin listing.');
    }

    // ── Mode 2: inspect a specific plugin ─────────────────────────────
    $targetDir = $pluginsDir . '/' . $targetPlugin;
    if (!is_dir($targetDir)) $er2("Plugin not found: $targetPlugin", 404);

    $candidates = [];
    foreach (['', 'data/'] as $sub) {
        $scanDir = $targetDir . '/' . rtrim($sub, '/');
        if (!is_dir($scanDir)) continue;
        foreach (scandir($scanDir) ?: [] as $f) {
            if (preg_match('/\.(sqlite3?|db)$/i', $f)) {
                $full = $scanDir . '/' . $f;
                if (is_file($full)) $candidates[] = $full;
            }
        }
    }

    // Also list JSON / CSV / TXT data files for discovery
    $dataFiles = [];
    foreach (['', 'data/'] as $sub) {
        $scanDir = $targetDir . '/' . rtrim($sub, '/');
        if (!is_dir($scanDir)) continue;
        foreach (scandir($scanDir) ?: [] as $f) {
            if (preg_match('/\.(json|csv|txt)$/i', $f)) {
                $full = $scanDir . '/' . $f;
                if (!is_file($full)) continue;
                $info = [
                    'file' => ($sub === '' ? '' : $sub) . $f,
                    'size_bytes' => filesize($full),
                    'modified' => date('c', filemtime($full)),
                ];
                // For JSON files: peek at top-level structure
                if (preg_match('/\.json$/i', $f) && filesize($full) < 5000000) {
                    try {
                        $raw = @file_get_contents($full);
                        $parsed = json_decode($raw, true);
                        if (is_array($parsed)) {
                            $keys = array_keys($parsed);
                            $isList = ($keys === range(0, count($parsed) - 1));
                            $info['type'] = $isList ? 'array' : 'object';
                            if ($isList) {
                                $info['array_length'] = count($parsed);
                                // Sample first element if list of objects
                                if (!empty($parsed) && is_array($parsed[0])) {
                                    $info['first_item_keys'] = array_keys($parsed[0]);
                                    $info['first_item_sample'] = $parsed[0];
                                }
                            } else {
                                $info['top_keys'] = array_keys($parsed);
                                // Show first 2 key-value pairs as sample
                                $sample = [];
                                $i = 0;
                                foreach ($parsed as $k => $v) {
                                    if ($i++ >= 2) break;
                                    if (is_array($v)) {
                                        $vKeys = array_keys($v);
                                        $vIsList = ($vKeys === range(0, count($v) - 1));
                                        $sample[$k] = $vIsList
                                            ? '[array of ' . count($v) . ']'
                                            : '{object with keys: ' . implode(',', array_keys($v)) . '}';
                                    } else {
                                        $sample[$k] = $v;
                                    }
                                }
                                $info['sample'] = $sample;
                            }
                        }
                    } catch (\Throwable $e) {
                        $info['parse_error'] = $e->getMessage();
                    }
                }
                $dataFiles[] = $info;
            }
        }
    }

    if (empty($candidates) && empty($dataFiles)) {
        $er2("No SQLite or JSON data files in '$targetPlugin'. Plugin may store data elsewhere.", 404);
    }

    $databases = [];
    foreach ($candidates as $dbPath) {
        $dbInfo = [
            'path' => str_replace($pluginsDir . '/', '', $dbPath),
            'size_bytes' => filesize($dbPath),
            'tables' => [],
        ];
        try {
            $pdo2 = new \PDO('sqlite:' . $dbPath);
            $pdo2->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo2->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $tableName) {
                $rowCount = null;
                try {
                    $c = $pdo2->query("SELECT COUNT(*) FROM \"$tableName\"");
                    $rowCount = (int)$c->fetchColumn();
                } catch (\Throwable $e) {}

                $tableInfo = ['name' => $tableName, 'row_count' => $rowCount];

                if ($targetTable === $tableName || $targetTable === '') {
                    $cols = $pdo2->query("PRAGMA table_info(\"$tableName\")")->fetchAll(\PDO::FETCH_ASSOC);
                    $tableInfo['columns'] = array_map(function($c) {
                        return [
                            'name' => $c['name'],
                            'type' => $c['type'],
                            'notnull' => (bool)$c['notnull'],
                            'pk' => (bool)$c['pk'],
                        ];
                    }, $cols);

                    if ($targetTable === $tableName) {
                        try {
                            $sample = $pdo2->query("SELECT * FROM \"$tableName\" LIMIT 3")->fetchAll(\PDO::FETCH_ASSOC);
                            $tableInfo['sample_rows'] = $sample;
                        } catch (\Throwable $e) {
                            $tableInfo['sample_error'] = $e->getMessage();
                        }
                    }
                }
                $dbInfo['tables'][] = $tableInfo;
            }
        } catch (\Throwable $e) {
            $dbInfo['error'] = $e->getMessage();
        }
        $databases[] = $dbInfo;
    }

    $ok2([
        'plugin' => $targetPlugin,
        'databases' => $databases,
        'data_files' => $dataFiles,
        'usage_hint' => $targetTable === ''
            ? 'Call with &table=<n> for columns + sample rows, OR look at data_files for JSON structure'
            : "Use the columns + sample_rows to write the real query",
    ], 'Schema inspection complete.');
}

if ($act === 'app_debug_send' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $rawPhone = trim($_GET['phone'] ?? '');
    $normalized = ca_phone_normalize($rawPhone);
    if (strlen($normalized) < 8) $er2('Invalid phone.', 400);
    $phoneIntl = ca_phone_intl($rawPhone);

    $client = ca_find_client_by_phone($store, $rawPhone);
    if (!$client) $er2('No CRM client matches this phone.', 404);

    $code = '999000'; // Fixed test code
    $message = "🔧 *DishNet Debug*\n\nTest code: *{$code}*\n\nThis is a debug message — do not use to log in.";

    // ── Direct WASender call so we can see the RAW response ─────────
    // Bypass NotificationService and hit WASender directly. This tells
    // us exactly what WASender returns (JSON, HTML, error).
    $wuUrl  = rtrim($config['wa_plugin_url'] ?? '', '/');
    $wuApp  = $config['wa_app_key'] ?? '';
    $wuAuth = $config['wa_auth_key'] ?? '';

    if (empty($wuUrl) || empty($wuApp) || empty($wuAuth)) {
        $er2('WASender not configured', 500);
    }

    // Phone: digits only, no +
    $toDigits = preg_replace('/[^0-9]/', '', $phoneIntl);

    $endpoint = $wuUrl . '/api/whatsapp-web/send-message';
    $formData = [
        'app_key'  => $wuApp,
        'auth_key' => $wuAuth,
        'to'       => $toDigits,
        'message'  => $message,
        'sandbox'  => 'false',
    ];

    $t0 = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $formData,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HEADER         => true,
    ]);
    $rawResp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $elapsedMs = (int)((microtime(true) - $t0) * 1000);

    $respHeaders = substr($rawResp, 0, $headerSize);
    $respBody = substr($rawResp, $headerSize);
    $parsed = json_decode($respBody, true);

    // Figure out actual success
    $isJson = is_array($parsed);
    $jsonSuccess = $isJson && ($parsed['success'] ?? false) === true;

    $ok2([
        'endpoint' => $endpoint,
        'sent_to' => $phoneIntl,
        'to_digits_format' => $toDigits,
        'elapsed_ms' => $elapsedMs,
        'http_code' => $httpCode,
        'content_type' => $contentType,
        'effective_url' => $effectiveUrl,
        'curl_error' => $curlErr ?: null,
        'response_is_json' => $isJson,
        'json_success_flag' => $jsonSuccess,
        'parsed_response' => $parsed,
        'raw_body_preview' => mb_substr($respBody, 0, 500),
        'raw_body_length' => strlen($respBody),
        'diagnosis' => $curlErr
            ? "CURL error — WASender unreachable: $curlErr"
            : (!$isJson
                ? 'WASender returned HTML instead of JSON — likely the URL is wrong, instance disconnected, or auth rejected and redirecting to login page'
                : ($jsonSuccess
                    ? 'WASender API accepted the request. If message did not arrive, the WA session is disconnected at the WASender/Evolution layer.'
                    : 'WASender returned JSON but with success=false — check parsed_response for the actual error')),
    ], 'Raw WASender diagnostic');
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_send_otp  (public)
// Body: { "phone": "+211923456789" }
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_send_otp') {
    if ($met !== 'POST') $er2('POST required.', 405);

    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    // v4.21.7: caller may send 'phone' OR 'email'. Email mode is for
    // customers who don't have WhatsApp on the phone they registered
    // with DishNet. The OTP infrastructure (rate limit, pending table,
    // verify) is keyed on a single 'identifier' string — the existing
    // 'phone' column repurposed. For phone login: identifier = E.164
    // phone (e.g. +211921443006). For email login: identifier =
    // lowercase email (e.g. rachel@example.com). The two namespaces
    // never collide because emails contain '@' and phones don't.
    $rawPhone = trim($body['phone'] ?? '');
    $rawEmail = trim($body['email'] ?? '');

    $loginMode = '';   // 'phone' | 'email'
    $identifier = '';  // what gets stored as PRIMARY KEY in app_otp_pending
    $clients = [];     // matching CRM client records

    if ($rawEmail !== '') {
        $loginMode = 'email';
        $identifier = strtolower($rawEmail);
        if (strpos($identifier, '@') === false || strlen($identifier) < 5) {
            $er2('Invalid email address.', 400);
        }
        $clients = ca_find_clients_by_email($store, $rawEmail);
        if (empty($clients)) {
            ca_audit($pdo, null, 'otp_no_account_email', $identifier);
            $er2('Email not found. Try Phone login if your email isn\'t registered.', 404);
        }
    } elseif ($rawPhone !== '') {
        $loginMode = 'phone';
        $normalized = ca_phone_normalize($rawPhone);
        if (strlen($normalized) < 8) {
            $er2('Invalid phone number.', 400);
        }
        $identifier = ca_phone_intl($rawPhone);
        $clients = ca_find_clients_by_phone($store, $rawPhone);
        if (empty($clients)) {
            ca_audit($pdo, null, 'otp_no_account', $identifier);
            $er2('No DishNet account with that phone. Contact Bidal on WhatsApp.', 404);
        }
    } else {
        $er2('Either phone or email is required.', 400);
    }

    // Rate limit: max 10 OTPs per identifier per hour. Same rule for
    // both phone and email — prevents either channel from being abused.
    $cutoff = time() - 3600;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM app_otp_rate WHERE phone = ? AND sent_at > ?");
    $stmt->execute([$identifier, $cutoff]);
    $recent = (int)$stmt->fetchColumn();
    if ($recent >= 10) {
        ca_audit($pdo, null, 'otp_rate_limit', $identifier);
        $er2('Too many requests. Try again in 1 hour.', 429);
    }

    // Primary = first in sorted order (active first, then lowest id)
    $client = $clients[0];

    // Generate code (same code regardless of channel)
    $code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    // v4.12.20: default 900s (15 min) — up from 600s (10 min). Accommodates
    // WASender queue latency in South Sudan where messages sometimes take
    // 3-5 minutes to arrive in WhatsApp even after the API call succeeds.
    // Same TTL applies to email — keeps both channels in sync so countdown
    // displayed to user matches reality on either channel.
    $ttl = (int)($config['app_otp_ttl_seconds'] ?? 900);

    // Store (upsert) — keyed on identifier (phone or email)
    $stmt = $pdo->prepare("
        INSERT INTO app_otp_pending (phone, code, expires_at, created_at, attempts, crm_client_id)
        VALUES (?, ?, ?, ?, 0, ?)
        ON CONFLICT(phone) DO UPDATE SET
            code = excluded.code,
            expires_at = excluded.expires_at,
            created_at = excluded.created_at,
            attempts = 0,
            crm_client_id = excluded.crm_client_id
    ");
    $stmt->execute([$identifier, $code, time() + $ttl, time(), (int)$client['id']]);

    // Record send for rate limiting
    $pdo->prepare("INSERT INTO app_otp_rate (phone, sent_at) VALUES (?, ?)")
        ->execute([$identifier, time()]);

    // Cleanup old rate entries (>24h)
    $pdo->prepare("DELETE FROM app_otp_rate WHERE sent_at < ?")
        ->execute([time() - 86400]);

    $ttlMin = max(1, (int)round($ttl / 60));
    $firstName = trim($client['name'] ?? '') ?: 'there';
    $firstName = explode(' ', $firstName)[0];
    $message = "🔐 *DishNet Login Code*\n\n"
             . "Your code: *{$code}*\n\n"
             . "Valid for {$ttlMin} minutes. If you did not request this, ignore.";

    $sendSuccess = false;
    $sendError = null;
    $senderEnabled = false;
    $dryRun = (bool)($config['dry_run_mode'] ?? false);
    $channelUsed = '';   // 'whatsapp' | 'email'

    if ($loginMode === 'phone') {
        // ─── Phone mode: send via WhatsApp (existing flow) ──────────────
        if ($notify) {
            $senderEnabled = !empty($config['wa_plugin_url']) && !empty($config['wa_app_key']) && !empty($config['wa_auth_key']);

            if (!$senderEnabled) {
                ca_audit($pdo, (int)$client['id'], 'otp_wa_not_configured', $identifier, [
                    'wa_plugin_url_set' => !empty($config['wa_plugin_url']),
                    'wa_app_key_set'    => !empty($config['wa_app_key']),
                    'wa_auth_key_set'   => !empty($config['wa_auth_key']),
                ]);
                $er2('WhatsApp sender is not configured on server. Contact admin.', 500);
            }

            $notify->sendVia(
                NotificationService::SUPPORT,
                $identifier,  // E.164 phone
                $message,
                'app_otp',
                ['crm_client_id' => (int)$client['id'], 'name' => $firstName]
            );

            // Check actual send result
            $reflect = new ReflectionObject($notify);
            if ($reflect->hasProperty('_lastSendSuccess')) {
                $prop = $reflect->getProperty('_lastSendSuccess');
                $prop->setAccessible(true);
                $sendSuccess = (bool) $prop->getValue($notify);
            }
            if ($reflect->hasProperty('_lastError')) {
                $prop = $reflect->getProperty('_lastError');
                $prop->setAccessible(true);
                $sendError = $prop->getValue($notify);
            }

            if (!$sendSuccess && !$dryRun) {
                ca_audit($pdo, (int)$client['id'], 'otp_send_failed', $identifier, ['error' => $sendError, 'channel' => 'whatsapp']);
                $er2('WhatsApp delivery failed: ' . ($sendError ?: 'unknown error'), 502);
            }
            $channelUsed = 'whatsapp';
        } else {
            error_log("[app-otp] \$notify not available — would send to {$identifier}: {$code}");
            $er2('Notification service unavailable.', 500);
        }
    } else {
        // ─── Email mode: send via SMTP ──────────────────────────────────
        // The customer typed an email that matches a CRM record. Send the
        // OTP to that email. We use the typed email value, not whatever's
        // in the CRM record, because email matching was case-insensitive
        // and they should get the response at the address they typed.
        $emailResult = ca_send_otp_email(
            $config,
            $store->getDataDir(),
            $rawEmail,                     // send to address as typed
            (string)($client['name'] ?? ''),
            $code,
            $ttlMin
        );
        $sendSuccess  = !empty($emailResult['ok']);
        $sendError    = $emailResult['error'] ?? '';
        $senderEnabled = $sendSuccess || $sendError !== 'No SMTP host configured (plugin or UCRM)';
        $channelUsed   = 'email';

        if (!$sendSuccess && !$dryRun) {
            ca_audit($pdo, (int)$client['id'], 'otp_send_failed', $identifier, [
                'error'   => $sendError,
                'channel' => 'email',
            ]);
            $er2('Email delivery failed: ' . ($sendError ?: 'unknown error'), 502);
        }
    }

    ca_audit($pdo, (int)$client['id'], 'otp_sent', $identifier, [
        'dry_run'      => $dryRun,
        'send_success' => $sendSuccess,
        'channel'      => $channelUsed,
        'mode'         => $loginMode,
    ]);

    $successMsg = $dryRun
        ? 'Dry run — OTP logged, not sent.'
        : ($loginMode === 'email' ? 'Code sent via Email.' : 'Code sent via WhatsApp.');

    $ok2([
        'expires_in' => $ttl,
        'server_time' => time(),  // v4.12.20: client can show countdown using server's clock
        'dry_run' => $dryRun,
        'channel' => $channelUsed,    // v4.21.7: tells UI which channel was used
        'mode'    => $loginMode,
        'debug' => [
            'client_id'      => (int)$client['id'],
            'identifier'     => $identifier,
            'sender_enabled' => $senderEnabled,
            'send_success'   => $sendSuccess,
        ],
    ], $successMsg);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_verify_otp  (public)
// Body: { "phone": "+211923456789", "code": "482193" }
// Returns: { token, expires_in }
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_verify_otp') {
    if ($met !== 'POST') $er2('POST required.', 405);

    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    // v4.21.7: caller may send 'phone' OR 'email'. The OTP record was
    // stored using whichever identifier was used at send time, so we
    // look up by the same key here.
    $rawPhone = trim($body['phone'] ?? '');
    $rawEmail = trim($body['email'] ?? '');
    $code = trim((string)($body['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) {
        $er2('Invalid code format.', 400);
    }

    if ($rawEmail !== '') {
        $identifier = strtolower($rawEmail);
        if (strpos($identifier, '@') === false) {
            $er2('Invalid email address.', 400);
        }
    } elseif ($rawPhone !== '') {
        $identifier = ca_phone_intl($rawPhone);
    } else {
        $er2('Either phone or email is required.', 400);
    }

    // Load pending OTP (column name is 'phone' but holds either phone or email)
    $stmt = $pdo->prepare("SELECT * FROM app_otp_pending WHERE phone = ? LIMIT 1");
    $stmt->execute([$identifier]);
    $rec = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$rec) {
        // v4.12.20 diagnostic: help distinguish "never sent" from "already expired and cleaned up"
        ca_audit($pdo, null, 'otp_verify_no_record', $identifier, ['entered_code' => substr($code, 0, 2) . '****']);
        $er2('No code on file. Tap "Resend code" to get a new one.', 400);
    }
    if ((int)$rec['expires_at'] < time()) {
        $age = time() - (int)$rec['created_at'];
        ca_audit($pdo, (int)$rec['crm_client_id'], 'otp_verify_expired', $identifier, [
            'age_seconds' => $age,
            'ttl_was' => (int)$rec['expires_at'] - (int)$rec['created_at'],
        ]);
        $er2('Code expired (' . $age . 's old). Request a new one.', 400);
    }

    // Lockout after 5 wrong attempts
    if ((int)$rec['attempts'] >= 5) {
        $pdo->prepare("DELETE FROM app_otp_pending WHERE phone = ?")->execute([$identifier]);
        ca_audit($pdo, (int)$rec['crm_client_id'], 'otp_lockout', $identifier);
        $er2('Too many wrong attempts. Request a new code.', 429);
    }

    if (!hash_equals((string)$rec['code'], $code)) {
        $pdo->prepare("UPDATE app_otp_pending SET attempts = attempts + 1 WHERE phone = ?")
            ->execute([$identifier]);
        ca_audit($pdo, (int)$rec['crm_client_id'], 'otp_wrong_code', $identifier);
        $er2('Wrong code.', 400);
    }

    // Load client to get current info
    $clientId = (int)$rec['crm_client_id'];
    $client = null;
    foreach ($store->load('client_search_index.json') ?? [] as $row) {
        if ((int)($row['id'] ?? 0) === $clientId) { $client = $row; break; }
    }
    if (!$client) {
        $er2('Account no longer exists.', 404);
    }

    // v4.12.13 — load ALL accounts for this identifier so the JWT can scope them.
    // User will see account switcher if count > 1; JWT validates X-Account-Id
    // is in this set on subsequent requests.
    // v4.21.7: mode-aware — phone OR email lookup.
    if ($rawEmail !== '') {
        $allAccounts = ca_find_clients_by_email($store, $rawEmail);
    } else {
        $allAccounts = ca_find_clients_by_phone($store, $rawPhone);
    }
    $accountsClaim = [];
    foreach ($allAccounts as $a) {
        $accountsClaim[] = [
            'id'     => (int)$a['id'],
            'name'   => $a['name'] ?? '',
            'status' => $a['status'] ?? '',
            'plans'  => $a['plans'] ?? '',
        ];
    }
    // Primary (sub) is first in sorted order — matches ordering helper used above
    if (!empty($allAccounts)) {
        $clientId = (int)$allAccounts[0]['id'];
        $client   = $allAccounts[0];
    }

    // Clear OTP
    $pdo->prepare("DELETE FROM app_otp_pending WHERE phone = ?")->execute([$identifier]);

    // Issue JWT
    $ttlDays = (int)($config['app_jwt_ttl_days'] ?? 30);
    $ttlSec = $ttlDays * 86400;

    // Override the jwt_ttl_seconds temporarily via config merge
    $cfgForJwt = array_merge($config, ['jwt_ttl_seconds' => $ttlSec]);
    $jwt = JwtAuth::fromConfig($cfgForJwt);

    $token = $jwt->issue([
        'sub'      => $clientId,
        'kind'     => 'app',
        'phone'    => $identifier,        // v4.21.7: 'phone' field holds whatever was used
        'name'     => $client['name'] ?? '',
        'accounts' => $accountsClaim,  // v4.12.13
    ]);

    ca_audit($pdo, $clientId, 'login_success', $identifier, [
        'account_count' => count($accountsClaim),
        'login_mode'    => $rawEmail !== '' ? 'email' : 'phone',
    ]);

    // v4.12.19 — Tell client whether they need to accept current T&C/Privacy.
    // Returns false for returning customers who have already accepted; true for
    // first-time users or when we've bumped a version. The login UI shows a
    // consent step before redirecting to the portal if needs_consent=true.
    $needsConsent = !ca_has_current_consent($pdo, $identifier);
    $legalVer = (function() {
        require_once dirname(__DIR__, 2) . '/lib/LegalContent.php';
        return dnLegalVersion();
    })();

    $ok2([
        'token' => $token,
        'expires_in' => $ttlSec,
        'customer' => [
            'id' => $clientId,
            'name' => $client['name'] ?? '',
            'phone' => $identifier,
        ],
        'accounts' => $accountsClaim,  // v4.12.13 — UI shows switcher if count > 1
        'needs_consent' => $needsConsent,  // v4.12.19
        'legal' => [
            'tos_version'     => $legalVer['tos'],
            'privacy_version' => $legalVer['privacy'],
            'dated'           => $legalVer['dated'],
        ],
    ], 'Login successful.');
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_logout  (Bearer)
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_logout') {
    if ($met !== 'POST') $er2('POST required.', 405);
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $claims = ca_require_auth($config, $pdo, $er2);

    if (!empty($claims['jti']) && !empty($claims['exp'])) {
        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO app_jwt_blacklist (jti, expires_at) VALUES (?, ?)
        ");
        $stmt->execute([$claims['jti'], (int)$claims['exp']]);
    }
    ca_audit($pdo, (int)($claims['sub'] ?? 0), 'logout', $claims['phone'] ?? null);

    $ok2([], 'Logged out.');
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_me  (Bearer)
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_me' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();
    $claims = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($claims, $er2); // v4.12.13 multi-account

    // v4.21.38: opportunistic refresh. The home screen reads this endpoint
    // and shows the "X invoices due · $Y · Pay to keep service active" banner
    // from cached data — without this, newly-paid customers keep seeing the
    // banner until cache eventually refreshes. 60-sec rate limit inside the
    // refresher prevents UCRM hammering.
    try {
        if (!class_exists('ClientInvoiceCacheRefresher')) {
            @require_once dirname(__DIR__, 2) . '/lib/ClientInvoiceCacheRefresher.php';
        }
        if (class_exists('ClientInvoiceCacheRefresher') && isset($crm)) {
            $refresher = new ClientInvoiceCacheRefresher($store, $crm, $dataDir);
            $refresher->refreshForClient($clientId, false, 'ondemand:app_me');
            $refresher->refreshClientRecord($clientId, 'ondemand:app_me');
        }
    } catch (\Throwable $_) { /* best-effort */ }

    // Read from client_search_index — already shaped for display
    $client = null;
    foreach ($store->load('client_search_index.json') ?? [] as $row) {
        if ((int)($row['id'] ?? 0) === $clientId) { $client = $row; break; }
    }
    if (!$client) $er2('Client not found.', 404);

    // Also load full client record for address
    $full = null;
    foreach ($store->load('ucrm_clients_cache.json') ?? [] as $row) {
        if ((int)($row['id'] ?? 0) === $clientId) { $full = $row; break; }
    }

    $location = '';
    if ($full) {
        $parts = array_filter([
            trim($full['street1'] ?? ''),
            trim($full['city'] ?? ''),
        ]);
        $location = implode(', ', $parts);
    }
    if (empty($location)) $location = 'Juba';

    // Derive service type — v4.21.49 hybrid-aware
    // Same logic as portal_data.php: detect by data presence, not by
    // plan-name substring matching. A customer can have multiple types.
    //
    // Sources:
    //   • Starlink: customer has KITs in sl_kits.json
    //   • Fiber:    customer has a row in fiber_usage_cache.json
    //   • LTE:      plan name contains 'lte' (no separate cache)
    $serviceTypes = [];
    $planName = strtolower(trim($client['plans'] ?? ''));

    // Starlink presence — check sl_kits.json for any KIT mapped to this CRM client.
    // Independent of the paused-state $myKits build below (which fires later
    // and depends on this file existing too).
    $hasStarlink = false;
    try {
        $plBase = dirname(__DIR__, 3);
        $slKitsFile2 = $plBase . '/dishnet-starlink-finance/data/sl_kits.json';
        if (is_file($slKitsFile2)) {
            $skRaw = json_decode((string)@file_get_contents($slKitsFile2), true) ?: [];
            foreach ($skRaw as $kKey => $kVal) {
                if (!is_array($kVal)) continue;
                $kCid = (int)(
                    $kVal['crm_client_id'] ?? $kVal['assigned_client_id']
                    ?? $kVal['client_id'] ?? $kVal['clientId'] ?? 0
                );
                if ($kCid === $clientId) { $hasStarlink = true; break; }
            }
        }
    } catch (\Throwable $_) { /* best-effort */ }
    if ($hasStarlink) {
        $serviceTypes[] = 'starlink';
    }

    // Fiber presence — check fiber_usage_cache.json
    try {
        $pluginsBase3 = dirname(__DIR__, 3);
        $fiberCacheFile = $pluginsBase3 . '/dishnet-fiber-finance/data/fiber_usage_cache.json';
        if (is_file($fiberCacheFile)) {
            $fiberCacheRaw = json_decode((string)@file_get_contents($fiberCacheFile), true) ?: [];
            foreach ($fiberCacheRaw as $row) {
                if (!is_array($row)) continue;
                if ((int)($row['crm_customer_id'] ?? 0) === $clientId) {
                    $serviceTypes[] = 'fiber';
                    break;
                }
            }
        }
    } catch (\Throwable $_) { /* best-effort */ }

    // LTE — plan-name fallback, only if not already detected structurally
    if (strpos($planName, 'lte') !== false && !in_array('lte', $serviceTypes, true)) {
        $serviceTypes[] = 'lte';
    }

    // Last-resort fallback for customers whose data hasn't synced yet
    if (empty($serviceTypes)) {
        if (strpos($planName, 'fiber') !== false) {
            $serviceTypes[] = 'fiber';
        } elseif (strpos($planName, 'lte') !== false) {
            $serviceTypes[] = 'lte';
        } else {
            $serviceTypes[] = 'starlink';
        }
    }

    $isHybrid = count($serviceTypes) >= 2;
    // Legacy single-value field — kept for native-app backwards compat.
    // For hybrid customers, default to 'starlink' (matches PWA behavior).
    $serviceType = $serviceTypes[0];

    // Initials
    $name = trim($client['name'] ?? 'DishNet Customer');
    $parts = preg_split('/\s+/', $name);
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') $initials .= mb_substr($p, 0, 1);
        if (mb_strlen($initials) >= 2) break;
    }

    // v4.21.42: Paused-state computation for the native app's home banner.
    // Same logic as the PWA's portal_data.php — joins this customer's KITs
    // with data-report's wifi_test_block_state.json (source of truth for
    // who's currently paused via gRPC test_block).
    $isPaused = false;
    $unpaidTotal = 0.0;
    try {
        $pluginsBase = dirname(__DIR__, 3);
        $blockStateFile = $pluginsBase . '/dishnet-data-report/data/wifi_test_block_state.json';
        $routerMapFile  = $pluginsBase . '/dishnet-data-report/data/wifi_router_map.json';
        $slKitsFile     = $pluginsBase . '/dishnet-starlink-finance/data/sl_kits.json';

        if (is_file($blockStateFile) && is_file($routerMapFile) && is_file($slKitsFile)) {
            $bs = json_decode((string)@file_get_contents($blockStateFile), true) ?: [];
            $rm = json_decode((string)@file_get_contents($routerMapFile), true)  ?: [];
            $sk = json_decode((string)@file_get_contents($slKitsFile), true)     ?: [];

            // Build set of THIS client's KITs
            $myKits = [];
            foreach ($sk as $kKey => $kVal) {
                if (!is_array($kVal)) continue;
                $kCid = (int)(
                    $kVal['crm_client_id'] ?? $kVal['assigned_client_id']
                    ?? $kVal['client_id'] ?? $kVal['clientId'] ?? 0
                );
                if ($kCid !== $clientId) continue;
                $kSerial = strtoupper(trim((string)(
                    $kVal['kit_serial'] ?? $kVal['kit_number'] ?? $kVal['kit']
                    ?? $kVal['kitSerial'] ?? (is_string($kKey) ? $kKey : '')
                )));
                if ($kSerial !== '') $myKits[$kSerial] = true;
            }

            // Any of those KITs in the paused-state file?
            foreach ($bs as $rid => $_state) {
                $rawId = (strpos((string)$rid, 'Router-') === 0)
                    ? substr((string)$rid, 7) : (string)$rid;
                $rmEntry = $rm[$rawId] ?? null;
                if (!is_array($rmEntry)) continue;
                $kit = strtoupper(trim((string)($rmEntry['kit_serial'] ?? '')));
                if ($kit !== '' && isset($myKits[$kit])) {
                    $isPaused = true;
                    break;
                }
            }
        }

        // Unpaid total for the message body
        foreach ($store->load('ucrm_invoices_cache.json') ?? [] as $inv) {
            if ((int)($inv['clientId'] ?? 0) !== $clientId) continue;
            $st = (int)($inv['status'] ?? 0);
            if ($st === 4) continue; // paid
            $total = (float)($inv['total'] ?? 0);
            $paid  = (float)($inv['amountPaid'] ?? 0);
            $unpaidTotal += max(0, $total - $paid);
        }
    } catch (\Throwable $_) { /* best-effort */ }

    // v4.21.47: Fiber usage stats — read from Fiber Finance plugin's cache
    // (refreshed every 60 min by FF main.php → SplynxSync->fetchUsageStats).
    // Native app shows "47 GB today · 1.2 TB this month" home card for fiber
    // customers. Source of truth: dishnet-fiber-finance/data/fiber_usage_cache.json.
    $fiberUsage = null;
    if ($serviceType === 'fiber') {
        try {
            $pluginsBase2 = dirname(__DIR__, 3);
            $usageCacheFile = $pluginsBase2 . '/dishnet-fiber-finance/data/fiber_usage_cache.json';
            if (is_file($usageCacheFile)) {
                $usageCacheRaw = json_decode((string)@file_get_contents($usageCacheFile), true) ?: [];
                foreach ($usageCacheRaw as $row) {
                    if (!is_array($row)) continue;
                    if ((int)($row['crm_customer_id'] ?? 0) !== $clientId) continue;
                    $fiberUsage = [
                        'today_in_bytes'   => (int)($row['today_in_bytes']  ?? 0),
                        'today_out_bytes'  => (int)($row['today_out_bytes'] ?? 0),
                        'week_in_bytes'    => (int)($row['week_in_bytes']   ?? 0),
                        'week_out_bytes'   => (int)($row['week_out_bytes']  ?? 0),
                        'month_in_bytes'   => (int)($row['month_in_bytes']  ?? 0),
                        'month_out_bytes'  => (int)($row['month_out_bytes'] ?? 0),
                        'd14_in_bytes'     => (int)($row['d14_in_bytes']    ?? 0),
                        'd14_out_bytes'    => (int)($row['d14_out_bytes']   ?? 0),
                        'all_in_bytes'     => (int)($row['all_in_bytes']    ?? 0),
                        'all_out_bytes'    => (int)($row['all_out_bytes']   ?? 0),
                        'service_count'    => (int)($row['service_count']   ?? 0),
                        'service_ids'      => (array)($row['service_ids']   ?? []),
                        'updated_at'       => (string)($row['updated_at']   ?? ''),
                    ];
                    break;
                }
            }
        } catch (\Throwable $_) { /* best-effort */ }
    }

    $ok2([
        'id' => $clientId,
        'name' => $name,
        'phone' => $claims['phone'] ?? ($client['phone'] ?? ''),
        'email' => $client['email'] ?? '',
        'service_type' => $serviceType,
        // v4.21.49: hybrid-aware fields. Native app should render a pill
        // toggle (Phone/Email-style) when is_hybrid=true and let user switch
        // which card to display. service_type is kept as legacy single-value
        // for old app builds; new builds should read services[] which is
        // authoritative.
        'services'   => $serviceTypes,
        'is_hybrid'  => $isHybrid,
        'service_location' => $location,
        'avatar_initials' => strtoupper($initials ?: '?'),
        // v4.21.42: paused-state fields. Native app should show a red banner
        // on home when is_paused=true, with paused_message body. Banner CTA
        // links to invoices view.
        'is_paused' => $isPaused,
        'paused_message' => $isPaused
            ? ($unpaidTotal > 0
                ? "Your internet is paused. Pay \$" . number_format($unpaidTotal, 0)
                  . " to restore service — your dish reconnects automatically within seconds of payment."
                : "Your internet is paused. Pay your outstanding balance to restore service — your dish reconnects automatically within seconds of payment.")
            : '',
        'unpaid_total' => $unpaidTotal,
        // v4.21.47: fiber_usage is null for non-fiber customers OR when no
        // cache row exists yet (new customer / FF cron hasn't run yet).
        'fiber_usage' => $fiberUsage,
        // v4.12.13 — return the live accounts list so UI can refresh the switcher.
        // Cross-checks JWT's accounts claim against current CRM index — added
        // accounts appear only at next login (JWT is fixed), but status changes
        // (active/suspended) reflect immediately.
        'accounts' => (function() use ($claims, $store) {
            $fromJwt = $claims['accounts'] ?? [];
            if (empty($fromJwt)) return [];
            $byId = [];
            foreach ($store->load('client_search_index.json') ?? [] as $row) {
                $byId[(int)($row['id'] ?? 0)] = $row;
            }
            $out = [];
            foreach ($fromJwt as $a) {
                $aid = (int)($a['id'] ?? 0);
                $live = $byId[$aid] ?? null;
                $out[] = [
                    'id'     => $aid,
                    'name'   => $live['name']   ?? ($a['name']   ?? ''),
                    'status' => $live['status'] ?? ($a['status'] ?? ''),
                    'plans'  => $live['plans']  ?? ($a['plans']  ?? ''),
                ];
            }
            return $out;
        })(),
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_plan  (Bearer)
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_plan' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();
    $claims = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($claims, $er2); // v4.12.13 multi-account

    // Find active service
    $service = null;
    foreach ($store->load('ucrm_services_cache.json') ?? [] as $s) {
        if ((int)($s['clientId'] ?? $s['_clientId'] ?? 0) !== $clientId) continue;
        if ((int)($s['status'] ?? 0) === 1) { $service = $s; break; }
    }
    // Fallback: any service for this client
    if (!$service) {
        foreach ($store->load('ucrm_services_cache.json') ?? [] as $s) {
            if ((int)($s['clientId'] ?? $s['_clientId'] ?? 0) === $clientId) { $service = $s; break; }
        }
    }
    if (!$service) $er2('No plan found.', 404);

    // Get plan name from plans cache
    $planName = trim($service['name'] ?? '');
    if (empty($planName) && !empty($service['servicePlanId'])) {
        foreach ($store->load('ucrm_plans_cache.json') ?? [] as $p) {
            if ((int)($p['id'] ?? 0) === (int)$service['servicePlanId']) {
                $planName = $p['name'] ?? 'Current plan';
                break;
            }
        }
    }
    if (empty($planName)) $planName = 'Current plan';

    // Days remaining
    $nextBill = $service['nextInvoicingDayAdjustment']
             ?? $service['activeTo']
             ?? null;
    $daysRemaining = null;
    if ($nextBill) {
        $t = strtotime($nextBill);
        if ($t !== false) {
            $daysRemaining = max(0, (int)floor(($t - time()) / 86400));
        }
    }

    $period = ((int)($service['invoicingPeriodType'] ?? 1)) === 3 ? 'quarterly' : 'monthly';

    $ok2([
        'name' => $planName,
        'price' => (float)($service['price'] ?? 0),
        'currency' => $service['currencyCode'] ?? 'USD',
        'period' => $period,
        'data_limit_gb' => null,  // Future: from Data Report
        'speed_mbps' => null,     // Future: from plan metadata
        'next_bill_at' => $nextBill,
        'days_remaining' => $daysRemaining,
        'status' => ((int)($service['status'] ?? 0)) === 1 ? 'active' : 'suspended',
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_usage  (Bearer) — stub until Data Report wiring
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_usage' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();
    ca_require_auth($config, $pdo, $er2);

    // TODO: query dishnet-data-report plugin's SQLite for real usage.
    // For now return unavailable — Android app handles this gracefully.
    $ok2([
        'used_gb' => null,
        'limit_gb' => null,
        'pct' => null,
        'cycle_start' => null,
        'cycle_end' => null,
        'unavailable' => true,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_invoices  (Bearer)
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_invoices' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();
    $claims = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($claims, $er2); // v4.12.13 multi-account

    // v4.21.38: opportunistic on-demand cache refresh. Rate-limited to once
    // per 60 seconds per client (handled inside the refresher) so repeated
    // refreshes from the same customer don't hammer UCRM. Bounds staleness
    // to ~60 sec even when no payment.add webhook fired (e.g. payment posted
    // via UCRM admin UI, manual collections, or webhook delivery delayed).
    try {
        if (!class_exists('ClientInvoiceCacheRefresher')) {
            @require_once dirname(__DIR__, 2) . '/lib/ClientInvoiceCacheRefresher.php';
        }
        if (class_exists('ClientInvoiceCacheRefresher') && isset($crm)) {
            $refresher = new ClientInvoiceCacheRefresher($store, $crm, $dataDir);
            $refresher->refreshForClient($clientId, false, 'ondemand:app_invoices');
        }
    } catch (\Throwable $_) { /* never break the response on refresh failure */ }

    $all = $store->load('ucrm_invoices_cache.json') ?? [];
    $mine = [];
    foreach ($all as $inv) {
        if ((int)($inv['clientId'] ?? 0) !== $clientId) continue;

        $total = (float)($inv['total'] ?? 0);
        $paid  = (float)($inv['amountPaid'] ?? 0);

        // UCRM invoice status: 1=draft, 2=unpaid, 3=partial, 4=paid, 5=void, 6=overdue
        $ucrmStatus = (int)($inv['status'] ?? 0);
        if ($ucrmStatus === 4 || $paid >= $total) {
            $status = 'paid';
        } elseif ($ucrmStatus === 6) {
            $status = 'overdue';
        } else {
            $dueDate = $inv['dueDate'] ?? null;
            $status = ($dueDate && strtotime($dueDate) < time()) ? 'overdue' : 'pending';
        }

        // Description = first item label
        $desc = 'DishNet service';
        if (!empty($inv['items'][0]['label'])) $desc = $inv['items'][0]['label'];

        $mine[] = [
            'id' => 'INV-' . (int)$inv['id'],
            'ucrm_id' => (int)$inv['id'],
            'amount' => $total,
            'amount_due' => max(0, $total - $paid),
            'currency' => $inv['currencyCode'] ?? 'USD',
            'status' => $status,
            'issued_at' => $inv['createdDate'] ?? null,
            'due_at' => $inv['dueDate'] ?? null,
            'paid_at' => ($paid >= $total) ? ($inv['paidDate'] ?? $inv['createdDate'] ?? null) : null,
            'description' => $desc,
            'invoice_number' => $inv['number'] ?? null,
        ];
    }

    // Sort newest first
    usort($mine, function($a, $b) {
        return strcmp($b['issued_at'] ?? '', $a['issued_at'] ?? '');
    });

    // Cap at 20 most recent
    $mine = array_slice($mine, 0, 20);
    $ok2($mine);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_invoice  (Bearer) — single invoice detail
// Query: ?id=48291
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_invoice' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();
    $claims = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($claims, $er2); // v4.12.13 multi-account

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) $er2('Missing invoice id.', 400);

    $inv = null;
    foreach ($store->load('ucrm_invoices_cache.json') ?? [] as $row) {
        if ((int)($row['id'] ?? 0) === $id) { $inv = $row; break; }
    }
    if (!$inv || (int)($inv['clientId'] ?? 0) !== $clientId) {
        $er2('Invoice not found.', 404);
    }

    $total = (float)($inv['total'] ?? 0);
    $paid  = (float)($inv['amountPaid'] ?? 0);
    $ucrmStatus = (int)($inv['status'] ?? 0);
    if ($ucrmStatus === 4 || $paid >= $total) $status = 'paid';
    elseif ($ucrmStatus === 6) $status = 'overdue';
    else {
        $dueDate = $inv['dueDate'] ?? null;
        $status = ($dueDate && strtotime($dueDate) < time()) ? 'overdue' : 'pending';
    }

    $items = [];
    foreach ($inv['items'] ?? [] as $it) {
        $items[] = [
            'label' => $it['label'] ?? '',
            'quantity' => (float)($it['quantity'] ?? 1),
            'price' => (float)($it['price'] ?? 0),
            'total' => (float)($it['total'] ?? 0),
        ];
    }

    $ok2([
        'id' => 'INV-' . (int)$inv['id'],
        'ucrm_id' => (int)$inv['id'],
        'amount' => $total,
        'amount_due' => max(0, $total - $paid),
        'currency' => $inv['currencyCode'] ?? 'USD',
        'status' => $status,
        'issued_at' => $inv['createdDate'] ?? null,
        'due_at' => $inv['dueDate'] ?? null,
        'paid_at' => ($paid >= $total) ? ($inv['paidDate'] ?? null) : null,
        'items' => $items,
        'subtotal' => (float)($inv['subtotal'] ?? 0),
        'tax' => (float)($inv['totalTaxes'] ?? 0),
        'invoice_number' => $inv['number'] ?? null,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// FCM PUSH NOTIFICATIONS
// ═══════════════════════════════════════════════════════════════════

// ─── Register FCM token (called by Android app after login) ──────
if ($act === 'app_register_fcm' && $met === 'POST') {
    // v4.12.27: bootstrap $pdo from $store — was missing, causing 500s.
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me = ca_require_auth($config, $pdo, $er2);
    $clientId = (int)($me['sub'] ?? 0);
    $token = trim($body['fcm_token'] ?? '');
    $platform = trim($body['platform'] ?? 'android');

    if (!$token) $er2('Missing fcm_token.', 400);
    if (strlen($token) > 500) $er2('Token too long.', 400);

    // Upsert: if token exists for another client, reassign (device changed owner)
    $pdo->prepare("
        INSERT INTO app_fcm_tokens (crm_client_id, token, platform, registered_at, updated_at)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(token) DO UPDATE SET
            crm_client_id = excluded.crm_client_id,
            platform = excluded.platform,
            updated_at = excluded.updated_at
    ")->execute([$clientId, $token, $platform, time(), time()]);

    ca_audit($pdo, $clientId, 'fcm_registered', $me['phone'] ?? '');
    $ok2(['registered' => true], 'FCM token registered.');
}

// ─── Unregister FCM token (called on logout) ─────────────────────
if ($act === 'app_unregister_fcm' && $met === 'POST') {
    // v4.12.27: bootstrap $pdo from $store — was missing, causing 500s.
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me = ca_require_auth($config, $pdo, $er2);
    $token = trim($body['fcm_token'] ?? '');
    if ($token) {
        $pdo->prepare("DELETE FROM app_fcm_tokens WHERE token = ?")->execute([$token]);
    }
    $ok2(['unregistered' => true], 'FCM token removed.');
}

// ─── Get cached WiFi credentials for a router/kit ────────────────
if ($act === 'app_wifi_get' && $met === 'GET') {
    // v4.12.27 CRITICAL FIX: bootstrap $pdo from $store. This handler has been
    // 500'ing since at least v4.12.19 with 'Undefined variable $pdo' — every
    // OTHER handler in this file does `$pdo = $store->getPdo();` at the top
    // (see line 340, 438, 703, 802, 951, 1079, 1099 etc.) but this one was
    // missing the line. Result: ca_require_auth($config, $pdo, $er2) fatals
    // on the undefined $pdo before any logic runs. That's why the SSID has
    // NEVER pre-filled on the PWA — the v4.12.21/23/25 fallback code was all
    // correct but unreachable. $config is fine because public.php sets it at
    // line 239 (before api_handlers loads), but $pdo is never set globally.
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2); // v4.12.13 multi-account
    $routerId = trim($_GET['router_id'] ?? '');
    $kitNumber = trim($_GET['kit'] ?? '');

    $row = null;
    if ($routerId) {
        $stmt = $pdo->prepare("SELECT * FROM app_wifi_cache WHERE router_id = ? AND crm_client_id = ? LIMIT 1");
        $stmt->execute([$routerId, $clientId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    if (!$row && $kitNumber) {
        $stmt = $pdo->prepare("SELECT * FROM app_wifi_cache WHERE kit_number = ? AND crm_client_id = ? LIMIT 1");
        $stmt->execute([$kitNumber, $clientId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    if ($row) {
        $ok2([
            'ssid' => $row['ssid'] ?? '',
            'password' => $row['password'] ?? '',
            'ssid_5ghz' => $row['ssid_5ghz'] ?? '',
            'password_5ghz' => $row['password_5ghz'] ?? '',
            'updated_at' => date('c', (int)($row['updated_at'] ?? 0)),
            'source' => $row['source'] ?? '',
        ], 'Cached WiFi found.');
    } else {
        // v4.12.21: Fallback to Data Report's wifi_router_map.json. That file
        // is populated by Data Report's cron (every 2h as of DR v2.8.13) for
        // ALL 267 routers. The app_wifi_cache SQLite table is only populated
        // after a successful app_wifi_save — so for customers who have never
        // changed WiFi through the app, SQLite is empty and we'd previously
        // return 'No cached WiFi' even though Data Report has it.
        //
        // v4.12.26: ENTIRE fallback block wrapped in try/catch. Previous
        // versions returned bare 500s when any step failed (file_get_contents
        // on non-readable path, json_decode on unexpected format, etc.),
        // making diagnosis impossible. Now any throwable is caught, converted
        // into the _diag payload with an 'error' field, and returned as a
        // normal JSON response with empty ssid. The frontend already handles
        // empty ssid gracefully (auto-expands Advanced).
        $fallbackSsid = '';
        $fallbackPass = '';
        $fallbackSource = '';
        $fallbackUpdated = '';
        $drMapFile = '';
        $drMap = null;
        $entry = null;
        $matchVia = '';
        $fallbackError = null;

        try {
            $drMapFile = dirname(dirname(dirname(__DIR__))) . '/dishnet-data-report/data/wifi_router_map.json';
            if (file_exists($drMapFile)) {
                $raw = @file_get_contents($drMapFile);
                if ($raw !== false && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $drMap = $decoded;

                        // Try 1: direct lookup with whatever the PWA sent
                        if (!$entry && $routerId && isset($drMap[$routerId])) {
                            $entry = $drMap[$routerId];
                            $matchVia = 'direct';
                        }

                        // Try 2: strip "Router-" prefix and look up
                        if (!$entry && $routerId && strpos($routerId, 'Router-') === 0) {
                            $shortId = substr($routerId, 7);
                            if ($shortId && isset($drMap[$shortId])) {
                                $entry = $drMap[$shortId];
                                $matchVia = 'short_id';
                            }
                        }

                        // Try 3: add "Router-" prefix and look up (in case PWA sent short)
                        if (!$entry && $routerId && strpos($routerId, 'Router-') !== 0 && isset($drMap['Router-' . $routerId])) {
                            $entry = $drMap['Router-' . $routerId];
                            $matchVia = 'prefix_added';
                        }

                        // Try 4: scan by kit_serial (map is small — ~267 entries)
                        if (!$entry && $kitNumber) {
                            foreach ($drMap as $rid => $rdata) {
                                if (!is_array($rdata)) continue;
                                $ks = (string)($rdata['kit_serial'] ?? '');
                                if ($ks === '') continue;
                                if ($ks === $kitNumber) {
                                    $entry = $rdata;
                                    $matchVia = 'kit_exact';
                                    break;
                                }
                                if (strcasecmp($ks, $kitNumber) === 0) {
                                    $entry = $rdata;
                                    $matchVia = 'kit_case_insensitive';
                                    break;
                                }
                                $a = preg_replace('/^KIT/i', '', $ks);
                                $b = preg_replace('/^KIT/i', '', $kitNumber);
                                if ($a !== '' && $a === $b) {
                                    $entry = $rdata;
                                    $matchVia = 'kit_no_prefix';
                                    break;
                                }
                            }
                        }

                        if ($entry) {
                            $fallbackSsid = $entry['cached_ssid'] ?? '';
                            $fallbackPass = $entry['cached_password'] ?? '';
                            $fallbackSource = 'data-report-cron' . ($matchVia ? ':' . $matchVia : '');
                            $fallbackUpdated = $entry['wifi_cached_at'] ?? '';
                        }
                    } else {
                        $fallbackError = 'json_decode returned non-array';
                    }
                } else {
                    $fallbackError = 'file_get_contents returned empty or false';
                }
            } else {
                $fallbackError = 'map file does not exist at expected path';
            }
        } catch (\Throwable $e) {
            $fallbackError = 'exception: ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
        }

        if ($fallbackSsid !== '') {
            $ok2([
                'ssid' => $fallbackSsid,
                'password' => $fallbackPass,
                'ssid_5ghz' => $fallbackSsid,
                'password_5ghz' => $fallbackPass,
                'updated_at' => $fallbackUpdated,
                'source' => $fallbackSource,
            ], 'Cached WiFi found (from Data Report).');
        } else {
            // v4.12.25 expanded diagnostic, v4.12.26 try/catch-safe version
            $diag = [
                'map_file' => $drMapFile,
                'map_present' => $drMapFile ? file_exists($drMapFile) : false,
                'map_size_bytes' => ($drMapFile && file_exists($drMapFile)) ? filesize($drMapFile) : 0,
                'map_entries' => is_array($drMap) ? count($drMap) : 0,
                'router_id_tried' => $routerId,
                'kit_tried' => $kitNumber,
                'error' => $fallbackError,
            ];
            if (is_array($drMap)) {
                try {
                    $allKeys = array_keys($drMap);
                    $diag['first_5_keys'] = array_slice($allKeys, 0, 5);

                    $kitNeedle = (string)$kitNumber;
                    $stripped = preg_replace('/^KIT/i', '', $kitNeedle);
                    $possibleHits = [];
                    $scanned = 0;
                    foreach ($drMap as $rk => $rv) {
                        if ($scanned++ > 500) break;
                        if (!is_array($rv)) continue;
                        $ks = (string)($rv['kit_serial'] ?? '');
                        $tid = (string)($rv['terminal_id'] ?? '');
                        if ($ks === '' && $tid === '') continue;

                        // Guarded stripos calls — PHP 7.4 handles empty needles
                        // as 0 (truthy-zero) which would cause false matches.
                        $match = false;
                        if ($ks !== '' && $kitNeedle !== '' && stripos($ks, $kitNeedle) !== false) $match = true;
                        if (!$match && $ks !== '' && $stripped !== '' && stripos($ks, $stripped) !== false) $match = true;
                        if (!$match && $tid !== '' && $kitNeedle !== '' && stripos($tid, $kitNeedle) !== false) $match = true;

                        if ($match) {
                            $possibleHits[] = [
                                'key' => $rk,
                                'kit_serial' => $ks,
                                'terminal_id' => $tid,
                                'router_id_full' => $rv['router_id_full'] ?? '',
                                'has_cached_ssid' => !empty($rv['cached_ssid']),
                                'wifi_cached_at' => $rv['wifi_cached_at'] ?? null,
                            ];
                            if (count($possibleHits) >= 5) break;
                        }
                    }
                    $diag['possible_kit_hits'] = $possibleHits;

                    $short = $routerId;
                    if ($short && strpos($short, 'Router-') === 0) $short = substr($short, 7);
                    $diag['short_router_id'] = $short;
                    $diag['short_id_exists_as_key'] = ($short && isset($drMap[$short]));
                    $diag['full_id_exists_as_key'] = ($routerId && isset($drMap[$routerId]));
                } catch (\Throwable $e) {
                    $diag['scan_error'] = $e->getMessage();
                }
            }
            $ok2([
                'ssid' => '',
                'password' => '',
                '_diag' => $diag,
            ], 'No cached WiFi.');
        }
    }
}

// ─── Save WiFi credentials to cache ──────────────────────────────
if ($act === 'app_wifi_save' && $met === 'POST') {
    // v4.12.27: bootstrap $pdo from $store — was missing, causing 500s.
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2); // v4.12.13 multi-account
    $routerId = trim($body['router_id'] ?? '');
    $kitNumber = trim($body['kit'] ?? '');
    $ssid = trim($body['ssid'] ?? '');
    $password = trim($body['password'] ?? '');
    $ssid5 = trim($body['ssid_5ghz'] ?? $ssid);
    $pass5 = trim($body['password_5ghz'] ?? $password);
    $source = trim($body['source'] ?? 'change');

    if (!$routerId && !$kitNumber) $er2('router_id or kit required.', 400);
    $key = $routerId ?: ('kit:' . $kitNumber);

    $pdo->prepare("
        INSERT INTO app_wifi_cache (router_id, crm_client_id, kit_number, ssid, password, ssid_5ghz, password_5ghz, source, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(router_id) DO UPDATE SET
            ssid = excluded.ssid, password = excluded.password,
            ssid_5ghz = excluded.ssid_5ghz, password_5ghz = excluded.password_5ghz,
            source = excluded.source, updated_at = excluded.updated_at
    ")->execute([$key, $clientId, $kitNumber, $ssid, $password, $ssid5, $pass5, $source, time()]);

    $ok2(['saved' => true], 'WiFi credentials cached.');
}

// ─── Save debug report from app/PWA ──────────────────────────────
if ($act === 'app_debug_report' && $met === 'POST') {
    // v4.12.27: bootstrap $pdo from $store — was missing, causing 500s.
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me = ca_require_auth($config, $pdo, $er2);
    $clientId = (int)($me['sub'] ?? 0);

    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_debug_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        crm_client_id INTEGER NOT NULL,
        phone TEXT,
        report TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )");

    $report = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO app_debug_reports (crm_client_id, phone, report, created_at) VALUES (?, ?, ?, ?)")
        ->execute([$clientId, $me['phone'] ?? '', $report, time()]);

    $ok2(['saved' => true, 'id' => $pdo->lastInsertId()], 'Debug report saved.');
}

// ─── List debug reports (admin) ──────────────────────────────────
if ($act === 'app_debug_list' && $met === 'GET') {
    // v4.12.27: bootstrap $pdo from $store — was missing, causing 500s.
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me = ca_require_auth($config, $pdo, $er2);

    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_debug_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        crm_client_id INTEGER NOT NULL,
        phone TEXT,
        report TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )");

    $stmt = $pdo->query("SELECT * FROM app_debug_reports ORDER BY id DESC LIMIT 50");
    $reports = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $ok2(['reports' => $reports], count($reports) . ' reports found.');
}
// ═══════════════════════════════════════════════════════════════════
// HELPER: ca_site_dish_resolve
// ═══════════════════════════════════════════════════════════════════
// Resolves dish online state via cache-first, live-on-stale logic.
// Shared between app_site_diagnostics (auto, 15-min cache) and
// app_site_refresh (manual, always live but rate-limited).
//
// Returns:
//   [
//     'state'      => 'online' | 'offline' | 'unavailable',
//     'age_s'      => int|null  — seconds since underlying data was fresh
//     'source'     => 'cache' | 'live' | 'rate_limited' | 'no_map_entry',
//     'updated_at' => ISO string | null,
//     'error_type' => null | 'dish_unreachable' | 'auth_failed' | 'infrastructure' | 'no_session' | 'rate_limited',
//     'map_entry'  => array|null  — the wifi_router_map.json row (for caller to read SSID etc.)
//   ]
if (!function_exists('ca_site_dish_resolve')) {
    function ca_site_dish_resolve($pdo, int $clientId, string $kitNumber, array $matchedKit, bool $forceFresh, string $triggerKind): array {
        $CACHE_FRESH_SEC    = 15 * 60;  // 15 min — cache usable if younger
        $RATE_LIMIT_SEC     = 5 * 60;   // 5 min — per (client_id, kit) live-fetch interval

        $mapFile = dirname(dirname(dirname(__DIR__))) . '/dishnet-data-report/data/wifi_router_map.json';
        $map = file_exists($mapFile) ? (json_decode(@file_get_contents($mapFile), true) ?? []) : [];

        // Locate the entry for this kit (by kit_serial)
        $mapEntry = null;
        $mapKey   = null;
        foreach ($map as $rid => $rdata) {
            if (!is_array($rdata)) continue;
            $ks = trim((string)($rdata['kit_serial'] ?? ''));
            if ($ks !== '' && $ks === $kitNumber) {
                $mapEntry = $rdata;
                $mapKey   = $rid;
                break;
            }
        }

        $now = time();
        $cachedAt = $mapEntry['cached_clients_at'] ?? '';
        $cachedAge = $cachedAt ? ($now - strtotime($cachedAt)) : null;

        // ── Decision tree ──
        // 1. If we have a fresh cache (< 15 min) AND caller didn't force → return cached
        if (!$forceFresh && $cachedAge !== null && $cachedAge < $CACHE_FRESH_SEC) {
            return [
                'state'      => 'online',  // we successfully reached this router recently
                'age_s'      => $cachedAge,
                'source'     => 'cache',
                'updated_at' => $cachedAt,
                'error_type' => null,
                'map_entry'  => $mapEntry,
            ];
        }

        // 2. Rate limit check — has this (client, kit) had a live fetch in the last 5 min?
        try {
            $stmt = $pdo->prepare(
                "SELECT fetched_at FROM app_site_refresh_log
                 WHERE client_id = ? AND kit_number = ?
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$clientId, $kitNumber]);
            $lastLiveAt = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            $lastLiveAt = null;
        }
        if ($lastLiveAt) {
            $sinceLastLive = $now - strtotime($lastLiveAt);
            if ($sinceLastLive < $RATE_LIMIT_SEC) {
                // Return cached answer (whatever it is) with a rate_limited source flag
                return [
                    'state'      => $cachedAge !== null && $cachedAge < $CACHE_FRESH_SEC ? 'online' : 'unavailable',
                    'age_s'      => $cachedAge,
                    'source'     => 'rate_limited',
                    'updated_at' => $cachedAt ?: null,
                    'error_type' => 'rate_limited',
                    'map_entry'  => $mapEntry,
                ];
            }
        }

        // 3. Do a live fetch. Need router_id to call drWifiFetchStatus.
        $routerId = '';
        if ($mapEntry && !empty($mapKey)) {
            $routerId = strpos($mapKey, 'Router-') === 0 ? $mapKey : 'Router-' . $mapKey;
        } elseif (!empty($matchedKit['router_id_full'])) {
            $routerId = $matchedKit['router_id_full'];
        }

        if (!$routerId) {
            // Nothing to call — no router ever discovered for this kit
            return [
                'state'      => 'unavailable',
                'age_s'      => null,
                'source'     => 'no_map_entry',
                'updated_at' => null,
                'error_type' => 'no_session',
                'map_entry'  => $mapEntry,
            ];
        }

        // Pull in the Data Report helper (v2.8.25+ factored this out for us)
        $drPluginDir  = dirname(dirname(dirname(__DIR__))) . '/dishnet-data-report';
        $drWifiChange = $drPluginDir . '/dr_wifi_change.php';
        if (!file_exists($drWifiChange)) {
            return [
                'state'      => 'unavailable',
                'age_s'      => $cachedAge,
                'source'     => 'live',
                'updated_at' => $cachedAt ?: null,
                'error_type' => 'infrastructure',
                'map_entry'  => $mapEntry,
            ];
        }

        // v4.12.33: Cross-plugin call via internal HTTP instead of PHP include.
        //
        // Original v4.12.31 attempt was `require_once $drWifiChange` from inside
        // this function. That failed with "Undefined variable $pluginDir" because
        // dr_wifi_change.php was designed to be included from Data Report's own
        // public.php where $pluginDir, $drEncKey, session_manager functions and
        // a pile of other scope is already set up. Importing it into Hybrid's
        // function scope fights that design and would require replicating all
        // of Data Report's bootstrap — brittle and high-risk.
        //
        // Correct approach: hit the existing HTTP endpoint via loopback curl.
        // Costs ~300ms extra (two SSL handshakes for dishnetafrica.com), but
        // respects plugin boundaries that already exist. The endpoint is the
        // same one WiFi Manager calls from its admin JS — proven working.
        //
        // Authentication: dr_wifi_get_status is currently open (staff-facing,
        // behind UCRM admin gate). Since this call originates from inside the
        // UCRM host itself, the UCRM admin check is implicitly satisfied via
        // the session cookie the browser sent to us, which we forward. If the
        // admin session cookie isn't available (e.g. customer-app bearer only),
        // the call may 401 — that's handled by the 'infrastructure' branch.
        //
        // URL is hardcoded to crm.dishnetafrica.com rather than pulled from
        // $config — $config isn't in this helper function's scope, and this
        // is an internal loopback call where the hostname is stable anyway.
        $drUrl = 'https://crm.dishnetafrica.com/crm/_plugins/dishnet-data-report/public.php'
               . '?action=dr_wifi_get_status&router_id=' . urlencode($routerId);

        $ch = curl_init($drUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 12,              // gRPC through dish can take a few seconds
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,           // loopback; cert may be self-signed
            CURLOPT_SSL_VERIFYHOST => 0,
            // Forward the UCRM session cookie if present so admin-gated actions work
            CURLOPT_COOKIE => $_SERVER['HTTP_COOKIE'] ?? '',
        ]);
        $httpBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $liveResult = null;
        if ($httpBody !== false && $httpCode === 200) {
            $decoded = json_decode($httpBody, true);
            if (is_array($decoded)) $liveResult = $decoded;
        }
        if ($liveResult === null) {
            // HTTP-layer failure — treat as infrastructure, keep cached answer
            return [
                'state'      => $cachedAge !== null && $cachedAge < $CACHE_FRESH_SEC ? 'online' : 'unavailable',
                'age_s'      => $cachedAge,
                'source'     => 'live',
                'updated_at' => $cachedAt ?: null,
                'error_type' => 'infrastructure',
                'map_entry'  => $mapEntry,
            ];
        }

        // The HTTP endpoint returns a shape similar to drWifiFetchStatus's
        // success branch but doesn't include error_type on failure. Map its
        // error text to our error_type conventions so the downstream logic
        // still gets accurate classification.
        if (empty($liveResult['ok'])) {
            $errMsg = (string)($liveResult['error'] ?? '');
            if (stripos($errMsg, 'TARGETID') !== false) {
                $liveResult['error_type'] = 'dish_unreachable';
            } elseif (stripos($errMsg, 'token_expired') !== false || stripos($errMsg, '401') !== false) {
                $liveResult['error_type'] = 'auth_failed';
            } elseif (stripos($errMsg, 'No active session') !== false) {
                $liveResult['error_type'] = 'no_session';
            } else {
                $liveResult['error_type'] = 'infrastructure';
            }
        }

        // Log the attempt (rate-limit bookkeeping + observability)
        try {
            $outcome = $liveResult['ok'] ? 'online' : ($liveResult['error_type'] ?? 'infrastructure');
            $stmt = $pdo->prepare(
                "INSERT INTO app_site_refresh_log (client_id, kit_number, router_id, outcome, trigger_kind, fetched_at)
                 VALUES (?, ?, ?, ?, ?, datetime('now'))"
            );
            $stmt->execute([$clientId, $kitNumber, $routerId, $outcome, $triggerKind]);
            // Prune rows older than 24h (rate limit only cares about last 5 min)
            $pdo->exec("DELETE FROM app_site_refresh_log WHERE fetched_at < datetime('now', '-24 hours')");
        } catch (\Throwable $e) {
            // Non-fatal — logging fails, live fetch already done
        }

        // Translate the live result into our three customer-facing states
        if ($liveResult['ok']) {
            // Success — refresh the cache on disk so the next page load is fast.
            // Update cached_clients_* fields so drDishOnlineStatus() in the staff
            // WiFi Manager also starts reporting this dish as online.
            if ($mapKey !== null) {
                $clients = $liveResult['clients'] ?? [];
                $wifiN = 0; $wiredN = 0;
                foreach ($clients as $c) {
                    if (!empty($c['is_controller'])) continue;
                    if (($c['band'] ?? '') === 'wired') $wiredN++;
                    else                                 $wifiN++;
                }
                $total = $wifiN + $wiredN;
                $nowIso = date('c');
                $map[$mapKey]['cached_clients_total'] = $total;
                $map[$mapKey]['cached_clients_wifi']  = $wifiN;
                $map[$mapKey]['cached_clients_wired'] = $wiredN;
                $map[$mapKey]['cached_clients_at']    = $nowIso;
                // Also update the v2.8.21 dish_online_* fields so staff tools agree
                $map[$mapKey]['dish_online_state']      = 'online';
                $map[$mapKey]['dish_online_age_s']      = 0;
                $map[$mapKey]['dish_online_updated_at'] = $nowIso;
                // If networks came back, update SSID fields too (bonus freshness)
                $networks = $liveResult['networks'] ?? [];
                if (!empty($networks)) {
                    $primary = null;
                    foreach ($networks as $n) {
                        if (empty($n['disabled'])) { $primary = $n; break; }
                    }
                    if (!$primary) $primary = $networks[0];
                    if ($primary) {
                        $map[$mapKey]['cached_ssid']       = $primary['ssid'] ?? '';
                        $map[$mapKey]['cached_auth']       = $primary['auth_type'] ?? 'wpa2';
                        $map[$mapKey]['wifi_cached_at']    = $nowIso;
                    }
                }
                // Write back. Non-fatal if disk write fails.
                @file_put_contents($mapFile, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $mapEntry = $map[$mapKey];
            }
            return [
                'state'      => 'online',
                'age_s'      => 0,
                'source'     => 'live',
                'updated_at' => date('c'),
                'error_type' => null,
                'map_entry'  => $mapEntry,
            ];
        }

        // Live fetch failed — classify outcome
        $errType = $liveResult['error_type'] ?? 'infrastructure';
        if ($errType === 'dish_unreachable') {
            // Genuine TARGETID — dish didn't answer. This is the only case
            // where we tell the customer their dish is offline.
            return [
                'state'      => 'offline',
                'age_s'      => $cachedAge,
                'source'     => 'live',
                'updated_at' => date('c'),
                'error_type' => 'dish_unreachable',
                'map_entry'  => $mapEntry,
            ];
        }
        // Everything else (auth, infra, no_session) → "unavailable", grey.
        // We DO NOT tell the customer their dish is offline in these cases.
        return [
            'state'      => 'unavailable',
            'age_s'      => $cachedAge,
            'source'     => 'live',
            'updated_at' => $cachedAt ?: null,
            'error_type' => $errType,
            'map_entry'  => $mapEntry,
        ];
    }
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_site_diagnostics  (Bearer)  v4.12.29 · reworked v4.12.31
// ═══════════════════════════════════════════════════════════════════
// Returns a structured bundle of diagnostic data for a single site (kit).
// v4.12.31 shifts the dish-status logic from age-threshold proxy (which
// produced the "Offline · 317d ago" false positives) to a cache-first-
// then-live-fetch pattern mirroring the staff WiFi Manager's proven
// Devices modal flow.
//
// Resolution order:
//   1. Read cached wifi_router_map.json entry (same as before).
//   2. If cached_clients_at < 15 min old → return cached, source='cache'.
//   3. Otherwise → fire a LIVE gRPC call via drWifiFetchStatus() in the
//      Data Report plugin. On success: update the cache on disk with
//      fresh cached_clients_at/_total/_wifi/_wired, return source='live'.
//   4. Rate limit: 1 live fetch per (client_id, kit_number) per 5 min.
//      If limited → fall back to cached answer.
//
// Three outcomes for the dish state (NOT two):
//   - 'online'             → live call succeeded OR cache < 15 min says so
//   - 'offline'            → live call returned TARGETID error (dish genuinely
//                            didn't answer — the real "dish is off" signal)
//   - 'unavailable'        → any other error (auth failure, rate limit, our
//                            infra, etc.). Customer should NOT be told their
//                            dish is offline when the problem is on our side.
//
// Authorization (unchanged): JWT valid + kit's crm_client_id matches me.
if ($act === 'app_site_diagnostics' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $kitNumber = trim($_GET['kit'] ?? '');
    $routerId  = trim($_GET['router_id'] ?? '');
    if (!$kitNumber && !$routerId) $er2('kit or router_id required.', 400);

    // ── STEP 1: Authorize — confirm this kit belongs to the customer ──
    $slKitsFile = dirname(dirname(dirname(__DIR__))) . '/dishnet-starlink-finance/data/sl_kits.json';
    if (!file_exists($slKitsFile)) $er2('Kit registry not available.', 503);

    $slKits = json_decode(@file_get_contents($slKitsFile), true);
    if (!is_array($slKits)) $er2('Kit registry unreadable.', 503);

    $matchedKit = null;
    foreach ($slKits as $k) {
        $kn = trim((string)($k['kit_number'] ?? ''));
        if ($kn === '') continue;
        if ($kitNumber && $kn === $kitNumber) { $matchedKit = $k; break; }
        if ($routerId && !empty($k['router_id_full']) && $k['router_id_full'] === $routerId) {
            $matchedKit = $k;
            break;
        }
    }
    if (!$matchedKit) $er2('Kit not found.', 404);

    $kitCrmId = trim((string)(
        $matchedKit['crm_client_id']    ??
        $matchedKit['assigned_client_id'] ??
        ''
    ));
    if ($kitCrmId === '' || (int)$kitCrmId !== $clientId) {
        $er2('Not authorized for this kit.', 403);
    }

    // Resolve the effective kit_number for logging / cache lookup
    $effectiveKit = $matchedKit['kit_number'] ?? $kitNumber;

    // ── STEP 2: Resolve dish state via cache-first, live-on-stale ─────
    // forceFresh=false means: use cache if < 15 min old. This is the
    // auto path; the explicit app_site_refresh endpoint passes true.
    $resolved = ca_site_dish_resolve($pdo, $clientId, $effectiveKit, $matchedKit, false, 'auto');

    // ── STEP 3: Build response ────────────────────────────────────────
    $mapEntry = $resolved['map_entry']; // may be null if nothing was ever cached
    $source   = $resolved['source'];    // 'cache' | 'live' | 'rate_limited' | 'no_map_entry'

    // Dish status comes from whatever ca_site_dish_resolve decided
    $dish = [
        'state'        => $resolved['state'],        // online | offline | unavailable
        'age_s'        => $resolved['age_s'],         // seconds since data was fresh (0 for just-live)
        'source'       => $source,                   // where this answer came from
        'updated_at'   => $resolved['updated_at'],    // ISO timestamp of underlying data
        'last_live_ok' => $mapEntry['cached_clients_at'] ?? null,
        'error_type'   => $resolved['error_type'],   // null on success; else dish_unreachable | auth_failed | infrastructure | no_session | rate_limited
    ];

    // Device info — from cache (not refreshed on every live call to keep payload small)
    $device = [
        'hardware_version' => $mapEntry['hardware_version'] ?? null,
        'direct_link'      => $mapEntry['direct_link'] ?? null,
        'is_bypassed'      => $mapEntry['is_bypassed'] ?? false,
        'discovered_at'    => $mapEntry['discovered_at'] ?? null,
    ];

    $network = [
        'ssid'             => $mapEntry['cached_ssid'] ?? null,
        'has_password'     => !empty($mapEntry['cached_password']),
        'auth_type'        => $mapEntry['cached_auth'] ?? null,
        'wifi_cached_at'   => $mapEntry['wifi_cached_at'] ?? null,
    ];

    $clients = [
        'total'       => isset($mapEntry['cached_clients_total']) ? (int)$mapEntry['cached_clients_total'] : null,
        'wifi'        => isset($mapEntry['cached_clients_wifi'])  ? (int)$mapEntry['cached_clients_wifi']  : null,
        'wired'       => isset($mapEntry['cached_clients_wired']) ? (int)$mapEntry['cached_clients_wired'] : null,
        'cached_at'   => $mapEntry['cached_clients_at'] ?? null,
        'full_list'   => null,  // fetched separately if the PWA asks
    ];

    $service = [
        'kit_number'    => $matchedKit['kit_number'] ?? null,
        'location'      => $matchedKit['location_name'] ?? $matchedKit['location'] ?? null,
        'plan_name'     => $matchedKit['plan_name'] ?? null,
        'status'        => $matchedKit['starlink_account_status'] ?? null,
        'service_line'  => $matchedKit['service_line'] ?? null,
        'account_num'   => $matchedKit['starlink_account_number'] ?? null,
    ];

    $ok2([
        'dish'    => $dish,
        'device'  => $device,
        'network' => $network,
        'clients' => $clients,
        'service' => $service,
        '_meta'   => [
            'has_map_entry' => $mapEntry !== null,
            'response_time' => date('c'),
            'source'        => $source,
        ],
    ], 'Site diagnostics loaded.');
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_site_refresh  (Bearer)  v4.12.31
// ═══════════════════════════════════════════════════════════════════
// Manual "Refresh" button on the customer Site Detail page.
// Same auth + resolution logic as app_site_diagnostics, but always
// forces a LIVE fetch (bypasses the 15-min cache check). Still
// enforces the 5-min per-customer rate limit. Returns the same shape
// as app_site_diagnostics but with 'trigger=manual' in the log.
if ($act === 'app_site_refresh' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $kitNumber = trim($body['kit'] ?? $_POST['kit'] ?? '');
    if (!$kitNumber) $er2('kit required.', 400);

    // Reuse the same authorization path
    $slKitsFile = dirname(dirname(dirname(__DIR__))) . '/dishnet-starlink-finance/data/sl_kits.json';
    if (!file_exists($slKitsFile)) $er2('Kit registry not available.', 503);
    $slKits = json_decode(@file_get_contents($slKitsFile), true);
    if (!is_array($slKits)) $er2('Kit registry unreadable.', 503);

    $matchedKit = null;
    foreach ($slKits as $k) {
        if (trim((string)($k['kit_number'] ?? '')) === $kitNumber) { $matchedKit = $k; break; }
    }
    if (!$matchedKit) $er2('Kit not found.', 404);

    $kitCrmId = trim((string)(
        $matchedKit['crm_client_id']    ??
        $matchedKit['assigned_client_id'] ??
        ''
    ));
    if ($kitCrmId === '' || (int)$kitCrmId !== $clientId) {
        $er2('Not authorized for this kit.', 403);
    }

    // Force a live fetch (bypass 15-min cache), but still respect 5-min rate limit
    $resolved = ca_site_dish_resolve($pdo, $clientId, $kitNumber, $matchedKit, true, 'manual');

    $ok2([
        'state'        => $resolved['state'],
        'age_s'        => $resolved['age_s'],
        'source'       => $resolved['source'],
        'updated_at'   => $resolved['updated_at'],
        'error_type'   => $resolved['error_type'],
    ], $resolved['source'] === 'rate_limited'
        ? 'Rate limited — showing cached status. Try again in a few minutes.'
        : 'Status refreshed.');
}

// ═══════════════════════════════════════════════════════════════════
// FCM PUSH SERVICE — send push notifications to customer devices
// ═══════════════════════════════════════════════════════════════════
if (!function_exists('ca_send_push')) {
    /**
     * Send a push notification to all devices registered for a CRM client.
     *
     * @param PDO    $pdo      SQLite handle (has app_fcm_tokens table)
     * @param array  $config   Plugin config (needs 'fcm_server_key' or 'fcm_service_account_json')
     * @param int    $clientId CRM client ID
     * @param string $event    Event type (invoice_created, payment_received, etc.)
     * @param string $title    Notification title
     * @param string $body     Notification body
     * @param array  $data     Extra data payload (optional)
     * @return array           ['sent' => int, 'failed' => int, 'errors' => []]
     */
    function ca_send_push($pdo, $config, $clientId, $event, $title, $body, $data = []) {
        $serverKey = trim($config['fcm_server_key'] ?? '');
        if (!$serverKey) {
            return ['sent' => 0, 'failed' => 0, 'errors' => ['FCM server key not configured']];
        }

        // Get all tokens for this client
        $stmt = $pdo->prepare("SELECT token, platform FROM app_fcm_tokens WHERE crm_client_id = ?");
        $stmt->execute([$clientId]);
        $tokens = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($tokens)) {
            return ['sent' => 0, 'failed' => 0, 'errors' => ['No registered devices']];
        }

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($tokens as $device) {
            $payload = [
                'to' => $device['token'],
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'icon' => 'ic_launcher',
                    'color' => '#D41C1C',
                    'sound' => 'default',
                    'click_action' => 'OPEN_CUSTOMER_APP',
                ],
                'data' => array_merge([
                    'event' => $event,
                    'client_id' => (string)$clientId,
                ], $data),
            ];

            $ch = curl_init('https://fcm.googleapis.com/fcm/send');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: key=' . $serverKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($resp, true);
            $success = ($httpCode === 200 && ($result['success'] ?? 0) > 0);

            // Log the push
            $pdo->prepare("
                INSERT INTO app_push_log (crm_client_id, event, title, body, success, error, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $clientId, $event, $title, $body,
                $success ? 1 : 0,
                $success ? null : substr($resp, 0, 500),
                time()
            ]);

            if ($success) {
                $sent++;
            } else {
                $failed++;
                $errors[] = substr($resp, 0, 200);
                // Remove invalid tokens (FCM returns NotRegistered)
                if (isset($result['results'][0]['error']) && 
                    in_array($result['results'][0]['error'], ['NotRegistered', 'InvalidRegistration'])) {
                    $pdo->prepare("DELETE FROM app_fcm_tokens WHERE token = ?")->execute([$device['token']]);
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
    }
}

// ═══════════════════════════════════════════════════════════════════
// PUSH TRIGGER HELPERS — called from notification crons
// ═══════════════════════════════════════════════════════════════════
if (!function_exists('ca_push_invoice_created')) {
    function ca_push_invoice_created($pdo, $config, $clientId, $invoiceNumber, $amount, $currency = 'USD') {
        return ca_send_push($pdo, $config, $clientId, 'invoice_created',
            'New Invoice · DishNet',
            "Invoice {$invoiceNumber} for \${$amount} {$currency} has been created. Tap to view.",
            ['invoice_number' => $invoiceNumber, 'amount' => (string)$amount]
        );
    }
}

if (!function_exists('ca_push_payment_received')) {
    function ca_push_payment_received($pdo, $config, $clientId, $amount, $currency = 'USD') {
        return ca_send_push($pdo, $config, $clientId, 'payment_received',
            'Payment Confirmed · DishNet',
            "Your payment of \${$amount} {$currency} has been received. Thank you!",
            ['amount' => (string)$amount]
        );
    }
}

if (!function_exists('ca_push_service_suspended')) {
    function ca_push_service_suspended($pdo, $config, $clientId, $serviceName = '') {
        $body = $serviceName
            ? "Your service '{$serviceName}' has been suspended due to non-payment."
            : "Your service has been suspended due to non-payment.";
        return ca_send_push($pdo, $config, $clientId, 'service_suspended',
            'Service Suspended · DishNet',
            $body . ' Please pay your outstanding balance to restore service.',
            ['service' => $serviceName]
        );
    }
}

if (!function_exists('ca_push_service_activated')) {
    function ca_push_service_activated($pdo, $config, $clientId, $serviceName = '') {
        $body = $serviceName
            ? "Your service '{$serviceName}' is now active!"
            : "Your service is now active!";
        return ca_send_push($pdo, $config, $clientId, 'service_activated',
            'Service Active · DishNet',
            $body . ' Enjoy your DishNet connection.',
            ['service' => $serviceName]
        );
    }
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_device_blocklist_get  (Bearer)
// v4.12.12 — per-customer hidden-device list for the Connected Devices view.
// Returns: {macs: ["aa:bb:...", ...], updated_at: "ISO8601" | null}
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_device_blocklist_get' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();
    $claims   = ca_require_auth($config, $pdo, $er2);
    $customerId = (int)($claims['sub'] ?? 0);
    if ($customerId <= 0) $er2('Invalid token claims.', 401);

    $all = $store->load('device_blocklist.json') ?? [];
    $entry = $all[$customerId] ?? null;
    $ok2([
        'customer_id' => $customerId,
        'macs'        => is_array($entry['macs'] ?? null) ? array_values($entry['macs']) : [],
        'updated_at'  => $entry['updated_at'] ?? null,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_device_blocklist_toggle  (Bearer, POST)
// v4.12.12 — add or remove one MAC address from this customer's blocklist.
// Body (form or JSON): { mac: "aa:bb:cc:dd:ee:ff", action: "block" | "unblock" }
// Optional body: { macs: ["...", "..."], action: "bulk_set" } to replace whole list
// (used for one-time localStorage → server migration on first load).
// Returns: updated {macs: [...], updated_at: "ISO8601"}
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_device_blocklist_toggle' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();
    $claims   = ca_require_auth($config, $pdo, $er2);
    $customerId = (int)($claims['sub'] ?? 0);
    if ($customerId <= 0) $er2('Invalid token claims.', 401);

    // Accept JSON body or form POST
    $body = $_POST;
    if (empty($body)) {
        $raw = file_get_contents('php://input');
        if ($raw) { $decoded = json_decode($raw, true); if (is_array($decoded)) $body = $decoded; }
    }

    $action = strtolower(trim($body['action'] ?? ''));
    if (!in_array($action, ['block','unblock','bulk_set'], true)) {
        $er2('action must be block, unblock, or bulk_set', 400);
    }

    // Normalize a single MAC: lowercase, strip whitespace, accept both : and - separators
    $normMac = function ($m) {
        $m = strtolower(trim((string)$m));
        $m = str_replace('-', ':', $m);
        return preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $m) ? $m : '';
    };

    $all = $store->load('device_blocklist.json') ?? [];
    $entry = $all[$customerId] ?? ['macs' => [], 'updated_at' => null];
    $current = is_array($entry['macs'] ?? null) ? array_values($entry['macs']) : [];

    if ($action === 'bulk_set') {
        $incoming = is_array($body['macs'] ?? null) ? $body['macs'] : [];
        $cleaned = [];
        foreach ($incoming as $m) {
            $nm = $normMac($m);
            if ($nm && !in_array($nm, $cleaned, true)) $cleaned[] = $nm;
        }
        // Cap at 200 MACs per customer to avoid abuse
        if (count($cleaned) > 200) $cleaned = array_slice($cleaned, 0, 200);
        $current = $cleaned;
    } else {
        $mac = $normMac($body['mac'] ?? '');
        if (!$mac) $er2('Invalid MAC address format.', 400);

        if ($action === 'block') {
            if (!in_array($mac, $current, true)) $current[] = $mac;
            if (count($current) > 200) $current = array_slice($current, -200);
        } else {
            $current = array_values(array_filter($current, fn($m) => $m !== $mac));
        }
    }

    $all[$customerId] = [
        'macs'       => $current,
        'updated_at' => date('c'),
    ];
    $store->save('device_blocklist.json', $all);

    $ok2([
        'customer_id' => $customerId,
        'macs'        => $current,
        'updated_at'  => $all[$customerId]['updated_at'],
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// v4.14.0 — HOTSPOT MODE ENDPOINTS (Phase 1a — backend only)
// ═══════════════════════════════════════════════════════════════════
// These two endpoints are the foundation for the "Starlink Hotspot"
// flavor of the customer app described in dishnet-africa-app-v3-prototype.
// Phase 1a ships the backend only; the matching UI screens
// (s-hotspot, s-hotspot-pw) are a future session.
//
// DESIGN DECISIONS (locked in on 25 Apr 2026, do not re-debate):
//   - SSID: customer chooses their own when enabling hotspot mode.
//     No auto-prefix like "DishNet-*". Empty SSID at toggle time is allowed
//     and means "keep current SSID, just flag the router as hotspot mode".
//   - Password: when rotation eventually lands, format will be
//     word+digits (e.g. "cafe-4821") for verbal communication.
//   - Toggle permission: BOTH customer (via their app) AND staff (future
//     admin UI). This endpoint is the customer-side path; staff side
//     uses a separate admin endpoint (not built yet).
//   - Existing password on hotspot ON: NOT rotated. The toggle is
//     purely a mode flag. Customer must explicitly rotate to change
//     the password (and kick current users).
//   - Pause permissions in hotspot mode: customer can pause any device
//     on their dish (already possible via the Data Report plugin's
//     dr_wifi_pause_client endpoint; no new logic needed here).
//
// SCOPING SAFETY:
//   - Customer browser NEVER supplies router_id that's trusted blindly.
//   - We use the same pattern as app_site_diagnostics: match router_id
//     against sl_kits.json entries and verify the kit's crm_client_id
//     equals the authenticated clientId. If not, 403.
//
// STORAGE:
//   - hotspot_config.json at plugin/data/ (created on demand).
//   - Keyed by router_id_full (e.g. "Router-01000000...").
//   - Shape:
//       {
//         "Router-XXX": {
//           "hotspot_mode": bool,
//           "ssid_on_enable": string | null,   // customer's chosen SSID
//           "enabled_at": ISO8601 | null,
//           "enabled_by_client_id": int | null,
//           "last_toggled_at": ISO8601,
//           "last_toggled_by": "client:<id>" | "staff:<username>"
//         }
//       }
// ═══════════════════════════════════════════════════════════════════

/**
 * Resolve the kit that a customer "owns" for a given router_id.
 * Returns the kit row on success, calls $er2() (which exits) on failure.
 *
 * Separated from app_site_diagnostics so both endpoints use the same
 * authorization logic verbatim. ANY future endpoint that takes a
 * router_id from the customer browser MUST use this helper.
 *
 * @param string $routerId     Router-XXX (full ID)
 * @param int    $clientId     Authenticated CRM client ID from JWT
 * @param callable $er2        Error callback
 * @return array               The matched kit row
 */
if (!function_exists('ca_hotspot_authz_router')) {
    function ca_hotspot_authz_router(string $routerId, int $clientId, $er2): array {
        if ($routerId === '') { $er2('router_id required.', 400); }
        if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

        $slKitsFile = dirname(dirname(dirname(__DIR__))) . '/dishnet-starlink-finance/data/sl_kits.json';
        if (!file_exists($slKitsFile)) { $er2('Kit registry not available.', 503); }

        $slKits = json_decode(@file_get_contents($slKitsFile), true);
        if (!is_array($slKits)) { $er2('Kit registry unreadable.', 503); }

        // ── Path A (fast path, original v4.14.0 behaviour): direct match on
        // sl_kits.json's router_id_full field. Works when sl_kits.json has
        // router_id_full populated. ────────────────────────────────────────
        $matchedKit = null;
        foreach ($slKits as $k) {
            if (!empty($k['router_id_full']) && $k['router_id_full'] === $routerId) {
                $matchedKit = $k;
                break;
            }
        }

        // ── Path B (v4.15.2 fallback): for kits where sl_kits.json doesn't
        // know the router_id_full (or has it stale), look the router up in
        // dishnet-data-report's wifi_router_map.json to get the kit_serial
        // / terminal_id, then cross-reference that back to sl_kits.json by
        // kit_number. This mirrors how Site Detail and app_site_diagnostics
        // resolve router → kit → customer for the same customer.
        //
        // Diag note: the diagnostic script in v4.15.0 surfaced this gap —
        // the data-report plugin's wifi_router_map.json had this router
        // mapped (so dr_wifi_get_status worked, app_site_diagnostics worked,
        // the entry card rendered) but sl_kits.json's router_id_full field
        // for the same kit was empty / unset, so the toggle endpoint's
        // direct lookup returned 404. ────────────────────────────────────
        if (!$matchedKit) {
            $drMapFile = dirname(dirname(dirname(__DIR__))) . '/dishnet-data-report/data/wifi_router_map.json';
            if (file_exists($drMapFile)) {
                $drRaw = @file_get_contents($drMapFile);
                $drMap = $drRaw !== false ? json_decode($drRaw, true) : null;
                if (is_array($drMap)) {
                    // wifi_router_map.json is keyed by router_id_full.
                    // Some installs may key by short id (post-Router-),
                    // so try both forms.
                    $entry = null;
                    if (isset($drMap[$routerId])) {
                        $entry = $drMap[$routerId];
                    } elseif (strpos($routerId, 'Router-') === 0) {
                        $short = substr($routerId, 7);
                        if (isset($drMap[$short])) $entry = $drMap[$short];
                    }
                    if (is_array($entry)) {
                        // Pull every plausible kit-identifying field and try
                        // to match it back to sl_kits.json.
                        $candidates = array_values(array_filter([
                            $entry['kit_serial']  ?? null,
                            $entry['terminal_id'] ?? null,
                            $entry['kit_number']  ?? null,
                        ], function($v){ return is_string($v) && $v !== ''; }));
                        $serviceLine = trim((string)($entry['service_line']   ?? ''));
                        $accountNum  = trim((string)($entry['account_number'] ?? ''));

                        foreach ($slKits as $k) {
                            $kn = trim((string)($k['kit_number'] ?? ''));
                            if ($kn === '') continue;
                            $hit = false;
                            // Exact / substring match on any kit identifier
                            foreach ($candidates as $c) {
                                if ($c === $kn || strpos($c, $kn) !== false || strpos($kn, $c) !== false) {
                                    $hit = true;
                                    break;
                                }
                            }
                            // Service-line match (each SL is unique to one kit)
                            if (!$hit && $serviceLine && !empty($k['service_line']) && $k['service_line'] === $serviceLine) {
                                $hit = true;
                            }
                            // Account-number match — only safe when the account
                            // has exactly one router (mirrors portal_data.php
                            // line 489 logic). Otherwise we'd grant access to
                            // ANY customer on a multi-router account.
                            if (!$hit && $accountNum && !empty($k['starlink_account_number'])
                                && $k['starlink_account_number'] === $accountNum) {
                                $accRouterCount = 0;
                                foreach ($drMap as $r) {
                                    if (is_array($r) && ($r['account_number'] ?? '') === $accountNum) $accRouterCount++;
                                }
                                if ($accRouterCount === 1) $hit = true;
                            }
                            if ($hit) { $matchedKit = $k; break; }
                        }
                    }
                }
            }
        }

        if (!$matchedKit) { $er2('Router not found.', 404); }

        $kitCrmId = trim((string)(
            $matchedKit['crm_client_id']    ??
            $matchedKit['assigned_client_id'] ??
            ''
        ));
        if ($kitCrmId === '' || (int)$kitCrmId !== $clientId) {
            $er2('Not authorized for this router.', 403);
        }

        return $matchedKit;
    }
}

/**
 * Load the hotspot_config.json blob (or return empty array if missing).
 * Atomic file I/O via the existing $store pattern so concurrent saves
 * don't corrupt the file.
 */
if (!function_exists('ca_hotspot_load_config')) {
    function ca_hotspot_load_config($store): array {
        $cfg = $store->load('hotspot_config.json');
        return is_array($cfg) ? $cfg : [];
    }
}

/**
 * Save the hotspot_config.json blob.
 */
if (!function_exists('ca_hotspot_save_config')) {
    function ca_hotspot_save_config($store, array $cfg): void {
        $store->save('hotspot_config.json', $cfg);
    }
}

/**
 * Normalize SSID — simple hygiene (trim, length cap, strip control chars).
 * Starlink accepts SSIDs up to 32 bytes UTF-8. We cap at 32 chars for
 * simplicity (2-byte UTF-8 sequences will be rejected with 400; we don't
 * do full byte-length math here because 99% of real-world SSIDs are
 * ASCII or extended-ASCII).
 */
if (!function_exists('ca_hotspot_clean_ssid')) {
    function ca_hotspot_clean_ssid(string $raw): string {
        $s = trim($raw);
        // Strip control chars (newlines, tabs, NUL) that would break WifiConfig
        $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s);
        if (strlen($s) > 32) $s = substr($s, 0, 32);
        return $s;
    }
}

/**
 * v4.17.0 — Generate a random Wi-Fi password.
 * v4.18.0 — Reduced from 12 chars (mixed case) to 8 chars (uppercase + digits).
 *           Customers are shown the new password BEFORE it's applied, then have
 *           to retype it to reconnect their own devices once the change lands.
 *           A short uppercase+digit string is fast to read aloud, easy to
 *           dictate, and quick to retype without errors. Charset excludes
 *           O/I and 0/1 to avoid visual ambiguity in print or on a screen.
 *
 *           NOTE: WPA2-PSK requires 8-63 characters minimum. We can't go
 *           below 8 without the router rejecting the change. Length 8 is
 *           the protocol floor and gives ~40 bits entropy, which is
 *           acceptable for a residential / small-business hotspot.
 *
 * Charset: ABCDEFGHJKLMNPQRSTUVWXYZ23456789  (32 characters)
 * Length:  8 chars (WPA2 minimum)
 * Entropy: log2(32^8) = 40 bits
 */
if (!function_exists('ca_hotspot_generate_password')) {
    function ca_hotspot_generate_password(int $length = 8): string {
        $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($charset) - 1;
        // WPA2 protocol floor — anything below 8 will be rejected by the
        // router. Hard-clamp here so a future caller can't accidentally
        // trigger a Starlink-side rejection with a too-short value.
        if ($length < 8) $length = 8;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            // random_int is cryptographically secure — important here since
            // this becomes a real Wi-Fi credential for the customer.
            $out .= $charset[random_int(0, $max)];
        }
        return $out;
    }
}

/**
 * v4.17.0 — Server-side curl helper for the data-report plugin's
 * dr_wifi_get_config (live cloud fetch) and dr_wifi_change_password
 * endpoints. Mirrors the pattern at line 1870-1886 (cross-plugin internal
 * loopback). Used by the new hotspot enable flow which needs to know the
 * current SSID and push a fresh password.
 *
 * Returns parsed JSON or null on any failure (HTTP, JSON, network).
 */
if (!function_exists('ca_hotspot_dr_call')) {
    function ca_hotspot_dr_call(string $action, array $params, string $method = 'GET'): ?array {
        $base = 'https://crm.dishnetafrica.com/crm/_plugins/dishnet-data-report/public.php';
        $ch = null;
        try {
            if ($method === 'POST') {
                $url = $base . '?action=' . urlencode($action);
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            } else {
                $qs = http_build_query(array_merge(['action' => $action], $params));
                $url = $base . '?' . $qs;
                $ch = curl_init($url);
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 20,    // wifi push to Starlink can be slow
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false, // loopback; cert may be self-signed
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_COOKIE         => $_SERVER['HTTP_COOKIE'] ?? '',
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code !== 200) return null;
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            if ($ch) { @curl_close($ch); }
            return null;
        }
    }
}

/**
 * v4.17.0 — Mirror SSID + password into app_wifi_cache. Called after a
 * successful hotspot enable so the existing wifi_change cache stays in sync
 * with what the hotspot flow just pushed. Other parts of the app (Site
 * Detail, Change WiFi screen) read from this cache and would otherwise
 * show stale data.
 */
if (!function_exists('ca_hotspot_mirror_wifi_cache')) {
    function ca_hotspot_mirror_wifi_cache(\PDO $pdo, string $routerId, int $clientId, string $kitNumber, string $ssid, string $password): void {
        try {
            $key = $routerId ?: ('kit:' . $kitNumber);
            $pdo->prepare("
                INSERT INTO app_wifi_cache (router_id, crm_client_id, kit_number, ssid, password, ssid_5ghz, password_5ghz, source, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(router_id) DO UPDATE SET
                    ssid = excluded.ssid, password = excluded.password,
                    ssid_5ghz = excluded.ssid_5ghz, password_5ghz = excluded.password_5ghz,
                    source = excluded.source, updated_at = excluded.updated_at
            ")->execute([$key, $clientId, $kitNumber, $ssid, $password, $ssid, $password, 'hotspot_enable', time()]);
        } catch (\Throwable $e) {
            // Cache mirror is best-effort — if the table has migrated, never
            // block the actual hotspot flow on this. Log but don't throw.
            error_log('[hotspot] app_wifi_cache mirror failed: ' . $e->getMessage());
        }
    }
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_hotspot_status  (Bearer, GET)
// ═══════════════════════════════════════════════════════════════════
// Returns current hotspot configuration for ONE of the customer's
// routers. Scoped server-side — customer-supplied router_id is verified
// against their owned kits.
//
// Request:
//   GET ?page=api&action=app_hotspot_status&router_id=Router-XXX
//
// Response data:
//   {
//     router_id: "Router-XXX",
//     hotspot_mode: bool,
//     ssid_on_enable: string | null,
//     enabled_at: ISO8601 | null,
//     enabled_by_client_id: int | null,
//     last_toggled_at: ISO8601 | null,
//     last_toggled_by: string | null,
//     supports_rotation: false,   // Phase 1a: rotation UI exists but no backend
//     supports_scheduling: false  // Phase 2 deliverable
//   }
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_hotspot_status' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me       = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $routerId = trim($_GET['router_id'] ?? '');
    if ($routerId === '') { $er2('router_id required.', 400); }
    if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

    // Authz: verify this router belongs to this customer
    ca_hotspot_authz_router($routerId, $clientId, $er2);

    // Load config
    $cfg = ca_hotspot_load_config($store);
    $entry = $cfg[$routerId] ?? null;

    $ok2([
        'router_id'            => $routerId,
        'hotspot_mode'         => (bool)($entry['hotspot_mode'] ?? false),
        'ssid_on_enable'       => $entry['ssid_on_enable'] ?? null,
        // v4.17.0: dashboard renders Wi-Fi credentials from stored values
        // (no more cloud round-trip on every dashboard load). These get
        // populated by the enable flow which generates+pushes a fresh
        // password to Starlink and saves the result here on confirm.
        'wifi_ssid'            => $entry['wifi_ssid']     ?? null,
        'wifi_password'        => $entry['wifi_password'] ?? null,
        'wifi_synced_at'       => $entry['wifi_synced_at'] ?? null,
        'enabled_at'           => $entry['enabled_at'] ?? null,
        'enabled_by_client_id' => isset($entry['enabled_by_client_id'])
                                    ? (int)$entry['enabled_by_client_id']
                                    : null,
        'last_toggled_at'      => $entry['last_toggled_at'] ?? null,
        'last_toggled_by'      => $entry['last_toggled_by'] ?? null,
        'supports_rotation'    => false,  // Phase 1a
        'supports_scheduling'  => false,  // Phase 2
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_hotspot_prepare  (Bearer, POST)  v4.18.0
// ═══════════════════════════════════════════════════════════════════
// Pre-flight for hotspot enable: confirm router is online, read its
// current SSID, and generate a fresh Wi-Fi password. Does NOT push
// anything to the router and does NOT change hotspot_mode.
//
// Why this is a separate step from toggle_mode: the customer's own
// device is connected to the same Wi-Fi we're about to change. If we
// push the new password before showing it to the customer, their device
// disconnects and they can't read the new password from the dashboard.
// app_hotspot_prepare lets the client show the password to the customer
// FIRST, get explicit acknowledgment, then call toggle_mode to actually
// apply it.
//
// Request body (JSON):
//   { router_id: "Router-XXX" }
//
// Response data:
//   { router_id, wifi_ssid, wifi_password }
// Returns 503 if the router is offline (same precondition as toggle_mode).
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_hotspot_prepare' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me       = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $body = $_POST;
    if (empty($body)) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
    }

    $routerId = trim((string)($body['router_id'] ?? ''));
    if ($routerId === '') { $er2('router_id required.', 400); }
    if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

    // Authz
    ca_hotspot_authz_router($routerId, $clientId, $er2);

    // Pre-flight — router online + readable SSID
    $live = ca_hotspot_dr_call('dr_wifi_get_config', [
        'router_id' => $routerId,
    ], 'GET');
    if (!$live || empty($live['ok']) || empty($live['networks'])) {
        $er2('Router is offline. Connect your dish, then try again.', 503);
    }
    $currentSsid = trim((string)($live['networks'][0]['ssid'] ?? ''));
    if ($currentSsid === '') {
        $er2('Could not read current Wi-Fi network. Try again in a moment.', 503);
    }

    // Generate fresh password — NOT pushed, NOT stored. Lives only in this
    // response until the client confirms with toggle_mode.
    $newPassword = ca_hotspot_generate_password();

    $ok2([
        'router_id'     => $routerId,
        'wifi_ssid'     => $currentSsid,
        'wifi_password' => $newPassword,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_hotspot_toggle_mode  (Bearer, POST)
// ═══════════════════════════════════════════════════════════════════
// Flips hotspot_mode on or off for one of the customer's routers.
// Does NOT rotate the password (decision locked in on 25 Apr 2026).
// Does NOT change the SSID (that's Phase 1b — rotation+rename flow).
//
// Request body (JSON):
//   {
//     router_id: "Router-XXX",
//     enable: true | false,
//     ssid: string  (optional, only used when enable=true; first-time
//                    SSID the customer picked for their hotspot — stored
//                    for later reference and upcoming rotation flow)
//   }
//
// Response data:
//   {
//     router_id: "Router-XXX",
//     hotspot_mode: bool,
//     toggled_at: ISO8601
//   }
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_hotspot_toggle_mode' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me       = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    // Accept JSON body
    $body = $_POST;
    if (empty($body)) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
    }

    $routerId = trim((string)($body['router_id'] ?? ''));
    if ($routerId === '') { $er2('router_id required.', 400); }
    if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

    // enable is required and must be explicit bool-ish
    if (!array_key_exists('enable', $body)) {
        $er2('enable (true|false) required.', 400);
    }
    $enable = (bool)$body['enable'];

    // Optional SSID — only honored when enabling
    $ssid = '';
    if ($enable && !empty($body['ssid'])) {
        $ssid = ca_hotspot_clean_ssid((string)$body['ssid']);
        if ($ssid === '') {
            $er2('Invalid SSID (empty after cleaning).', 400);
        }
    }

    // Authz: verify this router belongs to this customer, get the matched kit
    // (used below to populate kit_number on app_wifi_cache mirror).
    $matchedKit = ca_hotspot_authz_router($routerId, $clientId, $er2);
    $kitNumber  = trim((string)($matchedKit['kit_number'] ?? ''));

    $cfg   = ca_hotspot_load_config($store);
    $now   = date('c');
    $byTag = 'client:' . $clientId;

    if ($enable) {
        // ── v4.17.0 enable flow: pre-flight + push + verify ────────────────
        // v4.18.0: password may be supplied by the client (from a preceding
        // app_hotspot_prepare call which showed it to the customer first).
        // If absent, fall back to generating one server-side so callers that
        // don't use the prepare step still work.

        $existing = $cfg[$routerId] ?? [];

        // 1. PRE-FLIGHT — read current SSID from cloud. Doubles as router
        // online check (Q3: block enable when router is offline).
        $preflight = ca_hotspot_dr_call('dr_wifi_get_config', [
            'router_id' => $routerId,
        ], 'GET');
        if (!$preflight || empty($preflight['ok']) || empty($preflight['networks'])) {
            $er2('Router is offline. Connect your dish, then try again.', 503);
        }
        $currentSsid = trim((string)($preflight['networks'][0]['ssid'] ?? ''));
        if ($currentSsid === '') {
            $er2('Could not read current Wi-Fi network. Try again in a moment.', 503);
        }

        // 2. PASSWORD — prefer the client-supplied one (from prepare step).
        //    Validate: at least 8 chars (WPA2 min), no control characters.
        //    If the client didn't send one, generate fresh server-side.
        $clientSupplied = (string)($body['password'] ?? '');
        if ($clientSupplied !== '') {
            // Strip control chars, trim
            $clientSupplied = preg_replace('/[\x00-\x1F\x7F]/', '', trim($clientSupplied));
            if (strlen($clientSupplied) < 8 || strlen($clientSupplied) > 63) {
                $er2('Password must be 8-63 characters.', 400);
            }
            $newPassword = $clientSupplied;
        } else {
            $newPassword = ca_hotspot_generate_password();
        }

        // 3. PUSH to Starlink via data-report's dr_wifi_change_password.
        // Keep the existing SSID — Q1 decision: don't rename the customer's
        // network, just rotate the password. Set both 2.4 and 5GHz bands.
        $push = ca_hotspot_dr_call('dr_wifi_change_password', [
            'router_id'     => $routerId,
            'ssid'          => $currentSsid,
            'password'      => $newPassword,
            'ssid_5ghz'     => $currentSsid,
            'password_5ghz' => $newPassword,
            'auth_type'     => 'wpa2',
        ], 'POST');
        if (!$push || empty($push['ok'])) {
            $errMsg = is_array($push) ? (string)($push['error'] ?? 'Unknown error') : 'No response from router';
            $er2('Could not push new password to router: ' . $errMsg, 502);
        }

        // 4. VERIFY — wait then re-fetch. The existing wifi_change flow waits
        // 3s on the client; we wait 4s server-side to give Starlink cloud
        // a bit more time, since this is a one-shot operation.
        sleep(4);
        $verify = ca_hotspot_dr_call('dr_wifi_get_config', [
            'router_id' => $routerId,
        ], 'GET');

        $verified = false;
        if ($verify && !empty($verify['ok']) && !empty($verify['networks'])) {
            $vSsid = trim((string)($verify['networks'][0]['ssid'] ?? ''));
            $vPass = (string)($verify['networks'][0]['password'] ?? '');
            // dr_wifi_get_config returns the real password (not redacted),
            // so we can confirm the push actually applied.
            if ($vSsid === $currentSsid && $vPass === $newPassword) {
                $verified = true;
            }
        }
        // If verification didn't catch up in 4s but the push returned ok,
        // we accept the push result. Starlink occasionally takes 10-30s to
        // propagate. The customer's QR will work — we just couldn't confirm
        // synchronously. Log this case so we can see how often it happens.
        if (!$verified) {
            error_log('[hotspot] enable: push ok but verify lagged — router=' . $routerId);
        }

        // 5. SAVE to hotspot_config.json — full record now includes
        // wifi_ssid + wifi_password so the dashboard renders directly.
        $cfg[$routerId] = [
            'hotspot_mode'         => true,
            'ssid_on_enable'       => $ssid !== ''
                                      ? $ssid
                                      : ($existing['ssid_on_enable'] ?? null),
            // v4.17.0: actual Wi-Fi credentials we just pushed
            'wifi_ssid'            => $currentSsid,
            'wifi_password'        => $newPassword,
            'wifi_synced_at'       => $now,
            'enabled_at'           => $existing['enabled_at'] ?? $now,
            'enabled_by_client_id' => $existing['enabled_by_client_id']
                                      ?? $clientId,
            'last_toggled_at'      => $now,
            'last_toggled_by'      => $byTag,
        ];
        ca_hotspot_save_config($store, $cfg);

        // 6. MIRROR to app_wifi_cache so the existing Change WiFi screen and
        // anywhere else that reads cached credentials stays in sync.
        ca_hotspot_mirror_wifi_cache($pdo, $routerId, $clientId, $kitNumber, $currentSsid, $newPassword);

        // Audit
        ca_audit($pdo, $clientId, 'hotspot_enabled', null, json_encode([
            'router_id'    => $routerId,
            'ssid'         => $ssid !== '' ? $ssid : null,
            'wifi_ssid'    => $currentSsid,
            'verified'     => $verified,
        ]));

        $ok2([
            'router_id'    => $routerId,
            'hotspot_mode' => true,
            'wifi_ssid'    => $currentSsid,
            'wifi_password'=> $newPassword,
            'verified'     => $verified,
            'toggled_at'   => $now,
        ]);
    } else {
        // ── Disable: flip mode flag, keep stored credentials (so dashboard
        // re-enable can show last-known instantly without another push).
        // The actual Wi-Fi password on the router stays exactly as-is.
        $existing = $cfg[$routerId] ?? [];
        $cfg[$routerId] = [
            'hotspot_mode'         => false,
            'ssid_on_enable'       => $existing['ssid_on_enable'] ?? null,
            // Preserve credentials — they're still the live values on the router.
            'wifi_ssid'            => $existing['wifi_ssid'] ?? null,
            'wifi_password'        => $existing['wifi_password'] ?? null,
            'wifi_synced_at'       => $existing['wifi_synced_at'] ?? null,
            'enabled_at'           => $existing['enabled_at'] ?? null,
            'enabled_by_client_id' => $existing['enabled_by_client_id'] ?? null,
            'last_toggled_at'      => $now,
            'last_toggled_by'      => $byTag,
        ];
        ca_hotspot_save_config($store, $cfg);

        ca_audit($pdo, $clientId, 'hotspot_disabled', null, json_encode([
            'router_id' => $routerId,
        ]));

        $ok2([
            'router_id'    => $routerId,
            'hotspot_mode' => false,
            'toggled_at'   => $now,
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_hotspot_resync  (Bearer, POST)  v4.17.0
// ═══════════════════════════════════════════════════════════════════
// Re-fetch the live SSID + password from Starlink cloud and refresh both
// hotspot_config.json and app_wifi_cache. Used by the dashboard "Refresh"
// button when the customer has changed the password externally (Change
// WiFi screen, or directly through Starlink's app) and wants the hotspot
// dashboard / QR to show the current values.
//
// Does NOT generate a new password. This is purely a read-from-cloud +
// store-locally operation. The customer can use Disable → Re-enable to
// rotate the password if they want a fresh one.
//
// Request body (JSON):
//   { router_id: "Router-XXX" }
//
// Response data:
//   { router_id, wifi_ssid, wifi_password, synced_at }
// Returns error 503 if the router is offline.
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_hotspot_resync' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me       = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $body = $_POST;
    if (empty($body)) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
    }

    $routerId = trim((string)($body['router_id'] ?? ''));
    if ($routerId === '') { $er2('router_id required.', 400); }
    if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

    $matchedKit = ca_hotspot_authz_router($routerId, $clientId, $er2);
    $kitNumber  = trim((string)($matchedKit['kit_number'] ?? ''));

    // Fetch live from cloud
    $live = ca_hotspot_dr_call('dr_wifi_get_config', [
        'router_id' => $routerId,
    ], 'GET');
    if (!$live || empty($live['ok']) || empty($live['networks'])) {
        $er2('Router is offline. Try again when the dish is online.', 503);
    }
    $liveSsid = trim((string)($live['networks'][0]['ssid'] ?? ''));
    $livePass = (string)($live['networks'][0]['password'] ?? '');
    if ($liveSsid === '' || $livePass === '') {
        $er2('Could not read Wi-Fi config from router.', 503);
    }
    // Detect redacted/bullet response — same guard the client has
    if (preg_match('/^[\x{2022}\*]+$/u', $livePass)) {
        $er2('Router returned a redacted password. Try again in a moment.', 503);
    }

    $cfg = ca_hotspot_load_config($store);
    $existing = $cfg[$routerId] ?? [];
    $now = date('c');
    $cfg[$routerId] = array_merge($existing, [
        'wifi_ssid'      => $liveSsid,
        'wifi_password'  => $livePass,
        'wifi_synced_at' => $now,
    ]);
    ca_hotspot_save_config($store, $cfg);

    // Mirror to app_wifi_cache
    ca_hotspot_mirror_wifi_cache($pdo, $routerId, $clientId, $kitNumber, $liveSsid, $livePass);

    ca_audit($pdo, $clientId, 'hotspot_resync', null, json_encode([
        'router_id' => $routerId,
        'wifi_ssid' => $liveSsid,
    ]));

    $ok2([
        'router_id'     => $routerId,
        'wifi_ssid'     => $liveSsid,
        'wifi_password' => $livePass,
        'synced_at'     => $now,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_devices_record_seen  (Bearer, POST)  v4.19.0
// ═══════════════════════════════════════════════════════════════════
// Bulk-upsert sighting records. Called from the dashboard after each
// dr_wifi_get_status fetch so we maintain a persistent log of which
// device fingerprints have been on this router. Drives the NEW badge
// on the connected-devices view.
//
// Request body (JSON):
//   {
//     router_id: "Router-XXX",
//     devices: [
//       { fingerprint: "abc123", hostname: "John's iPhone" },
//       ...
//     ]
//   }
//
// Response:
//   { recorded: N, new_count: N }
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_devices_record_seen' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me       = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $body = $_POST;
    if (empty($body)) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
    }

    $routerId = trim((string)($body['router_id'] ?? ''));
    if ($routerId === '') { $er2('router_id required.', 400); }
    if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

    $devices = $body['devices'] ?? [];
    if (!is_array($devices)) $devices = [];

    // Authz
    ca_hotspot_authz_router($routerId, $clientId, $er2);

    $now = time();
    $newCount = 0;
    $recorded = 0;

    // v4.19.0: detect first-ever sighting for this router. If the seen
    // log is completely empty for this router_id, we're on first run
    // (either fresh install or just after upgrade). In that case,
    // auto-acknowledge the devices we're about to insert so the customer
    // doesn't open the dashboard to a flood of NEW badges on every device.
    // Only genuinely new fingerprints (joined after this seed) will be
    // flagged.
    $isFirstSeed = false;
    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM hotspot_seen_devices WHERE router_id = ? LIMIT 1");
        $countStmt->execute([$routerId]);
        $isFirstSeed = ((int)$countStmt->fetchColumn() === 0);
    } catch (\Throwable $e) {
        // If the count fails, assume not first seed — safer to flag a few
        // false NEWs than to silently miss a real one.
    }

    // Find existing fingerprints in one query so we can tell new from old.
    // v4.20.0: also pull current ip_history and hostname_history so we can
    // diff and append (capped at last 5 each).
    $existingFp = [];
    $existingHist = []; // fp → ['ips'=>[], 'hostnames'=>[]]
    if (!empty($devices)) {
        $fps = [];
        foreach ($devices as $d) {
            if (!empty($d['fingerprint'])) $fps[] = (string)$d['fingerprint'];
        }
        if (!empty($fps)) {
            $placeholders = implode(',', array_fill(0, count($fps), '?'));
            $params = array_merge([$routerId], $fps);
            $stmt = $pdo->prepare("SELECT fingerprint, ip_history, hostname_history, ip_last, hostname_last FROM hotspot_seen_devices WHERE router_id = ? AND fingerprint IN ($placeholders)");
            $stmt->execute($params);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $existingFp[$row['fingerprint']] = true;
                $ipHist = json_decode($row['ip_history'] ?? '[]', true);
                $hnHist = json_decode($row['hostname_history'] ?? '[]', true);
                $existingHist[$row['fingerprint']] = [
                    'ips'       => is_array($ipHist) ? $ipHist : [],
                    'hostnames' => is_array($hnHist) ? $hnHist : [],
                    'ip_last'   => $row['ip_last'] ?? '',
                    'hn_last'   => $row['hostname_last'] ?? '',
                ];
            }
        }
    }

    // Helper: append to history if changed, cap at 5 entries
    $appendHistory = function (array $hist, string $newVal, string $lastVal): array {
        $newVal = trim($newVal);
        if ($newVal === '' || $newVal === $lastVal) return $hist;
        $hist[] = ['val' => $newVal, 'at' => time()];
        // Keep last 5
        if (count($hist) > 5) $hist = array_slice($hist, -5);
        return $hist;
    };

    // Upsert each device. On first seed we set acknowledged_at so the
    // initial fleet doesn't flag as NEW.
    $insSeed = $pdo->prepare("
        INSERT INTO hotspot_seen_devices
            (router_id, fingerprint, crm_client_id, first_seen_at, last_seen_at,
             hostname_last, hostname_history, ip_last, ip_history,
             acknowledged_at, ack_label)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'auto-seed')
        ON CONFLICT(router_id, fingerprint) DO UPDATE SET
            last_seen_at = excluded.last_seen_at,
            hostname_last = excluded.hostname_last,
            hostname_history = excluded.hostname_history,
            ip_last = excluded.ip_last,
            ip_history = excluded.ip_history
    ");
    $insNormal = $pdo->prepare("
        INSERT INTO hotspot_seen_devices
            (router_id, fingerprint, crm_client_id, first_seen_at, last_seen_at,
             hostname_last, hostname_history, ip_last, ip_history)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(router_id, fingerprint) DO UPDATE SET
            last_seen_at = excluded.last_seen_at,
            hostname_last = excluded.hostname_last,
            hostname_history = excluded.hostname_history,
            ip_last = excluded.ip_last,
            ip_history = excluded.ip_history
    ");
    foreach ($devices as $d) {
        $fp = trim((string)($d['fingerprint'] ?? ''));
        if ($fp === '') continue;
        $hn = trim((string)($d['hostname'] ?? ''));
        $ip = trim((string)($d['ip'] ?? ''));
        if (empty($existingFp[$fp])) $newCount++;

        // Build history arrays for this device
        $hist = $existingHist[$fp] ?? ['ips' => [], 'hostnames' => [], 'ip_last' => '', 'hn_last' => ''];
        $newIpHist = $appendHistory($hist['ips'], $ip, $hist['ip_last']);
        $newHnHist = $appendHistory($hist['hostnames'], $hn, $hist['hn_last']);

        try {
            if ($isFirstSeed) {
                $insSeed->execute([
                    $routerId, $fp, $clientId, $now, $now,
                    $hn, json_encode($newHnHist), $ip, json_encode($newIpHist),
                    $now,
                ]);
            } else {
                $insNormal->execute([
                    $routerId, $fp, $clientId, $now, $now,
                    $hn, json_encode($newHnHist), $ip, json_encode($newIpHist),
                ]);
            }
            $recorded++;
        } catch (\Throwable $e) {
            error_log('[devices_record_seen] upsert failed: ' . $e->getMessage());
        }
    }

    $ok2([
        'recorded'   => $recorded,
        'new_count'  => $isFirstSeed ? 0 : $newCount,  // suppress NEW count on first seed
        'first_seed' => $isFirstSeed,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_devices_get_seen  (Bearer, GET)  v4.19.0
// ═══════════════════════════════════════════════════════════════════
// Returns all known fingerprints for a router, with their first_seen,
// last_seen, hostname_last, and acknowledged state. Used by the
// dashboard / devices view to enrich live device rows with NEW badges
// and historical context.
//
// Request: GET ?action=app_devices_get_seen&router_id=Router-XXX
//
// Response:
//   {
//     devices: [
//       {
//         fingerprint, first_seen_at, last_seen_at, hostname_last,
//         is_new: bool, acknowledged_at, ack_label
//       },
//       ...
//     ]
//   }
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_devices_get_seen' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me       = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $routerId = trim($_GET['router_id'] ?? '');
    if ($routerId === '') { $er2('router_id required.', 400); }
    if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

    ca_hotspot_authz_router($routerId, $clientId, $er2);

    $stmt = $pdo->prepare("
        SELECT fingerprint, first_seen_at, last_seen_at, hostname_last,
               hostname_history, ip_last, ip_history,
               acknowledged_at, ack_label, notes
        FROM hotspot_seen_devices
        WHERE router_id = ?
        ORDER BY first_seen_at DESC
    ");
    $stmt->execute([$routerId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $ipHist = json_decode($r['ip_history'] ?? '[]', true);
        $hnHist = json_decode($r['hostname_history'] ?? '[]', true);
        $out[] = [
            'fingerprint'      => $r['fingerprint'],
            'first_seen_at'    => (int)$r['first_seen_at'],
            'last_seen_at'     => (int)$r['last_seen_at'],
            'hostname_last'    => $r['hostname_last'] ?? '',
            'hostname_history' => is_array($hnHist) ? $hnHist : [],
            'ip_last'          => $r['ip_last'] ?? '',
            'ip_history'       => is_array($ipHist) ? $ipHist : [],
            'is_new'           => empty($r['acknowledged_at']),
            'acknowledged_at'  => $r['acknowledged_at'] ? (int)$r['acknowledged_at'] : null,
            'ack_label'        => $r['ack_label'],
            'notes'             => $r['notes'],
        ];
    }
    $ok2(['devices' => $out]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_devices_acknowledge  (Bearer, POST)  v4.19.0
// ═══════════════════════════════════════════════════════════════════
// Mark a device as known. Removes the NEW badge. Optional label
// ("Mark as known", "Family", "Guest") and notes for future reference.
//
// Request body (JSON):
//   {
//     router_id: "Router-XXX",
//     fingerprint: "abc123",
//     label: "Family"         (optional)
//     notes: "Mom's tablet"   (optional)
//   }
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_devices_acknowledge' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me       = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $body = $_POST;
    if (empty($body)) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
    }

    $routerId = trim((string)($body['router_id'] ?? ''));
    $fingerprint = trim((string)($body['fingerprint'] ?? ''));
    if ($routerId === '' || $fingerprint === '') {
        $er2('router_id and fingerprint required.', 400);
    }
    if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

    $label = trim((string)($body['label'] ?? ''));
    if ($label !== '' && strlen($label) > 64) $label = substr($label, 0, 64);
    $notes = trim((string)($body['notes'] ?? ''));
    if ($notes !== '' && strlen($notes) > 200) $notes = substr($notes, 0, 200);

    ca_hotspot_authz_router($routerId, $clientId, $er2);

    $stmt = $pdo->prepare("
        UPDATE hotspot_seen_devices
        SET acknowledged_at = ?, ack_label = ?, notes = ?
        WHERE router_id = ? AND fingerprint = ?
    ");
    $stmt->execute([time(), $label ?: null, $notes ?: null, $routerId, $fingerprint]);

    if ($stmt->rowCount() === 0) {
        // Fingerprint not in seen log yet — insert a synthetic record so the
        // ack persists even if the device disconnects before next record_seen.
        $now = time();
        $insStmt = $pdo->prepare("
            INSERT OR IGNORE INTO hotspot_seen_devices
                (router_id, fingerprint, crm_client_id, first_seen_at, last_seen_at, acknowledged_at, ack_label, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insStmt->execute([$routerId, $fingerprint, $clientId, $now, $now, $now, $label ?: null, $notes ?: null]);
    }

    ca_audit($pdo, $clientId, 'device_acknowledge', null, json_encode([
        'router_id'   => $routerId,
        'fingerprint' => $fingerprint,
        'label'       => $label,
    ]));

    $ok2(['acknowledged' => true]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_paid_access_grant  (Bearer, POST)  v4.20.0
// ═══════════════════════════════════════════════════════════════════
// Grant a device time-based access. Required body:
//   { router_id, fingerprint, minutes }
// Optional body:
//   { hostname, amount_ssp, amount_usd, note, created_by }
//
// On grant: any prior 'active' grant for the same (router, fingerprint)
// is marked 'extended' so cron picks up only the latest. Also unpauses
// the device if currently paused (otherwise it would stay paused with
// a fresh timer, which is wrong).
//
// Returns: { id, expires_at, started_at, status }
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_paid_access_grant' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me       = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $body = $_POST;
    if (empty($body)) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
    }

    $routerId    = trim((string)($body['router_id'] ?? ''));
    $fingerprint = trim((string)($body['fingerprint'] ?? ''));
    $minutes     = (int)($body['minutes'] ?? 0);

    if ($routerId === '' || $fingerprint === '') {
        $er2('router_id and fingerprint required.', 400);
    }
    if ($minutes < 1 || $minutes > 60 * 24 * 7) {
        $er2('minutes must be between 1 and 10080 (1 week).', 400);
    }
    if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

    ca_hotspot_authz_router($routerId, $clientId, $er2);

    $hostname  = trim((string)($body['hostname'] ?? ''));
    $amountSsp = isset($body['amount_ssp']) ? (float)$body['amount_ssp'] : null;
    $amountUsd = isset($body['amount_usd']) ? (float)$body['amount_usd'] : null;
    $note      = trim((string)($body['note'] ?? ''));
    if ($note !== '' && strlen($note) > 200) $note = substr($note, 0, 200);
    $createdBy = trim((string)($body['created_by'] ?? 'customer:' . $clientId));
    if (strlen($createdBy) > 64) $createdBy = substr($createdBy, 0, 64);

    $now       = time();
    $startedAt = $now;
    $expiresAt = $now + ($minutes * 60);

    try {
        $pdo->beginTransaction();

        // Mark any prior active grant for this (router, fp) as extended.
        $pdo->prepare("
            UPDATE hotspot_paid_access
            SET status = 'extended', expired_at = ?
            WHERE router_id = ? AND fingerprint = ? AND status = 'active'
        ")->execute([$now, $routerId, $fingerprint]);

        // Insert new active grant
        $ins = $pdo->prepare("
            INSERT INTO hotspot_paid_access
                (router_id, fingerprint, crm_client_id, started_at, expires_at,
                 status, amount_ssp, amount_usd, note, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $routerId, $fingerprint, $clientId, $startedAt, $expiresAt,
            $amountSsp, $amountUsd, $note ?: null, $createdBy, $now,
        ]);
        $grantId = (int)$pdo->lastInsertId();

        // Open a session log row
        $pdo->prepare("
            INSERT INTO hotspot_session_log
                (router_id, fingerprint, hostname_at_start, session_start,
                 linked_paid_id, amount_ssp, amount_usd)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $routerId, $fingerprint, $hostname ?: null, $startedAt,
            $grantId, $amountSsp, $amountUsd,
        ]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[paid_access_grant] tx failed: ' . $e->getMessage());
        $er2('Could not record grant: ' . $e->getMessage(), 500);
    }

    // Unpause if currently paused — fire-and-forget; if it fails the
    // device stays paused but the timer still ticks. Cron will pause
    // again at expiry which is a no-op if already paused.
    ca_hotspot_dr_call('dr_wifi_unpause_client', [
        'router_id' => $routerId,
        'client_id' => $fingerprint,
        'by'        => 'paid_access_grant',
    ], 'POST');

    ca_audit($pdo, $clientId, 'paid_access_grant', null, json_encode([
        'router_id'   => $routerId,
        'fingerprint' => $fingerprint,
        'minutes'     => $minutes,
        'expires_at'  => $expiresAt,
        'amount_ssp'  => $amountSsp,
    ]));

    $ok2([
        'id'         => $grantId,
        'started_at' => $startedAt,
        'expires_at' => $expiresAt,
        'status'     => 'active',
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_paid_access_extend  (Bearer, POST)  v4.20.0
// ═══════════════════════════════════════════════════════════════════
// Extend an active grant by N minutes. Body:
//   { router_id, fingerprint, minutes, amount_ssp?, amount_usd?, note? }
//
// Implementation: find the active grant; bump expires_at by `minutes`.
// If no active grant exists, returns 404 — caller should issue a fresh
// grant instead. Optionally append to the note and add to amount_ssp.
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_paid_access_extend' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me       = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $body = $_POST;
    if (empty($body)) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
    }

    $routerId    = trim((string)($body['router_id'] ?? ''));
    $fingerprint = trim((string)($body['fingerprint'] ?? ''));
    $minutes     = (int)($body['minutes'] ?? 0);

    if ($routerId === '' || $fingerprint === '') {
        $er2('router_id and fingerprint required.', 400);
    }
    if ($minutes < 1 || $minutes > 60 * 24 * 7) {
        $er2('minutes must be between 1 and 10080.', 400);
    }
    if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

    ca_hotspot_authz_router($routerId, $clientId, $er2);

    $stmt = $pdo->prepare("
        SELECT id, expires_at, amount_ssp, amount_usd, note
        FROM hotspot_paid_access
        WHERE router_id = ? AND fingerprint = ? AND status = 'active'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$routerId, $fingerprint]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        $er2('No active grant to extend. Grant a fresh one instead.', 404);
    }

    $newExpires = (int)$row['expires_at'] + ($minutes * 60);
    $addSsp     = isset($body['amount_ssp']) ? (float)$body['amount_ssp'] : 0.0;
    $addUsd     = isset($body['amount_usd']) ? (float)$body['amount_usd'] : 0.0;
    $newSsp     = $addSsp ? ((float)($row['amount_ssp'] ?? 0) + $addSsp) : $row['amount_ssp'];
    $newUsd     = $addUsd ? ((float)($row['amount_usd'] ?? 0) + $addUsd) : $row['amount_usd'];
    $appendNote = trim((string)($body['note'] ?? ''));
    $newNote    = $row['note'] ?? '';
    if ($appendNote !== '') {
        $newNote = ($newNote ? $newNote . ' / ' : '') . $appendNote;
        if (strlen($newNote) > 200) $newNote = substr($newNote, 0, 200);
    }

    $pdo->prepare("
        UPDATE hotspot_paid_access
        SET expires_at = ?, amount_ssp = ?, amount_usd = ?, note = ?
        WHERE id = ?
    ")->execute([$newExpires, $newSsp, $newUsd, $newNote ?: null, $row['id']]);

    ca_audit($pdo, $clientId, 'paid_access_extend', null, json_encode([
        'router_id'   => $routerId,
        'fingerprint' => $fingerprint,
        'minutes'     => $minutes,
        'new_expires' => $newExpires,
    ]));

    $ok2([
        'id'             => (int)$row['id'],
        'expires_at'     => $newExpires,
        'minutes_added'  => $minutes,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_paid_access_revoke  (Bearer, POST)  v4.20.0
// ═══════════════════════════════════════════════════════════════════
// Revoke an active grant immediately. Pauses the device.
// Body: { router_id, fingerprint }
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_paid_access_revoke' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me       = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $body = $_POST;
    if (empty($body)) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
    }

    $routerId    = trim((string)($body['router_id'] ?? ''));
    $fingerprint = trim((string)($body['fingerprint'] ?? ''));
    if ($routerId === '' || $fingerprint === '') {
        $er2('router_id and fingerprint required.', 400);
    }
    if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

    ca_hotspot_authz_router($routerId, $clientId, $er2);

    $now = time();
    $stmt = $pdo->prepare("
        SELECT id FROM hotspot_paid_access
        WHERE router_id = ? AND fingerprint = ? AND status = 'active'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$routerId, $fingerprint]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        $er2('No active grant to revoke.', 404);
    }

    $pdo->prepare("
        UPDATE hotspot_paid_access
        SET status = 'revoked', revoked_at = ?
        WHERE id = ?
    ")->execute([$now, $row['id']]);

    // Close the session log row for this grant
    $pdo->prepare("
        UPDATE hotspot_session_log
        SET session_end = ?, total_minutes = (? - session_start) / 60
        WHERE linked_paid_id = ? AND session_end IS NULL
    ")->execute([$now, $now, $row['id']]);

    // Pause the device
    ca_hotspot_dr_call('dr_wifi_pause_client', [
        'router_id' => $routerId,
        'client_id' => $fingerprint,
        'by'        => 'paid_access_revoke',
    ], 'POST');

    ca_audit($pdo, $clientId, 'paid_access_revoke', null, json_encode([
        'router_id'   => $routerId,
        'fingerprint' => $fingerprint,
    ]));

    $ok2(['revoked' => true]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_paid_access_list  (Bearer, GET)  v4.20.0
// ═══════════════════════════════════════════════════════════════════
// List active and recent grants for a router. Used by the devices view
// to show countdown badges and the daily session report.
//
// Request: GET ?action=app_paid_access_list&router_id=X
// Optional: &include_history=1  (also returns last 50 expired/revoked)
//
// Response:
//   {
//     active: [ { id, fingerprint, started_at, expires_at, seconds_left,
//                 amount_ssp, note, created_by }, ... ],
//     today: { sessions: N, total_minutes: N, revenue_ssp: N, revenue_usd: N },
//     history: [ ... ]  (only if include_history=1)
//   }
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_paid_access_list' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me       = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $routerId = trim($_GET['router_id'] ?? '');
    if ($routerId === '') { $er2('router_id required.', 400); }
    if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

    ca_hotspot_authz_router($routerId, $clientId, $er2);

    $now = time();
    $includeHistory = !empty($_GET['include_history']);

    // Active grants
    $stmt = $pdo->prepare("
        SELECT id, fingerprint, started_at, expires_at, amount_ssp, amount_usd,
               note, created_by, status
        FROM hotspot_paid_access
        WHERE router_id = ? AND status = 'active'
        ORDER BY expires_at ASC
    ");
    $stmt->execute([$routerId]);
    $active = [];
    while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $secLeft = max(0, (int)$r['expires_at'] - $now);
        $active[] = [
            'id'           => (int)$r['id'],
            'fingerprint'  => $r['fingerprint'],
            'started_at'   => (int)$r['started_at'],
            'expires_at'   => (int)$r['expires_at'],
            'seconds_left' => $secLeft,
            'amount_ssp'   => $r['amount_ssp'] !== null ? (float)$r['amount_ssp'] : null,
            'amount_usd'   => $r['amount_usd'] !== null ? (float)$r['amount_usd'] : null,
            'note'         => $r['note'],
            'created_by'   => $r['created_by'],
        ];
    }

    // Today's tally — sessions started after midnight local. SQLite has
    // no proper TZ, we use a simple "since 00:00 UTC" window. Operators
    // who care about local-day boundaries should reconcile manually for
    // now; this is a quick view, not a strict accounting report.
    $startOfDay = strtotime('today 00:00');
    $todayStmt = $pdo->prepare("
        SELECT COUNT(*) AS sessions,
               COALESCE(SUM(total_minutes), 0) AS total_minutes,
               COALESCE(SUM(amount_ssp), 0) AS rev_ssp,
               COALESCE(SUM(amount_usd), 0) AS rev_usd
        FROM hotspot_session_log
        WHERE router_id = ? AND session_start >= ?
    ");
    $todayStmt->execute([$routerId, $startOfDay]);
    $today = $todayStmt->fetch(\PDO::FETCH_ASSOC);

    $out = [
        'active' => $active,
        'today'  => [
            'sessions'      => (int)($today['sessions'] ?? 0),
            'total_minutes' => (int)($today['total_minutes'] ?? 0),
            'revenue_ssp'   => (float)($today['rev_ssp'] ?? 0),
            'revenue_usd'   => (float)($today['rev_usd'] ?? 0),
        ],
    ];

    if ($includeHistory) {
        $hStmt = $pdo->prepare("
            SELECT id, fingerprint, started_at, expires_at, status,
                   amount_ssp, amount_usd, note, created_by, expired_at, revoked_at
            FROM hotspot_paid_access
            WHERE router_id = ? AND status != 'active'
            ORDER BY id DESC LIMIT 50
        ");
        $hStmt->execute([$routerId]);
        $hist = [];
        while ($r = $hStmt->fetch(\PDO::FETCH_ASSOC)) {
            $hist[] = [
                'id'          => (int)$r['id'],
                'fingerprint' => $r['fingerprint'],
                'started_at'  => (int)$r['started_at'],
                'expires_at'  => (int)$r['expires_at'],
                'expired_at'  => $r['expired_at'] ? (int)$r['expired_at'] : null,
                'revoked_at'  => $r['revoked_at'] ? (int)$r['revoked_at'] : null,
                'status'      => $r['status'],
                'amount_ssp'  => $r['amount_ssp'] !== null ? (float)$r['amount_ssp'] : null,
                'amount_usd'  => $r['amount_usd'] !== null ? (float)$r['amount_usd'] : null,
                'note'        => $r['note'],
                'created_by'  => $r['created_by'],
            ];
        }
        $out['history'] = $hist;
    }

    $ok2($out);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_hotspot_rotate_password  (Bearer, POST)  v4.19.0
// ═══════════════════════════════════════════════════════════════════
// "Boot all devices" — rotate the Wi-Fi password to a fresh value,
// kicking everyone off until they reconnect with the new password.
// Same prepare/apply pattern as the enable flow:
//   stage='prepare' → returns new password without pushing
//   stage='apply'   → pushes the supplied password to the router
//
// Why split: the customer's own device is on the same Wi-Fi we're
// about to invalidate. Show password first, get explicit confirmation,
// then push.
//
// Request body (JSON):
//   stage='prepare': { router_id, stage:'prepare' }
//   stage='apply':   { router_id, stage:'apply', password:'XXX' }
//
// Response:
//   stage='prepare': { router_id, wifi_ssid, wifi_password }
//   stage='apply':   { router_id, wifi_ssid, wifi_password, rotated_at, verified }
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_hotspot_rotate_password' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    $me       = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($me, $er2);

    $body = $_POST;
    if (empty($body)) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
    }

    $routerId = trim((string)($body['router_id'] ?? ''));
    $stage    = trim((string)($body['stage'] ?? 'prepare'));
    if ($routerId === '') { $er2('router_id required.', 400); }
    if (strpos($routerId, 'Router-') !== 0) { $routerId = 'Router-' . $routerId; }

    $matchedKit = ca_hotspot_authz_router($routerId, $clientId, $er2);
    $kitNumber  = trim((string)($matchedKit['kit_number'] ?? ''));

    // PRE-FLIGHT — read current SSID + confirm router online (both stages)
    $live = ca_hotspot_dr_call('dr_wifi_get_config', [
        'router_id' => $routerId,
    ], 'GET');
    if (!$live || empty($live['ok']) || empty($live['networks'])) {
        $er2('Router is offline. Connect your dish, then try again.', 503);
    }
    $currentSsid = trim((string)($live['networks'][0]['ssid'] ?? ''));
    if ($currentSsid === '') {
        $er2('Could not read current Wi-Fi network. Try again in a moment.', 503);
    }

    if ($stage === 'prepare') {
        // Generate but don't push
        $newPassword = ca_hotspot_generate_password();
        $ok2([
            'router_id'     => $routerId,
            'wifi_ssid'     => $currentSsid,
            'wifi_password' => $newPassword,
        ]);
    }

    if ($stage !== 'apply') {
        $er2('Invalid stage. Use prepare or apply.', 400);
    }

    // APPLY — push the client-supplied password
    $newPassword = trim((string)($body['password'] ?? ''));
    $newPassword = preg_replace('/[\x00-\x1F\x7F]/', '', $newPassword);
    if (strlen($newPassword) < 8 || strlen($newPassword) > 63) {
        $er2('Password must be 8-63 characters.', 400);
    }

    $push = ca_hotspot_dr_call('dr_wifi_change_password', [
        'router_id'     => $routerId,
        'ssid'          => $currentSsid,
        'password'      => $newPassword,
        'ssid_5ghz'     => $currentSsid,
        'password_5ghz' => $newPassword,
        'auth_type'     => 'wpa2',
    ], 'POST');
    if (!$push || empty($push['ok'])) {
        $errMsg = is_array($push) ? (string)($push['error'] ?? 'Unknown error') : 'No response from router';
        $er2('Could not push new password: ' . $errMsg, 502);
    }

    sleep(4);
    $verify = ca_hotspot_dr_call('dr_wifi_get_config', [
        'router_id' => $routerId,
    ], 'GET');
    $verified = false;
    if ($verify && !empty($verify['ok']) && !empty($verify['networks'])) {
        $vSsid = trim((string)($verify['networks'][0]['ssid'] ?? ''));
        $vPass = (string)($verify['networks'][0]['password'] ?? '');
        if ($vSsid === $currentSsid && $vPass === $newPassword) $verified = true;
    }
    if (!$verified) {
        error_log('[hotspot] rotate: push ok but verify lagged — router=' . $routerId);
    }

    // Save to hotspot_config and mirror cache. Preserve other fields
    // (mode, location nickname, enabled_at) so this is purely a credential
    // refresh, not a reset.
    $cfg = ca_hotspot_load_config($store);
    $existing = $cfg[$routerId] ?? [];
    $now = date('c');
    $cfg[$routerId] = array_merge($existing, [
        'wifi_ssid'      => $currentSsid,
        'wifi_password'  => $newPassword,
        'wifi_synced_at' => $now,
    ]);
    ca_hotspot_save_config($store, $cfg);
    ca_hotspot_mirror_wifi_cache($pdo, $routerId, $clientId, $kitNumber, $currentSsid, $newPassword);

    ca_audit($pdo, $clientId, 'hotspot_rotate', null, json_encode([
        'router_id' => $routerId,
        'wifi_ssid' => $currentSsid,
        'verified'  => $verified,
    ]));

    $ok2([
        'router_id'     => $routerId,
        'wifi_ssid'     => $currentSsid,
        'wifi_password' => $newPassword,
        'rotated_at'    => $now,
        'verified'      => $verified,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// v4.12.14 — INVOICE & RECEIPT PDF ENDPOINTS
// ═══════════════════════════════════════════════════════════════════
// Reuses the same UCRM PDF fetch pattern as webhook.php / cron_quote_wa.php:
// - Invoice PDFs via $crm->getRawContent("invoices/{id}/pdf") → 1-shot temp file
// - Receipt PDFs via direct curl to /payments/{id}/pdf → permanent cache in receipt_pdfs/
// Both styles match the CRM Twig template exactly (CRM is source of truth).
// ═══════════════════════════════════════════════════════════════════

/**
 * Verify invoice belongs to the active customer account. Returns the cached
 * invoice row or exits 404. v4.12.14.
 */
if (!function_exists('ca_require_invoice_for_account')) {
    function ca_require_invoice_for_account($store, int $invoiceId, int $clientId, $er2): array {
        if ($invoiceId <= 0) $er2('Missing invoice id.', 400);
        foreach ($store->load('ucrm_invoices_cache.json') ?? [] as $row) {
            if ((int)($row['id'] ?? 0) === $invoiceId) {
                if ((int)($row['clientId'] ?? 0) !== $clientId) $er2('Invoice not found.', 404);
                return $row;
            }
        }
        $er2('Invoice not found.', 404);
        return []; // unreachable
    }
}

/**
 * Check + record a cooldown. Returns seconds remaining (0 = go ahead).
 * v4.12.14.
 */
if (!function_exists('ca_wa_cooldown_check')) {
    function ca_wa_cooldown_check($pdo, string $key, int $cooldownSec = 60): int {
        $stmt = $pdo->prepare("SELECT last_sent_at FROM app_wa_send_cooldown WHERE key = ?");
        $stmt->execute([$key]);
        $last = (int)($stmt->fetchColumn() ?: 0);
        if ($last > 0 && (time() - $last) < $cooldownSec) {
            return $cooldownSec - (time() - $last);
        }
        return 0;
    }
}
if (!function_exists('ca_wa_cooldown_mark')) {
    function ca_wa_cooldown_mark($pdo, string $key): void {
        $stmt = $pdo->prepare("
            INSERT INTO app_wa_send_cooldown (key, last_sent_at) VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET last_sent_at = excluded.last_sent_at
        ");
        $stmt->execute([$key, time()]);
    }
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_invoice_pdf_download  (Bearer)
// GET  ?page=api&action=app_invoice_pdf_download&inv_id=<id>[&dl=1]
// Streams the UCRM-generated invoice PDF directly to the browser.
// dl=1 forces download; default is inline (WebView opens in system viewer).
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_invoice_pdf_download' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();
    $claims   = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($claims, $er2);
    $invId    = (int)($_GET['inv_id'] ?? 0);
    $inv      = ca_require_invoice_for_account($store, $invId, $clientId, $er2);
    $forceDownload = !empty($_GET['dl']);

    if (!$crm) $er2('CRM service unavailable.', 500);

    try {
        $pdfRaw = $crm->getRawContent("invoices/{$invId}/pdf");
    } catch (\Throwable $e) {
        error_log('[app-inv-pdf] fetch failed: ' . $e->getMessage());
        $er2('Could not fetch invoice PDF from CRM.', 502);
    }
    if (!$pdfRaw) $er2('Invoice PDF not available yet.', 503);

    $bytes = base64_decode($pdfRaw);
    if (!$bytes || strlen($bytes) < 500) $er2('Invoice PDF is empty or invalid.', 502);

    $invNum = $inv['number'] ?? ('INV-' . $invId);
    $fname  = preg_replace('/[^A-Za-z0-9._-]+/', '_', $invNum) . '.pdf';

    ca_audit($pdo, $clientId, 'invoice_pdf_download', $claims['phone'] ?? null, [
        'inv_id' => $invId,
        'inv_num' => $invNum,
        'mode' => $forceDownload ? 'attachment' : 'inline',
    ]);

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_invoice_send_whatsapp  (Bearer, POST)
// Body: { inv_id: <id> }
// Sends the invoice PDF to the authenticated customer's OWN WhatsApp number
// (from JWT phone claim). Reuses the whSendInvoicePdf pattern: temp file +
// HMAC token + serve_temp_pdf URL. 60s cooldown per (invoice, customer).
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_invoice_send_whatsapp' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();
    $claims   = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($claims, $er2);
    $invId    = (int)($body['inv_id'] ?? 0);
    $inv      = ca_require_invoice_for_account($store, $invId, $clientId, $er2);

    if (!$crm)    $er2('CRM service unavailable.', 500);
    if (!$notify) $er2('Notification service unavailable.', 500);

    $targetPhone = trim($claims['phone'] ?? '');
    if (!$targetPhone) $er2('No phone on your account.', 400);

    // Cooldown
    $cdKey = "inv:{$invId}:{$clientId}";
    $remaining = ca_wa_cooldown_check($pdo, $cdKey, 60);
    if ($remaining > 0) {
        $er2("Please wait {$remaining}s before sending this invoice again.", 429);
    }

    // Fetch PDF from CRM
    try {
        $pdfRaw = $crm->getRawContent("invoices/{$invId}/pdf");
    } catch (\Throwable $e) {
        ca_audit($pdo, $clientId, 'invoice_wa_fetch_failed', $targetPhone, [
            'inv_id' => $invId, 'error' => $e->getMessage(),
        ]);
        $er2('Could not fetch invoice PDF from CRM.', 502);
    }
    if (!$pdfRaw) $er2('Invoice PDF not available yet.', 503);

    // Save to temp with HMAC — same pattern as webhook.php whSendInvoicePdf()
    $tempDir = $dataDir . '/temp_pdf';
    if (!is_dir($tempDir)) @mkdir($tempDir, 0755, true);
    foreach (glob($tempDir . '/*.pdf') as $_tf) {
        if (time() - filemtime($_tf) > 600) { @unlink($_tf); @unlink($_tf . '.meta'); }
    }

    $invNum   = $inv['number'] ?? ('INV-' . $invId);
    $pdfFile  = "inv_{$invId}_" . substr(md5(uniqid('', true)), 0, 8) . '.pdf';
    $pdfPath  = $tempDir . '/' . $pdfFile;
    $pdfToken = hash_hmac('sha256', $pdfFile, ($config['webhook_secret'] ?? 'dishnet') . date('Ymd'));
    file_put_contents($pdfPath, base64_decode($pdfRaw));
    file_put_contents($pdfPath . '.meta', json_encode([
        'token' => $pdfToken, 'created' => time(), 'invoice' => $invNum,
    ]));

    // Build URL
    $siteUrl = rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/');
    $siteUrl = preg_replace('#/api/v[0-9.]+$#', '', $siteUrl);
    $siteUrl = preg_replace('#/crm$#', '', $siteUrl);
    $pdfUrl  = $siteUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php'
             . '?page=api&action=serve_temp_pdf'
             . '&file=' . urlencode($pdfFile)
             . '&token=' . urlencode($pdfToken);

    $total   = (float)($inv['total'] ?? 0);
    $due     = max(0, $total - (float)($inv['amountPaid'] ?? 0));
    $dueDate = $inv['dueDate'] ?? '';
    $dueDateLabel = $dueDate ? date('d M Y', strtotime($dueDate)) : 'See invoice';

    $caption = "📄 Invoice {$invNum}\n"
             . 'Total: $' . number_format($total, 2) . "\n"
             . ($due > 0 ? 'Due: $' . number_format($due, 2) . ' by ' . $dueDateLabel : 'Status: PAID')
             . "\n\n— DishNet Africa";

    try {
        $notify->sendDocument('accounts', $targetPhone, $pdfUrl, "{$invNum}.pdf",
            $caption, 'portal_invoice_pdf');
    } catch (\Throwable $e) {
        ca_audit($pdo, $clientId, 'invoice_wa_send_failed', $targetPhone, [
            'inv_id' => $invId, 'error' => $e->getMessage(),
        ]);
        $er2('WhatsApp send failed: ' . $e->getMessage(), 502);
    }

    ca_wa_cooldown_mark($pdo, $cdKey);
    ca_audit($pdo, $clientId, 'invoice_wa_sent', $targetPhone, [
        'inv_id' => $invId, 'inv_num' => $invNum,
    ]);

    $ok2([
        'sent_to'  => $targetPhone,
        'inv_num'  => $invNum,
        'pdf_url'  => $pdfUrl,  // debugging — client should not rely on this
    ], 'Invoice sent to your WhatsApp.');
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_invoice_receipts_list  (Bearer)
// GET  ?page=api&action=app_invoice_receipts_list&inv_id=<id>
// Returns the list of payments associated with an invoice so the portal
// can render one "View Receipt" button per payment. Live CRM call, cached
// 5 minutes in ucrm_invoice_payments_cache.json keyed by invoice id.
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_invoice_receipts_list' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();
    $claims   = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($claims, $er2);
    $invId    = (int)($_GET['inv_id'] ?? 0);
    $inv      = ca_require_invoice_for_account($store, $invId, $clientId, $er2);

    $cache    = $store->load('ucrm_invoice_payments_cache.json') ?? [];
    $cacheKey = (string)$invId;
    $entry    = $cache[$cacheKey] ?? null;
    $fresh    = $entry && (time() - (int)($entry['fetched_at'] ?? 0)) < 300;

    if ($fresh) {
        $payments = $entry['payments'] ?? [];
    } else {
        if (!$crm) $er2('CRM service unavailable.', 500);
        $payments = [];
        try {
            // UCRM: GET /invoices/{id}/payments → [{id, amount, method, createdDate, ...}]
            $raw = $crm->get("invoices/{$invId}/payments");
            if (is_array($raw)) {
                foreach ($raw as $p) {
                    $payments[] = [
                        'id'          => (int)($p['id'] ?? 0),
                        'amount'      => (float)($p['amount'] ?? 0),
                        'method'      => $p['methodName'] ?? ($p['method'] ?? ''),
                        'method_id'   => (int)($p['method'] ?? 0),
                        'createdDate' => $p['createdDate'] ?? '',
                        'note'        => $p['note'] ?? '',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Don't fail the whole endpoint — return empty + log
            error_log('[app-inv-receipts] fetch failed inv=' . $invId . ' err=' . $e->getMessage());
        }

        // Sort newest-first
        usort($payments, function($a, $b) {
            return strcmp($b['createdDate'] ?? '', $a['createdDate'] ?? '');
        });

        $cache[$cacheKey] = ['fetched_at' => time(), 'payments' => $payments];
        // Cap cache at 500 invoices (LRU by fetched_at)
        if (count($cache) > 500) {
            uasort($cache, function($a, $b) {
                return (int)($b['fetched_at'] ?? 0) - (int)($a['fetched_at'] ?? 0);
            });
            $cache = array_slice($cache, 0, 500, true);
        }
        $store->save('ucrm_invoice_payments_cache.json', $cache);
    }

    $ok2([
        'inv_id'   => $invId,
        'inv_num'  => $inv['number'] ?? ('INV-' . $invId),
        'payments' => $payments,
        'count'    => count($payments),
        'cached'   => $fresh,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_payment_receipt_pdf  (Bearer)
// GET  ?page=api&action=app_payment_receipt_pdf&payment_id=<id>[&dl=1]
// Streams the UCRM-generated payment receipt PDF. First checks the local
// receipt_pdfs/ cache (populated by cron_quote_wa.php on prior WA send);
// if missing, fetches live from CRM using the same 2-try pattern as the
// cron (API endpoint, then admin-token fallback).
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_payment_receipt_pdf' && $met === 'GET') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();
    $claims   = ca_require_auth($config, $pdo, $er2);
    $clientId = ca_resolve_active_client_id($claims, $er2);
    $payId    = (int)($_GET['payment_id'] ?? 0);
    if ($payId <= 0) $er2('Missing payment_id.', 400);
    $forceDownload = !empty($_GET['dl']);

    if (!$crm) $er2('CRM service unavailable.', 500);

    // Validate payment belongs to an invoice in this account.
    // Check the payments cache first (populated by receipts_list). If not cached,
    // walk the full client's invoice payment cache.
    $paymentCache = $store->load('ucrm_invoice_payments_cache.json') ?? [];
    $authorized   = false;
    $invNumForFilename = '';

    foreach ($paymentCache as $invIdKey => $entry) {
        foreach (($entry['payments'] ?? []) as $p) {
            if ((int)($p['id'] ?? 0) === $payId) {
                // Cross-check the invoice belongs to active account
                foreach ($store->load('ucrm_invoices_cache.json') ?? [] as $inv) {
                    if ((int)($inv['id'] ?? 0) === (int)$invIdKey
                        && (int)($inv['clientId'] ?? 0) === $clientId) {
                        $authorized = true;
                        $invNumForFilename = $inv['number'] ?? '';
                        break 3;
                    }
                }
            }
        }
    }

    // Fallback: fetch payment directly from CRM and check its clientId
    if (!$authorized) {
        try {
            $payment = $crm->get("payments/{$payId}");
            if (is_array($payment) && (int)($payment['clientId'] ?? 0) === $clientId) {
                $authorized = true;
            }
        } catch (\Throwable $e) {
            // ignore — authorization will remain false
        }
    }

    if (!$authorized) $er2('Receipt not found.', 404);

    // Try local cache first (populated by cron_quote_wa.php for recent payments)
    $cachedFile = $dataDir . '/receipt_pdfs/DishNet-Receipt-' . $payId . '.pdf';
    $bytes = null;
    if (file_exists($cachedFile) && filesize($cachedFile) > 500) {
        $bytes = file_get_contents($cachedFile);
    }

    // Fetch live from CRM if not cached — same 2-try pattern as cron_quote_wa.php
    if (!$bytes) {
        $apiBase = rtrim($crm->getBaseUrl(), '/');
        $receiptUrl = $apiBase . "/payments/{$payId}/pdf";
        $appKey  = $crm->getAppKey();
        $authHdr = $crm->getAuthHeader() . ': ' . $appKey;

        $ch = curl_init($receiptUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [$authHdr, 'Accept: application/pdf'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $resp && strlen($resp) > 500) {
            $bytes = $resp;
        } else {
            // Fallback to admin token if configured
            $adminToken = trim($config['crm_auth_token'] ?? '');
            if ($adminToken !== '') {
                $ch2 = curl_init($receiptUrl);
                curl_setopt_array($ch2, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['x-auth-token: ' . $adminToken, 'Accept: application/pdf'],
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $resp2     = curl_exec($ch2);
                $httpCode2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);
                if ($httpCode2 === 200 && $resp2 && strlen($resp2) > 500) $bytes = $resp2;
            }
        }

        // Save into the cache so future requests (and the queue-retry loop) can reuse
        if ($bytes) {
            $receiptDir = $dataDir . '/receipt_pdfs';
            if (!is_dir($receiptDir)) @mkdir($receiptDir, 0755, true);
            @file_put_contents($cachedFile, $bytes);
        }
    }

    if (!$bytes) $er2('Receipt PDF not available.', 503);

    $fname = 'DishNet-Receipt-' . $payId . '.pdf';

    ca_audit($pdo, $clientId, 'receipt_pdf_download', $claims['phone'] ?? null, [
        'payment_id' => $payId,
        'inv_num' => $invNumForFilename,
        'mode' => $forceDownload ? 'attachment' : 'inline',
        'source' => file_exists($cachedFile) ? 'cache' : 'live',
    ]);

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}

// ═══════════════════════════════════════════════════════════════════
// v4.12.19 — T&C / Privacy consent
// ═══════════════════════════════════════════════════════════════════

/**
 * Returns true if this phone has already accepted the current doc versions.
 * Normalizes the phone using ca_phone_intl (same as OTP path).
 *
 * v4.12.24: removed the `if (!function_exists(...))` wrapper that previously
 * surrounded this definition. PHP does not hoist functions defined inside
 * conditional blocks — so when the login flow at line 1049 called this
 * function, execution hadn't yet reached this definition (line ~2260) and
 * PHP fatally errored with "Call to undefined function". Unwrapping makes
 * PHP hoist the function at file parse time, available everywhere.
 */
function ca_has_current_consent($pdo, string $rawPhone): bool {
    require_once dirname(__DIR__, 2) . '/lib/LegalContent.php';
    $ver = dnLegalVersion();
    $phone = ca_phone_intl($rawPhone);
    if ($phone === '') return false;
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM app_tos_consent
             WHERE phone = ? AND tos_version = ? AND privacy_version = ? LIMIT 1"
        );
        $stmt->execute([$phone, $ver['tos'], $ver['privacy']]);
        return (bool)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_legal_version  (public, no auth)
// GET  ?page=api&action=app_legal_version
// Returns current Terms and Privacy versions + effective date so the
// login UI can decide whether to prompt for consent and what to display.
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_legal_version' && $met === 'GET') {
    require_once dirname(__DIR__, 2) . '/lib/LegalContent.php';
    $ver = dnLegalVersion();
    $ok2([
        'tos_version'     => $ver['tos'],
        'privacy_version' => $ver['privacy'],
        'dated'           => $ver['dated'],
        'terms_url'       => 'public.php?page=terms',
        'privacy_url'     => 'public.php?page=privacy',
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// ACTION: app_record_consent  (public, POST)
// Body: { phone, tos_version, privacy_version }
// Called by the login UI after OTP verification on first-time acceptance.
// Idempotent via primary key. Also called if versions bump in the future.
// ═══════════════════════════════════════════════════════════════════
if ($act === 'app_record_consent' && $met === 'POST') {
    ca_init_tables($store->getPdo());
    $pdo = $store->getPdo();

    require_once dirname(__DIR__, 2) . '/lib/LegalContent.php';
    $currentVer = dnLegalVersion();

    $rawPhone      = trim($body['phone']           ?? '');
    $rawEmail      = trim($body['email']           ?? '');
    $submittedTos  = trim($body['tos_version']     ?? '');
    $submittedPriv = trim($body['privacy_version'] ?? '');

    // v4.21.15: support email-mode login (mirrors app_send_otp / app_verify_otp).
    // Either phone OR email is required.
    if ($rawEmail !== '') {
        $identifier = strtolower($rawEmail);
        if (strpos($identifier, '@') === false) $er2('Invalid email address.', 400);
    } elseif ($rawPhone !== '') {
        $identifier = ca_phone_intl($rawPhone);
        if ($identifier === '') $er2('Invalid phone number.', 400);
    } else {
        $er2('Either phone or email is required.', 400);
    }

    // Must match the CURRENT server-side version — prevents stale clients from
    // spoofing acceptance of an older wording.
    if ($submittedTos !== $currentVer['tos'] || $submittedPriv !== $currentVer['privacy']) {
        $er2('Document version out of date. Refresh and try again.', 409);
    }

    // Verify the identifier corresponds to a real CRM client to prevent random
    // drive-by writes by anonymous callers.
    $matches = $rawEmail !== ''
        ? ca_find_clients_by_email($store, $rawEmail)
        : ca_find_clients_by_phone($store, $rawPhone);
    if (empty($matches)) $er2('No DishNet account found for that ' . ($rawEmail !== '' ? 'email' : 'phone') . '.', 404);
    $clientId = (int)($matches[0]['id'] ?? 0);

    try {
        $stmt = $pdo->prepare(
            "INSERT OR IGNORE INTO app_tos_consent
             (phone, tos_version, privacy_version, accepted_at, ip, crm_client_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        // Note: column is named 'phone' for legacy compatibility, but stores
        // the OTP identifier (phone OR email). Same convention as app_otp_pending.
        // v4.21.20: real client IP via getClientIp() helper — important for
        // legal consent records where the IP may be needed as evidence of
        // acceptance (was previously logging UCRM's internal proxy IP).
        $stmt->execute([
            $identifier,
            $currentVer['tos'],
            $currentVer['privacy'],
            time(),
            function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? ''),
            $clientId,
        ]);
    } catch (\Throwable $e) {
        error_log('[consent] write failed: ' . $e->getMessage());
        $er2('Could not record consent. Please try again.', 500);
    }

    ca_audit($pdo, $clientId, 'consent_recorded', $identifier, [
        'tos_version' => $currentVer['tos'],
        'privacy_version' => $currentVer['privacy'],
        'mode' => $rawEmail !== '' ? 'email' : 'phone',
    ]);

    $ok2([
        'accepted'        => true,
        'tos_version'     => $currentVer['tos'],
        'privacy_version' => $currentVer['privacy'],
    ]);
}
