-- Versioned, idempotent analytics mirror. FINAL is mandatory for correct
-- query results after uncertain acknowledgement, replay or mutations.
-- A delete is represented by a versioned tombstone, not an async ALTER DELETE.
CREATE TABLE IF NOT EXISTS cajeer_logs.replicated_log_events
(
    id UUID,
    project_id UUID,
    source_id UUID,
    occurred_at DateTime64(3, 'UTC'),
    received_at DateTime64(3, 'UTC'),
    source LowCardinality(String),
    service LowCardinality(String),
    host LowCardinality(String),
    level LowCardinality(String),
    message String,
    context String,
    trace_id String,
    request_id String,
    tags Array(String),
    revision UInt64,
    deleted UInt8
)
ENGINE = ReplacingMergeTree(revision,deleted)
PARTITION BY toYYYYMM(received_at)
ORDER BY (project_id,id)
TTL toDateTime(received_at) + INTERVAL 180 DAY;
