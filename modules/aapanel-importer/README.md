# aaPanel / Nginx log importer 0.0.7

Рабочий CLI-импорт через SDK и Ingest API. Поддерживает Nginx common/combined access, стандартный error log, JSONL и текстовые PHP-журналы. Не требует доступа к БД CajeerLogs или aaPanel API; запускать непривилегированным пользователем, имеющим read-only доступ к конкретному журналу.

```sh
# CAJEER_URL, CAJEER_TOKEN — из защищённого окружения.
php bin/cajeer import:aapanel --file /www/wwwlogs/example.com.log --state /var/lib/cajeer-collector/example.sqlite --source nginx-access --format nginx-access --once
```

`bin/import-aapanel-logs.php` принимает те же параметры. Уберите `--once` для постоянного сбора. Реализация: `clients/python/cajeer_logs_collector.py`. Инструкции по ротации, состоянию и systemd: [SDK & Integrations](../../docs/SDK-Integrations.md). Автозагрузка через extension runtime/scheduler не заявлена.
