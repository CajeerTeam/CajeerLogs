<?php
declare(strict_types=1);

namespace CajeerLogs;

use PDO;
use Throwable;

final class Migrator
{
    public function __construct(private readonly PDO $pdo) {}

    public function run(): void
    {
        if (Database::driver() === 'pgsql') {
            $this->runPgsql();
        } else {
            $this->runSqlite();
        }
        Auth::seedAdminIfEmpty($this->pdo);
        $this->recordVersion('001_legacy_schema');
        $this->recordVersion('002_ops_2_13');
        $this->recordVersion('003_ops_14_25');
        $this->recordVersion('004_aapanel_site_logs');
        $this->recordVersion('005_admin_recovery_and_ops');
        $this->recordVersion('006_automation_ux_pack');
        $this->recordVersion('007_production_polish_pack');
        $this->recordVersion('008_github_update_center');
        $this->recordVersion('009_wiki_hardening_limits');
    }

    private function runPgsql(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS bot_tokens (
    id BIGSERIAL PRIMARY KEY,
    project VARCHAR(120) NOT NULL,
    bot VARCHAR(120) NOT NULL,
    environment VARCHAR(60) NOT NULL DEFAULT 'production',
    description TEXT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    is_active SMALLINT NOT NULL DEFAULT 1,
    last_used_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE bot_tokens ADD COLUMN IF NOT EXISTS rate_limit_per_minute INTEGER NOT NULL DEFAULT 120;
ALTER TABLE bot_tokens ADD COLUMN IF NOT EXISTS max_batch_size INTEGER NOT NULL DEFAULT 100;
ALTER TABLE bot_tokens ADD COLUMN IF NOT EXISTS events_limit_per_minute INTEGER NOT NULL DEFAULT 3000;
ALTER TABLE bot_tokens ADD COLUMN IF NOT EXISTS bytes_limit_per_minute INTEGER NOT NULL DEFAULT 10485760;
ALTER TABLE bot_tokens ADD COLUMN IF NOT EXISTS allowed_levels TEXT NULL;
ALTER TABLE bot_tokens ADD COLUMN IF NOT EXISTS require_signature SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE bot_tokens ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL;

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
    context JSONB NULL
);

ALTER TABLE log_events ADD COLUMN IF NOT EXISTS fingerprint CHAR(64) NULL;
ALTER TABLE log_events ADD COLUMN IF NOT EXISTS user_id_hash CHAR(64) NULL;
ALTER TABLE log_events ADD COLUMN IF NOT EXISTS chat_id_hash CHAR(64) NULL;
ALTER TABLE log_events ADD COLUMN IF NOT EXISTS guild_id_hash CHAR(64) NULL;

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
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(site, log_type, file_path)
);

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(80) PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);


ALTER TABLE incidents ADD COLUMN IF NOT EXISTS muted_until_at TIMESTAMPTZ NULL;
ALTER TABLE incidents ADD COLUMN IF NOT EXISTS muted_reason TEXT NULL;

ALTER TABLE aapanel_log_offsets ADD COLUMN IF NOT EXISTS inode BIGINT NULL;
ALTER TABLE aapanel_log_offsets ADD COLUMN IF NOT EXISTS file_size BIGINT NULL;
ALTER TABLE aapanel_log_offsets ADD COLUMN IF NOT EXISTS first_line_hash CHAR(64) NULL;
ALTER TABLE aapanel_log_offsets ADD COLUMN IF NOT EXISTS last_seen_mtime TIMESTAMPTZ NULL;
ALTER TABLE aapanel_log_offsets ADD COLUMN IF NOT EXISTS rotation_detected SMALLINT NOT NULL DEFAULT 0;

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
CREATE INDEX IF NOT EXISTS idx_bot_tokens_deleted ON bot_tokens (deleted_at);
CREATE INDEX IF NOT EXISTS idx_audit_events_created ON audit_events (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ingest_nonces_cleanup ON ingest_nonces (created_at);
CREATE INDEX IF NOT EXISTS idx_incidents_last_seen ON incidents (last_seen_at DESC);
CREATE INDEX IF NOT EXISTS idx_incidents_status ON incidents (status);
CREATE INDEX IF NOT EXISTS idx_alert_rules_active ON alert_rules (is_active);
CREATE INDEX IF NOT EXISTS idx_aapanel_log_offsets_site ON aapanel_log_offsets (site, log_type);

CREATE INDEX IF NOT EXISTS idx_login_attempts_user_ip ON login_attempts (username, ip, attempted_at DESC);
CREATE INDEX IF NOT EXISTS idx_cron_runs_task_started ON cron_runs (task, started_at DESC);
CREATE INDEX IF NOT EXISTS idx_saved_views_route ON saved_views (route, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_jobs_status_type ON jobs (status, type, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_aapanel_offsets_rotation ON aapanel_log_offsets (rotation_detected, last_import_at DESC);
CREATE INDEX IF NOT EXISTS idx_incidents_muted_until ON incidents (muted_until_at);

SQL;
        $this->pdo->exec($sql);
        $this->tryPgTrgmIndexes();
    }

    private function tryPgTrgmIndexes(): void
    {
        try {
            $this->pdo->exec('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_log_events_message_trgm ON log_events USING gin (message gin_trgm_ops)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_log_events_exception_trgm ON log_events USING gin (exception gin_trgm_ops)');
        } catch (Throwable $e) {
            Logger::error('Optional PostgreSQL trigram indexes were not created', ['exception' => $e]);
        }
    }

    private function runSqlite(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS bot_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project TEXT NOT NULL,
    bot TEXT NOT NULL,
    environment TEXT NOT NULL DEFAULT 'production',
    description TEXT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    is_active INTEGER NOT NULL DEFAULT 1,
    last_used_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL;
        $this->pdo->exec($sql);
        foreach ([
            'ALTER TABLE bot_tokens ADD COLUMN rate_limit_per_minute INTEGER NOT NULL DEFAULT 120',
            'ALTER TABLE bot_tokens ADD COLUMN max_batch_size INTEGER NOT NULL DEFAULT 100',
            'ALTER TABLE bot_tokens ADD COLUMN events_limit_per_minute INTEGER NOT NULL DEFAULT 3000',
            'ALTER TABLE bot_tokens ADD COLUMN bytes_limit_per_minute INTEGER NOT NULL DEFAULT 10485760',
            'ALTER TABLE bot_tokens ADD COLUMN allowed_levels TEXT NULL',
            'ALTER TABLE bot_tokens ADD COLUMN require_signature INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE bot_tokens ADD COLUMN deleted_at TEXT NULL',
        ] as $alter) {
            $this->execSqliteIgnoringDuplicate($alter);
        }

        $sql2 = <<<'SQL'
CREATE TABLE IF NOT EXISTS ingest_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bot_token_id INTEGER NOT NULL,
    event_count INTEGER NOT NULL DEFAULT 0,
    meta TEXT NULL,
    received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(bot_token_id) REFERENCES bot_tokens(id)
);

CREATE TABLE IF NOT EXISTS log_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_id INTEGER NULL,
    created_at TEXT NOT NULL,
    received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    level TEXT NOT NULL,
    project TEXT NOT NULL,
    bot TEXT NOT NULL,
    environment TEXT NOT NULL DEFAULT 'production',
    version TEXT NULL,
    host TEXT NULL,
    logger TEXT NULL,
    message TEXT NOT NULL,
    exception TEXT NULL,
    trace_id TEXT NULL,
    context TEXT NULL,
    fingerprint TEXT NULL,
    user_id_hash TEXT NULL,
    chat_id_hash TEXT NULL,
    guild_id_hash TEXT NULL,
    FOREIGN KEY(batch_id) REFERENCES ingest_batches(id)
);

CREATE TABLE IF NOT EXISTS audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor TEXT NULL,
    action TEXT NOT NULL,
    entity_type TEXT NULL,
    entity_id TEXT NULL,
    message TEXT NULL,
    meta TEXT NULL,
    ip TEXT NULL,
    user_agent TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ingest_nonces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash TEXT NOT NULL,
    nonce TEXT NOT NULL,
    timestamp_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(token_hash, nonce)
);

CREATE TABLE IF NOT EXISTS incidents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fingerprint TEXT NOT NULL,
    project TEXT NOT NULL,
    bot TEXT NOT NULL,
    environment TEXT NOT NULL DEFAULT 'production',
    level TEXT NOT NULL,
    title TEXT NOT NULL,
    sample_message TEXT NULL,
    sample_exception TEXT NULL,
    event_count INTEGER NOT NULL DEFAULT 0,
    first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_log_event_id INTEGER NULL,
    status TEXT NOT NULL DEFAULT 'open',
    muted_until_at TEXT NULL,
    muted_reason TEXT NULL,
    UNIQUE(project, bot, environment, level, fingerprint)
);

CREATE TABLE IF NOT EXISTS alert_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    channel TEXT NOT NULL,
    webhook_url TEXT NOT NULL,
    project TEXT NULL,
    bot TEXT NULL,
    environment TEXT NULL,
    levels TEXT NOT NULL DEFAULT 'ERROR,CRITICAL,SECURITY',
    threshold_count INTEGER NOT NULL DEFAULT 1,
    window_seconds INTEGER NOT NULL DEFAULT 300,
    cooldown_seconds INTEGER NOT NULL DEFAULT 900,
    is_active INTEGER NOT NULL DEFAULT 1,
    last_fired_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS alert_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alert_rule_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    message TEXT NULL,
    meta TEXT NULL,
    delivered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(alert_rule_id) REFERENCES alert_rules(id)
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    is_active INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS aapanel_log_offsets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site TEXT NOT NULL,
    log_type TEXT NOT NULL,
    file_path TEXT NOT NULL,
    offset_bytes INTEGER NOT NULL DEFAULT 0,
    imported_lines INTEGER NOT NULL DEFAULT 0,
    last_import_at TEXT NULL,
    inode INTEGER NULL,
    file_size INTEGER NULL,
    first_line_hash TEXT NULL,
    last_seen_mtime TEXT NULL,
    rotation_detected INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(site, log_type, file_path)
);


CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    ip TEXT NOT NULL,
    user_agent TEXT NULL,
    success INTEGER NOT NULL DEFAULT 0,
    attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cron_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task TEXT NOT NULL,
    status TEXT NOT NULL,
    message TEXT NULL,
    meta TEXT NULL,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT NULL,
    duration_ms INTEGER NULL
);

CREATE TABLE IF NOT EXISTS saved_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    route TEXT NOT NULL DEFAULT '/logs',
    query TEXT NOT NULL,
    is_shared INTEGER NOT NULL DEFAULT 1,
    created_by TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    payload TEXT NULL,
    result TEXT NULL,
    error TEXT NULL,
    created_by TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TEXT NULL,
    finished_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS update_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor TEXT NULL,
    action TEXT NOT NULL DEFAULT 'update',
    status TEXT NOT NULL,
    repo_url TEXT NULL,
    branch TEXT NULL,
    from_version TEXT NULL,
    to_version TEXT NULL,
    from_commit TEXT NULL,
    to_commit TEXT NULL,
    backup_path TEXT NULL,
    output_log TEXT NULL,
    error_message TEXT NULL,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT NULL,
    duration_ms INTEGER NULL
);

CREATE INDEX IF NOT EXISTS idx_update_runs_started ON update_runs (started_at DESC);
CREATE INDEX IF NOT EXISTS idx_update_runs_status ON update_runs (status, started_at DESC);


CREATE TABLE IF NOT EXISTS schema_migrations (
    version TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_log_events_created_at ON log_events (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_log_events_project_bot_created ON log_events (project, bot, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_log_events_level_created ON log_events (level, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_log_events_trace_id ON log_events (trace_id);
CREATE INDEX IF NOT EXISTS idx_log_events_received_at ON log_events (received_at DESC);
CREATE INDEX IF NOT EXISTS idx_log_events_fingerprint ON log_events (fingerprint);
CREATE INDEX IF NOT EXISTS idx_log_events_user_id_hash ON log_events (user_id_hash);
CREATE INDEX IF NOT EXISTS idx_ingest_batches_token_received ON ingest_batches (bot_token_id, received_at DESC);
CREATE INDEX IF NOT EXISTS idx_bot_tokens_lookup ON bot_tokens (token_hash, is_active);
CREATE INDEX IF NOT EXISTS idx_bot_tokens_deleted ON bot_tokens (deleted_at);
CREATE INDEX IF NOT EXISTS idx_audit_events_created ON audit_events (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ingest_nonces_cleanup ON ingest_nonces (created_at);
CREATE INDEX IF NOT EXISTS idx_incidents_last_seen ON incidents (last_seen_at DESC);
CREATE INDEX IF NOT EXISTS idx_incidents_status ON incidents (status);
CREATE INDEX IF NOT EXISTS idx_alert_rules_active ON alert_rules (is_active);
CREATE INDEX IF NOT EXISTS idx_aapanel_log_offsets_site ON aapanel_log_offsets (site, log_type);

CREATE INDEX IF NOT EXISTS idx_login_attempts_user_ip ON login_attempts (username, ip, attempted_at DESC);
CREATE INDEX IF NOT EXISTS idx_cron_runs_task_started ON cron_runs (task, started_at DESC);
CREATE INDEX IF NOT EXISTS idx_saved_views_route ON saved_views (route, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_jobs_status_type ON jobs (status, type, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_aapanel_offsets_rotation ON aapanel_log_offsets (rotation_detected, last_import_at DESC);
CREATE INDEX IF NOT EXISTS idx_incidents_muted_until ON incidents (muted_until_at);

SQL;
        $this->pdo->exec($sql2);
        foreach (['fingerprint TEXT NULL','user_id_hash TEXT NULL','chat_id_hash TEXT NULL','guild_id_hash TEXT NULL'] as $column) {
            $this->execSqliteIgnoringDuplicate('ALTER TABLE log_events ADD COLUMN ' . $column);
        }
        foreach ([
            'muted_until_at TEXT NULL',
            'muted_reason TEXT NULL',
        ] as $column) {
            $this->execSqliteIgnoringDuplicate('ALTER TABLE incidents ADD COLUMN ' . $column);
        }
        foreach ([
            'inode INTEGER NULL',
            'file_size INTEGER NULL',
            'first_line_hash TEXT NULL',
            'last_seen_mtime TEXT NULL',
            'rotation_detected INTEGER NOT NULL DEFAULT 0',
        ] as $column) {
            $this->execSqliteIgnoringDuplicate('ALTER TABLE aapanel_log_offsets ADD COLUMN ' . $column);
        }
    }

    private function recordVersion(string $version): void
    {
        try {
            if (Database::driver() === 'pgsql') {
                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, NOW()) ON CONFLICT (version) DO NOTHING');
            } else {
                $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO schema_migrations (version, applied_at) VALUES (:version, CURRENT_TIMESTAMP)');
            }
            $stmt->execute(['version' => $version]);
        } catch (Throwable $e) {
            Logger::error('Could not record migration version', ['version' => $version, 'exception' => $e]);
        }
    }

    private function execSqliteIgnoringDuplicate(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column name')) {
                throw $e;
            }
        }
    }
}
