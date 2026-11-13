# Apache deployment — Core Runtime 0.0.1

Используйте Apache 2.4 с PHP 8.4+ (PHP-FPM или PHP module), `DocumentRoot /var/www/cajeerlogs/public`, включённым mod_rewrite и mod_headers. Скопируйте `examples/apache/.htaccess` в `public/` или перенесите правила в VirtualHost. Для .htaccess требуются AllowOverride FileInfo Options. Отключите directory listing и запретите выполнение всех PHP-файлов кроме index.php. Настройте TLS и ограничения размера запроса; APP_MAX_BODY_BYTES применяется в приложении. Не размещайте `.env`, core или config внутри DocumentRoot.

Проверьте конфигурацию `apachectl configtest`, затем `GET /api/v1/health`. В 0.0.1 это только runtime, не полноценный logging backend.
