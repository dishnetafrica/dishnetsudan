<?php
// Tab: knowledge_base
// Extracted from public.php on 2026-03-15
?>
    <div class="kyc-card">
        <div class="kyc-card-header"><i class="bi bi-book"></i> Retailer Help Guide</div>
        <div style="padding:20px;font-size:13px;color:#6b7280;">Everything you need to know to serve customers and manage your business.</div>
    </div>

    <div class="kyc-card"><div class="kyc-card-header" style="background:#E3F2FD;color:#D41C1C;">&#128181; 1. How to Collect a Customer Payment</div>
    <div style="padding:20px;font-size:13px;line-height:1.9;">
    <table class="kyc-table">
    <tr><td style="width:36px;font-weight:800;color:#D41C1C;font-size:16px;text-align:center;">1</td><td>Tap <strong>Collect Payment</strong> from the bottom menu or sidebar</td></tr>
    <tr><td style="font-weight:800;color:#D41C1C;font-size:16px;text-align:center;">2</td><td>Search for the customer by name or CRM ID — results load from CRM</td></tr>
    <tr><td style="font-weight:800;color:#D41C1C;font-size:16px;text-align:center;">3</td><td>Tap the customer to auto-fill their details</td></tr>
    <tr><td style="font-weight:800;color:#D41C1C;font-size:16px;text-align:center;">4</td><td>Enter the amount (or tap a quick amount button: $40, $80, $110 etc.)</td></tr>
    <tr><td style="font-weight:800;color:#D41C1C;font-size:16px;text-align:center;">5</td><td>Select payment method (Cash / Bank Transfer / Mobile Money)</td></tr>
    <tr><td style="font-weight:800;color:#D41C1C;font-size:16px;text-align:center;">6</td><td>Tap <strong>Collect Payment</strong> → confirm → done!</td></tr>
    </table>
    <div style="margin-top:12px;padding:12px;background:#FFF3E0;border-radius:8px;border-left:3px solid #E65100;font-size:12px;">
        <strong>&#9888; Important:</strong> The amount is deducted from YOUR wallet. Make sure you collect the cash/transfer from the customer first! If CRM API is configured, the payment is automatically posted to CRM.
    </div>
    </div></div>

    <div class="kyc-card"><div class="kyc-card-header" style="background:#E8F5E9;color:#2E7D32;">&#128203; 2. How to Register a New Customer (KYC)</div>
    <div style="padding:20px;font-size:13px;line-height:1.9;">
    <table class="kyc-table">
    <tr><td style="width:36px;font-weight:800;color:#2E7D32;font-size:16px;text-align:center;">1</td><td>Tap <strong>KYC</strong> or <strong>Add Customer</strong></td></tr>
    <tr><td style="font-weight:800;color:#2E7D32;font-size:16px;text-align:center;">2</td><td><strong>Step 1 — Service:</strong> Choose connection type + service (Starlink / Fiber / SIM Card)</td></tr>
    <tr><td style="font-weight:800;color:#2E7D32;font-size:16px;text-align:center;">3</td><td><strong>Step 2 — Customer:</strong> Enter name, phone, email, address, GPS location</td></tr>
    <tr><td style="font-weight:800;color:#2E7D32;font-size:16px;text-align:center;">4</td><td><strong>Step 3 — Plan:</strong> Select hardware (Starlink/Fiber) + plan. For SIM: enter SIM details</td></tr>
    <tr><td style="font-weight:800;color:#2E7D32;font-size:16px;text-align:center;">5</td><td><strong>Step 4 — KYC:</strong> Upload customer photo + ID document. Set sales person, payment type</td></tr>
    <tr><td style="font-weight:800;color:#2E7D32;font-size:16px;text-align:center;">6</td><td><strong>Step 5 — Review:</strong> Check everything → Submit</td></tr>
    </table>
    <div style="margin-top:12px;padding:12px;background:#f0fdf4;border-radius:8px;border-left:3px solid #28a745;font-size:12px;">
        <strong>Cash payment:</strong> Wallet is debited on submit. <strong>Credit payment:</strong> No wallet deduction.<br>
        If CRM sync fails, wallet is automatically reversed after 3 retries.
    </div>
    </div></div>

    <div class="kyc-card"><div class="kyc-card-header" style="background:#FFF3E0;color:#E65100;">&#128176; 3. Your Wallet</div>
    <div style="padding:20px;font-size:13px;line-height:1.9;">
    <p>Your wallet is like a prepaid account. Money goes out when you:</p>
    <table class="kyc-table" style="margin:12px 0;">
    <tr><td style="font-weight:700;">Submit a Cash KYC</td><td>Debits the plan/hardware amount</td></tr>
    <tr><td style="font-weight:700;">Collect a Payment</td><td>Debits the payment amount</td></tr>
    <tr><td style="font-weight:700;">Activate a SIM</td><td>Debits the activation fee</td></tr>
    </table>
    <p>Money comes in when:</p>
    <table class="kyc-table" style="margin:12px 0;">
    <tr><td style="font-weight:700;">Admin Top-up</td><td>Admin credits your wallet after you deposit money</td></tr>
    <tr><td style="font-weight:700;">Recharge Approved</td><td>You submit proof of payment, admin approves</td></tr>
    <tr><td style="font-weight:700;">Auto-Reversal</td><td>If a CRM sync fails 3 times, you get automatic refund</td></tr>
    </table>
    <div style="padding:12px;background:#E3F2FD;border-radius:8px;font-size:12px;">
        <strong>&#128161; Tip:</strong> Check <strong>My Wallet</strong> regularly. Keep enough balance for daily collections. Request recharge early!
    </div>
    </div></div>

    <div class="kyc-card"><div class="kyc-card-header" style="background:#F3E5F5;color:#7B1FA2;">&#128241; 4. Available Plans & Pricing</div>
    <div style="padding:20px;font-size:13px;line-height:1.9;">

    <h4 style="margin:0 0 8px;color:#D41C1C;">&#128225; Starlink Plans</h4>
    <table class="kyc-table" style="margin-bottom:16px;"><thead><tr><th>Plan</th><th style="text-align:right;">Customer Price</th></tr></thead><tbody>
    <?php foreach ($store->load('subscription_plans.json') as $pl): if (($pl['type']??'')!=='starlink'||empty($pl['is_active'])) continue; ?>
    <tr><td style="font-weight:600;"><?= h($pl['name']) ?></td><td style="text-align:right;font-weight:700;">$<?= number_format($pl['customer_price']??0, 2) ?>/mo</td></tr>
    <?php endforeach; ?>
    </tbody></table>

    <h4 style="margin:0 0 8px;color:#2E7D32;">&#128268; Fiber Plans</h4>
    <table class="kyc-table" style="margin-bottom:16px;"><thead><tr><th>Plan</th><th>Speed</th><th style="text-align:right;">Price</th></tr></thead><tbody>
    <?php foreach ($store->load('subscription_plans.json') as $pl): if (($pl['type']??'')!=='fiber'||empty($pl['is_active'])) continue; ?>
    <tr><td style="font-weight:600;"><?= h($pl['name']) ?></td><td><?= h($pl['speed']??'-') ?></td><td style="text-align:right;font-weight:700;">$<?= number_format($pl['customer_price']??0, 2) ?>/mo</td></tr>
    <?php endforeach; ?>
    </tbody></table>

    <h4 style="margin:0 0 8px;color:#E65100;">&#128241; SIM / Data Network Plans</h4>
    <table class="kyc-table"><thead><tr><th>Plan</th><th>Validity</th><th style="text-align:right;">Price</th></tr></thead><tbody>
    <?php foreach ($store->load('subscription_plans.json') as $pl): if (($pl['type']??'')!=='sim'||empty($pl['is_active'])) continue; ?>
    <tr><td style="font-weight:600;"><?= h($pl['name']) ?></td><td><?= h($pl['validity']??'30 days') ?></td><td style="text-align:right;font-weight:700;">$<?= number_format($pl['customer_price']??0, 2) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    </div></div>

    <div class="kyc-card"><div class="kyc-card-header" style="background:#fef2f2;color:#991b1b;">&#128680; 5. Common Errors & What To Do</div>
    <div style="padding:20px;font-size:13px;line-height:1.9;">
    <table class="kyc-table"><thead><tr><th>Problem</th><th>Cause</th><th>Solution</th></tr></thead><tbody>
    <tr><td style="font-weight:700;">"Insufficient balance"</td><td>Wallet too low</td><td>Go to <strong>Recharge Wallet</strong> → upload payment proof → wait for admin</td></tr>
    <tr><td style="font-weight:700;">KYC stuck on "pending_sync"</td><td>CRM server busy</td><td>Wait 5 minutes. System retries automatically. Ask admin if stuck &gt;30min</td></tr>
    <tr><td style="font-weight:700;">"Username exists"</td><td>Duplicate customer</td><td>Admin edits username in All Applications. Try again with different name</td></tr>
    <tr><td style="font-weight:700;">CRM search returns nothing</td><td>Customer not in CRM yet</td><td>Register them first via <strong>New KYC</strong>, then collect payment after sync</td></tr>
    <tr><td style="font-weight:700;">Payment shows "Pending" sync</td><td>CRM API not configured</td><td>Ask admin to set API token in Settings</td></tr>
    </tbody></table>
    </div></div>

    <div class="kyc-card"><div class="kyc-card-header" style="background:#f1f5f9;color:#475569;">&#128222; 6. Need Help?</div>
    <div style="padding:20px;font-size:13px;line-height:1.9;">
    <p>Contact your admin or DishNet support:</p>
    <table class="kyc-table">
    <tr><td style="font-weight:700;">WhatsApp</td><td>+211 XX XXX XXXX</td></tr>
    <tr><td style="font-weight:700;">Email</td><td>support@dishnetafrica.com</td></tr>
    <tr><td style="font-weight:700;">CRM Portal</td><td><a href="https://crm.dishnetafrica.com" target="_blank">crm.dishnetafrica.com</a></td></tr>
    </table>
    </div></div>


