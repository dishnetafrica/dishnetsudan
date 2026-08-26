<?php
/**
 * The plugin's data must survive an upgrade.
 *
 * uCRM replaces the plugin directory when a new version is uploaded. While the
 * data directory lived inside it, every upload destroyed the database --
 * customers, staff, conversations, leads -- and the plugin came back showing
 * its first-run screen. It happened repeatedly before anyone connected the two
 * events, and a backup written beside the database went with it.
 *
 * These tests wipe the plugin directory the way uCRM does and require the data
 * to still be there afterwards.
 */
$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

require_once dirname(__DIR__) . '/lib/bootstrap_data.php';

$sandbox = sys_get_temp_dir() . '/dishnet_upgrade_' . getmypid();
$plugins = $sandbox . '/plugins';                    // uCRM's plugins root
$root    = $plugins . '/dishnet-hybrid-sudan';       // the plugin itself
@mkdir($root . '/lib', 0777, true);

/** Replace the plugin directory, exactly as an upgrade does. */
function reinstall(string $root): void {
    exec('rm -rf ' . escapeshellarg($root));
    @mkdir($root . '/lib', 0777, true);
    @mkdir($root . '/data', 0777, true);             // the empty data/ the zip ships
    file_put_contents($root . '/data/.gitkeep', '');
}

echo "\nThe data directory is chosen outside the plugin\n";
$dir = getDataDir($root);
t('it is not inside the plugin directory', strpos($dir, $root . '/') === 0, false);
t('it is a sibling at the plugins root', dirname($dir), $plugins);
t('and it exists', is_dir($dir), true);

echo "\nAn upgrade cannot reach it\n";
file_put_contents($dir . '/plugin.sqlite3', 'THE CUSTOMER DATABASE');
file_put_contents($dir . '/webhook_secret', 'the-shared-secret');
reinstall($root);

// A fresh process would not have the cached path, so ask again from scratch.
$out = [];
exec(sprintf('php -r %s 2>/dev/null',
    escapeshellarg('require "' . dirname(__DIR__) . '/lib/bootstrap_data.php";'
                 . ' $d = getDataDir("' . $root . '");'
                 . ' echo $d, "|", @file_get_contents($d . "/plugin.sqlite3"),'
                 . ' "|", @file_get_contents($d . "/webhook_secret");')), $out);
list($newDir, $db, $secret) = array_pad(explode('|', trim(implode('', $out))), 3, '');
t('the same directory is chosen after the upgrade', $newDir, $dir);
t('the database survived', $db, 'THE CUSTOMER DATABASE');
t('and so did the webhook secret', $secret, 'the-shared-secret');

echo "\nuCRM's own pluginDataDir still wins when it gives one\n";
$ucrmDir = $sandbox . '/ucrm-managed';
@mkdir($ucrmDir, 0777, true);
$root2 = $plugins . '/other-plugin';
@mkdir($root2, 0777, true);
file_put_contents($root2 . '/ucrm.json', json_encode(['pluginDataDir' => $ucrmDir]));
$out2 = [];
exec(sprintf('php -r %s 2>/dev/null',
    escapeshellarg('require "' . dirname(__DIR__) . '/lib/bootstrap_data.php";'
                 . ' echo getDataDir("' . $root2 . '");')), $out2);
t('uCRM decides when it has an opinion', trim(implode('', $out2)), $ucrmDir);

echo "\nAn install with data still in the old place has it rescued\n";
$root3 = $plugins . '/legacy-plugin';
@mkdir($root3 . '/data', 0777, true);
file_put_contents($root3 . '/data/plugin.sqlite3', 'LEGACY DATABASE');
file_put_contents($root3 . '/data/webhook_secret', 'legacy-secret');
$out3 = [];
exec(sprintf('php -r %s 2>/dev/null',
    escapeshellarg('require "' . dirname(__DIR__) . '/lib/bootstrap_data.php";'
                 . ' $d = getDataDir("' . $root3 . '");'
                 . ' echo $d, "|", @file_get_contents($d . "/plugin.sqlite3"),'
                 . ' "|", @file_get_contents($d . "/webhook_secret");')), $out3);
list($d3, $db3, $s3) = array_pad(explode('|', trim(implode('', $out3))), 3, '');
t('it moved to the safe location', dirname($d3), $plugins);
t('the old database came with it', $db3, 'LEGACY DATABASE');
t('and the secret too', $s3, 'legacy-secret');
t('the original is left in place, not moved',
  file_get_contents($root3 . '/data/plugin.sqlite3'), 'LEGACY DATABASE');

echo "\nThe rescue never overwrites data already in the safe location\n";
file_put_contents($d3 . '/plugin.sqlite3', 'CURRENT DATABASE');
file_put_contents($root3 . '/data/plugin.sqlite3', 'STALE LEFTOVER');
$out4 = [];
exec(sprintf('php -r %s 2>/dev/null',
    escapeshellarg('require "' . dirname(__DIR__) . '/lib/bootstrap_data.php";'
                 . ' $d = getDataDir("' . $root3 . '"); echo @file_get_contents($d . "/plugin.sqlite3");')), $out4);
t('the live database is untouched', trim(implode('', $out4)), 'CURRENT DATABASE');

exec('rm -rf ' . escapeshellarg($sandbox));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
