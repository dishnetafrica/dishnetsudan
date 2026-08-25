<?php
/**
 * BlueCardDb  HTTP client for BlueCard portal data via lte_feed.php.
 *
 * Uses simple GET requests to named ?table=bc_* endpoints.
 * No POST body, no SQL proxy  same pattern as cron_lte_sync.php.
 *
 * Config (kyc_config.json):
 *   lte_feed_url    default: http://162.241.149.144/lte_feed.php
 *   lte_feed_token  default: dishnet_lte_feed_Xk9mP2026
 */
class BlueCardDb
{
    private static ?bool $pinged = null;

    //  Ping 
    public static function isConnected(array $config): bool
    {
        if (self::$pinged !== null) return self::$pinged;
        $r = self::get($config, 'bc_ping');
        self::$pinged = ($r !== null && !empty($r['ok']));
        return self::$pinged;
    }

    public static function reset(): void { self::$pinged = null; }

    //  Test (for DB Settings page) 
    public static function test(array $config): array
    {
        $r = self::get($config, 'bc_ping');
        if ($r === null) {
            $url = self::url($config);
            return ['ok' => false, 'error' => "Could not reach {$url}  check network/firewall."];
        }
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => $r['error'] ?? 'bc_ping failed'];
        }
        $d = $r['data'] ?? $r;
        return ['ok' => true, 'version' => $d['version'] ?? '?', 'db' => $d['db'] ?? 'dishnetss_bluecard'];
    }

    //  Fetch a named portal table 
    public static function fetch(array $config, string $table, array $params = []): ?array
    {
        $r = self::get($config, $table, $params);
        if (!$r || empty($r['ok'])) return null;
        return $r['data'] ?? null;
    }

    //  Internal GET helper (identical curl pattern to cron_lte_sync.php) 
    public static function get(array $config, string $table, array $params = []): ?array
    {
        $url = self::url($config) . '?table=' . urlencode($table);
        foreach ($params as $k => $v) {
            if ($v !== '' && $v !== null) {
                $url .= '&' . urlencode($k) . '=' . urlencode($v);
            }
        }
        $token = $config['lte_feed_token'] ?? 'dishnet_lte_feed_Xk9mP2026';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['X-Feed-Token: ' . $token, 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) { error_log("[BlueCardDb] curl error: $err | url=$url"); return null; }
        if ($code !== 200) { error_log("[BlueCardDb] HTTP $code | url=$url"); return null; }
        $data = json_decode($body, true);
        if (!is_array($data)) { error_log("[BlueCardDb] bad JSON | url=$url"); return null; }
        return $data;
    }

    private static function url(array $config): string
    {
        return rtrim($config['lte_feed_url'] ?? 'http://162.241.149.144/lte_feed.php', '/');
    }
}
