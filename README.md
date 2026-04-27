# logs.cajeer.ru — production PHP edition

Внутренний self-hosted сервис Cajeer для централизованного хранения, анализа и эксплуатации логов ботов, сайтов aaPanel и инфраструктурных событий.

Стек:

- PHP 8.2+
- PDO PostgreSQL для production
- SQLite fallback для локального/аварийного запуска
- Nginx + PHP-FPM в aaPanel
- без Laravel, Node, Go и Composer-зависимостей
- без каталогов `sql/`, `docs/`, `scripts/`, `deploy/` в боевом архиве

## Основные разделы

- `/` — операционный командный центр.
- `/logs` — журналы, фильтры, экспорт, live-refresh, saved views.
- `/logs/{id}` — детальная карточка события, JSON/context/trace/fingerprint.
- `/search` — глобальный поиск.
- `/bots` — токены ботов, rate limit, HMAC, тестовый лог.
- `/bots/{id}` — диагностика конкретного бота и env для BotHost.
- `/bots/new` — мастер подключения бота.
- `/bots/health` — здоровье ботов.
- `/sites` — логи сайтов aaPanel.
- `/sites/{domain}` — топ 404/5xx/IP/User-Agent и импорт конкретного сайта.
- `/incidents` — инциденты, mute и массовые действия.
- `/alerts` — Telegram/Discord alerts.
- `/jobs` — очередь фоновых задач.
- `/cron` и `/system/cron-setup` — cron status и команды для aaPanel.
- `/system` — состояние системы и production-checks.
- `/system/database` — PostgreSQL ownership diagnostics и SQL-fix.
- `/system/migrations` — миграции из UI.
- `/system/update` — проверка после обновления.
- `/system/backups` — backup/restore и NDJSON.gz архивирование старых логов.
- `/recovery` — аварийная диагностика без сессии, но с учётом UI_IP_ALLOWLIST.

## Структура боевого архива

```text
logs-main/
├─ app/             # PHP classes
├─ bin/             # CLI команды
├─ bot_clients/     # RemoteLogHandler для Python-ботов
├─ public/          # web root для aaPanel/Nginx
├─ storage/         # runtime logs/cache/archives/sqlite
├─ .env             # production-конфигурация конкретного сервера
├─ .gitignore
├─ README.md
└─ VERSION
```

## Первичная подготовка

```bash
cd /www/wwwroot/logs.cajeer.ru
/www/server/php/83/bin/php bin/bootstrap.php
/www/server/php/83/bin/php bin/migrate.php
/www/server/php/83/bin/php bin/doctor.php
```

Если сайт использует PHP 8.2 или 8.4, замени путь:

```bash
/www/server/php/82/bin/php
/www/server/php/84/bin/php
```

## Создание администратора

```bash
cd /www/wwwroot/logs.cajeer.ru
/www/server/php/83/bin/php bin/make-user.php admin 'СЛОЖНЫЙ_ПАРОЛЬ' admin
```

Если PostgreSQL ругается на `must be owner of table ...`, открой:

```text
https://logs.cajeer.ru/system/database
```

или:

```bash
/www/server/php/83/bin/php bin/db-doctor.php --sql
```

## Cron для aaPanel

В aaPanel → Cron → Add Task → Shell Script добавь:

```bash
cd /www/wwwroot/logs.cajeer.ru && /www/server/php/83/bin/php bin/process-jobs.php >/dev/null 2>&1
cd /www/wwwroot/logs.cajeer.ru && /www/server/php/83/bin/php bin/alert-dispatch.php >/dev/null 2>&1
cd /www/wwwroot/logs.cajeer.ru && /www/server/php/83/bin/php bin/import-aapanel-logs.php --max-lines=1000 >/dev/null 2>&1
cd /www/wwwroot/logs.cajeer.ru && /www/server/php/83/bin/php bin/retention.php >/dev/null 2>&1
```

Рекомендуемые интервалы:

- `process-jobs.php` — каждую минуту.
- `alert-dispatch.php` — каждую минуту.
- `import-aapanel-logs.php` — каждые 1–5 минут.
- `retention.php` — раз в сутки.

Также можно открыть `/system/cron-setup`, скопировать команды и запустить каждую задачу вручную из UI для проверки.

## Подключение бота

1. Открой `/bots/new` или `/bots`.
2. Создай токен для проекта/бота/окружения.
3. Скопируй env-блок в BotHost.
4. Добавь `bot_clients/remote_log_handler.py` в проект бота.
5. Подключи handler в `main.py`.
6. Нажми «Тестовый лог» и проверь `/bots/{id}` или `/logs`.

Endpoint ingest:

```text
POST https://logs.cajeer.ru/api/v1/ingest
```

Headers:

```http
Content-Type: application/json
X-Log-Token: RAW_BOT_TOKEN
```

## Production-настройки безопасности

Проверь в `.env`:

```env
APP_ENV=production
APP_DEBUG=false
LOGS_ENV_FALLBACK_LOGIN=false
LOGS_TOKEN_PEPPER=<случайные 64 hex символа>
PRIVACY_HASH_PEPPER=<случайные 64 hex символа>
```

Если аварийный вход временно нужен:

```env
LOGS_ENV_FALLBACK_LOGIN=true
LOGS_WEB_BASIC_USER=admin
LOGS_WEB_BASIC_PASSWORD=сложный_пароль
```

После создания нормального admin-пользователя отключи fallback.

## Архивирование старых логов

Через UI:

```text
/system/backups
```

Через CLI:

```bash
/www/server/php/83/bin/php bin/archive-retention.php --days=30 --source=all
/www/server/php/83/bin/php bin/archive-retention.php --days=14 --source=aapanel_access
/www/server/php/83/bin/php bin/archive-retention.php --days=90 --source=aapanel_error
```

Архивы пишутся в:

```text
storage/archives/*.ndjson.gz
```

## После обновления

Открой:

```text
/system/update
```

и нажми:

```text
Проверить и подготовить после обновления
```

Или через CLI:

```bash
/www/server/php/83/bin/php bin/migrate.php
/www/server/php/83/bin/php bin/doctor.php
/www/server/php/83/bin/php bin/db-doctor.php
```

## Обновление из GitHub

В проект добавлен центр обновлений: `Система → Обновление` или `/system/update`.

Репозиторий по умолчанию:

```env
UPDATE_REPO_URL=https://github.com/CajeerTeam/CajeerLogs
UPDATE_BRANCH=main
UPDATE_MODE=git
UPDATE_ALLOW_WEB=true
UPDATE_BACKUP_DIR=/www/wwwroot/logs.cajeer.ru/storage/backups/updates
UPDATE_ROLLBACK_ON_FAILURE=true
```

Для приватного репозитория можно добавить токен:

```env
UPDATE_GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxx
```

Токен не выводится в интерфейсе и маскируется в логах команд.

Перед обновлением система проверяет git, текущий commit, удалённый commit, локальные изменения, доступность backup-каталога, PHP CLI, БД и `.env`. Перед `git reset --hard origin/main` создаётся backup файлов в `storage/backups/updates`, сохраняется `.env`, затем запускаются:

```bash
php bin/migrate.php
php bin/doctor.php
```

CLI-эквивалент:

```bash
cd /www/wwwroot/logs.cajeer.ru
/www/server/php/83/bin/php bin/update.php status
/www/server/php/83/bin/php bin/update.php backup
/www/server/php/83/bin/php bin/update.php update
/www/server/php/83/bin/php bin/update.php rollback
```

Если PHP сайта не 8.3, укажи правильный путь:

```env
UPDATE_PHP_BIN=/www/server/php/84/bin/php
```
