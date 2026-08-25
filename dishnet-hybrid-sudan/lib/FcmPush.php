<?php
/**
 * FCM Push Notification Helper
 *
 * Lightweight wrapper — includes just the push functions from api_customer_app.php.
 * Safe to require from webhook.php, cron scripts, or anywhere with $store + $config.
 *
 * Usage:
 *   require_once __DIR__ . '/FcmPush.php';
 *   fcm_push_invoice_created($store->getPdo(), $config, $clientId, 'INV001', 150);
 */
declare(strict_types=1);

if (!function_exists('fcm_send_push')) {
    function fcm_send_push($pdo, array $config, int $clientId, string $event, string $title, string $body, array $data = []): array {
        $serverKey = trim($config['fcm_server_key'] ?? '');
        if (!$serverKey) {
            return ['sent' => 0, 'failed' => 0, 'errors' => ['FCM server key not configured']];
        }

        // Ensure tables exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_fcm_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            crm_client_id INTEGER NOT NULL,
            token TEXT NOT NULL UNIQUE,
            platform TEXT DEFAULT 'android',
            registered_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_push_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            crm_client_id INTEGER NOT NULL,
            event TEXT NOT NULL,
            title TEXT,
            body TEXT,
            success INTEGER DEFAULT 0,
            error TEXT,
            sent_at INTEGER NOT NULL
        )");

        $stmt = $pdo->prepare("SELECT token, platform FROM app_fcm_tokens WHERE crm_client_id = ?");
        $stmt->execute([$clientId]);
        $tokens = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($tokens)) {
            return ['sent' => 0, 'failed' => 0, 'errors' => ['No registered devices for client ' . $clientId]];
        }

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($tokens as $device) {
            $payload = [
                'to' => $device['token'],
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'icon' => 'ic_launcher',
                    'color' => '#D41C1C',
                    'sound' => 'default',
                    'click_action' => 'OPEN_CUSTOMER_APP',
                ],
                'data' => array_merge([
                    'event' => $event,
                    'client_id' => (string)$clientId,
                ], $data),
            ];

            $ch = curl_init('https://fcm.googleapis.com/fcm/send');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: key=' . $serverKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $resp = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($resp ?: '{}', true) ?: [];
            $success = ($httpCode === 200 && ($result['success'] ?? 0) > 0);

            $pdo->prepare("
                INSERT INTO app_push_log (crm_client_id, event, title, body, success, error, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $clientId, $event, $title, $body,
                $success ? 1 : 0,
                $success ? null : substr($resp ?: 'HTTP ' . $httpCode, 0, 500),
                time()
            ]);

            if ($success) {
                $sent++;
            } else {
                $failed++;
                $errors[] = substr($resp ?: '', 0, 200);
                // Remove invalid tokens
                if (isset($result['results'][0]['error']) &&
                    in_array($result['results'][0]['error'], ['NotRegistered', 'InvalidRegistration'], true)) {
                    $pdo->prepare("DELETE FROM app_fcm_tokens WHERE token = ?")->execute([$device['token']]);
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
    }
}

// ── Convenience helpers for specific events ──────────────────────

if (!function_exists('fcm_push_invoice_created')) {
    function fcm_push_invoice_created($pdo, array $config, int $clientId, string $invoiceNumber, float $amount, string $currency = 'USD'): array {
        return fcm_send_push($pdo, $config, $clientId, 'invoice_created',
            'New Invoice · DishNet',
            "Invoice {$invoiceNumber} for \${$amount} {$currency} has been created. Tap to view.",
            ['invoice_number' => $invoiceNumber, 'amount' => (string)$amount]
        );
    }
}

if (!function_exists('fcm_push_payment_received')) {
    function fcm_push_payment_received($pdo, array $config, int $clientId, float $amount, string $currency = 'USD'): array {
        return fcm_send_push($pdo, $config, $clientId, 'payment_received',
            'Payment Confirmed · DishNet',
            "Your payment of \${$amount} {$currency} has been received. Thank you!",
            ['amount' => (string)$amount]
        );
    }
}

if (!function_exists('fcm_push_service_suspended')) {
    function fcm_push_service_suspended($pdo, array $config, int $clientId, string $serviceName = ''): array {
        $body = $serviceName
            ? "Your service '{$serviceName}' has been suspended due to non-payment."
            : "Your service has been suspended due to non-payment.";
        return fcm_send_push($pdo, $config, $clientId, 'service_suspended',
            'Service Suspended · DishNet',
            $body . ' Please pay your outstanding balance to restore service.',
            ['service' => $serviceName]
        );
    }
}

if (!function_exists('fcm_push_service_activated')) {
    function fcm_push_service_activated($pdo, array $config, int $clientId, string $serviceName = ''): array {
        $body = $serviceName
            ? "Your service '{$serviceName}' is now active!"
            : "Your service is now active!";
        return fcm_send_push($pdo, $config, $clientId, 'service_activated',
            'Service Active · DishNet',
            $body . ' Enjoy your DishNet connection.',
            ['service' => $serviceName]
        );
    }
}
