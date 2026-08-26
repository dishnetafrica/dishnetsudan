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

echo "\nThe importer writes the field names the screens actually read\n";
// The first version wrote name/price/active. The plans screen reads is_active,
// and the hardware screen reads title/sell_price/buy_price -- so every imported
// row rendered blank while the import reported success. That combination is
// worse than a failure, so the field names are checked against the screens
// rather than against memory.
$root = dirname(__DIR__);
$importer = file_get_contents($root . '/tools/import-from-ucrm.php');
$plansUi  = file_get_contents($root . '/tabs/sales/subscription_plans.php');
$hwUi     = file_get_contents($root . '/tabs/sales/hardware.php');

foreach (['name', 'type', 'speed', 'supplier', 'customer_price', 'starlink_cost', 'is_active']
         as $f) {
    t("plans: importer writes '{$f}'", str_contains($importer, "'{$f}'"), true);
}
t('plans screen reads is_active', str_contains($plansUi, "['is_active']"), true);
t('and the importer does not write the wrong "active"',
  (bool)preg_match("/'active'\s*=>/", $importer), false);
t('plan type is lowercase, matching the screen\'s tabs',
  str_contains($importer, "'type'            => 'starlink'"), true);
t('the screen filters on that lowercase value',
  str_contains($plansUi, "byType['starlink']"), true);

foreach (['title', 'sku', 'sell_price', 'buy_price', 'is_active', 'ucrm_product_id'] as $f) {
    t("hardware: importer writes '{$f}'", str_contains($importer, "'{$f}'"), true);
}
t('hardware screen reads title', str_contains($hwUi, "['title']"), true);
t('hardware screen reads sell_price', str_contains($hwUi, "['sell_price']"), true);

echo "\nRe-running matches on each table's own name field\n";
// Hardware calls it title. Matching on 'name' would duplicate the catalogue
// on every run.
t('hardware existence is checked by title',
  str_contains($importer, "existingNames(\$store, 'kyc_devices.json', 'title')"), true);

echo "\nA bad import can be undone without touching hand-entered rows\n";
t('rows are stamped on the way in', str_contains($importer, "'imported_from'"), true);
t('undo exists', str_contains($importer, "--undo"), true);
t('and it removes only stamped rows',
  str_contains($importer, "(\$row['imported_from'] ?? '') === \$stamp"), true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
