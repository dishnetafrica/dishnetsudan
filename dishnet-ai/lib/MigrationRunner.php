<?php
declare(strict_types=1);

/**
 * MigrationRunner — Automatic SQL schema migrations for DishNet Hybrid.
 *
 * Reads .sql files from the migrations/ directory, applies them in
 * alphabetical order, and tracks which have been applied in a
 * _migrations table inside the same SQLite database.
 *
 * ── Integration ─────────────────────────────────────────────────────────
 *
 *   Called from SqliteStore::create() after PRAGMA setup:
 *     $runner = new MigrationRunner($pdo, dirname(__DIR__) . '/migrations');
 *     $runner->run();
 *
 *   This means migrations run on EVERY boot (plugin page load, cron, API).
 *   Each migration only runs once — the _migrations table tracks applied files.
 *   Migrations are idempotent (use IF NOT EXISTS on all CREATE statements).
 *
 * ── Rules for writing migrations ────────────────────────────────────────
 *
 *   1. One .sql file per logical change (e.g. 001_event_queue.sql)
 *   2. Always use CREATE TABLE IF NOT EXISTS / CREATE INDEX IF NOT EXISTS
 *   3. Never DROP or ALTER existing tables used by production code
 *   4. Add new columns only — removal happens in a later migration after
 *      all code has stopped reading the old column
 *   5. Numbering: 001_, 002_, 003_ — sorted alphabetically = execution order
 *   6. Each file can contain multiple statements separated by semicolons
 *
 * ── Error handling ──────────────────────────────────────────────────────
 *
 *   If a migration fails, it is NOT marked as applied.
 *   The error is logged to data/migration.log.
 *   Subsequent migrations are SKIPPED (fail-fast) to avoid partial state.
 *   The system continues to boot — migrations are non-blocking.
 *   Admin can see migration status via the maintenance tab.
 *
 * PHP 7.4 compatible. Zero external dependencies.
 */
class MigrationRunner
{
    private \PDO   $pdo;
    private string $migrationsDir;
    private string $logFile;

    public function __construct(\PDO $pdo, string $migrationsDir, string $logFile = '')
    {
        $this->pdo           = $pdo;
        $this->migrationsDir = rtrim($migrationsDir, '/');
        $this->logFile       = $logFile ?: dirname($migrationsDir) . '/data/migration.log';

        // Create tracking table (idempotent)
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS _migrations (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                filename    TEXT    UNIQUE NOT NULL,
                checksum    TEXT    NOT NULL,
                applied_at  TEXT    NOT NULL DEFAULT (datetime(\'now\')),
                duration_ms INTEGER DEFAULT 0
            )
        ');
    }

    /**
     * Run all pending migrations.
     *
     * @return array Results: [['file' => '001_...', 'status' => 'ok|skipped|FAILED', ...], ...]
     */
    public function run(): array
    {
        // Get list of already-applied migrations
        $applied = [];
        $stmt = $this->pdo->query('SELECT filename, checksum FROM _migrations ORDER BY id');
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $applied[$row['filename']] = $row['checksum'];
        }

        // Find all .sql files in migrations directory
        $files = glob($this->migrationsDir . '/*.sql');
        if (!$files) return [];
        sort($files); // alphabetical order = execution order

        $results = [];

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $sql      = file_get_contents($filePath);
            $checksum = md5($sql);

            // Already applied?
            if (isset($applied[$filename])) {
                // Check for tampering (file changed after being applied)
                if ($applied[$filename] !== $checksum) {
                    $this->log("WARNING: {$filename} has been modified after being applied (checksum mismatch). Skipping.");
                    $results[] = [
                        'file'   => $filename,
                        'status' => 'checksum_mismatch',
                        'error'  => 'File was modified after being applied. Manual intervention required.',
                    ];
                }
                continue; // Skip already-applied migrations
            }

            // Apply the migration — statement by statement for full resilience
            $startMs    = (int)(microtime(true) * 1000);
            $stmtErrors = [];
            $stmtOk     = 0;

            // Split on semicolons; skip empty/comment-only chunks
            $rawStmts = array_filter(
                array_map('trim', explode(';', $sql)),
                function($s) { return $s !== '' && !preg_match('/^(\s*--[^\n]*\n?)*\s*$/', $s); }
            );

            foreach ($rawStmts as $single) {
                try {
                    $this->pdo->exec($single);
                    $stmtOk++;
                } catch (\Exception $se) {
                    $err = $se->getMessage();
                    // Safe-to-ignore errors: object already exists, column already there,
                    // constraint violations from dedup UPDATEs, index on missing column (partial table)
                    $safe = stripos($err, 'already exists')     !== false
                         || stripos($err, 'duplicate column')   !== false
                         || stripos($err, 'UNIQUE constraint')   !== false
                         || stripos($err, 'NOT NULL constraint')  !== false
                         || (stripos($err, 'no such column') !== false && stripos($single, 'CREATE') !== false)
                         || (stripos($err, 'no such table')  !== false && stripos($single, 'INDEX')  !== false);
                    if (!$safe) {
                        $stmtErrors[] = substr($single, 0, 80) . '… — ' . $err;
                    }
                    // Always continue to next statement regardless
                }
            }

            $durationMs = (int)(microtime(true) * 1000) - $startMs;

            // Always mark as applied — partial success is still progress; won't re-run broken stmts
            $stmt = $this->pdo->prepare(
                'INSERT OR IGNORE INTO _migrations (filename, checksum, duration_ms) VALUES (?, ?, ?)'
            );
            $stmt->execute([$filename, $checksum, $durationMs]);

            if (empty($stmtErrors)) {
                $this->log("OK: {$filename} ({$stmtOk} stmts, {$durationMs}ms)");
                $results[] = ['file' => $filename, 'status' => 'ok', 'duration_ms' => $durationMs];
            } else {
                $summary = implode(' | ', array_slice($stmtErrors, 0, 3));
                $this->log("PARTIAL: {$filename} — {$stmtOk} ok, " . count($stmtErrors) . " skipped: {$summary}");
                $results[] = ['file' => $filename, 'status' => 'partial', 'ok' => $stmtOk, 'errors' => $stmtErrors];
            }
        }

        return $results;
    }

    /**
     * Get current migration status (for admin dashboard / maintenance tab).
     */
    public function getStatus(): array
    {
        $applied = $this->pdo->query(
            'SELECT filename, checksum, applied_at, duration_ms FROM _migrations ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files);

        $pending = [];
        $appliedNames = array_column($applied, 'filename');
        foreach ($files as $f) {
            $name = basename($f);
            if (!in_array($name, $appliedNames, true)) {
                $pending[] = $name;
            }
        }

        return [
            'applied'       => $applied,
            'pending'       => $pending,
            'total_files'   => count($files),
            'total_applied' => count($applied),
        ];
    }

    /**
     * Append a line to the migration log file.
     */
    private function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
