# Backup failure and disaster recovery

## Trigger

`backup_fresh`, `restore_rehearsal_fresh`, `backup_status_readable`, `backup_archive_verified` or `backup_offsite_verified` fails; local backup volume is lost; primary PostgreSQL service is unrecoverable.

## Diagnosis

Do **not** delete a failing local archive. Distinguish scheduled dump failures, missing offsite mount, missing encryption key, ciphertext corruption and expired RPO. Confirm **independent** physical failure domains and escrow availability; an offsite directory on the same host does not prove resilience.

```sh
php bin/cajeer backup:history
php bin/cajeer backup:offsite-list
php bin/cajeer backup:verify BACKUP_ID
php bin/cajeer backup:offsite-verify BACKUP_ID
php bin/cajeer operations:triage BACKUP_ID
```

## Intervention

If the primary remains writable, notify incident commander and establish a controlled write stop before any recovery cutover. For a verified backup, provision a *fresh empty separate database* with an approved DBA (same schema owner, no other connections), and independently recover original application keys. Use an isolated recovery host and execute real offsite-only restore with explicit RPO/RTO budgets. A pre-0.8.7 archive cannot receive timestamp-based RPO certification. Do not place secrets in command history.

```sh
php bin/cajeer backup:dr-certify BACKUP_ID EMPTY_DB 3600 600
php bin/cajeer backup:history
```

The command performs database restore, integrity and authorization checks. The returned readiness time excludes infrastructure boot, ClickHouse/S3 recovery, DNS/load-balancer switch, client reconnect and manual approvals. See [DR certification limitations](../Backup-Disaster-Recovery-Certification-0.8.7.md).

## Verification

On the separately configured recovery host, check `/ready`, audit/queue integrity, representative event counts, token/session revocation, current encryption keys, external analytics and storage tiers. Keep the restore fence and target isolated if **any** check fails. DNS/traffic change is manual and requires explicit authorization.

```sh
php bin/cajeer health:ready
php bin/cajeer db:verify
php bin/cajeer audit:verify
php bin/cajeer queue:verify
```

## Escalation

DBA + security owner + incident commander for missing escrow key, corrupted offsite backup, RPO/RTO breach, unexpected post-restore data, or failed restore fence. Preserve failed evidence for review.
