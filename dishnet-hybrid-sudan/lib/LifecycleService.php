<?php
/**
 * LifecycleService — Customer Lifecycle Management
 * 
 * Tracks customers from registration through active service.
 * Handles stage transitions, message scheduling, and action queues.
 * 
 * @package DishNet Hybrid Telecom
 * @since v4.8.56
 */

class LifecycleService
{
    private PDO $pdo;
    private string $dataDir;
    
    // ═══════════════════════════════════════════════════════════════════════════
    // STAGE DEFINITIONS
    // ═══════════════════════════════════════════════════════════════════════════
    
    public const STAGES = [
        'starlink' => [
            'registered'        => ['label' => 'Registered', 'order' => 1],
            'pending_payment'   => ['label' => 'Awaiting Payment', 'order' => 2, 'needs_action' => true],
            'pending_location'  => ['label' => 'Pending Location', 'order' => 3, 'needs_action' => true],
            'location_received' => ['label' => 'Location Received', 'order' => 4],
            'activating'        => ['label' => 'Activating', 'order' => 5],
            'active'            => ['label' => 'Active', 'order' => 6],
            'overdue'           => ['label' => 'Overdue', 'order' => 7, 'needs_action' => true],
            'suspended'         => ['label' => 'Suspended', 'order' => 8],
            'return_requested'  => ['label' => 'Return Requested', 'order' => 9],
            'returned'          => ['label' => 'Returned', 'order' => 10],
            'cancelled'         => ['label' => 'Cancelled', 'order' => 11],
        ],
        'fiber' => [
            'registered'           => ['label' => 'Registered', 'order' => 1],
            'pending_payment'      => ['label' => 'Awaiting Payment', 'order' => 2, 'needs_action' => true],
            'pending_survey'       => ['label' => 'Survey Pending', 'order' => 3, 'needs_action' => true],
            'survey_done'          => ['label' => 'Survey Complete', 'order' => 4],
            'pending_installation' => ['label' => 'Installation Pending', 'order' => 5],
            'active'               => ['label' => 'Active', 'order' => 6],
            'overdue'              => ['label' => 'Overdue', 'order' => 7, 'needs_action' => true],
            'suspended'            => ['label' => 'Suspended', 'order' => 8],
            'cancelled'            => ['label' => 'Cancelled', 'order' => 9],
        ],
        'lte' => [
            'registered'            => ['label' => 'Registered', 'order' => 1],
            'pending_payment'       => ['label' => 'Awaiting Payment', 'order' => 2, 'needs_action' => true],
            'pending_signal_check'  => ['label' => 'Signal Check', 'order' => 3, 'needs_action' => true],
            'pending_installation'  => ['label' => 'Installation', 'order' => 4],
            'active'                => ['label' => 'Active', 'order' => 5],
            'overdue'               => ['label' => 'Overdue', 'order' => 6, 'needs_action' => true],
            'suspended'             => ['label' => 'Suspended', 'order' => 7],
            'cancelled'             => ['label' => 'Cancelled', 'order' => 8],
        ],
        'sim' => [
            'registered' => ['label' => 'Registered', 'order' => 1],
            'active'     => ['label' => 'Active', 'order' => 2],
            'overdue'    => ['label' => 'Overdue', 'order' => 3, 'needs_action' => true],
            'suspended'  => ['label' => 'Suspended', 'order' => 4],
            'cancelled'  => ['label' => 'Cancelled', 'order' => 5],
        ],
    ];
    
    // ═══════════════════════════════════════════════════════════════════════════
    // MESSAGE DEFINITIONS
    // ═══════════════════════════════════════════════════════════════════════════
    
    public const MESSAGES = [
        // ── Starlink Registration ────────────────────────────────────────────────
        '1A' => [
            'service' => 'starlink',
            'name' => 'Kit Collected',
            'trigger_stage' => 'registered',
            'condition' => 'kit_number IS NOT NULL AND sales_type = "Cash"',
            'template' => "🛰 *DishNet Starlink — Kit Collected!*\n\nDear {{salutation}},\n\nYour Starlink kit is with you! ✅\n\n📦 *Kit:* {{kit_number}}\n💳 Payment received — Thank you!\n\n📋 *Setup Steps:*\n1. Place dish with clear sky view\n2. Plug in & wait 5-10 mins to auto-align\n3. Connect router to power\n\n⚠️ *IMPORTANT — Activation Required:*\nSend your location to activate your dish!\n\n📍 *How to share location:*\n• WhatsApp: Tap 📎 → Location → Send\n• OR send: Area name + landmark\n• OR send: GPS coordinates\n\n👉 Send location here: wa.me/211921443002\n\n🔖 Ref: App #{{app_id}}\n\nYour dish won't connect until we activate it!",
        ],
        '1B' => [
            'service' => 'starlink',
            'name' => 'Payment Confirmed (No Kit)',
            'trigger_stage' => 'registered',
            'condition' => 'kit_number IS NULL AND sales_type = "Cash"',
            'template' => "🛰 *DishNet Starlink — Payment Confirmed!*\n\nDear {{salutation}},\n\nPayment received — Thank you! ✅\n\n⏳ *Next:* Collect your kit OR schedule installation\n\n📋 *Options:*\n• 📦 Pickup from office (self-install)\n• 🔧 We install for you (1-2 days)\n\nReply \"PICKUP\" or \"INSTALL\" to proceed!\n\n🔖 Ref: App #{{app_id}}\n\n📲 Sales: wa.me/211923400000",
        ],
        '1C' => [
            'service' => 'starlink',
            'name' => 'Registration Received (Credit)',
            'trigger_stage' => 'registered',
            'condition' => 'sales_type = "Credit"',
            'template' => "🛰 *DishNet Starlink — Registration Received*\n\nDear {{salutation}},\n\nYour Starlink request is registered! 📋\n\n⏳ *Status:* Awaiting payment\n\n📋 *To complete:*\n1. Pay your agent or visit our office\n2. Collect your Starlink kit\n3. Send us your location to activate\n4. Get online! 🚀\n\n🔖 Ref: App #{{app_id}}\n\n📲 Sales: wa.me/211923400000\n\nQuestions? Reply to this message!",
        ],
        
        // ── Starlink Post-Registration Reminders ─────────────────────────────────
        '2A' => [
            'service' => 'starlink',
            'name' => 'Day 1: Send Location',
            'trigger_stage' => 'pending_location',
            'trigger_days' => 1,
            'template' => "🛰 *DishNet — Send Your Location!*\n\nHi {{salutation}},\n\nHave you set up your Starlink dish yet? 📡\n\n⚠️ *We need your location to activate!*\n\n📍 *Send location now:*\n• WhatsApp: Tap 📎 → Location → Send\n• OR: Area name + nearest landmark\n• OR: GPS coordinates (lat, long)\n\n👉 Send here: wa.me/211921443002\n\n📦 Kit: {{kit_number}}\n\nYour dish is waiting to be activated! 🚀",
        ],
        '2B' => [
            'service' => 'starlink',
            'name' => 'Day 3: Need Help?',
            'trigger_stage' => 'pending_location',
            'trigger_days' => 3,
            'template' => "🛰 *DishNet — Need Help?*\n\nHi {{salutation}},\n\nYour Starlink kit was collected 3 days ago but isn't activated yet.\n\n🤔 *Is everything okay?*\n\n📋 *To activate:*\n1. Set up the dish (clear sky view)\n2. Send us your location\n3. We activate remotely!\n\n📍 Send location: wa.me/211921443002\n\n📞 Or reply \"CALL ME\" for phone support!\n\n📦 Kit: {{kit_number}}",
        ],
        '2C' => [
            'service' => 'starlink',
            'name' => 'Day 7: Escalation',
            'trigger_stage' => 'pending_location',
            'trigger_days' => 7,
            'template' => "🛰 *DishNet — Let's Get You Connected!*\n\nHi {{salutation}},\n\nYour Starlink kit was collected 7 days ago but isn't active yet.\n\n⚠️ *Is there a problem?*\n\n📞 *Options:*\n• Reply \"VISIT\" — Technician comes FREE\n• Reply \"CALL ME\" — We'll phone you\n• Reply \"RETURN\" — Return the kit\n\n📦 Kit: {{kit_number}}\n📲 Support: wa.me/211921443002\n\nWe want to help! 🙏",
        ],
        '2D' => [
            'service' => 'starlink',
            'name' => 'Location Received',
            'trigger_stage' => 'location_received',
            'template' => "🛰 *DishNet — Location Received!*\n\nHi {{salutation}},\n\nGot your location! ✅\n\n📍 Location: {{location_area}}\n\n⏳ *Activating your dish now...*\nThis takes 5-15 minutes.\n\n📋 *While you wait:*\n• Make sure dish is powered on\n• Light should turn solid white when ready\n• Connect to WiFi: STARLINK\n\nWe'll confirm once active!\n\n📦 Kit: {{kit_number}}",
        ],
        
        // ── Starlink Activation ──────────────────────────────────────────────────
        '3A' => [
            'service' => 'starlink',
            'name' => 'Service Activated',
            'trigger_stage' => 'active',
            'template' => "🛰 *Welcome to DishNet Starlink!* 🎉\n\nDear {{salutation}},\n\nYou're ONLINE! ✅\n\n🌐 *Your Service:*\n• Plan: {{plan_name}}\n• Speed: Up to 200 Mbps\n• Data: Unlimited\n\n📦 Kit: {{kit_number}}\n👤 Account: {{customer_id}}\n\n📶 *Your WiFi:*\n• Network: STARLINK\n• Password: default123\n\n🔐 *Change WiFi Password:*\nSend message to: wa.me/211921443002\n\nExample:\nWIFI PASSWORD: MyNewWifi2026\n\n📋 *Tips:*\n• Keep dish clear of debris\n• Don't move the dish\n• Use 5GHz WiFi for best speed\n\nEnjoy your internet! 🚀",
        ],
        
        // ── Billing ──────────────────────────────────────────────────────────────
        '7A' => [
            'service' => 'all',
            'name' => 'Overdue Day 1',
            'trigger_stage' => 'overdue',
            'trigger_days' => 1,
            'template' => "🛰 *DishNet — Payment Overdue*\n\nHi {{salutation}},\n\nYour {{service_type}} payment is overdue ⚠️\n\n💰 Amount: {{amount_due}}\n📅 Was due: {{due_date}}\n\nPlease pay today to keep service active.\n\n📲 Sales: wa.me/211923400000\n\nNeed payment plan? Reply \"HELP\"",
        ],
        
        // ── Exit ─────────────────────────────────────────────────────────────────
        '8A' => [
            'service' => 'starlink',
            'name' => 'Return Request Received',
            'trigger_stage' => 'return_requested',
            'template' => "🛰 *DishNet — Return Request Received*\n\nHi {{salutation}},\n\nWe received your return request 📋\n\n📦 Kit: {{kit_number}}\n\n📋 *Return process:*\n1. Bring complete kit to office\n2. Include: Dish + Router + Cables\n3. Our team inspects equipment\n4. Refund in 3-5 working days\n\n⚠️ Kit must be undamaged\n\n📍 Office: Plot 101, Hai Malakal, Juba\n📲 Support: wa.me/211921443002\n\nSorry to see you go 😔",
        ],
    ];
    
    // ═══════════════════════════════════════════════════════════════════════════
    // CONSTRUCTOR
    // ═══════════════════════════════════════════════════════════════════════════
    
    public function __construct(PDO $pdo, string $dataDir)
    {
        $this->pdo = $pdo;
        $this->dataDir = $dataDir;
        $this->ensureTables();
    }
    
    /**
     * Create tables if they don't exist
     */
    private function ensureTables(): void
    {
        try {
            $this->pdo->query("SELECT 1 FROM service_lifecycle LIMIT 1");
        } catch (Throwable $e) {
            // Run migration
            $migrationPath = dirname(__DIR__) . '/migrations/030_service_lifecycle.sql';
            if (file_exists($migrationPath)) {
                $sql = file_get_contents($migrationPath);
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $stmt) {
                    if (!empty($stmt) && stripos($stmt, '--') !== 0) {
                        try {
                            $this->pdo->exec($stmt);
                        } catch (Throwable $ex) {
                            // Ignore
                        }
                    }
                }
            }
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // LIFECYCLE CRUD
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Create a new lifecycle record from a KYC application
     */
    public function createFromApplication(array $app, ?int $customerId = null): ?int
    {
        // Determine service type
        $customerType = strtolower($app['customer_type'] ?? $app['connectivity_type'] ?? 'starlink');
        $serviceType = 'starlink';
        if (strpos($customerType, 'fiber') !== false || strpos($customerType, 'ftth') !== false) {
            $serviceType = 'fiber';
        } elseif (strpos($customerType, 'lte') !== false || strpos($customerType, '4g') !== false) {
            $serviceType = 'lte';
        } elseif (strpos($customerType, 'sim') !== false) {
            $serviceType = 'sim';
        }
        
        // Determine initial stage
        $salesType = $app['sales_type'] ?? 'Cash';
        $kitNumber = $app['kitName'] ?? null;
        
        $stage = 'registered';
        if ($salesType === 'Credit') {
            $stage = 'pending_payment';
        } elseif ($serviceType === 'starlink' && $kitNumber) {
            $stage = 'pending_location';
        }
        
        // Check if already exists
        $existing = $this->findByApplicationId($app['id'] ?? null);
        if ($existing) {
            return $existing['id'];
        }
        
        $now = date('c');
        $fullName = trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? ''));
        
        $stmt = $this->pdo->prepare("
            INSERT INTO service_lifecycle (
                customer_id, application_id, customer_name, customer_phone, customer_email,
                service_type, kit_number, plan_name, sales_type, amount_paid,
                stage, stage_entered_at, stage_history,
                needs_action, action_type, action_priority,
                registered_by_id, registered_by_name,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?
            )
        ");
        
        $needsAction = in_array($stage, ['pending_payment', 'pending_location', 'pending_survey', 'pending_signal_check']) ? 1 : 0;
        $actionType = $needsAction ? 'send_reminder' : null;
        
        $stageHistory = json_encode([
            ['stage' => $stage, 'at' => $now, 'by' => 'system', 'note' => 'KYC submitted']
        ]);
        
        $stmt->execute([
            $customerId,
            $app['id'] ?? null,
            $fullName,
            $app['mobile'] ?? null,
            $app['email'] ?? null,
            $serviceType,
            $kitNumber,
            $app['offer_name'] ?? $app['package_choice'] ?? null,
            $salesType,
            (float)($app['amount_charged'] ?? 0),
            $stage,
            $now,
            $stageHistory,
            $needsAction,
            $actionType,
            $needsAction ? 1 : 0,
            $app['retailer_id'] ?? null,
            $app['retailer_name'] ?? null,
            $now,
            $now,
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }
    
    /**
     * Find lifecycle by application ID
     */
    public function findByApplicationId(?string $appId): ?array
    {
        if (!$appId) return null;
        
        $stmt = $this->pdo->prepare("SELECT * FROM service_lifecycle WHERE application_id = ? AND deleted_at IS NULL");
        $stmt->execute([$appId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    
    /**
     * Find lifecycle by customer ID
     */
    public function findByCustomerId(int $customerId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM service_lifecycle WHERE customer_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    
    /**
     * Find lifecycle by kit number
     */
    public function findByKitNumber(string $kitNumber): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM service_lifecycle WHERE kit_number = ? AND deleted_at IS NULL");
        $stmt->execute([$kitNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    
    /**
     * Get lifecycle by ID
     */
    public function get(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM service_lifecycle WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // STAGE TRANSITIONS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Transition to a new stage
     */
    public function transitionTo(int $id, string $newStage, string $by = 'system', ?string $note = null): bool
    {
        $record = $this->get($id);
        if (!$record) return false;
        
        $now = date('c');
        $history = json_decode($record['stage_history'] ?? '[]', true) ?: [];
        $history[] = [
            'stage' => $newStage,
            'at' => $now,
            'by' => $by,
            'note' => $note,
        ];
        
        // Determine if this stage needs action
        $serviceType = strtolower($record['service_type']);
        $stageDef = self::STAGES[$serviceType][$newStage] ?? [];
        $needsAction = !empty($stageDef['needs_action']) ? 1 : 0;
        $actionType = $needsAction ? 'send_reminder' : null;
        
        $stmt = $this->pdo->prepare("
            UPDATE service_lifecycle SET
                stage = ?,
                stage_entered_at = ?,
                stage_history = ?,
                needs_action = ?,
                action_type = ?,
                updated_at = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $newStage,
            $now,
            json_encode($history),
            $needsAction,
            $actionType,
            $now,
            $id,
        ]);
    }
    
    /**
     * Mark as activated (sets stage to active)
     */
    public function markActivated(int $id, ?int $firstInvoiceId = null): bool
    {
        $now = date('c');
        
        $stmt = $this->pdo->prepare("
            UPDATE service_lifecycle SET
                activated_at = ?,
                first_invoice_id = ?,
                first_invoice_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$now, $firstInvoiceId, $now, $id]);
        
        return $this->transitionTo($id, 'active', 'system', 'First invoice created');
    }
    
    /**
     * Set location received
     */
    public function setLocationReceived(int $id, ?float $lat, ?float $lng, ?string $area): bool
    {
        $now = date('c');
        
        $stmt = $this->pdo->prepare("
            UPDATE service_lifecycle SET
                location_lat = ?,
                location_lng = ?,
                location_area = ?,
                location_received_at = ?,
                updated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$lat, $lng, $area, $now, $now, $id]);
        
        return $this->transitionTo($id, 'location_received', 'system', 'Location received from customer');
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // MESSAGING
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Record that a message was sent
     */
    public function recordMessageSent(int $id, string $messageCode, string $messageText, string $status = 'sent'): void
    {
        $record = $this->get($id);
        if (!$record) return;
        
        $now = date('c');
        
        // Update messages_sent tracking
        $messagesSent = json_decode($record['messages_sent'] ?? '{}', true) ?: [];
        $messagesSent[$messageCode] = $now;
        
        $stmt = $this->pdo->prepare("
            UPDATE service_lifecycle SET
                messages_sent = ?,
                last_message_id = ?,
                last_message_at = ?,
                updated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([json_encode($messagesSent), $messageCode, $now, $now, $id]);
        
        // Log the message
        $this->logMessage($id, $messageCode, $record['customer_phone'], $record['customer_name'], $messageText, $status);
    }
    
    /**
     * Log a message to lifecycle_message_log
     */
    private function logMessage(int $lifecycleId, string $code, ?string $phone, ?string $name, string $text, string $status): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO lifecycle_message_log (
                    lifecycle_id, message_code, recipient_phone, recipient_name, message_text, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
            ");
            $stmt->execute([$lifecycleId, $code, $phone, $name, $text, $status]);
        } catch (Throwable $e) {
            // Ignore - table may not exist
        }
    }
    
    /**
     * Check if a message has already been sent
     */
    public function wasMessageSent(int $id, string $messageCode): bool
    {
        $record = $this->get($id);
        if (!$record) return false;
        
        $messagesSent = json_decode($record['messages_sent'] ?? '{}', true) ?: [];
        return isset($messagesSent[$messageCode]);
    }
    
    /**
     * Get the next message to send based on stage and days
     */
    public function getNextMessage(array $record): ?array
    {
        $serviceType = strtolower($record['service_type'] ?? 'starlink');
        $stage = $record['stage'] ?? 'registered';
        $daysInStage = 0;
        
        if (!empty($record['stage_entered_at'])) {
            $entered = new DateTime($record['stage_entered_at']);
            $now = new DateTime();
            $daysInStage = $entered->diff($now)->days;
        }
        
        $messagesSent = json_decode($record['messages_sent'] ?? '{}', true) ?: [];
        
        foreach (self::MESSAGES as $code => $msg) {
            // Check service match
            if ($msg['service'] !== 'all' && $msg['service'] !== $serviceType) {
                continue;
            }
            
            // Check stage match
            if (($msg['trigger_stage'] ?? '') !== $stage) {
                continue;
            }
            
            // Check days threshold
            $triggerDays = $msg['trigger_days'] ?? 0;
            if ($daysInStage < $triggerDays) {
                continue;
            }
            
            // Check if already sent
            if (isset($messagesSent[$code])) {
                continue;
            }
            
            // This message should be sent
            return [
                'code' => $code,
                'name' => $msg['name'],
                'template' => $msg['template'],
                'days_in_stage' => $daysInStage,
            ];
        }
        
        return null;
    }
    
    /**
     * Render a message template with variables
     */
    public function renderMessage(string $template, array $record): string
    {
        $salutation = explode(' ', $record['customer_name'] ?? '')[0] ?: 'Customer';
        
        $vars = [
            '{{salutation}}' => $salutation,
            '{{customer_name}}' => $record['customer_name'] ?? '',
            '{{customer_phone}}' => $record['customer_phone'] ?? '',
            '{{kit_number}}' => $record['kit_number'] ?? '-',
            '{{app_id}}' => $record['application_id'] ?? '-',
            '{{customer_id}}' => $record['customer_id'] ? 'C-' . $record['customer_id'] : '-',
            '{{service_type}}' => ucfirst($record['service_type'] ?? 'Starlink'),
            '{{plan_name}}' => $record['plan_name'] ?? 'Standard',
            '{{location_area}}' => $record['location_area'] ?? 'Your location',
        ];
        
        return str_replace(array_keys($vars), array_values($vars), $template);
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // QUERIES
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Get customers needing action
     */
    public function getActionQueue(?string $serviceFilter = null, int $limit = 50): array
    {
        $sql = "SELECT * FROM service_lifecycle WHERE needs_action = 1 AND deleted_at IS NULL";
        $params = [];
        
        if ($serviceFilter && $serviceFilter !== 'all') {
            $sql .= " AND LOWER(service_type) = ?";
            $params[] = strtolower($serviceFilter);
        }
        
        $sql .= " ORDER BY action_priority DESC, stage_entered_at ASC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get stage counts by service type
     */
    public function getStageCounts(?string $serviceFilter = null): array
    {
        $sql = "SELECT service_type, stage, COUNT(*) as cnt FROM service_lifecycle WHERE deleted_at IS NULL";
        $params = [];
        
        if ($serviceFilter && $serviceFilter !== 'all') {
            $sql .= " AND LOWER(service_type) = ?";
            $params[] = strtolower($serviceFilter);
        }
        
        $sql .= " GROUP BY service_type, stage";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $counts = [];
        foreach ($rows as $row) {
            $svc = strtolower($row['service_type']);
            if (!isset($counts[$svc])) {
                $counts[$svc] = [];
            }
            $counts[$svc][$row['stage']] = (int)$row['cnt'];
        }
        
        return $counts;
    }
    
    /**
     * Import existing KYC applications into lifecycle
     */
    public function importFromKycApplications(array $applications): int
    {
        $imported = 0;
        foreach ($applications as $app) {
            // Skip if already imported
            if ($this->findByApplicationId($app['id'] ?? null)) {
                continue;
            }
            
            // Skip rejected/cancelled
            $status = strtolower($app['status'] ?? '');
            if (in_array($status, ['rejected', 'cancelled'])) {
                continue;
            }
            
            $id = $this->createFromApplication($app, $app['crm_client_id'] ?? null);
            if ($id) {
                $imported++;
            }
        }
        
        return $imported;
    }
}
