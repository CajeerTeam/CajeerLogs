# JSON/CSV Bulk Export — 0.6.8

This is a working asynchronous, session-authenticated PostgreSQL export pipeline, not an extension of the single-page synchronous export API.

## Upgrade and operation

1. Back up the application and PostgreSQL, apply migration `0027_export_jobs.sql` with the existing migration runner, then deploy 0.6.8. Existing page export endpoints are unchanged.
2. Ensure the PHP worker and HTTP/PHP processes share persistent `storage/exports/private` storage on the same machine or a secured shared mount. The app creates this directory with mode 0700 and export artifacts with mode 0600. Do not expose it through the web root or include it in public backups.
3. Run `php bin/cajeer exports:work 5` repeatedly using a dedicated service/scheduler (example systemd service and timer provided under `examples/systemd`). Run `php bin/cajeer exports:cleanup` hourly to remove expired artifacts and stale temporary files. Run the worker under the same restricted application user that owns the directory.
4. In Admin Panel → Logs → Bulk JSON/CSV Export, select a **bounded** time range, create a job, monitor its status and download the completed artifact through authenticated project API.

The endpoints are session-only and require both `logs:read` and `logs:export` in the project (and source where applicable). POST requires CSRF and fresh authentication, following existing identity policies. Tokens cannot create, list or download bulk exports. A job belongs to its originating user; other users cannot download the file merely by knowing its UUID.

- `POST /api/v1/projects/{project}/exports` with JSON `{"format":"json", "filters":{"from":"...Z","to":"...Z"}}` returns 202 and a job object. Existing log filters may also be supplied under `filters`.
- `GET /api/v1/projects/{project}/exports?limit=30` lists jobs owned by this user.
- `GET /api/v1/projects/{project}/exports/{export}` returns status and metadata.
- `GET /api/v1/projects/{project}/exports/{export}/download` streams a ready result after checking permissions, expiry, byte size and SHA-256.

## Limits and consistency

- Per owner: 5 queued/processing jobs; export period: at most 31 days; 1,000,000 rows; 256 MiB; 15 minutes execution; 3 worker attempts for interrupted jobs. Exceeding the budget marks the job failed.
- Worker reads PostgreSQL through an indexed keyset scan in a REPEATABLE READ snapshot and writes a temporary file before atomically publishing a final JSON or CSV. This ensures that a completed export has consistent pagination across multiple pages. Unfinished artifacts are never served.
- A PostgreSQL session advisory lock prevents concurrent workers from processing the same job. If a process crashes, its lock is released and an unfinished processing job may be picked up again; temporary files are swept by cleanup.
- Each artifact expires after 24 hours. Metadata is retained for 7 more days. Downloads verify stored SHA-256 and size. A mismatch is rejected with HTTP 410. CSV values that might evaluate as spreadsheet formulas are prefixed defensively.
- No artifact files should be placed in publicly mounted web directories. Allocate disk headroom, monitor worker failures/queue depth, and make sure scheduled cleanup runs even when there are no incoming events.

### Certification

`php tests/export_jobs.php` covers local validation and CSV streams. `php tests/export_jobs_database.php` is a real isolated-schema PostgreSQL test with 125 events, multi-page export, authorization and checksum tamper checks. The test is included in the PostgreSQL certification gate; it must actually pass on the deployment target before claiming production performance certification.
