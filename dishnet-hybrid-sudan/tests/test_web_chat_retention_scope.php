<?php
/**
 * Retention must never reach a WhatsApp conversation.
 *
 * Website chats now share tables with WhatsApp, so the 90-day delete is
 * narrowed by hand rather than by the table it lives in. WhatsApp
 * conversations are business records whose retention nobody has decided, and
 * this job must never be the thing that decides it. Every assertion below is
 * about that boundary holding under conditions designed to break it.
 */
$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

$root = dirname(__DIR__);
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/WebChatGuard.php';

$dir = sys_get_temp_dir() . '/dishnet_retscope_' . getmypid();
@mkdir($dir, 0700, true);
$pdo = new PDO('sqlite:' . $dir . '/plugin.sqlite3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$svc = new ConversationService($dir, $pdo);

/** Store exposing getPdo, like SqliteStore. */
class RetStore {
    public array $files = [];
    private PDO $pdo;
    public function __construct(PDO $p) { $this->pdo = $p; }
    public function getPdo(): PDO { return $this->pdo; }
    public function load(string $f): array { return $this->files[$f] ?? []; }
    public function save(string $f, array $r): void { $this->files[$f] = array_values($r); }
    public function withLock(string $f, callable $fn) { $this->files[$f] = $fn($this->files[$f] ?? []); }
}
$store = new RetStore($pdo);

$ancient = gmdate('Y-m-d H:i:s', time() - 400 * 86400);
$recent  = gmdate('Y-m-d H:i:s', time() - 3 * 86400);

function age(PDO $pdo, int $id, string $when): void {
    $pdo->prepare('UPDATE wa_conversations SET last_message_at = ?, updated_at = ?, created_at = ? WHERE id = ?')
        ->execute([$when, $when, $when, $id]);
}

// Ancient WhatsApp conversations on every channel — none may be touched.
$whatsapp = [];
foreach (['sales', 'support', 'accounts', 'marketing'] as $ch) {
    $c = $svc->ensureConversation('+24990000' . strlen($ch) . '11', $ch, 'WA ' . $ch, 'webhook');
    $svc->storeMessage((int)$c['id'], ['direction' => 'in', 'role' => 'customer', 'body' => 'old wa message']);
    age($pdo, (int)$c['id'], $ancient);
    $whatsapp[] = (int)$c['id'];
}
// A WhatsApp row deliberately mislabelled to look webbish in one way only.
$trap = $svc->ensureConversation('+249911111111', 'sales', 'Trap', 'webhook');
$pdo->prepare("UPDATE wa_conversations SET phone = 'web:notreallyweb' WHERE id = ?")
    ->execute([(int)$trap['id']]);           // web-looking phone, but channel = sales
age($pdo, (int)$trap['id'], $ancient);

// Website chats: one ancient, one recent.
$oldWeb = $svc->ensureConversation('web:aaaa1111bbbb2222', 'web', 'Website visitor', 'web_chat');
$svc->storeMessage((int)$oldWeb['id'], ['direction' => 'in', 'role' => 'customer', 'body' => 'old web message']);
age($pdo, (int)$oldWeb['id'], $ancient);
$newWeb = $svc->ensureConversation('web:cccc3333dddd4444', 'web', 'Website visitor', 'web_chat');
$svc->storeMessage((int)$newWeb['id'], ['direction' => 'in', 'role' => 'customer', 'body' => 'recent web message']);
age($pdo, (int)$newWeb['id'], $recent);

echo "\nA 90-day prune with WhatsApp history far older than the cutoff\n";
$before = (int)$pdo->query('SELECT COUNT(*) FROM wa_conversations')->fetchColumn();
list($leads, $transcripts) = (new WebChatGuard($store, []))->prune();
t('it deleted exactly one conversation', $transcripts, 1);

$left = $pdo->query('SELECT id FROM wa_conversations')->fetchAll(PDO::FETCH_COLUMN);
$left = array_map('intval', $left);
foreach ($whatsapp as $id) {
    t("WhatsApp conversation {$id} survived", in_array($id, $left, true), true);
}
t('a sales conversation with a web-looking phone survived',
  in_array((int)$trap['id'], $left, true), true);
t('the ancient website chat is gone', in_array((int)$oldWeb['id'], $left, true), false);
t('the recent website chat survived', in_array((int)$newWeb['id'], $left, true), true);
t('nothing else vanished', count($left), $before - 1);

echo "\nIts messages went with it, and only its messages\n";
$bodies = $pdo->query('SELECT body FROM wa_messages')->fetchAll(PDO::FETCH_COLUMN);
t('the deleted chat left no messages behind', in_array('old web message', $bodies, true), false);
t('WhatsApp messages are all still there',
  count(array_filter($bodies, function ($b) { return $b === 'old wa message'; })), 4);
t('the recent website message is still there', in_array('recent web message', $bodies, true), true);

echo "\nErasing one visitor on request\n";
$g = new WebChatGuard($store, []);
$r = $g->forget('cccc3333dddd4444');
t('it reports the transcript removed', $r['session'], true);
$left2 = array_map('intval', $pdo->query('SELECT id FROM wa_conversations')->fetchAll(PDO::FETCH_COLUMN));
t('that visitor is gone', in_array((int)$newWeb['id'], $left2, true), false);
foreach ($whatsapp as $id) {
    t("WhatsApp conversation {$id} still untouched", in_array($id, $left2, true), true);
}

echo "\nA malformed or hostile session id cannot reach anything\n";
foreach (["' OR 1=1 --", '%', '', 'notreallyweb', '../../etc'] as $bad) {
    (new WebChatGuard($store, []))->forget($bad);
}
$left3 = array_map('intval', $pdo->query('SELECT id FROM wa_conversations')->fetchAll(PDO::FETCH_COLUMN));
t('every remaining conversation is intact', count($left3), count($left2));
t('including the web-looking sales trap', in_array((int)$trap['id'], $left3, true), true);

array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
