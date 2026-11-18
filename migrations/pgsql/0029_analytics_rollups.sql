-- Exact transactional 1-minute rollups. PostgreSQL is the source of truth;
-- ClickHouse replay/backfill never mutates these counters.
CREATE TABLE analytics_rollup_event_state (
    event_id uuid PRIMARY KEY,
    project_id uuid NOT NULL,
    minute timestamptz NOT NULL,
    source_id uuid NOT NULL,
    source text NOT NULL,
    level text NOT NULL,
    service text NOT NULL,
    host text NOT NULL
);
CREATE INDEX analytics_rollup_event_project_idx ON analytics_rollup_event_state(project_id,event_id);
-- Keep source-less legacy project events on the original exact query path.
CREATE INDEX analytics_rollup_legacy_source_idx ON log_events(project_id)
    WHERE project_id IS NOT NULL AND source_id IS NULL;
CREATE TABLE analytics_rollup_minutes (
    project_id uuid NOT NULL,
    minute timestamptz NOT NULL,
    source_id uuid NOT NULL,
    source text NOT NULL,
    level text NOT NULL,
    service text NOT NULL,
    host text NOT NULL,
    events bigint NOT NULL CHECK (events >= 0),
    PRIMARY KEY (project_id,minute,source_id,source,level,service,host)
);
CREATE INDEX analytics_rollup_minute_project_idx ON analytics_rollup_minutes(project_id,minute);
CREATE TABLE analytics_rollup_backfill (
    project_id uuid PRIMARY KEY REFERENCES projects(id) ON DELETE CASCADE,
    last_event_id uuid,
    scanned bigint NOT NULL DEFAULT 0,
    applied bigint NOT NULL DEFAULT 0,
    complete boolean NOT NULL DEFAULT false,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

-- One SQL upsert per group, not one network round trip per event. State is
-- inserted before incrementing: retry/replay cannot increment counters twice.
CREATE FUNCTION analytics_rollup_capture_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    WITH inserted AS (
        INSERT INTO analytics_rollup_event_state(event_id,project_id,minute,source_id,source,level,service,host)
        SELECT id,project_id,date_trunc('minute',occurred_at),source_id,source,level,
            coalesce(service,''),coalesce(host,'') FROM new_rows
        WHERE project_id IS NOT NULL AND source_id IS NOT NULL
        ON CONFLICT (event_id) DO NOTHING
        RETURNING project_id,minute,source_id,source,level,service,host
    ), summed AS (
        SELECT project_id,minute,source_id,source,level,service,host,count(*) AS n
        FROM inserted GROUP BY project_id,minute,source_id,source,level,service,host
    )
    INSERT INTO analytics_rollup_minutes(project_id,minute,source_id,source,level,service,host,events)
    SELECT project_id,minute,source_id,source,level,service,host,n FROM summed
    ON CONFLICT (project_id,minute,source_id,source,level,service,host)
    DO UPDATE SET events=analytics_rollup_minutes.events+EXCLUDED.events;
    RETURN NULL;
END;
$$;

-- A deleted or changed event retracts exactly the state previously counted,
-- including changes to bucket, project, source, level, service, and host.
CREATE FUNCTION analytics_rollup_capture_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    WITH removed AS (
        DELETE FROM analytics_rollup_event_state s USING old_rows r
        WHERE s.event_id=r.id
        RETURNING s.project_id,s.minute,s.source_id,s.source,s.level,s.service,s.host
    ), summed AS (
        SELECT project_id,minute,source_id,source,level,service,host,count(*) AS n
        FROM removed GROUP BY project_id,minute,source_id,source,level,service,host
    )
    UPDATE analytics_rollup_minutes m SET events=m.events-summed.n FROM summed
    WHERE m.project_id=summed.project_id AND m.minute=summed.minute
      AND m.source_id=summed.source_id AND m.source=summed.source
      AND m.level=summed.level AND m.service=summed.service AND m.host=summed.host;
    -- Do not retain service/host metadata after the last event in a group is deleted.
    DELETE FROM analytics_rollup_minutes m USING old_rows o
    WHERE m.events=0 AND m.project_id=o.project_id AND m.minute=date_trunc('minute',o.occurred_at)
      AND m.source_id=o.source_id AND m.source=o.source AND m.level=o.level
      AND m.service=coalesce(o.service,'') AND m.host=coalesce(o.host,'');
    RETURN NULL;
END;
$$;

CREATE FUNCTION analytics_rollup_capture_update() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    -- Both changes occur inside the original UPDATE transaction. The old
    -- counted state, not OLD's current projection, is authoritative.
    WITH removed AS (
        DELETE FROM analytics_rollup_event_state s USING old_rows r
        WHERE s.event_id=r.id
        RETURNING s.project_id,s.minute,s.source_id,s.source,s.level,s.service,s.host
    ), summed AS (
        SELECT project_id,minute,source_id,source,level,service,host,count(*) AS n
        FROM removed GROUP BY project_id,minute,source_id,source,level,service,host
    )
    UPDATE analytics_rollup_minutes m SET events=m.events-summed.n FROM summed
    WHERE m.project_id=summed.project_id AND m.minute=summed.minute
      AND m.source_id=summed.source_id AND m.source=summed.source
      AND m.level=summed.level AND m.service=summed.service AND m.host=summed.host;

    WITH inserted AS (
        INSERT INTO analytics_rollup_event_state(event_id,project_id,minute,source_id,source,level,service,host)
        SELECT id,project_id,date_trunc('minute',occurred_at),source_id,source,level,
            coalesce(service,''),coalesce(host,'') FROM new_rows
        WHERE project_id IS NOT NULL AND source_id IS NOT NULL
        ON CONFLICT (event_id) DO NOTHING
        RETURNING project_id,minute,source_id,source,level,service,host
    ), summed AS (
        SELECT project_id,minute,source_id,source,level,service,host,count(*) AS n
        FROM inserted GROUP BY project_id,minute,source_id,source,level,service,host
    )
    INSERT INTO analytics_rollup_minutes(project_id,minute,source_id,source,level,service,host,events)
    SELECT project_id,minute,source_id,source,level,service,host,n FROM summed
    ON CONFLICT (project_id,minute,source_id,source,level,service,host)
    DO UPDATE SET events=analytics_rollup_minutes.events+EXCLUDED.events;
    DELETE FROM analytics_rollup_minutes m USING old_rows o
    WHERE m.events=0 AND m.project_id=o.project_id AND m.minute=date_trunc('minute',o.occurred_at)
      AND m.source_id=o.source_id AND m.source=o.source AND m.level=o.level
      AND m.service=coalesce(o.service,'') AND m.host=coalesce(o.host,'');
    RETURN NULL;
END;
$$;

CREATE TRIGGER analytics_rollup_insert AFTER INSERT ON log_events
    REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION analytics_rollup_capture_insert();
CREATE TRIGGER analytics_rollup_update AFTER UPDATE ON log_events
    REFERENCING OLD TABLE AS old_rows NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION analytics_rollup_capture_update();
CREATE TRIGGER analytics_rollup_delete AFTER DELETE ON log_events
    REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION analytics_rollup_capture_delete();
