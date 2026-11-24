-- The previous release did not record login provenance. Revoke ambiguous
-- sessions for linked accounts rather than silently treating them as passwords.
DELETE FROM auth_sessions WHERE user_id IN (SELECT user_id FROM skif4er_identities);
DELETE FROM skif4er_login_flows;
ALTER TABLE auth_sessions ADD COLUMN auth_method varchar(16) NOT NULL DEFAULT 'password' CHECK (auth_method IN ('password','skif4er'));
CREATE INDEX auth_sessions_external_idx ON auth_sessions(user_id) WHERE auth_method='skif4er';
ALTER TABLE skif4er_login_flows ADD COLUMN link_session_id uuid REFERENCES auth_sessions(id) ON DELETE CASCADE;
ALTER TABLE skif4er_login_flows ADD COLUMN verified_email varchar(254);
ALTER TABLE skif4er_login_flows ADD CONSTRAINT skif4er_link_email CHECK (verified_email IS NULL OR (link_session_id IS NOT NULL AND subject IS NOT NULL));
CREATE INDEX skif4er_link_session_idx ON skif4er_login_flows(link_session_id) WHERE link_session_id IS NOT NULL;
