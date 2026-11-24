-- Private per-user/project history of explicitly saved AI investigations.
CREATE TABLE ai_investigation_history (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id uuid NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    question text NOT NULL CHECK (octet_length(question) BETWEEN 1 AND 8000),
    result jsonb NOT NULL CHECK (jsonb_typeof(result)='object' AND octet_length(result::text)<=100000)
);
CREATE INDEX ai_history_owner_idx ON ai_investigation_history(user_id,project_id,created_at DESC,id DESC);
CREATE INDEX ai_history_expiry_idx ON ai_investigation_history(created_at,id);
