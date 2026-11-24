CREATE TABLE skif4er_identities (
    subject varchar(255) PRIMARY KEY,
    user_id uuid NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE skif4er_login_flows (
    browser_hash char(64) PRIMARY KEY,
    state_hash char(64) UNIQUE,
    nonce varchar(64),
    verifier varchar(64),
    subject varchar(255),
    user_id uuid REFERENCES users(id) ON DELETE CASCADE,
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 5),
    expires_at timestamptz NOT NULL,
    CHECK ((state_hash IS NOT NULL AND nonce IS NOT NULL AND verifier IS NOT NULL AND subject IS NULL AND user_id IS NULL) OR
           (state_hash IS NULL AND nonce IS NULL AND verifier IS NULL AND subject IS NOT NULL AND user_id IS NOT NULL))
);
CREATE INDEX skif4er_login_flows_expiry_idx ON skif4er_login_flows(expires_at);
