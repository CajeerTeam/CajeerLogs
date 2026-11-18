# CajeerLogs 0.6.0 — High-Throughput Ingestion & Queue Processing Stable

## Рабочий путь обработки

Версия 0.6.0 изменяет действующий PostgreSQL worker, а не только его конфигурацию.
`IngestService` принимает и авторизует sync/async HTTP ingest; `PostgresQueueDriver`
записывает durable job в одной транзакции с квотами, rate limiting, redaction,
HMAC nonce, idempotency key и счётчиками. `Worker` подтверждает задание после
реального `LogRepository::storeBatch()` и публикации webhook outbox в PostgreSQL.

Для async очереди по умолчанию `QUEUE_WORKER_BATCH_JOBS=4` (диапазон 1..16).
После стандартного первого fair claim с поддержкой истекшего lease воркер
просматривает готовые задания *того же проекта и токена*. Дополнительные jobs
выбираются только если одновременно выполняются ограничения:

- одинаковая версия нормализации событий;
- совокупно не более 100 событий;
- совокупный `payload_bytes` (фактическое JSONB-представление PostgreSQL)
  не больше 4 MiB; это намеренно более консервативно, чем размер исходных events;
- `max_running_jobs` конкретного проекта и установленный `batch_jobs` не нарушаются.

Несовместимые задания пропускаются **до аренды**; следующий проход очереди может
обработать их независимо. Остаются отдельные lease_id, attempts, HMAC/idempotency
receipt и durable result каждого исходного job. Двухэтапный claim сохраняет
очередность восстановления истекших leases и разграничение проектов.

В обработке группы один SQL-запрос блокирует строки `jobs` по ID, проверяет
каждый lease **после** блокировки и возвращает ограниченные по размеру payloads.
Внутри одной транзакции выполняется общий INSERT в log_events, отдельные
результаты заданий и webhook outbox, одно массовое обновление job_attempts.
При повреждённом job группа откатывается целиком и jobs изолируются
существующим per-job retry/dead-letter path. При потере соединения или
неопределённом результате COMMIT автоматического повторного выполнения нет:
состояние определяется сохранёнными lease/receipt.

Новых SQL-миграций нет. Миграции 0001–0025 **не изменены**;
HTTP API v1 и формат очереди совместимы с 0.5.10.

## Развёртывание обновления 0.5.10 → 0.6.0

1. Снимите и проверьте восстановимость резервной копии PostgreSQL и сохраните
   полный релиз 0.5.10 для обратного отката бинарных файлов.
2. Остановите queue workers и дождитесь завершения текущих транзакций.
   Не меняйте схему и не удаляйте таблицы `jobs` или `job_attempts`.
3. Наложите ZIP с изменёнными файлами в корень **полной** установки 0.5.10,
   сохраняя относительные пути. Не распаковывайте его как отдельный дистрибутив.
4. Выполните `php bin/cajeer migrate:verify`, `php bin/cajeer queue:verify`,
   `php bin/cajeer queue:project-verify`, `php bin/cajeer doctor`.
5. Запустите worker pool, дождитесь `queue:pool-health`, проверьте
   `php bin/cajeer queue:capacity`, `/ready` и метрики Prometheus.
6. Проверьте HTTP async запрос и дождитесь `completed`, повторите тот же body
   и Idempotency-Key и убедитесь, что создан только один job.

Операционный откат: выставить `QUEUE_WORKER_BATCH_JOBS=1` и перезапустить
воркеры; это отключит группировку без миграции данных. Для отката приложения
потребуются исходные файлы 0.5.10 и отдельная проверка версии/совместимости.

## Подтверждение на реальном стенде

Самотесты без PostgreSQL:

```bash
php tests/throughput_planner.php
php tests/worker_microbatch.php
python3 tests/load_test.py
python3 tests/certify.py
```

Интеграционные тесты на **одноразовом** PostgreSQL 17/18 (с расширением
`pdo_pgsql`, переменными `DB_*`, `APP_ENV=test`, `CAJEER_TEST_DATABASE=1`):

```bash
php tests/worker_microbatch_database.php
php tests/queue_fairness_database.php
php tests/queue_operations_database.php
python3 tests/production_smoke.py
```

`worker_microbatch_database.php` подтверждает уменьшение числа SQL INSERT
с четырёх до одного для четырёх совместимых jobs. Он также проверяет
изолирование повреждённого job, retry после отказа webhook outbox, полные
durable receipts, отсутствие дубликатов, счётчики очереди и работу планировщика
на смешанных размерах задач. Время выполнения выводится как фактическое
измерение; гарантированное ускорение на любом оборудовании не обещается.

Публичный CI release gate **также требует** полный sustained/stress/soak/fault прогон через production Docker/PG/TLS и включает его JSON evidence в итоговый отчёт; без этого gate релиз не сертифицируется.

Полный *нагрузочный* сертификат для этого релиза привязан к **полному**
верифицированному release ZIP 0.6.0 (ZIP с одними изменениями не подходит),
а также к живому `/ready` на HTTPS. Обязательны 100% async-трафик и
batch >= 2, четыре профиля sustained/stress/soak/fault с минимальной
длительностью 60/120/900/60 секунд, проверкой хранимых event IDs и всех
latency/error/throughput SLO. Пример запуска:

```bash
export CAJEER_LOAD_TEST=1
export CAJEER_TOKEN='temporary-test-token'
python3 scripts/load_test.py \
  --url https://logs-staging.example.test \
  --project 11111111-1111-4111-8111-111111111111 --source app \
  --rate 8 --batch 20 --concurrency 16 --async-percent 100 \
  --sustained-seconds 120 --stress-seconds 180 \
  --soak-seconds 1800 --fault-seconds 120 \
  --archive /path/to/CajeerLogs-0.6.0.zip --certify \
  --output /safe/path/throughput-0.6.0.json --confirm-disposable
```

Результат `certified: true` означает лишь успешный **конкретный запуск**.
Короткие проверки и локальные PHP unit tests не заменяют длительную
сертификацию реальной установки. Fault proxy проверяет потерю ACK, 429/503
и задержки; отдельный production smoke проверяет перезапуск PostgreSQL/worker.
Испытания проводятся только на изолированном стенде, не на действующей БД.
