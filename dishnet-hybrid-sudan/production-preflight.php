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
    if ($a === '--flush-stale') $MODE = 'flush';
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
$provider = strtolower(trim((string)($config['ai_provider'] ?? 'claude')));
$aiKey = (string)($config[$provider === 'openai' ? 'openai_api_key' : 'claude_api_key'] ?? '');
$aiKey !== '' ? ok("AI provider '{$provider}', key " . mask($aiKey)) : bad("no key for provider '{$provider}'");
$evoUrl = (string)($config['evo_api_url'] ?? '');
$evoKey = (string)($config['evo_api_key'] ?? '');
$evoUrl !== '' ? ok('Evolution URL: ' . $evoUrl) : bad('Evolution URL missing');
strpos($evoUrl, '/manager') === false
    ? ok('URL has no /manager suffix')
    : warn('stored URL carries /manager — the runtime strips it, but clean the setting in Configuration');
$evoKey !== '' ? ok('Evolution key ' . mask($evoKey)) : bad('Evolution key missing');
// The token is resolved exactly as the webhook guard resolves it: the config
// key first, then the generated data/webhook_secret file.
$whToken = trim((string)($config['evo_webhook_secret'] ?? ''));
$whSrc   = 'config';
if ($whToken === '' && is_file($dataDir . '/webhook_secret')) {
    $whToken = trim((string)@file_get_contents($dataDir . '/webhook_secret'));
    $whSrc   = 'data/webhook_secret';
}
$whToken !== '' ? ok("webhook token {$whSrc}: " . mask($whToken)) : bad('webhook token missing — inbound is unauthenticated');
require_once __DIR__ . '/lib/ConfigVault.php';
$vaultFile = ConfigVault::path(__DIR__, $dataDir);
if (is_file($vaultFile)) {
    $vk = json_decode((string)@file_get_contents($vaultFile), true);
    $n  = count($vk['config'] ?? []);
    $loc = strpos($vaultFile, $dataDir) === 0 ? 'data dir (survives updates only)' : 'plugins root (survives re-install)';
    ok("config vault: {$n} key(s) protected, in the {$loc}");
} else {
    warn('config vault not written yet — it appears after the first configured load');
}
$crmKey = (string)($config['ucrm_app_key'] ?? ($config['pluginAppKey'] ?? ''));

// ══ 2. Evolution ════════════════════════════════════════════════════════
echo "\n== evolution ==\n";
$evo = new EvolutionApiService($config);
if (!$evo->canReachApi()) {
    bad('cannot reach the Evolution API — everything downstream is moot');
} else {
    ok('Evolution API reachable');
    $instances = $evo->listInstances();   // plain array; [] on failure or none
    if ($instances === []) {
        bad('no instances visible — none created yet, or the API rejected the key');
    } else {
        $found = false;
        foreach ($instances as $inst) {
            $name  = (string)($inst['name'] ?? '');
            $state = (string)($inst['state'] ?? '?');
            $chan  = $evo->channelFor($name);
            $phone = (string)($inst['phone'] ?? '');
            $line  = "instance '{$name}' state={$state}" . ($chan !== '' ? " channel={$chan}" : ' (unmapped)')
                   . ($phone !== '' ? " number={$phone}" : '');
            if ($chan === '') {
                // Unmapped: nothing routes here, so a webhook would be dropped
                // as unknown_instance anyway. Worth seeing, not worth failing.
                echo "  info  {$line}\n";
                continue;
            }

            if ($chan === 'sales') {
                $found = true;
                !empty($inst['connected']) ? ok($line) : bad($line . ' — sales number is not connected');
            } else {
                // Support and accounts are not the sales line, so a disconnected
                // one is a warning rather than a failure -- but it is still a
                // number customers write to.
                !empty($inst['connected']) ? ok($line) : warn($line . ' — not connected');
            }

            // Every MAPPED instance needs its own webhook. Checking only sales
            // meant a support number could be silently undeliverable: mapped,
            // connected, and never receiving anything, with the preflight
            // reporting it as fine.
            $wh  = $evo->getWebhook($name);
            $whS = json_encode($wh['data'] ?? []);
            if (!($wh['ok'] ?? false)) {
                bad("cannot read the webhook registration for '{$name}': " . (string)($wh['error'] ?? '?'));
            } elseif (strpos($whS, 'page=evo_webhook') === false) {
                bad("Evolution has NO webhook registered for '{$name}' ({$chan}) — inbound messages "
                  . 'will never arrive. Register it in Engage → WhatsApp AI.');
            } elseif ($whToken !== '' && strpos($whS, $whToken) === false) {
                bad("Evolution's webhook for '{$name}' carries a DIFFERENT token than ours — "
                  . 'inbound will be rejected. Re-register it.');
            } else {
                ok("Evolution webhook registered for '{$name}' ({$chan}) with our current token");
            }
        }
        $found || bad("no instance is mapped to the 'sales' channel — assign one in Engage → WhatsApp AI");
    }
}

// ══ 3. Queue and recent activity ════════════════════════════════════════
echo "\n== queue ==\n";
try {
    $pdo = $store->getPdo();
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE event_type='ai.reply' AND status IN ('pending','failed')")->fetchColumn();
    $dead    = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE event_type='ai.reply' AND status='dead'
                                  AND (error IS NULL OR error NOT LIKE 'flushed by preflight%')")->fetchColumn();
    $oldest  = (string)$pdo->query("SELECT MIN(created_at) FROM events WHERE event_type='ai.reply' AND status IN ('pending','failed')")->fetchColumn();
    if ($pending === 0) {
        ok('reply queue empty');
    } else {
        warn("reply queue depth {$pending}, oldest {$oldest} UTC");
        echo "        Draining will SEND replies to everyone still queued — replies to hours-old\n";
        echo "        messages arrive out of nowhere. To discard the backlog instead:\n";
        echo "          php production-preflight.php --flush-stale\n";
    }
    $dead === 0 ? ok('no dead-lettered replies') : warn("{$dead} dead-lettered replies — read ai_platform.log");
    if ($MODE === 'flush') {
        $n = $pdo->exec("UPDATE events SET status='dead', error='flushed by preflight --flush-stale'
                         WHERE event_type='ai.reply' AND status IN ('pending','failed')
                           AND created_at < datetime('now', '-30 minutes')");
        echo '  info  flushed ' . (int)$n . " stale queued repl(ies) older than 30 minutes — fresh messages are untouched\n";
    }
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

// Where the database actually lives decides whether it can be downloaded.
// getDataDir() falls back to {pluginRoot}/data when ucrm.json names no
// pluginDataDir -- and the plugin directory IS served over HTTP, so that
// fallback puts customer contact details behind a guessable URL.
$dbFile = $dataDir . '/plugin.sqlite3';
$inWebDir = strpos(realpath($dataDir) ?: $dataDir, realpath(__DIR__) ?: __DIR__) === 0;
if (!$inWebDir) {
    ok('database is outside the plugin web directory');
} else {
    // The path alone is not the question -- whether it can be FETCHED is.
    // uCRM decides pluginDataDir; on installs that do not set it the data
    // directory lands inside the plugin, and older uCRM has no way to move it.
    // So ask the server instead of inferring from the layout: if the file is
    // not reachable over HTTP, the location is a note, not a fault.
    $probe = '';
    $ucrmJson = @json_decode((string)@file_get_contents(__DIR__ . '/ucrm.json'), true);
    $pub = (string)($ucrmJson['pluginPublicUrl'] ?? '');
    if ($pub !== '') $probe = preg_replace('~/public\.php.*$~', '', $pub) . '/data/plugin.sqlite3';

    if ($probe === '') {
        warn('database sits inside the plugin directory (' . $dataDir . ') and no public URL '
           . 'is known, so reachability could not be tested — check it by hand');
    } else {
        $ch = curl_init($probe);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_SSL_VERIFYHOST => 0]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            bad('THE DATABASE IS DOWNLOADABLE at ' . $probe . ' — it holds customer contact '
              . 'details and chat transcripts. Block it before anything else.');
        } elseif ($code === 0) {
            warn('could not reach ' . $probe . ' to test whether the database is downloadable '
               . '— test it from outside the server');
        } else {
            ok(sprintf('database is inside the plugin directory but not served (HTTP %d)', $code));
        }
    }
}
if (is_file($dbFile)) {
    $perms = substr(sprintf('%o', fileperms($dbFile)), -4);
    $perms <= '0640'
        ? ok("plugin.sqlite3 permissions {$perms}")
        : warn("plugin.sqlite3 is {$perms} — it holds contact details and transcripts, "
             . 'tighten it to 0640 or stricter');
}

$retDays = (int)($config['web_chat_retention_days'] ?? 90);
$retDays > 0
    ? ok("chat contact details deleted after {$retDays} days")
    : warn('retention is 0 — chat contact details and transcripts are kept forever, '
         . 'which must match what privacy.html tells visitors');

// Whether a reply is immediate or waits for cron. The webhook spawns a worker
// the moment a message arrives -- but only if it can find a PHP CLI binary.
// PHP_BINARY under php-fpm is the FPM master, not a CLI, so the spawn silently
// never ran and every customer waited for the five-minute scheduled run.
$cliCandidates = array_filter([
    defined('PHP_BINDIR') && PHP_BINDIR !== '' ? PHP_BINDIR . '/php' : null,
    '/usr/local/bin/php', '/usr/bin/php',
]);
$cliFound = '';
foreach ($cliCandidates as $c) { if (@is_executable($c)) { $cliFound = $c; break; } }
$execAllowed = function_exists('exec')
    && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);

if (!$execAllowed) {
    warn('exec() is disabled — replies cannot be immediate and will wait for the scheduled run');
} elseif ($cliFound === '') {
    bad('no PHP CLI binary found (' . implode(', ', $cliCandidates) . ') — every reply waits for '
      . 'the scheduled run instead of a few seconds');
} else {
    ok('replies are immediate — worker spawns via ' . $cliFound);
}

$alertTo = preg_replace('/[^0-9+]/', '', (string)($config['alert_whatsapp'] ?? ''));
if ($alertTo !== '') {
    ok("escalations and waiting customers alert +{$alertTo} (watchdog every 15 min, "
     . (int)($config['alert_hours_from'] ?? 7) . '-' . (int)($config['alert_hours_to'] ?? 21)
     . 'h, patience ' . (int)($config['alert_patience_minutes'] ?? 10) . ' min)');
} else {
    warn('no alert number set — when the AI hands off, or a customer waits unanswered, '
       . 'NOBODY IS TOLD. The queue once sat for hours exactly this way. Set it in '
       . 'Engage → WhatsApp AI.');
}

$aiCur = trim((string)($config['ai_currency'] ?? ''));
$aiCur !== ''
    ? ok("AI quotes prices in {$aiCur}")
    : warn('no currency set — the AI gives bare numbers while the website quotes $, '
         . 'so a customer can read a price as SDG');

// ══ 3b. Website chat — the second door to the same brain ════════════════
echo "\n== website chat ==\n";
require_once __DIR__ . '/lib/WebChatGuard.php';
$wcOn = PluginConfig::toBool($config['web_chat_enabled'] ?? false);
if (!$wcOn) {
    warn('website chat is OFF — visitors without WhatsApp have no way to ask');
} else {
    ok('web_chat_enabled = true');

    $origins = array_filter(array_map('trim', explode(',', (string)($config['web_chat_origins'] ?? ''))));
    if (!$origins) {
        warn('no allowed websites set — the endpoint falls back to the dishnetsudan.com default');
    } elseif (in_array('*', $origins, true)) {
        bad('web_chat_origins contains * — any site could spend your AI budget');
    } else {
        foreach ($origins as $o) {
            strpos($o, 'https://') === 0
                ? ok('accepts ' . $o)
                : bad("allowed website '{$o}' is not https — the browser will refuse it");
        }
    }

    $wcWa = preg_replace('/\D+/', '', (string)($config['web_chat_whatsapp'] ?? ''));
    $wcWa !== ''
        ? ok('hands off to +' . $wcWa)
        : warn('no handoff number — a capped or escalated visitor is told to wait rather than message');

    $guard = new WebChatGuard($store, $config);
    $st    = $guard->stats();
    if ($guard->ratesConfigured()) {
        $pct = $st['budget_usd'] > 0 ? ($st['spent_month_usd'] / $st['budget_usd']) * 100 : 0;
        $line = sprintf('spend this month $%.2f of $%.2f (%.0f%%)',
                        $st['spent_month_usd'], $st['budget_usd'], $pct);
        if ($pct >= 100)     bad($line . ' — the chat is refusing visitors');
        elseif ($pct >= 80)  warn($line);
        else                 ok($line);
    } else {
        warn('token rates not set, so the monthly budget cannot be enforced — '
           . "the message caps are the ceiling ({$st['daily_max']}/day)");
    }
    ok(sprintf('%d message(s) today, %d this month', $st['today'], $st['month']));

    // The endpoint answers as the same brain. If the provider key is missing it
    // degrades to a WhatsApp handoff, which is safe but should not be a surprise.
    $brainOk = (new DishNetAiBrain($config))->isConfigured();
    $brainOk
        ? ok('provider key present — the website chat can answer')
        : warn('no provider key — the website chat will only offer WhatsApp');
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
    $hw = $prod['data']['hardware'] ?? [];
    if ($hw) {
        ok('Products endpoint answered, ' . count($hw) . ' hardware item(s)');
        foreach ($hw as $h) {
            printf("    %-28s price=%s one-time\n", (string)($h['name'] ?? '?'),
                $h['price'] === null ? 'NULL (AI will say it will confirm)' : number_format((float)$h['price'], 2));
        }
    } else {
        warn('no items in uCRM Products — the AI cannot quote kit or installation prices '
           . '(CRM → Service plans & Products → Products)');
    }
    if (!empty($prod['data']['hardware_error'])) {
        warn('hardware lookup error: ' . (string)$prod['data']['hardware_error']);
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
        'How much do I need to pay to get started with 1TB?',
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
    foreach (array_merge($plans, $prod['ok'] ? ($prod['data']['hardware'] ?? []) : []) as $p)
        if (($p['price'] ?? null) !== null) $ucrmPrices[] = rtrim(rtrim(number_format((float)$p['price'], 2, '.', ''), '0'), '.');
    // A total of one-time items is a legitimate quote ("kit + installation =
    // $650 one-time"). Whitelist every subset sum of HARDWARE prices — and
    // ONLY hardware: a monthly price blended into an upfront figure must
    // still be flagged, because that is exactly the violation the upfront
    // rule forbids.
    $hwPrices = [];
    foreach (($prod['ok'] ? ($prod['data']['hardware'] ?? []) : []) as $h)
        if (($h['price'] ?? null) !== null) $hwPrices[] = (float)$h['price'];
    if (count($hwPrices) <= 10) {
        $sums = [0.0];
        foreach ($hwPrices as $hp) foreach ($sums as $sv) $sums[] = $sv + $hp;
        foreach (array_unique($sums) as $sv) if ($sv > 0)
            $ucrmPrices[] = rtrim(rtrim(number_format($sv, 2, '.', ''), '0'), '.');
    }
    $ucrmPrices = array_values(array_unique($ucrmPrices));
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
            // A figure stops being forbidden the moment it is a real catalogue
            // price: $50 was the old South Sudan install fee AND is now the
            // live Sudan installation price. The live catalogue always wins.
            $num = rtrim(rtrim(str_replace([',', '$'], '', $f), '0'), '.');
            if (in_array($num, $ucrmPrices, true)) continue;
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
