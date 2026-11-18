-- Project-scoped webhook event filters and optimistic concurrency for user-managed subscriptions.
-- Existing 0.6.6 subscriptions keep receiving logs.ingested. Secrets stay in the environment.
ALTER TABLE integration_webhooks
    ADD COLUMN events text[] NOT NULL DEFAULT ARRAY['logs.ingested']::text[],
    ADD COLUMN revision integer NOT NULL DEFAULT 1 CHECK (revision > 0),
    ADD COLUMN updated_at timestamptz NOT NULL DEFAULT now();
ALTER TABLE integration_webhooks ADD CONSTRAINT integration_webhooks_events_valid CHECK (
    cardinality(events) BETWEEN 1 AND 6
    AND events <@ ARRAY['logs.ingested','source.created','source.disabled','source.enabled','source.archived','source.restored']::text[]
);
CREATE INDEX idx_webhook_deliveries_hook_status ON webhook_deliveries(webhook_id,status,created_at DESC);
