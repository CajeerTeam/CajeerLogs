# CajeerLogs

Self-hosted PHP-first платформа централизованного логирования.

## 0.0.8 — Queue & Lifecycle

Рабочая durable PostgreSQL очередь ingest: `/api/v1/ingest/async`, status API, конкурентные workers с lease/retry/dead-letter, пакетный SQL INSERT, транзакционный webhook outbox. Scheduler с сохраняемым расписанием, opt-in retention по проектам и bounded cleanup. Синхронный ingest, четыре SDK, collector, webhooks и Admin Panel из предыдущих релизов сохранены. [Queue & Lifecycle](docs/Queue-Lifecycle.md). Для обновления 0.0.7: backup, наложение файлов, `php bin/cajeer migrate` (0007), запуск worker/scheduler. PHP 8.4+, PostgreSQL 17+; непрерывный worker требует pcntl. TOTP и инциденты ещё не реализованы.

### Быстрый запуск

Требуется PHP 8.4+ с PDO, `pdo_pgsql`, Argon2id, JSON, ctype, filter и PCRE; PostgreSQL 17+. Для установки создайте отдельную базу и непривилегированную роль PostgreSQL; подробности в [Database & Installer](docs/Database-Installer.md).

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
