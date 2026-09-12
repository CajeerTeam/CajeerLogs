-- CajeerLogs PostgreSQL 17+ initial schema
CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE IF NOT EXISTS users (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email varchar(255) NOT NULL UNIQUE,
    password_hash varchar(255) NOT NULL,
    role varchar(64) NOT NULL DEFAULT 'viewer',
    two_factor_enabled boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS api_tokens (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(255) NOT NULL,
    token_hash varchar(255) NOT NULL UNIQUE,
    scopes jsonb NOT NULL DEFAULT '[]'::jsonb,
    last_used_at timestamptz,
    expires_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS log_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    occurred_at timestamptz NOT NULL,
    received_at timestamptz NOT NULL DEFAULT now(),
    source varchar(255) NOT NULL,
    service varchar(255),
    host varchar(255),
    level varchar(32) NOT NULL,
    message text NOT NULL,
    context jsonb NOT NULL DEFAULT '{}'::jsonb,
    trace_id varchar(128),
    request_id varchar(128),
    tags text[] NOT NULL DEFAULT '{}',
    search_vector tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('simple', coalesce(message, '')), 'A') ||
        setweight(to_tsvector('simple', coalesce(source, '')), 'B') ||
        setweight(to_tsvector('simple', coalesce(service, '')), 'C')
    ) STORED
);

CREATE INDEX IF NOT EXISTS idx_log_events_occurred_at ON log_events (occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_log_events_level ON log_events (level);
CREATE INDEX IF NOT EXISTS idx_log_events_source ON log_events (source);
CREATE INDEX IF NOT EXISTS idx_log_events_trace_id ON log_events (trace_id);
CREATE INDEX IF NOT EXISTS idx_log_events_search ON log_events USING gin (search_vector);
CREATE INDEX IF NOT EXISTS idx_log_events_context ON log_events USING gin (context);

CREATE TABLE IF NOT EXISTS audit_log (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    actor_id uuid NULL,
    action varchar(255) NOT NULL,
    target varchar(255),
    context jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS jobs (
    id bigserial PRIMARY KEY,
    queue varchar(128) NOT NULL DEFAULT 'default',
    name varchar(255) NOT NULL,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    attempts integer NOT NULL DEFAULT 0,
    available_at timestamptz NOT NULL DEFAULT now(),
    reserved_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS settings (
    key varchar(255) PRIMARY KEY,
    value jsonb NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS webhooks (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    url text NOT NULL,
    events jsonb NOT NULL DEFAULT '[]'::jsonb,
    secret_hash varchar(255),
    enabled boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS extensions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(255) NOT NULL UNIQUE,
    type varchar(32) NOT NULL,
    version varchar(64) NOT NULL,
    manifest jsonb NOT NULL,
    enabled boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now()
);
