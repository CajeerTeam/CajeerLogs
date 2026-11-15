# Queue & Lifecycle — 0.0.8

## Обновление с 0.0.7

1. Сделайте проверенный backup PostgreSQL и проекта; остановите ingest и workers на время обновления.
2. Наложите файлы релиза. Выполните `php bin/cajeer migrate`, `php bin/cajeer migrate:status`, `php bin/cajeer db:doctor`.
3. Миграция `0007_queue_lifecycle.sql` расширяет `jobs`, создаёт `job_attempts`, `scheduler_runs`, добавляет opt-in `projects.retention_days` и индексы. 0001–0006 не изменены. Старые jobs не исполняются: новая очередь использует имя `ingest-v1`.
4. Запустите PHP-FPM и worker; убедитесь, что `/ready` возвращает 200. Запустите scheduler. Для непрерывного CLI worker нужен PHP `pcntl`; для конечных batch-запусков он не обязателен.

PostgreSQL 17+, PHP 8.4+. Поддерживается только PostgreSQL queue. Redis/RabbitMQ не активированы. Все серверные процессы должны использовать одну и ту же схему, `QUEUE_MAX_JOBS` и доступ к защищённому `.env`. Старый `QUEUE_DRIVER` не переключает backend: в этой версии он фиксирован на PostgreSQL.

## Sync и async

Обычный `POST /api/v1/ingest` и все SDK сохраняют прежнюю семантику: 201 только после записи логов, 200 при повторе. Для асинхронной отправки используйте новый `POST /api/v1/ingest/async`. Он принимает тот же JSON (событие либо `{"events":[...]}`), scope `logs:write` и обязательный `Idempotency-Key`. HMAC при необходимости подписывает именно путь `/api/v1/ingest/async` (см. Log-Ingestion.md). Не отправляйте один ключ на оба маршрута: сервер возвращает 409 при конфликте с существующей квитанцией/заданием.

```sh
curl --fail-with-body -i -X POST "$CAJEER_URL/api/v1/ingest/async" \
  -H "Authorization: Bearer $CAJEER_TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: batch-2026-11-14-001' \
  -d '{"timestamp":"2026-11-14T00:00:00Z","source":"app","level":"info","message":"worker example"}'
```

HTTP 202 означает, что валидированный, нормализованный и маскированный пакет и его ключ сохранены в PostgreSQL, WAL commit завершён. Это **не** подтверждение сохранения событий. Ответ содержит `accepted`, `received`, `job_id` (строка bigint), `status`, `replayed`; Location указывает на `/api/v1/ingest/jobs/{job}`. Повтор того же тела и ключа возвращает 200 с существующим ID и текущим состоянием, другой body — 409.

```sh
curl --fail-with-body "$CAJEER_URL/api/v1/ingest/jobs/123" \
  -H "Authorization: Bearer $CAJEER_TOKEN"
```

Статус новых jobs 0.2.7 доступен действующему `logs:write` credential той же цепочки ротации, с неизменным проектом/source/account; старые jobs сохраняют точного владельца. Чужие задания возвращают 404. При `completed` поле `result` содержит `event_ids` и `stored`. `pending` — ожидает worker/повтор, `running` — захвачено, `dead` — требует вмешательства, `cancelled` — оператор явно отказался от dead-letter payload. После отмены или исчерпания попыток клиент не должен считать пакет сохранённым. Отзыв токена закрывает доступ к новым запросам и статусу, но уже принятая работа не отменяется.

Лимиты: 100 событий, 1 МиБ исходного запроса (или меньший APP_MAX_BODY_BYTES), максимум 2 МиБ нормализованного JSON задания. `QUEUE_MAX_JOBS` (1..100000, по умолчанию 1000) ограничивает суммарное число pending/running/dead в схеме. При переполнении возвращается 503 `queue_full`, `Retry-After: 5`; повтор существующего задания не занимает новый слот. Лимит глобальный, не per-project; токены также ограничены rate limit. Для durable ack PostgreSQL должен работать с обычными fsync/WAL durability settings, не отключайте fsync. Успешные и отменённые задания 0.2.7 сохраняют метаданные, результат и ключ в течение `INGEST_IDEMPOTENCY_SECONDS` (по умолчанию 7 суток), фиксированного при приёме, затем удаляются scheduler; после удаления ключ может снова создать работу. Legacy jobs сохраняют 7 суток. Pending/running/dead payload и ключ сохраняются до завершения/retry/discard. Новые синхронные квитанции используют тот же настраиваемый срок, старые — 24 часа. Не переносите ключи между режимами и не повторяйте после истечения окна без сверки.

## Worker, retries, dead-letter

```sh
php bin/cajeer queue:work             # непрерывно, SIGTERM/SIGINT завершает текущую работу
php bin/cajeer queue:work 100         # до 100 захватов; выходит, когда очередь пуста
php bin/cajeer queue:status           # фактические счётчики
php bin/cajeer queue:list dead        # первые 100 dead jobs, без payload
php bin/cajeer queue:history 123      # история попыток
php bin/cajeer queue:retry 123        # тот же payload и ID, ещё до 8 попыток (общий предел 100)
php bin/cajeer queue:discard 123      # необратимо удалить payload dead job, оставить cancelled receipt
```

Несколько workers могут работать параллельно. Claim использует `FOR UPDATE SKIP LOCKED`, двухминутный lease и случайный lease ID; просроченный lease перехватывается, старый worker не может подтвердить новый claim. Worker берёт row lock и одной транзакцией вставляет весь batch через multi-row INSERT, создаёт webhook outbox и завершает job. После успеха очищается payload. При падении до commit транзакция откатывается; незавершённый claim восстанавливается после lease expiry. После commit повторная обработка невозможна. Политика retry: 8 попыток по умолчанию, 5, 10, 20... секунд (максимум час), история результатов и безопасный код ошибки без текста логов. Ошибка хранилища, мешающая записать retry, завершает worker; systemd перезапустит его, lease будет восстановлен. Ручной retry допустим после исправления причины, discard только по явному решению оператора. Заполненные dead jobs учитываются в лимите и требуют разбора. Для крупных payload учитывайте память/диск PostgreSQL и настройте лимиты по результатам нагрузочного теста.

Webhook worker из 0.0.7 запускается отдельно (`webhook:work 20`), сохраняет собственную at-least-once семантику. Завершение очереди ingest не означает успешную внешнюю webhook-доставку. Удаление логов retention не удаляет уже отправленные данные у получателей.

## Retention

По умолчанию для каждого проекта retention **выключен** (NULL); это предотвращает неожиданное удаление при обновлении. Настройте только после backup и согласования политики хранения.

```sh
php bin/cajeer retention:list
php bin/cajeer retention:set PROJECT_UUID 30
php bin/cajeer retention:run 1000
php bin/cajeer retention:set PROJECT_UUID off
```

1–3650 суток; удаление по серверному `received_at`, не клиентскому `timestamp` (для async это время записи worker). Старые импортированные timestamps не приводят к немедленному удалению. Каждый проход удаляет до 1000 событий по умолчанию (1..10000 CLI) в транзакции, используя `SKIP LOCKED`. Политика и удаление сериализуются advisory lock. Логи проектов с выключенной политикой и исторические unscoped события не удаляются. Retention не восстанавливает данные, не затрагивает pending/dead job payload, не удаляет audit logs и backup, не удаляет квитанции до их отдельного срока. Следите за PostgreSQL autovacuum и диском; DELETE не возвращает место ОС немедленно. Job result может ссылаться на уже удалённое событие. Для крупного backfill deletion запускайте небольшими batch в окно обслуживания.

## Scheduler и эксплуатация

`php bin/cajeer scheduler:run` выполняет только просроченные фиксированные задачи. В PostgreSQL сохраняется next_run_at, last_success_at, last_result; advisory transaction lock предотвращает параллельный запуск одной задачи. Успех и результат атомарны с maintenance; при ошибке задача остаётся due, остальные задачи продолжают выполняться, CLI возвращает 1.

| Задача | Интервал | Предел за проход |
|---|---:|---|
| retention | 60 сек | 1000 логов |
| auth_cleanup | 300 сек | 10000 строк каждой таблицы (сессии, rate-limit, receipts, nonce) |
| queue_cleanup | 3600 сек | 1000 completed/cancelled старше 7 суток + cascade attempts |
| webhook_cleanup | 3600 сек | 10000 terminal deliveries старше 30 суток |

Используйте systemd примеры в `examples/systemd`: `cajeerlogs-queue.service`, `cajeerlogs-scheduler.service` и `.timer`. Укажите фактический путь, непривилегированного системного пользователя и права на `.env`/storage; CLI и PHP-FPM должны использовать совместимую конфигурацию. После установки unit-файлов:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now cajeerlogs-queue.service cajeerlogs-scheduler.timer
sudo systemctl status cajeerlogs-queue.service
sudo journalctl -u cajeerlogs-queue.service -n 100
```

Для local Compose: `docker compose exec app php bin/cajeer queue:work 100` и `docker compose exec app php bin/cajeer scheduler:run`. Для production предпочтителен systemd непрерывный worker, cron finite batch — альтернатива. Не дублируйте cron и timer; scheduler уже включает auth/webhook cleanup. Отдельный webhook timer нужен для сетевой доставки.

`/metrics` добавляет `cajeerlogs_queue_jobs{status=...}` и `cajeerlogs_queue_available` (0 при невозможности запроса); `system:report` содержит queue counts, `queue:status` предназначен для CLI. `/ready` проверяет схему и БД, но не heartbeat worker: контролируйте systemd, возраст pending jobs и dead-letter. Не публикуйте metrics в Интернет без ограничения доступа на reverse proxy.

## Тестирование

```sh
php tests/queue.php
python3 tests/http_runtime.py
CAJEER_TEST_DATABASE=1 php tests/queue_database.php
```

Интеграционный тест создаёт отдельную схему, применяет 0001–0006, обновляет до 0007, использует независимые PostgreSQL соединения для проверки lock/lease, очереди, транзакций, ретеншена, outbox и scheduler. CI запускает PHP 8.4/8.5 + PostgreSQL 17. Для production проведите DB integration suite, нагрузочный прогон, тесты SIGTERM/kill, backup/restore и проверку диска. Локальные тесты без PostgreSQL проверяют кодек, backoff, отказ неподдерживаемых драйверов, маршруты и ошибки, но не заменяют DB integration.
