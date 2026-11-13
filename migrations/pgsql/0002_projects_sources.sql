CREATE TABLE projects (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(255) NOT NULL,
    slug varchar(100) NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE sources (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
    name varchar(255) NOT NULL,
    slug varchar(100) NOT NULL CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (project_id, slug), UNIQUE (project_id, id)
);
ALTER TABLE api_tokens ADD COLUMN project_id uuid REFERENCES projects(id) ON DELETE RESTRICT,
    ADD COLUMN source_id uuid, ADD COLUMN revoked_at timestamptz,
    ADD CONSTRAINT token_source_project_fk FOREIGN KEY (project_id, source_id) REFERENCES sources(project_id, id),
    ADD CONSTRAINT token_source_requires_project CHECK (source_id IS NULL OR project_id IS NOT NULL);
ALTER TABLE log_events ADD COLUMN project_id uuid REFERENCES projects(id) ON DELETE RESTRICT,
    ADD COLUMN source_id uuid,
    ADD CONSTRAINT log_source_project_fk FOREIGN KEY (project_id, source_id) REFERENCES sources(project_id, id),
    ADD CONSTRAINT log_source_requires_project CHECK (source_id IS NULL OR project_id IS NOT NULL);
CREATE INDEX idx_log_events_project_time ON log_events(project_id, occurred_at DESC, id DESC);
CREATE INDEX idx_log_events_source_time ON log_events(source_id, occurred_at DESC, id DESC);
CREATE UNIQUE INDEX idx_users_email_lower ON users (lower(email));
CREATE INDEX idx_jobs_available ON jobs (queue, available_at, id) WHERE reserved_at IS NULL;
