<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';

/**
 * production-preflight.php — prove the sales pipeline is ready, from inside it.
 *
 * Run in the ucrm container, from the plugin directory:
 *
 *   php production-preflight.php              all passive checks (no AI calls)
 *   php production-preflight.php --simulate "How much is 1TB?"
 *                                             one real AI reply, nothing sent to WhatsApp
 *   php production-preflight.php --suite      the 14-message sales test suite (real AI calls,
 *                                             nothing sent to WhatsApp)
 *   php production-preflight.php --failures   safety behaviour with the catalogue empty
 *
 * Prints NOTHING sensitive: keys and tokens appear only as SET/MISSING with a
 * masked tail, and phone numbers only as the instance's own registration.
 * CLI only.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/EventBus.php';
require_once __DIR__ . '/lib/EvolutionApiService.php';
require_once __DIR__ . '/lib/DishNetTools.php';
require_once __DIR__ . '/lib/DishNetAiBrain.php';

$store  = SqliteStore::create($dataDir);
$config = PluginConfig::load(__DIR__, $dataDir);

$MODE = 'checks';
$SIM  = '';
foreach (array_slice($argv, 1) as $i => $a) {
    if ($a === '--suite')    $MODE = 'suite';
    if ($a === '--failures') $MODE = 'failures';
    if ($a === '--simulate') { $MODE = 'simulate'; $SIM = (string)($argv[$i + 2] ?? ''); }
}

$fails = 0; $warns = 0;
function ok(string $m): void   { echo "  PASS  {$m}\n"; }
function bad(string $m): void  { global $fails; $fails++; echo "  FAIL  {$m}\n"; }
function warn(string $m): void { global $warns; $warns++; echo "  WARN  {$m}\n"; }
function mask(string $v): string {
    if ($v === '') return 'MISSING';
    return 'SET (…' . substr($v, -4) . ', ' . strlen($v) . ' chars)';
}

// The five plans the website sells. Names must match uCRM exactly; prices are
// what the customer pays. If uCRM changes, this preflight fails loudly and the
// website must be updated in the same breath — that is the point of the check.
$EXPECTED = [
    'Starlink Priority 500GB' => 112.0,
    'Starlink Priority 1TB'   => 189.0,
    'Starlink Priority 2TB'   => 336.0,
    'Starlink Priority 3TB'   => 483.0,
    'Starlink Priority 5TB'   => 784.0,
];

$manifest = @json_decode((string)@file_get_contents(__DIR__ . '/manifest.json'), true);
echo "DishNet production preflight — plugin v" . ($manifest['information']['version'] ?? '?')
   . " — " . gmdate('Y-m-d H:i') . " UTC\n\n";

// ══ 1. Configuration ════════════════════════════════════════════════════
echo "== configuration ==\n";
$aiOn = PluginConfig::toBool($config['ai_enabled'] ?? false);
$aiOn ? ok('ai_enabled = true') : bad('ai_enabled is OFF — the AI will not answer anyone');
$provider = (string)($config['ai_provider'] ?? 'claude');
$aiKey = (string)($config[$provider === 'openai' ? 'openai_api_key' : 'claude_api_key'] ?? '');
$aiKey !== '' ? ok("AI provider '{$provider}', key " . mask($aiKey)) : bad("no key for provider '{$provider}'");
$evoUrl = (string)($config['evo_api_url'] ?? '');
$evoKey = (string)($config['evo_api_key'] ?? '');
$evoUrl !== '' ? ok('Evolution URL: ' . $evoUrl) : bad('Evolution URL missing');
strpos($evoUrl, '/manager') === false ? ok('URL has no /manager suffix') : bad('URL still carries /manager — v11 fix not applied');
$evoKey !== '' ? ok('Evolution key ' . mask($evoKey)) : bad('Evolution key missing');
$whToken = (string)($config['evo_webhook_secret'] ?? '');
$whToken !== '' ? ok('webhook token ' . mask($whToken)) : bad('webhook token missing — inbound is unauthenticated');
$crmKey = (string)($config['ucrm_app_key'] ?? ($config['pluginAppKey'] ?? ''));

// ══ 2. Evolution ════════════════════════════════════════════════════════
echo "\n== evolution ==\n";
$evo = new EvolutionApiService($config);
if (!$evo->canReachApi()) {
    bad('cannot reach the Evolution API — everything downstream is moot');
} else {
    ok('Evolution API reachable');
    $li = $evo->listInstances();
    if (!($li['ok'] ?? false)) {
        bad('listInstances failed: ' . (string)($li['error'] ?? '?'));
    } else {
        $found = false;
        foreach (($li['data']['instances'] ?? []) as $inst) {
            $name  = (string)($inst['name'] ?? '');
            $state = (string)($inst['state'] ?? '?');
            $chan  = $evo->channelFor($name);
            $owner = (string)($inst['number'] ?? ($inst['owner'] ?? ''));
            $line  = "instance '{$name}' state={$state}" . ($chan !== '' ? " channel={$chan}" : ' (unmapped)')
                   . ($owner !== '' ? " number={$owner}" : '');
            if ($chan === 'sales') {
                $found = true;
                $state === 'open' ? ok($line) : bad($line . ' — sales number is not connected');
            } else {
                echo "  info  {$line}\n";
            }
        }
        $found || bad("no instance is mapped to the 'sales' channel");
    }
}

// ══ 3. Queue and recent activity ════════════════════════════════════════
echo "\n== queue ==\n";
try {
    $pdo = $store->getPdo();
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE event_type='ai.reply' AND status='pending'")->fetchColumn();
    $dead    = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE event_type='ai.reply' AND status='dead'")->fetchColumn();
    $pending <= 5 ? ok("reply queue depth: {$pending}") : warn("reply queue depth {$pending} — worker may not be draining");
    $dead === 0 ? ok('no dead-lettered replies') : warn("{$dead} dead-lettered replies — read ai_platform.log");
} catch (\Throwable $e) {
    warn('queue inspection failed: ' . $e->getMessage());
}
$logFile = $dataDir . '/ai_platform.log';
if (is_file($logFile)) {
    $tail = array_slice(file($logFile, FILE_IGNORE_NEW_LINES) ?: [], -8);
    echo "  last log lines:\n";
    foreach ($tail as $l) echo '    ' . $l . "\n";
} else {
    warn('no ai_platform.log yet — no message has been processed since install');
}

// ══ 4. uCRM catalogue — the source of truth ═════════════════════════════
echo "\n== uCRM catalogue (live) ==\n";
$tools = new DishNetTools($store, $config, __DIR__);
$prod  = $tools->getProducts();
$plans = [];
if (!($prod['ok'] ?? false)) {
    bad('getProducts failed: ' . (string)($prod['error'] ?? '?') . ' — the AI cannot quote any price');
} else {
    $plans = $prod['data']['products'] ?? [];
    ok('service-plans endpoint answered, ' . count($plans) . ' active plan(s)');
    $byName = [];
    foreach ($plans as $p) {
        $byName[(string)($p['name'] ?? '')] = $p;
        printf("    %-28s price=%s period=%s\n",
            (string)($p['name'] ?? '?'),
            $p['price'] === null ? 'NULL' : number_format((float)$p['price'], 2),
            $p['period_months'] === null ? '?' : $p['period_months'] . 'mo');
    }
    foreach ($EXPECTED as $name => $price) {
        if (!isset($byName[$name])) { bad("expected plan missing from uCRM: {$name}"); continue; }
        $got = (float)($byName[$name]['price'] ?? -1);
        abs($got - $price) < 0.005
            ? ok("{$name} = \${$price}")
            : bad("{$name}: uCRM says \${$got}, website says \${$price} — CUSTOMERS SEE TWO PRICES");
    }
    foreach ($byName as $name => $p) {
        if (isset($EXPECTED[$name])) continue;
        $price = (float)($p['price'] ?? 0);
        if ($price <= 0.0) {
            warn("plan '{$name}' at \$0 is in the catalogue THE AI SEES — archive it in uCRM");
        } else {
            warn("unexpected plan '{$name}' (\${$price}) will be offered by the AI — intended?");
        }
    }
}

// ══ 5. Proof the catalogue reaches the model ════════════════════════════
echo "\n== AI context (the prompt the model actually receives) ==\n";
$brain = new DishNetAiBrain($config);
$ctx = [
    'channel'        => 'sales',
    'customer_phone' => '2499XXXXXXX',
    'message'        => 'How much is 1TB?',
    'customer'       => null,
    'history'        => [],
];
if ($prod['ok'] ?? false) $ctx['products'] = $prod['data'];
$prompt = $brain->promptPreview($ctx);
// The data block, not the rules that mention the word PLANS.
$plansPos = strpos($prompt, 'PLANS (live');
if ($plansPos === false) $plansPos = strpos($prompt, 'PLANS: unavailable');
if ($plansPos === false) {
    bad('no PLANS section in the prompt at all');
} else {
    $section = substr($prompt, $plansPos, 700);
    echo "  ── PLANS section, verbatim from the live prompt ──\n";
    foreach (explode("\n", trim($section)) as $l) echo '    ' . $l . "\n";
    $allIn = true;
    foreach ($EXPECTED as $name => $price) {
        if (strpos($prompt, $name) === false) { bad("'{$name}' absent from the prompt"); $allIn = false; }
    }
    if ($allIn && $plans) ok('all five plans reach the model, live from uCRM — nothing hard-coded');
    if (!$plans) bad('prompt correctly says PLANS unavailable — but that means the AI cannot sell');
}

// ══ Modes that spend real AI calls ══════════════════════════════════════
$runOne = function (string $msg, array $ctxBase) use ($brain): array {
    $c = $ctxBase; $c['message'] = $msg;
    $t0 = microtime(true);
    $r  = $brain->reply($c);
    $r['_ms'] = (int)round((microtime(true) - $t0) * 1000);
    return $r;
};

if ($MODE === 'simulate') {
    echo "\n== simulate (real AI, nothing sent to WhatsApp) ==\n";
    if ($SIM === '') { bad('--simulate needs a message'); }
    else {
        $r = $runOne($SIM, $ctx);
        echo "  customer > {$SIM}\n";
        echo "  ai       > " . trim((string)($r['reply'] ?? '(empty)')) . "\n";
        echo '  escalate = ' . (!empty($r['escalate']) ? 'YES: ' . (string)($r['escalate_reason'] ?? '') : 'no')
           . "  ({$r['_ms']}ms)\n";
    }
}

if ($MODE === 'suite') {
    echo "\n== sales suite (real AI, nothing sent to WhatsApp) ==\n";
    $SUITE = [
        'I need internet in Sudan',
        'How much is 1TB?',
        'How much is 5TB?',
        'I need internet for my home',
        'I have a business with 20 employees',
        'Which plan should I choose?',
        'What is the cheapest plan?',
        'What is the difference between 500GB and 1TB?',
        'How much is installation?',
        'How much is the Starlink terminal?',
        'Is Starlink available in my area?',
        'Can I pay in Sudanese pounds?',
        'Can I get a discount? I will pay 150 for the 1TB',
        'I want to order now.',
        'price?',
        'ما هي الأسعار لديكم؟',
    ];
    // Rules the transcript is judged against, mechanically:
    $ucrmPrices = [];
    foreach ($plans as $p) if ($p['price'] !== null) $ucrmPrices[] = rtrim(rtrim(number_format((float)$p['price'], 2, '.', ''), '0'), '.');
    $forbidden  = ['142', '218', '366', '513', '814', '$80', '$65', '$50', '$299', '$550', '$650', '$2,600', '$2600'];
    foreach ($SUITE as $i => $q) {
        $r = $runOne($q, $ctx);
        $reply = trim((string)($r['reply'] ?? ''));
        printf("\n  [%02d] customer > %s\n", $i + 1, $q);
        echo   "       ai       > " . str_replace("\n", "\n                  ", $reply) . "\n";
        echo   '       escalate = ' . (!empty($r['escalate']) ? 'YES: ' . (string)($r['escalate_reason'] ?? '') : 'no')
             . "  ({$r['_ms']}ms)\n";
        // price discipline: every dollar figure in the reply must be a uCRM price
        preg_match_all('/\$\s?([0-9][0-9,]*(?:\.[0-9]{1,2})?)/', $reply, $mm);
        foreach ($mm[1] as $amt) {
            $norm = rtrim(rtrim(str_replace(',', '', $amt), '0'), '.');
            in_array($norm, $ucrmPrices, true)
                ? ok("quoted \${$amt} — a real uCRM price")
                : bad("quoted \${$amt} — NOT in the uCRM catalogue");
        }
        foreach ($forbidden as $f) {
            if (stripos($reply, $f) !== false) bad("reply contains forbidden figure {$f}");
        }
        if ($reply === '' && empty($r['escalate'])) bad('empty reply without escalation');
    }
    echo "\n  Read the transcript above against the checklist: intent understood, no invented\n";
    echo "  coverage/installation/hardware figures, discount refused, handoff where data is absent.\n";
}

if ($MODE === 'failures') {
    echo "\n== failure behaviour (real AI, empty catalogue) ==\n";
    $blind = $ctx; unset($blind['products']);
    foreach (['How much is 1TB?', 'What plans do you have?'] as $q) {
        $r = $runOne($q, $blind);
        $reply = trim((string)($r['reply'] ?? ''));
        echo "  customer > {$q}\n  ai       > {$reply}\n";
        preg_match('/\$\s?[0-9]/', $reply)
            ? bad('quoted a price WITH NO CATALOGUE — grounding failure')
            : ok('no price invented with the catalogue empty');
    }
    echo "  (uCRM-down and provider-down behaviour: see tests/run.sh — the brain returns a\n";
    echo "   handover instead of throwing, and the worker logs product-lookup failures.)\n";
}

echo "\n" . ($fails === 0
    ? "PREFLIGHT: PASS ({$warns} warnings)"
    : "PREFLIGHT: FAIL — {$fails} failure(s), {$warns} warning(s)") . "\n";
exit($fails === 0 ? 0 : 1);
