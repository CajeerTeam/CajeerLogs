# CajeerLogs 1.0.0 — on-call runbooks

## Trigger

Open the applicable scenario when `operations:triage` reports a failed check, `/ready` fails, a Prometheus alert fires, or customers report missing/delayed logs.

## Diagnosis

Begin with read-only checks using the runtime's `.env`, and retain timestamps. `operations:triage` classifies findings; `operations:incident` creates a private local evidence file. Avoid distributing credentials, SQL errors or log bodies.

```sh
php bin/cajeer operations:triage
php bin/cajeer operations:incident
```

| Symptom | Runbook |
| --- | --- |
| Local runtime, HTTPS, disk or worker process outage | [Runtime outage](runtime-outage.md) |
| Database connect/readiness, schema drift, integrity | [PostgreSQL outage](database-outage.md) |
| Backlog, dead-letter, paused dispatch, worker liveness | [Queue backlog](queue-backlog.md) |
| Missing/stale backups, offsite failure, DR event | [Backup and disaster recovery](backup-disaster.md) |
| Unauthorized access, compromised keys, MFA, TLS, audit integrity | [Security incident](security-incident.md) |
| Storage pressure, retention or archive tier failure | [Retention and storage](retention-storage.md) |
| Planned upgrade, failed deployment or compatibility | [Maintenance and upgrade](maintenance-upgrade.md) |

## Intervention

Appoint an incident commander for any production data integrity, data loss, secrets compromise or customer-facing outage. Only use state-changing commands listed in the relevant scenario after verifying current state and obtaining approval. Never blindly restore into the live PostgreSQL database.

## Verification

For all scenarios, confirm `health:ready`, `operations:check` and relevant checks after remediation; compare event counts and queue state, then resume traffic only through your external deployment orchestrator.

```sh
php bin/cajeer health:ready
php bin/cajeer operations:check
```

## Escalation

Escalate to database/infra/security owners if the root cause is unknown, required credentials are unavailable, the restore fence is present, RPO/RTO budgets are exceeded, or the same failure recurs. The runbooks do not authorize an automatic production cutover.

For preflight, staged upgrade, rollback and release evidence, use [1.0.0 Release Candidate Operations](../Release-Candidate-Operations-0.9.9.md).
