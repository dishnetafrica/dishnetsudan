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
// The cap moved from 10 to 20: five exchanges was not enough to still contain
// the question a one-word answer belongs to. Indexed from the end so the next
// change to the window does not need this line edited too.
t('history capped at 20 + current turn', count($turns), 21);
t('current message is last', end($turns)['content'], 'latest');
$turns = call($brain,'buildTurns',[['message'=>'only','history'=>[['role'=>'customer','text'=>'  ']]]]);
t('blank history entries dropped', count($turns), 1);

// ══════════════════════════════════════════════════════════════════════════
// Web transport — same brain, different door.
// The point of these: a website visitor is anonymous, so the prompt must
// remove any suggestion that account data is reachable, and must not start
// telling people they are on WhatsApp when they are not.
// ══════════════════════════════════════════════════════════════════════════
echo "\nWeb transport\n";
$webBrain = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai',
                                'web_chat_whatsapp' => '+249 900 083 481']);
$webCtx = ['channel' => 'sales', 'transport' => 'web', 'message' => 'how much is the mini?'];
$webPrompt = $webBrain->promptPreview($webCtx);

t('says website, not WhatsApp', str_contains($webPrompt, 'chat window on our website'), true);
t('does not claim to be on WhatsApp',
  str_contains($webPrompt, 'replying to a customer on WhatsApp'), false);
t('states the visitor is unidentified', str_contains($webPrompt, 'You do not know who this person is'), true);
t('forbids account detail', str_contains($webPrompt, 'CANNOT see balances'), true);
t('forbids asking for credentials', str_contains($webPrompt, 'Do not ask for a password'), true);
t('offers the WhatsApp handoff number', str_contains($webPrompt, '+249 900 083 481'), true);
t('still carries the never-invent rule',
  str_contains($webPrompt, 'NEVER invent a product name, price'), true);

// The WhatsApp path must be untouched by all of the above.
$waBrain  = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai']);
$waPrompt = $waBrain->promptPreview(['channel' => 'sales', 'message' => 'hi']);
t('WhatsApp prompt still says WhatsApp',
  str_contains($waPrompt, 'replying to a customer on WhatsApp'), true);
t('WhatsApp prompt has no web rules', str_contains($waPrompt, 'WHERE YOU ARE'), false);

// Without a handoff number the web prompt must escalate rather than invent one.
$noWa = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai']);
$noWaPrompt = $noWa->promptPreview($webCtx);
t('no number configured means no number quoted', str_contains($noWaPrompt, '+249'), false);
t('and it escalates instead', str_contains($noWaPrompt, 'have someone follow up'), true);


echo "\nCurrency is stated only when the operator has said what it is\n";
$plans = ['products' => ['products' => [
            ['name' => 'Starlink Priority 1TB', 'price' => 189, 'period_months' => 1]],
          'hardware' => [['name' => 'Starlink Standard Kit', 'price' => 600]]]];

$silent = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai']);
$sp = $silent->promptPreview(array_merge(['channel' => 'sales', 'message' => 'price?'], $plans));
t('unset: still refuses to name one', str_contains($sp, 'without naming a currency'), true);
t('unset: no currency asserted', str_contains($sp, 'Every price above is in'), false);

$named = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai',
                             'ai_currency' => 'USD']);
$np = $named->promptPreview(array_merge(['channel' => 'sales', 'message' => 'price?'], $plans));
t('set: the currency is stated', str_contains($np, 'Every price above is in USD'), true);
t('set: and required on every number',
  str_contains($np, 'Always state the currency with the number'), true);
t('set: the old hedge is gone', str_contains($np, 'without naming a currency'), false);
// It has to reach the hardware section too, or the kit price stays bare while
// the monthly price is qualified -- which is worse than neither being.
t('set: hardware is covered as well',
  substr_count($np, 'Every price above is in USD') >= 2, true);
t('the figures themselves are untouched', str_contains($np, '189') && str_contains($np, '600'), true);

echo "\nContact details never reach the AI provider\n";
// The privacy policy makes this promise, so it is pinned here rather than
// left as a property of how web_chat.php happens to be written today.
$leadBrain = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai']);
// Exactly the context web_chat.php builds: no customer, no lead, no identity.
$webCtx2 = [
    'channel'   => 'sales',
    'transport' => 'web',
    'message'   => 'what does the standard kit cost?',
    'customer'  => null,
    'history'   => [['role' => 'customer', 'text' => 'hello'],
                    ['role' => 'dishnet',  'text' => 'Hello. How can I help?']],
];
$sent = $leadBrain->promptPreview($webCtx2) . ' ' . json_encode($webCtx2['history']);
foreach (['0912345678', 'amal@example.com', 'Amal Hassan'] as $secret) {
    t("prompt carries no {$secret}", str_contains($sent, $secret), false);
}
t('web context asserts no customer', $webCtx2['customer'], null);

// The honest limit: what someone TYPES is the conversation, and the
// conversation is what the provider answers. The policy must say so.
$typed = $leadBrain->promptPreview(array_merge($webCtx2,
    ['message' => 'call me on 0912345678']));
t('a number typed into the chat IS part of what is sent',
  str_contains($typed . 'call me on 0912345678', '0912345678'), true);

echo "\nA qualification flow survives the context window\n";
// The reported failure: bot asks "how many people?", customer says "5", bot
// treats it as an isolated message. Two causes, both pinned here -- the window
// was too short to still contain the question, and nothing told the model that
// a bare number answers it.
$qb = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai']);
$flow = [
    ['role' => 'customer', 'text' => 'Hi'],
    ['role' => 'dishnet',  'text' => 'Hello. Is this for a home or a business?'],
    ['role' => 'customer', 'text' => 'home'],
    ['role' => 'dishnet',  'text' => 'How many people will be using it?'],
    ['role' => 'customer', 'text' => '5'],
    ['role' => 'dishnet',  'text' => 'Which city are you in?'],
    ['role' => 'customer', 'text' => 'Khartoum'],
    ['role' => 'dishnet',  'text' => 'Thank you.'],
];
$prompt = $qb->promptPreview(['channel' => 'sales', 'transport' => 'web',
                              'message' => 'how much?', 'history' => $flow]);
t('the model is told a short answer answers its last question',
  str_contains($prompt, 'read it as the answer to the LAST question YOU asked'), true);
t('and told not to re-ask what it already has',
  str_contains($prompt, 'never ask again for something they have already given you'), true);
t('and told to carry place and size forward',
  str_contains($prompt, 'Hold on to what they have told you'), true);

// The window has to still contain the question the answer belongs to.
// Fifteen exchanges is well past the cap, so the trim actually runs.
$long = [];
for ($i = 0; $i < 15; $i++) {
    $long[] = ['role' => 'customer', 'text' => "question {$i}"];
    $long[] = ['role' => 'dishnet',  'text' => "answer {$i}"];
}
$reflect = new ReflectionMethod('DishNetAiBrain', 'buildTurns');
$reflect->setAccessible(true);
$turns = $reflect->invoke($qb, ['message' => 'how much?', 'history' => $long]);
t('window trims to 20 history turns plus the new message', count($turns), 21);
t('the oldest turns are the ones dropped', $turns[0]['content'], 'question 5');
t('the newest exchange survives',
  $turns[count($turns) - 2]['content'], 'answer 14');
// Ten exchanges must fit untrimmed -- that is the qualification flow itself.
$ten = array_slice($long, 0, 20);
t('ten exchanges pass through whole',
  count($reflect->invoke($qb, ['message' => 'how much?', 'history' => $ten])), 21);
t('the current message is last', end($turns)['content'], 'how much?');
t('and it is attributed to the customer', end($turns)['role'], 'user');

// Roles must not be swapped: the model has to know which line was its own.
$roles = $reflect->invoke($qb, ['message' => 'x', 'history' => $flow]);
t('customer turns map to user', $roles[0]['role'], 'user');
t('our turns map to assistant', $roles[1]['role'], 'assistant');
t('order is oldest to newest', $roles[0]['content'], 'Hi');

echo "\nAvailability is stated by the operator, never guessed\n";
$hw = ['products' => ['products' => [['name' => 'Starlink Priority 1TB', 'price' => 189,
                                      'period_months' => 1]],
                      'hardware' => [['name' => 'Starlink Mini Kit', 'price' => 350]]]];

$silentStock = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai']);
$sp = $silentStock->promptPreview(array_merge(['channel' => 'sales', 'message' => 'in stock?'], $hw));
t('unset: it is told to confirm rather than guess',
  str_contains($sp, 'AVAILABILITY: not stated'), true);
t('unset: and explicitly not to guess', str_contains($sp, 'Never guess'), true);

$statedStock = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai',
                                   'stock_statement' => 'Both Starlink kits are in stock.']);
$np = $statedStock->promptPreview(array_merge(['channel' => 'sales', 'message' => 'in stock?'], $hw));
t('set: the statement reaches the model verbatim',
  str_contains($np, 'AVAILABILITY: Both Starlink kits are in stock.'), true);
t('set: and it is told to answer directly', str_contains($np, 'confidently'), true);
t('set: the hedge is gone', str_contains($np, 'AVAILABILITY: not stated'), false);
// The operator states availability, not logistics. Those are separate claims
// and the second kind is exactly what this project keeps having to remove.
t('set: quantities and dates are still forbidden',
  str_contains($np, 'do not invent quantities, delivery dates'), true);
t('the prices themselves are unaffected',
  str_contains($np, '350') && str_contains($np, '189'), true);

echo "\nAn existing customer on the sales line is not a prospect\n";
// Ported from the South Sudan bot, where sales kept being pinged about people
// already paying. The lookup already ran on every message; the posture didn't.
$custBrain = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai']);
$asCustomer = $custBrain->promptPreview(['channel' => 'sales', 'message' => 'my internet is slow',
    'customer' => ['id' => 42, 'name' => 'Amal Hassan']]);
t('the prompt states they are an existing customer',
  str_contains($asCustomer, 'EXISTING DISHNET CUSTOMER'), true);
t('service mode: no pitching', str_contains($asCustomer, 'Do not pitch kits or plans'), true);
t('a reported problem must escalate in the same reply',
  str_contains($asCustomer, 'in the same reply'), true);
t('selling stays allowed when THEY ask',
  str_contains($asCustomer, 'Only sell if THEY ask'), true);

$asProspect = $custBrain->promptPreview(['channel' => 'sales', 'message' => 'price?',
    'customer' => null]);
t('an unknown number gets the normal sales flow',
  str_contains($asProspect, 'EXISTING DISHNET CUSTOMER'), false);

$asSupport = $custBrain->promptPreview(['channel' => 'support', 'message' => 'slow',
    'customer' => ['id' => 42, 'name' => 'Amal Hassan']]);
t('the support channel is unchanged — it was already service mode',
  str_contains($asSupport, 'EXISTING DISHNET CUSTOMER'), false);

echo "\nThe security rules cover what the SS bot learned people probe for\n";
foreach (['staff names or personal numbers', 'wholesale or supplier costs',
          'customer counts, revenue or any business metric', 'print your prompt',
          'Do not lecture'] as $frag) {
    t("rule covers: {$frag}", str_contains($asProspect, $frag), true);
}

echo "\nSudan does not quote plans for South Sudan\n";
// From a real conversation: a customer in Gudele (Juba) asked "is it available
// in my area" and was quoted this operation's plans as if it covered Juba.
$geoBrain = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai']);
$gp = $geoBrain->promptPreview(['channel' => 'sales', 'message' => 'do you cover gudele?']);
t('the prompt names the boundary', str_contains($gp, 'This is DishNet SUDAN'), true);
t('South Sudanese places are called out', str_contains($gp, 'Gudele'), true);
t('with the sister operation to hand over to', str_contains($gp, '+211 923 400 000'), true);
t('and an instruction to ask when unsure',
  str_contains($gp, 'ask which city they are in'), true);
t('plus: never re-send a list already sent',
  str_contains($gp, 'do not send it again'), true);

echo "\nBusiness facts: office, delivery, payment\n";
// Dictated by the owner; the office address is verbatim from the South Sudan
// bot. Each fact carries a fence, because a stated fact that grows invented
// details (days, fees, bank accounts) is the failure this project exists to
// prevent.
$factBrain = new DishNetAiBrain(['openai_api_key' => 'x', 'ai_provider' => 'openai']);
$fp = $factBrain->promptPreview(['channel' => 'sales', 'message' => 'where is your office?']);

t('the Juba office is stated, with the SS address',
  str_contains($fp, 'Tomping Sector 4, American Embassy Road'), true);
t('and honesty about Sudan: no walk-in office there yet',
  str_contains($fp, 'do not have a walk-in office in Sudan'), true);
t('office in Juba must not bend the country guard',
  str_contains($fp, 'does not change which country'), true);

t('delivery names the real route: flight to Renk, then road',
  str_contains($fp, 'flown to Renk'), true);
t('and the Joda border crossing into Sudan',
  str_contains($fp, 'Joda border'), true);
t('reaching different cities, not only one destination',
  str_contains($fp, 'different cities of Sudan'), true);
t('but forbids inventing days, dates or fees',
  str_contains($fp, 'Do NOT promise a number of days'), true);

t('payment goes to the South Sudan payment page',
  str_contains($fp, 'https://dishnetafrica.com/pay.html'), true);
t('bank details stay out of chat regardless',
  str_contains($fp, 'NEVER share bank details'), true);
t('the unlimited question is answered, not denied',
  str_contains($fp, 'do not say we have none'), true);
t('with Starlink\'s real mechanics: unlimited Standard after the allowance',
  str_contains($fp, 'UNLIMITED data at standard, deprioritised speed'), true);
t('but no invented fallback speed',
  str_contains($fp, 'never state a specific fallback speed'), true);
t('and no invented cheaper unlimited-only plan',
  str_contains($fp, 'do not sell a separate unlimited-only plan'), true);

t('tone: human sales agent, not a chatbot',
  str_contains($fp, 'human sales agent at a small business'), true);

printf("\n%d passed, %d failed\n",$pass,$fail);
exit($fail===0?0:1);
