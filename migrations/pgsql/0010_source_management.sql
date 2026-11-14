-- Preserve source identity and historical event attribution.
ALTER TABLE sources ADD COLUMN description text NOT NULL DEFAULT '' CHECK (octet_length(description)<=4096),
    ADD COLUMN metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata)='object'),
    ADD COLUMN source_type text NOT NULL DEFAULT 'application' CHECK (source_type IN ('application','server','nginx','aapanel','custom')),
    ADD COLUMN status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','disabled','archived')),
    ADD COLUMN archived_at timestamptz,
    ADD COLUMN updated_at timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN revision bigint NOT NULL DEFAULT 1 CHECK (revision>0),
    ADD COLUMN retention_days integer CHECK (retention_days BETWEEN 1 AND 3650),
    ADD COLUMN max_body_bytes integer CHECK (max_body_bytes BETWEEN 1024 AND 1048576),
    ADD COLUMN max_batch_events integer CHECK (max_batch_events BETWEEN 1 AND 100),
    ADD COLUMN events_per_minute integer CHECK (events_per_minute BETWEEN 1 AND 1000000),
    ADD COLUMN last_seen_at timestamptz,
    ADD COLUMN accepted_events_total bigint NOT NULL DEFAULT 0 CHECK (accepted_events_total>=0),
    ADD COLUMN rejected_events_total bigint NOT NULL DEFAULT 0 CHECK (rejected_events_total>=0),
    ADD CONSTRAINT source_archive_state CHECK ((status='archived')=(archived_at IS NOT NULL));
CREATE INDEX idx_sources_project_status_id ON sources(project_id,status,id);
CREATE INDEX idx_log_events_source_received ON log_events(source_id,received_at DESC,id);
