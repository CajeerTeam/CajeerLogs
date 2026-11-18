-- Indexed project-local correlation and service/host lookups for Explorer.
-- Apply in a maintenance window on large existing tables (transactional migrator).
-- Partial indexes exclude unscoped legacy logs and missing identifiers.
CREATE INDEX log_events_project_trace_time_077 ON log_events(project_id,trace_id,occurred_at DESC,id DESC)
    WHERE source_id IS NOT NULL AND trace_id IS NOT NULL;
CREATE INDEX log_events_project_request_time_077 ON log_events(project_id,request_id,occurred_at DESC,id DESC)
    WHERE source_id IS NOT NULL AND request_id IS NOT NULL;
CREATE INDEX log_events_project_service_time_077 ON log_events(project_id,service,occurred_at DESC,id DESC)
    WHERE source_id IS NOT NULL AND service IS NOT NULL;
CREATE INDEX log_events_project_host_time_077 ON log_events(project_id,host,occurred_at DESC,id DESC)
    WHERE source_id IS NOT NULL AND host IS NOT NULL;
