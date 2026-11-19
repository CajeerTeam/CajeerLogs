# Queue backlog, dispatch pause and worker loss

## Trigger

`queue_accounting` or `worker_queue` fails, dead-letter grows, workers become stale, deliveries stall, or admission backpressure increases.

## Diagnosis

Determine whether dispatch is already paused, durable jobs are pending/running/dead, leases have expired, and worker supervision is functioning. Distinguish service rejection from SDK local spool backlog.

```sh
php bin/cajeer queue:status
php bin/cajeer queue:workers
php bin/cajeer queue:pool-health
php bin/cajeer queue:verify
php bin/cajeer queue:capacity
```

## Intervention

Investigate dependencies and worker/container memory before scaling. For planned maintenance use a controlled pause and drain **only** after coordinating external ingestion and defining rollback. `queue:drain` can time out leaving dispatch paused; never assume it resumes automatically. Dead-letter retry/discard is a conscious operator decision based on payload safety and idempotency; never mass-discard unknown jobs.

```sh
php bin/cajeer queue:pause maintenance --confirm
php bin/cajeer queue:drain 120 --confirm
```

See [queue operations](../Queue-Operations-Observability.md) for detailed preconditions and resumption order.

## Verification

After maintenance or remedial worker restart, explicitly resume dispatch when approved. Verify that live leases settle, completed event counters advance and no new dead-letter spike appears.

```sh
php bin/cajeer queue:resume --confirm
php bin/cajeer queue:status
php bin/cajeer queue:verify
php bin/cajeer queue:workers
```

## Escalation

Escalate if audit/queue accounting fails, queue drain exceeds lease/grace period, jobs are repeatedly dead-lettered or operator cannot safely establish idempotency of retries.
