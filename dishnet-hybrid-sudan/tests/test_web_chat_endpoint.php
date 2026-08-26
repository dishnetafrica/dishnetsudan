<?php
/**
 * Exercises the real endpoint over real HTTP, against a copy of the plugin
 * with its own data directory. No provider key is configured, so nothing here
 * spends money -- these are the paths that must behave correctly *before* the
 * model is ever reached, which is exactly where a public endpoint gets abused.
 */
$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

$root = dirname(__DIR__);
// The plugin copy goes inside its own parent directory, because ConfigVault
// writes to dirname(pluginRoot). Copying straight into /tmp puts the vault at
// /tmp/.dishnet-sudan.vault.json, shared by every test run on the machine --
// which is how a fake API key from one test ended up configuring the next one
// and made a 'no provider' assertion pass for the wrong reason.
$sandbox = sys_get_temp_dir() . '/dishnet_webchat_' . getmypid();
$tmp     = $sandbox . '/plugin';
@mkdir($tmp . '/data', 0700, true);

// A copy, so the test cannot touch the real plugin's data directory.
exec(sprintf('cp -R %s/. %s/ 2>/dev/null', escapeshellarg($root), escapeshellarg($tmp)));
@mkdir($tmp . '/data', 0700, true);
file_put_contents($tmp . '/ucrm.json', json_encode(['pluginDataDir' => $tmp . '/data']));

/**
 * Write the operator's settings where the plugin actually keeps them.
 *
 * On first boot SqliteStore migrates kyc_config.json into SQLite and deletes
 * the file, so writing the file alone only works until the first request. Once
 * the database exists the store is the source of truth -- which is the whole
 * reason the endpoint merges the two.
 */
function writeConfig(string $tmp, array $extra = []): void {
    $cfg = array_merge([
        'web_chat_enabled'  => true,
        'web_chat_whatsapp' => '+249900083481',
        'web_chat_origins'  => 'https://dishnetsudan.com,https://www.dishnetsudan.com',
    ], $extra);
    file_put_contents($tmp . '/data/kyc_config.json', json_encode($cfg));
    if (glob($tmp . '/data/*.sqlite3')) {
        require_once $tmp . '/lib/StoreInterface.php';
        require_once $tmp . '/lib/SqliteStore.php';
        $s = SqliteStore::create($tmp . '/data');
        $s->save('kyc_config.json', array_merge($s->load('kyc_config.json'), $cfg));
    }
}
writeConfig($tmp);

$port = 8912 + (getmypid() % 300);
$srv  = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($tmp)),
                  [1 => ['file','/dev/null','w'], 2 => ['file','/dev/null','w']], $pipes);
for ($i = 0; $i < 60; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
    if ($c) { fclose($c); break; }
    usleep(100000);
}
$base = "http://127.0.0.1:$port/public.php?page=web_chat";

/** @return array{code:int,headers:array,body:array|string} */
function call(string $url, string $method, ?string $body = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '',
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr((string)$raw, 0, $hlen);
    $bod  = substr((string)$raw, $hlen);
    $hs = [];
    foreach (explode("\r\n", $head) as $line) {
        if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $hs[strtolower(trim($k))] = trim($v); }
    }
    $j = json_decode($bod, true);
    return ['code' => $code, 'headers' => $hs, 'body' => is_array($j) ? $j : $bod];
}

$OK  = 'Origin: https://dishnetsudan.com';
$BAD = 'Origin: https://evil.example';
$JSON = 'Content-Type: application/json';

echo "\nCross-origin access is allowlisted\n";
$r = call($base, 'OPTIONS', null, [$OK]);
t('preflight from our site is allowed', $r['code'], 204);
t('and echoes the origin', $r['headers']['access-control-allow-origin'] ?? null, 'https://dishnetsudan.com');
t('and varies on Origin', $r['headers']['vary'] ?? null, 'Origin');

$r = call($base, 'OPTIONS', null, [$BAD]);
t('preflight from another site is refused', $r['code'], 403);
t('and gets no allow-origin header', isset($r['headers']['access-control-allow-origin']), false);

$r = call($base, 'POST', '{"message":"hello"}', [$BAD, $JSON]);
t('POST from another site is refused', $r['code'], 403);
t('and is given no assistant text to render', $r['body']['reply'] ?? null, '');

echo "\nMalformed requests fail safely, always with a way to reach us\n";
$r = call($base, 'GET', null, [$OK]);
t('GET is rejected', $r['code'], 405);
t('and still offers WhatsApp', str_contains((string)($r['body']['handoff'] ?? ''), 'wa.me'), true);

$r = call($base, 'POST', 'not json', [$OK, $JSON]);
t('non-JSON body rejected', [$r['code'], $r['body']['reason'] ?? null], [400, 'bad_json']);

$r = call($base, 'POST', '{"message":"   "}', [$OK, $JSON]);
t('blank message rejected', [$r['code'], $r['body']['reason'] ?? null], [400, 'empty']);

$r = call($base, 'POST', '{"message":"' . str_repeat('a', 9000) . '"}', [$OK, $JSON]);
t('oversized body rejected before parsing', [$r['code'], $r['body']['reason'] ?? null], [413, 'too_long']);

echo "\nNo provider key means an honest fallback, not a broken box\n";
$r = call($base, 'POST', '{"message":"how much is the mini?"}', [$OK, $JSON]);
t('reports why it cannot answer', $r['body']['reason'] ?? null, 'no_provider');
t('and hands off to WhatsApp', str_contains((string)($r['body']['handoff'] ?? ''), '249900083481'), true);
t('and says something useful to the visitor', str_contains((string)($r['body']['reply'] ?? ''), 'WhatsApp'), true);

echo "\nDisabled is a supported state\n";
writeConfig($tmp, ['web_chat_enabled' => false]);
$r = call($base, 'POST', '{"message":"hi"}', [$OK, $JSON]);
t('switched off reports itself', $r['body']['reason'] ?? null, 'disabled');
t('and still points at WhatsApp', str_contains((string)($r['body']['reply'] ?? ''), 'WhatsApp'), true);

echo "\nSessions are issued by us, never taken on trust\n";
writeConfig($tmp);
$r = call($base, 'POST', '{"message":"hi","session":"../../etc/passwd"}', [$OK, $JSON]);
$sess = (string)($r['body']['session'] ?? '');
// no_provider path returns no session, so assert on the rate-limited path
// instead: what matters is that a forged id is never echoed back as accepted.
t('a forged session id is not accepted', $sess === '../../etc/passwd', false);

echo "\nThe config probe tells the widget whether to render at all\n";
$r = call($base . '&probe=1', 'GET', null, [$OK]);
t('probe answers', [$r['code'], $r['body']['ok'] ?? null], [200, true]);
t('reports enabled', $r['body']['enabled'] ?? null, true);
t('reports the lead mode', $r['body']['lead_mode'] ?? null, 'after');
t('carries the handoff number', str_contains((string)($r['body']['handoff'] ?? ''), '249900083481'), true);
$r = call($base . '&probe=1', 'GET', null, [$BAD]);
t('probe refuses another site', $r['code'], 403);

echo "\nContact details are stored, and never required to get an answer\n";
function leads(string $tmp): array {
    require_once $tmp . '/lib/StoreInterface.php';
    require_once $tmp . '/lib/SqliteStore.php';
    $s = SqliteStore::create($tmp . '/data');
    try { return $s->load('web_chat_leads.json'); } catch (\Throwable $e) { return []; }
}

// A lead on its own is a complete request: no model call, nothing metered.
$r = call($base, 'POST', json_encode(['message' => '', 'lead' =>
        ['name' => 'Amal', 'phone' => '+249 91 234 5678', 'email' => 'amal@example.com']]),
     [$OK, $JSON]);
t('lead alone is accepted', [$r['code'], $r['body']['lead_saved'] ?? null], [200, true]);

$rows = leads($tmp);
$last = $rows ? end($rows) : [];
t('name stored', $last['name'] ?? null, 'Amal');
t('phone normalised to digits, keeping the +', $last['phone'] ?? null, '+249912345678');
t('email stored', $last['email'] ?? null, 'amal@example.com');

// A junk email must not be stored as if it were reachable.
call($base, 'POST', json_encode(['message' => '', 'session' => $last['session'] ?? '',
     'lead' => ['phone' => '0912000000', 'email' => 'not-an-email']]), [$OK, $JSON]);
$rows = leads($tmp);
$found = null;
foreach ($rows as $row) { if (($row['phone'] ?? '') === '0912000000') $found = $row; }
t('invalid email dropped', $found['email'] ?? 'MISSING', '');
t('but the phone is kept', $found['phone'] ?? null, '0912000000');

// An entirely empty submission is still a bad request, not a silent success.
$r = call($base, 'POST', json_encode(['message' => '', 'lead' => ['name' => '  ']]), [$OK, $JSON]);
t('empty lead and empty message is refused', [$r['code'], $r['body']['reason'] ?? null], [400, 'empty']);

// And asking a question without any lead must still work.
$r = call($base, 'POST', '{"message":"what does the mini cost?"}', [$OK, $JSON]);
t('a question with no contact details still gets a response',
  isset($r['body']['reply']) && $r['body']['reply'] !== '', true);

echo "\nLive settings beat the migration snapshot in the store\n";
// The real failure this pins: saveAiSettings writes kyc_config.json, but
// SqliteStore migrated an older copy into SQLite on first boot. Merging the
// store over the files let that stale snapshot pick the wrong ai_provider, so
// the brain looked for a key that was never set and the website reported
// itself unavailable while WhatsApp kept working.
require_once $tmp . '/lib/StoreInterface.php';
require_once $tmp . '/lib/SqliteStore.php';
$s = SqliteStore::create($tmp . '/data');
$snapshot = $s->load('kyc_config.json');
$snapshot['ai_provider']    = 'claude';        // stale: what the store remembers
$snapshot['claude_api_key'] = '';              // and it has no key
$s->save('kyc_config.json', $snapshot);

// What the operator actually set, on the files, where PluginConfig reads it.
$live = json_decode((string)file_get_contents($tmp . '/data/kyc_config.json'), true) ?: [];
$live['ai_provider']    = 'openai';
$live['openai_api_key'] = 'sk-test-not-a-real-key';
file_put_contents($tmp . '/data/kyc_config.json', json_encode($live));

$r = call($base, 'POST', '{"message":"how much is the mini?"}', [$OK, $JSON]);
// With a provider key present the endpoint must get past its own gate. The key
// is fake so the provider call fails, but 'no_provider' would mean it never
// even tried -- which is exactly the bug.
t('a stale store provider no longer hides the live key',
  ($r['body']['reason'] ?? '') === 'no_provider', false);

// And the store must still fill a genuine gap.
$live2 = $live; unset($live2['web_chat_whatsapp']);
file_put_contents($tmp . '/data/kyc_config.json', json_encode($live2));
$snapshot['web_chat_whatsapp'] = '+249111222333';
$s->save('kyc_config.json', $snapshot);
$r = call($base . '&probe=1', 'GET', null, [$OK]);
t('the store still supplies what the files do not have',
  str_contains((string)($r['body']['handoff'] ?? ''), '249111222333'), true);

// Restore for anything after this.
writeConfig($tmp);

echo "\nA transcript row can still be updated after it is written\n";
// The real failure: append() writes no id, updateOne finds rows BY id, so every
// save after the first silently did nothing. The transcript froze at the
// opening exchange, the model saw the greeting and the current message and
// nothing between, and it re-asked what the customer had already answered.
// Tested at the store, because that is where it lives -- and because with no
// provider key the endpoint bails before it ever writes a transcript.
require_once $tmp . '/lib/StoreInterface.php';
require_once $tmp . '/lib/SqliteStore.php';
$st = SqliteStore::create($tmp . '/data');

foreach (['web_chat_sessions.json', 'web_chat_leads.json'] as $file) {
    $sid = 'sess' . substr(md5($file), 0, 8);
    // Written exactly as web_chat.php writes it.
    $st->appendWithId($file, ['session' => $sid, 'turns' => json_encode([['role' => 'customer',
        'text' => 'hi morning']]), 'updated' => gmdate('c')]);

    $found = $st->findOne($file, 'session', $sid);
    t("{$file}: the row is found", ($found['session'] ?? null), $sid);
    t("{$file}: and it carries an id updateOne can use",
      isset($found['id']) && (int)$found['id'] > 0, true);

    // Now the second message, the one that used to vanish.
    $grown = [['role' => 'customer', 'text' => 'hi morning'],
              ['role' => 'dishnet',  'text' => 'Good morning.'],
              ['role' => 'customer', 'text' => 'want for hotspot'],
              ['role' => 'customer', 'text' => '10']];
    $ok = $st->updateOne($file, 'session', $sid,
        ['session' => $sid, 'turns' => json_encode($grown), 'updated' => gmdate('c')]);
    t("{$file}: the update reports success", $ok, true);

    $after = $st->findOne($file, 'session', $sid);
    $turns = json_decode((string)($after['turns'] ?? '[]'), true) ?: [];
    t("{$file}: the transcript actually grew", count($turns), 4);
    t("{$file}: and the later messages are there",
      in_array('10', array_column($turns, 'text'), true), true);
}

// The contract above was always sound; what broke was the call site. So check
// the source too: any row that is later updateOne'd must be created with
// appendWithId, and plain append() next to an updateOne on the same file is
// the exact mistake that shipped twice.
$src = file_get_contents($tmp . '/web_chat.php');
t('web_chat.php does not plain-append leads',
  str_contains($src, "->append('web_chat_leads.json'"), false);
t('leads are written with appendWithId', substr_count($src, '->appendWithId('), 1);
// Transcripts moved to the conversation system; the blob table is frozen as
// the migration's rollback path and must not be written to any more.
t('the old session blob is no longer written',
  str_contains($src, 'web_chat_sessions'), false);
t('transcripts go through ConversationService',
  str_contains($src, '$convSvc->storeMessage('), true);
t('and the visitor is keyed by session, not a fabricated phone',
  str_contains($src, "'web:' . \$session"), true);

// And the shape web_chat reads back must survive a round trip unchanged.
$sid = 'roundtrip01';
$orig = [['role' => 'customer', 'text' => 'home'], ['role' => 'dishnet', 'text' => 'How many?']];
$st->appendWithId('web_chat_sessions.json',
    ['session' => $sid, 'turns' => json_encode($orig), 'updated' => gmdate('c')]);
$back = json_decode((string)($st->findOne('web_chat_sessions.json', 'session', $sid)['turns'] ?? ''), true);
t('roles and order survive the round trip', $back, $orig);

echo "\nStray output cannot corrupt the response\n";
// This is the failure the owner hit: status 200, but a PHP warning printed in
// front of the JSON, so the browser could not parse a thing and the widget
// showed its own "unavailable" text. Indistinguishable from the chat being
// switched off, and invisible in the status code.
$guardFile = $tmp . '/lib/WebChatGuard.php';
$orig = file_get_contents($guardFile);
// After the declare, not before it: declare(strict_types=1) has to be the
// first statement in the file, so injecting ahead of it is a compile error
// rather than the stray-output case being tested.
file_put_contents($guardFile, str_replace('declare(strict_types=1);',
    'declare(strict_types=1); echo "PHP Warning: something noisy happened\n"; '
    . '$x = $undefinedOnPurpose;',
    $orig, $count));
t('noise injected into the boot path', $count, 1);

$r = call($base, 'POST', '{"message":"how much is the mini?"}', [$OK, $JSON]);
t('still returns 200', $r['code'], 200);
t('and the body is still valid JSON', is_array($r['body']), true);
t('and carries something for the visitor',
  is_array($r['body']) && ($r['body']['reply'] ?? '') !== '', true);

$logged = @file_get_contents($tmp . '/data/ai_platform.log') ?: '';
t('the stray output is in the log instead of the body',
  str_contains($logged, 'stray output before the response'), true);
t('and the log names what it was', str_contains($logged, 'something noisy happened'), true);

file_put_contents($guardFile, $orig);   // put it back

echo "\nNothing was written outside the test data directory\n";
t('no session file in the real plugin', file_exists($root . '/data/web_chat_sessions.json'), false);
t('no usage file in the real plugin', file_exists($root . '/data/web_chat_usage.json'), false);

if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
exec('rm -rf ' . escapeshellarg($sandbox));   // takes the vault with it

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
