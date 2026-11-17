# CajeerLogs

Self-hosted PHP-first платформа централизованного логирования.

## 0.5.5 — High-Throughput Batch Ingestion

Ограниченные транзакционные microbatches совместимых PostgreSQL jobs: один SQL INSERT событий, отдельные durable receipts/attempts/webhook-deliveries, откат группы с изоляцией неисправного job, защита от превышения 100 событий/4 МиБ, сокращение повторной нормализации и проверка scope один раз на уникальный источник. `QUEUE_WORKER_BATCH_JOBS=1..16` (по умолчанию 1 для обычной CLI-конфигурации, 4 для production Compose). [Документация](docs/High-Throughput-Batch-Ingestion.md). Миграции 0001–0022 неизменны; статус public-preview до полного PostgreSQL/Docker CI.

## 0.5.4 — Dead-Letter Management

Управление проблемными заданиями через `/admin/#queue`: проектная фильтрация, история попыток, атомарный retry/discard, RBAC, аудит и метрики DLQ. [Dead-Letter Management](docs/Dead-Letter-Management.md). Миграции 0001–0022 неизменны.

## 0.5.3 — Retry & Failure Recovery

Настраиваемый бюджет попыток, jitter, lease recovery и защита от неопределённого COMMIT. [Retry & Failure Recovery](docs/Retry-Failure-Recovery.md).

## 0.5.2 — Worker Runtime & Concurrency

Независимые PHP workers под управлением `queue:pool`, graceful drain, автоматическая замена завершившихся процессов, ограничения жизненного цикла, адаптивный polling, PostgreSQL transaction timeouts и индивидуальная диагностика. См. [Worker Operations](docs/Worker-Operations.md). Миграции 0001–0022 не изменены; новая миграция не требуется. Канал public-preview до прохождения полного CI.


## 0.5.1 — PostgreSQL Queue Hardening

Транзакционные счётчики jobs/events/bytes, индексированный claim, сверка ёмкости и миграция 0022. [PostgreSQL Queue Hardening](docs/PostgreSQL-Queue-Hardening.md).

## 0.5.0 — Security & Operations Stable (candidate)

Интеграция RBAC, 2FA, audit, rate limiting, HTTPS, Secure Installer, backup, retention и мониторинга. Защита production-конфигурации и метрик, проверка security-атрибутов схемы и `operations:check`. [Security & Operations Stable](docs/Security-Operations-Stable.md). Без новых миграций; Public Preview до полного CI.

## 0.4.10 — Health Checks & Prometheus

Readiness с отдельным PostgreSQL probe, heartbeat фоновых процессов, метрики backup, TLS/HTTP probes и опциональный monitoring stack. [Prometheus](docs/Prometheus.md).

## 0.4.9 — Retention & Storage Lifecycle

Read-only preview, ограниченные транзакции, история выполнения, Prometheus и политики служебных данных. Миграция 0021. [Retention & Storage Lifecycle](docs/Retention-Storage-Lifecycle.md). Public Preview до CI.

## 0.4.8 — Backup & Disaster Recovery

Плановые PostgreSQL backup, аутентифицированные шифрованные offsite-копии, импорт, проверяемое восстановление и безопасная ротация локальных архивов. Без новых миграций. [Backup & Disaster Recovery](docs/Backup-Disaster-Recovery.md). Public Preview до CI.

## 0.4.7 — Secure Installer

Защищённые файловые секреты, строгие права конфигурации, read-only preflight и проверка резервной копии перед обновлением. Без новых миграций. [Secure Installer](docs/Secure-Installer.md).

## 0.4.6 — HTTPS & Deployment Security

Проверка публичного Host, доверенные reverse proxy, TLS-проверка сертификата, усиление Nginx и опциональный обязательный TLS PostgreSQL. Без новых миграций. [Production Security](docs/Production-Security.md). Public Preview до CI.

## 0.4.5 — Rate Limiting & Abuse Protection

Общие PostgreSQL token buckets, независимые бюджеты поиска, аналитики, экспорта и административных изменений, настраиваемый burst, точный Retry-After, Nginx admission и диагностика лимитов. Миграция 0020. [Rate Limiting](docs/Rate-Limiting.md). Public Preview до CI.

## 0.4.4 — Audit Log & Accountability

Append-only audit, PostgreSQL hash chain, explicit scope and results, time filters, bounded NDJSON export and CLI verification. Migration 0019. [Audit Log & Accountability](docs/Audit-Log-Accountability.md). Public Preview до CI.

## 0.4.3 — Identity & 2FA Security

Политики обязательной 2FA, idle timeout, подтверждение личности и отдельная ротация TOTP-ключей: [Identity & 2FA Security](docs/Identity-2FA-Security.md).

## 0.4.2 — RBAC & Authorization (предыдущий релиз)

Настраиваемые глобальные и проектные роли, детальные разрешения, серверные назначения и проверка эффективного доступа, редактор ролей, аудит и защита от повышения полномочий. Миграция 0017. [Руководство RBAC](docs/RBAC.md). Public Preview до CI.

## 0.4.1 — Admin Panel Release

Глобальный поиск проектов, согласованная навигация и перепроверка доступа, независимые диагностические проверки. [Руководство](docs/Admin-Panel-Release.md).

## 0.4.0 — Admin Panel Release

Интегрированная административная панель: Dashboard, Log Explorer, Saved Views, JSON Inspector, проекты/источники, пользователи, токены, сервисные аккаунты, HMAC, отдельный раздел безопасности и аудит. Централизованная смена проекта и перепроверка доступа. Release gate для точного ZIP требует PostgreSQL-матрицу, браузерный E2E Chromium/Firefox/WebKit и production smoke. [Документация релиза](docs/Admin-Panel-Release.md). Без новых миграций; Public Preview до успешной сертификации CI.

## 0.3.9 — Token Management & Security

Каталог credentials, карточки токенов и сервисных аккаунтов, досрочное завершение HMAC overlap и журнал безопасности. [Документация](docs/Token-Management-Security.md). Без миграций. Public Preview до CI.

## 0.3.8 — Project & Source Management

Каталог проектов, фильтры и состояние источников, действующие политики, мастер подключения с токеном logs:write, HMAC и проверкой реального события. [Документация](docs/Project-Source-Management.md). Без новых миграций. Public Preview до CI.

## 0.3.7 — User & Access Management

Каталог пользователей с фильтрами, карточка аккаунта, эффективные права и членство в проектах, поиск участников, аудит и административный сброс 2FA. [Документация](docs/User-Access-Management.md). Без новых миграций. Public Preview до CI.

## 0.3.6 — Event Details & JSON Viewer

Инспектор событий, ленивое JSON-дерево, поиск по ключам и значениям, копирование путей и исходного JSON, связанные логи по Trace/Request ID. Без миграций. [Документация](docs/Event-Details-JSON-Viewer.md). Public Preview до CI.

## 0.3.5 — Advanced Filters & Search

Визуальный конструктор поиска, множественные значения, JSON-пути, активные фильтры, предустановки и личные сохранённые представления PostgreSQL. Миграция 0016. [Документация](docs/Advanced-Filters-Search.md). Public Preview до CI.

## 0.3.4 — Log Explorer & Table

Настраиваемая таблица логов, порядок и ширина колонок, UTC/локальное время, клавиатурная навигация, проверка новых событий без скачка страницы, подписанные курсоры и защищённый экспорт. Без миграций. [Log Explorer](docs/Log-Explorer.md). Public Preview до CI.

## 0.3.3 — Dashboard & Analytics

Рабочая аналитика PostgreSQL: периоды и сравнение, интерактивный график событий и ошибок, топ источников, сервисов и хостов, состояние источников и переходы в Log Explorer. Без новой миграции. [Dashboard & Analytics](docs/Dashboard-Analytics.md). Public Preview до CI.

## 0.3.2 — Authentication & Sessions

Двухэтапный вход, локальный QR для TOTP, одноразовые recovery-коды, редактируемый профиль, список и отзыв сессий пользователя и администратора. Миграция 0015 сохраняет действующие сессии. [Authentication & Sessions](docs/Authentication-Sessions.md). Public Preview до CI.

## 0.3.1 — Admin UI Foundation

Рабочая административная SPA на ES Modules: отдельные страницы, API-клиент, управление состоянием и сессиями, маршрутизация с RBAC, адаптивная дизайн-система с системной/светлой/тёмной темой. Без миграций и новых runtime-зависимостей. [Документация панели](docs/Admin-Panel.md). Public Preview до CI.

## 0.3.0 — Log Ingestion & Search Release

Завершение цикла 0.2.x: production pipeline ingest → PostgreSQL → search, безопасное чтение исторических JSONB, ограничение detail, HMAC v2 benchmark с проверкой snapshot и экспорта, сквозной release gate и обновление 0.1.0/0.2.0/0.2.9. Public Preview до фактического CI. См. [Log Ingestion & Search Release](docs/Log-Ingestion-Search-Release.md).

## 0.2.9 — Pagination & Log Explorer

Подписанные курсоры v2 с привязкой к области доступа, двусторонний keyset, ограниченный snapshot по received_at, байтовый бюджет страниц, проверяемый экспорт и восстановление позиции Log Explorer. Без новой миграции. См. [Pagination & Log Explorer](docs/Pagination-Log-Explorer.md).

## 0.2.8 — Log Search

Расширенные фильтры PostgreSQL FTS/JSONB, списки источников, уровней, сервисов, trace/request ID и тегов; точные JSON-пути, read-only SQL-бюджет, метрики и EXPLAIN для реальных фильтров. Без новой миграции. См. [Log Search](docs/Log-Search.md).

## 0.2.7 — Idempotency & Delivery Guarantees

Единая область доставки для цепочки ротации credential, согласованные sync/async ключи, настраиваемое окно хранения, повтор после потери ответа и строгие SDK receipts. Миграция 0014. См. [Idempotency & Delivery Guarantees](docs/Idempotency-Delivery-Guarantees.md).

## 0.2.6 — Redaction & Data Privacy

Настраиваемое аддитивное маскирование системы, проекта и источника; revision, preview и аудит; применение до durable enqueue и INSERT; обработка истории через CLI. См. [Redaction & Data Privacy](docs/Redaction-Data-Privacy.md).

## 0.2.5 — HMAC & Replay Protection

Обязательные политики подписи, отдельные зашифрованные signing keys, ротация с overlap, проверка nonce под блокировками PostgreSQL и HMAC v1/v2 во всех SDK. Миграция 0013. [Инструкция](docs/HMAC-signature.md).

## 0.2.4 — Ingest Authorization

Единый проверенный ingest principal, общие лимиты sync/async/polling, повторная авторизация под блокировкой токена и перед подтверждением, строгие scopes и SQL-проверка доступа очереди. [Инструкция](docs/Ingest-Authorization.md).

## 0.2.3 — Event Validation

Строгий JSON без повторных ключей, ограниченная структура, сохранение точности чисел context, timestamp admission policies, структурированные ошибки, версионированные durable jobs и клиентская валидация. [Инструкция](docs/Event-Validation.md).

## 0.2.2 — Single & Batch Ingestion

Единая нормализация и ограничение пакетов, проверка durable payload, пакетные блокировки и счётчики источников, безопасная обработка lease и dead-letter. [Инструкция](docs/Single-Batch-Ingestion.md).

## 0.2.1 — Ingest Runtime

Единый ingest-сервис, строгий HTTP envelope, ограничение параллельного приёма, бюджет SQL, безопасные 503 и локальные Prometheus-метрики. [Инструкция](docs/Ingest-Runtime.md).

## 0.2.0 — Database Core Release

Завершение цикла PostgreSQL-ядра: защищённая публикация восстановленной БД с логическим аудитом, проверка совместимости backup и обязательная матрица интеграции на одном ZIP (PHP 8.4/8.5 × PostgreSQL 17/18, обновления 0.1.0 и 0.1.9, production smoke). Канал public-preview до фактического прохождения release gate. [Инструкция](docs/Database-Core-Release.md).

## 0.1.9 — Database Integrity & Performance

Миграция 0012 проверяет связи логов, источников, токенов и очереди; добавлены индексируемый retention, логический аудит данных, диагностика PostgreSQL и конкурентный HTTP benchmark со сверкой UUID. [Инструкция](docs/Database-Integrity-Performance.md).

## 0.1.8 — API Token Management

Ротация API-токенов с ограниченным перекрытием, отзыв, сервисные учётные записи, лимиты запросов, неизменяемая область доступа и управление в Admin Panel. Миграция 0011. [Инструкция](docs/API-Token-Management.md).

## 0.1.7 — Source Management

Полный жизненный цикл источников: редактирование, типы и метаданные, отключение, архивирование и восстановление, индивидуальные ограничения и retention, last seen, статистика и Admin Panel с тестовым ingest. Новая миграция 0010. [Инструкция](docs/Source-Management.md).

## 0.1.6 — Project Management

Полный обратимый жизненный цикл проектов: редактирование с ревизиями, архивирование и восстановление, политики ingest/retention, ограничения ролей, статистика и Admin Panel. Новая миграция 0009. [Инструкция](docs/Project-Management.md).

## 0.1.5 — Installer & Administrator

Безопасная установка и повторный запуск после прерывания, подготовка выделенной схемы PostgreSQL с раздельными ролями, статус установки и CLI-восстановление пароля администратора с аудитом. [Инструкция](docs/Installer-Administrator.md).

## 0.1.4 — Log Repository

Единый PostgreSQL-репозиторий записи и чтения, атомарная проверка области доступа всего пакета, потоковый JSONL-экспорт, возобновляемый импорт архивов и явное назначение legacy-событий. [Инструкция](docs/Log-Repository.md).

## 0.1.3 — Transaction Management

Единый TransactionManager, savepoints, уровни изоляции, ограниченный retry конфликтов PostgreSQL и атомарные операции ingest, очереди и аудита. [Инструкция](docs/Transaction-Management.md).

## 0.1.2 — Migration Engine

Отдельные транзакции миграций, реальный контроль PostgreSQL catalog, read-only plan/verify, журнал попыток, безопасное восстановление concurrent indexes и batch backfill. При обновлении с 0.1.1 обязательно выполнить `php bin/cajeer migrate` даже при отсутствии pending SQL. [Инструкция](docs/Migration-Engine.md).

## 0.1.1 — PostgreSQL Runtime

Непостоянные PostgreSQL-соединения, ограниченный retry подключения, восстановление queue worker после разрыва, отдельная CLI-роль миграций, параметры сессии и метрики PostgreSQL. [Настройка и диагностика](docs/PostgreSQL-Runtime.md). SQL 0001–0008 не меняются.

## 0.1.0 — First Public Release

Первый публичный MVP: централизованный приём и поиск логов, Admin Panel, проекты, API-токены, TOTP, SDK PHP/Python/TypeScript/Kotlin, файловый коллектор, PostgreSQL-очередь, webhooks, retention, мониторинг и backup/restore. Рабочая production-конфигурация PHP-FPM/Nginx/PostgreSQL через Compose, проверяемая сборка полного релиза и обязательный CI release gate. Не LTS; дополнительные СУБД, поисковые движки, HA и инциденты остаются вне релиза.

**Установка, обновление с 0.0.9, сборка и эксплуатация:** [First Public Release](docs/First-Public-Release.md). Миграции 0001–0008 не меняются. Для локального запуска ниже используйте `APP_ENV=local`.

### Быстрый запуск

Требуется PHP 8.4+ с PDO, `pdo_pgsql`, sodium, Argon2id, JSON, ctype, filter и PCRE; PostgreSQL 17+. Для установки создайте отдельную базу и непривилегированную роль PostgreSQL; подробности в [Database & Installer](docs/Database-Installer.md).

```sh
cp .env.example .env
chmod 600 .env
# Укажите реальные DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD и APP_URL в .env.
php bin/cajeer install:check
php bin/cajeer install
php bin/cajeer migrate:status
php bin/cajeer db:doctor
php bin/cajeer doctor
```

Установщик спрашивает реквизиты и пароль администратора без отображения пароля. Для автоматизации доступны `--non-interactive` и переменные `CAJEER_ADMIN_EMAIL`, `CAJEER_ADMIN_PASSWORD`. Пароль администратора не сохраняется в `.env`.

```sh
php -S 127.0.0.1:8080 -t public public/router.php
# В другом терминале:
curl -i http://127.0.0.1:8080/api/v1/health
curl -i http://127.0.0.1:8080/ready
# Откройте http://127.0.0.1:8080/admin/
```

`/health` — состояние PHP runtime. `/ready` — состояние runtime, соединения PostgreSQL, миграций и таблиц; при недоступной БД возвращает 503. Встроенный PHP-сервер предназначен только для разработки. Для production используйте PHP-FPM, Nginx/Apache, DocumentRoot `public/`, TLS и непривилегированного системного пользователя. Для локального HTTP задайте `APP_ENV=local` и `APP_URL=http://127.0.0.1:8080` (origin должен совпадать с адресом браузера); для production `APP_ENV=production`, `APP_URL=https://ваш-домен` и HTTPS server variable. Настройте `queue:pool` и `scheduler:run` по [инструкции](docs/Queue-Lifecycle.md).

### Проверки

```sh
php tests/runtime.php
php tests/database_config.php
php tests/identity.php
php tests/ingest.php
php tests/search.php
php tests/queue.php
python3 tests/http_runtime.py
php bin/cajeer openapi:lint
# На отдельной тестовой БД с экспортированными DB_*:
CAJEER_TEST_DATABASE=1 php tests/database.php
CAJEER_TEST_DATABASE=1 php tests/identity_database.php
CAJEER_TEST_DATABASE=1 php tests/ingest_database.php
CAJEER_TEST_DATABASE=1 php tests/search_database.php
CAJEER_TEST_DATABASE=1 php tests/queue_database.php
composer install
composer test
composer analyse
```

См. [Database & Installer](docs/Database-Installer.md), [Core Runtime](docs/Core-Runtime.md), [Nginx](docs/Nginx-deployment.md), [Apache](docs/Apache-optional.md) и [реализованный OpenAPI](api/openapi.yaml). Планируемый API находится отдельно в `docs/openapi-planned.yaml`.


**0.5.4:** [Dead-Letter Management](docs/Dead-Letter-Management.md) — project-scoped queue inspection, atomic retry/discard and audited operations.

**0.5.3:** [Retry & Failure Recovery](docs/Retry-Failure-Recovery.md) — configurable durable attempt budgets, jitter, lease recovery and outage handling.
