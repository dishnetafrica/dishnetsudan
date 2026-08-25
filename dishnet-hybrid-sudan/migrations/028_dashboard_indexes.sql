-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 028: Production Dashboard Indexes
-- ═══════════════════════════════════════════════════════════════════════════
-- 
-- Additional indexes identified from production query analysis.
-- These ensure all dashboard widgets can execute in <10ms.
--
-- Reference: Telecom NOC dashboard query patterns
-- ═══════════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Job Queue Performance
-- ─────────────────────────────────────────────────────────────────────────────

-- For queue backlog queries
CREATE INDEX IF NOT EXISTS idx_job_queue_status 
ON job_queue(status);

-- For processing pending jobs
-- Note: idx_job_queue_pending requires run_after column (added in 002_job_queue.sql)
CREATE INDEX IF NOT EXISTS idx_job_queue_pending 
ON job_queue(status) 
WHERE status = 'pending';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Subscriber Growth Queries
-- ─────────────────────────────────────────────────────────────────────────────

-- For "new subscribers today/this month" queries
CREATE INDEX IF NOT EXISTS idx_lte_subs_created 
ON lte_subscribers(created_at DESC);

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Subscription Expiry (Compound Index)
-- ─────────────────────────────────────────────────────────────────────────────

-- For churn calculations: expired subscriptions by date
CREATE INDEX IF NOT EXISTS idx_lte_subs_status_expires 
ON lte_subscriptions(status, expires_at);

-- For package distribution queries
CREATE INDEX IF NOT EXISTS idx_lte_subs_package 
ON lte_subscriptions(package_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Renewal/Revenue Queries
-- ─────────────────────────────────────────────────────────────────────────────

-- For "revenue today/this month" queries
CREATE INDEX IF NOT EXISTS idx_lte_renewals_date 
ON lte_renewals(created_at DESC);

-- For agent performance leaderboard
CREATE INDEX IF NOT EXISTS idx_lte_renewals_agent 
ON lte_renewals(agent_id, created_at DESC);

-- For package revenue breakdown
CREATE INDEX IF NOT EXISTS idx_lte_renewals_package 
ON lte_renewals(package_id, created_at DESC);

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. Usage Tracking (Data Cap Plans)
-- ─────────────────────────────────────────────────────────────────────────────

-- For usage percentage alerts (50%, 80%, 95%)
CREATE INDEX IF NOT EXISTS idx_lte_subs_usage_pct 
ON lte_subscriptions(usage_percent DESC) 
WHERE status = 'active' AND package_type = 0;

-- For bytes_used thresholds
CREATE INDEX IF NOT EXISTS idx_lte_subs_bytes 
ON lte_subscriptions(bytes_used DESC) 
WHERE status = 'active';

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. Financial Ledger Performance
-- ─────────────────────────────────────────────────────────────────────────────

-- For subscriber ledger queries
CREATE INDEX IF NOT EXISTS idx_lte_ledger_subscriber 
ON lte_financial_ledger(subscriber_id, created_at DESC);

-- For daily revenue reports
CREATE INDEX IF NOT EXISTS idx_lte_ledger_date 
ON lte_financial_ledger(created_at DESC);

-- ─────────────────────────────────────────────────────────────────────────────
-- 7. SIM Inventory Performance
-- ─────────────────────────────────────────────────────────────────────────────

-- For inventory status queries
CREATE INDEX IF NOT EXISTS idx_lte_sims_status 
ON lte_sims(status);

-- For IMSI lookups
CREATE INDEX IF NOT EXISTS idx_lte_sims_imsi 
ON lte_sims(imsi);

-- ─────────────────────────────────────────────────────────────────────────────
-- 8. Package Performance
-- ─────────────────────────────────────────────────────────────────────────────

-- For active package lookups
CREATE INDEX IF NOT EXISTS idx_lte_packages_active 
ON lte_packages(is_active) 
WHERE is_active = 1;

-- ─────────────────────────────────────────────────────────────────────────────
-- 9. Verify Index Coverage with EXPLAIN
-- ─────────────────────────────────────────────────────────────────────────────
-- 
-- After migration, run these to verify index usage:
--
-- EXPLAIN QUERY PLAN SELECT COUNT(*) FROM lte_subscriptions WHERE status='active';
-- EXPLAIN QUERY PLAN SELECT COUNT(*) FROM lte_subscribers WHERE created_at >= date('now');
-- EXPLAIN QUERY PLAN SELECT SUM(amount_paid) FROM lte_renewals WHERE created_at >= date('now');
-- EXPLAIN QUERY PLAN SELECT COUNT(*) FROM lte_subscriptions WHERE status='active' AND expires_at <= datetime('now','+1 day');
--
-- All should show "USING INDEX" or "USING COVERING INDEX"
-- ─────────────────────────────────────────────────────────────────────────────
