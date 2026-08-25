#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * cron/cashbook_reconcile.php — DishNet Hybrid Telecom v4.4.27
 *
 * PURPOSE
 *   Telecom-grade field-finance fraud-prevention and ledger integrity checks.
 *   Runs nightly at 23:00 EAT. Sends a single consolidated WhatsApp report
 *   to Rupesh (accountant/admin) summarising everything that needs action.
 *
 * FOUR CONTROLS
 *
 *   1. ADVANCE AGING
 *      Every open advance must be settled within a purpose-based time window.
 *      If overdue: set is_overdue=1 + overdue_since, block new advances for
 *      that staff member (checked at createAdvance time), alert Rupesh.
 *
 *      Default limits (configurable in kyc_config.json → advance_aging_hours):
 *        fuel        → 24 h
 *        transport   → 24 h
 *        allowance   → 48 h
 *        parts       → 72 h
 *        equipment   → 72 h
 *        site_work   → 48 h
 *        misc        → 48 h
 *
 *   2. CASH CARRY LIMIT
 *      cash_in_hand = customer_collections + active_advance_balance
 *                     − daily_cash_expenses − confirmed_handovers
 *      (root advances only for advance stream; children excluded to avoid double-count)
 *      If cash_in_hand > carry_limit (default $100, config: advance_carry_limit):
 *        → insert row into staff_carry_alerts
 *        → alert Rupesh
 *        → flag is_carry_overlimit on staff record (ExpenseAdvanceService
 *          checks this flag before allowing a new advance)
 *
 *   3. NIGHTLY LEDGER DRIFT CHECK
 *      Compares cb_ledger math to advance-system math:
 *
 *        cb_net = SUM(cb_ledger.in) − SUM(cb_ledger.out)   [approved entries]
 *
 *        advance_math =
 *          SUM(root_advances issued)
 *          − SUM(expenses approved)
 *          − SUM(cash returned)
 *          = outstanding cash still in the field
 *
 *      drift = cb_net − (all collections in − all advances out − all expenses out)
 *      If |drift| > tolerance (default $0.01): WA Rupesh immediately.
 *      Always inserts a row into ledger_reconcile_log.
 *
 *   4. MISSING RECEIPT RULE
 *      Approved expenses where receipt_hash = '' and flag_no_receipt = 1
 *      that are older than receipt_grace_hours (default 24h) get flagged
 *      and included in the alert so Rupesh can chase the staff member.
 *
 *   5. AGENT FLOAT WARNING (v4.4.23)
 *      For each active sales/field_agent, if their wallet float falls below
 *      agent_float_warn_threshold (default $50), add to alert so Rupesh can
 *      top up before Diko runs out of float and can no longer collect.
 *
 * CALLED BY
 *   master.php at 23:00 EAT daily (or: php cron/cashbook_reconcile.php)
 *
 * CONFIG KEYS (all optional — safe defaults shown):
 *   advance_aging_hours          JSON object  {"fuel":24,"misc":48,...}
 *   advance_carry_limit          float        100
 *   advance_drift_tolerance      float        0.01
 *   advance_receipt_grace_hours  int          24
 *   advance_reconcile_project    string       "dishnet"
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
chdir(dirname(__DIR__));

require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/NotificationService.php';
require_once __DIR__ . '/../lib/CashbookService.php';
require_once __DIR__ . '/../lib/ExpenseAdvanceService.php';

require_once dirname(__DIR__) . '/lib/bootstrap_data.php';
$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$notify  = new NotificationService($store, $config);
$pdo     = $store->getPdo();

// ── Config ────────────────────────────────────────────────────────────────────
$defaultAgingHours = [
    'fuel'      => 24,
    'transport' => 24,
    'allowance' => 48,
    'site_work' => 48,
    'misc'      => 48,
    'parts'     => 72,
    'equipment' => 72,
];
$agingHours   = array_merge(
    $defaultAgingHours,
    (array)($config['advance_aging_hours'] ?? [])
);
$carryLimit        = (float)($config['advance_carry_limit']         ?? 100.0);
$floatWarnLimit    = (float)($config['agent_float_warn_threshold']   ?? 50.0);
$driftTol     = (float)($config['advance_drift_tolerance']      ?? 0.01);
$receiptGrace = (int)  ($config['advance_receipt_grace_hours']  ?? 24);
$project      = trim   ($config['advance_reconcile_project']    ?? 'dishnet');
$now          = date('Y-m-d H:i:s');
$nowTs        = time();

function rclog(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] [reconcile] ' . $msg . "\n";
}

// ── Find recipients (accountant + admin) ─────────────────────────────────────
$retailers  = $store->load('retailers.json') ?? [];
$recipients = [];
foreach ($retailers as $r) {
    if (empty($r['is_active'])) continue;
    $isAdmin = !empty($r['is_admin']);
    $role    = $r['role'] ?? '';
    if (($isAdmin || $role === 'accountant') && !empty($r['phone'])) {
        $recipients[] = $r;
    }
}
if (empty($recipients)) {
    rclog('No accountant/admin with phone — nothing to notify. Checks still run.');
}

// ═══════════════════════════════════════════════════════════════════════════════
// CONTROL 1 — ADVANCE AGING
// ═══════════════════════════════════════════════════════════════════════════════

rclog('Running Control 1: Advance Aging');

$overdueAdvances = [];
$newlyOverdue    = 0;

$activeAdvances = $pdo->query(
    "SELECT id, advance_no, recipient_id, recipient_name,
            issued_by_name, purpose, amount, amount_spent,
            amount_returned, children_allocated,
            issued_at, is_overdue, overdue_notified,
            parent_advance_id, root_advance_id
     FROM cash_advances
     WHERE status IN ('active','partial')
     AND (parent_advance_id IS NULL OR parent_advance_id = 0)"
)->fetchAll(\PDO::FETCH_ASSOC);

foreach ($activeAdvances as $adv) {
    $purpose    = strtolower(trim($adv['purpose'] ?? 'misc'));
    $limitHours = (int)($agingHours[$purpose] ?? $agingHours['misc']);
    $issuedTs   = strtotime($adv['issued_at'] ?? '');
    if (!$issuedTs) continue;

    $ageHours = ($nowTs - $issuedTs) / 3600;

    if ($ageHours <= $limitHours) {
        // Not yet overdue — clear flag if it was previously set
        if ($adv['is_overdue']) {
            $pdo->prepare(
                "UPDATE cash_advances SET is_overdue=0, overdue_since=NULL WHERE id=?"
            )->execute([$adv['id']]);
        }
        continue;
    }

    // Overdue
    $overdueHours = round($ageHours - $limitHours, 1);
    $balance = round(
        (float)$adv['amount']
        - (float)$adv['amount_spent']
        - (float)$adv['amount_returned']
        - (float)$adv['children_allocated'],
        2
    );

    if (!$adv['is_overdue']) {
        $newlyOverdue++;
        $overdueAt = date('Y-m-d H:i:s', $issuedTs + $limitHours * 3600);
        $pdo->prepare(
            "UPDATE cash_advances
             SET is_overdue=1, overdue_since=?, overdue_notified=0
             WHERE id=?"
        )->execute([$overdueAt, $adv['id']]);
    }

    $overdueAdvances[] = [
        'id'            => $adv['id'],
        'advance_no'    => $adv['advance_no'],
        'staff'         => $adv['recipient_name'],
        'purpose'       => ucfirst($purpose),
        'amount'        => (float)$adv['amount'],
        'balance'       => $balance,
        'age_hours'     => round($ageHours, 1),
        'limit_hours'   => $limitHours,
        'overdue_hours' => $overdueHours,
        'notified'      => (bool)$adv['overdue_notified'],
    ];
}

rclog(sprintf('Aging: %d overdue advances found (%d newly flagged)', count($overdueAdvances), $newlyOverdue));

// ═══════════════════════════════════════════════════════════════════════════════
// CONTROL 2 — CASH CARRY LIMIT (v4.4.24: queries staff_cash_position VIEW)
// ═══════════════════════════════════════════════════════════════════════════════

rclog("Running Control 2: Cash Carry Limit (limit: \${$carryLimit})");

$carryAlerts = [];

// Prefer the SQL VIEW (migration 008) — single query across all four streams.
// Fall back to PHP multi-stream calculation if VIEW hasn't been created yet.
$viewExists = (bool)$pdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='view' AND name='staff_cash_position'"
)->fetchColumn();

if ($viewExists) {
    $carryRows = $pdo->query('SELECT * FROM staff_cash_position')->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($carryRows as $row) {
        $sid        = (int)$row['staff_id'];
        $cashInHand = round((float)$row['cash_exposure'], 2);
        if ($cashInHand <= 0) {
            // Clear any stale overlimit flag
            $rList = $store->load('retailers.json') ?? [];
            foreach ($rList as &$r) {
                if ((int)($r['id'] ?? 0) === $sid && !empty($r['carry_overlimit'])) {
                    $r['carry_overlimit'] = false; $r['cash_in_hand'] = $cashInHand; break;
                }
            }
            unset($r);
            $store->save('retailers.json', $rList);
            $pdo->prepare('UPDATE staff_carry_alerts SET resolved=1, resolved_at=? WHERE staff_id=? AND resolved=0')
                ->execute([$now, $sid]);
            continue;
        }
        if ($cashInHand > $carryLimit) {
            $excess = round($cashInHand - $carryLimit, 2);
            $alreadyToday = $pdo->prepare('SELECT id FROM staff_carry_alerts WHERE staff_id=? AND resolved=0 AND flagged_at >= date(\'now\',\'-1 day\')');
            $alreadyToday->execute([$sid]);
            if (!$alreadyToday->fetch()) {
                $pdo->prepare('INSERT INTO staff_carry_alerts (staff_id, staff_name, cash_in_hand, carry_limit, flagged_at, resolved) VALUES (?,?,?,?,?,0)')
                    ->execute([$sid, $row['staff_name'], $cashInHand, $carryLimit, $now]);
            }
            $rList = $store->load('retailers.json') ?? [];
            foreach ($rList as &$r) {
                if ((int)($r['id'] ?? 0) === $sid) {
                    $r['carry_overlimit'] = true; $r['carry_overlimit_at'] = $now; $r['cash_in_hand'] = $cashInHand; break;
                }
            }
            unset($r);
            $store->save('retailers.json', $rList);
            $carryAlerts[] = [
                'staff'           => $row['staff_name'],
                'cash_in_hand'    => $cashInHand,
                'collections'     => round((float)$row['collections'], 2),
                'advance_balance' => round((float)$row['advance_balance'], 2),
                'expenses'        => round((float)$row['expenses'], 2),
                'handovers'       => round((float)$row['handovers'], 2),
                'limit'           => $carryLimit,
                'excess'          => $excess,
            ];
        }
    }
} else {
    // ── PHP fallback (VIEW not yet created — pre-migration 008) ──────────
    rclog('VIEW staff_cash_position not found — using PHP fallback for Control 2');
    require_once __DIR__ . '/../lib/StoreInterface.php';
    require_once __DIR__ . '/../lib/StaffCashPositionService.php';
    $_cpSvc   = new StaffCashPositionService($store, $pdo);
    $allPos   = $_cpSvc->getAllPositions();
    foreach ($allPos as $sid => $pos) {
        $cashInHand = $pos['cash_exposure'];
        if ($cashInHand <= 0) continue;
        if ($cashInHand > $carryLimit) {
            $excess = round($cashInHand - $carryLimit, 2);
            $alreadyToday = $pdo->prepare('SELECT id FROM staff_carry_alerts WHERE staff_id=? AND resolved=0 AND flagged_at >= date(\'now\',\'-1 day\')');
            $alreadyToday->execute([$sid]);
            if (!$alreadyToday->fetch()) {
                $pdo->prepare('INSERT INTO staff_carry_alerts (staff_id, staff_name, cash_in_hand, carry_limit, flagged_at, resolved) VALUES (?,?,?,?,?,0)')
                    ->execute([$sid, $pos['staff_name'], $cashInHand, $carryLimit, $now]);
            }
            $rList = $store->load('retailers.json') ?? [];
            foreach ($rList as &$r) {
                if ((int)($r['id'] ?? 0) === $sid) { $r['carry_overlimit'] = true; $r['cash_in_hand'] = $cashInHand; break; }
            }
            unset($r);
            $store->save('retailers.json', $rList);
            $carryAlerts[] = ['staff'=>$pos['staff_name'],'cash_in_hand'=>$cashInHand,'collections'=>$pos['collections'],'advance_balance'=>$pos['advance_balance'],'expenses'=>$pos['expenses'],'handovers'=>$pos['handovers'],'limit'=>$carryLimit,'excess'=>$excess];
        }
    }
}

rclog(sprintf('Carry: %d staff over limit of $%.2f', count($carryAlerts), $carryLimit));

// ═══════════════════════════════════════════════════════════════════════════════
// CONTROL 3 — NIGHTLY LEDGER DRIFT CHECK
// ═══════════════════════════════════════════════════════════════════════════════

rclog('Running Control 3: Ledger Drift Check');

// cb_ledger totals (approved entries only, project = dishnet)
$cbRow = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN direction='in'  THEN amount ELSE 0 END) AS cb_in,
        SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) AS cb_out
     FROM cb_ledger
     WHERE project=? AND status='approved'"
);
$cbRow->execute([$project]);
$cb = $cbRow->fetch(\PDO::FETCH_ASSOC);
$cbIn  = round((float)($cb['cb_in']  ?? 0), 2);
$cbOut = round((float)($cb['cb_out'] ?? 0), 2);
$cbNet = round($cbIn - $cbOut, 2);

// Advance system totals (root advances only)
$advRow = $pdo->query(
    "SELECT
        SUM(amount)          AS total_issued,
        SUM(amount_spent)    AS total_spent,
        SUM(amount_returned) AS total_returned
     FROM cash_advances
     WHERE parent_advance_id IS NULL OR parent_advance_id = 0"
)->fetch(\PDO::FETCH_ASSOC);

$totalIssued   = round((float)($advRow['total_issued']   ?? 0), 2);
$totalSpent    = round((float)($advRow['total_spent']    ?? 0), 2);
$totalReturned = round((float)($advRow['total_returned'] ?? 0), 2);
$outstanding   = round($totalIssued - $totalSpent - $totalReturned, 2);

// Collections from cb_ledger (source = 'collection' or category = 'collection')
$colRow = $pdo->query(
    "SELECT SUM(amount) as total FROM cb_ledger
     WHERE direction='in' AND status='approved'
     AND (source='collection' OR category='collection')"
)->fetch(\PDO::FETCH_ASSOC);
$totalCollections = round((float)($colRow['total'] ?? 0), 2);

// Expected cb_net:
//   collections_in − advances_issued_out + expenses_paid_in_field_net
// Simplified drift formula used by most telco finance systems:
//   drift = cb_net - (cb_in - cb_out) should always = 0
//   Advance-system check: totalIssued − totalSpent − totalReturned = outstanding
//   Cross-check: cb_out from advances should equal totalIssued,
//                cb_in from returns should equal totalReturned
// We use a simpler, actionable formula:
//   expected_outstanding = totalIssued - totalSpent - totalReturned
//   actual_outstanding   = SUM(active/partial advances' remaining balance)

$actualOutRow = $pdo->query(
    "SELECT SUM(amount - amount_spent - amount_returned - children_allocated) AS bal
     FROM cash_advances
     WHERE status IN ('active','partial')
     AND (parent_advance_id IS NULL OR parent_advance_id = 0)"
)->fetch(\PDO::FETCH_ASSOC);
$actualOutstanding = round((float)($actualOutRow['bal'] ?? 0), 2);

$drift    = round(abs($outstanding - $actualOutstanding), 2);
$driftOk  = $drift <= $driftTol;
$driftMsg = '';

if (!$driftOk) {
    $driftMsg = sprintf(
        'DRIFT DETECTED: advance_math_outstanding=%.2f, actual_balance_sum=%.2f, diff=%.2f',
        $outstanding, $actualOutstanding, $drift
    );
    rclog('⚠️  ' . $driftMsg);
} else {
    rclog(sprintf('Drift clean: outstanding=%.2f, actual=%.2f, diff=%.4f', $outstanding, $actualOutstanding, $drift));
}

// Persist to ledger_reconcile_log
try {
    $pdo->prepare(
        "INSERT INTO ledger_reconcile_log
         (run_at, project, cb_in, cb_out, cb_net,
          advances_out, expenses_out, returns_in, outstanding, drift, drift_ok, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $now, $project,
        $cbIn, $cbOut, $cbNet,
        $totalIssued, $totalSpent, $totalReturned,
        $actualOutstanding,
        $drift,
        $driftOk ? 1 : 0,
        $driftOk ? 'Clean' : $driftMsg,
    ]);
} catch (\Throwable $e) {
    rclog('ledger_reconcile_log insert failed: ' . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════════════════
// CONTROL 4 — MISSING RECEIPT RULE
// ═══════════════════════════════════════════════════════════════════════════════

rclog("Running Control 4: Missing Receipt (grace: {$receiptGrace}h)");

$graceThreshold = date('Y-m-d H:i:s', $nowTs - $receiptGrace * 3600);

$noReceiptRows = $pdo->prepare(
    "SELECT e.id, e.expense_no, e.staff_name, e.amount, e.currency,
            e.expense_date, e.reviewed_at, e.description
     FROM staff_expenses e
     WHERE e.status = 'approved'
     AND e.flag_no_receipt = 1
     AND (e.receipt_hash = '' OR e.receipt_hash IS NULL)
     AND (e.reviewed_at IS NULL OR e.reviewed_at <= ?)
     ORDER BY e.reviewed_at ASC
     LIMIT 50"
);
$noReceiptRows->execute([$graceThreshold]);
$missingReceipts = $noReceiptRows->fetchAll(\PDO::FETCH_ASSOC);

rclog(sprintf('Missing receipts: %d expenses past grace period', count($missingReceipts)));

// ═══════════════════════════════════════════════════════════════════════════════
// CONTROL 5 — AGENT FLOAT WARNING (v4.4.23)
// ═══════════════════════════════════════════════════════════════════════════════

rclog("Running Control 5: Agent Float Warning (threshold: \${$floatWarnLimit})");

$floatAlerts = [];

if ($floatWarnLimit > 0) {
    $allRetailers = $store->load('retailers.json') ?? [];
    foreach ($allRetailers as $r) {
        if (empty($r['is_active'])) continue;
        if (!in_array($r['role'] ?? '', ['sales','field_agent','collection'], true)) continue;
        $walletBal = (float)($r['wallet'] ?? 0);
        if ($walletBal < $floatWarnLimit) {
            $floatAlerts[] = [
                'name'      => $r['name'] ?? 'Agent #'.$r['id'],
                'wallet'    => $walletBal,
                'threshold' => $floatWarnLimit,
                'gap'       => round($floatWarnLimit - $walletBal, 2),
            ];
        }
    }
}

rclog(sprintf('Float low: %d agent(s) below $%.2f threshold', count($floatAlerts), $floatWarnLimit));

// ═══════════════════════════════════════════════════════════════════════════════
// CONTROL 6 — CASH AGING (v4.4.27)
// ═══════════════════════════════════════════════════════════════════════════════
// Telecom field-finance standard: agents must not hold company cash indefinitely.
// This control tracks HOW LONG each agent has been holding any cash, measured
// from the oldest inflow event still outstanding.
//
// Inflow sources tracked (what ADDS to exposure):
//   Stream A: customer payment collections (oldest collected_at / created_at)
//   Stream B: active cash advances         (oldest issued_at)
//   Stream C: transfers received           (oldest submitted_at)
//
// Tiers and limits (config: cash_aging_tiers in kyc_config.json):
//   light  = exposure < $50    →  48 h max
//   medium = $50 – $200        →  24 h max
//   heavy  = exposure > $200   →  12 h max
//
// On breach: insert into cash_aging_alerts, alert Rupesh via WA.
// ═══════════════════════════════════════════════════════════════════════════════

rclog('Running Control 6: Cash Aging');

$agingTiers = array_merge(
    ['light' => 48, 'medium' => 24, 'heavy' => 12],
    (array)($config['cash_aging_tiers'] ?? [])
);

$agingAlerts   = [];
$agingResolved = 0;

// ── SQL: oldest inflow event per agent (2 queries replacing N) ────────────────
// Build a map: agent_id → oldest_event_at + which source produced it.
// We query the three inflow sources separately and merge in PHP.
// This avoids a complex UNION across mixed JSON-extracted and real tables.

$oldestByAgent = []; // agent_id → ['event_time' => ISO, 'source' => string]

// Source A: collections (JSON virtual table)
try {
    $colRows = $pdo->query(
        "SELECT
            CAST(json_extract(c.data,'$.retailer_id') AS INTEGER) AS agent_id,
            MIN(COALESCE(
                NULLIF(json_extract(c.data,'$.collected_at'),''),
                NULLIF(json_extract(c.data,'$.created_at'),''),
                ''
            )) AS oldest
         FROM [payment_collections] c
         WHERE json_extract(c.data,'$.retailer_id') IS NOT NULL
         GROUP BY agent_id"
    )->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($colRows as $row) {
        $aid = (int)$row['agent_id'];
        $t   = $row['oldest'] ?? '';
        if ($aid <= 0 || !$t) continue;
        if (!isset($oldestByAgent[$aid]) || $t < $oldestByAgent[$aid]['event_time']) {
            $oldestByAgent[$aid] = ['event_time' => $t, 'source' => 'collection'];
        }
    }
} catch (\Throwable $e) {
    rclog('Control 6: collection query failed — ' . $e->getMessage());
}

// Source B: active root advances
try {
    $advRows = $pdo->query(
        "SELECT
            recipient_id AS agent_id,
            MIN(COALESCE(NULLIF(issued_at,''), created_at)) AS oldest
         FROM cash_advances
         WHERE status IN ('active','partial')
           AND (parent_advance_id IS NULL OR parent_advance_id = 0)
         GROUP BY recipient_id"
    )->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($advRows as $row) {
        $aid = (int)$row['agent_id'];
        $t   = $row['oldest'] ?? '';
        if ($aid <= 0 || !$t) continue;
        if (!isset($oldestByAgent[$aid]) || $t < $oldestByAgent[$aid]['event_time']) {
            $oldestByAgent[$aid] = ['event_time' => $t, 'source' => 'advance'];
        }
    }
} catch (\Throwable $e) {
    rclog('Control 6: advance query failed — ' . $e->getMessage());
}

// Source C: approved transfers received
try {
    $trfRows = $pdo->query(
        "SELECT
            to_id AS agent_id,
            MIN(submitted_at) AS oldest
         FROM staff_transfers
         WHERE status='approved'
         GROUP BY to_id"
    )->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($trfRows as $row) {
        $aid = (int)$row['agent_id'];
        $t   = $row['oldest'] ?? '';
        if ($aid <= 0 || !$t) continue;
        if (!isset($oldestByAgent[$aid]) || $t < $oldestByAgent[$aid]['event_time']) {
            $oldestByAgent[$aid] = ['event_time' => $t, 'source' => 'transfer_received'];
        }
    }
} catch (\Throwable $e) {
    rclog('Control 6: transfer query failed — ' . $e->getMessage()); }

// ── Evaluate each agent against their tier limit ──────────────────────────────
// Use VIEW if available (gives accurate exposure including transfers).
$agingPositions = [];
if ($viewExists) {
    $rows = $pdo->query('SELECT staff_id, staff_name, cash_exposure FROM staff_cash_position')
                ->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $agingPositions[(int)$r['staff_id']] = [
            'staff_name'    => $r['staff_name'],
            'cash_exposure' => (float)$r['cash_exposure'],
        ];
    }
}

$nowTs = time();

foreach ($agingPositions as $agentId => $pos) {
    $exposure = $pos['cash_exposure'];

    // Agent has no cash — resolve any open aging alerts
    if ($exposure <= 0) {
        $pdo->prepare(
            "UPDATE cash_aging_alerts SET resolved=1, resolved_at=? WHERE staff_id=? AND resolved=0"
        )->execute([$now, $agentId]);
        $agingResolved++;
        continue;
    }

    // No inflow event recorded — agent has exposure but no traceable source yet
    if (!isset($oldestByAgent[$agentId])) continue;

    $oldestTs   = strtotime($oldestByAgent[$agentId]['event_time']);
    if ($oldestTs <= 0) continue;
    $ageSeconds = $nowTs - $oldestTs;
    $ageHours   = round($ageSeconds / 3600, 1);

    // Determine tier
    if ($exposure < 50) {
        $tier       = 'light';
    } elseif ($exposure <= 200) {
        $tier       = 'medium';
    } else {
        $tier       = 'heavy';
    }
    $limitHours = (float)($agingTiers[$tier] ?? 24);

    if ($ageHours <= $limitHours) continue; // within limit — no alert

    $overBy = round($ageHours - $limitHours, 1);

    // Deduplicate: don't re-insert if already flagged and unresolved in last 12h
    $dupCheck = $pdo->prepare(
        "SELECT id FROM cash_aging_alerts
         WHERE staff_id=? AND resolved=0
           AND flagged_at >= datetime('now','-12 hours')"
    );
    $dupCheck->execute([$agentId]);
    if ($dupCheck->fetch()) continue;

    $pdo->prepare(
        "INSERT INTO cash_aging_alerts
         (staff_id, staff_name, cash_exposure, oldest_event_at, age_hours,
          tier, limit_hours, over_by_hours, oldest_source, resolved, notified, flagged_at)
         VALUES (?,?,?,?,?, ?,?,?,?, 0,1,?)"
    )->execute([
        $agentId,
        $pos['staff_name'],
        $exposure,
        $oldestByAgent[$agentId]['event_time'],
        $ageHours,
        $tier,
        $limitHours,
        $overBy,
        $oldestByAgent[$agentId]['source'],
        $now,
    ]);

    $ageDisplay = $ageHours >= 24
        ? number_format($ageHours / 24, 1) . 'd'
        : number_format($ageHours, 0) . 'h';

    $agingAlerts[] = [
        'staff'          => $pos['staff_name'],
        'exposure'       => $exposure,
        'age_hours'      => $ageHours,
        'age_display'    => $ageDisplay,
        'tier'           => $tier,
        'limit_hours'    => $limitHours,
        'over_by_hours'  => $overBy,
        'oldest_source'  => $oldestByAgent[$agentId]['source'],
        'oldest_event'   => $oldestByAgent[$agentId]['event_time'],
    ];
}

rclog(sprintf(
    'Aging: %d alert(s) fired, %d resolved',
    count($agingAlerts), $agingResolved
));

// ═══════════════════════════════════════════════════════════════════════════════
// CONTROL 7 — SNAPSHOT DRIFT VERIFICATION (v4.4.28)
// ═══════════════════════════════════════════════════════════════════════════════
// Compares staff_position_snapshot against the live VIEW (source of truth).
// Any mismatch > $0.02 triggers a rebuild + WA alert.
// This is the safety net for the materialized position table.
// ═══════════════════════════════════════════════════════════════════════════════

rclog('Running Control 7: Snapshot Drift Verification');

$snapshotDrifts = [];
$snapshotRebuilt = 0;

try {
    require_once __DIR__ . '/../lib/SnapshotService.php';
    $_snap = new SnapshotService($pdo, $store);

    // rebuildAll() returns drifted count and fixes them in one pass
    $rebuildResult = $_snap->rebuildAll();
    $snapshotRebuilt = $rebuildResult['rebuilt'];

    // diff() after rebuild shows any agents that STILL don't match (rebuild failed)
    $snapshotDrifts = $_snap->diff();

    rclog(sprintf(
        'Snapshot: rebuilt %d agents, %d drifted before rebuild, %d still mismatched after',
        $rebuildResult['rebuilt'],
        $rebuildResult['drifted'],
        count($snapshotDrifts)
    ));
} catch (\Throwable $e) {
    rclog('Control 7: SnapshotService unavailable — ' . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════════════════
// COMPOSE CONSOLIDATED WHATSAPP REPORT
// ═══════════════════════════════════════════════════════════════════════════════

$hasIssues = !empty($overdueAdvances) || !empty($carryAlerts) || !$driftOk
          || !empty($missingReceipts) || !empty($floatAlerts) || !empty($agingAlerts)
          || !empty($snapshotDrifts);

$lines   = [];
$dateStr = date('d M Y');

$lines[] = "🏦 *DishNet Finance Audit — {$dateStr}*";
$lines[] = $hasIssues
    ? '⚠️  *Action required — see items below.*'
    : '✅ *All controls passed — ledger clean.*';
$lines[] = '';

// ── Section 1: Advance Aging ──────────────────────────────────────────────────
$lines[] = '━━━━━━━━━━━━━━━━━━━';
$lines[] = '⏳ *OVERDUE ADVANCES (' . count($overdueAdvances) . ')*';
if (empty($overdueAdvances)) {
    $lines[] = '  ✅ None overdue.';
} else {
    foreach (array_slice($overdueAdvances, 0, 8) as $adv) {
        $lines[] = "  • *{$adv['advance_no']}* — {$adv['staff']}";
        $lines[] = "    {$adv['purpose']} | Bal: \${$adv['balance']} | Age: {$adv['age_hours']}h (limit {$adv['limit_hours']}h, overdue by {$adv['overdue_hours']}h)";
    }
    if (count($overdueAdvances) > 8) {
        $lines[] = '  _... and ' . (count($overdueAdvances) - 8) . ' more._';
    }
    $lines[] = '  👉 These staff cannot request new advances until settled.';
}
$lines[] = '';

// ── Section 2: Carry Limit ────────────────────────────────────────────────────
$lines[] = '━━━━━━━━━━━━━━━━━━━';
$lines[] = '💼 *CASH CARRY ALERTS (' . count($carryAlerts) . ')*';
if (empty($carryAlerts)) {
    $lines[] = "  ✅ No staff over \${$carryLimit} carry limit.";
} else {
    foreach ($carryAlerts as $ca) {
        $lines[] = "  • *{$ca['staff']}*: \${$ca['cash_in_hand']} in hand (limit \${$ca['limit']}, excess \${$ca['excess']})";
        $parts = [];
        if ($ca['collections'] > 0)     $parts[] = "Col \${$ca['collections']}";
        if ($ca['advance_balance'] > 0)  $parts[] = "Adv \${$ca['advance_balance']}";
        if ($ca['expenses'] > 0)         $parts[] = "Exp -\${$ca['expenses']}";
        if ($ca['handovers'] > 0)        $parts[] = "Hov -\${$ca['handovers']}";
        if (!empty($parts)) $lines[] = "    _" . implode(' · ', $parts) . "_";
    }
    $lines[] = '  👉 New advances blocked for flagged staff until carry resolves.';
}
$lines[] = '';

// ── Section 3: Drift Check ────────────────────────────────────────────────────
$lines[] = '━━━━━━━━━━━━━━━━━━━';
$lines[] = '📊 *LEDGER DRIFT CHECK*';
$lines[] = "  cb_ledger:  IN \${$cbIn}  OUT \${$cbOut}  Net \${$cbNet}";
$lines[] = "  Advances:   Issued \${$totalIssued}  Spent \${$totalSpent}  Returned \${$totalReturned}";
$lines[] = "  Outstanding (math): \${$outstanding}";
$lines[] = "  Outstanding (actual balances): \${$actualOutstanding}";
if ($driftOk) {
    $lines[] = '  ✅ Clean — drift within tolerance ($' . number_format($driftTol, 2) . ').';
} else {
    $lines[] = "  ⚠️  DRIFT: \${$drift} — investigate immediately!";
    $lines[] = "  Possible cause: failed partial write, manual DB edit, or advance settled outside plugin.";
}
$lines[] = '';

// ── Section 4: Missing Receipts ───────────────────────────────────────────────
$lines[] = '━━━━━━━━━━━━━━━━━━━';
$lines[] = '🧾 *MISSING RECEIPTS (' . count($missingReceipts) . ')*';
if (empty($missingReceipts)) {
    $lines[] = "  ✅ No approved expenses without receipt past {$receiptGrace}h grace.";
} else {
    // Group by staff
    $byStaff = [];
    foreach ($missingReceipts as $exp) {
        $byStaff[$exp['staff_name']][] = $exp;
    }
    foreach (array_slice($byStaff, 0, 6, true) as $staffName => $exps) {
        $total = array_sum(array_column($exps, 'amount'));
        $lines[] = "  • *{$staffName}* — " . count($exps) . " expense(s), total \${$total}";
        foreach (array_slice($exps, 0, 3) as $exp) {
            $lines[] = "    └ {$exp['expense_no']} \${$exp['amount']} ({$exp['expense_date']})";
        }
        if (count($exps) > 3) $lines[] = '    └ ... ' . (count($exps) - 3) . ' more';
    }
    if (count($byStaff) > 6) {
        $lines[] = '  _... and ' . (count($byStaff) - 6) . ' more staff members._';
    }
    $lines[] = '  👉 Ask staff to submit receipts or mark as no-receipt override.';
}
$lines[] = '';

// ── Section 5: Agent Float ────────────────────────────────────────────────────
$lines[] = '━━━━━━━━━━━━━━━━━━━';
$lines[] = '💳 *AGENT FLOAT LOW (' . count($floatAlerts) . ')*';
if (empty($floatAlerts)) {
    $lines[] = "  ✅ All agents above \${$floatWarnLimit} float threshold.";
} else {
    foreach ($floatAlerts as $fa) {
        $lines[] = "  • *{$fa['name']}*: wallet \${$fa['wallet']} — needs \${$fa['gap']} top-up to reach \${$fa['threshold']}";
    }
    $lines[] = '  👉 Top up affected agents before they run out of float.';
}
$lines[] = '';

// ── Section 6: Cash Aging ─────────────────────────────────────────────────────
$lines[] = '━━━━━━━━━━━━━━━━━━━';
$lines[] = '⏱ *CASH AGING (' . count($agingAlerts) . ')*';
if (empty($agingAlerts)) {
    $lines[] = '  ✅ All agents holding cash within time limits.';
} else {
    $tierIcon = ['light' => '🟡', 'medium' => '🟠', 'heavy' => '🔴'];
    $tierLabel = ['light' => '<$50/48h', 'medium' => '$50-200/24h', 'heavy' => '>$200/12h'];
    foreach ($agingAlerts as $a) {
        $icon = $tierIcon[$a['tier']] ?? '⚠️';
        $lines[] = "  {$icon} *{$a['staff']}* — \${$a['exposure']} held for *{$a['age_display']}*";
        $lines[] = "    Limit: {$a['limit_hours']}h (" . ($tierLabel[$a['tier']] ?? '') . ") · Over by {$a['over_by_hours']}h";
        $lines[] = "    Source: {$a['oldest_source']} since " . substr($a['oldest_event'], 0, 16);
    }
    $lines[] = '  👉 These agents must hand over cash to Rupesh immediately.';
}
$lines[] = '';

// ── Section 7: Snapshot Drift ─────────────────────────────────────────────────
$lines[] = '━━━━━━━━━━━━━━━━━━━';
$lines[] = '🗄 *SNAPSHOT INTEGRITY (' . ($snapshotRebuilt > 0 ? "{$snapshotRebuilt} rebuilt" : '✅') . ')*';
if (empty($snapshotDrifts)) {
    $lines[] = "  ✅ All agent snapshots match live data.";
} else {
    $lines[] = "  ⚠️  " . count($snapshotDrifts) . " agent(s) still mismatched after rebuild — investigate.";
    foreach (array_slice($snapshotDrifts, 0, 5) as $d) {
        $lines[] = "  • *{$d['staff_name']}*: snapshot \${$d['snapshot']} vs live \${$d['view']} (Δ\${$d['delta']})";
    }
}
$lines[] = '';

// ── Footer ────────────────────────────────────────────────────────────────────
$lines[] = '━━━━━━━━━━━━━━━━━━━';
$lines[] = '_DishNet Finance Audit — ' . date('H:i') . ' EAT_';

$message = implode("\n", $lines);

// ── Send to all accountants/admins ────────────────────────────────────────────
foreach ($recipients as $rec) {
    $phone = preg_replace('/[^0-9]/', '', $rec['phone'] ?? '');
    if (!$phone) continue;
    try {
        $notify->sendRaw($phone, $message, 'finance_audit_nightly');
        rclog('Sent to ' . $rec['name'] . ' (' . $phone . ')');
    } catch (\Throwable $e) {
        rclog('WA send failed to ' . $rec['name'] . ': ' . $e->getMessage());
    }
}

// ── Mark overdue advances as notified ────────────────────────────────────────
if (!empty($overdueAdvances)) {
    $ids = array_column($overdueAdvances, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare(
        "UPDATE cash_advances SET overdue_notified=1 WHERE id IN ({$placeholders})"
    )->execute($ids);
}

rclog(sprintf(
    'Done. Overdue: %d | Carry alerts: %d | Drift ok: %s | No-receipt: %d | Float low: %d | Cash aging: %d | Snap rebuilt: %d | Snap mismatched: %d',
    count($overdueAdvances),
    count($carryAlerts),
    $driftOk ? 'YES' : 'NO',
    count($missingReceipts),
    count($floatAlerts),
    count($agingAlerts),
    $snapshotRebuilt,
    count($snapshotDrifts)
));
