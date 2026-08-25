<?php
// ── Admin: Duplicate Registration Review ─────────────────────────────────────
if (!$isAdmin && ($retailer['role'] ?? '') !== 'accountant') {
    echo '<div class="kyc-alert danger">Access denied.</div>'; return;
}

$pdo = $store->getPdo();

// ── Ensure table exists (migration 054 may not have run yet) ─────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS duplicate_confirmations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        staff_id INTEGER NOT NULL DEFAULT 0,
        staff_name TEXT NOT NULL DEFAULT '',
        phone TEXT NOT NULL DEFAULT '',
        existing_name TEXT NOT NULL DEFAULT '',
        existing_crm_id INTEGER NOT NULL DEFAULT 0,
        note TEXT NOT NULL DEFAULT '',
        review_status TEXT NOT NULL DEFAULT 'pending',
        reviewed_by TEXT, reviewed_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
} catch (\Throwable $e) {}

// ── Handle mark as reviewed ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_review'])) {
    $entryId = (int)($_POST['entry_id'] ?? 0);
    $status  = in_array($_POST['review_status'] ?? '', ['ok','duplicate']) ? $_POST['review_status'] : 'ok';
    if ($entryId > 0) {
        try {
            $pdo->prepare(
                "UPDATE duplicate_confirmations
                 SET review_status=?, reviewed_by=?, reviewed_at=datetime('now')
                 WHERE id=?"
            )->execute([$status, $retailer['name'] ?? 'Admin', $entryId]);
        } catch (\Throwable $e) {}
        header('Location: ?page=dashboard&tab=duplicate_log');
        exit;
    }
}

// ── Load data from SQLite ────────────────────────────────────────────────────
try {
    $log = $pdo->query(
        "SELECT * FROM duplicate_confirmations ORDER BY id DESC LIMIT 200"
    )->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $log = [];
}

$pending  = array_filter($log, fn($e) => ($e['review_status'] ?? 'pending') === 'pending');
$reviewed = array_filter($log, fn($e) => ($e['review_status'] ?? 'pending') !== 'pending');
?>
<div style="max-width:900px;margin:0 auto;">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
    <div>
        <h2 style="margin:0;font-size:18px;font-weight:800;color:#1e293b;">🔍 Duplicate Registration Review</h2>
        <div style="font-size:12px;color:#64748b;margin-top:2px;">
            Cases where staff confirmed a different customer despite matching phone number
        </div>
    </div>
    <div style="display:flex;gap:8px;">
        <span style="background:#fef3c7;color:#92400e;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;">
            ⏳ <?= count($pending) ?> pending review
        </span>
        <span style="background:#f0fdf4;color:#166534;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;">
            ✅ <?= count($reviewed) ?> reviewed
        </span>
    </div>
</div>

<?php if (empty($log)): ?>
<div style="text-align:center;padding:60px;background:#fff;border-radius:16px;border:1px solid #e2e8f0;">
    <div style="font-size:48px;margin-bottom:12px;">✅</div>
    <div style="font-size:16px;font-weight:700;color:#1e293b;">No duplicate confirmations yet</div>
    <div style="font-size:13px;color:#64748b;margin-top:4px;">When staff confirm a shared/different customer, it will appear here for review.</div>
</div>
<?php else: ?>

<?php if (!empty($pending)): ?>
<div style="font-size:11px;font-weight:800;color:#92400e;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;">⏳ Needs Review</div>
<?php foreach ($pending as $e): ?>
<div style="background:#fff;border:1.5px solid #fbbf24;border-radius:12px;padding:14px 16px;margin-bottom:10px;display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap;">
    <div style="flex:1;min-width:200px;">
        <div style="font-size:13px;font-weight:700;color:#1e293b;margin-bottom:4px;">
            📱 <?= htmlspecialchars($e['phone'] ?? '') ?>
        </div>
        <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
            Staff: <strong><?= htmlspecialchars($e['staff_name'] ?? '') ?></strong>
            &nbsp;·&nbsp; <?= htmlspecialchars(substr($e['created_at'] ?? '', 0, 16)) ?>
        </div>
        <div style="font-size:12px;color:#dc2626;margin-top:4px;">
            Existing: <strong><?= htmlspecialchars($e['existing_name'] ?? '') ?></strong>
            <?= $e['existing_crm_id'] ? ' (CRM #' . (int)$e['existing_crm_id'] . ')' : '' ?>
        </div>
        <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Note: <?= htmlspecialchars($e['note'] ?? '') ?></div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
        <?php if ($e['existing_crm_id']): ?>
        <a href="https://crm.dishnetafrica.com/crm/client/<?= (int)$e['existing_crm_id'] ?>" target="_blank"
           style="background:#1d4ed8;color:#fff;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:700;text-decoration:none;">
            View CRM #<?= (int)$e['existing_crm_id'] ?>
        </a>
        <?php endif; ?>
        <form method="POST" style="margin:0;display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="mark_review" value="1">
            <input type="hidden" name="entry_id" value="<?= (int)($e['id'] ?? 0) ?>">
            <input type="hidden" name="review_status" value="ok">
            <button type="submit" style="background:#059669;color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:700;cursor:pointer;">✅ OK — Legitimate</button>
        </form>
        <form method="POST" style="margin:0;display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="mark_review" value="1">
            <input type="hidden" name="entry_id" value="<?= (int)($e['id'] ?? 0) ?>">
            <input type="hidden" name="review_status" value="duplicate">
            <button type="submit" style="background:#dc2626;color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:700;cursor:pointer;">❌ Is Duplicate</button>
        </form>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($reviewed)): ?>
<details style="margin-top:16px;">
<summary style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.8px;cursor:pointer;padding:8px 0;">
    ✅ Reviewed (<?= count($reviewed) ?>)
</summary>
<div style="margin-top:8px;">
<?php foreach ($reviewed as $e): ?>
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;margin-bottom:6px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;font-size:12px;color:#64748b;">
    <span>📱 <?= htmlspecialchars($e['phone'] ?? '') ?></span>
    <span>Staff: <?= htmlspecialchars($e['staff_name'] ?? '') ?></span>
    <span>Existing: <?= htmlspecialchars($e['existing_name'] ?? '') ?></span>
    <span style="margin-left:auto;background:<?= ($e['review_status'] ?? '') === 'ok' ? '#dcfce7' : '#fee2e2' ?>;
          color:<?= ($e['review_status'] ?? '') === 'ok' ? '#166534' : '#991b1b' ?>;
          border-radius:6px;padding:2px 10px;font-weight:700;">
        <?= ($e['review_status'] ?? '') === 'ok' ? '✅ Legitimate' : '❌ Duplicate' ?>
    </span>
    <span style="font-size:10px;"><?= htmlspecialchars($e['reviewed_by'] ?? '') ?> · <?= htmlspecialchars(substr($e['reviewed_at'] ?? '', 0, 16)) ?></span>
</div>
<?php endforeach; ?>
</div>
</details>
<?php endif; ?>

<?php endif; ?>
