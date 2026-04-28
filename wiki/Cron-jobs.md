# Cron-задачи

Рекомендуемые задачи:

```bash
cd /path/to/CajeerLogs && php bin/process-jobs.php >/dev/null 2>&1
cd /path/to/CajeerLogs && php bin/alert-dispatch.php >/dev/null 2>&1
cd /path/to/CajeerLogs && php bin/import-aapanel-logs.php --max-lines=1000 >/dev/null 2>&1
cd /path/to/CajeerLogs && php bin/retention.php >/dev/null 2>&1
```

Рекомендуемые интервалы:

- `process-jobs.php` — каждую минуту.
- `alert-dispatch.php` — каждую минуту.
- `import-aapanel-logs.php` — каждые 1–5 минут, если используется импорт Nginx-журналов.
- `retention.php` — один раз в день.

## Health endpoint cron-задач

Для внешнего мониторинга доступен JSON endpoint:

```text
GET /health/cron
```

Он показывает свежесть `process-jobs`, `alert-dispatch`, импорта журналов и retention. Если `process-jobs` давно не запускался, webhook-доставки будут копиться в очереди.
