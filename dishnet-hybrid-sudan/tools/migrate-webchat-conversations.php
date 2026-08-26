#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * migrate-webchat-conversations.php — move website chats into the conversation
 * system so they appear in the Inbox beside WhatsApp.
 *
 * Website chats were stored as JSON blobs in web_chat_sessions, which no screen
 * could read. This copies them into wa_conversations / wa_messages with
 * channel = 'web', where the Inbox already knows how to show them.
 *
 * It COPIES. web_chat_sessions is never written to and never deleted -- it stays
 * as the rollback path and as proof of what was migrated. Retiring it is a
 * separate decision for later.
 *
 *   --dry-run    report what would happen, change nothing        (start here)
 *   --migrate    take a backup, then copy
 *   --verify     compare the two stores and report any drift
 *   --rollback   delete ONLY the conversations this created
 *
 * Every mode is safe to re-run. Migration is keyed on phone = web:<session>,
 * so a second run adopts what already exists rather than duplicating it.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';

$mode = 'dry-run';
foreach ($argv as $a) {
    foreach (['dry-run', 'migrate', 'verify', 'rollback'] as $m) {
        if ($a === '--' . $m) $mode = $m;
    }
}

$dataDir = getDataDir($root);
$store   = SqliteStore::create($dataDir);
$pdo     = $store->getPdo();
$svc     = new ConversationService($dataDir, $pdo);

/** Sessions as they exist in the old store, with any lead attached. */
function oldSessions($store): array
{
    $leads = [];
    try {
        foreach ($store->load('web_chat_leads.json') as $l) {
            $leads[(string)($l['session'] ?? '')] = $l;
        }
    } catch (\Throwable $e) { /* leads are optional */ }

    $out = [];
    try {
        foreach ($store->load('web_chat_sessions.json') as $row) {
            $sid = (string)($row['session'] ?? '');
            if ($sid === '') continue;
            $turns = json_decode((string)($row['turns'] ?? '[]'), true);
            if (!is_array($turns)) $turns = [];
            $out[$sid] = [
                'session' => $sid,
                'turns'   => $turns,
                'updated' => (string)($row['updated'] ?? ''),
                'lead'    => $leads[$sid] ?? null,
            ];
        }
    } catch (\Throwable $e) { /* nothing to migrate */ }
    return $out;
}

function backup(string $dataDir): string
{
    $src = $dataDir . '/plugin.sqlite3';
    if (!is_file($src)) throw new RuntimeException("no database at {$src}");
    // NOT beside the database. The first backup this wrote lived in the same
    // directory as the file it was protecting, and an upgrade took both. One
    // level up is the location that demonstrably survives.
    $parent = dirname(rtrim($dataDir, '/'));
    $where  = (is_dir($parent) && is_writable($parent)) ? $parent : $dataDir;
    $dst = $where . '/plugin.sqlite3.pre-webchat-migration.' . gmdate('Ymd-His');
    if (!copy($src, $dst)) throw new RuntimeException('backup copy failed');
    @chmod($dst, 0600);
    return $dst;
}

$sessions = oldSessions($store);
printf("web_chat_sessions holds %d conversation(s).\n", count($sessions));

// ── rollback ──────────────────────────────────────────────────────────────
if ($mode === 'rollback') {
    // Scoped to channel = 'web' AND a web: phone. A WhatsApp row cannot match
    // either condition, let alone both.
    $ids = $pdo->query("SELECT id FROM wa_conversations WHERE channel = 'web' AND phone LIKE 'web:%'")
               ->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) { echo "Nothing to roll back.\n"; exit(0); }
    $pdo->beginTransaction();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM wa_messages WHERE conversation_id IN ({$in})")->execute($ids);
    $pdo->prepare("DELETE FROM wa_conversations WHERE id IN ({$in})")->execute($ids);
    $pdo->commit();
    printf("Rolled back %d web conversation(s). web_chat_sessions is untouched.\n", count($ids));
    exit(0);
}

// ── verify ────────────────────────────────────────────────────────────────
if ($mode === 'verify') {
    $bad = 0;
    foreach ($sessions as $sid => $s) {
        $found = $svc->findByPhone('web:' . $sid, 'web');
        $conv  = $found[0] ?? null;
        if (!$conv) {
            printf("  MISSING  %s (%d turn[s] in the old store)\n", $sid, count($s['turns']));
            $bad++; continue;
        }
        $msgs = $svc->getMessages((int)$conv['id'], 1000, 0);
        $want = array_values(array_filter(array_map(
            function ($t) { return trim((string)($t['text'] ?? '')); }, $s['turns'])));
        $got  = array_values(array_map(
            function ($m) { return trim((string)($m['body'] ?? '')); }, $msgs));
        if (count($want) !== count($got)) {
            printf("  COUNT    %s: old %d, new %d\n", $sid, count($want), count($got));
            $bad++; continue;
        }
        foreach ($want as $i => $line) {
            if ($line !== $got[$i]) {
                printf("  TEXT     %s turn %d differs\n", $sid, $i + 1);
                $bad++; break;
            }
        }
    }
    $wa = (int)$pdo->query("SELECT COUNT(*) FROM wa_conversations WHERE channel <> 'web'")->fetchColumn();
    printf("\nWhatsApp conversations present and untouched: %d\n", $wa);
    echo $bad === 0
        ? "VERIFY OK — every conversation matches.\n"
        : sprintf("VERIFY FAILED — %d problem(s).\n", $bad);
    exit($bad === 0 ? 0 : 1);
}

// ── dry-run / migrate ─────────────────────────────────────────────────────
if ($mode === 'migrate') {
    $path = backup($dataDir);
    printf("Backup written: %s\n", $path);
    printf("Roll back with: --rollback   (or restore that file)\n\n");
} else {
    echo "DRY RUN — nothing will be written.\n\n";
}

$created = $skipped = $messages = 0;
foreach ($sessions as $sid => $s) {
    $phone = 'web:' . $sid;
    $lead  = $s['lead'];
    $name  = trim((string)($lead['name'] ?? '')) ?: 'Website visitor';

    if ($svc->findByPhone($phone, 'web')) {
        $skipped++;                       // adopted, not duplicated
        continue;
    }
    printf("  %-40s %-22s %d turn(s)%s\n", $phone, $name, count($s['turns']),
           $lead ? '  [has contact details]' : '');
    $created++;
    $messages += count($s['turns']);

    if ($mode !== 'migrate') continue;

    $conv = $svc->ensureConversation($phone, 'web', $name, 'web_chat');
    $cid  = (int)$conv['id'];

    // The contact number belongs on the conversation, but not as its key: the
    // session is what identifies a visitor across messages.
    $contact = trim((string)($lead['phone'] ?? ''));
    if ($contact !== '') {
        $pdo->prepare('UPDATE wa_conversations SET tags = ? WHERE id = ?')
            ->execute([json_encode(['contact' => $contact]), $cid]);
    }

    foreach ($s['turns'] as $t) {
        $text = trim((string)($t['text'] ?? ''));
        if ($text === '') continue;
        $isCustomer = ($t['role'] ?? 'customer') === 'customer';
        $svc->storeMessage($cid, [
            'direction'  => $isCustomer ? 'in' : 'out',
            'role'       => $isCustomer ? 'customer' : 'assistant',
            'body'       => $text,
            'agent_name' => $isCustomer ? null : 'DishNet AI',
            'metadata'   => json_encode(['channel' => 'web', 'migrated' => true]),
        ]);
    }
    // The old store kept one timestamp only, so it is the best we can say.
    if ($s['updated'] !== '') {
        $pdo->prepare('UPDATE wa_conversations SET last_message_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$s['updated'], $s['updated'], $cid]);
    }
}

printf("\n%s: %d conversation(s), %d message(s). %d already present.\n",
       $mode === 'migrate' ? 'Migrated' : 'Would migrate', $created, $messages, $skipped);
if ($mode === 'migrate') {
    echo "web_chat_sessions was NOT modified. Run --verify next.\n";
}
