<?php
// ── cnOpen stub — ensures function is defined even if script loads late ──
// Full implementation in the <script> block below.
?>
<script>
// ── Credit Note Modal — all functions defined here at top of file ──────────
function cnOpen() {
    document.getElementById('cnModal').style.display = 'block';
    document.getElementById('cnSuccess').style.display = 'none';
    document.getElementById('cnError').style.display = 'none';
    document.getElementById('cnSubmitBtn').style.display = 'block';
    document.getElementById('cnSubmitBtn').disabled = false;
    document.getElementById('cnSubmitBtn').textContent = 'Issue Credit Note';
    document.getElementById('cnClientId').value = '';
    document.getElementById('cnAmount').value = '';
    document.getElementById('cnReason').value = '';
    document.getElementById('cnCustomerSearch').value = '';
    document.getElementById('cnSelectedCustomer').style.display = 'none';
    document.getElementById('cnSearchResults').style.display = 'none';
    cnSetType('credit_note');
}

function cnSearchCustomer(q) {
    var box = document.getElementById('cnSearchResults');
    if (!q || q.length < 2) { box.style.display = 'none'; return; }
    q = q.toLowerCase();
    var idx = window.CRM_IDX || [];
    var matches = idx.filter(function(c) {
        return c.search && c.search.indexOf(q) !== -1;
    }).slice(0, 10);

    if (!matches.length) {
        box.innerHTML = '';
        box.style.display = 'none';
        return;
    }

    box.innerHTML = '';
    matches.forEach(function(c) {
        var div = document.createElement('div');
        div.style.cssText = 'padding:10px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;';
        div.innerHTML = '<strong>' + c.name + '</strong> <span style="color:#94a3b8;font-size:11px;">CRM #' + c.id + (c.bal ? ' · $' + c.bal : '') + '</span>';
        div.addEventListener('mousedown', function() { cnSelectCustomer(c.id, c.name); });
        div.addEventListener('mouseover', function() { this.style.background = '#f8fafc'; });
        div.addEventListener('mouseout',  function() { this.style.background = '#fff'; });
        box.appendChild(div);
    });
    box.style.display = 'block';
}

function cnSelectCustomer(id, name) {
    document.getElementById('cnClientId').value = id;
    document.getElementById('cnCustomerSearch').value = name;
    document.getElementById('cnCustomerName').textContent = name;
    document.getElementById('cnCustomerId').textContent = 'CRM #' + id;
    document.getElementById('cnSelectedCustomer').style.display = 'block';
    document.getElementById('cnSearchResults').style.display = 'none';
}

function cnSetType(type) {
    document.getElementById('cnRefundType').value = type;
    var cLabel    = document.getElementById('cnTypeCreditLabel');
    var cashLabel = document.getElementById('cnTypeCashLabel');
    var warn      = document.getElementById('cnCashWarning');
    if (type === 'cash_refund') {
        cLabel.style.borderColor    = '#e2e8f0'; cLabel.style.background    = '#f8fafc';
        cashLabel.style.borderColor = '#D41C1C'; cashLabel.style.background = '#fff5f5';
        warn.style.display = 'block';
    } else {
        cLabel.style.borderColor    = '#D41C1C'; cLabel.style.background    = '#fff5f5';
        cashLabel.style.borderColor = '#e2e8f0'; cashLabel.style.background = '#f8fafc';
        warn.style.display = 'none';
    }
}

function cnSubmit() {
    var clientId = document.getElementById('cnClientId').value;
    var amount   = parseFloat(document.getElementById('cnAmount').value);
    var reason   = document.getElementById('cnReason').value.trim();
    var type     = document.getElementById('cnRefundType').value;
    var errEl    = document.getElementById('cnError');

    errEl.style.display = 'none';
    if (!clientId) { errEl.textContent = 'Please select a customer.'; errEl.style.display = 'block'; return; }
    if (!amount || amount <= 0) { errEl.textContent = 'Enter a valid amount.'; errEl.style.display = 'block'; return; }
    if (!reason) { errEl.textContent = 'Please select or enter a reason.'; errEl.style.display = 'block'; return; }

    var btn = document.getElementById('cnSubmitBtn');
    btn.textContent = 'Issuing...';
    btn.disabled = true;

    var token = <?= json_encode($retailer['api_token'] ?? '') ?>;

    fetch('?page=api&action=issue_credit_note', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + token
        },
        body: JSON.stringify({
            client_id: parseInt(clientId),
            amount: amount,
            reason: reason,
            refund_type: type
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        btn.style.display = 'none';
        if (d.status === 'success') {
            var msg = 'Credit note #' + d.data.credit_note_num + ' ($' + d.data.amount.toFixed(2) + ') for ' + d.data.client_name;
            if (d.data.wa_sent) msg += ' · WA sent ✅';
            if (d.data.cashbook_ref) msg += ' · Cashbook: ' + d.data.cashbook_ref;
            document.getElementById('cnSuccessMsg').textContent = msg;
            document.getElementById('cnSuccess').style.display = 'block';
        } else {
            errEl.textContent = d.message || 'Failed to issue credit note.';
            errEl.style.display = 'block';
            btn.textContent = 'Issue Credit Note';
            btn.disabled = false;
            btn.style.display = 'block';
        }
    })
    .catch(function() {
        errEl.textContent = 'Network error — please try again.';
        errEl.style.display = 'block';
        btn.textContent = 'Issue Credit Note';
        btn.disabled = false;
        btn.style.display = 'block';
    });
}
</script>
<?php
        // ── Cash enforcement: block collection if agent holds too much cash ──
        $_cpCarryLimit = (float)(($retailer['carry_limit'] ?? null) ?: ($config['advance_carry_limit'] ?? null) ?: 100);
        $_cpExposure = 0;
        $_cpBlocked = false;
        if (!($isAdmin ?? false) && !in_array($userRole ?? '', ['accountant'])) {
            try {
                if (!class_exists('DualReadCashPosition')) require_once __DIR__ . '/../../lib/DualReadCashPosition.php';
                $_cpPos = (new DualReadCashPosition($store, $store->getPdo(), $dataDir ?? ''))->getPosition($retailerId);
                $_cpExposure = (float)($_cpPos['cash_exposure'] ?? 0);
                if ($_cpExposure > $_cpCarryLimit) $_cpBlocked = true;
            } catch (\Throwable $e) {}
        }

        if ($_cpBlocked): ?>
        <div style="max-width:480px;margin:20px auto;text-align:center;">
            <div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:20px;padding:30px 24px;">
                <div style="font-size:48px;margin-bottom:12px;">⛔</div>
                <div style="font-size:18px;font-weight:800;color:#991b1b;margin-bottom:8px;">Collection Blocked</div>
                <div style="font-size:14px;color:#b91c1c;line-height:1.6;margin-bottom:16px;">
                    You are holding <strong>$<?= number_format($_cpExposure, 2) ?></strong> in company cash.<br>
                    The carry limit is <strong>$<?= number_format($_cpCarryLimit, 2) ?></strong>.<br><br>
                    Please hand over cash to the office before collecting more payments.
                </div>
                <a href="?page=dashboard&tab=my_account&v=handover" style="display:inline-block;background:#dc2626;color:#fff;padding:14px 28px;border-radius:12px;font-size:14px;font-weight:800;text-decoration:none;box-shadow:0 4px 14px rgba(220,38,38,.3);">🏦 Hand Over Cash Now</a>
                <div style="margin-top:14px;">
                    <a href="?page=dashboard&tab=my_account" style="font-size:13px;color:#6b7280;text-decoration:none;">View My Cash Position →</a>
                </div>
            </div>
        </div>
        <?php return; endif; ?>
<?php
        $myCollectionsAll = array_reverse(array_filter($store->load('payment_collections.json'), fn($c2) => (int)($c2['retailer_id']??0) === $retailerId));
        $colMyFrom = $_GET['mc_from'] ?? '';
        $colMyTo   = $_GET['mc_to']   ?? '';
        $colMyQ    = trim($_GET['mc_q'] ?? '');
        $myCollections = ($colMyFrom || $colMyTo || $colMyQ)
            ? array_values(array_filter($myCollectionsAll, function($c2) use ($colMyFrom,$colMyTo,$colMyQ) {
                $d = substr($c2['created_at']??'',0,10);
                if ($colMyFrom && $d < $colMyFrom) return false;
                if ($colMyTo   && $d > $colMyTo)   return false;
                if ($colMyQ && stripos(($c2['customer_name']??'').($c2['crm_customer_id']??''), $colMyQ)===false) return false;
                return true;
              }))
            : array_values($myCollectionsAll);
        $todayCols = array_filter($myCollectionsAll, fn($c2) => strpos($c2['created_at']??'', date('Y-m-d')) === 0);
        $todayTotal = array_sum(array_map(fn($c2) => $c2['amount'] ?? 0, $todayCols));
        $monthCols = array_filter($myCollectionsAll, fn($c2) => strpos($c2['created_at']??'', date('Y-m')) === 0);
        $monthTotal = array_sum(array_map(fn($c2) => $c2['amount'] ?? 0, $monthCols));
        $monthComm = array_sum(array_map(fn($c2) => $c2['commission'] ?? 0, $monthCols));
        $monthTarget = (float)($config['retailer_targets'][$retailerId] ?? ($config['retailer_targets']['default'] ?? 0));
        $targetPct = $monthTarget > 0 ? min(100, round($monthTotal / $monthTarget * 100)) : 0;
        $commRate = $config['commission_rate'] ?? 5;
    ?>

<style>
/* ══ Collection Page — Airtel Tribe Inspired ══ */
.cp-hero{background:linear-gradient(145deg,#D41C1C,#A81515);border-radius:20px;padding:20px 18px;color:#fff;position:relative;overflow:hidden;margin-bottom:14px;}
.cp-hero::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.06);}
.cp-hero-bal{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.5);font-weight:700;}
.cp-hero-amt{font-size:34px;font-weight:800;letter-spacing:-1px;margin:2px 0 12px;}
.cp-hero-row{display:flex;gap:8px;}
.cp-hero-pill{background:rgba(255,255,255,.12);border-radius:10px;padding:8px 10px;flex:1;text-align:center;}
.cp-hero-pill-label{font-size:8px;text-transform:uppercase;letter-spacing:.5px;color:rgba(255,255,255,.45);font-weight:700;}
.cp-hero-pill-val{font-size:15px;font-weight:800;margin-top:1px;}
.cp-target{margin-top:12px;}
.cp-target-bar{background:rgba(255,255,255,.15);border-radius:6px;height:6px;overflow:hidden;}
.cp-target-fill{height:100%;border-radius:6px;transition:width .5s;}
.cp-target-text{font-size:9px;color:rgba(255,255,255,.45);margin-top:3px;display:flex;justify-content:space-between;}

/* Step tabs */
.cp-tabs{display:flex;background:#fff;border-radius:14px;padding:4px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:14px;border:1px solid #f1f5f9;}
.cp-tab{flex:1;padding:10px;text-align:center;font-size:12px;font-weight:700;color:#94a3b8;border-radius:10px;cursor:pointer;transition:.2s;}
.cp-tab.active{background:#D41C1C;color:#fff;box-shadow:0 2px 8px rgba(212,28,28,.25);}

/* Search */
.cp-search{background:#fff;border-radius:16px;padding:16px;box-shadow:0 2px 10px rgba(0,0,0,.04);margin-bottom:14px;border:1px solid #f1f5f9;}
.crm-sr-item{display:flex;align-items:center;padding:11px 16px;cursor:pointer;border-bottom:1px solid #F1F5F9;transition:background .1s;}
.crm-sr-item:last-child{border-bottom:none;}
.crm-sr-item:hover,.crm-sr-item:focus{background:#EFF6FF;}
.cp-search-bar{display:flex;gap:8px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:14px;padding:4px;transition:.2s;}
.cp-search-bar:focus-within{border-color:#D41C1C;box-shadow:0 0 0 3px rgba(33,150,243,.08);}
.cp-search-bar input{flex:1;border:none;background:transparent;padding:10px 12px;font-size:16px;outline:none;}
.cp-search-bar button{background:#D41C1C;color:#fff;border:none;border-radius:10px;padding:10px 16px;font-weight:700;cursor:pointer;flex-shrink:0;}

/* Customer selected card */
.cp-cust-card{background:#fff5f5;border:2px solid #D41C1C;border-radius:14px;padding:14px 16px;margin-bottom:14px;display:none;}
.cp-cust-card.show{display:block;}
.cp-cust-name{font-size:16px;font-weight:800;color:#0D47A1;}
.cp-cust-meta{font-size:12px;color:#6b7280;margin-top:3px;}
.cp-cust-balance{font-size:13px;font-weight:700;margin-top:6px;}

/* Invoice cards */
.cp-inv-section{display:none;margin-bottom:14px;}
.cp-inv-section.show{display:block;}
.cp-inv-title{font-size:12px;font-weight:800;color:#1e293b;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;}
.cp-inv-card{background:#fff;border-radius:12px;padding:14px 16px;margin-bottom:8px;border:1.5px solid #e2e8f0;cursor:pointer;transition:.15s;display:flex;align-items:center;gap:12px;}
.cp-inv-card:active{transform:scale(.98);}
.cp-inv-card.selected{border-color:#D41C1C;background:#f0f9ff;box-shadow:0 0 0 3px rgba(33,150,243,.1);}
.cp-inv-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.cp-inv-info{flex:1;min-width:0;}
.cp-inv-num{font-size:13px;font-weight:700;color:#1e293b;}
.cp-inv-detail{font-size:11px;color:#6b7280;margin-top:2px;}
.cp-inv-right{text-align:right;flex-shrink:0;}
.cp-inv-amt{font-size:16px;font-weight:800;color:#dc3545;}
.cp-inv-status{font-size:9px;font-weight:700;margin-top:2px;}

/* Quick amounts */
.cp-amounts{display:flex;gap:8px;overflow-x:auto;padding:4px 0 10px;-webkit-overflow-scrolling:touch;margin-bottom:4px;scroll-snap-type:x mandatory;scroll-padding:0 8px;}
.cp-amounts::-webkit-scrollbar{display:none;}
.cp-amt-btn{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:10px 18px;font-size:14px;font-weight:700;color:#1e293b;cursor:pointer;flex-shrink:0;transition:.15s;scroll-snap-align:start;}
.cp-amt-btn:active{background:#D41C1C;color:#fff;border-color:#D41C1C;transform:scale(.95);}

/* Payment form */
.cp-form{background:#fff;border-radius:16px;padding:18px;box-shadow:0 2px 10px rgba(0,0,0,.04);margin-bottom:14px;border:1px solid #f1f5f9;}
.cp-field{margin-bottom:14px;}
.cp-field label{display:block;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.cp-field input,.cp-field select{display:block;width:100%;padding:12px 14px;font-size:16px;border:1.5px solid #e2e8f0;border-radius:12px;background:#f8fafc;box-sizing:border-box;transition:.2s;}
.cp-field input:focus,.cp-field select:focus{border-color:#D41C1C;background:#fff;box-shadow:0 0 0 3px rgba(33,150,243,.08);outline:none;}
.cp-amount-input{font-size:32px!important;font-weight:800;text-align:center;letter-spacing:1px;color:#1e293b;}
.cp-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.cp-submit{display:block;width:100%;padding:16px;background:#28a745;color:#fff;border:none;border-radius:14px;font-size:16px;font-weight:800;cursor:pointer;box-shadow:0 4px 16px rgba(40,167,69,.25);transition:.15s;}
.cp-submit:active{transform:scale(.98);}
.cp-info{background:#FFF8E1;border-radius:12px;padding:10px 14px;font-size:12px;color:#F57F17;display:flex;gap:8px;align-items:start;margin-top:12px;}

/* Collection history cards */
.cp-hist-title{font-size:13px;font-weight:800;color:#1e293b;margin:16px 0 10px;display:flex;justify-content:space-between;}
.cp-col-card{background:#fff;border-radius:14px;padding:14px 16px;margin-bottom:8px;box-shadow:0 1px 6px rgba(0,0,0,.04);border:1px solid #f1f5f9;display:flex;align-items:center;gap:12px;}
.cp-col-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.cp-col-info{flex:1;min-width:0;}
.cp-col-name{font-size:14px;font-weight:700;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.cp-col-meta{font-size:11px;color:#9ca3af;margin-top:2px;}
.cp-col-right{text-align:right;flex-shrink:0;}
.cp-col-amt{font-size:16px;font-weight:800;color:#28a745;}
.cp-col-status{font-size:10px;font-weight:700;margin-top:3px;}
.cp-col-receipt{display:block;font-size:10px;color:#D41C1C;text-decoration:none;font-weight:700;margin-top:3px;}
.cp-empty{text-align:center;padding:40px 20px;color:#9ca3af;}
.cp-empty i{font-size:48px;display:block;margin-bottom:10px;color:#d1d5db;}

/* Animations */
@keyframes cpSlide{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:none;}}
.cp-animate{animation:cpSlide .3s ease;}
.spin{animation:spin 1s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}

@media(min-width:769px){
.cp-hero-amt{font-size:38px;}
.cp-col-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
.cp-inv-card:hover{border-color:#90CAF9;}
}
</style>

<!-- Hero Stats -->
<div class="cp-hero">
    <div class="cp-hero-bal">Wallet Balance</div>
    <div class="cp-hero-amt">$<?= number_format($myWallet['balance'], 2) ?></div>
    <div class="cp-hero-row">
        <div class="cp-hero-pill">
            <div class="cp-hero-pill-label">Today</div>
            <div class="cp-hero-pill-val">$<?= number_format($todayTotal, 2) ?></div>
        </div>
        <div class="cp-hero-pill">
            <div class="cp-hero-pill-label">Month</div>
            <div class="cp-hero-pill-val">$<?= number_format($monthTotal, 2) ?></div>
        </div>
        <?php if (empty($retailer['is_employee'])): ?>
        <div class="cp-hero-pill">
            <div class="cp-hero-pill-label">Earned</div>
            <div class="cp-hero-pill-val" style="color:#69f0ae;">+$<?= number_format($monthComm, 2) ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($monthTarget > 0): ?>
    <div class="cp-target">
        <div class="cp-target-bar"><div class="cp-target-fill" style="width:<?= $targetPct ?>%;background:<?= $targetPct>=100?'#69f0ae':'#64B5F6' ?>;"></div></div>
        <div class="cp-target-text"><span><?= $targetPct ?>% target</span><span>$<?= number_format($monthTotal,0) ?> / $<?= number_format($monthTarget,0) ?></span></div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     CREDIT NOTE / REFUND MODAL
     ═══════════════════════════════════════════════════════════════ -->
<div id="cnModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);padding:16px;overflow-y:auto;">
  <div style="background:#fff;border-radius:20px;padding:20px;max-width:480px;margin:20px auto;position:relative;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
      <div style="font-size:16px;font-weight:800;color:#1e293b;">💳 Issue Credit / Refund</div>
      <button onclick="document.getElementById('cnModal').style.display='none'"
              style="background:#f1f5f9;border:none;border-radius:10px;padding:8px 12px;cursor:pointer;font-size:16px;">✕</button>
    </div>

    <!-- Customer search -->
    <div style="margin-bottom:12px;">
      <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px;">Customer</label>
      <input id="cnCustomerSearch" type="text" placeholder="Search name, ID, phone..."
             style="width:100%;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;font-size:15px;box-sizing:border-box;"
             oninput="cnSearchCustomer(this.value)" autocomplete="off"/>
      <div id="cnSearchResults" style="display:none;background:#fff;border:1px solid #e2e8f0;border-radius:12px;margin-top:4px;max-height:200px;overflow-y:auto;box-shadow:0 4px 20px rgba(0,0,0,.1);"></div>
      <div id="cnSelectedCustomer" style="display:none;background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:12px;margin-top:8px;">
        <div id="cnCustomerName" style="font-weight:700;color:#15803d;font-size:14px;"></div>
        <div id="cnCustomerId" style="font-size:11px;color:#6b7280;margin-top:2px;"></div>
      </div>
      <input type="hidden" id="cnClientId" value="">
    </div>

    <!-- Amount -->
    <div style="margin-bottom:12px;">
      <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px;">Credit Amount (USD)</label>
      <input id="cnAmount" type="number" step="0.01" min="0.01" placeholder="0.00"
             style="width:100%;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;font-size:18px;font-weight:700;box-sizing:border-box;"/>
    </div>

    <!-- Reason -->
    <div style="margin-bottom:16px;">
      <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px;">Reason</label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
        <?php foreach (['Overpayment','Service issue','Duplicate payment','Plan change adjustment','Goodwill credit','Other'] as $_cnR): ?>
        <button type="button" onclick="document.getElementById('cnReason').value='<?= $_cnR ?>'; document.querySelectorAll('.cn-reason-btn').forEach(b=>b.style.background='#f8fafc'); this.style.background='#dbeafe';"
                class="cn-reason-btn"
                style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px;font-size:12px;font-weight:600;cursor:pointer;text-align:left;">
          <?= $_cnR ?>
        </button>
        <?php endforeach; ?>
      </div>
      <input id="cnReason" type="text" placeholder="Or type custom reason..."
             style="width:100%;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;font-size:14px;box-sizing:border-box;"/>
    </div>

    <!-- Refund type toggle -->
    <div style="margin-bottom:14px;">
      <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:8px;">Type</label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
        <label id="cnTypeCreditLabel" onclick="cnSetType('credit_note')"
               style="border:2px solid #D41C1C;border-radius:12px;padding:12px;cursor:pointer;text-align:center;background:#fff5f5;">
          <input type="radio" name="cnType" value="credit_note" checked style="display:none;">
          <div style="font-size:18px;">💳</div>
          <div style="font-size:12px;font-weight:700;color:#D41C1C;margin-top:2px;">Credit Note Only</div>
          <div style="font-size:10px;color:#6b7280;margin-top:2px;">Applied to next invoice — no cash given</div>
        </label>
        <label id="cnTypeCashLabel" onclick="cnSetType('cash_refund')"
               style="border:2px solid #e2e8f0;border-radius:12px;padding:12px;cursor:pointer;text-align:center;background:#f8fafc;">
          <input type="radio" name="cnType" value="cash_refund" style="display:none;">
          <div style="font-size:18px;">💵</div>
          <div style="font-size:12px;font-weight:700;color:#475569;margin-top:2px;">Cash Refund</div>
          <div style="font-size:10px;color:#6b7280;margin-top:2px;">Physical cash returned + credit note</div>
        </label>
      </div>
      <div id="cnCashWarning" style="display:none;background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px;margin-top:8px;font-size:12px;color:#92400e;">
        ⚠️ Cash Refund will deduct from your physical cash in hand. Make sure you have enough.
      </div>
    </div>
    <input type="hidden" id="cnRefundType" value="credit_note">

    <!-- Submit -->
    <button id="cnSubmitBtn" onclick="cnSubmit()"
            style="width:100%;background:#D41C1C;color:#fff;border:none;border-radius:14px;padding:16px;font-size:16px;font-weight:800;cursor:pointer;">
      Issue Credit Note
    </button>
    <div id="cnError" style="display:none;color:#dc2626;font-size:13px;margin-top:8px;text-align:center;"></div>
    <div id="cnSuccess" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:12px;padding:14px;margin-top:10px;text-align:center;">
      <div style="font-size:22px;">✅</div>
      <div id="cnSuccessMsg" style="font-weight:700;color:#15803d;margin-top:4px;font-size:14px;"></div>
    </div>
  </div>
</div>


<!-- ── Credit note quick-action button ─────────────────────────────────── -->
<div style="margin-bottom:14px;">
    <button onclick="if(typeof cnOpen==='function'){cnOpen();}else{document.getElementById('cnModal')&&(document.getElementById('cnModal').style.display='block');}"
            style="width:100%;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:13px 16px;display:flex;align-items:center;gap:10px;cursor:pointer;text-align:left;">
        <span style="font-size:22px;">💳</span>
        <div style="flex:1;">
            <div style="font-size:13px;font-weight:700;color:#1e293b;">Issue Credit / Refund</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:1px;">Apply credit note to customer account in CRM</div>
        </div>
        <span style="color:#94a3b8;font-size:18px;">›</span>
    </button>
</div>

<!-- ── Compact client index — loaded from pre-built search index ──────── -->
<?php
$_compactIdx  = [];
// Primary: pre-built compact index (id/name/phone/bal only — ~10x smaller)
$_searchIndex = $store->load('client_search_index.json') ?? [];
if (count($_searchIndex)) {
    $_compactIdx = $_searchIndex; // already in correct shape
} else {
    // Fallback: build on-the-fly from full cache (before first cron run)
    $_ucrmCache = $store->load('ucrm_clients_cache.json') ?? [];
    foreach ($_ucrmCache as $_c) {
        $_cid = (int)($_c['id'] ?? 0); if (!$_cid) continue;
        $_fn  = trim($_c['firstName'] ?? ''); $_ln = trim($_c['lastName'] ?? '');
        $_nm  = trim("$_fn $_ln"); if (!$_nm) $_nm = trim($_c['companyName'] ?? $_c['username'] ?? '');
        $_ph  = ''; foreach ($_c['contacts'] ?? [] as $_ct) { if (!$_ph && !empty($_ct['phone'])) { $_ph = $_ct['phone']; break; } }
        if (!$_ph) $_ph = trim($_c['phone'] ?? '');
        $_compactIdx[] = ['id'=>$_cid,'name'=>$_nm,'phone'=>$_ph,'bal'=>(float)($_c['accountBalance']??0),'search'=>strtolower("$_nm $_ph $_cid")];
    }
}
$_indexAge = $store->load('client_delta_sync_meta.json')['last_run']
          ?? $store->load('ucrm_pull_last_run.json')['completed_at']
          ?? $store->load('ucrm_pull_last_run.json')['started_at']
          ?? 'never';

// ── Merge LTE subscribers into search index (plugin-only, not in UCRM) ──
$_lteFile = $dataDir . '/lte_subscribers.json';
if (file_exists($_lteFile)) {
    $_lteSubs = json_decode(file_get_contents($_lteFile), true) ?: [];
    foreach ($_lteSubs as $_ls) {
        $_lName = trim($_ls['name'] ?? '');
        $_lPhone = trim($_ls['phone'] ?? '');
        if (!$_lName) continue;
        $_lId = 'LTE-' . (int)($_ls['id'] ?? 0);
        $_compactIdx[] = [
            'id'     => $_lId,
            'name'   => $_lName,
            'phone'  => $_lPhone,
            'bal'    => 0,
            'plans'  => 'LTE ' . trim($_ls['package_name'] ?? ''),
            '_src'   => 'lte',
            'search' => strtolower("$_lName $_lPhone $_lId lte"),
        ];
    }
}
?>
<script>
window.CRM_IDX = <?= json_encode($_compactIdx, JSON_HEX_TAG|JSON_HEX_AMP) ?>;
window._indexAge = <?= json_encode($_indexAge) ?>;
window._indexSize = <?= count($_compactIdx) ?>;

// Background sync — silently refresh search index every page load (throttled server-side to 90s)
(function bgSync() {
    var token = window._apiToken || '';
    fetch('?page=api&action=bg_client_sync', {
        headers: token ? {'Authorization': 'Bearer ' + token} : {}
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d && d.data && d.data.synced && d.data.total) {
            // Hot-reload index from server without page refresh
            fetch('?page=api&action=client_search_index', {
                headers: token ? {'Authorization': 'Bearer ' + token} : {}
            })
            .then(function(r){ return r.json(); })
            .then(function(rd){
                if (rd && Array.isArray(rd.data) && rd.data.length) {
                    window.CRM_IDX = rd.data;
                    window._indexSize = rd.data.length;
                    var ind = document.getElementById('cpIndexInfo');
                    if (ind) ind.textContent = '✓ ' + rd.data.length + ' clients (just refreshed)';
                }
            }).catch(function(){});
        }
    }).catch(function(){}); // silent — never block UI
})();
</script>

<!-- Tab Switcher -->
<div class="cp-tabs">
    <div class="cp-tab active" onclick="cpSwitchTab('collect',this)">&#128181; Collect</div>
    <div class="cp-tab" onclick="cpSwitchTab('history',this)">&#128340; History</div>
</div>

<!-- ═══ COLLECT TAB ═══ -->
<div id="cpCollectView">

<!-- Step 1: Search Customer -->
<div class="cp-search" style="position:relative;">
    <div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:10px;">
        <span style="background:#fff5f5;color:#D41C1C;padding:2px 8px;border-radius:8px;font-size:11px;margin-right:6px;">Step 1</span>
        Find Customer
    </div>
    <div class="cp-search-bar" style="position:relative;">
        <input type="text" id="crmSearchInput"
               placeholder="Type name, phone, or CRM ID..."
               oninput="crmInstantSearch(this.value)"
               onkeydown="crmSearchKeyNav(event)"
               autocomplete="off" autocorrect="off" spellcheck="false"
               style="padding-right:36px;">
        <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#94A3B8;font-size:14px;pointer-events:none;">🔍</span>
    </div>
    <div id="cpIndexInfo" style="font-size:10px;color:#94a3b8;margin-top:4px;padding-left:2px;">
        <?= count($_compactIdx) ?> clients · synced <?= h($_indexAge) ?>
    </div>
    <div id="crmSearchResults"
         style="position:absolute;z-index:9999;left:0;right:0;margin-top:4px;
                background:#fff;border:1.5px solid #D41C1C;border-radius:12px;
                box-shadow:0 8px 32px rgba(0,0,0,0.18);overflow:hidden;
                display:none;max-height:260px;overflow-y:auto;"></div>
</div>

<!-- Customer Selected Card -->
<div id="cpCustCard" class="cp-cust-card">
    <div style="display:flex;justify-content:space-between;align-items:start;">
        <div>
            <div class="cp-cust-name" id="cpCustDisplay"></div>
            <div class="cp-cust-meta" id="cpCustIdDisplay"></div>
        </div>
        <button onclick="cpClearCustomer()" style="background:#fef2f2;border:none;border-radius:8px;padding:6px 10px;font-size:11px;font-weight:700;color:#dc3545;cursor:pointer;">Change</button>
    </div>
    <div class="cp-cust-balance" id="cpCustBalDisplay"></div>
</div>

<!-- Step 2: Open Invoices -->
<div id="cpInvoiceSection" class="cp-inv-section">
    <div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
        <div>
            <span style="background:#FFF3E0;color:#E65100;padding:2px 8px;border-radius:8px;font-size:11px;margin-right:6px;">Step 2</span>
            Select Invoice <span style="font-weight:400;color:#6b7280;font-size:11px;">(or enter custom amount)</span>
        </div>
        <span id="cpInvCacheHint" style="display:none;font-size:10px;color:#94a3b8;">
            cached · <a href="#" onclick="cpRefreshInvoices();return false;" style="color:#3b82f6;text-decoration:none;">🔄 Refresh</a>
        </span>
    </div>
    <div id="cpInvoiceList"></div>
    <div id="cpInvoiceLoading" style="display:none;text-align:center;padding:16px;color:#6b7280;font-size:12px;"><i class="bi bi-arrow-repeat spin"></i> Loading invoices...</div>
    <div id="cpNoInvoices" style="display:none;padding:12px;background:#E8F5E9;border-radius:10px;font-size:12px;color:#2E7D32;text-align:center;">
        <i class="bi bi-check-circle"></i> No unpaid invoices — enter custom amount below
    </div>
</div>

<!-- Step 3: Amount & Pay -->
<div class="cp-form">
    <div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:12px;">
        <span style="background:#E8F5E9;color:#2E7D32;padding:2px 8px;border-radius:8px;font-size:11px;margin-right:6px;">Step 3</span>
        Confirm & Pay
    </div>

    <form method="POST" id="collectForm">
    <?= csrfField() ?>
        <input type="hidden" name="action" value="collect_payment">
        <input type="hidden" name="crm_customer_id" id="cpCustId" value="">
        <input type="hidden" name="customer_name" id="cpCustName" value="">
        <input type="hidden" name="invoice_id" id="cpInvoiceId" value="">
        <input type="hidden" name="invoice_label" id="cpInvoiceLabel" value="">

        <div class="cp-field">
            <label>Amount</label>
            <input type="number" name="amount" id="cpAmount" class="cp-amount-input" step="0.01" min="1" required placeholder="0.00">
            <div style="text-align:center;font-size:11px;color:#6b7280;margin-top:4px;">
                <?php if (empty($retailer['is_employee'])): ?>
                You earn <?= $commRate ?>% = <strong id="cpCommPreview">$0.00</strong> commission
                <?php else: ?>
                <span id="cpCommPreview" style="display:none;">$0.00</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Currency selector — shown when SSP selected, quick amounts update -->
        <div class="cp-row" style="margin-bottom:0;">
          <div class="cp-field" style="flex:1;">
            <label>Currency</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
              <button type="button" id="cpCurUSD" onclick="cpSetCurrency('USD')"
                style="padding:10px;border-radius:10px;border:2px solid #D41C1C;background:#D41C1C;color:#fff;font-size:13px;font-weight:800;cursor:pointer;">
                💵 USD
              </button>
              <button type="button" id="cpCurSSP" onclick="cpSetCurrency('SSP')"
                style="padding:10px;border-radius:10px;border:2px solid #e2e8f0;background:#fff;color:#64748b;font-size:13px;font-weight:800;cursor:pointer;">
                🇸🇸 SSP
              </button>
            </div>
          </div>
          <input type="hidden" name="currency" id="cpCurrency" value="USD">
        </div>

        <!-- Quick Amounts -->
        <div class="cp-amounts">
            <?php foreach ([25, 40, 50, 65, 75, 80, 100, 110, 120, 200, 250, 300, 500, 875] as $qa): ?>
            <button type="button" class="cp-amt-btn" onclick="cpSetAmount(<?= $qa ?>)">$<?= $qa ?></button>
            <?php endforeach; ?>
        </div>

        <div class="cp-row">
            <div class="cp-field">
                <label>Service Type</label>
                <select name="service_type" id="cpServiceType">
                    <?php
                    $_myProjects = $retailer['projects'] ?? (!empty($retailer['project']) ? [$retailer['project']] : ['dishnet']);
                    if (!is_array($_myProjects)) $_myProjects = [$_myProjects];
                    $isAdminOrAcct = ($isAdmin ?? false) || in_array($userRole ?? '', ['accountant']);
                    // Map: project → service types
                    $_svcOptions = [];
                    if ($isAdminOrAcct || in_array('dishnet', $_myProjects)) {
                        $_svcOptions[] = ['starlink', '📡 Starlink'];
                        $_svcOptions[] = ['fiber', '🔌 Fiber'];
                    }
                    if ($isAdminOrAcct || in_array('4g', $_myProjects)) {
                        $_svcOptions[] = ['lte', '📶 LTE (4G)'];
                    }
                    if ($isAdminOrAcct || in_array('bluecard', $_myProjects)) {
                        $_svcOptions[] = ['bluecard', '🔵 BlueCARD (UNMISS)'];
                    }
                    if (empty($_svcOptions)) $_svcOptions[] = ['starlink', '📡 Starlink'];
                    foreach ($_svcOptions as $_so):
                    ?>
                    <option value="<?= $_so[0] ?>"><?= $_so[1] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cp-field">
                <label>Method</label>
                <select name="payment_method">
                    <option value="Cash">&#128181; Cash</option>
                </select>
            </div>
        </div>

        <!-- Receipt & Location Row -->
        <div class="cp-row">
            <div class="cp-field">
                <label>Receipt # <span style="color:#9ca3af;font-weight:400;">(manual book)</span></label>
                <input type="text" name="receipt_number" id="cpReceiptNo" placeholder="e.g. 3113" 
                    style="font-weight:700;letter-spacing:1px;">
            </div>
            <div class="cp-field">
                <label>Location</label>
                <select name="collection_location" id="cpLocation">
                    <option value="">-- Select --</option>
                    <option value="Tomping Office">Tomping Office</option>
                    <option value="Customer Site">Customer Site</option>
                    <option value="Field Visit">Field Visit</option>
                    <option value="Bank Deposit">Bank Deposit</option>
                    <option value="Other">Other</option>
                </select>
            </div>
        </div>

        <!-- SSP Amount (when paying in SSP) -->
        <div class="cp-field" id="cpSspRow" style="display:none;">
            <label>Amount Received in SSP <span style="color:#9ca3af;font-weight:400;">(actual cash)</span></label>
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="number" name="ssp_amount" id="cpSspAmount" placeholder="e.g. 150000" 
                    style="flex:1;font-weight:700;">
                <span style="color:#64748b;font-size:12px;font-weight:600;">SSP</span>
            </div>
            <div style="font-size:11px;color:#9ca3af;margin-top:4px;">
                ℹ️ Enter actual SSP received. USD amount above is the value posted to CRM.
            </div>
        </div>

        <!-- Invoice Instructions -->
        <div class="cp-field">
            <label>Invoice / Breakdown</label>
            <textarea name="invoice_note" id="cpInvoiceNote" rows="2" 
                placeholder="e.g. Invoice to create: 250 (150 installation + 100 subscription)"
                style="font-size:13px;resize:vertical;"></textarea>
        </div>

        <!-- Additional Note -->
        <div class="cp-field">
            <label>Additional Note <span style="color:#9ca3af;font-weight:400;">(commission, etc.)</span></label>
            <input type="text" name="payment_note" id="cpNote" placeholder="e.g. 50 commission to Laku Michael">
        </div>

        <button type="submit" class="cp-submit" onclick="return cpConfirmPay();">
            <i class="bi bi-cash-coin"></i> Collect Payment
        </button>

        <div class="cp-info">
            <i class="bi bi-info-circle" style="flex-shrink:0;margin-top:1px;"></i>
            <span>Debited from your wallet → posted to CRM as customer payment. Collect cash first!</span>
        </div>
    </form>
</div>

</div><!-- /cpCollectView -->

<!-- ═══ HISTORY TAB ═══ -->
<div id="cpHistoryView" style="display:none;">
<div class="cp-hist-title">
    <span>Collections (<?= count($myCollections) ?><?= ($colMyFrom||$colMyTo||$colMyQ) ? ' filtered' : '' ?>)</span>
    <span style="font-size:11px;color:#9ca3af;font-weight:600;">Today: <?= count($todayCols) ?></span>
</div>
<!-- Date filter bar -->
<form method="GET" style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 10px;">
  <input type="hidden" name="page" value="dashboard">
  <input type="hidden" name="tab" value="collect_payment">
  <input type="hidden" name="cp_view" value="history">
  <label style="flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;">
    <span style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;padding-left:2px;">From</span>
    <input type="date" name="mc_from" value="<?= h($colMyFrom) ?>"
      style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:16px!important;width:100%;box-sizing:border-box;" onchange="this.form.submit()">
  </label>
  <label style="flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;">
    <span style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;padding-left:2px;">To</span>
    <input type="date" name="mc_to" value="<?= h($colMyTo) ?>"
      style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:16px!important;width:100%;box-sizing:border-box;" onchange="this.form.submit()">
  </label>
  <input type="text" name="mc_q" value="<?= h($colMyQ) ?>" placeholder="Customer..."
    style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:16px!important;flex:1;min-width:0;">
  <button type="submit" style="padding:7px 14px;background:#D41C1C;color:#fff;border:none;border-radius:8px;font-size:14px;cursor:pointer;">🔍</button>
  <?php if ($colMyFrom||$colMyTo||$colMyQ): ?>
  <a href="?page=dashboard&tab=collect_payment&cp_view=history"
     style="padding:7px 12px;background:#FEE2E2;color:#dc2626;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;">✕ Clear</a>
  <?php endif; ?>
</form>
<?php
// Build retry lookup so we can show failed CRM syncs
$_retryQueue = $store->load('crm_payment_retry.json') ?? [];
$_failedCols  = []; // collection_id => error
$_pendingCols = []; // collection_id => attempts
foreach ($_retryQueue as $_rq) {
    $cid = (int)($_rq['collection_id'] ?? 0);
    if (!$cid) continue;
    if (($_rq['status']??'') === 'failed')  $_failedCols[$cid]  = $_rq['last_error'] ?? 'Unknown';
    if (($_rq['status']??'') === 'pending') $_pendingCols[$cid] = (int)($_rq['attempts'] ?? 1);
}
?>
<?php if (!empty($myCollections)): ?>
<?php foreach (array_slice($myCollections, 0, 50) as $col):
    $isToday = strpos($col['created_at']??'', date('Y-m-d')) === 0;
    $method = $col['method'] ?? 'Cash';
    $methodIcon = $method === 'Cash' ? '&#128181;' : ($method === 'Mobile Money' ? '&#128241;' : '&#127974;');
    $methodColor = $method === 'Cash' ? '#E8F5E9' : ($method === 'Mobile Money' ? '#E3F2FD' : '#FFF3E0');
    $hasInv = !empty($col['invoice_id']);
    $colId  = (int)($col['id'] ?? 0);
    $crmFailed  = isset($_failedCols[$colId]);
    $crmRetrying= isset($_pendingCols[$colId]);
    if (!empty($col['crm_synced'])) {
        $crmStatusHtml = '<span style="color:#28a745;">&#9989; CRM Synced</span>';
    } elseif ($crmFailed) {
        $errSnip = htmlspecialchars(substr($_failedCols[$colId], 0, 60));
        $crmStatusHtml = '<span style="color:#dc2626;" title="'.$errSnip.'">&#10060; CRM Failed</span>';
    } elseif ($crmRetrying) {
        $att = $_pendingCols[$colId];
        $crmStatusHtml = '<span style="color:#E65100;">&#9203; Retrying ('.$att.'/5)</span>';
    } elseif (empty($col['crm_customer_id'])) {
        $crmStatusHtml = '<span style="color:#9ca3af;" title="No CRM customer was selected when payment was recorded">&#8212; No CRM ID</span>';
    } else {
        $crmStatusHtml = '<span style="color:#E65100;">&#9203; CRM Pending</span>';
    }
?>
<div class="cp-col-card" <?= $crmFailed ? 'style="border-left:3px solid #dc2626;"' : '' ?>>
    <div class="cp-col-icon" style="background:<?= $methodColor ?>;"><?= $methodIcon ?></div>
    <div class="cp-col-info">
        <div class="cp-col-name"><?= h($col['customer_name']??'') ?></div>
        <div class="cp-col-meta">
            <?= $method ?>
            <?php if (!empty($col['receipt_number'])): ?> · <b>#<?= h($col['receipt_number']) ?></b><?php endif; ?>
            <?php if (!empty($col['location'])): ?> · <?= h($col['location']) ?><?php endif; ?>
            <?php if ($hasInv): ?> · Inv #<?= h($col['invoice_id']) ?><?php endif; ?>
            · <?= h(substr($col['created_at']??'', 0, $isToday ? 16 : 10)) ?>
            <?php if (($col['commission']??0) > 0 && empty($retailer['is_employee'])): ?> · <span style="color:#28a745;">+$<?= number_format($col['commission'],2) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($col['invoice_note'])): ?>
        <div style="font-size:10px;color:#6b7280;margin-top:2px;">📝 <?= h($col['invoice_note']) ?></div>
        <?php endif; ?>
        <?php if (!empty($col['ssp_amount'])): ?>
        <div style="font-size:10px;color:#1A237E;margin-top:2px;">🇸🇸 <?= number_format($col['ssp_amount']) ?> SSP received</div>
        <?php endif; ?>
    </div>
    <div class="cp-col-right">
        <div class="cp-col-amt">$<?= number_format($col['amount']??0, 2) ?></div>
        <div class="cp-col-status" style="font-size:10px;"><?= $crmStatusHtml ?></div>
        <a href="?page=receipt&id=<?= $col['id'] ?>" target="_blank" class="cp-col-receipt"><i class="bi bi-printer"></i> Receipt</a>
    </div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="cp-empty">
    <i class="bi bi-cash-coin"></i>
    <div style="font-size:15px;font-weight:700;">No collections yet</div>
    <div style="font-size:12px;margin-top:4px;">Search a customer to collect your first payment</div>
</div>
<?php endif; ?>
</div><!-- /cpHistoryView -->

<div style="height:80px;"></div>

<!-- CRM Search Script -->
<script>
// Tab switching
function cpSwitchTab(tab, el) {
    document.querySelectorAll('.cp-tab').forEach(t => t.classList.remove('active'));
    if (el) el.classList.add('active');
    document.getElementById('cpCollectView').style.display = tab==='collect' ? 'block' : 'none';
    document.getElementById('cpHistoryView').style.display = tab==='history' ? 'block' : 'none';
}

// Commission preview
document.getElementById('cpAmount')?.addEventListener('input', function() {
    const comm = (parseFloat(this.value||0) * <?= $commRate ?> / 100).toFixed(2);
    document.getElementById('cpCommPreview').textContent = '$' + comm;
});

function cpSetAmount(amt) {
    document.getElementById('cpAmount').value = amt;
    const comm = (amt * <?= $commRate ?> / 100).toFixed(2);
    document.getElementById('cpCommPreview').textContent = '$' + comm;
}

var _cpCurrency = 'USD';
function cpSetCurrency(cur) {
    _cpCurrency = cur;
    document.getElementById('cpCurrency').value = cur;
    var usdBtn = document.getElementById('cpCurUSD');
    var sspBtn = document.getElementById('cpCurSSP');
    var sspRow = document.getElementById('cpSspRow');
    if (cur === 'USD') {
        usdBtn.style.background = '#D41C1C'; usdBtn.style.borderColor = '#D41C1C'; usdBtn.style.color = '#fff';
        sspBtn.style.background = '#fff';    sspBtn.style.borderColor = '#e2e8f0'; sspBtn.style.color = '#64748b';
        document.getElementById('cpCommPreview').closest('div').style.display = '';
        if (sspRow) sspRow.style.display = 'none';
    } else {
        sspBtn.style.background = '#1A237E'; sspBtn.style.borderColor = '#1A237E'; sspBtn.style.color = '#fff';
        usdBtn.style.background = '#fff';    usdBtn.style.borderColor = '#e2e8f0'; usdBtn.style.color = '#64748b';
        // Commission doesn't apply to SSP collections
        document.getElementById('cpCommPreview').closest('div').style.display = 'none';
        // Show SSP amount field for tracking actual cash received
        if (sspRow) sspRow.style.display = '';
    }
}

function cpConfirmPay() {
    const amt   = document.getElementById('cpAmount').value;
    const name  = document.getElementById('cpCustName').value || 'customer';
    const cur   = document.getElementById('cpCurrency').value || 'USD';
    if (!amt || parseFloat(amt) <= 0) { alert('Enter amount'); return false; }
    if (!name) { alert('Select or enter customer name'); return false; }

    var crmId = document.getElementById('cpCustId').value;

    // ── Auto-match: if no CRM ID, try to find best match from local index ──
    if (!crmId) {
        var idx = window.CRM_IDX || [];
        var needle = name.toLowerCase().replace(/\s+/g,' ').trim();
        var needleWords = needle.split(' ').filter(function(w){ return w.length >= 3; });
        var bestMatch = null; var bestScore = 0;
        idx.forEach(function(c) {
            var cname = (c.name||'').toLowerCase();
            var hay   = (c.search||cname).toLowerCase();
            if (cname === needle) { bestMatch = c; bestScore = 100; return; }
            if (bestScore >= 100) return;
            var wm = needleWords.filter(function(w){ return hay.indexOf(w) !== -1; }).length;
            if (needleWords.length >= 2 && wm === needleWords.length && wm+80 > bestScore) {
                bestScore = wm + 80; bestMatch = c;
            } else if (needleWords.length >= 2) {
                var first = needleWords[0], last = needleWords[needleWords.length-1];
                if (first.length >= 4 && last.length >= 4 && cname.indexOf(first)!==-1 && cname.indexOf(last)!==-1 && 75 > bestScore) {
                    bestScore = 75; bestMatch = c;
                }
            }
        });

        if (bestMatch && bestScore >= 75) {
            // Silently attach matched CRM ID
            document.getElementById('cpCustId').value = bestMatch.id;
            document.getElementById('cpCustName').value = bestMatch.name;
            crmId = String(bestMatch.id);
            console.log('Auto-matched: ' + name + ' → ' + bestMatch.name + ' (CRM #' + bestMatch.id + ', score ' + bestScore + ')');
        }
    }

    const sym = cur === 'SSP' ? 'SSP ' : '$';
    if (!crmId) {
        return confirm('⚠️ No CRM match found for "' + name + '".\n\nPayment will be saved locally only — NOT posted to UCRM.\n\nContinue? (or Cancel and search by name/phone)');
    }
    return confirm('Collect ' + sym + amt + ' from ' + name + '?\n\nThis will debit your wallet.');
}

// Close dropdown on outside click
document.addEventListener('click', function(e) {
    var box = document.getElementById('crmSearchResults');
    var inp = document.getElementById('crmSearchInput');
    if (box && inp && !box.contains(e.target) && e.target !== inp) {
        box.style.display = 'none';
    }
});

function cpClearCustomer() {
    document.getElementById('cpCustCard').classList.remove('show');
    document.getElementById('cpInvoiceSection').classList.remove('show');
    document.getElementById('cpCustId').value = '';
    document.getElementById('cpCustName').value = '';
    document.getElementById('cpInvoiceId').value = '';
    document.getElementById('cpInvoiceLabel').value = '';
    document.getElementById('cpAmount').value = '';
    document.getElementById('cpNote').value = '';
    document.getElementById('crmSearchInput').value = '';
    document.getElementById('crmSearchResults').innerHTML = '';
}

// ── Instant CRM search (Starlink Finance pattern) ────────────────────────
var _crmSearchActive = -1;

function crmInstantSearch(raw) {
    var q = raw.trim().toLowerCase();
    var box = document.getElementById('crmSearchResults');

    if (q.length < 1) { box.style.display = 'none'; _crmSearchActive = -1; return; }

    var idx = window.CRM_IDX || [];
    var matches = idx.filter(function(c){ return c.search.indexOf(q) !== -1; }).slice(0, 10);

    if (matches.length === 0) {
        box.innerHTML = '<div style="padding:14px 16px;color:#9CA3AF;font-size:13px;text-align:center;">No results for "'+raw+'"</div>';
        box.style.display = '';
        return;
    }

    function hl(text, q) {
        if (!q || !text) return String(text||'');
        var re = new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi');
        return String(text).replace(re,'<mark style="background:#fff0f0;color:#1D4ED8;font-weight:700;border-radius:2px;">$1</mark>');
    }

    var html = '';
    matches.forEach(function(c, i) {
        var balColor = c.bal > 0 ? '#DC2626' : '#16A34A';
        var balLabel = c.bal > 0 ? '-$'+Math.abs(c.bal).toFixed(2)+' owes' : c.bal < 0 ? '+$'+Math.abs(c.bal).toFixed(2)+' credit' : '$0.00';
        // Service type badge
        var svcBadge = '';
        var pl = (c.plans||'').toLowerCase();
        var src = c._src||'';
        if (src === 'lte') svcBadge = '<span style="font-size:9px;font-weight:800;background:#FFF3E0;color:#E65100;padding:1px 6px;border-radius:4px;margin-left:4px;">LTE</span>';
        else if (pl.indexOf('starlink') !== -1) svcBadge = '<span style="font-size:9px;font-weight:800;background:#EDE7F6;color:#7B1FA2;padding:1px 6px;border-radius:4px;margin-left:4px;">Starlink</span>';
        else if (pl.indexOf('fiber') !== -1 || pl.indexOf('fibre') !== -1) svcBadge = '<span style="font-size:9px;font-weight:800;background:#E3F2FD;color:#1565C0;padding:1px 6px;border-radius:4px;margin-left:4px;">Fiber</span>';
        var cId = String(c.id).replace(/'/g,"\\'");
        html += '<div class="crm-sr-item" data-idx="'+i+'" tabindex="-1"'
            + ' onmousedown="crmPickResult(\''+cId+'\',\''+c.name.replace(/'/g,"\'")+'\','+c.bal+')"'
            + ' onmouseover="crmHighlight('+i+')">'
            + '<div style="flex:1;min-width:0;">'
            + '<div style="font-size:13px;font-weight:700;color:#1E293B;">'+hl(c.name, raw)+svcBadge+'</div>'
            + '<div style="font-size:11px;color:#64748B;margin-top:2px;">'
            + 'ID: <strong>'+c.id+'</strong>'
            + (c.phone ? ' · '+hl(c.phone, raw) : '')
            + '</div></div>'
            + '<div style="text-align:right;flex-shrink:0;padding-left:12px;">'
            + '<div style="font-size:12px;font-weight:700;color:'+balColor+';">'+balLabel+'</div>'
            + '</div></div>';
    });

    box.innerHTML = html;
    box.style.display = '';
    _crmSearchActive = -1;
}

function crmHighlight(i) {
    document.querySelectorAll('.crm-sr-item').forEach(function(el,j){
        el.style.background = j===i ? '#EFF6FF' : '#fff';
    });
    _crmSearchActive = i;
}

function crmSearchKeyNav(e) {
    var items = document.querySelectorAll('.crm-sr-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); crmHighlight(Math.min(_crmSearchActive+1, items.length-1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); crmHighlight(Math.max(_crmSearchActive-1, 0)); }
    else if (e.key === 'Enter' && _crmSearchActive >= 0) { e.preventDefault(); items[_crmSearchActive].onmousedown(); }
    else if (e.key === 'Escape') { document.getElementById('crmSearchResults').style.display='none'; }
}

function crmPickResult(id, name, bal) {
    document.getElementById('crmSearchInput').value = name;
    document.getElementById('crmSearchResults').style.display = 'none';
    cpSelectCustomer(id, name, bal);
}

// Legacy stub so any old references don't 404
function searchCrmCustomer() { crmInstantSearch(document.getElementById('crmSearchInput').value); }

// Select customer + load invoices
function cpSelectCustomer(id, name, balance) {
    document.getElementById('cpCustId').value = id;
    document.getElementById('cpCustName').value = name;
    document.getElementById('cpCustDisplay').textContent = name;
    var isLte = String(id).indexOf('LTE-') === 0;
    document.getElementById('cpCustIdDisplay').textContent = isLte ? '📶 LTE Customer' : 'CRM ID: ' + id;
    const balColor = balance > 0 ? '#dc3545' : '#28a745';
    document.getElementById('cpCustBalDisplay').innerHTML = 'Account Balance: <span style="color:'+balColor+';font-weight:800;">$' + balance.toFixed(2) + '</span>' + (balance > 0 ? ' <span style="color:#dc3545;font-size:11px;">(owes)</span>' : '');
    document.getElementById('cpCustCard').classList.add('show');
    document.getElementById('crmSearchResults').innerHTML = '';

    // ── AUTO-DETECT SERVICE TYPE from customer's plans ──
    var svcSel = document.getElementById('cpServiceType');
    var match = (window.CRM_IDX||[]).find(function(c){ return c.id == id; });
    if (match) {
        var pl = (match.plans||'').toLowerCase();
        var src = (match._src||'');
        if (src === 'lte') {
            svcSel.value = 'lte';
        } else if (pl.indexOf('starlink') !== -1) {
            svcSel.value = 'starlink';
        } else if (pl.indexOf('fiber') !== -1 || pl.indexOf('fibre') !== -1 || pl.indexOf('ftth') !== -1) {
            svcSel.value = 'fiber';
        }
        // If no plan match, leave whatever agent had selected
    }

    // Load invoices
    const invSection = document.getElementById('cpInvoiceSection');
    const invList = document.getElementById('cpInvoiceList');
    const invLoading = document.getElementById('cpInvoiceLoading');
    const invNone = document.getElementById('cpNoInvoices');
    invSection.classList.add('show');
    invList.innerHTML = '';
    invLoading.style.display = 'block';
    invNone.style.display = 'none';

    fetch('?page=api&action=customer_ledger&cid=' + id, {
        headers: { 'Authorization': 'Bearer <?= h($retailer['api_token'] ?? '') ?>' }
    })
    .then(r => {
        const cacheStatus = r.headers.get('X-Cache') || '';
        if (cacheStatus.startsWith('HIT')) {
            var hint = document.getElementById('cpInvCacheHint');
            if (hint) hint.style.display = 'inline';
        }
        return r.json();
    })
    .then(d => {
        invLoading.style.display = 'none';
        if (d.status !== 'success') { invNone.style.display = 'block'; return; }
        const unpaid = d.data.invoices_unpaid || [];
        if (unpaid.length === 0) { invNone.style.display = 'block'; return; }
        cpRenderInvoices(unpaid, d.data.total_due || 0, invList, d.data.payments || []);
    })
    .catch(() => {
        invLoading.style.display = 'none';
        invNone.style.display = 'block';
    });
}

// Store payments data for payment history lookup
var cpPaymentsCache = [];

function cpRenderInvoices(unpaid, totalDue, container, payments) {
    // Cache payments for history lookup
    cpPaymentsCache = payments || [];
    
    let html = '<div style="font-size:11px;font-weight:700;color:#dc3545;margin-bottom:8px;">&#9888; ' + unpaid.length + ' item(s) — Total due: $' + parseFloat(totalDue||0).toFixed(2) + '</div>';
    unpaid.forEach((inv, idx) => {
        const remaining = parseFloat(inv.amountToPay || inv.total || 0).toFixed(2);
        const total     = parseFloat(inv.total || inv.amountToPay || 0).toFixed(2);
        const paid      = parseFloat(inv.amountPaid || 0).toFixed(2);
        const invNum    = inv.number || inv.id || '—';
        const invId     = inv.id || 0;
        const dt        = (inv.createdDate || '').substring(0, 10);
        const isVirt    = inv._virtual;
        const label     = isVirt ? (inv._label || 'Outstanding Balance') : ('Invoice #' + invNum);
        const icon      = isVirt ? '&#128181;' : '&#128196;';
        const iconBg    = isVirt ? '#FFF3E0' : '#FFEBEE';
        const isPartial = inv.status == 2;
        const status    = isVirt ? 'Balance' : (isPartial ? 'Partial' : 'Unpaid');
        const statusColor = isVirt ? '#E65100' : (isPartial ? '#E65100' : '#dc3545');
        
        // Payment progress bar for partial payments
        const paidPct = total > 0 ? Math.min(100, (paid / total) * 100) : 0;
        const progressBar = isPartial && !isVirt ? 
            '<div style="height:3px;background:#fee2e2;border-radius:2px;margin-top:4px;overflow:hidden;">' +
            '<div style="height:100%;width:'+paidPct.toFixed(0)+'%;background:#22c55e;"></div></div>' : '';
        
        // Show original total and paid for partial invoices
        const amtDisplay = isPartial && !isVirt ?
            '<div class="cp-inv-amt" style="color:#dc3545;">$' + remaining + '</div>' +
            '<div style="font-size:9px;color:#6b7280;">of $' + total + ' (paid $' + paid + ')</div>' :
            '<div class="cp-inv-amt">$' + remaining + '</div>';
        
        // History button for partial invoices
        const histBtn = isPartial && !isVirt ?
            '<div onclick="event.stopPropagation();cpShowPaymentHistory('+invId+',\''+invNum+'\');" ' +
            'style="font-size:9px;color:#3b82f6;cursor:pointer;margin-top:2px;">📜 History</div>' : '';
        
        html += '<div class="cp-inv-card cp-animate" onclick="cpSelectInvoice(this,'+(isVirt?0:invId)+','+remaining+',\''+String(isVirt?'BAL':invNum)+'\')">' +
            '<div class="cp-inv-icon" style="background:'+iconBg+';">'+icon+'</div>' +
            '<div class="cp-inv-info"><div class="cp-inv-num">' + label + '</div>' +
            '<div class="cp-inv-detail">' + (isVirt ? 'Account balance due' : dt) + '</div>' + progressBar + '</div>' +
            '<div class="cp-inv-right">' + amtDisplay +
            '<div class="cp-inv-status" style="color:'+statusColor+';">' + status + '</div>' + histBtn + '</div></div>';
    });
    const td = parseFloat(totalDue||0).toFixed(2);
    html += '<button onclick="cpSetAmount(' + td + ');document.getElementById(\'cpNote\').value=\'Full payment — '+unpaid.length+' invoice(s)\'" ' +
        'style="width:100%;padding:10px;background:#FFEBEE;color:#dc3545;border:1.5px solid #FFCDD2;border-radius:12px;font-size:13px;font-weight:700;cursor:pointer;margin-top:6px;">' +
        '&#128176; Pay All — $' + td + '</button>';
    container.innerHTML = html;
}

// Show payment history modal for an invoice
function cpShowPaymentHistory(invoiceId, invoiceNum) {
    // Filter payments for this invoice
    const invPayments = cpPaymentsCache.filter(p => p.invoiceId == invoiceId);
    
    let html = '<div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;" onclick="this.remove();">';
    html += '<div onclick="event.stopPropagation();" style="background:#fff;border-radius:16px;width:90%;max-width:360px;max-height:70vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,0.3);">';
    html += '<div style="padding:16px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">';
    html += '<div style="font-weight:700;font-size:14px;">📜 Payment History</div>';
    html += '<div style="font-size:12px;color:#6b7280;">Invoice #' + invoiceNum + '</div></div>';
    
    if (invPayments.length === 0) {
        html += '<div style="padding:24px;text-align:center;color:#6b7280;font-size:13px;">No payments found for this invoice</div>';
    } else {
        html += '<div style="padding:12px;">';
        let totalPaid = 0;
        invPayments.forEach(p => {
            const amt = parseFloat(p.amount || 0);
            totalPaid += amt;
            const dt = (p.createdDate || '').substring(0, 10);
            const method = p.methodName || 'Unknown';
            const note = p.note || '';
            // Extract collector name from note
            const collectorMatch = note.match(/Collected by ([^|]+)/);
            const collector = collectorMatch ? collectorMatch[1].trim() : '—';
            
            html += '<div style="padding:10px;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:8px;">';
            html += '<div style="display:flex;justify-content:space-between;align-items:center;">';
            html += '<div style="font-weight:600;color:#22c55e;font-size:14px;">+$' + amt.toFixed(2) + '</div>';
            html += '<div style="font-size:11px;color:#6b7280;">' + dt + '</div></div>';
            html += '<div style="font-size:11px;color:#374151;margin-top:4px;">💳 ' + method + '</div>';
            html += '<div style="font-size:10px;color:#6b7280;margin-top:2px;">👤 ' + collector + '</div>';
            html += '</div>';
        });
        html += '<div style="padding:10px;background:#f0fdf4;border-radius:10px;margin-top:4px;">';
        html += '<div style="display:flex;justify-content:space-between;font-weight:700;font-size:13px;">';
        html += '<span>Total Paid</span><span style="color:#22c55e;">$' + totalPaid.toFixed(2) + '</span></div></div>';
        html += '</div>';
    }
    
    html += '<div style="padding:12px;border-top:1px solid #e5e7eb;">';
    html += '<button onclick="this.closest(\'div[style*=position:fixed]\').remove();" ' +
        'style="width:100%;padding:10px;background:#f3f4f6;border:none;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;">Close</button>';
    html += '</div></div></div>';
    
    document.body.insertAdjacentHTML('beforeend', html);
}

function cpRefreshInvoices() {
    var cid = document.getElementById('cpCustId').value;
    var name = document.getElementById('cpCustName').value;
    if (!cid) return;
    // Re-select with force refresh flag
    var invList = document.getElementById('cpInvoiceList');
    var invLoading = document.getElementById('cpInvoiceLoading');
    var invNone = document.getElementById('cpNoInvoices');
    var hint = document.getElementById('cpInvCacheHint');
    if (hint) hint.style.display = 'none';
    invList.innerHTML = '';
    invLoading.style.display = 'block';
    invNone.style.display = 'none';
    fetch('?page=api&action=customer_ledger&cid=' + cid + '&refresh=1', {
        headers: { 'Authorization': 'Bearer <?= h($retailer['api_token'] ?? '') ?>' }
    })
    .then(r => r.json())
    .then(d => {
        invLoading.style.display = 'none';
        if (d.status !== 'success') { invNone.style.display = 'block'; return; }
        const unpaid = d.data.invoices_unpaid || [];
        if (unpaid.length === 0) { invNone.style.display = 'block'; return; }
        cpRenderInvoices(unpaid, d.data.total_due || 0, invList, d.data.payments || []);
    })
    .catch(() => { invLoading.style.display = 'none'; invNone.style.display = 'block'; });
}
function cpSelectInvoice(el, invId, amt, invLabel) {
    document.querySelectorAll('.cp-inv-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('cpInvoiceId').value = invId || '';
    document.getElementById('cpInvoiceLabel').value = invLabel || '';
    document.getElementById('cpAmount').value = amt;
    var displayLabel = invLabel || (invId ? 'Invoice #' + invId : 'Balance');
    document.getElementById('cpNote').value = 'Invoice #' + displayLabel;
    const comm = (amt * <?= $commRate ?> / 100).toFixed(2);
    document.getElementById('cpCommPreview').textContent = '$' + comm;
}
</script>

