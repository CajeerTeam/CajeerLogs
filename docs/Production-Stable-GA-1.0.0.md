# CajeerLogs 1.0.0 — Production Stable GA

This is the **release procedure and release-notes document**, not a claim that a locally built ZIP has passed external certification. The project is GA only after the **tag-driven release CI** completes and the GitHub Release assets have been published with verified attestations.

## Supported components

- PHP 8.4 and 8.5 with PostgreSQL 17 and 18. PostgreSQL is authoritative; ClickHouse 25.8 is an optional analytics replica.
- Stable `/api/v1` and PHP, Python, TypeScript and Kotlin ingestion SDKs; Admin Panel assets must match runtime 1.0.0.
- Signed, SHA-256-bound artifacts and certification reports must be produced from the **same** exact release ZIP. See [support and compatibility policy](Release-policy.md).

## Clean installation

1. Obtain the release ZIP and `.sha256`, the SBOM, the supply-chain report and the certification report **from the published GitHub Release**, not a candidate build.
2. Run `sha256sum -c CajeerLogs-1.0.0.zip.sha256` and `gh attestation verify CajeerLogs-1.0.0.zip --repo OWNER/REPO --source-ref refs/tags/v1.0.0 --signer-workflow OWNER/REPO/.github/workflows/runtime.yml` using the actual repository.
3. Check the signed final `database-certification.json` with the same `gh attestation verify` command and repository/tag/workflow restrictions. Verify the repository and source commit identity before deployment. If any check fails, do not deploy.
4. Provision a private PostgreSQL database and restricted runtime credentials, configure HTTPS/TLS, required secrets and an authenticated Prometheus endpoint per [production checklist](Production-checklist.md). Avoid committing `.env` or tokens.
5. Run `php bin/cajeer install:check`; perform the documented CLI installation, then run `php bin/cajeer migrate:status`, `php bin/cajeer security:audit --tls`, `php bin/cajeer operations:check`, `php bin/cajeer operations:triage` and read `/ready`. Follow [installer guide](Production-Installer-0.9.4.md) for administrator initialization and preflight flags.
6. Start the HTTPS gateway, workers and scheduled tasks, send a signed test event, then confirm it appears in Log Explorer. Check worker metrics, alerts and a successful backup with a tested isolated restore.

## Upgrade from 0.9.9

1. Run the previous version's security/operations/backup checks and take a verified **offsite** backup; record the previous ZIP and its SHA-256. Stop producers and worker processes to create a consistent maintenance boundary.
2. Verify the 1.0.0 archive and signed evidence, ensure supported PHP/PostgreSQL versions and storage capacity. Follow [production installer upgrade guide](Production-Installer-0.9.4.md): `php bin/cajeer upgrade BACKUP_ID --confirm-stopped`.
3. Validate schema migration status, application and SDK versions, `/ready`, login/TOTP, ingestion idempotency and queue drain, alerts, cold storage, then resume traffic. New migrations are not introduced in 1.0.0; the runtime version still changes.
4. For rollback, stop services again and **restore the old binary and the original database backup together to an isolated verified target**. Never point the previous binary at a DB containing unverified newer writes. Test restore and cutover per [DR procedures](Data-Lifecycle-Disaster-Recovery-0.9.7.md).

## Operations

- Check security posture daily, on deployment and after secret rotation; monitor authenticated `/metrics`, alerts, PostgreSQL/ClickHouse storage and queue backlog.
- Confirm backup/restore RPO/RTO, retention, cold S3 scrub and incident recovery in the actual deployment environment; the CI certification is a baseline, not a guarantee of local hardware SLOs.
- Use [on-call runbooks](runbooks/README.md), [release operations](Release-Candidate-Operations-0.9.9.md), [load certification](Load-Testing-Performance-Certification.md), [backup and DR](Data-Lifecycle-Disaster-Recovery-0.9.7.md).

## Cryptographic release verification

GitHub's Sigstore/OIDC attestation establishes the build's origin and cryptographic integrity. A `.sha256` file or unsigned local in-toto JSON alone is insufficient. The release publishing job checks signed provenance of both the candidate ZIP and the **successful final certification JSON**, binds the proofs to the same artifact hash and tag, and publishes exactly once. On the release page, download all seven attached assets and verify the two attestations before installation. If the GitHub release is missing, **the GA has not been published**.
