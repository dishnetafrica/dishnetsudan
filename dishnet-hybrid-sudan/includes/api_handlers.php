<?php
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
    $act = $_GET['action'] ?? ''; $met = $_SERVER['REQUEST_METHOD'];
    $body = ($met === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $ok2 = function($d,$m='OK',$c=200){ob_end_clean();http_response_code($c);echo json_encode(['status'=>'success','message'=>$m,'data'=>$d]);exit;};
    $er2 = function($m,$c=400){ob_end_clean();http_response_code($c);echo json_encode(['status'=>'error','message'=>$m]);exit;};

    /**
     * Shared helper — rebuilds client_search_index.json from raw UCRM clients array.
     * Used by: bg_client_sync, ucrm_pull_sync, client_search_index fallback.
     * Index shape: id, name, phone, email, bal, plans, username, status, search.
     * All plugin features load from this — never rebuild from ucrm_clients_cache directly.
     */
    if (!function_exists('_buildClientSearchIndex')) {
        function _buildClientSearchIndex($store, array $clients): array {
            $planNames   = []; $svcByClient = [];
            try {
                foreach ($store->load('ucrm_plans_cache.json') ?? [] as $p) {
                    if (!empty($p['id'])) $planNames[(int)$p['id']] = $p['name'] ?? '';
                }
                foreach ($store->load('ucrm_services_cache.json') ?? [] as $s) {
                    $cid = (int)($s['clientId'] ?? $s['_clientId'] ?? 0);
                    if ($cid) $svcByClient[$cid][] = $planNames[(int)($s['servicePlanId'] ?? 0)] ?? '';
                }
            } catch (\Throwable $e) {}

            $idx = [];
            foreach ($clients as $c) {
                $cid = (int)($c['id'] ?? 0); if (!$cid) continue;
                $fn = trim($c['firstName'] ?? $c['first_name'] ?? '');
                $ln = trim($c['lastName']  ?? $c['last_name']  ?? '');
                $nm = trim("$fn $ln");
                if (!$nm) $nm = trim($c['companyName'] ?? $c['company_name'] ?? $c['username'] ?? '');
                $ph = ''; $em = ''; $xn = [];
                foreach ($c['contacts'] ?? [] as $ct) {
                    if (!$ph) { if (!empty($ct['phone'])) $ph = $ct['phone']; elseif (!empty($ct['phones'][0]['number'])) $ph = $ct['phones'][0]['number']; }
                    if (!$em && !empty($ct['email'])) $em = $ct['email'];
                    if (!empty($ct['name'])) $xn[] = $ct['name'];
                }
                if (!$ph) $ph = trim($c['phone'] ?? $c['mobile'] ?? '');
                if (!$em) $em = trim($c['email'] ?? '');
                $un = trim($c['username'] ?? ''); $co = trim($c['companyName'] ?? $c['company_name'] ?? '');
                $pl = implode(' ', array_filter($svcByClient[$cid] ?? []));
                $xns = implode(' ', $xn);
                $idx[] = ['id'=>$cid,'name'=>$nm,'phone'=>$ph,'email'=>$em,'bal'=>(float)($c['accountBalance']??0),
                          'plans'=>$pl,'username'=>$un,'status'=>($c['status']??''),
                          'search'=>strtolower("$nm $ph $em $cid $pl $xns $un $co")];
            }
            $store->save('client_search_index.json', $idx);
            return $idx;
        }
    }



    // ═══════════════════════════════════════════════════════════════
    // PRE-AUTH DOMAIN HANDLERS (no Bearer/session required)
    // ═══════════════════════════════════════════════════════════════
    require __DIR__ . '/api/api_public.php';
    require __DIR__ . '/api/api_payments_admin.php';
    require __DIR__ . '/api/api_products_admin.php';
    require __DIR__ . '/api/api_cron_debug.php';

    // ═══════════════════════════════════════════════════════════════
    // AUTH GUARD — Bearer token or browser session required below
    // ═══════════════════════════════════════════════════════════════
    $me2 = $auth->tokenAuth();

    // Session fallback: if no Bearer token, check if logged in via browser session
    // (allows admin to access API endpoints directly via browser URL)
    if (!$me2) {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $sessData = $_SESSION['kyc_retailer'] ?? null;
        if ($sessData && !empty($sessData['id'])) {
            // Use the full cached record if available, otherwise the session summary
            $me2 = $sessData['cached_record'] ?? $sessData;
        }
    }

    if (!$me2) $er2('Unauthorized.', 401);
    $rid        = (int)$me2['id'];

    // ── Global auth helpers — available to ALL handlers below ────────────────
    $isAdmin    = (bool)($me2['is_admin'] ?? false);
    $retailerId = $rid;
    $retailer   = $me2;
    $can        = function(string $perm) use ($me2): bool {
        if ($me2['is_admin'] ?? false) return true;
        $mods = $me2['modules'] ?? [];
        return in_array($perm, is_array($mods) ? $mods : [], true);
    };

    // ═══════════════════════════════════════════════════════════════
    // POST-AUTH DOMAIN HANDLERS
    // ═══════════════════════════════════════════════════════════════
    require __DIR__ . '/api/api_retailer.php';
    require __DIR__ . '/api/api_scheduling.php';
    require __DIR__ . '/api/api_splynx.php';
    require __DIR__ . '/api/api_crm_misc.php';
    require __DIR__ . '/api/api_lte.php';
    require __DIR__ . '/api/api_whatsapp.php';
    require __DIR__ . '/api/api_support.php';
    require __DIR__ . '/api/api_field_ops.php';
    require __DIR__ . '/api/api_leads.php';
    require __DIR__ . '/api/api_cashbook.php';
    require __DIR__ . '/api/api_crm_sync.php';
    require __DIR__ . '/api/api_lte_admin.php';
    require __DIR__ . '/api/api_notifications.php';
    require __DIR__ . '/api/api_fiber_purchase.php';
    require __DIR__ . '/api/api_hrm.php';
    require __DIR__ . '/api/api_staff_ledger.php';

    $er2("Unknown API action: {$act}", 404);
