# Dead-Letter Management — 0.5.4

Use the **Проблемные задания** workspace (`/admin/#queue`) with a selected project. It lists dead `ingest-v1` jobs, including safe error category, attempts, failure timestamp and durable payload sizes. Filter by reason, source UUID (a source present in the original payload), minimum attempt count and minimum age in seconds since the dead transition. Results are descending bigint ID keyset pages; the cursor is the last returned ID, not an offset. A changing queue is not a frozen snapshot. Job detail includes all retained attempt records (up to the existing 100-attempt lifetime cap), not log payloads, token identifiers, hashes or raw exception messages.

## Authorization and safe mutation

Built-in Administrator and Operator have `queue:read`, `queue:retry`, `queue:discard` within their existing project scope; Viewer does not. Existing custom roles do not automatically gain these permissions. Grant `queue:read` plus only the necessary mutation permission using the existing RBAC role editor. Machine API tokens cannot use these routes. Mutations require a session, CSRF, and recent authentication when configured. Use Settings → confirmation of identity before retrying an operation rejected as `reauthentication_required`.

API prefix `/api/v1`:

| Route | Operation |
|---|---|
| GET `/projects/{project}/queue/dead` | `limit` 1–100, `cursor`, `reason`, `source_id`, `min_attempts`, `min_age_seconds` |
| GET `/projects/{project}/queue/jobs/{job}` | Metadata and attempts, including terminal and retried jobs |
| POST `/projects/{project}/queue/dead/retry` | Retry 1–20 selected jobs |
| POST `/projects/{project}/queue/dead/discard` | Discard 1–20 selected jobs |

Mutation body example: `{"items":[{"id":"42","attempts":8}],"confirm":true}`. IDs are decimal strings (signed bigint). Duplicates, unknown fields and unconfirmed selections are invalid. The server locks rows in numeric order, validates every row belongs to the project and is still dead with the exact displayed attempt count, and applies the entire selection in one transaction. A stale selection returns 409, an absent/foreign job 404. No partial success. Re-read state before resubmitting after an ambiguous response; there is no automatic retry of destructive calls.

Retry reuses the original job ID, normalized payload, delivery receipt and attempt history. It grants the configured additional attempt budget, limited by the existing lifetime maximum of 100. If exhausted, investigate and decide whether to discard rather than recreate the same accepted delivery. Discard permanently erases the stored payload and leaves a cancelled receipt and attempt history until the existing retention/receipt expiry. It releases active queue accounting. Neither action changes already committed log rows. Both actions append `queue.retried` / `queue.discarded` to the existing audit chain in the same transaction. The audit target is the job ID; context includes project, attempt count and request ID. Failed audit writes roll back the job mutation. CLI `queue:retry JOB_ID` and `queue:discard JOB_ID` now use the same audited path (system actor); CLI access is trusted operator access.

## Monitoring and recovery

System Report and `/metrics` expose `cajeerlogs_queue_dead_jobs`, `cajeerlogs_queue_dead_events`, `cajeerlogs_queue_dead_bytes` and `cajeerlogs_queue_oldest_dead_seconds`. The supplied Prometheus alert `CajeerLogsDeadLetterBacklog` warns for persistent dead jobs older than 15 minutes or at least 10% of configured global job capacity (5-minute hold). Bytes are JSONB text bytes, not physical storage. Dead jobs still consume `QUEUE_MAX_JOBS`; no automatic purge or separate hard quota is introduced, since accepted work must not silently disappear. A full DLQ requires operator investigation; retry only after fixing the cause, discard only with explicit business approval. Multidimensional admission budgets remain the planned backpressure release.

Upgrade: stop writers, create and verify backup, overlay the patch, run `upgrade:check BACKUP_ID`, `migrate:verify`, `queue:verify`, `audit:verify`, `db:verify`, and `doctor`; restart all processes. No new migrations. Existing roles, jobs, receipts and history are preserved. Full PostgreSQL integration and upgrade tests are part of release certification.
