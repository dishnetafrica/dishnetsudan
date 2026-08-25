<?php
require_once dirname(__DIR__) . '/lib/DishNetTools.php';

/** Minimal store stub: only load() is used by the identify path. */
class FakeStore {
    public array $data = [];
    public function load(string $f) { return $this->data[$f] ?? []; }
}

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

// A realistic index: one full number, and one dangerously SHORT stored number
// of the kind that makes the old matcher leak.
$store = new FakeStore();
$store->data['client_search_index.json'] = [
    ['id'=>101, 'name'=>'John Deng',  'phone'=>'211912345678'],
    ['id'=>202, 'name'=>'Short Entry','phone'=>'345678'],       // 6 digits only
];
// No CRM configured -> API fallback is skipped, so we test index matching alone.
$tools = new DishNetTools($store, [], '/nonexistent');

echo "Strict matching (the fix)\n";
$r = $tools->identifyCustomerByPhone('211912345678');
t('exact number identifies the right customer', $r['data']['customer']['id'] ?? null, 101);

// THE LEAK CASE: '249111345678' shares its last 6 digits ('345678') with the
// short stored entry #202. The old matcher would have matched it and disclosed
// that customer's billing. The fix must not.
$r = $tools->identifyCustomerByPhone('249111345678');
t('short stored number no longer matches a foreign number',
   $r['data']['customer']['id'] ?? null, null);
t('CRM unreachable -> honest error, never a false "no such customer"',
   [$r['ok'], $r['error']], [false, 'CRM is not configured']);

$r = $tools->identifyCustomerByPhone('1234567');
t('too-short input refused outright', $r['ok'], false);

echo "\nAmbiguity is never guessed\n";
$amb = new FakeStore();
$amb->data['client_search_index.json'] = [
    ['id'=>1,'name'=>'A','phone'=>'211912345678'],
    ['id'=>2,'name'=>'B','phone'=>'256912345678'],   // same last 9 digits
];
$r = (new DishNetTools($amb, [], '/nonexistent'))->identifyCustomerByPhone('211912345678');
t('two customers share last 9 digits -> ambiguous, not a pick',
   [$r['data']['found'] ?? null, $r['data']['reason'] ?? null], [false,'ambiguous']);
t('ambiguous result discloses no customer', isset($r['data']['customer']), false);

echo "\nLegacy mode still available for rollback\n";
$legacy = new DishNetTools($store, ['tools_legacy_phone_match'=>true], '/nonexistent');
$r = $legacy->identifyCustomerByPhone('249111345678');
t('legacy mode reproduces the OLD loose match (the leak)',
   $r['data']['customer']['id'] ?? ($r['data']['reason'] ?? null), 202);

echo "\nEnvelope shape\n";
$r = $tools->getProducts();
t('no CRM configured -> clean error, no exception', $r['ok'], false);
t('error envelope has the three keys', array_keys($r), ['ok','data','error']);

printf("\n%d passed, %d failed\n",$pass,$fail);
exit($fail===0?0:1);
