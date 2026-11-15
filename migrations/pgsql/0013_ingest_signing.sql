ALTER TABLE projects ADD COLUMN require_ingest_hmac boolean NOT NULL DEFAULT false;
CREATE TABLE ingest_signing (
    token_id uuid PRIMARY KEY REFERENCES api_tokens(id) ON DELETE RESTRICT,
    current_key_id uuid UNIQUE,
    current_ciphertext text,
    previous_key_id uuid UNIQUE,
    previous_ciphertext text,
    previous_until timestamptz,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CHECK ((current_key_id IS NULL) = (current_ciphertext IS NULL)),
    CHECK ((previous_key_id IS NULL) = (previous_ciphertext IS NULL) AND (previous_key_id IS NULL) = (previous_until IS NULL)),
    CHECK (previous_key_id IS NULL OR (current_key_id IS NOT NULL AND previous_key_id <> current_key_id))
);
