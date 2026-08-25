<?php
/**
 * ledger_health.php — Admin Reconciliation Tool for Unified Staff Ledger
 *
 * DishNet Hybrid v4.11.3
 *
 * Shows: every staff member, ledger balance vs JSON balance, mismatches
 * Actions: Rebuild from JSON, Force reconcile, Toggle ledger on/off
 *
 * PHP 7.4 compatible.
 */
if (!($retailer['is_admin'] ?? false)) {
    echo '<div class="alert alert-danger">Admin access required.</div>';
    return;
}

require_once dirname(__DIR__, 2) . '/lib/DualReadCashPosition.php';
require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
require_once dirname(__DIR__, 2) . '/lib/StaffCashPositionService.php';

$pdo    = $store->getPdo();
$config = $store->load('kyc_config.json') ?? [];
$ledgerEnabled = ($config['ledger_enabled'] ?? true) !== false;

// ── POST Handlers ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $lhAction = $_POST['lh_action'] ?? '';

    if ($lhAction === 'toggle_ledger') {
        $config['ledger_enabled'] = !$ledgerEnabled;
        $store->save('kyc_config.json', $config);
        $ledgerEnabled = $config['ledger_enabled'];
        flash($ledgerEnabled ? '✅ Ledger reads ENABLED — using staff_ledger as primary.' : '⚠️ Ledger reads DISABLED — using JSON files as primary.', $ledgerEnabled ? 'success' : 'warning');
        header('Location: ?page=dashboard&tab=ledger_health');
        exit;
    }

    if ($lhAction === 'rebuild_from_json') {
        // Wipe staff_ledger and re-import from JSON sources.
        // SAFETY: entire operation runs in a single transaction so a backfill
        // failure rolls back the DELETE — the table is never left empty.
        // ADMIN_RESET_OK: intentional full rebuild inside transaction with immediate backfill
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM staff_ledger'); // ADMIN_RESET_OK
            $isApiCall = true;
            include dirname(__DIR__, 2) . '/backfill_staff_ledger.php';
            $pdo->commit();
            flash("✅ Rebuilt staff_ledger from JSON: {$backfillResult['total_inserted']} rows imported.", 'success');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            flash('❌ Rebuild failed (rolled back — no data lost): ' . $e->getMessage(), 'danger');
        }
        header('Location: ?page=dashboard&tab=ledger_health');
        exit;
    }

    if ($lhAction === 'force_reconcile') {
        // Re-run backfill (idempotent — fills gaps without duplicating)
        try {
            $isApiCall = true;
            include dirname(__DIR__, 2) . '/backfill_staff_ledger.php';
            // Clear mismatch log
            $dual = new DualReadCashPosition($store, $pdo, $dataDir);
            $dual->clearMismatchLog();
            flash("✅ Force reconcile: {$backfillResult['total_inserted']} new rows, {$backfillResult['total_skipped']} existing. Mismatch log cleared.", 'success');
        } catch (\Throwable $e) {
            flash('❌ Reconcile failed: ' . $e->getMessage(), 'danger');
        }
        header('Location: ?page=dashboard&tab=ledger_health');
        exit;
    }

    if ($lhAction === 'clear_log') {
        $dual = new DualReadCashPosition($store, $pdo, $dataDir);
        $dual->clearMismatchLog();
        flash('✅ Mismatch log cleared.', 'success');
        header('Location: ?page=dashboard&tab=ledger_health');
        exit;
    }
}

// ── Load Data ────────────────────────────────────────────────────────────
$ledger  = new StaffLedgerService($pdo);
$oldSvc  = new StaffCashPositionService($store, $pdo);
$dual    = new DualReadCashPosition($store, $pdo, $dataDir);

$newAll  = $ledger->allPositions('USD');
$oldAll  = $oldSvc->getAllPositions();

$allIds  = array_unique(array_merge(array_keys($newAll), array_keys($oldAll)));
sort($allIds);

$rows = [];
$matchCount = 0;
$mismatchCount = 0;

foreach ($allIds as $sid) {
    $newExp = round((float)($newAll[$sid]['cash_exposure'] ?? 0), 2);
    $oldExp = round((float)($oldAll[$sid]['cash_exposure'] ?? 0), 2);
    $delta  = round(abs($newExp - $oldExp), 2);
    $match  = $delta <= 0.01;

    $name = $newAll[$sid]['staff_name'] ?? $oldAll[$sid]['staff_name'] ?? 'ID#' . $sid;

    $rows[] = [
        'id'     => $sid,
        'name'   => $name,
        'ledger' => $newExp,
        'json'   => $oldExp,
        'delta'  => $delta,
        'match'  => $match,
    ];

    if ($match) $matchCount++; else $mismatchCount++;
}

// Sort by delta descending (biggest mismatches first)
usort($rows, function ($a, $b) { return $b['delta'] <=> $a['delta']; });

$totalLedgerRows = $ledger->totalRows();
$bySource        = $ledger->countBySource();
$mismatches      = $dual->getMismatches(20);
$activeMm        = $dual->getActiveMismatches();

$statusColor = $mismatchCount === 0 ? '#15803d' : '#dc2626';
$statusText  = $mismatchCount === 0 ? '✅ All balances match' : "⚠️ {$mismatchCount} mismatch(es) detected";
?>

<style>
.lh-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:16px; }
.lh-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:20px; }
.lh-stat { text-align:center; padding:16px; background:#f8fafc; border-radius:8px; }
.lh-stat-val { font-size:28px; font-weight:900; }
.lh-stat-lbl { font-size:12px; color:#64748b; margin-top:4px; }
.lh-tbl { width:100%; border-collapse:collapse; font-size:13px; }
.lh-tbl th { background:#f1f5f9; padding:8px 12px; text-align:left; font-weight:600; border-bottom:2px solid #e2e8f0; }
.lh-tbl td { padding:8px 12px; border-bottom:1px solid #f1f5f9; }
.lh-tbl tr:hover { background:#f8fafc; }
.lh-match { color:#15803d; font-weight:600; }
.lh-diff  { color:#dc2626; font-weight:700; background:#fef2f2; }
.lh-btn { display:inline-block; padding:8px 16px; border-radius:6px; font-size:13px; font-weight:600; border:none; cursor:pointer; text-decoration:none; }
.lh-btn-green { background:#15803d; color:#fff; }
.lh-btn-red   { background:#dc2626; color:#fff; }
.lh-btn-blue  { background:#2563eb; color:#fff; }
.lh-btn-gray  { background:#64748b; color:#fff; }
.lh-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
.lh-flag { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; }
</style>

<div class="lh-card">
    <h2 style="margin:0 0 16px 0; font-size:20px;">🏥 Ledger Health Dashboard</h2>

    <!-- Status & Stats -->
    <div class="lh-grid">
        <div class="lh-stat">
            <div class="lh-stat-val" style="color:<?= $statusColor ?>"><?= $mismatchCount === 0 ? '✓' : $mismatchCount ?></div>
            <div class="lh-stat-lbl"><?= $statusText ?></div>
        </div>
        <div class="lh-stat">
            <div class="lh-stat-val"><?= number_format($totalLedgerRows) ?></div>
            <div class="lh-stat-lbl">Ledger Rows</div>
        </div>
        <div class="lh-stat">
            <div class="lh-stat-val"><?= count($allIds) ?></div>
            <div class="lh-stat-lbl">Staff Tracked</div>
        </div>
        <div class="lh-stat">
            <div class="lh-stat-val">
                <span class="lh-flag" style="background:<?= $ledgerEnabled ? '#dcfce7;color:#15803d' : '#fef2f2;color:#dc2626' ?>">
                    <?= $ledgerEnabled ? 'ON' : 'OFF' ?>
                </span>
            </div>
            <div class="lh-stat-lbl">Ledger Reads</div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="lh-actions">
        <form method="POST" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="lh_action" value="toggle_ledger">
            <button type="submit" class="lh-btn <?= $ledgerEnabled ? 'lh-btn-red' : 'lh-btn-green' ?>"
                onclick="return confirm('<?= $ledgerEnabled ? 'Disable ledger reads? All views will revert to JSON files instantly.' : 'Enable ledger reads? Views will use staff_ledger as primary source.' ?>')">
                <?= $ledgerEnabled ? '⏸ Disable Ledger Reads' : '▶ Enable Ledger Reads' ?>
            </button>
        </form>
        <form method="POST" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="lh_action" value="force_reconcile">
            <button type="submit" class="lh-btn lh-btn-blue"
                onclick="return confirm('Run force reconcile? This fills gaps in staff_ledger from JSON files (idempotent, safe).')">
                🔄 Force Reconcile
            </button>
        </form>
        <form method="POST" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="lh_action" value="rebuild_from_json">
            <button type="submit" class="lh-btn lh-btn-red"
                onclick="return confirm('⚠️ REBUILD: This will DELETE all staff_ledger rows and re-import from JSON files.\n\nUse this if ledger data is corrupted.\n\nContinue?')">
                🔨 Rebuild from JSON
            </button>
        </form>
        <?php if (!empty($mismatches)): ?>
        <form method="POST" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="lh_action" value="clear_log">
            <button type="submit" class="lh-btn lh-btn-gray">🗑 Clear Mismatch Log</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Staff Balance Comparison Table -->
<div class="lh-card">
    <h3 style="margin:0 0 12px 0;">Balance Comparison: Ledger vs JSON</h3>
    <table class="lh-tbl">
        <thead>
            <tr>
                <th>ID</th>
                <th>Staff</th>
                <th style="text-align:right;">Ledger (USD)</th>
                <th style="text-align:right;">JSON (USD)</th>
                <th style="text-align:right;">Delta</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['match'] ? '' : 'lh-diff' ?>">
                <td><?= $r['id'] ?></td>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td style="text-align:right; font-family:monospace;">$<?= number_format($r['ledger'], 2) ?></td>
                <td style="text-align:right; font-family:monospace;">$<?= number_format($r['json'], 2) ?></td>
                <td style="text-align:right; font-family:monospace;">$<?= number_format($r['delta'], 2) ?></td>
                <td>
                    <?php if ($r['match']): ?>
                        <span class="lh-match">✓ Match</span>
                    <?php else: ?>
                        <span style="color:#dc2626; font-weight:700;">✗ MISMATCH</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
            <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:24px;">No staff data found. Run backfill first.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Mismatch History Log -->
<?php if (!empty($mismatches)): ?>
<div class="lh-card">
    <h3 style="margin:0 0 12px 0;">Recent Mismatch Log (<?= count($mismatches) ?>)</h3>
    <table class="lh-tbl">
        <thead>
            <tr><th>Time</th><th>Staff</th><th style="text-align:right;">Ledger</th><th style="text-align:right;">JSON</th><th style="text-align:right;">Delta</th></tr>
        </thead>
        <tbody>
        <?php foreach ($mismatches as $m): ?>
            <tr>
                <td style="font-size:12px;"><?= $m['detected_at'] ?? '' ?></td>
                <td><?= htmlspecialchars($m['staff_name'] ?? '') ?></td>
                <td style="text-align:right;font-family:monospace;">$<?= number_format((float)($m['ledger'] ?? 0), 2) ?></td>
                <td style="text-align:right;font-family:monospace;">$<?= number_format((float)($m['json'] ?? 0), 2) ?></td>
                <td style="text-align:right;font-family:monospace;color:#dc2626;">$<?= number_format((float)($m['delta'] ?? 0), 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Ledger Statistics -->
<div class="lh-card">
    <h3 style="margin:0 0 12px 0;">Ledger Source Breakdown</h3>
    <table class="lh-tbl">
        <thead><tr><th>Source : Status</th><th style="text-align:right;">Rows</th><th style="text-align:right;">Total Amount</th></tr></thead>
        <tbody>
        <?php foreach ($bySource as $key => $val): ?>
            <tr>
                <td style="font-family:monospace;"><?= htmlspecialchars($key) ?></td>
                <td style="text-align:right;"><?= number_format($val['count']) ?></td>
                <td style="text-align:right;font-family:monospace;">$<?= number_format($val['total'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Rollback Plan -->
<div class="lh-card" style="background:#f0f9ff; border-color:#bae6fd;">
    <h3 style="margin:0 0 8px 0; color:#0369a1;">📋 Rollback Plan</h3>
    <div style="font-size:13px; color:#0c4a6e; line-height:1.6;">
        <b>If things go wrong:</b><br>
        <b>Option A:</b> Click "Disable Ledger Reads" above → all views instantly revert to JSON files.<br>
        <b>Option B:</b> Click "Rebuild from JSON" → wipes ledger, re-imports from JSON (source of truth).<br>
        <b>Option C:</b> SSH in and run: <code>DELETE FROM staff_ledger;</code> → next page load recreates table.<br><br>
        <b>Timeline:</b><br>
        Week 1: Dual-read mode (current) — monitor this page for mismatches.<br>
        Week 2: If zero mismatches → ready for single-read (ledger only).<br>
        Week 3: If still zero → remove old JSON read paths (cleanup).<br>
        JSON files are <b>never deleted</b> — they remain as permanent backup.
    </div>
</div>

<?php
// ═══════════════════════════════════════════════════════════════════════════
//  TRANSACTION INTEGRITY AUDIT — v4.11.3
// ═══════════════════════════════════════════════════════════════════════════
require_once dirname(__DIR__, 2) . '/lib/TransactionIntegrityGuard.php';
$_tigReport = TransactionIntegrityGuard::fullAudit($store, $pdo);
$_tigSum    = $_tigReport['summary'];
$_tigChecks = $_tigReport['checks'];
?>
<div class="lh-card" style="margin-top:24px;">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
    <h3 style="margin:0;font-size:16px;">🔍 Transaction Integrity Audit</h3>
    <span style="font-size:11px;color:#64748b;">Last run: <?= htmlspecialchars($_tigSum['last_run']) ?></span>
  </div>

  <!-- Summary bar -->
  <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
    <div style="background:#dcfce7;border-radius:8px;padding:10px 18px;text-align:center;min-width:80px;">
      <div style="font-size:22px;font-weight:900;color:#166534;"><?= $_tigSum['passed'] ?></div>
      <div style="font-size:10px;font-weight:700;color:#166534;letter-spacing:.5px;">PASSED</div>
    </div>
    <div style="background:<?= $_tigSum['warnings']>0?'#fef9c3':'#f1f5f9' ?>;border-radius:8px;padding:10px 18px;text-align:center;min-width:80px;">
      <div style="font-size:22px;font-weight:900;color:<?= $_tigSum['warnings']>0?'#854d0e':'#94a3b8' ?>;"><?= $_tigSum['warnings'] ?></div>
      <div style="font-size:10px;font-weight:700;color:<?= $_tigSum['warnings']>0?'#854d0e':'#94a3b8' ?>;letter-spacing:.5px;">WARNINGS</div>
    </div>
    <div style="background:<?= $_tigSum['errors']>0?'#fee2e2':'#f1f5f9' ?>;border-radius:8px;padding:10px 18px;text-align:center;min-width:80px;">
      <div style="font-size:22px;font-weight:900;color:<?= $_tigSum['errors']>0?'#991b1b':'#94a3b8' ?>;"><?= $_tigSum['errors'] ?></div>
      <div style="font-size:10px;font-weight:700;color:<?= $_tigSum['errors']>0?'#991b1b':'#94a3b8' ?>;letter-spacing:.5px;">ERRORS</div>
    </div>
  </div>

  <!-- Individual checks -->
  <?php foreach ($_tigChecks as $_c): ?>
  <?php
    $_sev   = $_c['severity'] ?? 'ok';
    $_bg    = $_sev==='error' ? '#fff1f2' : ($_sev==='warn' ? '#fffbeb' : '#f0fdf4');
    $_border= $_sev==='error' ? '#fecaca' : ($_sev==='warn' ? '#fde68a' : '#bbf7d0');
    $_icon  = $_sev==='error' ? '🔴' : ($_sev==='warn' ? '🟡' : '✅');
  ?>
  <div style="background:<?= $_bg ?>;border:1px solid <?= $_border ?>;border-radius:8px;padding:12px 16px;margin-bottom:10px;">
    <div style="display:flex;gap:8px;align-items:flex-start;">
      <span style="font-size:14px;margin-top:1px;"><?= $_icon ?></span>
      <div style="flex:1;">
        <div style="font-weight:700;font-size:13px;color:#1e293b;">
          [<?= htmlspecialchars($_c['id']) ?>] <?= htmlspecialchars($_c['title']) ?>
        </div>
        <div style="font-size:12px;color:#334155;margin-top:3px;"><?= htmlspecialchars($_c['description']) ?></div>
        <?php if (!empty($_c['impact'])): ?>
        <div style="font-size:11px;color:#64748b;margin-top:4px;">
          <b>💰 Impact:</b> <?= htmlspecialchars($_c['impact']) ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($_c['fix'])): ?>
        <div style="font-size:11px;color:#0369a1;margin-top:4px;">
          <b>🔧 Fix:</b> <?= htmlspecialchars($_c['fix']) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
