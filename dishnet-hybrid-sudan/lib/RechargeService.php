<?php
declare(strict_types=1);

/**
 * RechargeService
 *
 * Manages the full wallet recharge request lifecycle:
 *   - Customer submits recharge request (status = pending)
 *   - Admin approves  → wallet balance auto-credited + passbook entry + activity log
 *   - Admin rejects   → rejection reason stored + activity log
 *
 * Recharge request record structure:
 * {
 *   "id":              1,
 *   "retailer_id":     3,
 *   "retailer_name":   "John Doe",
 *   "amount":          200.00,
 *   "payment_proof":   "uploads/proof_1234.jpg",   // null if not uploaded
 *   "note":            "Bank transfer ref #TX123",
 *   "status":          "pending",                  // pending | approved | rejected
 *   "approved_by":     null,                       // admin name on approval
 *   "approved_at":     null,
 *   "rejection_reason": null,
 *   "created_at":      "2025-01-15 10:23:05",
 *   "updated_at":      "2025-01-15 10:23:05"
 * }
 */
class RechargeService
{
    const FILE     = 'wallet_recharge_requests.json';
    const LOG_FILE = 'activity_log.json';

    private      $store;
    private WalletService $wallet;
    private string        $uploadDir;

    public function __construct( $store, WalletService $wallet, string $uploadDir)
    {
        $this->store     = $store;
        $this->wallet    = $wallet;
        $this->uploadDir = rtrim($uploadDir, '/');
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // SUBMIT REQUEST  (customer)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Submit a new recharge request.
     * Wallet balance is NOT changed here — only on admin approval.
     *
     * @param array  $post     ['amount', 'note']
     * @param array  $files    $_FILES array (field: payment_proof)
     * @param array  $retailer Logged-in retailer record
     * @return array ['success'=>bool, 'message'=>string]
     */
    public function submit(array $post, array $files, array $retailer): array
    {
        $amount = round((float)($post['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Please enter a valid recharge amount.'];
        }

        // Handle proof upload
        $proofPath = null;
        if (!empty($files['payment_proof']['tmp_name'])) {
            $result = $this->saveProof($files['payment_proof']);
            if (!$result['ok']) {
                return ['success' => false, 'message' => 'Proof upload failed: ' . $result['error']];
            }
            $proofPath = $result['path'];
        }

        // BUG FIX: Previously used nextId() + append() as two separate operations
        // creating a race condition where concurrent submissions could get the same
        // ID. appendWithId() acquires a single LOCK_EX across both operations.
        $now = date('Y-m-d H:i:s');

        $record = [
            'retailer_id'      => (int)$retailer['id'],
            'retailer_name'    => $retailer['name'],
            'retailer_email'   => $retailer['email'] ?? '',
            'amount'           => $amount,
            'payment_proof'    => $proofPath,
            'note'             => trim($post['note'] ?? ''),
            'status'           => 'pending',
            'approved_by'      => null,
            'approved_at'      => null,
            'rejection_reason' => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];

        $savedRecord = $this->store->appendWithId(self::FILE, $record);
        $id = (int)$savedRecord['id'];
        $this->log('recharge_request', $retailer['name'], "Submitted recharge request of \$$amount", $id);

        return [
            'success' => true,
            'message' => 'Recharge request submitted successfully. Your wallet will be credited after admin approval.',
            'data'    => ['id' => $id],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // APPROVE  (admin)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Approve a pending recharge request.
     * Credits the retailer's wallet and records passbook entry.
     *
     * @param int   $requestId
     * @param array $admin      Logged-in admin retailer record
     * @return array ['success'=>bool, 'message'=>string]
     */
    public function approve(int $requestId, array $admin): array
    {
        // B-09 FIX: findOne + updateOne were two separate unlocked operations.
        // Two concurrent admin clicks could both pass the status=='pending' check
        // and both attempt to credit the wallet. Now the status check and status
        // update are a single atomic read-modify-write under LOCK_EX.
        $req = null;
        $alreadyProcessed = false;

        $this->store->withLock(self::FILE, function (array $requests) use ($requestId, $admin, &$req, &$alreadyProcessed) {
            $now = date('Y-m-d H:i:s');
            foreach ($requests as &$r) {
                if ((int)($r['id'] ?? 0) === $requestId) {
                    $req = $r;
                    if ($r['status'] !== 'pending') {
                        $alreadyProcessed = true;
                        return ['records' => $requests, 'result' => null];
                    }
                    // Mark approved inside the lock — second concurrent call will see this
                    $r['status']           = 'approved';
                    $r['approved_by']      = $admin['name'];
                    $r['approved_at']      = $now;
                    $r['rejection_reason'] = null;
                    $r['updated_at']       = $now;
                    $req = $r; // return updated record
                    break;
                }
            }
            unset($r);
            return ['records' => $requests, 'result' => null];
        });

        if (!$req) {
            return ['success' => false, 'message' => 'Recharge request not found.'];
        }
        if ($alreadyProcessed) {
            return ['success' => false, 'message' => 'Request is already ' . $req['status'] . '.'];
        }

        // Credit the wallet — idempotency key prevents double-credit even if
        // two requests somehow passed the lock (belt-and-suspenders).
        $this->wallet->credit(
            (int)$req['retailer_id'],
            (float)$req['amount'],
            'Wallet recharge approved (Request #' . $requestId . ')' . ($req['note'] ? ' — ' . $req['note'] : ''),
            $admin['name'],
            'RECHARGE-APPROVE-' . $requestId   // idempotency key
        );

        $this->log('recharge_approved', $admin['name'],
            "Approved recharge request #{$requestId} for {$req['retailer_name']} — \${$req['amount']}", $requestId);

        return [
            'success' => true,
            'message' => '$' . number_format($req['amount'], 2) . ' approved and credited to ' . $req['retailer_name'] . '.',
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // REJECT  (admin)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Reject a pending recharge request.
     *
     * @param int    $requestId
     * @param string $reason
     * @param array  $admin
     * @return array ['success'=>bool, 'message'=>string]
     */
    public function reject(int $requestId, string $reason, array $admin): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'Please provide a rejection reason.'];
        }

        // B-04 FIX: Use withLock for atomic status check + update, mirroring approve().
        // Previously findOne + updateOne were two separate unlocked operations — a
        // concurrent approve() click could both pass the status=pending check and
        // result in a rejected record with a credited wallet.
        $req              = null;
        $alreadyProcessed = false;

        $this->store->withLock(self::FILE, function (array $requests) use ($requestId, $reason, $admin, &$req, &$alreadyProcessed) {
            $now = date('Y-m-d H:i:s');
            foreach ($requests as &$r) {
                if ((int)($r['id'] ?? 0) === $requestId) {
                    $req = $r;
                    if ($r['status'] !== 'pending') {
                        $alreadyProcessed = true;
                        return ['records' => $requests, 'result' => null];
                    }
                    $r['status']           = 'rejected';
                    $r['rejection_reason'] = $reason;
                    $r['rejected_by']      = $admin['name'];   // correct field name for rejections
                    $r['approved_by']      = null;             // not approved
                    $r['approved_at']      = null;
                    $r['updated_at']       = $now;
                    $req = $r;
                    break;
                }
            }
            unset($r);
            return ['records' => $requests, 'result' => null];
        });

        if (!$req) {
            return ['success' => false, 'message' => 'Recharge request not found.'];
        }
        if ($alreadyProcessed) {
            return ['success' => false, 'message' => 'Request is already ' . $req['status'] . '.'];
        }

        $this->log('recharge_rejected', $admin['name'],
            "Rejected recharge request #{$requestId} for {$req['retailer_name']} — Reason: {$reason}", $requestId);

        return [
            'success' => true,
            'message' => 'Recharge request #' . $requestId . ' has been rejected.',
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // GETTERS
    // ══════════════════════════════════════════════════════════════════════

    /** All requests (admin), newest first */
    public function getAll(int $limit = 500): array
    {
        $all = $this->store->load(self::FILE);
        usort($all, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return array_slice($all, 0, $limit);
    }

    /** Requests for a specific retailer, newest first */
    public function getForRetailer(int $retailerId, int $limit = 100): array
    {
        $all = $this->store->findAll(self::FILE, 'retailer_id', $retailerId);
        usort($all, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return array_slice($all, 0, $limit);
    }

    /** Count pending requests (for admin badge) */
    public function countPending(): int
    {
        $all = $this->store->load(self::FILE);
        return count(array_filter($all, fn($r) => ($r['status'] ?? '') === 'pending'));
    }

    /** Get recent activity log (newest first) */
    public function getActivityLog(int $limit = 200): array
    {
        $all = $this->store->load(self::LOG_FILE);
        usort($all, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return array_slice($all, 0, $limit);
    }

    // ══════════════════════════════════════════════════════════════════════
    // INTERNAL
    // ══════════════════════════════════════════════════════════════════════

    private function saveProof(array $file): array
    {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

        // SEC FIX: Use finfo for server-side MIME detection — browser-supplied
        // $file['type'] can be spoofed. Mirror KycService::uploadFileToCrm() pattern.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name'] ?? '');
        finfo_close($finfo);

        if (!in_array($mime, $allowed, true)) {
            return ['ok' => false, 'error' => 'Only JPG, PNG, GIF, WEBP, PDF allowed.'];
        }

        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'File must be under 5 MB.'];
        }

        // B-07 FIX: Extension derived from finfo mime (already validated above),
        // NOT from the user-supplied filename which can be spoofed or wrong.
        // Also use bin2hex(random_bytes) for collision-proof uniqueness (I-06 fix too).
        $mimeExtMap = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/gif'       => 'gif',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
        ];
        $ext      = $mimeExtMap[$mime] ?? 'bin';
        $filename = 'proof_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest     = $this->uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'error' => 'Could not save file.'];
        }

        // Compress image uploads
        require_once dirname(__DIR__) . '/lib/ImageCompressor.php';
        compressImage($dest);

        return ['ok' => true, 'path' => 'uploads/' . $filename];
    }

    private function log(string $event, string $actor, string $detail, ?int $refId = null): void
    {
        $entry = [
            'id'         => $this->store->nextId(self::LOG_FILE),
            'event'      => $event,
            'actor'      => $actor,
            'detail'     => $detail,
            'ref_id'     => $refId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->store->append(self::LOG_FILE, $entry);
    }
}
