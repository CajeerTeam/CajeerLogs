# CajeerLogs

Self-hosted PHP-first платформа централизованного логирования.

## 0.0.1 — Core Runtime

Рабочее PHP-ядро, HTTP routing, конфигурация, обработка ошибок, диагностика и метрики. Этот релиз **не является готовым logging-сервисом**: PostgreSQL, ingest, поиск, авторизация, очереди и Admin UI будут подключены в следующих версиях.

### Быстрый запуск

Требуется PHP 8.4+ с JSON, PDO, ctype, filter и PCRE. Для запуска минимального runtime Composer не обязателен; для разработки и тестирования установите зависимости.

```sh
cp .env.example .env
php bin/cajeer version
php bin/cajeer install:check
php bin/cajeer doctor
php -S 127.0.0.1:8080 -t public public/router.php
```

В другом терминале:

```sh
curl -i http://127.0.0.1:8080/api/v1/health
curl -i http://127.0.0.1:8080/metrics
curl -i -X POST http://127.0.0.1:8080/health
```

Health проверяет **только runtime**, не подключение к БД. При ошибке проверок возвращается 503. Неподключённые операции возвращают 404, а CLI-команды таких операций завершаются ненулевым кодом.

В production используйте PHP-FPM с Nginx или Apache, DocumentRoot `public/`, TLS и отдельного непривилегированного пользователя. Встроенный PHP-сервер предназначен только для разработки.

### Проверки

```sh
php tests/runtime.php
python3 tests/http_runtime.py
php bin/cajeer openapi:lint
composer install
composer test
composer analyse
```

См. [Core Runtime](docs/Core-Runtime.md), [Nginx](docs/Nginx-deployment.md), [Apache](docs/Apache-optional.md) и [реализованный OpenAPI](api/openapi.yaml). Планируемый API находится отдельно в `docs/openapi-planned.yaml`.
