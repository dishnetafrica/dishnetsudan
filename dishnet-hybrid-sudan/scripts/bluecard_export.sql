-- ════════════════════════════════════════════════════════════════════════════
-- BlueCard Data Export Queries
-- ════════════════════════════════════════════════════════════════════════════
-- Run these on the BlueCard MySQL server (dishnetss_bluecard database)
-- Export results as JSON for import into Hybrid
-- ════════════════════════════════════════════════════════════════════════════

-- ════════════════════════════════════════════════════════════════════════════
-- 1. EXPORT SIM CARDS
-- ════════════════════════════════════════════════════════════════════════════
-- Save output as: sims.json

SELECT JSON_ARRAYAGG(
    JSON_OBJECT(
        'id', s.id,
        'imsi', s.imsi,
        'msisdn', COALESCE(s.msisdn, s.imsi),
        'iccid', s.iccid,
        'auth_key', s.ki,
        'auth_opc', s.opc,
        'algo', COALESCE(s.algo, 'Milenage'),
        'status', CASE 
            WHEN s.user_id IS NOT NULL THEN 'assigned'
            WHEN s.status = 1 THEN 'in_stock'
            ELSE 'in_stock'
        END,
        'user_id', s.user_id,
        'magma_provisioned', COALESCE(s.provisioned, 1),
        'created_at', s.created_at
    )
) AS sims_json
FROM sim_cards s
WHERE s.deleted_at IS NULL;

-- ════════════════════════════════════════════════════════════════════════════
-- 2. EXPORT CUSTOMERS (users type=3)
-- ════════════════════════════════════════════════════════════════════════════
-- Save output as: customers.json

SELECT JSON_ARRAYAGG(
    JSON_OBJECT(
        'id', u.id,
        'name', COALESCE(u.name, CONCAT(u.first_name, ' ', u.last_name)),
        'phone', COALESCE(u.phone, u.mobile, u.whatsapp),
        'email', u.email,
        'address', u.address,
        'area', u.zone,
        'imsi', COALESCE(u.username, s.imsi),
        'msisdn', COALESCE(s.msisdn, u.username),
        'auth_key', s.ki,
        'auth_opc', s.opc,
        'status', CASE 
            WHEN u.status = 1 THEN 'active'
            WHEN u.status = 0 THEN 'suspended'
            ELSE 'active'
        END,
        'agent_id', u.created_by,
        'created_at', u.created_at
    )
) AS customers_json
FROM users u
LEFT JOIN sim_cards s ON s.user_id = u.id
WHERE u.type = 3 
  AND u.deleted_at IS NULL;

-- ════════════════════════════════════════════════════════════════════════════
-- 3. EXPORT ACTIVE SUBSCRIPTIONS (services + data_mgmt)
-- ════════════════════════════════════════════════════════════════════════════
-- Save output as: subscriptions.json

SELECT JSON_ARRAYAGG(
    JSON_OBJECT(
        'id', svc.id,
        'user_id', svc.user_id,
        'imsi', u.username,
        'package_id', svc.product_offering_id,
        'package_name', po.name,
        'price', po.price,
        'type', po.type,
        'bytes_allowed', CASE WHEN po.type = 0 THEN po.data_limit ELSE NULL END,
        'bytes_used', COALESCE(dm.bytes_used, 0),
        'bytes_baseline', COALESCE(dm.bytes_baseline, 0),
        'started_at', svc.start_date,
        'expires_at', svc.end_date,
        'magma_profile', CASE 
            WHEN po.name LIKE 'Silver%' THEN 'silver'
            WHEN po.name LIKE 'Gold%' THEN 'gold'
            WHEN po.name LIKE 'Platinum%' THEN 'platinum'
            WHEN po.name LIKE 'Diamond%' THEN 'diamond'
            ELSE 'default'
        END,
        'status', CASE 
            WHEN svc.end_date < NOW() THEN 'expired'
            WHEN dm.bytes_used >= po.data_limit AND po.type = 0 THEN 'exhausted'
            WHEN svc.status = 1 THEN 'active'
            ELSE 'inactive'
        END,
        'payment_method', COALESCE(svc.payment_method, 'cash'),
        'agent_id', svc.created_by,
        'created_at', svc.created_at
    )
) AS subscriptions_json
FROM services svc
JOIN users u ON u.id = svc.user_id
JOIN product_offering po ON po.id = svc.product_offering_id
LEFT JOIN data_mgmt dm ON dm.user_id = svc.user_id
WHERE svc.deleted_at IS NULL
  AND u.deleted_at IS NULL
  AND u.type = 3;

-- ════════════════════════════════════════════════════════════════════════════
-- 4. EXPORT RENEWAL HISTORY (balance_topup)
-- ════════════════════════════════════════════════════════════════════════════
-- Save output as: renewals.json (optional)

SELECT JSON_ARRAYAGG(
    JSON_OBJECT(
        'id', bt.id,
        'user_id', bt.user_id,
        'imsi', u.username,
        'package_id', bt.product_offering_id,
        'package_name', po.name,
        'amount', bt.amount,
        'payment_method', COALESCE(bt.payment_method, 'cash'),
        'agent_id', bt.created_by,
        'created_at', bt.created_at
    )
) AS renewals_json
FROM balance_topup bt
JOIN users u ON u.id = bt.user_id
JOIN product_offering po ON po.id = bt.product_offering_id
WHERE u.type = 3
  AND u.deleted_at IS NULL
ORDER BY bt.created_at DESC;

-- ════════════════════════════════════════════════════════════════════════════
-- ALTERNATIVE: DIRECT CSV EXPORT (run from command line)
-- ════════════════════════════════════════════════════════════════════════════

-- SIMs to CSV:
-- mysql -u root -p dishnetss_bluecard -e "SELECT id, imsi, msisdn, iccid, ki AS auth_key, opc AS auth_opc, status, user_id, created_at FROM sim_cards WHERE deleted_at IS NULL" | sed 's/\t/,/g' > sims.csv

-- Customers to CSV:
-- mysql -u root -p dishnetss_bluecard -e "SELECT u.id, COALESCE(u.name, CONCAT(u.first_name, ' ', u.last_name)) AS name, u.phone, u.email, u.address, u.zone AS area, u.username AS imsi, u.status, u.created_by AS agent_id, u.created_at FROM users u WHERE u.type = 3 AND u.deleted_at IS NULL" | sed 's/\t/,/g' > customers.csv

-- Services to CSV:
-- mysql -u root -p dishnetss_bluecard -e "SELECT svc.id, svc.user_id, u.username AS imsi, po.name AS package_name, po.price, po.type, svc.start_date AS started_at, svc.end_date AS expires_at, COALESCE(dm.bytes_used, 0) AS bytes_used, svc.status, svc.created_at FROM services svc JOIN users u ON u.id = svc.user_id JOIN product_offering po ON po.id = svc.product_offering_id LEFT JOIN data_mgmt dm ON dm.user_id = svc.user_id WHERE svc.deleted_at IS NULL AND u.type = 3" | sed 's/\t/,/g' > subscriptions.csv

-- ════════════════════════════════════════════════════════════════════════════
-- COUNTS (for verification)
-- ════════════════════════════════════════════════════════════════════════════

SELECT 'SIM Cards' AS entity, COUNT(*) AS count FROM sim_cards WHERE deleted_at IS NULL
UNION ALL
SELECT 'LTE Customers', COUNT(*) FROM users WHERE type = 3 AND deleted_at IS NULL
UNION ALL
SELECT 'Active Services', COUNT(*) FROM services svc JOIN users u ON u.id = svc.user_id WHERE svc.status = 1 AND svc.deleted_at IS NULL AND u.type = 3
UNION ALL
SELECT 'Packages', COUNT(*) FROM product_offering WHERE deleted_at IS NULL
UNION ALL
SELECT 'Renewals', COUNT(*) FROM balance_topup bt JOIN users u ON u.id = bt.user_id WHERE u.type = 3;
