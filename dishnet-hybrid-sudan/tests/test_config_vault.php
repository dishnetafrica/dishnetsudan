<?php
/**
 * The re-install survival guarantee, proven with a real round trip:
 * configure → destroy the plugin's config files → reload → everything back,
 * including the webhook secret byte-for-byte. And the other direction:
 * a value deliberately turned off or changed is never resurrected.
 */
require_once dirname(__DIR__) . '/lib/PluginConfig.php';
require_once dirname(__DIR__) . '/lib/ConfigVault.php';

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

// A private little world: plugins-root / plugin / data
$root = sys_get_temp_dir() . '/vaulttest_' . bin2hex(random_bytes(4));
$plugin = $root . '/plugins/dishnet-hybrid-sudan';
$data   = $plugin . '/data';
mkdir($data, 0700, true);

$write = function (array $cfg) use ($data) {
    file_put_contents($data . '/config.json', json_encode($cfg));
};

// ── 1. A configured plugin writes a vault outside its own folder ────────
$write(['ai_enabled' => '1', 'ai_provider' => 'OpenAI', 'openai_api_key' => 'sk-test-123',
        'evo_api_url' => 'https://evo.example', 'evo_api_key' => 'evokey']);
file_put_contents($data . '/webhook_secret', 'sekrit-token-abc');
$cfg = PluginConfig::load($plugin, $data);
$vault = ConfigVault::path($plugin, $data);
t('vault lands in the plugins root, not the plugin', strpos($vault, $plugin) === false, true);
t('vault file created', is_file($vault), true);
t('vault is private (0600)', substr(sprintf('%o', fileperms($vault)), -4), '0600');

// ── 2. Simulate delete + re-install: plugin folder wiped, vault survives ─
unlink($data . '/config.json');
unlink($data . '/webhook_secret');
$cfg2 = PluginConfig::load($plugin, $data);
t('AI re-enabled after wipe',      $cfg2['ai_enabled'] ?? null, true);
t('provider restored',            $cfg2['ai_provider'] ?? null, 'OpenAI');
t('AI key restored',              $cfg2['openai_api_key'] ?? null, 'sk-test-123');
t('Evolution key restored',       $cfg2['evo_api_key'] ?? null, 'evokey');
t('webhook secret file restored byte-for-byte',
  trim((string)file_get_contents($data . '/webhook_secret')), 'sekrit-token-abc');

// ── 3. A deliberate change is never overridden by the vault ─────────────
$write(['ai_enabled' => '0', 'ai_provider' => 'OpenAI', 'openai_api_key' => 'sk-NEW-456',
        'evo_api_url' => 'https://evo.example', 'evo_api_key' => 'evokey']);
$cfg3 = PluginConfig::load($plugin, $data);
t('deliberately disabled AI stays disabled', $cfg3['ai_enabled'] ?? null, false);
t('changed key wins over vault',             $cfg3['openai_api_key'] ?? null, 'sk-NEW-456');

// ── 4. And the vault learns the new values for the next disaster ────────
unlink($data . '/config.json');
$cfg4 = PluginConfig::load($plugin, $data);
t('next wipe restores the NEW key', $cfg4['openai_api_key'] ?? null, 'sk-NEW-456');

// ── 5. The 25 Aug hazard: data dir gone entirely at first load ──────────
// The restore must recreate the directory, and even if it could not, the
// refresh must never erase the vault's copy of the secret.
exec('rm -rf ' . escapeshellarg($data));
$cfg5 = PluginConfig::load($plugin, $data);
t('data dir recreated by the vault', is_dir($data), true);
t('secret restored into the fresh data dir',
  trim((string)@file_get_contents($data . '/webhook_secret')), 'sekrit-token-abc');
$vaultNow = json_decode((string)file_get_contents($vault), true);
t('vault still holds the secret after the ordeal',
  $vaultNow['webhook_secret_file'] ?? null, 'sekrit-token-abc');

// tidy
foreach ([$data . '/webhook_secret', $data . '/config.json', $vault] as $f) @unlink($f);
@rmdir($data); @rmdir($plugin); @rmdir($root . '/plugins'); @rmdir($root);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
