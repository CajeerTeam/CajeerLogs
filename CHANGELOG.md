# Changelog

Формат основан на [Keep a Changelog](http://keepachangelog.com/ru/1.0.0/), версии следуют [Semantic Versioning](https://semver.org/lang/ru/).

## [0.1.3] — Transaction Management

- Единое владение транзакциями, savepoints, isolation и ограниченные retry SQLSTATE 40001/40P01.
- Интеграция в ingest, identity, очередь, outbox, scheduler, retention, installer, migrations и restore.
- Защита от неопределённого COMMIT и повторного использования повреждённого PDO; локальные метрики транзакций.
- SQL 0001–0008 без изменений; совместимость backup 0.1.2.

## [0.1.2] — Migration Engine

- Последовательные per-file транзакции под общей session advisory lock; история SHA-256, durable attempts и безопасные ошибки.
- `migrate:plan`/`migrate:verify`: структурный PostgreSQL catalog, drift, read-only plan; legacy adoption через replay в rollback-only reference schema.
- Явные режимы concurrent index и ограниченного idempotent batch backfill; восстановление незавершённых попыток.
- Shared guard для HTTP/worker/maintenance, readiness и schema_verified metric; поддержка восстановления backup 0.1.1.
- SQL 0001–0008 неизменны. Служебные таблицы миграций создаются движком. PostgreSQL 17/18 integration и upgrade tests в CI.

## [0.1.1] — PostgreSQL Runtime

- Непостоянные PDO-сессии, ограниченный retry подключения с jitter, классификация PostgreSQL/libpq ошибок, настройки application_name и idle-in-transaction timeout.
- Queue worker восстанавливает соединение на границе заданий без слепого повтора SQL/COMMIT; lease остаётся источником истины. Диагностические PostgreSQL-метрики и безопасные ошибки.
- Раздельные CLI migration credentials и runtime-пользователь, без записи повышенного пароля в .env; совместимость backup 0.0.9/0.1.0.
- PostgreSQL 17/18 CI, тесты потери сессии, перезапуска, TLS и отдельной роли; SQL 0001–0008 неизменны.

## [0.1.0] — First Public Release

- Production Compose: изолированный PHP-FPM/Nginx/PostgreSQL, постоянная конфигурация и storage, workers/scheduler/webhooks.
- Детерминированная сборка и верификация ZIP, обязательный release gate после регрессий и production smoke.
- Совместимость восстановления 0.0.9, диагностика fsync/реплики и повреждённого или неверного TOTP-ключа.
- Production HTTPS-origin и отдельный защищённый путь конфигурации. SQL 0001–0008 неизменны.
- Публичный MVP; не LTS. Статус интеграционных проверок определяется фактическим CI.

## [0.0.9] — Security & Operations

- TOTP RFC 6238, encrypted per-user secrets, replay prevention, one-time recovery codes, optional mandatory admin enrollment, browser setup and session rotation; trusted CLI recovery with audit.
- PostgreSQL custom-format backup, atomic private publication, SHA-256 verification and restore to a separate empty database with credential invalidation; daily systemd example.
- Real operations metrics and protected scrape token, scheduler/queue/webhook monitoring, readiness security checks, HSTS and storage path hardening.
- Migration 0008, preserved 0001–0007, expanded runtime/browser and PostgreSQL backup/restore integration tests; local release metadata comparison.

## [0.0.8] — Queue & Lifecycle

- Durable PostgreSQL async ingest, token-owned job status, capacity backpressure, cross-mode idempotency conflict and validated/redacted payloads.
- Concurrent `SKIP LOCKED` worker, leases/fencing, transactional batch INSERT + webhook outbox + completion, retries/dead-letter/history, manual retry/discard, SIGTERM/SIGINT.
- Opt-in per-project retention by receipt time, bounded cleanup, persisted scheduler with advisory locks, queue metrics and systemd/cron examples.
- Migration 0007 preserves previous checksums and legacy rows; queue integration suite, HTTP/CLI tests and CI.

## [0.0.7] — SDK & Integrations

- Рабочие PHP/PSR-3/Monolog, Python/logging, TypeScript/Node и Kotlin/JVM SDK: batch, HMAC v1, retry/backoff, idempotency и проверка подтверждения.
- Unix/Linux collector: SQLite pending/checkpoint, JSONL/text/Nginx, aaPanel CLI, rotation/restart, systemd example.
- `logs.ingested` webhooks: атомарный PostgreSQL outbox, HTTPS/HMAC, публичный IPv4 DNS pinning, leases, retry/history/disable/cleanup; CLI worker.
- Миграция 0006 без изменения 0001–0005; SDK wire/collector tests и webhook integration suite в CI.

## [0.0.6] — Admin Panel

- Реальная same-origin SPA `/admin/`: cookie-сессии, CSRF, роли, dashboard, фильтры, пагинация, детали, CSV/JSON.
- Создание проектов и источников, участники, выпуск/отзыв токенов, пользователи, профиль и диагностика.
- Безопасный вывод недоверенных логов через DOM text nodes, CSP, адаптивный UI и две темы.
- Поставка готовых статических assets, без npm-зависимостей на сервере. Миграции 0001–0005 неизменны.
- Browser tests для UI и HTTP; PostgreSQL E2E проверяется отдельно.

## [0.0.5] — Log Search & Explorer

- PostgreSQL FTS, project/source-scoped list and detail, filters, deterministic cursor pagination.
- UTC statistics (levels, top sources, hourly/daily buckets), paged JSON/CSV export with formula protection.
- Session RBAC and scoped `logs:read` tokens, production TLS, rate limits, bound SQL parameters; unscoped legacy events remain hidden.
- Migration 0005 adds filter indexes; 0001–0004 unchanged. Updated OpenAPI, regression tests and PostgreSQL integration suite.
- Graphical Admin UI and async processing remain future releases.

## [0.0.4] — Log Ingestion

- Real synchronous `POST /api/v1/ingest`, authenticated by project/source-scoped `logs:write` token; transaction commits before HTTP 201.
- Single/batch (100 events), strict JSON/PSR-3/RFC3339 validation, UTC normalization, metadata, recursive secret redaction, prepared PostgreSQL INSERTs and generated UUIDs.
- Migration 0004 preserves existing data, requires scoped new log rows, adds token attribution, durable idempotency receipts (24h) and HMAC nonce tracking. Previous migrations unchanged.
- Optional HMAC v1 binds method, path, timestamp, nonce, idempotency key and body hash; per-IP/per-token rate limits and cleanup. Python client updated to canonical signature.
- OpenAPI, runtime/HTTP tests, PostgreSQL integration suite and CI. Search, UI and async workers remain future releases.

## [0.0.3] — Identity & Access

### Added
- PostgreSQL migration 0003: project membership, blocked users, server-side hashed sessions, rate-limit buckets, token issuer metadata.
- Реальный HTTP login/logout/logout-all/me, Argon2id, изменение пароля, ротация и отзыв сессий, cookie + CSRF, production HTTPS.
- Управление пользователями и ролями, защита последнего действующего администратора; транзакционные изменения и аудит.
- Создание/просмотр проектов и источников, управление членством, SQL-изоляция проектов для operator/viewer.
- Scoped API-токены с expiry, hash storage, last_used_at, выдачей секрета один раз, отзывом и introspection; защищённый system report.
- Atomic PostgreSQL rate limiting, CLI auth:cleanup, cron, реальные integration tests и OpenAPI runtime routes.

### Changed
- Существующие миграции 0001/0002 сохранены без изменения checksum; readiness проверяет новые таблицы.
- Production identity API требует HTTPS. Legacy глобальные токены с log scopes не авторизуются.
- Нереализованные TOTP/HMAC ingest не объявляются активными. Ingest, поиск, UI и workers — последующие версии.

## [0.0.2] — Database & Installer

### Added
- PostgreSQL 17+ через PDO: проверка версии и схемы, настройки TLS, таймауты соединения, запросов и блокировок; подключение через контейнер runtime.
- Транзакционный runner для PostgreSQL, история `schema_migrations`, SHA-256 контрольные суммы, advisory lock, отказ при расхождении истории или неизвестных миграциях.
- CLI `install`, `migrate`, `migrate:status`, `db:doctor`, реальные проверки БД в `install:check`, `doctor` и `system:report`.
- CLI-установщик с атомарным созданием `.env` (0600), уникальными секретами и первым администратором с Argon2id; повторный запуск не сбрасывает пароль.
- Репозитории пользователей, проектов, источников и токенов, связи и индексы PostgreSQL; токены хранятся как SHA-256 хеши случайных 256-битных значений.
- `/ready` для проверки БД и миграций независимо от runtime health; локальный Docker Compose с отдельной ролью БД.
- Тесты конфигурации, PostgreSQL integration suite и CI с PostgreSQL 17.

### Changed
- Базовая PostgreSQL-схема больше не требует pgcrypto; ручное применение SQL не является способом установки. Единственный источник SQL — `migrations/pgsql/`.
- PostgreSQL — единственная поддерживаемая СУБД в 0.0.2; web-установка отключена.

### Scope
- Приём/поиск логов, HTTP-авторизация, очередь и Admin UI ещё не реализованы. Наличие таблиц, репозиториев и первого администратора не означает наличие рабочего входа через браузер.

## [0.0.1] — Core Runtime

### Added
- Рабочий PHP Application Kernel, загрузка `.env`, валидация runtime-конфигурации и DI с singleton/transient lifecycle.
- HTTP Request/Response, Router с параметрами и middleware, HEAD/OPTIONS, корректные 404/405, JSON validation и лимит тела запроса.
- Централизованные безопасные HTTP-ошибки, request ID, security headers.
- Runtime health checks, Prometheus metrics, CLI diagnostics и runtime OpenAPI lint.
- PHP development router, PHPUnit, PHPStan, standalone runtime tests, HTTP integration tests и CI PHP 8.4/8.5.

### Changed
- Версия из `release.json`, исправление вложенного ConfigRepository.
- Опубликованный OpenAPI описывает реализованный API; будущий API вынесен в `docs/openapi-planned.yaml`.
- Не реализованные команды завершаются с кодом 69 вместо фиктивного успеха.
- Примеры Nginx/Apache ограничивают выполнение PHP точкой входа; queue unit не перезапускается при коде 69.

### Scope
- PostgreSQL/installer, ingestion, авторизация, поиск, Admin UI, очередь и остальные интеграции относятся к следующим релизам. Health проверяет только runtime.
