-- Offline upgrade: existing payloads and leases remain untouched. Metadata reflects
-- the actual PostgreSQL JSONB representation, including historical malformed jobs.
ALTER TABLE jobs ADD COLUMN payload_bytes bigint GENERATED ALWAYS AS (octet_length(payload::text)) STORED,
    ADD COLUMN payload_events integer GENERATED ALWAYS AS (CASE WHEN jsonb_typeof(payload->'events')='array' THEN jsonb_array_length(payload->'events') ELSE 0 END) STORED;
CREATE TABLE queue_accounting (
    queue text PRIMARY KEY CHECK (queue='ingest-v1'),
    active_jobs bigint NOT NULL CHECK (active_jobs>=0),
    active_events bigint NOT NULL CHECK (active_events>=0),
    active_bytes bigint NOT NULL CHECK (active_bytes>=0)
);
INSERT INTO queue_accounting(queue,active_jobs,active_events,active_bytes)
SELECT 'ingest-v1',count(*),COALESCE(sum(payload_events),0),COALESCE(sum(payload_bytes),0)
FROM jobs WHERE queue='ingest-v1' AND status IN ('pending','running','dead');
-- AFTER triggers see stored generated metadata. No write to the counter for claims,
-- lease renewals, retries or pending/running/dead transitions with unchanged payload.
CREATE FUNCTION queue_account_delta() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $$
DECLARE j bigint:=0; e bigint:=0; b bigint:=0; affected bigint;
BEGIN
    IF TG_OP<>'INSERT' THEN
        IF OLD.queue='ingest-v1' AND OLD.status IN ('pending','running','dead') THEN
            j:=j-1; e:=e-OLD.payload_events; b:=b-OLD.payload_bytes;
        END IF;
    END IF;
    IF TG_OP<>'DELETE' THEN
        IF NEW.queue='ingest-v1' AND NEW.status IN ('pending','running','dead') THEN
            j:=j+1; e:=e+NEW.payload_events; b:=b+NEW.payload_bytes;
        END IF;
    END IF;
    IF j<>0 OR e<>0 OR b<>0 THEN
        EXECUTE format('UPDATE %I.queue_accounting SET active_jobs=active_jobs+$1, active_events=active_events+$2, active_bytes=active_bytes+$3 WHERE queue=''ingest-v1''',TG_TABLE_SCHEMA) USING j,e,b;
        GET DIAGNOSTICS affected=ROW_COUNT;
        IF affected<>1 THEN RAISE EXCEPTION 'Queue accounting row missing' USING ERRCODE='23514'; END IF;
    END IF;
    RETURN NULL;
END;
$$;
CREATE TRIGGER jobs_account_delta AFTER INSERT OR UPDATE OR DELETE ON jobs FOR EACH ROW EXECUTE FUNCTION queue_account_delta();
CREATE FUNCTION queue_reject_truncate() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog AS $$
BEGIN RAISE EXCEPTION 'TRUNCATE jobs is forbidden; use queue lifecycle operations' USING ERRCODE='42501'; END;
$$;
CREATE TRIGGER jobs_account_no_truncate BEFORE TRUNCATE ON jobs FOR EACH STATEMENT EXECUTE FUNCTION queue_reject_truncate();
