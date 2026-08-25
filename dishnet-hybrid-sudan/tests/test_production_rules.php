<?php
/**
 * The commercial rules that would cost money if they silently regressed.
 *
 * 1. Prices reach the model ONLY via the live uCRM catalogue in the context.
 * 2. With no catalogue, the model is told to quote nothing and hand over.
 * 3. No price is hard-coded anywhere in the plugin's runtime code.
 * 4. A provider failure produces a handover, never an exception.
 * 5. The sales role forbids invented coverage and installation dates.
 */
require_once dirname(__DIR__) . '/lib/DishNetAiBrain.php';

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}
function has(string $n, string $hay, string $needle){ global $pass,$fail;
  if (strpos($hay,$needle)!==false){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       did not contain: %s\n",$n,$needle);}}
function hasnt(string $n, string $hay, string $needle){ global $pass,$fail;
  if (strpos($hay,$needle)===false){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       SHOULD NOT contain: %s\n",$n,$needle);}}

$brain = new DishNetAiBrain(['claude_api_key' => 'test-key']);

$catalogue = ['products' => [
    ['name'=>'Starlink Priority 500GB','price'=>112.0,'period_months'=>1],
    ['name'=>'Starlink Priority 1TB',  'price'=>189.0,'period_months'=>1],
    ['name'=>'Starlink Priority 2TB',  'price'=>336.0,'period_months'=>1],
    ['name'=>'Starlink Priority 3TB',  'price'=>483.0,'period_months'=>1],
    ['name'=>'Starlink Priority 5TB',  'price'=>784.0,'period_months'=>1],
], 'count'=>5];

$sales = ['channel'=>'sales','message'=>'How much is 1TB?','customer'=>null,'history'=>[]];

// ── 1. Catalogue in context → catalogue in prompt, verbatim ─────────────
$p = $brain->promptPreview($sales + ['products'=>$catalogue]);
has('plans block is the live-data block', $p, 'PLANS (live from our system');
foreach ($catalogue['products'] as $plan) {
    has("plan '{$plan['name']}' in prompt", $p, $plan['name']);
    has("price {$plan['price']} in prompt", $p, ' ' . rtrim(rtrim(number_format($plan['price'],2,'.',''), '0'), '.'));
}
has('prices marked quote-exactly', $p, 'quote these exactly');

// ── 2. No catalogue → forbidden to quote, told to hand over ─────────────
$p = $brain->promptPreview($sales);
has('empty catalogue announced', $p, 'PLANS: unavailable');
has('forbidden to name a price', $p, 'Do not name any plan or price');
hasnt('no plan names leak from anywhere else', $p, 'Starlink Priority');

// ── 3. Grounding rules always precede data ──────────────────────────────
$p = $brain->promptPreview($sales + ['products'=>$catalogue]);
$rules = strpos($p, 'NEVER invent a product name, price');
$data  = strpos($p, 'PLANS (live');
t('never-invent rule exists', is_int($rules), true);
t('rules come before data', $rules !== false && $data !== false && $rules < $data, true);
has('no-discount rule', $p, 'no authority to discount');
has('coverage never confirmed', $p, 'Never confirm either');

// ── 4. Provider failure = handover, never an exception ──────────────────
try {
    $r = $brain->reply($sales + ['products'=>$catalogue]);
    t('reply() does not throw on provider failure', true, true);
    t('failure escalates to a human', !empty($r['escalate']), true);
    // The reply may be empty (worker escalates) but must never quote a price.
    t('no price invented on failure', preg_match('/\$\s?\d/', (string)($r['reply'] ?? '')), 0);
} catch (\Throwable $e) {
    t('reply() does not throw on provider failure', get_class($e), 'no exception');
}

// ── 5. No hard-coded price in runtime code ──────────────────────────────
// The catalogue must be the only path a number can take to a customer. The
// preflight's EXPECTED table and the tests themselves are the deliberate
// exceptions: they exist to detect drift, not to serve customers.
$root = dirname(__DIR__);
$offenders = [];
foreach (['lib','workers','cron'] as $dir) {
    foreach (glob("$root/$dir/*.php") as $f) {
        $src = file_get_contents($f);
        foreach (['112','189','336','483','784'] as $price) {
            if (preg_match('/(?:price|cost|amount|charge)[\'"]?\s*(?:=>|=|:)\s*[\'"]?' . $price . '\b/i', $src)) {
                $offenders[] = basename($f) . " hard-codes {$price}";
            }
        }
    }
}
t('no runtime file hard-codes a plan price', $offenders, []);

// ── 6. UCRM pricing shape — verified against the live API 25 Aug 2026 ───
// Live UCRM 4.5 puts price inside `periods`, not at top level. Reading the
// top level returned null for every plan and the AI could quote nothing.
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/DishNetTools.php';

$live = DishNetTools::mapServicePlan([
    'id' => 7, 'name' => 'Starlink Priority 1TB', 'isActive' => true,
    'periods' => [
        ['period' => 1,  'price' => 189.0, 'enabled' => true],
        ['period' => 3,  'price' => 540.0, 'enabled' => false],
    ],
]);
t('periods shape: price extracted',   $live['price'], 189.0);
t('periods shape: monthly preferred', $live['period_months'], 1);

$multi = DishNetTools::mapServicePlan([
    'name' => 'X', 'periods' => [
        ['period' => 6, 'price' => 900.0, 'enabled' => true],
        ['period' => 1, 'price' => 189.0, 'enabled' => true],
    ],
]);
t('shortest enabled period wins', $multi['price'], 189.0);

$disabled = DishNetTools::mapServicePlan([
    'name' => 'X', 'periods' => [['period' => 1, 'price' => 189.0, 'enabled' => false]],
]);
t('disabled periods never quoted', $disabled['price'], null);

$top = DishNetTools::mapServicePlan(['name' => 'X', 'price' => 112, 'period' => 1]);
t('top-level price still honoured', $top['price'], 112.0);

$none = DishNetTools::mapServicePlan(['name' => 'X']);
t('no price anywhere stays null (AI says it will confirm)', $none['price'], null);

// ── 7. Hardware: same discipline as plans ───────────────────────────────
$kit = DishNetTools::mapHardwareItem(['id' => 3, 'name' => 'Starlink Standard Kit', 'price' => 350]);
t('hardware price mapped', $kit['price'], 350.0);
$nokit = DishNetTools::mapHardwareItem(['name' => 'Starlink Mini Kit']);
t('hardware without price stays null', $nokit['price'], null);

$p = $brain->promptPreview($sales + ['products' => $catalogue + [
    'hardware' => [['name' => 'Starlink Standard Kit', 'price' => 350.0]]]]);
has('hardware block rendered', $p, 'HARDWARE (one-time items, live from our system');
has('kit and price in prompt', $p, 'Starlink Standard Kit — price 350 one-time');

$p = $brain->promptPreview($sales + ['products' => $catalogue]);
has('no hardware in uCRM => AI told to confirm, not guess', $p, 'HARDWARE: no kit or installation prices');

// ── 8. Single number serving several channels routes inbound as SALES ───
require_once dirname(__DIR__) . '/lib/EvolutionApiService.php';
$one = new EvolutionApiService([
    'evo_api_url' => 'https://example.invalid', 'evo_api_key' => 'k',
    'evo_instance_sales'          => 'dishnet_sudan',
    'evo_accounts_instance_name'  => 'dishnet_sudan',   // legacy field, same instance
    'evo_instance_name'           => 'dishnet_sudan',   // legacy field, same instance
]);
t('duplicate mapping resolves to sales', $one->channelFor('dishnet_sudan'), 'sales');
$acctOnly = new EvolutionApiService([
    'evo_api_url' => 'https://example.invalid', 'evo_api_key' => 'k',
    'evo_accounts_instance_name' => 'dishnet_acct',
]);
t('account-only mapping still works', $acctOnly->channelFor('dishnet_acct'), 'account');

// ── 9. Provider name survives whatever case the config screen stores ────
$caps = new DishNetAiBrain(['ai_provider' => 'OpenAI', 'openai_api_key' => 'k']);
t('OpenAI (capitalised) finds the openai key', $caps->isConfigured(), true);

// ── 10. Upfront-cost discipline: one-time and monthly stay separate ─────
$p = $brain->promptPreview($sales + ['products' => $catalogue + [
    'hardware' => [['name' => 'Starlink Standard Kit', 'price' => 600.0],
                   ['name' => 'Professional Installation', 'price' => 50.0]]]]);
has('monthly vs one-time distinction is a rule', $p, 'MONEY IS TWO SEPARATE THINGS');
has('upfront = confirmed one-time items only',   $p, 'ONLY the confirmed one-time items from HARDWARE');
has('monthly price presented separately',        $p, 'monthly price');
has('no invented delivery/customs/taxes',        $p, 'Never add delivery, customs, taxes');
has('missing one-time price => confirm + hand over', $p, 'say you will confirm it and hand over');
$rulePos = strpos($p, 'MONEY IS TWO SEPARATE THINGS');
$dataPos = strpos($p, 'HARDWARE (one-time items');
t('upfront rule precedes the data it governs', $rulePos !== false && $dataPos !== false && $rulePos < $dataPos, true);
$sup = $brain->promptPreview(['channel' => 'support', 'message' => 'internet slow', 'customer' => null, 'history' => []]);
hasnt('sales-only rule stays off the support role', $sup, 'MONEY IS TWO SEPARATE THINGS');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
