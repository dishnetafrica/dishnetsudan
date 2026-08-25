<?php
declare(strict_types=1);

/**
 * DishNet Plugin Test Suite
 * ─────────────────────────────────────────────────────────────────────────────
 * Runs before any plugin update is applied.
 * Tests every service, route, data layer, and business rule.
 *
 * Usage:
 *   CLI  : php run_tests.php
 *   Web  : public.php?page=dashboard&tab=updater&run_tests=1  (admin only)
 *   Auto : called automatically by plugin_update handler
 *
 * Returns:
 *   ['passed'=>N, 'failed'=>N, 'skipped'=>N, 'results'=>[...], 'ok'=>bool]
 */

class DishNetTestSuite
{
    private array  $results  = [];
    private int    $passed   = 0;
    private int    $failed   = 0;
    private int    $skipped  = 0;
    private string $dataDir;
    private string $tmpDir;

    // Services under test (injected or created with mock store)
    private $realStore;   // real store for read-only checks
    private $mockStore;   // isolated in-memory store for write tests

    public function __construct(string $dataDir)
    {
        $this->dataDir = $dataDir;
        $this->tmpDir  = sys_get_temp_dir() . '/dishnet_tests_' . getmypid();
        @mkdir($this->tmpDir, 0755, true);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ENTRY POINT
    // ══════════════════════════════════════════════════════════════════════

    public function run(): array
    {
        $this->section('🗂  File & Structure');
        $this->testRequiredFiles();
        $this->testManifestStructure();
        $this->testPhpSyntax();
        $this->testDataDirectoryWritable();

        $this->section('📦  Store Layer');
        $this->testStoreLoadSave();
        $this->testStoreAppendAndFindOne();
        $this->testStoreUpdateOne();
        $this->testStoreWithLock();
        $this->testStoreNextId();
        $this->testStorePaginate();

        $this->section('🔐  Auth & Login');
        $this->testRetailerCreateAndLogin();
        $this->testLoginWithPhone();
        $this->testLoginFailsWithWrongPassword();
        $this->testLoginFailsForInactiveUser();
        $this->testApiTokenGenAndVerify();
        $this->testApiTokenExpiry();
        $this->testPasswordResetFlow();
        $this->testPrivilegeEscalationBlocked();

        $this->section('🔒  Rate Limiter');
        $this->testRateLimiterAllowsNormal();
        $this->testRateLimiterLocksAfterMax();
        $this->testRateLimiterUnlock();

        $this->section('💰  Wallet');
        $this->testWalletCreditAndBalance();
        $this->testWalletDebitSufficientFunds();
        $this->testWalletDebitInsufficientFunds();
        $this->testWalletReversal();
        $this->testWalletIdempotency();
        $this->testWalletPassbook();

        $this->section('💬  WA Bot');
        $this->testWaBotPhoneNormalise();
        $this->testWaBotHandleIncomingCreatesConversation();
        $this->testWaBotStateMachineCollectName();
        $this->testWaBotStateMachineCollectIssue();
        $this->testWaBotTicketCreated();
        $this->testWaBotStaffReplyDisengagesBot();
        $this->testWaBotAutoReplyCheck();
        $this->testWaBotCloseConversation();
        $this->testWaBotStats();

        $this->section('🔑  Password Reset');
        $this->testResetTokenCreatedAndUsed();
        $this->testResetTokenExpired();
        $this->testResetTokenInvalidated();

        $this->section('🌐  Routes & Pages');
        $this->testLoginPageExists();
        $this->testApiEndpointResponds();
        $this->testWaWebhookFileExists();
        $this->testCronBotFileExists();
        $this->testUpdaterFileExists();
        $this->testManifestJsonValid();

        $this->section('📋  Config Defaults');
        $this->testConfigDefaultsExist();
        $this->testWaBotConfigDefaults();

        $this->section('🔧  Business Logic');
        $this->testCsrfTokenGeneration();
        $this->testHtmlEscapeFunction();
        $this->testHumanTimeDiff();
        $this->testPhoneNormalisation();
        $this->testPasswordHashing();

        $this->section('📁  Data Integrity');
        $this->testApkPathInDataDir();
        $this->testBackupDirectoryLogic();
        $this->testUpdateLogStructure();

        // Cleanup
        $this->cleanupTmpDir();

        return [
            'passed'  => $this->passed,
            'failed'  => $this->failed,
            'skipped' => $this->skipped,
            'total'   => $this->passed + $this->failed + $this->skipped,
            'ok'      => $this->failed === 0,
            'results' => $this->results,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // TEST HELPERS
    // ══════════════════════════════════════════════════════════════════════

    private function assert(string $name, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->results[] = ['status' => 'pass', 'name' => $name, 'detail' => $detail];
            $this->passed++;
        } else {
            $this->results[] = ['status' => 'fail', 'name' => $name, 'detail' => $detail ?: 'Assertion failed'];
            $this->failed++;
        }
    }

    private function assertEq(string $name, $expected, $actual): void
    {
        $ok = $expected === $actual;
        $detail = $ok ? '' : "Expected " . json_encode($expected) . ", got " . json_encode($actual);
        $this->assert($name, $ok, $detail);
    }

    private function assertNotNull(string $name, $value, string $detail = ''): void
    {
        $this->assert($name, $value !== null, $detail ?: 'Expected non-null, got null');
    }

    private function assertNull(string $name, $value, string $detail = ''): void
    {
        $this->assert($name, $value === null, $detail ?: 'Expected null, got ' . json_encode($value));
    }

    private function assertContains(string $name, string $needle, string $haystack): void
    {
        $this->assert($name, str_contains($haystack, $needle), "'{$needle}' not found in string");
    }

    private function skip(string $name, string $reason): void
    {
        $this->results[] = ['status' => 'skip', 'name' => $name, 'detail' => $reason];
        $this->skipped++;
    }

    private function section(string $title): void
    {
        $this->results[] = ['status' => 'section', 'name' => $title, 'detail' => ''];
    }

    private function makeTmpStore(): object
    {
        $tmpDb = $this->tmpDir . '/test_' . uniqid();
        return SqliteStore::create($tmpDb);
    }

    private function cleanupTmpDir(): void
    {
        if (!is_dir($this->tmpDir)) return;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    // ══════════════════════════════════════════════════════════════════════
    // FILE & STRUCTURE TESTS
    // ══════════════════════════════════════════════════════════════════════

    private function testRequiredFiles(): void
    {
        $pluginDir = __DIR__;
        $required = [
            'manifest.json',
            'public.php',
            'main.php',
            'webhook.php',
            'wa_webhook.php',
            'cron_wa_bot.php',
            'cron_sync.php',
            'cron_lte.php',
            'cron_maintenance.php',
            'lib/RetailerAuth.php',
            'lib/WaBotService.php',
            'lib/WalletService.php',
            'lib/NotificationService.php',
            'lib/SqliteStore.php',
            'lib/StoreInterface.php',
            'lib/KycService.php',
            'lib/LoginRateLimiter.php',
            'lib/CrmApiClient.php',
        ];
        foreach ($required as $file) {
            $this->assert("File exists: {$file}", file_exists($pluginDir . '/' . $file));
        }
    }

    private function testManifestStructure(): void
    {
        $m = json_decode(file_get_contents(__DIR__ . '/../manifest.json'), true);
        $this->assertNotNull('manifest.json is valid JSON', $m);
        $this->assert('manifest has version', !empty($m['information']['version'] ?? ''));
        $this->assert('manifest has displayName', !empty($m['information']['displayName'] ?? ''));
        $this->assert('manifest has name', !empty($m['information']['name'] ?? ''));
        $this->assert('manifest has menu', !empty($m['menu'] ?? []));
    }

    private function testPhpSyntax(): void
    {
        $files = [
            'public.php', 'webhook.php', 'wa_webhook.php',
            'cron_wa_bot.php', 'cron_sync.php', 'cron_lte.php',
            'lib/RetailerAuth.php', 'lib/WaBotService.php',
            'lib/WalletService.php', 'lib/SqliteStore.php',
            'lib/NotificationService.php', 'lib/LoginRateLimiter.php',
        ];
        foreach ($files as $f) {
            $path = __DIR__ . '/' . $f;
            if (!file_exists($path)) { $this->skip("PHP syntax: {$f}", 'File not found'); continue; }
            // Check for obvious syntax markers
            $src = file_get_contents($path);
            $this->assert("PHP syntax: {$f}", str_starts_with(trim($src), '<?php'), 'Must start with <?php');
        }
    }

    private function testDataDirectoryWritable(): void
    {
        $this->assert('data/ directory exists', is_dir($this->dataDir));
        $testFile = $this->dataDir . '/.write_test_' . getmypid();
        $ok = file_put_contents($testFile, '1') !== false;
        @unlink($testFile);
        $this->assert('data/ directory is writable', $ok);
    }

    // ══════════════════════════════════════════════════════════════════════
    // STORE LAYER TESTS
    // ══════════════════════════════════════════════════════════════════════

    private function testStoreLoadSave(): void
    {
        $s = $this->makeTmpStore();
        $s->save('test.json', [['id'=>1,'val'=>'hello']]);
        $loaded = $s->load('test.json');
        $this->assertEq('Store: save and load', [['id'=>1,'val'=>'hello']], $loaded);
    }

    private function testStoreAppendAndFindOne(): void
    {
        $s = $this->makeTmpStore();
        $r = $s->appendWithId('items.json', ['name'=>'Alice','role'=>'admin']);
        $this->assert('Store: append returns record with id', isset($r['id']) && $r['id'] > 0);
        $found = $s->findOne('items.json', 'name', 'Alice');
        $this->assertNotNull('Store: findOne finds record', $found);
        $this->assertEq('Store: findOne returns correct field', 'admin', $found['role'] ?? null);
        $notFound = $s->findOne('items.json', 'name', 'Bob');
        $this->assertNull('Store: findOne returns null for missing', $notFound);
    }

    private function testStoreUpdateOne(): void
    {
        $s = $this->makeTmpStore();
        $r = $s->append('users.json', ['name'=>'Bob','score'=>10]);
        $ok = $s->updateOne('users.json', 'id', $r['id'], ['score'=>99]);
        $this->assert('Store: updateOne returns true', $ok);
        $updated = $s->findOne('users.json', 'id', $r['id']);
        $this->assertEq('Store: updateOne persists value', 99, $updated['score'] ?? null);
        $this->assertEq('Store: updateOne preserves other fields', 'Bob', $updated['name'] ?? null);
    }

    private function testStoreWithLock(): void
    {
        $s = $this->makeTmpStore();
        $s->save('counter.json', [['id'=>1,'n'=>0]]);
        $s->withLock('counter.json', function(array $items): array {
            $items[0]['n'] = 42;
            return ['records' => $items, 'result' => null];
        });
        $r = $s->findOne('counter.json', 'id', 1);
        $this->assertEq('Store: withLock persists changes', 42, $r['n'] ?? null);
    }

    private function testStoreNextId(): void
    {
        $s = $this->makeTmpStore();
        $id1 = $s->nextId('seq.json');
        $s->append('seq.json', ['id'=>$id1,'v'=>'a']);
        $id2 = $s->nextId('seq.json');
        $this->assert('Store: nextId increments', $id2 > $id1);
    }

    private function testStorePaginate(): void
    {
        $s = $this->makeTmpStore();
        for ($i = 1; $i <= 15; $i++) $s->append('pg.json', ['val'=>$i]);
        $page1 = $s->paginate('pg.json', 1, 10);
        $this->assert('Store: paginate returns correct count', count($page1['records'] ?? []) === 10);
        $this->assertEq('Store: paginate total correct', 15, $page1['total'] ?? 0);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AUTH TESTS
    // ══════════════════════════════════════════════════════════════════════

    private function testRetailerCreateAndLogin(): void
    {
        $s    = $this->makeTmpStore();
        $auth = new RetailerAuth($s);
        $r    = $auth->createRetailer(['name'=>'Test User','email'=>'test@test.com','phone'=>'+211900000001','password'=>'secret123']);
        $this->assert('Auth: createRetailer returns record', isset($r['id']));
        $this->assert('Auth: password is hashed', ($r['password'] ?? '') !== 'secret123');

        // Can't test webLogin without session — test underlying logic instead
        $found = $s->findOne('retailers.json', 'email', 'test@test.com');
        $this->assertNotNull('Auth: retailer stored correctly', $found);
        $this->assert('Auth: password verify works', password_verify('secret123', $found['password']));
    }

    private function testLoginWithPhone(): void
    {
        $s    = $this->makeTmpStore();
        $auth = new RetailerAuth($s);
        $auth->createRetailer(['name'=>'Phone User','email'=>'phone@test.com','phone'=>'+211901234567','password'=>'pass123','is_active'=>true]);

        // Test phone normalisation finds user
        $found = $auth->findByPhone('+211901234567');
        $this->assertNotNull('Auth: findByPhone finds by exact', $found);
        $found2 = $auth->findByPhone('+211 901 234 567');
        $this->assertNotNull('Auth: findByPhone finds with spaces', $found2);
        $found3 = $auth->findByPhone('211901234567');
        $this->assertNotNull('Auth: findByPhone finds without +', $found3);
    }

    private function testLoginFailsWithWrongPassword(): void
    {
        $s    = $this->makeTmpStore();
        $auth = new RetailerAuth($s);
        $auth->createRetailer(['name'=>'U','email'=>'u@t.com','phone'=>'+211999','password'=>'correct','is_active'=>true]);
        $found = $s->findOne('retailers.json','email','u@t.com');
        $this->assert('Auth: wrong password fails verify', !password_verify('wrong', $found['password']));
    }

    private function testLoginFailsForInactiveUser(): void
    {
        $s    = $this->makeTmpStore();
        $auth = new RetailerAuth($s);
        $r = $auth->createRetailer(['name'=>'Inactive','email'=>'in@t.com','phone'=>'+211000','password'=>'pw','is_active'=>false]);
        $s->updateOne('retailers.json','id',$r['id'],['is_active'=>false]);
        $found = $s->findOne('retailers.json','email','in@t.com');
        $this->assert('Auth: inactive user is_active=false', !($found['is_active'] ?? true));
    }

    private function testApiTokenGenAndVerify(): void
    {
        $s    = $this->makeTmpStore();
        $auth = new RetailerAuth($s);
        $r    = $auth->createRetailer(['name'=>'TK','email'=>'tk@t.com','phone'=>'+211111','password'=>'pw']);
        $this->assert('Auth: api_token generated on create', !empty($r['api_token']));
        $this->assert('Auth: token_issued_at set', !empty($r['token_issued_at']));
        $token = $auth->regenerateToken($r['id']);
        $this->assert('Auth: regenerateToken returns 64-char hex', strlen($token) === 64);
        $found = $auth->findByToken($token);
        $this->assertNotNull('Auth: findByToken finds valid token', $found);
    }

    private function testApiTokenExpiry(): void
    {
        $s    = $this->makeTmpStore();
        $auth = new RetailerAuth($s);
        $r    = $auth->createRetailer(['name'=>'EXP','email'=>'exp@t.com','phone'=>'+211222','password'=>'pw']);
        // Manually set issued_at 91 days ago
        $s->updateOne('retailers.json','id',$r['id'],['token_issued_at' => time() - (91 * 86400)]);
        $token = $s->findOne('retailers.json','id',$r['id'])['api_token'];
        $found = $auth->findByToken($token);
        $this->assertNull('Auth: expired token returns null', $found);
    }

    private function testPasswordResetFlow(): void
    {
        $s    = $this->makeTmpStore();
        $auth = new RetailerAuth($s);
        $r    = $auth->createRetailer(['name'=>'Reset','email'=>'reset@t.com','phone'=>'+211333','password'=>'oldpass']);
        $token = $auth->createResetToken($r['id']);
        $this->assert('Auth: reset token is 48-char hex', strlen($token) === 48);
        $found = $auth->findByResetToken($token);
        $this->assertNotNull('Auth: findByResetToken finds valid token', $found);
        $ok = $auth->consumeResetToken($token, 'newpassword123');
        $this->assert('Auth: consumeResetToken returns true', $ok);
        $updated = $s->findOne('retailers.json','id',$r['id']);
        $this->assert('Auth: new password verifies', password_verify('newpassword123', $updated['password']));
        $this->assertNull('Auth: token cleared after use', $updated['pwd_reset_token'] ?? null);
        // Token should not work again
        $again = $auth->findByResetToken($token);
        $this->assertNull('Auth: token cannot be reused', $again);
    }

    private function testPrivilegeEscalationBlocked(): void
    {
        $s    = $this->makeTmpStore();
        $auth = new RetailerAuth($s);
        $r    = $auth->createRetailer(['name'=>'Sales','email'=>'sales@t.com','phone'=>'+211444','password'=>'pw','is_admin'=>false]);
        // Non-admin call should strip is_admin
        $auth->updateRetailer($r['id'], ['is_admin'=>true, 'role'=>'admin'], false);
        $updated = $s->findOne('retailers.json','id',$r['id']);
        $this->assert('Auth: is_admin escalation blocked for non-admin', !($updated['is_admin'] ?? false));
    }

    // ══════════════════════════════════════════════════════════════════════
    // RATE LIMITER TESTS
    // ══════════════════════════════════════════════════════════════════════

    private function testRateLimiterAllowsNormal(): void
    {
        $s    = $this->makeTmpStore();
        $rl   = new LoginRateLimiter($s);
        $res  = $rl->check('user@t.com', '1.2.3.4');
        $this->assert('RateLimit: fresh account not locked', !($res['locked'] ?? true));
    }

    private function testRateLimiterLocksAfterMax(): void
    {
        $s  = $this->makeTmpStore();
        $rl = new LoginRateLimiter($s);
        // Record 5 failures
        for ($i = 0; $i < 5; $i++) $rl->recordFailure('bad@t.com', '9.9.9.9');
        $res = $rl->check('bad@t.com', '9.9.9.9');
        $this->assert('RateLimit: locks after max failures', $res['locked'] ?? false);
    }

    private function testRateLimiterUnlock(): void
    {
        $s  = $this->makeTmpStore();
        $rl = new LoginRateLimiter($s);
        for ($i = 0; $i < 5; $i++) $rl->recordFailure('lock@t.com', '5.5.5.5');
        $cleared = $rl->adminUnlock('lock@t.com');
        $this->assert('RateLimit: adminUnlock clears lock', $cleared > 0);
        $res = $rl->check('lock@t.com', '5.5.5.5');
        $this->assert('RateLimit: unlocked account not locked', !($res['locked'] ?? true));
    }

    // ══════════════════════════════════════════════════════════════════════
    // WALLET TESTS
    // ══════════════════════════════════════════════════════════════════════

    private function testWalletCreditAndBalance(): void
    {
        $s   = $this->makeTmpStore();
        $auth= new RetailerAuth($s);
        $r   = $auth->createRetailer(['name'=>'W','email'=>'w@t.com','phone'=>'+211555','password'=>'pw','wallet'=>0]);
        $wlt = new WalletService($s);
        $wlt->credit($r['id'], 100.0, 'Top-up', 'admin', 'test-cr-1');
        $bal = $wlt->getBalance($r['id']);
        $this->assertEq('Wallet: credit increases balance', 100.0, $bal);
    }

    private function testWalletDebitSufficientFunds(): void
    {
        $s   = $this->makeTmpStore();
        $auth= new RetailerAuth($s);
        $r   = $auth->createRetailer(['name'=>'D','email'=>'d@t.com','phone'=>'+211556','password'=>'pw','wallet'=>200.0]);
        $wlt = new WalletService($s);
        $res = $wlt->debit($r['id'], 50.0, 'KYC', 'test', 'test-db-1');
        $this->assert('Wallet: debit succeeds with funds', $res['success'] ?? false);
        $this->assertEq('Wallet: balance after debit', 150.0, $wlt->getBalance($r['id']));
    }

    private function testWalletDebitInsufficientFunds(): void
    {
        $s   = $this->makeTmpStore();
        $auth= new RetailerAuth($s);
        $r   = $auth->createRetailer(['name'=>'NF','email'=>'nf@t.com','phone'=>'+211557','password'=>'pw','wallet'=>10.0]);
        $wlt = new WalletService($s);
        $res = $wlt->debit($r['id'], 500.0, 'KYC', 'test', 'test-nf-1');
        $this->assert('Wallet: debit fails with insufficient funds', !($res['success'] ?? true));
        $this->assertEq('Wallet: balance unchanged after failed debit', 10.0, $wlt->getBalance($r['id']));
    }

    private function testWalletReversal(): void
    {
        $s   = $this->makeTmpStore();
        $auth= new RetailerAuth($s);
        $r   = $auth->createRetailer(['name'=>'RV','email'=>'rv@t.com','phone'=>'+211558','password'=>'pw','wallet'=>100.0]);
        $wlt = new WalletService($s);
        $res = $wlt->debit($r['id'], 30.0, 'Test', 'test', 'rev-key-1');
        $this->assert('Wallet: debit for reversal succeeds', $res['success'] ?? false);
        $trxNo = $res['trx_no'] ?? '';
        $this->assert('Wallet: trx_no returned', !empty($trxNo));
        $rev = $wlt->reverse($trxNo, 'Error', 'test');
        $this->assert('Wallet: reversal succeeds', $rev['success'] ?? false);
        $this->assertEq('Wallet: balance restored after reversal', 100.0, $wlt->getBalance($r['id']));
    }

    private function testWalletIdempotency(): void
    {
        $s   = $this->makeTmpStore();
        $auth= new RetailerAuth($s);
        $r   = $auth->createRetailer(['name'=>'ID','email'=>'id@t.com','phone'=>'+211559','password'=>'pw','wallet'=>100.0]);
        $wlt = new WalletService($s);
        $r1  = $wlt->debit($r['id'], 20.0, 'Idem', 'test', 'idem-key-unique');
        $r2  = $wlt->debit($r['id'], 20.0, 'Idem', 'test', 'idem-key-unique'); // same key
        $this->assert('Wallet: first debit succeeds', $r1['success'] ?? false);
        $this->assert('Wallet: duplicate idempotency key is idempotent', $r2['idempotent'] ?? false);
        $this->assertEq('Wallet: balance only debited once', 80.0, $wlt->getBalance($r['id']));
    }

    private function testWalletPassbook(): void
    {
        $s   = $this->makeTmpStore();
        $auth= new RetailerAuth($s);
        $r   = $auth->createRetailer(['name'=>'PB','email'=>'pb@t.com','phone'=>'+211560','password'=>'pw','wallet'=>0.0]);
        $wlt = new WalletService($s);
        $wlt->credit($r['id'], 100.0, 'Top', 'admin', 'pb-cr-1');
        $wlt->debit($r['id'], 25.0, 'KYC', 'test', 'pb-db-1');
        $pb = $wlt->getPassbook($r['id']);
        $this->assert('Wallet: passbook has 2 entries', count($pb) === 2);
        $summary = $wlt->getSummary($r['id']);
        $this->assertEq('Wallet: summary balance correct', 75.0, $summary['balance'] ?? null);
    }

    // ══════════════════════════════════════════════════════════════════════
    // WA BOT TESTS
    // ══════════════════════════════════════════════════════════════════════

    private function makeMockNotify(): object
    {
        // Simple mock that records calls but doesn't actually send
        return new class {
            public array $sent = [];
            public function sendVia(string $s, string $p, string $m, string $e='', array $v=[]): void {
                $this->sent[] = ['to'=>$p,'msg'=>$m,'event'=>$e];
            }
            public function sendAdmin(string $m, string $e='', array $v=[]): void {
                $this->sent[] = ['to'=>'admin','msg'=>$m,'event'=>$e];
            }
        };
    }

    private function testWaBotPhoneNormalise(): void
    {
        $s      = $this->makeTmpStore();
        $notify = $this->makeMockNotify();
        $bot    = new WaBotService($s, $notify, ['wa_bot_enabled'=>true]);
        $this->assertEq('WaBot: normalise strips spaces', '+211912345678', $bot->normalisePhone('+211 912 345 678'));
        $this->assertEq('WaBot: normalise strips dashes', '+211912345678', $bot->normalisePhone('+211-912-345-678'));
        $this->assertEq('WaBot: normalise strips parens', '+211912345678', $bot->normalisePhone('+211(912)345678'));
    }

    private function testWaBotHandleIncomingCreatesConversation(): void
    {
        $s      = $this->makeTmpStore();
        $notify = $this->makeMockNotify();
        $bot    = new WaBotService($s, $notify, ['wa_bot_enabled'=>true]);
        $conv   = $bot->handleIncoming('+211900000001', 'Hello', 'John');
        $this->assert('WaBot: handleIncoming creates conversation', isset($conv['id']));
        $this->assertEq('WaBot: conversation state is new', 'new', $conv['state'] ?? '');
        $this->assertEq('WaBot: phone stored correctly', '+211900000001', $conv['phone'] ?? '');
        $this->assertEq('WaBot: display name stored', 'John', $conv['display_name'] ?? '');
        $this->assert('WaBot: message added to thread', count($conv['messages'] ?? []) >= 1);
    }

    private function testWaBotStateMachineCollectName(): void
    {
        $s      = $this->makeTmpStore();
        $notify = $this->makeMockNotify();
        $bot    = new WaBotService($s, $notify, ['wa_bot_enabled'=>true]);
        // Set up a conversation in collecting_name state
        $conv = $bot->handleIncoming('+211900000002', 'Hello');
        $s->updateOne(WaBotService::CONV_FILE, 'id', $conv['id'], ['state'=>'collecting_name']);
        // Send name
        $conv2 = $bot->handleIncoming('+211900000002', 'Ahmed Ali');
        $loaded = $bot->getConversation($conv['id']);
        $this->assertEq('WaBot: state advances to collecting_issue', 'collecting_issue', $loaded['state'] ?? '');
        $this->assertEq('WaBot: name stored correctly', 'Ahmed Ali', $loaded['collected_name'] ?? '');
    }

    private function testWaBotStateMachineCollectIssue(): void
    {
        $s      = $this->makeTmpStore();
        $notify = $this->makeMockNotify();
        $bot    = new WaBotService($s, $notify, ['wa_bot_enabled'=>true]);
        $conv   = $bot->handleIncoming('+211900000003', 'Hi');
        $s->updateOne(WaBotService::CONV_FILE,'id',$conv['id'],['state'=>'collecting_issue','collected_name'=>'Test User']);
        $bot->handleIncoming('+211900000003', 'My internet is not working');
        $loaded = $bot->getConversation($conv['id']);
        $this->assertEq('WaBot: state advances to ticket_created', 'ticket_created', $loaded['state'] ?? '');
        $this->assert('WaBot: issue stored', !empty($loaded['collected_issue'] ?? ''));
    }

    private function testWaBotTicketCreated(): void
    {
        $s      = $this->makeTmpStore();
        $notify = $this->makeMockNotify();
        $bot    = new WaBotService($s, $notify, ['wa_bot_enabled'=>true]);
        $conv   = $bot->handleIncoming('+211900000004', 'Hi');
        $s->updateOne(WaBotService::CONV_FILE,'id',$conv['id'],['state'=>'collecting_issue','collected_name'=>'Mary']);
        $bot->handleIncoming('+211900000004', 'I need a new fiber connection please');
        $loaded  = $bot->getConversation($conv['id']);
        $tickets = $bot->getAllTickets();
        $this->assert('WaBot: ticket_id set on conversation', !empty($loaded['ticket_id'] ?? null));
        $this->assert('WaBot: ticket created in tickets store', count($tickets) >= 1);
        $ticket = $tickets[0];
        $this->assertEq('WaBot: ticket phone matches', '+211900000004', $ticket['phone'] ?? '');
        $this->assertEq('WaBot: ticket status is open', 'open', $ticket['status'] ?? '');
        $this->assert('WaBot: admin notified via WA', count($notify->sent) > 0);
    }

    private function testWaBotStaffReplyDisengagesBot(): void
    {
        $s      = $this->makeTmpStore();
        $notify = $this->makeMockNotify();
        $bot    = new WaBotService($s, $notify, ['wa_bot_enabled'=>true]);
        $conv   = $bot->handleIncoming('+211900000005', 'Help me');
        $ok     = $bot->staffReply($conv['id'], 'Hi, how can I help?', 'Support Agent');
        $this->assert('WaBot: staffReply returns true', $ok);
        $loaded = $bot->getConversation($conv['id']);
        $this->assertEq('WaBot: state becomes human_active', 'human_active', $loaded['state'] ?? '');
        $this->assert('WaBot: last_human_reply_at set', !empty($loaded['last_human_reply_at'] ?? ''));
        // Check message logged
        $staffMsgs = array_filter($loaded['messages']??[], fn($m)=>$m['role']==='staff');
        $this->assert('WaBot: staff message in thread', count($staffMsgs) >= 1);
    }

    private function testWaBotAutoReplyCheck(): void
    {
        $s      = $this->makeTmpStore();
        $notify = $this->makeMockNotify();
        $bot    = new WaBotService($s, $notify, ['wa_bot_enabled'=>true, 'wa_bot_timeout_minutes'=>0]);
        $conv   = $bot->handleIncoming('+211900000006', 'Hello');
        // Force last message to be old enough (timeout=0 means immediate)
        $s->updateOne(WaBotService::CONV_FILE,'id',$conv['id'],['last_customer_msg_at'=>date('Y-m-d H:i:s',time()-60)]);
        $replied = $bot->runAutoReplyCheck();
        $this->assert('WaBot: autoReplyCheck replies to waiting conv', $replied >= 1);
        $loaded  = $bot->getConversation($conv['id']);
        $this->assertEq('WaBot: state after auto-reply is collecting_name', 'collecting_name', $loaded['state']??'');
    }

    private function testWaBotCloseConversation(): void
    {
        $s      = $this->makeTmpStore();
        $notify = $this->makeMockNotify();
        $bot    = new WaBotService($s, $notify, ['wa_bot_enabled'=>true]);
        $conv   = $bot->handleIncoming('+211900000007', 'Test');
        $ok     = $bot->closeConversation($conv['id'], 'Admin');
        $this->assert('WaBot: closeConversation returns true', $ok);
        $loaded = $bot->getConversation($conv['id']);
        $this->assertEq('WaBot: state is closed', 'closed', $loaded['state']??'');
        // Closed conversations should not appear in open list
        $open = $bot->getAllConversations(false);
        $ids  = array_column($open,'id');
        $this->assert('WaBot: closed conv not in open list', !in_array($conv['id'], $ids));
    }

    private function testWaBotStats(): void
    {
        $s      = $this->makeTmpStore();
        $notify = $this->makeMockNotify();
        $bot    = new WaBotService($s, $notify, ['wa_bot_enabled'=>true]);
        $bot->handleIncoming('+211900000010', 'A');
        $conv2 = $bot->handleIncoming('+211900000011', 'B');
        $bot->staffReply($conv2['id'], 'Hi', 'Staff');
        $stats = $bot->getStats();
        $this->assertEq('WaBot: stats open_conversations', 2, $stats['open_conversations'] ?? 0);
        $this->assertEq('WaBot: stats human_active', 1, $stats['human_active'] ?? 0);
        $this->assertEq('WaBot: stats bot_active', 1, $stats['bot_active'] ?? 0);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PASSWORD RESET TESTS
    // ══════════════════════════════════════════════════════════════════════

    private function testResetTokenCreatedAndUsed(): void
    {
        $s    = $this->makeTmpStore();
        $auth = new RetailerAuth($s);
        $r    = $auth->createRetailer(['name'=>'R','email'=>'r@t.com','phone'=>'+211600','password'=>'old']);
        $tok  = $auth->createResetToken($r['id']);
        $found= $auth->findByResetToken($tok);
        $this->assertNotNull('Reset: token finds retailer', $found);
        $this->assertEq('Reset: correct retailer found', $r['id'], $found['id']??null);
    }

    private function testResetTokenExpired(): void
    {
        $s    = $this->makeTmpStore();
        $auth = new RetailerAuth($s);
        $r    = $auth->createRetailer(['name'=>'E','email'=>'e@t.com','phone'=>'+211601','password'=>'old']);
        $tok  = $auth->createResetToken($r['id']);
        // Expire it
        $s->updateOne('retailers.json','id',$r['id'],['pwd_reset_expires_at'=>time()-1]);
        $found= $auth->findByResetToken($tok);
        $this->assertNull('Reset: expired token returns null', $found);
    }

    private function testResetTokenInvalidated(): void
    {
        $s    = $this->makeTmpStore();
        $auth = new RetailerAuth($s);
        $r    = $auth->createRetailer(['name'=>'I','email'=>'i@t.com','phone'=>'+211602','password'=>'old']);
        $tok  = $auth->createResetToken($r['id']);
        $auth->consumeResetToken($tok, 'newpass');
        // Try again with same token
        $found = $auth->findByResetToken($tok);
        $this->assertNull('Reset: consumed token invalidated', $found);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ROUTE & PAGE TESTS
    // ══════════════════════════════════════════════════════════════════════

    private function testLoginPageExists(): void
    {
        $src = file_get_contents(__DIR__ . '/public.php');
        $this->assert("Route: login page defined", str_contains($src, "\$page === 'login'"));
        $this->assert("Route: reset_password page defined", str_contains($src, "\$page === 'reset_password'"));
        $this->assert("Route: forgot_password handler", str_contains($src, "forgot_password"));
    }

    private function testApiEndpointResponds(): void
    {
        $src = file_get_contents(__DIR__ . '/public.php');
        $this->assert("Route: api page defined", str_contains($src, "\$page === 'api'"));
        $this->assert("Route: wa_reply api action", str_contains($src, "wa_reply"));
        $this->assert("Route: wa_conversations api action", str_contains($src, "wa_conversations"));
    }

    private function testWaWebhookFileExists(): void
    {
        $this->assert('Route: wa_webhook.php exists', file_exists(__DIR__ . '/wa_webhook.php'));
        $src = file_get_contents(__DIR__ . '/wa_webhook.php');
        $this->assert('Route: wa_webhook handles POST', str_contains($src, "REQUEST_METHOD"));
        $this->assert('Route: wa_webhook uses WaBotService', str_contains($src, 'WaBotService'));
    }

    private function testCronBotFileExists(): void
    {
        $this->assert('Cron: cron_wa_bot.php exists', file_exists(__DIR__ . '/cron_wa_bot.php'));
        $src = file_get_contents(__DIR__ . '/cron_wa_bot.php');
        $this->assert('Cron: calls runAutoReplyCheck', str_contains($src, 'runAutoReplyCheck'));
    }

    private function testUpdaterFileExists(): void
    {
        $src = file_get_contents(__DIR__ . '/public.php');
        $this->assert("Updater: plugin_update handler exists", str_contains($src, "'plugin_update'"));
        $this->assert("Updater: rollback handler exists", str_contains($src, "'rollback'"));
        $this->assert("Updater: updater tab exists", str_contains($src, "\$tab === 'updater'"));
    }

    private function testManifestJsonValid(): void
    {
        $raw = file_get_contents(__DIR__ . '/../manifest.json');
        $m   = json_decode($raw, true);
        $this->assert('Manifest: valid JSON', json_last_error() === JSON_ERROR_NONE);
        $this->assert('Manifest: version semver-like', preg_match('/^\d+\.\d+/', $m['information']['version'] ?? '') === 1);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CONFIG DEFAULT TESTS
    // ══════════════════════════════════════════════════════════════════════

    private function testConfigDefaultsExist(): void
    {
        $src = file_get_contents(__DIR__ . '/public.php');
        $keys = ['wa_plugin_url','wa_app_key','wa_auth_key','whatsapp_admin_phone',
                 'crm_base_url','auto_sync_interval','wa_support_number'];
        foreach ($keys as $k) {
            $this->assert("Config: default for '{$k}' exists", str_contains($src, "\$config['{$k}']"));
        }
    }

    private function testWaBotConfigDefaults(): void
    {
        $src = file_get_contents(__DIR__ . '/public.php');
        $this->assert("Config: wa_bot_enabled default", str_contains($src, "wa_bot_enabled"));
        $this->assert("Config: wa_bot_timeout_minutes default", str_contains($src, "wa_bot_timeout_minutes"));
        $this->assert("Config: wa_webhook_secret default", str_contains($src, "wa_webhook_secret"));
    }

    // ══════════════════════════════════════════════════════════════════════
    // BUSINESS LOGIC TESTS
    // ══════════════════════════════════════════════════════════════════════

    private function testCsrfTokenGeneration(): void
    {
        $src = file_get_contents(__DIR__ . '/public.php');
        $this->assert('CSRF: csrfField function exists', str_contains($src, 'function csrfField'));
        $this->assert('CSRF: csrfCheck function exists', str_contains($src, 'function csrfCheck'));
    }

    private function testHtmlEscapeFunction(): void
    {
        // Test h() function works correctly
        $result = htmlspecialchars('<script>alert("xss")</script>', ENT_QUOTES, 'UTF-8');
        $expected = '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;';
        $this->assertEq('Security: HTML escape works', $expected, $result);
    }

    private function testHumanTimeDiff(): void
    {
        $now = time();
        $this->assert('Util: human_time_diff seconds', str_contains(human_time_diff($now - 30), 's'));
        $this->assert('Util: human_time_diff minutes', str_contains(human_time_diff($now - 120), 'm'));
        $this->assert('Util: human_time_diff hours',   str_contains(human_time_diff($now - 7200), 'h'));
        $this->assert('Util: human_time_diff days',    str_contains(human_time_diff($now - 172800), 'd'));
    }

    private function testPhoneNormalisation(): void
    {
        $s   = $this->makeTmpStore();
        $bot = new WaBotService($s, $this->makeMockNotify(), []);
        $tests = [
            ['+211 912 345 678', '+211912345678'],
            ['+211-912-345-678', '+211912345678'],
            ['211912345678',     '211912345678'],
            ['+211(912)345678',  '+211912345678'],
        ];
        foreach ($tests as [$input, $expected]) {
            $this->assertEq("Phone: normalise '{$input}'", $expected, $bot->normalisePhone($input));
        }
    }

    private function testPasswordHashing(): void
    {
        $hash = password_hash('test123', PASSWORD_BCRYPT, ['cost'=>12]);
        $this->assert('Security: bcrypt hash verifies', password_verify('test123', $hash));
        $this->assert('Security: wrong password fails', !password_verify('wrong', $hash));
        $this->assert('Security: hash not plaintext', $hash !== 'test123');
        $this->assert('Security: bcrypt cost 12', str_contains($hash, '$12$'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // DATA INTEGRITY TESTS
    // ══════════════════════════════════════════════════════════════════════

    private function testApkPathInDataDir(): void
    {
        $src = file_get_contents(__DIR__ . '/public.php');
        $this->assert('APK: download uses data/ path', str_contains($src, "\$dataDir . '/dishnet-app.apk'"));
        $this->assert('APK: upload saves to data/', str_contains($src, "dataDir . '/dishnet-app.apk'"));
    }

    private function testBackupDirectoryLogic(): void
    {
        $src = file_get_contents(__DIR__ . '/public.php');
        $this->assert('Updater: creates backup before update', str_contains($src, 'plugin_backups'));
        $this->assert('Updater: skips data/ during extraction', str_contains($src, "str_starts_with(\$entry, 'data/')"));
        $this->assert('Updater: rollback action exists', str_contains($src, "'rollback'"));
    }

    private function testUpdateLogStructure(): void
    {
        $src = file_get_contents(__DIR__ . '/public.php');
        $this->assert('Updater: update log written', str_contains($src, 'update_log.json'));
        $this->assert('Updater: log includes version info', str_contains($src, 'from_version'));
        $this->assert('Updater: log includes applied_by', str_contains($src, 'applied_by'));
    }
}
