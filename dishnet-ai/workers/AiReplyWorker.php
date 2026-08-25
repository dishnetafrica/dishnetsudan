<?php
declare(strict_types=1);

/**
 * AiReplyWorker — turns a queued inbound WhatsApp message into a reply.
 *
 * Consumes 'ai.reply' events emitted by evo_webhook_v2.php. This is where the
 * slow work lives, so the webhook can answer Evolution immediately.
 *
 * Per message:
 *   1. identify the customer from their number
 *   2. gather the context this channel's role needs
 *   3. ask ShopBot's AI for a reply
 *   4. send it through Evolution on the same number the customer used
 *
 * Retry safety: the Evolution send is the LAST thing that happens, and once it
 * has succeeded this method never throws. A retry after a successful send
 * would message the customer twice, which is worse than losing a log line.
 *
 * PHP 7.4 compatible.
 */
class AiReplyWorker extends WorkerBase
{
    private EvolutionApiService $evo;
    private DishNetTools $tools;
    private DishNetAiBrain $brain;
    private $convSvc;

    public function __construct($store, array $config, int $maxRun = 55, int $batch = 10)
    {
        parent::__construct($store, $config, $maxRun, $batch);

        $root = dirname(__DIR__);
        require_once $root . '/lib/EvolutionApiService.php';
        require_once $root . '/lib/DishNetTools.php';
        require_once $root . '/lib/ConversationService.php';
        require_once $root . '/lib/DishNetAiBrain.php';

        $this->evo   = new EvolutionApiService($config);
        $this->tools = new DishNetTools($store, $config, $root);
        $this->brain = new DishNetAiBrain($config);

        $dataDir = getDataDir($root);
        $this->convSvc = new ConversationService($dataDir, $this->pdo);
    }

    protected function getEventTypes(): array
    {
        return ['ai.reply'];
    }

    protected function handle(array $event): void
    {
        $p       = $event['_payload'] ?? [];
        $channel = (string)($p['channel'] ?? '');
        $phone   = (string)($p['customer_phone'] ?? '');
        $message = (string)($p['message'] ?? '');
        $convId  = (int)($event['entity_id'] ?? 0);

        if ($channel === '' || $phone === '' || $message === '') {
            $this->log('warn', 'ai.reply event missing required fields — dropping');
            return;   // not retryable; a retry cannot make the payload valid
        }

        // A human has taken this conversation — stay out of it.
        if ($convId > 0 && $this->humanIsHandling($convId)) {
            $this->log('info', "conv {$convId}: human active, skipping AI");
            return;
        }

        // Let the customer see something is happening while the model thinks.
        $this->evo->sendTyping($channel, $phone);

        $context = $this->buildContext($channel, $phone, $message, $p, $convId);

        $ai = $this->askBrain($context);
        if ($ai === null) {
            // Throw: EventBus retries with backoff. The customer has not been
            // messaged yet, so a retry is safe.
            throw new \RuntimeException('AI brain did not return a reply');
        }

        $reply = trim((string)($ai['reply'] ?? ''));
        if ($reply === '') {
            $this->log('warn', 'AI returned an empty reply — escalating instead');
            $this->escalate($convId, $channel, $phone, 'AI produced no reply');
            return;
        }

        // ── Point of no return ───────────────────────────────────────────
        $send = $this->evo->sendText($channel, $phone, $reply);
        if (!$send['ok']) {
            throw new \RuntimeException('Evolution send failed: ' . $send['error']);
        }

        // Everything past here is bookkeeping. Never throw — the customer has
        // already received the message and must not receive it again.
        try {
            if ($convId > 0) {
                $this->convSvc->storeMessage($convId, [
                    'direction'  => 'out',
                    'role'       => 'assistant',
                    'body'       => $reply,
                    'agent_name' => 'DishNet AI',
                    'metadata'   => json_encode(['channel' => $channel]),
                ]);
            }
            if (!empty($ai['escalate'])) {
                $this->escalate($convId, $channel, $phone, (string)($ai['escalate_reason'] ?? 'AI requested handover'));
            }
        } catch (\Throwable $e) {
            $this->log('error', 'post-send bookkeeping failed: ' . $e->getMessage());
        }
    }

    /**
     * Build the envelope ShopBot receives.
     *
     * Context is scoped by role: the sales number never needs a balance, and
     * the account number never needs the product catalogue. Fetching less is
     * both faster and a smaller disclosure surface.
     */
    private function buildContext(string $channel, string $phone, string $message, array $p, int $convId): array
    {
        $ctx = [
            'channel'           => $channel,
            'whatsapp_instance' => (string)($p['whatsapp_instance'] ?? ''),
            'customer_phone'    => $phone,
            'message'           => $message,
            'push_name'         => (string)($p['push_name'] ?? ''),
            'conversation_id'   => $convId,
            'customer'          => null,
            'history'           => [],
        ];

        // Identity is shared across all three numbers.
        $id = $this->tools->identifyCustomerByPhone($phone);
        $identified = false;
        $clientId   = 0;
        if ($id['ok'] && !empty($id['data']['found'])) {
            $ctx['customer'] = $id['data']['customer'];
            $clientId   = (int)($id['data']['customer']['id'] ?? 0);
            $identified = $clientId > 0;
            if ($convId > 0 && $identified) {
                try {
                    $this->convSvc->linkToCrm($convId, $clientId, (string)($id['data']['customer']['name'] ?? ''));
                } catch (\Throwable $e) { /* non-fatal */ }
            }
        } elseif ($id['ok'] && ($id['data']['reason'] ?? '') === 'ambiguous') {
            // Several customers share this number's last digits. Say so rather
            // than picking one — the AI must ask a verifying question.
            $ctx['identity_ambiguous'] = true;
        }

        switch ($channel) {
            case EvolutionApiService::CHANNEL_SALES:
                $products = $this->tools->getProducts();
                if ($products['ok']) $ctx['products'] = $products['data'];
                break;

            case EvolutionApiService::CHANNEL_SUPPORT:
                if ($identified) {
                    $svc = $this->tools->getCustomerServices($clientId);
                    if ($svc['ok']) $ctx['services'] = $svc['data'];
                }
                // Live line status works from the phone number alone, so it is
                // still useful for a customer we could not identify in UCRM.
                $line = $this->tools->getLineStatus($phone);
                if ($line['ok']) $ctx['line_status'] = $line['data'];
                break;

            case EvolutionApiService::CHANNEL_ACCOUNT:
                // Money is only ever disclosed for an unambiguous identification.
                if ($identified && empty($ctx['identity_ambiguous'])) {
                    $acct = $this->tools->getAccount($clientId);
                    if ($acct['ok']) $ctx['account'] = $acct['data'];
                }
                break;
        }

        if ($convId > 0) {
            try {
                $msgs = $this->convSvc->getMessages($convId, 10, 0);
                foreach ($msgs as $m) {
                    $ctx['history'][] = [
                        'role' => ($m['direction'] ?? 'in') === 'in' ? 'customer' : 'dishnet',
                        'text' => mb_substr((string)($m['body'] ?? ''), 0, 400),
                    ];
                }
            } catch (\Throwable $e) { /* history is optional */ }
        }

        return $ctx;
    }

    /**
     * Ask the AI for a reply.
     *
     * Two implementations behind ONE contract. In-process is the default
     * (Option A); setting shopbot_ai_url switches to an external brain
     * (Option B) with no other change anywhere in the system. This is the
     * seam that keeps the architecture decision reversible.
     */
    private function askBrain(array $context): ?array
    {
        if (trim((string)($this->config['shopbot_ai_url'] ?? '')) !== '') {
            return $this->askShopBot($context);
        }
        if (!$this->brain->isConfigured()) {
            $this->log('error', 'No AI provider key configured');
            return null;
        }
        $result = $this->brain->reply($context);

        $usage = $this->brain->getLastUsage();
        if ($usage) {
            $this->log('info', sprintf(
                'ai tokens in=%d out=%d model=%s',
                $usage['input_tokens'] ?? 0, $usage['output_tokens'] ?? 0, $usage['model'] ?? '?'
            ));
        }
        return $result;
    }

    /**
     * Call an external ShopBot service (Option B only).
     *
     * Contract — deliberately identical to DishNetAiBrain::reply(), so the two
     * are interchangeable:
     *
     *   POST {shopbot_ai_url}
     *   Authorization: Bearer {shopbot_ai_token}
     *   body:     the context envelope above
     *   response: {"reply": "...", "escalate": false, "escalate_reason": ""}
     */
    private function askShopBot(array $context): ?array
    {
        $url   = trim((string)($this->config['shopbot_ai_url'] ?? ''));
        $token = trim((string)($this->config['shopbot_ai_token'] ?? ''));
        if ($url === '') {
            $this->log('error', 'shopbot_ai_url is not configured');
            return null;
        }

        $headers = ['Content-Type: application/json'];
        if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($context),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 45,      // LLM round trips are slow
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->log('error', 'ShopBot unreachable: ' . $err);
            return null;
        }
        if ($code >= 400) {
            $this->log('error', "ShopBot returned HTTP {$code}");
            return null;
        }
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : null;
    }

    private function humanIsHandling(int $convId): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT state, last_human_reply_at FROM wa_conversations WHERE id = ?');
            $stmt->execute([$convId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || ($row['state'] ?? '') !== 'human_active') return false;
            $last = strtotime((string)($row['last_human_reply_at'] ?? '2000-01-01'));
            return (time() - $last) < 24 * 3600;   // same cooldown the old bot used
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function escalate(int $convId, string $channel, string $phone, string $reason): void
    {
        try {
            if ($convId > 0) {
                $this->pdo->prepare(
                    "UPDATE wa_conversations SET state = 'needs_human', updated_at = datetime('now') WHERE id = ?"
                )->execute([$convId]);
            }
            $this->bus->emit('wa.escalation', 'conversation', $convId, [
                'channel' => $channel,
                'phone'   => $phone,
                'reason'  => $reason,
            ], 2, 'ai_reply_worker');
        } catch (\Throwable $e) {
            $this->log('error', 'escalation failed: ' . $e->getMessage());
        }
    }
}
