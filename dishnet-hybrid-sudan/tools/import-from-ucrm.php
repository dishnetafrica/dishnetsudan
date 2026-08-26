#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * import-from-ucrm.php — fill the plugin's Service Plans and Hardware screens
 * from what is already configured in uCRM.
 *
 * The plugin keeps its own tables because uCRM has nowhere to record what
 * Starlink charges DishNet -- only what DishNet charges the customer. So the
 * plugin holds cost and margin, and pushes the customer price out to uCRM.
 * There was no way to go the other direction, which left both screens empty on
 * an install whose catalogue already lives in uCRM.
 *
 *   --dry-run   show what would be imported, change nothing   (start here)
 *   --import    write the rows
 *
 * Safe to re-run: a row whose name already exists is left alone, so anything
 * you have typed in -- costs especially -- is never overwritten.
 *
 * One deliberate asymmetry. Hardware is imported WITH its uCRM product id,
 * because both sides are uCRM products and the link is exact. Plans are
 * imported WITHOUT one: your plans are uCRM *service-plans*, but the plugin's
 * "Sync to UCRM" writes to *products* -- a different object with its own ids.
 * Storing a service-plan id in that field would make a later sync patch
 * whichever product happens to share the number. Left blank, sync matches by
 * name instead, which is what it already does for anything new.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/DishNetTools.php';

$apply = in_array('--import', $argv, true);

$dataDir = getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$store   = SqliteStore::create($dataDir);
try {
    foreach (($store->load('kyc_config.json') ?: []) as $k => $v) {
        if ($v === null || $v === '') continue;
        if (!array_key_exists($k, $config) || $config[$k] === '' || $config[$k] === null) $config[$k] = $v;
    }
} catch (\Throwable $e) { /* files alone */ }

$tools = new DishNetTools($store, $config, $root);
$cat   = $tools->getProducts();
if (empty($cat['ok'])) {
    fwrite(STDERR, 'uCRM catalogue unavailable: ' . (string)($cat['error'] ?? '?') . "\n");
    exit(2);
}

echo $apply ? "Importing.\n\n" : "DRY RUN — nothing will be written.\n\n";

/** Names already present, lowercased, so nothing typed in is overwritten. */
function existingNames($store, string $file): array
{
    $out = [];
    try {
        foreach ($store->load($file) as $r) {
            $n = strtolower(trim((string)($r['name'] ?? '')));
            if ($n !== '') $out[$n] = true;
        }
    } catch (\Throwable $e) { /* empty */ }
    return $out;
}

// ── Service plans ─────────────────────────────────────────────────────────
$haveP = existingNames($store, 'subscription_plans.json');
$plans = $cat['data']['products'] ?? [];
$addedP = $skipP = 0;

echo "SERVICE PLANS (uCRM service-plans -> the plugin's plan table)\n";
foreach ($plans as $p) {
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '' || ($p['price'] ?? null) === null) continue;
    if (isset($haveP[strtolower($name)])) {
        printf("  skip   %-30s already in the plugin\n", $name);
        $skipP++;
        continue;
    }
    printf("  add    %-30s customer price %s\n", $name,
           number_format((float)$p['price'], 2));
    $addedP++;
    if (!$apply) continue;

    $store->appendWithId('subscription_plans.json', [
        'name'            => $name,
        'type'            => 'Starlink',
        'speed'           => (string)($p['download_speed'] ?? ''),
        'supplier'        => 'Starlink',
        // What uCRM bills the customer. Cost is yours to fill in -- uCRM does
        // not know it, and guessing a margin would be inventing a number.
        'customer_price'  => (float)$p['price'],
        'starlink_cost'   => 0,
        'active'          => true,
        'ucrm_product_id' => null,          // see the note at the top
        'imported_from'   => 'ucrm-service-plans',
        'imported_at'     => gmdate('c'),
    ]);
}
if (!$plans) echo "  (uCRM returned no active service plans)\n";

// ── Hardware ──────────────────────────────────────────────────────────────
$haveH = existingNames($store, 'kyc_devices.json');
$hw    = $cat['data']['hardware'] ?? [];
$addedH = $skipH = 0;

echo "\nHARDWARE (uCRM products -> the plugin's hardware table)\n";
foreach ($hw as $h) {
    $name = trim((string)($h['name'] ?? ''));
    if ($name === '') continue;
    if (isset($haveH[strtolower($name)])) {
        printf("  skip   %-30s already in the plugin\n", $name);
        $skipH++;
        continue;
    }
    printf("  add    %-30s price %s\n", $name,
           ($h['price'] ?? null) === null ? '(not set)' : number_format((float)$h['price'], 2));
    $addedH++;
    if (!$apply) continue;

    $store->appendWithId('kyc_devices.json', [
        'name'            => $name,
        'sku'             => '',
        'price'           => ($h['price'] ?? null) === null ? 0 : (float)$h['price'],
        // Both sides are uCRM products here, so the link is exact and safe.
        'ucrm_product_id' => $h['id'] ?? null,
        'active'          => true,
        'imported_from'   => 'ucrm-products',
        'imported_at'     => gmdate('c'),
    ]);
}
if (!$hw) echo "  (uCRM returned no products)\n";

printf("\n%s %d plan(s) and %d hardware item(s). %d already present.\n",
       $apply ? 'Imported' : 'Would import', $addedP, $addedH, $skipP + $skipH);

if ($apply) {
    echo "\nCost is blank on the imported plans -- uCRM does not hold it. Fill it in on the\n"
       . "Service Plans screen and margin appears. Do NOT press \"Sync All to UCRM\" until\n"
       . "you have checked Customer Price matches uCRM, because that button writes the\n"
       . "plugin's price back to uCRM and would change what customers are quoted.\n";
} else {
    echo "\nRun again with --import to write these.\n";
}
