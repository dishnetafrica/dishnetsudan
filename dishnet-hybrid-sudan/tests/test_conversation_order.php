<?php
/**
 * Message order in a conversation.
 *
 * sent_at has one-second resolution and an AI reply almost always lands in the
 * same second as the message it answers. Ordering by sent_at alone leaves
 * SQLite free to break ties however it likes, and it returned whole transcripts
 * backwards. That is wrong in the Inbox and worse in AiReplyWorker, which feeds
 * this same function to the model as conversation history -- a reversed
 * transcript is a model reading the conversation from the end.
 */
$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

$root = dirname(__DIR__);
require_once $root . '/lib/ConversationService.php';

$dir = sys_get_temp_dir() . '/dishnet_convorder_' . getmypid();
@mkdir($dir, 0700, true);
$pdo = new PDO('sqlite:' . $dir . '/plugin.sqlite3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$svc = new ConversationService($dir, $pdo);

echo "\nA whole exchange written inside one second\n";
$conv = $svc->ensureConversation('+249900083481', 'sales', 'Test', 'test');
$cid  = (int)$conv['id'];
$flow = [
    ['in',  'customer',  'hi'],
    ['out', 'assistant', 'Hello! How can I help?'],
    ['in',  'customer',  'home'],
    ['out', 'assistant', 'Great. How many people?'],
    ['in',  'customer',  '5'],
    ['out', 'assistant', 'For a 5-person household...'],
];
foreach ($flow as $m) {
    $svc->storeMessage($cid, ['direction' => $m[0], 'role' => $m[1], 'body' => $m[2]]);
}
$got = array_map(function ($m) { return $m['body']; }, $svc->getMessages($cid, 100, 0));
t('the transcript reads oldest to newest', $got, array_column($flow, 2));
t('it is not reversed', $got[0], 'hi');
t('and the last line really is the last', end($got), 'For a 5-person household...');

echo "\nThe same holds for the window the model is given\n";
// AiReplyWorker takes the newest 20 and feeds them as history. The newest must
// be the end of the conversation, in order -- not the start.
for ($i = 0; $i < 30; $i++) {
    $svc->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => "extra {$i}"]);
}
$window = $svc->getMessages($cid, 20, 0);
$bodies = array_map(function ($m) { return $m['body']; }, $window);
t('the window is the requested size', count($bodies), 20);
t('it ends at the newest message', end($bodies), 'extra 29');
t('and runs forwards, not backwards', $bodies[0], 'extra 10');

echo "\nOrdering survives a conversation spanning several seconds\n";
$conv2 = $svc->ensureConversation('+249900000002', 'support', 'Test2', 'test');
$c2 = (int)$conv2['id'];
$pdo->prepare('INSERT INTO wa_messages (conversation_id, direction, role, body, sent_at) VALUES (?,?,?,?,?)')
    ->execute([$c2, 'in', 'customer', 'first', '2026-08-26 10:00:00']);
$pdo->prepare('INSERT INTO wa_messages (conversation_id, direction, role, body, sent_at) VALUES (?,?,?,?,?)')
    ->execute([$c2, 'out', 'assistant', 'second', '2026-08-26 10:00:05']);
$pdo->prepare('INSERT INTO wa_messages (conversation_id, direction, role, body, sent_at) VALUES (?,?,?,?,?)')
    ->execute([$c2, 'in', 'customer', 'third', '2026-08-26 10:01:00']);
t('real timestamps still order correctly',
  array_map(function ($m) { return $m['body']; }, $svc->getMessages($c2, 100, 0)),
  ['first', 'second', 'third']);

array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
