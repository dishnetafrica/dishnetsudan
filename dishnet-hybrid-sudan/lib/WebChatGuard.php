<?php
declare(strict_types=1);

/**
 * WebChatGuard — what stops the public chat endpoint from spending your money.
 *
 * The WhatsApp channel is naturally rate-limited: a message costs the sender a
 * phone number and Evolution's own throughput. A web endpoint has neither. It
 * is a URL that anyone can POST to in a loop, and every POST is a model call.
 * So the caps here are not a nicety, they are the reason the endpoint is safe
 * to expose at all.
 *
 * Four ceilings, checked in order, cheapest first:
 *
 *   1. per IP, short window      — stops a burst from one machine
 *   2. per session, per day      — stops one visitor grinding all afternoon
 *   3. global, per day           — bounds the whole channel no matter what
 *   4. monthly spend             — the operator's real budget
 *
 * The first three always apply, so there is a hard bound on cost even if the
 * operator never enters their provider's token rates. Ceiling 4 is exact when
 * they do: tokens come from DishNetAiBrain::getLastUsage(), which reports what
 * the provider actually billed rather than an estimate.
 *
 * Every rejection names a reason the endpoint can turn into an honest message.
 * A capped visitor is sent to WhatsApp — never left with a dead box.
 */
class WebChatGuard
{
    // Ceilings 1-3. Deliberately conservative: a real buyer asks a handful of
    // questions, and anything above these is either abuse or a stuck client.
    const IP_MAX          = 8;      // messages ...
    const IP_WINDOW       = 600;    // ... per 10 minutes from one IP
    const SESSION_MAX     = 30;     // messages per session per day
    const GLOBAL_MAX_DAY  = 600;    // messages per day across the whole channel

    const STORE_FILE      = 'web_chat_usage.json';
    const PRUNE_AFTER     = 172800; // keep two days; the daily counters need one
    const RETENTION_DAYS  = 90;     // leads and transcripts; override in settings

    /** @var mixed JsonStore|SqliteStore — duck-typed, same interface */
    private $store;
    private array $config;

    public function __construct($store, array $config)
    {
        $this->store  = $store;
        $this->config = $config;
    }

    private function cfgInt(string $key, int $default): int
    {
        $v = $this->config[$key] ?? '';
        return ($v === '' || !is_numeric($v)) ? $default : max(0, (int)$v);
    }

    private function cfgFloat(string $key, float $default): float
    {
        $v = $this->config[$key] ?? '';
        return ($v === '' || !is_numeric($v)) ? $default : max(0.0, (float)$v);
    }

    /**
     * May this request proceed?
     *
     * @return array{ok:bool,reason:string,message:string,retry_in:int}
     */
    public function check(string $ip, string $session): array
    {
        $now     = time();
        $entries = $this->load();

        $ipMax      = $this->cfgInt('web_chat_ip_max',      self::IP_MAX);
        $sessionMax = $this->cfgInt('web_chat_session_max',  self::SESSION_MAX);
        $globalMax  = $this->cfgInt('web_chat_daily_max',    self::GLOBAL_MAX_DAY);

        $ipHits = $sessionHits = $globalHits = 0;
        $oldestIpHit = $now;
        $today = gmdate('Y-m-d', $now);

        foreach ($entries as $e) {
            $ts = (int)($e['ts'] ?? 0);
            if ($ts <= 0) continue;
            if (($e['day'] ?? '') === $today) {
                $globalHits++;
                if (($e['session'] ?? '') === $session) $sessionHits++;
            }
            if (($e['ip'] ?? '') === $ip && $ts > $now - self::IP_WINDOW) {
                $ipHits++;
                if ($ts < $oldestIpHit) $oldestIpHit = $ts;
            }
        }

        if ($ipMax > 0 && $ipHits >= $ipMax) {
            $retry = max(1, (self::IP_WINDOW - ($now - $oldestIpHit)));
            return $this->deny('ip_rate',
                'You have sent a lot of messages in a short time. Give it a few minutes, '
                . 'or carry on with us on WhatsApp.', $retry);
        }
        if ($sessionMax > 0 && $sessionHits >= $sessionMax) {
            return $this->deny('session_cap',
                'We have reached the limit for this chat today. WhatsApp is the best place '
                . 'to keep going — a person can pick it up there too.');
        }
        if ($globalMax > 0 && $globalHits >= $globalMax) {
            return $this->deny('daily_cap',
                'Our chat assistant is busy right now. Message us on WhatsApp and we will '
                . 'answer you there.');
        }

        // Ceiling 4: money. Only enforceable once the operator tells us what
        // their provider charges — until then ceilings 1-3 are the bound, and
        // that is stated in the settings screen rather than left implied.
        $budget = $this->cfgFloat('web_chat_monthly_usd', 25.0);
        if ($budget > 0 && $this->ratesConfigured()) {
            $spent = $this->spentThisMonth($entries, $now);
            if ($spent >= $budget) {
                return $this->deny('budget',
                    'Our chat assistant is unavailable for the rest of the month. '
                    . 'Message us on WhatsApp and we will answer you there.');
            }
        }

        return ['ok' => true, 'reason' => '', 'message' => '', 'retry_in' => 0];
    }

    private function deny(string $reason, string $message, int $retryIn = 0): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $message, 'retry_in' => $retryIn];
    }

    /** Are both token rates set? Without them a USD ceiling is guesswork. */
    public function ratesConfigured(): bool
    {
        return $this->cfgFloat('web_chat_usd_per_1m_in', 0.0) > 0
            && $this->cfgFloat('web_chat_usd_per_1m_out', 0.0) > 0;
    }

    /** USD spent this calendar month, from tokens the provider actually billed. */
    public function spentThisMonth(?array $entries = null, ?int $now = null): float
    {
        $now     = $now ?? time();
        $entries = $entries ?? $this->load();
        $month   = gmdate('Y-m', $now);
        $in      = $this->cfgFloat('web_chat_usd_per_1m_in', 0.0);
        $out     = $this->cfgFloat('web_chat_usd_per_1m_out', 0.0);
        $total   = 0.0;
        foreach ($entries as $e) {
            if (substr((string)($e['day'] ?? ''), 0, 7) !== $month) continue;
            $total += ((int)($e['in'] ?? 0) / 1000000) * $in
                    + ((int)($e['out'] ?? 0) / 1000000) * $out;
        }
        return round($total, 4);
    }

    /**
     * Record one answered message. Called after the model replies, so a
     * failed call does not burn the visitor's allowance.
     */
    public function record(string $ip, string $session, array $usage = []): void
    {
        $now = time();
        $this->store->withLock(self::STORE_FILE, function (array $entries) use ($ip, $session, $usage, $now) {
            $entries[] = [
                'ts'      => $now,
                'day'     => gmdate('Y-m-d', $now),
                'ip'      => $ip,
                'session' => $session,
                'in'      => (int)($usage['input_tokens'] ?? 0),
                'out'     => (int)($usage['output_tokens'] ?? 0),
                'model'   => (string)($usage['model'] ?? ''),
            ];
            $cut = $now - self::PRUNE_AFTER;
            // Keep this month's rows regardless of age: the spend ceiling reads
            // them. Everything else is only needed for the rolling windows.
            $month = gmdate('Y-m', $now);
            return array_values(array_filter($entries, function ($e) use ($cut, $month) {
                return (int)($e['ts'] ?? 0) > $cut
                    || substr((string)($e['day'] ?? ''), 0, 7) === $month;
            }));
        });
    }

    /** Operator-facing counters for the settings screen and the preflight. */
    public function stats(): array
    {
        $now     = time();
        $entries = $this->load();
        $today   = gmdate('Y-m-d', $now);
        $month   = gmdate('Y-m', $now);
        $dayHits = $monthHits = $tokIn = $tokOut = 0;
        foreach ($entries as $e) {
            $day = (string)($e['day'] ?? '');
            if ($day === $today) $dayHits++;
            if (substr($day, 0, 7) === $month) {
                $monthHits++;
                $tokIn  += (int)($e['in'] ?? 0);
                $tokOut += (int)($e['out'] ?? 0);
            }
        }
        return [
            'today'            => $dayHits,
            'month'            => $monthHits,
            'tokens_in_month'  => $tokIn,
            'tokens_out_month' => $tokOut,
            'spent_month_usd'  => $this->ratesConfigured() ? $this->spentThisMonth($entries, $now) : null,
            'budget_usd'       => $this->cfgFloat('web_chat_monthly_usd', 25.0),
            'daily_max'        => $this->cfgInt('web_chat_daily_max', self::GLOBAL_MAX_DAY),
        ];
    }

    /**
     * Delete leads and transcripts older than the retention period.
     *
     * Personal data with no expiry is a policy failure, not a storage one:
     * a phone number left in a database for three years was collected for a
     * question answered in three minutes. The period is configurable because
     * the right number is a business decision, not ours.
     *
     * Returns [leadsDeleted, sessionsDeleted]. A session is kept in step with
     * its lead: deleting the contact detail but keeping the conversation that
     * names them would defeat the point.
     */
    public function prune(?int $days = null): array
    {
        $days = $days ?? $this->cfgInt('web_chat_retention_days', self::RETENTION_DAYS);
        if ($days <= 0) return [0, 0];          // 0 disables, deliberately
        $cutoff = time() - ($days * 86400);

        // Conversations live in the shared tables now, so the delete has to be
        // narrowed by hand. Three conditions, all of which a WhatsApp row fails:
        // channel = 'web', a phone that starts with "web:", and older than the
        // period. WhatsApp conversations are business records with a retention
        // question nobody has answered yet, and this must never be the thing
        // that answers it.
        $convGone = 0;
        try {
            $pdo = method_exists($this->store, 'getPdo') ? $this->store->getPdo() : null;
            if ($pdo) {
                $cut = gmdate('Y-m-d H:i:s', $cutoff);
                $sel = $pdo->prepare(
                    "SELECT id FROM wa_conversations
                      WHERE channel = 'web' AND phone LIKE 'web:%'
                        AND COALESCE(last_message_at, updated_at, created_at) < ?"
                );
                $sel->execute([$cut]);
                $ids = $sel->fetchAll(\PDO::FETCH_COLUMN);
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("DELETE FROM wa_messages WHERE conversation_id IN ({$in})")->execute($ids);
                    $pdo->prepare("DELETE FROM wa_conversations WHERE id IN ({$in})")->execute($ids);
                    $convGone = count($ids);
                }
            }
        } catch (\Throwable $e) { /* reported by the caller as zero */ }

        $gone = 0;
        foreach (['web_chat_leads.json' => 'created', 'web_chat_sessions.json' => 'updated'] as $file => $field) {
            $kept = [];
            $removed = 0;
            try {
                foreach ($this->store->load($file) as $row) {
                    $stamp = strtotime((string)($row[$field] ?? $row['updated'] ?? $row['created'] ?? ''));
                    // A row with no readable timestamp is kept: deleting data
                    // because we cannot date it is worse than keeping it.
                    if ($stamp !== false && $stamp < $cutoff) { $removed++; continue; }
                    $kept[] = $row;
                }
                if ($removed > 0) $this->store->save($file, $kept);
            } catch (\Throwable $e) {
                continue;
            }
            $gone += $removed;
            if ($file === 'web_chat_leads.json') $leads = $removed;
        }
        $leads = $leads ?? 0;
        // Transcripts counted from both places: the frozen blob table, which
        // still holds pre-migration chats, and the conversation tables where
        // everything since lives.
        return [$leads, ($gone - $leads) + $convGone];
    }

    /**
     * Erase one visitor: their contact details and the conversation with them.
     * Used by the admin screen so a deletion request needs no database access.
     */
    public function forget(string $session): array
    {
        $out = ['lead' => false, 'session' => false];

        // The conversation is the transcript now. Same three conditions as
        // prune(), so a malformed session can never reach a WhatsApp row.
        try {
            $pdo = method_exists($this->store, 'getPdo') ? $this->store->getPdo() : null;
            if ($pdo && preg_match('/^[a-f0-9]{6,64}$/', $session)) {
                $sel = $pdo->prepare(
                    "SELECT id FROM wa_conversations WHERE channel = 'web' AND phone = ?"
                );
                $sel->execute(['web:' . $session]);
                $ids = $sel->fetchAll(\PDO::FETCH_COLUMN);
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("DELETE FROM wa_messages WHERE conversation_id IN ({$in})")->execute($ids);
                    $pdo->prepare("DELETE FROM wa_conversations WHERE id IN ({$in})")->execute($ids);
                    $out['session'] = true;
                }
            }
        } catch (\Throwable $e) { /* report what did happen */ }
        foreach (['web_chat_leads.json' => 'lead', 'web_chat_sessions.json' => 'session'] as $file => $key) {
            try {
                $kept = [];
                foreach ($this->store->load($file) as $row) {
                    if ((string)($row['session'] ?? '') === $session) { $out[$key] = true; continue; }
                    $kept[] = $row;
                }
                if ($out[$key]) $this->store->save($file, $kept);
            } catch (\Throwable $e) { /* report what did happen */ }
        }
        return $out;
    }

    private function load(): array
    {
        try {
            return $this->store->load(self::STORE_FILE);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
