<?php
declare(strict_types=1);

/**
 * PluginConfig — one way to read this plugin's settings.
 *
 * uCRM writes the values from manifest.json's "configuration" block to
 * data/config.json every time an admin saves the plugin's settings form. That
 * file is the source of truth here — there is no .env in a uCRM plugin, and
 * secrets must never live in the plugin tree or in git.
 *
 * A kyc_config.json in the persistent data directory, if present, is merged on
 * top. That exists so an operator can set something the settings form does not
 * expose without waiting for a plugin release.
 *
 * Checkboxes arrive as "1"/"0"/true/false depending on uCRM version, so they
 * are normalised to real booleans here rather than in every caller.
 */
class PluginConfig
{
    /** Keys whose values must never be printed, logged or returned by an API. */
    const SECRET_KEYS = [
        'evo_api_key',
        'evo_webhook_secret',
        'claude_api_key',
        'openai_api_key',
        'ai_tools_token',
        'shopbot_ai_token',
    ];

    const BOOL_KEYS = [
        'ai_enabled',
        'tools_legacy_phone_match',
    ];

    public static function load(string $pluginRoot, string $dataDir): array
    {
        $config = [];

        foreach ([$pluginRoot . '/data/config.json', $dataDir . '/config.json'] as $path) {
            if (!is_file($path)) continue;
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) $config = array_merge($config, $decoded);
        }

        // Operator overrides, if any.
        $overrides = $dataDir . '/kyc_config.json';
        if (is_file($overrides)) {
            $decoded = json_decode((string)file_get_contents($overrides), true);
            if (is_array($decoded)) $config = array_merge($config, $decoded);
        }

        // The vault fills anything a re-install wiped; a value that is present
        // -- including deliberately off -- is never overridden. See ConfigVault.
        require_once __DIR__ . '/ConfigVault.php';
        $config = ConfigVault::apply($pluginRoot, $dataDir, $config);

        foreach (self::BOOL_KEYS as $k) {
            if (array_key_exists($k, $config)) $config[$k] = self::toBool($config[$k]);
        }
        foreach ($config as $k => $v) {
            if (is_string($v)) $config[$k] = trim($v);
        }

        return $config;
    }

    /** True only for values a person would call "on". */
    public static function toBool($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value))  return $value === 1;
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    /**
     * A copy safe to render on a page or return from an endpoint.
     * Secrets become a boolean "is it set" — never the value, never a prefix.
     */
    public static function redacted(array $config): array
    {
        $safe = [];
        foreach ($config as $k => $v) {
            if (in_array($k, self::SECRET_KEYS, true)) {
                $safe[$k] = (is_string($v) && $v !== '') ? '[set]' : '[not set]';
            } else {
                $safe[$k] = $v;
            }
        }
        return $safe;
    }

    /**
     * Store credentials from an authenticated dashboard screen.
     *
     * saveOverrides() refuses secrets on purpose: it also serves the standalone
     * plugin, whose page uCRM does not authenticate. The Hybrid dashboard tabs
     * sit behind the plugin's own login, so an admin there may set these -- but
     * only these two, named explicitly, so the refusal still covers everything
     * else.
     */
    public static function saveEvolutionCredentials(string $dataDir, string $url, string $key): array
    {
        $path     = $dataDir . '/kyc_config.json';
        $existing = [];
        if (is_file($path)) {
            $d = json_decode((string)file_get_contents($path), true);
            if (is_array($d)) $existing = $d;
        }

        $url = rtrim(trim($url), '/');
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            return [false, 'The API URL must start with https://'];
        }
        // Strip /manager and friends: pasting the manager URL is the natural
        // mistake, and it fails silently rather than loudly.
        if (class_exists('EvolutionApiService')) {
            $url = EvolutionApiService::normaliseBaseUrl($url);
        }
        $existing['evo_api_url'] = $url;

        // An empty key means "leave the stored one alone" -- the form shows a
        // mask rather than the real value, so blank must not wipe it.
        $key = trim($key);
        if ($key !== '') $existing['evo_api_key'] = $key;

        $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) return [false, 'Could not encode settings.'];

        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return [false, 'Could not write to the plugin data directory.'];
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) { @unlink($tmp); return [false, 'Could not save settings.']; }
        return [true, ''];
    }

    /**
     * Store AI settings from an authenticated dashboard screen.
     *
     * Same reasoning as saveEvolutionCredentials(): a named, narrow path so the
     * blanket refusal in saveOverrides() still holds everywhere else. A blank
     * key keeps the stored one, because the form shows a mask.
     */
    public static function saveAiSettings(string $dataDir, string $provider, string $key, string $instructions): array
    {
        $provider = $provider === 'openai' ? 'openai' : 'claude';
        $field    = $provider === 'openai' ? 'openai_api_key' : 'claude_api_key';

        $path     = $dataDir . '/kyc_config.json';
        $existing = [];
        if (is_file($path)) {
            $d = json_decode((string)file_get_contents($path), true);
            if (is_array($d)) $existing = $d;
        }

        $existing['ai_provider']             = $provider;
        $existing['bot_custom_instructions'] = trim($instructions);

        $key = trim($key);
        if ($key !== '') $existing[$field] = $key;

        $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) return [false, 'Could not encode settings.'];

        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return [false, 'Could not write to the plugin data directory.'];
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) { @unlink($tmp); return [false, 'Could not save settings.']; }
        return [true, ''];
    }

    public static function isSet_(array $config, string $key): bool
    {
        return isset($config[$key]) && is_string($config[$key]) && trim($config[$key]) !== '';
    }

    /**
     * Save operator overrides to kyc_config.json.
     *
     * Deliberately NOT data/config.json: uCRM owns that file and rewrites it
     * whenever an admin saves the settings form, which would silently discard
     * anything written here. kyc_config.json is merged on top at load time, so
     * a value set from the plugin page wins until it is cleared.
     *
     * Refuses to store secrets. Those belong on the uCRM Configuration screen,
     * which is behind uCRM's admin login; the plugin page is not.
     */
    public static function saveOverrides(string $dataDir, array $changes): array
    {
        foreach (array_keys($changes) as $k) {
            if (in_array($k, self::SECRET_KEYS, true) || $k === 'admin_token') {
                return [false, 'Secrets can only be set on the uCRM Configuration screen.'];
            }
        }

        $path     = $dataDir . '/kyc_config.json';
        $existing = [];
        if (is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) $existing = $decoded;
        }

        foreach ($changes as $k => $v) {
            // An empty string clears the override and lets config.json show through.
            if ($v === null || $v === '') { unset($existing[$k]); continue; }
            $existing[$k] = $v;
        }

        $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) return [false, 'Could not encode settings.'];

        // Write-then-rename so a crash cannot leave a half-written config.
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return [false, 'Could not write to the plugin data directory.'];
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return [false, 'Could not save settings.'];
        }
        return [true, ''];
    }
}
