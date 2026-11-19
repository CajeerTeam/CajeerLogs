# Security Policy — CajeerLogs

## Supported releases

| Release | Maintenance |
|---|---|
| Latest certified 1.0.x GA patch | Security fixes and compatibility fixes under [Release policy](docs/Release-policy.md) |
| Previous 1.0.x patches | Superseded; update to latest patch |
| 0.x preview/release candidates | Not eligible for ongoing maintenance guarantees |

A source ZIP named 1.0.0 is **not** proof of GA certification. Verify the GitHub release and signed provenance of the ZIP and final certification before deployment.

## Reporting vulnerabilities

Use the repository's **private GitHub Security Advisory reporting** mechanism; do not publish exploit details in public issues. Provide affected version(s), reproduction steps, severity/impact and safe diagnostics **without secrets or personal data**. Maintainers coordinate remediation and advisory publication. No fixed response time is guaranteed.

## Security baseline

Production requires HTTPS, restrictive PostgreSQL roles, RBAC and TOTP for administrators, authenticated private metrics, protected backup and secret storage, audit records, safe queue workers and verified package dependencies. Run `php bin/cajeer security:audit --tls` before going live and after configuration changes. See [Production Security](docs/Production-Security.md) and [Release Policy](docs/Release-policy.md).
