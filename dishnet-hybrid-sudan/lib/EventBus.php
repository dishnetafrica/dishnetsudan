<?php
declare(strict_types=1);

/**
 * EventBus — SQLite-backed async event queue for DishNet Hybrid.
 *
 * Replaces direct inline API calls with an emit → consume → ack pattern.
 * Events are processed by cron/event_processor.php (runs every 10 seconds).
 *
 * ── Why not just call the API directly? ─────────────────────────────────
 *
 *   1. Latency: Splynx API calls take 500ms-2s. During that time, Bidal's
 *      browser is waiting. With EventBus, the response returns in < 50ms.
 *
 *   2. Reliability: If Splynx is down, the inline call fails and the status
 *      change is lost. With EventBus, the event retries with backoff.
 *
 *   3. Audit trail: Every event is permanently logged with timestamps,
 *      making it easy to debug "why didn't this sync to Splynx?"
 *
 * ── Usage ───────────────────────────────────────────────────────────────
 *
 *   // Producer (in API handler or webhook):
 *   $bus = new EventBus($store->getPdo());
 *   $bus->emit('ticket.status_changed', 'ticket', 252, [
 *       'old_status' => 1,
 *       'new_status' => 7,
 *       'changed_by' => 'Bidal',
 *   ]);
 *
 *   // Consumer (in cron/event_processor.php):
 *   $events = $bus->consume(20);
 *   foreach ($events as $event) {
 *       try {
 *           handleEvent($event);
 *           $bus->ack($event['id']);
 *       } catch (\Exception $e) {
 *           $bus->fail($event['id'], $e->getMessage());
 *       }
 *   }
 *
 * ── Event Types ─────────────────────────────────────────────────────────
 *
 *   ticket.status_changed    → Push status to Splynx
 *   ticket.assigned          → Notify engineer via WA, update Splynx
 *   install.completed        → Activate Splynx service, notify customer
 *   install.rejected         → Notify engineer, reopen ticket
 *   splynx.ticket.updated    → Inbound webhook: sync Splynx → local
 *   splynx.ticket.created    → Inbound webhook: import new ticket
 *   customer.created         → CRM sync (replaces CrmQueue for new flows)
 *   wa.send                  → Queue WhatsApp message for delivery
 *
 * PHP 7.4 compatible. Zero external dependencies.
 */
class EventBus
{
    private \PDO $pdo;

    /** Maximum time a lock can be held before considered stale (seconds) */
    private const LOCK_TIMEOUT = 300; // 5 minutes

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRODUCER: Emit events
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Emit an event into the queue.
     *
     * @param string $type       Event type (e.g. 'ticket.status_changed')
     * @param string $entityType Entity category ('ticket', 'customer', 'service')
     * @param int    $entityId   ID of the affected entity
     * @param array  $payload    Arbitrary data for the handler
     * @param int    $priority   1=critical, 5=normal, 9=low
     * @param string $createdBy  Who/what created this event (audit trail)
     * @return int   The event ID
     */
    public function emit(
        string $type,
        string $entityType,
        int    $entityId,
        array  $payload = [],
        int    $priority = 5,
        string $createdBy = ''
    ): int {
        if (!$createdBy) {
            $createdBy = isset($_SERVER['REMOTE_ADDR'])
                ? $_SERVER['REMOTE_ADDR']
                : 'system';
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO events
                (event_type, entity_type, entity_id, payload, priority, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $type,
            $entityType,
            $entityId,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $priority,
            $createdBy,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CONSUMER: Claim and process events
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Claim up to $limit events for processing.
     *
     * Uses atomic UPDATE to prevent double-processing across concurrent workers.
     * Returns an array of event records with decoded payload.
     *
     * @param int    $limit     Max events to claim (default 20)
     * @param string $workerId  Unique worker ID (defaults to PID)
     * @return array Claimed events (may be empty)
     */
    public function consume(int $limit = 20, string $workerId = ''): array
    {
        if (!$workerId) {
            $workerId = (string)getmypid();
        }

        // Step 1: Release stale locks (workers that crashed)
        $this->releaseStale();

        // Step 2: Atomic claim + fetch in a single transaction
        $this->pdo->beginTransaction();
        try {
            // Find eligible events
            $selectStmt = $this->pdo->prepare('
                SELECT id FROM events
                WHERE status IN (\'pending\', \'failed\')
                  AND attempts < max_attempts
                  AND next_retry_at <= datetime(\'now\')
                  AND (locked_by IS NULL OR locked_by = \'\')
                ORDER BY priority ASC, created_at ASC
                LIMIT ?
            ');
            $selectStmt->execute([$limit]);
            $ids = $selectStmt->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($ids)) {
                $this->pdo->commit();
                return [];
            }

            // Claim them
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $updateStmt = $this->pdo->prepare("
                UPDATE events
                SET status = 'processing',
                    locked_by = ?,
                    locked_at = datetime('now')
                WHERE id IN ({$placeholders})
            ");
            $updateStmt->execute(array_merge([$workerId], $ids));

            // Fetch full records INSIDE the transaction (FIX: prevents stale read after commit)
            $fetchStmt = $this->pdo->prepare("
                SELECT * FROM events
                WHERE id IN ({$placeholders})
                ORDER BY priority ASC, created_at ASC
            ");
            $fetchStmt->execute($ids);
            $results = $fetchStmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->pdo->commit();
            return $results;

        } catch (\Exception $e) {
            $this->pdo->rollBack();
            error_log("EventBus::consume error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Mark an event as successfully processed.
     */
    public function ack(int $eventId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE events
            SET status       = 'done',
                processed_at = datetime('now'),
                locked_by    = NULL,
                locked_at    = NULL
            WHERE id = ?
        ");
        $stmt->execute([$eventId]);
    }

    /**
     * Mark an event as failed with exponential backoff.
     * After max_attempts, status becomes 'dead' (requires manual intervention).
     */
    public function fail(int $eventId, string $error): void
    {
        // Get current attempt count
        $stmt = $this->pdo->prepare('SELECT attempts, max_attempts FROM events WHERE id = ?');
        $stmt->execute([$eventId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) return;

        $newAttempts = (int)$row['attempts'] + 1;
        $maxAttempts = (int)$row['max_attempts'];
        $isDead      = $newAttempts >= $maxAttempts;

        // Calculate next retry time in PHP (avoids fragile SQLite datetime concatenation)
        $backoffSeconds = [10, 30, 300, 1800, 7200]; // 10s, 30s, 5m, 30m, 2h
        $backoffKey     = min($newAttempts - 1, count($backoffSeconds) - 1);
        $nextRetry      = date('Y-m-d H:i:s', time() + $backoffSeconds[$backoffKey]);

        $update = $this->pdo->prepare("
            UPDATE events
            SET status        = ?,
                attempts      = ?,
                error         = ?,
                next_retry_at = ?,
                processed_at  = CASE WHEN ? THEN datetime('now') ELSE processed_at END,
                locked_by     = NULL,
                locked_at     = NULL
            WHERE id = ?
        ");
        $update->execute([
            $isDead ? 'dead' : 'failed',
            $newAttempts,
            $error,
            $nextRetry,
            $isDead ? 1 : 0,
            $eventId,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HOUSEKEEPING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Release stale locks (workers that crashed without ack/fail).
     * Resets them to 'failed' so they can be retried.
     */
    private function releaseStale(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::LOCK_TIMEOUT);
        $stmt = $this->pdo->prepare("
            UPDATE events
            SET status    = 'failed',
                locked_by = NULL,
                locked_at = NULL,
                error     = COALESCE(error, '') || ' [stale lock released]'
            WHERE status = 'processing'
              AND locked_at < ?
        ");
        $stmt->execute([$cutoff]);
    }

    /**
     * Get dead-letter events (for admin alerting).
     *
     * @param int $limit Max events to return
     * @return array Dead events
     */
    public function getDeadLetters(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM events
            WHERE status = 'dead'
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Retry a dead event (admin action: manually reset for another attempt round).
     */
    public function retryDead(int $eventId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE events
            SET status       = 'pending',
                attempts     = 0,
                error        = NULL,
                locked_by    = NULL,
                locked_at    = NULL,
                next_retry_at= datetime('now'),
                processed_at = NULL
            WHERE id = ? AND status = 'dead'
        ");
        $stmt->execute([$eventId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Prune old completed events (run weekly in maintenance cron).
     *
     * @param int $olderThanDays Delete 'done' events older than this many days
     * @return int Number of events deleted
     */
    public function prune(int $olderThanDays = 30): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM events
            WHERE status = 'done'
              AND processed_at < datetime('now', '-' || ? || ' days')
        ");
        $stmt->execute([$olderThanDays]);
        return $stmt->rowCount();
    }

    /**
     * Dashboard summary: counts by status.
     */
    public function getSummary(): array
    {
        $rows = $this->pdo->query("
            SELECT status, COUNT(*) as cnt
            FROM events
            GROUP BY status
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $summary = [
            'pending'    => 0,
            'processing' => 0,
            'done'       => 0,
            'failed'     => 0,
            'dead'       => 0,
            'total'      => 0,
        ];
        foreach ($rows as $r) {
            $summary[$r['status']] = (int)$r['cnt'];
            $summary['total'] += (int)$r['cnt'];
        }
        return $summary;
    }

    /**
     * Get recent events for a specific entity (for ticket detail view).
     */
    public function getEntityHistory(string $entityType, int $entityId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, event_type, status, created_at, processed_at, error, attempts
            FROM events
            WHERE entity_type = ? AND entity_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$entityType, $entityId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
