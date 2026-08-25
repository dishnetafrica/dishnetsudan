<?php
/**
 * DishNet Hybrid Telecom — Persistent Data Directory Bootstrap
 * 
 * CRITICAL: UCRM provides `pluginDataDir` in ucrm.json for persistent storage
 * that survives plugin updates. ALL entry points MUST use this file to ensure
 * data is stored in the correct location.
 * 
 * NEVER store data in __DIR__/data when running in UCRM — it will be lost on update!
 * 
 * Usage:
 *   require_once __DIR__ . '/lib/bootstrap_data.php';
 *   $dataDir = getDataDir(__DIR__);
 */

/**
 * Get the persistent data directory for plugin storage.
 * 
 * Priority:
 * 1. UCRM's pluginDataDir from ucrm.json (persistent, survives updates)
 * 2. Fall back to {pluginRoot}/data for development only
 * 
 * @param string $pluginRoot  The plugin root directory (usually __DIR__ from entry point)
 * @return string             Absolute path to data directory
 */
function getDataDir(string $pluginRoot): string
{
    static $cachedDir = null;
    static $cachedRoot = null;
    
    // Return cached result if same root
    if ($cachedDir !== null && $cachedRoot === $pluginRoot) {
        return $cachedDir;
    }
    
    $dataDir = null;
    
    // Priority 1: UCRM's persistent data directory (from ucrm.json)
    foreach ([$pluginRoot . '/ucrm.json', $pluginRoot . '/data/ucrm.json'] as $ucrmJsonPath) {
        if (file_exists($ucrmJsonPath)) {
            $ucrmCfg = @json_decode(file_get_contents($ucrmJsonPath), true);
            if (!empty($ucrmCfg['pluginDataDir'])) {
                $dataDir = rtrim($ucrmCfg['pluginDataDir'], '/');
                break;
            }
        }
    }
    
    // Priority 2: Fall back to {pluginRoot}/data for development only
    if (!$dataDir) {
        $dataDir = $pluginRoot . '/data';
    }
    
    // Ensure directory exists
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0755, true);
    }
    
    // Cache for subsequent calls
    $cachedDir = $dataDir;
    $cachedRoot = $pluginRoot;
    
    return $dataDir;
}

/**
 * Check if running in UCRM environment (ucrm.json exists).
 * 
 * @param string $pluginRoot
 * @return bool
 */
function isUcrmEnvironment(string $pluginRoot): bool
{
    foreach ([$pluginRoot . '/ucrm.json', $pluginRoot . '/data/ucrm.json'] as $path) {
        if (file_exists($path)) {
            return true;
        }
    }
    return false;
}

/**
 * Get UCRM config from ucrm.json if available.
 * 
 * @param string $pluginRoot
 * @return array
 */
function getUcrmConfig(string $pluginRoot): array
{
    foreach ([$pluginRoot . '/ucrm.json', $pluginRoot . '/data/ucrm.json'] as $path) {
        if (file_exists($path)) {
            $cfg = @json_decode(file_get_contents($path), true);
            if (is_array($cfg)) {
                return $cfg;
            }
        }
    }
    return [];
}

/**
 * Get the real client IP, accounting for the reverse proxy in front of UCRM.
 *
 * UCRM runs behind a Docker reverse proxy that always sets REMOTE_ADDR to an
 * internal 172.x address. The actual client IP arrives in X-Forwarded-For
 * (chain: client, proxy1, proxy2 — first entry is the real client) or in
 * X-Real-IP (single value).
 *
 * Falls back to REMOTE_ADDR if neither header is set (e.g. cron context,
 * direct calls bypassing the proxy).
 *
 * Added: v4.21.20
 *
 * @return string  The real client IP, or '' if none could be determined.
 */
if (!function_exists('getClientIp')) {
    function getClientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
           ?? $_SERVER['HTTP_X_REAL_IP']
           ?? $_SERVER['REMOTE_ADDR']
           ?? '';
        if ($ip === '') return '';
        // X-Forwarded-For can be a comma-separated chain — take the first entry,
        // which is the original client (subsequent entries are intermediate proxies).
        return trim(explode(',', $ip)[0]);
    }
}
