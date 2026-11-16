-- Durable bounded lifecycle history. No log payloads or account identifiers.
CREATE TABLE retention_runs (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    finished_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    status text NOT NULL CHECK (status IN ('success','failed')),
    batch_limit integer NOT NULL CHECK (batch_limit BETWEEN 1 AND 10000),
    removed integer CHECK (removed >= 0 AND removed <= batch_limit),
    backlog_lower_bound integer CHECK (backlog_lower_bound >= 0),
    oldest_received_at timestamptz,
    sample_capped boolean NOT NULL,
    duration_ms integer CHECK (duration_ms >= 0),
    total_removed bigint NOT NULL CHECK (total_removed >= 0),
    CHECK ((status='success' AND removed IS NOT NULL AND backlog_lower_bound IS NOT NULL) OR (status='failed' AND removed IS NULL AND backlog_lower_bound IS NULL))
);
