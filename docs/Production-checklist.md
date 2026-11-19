# CajeerLogs 1.0.0 — Production operations checklist

Verify the SHA-256 and authenticated Sigstore/GitHub OIDC attestations for both the release ZIP and certification report before starting any production installation. Follow [GA publication and installation](Production-Stable-GA-1.0.0.md) and [support policy](Release-policy.md).

# CajeerLogs production checklist (1.0.0 release candidate)

This is an operator checklist; *checking an item in prose is not certification*. Only the signed CI-certified GitHub Release can be deployed as GA. Follow the [operational release procedure](Release-Candidate-Operations-0.9.9.md) and [incident runbooks](runbooks/README.md).

## Preparation

- [ ] Deploy **one immutable full candidate ZIP** with matching PHP runtime, Admin Panel, SDKs and versioned containers; verify SHA-256, SBOM, provenance and OCI digests.
- [ ] Complete strict Composer/npm/Gradle dependency verification, audit and independent reproducible builds.
- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, canonical HTTPS `APP_URL`; verify TLS chain and hostname with `security:audit --tls`.
- [ ] Configure protected PostgreSQL roles, network/TLS, secrets outside webroot, private environment files, and `PROMETHEUS_TOKEN`.
- [ ] Enable admin MFA and step-up reauthentication; validate RBAC, sessions, source-scoped ingest tokens and audit chain.

## Install / upgrade

- [ ] Perform `install:check`; run clean installation or safeguarded `upgrade BACKUP_ID --confirm-stopped` with writes/workers stopped.
- [ ] Run `migrate:verify`, `migrate:certify` and database integrity checks on disposable test environments.
- [ ] Verify that restart, repeated install/upgrade and prior-version rollback preserve data and account security.
- [ ] Test real Admin Panel actions in Chromium, Firefox and WebKit against the current API.

## Operations / DR

- [ ] Confirm `/ready`, `health:ready`, worker queue, scheduler, retention, offsite backup, security alerts and protected `/metrics`.
- [ ] Run `operations:triage`, `security:audit`, `operations:incident`; store the last incident JSON with mode `0600` under a `0700` directory.
- [ ] Run `backup:verify`, `backup:rehearse` and offsite-only DR; record **measured** application RPO/RTO and reconcile restored event/queue/audit data.
- [ ] Verify queue recovery after SIGKILL, durable async jobs, retries, idempotency, DLQ and backpressure.
- [ ] Observe real Prometheus/Blackbox/Alertmanager signals during controlled worker and PostgreSQL outages, then validate alert resolution.

## Release gate

- [ ] All four PHP 8.4/8.5 × PostgreSQL 17/18 reports and ClickHouse 25.8 tests succeeded on the same SHA-256.
- [ ] Security, API v1, SDK, browser, integration stability, upgrade/rollback and strict supply chain passed.
- [ ] Live HTTPS load 60/120/900/60 seconds (sustained/stress/soak/fault) passed with exact persisted **payload** checks and load SLOs.
- [ ] Release owner approved and archived the actual CI evidence and operational runbooks. No automatic cutover or certification from source code alone.
