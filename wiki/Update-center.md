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
UPDATE_REQUIRE_TAG=false
UPDATE_REQUIRE_CLEAN_WORKTREE=true
```

Если включить `UPDATE_REQUIRE_TAG=true`, `UPDATE_BRANCH` должен указывать на release tag вида `v0.8.2-ops-hardening`, а не на `main`.
