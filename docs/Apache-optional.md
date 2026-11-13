# Apache deployment — Database & Installer 0.0.2

Используйте Apache 2.4 с PHP 8.4+ (PHP-FPM или PHP module), `DocumentRoot /var/www/cajeerlogs/public`, включённым mod_rewrite и mod_headers. Скопируйте `examples/apache/.htaccess` в `public/` или перенесите правила в VirtualHost. Для .htaccess требуются AllowOverride FileInfo Options. Отключите directory listing и запретите выполнение всех PHP-файлов кроме index.php. Настройте TLS и ограничения размера запроса; APP_MAX_BODY_BYTES применяется в приложении. Не размещайте `.env`, core или config внутри DocumentRoot.

Проверьте конфигурацию `apachectl configtest`, затем `GET /api/v1/health`. `/health` проверяет runtime, `/ready` — PostgreSQL и миграции. Установка описана в [Database & Installer](Database-Installer.md). Ingest и авторизация ещё не реализованы.
