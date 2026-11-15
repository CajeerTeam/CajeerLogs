# Log Search — 0.2.8

Рабочий PostgreSQL Explorer поверх событий, принятых через `/api/v1/ingest`. Не требует дополнительного поискового сервера. PostgreSQL 17+, PHP 8.4+, `pdo_pgsql`.

## Обновление 0.2.7 → 0.2.8

1. Сделайте backup PostgreSQL и файлов приложения, остановите HTTP ingest и workers.
2. Наложите ZIP изменённых файлов на корень 0.2.7.
3. Выполните `php bin/cajeer version`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify`, `php bin/cajeer doctor`, `php bin/cajeer openapi:lint`.
4. Перезапустите PHP-FPM/worker и проверьте `/ready` и авторизованный поиск. Версия — 0.2.8.

Новой миграции нет; 0001–0014 неизменны. Используются существующие GIN-индексы FTS, context и tags, индексы project/source/time, project/level/time и project/trace/request/time. Фильтр slug разрешается через реестр `sources` внутри проекта, затем применяется к `source_id`, что позволяет использовать имеющийся индекс project/source/time. План выбирает PostgreSQL; реальный выигрыш требует EXPLAIN ANALYZE и измерений на рабочих данных. Новые индексы service/host и универсальные JSON-path индексы не добавляются без результатов измерений.

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

## Расширенный поиск 0.2.8

Все новые фильтры поддерживаются в `/logs`, `/logs/stats`, `/logs/export`. Для detail разрешены только прежние project_id/source_id.

| Параметр | Формат и семантика |
|---|---|
| `q_mode` | `web` (по умолчанию), `plain` (все слова), `phrase` (соседние токены) |
| `levels` | JSON-массив PSR-3 уровней, например `["error","warning"]` |
| `sources` | JSON-массив зарегистрированных slug |
| `source_ids` | JSON-массив UUID источников |
| `services`, `hosts` | JSON-массивы точных строк |
| `trace_ids`, `request_ids` | JSON-массивы точных идентификаторов для просмотра цепочек |
| `tags_all` | Все перечисленные теги присутствуют (`@>`) |
| `tags_any` | Хотя бы один тег присутствует (`&&`) |
| `context_path` | JSON-массив из 1–8 непустых строковых сегментов, например `["nested","region"]` |
| `context_equals` | Точное JSON-скалярное значение в указанном пути: строка, число, boolean, null |

Списки передаются одной URL-encoded строкой JSON, не `levels[]=...` и не повторяющимися query parameters. Внутри каждого списка — OR, кроме tags_all; между всеми фильтрами — AND. Старые одиночные фильтры можно сочетать со списками, они также соединяются AND. Списки канонизируются (сортировка/устранение повторов), поэтому перестановка не меняет cursor fingerprint. Изменение множества значений или других фильтров меняет fingerprint. `q_mode=web` сохраняет прежний default fingerprint. `context` и `context_equals` сохраняют исходные JSON-байты для точного сравнения; изменение их сериализации может потребовать новую первую страницу.

Пример реального HTTP-запроса:

```bash
curl --fail-with-body -sS -G "$CAJEER_URL/api/v1/logs" \
  -H "Authorization: Bearer $CAJEER_READ_TOKEN" \
  --data-urlencode "project_id=$CAJEER_PROJECT" \
  --data-urlencode 'levels=["error","warning"]' \
  --data-urlencode 'sources=["app","worker"]' \
  --data-urlencode 'q=database connection' \
  --data-urlencode 'q_mode=phrase' \
  --data-urlencode 'tags_any=["db","queue"]' \
  --data-urlencode 'from=2026-11-14T00:00:00Z' \
  --data-urlencode 'to=2026-11-15T00:00:00Z' \
  --data-urlencode 'context_path=["nested","region"]' \
  --data-urlencode 'context_equals="eu"'
```

JSON-путь использует PostgreSQL `#>` с привязанным `text[]`; это не произвольный JSONPath/SQL/regex. Числовые сегменты строкового пути могут индексировать массивы. Без context_equals проверяется существование (включая JSON null); JSON null отличается от отсутствующего пути. `123` и `"123"` различны. Точный numeric JSON передаётся без PHP/JS округления; значение, которое ingestion нормализовал в строку, нужно искать как строку. Для поиска path обязательны `from/to` до 31 суток: общий GIN containment не гарантирует ускорение произвольного `#>`.

FTS использует `websearch_to_tsquery`, `plainto_tsquery` или `phraseto_tsquery` с конфигурацией `simple`, существующим search_vector и GIN. Web поддерживает кавычки, OR и исключение через минус; plain интерпретирует слова без web-операторов; phrase требует последовательность токенов. Нет морфологии, wildcard-подстрок или произвольного tsquery. Поиск по токенам текста нечувствителен к регистру согласно PostgreSQL; точные metadata/tag/JSON значения чувствительны к регистру. PostgreSQL `numnode` ограничен 128; `querytree` без индексируемого положительного терма требует диапазон до 31 суток. Пустой tsquery даёт пустую выдачу. FTS работает по message/source/service, не по всему context.

Источник из credential всегда применяется дополнительным SQL-предикатом. Список чужих source UUID не расширяет scope; результат будет пустым или только из разрешённого источника. Явный одиночный чужой source_id по-прежнему даёт 403. Данные чужого проекта недоступны.

### Ограничения и эксплуатация

- 16 элементов на список, 64 суммарно до дедупликации; строковые размеры соответствуют одиночным полям. До 16 КиБ декодированных значений query, raw query до 64 КиБ (gateway может иметь меньший предел).
- Строгий raw URL parser отклоняет повторные ключи, bracket notation, неоднозначные PHP-normalized имена и неверные percent escapes. Все значения параметризованы. JSON-фильтры отклоняют дубли ключей, невалидный UTF-8/NUL, непредставимые числа, превышение глубины/размера.
- `SEARCH_TIMEOUT_MS=5000` (100..60000) задаёт HTTP search budget, ограниченный `DB_STATEMENT_TIMEOUT_MS`. После авторизации запросы list/detail/stats/export выполняются в read-only транзакции; `SET LOCAL statement_timeout` ограничивает SQL preflight/чтение и автоматически восстанавливается после commit/rollback. SQLSTATE 57014 возвращает HTTP 503 `search_timeout`, `Retry-After: 5`. Сократите диапазон или добавьте селективный фильтр. Бюджет не включает время подключения, schema guard, auth/rate limiter и сетевую отправку ответа.
- `/metrics` экспортирует `cajeerlogs_search_requests_total`, `cajeerlogs_search_rejections_total`, `cajeerlogs_search_duration_seconds` (histogram), `cajeerlogs_search_metrics_available`. Labels: mode list/detail/stats/export, status/reason; нет query, UUID или credential. Агрегаты локальны для storage/cache и сбрасываются при его очистке.
- Admin Panel: дополнительные фильтры поддерживают все новые параметры, сохраняют исходное JSON-значение для точного numeric поиска. Не помещайте секреты в поисковые поля/URL; production Nginx не логирует query string.

### EXPLAIN

```bash
php bin/cajeer db:explain "$CAJEER_PROJECT" \
  --filters '{"levels":"[\"error\",\"warning\"]","sources":"[\"app\"]","q":"database"}' \
  --analyze
```

`plans.filtered` использует фактический `LogRepository::pageStatement`; `--analyze` выполняет read-only SELECT и возвращает фактический план/BUFFERS. Без флага только оценка. CLI может включать значения фильтров в JSON-план; не публикуйте конфиденциальные параметры. CLI использует штатный DB statement timeout, а не HTTP budget. Синтетические локальные тесты не подтверждают производительность на production.

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
php tests/search_runtime.php
php bin/cajeer openapi:lint
# Только на отдельной тестовой БД PostgreSQL 17+, с корректными DB_*:
CAJEER_TEST_DATABASE=1 php tests/search_database.php
```

Интеграционный тест создаёт случайную изолированную схему, прогоняет миграции до 0004, записывает реальные события, применяет 0005, проверяет FTS/фильтры/пагинацию/статистику/экспорт/сессии/изоляцию, запускает сетевой HTTP-запрос и удаляет тестовую схему. CI запускает его на PostgreSQL 17. Для эксплуатации используйте PHP-FPM, TLS, ограничение ресурсов и проверенные backup/restore. Встроенный сервер PHP — только для разработки. Графический Log Explorer доступен с 0.0.6; альтернативные поисковые движки не подключены.
