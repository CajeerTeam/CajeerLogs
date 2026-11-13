# Nginx deployment — Core Runtime 0.0.1

Пример: `examples/nginx/cajeerlogs.conf`. Требуются Nginx и PHP 8.4+ FPM. Установите проект в `/var/www/cajeerlogs`, настройте DocumentRoot только на `public/`, обеспечьте чтение кода и запись в `storage/` для пользователя PHP-FPM. Храните `.env` вне web root, с минимальными правами чтения. Подставьте фактический сокет PHP-FPM и server_name.

Включите TLS для внешнего доступа. Пример слушает HTTP 80 и предназначен как локальная основа или для TLS-терминации на доверенном reverse proxy. Не открывайте `/metrics` всем пользователям интернета. Nginx передаёт PHP только `index.php`; остальные PHP-пути запрещены. `client_max_body_size` ограничивает запросы до 16 MiB, а приложение применяет APP_MAX_BODY_BYTES (1 MiB по умолчанию). Nginx может отклонить запрос раньше PHP.

```sh
nginx -t
php bin/cajeer install:check
curl -i http://logs.example.com/api/v1/health
```

Health относится только к PHP runtime. Не включайте systemd workers, cron и installer в 0.0.1. База и обработка логов не реализованы в этой версии.
