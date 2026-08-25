<?php
/**
 * Worker Bootstrap File
 * ═══════════════════════════════════════════════════════════════════════
 * Common includes for all LTE workers.
 * 
 * Sets up:
 * - Error handling
 * - Autoloading
 * - Database connection
 * - Common services
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Time limit (workers can run longer)
set_time_limit(300); // 5 minutes max

// Memory limit
ini_set('memory_limit', '256M');

// Determine paths
$pluginRoot = dirname(__DIR__);  // dishnet-hybrid-telecom/
$dataDir = '/data/ucrm/data/plugins/dishnet-hybrid-telecom/data';

// Check if running in dev environment
if (!file_exists($dataDir)) {
    // Fallback for local development
    $dataDir = $pluginRoot . '/data';
}

// Include required libraries
require_once $pluginRoot . '/lib/SqliteStore.php';
require_once $pluginRoot . '/lib/LteSqliteService.php';
require_once $pluginRoot . '/lib/LteEventService.php';
require_once $pluginRoot . '/lib/LteCacheService.php';
require_once $pluginRoot . '/lib/MagmaApiClient.php';

// Optional services (may not exist in all installations)
if (file_exists($pluginRoot . '/lib/NotificationService.php')) {
    require_once $pluginRoot . '/lib/NotificationService.php';
}

if (file_exists($pluginRoot . '/lib/EvolutionApiClient.php')) {
    require_once $pluginRoot . '/lib/EvolutionApiClient.php';
}

// Ensure data directory exists
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// Set timezone
date_default_timezone_set('Africa/Juba');

// Make dataDir available to workers
$GLOBALS['dataDir'] = $dataDir;
$GLOBALS['pluginRoot'] = $pluginRoot;
