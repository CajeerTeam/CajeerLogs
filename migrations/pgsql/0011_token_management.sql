CREATE TABLE service_accounts (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
    name varchar(255) NOT NULL CHECK (length(trim(name)) > 0),
    created_by uuid REFERENCES users(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    disabled_at timestamptz,
    UNIQUE (project_id,id), UNIQUE (project_id,name)
);
ALTER TABLE api_tokens
    ADD COLUMN description text NOT NULL DEFAULT '' CHECK (length(description) <= 2000),
    ADD COLUMN requests_per_minute integer CHECK (requests_per_minute BETWEEN 1 AND 1000000),
    ADD COLUMN revision bigint NOT NULL DEFAULT 1 CHECK (revision > 0),
    ADD COLUMN updated_at timestamptz NOT NULL DEFAULT now(),
    ADD COLUMN service_account_id uuid,
    ADD COLUMN rotated_to_id uuid REFERENCES api_tokens(id) ON DELETE RESTRICT,
    ADD COLUMN rotated_at timestamptz,
    ADD CONSTRAINT token_account_project_fk FOREIGN KEY (project_id,service_account_id) REFERENCES service_accounts(project_id,id),
    ADD CONSTRAINT token_account_requires_project CHECK (service_account_id IS NULL OR project_id IS NOT NULL),
    ADD CONSTRAINT token_rotation_pair CHECK ((rotated_to_id IS NULL) = (rotated_at IS NULL)),
    ADD CONSTRAINT token_rotation_not_self CHECK (rotated_to_id IS NULL OR rotated_to_id <> id),
    ADD CONSTRAINT token_rotation_successor_unique UNIQUE(rotated_to_id);
CREATE INDEX api_tokens_project_source_active_idx ON api_tokens(project_id,source_id,id) WHERE revoked_at IS NULL;
CREATE INDEX api_tokens_account_active_idx ON api_tokens(service_account_id,id) WHERE revoked_at IS NULL;
