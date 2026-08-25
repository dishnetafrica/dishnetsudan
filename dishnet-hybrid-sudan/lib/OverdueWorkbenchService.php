<?php
// ═══════════════════════════════════════════════════════════════════════════
// lib/OverdueWorkbenchService.php  —  Overdue Workbench (v4.21.66)
//
// Human-side counterpart to cron_overdue_email.php. Where the cron sends
// scheduled emails/WA on a fixed cadence, the workbench is what Aida, Bidal,
// and Rupesh use to ACTIVELY work overdue invoices: log promises to pay,
// assign field collectors, mark disputes, pause follow-up, etc.
//
// Read path:
//   listOverdue()  — joins live UCRM unpaid invoices + overdue_email_log
//                    (last auto-touch) + overdue_workbench (human notes)
//                    into one unified row. Filters and bucketing applied
//                    in PHP after the join because UCRM API can't filter
//                    by days-overdue cheaply.
//
// Write path (all log to overdue_workbench_log):
//   addNote, recordContact, setPromise, clearPromise, assignTo, setStatus,
//   bulkAssign, bulkSetStatus
//
// Auto-housekeeping:
//   onPaymentMatched() — called from webhook payment.add. Finds any
//   workbench row matching the just-paid invoice and marks closed_at +
//   close_reason='paid'. Idempotent; no-op if no matching row.
// ═══════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

if (!function_exists('str_contains'))    { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

require_once __DIR__ . '/SqliteStore.php';

class OverdueWorkbenchService
{
    /** @var SqliteStore */ private $store;
    /** @var \PDO */        private $pdo;
    /** @var array */       private $config;
    /** @var mixed */       private $crm;        // CrmApiClient or null

    public function __construct(SqliteStore $store, array $config = [], $crm = null)
    {
        $this->store  = $store;
        $this->pdo    = $store->getPdo();
        $this->config = $config;
        $this->crm    = $crm;
        $this->ensureSchema();
    }

    // ── Schema self-heal — same defensive pattern as duplicate_log
    private function ensureSchema(): void
    {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS overdue_workbench (
                invoice_number    TEXT PRIMARY KEY,
                client_id         INTEGER NOT NULL DEFAULT 0,
                client_name       TEXT NOT NULL DEFAULT '',
                amount_due        REAL NOT NULL DEFAULT 0,
                days_overdue      INTEGER NOT NULL DEFAULT 0,
                status            TEXT NOT NULL DEFAULT 'open',
                promised_pay_date TEXT,
                promised_amount   REAL,
                assigned_to       TEXT,
                pause_until       TEXT,
                last_note         TEXT,
                last_action_by    TEXT,
                last_action_at    TEXT,
                contact_attempts  INTEGER NOT NULL DEFAULT 0,
                last_contact_at   TEXT,
                created_at        TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at        TEXT NOT NULL DEFAULT (datetime('now')),
                closed_at         TEXT,
                close_reason      TEXT
            )");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_owb_status      ON overdue_workbench(status)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_owb_assigned    ON overdue_workbench(assigned_to)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_owb_promised    ON overdue_workbench(promised_pay_date)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_owb_last_action ON overdue_workbench(last_action_at)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_owb_client      ON overdue_workbench(client_id)");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS overdue_workbench_log (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_number  TEXT NOT NULL,
                client_id       INTEGER NOT NULL DEFAULT 0,
                action          TEXT NOT NULL,
                detail          TEXT,
                old_value       TEXT,
                new_value       TEXT,
                by_retailer     TEXT NOT NULL DEFAULT '',
                by_retailer_id  INTEGER,
                at_iso          TEXT NOT NULL DEFAULT (datetime('now'))
            )");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_owbl_invoice ON overdue_workbench_log(invoice_number, at_iso DESC)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_owbl_client  ON overdue_workbench_log(client_id, at_iso DESC)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_owbl_at      ON overdue_workbench_log(at_iso DESC)");
        } catch (\Throwable $e) { /* tables may already exist with newer shape */ }
    }

    // ═════════════════════════════════════════════════════════════════════
    // READ PATH
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Returns the full unified overdue dataset.
     * @param array $filters
     * @return array{summary:array, rows:array}
     */
    public function listOverdue(array $filters = []): array
    {
        $excludeIds      = $this->excludedClientIds();
        $rawInvoices     = $this->fetchUnpaidInvoices();
        $emailLogByInv   = $this->loadEmailLogByInvoice();
        $workbenchByInv  = $this->loadWorkbenchByInvoice();

        $clientCache = $this->store->load('ucrm_clients_cache.json') ?? [];
        $clientById  = [];
        foreach ($clientCache as $c) {
            $cid = (int)($c['id'] ?? 0);
            if ($cid > 0) $clientById[$cid] = $c;
        }

        $today = new \DateTime('now', new \DateTimeZone('Africa/Juba'));
        $rows = [];

        foreach ($rawInvoices as $inv) {
            $invNum   = (string)($inv['number'] ?? '');
            $clientId = (int)($inv['clientId'] ?? 0);
            $amtDue   = (float)($inv['amountToPay'] ?? $inv['total'] ?? 0);
            $dueStr   = (string)($inv['dueDate'] ?? '');
            if ($invNum === '' || $clientId <= 0 || $amtDue <= 0 || $dueStr === '') continue;
            if (in_array($clientId, $excludeIds, true)) continue;

            try { $dueDate = new \DateTime($dueStr, new \DateTimeZone('Africa/Juba')); }
            catch (\Throwable $e) { continue; }
            if ($today <= $dueDate) continue;
            $daysOverdue = (int)$today->diff($dueDate)->days;

            $client = $clientById[$clientId] ?? null;
            list($firstName, $fullName, $phone, $email) = $this->extractClientFields($client, $clientId);

            $emailLog = $emailLogByInv[$invNum] ?? null;
            $wb       = $workbenchByInv[$invNum] ?? null;

            $rows[] = [
                'invoice_number'    => $invNum,
                'invoice_id'        => (int)($inv['id'] ?? 0),
                'client_id'         => $clientId,
                'client_name'       => $fullName,
                'first_name'        => $firstName,
                'phone'             => $phone,
                'email'             => $email,
                'amount_due'        => $amtDue,
                'amount_total'      => (float)($inv['total'] ?? 0),
                'due_date'          => $dueStr,
                'due_date_fmt'      => $dueDate->format('d M Y'),
                'days_overdue'      => $daysOverdue,
                'bucket'            => $this->bucketFor($daysOverdue),
                'status'            => $wb['status']            ?? 'open',
                'assigned_to'       => $wb['assigned_to']       ?? null,
                'promised_pay_date' => $wb['promised_pay_date'] ?? null,
                'promised_amount'   => $wb['promised_amount']   ?? null,
                'pause_until'       => $wb['pause_until']       ?? null,
                'last_note'         => $wb['last_note']         ?? null,
                'last_action_by'    => $wb['last_action_by']    ?? null,
                'last_action_at'    => $wb['last_action_at']    ?? null,
                'contact_attempts'  => (int)($wb['contact_attempts'] ?? 0),
                'last_contact_at'   => $wb['last_contact_at']   ?? null,
                'last_email_stage'  => $emailLog['stage']       ?? null,
                'last_email_label'  => $emailLog['stage_label'] ?? null,
                'last_email_at'     => $emailLog['sent_at']     ?? null,
                'last_email_success'=> isset($emailLog['success']) ? (int)$emailLog['success'] : null,
                'has_workbench_row' => $wb !== null,
                'closed_at'         => $wb['closed_at'] ?? null,
                'promise_status'    => $this->computePromiseStatus($wb, $today),
            ];
        }

        $rows    = $this->applyFilters($rows, $filters, $today);
        $summary = $this->computeSummary($rows);

        // Sort: broken promises → due-today promises → 180+ → days_overdue desc
        usort($rows, function ($a, $b) {
            $rank = function ($r) {
                if ($r['promise_status'] === 'broken')    return 0;
                if ($r['promise_status'] === 'due_today') return 1;
                if ($r['bucket'] === '180+')              return 2;
                return 3;
            };
            $ra = $rank($a); $rb = $rank($b);
            if ($ra !== $rb) return $ra - $rb;
            return $b['days_overdue'] <=> $a['days_overdue'];
        });

        return ['summary' => $summary, 'rows' => $rows];
    }

    /** Detail view: full workbench history for one invoice */
    public function detail(string $invoiceNumber): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM overdue_workbench WHERE invoice_number = ?");
        $stmt->execute([$invoiceNumber]);
        $wb = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        $stmt = $this->pdo->prepare(
            "SELECT * FROM overdue_workbench_log
             WHERE invoice_number = ?
             ORDER BY at_iso DESC LIMIT 200"
        );
        $stmt->execute([$invoiceNumber]);
        $log = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $emails = [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM overdue_email_log WHERE invoice_number = ? ORDER BY sent_at DESC"
            );
            $stmt->execute([$invoiceNumber]);
            $emails = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        return ['workbench' => $wb, 'audit_log' => $log, 'email_log' => $emails];
    }

    // ═════════════════════════════════════════════════════════════════════
    // WRITE PATH
    // ═════════════════════════════════════════════════════════════════════

    public function addNote(string $invoiceNumber, string $note, array $by, ?string $contactWith = null): array
    {
        $note = trim($note);
        if ($note === '') return ['ok' => false, 'error' => 'Empty note'];
        $row = $this->upsertWorkbenchRow($invoiceNumber);
        if (!$row) return ['ok' => false, 'error' => 'Invoice not found in unpaid list'];

        $update = [
            'last_note'      => $note,
            'last_action_by' => (string)($by['name'] ?? ''),
            'last_action_at' => date('Y-m-d H:i:s'),
        ];
        if ($contactWith) {
            $update['contact_attempts'] = ((int)($row['contact_attempts'] ?? 0)) + 1;
            $update['last_contact_at']  = date('Y-m-d H:i:s');
        }
        $this->updateWorkbench($invoiceNumber, $update);

        $this->logAction(
            $invoiceNumber, (int)($row['client_id'] ?? 0),
            $contactWith ? 'contact' : 'note',
            $contactWith ? "via {$contactWith}: {$note}" : $note,
            null, $contactWith ?: null, $by
        );
        return ['ok' => true];
    }

    public function setPromise(string $invoiceNumber, string $isoDate, ?float $amount, string $note, array $by): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $isoDate)) {
            return ['ok' => false, 'error' => 'promised_pay_date must be YYYY-MM-DD'];
        }
        $row = $this->upsertWorkbenchRow($invoiceNumber);
        if (!$row) return ['ok' => false, 'error' => 'Invoice not found'];

        $oldPromise = (string)($row['promised_pay_date'] ?? '');
        $this->updateWorkbench($invoiceNumber, [
            'status'            => 'promised',
            'promised_pay_date' => $isoDate,
            'promised_amount'   => $amount,
            'last_note'         => $note ?: ($row['last_note'] ?? ''),
            'last_action_by'    => (string)($by['name'] ?? ''),
            'last_action_at'    => date('Y-m-d H:i:s'),
        ]);
        $detail = "Promised by {$isoDate}" . ($amount !== null ? " for \$" . number_format($amount, 2) : '');
        if ($note) $detail .= " — {$note}";
        $this->logAction($invoiceNumber, (int)($row['client_id'] ?? 0), 'promise', $detail, $oldPromise, $isoDate, $by);
        return ['ok' => true];
    }

    public function clearPromise(string $invoiceNumber, string $note, array $by): array
    {
        $row = $this->upsertWorkbenchRow($invoiceNumber);
        if (!$row) return ['ok' => false, 'error' => 'Invoice not found'];
        $oldPromise = (string)($row['promised_pay_date'] ?? '');
        $this->updateWorkbench($invoiceNumber, [
            'status'            => 'open',
            'promised_pay_date' => null,
            'promised_amount'   => null,
            'last_note'         => $note ?: 'Promise cleared',
            'last_action_by'    => (string)($by['name'] ?? ''),
            'last_action_at'    => date('Y-m-d H:i:s'),
        ]);
        $this->logAction($invoiceNumber, (int)($row['client_id'] ?? 0), 'promise', 'Promise cleared', $oldPromise, '', $by);
        return ['ok' => true];
    }

    public function assignTo(string $invoiceNumber, ?string $assignee, string $note, array $by): array
    {
        $assignee = $assignee !== null ? trim($assignee) : null;
        if ($assignee === '') $assignee = null;
        $row = $this->upsertWorkbenchRow($invoiceNumber);
        if (!$row) return ['ok' => false, 'error' => 'Invoice not found'];
        $old = (string)($row['assigned_to'] ?? '');
        $newStatus = $assignee !== null ? 'in_field' : ($row['status'] ?? 'open');
        $this->updateWorkbench($invoiceNumber, [
            'assigned_to'    => $assignee,
            'status'         => $newStatus,
            'last_note'      => $note ?: ($assignee ? "Assigned to {$assignee}" : 'Unassigned'),
            'last_action_by' => (string)($by['name'] ?? ''),
            'last_action_at' => date('Y-m-d H:i:s'),
        ]);
        $this->logAction(
            $invoiceNumber, (int)($row['client_id'] ?? 0), 'assign',
            $assignee ? ("Assigned to {$assignee}" . ($note ? " — {$note}" : '')) : 'Unassigned',
            $old, $assignee ?: '', $by
        );
        return ['ok' => true];
    }

    public function setStatus(string $invoiceNumber, string $newStatus, ?string $pauseUntil, string $note, array $by): array
    {
        $allowed = ['open','promised','in_field','disputed','unreachable','write_off_req','paused_followup'];
        if (!in_array($newStatus, $allowed, true)) {
            return ['ok' => false, 'error' => 'Invalid status. Allowed: ' . implode(',', $allowed)];
        }
        $row = $this->upsertWorkbenchRow($invoiceNumber);
        if (!$row) return ['ok' => false, 'error' => 'Invoice not found'];
        $oldStatus = (string)($row['status'] ?? 'open');

        $upd = [
            'status'         => $newStatus,
            'last_note'      => $note ?: "Status → {$newStatus}",
            'last_action_by' => (string)($by['name'] ?? ''),
            'last_action_at' => date('Y-m-d H:i:s'),
        ];
        if ($newStatus === 'paused_followup') {
            $upd['pause_until'] = $pauseUntil ?: null;
        } elseif ($oldStatus === 'paused_followup') {
            $upd['pause_until'] = null;
        }
        $this->updateWorkbench($invoiceNumber, $upd);
        $detail = "Status: {$oldStatus} → {$newStatus}"
                . ($note ? " — {$note}" : '')
                . ($pauseUntil && $newStatus === 'paused_followup' ? " (resume {$pauseUntil})" : '');
        $this->logAction($invoiceNumber, (int)($row['client_id'] ?? 0), 'status', $detail, $oldStatus, $newStatus, $by);
        return ['ok' => true];
    }

    public function bulkAssign(array $invoiceNumbers, ?string $assignee, string $note, array $by): array
    {
        $count = 0; $errors = [];
        foreach ($invoiceNumbers as $inv) {
            $r = $this->assignTo((string)$inv, $assignee, $note, $by);
            if ($r['ok']) $count++; else $errors[(string)$inv] = $r['error'];
        }
        $this->logAction('BULK', 0, 'bulk_assign',
            "Assigned " . count($invoiceNumbers) . " invoices to " . ($assignee ?: '(unassigned)'),
            null, $assignee ?: '', $by);
        return ['ok' => true, 'updated' => $count, 'errors' => $errors];
    }

    public function bulkSetStatus(array $invoiceNumbers, string $newStatus, ?string $pauseUntil, string $note, array $by): array
    {
        $count = 0; $errors = [];
        foreach ($invoiceNumbers as $inv) {
            $r = $this->setStatus((string)$inv, $newStatus, $pauseUntil, $note, $by);
            if ($r['ok']) $count++; else $errors[(string)$inv] = $r['error'];
        }
        $this->logAction('BULK', 0, 'bulk_status',
            "Set " . count($invoiceNumbers) . " invoices → {$newStatus}",
            null, $newStatus, $by);
        return ['ok' => true, 'updated' => $count, 'errors' => $errors];
    }

    /** Webhook payment.add hook — closes workbench row. Idempotent. */
    public function onPaymentMatched(string $invoiceNumber, array $by = []): bool
    {
        $stmt = $this->pdo->prepare("SELECT * FROM overdue_workbench WHERE invoice_number = ? AND closed_at IS NULL");
        $stmt->execute([$invoiceNumber]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return false;

        $this->updateWorkbench($invoiceNumber, [
            'closed_at'    => date('Y-m-d H:i:s'),
            'close_reason' => 'paid',
        ]);
        $this->logAction(
            $invoiceNumber, (int)($row['client_id'] ?? 0), 'status',
            'Closed: payment matched', (string)($row['status'] ?? 'open'), 'paid',
            $by ?: ['name' => 'system', 'id' => 0]
        );
        return true;
    }

    // ═════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═════════════════════════════════════════════════════════════════════

    private function fetchUnpaidInvoices(): array
    {
        // v4.21.66: paginate. UCRM caps each call at limit=500 max; production
        // has 2000+ unpaid invoices. Walk offsets until we get <500 back or
        // hit a hard safety ceiling of 20 pages (10K rows). Falls back to
        // ucrm_invoices_cache.json on API miss so the workbench is usable
        // when CRM is unreachable.
        if (!$this->crm || !method_exists($this->crm, 'isConfigured') || !$this->crm->isConfigured()) {
            return $this->loadInvoicesFromCache();
        }

        $all = [];
        $limit = 500;
        $maxPages = 20;
        for ($page = 0; $page < $maxPages; $page++) {
            $offset = $page * $limit;
            $url = "billing/invoices?statuses[]=1&statuses[]=2&limit={$limit}&offset={$offset}";
            $r = $this->crm->get($url);
            if (!is_array($r) || empty($r)) break;
            foreach ($r as $row) $all[] = $row;
            if (count($r) < $limit) break;
        }

        // If the API call returned nothing at all, fall back to cache so the
        // workbench still renders. Cache is refreshed on every payment.add
        // webhook (ClientInvoiceCacheRefresher v4.21.38).
        if (empty($all)) return $this->loadInvoicesFromCache();
        return $all;
    }

    private function loadInvoicesFromCache(): array
    {
        $invoices = $this->store->load('ucrm_invoices_cache.json') ?? [];
        if (!is_array($invoices)) return [];
        // Filter to status 1 (Draft) or 2 (Unpaid) only — match the API filter
        $out = [];
        foreach ($invoices as $inv) {
            $s = (int)($inv['status'] ?? $inv['invoiceStatus'] ?? 0);
            if (in_array($s, [1, 2, 3], true)) $out[] = $inv;
        }
        return $out;
    }

    private function loadEmailLogByInvoice(): array
    {
        $byInv = [];
        try {
            $rows = $this->pdo->query(
                "SELECT invoice_number, stage, stage_label, sent_at, success
                 FROM overdue_email_log
                 ORDER BY sent_at DESC"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $inv = $r['invoice_number'];
                if (!isset($byInv[$inv])) $byInv[$inv] = $r;  // newest first wins
            }
        } catch (\Throwable $e) {}
        return $byInv;
    }

    private function loadWorkbenchByInvoice(): array
    {
        $byInv = [];
        try {
            $rows = $this->pdo->query("SELECT * FROM overdue_workbench")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) $byInv[$r['invoice_number']] = $r;
        } catch (\Throwable $e) {}
        return $byInv;
    }

    private function extractClientFields(?array $client, int $clientId): array
    {
        if (!$client) return ['', "Client #{$clientId}", '', ''];
        $firstName = trim((string)($client['firstName'] ?? ''));
        $lastName  = trim((string)($client['lastName']  ?? ''));
        $fullName  = trim("{$firstName} {$lastName}");
        if ($fullName === '') $fullName = (string)($client['companyName'] ?? "Client #{$clientId}");
        if ($firstName === '') $firstName = $fullName;
        $phone = ''; $email = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!$phone && !empty($c['phone'])) $phone = $c['phone'];
            if (!$email && !empty($c['email'])) $email = $c['email'];
        }
        return [$firstName, $fullName, $phone, $email];
    }

    private function bucketFor(int $days): string
    {
        if ($days <= 14)  return '1-14';
        if ($days <= 30)  return '15-30';
        if ($days <= 60)  return '31-60';
        if ($days <= 90)  return '61-90';
        if ($days <= 180) return '91-180';
        return '180+';
    }

    private function computePromiseStatus(?array $wb, \DateTime $today): string
    {
        if (!$wb) return 'none';
        $p = (string)($wb['promised_pay_date'] ?? '');
        if ($p === '') return 'none';
        $todayStr = $today->format('Y-m-d');
        if ($p <  $todayStr) return 'broken';
        if ($p === $todayStr) return 'due_today';
        return 'pending';
    }

    private function applyFilters(array $rows, array $filters, \DateTime $today): array
    {
        $bucket          = (string)($filters['bucket']        ?? 'all');
        $status          = (string)($filters['status']        ?? '');
        $assignedTo      = (string)($filters['assigned_to']   ?? '');
        $minAmount       = (float)($filters['min_amount']     ?? 0);
        $clientSearch    = strtolower(trim((string)($filters['client_search'] ?? '')));
        $excludePaused   = !array_key_exists('exclude_paused', $filters) || !empty($filters['exclude_paused']);
        $onlyPromisesDue = !empty($filters['only_promises_due_today']);
        $onlyBroken      = !empty($filters['only_broken_promises']);
        $unassignedOnly  = !empty($filters['unassigned_only']);

        return array_values(array_filter($rows, function ($r) use ($bucket,$status,$assignedTo,$minAmount,$clientSearch,$excludePaused,$onlyPromisesDue,$onlyBroken,$unassignedOnly) {
            if ($bucket !== 'all' && $r['bucket'] !== $bucket) return false;
            if ($status !== '' && $r['status'] !== $status) return false;
            if ($assignedTo !== '' && (string)($r['assigned_to'] ?? '') !== $assignedTo) return false;
            if ($minAmount > 0 && (float)$r['amount_due'] < $minAmount) return false;
            if ($excludePaused && $status !== 'paused_followup' && $r['status'] === 'paused_followup') return false;
            if ($onlyPromisesDue && !in_array($r['promise_status'], ['due_today','broken'], true)) return false;
            if ($onlyBroken && $r['promise_status'] !== 'broken') return false;
            if ($unassignedOnly && !empty($r['assigned_to'])) return false;
            if ($clientSearch !== '') {
                $hay = strtolower(implode(' ', [
                    (string)$r['client_id'], (string)$r['client_name'], (string)$r['phone'],
                    (string)$r['email'], (string)$r['invoice_number'], (string)($r['last_note'] ?? ''),
                ]));
                if (strpos($hay, $clientSearch) === false) return false;
            }
            return true;
        }));
    }

    private function computeSummary(array $rows): array
    {
        $sum = [
            'count'            => count($rows),
            'total_due'        => 0.0,
            'by_bucket'        => ['1-14'=>0,'15-30'=>0,'31-60'=>0,'61-90'=>0,'91-180'=>0,'180+'=>0],
            'amt_bucket'       => ['1-14'=>0.0,'15-30'=>0.0,'31-60'=>0.0,'61-90'=>0.0,'91-180'=>0.0,'180+'=>0.0],
            'by_status'        => ['open'=>0,'promised'=>0,'in_field'=>0,'disputed'=>0,'unreachable'=>0,'write_off_req'=>0,'paused_followup'=>0],
            'broken_promises'  => 0,
            'promises_today'   => 0,
            'unassigned_count' => 0,
            'untouched_30d'    => 0,
        ];
        $cutoff = (new \DateTime('-30 days', new \DateTimeZone('Africa/Juba')))->format('Y-m-d H:i:s');
        foreach ($rows as $r) {
            $sum['total_due'] += (float)$r['amount_due'];
            if (isset($sum['by_bucket'][$r['bucket']])) {
                $sum['by_bucket'][$r['bucket']]++;
                $sum['amt_bucket'][$r['bucket']] += (float)$r['amount_due'];
            }
            if (isset($sum['by_status'][$r['status']])) $sum['by_status'][$r['status']]++;
            if ($r['promise_status'] === 'broken')    $sum['broken_promises']++;
            if ($r['promise_status'] === 'due_today') $sum['promises_today']++;
            if (empty($r['assigned_to']))             $sum['unassigned_count']++;
            $lastTouch = max((string)($r['last_action_at'] ?? ''), (string)($r['last_email_at'] ?? ''));
            if ($lastTouch === '' || $lastTouch < $cutoff) $sum['untouched_30d']++;
        }
        return $sum;
    }

    private function excludedClientIds(): array
    {
        $raw = (string)($this->config['overdue_email_exclude_clients'] ?? '');
        $ids = array_map('intval', array_filter(explode(',', $raw), function ($v) { return trim($v) !== ''; }));
        return array_values(array_unique(array_merge($ids, [888, 9])));
    }

    /** Insert workbench row only if invoice exists in unpaid set. Returns row or null. */
    private function upsertWorkbenchRow(string $invoiceNumber): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM overdue_workbench WHERE invoice_number = ?");
        $stmt->execute([$invoiceNumber]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($existing) return $existing;

        $invoices = $this->fetchUnpaidInvoices();
        $found = null;
        foreach ($invoices as $inv) {
            if (((string)($inv['number'] ?? '')) === $invoiceNumber) { $found = $inv; break; }
        }
        if (!$found) return null;
        $clientId = (int)($found['clientId'] ?? 0);

        $clients = $this->store->load('ucrm_clients_cache.json') ?? [];
        $client = null;
        foreach ($clients as $c) { if ((int)($c['id'] ?? 0) === $clientId) { $client = $c; break; } }
        list(, $fullName) = $this->extractClientFields($client, $clientId);

        $amt = (float)($found['amountToPay'] ?? $found['total'] ?? 0);
        $dueStr = (string)($found['dueDate'] ?? '');
        $days = 0;
        if ($dueStr !== '') {
            try {
                $today = new \DateTime('now', new \DateTimeZone('Africa/Juba'));
                $due   = new \DateTime($dueStr, new \DateTimeZone('Africa/Juba'));
                if ($today > $due) $days = (int)$today->diff($due)->days;
            } catch (\Throwable $e) {}
        }

        try {
            $this->pdo->prepare(
                "INSERT INTO overdue_workbench
                 (invoice_number, client_id, client_name, amount_due, days_overdue,
                  status, created_at, updated_at)
                 VALUES (?,?,?,?,?,'open', datetime('now'), datetime('now'))"
            )->execute([$invoiceNumber, $clientId, $fullName, $amt, $days]);
        } catch (\Throwable $e) { /* race: another request inserted same row */ }

        $stmt->execute([$invoiceNumber]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function updateWorkbench(string $invoiceNumber, array $fields): void
    {
        if (empty($fields)) return;
        $sets = []; $vals = [];
        foreach ($fields as $col => $val) {
            $sets[] = "{$col} = ?";
            $vals[] = $val;
        }
        $sets[] = "updated_at = datetime('now')";
        $vals[] = $invoiceNumber;
        $sql = "UPDATE overdue_workbench SET " . implode(', ', $sets) . " WHERE invoice_number = ?";
        try { $this->pdo->prepare($sql)->execute($vals); } catch (\Throwable $e) {}
    }

    private function logAction(string $invoiceNumber, int $clientId, string $action,
                               string $detail = '', $oldVal = null, $newVal = null, array $by = []): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO overdue_workbench_log
                 (invoice_number, client_id, action, detail, old_value, new_value,
                  by_retailer, by_retailer_id, at_iso)
                 VALUES (?,?,?,?,?,?,?,?, datetime('now'))"
            )->execute([
                $invoiceNumber, $clientId, $action, $detail,
                $oldVal !== null ? (string)$oldVal : null,
                $newVal !== null ? (string)$newVal : null,
                (string)($by['name'] ?? ''), (int)($by['id'] ?? 0),
            ]);
        } catch (\Throwable $e) {}
    }
}
