-- Validate historical scoped data before accepting the new schema. Never repair silently.
ALTER TABLE api_tokens ADD CONSTRAINT api_tokens_project_id_unique UNIQUE(project_id,id);
ALTER TABLE sources ADD CONSTRAINT sources_project_id_slug_unique UNIQUE(project_id,id,slug);
ALTER TABLE log_events ADD CONSTRAINT log_token_project_required CHECK (ingest_token_id IS NULL OR project_id IS NOT NULL),
    ADD CONSTRAINT log_token_project_fk FOREIGN KEY (project_id,ingest_token_id) REFERENCES api_tokens(project_id,id),
    ADD CONSTRAINT log_source_slug_fk FOREIGN KEY (project_id,source_id,source) REFERENCES sources(project_id,id,slug);
ALTER TABLE jobs ADD CONSTRAINT jobs_token_project_fk FOREIGN KEY(project_id,token_id) REFERENCES api_tokens(project_id,id),
    ADD CONSTRAINT jobs_ingest_lease_state CHECK (queue<>'ingest-v1' OR (
        (status='running' AND lease_id IS NOT NULL AND lease_until IS NOT NULL AND reserved_at IS NOT NULL)
        OR (status<>'running' AND lease_id IS NULL AND lease_until IS NULL AND reserved_at IS NULL))),
    ADD CONSTRAINT jobs_ingest_completion_state CHECK (queue<>'ingest-v1' OR ((status IN ('completed','dead','cancelled'))=(completed_at IS NOT NULL)));
-- Scope-aware trace lookup and FK checks without scans of the log heap.
-- Replace the existing request filter index with the same prefix plus keyset order.
CREATE INDEX idx_log_events_project_request_time ON log_events(project_id,request_id,occurred_at DESC,id DESC) WHERE request_id IS NOT NULL;
DROP INDEX idx_log_events_project_request;
CREATE INDEX idx_log_events_project_trace_time ON log_events(project_id,trace_id,occurred_at DESC,id DESC) WHERE trace_id IS NOT NULL;
CREATE INDEX idx_log_events_token ON log_events(ingest_token_id,project_id) WHERE ingest_token_id IS NOT NULL;
CREATE INDEX idx_jobs_token ON jobs(token_id,project_id) WHERE token_id IS NOT NULL;
-- Forward scan in exact retention order (the existing source_received index uses DESC time).
CREATE INDEX idx_log_events_source_retention ON log_events(source_id,received_at,id) WHERE source_id IS NOT NULL;
