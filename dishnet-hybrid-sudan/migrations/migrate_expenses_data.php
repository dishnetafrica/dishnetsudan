<?php
/**
 * Import cash_expenses.json → staff_expenses SQLite
 * One-time migration for Phase 3 accounting consolidation.
 * Run via: ?page=api&action=migrate_expenses
 * Safe to run multiple times — skips already-imported rows via legacy_json_id.
 */

function migrateFieldExpenses($store, $dataDir): array
{
    $pdo = $store->getPdo();

    // Run migration if columns don't exist yet
    try {
        $pdo->query("SELECT source FROM staff_expenses LIMIT 1");
    } catch (\Throwable $e) {
        $migFile = __DIR__ . '/migrations/043_unified_expenses.sql';
        if (file_exists($migFile)) {
            $sql = file_get_contents($migFile);
            foreach (explode(';', $sql) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt && strpos($stmt, '--') !== 0) {
                    try { $pdo->exec($stmt); } catch (\Throwable $e) { /* column may already exist */ }
                }
            }
        }
    }

    $allExps = $store->load('cash_expenses.json') ?? [];
    if (empty($allExps)) return ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'message' => 'No field expenses to import.'];

    $imported = 0;
    $skipped  = 0;
    $errors   = 0;

    $checkStmt = $pdo->prepare("SELECT id FROM staff_expenses WHERE legacy_json_id = ? AND source = 'field' LIMIT 1");

    $insertStmt = $pdo->prepare("INSERT INTO staff_expenses (
        source, legacy_json_id, staff_id, staff_name, project,
        category, amount, ssp_amount, currency, description, expense_date,
        receipt_path, receipt_hash, offline_uuid, submitted_via,
        status, reviewed_by, reviewed_at, reject_reason,
        is_staff_payment, auto_approved, approved_by, approved_at,
        voided_by, voided_at, void_reason, prev_status,
        staff_payment_to_id, staff_payment_to_name, staff_payment_type,
        cb_ref, cashbook_entry_id,
        submitted_at, created_at, updated_at
    ) VALUES (
        'field', ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?,
        ?, ?, ?
    )");

    $pdo->beginTransaction();
    try {
        foreach ($allExps as $e) {
            $jsonId = (int)($e['id'] ?? 0);
            if ($jsonId <= 0) { $skipped++; continue; }

            // Skip if already imported
            $checkStmt->execute([$jsonId]);
            if ($checkStmt->fetchColumn()) { $skipped++; continue; }

            $cur = strtoupper($e['currency'] ?? 'USD');
            $status = $e['status'] ?? 'pending';

            try {
                $insertStmt->execute([
                    $jsonId,                                                           // legacy_json_id
                    (int)($e['collector_id'] ?? 0),                                    // staff_id
                    $e['collector_name'] ?? $e['staff_name'] ?? '',                     // staff_name
                    $e['project'] ?? 'dishnet',                                        // project
                    $e['category'] ?? $e['expense_type'] ?? 'Other',                   // category
                    round((float)($e['amount'] ?? 0), 2),                              // amount
                    round((float)($e['ssp_amount'] ?? 0), 2),                          // ssp_amount
                    $cur,                                                              // currency
                    $e['description'] ?? $e['note'] ?? '',                              // description
                    substr($e['submitted_at'] ?? $e['created_at'] ?? date('Y-m-d'), 0, 10), // expense_date
                    $e['photo'] ?? $e['receipt_path'] ?? '',                            // receipt_path
                    '',                                                                // receipt_hash
                    '',                                                                // offline_uuid
                    'web',                                                             // submitted_via
                    $status,                                                           // status
                    $e['approved_by'] ?? $e['voided_by'] ?? '',                         // reviewed_by
                    $e['approved_at'] ?? $e['voided_at'] ?? null,                       // reviewed_at
                    $e['reject_reason'] ?? '',                                         // reject_reason
                    !empty($e['is_staff_payment']) ? 1 : 0,                            // is_staff_payment
                    !empty($e['auto_approved']) ? 1 : 0,                               // auto_approved
                    $e['approved_by'] ?? '',                                            // approved_by
                    $e['approved_at'] ?? null,                                         // approved_at
                    $e['voided_by'] ?? '',                                             // voided_by
                    $e['voided_at'] ?? null,                                           // voided_at
                    $e['void_reason'] ?? '',                                           // void_reason
                    $e['prev_status'] ?? '',                                           // prev_status
                    (int)($e['staff_payment_to_id'] ?? 0),                             // staff_payment_to_id
                    $e['staff_payment_to_name'] ?? $e['staff_name'] ?? '',              // staff_payment_to_name
                    $e['staff_payment_type'] ?? '',                                    // staff_payment_type
                    $e['cb_ref'] ?? '',                                                // cb_ref
                    (int)($e['cashbook_entry_id'] ?? 0),                               // cashbook_entry_id
                    $e['submitted_at'] ?? $e['created_at'] ?? date('Y-m-d H:i:s'),     // submitted_at
                    $e['created_at'] ?? $e['submitted_at'] ?? date('Y-m-d H:i:s'),     // created_at
                    $e['updated_at'] ?? $e['submitted_at'] ?? date('Y-m-d H:i:s'),     // updated_at
                ]);
                $imported++;
            } catch (\Throwable $ex) {
                $errors++;
            }
        }
        $pdo->commit();
    } catch (\Throwable $ex) {
        $pdo->rollBack();
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors, 'message' => 'Transaction failed: ' . $ex->getMessage()];
    }

    return [
        'imported' => $imported,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'total_json' => count($allExps),
        'total_sqlite' => (int)$pdo->query("SELECT COUNT(*) FROM staff_expenses")->fetchColumn(),
        'message'  => "Imported {$imported}, skipped {$skipped} (already exists), errors {$errors}.",
    ];
}
