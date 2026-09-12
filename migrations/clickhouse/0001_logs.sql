-- CajeerLogs ClickHouse recommended schema for high-load analytics
CREATE DATABASE IF NOT EXISTS cajeer_logs;

CREATE TABLE IF NOT EXISTS cajeer_logs.log_events
(
    id UUID,
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
    tags Array(String)
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(occurred_at)
ORDER BY (source, service, level, occurred_at, id)
TTL toDateTime(occurred_at) + INTERVAL 180 DAY;
