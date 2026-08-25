<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkerBase.php';

/**
 * MaintenanceWorker — Periodic database cleanup and health checks.
 *
 * Unlike other workers, this doesn't consume events.
 * It runs as a scheduled task via master.php (daily at 2 AM).
 *
 * Tasks:
 *   1. Prune completed events older than 30 days
 *   2. Prune completed jobs older than 30 days
 *   3. Detect and release stale locks
 *   4. Log queue health metrics
 *   5. WAL checkpoint
 */
class MaintenanceWorker extends WorkerBase
{
    protected function getEventTypes(): array
    {
        return []; // doesn't consume events
    }

    protected function handle(array $event): void
    {
        // Not used — this worker runs tasks directly
    }

    /**
     * Override run() to execute maintenance tasks instead of event processing.
     */
    public function run(): array
    {
        $results = [];

        // 1. Prune old completed events
        try {
            $pruned = $this->bus->prune(30);
            $results['events_pruned'] = $pruned;
            $this->log('INFO', "Pruned {$pruned} completed events (>30 days)");
        } catch (\Throwable $e) {
            $results['events_prune_error'] = $e->getMessage();
        }

        // 2. Prune old completed jobs
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM job_queue
                WHERE status IN ('completed', 'reversed')
                  AND completed_at < datetime('now', '-30 days')
            ");
            $stmt->execute();
            $jobsPruned = $stmt->rowCount();
            $results['jobs_pruned'] = $jobsPruned;
            $this->log('INFO', "Pruned {$jobsPruned} completed jobs (>30 days)");
        } catch (\Throwable $e) {
            $results['jobs_prune_error'] = $e->getMessage();
        }

        // 3. Release stale processing locks (events locked > 10 min)
        try {
            $stmt = $this->pdo->prepare("
                UPDATE events
                SET status = 'failed', locked_by = NULL, locked_at = NULL,
                    error = COALESCE(error,'') || ' [maintenance: stale lock released]'
                WHERE status = 'processing'
                  AND locked_at < datetime('now', '-600 seconds')
            ");
            $stmt->execute();
            $staleEvents = $stmt->rowCount();

            $stmt2 = $this->pdo->prepare("
                UPDATE job_queue
                SET status = 'failed', locked_by = NULL, locked_at = NULL,
                    error = COALESCE(error,'') || ' [maintenance: stale lock released]'
                WHERE status = 'processing'
                  AND locked_at < datetime('now', '-600 seconds')
            ");
            $stmt2->execute();
            $staleJobs = $stmt2->rowCount();

            $results['stale_locks_released'] = $staleEvents + $staleJobs;
            if ($staleEvents + $staleJobs > 0) {
                $this->log('WARN', "Released {$staleEvents} stale event locks, {$staleJobs} stale job locks");
            }
        } catch (\Throwable $e) {
            $results['stale_lock_error'] = $e->getMessage();
        }

        // 4. Queue health summary
        try {
            $eventSummary = $this->bus->getSummary();
            $results['event_queue'] = $eventSummary;

            $deadCount = (int)($eventSummary['dead'] ?? 0);
            if ($deadCount > 0) {
                $this->log('WARN', "{$deadCount} dead-letter events require attention");
            }
        } catch (\Throwable $e) {
            $results['health_error'] = $e->getMessage();
        }

        // 5. WAL checkpoint (keeps WAL file from growing unbounded)
        try {
            $this->pdo->exec("PRAGMA wal_checkpoint(TRUNCATE)");
            $results['wal_checkpoint'] = 'ok';
            $this->log('INFO', "WAL checkpoint completed");
        } catch (\Throwable $e) {
            $results['wal_error'] = $e->getMessage();
        }

        return array_merge(['worker' => 'MaintenanceWorker'], $results);
    }
}
