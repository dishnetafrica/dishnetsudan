<?php
// ═══════════════════════════════════════════════════════════════
// CRM SYNC / ORG7 / FTTH
// ═══════════════════════════════════════════════════════════════


// ─── POST: sync_now (fallback for non-JS, does full page reload after) ───────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sync_now') {
    $auth->requireAdmin();
    set_time_limit(120);
    $result = runInProcessSync($queue, $crm, $store, $wallet, $notify, 20);
    $store->save('sync_last_run.json', [
        'ran_at'    => date('Y-m-d H:i:s'),
        'ran_by'    => $retailer['name'] ?? 'Admin',
        'lines'     => array_slice($result['log'], -20),
        'exit_code' => $result['failed'] > 0 && $result['success'] === 0 ? 1 : 0,
        'success'   => $result['success'],
        'failed'    => $result['failed'],
        'processed' => $result['processed'],
    ]);
    if ($result['processed'] === 0) {
        flash('✓ Queue is empty — nothing to sync.', 'success');
    } elseif ($result['failed'] === 0) {
        flash("✓ Synced {$result['success']} application(s) to UCRM successfully.", 'success');
    } else {
        flash("⚠ {$result['success']} synced, {$result['failed']} failed. Check the log below.", 'warning');
    }
    redirect('?page=dashboard&tab=sync_queue');
}



if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='ftth_sync_all'){
    $auth->requireAdmin();
    $result = $ftthCrm->syncAllRetailers();
    flash("FTTH sync complete — Linked:{$result['linked']} Created:{$result['created']} Failed:{$result['failed']}",
        $result['failed']===0?'success':'warning');
    redirect('?page=dashboard&tab=settings');
}
// ── Org 7 retailer sync — from retailers tab ──────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sync_org7_retailers') {
    $admin = $auth->requireAdmin();
    csrfCheck();

    // 1. Push: ensure every plugin retailer has a CRM Org-7 client + sync wallet balance
    $pushResult = $ftthCrm->syncAllRetailers();

    // 2. Pull: fetch all org-7 clients from CRM and cache locally for display
    $org7OrgId  = (int)($config['crm_ftth_org_id'] ?? 7);
    $org7Clients = [];
    $offset      = 0;
    do {
        $page = $crm->get('clients?' . http_build_query([
            'organizationId' => $org7OrgId,
            'limit'          => 100,
            'offset'         => $offset,
            'direction'      => 'ASC',
            'order'          => 'client.id',
        ]));
        if (!is_array($page)) break;
        foreach ($page as $c) {
            $org7Clients[] = [
                'id'          => (int)($c['id'] ?? 0),
                'name'        => trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? '')),
                'company'     => $c['companyName'] ?? '',
                'username'    => $c['username'] ?? '',
                'email'       => $c['contacts'][0]['email'] ?? '',
                'phone'       => $c['contacts'][0]['phone'] ?? '',
                'balance'     => (float)($c['accountBalance'] ?? 0),
                'is_active'   => (bool)($c['isActive'] ?? true),
                'is_lead'     => (bool)($c['isLead'] ?? false),
                'crm_url'     => rtrim(preg_replace('#(/crm)?/api/v[^/]*/?$#','',rtrim($config['crm_base_url']??'https://crm.dishnetafrica.com','/')), '/').'/crm/client/' . (int)($c['id'] ?? 0),
                'org_id'      => $org7OrgId,
                'synced_at'   => date('Y-m-d H:i:s'),
            ];
        }
        $offset += 100;
    } while (count($page ?? []) === 100);

    // Build lookup: crm_id → plugin retailer
    $retailers    = $store->load('retailers.json');
    $crmToPlugin  = [];
    foreach ($retailers as $r) {
        if (!empty($r['ftth_crm_client_id'])) {
            $crmToPlugin[(int)$r['ftth_crm_client_id']] = $r;
        }
    }

    // Annotate each org7 client with link status
    foreach ($org7Clients as &$c7) {
        $linked = $crmToPlugin[$c7['id']] ?? null;
        $c7['linked_plugin_id']   = $linked ? (int)$linked['id'] : null;
        $c7['linked_plugin_name'] = $linked ? $linked['name'] : null;
        $c7['linked_plugin_role'] = $linked ? ($linked['role'] ?? 'sales') : null;
    }
    unset($c7);

    $store->save('org7_crm_cache.json', [
        'fetched_at' => date('Y-m-d H:i:s'),
        'org_id'     => $org7OrgId,
        'clients'    => $org7Clients,
    ]);

    $pulled = count($org7Clients);
    flash(
        "CRM Org-7 sync complete — <strong>{$pushResult['linked']}</strong> linked, " .
        "<strong>{$pushResult['created']}</strong> created, " .
        "<strong>{$pushResult['failed']}</strong> failed push | " .
        "<strong>{$pulled}</strong> clients pulled from CRM.",
        $pushResult['failed'] === 0 ? 'success' : 'warning'
    );
    redirect('?page=dashboard&tab=retailers&subtab=org7');
}

// ── Record debt recovery (accountant deducts from retailer's outstanding wallet) ──
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='record_debt_recovery') {
    $accountant = $auth->requireLogin();
    csrfCheck();
    if (!$can('accounts_dash') && !($accountant['is_admin']??false))
        { flash('Access denied.', 'danger'); redirect('?page=dashboard'); }

    $retailerId = (int)($_POST['retailer_id'] ?? 0);
    $amount     = round((float)($_POST['amount'] ?? 0), 2);
    $method     = trim($_POST['recovery_method'] ?? 'cash');
    $note       = trim($_POST['note'] ?? '');
    if ($retailerId <= 0 || $amount <= 0)
        { flash('Invalid recovery amount.', 'danger'); redirect('?page=dashboard&tab=accounts_dashboard'); }

    $target = null;
    foreach ($auth->getAllRetailers() as $r) {
        if ((int)$r['id'] === $retailerId) { $target = $r; break; }
    }
    if (!$target) { flash('Retailer not found.', 'danger'); redirect('?page=dashboard&tab=accounts_dashboard'); }

    $currentDebt = (float)($target['wallet'] ?? 0);

    $newDebt = round(max(0, $currentDebt - $amount), 2);
    $store->updateOne('retailers.json', 'id', $retailerId, [
        'wallet'             => $newDebt,
        'crm_outstanding'    => $newDebt,
        'crm_outstanding_at' => date('Y-m-d H:i:s'),
    ]);

    // ── Plugin passbook entry
    $wallet->credit($retailerId, -$amount,
        "Cash recovery [{$method}] by {$accountant['name']}" . ($note ? " — {$note}" : ''),
        'debt_recovery', $accountant['name']);

    // ── Post payment to CRM Org-7 against this retailer's open invoices
    //    Dr Cash / Cr Justus Receivable — closes the loop in UCRM
    $crmPayResult   = null;
    $crmPaySuccess  = false;
    $crmRetailerId  = (int)($target['ftth_crm_client_id'] ?? 0);
    if ($crmRetailerId && $crm->isConfigured()) {
        $crmPayload = [
            'clientId'                       => $crmRetailerId,
            'methodId'     => PaymentUuids::resolve($method),
            'amount'                         => $amount,
            'note'                           => "Cash recovery — {$accountant['name']}" . ($note ? ": {$note}" : ''),
            'applyToInvoicesAutomatically'   => true,  // let UCRM apply to oldest unpaid first
        ];
        $crmPayResult  = $crm->post('payments', $crmPayload);
        $crmPaySuccess = !empty($crmPayResult) && isset($crmPayResult['id']);
    }

    // ── Audit log
    $store->appendTo('audit_log.json', [
        'action'         => 'debt_recovery',
        'retailer_id'    => $retailerId,
        'retailer'       => $target['name'],
        'amount'         => $amount,
        'method'         => $method,
        'note'           => $note,
        'prev_debt'      => $currentDebt,
        'new_debt'       => $newDebt,
        'crm_client_id'  => $crmRetailerId ?: null,
        'crm_payment_id' => $crmPayResult['id'] ?? null,
        'crm_synced'     => $crmPaySuccess,
        'by'             => $accountant['name'],
        'at'             => date('Y-m-d H:i:s'),
    ]);

    $crmMsg = $crmPaySuccess
        ? ' CRM payment #' . ($crmPayResult['id'] ?? '?') . ' posted — invoice(s) auto-applied.'
        : ($crmRetailerId ? ' ⚠ CRM payment post failed — record manually in CRM.' : '');

    flash(
        "<strong>\${$amount}</strong> recovered from <strong>{$target['name']}</strong>. " .
        "Remaining: <strong>\${$newDebt}</strong>." . $crmMsg,
        $crmPaySuccess || !$crmRetailerId ? 'success' : 'warning'
    );
    redirect('?page=dashboard&tab=accounts_dashboard');
}

// ── Pull CRM Org-7 balances → plugin wallets ──────────────────────────────
// For each retailer that has a CRM link, reads their CRM accountBalance
// and OVERWRITES the plugin wallet with abs(crmBalance) since negative CRM
// balance = they owe DishNet = that's their wallet debt.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sync_org7_wallets') {
    $admin = $auth->requireAdmin();
    csrfCheck();

    // mode=preview → read CRM, show table, no writes
    // mode=apply   → read CRM, write crm_outstanding on each retailer (NOT wallet)
    //                wallet stays as pure agent float; crm_outstanding = debt from CRM invoices
    $mode      = trim($_POST['sync_mode'] ?? 'preview');
    $retailers = $store->load('retailers.json');
    $report    = [];
    $updated   = 0;

    foreach ($retailers as $r) {
        $crmId = (int)($r['ftth_crm_client_id'] ?? 0);
        if (!$crmId) continue;

        $crmClient = $crm->get("clients/{$crmId}");
        if (!$crmClient) {
            $report[] = [
                'id'         => $r['id'],
                'name'       => $r['name'],
                'crm_id'     => $crmId,
                'crm_bal'    => null,
                'plugin_wal' => (float)($r['wallet'] ?? 0),
                'prev_debt'  => (float)($r['crm_outstanding'] ?? 0),
                'owes_amt'   => 0.0,
                'owes'       => false,
                'changed'    => false,
                'error'      => true,
            ];
            continue;
        }

        $crmBal    = (float)($crmClient['accountBalance'] ?? 0);
        // UCRM: negative balance = retailer owes DishNet (unpaid top-up invoices)
        $owesAmt   = $crmBal < 0 ? round(-$crmBal, 2) : 0.0;
        $prevDebt  = (float)($r['crm_outstanding'] ?? 0);
        $changed   = abs($prevDebt - $owesAmt) > 0.005;

        $report[] = [
            'id'         => $r['id'],
            'name'       => $r['name'],
            'crm_id'     => $crmId,
            'crm_bal'    => $crmBal,
            'plugin_wal' => (float)($r['wallet'] ?? 0),
            'prev_debt'  => $prevDebt,
            'owes_amt'   => $owesAmt,
            'owes'       => $owesAmt > 0.005,
            'changed'    => $changed,
            'error'      => false,
        ];

        if ($mode === 'apply') {
            // Write both wallet AND crm_outstanding so all panels work correctly
            // wallet     = the live outstanding amount (what Justus owes right now)
            // crm_outstanding = same value, separate tracking field
            $store->updateOne('retailers.json', 'id', (int)$r['id'], [
                'wallet'             => $owesAmt,   // ← this is what the system reads everywhere
                'crm_outstanding'    => $owesAmt,   // ← accountant panels read this
                'crm_outstanding_at' => date('Y-m-d H:i:s'),
                'crm_bal_raw'        => $crmBal,
            ]);
            // Log to passbook so there's a visible audit trail
            $prevWal = (float)($r['wallet'] ?? 0);
            if (abs($prevWal - $owesAmt) > 0.005) {
                $diff = $owesAmt - $prevWal;
                $wallet->credit((int)$r['id'], $diff,
                    "CRM balance sync — CRM #{$crmId}: outstanding \${$owesAmt}",
                    'crm_sync', 'system'
                );
            }
            $updated++;
        }
    }

    usort($report, function($a, $b) {
        if ($a['error'] !== $b['error']) return $a['error'] ? 1 : -1;
        return ($b['owes_amt'] ?? 0) <=> ($a['owes_amt'] ?? 0);
    });

    $_SESSION['org7_wallet_report'] = $report;

    // Always persist cache for accountant panels
    $store->save('org7_crm_balance_cache.json', [
        'checked_at' => date('Y-m-d H:i:s'),
        'checked_by' => $admin['name'] ?? 'Admin',
        'applied'    => $mode === 'apply',
        'report'     => $report,
    ]);

    $debtors   = count(array_filter($report, fn($x) => $x['owes'] ?? false));
    $totalOwed = array_sum(array_column(array_filter($report, fn($x) => $x['owes'] ?? false), 'owes_amt'));
    $changed   = count(array_filter($report, fn($x) => ($x['changed'] ?? false) && !($x['error'] ?? false)));

    if ($mode === 'apply') {
        flash(
            "Sync applied — <strong>{$updated}</strong> retailers updated. " .
            "<strong>{$debtors}</strong> have outstanding debt totalling <strong>\$" . number_format($totalOwed, 2) . "</strong>.",
            $debtors > 0 ? 'warning' : 'success'
        );
    } else {
        flash(
            "Preview ready — <strong>{$changed}</strong> retailer(s) will change. " .
            "<strong>{$debtors}</strong> owe a total of <strong>\$" . number_format($totalOwed, 2) . "</strong>. " .
            "Review below then click <strong>Apply</strong>.",
            $debtors > 0 ? 'warning' : 'success'
        );
    }
    redirect('?page=dashboard&tab=retailers&subtab=org7');
}

// ── Import / Link a single CRM Org-7 client into plugin ──────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='import_org7_client') {
    $admin = $auth->requireAdmin();
    csrfCheck();

    $crmId    = (int)($_POST['crm_id']   ?? 0);
    $crmName  = trim($_POST['crm_name']  ?? '');
    $crmEmail = strtolower(trim($_POST['crm_email'] ?? ''));
    $crmPhone = trim($_POST['crm_phone'] ?? '');
    $role     = trim($_POST['role']      ?? 'sales');
    $isAdmin  = !empty($_POST['is_admin']);
    $password = trim($_POST['password']  ?? '');
    $mode     = trim($_POST['mode']      ?? 'create'); // 'create' | 'link'
    $linkToId = (int)($_POST['link_to_plugin_id'] ?? 0);

    if (!$crmId || !$crmName) {
        flash('CRM ID and name are required.', 'danger');
        redirect('?page=dashboard&tab=retailers&subtab=org7');
    }

    if ($mode === 'link' && $linkToId > 0) {
        // Just link an existing plugin retailer to this CRM client
        $ftthCrm->linkRetailer($linkToId, $crmId);
        $linkedR = $store->findOne('retailers.json', 'id', $linkToId);
        flash("Linked <strong>" . h($linkedR['name'] ?? "#$linkToId") . "</strong> → CRM #$crmId.", 'success');
        redirect('?page=dashboard&tab=retailers&subtab=org7');
    }

    // CREATE mode — make a new plugin retailer from this CRM client
    if (!$crmEmail) $crmEmail = 'crm' . $crmId . '@dishnetafrica.com';

    $existing = $store->findOne('retailers.json', 'email', $crmEmail);
    if ($existing) {
        // Email already exists — just link
        $ftthCrm->linkRetailer((int)$existing['id'], $crmId);
        flash("Plugin account for <strong>" . h($crmEmail) . "</strong> already existed — linked to CRM #$crmId.", 'warning');
        redirect('?page=dashboard&tab=retailers&subtab=org7');
    }

    if (!$password) $password = 'DishNet' . $crmId . '@2026';

    $newR = $auth->createRetailer([
        'name'            => $crmName,
        'email'           => $crmEmail,
        'phone'           => $crmPhone,
        'password'        => $password,
        'wallet'          => 0,
        'is_admin'        => $isAdmin,
        'is_field_agent'  => ($role === 'field_agent'),
        'role'            => $role,
    ]);
    // Link to CRM Org-7
    $ftthCrm->linkRetailer((int)$newR['id'], $crmId);

    flash(
        "✅ Created plugin account for <strong>" . h($crmName) . "</strong> as <strong>{$role}</strong>. " .
        "Temp password: <code>{$password}</code> — share this securely.",
        'success'
    );
    redirect('?page=dashboard&tab=retailers&subtab=org7');
}

// ── Bulk import all unlinked Org-7 clients ────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='bulk_import_org7') {
    $admin = $auth->requireAdmin();
    csrfCheck();

    $defaultRole    = trim($_POST['default_role']    ?? 'sales');
    $defaultIsAdmin = !empty($_POST['default_is_admin']);
    $cache          = $store->load('org7_crm_cache.json');
    $org7Clients    = $cache['clients'] ?? [];

    $created = $linked = $skipped = 0;
    $passwords = [];

    foreach ($org7Clients as $c7) {
        if (!empty($c7['linked_plugin_id'])) { $skipped++; continue; } // already linked

        $email = strtolower(trim($c7['email'] ?? ''));
        if (!$email) $email = 'crm' . $c7['id'] . '@dishnetafrica.com';

        $existing = $store->findOne('retailers.json', 'email', $email);
        if ($existing) {
            $ftthCrm->linkRetailer((int)$existing['id'], (int)$c7['id']);
            $linked++;
        } else {
            $pw = 'DishNet' . $c7['id'] . '@2026';
            $newR = $auth->createRetailer([
                'name'           => $c7['name'] ?: ('CRM#' . $c7['id']),
                'email'          => $email,
                'phone'          => $c7['phone'] ?? '',
                'password'       => $pw,
                'wallet'         => 0,
                'is_admin'       => $defaultIsAdmin,
                'is_field_agent' => ($defaultRole === 'field_agent'),
                'role'           => $defaultRole,
            ]);
            $ftthCrm->linkRetailer((int)$newR['id'], (int)$c7['id']);
            $passwords[] = h($c7['name']) . ': <code>' . $pw . '</code>';
            $created++;
        }
    }

    $msg = "Bulk import done — <strong>{$created}</strong> created, <strong>{$linked}</strong> linked by email, <strong>{$skipped}</strong> skipped (already linked).";
    if ($passwords) $msg .= '<br><small style="font-size:11px;">Temp passwords — ' . implode(' | ', array_slice($passwords, 0, 10)) . ($created > 10 ? ' …' : '') . '</small>';
    flash($msg, $created + $linked > 0 ? 'success' : 'info');
    redirect('?page=dashboard&tab=retailers&subtab=org7');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save_plan'){
    $auth->requireAdmin();
    $plans = $store->load('subscription_plans.json');
    $planId = (int)($_POST['plan_id'] ?? 0);
    $plan = [
        'name'           => trim($_POST['plan_name'] ?? ''),
        'type'           => trim($_POST['plan_type'] ?? 'starlink'),
        'starlink_cost'  => round((float)($_POST['starlink_cost'] ?? 0), 2),
        'customer_price' => round((float)($_POST['customer_price'] ?? 0), 2),
        'is_active'      => isset($_POST['plan_active']) ? true : false,
        'color'          => trim($_POST['plan_color'] ?? '#2196F3'),
        'updated_at'     => date('Y-m-d H:i:s'),
    ];
    $plan['profit']  = round($plan['customer_price'] - $plan['starlink_cost'], 2);
    $plan['margin']  = $plan['customer_price'] > 0 ? round(($plan['profit'] / $plan['customer_price']) * 100, 1) : 0;

    $existingUcrmId = 0;
    if ($planId > 0) {
        foreach ($plans as &$p) {
            if ((int)($p['id']??0)===$planId) {
                $existingUcrmId = (int)($p['ucrm_product_id'] ?? 0);
                $p = array_merge($p, $plan);
                break;
            }
        }
        unset($p);
    } else {
        $plan['id'] = $store->nextId('subscription_plans.json');
        $plan['created_at'] = date('Y-m-d H:i:s');
        $plans[] = $plan;
        $planId = $plan['id'];
    }

    // ── Auto-sync to UCRM products ──────────────────────────────────────────
    // Create product in UCRM if new, update name/price if already mapped.
    $ucrmMsg = '';
    try {
        require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
        $crmSync = CrmApiClient::fromUcrm(dirname(__DIR__), $config ?? []);
        if ($crmSync->isConfigured()) {
            $productPayload = [
                'name'         => $plan['name'],
                'invoiceLabel' => $plan['name'],
                'unit'         => 'Month',
                'price'        => (float)$plan['customer_price'],
                'taxable'      => false,
            ];
            if ($existingUcrmId > 0) {
                // PATCH existing product — update name + price
                $crmSync->patch("products/{$existingUcrmId}", $productPayload);
                $ucrmMsg = ' UCRM product #' . $existingUcrmId . ' updated.';
                // Write ucrm_product_id back (already there, but keep it)
                foreach ($plans as &$p2) {
                    if ((int)($p2['id']??0) === $planId) { $p2['ucrm_product_id'] = $existingUcrmId; break; }
                }
                unset($p2);
            } else {
                // POST new product
                $resp = $crmSync->post('products', $productPayload);
                if (!empty($resp['id'])) {
                    $newProdId = (int)$resp['id'];
                    foreach ($plans as &$p2) {
                        if ((int)($p2['id']??0) === $planId) { $p2['ucrm_product_id'] = $newProdId; break; }
                    }
                    unset($p2);
                    $ucrmMsg = ' UCRM product #' . $newProdId . ' created.';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[save_plan] UCRM sync failed: ' . $e->getMessage());
    }
    // ── End UCRM sync ───────────────────────────────────────────────────────

    $store->save('subscription_plans.json', $plans);
    flash(($planId && !isset($plan['created_at']) ? 'Plan updated.' : 'Plan created.') . $ucrmMsg, 'success');
    redirect('?page=dashboard&tab=subscription_plans');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save_hardware'){
    $auth->requireAdmin();
    $hw = $store->load('kyc_devices.json');
    $hwId = (int)($_POST['hw_id'] ?? 0);
    $item = [
        'title'       => trim($_POST['hw_title'] ?? ''),
        'type'        => trim($_POST['hw_type'] ?? 'starlink'),
        'buy_price'   => round((float)($_POST['hw_buy'] ?? 0), 2),
        'sell_price'  => round((float)($_POST['hw_sell'] ?? 0), 2),
        'price'       => 'USD ' . number_format((float)($_POST['hw_sell'] ?? 0), 0),
        'is_active'   => isset($_POST['hw_active']),
        'sku'         => trim($_POST['hw_sku'] ?? ''),
        'description' => trim($_POST['hw_description'] ?? ''),
    ];
    // Manual UCRM Product ID override — if user typed one, use it
    $manualUcrmId = (int)($_POST['hw_ucrm_product_id'] ?? 0);

    $existingHwUcrmId = 0;
    $savedHwId = $hwId;
    if ($hwId > 0) {
        foreach ($hw as &$h2) {
            if ((int)($h2['id']??0)===$hwId) {
                $existingHwUcrmId = $manualUcrmId > 0 ? $manualUcrmId : (int)($h2['ucrm_product_id'] ?? 0);
                if ($manualUcrmId > 0) $item['ucrm_product_id'] = $manualUcrmId;
                $h2 = array_merge($h2, $item);
                break;
            }
        }
        unset($h2);
    } else {
        if ($manualUcrmId > 0) {
            $item['ucrm_product_id'] = $manualUcrmId;
            $existingHwUcrmId = $manualUcrmId;
        }
        $item['id'] = $store->nextId('kyc_devices.json');
        $item['created_at'] = date('Y-m-d H:i:s');
        $hw[] = $item;
        $savedHwId = $item['id'];
    }

    // ── Auto-sync to UCRM products ──────────────────────────────────────────
    $hwUcrmMsg = '';
    try {
        require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
        $crmHw = CrmApiClient::fromUcrm(dirname(__DIR__), $config ?? []);
        if ($crmHw->isConfigured()) {
            $hwPayload = [
                'name'         => $item['title'],
                'invoiceLabel' => $item['title'],
                'unit'         => 'Nos',
                'price'        => (float)$item['sell_price'],
                'taxable'      => false,
            ];
            if ($existingHwUcrmId > 0) {
                $crmHw->patch("products/{$existingHwUcrmId}", $hwPayload);
                $hwUcrmMsg = ' UCRM product #' . $existingHwUcrmId . ' updated.';
                foreach ($hw as &$h3) {
                    if ((int)($h3['id']??0) === $savedHwId) { $h3['ucrm_product_id'] = $existingHwUcrmId; break; }
                }
                unset($h3);
            } else {
                $hwResp = $crmHw->post('products', $hwPayload);
                if (!empty($hwResp['id'])) {
                    $newHwProdId = (int)$hwResp['id'];
                    foreach ($hw as &$h3) {
                        if ((int)($h3['id']??0) === $savedHwId) { $h3['ucrm_product_id'] = $newHwProdId; break; }
                    }
                    unset($h3);
                    $hwUcrmMsg = ' UCRM product #' . $newHwProdId . ' created.';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[save_hardware] UCRM sync failed: ' . $e->getMessage());
    }
    // ── End UCRM sync ───────────────────────────────────────────────────────

    $store->save('kyc_devices.json', $hw);
    flash(($hwId > 0 ? 'Hardware updated.' : 'Hardware added.') . $hwUcrmMsg, 'success');
    redirect('?page=dashboard&tab=hardware');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='delete_hardware'){
    $auth->requireAdmin();
    $delId = (int)($_POST['hw_id'] ?? 0);
    $hw = array_values(array_filter($store->load('kyc_devices.json'), fn($h2) => (int)($h2['id']??0)!==$delId));
    $store->save('kyc_devices.json', $hw);
    flash('Hardware deleted.','success');
    redirect('?page=dashboard&tab=hardware');
}
// ── Save Fiber Installation Fee ─────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='save_install_fee'){
    $auth->requireAdmin();
    $cfg = $store->load('kyc_config.json') ?? [];
    $cfg['fiber_install_fee']        = max(0, (float)($_POST['fiber_install_fee'] ?? 100));
    $cfg['fiber_install_product_id'] = max(0, (int)($_POST['fiber_install_product_id'] ?? 244));
    $store->save('kyc_config.json', $cfg);
    flash('✅ Fiber installation fee updated to $' . number_format($cfg['fiber_install_fee'], 2) . ' (UCRM Product #' . $cfg['fiber_install_product_id'] . ').', 'success');
    redirect('?page=dashboard&tab=hardware');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='delete_plan'){
    $auth->requireAdmin();
    $delId = (int)($_POST['plan_id'] ?? 0);
    $plans = array_values(array_filter($store->load('subscription_plans.json'), fn($p) => (int)($p['id']??0)!==$delId));
    $store->save('subscription_plans.json', $plans);
    flash('Plan deleted.','success');
    redirect('?page=dashboard&tab=subscription_plans');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sim_inbound') {
    $me = $auth->currentRetailer();
    if ($me && !empty($me['is_admin'])) {
        $lines = array_filter(explode("\n", trim($_POST['sim_csv'] ?? '')));
        $sims = [];
        foreach ($lines as $l) {
            $p = array_map('trim', str_getcsv($l));
            if (count($p) >= 2) $sims[] = ['iccid'=>$p[0],'msisdn'=>$p[1],'imsi'=>$p[2]??'','pin'=>$p[3]??'','puk'=>$p[4]??''];
        }
        if ($sims) { $r = $sim->inboundBatch($sims); flash($r['imported'].' SIMs imported, '.$r['skipped'].' skipped.'); }
        else { flash('No valid SIM data.', 'danger'); }
    }
    redirect('?page=dashboard&tab=sim_cards');
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sim_allocate') {
    $me = $auth->currentRetailer();
    if ($me && !empty($me['is_admin'])) {
        $ids = array_map('intval', array_filter(explode(',', ($_POST['sim_ids'] ?? ''))));
        $toId = (int)($_POST['to_org_id'] ?? 0);
        $toNm = $_POST['to_org_name'] ?? '';
        if ($ids && $toId > 0 && $toNm) { $r = $sim->allocate($ids, $toId, $toNm, (int)$me['id']); flash($r['allocated'].' SIMs allocated.'); }
        else { flash('Missing fields.', 'danger'); }
    }
    redirect('?page=dashboard&tab=sim_cards');
}


// ─── RETAILER: Edit ───────────────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='edit_retailer'){
    $auth->requireAdmin();
    $rId=(int)($_POST['retailer_id']??0);
    if(!$rId){flash('Invalid retailer.','danger');redirect('?page=dashboard&tab=retailers');}
    $target=$store->findOne('retailers.json','id',$rId);
    if(!$target){flash('Retailer not found.','danger');redirect('?page=dashboard&tab=retailers');}
    
    // Get role_id (new RBAC) or fall back to legacy role slug
    $roleId = !empty($_POST['role_id']) ? (int)$_POST['role_id'] : null;
    $roleSlug = $_POST['role'] ?? 'sales';
    
    $updates=[
        'name'       =>trim($_POST['name']??''),
        'email'      =>strtolower(trim($_POST['email']??'')),
        'phone'      =>trim($_POST['phone']??''),
        'is_admin'   =>!empty($_POST['is_admin']),
        'is_active'  =>!empty($_POST['is_active']),
        'on_leave'   =>!empty($_POST['on_leave']),
        'role'       =>$roleSlug,
        'role_id'    =>$roleId,
        'ucrm_user_id'=>(int)($_POST['ucrm_user_id']??0) ?: null,
        'ucrm_app_key'=>trim($_POST['ucrm_app_key']??'') ?: null,
        'project'=>trim($_POST['project']??'') ?: 'dishnet',
        'projects'=>!empty($_POST['projects']) ? array_values(array_filter(array_map('trim', (array)$_POST['projects']))) : ['dishnet'],
        'carry_limit'=>(int)($_POST['carry_limit']??0) ?: null,
    ];
    // Only update password if a new one was supplied
    $newPw=trim($_POST['password']??'');
    if($newPw!=='') $updates['password']=password_hash($newPw,PASSWORD_BCRYPT);
    // Prevent demoting the only remaining admin
    if($target['is_admin']&&!$updates['is_admin']){
        $admins=array_filter($store->load('retailers.json'),fn($r)=>($r['is_admin']??false));
        if(count($admins)<=1){flash('Cannot remove the last admin.','danger');redirect('?page=dashboard&tab=retailers');}
    }
    $store->updateOne('retailers.json','id',$rId,$updates);
    flash('Retailer <strong>'.htmlspecialchars($updates['name']).'</strong> updated.','success');
    redirect('?page=dashboard&tab=retailers');
}

// ─── RETAILER: Save module permissions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_modules') {
    $auth->requireAdmin();
    $rId = (int)($_POST['retailer_id'] ?? 0);
    if (!$rId) { flash('Invalid retailer.','danger'); redirect('?page=dashboard&tab=retailers'); }
    $target = $store->findOne('retailers.json','id',$rId);
    if (!$target) { flash('Retailer not found.','danger'); redirect('?page=dashboard&tab=retailers'); }
    // Admins always have full access — no point restricting
    if ($target['is_admin'] ?? false) {
        flash('Admins always have full access — module restrictions do not apply.','warning');
        redirect('?page=dashboard&tab=retailers');
    }
    $posted  = $_POST['modules'] ?? [];  // array of module IDs
    $cleaned = array_values(array_filter(array_map('trim', (array)$posted)));
    $store->updateOne('retailers.json','id',$rId,['modules' => $cleaned]);
    $name = htmlspecialchars($target['name']);
    $count = count($cleaned);
    flash("✓ Module access for <strong>{$name}</strong> updated — {$count} module" . ($count!==1?'s':'') . " enabled.", 'success');
    redirect('?page=dashboard&tab=retailers');
}

// ─── RETAILER: Delete ─────────────────────────────────────────────────────────
// ── Reassign all open leads from an on-leave agent to another agent ───────────
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='reassign_agent_leads'){
    $auth->requireAdmin();
    $fromId = (int)($_POST['from_retailer_id'] ?? 0);
    $toId   = (int)($_POST['to_retailer_id']   ?? 0);
    if (!$fromId || !$toId) { flash('Both agents required.','danger'); redirect('?page=dashboard&tab=retailers'); }
    $fromR  = $store->findOne('retailers.json','id',$fromId);
    $toR    = $store->findOne('retailers.json','id',$toId);
    if (!$fromR || !$toR) { flash('Agent not found.','danger'); redirect('?page=dashboard&tab=retailers'); }
    $leads   = $store->load('leads.json') ?? [];
    $changed = 0;
    $openStatuses = ['open','contacted','interested','quoted','qualified'];
    foreach ($leads as &$l) {
        $isOwned    = (int)($l['retailer_id']??0) === $fromId;
        $isAssigned = (int)($l['assigned_to']??0) === $fromId;
        $isOpen     = in_array($l['status']??'', $openStatuses, true);
        if (($isOwned || $isAssigned) && $isOpen) {
            $l['assigned_to']   = $toId;
            $l['assigned_name'] = $toR['name'];
            $l['assigned_at']   = date('Y-m-d H:i:s');
            $l['assigned_by']   = ($retailer['name'] ?? 'Admin') . ' (leave cover)';
            if (!isset($l['history'])) $l['history'] = [];
            $l['history'][] = ['status'=>$l['status']??'open','by'=>$retailer['name']??'Admin','at'=>date('Y-m-d H:i:s'),
                               'note'=>"Reassigned from {$fromR['name']} (on leave) to {$toR['name']}"];
            $changed++;
        }
    }
    unset($l);
    $store->save('leads.json', $leads);
    if ($changed > 0 && !empty($toR['phone'])) {
        $notify->sendRaw($toR['phone'],
            "Hi {$toR['name']}, {$changed} lead(s) transferred from {$fromR['name']} (on leave). Check your leads in Operations Hub.",
            'ops_lead_reassignment');
    }
    flash("✅ {$changed} open lead(s) reassigned from {$fromR['name']} to {$toR['name']}.",'success');
    redirect('?page=dashboard&tab=retailers');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='delete_retailer'){
    $auth->requireAdmin();
    $rId=(int)($_POST['retailer_id']??0);
    if(!$rId){flash('Invalid retailer.','danger');redirect('?page=dashboard&tab=retailers');}
    $target=$store->findOne('retailers.json','id',$rId);
    if(!$target){flash('Retailer not found.','danger');redirect('?page=dashboard&tab=retailers');}
    // Prevent deleting self
    $me=$auth->currentRetailer();
    if($me&&$me['id']===$rId){flash('You cannot delete your own account.','danger');redirect('?page=dashboard&tab=retailers');}
    // Prevent deleting last admin
    if($target['is_admin']??false){
        $admins=array_filter($store->load('retailers.json'),fn($r)=>($r['is_admin']??false));
        if(count($admins)<=1){flash('Cannot delete the last admin account.','danger');redirect('?page=dashboard&tab=retailers');}
    }
    $all=array_values(array_filter($store->load('retailers.json'),fn($r)=>$r['id']!==$rId));
    $store->save('retailers.json',$all);
    flash('Retailer <strong>'.htmlspecialchars($target['name']).'</strong> deleted.','success');
    redirect('?page=dashboard&tab=retailers');
}

// ─── BACKUP: Download all data as ZIP ────────────────────────────────────────
// ─── BACKUP: Download auto-backup zip ────────────────────────────────────────
if (($_GET['action'] ?? '') === 'download_auto_backup') {
    $auth->requireAdmin();
    $reqFile = basename($_GET['file'] ?? '');
    if (!preg_match('/^pre-restore-auto-backup-[\d_-]+\.zip$/', $reqFile)) {
        flash('Invalid backup file requested.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }
    $fullPath = $dataDir . '/' . $reqFile;
    if (!file_exists($fullPath)) {
        flash('Backup file not found.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $reqFile . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: no-cache');
    readfile($fullPath);
    exit;
}

if (($_GET['action'] ?? '') === 'download_backup') {
    $auth->requireAdmin();

    $timestamp  = date('Y-m-d_H-i-s');
    $pluginRoot = dirname($dataDir);
    // Read version from manifest.json
    $mfJson = @json_decode(@file_get_contents($pluginRoot . '/manifest.json'), true);
    $pluginVer = $mfJson['information']['version'] ?? 'unknown';
    $zipName    = "dishnet-hybrid-v{$pluginVer}-backup-{$timestamp}.zip";
    $tmpZip     = sys_get_temp_dir() . '/' . $zipName;

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        flash('Could not create backup ZIP file.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    $included = 0;

    // ── 1. ALL PLUGIN CODE (makes ZIP deployable as UCRM plugin) ─────────
    // Recursively add all files from plugin root, excluding data/ dir
    $skipDirs = ['data', '.git', 'node_modules', '__MACOSX'];
    $codeIter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pluginRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($codeIter as $item) {
        $relative = substr((string)$item, strlen($pluginRoot) + 1);
        // Skip data directory and hidden/system dirs
        $topDir = explode('/', str_replace('\\', '/', $relative))[0];
        if (in_array($topDir, $skipDirs, true)) continue;
        if (strpos($relative, '.') === 0) continue; // hidden files
        if ($item->isFile()) {
            $zip->addFile((string)$item, $relative);
            $included++;
        }
    }

    // ── 2. DATA: JSON files ──────────────────────────────────────────────
    foreach (glob($dataDir . '/*.json') as $f) {
        $base = basename($f);
        if (strpos($base, '.') === 0) continue;
        if ($base === 'gdrive_config.json') continue; // OAuth tokens — security
        $zip->addFile($f, 'data/' . $base);
        $included++;
    }

    // ── 3. DATA: SQLite databases ────────────────────────────────────────
    $sqliteDbs = ['plugin.sqlite3', 'dishnet.sqlite'];
    foreach ($sqliteDbs as $dbFile) {
        $dbPath = $dataDir . '/' . $dbFile;
        if (!file_exists($dbPath)) continue;
        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->exec('PRAGMA wal_checkpoint(PASSIVE)');
            $pdo = null;
        } catch (Throwable $e) {}
        $tmpCopy = sys_get_temp_dir() . '/backup_' . $dbFile;
        if (@copy($dbPath, $tmpCopy)) {
            $zip->addFile($tmpCopy, 'data/' . $dbFile);
            $included++;
            if (file_exists($dbPath . '-wal') && @copy($dbPath . '-wal', $tmpCopy . '-wal')) {
                $zip->addFile($tmpCopy . '-wal', 'data/' . $dbFile . '-wal');
            }
        }
    }

    // ── 4. DATA: Uploads (expense photos, receipts) ──────────────────────
    $uploadsDir = $dataDir . '/uploads';
    if (is_dir($uploadsDir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            $relative = 'data/uploads/' . $iter->getSubPathName();
            $zip->addFile((string)$file, $relative);
        }
    }

    // ── 5. CERTIFICATES (Magma mTLS) ─────────────────────────────────────
    $certsDir = $pluginRoot . '/certs';
    if (is_dir($certsDir)) {
        $certIter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($certsDir, FilesystemIterator::SKIP_DOTS));
        foreach ($certIter as $cf) {
            $zip->addFile((string)$cf, 'certs/' . $certIter->getSubPathName());
        }
    }

    // ── 6. BACKUP MANIFEST ───────────────────────────────────────────────
    $manifest = json_encode([
        'plugin'      => 'dishnet-hybrid-telecom',
        'version'     => $pluginVer,
        'type'        => 'full_deployable',
        'created_at'  => date('Y-m-d H:i:s'),
        'files'       => $included,
        'note'        => 'Full deployable backup. Upload to UCRM Plugins to restore code + data + certs.',
    ], JSON_PRETTY_PRINT);
    $zip->addFromString('BACKUP_MANIFEST.json', $manifest);
    $zip->close();

    // Cleanup temp SQLite copies
    foreach ($sqliteDbs as $dbFile) {
        $tmpCopy = sys_get_temp_dir() . '/backup_' . $dbFile;
        @unlink($tmpCopy);
        @unlink($tmpCopy . '-wal');
    }

    // Stream to browser
    if (file_exists($tmpZip)) {
        logActivity($dataDir,'backup_created','Backup downloaded',$zipName.' ('.round(filesize($tmpZip)/1024).'KB)');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($tmpZip));
        header('Cache-Control: no-cache');
        readfile($tmpZip);
        @unlink($tmpZip);
        exit;
    }
    flash('Backup generation failed — no ZIP created.', 'danger');
    redirect('?page=dashboard&tab=backup');
}

// ─── BACKUP: Restore from uploaded ZIP ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_backup') {
    $admin = $auth->requireAdmin();

    $file = $_FILES['backup_zip'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        flash('Upload failed — please select a valid .zip backup file.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['application/zip','application/x-zip','application/x-zip-compressed','application/octet-stream'], true)
        && strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'zip') {
        flash('Invalid file type — only .zip backups are accepted.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        flash('Could not open ZIP file — it may be corrupted.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    // ── Detect backup type ──────────────────────────────────────────────
    // Type A: Plugin backup (BACKUP_MANIFEST.json, files at root)
    // Type B: Google Drive DATA zip (RESTORE_INSTRUCTIONS.txt, files under data/)
    $isTypeA = $zip->locateName('BACKUP_MANIFEST.json') !== false;
    $isTypeB = $zip->locateName('RESTORE_INSTRUCTIONS.txt') !== false;

    if (!$isTypeA && !$isTypeB) {
        // Type C: pre-restore auto-backup (has JSON + SQLite at root, no manifest)
        // Check if it has at least one known JSON file at root
        $_hasKnownFile = false;
        for ($ci = 0; $ci < $zip->numFiles; $ci++) {
            $_cn = $zip->getNameIndex($ci);
            if (in_array($_cn, ['retailers.json','passbook.json','kyc_applications.json','plugin.sqlite3'], true)) {
                $_hasKnownFile = true;
                break;
            }
        }
        if (!$_hasKnownFile) {
            flash('This does not appear to be a valid DishNet backup. Accepted: plugin backup, Google Drive DATA zip, or pre-restore auto-backup.', 'danger');
            $zip->close();
            redirect('?page=dashboard&tab=backup');
        }
    }

    if ($isTypeA) {
        $manifest = json_decode($zip->getFromIndex($zip->locateName('BACKUP_MANIFEST.json')), true);
        if (!in_array($manifest['plugin'] ?? '', ['kyc-customer-application', 'dishnet-hybrid-telecom'], true)) {
            flash('Backup is from a different plugin — restore cancelled.', 'danger');
            $zip->close();
            redirect('?page=dashboard&tab=backup');
        }
    }

    // ── Prefix: Google Drive DATA zips store files under data/ ────────
    $prefix = $isTypeB ? 'data/' : '';

    // ── Auto-backup current data before overwriting ─────────────────────
    $preName = "pre-restore-auto-backup-" . date('Y-m-d_H-i-s') . ".zip";
    $prePath  = $dataDir . '/' . $preName;
    $preZip   = new ZipArchive();
    if ($preZip->open($prePath, ZipArchive::CREATE) === true) {
        foreach (['kyc_applications.json','retailers.json','passbook.json','kyc_config.json','kyc_devices.json','kyc_packages.json','wallet_recharge_requests.json','activity_log.json','payment_collections.json','support_tickets.json','leads.json','subscription_plans.json','sim_cards.json','sim_movements.json','sim_fraud_log.json','crm_queue.json','cash_handovers.json','cash_expenses.json','cash_ins.json','cb_categories.json'] as $bf) {
            if (file_exists($dataDir . '/' . $bf)) $preZip->addFile($dataDir . '/' . $bf, $bf);
        }
        foreach (['plugin.sqlite3','dishnet.sqlite'] as $_preDb) {
            $_preDbPath = $dataDir . '/' . $_preDb;
            if (file_exists($_preDbPath)) {
                try { $pdo = new PDO('sqlite:' . $_preDbPath); $pdo->exec('PRAGMA wal_checkpoint(PASSIVE)'); $pdo = null; } catch (Throwable $e) {}
                $preZip->addFile($_preDbPath, $_preDb);
            }
        }
        $preZip->addFromString('BACKUP_MANIFEST.json', json_encode(['plugin'=>'dishnet-hybrid-telecom','version'=>'auto-pre-restore','created_at'=>date('Y-m-d H:i:s'),'note'=>'Auto-backup before restore.'], JSON_PRETTY_PRINT));
        $preZip->close();
    }

    // ── Restore JSON files ──────────────────────────────────────────────
    $allowedFiles = ['kyc_applications.json','retailers.json','passbook.json','kyc_config.json','kyc_devices.json','kyc_packages.json','wallet_recharge_requests.json','activity_log.json','payment_collections.json','support_tickets.json','leads.json','subscription_plans.json','sim_cards.json','sim_movements.json','sim_fraud_log.json','crm_queue.json','cash_handovers.json','cash_expenses.json','cash_ins.json','cb_categories.json'];
    $restored = 0; $skipped = 0; $dbRestored = [];
    $mode = $_POST['restore_mode'] ?? 'merge'; // merge | overwrite

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $zipName = $zip->getNameIndex($i);

        // Strip data/ prefix for Google Drive DATA zips
        $name = $zipName;
        if ($prefix !== '' && strpos($zipName, $prefix) === 0) {
            $name = substr($zipName, strlen($prefix));
        }

        // ── JSON data files ─────────────────────────────────────────────
        if (in_array($name, $allowedFiles, true)) {
            $content = $zip->getFromIndex($i);
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) { $skipped++; continue; }

            $dest = $dataDir . '/' . $name;
            if ($mode === 'merge' && file_exists($dest)) {
                $existing = json_decode(file_get_contents($dest), true) ?: [];
                if (!empty($existing) && isset($existing[0]['id'])) {
                    $existingIds = array_column($existing, 'id');
                    foreach ($decoded as $record) {
                        if (!in_array($record['id'] ?? null, $existingIds)) {
                            $existing[] = $record;
                        }
                    }
                    $decoded = $existing;
                }
                if ($name === 'kyc_config.json') {
                    $decoded = array_merge($decoded, json_decode(file_get_contents($dest), true) ?: []);
                }
            }
            file_put_contents($dest, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $restored++;

        // ── SQLite databases (overwrite mode only) ──────────────────────
        } elseif (in_array($name, ['plugin.sqlite3', 'dishnet.sqlite'], true) && $mode === 'overwrite') {
            $destDb = $dataDir . '/' . $name;
            // Close any existing connections by checkpointing WAL
            if (file_exists($destDb)) {
                try {
                    $pdo = new PDO('sqlite:' . $destDb);
                    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                    $pdo = null;
                } catch (Throwable $e) {}
            }
            // Extract to temp, then move atomically
            $tmpDb = $dataDir . '/restore_tmp_' . $name;
            $content = $zip->getFromIndex($i);
            if ($content !== false && strlen($content) > 100) {
                file_put_contents($tmpDb, $content);
                // Verify the restored DB is valid SQLite
                try {
                    $testPdo = new PDO('sqlite:' . $tmpDb);
                    $testPdo->query('PRAGMA integrity_check')->fetchColumn();
                    $testPdo = null;
                    // Valid — replace the live DB
                    @unlink($destDb . '-wal');
                    @unlink($destDb . '-shm');
                    rename($tmpDb, $destDb);
                    $dbRestored[] = $name;
                    $restored++;
                } catch (Throwable $e) {
                    @unlink($tmpDb);
                    $skipped++;
                }
            }

        // ── SQLite WAL files ────────────────────────────────────────────
        } elseif (in_array($name, ['plugin.sqlite3-wal', 'dishnet.sqlite-wal'], true) && $mode === 'overwrite') {
            // WAL files: only restore if the matching DB was also restored
            $parentDb = str_replace('-wal', '', $name);
            if (in_array($parentDb, $dbRestored, true)) {
                $content = $zip->getFromIndex($i);
                if ($content !== false) {
                    file_put_contents($dataDir . '/' . $name, $content);
                }
            }

        // ── Upload files (receipt photos, KYC docs) ─────────────────────
        } elseif (strpos($name, 'uploads/') === 0 && strlen($name) > 8 && strpos($name, '..') === false) {
            $destFile = $dataDir . '/' . $name;
            $destDir  = dirname($destFile);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            file_put_contents($destFile, $zip->getFromIndex($i));
            $restored++;

        // ── UCRM export files (clients, invoices, payments JSON) ────────
        } elseif (strpos($name, 'ucrm_export/') === 0 && strlen($name) > 12 && strpos($name, '..') === false) {
            $destFile = $dataDir . '/' . $name;
            $destDir  = dirname($destFile);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            file_put_contents($destFile, $zip->getFromIndex($i));
            $restored++;
        }
    }
    $zip->close();

    // Build result message
    $dbMsg = '';
    if (!empty($dbRestored)) {
        $dbMsg = ' SQLite databases restored: ' . implode(', ', $dbRestored) . '.';
    } elseif ($mode === 'merge') {
        $dbMsg = ' SQLite databases skipped (merge mode — use Overwrite to restore databases).';
    }

    // Log the restore event
    $logEntry = [
        'event'      => 'data_restored',
        'actor'      => $admin['name'],
        'detail'     => "Restored {$restored} items from backup '{$file['name']}' (mode: {$mode}).{$dbMsg} Auto-backup saved as {$preName}.",
        'ref_id'     => null,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $store->appendWithId('activity_log.json', $logEntry);

    flash("✅ Restore complete! {$restored} data sets restored.{$dbMsg} Previous data auto-saved as <strong>{$preName}</strong>.", 'success');
    redirect('?page=dashboard&tab=backup');
}

// ─── POST: restore_from_gdrive ─────────────────────────────────────────────
// One-click: download latest DATA zip from Google Drive and restore (overwrite mode).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_from_gdrive') {
    $admin = $auth->requireAdmin();
    @set_time_limit(600);

    require_once __DIR__ . '/../../lib/GoogleDriveBackup.php';
    $gdrive = new GoogleDriveBackup($dataDir);

    if (!$gdrive->isAuthorized()) {
        flash('Google Drive is not connected. Please authorize first.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    $gConf = $gdrive->getConfig();
    $dataFileId = $gConf['last_backup']['data_file_id'] ?? '';
    $dataFileName = $gConf['last_backup']['data_file'] ?? 'unknown';

    if (empty($dataFileId)) {
        flash('No DATA backup found on Google Drive. Run a backup first.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    // Download the DATA zip from Google Drive
    $token = $gdrive->getAccessToken();
    if (!$token) {
        flash('Could not get Google Drive access token. Try re-authorizing.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    $downloadUrl = "https://www.googleapis.com/drive/v3/files/{$dataFileId}?alt=media";
    $tmpFile = $dataDir . '/gdrive_restore_tmp.zip';
    $fp = fopen($tmpFile, 'w');
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $ok = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $httpCode !== 200 || filesize($tmpFile) < 100) {
        @unlink($tmpFile);
        flash("Failed to download DATA backup from Google Drive (HTTP {$httpCode}). Try downloading manually and uploading above.", 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    // Simulate a file upload and let the restore_backup handler process it
    // Instead of duplicating logic, we'll do the restore inline here
    $zip = new ZipArchive();
    if ($zip->open($tmpFile) !== true) {
        @unlink($tmpFile);
        flash('Downloaded file is not a valid ZIP.', 'danger');
        redirect('?page=dashboard&tab=backup');
    }

    // ── Auto-backup current data ────────────────────────────────────────
    $preName = "pre-restore-auto-backup-" . date('Y-m-d_H-i-s') . ".zip";
    $prePath = $dataDir . '/' . $preName;
    $preZip  = new ZipArchive();
    if ($preZip->open($prePath, ZipArchive::CREATE) === true) {
        foreach (['kyc_applications.json','retailers.json','passbook.json','kyc_config.json','kyc_devices.json','kyc_packages.json','wallet_recharge_requests.json','activity_log.json','payment_collections.json','support_tickets.json','leads.json','subscription_plans.json','sim_cards.json','sim_movements.json','sim_fraud_log.json','crm_queue.json','cash_handovers.json','cash_expenses.json','cash_ins.json','cb_categories.json'] as $bf) {
            if (file_exists($dataDir . '/' . $bf)) $preZip->addFile($dataDir . '/' . $bf, $bf);
        }
        foreach (['plugin.sqlite3','dishnet.sqlite'] as $_preDb) {
            $_preDbPath = $dataDir . '/' . $_preDb;
            if (file_exists($_preDbPath)) {
                try { $pdo = new PDO('sqlite:' . $_preDbPath); $pdo->exec('PRAGMA wal_checkpoint(PASSIVE)'); $pdo = null; } catch (Throwable $e) {}
                $preZip->addFile($_preDbPath, $_preDb);
            }
        }
        $preZip->addFromString('BACKUP_MANIFEST.json', json_encode(['plugin'=>'dishnet-hybrid-telecom','version'=>'auto-pre-gdrive-restore','created_at'=>date('Y-m-d H:i:s'),'note'=>'Auto-backup before Google Drive restore.'], JSON_PRETTY_PRINT));
        $preZip->close();
    }

    // ── Restore (overwrite mode, data/ prefix) ──────────────────────────
    $allowedFiles = ['kyc_applications.json','retailers.json','passbook.json','kyc_config.json','kyc_devices.json','kyc_packages.json','wallet_recharge_requests.json','activity_log.json','payment_collections.json','support_tickets.json','leads.json','subscription_plans.json','sim_cards.json','sim_movements.json','sim_fraud_log.json','crm_queue.json','cash_handovers.json','cash_expenses.json','cash_ins.json','cb_categories.json'];
    $restored = 0; $dbRestored = [];
    $prefix = 'data/';

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $zipName = $zip->getNameIndex($i);
        $name = $zipName;
        if (strpos($zipName, $prefix) === 0) {
            $name = substr($zipName, strlen($prefix));
        }

        if (in_array($name, $allowedFiles, true)) {
            $content = $zip->getFromIndex($i);
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) continue;
            file_put_contents($dataDir . '/' . $name, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $restored++;

        } elseif (in_array($name, ['plugin.sqlite3', 'dishnet.sqlite'], true)) {
            $destDb = $dataDir . '/' . $name;
            if (file_exists($destDb)) {
                try { $pdo = new PDO('sqlite:' . $destDb); $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); $pdo = null; } catch (Throwable $e) {}
            }
            $tmpDb = $dataDir . '/restore_tmp_' . $name;
            $content = $zip->getFromIndex($i);
            if ($content !== false && strlen($content) > 100) {
                file_put_contents($tmpDb, $content);
                try {
                    $testPdo = new PDO('sqlite:' . $tmpDb);
                    $testPdo->query('PRAGMA integrity_check')->fetchColumn();
                    $testPdo = null;
                    @unlink($destDb . '-wal');
                    @unlink($destDb . '-shm');
                    rename($tmpDb, $destDb);
                    $dbRestored[] = $name;
                    $restored++;
                } catch (Throwable $e) {
                    @unlink($tmpDb);
                }
            }

        } elseif (in_array($name, ['plugin.sqlite3-wal', 'dishnet.sqlite-wal'], true)) {
            $parentDb = str_replace('-wal', '', $name);
            if (in_array($parentDb, $dbRestored, true)) {
                $content = $zip->getFromIndex($i);
                if ($content !== false) file_put_contents($dataDir . '/' . $name, $content);
            }

        } elseif (strpos($name, 'uploads/') === 0 && strlen($name) > 8 && strpos($name, '..') === false) {
            $destFile = $dataDir . '/' . $name;
            $destDir  = dirname($destFile);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            file_put_contents($destFile, $zip->getFromIndex($i));
            $restored++;

        } elseif (strpos($name, 'ucrm_export/') === 0 && strlen($name) > 12 && strpos($name, '..') === false) {
            $destFile = $dataDir . '/' . $name;
            $destDir  = dirname($destFile);
            if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
            file_put_contents($destFile, $zip->getFromIndex($i));
            $restored++;
        }
    }
    $zip->close();
    @unlink($tmpFile);

    $dbMsg = !empty($dbRestored) ? ' SQLite: ' . implode(', ', $dbRestored) . '.' : '';
    $logEntry = [
        'event'      => 'data_restored',
        'actor'      => $admin['name'],
        'detail'     => "Google Drive restore: {$restored} items from '{$dataFileName}' (overwrite).{$dbMsg} Auto-backup: {$preName}.",
        'ref_id'     => null,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $store->appendWithId('activity_log.json', $logEntry);

    flash("✅ Google Drive restore complete! {$restored} data sets restored from <strong>" . htmlspecialchars($dataFileName) . "</strong>.{$dbMsg} Previous data saved as <strong>{$preName}</strong>.", 'success');
    redirect('?page=dashboard&tab=backup');
}

// ─── POST: export_sqlite_to_json ───────────────────────────────────────────
// Emergency rollback: dumps all SQLite tables back to *.json files in data dir.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_sqlite_to_json') {
    $admin = $auth->requireAdmin();
    if ($store instanceof SqliteStore) {
        $exportDir = $dataDir . '/_json_export_' . date('Ymd_His');
        $exported  = $store->exportAllToJson($exportDir);
        $count     = count($exported);
        $total     = array_sum($exported);
        flash("✅ SQLite exported to JSON: {$count} tables, {$total} total records → <code>{$exportDir}</code>", 'success');
    } else {
        flash('System is already running on JSON — no export needed.', 'info');
    }
    redirect('?page=dashboard&tab=maintenance');
}
