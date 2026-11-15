# Authentication & Sessions — 0.3.2

Production Admin SPA and PostgreSQL identity runtime. API v1 remains compatible with existing one-request login (email, password, optional otp). UI first verifies email/password, then requests TOTP or a recovery code when required. Missing second factor after a correct password returns `401 second_factor_required`, with no cookie; the second request re-verifies all credentials atomically. Invalid passwords and blocked accounts return generic `401 unauthorized`. Credentials are never placed in URLs, localStorage, sessionStorage or broadcast messages. The UI retains the password only in memory for up to five minutes or until confirmation/cancel; server rate limits apply to both requests. HTTPS is mandatory in production.

## Upgrade

1. Stop web and workers, take a verified backup, overlay the changed-files ZIP on the complete 0.3.1 installation.
2. Run `php bin/cajeer migrate`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify`, `php bin/cajeer doctor` and `npm --prefix admin run build` (the last is a development/build verification, not a production runtime dependency).
3. Restart web/workers. Confirm `/ready` and `php bin/cajeer version` report 0.3.2.

Migration 0015 adds a random public UUID, nullable user-agent and remote address to existing sessions, and display name/last-login timestamp to users. Existing session digests, CSRF values and expiry remain unchanged. Existing sessions have unknown device/address and users have unknown last login until the next successful login. Migrations 0001–0014 are untouched. Prior backups remain restorable through the normal fenced migration path. Restore invalidates session/token credentials as before.

## Account settings

`#settings` displays the account ID, email, effective global role, creation and last successful login, and a persisted editable display name. Email, role and blocking remain administrative operations; password changes require current password and revoke all sessions. TOTP enrollment uses a local canvas QR encoder, manual key fallback, a ten-minute enrollment and a six-digit code. Successful enrollment rotates the session and revokes others. Recovery codes are shown once, stored only as hashes, and can be replaced with current password plus a fresh TOTP or unused recovery code. Regeneration invalidates the entire old set atomically. Disabling TOTP requires current password and second factor, is prohibited for administrators under mandatory enrollment policy, and revokes all sessions.

## Sessions

The session table shows browser user-agent, peer IP, creation, last activity, absolute expiry and current session. User-agent is untrusted descriptive metadata, not a device fingerprint; the address is the server's peer (possibly a proxy). The session TTL is eight hours by default (`SECURITY_SESSION_TTL_SECONDS`, 300–604800 seconds; changes apply to newly issued sessions) and does not slide; last activity is updated at most every five minutes. No IP binding. No automatic inactivity timeout in this release. Active sessions are paginated by public UUID. Cookies are HttpOnly/SameSite=Strict and Secure in HTTPS installations. Only digests are stored; neither cookie secrets nor CSRF tokens are exposed by session listing. The current session is identified by its authenticated UUID, not an IP or device heuristic.

- `GET /api/v1/auth/sessions?limit=25&cursor=<uuid>`
- `DELETE /api/v1/auth/sessions/{session}` — own session, 404 if absent/foreign/expired; revoking current clears the cookie.
- `POST /api/v1/auth/logout-others` — keep current.
- `POST /api/v1/auth/logout-all` — revoke all and clear cookie.
- `GET /api/v1/users/{user}/sessions`, `DELETE /api/v1/users/{user}/sessions/{session}`, `POST /api/v1/users/{user}/sessions/revoke-all` — administrator only.
- `PATCH /api/v1/auth/profile` — `{"display_name":"Name"}` (empty clears; max 120 Unicode characters, no control characters).
- `POST /api/v1/auth/2fa/recovery-codes` — `{"password":"…","otp":"…"}`; ten new one-time codes.

All writes require cookie, CSRF and same-origin validation; authorization is rechecked under the identity transaction lock. Bearer credentials cannot access user session/profile operations. Session revocations and profile/recovery operations emit audit events without secrets. The frontend broadcasts only a change notification across tabs and revalidates on focus, visibility and every minute. Requests started before revocation may finish; new authenticated requests fail. A network error never implies a successful mutation or logout.

## Tests

`npm --prefix admin run build`, `php tests/identity.php`, `php bin/cajeer openapi:lint`, `php tests/restore_fence.php`. Real PostgreSQL identity/security tests: `CAJEER_TEST_DATABASE=1 php tests/identity_database.php` and `php tests/security_database.php` (the latter also needs a disposable restore database). Browser regression: `python3 tests/admin_browser.py`; `CAJEER_BROWSER_OFFLINE=1` uses real JS/CSS and fixture transport rather than genuine cookie/DB behavior. `tests/admin_browser_database.py` exercises real browser/HTTP/PostgreSQL. Release certification requires the full existing PHP/PostgreSQL matrix and Docker smoke; Public Preview until verified.
