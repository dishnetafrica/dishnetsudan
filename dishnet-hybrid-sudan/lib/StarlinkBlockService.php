<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }

/**
 * StarlinkBlockService v1.0  (introduced DishNet Hybrid v4.21.0)
 * ════════════════════════════════════════════════════════════════
 *
 * Orchestrates automatic Starlink device-blocking when UCRM marks a
 * customer as suspended for non-payment, and the inverse on restoration.
 *
 * Trigger points (all in webhook.php):
 *   service.suspend / SERVICE_SUSPEND  → suspend()
 *   service.unsuspend / activate       → restore()
 *   payment.add (when balance clears)  → restore() if state row exists
 *
 * Architecture (data flow per call):
 *
 *   1. UCRM client_id → Starlink Finance plugin's data/sl_kits.json
 *      to get list of KIT serial numbers assigned to this client.
 *   2. KIT serial → Data Report plugin's data/wifi_router_map.json
 *      to get router_id, account_number, is_bypassed.
 *   3. For each router:
 *      a) Save current SSID + password (wifi_get_config gRPC call)
 *      b) Fetch live device list (wifi_get_status gRPC call)
 *      c) Pause every connected MAC (wifi_pause_client gRPC call x N)
 *      d) Change SSID + password (wifi_change_password gRPC call)
 *   4. Persist state to sl_suspension_state table.
 *
 * All gRPC calls go through the Data Report plugin's public.php
 * (?action=dr_wifi_*) over loopback HTTP — Hybrid never speaks gRPC.
 *
 * VIP guard: clients tagged NO_AUTO_BLOCK (UCRM tag id 84 on production
 * CRM, configurable via starlink_block_vip_tag_id) or listed in the
 * starlink_block_vip_clients config key are skipped at suspend(). An
 * admin alert is sent instead so support can decide manually.
 *
 * Bypass-mode dishes (router not Starlink-managed): we still pause
 * connected MACs (limited utility) but skip the SSID/password change.
 *
 * State machine (in sl_suspension_state.state):
 *   active                    — no row exists (default)
 *   suspending                — block in progress, intermediate
 *   suspended                 — block confirmed, all gRPC OK
 *   partial_suspend_failed    — multi-router customer, some routers OK some not
 *   restoring                 — restore in progress
 *   error_manual_required     — 5+ retry attempts failed; admin must intervene
 *
 * Idempotency: every entry point checks current state first. A repeat
 * webhook for an already-suspended client is a no-op.
 *
 * NOTE on dependencies (data plugin paths):
 *   - dishnet-starlink-finance/data/sl_kits.json
 *   - dishnet-data-report/data/wifi_router_map.json
 *   These are read-only here. If either is missing/stale, the operation
 *   is logged and partial-failed — never throws.
 */
class StarlinkBlockService
{
    const TABLE_STATE = 'sl_suspension_state';
    const TABLE_LOG   = 'sl_suspension_log';

    const STATE_SUSPENDING       = 'suspending';
    const STATE_SUSPENDED        = 'suspended';
    const STATE_PARTIAL_FAILED   = 'partial_suspend_failed';
    const STATE_RESTORING        = 'restoring';
    const STATE_ERROR_MANUAL     = 'error_manual_required';

    const MAX_RETRY_ATTEMPTS     = 5;
    const HTTP_TIMEOUT_SEC       = 20;

    /** Soft budget for synchronous suspend/restore in webhook context.
     *  UCRM webhook timeout is ~30s; we leave ~10s headroom for the rest
     *  of webhook.php's processing. Routers not completed within budget
     *  are left in 'suspending' state for cron_starlink_block_retry to
     *  finish on the next tick. */
    const SYNC_BUDGET_SEC        = 18;

    /** Block modes (v4.21.4+).
     *  pause_only   — pause every connected MAC; SSID/password untouched.
     *  rename_only  — pause + rename SSID to DishNet-PAY-NOW; password kept.
     *                 DEFAULT. Customer sees the locked name in WiFi list.
     *                 Easiest unblock — no password recovery needed.
     *  full         — pause + rename SSID + change password to random code.
     *                 Use for escalated non-payers only.
     *  Default is rename_only. Override via config key
     *  starlink_block_default_mode = 'pause_only' | 'rename_only' | 'full'. */
    const MODE_PAUSE_ONLY        = 'pause_only';
    const MODE_RENAME_ONLY       = 'rename_only';
    const MODE_FULL              = 'full';

    /** @var \PDO */
    private $pdo;
    /** @var object Hybrid SqliteStore (for JSON access) */
    private $store;
    /** @var array config */
    private $config;
    /** @var string */
    private $dataDir;
    /** @var string base URL of dishnet-data-report public.php */
    private $drPluginUrl = '';
    /** @var string */
    private $hybridDataDir;
    /** @var NotificationService|null injected, used for admin alerts */
    private $notify;

    public function __construct(\PDO $pdo, $store, array $config, string $dataDir, $notify = null)
    {
        $this->pdo     = $pdo;
        $this->store   = $store;
        $this->config  = $config;
        $this->dataDir = rtrim($dataDir, '/');
        $this->hybridDataDir = $this->dataDir;
        $this->notify  = $notify;
        $this->drPluginUrl = $this->resolveDataReportUrl();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Suspend Starlink service for a UCRM client.
     *
     * @param int    $clientId      UCRM client ID
     * @param int    $serviceId     UCRM service ID (informational, may be 0)
     * @param string $triggeredBy   'webhook' | 'manual:<retailer_id>' | 'cron_retry'
     * @param string $eventType     e.g. 'service.suspend'
     * @return array { ok, state, routers_processed, routers_failed, skipped_reason }
     */
    public function suspend(int $clientId, int $serviceId, string $triggeredBy = 'webhook', string $eventType = '', ?array $freshClient = null): array
    {
        $this->log($clientId, '', 'webhook_received', true, null, '', "trigger={$triggeredBy} event={$eventType}");

        // ── 1. VIP guard ────────────────────────────────────────────────────
        // Pass $freshClient if caller has it (webhook does); otherwise falls
        // back to the daily-refreshed ucrm_clients_cache.json. v4.21.6+
        if ($this->isVipClient($clientId, $freshClient)) {
            $this->log($clientId, '', 'skip_vip', true, null, '', 'VIP client — auto-block skipped, admin alerted');
            $this->alertAdmin(
                "⚠️ *VIP Auto-Block Skipped*\n\n"
                . "Client #{$clientId} is on the VIP list — service.suspend fired but devices were NOT blocked.\n\n"
                . "Manual decision required. Review in CRM."
            );
            return ['ok' => true, 'skipped_reason' => 'vip', 'routers_processed' => 0];
        }

        // ── 2. Resolve client → KITs → routers ─────────────────────────────
        $routers = $this->resolveClientRouters($clientId);
        if (empty($routers)) {
            $this->log($clientId, '', 'webhook_skipped', true, null, '', 'No Starlink routers found for client');
            return ['ok' => true, 'skipped_reason' => 'no_routers', 'routers_processed' => 0];
        }

        $routersProcessed = 0;
        $routersFailed    = 0;
        $routersDeferred  = 0;
        $startTime        = microtime(true);
        $isWebhookContext = ($triggeredBy === 'webhook');

        foreach ($routers as $router) {
            // Budget guard: if running synchronously (webhook context), bail
            // before exceeding UCRM's webhook timeout. Cron picks up remaining
            // routers on next tick — they're already in 'suspending' state if
            // we created the row, or no row at all if we hadn't started yet
            // (cron won't see those, so we explicitly create rows up-front).
            if ($isWebhookContext && (microtime(true) - $startTime) > self::SYNC_BUDGET_SEC) {
                $this->ensureSuspendingRow($clientId, $serviceId, $router, $triggeredBy, $eventType);
                $routersDeferred++;
                continue;
            }

            $routerId  = (string)$router['router_id_full'];
            $kitSerial = (string)($router['kit_serial'] ?? '');
            $accNum    = (string)($router['account_number'] ?? '');
            $isBypass  = !empty($router['is_bypassed']);

            // ── Idempotency: already-suspended row → no-op ─────────────────
            $existing = $this->loadState($clientId, $routerId);
            if ($existing && in_array($existing['state'], [self::STATE_SUSPENDED, self::STATE_SUSPENDING], true)) {
                // STATE_SUSPENDING means cron will finish it; STATE_SUSPENDED is done.
                $this->log($clientId, $routerId, 'webhook_skipped', true, null, '',
                    "Already in state={$existing['state']}, skipping duplicate fire");
                $routersProcessed++;
                continue;
            }

            $ok = $this->suspendOneRouter($clientId, $serviceId, $router, $triggeredBy, $eventType);
            if ($ok) {
                $routersProcessed++;
            } else {
                $routersFailed++;
            }
        }

        $finalState = ($routersFailed > 0)
            ? self::STATE_PARTIAL_FAILED
            : self::STATE_SUSPENDED;

        return [
            'ok'                  => $routersFailed === 0,
            'state'               => $finalState,
            'routers_processed'   => $routersProcessed,
            'routers_failed'      => $routersFailed,
            'routers_deferred'    => $routersDeferred,
            'total_routers'       => count($routers),
        ];
    }

    /**
     * Create a 'suspending' row for a router we haven't gotten to yet.
     * Cron picks it up on next 10-min tick.
     */
    private function ensureSuspendingRow(int $clientId, int $serviceId, array $router, string $triggeredBy, string $eventType): void
    {
        $routerId = (string)$router['router_id_full'];
        $existing = $this->loadState($clientId, $routerId);
        if ($existing) return; // already exists, cron will pick it up

        $this->upsertState([
            'client_id'           => $clientId,
            'crm_service_id'      => $serviceId,
            'kit_serial'          => (string)($router['kit_serial'] ?? ''),
            'router_id'           => $routerId,
            'account_number'      => (string)($router['account_number'] ?? ''),
            'state'               => self::STATE_SUSPENDING,
            'is_bypass_mode'      => !empty($router['is_bypassed']) ? 1 : 0,
            'suspended_by'        => $triggeredBy,
            'triggered_by_event'  => $eventType,
            'attempt_count'       => 0, // cron will be the first real attempt
        ]);
        $this->log($clientId, $routerId, 'webhook_received', true, null, '',
            'Deferred to cron — webhook budget exceeded');
    }

    /**
     * Restore Starlink service for a UCRM client (reverse of suspend).
     *
     * @param int    $clientId
     * @param string $triggeredBy   'webhook' | 'payment.add' | 'manual:<retailer_id>'
     * @return array { ok, routers_restored, routers_failed }
     */
    public function restore(int $clientId, string $triggeredBy = 'webhook'): array
    {
        // Find all sl_suspension_state rows for this client (multi-router safe)
        $stmt = $this->pdo->prepare("SELECT * FROM " . self::TABLE_STATE . " WHERE client_id = ?");
        $stmt->execute([$clientId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            // No suspension active — nothing to restore (not an error)
            $this->log($clientId, '', 'webhook_skipped', true, null, '', 'No active suspension state — skip restore');
            return ['ok' => true, 'routers_restored' => 0, 'reason' => 'no_active_suspension'];
        }

        $restored = 0;
        $failed   = 0;
        $deferred = 0;
        $startTime = microtime(true);
        $isWebhookContext = in_array($triggeredBy, ['webhook', 'payment.add'], true);
        $restoredCredentials = []; // for the WA "service restored" message

        foreach ($rows as $row) {
            // Budget guard — leave remaining rows in 'restoring'/existing state
            // for cron to finish on next tick.
            if ($isWebhookContext && (microtime(true) - $startTime) > self::SYNC_BUDGET_SEC) {
                // Mark as restoring so cron picks it up
                $upd = $this->pdo->prepare(
                    "UPDATE " . self::TABLE_STATE . "
                     SET state = ?, restore_started_at = datetime('now'),
                         restore_triggered_by = ?
                     WHERE client_id = ? AND router_id = ?"
                );
                $upd->execute([self::STATE_RESTORING, $triggeredBy, (int)$row['client_id'], (string)$row['router_id']]);
                $deferred++;
                continue;
            }

            $ok = $this->restoreOneRouter($row, $triggeredBy);
            if ($ok) {
                $restored++;
                // Only include credentials in WA-enrichment payload for full-mode rows.
                // For pause_only, the customer's old SSID/password never changed —
                // their devices reconnect automatically without any new credentials
                // needed. Including empty creds in the WA would just add noise.
                $rowMode = (string)($row['block_mode'] ?? self::MODE_FULL);
                if ($rowMode === self::MODE_FULL && !empty($row['original_ssid_24'])) {
                    $restoredCredentials[] = [
                        'ssid'     => $row['original_ssid_24'],
                        'password' => $row['original_pass_24'],
                    ];
                }
            } else {
                $failed++;
            }
        }

        return [
            'ok'                       => $failed === 0,
            'routers_restored'         => $restored,
            'routers_failed'           => $failed,
            'routers_deferred'         => $deferred,
            'restored_credentials'     => $restoredCredentials, // caller can append to WA msg
        ];
    }

    /**
     * Cron entry point: pick up rows in unfinished states and retry.
     * Call from cron_starlink_block_retry.php every 10 minutes.
     */
    public function processRetryQueue(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . self::TABLE_STATE . "
             WHERE state IN (?, ?, ?)
               AND attempt_count < ?
               AND datetime(last_attempt_at) < datetime('now', '-5 minutes')
             ORDER BY last_attempt_at ASC
             LIMIT 20"
        );
        $stmt->execute([
            self::STATE_SUSPENDING,
            self::STATE_PARTIAL_FAILED,
            self::STATE_RESTORING,
            self::MAX_RETRY_ATTEMPTS,
        ]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $retried = 0;
        $abandoned = 0;

        foreach ($rows as $row) {
            $clientId = (int)$row['client_id'];
            $routerId = (string)$row['router_id'];

            if ($row['state'] === self::STATE_RESTORING) {
                $ok = $this->restoreOneRouter($row, 'cron_retry');
            } else {
                // Reconstruct router descriptor from what we have
                $router = [
                    'router_id_full' => $routerId,
                    'kit_serial'     => $row['kit_serial'],
                    'account_number' => $row['account_number'],
                    'is_bypassed'    => (bool)$row['is_bypass_mode'],
                ];
                $ok = $this->suspendOneRouter($clientId, (int)$row['crm_service_id'], $router, 'cron_retry', 'retry');
            }

            $retried++;
            if (!$ok) {
                // Check if we've now exhausted retries
                $now = $this->loadState($clientId, $routerId);
                if ($now && (int)$now['attempt_count'] >= self::MAX_RETRY_ATTEMPTS) {
                    $this->markErrorManualRequired($clientId, $routerId);
                    $abandoned++;
                }
            }
        }

        return ['retried' => $retried, 'abandoned' => $abandoned, 'queue_size' => count($rows)];
    }

    /**
     * Extension queue (v4.21.4+): for every router currently in SUSPENDED
     * state with block_mode=pause_only, fetch the live device list and
     * enforce the block:
     *
     *   1. NEW DEVICES: any connected MAC not in our known set → pause it.
     *      Closes the leaky-block problem (devices joining after initial
     *      block would otherwise get internet because password unchanged).
     *
     *   2. BYPASS DETECTION (v4.21.5+): any MAC in our paused_macs_json
     *      that is currently UNPAUSED at Starlink → staff has manually
     *      unpaused via mobile app, bypassing our system. Re-pause it,
     *      increment bypass_event_count, alert admin if threshold hit.
     *
     * Without (2), a staff member with Starlink account access could
     * quietly restore non-paying customers and the auto-block would have
     * no idea. This is the only enforcement layer against insider bypass.
     *
     * Called every 10 min via cron_starlink_block_retry.php — we reuse the
     * existing cron rather than creating a second one to keep cron count
     * low and master.php scheduling simple.
     *
     * Per-router guards:
     *   - skip if block_mode != 'pause_only' (full mode prevents new joins
     *     AND credentials they don't know — bypass impossible)
     *   - skip if state != 'suspended' (in-flight rows owned by retry queue)
     *   - skip if last_attempt_at < 8 min ago (de-dup against drift)
     *   - skip on Starlink throttle (handled inside drGetWifiStatus)
     *
     * Newly-paused MACs are appended to paused_macs_json so restore
     * unpauses them too. Bypass-corrected MACs stay in paused_macs_json
     * (they were already there).
     */
    public function processExtensionQueue(): array
    {
        // Find pause-only rows in SUSPENDED state that haven't been touched
        // recently. last_attempt_at < now-8min is the de-dup gate.
        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . self::TABLE_STATE . "
             WHERE state = ?
               AND block_mode = ?
               AND datetime(last_attempt_at) < datetime('now', '-8 minutes')
             ORDER BY last_attempt_at ASC
             LIMIT 50"
        );
        $stmt->execute([self::STATE_SUSPENDED, self::MODE_PAUSE_ONLY]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $checked = 0;
        $newlyPaused = 0;
        $bypassRepaused = 0;
        $bypassAlertsSent = 0;
        $pauseFailures = 0;

        foreach ($rows as $row) {
            $clientId = (int)$row['client_id'];
            $routerId = (string)$row['router_id'];

            // Fetch live client list
            $status = $this->drGetWifiStatus($routerId);
            if (!$status['ok']) {
                // Don't count as failure — dish offline, throttle, etc. Try
                // again next cycle. Just bump the timestamp so we don't
                // hammer this router endlessly.
                $this->pdo->prepare(
                    "UPDATE " . self::TABLE_STATE . "
                     SET last_attempt_at = datetime('now')
                     WHERE client_id = ? AND router_id = ?"
                )->execute([$clientId, $routerId]);
                $checked++;
                continue;
            }

            // Build known-MACs map: for each MAC we've seen, remember whether
            // it was a previously-paused-by-us device or a pre-existing pause.
            // Pre-existing pauses are customer's own (parental control etc.) —
            // if they get unpaused later, that's not a bypass, just a reversal
            // of the customer's own setting.
            $knownPausedByUs = [];   // MACs we paused — bypass alert if unpaused
            $knownPreExisting = [];  // MACs paused before us — leave alone
            $pausedMacs = json_decode((string)$row['paused_macs_json'], true) ?: [];
            $preExisting = json_decode((string)$row['pre_existing_paused_json'], true) ?: [];
            foreach ($pausedMacs as $m) {
                $mac = strtolower((string)($m['mac'] ?? ''));
                if ($mac !== '') $knownPausedByUs[$mac] = $m;
            }
            foreach ($preExisting as $m) {
                $mac = strtolower((string)($m['mac'] ?? ''));
                if ($mac !== '') $knownPreExisting[$mac] = true;
            }

            // Walk current device list, classify each:
            //   - connected & paused: nothing to do
            //   - connected & unpaused & known-by-us: BYPASS — re-pause
            //   - connected & unpaused & pre-existing: it was customer's own
            //     pause, customer has reversed it, leave alone (we don't own it)
            //   - connected & unpaused & unknown: NEW DEVICE — pause it
            $newDevices = [];
            $bypassDevices = [];
            foreach (($status['clients'] ?? []) as $c) {
                $mac = strtolower((string)($c['mac'] ?? ''));
                $cid = (int)($c['fingerprint'] ?? $c['client_id'] ?? 0);
                if ($mac === '' || $cid <= 0) continue;
                if (!empty($c['paused'])) continue; // already paused, fine

                if (isset($knownPausedByUs[$mac])) {
                    // We paused this — but Starlink says it's not paused.
                    // Someone unpaused it via the mobile app. Bypass.
                    $bypassDevices[] = [
                        'mac'       => $mac,
                        'client_id' => $cid,
                        'name'      => (string)($c['name'] ?? ''),
                    ];
                } elseif (isset($knownPreExisting[$mac])) {
                    // Customer's own pause — they (or someone with
                    // legitimate parental control access) chose to unpause.
                    // Not our business. Leave alone.
                    continue;
                } else {
                    // Unknown device — joined after our initial block.
                    $newDevices[] = [
                        'mac'       => $mac,
                        'client_id' => $cid,
                    ];
                }
            }

            $checked++;

            $hadBypass = !empty($bypassDevices);
            $hadNewDevice = !empty($newDevices);

            // ── Re-pause new devices ────────────────────────────────────
            $pausedThisRun = [];
            foreach ($newDevices as $d) {
                $r = $this->drPauseClient($routerId, $d['mac'], $d['client_id']);
                if ($r['ok']) {
                    $pausedThisRun[] = $d;
                    $newlyPaused++;
                    $this->log($clientId, $routerId, 'extend_pause_device', true, 0, '',
                        "mac={$d['mac']} (new device joined after initial block)", 1);
                } else {
                    $pauseFailures++;
                    $this->log($clientId, $routerId, 'extend_pause_device', false,
                        $r['grpc_status'] ?? null, $r['grpc_message'] ?? '',
                        "mac={$d['mac']}", 1);
                }
            }

            // ── Re-pause bypassed devices (the bypass-detection step) ───
            foreach ($bypassDevices as $d) {
                $r = $this->drPauseClient($routerId, $d['mac'], $d['client_id']);
                if ($r['ok']) {
                    $bypassRepaused++;
                    $this->log($clientId, $routerId, 'bypass_repause', true, 0, '',
                        "mac={$d['mac']} name={$d['name']} — staff bypassed via Starlink app, re-paused", 1);
                } else {
                    $pauseFailures++;
                    $this->log($clientId, $routerId, 'bypass_repause', false,
                        $r['grpc_status'] ?? null, $r['grpc_message'] ?? '',
                        "mac={$d['mac']}", 1);
                }
            }

            // ── Update state row ────────────────────────────────────────
            // Always bump last_attempt_at (de-dup window).
            // If bypass detected: increment bypass_event_count, record timestamp.
            // If new devices paused: append to paused_macs_json.
            $newPausedMacs = !empty($pausedThisRun)
                ? array_merge($pausedMacs, $pausedThisRun)
                : $pausedMacs;

            if ($hadBypass) {
                $bypassCount = (int)$row['bypass_event_count'] + count($bypassDevices);
                $this->pdo->prepare(
                    "UPDATE " . self::TABLE_STATE . "
                     SET paused_macs_json = ?,
                         last_attempt_at = datetime('now'),
                         bypass_event_count = ?,
                         last_bypass_at = datetime('now')
                     WHERE client_id = ? AND router_id = ?"
                )->execute([
                    json_encode($newPausedMacs),
                    $bypassCount,
                    $clientId, $routerId,
                ]);

                // Decide whether to alert admin. Threshold: 3+ bypass events
                // total. Cooldown: don't alert if we alerted in the last 6h.
                $shouldAlert = false;
                if ($bypassCount >= 3) {
                    $alertedAt = $row['bypass_alerted_at'] ?? null;
                    if (!$alertedAt) {
                        $shouldAlert = true;
                    } else {
                        $alertedTs = strtotime((string)$alertedAt);
                        if ($alertedTs && (time() - $alertedTs) > 21600) { // 6h
                            $shouldAlert = true;
                        }
                    }
                }

                if ($shouldAlert) {
                    $this->sendBypassAlert($clientId, $routerId, $bypassCount, $bypassDevices);
                    $this->pdo->prepare(
                        "UPDATE " . self::TABLE_STATE . "
                         SET bypass_alerted_at = datetime('now')
                         WHERE client_id = ? AND router_id = ?"
                    )->execute([$clientId, $routerId]);
                    $bypassAlertsSent++;
                }
            } elseif ($hadNewDevice) {
                $this->pdo->prepare(
                    "UPDATE " . self::TABLE_STATE . "
                     SET paused_macs_json = ?, last_attempt_at = datetime('now')
                     WHERE client_id = ? AND router_id = ?"
                )->execute([json_encode($newPausedMacs), $clientId, $routerId]);
            } else {
                // Nothing happened — just bump timestamp
                $this->pdo->prepare(
                    "UPDATE " . self::TABLE_STATE . "
                     SET last_attempt_at = datetime('now')
                     WHERE client_id = ? AND router_id = ?"
                )->execute([$clientId, $routerId]);
            }
        }

        return [
            'queue_size'         => count($rows),
            'checked'            => $checked,
            'newly_paused'       => $newlyPaused,
            'bypass_repaused'    => $bypassRepaused,
            'bypass_alerts_sent' => $bypassAlertsSent,
            'pause_failures'     => $pauseFailures,
        ];
    }

    /**
     * Send the admin WA alert when bypass-event threshold is hit.
     * Uses NotificationService → Accounts sender → whatsapp_admin_phone
     * config key. Fails silently if notify isn't available.
     */
    private function sendBypassAlert(int $clientId, string $routerId, int $bypassCount, array $bypassDevices): void
    {
        if (!$this->notify) return;
        $adminPhone = trim((string)($this->config['whatsapp_admin_phone'] ?? ''));
        if ($adminPhone === '') return;

        // Look up customer name for the alert (UCRM API call — fail-soft)
        $customerName = "Client #{$clientId}";
        try {
            if ($this->store) {
                $row = $this->pdo->prepare(
                    "SELECT customer_name FROM tickets WHERE crm_client_id = ? ORDER BY id DESC LIMIT 1"
                );
                $row->execute([$clientId]);
                $name = $row->fetchColumn();
                if ($name) $customerName = (string)$name;
            }
        } catch (\Throwable $_) {}

        $deviceLines = '';
        foreach (array_slice($bypassDevices, 0, 5) as $d) {
            $name = $d['name'] !== '' ? $d['name'] : '(unnamed)';
            $deviceLines .= "• {$name} ({$d['mac']})\n";
        }
        if (count($bypassDevices) > 5) {
            $deviceLines .= "• ... and " . (count($bypassDevices) - 5) . " more\n";
        }

        try {
            $this->notify->sendVia('accounts', $adminPhone,
                "⚠️ *DishNet Auto-Block Bypass Detected*\n\n"
                . "Customer: *{$customerName}* (#{$clientId})\n"
                . "Router: {$routerId}\n"
                . "Total bypass events: *{$bypassCount}*\n\n"
                . "Someone with Starlink account access has unpaused devices "
                . "we blocked. The system has automatically re-paused them:\n\n"
                . $deviceLines
                . "\nThe customer is still suspended in CRM. Please investigate "
                . "who is bypassing the auto-block.\n\n"
                . "— DishNet Auto-Block",
                'starlink_bypass_alert');
        } catch (\Throwable $_) {}
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CORE SUSPENSION LOGIC (one router)
    // ═══════════════════════════════════════════════════════════════════════

    private function suspendOneRouter(int $clientId, int $serviceId, array $router, string $triggeredBy, string $eventType): bool
    {
        $routerId  = (string)$router['router_id_full'];
        $kitSerial = (string)($router['kit_serial'] ?? '');
        $accNum    = (string)($router['account_number'] ?? '');
        $isBypass  = !empty($router['is_bypassed']);

        // Determine block mode for this suspension. Default is pause_only
        // unless config or per-router override says otherwise. Bypass-mode
        // dishes get pause_only forced (no WiFi to change).
        $configMode = (string)($this->config['starlink_block_default_mode'] ?? self::MODE_RENAME_ONLY);
        $blockMode  = in_array($configMode, [self::MODE_PAUSE_ONLY, self::MODE_RENAME_ONLY, self::MODE_FULL], true)
            ? $configMode
            : self::MODE_RENAME_ONLY;
        if ($isBypass) $blockMode = self::MODE_PAUSE_ONLY;

        // Increment attempt counter / create row in 'suspending' state
        $existing = $this->loadState($clientId, $routerId);
        $attemptNum = $existing ? ((int)$existing['attempt_count'] + 1) : 1;
        // Preserve mode if row already exists (e.g. cron retry should keep
        // the original mode, not flip mid-flight)
        if ($existing && !empty($existing['block_mode'])) {
            $blockMode = (string)$existing['block_mode'];
        }

        if (!$existing) {
            $this->upsertState([
                'client_id'           => $clientId,
                'crm_service_id'      => $serviceId,
                'kit_serial'          => $kitSerial,
                'router_id'           => $routerId,
                'account_number'      => $accNum,
                'state'               => self::STATE_SUSPENDING,
                'is_bypass_mode'      => $isBypass ? 1 : 0,
                'block_mode'          => $blockMode,
                'suspended_by'        => $triggeredBy,
                'triggered_by_event'  => $eventType,
                'attempt_count'       => 1,
            ]);
        } else {
            $this->updateAttempt($clientId, $routerId, $attemptNum);
        }

        // ── Step A: Save current SSID + password ────────────────────────────
        // Required for full and rename_only (both need original SSID for restore).
        // pause_only never touches credentials so there's nothing to save.
        $savedConfig = ['ssid_24' => '', 'ssid_5' => '', 'pass_24' => '', 'pass_5' => ''];
        if (in_array($blockMode, [self::MODE_FULL, self::MODE_RENAME_ONLY], true) && !$isBypass) {
            $cfg = $this->drGetWifiConfig($routerId);
            if (!$cfg['ok']) {
                $this->log($clientId, $routerId, 'save_state', false,
                    $cfg['grpc_status'] ?? null, $cfg['grpc_message'] ?? '',
                    'Failed to read current WiFi config — aborting full-mode suspend (cannot restore without it)',
                    $attemptNum);
                $this->setLastError($clientId, $routerId, 'save_state failed: ' . ($cfg['error'] ?? 'unknown'));
                return false;
            }
            $savedConfig = $cfg;
            $this->log($clientId, $routerId, 'save_state', true, 0, '',
                "ssid_24={$savedConfig['ssid_24']} ssid_5={$savedConfig['ssid_5']}", $attemptNum);

            // Persist saved credentials to state row
            $upd = $this->pdo->prepare(
                "UPDATE " . self::TABLE_STATE . "
                 SET original_ssid_24 = ?, original_ssid_5 = ?,
                     original_pass_24 = ?, original_pass_5 = ?,
                     original_auth_type = ?
                 WHERE client_id = ? AND router_id = ?"
            );
            $upd->execute([
                $savedConfig['ssid_24'],
                $savedConfig['ssid_5'],
                $savedConfig['pass_24'],
                $savedConfig['pass_5'],
                $savedConfig['auth_type'] ?? 'wpa2',
                $clientId, $routerId,
            ]);
        } elseif ($isBypass) {
            $this->log($clientId, $routerId, 'skip_bypass', true, null, '',
                'Router in bypass mode — pause-only forced, no SSID/password change', $attemptNum);
        } else {
            // pause_only mode — log that we're skipping save_state by design
            $this->log($clientId, $routerId, 'skip_save_state', true, null, '',
                "block_mode={$blockMode} — credentials never change, no save needed", $attemptNum);
        }

        // ── Step B: Fetch live client list ─────────────────────────────────
        $clients = $this->drGetWifiStatus($routerId);
        $liveMacs = [];
        $preExistingPaused = [];
        if ($clients['ok']) {
            foreach (($clients['clients'] ?? []) as $c) {
                $mac = (string)($c['mac'] ?? '');
                $cid = (int)($c['fingerprint'] ?? $c['client_id'] ?? 0);
                if (!$mac || !$cid) continue;
                if (!empty($c['paused'])) {
                    // Already paused before we touched anything — don't unpause on restore
                    $preExistingPaused[] = ['mac' => $mac, 'client_id' => $cid];
                } else {
                    $liveMacs[] = ['mac' => $mac, 'client_id' => $cid];
                }
            }
            $this->log($clientId, $routerId, 'list_clients', true, 0, '',
                'live=' . count($liveMacs) . ' pre_paused=' . count($preExistingPaused), $attemptNum);
        } else {
            // Status fetch failed — log but continue if full mode (password change
            // can still proceed). For pause_only with no client list, we have
            // nothing to do — partial fail.
            $this->log($clientId, $routerId, 'list_clients', false,
                $clients['grpc_status'] ?? null, $clients['grpc_message'] ?? '',
                'Could not fetch live client list', $attemptNum);
        }

        // Persist pause lists (in case we crash before completing — restore can read them)
        $upd = $this->pdo->prepare(
            "UPDATE " . self::TABLE_STATE . "
             SET paused_macs_json = ?, pre_existing_paused_json = ?
             WHERE client_id = ? AND router_id = ?"
        );
        $upd->execute([
            json_encode($liveMacs),
            json_encode($preExistingPaused),
            $clientId, $routerId,
        ]);

        // ── Step C: Pause every live device ────────────────────────────────
        $pausedOk = 0; $pausedFail = 0;
        foreach ($liveMacs as $m) {
            $r = $this->drPauseClient($routerId, $m['mac'], $m['client_id']);
            if ($r['ok']) {
                $pausedOk++;
                $this->log($clientId, $routerId, 'pause_device', true, 0, '',
                    "mac={$m['mac']} client_id={$m['client_id']}", $attemptNum);
            } else {
                $pausedFail++;
                $this->log($clientId, $routerId, 'pause_device', false,
                    $r['grpc_status'] ?? null, $r['grpc_message'] ?? '',
                    "mac={$m['mac']} client_id={$m['client_id']}", $attemptNum);
            }
        }

        // ── Step D: Change SSID (and optionally password) ──────────────────
        // full:        rename SSID + set random suspension password.
        // rename_only: rename SSID only — original password kept unchanged.
        // pause_only:  skip entirely.
        $passwordChanged = false;
        if ($blockMode === self::MODE_RENAME_ONLY && !$isBypass) {
            $newSsid = (string)($this->config['starlink_block_ssid'] ?? 'DishNet-PAY-NOW');
            // Keep original password — pass savedConfig['pass_24'] verbatim
            $origPass = (string)$savedConfig['pass_24'];
            $r = $this->drChangePassword($routerId, $newSsid, $origPass);
            if ($r['ok']) {
                $passwordChanged = true; // reusing flag — means "SSID change succeeded"
                $this->log($clientId, $routerId, 'change_ssid_only', true, 0, '',
                    "new_ssid={$newSsid} password_unchanged=true", $attemptNum);
                $upd = $this->pdo->prepare(
                    "UPDATE " . self::TABLE_STATE . "
                     SET suspension_ssid = ?, suspension_pass = ''
                     WHERE client_id = ? AND router_id = ?"
                );
                $upd->execute([$newSsid, $clientId, $routerId]);
            } else {
                $this->log($clientId, $routerId, 'change_ssid_only', false,
                    $r['grpc_status'] ?? null, $r['grpc_message'] ?? '',
                    'SSID rename failed (rename_only mode)', $attemptNum);
            }
        } elseif ($blockMode === self::MODE_FULL && !$isBypass) {
            $newSsid = (string)($this->config['starlink_block_ssid'] ?? 'DishNet-PAY-NOW');
            $newPass = $this->generateSuspensionPassword();

            $r = $this->drChangePassword($routerId, $newSsid, $newPass);
            if ($r['ok']) {
                $passwordChanged = true;
                $this->log($clientId, $routerId, 'change_password', true, 0, '',
                    "new_ssid={$newSsid}", $attemptNum);

                // Save the suspension SSID/pass we set (for unblock UI / support visibility)
                $upd = $this->pdo->prepare(
                    "UPDATE " . self::TABLE_STATE . "
                     SET suspension_ssid = ?, suspension_pass = ?
                     WHERE client_id = ? AND router_id = ?"
                );
                $upd->execute([$newSsid, $newPass, $clientId, $routerId]);
            } else {
                $this->log($clientId, $routerId, 'change_password', false,
                    $r['grpc_status'] ?? null, $r['grpc_message'] ?? '',
                    'Password change failed', $attemptNum);
            }
        } else {
            // pause_only or bypass — no password change is the intended behavior
            $this->log($clientId, $routerId, 'skip_change_password', true, null, '',
                "block_mode={$blockMode} bypass=" . ($isBypass ? 'yes' : 'no'), $attemptNum);
        }

        // ── Step E: Mark final state ───────────────────────────────────────
        // Success criteria differs by mode:
        //   pause_only / bypass — success if all live pauses succeeded
        //   rename_only         — also requires SSID rename to succeed
        //   full                — also requires SSID+password change to succeed
        if (in_array($blockMode, [self::MODE_FULL, self::MODE_RENAME_ONLY], true) && !$isBypass) {
            $success = $passwordChanged && $pausedFail === 0;
        } else {
            $success = $pausedFail === 0;
        }

        if ($success) {
            $this->setState($clientId, $routerId, self::STATE_SUSPENDED, '');
            return true;
        } else {
            $err = '';
            if (in_array($blockMode, [self::MODE_FULL, self::MODE_RENAME_ONLY], true) && !$passwordChanged && !$isBypass) {
                $err = 'ssid_change_failed';
            } elseif ($pausedFail > 0) {
                $err = "pause_failed_{$pausedFail}_devices";
            } else {
                $err = 'unknown';
            }
            $this->setState($clientId, $routerId, self::STATE_PARTIAL_FAILED, $err);
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CORE RESTORE LOGIC (one router)
    // ═══════════════════════════════════════════════════════════════════════

    private function restoreOneRouter(array $row, string $triggeredBy): bool
    {
        $clientId = (int)$row['client_id'];
        $routerId = (string)$row['router_id'];
        $isBypass = (bool)$row['is_bypass_mode'];
        // Older rows (pre-v4.21.4) have no block_mode column — default to
        // 'full' for backward compatibility (their restoration path needs
        // SSID+password rewrite, just like before).
        $blockMode = (string)($row['block_mode'] ?? self::MODE_FULL);

        // Mark as restoring
        $upd = $this->pdo->prepare(
            "UPDATE " . self::TABLE_STATE . "
             SET state = ?, restore_started_at = datetime('now'),
                 restore_triggered_by = ?, last_attempt_at = datetime('now'),
                 attempt_count = attempt_count + 1
             WHERE client_id = ? AND router_id = ?"
        );
        $upd->execute([self::STATE_RESTORING, $triggeredBy, $clientId, $routerId]);

        $attemptNum = (int)$row['attempt_count'] + 1;

        // ── Step A: Restore SSID (+ password for full mode) ────────────────
        // full:        restore original SSID + password.
        // rename_only: restore original SSID; password was never changed so
        //              we pass the saved original password back (no-op on pass).
        // pause_only / bypass: skip — credentials were never touched.
        $passOk = true;
        if (in_array($blockMode, [self::MODE_FULL, self::MODE_RENAME_ONLY], true) && !$isBypass) {
            $origSsid = (string)$row['original_ssid_24'];
            $origPass = (string)$row['original_pass_24'];

            if (!$origSsid || !$origPass) {
                // We don't have the original — alert admin, don't pretend success
                $this->log($clientId, $routerId, 'restore_password', false, null, '',
                    'Original credentials missing from state row — cannot restore', $attemptNum);
                $this->alertAdmin(
                    "⚠️ *Restore Failed — Missing Credentials*\n\n"
                    . "Client #{$clientId} router {$routerId} has no saved original credentials. "
                    . "Manual WiFi reconfiguration required."
                );
                $passOk = false;
            } else {
                $r = $this->drChangePassword($routerId, $origSsid, $origPass);
                if ($r['ok']) {
                    $this->log($clientId, $routerId, 'restore_password', true, 0, '',
                        "ssid={$origSsid}", $attemptNum);
                } else {
                    $passOk = false;
                    $this->log($clientId, $routerId, 'restore_password', false,
                        $r['grpc_status'] ?? null, $r['grpc_message'] ?? '',
                        'Password restore failed', $attemptNum);
                }
            }
        } else {
            // pause_only or bypass — credentials were never changed, nothing to restore
            $this->log($clientId, $routerId, 'skip_restore_ssid', true, null, '',
                "block_mode={$blockMode} bypass=" . ($isBypass ? 'yes' : 'no'), $attemptNum);
        }

        // ── Step B: Unpause MACs that we paused (skip pre-existing) ───────
        $pausedMacs = json_decode((string)$row['paused_macs_json'], true) ?: [];
        $unpauseFail = 0;
        foreach ($pausedMacs as $m) {
            $r = $this->drUnpauseClient($routerId, (string)($m['mac'] ?? ''), (int)($m['client_id'] ?? 0));
            if ($r['ok']) {
                $this->log($clientId, $routerId, 'unpause_device', true, 0, '',
                    "mac={$m['mac']}", $attemptNum);
            } else {
                $unpauseFail++;
                $this->log($clientId, $routerId, 'unpause_device', false,
                    $r['grpc_status'] ?? null, $r['grpc_message'] ?? '',
                    "mac={$m['mac']}", $attemptNum);
            }
        }

        $success = $passOk && ($unpauseFail === 0);

        if ($success) {
            // Delete the state row — restore complete, customer is back to normal
            $del = $this->pdo->prepare("DELETE FROM " . self::TABLE_STATE . " WHERE client_id = ? AND router_id = ?");
            $del->execute([$clientId, $routerId]);
            return true;
        } else {
            // Leave row in 'restoring' state for cron to retry
            $this->setLastError($clientId, $routerId,
                ($passOk ? '' : 'password_restore_failed; ') . ($unpauseFail ? "unpause_failed_{$unpauseFail}" : ''));
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // VIP / CONFIG GUARDS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Returns true if this client should NOT be auto-blocked.
     *
     * VIP determination is two-layered:
     *   1. Explicit ID list (config: starlink_block_vip_clients) — instant,
     *      survives any UCRM tag reorganization. CSV string or array.
     *   2. UCRM tag — config: starlink_block_vip_tag_id (default 84) and
     *      starlink_block_vip_tag_name (default 'NO_AUTO_BLOCK').
     *
     * Tag check accepts an optional $freshClient parameter (v4.21.6+).
     * Caller can pass the freshly-fetched UCRM client object (from
     * $crm->get("clients/{id}")) to bypass the daily-refreshed cache and
     * get an accurate answer immediately. This matters because if admin
     * adds the NO_AUTO_BLOCK tag at 9am, the cache won't reflect it until
     * 3am the next day — without the fresh-client option, that's a 18+
     * hour window where a newly-tagged VIP could still get blocked.
     *
     * If $freshClient is null, falls back to ucrm_clients_cache.json.
     * Webhook callers should ALWAYS pass $freshClient.
     */
    public function isVipClient(int $clientId, ?array $freshClient = null): bool
    {
        // ── 1. Explicit client ID list (config: starlink_block_vip_clients) ──
        // CSV string or array. Survives any UCRM tag reorganization.
        $vipList = $this->config['starlink_block_vip_clients'] ?? [];
        if (is_array($vipList) && in_array($clientId, array_map('intval', $vipList), true)) {
            return true;
        }
        if (is_string($vipList) && $vipList !== '') {
            $ids = array_map('intval', array_filter(array_map('trim', explode(',', $vipList))));
            if (in_array($clientId, $ids, true)) return true;
        }

        // ── 2. UCRM tag check ────────────────────────────────────────────────
        // Tag matching is two-pass:
        //   (a) by tag ID  — config: starlink_block_vip_tag_id (default 84)
        //                    Robust to tag rename in CRM admin.
        //   (b) by tag name — config: starlink_block_vip_tag_name (default 'NO_AUTO_BLOCK')
        //                    Fallback if tag was deleted and recreated with new ID.
        // Production tag (DishNet CRM): https://crm.dishnetafrica.com/crm/system/other/client-tags/84
        $vipTagId   = (int)($this->config['starlink_block_vip_tag_id'] ?? 84);
        $vipTagName = (string)($this->config['starlink_block_vip_tag_name'] ?? 'NO_AUTO_BLOCK');

        // Walk tags from $freshClient (preferred) or fall back to cache
        $tagsToCheck = null;
        if (is_array($freshClient) && (int)($freshClient['id'] ?? 0) === $clientId) {
            $tagsToCheck = $freshClient['tags'] ?? [];
        } else {
            try {
                $cache = $this->store->load('ucrm_clients_cache.json') ?? [];
                foreach ($cache as $c) {
                    if ((int)($c['id'] ?? 0) !== $clientId) continue;
                    $tagsToCheck = $c['tags'] ?? [];
                    break;
                }
            } catch (\Throwable $e) {
                // Cache unavailable — fail open (treat as non-VIP). Better to
                // occasionally block a VIP than fail silently when cache is
                // broken. Explicit config list above is the backstop.
                $tagsToCheck = null;
            }
        }

        if (is_array($tagsToCheck)) {
            foreach ($tagsToCheck as $tag) {
                if (is_array($tag)) {
                    // Primary: ID match
                    if ($vipTagId > 0 && (int)($tag['id'] ?? 0) === $vipTagId) return true;
                    // Fallback: name match (case-insensitive)
                    $tagName = (string)($tag['name'] ?? '');
                    if ($vipTagName !== '' && strcasecmp($tagName, $vipTagName) === 0) return true;
                } else {
                    // Legacy string tag (older cache format)
                    if ($vipTagName !== '' && strcasecmp((string)$tag, $vipTagName) === 0) return true;
                }
            }
        }

        return false;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CLIENT → KIT → ROUTER RESOLUTION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Returns array of router descriptors for this client.
     * Each item: ['router_id_full', 'kit_serial', 'account_number', 'is_bypassed']
     */
    private function resolveClientRouters(int $clientId): array
    {
        // Step 1: client → KIT serials (Starlink Finance plugin sl_kits.json)
        $kitSerials = $this->getClientKitSerials($clientId);
        if (empty($kitSerials)) return [];

        // Step 2: KIT → router (Data Report plugin wifi_router_map.json)
        $routerMap = $this->loadRouterMap();
        if (empty($routerMap)) return [];

        $found = [];
        foreach ($kitSerials as $ks) {
            foreach ($routerMap as $rid => $rinfo) {
                if (strcasecmp((string)($rinfo['kit_serial'] ?? ''), $ks) === 0) {
                    $found[] = [
                        'router_id_full' => (string)($rinfo['router_id_full'] ?? ('Router-' . $rid)),
                        'kit_serial'     => $ks,
                        'account_number' => (string)($rinfo['account_number'] ?? ''),
                        'is_bypassed'    => !empty($rinfo['is_bypassed']),
                    ];
                }
            }
        }
        return $found;
    }

    /**
     * Read sl_kits.json from sibling Starlink Finance plugin and find KIT serials
     * assigned to this UCRM client_id. Returns array of kit_serial strings.
     *
     * v4.21.24: also extracts KIT serials from UCRM service.name regex when
     * sl_kits.json doesn't have the client. Many production customers have
     * KITs embedded in their service titles (e.g. "Site : ACME (KIT401723651PG7)
     * : Service Plan Starlink Residential") but no entry in sl_kits.json, so
     * the json-only lookup returned empty for them. Service-name regex picks
     * up these cases as a fallback.
     */
    private function getClientKitSerials(int $clientId): array
    {
        // Plugin sibling path resolution — Hybrid plugin sits at .../_plugins/dishnet-hybrid-telecom
        // Starlink Finance sits at .../_plugins/dishnet-starlink-finance
        $candidates = [
            dirname(__DIR__, 2) . '/dishnet-starlink-finance/data/sl_kits.json',
            dirname(__DIR__, 1) . '/../dishnet-starlink-finance/data/sl_kits.json',
        ];

        $kitsData = null;
        foreach ($candidates as $p) {
            if (file_exists($p)) {
                $raw = @file_get_contents($p);
                if ($raw !== false) {
                    $kitsData = json_decode($raw, true);
                    if (is_array($kitsData)) break;
                }
            }
        }

        $found = [];

        // Source A: sl_kits.json (when present + has the client)
        if (is_array($kitsData)) {
            foreach ($kitsData as $key => $val) {
                if (!is_array($val)) continue;
                $cid = (int)(
                    $val['client_id']
                    ?? $val['crm_client_id']
                    ?? $val['ucrm_client_id']
                    ?? $val['clientId']
                    ?? $val['crmClientId']
                    ?? $val['customer_id']
                    ?? $val['customerId']
                    ?? 0
                );
                if ($cid !== $clientId) continue;
                $ks = (string)(
                    $val['kit_serial']
                    ?? $val['kit']
                    ?? $val['serial']
                    ?? $val['kitSerial']
                    ?? (is_string($key) ? $key : '')
                );
                if ($ks !== '') $found[] = strtoupper(trim($ks));
            }
        }

        // Source B: UCRM service.name regex fallback. Only call CRM if Source A
        // came up empty — saves a network round-trip for the common case.
        if (empty($found)) {
            try {
                if (!class_exists('CrmApiClient')) {
                    @require_once __DIR__ . '/CrmApiClient.php';
                }
                if (class_exists('CrmApiClient')) {
                    $baseUrl = (string)($this->config['crm_base_url'] ?? '');
                    $token   = (string)($this->config['crm_auth_token'] ?? $this->config['crm_app_key'] ?? '');
                    if ($baseUrl !== '' && $token !== '') {
                        $crm = new \CrmApiClient(rtrim($baseUrl, '/'), $token, 'x-auth-token');
                        $svcs = $crm->get("clients/{$clientId}/services");
                        if (is_array($svcs)) {
                            $regex = '/\bKIT[A-Z0-9]{8,}\b/i';
                            foreach ($svcs as $s) {
                                $name = (string)($s['name'] ?? '');
                                if ($name === '') continue;
                                if (preg_match_all($regex, $name, $m)) {
                                    foreach ($m[0] as $kit) {
                                        $found[] = strtoupper(trim($kit));
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Best-effort; fall through with whatever we have
                $this->log($clientId, '', 'kit_lookup_warn', false, null, '', 'service-name regex fallback threw: ' . $e->getMessage());
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Read wifi_router_map.json from sibling Data Report plugin.
     */
    private function loadRouterMap(): array
    {
        $candidates = [
            dirname(__DIR__, 2) . '/dishnet-data-report/data/wifi_router_map.json',
            dirname(__DIR__, 1) . '/../dishnet-data-report/data/wifi_router_map.json',
        ];
        foreach ($candidates as $p) {
            if (file_exists($p)) {
                $raw = @file_get_contents($p);
                if ($raw !== false) {
                    $data = json_decode($raw, true);
                    if (is_array($data)) return $data;
                }
            }
        }
        return [];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DATA REPORT PLUGIN HTTP CALLS (loopback gRPC bridge)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Resolve the public URL of dishnet-data-report's public.php.
     * Tries config override first, then ucrm.json's ucrmPublicUrl + standard path.
     */
    private function resolveDataReportUrl(): string
    {
        $override = trim((string)($this->config['data_report_plugin_url'] ?? ''));
        if ($override !== '') return rtrim($override, '/');

        $ucrmJson = null;
        foreach ([$this->dataDir . '/../ucrm.json', $this->dataDir . '/ucrm.json'] as $p) {
            if (file_exists($p)) {
                $ucrmJson = json_decode((string)@file_get_contents($p), true);
                if (is_array($ucrmJson)) break;
            }
        }
        if (!is_array($ucrmJson)) return '';

        $base = (string)($ucrmJson['ucrmPublicUrl'] ?? '');
        if (!$base) return '';
        // Normalize: strip /api/vN.M, /crm, then re-append /crm
        $base = preg_replace('#/api/v\d+\.\d+/?$#', '', $base);
        $base = rtrim($base, '/');
        if (substr($base, -4) === '/crm') $base = substr($base, 0, -4);
        $base = rtrim($base, '/') . '/crm';

        return $base . '/_plugins/dishnet-data-report/public.php';
    }

    private function drGetWifiConfig(string $routerId): array
    {
        $r = $this->drHttp(['action' => 'dr_wifi_get_config', 'router_id' => $routerId]);
        if (!$r['ok']) return $r;

        // Data Report returns: {ok, router_id, networks: [{ssid, password, auth_type, band, band_num, disabled}, ...]}
        // band_num: 2 = 2.4GHz, 5 = 5GHz, 0 = both/unknown (cache fallback)
        // We pick the primary (non-disabled) entry per band.
        $j = $r['json'] ?? [];
        if (empty($j['ok'])) {
            return ['ok' => false, 'error' => $j['error'] ?? 'wifi_get_config returned ok=false',
                    'grpc_status' => $j['grpc_status'] ?? null, 'grpc_message' => $j['grpc_message'] ?? ''];
        }

        $ssid24 = ''; $pass24 = ''; $ssid5 = ''; $pass5 = ''; $auth = 'wpa2';
        foreach (($j['networks'] ?? []) as $net) {
            if (!empty($net['disabled'])) continue;
            $band = (int)($net['band_num'] ?? 0);
            if ($band === 2 || $band === 0) {
                if ($ssid24 === '') {
                    $ssid24 = (string)($net['ssid'] ?? '');
                    $pass24 = (string)($net['password'] ?? '');
                    $auth   = (string)($net['auth_type'] ?? $auth);
                }
            }
            if ($band === 5) {
                if ($ssid5 === '') {
                    $ssid5 = (string)($net['ssid'] ?? '');
                    $pass5 = (string)($net['password'] ?? '');
                }
            }
        }
        // 5GHz often mirrors 2.4 — fall back if blank
        if ($ssid5 === '') { $ssid5 = $ssid24; $pass5 = $pass24; }

        if ($ssid24 === '' && $pass24 === '') {
            return ['ok' => false, 'error' => 'wifi_get_config: no usable network entry parsed',
                    'grpc_status' => null, 'grpc_message' => ''];
        }

        return [
            'ok'        => true,
            'ssid_24'   => $ssid24,
            'ssid_5'    => $ssid5,
            'pass_24'   => $pass24,
            'pass_5'    => $pass5,
            'auth_type' => $auth,
        ];
    }

    private function drGetWifiStatus(string $routerId): array
    {
        // Action name is dr_wifi_get_status (returns clients[] with mac, fingerprint, paused).
        $r = $this->drHttp(['action' => 'dr_wifi_get_status', 'router_id' => $routerId]);
        if (!$r['ok']) return $r;
        $j = $r['json'] ?? [];
        if (empty($j['ok'])) {
            return ['ok' => false, 'error' => $j['error'] ?? 'wifi_get_status returned ok=false',
                    'grpc_status' => $j['grpc_status'] ?? null, 'grpc_message' => $j['grpc_message'] ?? ''];
        }
        return ['ok' => true, 'clients' => $j['clients'] ?? []];
    }

    private function drPauseClient(string $routerId, string $mac, int $clientFp): array
    {
        return $this->drHttpPost('dr_wifi_pause_client', [
            'router_id' => $routerId,
            'mac'       => $mac,
            'client_id' => $clientFp,
            'by'        => 'auto_block',
        ]);
    }

    private function drUnpauseClient(string $routerId, string $mac, int $clientFp): array
    {
        return $this->drHttpPost('dr_wifi_unpause_client', [
            'router_id' => $routerId,
            'mac'       => $mac,
            'client_id' => $clientFp,
            'by'        => 'auto_block',
        ]);
    }

    private function drChangePassword(string $routerId, string $ssid, string $password): array
    {
        return $this->drHttpPost('dr_wifi_change_password', [
            'router_id' => $routerId,
            'ssid'      => $ssid,
            'password'  => $password,
            'auth_type' => 'wpa2',
        ]);
    }

    private function drHttp(array $params): array
    {
        if ($this->drPluginUrl === '') {
            return ['ok' => false, 'error' => 'data-report plugin URL not configured'];
        }
        $url = $this->drPluginUrl . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT_SEC,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) return ['ok' => false, 'error' => "curl: {$err}", 'http_code' => $code];
        $j = json_decode((string)$body, true);
        return ['ok' => true, 'json' => is_array($j) ? $j : [], 'http_code' => $code, 'raw' => (string)$body];
    }

    private function drHttpPost(string $action, array $payload): array
    {
        if ($this->drPluginUrl === '') {
            return ['ok' => false, 'error' => 'data-report plugin URL not configured'];
        }
        $url = $this->drPluginUrl . '?action=' . urlencode($action);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT_SEC,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) return ['ok' => false, 'error' => "curl: {$err}", 'http_code' => $code];
        $j = json_decode((string)$body, true);
        if (!is_array($j)) return ['ok' => false, 'error' => 'non-JSON response', 'raw' => (string)$body, 'http_code' => $code];
        // Bubble Data Report's ok flag up unchanged
        return [
            'ok'           => !empty($j['ok']),
            'error'        => $j['error'] ?? '',
            'grpc_status'  => $j['grpc_status'] ?? null,
            'grpc_message' => $j['grpc_message'] ?? '',
            'json'         => $j,
            'http_code'    => $code,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STATE / LOG HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    public function loadState(int $clientId, string $routerId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM " . self::TABLE_STATE . " WHERE client_id = ? AND router_id = ? LIMIT 1");
        $stmt->execute([$clientId, $routerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function loadStateForClient(int $clientId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM " . self::TABLE_STATE . " WHERE client_id = ?");
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function upsertState(array $data): void
    {
        $cols = ['client_id','crm_service_id','kit_serial','router_id','account_number',
                 'state','is_bypass_mode','block_mode','suspended_by','triggered_by_event','attempt_count',
                 'last_attempt_at'];
        $vals = [
            (int)($data['client_id'] ?? 0),
            (int)($data['crm_service_id'] ?? 0),
            (string)($data['kit_serial'] ?? ''),
            (string)($data['router_id'] ?? ''),
            (string)($data['account_number'] ?? ''),
            (string)($data['state'] ?? self::STATE_SUSPENDING),
            (int)($data['is_bypass_mode'] ?? 0),
            (string)($data['block_mode'] ?? self::MODE_PAUSE_ONLY),
            (string)($data['suspended_by'] ?? 'webhook'),
            (string)($data['triggered_by_event'] ?? ''),
            (int)($data['attempt_count'] ?? 1),
            date('Y-m-d H:i:s'),
        ];
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = "INSERT INTO " . self::TABLE_STATE . " (" . implode(',', $cols) . ") VALUES ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($vals);
    }

    private function updateAttempt(int $clientId, string $routerId, int $attemptNum): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE " . self::TABLE_STATE . "
             SET attempt_count = ?, last_attempt_at = datetime('now')
             WHERE client_id = ? AND router_id = ?"
        );
        $stmt->execute([$attemptNum, $clientId, $routerId]);
    }

    private function setState(int $clientId, string $routerId, string $state, string $err = ''): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE " . self::TABLE_STATE . "
             SET state = ?, last_error = ?, last_attempt_at = datetime('now')
             WHERE client_id = ? AND router_id = ?"
        );
        $stmt->execute([$state, $err, $clientId, $routerId]);
    }

    private function setLastError(int $clientId, string $routerId, string $err): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE " . self::TABLE_STATE . "
             SET last_error = ?, last_attempt_at = datetime('now')
             WHERE client_id = ? AND router_id = ?"
        );
        $stmt->execute([$err, $clientId, $routerId]);
    }

    private function markErrorManualRequired(int $clientId, string $routerId): void
    {
        $this->setState($clientId, $routerId, self::STATE_ERROR_MANUAL,
            'auto_retry_exhausted_after_' . self::MAX_RETRY_ATTEMPTS . '_attempts');
        $this->alertAdmin(
            "🚨 *Starlink Auto-Block Manual Required*\n\n"
            . "Client #{$clientId} router {$routerId} has failed " . self::MAX_RETRY_ATTEMPTS
            . " retry attempts. Auto-retry stopped.\n\n"
            . "Investigate via Customer 360 or sl_suspension_log table."
        );
    }

    private function log(int $clientId, string $routerId, string $action, bool $success,
                         ?int $grpcStatus, string $grpcMessage, string $detail = '', int $attemptNum = 1): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO " . self::TABLE_LOG . "
                 (client_id, router_id, action, success, grpc_status, grpc_message, detail, attempt_number)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $clientId, $routerId, $action, $success ? 1 : 0,
                $grpcStatus, $grpcMessage, $detail, $attemptNum
            ]);
        } catch (\Throwable $e) {
            // Never let logging failure break the operation
            error_log('[StarlinkBlockService] log write failed: ' . $e->getMessage());
        }
    }

    private function generateSuspensionPassword(): string
    {
        // Per spec: DishNet-Suspended-NNNN (4 random digits, easy to read aloud)
        return 'DishNet-Suspended-' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function alertAdmin(string $msg): void
    {
        if (!$this->notify) return;
        try {
            $adminPhone = (string)($this->config['whatsapp_admin_phone'] ?? '');
            if ($adminPhone === '') return;
            $this->notify->sendVia('accounts', $adminPhone, $msg, 'ops_starlink_block_alert');
        } catch (\Throwable $e) {
            error_log('[StarlinkBlockService] admin alert failed: ' . $e->getMessage());
        }
    }
}
