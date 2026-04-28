# Центр обновлений

Центр обновлений находится в `/system/update` и работает через `git`.

В production web-запуск обновлений лучше держать выключенным:

```env
UPDATE_ALLOW_WEB=false
```

CLI-команды:

```bash
php bin/update.php status
php bin/update.php backup
php bin/update.php update
php bin/update.php rollback
php bin/update-env-check.php
```

Перед обновлением проверьте резервные копии, права на файлы и возможность отката.


## Ограничение источника обновлений

Для self-hosted production ограничьте источник обновления:

```env
UPDATE_ALLOWED_REPO_HOSTS=github.com
UPDATE_ALLOWED_REPO_FULL_NAME=CajeerTeam/CajeerLogs
UPDATE_REQUIRE_TAG=true
UPDATE_REQUIRE_CLEAN_WORKTREE=true
```

Если `UPDATE_REQUIRE_TAG=true`, `UPDATE_BRANCH` должен указывать на release tag вида `v0.8.2-ops-hardening`, а не на `main`.


## Жёсткий production-режим

Для production рекомендуется обновляться только по release tag:

```env
UPDATE_BRANCH=v0.8.2-ops-hardening
UPDATE_REQUIRE_TAG=true
UPDATE_REQUIRE_CLEAN_WORKTREE=true
UPDATE_ALLOWED_REPO_HOSTS=github.com
UPDATE_ALLOWED_REPO_FULL_NAME=CajeerTeam/CajeerLogs
UPDATE_COMMAND_TIMEOUT_SECONDS=120
UPDATE_GIT_BIN=/usr/bin/git
UPDATE_TAR_BIN=/usr/bin/tar
UPDATE_PHP_BIN=/www/server/php/83/bin/php
```

Не используйте `main` как цель production-обновления. Перед обновлением центр обновлений создаёт backup, запускает миграции, `doctor` и `release-check`. Если миграции или проверки завершились ошибкой, включённый `UPDATE_ROLLBACK_ON_FAILURE=true` возвращает рабочее дерево к предыдущему commit.

Если миграция упала, не запускайте повторный update вслепую: проверьте `storage/logs/app.log`, последнюю запись в `update_runs`, backup-каталог и состояние БД.


## Фазы обновления

1. Проверить окружение и доступность `git`, `tar`, PHP CLI и `.env`.
2. Создать резервную копию файлов и snapshot `.env`.
3. Проверить удалённый release tag или ветку.
4. Выполнить `git fetch`.
5. Переключить рабочее дерево через `git reset --hard`.
6. Восстановить `.env` из backup.
7. Применить миграции.
8. Выполнить health/release checks.
9. При необходимости вручную перезапустить PHP-FPM.

## Диагностика окружения обновлений

Если в SSH `git`/`tar` доступны, но web-интерфейс пишет, что они не найдены, у PHP-FPM отличается `PATH` или отключены функции запуска процессов. Укажите полные пути:

```env
UPDATE_GIT_BIN=/usr/bin/git
UPDATE_TAR_BIN=/usr/bin/tar
UPDATE_PHP_BIN=/www/server/php/83/bin/php
```

Проверка:

```bash
php bin/update-env-check.php
```
