<?php
$R = dirname(__DIR__);
require_once $R . '/lib/EvolutionApiService.php';
require_once $R . '/lib/EvoWebhookGuard.php';

$pass = 0; $fail = 0;
function t(string $name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $name); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $name, var_export($got, true), var_export($want, true)); }
}

echo "EvolutionApiService — channel routing\n";
$cfg = [
  'evo_api_url' => 'https://evo.example/', 'evo_api_key' => 'k',
  'evo_instance_sales' => 'dishnet_sales',
  'evo_instance_support' => 'dishnet_support',
  'evo_instance_account' => 'dishnet_account',
];
$evo = new EvolutionApiService($cfg);
t('configured', $evo->isConfigured(), true);
t('channel->instance', $evo->instanceFor('sales'), 'dishnet_sales');
t('instance->channel', $evo->channelFor('dishnet_account'), 'account');
t('instance lookup is case-insensitive', $evo->channelFor('DishNet_Support'), 'support');
t('unknown instance rejected (not defaulted)', $evo->channelFor('some_other_instance'), '');
t('all three channels mapped', $evo->configuredChannels(), ['sales','support','account']);

echo "\nURL normalisation — /manager is the natural mistake\n";
// Evolution's welcome page advertises the manager URL. Pasting it makes every
// GET return the SPA's HTML with a 200 (reads as "connected, no instances")
// while every POST 404s. Strip it rather than diagnose it again.
t('strips /manager',        EvolutionApiService::normaliseBaseUrl('https://evo.host/manager'), 'https://evo.host');
t('strips /manager/',       EvolutionApiService::normaliseBaseUrl('https://evo.host/manager/'), 'https://evo.host');
t('strips /MANAGER',        EvolutionApiService::normaliseBaseUrl('https://evo.host/MANAGER'), 'https://evo.host');
t('strips /dashboard',      EvolutionApiService::normaliseBaseUrl('https://evo.host/dashboard'), 'https://evo.host');
t('leaves a clean url',     EvolutionApiService::normaliseBaseUrl('https://evo.host'), 'https://evo.host');
t('strips trailing slash',  EvolutionApiService::normaliseBaseUrl('https://evo.host/'), 'https://evo.host');
t('empty stays empty',      EvolutionApiService::normaliseBaseUrl(''), '');
t('does not eat a host ending in manager',
  EvolutionApiService::normaliseBaseUrl('https://manager.evo.host'), 'https://manager.evo.host');
$m = new EvolutionApiService(['evo_api_url'=>'https://evo.host/manager','evo_api_key'=>'k']);
t('constructor normalises too', $m->describe()['base_url'], 'https://evo.host');

echo "\nReachable vs fully-configured (the chicken-and-egg)\n";
// Listing instances must work on URL+key alone. Requiring a channel mapping
// first made the dropdown impossible on a fresh install: you needed an
// instance assigned in order to see the list you assign instances from.
$bare = new EvolutionApiService(['evo_api_url' => 'https://evo.example', 'evo_api_key' => 'k']);
t('URL+key alone -> can reach the API', $bare->canReachApi(), true);
t('URL+key alone -> NOT fully configured', $bare->isConfigured(), false);
t('no channels mapped yet', $bare->configuredChannels(), []);
$noKey = new EvolutionApiService(['evo_api_url' => 'https://evo.example']);
t('missing key -> cannot reach', $noKey->canReachApi(), false);
$full = new EvolutionApiService(['evo_api_url'=>'https://e','evo_api_key'=>'k','evo_instance_sales'=>'s']);
t('one channel mapped -> fully configured', $full->isConfigured(), true);

echo "\nEvolutionApiService — legacy config fallback\n";
$legacy = new EvolutionApiService([
  'evo_api_url'=>'https://e/','evo_api_key'=>'k',
  'evo_instance_name'=>'old_support', 'evo_accounts_instance_name'=>'old_accounts',
]);
t('old support key honoured', $legacy->instanceFor('support'), 'old_support');
t('old accounts key honoured', $legacy->channelFor('old_accounts'), 'account');
t('sales absent in legacy config', $legacy->instanceFor('sales'), '');

echo "\nEvolutionApiService — the key never leaks\n";
$d = $evo->describe();
t('describe() has no key field', array_key_exists('key', $d) || array_key_exists('api_key', $d), false);
t('describe() reports key is set', $d['key_set'], true);
t('no key value anywhere in describe()', strpos(json_encode($d), 'k"') !== false ? 'leak' : 'clean', 'clean');

echo "\nEvolutionApiService — phone/JID handling\n";
t('normalise strips +/spaces', EvolutionApiService::normalisePhone('+211 912 345 678'), '211912345678');
t('jid -> phone', EvolutionApiService::phoneFromJid('211912345678@s.whatsapp.net'), '211912345678');
t('@lid yields nothing (no phone in it)', EvolutionApiService::phoneFromJid('1354550141@lid'), '');

echo "\nEvoWebhookGuard — authentication\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$guard = new EvoWebhookGuard($pdo, ['evo_webhook_secret' => 'sekret']);

$_GET = []; $_SERVER = [];
t('no token -> reject', $guard->authenticate()[0], false);
$_GET['token'] = 'wrong';
t('wrong token -> reject', $guard->authenticate()[0], false);
$_GET['token'] = 'sekret';
t('correct token in query -> accept', $guard->authenticate()[0], true);
$_GET = []; $_SERVER['HTTP_X_DISHNET_TOKEN'] = 'sekret';
t('correct token in header -> accept', $guard->authenticate()[0], true);

$openGuard = new EvoWebhookGuard($pdo, []);
$_GET = []; $_SERVER = [];
t('unconfigured secret FAILS CLOSED', $openGuard->authenticate()[0], false);

echo "\nEvoWebhookGuard — event + replay\n";
t('normalise MESSAGES_UPSERT', EvoWebhookGuard::normaliseEvent(['event'=>'MESSAGES_UPSERT']), 'messages.upsert');
t('allowed event', $guard->isAllowedEvent('messages.upsert'), true);
t('unknown event rejected', $guard->isAllowedEvent('call.upsert'), false);
t('fresh message', $guard->isFresh(time() - 5), true);
t('20-min-old message = replay', $guard->isFresh(time() - 1200), false);
t('missing timestamp allowed', $guard->isFresh(null), true);

echo "\nEvoWebhookGuard — idempotency (the double-reply guard)\n";
t('first delivery claims', $guard->claim('MSGID_A', 'dishnet_sales', 'messages.upsert'), true);
t('duplicate delivery rejected', $guard->claim('MSGID_A', 'dishnet_sales', 'messages.upsert'), false);
t('different message still claims', $guard->claim('MSGID_B', 'dishnet_sales', 'messages.upsert'), true);
t('empty id is not a dedup key', $guard->claim('', 'x', 'y'), true);

echo "\nEvoWebhookGuard — log safety\n";
$line = EvoWebhookGuard::safeLogLine('messages.upsert', 'dishnet_sales', 'queued=1');
t('log line carries no secret', strpos($line, 'sekret'), false);

echo "\nWebhook secret generates itself\n";
$sdir = sys_get_temp_dir() . '/dishnet_ws_' . getmypid();
@mkdir($sdir, 0700, true);
$s1 = EvoWebhookGuard::autoSecret($sdir);
t('a secret is produced', strlen($s1) >= 64, true);
t('it is hex', (bool)preg_match('/^[0-9a-f]+$/', $s1), true);
t('stable across calls', EvoWebhookGuard::autoSecret($sdir), $s1);
t('stored file is owner-only', substr(sprintf('%o', fileperms("$sdir/webhook_secret")), -3), '600');
$sdir2 = $sdir . '_b'; @mkdir($sdir2, 0700, true);
t('a different install gets a different secret', EvoWebhookGuard::autoSecret($sdir2) === $s1, false);
$cfgGuard = new EvoWebhookGuard($pdo, ['evo_webhook_secret' => 'operator-chosen-value-that-is-long'], $sdir);
$_GET = ['token' => 'operator-chosen-value-that-is-long']; $_SERVER = [];
t('an explicitly configured secret still wins', $cfgGuard->authenticate()[0], true);
array_map('unlink', glob("$sdir/*") ?: []); @rmdir($sdir);
array_map('unlink', glob("$sdir2/*") ?: []); @rmdir($sdir2);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
