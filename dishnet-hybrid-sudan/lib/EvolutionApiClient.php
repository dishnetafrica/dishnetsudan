<?php
declare(strict_types=1);

/**
 * EvolutionApiClient — PHP client for Evolution API v2.x
 *
 * Endpoints used:
 *   GET  /instance/fetchInstances              — list instances
 *   POST /chat/findChats/{instance}            — list chats
 *   POST /chat/findMessages/{instance}         — fetch messages for a chat
 *   POST /chat/findContacts/{instance}         — fetch contacts
 *   POST /message/sendText/{instance}          — send text message
 *   GET  /instance/connectionState/{instance}  — check connection
 *
 * All calls use the global apikey header for auth.
 * PHP 7.4 compatible, pure curl, no dependencies.
 */
class EvolutionApiClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $instanceName;
    private int    $timeout;

    public function __construct(string $baseUrl, string $apiKey, string $instanceName, int $timeout = 30)
    {
        $this->baseUrl      = rtrim($baseUrl, '/');
        $this->apiKey       = $apiKey;
        $this->instanceName = $instanceName;
        $this->timeout      = $timeout;
    }

    // ── Instance ─────────────────────────────────────────────────────────────

    public function connectionState(): array
    {
        return $this->get("/instance/connectionState/{$this->instanceName}");
    }

    public function fetchInstances(): array
    {
        return $this->get('/instance/fetchInstances');
    }

    // ── Chats ────────────────────────────────────────────────────────────────

    /**
     * Fetch all chats (conversations list).
     * Returns array of chat objects with lastMsgTimestamp, unreadCount, etc.
     */
    public function findChats(): array
    {
        return $this->post("/chat/findChats/{$this->instanceName}", []);
    }

    /**
     * Fetch contacts. Can fetch all or filter by JID.
     */
    public function findContacts(string $jid = ''): array
    {
        $body = ['where' => []];
        if (!empty($jid)) {
            $body['where']['id'] = $jid;
        }
        return $this->post("/chat/findContacts/{$this->instanceName}", $body);
    }

    // ── Messages ─────────────────────────────────────────────────────────────

    /**
     * Fetch messages for a specific chat (by remoteJid).
     * @param string $remoteJid  e.g. "135455014137882@lid" or "211912345678@s.whatsapp.net"
     * @param int    $offset     Messages per page (Evolution API v2.3 calls this 'offset')
     * @param int    $page       Pagination page (1-based)
     */
    public function findMessages(string $remoteJid, int $offset = 100, int $page = 1): array
    {
        $body = [
            'where' => [
                'key' => ['remoteJid' => $remoteJid],
            ],
            'page'   => $page,
            'offset' => $offset,
        ];
        return $this->post("/chat/findMessages/{$this->instanceName}", $body);
    }

    /**
     * Fetch ALL messages for a chat across multiple pages.
     */
    public function findAllMessages(string $remoteJid, int $pageSize = 500): array
    {
        $all  = [];
        $page = 1;
        do {
            $result   = $this->findMessages($remoteJid, $pageSize, $page);
            // Handle different response formats
            $messages = null;
            if (isset($result['messages']['records']) && is_array($result['messages']['records'])) {
                $messages = $result['messages']['records'];
            } elseif (isset($result['messages']) && is_array($result['messages'])) {
                $messages = $result['messages'];
            } elseif (isset($result[0]['key'])) {
                $messages = $result;
            }
            if (!is_array($messages) || empty($messages)) break;
            $all = array_merge($all, $messages);
            $page++;
            if ($page > 200) break;
        } while (count($messages) >= $pageSize);

        return $all;
    }

    // ── Send ─────────────────────────────────────────────────────────────────

    /**
     * Send a text message.
     * @param string $phone  Phone number (digits only, e.g. "211912345678")
     * @param string $text   Message body
     */
    public function sendText(string $phone, string $text): array
    {
        $number = preg_replace('/[^0-9]/', '', $phone);
        return $this->post("/message/sendText/{$this->instanceName}", [
            'number' => $number,
            'text'   => $text,
        ]);
    }

    /**
     * Send a media message (document, image, video, audio).
     * @param string $phone     Phone number (digits only)
     * @param string $mediaType 'document', 'image', 'video', 'audio'
     * @param string $media     Base64 data or public URL
     * @param string $caption   Caption text
     * @param string $fileName  Filename for documents (e.g. "INV012775.pdf")
     */
    public function sendMedia(string $phone, string $mediaType, string $media, string $caption = '', string $fileName = ''): array
    {
        $number = preg_replace('/[^0-9]/', '', $phone);
        $body = [
            'number'    => $number,
            'mediatype' => $mediaType,
            'media'     => $media,
        ];
        if ($caption !== '')  $body['caption']  = $caption;
        if ($fileName !== '') $body['fileName'] = $fileName;

        return $this->post("/message/sendMedia/{$this->instanceName}", $body);
    }

    // ── Webhook ──────────────────────────────────────────────────────────────

    /**
     * Configure webhook for this instance.
     * @param string $url     Webhook endpoint URL
     * @param array  $events  Events to subscribe to
     */
    public function setWebhook(string $url, array $events = []): array
    {
        if (empty($events)) {
            $events = [
                'MESSAGES_UPSERT',
                'MESSAGES_UPDATE',
                'CONTACTS_UPSERT',
                'CONNECTION_UPDATE',
            ];
        }
        return $this->post("/webhook/set/{$this->instanceName}", [
            'webhook' => [
                'enabled'  => true,
                'url'      => $url,
                'byEvents' => false,
                'base64'   => false,
                'events'   => $events,
            ],
        ]);
    }

    /**
     * Get current webhook config.
     */
    public function findWebhook(): array
    {
        return $this->get("/webhook/find/{$this->instanceName}");
    }

    // ── HTTP helpers ─────────────────────────────────────────────────────────

    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        $headers = [
            'apikey: ' . $this->apiKey,
            'Content-Type: application/json',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['error' => $error, 'http_code' => 0];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['error' => 'Invalid JSON response', 'http_code' => $httpCode, 'raw' => substr($response, 0, 500)];
        }

        if ($httpCode >= 400) {
            $data['http_code'] = $httpCode;
            $data['error']     = $data['error'] ?? $data['message'] ?? "HTTP {$httpCode}";
        }

        return $data;
    }

    // ── Getters ──────────────────────────────────────────────────────────────

    public function getInstanceName(): string { return $this->instanceName; }
    public function getBaseUrl(): string      { return $this->baseUrl; }
}
