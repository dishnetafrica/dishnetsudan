<?php
// Tab: all_leads
// Extracted from public.php on 2026-03-15
        // ── Raise limits for large datasets ──────────────────────────────
        @ini_set('memory_limit', '256M');
        @set_time_limit(60);

        $allLeads     = $store->load('leads.json') ?? [];
        $cfg          = $store->load('kyc_config.json') ?? [];
        $allRetailers = $store->load('retailers.json') ?? [];

        // ── Default assignees from config ─────────────────────────────────
        $defaultAssignees = $cfg['default_lead_assignees'] ?? ['Aida', 'Mecklyne'];

        // ── Resolve retailer objects for quick-assign buttons ─────────────
        $quickAssignRetailers = [];
        foreach ($allRetailers as $rr) {
            foreach ($defaultAssignees as $da) {
                if (stripos(trim($rr['name'] ?? ''), trim($da)) !== false && !empty($rr['is_active'] ?? true)) {
                    $quickAssignRetailers[] = $rr;
                    break;
                }
            }
        }

        // ── Stats — single pass, no repeated full-array scans ─────────────
        $statTotal = $statOpen = $statQualified = $statWon = $statUnassigned = 0;
        $assigneeCounts = [];
        foreach ($allLeads as $al) {
            $statTotal++;
            $st = $al['status'] ?? 'open';
            $isActive = !in_array($st, ['won','lost']);
            if ($isActive)      $statOpen++;
            if ($isActive && !empty($al['qualified'])) $statQualified++;
            if ($st === 'won')  $statWon++;
            if ($isActive && empty($al['assigned_to'])) $statUnassigned++;
            if ($isActive) {
                $an = $al['assigned_name'] ?? '—';
                $assigneeCounts[$an] = ($assigneeCounts[$an] ?? 0) + 1;
            }
        }
        arsort($assigneeCounts);
        $convRate = $statTotal > 0 ? round($statWon / $statTotal * 100) : 0;

        // ── Active filters ────────────────────────────────────────────────
        $fStatus   = trim($_GET['lf_status']   ?? '');
        $fAgent    = trim($_GET['lf_agent']    ?? '');
        $fAssignee = trim($_GET['lf_assignee'] ?? '');
        $fService  = trim($_GET['lf_service']  ?? '');
        $fQ        = strtolower(trim($_GET['lf_q'] ?? ''));

        $filtered = array_values(array_filter($allLeads, function($l) use ($fStatus,$fAgent,$fAssignee,$fService,$fQ) {
            if ($fStatus && ($l['status']??'') !== $fStatus) return false;
            if ($fAgent  && (int)($l['retailer_id']??0) !== (int)$fAgent) return false;
            if ($fService && strtolower($l['service_type']??'') !== strtolower($fService)) return false;
            if ($fAssignee) {
                if ($fAssignee === '__unassigned__') { if (!empty($l['assigned_name'])) return false; }
                else { if (stripos($l['assigned_name']??'', $fAssignee) === false) return false; }
            }
            if ($fQ) {
                $hay = strtolower(($l['customer_name']??'').($l['phone']??'').($l['email']??'').($l['address']??''));
                if (strpos($hay, $fQ) === false) return false;
            }
            return true;
        }));
        $filtered = array_reverse($filtered);

        // ── Pagination ────────────────────────────────────────────────────
        $perPage       = 50;
        $totalFiltered = count($filtered);
        $totalPages    = max(1, (int)ceil($totalFiltered / $perPage));
        $curPage       = max(1, min($totalPages, (int)($_GET['lf_pg'] ?? 1)));
        $pageOffset    = ($curPage - 1) * $perPage;
        $pageRows      = array_slice($filtered, $pageOffset, $perPage);
        ?>

<!-- ══ STAT PILLS ═════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;">
  <div style="background:#fff;border-radius:14px;padding:14px;border-left:4px solid #D41C1C;box-shadow:0 2px 8px rgba(0,0,0,.05);">
    <div style="font-size:24px;font-weight:900;color:#D41C1C;"><?= $statTotal ?></div>
    <div style="font-size:11px;color:#64748b;font-weight:700;">Total Leads</div>
  </div>
  <div style="background:#fff;border-radius:14px;padding:14px;border-left:4px solid #f59e0b;box-shadow:0 2px 8px rgba(0,0,0,.05);">
    <div style="font-size:24px;font-weight:900;color:#d97706;"><?= $statOpen ?></div>
    <div style="font-size:11px;color:#64748b;font-weight:700;">Active</div>
  </div>
  <div style="background:#fff;border-radius:14px;padding:14px;border-left:4px solid #ef4444;box-shadow:0 2px 8px rgba(0,0,0,.05);">
    <div style="font-size:24px;font-weight:900;color:#dc2626;"><?= $statUnassigned ?></div>
    <div style="font-size:11px;color:#64748b;font-weight:700;">Unassigned</div>
  </div>
  <div style="background:#fff;border-radius:14px;padding:14px;border-left:4px solid #22c55e;box-shadow:0 2px 8px rgba(0,0,0,.05);">
    <div style="font-size:24px;font-weight:900;color:#16a34a;"><?= $statQualified ?></div>
    <div style="font-size:11px;color:#64748b;font-weight:700;">Qualified</div>
  </div>
  <div style="background:#fff;border-radius:14px;padding:14px;border-left:4px solid #8b5cf6;box-shadow:0 2px 8px rgba(0,0,0,.05);">
    <div style="font-size:24px;font-weight:900;color:#7c3aed;"><?= $statWon ?></div>
    <div style="font-size:11px;color:#64748b;font-weight:700;">Converted</div>
  </div>
  <div style="background:#fff;border-radius:14px;padding:14px;border-left:4px solid #0891b2;box-shadow:0 2px 8px rgba(0,0,0,.05);">
    <div style="font-size:24px;font-weight:900;color:#0e7490;"><?= $convRate ?>%</div>
    <div style="font-size:11px;color:#64748b;font-weight:700;">Conversion</div>
  </div>
</div>

<!-- ══ ASSIGNEE BREAKDOWN ═════════════════════════════════════════════════ -->
<?php if (!empty($assigneeCounts)): ?>
<div style="background:#fff;border-radius:14px;padding:12px 16px;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,.05);">
  <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;">📋 Active Leads by Assignee</div>
  <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
    <?php foreach ($assigneeCounts as $aName => $aCnt): ?>
    <?php $isDefault = in_array($aName, $defaultAssignees); ?>
    <a href="?page=dashboard&tab=all_leads&lf_assignee=<?= urlencode($aName) ?>"
       style="display:inline-flex;align-items:center;gap:6px;background:<?= $isDefault?'#fff5f5':'#f8fafc' ?>;border:1.5px solid <?= $isDefault?'#D41C1C':'#e2e8f0' ?>;border-radius:20px;padding:5px 12px;text-decoration:none;">
      <?php if ($aName === '—'): ?>
        <span style="font-size:12px;color:#94a3b8;">⬜ Unassigned</span>
        <span style="font-size:13px;font-weight:800;color:#dc2626;"><?= $aCnt ?></span>
      <?php else: ?>
        <span style="width:24px;height:24px;background:<?= $isDefault?'#D41C1C':'#94a3b8' ?>;color:#fff;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;"><?= strtoupper(substr($aName,0,1)) ?></span>
        <span style="font-size:12px;font-weight:700;color:#1e293b;"><?= h($aName) ?></span>
        <span style="font-size:13px;font-weight:900;color:<?= $isDefault?'#D41C1C':'#374151' ?>;"><?= $aCnt ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <?php if (!empty($fAssignee)): ?>
    <a href="?page=dashboard&tab=all_leads" style="font-size:11px;color:#6b7280;padding:4px 10px;background:#f1f5f9;border-radius:10px;text-decoration:none;">✕ clear</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ══ MAIN CARD ══════════════════════════════════════════════════════════ -->
<div class="kyc-card">
  <div class="kyc-card-header" style="flex-wrap:wrap;gap:8px;">
    <span><i class="bi bi-people"></i> All Leads
      <span style="background:#D41C1C;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;margin-left:6px;"><?= $totalFiltered ?></span>
    </span>
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-left:auto;">
      <!-- Quick-assign buttons for default assignees -->
      <?php foreach ($quickAssignRetailers as $qa): ?>
      <button type="button" onclick="quickAssign(<?= $qa['id'] ?>, '<?= h(addslashes($qa['name'])) ?>')"
        style="background:linear-gradient(135deg,#D41C1C,#A81515);color:#fff;border:none;padding:6px 14px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;">
        <span style="width:20px;height:20px;background:rgba(255,255,255,.25);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;"><?= strtoupper(substr($qa['name'],0,1)) ?></span>
        Assign → <?= h($qa['name']) ?>
      </button>
      <?php endforeach; ?>
      <button type="button" onclick="document.getElementById('smartDistributePanel').style.display=document.getElementById('smartDistributePanel').style.display==='none'?'block':'none'"
        style="background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;border:none;padding:6px 14px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;">
        🧠 Smart Distribute
      </button>
      <button type="button" onclick="document.getElementById('autoAssignPanel').style.display=document.getElementById('autoAssignPanel').style.display==='none'?'block':'none'"
        style="background:linear-gradient(135deg,#0891b2,#0e7490);color:#fff;border:none;padding:6px 14px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;">
        ⚙️ Auto-Assign
      </button>
      <button type="button" onclick="document.getElementById('callIntelPanel').style.display=document.getElementById('callIntelPanel').style.display==='none'?'block':'none'"
        style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;padding:6px 14px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;">
        📞 Call Intel
      </button>
      <button type="button" onclick="document.getElementById('csvImportWrap').style.display=document.getElementById('csvImportWrap').style.display==='none'?'block':'none'"
        style="background:#e3f2fd;border:1.5px solid #2196F3;color:#D41C1C;padding:5px 12px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;">📁 CSV</button>
      <button type="button" onclick="document.getElementById('assigneeSettingsWrap').style.display=document.getElementById('assigneeSettingsWrap').style.display==='none'?'block':'none'"
        style="background:#f1f5f9;border:1.5px solid #e2e8f0;color:#64748b;padding:5px 12px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;">⚙️ Defaults</button>
    </div>
  </div>

  <!-- CSV import panel -->
  <div id="csvImportWrap" style="display:none;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb;">
    <div style="font-size:12px;font-weight:700;margin-bottom:8px;">📁 Import Leads from CSV</div>
    <p style="font-size:11px;color:#6b7280;margin:0 0 10px;">Columns: ID, Name, Phone, Email, Status, Interest, Location, Agent, Source, Total Calls, Created, Updated. Duplicates by phone/email skipped.</p>
    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="import_leads_csv">
      <input type="file" name="leads_csv" accept=".csv" required style="font-size:12px;flex:1;min-width:200px;">
      <button type="submit" style="background:#D41C1C;color:#fff;border:none;padding:8px 18px;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer;">Import</button>
    </form>
  </div>

  <!-- Default assignees settings panel -->
  <div id="assigneeSettingsWrap" style="display:none;padding:14px 16px;background:#fffbeb;border-bottom:1px solid #fcd34d;">
    <div style="font-size:12px;font-weight:700;margin-bottom:6px;color:#92400e;">⚙️ Default Quick-Assign Staff</div>
    <p style="font-size:11px;color:#78350f;margin:0 0 10px;">Comma-separated names. These names get quick-assign buttons in the header and appear in the assignee breakdown. Current: <b><?= h(implode(', ', $defaultAssignees)) ?></b></p>
    <form method="POST" style="display:flex;gap:8px;align-items:center;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="set_default_assignees">
      <input type="text" name="assignees" value="<?= h(implode(', ', $defaultAssignees)) ?>"
        style="flex:1;padding:7px 12px;border:1.5px solid #fcd34d;border-radius:8px;font-size:13px;">
      <button type="submit" style="background:#d97706;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer;">Save</button>
    </form>
  </div>

  <!-- ══ SMART DISTRIBUTE PANEL ══════════════════════════════════════════ -->
  <?php
  // Pre-compute per-agent stats — single pass through leads, O(n) not O(n*m)
  $distStatsRaw = [];
  foreach ($allLeads as $l) {
      $rid2 = (int)($l['assigned_to'] ?? $l['retailer_id'] ?? 0);
      if (!$rid2) continue;
      if (!isset($distStatsRaw[$rid2])) $distStatsRaw[$rid2] = ['open'=>0,'won'=>0,'lost'=>0,'respH'=>0,'respN'=>0];
      $st = $l['status'] ?? 'open';
      if (in_array($st,['open','contacted','interested','quoted','qualified'])) $distStatsRaw[$rid2]['open']++;
      elseif ($st==='won')  $distStatsRaw[$rid2]['won']++;
      elseif ($st==='lost') $distStatsRaw[$rid2]['lost']++;
      if (!empty($l['assigned_at']) && !empty($l['history'])) {
          foreach ($l['history'] as $h2) {
              if (($h2['status']??'')==='contacted' && !empty($h2['at'])) {
                  $hrs = (strtotime($h2['at']) - strtotime($l['assigned_at'])) / 3600;
                  if ($hrs > 0 && $hrs < 720) { $distStatsRaw[$rid2]['respH'] += $hrs; $distStatsRaw[$rid2]['respN']++; }
                  break;
              }
          }
      }
  }
  $distStats = [];
  foreach ($allRetailers as $rr) {
      if (!($rr['is_active']??true) || ($rr['is_admin']??false)) continue;
      $rid2 = (int)($rr['id']??0);
      $d = $distStatsRaw[$rid2] ?? ['open'=>0,'won'=>0,'lost'=>0,'respH'=>0,'respN'=>0];
      $tot = $d['won'] + $d['lost'];
      $distStats[$rid2] = [
          'id'       => $rid2,
          'name'     => $rr['name'],
          'phone'    => $rr['phone'] ?? '',
          'role'     => $rr['role'] ?? 'sales',
          'on_leave' => !empty($rr['on_leave']),
          'open'     => $d['open'],
          'won'      => $d['won'],
          'lost'     => $d['lost'],
          'conv_rate'=> $tot > 0 ? round($d['won']/$tot*100) : null,
          'avg_resp' => $d['respN'] > 0 ? round($d['respH']/$d['respN'], 1) : null,
      ];
  }
  $distLog = $store->load('lead_distribution_log.json') ?? [];
  $lastRun = !empty($distLog) ? end($distLog) : null;
  ?>

  <div id="smartDistributePanel" style="display:none;border-bottom:2px solid #7c3aed;">
    <div style="background:linear-gradient(135deg,#4c1d95,#6d28d9);padding:14px 18px;display:flex;align-items:center;gap:10px;">
      <div style="font-size:20px;">🧠</div>
      <div>
        <div style="font-size:14px;font-weight:800;color:#fff;">Smart Lead Distribution Engine</div>
        <div style="font-size:11px;color:#c4b5fd;">Auto-balance leads across agents · 1 WhatsApp summary per agent (not per lead)</div>
      </div>
      <?php if($lastRun): ?>
      <div style="margin-left:auto;background:rgba(255,255,255,.15);border-radius:8px;padding:4px 10px;font-size:10px;color:#e9d5ff;text-align:right;">
        Last run: <?= substr($lastRun['run_at']??'',0,16) ?><br>
        <?= $lastRun['total']??0 ?> leads · by <?= h($lastRun['by']??'') ?>
      </div>
      <?php endif; ?>
    </div>

    <div style="padding:16px 18px;background:#faf5ff;">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="smart_distribute">

        <!-- Agent selector with live stats -->
        <div style="margin-bottom:14px;">
          <div style="font-size:12px;font-weight:800;color:#374151;margin-bottom:8px;">① Select Agents to Distribute To</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;">
            <?php foreach ($distStats as $ds): ?>
            <?php
              $convColor = $ds['conv_rate']===null ? '#9ca3af' : ($ds['conv_rate']>=50?'#16a34a':($ds['conv_rate']>=25?'#d97706':'#dc2626'));
              $respStr   = $ds['avg_resp']===null ? '—' : $ds['avg_resp'].'h';
              $isLeave   = $ds['on_leave'];
            ?>
            <label style="display:flex;align-items:flex-start;gap:8px;background:#fff;border:2px solid <?= $isLeave?'#fde68a':'#e2e8f0' ?>;border-radius:12px;padding:10px 12px;cursor:<?= $isLeave?'not-allowed':'pointer' ?>;opacity:<?= $isLeave?.55:1 ?>;"
                   title="<?= $isLeave?'On leave — excluded from distribution':'' ?>">
              <input type="checkbox" name="agent_ids[]" value="<?= $ds['id'] ?>"
                     <?= $isLeave?'disabled':'' ?>
                     style="width:16px;height:16px;accent-color:#7c3aed;flex-shrink:0;margin-top:2px;">
              <div style="min-width:0;">
                <div style="font-size:12px;font-weight:800;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                  <?= h($ds['name']) ?>
                  <?php if($isLeave): ?><span style="font-size:9px;background:#fde68a;color:#92400e;padding:1px 5px;border-radius:4px;margin-left:3px;">ON LEAVE</span><?php endif; ?>
                </div>
                <div style="font-size:10px;color:#6b7280;margin-top:2px;">
                  📋 <?= $ds['open'] ?> open &nbsp;·&nbsp;
                  ✅ <?= $ds['won'] ?> won &nbsp;·&nbsp;
                  <?php if($ds['conv_rate']!==null): ?><span style="color:<?= $convColor ?>;font-weight:700;"><?= $ds['conv_rate'] ?>%</span><?php else: ?><span style="color:#9ca3af;">0%</span><?php endif; ?>
                </div>
                <div style="font-size:10px;color:#9ca3af;margin-top:1px;">
                  ⏱ Avg response: <?= $respStr ?>
                </div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Config row -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px;">
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;">② Max leads per agent</label>
            <input type="number" name="max_per_agent" value="10" min="1" max="50"
              style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:700;box-sizing:border-box;">
            <div style="font-size:10px;color:#9ca3af;margin-top:2px;">Current load included</div>
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;">③ Distribution strategy</label>
            <select name="strategy" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;box-sizing:border-box;">
              <option value="round_robin">🔄 Round Robin — equal share</option>
              <option value="load_balance">⚖️ Load Balance — fill lightest first</option>
              <option value="performance">🏆 Performance — best agents first</option>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;">④ Filter by service</label>
            <select name="filter_service" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;box-sizing:border-box;">
              <option value="">All services</option>
              <option value="starlink">Starlink only</option>
              <option value="fiber">Fiber only</option>
              <option value="sim">SIM only</option>
            </select>
          </div>
        </div>

        <div style="display:flex;gap:10px;margin-bottom:12px;align-items:center;flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;font-weight:700;color:#374151;">
            <input type="checkbox" name="only_unassigned" value="1" checked style="width:15px;height:15px;accent-color:#7c3aed;">
            Only unassigned leads
          </label>
          <div style="flex:1;min-width:200px;">
            <input type="text" name="distribute_note" placeholder="Optional note to agents (e.g. 'March campaign — follow up within 48h')"
              style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;box-sizing:border-box;">
          </div>
        </div>

        <!-- WhatsApp notification callout -->
        <div style="background:#ecfdf5;border:1px solid #86efac;border-radius:10px;padding:10px 14px;font-size:11px;color:#166534;margin-bottom:12px;display:flex;gap:8px;">
          <span style="font-size:16px;">📱</span>
          <div>
            <strong>Smart notification batching:</strong> Regardless of how many leads are assigned, each agent receives exactly <strong>1 WhatsApp summary</strong> listing all their new leads.
            Assigning 10 leads to 4 agents = <strong>4 messages total</strong>, not 40.
          </div>
        </div>

        <button type="submit" style="background:linear-gradient(135deg,#6d28d9,#7c3aed);color:#fff;border:none;border-radius:10px;padding:11px 24px;font-size:13px;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:8px;">
          🚀 Run Smart Distribution
        </button>
      </form>

      <!-- Distribution history -->
      <?php if (!empty($distLog)): ?>
      <div style="margin-top:16px;border-top:1px solid #e9d5ff;padding-top:12px;">
        <div style="font-size:11px;font-weight:800;color:#6d28d9;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Recent Distribution Runs</div>
        <?php foreach (array_reverse(array_slice($distLog,-5)) as $dr): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f3f0ff;font-size:11px;flex-wrap:wrap;">
          <span style="color:#9ca3af;white-space:nowrap;"><?= substr($dr['run_at']??'',0,16) ?></span>
          <span style="background:#ede9fe;color:#5b21b6;padding:1px 7px;border-radius:8px;font-weight:700;"><?= $dr['total']??0 ?> leads</span>
          <span style="color:#6b7280;"><?= h($dr['strategy']??'') ?></span>
          <?php foreach($dr['assignments']??[] as $da): ?>
          <span style="background:#f3f4f6;color:#374151;padding:1px 6px;border-radius:6px;"><?= h($da['agent']) ?>: <?= $da['count'] ?></span>
          <?php endforeach; ?>
          <?php if($dr['note']??''): ?><span style="color:#9ca3af;font-style:italic;">"<?= h($dr['note']) ?>"</span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ PERFORMANCE MONITOR ════════════════════════════════════════════ -->
  <?php if (!empty($distStats)): ?>
  <div style="padding:14px 16px;background:#f0fdf4;border-bottom:1px solid #bbf7d0;">
    <div style="font-size:11px;font-weight:800;color:#065f46;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">📊 Agent Performance Monitor</div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:12px;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);">
      <thead>
        <tr style="background:#ecfdf5;border-bottom:2px solid #bbf7d0;">
          <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Agent</th>
          <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Open</th>
          <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Won ✅</th>
          <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Lost ❌</th>
          <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Conv %</th>
          <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Avg Response</th>
          <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Load Bar</th>
          <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $maxOpen = max(1, max(array_column($distStats, 'open')));
      foreach ($distStats as $ds):
          $convC = $ds['conv_rate']===null ? '#9ca3af' : ($ds['conv_rate']>=50?'#16a34a':($ds['conv_rate']>=25?'#d97706':'#dc2626'));
          $loadPct = min(100, round($ds['open'] / $maxOpen * 100));
          $loadC = $loadPct >= 80 ? '#dc2626' : ($loadPct >= 50 ? '#d97706' : '#16a34a');
      ?>
      <tr style="border-bottom:1px solid #f0fdf4;">
        <td style="padding:9px 12px;">
          <div style="font-weight:700;color:#1e293b;"><?= h($ds['name']) ?></div>
          <div style="font-size:10px;color:#9ca3af;"><?= h($ds['role']) ?><?= $ds['on_leave']?' · 🏖 on leave':'' ?></div>
        </td>
        <td style="padding:9px 12px;text-align:center;font-size:15px;font-weight:800;color:#1d4ed8;"><?= $ds['open'] ?></td>
        <td style="padding:9px 12px;text-align:center;font-size:13px;font-weight:700;color:#16a34a;"><?= $ds['won'] ?></td>
        <td style="padding:9px 12px;text-align:center;font-size:13px;font-weight:700;color:#dc2626;"><?= $ds['lost'] ?></td>
        <td style="padding:9px 12px;text-align:center;">
          <?php if ($ds['conv_rate']!==null): ?>
          <span style="background:<?= $convC ?>20;color:<?= $convC ?>;font-weight:800;padding:2px 8px;border-radius:8px;font-size:12px;"><?= $ds['conv_rate'] ?>%</span>
          <?php else: ?><span style="color:#9ca3af;font-size:11px;">—</span><?php endif; ?>
        </td>
        <td style="padding:9px 12px;text-align:center;color:#374151;">
          <?= $ds['avg_resp']!==null ? '<strong>'.$ds['avg_resp'].'h</strong>' : '<span style="color:#9ca3af">—</span>' ?>
        </td>
        <td style="padding:9px 12px;min-width:80px;">
          <div style="background:#f1f5f9;border-radius:4px;height:8px;overflow:hidden;">
            <div style="background:<?= $loadC ?>;height:100%;width:<?= $loadPct ?>%;border-radius:4px;transition:width .3s;"></div>
          </div>
          <div style="font-size:9px;color:#9ca3af;margin-top:2px;text-align:center;"><?= $ds['open'] ?> active</div>
        </td>
        <td style="padding:9px 12px;">
          <a href="?page=dashboard&tab=all_leads&lf_assignee=<?= urlencode($ds['name']) ?>"
             style="font-size:11px;font-weight:700;color:#1d4ed8;text-decoration:none;background:#eff6ff;padding:3px 10px;border-radius:6px;white-space:nowrap;">View leads →</a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div style="font-size:10px;color:#9ca3af;margin-top:6px;">Conv % = won ÷ (won + lost). Avg Response = time from assignment to first "contacted" status update. Load bar = relative to busiest agent.</div>
  </div>
  <?php endif; ?>

  <!-- ══ AUTO-ASSIGN SETTINGS PANEL ════════════════════════════════════════ -->
  <?php
  $autoEnabled   = (bool)($cfg['lead_auto_assign_enabled']     ?? false);
  $dripSize      = (int)($cfg['lead_drip_size']                ?? 5);
  $flagHours     = (int)($cfg['lead_stale_flag_hours']         ?? 48);
  $reassignHours = (int)($cfg['lead_stale_reassign_hours']     ?? 72);
  $cronLog       = $store->load('lead_cron_log.json')          ?? [];
  $lastCronRun   = !empty($cronLog) ? end($cronLog) : null;
  ?>
  <div id="autoAssignPanel" style="display:none;border-bottom:2px solid #0891b2;">
    <div style="background:linear-gradient(135deg,#164e63,#0e7490);padding:14px 18px;display:flex;align-items:center;gap:10px;">
      <div style="font-size:20px;">⚙️</div>
      <div>
        <div style="font-size:14px;font-weight:800;color:#fff;">Auto-Assign Engine</div>
        <div style="font-size:11px;color:#a5f3fc;">Drip 5 leads per agent → auto-reassign stale leads → follow-up reminders</div>
      </div>
      <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
        <?php if($lastCronRun): ?>
        <div style="background:rgba(255,255,255,.15);border-radius:8px;padding:4px 10px;font-size:10px;color:#e0f2fe;text-align:right;">
          Last run: <?= substr($lastCronRun['started_at']??'',0,16) ?><br>
          Drip:<?= $lastCronRun['drip_total']??0 ?> · Stale:<?= array_sum(array_map(fn($x)=>$x['count'],$lastCronRun['stale']??[])) ?> · Rem:<?= $lastCronRun['reminders']??0 ?>
        </div>
        <?php endif; ?>
        <button type="button" onclick="runLeadCron(this)"
          style="background:#fff;color:#0e7490;font-weight:800;font-size:11px;padding:7px 14px;border:none;border-radius:8px;cursor:pointer;">
          ▶ Run Now
        </button>
      </div>
    </div>
    <div style="padding:16px 18px;background:#ecfeff;">

      <!-- How it works -->
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;">
        <div style="background:#fff;border-radius:12px;padding:12px;border-left:4px solid #0891b2;">
          <div style="font-size:18px;">📋</div>
          <div style="font-size:12px;font-weight:800;color:#0e7490;margin-top:4px;">Drip Assign</div>
          <div style="font-size:11px;color:#6b7280;margin-top:3px;">Every 4h — top up each agent to <strong><?= $dripSize ?> active leads</strong>. Round-robin from unassigned pool. 1 WA summary per agent.</div>
        </div>
        <div style="background:#fff;border-radius:12px;padding:12px;border-left:4px solid #f59e0b;">
          <div style="font-size:18px;">⏰</div>
          <div style="font-size:12px;font-weight:800;color:#d97706;margin-top:4px;">Stale Warning</div>
          <div style="font-size:11px;color:#6b7280;margin-top:3px;">After <strong><?= $flagHours ?>h no update</strong> — agent gets WA warning: "Update now or lead gets reassigned."</div>
        </div>
        <div style="background:#fff;border-radius:12px;padding:12px;border-left:4px solid #dc2626;">
          <div style="font-size:18px;">🔄</div>
          <div style="font-size:12px;font-weight:800;color:#dc2626;margin-top:4px;">Auto-Reassign</div>
          <div style="font-size:11px;color:#6b7280;margin-top:3px;">After <strong><?= $reassignHours ?>h total</strong> — lead pulled from idle agent, given to lightest-loaded agent.</div>
        </div>
      </div>

      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_lead_auto_settings">
        <div style="display:grid;grid-template-columns:repeat(2,1fr) auto;gap:12px;align-items:end;">
          <div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:10px;">
              <input type="checkbox" name="lead_auto_assign_enabled" value="1" <?= $autoEnabled?'checked':'' ?>
                style="width:18px;height:18px;accent-color:#0891b2;">
              <span style="font-size:13px;font-weight:800;color:#0e7490;">Auto-Assign Engine <?= $autoEnabled?'<span style="color:#16a34a;">● ON</span>':'<span style="color:#dc2626;">● OFF</span>' ?></span>
            </label>
            <label style="display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:3px;">Drip size (leads per agent)</label>
            <input type="number" name="lead_drip_size" value="<?= $dripSize ?>" min="1" max="20"
              style="width:100%;padding:8px;border:1.5px solid #a5f3fc;border-radius:8px;font-size:13px;font-weight:700;box-sizing:border-box;">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:3px;">Stale warning after (hours)</label>
            <input type="number" name="lead_stale_flag_hours" value="<?= $flagHours ?>" min="12" max="168"
              style="width:100%;padding:8px;border:1.5px solid #a5f3fc;border-radius:8px;font-size:13px;box-sizing:border-box;">
            <label style="display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:3px;margin-top:8px;">Auto-reassign after (hours)</label>
            <input type="number" name="lead_stale_reassign_hours" value="<?= $reassignHours ?>" min="24" max="336"
              style="width:100%;padding:8px;border:1.5px solid #a5f3fc;border-radius:8px;font-size:13px;box-sizing:border-box;">
          </div>
          <div>
            <button type="submit" style="background:#0891b2;color:#fff;border:none;border-radius:10px;padding:12px 20px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;">
              💾 Save Settings
            </button>
          </div>
        </div>
      </form>

      <!-- Cron setup instructions -->
      <div style="background:#fff;border-radius:10px;padding:12px 14px;margin-top:14px;border:1px solid #a5f3fc;">
        <div style="font-size:11px;font-weight:800;color:#0e7490;margin-bottom:6px;">📋 Cron Setup (run every 4 hours)</div>
        <code style="font-size:10px;color:#374151;display:block;background:#f0fdfe;padding:6px 10px;border-radius:6px;overflow-x:auto;">
          0 */4 * * * php <?= h(realpath(__DIR__.'/../cron_leads.php') ?: '/path/to/cron_leads.php') ?> >> /var/log/dishnet_leads.log 2>&1
        </code>
        <div id="cronRunResult" style="font-size:11px;margin-top:6px;color:#6b7280;"></div>
      </div>
    </div>
  </div>

  <!-- ══ CALL INTELLIGENCE PANEL ════════════════════════════════════════════ -->
  <?php
  // Compute call stats across all leads
  $callStats = [];
  $totalCallsMade = 0;
  $leadsCalledToday = 0;
  $todayStr2 = date('Y-m-d');
  foreach ($allLeads as $al) {
      foreach ($al['call_log'] ?? [] as $cl) {
          $agN = $cl['by'] ?? '—';
          if (!isset($callStats[$agN])) $callStats[$agN] = ['total'=>0,'answered'=>0,'no_answer'=>0,'today'=>0,'recordings'=>0];
          $callStats[$agN]['total']++;
          $totalCallsMade++;
          $oc = $cl['outcome'] ?? 'no_answer';
          if ($oc === 'answered' || $oc === 'interested') $callStats[$agN]['answered']++;
          else $callStats[$agN]['no_answer']++;
          if (str_starts_with($cl['at'] ?? '', $todayStr2)) { $callStats[$agN]['today']++; $leadsCalledToday++; }
          if (!empty($cl['recording'])) $callStats[$agN]['recordings']++;
      }
  }
  $recLog = $store->load('call_recordings_log.json') ?? [];
  $totalRecordings = count($recLog);
  arsort($callStats);
  ?>
  <div id="callIntelPanel" style="display:none;border-bottom:2px solid #16a34a;">
    <div style="background:linear-gradient(135deg,#14532d,#166534);padding:14px 18px;display:flex;align-items:center;gap:10px;">
      <div style="font-size:20px;">📞</div>
      <div>
        <div style="font-size:14px;font-weight:800;color:#fff;">Call Intelligence</div>
        <div style="font-size:11px;color:#bbf7d0;">Call logs · agent performance · recording setup guide</div>
      </div>
      <div style="margin-left:auto;display:flex;gap:8px;">
        <div style="background:rgba(255,255,255,.15);border-radius:8px;padding:5px 12px;text-align:center;">
          <div style="font-size:16px;font-weight:900;color:#fff;"><?= $totalCallsMade ?></div>
          <div style="font-size:9px;color:#bbf7d0;">Total Calls</div>
        </div>
        <div style="background:rgba(255,255,255,.15);border-radius:8px;padding:5px 12px;text-align:center;">
          <div style="font-size:16px;font-weight:900;color:#fff;"><?= $leadsCalledToday ?></div>
          <div style="font-size:9px;color:#bbf7d0;">Calls Today</div>
        </div>
        <div style="background:rgba(255,255,255,.15);border-radius:8px;padding:5px 12px;text-align:center;">
          <div style="font-size:16px;font-weight:900;color:#fff;"><?= $totalRecordings ?></div>
          <div style="font-size:9px;color:#bbf7d0;">Recordings</div>
        </div>
      </div>
    </div>

    <div style="padding:16px 18px;background:#f0fdf4;">

      <!-- Agent call stats table -->
      <?php if (!empty($callStats)): ?>
      <div style="font-size:11px;font-weight:800;color:#065f46;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Agent Call Performance</div>
      <div style="overflow-x:auto;margin-bottom:16px;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);">
        <thead>
          <tr style="background:#dcfce7;border-bottom:2px solid #86efac;">
            <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Agent</th>
            <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Total Calls</th>
            <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Answered</th>
            <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">No Answer</th>
            <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Today</th>
            <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Answer %</th>
            <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">Recordings</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($callStats as $agentNm => $cs):
          $ansRate = $cs['total'] > 0 ? round($cs['answered']/$cs['total']*100) : 0;
          $ansColor = $ansRate >= 50 ? '#16a34a' : ($ansRate >= 25 ? '#d97706' : '#dc2626');
        ?>
        <tr style="border-bottom:1px solid #f0fdf4;">
          <td style="padding:8px 12px;font-weight:700;"><?= h($agentNm) ?></td>
          <td style="padding:8px 12px;text-align:center;font-size:15px;font-weight:800;color:#1d4ed8;"><?= $cs['total'] ?></td>
          <td style="padding:8px 12px;text-align:center;color:#16a34a;font-weight:700;"><?= $cs['answered'] ?></td>
          <td style="padding:8px 12px;text-align:center;color:#dc2626;font-weight:700;"><?= $cs['no_answer'] ?></td>
          <td style="padding:8px 12px;text-align:center;font-weight:700;color:#0891b2;"><?= $cs['today'] ?></td>
          <td style="padding:8px 12px;text-align:center;">
            <span style="background:<?= $ansColor ?>20;color:<?= $ansColor ?>;font-weight:800;padding:2px 8px;border-radius:8px;"><?= $ansRate ?>%</span>
          </td>
          <td style="padding:8px 12px;text-align:center;">
            <?php if ($cs['recordings'] > 0): ?>
            <span style="background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:6px;font-size:11px;">🎙 <?= $cs['recordings'] ?></span>
            <?php else: ?><span style="color:#9ca3af;font-size:11px;">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php else: ?>
      <div style="text-align:center;padding:20px;color:#9ca3af;font-size:12px;">No calls logged yet. Staff tap 📞 Call and log outcomes to see stats here.</div>
      <?php endif; ?>

      <!-- Call Recording Setup Guide -->
      <div style="background:#fff;border-radius:12px;padding:16px;border:2px solid #86efac;">
        <div style="font-size:13px;font-weight:800;color:#065f46;margin-bottom:10px;">🎙 How to Auto-Record ALL Staff Calls (SIM-Based, No Cloud Phone Needed)</div>
        <p style="font-size:11px;color:#374151;margin:0 0 10px;">
          TeleCRM-style call recording using the staff's own Android phone SIM — no VoIP, no extra hardware.
          Every call to any number is automatically recorded and uploaded to this system.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
          <div style="background:#f0fdf4;border-radius:8px;padding:10px;">
            <div style="font-size:11px;font-weight:800;color:#065f46;margin-bottom:6px;">📱 Step 1 — Install BCR</div>
            <div style="font-size:10px;color:#374151;line-height:1.5;">
              <strong>BCR (Basic Call Recorder)</strong> — free, open-source, no ads.<br>
              Works on most Android phones without root.<br>
              Download: <code style="background:#dcfce7;padding:1px 4px;border-radius:3px;">github.com/chenxiaolong/BCR</code><br>
              → Install APK → Enable "Record all calls" in settings
            </div>
          </div>
          <div style="background:#f0fdf4;border-radius:8px;padding:10px;">
            <div style="font-size:11px;font-weight:800;color:#065f46;margin-bottom:6px;">☁️ Step 2 — Auto-Upload Script</div>
            <div style="font-size:10px;color:#374151;line-height:1.5;">
              BCR saves recordings to phone storage.<br>
              Use <strong>Tasker</strong> or <strong>MacroDroid</strong> (free) to:<br>
              → Watch BCR folder for new files<br>
              → POST to this system's API automatically
            </div>
          </div>
          <div style="background:#f0fdf4;border-radius:8px;padding:10px;">
            <div style="font-size:11px;font-weight:800;color:#065f46;margin-bottom:6px;">🔗 Step 3 — Upload API Endpoint</div>
            <div style="font-size:10px;color:#374151;line-height:1.5;">
              In Tasker/MacroDroid, configure HTTP POST:<br>
              <code style="background:#dcfce7;padding:1px 4px;border-radius:3px;font-size:9px;word-break:break-all;">
                <?= h(rtrim($config['crm_base_url']??'https://crm.dishnetafrica.com','/')
                    ?:'https://crm.dishnetafrica.com') ?>/crm/_plugins/dishnet-hybrid-telecom/public.php?page=api&action=upload_call_recording
              </code><br>
              Fields: <code style="background:#dcfce7;padding:1px 4px;border-radius:3px;">recording</code> (file), <code style="background:#dcfce7;padding:1px 4px;border-radius:3px;">phone</code> (caller number), <code style="background:#dcfce7;padding:1px 4px;border-radius:3px;">direction</code> (inbound/outbound)<br>
              Header: <code style="background:#dcfce7;padding:1px 4px;border-radius:3px;">Authorization: Bearer {agent_token}</code>
            </div>
          </div>
          <div style="background:#f0fdf4;border-radius:8px;padding:10px;">
            <div style="font-size:11px;font-weight:800;color:#065f46;margin-bottom:6px;">✅ What You Get</div>
            <div style="font-size:10px;color:#374151;line-height:1.5;">
              • Every call automatically linked to lead profile<br>
              • Admin can listen from browser — no phone needed<br>
              • Works for inbound AND outbound calls<br>
              • Recording stays on phone too (BCR saves locally)<br>
              • Call duration, timestamp, agent all tracked<br>
              • Recordings survive even if agent deletes from phone
            </div>
          </div>
        </div>

        <!-- MacroDroid template -->
        <details style="margin-top:2px;">
          <summary style="font-size:11px;font-weight:700;color:#0891b2;cursor:pointer;padding:4px 0;">📋 MacroDroid Tasker Configuration Template (click to expand)</summary>
          <div style="background:#f8fafc;border-radius:8px;padding:10px;margin-top:8px;font-size:10px;font-family:monospace;color:#374151;white-space:pre-wrap;overflow-x:auto;">Trigger: File Created in BCR folder
  Folder: /storage/emulated/0/BCR/ (or check BCR settings for exact path)
  
Action: HTTP Request
  URL: <?= h(rtrim($config['crm_base_url']??'https://crm.dishnetafrica.com','/'))?>/crm/_plugins/dishnet-hybrid-telecom/public.php?page=api&action=upload_call_recording
  Method: POST
  Content-Type: multipart/form-data
  Fields:
    recording = [file path from trigger]
    phone     = [extract from filename — BCR names files: YYYYMMDD_HHMMSS_in/out_+2119XXXXXXX.aac]
    direction = [in/out from filename]
    duration  = [file duration in seconds — MacroDroid can read audio metadata]
  Headers:
    Authorization = Bearer {YOUR_AGENT_API_TOKEN}

Note: Each agent uses their own API token from Operations Hub → My Wallet → Account Info</div>
        </details>

        <!-- Recent recordings -->
        <?php if ($totalRecordings > 0):
          $recentRec = array_reverse(array_slice($recLog, -5));
        ?>
        <div style="margin-top:12px;border-top:1px solid #dcfce7;padding-top:10px;">
          <div style="font-size:11px;font-weight:800;color:#065f46;margin-bottom:6px;">Recent Recordings (<?= $totalRecordings ?> total)</div>
          <?php foreach($recentRec as $rec): ?>
          <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f0fdf4;font-size:11px;">
            <span style="color:#6b7280;white-space:nowrap;"><?= substr($rec['recorded_at']??'',0,16) ?></span>
            <span style="font-weight:700;"><?= h($rec['agent']??'') ?></span>
            <span style="color:#9ca3af;"><?= h($rec['phone']??'—') ?></span>
            <?php if(!empty($rec['duration'])): ?><span style="color:#9ca3af;"><?= round($rec['duration']/60,1) ?>m</span><?php endif; ?>
            <?php if(!empty($rec['lead_id'])): ?><span style="background:#eff6ff;color:#1d4ed8;padding:1px 6px;border-radius:6px;">Lead #<?= $rec['lead_id'] ?></span><?php endif; ?>
            <?php
            $recPath = $dataDir . '/' . ($rec['file']??'');
            if (file_exists($recPath)):
            ?>
            <audio controls style="height:24px;flex:1;min-width:100px;" preload="none"
              src="<?= h($_crmBaseW??"") ?>/crm/_plugins/dishnet-hybrid-telecom/public.php?page=api&action=get_call_recording&file=<?= urlencode(basename($rec['file']??'')) ?>&token=<?= h($retailer['api_token']??"") ?>">
            </audio>
            <?php else: ?><span style="color:#9ca3af;font-size:10px;">file missing</span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
  function runLeadCron(btn) {
    btn.disabled = true; btn.textContent = '⏳ Running…';
    var res = document.getElementById('cronRunResult');
    res.textContent = 'Running cron…';
    fetch('<?= h($_crmBaseW??"") ?>/crm/_plugins/dishnet-hybrid-telecom/public.php?page=api&action=run_leads_cron', {
          credentials:'same-origin',
          method: 'POST',
      headers: { 'Authorization': 'Bearer <?= h($retailer['api_token'] ?? "") ?>' }
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      btn.disabled = false; btn.textContent = '▶ Run Now';
      if (d.status === 'success') {
        res.style.color = '#16a34a';
        res.textContent = '✅ Done. ' + (d.data?.output?.split('\n').slice(-3).join(' | ') || '');
      } else {
        res.style.color = '#dc2626';
        res.textContent = '❌ ' + (d.message || 'Error');
      }
    })
    .catch(function(e){ btn.disabled=false; btn.textContent='▶ Run Now'; res.textContent='Network error: '+e.message; });
  }
  </script>

  <div style="padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e5e7eb;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
    <form method="GET" style="display:contents;">
      <input type="hidden" name="page" value="dashboard">
      <input type="hidden" name="tab"  value="all_leads">
      <input type="text" name="lf_q" value="<?= h($fQ) ?>" placeholder="🔍 Search name/phone…"
        style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;min-width:150px;flex:1;">
      <select name="lf_status" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
        <option value="">All Status</option>
        <?php foreach(['open'=>'Open','contacted'=>'Contacted','interested'=>'Interested','quoted'=>'Quoted','qualified'=>'Qualified','won'=>'Won','lost'=>'Lost'] as $sv=>$sl): ?>
        <option value="<?= $sv ?>" <?= $fStatus===$sv?'selected':'' ?>><?= $sl ?></option>
        <?php endforeach; ?>
      </select>
      <select name="lf_service" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
        <option value="">All Services</option>
        <option value="starlink" <?= $fService==='starlink'?'selected':'' ?>>Starlink</option>
        <option value="fiber"    <?= $fService==='fiber'?'selected':'' ?>>Fiber</option>
        <option value="sim"      <?= $fService==='sim'?'selected':'' ?>>SIM</option>
      </select>
      <select name="lf_assignee" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
        <option value="">All Assignees</option>
        <option value="__unassigned__" <?= $fAssignee==='__unassigned__'?'selected':'' ?>>⬜ Unassigned</option>
        <?php foreach (array_keys($assigneeCounts) as $an): if ($an === '—') continue; ?>
        <option value="<?= h($an) ?>" <?= $fAssignee===$an?'selected':'' ?>><?= h($an) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="lf_agent" style="padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;">
        <option value="">All Agents</option>
        <?php foreach ($allRetailers as $rr2): if (($rr2['role']??'sales') === 'admin' && !($rr2['is_admin']??false)) continue; ?>
        <option value="<?= $rr2['id'] ?>" <?= $fAgent==(string)$rr2['id']?'selected':'' ?>><?= h($rr2['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" style="background:#D41C1C;color:#fff;border:none;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">Filter</button>
      <?php if ($fQ||$fStatus||$fAssignee||$fService||$fAgent): ?>
      <a href="?page=dashboard&tab=all_leads" style="font-size:11px;color:#6b7280;padding:6px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;">✕ Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- ── BULK ASSIGN form wrapping the table ───────────────────────────── -->
  <form method="POST" id="bulkAssignForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="assign_leads">
    <input type="hidden" name="assign_to_id"   id="baToId"   value="">
    <input type="hidden" name="assign_to_name" id="baToName" value="">

    <!-- Bulk action toolbar (appears when rows are checked) -->
    <div id="bulkBar" style="display:none;padding:10px 14px;background:linear-gradient(135deg,#1A1A1A,#2A2A2A);color:#fff;border-bottom:1px solid #2A2A2A;align-items:center;gap:8px;flex-wrap:wrap;">
      <span id="bulkCount" style="font-size:13px;font-weight:700;flex:1;"></span>
      <span style="font-size:12px;opacity:.8;">Assign selected to:</span>
      <?php foreach ($quickAssignRetailers as $qa): ?>
      <button type="button" onclick="bulkSubmit(<?= $qa['id'] ?>, '<?= h(addslashes($qa['name'])) ?>')"
        style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.3);padding:6px 16px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">
        <?= h($qa['name']) ?>
      </button>
      <?php endforeach; ?>
      <select id="baCustomSelect" style="padding:6px 10px;border:1px solid rgba(255,255,255,.3);border-radius:8px;font-size:12px;background:rgba(255,255,255,.15);color:#fff;">
        <option value="">Other agent…</option>
        <?php foreach ($allRetailers as $rr3): if (!($rr3['is_active']??true)) continue; ?>
        <option value="<?= $rr3['id'] ?>|<?= h($rr3['name']) ?>"><?= h($rr3['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" onclick="bulkCustom()" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.3);padding:6px 12px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">Go</button>
      <button type="button" onclick="clearChecks()" style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.7);border:none;padding:6px 10px;border-radius:8px;font-size:11px;cursor:pointer;">✕ cancel</button>
    </div>

    <div class="kyc-card-body" style="padding:0;overflow-x:auto;">
      <table class="kyc-table" style="min-width:700px;">
        <thead>
          <tr>
            <th style="width:32px;"><input type="checkbox" id="checkAll" onchange="toggleAll(this)" style="width:16px;height:16px;accent-color:#D41C1C;cursor:pointer;"></th>
            <th>Customer</th>
            <th>Phone</th>
            <th>Service</th>
            <th>Added By</th>
            <th>
              <span style="display:flex;align-items:center;gap:4px;">
                Assigned To
                <?php if ($statUnassigned > 0): ?>
                <span style="background:#ef4444;color:#fff;font-size:9px;padding:1px 5px;border-radius:6px;font-weight:700;"><?= $statUnassigned ?> free</span>
                <?php endif; ?>
              </span>
            </th>
            <th>Status</th>
            <th>Qualified</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $sCfg = ['open'=>['Open','#f59e0b','#fffbeb'],'contacted'=>['Contacted','#3b82f6','#eff6ff'],'interested'=>['Interested','#8b5cf6','#f5f3ff'],'quoted'=>['Quoted','#f97316','#fff7ed'],'qualified'=>['Qualified','#10b981','#ecfdf5'],'won'=>['Won','#22c55e','#f0fdf4'],'lost'=>['Lost','#ef4444','#fef2f2']];
        if (empty($filtered)): ?>
        <tr><td colspan="9" style="text-align:center;padding:28px;color:#94a3b8;">
          <?= $fQ||$fStatus||$fAssignee ? 'No leads match your filters. <a href="?page=dashboard&tab=all_leads">Clear filters</a>' : 'No leads in the system yet.' ?>
        </td></tr>
        <?php else: foreach ($pageRows as $al):
            $als   = $sCfg[$al['status']??'open'] ?? $sCfg['open'];
            $aName = $al['assigned_name'] ?? '';
            $isAssigned = !empty($aName);
            $isDefault  = in_array($aName, $defaultAssignees);
        ?>
        <tr style="<?= !empty($al['qualified'])&&($al['status']??'')==='qualified'?'background:#f0fdf4;':'' ?>">
          <td><input type="checkbox" name="lead_ids[]" value="<?= $al['id'] ?>" class="lead-check"
            onchange="updateBulkBar()" style="width:16px;height:16px;accent-color:#D41C1C;cursor:pointer;"></td>
          <td>
            <div style="font-weight:700;font-size:13px;"><?= h($al['customer_name']??'') ?></div>
            <?php if (!empty($al['email'])): ?><div style="font-size:11px;color:#94a3b8;"><?= h($al['email']) ?></div><?php endif; ?>
            <?php if (($al['sales_type']??'Cash')==='Credit'): ?><span style="font-size:10px;color:#6366f1;font-weight:600;">📋 Credit</span><?php endif; ?>
          </td>
          <td style="font-size:12px;font-weight:600;"><?= h($al['phone']??'') ?></td>
          <td>
            <span style="font-size:11px;background:<?= ['starlink'=>'#E3F2FD','fiber'=>'#E8F5E9','sim'=>'#FFF3E0'][$al['service_type']??'starlink']??'#f1f5f9' ?>;color:<?= ['starlink'=>'#1565C0','fiber'=>'#2E7D32','sim'=>'#E65100'][$al['service_type']??'starlink']??'#374151' ?>;padding:2px 8px;border-radius:6px;font-weight:700;">
              <?= ucfirst($al['service_type']??'') ?>
            </span>
            <?php $alCalls = (int)($al['total_calls'] ?? count($al['call_log']??[])); if ($alCalls > 0): ?>
            <span style="font-size:10px;background:#e0f2fe;color:#0369a1;padding:1px 5px;border-radius:5px;margin-left:3px;">📞<?= $alCalls ?></span>
            <?php endif; ?>
            <?php if (!empty($al['stale_flagged'])): ?><span style="font-size:9px;background:#fef9c3;color:#854d0e;padding:1px 5px;border-radius:5px;margin-left:2px;">⏰stale</span><?php endif; ?>
          </td>
          <td style="font-size:12px;color:#64748b;"><?= h($al['retailer_name']??'') ?></td>
          <td>
            <?php if ($isAssigned): ?>
            <div style="display:flex;align-items:center;gap:6px;">
              <span style="width:26px;height:26px;background:<?= $isDefault?'#D41C1C':'#94a3b8' ?>;color:#fff;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;"><?= strtoupper(substr($aName,0,1)) ?></span>
              <div>
                <div style="font-size:12px;font-weight:700;color:<?= $isDefault?'#D41C1C':'#374151' ?>;"><?= h($aName) ?></div>
                <?php if (!empty($al['assigned_at'])): ?><div style="font-size:10px;color:#94a3b8;"><?= date('d M', strtotime($al['assigned_at'])) ?></div><?php endif; ?>
              </div>
            </div>
            <?php else: ?>
            <span style="font-size:11px;color:#cbd5e1;font-style:italic;">unassigned</span>
            <?php endif; ?>
          </td>
          <td><span style="background:<?= $als[2] ?>;color:<?= $als[1] ?>;padding:3px 9px;border-radius:8px;font-size:10px;font-weight:800;"><?= $als[0] ?></span></td>
          <td>
            <?php if (!empty($al['qualified'])): ?>
              <span style="color:#16a34a;font-weight:700;font-size:11px;">✅ <?= h($al['qualified_by']??'') ?></span>
              <?php if (!in_array($al['status']??'',['won','lost'])): ?>
              <form method="POST" style="display:inline;margin-left:4px;">
                <?= csrfField() ?><input type="hidden" name="action" value="unqualify_lead"><input type="hidden" name="lead_id" value="<?= $al['id'] ?>">
                <button type="submit" style="background:none;border:none;color:#9ca3af;font-size:10px;cursor:pointer;" title="Remove qualification">✕</button>
              </form>
              <?php endif; ?>
            <?php elseif (!in_array($al['status']??'',['won','lost'])): ?>
              <form method="POST">
                <?= csrfField() ?><input type="hidden" name="action" value="qualify_lead"><input type="hidden" name="lead_id" value="<?= $al['id'] ?>"><input type="hidden" name="back_tab" value="all_leads">
                <button type="submit" style="background:#e0f2f1;border:1px solid #80cbc4;color:#00695c;padding:3px 9px;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer;">🔓 Qualify</button>
              </form>
            <?php else: ?>
              <span style="color:#d1d5db;font-size:11px;">—</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap;">
            <!-- Quick single-assign buttons -->
            <?php foreach ($quickAssignRetailers as $qa): if (($al['assigned_name']??'') === $qa['name']) continue; ?>
            <form method="POST" style="display:inline;">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="assign_leads">
              <input type="hidden" name="lead_ids[]" value="<?= $al['id'] ?>">
              <input type="hidden" name="assign_to_id"   value="<?= $qa['id'] ?>">
              <input type="hidden" name="assign_to_name" value="<?= h($qa['name']) ?>">
              <button type="submit" title="Assign to <?= h($qa['name']) ?>"
                style="background:#fff5f5;border:1px solid rgba(212,28,28,.3);color:#D41C1C;padding:3px 8px;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer;margin-right:2px;">
                → <?= h($qa['name']) ?>
              </button>
            </form>
            <?php endforeach; ?>
            <!-- Convert button if qualified -->
            <?php if (!empty($al['qualified']) && !in_array($al['status']??'',['won','lost'])): ?>
            <form method="POST" style="display:inline;">
              <?= csrfField() ?><input type="hidden" name="action" value="convert_lead"><input type="hidden" name="lead_id" value="<?= $al['id'] ?>">
              <button type="submit" style="background:#e65100;color:#fff;border:none;padding:3px 9px;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer;">✅ KYC</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </form><!-- /bulkAssignForm -->

  <!-- ── Pagination ──────────────────────────────────────────────────── -->
  <?php if ($totalPages > 1 || $totalFiltered > 0): ?>
  <div style="padding:12px 16px;border-top:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:#f8fafc;border-radius:0 0 12px 12px;">
    <div style="font-size:12px;color:#64748b;">
      Showing <strong><?= $pageOffset + 1 ?>–<?= min($pageOffset + $perPage, $totalFiltered) ?></strong>
      of <strong><?= $totalFiltered ?></strong> leads
      <?php if ($fQ||$fStatus||$fAssignee||$fService||$fAgent): ?>
      <span style="color:#D41C1C;">(filtered)</span>
      <?php endif; ?>
    </div>
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
      <?php
      // Build base URL preserving all current filters
      $pgParams = array_filter([
          'page'        => 'dashboard',
          'tab'         => 'all_leads',
          'lf_q'        => $fQ,
          'lf_status'   => $fStatus,
          'lf_agent'    => $fAgent,
          'lf_assignee' => $fAssignee,
          'lf_service'  => $fService,
      ]);
      $pgBase = '?' . http_build_query($pgParams) . '&lf_pg=';

      // Prev button
      if ($curPage > 1): ?>
      <a href="<?= $pgBase.($curPage-1) ?>"
         style="padding:5px 12px;background:#fff;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;font-weight:700;color:#374151;text-decoration:none;">‹ Prev</a>
      <?php endif; ?>

      <?php
      // Smart page window: always show first, last, and 2 around current
      $shown = [];
      for ($p = 1; $p <= $totalPages; $p++) {
          if ($p === 1 || $p === $totalPages || abs($p - $curPage) <= 2) {
              $shown[] = $p;
          }
      }
      $prev2 = null;
      foreach ($shown as $p):
          if ($prev2 !== null && $p - $prev2 > 1): ?>
          <span style="font-size:13px;color:#94a3b8;padding:0 2px;">…</span>
      <?php endif;
          $isActive = $p === $curPage; ?>
      <a href="<?= $pgBase.$p ?>"
         style="padding:5px 10px;background:<?= $isActive?'#D41C1C':'#fff' ?>;border:1.5px solid <?= $isActive?'#D41C1C':'#e2e8f0' ?>;border-radius:8px;font-size:12px;font-weight:<?= $isActive?'800':'600' ?>;color:<?= $isActive?'#fff':'#374151' ?>;text-decoration:none;min-width:32px;text-align:center;"><?= $p ?></a>
      <?php $prev2 = $p; endforeach; ?>

      <?php if ($curPage < $totalPages): ?>
      <a href="<?= $pgBase.($curPage+1) ?>"
         style="padding:5px 12px;background:#fff;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;font-weight:700;color:#374151;text-decoration:none;">Next ›</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /kyc-card -->

<script>
function updateBulkBar() {
  var checked = document.querySelectorAll('.lead-check:checked');
  var bar = document.getElementById('bulkBar');
  var cnt = document.getElementById('bulkCount');
  if (checked.length > 0) {
    bar.style.display = 'flex';
    cnt.textContent   = checked.length + ' lead' + (checked.length > 1 ? 's' : '') + ' selected';
  } else {
    bar.style.display = 'none';
  }
}
function toggleAll(cb) {
  document.querySelectorAll('.lead-check').forEach(function(c){ c.checked = cb.checked; });
  updateBulkBar();
}
function clearChecks() {
  document.querySelectorAll('.lead-check').forEach(function(c){ c.checked = false; });
  document.getElementById('checkAll').checked = false;
  updateBulkBar();
}
function bulkSubmit(id, name) {
  var checked = document.querySelectorAll('.lead-check:checked');
  if (!checked.length) { alert('Select at least one lead.'); return; }
  document.getElementById('baToId').value   = id;
  document.getElementById('baToName').value = name;
  document.getElementById('bulkAssignForm').submit();
}
function bulkCustom() {
  var sel = document.getElementById('baCustomSelect');
  if (!sel.value) { alert('Choose an agent.'); return; }
  var parts = sel.value.split('|');
  bulkSubmit(parts[0], parts[1] || '');
}
function quickAssign(id, name) {
  var checked = document.querySelectorAll('.lead-check:checked');
  if (!checked.length) { alert('Select leads first using the checkboxes, then click Assign.'); return; }
  bulkSubmit(id, name);
}
</script>



