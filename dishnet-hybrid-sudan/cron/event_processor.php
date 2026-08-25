<?php
// Note: No strict_types - included from master.php

/**
 * cron/event_processor.php — EventBus consumer
 * DishNet Hybrid v3.8 — Phase 2
 *
 * Processes events from the events table.
 * Called by master.php every 10 seconds.
 *
 * Each event type is routed to a handler function.
 * Handlers make external API calls (Splynx, WhatsApp).
 * On success: ack(). On failure: fail() with exponential backoff.
 *
 * This file is included by master.php — $store, $config are available
 * from the parent scope. We re-require libs that master.php might not load.
 */

// ── Dependencies (master.php already loads SqliteStore) ──────────────────────
$pluginDir = dirname(__DIR__);

require_once $pluginDir . '/lib/EventBus.php';
require_once $pluginDir . '/lib/SplynxApiClient.php';
require_once $pluginDir . '/lib/SplynxTicketService.php';
require_once $pluginDir . '/lib/SplynxCustomerService.php';
require_once $pluginDir . '/lib/NotificationService.php';

// $store and $config are inherited from master.php scope
$pdo    = $store->getPdo();
$bus    = new EventBus($pdo);
$notify = new NotificationService($store, $config);

// ── Init Splynx client (may not be configured) ──────────────────────────────
$splynxUrl    = trim($config['splynx_url'] ?? '');
$splynxKey    = trim($config['splynx_key'] ?? '');
$splynxSecret = trim($config['splynx_secret'] ?? '');
$splynx       = ($splynxUrl && $splynxKey && $splynxSecret) 
    ? new SplynxApiClient($splynxUrl, $splynxKey, $splynxSecret)
    : null;

// ── Consume events ──────────────────────────────────────────────────────────
$events = $bus->consume(20);
if (empty($events)) return; // nothing to process

$processed = 0;
$failed    = 0;

foreach ($events as $event) {
    $eid     = (int)$event['id'];
    $type    = $event['event_type'] ?? '';
    $payload = json_decode($event['payload'] ?? '{}', true) ?: [];

    try {
        switch ($type) {

            // ── Ticket status changed (from NOC view) ────────────────────
            case 'ticket.status_changed':
                if (!$splynx->isConfigured()) {
                    $bus->ack($eid); // Nothing to push, but don't retry
                    break;
                }
                $splynxTid  = (int)($payload['splynx_ticket_id'] ?? $payload['ticket_id'] ?? 0);
                $newStatus  = (int)($payload['new_status'] ?? 0);
                if ($splynxTid && $newStatus) {
                    $result = $splynx->updateTicket($splynxTid, ['status' => $newStatus]);
                    if ($result === null) {
                        throw new \RuntimeException('Splynx API error: ' . json_encode($splynx->getLastError()));
                    }
                }
                $bus->ack($eid);
                $processed++;
                break;

            // ── Engineer assigned to ticket ───────────────────────────────
            case 'ticket.assigned':
                // Auto-move "new" tickets to "work in progress" in Splynx
                if ($splynx->isConfigured()) {
                    $splynxTid = (int)($payload['splynx_ticket_id'] ?? 0);
                    $oldStatus = (int)($payload['old_status'] ?? 0);
                    if ($splynxTid && $oldStatus === 1) {
                        $splynx->updateTicket($splynxTid, ['status' => 2]);
                    }
                }
                // Notify engineer via WhatsApp
                $engPhone = $payload['engineer_phone'] ?? '';
                if ($engPhone) {
                    $custName = $payload['customer_name'] ?? 'Customer';
                    $area     = $payload['area'] ?? '';
                    $msg = "\xF0\x9F\x94\xA7 *New Job Assigned*\nCustomer: {$custName}\nArea: {$area}\nOpen your DishNet app to view details.";
                    $notify->sendWhatsApp($engPhone, $msg, 'support');
                }
                $bus->ack($eid);
                $processed++;
                break;

            // ── Installation completed ────────────────────────────────────
            case 'install.completed':
                // Activate service in Splynx
                $serviceId = (int)($payload['splynx_service_id'] ?? 0);
                if ($serviceId && $splynx->isConfigured()) {
                    require_once $pluginDir . '/lib/SplynxCustomerService.php';
                    $splynxCusts = new SplynxCustomerService(
                        $splynx,
                        new SplynxTicketService($splynx, $store, $notify, $config),
                        $store,
                        $config
                    );
                    $splynxCusts->activateService($serviceId);
                }
                // Notify customer
                $custPhone = $payload['customer_phone'] ?? '';
                if ($custPhone) {
                    $custName = $payload['customer_name'] ?? 'Customer';
                    $msg = "\xE2\x9C\x85 *Installation Complete*\nDear {$custName}, your DishNet fiber connection is now active!\nIf you have questions, reply to this message.";
                    $notify->sendWhatsApp($custPhone, $msg, 'support');
                }
                $bus->ack($eid);
                $processed++;
                break;

            // ── Inbound: Splynx webhook events ───────────────────────────
            case 'splynx.ticket.updated':
            case 'splynx.ticket.created':
                // Fetch the SINGLE updated ticket from Splynx and merge locally
                // (NOT full syncTickets which pulls all 500 tickets)
                $splynxTid = (int)($payload['id'] ?? $payload['entity_id'] ?? 0);
                if ($splynxTid && $splynx->isConfigured()) {
                    $remote = $splynx->getTicket($splynxTid);
                    if ($remote) {
                        // Merge this single ticket into local store
                        $allTickets = $store->load('splynx_tickets.json') ?? [];
                        $found = false;
                        $newStatus  = (int)($remote['status_id'] ?? $remote['status'] ?? 0);
                        $statusLbl  = '';
                        $lblMap = [1=>'new',2=>'work in progress',3=>'resolved',4=>'waiting your answer',5=>'waiting on agent',7=>'survey done',8=>'fiber deployment in progress',9=>'ready onu mapped',10=>'cancel by customer',11=>'fiber not available',12=>'client not ready'];
                        $statusLbl = $lblMap[$newStatus] ?? 'status-' . $newStatus;

                        $isClosed    = ($remote['closed'] ?? '0') === '1';
                        $completedSt = [3, 4, 5]; // Resolved, Solved, Closed
                        $isDone      = $isClosed || in_array($newStatus, $completedSt, true);

                        foreach ($allTickets as &$lt) {
                            if ((int)($lt['id'] ?? 0) === $splynxTid) {
                                // Update status fields only (preserve DishNet-managed fields)
                                $lt['status']       = $newStatus;
                                $lt['status_label'] = $statusLbl;
                                $lt['updated_at']   = $remote['updated_at'] ?? date('Y-m-d H:i:s');
                                $lt['priority']     = $remote['priority'] ?? ($lt['priority'] ?? '');

                                // v4.11.3: Detect completion from webhook event
                                // Fires when Bidal clicks Solved or Closed in Splynx
                                if ($isDone && empty($lt['install_complete'])) {
                                    $lt['install_complete']    = true;
                                    $lt['install_complete_at'] = date('Y-m-d H:i:s');

                                    // Mirror to SQLite tickets table so cron Task 6 can pick it up
                                    try {
                                        $pdo->prepare(
                                            "UPDATE tickets SET install_complete=1, install_complete_at=datetime('now') WHERE id=?"
                                        )->execute([$splynxTid]);
                                    } catch (\Throwable $e) {}
                                }
                                $found = true;
                                break;
                            }
                        }
                        unset($lt);

                        if (!$found) {
                            // New ticket from Splynx — import it
                            $subject  = $remote['subject'] ?? '';
                            $custName = preg_replace('/^(DishNet |Dishnet )?(New GPON |Fiber )?(Installation|Installtion)\s*[-—]?\s*/i', '', $subject);
                            $allTickets[] = [
                                'id'               => $splynxTid,
                                'app_id'           => 0,
                                'customer_id'      => (int)($remote['customer_id'] ?? 0),
                                'customer_name'    => trim($custName) ?: $subject,
                                'address'          => '',
                                'status'           => $newStatus,
                                'status_label'     => $statusLbl,
                                'priority'         => $remote['priority'] ?? '',
                                'created_at'       => $remote['created_at'] ?? date('Y-m-d H:i:s'),
                                'updated_at'       => $remote['updated_at'] ?? date('Y-m-d H:i:s'),
                                'install_complete' => false,
                                'engineer'         => '',
                                'subject'          => $subject,
                                'splynx_imported'  => true,
                            ];
                        }
                        $store->save('splynx_tickets.json', array_values($allTickets));
                    }
                }
                $bus->ack($eid);
                $processed++;
                break;

            // ── WhatsApp message queue ────────────────────────────────────
            case 'wa.send':
                $phone   = $payload['phone'] ?? '';
                $message = $payload['message'] ?? '';
                $sender  = $payload['sender'] ?? 'support';
                if ($phone && $message) {
                    $notify->sendWhatsApp($phone, $message, $sender);
                }
                $bus->ack($eid);
                $processed++;
                break;

            // ── Installation rejected ─────────────────────────────────────
            case 'install.rejected':
                $engPhone = $payload['engineer_phone'] ?? '';
                if ($engPhone) {
                    $custName  = $payload['customer_name'] ?? 'Customer';
                    $area      = $payload['area'] ?? '';
                    $reason    = $payload['reason'] ?? 'No reason given';
                    $rejBy     = $payload['rejected_by'] ?? 'Support Leader';
                    $msg = "\xE2\x9D\x8C *Installation Rejected*\n"
                         . "Customer: {$custName}\n"
                         . "Area: {$area}\n"
                         . "Reason: {$reason}\n"
                         . "By: {$rejBy}\n"
                         . "Please review and re-submit.";
                    $notify->sendWhatsApp($engPhone, $msg, 'support');
                }
                $bus->ack($eid);
                $processed++;
                break;

            // ── Unknown event type ───────────────────────────────────────
            default:
                // Don't fail — just ack and log
                error_log("event_processor: unknown event type '{$type}' (event #{$eid})");
                $bus->ack($eid);
                $processed++;
                break;
        }

    } catch (\Throwable $e) {
        $bus->fail($eid, $e->getMessage());
        $failed++;
        error_log("event_processor: event #{$eid} ({$type}) failed: " . $e->getMessage());
    }
}

// ── Dead letter alerting ────────────────────────────────────────────────────
$deadLetters = $bus->getDeadLetters(10);
$adminPhone  = trim($config['whatsapp_admin_phone'] ?? '');

foreach ($deadLetters as $dead) {
    // Only alert once — check if already marked
    if (strpos($dead['error'] ?? '', '[admin alerted]') !== false) continue;

    // Mark as alerted FIRST (prevents double-alert if WA call is slow)
    $pdo->prepare("UPDATE events SET error = COALESCE(error,'') || ' [admin alerted]' WHERE id = ? AND status = 'dead'")->execute([$dead['id']]);

    if ($adminPhone) {
        $msg = "\xE2\x9A\xA0\xEF\xB8\x8F *Dead Letter Event*\n"
             . "Type: {$dead['event_type']}\n"
             . "Entity: {$dead['entity_type']} #{$dead['entity_id']}\n"
             . "Error: " . substr($dead['error'] ?? 'unknown', 0, 200) . "\n"
             . "Attempts: {$dead['attempts']}\n"
             . "Created: {$dead['created_at']}";
        try {
            $notify->sendWhatsApp($adminPhone, $msg, 'support');
        } catch (\Throwable $e) {
            // WA failure shouldn't crash the event processor
            error_log("Dead letter WA alert failed: " . $e->getMessage());
        }
    }
}

// Log summary
if ($processed > 0 || $failed > 0) {
    echo "event_processor: processed={$processed} failed={$failed} dead=" . count($deadLetters) . "\n";
}
