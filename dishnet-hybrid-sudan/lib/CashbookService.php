<?php
declare(strict_types=1);

require_once __DIR__ . '/PaymentUuids.php';

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

/**
 * CashbookService v2.0 — DishNet Africa
 * Dual-project: DishNet Africa (dishnet) + DishNet 4G (4g)
 * Replaces Rupesh's Excel books.
 */
class CashbookService
{
    const VERSION     = '2.0';
    const META_FILE   = 'cashbook_meta_v2.json';
    const PROJECTS    = ['dishnet', '4g', 'bluecard'];
    const SR_PREFIX   = ['dishnet' => 'CB', '4g' => 'CB4G', 'bluecard' => 'CBBC'];

    const CATEGORIES_IN = [
        'Receipt','Exchange','Loan Received','Loan Return Received',
        'Interco In','Opening Balance','Refund','Build Africa',
        'Bank Transfer','Misc Expense',
        // v4.9.18: SSP Advance chain
        'SSP Return',
    ];
    const CATEGORIES_OUT = [
        'Salary','Transport Allowance','Food Allowance','Commission',
        'Staff Advance',
        'Site Power','Site Rent','Exchange','Tax','Travel & Field',
        'Local Purchase','Airtime','Loan Given','Interco Out',
        'Bank Transfer','Bonus','Employee Benefit','Capital Purchase',
        'Refund','Discount','Build Africa','Site Expense',
        'Bandwidth','Misc Expense','Customer Refund','Customer Commission',
        // v4.9.10: new structured categories from BookKeeper audit
        'Govt Fees','Legal Fees','Vehicle','Advertising',
        'Partner Remuneration','Renewal Charges',
        // v4.9.18: SSP Advance chain
        'SSP Advance',
    ];
    const VAL_STATUSES = [
        'voucher'=>'Voucher Issued','wr'=>'WR (Written Receipt)',
        'online'=>'Online / Receipt No.','jedco'=>'Jedco Receipt',
        'pending'=>'Pending','done'=>'Done','exchange'=>'Exchange','na'=>'N/A',
    ];

    // Known staff members (for autocomplete in entry modal)
    const STAFF = [
        'dishnet' => [
            'Bidal Victor Charles Mensona','Emmanuel','Ochitti','Joel','Geoffrey',
            'Diko Jesca Robinah Wani','Kamanda','Modi Mawa Francis','Meckline',
            'Atip','Amos','Atul','Francis','Joseph','Justus','Christine',
            'MarGreet 1','MarGreet 2','Marry','Manoj Bhai','House Keeper',
            'Yash','Bhavin','Charles','Shamshare',
        ],
        '4g' => [
            'BBC','Yogibhai','Aida','Joel','Sokiri','Chelsio',
        ],
        'bluecard' => [
            'Aida','BBC','Yogibhai',
        ],
    ];

    // v4.9.10: Partners for remuneration category
    const PARTNERS = [
        'Tom (Joseph Luate)','Bhavin (Madlani)','Nirmal (Samani)',
        'Paji (Shamshare Singh)','Rupesh',
    ];

    private $store;
    private $dataDir;

    public function __construct($store, string $dataDir)
    {
        $this->store   = $store;
        $this->dataDir = rtrim($dataDir, '/');
        $this->initTable();
    }

    /** Direct PDO — bypasses SqliteStore so ensureTable() never touches cb_ledger */
    /** Public PDO accessor for callers that need raw queries (e.g. reconciliation views) */
    public function getPdo(): \PDO { return $this->pdo(); }

    /**
     * Auto-close an exchange batch if SSP remaining is within tolerance.
     *
     * Called after expense approval. If the batch's total approved+pending
     * SSP expenses are within $tolerance SSP of the batch's SSP received,
     * the batch is auto-closed with a system note.
     *
     * Tolerance of 500 SSP (~$0.08) handles rounding noise from rate conversion.
     *
     * @param string $exchangeRef  The EXCH-xxx ref to check
     * @param float  $tolerance    SSP tolerance (default 500)
     * @return bool  true if auto-closed, false if still open
     */
    public function autoCloseIfComplete(string $exchangeRef, float $tolerance = 500.0): bool
    {
        if (!$exchangeRef) return false;
        try {
            // Ensure states table exists
            $this->pdo()->exec("CREATE TABLE IF NOT EXISTS ssp_batch_states (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                exchange_ref TEXT NOT NULL UNIQUE,
                state TEXT NOT NULL DEFAULT 'open',
                closed_by TEXT NOT NULL DEFAULT '',
                closed_at TEXT,
                note TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )");

            // Skip if already closed
            $existing = $this->pdo()->prepare(
                "SELECT state FROM ssp_batch_states WHERE exchange_ref=? LIMIT 1"
            );
            $existing->execute([$exchangeRef]);
            $row = $existing->fetch(\PDO::FETCH_ASSOC);
            if ($row && $row['state'] === 'closed') return true;

            // Get SSP received for this batch from cash_ins.json
            // We store it in the batch state table to avoid re-scanning JSON each time
            // Instead: query staff_expenses to get total spent, compare against cash_ins
            $spent = $this->pdo()->prepare(
                "SELECT COALESCE(SUM(ssp_amount),0) FROM staff_expenses
                 WHERE exchange_ref=? AND status IN ('pending','approved')"
            );
            $spent->execute([$exchangeRef]);
            $sspSpent = (float)$spent->fetchColumn();

            if ($sspSpent <= 0) return false;

            // Get SSP received from cash_ins (already loaded in SqliteStore backing)
            // We can't easily query it here without the store, so use a stored approach:
            // Store received amount when batch is first seen, or look it up via cb_ledger
            // cb_ledger has FIELD-{ref}-SSP row with ssp_amount = received
            $received = $this->pdo()->prepare(
                "SELECT ssp_amount FROM cb_ledger
                 WHERE validation_ref=? AND direction='in' AND currency='SSP'
                 LIMIT 1"
            );
            $received->execute(['FIELD-' . $exchangeRef . '-SSP']);
            $row = $received->fetch(\PDO::FETCH_ASSOC);
            if (!$row) return false; // can't verify without reference amount
            $sspReceived = (float)$row['ssp_amount'];
            if ($sspReceived <= 0) return false;

            $remaining = $sspReceived - $sspSpent;
            if ($remaining > $tolerance) return false; // still open

            // Auto-close
            $note = $remaining <= 0
                ? 'Auto-closed: all SSP fully spent on ' . count([]) . ' expenses'
                : 'Auto-closed: ' . number_format($remaining, 0) . ' SSP remainder within tolerance';
            $now  = date('Y-m-d H:i:s');
            $this->pdo()->prepare(
                "INSERT INTO ssp_batch_states (exchange_ref,state,closed_by,closed_at,note,created_at)
                 VALUES (?,?,?,?,?,?)
                 ON CONFLICT(exchange_ref) DO UPDATE SET
                   state=excluded.state, closed_by=excluded.closed_by,
                   closed_at=excluded.closed_at, note=excluded.note"
            )->execute([$exchangeRef, 'closed', 'system (auto)', $now, $note, $now]);

            return true;
        } catch (\Throwable $e) {
            return false; // non-fatal
        }
    }

    /**
     * Sweep all open batches in cash_ins.json and auto-close any that are complete.
     * Called from the daily cron.
     *
     * @param array $cashIns  Full cash_ins.json array
     * @return int  Number of batches auto-closed
     */
    public function autoCloseCompletedBatches(array $cashIns): int
    {
        $closed = 0;
        $seen   = [];
        foreach ($cashIns as $ci) {
            if (!in_array($ci['category'] ?? '', ['Exchange', 'Customer SSP Payment'])) continue;
            if (in_array($ci['status'] ?? 'approved', ['voided', 'rejected'], true)) continue;
            if ((float)($ci['ssp_amount'] ?? 0) <= 0) continue;
            $ref = (string)($ci['exchange_ref'] ?? '');
            if (!$ref || isset($seen[$ref])) continue;
            $seen[$ref] = true;
            if ($this->autoCloseIfComplete($ref)) $closed++;
        }
        return $closed;
    }

    private function pdo(): \PDO
    {
        return $this->store->getPdo();
    }

    /**
     * Execute SQL against cb_ledger via raw PDO.
     * Returns array of rows for SELECT; empty array for INSERT/UPDATE/DELETE.
     */
    private function dbq(string $sql, array $params = []): array
    {
        $maxRetries = 5;
        $lastEx     = null;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $stmt = $this->pdo()->prepare($sql);
                $stmt->execute($params);

                // Only fetch for SELECT-type queries
                $verb = strtoupper(substr(ltrim($sql), 0, 6));
                if (in_array($verb, ['SELECT', 'PRAGMA'])) {
                    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
                }
                return [];
            } catch (\Throwable $e) {
                $lastEx = $e;
                $msg = strtolower($e->getMessage());
                // Retry on SQLITE_BUSY / database locked — wait then try again
                if (strpos($msg, 'locked') !== false || strpos($msg, 'busy') !== false) {
                    usleep(($attempt + 1) * 100000); // 100ms, 200ms, 300ms, 400ms, 500ms
                    continue;
                }
                // Not a lock error — fail immediately
                break;
            }
        }

        // All retries exhausted or non-lock error — log and throw
        $logFile = $this->dataDir . '/cashbook_errors.log';
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $lastEx->getMessage()
            . " (after {$attempt} attempts)"
            . "\n  SQL: " . substr($sql, 0, 300)
            . "\n  Params: " . json_encode(array_map(fn($p) => is_string($p) ? substr($p, 0, 50) : $p, $params))
            . "\n  Trace: " . $lastEx->getFile() . ':' . $lastEx->getLine()
            . "\n\n", FILE_APPEND);
        throw $lastEx;
    }

    private function initTable(): void
    {
        // Step 1: ensure table exists with only id (safest possible baseline)
        $this->dbq("CREATE TABLE IF NOT EXISTS cb_ledger (
            id INTEGER PRIMARY KEY AUTOINCREMENT
        )");

        // Step 2: read what columns actually exist right now
        $cols = array_column(
            $this->pdo()->query("PRAGMA table_info(cb_ledger)")->fetchAll(\PDO::FETCH_ASSOC),
            'name'
        );

        // Step 3: add every missing column — covers ALL versions ever shipped
        $allCols = [
            'sr'               => "TEXT NOT NULL DEFAULT ''",
            'date'             => "TEXT NOT NULL DEFAULT ''",
            'direction'        => "TEXT NOT NULL DEFAULT 'in'",
            'amount'           => "REAL NOT NULL DEFAULT 0",
            'currency'         => "TEXT NOT NULL DEFAULT 'USD'",
            'description'      => "TEXT NOT NULL DEFAULT ''",
            'validation_ref'   => "TEXT NOT NULL DEFAULT ''",
            'validation_status'=> "TEXT NOT NULL DEFAULT 'na'",
            'status'           => "TEXT NOT NULL DEFAULT 'approved'",
            'created_at'       => "TEXT NOT NULL DEFAULT ''",
            'updated_at'       => "TEXT NOT NULL DEFAULT ''",
            'project'          => "TEXT NOT NULL DEFAULT 'dishnet'",
            'category'         => "TEXT NOT NULL DEFAULT 'Misc Expense'",
            'category_raw'     => "TEXT NOT NULL DEFAULT ''",
            'person'           => "TEXT NOT NULL DEFAULT ''",
            'approved_by'      => "TEXT NOT NULL DEFAULT ''",
            'reject_reason'    => "TEXT NOT NULL DEFAULT ''",
            'crm_payment_id'   => "INTEGER NOT NULL DEFAULT 0",
            'crm_client_id'    => "INTEGER NOT NULL DEFAULT 0",
            'source'           => "TEXT NOT NULL DEFAULT 'manual'",
            'ssp_amount'       => "REAL",
            'ssp_rate'         => "REAL",
            'payroll_ref'      => "TEXT DEFAULT ''",  // v4.11.0: HRM payroll reference
            'cash_with'        => "TEXT DEFAULT ''",   // v4.11.3: who physically holds this cash
            'cash_with_id'     => "INTEGER DEFAULT 0", // v4.11.3: retailer ID of cash holder
        ];
        foreach ($allCols as $col => $def) {
            if (!in_array($col, $cols)) {
                $this->dbq("ALTER TABLE cb_ledger ADD COLUMN {$col} {$def}");
                $cols[] = $col; // keep $cols in sync
            }
        }

        // Step 4: create indexes — all columns now guaranteed present
        $this->dbq("CREATE INDEX IF NOT EXISTS idx_cb_project   ON cb_ledger(project)");
        $this->dbq("CREATE INDEX IF NOT EXISTS idx_cb_date      ON cb_ledger(date)");
        $this->dbq("CREATE INDEX IF NOT EXISTS idx_cb_valstatus ON cb_ledger(validation_status)");
        $this->dbq("CREATE INDEX IF NOT EXISTS idx_cb_category  ON cb_ledger(category)");
        $this->dbq("CREATE INDEX IF NOT EXISTS idx_cb_crm_pay   ON cb_ledger(crm_payment_id)");
        $this->dbq("CREATE INDEX IF NOT EXISTS idx_cb_source    ON cb_ledger(source)");

        // Step 5: Fix — drop UNIQUE constraint on validation_ref.
        // Migration 007 created it to prevent duplicate CRM payment refs, but
        // Exchange pairs legitimately share the same EXCH-xxx ref (USD + SSP),
        // and CRM webhook + PWA auto-post can race on the same PAY-xxx ref.
        // v4.9.19: More aggressive — check if unique index persists and rebuild if needed.
        try {
            $uniqueIdx = $this->pdo()->query(
                "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='cb_ledger' AND sql LIKE '%UNIQUE%'"
            )->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($uniqueIdx as $idxName) {
                $this->pdo()->exec("DROP INDEX IF EXISTS " . $idxName);
            }
            $this->pdo()->exec("CREATE INDEX IF NOT EXISTS idx_cb_valref ON cb_ledger(validation_ref)");
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    // ── META ──────────────────────────────────────────────────────────────

    public function getMeta(): array
    {
        $path = $this->dataDir . '/' . self::META_FILE;
        if (!file_exists($path)) return [];
        return json_decode(file_get_contents($path), true) ?: [];
    }
    private function saveMeta(array $meta): void
    {
        file_put_contents($this->dataDir . '/' . self::META_FILE,
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    public function setSeeded(): void
    {
        $meta = $this->getMeta();
        $meta['seeded_at']    = date('Y-m-d H:i:s');
        $meta['seeded_count'] = $this->countEntries();
        $meta['version']      = self::VERSION;
        $this->saveMeta($meta);
    }
    public function setExchangeRate(float $rate, string $by = ''): void
    {
        if ($rate <= 0) return;
        $meta = $this->getMeta();
        $meta['exchange_rate']   = $rate;
        $meta['rate_updated_at'] = date('Y-m-d H:i:s');
        $meta['rate_updated_by'] = $by;
        $this->saveMeta($meta);
        // Log to history — one entry per day (upsert)
        try {
            $this->pdo()->exec("CREATE TABLE IF NOT EXISTS ssp_rate_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rate REAL NOT NULL,
                effective_date TEXT NOT NULL,
                set_by TEXT NOT NULL DEFAULT \'\',
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )");
            $today = date('Y-m-d');
            $exists = $this->dbq("SELECT id FROM ssp_rate_history WHERE effective_date=? LIMIT 1", [$today]);
            if (!empty($exists[0]['id'])) {
                $this->dbq("UPDATE ssp_rate_history SET rate=?, set_by=?, created_at=datetime(\'now\') WHERE effective_date=?", [$rate, $by, $today]);
            } else {
                $this->dbq("INSERT INTO ssp_rate_history (rate, effective_date, set_by) VALUES (?,?,?)", [$rate, $today, $by]);
            }
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    /** Rate that was active on a given date — walks back through history */
    public function getRateForDate(string $date): float
    {
        try {
            $row = $this->dbq(
                "SELECT rate FROM ssp_rate_history WHERE effective_date<=? ORDER BY effective_date DESC LIMIT 1",
                [$date]
            );
            if (!empty($row[0]['rate'])) return (float)$row[0]['rate'];
        } catch (\Throwable $e) {}
        return $this->getExchangeRate();
    }

    /** Last N days of rate changes for display */
    public function getRateHistory(int $days = 30): array
    {
        try {
            return $this->dbq(
                "SELECT rate, effective_date, set_by, created_at FROM ssp_rate_history ORDER BY effective_date DESC LIMIT ?",
                [$days]
            );
        } catch (\Throwable $e) { return []; }
    }

    public function getExchangeRate(): float
    {
        return (float)($this->getMeta()['exchange_rate'] ?? 5180.0);
    }

    /**
     * Build rate context from actual cash_ins.json exchange history.
     *
     * Unlike getExchangeRate() (which returns the admin-set system rate),
     * this returns what staff *actually got* at the money changer — which
     * varies per person and per day. Used to show the "last market rate"
     * banner on the exchange form and SSP hero card.
     *
     * @param array $cashIns  Full cash_ins.json array (caller loads it)
     * @return array {
     *   last_rate, last_usd, last_ssp, last_by, last_at, last_minutes_ago,
     *   last_direction, median_7day, min_7day, max_7day, count_7day,
     *   trend ('up'|'down'|'flat'), system_rate, recent (last 7 entries)
     * }
     */
    public function getLastExchangeContext(array $cashIns): array
    {
        $systemRate = $this->getExchangeRate();
        $empty = [
            'last_rate'        => 0,
            'last_usd'         => 0,
            'last_ssp'         => 0,
            'last_by'          => '',
            'last_at'          => '',
            'last_minutes_ago' => -1,
            'last_direction'   => '',
            'median_7day'      => 0,
            'min_7day'         => 0,
            'max_7day'         => 0,
            'count_7day'       => 0,
            'trend'            => 'flat',
            'system_rate'      => $systemRate,
            'recent'           => [],
        ];

        // Filter: Exchange entries only, not voided/rejected, with a rate
        $exchanges = [];
        foreach ($cashIns as $ci) {
            if (!in_array($ci['category'] ?? '', ['Exchange', 'Customer SSP Payment'])) continue;
            if (in_array($ci['status'] ?? 'approved', ['voided','rejected'], true)) continue;
            $rate = (float)($ci['rate'] ?? 0);
            if ($rate < 100) continue; // sanity: SSP/USD rate is always in the thousands
            $exchanges[] = $ci;
        }

        if (empty($exchanges)) return $empty;

        // Sort newest first by created_at
        usort($exchanges, function($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        // Last exchange (most recent)
        $last    = $exchanges[0];
        $lastAt  = $last['created_at'] ?? '';
        $lastRate = (float)($last['rate'] ?? 0);
        $lastUsd  = (float)($last['usd_given'] ?? $last['amount'] ?? 0);
        $lastSsp  = (float)($last['ssp_amount'] ?? 0);
        $lastBy   = (string)($last['collector_name'] ?? '');
        $lastDir  = ($lastSsp > 0 && $lastUsd > 0) ? 'usd_to_ssp'
                  : (($lastUsd > 0 && $lastSsp === 0.0) ? 'usd_to_ssp' : 'ssp_to_usd');

        // Minutes ago
        $minsAgo = -1;
        if ($lastAt) {
            $diff = time() - strtotime($lastAt);
            $minsAgo = max(0, (int)round($diff / 60));
        }

        // 7-day rates for range and median
        $cutoff7 = date('Y-m-d H:i:s', strtotime('-7 days'));
        $rates7  = [];
        $recent  = [];
        foreach ($exchanges as $ci) {
            if (($ci['created_at'] ?? '') >= $cutoff7) {
                $rates7[] = (float)($ci['rate'] ?? 0);
                if (count($recent) < 7) {
                    $recent[] = [
                        'rate' => (float)($ci['rate'] ?? 0),
                        'by'   => $ci['collector_name'] ?? '',
                        'at'   => $ci['created_at'] ?? '',
                        'usd'  => (float)($ci['usd_given'] ?? $ci['amount'] ?? 0),
                        'ssp'  => (float)($ci['ssp_amount'] ?? 0),
                    ];
                }
            }
        }

        $median7 = 0.0;
        $min7    = 0.0;
        $max7    = 0.0;
        if (!empty($rates7)) {
            sort($rates7);
            $n      = count($rates7);
            $median7 = $n % 2 === 0
                ? ($rates7[$n/2-1] + $rates7[$n/2]) / 2
                : $rates7[(int)($n/2)];
            $min7   = min($rates7);
            $max7   = max($rates7);
        }

        // Trend: last rate vs 7-day median
        $trend = 'flat';
        if ($median7 > 0) {
            $diff = $lastRate - $median7;
            if ($diff > 50)       $trend = 'up';   // getting more SSP per $ than median
            elseif ($diff < -50)  $trend = 'down'; // getting less SSP per $
        }

        return [
            'last_rate'        => $lastRate,
            'last_usd'         => $lastUsd,
            'last_ssp'         => $lastSsp,
            'last_by'          => $lastBy,
            'last_at'          => $lastAt,
            'last_minutes_ago' => $minsAgo,
            'last_direction'   => $lastDir,
            'median_7day'      => round($median7),
            'min_7day'         => $min7,
            'max_7day'         => $max7,
            'count_7day'       => count($rates7),
            'trend'            => $trend,
            'system_rate'      => $systemRate,
            'recent'           => $recent,
        ];
    }

    /**
     * Get open exchange batches for a staff member — for the "funded by" dropdown.
     *
     * An "open" batch is a cash_ins.json Exchange entry where:
     *  - category = 'Exchange', not voided
     *  - ssp_amount > 0 (USD→SSP direction)
     *  - created within $days days
     *  - SSP remaining (ssp_received - linked approved expenses) > 0
     *
     * Returns batches sorted newest first, each with:
     *   exchange_ref, rate, ssp_received, ssp_spent, ssp_remaining,
     *   usd_given, created_at, label (for dropdown display)
     *
     * @param array $cashIns     Full cash_ins.json array
     * @param int   $staffId     Staff member to filter by
     * @param int   $days        How many days back to look (default 30)
     */
    /**
     * Deduct SSP from open exchange batches (FIFO) when a reverse exchange
     * (SSP→USD) happens. Links the deduction to the correct original batch
     * so batch reconciliation reflects the real remaining SSP.
     *
     * Writes a staff_expenses row for each batch consumed (so
     * getAllExchangeBatchSummary() counts it correctly), then calls
     * autoCloseIfComplete() on each touched batch.
     *
     * @param array  $cashIns   Full cash_ins.json array
     * @param int    $staffId   Staff who did the reverse exchange
     * @param string $staffName Staff name
     * @param float  $sspGiven  Total SSP given in the reverse exchange
     * @param float  $rate      Exchange rate used
     * @param string $revRef    The reverse exchange ref (EXCH-...-REV)
     * @param string $desc      Description for the deduction rows
     * @param string $date      Date of the reverse exchange (Y-m-d)
     * @return int   Number of batches touched
     */
    public function deductFromBatchesFIFO(
        array $cashIns, int $staffId, string $staffName,
        float $sspGiven, float $rate, string $revRef,
        string $desc = '', string $date = ''
    ): int {
        if ($sspGiven <= 0) return 0;

        $date    = $date ?: date('Y-m-d');
        $desc    = $desc ?: ('SSP reverse exchange @ ' . number_format($rate, 0));
        $touched = 0;
        $remaining = $sspGiven;

        // Get open batches oldest-first (FIFO)
        $batches = array_reverse($this->getOpenExchangeBatches($cashIns, $staffId, 90));

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;

            $ref          = $batch['exchange_ref'];
            $batchRemains = (float)($batch['ssp_remaining'] ?? 0);
            if ($batchRemains <= 0) continue;

            $deduct = min($remaining, $batchRemains);
            $remaining -= $deduct;

            // Write to staff_expenses so getAllExchangeBatchSummary counts it
            $idemKey = 'REVEXC-' . $revRef . '-' . $ref;
            try {
                $exists = $this->pdo()->prepare(
                    "SELECT id FROM staff_expenses WHERE description LIKE ? LIMIT 1"
                );
                $exists->execute(['%' . $idemKey . '%']);
                if ($exists->fetchColumn()) continue; // already recorded

                $this->pdo()->prepare(
                    "INSERT INTO staff_expenses
                     (staff_id, staff_name, currency, amount, ssp_amount, exchange_ref,
                      exchange_rate, category, description, expense_type,
                      status, approved_by, approved_at, expense_date, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $staffId, $staffName,
                    'SSP', 0, round($deduct, 0),
                    $ref,           // link to ORIGINAL batch
                    $rate,
                    'Exchange',
                    $desc . ' [' . $idemKey . ']',
                    'reverse_exchange',
                    'approved', $staffName, date('Y-m-d H:i:s'),
                    $date, date('Y-m-d H:i:s'),
                ]);
                $touched++;
            } catch (\Throwable $e) { /* non-fatal */ }

            // Try to auto-close this batch
            try { $this->autoCloseIfComplete($ref); } catch (\Throwable $e) {}
        }

        return $touched;
    }

        public function getOpenExchangeBatches(array $cashIns, int $staffId, int $days = 30): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $batches = [];

        foreach ($cashIns as $ci) {
            if (!in_array($ci['category'] ?? '', ['Exchange', 'Customer SSP Payment'])) continue;
            if (in_array($ci['status'] ?? 'approved', ['voided', 'rejected'], true)) continue;
            if ((int)($ci['collector_id'] ?? 0) !== $staffId) continue;
            if ((float)($ci['ssp_amount'] ?? 0) <= 0) continue;
            if (($ci['created_at'] ?? '') < $cutoff) continue;

            $ref = (string)($ci['exchange_ref'] ?? '');
            if (!$ref) continue;

            $batches[$ref] = [
                'exchange_ref' => $ref,
                'rate'         => (float)($ci['rate'] ?? 0),
                'ssp_received' => (float)($ci['ssp_amount'] ?? 0),
                'usd_given'    => (float)($ci['usd_given'] ?? $ci['amount'] ?? 0),
                'created_at'   => $ci['created_at'] ?? '',
                'ssp_spent'    => 0.0,
            ];
        }

        if (empty($batches)) return [];

        // Sum SSP spent per exchange_ref from staff_expenses (approved)
        $refs = array_keys($batches);
        $placeholders = implode(',', array_fill(0, count($refs), '?'));
        try {
            $rows = $this->pdo()->prepare(
                "SELECT exchange_ref, COALESCE(SUM(ssp_amount),0) as spent
                 FROM staff_expenses
                 WHERE exchange_ref IN ({$placeholders})
                   AND status IN ('pending','approved')
                 GROUP BY exchange_ref"
            );
            $rows->execute($refs);
            foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if (isset($batches[$r['exchange_ref']])) {
                    $batches[$r['exchange_ref']]['ssp_spent'] = (float)$r['spent'];
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // Compute remaining and build label, filter out fully spent
        $result = [];
        foreach ($batches as $b) {
            $remaining = max(0, $b['ssp_received'] - $b['ssp_spent']);
            if ($remaining < 1) continue; // fully spent — don't show

            $date    = substr($b['created_at'], 0, 10);
            $time    = substr($b['created_at'], 11, 5);
            $b['ssp_remaining'] = $remaining;
            $b['label'] = sprintf(
                '$%s @ %s  →  %s SSP left  (%s %s)',
                number_format($b['usd_given'], 0),
                number_format($b['rate'], 0),
                number_format($remaining, 0),
                $date, $time
            );
            $result[] = $b;
        }

        // Sort newest first
        usort($result, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $result;
    }

    /**
     * One-time backfill: link existing SSP expenses to exchange batches.
     *
     * Runs automatically the first time getAllExchangeBatchSummary() is called
     * after upload. Guarded by a meta flag so it never runs twice.
     *
     * Logic: for each SSP expense with empty exchange_ref, find the oldest
     * open exchange batch for that staff member created on or before the
     * expense date (FIFO — same as live submitExpense logic).
     *
     * @param array $cashIns  Full cash_ins.json array
     * @return int  Number of expenses linked
     */
    public function backfillExchangeRefs(array $cashIns): int
    {
        // Guard: only run once
        $meta = $this->getMeta();
        if (!empty($meta['exchange_ref_backfill_done'])) return 0;

        // Build per-staff exchange batches sorted oldest-first (FIFO)
        $staffBatches = []; // staffId => [batches sorted oldest first]
        foreach ($cashIns as $ci) {
            if (!in_array($ci['category'] ?? '', ['Exchange', 'Customer SSP Payment'])) continue;
            if (in_array($ci['status'] ?? 'approved', ['voided','rejected'], true)) continue;
            if ((float)($ci['ssp_amount'] ?? 0) <= 0) continue;
            $ref     = (string)($ci['exchange_ref'] ?? '');
            $staffId = (int)($ci['collector_id'] ?? 0);
            $rate    = (float)($ci['rate'] ?? 0);
            if (!$ref || !$staffId || $rate <= 0) continue;
            $staffBatches[$staffId][] = [
                'ref'        => $ref,
                'rate'       => $rate,
                'created_at' => $ci['created_at'] ?? '',
                'ssp_amount' => (float)$ci['ssp_amount'],
            ];
        }
        foreach ($staffBatches as $sid => $batches) {
            usort($batches, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
            $staffBatches[$sid] = $batches;
        }

        if (empty($staffBatches)) {
            $meta['exchange_ref_backfill_done'] = date('Y-m-d H:i:s');
            $this->saveMeta($meta);
            return 0;
        }

        // Find all SSP expenses with empty exchange_ref
        try {
            $rows = $this->pdo()->prepare(
                "SELECT id, staff_id, ssp_amount, expense_date, created_at
                 FROM staff_expenses
                 WHERE currency='SSP'
                   AND (exchange_ref IS NULL OR exchange_ref='')
                   AND status IN ('pending','approved')
                 ORDER BY staff_id, expense_date ASC, id ASC"
            );
            $rows->execute();
            $expenses = $rows->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Column may not exist yet (migration 047 not yet applied)
            $meta['exchange_ref_backfill_done'] = date('Y-m-d H:i:s');
            $this->saveMeta($meta);
            return 0;
        }

        $linked = 0;
        // Track running SSP spent per batch to implement FIFO properly
        $batchSpent = []; // ref => total SSP already assigned in this backfill

        foreach ($expenses as $exp) {
            $staffId    = (int)$exp['staff_id'];
            $sspNeeded  = (float)($exp['ssp_amount'] ?? 0);
            $expDate    = substr($exp['expense_date'] ?? $exp['created_at'] ?? '', 0, 10);
            if (!isset($staffBatches[$staffId]) || $sspNeeded <= 0) continue;

            // Find oldest batch for this staff where:
            // 1. batch created_at <= expense date
            // 2. SSP remaining (received - already spent - already assigned) > 0
            foreach ($staffBatches[$staffId] as $batch) {
                $batchDate = substr($batch['created_at'], 0, 10);
                if ($batchDate > $expDate) continue; // batch created after expense — skip

                // Compute how much SSP this batch has left
                $ref      = $batch['ref'];
                $received = $batch['ssp_amount'];
                if (!isset($batchSpent[$ref])) {
                    // Get already-linked expenses from DB for this batch
                    try {
                        $s = $this->pdo()->prepare(
                            "SELECT COALESCE(SUM(ssp_amount),0) FROM staff_expenses
                             WHERE exchange_ref=? AND status IN ('pending','approved')"
                        );
                        $s->execute([$ref]);
                        $batchSpent[$ref] = (float)$s->fetchColumn();
                    } catch (\Throwable $e) { $batchSpent[$ref] = 0.0; }
                }

                $remaining = $received - ($batchSpent[$ref] ?? 0);
                if ($remaining < 1) continue; // batch fully spent — try next

                // Link this expense to this batch
                try {
                    $this->pdo()->prepare(
                        "UPDATE staff_expenses SET exchange_ref=?, exchange_rate=? WHERE id=?"
                    )->execute([$ref, $batch['rate'], (int)$exp['id']]);
                    $batchSpent[$ref] = ($batchSpent[$ref] ?? 0) + $sspNeeded;
                    $linked++;
                } catch (\Throwable $e) { /* skip */ }
                break; // linked — move to next expense
            }
        }

        // Mark done
        $meta['exchange_ref_backfill_done'] = date('Y-m-d H:i:s');
        $meta['exchange_ref_backfill_linked'] = $linked;
        $this->saveMeta($meta);
        return $linked;
    }

    /**
     * Company-wide exchange batch reconciliation summary.
     *
     * For the SSP Overview → Exchange Reconciliation tab.
     * Returns ALL exchange batches (all staff) for the given period,
     * each with: USD given, SSP received, SSP spent (linked expenses),
     * SSP remaining, utilisation %, and a list of linked expenses.
     *
     * @param array $cashIns   Full cash_ins.json array
     * @param int   $days      How many days back to look (default 60)
     * @param bool  $includesClosed  If false (default), skip closed/verified batches
     */
    public function getAllExchangeBatchSummary(array $cashIns, int $days = 60, bool $includeClosed = false): array
    {
        // One-time backfill: link existing SSP expenses that pre-date the plugin upload.
        // Runs automatically on first call, never again (meta flag guard inside).
        $this->backfillExchangeRefs($cashIns);

        $cutoff  = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $batches = [];

        foreach ($cashIns as $ci) {
            if (!in_array($ci['category'] ?? '', ['Exchange', 'Customer SSP Payment'])) continue;
            if (in_array($ci['status'] ?? 'approved', ['voided', 'rejected'], true)) continue;
            if ((float)($ci['ssp_amount'] ?? 0) <= 0) continue;
            if (($ci['created_at'] ?? '') < $cutoff) continue;
            $ref = (string)($ci['exchange_ref'] ?? '');
            if (!$ref) continue;

            $batches[$ref] = [
                'exchange_ref'  => $ref,
                'staff_id'      => (int)($ci['collector_id'] ?? 0),
                'staff_name'    => (string)($ci['collector_name'] ?? ''),
                'rate'          => (float)($ci['rate'] ?? 0),
                'usd_given'     => (float)($ci['usd_given'] ?? $ci['amount'] ?? 0),
                'ssp_received'  => (float)($ci['ssp_amount'] ?? 0),
                'created_at'    => $ci['created_at'] ?? '',
                'ssp_spent'     => 0.0,
                'expenses'      => [],
                'is_closed'     => false,
                'closed_by'     => '',
                'closed_at'     => '',
            ];
        }

        if (empty($batches)) return [];

        // Load closed batch states
        try {
            $this->pdo()->exec("CREATE TABLE IF NOT EXISTS ssp_batch_states (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                exchange_ref TEXT NOT NULL UNIQUE,
                state TEXT NOT NULL DEFAULT 'open',
                closed_by TEXT NOT NULL DEFAULT '',
                closed_at TEXT,
                note TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )");
            $refs = array_keys($batches);
            $ph   = implode(',', array_fill(0, count($refs), '?'));
            $closed = $this->pdo()->prepare(
                "SELECT exchange_ref, closed_by, closed_at FROM ssp_batch_states
                 WHERE exchange_ref IN ({$ph}) AND state='closed'"
            );
            $closed->execute($refs);
            foreach ($closed->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                if (isset($batches[$c['exchange_ref']])) {
                    $batches[$c['exchange_ref']]['is_closed']  = true;
                    $batches[$c['exchange_ref']]['closed_by']  = $c['closed_by'];
                    $batches[$c['exchange_ref']]['closed_at']  = $c['closed_at'] ?? '';
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // Filter closed if not requested
        if (!$includeClosed) {
            $batches = array_filter($batches, fn($b) => !$b['is_closed']);
        }
        if (empty($batches)) return [];

        // Pull all linked expenses from staff_expenses
        $refs         = array_keys($batches);
        $placeholders = implode(',', array_fill(0, count($refs), '?'));
        try {
            $rows = $this->pdo()->prepare(
                "SELECT id, expense_no, exchange_ref, staff_name, category,
                        ssp_amount, amount, status, expense_date, description
                 FROM staff_expenses
                 WHERE exchange_ref IN ({$placeholders})
                   AND status IN ('pending','approved')
                 ORDER BY expense_date ASC, id ASC"
            );
            $rows->execute($refs);
            foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $ref = $r['exchange_ref'];
                if (!isset($batches[$ref])) continue;
                $ssp = (float)($r['ssp_amount'] ?? 0);
                $batches[$ref]['ssp_spent']   += $ssp;
                $batches[$ref]['expenses'][]   = $r;
            }
        } catch (\Throwable $e) { /* non-fatal */ }

        // Compute remaining + utilisation, build result
        $result = [];
        foreach ($batches as $b) {
            $remaining  = max(0, $b['ssp_received'] - $b['ssp_spent']);
            $util       = $b['ssp_received'] > 0
                ? min(100, round($b['ssp_spent'] / $b['ssp_received'] * 100, 1))
                : 0;
            $b['ssp_remaining']   = $remaining;
            $b['utilisation_pct'] = $util;
            $b['days_open'] = $b['created_at']
                ? (int)floor((time() - strtotime($b['created_at'])) / 86400)
                : 0;
            $result[] = $b;
        }

        usort($result, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $result;
    }

    // ── SR NUMBER ─────────────────────────────────────────────────────────

    private function nextSr(string $project): string
    {
        $prefix = self::SR_PREFIX[$project] ?? 'UDAL';
        $rows = $this->dbq(
            "SELECT sr FROM cb_ledger WHERE project=? AND sr LIKE ? ORDER BY id DESC LIMIT 1",
            [$project, $prefix.'-%']
        );
        if (!empty($rows)) {
            $last = $rows[0]['sr'];
            if (preg_match('/-(\d+)([A-Z]?)$/', $last, $m)) {
                return $prefix . '-' . ((int)$m[1] + 1);
            }
        }
        $cnt = $this->countEntries($project);
        return $prefix . '-' . ($cnt + 1);
    }

    /** Dedicated SR series for SSP entries — SSP-0001, SSP-0002, ... */
    private function nextSrSSP(): string
    {
        $rows = $this->dbq(
            "SELECT sr FROM cb_ledger WHERE sr LIKE 'SSP-%' ORDER BY id DESC LIMIT 1"
        );
        if (!empty($rows)) {
            $last = $rows[0]['sr'];
            if (preg_match('/SSP-(\d+)$/', $last, $m)) {
                return 'SSP-' . str_pad((string)((int)$m[1] + 1), 4, '0', STR_PAD_LEFT);
            }
        }
        $cnt = (int)($this->dbq("SELECT COUNT(*) as n FROM cb_ledger WHERE currency='SSP'")[0]['n'] ?? 0);
        return 'SSP-' . str_pad((string)($cnt + 1), 4, '0', STR_PAD_LEFT);
    }

    // ── ENTRY CRUD ────────────────────────────────────────────────────────

    public function addEntry(array $data, array $actor = [], bool $autoApprove = true): array
    {
        $project   = in_array($data['project'] ?? '', self::PROJECTS) ? $data['project'] : 'dishnet';
        $dir       = in_array($data['direction'] ?? '', ['in','out']) ? $data['direction'] : 'in';
        $amount    = round((float)($data['amount'] ?? 0), 2);
        $currency  = strtoupper($data['currency'] ?? 'USD');
        $cat       = trim($data['category'] ?? 'Receipt');
        $valStatus = array_key_exists($data['validation_status'] ?? '', self::VAL_STATUSES)
                     ? $data['validation_status'] : 'na';

        if ($amount <= 0) return ['ok'=>false,'error'=>'Amount must be > 0'];

        $sr  = $data['sr'] ?? $this->nextSr($project);
        $now = date('Y-m-d H:i:s');

        $this->dbq(
            "INSERT INTO cb_ledger
             (sr,project,date,direction,amount,currency,ssp_amount,ssp_rate,category,category_raw,person,
              description,validation_ref,validation_status,status,approved_by,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $sr, $project, $data['date'] ?? date('Y-m-d'),
                $dir, $amount, $currency,
                isset($data['ssp_amount']) ? (float)$data['ssp_amount'] : null,
                isset($data['ssp_rate'])   ? (float)$data['ssp_rate']   : null,
                $cat,
                $data['category_raw'] ?? $cat,
                trim($data['person'] ?? ''),
                trim($data['description'] ?? ''),
                trim($data['validation_ref'] ?? ''),
                $valStatus,
                $autoApprove ? 'approved' : 'pending_approval',
                $autoApprove ? ($actor['name'] ?? 'Rupesh') : '',
                $data['created_at'] ?? $now, $now,
            ]
        );
        return ['ok'=>true, 'id'=>$this->pdo()->lastInsertId(), 'sr'=>$sr];
    }

    public function addEntryRaw(array $data): string
    {
        $now     = date('Y-m-d H:i:s');
        $project = $data['project'] ?? 'dishnet';
        // SSP entries get their own SSP-XXXX series; USD entries use project prefix
        $isSspEntry = (($data['currency'] ?? 'USD') === 'SSP');
        $sr      = (($data['sr'] ?? '') !== '') ? $data['sr'] : ($isSspEntry ? $this->nextSrSSP() : $this->nextSr($project));
        $this->dbq(
            "INSERT INTO cb_ledger
             (sr,project,date,direction,amount,currency,ssp_amount,ssp_rate,
              category,category_raw,person,
              description,validation_ref,validation_status,status,approved_by,
              crm_payment_id,crm_client_id,source,payroll_ref,cash_with,cash_with_id,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $sr, $project,
                $data['date'] ?? date('Y-m-d'), $data['direction'] ?? 'in',
                (float)($data['amount'] ?? 0), $data['currency'] ?? 'USD',
                isset($data['ssp_amount']) ? (float)$data['ssp_amount'] : null,
                isset($data['ssp_rate'])   ? (float)$data['ssp_rate']   : null,
                $data['category'] ?? 'Misc Expense', $data['category_raw'] ?? '',
                $data['person'] ?? '', $data['description'] ?? '',
                $data['validation_ref'] ?? '', $data['validation_status'] ?? 'na',
                $data['status'] ?? 'approved', $data['approved_by'] ?? 'CRM',
                (int)($data['crm_payment_id'] ?? 0),
                (int)($data['crm_client_id']  ?? 0),
                $data['source'] ?? 'manual',
                $data['payroll_ref'] ?? '',
                $data['cash_with'] ?? '',
                (int)($data['cash_with_id'] ?? 0),
                $data['created_at'] ?? $now, $now,
            ]
        );
        return $sr;
    }

    public function updateEntry(int $id, array $data, array $admin): array
    {
        $allowed = ['description','validation_ref','validation_status','category','person','amount','date','direction'];
        $sets = []; $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) { $sets[] = "$f=?"; $params[] = $data[$f]; }
        }
        if (!$sets) return ['ok'=>false,'error'=>'Nothing to update'];
        $sets[] = 'updated_at=?'; $params[] = date('Y-m-d H:i:s');
        $params[] = $id;
        $this->dbq("UPDATE cb_ledger SET ".implode(',',$sets)." WHERE id=?", $params);
        return ['ok'=>true];
    }

    public function deleteEntry(int $id, array $admin): array
    {
        $entry = $this->dbq("SELECT id,sr FROM cb_ledger WHERE id=?", [$id]);
        if (empty($entry)) return ['ok'=>false,'error'=>'Entry not found'];
        $this->dbq("DELETE FROM cb_ledger WHERE id=?", [$id]);
        return ['ok'=>true, 'sr'=>$entry[0]['sr']??''];
    }

    public function settleDisb(int $id, string $voucherNo, float $returnAmount, array $admin): array
    {
        $entry = $this->getEntryById($id);
        if (!$entry) return ['ok'=>false,'error'=>'Not found'];
        $this->dbq(
            "UPDATE cb_ledger SET validation_status='voucher',validation_ref=?,updated_at=? WHERE id=?",
            [$voucherNo, date('Y-m-d H:i:s'), $id]
        );
        if ($returnAmount > 0) {
            $this->addEntry([
                'project'=>$entry['project'],'date'=>date('Y-m-d'),'direction'=>'in',
                'amount'=>$returnAmount,'currency'=>'USD','category'=>'Receipt',
                'description'=>'Change returned — '.$entry['person'].' re: '.$entry['sr'],
                'validation_ref'=>$voucherNo,'validation_status'=>'voucher',
            ], $admin, true);
        }
        return ['ok'=>true,'voucher'=>$voucherNo,'return_posted'=>$returnAmount>0];
    }

    // ── QUERIES ───────────────────────────────────────────────────────────

    public function getEntryById(int $id): ?array
    {
        $r = $this->dbq("SELECT * FROM cb_ledger WHERE id=?", [$id]);
        return $r[0] ?? null;
    }

    public function countEntries(string $project = ''): int
    {
        if ($project) {
            $r = $this->dbq("SELECT COUNT(*) as n FROM cb_ledger WHERE project=? AND NOT(amount=0 AND sr='')", [$project]);
        } else {
            $r = $this->dbq("SELECT COUNT(*) as n FROM cb_ledger WHERE NOT(amount=0 AND sr='')");
        }
        return (int)($r[0]['n'] ?? 0);
    }

    public function getEntries(array $f = []): array
    {
        list($where, $params) = $this->buildWhere($f);
        $limit  = (int)($f['limit']  ?? 50);
        $offset = (int)($f['offset'] ?? 0);

        // Compute running balance for the SAME project scope using all entries
        // (not just the current page) so the balance column is always correct.
        // v4.9.18: Currency-separated running balances — SSP and USD never mix
        $projFilter = !empty($f['project']) ? $f['project'] : '';
        $currFilter = !empty($f['currency']) ? $f['currency'] : '';
        $useSSP     = ($currFilter === 'SSP');
        $amtCol     = $useSSP ? 'COALESCE(ssp_amount,0)' : 'amount';
        // v4.9.18: When NOT in SSP mode, exclude SSP entries from USD running balance
        // Same pattern as getBalance() — currency='USD' OR NULL OR empty
        $currWhere  = $useSSP
            ? " AND currency='SSP'"
            : " AND (currency='USD' OR currency IS NULL OR currency='')";
        if ($projFilter) {
            $allRows = $this->dbq(
                "SELECT id, direction, {$amtCol} as bal_amt FROM cb_ledger
                 WHERE project=? AND status NOT IN ('voided','voided_reconcile')"
                . $currWhere
                . " ORDER BY date ASC, id ASC",
                [$projFilter]
            );
        } else {
            $allRows = $this->dbq(
                "SELECT id, direction, {$amtCol} as bal_amt FROM cb_ledger
                 WHERE status NOT IN ('voided','voided_reconcile')"
                . $currWhere
                . " ORDER BY date ASC, id ASC"
            );
        }
        // v4.9.18: Also compute SSP running balance separately for "All" view
        $sspBalMap = [];
        if (!$useSSP && !$currFilter) {
            $sspQ = "SELECT id, direction, COALESCE(ssp_amount,0) as bal_amt FROM cb_ledger
                     WHERE status NOT IN ('voided','voided_reconcile') AND currency='SSP'"
                   . ($projFilter ? " AND project=?" : "")
                   . " ORDER BY date ASC, id ASC";
            $sspRows = $this->dbq($sspQ, $projFilter ? [$projFilter] : []);
            $sspRun = 0.0;
            foreach ($sspRows as $sr) {
                $sspRun += $sr['direction'] === 'in' ? (float)$sr['bal_amt'] : -(float)$sr['bal_amt'];
                $sspBalMap[(int)$sr['id']] = round($sspRun, 0);
            }
        }
        $balMap  = [];
        $running = 0.0;
        foreach ($allRows as $r) {
            $running += $r['direction'] === 'in' ? (float)$r['bal_amt'] : -(float)$r['bal_amt'];
            $balMap[(int)$r['id']] = round($running, $useSSP ? 0 : 2);
        }

        // v4.9.10: Pass currency info to display layer
        $rows = $this->dbq(
            "SELECT * FROM cb_ledger WHERE $where ORDER BY date DESC, id DESC LIMIT $limit OFFSET $offset",
            $params
        );
        foreach ($rows as &$r) {
            $id = (int)$r['id'];
            // v4.9.18: SSP entries get their own running balance in "All" view
            if (!$useSSP && ($r['currency'] ?? 'USD') === 'SSP' && isset($sspBalMap[$id])) {
                $r['running_balance'] = $sspBalMap[$id];
                $r['_bal_currency']   = 'SSP';
            } else {
                $r['running_balance'] = $balMap[$id] ?? null;
                $r['_bal_currency']   = $useSSP ? 'SSP' : 'USD';
            }
        }
        return $rows;
    }

    public function countFiltered(array $f = []): int
    {
        list($where, $params) = $this->buildWhere($f);
        $r = $this->dbq("SELECT COUNT(*) as n FROM cb_ledger WHERE $where", $params);
        return (int)($r[0]['n'] ?? 0);
    }

    private function buildWhere(array $f): array
    {
        // Exclude ghost rows AND voided reconcile entries
        $w = ["NOT (amount=0 AND sr='')", "status != 'voided_reconcile'"];
        $p = [];
        if (!empty($f['project']))           { $w[]="project=?";           $p[]=$f['project']; }
        if (!empty($f['direction']))          { $w[]="direction=?";          $p[]=$f['direction']; }
        if (!empty($f['currency']))           { $w[]="currency=?";           $p[]=$f['currency']; }
        if (!empty($f['category']))           { $w[]="category=?";           $p[]=$f['category']; }
        if (!empty($f['validation_status']))  { $w[]="validation_status=?";  $p[]=$f['validation_status']; }
        if (!empty($f['person']))             { $w[]="person LIKE ?";         $p[]='%'.$f['person'].'%'; }
        if (!empty($f['date_from']))          { $w[]="date>=?";              $p[]=$f['date_from']; }
        if (!empty($f['date_to']))            { $w[]="date<=?";              $p[]=$f['date_to']; }
        if (!empty($f['status']))             { $w[]="status=?";             $p[]=$f['status']; }
        if (!empty($f['search'])) {
            $w[] = '(description LIKE ? OR sr LIKE ? OR person LIKE ? OR validation_ref LIKE ?)';
            $s = '%'.$f['search'].'%';
            $p = array_merge($p, [$s,$s,$s,$s]);
        }
        return [implode(' AND ', $w), $p];
    }

    public function getLedger(string $project, string $dateFrom = '', string $dateTo = '', int $limit = 100, int $offset = 0): array
    {
        // v4.21.9 — voided rows STAY visible in the row list (audit trail), but
        // are excluded from running-balance math. Two separate WHERE clauses now.
        $wList = ["project='".addslashes($project)."'", "status != 'voided_reconcile'"];
        $wBal  = ["project='".addslashes($project)."'", "status NOT IN ('voided','voided_reconcile')"];
        $p = [];
        if ($dateFrom) { $wList[]="date>=?"; $wBal[]="date>=?"; $p[]=$dateFrom; }
        if ($dateTo)   { $wList[]="date<=?"; $wBal[]="date<=?"; $p[]=$dateTo; }
        $wListStr = implode(' AND ', $wList);
        $wBalStr  = implode(' AND ', $wBal);

        // Running balance — voided rows DO NOT contribute
        $all = $this->dbq("SELECT id,direction,amount FROM cb_ledger WHERE $wBalStr AND (currency='USD' OR currency IS NULL OR currency='') ORDER BY date ASC, id ASC", $p);
        $balMap = []; $running = 0.0;
        foreach ($all as $r) {
            $running += $r['direction']==='in' ? $r['amount'] : -$r['amount'];
            $balMap[$r['id']] = round($running, 2);
        }
        // Row list — voided rows REMAIN visible for audit
        $rows = $this->dbq("SELECT * FROM cb_ledger WHERE $wListStr ORDER BY date DESC, id DESC LIMIT $limit OFFSET $offset", $p);
        foreach ($rows as &$r) { $r['running_balance'] = $balMap[$r['id']] ?? null; }
        return $rows;
    }

    public function getBalance(string $project): float
    {
        // USD only — exclude SSP entries so currencies stay fully separated
        $r = $this->dbq(
            "SELECT direction, SUM(amount) as total
             FROM cb_ledger
             WHERE project=? AND status='approved'
               AND (currency='USD' OR currency IS NULL OR currency='')
               AND NOT(amount=0 AND sr='')
             GROUP BY direction",
            [$project]
        );
        $in = 0.0; $out = 0.0;
        foreach ($r as $row) {
            if ($row['direction']==='in')  $in  = (float)$row['total'];
            if ($row['direction']==='out') $out = (float)$row['total'];
        }
        return round($in - $out, 2);
    }

    public function getBothBalances(): array
    {
        // USD balance — per project, pure USD entries only
        $dn = $this->getBalanceByCurrency('dishnet', 'USD');
        $g4 = $this->getBalanceByCurrency('4g', 'USD');
        $bc = $this->getBalanceByCurrency('bluecard', 'USD');

        // SSP balance — GLOBAL across ALL projects (SSP is one currency, not per-project)
        $sspRows = $this->dbq(
            "SELECT direction, SUM(ssp_amount) as total
             FROM cb_ledger
             WHERE status='approved'
               AND currency='SSP' AND ssp_amount IS NOT NULL AND ssp_amount > 0
             GROUP BY direction"
        );
        $sspIn = 0.0; $sspOut = 0.0;
        foreach ($sspRows as $row) {
            if ($row['direction'] === 'in')  $sspIn  = (float)$row['total'];
            if ($row['direction'] === 'out') $sspOut = (float)$row['total'];
        }
        $sspBal = round($sspIn - $sspOut, 0);

        return [
            'dishnet'       => ['balance' => $dn],
            '4g'            => ['balance' => $g4],
            'bluecard'      => ['balance' => $bc],
            'exchange_rate' => $this->getExchangeRate(),
            'combined_usd'  => round($dn + $g4 + $bc, 2),
            // Fully separate — NO conversion between currencies
            'USD'           => ['balance' => $dn],
            'SSP'           => ['balance' => $sspBal],
            'usd_equivalent_ssp' => 0.0,  // intentionally zero — no mixing
        ];
    }

    /**
     * Balance for a project filtered to one currency only.
     * USD entries: currency='USD' or currency IS NULL (legacy entries).
     * SSP entries: uses ssp_amount column, not amount.
     */
    public function getBalanceByCurrency(string $project, string $currency = 'USD'): float
    {
        if ($currency === 'SSP') {
            $r = $this->dbq(
                "SELECT direction, SUM(ssp_amount) as total
                 FROM cb_ledger
                 WHERE project=? AND status='approved'
                   AND currency='SSP' AND ssp_amount IS NOT NULL AND ssp_amount > 0
                 GROUP BY direction",
                [$project]
            );
        } else {
            $r = $this->dbq(
                "SELECT direction, SUM(amount) as total
                 FROM cb_ledger
                 WHERE project=? AND status='approved'
                   AND (currency='USD' OR currency IS NULL OR currency='')
                   AND NOT(amount=0 AND sr='')
                 GROUP BY direction",
                [$project]
            );
        }
        $in = 0.0; $out = 0.0;
        foreach ($r as $row) {
            if ($row['direction'] === 'in')  $in  = (float)$row['total'];
            if ($row['direction'] === 'out') $out = (float)$row['total'];
        }
        return round($in - $out, 2);
    }

    public function getSummary(string $project, string $dateFrom = '', string $dateTo = ''): array
    {
        $w = ["project='".addslashes($project)."'"]; $p = [];
        if ($dateFrom) { $w[]="date>=?"; $p[]=$dateFrom; }
        if ($dateTo)   { $w[]="date<=?"; $p[]=$dateTo; }
        // USD only — SSP entries stored as USD-equivalent in amount would distort totals
        $w[] = "(currency='USD' OR currency IS NULL OR currency='')";
        $wStr = implode(' AND ',$w);
        $rows = $this->dbq(
            "SELECT direction,category,SUM(amount) as total,COUNT(*) as cnt
             FROM cb_ledger WHERE $wStr AND status='approved'
             GROUP BY direction,category ORDER BY total DESC", $p
        );
        $in=[]; $out=[]; $tIn=0.0; $tOut=0.0;
        foreach ($rows as $r) {
            if ($r['direction']==='in')  { $in[$r['category']] =['total'=>(float)$r['total'],'count'=>(int)$r['cnt']]; $tIn+=(float)$r['total']; }
            else                         { $out[$r['category']]=['total'=>(float)$r['total'],'count'=>(int)$r['cnt']]; $tOut+=(float)$r['total']; }
        }
        return ['in'=>$in,'out'=>$out,'total_in'=>round($tIn,2),'total_out'=>round($tOut,2),'balance'=>round($tIn-$tOut,2)];
    }

    public function getPendingDisbursements(string $project = ''): array
    {
        // v4.11.3 PERF: Static cache per project key — called 3x per page for accountant nav
        static $_pdCache = [];
        $cacheKey = $project ?: '_all';
        if (isset($_pdCache[$cacheKey])) return $_pdCache[$cacheKey];

        $w = ["validation_status='pending'","direction='out'"]; $p = [];
        if ($project) { $w[]="project=?"; $p[]=$project; }
        $rows = $this->dbq("SELECT * FROM cb_ledger WHERE ".implode(' AND ',$w)." ORDER BY date ASC", $p);
        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $r['days_pending'] = (int)round((strtotime($today)-strtotime($r['date']))/86400);
        }
        $_pdCache[$cacheKey] = $rows;
        return $rows;
    }

    public function getStaffCashPosition(string $project = ''): array
    {
        $w = ["validation_status='pending'","direction='out'","person!=''"]; $p = [];
        if ($project) { $w[]="project=?"; $p[]=$project; }
        $rows = $this->dbq(
            "SELECT person,COUNT(*) as cnt,SUM(amount) as total,MIN(date) as oldest_date
             FROM cb_ledger WHERE ".implode(' AND ',$w)."
             GROUP BY person ORDER BY total DESC", $p
        );
        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $r['days_oldest'] = (int)round((strtotime($today)-strtotime($r['oldest_date']))/86400);
            $r['status'] = $r['days_oldest']>30 ? 'overdue' : 'pending';
        }
        return $rows;
    }

    public function getPayrollSummary(string $project='', string $month=''): array
    {
        $w = ["category IN ('Salary','Transport Allowance','Food Allowance','Bonus','Employee Benefit')"]; $p = [];
        if ($project) { $w[]="project=?"; $p[]=$project; }
        if ($month) { $w[]="date>=?"; $w[]="date<=?"; $p[]=$month.'-01'; $p[]=$month.'-31'; }
        $rows = $this->dbq(
            "SELECT person,category,SUM(amount) as total,MAX(validation_ref) as last_voucher
             FROM cb_ledger WHERE ".implode(' AND ',$w)."
             GROUP BY person,category ORDER BY person,category", $p
        );
        // Pivot: one row per person with salary/transport/food/other columns
        $byPerson = [];
        foreach ($rows as $r) {
            $name = $r['person'] ?: '(unknown)';
            if (!isset($byPerson[$name])) {
                $byPerson[$name] = ['person'=>$name,'salary'=>0.0,'transport'=>0.0,'food'=>0.0,'other'=>0.0,'voucher_ref'=>''];
            }
            $cat = $r['category'];
            if ($cat === 'Salary')               $byPerson[$name]['salary']    += (float)$r['total'];
            elseif ($cat === 'Transport Allowance') $byPerson[$name]['transport'] += (float)$r['total'];
            elseif ($cat === 'Food Allowance')    $byPerson[$name]['food']      += (float)$r['total'];
            else                                  $byPerson[$name]['other']     += (float)$r['total'];
            if ($r['last_voucher'] && !$byPerson[$name]['voucher_ref']) {
                $byPerson[$name]['voucher_ref'] = $r['last_voucher'];
            }
        }
        return array_values($byPerson);
    }

    public function getSiteTracker(string $type='power'): array
    {
        $cat = $type==='rent' ? 'Site Rent' : 'Site Power';
        $rows = $this->dbq(
            "SELECT
                category_raw                    AS site_name,
                MAX(date)                       AS last_date,
                SUM(amount)                     AS total_paid,
                COUNT(*)                        AS cnt,
                MAX(validation_status)          AS last_status,
                MAX(person)                     AS paid_by,
                MAX(validation_ref)             AS meter,
                (SELECT amount FROM cb_ledger e2
                 WHERE e2.category=e.category AND e2.project='4g'
                   AND e2.category_raw=e.category_raw
                 ORDER BY e2.date DESC, e2.id DESC LIMIT 1) AS last_amount
             FROM cb_ledger e
             WHERE category=? AND project='4g'
             GROUP BY category_raw
             ORDER BY last_date DESC", [$cat]
        );
        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $r['days_since'] = $r['last_date']
                ? (int)round((strtotime($today) - strtotime($r['last_date'])) / 86400)
                : 999;
        }
        return $rows;
    }

    public function getIntercoTransfers(): array
    {
        return $this->dbq(
            "SELECT * FROM cb_ledger
             WHERE category IN ('Interco In','Interco Out','Loan Given','Loan Received','Loan Return Received')
                OR category_raw IN ('DishNet Africa Ltd-Loan Return','DishNet 4G-Loan Repay','DishNet 4G-Loan')
             ORDER BY date DESC LIMIT 100"
        );
    }

    public function getPLByMonth(string $project, string $year=''): array
    {
        $w = ["project='".addslashes($project)."'"]; $p = [];
        if ($year) { $w[]="date LIKE ?"; $p[]=$year.'%'; }
        return $this->dbq(
            "SELECT strftime('%Y-%m',date) as month,direction,SUM(amount) as total
             FROM cb_ledger WHERE ".implode(' AND ',$w)."
             AND status='approved' GROUP BY month,direction ORDER BY month ASC", $p
        );
    }

    // ── LEGACY COMPAT ─────────────────────────────────────────────────────
    public function query(string $sql, array $params = []): array { return $this->dbq($sql, $params); }
    public function getPendingEntries(): array { return $this->getPendingDisbursements('dishnet'); }
    public function getLedgerLegacy(string $c='USD', string $f='', string $t=''): array { return $this->getLedger('dishnet',$f,$t,100,0); }
    public function getSummaryLegacy(string $c='USD', string $f='', string $t=''): array { return $this->getSummary('dishnet',$f,$t); }
    public function approveEntry(int $id, array $admin): array {
        $this->dbq("UPDATE cb_ledger SET status='approved',approved_by=?,updated_at=? WHERE id=?",
            [$admin['name']??'admin',date('Y-m-d H:i:s'),$id]);
        return ['ok'=>true];
    }
    public function rejectEntry(int $id, string $reason, array $admin): array {
        $this->dbq("UPDATE cb_ledger SET status='rejected',reject_reason=?,updated_at=? WHERE id=?",
            [$reason,date('Y-m-d H:i:s'),$id]);
        return ['ok'=>true];
    }
    // legacy opening balance stub
    public function setOpeningBalance(string $currency, float $amount, array $admin): array { return ['ok'=>true]; }

    /**
     * Sync CRM payment_collections into cashbook as Cash IN (Receipt) entries.
     * Only imports entries on/after the cashbook seed cutoff date.
     * Uses crm_payment_id + source='crm_sync' for idempotency — safe to run repeatedly.
     *
     * @param array  $collections  All payment_collections records from store->load()
     * @param string $afterDate    Y-m-d — only import on/after this date (default: last date in cashbook)
     * @return array               ['imported'=>int, 'skipped'=>int, 'cutoff'=>string]
     */
    /**
     * Sync payments from UCRM API directly into cashbook as Cash IN.
     * Pulls ALL payment methods (not just PWA-collected ones) from the CRM billing/payments endpoint.
     * Uses PAY-{crmId} as validation_ref for deduplication — safe to run repeatedly.
     *
     * @param object $crm        CrmApiClient instance
     * @param string $afterDate  Y-m-d cutoff (default: day after last cashbook entry)
     * @param array  $localCols  Fallback: payment_collections.json (used if CRM API unreachable)
     * @return array             ['imported'=>int, 'skipped'=>int, 'cutoff'=>string, 'source'=>string]
     */
    public function syncFromCrmApi($crm, string $afterDate = '', array $localCols = []): array
    {
        if (!$afterDate) {
            // v4.9.19: Use same date (not +1 day). The dedup set ($doneRefs) already
            // prevents duplicate imports, so the date cutoff only needs to limit how
            // far back to scan — not to avoid same-day overlaps. The old +1 day logic
            // caused a critical gap: payments arriving on the same day AFTER a sync
            // ran were permanently missed (cutoff = tomorrow → today skipped).
            $r = $this->dbq("SELECT MAX(date) as d FROM cb_ledger WHERE status='approved' AND NOT(amount=0 AND sr='')");
            $afterDate = $r[0]['d'] ?? '2026-01-01';
        }

        // v4.9.19: Removed destructive "DELETE … WHERE source='crm_api_sync'" that
        // wiped ALL previously-synced entries on every run, causing data loss when
        // the CRM API page didn't return older payments (already past afterDate cutoff).
        // The dedup set below already includes 'crm_api_sync', so existing entries
        // are naturally skipped — no need to delete-and-rebuild.
        // One-time cleanup: remove legacy broken refs from early versions
        $this->dbq("DELETE FROM cb_ledger WHERE source='crm_sync' AND validation_ref='CRM-0'");

        // v4.9.19: Clean up duplicates caused by ref format mismatch.
        // Old webhook wrote CRM-PAY-xxx, sync wrote PAY-xxx → dedup missed them.
        // Both sources use sr='CRM-{paymentId}', so match by SR (most reliable).
        // Also match by validation_ref pattern and crm_payment_id as fallback.
        $this->dbq(
            "DELETE FROM cb_ledger WHERE source='crm_api_sync' AND (
                sr IN (SELECT sr FROM cb_ledger WHERE source='crm_webhook')
                OR REPLACE(validation_ref, 'PAY-', '') IN (
                    SELECT REPLACE(REPLACE(validation_ref, 'CRM-PAY-', ''), 'PAY-', '')
                    FROM cb_ledger WHERE source='crm_webhook'
                      AND (validation_ref LIKE 'PAY-%' OR validation_ref LIKE 'CRM-PAY-%')
                )
            )"
        );
        // Step B: Normalize old webhook refs from CRM-PAY-xxx to PAY-xxx
        $this->dbq(
            "UPDATE cb_ledger SET validation_ref = REPLACE(validation_ref, 'CRM-PAY-', 'PAY-')
             WHERE source='crm_webhook' AND validation_ref LIKE 'CRM-PAY-%'"
        );

        // Build cashbook dedup set (PAY-* and COL-* refs already imported)
        // v4.9.9: added 'crm_webhook' so nightly sync skips payments already posted in real-time
        $existing = $this->dbq("SELECT validation_ref FROM cb_ledger WHERE source IN ('crm_sync','crm_api_sync','collect_payment','crm_webhook')");
        $doneRefs = [];
        foreach (array_column($existing, 'validation_ref') as $ref) {
            // Normalize both formats to PAY-xxx for consistent matching
            $norm = (strpos($ref, 'CRM-PAY-') === 0) ? str_replace('CRM-PAY-', 'PAY-', $ref) : $ref;
            $doneRefs[$norm] = true;
            $doneRefs[$ref]  = true; // also keep original for exact match
        }

        // Load payment_collections — build lookup by crm_payment_id and by (clientId+amount+date)
        $localCols       = $this->store->load('payment_collections.json') ?? [];
        $colByCrmId      = []; // crm_payment_id → index
        $colByKey        = []; // clientId:amount:date → index
        foreach ($localCols as $i => $c) {
            if (!empty($c['crm_payment_id'])) {
                $colByCrmId[(int)$c['crm_payment_id']] = $i;
            }
            $cKey = ($c['crm_customer_id']??'').':'.(float)($c['amount']??0).':'.substr($c['created_at']??'',0,10);
            $colByKey[$cKey] = $i;
        }

        $imported = 0; $skipped = 0; $apiSource = 'local';
        $colsModified = false;

        // ── Try CRM API ───────────────────────────────────────────────
        $apiPayments = [];
        if ($crm) {
            $page = 1; $pageSize = 200;
            do {
                // v4.10.4: Fetch ONLY Cash UUID payments from CRM API.
                // Bank/cheque payments are tracked in CRM only, not in the physical cashbook.
                $endpoint = 'payments?limit='.$pageSize.'&offset='.(($page-1)*$pageSize)
                          . '&order=createdDate&direction=DESC'
                          . '&methodId=' . PaymentUuids::CASH;
                $batch = $crm->get($endpoint);
                if (!is_array($batch) || empty($batch)) break;
                foreach ($batch as $pay) {
                    $payDate = substr($pay['createdDate'] ?? '', 0, 10);
                    if ($payDate < $afterDate) break 2;
                    $apiPayments[] = $pay;
                }
                $page++;
                if (count($batch) < $pageSize) break;
            } while (count($apiPayments) < 2000);

            if (!empty($apiPayments)) $apiSource = 'crm_api';
        }

        // ── Process CRM API payments ──────────────────────────────────
        if (!empty($apiPayments)) {
            $clientCache = [];
            foreach ($apiPayments as $pay) {
                $payDate  = substr($pay['createdDate'] ?? '', 0, 10);
                $crmId    = (int)($pay['id'] ?? 0);
                $amount   = (float)($pay['amount'] ?? 0);
                $clientId = (int)($pay['clientId'] ?? 0);
                $ref      = 'PAY-' . $crmId;

                if ($payDate < $afterDate) { $skipped++; continue; }
                if ($amount <= 0)          { $skipped++; continue; }

                // Determine payment method — used for category + validation_status
                $payMethodId   = $pay['methodId'] ?? '';
                $payMethodName = strtolower(trim($pay['methodName'] ?? ''));
                $isCash = ($payMethodId === PaymentUuids::CASH)
                    || (empty($payMethodId) && strpos($payMethodName, 'cash') !== false);
                $isBank = (!$isCash) && (
                    in_array($payMethodId, [
                        PaymentUuids::BANK_TRANSFER,
                        PaymentUuids::BANK_STANBIC,
                        PaymentUuids::BANK_ECO,
                        PaymentUuids::BANK_EQUITY,
                        PaymentUuids::CHECK_ECO,
                        PaymentUuids::CHECK_STANBIC,
                    ], true)
                    || strpos($payMethodName, 'bank') !== false
                    || strpos($payMethodName, 'transfer') !== false
                    || strpos($payMethodName, 'stanbic') !== false
                    || strpos($payMethodName, 'eco') !== false
                    || strpos($payMethodName, 'equity') !== false
                    || strpos($payMethodName, 'check') !== false
                    || strpos($payMethodName, 'cheque') !== false
                );
                // Skip truly unknown/unrecognised payment types (e.g. internal adjustments)
                if (!$isCash && !$isBank) { $skipped++; continue; }

                // v4.10.4: Cashbook = CASH transactions only.
                // Bank/cheque payments are tracked in CRM, not in the physical cashbook.
                if (!$isCash) { $skipped++; continue; }

                // Resolve customer name
                if ($clientId && !isset($clientCache[$clientId])) {
                    $cl = $crm->get('clients/'.$clientId);
                    $clientCache[$clientId] = $cl
                        ? trim(($cl['firstName']??'').' '.($cl['lastName']??''))
                        : 'CRM #'.$clientId;
                }
                $customerName = $clientCache[$clientId] ?? ('CRM #'.$clientId);
                $note = $pay['note'] ?? '';

                // ── Cross-match local collections ─────────────────────
                $matchIdx = null;
                if (isset($colByCrmId[$crmId])) {
                    $matchIdx = $colByCrmId[$crmId];
                } else {
                    // Match by clientId + amount + date (same-day cash collection)
                    $cKey = $clientId.':'.$amount.':'.$payDate;
                    if (isset($colByKey[$cKey])) {
                        $matchIdx = $colByKey[$cKey];
                    }
                }

                if ($matchIdx !== null) {
                    // Mark existing local collection as synced
                    if (empty($localCols[$matchIdx]['crm_synced'])) {
                        $localCols[$matchIdx]['crm_synced']     = true;
                        $localCols[$matchIdx]['crm_payment_id'] = $crmId;
                        $localCols[$matchIdx]['crm_synced_at']  = date('Y-m-d H:i:s');
                        $colsModified = true;
                    }
                } else {
                    // No local collection — create one as crm_direct so it appears in All Collections
                    $newCol = [
                        'id'              => time() + $crmId, // stable pseudo-id
                        'retailer_id'     => 0,
                        'retailer_name'   => 'Direct UCRM Entry',
                        'customer_name'   => $customerName,
                        'invoice_id'      => null,
                        'crm_customer_id' => $clientId,
                        'amount'          => $amount,
                        'currency'        => 'USD',
                        'method'          => 'Cash',
                        'service_type'    => '',
                        'note'            => $note,
                        'commission'      => 0,
                        'comm_rate'       => 0,
                        'crm_synced'      => true,
                        'crm_payment_id'  => $crmId,
                        'crm_synced_at'   => date('Y-m-d H:i:s'),
                        'source'          => 'crm_direct',
                        'created_at'      => substr($pay['createdDate'] ?? $payDate.' 00:00:00', 0, 19),
                    ];
                    $localCols[]    = $newCol;
                    $colByCrmId[$crmId] = count($localCols) - 1;
                    $colsModified = true;
                }

                // ── Write to cashbook (idempotent) ────────────────────
                if (!isset($doneRefs[$ref])) {
                    $methodLabel = $isBank ? 'Bank transfer' : 'Cash';
                    $desc = $methodLabel.' from '.$customerName.($clientId?' (CRM #'.$clientId.')':'');
                    if ($note) $desc .= ' — '.$note;
                    $this->addEntryRaw([
                        'sr'                => 'CRM-'.$crmId,
                        'project'           => 'dishnet',
                        'date'              => $payDate,
                        'direction'         => 'in',
                        'amount'            => $amount,
                        'currency'          => 'USD',
                        'category'          => $isBank ? 'Bank Transfer' : 'Receipt',
                        'category_raw'      => $isBank ? 'Bank Transfer' : 'Receipt',
                        'person'            => '',
                        'description'       => $desc,
                        'validation_ref'    => $ref,
                        'validation_status' => $isBank ? 'online' : 'na',
                        'status'            => 'approved',
                        'approved_by'       => 'CRM Pull',
                        'crm_payment_id'    => $crmId,
                        'crm_client_id'     => $clientId,
                        'source'            => 'crm_api_sync',
                        'created_at'        => substr($pay['createdDate'] ?? $payDate.' 00:00:00', 0, 19),
                    ]);
                    $doneRefs[$ref] = true;
                    $imported++;
                } else {
                    $skipped++;
                }
            }
        } else {
            // ── Fallback: local payment_collections.json ───────────────
            $apiSource = 'local_fallback';
            foreach ($localCols as $c) {
                $date    = substr($c['created_at'] ?? '', 0, 10);
                $storeId = (int)($c['id'] ?? 0);
                $amount  = (float)($c['amount'] ?? 0);
                $ref     = 'COL-' . $storeId;

                if ($date < $afterDate)     { $skipped++; continue; }
                if ($amount <= 0)           { $skipped++; continue; }
                if (isset($doneRefs[$ref])) { $skipped++; continue; }

                $customer = trim($c['customer_name'] ?? '');
                $crmCid   = (int)($c['crm_customer_id'] ?? 0);
                $agent    = trim($c['retailer_name'] ?? '');
                $note2    = trim($c['note'] ?? '');
                $desc = 'Cash collected'.($customer?' from '.$customer:'').($crmCid?' (CRM #'.$crmCid.')':'')
                       .($agent?' via '.$agent:'').($note2?' — '.$note2:'');

                $this->addEntryRaw([
                    'sr'=>'COL-'.$storeId,'project'=>'dishnet','date'=>$date,'direction'=>'in','amount'=>$amount,
                    'currency'=>'USD','category'=>'Receipt','category_raw'=>'Receipt',
                    'person'=>$agent,'description'=>$desc,'validation_ref'=>$ref,
                    'validation_status'=>'na','status'=>'approved','approved_by'=>'Local Sync',
                    'crm_payment_id'=>(int)($c['crm_payment_id']??0),
                    'crm_client_id'=>$crmCid,'source'=>'crm_sync',
                    'created_at'=>($c['created_at']??$date.' 00:00:00'),
                ]);
                $doneRefs[$ref] = true;
                $imported++;
            }
        }

        // Save updated payment_collections if anything changed
        if ($colsModified) {
            $this->store->save('payment_collections.json', array_values($localCols));
        }

        $meta = $this->getMeta();
        $meta['crm_sync_at']     = date('Y-m-d H:i:s');
        $meta['crm_sync_total']  = ($meta['crm_sync_total'] ?? 0) + $imported;
        $meta['crm_sync_cutoff'] = $afterDate;
        $meta['crm_sync_source'] = $apiSource;
        $this->store->save(self::META_FILE, $meta);

        return ['imported'=>$imported,'skipped'=>$skipped,'cutoff'=>$afterDate,'source'=>$apiSource];
    }


    /** Legacy wrapper — kept for compatibility */
    public function syncFromCrm(array $collections, string $afterDate = ''): array
    {
        // Default cutoff: same date as last cashbook entry (dedup handles overlaps)
        if (!$afterDate) {
            $r = $this->dbq("SELECT MAX(date) as d FROM cb_ledger WHERE status='approved' AND NOT(amount=0 AND sr='')");
            $afterDate = $r[0]['d'] ?? '2026-01-01';
        }

        // Remove any previously mis-imported entries with validation_ref='CRM-0' (old broken dedup)
        $this->dbq("DELETE FROM cb_ledger WHERE source='crm_sync' AND validation_ref='CRM-0'");

        // Dedup using validation_ref = 'COL-{store_id}' — works even when crm_payment_id is null
        $existing = $this->dbq("SELECT validation_ref FROM cb_ledger WHERE source='crm_sync'");
        $doneRefs = array_flip(array_column($existing, 'validation_ref'));

        $imported = 0;
        $skipped  = 0;

        foreach ($collections as $c) {
            $date      = substr($c['created_at'] ?? '', 0, 10);
            $storeId   = (int)($c['id'] ?? 0);      // internal payment_collections store id
            $crmPayId  = (int)($c['crm_payment_id'] ?? 0);  // CRM payment id (may be 0/null)
            $amount    = (float)($c['amount'] ?? 0);
            $colRef    = 'COL-' . $storeId;         // unique per collection record

            if ($date < $afterDate)              { $skipped++; continue; }  // before cutoff
            if ($amount <= 0)                    { $skipped++; continue; }  // zero/negative
            if (isset($doneRefs[$colRef]))        { $skipped++; continue; }  // already imported

            $customer = trim($c['customer_name'] ?? '');
            $crmCid   = (int)($c['crm_customer_id'] ?? 0);
            $agent    = trim($c['retailer_name'] ?? '');
            $note     = trim($c['note'] ?? '');

            $desc = 'Cash collected';
            if ($customer) $desc .= ' from ' . $customer;
            if ($crmCid)   $desc .= ' (CRM #' . $crmCid . ')';
            if ($agent)    $desc .= ' via ' . $agent;
            if ($note)     $desc .= ' — ' . $note;

            $this->addEntryRaw([
                'project'           => 'dishnet',
                'date'              => $date,
                'direction'         => 'in',
                'amount'            => $amount,
                'currency'          => 'USD',
                'category'          => 'Receipt',
                'category_raw'      => 'Receipt',
                'person'            => $agent,
                'description'       => $desc,
                'validation_ref'    => $colRef,       // COL-{storeId} — unique, stable
                'validation_status' => 'online',
                'status'            => 'approved',
                'approved_by'       => 'CRM Auto-Sync',
                'crm_payment_id'    => $crmPayId,
                'crm_client_id'     => $crmCid,
                'source'            => 'crm_sync',
                'created_at'        => ($c['created_at'] ?? $date . ' 00:00:00'),
            ]);
            $doneRefs[$colRef] = true; // block re-import within same batch
            $imported++;
        }

        // Update sync metadata
        $meta = $this->getMeta();
        $meta['crm_sync_at']      = date('Y-m-d H:i:s');
        $meta['crm_sync_total']   = ($meta['crm_sync_total'] ?? 0) + $imported;
        $meta['crm_sync_cutoff']  = $afterDate;
        $this->store->save(self::META_FILE, $meta);

        return ['imported' => $imported, 'skipped' => $skipped, 'cutoff' => $afterDate];
    }
}
