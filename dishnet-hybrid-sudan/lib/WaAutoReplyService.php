<?php
declare(strict_types=1);
if (!function_exists('str_contains'))    { function str_contains(string $h, string $n): bool    { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_ends_with'))   { function str_ends_with(string $h, string $n): bool   { return $n===''||substr($h,-strlen($n))===$n; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

/**
 * WaAutoReplyService — Unified WhatsApp Auto-Reply (v4.11.3)
 *
 * Channel-aware auto-reply with CRM lookup for the Accounts number.
 * Called from both wa_webhook.php (WASender) and evo_webhook.php (Evolution).
 *
 * SUPPORT channel:
 *   - Greeting → menu (1-Internet down, 2-Slow, 3-New connection, 4-Other)
 *   - Number selection → ticket creation
 *   - "status/ticket" → open ticket lookup
 *   - Unmatched → "agent will respond shortly"
 *
 * ACCOUNTS channel:
 *   - Greeting → menu (1-Balance, 2-Payment, 3-Invoice, 4-Speak to accounts)
 *   - "1/balance" → CRM balance lookup
 *   - "2/payment/paid" → last payment lookup
 *   - "3/invoice" → invoice info (PDF sending is Phase 3)
 *   - Unmatched → "Rupesh will respond shortly"
 *
 * PHP 7.4 compatible.
 */
class WaAutoReplyService
{
    private $store;
    private $pdo;
    private $notify;
    private $config;
    private $convSvc;
    private $crm = null;

    public function __construct($store, \PDO $pdo, $notify, array $config, $convSvc)
    {
        $this->store   = $store;
        $this->pdo     = $pdo;
        $this->notify  = $notify;
        $this->config  = $config;
        $this->convSvc = $convSvc;
    }

    /**
     * Process incoming message and send auto-reply if applicable.
     *
     * @param string      $phone       Customer phone
     * @param string      $text        Message text
     * @param string      $channel     'support' or 'accounts'
     * @param string|null $displayName WhatsApp push name
     * @param int|null    $convId      Conversation ID (if already stored)
     * @return array ['replied' => bool, 'reply' => string, 'action' => string]
     */
    public function handleIncoming(string $phone, string $text, string $channel, ?string $displayName = null, ?int $convId = null): array
    {
        $text = trim($text);
        $textLower = mb_strtolower($text);
        if (empty($text)) return ['replied' => false, 'reply' => '', 'action' => 'empty'];

        // ── Per-channel toggle guard ──────────────────────────────────────
        // Enforced here regardless of which webhook path called us.
        // wa_accounts_autoreply_enabled = false → log only, no reply.
        // Support channel is unaffected by this flag.
        if ($channel === 'accounts' && empty($this->config['wa_accounts_autoreply_enabled'])) {
            return ['replied' => false, 'reply' => '', 'action' => 'accounts_autoreply_disabled'];
        }

        // ── Skip auto-reply for internal staff ──
        // Check if this phone belongs to a DishNet retailer/employee
        if ($this->isStaffPhone($phone)) {
            return ['replied' => false, 'reply' => '', 'action' => 'staff_skipped'];
        }

        // Get or create conversation
        $conv = null;
        if ($convId) {
            try {
                $stmt = $this->pdo->prepare("SELECT * FROM wa_conversations WHERE id = ?");
                $stmt->execute([$convId]);
                $conv = $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {}
        }
        if (!$conv) {
            $conv = $this->convSvc->ensureConversation($phone, $channel, $displayName);
        }

        // ── Auto CRM lookup: link conversation to client if not yet linked ──
        if (empty($conv['crm_client_id']) && !empty($phone)) {
            try {
                $client = $this->lookupCrmClient($phone);
                if ($client && !empty($client['id'])) {
                    $clientName = $client['name'] ?? trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? '')) ?: 'Customer';
                    $this->linkConvToCrm($conv['id'], (int)$client['id'], $clientName);
                    $conv['crm_client_id']   = (int)$client['id'];
                    $conv['crm_client_name'] = $clientName;
                }
            } catch (\Throwable $e) {}
        }

        $state = $conv['state'] ?? 'new';

        // If a human is actively handling this conversation, don't auto-reply
        if ($state === 'human_active') {
            $lastHumanAt = strtotime($conv['last_human_reply_at'] ?? '2000-01-01');
            $cooldown = 24 * 3600; // 24h cooldown
            if (time() - $lastHumanAt < $cooldown) {
                return ['replied' => false, 'reply' => '', 'action' => 'human_active'];
            }
            // Cooldown expired — reset to new for auto-reply
            $this->updateConvState($conv['id'], 'new');
            $state = 'new';
        }

        // ── Security: flag suspicious messages in conversation state ──────
        // Mark conversations where someone is trying to extract data or inject prompts.
        // This is logged so Bidal/support can see it in WA Inbox.
        $_suspiciousPatterns = [
            '/ignore (previous|all|your) (instructions?|rules?|prompt)/i',
            '/show (me )?(all |every )?customer/i',
            '/give me (all |every )?customer/i',
            '/system prompt|your (instructions?|rules?|prompt)/i',
            '/DAN|jailbreak/i',
            '/what is .{0,30}(password|api.?key|secret)/i',
        ];
        foreach ($_suspiciousPatterns as $_sp) {
            if (preg_match($_sp, $text)) {
                // Tag this conversation as suspicious — shows in inbox
                try {
                    $this->pdo->prepare(
                        "UPDATE wa_conversations SET state='needs_human', category='security_flag', updated_at=datetime('now') WHERE id=?"
                    )->execute([$conv['id'] ?? 0]);
                } catch (\Throwable $_e) {}
                $_alertMsg = "🚨 *Security alert* — possible data extraction attempt: " . mb_substr($text, 0, 200);
                $this->alertStaff($channel, $phone, $displayName ?? 'Unknown', $_alertMsg);

                $reply = "I can only help with your DishNet service. For anything else, please visit our office in Juba.";
                $this->sendReply($phone, $reply, $channel, $conv['id'] ?? 0);
                return ['replied' => true, 'reply' => $reply, 'action' => 'security_blocked'];
            }
        }

        // ── Dedup: don't auto-reply if the exact same message was just sent ──
        // This prevents double-firing on rapid identical retries, but still
        // processes different follow-up messages (e.g. "Hi" then the real issue).
        $lastBotAt  = strtotime($conv['bot_replied_at'] ?? '2000-01-01');
        $lastInBody = $conv['last_in_body'] ?? null;
        if (time() - $lastBotAt < 30 && $lastInBody !== null && $lastInBody === $text) {
            return ['replied' => false, 'reply' => '', 'action' => 'dedup_identical'];
        }
        // Update last incoming message body for future dedup
        $this->updateConv($conv['id'], ['last_in_body' => mb_substr($text, 0, 500)]);

        // ── Conversational closers: thank you, ok, bye, etc. ──
        // Handle these in ANY state — don't create tickets or show menus
        $closerResult = $this->handleClosingPhrase($textLower, $phone, $channel, $conv['id']);
        if ($closerResult) return $closerResult;

        // ── Reopen closed conversations ──
        if ($state === 'closed') {
            $this->updateConv($conv['id'], ['state' => 'new', 'status' => 'active']);
            $state = 'new';
        }

        // ── Route to channel-specific handler ──
        if ($channel === 'accounts') {
            return $this->handleAccountsChannel($conv, $phone, $text, $textLower, $displayName);
        } else {
            return $this->handleSupportChannel($conv, $phone, $text, $textLower, $displayName);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  SUPPORT CHANNEL
    // ══════════════════════════════════════════════════════════════════════

    private function handleSupportChannel(array $conv, string $phone, string $text, string $textLower, ?string $displayName): array
    {
        $state = $conv['state'] ?? 'new';
        $name  = $displayName ?: ($conv['display_name'] ?? 'there');

        // ── State: starlink_pw_collecting — waiting for the new password ─
        if ($state === 'starlink_pw_collecting') {
            return $this->starlinkCollectPassword($conv, $phone, $text, $displayName);
        }

        // ── WiFi password/name change — intercept in ANY state ───────────
        // Fires before Claude so it never burns API tokens on this.
        // DishNet manages all Starlink WiFi — customers cannot change it themselves.
        if ($this->isWifiChangeRequest($textLower)) {
            return $this->starlinkRequestPassword($conv, $phone, $displayName);
        }

        // ── State: claude_active — Claude is handling this conversation, keep going ──
        if ($state === 'claude_active') {
            // Fix 5: Auto-escalate after 6 bot turns to prevent infinite loops
            $botTurnCount = 0;
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM wa_messages WHERE conversation_id = ? AND direction = 'out' AND role = 'bot'"
                );
                $stmt->execute([$conv['id']]);
                $botTurnCount = (int)$stmt->fetchColumn();
            } catch (\Throwable $e) {}

            if ($botTurnCount >= 6) {
                $this->updateConvState($conv['id'], 'needs_human');
                $escMsg = $this->getEscalationMessage();
                $reply  = "I want to make sure this gets fully sorted — I'm passing you to our support team now. {$escMsg}";
                $this->sendReply($phone, $reply, 'support', $conv['id']);
                $this->alertStaff('support', $phone, $displayName ?? 'Customer',
                    "Auto-escalated after {$botTurnCount} bot turns — customer needs human follow-up.\nLast message: " . mb_substr($text, 0, 200));
                return ['replied' => true, 'reply' => $reply, 'action' => 'auto_escalated_turns'];
            }

            $aiReply = $this->getAiReply($phone, $text, 'support', $conv);
            if ($aiReply) {
                $this->sendReply($phone, $aiReply, 'support', $conv['id']);
                return ['replied' => true, 'reply' => $aiReply, 'action' => 'ai_continued'];
            }
            // Claude unavailable — hand off to human
            $this->updateConvState($conv['id'], 'needs_human');
            $escMsg = $this->getEscalationMessage();
            $reply  = "Let me get one of our team to help you — {$escMsg}";
            $this->sendReply($phone, $reply, 'support', $conv['id']);
            $this->alertStaff('support', $phone, $displayName ?? 'Customer', $text);
            return ['replied' => true, 'reply' => $reply, 'action' => 'escalated'];
        }

        // ── State: collecting info for ticket ──
        if ($state === 'support_collecting_name') {
            return $this->supportCollectName($conv, $text, $name);
        }
        if ($state === 'support_collecting_issue') {
            return $this->supportCollectIssue($conv, $text);
        }

        // ── Check for menu selection (1-4) ──
        if ($state === 'support_menu_sent') {
            if (in_array($textLower, ['1', '2', '3'], true)) {
                $issues = ['1' => 'Internet is down', '2' => 'Slow internet speed', '3' => 'New connection inquiry'];
                $issue = $issues[$textLower];
                // Quick ticket — skip name collection if we have display name
                if ($displayName && strlen($displayName) > 1) {
                    $this->updateConv($conv['id'], [
                        'collected_name'  => $displayName,
                        'collected_issue' => $issue,
                        'state'           => 'ticket_created',
                    ]);
                    $ticketRef = $this->createSupportTicket($conv, $displayName, $issue, $phone);
                    $ticketReplies = [
                        "Got it, I've logged that for you. Ref: {$ticketRef}\n\nSomeone from our team will get back to you within an hour.",
                        "I've created a ticket for you ({$ticketRef}). Our team will reach out shortly.",
                        "Noted! Your reference is {$ticketRef}. We'll follow up within the hour.",
                        "All sorted — ticket {$ticketRef} is open. Expect a call from us within an hour.",
                    ];
                    $reply = $ticketReplies[array_rand($ticketReplies)];
                    $this->sendReply($phone, $reply, 'support', $conv['id']);
                    return ['replied' => true, 'reply' => $reply, 'action' => 'ticket_created'];
                }
                // Need name
                $this->updateConv($conv['id'], ['collected_issue' => $issue, 'state' => 'support_collecting_name']);
                $reply = "Thank you! To create your support ticket, please tell me your *full name*:";
                $this->sendReply($phone, $reply, 'support', $conv['id']);
                return ['replied' => true, 'reply' => $reply, 'action' => 'collecting_name'];
            }
            if ($textLower === '4') {
                $this->updateConvState($conv['id'], 'needs_human');
                $escMsg = $this->getEscalationMessage();
                $escReplies = [
                    "Sure, let me get someone for you. {$escMsg}",
                    "No worries, I'll connect you. {$escMsg}",
                    "Got it, passing this on now. {$escMsg}",
                ];
                $reply = $escReplies[array_rand($escReplies)];
                $this->sendReply($phone, $reply, 'support', $conv['id']);
                $this->alertStaff('support', $phone, $displayName ?? 'Customer', $text);
                return ['replied' => true, 'reply' => $reply, 'action' => 'escalated'];
            }
        }

        // ── Check for ticket status request ──
        if (preg_match('/\b(status|ticket|update|progress)\b/i', $textLower)) {
            return $this->supportTicketStatus($conv, $phone, $displayName);
        }

        // ── For NEW/returning conversations: Claude replies first, menu only as fallback ──
        if ($state === 'new' || $state === 'bot_replied') {
            $aiReply = $this->getAiReply($phone, $text, 'support', $conv);
            if ($aiReply) {
                $this->updateConvState($conv['id'], 'claude_active');
                $this->sendReply($phone, $aiReply, 'support', $conv['id']);
                return ['replied' => true, 'reply' => $aiReply, 'action' => 'ai_direct'];
            }
            // Claude not available (no API key) — fall back to menu
            $this->updateConvState($conv['id'], 'support_menu_sent');
            $reply = $this->pickVariant('support_greeting', $name, $conv['id']);
            $this->sendReply($phone, $reply, 'support', $conv['id']);
            return ['replied' => true, 'reply' => $reply, 'action' => 'menu_sent'];
        }

        // ── Post-menu: customer typed something other than 1-4 ──
        if ($state === 'support_menu_sent') {
            // Try template match for specific issues (wifi password, speed test, etc.)
            $templateReply = $this->tryTemplateMatch($text, 'support');
            if ($templateReply) {
                $this->sendReply($phone, $templateReply, 'support', $conv['id']);
                return ['replied' => true, 'reply' => $templateReply, 'action' => 'template_match'];
            }
            // No template match — try AI reply first
            $aiReply = $this->getAiReply($phone, $text, 'support', $conv);
            if ($aiReply) {
                $this->updateConvState($conv['id'], 'claude_active');
                $this->sendReply($phone, $aiReply, 'support', $conv['id']);
                return ['replied' => true, 'reply' => $aiReply, 'action' => 'ai_reply'];
            }

            // No template match, no AI — treat as free-text issue description, create ticket
            if (strlen($textLower) > 5) {
                $issueName = $displayName && strlen($displayName) > 1 ? $displayName : 'Customer';
                $this->updateConv($conv['id'], [
                    'collected_name'  => $issueName,
                    'collected_issue' => $text,
                    'state'           => 'ticket_created',
                ]);
                $ticketRef = $this->createSupportTicket($conv, $issueName, $text, $phone);
                $reply = "Got it, I've logged that for you.\n\n"
                       . "Ref: {$ticketRef}\n"
                       . "Our team will reach out within 1 hour.";
                $this->sendReply($phone, $reply, 'support', $conv['id']);
                return ['replied' => true, 'reply' => $reply, 'action' => 'ticket_from_freetext'];
            }
            // Too short — nudge
            $reply = "Could you tell me a bit more about the issue? Or pick an option:\n\n"
                   . "1 - Internet down\n2 - Slow speed\n3 - New connection\n4 - Talk to agent";
            $this->sendReply($phone, $reply, 'support', $conv['id']);
            return ['replied' => true, 'reply' => $reply, 'action' => 'menu_reminder'];
        }

        // Otherwise don't interrupt
        return ['replied' => false, 'reply' => '', 'action' => 'no_action'];
    }

    private function supportCollectName(array $conv, string $text, string $fallbackName): array
    {
        $name = ucwords(strtolower(trim($text)));
        if (strlen($name) < 2) {
            $reply = "Please tell me your *full name* so we can create your ticket:";
            $this->sendReply($conv['phone'] ?? '', $reply, 'support', $conv['id']);
            return ['replied' => true, 'reply' => $reply, 'action' => 'name_retry'];
        }
        $issue = $conv['collected_issue'] ?? 'General inquiry';
        $phone = $conv['phone'] ?? '';
        $this->updateConv($conv['id'], ['collected_name' => $name, 'state' => 'ticket_created']);
        $ticketRef = $this->createSupportTicket($conv, $name, $issue, $phone);
        $reply = "Thanks {$name}! I've logged your issue. Ref: {$ticketRef}\n\nOur team will reach out within the hour.";
        $this->sendReply($phone, $reply, 'support', $conv['id']);
        return ['replied' => true, 'reply' => $reply, 'action' => 'ticket_created'];
    }

    private function supportCollectIssue(array $conv, string $text): array
    {
        if (strlen(trim($text)) < 3) {
            $reply = "Please describe your issue in a bit more detail:";
            $this->sendReply($conv['phone'] ?? '', $reply, 'support', $conv['id']);
            return ['replied' => true, 'reply' => $reply, 'action' => 'issue_retry'];
        }
        $name = $conv['collected_name'] ?? $conv['display_name'] ?? 'Customer';
        $phone = $conv['phone'] ?? '';
        $this->updateConv($conv['id'], ['collected_issue' => $text, 'state' => 'ticket_created']);
        $ticketRef = $this->createSupportTicket($conv, $name, $text, $phone);
        $reply = "Got it, I've noted that down. Ref: {$ticketRef}. Someone will follow up with you shortly.";
        $this->sendReply($phone, $reply, 'support', $conv['id']);
        return ['replied' => true, 'reply' => $reply, 'action' => 'ticket_created'];
    }

    private function supportTicketStatus(array $conv, string $phone, ?string $displayName): array
    {
        // Look up recent tickets for this phone
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, subject, status, created_at FROM support_tickets
                 WHERE phone = ? OR customer_phone = ?
                 ORDER BY created_at DESC LIMIT 3"
            );
            $stmt->execute([$phone, $phone]);
            $tickets = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $tickets = [];
        }

        if (empty($tickets)) {
            $reply = "I don't see any recent tickets for your number. If you have an issue, just describe it and I'll create one for you.";
        } else {
            $reply = "Here's what I found:\n\n";
            foreach ($tickets as $t) {
                $status = ucfirst($t['status'] ?? 'open');
                $date = substr($t['created_at'] ?? '', 0, 10);
                $reply .= "#{$t['id']} — {$t['subject']} ({$status}, {$date})\n";
            }
            $reply .= "\nNeed more help? Send 4 to talk to someone.";
        }
        $this->sendReply($phone, $reply, 'support', $conv['id']);
        return ['replied' => true, 'reply' => $reply, 'action' => 'ticket_status'];
    }

    // ══════════════════════════════════════════════════════════════════════
    //  ACCOUNTS CHANNEL
    // ══════════════════════════════════════════════════════════════════════

    private function handleAccountsChannel(array $conv, string $phone, string $text, string $textLower, ?string $displayName): array
    {
        $state = $conv['state'] ?? 'new';
        $name  = $displayName ?: ($conv['display_name'] ?? 'there');

        // ── State: claude_active — Claude is handling this conversation ──
        if ($state === 'claude_active') {
            // Fix 5: Auto-escalate after 6 bot turns
            $botTurnCount = 0;
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM wa_messages WHERE conversation_id = ? AND direction = 'out' AND role = 'bot'"
                );
                $stmt->execute([$conv['id']]);
                $botTurnCount = (int)$stmt->fetchColumn();
            } catch (\Throwable $e) {}

            if ($botTurnCount >= 6) {
                $this->updateConvState($conv['id'], 'needs_human');
                $escMsg = $this->getEscalationMessage();
                $reply  = "Let me get our accounts team involved to make sure this is fully resolved. {$escMsg}";
                $this->sendReply($phone, $reply, 'accounts', $conv['id']);
                $this->alertStaff('accounts', $phone, $displayName ?? 'Customer',
                    "Auto-escalated after {$botTurnCount} bot turns — customer needs human follow-up.\nLast message: " . mb_substr($text, 0, 200));
                return ['replied' => true, 'reply' => $reply, 'action' => 'auto_escalated_turns'];
            }

            $aiReply = $this->getAiReply($phone, $text, 'accounts', $conv);
            if ($aiReply) {
                $this->sendReply($phone, $aiReply, 'accounts', $conv['id']);
                return ['replied' => true, 'reply' => $aiReply, 'action' => 'ai_continued'];
            }
            $this->updateConvState($conv['id'], 'needs_human');
            $escMsg = $this->getEscalationMessage();
            $reply  = "Let me connect you with our accounts team — {$escMsg}";
            $this->sendReply($phone, $reply, 'accounts', $conv['id']);
            $this->alertStaff('accounts', $phone, $displayName ?? 'Customer', $text);
            return ['replied' => true, 'reply' => $reply, 'action' => 'escalated'];
        }

        // ── For NEW/returning conversations: Claude first, menu as fallback ──
        if ($state === 'new' || $state === 'bot_replied') {
            // Keyword shortcuts still fire immediately (fast path for known intents)
            if (preg_match('/\b(balance|owe|owing|bill|amount|how much)\b/i', $textLower)) {
                return $this->accountsBalance($conv, $phone, $displayName);
            }
            if (preg_match('/\b(payment|paid|pay|receipt|received|confirm)\b/i', $textLower)) {
                return $this->accountsLastPayment($conv, $phone, $displayName);
            }
            if (preg_match('/\b(invoice|bill copy)\b/i', $textLower)) {
                return $this->accountsInvoiceInfo($conv, $phone, $displayName);
            }
            // Try Claude first for everything else
            $aiReply = $this->getAiReply($phone, $text, 'accounts', $conv);
            if ($aiReply) {
                $this->updateConvState($conv['id'], 'claude_active');
                $this->sendReply($phone, $aiReply, 'accounts', $conv['id']);
                return ['replied' => true, 'reply' => $aiReply, 'action' => 'ai_direct'];
            }
            // Claude not available — fall back to menu
            $this->updateConvState($conv['id'], 'accounts_menu_sent');
            $reply = $this->pickVariant('accounts_greeting', $name, $conv['id']);
            $this->sendReply($phone, $reply, 'accounts', $conv['id']);
            return ['replied' => true, 'reply' => $reply, 'action' => 'menu_sent'];
        }

        // ── Post-menu: handle number selections + keywords ──
        if ($state === 'accounts_menu_sent') {
            if (in_array($textLower, ['1'], true) || preg_match('/\b(balance|owe|owing|bill|amount|how much)\b/i', $textLower)) {
                return $this->accountsBalance($conv, $phone, $displayName);
            }
            if (in_array($textLower, ['2'], true) || preg_match('/\b(payment|paid|pay|receipt|received|confirm)\b/i', $textLower)) {
                return $this->accountsLastPayment($conv, $phone, $displayName);
            }
            if (in_array($textLower, ['3'], true) || preg_match('/\b(invoice|bill copy|send.*invoice|invoice.*copy)\b/i', $textLower)) {
                return $this->accountsInvoiceInfo($conv, $phone, $displayName);
            }
            if ($textLower === '4') {
                $this->updateConvState($conv['id'], 'needs_human');
                $escMsg = $this->getEscalationMessage();
                $reply  = "Sure, let me connect you with our accounts team. {$escMsg}";
                $this->sendReply($phone, $reply, 'accounts', $conv['id']);
                $this->alertStaff('accounts', $phone, $displayName ?? 'Customer', $text);
                return ['replied' => true, 'reply' => $reply, 'action' => 'escalated'];
            }

            // Try template match for specific queries
            $templateReply = $this->tryTemplateMatch($text, 'accounts');
            if ($templateReply) {
                $this->sendReply($phone, $templateReply, 'accounts', $conv['id']);
                return ['replied' => true, 'reply' => $templateReply, 'action' => 'template_match'];
            }

            // Unrecognized — try AI before falling back to menu nudge
            $aiReply = $this->getAiReply($phone, $text, 'accounts', $conv);
            if ($aiReply) {
                $this->updateConvState($conv['id'], 'claude_active');
                $this->sendReply($phone, $aiReply, 'accounts', $conv['id']);
                return ['replied' => true, 'reply' => $aiReply, 'action' => 'ai_reply'];
            }

            $reply = "I can help with:\n\n1 - Check balance\n2 - Confirm payment\n3 - Get invoice\n4 - Talk to accounts\n\nJust pick a number or tell me what you need.";
            $this->sendReply($phone, $reply, 'accounts', $conv['id']);
            return ['replied' => true, 'reply' => $reply, 'action' => 'menu_reminder'];
        }

        return ['replied' => false, 'reply' => '', 'action' => 'no_action'];
    }

    private function accountsBalance(array $conv, string $phone, ?string $displayName): array
    {
        $client = $this->lookupCrmClient($phone);
        if (!$client) {
            $reply = "Hmm, I can't find an account linked to this number. Could you tell me your name or account number?\n\nOr send 4 and our accounts team can help look you up.";
            $this->sendReply($phone, $reply, 'accounts', $conv['id']);
            return ['replied' => true, 'reply' => $reply, 'action' => 'crm_not_found'];
        }

        $name     = $client['name'] ?? 'Customer';
        $balance  = (float)($client['accountBalance'] ?? $client['balance'] ?? 0);
        $balStr   = '$' . number_format(abs($balance), 2);

        // Get active services
        $services = $this->getClientServices($client['id']);
        $svcLine  = '';
        if (!empty($services)) {
            $plans = array_map(fn($s) => $s['name'] ?? $s['servicePlanName'] ?? 'Service', $services);
            $svcLine = "\n📡 Services: " . implode(', ', array_slice($plans, 0, 3));
            if (count($plans) > 3) $svcLine .= ' + ' . (count($plans) - 3) . ' more';
        }

        if ($balance > 0.01) {
            $reply = "Hi {$name}, your current balance is {$balStr}.{$svcLine}\n\nYou can pay via Mobile Money, bank transfer (Equity/Stanbic), or cash to a DishNet agent.\n\nOnce paid, send 2 here to confirm.";
        } elseif ($balance < -0.01) {
            $reply = "Hi {$name}, you actually have a credit of {$balStr} on your account.{$svcLine}\n\nThat'll be applied to your next invoice automatically. You're all good!";
        } else {
            $reply = "Hi {$name}, your account is all clear — \$0 balance.{$svcLine}\n\nNothing to worry about!";
        }

        // Link conversation to CRM client
        $this->linkConvToCrm($conv['id'], (int)$client['id'], $name);

        $this->sendReply($phone, $reply, 'accounts', $conv['id']);
        return ['replied' => true, 'reply' => $reply, 'action' => 'balance_lookup'];
    }

    private function accountsLastPayment(array $conv, string $phone, ?string $displayName): array
    {
        $client = $this->lookupCrmClient($phone);
        if (!$client) {
            $reply = "Hmm, I can't find an account linked to this number. Send 4 and our accounts team can help look you up.";
            $this->sendReply($phone, $reply, 'accounts', $conv['id']);
            return ['replied' => true, 'reply' => $reply, 'action' => 'crm_not_found'];
        }

        $name = $client['name'] ?? 'Customer';

        // Get last payment from CRM
        $payment = $this->getLastPayment((int)$client['id']);
        if (!$payment) {
            $reply = "Hey {$name}, I don't see any recent payments on your account. If you've paid recently, it can take up to 24 hours to show up.\n\nSend 4 if you'd like to talk to our accounts team about this.";
        } else {
            $amt  = '$' . number_format((float)($payment['amount'] ?? 0), 2);
            $date = substr($payment['createdDate'] ?? $payment['created_at'] ?? '', 0, 10);
            $method = $payment['methodName'] ?? 'Unknown';
            $note = substr($payment['note'] ?? '', 0, 60);

            $reply = "Hi {$name}, your last payment was {$amt} on {$date} via {$method}."
                   . ($note ? " Note: {$note}" : "")
                   . "\n\nAll confirmed in our system!";
        }

        $this->linkConvToCrm($conv['id'], (int)$client['id'], $name);
        $this->sendReply($phone, $reply, 'accounts', $conv['id']);
        return ['replied' => true, 'reply' => $reply, 'action' => 'payment_lookup'];
    }

    private function accountsInvoiceInfo(array $conv, string $phone, ?string $displayName): array
    {
        $client = $this->lookupCrmClient($phone);
        if (!$client) {
            $reply = "Hmm, I can't find an account linked to this number. Send 4 and our accounts team can help look you up.";
            $this->sendReply($phone, $reply, 'accounts', $conv['id']);
            return ['replied' => true, 'reply' => $reply, 'action' => 'crm_not_found'];
        }

        $name = $client['name'] ?? 'Customer';

        // Get latest unpaid invoice
        $invoice = $this->getLatestInvoice((int)$client['id']);
        if (!$invoice) {
            $reply = "Good news {$name} — no unpaid invoices on your account. You're all up to date!";
        } else {
            $amt = '$' . number_format((float)($invoice['total'] ?? 0), 2);
            $num = $invoice['number'] ?? $invoice['id'];
            $due = substr($invoice['dueDate'] ?? $invoice['maturityDate'] ?? '', 0, 10);
            $status = ['0' => 'Draft', '1' => 'Unpaid', '2' => 'Partially paid', '3' => 'Paid'];
            $invStatus = $status[(string)($invoice['status'] ?? '1')] ?? 'Unpaid';

            $reply = "Hi {$name}, your latest invoice is #{$num} for {$amt}, due {$due}. Status: {$invStatus}.\n\nIf you need a PDF copy, send 4 and our team will send it over.";
        }

        $this->linkConvToCrm($conv['id'], (int)$client['id'], $name);
        $this->sendReply($phone, $reply, 'accounts', $conv['id']);
        return ['replied' => true, 'reply' => $reply, 'action' => 'invoice_lookup'];
    }

    // ══════════════════════════════════════════════════════════════════════
    //  CRM LOOKUP
    // ══════════════════════════════════════════════════════════════════════

    private function getCrm(): ?\CrmApiClient
    {
        if ($this->crm !== null) return $this->crm;
        try {
            require_once dirname(__FILE__) . '/CrmApiClient.php';
            $url = trim($this->config['crm_base_url'] ?? $this->config['ucrm_base_url'] ?? '');
            $key = trim($this->config['crm_api_key'] ?? $this->config['ucrm_api_key'] ?? '');
            if ($url && $key) {
                $this->crm = new \CrmApiClient($url, $key);
            }
        } catch (\Throwable $e) {}
        return $this->crm;
    }

    /**
     * Find CRM client by phone number.
     */
    private function lookupCrmClient(string $phone): ?array
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) < 8) return null;

        // 1. Try local search index first (fast)
        try {
            $searchIdx = $this->store->load('client_search_index.json') ?? [];
            foreach ($searchIdx as $c) {
                $cPhone = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
                if ($cPhone && (str_ends_with($cPhone, $phone) || str_ends_with($phone, $cPhone))) {
                    // Found in index — fetch full client from CRM
                    $crm = $this->getCrm();
                    if ($crm) {
                        $full = $crm->get("clients/{$c['id']}");
                        if ($full) {
                            $full['name'] = trim(($full['firstName'] ?? '') . ' ' . ($full['lastName'] ?? ''))
                                         ?: ($full['companyName'] ?? 'Customer');
                            return $full;
                        }
                    }
                    // Return index data as fallback
                    return $c;
                }
            }
        } catch (\Throwable $e) {}

        // 2. Try CRM API search
        $crm = $this->getCrm();
        if (!$crm) return null;

        try {
            // Search by phone suffix (last 9 digits)
            $suffix = substr($phone, -9);
            $results = $crm->get("clients?phone={$suffix}&limit=5") ?? [];
            if (empty($results)) {
                $results = $crm->get("clients?search={$suffix}&limit=5") ?? [];
            }
            foreach ($results as $r) {
                // Verify phone match
                foreach (($r['contacts'] ?? []) as $ct) {
                    $ctPhone = preg_replace('/[^0-9]/', '', $ct['phone'] ?? '');
                    if ($ctPhone && (str_ends_with($ctPhone, $suffix) || str_ends_with($suffix, $ctPhone))) {
                        $r['name'] = trim(($r['firstName'] ?? '') . ' ' . ($r['lastName'] ?? ''))
                                  ?: ($r['companyName'] ?? 'Customer');
                        return $r;
                    }
                }
            }
        } catch (\Throwable $e) {}

        return null;
    }

    private function getClientServices(int $clientId): array
    {
        $crm = $this->getCrm();
        if (!$crm) return [];
        try {
            return $crm->get("clients/{$clientId}/services") ?? [];
        } catch (\Throwable $e) { return []; }
    }

    private function getLastPayment(int $clientId): ?array
    {
        $crm = $this->getCrm();
        if (!$crm) return null;
        try {
            $payments = $crm->get("payments?clientId={$clientId}&limit=1") ?? [];
            return $payments[0] ?? null;
        } catch (\Throwable $e) { return null; }
    }

    private function getLatestInvoice(int $clientId): ?array
    {
        $crm = $this->getCrm();
        if (!$crm) return null;
        try {
            $invoices = $crm->get("invoices?clientId={$clientId}&statuses[]=1&statuses[]=2&limit=1") ?? [];
            if (empty($invoices)) {
                $invoices = $crm->get("billing/invoices?clientId={$clientId}&limit=1") ?? [];
            }
            return $invoices[0] ?? null;
        } catch (\Throwable $e) { return null; }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Pick a random message variant — makes the bot feel more human.
     * Each greeting set has 5+ versions. Name is inserted if available.
     */
    private $_staffPhones = null;

    /**
     * Handle closing/gratitude phrases — "thank you", "ok", "bye", "good" etc.
     * Returns result array if handled, null if not a closing phrase.
     */
    private function handleClosingPhrase(string $textLower, string $phone, string $channel, int $convId): ?array
    {
        // Common closing/gratitude/acknowledgement phrases
        $_closingPatterns = [
            '/^(thanks|thank you|thank u|thanx|thx|thnx|thnks|asante|shukran)[\s!.]*$/i',
            '/^(ok|okay|okey|okie|alright|noted|sure|fine|good|great|nice|cool|perfect|wonderful|awesome)[\s!.]*$/i',
            '/^(bye|goodbye|good bye|see you|see ya|later|take care|good night|good morning)[\s!.]*$/i',
            '/^(no|nope|not now|nothing|never mind|nevermind|nvm|no thanks|no thank)[\s!.]*$/i',
            '/^(yes|yep|yeah|ya|yah|yea)[\s!.]*$/i',
            '/^(welcome|you\'?re welcome|👍|👌|🙏|😊|❤|♥|🤝)[\s!.]*$/i',
        ];

        foreach ($_closingPatterns as $pattern) {
            if (preg_match($pattern, $textLower)) {
                // Pick a natural closing response
                $closings = [
                    "You're welcome! Reach out anytime you need help.",
                    "Happy to help! Message us anytime.",
                    "Anytime! We're here if you need us.",
                    "Glad we could help. Have a great day!",
                    "No problem at all. Take care!",
                ];
                $reply = $closings[array_rand($closings)];
                $this->sendReply($phone, $reply, $channel, $convId);
                // Close the conversation
                $this->updateConv($convId, ['state' => 'closed', 'status' => 'closed']);
                return ['replied' => true, 'reply' => $reply, 'action' => 'closing_phrase'];
            }
        }
        return null;
    }

    /**
     * Detect conversational closers — thank you, ok, bye, etc.
     */
    private function isConversationalCloser(string $textLower): bool
    {
        $closers = [
            // Thanks
            'thank you', 'thanks', 'thankyou', 'thx', 'thnx', 'ty',
            'thank u', 'thanks a lot', 'thanks so much', 'much appreciated',
            'shukran', 'asante',
            // Acknowledgments
            'ok', 'okay', 'ok thanks', 'okay thanks', 'alright', 'noted',
            'got it', 'understood', 'cool', 'great', 'perfect', 'nice',
            'fine', 'good', 'sure',
            // Goodbyes
            'bye', 'goodbye', 'good bye', 'see you', 'take care',
            'have a good day', 'have a nice day', 'goodnight', 'good night',
            // Simple reactions
            '👍', '👌', '🙏', '❤️', '😊', '🤝',
        ];
        // Exact match
        if (in_array($textLower, $closers, true)) return true;
        // Starts with thank
        if (strpos($textLower, 'thank') === 0) return true;
        // "ok" variants at start
        if (preg_match('/^(ok|okay|alright)\b/i', $textLower)) return true;
        return false;
    }

    /**
     * Pick a warm closing reply — varied to feel human.
     */
    private function pickCloserReply(string $textLower, string $name = ''): string
    {
        $hasName = !empty($name) && $name !== 'Unknown' && $name !== 'there';

        // Thanks replies
        if (strpos($textLower, 'thank') !== false || in_array($textLower, ['shukran', 'asante', 'thx', 'thnx', 'ty', '🙏'], true)) {
            $variants = [
                "You're welcome" . ($hasName ? " {$name}" : "") . "! Let us know if you need anything else.",
                "Happy to help" . ($hasName ? ", {$name}" : "") . "! We're here if you need us.",
                "Anytime! Don't hesitate to reach out again.",
                "Glad we could help! Have a great day.",
                "No problem at all" . ($hasName ? " {$name}" : "") . ". Take care!",
            ];
            return $variants[array_rand($variants)];
        }

        // Bye replies
        if (in_array($textLower, ['bye', 'goodbye', 'good bye', 'see you', 'take care', 'goodnight', 'good night'], true)
            || strpos($textLower, 'have a') === 0) {
            $variants = [
                "Take care" . ($hasName ? " {$name}" : "") . "! 👋",
                "Bye! Reach out anytime you need help.",
                "Have a good one! We're always here.",
                "See you" . ($hasName ? " {$name}" : "") . "! Take care.",
            ];
            return $variants[array_rand($variants)];
        }

        // Ok/acknowledgment — short reply, don't over-talk
        $variants = [
            "All good! Let us know if you need anything else.",
            "Got it. We're here if you need us!",
            "No worries! Reach out anytime.",
        ];
        return $variants[array_rand($variants)];
    }

    /**
     * Check if phone belongs to a DishNet staff member.
     */
    private function isStaffPhone(string $phone): bool
    {
        if ($this->_staffPhones === null) {
            $this->_staffPhones = [];
            try {
                $retailers = $this->store->load('retailers.json') ?? [];
                foreach ($retailers as $r) {
                    $ph = preg_replace('/[^0-9]/', '', $r['phone'] ?? '');
                    if (strlen($ph) >= 9) {
                        $this->_staffPhones[substr($ph, -9)] = true;
                    }
                }
            } catch (\Throwable $e) {}
            // Also add known business numbers
            $this->_staffPhones['921443002'] = true; // Support
            $this->_staffPhones['921443006'] = true; // Accounts/Admin
        }

        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) >= 9) {
            return isset($this->_staffPhones[substr($phone, -9)]);
        }
        return false;
    }

    /**
     * Pick a random message variant — makes the bot feel more human.
     * Uses ---SPLIT--- to send greeting and options as separate messages.
     * Detects returning customers for a warmer welcome.
     */
    private function pickVariant(string $key, string $name = 'there', ?int $convId = null): string
    {
        $hasName = ($name !== 'there' && strlen($name) > 1);
        $n = $hasName ? $name : '';

        // ── Detect returning customer ──
        $isReturning = false;
        if ($convId) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM wa_messages WHERE conversation_id = ? AND direction = 'in' AND sent_at < datetime('now', '-1 day')"
                );
                $stmt->execute([$convId]);
                $isReturning = ((int)$stmt->fetchColumn()) > 0;
            } catch (\Throwable $e) {}
        }

        // ── Returning customer: skip formal greeting ──
        if ($isReturning && $hasName) {
            $returning = [
                'support_greeting' => [
                    "Hey {$n}, good to hear from you again! What's going on?"
                        . "\n---SPLIT---\n"
                        . "1 - Internet down\n2 - Slow speed\n3 - New connection\n4 - Talk to someone",
                    "Hi {$n}! Welcome back. Same issue or something new?"
                        . "\n---SPLIT---\n"
                        . "1 - No internet\n2 - Speed issue\n3 - New setup\n4 - Other",
                    "{$n}! How can we help this time?"
                        . "\n---SPLIT---\n"
                        . "1 - Internet down\n2 - Slow\n3 - New connection\n4 - Agent",
                ],
                'accounts_greeting' => [
                    "Hey {$n}, welcome back! What do you need?"
                        . "\n---SPLIT---\n"
                        . "1 - Balance\n2 - Payment status\n3 - Invoice\n4 - Talk to accounts",
                    "Hi {$n}! Good to see you again. How can I help?"
                        . "\n---SPLIT---\n"
                        . "1 - Check balance\n2 - Confirm payment\n3 - Invoice\n4 - Other",
                ],
            ];
            $pool = $returning[$key] ?? [];
            if (!empty($pool)) return $pool[array_rand($pool)];
        }

        // ── First-time greeting (split: friendly hello + options separately) ──
        $variants = [
            'support_greeting' => [
                "Hey" . ($hasName ? " {$n}" : "") . "! Thanks for reaching out."
                    . "\n---SPLIT---\n"
                    . "What's going on?\n\n1 - Internet is down\n2 - It's slow\n3 - I want a new connection\n4 - Something else",
                "Hi" . ($hasName ? " {$n}" : "") . ", this is DishNet support."
                    . "\n---SPLIT---\n"
                    . "How can we help?\n\n1 - Internet down\n2 - Slow speed\n3 - New connection\n4 - Talk to someone",
                "Hello" . ($hasName ? " {$n}" : "") . "! DishNet here."
                    . "\n---SPLIT---\n"
                    . "Tell me what's up:\n\n1 - No internet\n2 - Slow connection\n3 - New setup\n4 - Other issue",
                "Hi" . ($hasName ? " {$n}" : " there") . "!"
                    . "\n---SPLIT---\n"
                    . "What do you need help with?\n\n1 - Internet not working\n2 - Speed problems\n3 - Want to connect\n4 - Need an agent",
                ($hasName ? "{$n}, h" : "H") . "ey! Welcome to DishNet."
                    . "\n---SPLIT---\n"
                    . "Pick what you need:\n\n1 - Internet is down\n2 - Slow speed\n3 - New connection\n4 - Speak to someone",
            ],
            'accounts_greeting' => [
                "Hey" . ($hasName ? " {$n}" : "") . "! Thanks for contacting DishNet accounts."
                    . "\n---SPLIT---\n"
                    . "How can I help?\n\n1 - Check my balance\n2 - Confirm a payment\n3 - Get my invoice\n4 - Talk to accounts",
                "Hi" . ($hasName ? " {$n}" : "") . ", DishNet accounts here."
                    . "\n---SPLIT---\n"
                    . "What do you need?\n\n1 - Balance\n2 - Payment status\n3 - Invoice copy\n4 - Speak to someone",
                "Hello" . ($hasName ? " {$n}" : "") . "!"
                    . "\n---SPLIT---\n"
                    . "I can help with:\n\n1 - How much do I owe?\n2 - Did my payment go through?\n3 - Send me my invoice\n4 - Something else",
                "Hi" . ($hasName ? " {$n}" : " there") . "!"
                    . "\n---SPLIT---\n"
                    . "What can I do for you?\n\n1 - Check balance\n2 - Verify payment\n3 - Invoice\n4 - Talk to accounts team",
                ($hasName ? "{$n}, w" : "W") . "elcome to DishNet accounts."
                    . "\n---SPLIT---\n"
                    . "Pick one:\n\n1 - Balance check\n2 - Payment confirmation\n3 - Invoice\n4 - Connect to accounts",
            ],
        ];

        $pool = $variants[$key] ?? [];
        if (empty($pool)) return "Hi! How can I help?";
        return $pool[array_rand($pool)];
    }

    // ══════════════════════════════════════════════════════════════════════
    //  STARLINK WiFi PASSWORD / NAME CHANGE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Detect WiFi password/name change requests.
     * DishNet manages all Starlink WiFi settings — customers cannot self-serve.
     */
    private function isWifiChangeRequest(string $textLower): bool
    {
        $triggers = [
            'wifi password', 'wi-fi password', 'wifipassword', 'wifi pass',
            'change password', 'new password', 'password change', 'update password',
            'change wifi', 'change wi-fi', 'change my wifi', 'update wifi',
            'wifi name', 'change ssid', 'network name', 'change network name',
            'forgot password', 'forget password', 'reset wifi', 'wifi reset',
            'new wifi', 'change my password', 'renew password',
        ];
        foreach ($triggers as $kw) {
            if (str_contains($textLower, $kw)) return true;
        }
        return false;
    }

    /**
     * Step 1 — Ask for the new password.
     * Sets state to starlink_pw_collecting.
     */
    private function starlinkRequestPassword(array $conv, string $phone, ?string $displayName): array
    {
        $this->updateConvState($conv['id'], 'starlink_pw_collecting');
        $name     = $conv['crm_client_name'] ?? $displayName ?? $conv['display_name'] ?? null;
        $greeting = $name ? "Sure {$name}!" : "Sure!";
        $reply    = "{$greeting} What would you like the new WiFi password to be?\n\n"
                  . "_(Minimum 8 characters, no spaces)_";
        $this->sendReply($phone, $reply, 'support', $conv['id']);
        return ['replied' => true, 'reply' => $reply, 'action' => 'starlink_pw_requested'];
    }

    /**
     * Step 2 — Validate and collect the new password, notify staff.
     * Staff change it via the DishNet Starlink app — customer never gets app access.
     */
    private function starlinkCollectPassword(array $conv, string $phone, string $text, ?string $displayName): array
    {
        $pw = trim($text);

        // ── Validate ─────────────────────────────────────────────────────
        if (strlen($pw) < 8) {
            $reply = "That's a bit short — WiFi passwords need to be *at least 8 characters*.\n\nTry a different one:";
            $this->sendReply($phone, $reply, 'support', $conv['id']);
            return ['replied' => true, 'reply' => $reply, 'action' => 'starlink_pw_too_short'];
        }
        if (strpos($pw, ' ') !== false) {
            $reply = "WiFi passwords can't have spaces — try again without any spaces:";
            $this->sendReply($phone, $reply, 'support', $conv['id']);
            return ['replied' => true, 'reply' => $reply, 'action' => 'starlink_pw_has_spaces'];
        }

        // ── Valid — store, alert staff, close this flow ───────────────────
        $name = $conv['crm_client_name'] ?? $displayName ?? $conv['display_name'] ?? 'Customer';

        $this->updateConv($conv['id'], [
            'collected_wifi_pw' => $pw,
            'state'             => 'needs_human',
        ]);

        // Alert staff with everything they need — no CRM job for this, just WA ping
        $staffMsg = "🔑 *WiFi Password Change Request*\n\n"
                  . "👤 *{$name}*\n"
                  . "📞 {$phone}\n"
                  . "🔐 New password: *{$pw}*\n\n"
                  . "_Change via DishNet Starlink app. Reply to customer once done._";
        try {
            $this->notify->sendAdmin($staffMsg, 'starlink_wifi_change', ['phone' => $phone]);
        } catch (\Throwable $e) {
            error_log('[WaAutoReply] WiFi change alert failed: ' . $e->getMessage());
        }

        // Time-aware confirmation to customer
        $escMsg = $this->getEscalationMessage();
        $reply  = "Got it! I've sent that to our team — they'll update your WiFi password and message you when it's done. {$escMsg}\n\n"
                . "⚠️ Once changed, all your devices will disconnect. Just reconnect with the new password.";
        $this->sendReply($phone, $reply, 'support', $conv['id']);
        return ['replied' => true, 'reply' => $reply, 'action' => 'starlink_pw_collected'];
    }

    /**
     * Time-aware escalation message.
     * Returns "within the hour" during office hours (8 AM–8 PM EAT),
     * "first thing tomorrow morning (from 8 AM)" outside hours.
     */
    private function getEscalationMessage(): string
    {
        $eatHour = ((int)gmdate('G') + 3) % 24;
        $withinHours = ($eatHour >= 8 && $eatHour < 20);
        if ($withinHours) {
            $timings = [
                "Someone from our team will follow up within the hour.",
                "Our team will get back to you within the hour.",
                "You'll hear from us within the hour.",
            ];
        } else {
            $timings = [
                "Our office is closed right now (8 AM–8 PM EAT), but the team will get back to you first thing tomorrow morning.",
                "It's outside office hours, but someone will follow up with you first thing tomorrow morning (from 8 AM EAT).",
                "We're closed for the night — our team will reach out first thing tomorrow morning.",
            ];
        }
        return $timings[array_rand($timings)];
    }

    /**
     * Send reply with human-like delay.
     * If message contains \n---SPLIT---\n, sends as two separate messages with a pause.
     */
    private function sendReply(string $phone, string $message, string $channel, int $convId): void
    {
        if (empty($phone) || empty($message)) return;

        $sender = $channel === 'accounts'
            ? \NotificationService::ACCOUNTS
            : \NotificationService::SUPPORT;

        // ── Human-like delay: 5-15 seconds before first reply ──
        $delay = rand(5, 15);
        sleep($delay);

        // ── Split message support: greeting first, then options ──
        $parts = explode("\n---SPLIT---\n", $message);
        $fullText = str_replace("\n---SPLIT---\n", "\n", $message); // for storage

        try {
            foreach ($parts as $i => $part) {
                $part = trim($part);
                if (empty($part)) continue;
                if ($i > 0) sleep(rand(2, 4)); // pause between parts
                $this->notify->sendVia($sender, $phone, $part, 'auto_reply_' . $channel, []);
            }
        } catch (\Throwable $e) {
            error_log("[WaAutoReply] Send failed: " . $e->getMessage());
        }

        // Store full reply in conversation
        try {
            $this->convSvc->storeMessage($convId, [
                'direction'  => 'out',
                'role'       => 'bot',
                'body'       => $fullText,
                'agent_name' => 'Auto-Reply (' . ucfirst($channel) . ')',
                'sent_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {}

        $this->updateConv($convId, ['bot_replied_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Get an AI-powered reply via Claude when keyword matching fails.
     * Builds context from CRM data and passes to the configured AI client.
     * Provider is selected by config key 'ai_provider' ('claude' or 'openai').
     * Returns null if API key not configured, message is trivial, or API fails.
     */
    private function getAiReply(string $phone, string $text, string $channel, array $conv): ?string
    {
        $provider    = trim($this->config['ai_provider'] ?? 'claude');   // 'claude' or 'openai'
        $customInstr = trim($this->config['bot_custom_instructions'] ?? '');
        $instrMode   = trim($this->config['bot_instructions_mode'] ?? 'append'); // 'append' or 'override'

        // Pick the right API key based on provider
        if ($provider === 'openai') {
            $apiKey = trim($this->config['openai_api_key'] ?? '');
        } else {
            $apiKey = trim($this->config['claude_api_key'] ?? '');
        }
        if (empty($apiKey)) return null;

        try {
            // Load provider client
            if ($provider === 'openai') {
                require_once dirname(__FILE__) . '/GptWaClient.php';
                $aiClient = new \GptWaClient($apiKey, $this->pdo);
            } else {
                require_once dirname(__FILE__) . '/ClaudeWaClient.php';
                $aiClient = new \ClaudeWaClient($apiKey, $this->pdo);
            }

            // Build customer context from CRM
            $ctx = [];
            $client = $this->lookupCrmClient($phone);
            if ($client) {
                $ctx['name']         = $client['name'] ?? null;
                $ctx['balance']      = $client['balance'] ?? null;
                $ctx['status']       = $client['isLead'] ?? false ? 'Lead' : ($client['isActive'] ?? true ? 'Active' : 'Suspended');
                // Try to get service info
                $services = $this->getClientServices((int)($client['id'] ?? 0));
                if (!empty($services)) {
                    $svc = $services[0];
                    $ctx['service_type'] = $svc['name'] ?? null;
                    $ctx['plan_name']    = $svc['servicePlanName'] ?? null;
                    // Plan expiry — UCRM field is 'activeTo' (ISO date string)
                    if (!empty($svc['activeTo'])) {
                        $ctx['active_to'] = substr($svc['activeTo'], 0, 10); // "2026-04-05"
                    }
                }
                // Last payment
                $lastPay = $this->getLastPayment((int)($client['id'] ?? 0));
                if ($lastPay) {
                    $ctx['last_payment'] = '$' . number_format((float)($lastPay['amount'] ?? 0), 2)
                        . ' on ' . substr($lastPay['createdDate'] ?? '', 0, 10);
                }
            }

            // ── Splynx live data enrichment ──────────────────────────────
            // Triggers when:
            //   a) CRM identifies this as a fiber/FTTH customer, OR
            //   b) CRM found no client at all — they may be fiber-only in Splynx
            // Gives Claude: live online/offline status, plan speeds, open tickets, IP.
            $serviceType = strtolower($ctx['service_type'] ?? '');
            $isFiber     = ($serviceType === 'fiber' || $serviceType === 'ftth');
            $noClient    = empty($client);

            if ($isFiber || $noClient) {
                $splynxCtx = $this->getFiberSplynxContext($phone);
                if (!empty($splynxCtx)) {
                    $ctx['splynx'] = $splynxCtx;

                    // If CRM had no client, fill ctx from Splynx
                    if ($noClient) {
                        if (!empty($splynxCtx['customer_name']))  $ctx['name']         = $splynxCtx['customer_name'];
                        if (!empty($splynxCtx['plan_name']))      $ctx['plan_name']     = $splynxCtx['plan_name'];
                        if (!empty($splynxCtx['service_address'])) $ctx['address']      = $splynxCtx['service_address'];
                        $ctx['service_type'] = 'fiber';
                    }

                    // Always prefer Splynx account status for fiber (more accurate)
                    if (!empty($splynxCtx['customer_status'])) {
                        $splynxStatus = strtolower($splynxCtx['customer_status']);
                        // Map Splynx statuses to friendly labels
                        $statusMap = [
                            'active'   => 'Active',
                            'blocked'  => 'Suspended',
                            'inactive' => 'Inactive',
                            'new'      => 'New',
                        ];
                        $ctx['status'] = $statusMap[$splynxStatus] ?? ucfirst($splynxCtx['customer_status']);
                    }
                }
            }

            // Recent conversation history (last 8 messages for context)
            $history = '';
            try {
                $msgs = $this->convSvc->getMessages((int)$conv['id'], 8, 0);
                $lines = [];
                foreach ($msgs as $m) {
                    $role = ($m['direction'] ?? 'in') === 'in' ? 'Customer' : 'DishNet';
                    $lines[] = $role . ': ' . mb_substr($m['body'] ?? '', 0, 150);
                }
                $history = implode("\n", $lines);
            } catch (\Throwable $e) {}

            return $aiClient->getReply($text, $ctx, $channel, $history, $customInstr, $instrMode);
        } catch (\Throwable $e) {
            error_log('[WaAutoReply] AI reply failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Pull live fiber line data from Splynx for a given phone number.
     * Returns array with: is_online, customer_status, service_status,
     * plan details, current session info, last seen, open tickets.
     * Returns [] if Splynx not configured or customer not found.
     */
    private function getFiberSplynxContext(string $phone): array
    {
        try {
            require_once dirname(__FILE__) . '/SplynxApiClient.php';
            $splynx = \SplynxApiClient::fromConfig($this->config);
            if (!$splynx->isConfigured()) return [];

            // ── Search customer by phone (try multiple formats) ──────────
            $customer = null;
            $phonesToTry = [$phone];

            // Strip leading + and try
            $stripped = ltrim($phone, '+');
            if ($stripped !== $phone) $phonesToTry[] = $stripped;

            // South Sudan: strip +211 prefix, try local format
            $local = preg_replace('/^\+?211/', '', $phone);
            if ($local && $local !== $phone) $phonesToTry[] = $local;
            if ($local && substr($local, 0, 1) !== '0') $phonesToTry[] = '0' . $local;

            foreach (array_unique($phonesToTry) as $tryPhone) {
                $found = $splynx->get('api/2.0/admin/customers/customer', ['phone' => $tryPhone]);
                if (!empty($found[0]['id'])) {
                    $customer = $found[0];
                    break;
                }
            }

            if (!$customer) return [];

            $splynxId = (int)$customer['id'];
            $ctx = [
                'splynx_id'       => $splynxId,
                'customer_status' => $customer['status'] ?? null,      // active, blocked, inactive
                'customer_name'   => trim(($customer['name'] ?? '') . ' ' . ($customer['last_name'] ?? '')),
                'service_address' => $customer['street_1'] ?? null,
            ];

            // ── Internet services ────────────────────────────────────────
            $services = $splynx->getCustomerServices($splynxId);
            if (!empty($services[0])) {
                $svc = $services[0];
                $ctx['service_status']    = $svc['status']       ?? null;  // active, disabled, suspended
                $ctx['plan_name']         = $svc['tariff_name']  ?? null;
                $ctx['assigned_ip']       = $svc['ip']           ?? null;
                $ctx['speed_down_mbps']   = $svc['speed_down']   ?? null;
                $ctx['speed_up_mbps']     = $svc['speed_up']     ?? null;
                $ctx['service_id']        = (int)($svc['id']     ?? 0);
                $ctx['mac_address']       = $svc['mac']          ?? null;
            }

            // ── Live online session check ────────────────────────────────
            // This is the most valuable data point: is the line online RIGHT NOW?
            $sessions = $splynx->get('api/2.0/admin/networking/online-sessions', [
                'customer_id' => $splynxId,
            ]);

            if (!empty($sessions) && is_array($sessions) && !empty($sessions[0])) {
                $sess = $sessions[0];
                $ctx['is_online']       = true;
                $ctx['session_start']   = $sess['started']  ?? null;
                $ctx['session_ip']      = $sess['ip']       ?? null;
                $ctx['nas_identifier']  = $sess['nas_id']   ?? ($sess['nas_ip'] ?? null);

                // Data used this session (bytes → MB)
                $downBytes = (int)($sess['acct_output_octets'] ?? $sess['output_octets'] ?? 0);
                $upBytes   = (int)($sess['acct_input_octets']  ?? $sess['input_octets']  ?? 0);
                if ($downBytes > 0) $ctx['session_down_mb'] = round($downBytes / 1048576, 1);
                if ($upBytes   > 0) $ctx['session_up_mb']   = round($upBytes   / 1048576, 1);

            } else {
                $ctx['is_online'] = false;

                // Get last time they were online from session history
                $history = $splynx->get('api/2.0/admin/networking/session-history', [
                    'customer_id' => $splynxId,
                    'sort_by'     => 'stop_time',
                    'sort_dir'    => 'desc',
                    'limit'       => 1,
                ]);
                if (!empty($history[0])) {
                    $ctx['last_seen'] = $history[0]['stop_time'] ?? $history[0]['started'] ?? null;
                }
            }

            // ── Open support tickets ─────────────────────────────────────
            $tickets = $splynx->getTickets(['customer_id' => $splynxId, 'status' => 1]); // 1=open
            if (!empty($tickets) && is_array($tickets)) {
                $ctx['open_ticket_count']   = count($tickets);
                $ctx['latest_ticket_title'] = $tickets[0]['subject'] ?? null;
                $ctx['latest_ticket_id']    = $tickets[0]['id']      ?? null;
            } else {
                $ctx['open_ticket_count'] = 0;
            }

            return $ctx;

        } catch (\Throwable $e) {
            error_log('[WaAutoReply] Splynx fiber context error: ' . $e->getMessage());
            return [];
        }
    }

    private function tryTemplateMatch(string $text, string $channel): ?string
    {
        try {
            require_once dirname(__FILE__) . '/TemplateReplyEngine.php';
            $engine = new \TemplateReplyEngine($this->pdo);
            $match  = $engine->findMatch($text, $channel);
            if ($match) {
                return $match['response_body'] ?? null;
            }
        } catch (\Throwable $e) {}
        return null;
    }

    private function updateConvState(int $convId, string $state): void
    {
        $this->updateConv($convId, ['state' => $state]);
    }

    private function updateConv(int $convId, array $fields): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        try {
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = "{$k} = ?";
                $vals[] = $v;
            }
            $vals[] = $convId;
            $this->pdo->prepare(
                "UPDATE wa_conversations SET " . implode(', ', $sets) . " WHERE id = ?"
            )->execute($vals);
        } catch (\Throwable $e) {
            error_log("[WaAutoReply] updateConv failed: " . $e->getMessage());
        }
    }

    private function linkConvToCrm(int $convId, int $clientId, string $clientName): void
    {
        $this->updateConv($convId, [
            'crm_client_id'   => $clientId,
            'crm_client_name' => $clientName,
        ]);
    }

    private function createSupportTicket(array $conv, string $name, string $issue, string $phone): string
    {
        $ticketRef = 'WA-' . date('ymd') . '-' . substr(md5($phone . time()), 0, 4);
        try {
            $this->pdo->prepare(
                "INSERT INTO support_tickets (subject, description, phone, customer_phone, customer_name, status, source, created_at)
                 VALUES (?, ?, ?, ?, ?, 'open', 'whatsapp_bot', datetime('now'))"
            )->execute([
                substr($issue, 0, 100),
                "Customer: {$name}\nPhone: {$phone}\nIssue: {$issue}\n\nCreated via WhatsApp Support Bot",
                $phone, $phone, $name,
            ]);
        } catch (\Throwable $e) {
            // support_tickets table may not exist — log but don't fail
            error_log("[WaAutoReply] Ticket creation failed: " . $e->getMessage());
        }

        // Alert support team
        $this->alertStaff('support', $phone, $name, "🎫 *New Ticket: {$ticketRef}*\n{$issue}");
        return $ticketRef;
    }

    private function alertStaff(string $channel, string $phone, string $name, string $context): void
    {
        try {
            $msg = "📱 *Customer needs help*\n\n"
                 . "👤 {$name}\n"
                 . "📞 {$phone}\n"
                 . "📝 {$context}\n\n"
                 . "_Please reply from the WhatsApp Inbox._";
            $this->notify->sendAdmin($msg, 'wa_escalation_' . $channel, ['phone' => $phone]);
        } catch (\Throwable $e) {}

        // ── Auto-create CRM scheduling job and assign to Bidal ──────────
        // All technical issues that need on-site or team attention go as
        // CRM jobs (not support tickets). Bidal is the primary field engineer.
        try {
            $this->createCrmJobForBidal($phone, $name, $context, $channel);
        } catch (\Throwable $e) {
            error_log('[WaAutoReply] alertStaff job creation error: ' . $e->getMessage());
        }
    }

    /**
     * Create a UCRM scheduling job and assign to Bidal (field engineer).
     * Also notifies Bidal on WhatsApp.
     * Called for every technical escalation — no support tickets, jobs only.
     */
    private function createCrmJobForBidal(
        string $phone,
        string $customerName,
        string $issueDesc,
        string $channel = 'support'
    ): bool {
        try {
            require_once dirname(__FILE__) . '/CrmApiClient.php';
            $crm = \CrmApiClient::fromUcrm(dirname(__DIR__), $this->config);
            if (!$crm->isConfigured()) return false;

            // ── Find Bidal in retailers.json ─────────────────────────────
            $retailers   = $this->store->load('retailers.json') ?? [];
            $bidalUcrmId = (int)($this->config['bidal_ucrm_user_id'] ?? 0);
            $bidalPhone  = '';

            foreach ($retailers as $r) {
                if (stripos($r['name'] ?? '', 'bidal') !== false) {
                    if (!empty($r['ucrm_user_id'])) $bidalUcrmId = (int)$r['ucrm_user_id'];
                    if (!empty($r['phone']))         $bidalPhone  = preg_replace('/[^0-9+]/', '', $r['phone']);
                    break;
                }
            }

            if (!$bidalUcrmId) {
                error_log('[WaAutoReply] createCrmJobForBidal: Bidal ucrm_user_id not found in retailers.json');
                return false;
            }

            // ── Look up UCRM client ID from conversation ─────────────────
            $crmClientId = 0;
            $address     = '';
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT crm_client_id FROM wa_conversations WHERE phone = ? AND status = 'active' LIMIT 1"
                );
                $stmt->execute([$phone]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $crmClientId = (int)($row['crm_client_id'] ?? 0);
            } catch (\Throwable $e) {}

            // Get client address from CRM if we have the ID
            if ($crmClientId) {
                $clientData = $crm->get("clients/{$crmClientId}");
                if ($clientData) {
                    $address = trim(
                        ($clientData['street1'] ?? '') . ' ' .
                        ($clientData['street2'] ?? '') . ' ' .
                        ($clientData['city']    ?? '')
                    );
                }
            }

            // ── Build job payload ────────────────────────────────────────
            $shortIssue = mb_substr($issueDesc, 0, 300);
            $jobPayload = [
                'title'          => 'WA Issue — ' . mb_substr($customerName, 0, 40),
                'date'           => date('Y-m-d') . 'T09:00:00.000Z',
                'duration'       => 60,
                'description'    => "Reported via WhatsApp ({$channel} line):\n\n{$shortIssue}\n\nCustomer phone: {$phone}",
                'status'         => 1,            // Open
                'assignedUserId' => $bidalUcrmId,
            ];
            if ($crmClientId) $jobPayload['clientId'] = $crmClientId;
            if ($address)     $jobPayload['address']  = $address;

            $newJob = $crm->post('scheduling/jobs', $jobPayload);
            if (!$newJob || empty($newJob['id'])) {
                error_log('[WaAutoReply] createCrmJobForBidal: CRM job creation failed — ' . json_encode($crm->getLastError()));
                return false;
            }
            $newJobId = (int)$newJob['id'];

            // Add default task
            $crm->post("scheduling/jobs/{$newJobId}/job-tasks", ['name' => 'Investigate and resolve reported issue']);

            // ── Notify Bidal on WhatsApp ─────────────────────────────────
            if ($bidalPhone) {
                $firstName = explode(' ', trim($customerName))[0] ?: 'Customer';
                $msg = "🔧 *New Job #{$newJobId}*\n"
                     . "Customer: *{$customerName}*\n"
                     . "Phone: {$phone}\n"
                     . ($address ? "Address: {$address}\n" : '')
                     . "Issue: " . mb_substr($shortIssue, 0, 150) . "\n\n"
                     . "_Via WhatsApp auto-support — check CRM for full details._";
                $this->notify->sendVia('support', $bidalPhone, $msg, 'wa_job_assigned_bidal', []);
            }

            error_log("[WaAutoReply] CRM Job #{$newJobId} created and assigned to Bidal (uid:{$bidalUcrmId}) for {$phone}");
            return true;

        } catch (\Throwable $e) {
            error_log('[WaAutoReply] createCrmJobForBidal exception: ' . $e->getMessage());
            return false;
        }
    }
}
