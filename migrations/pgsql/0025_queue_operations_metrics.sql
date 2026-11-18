-- 0.5.9: transactional dispatch control + bounded-cardinality delivery counters.
-- No existing job, lease, receipt or payload changes. New counters start at zero,
-- explicitly representing deliveries observed since this migration (not historic totals).
CREATE TABLE queue_dispatch_control (
    queue text PRIMARY KEY CHECK (queue='ingest-v1'),
    paused boolean NOT NULL DEFAULT false,
    generation bigint NOT NULL DEFAULT 0 CHECK (generation>=0),
    reason text,
    changed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    changed_by uuid REFERENCES users(id) ON DELETE SET NULL,
    CHECK (reason IS NULL OR octet_length(reason)<=200)
);
INSERT INTO queue_dispatch_control(queue) VALUES ('ingest-v1');

ALTER TABLE queue_project_state
    ADD COLUMN accepted_jobs_total bigint NOT NULL DEFAULT 0 CHECK (accepted_jobs_total>=0),
    ADD COLUMN accepted_events_total bigint NOT NULL DEFAULT 0 CHECK (accepted_events_total>=0),
    ADD COLUMN completed_jobs_total bigint NOT NULL DEFAULT 0 CHECK (completed_jobs_total>=0),
    ADD COLUMN completed_events_total bigint NOT NULL DEFAULT 0 CHECK (completed_events_total>=0),
    ADD COLUMN retried_jobs_total bigint NOT NULL DEFAULT 0 CHECK (retried_jobs_total>=0),
    ADD COLUMN dead_jobs_total bigint NOT NULL DEFAULT 0 CHECK (dead_jobs_total>=0),
    ADD COLUMN lease_expirations_total bigint NOT NULL DEFAULT 0 CHECK (lease_expirations_total>=0),
    ADD COLUMN delivery_latency_ms_total bigint NOT NULL DEFAULT 0 CHECK (delivery_latency_ms_total>=0),
    ADD COLUMN delivery_le_1 bigint NOT NULL DEFAULT 0 CHECK (delivery_le_1>=0),
    ADD COLUMN delivery_le_5 bigint NOT NULL DEFAULT 0 CHECK (delivery_le_5>=0),
    ADD COLUMN delivery_le_30 bigint NOT NULL DEFAULT 0 CHECK (delivery_le_30>=0),
    ADD COLUMN delivery_le_300 bigint NOT NULL DEFAULT 0 CHECK (delivery_le_300>=0);

-- Extend the established per-project accounting trigger rather than introducing
-- a second UPDATE per completed job. Transition counters are exact on commit,
-- rolled back with failed jobs, and never incremented by idempotent replay.
CREATE OR REPLACE FUNCTION queue_project_delta() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE j bigint:=0; p bigint:=0; r bigint:=0; e bigint:=0; b bigint:=0;
        project uuid; affected bigint;
        accepted bigint:=0; accepted_events bigint:=0; completed bigint:=0; completed_events bigint:=0;
        retried bigint:=0; dead_total bigint:=0; elapsed_ms bigint:=0;
        b1 bigint:=0; b5 bigint:=0; b30 bigint:=0; b300 bigint:=0;
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
        IF TG_OP='INSERT' THEN accepted:=1; accepted_events:=NEW.payload_events; END IF;
        IF NEW.status='running' THEN r:=r+1; END IF;
    END IF;
    IF TG_OP='UPDATE' AND OLD.queue='ingest-v1' THEN
        IF NEW.status='completed' AND OLD.status IS DISTINCT FROM 'completed' THEN
            completed:=1; completed_events:=OLD.payload_events;
            elapsed_ms:=GREATEST(0,round(extract(epoch from (COALESCE(NEW.completed_at,clock_timestamp())-OLD.created_at))*1000)::bigint);
            b1:=CASE WHEN elapsed_ms<=1000 THEN 1 ELSE 0 END;
            b5:=CASE WHEN elapsed_ms<=5000 THEN 1 ELSE 0 END;
            b30:=CASE WHEN elapsed_ms<=30000 THEN 1 ELSE 0 END;
            b300:=CASE WHEN elapsed_ms<=300000 THEN 1 ELSE 0 END;
        END IF;
        IF OLD.status IN ('running','dead') AND NEW.status='pending' THEN retried:=1; END IF;
        IF OLD.status IS DISTINCT FROM 'dead' AND NEW.status='dead' THEN dead_total:=1; END IF;
    END IF;
    IF (j<>0 OR p<>0 OR r<>0 OR e<>0 OR b<>0 OR accepted<>0 OR completed<>0 OR retried<>0 OR dead_total<>0) AND project IS NOT NULL THEN
        EXECUTE format('UPDATE %I.queue_project_state SET '
            ||'virtual_finish=CASE WHEN pending_jobs=0 AND $2>0 THEN GREATEST(virtual_finish,'
            ||'COALESCE((SELECT min(virtual_finish) FROM %I.queue_project_state '
            ||'WHERE project_id<>$1 AND pending_jobs>0),0)) ELSE virtual_finish END,'
            ||'active_jobs=active_jobs+$3,pending_jobs=pending_jobs+$2,running_jobs=running_jobs+$4,'
            ||'active_events=active_events+$5,active_bytes=active_bytes+$6,'
            ||'accepted_jobs_total=accepted_jobs_total+$7,accepted_events_total=accepted_events_total+$8,'
            ||'completed_jobs_total=completed_jobs_total+$9,completed_events_total=completed_events_total+$10,'
            ||'retried_jobs_total=retried_jobs_total+$11,dead_jobs_total=dead_jobs_total+$12,'
            ||'delivery_latency_ms_total=delivery_latency_ms_total+$13,'
            ||'delivery_le_1=delivery_le_1+$14,delivery_le_5=delivery_le_5+$15,'
            ||'delivery_le_30=delivery_le_30+$16,delivery_le_300=delivery_le_300+$17 '
            ||'WHERE project_id=$1 AND ($4<=0 OR max_running_jobs=0 OR running_jobs+$4<=max_running_jobs)',
            TG_TABLE_SCHEMA,TG_TABLE_SCHEMA)
        USING project,p,j,r,e,b,accepted,accepted_events,completed,completed_events,retried,dead_total,elapsed_ms,b1,b5,b30,b300;
        GET DIAGNOSTICS affected=ROW_COUNT;
        IF affected<>1 THEN RAISE EXCEPTION 'Project queue accounting missing or running limit exceeded' USING ERRCODE='23514'; END IF;
    END IF;
    RETURN NULL;
END;
$$;


-- Lease expiry is recorded when its attempt is actually closed. Same job can
-- expire multiple times, hence this is an attempt counter rather than a job count.
CREATE FUNCTION queue_attempt_expiry_metric() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog AS $$
BEGIN
    IF OLD.outcome IS DISTINCT FROM 'expired' AND NEW.outcome='expired' THEN
        EXECUTE format('UPDATE %I.queue_project_state s SET lease_expirations_total=lease_expirations_total+1 FROM %I.jobs j WHERE j.id=$1 AND s.project_id=j.project_id',TG_TABLE_SCHEMA,TG_TABLE_SCHEMA) USING NEW.job_id;
    END IF;
    RETURN NULL;
END;
$$;
CREATE TRIGGER attempts_queue_expiry_metric AFTER UPDATE OF outcome ON job_attempts
    FOR EACH ROW EXECUTE FUNCTION queue_attempt_expiry_metric();
