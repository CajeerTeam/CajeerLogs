# Log Search & Explorer — 0.0.5

Рабочий PostgreSQL Explorer поверх событий, принятых через `/api/v1/ingest`. Не требует дополнительного поискового сервера. PostgreSQL 17+, PHP 8.4+, `pdo_pgsql`.

## Обновление 0.0.4 → 0.0.5

1. Сделайте проверенный backup PostgreSQL и файлов приложения. На время обновления остановите запись/HTTP-трафик.
2. Наложите ZIP с изменёнными файлами на корень 0.0.4.
3. Выполните `php bin/cajeer migrate`, `php bin/cajeer migrate:status`, `php bin/cajeer db:doctor`, `php bin/cajeer version`.
4. Проверьте `/ready` и авторизованный поиск. Версия должна быть `0.0.5`.

`0005_log_search.sql` добавляет индексы по проекту/источнику/времени, проекту/уровню/времени, request ID и tags. Миграции 0001–0004 не меняются. События и credentials сохраняются. Обычный `CREATE INDEX` может блокировать запись на время построения; планируйте окно обслуживания и настройте `DB_STATEMENT_TIMEOUT_MS`/`DB_LOCK_TIMEOUT_MS` для миграции на большой таблице. При ошибке runner откатывает миграцию; не редактируйте историю/checksum вручную.

## Авторизация и изоляция

Все четыре GET-операции требуют `project_id` (UUID) и действующие credentials:

- `Authorization: Bearer cl_...` с `logs:read`, привязанный к этому проекту. Токен с `source_id` автоматически ограничен этим источником, даже если фильтр не передан. Явно другой `source_id` → 403. Токен `logs:write` без `logs:read` не может читать.
- Cookie `cajeerlogs_session` после входа. Viewer/operator читают проекты, в которых состоят; administrator может выбрать любой проект явно. GET/HEAD не требуют CSRF. Cookie и Bearer одновременно не допускаются.
- В production нужен HTTPS (настроенный `APP_URL=https://…` и фактический HTTPS в PHP-FPM). Rate limit по IP и principal — `API_RATE_LIMIT_PER_MINUTE`. Отозванные/просроченные credentials → 401, недостаточные права → 403.

События без зарегистрированного проекта/источника (исторические строки до 0.0.4) не выдаются через Explorer. Деталь чужого или отсутствующего события в разрешённом проекте → 404; фильтр по чужому источнику с project-wide токеном даёт пустую выборку. Данные не возвращаются вне разрешённого проекта. Ответы не кешируются. Не передавайте токены в URL. Учитывайте, что access logs прокси могут содержать query string; не помещайте секреты в поисковые параметры.

## Поиск

```bash
export CAJEER_URL=https://logs.example.com
export CAJEER_PROJECT='YOUR-PROJECT-UUID'
# CAJEER_READ_TOKEN — действующий logs:read token из Identity & Access.
curl --fail-with-body -sS -G "$CAJEER_URL/api/v1/logs" \
  -H "Authorization: Bearer $CAJEER_READ_TOKEN" \
  --data-urlencode "project_id=$CAJEER_PROJECT" \
  --data-urlencode 'q="database connection" OR timeout' \
  --data-urlencode 'level=error' \
  --data-urlencode 'from=2026-11-14T00:00:00Z' \
  --data-urlencode 'to=2026-11-15T00:00:00Z' \
  --data-urlencode 'limit=50'
```

Ответ `200`: `{"items":[...],"next_cursor":"..."}`; пустой результат — `{"items":[],"next_cursor":null}`. Каждый event содержит `id`, `project_id`, `source_id`, `source`, `service`, `host`, `level`, `message`, `context`, `trace_id`, `request_id`, `tags`, `timestamp` (occurred_at), `received_at`. UTC RFC3339 с микросекундами; JSON context сохраняет объекты и массивы, большие целые при декодировании могут быть строками. Секреты токенов не возвращаются.

| Параметр | Поведение |
|---|---|
| project_id | Обязательный UUID проекта |
| source_id / source | UUID или точный slug источника (оба можно совместить) |
| level | Точное значение PSR-3 в нижнем регистре |
| service / host / trace_id / request_id | Точные строки |
| from / to | RFC3339 с часовым поясом и до 6 дробных цифр; `[from,to)` по времени события |
| q | FTS, до 512 байт; `simple`, слова, фразы в кавычках, OR, минус перед исключаемым словом |
| tag | Точное значение в массиве tags |
| context | JSON-объект до 4096 байт, PostgreSQL `jsonb @>` (вложенное containment) |
| limit | 1–100, default 50 |
| cursor | `next_cursor` предыдущей страницы |

Все фильтры соединены AND. FTS ищет токены в message/source/service, не подстроки и не regex; без морфологии русского языка. Параметры должны быть скалярными строками; неизвестные параметры, невалидные значения и изменённые фильтры при продолжении → 422. URL-encode значения (особенно JSON и `+` в часовом поясе).

Пагинация: `ORDER BY occurred_at DESC,id DESC`, строгая граница пары `(occurred_at,id)`, без OFFSET. Повторите исходные фильтры и передайте `cursor` из ответа. `limit` можно менять. Курсор — непрозрачная seek-позиция с fingerprint фильтров, не credential и не доверенная авторизация; все права перепроверяются. Пагинация не фиксирует snapshot между HTTP-запросами: новые события перед текущей позицией видны при обновлении первой страницы, вставки позади позиции могут появиться в следующих страницах. Для исторического обхода фиксируйте `to`.

## Просмотр события

```bash
curl --fail-with-body -sS -G "$CAJEER_URL/api/v1/logs/$EVENT_ID" \
  -H "Authorization: Bearer $CAJEER_READ_TOKEN" \
  --data-urlencode "project_id=$CAJEER_PROJECT"
```

Ответ `{"data":{...}}`. Для detail разрешены только `project_id`, опциональный `source_id`; UUID события в пути.

## Статистика

```bash
curl --fail-with-body -sS -G "$CAJEER_URL/api/v1/logs/stats" \
  -H "Authorization: Bearer $CAJEER_READ_TOKEN" \
  --data-urlencode "project_id=$CAJEER_PROJECT" \
  --data-urlencode 'from=2026-11-14T00:00:00Z' \
  --data-urlencode 'to=2026-11-15T00:00:00Z' \
  --data-urlencode 'interval=hour'
```

Те же фильтры, кроме `limit`/`cursor`; `from` и `to` обязательны, диапазон до 31 суток. `interval=hour` (default) или `day`. Один SQL-запрос/один snapshot возвращает `total`, `by_level` (включая нули), `by_source` (топ 100 групп, `source_id`, `source`, `count`), `sources_truncated`, `buckets` (непустые UTC-бакеты по возрастанию), `interval`, `from`, `to`. Пропущенные бакеты означают ноль. Суммы по уровням и бакетам относятся ко всем совпадениям; при усечении источников их сумма может быть меньше total. На больших объёмах с дорогими фильтрами возможен timeout (503); сокращайте диапазон, добавляйте фильтры.

## Экспорт

```bash
curl --fail-with-body -sS -G "$CAJEER_URL/api/v1/logs/export" \
  -H "Authorization: Bearer $CAJEER_READ_TOKEN" \
  --data-urlencode "project_id=$CAJEER_PROJECT" \
  --data-urlencode 'format=csv' --data-urlencode 'limit=100' \
  -D export.headers -o logs.csv
```

`format=json` (default) возвращает тот же JSON page, `csv` — UTF-8 CSV с заголовком, `context` и `tags` как JSON, корректно экранированные кавычки/переводы строк. Опасные spreadsheet-formula префиксы получают апостроф; JSON подходит для машинной обработки без этого преобразования. `Content-Disposition` attachment, `X-Next-Cursor` при наличии следующей страницы. Экспорт намеренно постраничный (до 100 событий), не неограниченная буферизация БД. Для полной выгрузки обходите курсоры до отсутствия заголовка/`next_cursor`; при объединении CSV сохраняйте заголовок один раз. Применяются те же ограничения доступа и фильтры. Для больших выгрузок фиксируйте диапазон.

## Тесты и эксплуатация

```sh
php tests/search.php
php bin/cajeer openapi:lint
# Только на отдельной тестовой БД PostgreSQL 17+, с корректными DB_*:
CAJEER_TEST_DATABASE=1 php tests/search_database.php
```

Интеграционный тест создаёт случайную изолированную схему, прогоняет миграции до 0004, записывает реальные события, применяет 0005, проверяет FTS/фильтры/пагинацию/статистику/экспорт/сессии/изоляцию, запускает сетевой HTTP-запрос и удаляет тестовую схему. CI запускает его на PostgreSQL 17. Для эксплуатации используйте PHP-FPM, TLS, ограничение ресурсов и проверенные backup/restore. Встроенный сервер PHP — только для разработки. Графический Log Explorer доступен с 0.0.6; альтернативные поисковые движки не подключены.
