<?php
// Tab: sim_cards
// Extracted from public.php on 2026-03-15
        $allSims = $store->load('sim_cards.json');
        $sk = ['available'=>0,'allocated'=>0,'activated'=>0,'suspended'=>0];
        foreach ($allSims as $s) { $st = $s['status'] ?? ''; if (isset($sk[$st])) $sk[$st]++; }
    ?>
    <div class="kyc-card">
        <div class="kyc-card-header"><i class="bi bi-sim-fill"></i> SIM Inventory Summary</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;padding:16px;">
            <div style="text-align:center;padding:12px;background:#f0fdf4;border-radius:8px;"><div style="font-size:24px;font-weight:700;color:#166534;"><?= count($allSims) ?></div><div style="font-size:11px;color:#6b7280;">Total</div></div>
            <div style="text-align:center;padding:12px;background:#f0fdf4;border-radius:8px;"><div style="font-size:24px;font-weight:700;color:#166534;"><?= $sk['available'] ?></div><div style="font-size:11px;color:#6b7280;">Available</div></div>
            <div style="text-align:center;padding:12px;background:#fefce8;border-radius:8px;"><div style="font-size:24px;font-weight:700;color:#854d0e;"><?= $sk['allocated'] ?></div><div style="font-size:11px;color:#6b7280;">Allocated</div></div>
            <div style="text-align:center;padding:12px;background:#eff6ff;border-radius:8px;"><div style="font-size:24px;font-weight:700;color:#1e40af;"><?= $sk['activated'] ?></div><div style="font-size:11px;color:#6b7280;">Activated</div></div>
        </div>
    </div>

    <div class="kyc-card">
        <div class="kyc-card-header"><i class="bi bi-upload"></i> Import SIMs (CSV)</div>
        <form method="POST" style="padding:16px;">
        <?= csrfField() ?>
            <input type="hidden" name="action" value="sim_inbound">
            <div style="margin-bottom:12px;">
                <label style="font-size:12px;font-weight:600;color:#6b7280;">CSV lines: iccid,msisdn,imsi,pin,puk</label>
                <textarea name="sim_csv" rows="4" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;font-family:monospace;font-size:12px;" placeholder="8923400012345678901,+211912345678,412010012345678,1234,12345678"></textarea>
            </div>
            <button type="submit" class="kyc-btn primary">Import SIMs</button>
        </form>
    </div>

    <div class="kyc-card">
        <div class="kyc-card-header"><i class="bi bi-arrow-right-circle"></i> Allocate SIMs to Retailer</div>
        <form method="POST" style="padding:16px;">
        <?= csrfField() ?>
            <input type="hidden" name="action" value="sim_allocate">
            <div class="resp-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div><label style="font-size:12px;font-weight:600;color:#6b7280;">SIM IDs (comma-separated)</label><input type="text" name="sim_ids" placeholder="1,2,3" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;"></div>
                <div><label style="font-size:12px;font-weight:600;color:#6b7280;">To Org ID</label><input type="number" name="to_org_id" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;"></div>
            </div>
            <div style="margin-bottom:12px;"><label style="font-size:12px;font-weight:600;color:#6b7280;">To Org Name</label><input type="text" name="to_org_name" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;"></div>
            <button type="submit" class="kyc-btn primary">Allocate</button>
        </form>
    </div>

    <div class="kyc-card">
        <div class="kyc-card-header"><i class="bi bi-table"></i> SIM Cards (<?= count($allSims) ?>)</div>
        <table class="kyc-table"><thead><tr><th>ID</th><th>ICCID</th><th>MSISDN</th><th>Status</th><th>Owner</th><th>Customer</th><th>Activated</th></tr></thead><tbody>
        <?php foreach (array_slice($allSims, 0, 100) as $s): $st = $s['status'] ?? ''; $bc = $st==='available'?'success':($st==='activated'?'primary':($st==='allocated'?'warning':'secondary')); ?>
        <tr><td><?= (int)($s['id'] ?? 0) ?></td><td><code style="font-size:11px;"><?= h($s['iccid'] ?? '') ?></code></td><td><?= h($s['msisdn'] ?? '') ?></td>
        <td><span class="kyc-badge <?= $bc ?>"><?= h($st) ?></span></td><td><?= h($s['owner_org_name'] ?? '') ?></td>
        <td><?= h($s['activated_customer_name'] ?? '-') ?></td><td><?= h($s['activated_at'] ?? '-') ?></td></tr>
        <?php endforeach; if (empty($allSims)): ?><tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:24px;">No SIM cards imported yet.</td></tr><?php endif; ?>
        </tbody></table>
    </div>

