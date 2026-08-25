<?php
declare(strict_types=1);

/**
 * UcrmUser — who is looking at this page, according to uCRM itself.
 *
 * uCRM does not authenticate plugin pages, but it does give a plugin a way to
 * ask: the plugin is served from the same host as uCRM, so the browser sends
 * uCRM's session cookies along with the request. Forwarding those to uCRM's
 * /current-user endpoint returns the logged-in user, or 403 if there is none.
 *
 * That means no login, no password and no token to configure. An admin who is
 * already signed into UISP simply opens the page and it works; anyone else —
 * including an anonymous visitor who found the URL — gets nothing.
 *
 * Cookie names differ by version:
 *   UISP 1.0+   nms-crm-php-session-id, nms-session
 *   older UCRM  PHPSESSID
 * All three are forwarded when present, so both work.
 *
 * PHP 7.4 compatible.
 */
class UcrmUser
{
    /** @var array|null Per-request cache — one lookup per page render. */
    private static ?array $cache = null;

    /** Cookies uCRM uses for an admin session. */
    const SESSION_COOKIES = ['nms-crm-php-session-id', 'nms-session', 'PHPSESSID'];

    /**
     * Identify the caller.
     *
     * Returns:
     *   ok            bool    the question could be asked at all
     *   authenticated bool    somebody is logged into uCRM
     *   is_admin      bool    ...and they are staff, not a client
     *   username      string
     *   user_group    string
     *   reason        string  why not, when ok or authenticated is false
     */
    public static function current(string $pluginRoot): array
    {
        if (self::$cache !== null) return self::$cache;
        return self::$cache = self::lookup($pluginRoot);
    }

    public static function isAdmin(string $pluginRoot): bool
    {
        $u = self::current($pluginRoot);
        return !empty($u['is_admin']);
    }

    /** Forget the cached answer. Only useful in tests. */
    public static function reset(): void { self::$cache = null; }

    private static function lookup(string $pluginRoot): array
    {
        $no = static function (string $reason, bool $ok = true): array {
            return ['ok' => $ok, 'authenticated' => false, 'is_admin' => false,
                    'username' => '', 'user_group' => '', 'reason' => $reason];
        };

        // No cookies means nobody is signed in — do not bother uCRM.
        $cookieHeader = self::sessionCookieHeader();
        if ($cookieHeader === '') {
            return $no('Not signed in to UISP.');
        }

        $ucrm = self::readUcrmJson($pluginRoot);
        if (!$ucrm) {
            return $no('ucrm.json is missing — is the plugin enabled in UISP?', false);
        }

        foreach (self::candidateUrls($ucrm) as $url => $isLocal) {
            $result = self::ask($url, $cookieHeader, $isLocal);
            if ($result === null) continue;          // transport failure, try the next
            if ($result === false) {
                return $no('Not signed in to UISP.');   // a definite 401/403
            }

            $isClient = !empty($result['isClient']);
            return [
                'ok'            => true,
                'authenticated' => true,
                // Clients are customers in the client zone, never staff.
                'is_admin'      => !$isClient,
                'username'      => (string)($result['username'] ?? ''),
                'user_group'    => (string)($result['userGroup'] ?? ''),
                'reason'        => $isClient ? 'Signed in as a client, not staff.' : '',
            ];
        }

        return $no('Could not reach uCRM to verify who you are.', false);
    }

    /** Rebuild the caller's uCRM session cookies, dropping everything else. */
    private static function sessionCookieHeader(): string
    {
        $parts = [];
        foreach (self::SESSION_COOKIES as $name) {
            if (isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) && $_COOKIE[$name] !== '') {
                $parts[] = $name . '=' . $_COOKIE[$name];
            }
        }
        return implode('; ', $parts);
    }

    private static function readUcrmJson(string $pluginRoot): array
    {
        foreach ([$pluginRoot . '/ucrm.json', $pluginRoot . '/data/ucrm.json'] as $path) {
            if (!is_file($path)) continue;
            $d = json_decode((string)file_get_contents($path), true);
            if (is_array($d) && $d) return $d;
        }
        return [];
    }

    /**
     * Candidate endpoints, best first.
     *
     * The path moved to /crm/current-user in UISP 1.0, and the configured URL
     * may or may not already include /crm. Rather than guess the version, try
     * the sensible forms and take the first that answers. Local first: it
     * avoids a round trip out to the public hostname.
     *
     * @return array url => isLocal
     */
    private static function candidateUrls(array $ucrm): array
    {
        $out = [];
        foreach ([['ucrmLocalUrl', true], ['ucrmPublicUrl', false]] as $pair) {
            $base = rtrim(trim((string)($ucrm[$pair[0]] ?? '')), '/');
            if ($base === '') continue;

            if (substr($base, -4) === '/crm') {
                $out[$base . '/current-user'] = $pair[1];
            } else {
                $out[$base . '/crm/current-user'] = $pair[1];
                $out[$base . '/current-user']     = $pair[1];
            }
        }
        return $out;
    }

    /**
     * @return array|null|false  user data, null on transport failure, false when
     *                           uCRM says nobody is signed in
     */
    private static function ask(string $url, string $cookieHeader, bool $isLocal)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookieHeader, 'Accept: application/json'],
            // The local URL is a host-local hop to uCRM's own self-signed
            // certificate. Verifying it would fail on every default install.
            CURLOPT_SSL_VERIFYPEER => $isLocal ? false : self::verifyPublic(),
            CURLOPT_SSL_VERIFYHOST => $isLocal ? 0 : 2,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code === 0) return null;
        if ($code === 401 || $code === 403) return false;
        if ($code !== 200) return null;

        $data = json_decode((string)$raw, true);
        // A login page rendered as HTML also returns 200 — insist on the shape.
        if (!is_array($data) || !array_key_exists('username', $data)) return null;

        return $data;
    }

    private static function verifyPublic(): bool
    {
        return getenv('UCRM_SKIP_SSL_VERIFY') !== '1';
    }
}
