# Changelog

Формат основан на [Keep a Changelog](http://keepachangelog.com/ru/1.0.0/), версии следуют [Semantic Versioning](https://semver.org/lang/ru/).

## [0.0.3] — Identity & Access

### Added
- PostgreSQL migration 0003: project membership, blocked users, server-side hashed sessions, rate-limit buckets, token issuer metadata.
- Реальный HTTP login/logout/logout-all/me, Argon2id, изменение пароля, ротация и отзыв сессий, cookie + CSRF, production HTTPS.
- Управление пользователями и ролями, защита последнего действующего администратора; транзакционные изменения и аудит.
- Создание/просмотр проектов и источников, управление членством, SQL-изоляция проектов для operator/viewer.
- Scoped API-токены с expiry, hash storage, last_used_at, выдачей секрета один раз, отзывом и introspection; защищённый system report.
- Atomic PostgreSQL rate limiting, CLI auth:cleanup, cron, реальные integration tests и OpenAPI runtime routes.

### Changed
- Существующие миграции 0001/0002 сохранены без изменения checksum; readiness проверяет новые таблицы.
- Production identity API требует HTTPS. Legacy глобальные токены с log scopes не авторизуются.
- Нереализованные TOTP/HMAC ingest не объявляются активными. Ingest, поиск, UI и workers — последующие версии.

## [0.0.2] — Database & Installer

### Added
- PostgreSQL 17+ через PDO: проверка версии и схемы, настройки TLS, таймауты соединения, запросов и блокировок; подключение через контейнер runtime.
- Транзакционный runner для PostgreSQL, история `schema_migrations`, SHA-256 контрольные суммы, advisory lock, отказ при расхождении истории или неизвестных миграциях.
- CLI `install`, `migrate`, `migrate:status`, `db:doctor`, реальные проверки БД в `install:check`, `doctor` и `system:report`.
- CLI-установщик с атомарным созданием `.env` (0600), уникальными секретами и первым администратором с Argon2id; повторный запуск не сбрасывает пароль.
- Репозитории пользователей, проектов, источников и токенов, связи и индексы PostgreSQL; токены хранятся как SHA-256 хеши случайных 256-битных значений.
- `/ready` для проверки БД и миграций независимо от runtime health; локальный Docker Compose с отдельной ролью БД.
- Тесты конфигурации, PostgreSQL integration suite и CI с PostgreSQL 17.

### Changed
- Базовая PostgreSQL-схема больше не требует pgcrypto; ручное применение SQL не является способом установки. Единственный источник SQL — `migrations/pgsql/`.
- PostgreSQL — единственная поддерживаемая СУБД в 0.0.2; web-установка отключена.

### Scope
- Приём/поиск логов, HTTP-авторизация, очередь и Admin UI ещё не реализованы. Наличие таблиц, репозиториев и первого администратора не означает наличие рабочего входа через браузер.

## [0.0.1] — Core Runtime

### Added
- Рабочий PHP Application Kernel, загрузка `.env`, валидация runtime-конфигурации и DI с singleton/transient lifecycle.
- HTTP Request/Response, Router с параметрами и middleware, HEAD/OPTIONS, корректные 404/405, JSON validation и лимит тела запроса.
- Централизованные безопасные HTTP-ошибки, request ID, security headers.
- Runtime health checks, Prometheus metrics, CLI diagnostics и runtime OpenAPI lint.
- PHP development router, PHPUnit, PHPStan, standalone runtime tests, HTTP integration tests и CI PHP 8.4/8.5.

### Changed
- Версия из `release.json`, исправление вложенного ConfigRepository.
- Опубликованный OpenAPI описывает реализованный API; будущий API вынесен в `docs/openapi-planned.yaml`.
- Не реализованные команды завершаются с кодом 69 вместо фиктивного успеха.
- Примеры Nginx/Apache ограничивают выполнение PHP точкой входа; queue unit не перезапускается при коде 69.

### Scope
- PostgreSQL/installer, ingestion, авторизация, поиск, Admin UI, очередь и остальные интеграции относятся к следующим релизам. Health проверяет только runtime.
