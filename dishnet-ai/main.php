<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';

/**
 * main.php — the scheduled entry point.
 *
 * uCRM runs this on the plugin schedule (executionPeriod in manifest.json).
 * It drains the AI reply queue.
 *
 * The schedule is the SAFETY NET, not the main path. evo_webhook.php spawns a
 * worker the moment a message arrives, so replies are normally immediate. This
 * exists so that nothing is stranded if that spawn is unavailable — which is
 * why a few minutes' granularity is acceptable here.
 *
 * This is the GUARANTEED path. The webhook also tries to kick a worker the
 * moment a message arrives, which is what makes replies feel immediate — but
 * that depends on exec() being available in the container. If it is not, this
 * scheduled run still answers every customer, just up to a minute later.
 * Nothing is ever lost either way: the queue holds the work.
 */

require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/EventBus.php';
require_once __DIR__ . '/lib/ConversationService.php';
require_once __DIR__ . '/workers/WorkerBase.php';
require_once __DIR__ . '/workers/AiReplyWorker.php';

$log = static function (string $msg) use ($dataDir): void {
    $file = $dataDir . '/ai_platform.log';
    @file_put_contents($file, '[' . gmdate('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
    // Keep the log small — this runs every minute, forever.
    if (@filesize($file) > 512 * 1024) {
        $lines = @file($file) ?: [];
        @file_put_contents($file, implode('', array_slice($lines, -1000)));
    }
};

// SqliteStore::create() runs migrations/*.sql on the way up, so the events
// table and the AI platform schema exist before anything touches them.
$store  = SqliteStore::create($dataDir);
$config = PluginConfig::load(__DIR__, $dataDir);

// Constructing this creates wa_conversations / wa_messages if they are absent,
// so the admin page and the webhook never race to be the first to need them.
new ConversationService($dataDir, $store->getPdo());

if (!PluginConfig::toBool($config['ai_enabled'] ?? false)) {
    // Deliberately quiet: this is the normal state during setup.
    exit(0);
}

try {
    // A 50s ceiling keeps a run well short of the schedule, so two never
    // overlap. WorkerBase holds a lock as well, but not colliding beats
    // colliding safely.
    $worker = new AiReplyWorker($store, $config, 50, 10);
    $result = $worker->run();

    $processed = (int)($result['processed'] ?? 0);
    $failed    = (int)($result['failed'] ?? 0);
    if ($processed > 0 || $failed > 0) {
        $log("worker: processed={$processed} failed={$failed}");
    }
} catch (\Throwable $e) {
    // A crash here must never stop uCRM's plugin scheduler.
    $log('worker crashed: ' . $e->getMessage());
}

exit(0);
