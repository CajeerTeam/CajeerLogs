-- Indexes used by project-scoped filters and deterministic keyset pagination.
-- Transactional migration; on a large existing table schedule a maintenance window.
CREATE INDEX idx_log_events_project_source_time ON log_events(project_id, source_id, occurred_at DESC, id DESC);
CREATE INDEX idx_log_events_project_level_time ON log_events(project_id, level, occurred_at DESC, id DESC);
CREATE INDEX idx_log_events_project_request ON log_events(project_id, request_id) WHERE request_id IS NOT NULL;
CREATE INDEX idx_log_events_tags ON log_events USING gin(tags);
