# CajeerLogs 0.8.2 — full E2E and failure recovery

This release adds a **live stopped-service** test on top of the pre-existing
HTTP pipeline, S3 Signature V4 faults, rollup reconciliation and long load tests.
The test does **not** simulate a stopped database by substituting a closed port.

## What is exercised

On the verified release ZIP, the CI runner creates a unique disposable PostgreSQL
schema and commits four events across two projects. It holds one replication
lease to represent a worker process that exited before acknowledging work. It
then stops the **actual ClickHouse service container**, confirms that ingestion
and PostgreSQL remain available, and attempts delivery. The retryable outbox
is preserved. After the service starts, a new PHP process expires the abandoned
lease and reconciles the source data with the versioned ClickHouse replica.
A replay checks that the event count cannot double.

The runner then stops the **actual PostgreSQL service container**, requires
connection attempts to fail, restarts it and verifies persisted events. It
changes and deletes events and confirms ClickHouse tombstones, current rollups
and project isolation. Both containers are started again even if assertions
fail; the disposable schema is removed after the test. The test is not run
on arbitrary containers or against production credentials.

### Guardrails

- `GITHUB_ACTIONS=true`, `GITHUB_JOB=clickhouse-integration`, `APP_ENV=test`,
  `CAJEER_TEST_DATABASE=1`, `CAJEER_TEST_CLICKHOUSE=1`, and
  `CAJEER_TEST_SERVICE_STOP=1` are required.
- Connections are restricted to loopback (127.0.0.1 / localhost); arbitrary hosts
  cannot be used for destructive failure tests.
- Only full, distinct 64-hex-character GitHub service container IDs are
  accepted. `docker inspect` must identify running exact images
  `postgres:17` and `clickhouse/clickhouse-server:25.8` before stopping them.
- PostgreSQL system databases are rejected. Outbound database credentials are
  never included in the certification report.
- Every phase is bounded by a timeout. Stopped containers are restarted in a
  `finally` block; readiness is checked before subsequent operations.
- CI reports the test independently as `service-stop-and-recovery` and requires
  concrete PASS assertions and structured evidence tied to the release SHA-256.
- PostgreSQL and ClickHouse cannot be assumed healthy merely because a Docker
  start command returned successfully. A live connection is verified.

## Operator commands

In the configured disposable CI environment, the actual release test is:

```sh
python3 scripts/analytics_storage_certify.py release-out/CajeerLogs-0.8.2.zip \
  --output /tmp/analytics-storage-certification.json
```

The live service-stop stage may also be run alone on the verified extracted
artifact with matching CI service IDs:

```sh
python3 scripts/failure_recovery.py --root /tmp/cajeerlogs-release
```

Do not use these commands against a production Docker host. They deliberately
stop databases. For disaster recovery on production servers, use the documented
backup/restore procedure instead.

The local unit tests (`python3 tests/failure_recovery.py`) test the refusal and
cleanup guards without stopping any containers. **They are not live E2E
certification.** The real test only passes in CI when both container stop/start
cycles, all SQL assertions, replay and reconciliation succeed. The existing
S3 fault server is a signed HTTP fixture, not an external S3 provider.
