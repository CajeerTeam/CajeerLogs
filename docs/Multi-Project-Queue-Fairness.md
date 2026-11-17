# CajeerLogs 0.5.7 — Multi-Project Queue Fairness

## Runtime behavior

Version 0.5.7 continues using one PostgreSQL durable `ingest-v1` queue. It does not introduce Redis, detached payloads, or a second acknowledgement path. `0024_project_queue_fairness.sql` backfills one `queue_project_state` row per existing project from the actual durable job state. Existing job IDs, payloads, leases, idempotency receipts and attempts remain unchanged.

`queue_project_state` is updated by PostgreSQL triggers after job insert, status transition, payload change and delete. It stores exact active jobs, events, bytes, pending and running jobs. Only `pending`, `running`, and `dead` consume active capacity; terminal jobs release active counters, while pending/running counters reflect the respective status. A new project gets its state in the same transaction as its project row. Project and token ownership of existing ingest jobs cannot be changed after admission.

An authenticated enqueue retains its existing order: replay detection, validation, redaction, global backpressure lock, **project budget lock**, project/source quotas, durable insert and commit. It checks per-project jobs/events/bytes against the exact PostgreSQL-accounted JSONB representation. Failed project admission returns HTTP `503 queue_project_full` with `Retry-After: 5`. It does not consume project quotas or trip the global overload latch. A replay of an already accepted idempotency key does not charge capacity again. The queue's global jobs/events/bytes caps always remain in force.

### Fair scheduling

The worker's claim transaction selects the eligible project with the **lowest weighted virtual finish**, using `FOR UPDATE SKIP LOCKED` on the project state row. It then selects an expired lease or ready pending job *inside that project* using the new project-local partial indexes, applies the original lease fencing, and increments the project's virtual finish by `ceil(1024 / weight)` for each successfully dispatched claim. A weight-4 project therefore receives approximately four claims for each claim made to a continuously eligible weight-1 project. Scheduling is approximate under concurrent workloads, not strict real-time QoS or FIFO. The first available, recently expired lease may be deferred by the existing retry policy; such deferrals do not advance the dispatch counter.

When an idle project becomes ready, its virtual finish is advanced to the current active-project floor, so it cannot exploit long inactivity to monopolize future dispatch. Workers skip project-state rows already locked by other claimers. If all eligible rows are locked, a worker may temporarily see an empty claim and poll again. A finite worker microbatch first obtains one fair claim, then claims additional ready jobs with the same project and token. Every additionally claimed job advances the project's virtual finish, and the effective microbatch size cannot exceed the remaining per-project running-job budget.

The per-project `max_running_jobs` limit is protected by the same PostgreSQL project-row update that tracks `running_jobs`; it cannot be exceeded by concurrent claims even when workers run in separate processes. Existing jobs that were running when an operator lowered a limit are not aborted; no new pending jobs are started until running count falls below the new limit. Expired leases remain recoverable even when the project is saturated.

## Operator commands

Requires the trusted local CLI, access to the application runtime role and migration 0024. The CLI serializes updates in PostgreSQL and writes an audit-chain record for policy changes. No public policy-mutating API is opened.

```sh
php bin/cajeer queue:projects
php bin/cajeer queue:project PROJECT_UUID
php bin/cajeer queue:project-verify
```

Set a weight and five budget values, in this order:

```sh
php bin/cajeer queue:project-set PROJECT_UUID 4 2000 100000 536870912 4 --confirm
```

Arguments: `WEIGHT MAX_JOBS MAX_EVENTS MAX_BYTES MAX_RUNNING`. Weight is 1–16. Each maximum may be 0, meaning no **project-level** limit; global queue limits still apply. A project running-job ceiling is 0 (unlimited) or 1–1024. The example allows four simultaneous running jobs. To use weight 1 with no project-specific limits:

```sh
php bin/cajeer queue:project-set PROJECT_UUID 1 0 0 0 0 --confirm
```

The command does not modify or cancel previously accepted jobs. Audit records include only the project UUID and validated numeric policy values.

To check or repair the exact project counters after accidental manual SQL damage:

```sh
php bin/cajeer queue:project-verify
php bin/cajeer queue:project-reconcile --confirm
php bin/cajeer queue:verify
```

Reconciliation is an explicit maintenance operation. It blocks writers during a full count and **does not change jobs**, receipts, attempts, policy settings or fairness history. Do not run it routinely under peak load. `db:verify` also includes project-accounting integrity checking. `/ready` validates required migration objects and privileges but intentionally does **not** execute the expensive full aggregation.

## Monitoring and limitations

The existing Prometheus exporter adds aggregated `cajeerlogs_queue_project_projects`, `..._pending`, `..._running`, `..._dispatched`, and `..._rejected` gauges. No project UUID appears as a Prometheus label. The protected System Report exposes these global sums. The CLI provides exact project detail.

- Fairness is measured in **claimed jobs**, not bytes, events, CPU time or wall-clock time. A 100-event job and a 1-event job each cost one virtual dispatch turn. The next performance cycle can incorporate measured processing costs.
- The global `queue_accounting` row still serializes global capacity changes. Per-project state rows also serialize updates within one project. Neither is claimed to scale linearly with worker count.
- `max_running_jobs` limits claimed jobs; workers may complete or retry them independently. It is not an OS CPU or memory quota.
- The historical migrations 0001–0023 are never edited. Migration 0024 changes database objects and must be applied before new code processes jobs.
- PostgreSQL 17+ is required. Validate actual weighted throughput, EXPLAIN plans, deadlocks and restore behavior in the certified PostgreSQL/Docker matrix before promoting public-preview to stable.

## Upgrade procedure

1. Stop HTTP writers and worker pools, take and independently verify a 0.5.6 backup, and retain configuration and encryption keys.
2. Overlay the 0.5.7 changed-files ZIP onto the 0.5.6 tree.
3. Run `php bin/cajeer upgrade:check BACKUP_ID` and `php bin/cajeer migrate` while the system is quiescent. Migration 0024 backfills counters and builds two project-local job indexes; budget for WAL and index creation.
4. Run `php bin/cajeer migrate:verify`, `php bin/cajeer queue:verify`, `php bin/cajeer queue:project-verify`, `php bin/cajeer db:verify`, `php bin/cajeer audit:verify`.
5. Set project policies explicitly for priority workloads; existing projects default to weight 1 and no project-specific caps. Restart services and workers. Check `queue:projects`, `queue:workers` and the protected System Report.
6. Rehearse restore into an isolated empty database and validate project accounting before cutover.
