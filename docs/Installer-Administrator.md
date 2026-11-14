# 0.1.5 — Installer & Administrator

## Требования и границы

PHP 8.4+, `pdo_pgsql`, `sodium`, Argon2id, PostgreSQL 17/18. Для production — корректный HTTPS `APP_URL` (origin без пути), `APP_DEBUG=false`. Запускайте доверенный CLI от пользователя приложения. Установщик не требует PostgreSQL superuser; создание базы, LOGIN-ролей и паролей остаётся задачей DBA. Для обычной установки с одной ролью подготовьте БД и схему по [Database-Installer](Database-Installer.md). Для раздельных ролей см. ниже. Web installer по-прежнему отключён.

В каталоге конфигурации и `storage/` должно быть не менее 1 МиБ свободного места для установочных файлов; это минимальная проверка, а не sizing хранилища логов. Для production нужны эксплуатационные резервы диска БД, резервных копий и очереди. `.env` может находиться вне дерева проекта (`CAJEER_ENV_FILE=/config/.env`); в момент установки каталог должен быть доступен для записи. После установки runtime может работать с read-only конфигурацией.

## Раздельные роли и подготовка схемы

Для новой выделенной БД DBA создаёт роли (выполняйте `CREATE ROLE` и `CREATE DATABASE` от администратора PostgreSQL; задайте пароли через `\password`, не в истории SQL):

```sql
CREATE ROLE cajeer_migrator LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE ROLE cajeer_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
\password cajeer_migrator
\password cajeer_runtime
CREATE DATABASE cajeer_logs;
GRANT CONNECT ON DATABASE cajeer_logs TO cajeer_migrator,cajeer_runtime;
GRANT CREATE ON DATABASE cajeer_logs TO cajeer_migrator;
```

Настройте `.env` (копия `.env.example`, `chmod 600 .env`) с `DB_SCHEMA=cajeer`, `DB_USERNAME=cajeer_runtime`, `DB_PASSWORD`, `DB_DATABASE=cajeer_logs`, `DB_HOST`, TLS и остальными runtime-параметрами. Из доверенного CLI передайте `DB_MIGRATION_USERNAME=cajeer_migrator`, `DB_MIGRATION_PASSWORD` из защищённого окружения, затем:

```sh
php bin/cajeer install:prepare-schema --confirm
php bin/cajeer install:check
php bin/cajeer install --non-interactive
unset DB_MIGRATION_USERNAME DB_MIGRATION_PASSWORD
php bin/cajeer install:status
php bin/cajeer doctor
```

`install:prepare-schema` реально выполняет DDL в транзакции под блокировкой установки, создаёт новую выделенную схему (или повторно подготавливает принадлежащую migrator пустую схему), выдаёт USAGE и scoped default privileges на будущие таблицы/последовательности. Проверяет, что runtime-роль существует, имеет LOGIN/CONNECT, не имеет перечисленных повышенных атрибутов и не наследует CREATE в схеме. Проверяет действительное runtime-подключение после COMMIT. При неверном runtime-пароле пустая схема остаётся; исправьте пароль и повторите. Команда не работает с `public`, непустой схемой, чужим владельцем, одинаковыми ролями или суперпользователем. DBA отвечает за membership и внешние ACL. Для уже существующей БД пользуйтесь ручной процедурой [PostgreSQL-Runtime](PostgreSQL-Runtime.md), не запускайте prepare-schema для миграции владельцев.

После установки можно убрать временное право CREATE на БД у migrator, если оно не нужно для других задач (при adoption старой схемы Migration Engine может потребовать его снова). Повышенные credentials не записываются в `.env`. `doctor` проверяет runtime без требования DDL-прав и записи в конфигурационный каталог; `install:check` предназначен для установочного окружения и проверяет право миграции.

## Установка и восстановление после прерывания

1. `php bin/cajeer install:check` — проверка PHP, pdo_pgsql, Argon2id, каталогов, свободного места, HTTPS и подключения к PostgreSQL с правами миграции.
2. `php bin/cajeer install` — интерактивный ввод и скрытое подтверждение пароля первого администратора. Для автоматизации задайте `CAJEER_ADMIN_EMAIL` и один из `CAJEER_ADMIN_PASSWORD` или `CAJEER_ADMIN_PASSWORD_FILE` в окружении процесса. Последний указывает на обычный файл с правами 0600/0400; допускается один завершающий LF/CRLF. Пароли и пути к secret-файлам не должны находиться в `.env`.
3. `php bin/cajeer install --non-interactive` — выполняет реальное подключение, проверку текущей схемы и credentials существующего администратора, атомарную запись конфигурации, необходимые миграции, создание/проверку администратора и итоговую диагностику с заново загруженной конфигурацией.
4. `php bin/cajeer install:status`, `php bin/cajeer migrate:verify`, `php bin/cajeer doctor` — проверка завершения.

Установщик сериализует запуск через локальные locks в storage и рядом с конфигурацией, плюс PostgreSQL session advisory lock для выбранной схемы. Lock-файлы не удаляйте на работающем сервере. При сбое `.env` остаётся на месте с прежними секретами, подтверждённые миграции сохраняются, неуспешная транзакция откатывается. Повторите **тот же** install с теми же реквизитами. После миграций запись `settings['installation.completed']` и событие audit создаются атомарно с первым администратором; на legacy-инсталляции существующий администратор подтверждает запись. Она хранит ID администратора, время и origin, не пароль. Не удаляйте эту запись вручную. Схема SQL 0001–0008 неизменна.

Повторный запуск с существующим активным администратором и корректным паролем не создаёт другого администратора и не меняет пароль. После завершённых миграций повторный запуск не требует повышенной роли. Неправильные credentials отвергаются до записи `.env`; неявное переключение настроенной БД и ротация существующих секретов отвергаются. При ошибке TOTP-ключа существующих enrollment запись конфигурации отклоняется. Сохраняйте original `.env` и ключи в защищённом backup. Если требуется сменить DB target/секреты, используйте отдельную DBA/операционную процедуру, а не повторный install.

`install:status` возвращает JSON без паролей, токенов и DSN. Код 0 только при `ready`. Возможные состояния: `database_unavailable`, `schema_unavailable`, `migration_required`, `schema_drift`, `administrator_required`, `administrator_recovery_required`, `configuration_or_permissions_required`, `ready`. `completion_recorded=false` на работающей 0.1.4 после обновления допустимо: `ready` определяется реальной схемой, наличием активного администратора и runtime-проверками. Для записи marker можно один раз выполнить install с корректными credentials; это не обязательная миграция данных.

## Восстановление административного доступа

Только доверенный оператор, имеющий доступ к серверному CLI и DB credentials приложения:

```sh
php bin/cajeer admin:reset-password admin@example.com --confirm
```

Для автоматизации задайте `CAJEER_ADMIN_NEW_PASSWORD_FILE` (0600/0400) либо `CAJEER_ADMIN_NEW_PASSWORD` в окружении. Пароль 12–1024 байт, без NUL. Для заблокированного администратора требуется явное `--unblock` после `--confirm`. Команда не создаёт пользователя, не повышает роль обычного пользователя и не сбрасывает TOTP. Обновление Argon2id-хеша, отзыв браузерных сессий, отмена незавершённой настройки TOTP и аудит выполняются одной транзакцией под identity lock и migration guard. Сервисные API-токены не затрагиваются. Если утрачен второй фактор, используйте отдельную существующую `auth:2fa-reset EMAIL --confirm`; при обязательной 2FA администратор вновь проходит enrollment. Не отключайте 2FA ради смены пароля. При отсутствии любой учётной записи administrator в ранее завершённой установке требуется контролируемое восстановление backup/DBA, а не автоматическое создание нового администратора.

## Обновление 0.1.4 → 0.1.5

Остановите запись и workers, создайте backup PostgreSQL, сохраните `.env` и приложение. Наложите changed-files ZIP, выполните `php bin/cajeer version`, `php bin/cajeer migrate:verify`, `php bin/cajeer install:status`, `php bin/cajeer doctor`. Новых SQL-миграций нет; backup 0.1.4 совместим. Перезапустите PHP-FPM, queue, scheduler и webhooks. Для production Compose пересоберите образы 0.1.5, сохраните volumes, не запускайте второй независимый installer одновременно.

## Проверки

`php tests/installer.php` — локальные проверки защищённого файла, secret preservation, preflight и отсутствия ложного успеха. `CAJEER_TEST_DATABASE=1 php tests/database.php` на отдельной PostgreSQL-БД — частичная установка, реальный advisory lock, создание администратора, marker/audit, повторная установка, неверные credentials, password recovery и сохранение TOTP/token. `tests/postgres_split_role.php` с CI provisioning — создание схемы, default privileges, runtime connection, миграции, повторный install без DDL-role и runtime doctor. CI запускает PostgreSQL 17/18 и PHP 8.4/8.5. Не запускайте интеграционные тесты на production-БД.
