<?php
/**
 * One conversation, one clock.
 *
 * sent_at was written with date(), so it carried whichever timezone the entry
 * point ran under -- the webhook +2, the CLI worker UTC. Transcripts rendered
 * every AI reply two hours before the question it answered, and the model's
 * history read the same scramble. The whole test file runs under a +2 default
 * timezone, because that is the condition under which the old code broke.
 */
date_default_timezone_set('Africa/Khartoum');   // UTC+2 — the trap, on purpose

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

$root = dirname(__DIR__);
require_once $root . '/lib/ConversationService.php';

$dir = sys_get_temp_dir() . '/dishnet_ts_' . getmypid();
@mkdir($dir, 0700, true);
$pdo = new PDO('sqlite:' . $dir . '/plugin.sqlite3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$svc = new ConversationService($dir, $pdo);

echo "\nStorage is UTC even when PHP's clock is +2\n";
$conv = $svc->ensureConversation('+249900083481', 'sales', 'TZ Test', 'test');
$cid  = (int)$conv['id'];
$svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => 'hi']);
$row  = $pdo->query("SELECT sent_at, created_at FROM wa_messages WHERE conversation_id = {$cid}")
            ->fetch(PDO::FETCH_ASSOC);
$driftUtc   = abs(strtotime($row['sent_at'] . ' UTC') - time());
$driftLocal = abs(strtotime($row['sent_at'] . ' UTC') - (time() + 2 * 3600));
t('sent_at is within seconds of UTC now', $driftUtc < 5, true);
t('and is NOT the +2 local clock', $driftLocal < 5, false);
t('sent_at agrees with SQLite\'s own created_at',
  abs(strtotime($row['sent_at']) - strtotime($row['created_at'])) < 5, true);

echo "\nEvolution's unix timestamp converts to UTC too\n";
$unix = 1750000000;                              // a fixed moment
$evo  = ['key' => ['remoteJid' => '211927797217@s.whatsapp.net', 'fromMe' => false,
                   'id' => 'WAMID-TZ-1'],
         'message' => ['conversation' => 'test message'],
         'messageTimestamp' => $unix, 'pushName' => 'TZ'];
$svc->importEvoMessage($evo, 'sales');
$imp = $pdo->query("SELECT sent_at FROM wa_messages WHERE wa_message_id = 'WAMID-TZ-1'")
           ->fetchColumn();
t('imported sent_at is gmdate of the unix time', $imp, gmdate('Y-m-d H:i:s', $unix));
t('not the +2 rendering', $imp === date('Y-m-d H:i:s', $unix), false);

echo "\nAnd the transcript therefore interleaves correctly\n";
$conv2 = $svc->ensureConversation('+249900000009', 'sales', 'Order Test', 'test');
$c2 = (int)$conv2['id'];
foreach ([['in','q1'], ['out','a1'], ['in','q2'], ['out','a2']] as $m) {
    $svc->storeMessage($c2, ['direction' => $m[0], 'role' => $m[0]==='in'?'customer':'assistant',
                             'body' => $m[1]]);
}
t('question, answer, question, answer',
  array_map(function ($x) { return $x['body']; }, $svc->getMessages($c2, 10, 0)),
  ['q1', 'a1', 'q2', 'a2']);

echo "\nThe repair recognises exactly the +2 rows\n";
// Mirrors the tool's condition: sent_at 100-140 min AHEAD of created_at.
function needsRepair(string $sentAt, string $createdAt): bool {
    $d = (strtotime($sentAt) - strtotime($createdAt)) / 60;
    return $d >= 100 && $d <= 140;
}
$now = gmdate('Y-m-d H:i:s');
t('a +2h stamp is repaired', needsRepair(gmdate('Y-m-d H:i:s', time() + 7200), $now), true);
t('a live UTC row is untouched', needsRepair($now, $now), false);
t('backfilled history (sent long before stored) is untouched',
  needsRepair(gmdate('Y-m-d H:i:s', time() - 86400 * 3), $now), false);
t('a -2h stamp would NOT match — only the known skew is corrected',
  needsRepair(gmdate('Y-m-d H:i:s', time() - 7200), $now), false);

array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
