<?php
declare(strict_types=1);

// EARLY DEBUG - log that we reached the file
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Centralized error handler (catches uncaught exceptions + fatal fallback)
require_once __DIR__ . '/lib/error_handler.php';
require_once __DIR__ . '/lib/FcmPush.php';
$GLOBALS['_DISHNET_ERROR_FORMAT'] = 'json';

// Catch fatal errors early (keeps existing specific handler)
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'PHP Fatal: ' . $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
    }
});

// PHP 7.4 polyfills
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

chdir(__DIR__);

/**
 * DishNet Hybrid Telecom — webhook.php
 *
 * Receives UCRM webhook events and routes them to NotificationService.
 *
 * How to wire it up in UCRM:
 *   System → Webhooks → Add Webhook
 *   URL : https://crm.dishnetafrica.com/crm/_plugins/dishnet-hybrid-telecom/public.php?page=webhook
 *   Secret: (paste the webhook_secret from plugin Settings)
 *   Events: client.add, invoice.add, payment.add, service.suspend, service.end, service.activate
 *
 * NOTE: UCRM only exposes public.php directly. This file is included via public.php?page=webhook
 *
 * UCRM sends:
 *   Header  X-Crm-Key: <secret>
 *   Header  X-Crm-Crm: <crm name>
 *   Body    JSON: { "uuid":"...", "changeType":"...", "entity":{...}, "entityId":N }
 *
 * Supported events (changeType):
 *   client.add             → log, trigger welcome WhatsApp
 *   invoice.add            → notify customer of new invoice
 *   payment.add            → thank customer for payment
 *   service.suspend        → notify customer service suspended
 *   service.unsuspend / service.activate → notify service restored
 *   service.end            → churn recovery message
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────
// Check if we're being included from public.php (variables already defined)
if (!isset($dataDir)) {
    require_once __DIR__ . '/lib/bootstrap_data.php';
    $dataDir = getDataDir(__DIR__);
    if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
}

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/CrmApiClient.php';
require_once __DIR__ . '/lib/NotificationService.php';
require_once __DIR__ . '/lib/StarlinkBlockService.php';
require_once __DIR__ . '/lib/EventBus.php';

if (!isset($store)) {
    $store  = SqliteStore::create($dataDir);
}
if (!isset($config)) {
    $config = $store->load('kyc_config.json') ?? [];
}

// ── Response helpers ───────────────────────────────────────────────────────
function whResp(int $code, string $msg, array $data = []): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['status' => $code < 300 ? 'ok' : 'error', 'message' => $msg] + $data);
    exit;
}

function whLog(string $event, string $msg, array $data = []): void {
    global $dataDir;
    $logFile = $dataDir . '/webhook_log.json';
    $log = [];
    if (file_exists($logFile)) {
        $raw = json_decode(file_get_contents($logFile), true);
        $log = is_array($raw) ? $raw : [];
    }
    $maxId = empty($log) ? 0 : max(array_map(fn($r) => (int)($r['id'] ?? 0), $log));
    array_unshift($log, [
        'id'         => $maxId + 1,
        'event'      => $event,
        'message'    => $msg,
        'data'       => $data,
        'received_at'=> date('Y-m-d H:i:s'),
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    $log = array_slice($log, 0, 300);
    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Build invoice credit scenario data from invoice + client API responses.
 * Returns array: ['scenario'=>'auto_paid'|'partial'|'normal', 'amount_paid'=>float,
 *                 'remaining'=>float, 'credit_remaining'=>float]
 */
function whInvoiceCreditScenario(array $invoice, array $client): array
{
    $total      = (float)($invoice['total'] ?? $invoice['amount'] ?? 0);
    $amountPaid = (float)($invoice['amountPaid'] ?? 0);
    $acctBal    = (float)($client['accountBalance'] ?? 0); // positive = credit
    $remaining  = round($total - $amountPaid, 2);

    if ($amountPaid >= $total && $total > 0) {
        // Fully covered — calculate leftover credit in account
        $creditRemaining = max(0, round($acctBal, 2));
        return ['scenario' => 'auto_paid', 'amount_paid' => $amountPaid,
                'remaining' => 0, 'credit_remaining' => $creditRemaining, 'total' => $total];
    }
    if ($amountPaid > 0 && $remaining > 0) {
        return ['scenario' => 'partial', 'amount_paid' => $amountPaid,
                'remaining' => $remaining, 'credit_remaining' => 0, 'total' => $total];
    }
    return ['scenario' => 'normal', 'amount_paid' => 0,
            'remaining' => $total, 'credit_remaining' => 0, 'total' => $total];
}

/**
 * Send invoice PDF via WhatsML. Returns true on success.
 */
function whSendInvoicePdf(object $crm, object $notify, string $phone, int $invoiceId, string $invoNum, float $amount, string $dueDate, array $config, string $dataDir): bool
{
    try {
        $pdfRaw = $crm->getRawContent("invoices/{$invoiceId}/pdf");
        if (!$pdfRaw) return false;

        $tempDir = $dataDir . '/temp_pdf';
        if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
        foreach (glob($tempDir . '/*.pdf') as $_tf) {
            if (time() - filemtime($_tf) > 600) { @unlink($_tf); @unlink($_tf . '.meta'); }
        }

        $pdfFile  = "inv_{$invoiceId}_" . substr(md5(uniqid()), 0, 8) . '.pdf';
        $pdfPath  = $tempDir . '/' . $pdfFile;
        $pdfToken = hash_hmac('sha256', $pdfFile, ($config['webhook_secret'] ?? 'dishnet') . date('Ymd'));

        file_put_contents($pdfPath, base64_decode($pdfRaw));
        file_put_contents($pdfPath . '.meta', json_encode([
            'token' => $pdfToken, 'created' => time(), 'invoice' => $invoNum,
        ]));

        $siteUrl = rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/');
        $siteUrl = preg_replace('#/api/v[0-9.]+$#', '', $siteUrl);
        $siteUrl = preg_replace('#/crm$#', '', $siteUrl);
        $pdfUrl  = $siteUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php'
                 . '?page=api&action=serve_temp_pdf'
                 . '&file=' . urlencode($pdfFile)
                 . '&token=' . urlencode($pdfToken);

        $notify->sendDocument('accounts', $phone, $pdfUrl, "{$invoNum}.pdf",
            "Invoice #{$invoNum} — \${$amount} — Due: {$dueDate}\n— DishNet Africa",
            'ops_invoice_pdf');
        return true;
    } catch (\Throwable $e) {
        whLog('pdf_error', "Invoice PDF send failed: " . $e->getMessage(), ['invoice' => $invoNum]);
        return false;
    }
}

/**
 * Send credit-aware invoice notification + PDF.
 * Handles all 4 scenarios: auto_paid, partial, normal, overpaid.
 */
function whSendInvoiceNotification(object $notify, object $crm, string $phone, string $name,
    int $invoiceId, string $invoNum, float $amount, string $dueDate,
    array $creditData, array $config, string $dataDir, string $serviceName = ''): void
{
    $scenario = $creditData['scenario'];
    $sendPdf  = true; // always send PDF so customer has the record

    if ($scenario === 'auto_paid') {
        $notify->invoiceAutoPaid($phone, $name, $invoNum, $amount, $creditData['credit_remaining'], $serviceName);
        whLog('invoice_notify', "Auto-paid by credit: #{$invoNum} → {$name}", [
            'amount' => $amount, 'credit_remaining' => $creditData['credit_remaining']
        ]);
    } elseif ($scenario === 'partial') {
        $notify->invoicePartialCredit($phone, $name, $invoNum, $amount,
            $creditData['amount_paid'], $creditData['remaining'], $dueDate, $serviceName);
        whLog('invoice_notify', "Partial credit: #{$invoNum} → {$name}", [
            'amount' => $amount, 'credit_applied' => $creditData['amount_paid'],
            'remaining' => $creditData['remaining']
        ]);
    } else {
        // Normal — no advance payment
        $notify->invoiceCreated($phone, $name, $invoNum, $amount, $dueDate ?: 'See invoice', $serviceName);
        whLog('invoice_notify', "Normal invoice: #{$invoNum} → {$name}", ['amount' => $amount]);
    }

    // Always send PDF so customer has the invoice document
    $pdfOk = whSendInvoicePdf($crm, $notify, $phone, $invoiceId, $invoNum, $amount, $dueDate, $config, $dataDir);
    whLog('invoice_notify', "PDF send: " . ($pdfOk ? 'OK' : 'FAILED'), ['invoice' => $invoNum]);
}

// ── Only accept POST ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') whResp(405, 'POST required.');

// ══════════════════════════════════════════════════════════════════════════════
// SPLYNX WEBHOOK HANDLER (Phase 2 v3.8)
// URL: .../webhook.php?source=splynx
// Splynx → HMAC-SHA256 signed → queue to events table → event_processor handles
// ══════════════════════════════════════════════════════════════════════════════
$source = $_GET['source'] ?? '';

if ($source === 'splynx') {
    $rawBody = file_get_contents('php://input');
    if (empty($rawBody)) whResp(400, 'Empty body.');

    // HMAC-SHA256 signature validation
    $splynxSecret = trim($config['splynx_webhook_secret'] ?? '');
    if ($splynxSecret !== '') {
        $receivedSig = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
                    ?? $_SERVER['HTTP_X_SPLYNX_SIGNATURE']
                    ?? '';
        $expectedSig = hash_hmac('sha256', $rawBody, $splynxSecret);
        if (!hash_equals($expectedSig, trim($receivedSig))) {
            whLog('splynx_auth_failed', 'Invalid Splynx HMAC signature');
            whResp(401, 'Invalid signature.');
        }
    }

    $body = json_decode($rawBody, true);
    if (!is_array($body)) whResp(400, 'Invalid JSON.');

    // Extract Splynx event fields
    $eventType  = $body['event'] ?? $body['changeType'] ?? $body['type'] ?? 'unknown';
    $entityType = $body['entity_type'] ?? $body['model'] ?? 'ticket';
    $entityId   = (int)($body['entity_id'] ?? $body['id'] ?? $body['entityId'] ?? 0);

    // Map Splynx event names to our internal types
    $typeMap = [
        'ticket.updated'     => 'splynx.ticket.updated',
        'ticket.created'     => 'splynx.ticket.created',
        'ticket.closed'      => 'splynx.ticket.updated',
        'service.activated'  => 'splynx.service.activated',
        'service.suspended'  => 'splynx.service.suspended',
    ];
    $internalType = $typeMap[$eventType] ?? 'splynx.' . $eventType;

    // Queue the event for async processing
    $bus = new EventBus($store->getPdo());
    $eventId = $bus->emit(
        $internalType,
        $entityType,
        $entityId,
        $body,     // full Splynx payload preserved
        2,         // priority 2 = high (external system events)
        'splynx_webhook'
    );

    whLog('splynx_' . $eventType, "Splynx webhook queued as event #{$eventId}", [
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'event_type'  => $internalType,
    ]);

    whResp(200, 'Queued.', ['event_id' => $eventId]);
}

// ══════════════════════════════════════════════════════════════════════════════
// UCRM WEBHOOK HANDLER (existing — unchanged below)
// ══════════════════════════════════════════════════════════════════════════════

// ── HMAC signature verification ───────────────────────────────────────────
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) whResp(400, 'Empty body.');

$webhookSecret = trim($config['webhook_secret'] ?? '');
if ($webhookSecret !== '') {
    // UCRM sends the secret in X-Crm-Key header (plaintext match, not HMAC)
    $receivedKey = $_SERVER['HTTP_X_CRM_KEY']
                ?? $_SERVER['HTTP_X_UCRM_KEY']
                ?? getallheaders()['X-Crm-Key']
                ?? getallheaders()['X-Ucrm-Key']
                ?? '';
    // If UCRM/UISP sends NO key at all (empty), allow through — many UCRM versions
    // don't support webhook secrets. Only reject if a non-empty key was sent that doesn't match.
    if ($receivedKey !== '' && !hash_equals($webhookSecret, trim($receivedKey))) {
        whLog('auth_failed', 'Invalid webhook secret', ['received_key_prefix' => substr($receivedKey, 0, 8)]);
        whResp(401, 'Unauthorized.');
    }
}

// ── Parse payload ─────────────────────────────────────────────────────────
$payload = json_decode($rawBody, true);
if (!is_array($payload)) whResp(400, 'Invalid JSON.');

$changeType = $payload['changeType'] ?? $payload['eventType'] ?? '';
$entityType = $payload['entity'] ?? '';  // For UCRM format: entity is a string like "payment", "client"
$entityData = $payload['extraData']['entity'] ?? [];  // The actual entity data
$entityId   = (int)($payload['entityId'] ?? 0);
$uuid       = $payload['uuid'] ?? '';

// Handle UCRM's older format where entity is a string and data is in extraData
// UCRM sends: {"changeType":"insert", "entity":"payment", "entityId":8301, "extraData":{"entity":{...}}}
// But for test events: {"changeType":"test", "entity":"webhook"} - don't normalize these!

// Skip normalization for already-complete event types
$skipNormalize = [
    'test',
    'client.add', 'client.edit', 'client.delete', 'client.invite', 'client.message',
    'invoice.add', 'invoice.edit', 'invoice.delete', 'invoice.add_draft', 'invoice.draft_approved', 'invoice.near_due', 'invoice.overdue',
    'payment.add', 'payment.edit', 'payment.delete',
    'service.add', 'service.edit', 'service.suspend', 'service.suspend_cancel', 'service.end', 'service.activate', 'service.outage', 'service.postpone',
    'quote.add', 'quote.approve',
    'credit_note.add', 'credit_card.add',
    'ticket.add',
    // UCRM also sends these without dot notation
    'near_due', 'overdue', 'draft_approved', 'suspend', 'unsuspend', 'postpone',
];

if (in_array($changeType, $skipNormalize, true)) {
    // Already a valid event type - use as-is
    $entity = $entityData ?: [];
    whLog('passthrough', "Event type '{$changeType}' used as-is (no normalization)");
} elseif (is_string($entityType) && !empty($entityType) && in_array($changeType, ['insert', 'edit', 'delete', 'end'], true)) {
    // This is UCRM format - entity is a string type name
    // Normalize to our expected format: "payment" + "insert" → "payment.add"
    $typeMap = [
        'insert' => 'add',
        'edit'   => 'edit',
        'delete' => 'delete',
        'end'    => 'end',
    ];
    $action = $typeMap[$changeType] ?? $changeType;
    $normalizedType = strtolower($entityType) . '.' . $action;
    
    whLog('normalize', "Normalized changeType: {$changeType}/{$entityType} → {$normalizedType}", [
        'original_changeType' => $changeType,
        'entity_type' => $entityType,
        'normalized' => $normalizedType,
    ]);
    
    $changeType = $normalizedType;
    $entity = $entityData;  // Use the actual entity data from extraData
} else {
    // Standard format or unknown - entity might be the data object
    $entity = is_array($entityType) ? $entityType : ($entityData ?: []);
}

if (empty($changeType)) whResp(400, 'Missing changeType.');

// ── Init services ─────────────────────────────────────────────────────────
// Use fromUcrm factory to properly read API key from ucrm.json
$crm    = CrmApiClient::fromUcrm(__DIR__, $config);
$notify = new NotificationService($store, $config);

// Log service status for debugging
whLog('_debug', 'Services initialized', [
    'crm_configured' => $crm->getBaseUrl() ? 'YES' : 'NO',
    'crm_base_url' => $crm->getBaseUrl() ? substr($crm->getBaseUrl(), 0, 50) : '(empty)',
    'notify_enabled' => $notify->isDryRunMode() ? 'DRY RUN MODE' : 'LIVE',
    'dry_run_config' => ($config['dry_run_mode'] ?? false) ? 'ON' : 'OFF',
]);

// ── Route events ──────────────────────────────────────────────────────────
whLog($changeType, "Received UCRM webhook: {$changeType}", ['entity_id' => $entityId, 'uuid' => $uuid]);

switch ($changeType) {

    // ── UCRM Test webhook ─────────────────────────────────────────────────
    case 'test': {
        whLog('test', 'UCRM test webhook received - connection is working!', [
            'uuid' => $uuid,
            'entity' => $payload['entity'] ?? null,
            'entityId' => $entityId,
        ]);
        whResp(200, 'Test webhook received successfully. Connection is working!');
    }

    // ── New client created in CRM ────────────────────────────────────────
    case 'client.add':
    case 'CLIENT_ADD': {
        $clientId = $entityId ?: (int)($entity['id'] ?? 0);
        if (!$clientId) whResp(200, 'No client ID — skipped.');

        // Fetch full client to get phone & name
        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        // ── Duplicate phone check — alert admin if phone already exists ───
        if ($phone) {
            $phoneNorm = preg_replace('/[^0-9]/', '', $phone);
            $phoneLast9 = strlen($phoneNorm) >= 9 ? substr($phoneNorm, -9) : $phoneNorm;
            $dupFound = null;

            // Check client_search_index for existing clients with same phone
            $index = $store->load('client_search_index.json') ?? [];
            foreach ($index as $cl) {
                if ((int)($cl['id'] ?? 0) === $clientId) continue; // skip self
                $clPhone = preg_replace('/[^0-9]/', '', $cl['phone'] ?? '');
                $clLast9 = strlen($clPhone) >= 9 ? substr($clPhone, -9) : $clPhone;
                if ($clLast9 === $phoneLast9 && $clLast9 !== '') {
                    $dupFound = $cl;
                    break;
                }
            }

            if ($dupFound) {
                // Send WhatsApp alert to admin (whatsapp_admin_phone in settings)
                $alertMsg = "⚠ *Possible Duplicate Client*\n\n"
                          . "New client just created in CRM:\n"
                          . "*{$name}* (CRM #{$clientId})\n"
                          . "Phone: {$phone}\n\n"
                          . "This phone already belongs to:\n"
                          . "*" . ($dupFound['name'] ?? 'Unknown') . "* (CRM #" . ($dupFound['id'] ?? '?') . ")\n\n"
                          . "Please review and merge if duplicate.\n"
                          . "crm.dishnetafrica.com/crm/client/{$clientId}";

                $notify->sendAdmin($alertMsg, 'ops_dup_alert');
                whLog($changeType, "Duplicate phone alert sent — new #{$clientId} matches existing #" . ($dupFound['id'] ?? '?'));
            }
        }

        if ($phone) {
            // Welcome message — only if NOT already sent by plugin (check local apps)
            $existingApp = $store->findOne('kyc_applications.json', 'crm_client_id', (string)$clientId);
            if (!$existingApp) {
                // Client created directly in UCRM (not via our KYC form) — send welcome
                $notify->send('event_client_add', $phone, $name, [
                    'customer_name' => $name,
                    'crm_id'        => (string)$clientId,
                ]);
                whLog($changeType, "Welcome sent to {$name} ({$phone})", ['crm_id' => $clientId]);
            } else {
                whLog($changeType, "KYC-registered client — welcome already sent by plugin", ['crm_id' => $clientId]);
            }
        }

        whResp(200, 'client.add processed.');
    }

    // ── New invoice created ───────────────────────────────────────────────
    case 'invoice.add':
    case 'INVOICE_ADD': {
        $invoiceId = $entityId ?: (int)($entity['id'] ?? 0);
        if (!$invoiceId) {
            whLog($changeType, 'No invoice ID — skipped', ['entity' => $entity]);
            whResp(200, 'No invoice ID — skipped.');
        }

        $invoice = $crm->get("invoices/{$invoiceId}") ?? $crm->get("billing/invoices/{$invoiceId}") ?? $entity;
        $clientId = (int)($invoice['clientId'] ?? 0);
        $amount   = (float)($invoice['amountToPay'] ?? $invoice['total'] ?? $invoice['amount'] ?? 0);
        $invoNum  = (string)($invoice['number'] ?? $invoice['invoiceNumber'] ?? '');
        $dueDate  = substr($invoice['maturityDate'] ?? $invoice['dueDate'] ?? '', 0, 10);

        // v4.11.40: Skip draft invoices — stronger detection
        // UCRM status: 0=Draft, 1=Unpaid, 2=Partial, 3=Paid, 4=Void
        // Draft invoices have: status=0, no invoice number, and are not yet approved.
        // Guard 1: explicit status check
        // Guard 2: empty invoice number (drafts don't have numbers until approved)
        // Guard 3: status not in allowed list (unknown status = don't send)
        $invStatus = (int)($invoice['status'] ?? -1);
        $statusName = $invoice['statusLabel'] ?? $invoice['statusText'] ?? '';
        if ($invStatus === 0 || stripos($statusName, 'draft') !== false) {
            whLog($changeType, "Draft invoice (status={$invStatus}) — notification deferred until approval", ['invoice_id' => $invoiceId]);
            whResp(200, 'Draft invoice — notification skipped.');
        }
        if (empty($invoNum)) {
            whLog($changeType, "Invoice has no number (likely draft) — notification deferred", ['invoice_id' => $invoiceId, 'status' => $invStatus]);
            whResp(200, 'No invoice number — likely draft, skipped.');
        }
        if (!in_array($invStatus, [1, 2], true)) {
            whLog($changeType, "Invoice status={$invStatus} not unpaid/partial — skipped", ['invoice_id' => $invoiceId, 'number' => $invoNum]);
            whResp(200, "Invoice status {$invStatus} — not unpaid/partial, skipped.");
        }

        // Extract service description from invoice line items
        // UCRM label formats:
        //   Old: "Site : {Name} (000034) Services Plan FTTH50 Duration 7 Apr 2026 – 6 May 2026"
        //   New: "Site : Hidiriha Co Ltd - Torit (KIT...) : Service Plan Starlink Residential : Period 9 Apr 2026 – 8 May 2026"
        // We parse out just Plan + Period for a clean WhatsApp message line.
        $invItems = $invoice['items'] ?? [];
        $itemLabels = [];
        foreach ($invItems as $item) {
            $raw = trim($item['label'] ?? $item['name'] ?? $item['description'] ?? '');
            if (!$raw) continue;
            // Match "Service(s) Plan {name} Duration/Period {dates}" — plan can be multi-word
            if (preg_match('/Services?\s+Plan\s+(.+?)\s*:?\s*(?:Duration|Period)\s+(.+)$/ui', $raw, $m)) {
                $itemLabels[] = trim($m[1]) . ' · ' . trim($m[2]);
            } elseif (preg_match('/Services?\s+Plan\s+(.+?)$/ui', $raw, $m)) {
                $itemLabels[] = trim($m[1]);
            } else {
                $itemLabels[] = $raw; // fallback: use as-is
            }
        }
        $itemLabels = array_values(array_unique($itemLabels));
        $totalItems = count($itemLabels);
        if ($totalItems === 0) {
            $serviceName = '';
        } elseif ($totalItems <= 3) {
            $serviceName = implode(', ', $itemLabels);
        } else {
            $serviceName = implode(', ', array_slice($itemLabels, 0, 2)) . ' + ' . ($totalItems - 2) . ' more';
        }

        if (!$clientId) {
            whLog($changeType, 'No clientId on invoice — skipped');
            whResp(200, 'No clientId on invoice — skipped.');
        }

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        // Determine credit scenario
        $creditData = whInvoiceCreditScenario($invoice, $client);

        whLog($changeType, "Invoice notification check", [
            'invoice_id'   => $invoiceId,
            'invoice_num'  => $invoNum,
            'client_id'    => $clientId,
            'customer'     => $name,
            'phone'        => $phone ?: '(EMPTY)',
            'amount'       => $amount,
            'amount_paid'  => $creditData['amount_paid'],
            'remaining'    => $creditData['remaining'],
            'scenario'     => $creditData['scenario'],
            'due_date'     => $dueDate,
        ]);

        if ($phone && $amount > 0) {
            // ── Atomic dedup: SQLite INSERT OR IGNORE on unique key.
            //    Replaces flock+JSON pattern. Race-safe between webhook + cron.
            $invLogKey = "INV{$invoNum}";
            if (!$notify->dedupMark($invLogKey)) {
                whLog($changeType, "Invoice notification SKIPPED — already sent for #{$invoNum}");
                whResp(200, 'invoice.add already notified — skipped.');
            }

            whSendInvoiceNotification($notify, $crm, $phone, $name,
                $invoiceId, $invoNum, $amount, $dueDate ?: 'See invoice',
                $creditData, $config, $dataDir, $serviceName);

            // Push notification to customer's app
            try {
                fcm_push_invoice_created($store->getPdo(), $config, (int)$clientId, $invoNum, $amount);
            } catch (\Throwable $e) { /* silent */ }
        } else {
            whLog($changeType, "Skipped — no phone or zero amount", [
                'client_id' => $clientId, 'phone' => $phone ?: '(none)', 'amount' => $amount,
            ]);
        }

        // ── v4.21.38: refresh app caches so new invoice appears in customer app ──
        try {
            if (!class_exists('ClientInvoiceCacheRefresher')) {
                @require_once __DIR__ . '/lib/ClientInvoiceCacheRefresher.php';
            }
            if (class_exists('ClientInvoiceCacheRefresher') && $clientId) {
                $refresher = new ClientInvoiceCacheRefresher($store, $crm, $dataDir);
                $invR = $refresher->refreshForClient((int)$clientId, true, 'webhook:invoice.add');
                $cliR = $refresher->refreshClientRecord((int)$clientId, 'webhook:invoice.add');
                whLog($changeType, sprintf(
                    'App cache refresh: invoices=%s (%d) · client=%s',
                    !empty($invR['ok']) ? 'ok' : 'fail',
                    (int)($invR['updated'] ?? 0),
                    !empty($cliR['ok']) ? 'ok' : 'fail'
                ));
            }
        } catch (\Throwable $e) {
            whLog($changeType, 'App cache refresh crashed: ' . $e->getMessage());
        }

        whResp(200, 'invoice.add processed.');
    }

    // ── Payment received ──────────────────────────────────────────────────
    case 'payment.add':
    case 'PAYMENT_ADD': {
        $paymentId = $entityId ?: (int)($entity['id'] ?? 0);
        $payment   = $crm->get("billing/payments/{$paymentId}") ?? $entity;
        $clientId  = (int)($payment['clientId'] ?? 0);
        $amount    = (float)($payment['amount']  ?? 0);
        $txnId     = (string)($payment['id']     ?? $paymentId);

        if (!$clientId) whResp(200, 'No clientId on payment — skipped.');

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim($client['firstName'] ?? '')
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        // ── Auto-post to Cashbook (DishNet Africa book) ───────────────────
        // Only auto-post if amount > 0 and this CRM payment isn't already
        // in the cashbook. Uses PAY-{id} format to match syncFromCrmApi dedup.
        // BUG FIX v4.9.9: was querying non-existent "cashbook_entries" table
        //   → silently failed (caught by try/catch) → no real-time cashbook posts.
        //   Also unified validation_ref from "CRM-PAY-{id}" to "PAY-{id}" so
        //   nightly syncFromCrmApi recognises webhook entries and skips them.
        // v4.10.4: Cashbook = CASH transactions only. Bank/cheque tracked in CRM only.
        require_once __DIR__ . '/lib/PaymentUuids.php';
        $whMethodId   = $payment['methodId'] ?? '';
        $whMethodName = strtolower(trim($payment['methodName'] ?? ''));
        $whIsCash     = ($whMethodId === PaymentUuids::CASH)
            || (empty($whMethodId) && strpos($whMethodName, 'cash') !== false)
            || (empty($whMethodId) && $whMethodName === '');
        if ($amount > 0 && $paymentId > 0 && $whIsCash) {
            try {
                require_once __DIR__ . '/lib/CashbookService.php';
                $cb = new CashbookService($store, $dataDir);

                // Duplicate guard — check BOTH ref formats + crm_payment_id
                // Covers: webhook (PAY-{id}), old webhook (CRM-PAY-{id}), nightly sync
                $dupCheck = $store->getPdo()->prepare(
                    "SELECT id FROM cb_ledger
                     WHERE validation_ref IN (?, ?)
                        OR (crm_payment_id = ? AND direction = 'in')
                     LIMIT 1"
                );
                $dupCheck->execute(["PAY-{$paymentId}", "CRM-PAY-{$paymentId}", $paymentId]);
                $alreadyPosted = (bool)$dupCheck->fetch();

                if (!$alreadyPosted) {
                    // Build description matching Rupesh's existing style
                    // e.g. "Payment received from Shalina Pharmacy against Invoice No-INV010451"
                    $invoiceIds   = $payment['invoiceIds'] ?? ($payment['invoices'] ?? []);
                    $invNos       = [];
                    if (!empty($invoiceIds)) {
                        foreach ((array)$invoiceIds as $iid) {
                            $invNos[] = 'INV' . str_pad((string)(int)$iid, 6, '0', STR_PAD_LEFT);
                        }
                    }
                    $invStr = $invNos ? ' against ' . implode(' & ', $invNos) : '';
                    $note   = trim($payment['note'] ?? '');
                    $method = trim($payment['methodName'] ?? ($payment['method'] ?? 'Online'));
                    $desc   = "Payment received from {$name} (CRM #{$clientId}){$invStr}";
                    if ($note) $desc .= " — {$note}";

                    // Validation status — map CRM method to Rupesh's categories
                    $methodLower = strtolower($method);
                    if (strpos($methodLower, 'cash') !== false) {
                        $valStatus = 'wr';
                    } elseif (strpos($methodLower, 'bank') !== false || strpos($methodLower, 'transfer') !== false) {
                        $valStatus = 'online';
                    } else {
                        $valStatus = 'online';
                    }

                    // Identify who collected — look up payment_collections or parse note
                    $_cashWith = '';
                    $_cashWithId = 0;
                    $note   = trim($payment['note'] ?? '');
                    // Check payment_collections.json first (most reliable)
                    $_cols = $store->load('payment_collections.json') ?? [];
                    foreach ($_cols as $_col) {
                        if ((int)($_col['crm_payment_id'] ?? 0) === $paymentId ||
                            ((int)($_col['crm_customer_id'] ?? 0) === $clientId && abs((float)($_col['amount'] ?? 0) - $amount) < 0.01)) {
                            $_cashWith = $_col['retailer_name'] ?? '';
                            $_cashWithId = (int)($_col['retailer_id'] ?? 0);
                            break;
                        }
                    }
                    // Fallback: parse "Collected by {name}" from note
                    if (!$_cashWith && preg_match('/Collected by ([^|]+)/i', $note, $_m)) {
                        $_cashWith = trim($_m[1]);
                    }
                    // If collected by someone (field), tag with their name
                    // If no collector found, it was entered directly in CRM (already in office)
                    if (!$_cashWith) $_cashWith = 'Office';

                    $cb->addEntryRaw([
                        'sr'                => 'CRM-' . $paymentId,
                        'project'           => 'dishnet',
                        'date'              => date('Y-m-d'),
                        'direction'         => 'in',
                        'amount'            => $amount,
                        'currency'          => 'USD',
                        'category'          => 'Receipt',
                        'category_raw'      => 'Receipt',
                        'person'            => $_cashWith !== 'Office' ? $_cashWith : '',
                        'description'       => $desc,
                        'validation_ref'    => "PAY-{$paymentId}",
                        'validation_status' => $valStatus,
                        'status'            => 'approved',
                        'crm_payment_id'    => $paymentId,
                        'crm_client_id'     => $clientId,
                        'source'            => 'crm_webhook',
                        'cash_with'         => $_cashWith,
                        'cash_with_id'      => $_cashWithId,
                        'created_at'        => date('Y-m-d H:i:s'),
                    ]);

                    whLog($changeType, "Cashbook auto-post: \${$amount} from {$name} (PAY-{$paymentId})");
                } else {
                    whLog($changeType, "Cashbook skip — PAY-{$paymentId} already posted");
                }
            } catch (\Throwable $cbEx) {
                // Never let cashbook failure break the webhook response
                whLog('cashbook_error', "Auto-post failed for PAY-{$paymentId}: " . $cbEx->getMessage());
            }
        }
        // ── End cashbook auto-post ────────────────────────────────────────
        // v4.10.4: Log non-cash payments that are intentionally skipped from cashbook
        if ($amount > 0 && $paymentId > 0 && !$whIsCash) {
            whLog($changeType, "Cashbook skip — PAY-{$paymentId} is non-cash (method: {$whMethodName}), not posted to cashbook");
        }

        // ── WhatsApp receipt + PDF — send to customer for ALL payments ──
        if ($phone && $amount > 0) {
            // ── Clear invoice dedup keys for invoices covered by this payment ──
            try {
                $paidInvs = $crm->get("billing/invoices?clientId={$clientId}&statuses[]=3&limit=10") ?? [];
                foreach ($paidInvs as $pInv) {
                    $pInvNum = $pInv['number'] ?? '';
                    if ($pInvNum) {
                        $store->getPdo()->prepare(
                            "DELETE FROM notification_dedup WHERE dedup_key = ?"
                        )->execute(["INV{$pInvNum}"]);
                        whLog($changeType, "Cleared notify dedup for paid invoice #{$pInvNum}");
                    }
                }
            } catch (\Throwable $_e) { /* non-fatal */ }

            // ── Write suspension suppression key ──
            // UCRM may fire service.suspend at the same time as payment.add
            // (suspension_delay=0). This key tells the suspend handler to skip
            // the WhatsApp notification for this client for 5 minutes.
            try {
                $store->getPdo()->prepare(
                    "INSERT OR REPLACE INTO plugin_kv (key, value, updated_at) VALUES (?, ?, datetime('now'))"
                )->execute(['susp_skip_' . $clientId, (string)time()]);
            } catch (\Throwable $_e) { /* non-fatal */ }
            // ── Atomic dedup: SQLite INSERT OR IGNORE — race-safe ──
            $payLogKey = "PAY{$paymentId}";
            if (!$notify->dedupMark($payLogKey)) {
                whLog($changeType, "Payment notification SKIPPED — already sent for PAY-{$txnId}");
            } else {
                // Send text receipt to customer
                $notify->paymentReceived($phone, $name, $amount, "PAY-{$txnId}");
                whLog($changeType, "Payment thanks sent: \${$amount} → {$name}");

                // v4.10.4: Queue receipt PDF for cron pickup — background fetch after
                // fastcgi_finish_request was unreliable (sleep + cURL never completed).
                // Cron picks this up in ~5 min, fetches PDF via getRawContent(), sends WA.
                $receiptQueue = $store->load('receipt_pdf_queue.json') ?? [];

                // v4.11.3: Dedup — don't queue if this payment_id is already queued
                $_alreadyQueued = false;
                foreach ($receiptQueue as $_rqi) {
                    if ((int)($_rqi['payment_id'] ?? 0) === $paymentId) {
                        $_alreadyQueued = true;
                        break;
                    }
                }

                if (!$_alreadyQueued) {
                    $receiptQueue[] = [
                        'payment_id'    => $paymentId,
                        'client_id'     => $clientId,
                        'customer_name' => $name,
                        'phone'         => $phone,
                        'amount'        => $amount,
                        'queued_at'     => time(),
                    ];
                    // Keep only last 200 pending entries
                    $receiptQueue = array_filter($receiptQueue, function($r) {
                        return empty($r['sent']) && (time() - ($r['queued_at'] ?? 0)) < 86400;
                    });
                    $store->save('receipt_pdf_queue.json', array_values($receiptQueue));
                    whLog($changeType, "Receipt PDF queued for cron — PAY-{$paymentId}");
                } else {
                    whLog($changeType, "Receipt PDF SKIP — PAY-{$paymentId} already in queue");
                }

                // ── Flush response to UCRM, then run delivery note check in background ──
                header('Content-Type: application/json');
                header('Connection: close');
                $respBody = json_encode(['status' => 'ok', 'message' => 'payment.add processed.']);
                header('Content-Length: ' . strlen($respBody));
                echo $respBody;
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } else {
                    ob_end_flush();
                    flush();
                }

                // ── Delivery Note for credit-sale customers ──────────────────
                // If this client was registered via KYC as a credit sale AND
                // hasn't received a delivery note yet → generate & send now.
                // This is the moment they paid and will take equipment.
                try {
                    $kycApp = $store->findOne('kyc_applications.json', 'crm_client_id', (string)$clientId);
                    if ($kycApp) {
                        $salesType       = strtolower(trim($kycApp['sales_type'] ?? ''));
                        $deliveryAlready = !empty($kycApp['delivery_pdf_sent']);
                        $isCredit        = ($salesType !== 'cash');

                        if ($isCredit && !$deliveryAlready) {
                            // Check if total paid covers the quoted amount
                            $quotedAmount = (float)($kycApp['amount_charged'] ?? $kycApp['total_amount'] ?? 0);
                            // Get total payments for this client from CRM
                            $clientPayments = $crm->get("clients/{$clientId}/payments") ?? [];
                            $totalPaid = 0;
                            if (is_array($clientPayments)) {
                                foreach ($clientPayments as $cp) {
                                    $totalPaid += (float)($cp['amount'] ?? 0);
                                }
                            }
                            // If total paid is 0 (API might not support this endpoint), 
                            // use current payment amount as fallback
                            if ($totalPaid <= 0) $totalPaid = $amount;

                            whLog($changeType, "Credit sale check: quoted=\${$quotedAmount} paid=\${$totalPaid} delivery_sent={$deliveryAlready}");

                            if ($totalPaid >= $quotedAmount || $quotedAmount <= 0) {
                                // Generate delivery note
                                require_once __DIR__ . '/lib/DeliveryPdfService.php';
                                $retailer = [
                                    'name' => $kycApp['agent_name'] ?? $kycApp['submitted_by'] ?? 'DishNet Staff',
                                    'role' => $kycApp['agent_role'] ?? 'Field Agent',
                                ];
                                $pdfSvc = new DeliveryPdfService($store, $dataDir, $config);
                                $delResult = $pdfSvc->generateAndSend($kycApp, $retailer, (string)$clientId);

                                if (!empty($delResult['ok'])) {
                                    $store->updateOne('kyc_applications.json', 'crm_client_id', (string)$clientId, [
                                        'delivery_pdf_sent' => true,
                                        'delivery_sent_at'  => date('Y-m-d H:i:s'),
                                        'delivery_trigger'  => 'payment_received',
                                        'terms_pdf_path'    => $delResult['pdf_path'] ?? '',
                                    ]);
                                    whLog($changeType, "Delivery PDF SENT to credit customer {$name} (triggered by payment)");
                                } else {
                                    whLog($changeType, "Delivery PDF generation failed for {$name}: " . ($delResult['error'] ?? 'unknown'));
                                }
                            } else {
                                whLog($changeType, "Delivery note DEFERRED — partial payment (\${$totalPaid} of \${$quotedAmount})");
                            }
                        }
                    }
                } catch (\Throwable $delEx) {
                    whLog('delivery_error', "Delivery note trigger failed for PAY-{$paymentId}: " . $delEx->getMessage());
                }

                flock($payLockFp, LOCK_UN); fclose($payLockFp); @unlink($payLockFile);
                exit;
            } // end plugin dedup else
        }

        // ── Mark this client as "just paid" so service.unsuspend skips the
        //    redundant "Service Restored" message that arrives seconds later.
        //    Marker expires after 3 minutes (checked by service restored handler).
        if ($clientId && $amount > 0) {
            try {
                @file_put_contents(
                    $dataDir . '/recent_payment_' . $clientId . '.marker',
                    json_encode(['ts' => time(), 'amount' => $amount, 'phone' => $phone])
                );
            } catch (\Throwable $_) {}

            // Push notification to customer's app
            try {
                fcm_push_payment_received($store->getPdo(), $config, (int)$clientId, $amount);
            } catch (\Throwable $e) { /* silent */ }

            // ── v4.21.0: Instant restore for Starlink customers who paid ──
            // UCRM's service.unsuspend may take minutes (or never fire if suspension
            // was manual). If we have an active sl_suspension_state row for this
            // client, restore now so customer's WiFi works immediately.
            // restore() is idempotent — no-op if no row exists.
            try {
                $blockSvc = new StarlinkBlockService($store->getPdo(), $store, $config, $dataDir, $notify);
                $restoreResult = $blockSvc->restore((int)$clientId, 'payment.add');
                if (($restoreResult['routers_restored'] ?? 0) > 0) {
                    whLog($changeType, sprintf(
                        'Starlink instant-restore on payment: routers=%d',
                        $restoreResult['routers_restored']
                    ));
                }
            } catch (\Throwable $e) {
                whLog($changeType, 'Starlink instant-restore crashed: ' . $e->getMessage());
            }

            // ── v4.21.29: Bridge auto-restore via dishnet-data-report ──
            // Reads data-report's wifi_test_block_state.json directly, finds
            // routers belonging to this client's KITs, and unblocks via
            // dr_wifi_test_unblock. Picks up customers blocked via the
            // Block Manager UI / WiFi tab (which write to data-report's
            // state, not Hybrid's sl_suspension_state). Idempotent.
            try {
                if (!class_exists('StarlinkBlockBridge')) {
                    @require_once __DIR__ . '/lib/StarlinkBlockBridge.php';
                }
                if (class_exists('StarlinkBlockBridge')) {
                    $bridge = new StarlinkBlockBridge($store->getPdo(), $store, $config, $dataDir, $notify);
                    $bridgeResult = $bridge->restoreClient((int)$clientId, 'webhook:payment.add');
                    if (($bridgeResult['routers_restored'] ?? 0) > 0) {
                        whLog($changeType, sprintf(
                            'Starlink bridge auto-restore on payment: routers=%d failed=%d',
                            $bridgeResult['routers_restored'],
                            $bridgeResult['routers_failed'] ?? 0
                        ));
                    }
                }
            } catch (\Throwable $e) {
                whLog($changeType, 'Starlink bridge auto-restore crashed: ' . $e->getMessage());
            }

            // ── v4.21.38: Refresh invoice + client cache for the customer app ──
            // The mobile app reads ucrm_invoices_cache.json and
            // client_search_index.json (cached). When a payment posts, those
            // caches stay stale until manual sync — so the app keeps showing
            // "X invoices due · $Y · Pay to keep service active" on the home
            // screen even after the payment cleared. This refresh updates both
            // caches surgically (only this one client) so the next time the
            // customer opens the app, fresh data appears within seconds.
            try {
                if (!class_exists('ClientInvoiceCacheRefresher')) {
                    @require_once __DIR__ . '/lib/ClientInvoiceCacheRefresher.php';
                }
                if (class_exists('ClientInvoiceCacheRefresher')) {
                    $refresher = new ClientInvoiceCacheRefresher($store, $crm, $dataDir);
                    $invR = $refresher->refreshForClient((int)$clientId, true, 'webhook:payment.add');
                    $cliR = $refresher->refreshClientRecord((int)$clientId, 'webhook:payment.add');
                    whLog($changeType, sprintf(
                        'App cache refresh: invoices=%s (%d updated) · client=%s',
                        !empty($invR['ok']) ? 'ok' : 'fail',
                        (int)($invR['updated'] ?? 0),
                        !empty($cliR['ok']) ? 'ok' : 'fail'
                    ));
                }
            } catch (\Throwable $e) {
                whLog($changeType, 'App cache refresh crashed: ' . $e->getMessage());
            }

            // v4.21.66: Close any matching Overdue Workbench rows for invoices
            // this payment cleared. Idempotent — no-op when no workbench row.
            // Wrapped in try/catch; never breaks the webhook response.
            try {
                $invoiceIdsForOwb = $payment['invoiceIds'] ?? ($payment['invoices'] ?? []);
                if (!empty($invoiceIdsForOwb)) {
                    require_once __DIR__ . '/lib/OverdueWorkbenchService.php';
                    $owbCfg = $store->load('kyc_config.json') ?: [];
                    $owb = new OverdueWorkbenchService($store, $owbCfg, $crm);
                    $closed = 0;
                    foreach ($invoiceIdsForOwb as $invRef) {
                        // invoiceIds may be array of ids OR array of {id:N}
                        $invId = is_array($invRef) ? (int)($invRef['id'] ?? 0) : (int)$invRef;
                        if ($invId <= 0) continue;
                        // Resolve invoice number from cache or live API
                        $invObj = $crm->get("billing/invoices/{$invId}");
                        $invNum = is_array($invObj) ? (string)($invObj['number'] ?? '') : '';
                        if ($invNum === '') continue;
                        if ($owb->onPaymentMatched($invNum, ['name'=>'webhook:payment.add', 'id'=>0])) {
                            $closed++;
                        }
                    }
                    if ($closed > 0) {
                        whLog($changeType, "Overdue Workbench: closed {$closed} row(s) for paid invoices");
                    }
                }
            } catch (\Throwable $e) {
                whLog($changeType, 'OWB close hook crashed: ' . $e->getMessage());
            }
        }

        whResp(200, 'payment.add processed.');
    }

    // ── New service created ────────────────────────────────────────────────
    case 'service.add':
    case 'SERVICE_ADD': {
        $serviceId = $entityId ?: (int)($entity['id'] ?? 0);
        $service   = $crm->get("clients/services/{$serviceId}") ?? $entity;
        $clientId  = (int)($service['clientId'] ?? 0);
        $svcName   = $service['name'] ?? $service['serviceName'] ?? 'Internet Service';
        $status    = (int)($service['status'] ?? 0);

        if (!$clientId) {
            whLog($changeType, 'No clientId on service — skipped');
            whResp(200, 'No clientId — skipped.');
        }

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        // Only notify if service is active (status=1)
        if ($phone && $status == 1) {
            $notify->sendVia('accounts', $phone,
                "🚀 *Service Activated — DishNet Africa*\n\n"
                . "Dear {$name},\n\n"
                . "Your DishNet service *{$svcName}* is now active. 🌐\n\n"
                . "🔑 Login credentials have been shared via email.\n\n"
                . "Manage your account:\n"
                . "🔗 https://dishnetafrica.com/tutorials/index.html\n\n"
                . "📞 Support: +211 921 443 002\n"
                . "💬 wa.me/211921443002\n\n"
                . "Thank you for choosing DishNet! 🙏\n"
                . "— DishNet Team",
                'ops_service_activated');
            whLog($changeType, "Service activated notification -> {$name} ({$svcName})");

            // Push notification to customer's app
            try {
                fcm_push_service_activated($store->getPdo(), $config, (int)$clientId, $svcName);
            } catch (\Throwable $e) { /* silent — push is best-effort */ }
        } else {
            whLog($changeType, "Service.add logged (no notification - status={$status}, phone=" . ($phone ?: 'none') . ")");
        }

        // -- v4.11.3: Fiber install flow - fire immediately via webhook ----
        // When Bidal creates a service in Splynx OR UCRM for a fiber customer,
        // this fires the full install chain instantly (no 5-min cron wait):
        //   delivery note PDF -> customer
        //   CRM scheduling job -> Rupesh
        //   Splynx ticket auto-close
        //
        // Detects fiber by plan name. Only fires if:
        //   - service is active (status=1)
        //   - plan name contains 'fiber' or 'ftth'
        //   - no existing fiber_collection_jobs entry for this client
        $svcNameLower = strtolower($svcName);
        $isFiberSvc = strpos($svcNameLower, 'fiber') !== false
                   || strpos($svcNameLower, 'ftth') !== false
                   || strpos($svcNameLower, 'optical') !== false;

        if ($isFiberSvc && $status == 1 && $clientId > 0) {
            try {
                require_once __DIR__ . '/lib/FiberInstallService.php';
                $fiberInvSvc = new FiberInstallService($store->getPdo(), $store, $config, $dataDir);

                // Dedup: skip if already has a collection job for this CRM client
                $existsStmt = $store->getPdo()->prepare(
                    "SELECT id FROM fiber_collection_jobs WHERE crm_client_id = ? AND status = 'pending' LIMIT 1"
                );
                $existsStmt->execute([$clientId]);
                if ($existsStmt->fetch()) {
                    whLog($changeType, "Fiber service.add: collection job already exists for CRM client #{$clientId} -- skipped");
                } else {
                    // Look up matching open Splynx ticket via fiber_customer_map
                    $ticket = [];
                    try {
                        // Try to find Splynx customer ID from fiber_customer_map
                        $mapStmt = $store->getPdo()->prepare(
                            "SELECT splynx_customer_id FROM fiber_customer_map WHERE crm_client_id = ? LIMIT 1"
                        );
                        $mapStmt->execute([(string)$clientId]);
                        $splynxCustId = (int)($mapStmt->fetchColumn() ?? 0);

                        if ($splynxCustId > 0) {
                            // Find open ticket for this Splynx customer
                            $tkStmt = $store->getPdo()->prepare(
                                "SELECT * FROM tickets WHERE customer_id = ? AND install_complete = 0 AND cancelled = 0 ORDER BY created_at DESC LIMIT 1"
                            );
                            $tkStmt->execute([$splynxCustId]);
                            $ticketRow = $tkStmt->fetch(\PDO::FETCH_ASSOC);
                            if ($ticketRow) $ticket = $ticketRow;
                        }

                        // Also try matching by CRM client ID directly on tickets
                        if (empty($ticket)) {
                            $tkStmt2 = $store->getPdo()->prepare(
                                "SELECT * FROM tickets WHERE crm_client_id = ? AND install_complete = 0 AND cancelled = 0 ORDER BY created_at DESC LIMIT 1"
                            );
                            $tkStmt2->execute([$clientId]);
                            $ticketRow2 = $tkStmt2->fetch(\PDO::FETCH_ASSOC);
                            if ($ticketRow2) $ticket = $ticketRow2;
                        }
                    } catch (\Throwable $tkErr) {}

                    // Build synthetic Splynx service object for processNewInstall
                    $syntheticService = [
                        'id'          => $serviceId,
                        'customer_id' => $splynxCustId ?? 0,
                        'status'      => 'active',
                        'description' => $svcName,
                        'tariff_name' => $svcName,
                        'price'       => (float)($service['price'] ?? 0),
                    ];

                    // Build customer object
                    $customerObj = [
                        'id'    => $splynxCustId ?? 0,
                        'name'  => $name,
                        'phone' => $phone,
                        'login' => (string)$clientId,
                    ];

                    // Merge CRM client ID onto ticket so processNewInstall can link it
                    $ticket['crm_client_id'] = $clientId;
                    if (empty($ticket['customer_name'])) $ticket['customer_name'] = $name;
                    if (empty($ticket['phone']))          $ticket['phone']         = $phone;

                    $result = $fiberInvSvc->processNewInstall($syntheticService, $customerObj, $ticket);

                    if ($result['ok'] && $result['action'] === 'created') {
                        whLog($changeType, "Fiber install flow fired via webhook for {$name} (CRM #{$clientId}) -- job #{$result['job_id']}, amount \$" . ($result['amount'] ?? 0));
                    } elseif ($result['action'] === 'exists') {
                        whLog($changeType, "Fiber install flow: job already exists for {$name}");
                    } else {
                        whLog($changeType, "Fiber install flow ERROR for {$name}: " . ($result['error'] ?? 'unknown'));
                    }
                }
            } catch (\Throwable $fiberErr) {
                whLog($changeType, "Fiber service.add exception: " . $fiberErr->getMessage());
            }
        }

        whResp(200, 'service.add processed.');
    }

    // -- Service suspended -------------------------------------------------
    case 'suspend':
    case 'service.suspend':
    case 'SERVICE_SUSPEND': {
        $serviceId = $entityId ?: (int)($entity['id'] ?? 0);
        $service   = $crm->get("clients/services/{$serviceId}") ?? $entity;
        $clientId  = (int)($service['clientId'] ?? 0);

        // Clean service name — UCRM returns raw names like
        // "Site : Amanuel (000252) Service Plan FTTH50 : Period"
        $rawSvcName = $service['name'] ?? $service['serviceName'] ?? '';
        if (preg_match('/Service\\s+Plan\\s+([^\\s:]+)/i', $rawSvcName, $m)) {
            $svcName = $m[1]; // e.g. "FTTH50"
        } elseif (!empty($service['tariffName'])) {
            $svcName = $service['tariffName'];
        } elseif (!empty($rawSvcName)) {
            $parts = explode(':', $rawSvcName);
            $svcName = trim(end($parts)) ?: $rawSvcName;
        } else {
            $svcName = 'Internet Service';
        }

        if (!$clientId) whResp(200, 'No clientId — skipped.');

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        // Skip if client has no outstanding balance (payment just received simultaneously)
        $outstandingRaw = (float)($client['accountOutstandingRaw'] ?? $client['accountOutstanding'] ?? 0);
        if ($outstandingRaw <= 0) {
            whLog($changeType, "Suspension skipped — {$name} has no outstanding balance");
            whResp(200, 'service.suspend skipped — balance settled.');
        }

        // Skip if payment dedup key exists (paid within last 5 min)
        try {
            $dedupRow = $store->getPdo()->prepare("SELECT value FROM plugin_kv WHERE key=? LIMIT 1");
            $dedupRow->execute(['susp_skip_' . $clientId]);
            $dedupVal = $dedupRow->fetchColumn();
            if ($dedupVal && (time() - (int)$dedupVal) < 300) {
                whLog($changeType, "Suspension WA suppressed — recent payment for {$name}");
                whResp(200, 'service.suspend suppressed — recent payment.');
            }
        } catch (\Throwable $e) {}

        // ── v4.21.2: VIP early-skip — silent for customer, alert for admin only ──
        // For NO_AUTO_BLOCK clients (embassies, IOM, UN, etc.) we skip ALL
        // customer-facing side effects: no WA, no FCM push, no device block.
        // The customer experiences nothing — UCRM still shows them as suspended
        // internally, but their service continues uninterrupted. Admin gets a
        // private WA alert so support can decide how to handle it manually
        // (call the customer, defer, raise with diplomatic finance team, etc.).
        //
        // v4.21.6: pass $client (fresh from UCRM API a few lines above) to
        // isVipClient so tag check uses live data, not the daily-refreshed
        // cache. Critical: if admin tags a customer as NO_AUTO_BLOCK at 9am,
        // they're protected on the very next suspend webhook — not 18 hours
        // later when the cache refreshes.
        try {
            $vipBlockSvc = new StarlinkBlockService($store->getPdo(), $store, $config, $dataDir, $notify);
            if ($vipBlockSvc->isVipClient((int)$clientId, $client)) {
                whLog($changeType, "VIP suspension intercepted — {$name} (CRM #{$clientId}) — no customer WA, no block");

                // Admin alert: this is the ONLY signal that anything happened.
                $adminPhone = (string)($config['whatsapp_admin_phone'] ?? '');
                if ($adminPhone !== '') {
                    $outstandingFmt = number_format($outstandingRaw, 2);
                    $notify->sendVia('accounts', $adminPhone,
                        "🛡️ *VIP Suspension Intercepted*\n\n"
                        . "UCRM marked *{$name}* (CRM #{$clientId}) as suspended.\n"
                        . "Service: *{$svcName}*\n"
                        . "Outstanding: *\${$outstandingFmt}*\n\n"
                        . "Customer received NO message. Devices NOT blocked.\n"
                        . "Tag: NO_AUTO_BLOCK — manual decision required.\n\n"
                        . "Review in CRM and reach out to client finance team if needed.",
                        'ops_vip_suspension_intercepted');
                }
                whResp(200, 'service.suspend intercepted — VIP, admin alerted.');
            }
        } catch (\Throwable $e) {
            // VIP check failure must not block normal flow — fall through to
            // standard suspend (better to send a WA than silently fail and
            // block someone who should have been protected). Logged for review.
            whLog($changeType, 'VIP guard error: ' . $e->getMessage() . ' — falling through to standard suspend');
        }

        if ($phone) {
            $notify->sendVia('accounts', $phone,
                "🚫 *Service Suspended — DishNet Africa*\n\n"
                . "Dear {$name},\n\n"
                . "Your DishNet *{$svcName}* service has been suspended due to an unpaid invoice.\n\n"
                . "To restore your service immediately:\n"
                . "1️⃣ Pay online: https://dishnetafrica.com/tutorials/index.html\n"
                . "2️⃣ Or contact your agent\n\n"
                . "Service is restored *automatically within minutes* of payment.\n\n"
                . "📞 +211 921 443 009\n"
                . "— DishNet Accounts",
                'ops_service_suspended');
            whLog($changeType, "Suspension WhatsApp sent to {$name} ({$svcName})");

            // Push notification to customer's app
            try {
                fcm_push_service_suspended($store->getPdo(), $config, (int)$clientId, $svcName);
            } catch (\Throwable $e) { /* silent — push is best-effort */ }
        }

        // ── v4.21.0: Auto-block Starlink devices after suspension WA sent ──
        // Reads sl_kits.json + wifi_router_map.json, pauses live MACs, swaps
        // SSID+password to suspension values. VIP guard + bypass-mode handled
        // inside the service. Failures auto-retry via cron_starlink_block_retry.
        try {
            $blockSvc = new StarlinkBlockService($store->getPdo(), $store, $config, $dataDir, $notify);
            $blockResult = $blockSvc->suspend((int)$clientId, (int)$serviceId, 'webhook', 'service.suspend', $client);
            whLog($changeType, sprintf(
                'Starlink auto-block: ok=%s routers=%d failed=%d skip=%s',
                !empty($blockResult['ok']) ? 'yes' : 'no',
                $blockResult['routers_processed'] ?? 0,
                $blockResult['routers_failed'] ?? 0,
                $blockResult['skipped_reason'] ?? '-'
            ));
        } catch (\Throwable $e) {
            // Block-service failure must NOT break the webhook — WA already sent successfully
            whLog($changeType, 'Starlink auto-block crashed: ' . $e->getMessage());
        }

        // ── v4.21.29: Bridge auto-block via dishnet-data-report ──
        // The original StarlinkBlockService path above can fail silently when
        // sl_kits.json is incomplete or wifi_router_map.json doesn't have
        // entries for the client's KIT. The bridge calls data-report's
        // dr_wifi_test_block directly — same proven path the Block Manager
        // UI uses. Idempotent (skips already-paused). Best-effort: any
        // failure here doesn't affect the webhook response.
        try {
            if (!class_exists('StarlinkBlockBridge')) {
                @require_once __DIR__ . '/lib/StarlinkBlockBridge.php';
            }
            if (class_exists('StarlinkBlockBridge')) {
                $bridge = new StarlinkBlockBridge($store->getPdo(), $store, $config, $dataDir, $notify);
                $bridgeResult = $bridge->suspendClient((int)$clientId, $client, 'webhook:service.suspend');
                whLog($changeType, sprintf(
                    'Starlink bridge auto-block: ok=%s routers=%d failed=%d skip=%s',
                    !empty($bridgeResult['ok']) ? 'yes' : 'no',
                    $bridgeResult['routers_processed'] ?? 0,
                    $bridgeResult['routers_failed'] ?? 0,
                    $bridgeResult['skipped_reason'] ?? '-'
                ));
            }
        } catch (\Throwable $e) {
            whLog($changeType, 'Starlink bridge auto-block crashed: ' . $e->getMessage());
        }

        whResp(200, 'service.suspend processed.');
    }

    // ── Service restored ──────────────────────────────────────────────────
    case 'unsuspend':
    case 'service.unsuspend':
    case 'service.activate':
    case 'service.suspend_cancel':
    case 'SERVICE_ACTIVATE': {
        $serviceId = $entityId ?: (int)($entity['id'] ?? 0);
        $service   = $crm->get("clients/services/{$serviceId}") ?? $entity;
        $clientId  = (int)($service['clientId'] ?? 0);
        $svcName   = $service['name'] ?? 'Internet Service';

        if (!$clientId) whResp(200, 'No clientId — skipped.');

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        if ($phone) {
            // ── Dedup: if payment.add just fired for this client (within 3 min),
            //    the customer already got a "Payment Received" message — don't pile
            //    on with another WhatsApp immediately.
            $markerFile   = $dataDir . '/recent_payment_' . $clientId . '.marker';
            $recentPayment = false;
            if (file_exists($markerFile)) {
                $marker = @json_decode(@file_get_contents($markerFile), true);
                if ($marker && (time() - ($marker['ts'] ?? 0)) < 180) {
                    $recentPayment = true;
                }
                // Clean up expired markers
                @unlink($markerFile);
            }

            // ── v4.21.3: Dedup: if service.postpone just fired (within 3 min),
            //    the customer already got the "Service Temporarily Restored" WA
            //    and devices are already unpaused. UCRM sometimes fires both
            //    postpone AND unsuspend in the same operation. Skip the regular
            //    "✅ Service Restored" message in that case — it would be wrong
            //    (customer hasn't actually paid).
            $postponeMarkerFile = $dataDir . '/recent_postpone_' . $clientId . '.marker';
            $recentPostpone = false;
            if (file_exists($postponeMarkerFile)) {
                $postponeMarker = @json_decode(@file_get_contents($postponeMarkerFile), true);
                if ($postponeMarker && (time() - ($postponeMarker['ts'] ?? 0)) < 180) {
                    $recentPostpone = true;
                }
                // Don't unlink — postpone marker stays valid until natural expiry,
                // because the postpone period itself may be days
            }

            // ── v4.21.0: Restore Starlink devices (run BEFORE WA so we can include creds) ──
            // payment.add may have already restored — restore() is idempotent (no-op if no row).
            $restoredCreds = []; // [{ssid, password}, ...] — appended to WA message
            try {
                $blockSvc = new StarlinkBlockService($store->getPdo(), $store, $config, $dataDir, $notify);
                $restoreResult = $blockSvc->restore((int)$clientId, 'webhook');
                $restoredCreds = $restoreResult['restored_credentials'] ?? [];
                whLog($changeType, sprintf(
                    'Starlink auto-restore: ok=%s routers=%d failed=%d',
                    !empty($restoreResult['ok']) ? 'yes' : 'no',
                    $restoreResult['routers_restored'] ?? 0,
                    $restoreResult['routers_failed'] ?? 0
                ));
            } catch (\Throwable $e) {
                whLog($changeType, 'Starlink auto-restore crashed: ' . $e->getMessage());
            }

            // ── v4.21.29: Bridge auto-restore via dishnet-data-report ──
            try {
                if (!class_exists('StarlinkBlockBridge')) {
                    @require_once __DIR__ . '/lib/StarlinkBlockBridge.php';
                }
                if (class_exists('StarlinkBlockBridge')) {
                    $bridge = new StarlinkBlockBridge($store->getPdo(), $store, $config, $dataDir, $notify);
                    $bridgeResult = $bridge->restoreClient((int)$clientId, 'webhook:' . $changeType);
                    if (($bridgeResult['routers_restored'] ?? 0) > 0) {
                        whLog($changeType, sprintf(
                            'Starlink bridge auto-restore: routers=%d failed=%d',
                            $bridgeResult['routers_restored'],
                            $bridgeResult['routers_failed'] ?? 0
                        ));
                    }
                }
            } catch (\Throwable $e) {
                whLog($changeType, 'Starlink bridge auto-restore crashed: ' . $e->getMessage());
            }

            if ($recentPayment) {
                whLog($changeType, "Service restored notification SKIPPED for {$name} — payment receipt sent <3 min ago");
            } elseif ($recentPostpone) {
                // v4.21.3: postpone WA already sent — don't send the regular
                // "✅ Restored" because the customer hasn't paid yet.
                whLog($changeType, "Service restored notification SKIPPED for {$name} — postpone WA sent <3 min ago");
            } else {
                // Build WiFi credential block if we restored any (for Starlink customers)
                $credBlock = '';
                if (!empty($restoredCreds)) {
                    $credBlock = "\n\n📡 *Your WiFi*\n";
                    foreach ($restoredCreds as $c) {
                        if (empty($c['ssid']) || empty($c['password'])) continue;
                        $credBlock .= "Network: *{$c['ssid']}*\n";
                        $credBlock .= "Password: `{$c['password']}`\n";
                    }
                }

                $notify->sendVia('accounts', $phone,
                    "✅ *Service Restored — DishNet Africa*\n\n"
                    . "Dear {$name},\n\n"
                    . "Your DishNet service *{$svcName}* has been restored and is now active. 🌐\n\n"
                    . "You're back online!"
                    . $credBlock
                    . "\n\nManage your account:\n"
                    . "🔗 https://dishnetafrica.com/tutorials/index.html\n\n"
                    . "📞 +211 921 443 009\n"
                    . "— DishNet Accounts",
                    'ops_service_restored');
                whLog($changeType, "Restoration notice sent to {$name}");
            }

            // Push notification to customer's app (always — even if WA was deduped)
            try {
                fcm_push_service_activated($store->getPdo(), $config, (int)$clientId, $svcName);
            } catch (\Throwable $e) { /* silent */ }
        }

        // -- v4.11.3: Fiber install flow on service activation ----------
        // Catches case where plugin created service as 'disabled' and
        // engineer later activates it after completing installation.
        $svcNameLower2 = strtolower($svcName);
        $isFiberAct = strpos($svcNameLower2, 'fiber') !== false
                   || strpos($svcNameLower2, 'ftth') !== false
                   || strpos($svcNameLower2, 'optical') !== false;
        if ($isFiberAct && $clientId > 0) {
            try {
                $existsAct = $store->getPdo()->prepare(
                    "SELECT id FROM fiber_collection_jobs WHERE crm_client_id = ? AND status = 'pending' LIMIT 1"
                );
                $existsAct->execute([$clientId]);
                if (!$existsAct->fetch()) {
                    require_once __DIR__ . '/lib/FiberInstallService.php';
                    $fiberSvc2 = new FiberInstallService($store->getPdo(), $store, $config, $dataDir);
                    $ticket2   = [];
                    try {
                        $tkAct = $store->getPdo()->prepare(
                            "SELECT * FROM tickets WHERE crm_client_id = ? AND install_complete = 0 AND cancelled = 0 ORDER BY created_at DESC LIMIT 1"
                        );
                        $tkAct->execute([$clientId]);
                        $tkRow2 = $tkAct->fetch(\PDO::FETCH_ASSOC);
                        if ($tkRow2) $ticket2 = $tkRow2;
                    } catch (\Throwable $e) {}
                    $ticket2['crm_client_id']   = $clientId;
                    $ticket2['customer_name']   = $ticket2['customer_name'] ?? $name;
                    $ticket2['phone']           = $ticket2['phone'] ?? $phone;
                    $synthSvc2 = ['id' => $serviceId, 'status' => 'active', 'tariff_name' => $svcName, 'price' => 0];
                    $custObj2  = ['id' => 0, 'name' => $name, 'phone' => $phone, 'login' => (string)$clientId];
                    $res2 = $fiberSvc2->processNewInstall($synthSvc2, $custObj2, $ticket2);
                    whLog($changeType, "Fiber activate flow: " . ($res2['action'] ?? 'unknown') . " for {$name}");
                }
            } catch (\Throwable $e) {
                whLog($changeType, "Fiber activate flow error: " . $e->getMessage());
            }
        }

        whResp(200, 'service restored - notification sent.');
    }

    // -- Service postponed (admin extends customer despite unpaid balance) --
    // v4.21.3: previously this event was a no-op. Now: unpause Starlink
    // devices (same restore() call as service.unsuspend), but send a
    // DIFFERENT WA template that warns the bill is still pending.
    //
    // Sets a 'recent_postpone' marker so any subsequent service.unsuspend
    // event UCRM may fire alongside postpone is suppressed (avoids sending
    // the wrong "✅ Service Restored — back online" message which would
    // confuse the customer about their balance).
    case 'postpone':
    case 'service.postpone':
    case 'SERVICE_POSTPONE': {
        $serviceId = $entityId ?: (int)($entity['id'] ?? 0);
        $service   = $crm->get("clients/services/{$serviceId}") ?? $entity;
        $clientId  = (int)($service['clientId'] ?? 0);
        $svcName   = $service['name'] ?? $service['serviceName'] ?? 'Internet Service';

        if (!$clientId) whResp(200, 'No clientId — skipped.');

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        // Outstanding balance — useful for message context
        $outstanding    = (float)($client['accountOutstanding'] ?? 0);
        $outstandingFmt = number_format($outstanding, 2);

        // Postpone-to date if UCRM provides it (varies by version)
        $postponedTo = '';
        foreach (['servicePostponedTo', 'postponedTo', 'suspendedTo'] as $k) {
            if (!empty($service[$k])) { $postponedTo = (string)$service[$k]; break; }
        }
        $postponedToFmt = '';
        if ($postponedTo) {
            $ts = strtotime($postponedTo);
            if ($ts) $postponedToFmt = date('j M Y', $ts);
        }

        // ── 1. Unpause devices via StarlinkBlockService::restore() ──────
        // restore() is idempotent — no-op if no sl_suspension_state row.
        try {
            $blockSvc = new StarlinkBlockService($store->getPdo(), $store, $config, $dataDir, $notify);
            $restoreResult = $blockSvc->restore((int)$clientId, 'service.postpone');
            whLog($changeType, sprintf(
                'Starlink restore on postpone: ok=%s routers=%d failed=%d',
                !empty($restoreResult['ok']) ? 'yes' : 'no',
                $restoreResult['routers_restored'] ?? 0,
                $restoreResult['routers_failed'] ?? 0
            ));
        } catch (\Throwable $e) {
            whLog($changeType, 'Starlink restore on postpone crashed: ' . $e->getMessage());
        }

        // ── v4.21.29: Bridge auto-restore on postpone (catches Block-Manager-paused customers) ──
        try {
            if (!class_exists('StarlinkBlockBridge')) {
                @require_once __DIR__ . '/lib/StarlinkBlockBridge.php';
            }
            if (class_exists('StarlinkBlockBridge')) {
                $bridge = new StarlinkBlockBridge($store->getPdo(), $store, $config, $dataDir, $notify);
                $bridgeResult = $bridge->restoreClient((int)$clientId, 'webhook:service.postpone');
                if (($bridgeResult['routers_restored'] ?? 0) > 0) {
                    whLog($changeType, sprintf(
                        'Starlink bridge restore on postpone: routers=%d failed=%d',
                        $bridgeResult['routers_restored'],
                        $bridgeResult['routers_failed'] ?? 0
                    ));
                }
            }
        } catch (\Throwable $e) {
            whLog($changeType, 'Starlink bridge postpone restore crashed: ' . $e->getMessage());
        }

        // ── 2. Write postpone marker (suppresses any near-simultaneous
        //       service.unsuspend WA from sending the wrong message) ──
        try {
            @file_put_contents(
                $dataDir . '/recent_postpone_' . $clientId . '.marker',
                json_encode([
                    'ts'           => time(),
                    'svc_name'     => $svcName,
                    'postponed_to' => $postponedTo,
                    'outstanding'  => $outstanding,
                ])
            );
        } catch (\Throwable $_) {}

        // ── 3. Send "Service Temporarily Restored" WA ──────────────────
        if ($phone) {
            $deadlineLine = $postponedToFmt
                ? "Please settle by *{$postponedToFmt}* to avoid another suspension.\n\n"
                : "Please settle the outstanding balance to avoid another suspension.\n\n";
            $balanceLine = $outstanding > 0
                ? "Outstanding balance: *\${$outstandingFmt}*\n\n"
                : "";

            $notify->sendVia('accounts', $phone,
                "⏰ *Service Temporarily Restored — DishNet Africa*\n\n"
                . "Dear {$name},\n\n"
                . "Your DishNet *{$svcName}* service has been *temporarily restored*. "
                . "You're back online — but please note this is a courtesy extension.\n\n"
                . $balanceLine
                . "*Your bill is still pending.*\n\n"
                . $deadlineLine
                . "To pay now:\n"
                . "🔗 https://dishnetafrica.com/tutorials/index.html\n\n"
                . "📞 +211 921 443 009\n"
                . "— DishNet Accounts",
                'ops_service_postponed');
            whLog($changeType, "Postpone notice sent to {$name}");

            // FCM push so customer sees it in the app too
            try {
                fcm_push_service_activated($store->getPdo(), $config, (int)$clientId, $svcName);
            } catch (\Throwable $e) { /* silent */ }
        }

        whResp(200, 'service.postpone processed.');
    }

    // -- Service ended (churn) ---------------------------------------------
    case 'service.end':
    case 'SERVICE_END': {
        $serviceId = $entityId ?: (int)($entity['id'] ?? 0);
        $service   = $crm->get("clients/services/{$serviceId}") ?? $entity;
        $clientId  = (int)($service['clientId'] ?? 0);
        $svcName   = $service['name'] ?? 'Internet Service';

        if (!$clientId) whResp(200, 'No clientId — skipped.');

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        if ($phone) {
            $notify->serviceEnded($phone, $name, $svcName);
            whLog($changeType, "Churn recovery sent to {$name} ({$svcName})");
        }

        whResp(200, 'service.end processed.');
    }

    // ── Client details edited in UCRM (username, phone, email change) ────
    case 'client.edit':
    case 'CLIENT_EDIT': {
        $clientId = $entityId ?: (int)($entity['id'] ?? 0);
        if (!$clientId) { whLog($changeType, 'No clientId — skipped'); whResp(200, 'skipped'); }

        // v4.21.6: refresh ucrm_clients_cache.json for this one client so
        // any tag changes (notably NO_AUTO_BLOCK for VIP guard) are visible
        // immediately, instead of waiting for the daily 03:00 auto-pull.
        // We fetch first and reuse the result for both cache update and
        // the kyc_applications sync below.
        $client = $crm->get("clients/{$clientId}") ?? [];
        if (!empty($client) && (int)($client['id'] ?? 0) === $clientId) {
            try {
                $cache = $store->load('ucrm_clients_cache.json') ?? [];
                $found = false;
                foreach ($cache as $i => $c) {
                    if ((int)($c['id'] ?? 0) === $clientId) {
                        $cache[$i] = $client;
                        $found = true;
                        break;
                    }
                }
                if (!$found) $cache[] = $client; // newly-pulled, add it
                $store->save('ucrm_clients_cache.json', $cache);
                whLog($changeType, "ucrm_clients_cache refreshed for CRM #{$clientId}");
            } catch (\Throwable $e) {
                whLog($changeType, 'cache refresh failed: ' . $e->getMessage());
                // non-fatal — cache is best-effort, isVipClient still has
                // explicit-list backstop and webhook passes fresh client object
            }
        }

        // Find matching local application by crm_client_id
        $apps = $store->load('kyc_applications.json') ?? [];
        $matchIdx = null;
        foreach ($apps as $i => $a) {
            if ((string)($a['crm_client_id'] ?? '') === (string)$clientId) {
                $matchIdx = $i; break;
            }
        }

        if ($matchIdx === null) {
            whLog($changeType, "No local application for CRM #{$clientId} — sync skipped (cache was still refreshed)");
            whResp(200, 'No local record for this client (cache refreshed).');
        }

        // (Re-use $client fetched above for the kyc_applications sync below)
        $newFirst = trim($client['firstName'] ?? '');
        $newLast  = trim($client['lastName']  ?? '');
        $newUser  = trim($client['username']  ?? '');
        $newAddr  = trim($client['street1']   ?? '');

        // Extract phone + email from contacts array
        $newPhone = ''; $newEmail = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])  && $newPhone === '') $newPhone = $c['phone'];
            if (!empty($c['email'])  && $newEmail === '') $newEmail = $c['email'];
        }

        // Build diff — only update fields that actually changed
        $app     = $apps[$matchIdx];
        $updates = [];
        $changes = [];

        if ($newFirst && $newFirst !== ($app['firstname'] ?? '')) { $updates['firstname'] = $newFirst; $changes[] = "First name → {$newFirst}"; }
        if ($newLast  && $newLast  !== ($app['lastname']  ?? '')) { $updates['lastname']  = $newLast;  $changes[] = "Last name → {$newLast}"; }
        if ($newUser  && $newUser  !== ($app['username']  ?? '')) { $updates['username']  = $newUser;  $changes[] = "Username → {$newUser}"; }
        if ($newPhone && $newPhone !== ($app['mobile']    ?? '')) { $updates['mobile']    = $newPhone; $changes[] = "Phone → {$newPhone}"; }
        if ($newEmail && $newEmail !== ($app['email']     ?? '')) { $updates['email']     = $newEmail; $changes[] = "Email → {$newEmail}"; }
        if ($newAddr  && $newAddr  !== ($app['address_1'] ?? '')) { $updates['address_1'] = $newAddr;  $changes[] = "Address → {$newAddr}"; }

        // ── Lead → Regular conversion detection ──────────────────────────
        $wasLead    = !empty($app['is_lead']);
        $isNowLead  = $client['isLead'] ?? true;  // UCRM field
        $converted  = $wasLead && !$isNowLead;

        if ($converted) {
            $updates['is_lead']              = false;
            $updates['crm_client_type']      = 'regular';
            $updates['converted_at']         = date('Y-m-d H:i:s');
            $updates['converted_by']         = 'UCRM (webhook)';
            $changes[] = "Lead → Regular Customer";

            whLog($changeType, "🔄 LEAD CONVERTED: CRM #{$clientId} ({$newFirst} {$newLast}) is now a regular customer");

            // Notify the sales agent who registered this customer
            $agentId   = (int)($app['retailer_id'] ?? 0);
            $retailers = $store->load('retailers.json') ?? [];
            $agent     = null;
            foreach ($retailers as $r) {
                if ((int)($r['id'] ?? 0) === $agentId) { $agent = $r; break; }
            }
            $custName = trim("{$newFirst} {$newLast}") ?: ($app['firstname'] ?? 'Customer');
            if ($agent && !empty($agent['phone'])) {
                $msg = "✅ *Lead Converted!*\n\n"
                     . "Hi {$agent['name']},\n\n"
                     . "Your customer *{$custName}* (CRM #{$clientId}) has been converted from Lead to Regular Customer by Accounts.\n\n"
                     . "Service activation will follow.\n\n"
                     . "— _DishNet Africa_";
                $notify->sendVia('support', $agent['phone'], $msg, 'ops_lead_converted', [
                    'agent_name' => $agent['name'], 'customer_name' => $custName, 'crm_id' => $clientId,
                ]);
                whLog($changeType, "Agent {$agent['name']} notified of lead conversion for CRM #{$clientId}");
            }

            // Notify the customer
            $custPhone = $newPhone ?: ($app['mobile'] ?? '');
            if ($custPhone) {
                $msg = "🎉 *Welcome to DishNet!*\n\n"
                     . "Hi {$custName},\n\n"
                     . "Your account has been activated. Our team will set up your service shortly.\n\n"
                     . "For support: +211 927 797 217\n\n"
                     . "— _DishNet Africa_";
                $notify->sendVia('support', $custPhone, $msg, 'ops_customer_activated', [
                    'customer_name' => $custName, 'crm_id' => $clientId,
                ]);
                whLog($changeType, "Customer {$custName} notified of activation");
            }
        } elseif (!$wasLead && $isNowLead) {
            // Rare: Regular → Lead (downgrade/revert)
            $updates['is_lead']         = true;
            $updates['crm_client_type'] = 'lead';
            $changes[] = "Regular → Lead (reverted)";
        } else {
            // No conversion — just update crm_client_type to match current state
            $updates['crm_client_type'] = $isNowLead ? 'lead' : 'regular';
        }

        if (empty($updates)) {
            whLog($changeType, "CRM #{$clientId} — no trackable field changes detected");
            whResp(200, 'No relevant changes.');
        }

        $updates['crm_last_sync']        = date('Y-m-d H:i:s');
        $updates['crm_sync_source']      = 'webhook_client_edit';
        $updates['detail_update_history'] = array_merge(
            $app['detail_update_history'] ?? [],
            [['by' => 'UCRM (webhook)', 'at' => date('Y-m-d H:i:s'), 'changes' => implode(', ', $changes)]]
        );

        $store->updateOne('kyc_applications.json', 'crm_client_id', (string)$clientId, $updates);

        $summary = implode(' | ', $changes);
        whLog($changeType, "CRM #{$clientId} synced to plugin: {$summary}", ['changes' => $changes]);
        whResp(200, "client.edit synced: {$summary}");
    }

    
    case 'quote.approve':
    case 'QUOTE_APPROVE': {
        $quoteId  = $entityId ?: (int)($entity['id'] ?? 0);
        $quote    = $crm->get("billing/quotes/{$quoteId}") ?? $entity;
        $clientId = (int)($quote['clientId'] ?? 0);
        $total    = (float)($quote['total'] ?? 0);
        $totalFmt = number_format($total, 2);
        $num      = $quote['number'] ?? (string)$quoteId;

        if (!$clientId) whResp(200, 'No clientId — skipped.');

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        if ($phone) {
            $msg = "✅ *Quote Approved — DishNet Africa*\n\n"
                 . "Dear {$name},\n\n"
                 . "Thank you for approving Quote *#{$num}*!\n\n"
                 . "💰 Amount: \${$totalFmt}\n\n"
                 . "Our team will contact you shortly to schedule your installation.\n\n"
                 . "📞 +211 921 443 006\n"
                 . "— DishNet Africa";
            $notify->sendVia('support', $phone, $msg, 'ops_quote_approved', [
                'customer_name' => $name, 'quote_num' => $num, 'amount' => $totalFmt,
            ]);
            whLog($changeType, "Quote approved notification → {$name} (#{$num})");
        }

        whResp(200, 'quote.approve processed.');
    }

    // ── Job assigned to technician ───────────────────────────────────────────
    case 'job.add':
    case 'JOB_ADD': {
        $jobId = $entityId ?: (int)($entity['id'] ?? 0);
        
        // Get full job details from UCRM
        $job = $crm->get("scheduling/jobs/{$jobId}") ?? $entity;
        
        $title       = $job['title'] ?? "Job #{$jobId}";
        $address     = $job['address'] ?? '';
        $date        = $job['date'] ?? '';
        $timeFrom    = $job['timeFrom'] ?? '';
        $timeTo      = $job['timeTo'] ?? '';
        $status      = $job['status'] ?? '';
        $clientId    = (int)($job['clientId'] ?? 0);
        $assignedUserId = (int)($job['assignedUserId'] ?? 0);
        
        // Format date/time nicely
        $dateFormatted = $date ? date('D, M j Y', strtotime($date)) : 'TBD';
        $timeFormatted = '';
        if ($timeFrom) {
            $timeFormatted = date('g:i A', strtotime($timeFrom));
            if ($timeTo) {
                $timeFormatted .= ' - ' . date('g:i A', strtotime($timeTo));
            }
        }
        
        // Get client name if available
        $clientName = 'N/A';
        if ($clientId) {
            $client = $crm->get("clients/{$clientId}") ?? [];
            $clientName = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
            if (empty($clientName)) {
                $clientName = $client['companyName'] ?? "Client #{$clientId}";
            }
        }
        
        // Get assigned technician details
        if ($assignedUserId) {
            $user = $crm->get("users/{$assignedUserId}") ?? [];
            $techName  = trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? '')) ?: 'Technician';
            $techEmail = $user['email'] ?? '';
            $techPhone = $user['phone'] ?? '';
            
            // If no phone on user record, try to find in staff/retailers table
            if (empty($techPhone)) {
                // Try to find matching retailer by email
                $sql = "SELECT phone FROM retailers WHERE email = ? LIMIT 1";
                try {
                    $stmt = $store->getPdo()->prepare($sql);
                    $stmt->execute([$techEmail]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if ($row && !empty($row['phone'])) {
                        $techPhone = $row['phone'];
                    }
                } catch (\Throwable $e) {
                    // Ignore - just won't have phone
                }
            }
            
            if ($techPhone) {
                $message = "🔧 *New Job Assigned*\n\n"
                    . "Hi {$techName},\n\n"
                    . "A new job has been assigned to you:\n\n"
                    . "📋 *{$title}*\n"
                    . "👤 Customer: {$clientName}\n"
                    . "📍 Address: " . ($address ?: 'See job details') . "\n"
                    . "📅 Date: {$dateFormatted}\n"
                    . ($timeFormatted ? "⏰ Time: {$timeFormatted}\n" : "")
                    . "\n🔗 View in CRM: crm.dishnetafrica.com\n\n"
                    . "— DishNet Support";
                
                $notify->sendRaw($techPhone, $message, 'job_assigned');
                whLog($changeType, "Job #{$jobId} notification sent to {$techName}", [
                    'tech_phone' => $techPhone,
                    'client' => $clientName,
                    'date' => $dateFormatted,
                ]);
            } else {
                whLog($changeType, "Job #{$jobId} — No phone found for {$techName}", [
                    'tech_email' => $techEmail,
                    'assigned_user_id' => $assignedUserId,
                ]);
            }
        } else {
            whLog($changeType, "Job #{$jobId} — No user assigned", ['job_title' => $title]);
        }
        
        whResp(200, 'job.add processed.');
    }

    // ── Ticket created ────────────────────────────────────────────────────
    case 'ticket.add':
    case 'TICKET_ADD': {
        $ticketId = $entityId ?: (int)($entity['id'] ?? 0);
        $subject  = $entity['subject'] ?? $entity['title'] ?? "Ticket #{$ticketId}";
        $clientId = (int)($entity['clientId'] ?? 0);

        // Notify admin only (agent already knows — they raised it)
        $notify->sendAdmin(
            "🎫 *New Support Ticket #{$ticketId}*\n{$subject}" . ($clientId ? "\nClient: #{$clientId}" : ''),
            'event_ticket_add',
            ['ticket_id' => (string)$ticketId, 'subject' => $subject]
        );
        whLog($changeType, "Admin notified: ticket #{$ticketId}");
        whResp(200, 'ticket.add processed.');
    }

    // ── Invoice draft approved (THIS IS THE AUTO-INVOICE EVENT) ────────────
    // UCRM auto-invoicing: create draft → approve → fires draft_approved
    // Must send full notification + PDF, same as invoice.add
    case 'draft_approved':
    case 'invoice.draft_approved': {
        $invoiceId = $entityId ?: (int)($entity['id'] ?? 0);
        if (!$invoiceId) {
            whLog($changeType, 'No invoice ID — skipped');
            whResp(200, 'draft_approved — no invoice ID.');
        }

        $invoice = $crm->get("invoices/{$invoiceId}") ?? $crm->get("billing/invoices/{$invoiceId}") ?? $entity;

        $clientId = (int)($invoice['clientId'] ?? 0);
        $amount   = (float)($invoice['amountToPay'] ?? $invoice['total'] ?? $invoice['amount'] ?? 0);
        $invoNum  = (string)($invoice['number'] ?? $invoice['invoiceNumber'] ?? '');
        $dueDate  = substr($invoice['maturityDate'] ?? $invoice['dueDate'] ?? '', 0, 10);

        if (!$clientId || $amount <= 0) {
            whLog($changeType, "No client or zero amount — skipped", ['clientId' => $clientId, 'amount' => $amount]);
            whResp(200, 'draft_approved — no client/amount.');
        }

        // Skip if still draft or paid/voided (race condition safety)
        $draftApprStatus = (int)($invoice['status'] ?? -1);
        if (!in_array($draftApprStatus, [1, 2], true)) {
            whLog($changeType, "Invoice status={$draftApprStatus} not unpaid/partial — skipped", ['invoice_id' => $invoiceId]);
            whResp(200, "draft_approved — status {$draftApprStatus} not actionable.");
        }

        // Use invoice number; fall back to ID only for approved invoices
        if (empty($invoNum)) $invoNum = (string)$invoiceId;

        // ── Atomic dedup: SQLite INSERT OR IGNORE — race-safe with invoice.add + cron
        $invLogKey = "INV{$invoNum}";
        if (!$notify->dedupMark($invLogKey)) {
            whLog($changeType, "Already notified: #{$invoNum} — skipping");
            whResp(200, 'draft_approved — already notified.');
        }

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        // Extract service description from invoice line items (same as invoice.add)
        $invItems = $invoice['items'] ?? [];
        $itemLabels = [];
        foreach ($invItems as $item) {
            $raw = trim($item['label'] ?? $item['name'] ?? $item['description'] ?? '');
            if (!$raw) continue;
            if (preg_match('/Services?\s+Plan\s+(.+?)\s*:?\s*(?:Duration|Period)\s+(.+)$/ui', $raw, $m)) {
                $itemLabels[] = trim($m[1]) . ' · ' . trim($m[2]);
            } elseif (preg_match('/Services?\s+Plan\s+(.+?)$/ui', $raw, $m)) {
                $itemLabels[] = trim($m[1]);
            } else {
                $itemLabels[] = $raw;
            }
        }
        $itemLabels = array_values(array_unique($itemLabels));
        $totalItems = count($itemLabels);
        if ($totalItems === 0) {
            $serviceName = '';
        } elseif ($totalItems <= 3) {
            $serviceName = implode(', ', $itemLabels);
        } else {
            $serviceName = implode(', ', array_slice($itemLabels, 0, 2)) . ' + ' . ($totalItems - 2) . ' more';
        }

        // Determine credit scenario
        $creditData = whInvoiceCreditScenario($invoice, $client);

        whLog($changeType, "draft_approved notification check", [
            'invoice_num' => $invoNum, 'customer'    => $name,
            'amount'      => $amount,  'amount_paid' => $creditData['amount_paid'],
            'remaining'   => $creditData['remaining'], 'scenario' => $creditData['scenario'],
        ]);

        if ($phone) {
            whSendInvoiceNotification($notify, $crm, $phone, $name,
                $invoiceId, $invoNum, $amount, $dueDate ?: 'See invoice',
                $creditData, $config, $dataDir, $serviceName);
        } else {
            whLog($changeType, "No phone for client #{$clientId}");
        }

        whResp(200, 'draft_approved processed.');
    }

    // ── Quote created — notify customer + send PDF ───────────────────────
    case 'quote.add':
    case 'QUOTE_ADD': {
        $quoteId = $entityId ?: (int)($entity['id'] ?? 0);
        if (!$quoteId) whResp(200, 'quote.add — no quote ID.');

        // Dedup check — cron_quote_wa.php also sends quotes; avoid double-sending
        $qwaState  = $store->load('quote_wa_state.json') ?? [];
        $qwaSentIds = array_map('intval', $qwaState['sent_ids'] ?? []);
        if (in_array($quoteId, $qwaSentIds, true)) {
            whLog($changeType, "Quote #{$quoteId} already sent by cron_quote_wa — skipping webhook duplicate");
            whResp(200, 'quote.add — already notified by cron.');
        }

        $quote    = $crm->get("billing/quotes/{$quoteId}") ?? $entity;
        $clientId = (int)($quote['clientId'] ?? 0);
        // Sum line items for accurate total. UCRM totalPrice returns only the recurring/service price
        // (e.g. $112 for Priority plan), not the full quote including hardware. Summing items = correct total.
        $itemsTotal = 0.0;
        foreach ($quote['items'] ?? [] as $_qi) {
            $itemsTotal += (float)($_qi['total'] ?? (((float)($_qi['quantity'] ?? 1)) * ((float)($_qi['price'] ?? 0))));
        }
        $amount    = $itemsTotal > 0 ? $itemsTotal : (float)($quote['total'] ?? $quote['totalUntaxed'] ?? $quote['totalPrice'] ?? 0);
        $amountFmt = number_format($amount, 2);
        $quoteNum  = (string)($quote['number'] ?? $quoteId);

        // Send notification for ALL quotes (draft + open) — customer reviews draft before paying
        $quoteStatus = (int)($quote['status'] ?? 1);
        whLog($changeType, "Quote #{$quoteId} status={$quoteStatus} — proceeding with notification");

        if (!$clientId) {
            whLog($changeType, "No clientId on quote — skipped");
            whResp(200, 'quote.add — no client.');
        }

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        if ($phone && $amount > 0) {
            // ── KYC quote check: let cron_quote_wa handle ALL plugin-generated quotes ──
            // Why: KycService sends the welcome message ("Request Confirmed!") AFTER
            // creating the quote in CRM. UCRM fires quote.add webhook immediately,
            // which races the welcome message. cron_quote_wa waits 3 minutes, ensuring:
            //   1) Welcome message arrives first
            //   2) UCRM has generated the PDF
            //   3) Customer gets text + PDF together
            $kycApp = $store->findOne('kyc_applications.json', 'crm_client_id', (string)$clientId);
            if ($kycApp) {
                $isCashPaid = strtolower(trim($kycApp['sales_type'] ?? '')) === 'cash'
                           && (float)($kycApp['amount_charged'] ?? 0) > 0;

                if ($isCashPaid) {
                    // v4.11.3 FIX: Cash sales now get quote WA too
                    // Customer needs quote PDF as formal price agreement
                    // Delivery note is separate; both should be sent
                    whLog($changeType, "Quote #" . $quoteNum . " cash sale -- sending quote WA via cron (client #" . $clientId . ")");
                }

                // Credit/other KYC sale — defer to cron_quote_wa for proper sequencing
                // (welcome first, then quote text + PDF together after 3 min)
                $store->updateOne('kyc_applications.json', 'id', (int)$kycApp['id'], [
                    'quote_id'             => $quoteId,
                    'quote_ref'            => $quoteNum,
                    'quote_created'        => true,
                    'wa_quote_pending'     => true,
                    'wa_quote_phone'       => $phone,
                    'wa_quote_deferred_at' => date('Y-m-d H:i:s'),
                ]);
                whLog($changeType, "Quote #{$quoteNum} DEFERRED to cron_quote_wa — KYC app #{$kycApp['id']} (client #{$clientId})");
                whResp(200, 'quote.add — KYC quote, deferred to cron for proper sequencing.');
            }

            // ── Build rich quote message with item breakdown ──
            $items = $quote['items'] ?? [];

            // Detect service type from items
            $isStarlink = false;
            $isFiber = false;
            $kitName = '';
            $planName = '';
            $hwTotal = 0.0;
            $monthlyTotal = 0.0;

            foreach ($items as $_qi) {
                $lbl = $_qi['label'] ?? '';
                $lblLower = strtolower($lbl);
                $iTotal = (float)($_qi['total'] ?? (((float)($_qi['quantity'] ?? 1)) * ((float)($_qi['price'] ?? 0))));

                if (strpos($lblLower, 'starlink') !== false || strpos($lblLower, 'kit') !== false) $isStarlink = true;
                if (strpos($lblLower, 'fiber') !== false || strpos($lblLower, 'ftth') !== false || strpos($lblLower, 'optical') !== false) $isFiber = true;

                // Detect kit vs plan
                if (preg_match('/kit|hardware|device|router|ont|onu|cable|nanostation|mikrotik|installation/i', $lbl)) {
                    $hwTotal += $iTotal;
                    if (!$kitName && preg_match('/kit/i', $lbl)) $kitName = $lbl;
                } else {
                    $monthlyTotal += $iTotal;
                    if (!$planName) $planName = $lbl;
                }
            }

            // Get client location from address
            $location = '';
            $street = trim($client['street1'] ?? '');
            $city = trim($client['city'] ?? '');
            if ($street && $city) $location = "{$street}, {$city}";
            elseif ($street) $location = $street;
            elseif ($city) $location = $city;

            // Get email
            $email = '';
            foreach (($client['contacts'] ?? []) as $_c) {
                if (!empty($_c['email'])) { $email = $_c['email']; break; }
            }

            // Service type label
            $svcType = $isStarlink ? 'Starlink' : ($isFiber ? 'Fiber' : 'Internet');

            // Build message
            $msg = "📄 *Quotation & Order Summary*\n\n"
                 . "Dear {$name},\n\n"
                 . "Thank you for choosing DishNet! 🌐\n\n"
                 . "🆔 *Order:* {$quoteNum}\n";

            if ($location) {
                $msg .= "📍 *Location:* {$location}\n";
            }

            $msg .= "\n🛰️ *{$svcType} Package*\n";

            // List items
            foreach ($items as $_qi) {
                $lbl = $_qi['label'] ?? '';
                if (!$lbl) continue;
                $qty = (int)($_qi['quantity'] ?? 1);
                $iPrice = (float)($_qi['price'] ?? 0);
                $iTotal = (float)($_qi['total'] ?? ($qty * $iPrice));

                if ($qty > 1) {
                    $msg .= "📦 {$lbl} x{$qty} — USD " . number_format($iTotal, 0) . "\n";
                } else {
                    $msg .= "📦 {$lbl} — USD " . number_format($iTotal, 0) . "\n";
                }
            }

            if ($hwTotal > 0 && $monthlyTotal > 0) {
                $msg .= "💰 Hardware: USD " . number_format($hwTotal, 0) . "\n";
                $msg .= "💰 Monthly: USD " . number_format($monthlyTotal, 0) . "\n";
            }

            $msg .= "🏷️ *Total: USD " . number_format($amount, 0) . "*\n\n"
                 . "💳 Cash / Transfer / Card\n"
                 . "✅ Reply *YES* to proceed.\n\n";

            // Contact info
            $msg .= "📞 +211 921 443 009 | 🛒 0923 400 000";
            if ($email) $msg .= "\n📧 {$email}";
            $msg .= "\n\n🚀 *DishNet Internet Services*";
            // Use full rich message as caption (WhatsApp Web supports ~4096 chars)
            $shortCaption = $msg;

            // Keep full message for text-only fallback
            $fullMsg = $msg;

            whLog($changeType, "Quote #{$quoteNum} → preparing PDF+caption for {$name} ({$phone})", ['amount' => $amountFmt]);

            // NOTE: Do NOT mark sent_ids here — only mark AFTER successful send.
            // If webhook fails, cron_quote_wa picks it up as a retry.

            // ── Auto-approve draft so UCRM generates PDF ──
            if ($quoteStatus === 0) {
                try {
                    $crm->patch("billing/quotes/{$quoteId}", ['status' => 1]);
                    whLog($changeType, "Auto-approved draft #{$quoteNum} → Open");
                } catch (\Throwable $apErr) {
                    whLog($changeType, "Auto-approve note: " . $apErr->getMessage());
                }
            }

            // Flush 200 to UCRM, then send PDF+caption in background
            ignore_user_abort(true);
            http_response_code(200);
            header('Content-Type: application/json');
            header('Connection: close');
            $respBody = json_encode(['status' => 'ok', 'message' => 'quote.add processed.']);
            header('Content-Length: ' . strlen($respBody));
            echo $respBody;
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                ob_end_flush();
                flush();
            }

            // Background: fetch PDF from UCRM
            whLog($changeType, "Waiting 5s for UCRM PDF generation...");
            sleep(5);

            $pdfSent = false;

            // Try multiple PDF fetch approaches
            $apiBase = $crm->getBaseUrl();
            $appKey  = $crm->getAppKey();
            $authHdr = $crm->getAuthHeader() . ': ' . $appKey;

            // Correct UCRM API path: /quotes/{id}/pdf (NOT /billing/quotes/)
            $pdfPaths = [
                $apiBase . "/quotes/{$quoteId}/pdf",
            ];

            for ($retry = 1; $retry <= 3; $retry++) {
                try {
                    $pdfRaw = null;
                    foreach ($pdfPaths as $pIdx => $pdfUrl) {
                        $dch = curl_init($pdfUrl);
                        curl_setopt_array($dch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_HTTPHEADER     => [$authHdr],
                            CURLOPT_TIMEOUT        => 30,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_SSL_VERIFYPEER => false,
                        ]);
                        $dResp = curl_exec($dch);
                        $dCode = (int)curl_getinfo($dch, CURLINFO_HTTP_CODE);
                        $dLen  = $dResp !== false ? strlen($dResp) : 0;
                        $dType = curl_getinfo($dch, CURLINFO_CONTENT_TYPE) ?: '';
                        curl_close($dch);
                        whLog($changeType, "PDF fetch attempt {$retry}: HTTP={$dCode} len={$dLen}");

                        if ($dCode === 200 && $dLen > 100) {
                            // Check if it's actually a PDF (starts with %PDF or is base64)
                            $pdfRaw = base64_encode($dResp);
                            whLog($changeType, "PDF fetched: {$dLen} bytes");
                            break;
                        }
                    }
                    if ($pdfRaw) {
                        $pdfDir = $dataDir . '/quote_pdfs';
                        if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

                        $pdfFile  = "DishNet-Quote-" . preg_replace('/[^A-Za-z0-9\-]/', '', $quoteNum) . '.pdf';
                        $pdfPath  = $pdfDir . '/' . $pdfFile;
                        $secret   = ($config['webhook_secret'] ?? 'dishnet');
                        $pdfToken = hash_hmac('sha256', $pdfFile . date('Ymd'), $secret);
                        file_put_contents($pdfPath, base64_decode($pdfRaw));
                        file_put_contents($pdfPath . '.meta', json_encode([
                            'token' => $pdfToken, 'created' => time(), 'quote' => $quoteNum,
                            'filename' => "Quote-{$quoteNum}.pdf",
                        ]));

                        // Build public URL for WASender to fetch
                        // crm_base_url may include /api/v2.1 - strip it to get site root
                        $siteUrl = rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/');
                        $siteUrl = preg_replace('#/api/v[0-9.]+$#', '', $siteUrl);   // strip /api/v2.1
                        $siteUrl = preg_replace('#/crm$#', '', $siteUrl);             // strip trailing /crm
                        $pdfServeUrl = $siteUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php'
                                 . '?page=api&action=serve_quote_pdf'
                                 . '&file=' . urlencode($pdfFile)
                                 . '&token=' . urlencode($pdfToken);

                        whLog($changeType, "PDF serve URL: {$pdfServeUrl}");
                        // 1. Send rich text message first
                        $notify->sendVia('support', $phone, $fullMsg, 'ops_quote_created', [
                            'customer_name' => $name, 'quote_num' => $quoteNum, 'amount' => $amountFmt,
                        ]);

                        // 2. Then send PDF with short caption
                        $pdfCaption = "Quote #{$quoteNum} — USD " . number_format($amount, 0) . "\n— DishNet Africa";
                        $notify->sendDocument('support', $phone, $pdfServeUrl, "DishNet-Quote-{$quoteNum}.pdf",
                            $pdfCaption,
                            'ops_quote_pdf');
                        whLog($changeType, "Quote TEXT + PDF SENT: #{$quoteNum} → {$name} (attempt {$retry})");
                        $pdfSent = true;

                        // Mark as sent NOW — after confirmed delivery
                        $qwaSentIds[] = $quoteId;
                        $qwaState['sent_ids'] = array_values(array_unique($qwaSentIds));
                        $store->save('quote_wa_state.json', $qwaState);

                        break;
                    } else {
                        whLog($changeType, "PDF empty attempt {$retry}/3 — waiting 5s...");
                        sleep(5);
                    }
                } catch (\Throwable $e) {
                    whLog($changeType, "PDF fetch error attempt {$retry}: " . $e->getMessage());
                    sleep(5);
                }
            }

            if (!$pdfSent) {
                // Fallback: send text-only message if PDF couldn't be fetched
                whLog($changeType, "PDF failed — sending text-only message as fallback");
                $notify->sendVia('support', $phone, $fullMsg, 'ops_quote_created', [
                    'customer_name' => $name, 'quote_num' => $quoteNum, 'amount' => $amountFmt,
                ]);
                whLog($changeType, "Text-only quote sent: #{$quoteNum} → {$name}");

                // Mark as sent after text-only delivery
                $qwaSentIds[] = $quoteId;
                $qwaState['sent_ids'] = array_values(array_unique($qwaSentIds));
                $store->save('quote_wa_state.json', $qwaState);

                // Queue PDF for cron retry — text is sent, but PDF still needed
                $qwaState['pdf_pending'] = $qwaState['pdf_pending'] ?? [];
                $qwaState['pdf_pending'][] = [
                    'quote_id'  => $quoteId,
                    'quote_num' => $quoteNum,
                    'phone'     => $phone,
                    'name'      => $name,
                    'amount'    => $amountFmt,
                    'queued_at' => time(),
                ];
                $store->save('quote_wa_state.json', $qwaState);
            }
            exit;
        } else {
            if ($phone && $amount <= 0) {
                // $0 quote — notify ops team (plan may be misconfigured)
                whLog($changeType, "Quote #{$quoteNum} has \$0 amount — plan may not have pricing", ['client_id' => $clientId, 'client' => $name]);
                $notify->sendAdmin(
                    "⚠️ *Quote #{$quoteNum}* created with \$0 for {$name} ({$phone}).\n"
                    . "No quotation sent to customer — plan may be missing pricing.\n"
                    . "Please check and send manually if needed.",
                    'ops_quote_zero'
                );
            } else {
                whLog($changeType, "Skipped — no phone or zero amount", ['client_id' => $clientId]);
            }
        }

        whResp(200, 'quote.add processed.');
    }

    // ── Credit note issued ───────────────────────────────────────────────
    case 'credit_note.add':
    case 'CREDIT_NOTE_ADD': {
        $creditNoteId = $entityId ?: (int)($entity['id'] ?? 0);
        if (!$creditNoteId) whResp(200, 'credit_note.add — no ID.');

        $creditNote = $crm->get("billing/credit-notes/{$creditNoteId}") ?? $entity;
        $clientId   = (int)($creditNote['clientId'] ?? 0);
        $amount     = (float)($creditNote['total'] ?? $creditNote['amount'] ?? 0);
        $cnNum      = (string)($creditNote['number'] ?? $creditNoteId);

        if ($clientId && $amount > 0) {
            $client = $crm->get("clients/{$clientId}") ?? [];
            $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
            $phone  = '';
            foreach (($client['contacts'] ?? []) as $c) {
                if (!empty($c['phone'])) { $phone = $c['phone']; break; }
            }

            if ($phone) {
                $msg = "💳 *Credit Note Issued*\n\n"
                     . "Hi {$name},\n\n"
                     . "A credit of *\${$amount}* (#{$cnNum}) has been applied to your account.\n\n"
                     . "This will be offset against your next invoice.\n\n"
                     . "— _DishNet Africa_";
                $notify->sendVia('accounts', $phone, $msg, 'ops_credit_note', [
                    'customer_name' => $name, 'cn_num' => $cnNum, 'amount' => $amount,
                ]);
                whLog($changeType, "Credit note notification SENT: #{$cnNum} → {$name} ({$phone})");
            }
        }

        whResp(200, 'credit_note.add processed.');
    }

    // ── Client message (staff sends message from CRM → forward to WA) ───
    case 'client.message':
    case 'CLIENT_MESSAGE': {
        $clientId = $entityId ?: (int)($entity['clientId'] ?? $entity['id'] ?? 0);
        if (!$clientId) whResp(200, 'client.message — no client ID.');

        $messageText = $entity['message'] ?? $entity['body'] ?? $entity['text'] ?? '';
        if (empty($messageText)) {
            whLog($changeType, "Empty message body — skipped");
            whResp(200, 'client.message — empty body.');
        }

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        if ($phone) {
            $msg = "📩 *Message from DishNet*\n\n"
                 . "Hi {$name},\n\n"
                 . $messageText . "\n\n"
                 . "— _DishNet Africa_";
            $notify->sendVia('support', $phone, $msg, 'ops_client_message', [
                'customer_name' => $name, 'client_id' => $clientId,
            ]);
            whLog($changeType, "Client message forwarded to WA: {$name} ({$phone})");
        }

        whResp(200, 'client.message processed.');
    }

    // ── Near-due / Overdue — send WhatsApp notification immediately ──────
    case 'near_due':
    case 'invoice.near_due': {
        $invoiceId = (int)$entityId;
        if (!$invoiceId) whResp(200, 'near_due — no invoice ID.');

        $invoice = $crm->get("invoices/{$invoiceId}");
        if (!$invoice) $invoice = $crm->get("billing/invoices/{$invoiceId}");
        if (!$invoice) {
            whLog($changeType, "near_due — invoice #{$invoiceId} not found in CRM");
            whResp(200, 'near_due — invoice not found in CRM.');
        }

        $clientId   = (int)($invoice['clientId'] ?? 0);
        $invoiceNum = (string)($invoice['number'] ?? $invoiceId);
        $total      = (float)($invoice['total'] ?? 0);
        $amountPaid = (float)($invoice['amountPaid'] ?? 0);
        $dueDate    = substr($invoice['dueDate'] ?? $invoice['maturityDate'] ?? '', 0, 10);
        $currency   = $invoice['currencyCode'] ?? 'USD';
        $invStatus  = (int)($invoice['status'] ?? 0);

        if (!$clientId || $total <= 0) whResp(200, 'near_due — skipped (no client or zero amount).');

        // Skip if invoice is already paid
        $balance = $total - $amountPaid;
        if ($invStatus === 3 || $balance <= 0.01) {
            whLog($changeType, "near_due SKIPPED — invoice #{$invoiceNum} already paid (\${$amountPaid}/\${$total})");
            whResp(200, 'near_due — invoice already paid, skipping.');
        }

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        if (!$phone) whResp(200, 'near_due — no phone for client.');

        // Atomic dedup: SQLite INSERT OR IGNORE — one per invoice per day
        $dedupKey = "NEARDUE_{$invoiceId}_" . date('Y-m-d');
        if (!$notify->dedupMark($dedupKey)) {
            whLog($changeType, "near_due SKIPPED — already sent today for #{$invoiceNum}");
            whResp(200, 'near_due — already sent today.');
        }

        // Calculate days until due
        $daysUntil = 0;
        if ($dueDate) {
            $now = new \DateTime('today', new \DateTimeZone('Africa/Juba'));
            $due = new \DateTime($dueDate, new \DateTimeZone('Africa/Juba'));
            $daysUntil = (int)$now->diff($due)->format('%r%a');
        }

        // Pick the right reminder based on days until due
        $svcName = '';
        if ($daysUntil >= 6) {
            $notify->invoiceDue7Days($phone, $name, $invoiceNum, $total, $currency, $dueDate, $svcName);
        } elseif ($daysUntil >= 2) {
            $notify->invoiceDue3Days($phone, $name, $invoiceNum, $total, $currency, $dueDate, $svcName);
        } else {
            $notify->invoiceDueTomorrow($phone, $name, $invoiceNum, $total, $currency, $dueDate, $svcName);
        }

        whLog($changeType, "Pre-due reminder sent: #{$invoiceNum} \${$total} → {$name} ({$daysUntil} days)");
        whResp(200, 'near_due — notification sent.');
    }

    case 'overdue':
    case 'invoice.overdue': {
        $invoiceId = (int)$entityId;
        if (!$invoiceId) whResp(200, 'overdue — no invoice ID.');

        $invoice = $crm->get("invoices/{$invoiceId}");
        if (!$invoice) $invoice = $crm->get("billing/invoices/{$invoiceId}");
        if (!$invoice) {
            whLog($changeType, "overdue — invoice #{$invoiceId} not found in CRM");
            whResp(200, 'overdue — invoice not found in CRM.');
        }

        $clientId   = (int)($invoice['clientId'] ?? 0);
        $invoiceNum = (string)($invoice['number'] ?? $invoiceId);
        $total      = (float)($invoice['total'] ?? 0);
        $amountPaid = (float)($invoice['amountPaid'] ?? 0);
        $dueDate    = substr($invoice['dueDate'] ?? $invoice['maturityDate'] ?? '', 0, 10);
        $currency   = $invoice['currencyCode'] ?? 'USD';
        $invStatus  = (int)($invoice['status'] ?? 0); // 0=draft, 1=unpaid, 2=partial, 3=paid, 4=void

        if (!$clientId || $total <= 0) whResp(200, 'overdue — skipped (no client or zero amount).');

        // ── CRITICAL: Skip if invoice is already paid or balance is zero ──────
        // UCRM fires overdue webhooks based on due date, even if payment was
        // already received. This caused false reminders (e.g. Ashish Kareliya #1152).
        $balance = $total - $amountPaid;
        if ($invStatus === 3 || $balance <= 0.01) {
            whLog($changeType, "overdue SKIPPED — invoice #{$invoiceNum} already paid (\${$amountPaid}/\${$total}, status={$invStatus})");
            whResp(200, 'overdue — invoice already paid, skipping notification.');
        }

        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
             ?: ($client['companyName'] ?? 'Customer');
        $phone  = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }

        if (!$phone) whResp(200, 'overdue — no phone for client.');

        // Atomic dedup: SQLite INSERT OR IGNORE — one per invoice per day
        $dedupKey = "OVERDUE_{$invoiceId}_" . date('Y-m-d');
        if (!$notify->dedupMark($dedupKey)) {
            whLog($changeType, "overdue SKIPPED — already sent today for #{$invoiceNum}");
            whResp(200, 'overdue — already sent today.');
        }

        // Calculate how many days overdue
        $daysOverdue = 0;
        if ($dueDate) {
            $now = new \DateTime('today', new \DateTimeZone('Africa/Juba'));
            $due = new \DateTime($dueDate, new \DateTimeZone('Africa/Juba'));
            $daysOverdue = abs((int)$now->diff($due)->format('%a'));
        }

        $svcName = '';
        if ($daysOverdue <= 1) {
            $notify->overdueDay1($phone, $name, $invoiceNum, $total, $currency, $svcName);
        } elseif ($daysOverdue <= 3) {
            $notify->overdueDay3($phone, $name, $invoiceNum, $total, $currency, $svcName);
        } else {
            $notify->overdueDay5($phone, $name, $invoiceNum, $total, $currency, $svcName);
        }

        whLog($changeType, "Overdue notice sent: #{$invoiceNum} \${$total} → {$name} ({$daysOverdue} days overdue)");
        whResp(200, 'overdue — notification sent.');
    }

    // ── Payment deleted in CRM — auto-reverse cashbook + void collection ──
    case 'payment.delete':
    case 'PAYMENT_DELETE': {
        $paymentId = (int)$entityId;
        if ($paymentId <= 0) {
            whLog($changeType, "payment.delete — no entity ID");
            whResp(200, 'payment.delete — no ID.');
        }

        whLog($changeType, "Processing CRM payment deletion #{$paymentId}");

        $reversedCb   = false;
        $voidedCol    = false;
        $originalAmt  = 0.0;
        $originalDesc = '';
        $agentId      = 0;

        // ── 1. Reverse cashbook entry ────────────────────────────────────
        try {
            require_once __DIR__ . '/lib/CashbookService.php';
            $cb = new CashbookService($store, $dataDir);

            // Find the original Cash IN entry by crm_payment_id
            $existing = $cb->query(
                "SELECT id, sr, amount, currency, person, description, direction, project
                 FROM cb_ledger WHERE crm_payment_id = ? AND direction = 'in' LIMIT 1",
                [$paymentId]
            );

            if (!empty($existing)) {
                $orig = $existing[0];
                $originalAmt  = (float)$orig['amount'];
                $originalDesc = $orig['description'] ?? '';

                // Post a Cash OUT reversal
                $cb->addEntryRaw([
                    'project'           => $orig['project'] ?? 'dishnet',
                    'date'              => date('Y-m-d'),
                    'direction'         => 'out',
                    'amount'            => $originalAmt,
                    'currency'          => $orig['currency'] ?? 'USD',
                    'category'          => 'Refund',
                    'category_raw'      => 'CRM Payment Deleted',
                    'person'            => $orig['person'] ?? '',
                    'description'       => 'AUTO-REVERSAL: CRM Payment #' . $paymentId . ' deleted — '
                                         . substr($originalDesc, 0, 80),
                    'validation_ref'    => 'REV-CRM-PAY-' . $paymentId,
                    'validation_status' => 'done',
                    'status'            => 'approved',
                    'approved_by'       => 'System',
                    'crm_payment_id'    => $paymentId,
                    'crm_client_id'     => 0,
                    'source'            => 'crm_webhook_reversal',
                    'created_at'        => date('Y-m-d H:i:s'),
                ]);
                $reversedCb = true;

                // Mark original entry with a note
                $cb->query(
                    "UPDATE cb_ledger SET description = description || ' [REVERSED — CRM deleted]',
                            validation_status = 'na'
                     WHERE id = ?",
                    [(int)$orig['id']]
                );

                whLog($changeType, "Cashbook reversal posted: -\${$originalAmt} (was {$orig['sr']})");
            } else {
                whLog($changeType, "No cashbook entry found for CRM-PAY-{$paymentId} — skip reversal");
            }
        } catch (\Throwable $cbErr) {
            whLog('cashbook_error', "Reversal failed for CRM-PAY-{$paymentId}: " . $cbErr->getMessage());
        }

        // ── 2. Void collection record ────────────────────────────────────
        try {
            $collections = $store->load('payment_collections.json') ?? [];
            $changed = false;
            foreach ($collections as &$col) {
                if ((int)($col['crm_payment_id'] ?? 0) === $paymentId && ($col['status'] ?? '') !== 'voided') {
                    $col['status']    = 'voided';
                    $col['ev']        = 0;  // Exclude from snapshot calculations
                    $col['voided_at'] = date('Y-m-d H:i:s');
                    $col['voided_by'] = 'System (CRM payment deleted)';
                    $col['void_ref']  = 'CRM-DEL-' . $paymentId;
                    $agentId = (int)($col['retailer_id'] ?? $col['agent_id'] ?? 0);
                    $originalAmt = $originalAmt ?: (float)($col['amount'] ?? 0);
                    $voidedCol = true;
                    $changed = true;
                    whLog($changeType, "Collection voided: COL-{$col['id']} agent={$agentId}");
                    break;
                }
            }
            unset($col);
            if ($changed) {
                $store->save('payment_collections.json', $collections);
            }
        } catch (\Throwable $colErr) {
            whLog('collection_error', "Void failed for CRM-PAY-{$paymentId}: " . $colErr->getMessage());
        }

        // ── 2b. Void in staff_ledger (dual-write) ────────────────────────
        try {
            require_once __DIR__ . '/lib/StaffLedgerWriter.php';
            StaffLedgerWriter::onCrmPaymentDeleted($store->getPdo(), $paymentId, 'CRM payment deleted');
        } catch (\Throwable $_slErr) { /* non-fatal */ }

        // ── 3. Rebuild agent snapshot ────────────────────────────────────
        if ($agentId > 0) {
            try {
                require_once __DIR__ . '/lib/SnapshotService.php';
                (new SnapshotService($store->getPdo(), $store))->rebuild($agentId, 'payment_delete', 'CRM-DEL-' . $paymentId);
                whLog($changeType, "Snapshot rebuilt for agent #{$agentId}");
            } catch (\Throwable $snErr) {
                whLog('snapshot_error', "Rebuild failed for agent #{$agentId}: " . $snErr->getMessage());
            }
        }

        // ── 4. WhatsApp alert to accountant ──────────────────────────────
        try {
            if ($reversedCb || $voidedCol) {
                $alertMsg = "⚠️ *CRM Payment Deleted*\n\n"
                    . "Payment #*{$paymentId}* (\${$originalAmt}) was deleted from CRM.\n\n";
                if ($reversedCb) {
                    $alertMsg .= "✅ Cashbook auto-reversal posted\n";
                }
                if ($voidedCol) {
                    $alertMsg .= "✅ Collection record voided\n";
                }
                $alertMsg .= "\nOriginal: " . substr($originalDesc, 0, 60) . "\n"
                    . "Time: " . date('d M Y H:i') . "\n\n"
                    . "Please review in Cashbook → search *REV-CRM-PAY-{$paymentId}*\n"
                    . "— DishNet System";

                // Send to accountant (Rupesh's phone from config or first accountant)
                $acctPhone = '';
                $retailers = $store->load('retailers.json') ?? [];
                foreach ($retailers as $r) {
                    if (in_array($r['role'] ?? '', ['accountant', 'admin'], true) && !empty($r['phone'])) {
                        $acctPhone = preg_replace('/[^0-9]/', '', $r['phone']);
                        break;
                    }
                }
                if ($acctPhone) {
                    $notify->sendVia('support', $acctPhone, $alertMsg, 'ops_payment_deleted');
                    whLog($changeType, "Alert sent to accountant: {$acctPhone}");
                }
            }
        } catch (\Throwable $waErr) {
            whLog('wa_error', "Alert failed: " . $waErr->getMessage());
        }

        $actions = [];
        if ($reversedCb) $actions[] = 'cashbook reversed';
        if ($voidedCol) $actions[] = 'collection voided';
        $summary = $actions ? implode(' + ', $actions) : 'no matching records found';
        whResp(200, "payment.delete processed: {$summary}");
    }

    // ── Known events — no action needed ──────────────────────────────────
    case 'service.outage':
    case 'invoice.edit':
    case 'invoice.delete':
    case 'payment.edit':
    case 'payment.delete': {
        // v4.21.38: even if we don't notify, we MUST refresh app caches —
        // these events change invoice paid/unpaid state, which is what the
        // customer-facing app reads. Without this, edits made in UCRM
        // (waivers, adjustments, void) would never reach the customer's view.
        $entId = $entityId ?: (int)($entity['id'] ?? 0);
        $clientId = 0;
        try {
            if (in_array($changeType, ['invoice.edit', 'invoice.delete'], true) && $entId) {
                $inv = $crm->get("invoices/{$entId}") ?? $entity;
                $clientId = (int)($inv['clientId'] ?? 0);
            } elseif (in_array($changeType, ['payment.edit', 'payment.delete'], true) && $entId) {
                $pay = $crm->get("billing/payments/{$entId}") ?? $entity;
                $clientId = (int)($pay['clientId'] ?? 0);
            }
        } catch (\Throwable $_) {}

        if ($clientId > 0) {
            try {
                if (!class_exists('ClientInvoiceCacheRefresher')) {
                    @require_once __DIR__ . '/lib/ClientInvoiceCacheRefresher.php';
                }
                if (class_exists('ClientInvoiceCacheRefresher')) {
                    $refresher = new ClientInvoiceCacheRefresher($store, $crm, $dataDir);
                    $refresher->refreshForClient($clientId, true, "webhook:{$changeType}");
                    $refresher->refreshClientRecord($clientId, "webhook:{$changeType}");
                    whLog($changeType, "App cache refreshed for CRM #{$clientId}");
                }
            } catch (\Throwable $e) {
                whLog($changeType, 'App cache refresh crashed: ' . $e->getMessage());
            }
        }

        whResp(200, "{$changeType} processed (cache refreshed).");
    }

    case 'invoice.add_draft':
    case 'client.invite':
    case 'client.delete':
    case 'client.archive':
    case 'service.edit':
    case 'credit_card.add': {
        whLog($changeType, "Known event — no action configured", ['entity_id' => $entityId]);
        whResp(200, "{$changeType} acknowledged.");
    }

    default:
        // Log but don't fail — future UCRM events we haven't handled yet
        whLog($changeType, "Unhandled event type — logged only", ['entity_id' => $entityId]);
        whResp(200, "Event '{$changeType}' received and logged (no action configured).");
}
