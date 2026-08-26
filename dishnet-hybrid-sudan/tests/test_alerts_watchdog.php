<?php
/**
 * Alerts: the thing that notices every other failure.
 *
 * The install's worst incident was silent -- eleven customer messages queued
 * for hours, discovered by running SQL. Watchdog and escalation alerts exist
 * so the next silent failure is loud. These tests pin the three ways alerting
 * itself goes wrong: not firing, firing forever, and firing so often it gets
 * muted.
 */
$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

require_once dirname(__DIR__) . '/lib/AlertService.php';

class AlertFakeStore {
    public array $files = [];
    public function load(string $f): array { return $this->files[$f] ?? []; }
    public function withLock(string $f, callable $fn) { $this->files[$f] = $fn($this->files[$f] ?? []); }
}
class FakeEvo {
    public array $sent = [];
    public $result = ['ok' => true];
    public function sendText(string $ch, string $to, string $text): array {
        $this->sent[] = ['channel' => $ch, 'to' => $to, 'text' => $text];
        return $this->result;
    }
}

echo "\nWho counts as waiting\n";
$now = time();
$ts  = function (int $minAgo) use ($now) { return gmdate('Y-m-d H:i:s', $now - $minAgo * 60); };
$rows = [
    ['id' => 1, 'last_customer_at' => $ts(30),   'last_agent_at' => $ts(25)],  // answered
    ['id' => 2, 'last_customer_at' => $ts(30),   'last_agent_at' => $ts(40)],  // waiting 30 min
    ['id' => 3, 'last_customer_at' => $ts(5),    'last_agent_at' => $ts(40)],  // inside patience
    ['id' => 4, 'last_customer_at' => $ts(2000), 'last_agent_at' => ''],       // stale, >24h
    ['id' => 5, 'last_customer_at' => '',        'last_agent_at' => ''],       // never spoke
    ['id' => 6, 'last_customer_at' => $ts(60),   'last_agent_at' => ''],       // never answered at all
];
$got = array_column(AlertService::findUnanswered($rows, [], $now, 10), 'id');
t('the answered customer does not alert', in_array(1, $got, true), false);
t('the 30-minute wait alerts', in_array(2, $got, true), true);
t('a customer inside the patience window does not', in_array(3, $got, true), false);
t('history older than a day is not a live wait', in_array(4, $got, true), false);
t('a conversation with no customer message never alerts', in_array(5, $got, true), false);
t('a conversation the bot never answered alerts', in_array(6, $got, true), true);

echo "\nOnce per waiting message, fresh again when they write again\n";
$alerted = [2 => $rows[1]['last_customer_at']];
$got2 = array_column(AlertService::findUnanswered($rows, $alerted, $now, 10), 'id');
t('an already-alerted wait stays quiet', in_array(2, $got2, true), false);
$rows[1]['last_customer_at'] = $ts(15);          // the customer wrote again
$got3 = array_column(AlertService::findUnanswered($rows, $alerted, $now, 10), 'id');
t('a new message from the same customer re-alerts', in_array(2, $got3, true), true);

echo "\nCooldowns: one buzz, not three\n";
$s = new AlertFakeStore(); $evo = new FakeEvo();
$a = new AlertService($s, ['alert_whatsapp' => '+249111222333'], $evo);
t('first alert sends', $a->notify('escalate:conv:7', 'needs a human', 30)['sent'], true);
t('and reaches the configured number', $evo->sent[0]['to'], '+249111222333');
t('a repeat inside the cooldown is swallowed',
  $a->notify('escalate:conv:7', 'needs a human again', 30)['reason'], 'cooldown');
t('a different conversation is not blocked',
  $a->notify('escalate:conv:8', 'other customer', 30)['sent'], true);
t('exactly two messages went out', count($evo->sent), 2);

echo "\nFailure releases the lock instead of silencing the window\n";
$s2 = new AlertFakeStore(); $evo2 = new FakeEvo();
$evo2->result = ['ok' => false, 'error' => 'evolution down'];
$a2 = new AlertService($s2, ['alert_whatsapp' => '+249111222333'], $evo2);
$r = $a2->notify('watchdog:1:x', 'waiting', 30);
t('the failure is reported', str_starts_with($r['reason'], 'send_failed'), true);
$evo2->result = ['ok' => true];
t('the next attempt goes through rather than hitting a cooldown',
  $a2->notify('watchdog:1:x', 'waiting', 30)['sent'], true);

echo "\nNo number means off, and says so\n";
$a3 = new AlertService(new AlertFakeStore(), [], new FakeEvo());
t('unset target refuses with the reason named',
  $a3->notify('any', 'text'), ['sent' => false, 'reason' => 'no_alert_number']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
