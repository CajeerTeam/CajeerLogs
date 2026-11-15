# Pagination & Log Explorer — 0.2.9

## Обновление

1. Создайте backup текущей 0.2.8, остановите HTTP ingest и workers.
2. Наложите архив изменений, выполните `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify`, `php bin/cajeer doctor`.
3. Перезапустите PHP-FPM, queue workers, scheduler и webhook worker; проверьте `/ready`.

Схема 0014 не изменяется. Для одинаковых гарантий snapshot не оставляйте старые writers 0.2.8 в кластере. Backup 0.2.8 поддерживается. Новый обязательный env-параметр отсутствует. Старые HTTP курсоры v1 больше не принимаются: начните поиск без `cursor`.

## API

`GET /api/v1/logs` и `/api/v1/logs/export` принимают `consistency=live|snapshot` (default live), `limit=1..100`, `cursor` и все прежние фильтры. Статистика и detail не принимают `consistency`. Передайте неизменные фильтры и режим на каждой странице. Изменять limit и формат экспорта разрешено; группировка страниц при этом может измениться.

```bash
curl -sS -G "$CAJEER_URL/api/v1/logs" \
  -H "Authorization: Bearer $CAJEER_READ_TOKEN" \
  --data-urlencode "project_id=$PROJECT_UUID" \
  --data-urlencode 'consistency=snapshot' \
  --data-urlencode 'levels=["error","warning"]' \
  --data-urlencode 'limit=50'
```

Ответ содержит:

| Поле | Значение |
|---|---|
| `items` | События в порядке `occurred_at DESC,id DESC` |
| `next_cursor` | Курсор более старых записей либо null |
| `prev_cursor` | Курсор более новых записей либо null |
| `page_cursor` | Курсор для повторного запроса текущей страницы, включая первую |
| `consistency` | live/snapshot |
| `snapshot_at` | UTC граница received_at в snapshot, null в live |
| `cursor_expires_at` | Окончание срока навигации UTC |
| `page_digest` | SHA-256 JSON-массива `items` в серверной сериализации |

Для следующей страницы передайте `cursor=next_cursor`, для предыдущей — `cursor=prev_cursor`; при возврате порядок ответа остаётся descending. SQL использует строгую пару `(occurred_at,id)` без OFFSET. В направлении newer PostgreSQL читает ближайшие строки ascending, затем возвращает их в descending. Равные timestamps не теряются благодаря UUID tie-breaker. Если события были удалены или изменены, пустая страница может не иметь продолжения; начните новый поиск.

Курсор v2 — base64url-представление подписанного состояния с HMAC-SHA256, expiry 1 час от начала навигации (не продлевается на каждой странице). Привязка включает authenticated token UUID или конкретную browser session, проект, эффективный source scope, нормализованные фильтры и режим. Изменённый, просроченный, старый или переданный другой учётной записи курсор возвращает 422 `invalid_query`. Все разрешения проверяются заново. Курсоры не являются credentials и не шифруются. При ротации Bearer, новом login или изменении server master начните новый поиск.

Секрет подписи выводится из эффективного `SECURITY_TOTP_KEY`/`SECURITY_HMAC_SECRET` через HKDF с контекстом `cajeerlogs:pagination:v2`. Если master не настроен или невалиден, runtime создаёт один случайный серверный ключ в закрытой таблице `settings` (`pagination.signing_key`) через `INSERT ... ON CONFLICT DO NOTHING`. Ключ не возвращается через API, не включается в логи и сохраняется при рестартах; DB backups содержат этот секрет. Все экземпляры должны использовать одинаковую master-конфигурацию. Смена master/ключа инвалидирует старые курсоры. Часы PHP-хостов должны быть синхронизированы.

## Гарантии live и snapshot

- Live: каждый запрос видит текущие committed rows. Новые строки перед позицией видны при новом поиске; backdated строки позади позиции могут появиться на последующих страницах. При неизменных данных полный обход в одном направлении не пропускает и не дублирует строки.
- Snapshot: первый запрос берёт exclusive transaction advisory lock проекта, дождётся уже начатых штатных записей и фиксирует `clock_timestamp()` PostgreSQL. Все `LogRepository::storeBatch` берут совместимый shared lock до INSERT, а `received_at` устанавливается по DB clock именно во время INSERT. Поэтому штатные события, принятые после границы, в том числе поздние/async с историческим `occurred_at`, не попадают в последующие страницы. Exclusive lock освобождается после первого read-only COMMIT/rollback; отдельный SQL-бюджет поиска ограничивает ожидание. Продолжения не берут exclusive lock.
- Snapshot — ограничение по времени приёма, а не сохранённый MVCC snapshot или копия содержимого. Retention и redaction видны при последующих чтениях; изменённые фильтруемые поля могут изменить состав. Ручные SQL-вставки, legacy adoption, импорт старой версии, часы PostgreSQL, переведённые назад, и прямое изменение `received_at` не покрываются гарантией. Для строго неизменного полного экспорта используйте CLI в одной repeatable-read транзакции. Snapshot может временно задержать writers при первой странице; не используйте тяжёлые широкие запросы в пиковую нагрузку.

## Ограничение размера и экспорт

SQL сначала выбирает `limit+1` кандидатов через keyset, сериализует их в PostgreSQL и возвращает только prefix с суммарным payload до 2 МиБ. PHP повторно ограничивает сериализованный объём items. Страница может содержать меньше `limit`, но continuation остаётся на последней фактически выданной строке. Если первый кандидат слишком велик, 413 `payload_too_large`: прочитайте событие через detail или обработайте исторические данные, не пропускайте его молча. Размер CSV ограничен 5 МиБ с учётом экранирования; JSON имеет небольшой дополнительный envelope. Бюджет страницы не ограничивает внутреннюю память PostgreSQL при сериализации исторических строк; SQL timeout остаётся обязательным. Не заявляется произвольная точность PHP floating-point при выдаче исторических JSONB-чисел; типы ответа совпадают с прежним API.

Для экспорта показанной страницы отправьте `page_cursor` с прежними фильтрами, limit и consistency. `If-Match: "<page_digest>"` (один quoted digest) необязателен. При несовпадении сервер возвращает 412 `page_changed`; новые данные не выдаются как прежняя страница. CSV и JSON возвращают `X-Page-Cursor`, `X-Page-Digest`, `X-Next-Cursor` и `X-Prev-Cursor`, когда продолжение доступно. Digest относится к JSON items, не к байтам CSV (формулы CSV нейтрализуются, как ранее). Для полного HTTP-экспорта начните snapshot и обходите `next_cursor`, проверяя expiry и статус; CSV header объединяйте один раз. Не считайте это immutable archive при retention/redaction.

```bash
curl -fsS -G "$CAJEER_URL/api/v1/logs/export" \
  -H "Authorization: Bearer $CAJEER_READ_TOKEN" \
  -H "If-Match: \"$PAGE_DIGEST\"" \
  --data-urlencode "project_id=$PROJECT_UUID" \
  --data-urlencode 'consistency=snapshot' \
  --data-urlencode 'levels=["error","warning"]' \
  --data-urlencode "cursor=$PAGE_CURSOR" \
  --data-urlencode 'limit=50' --data-urlencode 'format=csv' \
  -o page.csv
```

Полный CLI stream: `php bin/cajeer logs:export-jsonl PROJECT_UUID [SOURCE_UUID] > logs.jsonl`. Он использует тот же byte-bounded keyset SQL в одной `REPEATABLE READ READ ONLY` транзакции, пишет строки по мере чтения и завершится с ошибкой при oversized row или ошибке записи. Удалите неполный файл при ненулевом exit code. Длительный snapshot удерживает MVCC history и может мешать VACUUM; запускайте вне пика.

`db:explain PROJECT --filters JSON --analyze` теперь анализирует byte-bounded SQL первого live page. Подписанные HTTP cursors и snapshot в CLI EXPLAIN не принимаются (они требуют principal/реального watermark).

## Log Explorer

Режим страницы выбирается вместе с фильтрами. Кнопки Назад/Далее используют серверные prev/next, «Начать заново» создаёт новую навигацию. Текущие фильтры и позиция сохраняются только в памяти открытой панели при переходах между разделами; сбрасываются при смене проекта, изменении фильтров, logout или reload. Нет сохранения sensitive queries/cursors в localStorage, sessionStorage или URL. При 422/expired доступны повторный запуск и новый поиск. Экспорт отправляет page_cursor и If-Match, проверяет X-Page-Digest до сохранения файла. При изменении страницы экспорт не скачивает несовпадающий результат.

## Проверки

```bash
php tests/pagination.php
php tests/search.php
php tests/search_runtime.php
php tests/log_repository.php
python3 tests/admin_browser.py
CAJEER_TEST_DATABASE=1 php tests/search_database.php
```

PostgreSQL suite требует отдельную тестовую БД: проверяет реальный reverse keyset, snapshot при backdated ingest, ожидание advisory writer fence, byte-limited JSONB, проверяемый экспорт, изменение данных, курсор после обновления и scope. Не запускайте на production. `tests/upgrade_release.py` и `scripts/certify.py` проверяют actual 0.2.8 → 0.2.9. Public Preview до фактического прохождения PostgreSQL gate; скорость, масштабируемость и независимая security-сертификация не заявляются без измерений.
