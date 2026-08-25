<?php
declare(strict_types=1);

/**
 * CrmQueue — Background job queue for CRM sync.
 *
 * v3.0 (Phase 2): Dual-write to SQLite job_queue table + JSON blob.
 *
 * READS:  SQL job_queue table (primary) with JSON fallback
 * WRITES: Both SQL + JSON (dual-write) for safety during transition
 *
 * All method signatures unchanged. Callers (cron_sync.php,
 * runInProcessSync, KycService) need zero changes.
 *
 * PHP 7.4 compatible. Zero external dependencies.
 */
class CrmQueue
{
    private const FILE        = 'crm_queue.json';
    private const MAX_RETRIES = 3;

    private $store;
    private ?\PDO $pdo = null;
    private bool $tableReady = false;

    public function __construct($store)
    {
        $this->store = $store;
        $this->initTable();
    }

    private function initTable(): void
    {
        try {
            if (method_exists($this->store, 'getPdo')) {
                $this->pdo = $this->store->getPdo();
                $this->tableReady = (bool)$this->pdo->query(
                    "SELECT 1 FROM sqlite_master WHERE type='table' AND name='job_queue'"
                )->fetch();
            }
        } catch (\Throwable $e) {
            $this->tableReady = false;
        }
    }

    /**
     * Enqueue a CRM sync job. Returns job ID.
     */
    public function enqueue(array $jobData): int
    {
        // Always write to JSON (backward compat for admin dashboard, backups)
        $record = $this->store->appendWithId(self::FILE, array_merge($jobData, [
            'status'       => 'pending',
            'attempts'     => 0,
            'last_error'   => null,
            'crm_client_id'=> null,
            'next_retry_at'=> date('Y-m-d H:i:s'),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
            'completed_at' => null,
        ]));
        $jsonId = (int)$record['id'];

        // Dual-write to SQL
        if ($this->tableReady) {
            try {
                $appId = (int)($jobData['application_id'] ?? 0);
                $payload = $jobData;
                // Strip queue-state fields from payload (they live in columns)
                unset($payload['status'], $payload['attempts'], $payload['last_error'],
                      $payload['crm_client_id'], $payload['next_retry_at'],
                      $payload['created_at'], $payload['updated_at'], $payload['completed_at']);

                $this->pdo->prepare(
                    "INSERT INTO job_queue (job_type, payload, status, application_id, created_at, updated_at)
                     VALUES ('crm_sync', ?, 'pending', ?, datetime('now'), datetime('now'))"
                )->execute([
                    json_encode($payload, JSON_UNESCAPED_UNICODE),
                    $appId ?: null,
                ]);
            } catch (\Throwable $e) {
                error_log("CrmQueue::enqueue SQL dual-write failed: " . $e->getMessage());
            }
        }
        return $jsonId;
    }

    /**
     * Get next pending jobs ready to process.
     */
    public function getPendingJobs(int $limit = 10): array
    {
        // Try SQL first (faster, atomic dequeue via index)
        if ($this->tableReady) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT * FROM job_queue
                    WHERE status IN ('pending', 'failed')
                      AND attempts < ?
                      AND next_retry_at <= datetime('now')
                    ORDER BY priority ASC, created_at ASC
                    LIMIT ?
                ");
                $stmt->execute([self::MAX_RETRIES, $limit]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    return array_map(function ($row) {
                        $payload = json_decode($row['payload'] ?? '{}', true) ?: [];
                        return array_merge($payload, [
                            'id'            => (int)$row['id'],
                            'status'        => $row['status'],
                            'attempts'      => (int)$row['attempts'],
                            'last_error'    => $row['error'],
                            'crm_client_id' => $row['crm_client_id'],
                            'application_id'=> (int)($row['application_id'] ?? 0),
                            'created_at'    => $row['created_at'],
                            'updated_at'    => $row['updated_at'],
                            'completed_at'  => $row['completed_at'],
                            '_source'       => 'sql',
                        ]);
                    }, $rows);
                }
            } catch (\Throwable $e) {
                error_log("CrmQueue SQL read failed, JSON fallback: " . $e->getMessage());
            }
        }
        // JSON fallback
        $all = $this->store->load(self::FILE);
        $now = date('Y-m-d H:i:s');
        $pending = array_filter($all, function ($j) use ($now) {
            return in_array($j['status'] ?? '', ['pending', 'failed'])
                && ($j['attempts'] ?? 0) < self::MAX_RETRIES
                && ($j['next_retry_at'] ?? '') <= $now;
        });
        usort($pending, function ($a, $b) { return ($a['created_at'] ?? '') <=> ($b['created_at'] ?? ''); });
        return array_slice($pending, 0, $limit);
    }

    public function markProcessing(int $jobId): bool
    {
        $jsonOk = $this->store->updateOne(self::FILE, 'id', $jobId, [
            'status' => 'processing', 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->sqlUpdate($jobId, ['status' => 'processing']);
        return $jsonOk;
    }

    public function markCompleted(int $jobId, string $crmClientId): bool
    {
        $jsonOk = $this->store->updateOne(self::FILE, 'id', $jobId, [
            'status' => 'completed', 'crm_client_id' => $crmClientId,
            'completed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($this->tableReady) {
            try {
                $this->pdo->prepare(
                    "UPDATE job_queue SET status='completed', crm_client_id=?, completed_at=datetime('now'),
                     updated_at=datetime('now'), locked_by=NULL, locked_at=NULL WHERE id=?"
                )->execute([$crmClientId, $jobId]);
            } catch (\Throwable $e) { error_log("CrmQueue SQL markCompleted: " . $e->getMessage()); }
        }
        return $jsonOk;
    }

    public function markFailed(int $jobId, string $error): bool
    {
        $job      = $this->store->findOne(self::FILE, 'id', $jobId);
        $attempts = ($job['attempts'] ?? 0) + 1;
        $backoff  = [60, 300, 1800];
        $delay    = $backoff[min($attempts - 1, count($backoff) - 1)];
        $nextRetry= date('Y-m-d H:i:s', time() + $delay);
        $isDead   = $attempts >= self::MAX_RETRIES;

        $jsonOk = $this->store->updateOne(self::FILE, 'id', $jobId, [
            'status' => $isDead ? 'exhausted' : 'failed', 'attempts' => $attempts,
            'last_error' => $error, 'next_retry_at' => $nextRetry, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($this->tableReady) {
            try {
                $this->pdo->prepare(
                    "UPDATE job_queue SET status=?, attempts=?, error=?, next_retry_at=?,
                     updated_at=datetime('now'), locked_by=NULL, locked_at=NULL WHERE id=?"
                )->execute([$isDead ? 'exhausted' : 'failed', $attempts, $error, $nextRetry, $jobId]);
            } catch (\Throwable $e) { error_log("CrmQueue SQL markFailed: " . $e->getMessage()); }
        }
        return $jsonOk;
    }

    public function markReversed(int $jobId, string $reason): bool
    {
        $jsonOk = $this->store->updateOne(self::FILE, 'id', $jobId, [
            'status' => 'reversed', 'last_error' => $reason, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->sqlUpdate($jobId, ['status' => 'reversed', 'error' => $reason]);
        return $jsonOk;
    }

    public function getByApplicationId(int $appId): ?array
    {
        if ($this->tableReady) {
            try {
                $stmt = $this->pdo->prepare('SELECT * FROM job_queue WHERE application_id=? ORDER BY id DESC LIMIT 1');
                $stmt->execute([$appId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $payload = json_decode($row['payload'] ?? '{}', true) ?: [];
                    return array_merge($payload, [
                        'id' => (int)$row['id'], 'status' => $row['status'],
                        'attempts' => (int)$row['attempts'], 'last_error' => $row['error'],
                        'crm_client_id' => $row['crm_client_id'],
                        'application_id' => (int)($row['application_id'] ?? 0),
                    ]);
                }
            } catch (\Throwable $e) { /* JSON fallback */ }
        }
        return $this->store->findOne(self::FILE, 'application_id', $appId);
    }

    public function getSummary(): array
    {
        if ($this->tableReady) {
            try {
                $rows = $this->pdo->query("SELECT status, COUNT(*) as cnt FROM job_queue GROUP BY status")
                    ->fetchAll(\PDO::FETCH_ASSOC);
                $s = ['pending'=>0,'processing'=>0,'completed'=>0,'failed'=>0,'exhausted'=>0,'reversed'=>0,'total'=>0];
                foreach ($rows as $r) { $s[$r['status']] = (int)$r['cnt']; $s['total'] += (int)$r['cnt']; }
                return $s;
            } catch (\Throwable $e) { /* JSON fallback */ }
        }
        $all = $this->store->load(self::FILE);
        $s = ['pending'=>0,'processing'=>0,'completed'=>0,'failed'=>0,'exhausted'=>0,'reversed'=>0];
        foreach ($all as $j) { $k = $j['status'] ?? 'pending'; $s[$k] = ($s[$k] ?? 0) + 1; }
        $s['total'] = count($all);
        return $s;
    }

    /** Generic SQL column updater */
    private function sqlUpdate(int $jobId, array $fields): void
    {
        if (!$this->tableReady) return;
        try {
            $sets = []; $vals = [];
            foreach ($fields as $col => $val) { $sets[] = "{$col} = ?"; $vals[] = $val; }
            $sets[] = "updated_at = datetime('now')";
            $sets[] = "locked_by = NULL";
            $sets[] = "locked_at = NULL";
            $vals[] = $jobId;
            $this->pdo->prepare("UPDATE job_queue SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        } catch (\Throwable $e) { error_log("CrmQueue::sqlUpdate error: " . $e->getMessage()); }
    }
}
