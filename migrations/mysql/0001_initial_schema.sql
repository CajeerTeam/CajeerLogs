-- CajeerLogs MySQL 8.4+ / MariaDB 10.11+ optional schema skeleton
CREATE TABLE IF NOT EXISTS users (
    id char(36) PRIMARY KEY,
    email varchar(255) NOT NULL UNIQUE,
    password_hash varchar(255) NOT NULL,
    role varchar(64) NOT NULL DEFAULT 'viewer',
    two_factor_enabled boolean NOT NULL DEFAULT false,
    created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS log_events (
    id char(36) PRIMARY KEY,
    occurred_at timestamp NOT NULL,
    received_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source varchar(255) NOT NULL,
    service varchar(255),
    host varchar(255),
    level varchar(32) NOT NULL,
    message text NOT NULL,
    context json NOT NULL,
    trace_id varchar(128),
    request_id varchar(128),
    FULLTEXT KEY ft_log_message (message)
);
