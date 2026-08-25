-- Migration 048: Add crm_job_id to fiber_collection_jobs
-- Stores the UCRM scheduling job ID created for the accountant
-- so we can link the collection job back to the CRM job.

ALTER TABLE fiber_collection_jobs ADD COLUMN crm_job_id INTEGER DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_fiber_crm_job
    ON fiber_collection_jobs(crm_job_id)
    WHERE crm_job_id > 0;
