#!/usr/bin/env php
<?php
// Note: No strict_types — included from master.php

/**
 * cron/web_chat_retention.php — delete website-chat personal data past its date.
 *
 * The chat collects a phone number and an email from people who asked a
 * question. Keeping those indefinitely is a policy failure rather than a
 * storage one, and privacy.html now states a period, so something has to
 * enforce it. Runs daily; deleting nothing is the normal outcome.
 *
 * A lead and its transcript go together: removing the contact detail while
 * keeping the conversation that names them would defeat the point.
 *
 * Period: web_chat_retention_days, default 90. Set it to 0 to keep everything,
 * which is a decision someone has to make deliberately rather than by omission.
 *
 * Self-executing on include, and it builds its own store — master.php prefixes
 * its variables precisely because included scripts clobber $store and $config.
 */

$_wcr_root = dirname(__DIR__);
require_once $_wcr_root . '/lib/bootstrap_data.php';
require_once $_wcr_root . '/lib/StoreInterface.php';
require_once $_wcr_root . '/lib/SqliteStore.php';
require_once $_wcr_root . '/lib/PluginConfig.php';
require_once $_wcr_root . '/lib/WebChatGuard.php';

$_wcr_data   = getDataDir($_wcr_root);
$_wcr_config = PluginConfig::load($_wcr_root, $_wcr_data);
$_wcr_store  = SqliteStore::create($_wcr_data);

$_wcr_days = (int)($_wcr_config['web_chat_retention_days'] ?? WebChatGuard::RETENTION_DAYS);

if ($_wcr_days <= 0) {
    echo "web_chat_retention: disabled (web_chat_retention_days = 0)\n";
} else {
    try {
        list($_wcr_leads, $_wcr_sessions) =
            (new WebChatGuard($_wcr_store, $_wcr_config))->prune($_wcr_days);
        if ($_wcr_leads || $_wcr_sessions) {
            $line = sprintf(
                "web_chat_retention: deleted %d lead(s) and %d transcript(s) older than %d days\n",
                $_wcr_leads, $_wcr_sessions, $_wcr_days);
            echo $line;
            // Deletions of personal data are worth a durable record, not just
            // whatever the cron output happens to be kept.
            @file_put_contents($_wcr_data . '/ai_platform.log',
                '[' . gmdate('c') . '] ' . $line, FILE_APPEND);
        }
    } catch (\Throwable $e) {
        echo 'web_chat_retention: FAILED — ' . $e->getMessage() . "\n";
    }
}

unset($_wcr_root, $_wcr_data, $_wcr_config, $_wcr_store, $_wcr_days);
