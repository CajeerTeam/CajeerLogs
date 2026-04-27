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
