<?php
/**
 * DishNet Hybrid Telecom — Lead Seed Installer
 *
 * Run ONCE to import the 277 CSV leads into the plugin.
 * Usage: php install_leads.php
 *   or:  visit /crm/_plugins/dishnet-hybrid-telecom/install_leads.php
 *
 * After running, delete this file (or it will refuse to run again).
 * Leads are assigned retailer_id=1 (admin) — reassign via All Leads tab.
 */
declare(strict_types=1);
chdir(__DIR__);

$isCLI = php_sapi_name() === 'cli';
$nl    = $isCLI ? PHP_EOL : '<br>';

// Safety: refuse to run if seed already applied
require_once __DIR__ . '/lib/bootstrap_data.php';
$_installDataDir = getDataDir(__DIR__);
$lockFile = $_installDataDir . '/.leads_seed_applied';
if (file_exists($lockFile)) {
    echo "✓ Lead seed already applied on " . file_get_contents($lockFile) . $nl;
    echo "  Delete data/.leads_seed_applied to re-run." . $nl;
    exit(0);
}

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);

// Auto-detect active backend — use SQLite if db exists, else JsonStore
$store = file_exists($dataDir . '/plugin.sqlite3')
    ? SqliteStore::create($dataDir)
    : new JsonStore($dataDir);

$backend = ($store instanceof SqliteStore) ? 'SQLite' : 'JSON';
echo "Backend  : {$backend}" . $nl;

// Load seed
$seedFile = $_installDataDir . '/leads_seed.json';
if (!file_exists($seedFile)) {
    echo "ERROR: data/leads_seed.json not found." . $nl;
    exit(1);
}
$seedLeads = json_decode(file_get_contents($seedFile), true);
if (!is_array($seedLeads)) {
    echo "ERROR: Invalid seed JSON." . $nl;
    exit(1);
}

// Load existing leads (don't wipe them)
$existing   = $store->load('leads.json');
$existingIds = array_filter(array_column($existing, 'csv_original_id'));

// Find next ID above all existing
$maxId = 0;
foreach ($existing  as $l) $maxId = max($maxId, (int)($l['id'] ?? 0));
foreach ($seedLeads as $l) $maxId = max($maxId, (int)($l['id'] ?? 0));
$maxId++;

$imported = 0;
$skipped  = 0;
foreach ($seedLeads as &$lead) {
    $origId = $lead['csv_original_id'] ?? 0;
    if ($origId && in_array($origId, $existingIds, false)) {
        $skipped++;
        continue;
    }
    $lead['id'] = $maxId++;
    $existing[] = $lead;
    $imported++;
}
unset($lead);

$store->save('leads.json', $existing);
file_put_contents($lockFile, date('Y-m-d H:i:s'));

echo "═══════════════════════════════════════" . $nl;
echo "DishNet Lead Seed Installer" . $nl;
echo "═══════════════════════════════════════" . $nl;
echo "Imported : {$imported} new leads" . $nl;
echo "Skipped  : {$skipped} already present" . $nl;
echo "Total    : " . count($existing) . " leads in database" . $nl;
echo "Lock     : data/.leads_seed_applied" . $nl;
echo "═══════════════════════════════════════" . $nl;
echo "Done! Delete install_leads.php for security." . $nl;
