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
    
    // Priority 2: a sibling of the plugin, OUTSIDE anything uCRM replaces.
    //
    // {pluginRoot}/data was the old fallback and it destroyed the database on
    // every upgrade: uCRM replaces the plugin directory when a new version is
    // uploaded, and the data directory was inside it. Customers, staff,
    // conversations, leads -- all of it gone, and the plugin came back showing
    // its first-run screen. It happened repeatedly before anyone connected the
    // two events.
    //
    // The plugins root survives, which ConfigVault has been relying on all
    // along: its vault file lives there and is the only thing that ever came
    // through an upgrade intact. The database belongs beside it.
    //
    // uCRM's own pluginDataDir still wins when it provides one -- it knows
    // better than we do, and it is already outside the plugin.
    if (!$dataDir) {
        $parent = dirname(rtrim($pluginRoot, '/'));
        $plugin = basename(rtrim($pluginRoot, '/'));
        if (is_dir($parent) && is_writable($parent)) {
            $dataDir = $parent . '/.' . $plugin . '-data';
        } else {
            // Nowhere safe to put it. Keeping the old location is better than
            // failing to start, but it must not be silent.
            error_log('[getDataDir] plugins root not writable — data stays inside the plugin '
                    . 'directory and WILL be lost on the next upgrade');
            $dataDir = $pluginRoot . '/data';
        }
    }
    
    // Ensure directory exists
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0755, true);
    }

    // One-time rescue: an install that still has its data in the old location
    // gets it moved out before uCRM has a chance to delete it. Copy, never
    // move -- if anything here goes wrong the original must still be there.
    $legacy = $pluginRoot . '/data';
    if ($dataDir !== $legacy && is_dir($legacy) && !is_file($dataDir . '/plugin.sqlite3')
        && is_file($legacy . '/plugin.sqlite3')) {
        foreach ((scandir($legacy) ?: []) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $from = $legacy . '/' . $entry;
            $to   = $dataDir . '/' . $entry;
            if (is_file($from) && !file_exists($to)) @copy($from, $to);
        }
        error_log('[getDataDir] rescued plugin data from ' . $legacy . ' to ' . $dataDir);
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
