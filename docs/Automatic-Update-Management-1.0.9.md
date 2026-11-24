# Automatic Update Management — 1.0.9

Automatic updates are disabled by default. The operator controls the persistent policy with `php bin/cajeer update:auto-configure`. The scheduler runs independently of the web server and maintenance scheduler. It uses the real GitHub Releases discovery and the verified download and native safe installer from 1.0.1–1.0.3. No new migrations. A changed source archive is not a signed published release.

## Deployment and trust boundary

Supported: canonical, single-host native Linux installation owned by a non-root application operator, with systemd, Python 3.11+, PHP 8.4+, PDO PostgreSQL/ZIP, Composer, PostgreSQL client tools, and GitHub CLI attestation support. Read [Safe Update Installation](Safe-Update-Installation-1.0.3.md) and meet every prerequisite before enabling `install`. Docker/distributed installations continue to use operator-driven deployment; this scheduler does not restart or mutate Docker containers. The application owner and its executable code are trusted for native updates; do not enable unattended installs on a shared or untrusted host. Never run the web process as root, grant it broad sudo, or allow HTTP users to set commands, database names or systemd units. A deployment requiring strict OS-level privilege separation must use a separately administered update system instead of this native scheduler. Configure GitHub authentication in the timer's trusted environment as appropriate, not in a public file. Verify TLS and the exact signed artifacts. Do not disable attestation verification.

Install the example service and timer from `examples/systemd/`, substituting the actual owner, group, canonical path and systemd inventory. Place the units under the administrator-controlled systemd directory and enable **only the timer** with `systemctl daemon-reload` and `systemctl enable --now cajeerlogs-auto-update.timer`. It runs a bounded policy tick each minute; the policy interval determines actual network checks. The timer/service itself must never be part of the stopped application unit list. Use the exact names `cajeerlogs-auto-update.service` and `.timer`. Restrict any required noninteractive systemctl permissions to the explicit app units; review the security implications of unattended privilege delegation. Check/download modes require no systemctl privilege. The scheduler and manual native installer share the install lock at cutover. Do not run other deployment tasks concurrently.

## Modes and channels

```sh
php bin/cajeer update:auto-configure --mode check --channel stable --interval-minutes 60
php bin/cajeer update:auto-tick
php bin/cajeer update:auto-status
php bin/cajeer update:auto-configure --mode download --channel preview --interval-minutes 120
php bin/cajeer update:auto-configure --mode off
```

`check` only discovers and notifies. `download` additionally retrieves and verifies a specific newer version using SHA-256, signed provenance and runtime compatibility; it does not install. `install` supports stable releases only. Stable/preview controls scheduled checks independently from `UPDATE_CHANNEL` used for ordinary discovery. To use the same channel for manual checks, set that environment variable too or use `update:check --channel=preview`. Scheduled errors do not count as success and retry after the configured interval. No new candidate or no published releases is not an error. The newest candidate is re-discovered before an install, so a withdrawn release or changed channel is not installed from stale data. `update:download --channel=stable --version=VERSION` requires the specified release still be the newest published candidate for that channel.

## Unattended installation

Create separate, genuinely empty PostgreSQL databases owned by the app database role in advance. Never reuse a database that was attempted, even if the install rolled back; retire failed clones and provision new names. The updater does not create/drop databases or choose credentials. The retained old database is not a writable standby. Provide the entire unit list, including all external writers and activating timers/sockets/path units as described in the native installation guide. This example is illustrative only: replace the unit list with the entire inventory of your own deployment.

```sh
php bin/cajeer update:auto-configure --mode install --channel stable \
  --interval-minutes 60 --window-start 03:00 --window-minutes 60 \
  --target-db cajeer_logs_next_a --target-db cajeer_logs_next_b \
  --unit cajeerlogs-web.service --unit cajeerlogs-queue.service \
  --unit cajeerlogs-scheduler.service --unit cajeerlogs-scheduler.timer \
  --unit cajeerlogs-webhook.service --unit cajeerlogs-webhook.timer \
  --sudo-systemctl --confirm
```

The daily window is UTC and supports crossing midnight. It restricts the **start** of installation, not the end of backup or migration; a running installation is never killed simply because the window closes. The full signed ZIP is downloaded before installing. The original installer independently verifies provenance again, checks current readiness, stops and masks all specified services, backs up PostgreSQL, restores to the reserved isolated database, migrates, validates readiness, atomically swaps files and resumes services. Each attempt permanently consumes a name from the policy pool. When the pool is exhausted, automation blocks and notifies. Reconfigure with newly provisioned database names and explicitly resume after validating the deployment. Never set external `DB_*`, `APP_URL`, `BACKUP_DIR`, `CAJEER_ENV_FILE` or release-version overrides for install mode.

## Notifications and recovery

Admin → System → Automatic updates displays mode, UTC window, latest/next check, candidate, verified ZIP, unused database count, heartbeat and the most recent 20 transition notifications. The existing `system:read` API returns a bounded display-only projection even when GitHub discovery fails. It cannot authorize or execute installation. Stale status warns of a missing heartbeat (or an active long installation). Operator policy and authoritative state live outside the live directory in `.current.automatic-update/` (0700), so an atomic application switch does not lose schedule, consumed targets or recovery fence. Do not remove that state while the scheduler is active. Web requests never write it. Notifications are visible in the panel and the systemd journal; no external mail service is required. A failed tick exits nonzero, and a concurrent tick exits without launching a duplicate. Status/configuration commands refuse concurrent modifications.

A failed or interrupted installation prevents subsequent automatic attempts. Do not remove the recovery journal or expose both databases simultaneously. Inspect `update:status` and the systemd journal; use `update:recover --confirm` (with `--sudo-systemctl` if required). Before commit the native installer can restore the prior version; after commit it must resume the new DB and must not discard accepted writes. When ready, explicitly execute:

```sh
php bin/cajeer update:auto-resume --confirm
```

Resume checks the authoritative native installer journal. If it is unfinished, recovery is required first. Replenish the DB pool as necessary. To stop future automation configure `--mode off` (or disable the timer); do not kill an active install. The scheduler never runs an unverified artifact, automatically retries a failed installation, or rolls back committed data.
