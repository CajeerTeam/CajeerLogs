# CajeerLogs 0.9.5 — Admin Panel Stable

## Production workflow

Use the supported CLI installer/upgrade and a pre-upgrade PostgreSQL backup. Upgrade 0.9.4 → 0.9.5 by replacing the patched files with PHP workers and HTTP traffic stopped. Do not replace `.env` or the `storage/` directory. No additional PostgreSQL migration is introduced by the admin update.

1. Run `php bin/cajeer version`, `php bin/cajeer migrate:verify` and `php bin/cajeer db:verify`.
2. Run `npm --prefix admin run build` to validate shipped versioned JS/CSS imports; this does not compile an alternative runtime bundle.
3. Start HTTPS traffic and workers, open `/admin/`, sign in and verify projects, log search, source onboarding, access roles, system health and session settings.
4. Validate login, password changes, TOTP/recovery, role revocation, project switching, export and UI error recovery in browser E2E with an actual disposable PostgreSQL database.

### Uncertain mutation results

The browser never automatically retries a write. A transport failure, 5xx response or malformed success response may occur **after** a server-side commit. In forms and confirmation dialogs, the affected submit action is disabled and operators must refresh the page and verify the result (project, user, token, etc.) before attempting another action. A known 409 revision conflict offers an explicit refresh without asserting that a write was committed. GET failures remain retryable without locking search forms. This prevents accidental duplicate creation after a lost HTTP response but is not a replacement for server-side idempotency and transactional consistency.

### Session and privacy

Session validity is verified on focus, tab changes and periodically; permission/role changes revoke stale workspaces. Account names updated in another browser tab refresh the navigation header, while reauthentication and policy changes refresh settings on the next validation. The HTML entrypoint is sent with `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer` and `X-Content-Type-Options: nosniff` in addition to strict CSP. Do not store tokens, private log filters, passwords or TOTP secrets in URLs or browser storage.

### Certification

`python3 scripts/certify.py admin <verified-release-zip> --output <evidence.json>` runs shipped admin tests, Chromium UI fixture tests and browser → PHP → PostgreSQL E2E on Chromium, Firefox and WebKit. Only a successful run against the exact 0.9.5 ZIP counts as certification. `tests/http_runtime.py`, `node --test admin/client.test.mjs` and `npm --prefix admin run build` provide local validation, not equivalent browser/DB certification. The CI release gate must also pass the dependency integrity checks and all database stages, including the 0.9.4 → 0.9.5 upgrade.
