<?php
declare(strict_types=1);

/**
 * FtthCrmService
 *
 * Manages the relationship between plugin retailers and their corresponding
 * CRM client records in Organization 7 (FTTH Project).
 *
 * ── Architecture ────────────────────────────────────────────────────────
 *
 *   DishNet UCRM has two organizations:
 *     Org 2 — DishNet Africa Limited  → end customers (Fiber / StarLink)
 *     Org 7 — FTTH Project            → retailers (treated as B2B clients)
 *
 *   The plugin manages retailers internally via retailers.json. To get
 *   double-verification and CRM-level invoicing, each retailer also needs
 *   a CRM client record in Org 7. This service:
 *
 *     1. Creates the Org 7 client if it doesn't exist yet
 *     2. Links it by storing ftth_crm_client_id on the retailer record
 *     3. Syncs wallet balance as a custom attribute after every change
 *     4. Creates a CRM invoice in Org 7 when an admin tops up a wallet
 *
 * ── Retailer record additions ────────────────────────────────────────────
 *
 *   retailers.json gains two new fields:
 *     "ftth_crm_client_id": 1234   ← CRM client ID in Org 7
 *     "ftth_crm_synced_at": "..."  ← last successful sync timestamp
 *
 * ── CRM custom attributes used (Org 7) ──────────────────────────────────
 *
 *   These attribute IDs must exist in your UCRM under Org 7 clients.
 *   You can create them in UCRM → Settings → Custom Attributes.
 *   Set the IDs in kyc_config.json under ftth_attr_* keys.
 *
 *   ftth_attr_wallet_balance   (default: 101)  ← current wallet balance
 *   ftth_attr_retailer_id      (default: 102)  ← plugin retailer ID
 *   ftth_attr_retailer_role    (default: 103)  ← role (sales/field_agent/etc)
 */
class FtthCrmService
{
    // Default CRM custom attribute IDs for FTTH Org 7 retailer clients
    // Override in kyc_config.json under ftth_attr_* keys
    const DEFAULT_ATTR_WALLET_BALANCE = 101;
    const DEFAULT_ATTR_RETAILER_ID    = 102;
    const DEFAULT_ATTR_RETAILER_ROLE  = 103;

    const FTTH_ORG_ID = 7;   // Organization 7 — FTTH Project

    private CrmApiClient $crm;
    private     $store;
    private int          $attrWallet;
    private int          $attrRetailerId;
    private int          $attrRole;

    public function __construct(CrmApiClient $crm,  $store, array $config = [])
    {
        $this->crm           = $crm;
        $this->store         = $store;
        $this->attrWallet    = (int)($config['ftth_attr_wallet_balance'] ?? self::DEFAULT_ATTR_WALLET_BALANCE);
        $this->attrRetailerId= (int)($config['ftth_attr_retailer_id']    ?? self::DEFAULT_ATTR_RETAILER_ID);
        $this->attrRole      = (int)($config['ftth_attr_retailer_role']  ?? self::DEFAULT_ATTR_RETAILER_ROLE);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ENSURE CLIENT EXISTS IN ORG 7
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Ensure a retailer has a CRM client record in Org 7 (FTTH Project).
     *
     * Logic:
     *   - If retailer already has ftth_crm_client_id → verify it exists in CRM → return it
     *   - If not → search CRM by email → if found → link it
     *   - If not found → create new Org 7 client → link it
     *
     * @return int|null CRM client ID in Org 7, or null on failure
     */
    public function ensureRetailerClient(array $retailer): ?int
    {
        // Already linked?
        if (!empty($retailer['ftth_crm_client_id'])) {
            $existing = $this->crm->get('clients/' . (int)$retailer['ftth_crm_client_id']);
            if ($existing && !empty($existing['id'])) {
                return (int)$existing['id'];
            }
            // Stale link — fall through to re-create
        }

        // Search by email in CRM
        $searchResult = $this->crm->get('clients?organizationId=' . self::FTTH_ORG_ID . '&username=' . urlencode($retailer['email'] ?? ''));
        if ($searchResult && !empty($searchResult[0]['id'])) {
            $crmId = (int)$searchResult[0]['id'];
            $this->linkRetailer((int)$retailer['id'], $crmId);
            return $crmId;
        }

        // Create new CRM client in Org 7
        return $this->createRetailerClient($retailer);
    }

    /**
     * Create a new CRM client in Org 7 for a plugin retailer.
     * Returns the new CRM client ID or null on failure.
     */
    public function createRetailerClient(array $retailer): ?int
    {
        $payload = [
            'clientType'     => 1,                    // individual
            'isLead'         => false,                 // retailers are confirmed clients
            'organizationId' => self::FTTH_ORG_ID,    // Org 7 — FTTH Project
            'firstName'      => $this->extractFirstName($retailer['name'] ?? ''),
            'lastName'       => $this->extractLastName($retailer['name'] ?? ''),
            'username'       => $retailer['email'] ?? '',
            'zipCode'        => '',
            'city'           => '',
            'countryId'      => null,
            'stateId'        => null,
            'contacts'       => [[
                'email' => $retailer['email'] ?? '',
                'phone' => $retailer['phone'] ?? '',
                'name'  => $retailer['name'] ?? '',
            ]],
            'attributes'     => [
                ['customAttributeId' => $this->attrWallet,     'value' => (string)($retailer['wallet'] ?? 0)],
                ['customAttributeId' => $this->attrRetailerId,  'value' => (string)($retailer['id'] ?? '')],
                ['customAttributeId' => $this->attrRole,        'value' => $retailer['role'] ?? 'sales'],
            ],
            'note' => 'DishNet Plugin Retailer — auto-created by Hybrid Telecom Plugin v3',
        ];

        $response = $this->crm->post('clients', $payload);

        if (!$response || empty($response['id'])) {
            return null;
        }

        $crmId = (int)$response['id'];
        $this->linkRetailer((int)$retailer['id'], $crmId);
        return $crmId;
    }

    // ══════════════════════════════════════════════════════════════════════
    // WALLET BALANCE SYNC
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Sync the current wallet balance to the retailer's Org 7 CRM attribute.
     * Called after every wallet credit or debit.
     *
     * @param array $retailer  Full retailer record (must have fresh wallet balance)
     * @param float $balance   Current balance to sync
     */
    public function syncWalletBalance(array $retailer, float $balance, ?int $knownCrmId = null): bool
    {
        // I-01 FIX: Accept already-resolved $knownCrmId to skip the redundant CRM lookup
        // when the caller just called ensureRetailerClient() moments earlier.
        $crmId = $knownCrmId ?? $this->ensureRetailerClient($retailer);
        if (!$crmId) return false;

        $result = $this->crm->patch("clients/{$crmId}", [
            'attributes' => [
                ['customAttributeId' => $this->attrWallet, 'value' => (string)round($balance, 2)],
            ],
        ]);

        if ($result !== null) {
            $this->store->updateOne('retailers.json', 'id', (int)$retailer['id'], [
                'ftth_crm_synced_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        }

        return false;
    }

    // ══════════════════════════════════════════════════════════════════════
    // INVOICE CREATION ON WALLET TOP-UP
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Create a CRM invoice in Org 7 when admin tops up a retailer's wallet.
     *
     * This gives the accountants (Rupesh/Nirav) a proper invoice trail in CRM
     * for every cash deposit made against a retailer account.
     *
     * @param array  $retailer   Retailer record
     * @param float  $amount     Top-up amount
     * @param string $note       Description / payment reference
     * @param string $createdBy  Admin name
     * @return array|null        CRM invoice response or null on failure
     */
    public function createTopupInvoice(array $retailer, float $amount, string $note, string $createdBy, ?int $knownCrmId = null): ?array
    {
        // I-01 FIX: Accept already-resolved $knownCrmId to avoid redundant CRM lookup.
        $crmId = $knownCrmId ?? $this->ensureRetailerClient($retailer);
        if (!$crmId) return null;

        $invoicePayload = [
            'clientId'     => $crmId,
            'invoiceItems' => [[
                'label'       => 'Wallet Top-Up' . ($note ? ' — ' . $note : ''),
                'price'       => $amount,
                'quantity'    => 1,
                'unit'        => 'amount',
                'taxable'     => false,
            ]],
            'notes'        => "Credited by: {$createdBy} on " . date('Y-m-d H:i:s') . '. Plugin reference: Wallet top-up.',
            'adminNotes'   => "Plugin wallet_topup — retailer_id:{$retailer['id']} — amount:{$amount}",
            'invoiceTemplateId' => null,  // use default template
            'organizationId'   => self::FTTH_ORG_ID,
        ];

        return $this->crm->post('invoices', $invoicePayload);
    }

    // ══════════════════════════════════════════════════════════════════════
    // LINK / UNLINK
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Store the FTTH CRM client ID on the retailer record.
     */
    public function linkRetailer(int $retailerId, int $ftthCrmClientId): void
    {
        $this->store->updateOne('retailers.json', 'id', $retailerId, [
            'ftth_crm_client_id' => $ftthCrmClientId,
            'ftth_crm_synced_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Manually trigger a full sync for all retailers who lack a CRM link.
     * Run this once after deploying to link existing retailer accounts.
     *
     * @return array ['linked'=>int, 'created'=>int, 'failed'=>int]
     */
    public function syncAllRetailers(): array
    {
        $retailers = $this->store->load('retailers.json');
        $linked = $created = $failed = 0;

        foreach ($retailers as $r) {
            if (empty($r['is_active'])) continue;

            $hadId = !empty($r['ftth_crm_client_id']);
            $crmId = $this->ensureRetailerClient($r);

            if ($crmId) {
                $hadId ? $linked++ : $created++;
                // I-07 FIX: Pass $crmId to skip redundant ensureRetailerClient() inside syncWalletBalance
                $this->syncWalletBalance($r, (float)($r['wallet'] ?? 0), $crmId);
            } else {
                $failed++;
            }
        }

        return ['linked' => $linked, 'created' => $created, 'failed' => $failed];
    }

    /**
     * Get sync status for admin dashboard.
     */
    public function getSyncStatus(): array
    {
        $retailers = $this->store->load('retailers.json');
        $total   = count($retailers);
        $synced  = 0;
        $unsynced = [];

        foreach ($retailers as $r) {
            if (!empty($r['ftth_crm_client_id'])) {
                $synced++;
            } else {
                $unsynced[] = ['id' => $r['id'], 'name' => $r['name'], 'email' => $r['email'] ?? ''];
            }
        }

        return [
            'total'    => $total,
            'synced'   => $synced,
            'unsynced' => count($unsynced),
            'unsynced_list' => $unsynced,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    private function extractFirstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName), 2);
        return $parts[0] ?? $fullName;
    }

    private function extractLastName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName), 2);
        return $parts[1] ?? '';
    }
}
