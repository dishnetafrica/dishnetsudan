<?php
// ── Send Quotation Tab — DishNet Hybrid v4.4.20 ───────────────────────────────
// Flows: B (lead quote), C (cash proforma), D (manual/any customer)
// Requires: $store, $dataDir, $retailer, $isAdmin, $userRole

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

require_once __DIR__ . '/../../lib/QuotationService.php';

$allowedRoles = ['sales', 'accountant', 'admin', 'field_accountant'];
if (!$isAdmin && !in_array($userRole ?? '', $allowedRoles)) {
    echo '<div style="padding:40px;text-align:center;color:#dc2626;">⛔ Access denied.</div>';
    return;
}

$cfg     = $store->load('kyc_config.json') ?: [];
$quotSvc = new QuotationService($store, $dataDir, $cfg);

// Load plans for item picker autocomplete
$plans = $store->load('subscription_plans.json') ?? [];
$plansJson = json_encode(array_values(array_map(fn($p) => [
    'id'    => $p['id'] ?? 0,
    'name'  => $p['name'] ?? '',
    'price' => (float)($p['customer_price'] ?? $p['amount'] ?? 0),
    'unit'  => 'month',
], array_filter($plans, fn($p) => (float)($p['customer_price'] ?? $p['amount'] ?? 0) > 0))));

// Load leads for lead picker
$leads = $store->load('leads.json') ?? [];
$openLeads = array_values(array_filter($leads, fn($l) =>
    in_array($l['status'] ?? 'open', ['open','contacted','interested','qualified'], true) &&
    !empty($l['phone'])
));

// Flash message from POST redirect
$flash = '';
$flashType = 'success';
if (!empty($_GET['flash'])) {
    $flash     = urldecode($_GET['flash']);
    $flashType = $_GET['ft'] ?? 'success';
}

// Active sub-tab: lead | cash | manual
$subTab = $_GET['qmode'] ?? 'lead';
?>

<style>
.sq-card { background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:24px; max-width:640px; }
.sq-label { display:block; font-size:0.83rem; font-weight:600; color:#374151; margin-bottom:4px; }
.sq-input { width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.9rem; box-sizing:border-box; }
.sq-input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
.sq-row { display:flex; gap:10px; margin-bottom:14px; }
.sq-row > * { flex:1; }
.sq-btn { padding:10px 22px; border:none; border-radius:8px; cursor:pointer; font-size:0.9rem; font-weight:600; }
.sq-btn-primary { background:#6366f1; color:#fff; }
.sq-btn-primary:hover { background:#4f46e5; }
.sq-btn-ghost { background:#f3f4f6; color:#374151; }
.sq-btn-ghost:hover { background:#e5e7eb; }
.sq-btn-green { background:#10b981; color:#fff; }
.sq-btn-sm { padding:5px 12px; font-size:0.82rem; border-radius:6px; }
.sq-item-row { display:flex; gap:8px; align-items:center; margin-bottom:8px; background:#f9fafb; border-radius:8px; padding:8px 10px; }
.sq-item-row input { flex:1; padding:6px 9px; border:1px solid #d1d5db; border-radius:6px; font-size:0.85rem; }
.sq-item-row input.qty { max-width:58px; }
.sq-item-row input.price { max-width:90px; }
.sq-item-row input.unit { max-width:80px; }
.sq-remove { background:none; border:none; color:#ef4444; font-size:1.1rem; cursor:pointer; padding:0 4px; }
.sq-total-bar { background:#f0fdf4; border:1px solid #6ee7b7; border-radius:8px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; margin:12px 0; }
.sq-tab-pill { display:inline-block; padding:8px 18px; border-radius:30px; font-size:0.88rem; font-weight:600; text-decoration:none; color:#6b7280; border:2px solid transparent; }
.sq-tab-pill.active { background:#6366f1; color:#fff; }
.sq-section-title { font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:#9ca3af; font-weight:700; margin:16px 0 8px; }
.sq-preview-box { background:#1a1a1a; color:#e5e7eb; border-radius:10px; padding:16px; font-size:0.82rem; font-family:monospace; white-space:pre-wrap; max-height:380px; overflow-y:auto; display:none; }
.sq-badge { display:inline-block; padding:1px 8px; border-radius:12px; font-size:0.75rem; font-weight:700; }
</style>

<div style="padding:24px 20px;">

  <!-- Header -->
  <div style="margin-bottom:20px;">
    <h2 style="margin:0;font-size:1.3rem;font-weight:700;color:#111827;">📄 Send Quotation</h2>
    <p style="margin:4px 0 0;font-size:0.85rem;color:#6b7280;">Build a proforma and send via WhatsApp (+ UCRM email for registered customers).</p>
  </div>

  <?php if ($flash): ?>
  <div style="padding:12px 16px;border-radius:8px;margin-bottom:16px;<?= $flashType==='success' ? 'background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;' : 'background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;' ?>">
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <!-- Sub-tab pills -->
  <div style="display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap;">
    <a href="?page=dashboard&tab=send_quote&qmode=lead"   class="sq-tab-pill <?= $subTab==='lead'   ? 'active' : '' ?>">🎯 Lead Quote</a>
    <a href="?page=dashboard&tab=send_quote&qmode=cash"   class="sq-tab-pill <?= $subTab==='cash'   ? 'active' : '' ?>">💵 Cash Proforma</a>
    <a href="?page=dashboard&tab=send_quote&qmode=manual" class="sq-tab-pill <?= $subTab==='manual' ? 'active' : '' ?>">✏️ Manual Quote</a>
  </div>

  <!-- ════════ FLOW B: LEAD QUOTE ════════ -->
  <?php if ($subTab === 'lead'): ?>
  <div class="sq-card">
    <p style="font-size:0.85rem;color:#6b7280;margin:0 0 16px;">Pick a lead — we'll auto-fill pricing from their interest plan and send via WhatsApp.</p>

    <form method="post" id="frmLeadQuote">
      <input type="hidden" name="action" value="send_lead_quote">
      <?= csrfField() ?>
      <input type="hidden" name="quote_items_json" id="leadItemsJson" value="[]">

      <div style="margin-bottom:14px;">
        <label class="sq-label">Lead *</label>
        <select name="lead_id" id="leadPicker" class="sq-input" required onchange="onLeadPick(this)">
          <option value="">— Select a lead —</option>
          <?php foreach ($openLeads as $l): ?>
          <option value="<?= (int)$l['id'] ?>"
                  data-phone="<?= htmlspecialchars($l['phone'] ?? '') ?>"
                  data-service="<?= htmlspecialchars($l['service_type'] ?? '') ?>"
                  data-plan="<?= htmlspecialchars($l['interest_plan'] ?? '') ?>"
                  data-crm="<?= (int)($l['crm_client_id'] ?? 0) ?>">
            <?= htmlspecialchars($l['customer_name'] ?? 'Unknown') ?>
            (<?= htmlspecialchars($l['phone'] ?? '') ?>)
            — <?= htmlspecialchars(ucfirst($l['service_type'] ?? '')) ?>
            <?= $l['interest_plan'] ? '/ ' . htmlspecialchars($l['interest_plan']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="leadPickedInfo" style="display:none;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:0.85rem;">
        📞 <span id="leadPhone"></span> &nbsp;|&nbsp; 🔧 <span id="leadService"></span>
        <span id="leadCrmBadge" style="display:none;margin-left:8px;">
          <span class="sq-badge" style="background:#d1fae5;color:#065f46;">CRM ✓</span>
          <label style="margin-left:6px;font-weight:600;font-size:0.8rem;">
            <input type="checkbox" name="create_crm_quote" value="1" checked> Also create UCRM quote
          </label>
        </span>
      </div>

      <div class="sq-section-title">Quote Items</div>
      <div id="leadItemsList"></div>

      <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="addItem('lead')" style="margin-bottom:12px;">+ Add item</button>

      <div class="sq-total-bar" id="leadTotalBar" style="display:none;">
        <span style="font-weight:600;color:#065f46;">Total</span>
        <span style="font-size:1.1rem;font-weight:800;color:#059669;">$<span id="leadTotal">0.00</span></span>
      </div>

      <div style="margin-bottom:14px;">
        <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="previewMsg('lead')" style="margin-right:8px;">👁 Preview WA Message</button>
      </div>

      <pre class="sq-preview-box" id="previewLead"></pre>

      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="sq-btn sq-btn-primary" id="leadSubmitBtn" disabled>📤 Send Quotation via WhatsApp</button>
      </div>
    </form>
  </div>

  <!-- ════════ FLOW C: CASH PROFORMA ════════ -->
  <?php elseif ($subTab === 'cash'): ?>
  <div class="sq-card">
    <p style="font-size:0.85rem;color:#6b7280;margin:0 0 16px;">Instant proforma for a walk-in or cash sale. No CRM account needed — just name + phone.</p>

    <form method="post" id="frmCash">
      <input type="hidden" name="action" value="send_cash_proforma">
      <?= csrfField() ?>
      <input type="hidden" name="cq_items_json" id="cashItemsJson" value="[]">

      <div class="sq-row">
        <div>
          <label class="sq-label">Customer Name *</label>
          <input type="text" name="cq_customer_name" class="sq-input" required placeholder="e.g. James Diko">
        </div>
        <div>
          <label class="sq-label">WhatsApp Phone *</label>
          <input type="text" name="cq_customer_phone" class="sq-input" required placeholder="+211 920…">
        </div>
      </div>

      <div style="margin-bottom:14px;">
        <label class="sq-label">Amount Already Paid ($) <span style="color:#9ca3af;font-weight:400;">(0 if not yet paid)</span></label>
        <input type="number" name="cq_amount_paid" class="sq-input" min="0" step="0.01" value="0" style="max-width:180px;" onchange="recalc('cash')">
      </div>

      <div class="sq-section-title">Items / Services</div>
      <div id="cashItemsList"></div>
      <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="addItem('cash')" style="margin-bottom:12px;">+ Add item</button>

      <div class="sq-total-bar" id="cashTotalBar" style="display:none;">
        <span style="font-weight:600;color:#065f46;">Total</span>
        <span style="font-size:1.1rem;font-weight:800;color:#059669;">$<span id="cashTotal">0.00</span></span>
      </div>

      <div style="margin-bottom:14px;">
        <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="previewMsg('cash')">👁 Preview WA Message</button>
      </div>
      <pre class="sq-preview-box" id="previewCash"></pre>

      <div style="margin-top:16px;">
        <button type="submit" class="sq-btn sq-btn-primary">📤 Send Proforma via WhatsApp</button>
      </div>
    </form>
  </div>

  <!-- ════════ FLOW D: MANUAL QUOTE ════════ -->
  <?php elseif ($subTab === 'manual'): ?>
  <div class="sq-card">
    <p style="font-size:0.85rem;color:#6b7280;margin:0 0 16px;">Send a quote to any customer. Provide CRM ID to also generate a formal UCRM quote + email.</p>

    <form method="post" id="frmManual">
      <input type="hidden" name="action" value="send_manual_quote">
      <?= csrfField() ?>
      <input type="hidden" name="mq_items_json" id="manualItemsJson" value="[]">

      <div class="sq-row">
        <div>
          <label class="sq-label">Customer Name *</label>
          <input type="text" name="mq_customer_name" class="sq-input" required placeholder="Full name">
        </div>
        <div>
          <label class="sq-label">WhatsApp Phone *</label>
          <input type="text" name="mq_customer_phone" class="sq-input" required placeholder="+211 920…">
        </div>
      </div>

      <div class="sq-row">
        <div>
          <label class="sq-label">CRM Client ID <span style="color:#9ca3af;font-weight:400;">(optional — auto-fetches name/phone)</span></label>
          <input type="number" name="mq_crm_client_id" class="sq-input" min="0" placeholder="e.g. 1234" id="manualCrmId" oninput="toggleCrmQuoteOption()">
        </div>
        <div id="mq_crm_opt" style="display:none;padding-top:22px;">
          <label style="font-size:0.85rem;font-weight:600;">
            <input type="checkbox" name="mq_create_crm" value="1" checked>
            Also create UCRM quote + send email
          </label>
        </div>
      </div>

      <div style="margin-bottom:14px;">
        <label class="sq-label">Note / Footer message <span style="color:#9ca3af;font-weight:400;">(optional)</span></label>
        <input type="text" name="mq_note" class="sq-input" placeholder="e.g. Includes free router installation this week only">
      </div>

      <div class="sq-section-title">Quote Items</div>
      <div id="manualItemsList"></div>
      <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="addItem('manual')" style="margin-bottom:12px;">+ Add item</button>

      <div class="sq-total-bar" id="manualTotalBar" style="display:none;">
        <span style="font-weight:600;color:#065f46;">Total</span>
        <span style="font-size:1.1rem;font-weight:800;color:#059669;">$<span id="manualTotal">0.00</span></span>
      </div>

      <div style="margin-bottom:14px;">
        <button type="button" class="sq-btn sq-btn-ghost sq-btn-sm" onclick="previewMsg('manual')">👁 Preview WA Message</button>
      </div>
      <pre class="sq-preview-box" id="previewManual"></pre>

      <div style="margin-top:16px;">
        <button type="submit" class="sq-btn sq-btn-primary">📤 Send Quote via WhatsApp</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

</div>

<script>
const PLANS = <?= $plansJson ?>;
const LEAD_DATA = {};
<?php foreach ($openLeads as $l): ?>
LEAD_DATA[<?= (int)$l['id'] ?>] = {
  phone: <?= json_encode($l['phone'] ?? '') ?>,
  service: <?= json_encode(ucfirst($l['service_type'] ?? '')) ?>,
  plan: <?= json_encode($l['interest_plan'] ?? '') ?>,
  crm_client_id: <?= (int)($l['crm_client_id'] ?? 0) ?>
};
<?php endforeach; ?>

const itemCounters = {lead:0, cash:0, manual:0};

function addItem(ctx, label='', price=0, qty=1, unit='month') {
  const container = document.getElementById(ctx + 'ItemsList');
  const id = ++itemCounters[ctx];
  const div = document.createElement('div');
  div.className = 'sq-item-row';
  div.id = ctx + '_item_' + id;
  div.innerHTML = `
    <input type="text" placeholder="Description" value="${label}" oninput="recalc('${ctx}')" class="label">
    <input type="number" class="qty" placeholder="Qty" value="${qty}" min="1" oninput="recalc('${ctx}')">
    <input type="text" class="unit" placeholder="Unit" value="${unit}" title="e.g. month, piece, amount">
    <span>$</span>
    <input type="number" class="price" placeholder="Price" value="${price || ''}" min="0" step="0.01" oninput="recalc('${ctx}')">
    <button type="button" class="sq-remove" onclick="removeItem('${ctx}','${id}')" title="Remove">✕</button>
  `;
  // Plan autocomplete on label input
  const labelInput = div.querySelector('.label');
  labelInput.addEventListener('focus', () => showPlanSuggest(labelInput, ctx, id));
  container.appendChild(div);
  recalc(ctx);
}

function showPlanSuggest(inp, ctx, id) {
  if (!PLANS.length) return;
  const existing = document.getElementById('planSuggest_' + ctx + '_' + id);
  if (existing) return;
  const wrap = document.createElement('datalist');
  wrap.id = 'planSuggest_' + ctx + '_' + id;
  PLANS.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p.name + ' — $' + p.price + '/' + p.unit;
    opt.dataset.price = p.price;
    opt.dataset.unit  = p.unit;
    opt.dataset.name  = p.name;
    wrap.appendChild(opt);
  });
  inp.setAttribute('list', wrap.id);
  inp.after(wrap);
  inp.addEventListener('change', function() {
    const match = PLANS.find(p => this.value.startsWith(p.name));
    if (match) {
      const row = document.getElementById(ctx + '_item_' + id);
      row.querySelector('.price').value = match.price;
      row.querySelector('.unit').value  = match.unit;
      this.value = match.name;
      recalc(ctx);
    }
  });
}

function removeItem(ctx, id) {
  const el = document.getElementById(ctx + '_item_' + id);
  if (el) el.remove();
  recalc(ctx);
}

function getItems(ctx) {
  const rows = document.querySelectorAll('#' + ctx + 'ItemsList .sq-item-row');
  const items = [];
  rows.forEach(row => {
    const label = (row.querySelector('.label')?.value || '').trim();
    const qty   = parseInt(row.querySelector('.qty')?.value || '1') || 1;
    const price = parseFloat(row.querySelector('.price')?.value || '0') || 0;
    const unit  = (row.querySelector('.unit')?.value || 'amount').trim();
    if (label || price > 0) items.push({label, quantity: qty, price, unit});
  });
  return items;
}

function recalc(ctx) {
  const items = getItems(ctx);
  const total = items.reduce((s, i) => s + i.price * i.quantity, 0);
  const totalEl = document.getElementById(ctx + 'Total');
  const barEl   = document.getElementById(ctx + 'TotalBar');
  const jsonEl  = document.getElementById(ctx + 'ItemsJson');
  if (totalEl) totalEl.textContent = total.toFixed(2);
  if (barEl)   barEl.style.display = items.length ? '' : 'none';
  if (jsonEl)  jsonEl.value = JSON.stringify(items);
  // Enable lead submit
  if (ctx === 'lead') {
    const btn = document.getElementById('leadSubmitBtn');
    if (btn) btn.disabled = !items.length;
  }
}

function onLeadPick(sel) {
  const id  = parseInt(sel.value);
  const info = document.getElementById('leadPickedInfo');
  if (!id || !LEAD_DATA[id]) { info.style.display='none'; return; }
  const d = LEAD_DATA[id];
  document.getElementById('leadPhone').textContent   = d.phone;
  document.getElementById('leadService').textContent = d.service + (d.plan ? ' / ' + d.plan : '');
  const crmBadge = document.getElementById('leadCrmBadge');
  if (d.crm_client_id > 0) { crmBadge.style.display=''; } else { crmBadge.style.display='none'; }
  info.style.display = '';
  // Auto-populate items from plan name match
  const container = document.getElementById('leadItemsList');
  container.innerHTML = '';
  itemCounters.lead = 0;
  const match = PLANS.find(p => d.plan && (p.name.toLowerCase().includes(d.plan.toLowerCase()) || d.plan.toLowerCase().includes(p.name.toLowerCase())));
  if (match) {
    addItem('lead', match.name, match.price, 1, match.unit);
  } else if (d.plan) {
    addItem('lead', d.service + (d.plan ? ' — ' + d.plan : ''), 0, 1, 'month');
  } else {
    addItem('lead');
  }
}

function toggleCrmQuoteOption() {
  const val = parseInt(document.getElementById('manualCrmId')?.value || '0');
  const opt = document.getElementById('mq_crm_opt');
  if (opt) opt.style.display = val > 0 ? '' : 'none';
}

async function previewMsg(ctx) {
  const items = getItems(ctx);
  if (!items.length) { alert('Add at least one item first.'); return; }
  let custName = 'Customer';
  if (ctx === 'lead') {
    const sel = document.getElementById('leadPicker');
    custName = sel.options[sel.selectedIndex]?.text.split('(')[0].trim() || 'Customer';
  } else if (ctx === 'cash') {
    custName = document.querySelector('[name=cq_customer_name]')?.value || 'Customer';
  } else {
    custName = document.querySelector('[name=mq_customer_name]')?.value || 'Customer';
  }
  const note = document.querySelector('[name=mq_note]')?.value || '';
  const previewEl = document.getElementById('preview' + ctx.charAt(0).toUpperCase() + ctx.slice(1));
  previewEl.textContent = 'Loading preview…';
  previewEl.style.display = 'block';
  try {
    const resp = await fetch('?plugin=dishnet-hybrid-telecom&api=1', {
          credentials:'same-origin',
          method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'preview_quote_message', customer_name:custName, items, note, type: ctx === 'cash' ? 'Cash Sale Receipt' : 'Quotation'})
    });
    const data = await resp.json();
    previewEl.textContent = data.message || 'Error';
  } catch(e) {
    previewEl.textContent = 'Preview unavailable — check connection.';
  }
}

// Init: add one empty row on cash & manual tabs
(function() {
  <?php if ($subTab === 'cash'): ?> addItem('cash'); <?php endif; ?>
  <?php if ($subTab === 'manual'): ?> addItem('manual'); <?php endif; ?>
  // Auto-select lead if coming from leads tab Quick Quote button
  <?php
  $prefillLeadId = (int)($_GET['prefill_lead'] ?? 0);
  if ($prefillLeadId && $subTab === 'lead'): ?>
  const sel = document.getElementById('leadPicker');
  if (sel) {
    sel.value = '<?= $prefillLeadId ?>';
    if (sel.value) { onLeadPick(sel); }
  }
  <?php endif; ?>
})();
</script>
