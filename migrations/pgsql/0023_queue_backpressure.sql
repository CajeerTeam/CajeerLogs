-- Durable latch and rejection counters; no queue payload or lease rewrite.
-- Backfill starts unpaused. First HTTP admission reconciles the latch against
-- the current configured watermarks under the queue_accounting row lock.
ALTER TABLE queue_accounting
    ADD COLUMN admission_paused boolean NOT NULL DEFAULT false,
    ADD COLUMN rejected_jobs bigint NOT NULL DEFAULT 0 CHECK (rejected_jobs>=0),
    ADD COLUMN rejected_events bigint NOT NULL DEFAULT 0 CHECK (rejected_events>=0),
    ADD COLUMN rejected_bytes bigint NOT NULL DEFAULT 0 CHECK (rejected_bytes>=0),
    ADD COLUMN rejected_paused bigint NOT NULL DEFAULT 0 CHECK (rejected_paused>=0);
