<?php
// ═══════════════════════════════════════════════════════════════
// STOCK MANAGEMENT API — endpoint handlers
// Called from routes.php (page=stock_api) with all variables pre-set:
//   $_stockSvc, $_stockIsPriv, $isAdmin, $retailer, $rid, $act, $met, $body, $ok2, $er2
// ═══════════════════════════════════════════════════════════════

// ── GET stock_locations — warehouse location list ────────────
if ($act === 'stock_locations' && $met === 'GET') {
    $ok2(StockService::LOCATIONS);
}

// ── GET stock_categories ─────────────────────────────────────
if ($act === 'stock_categories' && $met === 'GET') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    try {
        $ok2($_stockSvc->getCategories(!empty($_GET['active_only'])));
    } catch (\Throwable $e) {
        $er2('Failed to load categories: ' . $e->getMessage(), 500);
    }
}

// ── POST stock_category_save ─────────────────────────────────
if ($act === 'stock_category_save' && $met === 'POST') {
    if (!$isAdmin) $er2('Admin only', 403);
    try {
        $ok2($_stockSvc->saveCategory($body));
    } catch (\Throwable $e) {
        $er2($e->getMessage());
    }
}

// ── GET stock_seed_catalog — one-time seed from kyc_devices ──
if ($act === 'stock_seed_catalog' && $met === 'POST') {
    if (!$isAdmin) $er2('Admin only', 403);
    $devices = $store->load('kyc_devices.json') ?? [];
    $count = $_stockSvc->seedFromDevices($devices);
    // Also apply default images
    $imgCount = $_stockSvc->applyDefaultImages();
    $ok2(['seeded' => $count, 'images_applied' => $imgCount], $count > 0 ? "Seeded {$count} categories, {$imgCount} images applied" : 'Catalog already seeded');
}

// ── POST stock_apply_images — apply default product images ───
if ($act === 'stock_apply_images' && $met === 'POST') {
    if (!$isAdmin) $er2('Admin only', 403);
    $updated = $_stockSvc->applyDefaultImages();
    $ok2(['updated' => $updated], $updated > 0 ? "Applied images to {$updated} categories" : 'All categories already have images');
}

// ── GET stock_units ──────────────────────────────────────────
if ($act === 'stock_units' && $met === 'GET') {
    $filters = [];
    foreach (['category_id', 'status', 'service_type', 'location_type', 'search'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $filters[$k] = $_GET[$k];
    }
    // Non-privileged users only see their own checked-out items
    if (!$_stockIsPriv) {
        $filters['assigned_to_rid'] = $rid;
        $filters['status'] = 'checked_out';
    }
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $ok2($_stockSvc->getUnits($filters, $limit, $offset));
}

// ── GET stock_unit_detail ────────────────────────────────────
if ($act === 'stock_unit_detail' && $met === 'GET') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    $unitId = (int)($_GET['unit_id'] ?? 0);
    if (!$unitId) $er2('unit_id required');
    $detail = $_stockSvc->getUnitDetail($unitId);
    if (!$detail) $er2('Unit not found', 404);
    $ok2($detail);
}

// ── POST stock_unit_save — create or manual entry ────────────
if ($act === 'stock_unit_save' && $met === 'POST') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    try {
        $unit = $_stockSvc->createUnit($body, $rid, $retailer['name'] ?? 'Staff');
        $ok2($unit, 'Unit created');
    } catch (\Throwable $e) {
        $er2($e->getMessage());
    }
}

// ── GET stock_quantities ─────────────────────────────────────
if ($act === 'stock_quantities' && $met === 'GET') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    $catId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $ok2($_stockSvc->getQuantities($catId));
}

// ── POST stock_qty_adjust ────────────────────────────────────
if ($act === 'stock_qty_adjust' && $met === 'POST') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    $catId = (int)($body['category_id'] ?? 0);
    $delta = (int)($body['delta'] ?? 0);
    if (!$catId || $delta === 0) $er2('category_id and non-zero delta required');
    try {
        $result = $_stockSvc->adjustQuantity($catId, $delta, $body, $rid, $retailer['name'] ?? 'Staff');
        $ok2($result, 'Quantity adjusted');
    } catch (\Throwable $e) {
        $er2($e->getMessage());
    }
}

// ── POST stock_checkout ──────────────────────────────────────
if ($act === 'stock_checkout' && $met === 'POST') {
    if (!$_stockIsPriv && !($retailer['role'] ?? '') ) $er2('Access denied', 403);
    $unitId = (int)($body['unit_id'] ?? 0);
    $agentRid = (int)($body['agent_rid'] ?? 0);
    $agentName = trim($body['agent_name'] ?? '');
    if (!$unitId || !$agentRid || !$agentName) $er2('unit_id, agent_rid, agent_name required');
    try {
        $result = $_stockSvc->checkout($unitId, $agentRid, $agentName, $rid, $retailer['name'] ?? 'Staff', $body['note'] ?? '');
        $ok2($result, "Checked out to {$agentName}");
    } catch (\Throwable $e) {
        $er2($e->getMessage());
    }
}

// ── POST stock_checkin ───────────────────────────────────────
if ($act === 'stock_checkin' && $met === 'POST') {
    $unitId = (int)($body['unit_id'] ?? 0);
    if (!$unitId) $er2('unit_id required');
    // Field agents can only check in their own items
    if (!$_stockIsPriv) {
        $detail = $_stockSvc->getUnitDetail($unitId);
        if (!$detail || (int)($detail['assigned_to_rid'] ?? 0) !== $rid) $er2('Access denied', 403);
    }
    try {
        $result = $_stockSvc->checkin($unitId, $rid, $retailer['name'] ?? 'Staff', $body['condition'] ?? 'good', $body['note'] ?? '');
        $ok2($result, 'Checked in');
    } catch (\Throwable $e) {
        $er2($e->getMessage());
    }
}

// ── POST stock_install ───────────────────────────────────────
if ($act === 'stock_install' && $met === 'POST') {
    $unitId = (int)($body['unit_id'] ?? 0);
    if (!$unitId) $er2('unit_id required');
    try {
        $result = $_stockSvc->install($unitId, $body, $rid, $retailer['name'] ?? 'Staff');
        $ok2($result, 'Installed');
    } catch (\Throwable $e) {
        $er2($e->getMessage());
    }
}

// ── POST stock_unit_update — edit serial, cost, location, notes ──
if ($act === 'stock_unit_update' && $met === 'POST') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    $unitId = (int)($body['unit_id'] ?? 0);
    if (!$unitId) $er2('unit_id required');
    try {
        $result = $_stockSvc->updateUnit($unitId, $body, $rid, $retailer['name'] ?? 'Staff');
        $ok2($result, 'Unit updated');
    } catch (\Throwable $e) {
        $er2($e->getMessage());
    }
}

// ── POST stock_unit_delete — remove mistaken entries ─────────
if ($act === 'stock_unit_delete' && $met === 'POST') {
    if (!$isAdmin) $er2('Admin only', 403);
    $unitId = (int)($body['unit_id'] ?? 0);
    if (!$unitId) $er2('unit_id required');
    try {
        $result = $_stockSvc->deleteUnit($unitId, $rid, $retailer['name'] ?? 'Staff', $body['reason'] ?? '');
        $ok2($result, 'Unit deleted');
    } catch (\Throwable $e) {
        $er2($e->getMessage());
    }
}

// ── POST stock_return ────────────────────────────────────────
if ($act === 'stock_return' && $met === 'POST') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    $unitId = (int)($body['unit_id'] ?? 0);
    if (!$unitId) $er2('unit_id required');
    try {
        $result = $_stockSvc->returnUnit($unitId, $rid, $retailer['name'] ?? 'Staff', $body['condition'] ?? 'good', $body['note'] ?? '');
        $ok2($result, 'Returned');
    } catch (\Throwable $e) {
        $er2($e->getMessage());
    }
}

// ── POST stock_transfer ──────────────────────────────────────
if ($act === 'stock_transfer' && $met === 'POST') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    $unitId = (int)($body['unit_id'] ?? 0);
    if (!$unitId) $er2('unit_id required');
    try {
        $result = $_stockSvc->transfer($unitId, $body, $rid, $retailer['name'] ?? 'Staff', $body['note'] ?? '');
        $ok2($result, 'Transferred');
    } catch (\Throwable $e) {
        $er2($e->getMessage());
    }
}

// ── POST stock_write_off ─────────────────────────────────────
if ($act === 'stock_write_off' && $met === 'POST') {
    if (!$isAdmin) $er2('Admin only', 403);
    $unitId = (int)($body['unit_id'] ?? 0);
    if (!$unitId) $er2('unit_id required');
    try {
        $result = $_stockSvc->writeOff($unitId, $rid, $retailer['name'] ?? 'Staff', $body['reason'] ?? '');
        $ok2($result, 'Written off');
    } catch (\Throwable $e) {
        $er2($e->getMessage());
    }
}

// ── POST stock_inbound — receive stock from supplier ─────────
if ($act === 'stock_inbound' && $met === 'POST') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    try {
        $purchase = $_stockSvc->createPurchase($body, $rid, $retailer['name'] ?? 'Staff');
        // Process items array if provided
        $items = $body['items'] ?? [];
        $created = [];
        foreach ($items as $item) {
            $catId = (int)($item['category_id'] ?? 0);
            $cat = $_stockSvc->getCategory($catId);
            if (!$cat) continue;

            if ($cat['track_mode'] === 'serial') {
                $item['reference_type'] = 'purchase';
                $item['reference_id'] = (string)$purchase['id'];
                $item['purchase_ref'] = $body['invoice_number'] ?? '';
                $created[] = $_stockSvc->createUnit($item, $rid, $retailer['name'] ?? 'Staff');
            } else {
                $qty = (int)($item['quantity'] ?? 1);
                if ($qty > 0) {
                    $item['reference_type'] = 'purchase';
                    $item['reference_id'] = (string)$purchase['id'];
                    $item['note'] = 'Received from ' . ($body['supplier'] ?? 'supplier');
                    $_stockSvc->adjustQuantity($catId, $qty, $item, $rid, $retailer['name'] ?? 'Staff');
                    $created[] = ['category_id' => $catId, 'quantity' => $qty];
                }
            }
        }
        $ok2(['purchase' => $purchase, 'items_created' => count($created)], 'Stock received');
    } catch (\Throwable $e) {
        $er2($e->getMessage());
    }
}

// ── GET stock_report ─────────────────────────────────────────
if ($act === 'stock_report' && $met === 'GET') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    try {
        $ok2($_stockSvc->getDashboardStats());
    } catch (\Throwable $e) {
        $er2('Failed to load stats: ' . $e->getMessage(), 500);
    }
}

// ── GET stock_movements_log ──────────────────────────────────
if ($act === 'stock_movements_log' && $met === 'GET') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    $filters = [];
    foreach (['movement_type', 'category_id', 'performed_by', 'date_from', 'date_to', 'search'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $filters[$k] = $_GET[$k];
    }
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $ok2($_stockSvc->getMovements($filters, $limit, $offset));
}

// ── GET stock_agent_holdings ─────────────────────────────────
if ($act === 'stock_agent_holdings' && $met === 'GET') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    $agentRid = isset($_GET['agent_rid']) ? (int)$_GET['agent_rid'] : null;
    $ok2($_stockSvc->getAgentHoldings($agentRid));
}

// ── GET stock_purchases ──────────────────────────────────────
if ($act === 'stock_purchases' && $met === 'GET') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    $ok2($_stockSvc->getPurchases());
}

// ── GET staff_list (for checkout agent dropdown) ─────────────
if ($act === 'staff_list' && $met === 'GET') {
    $allR = $store->load('retailers.json') ?? [];
    $list = [];
    foreach ($allR as $r) {
        $list[] = [
            'id'       => (int)($r['id'] ?? 0),
            'name'     => $r['name'] ?? '',
            'role'     => $r['role'] ?? '',
            'is_active'=> !empty($r['is_active']),
        ];
    }
    $ok2($list);
}

// ── GET stock_export_csv ─────────────────────────────────────
if ($act === 'stock_export_csv' && $met === 'GET') {
    if (!$_stockIsPriv) $er2('Access denied', 403);
    $filters = [];
    foreach (['category_id', 'status', 'service_type', 'search'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $filters[$k] = $_GET[$k];
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stock_export_' . date('Y-m-d') . '.csv"');
    echo $_stockSvc->exportUnitsCsv($filters);
    exit;
}

// ── GET stock_export_deployed ────────────────────────────────
// Downloads CSV (Excel-compatible) of all deployed equipment: serial → client mapping
if ($act === 'stock_export_deployed' && $met === 'GET') {
    if (!$_stockIsPriv) $er2('Access denied', 403);

    $db = $_stockSvc->getDb();
    $rows = $db->query(
        "SELECT u.serial_number, u.mac_address, c.title AS category, c.sku,
                u.location_name AS client_name, u.crm_client_id,
                u.condition_grade, u.updated_at AS deployed_date,
                u.cost_price
         FROM stock_units u
         LEFT JOIN stock_categories c ON c.id = u.category_id
         WHERE u.status = 'installed'
         ORDER BY c.title ASC, u.updated_at DESC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="deployed_equipment_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['Serial Number', 'MAC Address', 'Category', 'SKU', 'Client Name', 'CRM Client ID', 'Condition', 'Deployed Date', 'Cost (USD)']);
    foreach ($rows as $r) {
        fputcsv($fp, [
            $r['serial_number'] ?? '',
            $r['mac_address'] ?? '',
            $r['category'] ?? '',
            $r['sku'] ?? '',
            $r['client_name'] ?? '',
            $r['crm_client_id'] ?? '',
            $r['condition_grade'] ?? '',
            substr($r['deployed_date'] ?? '', 0, 10),
            $r['cost_price'] ?? '',
        ]);
    }
    fclose($fp);
    exit;
}
