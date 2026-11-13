# Nginx deployment — Log Ingestion 0.0.4

Пример: `examples/nginx/cajeerlogs.conf`. Требуются Nginx и PHP 8.4+ FPM. Установите проект в `/var/www/cajeerlogs`, настройте DocumentRoot только на `public/`, обеспечьте чтение кода и запись в `storage/` для пользователя PHP-FPM. Храните `.env` вне web root, с минимальными правами чтения. Подставьте фактический сокет PHP-FPM и server_name.

Включите TLS для внешнего доступа. Пример перенаправляет HTTP 80 на HTTPS 443; замените домен и пути к действующим сертификатам. Identity API требует `APP_URL=https://...` и `HTTPS=on` от доверенного web server. При внешней TLS-терминации задавайте HTTPS только в защищённом внутреннем маршруте, не из произвольных X-Forwarded-Proto. Не открывайте `/metrics` всем пользователям интернета. Nginx передаёт PHP только `index.php`; остальные PHP-пути запрещены. `client_max_body_size` ограничивает запросы до 16 MiB, а приложение применяет APP_MAX_BODY_BYTES (1 MiB по умолчанию). Nginx может отклонить запрос раньше PHP.

```sh
nginx -t
php bin/cajeer install:check
curl -i https://logs.example.com/api/v1/health
```

Health относится только к PHP runtime. `/ready` проверяет PostgreSQL и миграции. Запустите CLI-установщик по [инструкции](Database-Installer.md), настройте cron `auth:cleanup`; workers и scheduler пока не включайте. HTTP-авторизация реализована, см. [Identity & Access](Identity-Access.md). Ingest реализован, см. [Log Ingestion](Log-Ingestion.md).

Для выделенного PHP-FPM pool см. `examples/php-fpm/cajeerlogs.conf`. Создайте системного пользователя `cajeerlogs`, настройте права кода, `.env` и `storage/logs/`, скорректируйте владельца сокета Nginx и поместите pool в конфигурацию PHP-FPM. Пример ограничений процессов требует настройки под память и нагрузку сервера.
