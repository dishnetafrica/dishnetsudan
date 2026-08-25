<?php
// ═══════════════════════════════════════════════════════════════
// PRODUCTS / QUOTES ADMIN (pre-auth)
// ═══════════════════════════════════════════════════════════════


    // ─── DIAGNOSTIC: View quote/proforma log ─────────────────────────────────
    // GET ?page=api&action=quote_log
    if ($act === 'quote_log' && $met === 'GET') {
        // $dataDir inherited from public.php (UCRM persistent)
        $diagFile = $dataDir . '/quotes_log.json';
        $diagLogs = file_exists($diagFile) ? json_decode(file_get_contents($diagFile), true) : [];
        if (!is_array($diagLogs)) $diagLogs = [];
        // Show newest first
        $diagLogs = array_reverse($diagLogs);
        $diagLogs = array_slice($diagLogs, 0, 50); // Last 50
        $ok2([
            'count' => count($diagLogs),
            'logs'  => $diagLogs,
            'config' => [
                'kyc_auto_quote_enabled' => ($config['kyc_auto_quote_enabled'] ?? true) !== false,
                'kyc_quote_validity_days' => (int)($config['kyc_quote_validity_days'] ?? 7),
                'kyc_auto_quote_max_amount' => (float)($config['kyc_auto_quote_max_amount'] ?? 0),
                'quote_prefix' => $config['quote_prefix'] ?? 'QUO',
            ],
        ]);
    }

    // ─── DIAGNOSTIC: View recent KYC applications and quote status ───────────
    // GET ?page=api&action=kyc_debug
    if ($act === 'kyc_debug' && $met === 'GET') {
        $apps = $store->load('kyc_applications.json') ?? [];
        // Sort by created_at desc, take last 10
        usort($apps, function($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });
        $recent = array_slice($apps, 0, 10);
        $summary = [];
        foreach ($recent as $app) {
            $summary[] = [
                'id'              => $app['id'] ?? null,
                'customer'        => trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? '')),
                'crm_client_id'   => $app['crm_client_id'] ?? null,
                'created_at'      => $app['created_at'] ?? null,
                'quote_id'        => $app['quote_id'] ?? null,
                'quote_created'   => $app['quote_created'] ?? false,
                'quote_ref'       => $app['quote_ref'] ?? null,
                'quote_error'     => $app['quote_error'] ?? null,
                'amount_charged'  => $app['amount_charged'] ?? 0,
                'payment_type'    => $app['payment_type'] ?? null,
                'offer_name'      => $app['offer_name'] ?? null,
                'device_title'    => $app['device_title'] ?? null,
                'package_choice'  => $app['package_choice'] ?? null,
                'device_id'       => $app['device_id'] ?? null,
            ];
        }
        $ok2([
            'count' => count($recent),
            'applications' => $summary,
        ]);
    }

    // ─── SYNC: Fetch quotes from UCRM and store locally ──────────────────────
    // GET ?page=api&action=sync_ucrm_quotes&from=2026-01-01
    if ($act === 'sync_ucrm_quotes' && $met === 'GET') {
        $fromDate = $_GET['from'] ?? '2026-01-01';
        $toDate   = $_GET['to'] ?? date('Y-m-d');
        $limit    = min(500, max(10, (int)($_GET['limit'] ?? 500)));
        
        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            $er2('Invalid from date format. Use YYYY-MM-DD');
        }
        
        require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
        $crm = CrmApiClient::fromUcrm(dirname(__DIR__), $config);
        
        if (!$crm->isConfigured()) {
            $er2('CRM not configured');
        }
        
        // Try multiple endpoints - UCRM API varies by version
        $qs = http_build_query([
            'createdDateFrom' => $fromDate,
            'createdDateTo'   => $toDate,
            'limit'           => $limit,
        ]);
        
        $quotes = null;
        $endpoint = null;
        $errors = [];
        
        // Try billing/quotes first (standard UCRM 3.x+)
        $quotes = $crm->get("billing/quotes?{$qs}");
        if (is_array($quotes)) {
            $endpoint = 'billing/quotes';
        } else {
            $errors['billing/quotes'] = $crm->getLastError();
            
            // Try without query params
            $quotes = $crm->get("billing/quotes");
            if (is_array($quotes)) {
                $endpoint = 'billing/quotes (no params)';
            } else {
                $errors['billing/quotes_noparams'] = $crm->getLastError();
                
                // Try quotes without billing prefix
                $quotes = $crm->get("quotes?{$qs}");
                if (is_array($quotes)) {
                    $endpoint = 'quotes';
                } else {
                    $errors['quotes'] = $crm->getLastError();
                }
            }
        }
        
        // If all failed, return diagnostic info
        if (!is_array($quotes)) {
            $ok2([
                'success'  => false,
                'message'  => 'Could not fetch quotes from UCRM. Quotes may not exist or API endpoint differs.',
                'errors'   => $errors,
                'hint'     => 'Please check UCRM → Billing → Quotes to see if any quotes exist. If none exist, this is expected.',
            ]);
        }
        
        // Store locally
        $existing = $store->load('ucrm_quotes_sync.json') ?? [];
        $existingIds = array_column($existing, 'id');
        
        $newCount = 0;
        $updatedCount = 0;
        
        foreach ($quotes as $q) {
            $qId = (int)($q['id'] ?? 0);
            if (!$qId) continue;
            
            $quoteRecord = [
                'id'              => $qId,
                'number'          => $q['number'] ?? null,
                'clientId'        => $q['clientId'] ?? null,
                'clientName'      => null, // Will be populated below
                'status'          => $q['status'] ?? null, // 0=draft, 1=open, 2=approved, 3=rejected, 4=void
                'total'           => (float)($q['total'] ?? 0),
                'createdDate'     => $q['createdDate'] ?? null,
                'dueDate'         => $q['dueDate'] ?? null,
                'notes'           => $q['notes'] ?? null,
                'items'           => $q['items'] ?? [],
                'synced_at'       => date('Y-m-d H:i:s'),
            ];
            
            // Try to get client name
            if (!empty($q['clientId'])) {
                $client = $crm->get("clients/{$q['clientId']}");
                if (is_array($client)) {
                    $quoteRecord['clientName'] = trim(
                        ($client['firstName'] ?? '') . ' ' . 
                        ($client['lastName'] ?? '') . ' ' .
                        ($client['companyName'] ?? '')
                    );
                }
            }
            
            $idx = array_search($qId, $existingIds);
            if ($idx !== false) {
                $existing[$idx] = $quoteRecord;
                $updatedCount++;
            } else {
                $existing[] = $quoteRecord;
                $newCount++;
            }
        }
        
        // Sort by createdDate desc
        usort($existing, function($a, $b) {
            return strcmp($b['createdDate'] ?? '', $a['createdDate'] ?? '');
        });
        
        $store->save('ucrm_quotes_sync.json', $existing);
        
        $ok2([
            'success'       => true,
            'fetched'       => count($quotes),
            'new'           => $newCount,
            'updated'       => $updatedCount,
            'total_stored'  => count($existing),
            'date_range'    => ['from' => $fromDate, 'to' => $toDate],
        ]);
    }

    // ─── VIEW: List synced UCRM quotes ───────────────────────────────────────
    // GET ?page=api&action=ucrm_quotes
    if ($act === 'ucrm_quotes' && $met === 'GET') {
        $quotes = $store->load('ucrm_quotes_sync.json') ?? [];
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        
        // Optional filters
        $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
        $status = isset($_GET['status']) ? (int)$_GET['status'] : null;
        
        $filtered = $quotes;
        if ($clientId !== null) {
            $filtered = array_filter($filtered, function($q) use ($clientId) {
                return ($q['clientId'] ?? 0) === $clientId;
            });
        }
        if ($status !== null) {
            $filtered = array_filter($filtered, function($q) use ($status) {
                return ($q['status'] ?? -1) === $status;
            });
        }
        
        $filtered = array_values($filtered);
        $total = count($filtered);
        $page = array_slice($filtered, $offset, $limit);
        
        $ok2([
            'total'   => $total,
            'offset'  => $offset,
            'limit'   => $limit,
            'quotes'  => $page,
            'statuses' => [
                0 => 'draft',
                1 => 'open',
                2 => 'approved',
                3 => 'rejected',
                4 => 'void',
            ],
        ]);
    }

    // ─── SYNC UCRM PRODUCTS ─────────────────────────────────────────────────
    // GET ?page=api&action=sync_ucrm_products
    // Fetches all products from UCRM and stores locally for mapping
    if ($act === 'sync_ucrm_products' && $met === 'GET') {
        require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
        $crm = CrmApiClient::fromUcrm(dirname(__DIR__), $config);
        
        if (!$crm->isConfigured()) {
            $er2('CRM not configured');
        }
        
        // UCRM products endpoint
        $products = $crm->get('products');
        
        if (!is_array($products)) {
            $er2('Failed to fetch products from UCRM: ' . json_encode($crm->getLastError()));
        }
        
        // Add sync timestamp
        $syncTime = date('Y-m-d H:i:s');
        foreach ($products as &$p) {
            $p['synced_at'] = $syncTime;
        }
        unset($p);
        
        // Store locally
        $store->save('ucrm_products.json', $products);
        
        // Build category summary
        $categories = [];
        foreach ($products as $p) {
            $cat = $p['unit'] ?? 'unknown';
            if (!isset($categories[$cat])) $categories[$cat] = 0;
            $categories[$cat]++;
        }
        
        $ok2([
            'success'    => true,
            'fetched'    => count($products),
            'synced_at'  => $syncTime,
            'categories' => $categories,
            'sample'     => array_slice($products, 0, 5),
        ]);
    }

    // GET ?page=api&action=verify_product_mapping
    // Shows each local plan/device vs its actual UCRM product name — confirms mapping is correct
    if ($act === 'verify_product_mapping' && $met === 'GET') {
        $ucrmProducts = $crm->get('products') ?: [];
        $ucrmById = [];
        foreach ($ucrmProducts as $p) { $ucrmById[(int)$p['id']] = $p; }

        $plans1  = $store->load('subscription_plans.json') ?? [];
        $plans2  = $store->load('kyc_packages.json') ?? [];
        $devices = $store->load('kyc_devices.json') ?? [];
        $ltePkgs = $store->load('lte_packages.json') ?? [];

        $rows = [];
        $buildRow = function($localName, $localPrice, $ucrmId, $source) use ($ucrmById) {
            $ucrmId = (int)$ucrmId;
            if ($ucrmId && isset($ucrmById[$ucrmId])) {
                $up = $ucrmById[$ucrmId];
                $nameMatch  = strtolower(trim($up['name'])) === strtolower(trim($localName));
                $priceMatch = abs((float)($up['price'] ?? 0) - $localPrice) < 0.01;
                return [
                    'source'        => $source,
                    'local_name'    => $localName,
                    'local_price'   => $localPrice,
                    'ucrm_id'       => $ucrmId,
                    'ucrm_name'     => $up['name'],
                    'ucrm_price'    => $up['price'] ?? 0,
                    'ucrm_url'      => 'https://crm.dishnetafrica.com/crm/system/items/products/' . $ucrmId,
                    'name_match'    => $nameMatch,
                    'price_match'   => $priceMatch,
                    'status'        => ($nameMatch && $priceMatch) ? '✅ synced' : '⚠️ mismatch',
                ];
            }
            return [
                'source'     => $source,
                'local_name' => $localName,
                'local_price'=> $localPrice,
                'ucrm_id'    => $ucrmId ?: null,
                'ucrm_name'  => null,
                'status'     => $ucrmId ? '❌ ID not found in UCRM' : '❌ not mapped',
            ];
        };

        foreach ($plans1 as $p) {
            if (empty($p['is_active'])) continue;
            $rows[] = $buildRow($p['name'], (float)($p['customer_price'] ?? 0), $p['ucrm_product_id'] ?? 0, 'subscription_plans');
        }
        foreach ($plans2 as $p) {
            if (empty($p['is_active'])) continue;
            $rows[] = $buildRow($p['name'], (float)($p['customer_price'] ?? $p['amount'] ?? 0), $p['ucrm_product_id'] ?? 0, 'kyc_packages');
        }
        foreach ($devices as $d) {
            if (empty($d['is_active'])) continue;
            $rows[] = $buildRow($d['title'] ?? $d['name'], (float)($d['sell_price'] ?? 0), $d['ucrm_product_id'] ?? 0, 'kyc_devices');
        }
        foreach ($ltePkgs as $p) {
            if (empty($p['is_active'])) continue;
            $rows[] = $buildRow($p['name'], (float)($p['price'] ?? 0), $p['ucrm_product_id'] ?? 0, 'lte_packages');
        }

        $synced   = count(array_filter($rows, fn($r) => str_contains($r['status'], '✅')));
        $mismatch = count(array_filter($rows, fn($r) => str_contains($r['status'], '⚠️')));
        $missing  = count(array_filter($rows, fn($r) => str_contains($r['status'], '❌')));

        $ok2([
            'ucrm_products_total' => count($ucrmProducts),
            'summary' => ['synced' => $synced, 'mismatch' => $mismatch, 'not_mapped' => $missing],
            'hint'    => $mismatch > 0 ? 'Run push_products_to_ucrm&dry_run=0 to fix mismatches' : ($missing > 0 ? 'Some plans not mapped — run Sync All' : 'All good!'),
            'rows'    => $rows,
        ]);
    }

    // GET ?page=api&action=ucrm_products
    // View synced UCRM products with optional search
    if ($act === 'ucrm_products' && $met === 'GET') {
        $products = $store->load('ucrm_products.json') ?? [];
        $search   = trim($_GET['search'] ?? '');
        $limit    = min(200, max(10, (int)($_GET['limit'] ?? 100)));
        $offset   = max(0, (int)($_GET['offset'] ?? 0));
        
        $filtered = $products;
        if ($search !== '') {
            $searchLower = strtolower($search);
            $filtered = array_filter($filtered, function($p) use ($searchLower) {
                $name = strtolower($p['name'] ?? '');
                return strpos($name, $searchLower) !== false;
            });
        }
        
        $filtered = array_values($filtered);
        $total = count($filtered);
        $page  = array_slice($filtered, $offset, $limit);
        
        $ok2([
            'total'    => $total,
            'offset'   => $offset,
            'limit'    => $limit,
            'products' => $page,
        ]);
    }

    // GET ?page=api&action=auto_map_products
    // Auto-matches UCRM products to local packages/devices by name similarity
    if ($act === 'auto_map_products' && $met === 'GET') {
        $products    = $store->load('ucrm_products.json') ?? [];
        $packages    = $store->load('subscription_plans.json') ?? [];
        $packages2   = $store->load('kyc_packages.json') ?? [];
        $devices     = $store->load('kyc_devices.json') ?? [];
        $dryRun      = ($_GET['dry_run'] ?? '1') === '1';
        
        if (empty($products)) {
            $er2('No UCRM products synced. Run sync_ucrm_products first.');
        }
        
        // Build lookup: lowercase name -> product
        $productLookup = [];
        foreach ($products as $p) {
            $key = strtolower(trim($p['name'] ?? ''));
            $productLookup[$key] = $p;
        }
        
        $mappings = [];
        $updated  = ['subscription_plans' => 0, 'kyc_packages' => 0, 'kyc_devices' => 0];
        
        // Helper: find best match
        $findMatch = function($name) use ($productLookup, $products) {
            $nameLower = strtolower(trim($name));
            
            // Exact match
            if (isset($productLookup[$nameLower])) {
                return ['match' => 'exact', 'product' => $productLookup[$nameLower]];
            }
            
            // Partial match (name contains or is contained)
            foreach ($products as $p) {
                $pName = strtolower(trim($p['name'] ?? ''));
                if (strpos($pName, $nameLower) !== false || strpos($nameLower, $pName) !== false) {
                    return ['match' => 'partial', 'product' => $p];
                }
            }
            
            // Word match (key words overlap)
            $nameWords = preg_split('/[\s\-\_\(\)]+/', $nameLower);
            $nameWords = array_filter($nameWords, function($w) { return strlen($w) > 2; });
            
            foreach ($products as $p) {
                $pName = strtolower(trim($p['name'] ?? ''));
                $pWords = preg_split('/[\s\-\_\(\)]+/', $pName);
                $pWords = array_filter($pWords, function($w) { return strlen($w) > 2; });
                
                $overlap = array_intersect($nameWords, $pWords);
                if (count($overlap) >= 2) {
                    return ['match' => 'words', 'product' => $p, 'overlap' => array_values($overlap)];
                }
            }
            
            return null;
        };
        
        // Map subscription_plans
        foreach ($packages as $i => $pkg) {
            $name = $pkg['name'] ?? '';
            if (empty($name)) continue;
            
            $match = $findMatch($name);
            if ($match) {
                $mappings[] = [
                    'source' => 'subscription_plans',
                    'local_id' => $pkg['id'] ?? $i,
                    'local_name' => $name,
                    'ucrm_product_id' => $match['product']['id'],
                    'ucrm_name' => $match['product']['name'],
                    'ucrm_price' => $match['product']['price'] ?? 0,
                    'match_type' => $match['match'],
                ];
                if (!$dryRun && !isset($packages[$i]['ucrm_product_id'])) {
                    $packages[$i]['ucrm_product_id'] = $match['product']['id'];
                    $updated['subscription_plans']++;
                }
            }
        }
        
        // Map kyc_packages
        foreach ($packages2 as $i => $pkg) {
            $name = $pkg['name'] ?? '';
            if (empty($name)) continue;
            
            $match = $findMatch($name);
            if ($match) {
                $mappings[] = [
                    'source' => 'kyc_packages',
                    'local_id' => $pkg['id'] ?? $i,
                    'local_name' => $name,
                    'ucrm_product_id' => $match['product']['id'],
                    'ucrm_name' => $match['product']['name'],
                    'ucrm_price' => $match['product']['price'] ?? 0,
                    'match_type' => $match['match'],
                ];
                if (!$dryRun && !isset($packages2[$i]['ucrm_product_id'])) {
                    $packages2[$i]['ucrm_product_id'] = $match['product']['id'];
                    $updated['kyc_packages']++;
                }
            }
        }
        
        // Map kyc_devices
        foreach ($devices as $i => $dev) {
            $name = $dev['title'] ?? $dev['name'] ?? '';
            if (empty($name)) continue;
            
            $match = $findMatch($name);
            if ($match) {
                $mappings[] = [
                    'source' => 'kyc_devices',
                    'local_id' => $dev['id'] ?? $i,
                    'local_name' => $name,
                    'ucrm_product_id' => $match['product']['id'],
                    'ucrm_name' => $match['product']['name'],
                    'ucrm_price' => $match['product']['price'] ?? 0,
                    'match_type' => $match['match'],
                ];
                if (!$dryRun && !isset($devices[$i]['ucrm_product_id'])) {
                    $devices[$i]['ucrm_product_id'] = $match['product']['id'];
                    $updated['kyc_devices']++;
                }
            }
        }
        
        // Save if not dry run
        if (!$dryRun) {
            if ($updated['subscription_plans'] > 0) $store->save('subscription_plans.json', $packages);
            if ($updated['kyc_packages'] > 0) $store->save('kyc_packages.json', $packages2);
            if ($updated['kyc_devices'] > 0) $store->save('kyc_devices.json', $devices);
        }
        
        $ok2([
            'dry_run'        => $dryRun,
            'ucrm_products'  => count($products),
            'mappings_found' => count($mappings),
            'updated'        => $dryRun ? 'N/A (dry run)' : $updated,
            'mappings'       => $mappings,
            'hint'           => $dryRun ? 'Add &dry_run=0 to apply mappings' : 'Mappings applied!',
        ]);
    }

    // POST ?page=api&action=set_product_mapping
    // Manually set ucrm_product_id for a package/device
    if ($act === 'set_product_mapping' && $met === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $source = $body['source'] ?? '';  // subscription_plans, kyc_packages, kyc_devices
        $localId = (int)($body['local_id'] ?? 0);
        $ucrmProductId = isset($body['ucrm_product_id']) ? (int)$body['ucrm_product_id'] : null;
        
        $fileMap = [
            'subscription_plans' => 'subscription_plans.json',
            'kyc_packages'       => 'kyc_packages.json',
            'kyc_devices'        => 'kyc_devices.json',
        ];
        
        if (!isset($fileMap[$source])) {
            $er2('Invalid source. Use: subscription_plans, kyc_packages, or kyc_devices');
        }
        if ($localId <= 0) {
            $er2('local_id required');
        }
        
        $file = $fileMap[$source];
        $items = $store->load($file) ?? [];
        
        $found = false;
        foreach ($items as &$item) {
            if (($item['id'] ?? 0) == $localId) {
                if ($ucrmProductId === null || $ucrmProductId === 0) {
                    unset($item['ucrm_product_id']);
                } else {
                    $item['ucrm_product_id'] = $ucrmProductId;
                }
                $found = true;
                break;
            }
        }
        unset($item);
        
        if (!$found) {
            $er2("Item with id={$localId} not found in {$source}");
        }
        
        $store->save($file, $items);
        
        $ok2([
            'success' => true,
            'source'  => $source,
            'local_id' => $localId,
            'ucrm_product_id' => $ucrmProductId,
        ]);
    }

    // ─── PUSH PLUGIN PRODUCTS TO UCRM ─────────────────────────────────────────
    // GET ?page=api&action=push_products_to_ucrm&dry_run=1
    // Creates UCRM products from plugin packages/devices, saves returned IDs
    if ($act === 'push_products_to_ucrm' && $met === 'GET') {
        require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
        $crm = CrmApiClient::fromUcrm(dirname(__DIR__), $config);
        
        if (!$crm->isConfigured()) {
            $er2('CRM not configured');
        }
        
        $dryRun      = ($_GET['dry_run'] ?? '1') === '1';
        $forceUpdate = ($_GET['force'] ?? '0') === '1';
        
        // ── STEP 1: Fetch ALL existing products from UCRM first ──────────────
        // This prevents duplicates and lets us match by name instead of
        // blindly creating new products every time.
        $ucrmProducts   = $crm->get('products') ?: [];
        $ucrmByName     = [];  // lowercase name → product
        $ucrmById       = [];  // id → product
        foreach ($ucrmProducts as $up) {
            $ucrmByName[strtolower(trim($up['name'] ?? ''))] = $up;
            $ucrmById[(int)$up['id']] = $up;
        }
        $lastUcrmId = empty($ucrmProducts) ? 0 : max(array_map(fn($p) => (int)($p['id'] ?? 0), $ucrmProducts));

        // Load plugin catalogs
        $plans1    = $store->load('subscription_plans.json') ?? [];
        $plans2    = $store->load('kyc_packages.json') ?? [];
        $devices   = $store->load('kyc_devices.json') ?? [];
        $ltePkgs   = $store->load('lte_packages.json') ?? [];
        
        $results = [
            'subscription_plans' => ['total' => 0, 'skipped' => 0, 'matched' => 0, 'created' => 0, 'failed' => 0, 'items' => []],
            'kyc_packages'       => ['total' => 0, 'skipped' => 0, 'matched' => 0, 'created' => 0, 'failed' => 0, 'items' => []],
            'kyc_devices'        => ['total' => 0, 'skipped' => 0, 'matched' => 0, 'created' => 0, 'failed' => 0, 'items' => []],
            'lte_packages'       => ['total' => 0, 'skipped' => 0, 'matched' => 0, 'created' => 0, 'failed' => 0, 'items' => []],
        ];
        
        // Helper: determine unit type based on item type
        $getUnit = function($item, $type) {
            if ($type === 'kyc_devices') {
                return 'Nos';
            }
            return 'Month';
        };
        
        // Helper: resolve or create product in UCRM
        // 1. Check if local ucrm_product_id exists AND matches a real UCRM product → skip (already good)
        // 2. Check if UCRM has a product with same name → assign that ID (match)
        // 3. Otherwise → create new product in UCRM
        $createProduct = function($name, $price, $unit, $type, $existingLocalId) use ($crm, $dryRun, $forceUpdate, $ucrmByName, $ucrmById) {
            $nameLower = strtolower(trim($name));

            // Case 1: local ID exists and UCRM product found by that ID
            if ($existingLocalId > 0 && isset($ucrmById[$existingLocalId])) {
                $ucrmName = trim($ucrmById[$existingLocalId]['name'] ?? '');
                $ucrmPrice = (float)($ucrmById[$existingLocalId]['price'] ?? 0);
                $nameChanged  = (strtolower($ucrmName) !== $nameLower);
                $priceChanged = (abs($ucrmPrice - $price) > 0.009);
                if ($nameChanged || $priceChanged || $forceUpdate) {
                    // Update name/price in UCRM to match plugin
                    if (!$dryRun) {
                        $crm->patch("products/{$existingLocalId}", [
                            'name'         => $name,
                            'invoiceLabel' => $name,
                            'unit'         => $unit,
                            'price'        => $price,
                            'taxable'      => false,
                        ]);
                    }
                    return ['action' => 'updated', 'id' => $existingLocalId,
                            'old_name' => $ucrmName, 'new_name' => $name,
                            'price_changed' => $priceChanged];
                }
                // Name + price match — already in sync
                return ['action' => 'verified', 'id' => $existingLocalId, 'ucrm_name' => $ucrmName];
            }

            // Case 2: name match in UCRM (no local ID or ID mismatch)
            if (isset($ucrmByName[$nameLower])) {
                $matched = $ucrmByName[$nameLower];
                $matchedId = (int)$matched['id'];
                // Also update price if different
                if (!$dryRun && abs((float)($matched['price'] ?? 0) - $price) > 0.009) {
                    $crm->patch("products/{$matchedId}", [
                        'name'         => $name,
                        'invoiceLabel' => $name,
                        'unit'         => $unit,
                        'price'        => $price,
                        'taxable'      => false,
                    ]);
                }
                return ['action' => 'matched', 'id' => $matchedId, 'ucrm_name' => $matched['name']];
            }

            // Case 3: not found — create new
            $payload = [
                'name'         => $name,
                'invoiceLabel' => $name,
                'unit'         => $unit,
                'price'        => (float)$price,
                'taxable'      => false,
            ];
            if ($dryRun) {
                return ['action' => 'would_create', 'dry_run' => true, 'would_create' => $payload];
            }
            $response = $crm->post('products', $payload);
            if (!empty($response['id'])) {
                return ['action' => 'created', 'id' => (int)$response['id'], 'name' => $response['name'] ?? $name];
            }
            return ['action' => 'failed', 'error' => $crm->getLastError()];
        };

        // ── Helper: process one item through createProduct and update local array ──
        $processItem = function($name, $price, $unit, $catKey, $existId, $i, &$arr, &$results) use ($createProduct, $dryRun) {
            $result = $createProduct($name, $price, $unit, $catKey, $existId);
            $action = $result['action'] ?? '';
            if (!$dryRun && !empty($result['id'])) {
                $arr[$i]['ucrm_product_id'] = (int)$result['id'];
            }
            if ($action === 'verified')      $results[$catKey]['skipped']++;
            elseif (in_array($action, ['matched','updated','created','would_create'])) $results[$catKey]['created']++;
            else                             $results[$catKey]['failed']++;
            $results[$catKey]['items'][] = [
                'local_id' => $arr[$i]['id'] ?? $i,
                'name'     => $name,
                'price'    => $price,
                'result'   => $result,
            ];
        };

        // Process subscription_plans
        foreach ($plans1 as $i => $item) {
            if (empty($item['is_active'])) continue;
            $results['subscription_plans']['total']++;
            $name = trim($item['name'] ?? ''); if (!$name) continue;
            $price   = (float)($item['customer_price'] ?? $item['amount'] ?? $item['price'] ?? 0);
            $existId = (int)($item['ucrm_product_id'] ?? 0);
            $processItem($name, $price, 'Month', 'subscription_plans', $existId, $i, $plans1, $results);
        }

        // Process kyc_packages
        foreach ($plans2 as $i => $item) {
            if (empty($item['is_active'])) continue;
            $results['kyc_packages']['total']++;
            $name = trim($item['name'] ?? ''); if (!$name) continue;
            $price   = (float)($item['customer_price'] ?? $item['amount'] ?? $item['price'] ?? 0);
            $existId = (int)($item['ucrm_product_id'] ?? 0);
            $processItem($name, $price, 'Month', 'kyc_packages', $existId, $i, $plans2, $results);
        }

        // Process kyc_devices
        foreach ($devices as $i => $item) {
            if (empty($item['is_active'])) continue;
            $results['kyc_devices']['total']++;
            $name = trim($item['title'] ?? $item['name'] ?? ''); if (!$name) continue;
            $price   = (float)preg_replace('/[^0-9.]/', '', (string)($item['sell_price'] ?? $item['price'] ?? '0'));
            $existId = (int)($item['ucrm_product_id'] ?? 0);
            $processItem($name, $price, 'Nos', 'kyc_devices', $existId, $i, $devices, $results);
        }

        // Process lte_packages
        foreach ($ltePkgs as $i => $item) {
            if (empty($item['is_active'])) continue;
            $results['lte_packages']['total']++;
            $name = trim($item['name'] ?? ''); if (!$name) continue;
            $price   = (float)($item['price'] ?? 0);
            $existId = (int)($item['ucrm_product_id'] ?? 0);
            $processItem($name, $price, 'Month', 'lte_packages', $existId, $i, $ltePkgs, $results);
        }

        // Save all catalogs with updated ucrm_product_id
        if (!$dryRun) {
            $store->save('subscription_plans.json', $plans1);
            $store->save('kyc_packages.json', $plans2);
            $store->save('kyc_devices.json', $devices);
            $store->save('lte_packages.json', $ltePkgs);
        }

        $totalCreated = array_sum(array_column($results, 'created'));
        $totalSkipped = array_sum(array_column($results, 'skipped'));
        $totalFailed  = array_sum(array_column($results, 'failed'));

        $ok2([
            'dry_run'               => $dryRun,
            'ucrm_products_fetched' => count($ucrmProducts),
            'last_ucrm_id'          => $lastUcrmId,
            'force_update'          => $forceUpdate,
            'summary'               => [
                'total_active'  => array_sum(array_column($results, 'total')),
                'total_created' => $totalCreated,
                'total_skipped' => $totalSkipped,
                'total_failed'  => $totalFailed,
            ],
            'details' => $results,
            'hint'    => $dryRun
                ? 'Add &dry_run=0 to apply changes in UCRM'
                : ($totalCreated > 0 ? 'Products synced! Names/prices updated where needed.' : 'All products already in sync.'),
        ]);
    }

    // ── Sync single hardware item to UCRM product ────────────────────────────
    // POST ?page=api&action=sync_hardware_to_ucrm  { hw_id: N }
    if ($act === 'sync_hardware_to_ucrm' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $hwId = (int)($body['hw_id'] ?? 0);
        if (!$hwId) $er2('hw_id required.', 422);

        $devices = $store->load('kyc_devices.json') ?? [];
        $hw = null; $hwIdx = null;
        foreach ($devices as $i => $d2) {
            if ((int)($d2['id'] ?? 0) === $hwId) { $hw = $d2; $hwIdx = $i; break; }
        }
        if (!$hw) $er2('Hardware not found.', 404);

        $hwName = trim($hw['title'] ?? $hw['name'] ?? 'Hardware');
        $hwPrice = (float)($hw['sell_price'] ?? 0);
        $payload = ['name'=>$hwName,'invoiceLabel'=>$hwName,'unit'=>'Nos','price'=>$hwPrice,'taxable'=>false];
        $existId = (int)($hw['ucrm_product_id'] ?? 0);

        if ($existId > 0) {
            // Update name+price in UCRM to match plugin
            $crm->patch("products/{$existId}", $payload);
            $ok2(['ucrm_product_id' => $existId, 'action' => 'updated']);
        } else {
            // Check if UCRM already has a product with same name
            $ucrmAll = $crm->get('products') ?: [];
            $matchId = null;
            foreach ($ucrmAll as $up) {
                if (strtolower(trim($up['name'] ?? '')) === strtolower($hwName)) {
                    $matchId = (int)$up['id']; break;
                }
            }
            if ($matchId) {
                $crm->patch("products/{$matchId}", $payload);
                $devices[$hwIdx]['ucrm_product_id'] = $matchId;
                $store->save('kyc_devices.json', $devices);
                $ok2(['ucrm_product_id' => $matchId, 'action' => 'matched_and_updated']);
            } else {
                $resp = $crm->post('products', $payload);
                if (empty($resp['id'])) $er2('UCRM did not return product ID: ' . json_encode($crm->getLastError()));
                $newId = (int)$resp['id'];
                $devices[$hwIdx]['ucrm_product_id'] = $newId;
                $store->save('kyc_devices.json', $devices);
                $ok2(['ucrm_product_id' => $newId, 'action' => 'created']);
            }
        }
    }

    // ── Sync single plan to UCRM product ────────────────────────────────────
    // POST ?page=api&action=sync_plan_to_ucrm  { plan_id: N }
    if ($act === 'sync_plan_to_ucrm' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $planId = (int)($body['plan_id'] ?? 0);
        if (!$planId) $er2('plan_id required.', 422);

        $plans = $store->load('subscription_plans.json') ?? [];
        $plan  = null; $idx = null;
        foreach ($plans as $i => $p) {
            if ((int)($p['id'] ?? 0) === $planId) { $plan = $p; $idx = $i; break; }
        }
        if (!$plan) $er2('Plan not found.', 404);

        $planName  = trim($plan['name']);
        $planPrice = (float)($plan['customer_price'] ?? 0);
        $payload   = ['name'=>$planName,'invoiceLabel'=>$planName,'unit'=>'Month','price'=>$planPrice,'taxable'=>false];
        $existingId = (int)($plan['ucrm_product_id'] ?? 0);

        if ($existingId > 0) {
            // Update name+price — keeps UCRM in sync with plugin
            $crm->patch("products/{$existingId}", $payload);
            $ok2(['ucrm_product_id' => $existingId, 'action' => 'updated']);
        } else {
            // Check name match in UCRM first
            $ucrmAll = $crm->get('products') ?: [];
            $matchId = null;
            foreach ($ucrmAll as $up) {
                if (strtolower(trim($up['name'] ?? '')) === strtolower($planName)) {
                    $matchId = (int)$up['id']; break;
                }
            }
            if ($matchId) {
                $crm->patch("products/{$matchId}", $payload);
                $plans[$idx]['ucrm_product_id'] = $matchId;
                $store->save('subscription_plans.json', $plans);
                $ok2(['ucrm_product_id' => $matchId, 'action' => 'matched_and_updated']);
            } else {
                $resp = $crm->post('products', $payload);
                if (empty($resp['id'])) $er2('UCRM did not return product ID: ' . json_encode($crm->getLastError()));
                $newId = (int)$resp['id'];
                $plans[$idx]['ucrm_product_id'] = $newId;
                $store->save('subscription_plans.json', $plans);
                $ok2(['ucrm_product_id' => $newId, 'action' => 'created']);
            }
        }
    }

    // ─── DIAGNOSTIC: Check quote setup (plans, devices, config) ──────────────
    // GET ?page=api&action=quote_setup_debug
    if ($act === 'quote_setup_debug' && $met === 'GET') {
        $plans1 = $store->load('subscription_plans.json') ?? [];
        $plans2 = $store->load('kyc_packages.json') ?? [];
        $devices = $store->load('kyc_devices.json') ?? [];
        $ucrmProducts = $store->load('ucrm_products.json') ?? [];
        
        $activePlans1 = array_filter($plans1, function($p) { return !empty($p['is_active']); });
        $activePlans2 = array_filter($plans2, function($p) { return !empty($p['is_active']); });
        $activeDevices = array_filter($devices, function($d) { return !empty($d['is_active']); });
        
        // Count items with UCRM product mapping
        $mappedPlans1 = array_filter($plans1, function($p) { return !empty($p['ucrm_product_id']); });
        $mappedPlans2 = array_filter($plans2, function($p) { return !empty($p['ucrm_product_id']); });
        $mappedDevices = array_filter($devices, function($d) { return !empty($d['ucrm_product_id']); });
        
        $ok2([
            'subscription_plans' => [
                'total' => count($plans1),
                'active' => count($activePlans1),
                'mapped_to_ucrm' => count($mappedPlans1),
                'sample' => array_slice(array_values($activePlans1), 0, 3),
            ],
            'kyc_packages' => [
                'total' => count($plans2),
                'active' => count($activePlans2),
                'mapped_to_ucrm' => count($mappedPlans2),
                'sample' => array_slice(array_values($activePlans2), 0, 3),
            ],
            'kyc_devices' => [
                'total' => count($devices),
                'active' => count($activeDevices),
                'mapped_to_ucrm' => count($mappedDevices),
                'sample' => array_slice(array_values($activeDevices), 0, 3),
            ],
            'ucrm_products' => [
                'synced' => count($ucrmProducts),
                'last_sync' => !empty($ucrmProducts) ? ($ucrmProducts[0]['synced_at'] ?? 'unknown') : 'never',
            ],
            'config' => [
                'kyc_auto_quote_enabled' => ($config['kyc_auto_quote_enabled'] ?? true) !== false,
                'kyc_quote_validity_days' => (int)($config['kyc_quote_validity_days'] ?? 7),
                'quote_prefix' => $config['quote_prefix'] ?? 'QUO',
                'fiber_install_product_id' => (int)($config['fiber_install_product_id'] ?? 244),
            ],
            'tip' => 'Run sync_ucrm_products then auto_map_products to link local items to UCRM products.',
        ]);
    }
