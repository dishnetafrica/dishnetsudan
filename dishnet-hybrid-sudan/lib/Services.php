<?php
declare(strict_types=1);

/**
 * Lazy Service Container for DishNet Hybrid Telecom.
 *
 * Replaces eager loading of all 22 lib files on every request.
 * Services are require_once'd and instantiated only on first access.
 *
 * Usage:
 *   $crm = svc('crm');
 *   $notify = svc('notify');
 *
 * Core services ($store, $auth, $limiter, $rbac) remain eagerly loaded
 * because they're needed on literally every request.
 */
function svc(string $name)
{
    static $instances = [];
    if (isset($instances[$name])) return $instances[$name];

    // Core dependencies — always available from global scope
    $store   = $GLOBALS['store'];
    $config  = $GLOBALS['config'];
    $dataDir = $GLOBALS['dataDir'];
    $pluginRoot = $GLOBALS['_PLUGIN_ROOT'];

    switch ($name) {

        // ── CRM / Billing ────────────────────────────────────────────────
        case 'crm':
            require_once $pluginRoot . '/lib/CrmApiClient.php';
            return $instances[$name] = CrmApiClient::fromUcrm($pluginRoot, $config);

        case 'ftthCrm':
            require_once $pluginRoot . '/lib/FtthCrmService.php';
            return $instances[$name] = new FtthCrmService(svc('crm'), $store, $config);

        case 'queue':
            require_once $pluginRoot . '/lib/CrmQueue.php';
            return $instances[$name] = new CrmQueue($store);

        case 'paymentUuids':
            require_once $pluginRoot . '/lib/PaymentUuids.php';
            return $instances[$name] = new PaymentUuids($store);

        // ── Sales / Wallet ───────────────────────────────────────────────
        case 'wallet':
            require_once $pluginRoot . '/lib/WalletService.php';
            return $instances[$name] = new WalletService($store, $store->getPdo());

        case 'recharge':
            require_once $pluginRoot . '/lib/RechargeService.php';
            $uploadDir = $dataDir . '/uploads';
            return $instances[$name] = new RechargeService($store, svc('wallet'), $uploadDir);

        case 'sim':
            require_once $pluginRoot . '/lib/SimService.php';
            return $instances[$name] = new SimService($store, svc('wallet'));

        case 'kyc':
            require_once $pluginRoot . '/lib/KycService.php';
            return $instances[$name] = new KycService(svc('crm'), $store, svc('wallet'), svc('queue'), $dataDir);

        // ── Notifications / WhatsApp ─────────────────────────────────────
        case 'notify':
            require_once $pluginRoot . '/lib/NotificationService.php';
            return $instances[$name] = new NotificationService($store, $config);

        case 'waBot':
            require_once $pluginRoot . '/lib/WaBotService.php';
            return $instances[$name] = new WaBotService($store, svc('notify'), $config);

        // ── LTE / Magma ──────────────────────────────────────────────────
        case 'magma':
            require_once $pluginRoot . '/lib/MagmaApiClient.php';
            return $instances[$name] = new MagmaApiClient($config);

        case 'lte':
            require_once $pluginRoot . '/lib/LteSqliteService.php';
            return $instances[$name] = new LteSqliteService($store->getPdo(), svc('magma'));

        case 'lteService':
            require_once $pluginRoot . '/lib/LteService.php';
            return $instances[$name] = new LteService($store, svc('magma'), $store->getPdo());

        // ── Splynx ───────────────────────────────────────────────────────
        case 'splynx':
            require_once $pluginRoot . '/lib/SplynxApiClient.php';
            return $instances[$name] = SplynxApiClient::fromConfig($config);

        case 'splynxTickets':
            require_once $pluginRoot . '/lib/SplynxTicketService.php';
            return $instances[$name] = new SplynxTicketService(svc('splynx'), $store, svc('notify'), $config);

        case 'splynxCusts':
            require_once $pluginRoot . '/lib/SplynxCustomerService.php';
            return $instances[$name] = new SplynxCustomerService(svc('splynx'), svc('splynxTickets'), $store, $config);

        default:
            throw new \RuntimeException("Unknown service: {$name}");
    }
}
