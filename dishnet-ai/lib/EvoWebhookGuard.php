<?php
declare(strict_types=1);

/**
 * EvoWebhookGuard — validates inbound Evolution API webhook requests.
 *
 * IMPORTANT, and worth being plain about: Evolution API v2 does NOT sign
 * webhook payloads. There is no HMAC to verify, so no amount of code here can
 * cryptographically prove a request came from Evolution. What we can do is:
 *
 *   1. Require a shared secret that only Evolution knows (placed in the
 *      webhook URL when the instance is configured, or sent as a header on
 *      builds that support webhook.headers).
 *   2. Only accept instances we have explicitly mapped to a channel.
 *   3. Only accept event types we handle.
 *   4. Reject messages whose timestamp is outside a replay window.
 *   5. Reject a message id we have already processed.
 *
 * That is defence in depth against the realistic threat: someone who learns
 * the webhook URL and posts fake customer messages to make the AI reply to
 * arbitrary numbers, or to create leads and tickets.
 *
 * The webhook this replaces (evo_webhook.php) performs NONE of these checks —
 * it accepts any POST from anyone.
 *
 * PHP 7.4 compatible.
 */
class EvoWebhookGuard
{
    /** Events we act on. Anything else is acknowledged and dropped. */
    const ALLOWED_EVENTS = [
        'messages.upsert',
        'messages.update',
        'connection.update',
    ];

    /** A message older than this is treated as a replay. */
    const REPLAY_WINDOW_SECONDS = 900;   // 15 minutes

    private \PDO   $pdo;
    private string $secret;

    public function __construct(\PDO $pdo, array $config)
    {
        $this->pdo    = $pdo;
        $this->secret = trim((string)($config['evo_webhook_secret'] ?? ''));
        $this->ensureTable();
    }

    /**
     * Is the caller authorised?
     *
     * Accepts the secret from either:
     *   - the X-DishNet-Token header (Evolution builds supporting webhook.headers)
     *   - a ?token= query parameter (works on every v2 build)
     *
     * Fails closed: an unconfigured secret rejects everything rather than
     * silently running open, which is how the current webhook behaves.
     */
    public function authenticate(): array
    {
        if ($this->secret === '') {
            return [false, 'Webhook secret is not configured — refusing all requests'];
        }

        $presented = '';
        foreach (['HTTP_X_DISHNET_TOKEN', 'HTTP_X_WEBHOOK_TOKEN'] as $h) {
            if (!empty($_SERVER[$h])) { $presented = trim((string)$_SERVER[$h]); break; }
        }
        if ($presented === '' && !empty($_GET['token'])) {
            $presented = trim((string)$_GET['token']);
        }
        if ($presented === '') {
            return [false, 'Missing webhook token'];
        }
        if (!hash_equals($this->secret, $presented)) {
            return [false, 'Invalid webhook token'];
        }
        return [true, ''];
    }

    /** Normalise Evolution's event name, which varies in case across builds. */
    public static function normaliseEvent(array $payload): string
    {
        $event = (string)($payload['event'] ?? '');
        return str_replace('_', '.', mb_strtolower(trim($event)));
    }

    public function isAllowedEvent(string $event): bool
    {
        return in_array($event, self::ALLOWED_EVENTS, true);
    }

    /**
     * Is this message inside the replay window?
     *
     * Evolution sends messageTimestamp in seconds. A missing timestamp is
     * allowed through — some builds omit it on non-message events, and the
     * message-id dedup below is the real duplicate defence.
     */
    public function isFresh(?int $messageTimestamp): bool
    {
        if ($messageTimestamp === null || $messageTimestamp <= 0) return true;
        $age = time() - $messageTimestamp;
        // Tolerate modest clock skew in the future direction.
        return $age <= self::REPLAY_WINDOW_SECONDS && $age >= -300;
    }

    /**
     * Claim a message id. Returns true when this is the first time we have
     * seen it, false when it is a duplicate.
     *
     * Uses INSERT against a UNIQUE index rather than SELECT-then-INSERT, so two
     * concurrent deliveries of the same message cannot both win.
     */
    public function claim(string $messageId, string $instance, string $event): bool
    {
        $messageId = trim($messageId);
        if ($messageId === '') return true;   // nothing to dedup on

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO evo_webhook_seen (message_id, instance, event, received_at)
                 VALUES (?, ?, ?, datetime('now'))"
            );
            $stmt->execute([$messageId, $instance, $event]);
            return true;
        } catch (\PDOException $e) {
            // UNIQUE violation = we already handled this message.
            return false;
        }
    }

    /** Housekeeping. Cheap, and keeps the dedup table from growing forever. */
    public function prune(int $olderThanHours = 72): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "DELETE FROM evo_webhook_seen WHERE received_at < datetime('now', ?)"
            );
            $stmt->execute(['-' . $olderThanHours . ' hours']);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Log a webhook request without leaking anything sensitive.
     * Never pass the raw body here — it contains customer message content.
     */
    public static function safeLogLine(string $event, string $instance, string $outcome): string
    {
        return sprintf(
            '[evo_webhook] event=%s instance=%s outcome=%s ip=%s',
            $event !== '' ? $event : '-',
            $instance !== '' ? $instance : '-',
            $outcome,
            isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-'
        );
    }

    private function ensureTable(): void
    {
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS evo_webhook_seen (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    message_id  TEXT NOT NULL,
                    instance    TEXT,
                    event       TEXT,
                    received_at TEXT NOT NULL DEFAULT (datetime('now'))
                )"
            );
            $this->pdo->exec(
                'CREATE UNIQUE INDEX IF NOT EXISTS idx_evo_seen_msgid ON evo_webhook_seen(message_id)'
            );
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_evo_seen_time ON evo_webhook_seen(received_at)'
            );
        } catch (\Throwable $e) {
            error_log('[EvoWebhookGuard] table init failed: ' . $e->getMessage());
        }
    }
}
