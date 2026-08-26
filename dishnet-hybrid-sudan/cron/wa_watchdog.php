#!/usr/bin/env php
<?php
// Note: No strict_types — included from master.php

/**
 * cron/wa_watchdog.php — a customer is waiting and nobody has answered.
 *
 * Ported from the South Sudan bot's 15-minute watchdog. Covers BOTH channels
 * (WhatsApp and website chat live in the same tables), runs inside business
 * hours only, and alerts once per waiting message -- a customer who writes
 * again resets the clock and earns a fresh alert.
 *
 * This is the guard against the failure this install actually had: the queue
 * stopped draining, eleven messages piled up over hours, and the only way
 * anyone found out was by running SQL. Whatever breaks next -- webhook,
 * scheduler, provider -- the symptom is always the same, an unanswered
 * customer, and that is the thing watched.
 */

$_wd_root = dirname(__DIR__);
require_once $_wd_root . '/lib/bootstrap_data.php';
require_once $_wd_root . '/lib/StoreInterface.php';
require_once $_wd_root . '/lib/SqliteStore.php';
require_once $_wd_root . '/lib/PluginConfig.php';
require_once $_wd_root . '/lib/AlertService.php';

$_wd_data   = getDataDir($_wd_root);
$_wd_store  = SqliteStore::create($_wd_data);
$_wd_config = PluginConfig::load($_wd_root, $_wd_data);
try {
    foreach (($_wd_store->load('kyc_config.json') ?: []) as $k => $v) {
        if ($v === null || $v === '') continue;
        if (!array_key_exists($k, $_wd_config) || $_wd_config[$k] === '' || $_wd_config[$k] === null) {
            $_wd_config[$k] = $v;
        }
    }
} catch (\Throwable $e) { /* files alone */ }

$_wd_alerts = new AlertService($_wd_store, $_wd_config);

if ($_wd_alerts->target() === '') {
    echo "wa_watchdog: no alert number set — watching nothing\n";
} else {
    // Sudan is UTC+2 (CAT). Waking someone at 03:00 for a message that can
    // wait until morning teaches them to mute the alerts.
    $_wd_hour  = (int)gmdate('G') + 2;
    if ($_wd_hour >= 24) $_wd_hour -= 24;
    $_wd_from  = max(0, min(23, (int)($_wd_config['alert_hours_from'] ?? 7)));
    $_wd_to    = max(1, min(24, (int)($_wd_config['alert_hours_to'] ?? 21)));

    if ($_wd_hour < $_wd_from || $_wd_hour >= $_wd_to) {
        echo "wa_watchdog: outside alert hours ({$_wd_from}-{$_wd_to}, now {$_wd_hour})\n";
    } else {
        $_wd_patience = (int)($_wd_config['alert_patience_minutes'] ?? 10);

        $_wd_rows = $_wd_store->getPdo()->query(
            "SELECT id, channel, phone, display_name, last_customer_at, last_agent_at
               FROM wa_conversations WHERE status = 'active'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $_wd_seen = [];
        try {
            foreach ($_wd_store->load('wa_watchdog_state.json') as $r) {
                $_wd_seen[(int)($r['conversation_id'] ?? 0)] = (string)($r['alerted_for'] ?? '');
            }
        } catch (\Throwable $e) { /* first run */ }

        $_wd_waiting = AlertService::findUnanswered($_wd_rows, $_wd_seen, time(), $_wd_patience);

        foreach ($_wd_waiting as $c) {
            $mins  = (int)floor((time() - strtotime((string)$c['last_customer_at'])) / 60);
            $who   = trim((string)($c['display_name'] ?? '')) ?: (string)$c['phone'];
            $where = ($c['channel'] ?? '') === 'web' ? 'website chat' : 'WhatsApp (' . $c['channel'] . ')';
            $r = $_wd_alerts->notify(
                'watchdog:' . (int)$c['id'] . ':' . $c['last_customer_at'],
                "⚠️ DishNet: {$who} has been waiting {$mins} min on {$where} with no reply. "
                . "Open Engage → WhatsApp → Inbox.",
                0   // the key embeds the message time, so it is naturally once-per-message
            );
            if (!empty($r['sent'])) {
                echo "wa_watchdog: alerted for conv {$c['id']} ({$who}, {$mins} min)\n";
                $_wd_seen[(int)$c['id']] = (string)$c['last_customer_at'];
            } else {
                echo "wa_watchdog: alert for conv {$c['id']} not sent — {$r['reason']}\n";
            }
        }

        $_wd_out = [];
        foreach ($_wd_seen as $id => $for) $_wd_out[] = ['conversation_id' => $id, 'alerted_for' => $for];
        $_wd_store->save('wa_watchdog_state.json', $_wd_out);

        if (!$_wd_waiting) echo "wa_watchdog: nobody waiting\n";
    }
}

unset($_wd_root, $_wd_data, $_wd_store, $_wd_config, $_wd_alerts, $_wd_rows,
      $_wd_seen, $_wd_waiting, $_wd_out, $_wd_hour, $_wd_from, $_wd_to, $_wd_patience);
