<?php
require_once dirname(__DIR__) . '/lib/EvolutionApiService.php';

$pass=0; $fail=0;
function t(string $n,$got,$want){ global $pass,$fail;
  if($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

/** Feed a canned fetchInstances payload without touching the network. */
class FakeEvo extends EvolutionApiService {
    public array $payload = [];
    public function fetchInstances(): array { return ['ok'=>true,'http'=>200,'data'=>$this->payload,'error'=>'']; }
}
$e = new FakeEvo(['evo_api_url'=>'https://x','evo_api_key'=>'k']);

echo "Flat shape (v2.3 fetchInstances)\n";
$e->payload = [[
  'name'=>'dishnet_sales','connectionStatus'=>'open',
  'ownerJid'=>'211924332000@s.whatsapp.net','profileName'=>'Star Link',
]];
$r = $e->listInstances();
t('one instance',      count($r), 1);
t('name',              $r[0]['name'], 'dishnet_sales');
t('connected',         $r[0]['connected'], true);
t('phone from jid',    $r[0]['phone'], '211924332000');
t('profile name',      $r[0]['profile'], 'Star Link');

echo "\nNested shape (older builds wrap in \"instance\")\n";
$e->payload = [['instance'=>[
  'instanceName'=>'dishnet_support','state'=>'connecting','owner'=>'249111222333@s.whatsapp.net',
]]];
$r = $e->listInstances();
t('name from instanceName', $r[0]['name'], 'dishnet_support');
t('state from state',       $r[0]['state'], 'connecting');
t('not connected',          $r[0]['connected'], false);
t('phone from owner',       $r[0]['phone'], '249111222333');

echo "\n\"connected\" is treated the same as \"open\"\n";
$e->payload = [['name'=>'a','connectionStatus'=>'connected']];
t('connected == open', $e->listInstances()[0]['connected'], true);
$e->payload = [['name'=>'a','connectionStatus'=>'close']];
t('close is not connected', $e->listInstances()[0]['connected'], false);

echo "\nRubbish in, nothing out\n";
$e->payload = [];                       t('empty list',        $e->listInstances(), []);
$e->payload = ['nonsense', 42];         t('non-array rows skipped', $e->listInstances(), []);
$e->payload = [['connectionStatus'=>'open']]; t('nameless row skipped',  $e->listInstances(), []);

echo "\nSorted by name so the UI is stable\n";
$e->payload = [['name'=>'zebra'],['name'=>'alpha'],['name'=>'mike']];
t('alphabetical', array_column($e->listInstances(),'name'), ['alpha','mike','zebra']);

printf("\n%d passed, %d failed\n",$pass,$fail);
exit($fail===0?0:1);
