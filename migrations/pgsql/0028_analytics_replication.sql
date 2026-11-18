-- Outbox is written in exactly the same PostgreSQL transaction as log mutations.
-- It intentionally does not reference log_events: delete tombstones must survive deletion.
CREATE SEQUENCE analytics_replication_revision_seq AS bigint;
CREATE TABLE analytics_replication_outbox (
    event_id uuid PRIMARY KEY,
    project_id uuid NOT NULL,
    source_id uuid NOT NULL,
    occurred_at timestamptz NOT NULL,
    received_at timestamptz NOT NULL,
    revision bigint NOT NULL CHECK (revision>0),
    operation text NOT NULL CHECK (operation IN ('upsert','delete')),
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','running','completed','dead')),
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 100),
    available_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    lease_id uuid,
    lease_until timestamptz,
    error_code text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    completed_at timestamptz,
    CHECK ((status='running')=(lease_id IS NOT NULL AND lease_until IS NOT NULL))
);
CREATE INDEX analytics_replication_claim_idx ON analytics_replication_outbox(available_at,updated_at,event_id)
    WHERE status IN ('pending','running');
CREATE INDEX analytics_replication_dead_idx ON analytics_replication_outbox(updated_at,event_id)
    WHERE status='dead';
CREATE INDEX analytics_replication_project_idx ON analytics_replication_outbox(project_id,updated_at DESC);
CREATE TABLE analytics_replication_backfill (
    project_id uuid PRIMARY KEY REFERENCES projects(id) ON DELETE CASCADE,
    last_event_id uuid,
    scanned bigint NOT NULL DEFAULT 0,
    queued bigint NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

-- ON CONFLICT updates are intentional: a changed event invalidates the old lease,
-- preventing a stale worker from acknowledging a newer mutation.
CREATE FUNCTION analytics_replication_capture() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='INSERT' THEN
        INSERT INTO analytics_replication_outbox(event_id,project_id,source_id,occurred_at,received_at,revision,operation)
        SELECT id,project_id,source_id,occurred_at,received_at,nextval('analytics_replication_revision_seq'),'upsert'
        FROM new_rows WHERE project_id IS NOT NULL AND source_id IS NOT NULL
        ON CONFLICT (event_id) DO UPDATE SET
            project_id=EXCLUDED.project_id,source_id=EXCLUDED.source_id,occurred_at=EXCLUDED.occurred_at,
            received_at=EXCLUDED.received_at,revision=EXCLUDED.revision,operation='upsert',status='pending',
            attempts=0,available_at=clock_timestamp(),lease_id=NULL,lease_until=NULL,error_code=NULL,
            completed_at=NULL,updated_at=clock_timestamp();
    ELSIF TG_OP='UPDATE' THEN
        INSERT INTO analytics_replication_outbox(event_id,project_id,source_id,occurred_at,received_at,revision,operation)
        SELECT n.id,n.project_id,n.source_id,n.occurred_at,n.received_at,
            nextval('analytics_replication_revision_seq'),'upsert'
        FROM new_rows n JOIN old_rows o ON o.id=n.id
        WHERE n.project_id IS NOT NULL AND n.source_id IS NOT NULL
        AND (n.project_id,n.source_id,n.occurred_at,n.received_at,n.source,n.service,n.host,
             n.level,n.message,n.context,n.trace_id,n.request_id,n.tags)
          IS DISTINCT FROM (o.project_id,o.source_id,o.occurred_at,o.received_at,o.source,o.service,o.host,
             o.level,o.message,o.context,o.trace_id,o.request_id,o.tags)
        ON CONFLICT (event_id) DO UPDATE SET
            project_id=EXCLUDED.project_id,source_id=EXCLUDED.source_id,occurred_at=EXCLUDED.occurred_at,
            received_at=EXCLUDED.received_at,revision=EXCLUDED.revision,operation='upsert',status='pending',
            attempts=0,available_at=clock_timestamp(),lease_id=NULL,lease_until=NULL,error_code=NULL,
            completed_at=NULL,updated_at=clock_timestamp();
    ELSIF TG_OP='DELETE' THEN
        INSERT INTO analytics_replication_outbox(event_id,project_id,source_id,occurred_at,received_at,revision,operation)
        SELECT id,project_id,source_id,occurred_at,received_at,
            nextval('analytics_replication_revision_seq'),'delete'
        FROM old_rows WHERE project_id IS NOT NULL AND source_id IS NOT NULL
        ON CONFLICT (event_id) DO UPDATE SET
            project_id=EXCLUDED.project_id,source_id=EXCLUDED.source_id,occurred_at=EXCLUDED.occurred_at,
            received_at=EXCLUDED.received_at,revision=EXCLUDED.revision,operation='delete',status='pending',
            attempts=0,available_at=clock_timestamp(),lease_id=NULL,lease_until=NULL,error_code=NULL,
            completed_at=NULL,updated_at=clock_timestamp();
    END IF;
    RETURN NULL;
END;
$$;
CREATE TRIGGER analytics_replication_insert AFTER INSERT ON log_events
    REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION analytics_replication_capture();
CREATE TRIGGER analytics_replication_update AFTER UPDATE ON log_events
    REFERENCING OLD TABLE AS old_rows NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION analytics_replication_capture();
CREATE TRIGGER analytics_replication_delete AFTER DELETE ON log_events
    REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION analytics_replication_capture();
