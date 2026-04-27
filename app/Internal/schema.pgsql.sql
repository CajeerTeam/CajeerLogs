-- Cajeer Logs full PostgreSQL schema
-- Apply on an empty database as the application DB owner.

CREATE TABLE IF NOT EXISTS bot_tokens (
    id BIGSERIAL PRIMARY KEY,
    project VARCHAR(120) NOT NULL,
    bot VARCHAR(120) NOT NULL,
    environment VARCHAR(60) NOT NULL DEFAULT 'production',
    description TEXT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    is_active SMALLINT NOT NULL DEFAULT 1,
    last_used_at TIMESTAMPTZ NULL,
    rate_limit_per_minute INTEGER NOT NULL DEFAULT 120,
    max_batch_size INTEGER NOT NULL DEFAULT 100,
    allowed_levels TEXT NULL,
    require_signature SMALLINT NOT NULL DEFAULT 0,
    deleted_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ingest_batches (
    id BIGSERIAL PRIMARY KEY,
    bot_token_id BIGINT NOT NULL REFERENCES bot_tokens(id) ON DELETE RESTRICT,
    event_count INTEGER NOT NULL DEFAULT 0,
    meta JSONB NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS log_events (
    id BIGSERIAL PRIMARY KEY,
    batch_id BIGINT NULL REFERENCES ingest_batches(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    level VARCHAR(20) NOT NULL,
    project VARCHAR(120) NOT NULL,
    bot VARCHAR(120) NOT NULL,
    environment VARCHAR(60) NOT NULL DEFAULT 'production',
    version VARCHAR(80) NULL,
    host VARCHAR(120) NULL,
    logger VARCHAR(190) NULL,
    message TEXT NOT NULL,
    exception TEXT NULL,
    trace_id VARCHAR(120) NULL,
    context JSONB NULL,
    fingerprint CHAR(64) NULL,
    user_id_hash CHAR(64) NULL,
    chat_id_hash CHAR(64) NULL,
    guild_id_hash CHAR(64) NULL
);

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGSERIAL PRIMARY KEY,
    actor VARCHAR(190) NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id VARCHAR(80) NULL,
    message TEXT NULL,
    meta JSONB NULL,
    ip VARCHAR(80) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ingest_nonces (
    id BIGSERIAL PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    nonce VARCHAR(128) NOT NULL,
    timestamp_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(token_hash, nonce)
);

CREATE TABLE IF NOT EXISTS incidents (
    id BIGSERIAL PRIMARY KEY,
    fingerprint CHAR(64) NOT NULL,
    project VARCHAR(120) NOT NULL,
    bot VARCHAR(120) NOT NULL,
    environment VARCHAR(60) NOT NULL DEFAULT 'production',
    level VARCHAR(20) NOT NULL,
    title TEXT NOT NULL,
    sample_message TEXT NULL,
    sample_exception TEXT NULL,
    event_count BIGINT NOT NULL DEFAULT 0,
    first_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_log_event_id BIGINT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    muted_until_at TIMESTAMPTZ NULL,
    muted_reason TEXT NULL,
    UNIQUE(project, bot, environment, level, fingerprint)
);

CREATE TABLE IF NOT EXISTS alert_rules (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    channel VARCHAR(30) NOT NULL,
    webhook_url TEXT NOT NULL,
    project VARCHAR(120) NULL,
    bot VARCHAR(120) NULL,
    environment VARCHAR(60) NULL,
    levels TEXT NOT NULL DEFAULT 'ERROR,CRITICAL,SECURITY',
    threshold_count INTEGER NOT NULL DEFAULT 1,
    window_seconds INTEGER NOT NULL DEFAULT 300,
    cooldown_seconds INTEGER NOT NULL DEFAULT 900,
    is_active SMALLINT NOT NULL DEFAULT 1,
    last_fired_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS alert_deliveries (
    id BIGSERIAL PRIMARY KEY,
    alert_rule_id BIGINT NOT NULL REFERENCES alert_rules(id) ON DELETE CASCADE,
    status VARCHAR(30) NOT NULL,
    message TEXT NULL,
    meta JSONB NULL,
    delivered_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(120) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'admin',
    is_active SMALLINT NOT NULL DEFAULT 1,
    last_login_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS aapanel_log_offsets (
    id BIGSERIAL PRIMARY KEY,
    site VARCHAR(190) NOT NULL,
    log_type VARCHAR(20) NOT NULL,
    file_path TEXT NOT NULL,
    offset_bytes BIGINT NOT NULL DEFAULT 0,
    imported_lines BIGINT NOT NULL DEFAULT 0,
    last_import_at TIMESTAMPTZ NULL,
    inode BIGINT NULL,
    file_size BIGINT NULL,
    first_line_hash CHAR(64) NULL,
    last_seen_mtime TIMESTAMPTZ NULL,
    rotation_detected SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(site, log_type, file_path)
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(120) NOT NULL,
    ip VARCHAR(80) NOT NULL,
    user_agent TEXT NULL,
    success SMALLINT NOT NULL DEFAULT 0,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS cron_runs (
    id BIGSERIAL PRIMARY KEY,
    task VARCHAR(120) NOT NULL,
    status VARCHAR(30) NOT NULL,
    message TEXT NULL,
    meta JSONB NULL,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ NULL,
    duration_ms INTEGER NULL
);

CREATE TABLE IF NOT EXISTS saved_views (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    route VARCHAR(120) NOT NULL DEFAULT '/logs',
    query TEXT NOT NULL,
    is_shared SMALLINT NOT NULL DEFAULT 1,
    created_by VARCHAR(190) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS jobs (
    id BIGSERIAL PRIMARY KEY,
    type VARCHAR(120) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    payload JSONB NULL,
    result JSONB NULL,
    error TEXT NULL,
    created_by VARCHAR(190) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at TIMESTAMPTZ NULL,
    finished_at TIMESTAMPTZ NULL
);

CREATE TABLE IF NOT EXISTS app_settings (
    key VARCHAR(160) PRIMARY KEY,
    value TEXT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS update_runs (
    id BIGSERIAL PRIMARY KEY,
    actor VARCHAR(190) NULL,
    action VARCHAR(40) NOT NULL DEFAULT 'update',
    status VARCHAR(30) NOT NULL,
    repo_url TEXT NULL,
    branch VARCHAR(120) NULL,
    from_version VARCHAR(80) NULL,
    to_version VARCHAR(80) NULL,
    from_commit VARCHAR(80) NULL,
    to_commit VARCHAR(80) NULL,
    backup_path TEXT NULL,
    output_log TEXT NULL,
    error_message TEXT NULL,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ NULL,
    duration_ms INTEGER NULL
);

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(80) PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_update_runs_started ON update_runs (started_at DESC);
CREATE INDEX IF NOT EXISTS idx_update_runs_status ON update_runs (status, started_at DESC);
CREATE INDEX IF NOT EXISTS idx_log_events_created_at ON log_events (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_log_events_project_bot_created ON log_events (project, bot, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_log_events_level_created ON log_events (level, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_log_events_trace_id ON log_events (trace_id);
CREATE INDEX IF NOT EXISTS idx_log_events_received_at ON log_events (received_at DESC);
CREATE INDEX IF NOT EXISTS idx_log_events_fingerprint ON log_events (fingerprint);
CREATE INDEX IF NOT EXISTS idx_log_events_user_id_hash ON log_events (user_id_hash);
CREATE INDEX IF NOT EXISTS idx_ingest_batches_token_received ON ingest_batches (bot_token_id, received_at DESC);
CREATE INDEX IF NOT EXISTS idx_bot_tokens_lookup ON bot_tokens (token_hash, is_active);
CREATE INDEX IF NOT EXISTS idx_audit_events_created ON audit_events (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_incidents_last_seen ON incidents (last_seen_at DESC);
CREATE INDEX IF NOT EXISTS idx_alert_rules_active ON alert_rules (is_active);
CREATE INDEX IF NOT EXISTS idx_login_attempts_user_ip ON login_attempts (username, ip, attempted_at DESC);
CREATE INDEX IF NOT EXISTS idx_cron_runs_task_started ON cron_runs (task, started_at DESC);
CREATE INDEX IF NOT EXISTS idx_saved_views_route ON saved_views (route, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_jobs_status_type ON jobs (status, type, created_at ASC);
