# Установка

## Требования

- PHP 8.2 или новее.
- Расширение `pdo`.
- `pdo_pgsql` для production-режима.
- PostgreSQL для постоянного хранения.
- Web-сервер с document root на каталог `public/`.

## Базовая установка

```bash
git clone https://github.com/CajeerTeam/CajeerLogs.git
cd CajeerLogs
cp .env.example .env
php bin/bootstrap.php
php bin/migrate.php
php bin/doctor.php
php bin/self-test.php
php bin/wiki-check.php
```

Создайте администратора:

```bash
php bin/make-user.php admin 'CHANGE_ME_STRONG_PASSWORD' admin
```

После создания пользователя отключите аварийный вход через `.env`:

```env
LOGS_ENV_FALLBACK_LOGIN=false
```
