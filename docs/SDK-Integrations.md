# SDK & Integrations

## 0.6.3 — TypeScript / Node.js SDK Stable

`@cajeer/logs-sdk` now supports **optional** `DurableBatchDelivery` on Node.js 20+: atomic, private file-backed outbox, bounded batching, checked HTTP/HMAC receipts, replay of identical bytes/key after restart and fail-closed expiration. An enqueue acknowledgement means *local persistence only*, not PostgreSQL commit. For setup, crash recovery, limits and packaging see [TypeScript SDK Stable](TypeScript-SDK-Stable.md).

## 0.6.2 — Python SDK Stable

Python SDK получил `DurableBatchSpool` для синхронного batching и
`CajeerLogsBufferedHandler` для stdlib logging. Запись на диск выполняется до
сетевого запроса; после потери ACK восстановление сохраняет точное тело и
ключ. Реальный запуск: [Python SDK Stable](Python-SDK-Stable.md).

## 0.6.1 — PHP / Monolog SDK Stable

PHP SDK с этой версии поддерживает **опциональный** `DurableBatchDelivery` — приватный
файловый spool с ограниченным batching и восстановлением точных JSON-байтов и
Idempotency-Key после сбоя/перезапуска. PSR-3 и Monolog 3 используют общий
транспорт при явном подключении; существующий синхронный путь не изменён.
Для PHP-клиента, настройке `flush()`, контроля заполнения, режимов ошибок и
ограничений файловой надёжности см. [PHP SDK Stable](PHP-Monolog-SDK-Stable.md).

## Исторический релиз 0.0.7

## Обновление 0.0.6 → 0.0.7

Сделайте backup проекта и PostgreSQL, остановите ingest на время обновления, наложите архив в корень репозитория, затем:

```sh
php bin/cajeer migrate
php bin/cajeer migrate:status
php bin/cajeer db:doctor
php bin/cajeer version
```

Версия `0.0.7`. Миграция `0006_sdk_integrations.sql` создаёт `integration_webhooks`, `webhook_deliveries`, `webhook_attempts`. Миграции 0001–0005 неизменны. События, проекты, источники, токены и сессии сохраняются. Старые записи таблицы `webhooks` (каркас предыдущих версий) не активируются автоматически. До миграции новый ingest не сможет выполнить outbox INSERT, поэтому не запускайте новый PHP-код на старой схеме. Для production используйте PHP-FPM, HTTPS, PostgreSQL 17+; отдельный webhook worker требует `ext-curl`. Для клиента и коллектора PostgreSQL/PHP не нужны.

## Подключение приложения

1. В `/admin/` создайте проект, источник со slug `app` и API-токен `logs:write` (лучше ограниченный этим источником).
2. В окружении внешнего приложения задайте `CAJEER_URL=https://logs.example.com` и `CAJEER_TOKEN` из secret manager/защищённого файла. Не храните token в Git, аргументах процесса, публичном JavaScript или логах.
3. Установите соответствующий SDK: [PHP + PSR-3/Monolog](../packages/php-sdk/README.md), [Python](../clients/python/README.md), [TypeScript/Node](../packages/ts-sdk/README.md), [JVM/Kotlin](../clients/java-kotlin/README.md).
4. Отправьте событие. Проверьте `event_ids` из ответа и откройте соответствующий проект в Log Explorer.

Проверочный запрос (без обязательного HMAC; если `INGEST_REQUIRE_HMAC=true`, используйте signed SDK):

```sh
curl --fail-with-body -X POST "$CAJEER_URL/api/v1/ingest" \
  -H "Authorization: Bearer $CAJEER_TOKEN" -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: example-start-42' \
  -d '{"source":"app","level":"info","message":"Application started","timestamp":"2026-11-14T00:00:00Z","context":{"version":"1.0"}}'
```

Single event / `{"events":[...]}`; максимум 100 событий, 1 MiB на запрос, 64 KiB на событие, 16 KiB message, 32 KiB context. Полный набор полей и форматы: [Log Ingestion](Log-Ingestion.md). HTTP 201 означает завершённый PostgreSQL COMMIT, 200 — повтор идемпотентного запроса. Все SDK проверяют `accepted`, `received`, `stored`, `rejected`, `event_ids`, `replayed`, не считают любой 2xx успехом. Не меняйте тело или ключ при неопределённом результате.

### Повторные попытки

SDK сериализуют один раз и повторяют точные байты с тем же ключом. По умолчанию до 3 попыток, exponential backoff с jitter, timeout 10 s; 408/429/500/502/503/504 и ошибки транспорта повторяемы; числовой Retry-After учитывается, при задержке свыше 30 s SDK возвращает отказ вместо немедленной повторной попытки. Прочие 4xx, включая 409, не повторяются автоматически. HMAC использует тот же scoped Bearer-token, свежие timestamp и nonce на каждой попытке; путь и хеш тела связаны подписью. Redirects не выполняются. HTTPS проверяет сертификат; HTTP по умолчанию только loopback. По умолчанию SDK не хранят долговременную очередь; исключения — опциональные PHP `DurableBatchDelivery` 0.6.1, Python `DurableAsyncSpool` и `DurableBatchSpool` 0.6.2, TypeScript `DurableBatchDelivery` 0.6.3. Для остальных клиентов после отказа вызывающий код должен сохранить тело и ключ либо использовать файловый collector. Новые доставки 0.2.7 имеют окно 1–30 дней (по умолчанию 7) в пределах цепочки ротаций credential. Legacy receipts сохраняют 24 часа и прежнего владельца. PHP `sendBody`, TypeScript `sendBody`, Python `send_body` и Kotlin `ingest(String, key)` позволяют повторить сохранённые JSON-байты с исходным ключом; SDK проверяют уникальные корректные UUID в receipt. При неизвестном результате старше окна нельзя гарантировать отсутствие дублей. Для длительных сбоев используйте файловый collector с ручным разрешением просроченных pending batches.

Синхронные logging adapters могут задерживать основной поток; на высоконагруженных приложениях логируйте в локальный файл и выделите отдельный collector. Серверное redaction — best effort, не помещайте секреты в логи.

## Файловый коллектор

Python 3.10+ на Linux/Unix, без runtime-зависимостей кроме стандартных SQLite/zoneinfo/fcntl; для установки `pip install ./clients/python` или запуск скрипта непосредственно. Один state DB на один файл, один токен, источник и формат. SQLite служит локальным журналом, а не альтернативой PostgreSQL сервера.

```sh
# Из защищённого окружения: CAJEER_URL, CAJEER_TOKEN
python3 clients/python/cajeer_logs_collector.py \
  --file /var/log/myapp/app.jsonl \
  --state /var/lib/cajeer-collector/app.sqlite \
  --source app --format jsonl --service backend --signed
```

Форматы:

- `jsonl`: объект события в каждой UTF-8 строке, поля timestamp/level/message/context и прочие поля v1; timestamp/level по умолчанию время чтения/info, source принудительно из CLI. Не допускаются произвольные поля за пределами v1 (сервер отклонит пакет).
- `text`: каждая непустая строка — сообщение уровня info, время чтения; подходит для обычных PHP-журналов, stack traces построчно. Для структурированных PHP-событий предпочтителен JSONL.
- `nginx-access`: common/combined, HTTP status, IP, method, path, bytes; URL query/fragment, referrer и User-Agent не передаются. 4xx → warning, 5xx → error.
- `nginx-json`: структурированный Nginx `log_format escape=json`, время в UTC, request/status/bytes/durations; остальные поля не пересылаются. См. [Nginx Log Import Stable](Nginx-Log-Import-Stable.md).
- `nginx-error`: стандарт `YYYY/MM/DD HH:MM:SS [level] ...`, локальный timezone задаётся `--timezone Europe/Amsterdam` (по умолчанию UTC); warn/crit/emerg нормализуются. Для локального неоднозначного времени при переводе часов предпочтителен JSONL с offset.

В aaPanel обычно `/www/wwwlogs/DOMAIN.log` и `/www/wwwlogs/DOMAIN.error.log`; укажите фактические пути и отдельные state DB/источники. `php bin/cajeer import:aapanel` и `php bin/import-aapanel-logs.php` передают аргументы тому же работающему collector. `--once` обрабатывает доступные полные строки и выходит; без него опрос по `--interval` (2 s). `--batch` по умолчанию 50, максимум 100; пакет ограничен 1 MiB. Неполная последняя строка ждёт завершения. Ошибка формата/размера/сервера не пропускается: процесс возвращает 1, checkpoint не продвигается за неподтверждённое событие. Исправьте источник или credentials, затем перезапустите. Для health/alerting мониторьте exit status, отставание и размер журнала.

### Сохранность, ротация и восстановление

- Создайте приватный каталог state от имени collector (0700, файлы 0600). SQLite хранит исходные pending bytes, которые могут содержать секреты; не копируйте его в публичные backup. Не удаляйте state/lock/journal при обычном перезапуске. Lock исключает два процесса с одним state.
- Первый запуск читает текущий файл с начала, не импортирует старые архивы автоматически. После успешного ответа фиксируется offset. При сбое после COMMIT но до фиксации offset следующий запуск посылает прежнее тело и ключ, а сервер возвращает исходные UUID. После 23 часов неопределённости collector останавливается; до восстановления проверьте наличие событий/receipt в БД, не удаляйте pending state и не создавайте новый ключ вслепую.
- Ротация rename + reopen (например logrotate `create`, Nginx reopen): сначала дочитывается старый inode, затем текущий файл. Храните незажатые ротированные файлы до подтверждения чтения; используйте `delaycompress` и достаточное число архивов. `--rotated-glob` ограничивает поиск (по умолчанию `<file>.*`); не включайте чужие журналы/файлы state. Промежуточные незажатые файлы сортируются по mtime; неоднозначный порядок, отсутствие старого inode, неполный хвост или изменившийся ранее обработанный архив требуют ручной сверки. Collector ожидает 2 s тишины старого файла; писатель должен завершить запись в старый inode до переключения. Для нестандартного `copytruncate` доступна явная опция `--copytruncate`: только уникальная стабильная архивная копия с проверенным префиксом checkpoint. При отсутствии подходящего архива сбор останавливается. Copytruncate по своей природе может потерять запись в окне copy/truncate; предпочтителен rename+reopen. Не удаляйте/сжимайте архивы до чтения. При высокоскоростной ротации предпочтителен отдельный журнал на файл или JSONL с контролируемым жизненным циклом.
- State привязан к URL/API prefix, хешу токена, файлу, источнику, формату и timezone; смена токена при неизвестной доставке небезопасна. Для плановой смены сначала дождитесь пустого pending и сверенного checkpoint, сохраните state и перенесите только подтверждённую позицию в новую конфигурацию под контролем оператора. Не перезапускайте с пустой БД для того же файла без понимания повторного импорта.

См. `examples/systemd/cajeerlogs-collector.service` (на стороне источника, измените paths/user/group и EnvironmentFile). Не запускайте от root; предоставьте read-only доступ к журналам.

## Исходящие webhooks: `logs.ingested`

Это отдельный работающий канал уведомлений о **закоммиченном пакете**, не будущие alert rules и не HTTP API управления webhooks. Подписка принадлежит проекту, содержит URL и имя переменной секрета. Тело включает `event`, `project_id`, `event_ids` — без message/context/credentials. Получатель при необходимости читает детали через собственный scoped `logs:read` token. До 20 активных подписок на проект.

1. Установите PHP `ext-curl` для worker. Создайте уникальный секрет (например `openssl rand -hex 32`) и поместите в защищённое окружение worker/CLI под именем `CAJEER_WEBHOOK_MY_APP`; минимум 32 байта, не передавайте значение в командной строке. В БД хранится только имя переменной, не секрет. `.env` должен иметь mode 0600. Старый глобальный `WEBHOOK_SIGNING_SECRET` не используется новым dispatcher.
2. Зарегистрируйте подписку оператором сервера:

```sh
php bin/cajeer webhook:add PROJECT_UUID https://receiver.example.com/cajeer CAJEER_WEBHOOK_MY_APP
php bin/cajeer webhook:list
php bin/cajeer webhook:work 20
php bin/cajeer webhook:history WEBHOOK_UUID
php bin/cajeer webhook:retry FAILED_DELIVERY_UUID
php bin/cajeer webhook:disable WEBHOOK_UUID
php bin/cajeer webhook:cleanup
```

URL: только HTTPS DNS hostname, порт 443, без userinfo, query, fragment; DNS должен разрешаться исключительно в публичные IPv4-адреса, IP фиксируется на HTTPS-соединение (DNS rebinding prevention). Без redirects, proxies, приватных/link-local/reserved/multicast IP. Сертификат проверяется. Используйте egress firewall как дополнительную защиту, доверенный DNS. IPv6-only и частные webhook endpoints не поддерживаются. Worker запускается доверенным администратором; не делайте пользовательский API для произвольных URLs без отдельной security review.

3. Настройте `webhook:work` каждую минуту через systemd timer или cron. Пример в `examples/systemd/cajeerlogs-webhook.*`. Worker ограничен 1..100 задачами, default 20. Отправка HTTP выполняется вне DB-транзакции. `FOR UPDATE SKIP LOCKED`, lease 2 min, история каждой попытки, восстановление зависших отправок. 2xx → delivered, network/408/425/429/5xx из списка → retry (30 s exponential backoff, до 8 попыток), остальные коды → failed; ручной retry сохраняет историю и стабильный ID. Не запускайте одновременно cron и timer без необходимости. Disabled hook отменяет ожидающие доставки, но уже отправляемый запрос может завершиться. `webhook:cleanup` удаляет до 10000 терминальных записей старше 30 дней и историю; pending/sending не удаляются. Нужны мониторинг backlog, failed, свободного места и регулярная очистка.

### Подпись получателя

HTTP POST, `Content-Type: application/json`, `X-Cajeer-Delivery` (стабильный UUID доставки), `X-Cajeer-Timestamp` (Unix seconds), `X-Cajeer-Signature` (hex HMAC-SHA256). Подпись связывает ID, timestamp и SHA-256 **точных** полученных bytes:

```python
import hashlib, hmac, time

def verify(secret: str, delivery_id: str, timestamp: str, signature: str, raw: bytes) -> bool:
    if not timestamp.isascii() or not timestamp.isdigit() or len(timestamp) != 10:
        return False
    if abs(time.time() - int(timestamp)) > 300:
        return False
    canonical = '\n'.join(('cajeerlogs-webhook-v1', delivery_id, timestamp, hashlib.sha256(raw).hexdigest()))
    expected = hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature)
```

Получатель должен атомарно дедуплицировать `X-Cajeer-Delivery` вместе со своим side effect и отвечать 2xx после своей надёжной обработки. At-least-once delivery: если worker упал после отправки, но до сохранения результата, возможен повтор с тем же ID; не exactly-once. Поскольку webhook outbox пишется вместе с логами, неудача INSERT откатывает ingest, а idempotent replay не создаёт новый webhook. Worker не блокирует HTTP-запрос внешнего приложения.

## Проверки

```sh
python3 tests/python_ingest.py
python3 tests/sdk_wire.py             # PHP, Python, Node/tsc, JDK/kotlinc или Gradle
python3 tests/sdk_buffered_wire.py    # PHP durable batching, real HTTP, restart/fault; Monolog 3 с Composer
php tests/webhooks.php
# На отдельной PostgreSQL 17+ с DB_* и CREATE SCHEMA:
CAJEER_TEST_DATABASE=1 php tests/webhook_database.php
CAJEER_TEST_DATABASE=1 php tests/ingest_database.php
```

Wire tests используют настоящий HTTP-сервер с контролируемыми ответами, не подменяют PostgreSQL E2E. CI запускает DB integration suite и предыдущие тесты. `webhook_database.php` проверяет outbox, scoping, откат, replay, историю, backoff, lease, ручной retry, disable и cleanup. Production TLS/egress, нагрузку, отказоустойчивость файловой системы и фактическую доставку в receiver проверьте на целевом стенде. С 0.0.8 доступны очередь логов и scheduler: [Queue & Lifecycle](Queue-Lifecycle.md). ClickHouse и alerts остаются будущими релизами.


## 0.5.8 — Durable async receipts

POST `/ingest/async` подтверждает очередь, не логическую вставку. SDK используют идентичные байты и ключ при сетевом сбое, а после подтверждённого job ID проверяют `GET /ingest/jobs/{id}` без второго enqueue. Для process restart используется приватный durable outbox: в Python есть `DurableAsyncSpool`, в file collector — `--async` и SQLite job ID. Не следует повторять `dead`/`cancelled` автоматически. [Runbook](SDK-Delivery-Recovery.md).
