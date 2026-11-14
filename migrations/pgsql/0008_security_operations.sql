CREATE TABLE user_totp (
    user_id uuid PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    secret_ciphertext text NOT NULL,
    pending_session char(64),
    pending_until timestamptz,
    last_step bigint NOT NULL DEFAULT -1,
    CHECK ((pending_session IS NULL) = (pending_until IS NULL))
);
CREATE TABLE user_recovery_codes (
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash char(64) NOT NULL,
    PRIMARY KEY (user_id,code_hash)
);
-- Legacy installations never supported enrollment. Fail closed if a flag was set manually.
DELETE FROM auth_sessions WHERE user_id IN (SELECT id FROM users WHERE two_factor_enabled);
