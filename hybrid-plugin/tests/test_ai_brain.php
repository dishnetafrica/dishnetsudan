<?php
require_once dirname(__DIR__) . '/lib/DishNetAiBrain.php';

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}
function has(string $n, string $hay, string $needle){ global $pass,$fail;
  if (strpos($hay,$needle)!==false){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       prompt did not contain: %s\n",$n,$needle);}}
function hasnt(string $n, string $hay, string $needle){ global $pass,$fail;
  if (strpos($hay,$needle)===false){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       prompt SHOULD NOT contain: %s\n",$n,$needle);}}

/** Reach a private method for testing. */
function call(DishNetAiBrain $b, string $m, array $args) {
    $r = new ReflectionMethod('DishNetAiBrain', $m); $r->setAccessible(true);
    return $r->invokeArgs($b, $args);
}

$brain = new DishNetAiBrain(['claude_api_key' => 'test-key']);

echo "Fails safe when unconfigured\n";
$blind = new DishNetAiBrain([]);
t('no key -> not configured', $blind->isConfigured(), false);
$r = $blind->reply(['channel'=>'sales','message'=>'What plans do you have?']);
t('no key -> escalates, does not answer', [$r['reply'], $r['escalate']], ['', true]);
$r = $brain->reply(['channel'=>'sales','message'=>'   ']);
t('empty message -> escalates', $r['escalate'], true);

echo "\nAsking for a human short-circuits before any model call\n";
foreach (['let me speak to a human','I want to talk to an agent','can I chat with someone',
          'give me a real person'] as $msg) {
    $r = $brain->reply(['channel'=>'support','message'=>$msg]);
    t("'".$msg."' -> escalate", $r['escalate'], true);
}
// Uses a bogus key, so the provider rejects it and the brain escalates. What
// matters here is only that it did NOT take the human-request short-circuit —
// it went on to consult the model.
$r = $brain->reply(['channel'=>'support','message'=>'my internet is slow']);
t('an ordinary fault does NOT take the human short-circuit',
  $r['escalate_reason'] === 'Customer asked for a human agent', false);
t('a provider rejection escalates rather than inventing an answer',
  [$r['reply'], $r['escalate']], ['', true]);

echo "\nMarker parsing — customers must never see a marker\n";
$r = call($brain,'parseMarkers',["Your balance is 45.00.\n<<ESCALATE billing dispute>>"]);
t('escalate marker detected', $r['escalate'], true);
t('reason captured', $r['escalate_reason'], 'billing dispute');
t('marker stripped from reply', $r['reply'], 'Your balance is 45.00.');

$r = call($brain,'parseMarkers',["Here you go.\n<<QUOTE Home 20>>"]);
t('quote marker escalates to staff', $r['escalate'], true);
t('quote marker stripped', $r['reply'], 'Here you go.');

$r = call($brain,'parseMarkers',["Sure.\n<<SOMETHING_UNKNOWN foo>>"]);
t('UNKNOWN marker still stripped (no leak)', $r['reply'], 'Sure.');
t('unknown marker does not escalate', $r['escalate'], false);

$r = call($brain,'parseMarkers',["<<ESCALATE cannot help>>"]);
t('marker-only reply gets human-safe text', $r['reply'] !== '', true);

$long = str_repeat('word ', 500);
$r = call($brain,'parseMarkers',[$long]);
t('over-long reply is truncated', mb_strlen($r['reply']) <= DishNetAiBrain::MAX_REPLY_CHARS, true);

echo "\nGrounding rules are always present\n";
$p = call($brain,'buildSystemPrompt',[['channel'=>'sales','message'=>'hi']]);
has('forbids inventing prices', $p, 'NEVER invent a product name, price');
has('forbids treating null as a value', $p, 'you do not know it');
has('forbids accepting customer prices', $p, 'OUR PRICES ARE FIXED');
has('forbids leaking other customers', $p, "another customer's information");
has('prefers handover over guessing', $p, 'better than a plausible guess');
has('mirrors customer language incl. Arabic', $p, 'reply in Arabic');

echo "\nChannel roles differ, brain does not\n";
$sales   = call($brain,'buildSystemPrompt',[['channel'=>'sales','message'=>'x']]);
$support = call($brain,'buildSystemPrompt',[['channel'=>'support','message'=>'x']]);
$account = call($brain,'buildSystemPrompt',[['channel'=>'account','message'=>'x']]);
has('sales role named',   $sales,   'YOUR ROLE ON THIS NUMBER: SALES');
has('support role named', $support, 'YOUR ROLE ON THIS NUMBER: SUPPORT');
has('account role named', $account, 'YOUR ROLE ON THIS NUMBER: ACCOUNTS');
hasnt('sales cannot see billing', $sales, 'YOUR ROLE ON THIS NUMBER: ACCOUNTS');
has('sales advises on need, not product name', $sales, 'describe a NEED');
has('sales must not confirm coverage', $sales, 'Never confirm either');
has('support reads live line status', $support, 'LINE STATUS shows the connection is up');
has('account refuses unidentified callers', $account, 'Do not confirm or deny');
t('only sales offers QUOTE', [strpos($sales,'<<QUOTE')!==false, strpos($account,'<<QUOTE')!==false], [true,false]);

echo "\nData block states only what the tools returned\n";
$ctx = ['channel'=>'sales','message'=>'x','products'=>['products'=>[
    ['name'=>'Home 20','price'=>100000.0,'period_months'=>1,'download_speed'=>'20 Mbps'],
    ['name'=>'Office 50','price'=>null],                    // price genuinely unknown
]]];
$d = call($brain,'dataBlock',[$ctx]);
has('real plan and price rendered', $d, 'Home 20 — price 100000 per month');
has('speed rendered when present', $d, 'download 20 Mbps');
has('missing price is admitted, not invented', $d, 'price not listed');

$d = call($brain,'dataBlock',[['channel'=>'sales','message'=>'x']]);
has('no products -> forbids naming any plan', $d, 'Do not name any plan or price');

$d = call($brain,'dataBlock',[['channel'=>'account','message'=>'x','identity_ambiguous'=>true]]);
has('ambiguous identity -> reveal nothing', $d, 'Reveal nothing until then');

$d = call($brain,'dataBlock',[['channel'=>'account','message'=>'x',
    'customer'=>['name'=>'John Deng','is_lead'=>false],
    'account'=>['balance'=>45.0,'invoice'=>['number'=>'INV1','amount_due'=>45.0,'due_date'=>'2026-09-01']]]]);
has('balance stated as owed', $d, '45.00 OWED by the customer');
has('invoice number stated', $d, 'INV1');

$d = call($brain,'dataBlock',[['channel'=>'account','message'=>'x','account'=>['balance'=>-20.0]]]);
has('credit stated as credit', $d, '20.00 in CREDIT');

$d = call($brain,'dataBlock',[['channel'=>'support','message'=>'x']]);
has('unidentified customer is stated plainly', $d, 'Not identified');

echo "\nHistory window is bounded\n";
$hist = [];
for ($i=0;$i<40;$i++) $hist[] = ['role'=>'customer','text'=>'msg '.$i];
$turns = call($brain,'buildTurns',[['message'=>'latest','history'=>$hist]]);
t('history capped at 10 + current turn', count($turns), 11);
t('current message is last', $turns[10]['content'], 'latest');
$turns = call($brain,'buildTurns',[['message'=>'only','history'=>[['role'=>'customer','text'=>'  ']]]]);
t('blank history entries dropped', count($turns), 1);

printf("\n%d passed, %d failed\n",$pass,$fail);
exit($fail===0?0:1);
