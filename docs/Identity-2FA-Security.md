# Identity & 2FA Security — 0.4.3

Upgrade from 0.4.2: stop application/workers, back up database and private environment, overlay the patch, run `php bin/cajeer migrate`, `migrate:verify`, `db:verify`, `doctor`. Migration 0018 adds `auth_sessions.reauthenticated_at` and an idle cleanup index. Existing sessions keep their original expiry; they do not get a fabricated recent-authentication timestamp.

## Policy configuration

| Environment variable | Default | Meaning |
|---|---|---|
| `SECURITY_2FA_REQUIRED_FOR_ADMINS` | existing setting | Existing mandatory Administrator TOTP |
| `SECURITY_2FA_REQUIRED_ROLES` | empty | Comma-separated administrator,operator,viewer |
| `SECURITY_2FA_REQUIRED_PERMISSIONS` | empty | Comma-separated supported RBAC grants, e.g. tokens:create,roles:manage |
| `SECURITY_SESSION_IDLE_SECONDS` | 0 | 0 disables; 1–604800 seconds since last interactive API activity |
| `SECURITY_REAUTH_SECONDS` | 0 | 0 disables; 1–3600 seconds since successful login/enrollment/confirmation |
| `SECURITY_TOTP_ACTIVE_KEY` | empty | Independent random 32-byte hex TOTP-only encryption key |
| `SECURITY_TOTP_PREVIOUS_KEY` | empty | Previous independent key during rotation |

Recommended production values: idle 1800, reauth 300. The production environment example enables these; older deployments retain defaults unless explicitly configured. Configure all PHP instances and CLI consistently. Role/permission conditions are OR, and permissions are the global effective ceiling, deliberately conservative even if an individual project restricts the grant. Required unenrolled users may only check identity, set up/enable TOTP and log out; neither log endpoints nor administration bypass this. An enrolled user cannot disable TOTP while required. Machine tokens are unchanged. Configuration policy is operator-controlled, not a mutable web setting.

Idle expiry is checked server-side on every session request. The session list shows the earlier of absolute and idle expiry. `/auth/me`, background project checks and automatic dashboard/log polling do not refresh activity. A client may mark a GET as background using `X-Cajeer-Background: 1`; it cannot bypass expiration or authorization. Activity means API requests, not mouse movement. Scheduled/CLI auth cleanup deletes expired/idle sessions. Browser revalidation detects expiry within its next poll or request. Absolute expiry is never extended by reauthentication.

## Recent authentication

When enabled, a successful login gives a fresh window. Older sessions must use Settings → Confirm identity or `POST /api/v1/auth/reauthenticate` with cookie, CSRF header and JSON `{ "password": "...", "otp": "..." }`. OTP is required when enrolled; either a fresh TOTP or unused recovery code is accepted. Only this session is refreshed, with rate limiting and audit. Stale critical writes return 403 `reauthentication_required` before mutation. Confirm identity and explicitly retry the action; the client never auto-replays a mutation. Includes role/user/member changes, credential issuance/update/revoke/rotation, HMAC, service accounts and project/security policy changes. Own password changes require a fresh factor when enrolled. Existing password+factor protected actions retain direct confirmation. Recovery regeneration invalidates the previous codes and other sessions.

## Independent TOTP key rotation

**Never replace `SECURITY_TOTP_KEY` or `SECURITY_HMAC_SECRET` just to rotate TOTP.** The legacy key also participates in signing, cursors and delivery. Keep it unchanged. The new active key is isolated by HKDF domain and ciphertext key ID. Legacy ciphertext remains readable, and v2 ciphertext authenticates user identity using secretbox.

1. Back up database and config; retain every key needed by retained backup copies in protected offline escrow. Generate a new random 32-byte hex key using `openssl rand -hex 32` in a private operator environment. Configure `SECURITY_TOTP_ACTIVE_KEY` (never pass key as CLI argument). For subsequent rotations move prior active to `SECURITY_TOTP_PREVIOUS_KEY` and install the new active key. Roll out identical configuration to all processes before rewriting records; stop old-version processes. Do not start another rotation while more than one historical v2 key is needed.
2. Execute `php bin/cajeer auth:totp-key-verify` to verify all stored records, including pending enrollment. No keys or plaintext appear in output.
3. Run `php bin/cajeer auth:totp-key-rotate --confirm 100` until `remaining` is zero. Each invocation updates at most 100 (1–500 configurable) rows in an identity-locked transaction and verifies the newly sealed secret. It preserves pending enrollment, last accepted TOTP step and recovery codes. An interrupted batch rolls back; rerun safely.
4. Run `auth:totp-key-verify` again and check `remaining=0`. Test an enrolled user's login and readiness. Remove `SECURITY_TOTP_PREVIOUS_KEY` only after all processes use the new key and all rows are verified; keep the old key in backup escrow. Verify again. For restore of old backups, make the needed old key available. Restore verifies all active TOTP ciphertext before publishing its target and drops pending enrollment tied to revoked sessions. Missing keys leave the target quarantined. Rerun rotation before promotion. Do not discard the legacy shared key.

Readiness checks representative stored key versions, while the CLI verifies every row. The operator should quiesce enrollment during final verification and key retirement to avoid configuration drift. The application does not automatically modify environment secrets or retire old keys. Key rotation is for encryption-at-rest; it does not change users' TOTP seeds or invalidate their authenticator apps. For compromised seeds use existing `auth:2fa-reset EMAIL --confirm` under trusted operator control, then re-enroll.

## Tests and release

`php tests/identity.php`, `npm --prefix admin run build`, `php bin/cajeer openapi:lint`. With disposable PostgreSQL and `CAJEER_TEST_DATABASE=1 APP_ENV=test`, run `php tests/identity_security_database.php`. The existing certification matrix also runs this test, prior security tests and upgrade from actual 0.4.2. Keep public-preview until all release evidence is successful.
