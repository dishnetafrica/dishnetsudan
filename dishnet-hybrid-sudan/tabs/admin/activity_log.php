<?php
// Tab: activity_log
// Extracted from public.php on 2026-03-15
    // Merge old-format entries (event/actor/detail/ref_id) with new logActivity format (action/title/detail/time)
    $rawLog  = $store->load('activity_log.json');
    $actLog  = [];
    foreach (array_reverse($rawLog) as $e) {
        $_act = $e['action'] ?? $e['event'] ?? '';
        switch ($_act) {
            case 'kyc_submitted': case 'kyc_submit':    $_icon = '📋'; $_clr = '#2563EB'; break;
            case 'wallet_topup': case 'wallet_recharge': $_icon = '💰'; $_clr = '#16a34a'; break;
            case 'settings_saved':                       $_icon = '⚙️'; $_clr = '#7c3aed'; break;
            case 'backup_created':                       $_icon = '💾'; $_clr = '#d97706'; break;
            case 'login': case 'auth':                   $_icon = '🔑'; $_clr = '#0891b2'; break;
            case 'sync': case 'crm_sync':                $_icon = '🔄'; $_clr = '#64748b'; break;
            default:                                     $_icon = '📝'; $_clr = '#64748b';
        }
        $actLog[] = [
            'icon'   => $_icon,
            'color'  => $_clr,
            'title'  => $e['title']  ?? str_replace('_',' ',ucfirst($e['event']??'Event')),
            'detail' => $e['detail'] ?? ($e['actor'] ? 'by '.$e['actor'] : ''),
            'time'   => $e['time']   ?? $e['created_at'] ?? '',
            'date'   => $e['date']   ?? substr($e['created_at']??'',0,10),
        ];
    }
    // Filter
    $logFilter = trim($_GET['log_filter'] ?? '');
    if ($logFilter) {
        $lf = strtolower($logFilter);
        $actLog = array_values(array_filter($actLog, fn($e) =>
            str_contains(strtolower($e['title'].$e['detail']), $lf)
        ));
    }
?>
<div style="max-width:900px;margin:0 auto;padding:0 4px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
        <div>
            <div style="font-size:20px;font-weight:800;color:#1E293B;">📜 Activity Log</div>
            <a href="?page=dashboard&action=export_csv&export_tab=activity_log"
               style="background:#16a34a;color:#fff;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:600;text-decoration:none;">⬇ Export CSV</a>
            <div style="font-size:12px;color:#64748B;margin-top:2px;"><?= count($actLog) ?> entries<?= $logFilter ? ' (filtered)' : '' ?></div>
        </div>
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="page" value="dashboard">
            <input type="hidden" name="tab"  value="activity_log">
            <input type="text" name="log_filter" value="<?= h($logFilter) ?>"
                   placeholder="Filter logs…"
                   style="border:1.5px solid #E2E8F0;border-radius:8px;padding:7px 12px;font-size:13px;outline:none;">
            <button type="submit" style="background:#D41C1C;color:#fff;border:none;border-radius:8px;padding:7px 14px;font-size:13px;font-weight:600;cursor:pointer;">Filter</button>
            <?php if($logFilter): ?>
            <a href="?page=dashboard&tab=activity_log" style="color:#64748B;font-size:12px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($actLog)): ?>
    <div style="padding:60px;text-align:center;color:#94A3B8;background:#fff;border-radius:14px;border:1px solid #E2E8F0;">
        <div style="font-size:40px;margin-bottom:12px;opacity:.3;">📜</div>
        <div style="font-size:14px;font-weight:600;">No activity recorded yet</div>
        <div style="font-size:12px;margin-top:4px;">Actions like KYC submissions, wallet top-ups, and setting changes will appear here</div>
    </div>
    <?php else: ?>
    <div style="background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;">
        <?php
        // Build reverse-indexed map for raw entries (to get before/after fields)
        $rawByIdx = array_reverse($rawLog);
        ?>
        <?php foreach ($actLog as $idx => $entry):
            $rawE      = $rawByIdx[$idx] ?? [];
            $hasBal    = isset($rawE['balance_before']) && isset($rawE['balance_after']);
            $balBefore = (float)($rawE['balance_before'] ?? 0);
            $balAfter  = (float)($rawE['balance_after']  ?? 0);
            $balDelta  = round($balAfter - $balBefore, 2);
        ?>
        <div style="display:flex;align-items:flex-start;gap:14px;padding:14px 18px;<?= $idx > 0 ? 'border-top:1px solid #F1F5F9;' : '' ?>">
            <div style="width:36px;height:36px;border-radius:50%;background:<?= h($entry['color']) ?>18;
                        display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">
                <?= $entry['icon'] ?>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:13px;font-weight:700;color:#1E293B;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <?= h($entry['title']) ?>
                    <?php if (!empty($rawE['actor'])): ?>
                    <span style="font-size:10px;font-weight:600;background:#EFF6FF;color:#1D4ED8;padding:1px 7px;border-radius:10px;"><?= h($rawE['actor']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($rawE['action'])): ?>
                    <span style="font-size:10px;font-weight:700;background:#F1F5F9;color:#475569;padding:1px 7px;border-radius:10px;font-family:monospace;"><?= h($rawE['action']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($rawE['was_large_txn'])): ?>
                    <span style="font-size:10px;background:#FFF7ED;color:#C2410C;border:1px solid #FED7AA;border-radius:6px;padding:1px 6px;font-weight:700;">⚠ LARGE TXN</span>
                    <?php endif; ?>
                </div>
                <?php if ($entry['detail']): ?>
                <div style="font-size:12px;color:#64748B;margin-top:3px;word-break:break-word;"><?= h($entry['detail']) ?></div>
                <?php endif; ?>
                <?php if ($hasBal): ?>
                <div style="display:flex;align-items:center;gap:6px;margin-top:6px;flex-wrap:wrap;">
                    <span style="font-size:11px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:2px 8px;font-family:monospace;color:#64748B;">
                        before <strong style="color:#1E293B;">$<?= number_format($balBefore,2) ?></strong>
                    </span>
                    <span style="color:#CBD5E1;">→</span>
                    <span style="font-size:11px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;padding:2px 8px;font-family:monospace;color:#64748B;">
                        after <strong style="color:#1E293B;">$<?= number_format($balAfter,2) ?></strong>
                    </span>
                    <span style="font-size:11px;font-weight:700;padding:2px 9px;border-radius:6px;
                          background:<?= $balDelta < 0 ? '#FEF2F2' : '#F0FDF4' ?>;
                          color:<?= $balDelta < 0 ? '#DC2626' : '#16A34A' ?>;">
                        <?= ($balDelta < 0 ? '−$' : '+$') . number_format(abs($balDelta), 2) ?>
                    </span>
                    <?php if (!empty($rawE['approved_by'])): ?>
                    <span style="font-size:10px;background:#F0FDF4;color:#166534;border:1px solid #BBF7D0;border-radius:6px;padding:1px 6px;">✅ admin: <?= h($rawE['approved_by']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($rawE['idem_key'])): ?>
                    <span style="font-size:10px;color:#94A3B8;font-family:monospace;" title="Idempotency: <?= h($rawE['idem_key']) ?>">🔑 guarded</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div style="font-size:11px;color:#94A3B8;white-space:nowrap;flex-shrink:0;text-align:right;">
                <?= h(substr($entry['time'],11,5)) ?><br>
                <span style="font-size:10px;"><?= h($entry['date']) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>



