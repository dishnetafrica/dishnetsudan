<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }

require_once __DIR__ . '/StoreInterface.php';

/**
 * SqliteStore — Production-grade SQLite backend for DishNet Hybrid Telecom.
 *
 * v2.0 — Complete rewrite. Key improvements over v1:
 *
 *  ① appendWithId  — real INSERT + RETURNING, no full-table rewrite
 *  ② append        — real INSERT, no full-table rewrite
 *  ③ withLock      — surgical UPDATE per row, not DELETE-all + re-INSERT-all
 *  ④ findOne/findAll — json_extract() index queries, not PHP-side array_filter
 *  ⑤ Flat-object configs — stored as id=1 single row, detected reliably
 *  ⑥ Separate index table — tracks which tables have been indexed
 *  ⑦ install_leads.php compatibility — factory accepts both JsonStore + SqliteStore callers
 *
 * Schema per table
 * ────────────────
 *   CREATE TABLE [{table}] (
 *     id   INTEGER PRIMARY KEY,   -- matches record['id'] or AUTOINCREMENT
 *     data TEXT NOT NULL          -- full JSON of the record
 *   )
 *
 * Flat-object collections (kyc_config, email_settings, etc.)
 * ─────────────────────────────────────────────────────────
 *   Stored as single row with id = 0 (sentinel).
 *   load() detects id=0 and returns the decoded object directly,
 *   so callers get ['crm_base_url'=>'...'] not [['crm_base_url'=>'...']].
 *
 * Performance (at 10,000+ rows per table)
 * ────────────────────────────────────────
 *   append / appendWithId : O(1)  — single INSERT
 *   findOne by id         : O(1)  — PRIMARY KEY lookup
 *   findOne by field      : O(log n) — json_extract index
 *   load()                : O(n)  — sequential scan (unavoidable for full loads)
 *   withLock single-row   : O(log n) — UPDATE WHERE id = ?
 *   withLock multi-row    : O(n)  — load + write changed rows only
 *
 * Migration
 * ─────────
 *   First boot: if plugin.sqlite3 missing, all *.json files are imported.
 *   Originals renamed to *.json.migrated (kept as backups).
 *   Migration log written to data/sqlite_migration.log.
 */
class SqliteStore implements StoreInterface
{
    private \PDO   $pdo;
    private string $dir;

    /** Tables known to store flat-object configs (not record arrays). */
    private static array $FLAT_TABLES = [
        'kyc_config', 'email_settings', 'backup_settings',
        'last_backup_meta', 'sync_last_run', 'ucrm_sync_meta',
        'ucrm_pull_last_run',
        // APK manager — meta is a single assoc dict, history is an indexed array
        'android_app_meta',
        // Cron state files — assoc arrays keyed by job name or job ID
        'master_schedule', 'job_assignments_seen',
        'scheduling_cache_meta', 'wa_sync_state',
        'cash_carry_reminder_state',
        // v4.15.4: hotspot_config is keyed by Router-XXX (string keys, assoc dict)
        // Without this, SqliteStore::save writes the assoc array via the auto-detect
        // path (single row with id=0), but SqliteStore::load wraps the result in
        // array_values() because the table isn't recognized as flat — which strips
        // the string keys and returns [{...}] instead of {"Router-X": {...}}.
        // Net effect before the fix: POST appears to save (returns hotspot_mode:true)
        // but GET reads back hotspot_mode:false because $cfg["Router-X"] is null.
        'hotspot_config',
    ];

    /** Large cache tables — stored as single JSON blob row for efficiency. */
    private static array $BLOB_TABLES = [
        'ucrm_clients_cache', 'ucrm_invoices_cache', 'ucrm_services_cache',
        'ucrm_plans_cache', 'org7_crm_cache', 'org7_crm_balance_cache',
        'sp_client_index', 'sp_summary',
    ];

    /** Sentinel id for flat-object rows. */
    private const FLAT_ID = 0;

    // ── Constructor (private — use ::create()) ─────────────────────────────
    private function __construct(\PDO $pdo, string $dir)
    {
        $this->pdo = $pdo;
        $this->dir = rtrim($dir, '/');
    }

    // ══════════════════════════════════════════════════════════════════════
    // FACTORY
    // ══════════════════════════════════════════════════════════════════════

    public static function create(string $dataDir): self
    {
        $dataDir = rtrim($dataDir, '/');
        if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

        $dbPath = $dataDir . '/plugin.sqlite3';
        $isNew  = !file_exists($dbPath);

        $pdo = new \PDO('sqlite:' . $dbPath, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        // Performance PRAGMAs — set before any other queries.
        // ORDER MATTERS: busy_timeout must come first so that journal_mode=WAL
        // recovery waits up to 5s for a lock rather than failing instantly.
        // Wrapped individually: PRAGMA failures must never crash the whole plugin.
        $pragmas = [
            "PRAGMA busy_timeout  = 5000",     // FIRST: wait 5s on any lock contention
            "PRAGMA journal_mode = WAL",       // concurrent readers + single writer
            "PRAGMA synchronous   = NORMAL",   // safe + fast (fsync on checkpoint)
            "PRAGMA foreign_keys  = ON",
            "PRAGMA temp_store    = MEMORY",
            "PRAGMA cache_size    = -32000",   // 32 MB page cache (v4.11.3: doubled)
            "PRAGMA mmap_size     = 268435456",// 256 MB memory-mapped I/O (v4.11.3: doubled)
            "PRAGMA wal_autocheckpoint = 1000",// checkpoint every 1000 pages
        ];

        $walFailed = false;
        foreach ($pragmas as $pragma) {
            try {
                $pdo->exec($pragma);
            } catch (\Throwable $e) {
                error_log("[SqliteStore] PRAGMA failed ({$pragma}): " . $e->getMessage());
                if (str_contains($pragma, 'journal_mode')) {
                    $walFailed = true;
                }
            }
        }

        // ── WAL auto-recovery ────────────────────────────────────────────
        // If journal_mode=WAL failed, stale -wal/-shm files from a previous
        // crash may be blocking the connection. Delete them and reopen.
        // The database itself is safe: all committed data is in the main file.
        if ($walFailed) {
            $walFile = $dbPath . '-wal';
            $shmFile = $dbPath . '-shm';
            $deleted = [];
            if (file_exists($walFile) && @unlink($walFile)) $deleted[] = basename($walFile);
            if (file_exists($shmFile) && @unlink($shmFile)) $deleted[] = basename($shmFile);
            if ($deleted) {
                error_log('[SqliteStore] WAL recovery: deleted stale files: ' . implode(', ', $deleted) . ' — reopening database');
                // Reopen with a fresh connection
                $pdo = new \PDO('sqlite:' . $dbPath, null, null, [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
                try { $pdo->exec("PRAGMA busy_timeout = 5000"); } catch (\Throwable $e) {}
                try { $pdo->exec("PRAGMA journal_mode = WAL"); } catch (\Throwable $e) {
                    error_log('[SqliteStore] WAL still fails after recovery: ' . $e->getMessage());
                }
                try { $pdo->exec("PRAGMA foreign_keys = ON");   } catch (\Throwable $e) {}
            }
        }

        $store = new self($pdo, $dataDir);

        // ── WAL checkpoint on shutdown ────────────────────────────────────
        // PHP's exit() (used by API ok2/er2 helpers) bypasses normal cleanup,
        // leaving WAL pages unmerged. A shutdown function ensures we always
        // checkpoint the WAL back into the main database file before the
        // process dies — preventing stale WAL files from accumulating.
        register_shutdown_function(static function() use ($pdo) {
            try {
                // PASSIVE checkpoint: merges WAL into main DB without blocking readers.
                // If another process is reading, this is a no-op (safe to skip).
                $pdo->exec("PRAGMA wal_checkpoint(PASSIVE)");
            } catch (\Throwable $e) {
                // Never throw from shutdown — process is already ending
            }
        });

        if ($isNew) {
            $store->firstBootMigration();
        } else {
            // Ensure indexes exist on existing database (idempotent)
            $store->ensureIndexes();
        }

        // ── Run schema migrations (Phase 2 v3.8) ────────────────────────
        // Migrations are idempotent and non-blocking.
        // Creates events, job_queue, tickets tables on first run.
        // dirname(__DIR__) = plugin root (lib/../ = plugin root)
        $migrationsDir = dirname(__DIR__) . '/migrations';
        if (is_dir($migrationsDir)) {
            require_once dirname(__DIR__) . '/lib/MigrationRunner.php';
            $runner = new \MigrationRunner($pdo, $migrationsDir);
            $runner->run(); // Safe: skips already-applied, logs errors, never throws
        }

        // NOTE: the parent DishNet Hybrid plugin seeds BlueCard/LTE data here.
        // This plugin has no LTE feature, so that block is removed rather than
        // left to log a missing directory once a minute forever.

        return $store;
    }

    /**
     * Get the underlying PDO instance.
     * Used by EventBus and MigrationRunner for direct SQL access
     * to normalized tables (events, job_queue, tickets).
     *
     * @return \PDO
     */
    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Seed LTE data from data/seed/*.json into the store.
     * Only runs if the target collection is empty — never overwrites.
     * Called once from create() after migrations.
     */
    public function seedLteData(string $seedDir): void
    {
        $files = [
            'lte_packages.json',
            'lte_sims.json',
            'lte_subscribers.json',
            'lte_subscriptions.json',
            'lte_renewals.json',
        ];

        // Use a simple flag file so we only check once ever
        $flagFile = $this->dir . '/.lte_seeded';
        if (file_exists($flagFile)) return;

        $didSeed = false;
        foreach ($files as $file) {
            $src = $seedDir . '/' . $file;
            if (!file_exists($src)) continue;

            try {
                // Only seed if currently empty
                $existing = $this->load($file);
                if (!empty($existing)) continue;

                $data = json_decode(file_get_contents($src), true);
                if (!is_array($data) || empty($data)) continue;

                $this->save($file, $data);
                $didSeed = true;
                error_log("[LTE seed] {$file}: " . count($data) . " records loaded");
            } catch (\Throwable $e) {
                error_log("[LTE seed] ERROR {$file}: " . $e->getMessage());
            }
        }

        // Write flag file whether we seeded or not (data might be pre-existing)
        @file_put_contents($flagFile, date('Y-m-d H:i:s') . ($didSeed ? ' seeded' : ' skipped'));
    }

    public function getDataDir(): string
    {
        return $this->dir;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PUBLIC INTERFACE — identical signatures to JsonStore
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Load all records from a collection.
     * Returns plain array for record collections, assoc array for flat configs.
     */
    public function load(string $file): array
    {
        // v4.11.3 PERF: Request-level in-memory cache for read-only blobs.
        // retailers.json is loaded 29x per admin page — this makes 28 of those free.
        // Cache is cleared by save() and updateOne() to prevent stale reads.
        static $_loadCache = [];
        // Only cache known-stable blobs (not time-sensitive data)
        static $_cacheable = ['retailers.json', 'kyc_devices.json', 'kyc_packages.json',
                              'subscription_plans.json', 'kyc_config.json'];
        if (in_array($file, $_cacheable, true) && isset($_loadCache[$file])) {
            return $_loadCache[$file];
        }

        $table = $this->tableFor($file);
        $this->ensureTable($table);

        // ── Proper column tables (from SQL migrations, e.g. lte_subscribers) ──
        // These have real columns, not blob (id+data) format.
        if ($this->isProperTable($table)) {
            try {
                $rows = $this->pdo
                    ->query("SELECT * FROM [{$table}] ORDER BY id ASC")
                    ->fetchAll(\PDO::FETCH_ASSOC);
                return $rows ?: [];
            } catch (\Throwable $e) {
                return [];
            }
        }

        if ($this->isBlobTable($table)) {
            // Blob tables: single JSON-encoded row
            $row = $this->pdo
                ->query("SELECT data FROM [{$table}] WHERE id = " . self::FLAT_ID . " LIMIT 1")
                ->fetchColumn();
            if ($row === false) return [];
            $decoded = json_decode($row, true);
            $result_blob = is_array($decoded) ? $decoded : [];
            if (in_array($file, $_cacheable, true)) { $_loadCache[$file] = $result_blob; }
            return $result_blob;
        }

        if ($this->isFlatTable($table)) {
            // Config tables: single row with sentinel id
            $row = $this->pdo
                ->query("SELECT data FROM [{$table}] WHERE id = " . self::FLAT_ID . " LIMIT 1")
                ->fetchColumn();
            if ($row === false) return [];
            $decoded = json_decode($row, true);
            return is_array($decoded) ? $decoded : [];
        }

        // Normal record array
        try {
            $rows = $this->pdo
                ->query("SELECT data FROM [{$table}] ORDER BY id ASC")
                ->fetchAll(\PDO::FETCH_COLUMN);

            $result = array_values(array_filter(array_map(
                fn($r) => json_decode($r, true),
                $rows
            )));
            // Cache stable blobs for this request (cleared by save/updateOne)
            if (in_array($file, $_cacheable, true)) { $_loadCache[$file] = $result; }
            return $result;
        } catch (\PDOException $e) {
            // Fallback: table has proper columns (no 'data' column).
            // This happens when isProperTable cache is stale or table was
            // migrated to structured format between check and query.
            if (strpos($e->getMessage(), 'no such column') !== false) {
                try {
                    $rows = $this->pdo
                        ->query("SELECT * FROM [{$table}] ORDER BY id ASC")
                        ->fetchAll(\PDO::FETCH_ASSOC);
                    return $rows ?: [];
                } catch (\Throwable $e2) {
                    return [];
                }
            }
            throw $e;
        }
    }

    /**
     * Save (replace) entire collection.
     * O(n) — unavoidable for full-collection writes, but uses a single transaction.
     */
    public function save(string $file, array $data): void
    {
        // v4.11.3 PERF: Invalidate request-level load cache on write
        static $_loadCache = [];
        unset($_loadCache[$file]);

        $table = $this->tableFor($file);
        $this->ensureTable($table);

        // ── Structured tables (from SQL migrations) — skip blob save ──
        // These tables have proper columns and must be written via direct SQL
        // (e.g. LteSqliteService or the lte_reseed API endpoint).
        // Silently skip to prevent "no such column: data" crashes.
        if ($this->isProperTable($table)) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("DELETE FROM [{$table}]");
            $stmt = $this->pdo->prepare(
                "INSERT INTO [{$table}] (id, data) VALUES (:id, :data)"
            );

            if ($this->isBlobTable($table) || $this->isFlatTable($table)) {
                // Store as single blob row
                $stmt->execute([
                    ':id'   => self::FLAT_ID,
                    ':data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                ]);
            } elseif ($this->isAssocArray($data)) {
                // Auto-detect flat object (for files not in FLAT_TABLES but stored as assoc)
                $stmt->execute([
                    ':id'   => self::FLAT_ID,
                    ':data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                ]);
            } else {
                foreach ($data as $record) {
                    $stmt->execute([
                        ':id'   => isset($record['id']) ? (int)$record['id'] : null,
                        ':data' => json_encode($record, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Append a record — real O(1) INSERT, no full-table rewrite.
     */
    public function append(string $file, array $record): array
    {
        $table = $this->tableFor($file);
        $this->ensureTable($table);

        // Structured tables must be written via direct SQL, not blob append
        if ($this->isProperTable($table)) {
            return $record;
        }

        $id = isset($record['id']) ? (int)$record['id'] : null;
        $this->pdo
            ->prepare("INSERT OR REPLACE INTO [{$table}] (id, data) VALUES (:id, :data)")
            ->execute([
                ':id'   => $id,
                ':data' => json_encode($record, JSON_UNESCAPED_UNICODE),
            ]);

        return $record;
    }

    /**
     * Find first record matching key=value.
     * Uses json_extract index when available — O(log n) for indexed fields.
     */
    public function findOne(string $file, string $key, $value): ?array
    {
        $table = $this->tableFor($file);
        $this->ensureTable($table);

        // ── Proper column tables — direct WHERE clause ──
        if ($this->isProperTable($table)) {
            try {
                // Sanitize key to prevent injection (only allow alphanumeric + underscore)
                $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
                $stmt = $this->pdo->prepare(
                    "SELECT * FROM [{$table}] WHERE [{$safeKey}] = :val LIMIT 1"
                );
                $stmt->execute([':val' => $value]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $row ?: null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Fast path: PRIMARY KEY lookup
        if ($key === 'id') {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT data FROM [{$table}] WHERE id = :id LIMIT 1"
                );
                $stmt->execute([':id' => (int)$value]);
                $row = $stmt->fetchColumn();
                return ($row !== false) ? (json_decode($row, true) ?: null) : null;
            } catch (\PDOException $e) {
                if (strpos($e->getMessage(), 'no such column') !== false) {
                    $stmt2 = $this->pdo->prepare("SELECT * FROM [{$table}] WHERE id = :id LIMIT 1");
                    $stmt2->execute([':id' => (int)$value]);
                    $row = $stmt2->fetch(\PDO::FETCH_ASSOC);
                    return $row ?: null;
                }
                throw $e;
            }
        }

        // json_extract path — uses index if created, falls back to scan
        try {
            $stmt = $this->pdo->prepare(
                "SELECT data FROM [{$table}] WHERE json_extract(data, :path) = :val LIMIT 1"
            );
            $stmt->execute([':path' => "\$.{$key}", ':val' => $value]);
            $row = $stmt->fetchColumn();
            return ($row !== false) ? (json_decode($row, true) ?: null) : null;
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'no such column') !== false) {
                $safeKey2 = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
                $stmt2 = $this->pdo->prepare("SELECT * FROM [{$table}] WHERE [{$safeKey2}] = :val LIMIT 1");
                $stmt2->execute([':val' => $value]);
                $row = $stmt2->fetch(\PDO::FETCH_ASSOC);
                return $row ?: null;
            }
            throw $e;
        }
    }

    /**
     * Find all records matching key=value.
     * Uses json_extract index when available.
     */
    public function findAll(string $file, string $key, $value): array
    {
        $table = $this->tableFor($file);
        $this->ensureTable($table);

        // ── Proper column tables — direct WHERE clause ──
        if ($this->isProperTable($table)) {
            try {
                $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
                $stmt = $this->pdo->prepare(
                    "SELECT * FROM [{$table}] WHERE [{$safeKey}] = :val ORDER BY id ASC"
                );
                $stmt->execute([':val' => $value]);
                return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                return [];
            }
        }

        try {
            if ($key === 'id') {
                $stmt = $this->pdo->prepare(
                    "SELECT data FROM [{$table}] WHERE id = :id ORDER BY id ASC"
                );
                $stmt->execute([':id' => (int)$value]);
            } else {
                // v4.11.3: Cast both sides to text so integer 24 matches stored string "24"
                // and vice versa — json_extract is type-sensitive in SQLite.
                $stmt = $this->pdo->prepare(
                    "SELECT data FROM [{$table}] WHERE CAST(json_extract(data, :path) AS TEXT) = CAST(:val AS TEXT) ORDER BY id ASC"
                );
                $stmt->execute([':path' => "\$.{$key}", ':val' => $value]);
            }

            return array_values(array_filter(array_map(
                fn($r) => json_decode($r, true),
                $stmt->fetchAll(\PDO::FETCH_COLUMN)
            )));
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'no such column') !== false) {
                $safeKey2 = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
                $stmt2 = $this->pdo->prepare("SELECT * FROM [{$table}] WHERE [{$safeKey2}] = :val ORDER BY id ASC");
                $stmt2->execute([':val' => $value]);
                return $stmt2->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            }
            throw $e;
        }
    }

    /**
     * Update first matching record — surgical UPDATE, not full rewrite.
     * O(log n) for indexed fields, O(n) for unindexed.
     */
    public function updateOne(string $file, string $key, $value, array $updates): bool
    {
        // v4.11.3 PERF: Invalidate request-level load cache on write
        static $_loadCache = [];
        unset($_loadCache[$file]);

        $table = $this->tableFor($file);
        $this->ensureTable($table);

        // ── Proper column tables — direct UPDATE on columns ──
        if ($this->isProperTable($table)) {
            try {
                // Find the row first to get its id
                $record = $this->findOne($file, $key, $value);
                if ($record === null) return false;
                $rowId = (int)($record['id'] ?? 0);
                if (!$rowId) return false;

                // Build SET clause from updates (only columns that exist)
                $cols = $this->pdo->query("PRAGMA table_info([{$table}])")->fetchAll(\PDO::FETCH_COLUMN, 1);
                $sets = [];
                $params = [':_id' => $rowId];
                foreach ($updates as $col => $val) {
                    $safeCol = preg_replace('/[^a-zA-Z0-9_]/', '', $col);
                    if (!in_array($safeCol, $cols, true)) continue;
                    $sets[] = "[{$safeCol}] = :u_{$safeCol}";
                    $params[":u_{$safeCol}"] = $val;
                }
                if (empty($sets)) return false;

                // Add updated_at if column exists
                if (in_array('updated_at', $cols, true) && !isset($updates['updated_at'])) {
                    $sets[] = "[updated_at] = :u_updated_at";
                    $params[':u_updated_at'] = date('Y-m-d H:i:s');
                }

                $sql = "UPDATE [{$table}] SET " . implode(', ', $sets) . " WHERE id = :_id";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->rowCount() > 0;
            } catch (\Throwable $e) {
                error_log("[SqliteStore::updateOne] proper table error on {$table}: " . $e->getMessage());
                return false;
            }
        }

        // 1. Fetch the matching row
        $record = $this->findOne($file, $key, $value);
        if ($record === null) return false;

        // 2. Merge updates
        $merged = array_merge($record, $updates);

        // 3. Write back — single UPDATE by id
        $rowId = isset($record['id']) ? (int)$record['id'] : self::FLAT_ID;
        $stmt  = $this->pdo->prepare(
            "UPDATE [{$table}] SET data = :data WHERE id = :id"
        );
        $stmt->execute([
            ':data' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            ':id'   => $rowId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Next auto-increment ID — O(1) MAX(id) query.
     */
    public function nextId(string $file): int
    {
        $table = $this->tableFor($file);
        $this->ensureTable($table);

        $max = $this->pdo
            ->query("SELECT MAX(id) FROM [{$table}]")
            ->fetchColumn();

        return ($max === null || $max === false) ? 1 : (int)$max + 1;
    }

    /**
     * Atomic append with auto-assigned ID.
     * O(1) — uses MAX(id) + INSERT in a single transaction.
     * No full-table rewrite.
     */
    public function appendWithId(string $file, array $record): array
    {
        $table = $this->tableFor($file);
        $this->ensureTable($table);

        // ── Proper column tables — INSERT into real columns ──
        if ($this->isProperTable($table)) {
            $this->pdo->beginTransaction();
            try {
                $max = $this->pdo
                    ->query("SELECT MAX(id) FROM [{$table}]")
                    ->fetchColumn();
                $newId        = ($max === null || $max === false) ? 1 : (int)$max + 1;
                $record['id'] = $newId;

                // Only insert columns that exist in the table
                $cols = $this->pdo->query("PRAGMA table_info([{$table}])")->fetchAll(\PDO::FETCH_COLUMN, 1);
                $insertCols = [];
                $placeholders = [];
                $params = [];
                foreach ($record as $col => $val) {
                    $safeCol = preg_replace('/[^a-zA-Z0-9_]/', '', $col);
                    if (!in_array($safeCol, $cols, true)) continue;
                    $insertCols[] = "[{$safeCol}]";
                    $placeholders[] = ":c_{$safeCol}";
                    $params[":c_{$safeCol}"] = $val;
                }
                // Add created_at if column exists and not already set
                if (in_array('created_at', $cols, true) && !isset($record['created_at'])) {
                    $insertCols[] = "[created_at]";
                    $placeholders[] = ":c_created_at";
                    $params[':c_created_at'] = date('Y-m-d H:i:s');
                }

                $sql = "INSERT INTO [{$table}] (" . implode(',', $insertCols) . ") VALUES (" . implode(',', $placeholders) . ")";
                $this->pdo->prepare($sql)->execute($params);
                $this->pdo->commit();
                return $record;
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        $this->pdo->beginTransaction();
        try {
            $max = $this->pdo
                ->query("SELECT MAX(id) FROM [{$table}]")
                ->fetchColumn();

            $newId         = ($max === null || $max === false) ? 1 : (int)$max + 1;
            $record['id']  = $newId;

            $this->pdo
                ->prepare("INSERT INTO [{$table}] (id, data) VALUES (:id, :data)")
                ->execute([
                    ':id'   => $newId,
                    ':data' => json_encode($record, JSON_UNESCAPED_UNICODE),
                ]);

            $this->pdo->commit();
            return $record;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Compatibility stub — returns SQLite db path for any file argument.
     */
    public function path(string $file): string
    {
        return $this->dir . '/plugin.sqlite3';
    }

    /**
     * Locked read-modify-write cycle (for complex multi-row operations).
     *
     * v2.0: Uses surgical per-row UPDATEs — only writes rows that changed.
     * For single-record updates, prefer updateOne() which is O(log n).
     * withLock is O(n) reads + O(changed) writes.
     */
    public function withLock(string $file, callable $fn)
    {
        $table = $this->tableFor($file);
        $this->ensureTable($table);

        $this->pdo->beginTransaction();
        try {
            // Read snapshot
            $rows = $this->pdo
                ->query("SELECT id, data FROM [{$table}] ORDER BY id ASC")
                ->fetchAll(\PDO::FETCH_ASSOC);

            $before = [];
            foreach ($rows as $row) {
                $before[(int)$row['id']] = $row['data'];
            }

            $records = array_values(array_filter(array_map(
                fn($r) => json_decode($r['data'], true),
                $rows
            )));

            // Unwrap flat-object
            $isFlat = $this->isFlatTable($table)
                || ($this->isAssocArray($records[0] ?? null) && !isset(($records[0] ?? [])['id']));
            if ($isFlat && count($records) === 1) {
                $records = $records[0];
            }

            // Run the modification callback
            $result   = $fn($records);
            $modified = $result['records'];

            // Re-wrap flat-object for writing
            if ($isFlat && $this->isAssocArray($modified)) {
                $modified = [$modified];
                $modified[0]['_flat'] = true; // mark for id=0 storage
            }

            // Surgical write: only update/insert/delete changed rows
            $stmtUpdate = $this->pdo->prepare(
                "UPDATE [{$table}] SET data = :data WHERE id = :id"
            );
            $stmtInsert = $this->pdo->prepare(
                "INSERT INTO [{$table}] (id, data) VALUES (:id, :data)"
            );

            $afterIds = [];
            foreach ($modified as $record) {
                if ($isFlat && isset($record['_flat'])) {
                    unset($record['_flat']);
                    $rowId = self::FLAT_ID;
                } else {
                    $rowId = isset($record['id']) ? (int)$record['id'] : null;
                }

                $json = json_encode($record, JSON_UNESCAPED_UNICODE);

                if ($rowId !== null && isset($before[$rowId])) {
                    // Row existed — only update if data changed
                    if ($before[$rowId] !== $json) {
                        $stmtUpdate->execute([':data' => $json, ':id' => $rowId]);
                    }
                    $afterIds[$rowId] = true;
                } else {
                    // New row — INSERT
                    if ($rowId === null) {
                        // Auto-assign id
                        $max   = $this->pdo->query("SELECT MAX(id) FROM [{$table}]")->fetchColumn();
                        $rowId = ($max === null || $max === false) ? 1 : (int)$max + 1;
                        $record['id'] = $rowId;
                        $json = json_encode($record, JSON_UNESCAPED_UNICODE);
                    }
                    $stmtInsert->execute([':id' => $rowId, ':data' => $json]);
                    $afterIds[$rowId] = true;
                }
            }

            // Delete rows removed by the callback
            foreach (array_keys($before) as $oldId) {
                if (!isset($afterIds[$oldId])) {
                    $this->pdo
                        ->prepare("DELETE FROM [{$table}] WHERE id = :id")
                        ->execute([':id' => $oldId]);
                }
            }

            $this->pdo->commit();
            return $result['result'];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // SQLITE-ONLY BONUS METHODS (not in JsonStore / StoreInterface)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Count records — O(1), much faster than count(load()).
     */
    public function count(string $file): int
    {
        $table = $this->tableFor($file);
        $this->ensureTable($table);
        return (int)$this->pdo
            ->query("SELECT COUNT(*) FROM [{$table}]")
            ->fetchColumn();
    }

    /**
     * Paginated load — for admin tabs showing large collections.
     * Returns ['records'=>[], 'total'=>int, 'pages'=>int]
     */
    public function paginate(string $file, int $page = 1, int $perPage = 50, string $orderBy = 'id DESC'): array
    {
        $table  = $this->tableFor($file);
        $this->ensureTable($table);
        $offset = ($page - 1) * $perPage;

        $total = (int)$this->pdo
            ->query("SELECT COUNT(*) FROM [{$table}]")
            ->fetchColumn();

        $rows = $this->pdo
            ->query("SELECT data FROM [{$table}] ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}")
            ->fetchAll(\PDO::FETCH_COLUMN);

        return [
            'records' => array_values(array_filter(array_map(fn($r) => json_decode($r, true), $rows))),
            'total'   => $total,
            'pages'   => (int)ceil($total / $perPage),
            'page'    => $page,
        ];
    }

    /**
     * Add a json_extract index on a JSON field.
     * Safe to call multiple times — IF NOT EXISTS.
     */
    public function addIndex(string $file, string $field): void
    {
        $table   = $this->tableFor($file);
        $idxName = "idx_{$table}_{$field}";
        try {
            $this->pdo->exec(
                "CREATE INDEX IF NOT EXISTS [{$idxName}] ON [{$table}] " .
                "(json_extract(data, '$.{$field}'))"
            );
        } catch (\Throwable $e) {
            // Older SQLite without json_extract support — silently skip
        }
    }

    /**
     * Run a raw SQL query (admin/reporting use only).
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Export all SQLite tables back to individual JSON files.
     * For backup, migration, or rollback.
     */
    public function exportAllToJson(string $targetDir = ''): array
    {
        if ($targetDir === '') $targetDir = $this->dir;
        if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);

        $tables = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $exported = [];
        foreach ($tables as $table) {
            $data = $this->load($table . '.json');
            $path = $targetDir . '/' . $table . '.json';
            file_put_contents(
                $path,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            $exported[$table . '.json'] = is_array($data) ? count($data) : 1;
        }
        return $exported;
    }

    /**
     * Database stats — for the admin maintenance tab.
     */
    public function stats(): array
    {
        $tables = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $result = [];
        foreach ($tables as $t) {
            $count = (int)$this->pdo->query("SELECT COUNT(*) FROM [{$t}]")->fetchColumn();
            $result[$t] = $count;
        }

        $dbPath = $this->dir . '/plugin.sqlite3';
        $result['__db_size_bytes'] = file_exists($dbPath) ? filesize($dbPath) : 0;
        $result['__db_size_human'] = $this->formatBytes($result['__db_size_bytes']);
        $result['__wal_mode']      = $this->pdo->query("PRAGMA journal_mode")->fetchColumn();
        $result['__sqlite_version']= $this->pdo->query("SELECT sqlite_version()")->fetchColumn();

        return $result;
    }

    // ══════════════════════════════════════════════════════════════════════
    // FIRST-BOOT MIGRATION
    // ══════════════════════════════════════════════════════════════════════

    public function firstBootMigration(): void
    {
        $log = ["=== DishNet SQLite Migration ===", "Date: " . date('Y-m-d H:i:s'), ""];
        $log[] = "Migrating JSON files from: {$this->dir}";
        $log[] = "";

        foreach (glob($this->dir . '/*.json') as $jsonPath) {
            $file = basename($jsonPath);
            if (str_ends_with($file, '.migrated') || str_ends_with($file, '.bak')) continue;
            // config.json belongs to uCRM, not to us. uCRM rewrites it every
            // time an admin saves the plugin settings form, so importing and
            // renaming it would silently discard the next settings change.
            // ucrm.json is likewise uCRM's, and holds the plugin app key.
            if ($file === 'config.json' || $file === 'ucrm.json') continue;

            try {
                $raw  = file_get_contents($jsonPath);
                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    $log[] = "SKIP  {$file} (not a JSON array/object)";
                    continue;
                }

                $table = $this->tableFor($file);
                $this->ensureTable($table);

                $this->pdo->beginTransaction();
                $stmt  = $this->pdo->prepare(
                    "INSERT INTO [{$table}] (id, data) VALUES (:id, :data)"
                );

                $count = 0;
                if ($this->isAssocArray($data) && !isset($data[0])) {
                    // Flat-object config
                    $stmt->execute([
                        ':id'   => self::FLAT_ID,
                        ':data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    ]);
                    $count = 1;
                } else {
                    foreach ($data as $record) {
                        $id = isset($record['id']) ? (int)$record['id'] : null;
                        $stmt->execute([
                            ':id'   => $id,
                            ':data' => json_encode($record, JSON_UNESCAPED_UNICODE),
                        ]);
                        $count++;
                    }
                }
                $this->pdo->commit();

                // Rename original to .migrated
                rename($jsonPath, $jsonPath . '.migrated');
                $log[] = "OK    {$file}: {$count} records";
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                $log[] = "FAIL  {$file}: " . $e->getMessage();
            }
        }

        $log[] = "";
        $log[] = "Creating performance indexes...";
        $this->ensureIndexes();
        $log[] = "Done.";

        file_put_contents(
            $this->dir . '/sqlite_migration.log',
            implode(PHP_EOL, $log) . PHP_EOL
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // INTERNAL HELPERS
    // ══════════════════════════════════════════════════════════════════════

    private function tableFor(string $file): string
    {
        $name = preg_replace('/\.json$/', '', basename($file));
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    }

    private function ensureTable(string $table): void
    {
        static $checked = [];
        if (isset($checked[$table])) return;

        // If the table already exists with proper columns (from SQL migrations),
        // do NOT create it as a blob table. Just mark as checked.
        if ($this->isProperTable($table)) {
            $checked[$table] = true;
            return;
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS [{$table}] (
                id   INTEGER PRIMARY KEY,
                data TEXT    NOT NULL
            )
        ");
        $checked[$table] = true;
    }

    /**
     * Detect if a table has proper columns (from SQL migrations) vs blob format (id+data).
     * Caches result per-table for performance.
     */
    private function isProperTable(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];

        try {
            $cols = $this->pdo->query("PRAGMA table_info([{$table}])")->fetchAll(\PDO::FETCH_COLUMN, 1);
            // A table is "proper" if it exists, has more than 2 columns, and does NOT have a 'data' column
            // (or has >3 columns even with 'data' — e.g. some tables might have a data field legitimately)
            $cache[$table] = !empty($cols) && count($cols) > 2 && !in_array('data', $cols, true);
        } catch (\Throwable $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }

    private function ensureIndexes(): void
    {
        // High-frequency lookup fields — all critical hot paths
        $indexes = [
            // Leads
            ['leads',                    'status'],
            ['leads',                    'retailer_id'],
            ['leads',                    'assigned_to'],
            // Retailers / auth
            ['retailers',                'email'],
            // KYC
            ['kyc_applications',         'retailer_id'],
            ['kyc_applications',         'crm_client_id'],
            ['kyc_applications',         'status'],
            // Passbook — hot: every payment does a findAll by retailer_id
            ['passbook',                 'retailer_id'],
            ['passbook',                 'application_id'],
            ['passbook',                 'idempotency_key'],
            ['passbook',                 'trx_no'],
            // Payment collections
            ['payment_collections',      'retailer_id'],
            ['payment_collections',      'created_at'],
            ['payment_collections',      'idempotency_key'],
            // Recharge requests
            ['wallet_recharge_requests', 'status'],
            ['wallet_recharge_requests', 'retailer_id'],
            // CRM queue
            ['crm_queue',                'status'],
            // LTE
            ['lte_subscribers',          'status'],
            ['lte_subscribers',          'agent_id'],
            ['lte_subscriptions',        'subscriber_id'],
            ['lte_subscriptions',        'status'],
            ['lte_renewals',             'subscriber_id'],
            ['lte_renewals',             'created_at'],
            ['lte_sims',                 'status'],
            ['lte_sims',                 'msisdn'],
            // Notifications / logs
            ['notification_log',         'sent_at'],
            ['activity_log',             'retailer_id'],
            ['activity_log',             'created_at'],
            ['login_attempts',           'email_hash'],
            // Support
            ['support_tickets',          'status'],
            ['support_tickets',          'retailer_id'],
        ];

        foreach ($indexes as [$table, $field]) {
            $this->addIndex($table . '.json', $field);
        }
    }

    private function isFlatTable(string $table): bool
    {
        return in_array($table, self::$FLAT_TABLES, true);
    }

    private function isBlobTable(string $table): bool
    {
        return in_array($table, self::$BLOB_TABLES, true);
    }

    private function isAssocArray($v): bool
    {
        return is_array($v)
            && !empty($v)
            && array_keys($v) !== range(0, count($v) - 1);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
