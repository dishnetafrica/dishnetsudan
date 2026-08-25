<?php
// ══════════════════════════════════════════════════════════════════════
// Daily Cash Declaration — End-of-day cash count by field staff
// Mobile-first. Agent declares physical cash, system compares to expected.
// ══════════════════════════════════════════════════════════════════════

require_once dirname(__DIR__, 2) . '/lib/DualReadCashPosition.php';

$staffId = (int)$retailer['id'];
$pdo = $store->getPdo();

// Auto-create table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS staff_cash_declarations (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        staff_id        INTEGER NOT NULL,
        staff_name      TEXT    NOT NULL,
        declaration_date TEXT   NOT NULL,
        expected_usd    REAL    DEFAULT 0,
        declared_usd    REAL    DEFAULT 0,
        diff_usd        REAL    DEFAULT 0,
        expected_ssp    REAL    DEFAULT 0,
        declared_ssp    REAL    DEFAULT 0,
        diff_ssp        REAL    DEFAULT 0,
        photo           TEXT    DEFAULT NULL,
        notes           TEXT    DEFAULT NULL,
        status          TEXT    DEFAULT 'submitted',
        created_at      TEXT    DEFAULT (datetime('now'))
    );
    CREATE UNIQUE INDEX IF NOT EXISTS idx_decl_staff_date ON staff_cash_declarations(staff_id, declaration_date);
");

$today = date('Y-m-d');
// v4.11.38: JSON source — DualReadCashPosition reads staff_ledger which can be stale
require_once dirname(__DIR__, 2) . '/lib/StaffCashPositionService.php';
$cpSvc = new StaffCashPositionService($store, $pdo);
$pos   = $cpSvc->getPosition($staffId);

// Check if already declared today
$chk = $pdo->prepare("SELECT * FROM staff_cash_declarations WHERE staff_id=? AND declaration_date=?");
$chk->execute([$staffId, $today]);
$todayDecl = $chk->fetch(\PDO::FETCH_ASSOC);

// POST: submit declaration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cd_action'] ?? '') === 'declare') {
    $declUsd = round((float)($_POST['declared_usd'] ?? 0), 2);
    $declSsp = round((float)($_POST['declared_ssp'] ?? 0), 0);
    $notes   = trim($_POST['notes'] ?? '');
    $expUsd  = round((float)$pos['cash_in_hand'], 2);
    // SSP balance from cashbook if available
    $expSsp  = 0;
    try {
        require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
        $cb = new CashbookService($store, dirname(__DIR__, 2) . '/data');
        $cbSum = $cb->getStaffSummary($staffId, 'dishnet');
        $expSsp = round((float)($cbSum['ssp_balance'] ?? 0), 0);
    } catch (Throwable $e) {}

    $diffUsd = round($declUsd - $expUsd, 2);
    $diffSsp = round($declSsp - $expSsp, 0);

    // Handle photo
    $photoPath = null;
    if (!empty($_FILES['cash_photo']['tmp_name']) && is_uploaded_file($_FILES['cash_photo']['tmp_name'])) {
        $dir = $dataDir . '/uploads/cash_photos';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $fn = $staffId . '_' . $today . '_' . time() . '.jpg';
        move_uploaded_file($_FILES['cash_photo']['tmp_name'], $dir . '/' . $fn);
        require_once dirname(dirname(__DIR__)) . '/lib/ImageCompressor.php';
        compressImage($dir . '/' . $fn);
        $photoPath = 'uploads/cash_photos/' . $fn;
    }

    $stmt = $pdo->prepare("INSERT INTO staff_cash_declarations 
        (staff_id, staff_name, declaration_date, expected_usd, declared_usd, diff_usd, expected_ssp, declared_ssp, diff_ssp, photo, notes, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(staff_id, declaration_date) DO UPDATE SET
            expected_usd=excluded.expected_usd, declared_usd=excluded.declared_usd, diff_usd=excluded.diff_usd,
            expected_ssp=excluded.expected_ssp, declared_ssp=excluded.declared_ssp, diff_ssp=excluded.diff_ssp,
            photo=COALESCE(excluded.photo, staff_cash_declarations.photo), notes=excluded.notes, status=excluded.status");
    $status = (abs($diffUsd) <= 0.5 && abs($diffSsp) <= 500) ? 'verified' : 'discrepancy';
    $stmt->execute([$staffId, $retailer['name'], $today, $expUsd, $declUsd, $diffUsd, $expSsp, $declSsp, $diffSsp, $photoPath, $notes, $status]);

    // Alert admin on discrepancy
    if ($status === 'discrepancy') {
        $store->appendWithId('activity_log.json', [
            'event' => 'cash_discrepancy', 'actor' => $retailer['name'],
            'action' => 'ALERT', 'detail' => "Cash discrepancy: USD {$diffUsd}, SSP {$diffSsp}",
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        try {
            $n2 = svc('notify');
            $adminPhone = $config['admin_phone'] ?? '';
            if ($adminPhone) {
                $n2->sendVia('support', $adminPhone, "⚠️ *Cash Discrepancy Alert*\n\nStaff: {$retailer['name']}\nDate: {$today}\n\nUSD: Expected \${$expUsd} → Declared \${$declUsd} (diff: \${$diffUsd})\nSSP: Expected {$expSsp} → Declared {$declSsp} (diff: {$diffSsp})\n\nCheck Staff Cash Control for details.", 'cash_discrepancy_alert');
            }
        } catch (Throwable $e) {}
    }

    flash($status === 'verified' ? '✅ Cash declaration verified — balances match.' : '⚠️ Cash discrepancy recorded. Admin has been notified.', $status === 'verified' ? 'success' : 'warning');
    redirect('?page=dashboard&tab=cash_declaration');
}

// Load recent declarations
$history = $pdo->prepare("SELECT * FROM staff_cash_declarations WHERE staff_id=? ORDER BY declaration_date DESC LIMIT 14");
$history->execute([$staffId]);
$recentDecls = $history->fetchAll(\PDO::FETCH_ASSOC);

// SSP expected
$expSsp = 0;
try {
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb2 = new CashbookService($store, dirname(__DIR__, 2) . '/data');
    $cbSum2 = $cb2->getStaffSummary($staffId, 'dishnet');
    $expSsp = round((float)($cbSum2['ssp_balance'] ?? 0), 0);
} catch (Throwable $e) {}
?>

<style>
.cd-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:16px;margin-bottom:14px;}
.cd-bal{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;}
.cd-bal-box{border-radius:12px;padding:14px;text-align:center;}
.cd-inp{width:100%;padding:12px;border:2px solid #e2e8f0;border-radius:12px;font-size:22px;font-weight:900;text-align:center;font-family:inherit;box-sizing:border-box;}
.cd-inp:focus{border-color:#7C3AED;outline:none;}
.cd-btn{width:100%;padding:14px;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;}
.cd-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f1f5f9;}
.cd-label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;}
</style>

<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
    <div style="font-size:24px;">📋</div>
    <div>
        <div style="font-size:17px;font-weight:800;color:#1e293b;">Daily Cash Declaration</div>
        <div style="font-size:11px;color:#6b7280;"><?= h($retailer['name']) ?> · <?= date('l, M j') ?></div>
    </div>
</div>

<?php if ($todayDecl): ?>
<!-- Already declared today -->
<div class="cd-card" style="background:<?= $todayDecl['status']==='verified' ? '#f0fdf4' : '#fef2f2' ?>;border-color:<?= $todayDecl['status']==='verified' ? '#bbf7d0' : '#fecaca' ?>;">
    <div style="font-size:15px;font-weight:800;color:<?= $todayDecl['status']==='verified' ? '#15803d' : '#991b1b' ?>;margin-bottom:8px;">
        <?= $todayDecl['status']==='verified' ? '✅ Today\'s Cash Verified' : '⚠️ Discrepancy Recorded' ?>
    </div>
    <div class="cd-bal">
        <div class="cd-bal-box" style="background:#fff;border:1px solid #e2e8f0;">
            <div class="cd-label">💵 USD</div>
            <div style="font-size:11px;color:#6b7280;">Expected: $<?= number_format((float)$todayDecl['expected_usd'], 2) ?></div>
            <div style="font-size:11px;color:#6b7280;">Declared: $<?= number_format((float)$todayDecl['declared_usd'], 2) ?></div>
            <?php if (abs((float)$todayDecl['diff_usd']) > 0.01): ?>
            <div style="font-size:13px;font-weight:800;color:<?= (float)$todayDecl['diff_usd'] < 0 ? '#991b1b' : '#15803d' ?>;"><?= (float)$todayDecl['diff_usd'] >= 0 ? '+' : '' ?>$<?= number_format((float)$todayDecl['diff_usd'], 2) ?></div>
            <?php else: ?>
            <div style="font-size:13px;font-weight:800;color:#15803d;">✓ Match</div>
            <?php endif; ?>
        </div>
        <div class="cd-bal-box" style="background:#fff;border:1px solid #e2e8f0;">
            <div class="cd-label">🇸🇸 SSP</div>
            <div style="font-size:11px;color:#6b7280;">Expected: <?= number_format((float)$todayDecl['expected_ssp']) ?></div>
            <div style="font-size:11px;color:#6b7280;">Declared: <?= number_format((float)$todayDecl['declared_ssp']) ?></div>
            <?php if (abs((float)$todayDecl['diff_ssp']) > 100): ?>
            <div style="font-size:13px;font-weight:800;color:<?= (float)$todayDecl['diff_ssp'] < 0 ? '#991b1b' : '#15803d' ?>;"><?= (float)$todayDecl['diff_ssp'] >= 0 ? '+' : '' ?><?= number_format((float)$todayDecl['diff_ssp']) ?> SSP</div>
            <?php else: ?>
            <div style="font-size:13px;font-weight:800;color:#15803d;">✓ Match</div>
            <?php endif; ?>
        </div>
    </div>
    <div style="font-size:11px;color:#6b7280;text-align:center;">Submitted at <?= substr($todayDecl['created_at'] ?? '', 11, 5) ?></div>
</div>

<?php else: ?>
<!-- Declaration form -->
<div class="cd-card">
    <div style="font-size:14px;font-weight:800;color:#1e293b;margin-bottom:4px;">End-of-Day Cash Count</div>
    <div style="font-size:11px;color:#6b7280;margin-bottom:16px;">Count your physical cash and enter the amounts below.</div>

    <!-- Expected balances -->
    <div class="cd-bal">
        <div class="cd-bal-box" style="background:#f0fdf4;">
            <div class="cd-label">💵 Expected USD</div>
            <div style="font-size:22px;font-weight:900;color:#15803d;">$<?= number_format((float)$pos['cash_in_hand'], 2) ?></div>
        </div>
        <div class="cd-bal-box" style="background:#eff6ff;">
            <div class="cd-label">🇸🇸 Expected SSP</div>
            <div style="font-size:22px;font-weight:900;color:#1d4ed8;"><?= number_format($expSsp) ?></div>
        </div>
    </div>

    <form method="POST" action="?page=dashboard&tab=cash_declaration" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="cd_action" value="declare">

        <div style="margin-bottom:14px;">
            <label style="font-size:11px;font-weight:700;color:#15803d;display:block;margin-bottom:4px;">💵 ACTUAL USD CASH COUNT</label>
            <input type="number" name="declared_usd" class="cd-inp" placeholder="0.00" step="0.01" min="0" required style="border-color:#bbf7d0;">
        </div>

        <div style="margin-bottom:14px;">
            <label style="font-size:11px;font-weight:700;color:#1d4ed8;display:block;margin-bottom:4px;">🇸🇸 ACTUAL SSP CASH COUNT</label>
            <input type="number" name="declared_ssp" class="cd-inp" placeholder="0" step="1" min="0" style="border-color:#bfdbfe;">
        </div>

        <div style="margin-bottom:14px;">
            <label style="font-size:11px;font-weight:700;color:#6b7280;display:block;margin-bottom:4px;">📸 PHOTO OF CASH (optional)</label>
            <label style="display:flex;align-items:center;gap:10px;padding:14px;border:2px dashed #d1d5db;border-radius:12px;cursor:pointer;">
                <span style="font-size:24px;">📷</span>
                <span style="font-size:12px;color:#6b7280;" id="cdPhotoName">Tap to photograph your cash</span>
                <input type="file" name="cash_photo" accept="image/*" capture="environment" style="display:none;" onchange="document.getElementById('cdPhotoName').textContent=this.files[0]?.name||'Tap to photograph'">
            </label>
        </div>

        <div style="margin-bottom:14px;">
            <label style="font-size:11px;font-weight:700;color:#6b7280;display:block;margin-bottom:4px;">NOTES (optional)</label>
            <input type="text" name="notes" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;box-sizing:border-box;" placeholder="e.g. Gave 50 SSP to motorbike driver, no receipt">
        </div>

        <button type="submit" class="cd-btn" style="background:#1e293b;color:#fff;">📋 Submit Cash Declaration</button>
    </form>
</div>
<?php endif; ?>

<!-- History -->
<?php if (!empty($recentDecls)): ?>
<div class="cd-card">
    <div style="font-size:14px;font-weight:800;color:#1e293b;margin-bottom:10px;">Recent Declarations</div>
    <?php foreach ($recentDecls as $d):
        $isOk = $d['status'] === 'verified';
    ?>
    <div class="cd-row">
        <div>
            <div style="font-size:12px;font-weight:700;color:#1e293b;"><?= h($d['declaration_date']) ?></div>
            <div style="font-size:10px;color:#6b7280;">
                USD: $<?= number_format((float)$d['declared_usd'], 2) ?>
                <?php if (abs((float)$d['diff_usd']) > 0.01): ?>
                <span style="color:<?= (float)$d['diff_usd'] < 0 ? '#991b1b' : '#15803d' ?>;">(<?= (float)$d['diff_usd'] >= 0 ? '+' : '' ?><?= number_format((float)$d['diff_usd'], 2) ?>)</span>
                <?php endif; ?>
                · SSP: <?= number_format((float)$d['declared_ssp']) ?>
                <?php if (abs((float)$d['diff_ssp']) > 100): ?>
                <span style="color:<?= (float)$d['diff_ssp'] < 0 ? '#991b1b' : '#15803d' ?>;">(<?= (float)$d['diff_ssp'] >= 0 ? '+' : '' ?><?= number_format((float)$d['diff_ssp']) ?>)</span>
                <?php endif; ?>
            </div>
        </div>
        <span style="font-size:16px;"><?= $isOk ? '✅' : '⚠️' ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
