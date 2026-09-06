<?php
/**
 * The data directory must never flip off an established store.
 *
 * Incident (Sep 2026): two databases existed side by side -- the sibling
 * (.plugin-data) holding every conversation, ticket and message, and an empty
 * {pluginRoot}/data skeleton. getDataDir gave ucrm.json's pluginDataDir top
 * priority, so if that ever named the empty dir (an upgrade can), the plugin
 * would boot on the empty database and look like it had lost everything.
 * Once the sibling holds a plugin.sqlite3, it must win unconditionally.
 */
$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

require_once dirname(__DIR__) . '/lib/bootstrap_data.php';

$base = sys_get_temp_dir() . '/dishnet_dd_' . getmypid() . '_' . mt_rand();

// Each case uses a UNIQUE pluginRoot so getDataDir's static cache never bleeds.
function setup(string $base, string $tag): array {
    $root   = "$base/$tag/plugins/dishnet-hybrid-sudan";
    $sibling= "$base/$tag/plugins/.dishnet-hybrid-sudan-data";
    @mkdir($root, 0777, true);
    return [$root, $sibling];
}

echo "\nAn established sibling store wins even when ucrm.json points elsewhere\n";
[$root, $sibling] = setup($base, 'established');
@mkdir($sibling, 0777, true);
file_put_contents($sibling . '/plugin.sqlite3', 'REAL DATA');   // the live store
@mkdir($root . '/data', 0777, true);
file_put_contents($root . '/data/plugin.sqlite3', '');          // empty skeleton
file_put_contents($root . '/ucrm.json', json_encode(['pluginDataDir' => $root . '/data']));
t('getDataDir returns the sibling, not the ucrm.json-named empty dir',
  getDataDir($root), $sibling);

echo "\nWithout an established sibling, ucrm.json is still honoured (fresh install)\n";
[$root2] = setup($base, 'fresh_ucrm');
$ext = "$base/fresh_ucrm/external-data";
@mkdir($ext, 0777, true);
file_put_contents($root2 . '/ucrm.json', json_encode(['pluginDataDir' => $ext]));
t('a fresh install honours ucrm.json pluginDataDir', getDataDir($root2), $ext);

echo "\nWith nothing configured, it falls back to the sibling\n";
[$root3, $sibling3] = setup($base, 'fresh_bare');
t('a bare fresh install uses the sibling', getDataDir($root3), $sibling3);

exec('rm -rf ' . escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
