# High-Throughput Batch Ingestion — 0.5.5

## What changed in the live ingestion pipeline

CajeerLogs already supports a single atomic PostgreSQL `jsonb_to_recordset` INSERT for HTTP batches (up to 100 events), with validation, redaction, authorization, project pagination fencing, and per-job durable receipts. Version 0.5.5 also groups *compatible queued jobs* into a single SQL INSERT, without changing the HTTP API, event limits, receipt format or database schema.

The new worker path is active when `QUEUE_WORKER_BATCH_JOBS` is between 2 and 16. Raw CLI/environment defaults to **1** (no behavioral change); `compose.production.yaml` defaults to **4**. A normal `queue:pool` worker uses this configuration. `queue:work N` still processes no more than N claims, even when N is smaller than the microbatch configuration; continuous workers also honor the remaining recycling budget.

Each group begins with the ordinary durable `claim()` operation, which prioritizes expired-lease recovery. A second PostgreSQL transaction obtains up to N−1 *ready pending* jobs with the same `project_id` and `token_id` using `FOR UPDATE SKIP LOCKED`. Each job receives its own lease ID and `job_attempts` row before the transaction commits. An expired lease that must be deferred or a job at its attempts limit retains the preexisting single-claim behavior.

During processing the worker locks all claimed jobs in ascending numeric ID order and checks each live lease. Each durable JSONB payload is size-bounded *before* transferring it to PHP, decoded with its historical validation version, validated, and checked against the original project/token/source map. Grouping requires one project, one token, one validation version, no conflicting source mappings, no more than 100 events, and the same 4 MiB SQL byte limit. All events pass the full per-event redaction policy and DB authorization checks. PostgreSQL scope validation for the INSERT materializes the token once and validates each distinct `(source_id, slug)` before joining the individual rows.

One `LogRepository::storeBatch()` inserts the combined event list. IDs are generated in input order; the results are split exactly at the original job boundaries. Each job still gets its **own** webhook outbox publication, durable `event_ids` result, terminal state, `job_attempts` completion and queue accounting update. All of these changes are in **one PostgreSQL transaction**. PostgreSQL returning rows may be unordered; the repository explicitly verifies the complete ID set and uses the generated order for each receipt.

If the group contains malformed data, crosses a batch byte/event boundary, has a stale lease, or suffers an ordinary application/SQL error, the **whole group transaction is rolled back**. Each still-owned job is processed using the original single-job path, isolating the faulty job and preserving its ordinary retry/dead-letter behavior. On connection loss, infrastructure pause, unusable session or **uncertain COMMIT**, the process does not attempt to replay the group: durable state and existing fenced lease recovery are authoritative.

## Configuration

```dotenv
# 1 keeps the prior single-job execution behavior; 2–16 enables grouping
QUEUE_WORKER_BATCH_JOBS=4
QUEUE_WORKER_PROCESSES=2
QUEUE_WORKER_MAX_JOBS=1000
QUEUE_LEASE_SECONDS=120
QUEUE_PROCESS_TIMEOUT_SECONDS=90
```

A microbatch does **not** enlarge HTTP request limits, bypass the authorization/rate-limit policy, grant more worker connections, or alter `QUEUE_MAX_JOBS`. Large full-size jobs may provide no benefit: if a group exceeds the 100-event/4 MiB limit it is processed individually. Configure a smaller batch size if this is the usual workload. More processes do not imply linear throughput, especially when PostgreSQL WAL, project locks or queue accounting dominate.

## Repeatable real PostgreSQL comparison

On a disposable PostgreSQL 17/18 test database with `pdo_pgsql`, configure `CAJEER_TEST_DATABASE=1`, `APP_ENV=test` and the usual isolated `DB_*` credentials. Run:

```bash
php tests/worker_microbatch.php
php tests/worker_microbatch_database.php
```

The second command executes **real HTTP async enqueue**, independently claimed jobs, a real SQL insert, webhook outbox transactions, malformed-payload rollback/fallback, retry after a deliberately failed outbox, the 100-event group boundary, and `Worker::run` in both modes. It prints observed individual and grouped times, and **asserts** that four one-event jobs use four SQL repository INSERTs in mode 1 and only one in mode 4. It does not assert a timing speedup because hardware and background DB load vary. All temporary PostgreSQL objects are created under a private test schema and removed afterward.

The existing end-to-end `scripts/benchmark.py` can measure sustained HTTP admission and end-to-end delivery with real worker pools:

```bash
# Different isolated test runs, identical hardware and workload.
QUEUE_WORKER_BATCH_JOBS=1 php bin/cajeer queue:pool 2
QUEUE_WORKER_BATCH_JOBS=4 php bin/cajeer queue:pool 2
python3 scripts/benchmark.py --help
```

Run each worker configuration **separately**, not simultaneously against the same queue. Keep project data, token scopes, PostgreSQL indexes, `fsync`, redaction rules, and test load equivalent. Use independent markers/credentials and check `queue:verify` after draining. The benchmark itself validates the exact persisted event-ID set and cannot establish a universal throughput target without a reproducible environment.

## Deployment / rollback

Stop queue workers, install the 0.5.5 changed-files overlay, run `php bin/cajeer migrate:verify`, `php bin/cajeer queue:verify`, `php bin/cajeer doctor`, rebuild the frontend, then start the new workers. **No new SQL migrations are required**; migrations 0001–0022 remain byte-identical.

A low-risk runtime rollback is `QUEUE_WORKER_BATCH_JOBS=1` followed by a worker restart. This disables grouping without rewriting jobs or receipts. Retain the prior 0.5.4 artifact for code rollback; never roll back without checking any other application changes. Keep the existing secure backup, upgrade check and restore rehearsal procedures.

## Known limitations

- Groups are formed only from jobs of one project and one token; a multi-tenant worker with highly interleaved jobs may see few grouping opportunities.
- The first claim and additional claims are separately committed. A failure between those operations leaves the first job leased until normal expiry/recovery; it is **not** lost.
- Each original job still requires a separate terminal UPDATE and outbox publication. The optimization targets repeated normalization, redaction-policy fetch and the heavy log-event INSERT, not all SQL round trips.
- Failed group processing can add overhead from rollback and individual replay. No global retry budget or cross-project grouping is introduced.
- Production throughput improvements must be established with actual PostgreSQL and an end-to-end workload; a local PDO mock cannot substantiate throughput claims.
