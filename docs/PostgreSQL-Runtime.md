# CajeerLogs 0.1.1 — PostgreSQL Runtime

## Обновление

Наложите ZIP изменённых файлов на 0.1.0, предварительно сделав backup БД и `.env`. Перезапустите PHP-FPM и все workers. SQL 0001–0008 сохранены побайтово, новых миграций нет.

```sh
php bin/cajeer version
php bin/cajeer migrate:status
php bin/cajeer db:doctor
php bin/cajeer doctor
```

Совместимость backup: 0.0.9 и 0.1.0 можно восстановить в 0.1.1; проверяется история миграций. Восстановление — только в отдельную пустую БД по [инструкции](Security-Operations.md).

## Соединения

| Параметр | Значение по умолчанию | Допустимый диапазон |
|---|---|---|
| `DB_APPLICATION_NAME` | `cajeerlogs` | 1–48 ASCII символов `[A-Za-z0-9_.:-]` |
| `DB_CONNECT_TIMEOUT` | 5 секунд | 1–60 |
| `DB_CONNECT_ATTEMPTS` | 2 | 1–5 |
| `DB_CONNECT_RETRY_MS` | 250 | 10–5000 мс |
| `DB_STATEMENT_TIMEOUT_MS` | 30000 | 1–600000 |
| `DB_LOCK_TIMEOUT_MS` | 10000 | 1–600000 |
| `DB_IDLE_TRANSACTION_TIMEOUT_MS` | 60000 | 1000–3600000 |
| `DB_KEEPALIVES_IDLE` | 30 секунд | 1–600 |
| `DB_KEEPALIVES_INTERVAL` | 10 секунд | 1–120 |
| `DB_KEEPALIVES_COUNT` | 3 | 1–10 |
| `DB_TCP_USER_TIMEOUT_MS` | 30000 | 1000–600000 |

PDO использует нативные prepared statements и непостоянные соединения. Каждая новая сессия устанавливает search_path, UTC, UTF-8 и таймауты заново. PHP-FPM владеет соединением в пределах одного запроса; queue worker переиспользует здоровую сессию и проверяет её не реже чем на очередной границе работы после 5 секунд. Отдельные CLI-процессы webhook и scheduler создают новые соединения при каждом запуске.

Для TCP libpq включает keepalive и TCP user timeout (если ОС поддерживает эти параметры); они помогают обнаружить разрыв/сетевую blackhole. Это не абсолютный deadline SQL-запроса, Unix socket и поведение ОС могут отличаться.

Retry выполняется **только при открытии/инициализации соединения** для сетевых ошибок и исчерпания доступных подключений. Ошибки credentials, неверной схемы и TLS не должны исправляться повтором. SQLSTATE с libpq диагностикой классифицируется без вывода строки ошибки, пароля и DSN. Для неизвестных/локализованных сообщений классификация может быть менее точной. Задержки с jitter увеличиваются экспоненциально, не более 5 секунд. Верхняя оценка времени подключения — число попыток × connect timeout + задержки (DNS и сетевой стек могут влиять на время); учитывайте её при настройке HTTP-клиентов, FPM и Prometheus scrape timeout.

При обрыве между claim и завершением worker не повторяет неизвестно завершившийся SQL/COMMIT. Он отбрасывает сессию и начинает следующую операцию на новой; уже зафиксированное завершение остаётся в БД, иначе задание восстанавливается после истечения двухминутного lease. После десяти последовательных отказов непрерывный worker завершает работу с ошибкой; systemd/Compose supervisor перезапускает его. Конечный `queue:work N` завершает работу с ошибкой при потере сессии. Для синхронного ingest при неопределённом результате используйте исходный `Idempotency-Key`, тело запроса и действующий токен. Не генерируйте новый ключ только потому, что ответ потерян.

## TLS и PgBouncer

Для PostgreSQL через недоверенную сеть используйте:

```dotenv
DB_HOST=db.example.com
DB_SSLMODE=verify-full
DB_SSLROOTCERT=/etc/cajeerlogs/postgres-ca.pem
```

Сертификат должен соответствовать имени хоста. `require` шифрует соединение, но без проверки имени не заменяет `verify-full`. `prefer` сохранён для обратной совместимости; production Compose использует `disable` только в собственной изолированной сети. GSS-шифрование отключено, чтобы не обходить выбранный режим TLS. CA должен быть доступен пользователю PHP. `db:doctor` показывает фактический TLS и значения параметров сессии.

PgBouncer допускается **только в `pool_mode=session`**: PostgreSQL SET, нативные prepared statements и session advisory locks должны оставаться на одной серверной сессии. Миграции и резервирование предпочтительно направлять напрямую к PostgreSQL. Transaction/statement pooling не поддерживаются; приложение не может автоматически определить режим внешнего прокси. CI содержит реальный тест session pooling, но не проверку всех версий/конфигураций PgBouncer.

## Раздельные роли

Обратная совместимость: без `DB_MIGRATION_USERNAME` и `DB_MIGRATION_PASSWORD` используется существующая роль. Для минимизации привилегий создайте отдельную схему и роли в выделенной базе. Пример для новой установки (выполнить доверенным администратором PostgreSQL, заменив имена/пароли; `\password` запрашивает пароль в терминале):

```sql
CREATE ROLE cajeer_migrator LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE cajeer_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
\password cajeer_migrator
\password cajeer_runtime
CREATE SCHEMA cajeer AUTHORIZATION cajeer_migrator;
GRANT CONNECT ON DATABASE cajeer_logs TO cajeer_migrator, cajeer_runtime;
GRANT USAGE ON SCHEMA cajeer TO cajeer_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE cajeer_migrator IN SCHEMA cajeer
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO cajeer_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE cajeer_migrator IN SCHEMA cajeer
  GRANT USAGE, SELECT ON SEQUENCES TO cajeer_runtime;
```

В runtime `.env` укажите `DB_SCHEMA=cajeer`, `DB_USERNAME=cajeer_runtime` и его пароль. Не храните migration credentials в `.env`, не передавайте их PHP-FPM/workers. В доверенном CLI:

```sh
export DB_MIGRATION_USERNAME=cajeer_migrator
read -rs -p 'Migration password: ' DB_MIGRATION_PASSWORD; echo
export DB_MIGRATION_PASSWORD
php bin/cajeer install:check
php bin/cajeer install
# При обновлениях: php bin/cajeer migrate
unset DB_MIGRATION_USERNAME DB_MIGRATION_PASSWORD
php bin/cajeer db:doctor
```

Установка создаёт таблицы от migration role и администратора от runtime role. `migrate:status` и readiness используют runtime role. После первой установки дополнительно ограничьте `schema_migrations`:

```sql
REVOKE INSERT, UPDATE, DELETE ON cajeer.schema_migrations FROM cajeer_runtime;
GRANT SELECT ON cajeer.schema_migrations TO cajeer_runtime;
```

Для существующей БД перенос владения объектами и выдача прав выполняются DBA в окно обслуживания; не меняйте владельца всего кластера. Проверяйте `db:doctor` после изменения прав. Backup читает текущую схему от runtime role; restore выполняйте в отдельную пустую БД от её владельца (временно передав owner credentials через `DB_USERNAME`/`DB_PASSWORD`), затем DBA выдаёт runtime grants, проверяет права и лишь после этого переключает приложение. Для PgBouncer/отдельных TLS настроек backup/restore поддерживают действующие DB_* параметры.

## Метрики

`/metrics` дополняет прежние gauges: `cajeerlogs_postgres_available`, `cajeerlogs_postgres_connections`, `cajeerlogs_postgres_probe_seconds`, `cajeerlogs_postgres_connect_seconds`, `cajeerlogs_postgres_connect_attempts`, `cajeerlogs_postgres_connect_failed_attempts`. Последние три описывают сбор метрик в текущем процессе, а не глобальную сумму. Счётчики `sessions_total`, `sessions_abandoned_total`, `sessions_fatal_total`, `sessions_killed_total`, `xact_commit_total`, `xact_rollback_total`, `deadlocks_total`, `active_seconds_total` (префикс `cajeerlogs_postgres_`) считываются из `pg_stat_database`; это статистика всей текущей БД, включая другие схемы. `stats_reset_timestamp_seconds` позволяет видеть сброс статистики. Probe — реальный `SELECT 1`, а active time — суммарное время активности backend, не распределение latency отдельных запросов. При недоступности БД `available=0`, без фиктивных значений и без многократного независимого retry за один scrape.

## Тестирование

```sh
php tests/postgres_runtime.php
CAJEER_TEST_DATABASE=1 php tests/postgres_runtime_database.php
```

Тест PostgreSQL требует disposable DB и пользователя с правом создавать схему и завершать собственные backend. Он проверяет реальные SET, pg_terminate_backend, statement timeout, lease и непрерывный worker. GitHub Actions проверяет PHP 8.4/8.5 с PostgreSQL 17/18, дополнительно restart disposable PostgreSQL container, verified TLS, PgBouncer session pool и отдельные роли. Не запускайте destructive CI helpers на production. Локальные тесты с doubles не заменяют эти проверки.
