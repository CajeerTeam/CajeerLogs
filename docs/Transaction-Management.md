# 0.1.3 — Transaction Management

## Upgrade from 0.1.2

Stop intake and workers, create a database backup and separately save configuration/keys. Overlay the changed-files archive in the application root. Run `php bin/cajeer version`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:doctor` and `php bin/cajeer doctor`. Restart PHP-FPM, queue, scheduler and webhook workers. The numbered migrations 0001–0008 are unchanged; no new database objects or environment settings are required. A legacy installation not yet verified by the 0.1.2 engine must first run `migrate` with its migration role.

## Transaction boundary

`Cajeer\Logs\Database\TransactionManager` owns a PDO session transaction. It is used by sync/async ingest, queue claim/process/fail, identity writes and login, initial administrator setup, webhook outbox, retention, scheduler, migration transactions, TOTP CLI reset and restored credential invalidation. Migration DDL executed outside a transaction (concurrent-index) and the rollback-only reference-schema savepoint remain special cases owned by MigrationRunner. Standalone single-statement autocommit queries remain valid.

```php
$pdo = $app->container()->get(PDO::class);
$tx = new \Cajeer\Logs\Database\TransactionManager($pdo);
$result = $tx->run(function ($tx) use ($pdo) {
    $pdo->exec("UPDATE projects SET name='New name' WHERE slug='demo'");
    return $tx->run(function () use ($pdo) {
        return $pdo->query("SELECT id FROM projects WHERE slug='demo'")->fetchColumn();
    });
}, isolation: 'READ COMMITTED', maxAttempts: 3);
```

- Outer calls start a transaction, set isolation and read/write mode, force `synchronous_commit=on` for writes, probe `SELECT 1` before commit, and return the callback result only after successful commit. The probe detects a swallowed SQL error in an aborted PostgreSQL transaction.
- Isolation is `READ COMMITTED` (default), `REPEATABLE READ`, or `SERIALIZABLE`; use `readOnly: true` for a read-only snapshot. PostgreSQL itself enforces isolation and write restrictions.
- Nested calls on the **same manager** create a savepoint. They inherit the outer options; an ordinary failure rolls back to the savepoint and can be handled by the caller. Nested serialization/deadlock errors mark the root rollback-only, even if caught. Only the root may retry. The manager refuses a foreign active transaction. Callbacks must never perform BEGIN/COMMIT/ROLLBACK or change transaction ownership.
- Default is one attempt, explicit `maxAttempts` 1–5. Existing transactional business operations use three attempts. Only PostgreSQL SQLSTATE `40001` (serialization failure) and `40P01` (deadlock) are automatically replayed, after successful rollback/known abort, with bounded randomized exponential delay (5–250 ms). No retries for validation, authorization, unique/FK violations, timeouts, lost connections or unknown statement completion.
- Database-only effects inside callbacks: do not call HTTP, send mail, write files, perform external mutations or publish a response before commit. Random session/token/event IDs may be regenerated; rolled-back values never leave the callback. Rate-limit increments remain outside retry boundaries. Outgoing webhook network delivery remains outside the transaction; `claim` and `finish` are separate database-only operations. A successful callback followed by an ambiguous COMMIT is **not** reported as success. The session is marked unusable and must be replaced; queue recovery checks durable job/lease state instead of replaying an unknown operation. Sync ingest clients should retry with the same Idempotency-Key and a fresh HMAC nonce; without a key they must reconcile ambiguous writes before retrying.
- A failed rollback or failed savepoint cleanup also poisons the session. `ConnectionManager` discards it at the next operation boundary. Do not continue using a poisoned PDO directly. HTTP returns a safe error; the queue worker reconnects according to the existing 0.1.1 policy.

## Lock ordering and audit

Existing resource serialization is preserved: runtime holds the shared migration guard before starting business transactions; identity changes hold the identity advisory transaction lock before user/session/token mutations; installer holds its installation lock before identity lock; ingest locks the token before resolving sources and (async) taking the global enqueue lock; queue claim/process lock the job before job attempts; webhook claim/finish lock the delivery before attempts; scheduler locks the task before retention policy and rows. Never acquire these locks in reverse in new operations. Retries re-run authorization and HMAC freshness checks, not only the last SQL statement. Audit rows for administrative changes, successful login and 2FA are committed or rolled back with the mutation. Failed TOTP login commits the failure audit before returning unauthorized; rate limiting persists independently. Queue completion and log/outbox records share the same transaction.

## Diagnostics

`system:report` includes `transaction_metrics_local` (attempts, commits, explicit rollbacks, retries, conflicts, savepoints, cleanup failures, uncertain commits and cumulative duration). Counters live in the current PHP execution only, not across FPM requests or processes. `/metrics` continues to expose database-wide PostgreSQL transaction commits/rollbacks/deadlocks from `pg_stat_database`; those counters cover the whole database, not just application transactions.

## Tests

```
php tests/transaction.php
CAJEER_TEST_DATABASE=1 php tests/transaction_database.php
```

The first suite uses PDO test doubles to inject failures, including connection loss during COMMIT and cleanup failure. The second requires PostgreSQL 17/18 and disposable DB_* credentials with CREATE SCHEMA rights. It exercises actual isolation, savepoint recovery, swallowed SQL error, two-session serialization conflict, independent-process deadlock, audit rollback/commit and terminated backend. CI runs both plus the existing ingest/identity/queue/webhook/migration/backup suites and the actual 0.1.2 upgrade. Do not run database tests against production. Success of the local test-double suite is not proof that the PostgreSQL or deployment suites passed.
