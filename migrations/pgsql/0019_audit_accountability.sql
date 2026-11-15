-- Preserve historical rows. Chain order is insertion order after migration, not event time.
ALTER TABLE audit_log ADD COLUMN project_id uuid;
ALTER TABLE audit_log ADD COLUMN result varchar(16) NOT NULL DEFAULT 'success';
ALTER TABLE audit_log ADD COLUMN actor_type varchar(16) NOT NULL DEFAULT 'system';
ALTER TABLE audit_log ADD COLUMN chain_position bigint;
ALTER TABLE audit_log ADD COLUMN previous_hash text;
ALTER TABLE audit_log ADD COLUMN event_hash text;
CREATE FUNCTION audit_scope(action text, target text, context jsonb) RETURNS uuid
LANGUAGE sql IMMUTABLE SET search_path = pg_catalog AS $$
 SELECT CASE WHEN candidate ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN candidate::uuid END
 FROM (SELECT CASE WHEN action IN ('project.created','project.updated','project.archived','project.restored','project.signing_policy','token.bulk_revoked') THEN target
 WHEN action IN ('project.member_added','project.member_role_set','project.member_removed') THEN split_part(target,':',1)
 ELSE context->>'project_id' END AS candidate) s
$$;
CREATE FUNCTION audit_digest(pos bigint, prev text, event_id uuid, actor uuid, kind text, action text, target text, project uuid, result text, context jsonb, happened timestamptz) RETURNS text
LANGUAGE sql IMMUTABLE SET search_path = pg_catalog AS $$
 SELECT encode(sha256(convert_to(jsonb_build_array(pos,prev,event_id,actor,kind,action,target,project,result,context,extract(epoch FROM happened))::text,'UTF8')),'hex')
$$;
UPDATE audit_log SET project_id=audit_scope(action,target,context), result=CASE WHEN action IN ('auth.login_failed','auth.reauthentication_failed') THEN 'failure' ELSE 'success' END, actor_type=CASE WHEN actor_id IS NOT NULL THEN 'user' WHEN action='auth.login_failed' THEN 'anonymous' ELSE 'system' END;
-- Existing audit data is a baseline, not proof that it was never modified before upgrade.
CREATE FUNCTION audit_backfill() RETURNS void LANGUAGE plpgsql AS $$
DECLARE r record; pos bigint := 0; prev text := repeat('0',64); digest text;
BEGIN
 FOR r IN SELECT * FROM audit_log ORDER BY created_at,id LOOP
  pos := pos+1;
  digest := audit_digest(pos,prev,r.id,r.actor_id,r.actor_type,r.action,r.target,r.project_id,r.result,r.context,r.created_at);
  UPDATE audit_log SET chain_position=pos,previous_hash=prev,event_hash=digest WHERE id=r.id;
  prev := digest;
 END LOOP;
END $$;
SELECT audit_backfill();
DROP FUNCTION audit_backfill();
ALTER TABLE audit_log ALTER COLUMN chain_position SET NOT NULL;
ALTER TABLE audit_log ALTER COLUMN previous_hash SET NOT NULL;
ALTER TABLE audit_log ALTER COLUMN event_hash SET NOT NULL;
ALTER TABLE audit_log ADD CONSTRAINT audit_chain_position_unique UNIQUE(chain_position);
ALTER TABLE audit_log ADD CONSTRAINT audit_chain_values CHECK(chain_position>0 AND previous_hash ~ '^[0-9a-f]{64}$' AND event_hash ~ '^[0-9a-f]{64}$' AND result IN ('success','failure','denied') AND actor_type IN ('user','token','system','anonymous'));
CREATE INDEX audit_log_project_time_idx ON audit_log(project_id,created_at DESC,id DESC);
CREATE INDEX audit_log_time_idx ON audit_log(created_at DESC,id DESC);
CREATE INDEX audit_log_action_time_idx ON audit_log(action,created_at DESC,id DESC);
CREATE FUNCTION audit_append() RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog AS $$
DECLARE pos bigint; prev text;
BEGIN
 -- Serialize the chain per schema until commit. The unique constraint also fails closed
 -- if a stale transaction snapshot cannot observe the latest committed predecessor.
 PERFORM pg_advisory_xact_lock(hashtextextended('cajeer.audit:'||TG_TABLE_SCHEMA,0));
 EXECUTE format('SELECT chain_position,event_hash FROM %I.audit_log ORDER BY chain_position DESC LIMIT 1',TG_TABLE_SCHEMA) INTO pos,prev;
 NEW.chain_position := coalesce(pos,0)+1;
 NEW.previous_hash := coalesce(prev,repeat('0',64));
 EXECUTE format('SELECT %I.audit_scope($1,$2,$3)',TG_TABLE_SCHEMA) INTO NEW.project_id USING NEW.action,NEW.target,NEW.context;
 EXECUTE format('SELECT %I.audit_digest($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)',TG_TABLE_SCHEMA) INTO NEW.event_hash USING NEW.chain_position,NEW.previous_hash,NEW.id,NEW.actor_id,NEW.actor_type,NEW.action,NEW.target,NEW.project_id,NEW.result,NEW.context,NEW.created_at;
 RETURN NEW;
END $$;
CREATE FUNCTION audit_immutable() RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog AS $$
BEGIN
 RAISE EXCEPTION 'Audit log is append-only' USING ERRCODE='42501';
END $$;
CREATE TRIGGER audit_append_before_insert BEFORE INSERT ON audit_log FOR EACH ROW EXECUTE FUNCTION audit_append();
CREATE TRIGGER audit_reject_mutation BEFORE UPDATE OR DELETE OR TRUNCATE ON audit_log FOR EACH STATEMENT EXECUTE FUNCTION audit_immutable();
ALTER TABLE audit_log ENABLE ALWAYS TRIGGER audit_append_before_insert;
ALTER TABLE audit_log ENABLE ALWAYS TRIGGER audit_reject_mutation;
