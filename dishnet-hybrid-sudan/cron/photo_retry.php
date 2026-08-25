#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * cron/photo_retry.php — DishNet Hybrid Telecom
 *
 * Purpose:
 *   Automatically re-uploads customer photos and ID proofs that failed
 *   during KYC registration (network blip, CRM timeout, etc).
 *   Removes the need for agents to manually press "Re-upload" buttons.
 *
 * Queue:
 *   photo_retry_queue.json — written by KycService on upload failure.
 *   Each entry: { crm_client_id, file_path, doc_name, type, attempts,
 *                 queued_at, next_retry_at, completed_at? }
 *
 * Retry schedule:
 *   Attempt 1: 1 minute after failure
 *   Attempt 2: 5 minutes after attempt 1
 *   Attempt 3: 30 minutes after attempt 2
 *   After 3 attempts: marked 'exhausted', agent notified via WhatsApp
 *
 * Dependencies:
 *   CrmApiClient, NotificationService, SqliteStore/JsonStore
 *
 * Called by:
 *   cron/master.php (every minute)
 *   Or directly: php cron/photo_retry.php >> /var/log/dishnet_photo_retry.log 2>&1
 */

chdir(dirname(__DIR__));

require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/CrmApiClient.php';
require_once __DIR__ . '/../lib/NotificationService.php';
require_once __DIR__ . '/../lib/bootstrap_data.php';

$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$crm     = CrmApiClient::fromUcrm(dirname(__DIR__), $config);
$notify  = new NotificationService($store, $config);

// Single-instance lock
$lockFile = $dataDir . '/cron_photo_retry.lock';
$lockFp   = fopen($lockFile, 'w+');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    return;
}

const MAX_ATTEMPTS = 3;
const BACKOFF = [0 => 60, 1 => 300, 2 => 1800]; // seconds: 1min, 5min, 30min

$now      = time();
$queue    = $store->load('photo_retry_queue.json') ?? [];
$pending  = array_filter($queue, function(array $item) use ($now): bool {
    return empty($item['completed_at'])
        && empty($item['exhausted'])
        && strtotime($item['next_retry_at'] ?? '1970-01-01') <= $now
        && ($item['attempts'] ?? 0) < MAX_ATTEMPTS;
});

$processed = $succeeded = $failed = 0;

foreach ($pending as $idx => $item) {
    $processed++;
    $crmId    = $item['crm_client_id'] ?? '';
    $filePath = $item['file_path'] ?? '';
    $docName  = $item['doc_name'] ?? 'Document';
    $attempts = (int)($item['attempts'] ?? 0);

    if (!$crmId || !file_exists($filePath)) {
        log_msg("Skipping item — file missing or no CRM ID: {$filePath}");
        $queue[$idx]['exhausted']   = true;
        $queue[$idx]['exhaust_reason'] = 'file_not_found';
        continue;
    }

    log_msg("Retrying {$docName} for CRM #{$crmId} (attempt " . ($attempts + 1) . "/" . MAX_ATTEMPTS . ")");

    $ok = $crm->upload($filePath, ['name' => $docName], $crmId);

    if ($ok) {
        $succeeded++;
        $queue[$idx]['completed_at'] = date('Y-m-d H:i:s');
        $queue[$idx]['attempts']     = $attempts + 1;
        @unlink($filePath); // clean up disk file after successful upload

        // Update the kyc_application photo_uploaded / id_uploaded flag
        $type = $item['type'] ?? 'photo';
        $apps = $store->load('kyc_applications.json') ?? [];
        foreach ($apps as $ai => $app) {
            if ((string)($app['crm_client_id'] ?? '') === (string)$crmId) {
                $field = ($type === 'id') ? 'id_uploaded' : 'photo_uploaded';
                $store->updateOne('kyc_applications.json', 'crm_client_id', $crmId, [
                    $field       => true,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                log_msg("  ✅ Updated {$field}=true on application (CRM #{$crmId})");
                break;
            }
        }
        log_msg("  ✅ Upload succeeded for CRM #{$crmId} — {$docName}");
    } else {
        $failed++;
        $newAttempts = $attempts + 1;
        $queue[$idx]['attempts']      = $newAttempts;
        $queue[$idx]['last_error']    = json_encode($crm->getLastError());
        $queue[$idx]['last_tried_at'] = date('Y-m-d H:i:s');

        if ($newAttempts >= MAX_ATTEMPTS) {
            // Exhausted — notify admin via WhatsApp
            $queue[$idx]['exhausted'] = true;
            $notify->sendAdmin(
                "⚠️ *Photo Upload Failed* after " . MAX_ATTEMPTS . " attempts.\n" .
                "CRM Client: #{$crmId}\n" .
                "Document: {$docName}\n" .
                "File: " . basename($filePath) . "\n" .
                "Please upload manually in UCRM → Client → Files tab.",
                'photo_retry_exhausted'
            );
            log_msg("  ❌ Exhausted after {$newAttempts} attempts — admin notified");
        } else {
            $delay = BACKOFF[$newAttempts] ?? 1800;
            $queue[$idx]['next_retry_at'] = date('Y-m-d H:i:s', time() + $delay);
            log_msg("  ⏳ Failed (attempt {$newAttempts}) — next retry in " . ($delay/60) . " min");
        }
    }
}

// Remove completed + exhausted entries older than 7 days to keep queue clean
$cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
$queue  = array_values(array_filter($queue, function(array $item) use ($cutoff): bool {
    if (!empty($item['completed_at'])) return $item['completed_at'] > $cutoff;
    if (!empty($item['exhausted']))    return ($item['last_tried_at'] ?? $item['queued_at']) > $cutoff;
    return true; // keep all pending items
}));

// ── REQ-06: Disk cleanup — delete files for exhausted items older than 30 days ──
$cutoff30 = strtotime('-30 days');
foreach ($queue as $item) {
    if (empty($item['exhausted'])) continue;
    $triedAt = strtotime($item['last_tried_at'] ?? $item['queued_at'] ?? '1970-01-01');
    if ($triedAt < $cutoff30 && !empty($item['file_path']) && file_exists($item['file_path'])) {
        @unlink($item['file_path']);
        log_msg('Disk cleanup: removed exhausted file ' . basename($item['file_path']));
    }
}

// Remove empty month/client directories under kyc_uploads
$uploadsDir = $dataDir . '/kyc_uploads';
foreach (glob($uploadsDir . '/20*') ?: [] as $monthDir) {
    foreach (glob($monthDir . '/crm-*') ?: [] as $clientDir) {
        $files = array_diff(scandir($clientDir), ['.', '..']);
        if (empty($files)) @rmdir($clientDir);
    }
    $subdirs = array_diff(scandir($monthDir), ['.', '..']);
    if (empty($subdirs)) @rmdir($monthDir);
}

$store->save('photo_retry_queue.json', $queue);

flock($lockFp, LOCK_UN);
fclose($lockFp);

if ($processed > 0) {
    log_msg("Run complete — processed: {$processed}, succeeded: {$succeeded}, failed: {$failed}");
}

function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}
