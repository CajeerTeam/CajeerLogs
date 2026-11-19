# Retention and storage pressure

## Trigger

`retention_progress` or `storage_headroom` fails, cold-store readback fails, partitions grow unexpectedly or log archive requests become slow.

## Diagnosis

Check free space/inodes, active retention windows, project-specific retention policy and whether archive tier storage or PostgreSQL is failing. Retention is destructive and does not guarantee offsite DB backup coverage.

```sh
php bin/cajeer retention:list
php bin/cajeer retention:history
php bin/cajeer retention:preview
php bin/cajeer storage:archive status
php bin/cajeer operations:triage
```

## Intervention

Do **not** run retention as a reflex to full storage: confirm legal hold, backup verification, tier policy and time window first. Before cold archive eviction, inspect S3/remote object existence and readback verification; do not remove local data to suppress alerts. See [storage lifecycle](../Scalable-Storage-Retention-0.7.8.md) and [retention lifecycle](../Retention-Storage-Lifecycle.md).

## Verification

Confirm headroom/inodes, successful policy execution history, preserved read paths and sample event retrieval across hot/warm/cold storage. Monitor lag on replication before declaring stability.

```sh
php bin/cajeer retention:history
php bin/cajeer storage:archive status
php bin/cajeer health:ready
```

## Escalation

Escalate to storage/database owner for missing signed cold objects, unverified restore, persistent space exhaustion or any possible breach of legal/data retention requirements.
