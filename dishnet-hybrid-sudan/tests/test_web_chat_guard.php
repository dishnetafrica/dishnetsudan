<?php
require_once dirname(__DIR__) . '/lib/WebChatGuard.php';

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

/** Minimal store with the two methods the guard uses. */
class FakeStore {
    public array $rows = [];
    public function load(string $f): array { return $this->rows; }
    public function withLock(string $f, callable $fn) { $this->rows = $fn($this->rows); }
}

// ── Ceiling 1: one IP bursting ────────────────────────────────────────────
$s = new FakeStore();
$g = new WebChatGuard($s, []);
for ($i = 0; $i < WebChatGuard::IP_MAX; $i++) {
    t("burst $i allowed", $g->check('1.2.3.4', "sess-$i")['ok'], true);
    $g->record('1.2.3.4', "sess-$i");
}
$r = $g->check('1.2.3.4', 'sess-new');
t('IP capped after the limit', $r['ok'], false);
t('IP denial names the reason', $r['reason'], 'ip_rate');
t('IP denial says when to retry', $r['retry_in'] > 0, true);
t('a different IP is unaffected', $g->check('9.9.9.9', 'sess-x')['ok'], true);

// ── Ceiling 2: one session grinding all day ───────────────────────────────
// Spread across IPs so only the session cap can be what stops it.
$s = new FakeStore();
$g = new WebChatGuard($s, ['web_chat_session_max' => 5]);
for ($i = 0; $i < 5; $i++) { $g->record("10.0.0.$i", 'grinder'); }
$r = $g->check('10.0.0.99', 'grinder');
t('session capped', [$r['ok'], $r['reason']], [false, 'session_cap']);
t('another session still allowed', $g->check('10.0.0.99', 'other')['ok'], true);

// ── Ceiling 3: the whole channel for the day ──────────────────────────────
$s = new FakeStore();
$g = new WebChatGuard($s, ['web_chat_daily_max' => 4, 'web_chat_session_max' => 100]);
for ($i = 0; $i < 4; $i++) { $g->record("10.1.0.$i", "s$i"); }
$r = $g->check('10.1.0.200', 'fresh-session');
t('global daily cap stops even a brand new visitor', [$r['ok'], $r['reason']], [false, 'daily_cap']);

// ── Ceiling 4: money ──────────────────────────────────────────────────────
$s = new FakeStore();
$rates = ['web_chat_usd_per_1m_in' => 3.0, 'web_chat_usd_per_1m_out' => 15.0,
          'web_chat_monthly_usd' => 1.0, 'web_chat_daily_max' => 0,
          'web_chat_session_max' => 0, 'web_chat_ip_max' => 0];
$g = new WebChatGuard($s, $rates);
t('rates recognised as configured', $g->ratesConfigured(), true);
// 100k in + 50k out = 0.30 + 0.75 = $1.05, just over a $1 budget.
$g->record('2.2.2.2', 'buyer', ['input_tokens' => 100000, 'output_tokens' => 50000]);
t('spend computed from billed tokens', $g->spentThisMonth(), 1.05);
$r = $g->check('2.2.2.2', 'buyer');
t('budget ceiling stops further calls', [$r['ok'], $r['reason']], [false, 'budget']);

// Without rates the USD ceiling cannot be enforced, and must not pretend to be.
$g2 = new WebChatGuard($s, ['web_chat_monthly_usd' => 1.0, 'web_chat_daily_max' => 0,
                            'web_chat_session_max' => 0, 'web_chat_ip_max' => 0]);
t('no rates means no USD enforcement', $g2->ratesConfigured(), false);
t('and the request is allowed through', $g2->check('2.2.2.2', 'buyer')['ok'], true);

// ── Every denial must give the visitor somewhere to go ────────────────────
$s = new FakeStore();
$g = new WebChatGuard($s, ['web_chat_ip_max' => 1]);
$g->record('3.3.3.3', 'z');
$r = $g->check('3.3.3.3', 'z');
t('denial carries a message for the visitor', $r['message'] !== '', true);
t('and points at WhatsApp', str_contains(strtolower($r['message']), 'whatsapp'), true);

// ── A failed model call must not cost the visitor their allowance ─────────
// record() is only ever called after a reply, so a check with no record
// leaves the count untouched.
$s = new FakeStore();
$g = new WebChatGuard($s, []);
$g->check('4.4.4.4', 'q'); $g->check('4.4.4.4', 'q');
t('checking alone consumes nothing', count($s->rows), 0);

// ── Pruning keeps the month, drops the ancient ────────────────────────────
$s = new FakeStore();
$s->rows = [
    ['ts' => time() - 90 * 86400, 'day' => gmdate('Y-m-d', time() - 90 * 86400),
     'ip' => 'old', 'session' => 'old', 'in' => 1, 'out' => 1],
];
$g = new WebChatGuard($s, []);
$g->record('5.5.5.5', 'new');
t('ancient row pruned', count($s->rows), 1);
t('the surviving row is the new one', $s->rows[0]['ip'], '5.5.5.5');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
