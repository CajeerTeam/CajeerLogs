# 0.1.4 — Log Repository

## Update from 0.1.3

Stop ingestion and workers, make a backup and separately retain `.env` and encryption keys. Overlay changed files, then run `php bin/cajeer version`, `migrate:verify`, `db:doctor`, `doctor`. Restart PHP-FPM, queue and maintenance processes. SQL migrations 0001–0008 are unchanged. Backups from 0.0.9 through 0.1.3 remain compatible with the existing restore policy (credentials invalidated on restore). The release remains Public Preview.

## Storage guarantees

`LogRepository` owns both the write and query SQL. HTTP ingestion resolves all distinct registered source slugs in one project-scoped SQL query. The repository validates decoded events again (including durable job payloads), bounds the batch to 100 events and 4 MiB of normalized rows, generates UUIDs and uses one `jsonb_to_recordset` INSERT. A materialized eligible set joins the actual source and token rows; a count equality gate admits all input rows or none. Token project, optional source binding, `logs:write`, source UUID and slug must agree. Existing authorization at admission remains mandatory; the storage check does not replace authentication. Already accepted async jobs may finish after token revocation/expiration, consistent with 0.1.3; changed or invalid scope fails closed. The existing HTTP limits (1 MiB body, 64 KiB raw event, 100 events) and 2 MiB normalized queue payload remain unchanged.

`RETURNING id` is checked against all generated UUIDs; receipt order is input order, not database row order. A caller must use the existing TransactionManager (or own the entire transaction) and propagate any exception so the whole operation rolls back. The API emits 201 only after commit, async emits 202 only after durable enqueue, and worker completion plus outbox still commit atomically. Unknown COMMIT outcomes must be reconciled using the original Idempotency-Key; no blind SQL replay. Sync/async key reuse remains rejected while a receipt or job exists; existing receipt and job expiration policies apply. No durable global exactly-once claim is made.

`PostgresFullTextSearchEngine` is now a compatibility adapter, delegating all query work to `LogRepository`. The API v1 filter syntax, field names, detail, statistics and cursor format remain unchanged. `LogQuery::scoped` refuses conflicting source constraints instead of silently replacing them. Historical rows missing project/source remain excluded. Keyset iteration uses (occurred_at,id), both descending; no OFFSET or unbounded fetchAll. HTTP pages still have max 100 rows.

## Streaming archive export

Trusted host CLI only (not a new unauthenticated HTTP endpoint). Always protect the archive because it contains log data. Export one source when planning reimport. Example:

```sh
umask 077
php bin/cajeer logs:export-jsonl PROJECT_UUID SOURCE_UUID > events.jsonl.part && mv events.jsonl.part events.jsonl
```

`SOURCE_UUID` is optional for a whole-project archive. The command reads a REPEATABLE READ READ ONLY snapshot with the schema shared lock and yields 50 rows per page. Every line is a full API event with original identifiers and timestamps. New concurrent writes are not included; retention/deletes do not change the snapshot. There is no automatic retry once output has begun. On nonzero exit, discard the `.part` file; do not treat partial output as a valid snapshot. Large exports keep a database snapshot and schema lock open, can delay vacuum and migrations, and may hit configured SQL/idle timeouts; use a maintenance window or export a source at a time. CLI has host-level privileges and can export any explicitly selected project. Do not expose it to untrusted users.

## Streaming archive import

Use existing collector's durable SQLite checkpoint and authenticated HTTP ingestion. The source slug must equal the source in each exported event; export each source separately. Provision a destination project/source and `logs:write` token. Example (environment secrets must be set securely):

```sh
php bin/cajeer logs:import-jsonl --file /secure/events.jsonl \
  --state /var/lib/cajeer-collector/events.sqlite --source app \
  --batch 50 --signed
```

The parser preserves timestamp, message, context, tags, service, host, trace/request ID and level. Source must match. Old UUIDs, project IDs and received_at are not sent; ingestion creates new IDs and new received_at. This is an import of events, not a database restore. Normal ingest validation and redaction apply; legacy or unusually large rows which exceed ingest limits must be repaired explicitly before import. Archive lines up to 128 KiB are read in bounded chunks, and an incomplete final line is an error. If a batch cannot be sent, its original serialized body and idempotency key are retained until a successful receipt. Do not delete state or resume uncertain deliveries after the documented 23-hour safe window without reconciliation. Reimport with new state creates new events. Do not use compressed files directly; decompress to a private regular file first. Existing collector rotation and ordinary JSONL behavior are unchanged.

## Legacy data quarantine and explicit adoption

```sh
php bin/cajeer logs:legacy-count
php bin/cajeer logs:legacy-list 50
php bin/cajeer logs:legacy-adopt EVENT_UUID PROJECT_UUID SOURCE_UUID --confirm
```

The list command returns identifiers, historical source labels and timestamps only, with `next_after` for pagination. The count command reports rows without both project/source and rows with project but missing source. It does not expose raw content. Adoption is a trusted operator action on a specific UUID; do not guess ownership from a slug. Review provenance independently, make a backup, and specify the verified project and registered source. The target slug must exactly match the historical row; an existing project cannot be changed. Only rows lacking source and token attribution qualify. The row is locked, validated, normalized and redacted, then updated and audited in one transaction. The original UUID and timestamps are preserved; no webhook or fake token attribution is generated. Invalid legacy records remain quarantined. There is no bulk reassignment or automatic exposure.

## Metrics

The protected system report includes `log_repository_metrics_local`: batch attempts, failed batches, returned INSERT rows, and accumulated INSERT seconds in the current PHP process. Returned rows are not a durable commit counter (a surrounding transaction may still roll back). PostgreSQL transaction and storage metrics remain available globally.

## Verification

Local: `php tests/log_repository.php`, `python3 tests/archive_import.py` plus existing runtime suites. Real PostgreSQL: `CAJEER_TEST_DATABASE=1 php tests/log_repository_database.php` in an isolated test DB with the standard DB_* settings. The suite creates an isolated schema, tests legacy migration, writes and reads actual events, checks scope rejection for wrong project/slug/token and mixed batch, SQL constraint rollback, explicit adoption, audit and CLI export. Existing ingest/queue/search/upgrade suites remain required. CI runs PostgreSQL 17/18. Do not certify production until those tests actually pass on PostgreSQL.
