<?php
/**
 * Migration 031 — Seed LTE data from BlueCard export
 *
 * Copies lte_*.json seed files from data/seed/ into the plugin's
 * persistent data directory (SqliteStore).
 *
 * Only runs if the target files don't already exist (or are empty).
 * Safe to re-run — never overwrites existing data.
 */

$seedDir = __DIR__ . '/../data/seed';
$files   = [
    'lte_packages.json',
    'lte_sims.json',
    'lte_subscribers.json',
    'lte_subscriptions.json',
    'lte_renewals.json',
];

$seeded  = 0;
$skipped = 0;
$errors  = [];

foreach ($files as $file) {
    $src = $seedDir . '/' . $file;

    if (!file_exists($src)) {
        $skipped++;
        continue;
    }

    // Check if data already exists in the store
    try {
        $existing = $store->load($file);
        if (!empty($existing)) {
            $skipped++;
            continue; // Already has data — don't overwrite
        }
    } catch (\Throwable $e) {
        // Table doesn't exist yet — that's fine, we'll seed it
    }

    // Load seed data and write to store
    try {
        $data = json_decode(file_get_contents($src), true);
        if (!is_array($data)) {
            $errors[] = "$file: invalid JSON in seed file";
            continue;
        }
        $store->save($file, $data);
        $seeded++;
        error_log("[031_lte_seed] Seeded $file: " . count($data) . " records");
    } catch (\Throwable $e) {
        $errors[] = "$file: " . $e->getMessage();
    }
}

if (!empty($errors)) {
    error_log("[031_lte_seed] Errors: " . implode(', ', $errors));
}

error_log("[031_lte_seed] Done — seeded=$seeded, skipped=$skipped");
