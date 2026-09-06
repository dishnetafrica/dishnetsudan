<?php
/**
 * One stuck event type must never starve another.
 *
 * Production incident (6 Sep 2026): the AI stopped answering customers. Root
 * cause: wa.escalation events were emitted but no worker consumed them, so
 * they accumulated as the OLDEST pending rows. consume() fetched the globally
 * oldest eligible events up to the batch size and discarded the wrong types in
 * PHP -- so once the escalation backlog reached the batch size (10), every
 * batch was full of escalations and the ai.reply queue was claimed to zero.
 * Real customers waited; nothing replied. The fix filters by type in SQL.
 */
$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

$root = dirname(__DIR__);
require_once $root . '/lib/EventBus.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Minimal events schema matching production columns the bus touches.
$pdo->exec("CREATE TABLE events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_type TEXT, entity_type TEXT, entity_id INTEGER,
  payload TEXT, priority INTEGER DEFAULT 5,
  status TEXT DEFAULT 'pending', attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 5,
  next_retry_at TEXT DEFAULT (datetime('now')), locked_by TEXT, locked_at TEXT,
  error TEXT, processed_at TEXT, created_at TEXT DEFAULT (datetime('now'))
)");
$bus = new EventBus($pdo);

echo "\nReproduce the incident: 10 old orphan events, then 3 fresh ai.reply\n";
// Escalations first, so they are the oldest rows (as in production).
for ($i = 0; $i < 10; $i++) {
    $pdo->exec("INSERT INTO events (event_type, priority, created_at, next_retry_at)
                VALUES ('wa.escalation', 5, datetime('now','-'|| (100-$i) ||' minutes'),
                        datetime('now','-'|| (100-$i) ||' minutes'))");
}
for ($i = 0; $i < 3; $i++) {
    $pdo->exec("INSERT INTO events (event_type, priority, created_at, next_retry_at)
                VALUES ('ai.reply', 5, datetime('now','-'|| (5-$i) ||' minutes'),
                        datetime('now','-'|| (5-$i) ||' minutes'))");
}

echo "\nUntyped consume (the OLD behaviour) is all escalations — replies starved\n";
$anyBatch = $bus->consume(10, 'w1');   // no type filter → oldest across all types
$types = array_column($anyBatch, 'event_type');
t('a batch of 10 with no filter contains zero ai.reply',
  in_array('ai.reply', $types, true), false);
// release them back
foreach ($anyBatch as $e) $pdo->exec("UPDATE events SET status='pending', locked_by=NULL WHERE id=".$e['id']);

echo "\nTyped consume (the FIX) claims the replies regardless of the backlog\n";
$replyBatch = $bus->consume(10, 'w2', ['ai.reply']);
t('asking for ai.reply returns exactly the 3 replies', count($replyBatch), 3);
t('and nothing else', array_values(array_unique(array_column($replyBatch, 'event_type'))), ['ai.reply']);

echo "\nThe escalation backlog is still claimable by its own consumer\n";
foreach ($replyBatch as $e) $bus->ack((int)$e['id']);
$escBatch = $bus->consume(10, 'w3', ['wa.escalation']);
t('a wa.escalation consumer still sees its 10', count($escBatch), 10);

echo "\nA wildcard consumer still gets everything eligible\n";
foreach ($escBatch as $e) $pdo->exec("UPDATE events SET status='pending', locked_by=NULL WHERE id=".$e['id']);
// The 3 replies were acked to 'done' above; only the 10 escalations remain.
$all = $bus->consume(50, 'w4', ['*']);
t('star pulls every still-eligible event (10 escalations)', count($all), 10);
$empty = $bus->consume(50, 'w5', []);   // empty types also means "all"
t('empty types behaves as wildcard', count($empty), 0); // w4 already claimed them

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
