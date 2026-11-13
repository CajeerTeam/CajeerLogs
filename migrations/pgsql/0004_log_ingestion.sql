-- Preserve historic unscoped rows, but require a registered source for all new writes.
ALTER TABLE log_events ADD COLUMN ingest_token_id uuid REFERENCES api_tokens(id) ON DELETE SET NULL;
ALTER TABLE log_events ADD CONSTRAINT log_events_ingest_scope CHECK (project_id IS NOT NULL AND source_id IS NOT NULL) NOT VALID;

-- Receipts contain only keyed request digests and event UUIDs, never original log content.
CREATE TABLE ingest_receipts (
    token_id uuid NOT NULL REFERENCES api_tokens(id) ON DELETE CASCADE,
    key_hash char(64) NOT NULL,
    request_hash char(64) NOT NULL,
    event_ids jsonb NOT NULL CHECK (jsonb_typeof(event_ids) = 'array'),
    expires_at timestamptz NOT NULL,
    PRIMARY KEY (token_id, key_hash)
);
CREATE INDEX ingest_receipts_expiry_idx ON ingest_receipts(expires_at);
CREATE TABLE ingest_nonces (
    token_id uuid NOT NULL REFERENCES api_tokens(id) ON DELETE CASCADE,
    nonce_hash char(64) NOT NULL,
    expires_at timestamptz NOT NULL,
    PRIMARY KEY (token_id, nonce_hash)
);
CREATE INDEX ingest_nonces_expiry_idx ON ingest_nonces(expires_at);
