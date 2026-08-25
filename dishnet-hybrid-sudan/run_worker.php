<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';

/**
 * run_worker.php — drain the AI reply queue once.
 *
 * Spawned in the background by evo_webhook.php the moment a message is queued,
 * so the customer gets an answer in seconds rather than waiting for the next
 * scheduled run. main.php calls the same worker on a schedule as the
 * guaranteed path. Both are safe to run at once: WorkerBase takes a lock.
 *
 * CLI only — refuses to run over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/EventBus.php';
require_once __DIR__ . '/workers/WorkerBase.php';
require_once __DIR__ . '/workers/AiReplyWorker.php';

$store  = SqliteStore::create($dataDir);
$config = PluginConfig::load(__DIR__, $dataDir);

if (!PluginConfig::toBool($config['ai_enabled'] ?? false)) {
    exit(0);
}

// WorkerBase::log() writes to stdout, which the spawn discards
// (> /dev/null). Capture it so the per-stage trace — webhook, queue, product
// lookup, AI call, send, handoff — survives in ai_platform.log where the
// scheduled cron run already sends its copy.
ob_start();
try {
    $result = (new AiReplyWorker($store, $config, 45, 10))->run();
    $trace = ob_get_clean();
    if ($trace !== '' || !empty($result['processed']) || !empty($result['failed'])) {
        @file_put_contents(
            $dataDir . '/ai_platform.log',
            $trace . sprintf("[%s] spawned worker: processed=%d failed=%d\n",
                gmdate('Y-m-d H:i:s'), $result['processed'] ?? 0, $result['failed'] ?? 0),
            FILE_APPEND
        );
    }
} catch (\Throwable $e) {
    $trace = ob_get_clean();
    if ($trace !== '') @file_put_contents($dataDir . '/ai_platform.log', $trace, FILE_APPEND);
    @file_put_contents(
        $dataDir . '/ai_platform.log',
        '[' . gmdate('Y-m-d H:i:s') . '] spawned worker crashed: ' . $e->getMessage() . PHP_EOL,
        FILE_APPEND
    );
    exit(1);
}
exit(0);
