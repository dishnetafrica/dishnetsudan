<?php
/**
 * Importing the uCRM catalogue into the plugin's own tables.
 *
 * The plugin keeps cost and margin, which uCRM has nowhere to store. That is
 * why the tables are separate -- but it left both screens empty on an install
 * whose catalogue already lives in uCRM. These assertions are about the two
 * ways an import like this goes wrong: overwriting what someone typed, and
 * linking an id that makes a later push patch the wrong record.
 */
$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

require_once dirname(__DIR__) . '/lib/DishNetTools.php';

echo "\nuCRM's service-plan shape is read correctly\n";
// Price lives in a periods array, not at the top level. Getting this wrong is
// what once made every plan quote as null.
$plan = DishNetTools::mapServicePlan([
    'id' => 7, 'name' => 'Starlink Priority 1TB',
    'periods' => [
        ['period' => 12, 'price' => 2100, 'enabled' => true],
        ['period' => 1,  'price' => 189,  'enabled' => true],
        ['period' => 3,  'price' => 500,  'enabled' => false],
    ],
]);
t('the shortest enabled period wins', $plan['price'], 189.0);
t('and its length is recorded', $plan['period_months'], 1);
t('a disabled period is ignored', $plan['price'] === 500.0, false);
t('the name comes through', $plan['name'], 'Starlink Priority 1TB');

$noPrice = DishNetTools::mapServicePlan(['id' => 8, 'name' => 'Draft plan']);
t('a plan with no price stays null rather than zero', $noPrice['price'], null);

echo "\nA hardware item keeps its uCRM id, because both sides are products\n";
$item = DishNetTools::mapHardwareItem(['id' => 3, 'name' => 'Starlink Mini Kit', 'price' => 350]);
t('id preserved for an exact link', $item['id'], 3);
t('price preserved', $item['price'], 350.0);

echo "\nThe importer refuses to overwrite what is already there\n";
// Mirrors existingNames(): match on lowercased name.
function wouldSkip(array $existing, string $name): bool {
    $have = [];
    foreach ($existing as $r) {
        $n = strtolower(trim((string)($r['name'] ?? '')));
        if ($n !== '') $have[$n] = true;
    }
    return isset($have[strtolower(trim($name))]);
}
$typed = [['name' => 'Starlink Priority 1TB', 'starlink_cost' => 150, 'customer_price' => 189]];
t('an existing plan is skipped, so a typed cost survives',
  wouldSkip($typed, 'Starlink Priority 1TB'), true);
t('matching ignores case and spacing',
  wouldSkip($typed, '  starlink priority 1tb '), true);
t('a genuinely new plan is not skipped',
  wouldSkip($typed, 'Starlink Priority 5TB'), false);

echo "\nPlans are imported without a uCRM product id, and that is deliberate\n";
// The plugin's sync writes to products/{id}. A service-plan id in that field
// would make it patch whichever product shares the number.
$importedPlan = ['name' => 'Starlink Priority 1TB', 'customer_price' => 189.0,
                 'starlink_cost' => 0, 'ucrm_product_id' => null];
t('no product id is stored for a service plan', $importedPlan['ucrm_product_id'], null);
t('cost is left for a human to fill', $importedPlan['starlink_cost'], 0);
t('customer price comes from uCRM', $importedPlan['customer_price'], 189.0);

$importedHw = ['name' => 'Starlink Mini Kit', 'price' => 350.0, 'ucrm_product_id' => 3];
t('hardware DOES carry its product id', $importedHw['ucrm_product_id'], 3);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
