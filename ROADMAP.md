# Roadmap CajeerLogs до 8.0.0

Roadmap построен по SemVer. Минорные версии внутри мажора расширяют функциональность без нарушения стабильных контрактов. Мажорные версии допускают breaking changes только с migration path, deprecation policy и понятной документацией в GitFlic Wiki.

## 0.x — Foundation Preview

### 0.1.0 — Initial Architecture Skeleton

- Базовая структура репозитория.
- PHP 8.4+ backend skeleton с PHP 8.5 readiness.
- Nginx-first deploy examples, Apache optional.
- OpenAPI 3.1.1 contract-first спецификация.
- PostgreSQL/MySQL/MariaDB/SQLite миграции первого слоя.
- ClickHouse schema для high-load analytics.
- CLI `cajeer` entrypoint.
- Web Installer + CLI Installer skeleton.
- Nuxt Admin UI skeleton.
- TypeScript API SDK skeleton.
- Python client skeleton.
- Java/Kotlin plugin skeleton.
- PHP Extension SDK skeleton.
- GitFlic Wiki source pages.

### 0.2.0 — Runtime Core

- Полный bootstrap ядра.
- Service container.
- Config repository с env overlays.
- HTTP kernel lifecycle.
- Request ID / correlation ID.
- JSON API error format.
- Middleware pipeline.
- Security headers middleware.
- Локализация системных сообщений.

### 0.3.0 — Database Core

- Production-ready PostgreSQL layer.
- PostgreSQL 17+ baseline и PostgreSQL 18+ recommended checks.
- MySQL 8.4+/MariaDB 10.11+ compatibility layer.
- SQLite 3.40+ local/demo fallback.
- Migration runner.
- Transaction manager.
- Schema diagnostics.
- DB health checks.

### 0.4.0 — Log Ingest MVP

- `POST /api/v1/ingest`.
- Batch ingest.
- HMAC signed payloads.
- Token scopes.
- Rate limiting.
- Secret redaction.
- Log normalization.
- Dead-letter queue.
- Basic retention policies.

### 0.5.0 — Search and PostgreSQL Analytics

- PostgreSQL Full-Text Search.
- Log filters: severity, source, service, host, trace_id, request_id, time range.
- Aggregations over PostgreSQL.
- Saved views.
- Export logs to NDJSON/JSON/CSV.
- Import logs from NDJSON/JSON/CSV.

### 0.6.0 — Security Core

- Users.
- RBAC.
- Permission scopes.
- API token scopes.
- 2FA для администраторов.
- CSRF protection.
- Audit log.
- Webhook signature verification.
- Security report.

### 0.7.0 — Queue, Scheduler, Storage

- Redis queue driver.
- PostgreSQL queue driver.
- RabbitMQ optional adapter.
- Built-in cron/job runner.
- Local storage disk.
- S3-compatible storage disk.
- Queue/cache/storage diagnostics.
- Archive retention jobs.

### 0.8.0 — Admin UI Preview

- Nuxt Admin shell.
- Login/session UI.
- Logs explorer.
- Saved views.
- Incidents preview.
- Tokens UI.
- System diagnostics UI.
- Extensions UI shell.
- Prebuilt assets delivery through `public/admin/assets`.

### 0.9.0 — Extension System Preview

- Extension manifest validation.
- Modules/plugins loading lifecycle.
- Permissions declared by extension.
- Events/hooks API.
- PHP SDK for extensions.
- Sandbox constraints.
- Extension config import/export.
- Compatibility checker.

## 1.x — Stable Self-hosted LTS

### 1.0.0 — First Stable LTS

- Stable REST API v1.
- Stable database schema v1.
- Production installer.
- Production migration/rollback flow.
- Security baseline.
- Full GitFlic Wiki documentation.
- LTS branch and security release policy.

### 1.1.0 — Incidents and Alerts

- Incident model.
- Alert rules.
- Alert dispatch pipeline.
- Webhooks for alerts.
- Event API for external automations.
- Signed outbound payloads.

### 1.2.0 — Import/Export Hardening

- Logs import/export.
- Users import/export.
- Settings import/export.
- Themes config import/export.
- Validation reports.
- Dry-run mode.

### 1.3.0 — Observability Hardening

- Prometheus metrics stabilization.
- OpenTelemetry traces.
- Health checks.
- System report.
- Queue/cache/storage diagnostics.
- Runtime diagnostics API.

## 2.x — High-load Analytics

### 2.0.0 — ClickHouse Analytics Core

- ClickHouse recommended analytics path.
- Dual-write strategy for selected events.
- Backfill jobs from PostgreSQL to ClickHouse.
- Aggregated dashboards.
- Analytics retention policies.
- ClickHouse diagnostics.

### 2.1.0 — Advanced Search

- Optional Meilisearch adapter.
- Optional OpenSearch adapter.
- Search index jobs.
- Reindex CLI.
- Search quality diagnostics.

## 3.x — GitFlic Registry and Updates

### 3.0.0 — Update Center

- Core updates through GitFlic Releases.
- Module/plugin/theme updates through GitFlic Registry.
- Signed release metadata.
- Preflight checks.
- Backup before update.
- Rollback workflow.

### 3.1.0 — Extension Marketplace Layer

- Registry browsing API.
- Compatibility matrix.
- Extension permission review.
- Extension install/update/remove lifecycle.

## 4.x — Ecosystem Clients

### 4.0.0 — SDK Stability

- Stable TypeScript API SDK.
- Stable Python client.
- Stable Java/Kotlin plugin.
- Client contract tests.
- Example integrations for bots, web apps and infrastructure services.

## 5.x — Enterprise-grade Operations

### 5.0.0 — Operations LTS

- Backup/restore workflows.
- Disaster recovery docs.
- Multi-node deployment guide.
- Performance budgets.
- Long-term support branch.
- Security-only release channel.

## 6.x — Advanced Modules

- Advanced anomaly detection module.
- Correlation rules.
- Custom processors.
- Server-side components for dashboards.
- Theme system hardening.

## 7.x — Scale and Federation

- Multi-instance ingestion patterns.
- Optional federated log gateways.
- Cross-instance export/import.
- Large archive browsing.

## 8.x — Ecosystem Maturity

- Stable extension ABI.
- Stable registry protocol.
- Extended SDK ecosystem.
- Migration tooling between major versions.
- Long-cycle LTS releases.
