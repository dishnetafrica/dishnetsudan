<?php
require_once dirname(__DIR__) . '/lib/PluginConfig.php';

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

$dir = sys_get_temp_dir() . '/dishnet_gate_' . getmypid();
@mkdir($dir, 0700, true);
$cleanup = function() use ($dir) { array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir); };

echo "\nSecrets can never be written from a plugin page\n";
foreach (['evo_api_key','evo_webhook_secret','claude_api_key','openai_api_key','ai_tools_token','admin_token'] as $k) {
    list($ok, $err) = PluginConfig::saveOverrides($dir, [$k => 'x']);
    t("refuses to store $k", [$ok, $err], [false, 'Secrets can only be set on the uCRM Configuration screen.']);
}
t('no file written by a refused save', file_exists("$dir/kyc_config.json"), false);

echo "\nNon-secret overrides save and clear\n";
list($ok,) = PluginConfig::saveOverrides($dir, ['evo_instance_sales' => 'dishnet_sales', 'ai_enabled' => true]);
t('save accepted', $ok, true);
$c = json_decode((string)file_get_contents("$dir/kyc_config.json"), true);
t('value stored', $c['evo_instance_sales'] ?? null, 'dishnet_sales');
PluginConfig::saveOverrides($dir, ['evo_instance_sales' => '']);
$c = json_decode((string)file_get_contents("$dir/kyc_config.json"), true);
t('empty value clears the override', array_key_exists('evo_instance_sales', $c), false);
t('other values survive', $c['ai_enabled'] ?? null, true);

echo "\nSecrets stay out of anything rendered\n";
$cfg = ['evo_api_key'=>'EVOKEY1','claude_api_key'=>'CLAUDEKEY1','admin_token'=>'ADM1','evo_api_url'=>'https://x'];
// JSON_UNESCAPED_SLASHES so the URL assertion below compares like for like —
// json_encode would otherwise write https:\/\/x.
$redacted = PluginConfig::redacted($cfg);
$safe = json_encode($redacted, JSON_UNESCAPED_SLASHES);
foreach (['EVOKEY1','CLAUDEKEY1'] as $s) t("redacted() hides $s", strpos($safe,$s), false);
t('redacted() keeps non-secrets', $redacted['evo_api_url'] ?? null, 'https://x');
t('redacted() marks a set secret', strpos($safe,'[set]') !== false, true);

echo "\nCheckbox values from uCRM are normalised\n";
foreach ([['1',true],['0',false],['true',true],['false',false],['yes',true],['on',true],['',false],[1,true],[0,false],[true,true]] as $case) {
    t("toBool(".var_export($case[0],true).")", PluginConfig::toBool($case[0]), $case[1]);
}

$cleanup();
echo "\nEvolution credentials: the one permitted secret path\n";
$cdir = sys_get_temp_dir() . '/dishnet_cred_' . getmypid();
@mkdir($cdir, 0700, true);
list($ok,$err) = PluginConfig::saveEvolutionCredentials($cdir, 'https://evo.example.host', 'KEY123456');
t('saves url and key', [$ok,$err], [true,'']);
$c = json_decode((string)file_get_contents("$cdir/kyc_config.json"), true);
t('url stored',  $c['evo_api_url'] ?? null, 'https://evo.example.host');
t('key stored',  $c['evo_api_key'] ?? null, 'KEY123456');

// A blank key must not wipe a stored one -- the form shows a mask, not the value.
PluginConfig::saveEvolutionCredentials($cdir, 'https://evo.example.host', '');
$c = json_decode((string)file_get_contents("$cdir/kyc_config.json"), true);
t('blank key keeps the stored one', $c['evo_api_key'] ?? null, 'KEY123456');

PluginConfig::saveEvolutionCredentials($cdir, 'https://other.host/', 'NEWKEY');
$c = json_decode((string)file_get_contents("$cdir/kyc_config.json"), true);
t('url trailing slash stripped', $c['evo_api_url'] ?? null, 'https://other.host');
t('new key replaces old',        $c['evo_api_key'] ?? null, 'NEWKEY');

list($ok,$err) = PluginConfig::saveEvolutionCredentials($cdir, 'evo.example.host', 'K');
t('refuses a url without a scheme', [$ok, $err], [false, 'The API URL must start with https://']);

t('file is owner-only', substr(sprintf('%o', fileperms("$cdir/kyc_config.json")), -3), '600');
array_map('unlink', glob("$cdir/*") ?: []); @rmdir($cdir);

printf("\n%d passed, %d failed\n",$pass,$fail);
exit($fail===0?0:1);
