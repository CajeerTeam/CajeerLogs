CREATE TABLE rbac_roles (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(120) NOT NULL CHECK (length(btrim(name)) > 0),
    scope text NOT NULL CHECK (scope IN ('global','project')),
    permissions jsonb NOT NULL CHECK (jsonb_typeof(permissions) = 'array'),
    revision integer NOT NULL DEFAULT 1 CHECK (revision > 0),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (scope, name)
);
ALTER TABLE users ADD COLUMN rbac_role_id uuid REFERENCES rbac_roles(id) ON DELETE RESTRICT;
ALTER TABLE users ADD CONSTRAINT users_rbac_no_admin CHECK (role <> 'administrator' OR rbac_role_id IS NULL);
ALTER TABLE project_members ADD COLUMN rbac_role_id uuid REFERENCES rbac_roles(id) ON DELETE RESTRICT;
CREATE INDEX users_rbac_role_idx ON users(rbac_role_id) WHERE rbac_role_id IS NOT NULL;
CREATE INDEX project_members_rbac_role_idx ON project_members(rbac_role_id) WHERE rbac_role_id IS NOT NULL;
