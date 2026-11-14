# Database Integrity & Performance — 0.1.9

PostgreSQL 17/18, PHP 8.4/8.5. SQL `0012_database_integrity.sql` — новая транзакционная миграция; 0001–0011 неизменны. Release остаётся Public Preview.

## Обновление и диагностика

1. Остановить ingest и фоновые сервисы, выполнить `backup:create` на 0.1.8; отдельно сохранить конфигурацию/секреты.
2. Наложить обновление. Выполнить `php bin/cajeer migrate:plan`.
3. Выполнить `php bin/cajeer db:integrity 20` на ещё не обновлённой, структурно подтверждённой схеме 0.1.8. Устранить обнаруженные нарушения после расследования и резервирования. Никакого автоматического ремонта или удаления.
4. `php bin/cajeer migrate`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:integrity`, `php bin/cajeer db:doctor`, `php bin/cajeer doctor`.
5. Возобновить сервисы и проверить `/ready`. При разделённых ролях использовать migration credentials для `migrate` как раньше.

0012 проверяет исторические строки: несогласованные project/token, source/slug или lease/completion state останавливают миграцию целиком. Индексы строятся транзакционно, поэтому планировать окно обслуживания, свободное место на диске и WAL; при необходимости настроить `DB_MIGRATION_STATEMENT_TIMEOUT_MS`. Валидация FK и новые индексы создают дополнительную нагрузку на запись. Строгая MigrationGuard не допускает старую схему для нового runtime. Восстановление backup 0.1.8 в пустую отдельную БД применяет 0012; предыдущая политика отзыва credentials сохранена.

### Логический аудит

`php bin/cajeer db:integrity [1..100]` — доверенный CLI, снимок `REPEATABLE READ READ ONLY`, shared migration lock. 0 — проверенные условия не нарушены; 2 — найдены нарушения; 1 — проверка не завершена или схема несовместима. Команда поддерживает preflight при единственной ожидающей 0012 и verified schema. Не выполняется при schema drift: сначала `migrate:verify`.

Проверки: project/source/slug/token/scopes логов, project/token jobs, leases, completion, attempts, payload/result, source map, receipts, сохранённые ссылки результата/receipt, пересечение действующего receipt с job, scope преемника токена, webhook payload и attempts. Вывод содержит идентификаторы и нижнюю границу числа нарушений, не сообщения, JSON context, токены или хеши idempotency keys. Для receipt идентификатор — token_id, не уникальный ключ receipt. Выборка не больше указанного лимита + 1 на проверку; `truncated` означает, что больше строк не считалось. Полный обход может быть дорогим даже при нуле нарушений; запускать вне пика, `DB_STATEMENT_TIMEOUT_MS` регулирует timeout каждого запроса. SQL-ошибка приводит к отказу, а не к ложному OK.

Непривязанные legacy-события отдельно отражаются `legacy_unassigned`, не являются ошибкой. Отсутствующие event UUID в старых receipts/results/outbox могут быть результатом штатного retention; проверяется принадлежность сохранившихся событий. Отозванные токены и expired jobs не считаются нарушением только по времени/статусу. Это логический аудит, не физическая проверка heap/индексов (для этого PostgreSQL DBA использует `amcheck`/`pg_amcheck` и проверку backup). Он не доказывает отсутствие всех возможных повреждений или корректность произвольного ручного SQL.

### Диагностика производительности

```bash
php bin/cajeer db:performance > performance.json
php bin/cajeer db:explain PROJECT_UUID --trace TRACE_ID
php bin/cajeer db:explain PROJECT_UUID --trace TRACE_ID --analyze
```

Таблицы: heap/index/total bytes, estimated live/dead tuples, planner rows, xid age, last (auto)vacuum/analyze, scans и relation options. Индексы: размер, scans/tuples, valid/ready. База: temp IO, cache counters, deadlocks, timing и stats_reset. Активность агрегируется по состоянию в текущей БД без текста SQL; видимость чужих сессий ограничена ролью. Настройки не меняются. Dead tuples — оценка, не измерение bloat; нулевой idx_scan не означает, что индекс лишний. I/O timing может быть отключён. Снимать два отчёта в одинаковой среде, сравнивать разницу счётчиков (не при сбросе статистики).

EXPLAIN по умолчанию не выполняет запросы. `--analyze` выполняет только SELECT в read-only transaction, возвращает JSON с execution time и buffers; может создать нагрузку. Latest/trace используют тот же SQL, что LogRepository; retention_candidates и queue_ready — диагностические SELECT без удалений и блокировок. Нет автоматического VACUUM FULL, REINDEX или изменения серверных параметров. План зависит от статистики и распределения данных; не форсировать enable_seqscan. После большого импорта DBA может выполнить `ANALYZE` для обновления статистики.

### Изменения запросов

- Композитный индекс `(project_id, trace_id, occurred_at DESC, id DESC)` для изолированного поиска и keyset. Старый `project_request` заменён индексом с таким же префиксом и сортировкой `(project_id,request_id,occurred_at DESC,id DESC)`. Старый глобальный trace индекс сохранён.
- Индексы на `log_events(ingest_token_id, project_id)` и `jobs(token_id, project_id)` помогают FK lookup, в том числе при обслуживании токенов.
- Retention: lateral scans с индексами `(project_id, received_at,id)` и `(source_id,received_at,id)`, не глобальная выборка с `LEAST` для всех событий. Берётся до лимита кандидатов на каждую заданную политику, затем UNION и глобальный лимит; `FOR UPDATE SKIP LOCKED` сохраняется, совпадающие политики не удаляют событие дважды. Суммарные промежуточные кандидаты зависят от числа политик. Если первые кандидаты заблокированы, возможен меньший batch/отложенная очистка; следующая итерация повторяет выборку. Retention остаётся по received_at, а не occurred_at, проект или источник могут задавать более короткий срок.

## Реальная нагрузочная проверка

Использовать выделенный тестовый проект, источник и токен с `logs:write`, `logs:read`. Запустить минимум один queue worker, для конкурентного сценария два. Тест добавляет настоящие логи, receipts/jobs и расходует квоты. Не запускать на production без согласованного окна и лимитов. У тестового проекта retention не должен удалять новые события в ходе проверки.

```bash
export CAJEER_TOKEN='…' # Лучше ввод через read -rs, не хранить в history.
python3 scripts/benchmark.py --url https://logs.example.com \
  --project PROJECT_UUID --source app --requests 120 --batch 25 \
  --concurrency 8 --timeout 600 --confirm
unset CAJEER_TOKEN
```

Для частного CA: `--ca-file /path/to/ca.pem`. TLS всегда проверяется, HTTP допускается только на loopback. Клиент не следует redirects и не пересылает credentials. Пределы: 1–10000 исходных запросов, 1–100 событий в batch, 1–64 потока, 1–3600 секунд. Каждый третий запрос single, следующий batch, следующий async; каждый повторяется с теми же байтами/key и свежим HMAC nonce. Одновременно выполняется чтение. После завершения jobs проверяется **полное множество UUID** через keyset pagination по уникальному request_id. Отказ, дубликаты, отсутствующие события, ошибки API и незавершённые jobs приводят к ненулевому exit. При отказе возможны уже сохранённые события; не повторять запуск как идемпотентное продолжение (новый run_id). Результат включает latency p50/p95/p99 для первоначальных запросов/страниц, время admission и end-to-end events/sec (включает повторы, ожидание worker и финальный поиск), количество проверенных событий. Это не максимальная пропускная способность и не SLA. Перед большими прогонами настроить квоты, очередь, ресурсные лимиты и мониторинг. Сравнивать один и тот же объём исходных данных, concurrency, окружение и состояние кешей; не считать быстрый acceptance 202 завершённой записью.

## Проверки

`php tests/integrity.php` — локальная логика и контролируемый PDO; `python3 tests/benchmark.py` — локальный HTTP fixture. `CAJEER_TEST_DATABASE=1 php tests/integrity_database.php` — реальный disposable PostgreSQL: миграция с плохими данными и rollback, FK, очередь, snapshot, retention с независимой транзакцией, планы. GitHub Actions включает его в PHP/PostgreSQL matrix. `tests/production_smoke.py` выполняет benchmark через реальный HTTPS/PHP-FPM/PG с двумя workers после backup/restore и restart; `tests/upgrade_release.py` проверяет настоящий исходник 0.1.8, миграцию, данные и restore.

Без успешного выполнения этих PostgreSQL-тестов нельзя утверждать измеренное ускорение, нагрузочный предел или production-сертификацию. Партиционирование и новые backend не вводятся без результатов измерения.
