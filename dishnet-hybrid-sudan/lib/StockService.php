<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }

/**
 * StockService — Unified Equipment & Inventory Management
 * DishNet Hybrid v4.10.0
 *
 * Handles: product catalog, serial-tracked units, bulk quantities,
 *          movements (check-out/in, install, return, transfer),
 *          purchase receipts, agent holdings, stock reports.
 *
 * All tables: stock_categories, stock_units, stock_quantities,
 *             stock_movements, stock_purchases (migration 036).
 *
 * PHP 7.4 compatible. Uses SqliteStore PDO.
 */
class StockService
{
    /** @var \PDO */
    private $db;

    /** @var string */
    private $dataDir;

    // Valid statuses
    const UNIT_STATUSES = ['in_stock', 'checked_out', 'installed', 'returned', 'damaged', 'written_off'];
    const LOCATION_TYPES = ['warehouse', 'field_agent', 'customer', 'transit'];
    const MOVEMENT_TYPES = ['inbound', 'checkout', 'checkin', 'install', 'return', 'transfer', 'adjust', 'write_off'];
    const SERVICE_TYPES = ['starlink', 'fiber', 'lte', 'general'];
    const TRACK_MODES = ['serial', 'quantity'];
    const CONDITIONS = ['new', 'good', 'fair', 'damaged'];

    /** DishNet warehouse locations */
    const LOCATIONS = [
        ['ref' => 'unmiss',  'name' => 'DishNet UNMISS'],
        ['ref' => 'kololo',  'name' => 'DishNet Kololo Office'],
    ];
    const DEFAULT_LOCATION = 'DishNet UNMISS';

    public function __construct(\PDO $db, string $dataDir)
    {
        $this->db = $db;
        $this->dataDir = $dataDir;
    }

    /** Expose DB handle for read-only queries (e.g. export). */
    public function getDb(): \PDO { return $this->db; }

    /**
     * Ensure all stock tables exist (fallback if migration didn't run).
     */
    public function ensureTables(): void
    {
        // v4.11.3: Skip if tables already exist — avoids 14 DDL statements per API call
        $check = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='stock_categories'");
        if ($check->fetch()) return;

        $this->db->exec("CREATE TABLE IF NOT EXISTS stock_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, sku TEXT UNIQUE,
            service_type TEXT NOT NULL DEFAULT 'general', track_mode TEXT NOT NULL DEFAULT 'serial',
            buy_price REAL DEFAULT 0, sell_price REAL DEFAULT 0, min_stock INTEGER DEFAULT 0,
            unit TEXT DEFAULT 'piece', is_active INTEGER DEFAULT 1, description TEXT DEFAULT '',
            image_url TEXT DEFAULT '',
            created_at TEXT NOT NULL)");
        // Add image_url column if missing (existing installs)
        try { $this->db->exec("ALTER TABLE stock_categories ADD COLUMN image_url TEXT DEFAULT ''"); } catch (\Throwable $e) {}

        $this->db->exec("CREATE TABLE IF NOT EXISTS stock_units (
            id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER NOT NULL,
            serial_number TEXT, secondary_serial TEXT DEFAULT '', status TEXT NOT NULL DEFAULT 'in_stock',
            location_type TEXT DEFAULT 'warehouse', location_ref TEXT DEFAULT '', location_name TEXT DEFAULT '',
            condition_grade TEXT DEFAULT 'new', purchase_ref TEXT DEFAULT '', purchase_cost REAL DEFAULT 0,
            crm_client_id INTEGER, crm_service_id INTEGER, job_id INTEGER, assigned_to_rid INTEGER,
            notes TEXT DEFAULT '', starlink_account TEXT DEFAULT '', starlink_status TEXT DEFAULT '',
            lte_imsi TEXT DEFAULT '', lte_msisdn TEXT DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT)");

        $this->db->exec("CREATE TABLE IF NOT EXISTS stock_quantities (
            id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER NOT NULL,
            location_type TEXT DEFAULT 'warehouse', location_ref TEXT DEFAULT '', location_name TEXT DEFAULT '',
            qty_on_hand INTEGER NOT NULL DEFAULT 0, qty_reserved INTEGER DEFAULT 0, updated_at TEXT)");

        $this->db->exec("CREATE TABLE IF NOT EXISTS stock_movements (
            id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER NOT NULL, unit_id INTEGER,
            movement_type TEXT NOT NULL, quantity INTEGER DEFAULT 1,
            from_location_type TEXT DEFAULT '', from_location_ref TEXT DEFAULT '', from_location_name TEXT DEFAULT '',
            to_location_type TEXT NOT NULL DEFAULT '', to_location_ref TEXT NOT NULL DEFAULT '', to_location_name TEXT DEFAULT '',
            reference_type TEXT DEFAULT '', reference_id TEXT DEFAULT '',
            performed_by INTEGER NOT NULL, performed_by_name TEXT DEFAULT '',
            note TEXT DEFAULT '', photo_path TEXT DEFAULT '', created_at TEXT NOT NULL)");

        $this->db->exec("CREATE TABLE IF NOT EXISTS stock_purchases (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier TEXT NOT NULL, invoice_number TEXT DEFAULT '',
            purchase_date TEXT NOT NULL, total_cost REAL DEFAULT 0, currency TEXT DEFAULT 'USD',
            ssp_rate REAL, payment_method TEXT DEFAULT 'cash', received_by INTEGER,
            received_by_name TEXT DEFAULT '', status TEXT DEFAULT 'received', notes TEXT DEFAULT '',
            photo_path TEXT DEFAULT '', cb_ledger_id INTEGER, created_at TEXT NOT NULL)");

        // Indexes (IF NOT EXISTS is safe)
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_su_serial ON stock_units(serial_number)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_su_cat_status ON stock_units(category_id, status)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_su_assigned ON stock_units(assigned_to_rid)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_su_status ON stock_units(status)");
        $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sq_cat_loc ON stock_quantities(category_id, location_type, location_ref)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sm_cat_date ON stock_movements(category_id, created_at)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sm_unit ON stock_movements(unit_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sp_date ON stock_purchases(purchase_date)");
    }

    /**
     * Create from SqliteStore (same pattern as other services).
     */
    public static function fromStore($store, string $dataDir): self
    {
        return new self($store->getPdo(), $dataDir);
    }

    // ═══════════════════════════════════════════════════════════════
    // CATALOG (stock_categories)
    // ═══════════════════════════════════════════════════════════════

    /**
     * List all categories with current stock counts.
     */
    public function getCategories(bool $activeOnly = false): array
    {
        $sql = "SELECT c.*,
                COALESCE(su.serial_total, 0) AS serial_total,
                COALESCE(su.serial_in_stock, 0) AS serial_in_stock,
                COALESCE(su.serial_checked_out, 0) AS serial_checked_out,
                COALESCE(su.serial_installed, 0) AS serial_installed,
                COALESCE(sq.qty_total, 0) AS qty_total
            FROM stock_categories c
            LEFT JOIN (
                SELECT category_id,
                    COUNT(*) AS serial_total,
                    SUM(CASE WHEN status='in_stock' THEN 1 ELSE 0 END) AS serial_in_stock,
                    SUM(CASE WHEN status='checked_out' THEN 1 ELSE 0 END) AS serial_checked_out,
                    SUM(CASE WHEN status='installed' THEN 1 ELSE 0 END) AS serial_installed
                FROM stock_units WHERE status != 'written_off'
                GROUP BY category_id
            ) su ON su.category_id = c.id
            LEFT JOIN (
                SELECT category_id, SUM(qty_on_hand) AS qty_total
                FROM stock_quantities GROUP BY category_id
            ) sq ON sq.category_id = c.id";
        if ($activeOnly) $sql .= " WHERE c.is_active = 1";
        $sql .= " ORDER BY c.service_type, c.title";
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get single category by ID.
     */
    public function getCategory(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM stock_categories WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create or update a category.
     */
    public function saveCategory(array $data): array
    {
        $id = (int)($data['id'] ?? 0);
        $title = trim($data['title'] ?? '');
        if (!$title) throw new \InvalidArgumentException('Title is required');

        $sku = trim($data['sku'] ?? '');
        $serviceType = $data['service_type'] ?? 'general';
        if (!in_array($serviceType, self::SERVICE_TYPES)) $serviceType = 'general';
        $trackMode = $data['track_mode'] ?? 'serial';
        if (!in_array($trackMode, self::TRACK_MODES)) $trackMode = 'serial';

        $fields = [
            'title' => $title,
            'sku' => $sku ?: null,
            'service_type' => $serviceType,
            'track_mode' => $trackMode,
            'buy_price' => (float)($data['buy_price'] ?? 0),
            'sell_price' => (float)($data['sell_price'] ?? 0),
            'min_stock' => (int)($data['min_stock'] ?? 0),
            'unit' => trim($data['unit'] ?? 'piece'),
            'is_active' => (int)($data['is_active'] ?? 1),
            'description' => trim($data['description'] ?? ''),
            'image_url' => trim($data['image_url'] ?? ''),
        ];

        if ($id > 0) {
            // Update
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = "$k = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $this->db->prepare("UPDATE stock_categories SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
            return array_merge(['id' => $id], $fields);
        }

        // Insert
        $fields['created_at'] = date('Y-m-d H:i:s');
        $cols = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $this->db->prepare("INSERT INTO stock_categories ($cols) VALUES ($placeholders)")->execute(array_values($fields));
        return array_merge(['id' => (int)$this->db->lastInsertId()], $fields);
    }

    /** Default product images by SKU (from DishNet website + manufacturers) */
    const DEFAULT_IMAGES = [
        // Starlink — from dishnetafrica.com product pages
        'SL-MINI'      => 'https://dishnetafrica.com/admin/public/uploads/images/1751541009.jpg',
        'SL-STD'       => 'https://dishnetafrica.com/admin/public/uploads/images/1752147097.avif',
        'SL-ACTUATED'  => 'https://dishnetafrica.com/admin/public/uploads/images/1751869933.png',
        'SL-HP'        => 'https://dishnetafrica.com/admin/public/uploads/images/1751869896.jpg',
        // Fiber equipment
        'FB-ONU'       => 'https://m.media-amazon.com/images/I/51+BWZVU2RL._AC_SL1000_.jpg',
        'FB-ROUTER'    => 'https://m.media-amazon.com/images/I/41hT3ioYoWL._AC_SL1000_.jpg',
        // Networking
        'GN-271'       => 'https://m.media-amazon.com/images/I/41hT3ioYoWL._AC_SL1000_.jpg',
        'GN-231'       => 'https://m.media-amazon.com/images/I/31NXTBX5jxL._AC_SL1000_.jpg',
        'GN-232'       => 'https://m.media-amazon.com/images/I/41iWHMu06WL._AC_SL1000_.jpg',
        'GN-233'       => 'https://m.media-amazon.com/images/I/31ixOGo4URL._AC_SL1000_.jpg',
    ];

    /**
     * Get default image URL for a SKU.
     */
    public static function getDefaultImage(string $sku, string $serviceType = ''): string
    {
        if (isset(self::DEFAULT_IMAGES[$sku])) return self::DEFAULT_IMAGES[$sku];
        // Fallback by service type
        if ($serviceType === 'starlink') return self::DEFAULT_IMAGES['SL-MINI'];
        if ($serviceType === 'fiber') return self::DEFAULT_IMAGES['FB-ONU'];
        return '';
    }

    /**
     * Apply default images to all categories that don't have one set.
     */
    public function applyDefaultImages(): int
    {
        $cats = $this->db->query("SELECT id, sku, service_type, image_url FROM stock_categories")->fetchAll(\PDO::FETCH_ASSOC);
        $updated = 0;
        foreach ($cats as $c) {
            if (!empty($c['image_url'])) continue;
            $img = self::getDefaultImage($c['sku'] ?? '', $c['service_type'] ?? '');
            if ($img) {
                $this->db->prepare("UPDATE stock_categories SET image_url = ? WHERE id = ?")->execute([$img, (int)$c['id']]);
                $updated++;
            }
        }
        return $updated;
    }

    /**
     * Seed catalog from kyc_devices.json (first-run only).
     */
    public function seedFromDevices(array $devices): int
    {
        $existing = (int)$this->db->query("SELECT COUNT(*) FROM stock_categories")->fetchColumn();
        if ($existing > 0) return 0; // Already seeded

        $count = 0;
        foreach ($devices as $d) {
            $title = trim($d['title'] ?? $d['name'] ?? '');
            if (!$title) continue;
            $type = $d['type'] ?? 'general';
            // Map kyc_devices type to our service_type
            if (in_array($type, ['starlink', 'starlink_mini', 'starlink_hp'])) $type = 'starlink';
            elseif (in_array($type, ['fiber', 'ftth'])) $type = 'fiber';
            elseif ($type === 'lte') $type = 'lte';
            else $type = 'general';

            // Expensive items = serial tracked, cheap items = quantity
            $sellPrice = (float)($d['sell_price'] ?? 0);
            $trackMode = $sellPrice >= 20 ? 'serial' : 'quantity';

            try {
                $sku = $d['sku'] ?? '';
                $this->saveCategory([
                    'title' => $title,
                    'sku' => $sku,
                    'service_type' => $type,
                    'track_mode' => $trackMode,
                    'buy_price' => (float)($d['buy_price'] ?? $d['cost_ex_vat'] ?? 0),
                    'sell_price' => $sellPrice,
                    'min_stock' => 2,
                    'unit' => 'piece',
                    'description' => $d['description'] ?? '',
                    'image_url' => self::getDefaultImage($sku, $type),
                ]);
                $count++;
            } catch (\Throwable $e) {
                // Skip duplicates
            }
        }
        return $count;
    }

    // ═══════════════════════════════════════════════════════════════
    // UNITS (stock_units — serial tracked)
    // ═══════════════════════════════════════════════════════════════

    /**
     * List units with optional filters.
     */
    public function getUnits(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = "u.category_id = ?";
            $params[] = (int)$filters['category_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = "u.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['service_type'])) {
            $where[] = "c.service_type = ?";
            $params[] = $filters['service_type'];
        }
        if (!empty($filters['location_type'])) {
            $where[] = "u.location_type = ?";
            $params[] = $filters['location_type'];
        }
        if (!empty($filters['assigned_to_rid'])) {
            $where[] = "u.assigned_to_rid = ?";
            $params[] = (int)$filters['assigned_to_rid'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(u.serial_number LIKE ? OR u.secondary_serial LIKE ? OR u.location_name LIKE ? OR u.notes LIKE ?)";
            $term = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$term, $term, $term, $term]);
        }

        $w = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM stock_units u JOIN stock_categories c ON c.id = u.category_id WHERE $w";
        $st = $this->db->prepare($countSql);
        $st->execute($params);
        $total = (int)$st->fetchColumn();

        $sql = "SELECT u.*, c.title AS category_name, c.sku AS category_sku,
                       c.service_type, c.track_mode
                FROM stock_units u
                JOIN stock_categories c ON c.id = u.category_id
                WHERE $w
                ORDER BY u.updated_at DESC, u.id DESC
                LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        return ['items' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * Get single unit with full movement history.
     */
    public function getUnitDetail(int $unitId): ?array
    {
        $st = $this->db->prepare("SELECT u.*, c.title AS category_name, c.sku AS category_sku,
                                         c.service_type, c.track_mode
                                  FROM stock_units u
                                  JOIN stock_categories c ON c.id = u.category_id
                                  WHERE u.id = ?");
        $st->execute([$unitId]);
        $unit = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$unit) return null;

        $st2 = $this->db->prepare("SELECT * FROM stock_movements WHERE unit_id = ? ORDER BY created_at DESC");
        $st2->execute([$unitId]);
        $unit['movements'] = $st2->fetchAll(\PDO::FETCH_ASSOC);

        return $unit;
    }

    /**
     * Create a new unit (single serial-tracked item).
     */
    public function createUnit(array $data, int $performedBy, string $performerName): array
    {
        $catId = (int)($data['category_id'] ?? 0);
        $cat = $this->getCategory($catId);
        if (!$cat) throw new \InvalidArgumentException('Invalid category');
        if ($cat['track_mode'] !== 'serial') throw new \InvalidArgumentException('Category is quantity-tracked, not serial');

        $serial = trim($data['serial_number'] ?? '');
        if (!$serial) throw new \InvalidArgumentException('Serial number required for tracked items');

        // Check duplicate
        $dup = $this->db->prepare("SELECT id FROM stock_units WHERE serial_number = ? LIMIT 1");
        $dup->execute([$serial]);
        if ($dup->fetch()) throw new \InvalidArgumentException("Serial number '{$serial}' already exists");

        $now = date('Y-m-d H:i:s');
        $fields = [
            'category_id' => $catId,
            'serial_number' => $serial,
            'secondary_serial' => trim($data['secondary_serial'] ?? ''),
            'status' => 'in_stock',
            'location_type' => 'warehouse',
            'location_ref' => trim($data['location_ref'] ?? 'main'),
            'location_name' => trim($data['location_name'] ?? self::DEFAULT_LOCATION),
            'condition_grade' => $data['condition_grade'] ?? 'new',
            'purchase_ref' => trim($data['purchase_ref'] ?? ''),
            'purchase_cost' => (float)($data['purchase_cost'] ?? $cat['buy_price'] ?? 0),
            'notes' => trim($data['notes'] ?? ''),
            'starlink_account' => trim($data['starlink_account'] ?? ''),
            'starlink_status' => trim($data['starlink_status'] ?? ''),
            'lte_imsi' => trim($data['lte_imsi'] ?? ''),
            'lte_msisdn' => trim($data['lte_msisdn'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $cols = implode(', ', array_keys($fields));
        $ph = implode(', ', array_fill(0, count($fields), '?'));
        $this->db->prepare("INSERT INTO stock_units ($cols) VALUES ($ph)")->execute(array_values($fields));
        $unitId = (int)$this->db->lastInsertId();

        // Log inbound movement
        $this->logMovement([
            'category_id' => $catId,
            'unit_id' => $unitId,
            'movement_type' => 'inbound',
            'to_location_type' => 'warehouse',
            'to_location_ref' => $fields['location_ref'],
            'to_location_name' => $fields['location_name'],
            'reference_type' => $data['reference_type'] ?? 'manual',
            'reference_id' => $data['reference_id'] ?? '',
            'performed_by' => $performedBy,
            'performed_by_name' => $performerName,
            'note' => $data['inbound_note'] ?? 'Initial stock entry',
        ]);

        return array_merge(['id' => $unitId], $fields);
    }

    /**
     * Update a stock unit (serial, cost, location, condition, notes).
     * Only allowed for in_stock items. Logged in movements as 'adjustment'.
     */
    public function updateUnit(int $unitId, array $data, int $performedBy, string $performerName): array
    {
        $unit = $this->getUnitRow($unitId);
        if (!$unit) throw new \InvalidArgumentException('Unit not found');
        if ($unit['status'] !== 'in_stock') throw new \InvalidArgumentException('Can only edit items that are In Stock');

        $now = date('Y-m-d H:i:s');
        $changes = [];

        // Allowed fields to update
        $allowed = [
            'serial_number'    => 'string',
            'secondary_serial' => 'string',
            'category_id'      => 'int',
            'purchase_cost'    => 'float',
            'condition_grade'  => 'string',
            'location_name'    => 'string',
            'location_ref'     => 'string',
            'notes'            => 'string',
            'starlink_account' => 'string',
        ];

        $sets = [];
        $vals = [];
        foreach ($allowed as $field => $type) {
            if (!array_key_exists($field, $data)) continue;
            $old = $unit[$field] ?? '';
            if ($type === 'int') $val = (int)$data[$field];
            elseif ($type === 'float') $val = (float)$data[$field];
            else $val = trim($data[$field] ?? '');
            if ((string)$old !== (string)$val) {
                $changes[] = "{$field}: {$old} → {$val}";
                $sets[] = "{$field} = ?";
                $vals[] = $val;
            }
        }

        if (empty($sets)) return array_merge($unit, ['message' => 'No changes']);

        // Check serial duplicate if serial changed
        if (isset($data['serial_number']) && $data['serial_number'] !== $unit['serial_number']) {
            $dup = $this->db->prepare("SELECT id FROM stock_units WHERE serial_number = ? AND id != ? LIMIT 1");
            $dup->execute([trim($data['serial_number']), $unitId]);
            if ($dup->fetch()) throw new \InvalidArgumentException("Serial '{$data['serial_number']}' already exists");
        }

        $sets[] = "updated_at = ?";
        $vals[] = $now;
        $vals[] = $unitId;

        $this->db->prepare("UPDATE stock_units SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);

        $this->logMovement([
            'category_id' => $unit['category_id'],
            'unit_id' => $unitId,
            'movement_type' => 'adjustment',
            'to_location_type' => 'warehouse',
            'to_location_name' => $data['location_name'] ?? $unit['location_name'] ?? '',
            'performed_by' => $performedBy,
            'performed_by_name' => $performerName,
            'note' => 'Edit: ' . implode('; ', $changes),
        ]);

        return array_merge($this->getUnitRow($unitId), ['changes' => $changes]);
    }

    /**
     * Delete a stock unit. Only allowed for in_stock items.
     * Logs as 'void' movement for audit trail.
     */
    public function deleteUnit(int $unitId, int $performedBy, string $performerName, string $reason = ''): array
    {
        $unit = $this->getUnitRow($unitId);
        if (!$unit) throw new \InvalidArgumentException('Unit not found');
        if (!in_array($unit['status'], ['in_stock', 'returned'], true)) {
            throw new \InvalidArgumentException('Can only delete items that are In Stock or Returned. Status: ' . $unit['status']);
        }

        // Log the deletion first (audit trail)
        $this->logMovement([
            'category_id' => $unit['category_id'],
            'unit_id' => $unitId,
            'movement_type' => 'void',
            'from_location_type' => $unit['location_type'] ?? 'warehouse',
            'from_location_name' => $unit['location_name'] ?? '',
            'performed_by' => $performedBy,
            'performed_by_name' => $performerName,
            'note' => $reason ? "Deleted: {$reason}" : 'Deleted: wrong scan / mistake',
        ]);

        $this->db->prepare("DELETE FROM stock_units WHERE id = ?")->execute([$unitId]);

        return ['deleted' => true, 'unit_id' => $unitId, 'serial' => $unit['serial_number'] ?? ''];
    }

    // ═══════════════════════════════════════════════════════════════
    // QUANTITIES (stock_quantities — bulk tracked)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get quantity levels for a category (or all).
     */
    public function getQuantities(?int $categoryId = null): array
    {
        $sql = "SELECT q.*, c.title AS category_name, c.sku AS category_sku, c.service_type, c.unit, c.min_stock
                FROM stock_quantities q
                JOIN stock_categories c ON c.id = q.category_id";
        $params = [];
        if ($categoryId) {
            $sql .= " WHERE q.category_id = ?";
            $params[] = $categoryId;
        }
        $sql .= " ORDER BY c.title, q.location_name";
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Adjust quantity (add or remove stock).
     */
    public function adjustQuantity(int $categoryId, int $delta, array $data, int $performedBy, string $performerName): array
    {
        $cat = $this->getCategory($categoryId);
        if (!$cat) throw new \InvalidArgumentException('Invalid category');
        if ($cat['track_mode'] !== 'quantity') throw new \InvalidArgumentException('Category is serial-tracked, not quantity');

        $locType = $data['location_type'] ?? 'warehouse';
        $locRef = trim($data['location_ref'] ?? 'main');
        $locName = trim($data['location_name'] ?? self::DEFAULT_LOCATION);
        $now = date('Y-m-d H:i:s');

        // Upsert quantity row
        $this->db->prepare("INSERT INTO stock_quantities (category_id, location_type, location_ref, location_name, qty_on_hand, updated_at)
            VALUES (?, ?, ?, ?, 0, ?)
            ON CONFLICT(category_id, location_type, location_ref) DO UPDATE SET location_name = excluded.location_name")
            ->execute([$categoryId, $locType, $locRef, $locName, $now]);

        // Apply delta
        $this->db->prepare("UPDATE stock_quantities SET qty_on_hand = MAX(0, qty_on_hand + ?), updated_at = ?
            WHERE category_id = ? AND location_type = ? AND location_ref = ?")
            ->execute([$delta, $now, $categoryId, $locType, $locRef]);

        // Log movement
        $movType = $delta > 0 ? 'inbound' : 'adjust';
        if (($data['movement_type'] ?? '') === 'checkout') $movType = 'checkout';
        if (($data['movement_type'] ?? '') === 'install') $movType = 'install';

        $this->logMovement([
            'category_id' => $categoryId,
            'movement_type' => $movType,
            'quantity' => abs($delta),
            'to_location_type' => $locType,
            'to_location_ref' => $locRef,
            'to_location_name' => $locName,
            'reference_type' => $data['reference_type'] ?? 'manual',
            'reference_id' => $data['reference_id'] ?? '',
            'performed_by' => $performedBy,
            'performed_by_name' => $performerName,
            'note' => $data['note'] ?? ($delta > 0 ? 'Stock added' : 'Stock adjusted'),
        ]);

        // Return updated row
        $st = $this->db->prepare("SELECT * FROM stock_quantities WHERE category_id = ? AND location_type = ? AND location_ref = ?");
        $st->execute([$categoryId, $locType, $locRef]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    // ═══════════════════════════════════════════════════════════════
    // MOVEMENTS (the core actions)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Check out a unit to a field agent.
     */
    public function checkout(int $unitId, int $agentRid, string $agentName, int $performedBy, string $performerName, string $note = ''): array
    {
        $unit = $this->getUnitRow($unitId);
        if (!$unit) throw new \InvalidArgumentException('Unit not found');
        if ($unit['status'] !== 'in_stock' && $unit['status'] !== 'returned') {
            throw new \InvalidArgumentException("Cannot check out: unit status is '{$unit['status']}'");
        }

        $now = date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE stock_units SET status = 'checked_out', location_type = 'field_agent',
            location_ref = ?, location_name = ?, assigned_to_rid = ?, updated_at = ? WHERE id = ?")
            ->execute([(string)$agentRid, $agentName, $agentRid, $now, $unitId]);

        $this->logMovement([
            'category_id' => $unit['category_id'],
            'unit_id' => $unitId,
            'movement_type' => 'checkout',
            'from_location_type' => $unit['location_type'],
            'from_location_ref' => $unit['location_ref'],
            'from_location_name' => $unit['location_name'],
            'to_location_type' => 'field_agent',
            'to_location_ref' => (string)$agentRid,
            'to_location_name' => $agentName,
            'performed_by' => $performedBy,
            'performed_by_name' => $performerName,
            'note' => $note ?: "Checked out to {$agentName}",
        ]);

        return $this->getUnitRow($unitId);
    }

    /**
     * Check in (return to warehouse) a unit from field agent.
     */
    public function checkin(int $unitId, int $performedBy, string $performerName, string $condition = 'good', string $note = ''): array
    {
        $unit = $this->getUnitRow($unitId);
        if (!$unit) throw new \InvalidArgumentException('Unit not found');
        if ($unit['status'] !== 'checked_out') {
            throw new \InvalidArgumentException("Cannot check in: unit status is '{$unit['status']}'");
        }

        $newStatus = in_array($condition, ['damaged']) ? 'damaged' : 'in_stock';
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE stock_units SET status = ?, location_type = 'warehouse',
            location_ref = 'main', location_name = ?,
            assigned_to_rid = NULL, condition_grade = ?, updated_at = ? WHERE id = ?")
            ->execute([$newStatus, self::DEFAULT_LOCATION, $condition, $now, $unitId]);

        $this->logMovement([
            'category_id' => $unit['category_id'],
            'unit_id' => $unitId,
            'movement_type' => 'checkin',
            'from_location_type' => 'field_agent',
            'from_location_ref' => $unit['location_ref'],
            'from_location_name' => $unit['location_name'],
            'to_location_type' => 'warehouse',
            'to_location_ref' => 'main',
            'to_location_name' => self::DEFAULT_LOCATION,
            'performed_by' => $performedBy,
            'performed_by_name' => $performerName,
            'note' => $note ?: "Returned to warehouse (condition: {$condition})",
        ]);

        return $this->getUnitRow($unitId);
    }

    /**
     * Mark unit as installed at customer site.
     */
    public function install(int $unitId, array $data, int $performedBy, string $performerName): array
    {
        $unit = $this->getUnitRow($unitId);
        if (!$unit) throw new \InvalidArgumentException('Unit not found');
        if (!in_array($unit['status'], ['in_stock', 'checked_out'])) {
            throw new \InvalidArgumentException("Cannot install: unit status is '{$unit['status']}'");
        }

        $crmClientId = (int)($data['crm_client_id'] ?? 0);
        $clientName = trim($data['client_name'] ?? 'Customer');
        $now = date('Y-m-d H:i:s');

        $this->db->prepare("UPDATE stock_units SET status = 'installed', location_type = 'customer',
            location_ref = ?, location_name = ?, crm_client_id = ?, crm_service_id = ?,
            job_id = ?, assigned_to_rid = NULL, updated_at = ? WHERE id = ?")
            ->execute([
                (string)$crmClientId, $clientName, $crmClientId ?: null,
                (int)($data['crm_service_id'] ?? 0) ?: null,
                (int)($data['job_id'] ?? 0) ?: null, $now, $unitId
            ]);

        $this->logMovement([
            'category_id' => $unit['category_id'],
            'unit_id' => $unitId,
            'movement_type' => 'install',
            'from_location_type' => $unit['location_type'],
            'from_location_ref' => $unit['location_ref'],
            'from_location_name' => $unit['location_name'],
            'to_location_type' => 'customer',
            'to_location_ref' => (string)$crmClientId,
            'to_location_name' => $clientName,
            'reference_type' => $data['reference_type'] ?? 'job',
            'reference_id' => $data['reference_id'] ?? ($data['job_id'] ?? ''),
            'performed_by' => $performedBy,
            'performed_by_name' => $performerName,
            'note' => $data['note'] ?? "Installed at {$clientName}",
        ]);

        return $this->getUnitRow($unitId);
    }

    /**
     * Customer return — equipment retrieved from customer back to warehouse.
     */
    public function returnUnit(int $unitId, int $performedBy, string $performerName, string $condition = 'good', string $note = ''): array
    {
        $unit = $this->getUnitRow($unitId);
        if (!$unit) throw new \InvalidArgumentException('Unit not found');
        if ($unit['status'] !== 'installed') {
            throw new \InvalidArgumentException("Cannot return: unit status is '{$unit['status']}'");
        }

        $newStatus = $condition === 'damaged' ? 'damaged' : 'returned';
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE stock_units SET status = ?, location_type = 'warehouse',
            location_ref = 'main', location_name = ?,
            crm_client_id = NULL, crm_service_id = NULL, job_id = NULL,
            assigned_to_rid = NULL, condition_grade = ?, updated_at = ? WHERE id = ?")
            ->execute([$newStatus, self::DEFAULT_LOCATION, $condition, $now, $unitId]);

        $this->logMovement([
            'category_id' => $unit['category_id'],
            'unit_id' => $unitId,
            'movement_type' => 'return',
            'from_location_type' => 'customer',
            'from_location_ref' => $unit['location_ref'],
            'from_location_name' => $unit['location_name'],
            'to_location_type' => 'warehouse',
            'to_location_ref' => 'main',
            'to_location_name' => self::DEFAULT_LOCATION,
            'performed_by' => $performedBy,
            'performed_by_name' => $performerName,
            'note' => $note ?: "Retrieved from customer (condition: {$condition})",
        ]);

        return $this->getUnitRow($unitId);
    }

    /**
     * Write off a unit (damaged, lost, stolen).
     */
    public function writeOff(int $unitId, int $performedBy, string $performerName, string $reason = ''): array
    {
        $unit = $this->getUnitRow($unitId);
        if (!$unit) throw new \InvalidArgumentException('Unit not found');

        $now = date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE stock_units SET status = 'written_off', updated_at = ? WHERE id = ?")->execute([$now, $unitId]);

        $this->logMovement([
            'category_id' => $unit['category_id'],
            'unit_id' => $unitId,
            'movement_type' => 'write_off',
            'from_location_type' => $unit['location_type'],
            'from_location_ref' => $unit['location_ref'],
            'from_location_name' => $unit['location_name'],
            'performed_by' => $performedBy,
            'performed_by_name' => $performerName,
            'note' => $reason ?: 'Written off',
        ]);

        return $this->getUnitRow($unitId);
    }

    /**
     * Transfer unit between locations (agent-to-agent or to warehouse).
     */
    public function transfer(int $unitId, array $destination, int $performedBy, string $performerName, string $note = ''): array
    {
        $unit = $this->getUnitRow($unitId);
        if (!$unit) throw new \InvalidArgumentException('Unit not found');
        if (in_array($unit['status'], ['written_off', 'installed'])) {
            throw new \InvalidArgumentException("Cannot transfer: unit status is '{$unit['status']}'");
        }

        $toType = $destination['location_type'] ?? 'warehouse';
        $toRef = trim($destination['location_ref'] ?? 'main');
        $toName = trim($destination['location_name'] ?? self::DEFAULT_LOCATION);
        $newStatus = $toType === 'field_agent' ? 'checked_out' : 'in_stock';
        $assignedRid = $toType === 'field_agent' ? (int)$toRef : null;

        $now = date('Y-m-d H:i:s');
        $this->db->prepare("UPDATE stock_units SET status = ?, location_type = ?, location_ref = ?,
            location_name = ?, assigned_to_rid = ?, updated_at = ? WHERE id = ?")
            ->execute([$newStatus, $toType, $toRef, $toName, $assignedRid, $now, $unitId]);

        $this->logMovement([
            'category_id' => $unit['category_id'],
            'unit_id' => $unitId,
            'movement_type' => 'transfer',
            'from_location_type' => $unit['location_type'],
            'from_location_ref' => $unit['location_ref'],
            'from_location_name' => $unit['location_name'],
            'to_location_type' => $toType,
            'to_location_ref' => $toRef,
            'to_location_name' => $toName,
            'performed_by' => $performedBy,
            'performed_by_name' => $performerName,
            'note' => $note ?: "Transferred to {$toName}",
        ]);

        return $this->getUnitRow($unitId);
    }

    // ═══════════════════════════════════════════════════════════════
    // PURCHASES
    // ═══════════════════════════════════════════════════════════════

    /**
     * Record a purchase (supplier inbound).
     */
    public function createPurchase(array $data, int $receivedBy, string $receiverName): array
    {
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("INSERT INTO stock_purchases
            (supplier, invoice_number, purchase_date, total_cost, currency, ssp_rate,
             payment_method, received_by, received_by_name, status, notes, photo_path, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'received', ?, ?, ?)")
            ->execute([
                trim($data['supplier'] ?? ''),
                trim($data['invoice_number'] ?? ''),
                $data['purchase_date'] ?? date('Y-m-d'),
                (float)($data['total_cost'] ?? 0),
                $data['currency'] ?? 'USD',
                !empty($data['ssp_rate']) ? (float)$data['ssp_rate'] : null,
                $data['payment_method'] ?? 'cash',
                $receivedBy, $receiverName,
                trim($data['notes'] ?? ''),
                trim($data['photo_path'] ?? ''),
                $now,
            ]);

        return ['id' => (int)$this->db->lastInsertId()];
    }

    /**
     * List purchases.
     */
    public function getPurchases(int $limit = 50, int $offset = 0): array
    {
        $total = (int)$this->db->query("SELECT COUNT(*) FROM stock_purchases")->fetchColumn();
        $st = $this->db->prepare("SELECT * FROM stock_purchases ORDER BY purchase_date DESC, id DESC LIMIT ? OFFSET ?");
        $st->execute([$limit, $offset]);
        return ['items' => $st->fetchAll(\PDO::FETCH_ASSOC), 'total' => $total];
    }

    // ═══════════════════════════════════════════════════════════════
    // REPORTS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Dashboard summary stats.
     */
    public function getDashboardStats(): array
    {
        $stats = [];

        // Serial counts by status
        $st = $this->db->query("SELECT status, COUNT(*) AS cnt FROM stock_units GROUP BY status");
        $byStatus = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $byStatus[$r['status']] = (int)$r['cnt'];
        $stats['serial_by_status'] = $byStatus;
        $stats['total_serial'] = array_sum($byStatus);
        $stats['in_stock'] = ($byStatus['in_stock'] ?? 0) + ($byStatus['returned'] ?? 0);
        $stats['checked_out'] = $byStatus['checked_out'] ?? 0;
        $stats['installed'] = $byStatus['installed'] ?? 0;
        $stats['damaged'] = $byStatus['damaged'] ?? 0;

        // Total stock value (in_stock units)
        $val = $this->db->query("SELECT COALESCE(SUM(purchase_cost), 0) FROM stock_units WHERE status IN ('in_stock','returned')")->fetchColumn();
        $stats['stock_value'] = round((float)$val, 2);

        // Installed value
        $ival = $this->db->query("SELECT COALESCE(SUM(purchase_cost), 0) FROM stock_units WHERE status = 'installed'")->fetchColumn();
        $stats['installed_value'] = round((float)$ival, 2);

        // Quantity items
        $qt = $this->db->query("SELECT COALESCE(SUM(qty_on_hand), 0) FROM stock_quantities")->fetchColumn();
        $stats['total_qty_items'] = (int)$qt;

        // Low stock alerts
        $low = $this->db->query("SELECT c.id, c.title, c.min_stock, c.track_mode,
                COALESCE(su.cnt, 0) AS serial_avail,
                COALESCE(sq.qty, 0) AS qty_avail
            FROM stock_categories c
            LEFT JOIN (SELECT category_id, COUNT(*) AS cnt FROM stock_units
                       WHERE status IN ('in_stock','returned') GROUP BY category_id) su
                ON su.category_id = c.id
            LEFT JOIN (SELECT category_id, SUM(qty_on_hand) AS qty FROM stock_quantities GROUP BY category_id) sq
                ON sq.category_id = c.id
            WHERE c.is_active = 1 AND c.min_stock > 0
            AND (
                (c.track_mode = 'serial' AND COALESCE(su.cnt, 0) < c.min_stock) OR
                (c.track_mode = 'quantity' AND COALESCE(sq.qty, 0) < c.min_stock)
            )
            ORDER BY c.title")->fetchAll(\PDO::FETCH_ASSOC);
        $stats['low_stock_alerts'] = $low;

        // Categories count
        $stats['category_count'] = (int)$this->db->query("SELECT COUNT(*) FROM stock_categories WHERE is_active = 1")->fetchColumn();

        // Recent movements (last 7 days)
        $weekAgo = date('Y-m-d', strtotime('-7 days'));
        $recent = $this->db->prepare("SELECT movement_type, COUNT(*) AS cnt FROM stock_movements WHERE created_at >= ? GROUP BY movement_type");
        $recent->execute([$weekAgo]);
        $stats['movements_7d'] = [];
        foreach ($recent->fetchAll(\PDO::FETCH_ASSOC) as $r) $stats['movements_7d'][$r['movement_type']] = (int)$r['cnt'];

        // By service type
        $bySvc = $this->db->query("SELECT c.service_type, COUNT(*) AS cnt
            FROM stock_units u JOIN stock_categories c ON c.id = u.category_id
            WHERE u.status IN ('in_stock','returned')
            GROUP BY c.service_type")->fetchAll(\PDO::FETCH_ASSOC);
        $stats['in_stock_by_service'] = [];
        foreach ($bySvc as $r) $stats['in_stock_by_service'][$r['service_type']] = (int)$r['cnt'];

        return $stats;
    }

    /**
     * Agent holdings — what each field agent currently holds.
     */
    public function getAgentHoldings(?int $agentRid = null): array
    {
        $sql = "SELECT u.*, c.title AS category_name, c.sku AS category_sku, c.service_type
                FROM stock_units u
                JOIN stock_categories c ON c.id = u.category_id
                WHERE u.status = 'checked_out' AND u.assigned_to_rid IS NOT NULL";
        $params = [];
        if ($agentRid) {
            $sql .= " AND u.assigned_to_rid = ?";
            $params[] = $agentRid;
        }
        $sql .= " ORDER BY u.location_name, c.title";
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        // Group by agent
        $agents = [];
        foreach ($rows as $r) {
            $rid = (int)$r['assigned_to_rid'];
            if (!isset($agents[$rid])) {
                $agents[$rid] = [
                    'rid' => $rid,
                    'name' => $r['location_name'],
                    'items' => [],
                    'total_value' => 0,
                ];
            }
            $agents[$rid]['items'][] = $r;
            $agents[$rid]['total_value'] += (float)$r['purchase_cost'];
        }

        return array_values($agents);
    }

    /**
     * Movement log with filters.
     */
    public function getMovements(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['movement_type'])) {
            $where[] = "m.movement_type = ?";
            $params[] = $filters['movement_type'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = "m.category_id = ?";
            $params[] = (int)$filters['category_id'];
        }
        if (!empty($filters['performed_by'])) {
            $where[] = "m.performed_by = ?";
            $params[] = (int)$filters['performed_by'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "m.created_at >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "m.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[] = "(u.serial_number LIKE ? OR m.to_location_name LIKE ? OR m.from_location_name LIKE ? OR m.note LIKE ? OR m.performed_by_name LIKE ?)";
            $term = '%' . $filters['search'] . '%';
            $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
        }

        $w = implode(' AND ', $where);

        // COUNT query needs the same JOINs as SELECT (search filter uses u.serial_number)
        $countSql = "SELECT COUNT(*) FROM stock_movements m
            JOIN stock_categories c ON c.id = m.category_id
            LEFT JOIN stock_units u ON u.id = m.unit_id
            WHERE $w";
        $cst = $this->db->prepare($countSql);
        $cst->execute($params);
        $total = (int)$cst->fetchColumn();

        $params[] = $limit;
        $params[] = $offset;
        $st = $this->db->prepare("SELECT m.*, c.title AS category_name, u.serial_number
            FROM stock_movements m
            JOIN stock_categories c ON c.id = m.category_id
            LEFT JOIN stock_units u ON u.id = m.unit_id
            WHERE $w ORDER BY m.created_at DESC, m.id DESC LIMIT ? OFFSET ?");
        $st->execute($params);

        return ['items' => $st->fetchAll(\PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * CSV export of units.
     */
    public function exportUnitsCsv(array $filters = []): string
    {
        $result = $this->getUnits($filters, 10000, 0);
        $lines = ["Serial Number,Category,SKU,Service,Status,Location,Condition,Customer CRM ID,Purchase Cost,Notes,Updated"];
        foreach ($result['items'] as $r) {
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $r['serial_number'] ?? '') . '"',
                '"' . str_replace('"', '""', $r['category_name'] ?? '') . '"',
                '"' . ($r['category_sku'] ?? '') . '"',
                $r['service_type'] ?? '',
                $r['status'] ?? '',
                '"' . str_replace('"', '""', $r['location_name'] ?? '') . '"',
                $r['condition_grade'] ?? '',
                $r['crm_client_id'] ?? '',
                number_format((float)($r['purchase_cost'] ?? 0), 2, '.', ''),
                '"' . str_replace('"', '""', $r['notes'] ?? '') . '"',
                $r['updated_at'] ?? '',
            ]);
        }
        return implode("\n", $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // INTEGRATION: KYC + Scheduling auto-deduct
    // ═══════════════════════════════════════════════════════════════

    /**
     * Check stock availability for a KYC hw_cart.
     * Returns per-item availability: [['title'=>..., 'available'=>N, 'needed'=>N, 'ok'=>bool], ...]
     * Non-blocking: always returns results, never throws.
     */
    public function checkAvailability(array $hwCartItems): array
    {
        $results = [];
        foreach ($hwCartItems as $item) {
            $title = trim($item['title'] ?? '');
            $needed = max(1, (int)($item['qty'] ?? 1));
            if (!$title) continue;

            // Find matching category by title (case-insensitive)
            $cat = $this->findCategoryByTitle($title);
            if (!$cat) {
                $results[] = ['title' => $title, 'needed' => $needed, 'available' => null, 'ok' => true, 'note' => 'Not tracked in stock'];
                continue;
            }

            $available = $this->getCategoryAvailable($cat);
            $results[] = [
                'title'     => $title,
                'needed'    => $needed,
                'available' => $available,
                'ok'        => $available >= $needed,
                'category_id' => (int)$cat['id'],
                'track_mode'  => $cat['track_mode'],
            ];
        }
        return $results;
    }

    /**
     * Auto-deduct stock for a completed installation job.
     *
     * For quantity items: decrement stock_quantities at warehouse.
     * For serial items: if agent has a checked-out unit in that category, mark it installed.
     *   If no checked-out unit found, log a movement but don't block.
     *
     * @return array Summary of what was deducted
     */
    public function deductForInstallation(array $hwCartItems, int $crmClientId, string $clientName,
                                          int $jobId, int $performedBy, string $performerName): array
    {
        $summary = [];
        $now = date('Y-m-d H:i:s');

        foreach ($hwCartItems as $item) {
            $title = trim($item['title'] ?? '');
            $needed = max(1, (int)($item['qty'] ?? 1));
            if (!$title) continue;

            $cat = $this->findCategoryByTitle($title);
            if (!$cat) {
                $summary[] = ['title' => $title, 'action' => 'skipped', 'reason' => 'No stock category found'];
                continue;
            }

            $catId = (int)$cat['id'];

            if ($cat['track_mode'] === 'quantity') {
                // Deduct from warehouse quantity
                try {
                    $this->adjustQuantity($catId, -$needed, [
                        'movement_type' => 'install',
                        'reference_type' => 'job',
                        'reference_id' => (string)$jobId,
                        'note' => "Auto-deduct: installed for {$clientName} (CRM #{$crmClientId})",
                    ], $performedBy, $performerName);
                    $summary[] = ['title' => $title, 'action' => 'deducted', 'qty' => $needed, 'type' => 'quantity'];
                } catch (\Throwable $e) {
                    $summary[] = ['title' => $title, 'action' => 'failed', 'reason' => $e->getMessage()];
                }
            } else {
                // Serial tracked: find agent's checked-out unit in this category, or any in_stock unit
                $deducted = 0;
                for ($i = 0; $i < $needed; $i++) {
                    $unit = $this->findUnitForInstall($catId, $performedBy);
                    if ($unit) {
                        try {
                            $this->install($unit['id'], [
                                'crm_client_id' => $crmClientId,
                                'client_name' => $clientName,
                                'job_id' => $jobId,
                                'reference_type' => 'job',
                                'reference_id' => (string)$jobId,
                                'note' => 'Auto-deduct on job completion',
                            ], $performedBy, $performerName);
                            $deducted++;
                        } catch (\Throwable $e) {
                            // Skip this unit, try next
                        }
                    }
                }
                if ($deducted > 0) {
                    $summary[] = ['title' => $title, 'action' => 'installed', 'qty' => $deducted, 'type' => 'serial'];
                } else {
                    $summary[] = ['title' => $title, 'action' => 'pending', 'reason' => 'No available unit found — assign manually in Stock Management'];
                }
            }
        }
        return $summary;
    }

    /**
     * Find a stock category by matching title (case-insensitive).
     */
    private function findCategoryByTitle(string $title): ?array
    {
        $st = $this->db->prepare("SELECT * FROM stock_categories WHERE LOWER(title) = LOWER(?) AND is_active = 1 LIMIT 1");
        $st->execute([$title]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get available count for a category.
     */
    private function getCategoryAvailable(array $cat): int
    {
        if ($cat['track_mode'] === 'serial') {
            $st = $this->db->prepare("SELECT COUNT(*) FROM stock_units WHERE category_id = ? AND status IN ('in_stock','returned')");
            $st->execute([(int)$cat['id']]);
            return (int)$st->fetchColumn();
        } else {
            $st = $this->db->prepare("SELECT COALESCE(SUM(qty_on_hand),0) FROM stock_quantities WHERE category_id = ?");
            $st->execute([(int)$cat['id']]);
            return (int)$st->fetchColumn();
        }
    }

    /**
     * Find a unit suitable for installation:
     * 1. First: unit checked out to this specific agent
     * 2. Fallback: any in_stock unit in the category
     */
    private function findUnitForInstall(int $categoryId, int $agentRid): ?array
    {
        // Priority 1: checked out to this agent
        $st = $this->db->prepare("SELECT * FROM stock_units WHERE category_id = ? AND status = 'checked_out' AND assigned_to_rid = ? LIMIT 1");
        $st->execute([$categoryId, $agentRid]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row) return $row;

        // Priority 2: any available in stock
        $st2 = $this->db->prepare("SELECT * FROM stock_units WHERE category_id = ? AND status IN ('in_stock','returned') LIMIT 1");
        $st2->execute([$categoryId]);
        $row2 = $st2->fetch(\PDO::FETCH_ASSOC);
        return $row2 ?: null;
    }

    // ═══════════════════════════════════════════════════════════════
    // INTERNAL HELPERS
    // ═══════════════════════════════════════════════════════════════

    private function getUnitRow(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM stock_units WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function logMovement(array $data): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("INSERT INTO stock_movements
            (category_id, unit_id, movement_type, quantity,
             from_location_type, from_location_ref, from_location_name,
             to_location_type, to_location_ref, to_location_name,
             reference_type, reference_id, performed_by, performed_by_name, note, photo_path, created_at)
            VALUES (?,?,?,?, ?,?,?, ?,?,?, ?,?,?,?,?,?,?)")
            ->execute([
                (int)($data['category_id'] ?? 0),
                !empty($data['unit_id']) ? (int)$data['unit_id'] : null,
                $data['movement_type'] ?? 'adjust',
                (int)($data['quantity'] ?? 1),
                $data['from_location_type'] ?? '',
                $data['from_location_ref'] ?? '',
                $data['from_location_name'] ?? '',
                $data['to_location_type'] ?? '',
                $data['to_location_ref'] ?? '',
                $data['to_location_name'] ?? '',
                $data['reference_type'] ?? '',
                $data['reference_id'] ?? '',
                (int)($data['performed_by'] ?? 0),
                $data['performed_by_name'] ?? '',
                $data['note'] ?? '',
                $data['photo_path'] ?? '',
                $now,
            ]);
    }
}
