<?php
// ════════════════════════════════════════════════════════════════════
// Customer Portal — Shared Data Loader
// ════════════════════════════════════════════════════════════════════
// Extracted from portal.php in v4.13.0 as part of the desktop portal
// split. Loads all customer data caches and sets $portal* globals that
// both the mobile template (portal.php) and desktop views (views/*.php)
// consume.
//
// Sets on success:
//   $portalCustomer, $portalFullClient, $portalService, $portalPlanName,
//   $portalAllServices, $portalInvoices, $portalAllUnpaid, $portalUnpaidCount,
//   $portalUnpaidTotal, $portalTotalInvoiceCount, $portalUsage, $portalRouter,
//   $portalSites, $portalActiveCount, $portalTotalUsageGb,
//   $portalCustomerId, $portalClaims, $portalCustomerName, $portalFirstName,
//   $portalServiceType, $portalLocation, $portalPrice, $portalCurrency,
//   $portalNextBill, $portalDaysLeft
//
// Sets on failure:
//   $portalAuthError (string)  — templates should check this first
//
// Requires in scope from caller (public.php):
//   $store   — from svc('store') / Storage instance
//   $config  — from loaded config
//
// Reads caches (via $store->load):
//   client_search_index.json, ucrm_clients_cache.json, ucrm_services_cache.json,
//   ucrm_plans_cache.json, ucrm_invoices_cache.json
//
// Reads cross-plugin data (direct file reads):
//   ../dishnet-starlink-finance/data/sl_kits.json
//   ../dishnet-data-report/data/sl_usage.json
//   ../dishnet-data-report/data/sl_svc_cache.json
//   ../dishnet-data-report/data/wifi_router_map.json
//
// Rule: this file must produce IDENTICAL state to the pre-v4.13.0
// inline loader. If anything changes behavior, it's a regression.
// ════════════════════════════════════════════════════════════════════

// Safe JSON file reader — prevents json_decode(false) fatal on PHP 8.x
function portalJsonLoad(string $path): array {
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return [];
    return json_decode($raw, true) ?: [];
}
//   Native exposes window.DishNet.* for: biometric, logout, openWhatsApp,
//   openWifi, share, shake (haptic).
//
// Why a single file: all views share CSS + the JS bridge, easier to
// maintain + ship than N split files. If it grows >1500 lines, split.
// ════════════════════════════════════════════════════════════════════

// ── Auth: verify Bearer JWT (same approach as ca_require_auth in API) ──
require_once dirname(__DIR__, 2) . '/lib/JwtAuth.php';

$portalAuthError = null;
$portalClaims = null;

$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (empty($hdr) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}
// Fallback: token can be passed as query param for direct browser testing,
// or stored in dn_customer_token cookie for PWA access.
// Production WebView always uses Authorization header.
$fallbackToken = trim($_GET['token'] ?? '');
$cookieToken = trim($_COOKIE['dn_customer_token'] ?? '');

$token = '';
if (preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) {
    $token = trim($m[1]);
} elseif ($fallbackToken !== '') {
    $token = $fallbackToken;
} elseif ($cookieToken !== '') {
    $token = $cookieToken;
}

if (!$token) {
    // No token — redirect to web login page (PWA flow)
    $loginUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?page=customer_login';
    header('Location: ' . $loginUrl);
    exit;
} else {
    try {
        $jwtAuth = JwtAuth::fromConfig($config);
        $portalClaims = $jwtAuth->verify($token);
        if (($portalClaims['kind'] ?? '') !== 'app') {
            $portalAuthError = 'Wrong token type.';
        }
    } catch (\RuntimeException $e) {
        $portalAuthError = 'Invalid or expired token.';
    }
}

// ── Load customer data from caches ──
// v4.12.20 — Resolve active account from header / query / cookie, validated against
// the JWT accounts claim. Falls back to sub (primary) if none specified or invalid.
$portalCustomerId = $portalClaims ? (int)($portalClaims['sub'] ?? 0) : 0;
if ($portalClaims && !empty($portalClaims['accounts']) && is_array($portalClaims['accounts'])) {
    $_allowedIds = [];
    foreach ($portalClaims['accounts'] as $_a) {
        $_aid = (int)($_a['id'] ?? 0);
        if ($_aid > 0) $_allowedIds[] = $_aid;
    }
    $_requestedId = 0;
    if (isset($_SERVER['HTTP_X_ACCOUNT_ID'])) {
        $_requestedId = (int)$_SERVER['HTTP_X_ACCOUNT_ID'];
    } elseif (!empty($_GET['account_id'])) {
        $_requestedId = (int)$_GET['account_id'];
    } elseif (!empty($_COOKIE['dn_active_account_id'])) {
        $_requestedId = (int)$_COOKIE['dn_active_account_id'];
    }
    if ($_requestedId > 0 && in_array($_requestedId, $_allowedIds, true)) {
        $portalCustomerId = $_requestedId;
    }
}
$portalCustomer = null;
$portalFullClient = null;
$portalService = null;
$portalPlanName = '';
$portalInvoices = [];

// ── v4.20.7 — UCRM Client Zone deep-link base URL ──────────────────
// Used by portal.php to build "Pay with Card" CTAs that open the
// customer's UCRM Client Zone (where Stripe credit-card / autopay
// flows live). UCRM does not expose a tokenized public payment URL
// via the API on this version, so all card payments route through
// Client Zone (login required, one-time per browser session).
//
// Source priority:
//   1. Optional config override `crm_base_url` (admin can pin a value)
//   2. ucrm.json `ucrmPublicUrl` (auto-populated by UCRM, has trailing /crm/)
//   3. Hardcoded fallback (defensive — should never hit)
$portalCrmBaseUrl = '';
$_cfgBase = trim((string)($config['crm_base_url'] ?? ''));
if ($_cfgBase !== '') {
    $portalCrmBaseUrl = rtrim($_cfgBase, '/');
} else {
    $_pluginRoot = dirname(__DIR__, 2);
    foreach ([$_pluginRoot . '/ucrm.json', $_pluginRoot . '/data/ucrm.json'] as $_p) {
        if (is_file($_p)) {
            $_uj = json_decode((string)@file_get_contents($_p), true);
            if (is_array($_uj) && !empty($_uj['ucrmPublicUrl'])) {
                $portalCrmBaseUrl = rtrim((string)$_uj['ucrmPublicUrl'], '/');
                break;
            }
        }
    }
}
if ($portalCrmBaseUrl === '') {
    $portalCrmBaseUrl = 'https://crm.dishnetafrica.com/crm';
}

// v4.20.9.1 — Normalize: ucrm.json's ucrmPublicUrl on this install is
// populated as https://host/crm/api/v2.1 (the API URL) rather than the
// bare CRM web URL https://host/crm — so without normalization, Pay
// buttons route to the JSON API and 404. Strip /api/vN.M and any
// trailing /crm, then re-append /crm so we always end up at the web
// root used by /client-zone/...
//
// All these inputs normalize to https://crm.dishnetafrica.com/crm:
//   https://crm.dishnetafrica.com/crm/api/v2.1
//   https://crm.dishnetafrica.com/crm/api/v1.0/
//   https://crm.dishnetafrica.com/crm
//   https://crm.dishnetafrica.com
$portalCrmBaseUrl = preg_replace('#/api/v[0-9.]+/?$#', '', $portalCrmBaseUrl);
$portalCrmBaseUrl = preg_replace('#/crm/?$#', '', $portalCrmBaseUrl);
$portalCrmBaseUrl = rtrim($portalCrmBaseUrl, '/') . '/crm';

if ($portalCustomerId && !$portalAuthError) {
    // Customer from index
    foreach ($store->load('client_search_index.json') ?? [] as $row) {
        if ((int)($row['id'] ?? 0) === $portalCustomerId) {
            $portalCustomer = $row;
            break;
        }
    }
    if (!$portalCustomer) {
        $portalAuthError = 'Account not found.';
    }
}

if (!$portalAuthError) {
    // Full client record (for address)
    foreach ($store->load('ucrm_clients_cache.json') ?? [] as $row) {
        if ((int)($row['id'] ?? 0) === $portalCustomerId) {
            $portalFullClient = $row;
            break;
        }
    }

    // Active service + plan (legacy single-service — kept for backward compat)
    $portalAllServices = [];
    foreach ($store->load('ucrm_services_cache.json') ?? [] as $s) {
        $cid = (int)($s['clientId'] ?? $s['_clientId'] ?? 0);
        if ($cid !== $portalCustomerId) continue;
        $portalAllServices[] = $s;
        if ((int)($s['status'] ?? 0) === 1) {
            if (!$portalService) $portalService = $s;
        }
        if (!$portalService) $portalService = $s;
    }
    if ($portalService) {
        $portalPlanName = trim($portalService['name'] ?? '');
        if (!$portalPlanName && !empty($portalService['servicePlanId'])) {
            foreach ($store->load('ucrm_plans_cache.json') ?? [] as $p) {
                if ((int)($p['id'] ?? 0) === (int)$portalService['servicePlanId']) {
                    $portalPlanName = $p['name'] ?? '';
                    break;
                }
            }
        }
    }

    // Invoices
    foreach ($store->load('ucrm_invoices_cache.json') ?? [] as $inv) {
        if ((int)($inv['clientId'] ?? 0) !== $portalCustomerId) continue;
        $total = (float)($inv['total'] ?? 0);
        $paid = (float)($inv['amountPaid'] ?? 0);
        $ucrmStatus = (int)($inv['status'] ?? 0);
        if ($ucrmStatus === 4 || $paid >= $total) $st = 'paid';
        elseif ($ucrmStatus === 6) $st = 'overdue';
        else {
            $dueDate = $inv['dueDate'] ?? null;
            $st = ($dueDate && strtotime($dueDate) < time()) ? 'overdue' : 'pending';
        }
        $portalInvoices[] = [
            'id' => (int)$inv['id'],
            'number' => $inv['number'] ?? ('INV-' . (int)$inv['id']),
            'total' => $total,
            'due' => max(0, $total - $paid),
            'currency' => $inv['currencyCode'] ?? 'USD',
            'status' => $st,
            'created' => $inv['createdDate'] ?? null,
            'due_date' => $inv['dueDate'] ?? null,
            'description' => !empty($inv['items'][0]['label']) ? $inv['items'][0]['label'] : 'Service',
        ];
    }
    // Sort newest first
    usort($portalInvoices, function($a, $b) {
        return strcmp($b['created'] ?? '', $a['created'] ?? '');
    });

    // Calculate REAL totals from ALL invoices before slicing for display
    $portalAllUnpaid = array_filter($portalInvoices, function($i) { return $i['status'] !== 'paid'; });
    $portalUnpaidCount = count($portalAllUnpaid);
    $portalUnpaidTotal = 0.0;
    foreach ($portalAllUnpaid as $inv) {
        $portalUnpaidTotal += (float)($inv['due'] ?? 0);
    }
    $portalTotalInvoiceCount = count($portalInvoices);

    // Show all invoices (no arbitrary limit — blueCARD has 50+)
    // Only slice for the display list if truly excessive
    if (count($portalInvoices) > 100) {
        $portalInvoices = array_slice($portalInvoices, 0, 100);
    }

    // ── Usage data ──────────────────────────────────────────────────
    // Primary: dishnet-data-report plugin (syncs hourly via Starlink API)
    // Fallback: dishnet-starlink-finance plugin (synced less frequently)
    // Chain: CRM client_id → sl_kits.json → kit_number → sl_usage.json
    $portalUsage = null;
    try {
        $pluginsBase = dirname(dirname(dirname(__DIR__)));

        // KITs data — try both plugins for the mapping
        $kitsData = [];
        foreach (['dishnet-starlink-finance', 'dishnet-data-report'] as $kp) {
            $kf = $pluginsBase . '/' . $kp . '/data/sl_kits.json';
            if (is_file($kf)) {
                $kd = portalJsonLoad($kf);
                if (is_array($kd) && !empty($kd)) { $kitsData = $kd; break; }
            }
        }

        // Usage data — Data Report first (fresher), Finance as fallback
        $allUsage = [];
        foreach (['dishnet-data-report', 'dishnet-starlink-finance'] as $up) {
            $uf = $pluginsBase . '/' . $up . '/data/sl_usage.json';
            if (is_file($uf)) {
                $ud = portalJsonLoad($uf);
                if (is_array($ud) && !empty($ud)) { $allUsage = $ud; break; }
            }
        }

        // Also try the Data Report plugin's service line cache for kit resolution
        $slSvcCache = [];
        $svcCacheFile = $pluginsBase . '/dishnet-data-report/data/sl_svc_cache.json';
        if (is_file($svcCacheFile)) {
            $slSvcCache = portalJsonLoad($svcCacheFile);
        }

        if (!empty($kitsData) && !empty($allUsage)) {
            // Find KIT(s) for this customer
            $customerKits = [];
            $customerSLs = [];
            foreach ($kitsData as $kit) {
                $kitCrmId = (int)($kit['crm_client_id'] ?? $kit['assigned_client_id'] ?? 0);
                if ($kitCrmId === $portalCustomerId) {
                    $kn = $kit['kit_number'] ?? '';
                    if ($kn) $customerKits[] = $kn;
                    // Also track service line if available
                    $sl = $kit['service_line'] ?? '';
                    if ($sl) $customerSLs[] = $sl;
                }
            }

            // Also check sl_svc_cache for additional SL→kit mappings
            foreach ($slSvcCache as $slEntry) {
                $eKit = $slEntry['kit_number'] ?? '';
                $eSl = $slEntry['service_line'] ?? '';
                if ($eKit && in_array($eKit, $customerKits, true) && $eSl) {
                    $customerSLs[] = $eSl;
                }
            }

            $customerSLs = array_unique($customerSLs);

            // Find most recent usage record matching any customer KIT or service line
            if (!empty($customerKits) || !empty($customerSLs)) {
                $bestUsage = null;
                $bestKey = '';
                foreach ($allUsage as $u) {
                    $uKit = $u['kit_number'] ?? '';
                    $uSl = $u['service_line'] ?? '';
                    $match = false;
                    // Match by kit_number
                    if ($uKit && in_array($uKit, $customerKits, true)) $match = true;
                    // Match by service_line
                    if (!$match && $uSl && in_array($uSl, $customerSLs, true)) $match = true;
                    // Cross-match: usage has kit_number, we have same kit
                    if (!$match && $uKit) {
                        foreach ($customerKits as $ck) {
                            if ($ck === $uKit) { $match = true; break; }
                        }
                    }
                    if (!$match) continue;
                    $ck = $u['cycle_key'] ?? '';
                    if ($ck > $bestKey) {
                        $bestKey = $ck;
                        $bestUsage = $u;
                    }
                }
                if ($bestUsage) {
                    $totalGb = (float)($bestUsage['total_gb'] ?? 0);
                    $allowance = $bestUsage['local_priority_allowance'] ?? $bestUsage['other_data_allowance'] ?? null;
                    $isUnlimited = ($allowance === 'Unlimited' || $allowance === null || $allowance === '');
                    $limitGb = $isUnlimited ? null : (int)$allowance;
                    $pct = ($limitGb && $limitGb > 0) ? min(100, (int)round($totalGb / $limitGb * 100)) : null;

                    $dailyWhite = $bestUsage['daily_white'] ?? [];
                    $dailyBlue = $bestUsage['daily_blue'] ?? [];
                    $dailyTotal = [];
                    $maxDays = max(count($dailyWhite), count($dailyBlue));
                    for ($i = 0; $i < $maxDays; $i++) {
                        $dailyTotal[] = round(($dailyWhite[$i] ?? 0) + ($dailyBlue[$i] ?? 0), 2);
                    }

                    $portalUsage = [
                        'total_gb' => round($totalGb, 1),
                        'limit_gb' => $limitGb,
                        'unlimited' => $isUnlimited,
                        'pct' => $pct,
                        'cycle_label' => $bestUsage['cycle_label'] ?? '',
                        'cycle_start' => $bestUsage['axis_left'] ?? '',
                        'cycle_end' => $bestUsage['axis_right'] ?? '',
                        'updated' => $bestUsage['updated_at'] ?? '',
                        'daily' => $dailyTotal,
                        'plan_id' => $bestUsage['plan_id'] ?? '',
                        'source' => basename(dirname($uf ?? '')),
                    ];
                }
            }
        }
    } catch (\Throwable $e) {
        // Silent — usage is optional
    }

    // ── WiFi router info: find customer's router from Data Report ──
    $portalRouter = null;
    try {
        $pluginsBase = dirname(dirname(dirname(__DIR__)));
        $routerMapFile = $pluginsBase . '/dishnet-data-report/data/wifi_router_map.json';
        if (is_file($routerMapFile)) {
            $routerMap = portalJsonLoad($routerMapFile);
            // Also load kits to map CRM client → kit → router
            $kitsForRouter = [];
            foreach (['dishnet-starlink-finance', 'dishnet-data-report'] as $kp2) {
                $kf2 = $pluginsBase . '/' . $kp2 . '/data/sl_kits.json';
                if (is_file($kf2)) {
                    $kd2 = portalJsonLoad($kf2);
                    if (is_array($kd2) && !empty($kd2)) { $kitsForRouter = $kd2; break; }
                }
            }
            // Find KIT serials + service lines + account numbers for this customer
            $myKitSerials = [];
            $myServiceLines = [];
            $myAccNums = [];
            foreach ($kitsForRouter as $kit) {
                $kitCrmId = (int)($kit['crm_client_id'] ?? $kit['assigned_client_id'] ?? 0);
                if ($kitCrmId === $portalCustomerId) {
                    $sn = $kit['serial_number'] ?? $kit['kit_number'] ?? '';
                    if ($sn) $myKitSerials[] = $sn;
                    $mySl = $kit['service_line'] ?? '';
                    if ($mySl) $myServiceLines[] = $mySl;
                    $myAcc = $kit['starlink_account_number'] ?? $kit['account_number'] ?? '';
                    if ($myAcc) $myAccNums[] = $myAcc;
                }
            }
            $myAccNums = array_unique($myAccNums);
            $myServiceLines = array_unique($myServiceLines);

            // Match against router_map
            foreach ($routerMap as $rid => $rInfo) {
                $rKit = $rInfo['kit_serial'] ?? $rInfo['terminal_id'] ?? '';
                $rSl = $rInfo['service_line'] ?? '';
                $rAcc = $rInfo['account_number'] ?? '';
                $match = false;
                // Match by kit serial
                foreach ($myKitSerials as $ms) {
                    if ($rKit && ($rKit === $ms || strpos($rKit, $ms) !== false || strpos($ms, $rKit) !== false)) {
                        $match = true; break;
                    }
                }
                // Match by service_line
                if (!$match && $rSl) {
                    foreach ($myServiceLines as $msl) {
                        if ($rSl === $msl) { $match = true; break; }
                    }
                }
                // Match by account_number (single-router accounts only)
                if (!$match && $rAcc) {
                    foreach ($myAccNums as $ma) {
                        if ($rAcc === $ma) {
                            $accRouterCount2 = 0;
                            foreach ($routerMap as $rr2) {
                                if (($rr2['account_number'] ?? '') === $rAcc) $accRouterCount2++;
                            }
                            if ($accRouterCount2 === 1) { $match = true; break; }
                        }
                    }
                }
                if ($match) {
                    $portalRouter = $rInfo;
                    break; // Take first match
                }
            }
        }
    } catch (\Throwable $e) {
        // Silent
    }

    // ── Multi-site data: build comprehensive per-KIT site list ──────
    $portalSites = [];
    $portalActiveCount = 0;
    $portalTotalUsageGb = 0.0;
    try {
        $pluginsBase = dirname(dirname(dirname(__DIR__)));
        // Load KITs
        $allKitsForSites = [];
        foreach (['dishnet-starlink-finance', 'dishnet-data-report'] as $kp3) {
            $kf3 = $pluginsBase . '/' . $kp3 . '/data/sl_kits.json';
            if (is_file($kf3)) {
                $kd3 = portalJsonLoad($kf3);
                if (is_array($kd3) && !empty($kd3)) { $allKitsForSites = $kd3; break; }
            }
        }
        // Load usage
        $allUsageForSites = [];
        foreach (['dishnet-data-report', 'dishnet-starlink-finance'] as $up2) {
            $uf2 = $pluginsBase . '/' . $up2 . '/data/sl_usage.json';
            if (is_file($uf2)) {
                $ud2 = portalJsonLoad($uf2);
                if (is_array($ud2) && !empty($ud2)) { $allUsageForSites = $ud2; break; }
            }
        }
        // Load routers
        $allRoutersForSites = [];
        $rmf2 = $pluginsBase . '/dishnet-data-report/data/wifi_router_map.json';
        if (is_file($rmf2)) {
            $allRoutersForSites = portalJsonLoad($rmf2);
        }

        // ── v4.21.104: Data Report KIT registry (Starlink-derived liveness) ──
        // dr_kit_registry.json is owned by dishnet-data-report and rebuilt
        // every cron run from live Starlink API responses (cron.php Phase 1
        // → KitRegistryWriter::regenerate). Carries authoritative fields:
        //   subscription_active   bool|null  — Starlink's $sub.active flag
        //                                       (null = Starlink hasn't told us)
        //   pending_activation    bool|null  — KIT shipped but not activated
        //   subscription_paused   bool       — customer/admin paused
        //   subscription_suspended bool      — Starlink-side suspension
        //   sl_status             string     — top-level SL status text
        //   last_starlink_confirmed_at  ISO  — empty if Starlink didn't return
        //                                       this SL on the most recent run
        //
        // Portal trust rule: if last_starlink_confirmed_at is fresh (<24h),
        // Data Report's view wins. Otherwise fall back to Finance's
        // sl_kits.json[starlink_account_status]. This handles the case where
        // Data Report's cron is failing on cookies/auth — the customer should
        // still see SOMETHING (Finance's last-known state) instead of an
        // empty portal.
        $drRegistryByKit = [];
        $drRegistryFile  = $pluginsBase . '/dishnet-data-report/data/dr_kit_registry.json';
        if (is_file($drRegistryFile)) {
            $drDoc = portalJsonLoad($drRegistryFile);
            if (is_array($drDoc) && isset($drDoc['kits']) && is_array($drDoc['kits'])) {
                foreach ($drDoc['kits'] as $kSerial => $kRec) {
                    if (!is_array($kRec)) continue;
                    $drRegistryByKit[strtoupper(trim((string)$kSerial))] = $kRec;
                }
            }
        }
        // Staleness threshold: 24h. If Starlink hasn't confirmed within
        // this window we don't trust the registry's active flag.
        $drStaleSecs = 24 * 3600;
        $nowTs = time();

        // Filter KITs for this customer
        foreach ($allKitsForSites as $kit) {
            $kitCrmId = (int)($kit['crm_client_id'] ?? $kit['assigned_client_id'] ?? 0);
            if ($kitCrmId !== $portalCustomerId) continue;

            $kn = $kit['kit_number'] ?? '';
            $sl = $kit['service_line'] ?? '';
            $financeStatus = strtolower($kit['starlink_account_status'] ?? 'unknown');

            // ── v4.21.104: layered active/inactive resolution ────────────
            // Step 1: try Data Report's authoritative view (Starlink-derived).
            // Step 2: if Data Report's record is missing or stale, fall back
            //         to Finance's manually-maintained status string.
            $drRec = isset($drRegistryByKit[strtoupper(trim((string)$kn))])
                   ? $drRegistryByKit[strtoupper(trim((string)$kn))]
                   : null;

            $drFresh   = false;   // did Starlink confirm this on a recent run?
            $drActive  = null;    // Starlink-derived active flag (or null if unknown)
            $drPaused  = false;
            $drSuspended = false;
            $drStandby = false;
            $drPending = false;
            $drEndDate = '';      // subscription end date (ISO) if any
            $drEndingSoon = false; // true if endDate is set AND within next 60 days
            $statusSource = 'finance';   // 'data_report' | 'finance' (for diagnostics)

            if (is_array($drRec)) {
                $confirmed = (string)($drRec['last_starlink_confirmed_at'] ?? '');
                if ($confirmed !== '') {
                    $confTs = strtotime($confirmed);
                    if ($confTs && ($nowTs - $confTs) < $drStaleSecs) {
                        $drFresh = true;
                    }
                }
                if ($drFresh) {
                    if (array_key_exists('subscription_active', $drRec) && $drRec['subscription_active'] !== null) {
                        $drActive = (bool)$drRec['subscription_active'];
                    }
                    $drPaused    = !empty($drRec['subscription_paused']);
                    $drSuspended = !empty($drRec['subscription_suspended']);
                    $drStandby   = !empty($drRec['subscription_standby']);
                    $drPending   = !empty($drRec['pending_activation']);
                    $drEndDate   = (string)($drRec['subscription_end_date'] ?? '');
                    if ($drEndDate !== '') {
                        $endTs = strtotime($drEndDate);
                        // "Ending" only if endDate is in the future and within 60d.
                        // Past endDates mean the subscription has already ended;
                        // those should already be reflected in subscription_active=false.
                        if ($endTs && $endTs > $nowTs && ($endTs - $nowTs) <= 60 * 86400) {
                            $drEndingSoon = true;
                        }
                    }
                }
            }

            // Effective active = Data Report says active AND not paused/suspended/standby,
            // when fresh. Otherwise use Finance's string.
            //
            // Status precedence (highest specificity wins):
            //   pending_activation > suspended > paused > standby > ending > active/inactive
            // pending_activation outranks others because a KIT can be pending+inactive
            // (subscription not started yet) and the customer needs to know that's the
            // reason. suspended outranks paused because suspension = non-payment which
            // is the more pressing customer message. standby is rare (Mini dishes on
            // standby plan) and outranked by paused/suspended if both apply.
            if ($drFresh && $drActive !== null) {
                $isActive = ($drActive === true) && !$drPaused && !$drSuspended && !$drStandby;
                $statusSource = 'data_report';
                if ($drPending) {
                    $status = 'pending_activation';
                } elseif ($drSuspended) {
                    $status = 'suspended';
                } elseif ($drPaused) {
                    $status = 'paused';
                } elseif ($drStandby) {
                    $status = 'standby';
                } elseif ($drActive === true && $drEndingSoon) {
                    // Active but ending soon — keep is_active=true (service still
                    // works) but surface the date in the badge so customer can act.
                    $status = 'ending';
                } else {
                    $status = $isActive ? 'active' : 'inactive';
                }
            } else {
                // Stale Starlink data or Data Report has no record — fall back
                // to Finance's manually-maintained status. This keeps the
                // portal honest when Data Report's cron is failing for the
                // customer's account (cookies expired, account 401s, etc.)
                // — they still see what we last knew, not a broken portal.
                $status = $financeStatus;
                $isActive = ($financeStatus === 'active');
                $statusSource = 'finance';
            }

            if ($isActive) $portalActiveCount++;

            // Find usage for this KIT (most recent cycle)
            $siteUsage = null;
            $bestCycleKey = '';
            foreach ($allUsageForSites as $u) {
                $uKit = $u['kit_number'] ?? '';
                $uSl = $u['service_line'] ?? '';
                if (($uKit && $uKit === $kn) || ($uSl && $uSl === $sl)) {
                    $ck = $u['cycle_key'] ?? '';
                    if ($ck > $bestCycleKey) {
                        $bestCycleKey = $ck;
                        $siteUsage = $u;
                    }
                }
            }

            $siteGb = $siteUsage ? round((float)($siteUsage['total_gb'] ?? 0), 1) : null;
            if ($siteGb !== null) $portalTotalUsageGb += $siteGb;

            // Find router for this KIT
            $siteRouter = null;
            $sn = $kit['serial_number'] ?? '';
            $kitAccNum = $kit['starlink_account_number'] ?? $kit['account_number'] ?? '';
            foreach ($allRoutersForSites as $rid => $rInfo) {
                $rKit = $rInfo['kit_serial'] ?? $rInfo['terminal_id'] ?? '';
                $rSl = $rInfo['service_line'] ?? '';
                $rAcc = $rInfo['account_number'] ?? '';
                $match = false;
                // Match by kit_number
                if ($rKit && $kn && ($rKit === $kn || strpos($rKit, $kn) !== false || strpos($kn, $rKit) !== false)) {
                    $match = true;
                }
                // Match by serial_number
                if (!$match && $rKit && $sn && ($rKit === $sn || strpos($rKit, $sn) !== false || strpos($sn, $rKit) !== false)) {
                    $match = true;
                }
                // Match by service_line
                if (!$match && $rSl && $sl && $rSl === $sl) {
                    $match = true;
                }
                // Match by account_number (for single-router accounts)
                if (!$match && $rAcc && $kitAccNum && $rAcc === $kitAccNum) {
                    // Only if this account has exactly 1 router in the map
                    $accRouterCount = 0;
                    foreach ($allRoutersForSites as $rr) {
                        if (($rr['account_number'] ?? '') === $rAcc) $accRouterCount++;
                    }
                    if ($accRouterCount === 1) $match = true;
                }
                if ($match) {
                    $siteRouter = $rInfo;
                    break;
                }
            }

            $portalSites[] = [
                'kit_number' => $kn,
                'location' => $kit['location_name'] ?? $kit['location'] ?? $kn,
                'status' => $status,
                'is_active' => $isActive,
                'usage_gb' => $siteGb,
                'usage_cycle' => $siteUsage ? ($siteUsage['cycle_label'] ?? '') : '',
                'usage_updated' => $siteUsage ? ($siteUsage['updated_at'] ?? '') : '',
                'has_router' => !empty($siteRouter),
                'router_id' => $siteRouter['router_id_full'] ?? '',
                'router_nick' => $siteRouter['sl_nickname'] ?? $siteRouter['ut_nickname'] ?? '',
                'service_line' => $sl,
                'plan' => $portalPlanName ?: ($kit['plan_name'] ?? ''),
                'activation_date' => $kit['activation_date'] ?? '',
                // v4.21.105: liveness diagnostics — UI may show a badge
                // when status came from Finance (stale Starlink data) so
                // ops can tell whether the portal is reflecting truth.
                'status_source' => $statusSource,            // 'data_report' | 'finance'
                'starlink_confirmed_at' => is_array($drRec) ? ((string)($drRec['last_starlink_confirmed_at'] ?? '')) : '',
                'starlink_pending_activation' => $drFresh ? $drPending : false,
                // v4.21.105: full Starlink status taxonomy surfaced for UI rendering.
                // status string above already encodes which of these is dominant;
                // the individual flags + end_date let the UI render compound badges
                // (e.g. "Active · Ending 24/05/2026").
                'starlink_paused'      => $drPaused,
                'starlink_suspended'   => $drSuspended,
                'starlink_standby'     => $drStandby,
                'starlink_ending_soon' => $drEndingSoon,
                'subscription_end_date' => $drEndDate,
            ];
        }
        // Sort: active first, then by location name
        usort($portalSites, function($a, $b) {
            if ($a['is_active'] !== $b['is_active']) return $b['is_active'] ? 1 : -1;
            return strcasecmp($a['location'], $b['location']);
        });
        $portalTotalUsageGb = round($portalTotalUsageGb, 1);
    } catch (\Throwable $e) {
        // Silent — sites is optional
    }
}

// ════════════════════════════════════════════════════════════════════
// Paused-state detection — v4.21.42
// ════════════════════════════════════════════════════════════════════
// Joins this customer's KITs with data-report's wifi_test_block_state.json
// (the source of truth for who's currently paused via gRPC test_block).
// Used by home banner + service_status page + sites page to show an
// honest "your service is paused" UX instead of the misleading combo of
// "Pay to keep service active" + "All services operational" the customer
// would otherwise see while their dish is paused.
//
// Sets:
//   $portalIsPaused      (bool)   any of this customer's KITs paused?
//   $portalPausedKits    (array)  KIT serials currently paused
//   $portalPausedRouters (array)  router_ids currently paused
//   $portalPausedSites   (array)  Hybrid-side sites array filtered to paused
//   $portalAllSitesPaused (bool)  ALL of this customer's sites are paused?

$portalIsPaused = false;
$portalPausedKits = [];
$portalPausedRouters = [];
$portalPausedSites = [];
$portalAllSitesPaused = false;

if (!$portalAuthError && !empty($portalCustomerId)) {
    try {
        $pluginsBase = dirname(dirname(dirname(__DIR__)));
        $blockStateFile = $pluginsBase . '/dishnet-data-report/data/wifi_test_block_state.json';
        $routerMapFile2 = $pluginsBase . '/dishnet-data-report/data/wifi_router_map.json';

        if (is_file($blockStateFile) && is_file($routerMapFile2)) {
            $blockState = portalJsonLoad($blockStateFile);
            $routerMap = portalJsonLoad($routerMapFile2);

            if (is_array($blockState) && is_array($routerMap) && !empty($blockState)) {
                // Build set of THIS customer's KITs (uppercase, trimmed)
                $myKits = [];
                foreach ($portalSites as $s) {
                    $k = strtoupper(trim((string)($s['kit_number'] ?? '')));
                    if ($k !== '') $myKits[$k] = true;
                }

                // For each currently-paused router, look up its KIT in the
                // router map and check if it belongs to this customer.
                foreach ($blockState as $rid => $stateRow) {
                    $rawId = (strpos((string)$rid, 'Router-') === 0)
                        ? substr((string)$rid, 7) : (string)$rid;
                    $rmEntry = $routerMap[$rawId] ?? null;
                    if (!is_array($rmEntry)) continue;
                    $kit = strtoupper(trim((string)($rmEntry['kit_serial'] ?? '')));
                    if ($kit === '') continue;
                    if (isset($myKits[$kit])) {
                        $portalPausedKits[$kit] = true;
                        $portalPausedRouters[] = (string)$rid;
                    }
                }
                $portalPausedKits = array_keys($portalPausedKits);
                $portalIsPaused = !empty($portalPausedKits);

                // Filter sites to paused subset (for sites page) and detect
                // the all-paused case (for home banner aggressiveness).
                if ($portalIsPaused && !empty($portalSites)) {
                    foreach ($portalSites as $s) {
                        $k = strtoupper(trim((string)($s['kit_number'] ?? '')));
                        if ($k !== '' && in_array($k, $portalPausedKits, true)) {
                            $portalPausedSites[] = $s;
                        }
                    }
                    $portalAllSitesPaused = (count($portalPausedSites) === count($portalSites));
                }
            }
        }
    } catch (\Throwable $e) {
        // Silent — paused-state is best-effort; never break the portal
    }
}

// ════════════════════════════════════════════════════════════════════
// Fiber usage detection — v4.21.47 (set later, after $portalServiceType)
// ════════════════════════════════════════════════════════════════════
// Initialised here so they're always defined for every code path; the
// actual cache lookup happens further down once $portalServiceType is
// computed (see "Fiber usage cache lookup" block).
$portalFiberUsage = null;
$portalFiberUsageStale = false;
$portalFiberServiceIndex = []; // v4.21.78: keyed by service_id → {plan_name,ip,status,login,description}

// ── View dispatch ──
$view = $_GET['view'] ?? 'home';
// v4.15.3: added 's_hotspot' and 's_hotspot_pw' to the whitelist. Without
// these, unknown views silently fall through to 'home' BEFORE the dispatch
// chain in portal.php runs — which is why v4.15.0/.1/.2 navigated to the
// new views via DishNet.goInternal() but rendered the home tab instead.
// v4.18.1: added 's_hotspot_picker' for the home-dashboard hotspot tile.
// v4.21.55: added 'fiber_usage' to the whitelist. Without this, the link
// from the home fiber card silently fell through to 'home' (same trap as
// the v4.15 hotspot views — see comment above).
if (!in_array($view, ['home', 'invoices', 'invoice_detail', 'support', 'account', 'wifi_change', 'usage', 'fiber_usage', 'sites', 'site_detail', 'speed_test', 'wifi_site', 'service_status', 'debug_panel', 'devices', 's_hotspot', 's_hotspot_pw', 's_hotspot_picker'], true)) {
    $view = 'home';
}

// ── Load single invoice for detail view ──
$portalInvoiceDetail = null;
$portalInvoiceItems = [];
if ($view === 'invoice_detail' && !$portalAuthError) {
    $invId = (int)($_GET['inv_id'] ?? 0);
    if ($invId) {
        foreach ($store->load('ucrm_invoices_cache.json') ?? [] as $inv) {
            if ((int)($inv['id'] ?? 0) === $invId && (int)($inv['clientId'] ?? 0) === $portalCustomerId) {
                $total = (float)($inv['total'] ?? 0);
                $paid = (float)($inv['amountPaid'] ?? 0);
                $ucrmStatus = (int)($inv['status'] ?? 0);
                if ($ucrmStatus === 4 || $paid >= $total) $st = 'paid';
                elseif ($ucrmStatus === 6) $st = 'overdue';
                else {
                    $dd = $inv['dueDate'] ?? null;
                    $st = ($dd && strtotime($dd) < time()) ? 'overdue' : 'pending';
                }
                $portalInvoiceDetail = [
                    'id' => (int)$inv['id'],
                    'number' => $inv['number'] ?? ('INV-' . (int)$inv['id']),
                    'total' => $total,
                    'paid' => $paid,
                    'due' => max(0, $total - $paid),
                    'currency' => $inv['currencyCode'] ?? 'USD',
                    'status' => $st,
                    'created' => $inv['createdDate'] ?? null,
                    'due_date' => $inv['dueDate'] ?? null,
                    'subtotal' => (float)($inv['subtotal'] ?? 0),
                    'tax' => (float)($inv['totalTaxes'] ?? 0),
                ];
                foreach ($inv['items'] ?? [] as $it) {
                    $portalInvoiceItems[] = [
                        'label' => $it['label'] ?? 'Service',
                        'qty' => (float)($it['quantity'] ?? 1),
                        'price' => (float)($it['price'] ?? 0),
                        'total' => (float)($it['total'] ?? 0),
                    ];
                }
                break;
            }
        }
    }
}

// ── Derive display helpers ──
$portalCustomerName = '';
$portalFirstName = 'there';
if ($portalCustomer) {
    $portalCustomerName = trim($portalCustomer['name'] ?? 'Customer');
    $portalFirstName = explode(' ', $portalCustomerName)[0] ?: 'there';
}

// ════════════════════════════════════════════════════════════════════
// Service-type detection — v4.21.49 hybrid-aware
// ════════════════════════════════════════════════════════════════════
// Replaces the old single-value $portalServiceType inference (which picked
// the first matching keyword in the plan-name string and treated services
// as mutually exclusive) with structural detection that can identify
// customers having multiple service types simultaneously.
//
// Detection sources:
//   • Starlink:  customer has at least one KIT in $portalSites (already
//                filtered to this CRM client_id earlier in this file)
//   • Fiber:     customer has a row in dishnet-fiber-finance/data/
//                fiber_usage_cache.json with crm_customer_id matching
//   • LTE:       customer's plan name contains 'lte' (no separate cache
//                yet for LTE usage; fallback path)
//
// Sets:
//   $portalServiceType   (string)  — backwards-compat single value. Equals
//                                     the "primary" type: hybrid → uses
//                                     $portalSelectedSvc, fiber-only →
//                                     'fiber', etc. Existing code paths
//                                     that branch on this still work.
//   $portalServiceTypes  (array)   — every type the customer has, in
//                                     stable order, e.g. ['starlink','fiber']
//   $portalIsHybrid      (bool)    — count >= 2
//   $portalSelectedSvc   (string)  — which pill is currently active (only
//                                     meaningful when hybrid). Read from
//                                     cookie 'dn_svc_pref', default
//                                     'starlink'. Front-end JS writes the
//                                     cookie on pill tap and reloads.

$portalServiceTypes = [];
$portalIsHybrid     = false;
$portalSelectedSvc  = 'starlink';

// 1) Pre-load fiber cache here (before service-type inference) so we can
//    tell whether this customer is a fiber subscriber. Same data we used
//    to load only inside an `if ($portalServiceType === 'fiber')` guard;
//    now load unconditionally so hybrid detection works.
if (!$portalAuthError && !empty($portalCustomerId)) {
    try {
        $pluginsBase = dirname(dirname(dirname(__DIR__)));
        $usageCacheFile = $pluginsBase . '/dishnet-fiber-finance/data/fiber_usage_cache.json';
        if (is_file($usageCacheFile)) {
            $usageCache = portalJsonLoad($usageCacheFile);
            if (is_array($usageCache)) {
                foreach ($usageCache as $row) {
                    if (!is_array($row)) continue;
                    if ((int)($row['crm_customer_id'] ?? 0) === $portalCustomerId) {
                        $portalFiberUsage = $row;
                        $upd = strtotime((string)($row['updated_at'] ?? ''));
                        if ($upd > 0 && (time() - $upd) > 4 * 3600) {
                            $portalFiberUsageStale = true;
                        }
                        break;
                    }
                }
            }
        }
        // v4.21.78: also load fiber_services.json to get plan_name/IP per service_id
        $svcFile = $pluginsBase . '/dishnet-fiber-finance/data/fiber_services.json';
        if (is_file($svcFile)) {
            $fiberSvcs = portalJsonLoad($svcFile);
            if (is_array($fiberSvcs)) {
                foreach ($fiberSvcs as $fs) {
                    if (!is_array($fs)) continue;
                    $fsId = (string)($fs['service_id'] ?? $fs['splynx_id'] ?? '');
                    if ($fsId === '') continue;
                    $portalFiberServiceIndex[$fsId] = [
                        'plan_name'   => (string)($fs['plan_name']   ?? ''),
                        'login'       => (string)($fs['login']       ?? ''),
                        'ip_address'  => (string)($fs['ip_address']  ?? ''),
                        'description' => (string)($fs['description'] ?? ''),
                        'status'      => (string)($fs['splynx_status'] ?? $fs['status'] ?? ''),
                        'start_date'  => (string)($fs['start_date']  ?? ''),
                    ];
                }
            }
        }
    } catch (\Throwable $e) {
        // Silent — fiber usage is best-effort; never break the portal
    }
}

// 2) Build the service-types array
//    Starlink: customer has KITs in $portalSites
if (!empty($portalSites)) {
    $portalServiceTypes[] = 'starlink';
}
//    Fiber: customer has a row in fiber_usage_cache.json
if (!empty($portalFiberUsage)) {
    $portalServiceTypes[] = 'fiber';
}
//    LTE: fallback to plan-name detection (no usage cache yet)
if ($portalCustomer) {
    $pn = strtolower($portalPlanName . ' ' . ($portalCustomer['plans'] ?? ''));
    if (strpos($pn, 'lte') !== false && !in_array('lte', $portalServiceTypes, true)) {
        $portalServiceTypes[] = 'lte';
    }
}
//    If we found nothing structurally, fall back to plan-name like the
//    old code did (e.g. customers with NO KITs and NO fiber-cache row,
//    only a plan that says "Starlink Home"). This is the long-tail case
//    of customers whose data hasn't fully synced yet.
if (empty($portalServiceTypes) && $portalCustomer) {
    $pn = strtolower($portalPlanName . ' ' . ($portalCustomer['plans'] ?? ''));
    if (strpos($pn, 'fiber') !== false) {
        $portalServiceTypes[] = 'fiber';
    } elseif (strpos($pn, 'lte') !== false) {
        $portalServiceTypes[] = 'lte';
    } else {
        $portalServiceTypes[] = 'starlink';
    }
}

$portalIsHybrid = count($portalServiceTypes) >= 2;

// 3) Determine selected pill (hybrid only). Read cookie if present;
//    otherwise default to first type in the array (Starlink wins because
//    it's added first above — preserves existing behavior for hybrid
//    customers' first visit).
if ($portalIsHybrid) {
    $cookiePref = isset($_COOKIE['dn_svc_pref']) ? (string)$_COOKIE['dn_svc_pref'] : '';
    if (in_array($cookiePref, $portalServiceTypes, true)) {
        $portalSelectedSvc = $cookiePref;
    } else {
        $portalSelectedSvc = $portalServiceTypes[0]; // 'starlink' by ordering
    }
} else {
    $portalSelectedSvc = $portalServiceTypes[0] ?? 'starlink';
}

// 4) $portalServiceType — backwards-compat single value. For non-hybrid
//    customers it's just their one type. For hybrids it tracks the
//    currently-selected pill so existing if-branches still render the
//    "right" card without modification.
$portalServiceType = $portalSelectedSvc;

$portalLocation = 'Juba';
if ($portalFullClient) {
    $parts = array_filter([
        trim($portalFullClient['street1'] ?? ''),
        trim($portalFullClient['city'] ?? 'Juba'),
    ]);
    if ($parts) $portalLocation = implode(', ', $parts);
}

$portalPrice = $portalService ? (float)($portalService['price'] ?? 0) : 0;
$portalCurrency = $portalService['currencyCode'] ?? 'USD';
$portalNextBill = $portalService['nextInvoicingDayAdjustment']
                ?? $portalService['activeTo'] ?? null;
$portalDaysLeft = null;
if ($portalNextBill) {
    $t = strtotime($portalNextBill);
    if ($t !== false) $portalDaysLeft = max(0, (int)floor(($t - time()) / 86400));
}

// HTML escape helper
function pe($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
// Money fmt
function pm($v, $cur = 'USD') { return '$' . number_format((float)$v, 0); }
