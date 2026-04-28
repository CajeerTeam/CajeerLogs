# Cajeer Logs

Текущая версия: `0.8.2-ops-hardening`.

Cajeer Logs — открытый PHP-сервис для централизованного приёма, хранения и анализа журналов приложений, ботов, сайтов и инфраструктурных компонентов.

Проект рассчитан на самостоятельное развёртывание: без обязательного Composer, без Laravel, без Node.js и без внешнего сервиса наблюдаемости в runtime.


## Возможности

- HTTP API для приёма событий от приложений и ботов.
- Python-клиент `clients/bot.py` с пачками, повторной отправкой, локальной очередью и HMAC-подписью.
- PostgreSQL для production-эксплуатации.
- SQLite fallback для локальной проверки и demo-окружений.
- Web-интерфейс для журналов, токенов, представлений, инцидентов, оповещений, аудита и системных проверок.
- Импорт access/error журналов Nginx из локального каталога сервера.
- Retention-политики с опциональной архивацией в NDJSON.gz перед удалением.
- Центр обновлений через git с предварительными проверками, ограничением репозитория, резервной копией и откатом.
- RBAC, аудит действий, CSRF-защита, rate limit ingest API и редактирование секретов в событиях.

## Требования

- PHP 8.2 или новее.
- Расширение `pdo`.
- `pdo_pgsql` для PostgreSQL.
- `pdo_sqlite` только для локального fallback-режима.
- Nginx или Apache с document root на каталог `public/`.
- `git` и `tar`, если используется встроенный центр обновлений.

## Структура репозитория

```text
CajeerLogs/
├─ app/             # PHP-классы приложения
├─ app/Internal/    # SQL snapshot схемы PostgreSQL
├─ bin/             # CLI-команды обслуживания
├─ clients/         # Клиенты удалённой отправки журналов
├─ public/          # Web root
├─ storage/         # Runtime-каталоги; содержимое игнорируется Git
├─ wiki/            # Исходники страниц GitHub Wiki
├─ openapi.yaml     # Машиночитаемый контракт ingest API
├─ .env.example     # Безопасный шаблон конфигурации
├─ README.md
├─ LICENSE
└─ VERSION
```

## Быстрый старт

```bash
git clone https://github.com/CajeerTeam/CajeerLogs.git
cd CajeerLogs
cp .env.example .env
```

Минимально настройте `.env`:

```env
APP_URL=https://logs.example.com
DB_CONNECTION=pgsql
DB_DATABASE=cajeer_logs
DB_USERNAME=cajeer_logs
DB_PASSWORD=change_me
LOGS_TOKEN_PEPPER=<64 hex chars>
PRIVACY_HASH_PEPPER=<64 hex chars>
DOCS_URL=https://github.com/CajeerTeam/CajeerLogs/wiki
```

Сгенерировать pepper-значения можно так:

```bash
openssl rand -hex 32
```

Подготовьте каталоги, примените миграции и проверьте окружение:

```bash
php bin/bootstrap.php
php bin/migrate.php
php bin/doctor.php
php bin/self-test.php
php bin/wiki-check.php
php bin/schema-check.php
php bin/release-check.php
```

Создайте первого администратора:

```bash
php bin/make-user.php admin 'CHANGE_ME_STRONG_PASSWORD' admin
```

Document root web-сервера должен указывать на:

```text
/path/to/CajeerLogs/public
```

## Плановые задачи

Рекомендуемый набор cron/systemd timer задач:

```bash
cd /path/to/CajeerLogs && php bin/process-jobs.php >/dev/null 2>&1
cd /path/to/CajeerLogs && php bin/alert-dispatch.php >/dev/null 2>&1
cd /path/to/CajeerLogs && php bin/import-aapanel-logs.php --max-lines=1000 >/dev/null 2>&1
cd /path/to/CajeerLogs && php bin/retention.php >/dev/null 2>&1
```

Рекомендуемые интервалы:

- `process-jobs.php` — каждую минуту.
- `alert-dispatch.php` — каждую минуту.
- `import-aapanel-logs.php` — каждые 1–5 минут, если нужен импорт локальных Nginx-журналов.
- `retention.php` — один раз в день.

## Ingest API

Основная точка приёма событий:

```http
POST /api/v1/ingest
```

Минимальные заголовки:

```http
Content-Type: application/json
X-Log-Token: RAW_BOT_TOKEN
```

В production HMAC-подпись включена по умолчанию. Дополнительно используются:

```http
X-Log-Timestamp: <unix timestamp>
X-Log-Nonce: <unique nonce>
X-Log-Signature: <hex hmac sha256>
```

Машиночитаемая спецификация находится в [`openapi.yaml`](openapi.yaml).

## Подключение Python-приложения или бота

1. Откройте `/bots` в web-интерфейсе.
2. Создайте токен для конкретного проекта, приложения и окружения.
3. Скопируйте сгенерированный блок переменных окружения в окружение запуска процесса.
4. Скопируйте `clients/bot.py` в проект или подключите его как внутренний модуль.
5. Добавьте `RemoteLogHandler` в точке входа приложения.
6. Отправьте тестовое событие и проверьте `/logs` или `/bots/health`.

## Обновление

Центр обновлений доступен в `/system/update`. Для публичного развёртывания web-запуск обновлений должен быть выключен до проверки репозитория, резервных копий и прав на файловую систему:

```env
UPDATE_REPO_URL=https://github.com/CajeerTeam/CajeerLogs
UPDATE_BRANCH=v0.8.2-ops-hardening
UPDATE_MODE=git
UPDATE_ALLOWED_REPO_HOSTS=github.com
UPDATE_ALLOWED_REPO_FULL_NAME=CajeerTeam/CajeerLogs
UPDATE_REQUIRE_TAG=true
UPDATE_REQUIRE_CLEAN_WORKTREE=true
UPDATE_ALLOW_WEB=false
UPDATE_BACKUP_DIR=storage/backups/updates
UPDATE_ROLLBACK_ON_FAILURE=true
UPDATE_GIT_BIN=/usr/bin/git
UPDATE_TAR_BIN=/usr/bin/tar
UPDATE_PHP_BIN=/www/server/php/83/bin/php
```

CLI-команды:

```bash
php bin/update.php status
php bin/update.php backup
php bin/update.php update
php bin/update.php rollback
php bin/update-env-check.php
```


## Настройки GitHub-репозитория

После загрузки проекта на GitHub проверьте настройки репозитория:

- включены `Wikis`, `Issues` и `Pull requests`;
- редактирование Wiki ограничено участниками с push-доступом;
- включена неизменяемость релизов;
- для merge включены squash/rebase, merge commits можно отключить;
- включено автоматическое удаление head-веток после merge;
- включён sign-off для web-коммитов, если используется DCO-процесс;
- `Sponsorships`, `Packages`, `Deployments`, `Discussions` оставлены выключенными, если проект их не использует.

Подробный чек-лист: [wiki/Repository-settings.md](wiki/Repository-settings.md).

## Документация

Основная документация проекта находится в GitHub Wiki:

https://github.com/CajeerTeam/CajeerLogs/wiki

Исходники wiki-страниц хранятся в каталоге [`wiki/`](wiki/) с ASCII-именами файлов, чтобы они корректно попадали в архивы и проверялись через pull request. Публикация в GitHub Wiki выполняется отдельным ручным шагом.

## Безопасность

- Не коммитьте `.env`, дампы БД, runtime-журналы, токены и резервные копии.
- Держите `APP_DEBUG=false` в production.
- После создания администратора выключите `LOGS_ENV_FALLBACK_LOGIN`.
- Для недоверенных сетей включите `INGEST_REQUIRE_SIGNATURE=true`.
- Ограничьте доступ к web-интерфейсу через `UI_IP_ALLOWLIST`, reverse proxy или сетевые политики.
- При компрометации секретов ротируйте пароль БД, ingest-токены и pepper-значения.

Процесс сообщения об уязвимостях описан в [`SECURITY.md`](SECURITY.md).

## Вклад в проект

Перед pull request прочитайте [`CONTRIBUTING.md`](CONTRIBUTING.md). В изменениях не должно быть секретов, runtime-файлов, дампов БД и упоминаний коммерческих площадок в качестве обязательных инструкций.

## Лицензия

Apache License 2.0. См. [`LICENSE`](LICENSE).

## Smoke test ingest API

После создания токена можно проверить работающий endpoint:

```bash
php bin/ingest-smoke.php --url=https://logs.example.com/api/v1/ingest --token=RAW_BOT_TOKEN --signed
```

## Публикация Wiki

Исходники Wiki лежат в `wiki/`. Публикуйте их вручную workflow `Publish Wiki` или локально через `bash bin/wiki-publish.sh`.


## Проверка релиза

Перед публикацией релиза запустите:

```bash
php bin/release-check.php
```

Для smoke-проверки работающего ingest API используйте:

```bash
php bin/ingest-smoke.php --url=https://logs.example.com/api/v1/ingest --token=RAW_BOT_TOKEN --signed
```

## Эксплуатационная диагностика

Для production-развёртывания полезны страницы:

- `/system/runtime` — PHP-FPM/CLI, OPcache, PDO-драйверы, production lock;
- `/system/jobs` — очередь фоновых задач и повтор webhook-доставок;
- `/health/cron` — JSON-проверка свежести cron-задач.

Перед обновлением можно проверить окружение без подключения к БД:

```bash
php bin/update-env-check.php
```
