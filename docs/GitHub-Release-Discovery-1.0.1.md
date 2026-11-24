# CajeerLogs 1.0.1 — GitHub Release Discovery

This functionality performs **real remote release discovery**, not package installation.

## Operator use

1. Sign in to `/admin/` as a user authorized for `system:read` and open **Система → Обновления · GitHub Releases**.
2. The panel shows installed version, newest published GitHub version, update availability, when it was checked, the release link, and the changelog displayed as inert text.
3. Click **Проверить сейчас** to bypass the shared five-minute cache. Refreshes are independently rate-limited (6/minute per identity).
4. Alternatively, run `php bin/cajeer update:check` or `php bin/cajeer update:check --refresh`. Output is JSON, with a nonzero exit status on connection failure. An explicitly supplied trusted metadata file is still supported: `php bin/cajeer update:check /path/to/release.json`.

Set `UPDATE_CHANNEL=stable` (default) to exclude drafts and pre-releases or `UPDATE_CHANNEL=preview` to include published SemVer pre-releases. Configuration is validated when the application boots. A source release without a published GitHub Release entry is **not** treated as available.

## Network and security contract

- Fixed, read-only destination: `https://api.github.com/repos/CajeerTeam/CajeerLogs/releases`, with versioned GitHub REST API headers. No arbitrary URLs, user-supplied hosts, token injection, redirect following, package downloads or archive extraction.
- Verified peer/hostname TLS, 5-second HTTP timeout per page, 512 KiB maximum JSON per page, a maximum of 3 pages × 30 releases and bounded 16 KiB changelog text per release. GitHub errors are not silently treated as "no updates".
- `GET /api/v1/system/updates` requires authentication and the existing `system:read` permission. `GET /api/v1/system/updates?refresh=1` performs a fresh check with a separate limiter. Responses use `Cache-Control: private, no-store`.
- The shared cache is held under private `storage/cache/` and avoids exceeding GitHub's unauthenticated request quota. Its contents are advisory discovery data only, **not** trusted installer metadata, a signature, or authorization to execute anything.
- Changelog and remote release title are inserted with DOM text nodes, not HTML. Release links are constructed by the server for the pinned GitHub repository, ignoring untrusted upstream `html_url`.

## Failures and limits

Connection errors, HTTP 403/429, TLS failures, malformed JSON and oversized responses are reported as unavailable. Admins should check outbound HTTPS connectivity to `api.github.com`, DNS, CA certificates, proxy/firewall policy, and GitHub API availability. No local log ingestion or workers depend on this lookup. Offline discovery tests inject a deterministic HTTP transport; production always uses the real HTTPS client.

This version does not verify artifact signatures, download updates, apply migrations, perform rollbacks, or schedule updates. Those steps belong to later versioned releases and must not be inferred from successful discovery.
