# Cajeer Logs

Cajeer Logs is a lightweight self-hosted log center for bots, aaPanel/Nginx sites and infrastructure events. It is designed for small teams that need centralized ingestion, search, incident triage, bot diagnostics and retention without a heavy external observability stack.

![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)
![License](https://img.shields.io/badge/license-Apache--2.0-green)
![Runtime](https://img.shields.io/badge/runtime-no%20Composer%20required-lightgrey)

## Features

- HTTP ingest endpoint for bots and services.
- Python client handler in `clients/bot.py`.
- PostgreSQL production storage with SQLite fallback for local/demo runs.
- Web UI for logs, bot tokens, saved views, incidents, alerts, jobs and system checks.
- aaPanel log import for Nginx access/error logs.
- Retention jobs with optional NDJSON.gz archives.
- Git-based update center with preflight checks, backup and rollback support.
- No Laravel, Node.js, Go or Composer dependency required at runtime.

## Requirements

- PHP 8.2 or newer.
- PDO extension.
- PostgreSQL with `pdo_pgsql` for production.
- SQLite with `pdo_sqlite` for local/demo fallback.
- Nginx or Apache with the document root pointed to `public/`.
- `git` and `tar` only if the built-in update center is used.

## Repository structure

```text
logs-main/
├─ app/             # PHP application classes
├─ app/Internal/    # SQL schema snapshots
├─ bin/             # CLI maintenance commands
├─ clients/         # Remote logging clients
├─ docs/            # Project and deployment documentation
├─ public/          # Web root
├─ storage/         # Runtime cache/log/archive directories; contents are ignored
├─ .env.example     # Safe configuration template
├─ LICENSE
├─ README.md
└─ VERSION
```

## Quick start

```bash
git clone https://github.com/CajeerTeam/CajeerLogs.git
cd CajeerLogs
cp .env.example .env
```

Edit `.env` and set at minimum:

```env
APP_URL=https://logs.example.com
DB_CONNECTION=pgsql
DB_DATABASE=cajeer_logs
DB_USERNAME=cajeer_logs
DB_PASSWORD=change_me
LOGS_TOKEN_PEPPER=<64 hex chars>
PRIVACY_HASH_PEPPER=<64 hex chars>
```

Generate pepper values:

```bash
openssl rand -hex 32
```

Prepare runtime directories, run migrations and check the installation:

```bash
php bin/bootstrap.php
php bin/migrate.php
php bin/doctor.php
```

Create the first administrator:

```bash
php bin/make-user.php admin 'CHANGE_ME_STRONG_PASSWORD' admin
```

Point your web server document root to:

```text
/path/to/CajeerLogs/public
```

## aaPanel/Nginx deployment

A typical aaPanel deployment uses this project path:

```text
/www/wwwroot/logs.example.com
```

and this web root:

```text
/www/wwwroot/logs.example.com/public
```

After upload or `git clone`, run:

```bash
cd /www/wwwroot/logs.example.com
/www/server/php/83/bin/php bin/bootstrap.php
/www/server/php/83/bin/php bin/migrate.php
/www/server/php/83/bin/php bin/doctor.php
```

If the server uses PHP 8.2 or 8.4, replace the PHP binary path accordingly.

See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) for the full production checklist.

## Cron jobs

Recommended cron commands:

```bash
cd /www/wwwroot/logs.example.com && /www/server/php/83/bin/php bin/process-jobs.php >/dev/null 2>&1
cd /www/wwwroot/logs.example.com && /www/server/php/83/bin/php bin/alert-dispatch.php >/dev/null 2>&1
cd /www/wwwroot/logs.example.com && /www/server/php/83/bin/php bin/import-aapanel-logs.php --max-lines=1000 >/dev/null 2>&1
cd /www/wwwroot/logs.example.com && /www/server/php/83/bin/php bin/retention.php >/dev/null 2>&1
```

Recommended intervals:

- `process-jobs.php`: every minute.
- `alert-dispatch.php`: every minute.
- `import-aapanel-logs.php`: every 1–5 minutes.
- `retention.php`: daily.

## API ingest

Endpoint:

```http
POST /api/v1/ingest
```

Headers:

```http
Content-Type: application/json
X-Log-Token: RAW_BOT_TOKEN
```

See [`docs/API.md`](docs/API.md) for payload format and Python client usage.

## Connecting a Python bot

1. Open `/bots/new` or `/bots` in the web UI.
2. Create a token for the project/bot/environment.
3. Copy the generated environment block into the bot host.
4. Copy `clients/bot.py` into the bot project, for example as `remote_log_handler.py`.
5. Connect `RemoteLogHandler` in the bot entrypoint.
6. Send a test log and verify it in `/logs` or `/bots/{id}`.

## Updating from GitHub

The update center is available at `/system/update`. For public deployments, keep web-triggered updates disabled until the repository, backups and permissions are verified:

```env
UPDATE_REPO_URL=https://github.com/CajeerTeam/CajeerLogs
UPDATE_BRANCH=main
UPDATE_MODE=git
UPDATE_ALLOW_WEB=false
UPDATE_BACKUP_DIR=storage/backups/updates
UPDATE_ROLLBACK_ON_FAILURE=true
```

CLI commands:

```bash
php bin/update.php status
php bin/update.php backup
php bin/update.php update
php bin/update.php rollback
```

## Security notes

- Never commit `.env`, database dumps, runtime logs, bot tokens or update tokens.
- Rotate the PostgreSQL password and pepper values if they were ever committed or shared.
- Keep `APP_DEBUG=false` in production.
- Disable `LOGS_ENV_FALLBACK_LOGIN` after creating the first admin user.
- Prefer `INGEST_REQUIRE_SIGNATURE=true` for untrusted networks.
- Restrict access to system/recovery pages with `UI_IP_ALLOWLIST` where possible.

Report vulnerabilities using the process in [`SECURITY.md`](SECURITY.md).

## Contributing

Contributions are welcome. Read [`CONTRIBUTING.md`](CONTRIBUTING.md) before opening a pull request.

## License

Licensed under the Apache License, Version 2.0. See [`LICENSE`](LICENSE).
