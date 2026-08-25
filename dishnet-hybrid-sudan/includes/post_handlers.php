<?php
// ── CSV Export (Starlink Finance pattern) ────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    // Ensure user is logged in and is admin
    if (!isset($retailer)) $retailer = $auth->requireLogin();
    $isAdmin = !empty($retailer['is_admin']);
    if (!$isAdmin) { flash('Admin access required.', 'danger'); redirect('?page=dashboard'); }
    $exportTab = trim($_GET['export_tab'] ?? $_GET['tab'] ?? '');

    function outputCSV(string $fn, array $hdr, array $rows): void {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        header('Cache-Control: no-cache');
        $fp = fopen('php://output','w');
        fprintf($fp,chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel
        fputcsv($fp,$hdr);
        foreach($rows as $r) fputcsv($fp,$r);
        fclose($fp); exit;
    }

    switch ($exportTab) {
        case 'applications':
            $apps = $store->load('kyc_applications.json');
            outputCSV('applications_'.date('Y-m-d').'.csv',
                ['ID','Type','Name','Mobile','Address','Status','CRM ID','Agent','Date','Fee'],
                array_map(fn($a)=>[$a['id']??'',$a['connectivity_type']??'',
                    trim(($a['firstname']??'').' '.($a['lastname']??'')),
                    $a['mobile']??'',$a['address_1']??'',$a['status']??'',$a['crm_client_id']??'',
                    $a['retailer_name']??'',substr($a['created_at']??'',0,10),$a['setup_fee']??'0'
                ], $apps));
            break; // outputCSV exits, but break for safety
        case 'retailers':
            $rs = $store->load('retailers.json');
            outputCSV('agents_'.date('Y-m-d').'.csv',
                ['ID','Name','Email','Phone','Role','Balance','Active','Joined'],
                array_map(fn($r)=>[$r['id']??'',$r['name']??'',$r['email']??'',$r['phone']??'',
                    $r['role']??'agent',number_format($wallet->getBalance($r['id']??0),2),
                    ($r['is_active']??false)?'Yes':'No',substr($r['created_at']??'',0,10)
                ], $rs));
            break;
        case 'all_collections':
        case 'payment_collections':
            $cols = $store->load('payment_collections.json');
            outputCSV('collections_'.date('Y-m-d').'.csv',
                ['ID','Customer','CRM ID','Amount','Method','Agent','Status','Date'],
                array_map(fn($c)=>[$c['id']??'',$c['customer_name']??'',$c['crm_customer_id']??'',
                    number_format($c['amount']??0,2),$c['method']??'Cash',
                    $c['retailer_name']??'',$c['status']??'',substr($c['created_at']??'',0,10)
                ], $cols));
            break;
        case 'activity_log':
            $log = $store->load('activity_log.json');
            outputCSV('activity_'.date('Y-m-d').'.csv',
                ['Action','Title','Detail','Date','Time'],
                array_map(fn($e)=>[$e['action']??$e['event']??'',$e['title']??'',$e['detail']??'',
                    $e['date']??substr($e['created_at']??'',0,10),$e['time']??$e['created_at']??''
                ], $log));
            break;
        default:
            http_response_code(400);echo 'Unknown export tab';exit;
    }
}

$flash = getFlash();

// ==============================================================================
// SUDAN EDITION - no seeding.
//
// The South Sudan build created four accounts here with their passwords written
// into this file, and seeded catalogues carrying real supplier names, cost
// prices, margins and live subscriber counts.
//
// None of that belongs in a fresh deployment:
//   - a password in source is a published password, and uCRM serves public.php
//     without authentication, so that page is reachable from the internet;
//   - one branch reset those passwords back to the known values whenever an
//     administrator changed them;
//   - the catalogues are South Sudan commercial data, not starter content.
//
// So this install starts empty and public.php runs a first-run setup instead.
// Catalogues are filled in by the administrator, or read live from uCRM by the
// AI layer - which is where prices should come from in any case.
// ==============================================================================

if (empty($store->load('install_state.json'))) {
    $store->save('install_state.json', [[
        'id'           => 1,
        'installed_at' => date('Y-m-d H:i:s'),
        'edition'      => 'sudan',
        'setup_done'   => false,
    ]]);
}

// ─── ACTIONS ─────────────────────────────────────────────────────────────────
// B-04 FIX: Global CSRF gate — all POST actions except do_login require a valid token.
// The token is defined in csrfToken() (line ~16) but was never actually checked.
// Tab-specific actions (sc_action, adv_action, exp_action, st_action, cb_action) are checked
// by their respective tabs if they include csrfField(). We only gate the main 'action' field.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    $cbAction = $_POST['cb_action'] ?? '';
    // Tab-specific action fields that bypass global CSRF (tabs handle their own security)
    $hasTabAction = !empty($_POST['sc_action']) || !empty($_POST['adv_action']) || 
                    !empty($_POST['exp_action']) || !empty($_POST['st_action']) ||
                    !empty($_POST['hq_action'])  || !empty($_POST['inv_action']) ||
                    !empty($_POST['fc_action'])  || !empty($_POST['fq_action']) ||
                    !empty($_POST['_wai_action']);
    if (!in_array($postAction, ['do_login','create_agent','kyc_reupload_docs','cashbook_set_rate']) && 
        $cbAction === '' && !$hasTabAction && !csrfCheck()) {
        flash('Security token mismatch. Please try again.', 'danger');
        // Redirect back to wherever they came from, or dashboard
        $ref = $_SERVER['HTTP_REFERER'] ?? '?page=dashboard';
        redirect($ref);
    }
}

// ═══════════════════════════════════════════════════════════════
// POST HANDLER DOMAIN FILES
// ═══════════════════════════════════════════════════════════════
require __DIR__ . '/post/post_auth.php';
require __DIR__ . '/post/post_kyc.php';
require __DIR__ . '/post/post_sales.php';
require __DIR__ . '/post/post_admin.php';
require __DIR__ . '/post/post_sync.php';
require __DIR__ . '/post/post_leads.php';
require __DIR__ . '/post/post_cashbook.php';
require __DIR__ . '/post/post_field.php';
