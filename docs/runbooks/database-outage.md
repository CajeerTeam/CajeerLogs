# PostgreSQL connectivity and integrity

## Trigger

`database_readiness`, `audit_integrity` or `queue_accounting` fails; connection pool is exhausted; database migrations or audit-chain checks fail.

## Diagnosis

Start with bounded application probes, then inspect PostgreSQL via approved DBA tooling and infrastructure telemetry (disk, connections, TLS, replication, locks). An audit-chain failure is a **data integrity / security** event; do not suppress it by rewriting history.

```sh
php bin/cajeer health:ready
php bin/cajeer db:doctor
php bin/cajeer migrate:status
php bin/cajeer migrate:verify
php bin/cajeer audit:verify
php bin/cajeer queue:verify
```

## Intervention

Coordinate the incident before restarting workers or PostgreSQL. Pause external ingestion upstream if required; account for durable queue leases. For schema drift, **do not** apply ad-hoc SQL or rerun migrations automatically; compare the exact release and migration hashes. For destroyed database storage, use [backup/DR](backup-disaster.md) on a **separate, empty** target with the restore fence. The source/live database is never a supported restore target.

## Verification

Confirm authenticated database connections, all migrations, queue accounting, immutable audit chain and application readiness. Check representative data and replay/idempotency state before resuming customer traffic.

```sh
php bin/cajeer migrate:verify
php bin/cajeer db:verify
php bin/cajeer audit:verify
php bin/cajeer health:ready
```

## Escalation

Immediately notify DBA/security owner when audit verification or logical integrity fails, or if data-loss recovery is required. A failed or partially restored target must remain quarantined and be reprovisioned, not reused.
