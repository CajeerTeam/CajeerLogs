-- Warm archive partitions hold complete immutable snapshots, never replace the hot primary key.
-- Only the operator creates monthly partitions, through the archive CLI.
CREATE TABLE archived_log_events (
    id uuid NOT NULL,
    project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
    source_id uuid NOT NULL,
    received_at timestamptz NOT NULL,
    snapshot jsonb NOT NULL CHECK (
        jsonb_typeof(snapshot)='object'
        AND snapshot->>'id'=id::text
        AND snapshot->>'project_id'=project_id::text
        AND snapshot->>'source_id'=source_id::text
        AND (snapshot->>'received_at')::timestamptz=received_at
    ),
    archived_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(received_at,id)
) PARTITION BY RANGE(received_at);
CREATE INDEX archived_log_events_project_received_idx ON archived_log_events(project_id,received_at,id);

-- One verified immutable remote object per bounded batch of warm snapshots.
CREATE TABLE archived_log_objects (
    id uuid PRIMARY KEY,
    project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
    month_start date NOT NULL,
    object_key text NOT NULL UNIQUE,
    sha256 char(64) NOT NULL CHECK (sha256 ~ '^[a-f0-9]{64}$'),
    bytes integer NOT NULL CHECK (bytes>0 AND bytes<=16777216),
    event_count integer NOT NULL CHECK (event_count>0 AND event_count<=500),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX archived_log_objects_project_month_idx ON archived_log_objects(project_id,month_start);

-- Archive data must not silently escape later project/source retention policies.
CREATE FUNCTION check_archive_retention_policy() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.retention_days IS NOT NULL AND (TG_OP='INSERT' OR OLD.retention_days IS DISTINCT FROM NEW.retention_days) THEN
        IF TG_TABLE_NAME='projects' THEN
            IF EXISTS(SELECT 1 FROM archived_log_events WHERE project_id=NEW.id LIMIT 1)
              OR EXISTS(SELECT 1 FROM archived_log_objects WHERE project_id=NEW.id LIMIT 1) THEN
                RAISE EXCEPTION 'Purge warm/cold archives before enabling project retention' USING ERRCODE='check_violation';
            END IF;
        ELSE
            IF EXISTS(SELECT 1 FROM archived_log_events WHERE source_id=NEW.id LIMIT 1)
              OR EXISTS(SELECT 1 FROM archived_log_objects WHERE project_id=NEW.project_id LIMIT 1) THEN
                RAISE EXCEPTION 'Purge warm/cold archives before enabling source retention' USING ERRCODE='check_violation';
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER archive_project_retention_guard BEFORE UPDATE OF retention_days ON projects
  FOR EACH ROW EXECUTE FUNCTION check_archive_retention_policy();
CREATE TRIGGER archive_source_retention_guard BEFORE UPDATE OF retention_days ON sources
  FOR EACH ROW EXECUTE FUNCTION check_archive_retention_policy();
