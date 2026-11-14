# Scheduler

В 0.0.8 встроенный PostgreSQL scheduler реально выполняет retention и очистку сессий, rate-limit, ingest receipts, nonce, завершённых jobs и webhook deliveries. Запуск: `php bin/cajeer scheduler:run` через systemd timer либо cron. Состояние расписания хранится в `scheduler_runs`, параллельные запуски сериализуются. Подробности, расписание и команды в [Queue & Lifecycle](Queue-Lifecycle.md).
