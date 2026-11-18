-- Durable, principal-bound bulk exports. Files are private and never stored in PostgreSQL.
CREATE TABLE log_export_jobs (
    id uuid PRIMARY KEY,
    project_id uuid NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    requested_by uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    source_id uuid,
    filters jsonb NOT NULL,
    format text NOT NULL CHECK (format IN ('json','csv')),
    status text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','ready','failed','expired')),
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 10),
    row_count bigint NOT NULL DEFAULT 0 CHECK (row_count>=0),
    byte_count bigint NOT NULL DEFAULT 0 CHECK (byte_count>=0),
    checksum text,
    error_code text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    started_at timestamptz,
    finished_at timestamptz,
    expires_at timestamptz NOT NULL DEFAULT (clock_timestamp() + interval '24 hours'),
    CHECK (jsonb_typeof(filters)='object'),
    CHECK (checksum IS NULL OR checksum ~ '^[0-9a-f]{64}$'),
    CHECK (status <> 'ready' OR (checksum IS NOT NULL AND finished_at IS NOT NULL)),
    CHECK (status <> 'failed' OR error_code IS NOT NULL)
);
CREATE INDEX idx_log_export_jobs_owner ON log_export_jobs(project_id,requested_by,created_at DESC);
CREATE INDEX idx_log_export_jobs_work ON log_export_jobs(status,created_at) WHERE status IN ('queued','processing');
CREATE INDEX idx_log_export_jobs_expiry ON log_export_jobs(expires_at);
