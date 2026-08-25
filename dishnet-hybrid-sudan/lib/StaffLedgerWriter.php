<?php
declare(strict_types=1);
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

/**
 * StaffLedgerWriter — Dual-write helper for staff_ledger integration.
 *
 * DishNet Hybrid v4.11.3
 *
 * Each public method is called from exactly ONE write point. If the ledger
 * write fails, it logs the error but NEVER blocks the primary write path.
 * The nightly backfill_staff_ledger.php will catch any missed entries.
 *
 * Pattern at each call site:
 *   require_once __DIR__ . '/../lib/StaffLedgerWriter.php';
 *   StaffLedgerWriter::onCollection($pdo, $collectionRecord);
 *
 * PHP 7.4 compatible. Zero impact if staff_ledger table doesn't exist yet.
 */
class StaffLedgerWriter
{
    /**
     * Called after a payment_collections.json append.
     * Write points: KycService::process(), post_sales collect_payment, cron_crm_payment_reconcile
     */
    public static function onCollection(\PDO $pdo, array $col): void
    {
        self::safe($pdo, function ($ledger) use ($col) {
            $id = (int)($col['id'] ?? 0);
            if ($id <= 0) return;
            $currency = strtoupper($col['currency'] ?? 'USD');
            $ledger->record([
                'staff_id'          => (int)($col['retailer_id'] ?? 0),
                'staff_name'        => (string)($col['retailer_name'] ?? $col['collector_name'] ?? ''),
                'direction'         => 'in',
                'currency'          => $currency,
                'amount'            => round((float)($col['amount'] ?? 0), 2),
                'ssp_amount'        => $currency === 'SSP' ? round((float)($col['amount'] ?? 0), 2) : 0,
                'ssp_rate'          => round((float)($col['ssp_rate'] ?? 0), 4),
                'category'          => 'collection',
                'description'       => 'Collection #' . $id,
                'status'            => 'active',
                'source_type'       => 'payment_collections',
                'source_id'         => (string)$id,
                'idempotency_key'   => 'COL-' . $id,
                'counterparty_name' => (string)($col['client_name'] ?? ''),
                'crm_payment_id'    => (int)($col['crm_payment_id'] ?? 0),
                'crm_client_id'     => (int)($col['crm_client_id'] ?? $col['client_id'] ?? 0),
                'event_date'        => substr($col['collected_at'] ?? $col['created_at'] ?? date('Y-m-d'), 0, 10),
            ]);
        });
    }

    /**
     * Called after collection voided (payment.delete webhook or manual void).
     * Write points: webhook.php payment.delete, staff_cashbooks void_collection
     */
    public static function onCollectionVoided(\PDO $pdo, int $collectionId, string $voidedBy = '', string $reason = ''): void
    {
        self::safe($pdo, function ($ledger) use ($collectionId, $voidedBy, $reason) {
            $ledger->voidByKey('COL-' . $collectionId, $voidedBy, $reason);
        });
    }

    /**
     * Called after collection voided by CRM payment ID.
     * Write point: webhook.php payment.delete
     */
    public static function onCrmPaymentDeleted(\PDO $pdo, int $crmPaymentId, string $reason = ''): void
    {
        self::safe($pdo, function ($ledger) use ($crmPaymentId, $reason) {
            $ledger->voidByCrmPayment($crmPaymentId, 'webhook', $reason);
        });
    }

    /**
     * Called after cash_advances INSERT (advance issued).
     * Write point: ExpenseAdvanceService::createAdvance()
     */
    public static function onAdvanceIssued(\PDO $pdo, array $adv): void
    {
        self::safe($pdo, function ($ledger) use ($adv) {
            $id = (int)($adv['id'] ?? 0);
            if ($id <= 0) return;
            $currency  = strtoupper($adv['currency'] ?? 'USD');
            $amount    = round((float)($adv['amount'] ?? 0), 2);
            // SSP advances: ssp_amount must be set — balance() reads ssp_amount for SSP currency
            $sspAmount = $currency === 'SSP' ? $amount : round((float)($adv['ssp_amount'] ?? 0), 2);
            $sspRate   = round((float)($adv['ssp_rate'] ?? 0), 4);
            $ledger->record([
                'staff_id'          => (int)($adv['recipient_id'] ?? 0),
                'staff_name'        => (string)($adv['recipient_name'] ?? ''),
                'direction'         => 'in',
                'currency'          => $currency,
                'amount'            => $amount,
                'ssp_amount'        => $sspAmount,
                'ssp_rate'          => $sspRate,
                'category'          => 'advance',
                'subcategory'       => (string)($adv['purpose'] ?? 'misc'),
                'description'       => 'Advance ' . ($adv['advance_no'] ?? '#' . $id),
                'status'            => 'active',
                'source_type'       => 'cash_advances',
                'source_id'         => (string)$id,
                'idempotency_key'   => 'ADV-' . $id,
                'counterparty_id'   => (int)($adv['issued_by_id'] ?? 0),
                'counterparty_name' => (string)($adv['issued_by_name'] ?? ''),
                'event_date'        => substr($adv['issued_at'] ?? date('Y-m-d'), 0, 10),
            ]);
        });
    }

    /**
     * Called after advance return recorded.
     * Write point: ExpenseAdvanceService::recordReturn()
     */
    public static function onAdvanceReturn(\PDO $pdo, int $advanceId, int $staffId, string $staffName, float $returnedAmount, string $currency = 'USD'): void
    {
        self::safe($pdo, function ($ledger) use ($advanceId, $staffId, $staffName, $returnedAmount, $currency) {
            if ($advanceId <= 0 || $returnedAmount <= 0) return;
            $ledger->record([
                'staff_id'        => $staffId,
                'staff_name'      => $staffName,
                'direction'       => 'out',
                'currency'        => strtoupper($currency),
                'amount'          => round($returnedAmount, 2),
                'category'        => 'advance_return',
                'description'     => 'Return on advance #' . $advanceId,
                'status'          => 'active',
                'source_type'     => 'cash_advances',
                'source_id'       => (string)$advanceId,
                'idempotency_key' => 'ADVRET-' . $advanceId,
                'event_date'      => date('Y-m-d'),
            ]);
        });
    }

    /**
     * Called after advance cancelled.
     * Write point: ExpenseAdvanceService::cancelAdvance()
     */
    public static function onAdvanceCancelled(\PDO $pdo, int $advanceId, string $cancelledBy = ''): void
    {
        self::safe($pdo, function ($ledger) use ($advanceId, $cancelledBy) {
            $ledger->voidByKey('ADV-' . $advanceId, $cancelledBy, 'Advance cancelled');
        });
    }

    /**
     * Called after expense approved (staff_expenses or cash_expenses.json).
     * Write points: ExpenseAdvanceService::approveExpense(), staff_cashbooks quick_approve
     */
    public static function onExpenseApproved(\PDO $pdo, array $exp, string $source = 'staff_expenses'): void
    {
        self::safe($pdo, function ($ledger) use ($exp, $source) {
            $id = (int)($exp['id'] ?? 0);
            if ($id <= 0) return;

            // Determine idempotency key based on source
            if ($source === 'cash_expenses') {
                $idemKey = 'FEXP-' . $id;
            } else {
                $legacyId = (int)($exp['legacy_json_id'] ?? 0);
                $expSource = $exp['source'] ?? 'advance';
                if ($expSource === 'field' && $legacyId > 0) {
                    $idemKey = 'FEXP-' . $legacyId;
                } else {
                    $idemKey = 'EXP-' . $id;
                }
            }

            $currency = strtoupper($exp['currency'] ?? 'USD');
            $amount = round((float)($exp['amount'] ?? 0), 2);

            // v4.11.3: For staff payment expenses (Diko issues advance to Meckline),
            // the expense OUT belongs to the RECIPIENT (Meckline), not the submitter (Diko).
            // is_staff_payment=true OR staff_name present = it's an advance-to-staff entry.
            // In these cases collector_id is the ISSUER; we need the recipient's details.
            // The cash_in was already written under recipient's collector_id — OUT must match.
            $isStaffPay = !empty($exp['is_staff_payment']) || (!empty($exp['staff_name']) && ($exp['staff_name'] ?? '') !== ($exp['collector_name'] ?? ''));
            if ($isStaffPay && $source === 'cash_expenses') {
                // Recipient staff_id is looked up from staff_name if we have it
                // Fallback: use collector_id (which is actually the recipient on these entries)
                // For staff payment advances, collector_id WAS set to the recipient by the auto-link
                $recipId   = (int)($exp['recipient_id'] ?? 0);
                $recipName = (string)($exp['staff_name'] ?? '');
                // If no explicit recipient_id, staff_id field may hold issuer — don't use it
                $staffId   = $recipId > 0 ? $recipId : (int)($exp['collector_id'] ?? 0);
                $staffName = $recipName ?: (string)($exp['collector_name'] ?? '');
            } else {
                $staffId   = (int)($exp['staff_id'] ?? $exp['collector_id'] ?? 0);
                $staffName = (string)($exp['staff_name'] ?? $exp['collector_name'] ?? '');
            }

            $ledger->record([
                'staff_id'        => $staffId,
                'staff_name'      => $staffName,
                'direction'       => 'out',
                'currency'        => $currency,
                'amount'          => $amount,
                'ssp_amount'      => round((float)($exp['ssp_amount'] ?? 0), 2),
                'category'        => 'expense',
                'subcategory'     => (string)($exp['category'] ?? $exp['expense_type'] ?? 'Other'),
                'description'     => (string)($exp['description'] ?? $exp['note'] ?? ''),
                'status'          => 'active',
                'source_type'     => $source,
                'source_id'       => (string)$id,
                'idempotency_key' => $idemKey,
                'event_date'      => substr($exp['expense_date'] ?? $exp['submitted_at'] ?? $exp['created_at'] ?? date('Y-m-d'), 0, 10),
            ]);
        });
    }

    /**
     * Called after expense voided.
     * Write points: staff_cashbooks void_entry, ExpenseAdvanceService
     */
    public static function onExpenseVoided(\PDO $pdo, int $expenseId, string $source = 'staff_expenses', string $voidedBy = '', int $legacyJsonId = 0): void
    {
        self::safe($pdo, function ($ledger) use ($expenseId, $source, $voidedBy, $legacyJsonId) {
            if ($source === 'cash_expenses') {
                $ledger->voidByKey('FEXP-' . $expenseId, $voidedBy, 'Expense voided');
            } elseif ($legacyJsonId > 0) {
                $ledger->voidByKey('FEXP-' . $legacyJsonId, $voidedBy, 'Expense voided');
            } else {
                $ledger->voidByKey('EXP-' . $expenseId, $voidedBy, 'Expense voided');
            }
        });
    }

    /**
     * Called after handover confirmed.
     * Write point: handover_queue.php confirm_handover, post_field submit_handover (admin)
     */
    public static function onHandoverConfirmed(\PDO $pdo, array $hov): void
    {
        self::safe($pdo, function ($ledger) use ($hov) {
            $id = (int)($hov['id'] ?? 0);
            if ($id <= 0) return;
            $currency = strtoupper($hov['currency'] ?? 'USD');
            $amount    = round((float)($hov['amount']     ?? 0), 2);
            $sspAmount = round((float)($hov['ssp_amount'] ?? 0), 2);
            $sspRate   = round((float)($hov['ssp_rate']   ?? 0), 4);
            $eventDate = substr($hov['confirmed_at'] ?? date('Y-m-d'), 0, 10);

            // ── OUT: sender's bag decreases ───────────────────────────────
            $ledger->record([
                'staff_id'          => (int)($hov['from_id'] ?? 0),
                'staff_name'        => (string)($hov['from_name'] ?? ''),
                'direction'         => 'out',
                'currency'          => $currency,
                'amount'            => $amount,
                'ssp_amount'        => $sspAmount,
                'ssp_rate'          => $sspRate,
                'category'          => 'handover',
                'description'       => 'Handover #' . $id . ' to ' . ($hov['to_name'] ?? 'accountant'),
                'status'            => 'active',
                'source_type'       => 'cash_handovers',
                'source_id'         => (string)$id,
                'idempotency_key'   => 'HOV-' . $id,
                'counterparty_id'   => (int)($hov['to_id'] ?? 0),
                'counterparty_name' => (string)($hov['to_name'] ?? ''),
                'event_date'        => $eventDate,
            ]);

            // ── IN: receiver's bag increases (Diko / Rupesh) ──────────────
            // Only write if receiver has a staff_id set
            $toId = (int)($hov['to_id'] ?? 0);
            if ($toId > 0) {
                $ledger->record([
                    'staff_id'          => $toId,
                    'staff_name'        => (string)($hov['to_name'] ?? ''),
                    'direction'         => 'in',
                    'currency'          => $currency,
                    'amount'            => $amount,
                    'ssp_amount'        => $sspAmount,
                    'ssp_rate'          => $sspRate,
                    'category'          => 'collection',
                    'subcategory'       => 'handover_received',
                    'description'       => 'Received handover #' . $id . ' from ' . ($hov['from_name'] ?? 'staff'),
                    'status'            => 'active',
                    'source_type'       => 'cash_handovers',
                    'source_id'         => (string)$id,
                    'idempotency_key'   => 'HOV-IN-' . $id,
                    'counterparty_id'   => (int)($hov['from_id'] ?? 0),
                    'counterparty_name' => (string)($hov['from_name'] ?? ''),
                    'event_date'        => $eventDate,
                ]);
            }

            // ── RELAY: field_accountant (Diko) collects chain cash before relaying ──
            // When Diko submits a relay handover, individual staff submitted to the
            // main accountant (to_id = Rupesh), so Diko never got HOV-IN entries.
            // Fix: when a RELAY handover is confirmed, write a "relay_received" IN
            // entry for the from_id (Diko) so her bag reflects what she collected
            // before relaying it forward. Out (HOV-{id}) + In (HOV-RELAY-IN-{id}) = $0 net.
            $fromId = (int)($hov['from_id'] ?? 0);
            // Trigger relay IN if type=relay OR source_handover_ids set (field_accountant bundled relay)
            $_isRelay = ($hov['type'] ?? '') === 'relay' || !empty($hov['source_handover_ids']);
            if ($_isRelay && $fromId > 0 && $fromId !== $toId) {
                $ledger->record([
                    'staff_id'          => $fromId,
                    'staff_name'        => (string)($hov['from_name'] ?? ''),
                    'direction'         => 'in',
                    'currency'          => $currency,
                    'amount'            => $amount,
                    'ssp_amount'        => $sspAmount,
                    'ssp_rate'          => $sspRate,
                    'category'          => 'collection',
                    'subcategory'       => 'relay_received',
                    'description'       => 'Relay chain received #' . $id . ' — collected from field staff, relayed to ' . ($hov['to_name'] ?? 'accountant'),
                    'status'            => 'active',
                    'source_type'       => 'cash_handovers',
                    'source_id'         => (string)$id,
                    'idempotency_key'   => 'HOV-RELAY-IN-' . $id,
                    'counterparty_id'   => $toId,
                    'counterparty_name' => (string)($hov['to_name'] ?? ''),
                    'event_date'        => $eventDate,
                ]);
            }
        });
    }

    /**
     * Called after handover reverted.
     * Write point: handover_queue.php revert_handover
     */
    public static function onHandoverReverted(\PDO $pdo, int $handoverId, string $revertedBy = ''): void
    {
        self::safe($pdo, function ($ledger) use ($handoverId, $revertedBy) {
            $ledger->voidByKey('HOV-'    . $handoverId, $revertedBy, 'Handover reverted');
            $ledger->voidByKey('HOV-IN-' . $handoverId, $revertedBy, 'Handover reverted — receiver entry');
            $ledger->voidByKey('HOV-RELAY-IN-' . $handoverId, $revertedBy, 'Handover reverted — relay sender entry');
        });
    }

    /**
     * Called after staff_transfers INSERT.
     * Write point: StaffTransferService::create()
     */
    public static function onTransferCreated(\PDO $pdo, array $trf): void
    {
        self::safe($pdo, function ($ledger) use ($trf) {
            $id = (int)($trf['id'] ?? 0);
            if ($id <= 0) return;
            $currency = strtoupper($trf['currency'] ?? 'USD');
            $amount = round((float)($trf['amount'] ?? 0), 2);
            $eventDate = substr($trf['submitted_at'] ?? date('Y-m-d'), 0, 10);

            // OUT from sender
            $ledger->record([
                'staff_id'          => (int)($trf['from_id'] ?? 0),
                'staff_name'        => (string)($trf['from_name'] ?? ''),
                'direction'         => 'out',
                'currency'          => $currency,
                'amount'            => $amount,
                'category'          => 'transfer_out',
                'subcategory'       => (string)($trf['purpose'] ?? 'field_work'),
                'description'       => ($trf['transfer_no'] ?? 'TRF-' . $id),
                'status'            => 'active',
                'source_type'       => 'staff_transfers',
                'source_id'         => (string)$id,
                'idempotency_key'   => 'TRFOUT-' . $id,
                'counterparty_id'   => (int)($trf['to_id'] ?? 0),
                'counterparty_name' => (string)($trf['to_name'] ?? ''),
                'event_date'        => $eventDate,
            ]);

            // IN to receiver
            $ledger->record([
                'staff_id'          => (int)($trf['to_id'] ?? 0),
                'staff_name'        => (string)($trf['to_name'] ?? ''),
                'direction'         => 'in',
                'currency'          => $currency,
                'amount'            => $amount,
                'category'          => 'transfer_in',
                'subcategory'       => (string)($trf['purpose'] ?? 'field_work'),
                'description'       => ($trf['transfer_no'] ?? 'TRF-' . $id) . ' from ' . ($trf['from_name'] ?? ''),
                'status'            => 'active',
                'source_type'       => 'staff_transfers',
                'source_id'         => (string)$id,
                'idempotency_key'   => 'TRFIN-' . $id,
                'counterparty_id'   => (int)($trf['from_id'] ?? 0),
                'counterparty_name' => (string)($trf['from_name'] ?? ''),
                'event_date'        => $eventDate,
            ]);
        });
    }

    /**
     * Called after staff_transfers voided.
     * Write point: StaffTransferService::void()
     */
    public static function onTransferVoided(\PDO $pdo, int $transferId, string $voidedBy = ''): void
    {
        self::safe($pdo, function ($ledger) use ($transferId, $voidedBy) {
            $ledger->voidByKey('TRFOUT-' . $transferId, $voidedBy, 'Transfer voided');
            $ledger->voidByKey('TRFIN-' . $transferId, $voidedBy, 'Transfer voided');
        });
    }

    /**
     * Called after cash_ins.json entry created (SSP Received, Exchange, USD Received).
     * Write points: staff_cashbooks manual entry, post_field SSP advance,
     *               post_cashbook auto-link, ssp_overview
     */
    public static function onCashIn(\PDO $pdo, array $ci): void
    {
        self::safe($pdo, function ($ledger) use ($ci) {
            $id = (int)($ci['id'] ?? 0);
            if ($id <= 0) return;

            $cat = $ci['category'] ?? '';
            $dir = strtolower($ci['direction'] ?? 'in');

            if (in_array($cat, ['SSP Received', 'Exchange'])) {
                $currency  = 'SSP';
                $amount    = round((float)($ci['ssp_amount'] ?? $ci['amount'] ?? 0), 0);
                $ledgerDir = 'in';
                $ledgerCat = 'collection';
            } elseif ($cat === 'USD Received') {
                $currency  = 'USD';
                $amount    = round((float)($ci['amount'] ?? 0), 2);
                $ledgerDir = 'in';
                $ledgerCat = 'collection';
            } elseif ($dir === 'out') {
                // Manual cashbook OUT entries — Site Power, Fuel, etc.
                $currency  = strtoupper($ci['currency'] ?? 'USD');
                $amount    = round((float)($currency === 'SSP' ? ($ci['ssp_amount'] ?? $ci['amount'] ?? 0) : ($ci['amount'] ?? 0)), 2);
                $ledgerDir = 'out';
                $ledgerCat = 'expense';
            } else {
                return; // Unknown IN category
            }

            if ($amount <= 0) return;

            // Skip personal pay — salary, allowance, bonus are NOT field cash
            // v4.21.109: keyword list now centralised in StaffCashPositionService.
            // Keep an inline copy as ultimate safety net in case the autoloader
            // misses the class during a ledger replay; behaviour is identical.
            if (class_exists('StaffCashPositionService')) {
                if (\StaffCashPositionService::isPersonalPay($ci)) return;
            } else {
                $_desc = strtolower($ci['description'] ?? '');
                $_ref  = strtolower($ci['cb_ref'] ?? '');
                foreach (['salary', 'transport allowance', 'food allowance', 'bonus', 'employee benefit'] as $_pp) {
                    if (strpos($_desc, $_pp) !== false || strpos($_ref, $_pp) !== false) return;
                }
            }

            $ledger->record([
                'staff_id'        => (int)($ci['collector_id'] ?? $ci['staff_id'] ?? 0),
                'staff_name'      => (string)($ci['collector_name'] ?? ''),
                'direction'       => $ledgerDir,
                'currency'        => $currency,
                'amount'          => $amount,
                'ssp_amount'      => $currency === 'SSP' ? $amount : round((float)($ci['ssp_amount'] ?? 0), 2),
                'category'        => $ledgerCat,
                'subcategory'     => $cat,
                'description'     => (string)($ci['description'] ?? ''),
                'status'          => 'active',
                'source_type'     => 'cash_ins',
                'source_id'       => (string)$id,
                // OUT entries get CINO- prefix to avoid colliding with the CIN- key
                // used by the IN side of the same cash_ins.json entry (e.g. exchange)
                'idempotency_key' => ($ledgerDir === 'out' ? 'CINO-' : 'CIN-') . $id,
                'event_date'      => substr($ci['created_at'] ?? date('Y-m-d'), 0, 10),
            ]);
        });
    }

    /**
     * Called after cash_ins.json entry voided.
     */
    public static function onCashInVoided(\PDO $pdo, int $cashInId, string $voidedBy = ''): void
    {
        self::safe($pdo, function ($ledger) use ($cashInId, $voidedBy) {
            $ledger->voidByKey('CIN-' . $cashInId, $voidedBy, 'Cash in voided');
        });
    }

    // ── Internal: fail-safe wrapper ─────────────────────────────────────

    /**
     * Execute a ledger operation wrapped in try/catch.
     * NEVER throws — ledger failures must not block primary writes.
     */
    private static function safe(\PDO $pdo, callable $fn): void
    {
        try {
            require_once dirname(__FILE__) . '/StaffLedgerService.php';
            $ledger = new \StaffLedgerService($pdo);
            $fn($ledger);
        } catch (\Throwable $e) {
            error_log('[StaffLedgerWriter] WARN: ' . $e->getMessage());
            // Non-fatal: nightly backfill will catch anything missed
        }
    }

    /**
     * Give/receive SSP between staff. Creates two staff_ledger rows.
     * Used for: Rupesh gives Diko SSP, Diko returns SSP to Rupesh.
     */
    public static function onSSPTransfer(\PDO $pdo, array $trf): void
    {
        self::safe($pdo, function ($ledger) use ($trf) {
            $ref  = (string)($trf['transfer_ref'] ?? '');
            if (!$ref) return;
            $ssp  = round((float)($trf['ssp_amount'] ?? 0), 0);
            $rate = (float)($trf['ssp_rate'] ?? 0);
            $date = substr($trf['event_date'] ?? date('Y-m-d'), 0, 10);
            $desc = (string)($trf['description'] ?? $ref);
            if ($ssp <= 0) return;

            $ledger->record([
                'staff_id'          => (int)($trf['from_id'] ?? 0),
                'staff_name'        => (string)($trf['from_name'] ?? ''),
                'direction'         => 'out',
                'currency'          => 'SSP',
                'amount'            => 0,
                'ssp_amount'        => $ssp,
                'ssp_rate'          => $rate,
                'category'          => 'ssp_transfer_out',
                'description'       => $desc . ' to ' . ($trf['to_name'] ?? ''),
                'status'            => 'active',
                'source_type'       => 'ssp_transfer',
                'source_id'         => $ref,
                'idempotency_key'   => 'SSPTRFOUT-' . $ref,
                'counterparty_id'   => (int)($trf['to_id'] ?? 0),
                'counterparty_name' => (string)($trf['to_name'] ?? ''),
                'event_date'        => $date,
            ]);

            $ledger->record([
                'staff_id'          => (int)($trf['to_id'] ?? 0),
                'staff_name'        => (string)($trf['to_name'] ?? ''),
                'direction'         => 'in',
                'currency'          => 'SSP',
                'amount'            => 0,
                'ssp_amount'        => $ssp,
                'ssp_rate'          => $rate,
                'category'          => 'ssp_transfer_in',
                'description'       => $desc . ' from ' . ($trf['from_name'] ?? ''),
                'status'            => 'active',
                'source_type'       => 'ssp_transfer',
                'source_id'         => $ref,
                'idempotency_key'   => 'SSPTRFIN-' . $ref,
                'counterparty_id'   => (int)($trf['from_id'] ?? 0),
                'counterparty_name' => (string)($trf['from_name'] ?? ''),
                'event_date'        => $date,
            ]);
        });
    }

    /** Void both sides of an SSP transfer. */
    public static function onSSPTransferVoided(\PDO $pdo, string $ref, string $voidedBy = ''): void
    {
        self::safe($pdo, function ($ledger) use ($ref, $voidedBy) {
            $ledger->voidByKey('SSPTRFOUT-' . $ref, $voidedBy, 'SSP transfer voided');
            $ledger->voidByKey('SSPTRFIN-'  . $ref, $voidedBy, 'SSP transfer voided');
        });
    }
}