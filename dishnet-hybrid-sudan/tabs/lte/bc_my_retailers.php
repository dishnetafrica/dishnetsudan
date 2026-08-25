<?php
// 
// My Retailers  Outstanding tracker for BBC and dealers
// Accessible to sales / sales_staff / admin roles
// Shows only retailers under them (from BlueCard user_management)
// 

$tok = htmlspecialchars($retailer['api_token'] ?? '', ENT_QUOTES);
$rid = (int)($retailer['id'] ?? 0);
$bcUid = (int)($retailer['bluecard_user_id'] ?? 0);
$isAdm = !empty($retailer['is_admin']);
?>
<div style="max-width:960px;">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
  <div>
    <div style="font-size:18px;font-weight:800;"> My Retailers  Outstanding</div>
    <div style="font-size:12px;color:#64748B;margin-top:3px;">
      Track how much each retailer owes vs collected. Outstanding = Recharged  Collected by BBC.
    </div>
  </div>
  <a href="?page=dashboard&tab=cashbook&cb_proj=4g" class="btn btn-primary" style="font-size:13px;"> Record Collection</a>
</div>

<!-- Summary -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;">
  <div style="background:#fff;border:1.5px solid #E2E8F0;border-radius:14px;padding:16px;text-align:center;">
    <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:6px;">Total Outstanding</div>
    <div id="myrlTotalOut" style="font-size:28px;font-weight:800;color:#DC2626;"></div>
  </div>
  <div style="background:#fff;border:1.5px solid #E2E8F0;border-radius:14px;padding:16px;text-align:center;">
    <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:6px;">Retailers Tracked</div>
    <div id="myrlCount" style="font-size:28px;font-weight:800;color:#1D4ED8;"></div>
  </div>
  <div style="background:#fff;border:1.5px solid #E2E8F0;border-radius:14px;padding:16px;text-align:center;">
    <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:6px;">At/Over Limit</div>
    <div id="myrlBlocked" style="font-size:28px;font-weight:800;color:#D97706;"></div>
  </div>
</div>

<!-- Table -->
<div style="background:#fff;border:1.5px solid #E2E8F0;border-radius:14px;overflow:hidden;">
  <div style="padding:14px 18px;border-bottom:1px solid #F1F5F9;display:flex;justify-content:space-between;align-items:center;">
    <div style="font-weight:700;">All Retailers</div>
    <input type="text" id="myrlSearch" placeholder="Search name or mobile" oninput="myrlFilter(this.value)"
           style="border:1.5px solid #E2E8F0;border-radius:10px;padding:7px 12px;font-size:12px;font-family:inherit;outline:none;width:200px;">
  </div>
  <div id="myrlTableWrap" style="overflow-x:auto;">
    <div style="padding:24px;text-align:center;color:#94a3b8;"> Loading</div>
  </div>
</div>

</div>

<script>
var _myrlData = [];

fetch('?page=api&action=bc_my_retailer_outstanding', {
    headers:{'X-Api-Token':'<?= $tok ?>'}
}).then(function(r){return r.json();})
.then(function(d){
    var rows = (d.data||{}).rows || [];
    var totalOut = (d.data||{}).total_outstanding || 0;
    _myrlData = rows;
    document.getElementById('myrlTotalOut').textContent = '$' + totalOut.toFixed(2);
    document.getElementById('myrlCount').textContent = rows.length;
    document.getElementById('myrlBlocked').textContent = rows.filter(function(r){return r.is_blocked;}).length;
    myrlFilter('');
}).catch(function(){
    document.getElementById('myrlTableWrap').innerHTML='<div style="padding:20px;color:#DC2626;text-align:center;">Failed to load data.</div>';
});

function myrlFilter(q) {
    q = (q||'').toLowerCase();
    var filtered = _myrlData.filter(function(r){
        return !q || (r.name||'').toLowerCase().indexOf(q)>=0 || (r.mobile||'').indexOf(q)>=0;
    });

    if (!filtered.length) {
        document.getElementById('myrlTableWrap').innerHTML='<div style="padding:20px;text-align:center;color:#94a3b8;">No retailers found.</div>';
        return;
    }

    var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
        + '<thead><tr style="background:#F8FAFC;">'
        + '<th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;">Retailer</th>'
        + '<th style="padding:10px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;">Recharged</th>'
        + '<th style="padding:10px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;">Collected</th>'
        + '<th style="padding:10px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;">Outstanding</th>'
        + '<th style="padding:10px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;">Limit</th>'
        + '<th style="padding:10px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;">Status</th>'
        + '</tr></thead><tbody>';

    filtered.forEach(function(r) {
        var pct = r.limit_usd > 0 ? Math.min(r.outstanding / r.limit_usd * 100, 100).toFixed(0) : 0;
        var barCol = pct>=100?'#DC2626':pct>=80?'#D97706':'#16A34A';
        var badge = r.is_blocked
            ? '<span style="background:#FEE2E2;color:#DC2626;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;"> BLOCKED</span>'
            : parseInt(pct)>=80
                ? '<span style="background:#FEF3C7;color:#D97706;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;"> Near Limit</span>'
                : '<span style="background:#DCFCE7;color:#16A34A;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;"> OK</span>';

        html += '<tr style="border-top:1px solid #F1F5F9;">'
            + '<td style="padding:12px 16px;"><div style="font-weight:700;">' + (r.name||'') + '</div>'
            +   '<div style="font-size:11px;color:#94a3b8;">' + (r.mobile||'') + '</div>'
            +   '<div style="background:#E2E8F0;border-radius:4px;height:5px;width:100px;margin-top:6px;">'
            +   '<div style="background:'+barCol+';border-radius:4px;height:5px;width:'+pct+'%;"></div></div></td>'
            + '<td style="padding:12px 16px;text-align:right;color:#1D4ED8;font-weight:600;">$' + r.recharged.toFixed(2) + '</td>'
            + '<td style="padding:12px 16px;text-align:right;color:#16A34A;font-weight:600;">$' + r.collected.toFixed(2) + '</td>'
            + '<td style="padding:12px 16px;text-align:right;font-weight:800;color:' + (r.outstanding>0?'#DC2626':'#64748B') + ';font-size:15px;">$' + r.outstanding.toFixed(2) + '</td>'
            + '<td style="padding:12px 16px;text-align:center;color:#64748B;">$' + r.limit_usd.toFixed(0) + '</td>'
            + '<td style="padding:12px 16px;text-align:center;">' + badge + '</td>'
            + '</tr>';
    });

    html += '</tbody></table>';
    document.getElementById('myrlTableWrap').innerHTML = html;
}
</script>
