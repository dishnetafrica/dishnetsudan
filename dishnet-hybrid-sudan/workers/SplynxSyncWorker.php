<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkerBase.php';
require_once dirname(__DIR__) . '/lib/SplynxApiClient.php';

/**
 * SplynxSyncWorker — Pushes ticket changes to Splynx ISP.
 *
 * Handles:
 *   ticket.status_changed → PUT /api/2.0/admin/support/tickets/{id}
 *   ticket.assigned       → status bump (new→wip) + WA notify engineer
 *   install.ready         → notify support leader
 */
class SplynxSyncWorker extends WorkerBase
{
    private SplynxApiClient $splynx;

    public function __construct($store, array $config, int $maxRun = 55, int $batch = 20)
    {
        parent::__construct($store, $config, $maxRun, $batch);
        $this->splynx = SplynxApiClient::fromConfig($config);
    }

    protected function getEventTypes(): array
    {
        return [
            'ticket.status_changed',
            'ticket.assigned',
            'install.ready',
        ];
    }

    protected function handle(array $event): void
    {
        $type    = $event['event_type'] ?? '';
        $payload = $event['_payload'] ?? [];

        switch ($type) {
            case 'ticket.status_changed':
                $this->handleStatusChanged($payload);
                break;

            case 'ticket.assigned':
                $this->handleAssigned($payload);
                break;

            case 'install.ready':
                $this->handleInstallReady($payload);
                break;
        }
    }

    private function handleStatusChanged(array $p): void
    {
        if (!$this->splynx->isConfigured()) return;
        $splynxTid = (int)($p['splynx_ticket_id'] ?? 0);
        $newStatus = (int)($p['new_status'] ?? 0);
        if (!$splynxTid || !$newStatus) return;

        $result = $this->splynx->updateTicket($splynxTid, ['status' => $newStatus]);
        if ($result === null) {
            throw new \RuntimeException('Splynx API failed: ' . json_encode($this->splynx->getLastError()));
        }
        $this->log('INFO', "Ticket #{$splynxTid} status→{$newStatus} synced to Splynx");
    }

    private function handleAssigned(array $p): void
    {
        $splynxTid = (int)($p['splynx_ticket_id'] ?? 0);
        $oldStatus = (int)($p['old_status'] ?? 0);

        // Auto-bump new→wip in Splynx
        if ($this->splynx->isConfigured() && $splynxTid && $oldStatus === 1) {
            $this->splynx->updateTicket($splynxTid, ['status' => 2]);
            $this->log('INFO', "Ticket #{$splynxTid} bumped new→wip on assignment");
        }

        // WA notify engineer
        $phone = $p['engineer_phone'] ?? '';
        if ($phone) {
            require_once dirname(__DIR__) . '/lib/NotificationService.php';
            $notify = new NotificationService($this->store, $this->config);
            $msg = "\xF0\x9F\x94\xA7 *New Job Assigned*\n"
                 . "Customer: " . ($p['customer_name'] ?? 'Customer') . "\n"
                 . "Area: " . ($p['area'] ?? '') . "\n"
                 . "Open your DishNet app to view details.";
            $notify->sendWhatsApp($phone, $msg, 'support');
        }
    }

    private function handleInstallReady(array $p): void
    {
        // Notify support leader
        require_once dirname(__DIR__) . '/lib/NotificationService.php';
        $notify = new NotificationService($this->store, $this->config);
        $adminPhone = trim($this->config['whatsapp_admin_phone'] ?? '');
        if ($adminPhone) {
            $msg = "\xE2\x9C\x85 *Install Ready for Commissioning*\n"
                 . "Engineer: " . ($p['engineer_name'] ?? '') . "\n"
                 . "ONU: " . ($p['onu_serial'] ?? '') . "\n"
                 . "Signal: " . ($p['signal_db'] ?? '?') . " dBm";
            $notify->sendWhatsApp($adminPhone, $msg, 'support');
        }
    }
}
