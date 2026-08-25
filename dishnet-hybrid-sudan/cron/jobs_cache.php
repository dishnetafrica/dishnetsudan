#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * cron/jobs_cache.php — DishNet Hybrid Telecom
 * Fetches ALL scheduling jobs in one request and stamps _ucrm_user_id
 * from assignedUserId for per-staff filtering.
 *
 * NOTE: UCRM API ignores all assignee filter params (assigneeId=, assignees[]=,
 * userIds[]=). Only client-side filtering on assignedUserId works.
 *
 * Runs every 10 minutes via master.php.
 */

chdir(dirname(__DIR__));
require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/CrmApiClient.php';

require_once dirname(__DIR__) . '/lib/bootstrap_data.php';
$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$crm     = CrmApiClient::fromUcrm(dirname(__DIR__), $config);

if (!$crm->isConfigured()) {
    log_msg('CRM not configured — skipping.'); return;
}

$dateFrom = '2026-01-01';

// Build retailer map: ucrm_user_id => retailer (for stamping _retailer_id)
$retailers  = $store->load('retailers.json') ?? [];
$retailerMap = [];
foreach ($retailers as $r) {
    $uid = (int)($r['ucrm_user_id'] ?? 0);
    if ($uid > 0) $retailerMap[$uid] = $r;
}

// Fetch ALL jobs in one request — UCRM API ignores assignee filters
$qs   = "?limit=500&dateFrom={$dateFrom}&statuses[]=0&statuses[]=1&statuses[]=2";
$jobs = $crm->get('scheduling/jobs' . $qs);

if (!is_array($jobs)) {
    log_msg('CRM API error: ' . json_encode($crm->getLastError())); return;
}

// Load existing cache for merge
$cache = $store->load('scheduling_jobs_cache.json') ?? [];
$byId  = [];
foreach ($cache as $j) { $byId[(int)($j['id'] ?? 0)] = $j; }

$fetched = 0;
foreach ($jobs as $j) {
    $id  = (int)($j['id'] ?? 0); if (!$id) continue;
    $uid = (int)($j['assignedUserId'] ?? 0);
    $j['_ucrm_user_id']  = $uid;
    $j['_crm_client_id'] = 0;
    $j['_retailer_id']   = (int)($retailerMap[$uid]['id'] ?? 0);
    $j['_cached_at']     = time();
    $byId[$id] = $j;
    $fetched++;
}

$store->save('scheduling_jobs_cache.json', array_values($byId));
$store->save('scheduling_cache_meta.json', [
    'last_sync'      => time(),
    'last_sync_ts'   => date('Y-m-d H:i:s'),
    'total_jobs'     => count($byId),
    'fetched'        => $fetched,
    'errors'         => 0,
    'plugin_version' => '4.3.45',
]);

log_msg("Done — {$fetched} jobs fetched, " . count($byId) . " total in cache.");

function log_msg(string $m): void {
    echo '[' . date('Y-m-d H:i:s') . '] [jobs_cache] ' . $m . "\n";
}
