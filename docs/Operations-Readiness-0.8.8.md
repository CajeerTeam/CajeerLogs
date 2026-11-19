# Operations Readiness — 0.8.8

This is the operational entry point for the deployed **0.8.8** application, not a guarantee of production readiness. Start at [the on-call index](runbooks/README.md) and keep a copy of this documentation **outside** the affected host. The CLI checks existing PostgreSQL, worker, backup, audit, queue and filesystem state; it does not repair failures or automatically promote a recovery database.

## Minimum operator workflow

Use the same restricted runtime account and environment as the deployed application. Do not paste `.env`, tokens, secrets, raw DB errors or customer log payloads into tickets. For a normal shift handoff:

```sh
php bin/cajeer version
php bin/cajeer health:ready
php bin/cajeer operations:triage
php bin/cajeer queue:status
php bin/cajeer backup:history
```

`operations:triage [BACKUP_ID]` runs the existing `OperationsCheck` and emits a JSON report with **only** boolean check outcomes, skipped-check identifiers and corresponding local runbook paths. Exit **0**: no checked failure; **1**: degraded (warnings only); **2**: critical or unavailable. A skipped check means **not assessed**, never success. `operations:check` remains the authoritative detailed read-only check, including backup timestamps and worker state. Triage does not verify an offsite copy unless `BACKUP_ID` is explicitly given; an `ok` report is not an RPO/RTO certificate, end-to-end availability proof or security audit.

To preserve an incident timestamp and check results:

```sh
php bin/cajeer operations:incident
php bin/cajeer operations:incident BACKUP_ID
```

The command returns the path to an incident JSON report inside private `storage/incident-reports/` (0700, file 0600, exclusive create), includes no raw secrets or event payloads and never overwrites an existing report. Store and transfer it using approved encrypted incident evidence storage and the organization's retention policy. Exit codes match `operations:triage`; **a nonzero status may still have a successfully written report**. The operator must review the report before sharing, since check names and deployment version may themselves be sensitive. An error creating the private file does **not** turn a failed check into success.

## Operating boundaries

| Activity | Safe first command | Destructive or state-changing action requires |
| --- | --- | --- |
| Runtime/DB outage | `php bin/cajeer health:ready` | DBA and incident commander approval for failover or replacement |
| Queue backlog | `php bin/cajeer queue:status` | Explicit `--confirm` for pause/resume/drain; coordinate writers |
| Backup freshness | `php bin/cajeer backup:history` | Separate empty target and correct keys for DR; never point restore at live DB |
| Rehearsal | `php bin/cajeer backup:dr-certify BACKUP_ID EMPTY_DB 3600 600` | Disposable **empty** DB provisioned by DBA; explicit RPO/RTO budget |
| Schema upgrade | `php bin/cajeer migrate:plan` | Verified backup and maintenance window; no automatic rollback of live schema |
| Retention | `php bin/cajeer retention:preview` | Approval before deleting historical data |
| TLS/authentication | `php bin/cajeer tls:check` | Security owner approval for credentials, keys, certificates |

Before maintenance, record the previous release SHA-256, deployed `.env` version/location, on-call roster, last successful *verified offsite* backup, successful restore drill, and clear go/no-go criteria. Keep secrets in an independent secure vault. Run the release verification and compatibility procedures in [Upgrade & Rollback Certification](Upgrade-Rollback-Certification-0.8.6.md), and [DR](Backup-Disaster-Recovery-Certification-0.8.7.md); only evidence from the *target infrastructure* establishes operational objectives. External ClickHouse, S3, TLS, PHP services, caches and DNS may have separate recovery procedures; DB restore alone does not restore them.

## Routine schedule and incident response

- **Each shift**: `operations:triage`, `/ready`, queue status, backup history, active alerts and audit verification. Investigate skipped/failed checks; compare changes to last baseline.
- **Daily**: verify backup due-check, offsite authentication with `backup:offsite-verify BACKUP_ID`, free disk, worker heartbeats and queue backlog; check scheduled retention only within its configured window.
- **Weekly**: run `backup:rehearse` or `backup:dr-certify` on a fresh empty DB, verify selected records and credentials, document observed readiness time and any RPO breach. Never certify production RTO using DB-only timing.
- **On release**: execute the maintenance checklist, keep the previous code and DB snapshot together; follow the rollback decision tree and run post-deploy smoke tests.
- **On incident**: preserve an `operations:incident` snapshot before and after changes where possible; assign incident commander, record severity, start/end time, affected projects, decisions and user-facing communication. No background remediation is attempted by the triage CLI.

## Validation of instructions

`python3 scripts/check_runbooks.py` verifies that each runbook has trigger/diagnosis/intervention/verification/escalation sections, referenced local Markdown files exist, and `php bin/cajeer` invocations in fenced shell examples name commands actually accepted by the CLI dispatcher. It runs as part of CI and on the exact built ZIP. This is **documentation consistency**, not a substitute for exercised PostgreSQL restores, traffic recovery, alert firing or on-call training.

## Release note and constraints

0.8.8 adds read-only incident classification, private evidence snapshots, operator runbooks and CI documentation consistency checks. No schema migrations, API breaking changes, automatic destructive repairs or service failover are introduced. `public-preview` is retained until the full target-environment certification matrix and tabletop/on-call drills have passed.
