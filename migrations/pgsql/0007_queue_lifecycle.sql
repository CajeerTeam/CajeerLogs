-- Durable ingest jobs; preserve old job rows and migration checksums.
ALTER TABLE jobs ADD COLUMN status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','running','completed','dead','cancelled')),
    ADD COLUMN token_id uuid REFERENCES api_tokens(id) ON DELETE RESTRICT,
    ADD COLUMN project_id uuid REFERENCES projects(id) ON DELETE RESTRICT,
    ADD COLUMN key_hash char(64), ADD COLUMN request_hash char(64),
    ADD COLUMN lease_id uuid, ADD COLUMN lease_until timestamptz,
    ADD COLUMN max_attempts integer NOT NULL DEFAULT 8 CHECK (max_attempts BETWEEN 1 AND 100),
    ADD COLUMN completed_at timestamptz, ADD COLUMN last_error text,
    ADD COLUMN result jsonb;
ALTER TABLE jobs ADD CONSTRAINT jobs_ingest_metadata CHECK (queue<>'ingest-v1' OR (token_id IS NOT NULL AND project_id IS NOT NULL AND key_hash IS NOT NULL AND request_hash IS NOT NULL AND name='ingest'));
CREATE UNIQUE INDEX jobs_ingest_key ON jobs(token_id,key_hash) WHERE queue='ingest-v1';
CREATE INDEX jobs_ingest_ready ON jobs(available_at,id) WHERE queue='ingest-v1' AND status='pending';
CREATE INDEX jobs_ingest_lease ON jobs(lease_until,id) WHERE queue='ingest-v1' AND status='running';
CREATE INDEX jobs_ingest_active ON jobs(id) WHERE queue='ingest-v1' AND status IN ('pending','running','dead');
CREATE INDEX jobs_ingest_cleanup ON jobs(completed_at,id) WHERE queue='ingest-v1' AND status IN ('completed','cancelled');
CREATE TABLE job_attempts (
    job_id bigint NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
    attempt integer NOT NULL, started_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    finished_at timestamptz, outcome text NOT NULL DEFAULT 'running' CHECK (outcome IN ('running','completed','retry','dead','expired')),
    error text, PRIMARY KEY(job_id,attempt)
);
-- Opt-in retention, measured from server receipt time (not client timestamp).
ALTER TABLE projects ADD COLUMN retention_days integer CHECK (retention_days BETWEEN 1 AND 3650);
CREATE INDEX log_events_retention_idx ON log_events(project_id,received_at,id) WHERE project_id IS NOT NULL;
CREATE TABLE scheduler_runs (
    name text PRIMARY KEY, next_run_at timestamptz NOT NULL,
    last_success_at timestamptz NOT NULL, last_result jsonb NOT NULL
);
