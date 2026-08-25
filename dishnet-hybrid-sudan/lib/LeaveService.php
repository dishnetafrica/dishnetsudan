<?php
declare(strict_types=1);
if (!function_exists('str_contains'))   { function str_contains(string $h, string $n): bool   { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h, string $n): bool{ return $n===''||strncmp($h,$n,strlen($n))===0; } }

/**
 * LeaveService — DishNet Hybrid Telecom v4.11.0
 *
 * Leave type management, balance tracking, request/approval flow.
 *
 * Usage:
 *   $ls = new LeaveService($store, $pdo);
 *   $types    = $ls->getLeaveTypes();
 *   $balances = $ls->getBalances($retailerId, 2026);
 *   $ls->submitRequest($retailerId, 'Amos', $typeId, '2026-04-01', '2026-04-03', 'Family event');
 *   $ls->approveRequest($requestId, 'Rupesh');
 */
class LeaveService
{
    private \StoreInterface $store;
    private \PDO            $pdo;

    public function __construct(\StoreInterface $store, \PDO $pdo)
    {
        $this->store = $store;
        $this->pdo   = $pdo;
    }

    // ══════════════════════════════════════════════════════════════════════
    // LEAVE TYPES
    // ══════════════════════════════════════════════════════════════════════

    public function getLeaveTypes(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM hrm_leave_types';
        if ($activeOnly) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY id ASC';
        return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getLeaveType(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hrm_leave_types WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // LEAVE BALANCES
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Get leave balances for an employee for a given year.
     * Auto-initialises missing balances from leave type entitlements.
     */
    public function getBalances(int $retailerId, int $year = 0): array
    {
        if (!$year) $year = (int)date('Y');

        $types = $this->getLeaveTypes();
        $result = [];

        foreach ($types as $t) {
            $tid = (int)$t['id'];
            $stmt = $this->pdo->prepare(
                'SELECT * FROM hrm_leave_balances WHERE retailer_id = ? AND leave_type_id = ? AND year = ?'
            );
            $stmt->execute([$retailerId, $tid, $year]);
            $bal = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$bal) {
                // Auto-initialise
                $entitlement = (int)$t['days_per_year'];
                $carried = $this->getCarryForward($retailerId, $tid, $year, $t);
                $available = $entitlement + $carried;
                $this->pdo->prepare(
                    "INSERT OR IGNORE INTO hrm_leave_balances (retailer_id, leave_type_id, year, entitlement, carried, taken, pending, available, updated_at)
                     VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?)"
                )->execute([$retailerId, $tid, $year, $entitlement, $carried, $available, date('Y-m-d H:i:s')]);

                $bal = [
                    'retailer_id'   => $retailerId,
                    'leave_type_id' => $tid,
                    'year'          => $year,
                    'entitlement'   => $entitlement,
                    'carried'       => $carried,
                    'taken'         => 0,
                    'pending'       => 0,
                    'available'     => $available,
                ];
            }

            $bal['leave_type_name'] = $t['name'];
            $bal['leave_type_code'] = $t['code'];
            $bal['is_paid']         = (int)$t['is_paid'];
            $bal['color']           = $t['color'] ?? '#3B82F6';
            $result[] = $bal;
        }

        return $result;
    }

    /**
     * Calculate carry-forward days from previous year.
     */
    private function getCarryForward(int $retailerId, int $typeId, int $year, array $type): int
    {
        if (!(int)($type['carry_forward'] ?? 0)) return 0;
        $maxCarry = (int)($type['max_carry'] ?? 0);

        $stmt = $this->pdo->prepare(
            'SELECT available FROM hrm_leave_balances WHERE retailer_id = ? AND leave_type_id = ? AND year = ?'
        );
        $stmt->execute([$retailerId, $typeId, $year - 1]);
        $prev = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$prev) return 0;

        $remaining = max(0, (int)$prev['available']);
        return $maxCarry > 0 ? min($remaining, $maxCarry) : $remaining;
    }

    /**
     * Recalculate a balance from leave requests (in case of manual corrections).
     */
    public function recalcBalance(int $retailerId, int $typeId, int $year): void
    {
        // Count approved days
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(days), 0) FROM hrm_leave_requests
             WHERE retailer_id = ? AND leave_type_id = ? AND status = 'approved'
               AND start_date >= ? AND start_date <= ?"
        );
        $stmt->execute([$retailerId, $typeId, "{$year}-01-01", "{$year}-12-31"]);
        $taken = (int)$stmt->fetchColumn();

        // Count pending days
        $stmtP = $this->pdo->prepare(
            "SELECT COALESCE(SUM(days), 0) FROM hrm_leave_requests
             WHERE retailer_id = ? AND leave_type_id = ? AND status = 'pending'
               AND start_date >= ? AND start_date <= ?"
        );
        $stmtP->execute([$retailerId, $typeId, "{$year}-01-01", "{$year}-12-31"]);
        $pending = (int)$stmtP->fetchColumn();

        $balStmt = $this->pdo->prepare(
            'SELECT entitlement, carried FROM hrm_leave_balances WHERE retailer_id = ? AND leave_type_id = ? AND year = ?'
        );
        $balStmt->execute([$retailerId, $typeId, $year]);
        $bal = $balStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$bal) return;

        $available = max(0, (int)$bal['entitlement'] + (int)$bal['carried'] - $taken - $pending);
        $this->pdo->prepare(
            "UPDATE hrm_leave_balances SET taken = ?, pending = ?, available = ?, updated_at = ?
             WHERE retailer_id = ? AND leave_type_id = ? AND year = ?"
        )->execute([$taken, $pending, $available, date('Y-m-d H:i:s'), $retailerId, $typeId, $year]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // LEAVE REQUESTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Submit a leave request.
     */
    public function submitRequest(int $retailerId, string $empName, int $typeId,
        string $startDate, string $endDate, string $reason = ''): array
    {
        $type = $this->getLeaveType($typeId);
        if (!$type) return ['ok' => false, 'error' => 'Invalid leave type'];

        // Calculate days (simple: business days = date diff + 1)
        $days = max(1, (int)ceil((strtotime($endDate) - strtotime($startDate)) / 86400) + 1);

        // Check balance (for types with limits)
        $year = (int)date('Y', strtotime($startDate));
        if ((int)$type['days_per_year'] > 0) {
            $balances = $this->getBalances($retailerId, $year);
            $bal = null;
            foreach ($balances as $b) {
                if ((int)$b['leave_type_id'] === $typeId) { $bal = $b; break; }
            }
            if ($bal && (int)$bal['available'] < $days) {
                return ['ok' => false, 'error' => "Insufficient {$type['name']} balance. Available: {$bal['available']} days, Requested: {$days} days"];
            }
        }

        // Check overlapping requests
        $overlap = $this->pdo->prepare(
            "SELECT COUNT(*) FROM hrm_leave_requests
             WHERE retailer_id = ? AND status IN ('pending','approved')
               AND start_date <= ? AND end_date >= ?"
        );
        $overlap->execute([$retailerId, $endDate, $startDate]);
        if ((int)$overlap->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'Overlapping leave request already exists'];
        }

        $this->pdo->prepare(
            "INSERT INTO hrm_leave_requests
             (retailer_id, employee_name, leave_type_id, leave_type_name, start_date, end_date, days, reason, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
        )->execute([
            $retailerId, $empName, $typeId, $type['name'],
            $startDate, $endDate, $days, $reason,
            date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
        ]);

        $reqId = (int)$this->pdo->lastInsertId();

        // Update pending count in balance
        $this->recalcBalance($retailerId, $typeId, $year);

        return ['ok' => true, 'id' => $reqId, 'days' => $days, 'leave_type' => $type['name']];
    }

    /**
     * Approve a leave request.
     */
    public function approveRequest(int $requestId, string $approvedBy): array
    {
        $req = $this->getRequest($requestId);
        if (!$req) return ['ok' => false, 'error' => 'Request not found'];
        if ($req['status'] !== 'pending') return ['ok' => false, 'error' => 'Request is not pending'];

        $this->pdo->prepare(
            "UPDATE hrm_leave_requests SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = ? WHERE id = ?"
        )->execute([$approvedBy, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $requestId]);

        $year = (int)date('Y', strtotime($req['start_date']));
        $this->recalcBalance((int)$req['retailer_id'], (int)$req['leave_type_id'], $year);

        return ['ok' => true, 'status' => 'approved'];
    }

    /**
     * Reject a leave request.
     */
    public function rejectRequest(int $requestId, string $rejectedBy, string $reason = ''): array
    {
        $req = $this->getRequest($requestId);
        if (!$req) return ['ok' => false, 'error' => 'Request not found'];
        if ($req['status'] !== 'pending') return ['ok' => false, 'error' => 'Request is not pending'];

        $this->pdo->prepare(
            "UPDATE hrm_leave_requests SET status = 'rejected', approved_by = ?, approved_at = ?, rejection_reason = ?, updated_at = ? WHERE id = ?"
        )->execute([$rejectedBy, date('Y-m-d H:i:s'), $reason, date('Y-m-d H:i:s'), $requestId]);

        $year = (int)date('Y', strtotime($req['start_date']));
        $this->recalcBalance((int)$req['retailer_id'], (int)$req['leave_type_id'], $year);

        return ['ok' => true, 'status' => 'rejected'];
    }

    /**
     * Cancel a leave request (by the employee themselves).
     */
    public function cancelRequest(int $requestId): array
    {
        $req = $this->getRequest($requestId);
        if (!$req) return ['ok' => false, 'error' => 'Request not found'];
        if (!in_array($req['status'], ['pending', 'approved'])) {
            return ['ok' => false, 'error' => 'Cannot cancel this request'];
        }

        $this->pdo->prepare(
            "UPDATE hrm_leave_requests SET status = 'cancelled', updated_at = ? WHERE id = ?"
        )->execute([date('Y-m-d H:i:s'), $requestId]);

        $year = (int)date('Y', strtotime($req['start_date']));
        $this->recalcBalance((int)$req['retailer_id'], (int)$req['leave_type_id'], $year);

        return ['ok' => true, 'status' => 'cancelled'];
    }

    /**
     * Get a single request.
     */
    public function getRequest(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hrm_leave_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List leave requests with optional filters.
     */
    public function listRequests(array $filters = []): array
    {
        $sql = 'SELECT * FROM hrm_leave_requests';
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['retailer_id'])) {
            $where[] = 'retailer_id = ?';
            $params[] = (int)$filters['retailer_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'start_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'end_date <= ?';
            $params[] = $filters['to'];
        }

        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)($filters['limit'] ?? 100);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get pending request count (for badge).
     */
    public function pendingCount(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM hrm_leave_requests WHERE status = 'pending'")->fetchColumn();
    }
}
