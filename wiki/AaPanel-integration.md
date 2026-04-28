# Интеграция aaPanel

Cajeer Logs поддерживает импорт локальных access/error журналов Nginx из типовой раскладки aaPanel. Интеграция не требует внешнего сервиса: приложение читает файлы на том же сервере, где развёрнут web-интерфейс.

## Переменные окружения

```env
NGINX_LOG_DIR=/www/wwwlogs
NGINX_LOG_PROJECT=Web Sites
NGINX_LOG_ENVIRONMENT=production
AAPANEL_LOG_DIR=/www/wwwlogs
AAPANEL_LOG_PROJECT=Web Sites
AAPANEL_LOG_ENVIRONMENT=production
AAPANEL_LOG_IMPORT_MAX_LINES=1000
```

`NGINX_*` — нейтральные имена для новых установок. `AAPANEL_*` оставлены для совместимости с существующими aaPanel-развёртываниями.

## Права доступа

PHP-процесс должен иметь право читать файлы журналов. Если импорт ничего не добавляет, проверьте:

- путь `NGINX_LOG_DIR`;
- права пользователя PHP-FPM/CLI;
- наличие файлов `*.log`;
- результат `php bin/import-aapanel-logs.php --max-lines=100`.

## Offset tracking

Импортёр хранит offset, inode, размер файла и признаки rotation в таблице `aapanel_log_offsets`. При log rotation импорт продолжит чтение с корректной позиции или начнёт с начала нового файла, если rotation обнаружена.

## Ручной запуск

```bash
php bin/import-aapanel-logs.php --max-lines=1000
```

Для production используйте cron/systemd timer с интервалом 1–5 минут.
