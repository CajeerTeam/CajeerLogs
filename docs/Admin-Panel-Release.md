# CajeerLogs 0.4.0 — Admin Panel Release

0.4.0 объединяет административные функции 0.3.1–0.3.9 в работающую PHP/PostgreSQL SPA. API v1, PHP 8.4/8.5, PostgreSQL 17/18 и существующие SDK сохранены. Номер релиза не означает прохождения сертификации: канал `public-preview` сохраняется до прохождения CI для точного ZIP.

## Обновление с 0.3.9

1. Остановить веб-трафик и workers; выполнить `php bin/cajeer backup:create`. Сохранить конфигурацию, ключи шифрования и сертификаты отдельно в защищённом хранилище.
2. Распаковать changed-files ZIP в корень существующего проекта с заменой файлов. Не менять `.env`, содержимое `storage/` и существующие миграции. При Docker deployment пересобрать образы приложения.
3. Проверить:

```sh
php bin/cajeer version             # 0.4.0
php bin/cajeer migrate:status
php bin/cajeer migrate:verify
php bin/cajeer db:verify
php bin/cajeer doctor
php bin/cajeer openapi:lint
npm --prefix admin run build      # только для проверки, Node не нужен в production
```

Миграции 0001–0016 не меняются; новой миграции нет. Если предыдущая установка имеет pending migrations, применить их штатным `php bin/cajeer migrate`. После успешных проверок запустить PHP-FPM/web server/workers. Для возврата использовать сохранённые 0.3.9 файлы и конфигурацию; при восстановлении БД следовать [Backup & Recovery](Database-Core-Release.md), не перезаписывать живую БД.

## Рабочие разделы и сквозной сценарий

| Раздел | Операции |
| --- | --- |
| Обзор | Периоды, сравнение, реальные агрегаты PostgreSQL, графики, активность источников, drill-down |
| Логи | Live/Snapshot, подписанные курсоры, колонки, фильтры, личные Saved Views, экспорт страницы CSV/JSON |
| Инспектор события | JSON Tree, поиск, JSON Pointer, исходные числовые литералы, связанные Trace/Request ID |
| Проекты и источники | Каталог, участники, состояния, квоты, retention, redaction, мастер подключения |
| API-токены | Выдача, отзыв, ротация, сервисные аккаунты, HMAC v1/v2, завершение overlap |
| Безопасность и аудит | Проектный журнал для эффективного Operator/Administrator, глобальный журнал только Administrator, переходы к credentials, пользователям и собственным сессиям |
| Пользователи | Каталог, карточка, роли, project membership, административные сессии и 2FA reset |
| Система | Диагностика runtime, readiness и БД |
| Настройки | Профиль, пароль, TOTP, recovery-коды, активные сессии и темы |

Начните с администратора: создать проект → источник (мастер подключения) → scoped `logs:write` токен и отдельный HMAC v2 ключ → отправить подписанный тест → убедиться, что receipt соответствует сохранённому событию → открыть Explorer → применить фильтр и сохранить представление → открыть инспектор/экспорт → перейти в Dashboard → назначить Operator/Viewer → проверить эффективный доступ → ротировать и отозвать credentials → проверить аудит.

Смена проекта проходит через единый `selectProject`: фильтры, курсоры, Dashboard и состояние каталога источников сбрасываются. Переход в защищённый проектный раздел повторно запрашивает метаданные проекта и эффективную роль. При фокусе/возврате в вкладку и периодической проверке сессии повторно проверяется доступ к выбранному проекту. Если он отозван (403/404), выбор удаляется, предыдущий экран и модальные диалоги закрываются, список доступных проектов запрашивается заново. Следующий доступный проект может быть выбран автоматически; если их нет, отображается пустое состояние. Сетевая ошибка не означает отзыв членства. Backend остаётся единственным источником истины и проверяет права на каждом защищённом запросе; уже скопированные/экспортированные данные отозвать невозможно. Пользователь с глобальной ролью Operator и проектной Viewer не видит управления токенами и проектного аудита. Раздел Security не создаёт нового хранилища аудита и не обещает криптографическую неизменяемость.

## Безопасность production

- DocumentRoot строго `public/`; HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, внешний `APP_URL` должен совпадать с origin. `SECURITY_2FA_REQUIRED_FOR_ADMINS=true` и случайный 32-байтовый hex `SECURITY_TOTP_KEY`; не менять существующий ключ без процедуры миграции секретов.
- PHP runtime и миграционная роль разделяются согласно [PostgreSQL Runtime](PostgreSQL-Runtime.md). Не предоставлять runtime лишние права DDL. Проверять backup restore в отдельной БД.
- Cookie/CSRF и Bearer/HMAC остаются раздельными. Не хранить секреты в localStorage, URL, Git или логах. Для событий включать redaction; UI отображает только серверные данные.
- Установка и запуск: [Admin Panel](Admin-Panel.md), [Production deployment](Nginx-deployment.md), [Authentication & Sessions](Authentication-Sessions.md), [Token Management & Security](Token-Management-Security.md).

## Release gate

Сборка одного детерминированного **полного** ZIP кандидата (не changed-files patch):

```sh
python3 scripts/release.py build release-out/CajeerLogs-0.4.0.zip
(cd release-out && sha256sum -c CajeerLogs-0.4.0.zip.sha256)
python3 scripts/release.py verify release-out/CajeerLogs-0.4.0.zip
```

CI использует SHA-256 этого архива. Четыре `database` evidence report (PHP 8.4/8.5 × PostgreSQL 17/18) обязательны. Исторические деревья для upgrade берутся из действительных релизных коммитов, включая 0.3.9; при отсутствии исходного релиза проверка должна завершиться ошибкой. Схема, миграции, очереди, ingest/search, изоляция, HMAC и восстановление проверяются на реальной БД. Upgrade проверяет сохранение старой сессии, токена, событий, job и idempotency receipts.

Дополнительный gate `admin` запускает проверки именно из распакованного полного ZIP, а не из рабочего checkout. На изолированной тестовой PostgreSQL БД в CI:

```sh
python3 -m venv /tmp/cl-browser
/tmp/cl-browser/bin/pip install 'playwright==1.52.0'
/tmp/cl-browser/bin/python -m playwright install --with-deps chromium firefox webkit
# APP_ENV=test, CAJEER_TEST_DATABASE=1, DB_* и отдельные restore БД;
# PG_DUMP_BIN и PG_RESTORE_BIN — пути к matching PostgreSQL binaries.
/tmp/cl-browser/bin/python scripts/certify.py admin release-out/CajeerLogs-0.4.0.zip \
  --output /tmp/admin-certification.json
```

`admin` обязан выполнить frontend build, сетевую Chromium UI regression с контролируемыми API-ответами, затем три настоящих browser → PHP HTTP → PostgreSQL E2E: Chromium, Firefox, WebKit. Последние создают временную случайную схему, выполняют сценарии login/session, CRUD, ingest, search, экспорт, роли и отзыв membership, scoped HMAC v2 onboarding, аудит и TOTP/recovery, затем удаляют схему. Offline-режим разрешён только для локальной регрессии, не для gate.

Финальная проверка требует четыре database reports, отдельный admin report, идентичный хеш кандидата, полный порядок успешных этапов и нулевые exit codes. После этого запускается реальный Docker production smoke с TLS, PHP-FPM, Nginx и PostgreSQL:

```sh
python3 scripts/certify.py release release-out/CajeerLogs-0.4.0.zip \
  --reports /tmp/database-evidence --admin-report /tmp/admin-certification.json \
  --output release-out/database-certification.json
```

Отчёты — свидетельства доверенного CI, не цифровые подписи и не независимая security-сертификация. Не подменять реальные отчёты синтетическими. Отсутствующая, неуспешная, дублирующаяся или относящаяся к другому ZIP проверка запрещает выдачу certified CI artifact. Успешные runtime/unit/offline проверки не заменяют ни PostgreSQL E2E, ни Docker production smoke.
