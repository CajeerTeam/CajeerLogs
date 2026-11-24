# CajeerLogs v1.0.2 — Verified Update Downloads

## Release changes

- `php bin/cajeer update:download [--refresh]` fetches a **published** `CajeerTeam/CajeerLogs` release from GitHub using GitHub CLI, requires a matching SHA-256 sidecar, and independently checks the GitHub asset digest when supplied.
- A **GitHub OIDC/Sigstore attestation** must authenticate the exact ZIP using the pinned repository, source tag, commit SHA, signer workflow, SLSA predicate and GitHub-hosted runner. Invalid or missing attestations reject the download.
- Verify every manifest file checksum, ZIP entry safety and the future release's PHP, PostgreSQL and API compatibility before atomically committing the archive to private storage.
- `php bin/cajeer update:verify VERSION` rechecks published tag, signed provenance, checksum, manifest and runtime compatibility.
- The system panel offers an authenticated download of a *previously verified* ZIP, but does not perform package installation, migrations or restarts.

## Security and acceptance

The full unchanged production GA pipeline remains mandatory, including PHP 8.4/8.5 × PostgreSQL 17/18, Docker, SDK, browser, security, load and supply chain gates. This release does not claim that the external GitHub Actions GA certification has passed. See `docs/Verified-Update-Downloads-1.0.2.md` for usage and limitations.
