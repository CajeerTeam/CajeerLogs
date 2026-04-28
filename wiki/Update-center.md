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
```

Не используйте `main` как цель production-обновления. Перед обновлением центр обновлений создаёт backup, запускает миграции, `doctor` и `release-check`. Если миграции или проверки завершились ошибкой, включённый `UPDATE_ROLLBACK_ON_FAILURE=true` возвращает рабочее дерево к предыдущему commit.

Если миграция упала, не запускайте повторный update вслепую: проверьте `storage/logs/app.log`, последнюю запись в `update_runs`, backup-каталог и состояние БД.
