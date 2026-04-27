# PostgreSQL

PostgreSQL — основной режим для production.

## Создание пользователя и базы

```sql
CREATE USER cajeer_logs WITH PASSWORD 'change_me';
CREATE DATABASE cajeer_logs OWNER cajeer_logs;
GRANT ALL PRIVILEGES ON DATABASE cajeer_logs TO cajeer_logs;
```

После настройки `.env` примените миграции:

```bash
php bin/migrate.php
php bin/db-doctor.php
```

Если владельцы таблиц отличаются от ожидаемого пользователя, получите SQL для исправления:

```bash
php bin/db-doctor.php --sql
```
