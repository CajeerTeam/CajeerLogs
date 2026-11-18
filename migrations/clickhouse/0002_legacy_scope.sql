-- Earlier 0.7.0 manual installations lack these fields.
-- Legacy rows get the nil UUID, and can never match scoped project queries.
ALTER TABLE cajeer_logs.log_events ADD COLUMN IF NOT EXISTS project_id UUID DEFAULT '00000000-0000-0000-0000-000000000000';
ALTER TABLE cajeer_logs.log_events ADD COLUMN IF NOT EXISTS source_id UUID DEFAULT '00000000-0000-0000-0000-000000000000';
