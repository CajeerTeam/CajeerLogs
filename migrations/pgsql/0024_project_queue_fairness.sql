-- Project-scoped capacity, in-flight limits and weighted fair dispatch.
-- Migration backfills from existing durable jobs without modifying any payload,
-- lease, attempt or idempotency receipt. 0 means an unlimited project budget.
CREATE TABLE queue_project_state (
    project_id uuid PRIMARY KEY REFERENCES projects(id) ON DELETE CASCADE,
    weight integer NOT NULL DEFAULT 1 CHECK (weight BETWEEN 1 AND 16),
    max_jobs bigint NOT NULL DEFAULT 0 CHECK (max_jobs BETWEEN 0 AND 100000000),
    max_events bigint NOT NULL DEFAULT 0 CHECK (max_events BETWEEN 0 AND 1000000000),
    max_bytes bigint NOT NULL DEFAULT 0 CHECK (max_bytes BETWEEN 0 AND 1099511627776),
    max_running_jobs integer NOT NULL DEFAULT 0 CHECK (max_running_jobs BETWEEN 0 AND 1024),
    active_jobs bigint NOT NULL DEFAULT 0 CHECK (active_jobs>=0),
    pending_jobs bigint NOT NULL DEFAULT 0 CHECK (pending_jobs>=0),
    running_jobs bigint NOT NULL DEFAULT 0 CHECK (running_jobs>=0),
    active_events bigint NOT NULL DEFAULT 0 CHECK (active_events>=0),
    active_bytes bigint NOT NULL DEFAULT 0 CHECK (active_bytes>=0),
    virtual_finish bigint NOT NULL DEFAULT 0 CHECK (virtual_finish>=0),
    dispatched bigint NOT NULL DEFAULT 0 CHECK (dispatched>=0),
    rejected bigint NOT NULL DEFAULT 0 CHECK (rejected>=0),
    last_claim_at timestamptz,
    CHECK (pending_jobs+running_jobs<=active_jobs)
);
INSERT INTO queue_project_state(project_id,active_jobs,pending_jobs,running_jobs,active_events,active_bytes)
SELECT p.id,COALESCE(x.active_jobs,0),COALESCE(x.pending_jobs,0),COALESCE(x.running_jobs,0),
       COALESCE(x.active_events,0),COALESCE(x.active_bytes,0)
FROM projects p LEFT JOIN (
    SELECT project_id,
        count(*) FILTER (WHERE status IN ('pending','running','dead')) AS active_jobs,
        count(*) FILTER (WHERE status='pending') AS pending_jobs,
        count(*) FILTER (WHERE status='running') AS running_jobs,
        COALESCE(sum(payload_events) FILTER (WHERE status IN ('pending','running','dead')),0) AS active_events,
        COALESCE(sum(payload_bytes) FILTER (WHERE status IN ('pending','running','dead')),0) AS active_bytes
    FROM jobs WHERE queue='ingest-v1' GROUP BY project_id
) x ON x.project_id=p.id;

CREATE FUNCTION queue_project_create() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $$
BEGIN
    EXECUTE format('INSERT INTO %I.queue_project_state(project_id) VALUES ($1)',TG_TABLE_SCHEMA) USING NEW.id;
    RETURN NULL;
END;
$$;
CREATE TRIGGER projects_queue_create AFTER INSERT ON projects FOR EACH ROW EXECUTE FUNCTION queue_project_create();

-- A durable job must never change ownership after admission (including manual SQL).
CREATE FUNCTION queue_job_scope_immutable() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $$
BEGIN
    IF OLD.queue='ingest-v1' AND (NEW.queue IS DISTINCT FROM OLD.queue OR
        NEW.project_id IS DISTINCT FROM OLD.project_id OR NEW.token_id IS DISTINCT FROM OLD.token_id) THEN
        RAISE EXCEPTION 'Durable queue ownership is immutable' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER jobs_queue_scope_immutable BEFORE UPDATE ON jobs FOR EACH ROW EXECUTE FUNCTION queue_job_scope_immutable();

-- Keep per-project counters exact on every state transition, including direct SQL,
-- rollback, restore and dead-letter discard. Do not charge completed/cancelled jobs.
CREATE FUNCTION queue_project_delta() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE j bigint:=0; p bigint:=0; r bigint:=0; e bigint:=0; b bigint:=0;
        project uuid; affected bigint;
BEGIN
    IF TG_OP<>'INSERT' AND OLD.queue='ingest-v1' THEN
        project:=OLD.project_id;
        IF OLD.status IN ('pending','running','dead') THEN
            j:=j-1; e:=e-OLD.payload_events; b:=b-OLD.payload_bytes;
        END IF;
        IF OLD.status='pending' THEN p:=p-1; END IF;
        IF OLD.status='running' THEN r:=r-1; END IF;
    END IF;
    IF TG_OP<>'DELETE' AND NEW.queue='ingest-v1' THEN
        project:=NEW.project_id;
        IF NEW.status IN ('pending','running','dead') THEN
            j:=j+1; e:=e+NEW.payload_events; b:=b+NEW.payload_bytes;
        END IF;
        IF NEW.status='pending' THEN p:=p+1; END IF;
        IF NEW.status='running' THEN r:=r+1; END IF;
    END IF;
    IF (j<>0 OR p<>0 OR r<>0 OR e<>0 OR b<>0) AND project IS NOT NULL THEN
        EXECUTE format('UPDATE %I.queue_project_state SET '
            ||'virtual_finish=CASE WHEN pending_jobs=0 AND $2>0 THEN GREATEST(virtual_finish,'
            ||'COALESCE((SELECT min(virtual_finish) FROM %I.queue_project_state '
            ||'WHERE project_id<>$1 AND pending_jobs>0),0)) ELSE virtual_finish END,'
            ||'active_jobs=active_jobs+$3,pending_jobs=pending_jobs+$2,running_jobs=running_jobs+$4,'
            ||'active_events=active_events+$5,active_bytes=active_bytes+$6 '
            ||'WHERE project_id=$1 AND ($4<=0 OR max_running_jobs=0 OR running_jobs+$4<=max_running_jobs)',
            TG_TABLE_SCHEMA,TG_TABLE_SCHEMA)
        USING project,p,j,r,e,b;
        GET DIAGNOSTICS affected=ROW_COUNT;
        IF affected<>1 THEN RAISE EXCEPTION 'Project queue accounting missing or running limit exceeded' USING ERRCODE='23514'; END IF;
    END IF;
    RETURN NULL;
END;
$$;
CREATE TRIGGER jobs_project_delta AFTER INSERT OR UPDATE OR DELETE ON jobs FOR EACH ROW EXECUTE FUNCTION queue_project_delta();

-- Fair dispatcher scans only projects with queued/leased jobs and uses project-local indexes.
CREATE INDEX queue_project_dispatch ON queue_project_state(virtual_finish,project_id)
    WHERE pending_jobs>0 OR running_jobs>0;
CREATE INDEX jobs_ingest_project_ready ON jobs(project_id,available_at,id)
    WHERE queue='ingest-v1' AND status='pending';
CREATE INDEX jobs_ingest_project_lease ON jobs(project_id,lease_until,id)
    WHERE queue='ingest-v1' AND status='running';
