## 0.6.6 — aaPanel Integration Stable

- Per-site multi-stream aaPanel manager on the real Nginx HTTP/SQLite importer.
- Auto-discovery (explicit enrollment), local config and CLI controls, read-only site scans.
- Independent per-site checkpoint/retry, reliable rotation, fail-isolated operation,
  persistent JSON health/backlog metrics and systemd service.
- Real HTTP integration regression, CI and release packaging gates; no PostgreSQL migration.
- New sources must exist in CajeerLogs project before enabling ingestion.

## 0.6.5 — Nginx Log Import Stable

- Nginx file collector: durable incremental HTTP ingest with SQLite checkpoint, verified
  pending batch receipts, restart recovery and existing rename/reopen handling.
- New `nginx-json` parser for Nginx `log_format escape=json`: UTC timestamps,
  method/path/status/byte count and bounded request/upstream durations. URL query,
  referrer, user-agent, host and unrecognized fields are discarded.
- Opt-in `--copytruncate`: archived copy must match acknowledged prefix, be stable,
  unique and uncompressed before recovery; otherwise fail closed. Concurrent writes
  during copytruncate may still be lost by logrotate, so rename+reopen is preferred.
- Real loopback HTTP/HMAC/SQLite/restart/rotation/ambiguous archive integration test,
  CI runbook, version synchronization and upgrade compatibility from 0.6.4.
- No PostgreSQL migration; certification requires complete CI and target host test.

## 0.6.4 — Kotlin Client Stable

- Kotlin 2.0 / JVM 17+ bounded background delivery queue with nonblocking submit, immediate queue-full errors, batching, timer/flush, graceful drain, confirmed futures and event accounting.
- Real Java `CompletableFuture`/`AutoCloseable` interoperability and `java.util.logging` handler, with bounded context/trace serialization and explicit failures.
- Retains existing HTTP/HMAC, validated receipts and original request/key replay; uncertain batches expose safe manual recovery, no false crash-durability claims.
- Gradle Maven publication and loopback HTTP integration tests for HMAC, 503, lost ACK, 401, overloads, concurrent producers, backpressure and Java/JUL.
- Upgrades and restore compatibility from 0.6.3, no PostgreSQL migration.

## 0.6.3 — TypeScript SDK Stable

- Node.js SDK: disk-backed bounded batching (`DurableBatchDelivery`), fsync/atomic persisted bodies, idempotent crash recovery, credential-scoped spool, 23h default fail-closed replay window.
- Retry/HMAC: immutable JSON body and idempotency key, fresh signature nonce, optional abort signals; expanded real HTTP and multiprocess tests.
- npm: package version 0.6.3, type declarations, Node.js 20+ ESM distribution with a reproducible build and package tests.
- Server ingest API/schema unchanged; no PostgreSQL migrations.

## 0.6.2 — Python SDK Stable

- Реальный durable synchronous Python batching на приватном SQLite spool: атомарная запись каждого события, фиксация неизменяемого HTTP batch и ключа до сети, bounded capacity, replay/restart recovery, защита от устаревших неоднозначных попыток.
- Stdlib `logging` интеграция с background flusher, конфигурируемым fail-on-enqueue, сохранением `extra`, formatter/traceback, счётчиками неудачных enqueue и HTTP доставок.
- CLI `cajeer-logs-spool status|flush`, изоляция endpoint/signing identity, межпроцессный lock; прежние синхронные и async API сохранены.
- Собираемые wheel/sdist и проверка установки пакета, Python 3.10–3.13 CI, реальный loopback HTTP/HMAC/401/503/lost ACK/multiprocess тест, обновлённая документация и upgrade compatibility.
- PostgreSQL migrations отсутствуют; production certification требует фактического полного CI/PostgreSQL прогона.

## 0.6.1 — PHP / Monolog SDK Stable

- Production PHP SDK: durable file spool with atomic, fsynced event writes and immutable batch envelopes; persistent idempotency keys, validated HTTP receipts, restart/replay recovery, limits on batch events/bytes and queue capacity.
- Opt-in PSR-3 and Monolog 3 delivery integration, explicit flush/close, optional fail-open-to-spool mode with observable failure, PSR-3 message interpolation, safe context normalization, Monolog recursion protection; existing synchronous APIs unchanged.
- Real loopback HTTP/HMAC fault tests, network outage, lost response, concurrent producers, byte boundary, security constraints and capacity checks; CI requires actual Monolog dependencies for SDK integration.
- Version synchronization across runtime and client packages; backward-compatible 0.6.0 backup/upgrade support, release and certification updates. No PostgreSQL migration.
- **Certification status:** release gate exists; external Docker/PostgreSQL and sustained production load require CI execution. See [runbook](docs/PHP-Monolog-SDK-Stable.md).

## 0.6.0 — High-Throughput Ingestion & Queue Processing Stable

- Adaptive PostgreSQL microbatch claim: job count, validation version, JSONB payload bytes and 100-event/4-MiB SQL limits are checked **before** leasing additional jobs. Skip incompatible entries without locking their leases until a future dispatch.
- One ordered SQL row-lock/payload/lease read per group; one transactional SQL statement closes all `job_attempts`; individual receipts and webhook outbox publications remain fully atomic.
- 4 compatible jobs per worker iteration is now the default, with `QUEUE_WORKER_BATCH_JOBS=1` as a safe operational rollback. HTTP contracts and PostgreSQL schema remain unchanged.
- Live production-style Docker/PostgreSQL/TLS smoke upgraded to 100% async high-batch ingest, fault and end-to-end persistence verification; final CI release certification requires an additional genuine long-duration load run and an exact 0.6.0 distribution.
- Backup/upgrade continuity from 0.5.10, CI release and multi-version checks, replay and queue-accounting assertions.
- **Certification not implied by shipping code**: evidence must come from actual production-style DB/TLS/load runs on the deployed environment.

## 0.5.10 — Load Testing & Performance Certification

- Новая программа нагрузочного тестирования live HTTP/CajeerLogs: sustained, трехступенчатый ramp stress, soak и fault-injection reverse proxy (HTTP 429/503, задержка и потеря ACK после принятой записи).
- Проверка целостности каждого теста через API поиска (строгое совпадение множества ID), завершение реальных async jobs, повторная доставка с теми же байтами и idempotency key, новым HMAC nonce, обнаружение дубликатов.
- Отчёт с p50/p95/p99, RPS, error rate, отклонениями от целевой подачи, фактическими inject-счётчиками и независимыми SLO gates; атомарная запись JSON с exit code 1 при неуспехе.
- Профиль длительной сертификации только для отдельного стенда (минимальные длительности 60/120/900/60 секунд), запрет фиктивного `certified=true` для коротких прогонов, привязка к SHA256 верифицированного релиза и проверке live `/ready`.
- Автоматизированные тесты генератора и негативных сценариев в CI; реальные короткие профили на production-style Docker/PostgreSQL/TLS стенде в release smoke.
- Поддержка восстановления бэкапов 0.5.9 в 0.5.10; неизменная схема PostgreSQL (001–0025).

## 0.5.9 — Queue Operations & Observability

- Транзакционный pause/resume dispatch с PostgreSQL fencing всех конкурентных claims; bounded drain CLI.
- Сессионные admin endpoints, CSRF/recent authentication, аудит изменений состояния.
- Точные PostgreSQL counters и histogram времени выполнения, независимые от PHP worker и не создающие project-ID Prometheus labels.
- Queue Center в System Report/Admin UI, дополнительные Grafana панели и alert rules.
- Миграция 0025, upgrade с 0.5.8 и тесты конкурентных claims. Сохраняется public-preview до полной CI сертификации.

## 0.5.8 — Delivery Guarantees & SDK Recovery

- Real async admission (HTTP 202) and durable job completion handling in Python, TypeScript, PHP and Kotlin SDKs. SDKs re-send identical bytes and idempotency key after ambiguous POST outcomes, generate fresh HMAC nonces, validate job IDs and completed event IDs, never treat 202 as an INSERT acknowledgement.
- Bounded authenticated GET status polling, explicit pending/dead/cancelled outcomes, no automatic duplicate enqueue on failed/unknown jobs, and constrained response bodies/redirects.
- Python collector opt-in `--async` SQLite job ID checkpoint: commits source offsets only after `completed`; restart resumes status polling without re-enqueue. Separate bounded, lock-protected, 0600 SQLite application outbox (`DurableAsyncSpool`) persists bytes/key before network and records confirmed event IDs.
- Actual HTTP wire tests for all four SDKs (lost response after admission, 503, replay, malformed completion, terminal states), process restart tests for collector and spool; historical PostgreSQL migrations remain unchanged.

## 0.5.7 — Multi-Project Queue Fairness

- Exact per-project queue accounting (jobs, events, bytes, pending and running) maintained by transactional PostgreSQL triggers; migration 0024 backfills existing durable jobs without rewriting payloads or leases.
- Weighted virtual-finish dispatch shared by independent workers; per-project in-flight ceiling with SKIP LOCKED and fair recovery of expired leases.
- Transactional project-specific admission limits, project policy operator CLI with audit and explicit maintenance reconciliation.
- PostgreSQL project/ready/lease indexes, operational metrics and database integrity verification; historical migration checksums preserved.

# 0.5.6 — Backpressure & Admission Control

- Точные PostgreSQL admission limits по активным jobs/events/JSONB bytes; многомерные budgets проверяются перед квотами проекта без сканирования jobs на каждом enqueue.
- Durable hysteresis latch (high/low watermarks), 503 `queue_full`, Retry-After и транзакционные счётчики отказов без потери уже принятых заданий.
- Защита idempotency replay при заполненной очереди и атомарность accounting по INSERT/completion/dead-letter/discard.
- Миграция 0023, операторская диагностика, CLI и Prometheus, новые конкурентные тесты и upgrade с 0.5.5.

# 0.5.5 — High-Throughput Batch Ingestion

- Транзакционное объединение совместимых pending jobs в ограниченные группы, один SQL INSERT событий на группу, отдельные webhook outbox записи и durable results для каждого исходного job.
- Graceful fallback к индивидуальной обработке после rollback неудачной группы; соединение с неопределённым COMMIT повторно не используется.
- Защищённая PostgreSQL-проверка project/token/source scope для каждой группы, валидация всех событий и redaction policies сохранены.
- Сокращена повторная нормализация событий на hot path, scope источников авторизуется один раз для уникальной пары, без ослабления all-or-nothing SQL guard.
- Новые unit/process/database тесты, реальный DB upgrade gate 0.5.4 → 0.5.5, без миграций.

# Changelog

Формат основан на [Keep a Changelog](http://keepachangelog.com/ru/1.0.0/), версии следуют [Semantic Versioning](https://semver.org/lang/ru/).

## 0.5.4 — Dead-Letter Management

- Project-scoped metadata-only dead-letter catalogue, filters, keyset pagination and attempt history.
- Atomic confirmed retry/discard selections with optimistic attempt checks, granular RBAC, recent authentication and hash-chain audit.
- Admin dead-letter workspace, audited CLI actions and DLQ gauges/alerts.
- No schema migration; existing durable payloads, receipts and attempts remain compatible.

## 0.5.3 — Retry & Failure Recovery

- Validated retry policies, persisted attempt budgets for new jobs, bounded exponential delays with jitter and manual retry budget.
- Safe SQLSTATE classification, infrastructure backoff with recovery readiness check, delayed lease recovery and fencing; uncertain commits remain non-replayable.
- Retry policy diagnostics, actual PostgreSQL failure scenarios and upgrade from 0.5.2. No schema migration; existing jobs retain their attempt limits.

## 0.5.2 — Worker Runtime & Concurrency

- Supervised independent PHP queue processes, bounded restart backoff and graceful drain with forced termination deadline.
- Job/time/memory recycling, adaptive idle polling, configurable lease and transaction timeouts; durable recovery semantics preserved.
- Per-process heartbeat registry, CLI/metrics/System Report diagnostics and production pool deployment.
- No new migrations. Actual 0.5.1 upgrade and process lifecycle tests in release gate.

## 0.5.1 — PostgreSQL Queue Hardening

Transactional queue capacity accounting, generated payload metadata, indexed claim paths,
operator verification/reconciliation, and migration of existing durable jobs. See
[PostgreSQL Queue Hardening](docs/PostgreSQL-Queue-Hardening.md). PostgreSQL certification
remains required before production promotion.

## 0.5.0 — Security & Operations Stable candidate

- Production metrics fail closed without credentials; runtime dotenv descriptor/ownership checks and safer Compose session defaults.
- Catalog security-attribute drift detection, required RBAC/saved-view grants and function EXECUTE readiness checks; no new migration.
- Read-only `operations:check [BACKUP_ID]` combines actual readiness, audit, worker, retention, storage, backup and offsite verification.
- Full production acceptance adds reauthentication, RBAC delegation denial, export rate, retention and recovery from database outage.
- Exact archive gate includes actual 0.4.10 upgrade and fixes already-applied 0021 expectations; channel remains public-preview pending full CI.

## 0.4.10 — Health Checks & Prometheus

- Separate bounded readiness/scrape PostgreSQL sessions; no-cache health responses.
- Worker progress heartbeats, CLI health probes, backup freshness and explicit never-run scheduler metrics.
- Optional authenticated HTTPS Prometheus, Blackbox Exporter, Alertmanager and Grafana stack; tested configuration and alert rules.
- No schema changes; 0.4.9 backup/upgrade compatibility; public-preview until CI certification.

## 0.4.9 — Retention & Storage Lifecycle
- Bounded scoped read-only preview, atomic batch history, cumulative deletion counter and configurable retention cycle.
- Configurable terminal webhook retention and queue floor without shortening accepted delivery guarantees.
- Retention diagnostics and Prometheus gauges; migration 0021 and upgrade from 0.4.8.

## 0.4.8 — Backup & Disaster Recovery
- Bounded PostgreSQL client processes, disk-space preflight and durable local publication.
- Encrypted authenticated streaming offsite copies, verified import, scheduled backup with retry and history, explicit safe pruning and restore rehearsal.
- Exact archive certification includes backup tests and upgrade from 0.4.7; no database migration.

## 0.4.7 — Secure Installer
- Private file and lock validation, atomic durable configuration publishing, file-backed runtime and migration secrets.
- Read-only installation dry run, verified backup and schema upgrade preflight, existing encryption key validation.
- Installer regression and PostgreSQL upgrade 0.4.6 certification; no new migrations.

## 0.4.6 — HTTPS & Deployment Security
- Trusted last-hop IPv4/IPv6 proxies with strict overwritten client address and scheme, production Host and HTTPS enforcement before body parsing.
- Nginx public origin allowlist, hardened headers, verified certificate CLI and optional enforced PostgreSQL verify-full.
- Live TLS tests and 0.4.5 upgrade certification; no new migration.

## 0.4.5 — Rate Limiting & Abuse Protection
- Shared PostgreSQL token buckets across API routes, per-operation budgets and configurable burst.
- Precise request Retry-After, gateway admission limits, identity metrics and Admin diagnostics.
- Migration 0020, bounded cleanup, concurrent PostgreSQL tests and 0.4.4 upgrade gate.

## 0.4.4 — Audit Log & Accountability

- Append-only audit chain with explicit project scope, actor type, result, indexed filters and bounded NDJSON export.
- Audit authenticated authorization denials, log exports, audit exports and CLI retention policy changes.
- Audit verification CLI with optional independently retained checkpoint; restore verifies chain before publication.
- Migration 0019 preserves historical records; real PostgreSQL audit integration and upgrade 0.4.3 are required by release gate.

## 0.4.3 — Identity & 2FA Security

- Role and permission based mandatory TOTP, enrollment-only access, idle timeout and recent authentication.
- Independent versioned TOTP key rotation; bounded CLI batches and verification. Shared ingest keys unchanged.
- Password changes require a fresh factor when enrolled; recovery regeneration revokes other sessions.
- Migration 0018; real PostgreSQL security tests and upgrade gate from 0.4.2.

## [0.4.2] — RBAC & Authorization

- Custom global and project restriction roles with immutable built-in ceilings, PostgreSQL migration 0017.
- Effective grants, server-side per-operation enforcement, role editor and assignment, revision checks and audit.
- Independent log export and credential delegation checks; existing machine-token scopes preserved.
- Real PostgreSQL RBAC integration tests and upgrade from 0.4.1 required by release gate.

## [0.4.1] — Admin Panel Release

- Глобальный поиск проектов с серверной пагинацией и переходом в рабочие разделы.
- Согласованная перепроверка проектного доступа, отмена устаревших запросов и защита от поздних ответов.
- Независимые проверки runtime, readiness и системного отчёта.
- Регрессии интерфейса и обязательное обновление с 0.4.0 в release gate.

## [0.4.0] — Admin Panel Release

- Самостоятельный Security & Audit workspace с проектным и глобальным журналом, переходами к credentials, пользователям и сессиям.
- Единый выбор проекта, сброс проектных фильтров/курсоров, перепроверка эффективных ролей при навигации и проверке сессии; закрытие устаревших диалогов.
- Browser E2E на точном ZIP (Chromium/Firefox/WebKit), проверка реального TOTP/recovery и изменения membership, обязательное admin evidence в release gate; upgrade 0.3.9.
- Обновлена документация установки, эксплуатации, ролей и проверки релиза; без новых миграций. Public Preview до полного CI.

## [0.3.9] — Token Management & Security

- Серверные фильтры, карточки credentials, цепочка ротации и статистика сервисных аккаунтов.
- Досрочный отзыв предыдущего HMAC-ключа с проверкой обоих UUID, проектный и глобальный журнал безопасности.
- Без миграций; Public Preview до CI.

## [0.3.8] — Project & Source Management

- Каталог проектов и источников: поиск по UUID, фильтр типа, пагинация, состояние поступлений и счётчики.
- Мастер создания источника, выдачи scoped logs:write, HMAC v1/v2, подписанного ingest и проверки сохранения через detail API.
- Действующие политики, статистика, переход к логам источника, тесты и совместимость обновления с 0.3.7.
- Без новых миграций; Public Preview до полной CI-сертификации.

## [0.3.7] — User & Access Management

- Серверный каталог пользователей, карточка, проектный доступ и история изменений.
- Управление членством через поиск пользователей и проектов; защита от устаревшего редактирования.
- Административный сброс 2FA с повторной аутентификацией и отзывом сессий.
- API v1 и схема 0001–0016 сохранены.

## [0.3.6] — Event Details & JSON Viewer

- Инспектор: обзор, поля, JSON-дерево, исходный JSON, поиск и связанные события.
- Сохранение числовых литералов ответа API, ленивое отображение, копирование, скачивание и фильтрация по JSON-пути.
- Существующий API v1, signed cursor, RBAC, redaction и detail budget без изменений. Без миграций.

## [0.3.5] — Advanced Filters & Search

- Visual filter lists, source registry lookup, JSON path builder, presets and removable active filters.
- Private PostgreSQL saved views with validated filters, table layout, revision checking and audit.
- Additive migration 0016; previous migrations and search/cursor/export contracts preserved.
- Release gate includes upgrade from 0.3.4. Public Preview until CI passes.

## 0.3.4 — Log Explorer & Table

- Настраиваемые колонки, ширина, порядок, плотность, перенос текста и часовой пояс; безопасная подсветка и копирование полей.
- Навигация по событиям и страницам, восстановление позиции, обновление текущего курсора, ручная/автоматическая проверка первой страницы без скачка.
- Сохранены snapshot/live, RBAC, page_digest и If-Match; без новых миграций. Добавлены frontend и upgrade-тесты.

## 0.3.3 — Dashboard & Analytics

- PostgreSQL: UTC интервалы minute/5min/hour/day, errors в bucket, точное количество групп источников, топ сервисов и хостов.
- Dashboard: периоды до 31 дня, сравнение, метрики, интерактивные графики с переходами в Explorer, обновление по таймеру, отдельные ошибки загрузки.
- Состояние источников с пагинацией, поиском, статусами и порогом давности последнего приёма.
- Исправлено исключение production ES Modules из Git; восстановлены отсутствующие модули. Обновление без миграций, API v1 сохранён.

## 0.3.2 — Authentication & Sessions

- Двухэтапный вход, пароль и TOTP/recovery, локальный QR-код и безопасная замена recovery-кодов.
- PostgreSQL 0015: публичные UUID сессий, метаданные подключения, профиль и время последнего входа; сохранение старых сессий.
- Список сессий, индивидуальный отзыв, отзыв остальных, администраторское управление, аудит и синхронизация вкладок.
- Реальные API, обновлённые браузерные и PostgreSQL-тесты, OpenAPI и совместимость backup. Public Preview до полного CI.

## 0.3.1 — Admin UI Foundation

- Production SPA разбита на ES Modules: семь действующих страниц, состояние, маршруты, сессии, API-клиент и компоненты.
- Единый same-origin клиент с CSRF, request ID, отменой запросов при смене маршрута/сессии и отдельным Bearer-режимом проверки источника.
- Дизайн-токены, системная/светлая/тёмная темы, адаптивное меню, breadcrumbs, фокус, индикаторы загрузки и клавиатурный доступ к таблицам.
- Проверка всех транзитивных assets, тесты API-клиента и браузерные регрессии. Схема PostgreSQL, API v1 и SDK wire-контракты без изменений.
- Public Preview до полного CI.

## [0.3.0] — Log Ingestion & Search Release

- Согласованное чтение исторических JSONB-чисел в list/detail/export, точный page digest, 4 МиБ detail budget и завершение search budget после сериализации.
- Benchmark поддерживает HMAC v2, snapshot, обратную навигацию и условный экспорт; Python SDK принимает доверенный CA.
- Сквозной HTTP/PostgreSQL pipeline с SDK, политиками, ротацией, async jobs, snapshot и экспортом; обязательный в release gate, также запускается через production TLS.
- Обновления с 0.1.0, 0.2.0 и 0.2.9, совместимость backup, миграции 0001–0014 неизменны. Public Preview до фактической сертификации.

## [0.2.9] — Pagination & Log Explorer

- Подписанные курсоры v2 (HMAC-SHA256, область доступа, фильтры, направление, TTL); старые HTTP cursors требуют нового поиска.
- Двусторонний keyset и ограниченный snapshot по received_at с проектным fence для штатных INSERT.
- Бюджет 2 МиБ данных страницы, continuation для CSV/JSON и If-Match для проверки совпадения страницы; bounded JSONL CLI stream.
- Log Explorer с серверными курсорами, восстановлением позиции в памяти сессии, новым поиском и проверкой экспорта.
- Миграции 0001–0014 неизменны; Public Preview до PostgreSQL release gate.

## [0.2.8] — Log Search

- Составные списочные фильтры, три режима FTS, точные JSONB-пути и теги all/any; общие predicates для list/stats/export.
- Строгий разбор query string, ограничение сложности и read-only SQL timeout, отдельные Prometheus search metrics.
- Admin Panel с расширенными фильтрами; `db:explain --filters` использует фактический SQL runtime.
- Миграции 0001–0014 неизменны; Public Preview до PostgreSQL release gate.

## [0.2.7] — Idempotency & Delivery Guarantees

- Единый транзакционный поиск sync receipts и async jobs в пределах цепочки ротации credential; точные байты тела и запрет смены режима.
- Результаты новых доставок доступны с действующим credential той же семьи; legacy hashes проверяются только исходным credential.
- Настраиваемое окно 1–30 дней (7 по умолчанию), фиксируется при приёме; pending/running/dead jobs сохраняются.
- Повтор до новой event validation, проверка целостности цепочек и UUID, SDK resume и wire fault-injection.
- Миграция 0014, неизменные 0001–0013; Public Preview до PostgreSQL release gate.

## [0.2.6] — Redaction & Data Privacy

- Аддитивные политики системы, проекта и источника, revision и аудит.
- Маскирование JSON paths, имён ключей и bounded regex, JWT/PEM/DSN; защита перед enqueue/INSERT и при обработке worker.
- Административные API/preview и редактор, CLI dry-run/rewrite истории.
- Миграции 0001–0013 неизменны; PostgreSQL integration suite обязателен для сертификации.

## [0.2.5] — HMAC & Replay Protection

- HMAC v1 сохранён; v2 подписывает UUID отдельного ключа и точное тело запроса.
- Обязательная подпись проекта или credential, зашифрованные ключи и ротация с одним предыдущим ключом.
- Транзакционная проверка политики, DB clock, общий nonce namespace и запрет downgrade.
- Admin Panel, SDK и JSONL collector поддерживают v2; миграция 0013, предыдущие неизменны.
- Public Preview до фактического PostgreSQL release gate.

## [0.2.4] — Ingest Authorization

- Единая авторизация sync/async и polling, общий IP/token request budget; доверие только REMOTE_ADDR.
- Повторная проверка credential под транзакционной блокировкой и перед подтверждением, без повторного списания request quota.
- Строгая форма scopes в БД и административном API, SQL-защита записи и enqueue, явная проектная область job status.
- Метрики отказов polling и временного изменения политики. Миграции 0001–0012 неизменны; Public Preview до фактического release gate.

## [0.2.3] — Event Validation

- Строгий bounded JSON reader, запрет повторных ключей, контроль исходного и нормализованного размера, Unicode, структуры и чисел.
- Ошибки валидации с безопасными reason, field и event_index.
- Настраиваемые окна timestamp только для новых операций; replay и ранее принятые jobs не переоцениваются.
- Версионирование durable payload, совместимость прежних заданий; клиентские проверки и строгий JSONL-импорт.
- Миграции 0001–0012 неизменны; Public Preview до фактической сертификации PostgreSQL.

## [0.2.2] — Single & Batch Ingestion

- Общая нормализация пакета, точная карта источников, отдельные лимиты SQL и durable payload, ограничение JSONB при записи и чтении.
- Async replay не проходит admission повторно; подготовка durable payload до захвата источников и глобальной блокировки очереди.
- Пакетные упорядоченные блокировки и UPDATE счётчиков источников; сохранены атомарные quota и rollback.
- Строгая проверка durable payload, немедленный dead-letter некорректных заданий, проверка lease после блокировки, атомарность истории попытки.
- Регрессионные и PostgreSQL fault-injection тесты; миграции 0001–0012 неизменны. Public Preview до фактического release gate.

## [0.2.1] — Ingest Runtime

- Единый IngestService для sync/async; сохранены транзакции, HMAC, receipts и очередь.
- Строгая проверка framing и JSON media type, лимит тела до чтения, локальный admission и кооперативный SQL-бюджет.
- Retry-After для временной недоступности, bounded Prometheus counters и histogram.
- Версии API/SDK и CI обновлены; миграции 0001–0012 не изменены.

## [0.2.0] — Database Core Release

- Защита целевой БД восстановления сохраняемым PostgreSQL quarantine: runtime не запускается до проверенной публикации; отзыв credentials и снятие fence атомарны.
- `db:verify`: единая рабочая проверка установки, PostgreSQL readiness, администратора и логической целостности.
- Логическая проверка восстановленных данных и строгая история совместимых backup; проверка неверно маркированного архива и повреждённых receipts.
- Один проверенный ZIP для всей матрицы PHP 8.4/8.5 × PostgreSQL 17/18. Исполняемый release gate, отчёты с фактическими версиями, exit codes и SHA-256; полный upgrade с 0.1.0 и 0.1.9 и production smoke.
- Версия backend/API/SDK/Admin Panel 0.2.0; миграции 0001–0012 не изменены. Public preview до подтверждённого CI.

## [0.1.9] — Database Integrity & Performance

- Миграция 0012: проверяемые FK проекта токена и slug источника, состояния lease и завершения ingest jobs, индексы поиска и FK.
- `db:integrity` проверяет логические связи и JSON-результаты, поддерживает read-only preflight 0.1.8; legacy и удалённые retention события не считаются повреждением.
- Retention выбирает ограниченные наборы по индексам каждой политики, дедуплицирует кандидатов.
- `db:performance`, `db:explain`: реальные размеры, статистика PostgreSQL и планы запросов.
- HTTP benchmark с HMAC, параллельным ingest, повтором и сверкой UUID; PostgreSQL integration suite и production smoke с двумя workers.
- Сохранены миграции 0001–0011, поддерживается восстановление backup 0.1.8.

## [0.1.8] — API Token Management

- Ротация с новым UUID, ограниченным overlap и запретом расширения scopes.
- Редактирование безопасных метаданных с revision, немедленный и пакетный отзыв.
- Проектные сервисные учётные записи, ограничение запросов и throttled last_used_at.
- Admin Panel, OpenAPI, миграция 0011, регрессии и интеграционные тесты.

## [0.1.7] — Source Management

- SourceRepository: метаданные, типы, неизменяемый slug, revision и переходы active/disabled/archived; restore оставляет disabled.
- Реальные source admission, квоты и ограничения в sync/async ingest, атомарные counters, сохранение принятых jobs и idempotency.
- Индивидуальный retention ограничен сроком проекта; статистика, last seen и аудит.
- Admin Panel: управление источником, фильтрация, статистика, подписанный тестовый ingest.
- SQL 0010; 0001–0009 неизменны, совместимое восстановление старых backup с миграцией.

## [0.1.6] — Project Management

- Реальные операции PATCH, archive/restore, поиск, фильтрация и статистика проектов.
- Оптимистичная ревизия и аудит; архив блокирует новые sync/async admissions без удаления истории.
- Политики размера тела, пакета, событий в минуту и retention; роли проекта ограничивают глобальную роль.
- Admin Panel: редактирование, политики, состояние, поиск и статистика.
- SQL 0009; 0001–0008 неизменны. Совместимые старые backup восстанавливаются с применением 0009.

## [0.1.5] — Installer & Administrator

- Проверяемый bootstrap: PostgreSQL session lock, локальные locks, preflight, продолжение после частичных миграций и атомарный completion marker с audit.
- `install:prepare-schema --confirm` для выделенной пустой схемы с раздельными ролями; `install:status` без секретов.
- Защищённый password-file, отказ от неявной смены DB target/ключей, атомарная запись .env и проверка TOTP enrollments.
- `admin:reset-password EMAIL --confirm [--unblock]`: Argon2id, отзыв сессий, отмена pending TOTP и аудит; enrolled TOTP и API-токены сохраняются.
- Runtime doctor не требует DDL-прав и writable config; readiness проверяет права последовательности jobs.
- SQL 0001–0008 неизменны; backup 0.1.4 совместим.

## [0.1.4] — Log Repository

- Единый репозиторий записи, keyset-чтения, FTS, detail и statistics.
- Атомарный PostgreSQL INSERT с проверкой project/source/token и сверкой возвращённых UUID; ограничение размера нормализованного пакета.
- Согласованный потоковый JSONL-экспорт, возобновляемый импорт архива через коллектор, проверка и явное назначение legacy-событий с аудитом.
- SQL 0001–0008 неизменны; backup 0.1.3 совместим.

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
