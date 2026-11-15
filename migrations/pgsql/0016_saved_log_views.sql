CREATE TABLE saved_log_views (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id uuid NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    owner_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name varchar(120) NOT NULL CHECK (length(btrim(name)) > 0),
    filters jsonb NOT NULL CHECK (jsonb_typeof(filters) = 'object'),
    layout jsonb NOT NULL CHECK (jsonb_typeof(layout) = 'object'),
    revision integer NOT NULL DEFAULT 1 CHECK (revision > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (project_id, owner_id, name)
);
CREATE INDEX saved_log_views_owner_idx ON saved_log_views(owner_id, project_id, id);
