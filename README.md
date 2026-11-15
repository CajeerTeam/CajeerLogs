# CajeerLogs

Self-hosted PHP-first платформа централизованного логирования.

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

`/health` — состояние PHP runtime. `/ready` — состояние runtime, соединения PostgreSQL, миграций и таблиц; при недоступной БД возвращает 503. Встроенный PHP-сервер предназначен только для разработки. Для production используйте PHP-FPM, Nginx/Apache, DocumentRoot `public/`, TLS и непривилегированного системного пользователя. Для локального HTTP задайте `APP_ENV=local` и `APP_URL=http://127.0.0.1:8080` (origin должен совпадать с адресом браузера); для production `APP_ENV=production`, `APP_URL=https://ваш-домен` и HTTPS server variable. Настройте `queue:work` и `scheduler:run` по [инструкции](docs/Queue-Lifecycle.md).

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
