-- Preserve the original credential as the immutable delivery namespace of rotations.
ALTER TABLE api_tokens ADD COLUMN delivery_family_id uuid REFERENCES api_tokens(id) ON DELETE RESTRICT,
    ADD CONSTRAINT token_delivery_family_not_self CHECK (delivery_family_id IS NULL OR delivery_family_id <> id);
WITH RECURSIVE lineage(id, root) AS (
    SELECT t.id,t.id FROM api_tokens t WHERE NOT EXISTS (SELECT 1 FROM api_tokens p WHERE p.rotated_to_id=t.id)
    UNION ALL
    SELECT p.rotated_to_id,l.root FROM lineage l JOIN api_tokens p ON p.id=l.id WHERE p.rotated_to_id IS NOT NULL
)
UPDATE api_tokens t SET delivery_family_id=l.root FROM lineage l WHERE t.id=l.id AND t.id<>l.root;
-- Fail migration if a pre-existing cycle or inconsistent scope prevents a valid family.
SELECT 1 / CASE WHEN EXISTS (SELECT 1 FROM api_tokens p JOIN api_tokens t ON t.id=p.rotated_to_id
        WHERE t.delivery_family_id IS DISTINCT FROM COALESCE(p.delivery_family_id,p.id)
        OR t.project_id IS DISTINCT FROM p.project_id OR t.source_id IS DISTINCT FROM p.source_id
        OR t.service_account_id IS DISTINCT FROM p.service_account_id) THEN 0 ELSE 1 END;
CREATE INDEX api_tokens_delivery_family_idx ON api_tokens(delivery_family_id,id) WHERE delivery_family_id IS NOT NULL;
ALTER TABLE ingest_receipts ADD COLUMN delivery_digest text CHECK (delivery_digest ~ '^[a-f0-9]{64}$');
ALTER TABLE jobs ADD COLUMN delivery_digest text CHECK (delivery_digest ~ '^[a-f0-9]{64}$'),
    ADD COLUMN delivery_count integer CHECK (delivery_count BETWEEN 1 AND 100),
    ADD COLUMN delivery_retention_seconds integer CHECK (delivery_retention_seconds BETWEEN 86400 AND 2592000),
    ADD CONSTRAINT job_delivery_pair CHECK ((delivery_digest IS NULL) = (delivery_count IS NULL) AND (delivery_digest IS NULL) = (delivery_retention_seconds IS NULL)),
    ADD CONSTRAINT job_delivery_ingest CHECK (delivery_digest IS NULL OR queue='ingest-v1');
