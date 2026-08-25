<?php
// Tab: leads — Call Management System
// Smart Queue + Daily Assignment + Filter Tabs
// All leads visible to all sales staff. PHP 7.4 compatible.

        $allLeads = $store->load('leads.json') ?? [];
        $today = date('Y-m-d');
        $myId  = $retailerId;
        $myName = $retailer['name'] ?? 'Agent';

        // ── FIX 1: Active CALLERS only — exclude admin from round-robin ──
        // Only sales/field_agent roles join the daily rotation.
        // Admins can still see all leads but don't get leads assigned.
        $salesAgents = [];
        foreach ($store->load('retailers.json') ?? [] as $ra) {
            if (empty($ra['is_active'])) continue;
            if (in_array($ra['role'] ?? '', ['sales', 'field_agent', 'sales_staff'], true)) {
                $salesAgents[] = ['id' => (int)$ra['id'], 'name' => $ra['name'] ?? ''];
            }
        }

        // ── FIX 2: Build CRM phone index for "Already in CRM" detection ──
        // Check both kyc_applications (registered) and UCRM client cache
        $crmPhoneIndex = []; // normalised phone => ['crm_id', 'name', 'source']
        $kycApps = $store->load('kyc_applications.json') ?? [];
        foreach ($kycApps as $app) {
            $p = preg_replace('/[^0-9]/', '', $app['mobile'] ?? $app['phone'] ?? '');
            if (strlen($p) >= 7) {
                $p9 = substr($p, -9); // last 9 digits for fuzzy match
                $crmPhoneIndex[$p9] = [
                    'crm_id' => $app['crm_client_id'] ?? null,
                    'name'   => trim(($app['firstname']??'').' '.($app['lastname']??'')),
                    'source' => 'registered',
                    'status' => $app['status'] ?? 'new',
                ];
            }
        }
        // Also check enrichment cache for live UCRM clients
        $enrichCache = $store->load('crm_enrich_cache.json') ?? [];
        foreach ($enrichCache['clients_by_id'] ?? [] as $cid => $cl) {
            foreach ($cl['contacts'] ?? [] as $ct) {
                $p = preg_replace('/[^0-9]/', '', $ct['phone'] ?? '');
                if (strlen($p) >= 7) {
                    $p9 = substr($p, -9);
                    if (!isset($crmPhoneIndex[$p9])) {
                        $crmPhoneIndex[$p9] = [
                            'crm_id' => $cid,
                            'name'   => trim(($cl['firstName']??'').' '.($cl['lastName']??'')),
                            'source' => 'crm_client',
                            'status' => 'active',
                        ];
                    }
                }
            }
        }

        // ── Daily auto-assign (round-robin, callers only) ──
        $needsSave = false;
        $agentIdx = 0;
        $agentCount = count($salesAgents);
        if ($agentCount > 0) {
            $unassignedToday = [];
            foreach ($allLeads as $i => $l) {
                if (in_array($l['status'] ?? '', ['won','lost'])) continue;
                if (($l['daily_assign_date'] ?? '') === $today) continue;
                $unassignedToday[] = $i;
            }
            foreach ($unassignedToday as $idx) {
                $agent = $salesAgents[$agentIdx % $agentCount];
                $allLeads[$idx]['daily_assign_to']   = $agent['id'];
                $allLeads[$idx]['daily_assign_name'] = $agent['name'];
                $allLeads[$idx]['daily_assign_date'] = $today;
                $agentIdx++;
                $needsSave = true;
            }
            if ($needsSave) {
                $store->save('leads.json', $allLeads);
            }
        }

        // ── Build filtered views ──
        // Archived leads: hidden from agents, only admin sees them via the 'archived' filter
        $archivedLeads = array_values(array_filter($allLeads, fn($l) => !empty($l['archived'])));
        // Working set: strip archived from everything agents see
        $workingLeads  = array_filter($allLeads, fn($l) => empty($l['archived']));
        $activeLeads   = array_filter($workingLeads, function($l) { return !in_array($l['status'] ?? '', ['won','lost']); });

        $neverCalled = array_filter($activeLeads, function($l) { return empty($l['total_calls']) || (int)($l['total_calls'] ?? 0) === 0; });
        $calledToday = array_filter($activeLeads, function($l) use ($today) { return !empty($l['last_call_at']) && substr($l['last_call_at'], 0, 10) === $today; });
        $overdue     = array_filter($activeLeads, function($l) use ($today) { return !empty($l['follow_up_date']) && $l['follow_up_date'] < $today; });
        $myDaily     = array_filter($activeLeads, function($l) use ($myId, $today) { return (int)($l['daily_assign_to'] ?? 0) === $myId && ($l['daily_assign_date'] ?? '') === $today; });
        $myUncalled  = array_filter($myDaily, function($l) use ($today) { return empty($l['last_call_at']) || substr($l['last_call_at'], 0, 10) !== $today; });
        // Closer queue — leads marked Interested, needs specialist follow-up
        $closerQueue = array_filter($activeLeads, function($l) {
            return ($l['status'] ?? '') === 'interested';
        });

        // Sort helpers
        $prioOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
        $sortByPrio = function($arr) use ($prioOrder) {
            $a = array_values($arr);
            usort($a, function($x, $y) use ($prioOrder) {
                return ($prioOrder[$x['priority'] ?? 'medium'] ?? 1) - ($prioOrder[$y['priority'] ?? 'medium'] ?? 1);
            });
            return $a;
        };

        $neverCalled = $sortByPrio($neverCalled);
        $overdue     = $sortByPrio($overdue);
        $myDaily     = $sortByPrio($myDaily);
        $myUncalled  = $sortByPrio($myUncalled);
        $calledToday = array_values($calledToday);
        $closedLeads = array_filter($workingLeads, function($l) { return in_array($l['status'] ?? '', ['won','lost']); });

        // ── Next lead for smart queue ──
        $nextLead = !empty($myUncalled) ? $myUncalled[0] : (!empty($neverCalled) ? $neverCalled[0] : null);

        $editLead = null;
        $elId = (int)($_GET['edit_lead'] ?? 0);
        if ($elId) { foreach ($allLeads as $l) { if ((int)($l['id'] ?? 0) === $elId) { $editLead = $l; break; } } }

        $statusConfig = [
            'open'       => ['Open',      '#f39c12','#fff8e1','&#128308;'],
            'contacted'  => ['Contacted', '#2196F3','#E3F2FD','&#128222;'],
            'interested' => ['Interested','#9C27B0','#F3E5F5','&#128161;'],
            'quoted'     => ['Quoted',    '#FF9800','#FFF3E0','&#128176;'],
            'qualified'  => ['Qualified', '#00897B','#E0F2F1','&#9989;'],
            'won'        => ['Won',       '#28a745','#d4edda','&#127942;'],
            'lost'       => ['Lost',      '#dc3545','#fef2f2','&#10060;'],
        ];

        // ── FIX 4: Build per-caller progress for admin panel ──
        $callerProgress = [];
        foreach ($salesAgents as $ag) {
            $aid = $ag['id'];
            $assigned = array_filter($activeLeads, fn($l) => (int)($l['daily_assign_to']??0)===$aid && ($l['daily_assign_date']??'')===$today);
            $calledTodayByAgent = array_filter($assigned, fn($l) => !empty($l['last_call_at']) && substr($l['last_call_at'],0,10)===$today);
            $callerProgress[] = [
                'id'       => $aid,
                'name'     => $ag['name'],
                'assigned' => count($assigned),
                'called'   => count($calledTodayByAgent),
                'pending'  => count($assigned) - count($calledTodayByAgent),
            ];
        }

        // Which filter is active?
        $flt = $_GET['f'] ?? 'my_today';
        if ($flt === 'archived' && !$isAdmin) $flt = 'my_today';
        $filterMap = [
            'my_today'  => $myDaily,
            'closer'    => array_values($closerQueue),
            'never'     => $neverCalled,
            'overdue'   => $overdue,
            'today'     => $calledToday,
            'all'       => array_values($activeLeads),
            'archived'  => $archivedLeads,
        ];
        $displayLeads = $sortByPrio($filterMap[$flt] ?? $myDaily);
?>

<style>
.ld-tabs{display:flex;gap:0;overflow-x:auto;border-bottom:2px solid #e2e8f0;margin-bottom:12px;-webkit-overflow-scrolling:touch}
.ld-tab{padding:10px 14px;font-size:12px;font-weight:700;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;text-decoration:none;display:flex;align-items:center;gap:5px}
.ld-tab:hover{color:#1e293b}
.ld-tab.active{color:#D41C1C;border-bottom-color:#D41C1C}
.ld-tab .cnt{background:#f1f5f9;color:#64748b;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:800}
.ld-tab.active .cnt{background:#fef2f2;color:#D41C1C}

/* ── Smart Queue Hero ── */
.ld-queue{background:linear-gradient(135deg,#16a34a,#15803d);border-radius:16px;padding:18px 20px;margin-bottom:16px;color:#fff;display:flex;align-items:center;gap:14px;cursor:pointer;box-shadow:0 4px 16px rgba(22,163,74,.3);transition:transform .1s}
.ld-queue:active{transform:scale(.98)}
.ld-queue-icon{width:52px;height:52px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0}
.ld-queue-info{flex:1}
.ld-queue-title{font-size:16px;font-weight:800}
.ld-queue-sub{font-size:12px;color:#bbf7d0;margin-top:2px}
.ld-queue-btn{background:#fff;color:#16a34a;font-weight:900;padding:10px 20px;border-radius:12px;font-size:14px;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.15)}

/* ── Stats bar ── */
.ld-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px}
.ld-stat{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 8px;text-align:center}
.ld-stat-v{font-size:18px;font-weight:900}
.ld-stat-l{font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px}

/* ── Lead card ── */
.lc{background:#fff;border-radius:14px;margin-bottom:10px;box-shadow:0 1px 4px rgba(0,0,0,.05);overflow:hidden;border:1px solid #f0f0f0}
.lc.called-today{opacity:.55;border-left:3px solid #16a34a}
.lc-top{padding:14px 16px 10px}
.lc-name{font-size:17px;font-weight:800;color:#1e293b}
.lc-phone{display:flex;align-items:center;gap:6px;font-size:13px;color:#374151;margin-top:4px}
.lc-phone a{color:inherit;text-decoration:none}
.lc-phone .wa{color:#25D366;font-size:16px;margin-left:4px}
.lc-meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.lc-badge{font-size:10px;font-weight:700;padding:3px 10px;border-radius:12px;white-space:nowrap}
.lc-call-status{margin-top:8px;padding:8px 12px;background:#f8fafc;border-radius:8px;font-size:11px;color:#64748b;display:flex;align-items:center;gap:8px}
.lc-call-status .caller{font-weight:700;color:#1e293b}
.lc-call-status .time{color:#9ca3af}
.lc-call-status .never{color:#dc2626;font-weight:700}
.lc-note{font-size:11px;color:#9ca3af;font-style:italic;margin-top:6px;padding-left:2px}
.lc-follow{font-size:10px;color:#6b7280;margin-top:4px}
.lc-actions{display:flex;border-top:1px solid #f0f0f0}
.lc-actions>*{flex:1;display:flex;align-items:center;justify-content:center;gap:4px;padding:11px 4px;font-size:11px;font-weight:700;text-decoration:none;border-right:1px solid #f0f0f0;cursor:pointer;background:none;border-top:none;border-bottom:none;color:#374151}
.lc-actions>*:last-child{border-right:none}
.lc-actions>*:hover{background:#f8fafc}
.lc-actions .act-call{color:#16a34a}
.lc-actions .act-wa{color:#25D366}
.lc-actions .act-quote{color:#6366f1}

/* ── Daily badge ── */
.ld-daily-badge{font-size:10px;font-weight:800;padding:3px 10px;border-radius:12px;background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd}
.ld-mine-badge{background:#fef2f2;color:#D41C1C;border:1px solid #fca5a5}

/* ── Admin caller progress panel ── */
.caller-panel{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:14px 16px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.caller-panel-title{font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.caller-row{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.caller-row:last-child{margin-bottom:0;}
.caller-avatar{width:30px;height:30px;border-radius:8px;background:#D41C1C;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:900;flex-shrink:0;}
.caller-info{flex:1;min-width:0;}
.caller-name{font-size:12px;font-weight:700;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.caller-bar-wrap{background:#f1f5f9;border-radius:6px;height:7px;margin-top:4px;overflow:hidden;}
.caller-bar-fill{height:100%;border-radius:6px;background:linear-gradient(90deg,#16a34a,#22c55e);transition:width .3s;}
.caller-nums{font-size:11px;font-weight:700;white-space:nowrap;flex-shrink:0;}

/* ── CRM badge ── */
.badge-in-crm{font-size:10px;font-weight:800;padding:3px 10px;border-radius:12px;background:#fef3c7;color:#d97706;border:1px solid #fcd34d;display:inline-flex;align-items:center;gap:4px;}
.badge-in-crm.registered{background:#dcfce7;color:#16a34a;border-color:#86efac;}
</style>

<?php if (!$editLead): ?>

<!-- ══ Admin / Leader: Caller Progress Panel ══ -->
<?php if (in_array($role ?? '', ['admin','accountant','support_leader'], true) && !empty($callerProgress)): ?>
<div class="caller-panel">
    <div class="caller-panel-title">📊 Caller Progress Today</div>
    <?php foreach ($callerProgress as $cp):
        $pct = $cp['assigned'] > 0 ? round($cp['called'] / $cp['assigned'] * 100) : 0;
        $initial = strtoupper(substr($cp['name'], 0, 1));
    ?>
    <div class="caller-row">
        <div class="caller-avatar"><?= h($initial) ?></div>
        <div class="caller-info">
            <div class="caller-name"><?= h($cp['name']) ?></div>
            <div class="caller-bar-wrap">
                <div class="caller-bar-fill" style="width:<?= $pct ?>%;"></div>
            </div>
        </div>
        <div class="caller-nums">
            <span style="color:#16a34a;"><?= $cp['called'] ?></span>
            <span style="color:#cbd5e1;"> / </span>
            <span style="color:#1e293b;"><?= $cp['assigned'] ?></span>
            <?php if ($cp['pending'] > 0): ?>
            <span style="color:#f59e0b;font-size:10px;"> (<?= $cp['pending'] ?> left)</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ Smart Queue ══ -->
<?php if ($nextLead): ?>
<div class="ld-queue" onclick="openCallModal(<?= (int)$nextLead['id'] ?>,'<?= h(addslashes($nextLead['customer_name']??'')) ?>','<?= h($nextLead['phone']??'') ?>',<?= (int)($nextLead['total_calls']??0) ?>)">
    <div class="ld-queue-icon">📞</div>
    <div class="ld-queue-info">
        <div class="ld-queue-title"><?= h($nextLead['customer_name'] ?? '') ?></div>
        <div class="ld-queue-sub"><?= h($nextLead['phone'] ?? '') ?> · <?= ucfirst($nextLead['service_type'] ?? '') ?> · <?= ucfirst($nextLead['priority'] ?? 'medium') ?> priority</div>
    </div>
    <div class="ld-queue-btn">Call Now</div>
</div>
<?php else: ?>
<div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:14px;padding:16px;text-align:center;margin-bottom:16px;color:#16a34a;font-weight:700;font-size:14px;">
    ✅ All your leads for today have been called!
</div>
<?php endif; ?>

<!-- ══ Stats ══ -->
<div class="ld-stats">
    <div class="ld-stat"><div class="ld-stat-v" style="color:#f39c12"><?= count($myDaily) ?></div><div class="ld-stat-l">My Today</div></div>
    <div class="ld-stat"><div class="ld-stat-v" style="color:#dc2626"><?= count($neverCalled) ?></div><div class="ld-stat-l">Never Called</div></div>
    <div class="ld-stat"><div class="ld-stat-v" style="color:#e65100"><?= count($overdue) ?></div><div class="ld-stat-l">Overdue</div></div>
    <div class="ld-stat"><div class="ld-stat-v" style="color:#16a34a"><?= count($calledToday) ?></div><div class="ld-stat-l">Called Today</div></div>
</div>

<!-- ══ Filter Tabs ══ -->
<div class="ld-tabs">
    <a href="?page=dashboard&tab=leads&f=my_today" class="ld-tab <?= $flt==='my_today'?'active':'' ?>">📋 My Today<span class="cnt"><?= count($myDaily) ?></span></a>
    <?php if (!empty($closerQueue)): ?>
    <a href="?page=dashboard&tab=leads&f=closer" class="ld-tab <?= $flt==='closer'?'active':'' ?>" style="<?= $flt!=='closer'?'color:#d97706;':'' ?>">🔥 Closer Queue<span class="cnt" style="background:#fef3c7;color:#d97706;"><?= count($closerQueue) ?></span></a>
    <?php endif; ?>
    <a href="?page=dashboard&tab=leads&f=never" class="ld-tab <?= $flt==='never'?'active':'' ?>">🔴 Never Called<span class="cnt"><?= count($neverCalled) ?></span></a>
    <a href="?page=dashboard&tab=leads&f=overdue" class="ld-tab <?= $flt==='overdue'?'active':'' ?>">⏰ Overdue<span class="cnt"><?= count($overdue) ?></span></a>
    <a href="?page=dashboard&tab=leads&f=today" class="ld-tab <?= $flt==='today'?'active':'' ?>">✅ Called Today<span class="cnt"><?= count($calledToday) ?></span></a>
    <a href="?page=dashboard&tab=leads&f=all" class="ld-tab <?= $flt==='all'?'active':'' ?>">All<span class="cnt"><?= count($activeLeads) ?></span></a>
    <?php if ($isAdmin && !empty($archivedLeads)): ?>
    <a href="?page=dashboard&tab=leads&f=archived" class="ld-tab <?= $flt==='archived'?'active':'' ?>" style="color:#94a3b8;">🗄️ Archived<span class="cnt" style="background:#f1f5f9;color:#94a3b8;"><?= count($archivedLeads) ?></span></a>
    <?php endif; ?>
</div>

<div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;">
    <span><?= count($displayLeads) ?> leads</span>
    <a href="?page=dashboard&tab=leads&edit_lead=0" style="background:#D41C1C;color:#fff;padding:8px 18px;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;">⊕ Add Lead</a>
</div>

<?php endif; ?>

<?php if ($editLead || isset($_GET['edit_lead'])): ?>
<!-- ══ Add / Edit Lead Form ══ -->
<div style="background:#fff;border-radius:14px;padding:18px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <div style="font-size:15px;font-weight:800;color:#1e293b;margin-bottom:14px;"><?= $editLead ? '✏️ Edit Lead' : '➕ Add New Lead' ?></div>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $editLead ? 'update_lead' : 'add_lead' ?>">
        <?php if ($editLead): ?><input type="hidden" name="lead_id" value="<?= (int)$editLead['id'] ?>"><?php endif; ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div><label style="font-size:11px;font-weight:700;color:#6b7280;">Name *</label><input type="text" name="lead_name" value="<?= h($editLead['customer_name']??'') ?>" required style="width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;box-sizing:border-box;"></div>
            <div><label style="font-size:11px;font-weight:700;color:#6b7280;">Phone *</label><input type="tel" name="lead_phone" value="<?= h($editLead['phone']??'') ?>" required style="width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;box-sizing:border-box;"></div>
            <div><label style="font-size:11px;font-weight:700;color:#6b7280;">Service Type</label><select name="lead_service" style="width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;box-sizing:border-box;"><option value="fiber" <?= ($editLead['service_type']??'')==='fiber'?'selected':'' ?>>Fiber</option><option value="starlink" <?= ($editLead['service_type']??'')==='starlink'?'selected':'' ?>>Starlink</option><option value="lte" <?= ($editLead['service_type']??'')==='lte'?'selected':'' ?>>LTE</option></select></div>
            <div><label style="font-size:11px;font-weight:700;color:#6b7280;">Priority</label><select name="lead_priority" style="width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;box-sizing:border-box;"><option value="high" <?= ($editLead['priority']??'')==='high'?'selected':'' ?>>🔥 High</option><option value="medium" <?= ($editLead['priority']??'medium')==='medium'?'selected':'' ?>>Medium</option><option value="low" <?= ($editLead['priority']??'')==='low'?'selected':'' ?>>Low</option></select></div>
            <div><label style="font-size:11px;font-weight:700;color:#6b7280;">Address</label><input type="text" name="lead_address" value="<?= h($editLead['address']??'') ?>" style="width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;box-sizing:border-box;"></div>
            <div><label style="font-size:11px;font-weight:700;color:#6b7280;">Follow-up</label><input type="date" name="lead_followup" value="<?= h($editLead['follow_up_date']??'') ?>" style="width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;box-sizing:border-box;"></div>
        </div>
        <div style="margin-top:10px;"><label style="font-size:11px;font-weight:700;color:#6b7280;">Notes</label><input type="text" name="lead_notes" value="<?= h($editLead['notes']??'') ?>" placeholder="Any notes..." style="width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;box-sizing:border-box;"></div>
        <div style="display:flex;gap:8px;margin-top:14px;">
            <button type="submit" style="flex:1;background:#D41C1C;color:#fff;border:none;border-radius:10px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;"><?= $editLead ? 'Update' : 'Add Lead' ?></button>
            <a href="?page=dashboard&tab=leads&f=<?= h($flt) ?>" style="padding:12px 16px;color:#6b7280;font-size:13px;text-decoration:none;display:flex;align-items:center;">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$editLead): ?>
<!-- ══ Lead Cards ══ -->
<?php if (empty($displayLeads)): ?>
<div style="text-align:center;padding:40px 20px;color:#9ca3af;">
    <div style="font-size:40px;margin-bottom:12px;">✅</div>
    <div style="font-size:14px;font-weight:700;"><?= $flt === 'today' ? 'No calls logged today yet' : ($flt === 'my_today' ? 'All your leads called!' : 'No leads in this filter') ?></div>
</div>
<?php else: ?>
<?php foreach (array_slice($displayLeads, 0, 50) as $ld):
    $st = $statusConfig[$ld['status']??'open'] ?? $statusConfig['open'];
    $isOverdue = !empty($ld['follow_up_date']) && $ld['follow_up_date'] < $today;
    $isCalledToday = !empty($ld['last_call_at']) && substr($ld['last_call_at'], 0, 10) === $today;
    $ph = trim($ld['phone'] ?? '');
    $phClean = preg_replace('/[^0-9+]/', '', $ph);
    $isMyDaily = (int)($ld['daily_assign_to'] ?? 0) === $myId && ($ld['daily_assign_date'] ?? '') === $today;
    $isMyLead  = (int)($ld['retailer_id']??0) === $myId || (int)($ld['assigned_to']??0) === $myId;

    // FIX 2: CRM match — last 9 digits of phone
    $ph9      = strlen($phClean) >= 7 ? substr(preg_replace('/[^0-9]/', '', $phClean), -9) : '';
    $crmMatch = ($ph9 && isset($crmPhoneIndex[$ph9])) ? $crmPhoneIndex[$ph9] : null;

    // FIX 3: Always-visible assigned caller (today)
    $assignedCallerName = $ld['daily_assign_name'] ?? '';
    $assignedCallerId   = (int)($ld['daily_assign_to'] ?? 0);
    $isAssignedToday    = ($ld['daily_assign_date'] ?? '') === $today && $assignedCallerId > 0;
?>
<div class="lc<?= $isCalledToday ? ' called-today' : '' ?>">
    <div class="lc-top">
        <div class="lc-name"><?= h($ld['customer_name'] ?? '') ?></div>
        <?php if ($ph): ?>
        <div class="lc-phone">
            <a href="tel:<?= h($phClean) ?>"><i class="bi bi-telephone-fill"></i> <?= h($ph) ?></a>
            <a href="https://wa.me/<?= h(ltrim($phClean,'+')) ?>" target="_blank" class="wa"><i class="bi bi-whatsapp"></i></a>
        </div>
        <?php endif; ?>
        <div class="lc-meta">
            <?php if ($isMyDaily): ?>
                <!-- This caller's own daily lead -->
                <span class="ld-daily-badge">📋 My list today</span>
            <?php elseif ($isAssignedToday && $assignedCallerName): ?>
                <!-- FIX 3: Show to EVERYONE — prevents any overlap -->
                <span class="lc-badge" style="background:#fdf4ff;color:#7c3aed;border:1px solid #d8b4fe;">
                    👤 <?= h($assignedCallerName) ?>'s today
                </span>
            <?php elseif ($isMyLead): ?>
                <span class="ld-mine-badge">📌 Mine</span>
            <?php endif; ?>

            <!-- FIX 2: Already in CRM badge -->
            <?php if ($crmMatch): ?>
                <?php if ($crmMatch['source'] === 'registered'): ?>
                    <span class="badge-in-crm registered" title="Registered: <?= h($crmMatch['name']) ?>">
                        ✅ In CRM<?= $crmMatch['crm_id'] ? ' #'.(int)$crmMatch['crm_id'] : '' ?>
                    </span>
                <?php else: ?>
                    <span class="badge-in-crm" title="Existing UCRM client: <?= h($crmMatch['name']) ?>">
                        ⚠️ Existing client<?= $crmMatch['crm_id'] ? ' #'.(int)$crmMatch['crm_id'] : '' ?>
                    </span>
                <?php endif; ?>
            <?php endif; ?>

            <span class="lc-badge" style="background:<?= $st[2] ?>;color:<?= $st[1] ?>;"><?= $st[3] ?> <?= $st[0] ?></span>
            <span class="lc-badge" style="background:#f8fafc;color:#374151;"><i class="bi bi-broadcast"></i> <?= ucfirst($ld['service_type']??'') ?></span>
            <?php if ($ld['priority']==='high'): ?><span class="lc-badge" style="background:#fef2f2;color:#dc3545;">🔥 HIGH</span><?php endif; ?>
            <?php if ($isOverdue && !$isCalledToday): ?><span class="lc-badge" style="background:#dc3545;color:#fff;">OVERDUE</span><?php endif; ?>
            <?php if ($isCalledToday): ?><span class="lc-badge" style="background:#d1fae5;color:#065f46;">✅ Called today</span><?php endif; ?>
        </div>

        <!-- ══ CALL STATUS — the key info ══ -->
        <div class="lc-call-status">
            <?php
            // ── Arrival timer — shows how long since assigned, with urgency colours ──
            $refTs      = !empty($ld['assigned_at']) ? strtotime($ld['assigned_at'])
                        : (!empty($ld['created_at']) ? strtotime($ld['created_at']) : 0);
            $deadlineMin = (int)($config['lead_call_deadline_minutes'] ?? 60);
            $warnMin     = $deadlineMin - 15;
            $minutesAgo  = $refTs ? (int)round((time() - $refTs) / 60) : null;
            $isUncalled  = empty($ld['total_calls']) || (int)($ld['total_calls'] ?? 0) === 0;
            if ($isUncalled && $minutesAgo !== null && !$isCalledToday):
                if ($minutesAgo >= $deadlineMin):
                    $overLabel = $minutesAgo >= 120 ? floor($minutesAgo/60).'h '.($minutesAgo%60).'m' : $minutesAgo.'m';
            ?>
                    <span style="background:#fef2f2;color:#dc2626;font-weight:800;font-size:11px;padding:3px 10px;border-radius:8px;white-space:nowrap;">🔴 OVERDUE <?= $overLabel ?> ago</span>
            <?php   elseif ($minutesAgo >= $warnMin): ?>
                    <span style="background:#fff7ed;color:#d97706;font-weight:800;font-size:11px;padding:3px 10px;border-radius:8px;white-space:nowrap;">⚠️ <?= $deadlineMin - $minutesAgo ?>m left</span>
            <?php   else: ?>
                    <span style="background:#f0fdf4;color:#16a34a;font-size:11px;padding:3px 10px;border-radius:8px;white-space:nowrap;">⏱️ <?= $minutesAgo ?>m ago</span>
            <?php   endif; endif; ?>
            <?php if ($isUncalled): ?>
                <span class="never">🔴 Never called</span>
            <?php else: ?>
                <span>📞 <?= (int)$ld['total_calls'] ?> call<?= (int)$ld['total_calls'] > 1 ? 's' : '' ?></span>
                <span>· Last: <span class="caller"><?= h($ld['last_caller'] ?? '') ?></span></span>
                <?php
                    $lastAt = $ld['last_call_at'] ?? '';
                    $ago = '';
                    if ($lastAt) {
                        $diff = time() - strtotime($lastAt);
                        if ($diff < 3600) $ago = floor($diff/60).'m ago';
                        elseif ($diff < 86400) $ago = floor($diff/3600).'h ago';
                        else $ago = floor($diff/86400).'d ago';
                    }
                ?>
                <span class="time"><?= $ago ?></span>
            <?php endif; ?>
        </div>

        <?php if (!empty($ld['notes'])): ?><div class="lc-note">"<?= h(substr($ld['notes'],0,80)) ?>"</div><?php endif; ?>
        <?php if (!empty($ld['follow_up_date'])): ?><div class="lc-follow"><i class="bi bi-calendar-event"></i> Follow-up: <?= h($ld['follow_up_date']) ?></div><?php endif; ?>
        <?php if (!empty($ld['address'])): ?><div class="lc-follow"><i class="bi bi-geo-alt"></i> <?= h(substr($ld['address'],0,50)) ?></div><?php endif; ?>
    </div>
    <div class="lc-actions">
        <a class="act-call" onclick="openCallModal(<?= (int)$ld['id'] ?>,'<?= h(addslashes($ld['customer_name']??'')) ?>','<?= h($ph) ?>',<?= (int)($ld['total_calls']??0) ?>)"><i class="bi bi-telephone-fill"></i> Call</a>
        <a class="act-wa" href="https://wa.me/<?= h(ltrim($phClean,'+')) ?>" target="_blank"><i class="bi bi-whatsapp"></i> WA</a>
        <a class="act-quote" href="?page=dashboard&tab=send_quote&qmode=lead&prefill_lead=<?= (int)$ld['id'] ?>"><i class="bi bi-file-earmark-text"></i> Quote</a>
        <form method="POST" style="flex:1;display:flex;margin:0;">
        <?= csrfField() ?>
            <input type="hidden" name="action" value="update_lead_status">
            <input type="hidden" name="lead_id" value="<?= $ld['id'] ?>">
            <select name="new_status" onchange="this.form.submit()" style="width:100%;border:none;background:transparent;font-size:11px;font-weight:700;color:#64748b;cursor:pointer;text-align:center;padding:10px 2px;">
                <option value="">↔ Move</option>
                <option value="contacted">📞 Contacted</option>
                <option value="interested">💡 Interested</option>
                <option value="quoted">💰 Quoted</option>
                <option value="won">🏆 Won</option>
                <option value="lost">❌ Lost</option>
            </select>
        </form>
        <a href="?page=dashboard&tab=leads&edit_lead=<?= $ld['id'] ?>&f=<?= h($flt) ?>"><i class="bi bi-pencil"></i></a>
    </div>
</div>
<?php endforeach; ?>
<?php if (count($displayLeads) > 50): ?>
<div style="text-align:center;padding:12px;color:#9ca3af;font-size:12px;">Showing first 50 of <?= count($displayLeads) ?>. Use filters to narrow down.</div>
<?php endif; ?>
<?php endif; ?>

<!-- ══ Closed ══ -->
<?php if ($flt === 'all' && !empty($closedLeads)): ?>
<div style="margin-top:20px;">
    <div style="font-size:13px;font-weight:800;color:#6b7280;margin-bottom:10px;">Closed (<?= count($closedLeads) ?>)</div>
    <?php foreach (array_slice(array_values($closedLeads), 0, 10) as $cl2):
        $cst = $statusConfig[$cl2['status']??'lost'] ?? $statusConfig['lost'];
    ?>
    <div style="background:#fff;border-radius:10px;padding:12px 14px;margin-bottom:6px;box-shadow:0 1px 3px rgba(0,0,0,.04);display:flex;align-items:center;gap:10px;opacity:.6;">
        <span class="lc-badge" style="background:<?= $cst[2] ?>;color:<?= $cst[1] ?>;"><?= $cst[0] ?></span>
        <div style="flex:1;"><div style="font-size:13px;font-weight:600;"><?= h($cl2['customer_name']??'') ?></div>
        <div style="font-size:10px;color:#9ca3af;"><?= ucfirst($cl2['service_type']??'') ?> · <?= h(substr($cl2['won_at'] ?? $cl2['lost_at'] ?? $cl2['updated_at'] ?? '', 0, 10)) ?></div></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ══ Call Modal ══ -->
<div id="callModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:flex-end;justify-content:center;" onclick="if(event.target===this)closeCallModal()">
  <div style="background:#fff;border-radius:20px 20px 0 0;width:100%;max-width:480px;overflow:hidden;max-height:92vh;overflow-y:auto;">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#16a34a,#15803d);padding:14px 18px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:1;">
      <div style="width:42px;height:42px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">📞</div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:15px;font-weight:800;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" id="callModalName"></div>
        <div style="font-size:12px;color:#bbf7d0;" id="callModalPhone"></div>
        <div style="font-size:10px;color:#86efac;margin-top:1px;" id="callModalAttempt"></div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0;">
        <a id="callModalDialBtn" href="#" data-phone="" onclick="dialNumber(this.dataset.phone);return false;" style="background:#fff;color:#16a34a;font-weight:900;font-size:13px;padding:9px 14px;border-radius:10px;text-decoration:none;">📲 Call</a>
        <a id="callModalWaBtn" href="#" data-phone="" onclick="window.open('https://wa.me/'+this.dataset.phone,'_blank');return false;" style="background:#25D366;color:#fff;font-weight:900;font-size:13px;padding:9px 14px;border-radius:10px;text-decoration:none;"><i class="bi bi-whatsapp"></i></a>
      </div>
    </div>

    <div style="padding:14px 16px;">

      <!-- ── Script box ── -->
      <div id="callModalScript" style="background:#1e293b;color:#f1f5f9;border-radius:12px;padding:14px 16px;margin-bottom:14px;font-size:13px;line-height:1.6;display:none;">
        <div style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">📋 SAY EXACTLY THIS</div>
        <div id="callModalScriptText" style="font-style:italic;"></div>
        <div style="font-size:10px;color:#64748b;margin-top:8px;">Read word for word. Don't add anything.</div>
      </div>

      <!-- ── 5 outcome buttons ── -->
      <div style="font-size:10px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">After the call, press ONE:</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;">
        <button onclick="selectOutcome('no_answer')"  data-outcome="no_answer"  class="ldout-btn" style="background:#f1f5f9;color:#374151;padding:13px 8px;border-radius:12px;border:2px solid transparent;font-size:12px;font-weight:700;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;"><span style="font-size:20px;">📵</span>No Answer</button>
        <button onclick="selectOutcome('interested')" data-outcome="interested" class="ldout-btn" style="background:#fef3c7;color:#d97706;padding:13px 8px;border-radius:12px;border:2px solid transparent;font-size:12px;font-weight:700;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;"><span style="font-size:20px;">🔥</span>Interested</button>
        <button onclick="selectOutcome('callback')"   data-outcome="callback"   class="ldout-btn" style="background:#eff6ff;color:#1d4ed8;padding:13px 8px;border-radius:12px;border:2px solid transparent;font-size:12px;font-weight:700;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;"><span style="font-size:20px;">📅</span>Call Later</button>
        <button onclick="selectOutcome('not_interested')" data-outcome="not_interested" class="ldout-btn" style="background:#fef2f2;color:#dc2626;padding:13px 8px;border-radius:12px;border:2px solid transparent;font-size:12px;font-weight:700;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;"><span style="font-size:20px;">❌</span>Not Interested</button>
      </div>

      <!-- Callback date — only shows when "Call Later" pressed -->
      <div id="callbackDateWrap" style="display:none;margin-bottom:12px;">
        <label style="font-size:10px;font-weight:700;color:#6b7280;display:block;margin-bottom:4px;">When should we call back?</label>
        <input type="date" id="callModalFollowUp" style="width:100%;padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;box-sizing:border-box;">
      </div>

      <!-- Auto-WA notice -->
      <div id="autoWaNotice" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:11px;color:#166534;"></div>

      <!-- Notes (optional, collapsed by default) -->
      <details style="margin-bottom:12px;">
        <summary style="font-size:11px;color:#94a3b8;cursor:pointer;list-style:none;">+ Add note (optional)</summary>
        <textarea id="callModalNote" placeholder="Any notes..." style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;font-family:inherit;resize:vertical;min-height:44px;box-sizing:border-box;margin-top:6px;"></textarea>
      </details>

      <input type="hidden" id="callModalLeadId" value="">
      <input type="hidden" id="callModalOutcome" value="">

      <button id="callModalSaveBtn" onclick="saveCallOutcome()" disabled style="width:100%;background:#cbd5e1;color:#94a3b8;border:none;border-radius:12px;padding:13px;font-size:14px;font-weight:800;cursor:not-allowed;margin-bottom:6px;transition:all .15s;">Select an outcome above</button>
      <button onclick="closeCallModal()" style="width:100%;background:#f1f5f9;color:#64748b;border:none;border-radius:12px;padding:10px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
    </div>
  </div>
</div>

<script>
window._apiToken='<?= h($retailer['api_token']??'') ?>';

var _currentLeadAttempts = 0;
var _maxAttempts = <?= (int)($config['lead_max_attempts'] ?? 3) ?>;

// Scripts per attempt number
var SCRIPTS = [
  // attempt 1
  "\"Hi [Name], this is [Your name] from DishNet Fiber.\nYou messaged us about internet.\nCan I ask you 2 quick questions?\"\n\n→ What area are you in?\n→ Home or office internet?",
  // attempt 2
  "\"Hi [Name], DishNet Fiber here — I tried calling you yesterday.\nDo you have 2 minutes now?\"\n\n→ [Yes] Continue with questions\n→ [No] \"When is a good time? I'll call exactly then.\"",
  // attempt 3 (final)
  "\"Hi [Name], DishNet Fiber — last try!\nAre you still interested in fiber internet?\"\n\n→ [Yes] Continue\n→ [No] Press Not Interested"
];

var WA_NOTICES = {
  'no_answer':      '📤 Auto-WA will be sent: "We tried calling, we\'ll try again tomorrow."',
  'not_interested': '📤 Auto-WA will be sent: "Thanks, we\'ll leave it here. Message us anytime."',
  'interested':     '🔥 Lead moved to Closer queue. Auto-WA: "Great talking to you! Specialist will call within 2 hours."',
  'callback':       '📅 Lead scheduled for callback. Auto-WA: "We\'ll call you at the time you requested."'
};

function dialNumber(ph){if(ph)window.location.href='tel:'+ph.replace(/\s/g,'')}

function openCallModal(id, name, phone, attempts){
  _currentLeadAttempts = parseInt(attempts) || 0;
  document.getElementById('callModalLeadId').value = id;
  document.getElementById('callModalName').textContent = name;
  document.getElementById('callModalPhone').textContent = phone;
  var attemptNum = _currentLeadAttempts + 1;
  var attemptLabel = 'Attempt ' + attemptNum + ' of ' + _maxAttempts;
  if(attemptNum > _maxAttempts) attemptLabel = '⚠️ Max attempts reached';
  document.getElementById('callModalAttempt').textContent = attemptLabel;

  var c = phone.replace(/[^0-9+]/g,'');
  document.getElementById('callModalDialBtn').dataset.phone = c;
  document.getElementById('callModalWaBtn').dataset.phone  = c.replace(/^\+/,'');

  // Show script for this attempt
  var scriptBox  = document.getElementById('callModalScript');
  var scriptText = document.getElementById('callModalScriptText');
  var idx = Math.min(_currentLeadAttempts, SCRIPTS.length - 1);
  scriptText.textContent = SCRIPTS[idx].replace(/\[Name\]/g, name.split(' ')[0] || name);
  scriptBox.style.display = 'block';

  // Reset state
  document.getElementById('callModalNote').value = '';
  document.getElementById('callModalFollowUp').value = '';
  document.getElementById('callModalOutcome').value = '';
  document.getElementById('callbackDateWrap').style.display = 'none';
  document.getElementById('autoWaNotice').style.display = 'none';
  document.querySelectorAll('.ldout-btn').forEach(function(b){ b.style.border='2px solid transparent'; b.style.opacity='1'; });
  var saveBtn = document.getElementById('callModalSaveBtn');
  saveBtn.disabled = true;
  saveBtn.style.background = '#cbd5e1';
  saveBtn.style.color = '#94a3b8';
  saveBtn.style.cursor = 'not-allowed';
  saveBtn.textContent = 'Select an outcome above';

  document.getElementById('callModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function selectOutcome(k){
  document.getElementById('callModalOutcome').value = k;
  document.querySelectorAll('.ldout-btn').forEach(function(b){
    var active = b.dataset.outcome === k;
    b.style.border  = active ? '2px solid currentColor' : '2px solid transparent';
    b.style.opacity = active ? '1' : '0.5';
    b.style.fontWeight = active ? '900' : '700';
  });

  // Show callback date picker only for "Call Later"
  document.getElementById('callbackDateWrap').style.display = k === 'callback' ? 'block' : 'none';
  if(k === 'callback'){
    var t = new Date(); t.setDate(t.getDate()+1);
    document.getElementById('callModalFollowUp').value = t.toISOString().slice(0,10);
  }

  // Show auto-WA notice
  var notice = document.getElementById('autoWaNotice');
  if(WA_NOTICES[k]){
    notice.textContent = WA_NOTICES[k];
    notice.style.display = 'block';
  } else {
    notice.style.display = 'none';
  }

  // Activate save button
  var saveBtn = document.getElementById('callModalSaveBtn');
  saveBtn.disabled = false;
  saveBtn.style.background = 'linear-gradient(135deg,#16a34a,#15803d)';
  saveBtn.style.color = '#fff';
  saveBtn.style.cursor = 'pointer';
  saveBtn.textContent = '💾 Save & Next Lead';
}

function closeCallModal(){document.getElementById('callModal').style.display='none';document.body.style.overflow='';}

function saveCallOutcome(){
  var outcome = document.getElementById('callModalOutcome').value;
  if(!outcome){ alert('Please select an outcome first'); return; }
  var b = document.getElementById('callModalSaveBtn');
  b.disabled = true; b.textContent = 'Saving…';
  fetch('?page=api&action=log_call',{
    credentials:'same-origin',
    method:'POST',
    headers:{'Content-Type':'application/json','Authorization':'Bearer '+window._apiToken},
    body:JSON.stringify({
      lead_id:       document.getElementById('callModalLeadId').value,
      outcome:       outcome,
      note:          document.getElementById('callModalNote').value.trim(),
      new_status:    '',
      follow_up_date:document.getElementById('callModalFollowUp').value,
      duration_seconds: 0
    })
  }).then(function(r){return r.json();}).then(function(d){
    if(d.status==='success'||d.success||(d.data&&d.data.success)){
      closeCallModal();
      location.reload();
    } else {
      alert('❌ '+(d.message||(d.data&&d.data.message)||'Failed'));
      b.disabled=false; b.textContent='💾 Save & Next Lead';
    }
  }).catch(function(){b.disabled=false; b.textContent='💾 Save & Next Lead';});
}
</script>
