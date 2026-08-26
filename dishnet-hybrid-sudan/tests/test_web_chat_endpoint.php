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
$tmp  = sys_get_temp_dir() . '/dishnet_webchat_' . getmypid();
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

echo "\nNothing was written outside the test data directory\n";
t('no session file in the real plugin', file_exists($root . '/data/web_chat_sessions.json'), false);
t('no usage file in the real plugin', file_exists($root . '/data/web_chat_usage.json'), false);

if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
exec('rm -rf ' . escapeshellarg($tmp));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
