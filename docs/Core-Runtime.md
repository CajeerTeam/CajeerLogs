# Core Runtime 0.0.1

## Запуск и конфигурация

PHP 8.4+; обязательные расширения: json, PDO (без обязательного драйвера БД на этом этапе), ctype, filter, pcre. Запускайте из корня репозитория. `release.json` — источник версии приложения. Composer autoload используется при наличии `vendor/autoload.php`; минимальный runtime имеет собственный PSR-4 fallback.

`.env` необязателен; `.env.example` — пример. Приоритет: переменные окружения процесса, `$_ENV`, `.env`, defaults. Значения с пробелами и `#` можно заключать в одинарные или двойные кавычки. Двойные кавычки поддерживают `\n`, `\r`, `\t`, `\"`, `\\`. Подстановка переменных и выполнение shell-команд не поддерживаются. Некорректные строки и повторные ключи блокируют запуск. Пустое значение не заменяется автоматически default-значением. `true`, `false`, `null` и формы в скобках интерпретируются helper `env()` как соответствующие типы. Не используйте эти значения как имена или строки runtime-настроек.

| Параметр | Default | Ограничения |
|---|---|---|
| APP_NAME | CajeerLogs | непустая строка |
| APP_ENV | production | production/local/development/test |
| APP_DEBUG | false | boolean; true запрещён в production |
| APP_URL | http://localhost:8080 | абсолютный HTTP(S) URL без credentials/query/fragment |
| APP_LOCALE | ru | непустая строка |
| APP_TIMEZONE | Europe/Moscow | IANA timezone |
| APP_MAX_BODY_BYTES | 1048576 | 1–16777216 bytes |
| API_PREFIX | /api/v1 | абсолютный path, сегменты A-Z/a-z/0-9/_/- |
| OPENAPI_PATH | api/openapi.yaml | путь к спецификации внутри проекта |
| PROMETHEUS_ENABLED | true | boolean |
| PROMETHEUS_PATH | /metrics | не должен совпадать с health endpoints |

APP_DEBUG не раскрывает stack trace даже в local. Настройки БД, очередей, секретов, cache, analytics и storage в примере зарезервированы для дальнейших версий и не активируют соответствующие подсистемы. Не используйте демонстрационные секреты при включении будущих функций.

## HTTP lifecycle

`public/index.php` → bootstrap → Application::boot → Request::fromGlobals → Application::handle → Router middleware → handler → Response → send.

Router поддерживает точные пути и именованные сегменты `{id}`, middleware с `next(Request): Response`, HEAD fallback на GET, автоматический OPTIONS, 404 и 405 с Allow. Параметры пути декодируются один раз; недопустимые управляющие символы, разделители, точки `.`/`..` и невалидный UTF-8 запрещены. Literal routes имеют приоритет перед параметризованными. Request::json проверяет Content-Type, корректность JSON и форму данных. Тело ограничивается на входе и в runtime middleware.

Все динамические ответы содержат X-Request-ID (новое случайное значение на запрос), no-store и защитные заголовки. Ошибки имеют формат `{error, message, request_id}`. Внутренние исключения не раскрывают stack trace и значения конфигурации; PHP error log содержит тип исключения, файл/строку и request ID. Неизвестные ошибки bootstrap дают HTTP 500. Health возвращает 503 при провале runtime checks. Заголовки и тело статических файлов обслуживает веб-сервер отдельно.

### Реально доступные endpoints

| Method | Path | Result |
|---|---|---|
| GET | / | runtime health |
| GET | /health | runtime health |
| GET | /api/v1/health | runtime health |
| GET | /metrics | Prometheus gauges, если включено |
| HEAD | те же пути | без тела |
| OPTIONS | те же пути | 204 и Allow |

`/api/v1/system/report`, `/api/v1/ingest`, `/api/v1/logs`, `/api/v1/webhooks` ещё не реализованы и не зарегистрированы. Не открывайте административные или ingestion endpoints без авторизации. Системный отчёт доступен только локально через CLI.

## CLI

`php bin/cajeer help`, `version`, `install:check`, `doctor`, `system:report`, `openapi:lint`.

- `install:check` проверяет PHP, обязательные расширения, наличие файлов, загруженную конфигурацию и права записи в storage.
- `doctor` выполняет те же проверки и проверяет реальный dispatch health, 405 и 404.
- `system:report` возвращает безопасный JSON с результатами проверок, версиями и доступными PDO drivers; не выводит .env, пароли и токены.
- `openapi:lint` проверяет metadata, version, local refs, операции, соответствие актуальному runtime, обе копии документа. Это целевой lint текущего API, не универсальный валидатор всей OpenAPI 3.1. Документ записан JSON-синтаксисом (подмножество YAML 1.2) для работы без Composer; другие YAML-документы требуют symfony/yaml. При изменении API_PREFIX настройте server URL в своей спецификации и синхронизируйте копии.

Exit codes: 0 — успешно; 1 — ошибка проверки/runtime; 64 — неизвестная команда; 69 — операция пока не поддерживается. Не включайте queue/scheduler units, cron jobs или installer до реализации соответствующих функций.

## Проверка и ограничения

`php tests/runtime.php` — проверки ядра без vendor; `python3 tests/http_runtime.py` — реальные HTTP-запросы, включая ошибки конфигурации, лимиты, методы и отказ runtime-хранилища. `composer test` запускает PHPUnit, `composer analyse` — PHPStan для Core Runtime, `.github/workflows/runtime.yml` запускает проверки на PHP 8.4/8.5.

0.0.1 не обещает production-готовность платформы логирования целиком. Следующий релиз 0.0.2 реализует PostgreSQL и установку. Для публичного развёртывания runtime настройте TLS, ограничьте доступ к метрикам сетевой политикой и установите лимиты на reverse proxy/PHP-FPM. Не публикуйте корень репозитория и не используйте PHP development server в production.
