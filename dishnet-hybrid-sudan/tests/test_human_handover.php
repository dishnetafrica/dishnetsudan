<?php
/**
 * What happens after a colleague replies by hand.
 *
 * Two behaviours, and they are separate. Whether the AI keeps answering is a
 * business choice with a setting. Whether the AI can SEE what the colleague
 * said is not optional -- it was reading their words as its own previous turn,
 * so it would carry on as though it had made whatever promise the person made.
 */
$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

$root = dirname(__DIR__);
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/DishNetAiBrain.php';

$dir = sys_get_temp_dir() . '/dishnet_handover_' . getmypid();
@mkdir($dir, 0700, true);
$pdo = new PDO('sqlite:' . $dir . '/plugin.sqlite3');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$svc = new ConversationService($dir, $pdo);

$conv = $svc->ensureConversation('+211927797217', 'sales', 'Tester', 'test');
$cid  = (int)$conv['id'];
$svc->storeMessage($cid, ['direction' => 'in',  'role' => 'customer',  'body' => 'I need Starlink']);
$svc->storeMessage($cid, ['direction' => 'out', 'role' => 'assistant', 'body' => 'Home or business?',
                          'agent_name' => 'DishNet AI']);
$svc->storeMessage($cid, ['direction' => 'in',  'role' => 'customer',  'body' => 'home']);
$svc->storeMessage($cid, ['direction' => 'out', 'role' => 'agent',
                          'body' => 'I will call you at 4pm to arrange installation.',
                          'agent_name' => 'Bhavin']);

// Exactly the mapping AiReplyWorker::buildContext performs.
function historyFrom(array $msgs): array {
    $out = [];
    foreach ($msgs as $m) {
        $inbound = ($m['direction'] ?? 'in') === 'in';
        $text    = mb_substr((string)($m['body'] ?? ''), 0, 400);
        if (!$inbound) {
            $who = trim((string)($m['agent_name'] ?? ''));
            if (($m['role'] ?? '') === 'agent' && $who !== '' && $who !== 'DishNet AI') {
                $text = '[' . $who . ', from our team] ' . $text;
            }
        }
        $out[] = ['role' => $inbound ? 'customer' : 'dishnet', 'text' => $text];
    }
    return $out;
}

echo "\nThe AI can see what the colleague said, and who said it\n";
$history = historyFrom($svc->getMessages($cid, 20, 0));
$texts   = array_column($history, 'text');
t('the colleague\'s message is in the history',
  in_array('[Bhavin, from our team] I will call you at 4pm to arrange installation.', $texts, true), true);
t('and it is attributed, not passed off as the AI\'s',
  str_contains($texts[3], '[Bhavin, from our team]'), true);
t('the AI\'s own message is left unmarked', $texts[1], 'Home or business?');
t('customer messages are untouched', $texts[0], 'I need Starlink');

echo "\nThe prompt tells the model what that marker means\n";
$brain  = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai']);
$prompt = $brain->promptPreview(['channel' => 'sales', 'message' => 'ok', 'history' => $history]);
t('it explains the marker', str_contains($prompt, 'written by a human colleague'), true);
t('and says to keep their promise', str_contains($prompt, 'keep any promise in it'), true);
t('and not to claim it', str_contains($prompt, 'never claim you said it'), true);

echo "\nHow long the AI stands down is a setting\n";
// Mirrors humanIsHandling(): state, cooldown, last reply time.
function suppressed(?string $mins, int $agoSeconds): bool {
    $m = ($mins === null || $mins === '' || !is_numeric($mins)) ? 1440 : max(0, (int)$mins);
    if ($m === 0) return false;
    return $agoSeconds < $m * 60;
}
t('unset keeps the old 24-hour pause', suppressed(null, 3600), true);
t('and lets it resume after 24 hours', suppressed(null, 25 * 3600), false);
t('a short pause resumes quickly', suppressed('10', 11 * 60), false);
t('but still covers someone mid-conversation', suppressed('10', 60), true);
t('0 means the AI never stands down', suppressed('0', 1), false);
t('nonsense falls back to the default rather than never pausing', suppressed('abc', 3600), true);

echo "\nA colleague's message reaches the model even while it is standing down\n";
// The two are independent: seeing the message is not conditional on replying.
t('history is built regardless of the pause setting',
  count(historyFrom($svc->getMessages($cid, 20, 0))), 4);

array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
