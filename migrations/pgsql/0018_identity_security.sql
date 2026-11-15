-- Existing sessions are not automatically granted recent authentication.
ALTER TABLE auth_sessions ADD COLUMN reauthenticated_at timestamptz;
CREATE INDEX auth_sessions_last_seen_idx ON auth_sessions(last_seen_at);
