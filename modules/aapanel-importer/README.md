# aaPanel multi-site import 0.6.6

Working local operator for aaPanel Nginx access/error/JSON logs. It uses the existing
signed or unsigned CajeerLogs Ingest API and the existing durable SQLite Collector.
No aaPanel credentials, database access or privileged daemon are required.

The manager **discovers** `/www/wwwlogs/<site>.log`, `<site>.error.log`, and
`<site>.json.log` and assigns a deterministic source slug per site and kind.
New streams are **disabled** until an operator registers the source slug in the
CajeerLogs project and enables the stream. Use a project token authorized for all
configured sources (a source-bound token cannot write to unrelated sources).
Log files, checkpoints, configuration and monitor state are local to the aaPanel host.

```bash
sudo install -d -m 0700 -o cajeer -g cajeer /var/lib/cajeer-aapanel
sudo install -d -m 0700 -o cajeer -g cajeer /etc/cajeer-aapanel
# Run as cajeer (needs read-only log access). Use a private env file for
# CAJEER_URL and CAJEER_TOKEN; HMAC enabled by default, CAJEER_SIGNED=0 for an explicitly unsigned local deployment.
php bin/cajeer import:aapanel --config /etc/cajeer-aapanel/config.json init \
  --logs-dir /www/wwwlogs --state-dir /var/lib/cajeer-aapanel
php bin/cajeer import:aapanel --config /etc/cajeer-aapanel/config.json discover
php bin/cajeer import:aapanel --config /etc/cajeer-aapanel/config.json list
# Create displayed source slugs using project source management in Admin UI.
php bin/cajeer import:aapanel --config /etc/cajeer-aapanel/config.json set --id STREAM_ID --enable
php bin/cajeer import:aapanel --config /etc/cajeer-aapanel/config.json run --once
php bin/cajeer import:aapanel --config /etc/cajeer-aapanel/config.json run --auto-discover
php bin/cajeer import:aapanel --config /etc/cajeer-aapanel/config.json status
```

Optional per-stream error-log timezone: `set --id STREAM_ID --timezone Europe/Moscow`
(before first checkpoint).
`discover --enable-new` enables newly found sources; use this only after setting
up a project-wide ingest token and deliberately accepting automatic collection.
`run --auto-discover` detects newly created sites but leaves them disabled.
The runtime uses four bounded worker threads by default (`run --workers 1..16`),
and one HTTP attempt per turn, so a failed site cannot repeatedly block others.
For systemd use `examples/systemd/cajeerlogs-aapanel.service` after replacing
paths, account and environment file; monitor with `status` (JSON / non-zero
exit code) or inspect private `/var/lib/cajeer-aapanel/monitor.json`.

**Delivery semantics:** one independent SQLite checkpoint and pending HTTP batch
per site/type, immutable idempotent retry, rename/reopen and verified uncompressed
copytruncate archive recovery. For copytruncate, writes between the copy and
truncate can still be lost: prefer rename/reopen. Per-site failures do not stop
other streams; failed streams retain their pending data and are reported as
errors, and the `run --once` command returns nonzero if any stream fails.
No raw log lines or tokens are written into monitoring output. Only regular
files under the configured log directory are followed (symlinks are refused).
Keep credentials out of command line flags. Protect config and state with
owner-only access (0700 directory, 0600 files), run as a non-root account,
and preserve state directories during upgrades and restores.

The legacy single-file command is still supported:
`php bin/cajeer import:aapanel --file /www/wwwlogs/example.com.log --state /var/lib/cajeer-collector/example.sqlite --source nginx-access --format nginx-access --once`.

The manager provides local CLI/config and JSON monitoring. It does not claim
remote aaPanel API configuration or live Admin Panel agent control; project
sources are managed through the existing Admin UI.
