<?php
declare(strict_types=1);

/**
 * LeadRecoveryService — Find unconverted leads from WhatsApp history.
 *
 * Flow:
 * 1. Import contacts from IsOnWhatsapp (Evolution API)
 * 2. Fetch all CRM clients with phone numbers
 * 3. Cross-reference: who contacted us but is NOT a customer?
 * 4. Result = unconverted leads ready for follow-up
 */
class LeadRecoveryService
{
    private \PDO $db;

    public function __construct(\PDO $pdo)
    {
        $this->db = $pdo;
        // Ensure tables exist (idempotent)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS wa_known_contacts (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                phone       TEXT    NOT NULL,
                country     TEXT    DEFAULT NULL,
                source      TEXT    DEFAULT 'evolution',
                first_seen  TEXT    DEFAULT (datetime('now')),
                created_at  TEXT    DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_known_phone ON wa_known_contacts(phone);
            CREATE TABLE IF NOT EXISTS wa_lead_recovery (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                phone           TEXT    NOT NULL,
                display_name    TEXT    DEFAULT NULL,
                country         TEXT    DEFAULT NULL,
                crm_client_id   INTEGER DEFAULT NULL,
                crm_client_name TEXT    DEFAULT NULL,
                is_customer     INTEGER DEFAULT 0,
                status          TEXT    DEFAULT 'new',
                follow_up_count INTEGER DEFAULT 0,
                last_follow_up  TEXT    DEFAULT NULL,
                last_message_at TEXT    DEFAULT NULL,
                notes           TEXT    DEFAULT NULL,
                created_at      TEXT    DEFAULT (datetime('now')),
                updated_at      TEXT    DEFAULT (datetime('now'))
            );
            CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_lead_phone ON wa_lead_recovery(phone);
            CREATE INDEX IF NOT EXISTS idx_wa_lead_status ON wa_lead_recovery(status, is_customer);
        ");
    }

    /**
     * Import contacts from IsOnWhatsapp JSON data.
     * Only imports @s.whatsapp.net entries (real phones).
     * @return int Number of contacts imported
     */
    public function importContacts(array $records): int
    {
        // Detect format: Query export (has 'messages' field) vs IsOnWhatsapp (has 'remoteJid')
        $first = $records[0] ?? [];
        if (isset($first['phone']) && isset($first['messages'])) {
            return $this->importFromQuery($records);
        }
        return $this->importFromIsOnWhatsapp($records);
    }

    /**
     * Import from PostgreSQL query export: {phone, name, messages, first_contact, last_contact}
     * Also handles hot leads format: {phone, name, messages, last_contact, interests}
     */
    private function importFromQuery(array $records): int
    {
        $stmtContact = $this->db->prepare(
            "INSERT OR IGNORE INTO wa_known_contacts (phone, country, source, first_seen)
             VALUES (?, ?, 'evo_query', ?)"
        );
        $stmtLead = $this->db->prepare(
            "INSERT INTO wa_lead_recovery (phone, display_name, country, status, last_message_at, notes)
             VALUES (?, ?, ?, 'new', ?, ?)
             ON CONFLICT(phone) DO UPDATE SET
                display_name = COALESCE(excluded.display_name, wa_lead_recovery.display_name),
                last_message_at = excluded.last_message_at,
                notes = excluded.notes,
                updated_at = datetime('now')"
        );

        $imported = 0;
        // Skip own DishNet numbers
        $skipPhones = ['211921443002','211921443006','211921443009','211923400000'];
        foreach ($records as $r) {
            $phone = preg_replace('/[^0-9]/', '', $r['phone'] ?? '');
            if (strlen($phone) < 8 || strpos($phone, '@') !== false) continue;
            if (in_array($phone, $skipPhones)) continue;

            $country = $this->detectCountry($phone);
            $name = trim($r['name'] ?? '');
            if (strtolower($name) === 'você') $name = ''; // Skip bot push name
            $msgCount = (int)($r['messages'] ?? 0);
            $firstContact = substr($r['first_contact'] ?? '', 0, 19);
            $lastContact = substr($r['last_contact'] ?? '', 0, 19);
            $interests = trim($r['interests'] ?? '');

            // Build notes
            $notes = "{$msgCount} msgs";
            if ($interests) $notes .= " | Interest: {$interests}";
            if ($firstContact) $notes .= " | Since: " . substr($firstContact, 0, 10);

            $stmtContact->execute([$phone, $country, $firstContact ?: date('Y-m-d H:i:s')]);
            $stmtLead->execute([$phone, $name ?: null, $country, $lastContact ?: null, $notes]);
            $imported++;
        }

        return $imported;
    }

    /**
     * Import from IsOnWhatsapp format: {remoteJid, jidOptions, createdAt, ...}
     */
    private function importFromIsOnWhatsapp(array $records): int
    {
        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO wa_known_contacts (phone, country, source, first_seen)
             VALUES (?, ?, 'evolution', ?)"
        );

        $imported = 0;
        foreach ($records as $r) {
            $remoteJid = $r['remoteJid'] ?? '';
            if (strpos($remoteJid, '@s.whatsapp.net') === false) continue;

            $phone = preg_replace('/[^0-9]/', '', explode('@', $remoteJid)[0]);
            if (strlen($phone) < 8) continue;

            $country = $this->detectCountry($phone);
            $createdAt = $r['createdAt'] ?? date('Y-m-d H:i:s');
            if (is_string($createdAt) && strlen($createdAt) > 19) {
                $createdAt = substr($createdAt, 0, 19);
            }

            $stmt->execute([$phone, $country, $createdAt]);
            if ($stmt->rowCount() > 0) $imported++;
        }

        return $imported;
    }

    /**
     * Cross-reference using pre-built phone→client map (from local cache).
     * @param array $crmPhoneMap ['211912345678' => ['id' => 123, 'name' => 'John']]
     */
    public function crossReferenceLocal(array $crmPhoneMap): array
    {
        $contacts = $this->db->query("SELECT phone, country, first_seen FROM wa_known_contacts")->fetchAll(\PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare(
            "INSERT INTO wa_lead_recovery (phone, country, crm_client_id, crm_client_name, is_customer, status, last_message_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(phone) DO UPDATE SET
                crm_client_id = excluded.crm_client_id,
                crm_client_name = excluded.crm_client_name,
                is_customer = excluded.is_customer,
                updated_at = datetime('now')"
        );

        $customers = 0;
        $leads = 0;

        foreach ($contacts as $c) {
            $phone = $c['phone'];
            $match = $crmPhoneMap[$phone] ?? null;

            // Try with/without 211 prefix
            if (!$match && strpos($phone, '211') === 0) {
                $short = substr($phone, 3);
                $match = $crmPhoneMap[$short] ?? $crmPhoneMap['0' . $short] ?? null;
            }
            if (!$match && strlen($phone) === 9) {
                $match = $crmPhoneMap['211' . $phone] ?? null;
            }
            // Try adding 0 prefix
            if (!$match && strpos($phone, '211') === 0) {
                $match = $crmPhoneMap['0' . substr($phone, 3)] ?? null;
            }

            $isCustomer = $match ? 1 : 0;
            $status = $match ? 'customer' : 'new';

            $stmt->execute([
                $phone,
                $c['country'],
                $match['id'] ?? null,
                $match['name'] ?? null,
                $isCustomer,
                $status,
                $c['first_seen'],
            ]);

            if ($isCustomer) $customers++;
            else $leads++;
        }

        return [
            'total_contacts' => count($contacts),
            'customers'      => $customers,
            'leads'          => $leads,
        ];
    }

    /**
     * Cross-reference contacts against CRM clients (API version).
     * @param array $crmClients Array of CRM clients with phone fields
     * @return array ['total_contacts', 'customers', 'leads']
     */
    public function crossReference(array $crmClients): array
    {
        // Build CRM phone → client lookup
        $crmPhones = [];
        foreach ($crmClients as $client) {
            $name = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''));
            if (empty($name)) $name = $client['companyName'] ?? 'Unknown';
            $clientId = $client['id'] ?? null;

            // Check all phone fields
            foreach (['phone', 'phone2', 'phone3'] as $field) {
                $p = $this->normalisePhone($client[$field] ?? '');
                if (!empty($p)) {
                    $crmPhones[$p] = ['id' => $clientId, 'name' => $name];
                }
            }
            // Also check contacts array
            foreach ($client['contacts'] ?? [] as $contact) {
                $p = $this->normalisePhone($contact['phone'] ?? '');
                if (!empty($p)) {
                    $crmPhones[$p] = ['id' => $clientId, 'name' => $name];
                }
            }
        }

        // Get all known contacts
        $contacts = $this->db->query("SELECT phone, country, first_seen FROM wa_known_contacts")->fetchAll(\PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare(
            "INSERT INTO wa_lead_recovery (phone, country, crm_client_id, crm_client_name, is_customer, status, last_message_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(phone) DO UPDATE SET
                crm_client_id = excluded.crm_client_id,
                crm_client_name = excluded.crm_client_name,
                is_customer = excluded.is_customer,
                updated_at = datetime('now')"
        );

        $customers = 0;
        $leads = 0;

        foreach ($contacts as $c) {
            $phone = $c['phone'];
            $match = $crmPhones[$phone] ?? null;

            // Also try with/without 211 prefix
            if (!$match && strpos($phone, '211') === 0) {
                $short = substr($phone, 3);
                $match = $crmPhones[$short] ?? $crmPhones['0' . $short] ?? null;
            }
            if (!$match && strlen($phone) === 9) {
                $match = $crmPhones['211' . $phone] ?? null;
            }

            $isCustomer = $match ? 1 : 0;
            $status = $match ? 'customer' : 'new';

            $stmt->execute([
                $phone,
                $c['country'],
                $match['id'] ?? null,
                $match['name'] ?? null,
                $isCustomer,
                $status,
                $c['first_seen'],
            ]);

            if ($isCustomer) $customers++;
            else $leads++;
        }

        return [
            'total_contacts' => count($contacts),
            'customers'      => $customers,
            'leads'          => $leads,
            'crm_clients'    => count($crmClients),
            'crm_phones'     => count($crmPhones),
        ];
    }

    /**
     * Get lead list with filters.
     */
    public function getLeads(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'converted') {
                $where[] = 'status = ?';
                $params[] = 'converted';
            } else {
                $where[] = 'is_customer = 0';
                $where[] = 'status = ?';
                $params[] = $filters['status'];
            }
        } else {
            $where[] = 'is_customer = 0';
        }
        if (!empty($filters['country'])) {
            $where[] = 'country = ?';
            $params[] = $filters['country'];
        }

        $whereStr = empty($where) ? '1=1' : implode(' AND ', $where);

        // Count for pagination
        $cStmt = $this->db->prepare("SELECT COUNT(*) FROM wa_lead_recovery WHERE {$whereStr}");
        $cStmt->execute(array_values($params));
        $totalCount = (int)$cStmt->fetchColumn();

        // Add search after count (search doesn't affect page count display)
        if (!empty($filters['search'])) {
            $where[] = '(phone LIKE ? OR display_name LIKE ? OR notes LIKE ?)';
            $s = '%' . $filters['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s;
            $whereStr = implode(' AND ', $where);
        }

        $sql = "SELECT * FROM wa_lead_recovery WHERE {$whereStr} ORDER BY last_message_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ['rows' => $rows, 'total' => $totalCount];
    }

    /**
     * Import last messages to attach to leads (for chat preview).
     * Format: [{phone, msg, dir, sent_at}, ...]
     */
    public function importLastMessages(array $messages): int
    {
        // Add last_message column if missing
        try {
            $this->db->exec("ALTER TABLE wa_lead_recovery ADD COLUMN last_message TEXT DEFAULT NULL");
        } catch (\Throwable $e) { /* column exists */ }

        $stmt = $this->db->prepare(
            "UPDATE wa_lead_recovery SET last_message = ?, updated_at = datetime('now') WHERE phone = ?"
        );

        $updated = 0;
        foreach ($messages as $m) {
            $phone = preg_replace('/[^0-9]/', '', $m['phone'] ?? '');
            $msg = trim($m['msg'] ?? $m['message'] ?? $m['last_message'] ?? '');
            if (strlen($phone) < 8 || empty($msg)) continue;
            $stmt->execute([mb_substr($msg, 0, 500), $phone]);
            if ($stmt->rowCount() > 0) $updated++;
        }
        return $updated;
    }

    /**
     * Get stats summary.
     */
    public function getStats(): array
    {
        $stats = [];
        try {
            $stats['total_contacts'] = (int)$this->db->query("SELECT COUNT(*) FROM wa_known_contacts")->fetchColumn();
            $stats['total_leads'] = (int)$this->db->query("SELECT COUNT(*) FROM wa_lead_recovery WHERE is_customer = 0")->fetchColumn();
            $stats['total_customers'] = (int)$this->db->query("SELECT COUNT(*) FROM wa_lead_recovery WHERE is_customer = 1")->fetchColumn();
            $stats['followed_up'] = (int)$this->db->query("SELECT COUNT(*) FROM wa_lead_recovery WHERE is_customer = 0 AND follow_up_count > 0")->fetchColumn();
            $stats['converted'] = (int)$this->db->query("SELECT COUNT(*) FROM wa_lead_recovery WHERE status = 'converted'")->fetchColumn();
            $stats['new'] = (int)$this->db->query("SELECT COUNT(*) FROM wa_lead_recovery WHERE status = 'new'")->fetchColumn();

            // Country breakdown for leads
            $stmt = $this->db->query("SELECT country, COUNT(*) as cnt FROM wa_lead_recovery WHERE is_customer = 0 GROUP BY country ORDER BY cnt DESC LIMIT 10");
            $stats['by_country'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Tables may not exist yet
        }
        return $stats;
    }

    /**
     * Mark a lead as followed up.
     */
    public function markFollowedUp(int $leadId, ?string $notes = null): void
    {
        $sql = "UPDATE wa_lead_recovery SET follow_up_count = follow_up_count + 1, 
                last_follow_up = datetime('now'), status = 'followed_up', updated_at = datetime('now')";
        $params = [];
        if ($notes !== null) {
            $sql .= ", notes = ?";
            $params[] = $notes;
        }
        $sql .= " WHERE id = ?";
        $params[] = $leadId;
        $this->db->prepare($sql)->execute($params);
    }

    /**
     * Update lead status.
     */
    public function updateStatus(int $leadId, string $status): void
    {
        $this->db->prepare(
            "UPDATE wa_lead_recovery SET status = ?, updated_at = datetime('now') WHERE id = ?"
        )->execute([$status, $leadId]);
    }

    /**
     * Handle a reply from a followed-up lead.
     * If positive intent detected → auto-create lead in leads.json + smart-assign.
     */
    public function handleReply($store, string $phone, string $pushName, string $msgText, array $config): void
    {
        $phone = $this->normalisePhone($phone);

        // Check if this phone is a followed-up lead
        $stmt = $this->db->prepare(
            "SELECT * FROM wa_lead_recovery WHERE phone = ? AND status IN ('followed_up', 'new') AND is_customer = 0"
        );
        $stmt->execute([$phone]);
        $lead = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$lead) return; // Not a tracked lead

        // Detect positive intent
        $lower = strtolower(trim($msgText));
        $positivePatterns = [
            'yes', 'yeah', 'yep', 'ok', 'okay', 'sure', 'interested', 'want',
            'i want', 'i need', 'tell me more', 'how much', 'price',
            'starlink', 'fiber', 'install', 'connect', 'sign up', 'register',
            'available', 'can i get', 'send me', 'proceed',
        ];
        $isPositive = false;
        foreach ($positivePatterns as $p) {
            if (strpos($lower, $p) !== false) { $isPositive = true; break; }
        }
        if (!$isPositive) return;

        // Update WA lead status
        $this->db->prepare(
            "UPDATE wa_lead_recovery SET status = 'interested', updated_at = datetime('now') WHERE id = ?"
        )->execute([$lead['id']]);

        // Check if lead already exists in leads.json (don't duplicate)
        $existingLeads = $store->load('leads.json') ?? [];
        foreach ($existingLeads as $el) {
            $elPhone = $this->normalisePhone($el['phone'] ?? '');
            if ($elPhone === $phone) return; // Already in lead system
        }

        // Determine service interest from notes
        $notes = $lead['notes'] ?? '';
        $serviceType = 'starlink'; // default
        if (stripos($notes, 'FIBER') !== false) $serviceType = 'fiber';
        elseif (stripos($notes, 'LTE') !== false) $serviceType = 'lte';

        // Smart-assign: use lead_assignee_ids whitelist (falls back to all sales agents)
        $retailers     = $store->load('retailers.json') ?? [];
        $allowedIds    = array_filter(array_map('intval', explode(',', $config['lead_assignee_ids'] ?? '')));
        $salesRolesLR  = ['sales','field_agent','sales_staff'];
        $agentLoads    = [];
        foreach ($retailers as $r) {
            if (empty($r['is_active'])) continue;
            if (!in_array($r['role'] ?? '', $salesRolesLR, true)) continue;
            if (!empty($allowedIds) && !in_array((int)$r['id'], $allowedIds, true)) continue;
            $agId = (int)$r['id'];
            $openCount = count(array_filter($existingLeads, function($l) use ($agId) {
                return (int)($l['assigned_to'] ?? $l['retailer_id'] ?? 0) === $agId
                    && !in_array($l['status'] ?? '', ['won','lost'], true);
            }));
            $agentLoads[] = ['id' => $agId, 'name' => $r['name'] ?? '', 'load' => $openCount];
        }
        $assignTo = 0;
        $assignName = '';
        if (!empty($agentLoads)) {
            usort($agentLoads, fn($a, $b) => $a['load'] - $b['load']);
            $assignTo   = $agentLoads[0]['id'];
            $assignName = $agentLoads[0]['name'];
        }

        // Parse name
        $fullName = $pushName ?: ($lead['display_name'] ?? '');
        $nameParts = explode(' ', trim($fullName), 2);

        // Create lead in leads.json
        $newLead = [
            'id'              => $store->nextId('leads.json'),
            'customer_name'   => $fullName,
            'firstname'       => $nameParts[0] ?? '',
            'lastname'        => $nameParts[1] ?? '',
            'phone'           => $phone,
            'email'           => '',
            'address'         => '',
            'service_type'    => $serviceType,
            'interest_plan'   => '',
            'source'          => 'whatsapp_recovery',
            'source_detail'   => 'WA Lead Recovery — replied to follow-up',
            'priority'        => 'high',
            'follow_up_date'  => date('Y-m-d'),
            'notes'           => "Auto-created from WA Lead Recovery.\nReply: \"{$msgText}\"\nInterests: " . ($notes ?: 'unknown'),
            'retailer_id'     => $assignTo ?: 1,
            'retailer_name'   => $assignName ?: 'System',
            'assigned_to'     => $assignTo ?: null,
            'assigned_by'     => 'WA Lead Recovery (auto)',
            'assigned_at'     => date('Y-m-d H:i:s'),
            'status'          => 'interested',
            'qualified'       => false,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
            'connectivity_type' => 'New Connection',
            'sales_type'      => 'Cash',
        ];

        $existingLeads[] = $newLead;
        $store->save('leads.json', $existingLeads);

        // Log the conversion
        $this->db->prepare(
            "UPDATE wa_lead_recovery SET status = 'converted', notes = notes || ? , updated_at = datetime('now') WHERE id = ?"
        )->execute(["\n→ Auto-converted to Lead #{$newLead['id']} assigned to {$assignName}", $lead['id']]);
    }

    private function normalisePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (empty($phone)) return '';
        if (strpos($phone, '00211') === 0) $phone = substr($phone, 2);
        if (strpos($phone, '+') === 0) $phone = substr($phone, 1);
        if (strlen($phone) === 9 && ($phone[0] === '9' || $phone[0] === '0')) {
            $phone = '211' . ($phone[0] === '0' ? substr($phone, 1) : $phone);
        }
        if (strpos($phone, '0') === 0 && strlen($phone) === 10) {
            $phone = '211' . substr($phone, 1);
        }
        return $phone;
    }

    private function detectCountry(string $phone): string
    {
        if (strpos($phone, '211') === 0) return 'SS';
        if (strpos($phone, '91') === 0 && strlen($phone) >= 12) return 'IN';
        if (strpos($phone, '971') === 0) return 'AE';
        if (strpos($phone, '256') === 0) return 'UG';
        if (strpos($phone, '254') === 0) return 'KE';
        if (strpos($phone, '249') === 0) return 'SD';
        if (strpos($phone, '251') === 0) return 'ET';
        if (strpos($phone, '1') === 0 && strlen($phone) === 11) return 'US';
        return 'OTHER';
    }
}
