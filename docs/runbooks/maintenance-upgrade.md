# Planned maintenance, upgrade and abort criteria

## Trigger

Deployment of 0.8.8 from 0.8.7, a failed upgrade smoke test, migration drift, expired release credentials or a rollback decision.

## Diagnosis

Record exact release ZIP/SHA-256, current DB schema/migration versions, queue leases and offsite restore confidence. Prepare a previous **code + data** pair and fresh empty rehearsal target; an older binary cannot safely read an upgraded schema unless the compatibility matrix explicitly permits it.

```sh
php bin/cajeer version
php bin/cajeer migrate:status
php bin/cajeer migrate:plan
php bin/cajeer operations:check
php bin/cajeer backup:history
```

## Intervention

Obtain a maintenance window and authorization; drain writers using the approved [queue procedure](queue-backlog.md), take a new verified backup, prove encrypted offsite readback and perform rehearsal on an empty DB. Install the exact verified release through your deployment orchestrator, then run preflight and approved migrations; do not combine code rollback with an in-place schema downgrade. See [upgrade/rollback certification](../Upgrade-Rollback-Certification-0.8.6.md).

```sh
php bin/cajeer backup:verify BACKUP_ID
php bin/cajeer upgrade:check BACKUP_ID
php bin/cajeer migrate:verify
```

**Abort** if archive verification, integrity, authentication, schema history, queue accounting or business write/read checks fail. To recover, isolate the bad deployment, provision a fresh empty DB and restore the **paired pre-upgrade** code and dump; test before traffic switch. Do not reuse a failed fenced database.

## Verification

Inspect the deployed version and verify readiness, schema, audit, queues, real client ingestion, SDK replay and external ClickHouse/S3 as configured. Resume dispatch explicitly only after approval.

```sh
php bin/cajeer version
php bin/cajeer migrate:verify
php bin/cajeer audit:verify
php bin/cajeer queue:verify
php bin/cajeer health:ready
```

## Escalation

Escalate failed migration and rollback to DBA and release owner; stop cutover if the previous version backup or old application secrets are missing. Release certification in CI does not authorize unattended production rollback.
