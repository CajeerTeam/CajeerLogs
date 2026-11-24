# CajeerLogs 1.0.2 — Verified Update Downloads

## Deploying the verification requirements

Runtime requirements: PHP 8.4+, `ext-zip`, PostgreSQL 17+ (with working PDO connection), the official `gh` CLI supporting `gh attestation verify`, and outbound HTTPS to GitHub API and its asset/attestation services. The bundled production Dockerfile installs the ZIP extension and GitHub CLI. Set a minimally scoped, read-only GitHub token via `GH_TOKEN` **in the CLI process**; never place it in a public .env or web page. For a read-only Docker container, use `docker compose --env-file .env.production -f compose.production.yaml exec -e GH_TOKEN=YOUR_READ_ONLY_TOKEN app php bin/cajeer update:download` from the operator workstation; do not commit the token. The CLI uses private writable `storage/archives/verified-updates/.gh-cache` for Sigstore root metadata and isolates its GitHub CLI configuration. `gh auth status` and `gh attestation verify --help` can diagnose access. Any missing verifier or offline GitHub API results in an explicit failure, **never unsigned fallback**.

`php bin/cajeer update:check [--refresh]` discovers published releases. `php bin/cajeer update:download [--refresh]` selects only a newer candidate in the configured stable/preview channel and downloads the official `CajeerLogs-VERSION.zip` plus `CajeerLogs-VERSION.zip.sha256` to a private temporary folder. The GitHub API must confirm that both assets belong to a published tag with a lightweight commit reference. ZIP bytes must exactly match both SHA-256 sidecar and the GitHub asset digest (where present). The verifier then runs:

```
gh attestation verify <ZIP> --repo CajeerTeam/CajeerLogs \
  --source-ref refs/tags/vVERSION --source-digest <source commit SHA> \
  --signer-workflow CajeerTeam/CajeerLogs/.github/workflows/runtime.yml \
  --deny-self-hosted-runners --predicate-type https://slsa.dev/provenance/v1 --format json
```

Cryptographic identity and digest checks are performed by `gh`; JSON validation in PHP checks that the verification command actually returned verified attestations. The ZIP must also have a complete `RELEASE-MANIFEST.json`, no duplicate/path-traversal/symlink entries, matching digest and size for every entry, the expected CajeerLogs version/API v1, compatible PHP minimum and live PostgreSQL major version. On success, the downloaded archive and verification record become visible atomically under `storage/archives/verified-updates/vVERSION/` with private filesystem permissions.

`php bin/cajeer update:verify VERSION` repeats the live provenance, checksum, tag and compatibility checks for a previously downloaded archive. The authenticated `/admin/` System panel shows when a candidate ZIP is already verified and provides a download link. The private API endpoints require `system:read`. A tampered/missing file is not served as verified. Updates are **never installed** by these commands or the administrative panel; actual installation, backup, migration and rollback arrive in 1.0.3.

## Failure behavior

No download occurs if the candidate is not newer, the GitHub release is a draft, assets are missing, SHA-256 mismatches, GitHub's signed build attestation does not exist, source ref/commit/workflow differ, the ZIP manifest is incorrect, the runtime/database are incompatible, or the GitHub CLI cannot authenticate. Partial downloads are discarded. If an existing verified directory is unexpectedly corrupted, an operator must investigate and remove it deliberately: the server refuses to overwrite it automatically.
