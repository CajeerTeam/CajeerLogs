# Nginx Log Import Stable — 0.6.5

This is a **working file-based importer**, not an Nginx module and not an aaPanel
plugin. It reads access/error logs on the Nginx host, sends bounded batches to
CajeerLogs ingest v1 via the existing Python HTTP SDK, and checkpoints only
**server-confirmed** deliveries in a private SQLite database. Requires Python
3.10+ on a Unix host (`fcntl`), access to the source log files, and a valid
CajeerLogs ingest token. PostgreSQL is only on the CajeerLogs server.

## Setup

```sh
python3 -m pip install ./clients/python
# create /var/lib/cajeer-collector owned by the collector service user, mode 0700
# EnvironmentFile (0600): CAJEER_URL=https://logs.example.com; CAJEER_TOKEN=...
cajeer-logs-collector \
  --file /var/log/nginx/access.log \
  --state /var/lib/cajeer-collector/nginx-access.sqlite \
  --source nginx-access --format nginx-access --service web --signed \
  --batch 50 --interval 2
```

Use separate `--state` and `--source` per log file and log type. Set `--format
nginx-error --timezone Europe/Amsterdam` for the error file; error timestamps
lack timezone information. For aaPanel the same executable reads
`/www/wwwlogs/DOMAIN.log` and `DOMAIN.error.log` with matching permissions.
For server-side deployment, `php bin/cajeer import:aapanel` passes flags to
this same collector; it is not an independent ingestion implementation.

**Formats:**

- `nginx-access`: standard Nginx common/combined access logs with `[dd/Mon/yyyy:hh:mm:ss +zzzz]`, request, status and bytes sent; UTC timestamp from the log's offset. It strips URL query/fragment; it never forwards referrer or User-Agent.
- `nginx-error`: standard `YYYY/MM/DD HH:MM:SS [level] message` (local timezone given by `--timezone`). Error text itself can contain secrets: configure redaction at source and destination.
- `nginx-json`: `log_format escape=json` record with `time_iso8601`, `request` or `request_method` + `request_uri` (+ optional `server_protocol`), `status`, `remote_addr`, `body_bytes_sent`, `request_time`, `upstream_response_time`. It discards all other fields. Status maps to info/warning/error. Durations are stored as milliseconds; invalid timestamps, duplicate JSON keys, malformed requests or non-finite durations stop processing at that byte offset.
- `jsonl`, `cajeer-jsonl`, `text` remain supported without changing their existing semantics.

A sample Nginx HTTP-context config is at
[`examples/nginx/cajeerlogs-json.conf`](../examples/nginx/cajeerlogs-json.conf).
`log_format` must be declared in `http`, not inside `server` or `location`.
Run `nginx -t` and reload; enable the structured `access_log` in the desired
virtual host. Configure file access permissions rather than running collector
as root.

## Checkpoints, retries and recovery

The collector checkpoints (`dev`, `inode`, `offset`, SHA-256 of the preceding
up-to-4096 bytes) after the remote server returns a **validated synchronous
receipt**. Before each request it durably stores the exact JSON payload,
idempotency key, end offset and end fingerprint. A crash, HTTP 503, timeout or
lost response does **not** advance the position. Subsequent attempts reuse the
same payload and key and generate a new HMAC nonce. Asynchronous mode
`--async` instead stores the accepted job ID and waits for successful job
completion before committing the checkpoint.

Retrying an unknown synchronous delivery after 23 hours is forbidden because
server receipt retention may have expired. Restore with the original state DB
and credentials; do not delete the DB to hide a failed batch. The `--once`
option drains all complete available lines, but will not await rotation quiet
periods. Use the continuously running service for unattended collection.

## Rotation

**Recommended: rename + reopen**, for example Nginx `USR1` after logrotate
`create`, with `delaycompress` and enough retained uncompressed archives. The
collector drains the original inode, waits for a 2-second quiet period, drains
unread intermediate archives in mtime order, and then switches to the active
path. Missing inode, incomplete last line, modified retired archive or
ambiguous archive order stops collection instead of advancing prematurely.
The `--rotated-glob` option narrows which paths are eligible, e.g.
`--rotated-glob '/var/log/nginx/access.log.[0-9]*'`. Do not include other sites.

**Opt-in: `--copytruncate`** (can be enabled on an existing 0.6.4 checkpoint without resetting the state) for legacy logrotate installations that cannot
reopen file descriptors. The importer detects truncation or a changed
checkpoint prefix on the same inode, searches for a distinct uncompressed
archive containing the already-acknowledged prefix, requires exactly one
matching archive and a 2-second quiet period, then drains that archive before
switching to the new active content. If no unique verified archive exists, the
collector stops and retains its state; it never resets the offset or silently
skips data. Keep the archived copy until it is fully consumed.

**Important:** Nginx may write bytes between the `copy` and `truncate`
operations. logrotate can lose those bytes even if the importer detects a
matching archive; `--copytruncate` cannot provide a lossless guarantee. Prefer
rename + reopen where delivery guarantees are required. The 4096-byte
fingerprint also does not detect arbitrary changes earlier in a huge file:
operators must protect archive history from external modification.

## Faults and monitoring

- Non-newline-terminated tails remain pending. Bad UTF-8, invalid input or
  oversized lines (>64 KiB, or >128 KiB for Cajeer archive input) produce an
  error without skipping the offending line. Repair input before restarting.
- Only one process can own a SQLite state DB at a time (nonblocking lock).
  State files must be regular, private (0600), and reside in a directory owned
  by the service account without group/world write permission.
- Monitor collector exit code, HTTP error count, age of last successful
  checkpoint, log-file backlog, disk capacity, retention and service restarts.
- Rotate ingest credentials only after all pending batches are acknowledged
  and state transition has been explicitly planned.

## Executable verification

```sh
python3 tests/archive_import.py
python3 tests/nginx_import_stable.py
# Additional protocol/other SDK integration test (requires their toolchains):
python3 tests/sdk_wire.py
```

The importer tests use a local real HTTP server, HMAC validation and SQLite,
including network failure, lost acknowledgment, restart, copytruncate, missing
archive, ambiguous archives and secret redaction. They do **not** substitute
for a long-running deployment test with actual Nginx/logrotate/PostgreSQL.
There are no new server DB migrations in 0.6.5.
