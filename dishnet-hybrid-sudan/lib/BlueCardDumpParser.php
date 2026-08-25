<?php
/**
 * BlueCardDumpParser
 * ═══════════════════════════════════════════════════════════════════════
 * Parses MySQL dump files exported from BlueCard portal.
 * Extracts sim_cards, users (LTE customers), services tables.
 */

class BlueCardDumpParser
{
    private string $content;
    private array $tables = [];
    
    public function __construct(string $content)
    {
        $this->content = $content;
    }
    
    /**
     * Parse the dump and extract all relevant tables
     */
    public function parse(): array
    {
        $this->tables = [
            'sim_cards'  => $this->extractTable('sim_cards'),
            'users'      => $this->extractTable('users'),
            'services'   => $this->extractTable('services'),
            'data_mgmt'  => $this->extractTable('data_mgmt'),
        ];
        
        return $this->tables;
    }
    
    /**
     * Extract INSERT statements for a specific table
     */
    private function extractTable(string $tableName): array
    {
        $rows = [];
        
        // Find INSERT INTO statements for this table
        // Pattern: INSERT INTO `tablename` (...) VALUES\n(...),\n(...);
        $pattern = '/INSERT INTO `' . preg_quote($tableName, '/') . '`\s*\(([^)]+)\)\s*VALUES\s*(.+?);/s';
        
        if (!preg_match_all($pattern, $this->content, $matches, PREG_SET_ORDER)) {
            return [];
        }
        
        foreach ($matches as $match) {
            // Parse column names
            $columnsRaw = $match[1];
            $columns = array_map(function($c) {
                return trim($c, " `\t\n\r");
            }, explode(',', $columnsRaw));
            
            // Parse values - this is tricky because values can span multiple lines
            $valuesBlock = $match[2];
            $parsedRows = $this->parseValuesBlock($valuesBlock, $columns);
            $rows = array_merge($rows, $parsedRows);
        }
        
        return $rows;
    }
    
    /**
     * Parse a VALUES block into array of rows
     */
    private function parseValuesBlock(string $block, array $columns): array
    {
        $rows = [];
        $block = trim($block);
        
        // Split by ),( or ),\n( while handling quoted strings
        $rowStrings = $this->splitRows($block);
        
        foreach ($rowStrings as $rowStr) {
            $values = $this->parseRow($rowStr);
            if (count($values) === count($columns)) {
                $rows[] = array_combine($columns, $values);
            }
        }
        
        return $rows;
    }
    
    /**
     * Split a VALUES block into individual row strings
     */
    private function splitRows(string $block): array
    {
        $rows = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $depth = 0;
        $len = strlen($block);
        
        for ($i = 0; $i < $len; $i++) {
            $char = $block[$i];
            $prev = $i > 0 ? $block[$i-1] : '';
            
            if (!$inString) {
                if ($char === "'" || $char === '"') {
                    $inString = true;
                    $stringChar = $char;
                    $current .= $char;
                } elseif ($char === '(') {
                    $depth++;
                    if ($depth === 1) {
                        $current = '';
                    } else {
                        $current .= $char;
                    }
                } elseif ($char === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $rows[] = trim($current);
                        $current = '';
                    } else {
                        $current .= $char;
                    }
                } else {
                    if ($depth > 0) {
                        $current .= $char;
                    }
                }
            } else {
                $current .= $char;
                if ($char === $stringChar && $prev !== '\\') {
                    $inString = false;
                }
            }
        }
        
        return $rows;
    }
    
    /**
     * Parse a single row string into values
     */
    private function parseRow(string $rowStr): array
    {
        $values = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($rowStr);
        
        for ($i = 0; $i < $len; $i++) {
            $char = $rowStr[$i];
            $prev = $i > 0 ? $rowStr[$i-1] : '';
            
            if (!$inString) {
                if ($char === "'" || $char === '"') {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === ',') {
                    $values[] = $this->cleanValue(trim($current));
                    $current = '';
                    continue;
                } else {
                    $current .= $char;
                }
            } else {
                if ($char === $stringChar && $prev !== '\\') {
                    $inString = false;
                } else {
                    $current .= $char;
                }
            }
            
            if ($inString || $char !== ',') {
                // Already handled above
            }
        }
        
        // Last value
        $values[] = $this->cleanValue(trim($current));
        
        return $values;
    }
    
    /**
     * Clean a parsed value
     */
    private function cleanValue(string $val): ?string
    {
        $val = trim($val);
        
        if ($val === 'NULL' || $val === 'null') {
            return null;
        }
        
        // Remove surrounding quotes
        if ((substr($val, 0, 1) === "'" && substr($val, -1) === "'") ||
            (substr($val, 0, 1) === '"' && substr($val, -1) === '"')) {
            $val = substr($val, 1, -1);
        }
        
        // Unescape
        $val = str_replace("\\'", "'", $val);
        $val = str_replace('\\"', '"', $val);
        $val = str_replace('\\\\', '\\', $val);
        
        return $val;
    }
    
    /**
     * Get parsed tables
     */
    public function getTables(): array
    {
        return $this->tables;
    }
    
    /**
     * Get SIM cards mapped to Hybrid format
     */
    public function getSims(): array
    {
        $sims = [];
        foreach ($this->tables['sim_cards'] ?? [] as $row) {
            $sims[] = [
                'bluecard_id' => $row['id'] ?? null,
                'imsi'        => (string)($row['imsi'] ?? ''),
                'msisdn'      => $row['msisdn'] ?? (string)($row['imsi'] ?? ''),
                'auth_key'    => $row['auth_key'] ?? '',
                'auth_opc'    => $row['opc_value'] ?? '',
                'algo'        => $row['algo'] ?? 'Milenage',
                'status'      => $this->mapSimStatus($row['status'] ?? 'In stock'),
                'user_id'     => $row['user_id'] ?? null,
                'created_at'  => $row['created_at'] ?? null,
            ];
        }
        return $sims;
    }
    
    /**
     * Get LTE customers mapped to Hybrid format
     */
    public function getCustomers(): array
    {
        $customers = [];
        $simsByUser = [];
        
        // Index SIMs by user_id
        foreach ($this->tables['sim_cards'] ?? [] as $sim) {
            if (!empty($sim['user_id'])) {
                $simsByUser[$sim['user_id']] = $sim;
            }
        }
        
        foreach ($this->tables['users'] ?? [] as $row) {
            // Skip admin users (type != 3 or mobile doesn't look like IMSI)
            $mobile = $row['mobile'] ?? '';
            if (!preg_match('/^4600000000\d+$/', $mobile)) {
                continue; // Not an LTE customer
            }
            
            $userId = $row['id'] ?? null;
            $sim = $simsByUser[$userId] ?? null;
            
            $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
            if (empty($name) || $name === ' ') {
                $name = $mobile; // Use IMSI as name if no name
            }
            
            $customers[] = [
                'bluecard_id'    => $userId,
                'name'           => $name,
                'phone'          => $row['whatsapp_number'] ?? $row['alternateMobileNo'] ?? null,
                'email'          => $row['email'] ?? null,
                'address'        => $row['address'] ?? $row['house_no'] ?? null,
                'area'           => $row['area'] ?? $row['city'] ?? null,
                'imsi'           => $mobile,
                'msisdn'         => $mobile,
                'auth_key'       => $sim['auth_key'] ?? '',
                'auth_opc'       => $sim['opc_value'] ?? '',
                'status'         => ($row['is_active'] ?? 1) ? 'active' : 'suspended',
                'nationality'    => $row['nationality'] ?? null,
                'id_type'        => $row['POI'] ?? null,
                'id_number'      => $row['aadhar_card_no'] ?? null,
                'created_at'     => $row['created_at'] ?? null,
            ];
        }
        
        return $customers;
    }
    
    /**
     * Get services mapped to subscriptions
     */
    public function getServices(): array
    {
        $services = [];
        foreach ($this->tables['services'] ?? [] as $row) {
            $services[] = [
                'bluecard_id'  => $row['id'] ?? null,
                'user_id'      => $row['user_id'] ?? null,
                'imsi'         => (string)($row['service_id'] ?? ''),
                'offer_id'     => $row['offer_id'] ?? null,
                'created_at'   => $row['created_at'] ?? null,
            ];
        }
        return $services;
    }
    
    /**
     * Get usage data
     */
    public function getUsageData(): array
    {
        $usage = [];
        foreach ($this->tables['data_mgmt'] ?? [] as $row) {
            $usage[] = [
                'user_id'       => $row['user_id'] ?? null,
                'imsi'          => $row['imsi'] ?? null,
                'bytes_used'    => $row['data_used'] ?? $row['usage'] ?? 0,
                'updated_at'    => $row['updated_at'] ?? null,
            ];
        }
        return $usage;
    }
    
    /**
     * Map BlueCard SIM status to Hybrid status
     */
    private function mapSimStatus(string $status): string
    {
        $map = [
            'In stock'         => 'in_stock',
            'Internal usage'   => 'internal',
            'Sold'             => 'sold',
            'Rent'             => 'assigned',
            'Returned'         => 'returned',
            'Assigned'         => 'assigned',
            'Returned Request' => 'return_pending',
        ];
        return $map[$status] ?? 'in_stock';
    }
    
    /**
     * Get summary statistics
     */
    public function getSummary(): array
    {
        return [
            'sim_cards'  => count($this->tables['sim_cards'] ?? []),
            'users'      => count($this->tables['users'] ?? []),
            'services'   => count($this->tables['services'] ?? []),
            'data_mgmt'  => count($this->tables['data_mgmt'] ?? []),
            'customers'  => count($this->getCustomers()),
        ];
    }
    
    /**
     * Import parsed data into staging tables (bluecard_*)
     * Safe import that doesn't affect production LTE tables
     * 
     * @param object $store SqliteStore instance
     * @param string $filename Source filename for logging
     * @return array Import results
     */
    public function importToStagingTables($store, string $filename = ''): array
    {
        $results = [
            'users_imported'    => 0,
            'sims_imported'     => 0,
            'services_imported' => 0,
            'plans_imported'    => 0,
            'topups_imported'   => 0,
            'usage_imported'    => 0,
            'errors'            => [],
        ];
        
        // Create import log entry
        $logId = null;
        try {
            $store->execute(
                "INSERT INTO bluecard_import_log (filename, import_type, started_at, status) 
                 VALUES (?, 'full', datetime('now'), 'running')",
                [$filename]
            );
            $logId = $store->queryValue("SELECT last_insert_rowid()");
        } catch (Exception $e) {
            // Log table may not exist
        }
        
        // Clear existing staging data — all 6 tables cleared inside a single
        // transaction so an insert failure can be rolled back without leaving
        // some tables empty and others still populated (inconsistent state).
        $pdo = $store->getPdo();
        $pdo->beginTransaction();
        try {
            $store->execute("DELETE FROM bluecard_users");
            $store->execute("DELETE FROM bluecard_sims");
            $store->execute("DELETE FROM bluecard_services");
            $store->execute("DELETE FROM bluecard_plans");
            $store->execute("DELETE FROM bluecard_topups");
            $store->execute("DELETE FROM bluecard_usage");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $results['errors'][] = "Clear failed (rolled back): " . $e->getMessage();
            return $results;
        }
        // Transaction stays open — inserts below will commit or rollback together.
        
        // Import Users
        $customers = $this->getCustomers();
        foreach ($customers as $c) {
            try {
                $store->execute(
                    "INSERT INTO bluecard_users (id, name, phone, email, address, area, status, created_at, mapped_to_hybrid)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)",
                    [
                        $c['bluecard_id'],
                        $c['name'],
                        $c['phone'],
                        $c['email'],
                        $c['address'],
                        $c['area'],
                        $c['status'] === 'active' ? 1 : 0,
                        $c['created_at'],
                    ]
                );
                $results['users_imported']++;
            } catch (Exception $e) {
                $results['errors'][] = "User {$c['bluecard_id']}: " . $e->getMessage();
            }
        }
        
        // Import SIMs
        $sims = $this->getSims();
        foreach ($sims as $s) {
            try {
                $store->execute(
                    "INSERT INTO bluecard_sims (id, imsi, msisdn, auth_key, auth_opc, algo, status, user_id, created_at, mapped_to_hybrid)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)",
                    [
                        $s['bluecard_id'],
                        $s['imsi'],
                        $s['msisdn'],
                        $s['auth_key'],
                        $s['auth_opc'],
                        $s['algo'],
                        $s['status'] === 'in_stock' ? 1 : ($s['status'] === 'assigned' ? 2 : 0),
                        $s['user_id'],
                        $s['created_at'],
                    ]
                );
                $results['sims_imported']++;
            } catch (Exception $e) {
                $results['errors'][] = "SIM {$s['imsi']}: " . $e->getMessage();
            }
        }
        
        // Import Services
        $services = $this->getServices();
        foreach ($services as $svc) {
            try {
                $store->execute(
                    "INSERT INTO bluecard_services (id, user_id, plan_id, created_at, status, mapped_to_hybrid)
                     VALUES (?, ?, ?, ?, 1, 0)",
                    [
                        $svc['bluecard_id'],
                        $svc['user_id'],
                        $svc['offer_id'],
                        $svc['created_at'],
                    ]
                );
                $results['services_imported']++;
            } catch (Exception $e) {
                $results['errors'][] = "Service {$svc['bluecard_id']}: " . $e->getMessage();
            }
        }
        
        // Import Usage
        $usage = $this->getUsageData();
        foreach ($usage as $u) {
            try {
                $store->execute(
                    "INSERT INTO bluecard_usage (user_id, imsi, bytes_used, updated_at, mapped_to_hybrid)
                     VALUES (?, ?, ?, ?, 0)",
                    [
                        $u['user_id'],
                        $u['imsi'],
                        $u['bytes_used'],
                        $u['updated_at'],
                    ]
                );
                $results['usage_imported']++;
            } catch (Exception $e) {
                // Ignore usage import errors
            }
        }
        
        // Commit all DELETEs + inserts atomically.
        // If anything above threw, we already rolled back and returned early.
        if ($pdo->inTransaction()) {
            try {
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $results['errors'][] = 'Commit failed (rolled back): ' . $e->getMessage();
                return $results;
            }
        }

        // Update import log
        if ($logId) {
            try {
                $errorsJson = !empty($results['errors']) ? json_encode(array_slice($results['errors'], 0, 50)) : null;
                $store->execute(
                    "UPDATE bluecard_import_log SET 
                        completed_at = datetime('now'),
                        status = 'completed',
                        users_imported = ?,
                        sims_imported = ?,
                        services_imported = ?,
                        usage_imported = ?,
                        errors = ?
                     WHERE id = ?",
                    [
                        $results['users_imported'],
                        $results['sims_imported'],
                        $results['services_imported'],
                        $results['usage_imported'],
                        $errorsJson,
                        $logId,
                    ]
                );
            } catch (Exception $e) {
                // Ignore
            }
        }
        
        return $results;
    }
}

