# Advanced Filters & Search — 0.3.5

Log Explorer uses the existing PostgreSQL search API; no alternate index or client-side search is introduced. Search modes web/plain/phrase, exact fields, multi-value levels/source/source UUID/service/host/trace/request IDs, tags ALL/ANY and JSON context predicates remain available. Values inside a list use OR (except tags ALL), distinct filters use AND. The visual path editor preserves ordered keys and array indices, supports existence (blank comparison) or a JSON scalar, including `null`. JSON path search requires a range of at most 31 days. Raw JSON object/scalar text is kept as text, without rounding numeric literals through JavaScript.

Choose a source using the project registry search and Next results, or enter a known slug/UUID. Apply filters with **Найти**. Presets replace the current filter set; active chips remove one filter, and quick filters on visible table cells apply exact values. The last-hour preset resolves to absolute UTC boundaries when selected. Search parameters are not persisted in the browser or URL. Log export and signed cursors are unchanged.

## Saved views

Open **Сохранённые представления** in Explorer. Save applied filters and current column order/visibility/widths/density/timezone/wrapping, then apply, rename, update or delete. An unapplied draft is not saved. A saved view starts a fresh query; cursors, results and snapshot watermarks are never stored. A date range is absolute, not rolling. Each user/project can have up to 100 private views, names unique within that scope. All operations require an active cookie session and current project `logs:read`; writes require CSRF. Administrators cannot read another user's private views. Revision conflicts prevent stale updates. Audit records contain operation, view/project IDs, and actor, not filter content.

`GET/POST /api/v1/projects/{project}/saved-views`, `GET/PATCH/DELETE /api/v1/projects/{project}/saved-views/{view}`. POST/PATCH accept `name`, `filters` (query strings, arrays/JSON encoded as strings), `layout` (full table layout); PATCH also requires integer `revision`. GET list returns `items`; individual GET/POST/PATCH return the view. DELETE returns 204. No bearer access. Filters are validated by LogQuery on save and read. Sensitive queries intentionally saved are stored in PostgreSQL and backups; avoid storing secrets and use standard backup access controls.

## Upgrade

Stop application/workers, back up database, overlay changed files and run `php bin/cajeer migrate`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify`, `php bin/cajeer openapi:lint`, `npm --prefix admin run build`. Migration 0016 creates only the saved-view table/index; existing data and history are preserved. Rebuild container images when applicable. Full database and production release certification is required before declaring stable.
