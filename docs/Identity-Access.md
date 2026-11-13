# Identity & Access 0.0.3

## Обновление и deployment

Поддерживаются PHP 8.4+ с Argon2id, PDO/pdo_pgsql и PostgreSQL 17+. Перед обновлением создайте резервную копию PostgreSQL, проверьте её восстановление и временно остановите запись. Наложите изменённые файлы на 0.0.2, затем:

```sh
php bin/cajeer migrate
php bin/cajeer migrate:status
php bin/cajeer db:doctor
php bin/cajeer version
```

`0003_identity_access.sql` добавляет членство проектов, блокировку пользователей, серверные сессии, rate-limit buckets и автора API-токена. Миграции 0001/0002 не меняются. Существующий администратор и его пароль сохраняются. Для чистой установки используйте `php bin/cajeer install` ([инструкция](Database-Installer.md)). Откат к 0.0.2 после миграции не поддерживается автоматически: восстановите согласованные копии кода и БД.

В production обязательно `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://logs.example.com`. Авторизационные маршруты требуют реальный HTTPS, переданный PHP через серверную переменную `HTTPS=on` или `1`. В Nginx/PHP-FPM используйте TLS-конфигурацию из `examples/nginx/cajeerlogs.conf` с действующими сертификатами, сокетом и доменом. За TLS-терминирующим reverse proxy задавайте `HTTPS=on` только на внутреннем доверенном TLS-маршруте, запретив прямой доступ к backend. Не устанавливайте его по произвольному клиентскому `X-Forwarded-Proto`.

`APP_ENV=local`/`test` разрешает HTTP для локального тестирования; не публикуйте такой режим в интернете. `APP_URL` должен соответствовать origin браузера (scheme, host, port). Cookie host-only, HttpOnly, SameSite=Strict, Secure на HTTPS, Path=/; для production используйте отдельный hostname для CajeerLogs.

## Сессии

`POST /api/v1/auth/login` принимает JSON `email`, `password`. Успех: HTTP 200, JSON `user`, `csrf_token`, `expires_in` и `Set-Cookie: cajeerlogs_session=...`. Срок сессии — 8 часов (абсолютный, без продления). Идентификатор — случайные 256 бит, в БД только SHA-256 digest. Новая авторизация ротирует идентификатор и аннулирует предоставленную старую сессию. `GET /auth/me` возвращает актуального пользователя и CSRF token; секрет сессии не возвращается в JSON. Передавайте cookie и `X-CSRF-Token` для POST/PATCH/PUT/DELETE. Любой явно указанный посторонний Origin и `Sec-Fetch-Site: cross-site` блокируются при login и изменениях. API не разрешает cross-origin credentials через CORS.

`POST /auth/logout` отзывает текущую сессию, `POST /auth/logout-all` — все сессии пользователя. `POST /auth/password` требует `current_password` и новый `password`, после смены все сессии отзываются. Администратор может изменить email, роль, пароль и blocked status пользователя через PATCH /users/{user}; его сессии также отзываются. Пароли — Argon2id, 12–1024 байта. Last usable administrator cannot be blocked or demoted; проверки и административные записи сериализуются advisory lock в транзакции. Для записей, уже принятых до отзыва, завершение текущего запроса возможно; новые запросы проверяют текущее состояние.

## Роли и проекты

| Операция | Administrator | Operator | Viewer |
|---|---|---|---|
| Пользователи и роли | Все | Нет | Нет |
| Создание проектов и членство | Все | Нет | Нет |
| Просмотр проектов/источников | Все | Только членство | Только членство |
| Создание источников и управление токенами | Все | Только членство | Нет |
| Диагностический отчёт | Да | Нет | Нет |
| Права logs:read / logs:write (будущие log endpoints) | Оба | Оба в своих проектах | Только read в своих проектах |

Администратор создаёт проект, выдаёт пользователям членство через PUT /projects/{project}/members/{user} и отзывает через DELETE. Без членства operator/viewer не видят проект. Изменения проверяются в БД на каждом запросе, а списки фильтруются SQL. Списки используют `limit=1..100` (50 по умолчанию) и UUID `cursor` из `next_cursor`. Порядок по UUID, не по времени. Пользователи блокируются вместо физического удаления; проекты и источники в 0.0.3 создаются и просматриваются, физического удаления нет.

## API-токены

Создание: `POST /projects/{project}/tokens` с JSON `name`, `scopes` (logs:read и/или logs:write), опционально `source_id`, `expires_in` (секунды, 60..31536000, по умолчанию 30 дней). Ответ 201 содержит `id` и `token` — единственный показ секрета `cl_` + 64 hex-символа. Храните его в secret manager, не в логах или Git. В БД хранится SHA-256, а список токенов возвращает только метаданные, срок, отзыв, автора, последнее использование. Использование: `Authorization: Bearer <token>`. Нельзя одновременно посылать Bearer и session cookie. `GET /auth/token` возвращает метаданные текущего активного токена. `DELETE /projects/{project}/tokens/{token}` отзывает его.

- `logs:read` допускает чтение метаданных своего проекта и источников. Если токен ограничен источником, разрешён только GET конкретного источника, а не списка всех источников или проекта. `logs:write` проверяется как scope, но endpoint приёма логов появится в 0.0.4.
- Токен не может менять пользователей, проекты, источники, членство или создавать другие токены.
- `system:read` — отдельный глобальный токен, создаваемый только администратором через POST /system/tokens. Разрешает GET /system/report, но не управление пользователями или доступ к проектам. Отзывается DELETE /system/tokens/{token}.
- Токены являются сервисными credentials, а не пользовательскими сессиями. Они не отзываются автоматически при блокировке автора; их необходимо явно отозвать при компрометации. Старые токены 0.0.2 без проекта с log scopes либо смешанными system/log scopes отклоняются runtime; замените их корректно scoped токенами. Старые токены без expires_at сохраняют прежний срок (отсутствие expiry), рекомендуем перевыпустить.

## Пример сквозной работы

Ниже используется `jq`, shell и curl. Запускайте на доверенной машине. Пароль считывается без отображения и передаётся через stdin. Cookie jar и временные JSON содержат credentials — храните их с правами 0600 и удалите после использования.

```sh
BASE=https://logs.example.com/api/v1
umask 077
COOKIE=$(mktemp)
LOGIN=$(mktemp)
trap 'rm -f "$COOKIE" "$LOGIN"' EXIT
read -rsp 'Admin password: ' PW; echo
printf '%s' "$PW" | jq -Rs --arg email admin@example.com '{email:$email,password:.}' | \
  curl --fail-with-body -sS -c "$COOKIE" -H 'Content-Type: application/json' \
    --data-binary @- "$BASE/auth/login" > "$LOGIN"
unset PW
CSRF=$(jq -er '.csrf_token' "$LOGIN")
PROJECT=$(curl --fail-with-body -sS -b "$COOKIE" -H "X-CSRF-Token: $CSRF" \
  -H 'Content-Type: application/json' --data '{"name":"Application","slug":"application"}' \
  "$BASE/projects" | jq -er '.id')
SOURCE=$(curl --fail-with-body -sS -b "$COOKIE" -H "X-CSRF-Token: $CSRF" \
  -H 'Content-Type: application/json' --data '{"name":"Backend","slug":"backend"}' \
  "$BASE/projects/$PROJECT/sources" | jq -er '.id')
# Preserve the returned secret immediately in a secure file or secret manager.
TOKEN_FILE=$(mktemp)
jq -n --arg source "$SOURCE" '{name:"backend-reader",scopes:["logs:read"],source_id:$source}' | \
  curl --fail-with-body -sS -b "$COOKIE" -H "X-CSRF-Token: $CSRF" \
    -H 'Content-Type: application/json' --data-binary @- "$BASE/projects/$PROJECT/tokens" > "$TOKEN_FILE"
# Inspect the token metadata using its Bearer secret; avoid echoing the token.
TOKEN=$(jq -er '.token' "$TOKEN_FILE")
printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" | curl --fail-with-body -sS --config - "$BASE/auth/token"
unset TOKEN
curl --fail-with-body -sS -b "$COOKIE" -H "X-CSRF-Token: $CSRF" -X POST "$BASE/auth/logout"
# Revoke token when no longer needed and securely remove TOKEN_FILE.
```

## Эксплуатация и ошибки

- 401: отсутствующие, неверные, просроченные или отозванные credentials; `WWW-Authenticate: Bearer`.
- 403: роль, проект/источник, scope, CSRF или HTTPS не разрешают действие.
- 409: дублирующиеся записи, конфликт FK, попытка отключить последнего администратора.
- 422: некорректные поля; 429: лимит запросов (`Retry-After`); 503: недоступна база/настройка identity. Без успешной записи в БД login/создание токена не возвращают успех.

Rate limiting — atomic PostgreSQL UPSERT, единый для всех PHP workers: 600 identity-запросов/IP/мин по умолчанию (`API_RATE_LIMIT_PER_MINUTE`), 300 запросов на principal/мин, 20 попыток login/IP/5 мин, 10 попыток/email/5 мин, 5 изменений пароля/principal/5 мин. Это фиксированные окна, допускающие пограничные bursts. IP берётся из `REMOTE_ADDR`; настройте доверенную real-IP обработку на reverse proxy, не доверяйте произвольному X-Forwarded-For. Для публичного deployment дополнительно включите сетевые ограничения и rate limits в Nginx. Атаки на email-лимит могут временно ограничить вход жертвы, поэтому применяйте upstream throttling и мониторинг.

Аудит административных записей и login/logout сохраняется в `audit_log` (actor_id, action, target, request_id) без паролей, токенов или полного запроса. Изменение состояния и audit записи коммитятся вместе. Старые audit events не очищаются auth:cleanup; настройте отдельную политику хранения. Запускайте `php bin/cajeer auth:cleanup` каждые 15 минут через cron (`examples/cron/cajeerlogs.cron`), очистка ограничена 10000 строк каждой таблицы за проход и использует SKIP LOCKED. Не включайте ещё не реализованные workers/scheduler.

Метрики и /health не требуют авторизации; ограничьте метрики сетью. `/ready` проверяет миграции, доступ к таблицам и PostgreSQL. `/api/v1/system/report` защищён. HTTP 503 вместо успешного ответа используется при недоступной identity storage.

## Проверки и границы

`php tests/identity.php` — автономные проверки; `CAJEER_TEST_DATABASE=1 php tests/identity_database.php` — настоящая PostgreSQL 17, отдельная случайная схема, миграция 0.0.2→0.0.3, тесты API и реальный HTTP вход. CI запускает оба набора, а также `tests/database.php`, runtime и HTTP regression tests. Для тестов нужна отдельная тестовая БД с правом CREATE SCHEMA; тесты удаляют только созданную ими схему.

В 0.0.3 не реализованы ingestion, log search, графическая Admin UI, HMAC-приём событий, TOTP/recovery codes, очередь. Аккаунты с вручную выставленным two_factor_enabled не допускаются к входу до реализации TOTP; не выставляйте этот флаг самостоятельно. PostgreSQL остаётся единственной поддерживаемой БД. Перед публичным production deployment необходим успешный интеграционный прогон на целевом PostgreSQL и проверка TLS/PHP-FPM.
