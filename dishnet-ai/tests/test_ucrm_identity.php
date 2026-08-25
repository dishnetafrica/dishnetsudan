<?php
require_once dirname(__DIR__) . '/lib/UcrmUser.php';

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}
function call(string $m, array $args) {
    $r = new ReflectionMethod('UcrmUser', $m); $r->setAccessible(true);
    return $r->invokeArgs(null, $args);
}

echo "No uCRM session means no access — and no pointless API call\n";
$_COOKIE = [];
UcrmUser::reset();
$u = UcrmUser::current('/nonexistent');
t('not authenticated', $u['authenticated'], false);
t('not admin', $u['is_admin'], false);
t('reason is about signing in', $u['reason'], 'Not signed in to UISP.');
t('isAdmin() agrees', UcrmUser::isAdmin('/nonexistent'), false);

echo "\nOnly uCRM session cookies are forwarded\n";
$_COOKIE = ['nms-session'=>'A1', 'nms-crm-php-session-id'=>'B2', 'PHPSESSID'=>'C3',
            'tracking_id'=>'LEAKME', 'some_other'=>'ALSOLEAK'];
$hdr = call('sessionCookieHeader', []);
t('carries nms-session',            strpos($hdr,'nms-session=A1') !== false, true);
t('carries nms-crm-php-session-id', strpos($hdr,'nms-crm-php-session-id=B2') !== false, true);
t('carries PHPSESSID (older uCRM)', strpos($hdr,'PHPSESSID=C3') !== false, true);
t('does NOT forward unrelated cookies', strpos($hdr,'LEAKME'), false);
t('does NOT forward some_other',        strpos($hdr,'ALSOLEAK'), false);

$_COOKIE = [];
t('no cookies -> empty header', call('sessionCookieHeader', []), '');

echo "\nEndpoint discovery covers both UISP layouts\n";
$urls = call('candidateUrls', [['ucrmLocalUrl'=>'https://local:8443/crm','ucrmPublicUrl'=>'https://pub/crm']]);
t('base already ending /crm is not doubled', isset($urls['https://local:8443/crm/current-user']), true);
t('no /crm/crm/ produced', isset($urls['https://local:8443/crm/crm/current-user']), false);
t('local is tried as local', $urls['https://local:8443/crm/current-user'] ?? null, true);
t('public is tried as public', $urls['https://pub/crm/current-user'] ?? null, false);

$urls = call('candidateUrls', [['ucrmLocalUrl'=>'https://local:8443']]);
t('bare base gets the UISP 1.0 path', isset($urls['https://local:8443/crm/current-user']), true);
t('bare base also tries the legacy path', isset($urls['https://local:8443/current-user']), true);

t('no urls without config', call('candidateUrls', [[]]), []);

echo "\nMissing ucrm.json is reported, not guessed around\n";
$_COOKIE = ['nms-session'=>'x'];
UcrmUser::reset();
$u = UcrmUser::current('/definitely/not/a/plugin');
t('ok=false when ucrm.json is absent', $u['ok'], false);
t('reason mentions ucrm.json', strpos($u['reason'],'ucrm.json') !== false, true);
t('still not admin', $u['is_admin'], false);

echo "\nPer-request cache\n";
UcrmUser::reset();
$a = UcrmUser::current('/definitely/not/a/plugin');
$b = UcrmUser::current('/a/completely/different/path');
t('second call returns the cached answer', $a === $b, true);
UcrmUser::reset();

printf("\n%d passed, %d failed\n",$pass,$fail);
exit($fail===0?0:1);
