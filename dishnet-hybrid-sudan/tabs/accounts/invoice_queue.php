<?php
// ── Invoice Queue Tab — DishNet Hybrid v4.11.3 ────────────────────────────────
// Actors: Accountant (Rupesh) and Admin
// Two sections:
//   Section A — Fiber Install Jobs  (fiber_collection_jobs SQLite)
//   Section B — UCRM Scheduling Jobs (job_invoice_queue.json)
// Requires: $store, $dataDir, $retailer, $isAdmin, $userRole (from public.php)

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

$isAcctMgr = $isAdmin || in_array($userRole ?? '', ['accountant', 'admin']);
if (!$isAcctMgr) {
    echo '<div style="padding:40px;text-align:center;color:#dc2626;">Access denied.</div>';
    return;
}

$pdo = $store->getPdo();
$actionMsg = '';
$actionOk  = null;

// ── POST: Fiber job — mark invoiced / skipped ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAct = $_POST['iq_action'] ?? '';

    if ($postAct === 'fiber_invoiced' || $postAct === 'fiber_skipped') {
        $fiberId  = (int)($_POST['fiber_job_id'] ?? 0);
        $invRef   = trim($_POST['iq_inv_ref'] ?? '');
        $note     = trim($_POST['iq_note'] ?? '');
        if ($fiberId > 0) {
            if ($postAct === 'fiber_invoiced') {
                $pdo->prepare(
                    "UPDATE fiber_collection_jobs SET status='invoiced', invoiced_at=datetime('now'),
                     invoiced_by=?, payment_collection_id=0, updated_at=datetime('now') WHERE id=?"
                )->execute([$retailer['name'] ?? 'Accountant', $fiberId]);
                // Store invoice ref in description column if available, else just log
                $actionOk  = true;
                $actionMsg = "Fiber job #FIB-{$fiberId} marked as Invoiced" . ($invRef ? " (Ref: {$invRef})" : '') . '.';
                // Invalidate invoice queue badge cache
                try { $pdo->prepare("DELETE FROM plugin_kv WHERE key IN ('nav_badges','ij_pending_count')")->execute(); } catch (\Throwable $e) {}
            } else {
                $pdo->prepare(
                    "UPDATE fiber_collection_jobs SET status='cancelled', cancel_reason=?,
                     updated_at=datetime('now') WHERE id=?"
                )->execute([$note ?: 'Skipped by accountant', $fiberId]);
                $actionOk  = true;
                $actionMsg = "Fiber job #FIB-{$fiberId} skipped.";
            }
        }
    }

    // ── POST: UCRM job — mark invoiced / skipped ──────────────────────────────
    if (in_array($postAct, ['invoiced', 'skipped'], true)) {
        $ijJobNo = trim($_POST['ij_job_no'] ?? '');
        $invRef  = trim($_POST['iq_inv_ref'] ?? '');
        $note    = trim($_POST['iq_note'] ?? '');
        if ($ijJobNo) {
            $queue = $store->load('job_invoice_queue.json') ?? [];
            $found = false;
            foreach ($queue as &$entry) {
                if (($entry['job_no'] ?? '') === $ijJobNo) {
                    $entry['status']       = $postAct;
                    $entry['invoice_ref']  = $invRef;
                    $entry['invoice_note'] = $note;
                    $entry['actioned_by']  = $retailer['name'] ?? 'Accountant';
                    $entry['actioned_at']  = date('Y-m-d H:i:s');
                    $found = true;
                    break;
                }
            }
            unset($entry);
            if ($found) {
                $store->save('job_invoice_queue.json', $queue);
                $actionOk  = true;
                $actionMsg = $postAct === 'invoiced'
                    ? "Job {$ijJobNo} marked as Invoiced" . ($invRef ? " (Ref: {$invRef})" : '') . '.'
                    : "Job {$ijJobNo} skipped.";
            }
        }
    }
}

// ── Load fiber jobs ───────────────────────────────────────────────────────────
$filterSt = $_GET['iq_status'] ?? 'pending';
$searchQ  = trim($_GET['iq_q'] ?? '');

$fiberJobs = [];
try {
    $pdo->query("SELECT id FROM fiber_collection_jobs LIMIT 0"); // table exists check
    $fiberAll = $pdo->query("SELECT * FROM fiber_collection_jobs ORDER BY created_at DESC")->fetchAll(\PDO::FETCH_ASSOC);

    // Count totals
    $fiberCounts = ['pending' => 0, 'invoiced' => 0, 'cancelled' => 0];
    foreach ($fiberAll as $fj) {
        $s = $fj['status'] ?? 'pending';
        if (isset($fiberCounts[$s])) $fiberCounts[$s]++;
    }

    // Filter
    $fiberJobs = array_values(array_filter($fiberAll, function($fj) use ($filterSt, $searchQ) {
        $st = $fj['status'] ?? 'pending';
        // Map 'skipped' filter to 'cancelled' for fiber
        $matchSt = ($filterSt === 'skipped') ? 'cancelled' : $filterSt;
        if ($matchSt && $st !== $matchSt) return false;
        if ($searchQ) {
            $hay = strtolower(($fj['customer_name'] ?? '') . ' ' . ($fj['phone'] ?? '') . ' ' . ($fj['area'] ?? '') . ' ' . ($fj['plan_name'] ?? ''));
            if (!str_contains($hay, strtolower($searchQ))) return false;
        }
        return true;
    }));
} catch (\Throwable $e) {
    $fiberCounts = ['pending' => 0, 'invoiced' => 0, 'cancelled' => 0];
}

// ── Load UCRM jobs ────────────────────────────────────────────────────────────
$rawQueue = $store->load('job_invoice_queue.json') ?? [];
$ucrmCounts = ['pending' => 0, 'invoiced' => 0, 'skipped' => 0];
foreach ($rawQueue as $x) {
    $s = $x['status'] ?? 'pending';
    if (isset($ucrmCounts[$s])) $ucrmCounts[$s]++;
}
$ucrmFiltered = array_values(array_filter($rawQueue, function($x) use ($filterSt, $searchQ) {
    if ($filterSt && ($x['status'] ?? 'pending') !== $filterSt) return false;
    if ($searchQ) {
        $hay = strtolower(($x['job_no'] ?? '') . ' ' . ($x['client_name'] ?? '') . ' ' . ($x['crm_job_title'] ?? ''));
        if (!str_contains($hay, strtolower($searchQ))) return false;
    }
    return true;
}));
usort($ucrmFiltered, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

// ── Combined counts for tabs ──────────────────────────────────────────────────
$totalPending  = ($fiberCounts['pending']  ?? 0) + ($ucrmCounts['pending']  ?? 0);
$totalInvoiced = ($fiberCounts['invoiced'] ?? 0) + ($ucrmCounts['invoiced'] ?? 0);
$totalSkipped  = ($fiberCounts['cancelled'] ?? 0) + ($ucrmCounts['skipped'] ?? 0);

// CRM base URL for links
$_crmBase = rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com/crm', '/api/v2.1');
$_crmBase = preg_replace('#/api/v[0-9.]+$#', '', $_crmBase);

?>
<style>
.iq-wrap{padding:20px;}
.iq-section-hdr{font-size:14px;font-weight:800;color:#1e293b;margin:20px 0 12px;display:flex;align-items:center;gap:8px;padding-bottom:8px;border-bottom:2px solid #e2e8f0;}
.iq-card{background:#fff;border-radius:10px;border:1px solid #e5e7eb;margin-bottom:10px;overflow:hidden;}
.iq-card.fiber{border-left:4px solid #0ea5e9;}
.iq-card.ucrm{border-left:4px solid #8b5cf6;}
.iq-head{display:flex;justify-content:space-between;align-items:flex-start;padding:14px 16px 8px;gap:10px;}
.iq-body{padding:0 16px 14px;}
.iq-tag{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;padding:2px 8px;border-radius:20px;}
.iq-tag.fiber{background:#e0f2fe;color:#0369a1;}
.iq-tag.ucrm{background:#ede9fe;color:#6d28d9;}
.iq-title{font-size:14px;font-weight:700;color:#111827;margin:3px 0;}
.iq-meta{font-size:12px;color:#6b7280;margin-top:2px;display:flex;flex-wrap:wrap;gap:8px;}
.iq-meta span{display:flex;align-items:center;gap:3px;}
.iq-actions{margin-top:10px;padding-top:10px;border-top:1px solid #f3f4f6;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.iq-inp{font-size:13px;border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;}
.iq-btn{padding:7px 14px;border-radius:7px;border:none;cursor:pointer;font-size:12px;font-weight:700;}
.iq-btn-green{background:#10b981;color:#fff;}
.iq-btn-green:hover{background:#059669;}
.iq-btn-gray{background:#f3f4f6;color:#374151;}
.iq-btn-gray:hover{background:#e5e7eb;}
.iq-pill{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:30px;font-size:13px;font-weight:600;text-decoration:none;}
.iq-done-banner{font-size:12px;border-radius:6px;padding:8px 12px;}
</style>

<div class="iq-wrap">

  <!-- Header -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div>
      <h2 style="margin:0;font-size:18px;font-weight:800;color:#111827;">🧾 Invoice Queue</h2>
      <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Fiber installs and field jobs that need invoicing in UCRM — mark each as done.</p>
    </div>
  </div>

  <?php if ($actionMsg): ?>
  <div style="padding:11px 14px;border-radius:8px;margin-bottom:14px;font-weight:600;font-size:13px;<?= $actionOk ? 'background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;' : 'background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;' ?>">
    <?= $actionOk ? '✅' : '⚠️' ?> <?= htmlspecialchars($actionMsg) ?>
  </div>
  <?php endif; ?>

  <!-- Status tabs -->
  <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
    <?php
    $pills = [
        'pending'  => ['⏳ Pending',   $totalPending,  '#fef3c7', '#92400e'],
        'invoiced' => ['✅ Invoiced',  $totalInvoiced, '#d1fae5', '#065f46'],
        'skipped'  => ['⏭️ Skipped',  $totalSkipped,  '#f3f4f6', '#6b7280'],
    ];
    foreach ($pills as $pst => [$plbl, $pcnt, $pbg, $pclr]):
        $active = $filterSt === $pst ? 'border:2px solid #6366f1;' : 'border:2px solid transparent;';
    ?>
    <a href="?page=dashboard&tab=invoice_queue&iq_status=<?= $pst ?>&iq_q=<?= urlencode($searchQ) ?>"
       class="iq-pill" style="background:<?= $pbg ?>;color:<?= $pclr ?>;<?= $active ?>">
      <?= $plbl ?> <strong><?= $pcnt ?></strong>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Search -->
  <form method="get" style="display:flex;gap:8px;margin-bottom:20px;">
    <input type="hidden" name="page" value="dashboard">
    <input type="hidden" name="tab" value="invoice_queue">
    <input type="hidden" name="iq_status" value="<?= htmlspecialchars($filterSt) ?>">
    <input type="text" name="iq_q" value="<?= htmlspecialchars($searchQ) ?>"
           placeholder="Search customer, plan, area…"
           style="flex:1;max-width:360px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">
    <button type="submit" style="padding:8px 14px;background:#6366f1;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;">Search</button>
    <?php if ($searchQ): ?><a href="?page=dashboard&tab=invoice_queue&iq_status=<?= $filterSt ?>" style="padding:8px 10px;color:#6b7280;text-decoration:none;font-size:13px;align-self:center;">✕</a><?php endif; ?>
  </form>

  <!-- ═══════════════════════════════════════════════════════════════
       SECTION A: FIBER INSTALL JOBS
       ═══════════════════════════════════════════════════════════════ -->
  <div class="iq-section-hdr">
    📡 Fiber Install Jobs
    <span style="background:#0ea5e9;color:#fff;font-size:10px;padding:2px 8px;border-radius:20px;font-weight:700;">
      <?= $fiberCounts['pending'] ?? 0 ?> pending
    </span>
    <span style="font-size:11px;color:#94a3b8;font-weight:500;margin-left:4px;">Install completed → create invoice in UCRM</span>
  </div>

  <?php if (empty($fiberJobs)): ?>
    <div style="text-align:center;padding:30px 20px;color:#9ca3af;background:#fff;border-radius:10px;border:1px solid #e5e7eb;margin-bottom:20px;">
      <?php if ($filterSt === 'pending'): ?>
        <div style="font-size:2rem;">🎉</div>
        <p style="margin:6px 0 0;font-size:13px;">No pending fiber install jobs — all invoiced!</p>
      <?php else: ?>
        <p style="margin:0;font-size:13px;">No <?= htmlspecialchars($filterSt) ?> fiber jobs.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php foreach ($fiberJobs as $fj):
        $fjSt      = $fj['status'] ?? 'pending';
        $fjId      = (int)$fj['id'];
        $fjName    = $fj['customer_name'] ?? '-';
        $fjPhone   = $fj['phone'] ?? '';
        $fjArea    = $fj['area'] ?? '-';
        $fjPlan    = $fj['plan_name'] ?? '-';
        $fjAmt     = (float)($fj['amount'] ?? 0);
        $fjCrmId   = (int)($fj['crm_client_id'] ?? 0);
        $fjCrmJob  = (int)($fj['crm_job_id'] ?? 0);
        $fjCreated = substr($fj['created_at'] ?? '', 0, 16);
        $fjInvAt   = substr($fj['invoiced_at'] ?? '', 0, 16);
        $fjInvBy   = $fj['invoiced_by'] ?? '';
        $fjCancel  = $fj['cancel_reason'] ?? '';
        // Status badge
        if ($fjSt === 'invoiced') {
            $fjBadge = '<span style="background:#d1fae5;color:#065f46;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">Invoiced</span>';
        } elseif ($fjSt === 'cancelled') {
            $fjBadge = '<span style="background:#f3f4f6;color:#6b7280;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">Skipped</span>';
        } else {
            $fjBadge = '<span style="background:#fef3c7;color:#92400e;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">Pending</span>';
        }
    ?>
    <div class="iq-card fiber">
      <div class="iq-head">
        <div style="flex:1;min-width:0;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
            <span class="iq-tag fiber">Fiber Install</span>
            <span style="font-size:11px;color:#94a3b8;font-family:monospace;">FIB-<?= $fjId ?></span>
            <?php if ($fjCrmId): ?>
              <a href="<?= $_crmBase ?>/crm/client/<?= $fjCrmId ?>" target="_blank"
                 style="font-size:11px;color:#0369a1;text-decoration:none;">CRM #<?= $fjCrmId ?> ↗</a>
            <?php endif; ?>
            <?php if ($fjCrmJob): ?>
              <a href="<?= $_crmBase ?>/crm/scheduling/job/<?= $fjCrmJob ?>" target="_blank"
                 style="font-size:11px;color:#6d28d9;text-decoration:none;">Job #<?= $fjCrmJob ?> ↗</a>
            <?php endif; ?>
          </div>
          <div class="iq-title">📡 <?= h($fjName) ?></div>
          <div class="iq-meta">
            <?php if ($fjPhone): ?><span>📞 <?= h($fjPhone) ?></span><?php endif; ?>
            <span>📍 <?= h($fjArea) ?></span>
            <span>📶 <?= h($fjPlan) ?></span>
            <span style="font-weight:700;color:#059669;">💵 $<?= number_format($fjAmt, 2) ?></span>
            <span>🕐 <?= $fjCreated ?></span>
          </div>
        </div>
        <div><?= $fjBadge ?></div>
      </div>

      <?php if ($fjSt === 'pending'): ?>
      <div class="iq-body">
        <div style="font-size:11px;color:#6b7280;margin-bottom:8px;background:#f0f9ff;border-radius:6px;padding:8px 10px;">
          💡 <strong>Steps:</strong>
          1. Open <a href="<?= $_crmBase ?>/crm/client/<?= $fjCrmId ?>" target="_blank" style="color:#0369a1;">CRM client</a>
          <?php if ($fjCrmJob): ?> · 2. Complete <a href="<?= $_crmBase ?>/crm/scheduling/job/<?= $fjCrmJob ?>" target="_blank" style="color:#6d28d9;">scheduling job #<?= $fjCrmJob ?></a><?php endif; ?>
          · 3. Create invoice · 4. Mark invoiced below
        </div>
        <form method="post" class="iq-actions">
          <?= csrfField() ?>
          <input type="hidden" name="iq_action" value="fiber_invoiced">
          <input type="hidden" name="fiber_job_id" value="<?= $fjId ?>">
          <input type="text" name="iq_inv_ref" class="iq-inp" placeholder="Invoice # (e.g. 2026-0042)" style="width:180px;">
          <button type="submit" class="iq-btn iq-btn-green">✅ Mark Invoiced</button>
          <button type="submit" name="iq_action" value="fiber_skipped" class="iq-btn iq-btn-gray"
                  onclick="return confirm('Skip this fiber job — no invoice needed?')">⏭️ Skip</button>
        </form>
      </div>

      <?php elseif ($fjSt === 'invoiced'): ?>
      <div class="iq-body">
        <div class="iq-done-banner" style="background:#ecfdf5;color:#065f46;">
          ✅ Invoiced by <strong><?= h($fjInvBy) ?></strong> at <?= $fjInvAt ?>
        </div>
      </div>

      <?php elseif ($fjSt === 'cancelled'): ?>
      <div class="iq-body">
        <div class="iq-done-banner" style="background:#f9fafb;color:#6b7280;">
          ⏭️ Skipped<?= $fjCancel ? ' — ' . h($fjCancel) : '' ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════════════
       SECTION B: UCRM SCHEDULING JOBS
       ═══════════════════════════════════════════════════════════════ -->
  <div class="iq-section-hdr" style="margin-top:28px;">
    🏁 Field & Scheduling Jobs
    <span style="background:#8b5cf6;color:#fff;font-size:10px;padding:2px 8px;border-radius:20px;font-weight:700;">
      <?= $ucrmCounts['pending'] ?? 0 ?> pending
    </span>
  </div>

  <?php if (empty($ucrmFiltered)): ?>
    <div style="text-align:center;padding:30px 20px;color:#9ca3af;background:#fff;border-radius:10px;border:1px solid #e5e7eb;">
      <?php if ($filterSt === 'pending'): ?>
        <div style="font-size:2rem;">🎉</div>
        <p style="margin:6px 0 0;font-size:13px;">No pending scheduling jobs.</p>
      <?php else: ?>
        <p style="margin:0;font-size:13px;">No <?= htmlspecialchars($filterSt) ?> scheduling jobs.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php foreach ($ucrmFiltered as $ij):
        $st       = $ij['status']           ?? 'pending';
        $jobNo    = $ij['job_no']            ?? '-';
        $crmJobId = (int)($ij['crm_job_id'] ?? 0);
        $title    = $ij['crm_job_title']     ?? "Job #{$crmJobId}";
        $client   = $ij['client_name']       ?? '-';
        $by       = $ij['completed_by_name'] ?? '-';
        $doneAt   = substr($ij['completed_at'] ?? '', 0, 16);
        $invRef   = $ij['invoice_ref']       ?? '';
        $invNote  = $ij['invoice_note']      ?? '';
        $actionBy = $ij['actioned_by']       ?? '';
        $actionAt = substr($ij['actioned_at'] ?? '', 0, 16);
        if ($st === 'invoiced') {
            $stBadge = '<span style="background:#d1fae5;color:#065f46;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">Invoiced</span>';
        } elseif ($st === 'skipped') {
            $stBadge = '<span style="background:#f3f4f6;color:#6b7280;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">Skipped</span>';
        } else {
            $stBadge = '<span style="background:#fef3c7;color:#92400e;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">Pending</span>';
        }
    ?>
    <div class="iq-card ucrm">
      <div class="iq-head">
        <div style="flex:1;min-width:0;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
            <span class="iq-tag ucrm">UCRM Job</span>
            <span style="font-size:11px;color:#94a3b8;font-family:monospace;"><?= h($jobNo) ?></span>
            <?php if ($crmJobId): ?>
              <a href="<?= $_crmBase ?>/crm/scheduling/job/<?= $crmJobId ?>" target="_blank"
                 style="font-size:11px;color:#6d28d9;text-decoration:none;">Job #<?= $crmJobId ?> ↗</a>
            <?php endif; ?>
          </div>
          <div class="iq-title">🏁 <?= h($title) ?></div>
          <div class="iq-meta">
            <span>👤 <?= h($client) ?></span>
            <span>🔧 <?= h($by) ?></span>
            <?php if ($doneAt): ?><span>🕐 <?= $doneAt ?></span><?php endif; ?>
          </div>
        </div>
        <div><?= $stBadge ?></div>
      </div>

      <?php if ($st === 'pending'): ?>
      <div class="iq-body">
        <form method="post" class="iq-actions">
          <?= csrfField() ?>
          <input type="hidden" name="ij_job_no" value="<?= h($jobNo) ?>">
          <input type="text" name="iq_inv_ref" class="iq-inp" placeholder="Invoice # (optional)" style="width:180px;">
          <input type="text" name="iq_note" class="iq-inp" placeholder="Note (optional)" style="flex:1;min-width:100px;">
          <button type="submit" name="iq_action" value="invoiced" class="iq-btn iq-btn-green">✅ Mark Invoiced</button>
          <button type="submit" name="iq_action" value="skipped" class="iq-btn iq-btn-gray"
                  onclick="return confirm('Mark as skipped?')">⏭️ Skip</button>
        </form>
      </div>

      <?php elseif ($st === 'invoiced'): ?>
      <div class="iq-body">
        <div class="iq-done-banner" style="background:#ecfdf5;color:#065f46;">
          ✅ Invoiced by <strong><?= h($actionBy) ?></strong> at <?= $actionAt ?>
          <?php if ($invRef): ?> · Ref: <strong><?= h($invRef) ?></strong><?php endif; ?>
          <?php if ($invNote): ?><br><?= h($invNote) ?><?php endif; ?>
        </div>
      </div>

      <?php elseif ($st === 'skipped'): ?>
      <div class="iq-body">
        <div class="iq-done-banner" style="background:#f9fafb;color:#6b7280;">
          ⏭️ Skipped by <strong><?= h($actionBy) ?></strong> at <?= $actionAt ?>
          <?php if ($invNote): ?> · <?= h($invNote) ?><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>
