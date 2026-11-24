# CajeerLogs v1.0.1 — GitHub Release Discovery

## Release changes

- Real GitHub Releases discovery through pinned HTTPS endpoint, bounded API pagination and cached results.
- SemVer 2.0 ordering with stable/preview channels and published-release filtering.
- Read-only update status and safe changelog viewer in the authenticated administration panel.
- CLI `update:check` now supports live discovery and explicit refresh; legacy trusted-file comparison is preserved.
- This release **does not install updates** or treat GitHub release metadata as signed artifacts.

## Security and acceptance

The existing GA workflow must verify the PHP 8.4/8.5 × PostgreSQL 17/18 matrix, browser and API compatibility, real Docker/SDK/ClickHouse integrations, performance SLOs, dependency integrity, reproducible builds, Sigstore/OIDC attestations and the exact release archive. `publish-ga` is allowed to publish only after all gates pass. This document does **not** assert that such a release has been published.

## Operators

See [GitHub Release Discovery](GitHub-Release-Discovery-1.0.1.md) for UI, CLI, network requirements and documented non-installation behavior; [Production-Stable-GA-1.0.0](Production-Stable-GA-1.0.0.md) for existing signed-release verification and recovery procedures.
