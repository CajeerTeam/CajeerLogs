-- Existing projects and memberships retain their current behavior.
ALTER TABLE projects ADD COLUMN description text NOT NULL DEFAULT '' CHECK (octet_length(description)<=4096),
    ADD COLUMN metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata)='object'),
    ADD COLUMN archived_at timestamptz,
    ADD COLUMN updated_at timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN revision bigint NOT NULL DEFAULT 1 CHECK (revision>0),
    ADD COLUMN max_body_bytes integer NOT NULL DEFAULT 1048576 CHECK (max_body_bytes BETWEEN 1024 AND 1048576),
    ADD COLUMN max_batch_events integer NOT NULL DEFAULT 100 CHECK (max_batch_events BETWEEN 1 AND 100),
    ADD COLUMN events_per_minute integer CHECK (events_per_minute BETWEEN 1 AND 1000000);
ALTER TABLE project_members ADD COLUMN project_role text CHECK (project_role IN ('operator','viewer'));
CREATE INDEX idx_projects_archive_id ON projects(archived_at,id);
CREATE INDEX idx_jobs_project_status ON jobs(project_id,status) WHERE queue='ingest-v1';
