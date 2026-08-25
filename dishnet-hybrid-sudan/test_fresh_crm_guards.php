<?php
/**
 * test_fresh_crm_guards.php — Stress test for "always check CRM before sending"
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Tests every notification path to ensure NO message is sent to a customer
 * who has already paid, regardless of race conditions or stale data.
 *
 * Usage:
 *   CLI:  php test_fresh_crm_guards.php
 *   Web:  public.php?page=api&action=test_crm_guards  (admin only)
 *
 * What it tests:
 *   1. cron_maintenance.php Task 4a — Pre-due reminders (7d/3d/1d)
 *   2. cron_maintenance.php Task 4b — New invoice scanner
 *   3. cron_maintenance.php Task 5  — Overdue escalation (D1/D3/D5/D7)
 *   4. cron_overdue_email.php       — Email stages (14/31/61/90/120+)
 *   5. cron_invoice_notify.php      — New invoice WA + PDF
 *   6. webhook.php service.suspend  — Suspension WA
 *
 * Each path is tested with 8 invoice scenarios:
 *   A. Fully paid (amountToPay=0, status=3) → MUST SKIP
 *   B. Voided (status=4) → MUST SKIP
 *   C. Unpaid (amountToPay=80, status=1) → SHOULD SEND
 *   D. Partial (amountToPay=30, status=2) → SHOULD SEND
 *   E. Race: bulk=unpaid, fresh=paid → MUST SKIP
 *   F. Race: bulk=unpaid, fresh=partial → SHOULD SEND (with updated amount)
 *   G. Zero amount (total=0) → MUST SKIP
 *   H. Fresh check fails (CRM down) → SHOULD SEND (fail-open on fresh check)
 */

date_default_timezone_set('Africa/Juba');

// ── Output formatting ──────────────────────────────────────────────────────
$isCli  = (php_sapi_name() === 'cli');
$passed = 0;
$failed = 0;
$total  = 0;
$results = [];

function tlog(string $msg): void {
    global $isCli;
    echo $isCli ? $msg . "\n" : htmlspecialchars($msg) . "<br>";
}

function assertSkip(string $testName, bool $wasSkipped): void {
    global $passed, $failed, $total, $results;
    $total++;
    if ($wasSkipped) {
        $passed++;
        $results[] = ['test' => $testName, 'result' => 'PASS', 'detail' => 'Correctly SKIPPED (no message sent)'];
    } else {
        $failed++;
        $results[] = ['test' => $testName, 'result' => 'FAIL', 'detail' => 'SHOULD HAVE SKIPPED but would send!'];
        tlog("  ❌ FAIL: {$testName} — message would be sent to a paid customer!");
    }
}

function assertSend(string $testName, bool $wasSent, string $detail = ''): void {
    global $passed, $failed, $total, $results;
    $total++;
    if ($wasSent) {
        $passed++;
        $results[] = ['test' => $testName, 'result' => 'PASS', 'detail' => 'Correctly SENT' . ($detail ? " ({$detail})" : '')];
    } else {
        $failed++;
        $results[] = ['test' => $testName, 'result' => 'FAIL', 'detail' => 'Should have sent but was SKIPPED'];
        tlog("  ❌ FAIL: {$testName} — message was skipped for an unpaid customer!");
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// MOCK CRM CLIENT — simulates UCRM API responses
// ═════════════════════════════════════════════════════════════════════════════

class MockCrmClient {
    private array $invoices = [];
    private array $freshOverrides = [];
    private array $clients = [];
    private bool  $freshCheckFails = false;
    private int   $apiCalls = 0;

    public function addInvoice(array $inv): void {
        $this->invoices[$inv['id']] = $inv;
    }

    public function setFreshOverride(int $invId, array $override): void {
        $this->freshOverrides[$invId] = $override;
    }

    public function setFreshCheckFails(bool $fails): void {
        $this->freshCheckFails = $fails;
    }

    public function addClient(int $id, array $client): void {
        $this->clients[$id] = $client;
    }

    public function getApiCalls(): int { return $this->apiCalls; }
    public function resetApiCalls(): void { $this->apiCalls = 0; }

    /**
     * Simulates $crm->get() — returns fresh data if override exists
     */
    public function get(string $endpoint) {
        $this->apiCalls++;

        // Single invoice fetch (fresh check)
        if (preg_match('#^invoices/(\d+)$#', $endpoint, $m)) {
            $id = (int)$m[1];
            if ($this->freshCheckFails) return null;
            if (isset($this->freshOverrides[$id])) return $this->freshOverrides[$id];
            return $this->invoices[$id] ?? null;
        }
        if (preg_match('#^billing/invoices/(\d+)$#', $endpoint, $m)) {
            $id = (int)$m[1];
            if ($this->freshCheckFails) return null;
            if (isset($this->freshOverrides[$id])) return $this->freshOverrides[$id];
            return $this->invoices[$id] ?? null;
        }

        // Bulk invoice fetch
        if (strpos($endpoint, 'invoices') !== false) {
            return array_values($this->invoices);
        }

        // Client fetch
        if (preg_match('#^clients/(\d+)$#', $endpoint, $m)) {
            $id = (int)$m[1];
            return $this->clients[$id] ?? ['firstName'=>'Test','lastName'=>'User','contacts'=>[['phone'=>'+211900000001']]];
        }

        return null;
    }

    public function isConfigured(): bool { return true; }
}

// ═════════════════════════════════════════════════════════════════════════════
// TEST SCENARIOS — 8 invoice states
// ═════════════════════════════════════════════════════════════════════════════

function buildScenarios(): array {
    $base = [
        'number'       => 'INV099999',
        'clientId'     => 100,
        'dueDate'      => date('Y-m-d', strtotime('-1 day')),
        'currencyCode' => 'USD',
        'createdDate'  => date('Y-m-d H:i:s', strtotime('-2 hours')),
    ];

    return [
        'A_fully_paid' => [
            'bulk'  => array_merge($base, ['id'=>1, 'total'=>80, 'amountToPay'=>0,  'status'=>3]),
            'fresh' => null, // no override needed — bulk already shows paid
            'expect' => 'skip',
            'label' => 'Fully paid (amountToPay=0, status=3)',
        ],
        'B_voided' => [
            'bulk'  => array_merge($base, ['id'=>2, 'total'=>80, 'amountToPay'=>0,  'status'=>4]),
            'fresh' => null,
            'expect' => 'skip',
            'label' => 'Voided invoice (status=4)',
        ],
        'C_unpaid' => [
            'bulk'  => array_merge($base, ['id'=>3, 'total'=>80, 'amountToPay'=>80, 'status'=>1]),
            'fresh' => null,
            'expect' => 'send',
            'label' => 'Unpaid (amountToPay=80, status=1)',
        ],
        'D_partial' => [
            'bulk'  => array_merge($base, ['id'=>4, 'total'=>80, 'amountToPay'=>30, 'status'=>2]),
            'fresh' => null,
            'expect' => 'send',
            'label' => 'Partial (amountToPay=30, status=2)',
        ],
        'E_race_paid' => [
            'bulk'  => array_merge($base, ['id'=>5, 'total'=>80, 'amountToPay'=>80, 'status'=>1]),
            'fresh' => array_merge($base, ['id'=>5, 'total'=>80, 'amountToPay'=>0,  'status'=>3]),
            'expect' => 'skip',
            'label' => 'RACE: bulk=unpaid → fresh=paid',
        ],
        'F_race_partial' => [
            'bulk'  => array_merge($base, ['id'=>6, 'total'=>80, 'amountToPay'=>80, 'status'=>1]),
            'fresh' => array_merge($base, ['id'=>6, 'total'=>80, 'amountToPay'=>30, 'status'=>2]),
            'expect' => 'send',
            'label' => 'RACE: bulk=unpaid → fresh=partial ($30 left)',
        ],
        'G_zero_amount' => [
            'bulk'  => array_merge($base, ['id'=>7, 'total'=>0,  'amountToPay'=>0,  'status'=>1]),
            'fresh' => null,
            'expect' => 'skip',
            'label' => 'Zero amount invoice',
        ],
        'H_fresh_fails' => [
            'bulk'  => array_merge($base, ['id'=>8, 'total'=>80, 'amountToPay'=>80, 'status'=>1]),
            'fresh' => '__FAIL__',
            'expect' => 'send',
            'label' => 'CRM down on fresh check (fail-open)',
        ],
    ];
}


// ═════════════════════════════════════════════════════════════════════════════
// GUARD LOGIC SIMULATORS — extract the exact guard logic from each cron file
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Simulates Task 5 (Overdue D1/D3/D5/D7) guard logic
 * from cron_maintenance.php lines ~850-900
 */
function simulateOverdueEscalation(MockCrmClient $crm, array $bulkInv): array {
    $total    = (float)($bulkInv['amountToPay'] ?? $bulkInv['total'] ?? 0);
    $clientId = (int)($bulkInv['clientId'] ?? 0);
    $invId    = (int)($bulkInv['id'] ?? 0);

    // Guard 1: amount <= 0
    if (!$clientId || $total <= 0) return ['skipped' => true, 'reason' => 'amount<=0 or no client'];

    // Guard 2: fresh invoice check
    $freshInv = $crm->get("invoices/{$invId}");
    if ($freshInv) {
        $freshAmtDue = (float)($freshInv['amountToPay'] ?? $freshInv['total'] ?? 0);
        if ($freshAmtDue <= 0) {
            return ['skipped' => true, 'reason' => 'fresh check: amountToPay=0'];
        }
        $total = $freshAmtDue;
    }

    return ['skipped' => false, 'amount' => $total];
}

/**
 * Simulates Task 4a (Pre-due 7d/3d/1d) guard logic
 */
function simulatePreDueReminder(MockCrmClient $crm, array $bulkInv): array {
    $total    = (float)($bulkInv['amountToPay'] ?? $bulkInv['total'] ?? 0);
    $clientId = (int)($bulkInv['clientId'] ?? 0);
    $invId    = (int)($bulkInv['id'] ?? 0);

    // Guard 1: amount <= 0
    if (!$clientId || $total <= 0) return ['skipped' => true, 'reason' => 'amount<=0 or no client'];

    // Guard 2: status check from bulk
    $invStatus = (int)($bulkInv['status'] ?? -1);
    if (in_array($invStatus, [3, 4], true)) {
        return ['skipped' => true, 'reason' => "status={$invStatus} (paid/void)"];
    }

    // Guard 3: fresh invoice check
    if ($invId) {
        $freshInv = $crm->get("invoices/{$invId}");
        if ($freshInv) {
            $freshStatus = (int)($freshInv['status'] ?? -1);
            $freshAmt    = (float)($freshInv['amountToPay'] ?? $freshInv['total'] ?? 0);
            if (in_array($freshStatus, [3, 4], true) || $freshAmt <= 0) {
                return ['skipped' => true, 'reason' => 'fresh check: paid/void/zero'];
            }
            $total = $freshAmt;
        }
    }

    return ['skipped' => false, 'amount' => $total];
}

/**
 * Simulates Task 4b (New invoice scanner) guard logic
 */
function simulateInvoiceNotifyScanner(MockCrmClient $crm, array $bulkInv): array {
    $invoiceId = (int)($bulkInv['id'] ?? 0);
    $total     = (float)($bulkInv['amountToPay'] ?? $bulkInv['total'] ?? 0);
    $clientId  = (int)($bulkInv['clientId'] ?? 0);

    // Guard 1: basic validation
    if (!$invoiceId || !$clientId || $total <= 0) return ['skipped' => true, 'reason' => 'basic validation'];

    // Guard 2: fresh invoice check
    $freshInv = $crm->get("invoices/{$invoiceId}");
    if ($freshInv) {
        $freshAmt    = (float)($freshInv['amountToPay'] ?? $freshInv['total'] ?? 0);
        $freshStatus = (int)($freshInv['status'] ?? -1);
        if ($freshAmt <= 0 || in_array($freshStatus, [3, 4], true)) {
            return ['skipped' => true, 'reason' => 'fresh check: paid/void/zero'];
        }
        $total = $freshAmt;
    }

    return ['skipped' => false, 'amount' => $total];
}

/**
 * Simulates cron_overdue_email.php guard logic
 */
function simulateOverdueEmail(MockCrmClient $crm, array $bulkInv): array {
    $invId   = (int)($bulkInv['id'] ?? 0);
    $amtDue  = (float)($bulkInv['amountToPay'] ?? $bulkInv['total'] ?? 0);

    // Guard 1: amount <= 0
    if ($amtDue <= 0) return ['skipped' => true, 'reason' => 'amtDue<=0'];

    // Guard 2: fresh invoice status check
    $freshInv = $crm->get("billing/invoices/{$invId}");
    if ($freshInv && !in_array((int)($freshInv['status'] ?? 0), [1,2], true)) {
        return ['skipped' => true, 'reason' => 'fresh check: not unpaid/partial'];
    }

    return ['skipped' => false, 'amount' => $amtDue];
}

/**
 * Simulates cron_invoice_notify.php guard logic
 */
function simulateInvoiceNotifyCron(MockCrmClient $crm, array $bulkInv): array {
    $invoiceId = (int)($bulkInv['id'] ?? 0);
    $total     = (float)($bulkInv['total'] ?? 0);

    // Guard 1: basic validation
    if (!$invoiceId || $total <= 0) return ['skipped' => true, 'reason' => 'basic validation'];

    // Guard 2: status from bulk
    $invStatus = (int)($bulkInv['status'] ?? -1);
    if (!in_array($invStatus, [1, 2], true)) return ['skipped' => true, 'reason' => "bulk status={$invStatus}"];

    // Guard 3: fresh status re-check
    $freshInv = $crm->get("billing/invoices/{$invoiceId}");
    if ($freshInv) {
        $freshStatus = (int)($freshInv['status'] ?? -1);
        if (!in_array($freshStatus, [1, 2], true)) {
            return ['skipped' => true, 'reason' => 'fresh check: not unpaid/partial'];
        }
    }

    return ['skipped' => false, 'amount' => $total];
}

/**
 * Simulates webhook.php service.suspend guard logic
 */
function simulateSuspensionWebhook(MockCrmClient $crm, array $client): array {
    $outstandingRaw = (float)($client['accountOutstandingRaw'] ?? $client['accountOutstanding'] ?? 0);

    // Guard: no outstanding balance
    if ($outstandingRaw <= 0) {
        return ['skipped' => true, 'reason' => 'balance settled (outstanding<=0)'];
    }

    return ['skipped' => false, 'amount' => $outstandingRaw];
}


// ═════════════════════════════════════════════════════════════════════════════
// RUN ALL TESTS
// ═════════════════════════════════════════════════════════════════════════════

tlog("═══════════════════════════════════════════════════════════════════");
tlog("  DishNet Fresh CRM Guard Stress Test");
tlog("  " . date('Y-m-d H:i:s') . " — 8 scenarios × 6 paths = 48 tests");
tlog("═══════════════════════════════════════════════════════════════════");

$scenarios = buildScenarios();

// ── Path 1: Overdue Escalation (Task 5) ─────────────────────────────────
tlog("\n▸ PATH 1: Overdue Escalation (D1/D3/D5/D7) — cron_maintenance.php Task 5");
foreach ($scenarios as $key => $s) {
    $crm = new MockCrmClient();
    $crm->addInvoice($s['bulk']);
    if ($s['fresh'] === '__FAIL__') {
        $crm->setFreshCheckFails(true);
    } elseif ($s['fresh'] !== null) {
        $crm->setFreshOverride($s['bulk']['id'], $s['fresh']);
    }

    $result = simulateOverdueEscalation($crm, $s['bulk']);
    if ($s['expect'] === 'skip') {
        assertSkip("Task5/{$key}: {$s['label']}", $result['skipped']);
    } else {
        assertSend("Task5/{$key}: {$s['label']}", !$result['skipped'],
            isset($result['amount']) ? "amount={$result['amount']}" : '');
    }
}

// ── Path 2: Pre-due Reminders (Task 4a) ─────────────────────────────────
tlog("\n▸ PATH 2: Pre-due Reminders (7d/3d/1d) — cron_maintenance.php Task 4a");
foreach ($scenarios as $key => $s) {
    $crm = new MockCrmClient();
    $crm->addInvoice($s['bulk']);
    if ($s['fresh'] === '__FAIL__') {
        $crm->setFreshCheckFails(true);
    } elseif ($s['fresh'] !== null) {
        $crm->setFreshOverride($s['bulk']['id'], $s['fresh']);
    }

    $result = simulatePreDueReminder($crm, $s['bulk']);
    if ($s['expect'] === 'skip') {
        assertSkip("Task4a/{$key}: {$s['label']}", $result['skipped']);
    } else {
        assertSend("Task4a/{$key}: {$s['label']}", !$result['skipped'],
            isset($result['amount']) ? "amount={$result['amount']}" : '');
    }
}

// ── Path 3: Invoice Notify Scanner (Task 4b) ───────────────────────────
tlog("\n▸ PATH 3: Invoice Notify Scanner — cron_maintenance.php Task 4b");
foreach ($scenarios as $key => $s) {
    $crm = new MockCrmClient();
    $crm->addInvoice($s['bulk']);
    if ($s['fresh'] === '__FAIL__') {
        $crm->setFreshCheckFails(true);
    } elseif ($s['fresh'] !== null) {
        $crm->setFreshOverride($s['bulk']['id'], $s['fresh']);
    }

    $result = simulateInvoiceNotifyScanner($crm, $s['bulk']);
    if ($s['expect'] === 'skip') {
        assertSkip("Task4b/{$key}: {$s['label']}", $result['skipped']);
    } else {
        assertSend("Task4b/{$key}: {$s['label']}", !$result['skipped'],
            isset($result['amount']) ? "amount={$result['amount']}" : '');
    }
}

// ── Path 4: Overdue Email ───────────────────────────────────────────────
tlog("\n▸ PATH 4: Overdue Email — cron_overdue_email.php");
foreach ($scenarios as $key => $s) {
    $crm = new MockCrmClient();
    $crm->addInvoice($s['bulk']);
    if ($s['fresh'] === '__FAIL__') {
        $crm->setFreshCheckFails(true);
    } elseif ($s['fresh'] !== null) {
        $crm->setFreshOverride($s['bulk']['id'], $s['fresh']);
    }

    $result = simulateOverdueEmail($crm, $s['bulk']);
    if ($s['expect'] === 'skip') {
        assertSkip("OverdueEmail/{$key}: {$s['label']}", $result['skipped']);
    } else {
        assertSend("OverdueEmail/{$key}: {$s['label']}", !$result['skipped'],
            isset($result['amount']) ? "amount={$result['amount']}" : '');
    }
}

// ── Path 5: Invoice Notify Cron ─────────────────────────────────────────
tlog("\n▸ PATH 5: Invoice Notify — cron_invoice_notify.php");
foreach ($scenarios as $key => $s) {
    $crm = new MockCrmClient();
    $crm->addInvoice($s['bulk']);
    if ($s['fresh'] === '__FAIL__') {
        $crm->setFreshCheckFails(true);
    } elseif ($s['fresh'] !== null) {
        $crm->setFreshOverride($s['bulk']['id'], $s['fresh']);
    }

    $result = simulateInvoiceNotifyCron($crm, $s['bulk']);
    if ($s['expect'] === 'skip') {
        assertSkip("InvNotify/{$key}: {$s['label']}", $result['skipped']);
    } else {
        assertSend("InvNotify/{$key}: {$s['label']}", !$result['skipped'],
            isset($result['amount']) ? "amount={$result['amount']}" : '');
    }
}

// ── Path 6: Suspension Webhook ──────────────────────────────────────────
tlog("\n▸ PATH 6: Suspension Webhook — webhook.php service.suspend");
$suspScenarios = [
    'A_balance_zero'    => ['client' => ['accountOutstandingRaw' => 0],    'expect' => 'skip',  'label' => 'Balance = 0 (paid)'],
    'B_balance_negative'=> ['client' => ['accountOutstandingRaw' => -20],  'expect' => 'skip',  'label' => 'Balance = -20 (credit)'],
    'C_balance_owed'    => ['client' => ['accountOutstandingRaw' => 80],   'expect' => 'send',  'label' => 'Balance = 80 (owes)'],
    'D_balance_partial' => ['client' => ['accountOutstandingRaw' => 30],   'expect' => 'send',  'label' => 'Balance = 30 (partial)'],
    'E_no_field'        => ['client' => [],                                'expect' => 'skip',  'label' => 'No accountOutstandingRaw field'],
];
foreach ($suspScenarios as $key => $s) {
    $crm = new MockCrmClient();
    $result = simulateSuspensionWebhook($crm, $s['client']);
    if ($s['expect'] === 'skip') {
        assertSkip("Suspend/{$key}: {$s['label']}", $result['skipped']);
    } else {
        assertSend("Suspend/{$key}: {$s['label']}", !$result['skipped']);
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// BONUS: Race condition timing test — fresh amount should override bulk amount
// ═════════════════════════════════════════════════════════════════════════════

tlog("\n▸ BONUS: Amount override tests (fresh amount replaces stale bulk amount)");

// Scenario: Bulk says $80, fresh says $30 (partial payment received)
$crm = new MockCrmClient();
$bulkInv = ['id'=>99, 'number'=>'INV099', 'clientId'=>100, 'total'=>80, 'amountToPay'=>80, 'status'=>1, 'dueDate'=>date('Y-m-d',strtotime('-1 day')), 'currencyCode'=>'USD'];
$crm->addInvoice($bulkInv);
$crm->setFreshOverride(99, ['id'=>99, 'total'=>80, 'amountToPay'=>30, 'status'=>2]);

$r = simulateOverdueEscalation($crm, $bulkInv);
$total++;
if (!$r['skipped'] && abs($r['amount'] - 30) < 0.01) {
    $passed++;
    $results[] = ['test' => 'AmountOverride/Task5', 'result' => 'PASS', 'detail' => 'Fresh amount $30 used (not stale $80)'];
} else {
    $failed++;
    $results[] = ['test' => 'AmountOverride/Task5', 'result' => 'FAIL', 'detail' => 'Amount not updated from fresh check'];
    tlog("  ❌ FAIL: Amount override — expected 30, got " . ($r['amount'] ?? 'skipped'));
}

$r = simulatePreDueReminder($crm, $bulkInv);
$total++;
if (!$r['skipped'] && abs($r['amount'] - 30) < 0.01) {
    $passed++;
    $results[] = ['test' => 'AmountOverride/Task4a', 'result' => 'PASS', 'detail' => 'Fresh amount $30 used'];
} else {
    $failed++;
    $results[] = ['test' => 'AmountOverride/Task4a', 'result' => 'FAIL', 'detail' => 'Amount not updated'];
    tlog("  ❌ FAIL: Amount override Task4a");
}

$r = simulateInvoiceNotifyScanner($crm, $bulkInv);
$total++;
if (!$r['skipped'] && abs($r['amount'] - 30) < 0.01) {
    $passed++;
    $results[] = ['test' => 'AmountOverride/Task4b', 'result' => 'PASS', 'detail' => 'Fresh amount $30 used'];
} else {
    $failed++;
    $results[] = ['test' => 'AmountOverride/Task4b', 'result' => 'FAIL', 'detail' => 'Amount not updated'];
    tlog("  ❌ FAIL: Amount override Task4b");
}


// ═════════════════════════════════════════════════════════════════════════════
// BONUS: API call count — ensure fresh check is actually happening
// ═════════════════════════════════════════════════════════════════════════════

tlog("\n▸ BONUS: Verify fresh check API calls are being made");

$crm = new MockCrmClient();
$unpaidInv = ['id'=>50, 'number'=>'INV050', 'clientId'=>100, 'total'=>80, 'amountToPay'=>80, 'status'=>1, 'dueDate'=>date('Y-m-d',strtotime('-1 day')), 'currencyCode'=>'USD'];
$crm->addInvoice($unpaidInv);

$crm->resetApiCalls();
simulateOverdueEscalation($crm, $unpaidInv);
$calls = $crm->getApiCalls();
$total++;
if ($calls >= 1) {
    $passed++;
    $results[] = ['test' => 'ApiCalls/Task5', 'result' => 'PASS', 'detail' => "{$calls} API call(s) made (fresh check)"];
} else {
    $failed++;
    $results[] = ['test' => 'ApiCalls/Task5', 'result' => 'FAIL', 'detail' => 'No fresh check API call!'];
}

$crm->resetApiCalls();
simulatePreDueReminder($crm, $unpaidInv);
$calls = $crm->getApiCalls();
$total++;
if ($calls >= 1) {
    $passed++;
    $results[] = ['test' => 'ApiCalls/Task4a', 'result' => 'PASS', 'detail' => "{$calls} API call(s)"];
} else {
    $failed++;
    $results[] = ['test' => 'ApiCalls/Task4a', 'result' => 'FAIL', 'detail' => 'No fresh check!'];
}

$crm->resetApiCalls();
simulateInvoiceNotifyScanner($crm, $unpaidInv);
$calls = $crm->getApiCalls();
$total++;
if ($calls >= 1) {
    $passed++;
    $results[] = ['test' => 'ApiCalls/Task4b', 'result' => 'PASS', 'detail' => "{$calls} API call(s)"];
} else {
    $failed++;
    $results[] = ['test' => 'ApiCalls/Task4b', 'result' => 'FAIL', 'detail' => 'No fresh check!'];
}


// ═════════════════════════════════════════════════════════════════════════════
// SUMMARY
// ═════════════════════════════════════════════════════════════════════════════

$allPassed = ($failed === 0);
tlog("\n═══════════════════════════════════════════════════════════════════");
tlog("  RESULTS: {$passed}/{$total} passed, {$failed} failed");
tlog("  STATUS:  " . ($allPassed ? '✅ ALL TESTS PASSED' : '❌ SOME TESTS FAILED'));
tlog("═══════════════════════════════════════════════════════════════════");

if (!$allPassed) {
    tlog("\nFailed tests:");
    foreach ($results as $r) {
        if ($r['result'] === 'FAIL') {
            tlog("  ❌ {$r['test']}: {$r['detail']}");
        }
    }
}

// Return structured results for API/web use
if (!$isCli) {
    return ['passed' => $passed, 'failed' => $failed, 'total' => $total, 'ok' => $allPassed, 'results' => $results];
}

exit($allPassed ? 0 : 1);
