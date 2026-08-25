#!/usr/bin/env php
<?php
/**
 * BlueCard → Hybrid LTE Data Importer
 * ═══════════════════════════════════════════════════════════════════════
 * 
 * Imports data from BlueCard MySQL into Hybrid SQLite.
 * 
 * USAGE:
 *   php scripts/import_bluecard.php --sims=sims.json
 *   php scripts/import_bluecard.php --customers=customers.json
 *   php scripts/import_bluecard.php --subscriptions=services.json
 *   php scripts/import_bluecard.php --all=/path/to/export/
 * 
 * INPUT FORMATS:
 *   JSON arrays exported from BlueCard MySQL
 *   CSV files with headers
 * 
 * @version 1.0
 * @date March 2026
 */

date_default_timezone_set('Africa/Juba');
chdir(__DIR__ . '/..');

require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/bootstrap_data.php';

// ═══════════════════════════════════════════════════════════════════════
// CONFIGURATION
// ═══════════════════════════════════════════════════════════════════════

$dataDir = getDataDir(__DIR__ . '/..');
$store   = SqliteStore::create($dataDir);
$db      = $store->getPdo();

$dryRun  = in_array('--dry-run', $argv);
$verbose = in_array('-v', $argv) || in_array('--verbose', $argv);

// ═══════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════

function clog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function loadJsonOrCsv(string $path): array {
    if (!file_exists($path)) {
        throw new Exception("File not found: {$path}");
    }
    
    $content = file_get_contents($path);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    
    if ($ext === 'json') {
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON: " . json_last_error_msg());
        }
        return $data;
    }
    
    if ($ext === 'csv') {
        $lines = array_filter(explode("\n", $content));
        if (empty($lines)) return [];
        
        $headers = str_getcsv(array_shift($lines));
        $data = [];
        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (count($row) === count($headers)) {
                $data[] = array_combine($headers, $row);
            }
        }
        return $data;
    }
    
    throw new Exception("Unsupported file format: {$ext}");
}

function getArg(string $name): ?string {
    global $argv;
    foreach ($argv as $arg) {
        if (strpos($arg, "--{$name}=") === 0) {
            return substr($arg, strlen("--{$name}="));
        }
    }
    return null;
}

// ═══════════════════════════════════════════════════════════════════════
// IMPORT: SIM CARDS
// ═══════════════════════════════════════════════════════════════════════

function importSims(PDO $db, array $sims, bool $dryRun): array {
    clog("Importing " . count($sims) . " SIM cards...");
    
    $imported = 0;
    $skipped  = 0;
    $errors   = [];
    $now      = date('Y-m-d H:i:s');
    
    $checkStmt = $db->prepare("SELECT id FROM lte_sims WHERE imsi = :imsi");
    $insertStmt = $db->prepare(
        "INSERT INTO lte_sims 
         (imsi, msisdn, iccid, auth_key, auth_opc, algo, status, batch, vendor, bluecard_id, magma_provisioned, created_at, updated_at)
         VALUES 
         (:imsi, :msisdn, :iccid, :key, :opc, :algo, :status, :batch, :vendor, :bcid, :prov, :now, :now)"
    );
    
    if (!$dryRun) {
        $db->beginTransaction();
    }
    
    try {
        foreach ($sims as $sim) {
            $imsi = trim($sim['imsi'] ?? $sim['IMSI'] ?? '');
            if (empty($imsi)) {
                $errors[] = "Missing IMSI";
                continue;
            }
            
            // Check for existing
            $checkStmt->execute([':imsi' => $imsi]);
            if ($checkStmt->fetch()) {
                $skipped++;
                continue;
            }
            
            if (!$dryRun) {
                $insertStmt->execute([
                    ':imsi'   => $imsi,
                    ':msisdn' => $sim['msisdn'] ?? $sim['MSISDN'] ?? $imsi,
                    ':iccid'  => $sim['iccid'] ?? $sim['ICCID'] ?? null,
                    ':key'    => $sim['auth_key'] ?? $sim['ki'] ?? $sim['K'] ?? '',
                    ':opc'    => $sim['auth_opc'] ?? $sim['opc'] ?? $sim['OPc'] ?? '',
                    ':algo'   => $sim['algo'] ?? 'Milenage',
                    ':status' => $sim['status'] ?? 'in_stock',
                    ':batch'  => $sim['batch'] ?? 'bluecard_import',
                    ':vendor' => $sim['vendor'] ?? 'DishNet',
                    ':bcid'   => $sim['id'] ?? $sim['bluecard_id'] ?? null,
                    ':prov'   => isset($sim['magma_provisioned']) ? (int)$sim['magma_provisioned'] : 1,
                    ':now'    => $now,
                ]);
            }
            $imported++;
        }
        
        if (!$dryRun) {
            $db->commit();
        }
    } catch (Exception $e) {
        if (!$dryRun) {
            $db->rollBack();
        }
        throw $e;
    }
    
    clog("  → Imported: {$imported}, Skipped: {$skipped}, Errors: " . count($errors));
    return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

// ═══════════════════════════════════════════════════════════════════════
// IMPORT: CUSTOMERS (users type=3)
// ═══════════════════════════════════════════════════════════════════════

function importCustomers(PDO $db, array $customers, bool $dryRun): array {
    clog("Importing " . count($customers) . " customers...");
    
    $imported = 0;
    $skipped  = 0;
    $errors   = [];
    $now      = date('Y-m-d H:i:s');
    
    $checkImsi = $db->prepare("SELECT id FROM lte_subscribers WHERE imsi = :imsi");
    $checkPhone = $db->prepare("SELECT id FROM lte_subscribers WHERE phone = :phone AND phone IS NOT NULL AND phone != ''");
    
    $insertStmt = $db->prepare(
        "INSERT INTO lte_subscribers 
         (name, phone, email, address, area, imsi, msisdn, auth_key, auth_opc, 
          status, magma_state, agent_id, agent_name, registered_by, bluecard_id, created_at, updated_at)
         VALUES 
         (:name, :phone, :email, :address, :area, :imsi, :msisdn, :key, :opc,
          :status, :magma, :agent_id, :agent, :reg_by, :bcid, :created, :now)"
    );
    
    // Also update SIM status when assigned
    $updateSim = $db->prepare(
        "UPDATE lte_sims SET status = 'assigned', subscriber_id = :sub_id, updated_at = :now WHERE imsi = :imsi"
    );
    
    if (!$dryRun) {
        $db->beginTransaction();
    }
    
    try {
        foreach ($customers as $cust) {
            $imsi = trim($cust['imsi'] ?? $cust['IMSI'] ?? $cust['username'] ?? '');
            $phone = trim($cust['phone'] ?? $cust['mobile'] ?? $cust['whatsapp'] ?? '');
            $name = trim($cust['name'] ?? $cust['full_name'] ?? $cust['fullname'] ?? 'Unknown');
            
            if (empty($imsi) && empty($phone)) {
                $errors[] = "Customer missing both IMSI and phone: {$name}";
                continue;
            }
            
            // Check for existing by IMSI
            if (!empty($imsi)) {
                $checkImsi->execute([':imsi' => $imsi]);
                if ($checkImsi->fetch()) {
                    $skipped++;
                    continue;
                }
            }
            
            // Check for existing by phone (if no IMSI match)
            if (!empty($phone)) {
                $checkPhone->execute([':phone' => $phone]);
                if ($checkPhone->fetch()) {
                    $skipped++;
                    continue;
                }
            }
            
            // Get auth keys from SIM table if not in customer record
            $authKey = $cust['auth_key'] ?? $cust['ki'] ?? '';
            $authOpc = $cust['auth_opc'] ?? $cust['opc'] ?? '';
            
            if (empty($authKey) && !empty($imsi)) {
                $simStmt = $db->prepare("SELECT auth_key, auth_opc FROM lte_sims WHERE imsi = :imsi");
                $simStmt->execute([':imsi' => $imsi]);
                $simData = $simStmt->fetch(PDO::FETCH_ASSOC);
                if ($simData) {
                    $authKey = $simData['auth_key'];
                    $authOpc = $simData['auth_opc'];
                }
            }
            
            // Determine status
            $status = 'active';
            if (isset($cust['status'])) {
                $s = strtolower($cust['status']);
                if (in_array($s, ['inactive', 'suspended', 'disabled', '0'])) {
                    $status = 'suspended';
                } elseif ($s === 'terminated' || $s === 'deleted') {
                    $status = 'terminated';
                }
            }
            
            $magmaState = $status === 'active' ? 'ACTIVE' : 'INACTIVE';
            
            // Parse created_at
            $createdAt = $cust['created_at'] ?? $cust['created'] ?? $cust['registration_date'] ?? $now;
            if (is_numeric($createdAt)) {
                $createdAt = date('Y-m-d H:i:s', $createdAt);
            }
            
            if (!$dryRun) {
                $insertStmt->execute([
                    ':name'     => $name,
                    ':phone'    => $phone ?: null,
                    ':email'    => $cust['email'] ?? null,
                    ':address'  => $cust['address'] ?? $cust['location'] ?? null,
                    ':area'     => $cust['area'] ?? $cust['zone'] ?? null,
                    ':imsi'     => $imsi ?: null,
                    ':msisdn'   => $cust['msisdn'] ?? $imsi ?: null,
                    ':key'      => $authKey,
                    ':opc'      => $authOpc,
                    ':status'   => $status,
                    ':magma'    => $magmaState,
                    ':agent_id' => $cust['agent_id'] ?? $cust['created_by'] ?? null,
                    ':agent'    => $cust['agent_name'] ?? $cust['agent'] ?? null,
                    ':reg_by'   => 'bluecard_import',
                    ':bcid'     => $cust['id'] ?? $cust['bluecard_id'] ?? null,
                    ':created'  => $createdAt,
                    ':now'      => $now,
                ]);
                
                $subId = (int)$db->lastInsertId();
                
                // Update SIM assignment
                if (!empty($imsi)) {
                    $updateSim->execute([':sub_id' => $subId, ':imsi' => $imsi, ':now' => $now]);
                }
            }
            $imported++;
        }
        
        if (!$dryRun) {
            $db->commit();
        }
    } catch (Exception $e) {
        if (!$dryRun) {
            $db->rollBack();
        }
        throw $e;
    }
    
    clog("  → Imported: {$imported}, Skipped: {$skipped}, Errors: " . count($errors));
    return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

// ═══════════════════════════════════════════════════════════════════════
// IMPORT: SUBSCRIPTIONS (services + data_mgmt)
// ═══════════════════════════════════════════════════════════════════════

function importSubscriptions(PDO $db, array $services, bool $dryRun): array {
    clog("Importing " . count($services) . " subscriptions...");
    
    $imported = 0;
    $skipped  = 0;
    $errors   = [];
    $now      = date('Y-m-d H:i:s');
    
    // Lookup subscriber by IMSI or bluecard_id
    $findSub = $db->prepare(
        "SELECT id, imsi FROM lte_subscribers WHERE imsi = :imsi OR bluecard_id = :bcid LIMIT 1"
    );
    
    // Lookup package by name or bluecard_id
    $findPkg = $db->prepare(
        "SELECT id, name, price, type, bytes_allowed, days, magma_profile 
         FROM lte_packages WHERE name = :name OR bluecard_id = :bcid LIMIT 1"
    );
    
    // Check existing subscription
    $checkSub = $db->prepare(
        "SELECT id FROM lte_subscriptions WHERE subscriber_id = :sub_id AND status = 'active'"
    );
    
    $insertStmt = $db->prepare(
        "INSERT INTO lte_subscriptions 
         (subscriber_id, package_id, package_name, package_price, package_type,
          bytes_allowed, bytes_used, bytes_remaining, bytes_baseline, usage_percent,
          started_at, expires_at, magma_profile, status, amount_paid, payment_method,
          agent_id, agent_name, bluecard_id, created_at, updated_at)
         VALUES 
         (:sub_id, :pkg_id, :pkg_name, :price, :type,
          :bytes_allowed, :bytes_used, :bytes_remaining, :baseline, :percent,
          :started, :expires, :profile, :status, :paid, :method,
          :agent_id, :agent, :bcid, :now, :now)"
    );
    
    if (!$dryRun) {
        $db->beginTransaction();
    }
    
    try {
        foreach ($services as $svc) {
            // Find subscriber
            $imsi = $svc['imsi'] ?? $svc['user_imsi'] ?? $svc['username'] ?? '';
            $userBcId = $svc['user_id'] ?? $svc['customer_id'] ?? null;
            
            $findSub->execute([':imsi' => $imsi, ':bcid' => $userBcId]);
            $subscriber = $findSub->fetch(PDO::FETCH_ASSOC);
            
            if (!$subscriber) {
                $errors[] = "Subscriber not found for IMSI={$imsi}, user_id={$userBcId}";
                continue;
            }
            
            $subscriberId = (int)$subscriber['id'];
            
            // Check if already has active subscription
            $checkSub->execute([':sub_id' => $subscriberId]);
            if ($checkSub->fetch()) {
                $skipped++;
                continue;
            }
            
            // Find package
            $pkgName = $svc['package_name'] ?? $svc['plan_name'] ?? $svc['offering_name'] ?? '';
            $pkgBcId = $svc['package_id'] ?? $svc['offering_id'] ?? null;
            
            $findPkg->execute([':name' => $pkgName, ':bcid' => $pkgBcId]);
            $package = $findPkg->fetch(PDO::FETCH_ASSOC);
            
            if (!$package) {
                // Use defaults
                $package = [
                    'id' => null,
                    'name' => $pkgName ?: 'Unknown',
                    'price' => $svc['price'] ?? $svc['amount'] ?? 0,
                    'type' => $svc['type'] ?? 2,
                    'bytes_allowed' => $svc['bytes_allowed'] ?? $svc['data_limit'] ?? null,
                    'days' => $svc['days'] ?? $svc['duration'] ?? 30,
                    'magma_profile' => $svc['profile'] ?? 'default',
                ];
            }
            
            // Calculate usage
            $bytesAllowed = $package['bytes_allowed'];
            $bytesUsed = (int)($svc['bytes_used'] ?? $svc['data_used'] ?? $svc['usage'] ?? 0);
            $bytesBaseline = (int)($svc['bytes_baseline'] ?? 0);
            $bytesRemaining = $bytesAllowed ? max(0, $bytesAllowed - $bytesUsed) : null;
            $usagePercent = ($bytesAllowed && $bytesAllowed > 0) ? round(($bytesUsed / $bytesAllowed) * 100, 2) : 0;
            
            // Parse dates
            $startedAt = $svc['started_at'] ?? $svc['start_date'] ?? $svc['activated_at'] ?? $svc['created_at'] ?? $now;
            $expiresAt = $svc['expires_at'] ?? $svc['expiry_date'] ?? $svc['end_date'] ?? null;
            
            if (is_numeric($startedAt)) $startedAt = date('Y-m-d H:i:s', $startedAt);
            if (is_numeric($expiresAt)) $expiresAt = date('Y-m-d H:i:s', $expiresAt);
            
            // If no expiry, calculate from start + days
            if (empty($expiresAt) && !empty($startedAt)) {
                $days = (int)($package['days'] ?? 30);
                $expiresAt = date('Y-m-d H:i:s', strtotime($startedAt) + ($days * 86400));
            }
            
            // Determine status
            $status = 'active';
            if (isset($svc['status'])) {
                $s = strtolower($svc['status']);
                if (in_array($s, ['expired', 'exhausted', 'inactive', 'cancelled'])) {
                    $status = $s;
                }
            }
            // Auto-expire if past expiry date
            if (!empty($expiresAt) && strtotime($expiresAt) < time()) {
                $status = 'expired';
            }
            
            if (!$dryRun) {
                $insertStmt->execute([
                    ':sub_id'         => $subscriberId,
                    ':pkg_id'         => $package['id'],
                    ':pkg_name'       => $package['name'],
                    ':price'          => $package['price'],
                    ':type'           => $package['type'],
                    ':bytes_allowed'  => $bytesAllowed,
                    ':bytes_used'     => $bytesUsed,
                    ':bytes_remaining'=> $bytesRemaining,
                    ':baseline'       => $bytesBaseline,
                    ':percent'        => $usagePercent,
                    ':started'        => $startedAt,
                    ':expires'        => $expiresAt,
                    ':profile'        => $package['magma_profile'],
                    ':status'         => $status,
                    ':paid'           => $svc['amount_paid'] ?? $package['price'],
                    ':method'         => $svc['payment_method'] ?? 'cash',
                    ':agent_id'       => $svc['agent_id'] ?? null,
                    ':agent'          => $svc['agent_name'] ?? null,
                    ':bcid'           => $svc['id'] ?? $svc['bluecard_id'] ?? null,
                    ':now'            => $now,
                ]);
            }
            $imported++;
        }
        
        if (!$dryRun) {
            $db->commit();
        }
    } catch (Exception $e) {
        if (!$dryRun) {
            $db->rollBack();
        }
        throw $e;
    }
    
    clog("  → Imported: {$imported}, Skipped: {$skipped}, Errors: " . count($errors));
    return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

// ═══════════════════════════════════════════════════════════════════════
// MAIN
// ═══════════════════════════════════════════════════════════════════════

clog("════════════════════════════════════════════════════════════════");
clog("BlueCard → Hybrid LTE Data Importer");
clog("════════════════════════════════════════════════════════════════");

if ($dryRun) {
    clog("*** DRY RUN MODE — No changes will be made ***");
}

$results = [];

// Import SIMs
$simsFile = getArg('sims');
if ($simsFile) {
    clog("");
    $sims = loadJsonOrCsv($simsFile);
    $results['sims'] = importSims($db, $sims, $dryRun);
}

// Import Customers
$customersFile = getArg('customers');
if ($customersFile) {
    clog("");
    $customers = loadJsonOrCsv($customersFile);
    $results['customers'] = importCustomers($db, $customers, $dryRun);
}

// Import Subscriptions
$subscriptionsFile = getArg('subscriptions');
if ($subscriptionsFile) {
    clog("");
    $subscriptions = loadJsonOrCsv($subscriptionsFile);
    $results['subscriptions'] = importSubscriptions($db, $subscriptions, $dryRun);
}

// Import all from directory
$allDir = getArg('all');
if ($allDir) {
    clog("");
    clog("Importing all from directory: {$allDir}");
    
    // Look for standard filenames
    $simFiles = ['sims.json', 'sim_cards.json', 'sims.csv'];
    $custFiles = ['customers.json', 'users.json', 'subscribers.json', 'customers.csv'];
    $svcFiles = ['subscriptions.json', 'services.json', 'subscriptions.csv'];
    
    foreach ($simFiles as $f) {
        $path = rtrim($allDir, '/') . '/' . $f;
        if (file_exists($path)) {
            clog("");
            $sims = loadJsonOrCsv($path);
            $results['sims'] = importSims($db, $sims, $dryRun);
            break;
        }
    }
    
    foreach ($custFiles as $f) {
        $path = rtrim($allDir, '/') . '/' . $f;
        if (file_exists($path)) {
            clog("");
            $customers = loadJsonOrCsv($path);
            $results['customers'] = importCustomers($db, $customers, $dryRun);
            break;
        }
    }
    
    foreach ($svcFiles as $f) {
        $path = rtrim($allDir, '/') . '/' . $f;
        if (file_exists($path)) {
            clog("");
            $subscriptions = loadJsonOrCsv($path);
            $results['subscriptions'] = importSubscriptions($db, $subscriptions, $dryRun);
            break;
        }
    }
}

// Show help if no arguments
if (empty($results)) {
    clog("");
    clog("USAGE:");
    clog("  php scripts/import_bluecard.php --sims=path/to/sims.json");
    clog("  php scripts/import_bluecard.php --customers=path/to/customers.json");
    clog("  php scripts/import_bluecard.php --subscriptions=path/to/services.json");
    clog("  php scripts/import_bluecard.php --all=/path/to/export/folder/");
    clog("");
    clog("OPTIONS:");
    clog("  --dry-run    Preview changes without writing to database");
    clog("  -v           Verbose output");
    clog("");
    clog("EXPECTED FILE FORMATS:");
    clog("  sims.json:         [{imsi, auth_key, auth_opc, status, ...}]");
    clog("  customers.json:    [{name, phone, imsi, email, address, ...}]");
    clog("  subscriptions.json:[{imsi, package_name, expires_at, bytes_used, ...}]");
    exit(1);
}

// Summary
clog("");
clog("════════════════════════════════════════════════════════════════");
clog("IMPORT COMPLETE");
clog("════════════════════════════════════════════════════════════════");

$totalImported = 0;
$totalSkipped = 0;
$totalErrors = 0;

foreach ($results as $type => $r) {
    $totalImported += $r['imported'];
    $totalSkipped += $r['skipped'];
    $totalErrors += count($r['errors']);
    clog(sprintf("  %-15s: %d imported, %d skipped, %d errors", ucfirst($type), $r['imported'], $r['skipped'], count($r['errors'])));
}

clog("────────────────────────────────────────────────────────────────");
clog(sprintf("  TOTAL:          %d imported, %d skipped, %d errors", $totalImported, $totalSkipped, $totalErrors));

if ($dryRun) {
    clog("");
    clog("*** This was a DRY RUN — run without --dry-run to apply changes ***");
}

clog("");
