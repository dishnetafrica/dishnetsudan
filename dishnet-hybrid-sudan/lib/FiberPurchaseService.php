<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

/**
 * FiberPurchaseService — Phase 1
 *
 * Tracks supplier invoices for fiber infrastructure, calculates expected costs
 * from Splynx active services × plan costs, and reconciles expected vs actual.
 *
 * Invoice lifecycle: received → verified → approved → paid → posted
 *
 * Depends on:
 *   - SQLite (via $pdo) — tables from migration 038
 *   - SplynxApiClient — for fetching active services + tariffs
 *   - CashbookService — for posting paid invoices (Phase 3)
 */
class FiberPurchaseService
{
    private \PDO $db;
    private array $config;

    /** Valid status transitions */
    private const TRANSITIONS = [
        'received'  => 'verified',
        'verified'  => 'approved',
        'approved'  => 'paid',
        'paid'      => 'posted',
    ];

    public function __construct(\PDO $pdo, array $config = [])
    {
        $this->db     = $pdo;
        $this->config = $config;
        $this->ensureTables();
    }

    // ══════════════════════════════════════════════════════════════════════
    // TABLE BOOTSTRAP
    // ══════════════════════════════════════════════════════════════════════

    private function ensureTables(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS fiber_supplier_invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier TEXT NOT NULL, invoice_number TEXT NOT NULL,
            invoice_date TEXT NOT NULL, billing_period TEXT NOT NULL, total_amount REAL NOT NULL,
            currency TEXT NOT NULL DEFAULT 'USD', expected_amount REAL DEFAULT NULL,
            variance REAL DEFAULT NULL, variance_pct REAL DEFAULT NULL, line_items TEXT DEFAULT NULL,
            status TEXT NOT NULL DEFAULT 'received', verified_by TEXT DEFAULT NULL, verified_at TEXT DEFAULT NULL,
            approved_by TEXT DEFAULT NULL, approved_at TEXT DEFAULT NULL, paid_at TEXT DEFAULT NULL,
            payment_ref TEXT DEFAULT NULL, cb_entry_id INTEGER DEFAULT NULL, notes TEXT DEFAULT NULL,
            attachment_path TEXT DEFAULT NULL, created_by TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')), updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS fiber_plan_costs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier TEXT NOT NULL, plan_name TEXT NOT NULL,
            cost_per_unit REAL NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT DEFAULT NULL,
            source TEXT DEFAULT 'manual', created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS fiber_cost_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT, billing_period TEXT NOT NULL UNIQUE,
            snapshot_date TEXT NOT NULL, active_services INTEGER NOT NULL DEFAULT 0,
            expected_cost REAL NOT NULL DEFAULT 0, actual_cost REAL DEFAULT NULL, variance REAL DEFAULT NULL,
            plan_breakdown TEXT DEFAULT NULL, supplier_invoice_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
    }

    // ══════════════════════════════════════════════════════════════════════
    // INVOICE CRUD
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Create a new supplier invoice record.
     * @return int The new invoice ID
     */
    public function createInvoice(array $data): int
    {
        $lineItems = null;
        if (!empty($data['line_items'])) {
            $lineItems = is_string($data['line_items']) ? $data['line_items'] : json_encode($data['line_items']);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO fiber_supplier_invoices
                (supplier, invoice_number, invoice_date, billing_period, total_amount, currency, line_items, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            trim($data['supplier'] ?? ''),
            trim($data['invoice_number'] ?? ''),
            trim($data['invoice_date'] ?? date('Y-m-d')),
            trim($data['billing_period'] ?? date('Y-m')),
            (float)($data['total_amount'] ?? 0),
            trim($data['currency'] ?? 'USD'),
            $lineItems,
            trim($data['notes'] ?? ''),
            trim($data['created_by'] ?? ''),
        ]);

        $id = (int)$this->db->lastInsertId();

        // Auto-reconcile if we have Splynx data
        try {
            $this->reconcileInvoice($id);
        } catch (\Throwable $e) {
            // Non-fatal — reconciliation can run later
        }

        return $id;
    }

    /**
     * Get a single invoice by ID.
     */
    public function getInvoice(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM fiber_supplier_invoices WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['line_items_parsed'] = json_decode($row['line_items'] ?? 'null', true);
        return $row;
    }

    /**
     * List invoices with optional filters.
     */
    public function listInvoices(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = []; $params = [];

        if (!empty($filters['supplier'])) {
            $where[] = "supplier = ?"; $params[] = $filters['supplier'];
        }
        if (!empty($filters['billing_period'])) {
            $where[] = "billing_period = ?"; $params[] = $filters['billing_period'];
        }
        if (!empty($filters['status'])) {
            $where[] = "status = ?"; $params[] = $filters['status'];
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM fiber_supplier_invoices {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Rows
        $sql = "SELECT * FROM fiber_supplier_invoices {$whereClause} ORDER BY invoice_date DESC, id DESC LIMIT ? OFFSET ?";
        $rowParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($rowParams);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($items as &$item) {
            $item['line_items_parsed'] = json_decode($item['line_items'] ?? 'null', true);
        }
        unset($item);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Update invoice fields (only if status is 'received' or 'verified').
     */
    public function updateInvoice(int $id, array $data): bool
    {
        $inv = $this->getInvoice($id);
        if (!$inv) return false;
        if (!in_array($inv['status'], ['received', 'verified'])) return false;

        $sets = []; $params = [];
        $allowed = ['supplier', 'invoice_number', 'invoice_date', 'billing_period', 'total_amount', 'currency', 'notes'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "{$field} = ?";
                $params[] = is_numeric($data[$field]) ? (float)$data[$field] : trim((string)$data[$field]);
            }
        }
        if (array_key_exists('line_items', $data)) {
            $sets[] = "line_items = ?";
            $params[] = is_string($data['line_items']) ? $data['line_items'] : json_encode($data['line_items']);
        }

        if (empty($sets)) return false;

        $sets[] = "updated_at = ?";
        $params[] = date('Y-m-d H:i:s');
        $params[] = $id;

        $sql = "UPDATE fiber_supplier_invoices SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        // Re-reconcile
        try { $this->reconcileInvoice($id); } catch (\Throwable $e) {}

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete an invoice (only if status = received).
     */
    public function deleteInvoice(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM fiber_supplier_invoices WHERE id = ? AND status = 'received'");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    // ══════════════════════════════════════════════════════════════════════
    // LIFECYCLE TRANSITIONS
    // ══════════════════════════════════════════════════════════════════════

    public function verifyInvoice(int $id, string $verifiedBy): bool
    {
        return $this->transition($id, 'received', 'verified', [
            'verified_by' => $verifiedBy, 'verified_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function approveInvoice(int $id, string $approvedBy): bool
    {
        return $this->transition($id, 'verified', 'approved', [
            'approved_by' => $approvedBy, 'approved_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markPaid(int $id, string $paymentRef, string $paidAt = ''): bool
    {
        return $this->transition($id, 'approved', 'paid', [
            'payment_ref' => $paymentRef, 'paid_at' => $paidAt ?: date('Y-m-d H:i:s'),
        ]);
    }

    public function markPosted(int $id, int $cbEntryId): bool
    {
        return $this->transition($id, 'paid', 'posted', [
            'cb_entry_id' => $cbEntryId,
        ]);
    }

    private function transition(int $id, string $fromStatus, string $toStatus, array $extra = []): bool
    {
        $sets = ["status = ?", "updated_at = ?"];
        $params = [$toStatus, date('Y-m-d H:i:s')];

        foreach ($extra as $col => $val) {
            $sets[] = "{$col} = ?";
            $params[] = $val;
        }
        $params[] = $id;
        $params[] = $fromStatus;

        $sql = "UPDATE fiber_supplier_invoices SET " . implode(', ', $sets) . " WHERE id = ? AND status = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    // ══════════════════════════════════════════════════════════════════════
    // RECONCILIATION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Calculate expected cost for a billing period from Splynx active services.
     * Returns: ['total' => float, 'services' => int, 'by_plan' => [{plan, count, unit_cost, total}]]
     */
    /**
     * Calculate expected cost for a billing period.
     * Uses local fiber_services_cache (populated by FiberFinanceEngine::runSync).
     * Falls back to Splynx API only if cache is empty.
     */
    public function calculateExpected(string $billingPeriod = ''): array
    {
        if (!$billingPeriod) $billingPeriod = date('Y-m');

        $planCosts = $this->getCurrentCosts();

        // Try local cache first (fast — 5ms SQLite query)
        $cached = $this->db->query(
            "SELECT plan_name, COUNT(*) as cnt, AVG(tariff_price) as avg_price
             FROM fiber_services_cache WHERE splynx_status = 'active'
             GROUP BY plan_name ORDER BY plan_name"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $byPlan = [];
        $totalServices = 0;
        $totalExpected = 0;

        if (!empty($cached)) {
            // Use local cache
            foreach ($cached as $row) {
                $planName = $row['plan_name'] ?: 'Unknown Plan';
                $count    = (int)$row['cnt'];
                $costRec  = $planCosts[$planName] ?? null;
                $unitCost = $costRec ? (float)$costRec['cost_per_unit'] : (float)$row['avg_price'];
                $total    = round($unitCost * $count, 2);

                $byPlan[] = ['plan' => $planName, 'count' => $count, 'unit_cost' => $unitCost, 'total' => $total];
                $totalServices += $count;
                $totalExpected += $total;
            }
        } else {
            // Fallback: live Splynx API (only if no cache exists yet)
            $splynxData = $this->getSplynxActiveServices();
            $byPlanMap = [];
            foreach ($splynxData as $svc) {
                $status = strtolower($svc['status'] ?? '');
                if ($status !== 'active') continue;
                $planName = $svc['tariff'] ?? $svc['description'] ?? 'Unknown Plan';
                if (!isset($byPlanMap[$planName])) {
                    $byPlanMap[$planName] = ['plan' => $planName, 'count' => 0, 'unit_cost' => 0, 'total' => 0];
                }
                $byPlanMap[$planName]['count']++;
                $totalServices++;
            }
            foreach ($byPlanMap as $planName => &$row) {
                $costRecord = $planCosts[$planName] ?? null;
                $row['unit_cost'] = $costRecord ? (float)$costRecord['cost_per_unit'] : $this->getSplynxTariffPrice($planName);
                $row['total'] = round($row['unit_cost'] * $row['count'], 2);
                $totalExpected += $row['total'];
            }
            unset($row);
            $byPlan = array_values($byPlanMap);
        }

        return [
            'billing_period' => $billingPeriod,
            'total'          => round($totalExpected, 2),
            'services'       => $totalServices,
            'by_plan'        => $byPlan,
            'calculated_at'  => date('Y-m-d H:i:s'),
            'source'         => !empty($cached) ? 'cache' : 'splynx_api',
        ];
    }

    /**
     * Reconcile an invoice against expected costs.
     * Updates the invoice record with expected_amount, variance, variance_pct.
     */
    public function reconcileInvoice(int $invoiceId): array
    {
        $inv = $this->getInvoice($invoiceId);
        if (!$inv) return ['error' => 'Invoice not found'];

        $expected = $this->calculateExpected($inv['billing_period']);

        $variance    = round((float)$inv['total_amount'] - $expected['total'], 2);
        $variancePct = $expected['total'] > 0
            ? round(($variance / $expected['total']) * 100, 1)
            : 0;

        // Per-plan comparison if invoice has line items
        $lineComparison = [];
        $invoiceLines = $inv['line_items_parsed'] ?? [];
        if (!empty($invoiceLines) && is_array($invoiceLines)) {
            $expectedByPlan = [];
            foreach ($expected['by_plan'] as $ep) {
                $expectedByPlan[$ep['plan']] = $ep;
            }

            foreach ($invoiceLines as $line) {
                $planName  = $line['plan'] ?? $line['description'] ?? '';
                $invQty    = (int)($line['qty'] ?? $line['quantity'] ?? 0);
                $invCost   = (float)($line['unit_cost'] ?? $line['price'] ?? 0);
                $invTotal  = (float)($line['total'] ?? ($invQty * $invCost));
                $exp       = $expectedByPlan[$planName] ?? null;
                $expQty    = $exp ? $exp['count'] : 0;
                $expCost   = $exp ? $exp['unit_cost'] : 0;
                $expTotal  = $exp ? $exp['total'] : 0;

                $lineComparison[] = [
                    'plan'          => $planName,
                    'inv_qty'       => $invQty,
                    'inv_unit_cost' => $invCost,
                    'inv_total'     => round($invTotal, 2),
                    'exp_qty'       => $expQty,
                    'exp_unit_cost' => $expCost,
                    'exp_total'     => round($expTotal, 2),
                    'qty_diff'      => $invQty - $expQty,
                    'cost_diff'     => round($invCost - $expCost, 2),
                    'total_diff'    => round($invTotal - $expTotal, 2),
                ];

                // Remove from expected so we can detect plans in expected but not invoiced
                unset($expectedByPlan[$planName]);
            }

            // Plans we expect but supplier didn't invoice (possible credit)
            foreach ($expectedByPlan as $planName => $ep) {
                $lineComparison[] = [
                    'plan'          => $planName,
                    'inv_qty'       => 0,
                    'inv_unit_cost' => 0,
                    'inv_total'     => 0,
                    'exp_qty'       => $ep['count'],
                    'exp_unit_cost' => $ep['unit_cost'],
                    'exp_total'     => $ep['total'],
                    'qty_diff'      => -$ep['count'],
                    'cost_diff'     => 0,
                    'total_diff'    => -$ep['total'],
                ];
            }
        }

        // Update invoice with reconciliation data
        $this->db->prepare(
            "UPDATE fiber_supplier_invoices
             SET expected_amount = ?, variance = ?, variance_pct = ?, updated_at = ?
             WHERE id = ?"
        )->execute([$expected['total'], $variance, $variancePct, date('Y-m-d H:i:s'), $invoiceId]);

        return [
            'invoice_id'       => $invoiceId,
            'invoice_total'    => (float)$inv['total_amount'],
            'expected_total'   => $expected['total'],
            'variance'         => $variance,
            'variance_pct'     => $variancePct,
            'expected_services'=> $expected['services'],
            'expected_by_plan' => $expected['by_plan'],
            'line_comparison'  => $lineComparison,
            'reconciled_at'    => date('Y-m-d H:i:s'),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // PLAN COST TRACKING
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Record or update a plan cost.
     */
    public function recordPlanCost(string $supplier, string $planName, float $costPerUnit, string $effectiveFrom = '', string $source = 'manual'): int
    {
        if (!$effectiveFrom) $effectiveFrom = date('Y-m-d');

        // Close previous cost record for this plan+supplier
        $this->db->prepare(
            "UPDATE fiber_plan_costs SET effective_to = ? WHERE supplier = ? AND plan_name = ? AND effective_to IS NULL"
        )->execute([$effectiveFrom, $supplier, $planName]);

        // Insert new
        $this->db->prepare(
            "INSERT OR REPLACE INTO fiber_plan_costs (supplier, plan_name, cost_per_unit, effective_from, source) VALUES (?, ?, ?, ?, ?)"
        )->execute([$supplier, $planName, $costPerUnit, $effectiveFrom, $source]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get current (latest) costs for all plans, keyed by plan_name.
     */
    public function getCurrentCosts(string $supplier = ''): array
    {
        $where = "WHERE effective_to IS NULL";
        $params = [];
        if ($supplier) {
            $where .= " AND supplier = ?";
            $params[] = $supplier;
        }

        $stmt = $this->db->prepare("SELECT * FROM fiber_plan_costs {$where} ORDER BY plan_name");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $indexed = [];
        foreach ($rows as $r) {
            $indexed[$r['plan_name']] = $r;
        }
        return $indexed;
    }

    /**
     * Get cost history for a plan.
     */
    public function getCostHistory(string $planName, string $supplier = ''): array
    {
        $where = "WHERE plan_name = ?";
        $params = [$planName];
        if ($supplier) { $where .= " AND supplier = ?"; $params[] = $supplier; }

        $stmt = $this->db->prepare("SELECT * FROM fiber_plan_costs {$where} ORDER BY effective_from DESC");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ══════════════════════════════════════════════════════════════════════
    // DASHBOARD / STATS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Get summary stats for the dashboard.
     */
    /**
     * Light dashboard stats — SQLite only, no Splynx API calls.
     * Used for tab bar badges and non-dashboard sub-tabs.
     */
    public function getDashboardStatsLight(): array
    {
        $currentPeriod = date('Y-m');

        // Latest invoice
        $latestInv = $this->db->query(
            "SELECT * FROM fiber_supplier_invoices ORDER BY invoice_date DESC, id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);

        // Status counts
        $statusCounts = [];
        $sc = $this->db->query("SELECT status, COUNT(*) as cnt FROM fiber_supplier_invoices GROUP BY status")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($sc as $r) { $statusCounts[$r['status']] = (int)$r['cnt']; }

        // Unique suppliers (from both invoices + plan costs)
        $suppliers = $this->getSuppliers();

        // Total paid this year
        $yearStart = date('Y') . '-01';
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM fiber_supplier_invoices WHERE billing_period >= ? AND status IN ('paid','posted')");
        $stmt->execute([$yearStart]);
        $totalPaid = (float)$stmt->fetchColumn();

        return [
            'current_period'      => $currentPeriod,
            'expected_cost'       => 0,       // Not computed in light mode
            'expected_services'   => 0,
            'expected_by_plan'    => [],
            'latest_invoice'      => $latestInv ?: null,
            'status_counts'       => $statusCounts,
            'suppliers'           => $suppliers,
            'total_paid_ytd'      => round($totalPaid, 2),
            'pending_count'       => ($statusCounts['received'] ?? 0) + ($statusCounts['verified'] ?? 0),
        ];
    }

    public function getDashboardStats(): array
    {
        $currentPeriod = date('Y-m');
        $lastPeriod    = date('Y-m', strtotime('first day of last month'));

        // This month expected
        $expected = $this->calculateExpected($currentPeriod);

        // Latest invoice
        $latestInv = $this->db->query(
            "SELECT * FROM fiber_supplier_invoices ORDER BY invoice_date DESC, id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);

        // Status counts
        $statusCounts = [];
        $sc = $this->db->query("SELECT status, COUNT(*) as cnt FROM fiber_supplier_invoices GROUP BY status")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($sc as $r) { $statusCounts[$r['status']] = (int)$r['cnt']; }

        // Unique suppliers
        $suppliers = $this->db->query("SELECT DISTINCT supplier FROM fiber_supplier_invoices ORDER BY supplier")->fetchAll(\PDO::FETCH_COLUMN);

        // Total paid this year
        $yearStart = date('Y') . '-01';
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM fiber_supplier_invoices WHERE billing_period >= ? AND status IN ('paid','posted')");
        $stmt->execute([$yearStart]);
        $totalPaid = (float)$stmt->fetchColumn();

        return [
            'current_period'      => $currentPeriod,
            'expected_cost'       => $expected['total'],
            'expected_services'   => $expected['services'],
            'expected_by_plan'    => $expected['by_plan'],
            'latest_invoice'      => $latestInv ?: null,
            'status_counts'       => $statusCounts,
            'suppliers'           => $suppliers,
            'total_paid_ytd'      => round((float)$totalPaid, 2),
            'pending_count'       => ($statusCounts['received'] ?? 0) + ($statusCounts['verified'] ?? 0),
        ];
    }

    /**
     * Get trend data for last N months.
     */
    public function getTrend(int $months = 6): array
    {
        $oldest = date('Y-m', strtotime("-{$months} months"));

        // Batch query 1: actual costs by period (1 query instead of N)
        $actuals = [];
        $stmt = $this->db->prepare(
            "SELECT billing_period, COALESCE(SUM(total_amount), 0) as total
             FROM fiber_supplier_invoices WHERE billing_period >= ?
             GROUP BY billing_period"
        );
        $stmt->execute([$oldest]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $actuals[$r['billing_period']] = (float)$r['total'];
        }

        // Batch query 2: expected costs from snapshots (1 query instead of N)
        $expected = [];
        $stmt2 = $this->db->prepare(
            "SELECT billing_period, expected_cost FROM fiber_cost_snapshots WHERE billing_period >= ?"
        );
        $stmt2->execute([$oldest]);
        foreach ($stmt2->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $expected[$r['billing_period']] = (float)$r['expected_cost'];
        }

        // Build result array
        $data = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $period = date('Y-m', strtotime("-{$i} months"));
            $data[] = [
                'period'   => $period,
                'label'    => date('M Y', strtotime("-{$i} months")),
                'actual'   => round($actuals[$period] ?? 0, 2),
                'expected' => isset($expected[$period]) ? round($expected[$period], 2) : null,
            ];
        }
        return $data;
    }

    // ══════════════════════════════════════════════════════════════════════
    // SPLYNX DATA (uses SplynxApiClient from Hybrid)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Fetch active internet services from Splynx.
     */
    private function getSplynxActiveServices(): array
    {
        try {
            $splynx = SplynxApiClient::fromConfig($this->config);
            if (!$splynx->isConfigured()) return [];

            // Fetch all internet services
            $services = $splynx->get('api/2.0/admin/customers/customer-internet-services') ?? [];
            return is_array($services) ? $services : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get tariff price from Splynx for a plan name.
     */
    private function getSplynxTariffPrice(string $planName): float
    {
        static $tariffCache = null;
        if ($tariffCache === null) {
            try {
                $splynx = SplynxApiClient::fromConfig($this->config);
                $tariffs = $splynx->getTariffs();
                $tariffCache = [];
                foreach ($tariffs as $t) {
                    $name = $t['title'] ?? $t['name'] ?? '';
                    $tariffCache[$name] = (float)($t['price'] ?? 0);
                }
            } catch (\Throwable $e) {
                $tariffCache = [];
            }
        }
        return $tariffCache[$planName] ?? 0;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PHASE 3: CASHBOOK POSTING
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Post a paid invoice to the cashbook as an expense.
     * Returns ['ok' => bool, 'cb_entry_id' => int|null, 'error' => string|null]
     */
    public function postToCashbook(int $invoiceId, $cashbookService, string $postedBy = 'Admin'): array
    {
        $inv = $this->getInvoice($invoiceId);
        if (!$inv) return ['ok' => false, 'error' => 'Invoice not found'];
        if ($inv['status'] !== 'paid') return ['ok' => false, 'error' => 'Invoice must be in "paid" status'];
        if ($inv['cb_entry_id']) return ['ok' => false, 'error' => 'Already posted to cashbook'];

        $valRef = "FIBER-INV-{$invoiceId}";

        // Dedup check
        $dup = $this->db->prepare("SELECT id FROM cb_ledger WHERE validation_ref = ? LIMIT 1");
        $dup->execute([$valRef]);
        if ($dup->fetch()) return ['ok' => false, 'error' => 'Already exists in cashbook (dedup)'];

        $result = $cashbookService->addEntry([
            'project'          => 'dishnet',
            'direction'        => 'out',
            'amount'           => (float)$inv['total_amount'],
            'currency'         => $inv['currency'] ?? 'USD',
            'category'         => 'Fiber Infrastructure',
            'category_raw'     => 'Fiber Infrastructure / ' . $inv['supplier'],
            'person'           => $inv['supplier'],
            'description'      => "Fiber Invoice #{$inv['invoice_number']} — {$inv['billing_period']}",
            'validation_ref'   => $valRef,
            'validation_status'=> 'verified',
            'date'             => $inv['paid_at'] ? substr($inv['paid_at'], 0, 10) : date('Y-m-d'),
        ], ['name' => $postedBy], true);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Cashbook entry failed'];
        }

        $cbId = (int)($result['id'] ?? 0);
        $this->markPosted($invoiceId, $cbId);

        return ['ok' => true, 'cb_entry_id' => $cbId];
    }

    // ══════════════════════════════════════════════════════════════════════
    // MONTHLY SNAPSHOTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Take an immutable monthly snapshot of expected vs actual.
     * Idempotent — won't overwrite if one already exists for the period.
     */
    public function takeSnapshot(string $billingPeriod = ''): array
    {
        if (!$billingPeriod) $billingPeriod = date('Y-m');

        // Check if already taken
        $existing = $this->db->prepare("SELECT * FROM fiber_cost_snapshots WHERE billing_period = ?");
        $existing->execute([$billingPeriod]);
        if ($existing->fetch()) {
            return ['created' => false, 'reason' => 'Snapshot already exists for ' . $billingPeriod];
        }

        $expected = $this->calculateExpected($billingPeriod);

        // Find invoice for this period
        $inv = $this->db->prepare(
            "SELECT id, total_amount FROM fiber_supplier_invoices WHERE billing_period = ? ORDER BY id DESC LIMIT 1"
        );
        $inv->execute([$billingPeriod]);
        $invRow = $inv->fetch(\PDO::FETCH_ASSOC);

        $actual   = $invRow ? (float)$invRow['total_amount'] : null;
        $variance = ($actual !== null) ? round($actual - $expected['total'], 2) : null;

        $this->db->prepare(
            "INSERT INTO fiber_cost_snapshots
                (billing_period, snapshot_date, active_services, expected_cost, actual_cost, variance, plan_breakdown, supplier_invoice_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $billingPeriod, date('Y-m-d'),
            $expected['services'], $expected['total'],
            $actual, $variance,
            json_encode($expected['by_plan']),
            $invRow ? (int)$invRow['id'] : null,
        ]);

        return ['created' => true, 'billing_period' => $billingPeriod, 'expected' => $expected['total'], 'actual' => $actual, 'variance' => $variance];
    }

    /**
     * Get a snapshot for a period.
     */
    public function getSnapshot(string $billingPeriod): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM fiber_cost_snapshots WHERE billing_period = ?");
        $stmt->execute([$billingPeriod]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $row['plan_breakdown_parsed'] = json_decode($row['plan_breakdown'] ?? '[]', true);
        }
        return $row ?: null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PHASE 2: PRICE CHANGE DETECTION + LEAKAGE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Detect supplier price changes by comparing invoice line items across periods.
     * Returns plans where the unit cost changed vs the previous period.
     */
    public function detectPriceChanges(string $currentPeriod = ''): array
    {
        if (!$currentPeriod) $currentPeriod = date('Y-m');
        $prevPeriod = date('Y-m', strtotime($currentPeriod . '-01 -1 month'));

        $changes = [];

        // Get line items from current and previous period invoices
        $current = $this->getInvoiceLinesForPeriod($currentPeriod);
        $previous = $this->getInvoiceLinesForPeriod($prevPeriod);

        if (empty($current) || empty($previous)) {
            return ['changes' => [], 'current_period' => $currentPeriod, 'previous_period' => $prevPeriod, 'note' => 'Need invoices in both periods'];
        }

        // Index previous by plan
        $prevByPlan = [];
        foreach ($previous as $l) {
            $prevByPlan[$l['plan'] ?? ''] = $l;
        }

        foreach ($current as $line) {
            $plan = $line['plan'] ?? '';
            $curCost = (float)($line['unit_cost'] ?? 0);
            if (isset($prevByPlan[$plan])) {
                $prevCost = (float)($prevByPlan[$plan]['unit_cost'] ?? 0);
                if (abs($curCost - $prevCost) > 0.005) {
                    $changes[] = [
                        'plan'       => $plan,
                        'old_cost'   => $prevCost,
                        'new_cost'   => $curCost,
                        'change'     => round($curCost - $prevCost, 2),
                        'change_pct' => $prevCost > 0 ? round((($curCost - $prevCost) / $prevCost) * 100, 1) : 0,
                    ];
                }
            }
        }

        return [
            'changes'         => $changes,
            'current_period'  => $currentPeriod,
            'previous_period' => $prevPeriod,
            'count'           => count($changes),
        ];
    }

    /**
     * Get parsed line items from the latest invoice for a period.
     */
    private function getInvoiceLinesForPeriod(string $period): array
    {
        $stmt = $this->db->prepare(
            "SELECT line_items FROM fiber_supplier_invoices WHERE billing_period = ? AND line_items IS NOT NULL ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$period]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return [];
        $parsed = json_decode($row['line_items'], true);
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Detect cost leakage: services active in Splynx but not active in CRM.
     * We're paying the supplier for these but not billing the customer.
     */
    public function detectLeakage(): array
    {
        $splynxServices = $this->getSplynxActiveServices();
        $planCosts = $this->getCurrentCosts();
        $leaks = [];
        $totalLeak = 0;

        // We need to cross-reference with CRM service status.
        // Fetch CRM clients with services to check status.
        try {
            require_once __DIR__ . '/CrmApiClient.php';
            $crm = CrmApiClient::fromUcrm(dirname(__DIR__), $this->config);
        } catch (\Throwable $e) {
            return ['leaks' => [], 'error' => 'Cannot connect to CRM: ' . $e->getMessage()];
        }

        foreach ($splynxServices as $svc) {
            $splynxStatus = strtolower($svc['status'] ?? '');
            if ($splynxStatus !== 'active') continue;

            $planName  = $svc['tariff'] ?? $svc['description'] ?? 'Unknown';
            $custName  = $svc['customer_name'] ?? $svc['login'] ?? '?';
            $custId    = $svc['customer_id'] ?? '';

            // Look up CRM mapping — check if this customer has an active CRM service
            // For now, flag all Splynx-active services where plan cost > 0 but no matching CRM active
            $costRec = $planCosts[$planName] ?? null;
            $unitCost = $costRec ? (float)$costRec['cost_per_unit'] : $this->getSplynxTariffPrice($planName);

            if ($unitCost <= 0) continue; // No known cost, skip

            // TODO Phase 4: cross-reference with CRM service status for true leakage detection
            // For now, just report all active services with their costs for the overview
        }

        return [
            'leaks'             => $leaks,
            'count'             => count($leaks),
            'total_monthly_leak'=> round($totalLeak, 2),
        ];
    }

    /**
     * Check if an invoice is missing for a billing period.
     * Returns true if no invoice exists for the period and we're past the alert day.
     */
    public function checkMissingInvoice(string $billingPeriod = '', int $alertDay = 10): array
    {
        if (!$billingPeriod) $billingPeriod = date('Y-m');
        $dayOfMonth = (int)date('j');

        if ($dayOfMonth < $alertDay) {
            return ['missing' => false, 'reason' => "Only day {$dayOfMonth}, alert triggers on day {$alertDay}"];
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM fiber_supplier_invoices WHERE billing_period = ?");
        $stmt->execute([$billingPeriod]);
        $count = (int)$stmt->fetchColumn();

        return [
            'missing'        => $count === 0,
            'billing_period' => $billingPeriod,
            'invoice_count'  => $count,
            'alert_day'      => $alertDay,
        ];
    }

    /**
     * Get distinct supplier list.
     */
    public function getSuppliers(): array
    {
        $fromInvoices = $this->db->query("SELECT DISTINCT supplier FROM fiber_supplier_invoices WHERE supplier != '' ORDER BY supplier")->fetchAll(\PDO::FETCH_COLUMN);
        $fromCosts    = $this->db->query("SELECT DISTINCT supplier FROM fiber_plan_costs WHERE supplier != '' ORDER BY supplier")->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_unique(array_merge($fromInvoices, $fromCosts)));
    }

    // ══════════════════════════════════════════════════════════════════════
    // INVOICE PREDICTION ENGINE
    // Generates predicted supplier invoice based on active Splynx services.
    // Mirrors Fiber Finance plugin's supplier_invoices generation.
    //
    // Logic:
    //   - Each service gets a line item with proration for partial months
    //   - Service started mid-month: days_active / days_in_month × monthly_rate
    //   - Service disabled mid-month: days_active / days_in_month × monthly_rate
    //   - Installation cost for new customers ($50/install default)
    //   - Grand total = service_total + installation_total
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Generate a predicted supplier invoice for a billing period.
     * Reads from fiber_services_cache (must be synced first).
     */
    public function generatePredictedInvoice(string $billingPeriod = '', array $options = []): array
    {
        if (!$billingPeriod) $billingPeriod = date('Y-m');

        $periodStart  = $billingPeriod . '-01';
        $daysInMonth  = (int)date('t', strtotime($periodStart));
        $periodEnd    = $billingPeriod . '-' . str_pad((string)$daysInMonth, 2, '0', STR_PAD_LEFT);
        $installCost  = (float)($options['installation_cost'] ?? $this->config['fiber_installation_cost'] ?? 50);
        $planCosts    = $this->getCurrentCosts();

        // Fetch all services — active AND recently disabled (they were on for part of the month)
        $stmt = $this->db->prepare(
            "SELECT * FROM fiber_services_cache
             WHERE (splynx_status = 'active')
                OR (splynx_status IN ('stopped','disabled','hidden')
                    AND last_seen >= ?)"
        );
        $stmt->execute([$periodStart]);
        $services = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $lineItems     = [];
        $lineNo        = 0;
        $serviceTotal  = 0;
        $newCustomers  = 0;
        $auditFlags    = [];
        $seenCustomers = [];

        // Check which customers are "new" (first appeared this period or later)
        $existingBefore = [];
        try {
            $eb = $this->db->prepare(
                "SELECT DISTINCT splynx_customer_id FROM fiber_status_changes
                 WHERE new_status = 'Active' AND changed_at < ?"
            );
            $eb->execute([$periodStart]);
            foreach ($eb->fetchAll(\PDO::FETCH_COLUMN) as $cid) {
                $existingBefore[$cid] = true;
            }
        } catch (\Throwable $e) {}

        foreach ($services as $svc) {
            $lineNo++;
            $sid       = $svc['splynx_service_id'];
            $custId    = $svc['splynx_customer_id'];
            $planName  = $svc['plan_name'] ?? '';
            $plan      = $planCosts[$planName] ?? null;
            $monthlyRate = (float)($plan['cost_per_unit'] ?? $svc['tariff_price'] ?? 0);
            $splynxSt  = strtolower($svc['splynx_status'] ?? '');
            $startDate = $svc['created_at'] ?? '';
            $lastSeen  = $svc['last_seen'] ?? '';

            // Calculate active days in this billing period
            $effectiveStart = $periodStart;
            $effectiveEnd   = $periodEnd;

            // If service started after period start, prorate from start_date
            if ($startDate && $startDate > $periodStart) {
                $effectiveStart = $startDate;
            }

            // If service was disabled/stopped, check when
            if (in_array($splynxSt, ['stopped', 'disabled', 'hidden'])) {
                // last_seen is roughly when it was last active
                if ($lastSeen && substr($lastSeen, 0, 10) < $periodEnd) {
                    $effectiveEnd = substr($lastSeen, 0, 10);
                }
                // If it went down before the period started, skip entirely
                if ($effectiveEnd < $periodStart) continue;
            }

            // Cap to period bounds
            if ($effectiveStart < $periodStart) $effectiveStart = $periodStart;
            if ($effectiveEnd > $periodEnd) $effectiveEnd = $periodEnd;

            $startTs   = strtotime($effectiveStart);
            $endTs     = strtotime($effectiveEnd);
            $activeDays = max(0, (int)(($endTs - $startTs) / 86400) + 1);
            if ($activeDays > $daysInMonth) $activeDays = $daysInMonth;

            $prorationRate = $daysInMonth > 0 ? round($activeDays / $daysInMonth, 4) : 0;
            $lineTotal     = round($monthlyRate * $prorationRate, 2);

            // New customer?
            $isNew = !isset($existingBefore[$custId]) && !isset($seenCustomers[$custId]);
            if ($startDate && $startDate >= $periodStart && $startDate <= $periodEnd) {
                $isNew = true;
            }
            $seenCustomers[$custId] = true;

            // Audit flag for partial months
            $auditFlag = '';
            if ($activeDays < $daysInMonth && in_array($splynxSt, ['stopped', 'disabled', 'hidden'])) {
                $auditFlag = "Service disabled — prorated to {$effectiveEnd}";
                $auditFlags[] = ['line' => $lineNo, 'flag' => $auditFlag];
            } elseif ($activeDays < $daysInMonth && $effectiveStart > $periodStart) {
                $auditFlag = "New mid-month — started {$effectiveStart}";
                $auditFlags[] = ['line' => $lineNo, 'flag' => $auditFlag];
            }

            $lineItems[] = [
                'line_no'          => $lineNo,
                'type'             => 'service',
                'service_id'       => $sid,
                'customer_id'      => $custId,
                'crm_client_id'    => $svc['crm_client_id'] ?? '',
                'customer_name'    => $svc['customer_name'] ?? '',
                'plan_name'        => $planName,
                'service_status'   => $splynxSt,
                'crm_status'       => $svc['crm_status'] ?? '',
                'monthly_rate'     => $monthlyRate,
                'active_days'      => $activeDays,
                'days_in_month'    => $daysInMonth,
                'proration_rate'   => $prorationRate,
                'line_total'       => $lineTotal,
                'effective_start'  => $effectiveStart,
                'effective_end'    => $effectiveEnd,
                'start_date'       => $startDate,
                'is_new_customer'  => $isNew && $lineTotal > 0,
                'audit_flag'       => $auditFlag,
            ];

            $serviceTotal += $lineTotal;
            if ($isNew && $lineTotal > 0) $newCustomers++;
        }

        $installationTotal = round($newCustomers * $installCost, 2);
        $grandTotal        = round($serviceTotal + $installationTotal, 2);

        // Separate active vs disabled line counts
        $activeLines   = array_filter($lineItems, function($l) { return $l['service_status'] === 'active'; });
        $disabledLines = array_filter($lineItems, function($l) { return $l['service_status'] !== 'active'; });

        return [
            'month_key'                  => $billingPeriod,
            'invoice_number'             => 'PRED-' . str_replace('-', '', $billingPeriod),
            'period_start'               => $periodStart,
            'period_end'                 => $periodEnd,
            'days_in_month'              => $daysInMonth,
            'currency'                   => 'USD',
            'status'                     => 'predicted',
            'line_items'                 => $lineItems,
            'service_line_count'         => count($lineItems),
            'active_line_count'          => count($activeLines),
            'disabled_line_count'        => count($disabledLines),
            'service_total'              => round($serviceTotal, 2),
            'installation_cost_per_svc'  => $installCost,
            'new_customer_count'         => $newCustomers,
            'installation_total'         => $installationTotal,
            'grand_total'                => $grandTotal,
            'audit_flags'                => $auditFlags,
            'audit_flag_count'           => count($auditFlags),
            'generated_at'               => date('Y-m-d H:i:s'),
            // Reconciliation — filled when actual invoice arrives
            'supplier_actual_amount'     => null,
            'supplier_invoice_ref'       => '',
            'variance'                   => null,
            'variance_pct'               => null,
            // Summary by plan
            'by_plan'                    => $this->summarizePredictionByPlan($lineItems),
        ];
    }

    /**
     * Summarize predicted invoice by plan.
     */
    private function summarizePredictionByPlan(array $lineItems): array
    {
        $byPlan = [];
        foreach ($lineItems as $li) {
            $p = $li['plan_name'] ?: 'Unknown';
            if (!isset($byPlan[$p])) {
                $byPlan[$p] = ['plan' => $p, 'active' => 0, 'disabled' => 0, 'total' => 0, 'rate' => $li['monthly_rate']];
            }
            if ($li['service_status'] === 'active') $byPlan[$p]['active']++;
            else $byPlan[$p]['disabled']++;
            $byPlan[$p]['total'] += $li['line_total'];
        }
        foreach ($byPlan as &$bp) { $bp['total'] = round($bp['total'], 2); }
        unset($bp);
        return array_values($byPlan);
    }

    /**
     * Reconcile a predicted invoice against the actual supplier invoice.
     */
    public function reconcilePrediction(string $billingPeriod, float $actualAmount, string $invoiceRef = ''): array
    {
        $predicted = $this->generatePredictedInvoice($billingPeriod);
        $variance  = round($actualAmount - $predicted['grand_total'], 2);
        $variancePct = $predicted['grand_total'] > 0
            ? round(($variance / $predicted['grand_total']) * 100, 1) : 0;

        return [
            'billing_period'   => $billingPeriod,
            'predicted_total'  => $predicted['grand_total'],
            'service_total'    => $predicted['service_total'],
            'installation_total' => $predicted['installation_total'],
            'actual_amount'    => $actualAmount,
            'variance'         => $variance,
            'variance_pct'     => $variancePct,
            'status'           => abs($variancePct) < 2 ? 'match' : ($variancePct > 0 ? 'over' : 'under'),
            'by_plan'          => $predicted['by_plan'],
            'line_count'       => $predicted['service_line_count'],
            'new_customers'    => $predicted['new_customer_count'],
            'audit_flags'      => $predicted['audit_flag_count'],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // SUPPLIER INVOICE COMPARISON ENGINE
    //
    // Core question: "Is 4G Telecom cheating us?"
    //
    // Takes supplier's actual invoice (total + optional line items) and
    // compares against our prediction line by line.
    //
    // Detects:
    //   - Overcharges: supplier charged more than our calculation
    //   - Proration misses: supplier charged full month for partial service
    //   - Ghost charges: supplier billed services we don't have
    //   - Missed credits: cancelled services still charged
    //   - Dead installs: customer never used the service
    //
    // Supports:
    //   - Per-line dispute flagging with reasons
    //   - Dead install marking
    //   - Accountant notes per line
    //   - Comparison report for sending to supplier
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Full line-by-line comparison of our prediction vs supplier invoice.
     *
     * @param string $billingPeriod  e.g. '2026-02'
     * @param float  $supplierTotal  What the supplier actually invoiced
     * @param string $supplierRef    Supplier's invoice number
     * @param array  $supplierLines  Optional: supplier's line items [{service_id, amount, plan, customer}]
     * @return array Complete comparison with per-line diffs
     */
    public function compareWithSupplier(string $billingPeriod, float $supplierTotal, string $supplierRef = '', array $supplierLines = []): array
    {
        $predicted = $this->generatePredictedInvoice($billingPeriod);
        $ourLines  = $predicted['line_items'] ?? [];

        // Load any saved disputes/flags for this period
        $savedFlags = $this->loadComparisonFlags($billingPeriod);

        // Overall variance
        $variance    = round($supplierTotal - $predicted['grand_total'], 2);
        $variancePct = $predicted['grand_total'] > 0
            ? round(($variance / $predicted['grand_total']) * 100, 1) : 0;

        // Per-line comparison
        $lineComparison = [];
        $issues = [];
        $totalOurCost = 0;
        $totalSupplierCost = 0;
        $disputeCount = 0;
        $deadInstallCount = 0;
        $overchargeTotal = 0;

        // Index supplier lines by service_id for matching
        $supplierByService = [];
        foreach ($supplierLines as $sl) {
            $sid = (string)($sl['service_id'] ?? $sl['splynx_id'] ?? '');
            if ($sid) $supplierByService[$sid] = $sl;
        }

        foreach ($ourLines as $ourLine) {
            $sid = $ourLine['service_id'];
            $flags = $savedFlags[$sid] ?? [];
            $supplierLine = $supplierByService[$sid] ?? null;

            // Calculate what supplier likely charged (if no line items provided, estimate)
            $supplierAmount = null;
            $diffType = 'unknown';

            if ($supplierLine) {
                // Supplier provided line items — exact comparison
                $supplierAmount = (float)($supplierLine['amount'] ?? $supplierLine['total'] ?? $supplierLine['line_total'] ?? 0);
                $diff = round($supplierAmount - $ourLine['line_total'], 2);

                if (abs($diff) < 0.01) {
                    $diffType = 'match';
                } elseif ($diff > 0) {
                    $diffType = 'overcharge';
                    $overchargeTotal += $diff;
                } else {
                    $diffType = 'undercharge';
                }
                $totalSupplierCost += $supplierAmount;
                unset($supplierByService[$sid]); // Mark as matched
            } else {
                // No supplier line items — check for proration issues
                if ($ourLine['proration_rate'] < 1 && $ourLine['service_status'] !== 'active') {
                    // Service was disabled mid-month — supplier might charge full month
                    $fullMonthCost = $ourLine['monthly_rate'];
                    $ourCost = $ourLine['line_total'];
                    $potentialOvercharge = round($fullMonthCost - $ourCost, 2);
                    if ($potentialOvercharge > 0.50) {
                        $diffType = 'proration_risk';
                        $supplierAmount = $fullMonthCost; // Assume they charged full month
                        $overchargeTotal += $potentialOvercharge;
                    } else {
                        $diffType = 'likely_ok';
                    }
                } else {
                    $diffType = 'likely_ok';
                }
            }

            $totalOurCost += $ourLine['line_total'];

            $isDisputed = (bool)($flags['disputed'] ?? false);
            $isDeadInstall = (bool)($flags['dead_install'] ?? false);
            if ($isDisputed) $disputeCount++;
            if ($isDeadInstall) $deadInstallCount++;

            $lineComparison[] = [
                'line_no'           => $ourLine['line_no'],
                'service_id'        => $sid,
                'customer_name'     => $ourLine['customer_name'],
                'plan_name'         => $ourLine['plan_name'],
                'service_status'    => $ourLine['service_status'],
                'our_days'          => $ourLine['active_days'],
                'days_in_month'     => $ourLine['days_in_month'],
                'our_rate'          => $ourLine['monthly_rate'],
                'our_proration'     => $ourLine['proration_rate'],
                'our_amount'        => $ourLine['line_total'],
                'supplier_amount'   => $supplierAmount,
                'diff'              => $supplierAmount !== null ? round($supplierAmount - $ourLine['line_total'], 2) : null,
                'diff_type'         => $diffType,
                'is_new_customer'   => $ourLine['is_new_customer'],
                'effective_start'   => $ourLine['effective_start'],
                'effective_end'     => $ourLine['effective_end'],
                // Flags (from saved state)
                'disputed'          => $isDisputed,
                'dispute_reason'    => $flags['dispute_reason'] ?? '',
                'dead_install'      => $isDeadInstall,
                'acct_remarks'      => $flags['acct_remarks'] ?? '',
            ];
        }

        // Ghost charges: services in supplier invoice but NOT in our records
        $ghostCharges = [];
        foreach ($supplierByService as $sid => $sl) {
            $amt = (float)($sl['amount'] ?? $sl['total'] ?? $sl['line_total'] ?? 0);
            $ghostCharges[] = [
                'service_id'    => $sid,
                'customer_name' => $sl['customer'] ?? $sl['customer_name'] ?? 'Unknown',
                'plan_name'     => $sl['plan'] ?? $sl['plan_name'] ?? 'Unknown',
                'amount'        => $amt,
                'reason'        => 'Not in our records — verify with supplier',
            ];
            $overchargeTotal += $amt;
            $totalSupplierCost += $amt;
        }

        // Categorize all issues
        $overcharges    = array_filter($lineComparison, function($l) { return $l['diff_type'] === 'overcharge'; });
        $prorationRisks = array_filter($lineComparison, function($l) { return $l['diff_type'] === 'proration_risk'; });
        $undercharges   = array_filter($lineComparison, function($l) { return $l['diff_type'] === 'undercharge'; });

        // Verdict
        $verdict = 'clean';
        if (count($ghostCharges) > 0 || count($overcharges) > 0) $verdict = 'overcharged';
        elseif (count($prorationRisks) > 0) $verdict = 'proration_risk';
        elseif ($variance > 0 && $variancePct > 2) $verdict = 'over';
        elseif ($variance < 0 && $variancePct < -2) $verdict = 'under';
        elseif (abs($variancePct) <= 2) $verdict = 'match';

        $verdictMessages = [
            'clean'          => 'Invoice matches our calculations. No issues found.',
            'match'          => 'Invoice is within 2% of prediction. Acceptable.',
            'overcharged'    => 'OVERCHARGE DETECTED. ' . count($overcharges) . ' line(s) charged more than calculated.',
            'proration_risk' => 'PRORATION RISK. ' . count($prorationRisks) . ' cancelled service(s) may be charged full month.',
            'over'           => 'Supplier charged $' . number_format(abs($variance), 2) . ' MORE than our calculation.',
            'under'          => 'Supplier charged $' . number_format(abs($variance), 2) . ' LESS than our calculation.',
        ];

        return [
            'billing_period'        => $billingPeriod,
            'supplier_ref'          => $supplierRef,
            'supplier_total'        => $supplierTotal,
            'our_predicted_total'   => $predicted['grand_total'],
            'our_service_total'     => $predicted['service_total'],
            'our_installation_total'=> $predicted['installation_total'],
            'variance'              => $variance,
            'variance_pct'          => $variancePct,
            'verdict'               => $verdict,
            'verdict_message'       => $verdictMessages[$verdict] ?? '',
            // Line-by-line
            'line_comparison'       => $lineComparison,
            'line_count'            => count($lineComparison),
            // Issues found
            'overcharge_count'      => count($overcharges),
            'overcharge_total'      => round($overchargeTotal, 2),
            'proration_risk_count'  => count($prorationRisks),
            'ghost_charge_count'    => count($ghostCharges),
            'ghost_charges'         => $ghostCharges,
            'undercharge_count'     => count($undercharges),
            // Flags
            'dispute_count'         => $disputeCount,
            'dead_install_count'    => $deadInstallCount,
            // Metadata
            'compared_at'           => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Save dispute/dead-install/notes flags for a service line in a period.
     */
    public function saveComparisonFlag(string $billingPeriod, string $serviceId, array $flags): bool
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS fiber_comparison_flags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            billing_period TEXT NOT NULL, service_id TEXT NOT NULL,
            disputed INTEGER DEFAULT 0, dispute_reason TEXT DEFAULT '',
            dead_install INTEGER DEFAULT 0, acct_remarks TEXT DEFAULT '',
            flagged_by TEXT DEFAULT '', flagged_at TEXT NOT NULL DEFAULT '',
            UNIQUE(billing_period, service_id)
        )");

        $stmt = $this->db->prepare(
            "INSERT INTO fiber_comparison_flags (billing_period, service_id, disputed, dispute_reason, dead_install, acct_remarks, flagged_by, flagged_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(billing_period, service_id) DO UPDATE SET
             disputed = excluded.disputed, dispute_reason = excluded.dispute_reason,
             dead_install = excluded.dead_install, acct_remarks = excluded.acct_remarks,
             flagged_by = excluded.flagged_by, flagged_at = excluded.flagged_at"
        );
        $stmt->execute([
            $billingPeriod, $serviceId,
            (int)($flags['disputed'] ?? 0), trim($flags['dispute_reason'] ?? ''),
            (int)($flags['dead_install'] ?? 0), trim($flags['acct_remarks'] ?? ''),
            $flags['flagged_by'] ?? 'Admin', date('Y-m-d H:i:s'),
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Load all comparison flags for a period.
     */
    private function loadComparisonFlags(string $billingPeriod): array
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS fiber_comparison_flags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                billing_period TEXT NOT NULL, service_id TEXT NOT NULL,
                disputed INTEGER DEFAULT 0, dispute_reason TEXT DEFAULT '',
                dead_install INTEGER DEFAULT 0, acct_remarks TEXT DEFAULT '',
                flagged_by TEXT DEFAULT '', flagged_at TEXT NOT NULL DEFAULT '',
                UNIQUE(billing_period, service_id)
            )");
            $stmt = $this->db->prepare("SELECT * FROM fiber_comparison_flags WHERE billing_period = ?");
            $stmt->execute([$billingPeriod]);
            $flags = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $flags[$r['service_id']] = $r;
            }
            return $flags;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get dispute summary for a period.
     */
    public function getDisputeSummary(string $billingPeriod): array
    {
        $flags = $this->loadComparisonFlags($billingPeriod);
        $disputed = array_filter($flags, function($f) { return (int)($f['disputed'] ?? 0) === 1; });
        $dead = array_filter($flags, function($f) { return (int)($f['dead_install'] ?? 0) === 1; });
        return [
            'period'          => $billingPeriod,
            'total_flags'     => count($flags),
            'disputed'        => count($disputed),
            'dead_installs'   => count($dead),
            'disputed_items'  => array_values($disputed),
            'dead_items'      => array_values($dead),
        ];
    }
}
