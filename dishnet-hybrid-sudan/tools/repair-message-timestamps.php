#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * repair-message-timestamps.php — put mis-clocked messages back in order.
 *
 * sent_at used to be written with date(), so it carried whichever timezone the
 * entry point ran under: the webhook stamped inbound with Africa time (+2),
 * the CLI worker stamped replies with UTC. One conversation, two clocks --
 * transcripts rendered every AI reply two hours before the question, and the
 * model's history read the same scramble.
 *
 * created_at is the anchor: SQLite's datetime('now') default, always UTC, set
 * at the true moment of insertion. A LIVE message's sent_at should be within
 * seconds of it, so any row where sent_at runs 100-140 minutes AHEAD of
 * created_at is a +2 stamp, and snapping it back to created_at restores both
 * the truth and the order. Backfilled history (old messages synced later) has
 * sent_at far BEHIND created_at -- untouched by design.
 *
 *   --dry-run   count and show what would change     (start here)
 *   --repair    fix the rows
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';

$apply = in_array('--repair', $argv, true);
$pdo   = SqliteStore::create(getDataDir($root))->getPdo();

$COND = "(julianday(sent_at) - julianday(created_at)) * 1440 BETWEEN 100 AND 140";

$rows = $pdo->query(
    "SELECT id, conversation_id, direction, sent_at, created_at,
            substr(body, 1, 40) AS preview
       FROM wa_messages WHERE {$COND} ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

printf("%d message(s) carry a +2h stamp.\n\n", count($rows));
foreach ($rows as $r) {
    printf("  #%-4d conv %-3d %-4s sent_at %s -> %s  «%s»\n",
        $r['id'], $r['conversation_id'], $r['direction'],
        $r['sent_at'], $r['created_at'], $r['preview']);
}

if (!$rows) { echo "Nothing to repair.\n"; exit(0); }

if (!$apply) {
    echo "\nDry run. Re-run with --repair to fix these.\n";
    exit(0);
}

$pdo->beginTransaction();
$n = $pdo->exec("UPDATE wa_messages SET sent_at = created_at WHERE {$COND}");
// Conversation counters were fed the same skewed clocks.
$pdo->exec(
    "UPDATE wa_conversations SET
        last_message_at  = (SELECT MAX(sent_at) FROM wa_messages m WHERE m.conversation_id = wa_conversations.id),
        last_customer_at = (SELECT MAX(sent_at) FROM wa_messages m WHERE m.conversation_id = wa_conversations.id AND m.direction = 'in'),
        last_agent_at    = (SELECT MAX(sent_at) FROM wa_messages m WHERE m.conversation_id = wa_conversations.id AND m.direction = 'out')
      WHERE id IN (SELECT DISTINCT conversation_id FROM wa_messages)"
);
$pdo->commit();
printf("\nRepaired %d message(s) and refreshed conversation clocks.\n", $n);
