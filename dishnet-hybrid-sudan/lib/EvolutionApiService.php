<?php
declare(strict_types=1);

/**
 * EvolutionApiService — DishNet's Evolution API adapter.
 *
 * One place that knows how to talk to Evolution API. Nothing else in the plugin
 * should build an Evolution URL or hold the API key.
 *
 * Verified against Evolution API v2.3.7 (the version the DishNet instance
 * reports). Endpoint set is the intersection of the two Evolution clients
 * already running in production here — lib/EvolutionApiClient.php and
 * ShopBot's EvolutionGateway — so nothing below is speculative.
 *
 * Channel routing: DishNet runs one WhatsApp number per business channel.
 * This class maps channel <-> instance in both directions, because the webhook
 * needs instance -> channel and the sender needs channel -> instance.
 *
 * Configuration lives in kyc_config.json inside UCRM's pluginDataDir. That
 * directory is outside the plugin tree and outside git, which is where the API
 * key belongs. There is no .env in a UCRM plugin — this store IS the
 * plugin's configuration mechanism.
 *
 *   evo_api_url            https://evo.example.host       (no trailing slash)
 *   evo_api_key            <secret>
 *   evo_instance_sales     dishnet_sales
 *   evo_instance_support   dishnet_support
 *   evo_instance_account   dishnet_account
 *
 * PHP 7.4 compatible. Pure curl, no dependencies.
 */
class EvolutionApiService
{
    /** Business channels. These strings appear in wa_conversations.channel. */
    const CHANNEL_SALES   = 'sales';
    const CHANNEL_SUPPORT = 'support';
    const CHANNEL_ACCOUNT = 'account';

    /** Channels we accept from a webhook or a send call. */
    const CHANNELS = [self::CHANNEL_SALES, self::CHANNEL_SUPPORT, self::CHANNEL_ACCOUNT];

    private string $baseUrl;
    private string $apiKey;
    private int    $timeout;

    /** channel => instance name */
    private array $channelToInstance = [];
    /** lowercased instance name => channel */
    private array $instanceToChannel = [];

    private array $lastError = [];

    public function __construct(array $config, int $timeout = 20)
    {
        $this->baseUrl = rtrim(trim((string)($config['evo_api_url'] ?? '')), '/');
        $this->apiKey  = trim((string)($config['evo_api_key'] ?? ''));
        $this->timeout = $timeout;

        // Preferred, explicit per-channel config.
        $map = [
            self::CHANNEL_SALES   => trim((string)($config['evo_instance_sales']   ?? '')),
            self::CHANNEL_SUPPORT => trim((string)($config['evo_instance_support'] ?? '')),
            self::CHANNEL_ACCOUNT => trim((string)($config['evo_instance_account'] ?? '')),
        ];

        // Backward compatibility with the single-instance config that shipped
        // before three numbers existed. Only fills gaps — never overrides.
        if ($map[self::CHANNEL_SUPPORT] === '') {
            $map[self::CHANNEL_SUPPORT] = trim((string)($config['evo_instance_name'] ?? ''));
        }
        if ($map[self::CHANNEL_ACCOUNT] === '') {
            $map[self::CHANNEL_ACCOUNT] = trim((string)($config['evo_accounts_instance_name'] ?? ''));
        }

        foreach ($map as $channel => $instance) {
            if ($instance === '') continue;
            $this->channelToInstance[$channel] = $instance;
            $this->instanceToChannel[mb_strtolower($instance)] = $channel;
        }
    }

    // ── Configuration ────────────────────────────────────────────────────────

    /**
     * Enough to call Evolution at all: a URL and a key.
     *
     * Deliberately separate from isConfigured(). Listing and creating instances
     * must work BEFORE any channel is mapped -- otherwise you would need an
     * instance assigned in order to see the list you assign instances from.
     */
    public function canReachApi(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /** Fully set up: reachable AND at least one number mapped to an instance. */
    public function isConfigured(): bool
    {
        return $this->canReachApi() && $this->channelToInstance !== [];
    }

    /** Which instance serves this channel? Empty string when unmapped. */
    public function instanceFor(string $channel): string
    {
        return $this->channelToInstance[$channel] ?? '';
    }

    /**
     * Which channel does this instance belong to?
     * Returns '' for an instance we do not know — the webhook MUST reject those
     * rather than guessing, or one number's traffic lands in another's context.
     */
    public function channelFor(string $instance): string
    {
        return $this->instanceToChannel[mb_strtolower(trim($instance))] ?? '';
    }

    public function configuredChannels(): array
    {
        return array_keys($this->channelToInstance);
    }

    /** Safe for logs and admin screens — never includes the key. */
    public function describe(): array
    {
        return [
            'base_url'   => $this->baseUrl,
            'key_set'    => $this->apiKey !== '',
            'key_length' => strlen($this->apiKey),   // a length is not a secret
            'channels'   => $this->channelToInstance,
        ];
    }

    public function getLastError(): array { return $this->lastError; }

    // ── Instances ────────────────────────────────────────────────────────────

    public function fetchInstances(): array
    {
        return $this->request('GET', '/instance/fetchInstances');
    }

    /** 'open' = connected, 'connecting', 'close', or null when unreachable. */
    public function connectionState(string $instance): ?string
    {
        $r = $this->request('GET', '/instance/connectionState/' . rawurlencode($instance));
        if (!$r['ok']) return null;
        $d = $r['data'];
        return $d['instance']['state'] ?? ($d['state'] ?? null);
    }

    /** Connection state for every configured channel. For the admin screen. */
    public function channelHealth(): array
    {
        $out = [];
        foreach ($this->channelToInstance as $channel => $instance) {
            $state = $this->connectionState($instance);
            $out[$channel] = [
                'instance'  => $instance,
                'state'     => $state ?? 'unreachable',
                'connected' => $state === 'open',
            ];
        }
        return $out;
    }

    /**
     * Create an instance. Evolution generates a QR straight away.
     *
     * integration WHATSAPP-BAILEYS is the QR-pairing mode (as opposed to the
     * Meta Cloud API mode), which is what a normal WhatsApp number uses.
     */
    public function createInstance(string $name): array
    {
        return $this->request('POST', '/instance/create', [
            'instanceName' => $name,
            'integration'  => 'WHATSAPP-BAILEYS',
            'qrcode'       => true,
        ]);
    }

    /**
     * Ask for a pairing QR.
     *
     * Returns ['qr' => data-uri or '', 'pairing_code' => string]. Evolution
     * rotates the code every few seconds and gives up after a limited number
     * of attempts, so treat what comes back as valid for about a minute.
     *
     * An already-connected instance returns no QR — check connectionState
     * first if you need to distinguish that from a failure.
     */
    public function connect(string $instance): array
    {
        $r = $this->request('GET', '/instance/connect/' . rawurlencode($instance));
        if (!$r['ok']) return $r;

        $d  = $r['data'];
        $qr = (string)($d['base64'] ?? ($d['qrcode']['base64'] ?? ''));
        // Some builds return raw base64, others a full data URI.
        if ($qr !== '' && strpos($qr, 'data:') !== 0) {
            $qr = 'data:image/png;base64,' . $qr;
        }

        $r['qr']           = $qr;
        $r['pairing_code'] = (string)($d['pairingCode'] ?? ($d['code'] ?? ''));
        return $r;
    }

    /** Sign a number out of WhatsApp without deleting the instance. */
    public function logoutInstance(string $instance): array
    {
        return $this->request('DELETE', '/instance/logout/' . rawurlencode($instance));
    }

    // ── Webhook management ───────────────────────────────────────────────────

    /**
     * Point an instance's webhook at us.
     *
     * $url should already carry the shared secret, because Evolution v2 does
     * not sign webhook payloads — the secret in the URL is the authentication.
     * See EvoWebhookGuard.
     */
    public function setWebhook(string $instance, string $url, array $events = []): array
    {
        if (!$events) {
            $events = ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE'];
        }
        return $this->request('POST', '/webhook/set/' . rawurlencode($instance), [
            'webhook' => [
                'enabled'  => true,
                'url'      => $url,
                'byEvents' => false,
                'base64'   => false,
                'events'   => $events,
            ],
        ]);
    }

    public function findWebhook(string $instance): array
    {
        return $this->request('GET', '/webhook/find/' . rawurlencode($instance));
    }

    // ── Sending ──────────────────────────────────────────────────────────────

    /**
     * Send text on a business channel.
     *
     * Channel-addressed rather than instance-addressed on purpose: callers
     * should say "reply on the account number", not name an instance.
     */
    public function sendText(string $channel, string $phone, string $text): array
    {
        $instance = $this->requireInstance($channel);
        if ($instance === '') {
            return $this->fail("No Evolution instance configured for channel '{$channel}'");
        }
        return $this->request('POST', '/message/sendText/' . rawurlencode($instance), [
            'number' => self::normalisePhone($phone),
            'text'   => $text,
        ]);
    }

    /**
     * Send media. $media is a public URL or base64 payload.
     * $mediaType: image | video | document | audio
     */
    public function sendMedia(
        string $channel,
        string $phone,
        string $mediaType,
        string $media,
        string $caption = '',
        string $fileName = ''
    ): array {
        $instance = $this->requireInstance($channel);
        if ($instance === '') {
            return $this->fail("No Evolution instance configured for channel '{$channel}'");
        }
        $body = [
            'number'    => self::normalisePhone($phone),
            'mediatype' => $mediaType,
            'media'     => $media,
        ];
        if ($caption  !== '') $body['caption']  = $caption;
        if ($fileName !== '') $body['fileName'] = $fileName;

        return $this->request('POST', '/message/sendMedia/' . rawurlencode($instance), $body);
    }

    public function sendImage(string $channel, string $phone, string $media, string $caption = ''): array
    {
        return $this->sendMedia($channel, $phone, 'image', $media, $caption);
    }

    /** Invoices and quotations go out this way. */
    public function sendDocument(string $channel, string $phone, string $media, string $fileName, string $caption = ''): array
    {
        return $this->sendMedia($channel, $phone, 'document', $media, $caption, $fileName);
    }

    public function markAsRead(string $channel, string $remoteJid, string $messageId, bool $fromMe = false): array
    {
        $instance = $this->requireInstance($channel);
        if ($instance === '') return $this->fail("No instance for channel '{$channel}'");

        return $this->request('POST', '/chat/markMessageAsRead/' . rawurlencode($instance), [
            'readMessages' => [[
                'remoteJid' => $remoteJid,
                'fromMe'    => $fromMe,
                'id'        => $messageId,
            ]],
        ]);
    }

    /**
     * Typing indicator. Worth sending before an AI reply — the model takes a
     * few seconds and silence reads as being ignored.
     */
    public function sendTyping(string $channel, string $phone, int $durationMs = 3000): array
    {
        $instance = $this->requireInstance($channel);
        if ($instance === '') return $this->fail("No instance for channel '{$channel}'");

        return $this->request('POST', '/chat/sendPresence/' . rawurlencode($instance), [
            'number'   => self::normalisePhone($phone),
            'presence' => 'composing',
            'delay'    => $durationMs,
        ]);
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function findMessages(string $channel, string $remoteJid, int $pageSize = 50, int $page = 1): array
    {
        $instance = $this->requireInstance($channel);
        if ($instance === '') return $this->fail("No instance for channel '{$channel}'");

        return $this->request('POST', '/chat/findMessages/' . rawurlencode($instance), [
            'where'  => ['key' => ['remoteJid' => $remoteJid]],
            'page'   => $page,
            'offset' => $pageSize,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Digits only. Evolution rejects '+' and spaces on the number field. */
    public static function normalisePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    /** '211912345678@s.whatsapp.net' -> '211912345678'. Returns '' for @lid. */
    public static function phoneFromJid(string $jid): string
    {
        if ($jid === '' || strpos($jid, '@lid') !== false) return '';
        $left = explode('@', $jid)[0];
        return self::normalisePhone($left);
    }

    private function requireInstance(string $channel): string
    {
        return $this->instanceFor($channel);
    }

    private function fail(string $message): array
    {
        $this->lastError = ['message' => $message, 'http' => 0];
        return ['ok' => false, 'http' => 0, 'data' => [], 'error' => $message];
    }

    /**
     * One HTTP call.
     *
     * Retries idempotent reads and connection-level failures. A POST that
     * actually reached Evolution is never retried — a duplicate WhatsApp
     * message to a customer is worse than a failed send we can report.
     */
    private function request(string $method, string $path, array $body = null, int $attempt = 1): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            return $this->fail('Evolution API is not configured');
        }

        $ch = curl_init($this->baseUrl . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['apikey: ' . $this->apiKey, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR      => 7,   // keep POST across a redirect
        ];
        if ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            $opts[CURLOPT_POSTFIELDS]    = json_encode($body ?? []);
        }
        curl_setopt_array($ch, $opts);

        $raw       = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Connection never completed — safe to retry regardless of method.
        if ($raw === false) {
            if ($attempt < 3) {
                usleep(200000 * $attempt);
                return $this->request($method, $path, $body, $attempt + 1);
            }
            return $this->fail('Connection failed: ' . $curlError);
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) $data = ['raw' => mb_substr((string)$raw, 0, 500)];

        if ($httpCode >= 500 && $method === 'GET' && $attempt < 3) {
            usleep(200000 * $attempt);
            return $this->request($method, $path, $body, $attempt + 1);
        }

        if ($httpCode >= 400) {
            $msg = $data['message'] ?? ($data['error'] ?? ('HTTP ' . $httpCode));
            if (is_array($msg)) $msg = implode('; ', array_map('strval', $msg));
            $this->lastError = ['message' => (string)$msg, 'http' => $httpCode, 'path' => $path];
            return ['ok' => false, 'http' => $httpCode, 'data' => $data, 'error' => (string)$msg];
        }

        $this->lastError = [];
        return ['ok' => true, 'http' => $httpCode, 'data' => $data, 'error' => ''];
    }
}
