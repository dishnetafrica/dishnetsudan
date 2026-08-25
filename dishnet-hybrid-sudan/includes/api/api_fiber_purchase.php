<?php
// API domain: fiber_purchase_*
// Handles supplier invoice CRUD, reconciliation, plan costs, dashboard

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

require_once dirname(__DIR__, 2) . '/lib/FiberPurchaseService.php';
require_once dirname(__DIR__, 2) . '/lib/SplynxApiClient.php';

$fpSvc = new FiberPurchaseService($store->getPdo(), $config);

// ── Dashboard stats ────────────────────────────────────────────────────
if ($act === 'fiber_purchase_dashboard' && $met === 'GET') {
    $ok2($fpSvc->getDashboardStats(), 'Fiber purchase dashboard');
}

// ── List invoices ──────────────────────────────────────────────────────
if ($act === 'fiber_purchase_invoices' && $met === 'GET') {
    $filters = [];
    if (!empty($_GET['supplier']))       $filters['supplier']       = $_GET['supplier'];
    if (!empty($_GET['billing_period'])) $filters['billing_period'] = $_GET['billing_period'];
    if (!empty($_GET['status']))         $filters['status']         = $_GET['status'];
    $limit  = max(1, min(200, (int)($_GET['limit']  ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $ok2($fpSvc->listInvoices($filters, $limit, $offset), 'Invoices');
}

// ── Get single invoice ─────────────────────────────────────────────────
if ($act === 'fiber_purchase_invoice' && $met === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) $er2('id required', 422);
    $inv = $fpSvc->getInvoice($id);
    if (!$inv) $er2('Invoice not found', 404);
    $ok2($inv, 'Invoice details');
}

// ── Create invoice ─────────────────────────────────────────────────────
if ($act === 'fiber_purchase_invoice_create' && $met === 'POST') {
    $required = ['supplier', 'invoice_number', 'total_amount'];
    foreach ($required as $f) {
        if (empty($_POST[$f]) && empty($body[$f])) $er2("{$f} required", 422);
    }
    $data = array_merge($_POST, $body ?? []);
    $data['created_by'] = $retailer['name'] ?? 'Admin';

    // Parse line items from JSON string if needed
    if (isset($data['line_items']) && is_string($data['line_items'])) {
        $parsed = json_decode($data['line_items'], true);
        if (is_array($parsed)) $data['line_items'] = $parsed;
    }

    $id = $fpSvc->createInvoice($data);
    $ok2(['id' => $id, 'invoice' => $fpSvc->getInvoice($id)], 'Invoice created');
}

// ── Update invoice ─────────────────────────────────────────────────────
if ($act === 'fiber_purchase_invoice_update' && $met === 'POST') {
    $id = (int)($_POST['id'] ?? $body['id'] ?? 0);
    if (!$id) $er2('id required', 422);
    $data = array_merge($_POST, $body ?? []);
    unset($data['id']);
    $ok = $fpSvc->updateInvoice($id, $data);
    if (!$ok) $er2('Update failed — invoice may be locked or not found', 422);
    $ok2($fpSvc->getInvoice($id), 'Invoice updated');
}

// ── Delete invoice ─────────────────────────────────────────────────────
if ($act === 'fiber_purchase_invoice_delete' && $met === 'POST') {
    $id = (int)($_POST['id'] ?? $body['id'] ?? 0);
    if (!$id) $er2('id required', 422);
    if (!$fpSvc->deleteInvoice($id)) $er2('Cannot delete — only "received" invoices can be deleted', 422);
    $ok2(['deleted' => true], 'Invoice deleted');
}

// ── Lifecycle transitions ──────────────────────────────────────────────
if ($act === 'fiber_purchase_invoice_verify' && $met === 'POST') {
    $id = (int)($_POST['id'] ?? $body['id'] ?? 0);
    if (!$id) $er2('id required', 422);
    if (!$fpSvc->verifyInvoice($id, $retailer['name'] ?? 'Admin'))
        $er2('Verify failed — invoice must be in "received" status', 422);
    $ok2($fpSvc->getInvoice($id), 'Invoice verified');
}

if ($act === 'fiber_purchase_invoice_approve' && $met === 'POST') {
    $id = (int)($_POST['id'] ?? $body['id'] ?? 0);
    if (!$id) $er2('id required', 422);
    if (!$fpSvc->approveInvoice($id, $retailer['name'] ?? 'Admin'))
        $er2('Approve failed — invoice must be in "verified" status', 422);
    $ok2($fpSvc->getInvoice($id), 'Invoice approved');
}

if ($act === 'fiber_purchase_invoice_pay' && $met === 'POST') {
    $id  = (int)($_POST['id'] ?? $body['id'] ?? 0);
    $ref = trim($_POST['payment_ref'] ?? $body['payment_ref'] ?? '');
    if (!$id) $er2('id required', 422);
    if (!$ref) $er2('payment_ref required', 422);
    if (!$fpSvc->markPaid($id, $ref))
        $er2('Mark paid failed — invoice must be in "approved" status', 422);
    $ok2($fpSvc->getInvoice($id), 'Invoice marked as paid');
}

// ── Reconciliation ─────────────────────────────────────────────────────
if ($act === 'fiber_purchase_reconcile' && $met === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $result = $fpSvc->reconcileInvoice($id);
    } else {
        $period = $_GET['period'] ?? date('Y-m');
        $result = $fpSvc->calculateExpected($period);
    }
    $ok2($result, 'Reconciliation');
}

// ── Expected cost breakdown ────────────────────────────────────────────
if ($act === 'fiber_purchase_expected' && $met === 'GET') {
    $period = $_GET['period'] ?? date('Y-m');
    $ok2($fpSvc->calculateExpected($period), 'Expected costs');
}

// ── Plan costs ─────────────────────────────────────────────────────────
if ($act === 'fiber_purchase_plan_costs' && $met === 'GET') {
    $supplier = $_GET['supplier'] ?? '';
    $ok2(['costs' => $fpSvc->getCurrentCosts($supplier)], 'Current plan costs');
}

if ($act === 'fiber_purchase_plan_cost_update' && $met === 'POST') {
    $supplier = trim($_POST['supplier'] ?? $body['supplier'] ?? '');
    $plan     = trim($_POST['plan_name'] ?? $body['plan_name'] ?? '');
    $cost     = (float)($_POST['cost_per_unit'] ?? $body['cost_per_unit'] ?? 0);
    $from     = trim($_POST['effective_from'] ?? $body['effective_from'] ?? date('Y-m-d'));
    if (!$supplier || !$plan) $er2('supplier and plan_name required', 422);
    $id = $fpSvc->recordPlanCost($supplier, $plan, $cost, $from);
    $ok2(['id' => $id, 'costs' => $fpSvc->getCurrentCosts()], 'Plan cost updated');
}

if ($act === 'fiber_purchase_cost_history' && $met === 'GET') {
    $plan = $_GET['plan_name'] ?? '';
    if (!$plan) $er2('plan_name required', 422);
    $ok2($fpSvc->getCostHistory($plan, $_GET['supplier'] ?? ''), 'Cost history');
}

// ── Trend data ─────────────────────────────────────────────────────────
if ($act === 'fiber_purchase_trend' && $met === 'GET') {
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $ok2($fpSvc->getTrend($months), 'Cost trend');
}

// ── Post to cashbook (paid → posted) ───────────────────────────────────
if ($act === 'fiber_purchase_invoice_post' && $met === 'POST') {
    $id = (int)($_POST['id'] ?? $body['id'] ?? 0);
    if (!$id) $er2('id required', 422);
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cbSvc  = new CashbookService($store, $dataDir);
    $result = $fpSvc->postToCashbook($id, $cbSvc, $retailer['name'] ?? 'Admin');
    if (!$result['ok']) $er2($result['error'], 422);
    // WhatsApp notification
    try {
        $inv = $fpSvc->getInvoice($id);
        $notify->sendAdmin(
            "✅ *Fiber Invoice Posted to Cashbook*\n\n"
            . "🧾 Invoice: #{$inv['invoice_number']}\n"
            . "🏢 Supplier: {$inv['supplier']}\n"
            . "💰 Amount: \${$inv['total_amount']}\n"
            . "📅 Period: {$inv['billing_period']}\n"
            . "👤 By: " . ($retailer['name'] ?? 'Admin'),
            'fiber_invoice_posted'
        );
    } catch (\Throwable $e) {}
    $ok2($result, 'Invoice posted to cashbook');
}

// ── Take monthly snapshot ──────────────────────────────────────────────
if ($act === 'fiber_purchase_snapshot_take' && $met === 'POST') {
    $period = trim($_POST['period'] ?? $body['period'] ?? date('Y-m'));
    $ok2($fpSvc->takeSnapshot($period), 'Snapshot');
}

if ($act === 'fiber_purchase_snapshot' && $met === 'GET') {
    $period = $_GET['period'] ?? date('Y-m');
    $snap = $fpSvc->getSnapshot($period);
    $ok2($snap ?: ['error' => 'No snapshot for ' . $period], $snap ? 'Snapshot' : 'Not found');
}

// ── Price change detection ─────────────────────────────────────────────
if ($act === 'fiber_purchase_price_changes' && $met === 'GET') {
    $period = $_GET['period'] ?? date('Y-m');
    $ok2($fpSvc->detectPriceChanges($period), 'Price changes');
}

// ── Leakage detection ──────────────────────────────────────────────────
if ($act === 'fiber_purchase_leakage' && $met === 'GET') {
    $ok2($fpSvc->detectLeakage(), 'Leakage detection');
}

// ── Missing invoice check ──────────────────────────────────────────────
if ($act === 'fiber_purchase_missing_check' && $met === 'GET') {
    $period  = $_GET['period'] ?? date('Y-m');
    $alertDay = (int)($_GET['alert_day'] ?? $config['fiber_invoice_alert_day'] ?? 10);
    $ok2($fpSvc->checkMissingInvoice($period, $alertDay), 'Missing invoice check');
}

// ── Import data from Fiber Finance backup ─────────────────────────────
if ($act === 'fiber_import_data' && $met === 'POST') {
    require_once dirname(__DIR__, 2) . '/lib/fiber_data_import.php';
    $sourceDir = '';
    if (!empty($body['source_dir'])) {
        $sourceDir = $body['source_dir'];
    } else {
        $_pr = dirname(__DIR__, 2);
        $_dd = method_exists($store, 'getDataDir') ? $store->getDataDir() : $_pr . '/data';
        foreach ([$_dd . '/fiber_backup', $_pr . '/data/fiber_backup', $_pr . '/fiber_backup'] as $_td) {
            if (is_dir($_td) && count(glob($_td . '/*.json')) > 0) { $sourceDir = $_td; break; }
        }
    }
    if (!$sourceDir || !is_dir($sourceDir)) $er2("Backup directory not found. Upload to data/fiber_backup/", 422);
    $result = importFiberData($store->getPdo(), $sourceDir);
    $ok2($result, 'Import complete');
}

// ── Run reconciliation engine ─────────────────────────────────────────
if ($act === 'fiber_run_reconciliation' && $met === 'POST') {
    require_once dirname(__DIR__, 2) . '/lib/FiberFinanceEngine.php';
    $ffEng = new FiberFinanceEngine($store->getPdo(), $config);
    $autoFix = (bool)($body['auto_fix'] ?? false);
    $force   = (bool)($body['force'] ?? false);
    $ok2($ffEng->runReconciliation($autoFix, $force), 'Reconciliation');
}

// ── Full KPI cache ────────────────────────────────────────────────────
if ($act === 'fiber_kpi' && $met === 'GET') {
    require_once dirname(__DIR__, 2) . '/lib/FiberFinanceEngine.php';
    $ffEng = new FiberFinanceEngine($store->getPdo(), $config);
    $ok2($ffEng->buildKpiCache(), 'KPI cache');
}

// ── Churn analytics ───────────────────────────────────────────────────
if ($act === 'fiber_churn' && $met === 'GET') {
    require_once dirname(__DIR__, 2) . '/lib/FiberFinanceEngine.php';
    $ffEng = new FiberFinanceEngine($store->getPdo(), $config);
    $from = $_GET['from'] ?? date('Y-m-01', strtotime('-3 months'));
    $to   = $_GET['to']   ?? date('Y-m-d');
    $ok2($ffEng->calculateChurn($from, $to), 'Churn analytics');
}

// ── Sync health ───────────────────────────────────────────────────────
if ($act === 'fiber_sync_health' && $met === 'GET') {
    require_once dirname(__DIR__, 2) . '/lib/FiberFinanceEngine.php';
    $ffEng = new FiberFinanceEngine($store->getPdo(), $config);
    $ok2($ffEng->getSyncHealth(), 'Sync health');
}

// ── Predicted invoice ─────────────────────────────────────────────────
if ($act === 'fiber_predicted_invoice' && $met === 'GET') {
    $period = $_GET['period'] ?? date('Y-m');
    $ok2($fpSvc->generatePredictedInvoice($period), 'Predicted invoice');
}

if ($act === 'fiber_reconcile_prediction' && $met === 'POST') {
    $period = trim($body['period'] ?? $_POST['period'] ?? date('Y-m'));
    $actual = (float)($body['actual_amount'] ?? $_POST['actual_amount'] ?? 0);
    $ref    = trim($body['invoice_ref'] ?? $_POST['invoice_ref'] ?? '');
    if ($actual <= 0) $er2('actual_amount required', 422);
    $ok2($fpSvc->reconcilePrediction($period, $actual, $ref), 'Reconciliation');
}

// ── Supplier invoice comparison (line-by-line diff) ───────────────────
if ($act === 'fiber_compare_supplier' && $met === 'POST') {
    $period = trim($body['period'] ?? $_POST['period'] ?? date('Y-m'));
    $actual = (float)($body['supplier_total'] ?? $_POST['supplier_total'] ?? 0);
    $ref    = trim($body['supplier_ref'] ?? $_POST['supplier_ref'] ?? '');
    $lines  = $body['supplier_lines'] ?? [];
    if ($actual <= 0) $er2('supplier_total required', 422);
    $ok2($fpSvc->compareWithSupplier($period, $actual, $ref, $lines), 'Comparison');
}

if ($act === 'fiber_save_dispute' && $met === 'POST') {
    $period = trim($body['period'] ?? $_POST['period'] ?? '');
    $sid    = trim($body['service_id'] ?? $_POST['service_id'] ?? '');
    if (!$period || !$sid) $er2('period and service_id required', 422);
    $fpSvc->saveComparisonFlag($period, $sid, [
        'disputed'       => (int)($body['disputed'] ?? $_POST['disputed'] ?? 0),
        'dispute_reason' => trim($body['dispute_reason'] ?? $_POST['dispute_reason'] ?? ''),
        'dead_install'   => (int)($body['dead_install'] ?? $_POST['dead_install'] ?? 0),
        'acct_remarks'   => trim($body['acct_remarks'] ?? $_POST['acct_remarks'] ?? ''),
        'flagged_by'     => $retailer['name'] ?? 'Admin',
    ]);
    $ok2(['saved' => true], 'Flag saved');
}

if ($act === 'fiber_dispute_summary' && $met === 'GET') {
    $period = $_GET['period'] ?? date('Y-m');
    $ok2($fpSvc->getDisputeSummary($period), 'Dispute summary');
}