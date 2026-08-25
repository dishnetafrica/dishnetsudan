<?php
// Tab: service_status
// Extracted from public.php on 2026-03-15
?>

<div style="font-size:16px;font-weight:800;color:#1e293b;margin-bottom:14px;"><i class="bi bi-broadcast" style="color:#28a745;margin-right:6px;"></i>Service Status Check</div>

<div class="cl-search" style="background:#fff;border-radius:16px;padding:18px;box-shadow:0 2px 10px rgba(0,0,0,.05);border:1px solid #f1f5f9;margin-bottom:16px;">
    <div style="font-size:13px;font-weight:700;color:#475569;margin-bottom:8px;">Enter CRM Customer ID to check services &amp; billing status</div>
    <div class="cl-search-bar" style="display:flex;gap:8px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:14px;padding:4px;">
        <input type="number" id="svcCheckId" placeholder="Customer CRM ID..." style="flex:1;border:none;background:transparent;padding:10px 12px;font-size:16px;outline:none;" onkeyup="if(event.key==='Enter')supCheckService()">
        <button onclick="supCheckService()" style="background:#28a745;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-weight:700;cursor:pointer;flex-shrink:0;"><i class="bi bi-broadcast"></i> Check</button>
    </div>
</div>
<div id="svcCheckResults"></div>

<script>
function supCheckService() {
    var cid = document.getElementById('svcCheckId').value.trim();
    if (!cid) return;
    var box = document.getElementById('svcCheckResults');
    box.innerHTML = '<div style="padding:20px;text-align:center;color:#6b7280;"><i class="bi bi-arrow-repeat spin"></i> Checking...</div>';

    fetch('?page=api&action=customer_invoices&cid=' + cid, {
        headers: { 'Authorization': 'Bearer <?= h($retailer['api_token'] ?? "") ?>' }
    })
    .then(function(r){return r.json();})
    .then(function(d) {
        if (d.status !== 'success') { box.innerHTML = '<div style="color:#dc3545;padding:12px;">Failed to load. Check CRM ID.</div>'; return; }
        var client = d.data.client || {};
        var svcs = d.data.services || [];
        var invs = d.data.invoices || [];
        var name = (client.firstName||'') + ' ' + (client.lastName||'');
        var bal = parseFloat(client.accountBalance||0);

        var html = '<div style="background:#fff;border-radius:14px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.05);margin-bottom:12px;">';
        html += '<div style="font-size:18px;font-weight:800;">'+name+'</div>';
        html += '<div style="font-size:12px;color:#6b7280;">CRM #'+cid+' &middot; Balance: <span style="color:'+(bal<0?'#dc3545':'#28a745')+';font-weight:700;">$'+bal.toFixed(2)+'</span></div></div>';

        // Services with color-coded status
        html += '<div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:8px;">Services ('+svcs.length+')</div>';
        if (!svcs.length) html += '<div style="background:#FFF3E0;border-radius:10px;padding:10px;color:#E65100;font-size:12px;"><i class="bi bi-exclamation-triangle"></i> No active services found</div>';
        svcs.forEach(function(s) {
            var active = (s.status === 1 || s.status === 3);
            var suspended = s.status === 5;
            var stText = active ? 'Active' : (suspended ? 'Suspended' : 'Inactive');
            var stColor = active ? '#28a745' : (suspended ? '#FF9800' : '#dc3545');
            html += '<div style="background:#fff;border-radius:14px;padding:14px;margin-bottom:8px;box-shadow:0 1px 4px rgba(0,0,0,.03);border-left:4px solid '+stColor+';">';
            html += '<div style="display:flex;justify-content:space-between;align-items:center;">';
            html += '<div><div style="font-weight:700;font-size:14px;">'+(s.name||s.servicePlanName||'Service')+'</div>';
            html += '<div style="font-size:11px;color:#6b7280;margin-top:2px;">ID: '+s.id;
            if (s.activeFrom) html += ' &middot; Since: '+(s.activeFrom||'').substring(0,10);
            if (s.activeTo) html += ' &middot; Until: '+(s.activeTo||'').substring(0,10);
            html += '</div></div>';
            html += '<span style="background:'+(active?'#E8F5E9':(suspended?'#FFF3E0':'#FFEBEE'))+';color:'+stColor+';padding:4px 10px;border-radius:8px;font-size:11px;font-weight:700;">'+stText+'</span>';
            html += '</div></div>';
        });

        // Open invoices
        html += '<div style="font-size:13px;font-weight:800;color:#1e293b;margin:16px 0 8px;">Open Invoices ('+invs.length+')</div>';
        if (!invs.length) html += '<div style="background:#E8F5E9;border-radius:10px;padding:10px;color:#2E7D32;font-size:12px;"><i class="bi bi-check-circle"></i> No outstanding invoices</div>';
        invs.forEach(function(inv) {
            var due = parseFloat(inv.total||0) - parseFloat(inv.amountPaid||0);
            var invDate = (inv.createdDate||'').substring(0,10);
            var dueDate = (inv.dueDate||'').substring(0,10);
            var overdue = dueDate && new Date(dueDate) < new Date();
            html += '<div style="display:flex;justify-content:space-between;align-items:center;background:#fff;border-radius:10px;padding:10px 12px;margin-bottom:4px;box-shadow:0 1px 3px rgba(0,0,0,.03);">';
            html += '<div><div style="font-size:12px;font-weight:700;">#'+(inv.number||inv.id)+'</div><div style="font-size:10px;color:#9ca3af;">'+invDate+(dueDate?' &middot; Due: '+dueDate:'')+'</div></div>';
            html += '<div style="text-align:right;"><div style="font-weight:800;color:#dc3545;">$'+due.toFixed(2)+'</div>';
            if (overdue) html += '<div style="font-size:9px;color:#dc3545;font-weight:700;">OVERDUE</div>';
            html += '</div></div>';
        });

        box.innerHTML = html;
    })
    .catch(function() { box.innerHTML = '<div style="padding:12px;color:#dc3545;">Failed to load. Check CRM connection.</div>'; });
}
</script>
<div style="height:80px;"></div>


