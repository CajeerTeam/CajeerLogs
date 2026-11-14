-- Outgoing webhook subscriptions are explicitly provisioned by the host administrator.
-- Do not activate legacy placeholder webhooks automatically.
CREATE TABLE integration_webhooks (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id uuid NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    url text NOT NULL CHECK (length(url) <= 2048),
    secret_env text NOT NULL CHECK (secret_env ~ '^CAJEER_WEBHOOK_[A-Z0-9_]{1,100}$'),
    enabled boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(project_id, url)
);
CREATE INDEX idx_integration_webhooks_project ON integration_webhooks(project_id) WHERE enabled;
CREATE TABLE webhook_deliveries (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    webhook_id uuid NOT NULL REFERENCES integration_webhooks(id) ON DELETE CASCADE,
    payload jsonb NOT NULL,
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','sending','delivered','failed','cancelled')),
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    retry_limit integer NOT NULL DEFAULT 8 CHECK (retry_limit >= 1),
    next_attempt_at timestamptz NOT NULL DEFAULT now(),
    lease_until timestamptz,
    lease_id uuid,
    created_at timestamptz NOT NULL DEFAULT now(),
    completed_at timestamptz
);
CREATE INDEX idx_webhook_deliveries_due ON webhook_deliveries(next_attempt_at, id) WHERE status IN ('pending','sending');
CREATE INDEX idx_webhook_deliveries_hook ON webhook_deliveries(webhook_id, created_at DESC);
CREATE TABLE webhook_attempts (
    delivery_id uuid NOT NULL REFERENCES webhook_deliveries(id) ON DELETE CASCADE,
    attempt integer NOT NULL,
    started_at timestamptz NOT NULL DEFAULT now(),
    finished_at timestamptz,
    outcome text NOT NULL DEFAULT 'sending' CHECK (outcome IN ('sending','delivered','retry','failed','unknown','cancelled')),
    http_status integer,
    PRIMARY KEY(delivery_id, attempt)
);

CREATE INDEX idx_webhook_deliveries_completed ON webhook_deliveries(completed_at) WHERE status IN ('delivered','failed','cancelled');
