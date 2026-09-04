<?php
/**
 * The forced first-login password change must actually be completable.
 *
 * Staff accounts start on the default password, which sets must_change_pwd,
 * which raises a modal with only New + Confirm fields -- while the backend
 * demanded a current_password the modal never sends. Every new staff member
 * was locked out on that screen. The fix exempts exactly the forced flow,
 * gated on the STORED record's flag, and the flag self-clears on the first
 * successful change, so the exemption is single-use by construction.
 */
$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

$root = dirname(__DIR__);
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/RetailerAuth.php';

$dir = sys_get_temp_dir() . '/dishnet_fpwd_' . getmypid();
@mkdir($dir, 0700, true);
$auth = new RetailerAuth(new JsonStore($dir));

echo "\nThe default password marks the account for a forced change\n";
$r = $auth->createRetailer(['name' => 'Test Staff', 'email' => 'staff@test.local',
                            'phone' => '+249900000042',
                            'password' => '123456']);
$id = (int)$r['id'];
$rec = $auth->getRetailerById($id);
t('a default-password account carries must_change_pwd', !empty($rec['must_change_pwd']), true);

echo "\nThe modal and the handler agree on what is sent\n";
// The contract that broke: the modal posts only new+confirm, so the handler
// may demand current_password only OUTSIDE the forced flow.
$modal = file_get_contents($root . '/public.php');
$start = strpos($modal, 'FORCED PASSWORD CHANGE MODAL');
$end   = strpos($modal, 'must_change_pwd ?>', $start);
$modal = substr($modal, $start, $end - $start);
t('the modal sends new_password', str_contains($modal, 'new_password'), true);
t('and confirm_password', str_contains($modal, 'confirm_password'), true);
t('and does NOT send current_password', str_contains($modal, 'current_password'), false);

$api = file_get_contents($root . '/includes/api/api_retailer.php');
t('the handler gates the current-password demand on the stored flag',
  str_contains($api, "\$forcedFlow = !empty(\$recR['must_change_pwd']);"), true);
t('required only outside the forced flow',
  str_contains($api, "if (!\$forcedFlow && !\$curPwd)   \$er2('Current password is required.');"), true);
t('verified only outside the forced flow',
  str_contains($api, "if (!\$forcedFlow && !\$auth->verifyPassword(\$rid, \$curPwd))"), true);

echo "\nOne successful change closes the exemption\n";
$oldToken = $rec['api_token'] ?? '';
$auth->updateRetailer($id, ['password' => 'MyNewSecret9'], false);
$rec2 = $auth->getRetailerById($id);
t('must_change_pwd clears itself', (bool)($rec2['must_change_pwd'] ?? true), false);
t('the api token rotates with the password', ($rec2['api_token'] ?? '') === $oldToken, false);
t('the new password verifies', $auth->verifyPassword($id, 'MyNewSecret9'), true);
t('the default password no longer works', $auth->verifyPassword($id, '123456'), false);

array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
