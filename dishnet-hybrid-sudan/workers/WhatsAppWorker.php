<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkerBase.php';
require_once dirname(__DIR__) . '/lib/NotificationService.php';

/**
 * WhatsAppWorker — Sends queued WhatsApp messages via WASender API.
 *
 * Handles:
 *   wa.send           → generic message send
 *   install.rejected  → engineer rejection notification
 *   install.completed → customer activation notification
 */
class WhatsAppWorker extends WorkerBase
{
    private NotificationService $notify;

    public function __construct($store, array $config, int $maxRun = 55, int $batch = 20)
    {
        parent::__construct($store, $config, $maxRun, $batch);
        $this->notify = new NotificationService($store, $config);
    }

    protected function getEventTypes(): array
    {
        return [
            'wa.send',
            'install.rejected',
            'install.completed',
        ];
    }

    protected function handle(array $event): void
    {
        $type    = $event['event_type'] ?? '';
        $payload = $event['_payload'] ?? [];

        switch ($type) {
            case 'wa.send':
                $phone   = $payload['phone'] ?? '';
                $message = $payload['message'] ?? '';
                $sender  = $payload['sender'] ?? 'support';
                if ($phone && $message) {
                    $this->notify->sendWhatsApp($phone, $message, $sender);
                }
                break;

            case 'install.rejected':
                $phone = $payload['engineer_phone'] ?? '';
                if ($phone) {
                    $msg = "\xE2\x9D\x8C *Installation Rejected*\n"
                         . "Customer: " . ($payload['customer_name'] ?? '') . "\n"
                         . "Area: " . ($payload['area'] ?? '') . "\n"
                         . "Reason: " . ($payload['reason'] ?? 'No reason') . "\n"
                         . "By: " . ($payload['rejected_by'] ?? '') . "\n"
                         . "Please review and re-submit.";
                    $this->notify->sendWhatsApp($phone, $msg, 'support');
                }
                break;

            case 'install.completed':
                $phone = $payload['customer_phone'] ?? '';
                if ($phone) {
                    $name = $payload['customer_name'] ?? 'Customer';
                    $msg = "\xE2\x9C\x85 *Installation Complete*\n"
                         . "Dear {$name}, your DishNet fiber connection is now active!\n"
                         . "If you have questions, reply to this message.";
                    $this->notify->sendWhatsApp($phone, $msg, 'support');
                }
                break;
        }
    }
}
