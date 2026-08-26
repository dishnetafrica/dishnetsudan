<?php
/**
 * A website chat has to be visible where an admin already looks.
 *
 * The whole point of moving website chats into the conversation tables is that
 * the Inbox shows them beside WhatsApp. These assertions are about the data the
 * Inbox reads -- the list, the counts, the transcript and who said each line --
 * because a screen that renders is not the same as a screen that shows the
 * right thing.
 */
$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

$root = dirname(__DIR__);
require_once $root . '/lib/ConversationService.php';

$dir = sys_get_temp_dir() . '/dishnet_inbox_' . getmypid();
@mkdir($dir, 0700, true);
$pdo = new PDO('sqlite:' . $dir . '/plugin.sqlite3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$svc = new ConversationService($dir, $pdo);

// Exactly what web_chat.php does for a visitor who has not left details.
$session = 'a3e58247bced331058ab';
$conv = $svc->ensureConversation('web:' . $session, 'web', 'Website visitor', 'web_chat');
$cid  = (int)$conv['id'];
$exchange = [
    ['in',  'customer',  'hi',                              null],
    ['out', 'assistant', 'Hello! How can I help?',          'DishNet AI'],
    ['in',  'customer',  'home',                            null],
    ['out', 'assistant', 'Great. How many people?',         'DishNet AI'],
    ['in',  'customer',  '5',                               null],
    ['out', 'assistant', 'For a 5-person household...',     'DishNet AI'],
];
foreach ($exchange as $m) {
    $svc->storeMessage($cid, ['direction' => $m[0], 'role' => $m[1], 'body' => $m[2],
                              'agent_name' => $m[3], 'metadata' => json_encode(['channel' => 'web'])]);
}
// And a WhatsApp conversation, so the filters have something to separate.
$wa = $svc->ensureConversation('+249900083481', 'sales', 'WA Customer', 'webhook');
$svc->storeMessage((int)$wa['id'], ['direction' => 'in', 'role' => 'customer', 'body' => 'wa message']);

echo "\nIt appears in the conversation list\n";
$all = $svc->listConversations([], 50, 0);
t('both channels are listed', count($all), 2);
$web = $svc->listConversations(['channel' => 'web'], 50, 0);
t('the Website filter returns only the web chat', count($web), 1);
t('and it is the right one', $web[0]['phone'], 'web:' . $session);
t('shown as a visitor, not a phone owner', $web[0]['display_name'], 'Website visitor');
$sales = $svc->listConversations(['channel' => 'sales'], 50, 0);
t('the WhatsApp filter does not include it', count($sales), 1);
t('and WhatsApp is not mislabelled', $sales[0]['channel'], 'sales');

echo "\nThe counts the tabs use\n";
$stats = $svc->getStats();
t('website unread is counted separately', $stats['unread_web'], 3);
t('and it is not double counted into support', $stats['unread_support'], 0);
t('the total includes both channels', $stats['total_unread'], 4);

echo "\nThe transcript reads like a conversation\n";
$msgs = $svc->getMessages($cid, 100, 0);
t('every message is there', count($msgs), 6);
t('in the order it happened', array_map(function ($m) { return $m['body']; }, $msgs),
  array_column($exchange, 2));

// What the Inbox turns into CUSTOMER / AI / HUMAN.
$labels = array_map(function ($m) {
    if (($m['direction'] ?? '') === 'in') return 'CUSTOMER';
    if (($m['role'] ?? '') === 'assistant') return 'AI';
    return 'HUMAN' . (!empty($m['agent_name']) ? ' — ' . $m['agent_name'] : '');
}, $msgs);
t('each line is attributable', $labels,
  ['CUSTOMER', 'AI', 'CUSTOMER', 'AI', 'CUSTOMER', 'AI']);
t('the AI names itself', $msgs[1]['agent_name'], 'DishNet AI');

echo "\nA human stepping in is distinguishable from the AI\n";
$svc->storeMessage($cid, ['direction' => 'out', 'role' => 'agent',
                          'body' => 'I can arrange installation.', 'agent_name' => 'Bhavin']);
$msgs = $svc->getMessages($cid, 100, 0);
$last = end($msgs);
t('it is not attributed to the AI', $last['role'] === 'assistant', false);
t('and it carries the person\'s name', $last['agent_name'], 'Bhavin');

echo "\nAsking for a human raises the same flag WhatsApp uses\n";
$pdo->prepare("UPDATE wa_conversations SET state = 'needs_human' WHERE id = ?")->execute([$cid]);
t('it shows in the Needs Reply count', $svc->getStats()['needs_human'], 1);
t('and the filter finds it',
  count($svc->listConversations(['state' => 'needs_human'], 50, 0)), 1);

echo "\nContact details, once given, show on the conversation\n";
$pdo->prepare('UPDATE wa_conversations SET display_name = ?, tags = ? WHERE id = ?')
    ->execute(['Amal', json_encode(['contact' => '+249912345678']), $cid]);
$row = $svc->getConversation($cid);
t('the name replaces "Website visitor"', $row['display_name'], 'Amal');
t('the number is on the record', json_decode($row['tags'], true)['contact'], '+249912345678');
t('but the session is still the key', $row['phone'], 'web:' . $session);

array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
