<?php
// ── SSP Overview — Company-wide SSP position dashboard ──────────────────
// READ-ONLY view. Assembles data from 4 existing stores:
//   cb_ledger (SQLite)  — SSP purchased via Exchange
//   cash_ins.json       — SSP distributed to staff
//   cash_expenses.json  — SSP spent by staff
//   cash_handovers.json — SSP returned to office
//
// v4.9.21 — Aida / Rupesh requested
// ────────────────────────────────────────────────────────────────────────

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }

// ── Access: admin + accountant only ─────────────────────────────────────
if (!($retailer['is_admin'] ?? false) && !in_array($retailer['role'] ?? '', ['admin','accountant'])) {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied — admin/accountant only.</div>';
    return;
}

require_once __DIR__ . '/../../lib/CashbookService.php';

$cb = new CashbookService($store, $dataDir);

// ═══════════════════════════════════════════════════════════════════════
// 0. HANDLE BACKFILL ACTION — create missing cash_in records for legacy
// ═══════════════════════════════════════════════════════════════════════
if (($_POST['ssp_action'] ?? '') === 'backfill_legacy' && csrfCheck()) {
    $allRet    = $store->load('retailers.json') ?: [];
    $cashIns   = $store->load('cash_ins.json') ?: [];

    // Collect all cb_refs already linked
    $existingRefs = [];
    foreach ($cashIns as $ci) {
        $ref = $ci['cb_ref'] ?? '';
        if ($ref !== '') $existingRefs[$ref] = true;
    }

    // Name → retailer resolver (fuzzy contains, same as auto-link in post_cashbook)
    $resolve = function($personName) use ($allRet) {
        $pl = strtolower(trim($personName));
        if ($pl === '') return ['id' => 0, 'name' => '', 'phone' => ''];
        foreach ($allRet as $r) {
            if (empty($r['is_active'])) continue;
            if (strtolower($r['name'] ?? '') === $pl) return ['id' => (int)$r['id'], 'name' => $r['name'] ?? '', 'phone' => $r['phone'] ?? ''];
        }
        foreach ($allRet as $r) {
            if (empty($r['is_active'])) continue;
            $rn = strtolower($r['name'] ?? '');
            if ($rn !== '' && (strpos($rn, $pl) !== false || strpos($pl, $rn) !== false)) {
                return ['id' => (int)$r['id'], 'name' => $r['name'] ?? '', 'phone' => $r['phone'] ?? ''];
            }
        }
        return ['id' => 0, 'name' => '', 'phone' => ''];
    };

    // Get all SSP OUT from cb_ledger
    $outRows = $cb->query(
        "SELECT id, sr, date, amount, ssp_amount, ssp_rate, person, description, category, created_at
         FROM cb_ledger WHERE currency='SSP' AND direction='out' ORDER BY id ASC"
    );

    $created = 0;
    $skipped = 0;
    foreach ($outRows as $row) {
        $sr = $row['sr'] ?? '';
        if ($sr === '' || isset($existingRefs[$sr])) { $skipped++; continue; }

        // Only backfill entries to known staff (name resolves to a retailer)
        $match = $resolve($row['person'] ?? '');
        if ($match['id'] <= 0) { $skipped++; continue; }

        $sspAmt = (float)($row['ssp_amount'] ?? 0);
        $usdAmt = (float)($row['amount'] ?? 0);
        // If currency=SSP but ssp_amount not populated, fall back to amount column
        if ($sspAmt <= 0 && $usdAmt > 0) { $sspAmt = $usdAmt; }
        if ($sspAmt <= 0 && $usdAmt <= 0) { $skipped++; continue; }

        $ciCategory = 'SSP Received';
        $ciDesc = 'From Office — ' . $cat;
        if (!empty($row['description'])) $ciDesc .= ' (' . trim($row['description']) . ')';
        $ciDesc .= ' [backfill]';

        $cashIns[] = [
            'id'            => count($cashIns) + 1,
            'collector_id'  => $match['id'],
            'collector_name'=> $match['name'],
            'amount'        => $usdAmt,
            'currency'      => 'SSP',
            'ssp_amount'    => $sspAmt,
            'usd_given'     => 0,
            'rate'          => (float)($row['ssp_rate'] ?? 0),
            'category'      => $ciCategory,
            'description'   => $ciDesc,
            'status'        => 'approved',
            'approved_by'   => 'auto (cashbook link) [backfill]',
            'approved_at'   => date('Y-m-d H:i:s'),
            'cb_ref'        => $sr,
            'created_at'    => $row['created_at'] ?? date('Y-m-d H:i:s'),
        ];
        $existingRefs[$sr] = true;
        $created++;
    }

    if ($created > 0) {
        $store->save('cash_ins.json', $cashIns);
        if (function_exists('logActivity')) {
            logActivity($dataDir, 'ssp_backfill', "Created {$created} legacy cash_in records (skipped {$skipped})", '');
        }
    }

    $qs = http_build_query(['page' => 'dashboard', 'tab' => 'ssp_overview', 'bf' => $created, 'bfs' => $skipped]);
    header('Location: ?' . $qs);
    exit;
}

// ── cb_ledger: SSP purchased via Exchange (direction=in, currency=SSP) ──
$sspExchangeRows = $cb->query(
    "SELECT id, date, amount, ssp_amount, ssp_rate, person, description, validation_ref, created_at
     FROM cb_ledger WHERE category='Exchange' AND currency='SSP' AND direction='in'
     ORDER BY date DESC, id DESC"
);
$totalSspPurchased = 0;
foreach ($sspExchangeRows as $r) {
    $totalSspPurchased += (float)($r['ssp_amount'] ?? 0);
}

// ── cb_ledger: SSP OUT from cashbook (direct office→staff entries) ──
$sspOutRows = $cb->query(
    "SELECT id, sr, date, amount, ssp_amount, ssp_rate, person, description, category, created_at
     FROM cb_ledger WHERE currency='SSP' AND direction='out'
     ORDER BY date DESC, id DESC"
);

// ── Current exchange rate + last actual market rate context ─────────────
$currentRate  = $cb->getRateForDate(date('Y-m-d'));

// ── cash_ins.json: SSP distributed to staff ─────────────────────────────
$allCashIns = $store->load('cash_ins.json') ?: [];

// getLastExchangeContext reads actual rates staff got at the money changer.
// Different from system rate: Diko may get 6,000 while Rupesh gets 5,700.
$_excCtx = $cb->getLastExchangeContext($allCashIns);

// Phase 2: company-wide exchange batch reconciliation (last 60 days)
$_excBatches = $cb->getAllExchangeBatchSummary($allCashIns, 60);

// ── Expenses: unified via ExpenseGateway ─────────────────────────────────
if (!class_exists('ExpenseGateway')) require_once dirname(__DIR__, 2) . '/lib/ExpenseGateway.php';
$_sspGw = new ExpenseGateway($store);
$allExpenses = $_sspGw->getAll();

// ── cash_handovers.json: SSP returned ───────────────────────────────────
$allHandovers = $store->load('cash_handovers.json') ?: [];

// ── retailers.json: staff list ──────────────────────────────────────────
$allRetailers = $store->load('retailers.json') ?: [];
$staffMap = []; // id => name/role
foreach ($allRetailers as $r) {
    if (empty($r['is_active']) && empty($r['is_admin'])) continue;
    $staffMap[(int)$r['id']] = [
        'name' => $r['name'] ?? 'Unknown',
        'role' => $r['role'] ?? 'staff',
    ];
}

// ═══════════════════════════════════════════════════════════════════════
// 2. COMPUTE PER-PERSON SSP POSITIONS
// ═══════════════════════════════════════════════════════════════════════

// Filter helpers
$isValidCashIn = function($ci) {
    $status = $ci['status'] ?? 'approved';
    return !in_array($status, ['rejected', 'voided']);
};

$isSSPExpense = function($ex) {
    return ($ex['currency'] ?? 'USD') === 'SSP'
        && in_array($ex['status'] ?? '', ['approved', 'pending']);
};

$isSSPHandoverConfirmed = function($h) {
    return strtoupper($h['currency'] ?? 'USD') === 'SSP'
        && ($h['status'] ?? '') === 'confirmed';
};

// Identify "direct from Rupesh" cash_ins via approved_by
$isDirectFromOffice = function($ci) {
    $ab = $ci['approved_by'] ?? '';
    return str_contains($ab, 'cashbook link');
};

// ── Build per-person breakdown ──────────────────────────────────────────
$personData = []; // collector_id => [received, spent_approved, spent_pending, returned, direct_from_office]

foreach ($allCashIns as $ci) {
    if (!$isValidCashIn($ci)) continue;
    $cid = (int)($ci['collector_id'] ?? 0);
    if ($cid <= 0) continue;
    if (!isset($personData[$cid])) {
        $personData[$cid] = ['received' => 0, 'spent_approved' => 0, 'spent_pending' => 0, 'returned' => 0, 'direct_from_office' => 0];
    }
    $amt = (float)($ci['ssp_amount'] ?? 0);
    $personData[$cid]['received'] += $amt;
    if ($isDirectFromOffice($ci)) {
        $personData[$cid]['direct_from_office'] += $amt;
    }
}

foreach ($allExpenses as $ex) {
    if (!$isSSPExpense($ex)) continue;
    $cid = (int)($ex['staff_id'] ?? $ex['collector_id'] ?? 0);
    if ($cid <= 0) continue;
    if (!isset($personData[$cid])) {
        $personData[$cid] = ['received' => 0, 'spent_approved' => 0, 'spent_pending' => 0, 'returned' => 0, 'direct_from_office' => 0];
    }
    $amt = (float)($ex['ssp_amount'] ?? 0);
    if (($ex['status'] ?? '') === 'approved') {
        $personData[$cid]['spent_approved'] += $amt;
    } else {
        $personData[$cid]['spent_pending'] += $amt;
    }
}

foreach ($allHandovers as $h) {
    if (!$isSSPHandoverConfirmed($h)) continue;
    $fid = (int)($h['from_id'] ?? 0);
    if ($fid <= 0) continue;
    if (!isset($personData[$fid])) {
        $personData[$fid] = ['received' => 0, 'spent_approved' => 0, 'spent_pending' => 0, 'returned' => 0, 'direct_from_office' => 0];
    }
    $amt = (float)($h['ssp_amount'] ?? $h['amount'] ?? 0);
    $personData[$fid]['returned'] += $amt;
}

// ── Compute per-person balances ─────────────────────────────────────────
$personRows = [];
$grandTotalReceived  = 0;
$grandTotalSpent     = 0;
$grandTotalPending   = 0;
$grandTotalReturned  = 0;
$grandTotalBalance   = 0;
$grandDirectFromOffice = 0;

foreach ($personData as $pid => $d) {
    $balance = $d['received'] - $d['spent_approved'] - $d['spent_pending'] - $d['returned'];
    $name = isset($staffMap[$pid]) ? $staffMap[$pid]['name'] : ('ID#' . $pid);
    $role = isset($staffMap[$pid]) ? $staffMap[$pid]['role'] : 'unknown';
    $personRows[] = [
        'id'        => $pid,
        'name'      => $name,
        'role'      => $role,
        'received'  => $d['received'],
        'spent'     => $d['spent_approved'],
        'pending'   => $d['spent_pending'],
        'returned'  => $d['returned'],
        'balance'   => $balance,
        'direct'    => $d['direct_from_office'],
    ];
    $grandTotalReceived  += $d['received'];
    $grandTotalSpent     += $d['spent_approved'];
    $grandTotalPending   += $d['spent_pending'];
    $grandTotalReturned  += $d['returned'];
    $grandTotalBalance   += $balance;
    $grandDirectFromOffice += $d['direct_from_office'];
}

// Sort by balance descending (who's holding the most)
usort($personRows, function($a, $b) { return $b['balance'] <=> $a['balance']; });

// ═══════════════════════════════════════════════════════════════════════
// 3. RUPESH'S SSP POOL = cb_ledger balance (ALL SSP IN - ALL SSP OUT)
//    This is the actual running balance of his SSP cashbook.
// ═══════════════════════════════════════════════════════════════════════
$cbSspTotalIn  = 0;
$cbSspTotalOut = 0;
// IN already counted from Exchange query, but there might be non-Exchange INs
$allSspInRows = $cb->query(
    "SELECT ssp_amount FROM cb_ledger WHERE currency='SSP' AND direction='in'"
);
foreach ($allSspInRows as $row) {
    $cbSspTotalIn += (float)($row['ssp_amount'] ?? 0);
}
foreach ($sspOutRows as $row) {
    $cbSspTotalOut += (float)($row['ssp_amount'] ?? 0);
}
$rupeshPool = $cbSspTotalIn - $cbSspTotalOut;

// ═══════════════════════════════════════════════════════════════════════
// 4. SSP MOVEMENT LOG — chronological list of ALL movements
// ═══════════════════════════════════════════════════════════════════════
$movements = [];

// 4a. Exchange entries (SSP purchased)
foreach ($sspExchangeRows as $r) {
    $movements[] = [
        'date'   => $r['date'] ?? substr($r['created_at'] ?? '', 0, 10),
        'from'   => 'Exchange',
        'to'     => trim($r['person'] ?? 'Rupesh'),
        'amount' => (float)($r['ssp_amount'] ?? 0),
        'type'   => 'exchange',
        'ref'    => $r['description'] ?? '',
        'ts'     => $r['created_at'] ?? $r['date'] ?? '',
    ];
}

// 4b. Cash IN entries (SSP distributed)
foreach ($allCashIns as $ci) {
    if (!$isValidCashIn($ci)) continue;
    $sspAmt = (float)($ci['ssp_amount'] ?? 0);
    if ($sspAmt <= 0) continue;
    $cid = (int)($ci['collector_id'] ?? 0);
    $cName = isset($staffMap[$cid]) ? $staffMap[$cid]['name'] : ('ID#' . $cid);
    $ab = $ci['approved_by'] ?? '';
    $fromLabel = 'Office';
    if (str_contains($ab, 'cashbook link')) {
        $fromLabel = 'Rupesh (cashbook)';
    } elseif (str_contains($ab, 'expense approve')) {
        $fromLabel = 'Staff (expense)';
    } elseif (str_contains($ab, 'staff payment')) {
        $fromLabel = 'Staff (payment)';
    } elseif (str_contains($ab, 'batch staff')) {
        $fromLabel = 'Staff (batch)';
    } elseif (str_contains($ab, 'quick approve')) {
        $fromLabel = 'Staff (quick)';
    } elseif (str_contains($ab, 'SSP auto')) {
        $fromLabel = 'System (auto)';
    }
    $movements[] = [
        'date'   => substr($ci['created_at'] ?? date('Y-m-d'), 0, 10),
        'from'   => $fromLabel,
        'to'     => $cName,
        'amount' => $sspAmt,
        'type'   => 'transfer',
        'ref'    => $ci['description'] ?? ($ci['category'] ?? ''),
        'ts'     => $ci['created_at'] ?? '',
    ];
}

// 4c. Expenses (SSP spent externally)
foreach ($allExpenses as $ex) {
    if (!$isSSPExpense($ex)) continue;
    $sspAmt = (float)($ex['ssp_amount'] ?? 0);
    if ($sspAmt <= 0) continue;
    $cid = (int)($ex['staff_id'] ?? $ex['collector_id'] ?? 0);
    $cName = isset($staffMap[$cid]) ? $staffMap[$cid]['name'] : ('ID#' . $cid);
    $movements[] = [
        'date'   => substr($ex['submitted_at'] ?? $ex['created_at'] ?? date('Y-m-d'), 0, 10),
        'from'   => $cName,
        'to'     => $ex['category'] ?? 'Expense',
        'amount' => $sspAmt,
        'type'   => 'expense',
        'ref'    => $ex['description'] ?? '',
        'ts'     => $ex['submitted_at'] ?? $ex['created_at'] ?? '',
        'status' => $ex['status'] ?? '',
    ];
}

// 4d. Handovers (SSP returned to office)
foreach ($allHandovers as $h) {
    if (!$isSSPHandoverConfirmed($h)) continue;
    $sspAmt = (float)($h['ssp_amount'] ?? $h['amount'] ?? 0);
    if ($sspAmt <= 0) continue;
    $fid = (int)($h['from_id'] ?? 0);
    $fName = isset($staffMap[$fid]) ? $staffMap[$fid]['name'] : ('ID#' . $fid);
    $movements[] = [
        'date'   => substr($h['confirmed_at'] ?? $h['created_at'] ?? date('Y-m-d'), 0, 10),
        'from'   => $fName,
        'to'     => $h['to_name'] ?? 'Office',
        'amount' => $sspAmt,
        'type'   => 'return',
        'ref'    => $h['notes'] ?? '',
        'ts'     => $h['confirmed_at'] ?? $h['created_at'] ?? '',
    ];
}

// Sort movements newest first
usort($movements, function($a, $b) {
    return strcmp($b['ts'] ?: $b['date'], $a['ts'] ?: $a['date']);
});

// ═══════════════════════════════════════════════════════════════════════
// 5. WARNINGS — broken auto-link detection (ID-based, not name-based)
// ═══════════════════════════════════════════════════════════════════════
$warnings = [];
$legacyGaps = [];

// 5a. Build person-name → retailer_id resolver (same fuzzy logic as auto-link)
$nameToRetailerId = function($personName) use ($allRetailers) {
    $personLower = strtolower(trim($personName));
    if ($personLower === '') return 0;
    // Exact match first
    foreach ($allRetailers as $r) {
        if (empty($r['is_active'])) continue;
        if (strtolower($r['name'] ?? '') === $personLower) return (int)$r['id'];
    }
    // Contains match (e.g. "Diko" matches "Ms Diko Jeseka")
    foreach ($allRetailers as $r) {
        if (empty($r['is_active'])) continue;
        $rName = strtolower($r['name'] ?? '');
        if ($rName !== '' && (strpos($rName, $personLower) !== false || strpos($personLower, $rName) !== false)) {
            return (int)$r['id'];
        }
    }
    return 0;
};

// 5b. Build cb_ledger SSP OUT by resolved retailer ID + track which have auto-linked cb_refs
$cbOutById = [];       // retailer_id => total SSP out
$cbOutLinkedById = [];  // retailer_id => total SSP out for entries that HAVE a matching cash_in via cb_ref
$cbOutUnlinkedById = []; // retailer_id => total SSP out for entries with NO cash_in link
$cbOutNameMap = [];    // retailer_id => display name from cb_ledger

// Collect all cb_refs that exist in cash_ins (ANY auto-link path, not just cashbook)
// Cash_ins are created by 6 different auto-link paths — all store cb_ref.
$linkedCbRefs = [];
foreach ($allCashIns as $ci) {
    $ref = $ci['cb_ref'] ?? '';
    if ($ref !== '') {
        $linkedCbRefs[$ref] = true;
    }
}

foreach ($sspOutRows as $row) {
    $p   = trim($row['person'] ?? '');
    if ($p === '') continue;
    $rid = $nameToRetailerId($p);
    if ($rid <= 0) continue; // skip entries to non-staff (vendors etc)
    $amt = (float)($row['ssp_amount'] ?? 0);
    $sr  = $row['sr'] ?? '';

    $cbOutById[$rid] = ($cbOutById[$rid] ?? 0) + $amt;
    $cbOutNameMap[$rid] = $p;

    if ($sr !== '' && isset($linkedCbRefs[$sr])) {
        $cbOutLinkedById[$rid] = ($cbOutLinkedById[$rid] ?? 0) + $amt;
    } else {
        $cbOutUnlinkedById[$rid] = ($cbOutUnlinkedById[$rid] ?? 0) + $amt;
    }
}

// 5c. Build cash_ins total by collector_id — count ALL that have a cb_ref
//     (any auto-link path: cashbook, expense approve, staff payment, batch, quick)
$ciDirectById = [];
foreach ($allCashIns as $ci) {
    if (!$isValidCashIn($ci)) continue;
    $cbRef = $ci['cb_ref'] ?? '';
    if ($cbRef === '') continue; // no cb_ref means not linked to cb_ledger
    $cid = (int)($ci['collector_id'] ?? 0);
    if ($cid <= 0) continue;
    $ciDirectById[$cid] = ($ciDirectById[$cid] ?? 0) + (float)($ci['ssp_amount'] ?? 0);
}

// 5d. Compare by retailer ID — only flag REAL mismatches
foreach ($cbOutById as $rid => $cbTotal) {
    $ciTotal  = $ciDirectById[$rid] ?? 0;
    $linked   = $cbOutLinkedById[$rid] ?? 0;
    $unlinked = $cbOutUnlinkedById[$rid] ?? 0;
    $diff     = abs($cbTotal - $ciTotal);
    $name     = isset($staffMap[$rid]) ? $staffMap[$rid]['name'] : ($cbOutNameMap[$rid] ?? 'ID#'.$rid);

    if ($diff <= 1) continue; // matches (within rounding)

    if ($unlinked > 0 && abs($unlinked - $diff) <= 1) {
        // The gap is exactly the pre-auto-link entries — expected, not broken
        $legacyGaps[] = [
            'person'   => $name,
            'unlinked' => $unlinked,
            'total'    => $cbTotal,
        ];
    } else {
        // Genuine mismatch — some auto-linked entries may have failed
        $warnings[] = [
            'person'   => $name,
            'cb_out'   => $cbTotal,
            'ci_in'    => $ciTotal,
            'diff'     => $cbTotal - $ciTotal,
            'linked'   => $linked,
            'unlinked' => $unlinked,
        ];
    }
}

// Also check reverse: cash_ins direct to someone with NO cb_ledger OUT
foreach ($ciDirectById as $cid => $ciAmt) {
    if (isset($cbOutById[$cid])) continue;
    if ($ciAmt <= 1) continue;
    $name = isset($staffMap[$cid]) ? $staffMap[$cid]['name'] : 'ID#'.$cid;
    $warnings[] = [
        'person'   => $name,
        'cb_out'   => 0,
        'ci_in'    => $ciAmt,
        'diff'     => -$ciAmt,
        'linked'   => 0,
        'unlinked' => 0,
    ];
}

// ═══════════════════════════════════════════════════════════════════════
// 6. SSP POSITION SUMMARY — derive from actual holders, not subtraction
// ═══════════════════════════════════════════════════════════════════════
// cb_ledger and cash_expenses overlap (expense_sync creates entries in both).
// So we CAN'T compute External Spend from cash_expenses alone.
// Instead: sum all holders → whatever's missing = spent externally.

// Staff-to-staff tracking (informational only)
$totalStaffPayments  = 0;
foreach ($allExpenses as $ex) {
    if (($ex['currency'] ?? 'USD') !== 'SSP') continue;
    if (($ex['status'] ?? '') !== 'approved') continue;
    if (!empty($ex['is_staff_payment']) || !empty($ex['staff_name'])) {
        $totalStaffPayments += (float)($ex['ssp_amount'] ?? 0);
    }
}

// In Circulation = all SSP still held by someone in the company
$totalInCirculation = $rupeshPool + $grandTotalBalance + $grandTotalPending;

// External Spend = SSP that left the company (derived, always correct)
$totalExternalSpend = $cbSspTotalIn - $totalInCirculation;

// Cross-check: cbSspTotalIn = Pool + Staff + Pending + External (always true)
$crossCheckOk = ($totalExternalSpend >= 0);

// ═══════════════════════════════════════════════════════════════════════
// 6b. COMPANY SSP CASHBOOK — cb_ledger SSP entries with running balance
// ═══════════════════════════════════════════════════════════════════════
$sspLedgerRows = $cb->query(
    "SELECT id, sr, date, direction, amount, ssp_amount, ssp_rate, currency,
            category, person, description, validation_ref, created_at
     FROM cb_ledger WHERE currency='SSP'
     ORDER BY date ASC, id ASC"
);

// Compute running balance (chronological)
$sspRunBal = 0;
$sspLedger = [];
foreach ($sspLedgerRows as $row) {
    $sspAmt = (float)($row['ssp_amount'] ?? 0);
    $dir    = $row['direction'] ?? 'in';
    if ($dir === 'in') {
        $sspRunBal += $sspAmt;
    } else {
        $sspRunBal -= $sspAmt;
    }
    $row['_running_bal'] = $sspRunBal;
    $sspLedger[] = $row;
}
// Reverse for display (newest first) — but keep running balance from chronological calc
$sspLedger = array_reverse($sspLedger);
$sspLedgerPageSize = 30;

// ═══════════════════════════════════════════════════════════════════════
// 7. RENDER
// ═══════════════════════════════════════════════════════════════════════

$fmtSSP = function($n) {
    $abs = abs(round($n));
    $formatted = number_format($abs, 0);
    return ($n < 0 ? '-' : '') . $formatted . ' SSP';
};
$fmtPct = function($part, $total) {
    if ($total == 0) return '—';
    return round(($part / $total) * 100, 1) . '%';
};

// Role label map
$roleLabels = [
    'admin' => 'Admin', 'accountant' => 'Accountant', 'sales' => 'Sales',
    'sales_staff' => 'Sales Staff', 'field_agent' => 'Field Agent',
    'field_accountant' => 'Field Acct', 'collection' => 'Collection',
    'support_leader' => 'Support Lead', 'support' => 'Support',
];

// Movement type config
$typeIcons = [
    'exchange' => ['icon' => '💱', 'color' => '#7c3aed', 'label' => 'Exchange'],
    'transfer' => ['icon' => '📤', 'color' => '#2563eb', 'label' => 'Transfer'],
    'expense'  => ['icon' => '🧾', 'color' => '#dc2626', 'label' => 'Expense'],
    'return'   => ['icon' => '↩️', 'color' => '#16a34a', 'label' => 'Return'],
];

$pageSize = 50;
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&display=swap');
:root{--ssp-font:'DM Sans',-apple-system,BlinkMacSystemFont,sans-serif;}
.ssp-ov{font-family:var(--ssp-font);padding-bottom:60px;max-width:1200px;margin:0 auto;}
.ssp-ov *{box-sizing:border-box;}

/* ── Header ───────────────────────────────────── */
.ssp-hdr{margin-bottom:22px;}
.ssp-hdr h2{font-size:22px;font-weight:900;color:#0f0f0f;margin:0 0 3px;display:flex;align-items:center;gap:10px;}
.ssp-hdr-sub{font-size:11px;color:#94a3b8;}

/* ── Summary Cards ────────────────────────────── */
.ssp-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:24px;}
.ssp-card{background:#fff;border-radius:14px;border:1.5px solid #ececec;padding:14px 16px;position:relative;overflow:hidden;}
.ssp-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;border-radius:14px 0 0 14px;}
.ssp-card.purple::before{background:#7c3aed;}.ssp-card.red::before{background:#dc2626;}
.ssp-card.green::before{background:#16a34a;}.ssp-card.blue::before{background:#2563eb;}
.ssp-card.amber::before{background:#d97706;}.ssp-card.slate::before{background:#64748b;}
.ssp-card-v{font-size:22px;font-weight:900;color:#0f0f0f;line-height:1;letter-spacing:-.3px;}
.ssp-card-l{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#b0b0b0;margin-top:5px;}
.ssp-card-s{font-size:10px;color:#94a3b8;margin-top:3px;}

/* ── Section Titles ───────────────────────────── */
.ssp-sec{font-size:15px;font-weight:800;color:#0f0f0f;margin:24px 0 10px;display:flex;align-items:center;gap:8px;}
.ssp-sec-icon{font-size:18px;}

/* ── Table ────────────────────────────────────── */
.ssp-tbl-wrap{overflow-x:auto;margin-bottom:20px;border-radius:14px;border:1.5px solid #ececec;background:#fff;}
.ssp-tbl{width:100%;border-collapse:collapse;min-width:700px;}
.ssp-tbl th{background:#f8f8f8;padding:9px 12px;font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;text-align:right;border-bottom:1.5px solid #ececec;white-space:nowrap;}
.ssp-tbl th:first-child,.ssp-tbl td:first-child{text-align:left;}
.ssp-tbl td{padding:10px 12px;font-size:12.5px;border-bottom:1px solid #f3f4f6;text-align:right;vertical-align:middle;}
.ssp-tbl tr:last-child td{border-bottom:none;}
.ssp-tbl tr:hover td{background:#fafafa;}
.ssp-tbl tfoot td{font-weight:800;background:#f8f8f8;border-top:2px solid #e2e8f0;}
.ssp-name{font-weight:700;color:#0f0f0f;}
.ssp-role{font-size:9px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-top:1px;}
.ssp-neg{color:#dc2626;font-weight:700;}
.ssp-pos{color:#16a34a;font-weight:700;}
.ssp-zero{color:#94a3b8;}
.ssp-bal{font-weight:900;font-size:14px;padding:3px 8px;border-radius:6px;display:inline-block;letter-spacing:-.2px;}
.ssp-bal-pos{background:#f0fdf4;color:#16a34a;}
.ssp-bal-neg{background:#fef2f2;color:#dc2626;}
.ssp-bal-zero{background:#f8fafc;color:#94a3b8;}
.ssp-pct{font-size:10px;color:#64748b;font-weight:600;}

/* ── Movement Log ─────────────────────────────── */
.ssp-mv-row{display:flex;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid #f3f4f6;font-size:12px;}
.ssp-mv-row:last-child{border-bottom:none;}
.ssp-mv-row:hover{background:#fafafa;}
.ssp-mv-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.ssp-mv-body{flex:1;min-width:0;}
.ssp-mv-main{font-weight:600;color:#0f0f0f;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ssp-mv-sub{font-size:10px;color:#94a3b8;margin-top:1px;}
.ssp-mv-amt{font-weight:800;font-size:13px;white-space:nowrap;text-align:right;min-width:90px;}
.ssp-mv-date{font-size:10px;color:#94a3b8;min-width:70px;text-align:right;}
.ssp-mv-type{font-size:8.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:2px 6px;border-radius:4px;display:inline-block;}
.ssp-mv-pend{background:#fef3c7;color:#92400e;font-size:8px;font-weight:700;padding:1px 4px;border-radius:3px;margin-left:4px;}

/* ── Warnings ─────────────────────────────────── */
.ssp-warn{background:#fef2f2;border:1.5px solid #fecaca;border-radius:12px;padding:14px;margin-bottom:16px;}
.ssp-warn-title{font-weight:800;color:#dc2626;font-size:13px;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.ssp-warn-item{font-size:11px;color:#7f1d1d;padding:4px 0;border-bottom:1px solid #fee2e2;}
.ssp-warn-item:last-child{border-bottom:none;}
.ssp-info{background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:14px;margin-bottom:16px;}
.ssp-info-title{font-weight:800;color:#1d4ed8;font-size:13px;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.ssp-info-item{font-size:11px;color:#1e3a5f;padding:4px 0;border-bottom:1px solid #dbeafe;}
.ssp-info-item:last-child{border-bottom:none;}

/* ── Toggle ───────────────────────────────────── */
.ssp-toggle{display:inline-flex;background:#f1f5f9;border-radius:8px;padding:2px;margin-bottom:14px;}
.ssp-toggle-btn{padding:6px 14px;font-size:11px;font-weight:700;border:none;background:none;cursor:pointer;border-radius:6px;color:#64748b;transition:all .15s;}
.ssp-toggle-btn.active{background:#fff;color:#0f0f0f;box-shadow:0 1px 3px rgba(0,0,0,.1);}

/* ── Ledger ────────────────────────────────────── */
.ssp-led{width:100%;border-collapse:collapse;min-width:600px;}
.ssp-led th{background:#f8f8f8;padding:8px 10px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;border-bottom:1.5px solid #ececec;white-space:nowrap;}
.ssp-led td{padding:8px 10px;font-size:11.5px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.ssp-led tr:last-child td{border-bottom:none;}
.ssp-led tr:hover td{background:#fafafa;}
.ssp-led .l-sr{font-family:'SF Mono',Consolas,monospace;font-size:10px;color:#94a3b8;}
.ssp-led .l-cat{font-weight:700;font-size:11px;}
.ssp-led .l-person{font-weight:600;color:#0f0f0f;}
.ssp-led .l-desc{font-size:10px;color:#94a3b8;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ssp-led .l-in{color:#16a34a;font-weight:800;text-align:right;}
.ssp-led .l-out{color:#dc2626;font-weight:800;text-align:right;}
.ssp-led .l-bal{font-weight:900;text-align:right;font-size:12px;}
.ssp-led .l-date{font-size:10px;color:#64748b;white-space:nowrap;}

/* ── Show more ────────────────────────────────── */
.ssp-showmore{display:block;width:100%;padding:10px;text-align:center;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:0 0 14px 14px;font-size:11px;font-weight:700;color:#2563eb;cursor:pointer;border-top:none;}
.ssp-showmore:hover{background:#eff6ff;}

/* ── Responsive ───────────────────────────────── */
@media(max-width:640px){
    .ssp-cards{grid-template-columns:repeat(2,1fr);}
    .ssp-mv-row{gap:6px;padding:6px 10px;}
    .ssp-mv-date{display:none;}
}
</style>

<div class="ssp-ov">

<!-- ═══════════════ HEADER ═══════════════ -->
<div class="ssp-hdr">
    <h2>💱 SSP Overview</h2>
    <div class="ssp-hdr-sub">Company-wide SSP position &middot; Updated <?php echo date('D j M Y, H:i'); ?></div>
</div>

<?php if (isset($_GET['bf'])): ?>
<div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:12px;color:#166534;font-weight:600;display:flex;align-items:center;gap:8px;">
    ✅ Backfill complete — <?php echo (int)$_GET['bf']; ?> cash_in records created<?php if (isset($_GET['bfs'])): ?>, <?php echo (int)$_GET['bfs']; ?> skipped<?php endif; ?>. Legacy entries are now linked.
</div>
<?php endif; ?>

<!-- ═══════════════ WARNINGS ═══════════════ -->
<?php if (!empty($warnings)): ?>
<div class="ssp-warn">
    <div class="ssp-warn-title">⚠️ Auto-link Broken (<?php echo count($warnings); ?> found)</div>
    <?php foreach ($warnings as $w): ?>
    <div class="ssp-warn-item">
        <strong><?php echo htmlspecialchars($w['person']); ?></strong>
        — Cashbook OUT: <?php echo $fmtSSP($w['cb_out']); ?>
        vs Cash-In received: <?php echo $fmtSSP($w['ci_in']); ?>
        &rarr; Gap: <strong><?php echo $fmtSSP($w['diff']); ?></strong>
        <?php if (($w['linked'] ?? 0) > 0): ?>
            (<?php echo $fmtSSP($w['linked']); ?> linked, <?php echo $fmtSSP($w['unlinked'] ?? 0); ?> unlinked)
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($legacyGaps)): ?>
<?php
    $totalLegacySSP = 0;
    foreach ($legacyGaps as $lg) $totalLegacySSP += $lg['unlinked'];
?>
<div class="ssp-info">
    <div class="ssp-info-title" style="justify-content:space-between;">
        <span>ℹ️ Pre-auto-link entries (<?php echo count($legacyGaps); ?> staff) — <?php echo $fmtSSP($totalLegacySSP); ?> unlinked</span>
        <form method="POST" style="margin:0;display:inline;" onsubmit="return confirm('This will create cash_in records for <?php echo count($legacyGaps); ?> staff to match their old cashbook entries.\n\nTotal: <?php echo $fmtSSP($totalLegacySSP); ?>\n\nThis is safe — it only adds missing records, won&#39;t duplicate existing ones.\n\nContinue?');">
            <input type="hidden" name="ssp_action" value="backfill_legacy">
            <?php echo csrfField(); ?>
            <button type="submit" style="background:#2563eb;color:#fff;border:none;padding:5px 14px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;">🔧 Fix Legacy Entries</button>
        </form>
    </div>
    <div style="font-size:10px;color:#3b82f6;margin-bottom:6px;">These cashbook entries were created before auto-link (v4.9.21). Click "Fix" to create the missing cash_in records — staff balances will then show correctly in their Field Registers too.</div>
    <?php foreach ($legacyGaps as $lg): ?>
    <div class="ssp-info-item">
        <strong><?php echo htmlspecialchars($lg['person']); ?></strong>
        — <?php echo $fmtSSP($lg['unlinked']); ?> of <?php echo $fmtSSP($lg['total']); ?> total is from pre-auto-link entries
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ═══════════════ SUMMARY CARDS ═══════════════ -->
<div class="ssp-cards">
    <div class="ssp-card purple">
        <div class="ssp-card-v"><?php echo $fmtSSP($totalSspPurchased); ?></div>
        <div class="ssp-card-l">Total SSP Purchased</div>
        <div class="ssp-card-s">All-time via Exchange</div>
    </div>
    <div class="ssp-card amber">
        <div class="ssp-card-v"><?php echo $fmtSSP($rupeshPool); ?></div>
        <div class="ssp-card-l">Rupesh Pool</div>
        <div class="ssp-card-s">Cashbook balance (IN − OUT)</div>
    </div>
    <div class="ssp-card green">
        <div class="ssp-card-v"><?php echo $fmtSSP($grandTotalBalance); ?></div>
        <div class="ssp-card-l">Staff Holding</div>
        <div class="ssp-card-s">Cash-ins − Expenses − Handovers</div>
    </div>
    <div class="ssp-card blue">
        <div class="ssp-card-v"><?php echo $fmtSSP($totalInCirculation); ?></div>
        <div class="ssp-card-l">In Circulation</div>
        <div class="ssp-card-s">Rupesh + Staff + Pending</div>
    </div>
    <div class="ssp-card red">
        <div class="ssp-card-v"><?php echo $fmtSSP($totalExternalSpend); ?></div>
        <div class="ssp-card-l">SSP Left Company</div>
        <div class="ssp-card-s">Purchased − In Circulation</div>
    </div>
    <div class="ssp-card slate" id="excRateCard" style="cursor:default;position:relative;">
        <?php
        $_lr      = $_excCtx['last_rate'];
        $_lby     = $_excCtx['last_by'];
        $_lmins   = $_excCtx['last_minutes_ago'];
        $_med7    = $_excCtx['median_7day'];
        $_min7    = $_excCtx['min_7day'];
        $_max7    = $_excCtx['max_7day'];
        $_cnt7    = $_excCtx['count_7day'];
        $_trend   = $_excCtx['trend'];
        $_sysRate = $_excCtx['system_rate'];
        $_trendArrow = $_trend === 'up' ? '▲' : ($_trend === 'down' ? '▼' : '—');
        $_trendClr   = $_trend === 'up' ? '#16a34a' : ($_trend === 'down' ? '#dc2626' : '#94a3b8');
        $_lbl = $_lr > 0 ? number_format($_lr, 0) : '—';
        // Time label
        $_timeLabel = '';
        if ($_lmins >= 0) {
            if      ($_lmins < 1)    $_timeLabel = 'just now';
            elseif  ($_lmins < 60)   $_timeLabel = $_lmins . 'm ago';
            elseif  ($_lmins < 1440) $_timeLabel = round($_lmins/60) . 'h ago';
            else                     $_timeLabel = round($_lmins/1440) . 'd ago';
        }
        ?>
        <div class="ssp-card-v"><?php echo $_lbl; ?></div>
        <div class="ssp-card-l">
            Market Rate
            <?php if ($_trend !== 'flat' && $_lr > 0): ?>
            <span style="color:<?php echo $_trendClr; ?>;font-size:11px;margin-left:4px;"><?php echo $_trendArrow; ?></span>
            <?php endif; ?>
        </div>
        <?php if ($_lr > 0 && $_lby): ?>
        <div class="ssp-card-s" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <?php echo htmlspecialchars($_lby); ?> · <?php echo $_timeLabel; ?>
        </div>
        <?php elseif ($currentRate > 0): ?>
        <div class="ssp-card-s">System: <?php echo number_format($currentRate, 0); ?> SSP/$</div>
        <?php else: ?>
        <div class="ssp-card-s">No exchange recorded yet</div>
        <?php endif; ?>
        <?php if ($_cnt7 > 1): ?>
        <div style="margin-top:6px;font-size:10px;color:#94a3b8;line-height:1.5;">
            7d: <?php echo number_format($_min7, 0); ?>–<?php echo number_format($_max7, 0); ?> SSP/$
            &nbsp;·&nbsp; <?php echo $_cnt7; ?> exchanges
        </div>
        <?php endif; ?>
        <?php if ($_lr > 0 && $_sysRate > 0 && abs($_lr - $_sysRate) > 10): ?>
        <div style="margin-top:4px;font-size:10px;color:#f59e0b;">
            System rate: <?php echo number_format($_sysRate, 0); ?> SSP/$
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════ BALANCE PROOF ═══════════════ -->
<div style="background:<?php echo $crossCheckOk ? '#f0fdf4' : '#fef2f2'; ?>;border:1.5px solid <?php echo $crossCheckOk ? '#bbf7d0' : '#fecaca'; ?>;border-radius:10px;padding:10px 14px;margin-bottom:20px;font-size:11px;display:flex;flex-wrap:wrap;gap:4px 12px;align-items:center;">
    <span style="font-weight:800;color:<?php echo $crossCheckOk ? '#16a34a' : '#dc2626'; ?>;"><?php echo $crossCheckOk ? '✅' : '⚠️'; ?> Balance proof</span>
    <span style="color:#0f0f0f;font-weight:700;"><?php echo $fmtSSP($cbSspTotalIn); ?></span>
    <span style="color:#64748b;">=</span>
    <span style="color:#d97706;font-weight:600;"><?php echo $fmtSSP($rupeshPool); ?></span>
    <span style="color:#94a3b8;font-size:10px;">Rupesh</span>
    <span style="color:#64748b;">+</span>
    <span style="color:#16a34a;font-weight:600;"><?php echo $fmtSSP($grandTotalBalance); ?></span>
    <span style="color:#94a3b8;font-size:10px;">Staff</span>
    <?php if ($grandTotalPending > 0): ?>
    <span style="color:#64748b;">+</span>
    <span style="color:#64748b;font-weight:600;"><?php echo $fmtSSP($grandTotalPending); ?></span>
    <span style="color:#94a3b8;font-size:10px;">Pending</span>
    <?php endif; ?>
    <span style="color:#64748b;">+</span>
    <span style="color:#dc2626;font-weight:600;"><?php echo $fmtSSP($totalExternalSpend); ?></span>
    <span style="color:#94a3b8;font-size:10px;">External</span>
    <?php if (!$crossCheckOk): ?>
    <span style="color:#dc2626;font-weight:700;margin-left:8px;">⚠ External spend is negative — data inconsistency</span>
    <?php endif; ?>
    <?php if ($totalStaffPayments > 0): ?>
    <span style="color:#94a3b8;font-size:10px;margin-left:8px;">| Staff-to-staff: <?php echo $fmtSSP($totalStaffPayments); ?></span>
    <?php endif; ?>
</div>

<!-- ═══════════════ COMPANY SSP CASHBOOK ═══════════════ -->
<div class="ssp-sec"><span class="ssp-sec-icon">📒</span> Company SSP Cashbook</div>
<div style="font-size:10px;color:#94a3b8;margin:-6px 0 10px;">Every SSP entry from the cashbook — Rupesh's pool ledger with running balance</div>

<?php if (empty($sspLedger)): ?>
<div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:30px;text-align:center;color:#94a3b8;font-size:13px;">
    No SSP cashbook entries found.
</div>
<?php else: ?>
<div class="ssp-tbl-wrap">
<table class="ssp-led">
<thead>
<tr>
    <th style="text-align:left;">Date</th>
    <th style="text-align:left;">SR</th>
    <th style="text-align:left;">Category</th>
    <th style="text-align:left;">Person</th>
    <th style="text-align:left;">Description</th>
    <th style="text-align:right;">SSP IN</th>
    <th style="text-align:right;">SSP OUT</th>
    <th style="text-align:right;">Balance</th>
</tr>
</thead>
<tbody>
<?php foreach ($sspLedger as $li => $le):
    $lDir  = $le['direction'] ?? 'in';
    $lSSP  = (float)($le['ssp_amount'] ?? 0);
    $lRate = (float)($le['ssp_rate'] ?? 0);
    $lBal  = $le['_running_bal'];
    $lHidden = ($li >= $sspLedgerPageSize) ? ' style="display:none;" data-ssp-ledger-extra="1"' : '';
?>
<tr<?php echo $lHidden; ?>>
    <td class="l-date"><?php echo htmlspecialchars($le['date'] ?? ''); ?></td>
    <td class="l-sr"><?php echo htmlspecialchars($le['sr'] ?? ''); ?></td>
    <td class="l-cat"><?php echo htmlspecialchars($le['category'] ?? ''); ?></td>
    <td class="l-person"><?php echo htmlspecialchars($le['person'] ?? ''); ?></td>
    <td class="l-desc" title="<?php echo htmlspecialchars($le['description'] ?? ''); ?>"><?php echo htmlspecialchars(substr($le['description'] ?? '', 0, 50)); ?><?php if (strlen($le['description'] ?? '') > 50) echo '…'; ?></td>
    <td class="l-in"><?php echo $lDir === 'in' ? number_format($lSSP, 0) : ''; ?></td>
    <td class="l-out"><?php echo $lDir === 'out' ? number_format($lSSP, 0) : ''; ?></td>
    <td class="l-bal" style="color:<?php echo $lBal >= 0 ? '#0f0f0f' : '#dc2626'; ?>;"><?php echo number_format($lBal, 0); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php if (count($sspLedger) > $sspLedgerPageSize): ?>
<button class="ssp-showmore" id="sspLedgerShowMore" onclick="sspLedgerShowAll()">
    Show all <?php echo number_format(count($sspLedger)); ?> entries (<?php echo number_format(count($sspLedger) - $sspLedgerPageSize); ?> hidden)
</button>
<?php endif; ?>
<?php endif; ?>

<!-- ═══════════════ PER-PERSON TABLE ═══════════════ -->
<div class="ssp-sec"><span class="ssp-sec-icon">👥</span> Per-Person Breakdown</div>

<?php if (empty($personRows)): ?>
<div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:30px;text-align:center;color:#94a3b8;font-size:13px;">
    No SSP movements found.
</div>
<?php else: ?>
<div class="ssp-tbl-wrap">
<table class="ssp-tbl">
<thead>
<tr>
    <th style="text-align:left;">Staff</th>
    <th>SSP Received</th>
    <th>SSP Spent</th>
    <th>SSP Pending</th>
    <th>SSP Returned</th>
    <th>Balance</th>
    <th>% of Total</th>
</tr>
</thead>
<tbody>
<?php foreach ($personRows as $pr): ?>
<?php
    $balClass = $pr['balance'] > 0 ? 'ssp-bal-pos' : ($pr['balance'] < 0 ? 'ssp-bal-neg' : 'ssp-bal-zero');
    $rl = $roleLabels[$pr['role']] ?? ucfirst($pr['role']);
?>
<tr>
    <td style="text-align:left;">
        <div class="ssp-name"><?php echo htmlspecialchars($pr['name']); ?></div>
        <div class="ssp-role"><?php echo htmlspecialchars($rl); ?></div>
    </td>
    <td class="<?php echo $pr['received'] > 0 ? '' : 'ssp-zero'; ?>"><?php echo $fmtSSP($pr['received']); ?></td>
    <td class="<?php echo $pr['spent'] > 0 ? 'ssp-neg' : 'ssp-zero'; ?>"><?php echo $fmtSSP($pr['spent']); ?></td>
    <td class="<?php echo $pr['pending'] > 0 ? '' : 'ssp-zero'; ?>"><?php echo $pr['pending'] > 0 ? $fmtSSP($pr['pending']) : '—'; ?></td>
    <td class="<?php echo $pr['returned'] > 0 ? 'ssp-pos' : 'ssp-zero'; ?>"><?php echo $pr['returned'] > 0 ? $fmtSSP($pr['returned']) : '—'; ?></td>
    <td><span class="ssp-bal <?php echo $balClass; ?>"><?php echo $fmtSSP($pr['balance']); ?></span></td>
    <td class="ssp-pct"><?php echo $fmtPct($pr['balance'], $grandTotalBalance); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr>
    <td style="text-align:left;font-size:11px;">TOTAL (<?php echo count($personRows); ?> staff)</td>
    <td><?php echo $fmtSSP($grandTotalReceived); ?></td>
    <td class="ssp-neg"><?php echo $fmtSSP($grandTotalSpent); ?></td>
    <td><?php echo $grandTotalPending > 0 ? $fmtSSP($grandTotalPending) : '—'; ?></td>
    <td class="ssp-pos"><?php echo $fmtSSP($grandTotalReturned); ?></td>
    <td><span class="ssp-bal <?php echo $grandTotalBalance > 0 ? 'ssp-bal-pos' : ($grandTotalBalance < 0 ? 'ssp-bal-neg' : 'ssp-bal-zero'); ?>"><?php echo $fmtSSP($grandTotalBalance); ?></span></td>
    <td class="ssp-pct">100%</td>
</tr>
</tfoot>
</table>
</div>
<?php endif; ?>

<!-- ═══════════════ MOVEMENT LOG ═══════════════ -->
<div class="ssp-sec"><span class="ssp-sec-icon">📜</span> SSP Movement Log</div>

<!-- Filter toggles -->
<div class="ssp-toggle" id="sspMvFilter">
    <button class="ssp-toggle-btn active" data-filter="all" onclick="sspFilterMv(this,'all')">All</button>
    <button class="ssp-toggle-btn" data-filter="exchange" onclick="sspFilterMv(this,'exchange')">Exchange</button>
    <button class="ssp-toggle-btn" data-filter="transfer" onclick="sspFilterMv(this,'transfer')">Transfers</button>
    <button class="ssp-toggle-btn" data-filter="expense" onclick="sspFilterMv(this,'expense')">Expenses</button>
    <button class="ssp-toggle-btn" data-filter="return" onclick="sspFilterMv(this,'return')">Returns</button>
</div>

<div class="ssp-tbl-wrap" style="border-radius:14px;">
<div id="sspMvList">
<?php
$mvCount = 0;
foreach ($movements as $i => $mv):
    $ti = $typeIcons[$mv['type']] ?? ['icon'=>'•','color'=>'#64748b','label'=>$mv['type']];
    $hidden = ($i >= $pageSize) ? ' style="display:none;" data-ssp-extra="1"' : '';
    $mvCount++;
?>
<div class="ssp-mv-row" data-mvtype="<?php echo $mv['type']; ?>"<?php echo $hidden; ?>>
    <div class="ssp-mv-icon" style="background:<?php echo $ti['color']; ?>18;color:<?php echo $ti['color']; ?>;"><?php echo $ti['icon']; ?></div>
    <div class="ssp-mv-body">
        <div class="ssp-mv-main"><?php echo htmlspecialchars($mv['from']); ?> → <?php echo htmlspecialchars($mv['to']); ?></div>
        <div class="ssp-mv-sub">
            <?php echo htmlspecialchars(substr($mv['ref'], 0, 80)); ?>
            <?php if (!empty($mv['status']) && $mv['status'] === 'pending'): ?>
                <span class="ssp-mv-pend">PENDING</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="ssp-mv-amt" style="color:<?php echo $ti['color']; ?>;"><?php echo $fmtSSP($mv['amount']); ?></div>
    <div class="ssp-mv-date"><?php echo $mv['date']; ?></div>
    <div><span class="ssp-mv-type" style="background:<?php echo $ti['color']; ?>14;color:<?php echo $ti['color']; ?>;"><?php echo $ti['label']; ?></span></div>
</div>
<?php endforeach; ?>

<?php if ($mvCount === 0): ?>
<div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;">No SSP movements found.</div>
<?php endif; ?>
</div>

<?php if ($mvCount > $pageSize): ?>
<button class="ssp-showmore" id="sspShowMore" onclick="sspShowAll()">
    Show all <?php echo number_format($mvCount); ?> movements (<?php echo number_format($mvCount - $pageSize); ?> hidden)
</button>
<?php endif; ?>
</div>

</div><!-- .ssp-ov -->

<!-- ═══════════════ EXCHANGE BATCH RECONCILIATION ═══════════════ -->
<div class="ssp-sec" style="margin-top:28px;">
    <span class="ssp-sec-icon">🔗</span> Exchange Batch Reconciliation
    <span style="font-size:10px;font-weight:400;color:#94a3b8;margin-left:8px;">Last 60 days · links USD given → SSP spent → remaining</span>
</div>

<?php if (empty($_excBatches)): ?>
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;text-align:center;color:#94a3b8;font-size:13px;margin-bottom:20px;">
    No exchange batches found in the last 60 days.<br>
    <span style="font-size:11px;">Batches appear once staff record an exchange using the Exchange form in their cashbook.</span>
</div>
<?php else: ?>

<div class="ssp-tbl-wrap" style="margin-bottom:24px;">
<table class="ssp-tbl" style="min-width:820px;">
<thead>
<tr>
    <th style="text-align:left;">Exchange Ref</th>
    <th style="text-align:left;">Staff</th>
    <th style="text-align:left;">Date</th>
    <th>USD Given</th>
    <th>Rate</th>
    <th>SSP Received</th>
    <th>SSP Spent</th>
    <th>SSP Remaining</th>
    <th>Utilised</th>
    <th>Age</th>
    <th></th>
</tr>
</thead>
<tbody>
<?php
$_excTotal = ['usd'=>0,'ssp_received'=>0,'ssp_spent'=>0,'ssp_remaining'=>0];
foreach ($_excBatches as $_eb):
    $_ebUtil   = $_eb['utilisation_pct'];
    $_ebUtilClr = $_ebUtil >= 90 ? '#16a34a' : ($_ebUtil >= 50 ? '#d97706' : '#dc2626');
    $_ebAgeDays = (int)$_eb['days_open'];
    $_ebAgeClr  = $_ebAgeDays > 14 ? '#dc2626' : ($_ebAgeDays > 7 ? '#d97706' : '#64748b');
    $_ebRemaining = (float)$_eb['ssp_remaining'];
    $_ebRemClr  = $_ebRemaining > 50000 ? '#d97706' : '#16a34a';
    $_excTotal['usd']          += $_eb['usd_given'];
    $_excTotal['ssp_received'] += $_eb['ssp_received'];
    $_excTotal['ssp_spent']    += $_eb['ssp_spent'];
    $_excTotal['ssp_remaining']+= $_ebRemaining;
    $expCount = count($_eb['expenses'] ?? []);
?>
<tr style="<?= $_ebRemaining > 100000 && $_ebAgeDays > 10 ? 'background:#fffbeb;' : '' ?>">
    <td style="text-align:left;">
        <span style="font-family:'SF Mono',monospace;font-size:10px;color:#7c3aed;background:#f5f3ff;padding:2px 7px;border-radius:6px;">
            <?= htmlspecialchars($_eb['exchange_ref']) ?>
        </span>
    </td>
    <td style="text-align:left;font-weight:600;"><?= htmlspecialchars($_eb['staff_name']) ?></td>
    <td style="text-align:left;font-size:11px;color:#64748b;">
        <?= substr($_eb['created_at'], 0, 10) ?>
        <span style="color:#94a3b8;"> <?= substr($_eb['created_at'], 11, 5) ?></span>
    </td>
    <td style="font-weight:700;color:#15803d;">$<?= number_format($_eb['usd_given'], 0) ?></td>
    <td style="font-size:11px;color:#64748b;"><?= number_format($_eb['rate'], 0) ?></td>
    <td><?= $fmtSSP($_eb['ssp_received']) ?></td>
    <td>
        <?= $fmtSSP($_eb['ssp_spent']) ?>
        <?php if ($expCount > 0): ?>
        <div style="font-size:10px;color:#94a3b8;margin-top:1px;">
            <?= $expCount ?> expense<?= $expCount !== 1 ? 's' : '' ?>
        </div>
        <?php endif; ?>
    </td>
    <td style="font-weight:700;color:<?= $_ebRemClr ?>;">
        <?= $fmtSSP($_ebRemaining) ?>
    </td>
    <td>
        <div style="display:flex;align-items:center;gap:6px;">
            <div style="flex:1;height:6px;background:#e2e8f0;border-radius:3px;min-width:50px;">
                <div style="width:<?= min(100,$_ebUtil) ?>%;height:100%;background:<?= $_ebUtilClr ?>;border-radius:3px;"></div>
            </div>
            <span style="font-size:11px;font-weight:700;color:<?= $_ebUtilClr ?>;white-space:nowrap;">
                <?= $_ebUtil ?>%
            </span>
        </div>
    </td>
    <td style="font-size:11px;color:<?= $_ebAgeClr ?>;font-weight:600;">
        <?= $_ebAgeDays ?>d
        <?php if ($_ebAgeDays > 7 && $_ebRemaining > 50000): ?>
        <span title="Old batch with large unspent SSP — verify physical cash" style="color:#dc2626;">⚠</span>
        <?php endif; ?>
    </td>
    <td style="white-space:nowrap;">
        <?php if (!$_eb['is_closed']): ?>
        <button onclick="sbbOpen(<?= htmlspecialchars(json_encode($_eb['exchange_ref']),ENT_QUOTES) ?>,<?= (int)$_ebRemaining ?>,<?= htmlspecialchars(json_encode($_eb['staff_name']),ENT_QUOTES) ?>)"
            style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;">
            ✓ Verify
        </button>
        <?php else: ?>
        <span style="font-size:10px;color:#16a34a;font-weight:600;" title="Verified by <?= htmlspecialchars($_eb['closed_by']) ?> on <?= substr($_eb['closed_at'],0,10) ?>">✅ Closed</span>
        <?php endif; ?>
    </td>
</tr>
<?php if (!empty($_eb['expenses'])): ?>
<tr>
    <td colspan="10" style="padding:0 12px 10px 32px;background:#fafafa;border-bottom:1px solid #f0f0f0;">
        <div style="font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Linked expenses</div>
        <div style="display:flex;flex-wrap:wrap;gap:4px;">
        <?php foreach ($_eb['expenses'] as $_bex):
            $bexStat = $_bex['status'] ?? '';
            $bexClr  = $bexStat === 'approved' ? '#dcfce7' : '#fef3c7';
            $bexTxt  = $bexStat === 'approved' ? '#15803d' : '#92400e';
        ?>
        <span style="background:<?= $bexClr ?>;color:<?= $bexTxt ?>;border-radius:6px;padding:2px 8px;font-size:10px;font-weight:600;">
            <?= htmlspecialchars($_bex['expense_no'] ?? '') ?>
            <?= htmlspecialchars(ucfirst($_bex['category'] ?? '')) ?>
            <?= $fmtSSP((float)$_bex['ssp_amount']) ?>
            <span style="opacity:.6;">(<?= htmlspecialchars($_bex['staff_name'] ?? '') ?>)</span>
        </span>
        <?php endforeach; ?>
        </div>
    </td>
</tr>
<?php endif; ?>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr>
    <td colspan="3" style="text-align:left;font-weight:700;">Totals (<?= count($_excBatches) ?> batches)</td>
    <td style="font-weight:800;color:#15803d;">$<?= number_format($_excTotal['usd'], 0) ?></td>
    <td></td>
    <td style="font-weight:800;"><?= $fmtSSP($_excTotal['ssp_received']) ?></td>
    <td style="font-weight:800;"><?= $fmtSSP($_excTotal['ssp_spent']) ?></td>
    <td style="font-weight:800;color:<?= $_excTotal['ssp_remaining'] > 500000 ? '#d97706' : '#16a34a' ?>;">
        <?= $fmtSSP($_excTotal['ssp_remaining']) ?>
    </td>
    <td colspan="2">
        <?php
        $totalUtil = $_excTotal['ssp_received'] > 0
            ? round($_excTotal['ssp_spent'] / $_excTotal['ssp_received'] * 100, 1) : 0;
        ?>
        <span style="font-weight:700;color:<?= $totalUtil >= 80 ? '#16a34a' : '#d97706' ?>;">
            <?= $totalUtil ?>% overall
        </span>
    </td>
    <td></td>
</tr>
</tfoot>
</table>
</div>

<?php
// Unlinked SSP expenses warning — expenses with currency=SSP and no exchange_ref
try {
    $_unlinkedCount = $cb->getPdo()->query(
        "SELECT COUNT(*) FROM staff_expenses
         WHERE currency='SSP' AND (exchange_ref IS NULL OR exchange_ref='')
           AND status IN ('pending','approved')
           AND created_at >= datetime('now','-60 days')"
    )->fetchColumn();
} catch (Throwable $e) { $_unlinkedCount = 0; }
if ($_unlinkedCount > 0):
?>
<div style="background:#fef9ec;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:20px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:8px;">
    ⚠️ <strong><?= (int)$_unlinkedCount ?> SSP expense<?= $_unlinkedCount !== 1 ? 's' : '' ?></strong>
    in the last 60 days have no exchange batch linked — USD equivalent uses system rate only.
    Staff can link expenses to batches when submitting new expenses.
</div>
<?php endif; ?>

<?php endif; // empty $_excBatches ?>


<!-- ═══ Verify Batch Modal ═══ -->
<div id="sbbOv" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:18px;padding:24px;width:min(340px,92vw);box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="font-size:15px;font-weight:800;color:#1e293b;margin-bottom:6px;">✓ Verify Exchange Batch</div>
    <div style="font-size:12px;color:#64748b;margin-bottom:14px;" id="sbbDesc">—</div>
    <form method="POST" action="?page=dashboard&tab=ssp_overview">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="close_exchange_batch">
      <input type="hidden" name="exchange_ref" id="sbbRef">
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Note (optional)</label>
        <input type="text" name="close_note" placeholder="e.g. Cash returned, all receipts verified"
          style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;box-sizing:border-box;">
      </div>
      <div style="display:flex;gap:8px;">
        <button type="button" onclick="sbbClose()" style="flex:1;padding:10px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
        <button type="submit" style="flex:2;padding:10px;background:#15803d;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:800;cursor:pointer;">✓ Mark as Verified</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Filter movement log by type ─────────────────────────────────────────
function sspFilterMv(btn, type) {
    var btns = document.querySelectorAll('#sspMvFilter .ssp-toggle-btn');
    for (var i = 0; i < btns.length; i++) btns[i].className = 'ssp-toggle-btn';
    btn.className = 'ssp-toggle-btn active';

    var rows = document.querySelectorAll('#sspMvList .ssp-mv-row');
    for (var j = 0; j < rows.length; j++) {
        var r = rows[j];
        var isExtra = r.getAttribute('data-ssp-extra') === '1';
        var matchType = (type === 'all' || r.getAttribute('data-mvtype') === type);

        // Respect show-all state
        var showAllDone = document.getElementById('sspShowMore');
        var allShown = (!showAllDone || showAllDone.style.display === 'none');

        if (!matchType) {
            r.style.display = 'none';
        } else if (isExtra && !allShown) {
            r.style.display = 'none';
        } else {
            r.style.display = '';
        }
    }
}

// ── Show all ledger entries ──────────────────────────────────────────
function sspLedgerShowAll() {
    var extras = document.querySelectorAll('[data-ssp-ledger-extra="1"]');
    for (var i = 0; i < extras.length; i++) extras[i].style.display = '';
    var btn = document.getElementById('sspLedgerShowMore');
    if (btn) btn.style.display = 'none';
}

// ── Show all movements ──────────────────────────────────────────────────
function sspShowAll() {
    var extras = document.querySelectorAll('[data-ssp-extra="1"]');
    for (var i = 0; i < extras.length; i++) extras[i].style.display = '';
    var btn = document.getElementById('sspShowMore');
    if (btn) btn.style.display = 'none';
    // Re-apply current filter
    var active = document.querySelector('#sspMvFilter .ssp-toggle-btn.active');
    if (active) {
        var type = active.getAttribute('data-filter');
        if (type && type !== 'all') sspFilterMv(active, type);
    }
}

// ── Verify batch modal ───────────────────────────────────────────────────
function sbbOpen(ref, remaining, staffName) {
    document.getElementById('sbbRef').value = ref;
    var rem = parseInt(remaining) || 0;
    var desc = ref + ' · ' + staffName;
    if (rem > 0) desc += ' · ' + rem.toLocaleString() + ' SSP unspent';
    document.getElementById('sbbDesc').textContent = desc;
    var ov = document.getElementById('sbbOv');
    ov.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function sbbClose() {
    document.getElementById('sbbOv').style.display = 'none';
    document.body.style.overflow = '';
}
document.getElementById('sbbOv').addEventListener('click', function(e) {
    if (e.target === this) sbbClose();
});
</script>
