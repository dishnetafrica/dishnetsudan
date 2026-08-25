<?php
declare(strict_types=1);

require_once __DIR__ . '/StoreInterface.php';

/**
 * JsonStore — Simple flat-file database using JSON files.
 * All plugin data lives in /data/*.json
 *
 * v2.2 — Added flock() locking on all write operations.
 *         Prevents concurrent write corruption on live system.
 *         All method signatures unchanged. Drop-in replacement.
 */
class JsonStore implements StoreInterface
{
    private string $dir;

    public function __construct(string $dataDir)
    {
        $this->dir = rtrim($dataDir, '/');
        if (!is_dir($this->dir)) @mkdir($this->dir, 0755, true);
    }

    public function load(string $file): array
    {
        $path = $this->dir . '/' . $file;
        if (!file_exists($path)) return [];
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    public function save(string $file, array $data): void
    {
        $path = $this->dir . '/' . $file;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Atomic write: write to .tmp then rename (prevents partial writes)
        $tmp = $path . '.tmp.' . getmypid();
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $path);
    }

    public function append(string $file, array $record): array
    {
        return $this->withLock($file, function (array $records) use ($record) {
            $records[] = $record;
            return ['records' => $records, 'result' => $record];
        });
    }

    /** Find first matching record by key=value */
    public function findOne(string $file, string $key, $value): ?array
    {
        foreach ($this->load($file) as $r) {
            if (($r[$key] ?? null) == $value) return $r;
        }
        return null;
    }

    /** Find all matching records by key=value */
    public function findAll(string $file, string $key, $value): array
    {
        return array_values(array_filter(
            $this->load($file),
            function($r) use ($key, $value) { return ($r[$key] ?? null) == $value; }
        ));
    }

    /** Update first matching record */
    public function updateOne(string $file, string $key, $value, array $updates): bool
    {
        $found = false;
        $this->withLock($file, function (array $records) use ($key, $value, $updates, &$found) {
            foreach ($records as &$r) {
                if (($r[$key] ?? null) == $value) {
                    $r     = array_merge($r, $updates);
                    $found = true;
                    break;
                }
            }
            unset($r);
            return ['records' => $records, 'result' => $found];
        });
        return $found;
    }

    /**
     * Generate next auto-increment ID for a collection.
     * v2.2: Now reads inside shared lock for consistent snapshot.
     */
    public function nextId(string $file): int
    {
        $path = $this->dir . '/' . $file;
        if (!file_exists($path)) return 1;

        $fp = fopen($path, 'r');
        if (!$fp) return 1;

        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $records = json_decode($raw, true);
        if (!is_array($records) || empty($records)) return 1;

        return max(array_map(function($r) { return (int)($r['id'] ?? 0); }, $records)) + 1;
    }

    /**
     * Atomic append with ID — combines nextId + append under ONE lock.
     * Eliminates race condition where two requests get same ID.
     * NEW METHOD — does not break existing callers.
     */
    public function appendWithId(string $file, array $record): array
    {
        return $this->withLock($file, function (array $records) use ($record) {
            $maxId = empty($records)
                ? 0
                : max(array_map(function($r) { return (int)($r['id'] ?? 0); }, $records));
            $record['id'] = $maxId + 1;
            $records[] = $record;
            return ['records' => $records, 'result' => $record];
        });
    }

    public function path(string $file): string
    {
        return $this->dir . '/' . $file;
    }

    /**
     * Locked read-modify-write cycle.
     * Callback receives current records array, must return:
     *   ['records' => $modified, 'result' => $returnValue]
     */
    public function withLock(string $file, callable $fn)
    {
        $path = $this->dir . '/' . $file;

        $fp = fopen($path, file_exists($path) ? 'r+' : 'w+');
        if (!$fp) {
            throw new RuntimeException("Cannot open {$path} for locking");
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException("Cannot acquire lock on {$path}");
        }

        try {
            // Read while holding exclusive lock
            $raw = stream_get_contents($fp);
            $records = ($raw !== '' && $raw !== false)
                ? (json_decode($raw, true) ?? [])
                : [];

            // Execute modification
            $result = $fn($records);
            $modified = $result['records'];

            // Write back
            $json = json_encode($modified, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json);
            fflush($fp);

            return $result['result'];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
