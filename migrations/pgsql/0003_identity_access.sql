ALTER TABLE users ADD COLUMN blocked_at timestamptz;
ALTER TABLE users ADD CONSTRAINT users_role_valid CHECK (role IN ('administrator','operator','viewer'));
CREATE TABLE project_members (
    project_id uuid NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY(project_id,user_id)
);
CREATE INDEX project_members_user_idx ON project_members(user_id,project_id);
CREATE TABLE auth_sessions (
    token_hash char(64) PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    csrf_token char(64) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL,
    last_seen_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX auth_sessions_user_idx ON auth_sessions(user_id);
CREATE INDEX auth_sessions_expiry_idx ON auth_sessions(expires_at);
CREATE TABLE auth_rate_limits (
    bucket_key char(64) NOT NULL,
    window_start bigint NOT NULL,
    hits integer NOT NULL CHECK (hits > 0),
    PRIMARY KEY(bucket_key,window_start)
);
CREATE INDEX auth_rate_limits_expiry_idx ON auth_rate_limits(window_start);
ALTER TABLE api_tokens ADD COLUMN created_by uuid REFERENCES users(id) ON DELETE SET NULL;
CREATE INDEX audit_log_actor_time_idx ON audit_log(actor_id,created_at DESC);
