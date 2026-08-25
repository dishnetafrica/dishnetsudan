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

    public static function isSet_(array $config, string $key): bool
    {
        return isset($config[$key]) && is_string($config[$key]) && trim($config[$key]) !== '';
    }
}
