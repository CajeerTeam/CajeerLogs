-- Add public identifiers without changing existing cookie/token hashes or expiry.
ALTER TABLE auth_sessions ADD COLUMN id uuid NOT NULL DEFAULT gen_random_uuid();
ALTER TABLE auth_sessions ADD CONSTRAINT auth_sessions_public_id_unique UNIQUE (id);
ALTER TABLE auth_sessions ADD COLUMN user_agent varchar(512);
ALTER TABLE auth_sessions ADD COLUMN remote_address varchar(64);
CREATE INDEX auth_sessions_user_public_id_idx ON auth_sessions(user_id,id);
ALTER TABLE users ADD COLUMN display_name varchar(120) NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN last_login_at timestamptz;
