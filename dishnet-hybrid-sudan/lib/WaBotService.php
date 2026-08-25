<?php
declare(strict_types=1);

/**
 * WaBotService — WhatsApp Inbox + Auto-Reply Bot
 *
 * Handles:
 *  1. Storing incoming / outgoing messages in wa_conversations.json
 *  2. Conversation state machine (new → bot_replied → collecting_name →
 *     collecting_issue → ticket_created | human_active)
 *  3. Auto-reply after N minutes of no human response
 *  4. Ticket creation from collected info
 *  5. Staff can reply from dashboard → bot disengages
 *
 * Conversation record:
 * {
 *   "id": 1,
 *   "phone": "211912345678",
 *   "display_name": "Unknown",
 *   "state": "new",           // new|bot_replied|collecting_name|collecting_issue|ticket_created|human_active|closed
 *   "collected_name": "",
 *   "collected_issue": "",
 *   "ticket_id": null,
 *   "last_customer_msg_at": "2024-01-01 10:00:00",
 *   "last_human_reply_at": null,
 *   "bot_replied_at": null,
 *   "created_at": "2024-01-01 10:00:00",
 *   "updated_at": "2024-01-01 10:00:00",
 *   "messages": [
 *     {"role":"customer","text":"Hello","at":"2024-01-01 10:00:00"},
 *     {"role":"bot","text":"Hi...","at":"2024-01-01 10:15:00"},
 *     {"role":"staff","text":"Hi there","at":"2024-01-01 10:20:00","staff_name":"John"}
 *   ]
 * }
 */
class WaBotService
{
    const CONV_FILE   = 'wa_conversations.json';
    const TICKET_FILE = 'wa_tickets.json';

    private $store;
    private $notify;
    private array $config;

    public function __construct($store, $notify, array $config)
    {
        $this->store  = $store;
        $this->notify = $notify;
        $this->config = $config;
    }

    // ══════════════════════════════════════════════════════════════════════
    // INCOMING MESSAGE (called by wa_webhook.php)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Process an inbound customer message.
     * Returns the conversation record.
     */
    public function handleIncoming(string $phone, string $text, ?string $displayName = null): array
    {
        $phone = $this->normalisePhone($phone);
        $text  = trim($text);

        // Find or create conversation
        $conv = $this->findOpenConversation($phone);
        if (!$conv) {
            $conv = $this->createConversation($phone, $displayName);
        } elseif ($displayName && ($conv['display_name'] ?? 'Unknown') === 'Unknown') {
            // Update display name if we now have one
            $this->store->updateOne(self::CONV_FILE, 'id', $conv['id'], ['display_name' => $displayName]);
            $conv['display_name'] = $displayName;
        }

        // Append message to thread
        $conv = $this->appendMessage($conv, 'customer', $text);

        // Update last customer message timestamp
        $this->store->updateOne(self::CONV_FILE, 'id', $conv['id'], [
            'last_customer_msg_at' => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);
        $conv['last_customer_msg_at'] = date('Y-m-d H:i:s');

        // Advance state machine if bot is in collection mode
        $conv = $this->advanceBotFlow($conv, $text);

        return $conv;
    }

    /**
     * Advance the bot conversation flow based on current state.
     */
    private function advanceBotFlow(array $conv, string $customerText): array
    {
        $state = $conv['state'] ?? 'new';

        switch ($state) {

            case 'collecting_name':
                // Customer replied with their name
                $name = ucwords(strtolower(trim($customerText)));
                if (strlen($name) < 2) {
                    $this->sendBotMessage($conv, "Sorry, could you please tell me your *full name*? 😊");
                    return $conv;
                }
                $this->store->updateOne(self::CONV_FILE, 'id', $conv['id'], [
                    'collected_name' => $name,
                    'state'          => 'collecting_issue',
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);
                $conv['collected_name'] = $name;
                $conv['state']          = 'collecting_issue';
                $this->sendBotMessage($conv,
                    "Thank you, *{$name}*! 🙏\n\n"
                    . "Please describe your issue or question in detail:\n"
                    . "_E.g. My internet is down / I want to upgrade my plan / I need a new connection_"
                );
                break;

            case 'collecting_issue':
                // Customer described their issue
                $issue = trim($customerText);
                if (strlen($issue) < 5) {
                    $this->sendBotMessage($conv, "Please provide a bit more detail about your issue so we can help you better 🙏");
                    return $conv;
                }
                $this->store->updateOne(self::CONV_FILE, 'id', $conv['id'], [
                    'collected_issue' => $issue,
                    'state'           => 'ticket_created',
                    'updated_at'      => date('Y-m-d H:i:s'),
                ]);
                $conv['collected_issue'] = $issue;
                $conv['state']           = 'ticket_created';
                $this->createTicket($conv);
                $this->sendBotMessage($conv,
                    "✅ *Ticket Created!*\n\n"
                    . "Your request has been logged and our team will contact you shortly.\n\n"
                    . "📋 *Summary:*\n"
                    . "• Name: {$conv['collected_name']}\n"
                    . "• Issue: {$issue}\n\n"
                    . "⏰ Expected response time: *1–2 hours* during business hours.\n\n"
                    . "Thank you for your patience! 🙏\n"
                    . "— *DishNet Support Team*"
                );
                // Alert admin
                $this->notify->sendAdmin(
                    "🎫 *New WhatsApp Ticket*\n"
                    . "From: {$conv['display_name']} ({$conv['phone']})\n"
                    . "Name: {$conv['collected_name']}\n"
                    . "Issue: {$issue}",
                    'wa_ticket_created',
                    ['phone' => $conv['phone'], 'name' => $conv['collected_name'], 'issue' => $issue]
                );
                break;

            case 'new':
            case 'human_active':
            case 'ticket_created':
                // No bot action — human handles it or ticket already logged
                break;
        }

        return $conv;
    }

    // ══════════════════════════════════════════════════════════════════════
    // AUTO-REPLY CRON (called by cron_wa_bot.php)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Check all open conversations and auto-reply if no human response
     * within the configured timeout.
     * Returns count of conversations auto-replied.
     */
    public function runAutoReplyCheck(): int
    {
        $timeoutMinutes = (int)($this->config['wa_bot_timeout_minutes'] ?? 15);
        $enabled        = (bool)($this->config['wa_bot_enabled'] ?? true);
        if (!$enabled) return 0;

        $conversations = $this->store->load(self::CONV_FILE) ?? [];
        $replied       = 0;
        $cutoff        = time() - ($timeoutMinutes * 60);

        foreach ($conversations as $conv) {
            if (($conv['state'] ?? '') !== 'new') continue;

            $lastCustomer = strtotime($conv['last_customer_msg_at'] ?? $conv['created_at'] ?? '');
            $lastHuman    = $conv['last_human_reply_at'] ? strtotime($conv['last_human_reply_at']) : null;

            // Skip if human already replied
            if ($lastHuman !== null) continue;

            // Skip if not old enough yet
            if ($lastCustomer > $cutoff) continue;

            // Auto-reply!
            $this->sendAutoReply($conv);
            $replied++;
        }

        return $replied;
    }

    private function sendAutoReply(array $conv): void
    {
        $customMsg = trim($this->config['wa_bot_busy_message'] ?? '');
        if (!$customMsg) {
            $customMsg = "👋 Hello! Thank you for contacting *DishNet Africa*.\n\n"
                       . "Our support team is currently busy assisting other customers. "
                       . "We will get back to you as soon as possible.\n\n"
                       . "To help us serve you faster, I'll collect a few details 📋";
        }

        $this->notify->sendVia('support', $conv['phone'], $customMsg, 'wa_bot_auto_reply', [
            'phone' => $conv['phone'],
        ]);

        // Short pause then ask for name
        sleep(1);

        $this->notify->sendVia('support', $conv['phone'],
            "May I have your *full name* please? 😊",
            'wa_bot_ask_name',
            ['phone' => $conv['phone']]
        );

        // Update conversation state
        $this->store->updateOne(self::CONV_FILE, 'id', $conv['id'], [
            'state'          => 'collecting_name',
            'bot_replied_at' => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        // Log bot messages in thread
        $conv['state'] = 'collecting_name';
        $this->appendMessage($conv, 'bot', $customMsg);
        $this->appendMessage($conv, 'bot', "May I have your *full name* please? 😊");
    }

    // ══════════════════════════════════════════════════════════════════════
    // STAFF REPLY (called from dashboard)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Staff sends a reply from the dashboard.
     * Disengages the bot for this conversation.
     */
    public function staffReply(int $convId, string $text, string $staffName): bool
    {
        $conv = $this->store->findOne(self::CONV_FILE, 'id', $convId);
        if (!$conv) return false;

        // Send via WhatsApp
        $this->notify->sendVia('support', $conv['phone'], $text, 'wa_staff_reply', [
            'staff_name' => $staffName,
            'phone'      => $conv['phone'],
        ]);

        // Log message in thread
        $conv = $this->appendMessage($conv, 'staff', $text, $staffName);

        // Mark conversation as human_active, record last human reply
        $this->store->updateOne(self::CONV_FILE, 'id', $convId, [
            'state'               => 'human_active',
            'last_human_reply_at' => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Close a conversation.
     */
    public function closeConversation(int $convId, string $staffName): bool
    {
        $conv = $this->store->findOne(self::CONV_FILE, 'id', $convId);
        if (!$conv) return false;

        $this->store->updateOne(self::CONV_FILE, 'id', $convId, [
            'state'      => 'closed',
            'closed_at'  => date('Y-m-d H:i:s'),
            'closed_by'  => $staffName,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    // ══════════════════════════════════════════════════════════════════════
    // TICKET
    // ══════════════════════════════════════════════════════════════════════

    private function createTicket(array $conv): array
    {
        $ticket = [
            'conv_id'       => $conv['id'],
            'phone'         => $conv['phone'],
            'customer_name' => $conv['collected_name'] ?? 'Unknown',
            'issue'         => $conv['collected_issue'] ?? '',
            'source'        => 'whatsapp_bot',
            'status'        => 'open',
            'priority'      => 'normal',
            'assigned_to'   => null,
            'notes'         => '',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        $ticket = $this->store->appendWithId(self::TICKET_FILE, $ticket);

        // Link ticket ID back to conversation
        $this->store->updateOne(self::CONV_FILE, 'id', $conv['id'], [
            'ticket_id' => $ticket['id'],
        ]);

        return $ticket;
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    public function findOpenConversation(string $phone): ?array
    {
        $all = $this->store->load(self::CONV_FILE) ?? [];
        foreach ($all as $c) {
            if (($c['phone'] ?? '') === $phone && !in_array($c['state'] ?? '', ['closed'])) {
                return $c;
            }
        }
        return null;
    }

    private function createConversation(string $phone, ?string $displayName): array
    {
        $conv = [
            'phone'                => $phone,
            'display_name'         => $displayName ?? 'Unknown',
            'state'                => 'new',
            'collected_name'       => '',
            'collected_issue'      => '',
            'ticket_id'            => null,
            'last_customer_msg_at' => date('Y-m-d H:i:s'),
            'last_human_reply_at'  => null,
            'bot_replied_at'       => null,
            'messages'             => [],
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ];
        return $this->store->appendWithId(self::CONV_FILE, $conv);
    }

    private function appendMessage(array $conv, string $role, string $text, ?string $staffName = null): array
    {
        $all = $this->store->load(self::CONV_FILE) ?? [];
        foreach ($all as &$c) {
            if ((int)($c['id'] ?? 0) === (int)$conv['id']) {
                $msg = ['role' => $role, 'text' => $text, 'at' => date('Y-m-d H:i:s')];
                if ($staffName) $msg['staff_name'] = $staffName;
                $c['messages'][] = $msg;
                $conv['messages'][] = $msg;
                break;
            }
        }
        unset($c);
        $this->store->save(self::CONV_FILE, $all);
        return $conv;
    }

    private function sendBotMessage(array $conv, string $text): void
    {
        $this->notify->sendVia('support', $conv['phone'], $text, 'wa_bot_message', [
            'phone' => $conv['phone'],
        ]);
        $this->appendMessage($conv, 'bot', $text);
    }

    public function normalisePhone(string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', trim($phone));
    }

    // ══════════════════════════════════════════════════════════════════════
    // DATA GETTERS
    // ══════════════════════════════════════════════════════════════════════

    public function getAllConversations(bool $includeClosedParam = false): array
    {
        $all = $this->store->load(self::CONV_FILE) ?? [];
        if (!$includeClosedParam) {
            $all = array_filter($all, fn($c) => ($c['state'] ?? '') !== 'closed');
        }
        // Sort: newest first
        usort($all, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        return array_values($all);
    }

    public function getConversation(int $id): ?array
    {
        return $this->store->findOne(self::CONV_FILE, 'id', $id);
    }

    public function getAllTickets(): array
    {
        $tickets = $this->store->load(self::TICKET_FILE) ?? [];
        usort($tickets, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return $tickets;
    }

    public function updateTicket(int $id, array $updates): bool
    {
        $updates['updated_at'] = date('Y-m-d H:i:s');
        return $this->store->updateOne(self::TICKET_FILE, 'id', $id, $updates);
    }

    public function getStats(): array
    {
        $convs   = $this->store->load(self::CONV_FILE) ?? [];
        $tickets = $this->store->load(self::TICKET_FILE) ?? [];

        $open    = 0; $bot = 0; $human = 0; $ticketOpen = 0;
        foreach ($convs as $c) {
            $state = $c['state'] ?? 'new';
            if ($state === 'closed') continue;
            $open++;
            if (in_array($state, ['new','bot_replied','collecting_name','collecting_issue'])) $bot++;
            if ($state === 'human_active') $human++;
        }
        foreach ($tickets as $t) {
            if (($t['status'] ?? 'open') === 'open') $ticketOpen++;
        }

        return [
            'open_conversations' => $open,
            'bot_active'         => $bot,
            'human_active'       => $human,
            'open_tickets'       => $ticketOpen,
            'total_tickets'      => count($tickets),
        ];
    }
}
