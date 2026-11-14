# CajeerLogs 0.2.0 — Database Core Release

0.2.0 объединяет PostgreSQL Runtime, Migration Engine, Transaction Management, Log Repository, Installer & Administrator, Project/Source/Token Management и Database Integrity & Performance. Новых numbered migrations нет: `0001–0012` остаются неизменными. Версия API v1 и SDK сохраняет обратную совместимость. Канал public-preview сохраняется, пока нет результатов обязательной матрицы интеграции и production smoke; номер релиза сам по себе не является сертификацией.

## Установка и обновление

На предыдущей версии остановить ingest и фоновые workers, создать `backup:create`, сохранить конфигурацию и секреты отдельно. Наложить архив изменений на корень предыдущей версии. Для перехода с 0.1.0 использовать полный проверенный дистрибутив 0.2.0, не архив изменений относительно 0.1.9.

```sh
php bin/cajeer version
php bin/cajeer migrate:plan
php bin/cajeer migrate
php bin/cajeer migrate:verify
php bin/cajeer db:verify
php bin/cajeer db:doctor
php bin/cajeer doctor
php bin/cajeer openapi:lint
```

0.1.9 уже содержит схему 0012; `migrate` повторно проверяет её. При переходе с 0.1.0 создаются служебные таблицы Migration Engine, исходная структура сверяется с reference schema и применяются 0009–0012. При разделённых ролях требуются CLI migration credentials и право CREATE на БД для первоначальной проверки старой схемы. Запускать после успешных проверок. `db:integrity` может сканировать большие таблицы: выполнять вне пикового времени. При нарушениях не продолжать развёртывание до диагностики. Команда `db:verify [limit]` проверяет актуальность схемы, реальные права и readiness, наличие активного администратора и логический аудит под общей schema guard; возвращает 0 при успехе, 1 при недоступности/незавершённой установке, 2 при логических нарушениях. Она не заменяет интеграционную сертификацию. Новая установка: [First-Public-Release.md](First-Public-Release.md), [Installer-Administrator.md](Installer-Administrator.md).

## Восстановление без публикации непроверенной БД

`backup:restore ID TARGET_DATABASE` по-прежнему требует отдельную пустую БД, владельцем которой является настроенная роль приложения. До запуска `pg_restore` устанавливается постоянный database-level параметр `cajeerlogs.restore_state=pending`. Runtime проверяет его по `pg_catalog.pg_db_role_setting` при создании PDO, readiness и получении migration guard. Он сохраняется после завершения аварийного процесса, потери сети и SQL-ошибки. Обычное PostgreSQL CLI подключение для DBA остаётся доступным. Это ограничение CajeerLogs 0.2.0, не запрет произвольного SQL-доступа и не защита от DBA или старого runtime.

`pg_restore` выполняется в отдельной транзакции, после чего исходная сессия проверяет историю объявленной версии, выполняет необходимые миграции и полный логический аудит `IntegrityInspector`. При несовпадении, нарушении или таймауте целевая БД не публикуется. После успешного аудита отзыв сессий, API-токенов и recovery codes и сброс database-level маркера выполняются одной транзакцией. Результат содержит `integrity_verified: true` только после подтверждённого COMMIT. Неопределённый COMMIT требует проверки фактического состояния; не переключать production по сообщению об ошибке. При ошибке предпочтительно удалить **только отдельную целевую БД** и создать её заново, а не сбрасывать marker вручную. Исходная БД не меняется. Бэкапы 0.0.9–0.2.0 поддерживаются при точном совпадении истории, предыдущие версии не принимаются. Логическая проверка не заменяет физическую проверку страниц, не восстанавливает внешние секреты и не гарантирует отсутствие дублированной внешней webhook-доставки после отката во времени. Перед запуском workers сверить внешние эффекты.

## Обязательный release gate

GitHub Actions строит один полный ZIP 0.2.0 и SHA-256 (`candidate-uncertified`). Четыре независимых job PHP 8.4/8.5 × PostgreSQL 17/18 используют **тот же ZIP**, а не произвольное состояние checkout. После проверки полного файла `scripts/certify.py database` распаковывает его, узнаёт реальные версии PHP и PostgreSQL, запускает 14 интеграционных наборов и два upgrade сценария с настоящими исходниками 0.1.0 и 0.1.9. Остальные обязательные шаги CI: PHPUnit/PHPStan, TLS/PgBouncer и recovery/restart, split-role, SDK, браузерный E2E. Артефакт database report публикуется только после успеха job. Итоговый job проверяет точный SHA-256 кандидата, все четыре отчёта, отсутствие пропущенных/неуспешных этапов; запускает production HTTPS/PHP-FPM/PostgreSQL smoke на том же дистрибутиве. Smoke включает backup/restore, два workers и HTTP benchmark со сверкой UUID. Только после успешного завершения публикуется `CajeerLogs-0.2.0-certified-ci` с `database-certification.json`. Отчёт содержит фактические версии, exit codes, длительности этапов и хеш ZIP. Он не является цифровой подписью или независимой сертификацией безопасности. Нагрузочный smoke не задаёт SLA и не заменяет длительное испытание.

### Локальное воспроизведение с настоящим PostgreSQL

Подготовить **три отдельные тестовые БД**: основную и две пустые для restore. Все должны принадлежать тестовой LOGIN role; не использовать production. Задать переменные `APP_ENV=test`, `CAJEER_TEST_DATABASE=1`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE`, `CAJEER_TEST_RESTORE_DB`, `CAJEER_TEST_UPGRADE_RESTORE_DB`, `PG_DUMP_BIN`, `PG_RESTORE_BIN`. Установить PHP 8.4/8.5 с pdo_pgsql, PostgreSQL 17/18, соответствующие client tools и Python 3. Предыдущие полные исходные деревья должны быть из настоящих релизов, а не имитацией текущей версии. CI использует точные Git-коммиты из истории репозитория; если история недоступна, укажите локальные полные архивы этих версий.

```sh
python3 scripts/release.py build release-out/CajeerLogs-0.2.0.zip
python3 scripts/certify.py database release-out/CajeerLogs-0.2.0.zip \
  --from-010 /tmp/cajeer-0.1.0 --from-019 /tmp/cajeer-0.1.9 \
  --output /tmp/database-certification.json
```

Ненулевой exit code означает, что обязательные проверки не прошли. Отсутствие PostgreSQL, driver, client tools, правильных предыдущих исходников или результатов матрицы — ошибка, а не пропуск. После четырёх успешных прогонов поместить JSON в разные подкаталоги каталога evidence, затем выполнить `python3 scripts/certify.py release release-out/CajeerLogs-0.2.0.zip --reports /tmp/evidence --output /tmp/release-certification.json`. Требуются Docker Compose и OpenSSL. Локальный runner не запускает дополнительные CI-only проверки TLS/PgBouncer, browser E2E и split-role; полный workflow остаётся обязательным условием публикации. Не передавать отчёты из недоверенных источников.
