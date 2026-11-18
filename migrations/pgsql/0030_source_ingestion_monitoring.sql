-- 0.7.4: source admission telemetry, distinct from retained log events.
-- Existing cumulative source totals remain authoritative; historical minutes are not fabricated.
ALTER TABLE sources ADD COLUMN ingestion_metrics_since timestamptz NOT NULL DEFAULT now();

CREATE TABLE source_ingest_minutes (
    project_id uuid NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    source_id uuid NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    minute timestamptz NOT NULL,
    accepted bigint NOT NULL DEFAULT 0 CHECK (accepted >= 0),
    rejected bigint NOT NULL DEFAULT 0 CHECK (rejected >= 0),
    PRIMARY KEY (project_id, source_id, minute)
);
CREATE INDEX source_ingest_minutes_expiration_idx ON source_ingest_minutes(minute);
