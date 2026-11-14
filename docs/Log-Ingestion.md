# Log Ingestion 0.0.4

## Установка и обновление

Рабочий синхронный контур: HTTP → проверка scoped Bearer → лимиты → валидация → redaction → PostgreSQL transaction → HTTP 201. Требуются PHP 8.4+, PDO `pdo_pgsql`, PostgreSQL 17+. Перед обновлением 0.0.3 сохраните резервные копии кода и БД, наложите файлы релиза и выполните:

```sh
php bin/cajeer migrate
php bin/cajeer migrate:status
php bin/cajeer db:doctor
php bin/cajeer version
```

Миграция `0004_log_ingestion.sql` добавляет атрибуцию токена, таблицы идемпотентности и nonce, индексы срока действия. Миграции 0001–0003 не изменяются. Исторические записи без проекта/источника сохраняются (`CHECK ... NOT VALID`), но новые записи обязаны принадлежать зарегистрированному источнику. Старые записи не присваиваются проектам автоматически. Откат к 0.0.3 — восстановление согласованных копий кода и БД, не ручное редактирование истории миграций.

## Получение credentials

Через API 0.0.3 создайте проект, источник и токен `logs:write` (см. [Identity & Access](Identity-Access.md)). `source` в событии — **slug** зарегистрированного источника, а не произвольное имя приложения или UUID. Проект определяется только из токена. Токен проекта разрешает его источники; source-bound токен — только один источник. Неверный или отозванный токен даёт 401; отсутствие scope или недоступный источник — 403. Cookie-сессии для ingest запрещены, включая admin. Не передавайте пароль администратора вместо токена.

## Пример

```sh
# CAJEER_TOKEN задайте безопасно в окружении, не в истории shell.
cat > event.json <<'JSON'
{
  "timestamp": "2026-11-13T12:00:00.123456+03:00",
  "source": "app",
  "level": "error",
  "message": "Database connection failed",
  "service": "backend",
  "host": "app-01",
  "context": {"error_code": "DB_DOWN", "password": "never-store-this"},
  "trace_id": "trace-123",
  "request_id": "req-123",
  "tags": ["database", "production"]
}
JSON
curl --fail-with-body -sS https://logs.example.com/api/v1/ingest \
  -H "Authorization: Bearer ${CAJEER_TOKEN}" \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: demo-event-001' \
  --data-binary @event.json
```

Пример HTTP 201 (UUID генерируется сервером):

```json
{"accepted":true,"received":1,"stored":1,"rejected":0,"event_ids":["b0a3b38a-b2c4-4d9b-807e-8c98b0633eaa"],"replayed":false}
```

Для пакета используйте `{"events":[{...},{...}]}`. Порядок `event_ids` совпадает с порядком событий. Нет частичного успеха: любое невалидное событие, запрещённый источник или ошибка INSERT откатывает весь пакет. HTTP 201 только после COMMIT (`SET LOCAL synchronous_commit=on`, обычная настройка PostgreSQL fsync обязательна), HTTP 200 для повторной доставки по ключу. 202 не используется: асинхронная очередь ещё не реализована. В случае разрыва связи после COMMIT результат может быть неизвестен клиенту; используйте тот же idempotency key и те же байты.

## Формат и ограничения

- Обязательные `timestamp`, `source`, `level`, `message`. RFC3339 с timezone, 0–6 дробных цифр, корректная календарная дата; UTC в `occurred_at`. `received_at` задаётся PostgreSQL. Уровни PSR-3, регистр нормализуется.
- `source`: slug 1–100 ASCII символов. `message`: непустая строка до 16 KiB. `service`, `host`: до 255 байт; `trace_id`, `request_id`: до 128 байт. Необязательные строковые поля могут быть null.
- `context`: JSON object до 32 KiB, пустой объект остаётся `{}`, массивы внутри объекта сохраняют тип. Целые числа больше диапазона PHP int сохраняются строками. `tags`: до 32 непустых строк, каждая до 128 байт, дубли удаляются. Неизвестные поля, NUL, бесконечные числа, чрезмерная вложенность отклоняются.
- Максимум 100 событий, 64 KiB на сериализованное событие, весь ingest body ограничен 1 MiB и меньшим глобальным `APP_MAX_BODY_BYTES`, если он задан. Ограничение ingest защищает декодирование JSON от чрезмерного потребления памяти; глобальный лимит для других маршрутов может быть выше. Ограничения строк и JSON измеряются в UTF-8 байтах, а не символах. Маскированные значения также проверяются по размеру.
- `Content-Type: application/json` или `+json`; сжатые body не поддерживаются. В production обязательны HTTPS и `APP_URL=https://...`; HTTPS server variable должен задаваться доверенным сервером, а не клиентским X-Forwarded-Proto.
- `API_RATE_LIMIT_PER_MINUTE` ограничивает запросы отдельно по IP и токену (по умолчанию 600). Один пакет считается одним запросом. HTTP 429 содержит Retry-After. Лимиты хранятся в PostgreSQL и действуют между PHP workers. При проксировании настройте доверенный real IP на уровне web server, иначе общий IP прокси будет общим лимитом.
- Redaction маскирует известные ключи (password, access_token, api_key, cookies, authorization и др.) во вложенных объектах и распространённые формы секретов в строках. Это best effort, не DLP: не отправляйте секреты, персональные данные или произвольные credentials в логах. Никакое маскирование не гарантирует распознавание всех секретов. Токен и исходное тело не записываются в receipts/nonce или сообщения ошибок.

## Идемпотентность

`Idempotency-Key`: 1–128 ASCII символов `[A-Za-z0-9._:-]`, первый символ — буква/цифра. Область — конкретный API-токен, срок — 24 часа с успешной записи. Повтор с точно тем же телом возвращает HTTP 200, прежние UUID, `stored:0`, `replayed:true`. Другие байты с тем же ключом — 409. После истечения 24 часов ключ может создать новые записи. Без ключа каждое успешное обращение создаёт новые UUID. Хранится хеш ключа и HMAC тела, не исходное тело. Не используйте ключ как секрет. Ошибка транзакции не резервирует ключ.

Отзыв токена сериализуется с записью через блокировку строки токена в транзакции: после завершения отзыва новые записи по этому токену невозможны. Одновременные запросы с одним токеном сериализуются на время транзакции. Для высокой параллельности используйте несколько scoped-токенов; нагрузочный предел пока не измерен.

## HMAC (опционально)

`INGEST_REQUIRE_HMAC=false` по умолчанию для существующих Bearer-клиентов. `true` требует три заголовка: `X-Log-Timestamp` (Unix seconds), `X-Log-Nonce` (32–64 lowercase hex, случайный для каждого запроса), `X-Log-Signature` (64 lowercase hex). Если передан хоть один заголовок, проверяются все три даже при false.

Подпись: HMAC-SHA256 с **полным Bearer token** в качестве ключа. Это не отдельный фактор защиты от компрометации токена, а проверка целостности и свежести подписанного запроса. TLS обязателен в production. Каноническая UTF-8 строка, ровно семь строк, разделитель LF, без завершающего LF:

```text
cajeerlogs-ingest-v1
POST
/api/v1/ingest
<unix timestamp>
<nonce>
<Idempotency-Key or empty string>
<lowercase sha256 hex of raw body>
```

Путь берётся из запроса (учитывает `API_PREFIX`). Query string не участвует в обработке ingest. Окно времени ±300 секунд. Nonce фиксируется в БД на 11 минут в одной транзакции с данными. Повтор nonce успешной записи отклоняется 409; при retry с тем же idempotency key генерируйте новую подпись и nonce. Ошибки подписи 401. Для синхронизации часов используйте NTP.

```python
import hashlib, hmac, secrets, time
body = open("event.json", "rb").read()
timestamp = str(int(time.time()))
nonce = secrets.token_hex(16)
canonical = "\n".join(["cajeerlogs-ingest-v1", "POST", "/api/v1/ingest",
                       timestamp, nonce, "demo-event-001", hashlib.sha256(body).hexdigest()])
signature = hmac.new(token.encode(), canonical.encode(), hashlib.sha256).hexdigest()
# Send timestamp, nonce, signature in the three X-Log-* headers; send exact body bytes.
```

Python-клиент из `clients/python` поддерживает этот протокол через `signed=True`. Старый body-only HMAC не принимается. С 0.0.7 исходящие `logs.ingested` webhooks используют отдельную подпись и transactional outbox: [SDK & Integrations](SDK-Integrations.md).

## Эксплуатация

Запускайте `php bin/cajeer auth:cleanup` через cron (например каждую минуту или каждые 5 минут); команда удаляет просроченные сессии, rate-limit buckets, receipts и nonce пакетами до 10000 на таблицу. Не удаляет log_events. При высокой частоте следите за отставанием cleanup и размером таблиц; настройте частоту. Политики хранения самих логов — следующий этап, до него контролируйте свободное место и резервные копии PostgreSQL.

`/ready` проверяет 0004 и права к новым таблицам. HTTP 503 при недоступной БД или временной SQL-ошибке. Ошибки 400 (JSON), 401 (credential/signature), 403 (scope/HTTPS), 409 (nonce/key conflict), 413 (size), 415 (media), 422 (validation), 429 (rate), 500 (unexpected). Ответы ошибок не содержат исходного события. С 0.0.5 сохранённые события доступны через [Log Search](Log-Search.md); графическая Admin Panel доступна с 0.0.6; расширенная аналитика остаётся будущим релизом.

Проверки:

```sh
php tests/ingest.php
php tests/runtime.php
php tests/identity.php
python3 tests/http_runtime.py
php bin/cajeer openapi:lint
# Separate test database with CREATE SCHEMA privileges and DB_* exported:
CAJEER_TEST_DATABASE=1 php tests/ingest_database.php
```

PostgreSQL integration suite проверяет обновление 0.0.3, запись через независимое соединение, реальные HTTP-запросы, откат пакета при SQL-ошибке, scoping, отзыв/истечение, rate limiting, HMAC/replay, idempotency и cleanup. CI запускает его на PostgreSQL 17 с PHP 8.4/8.5. До публичной production эксплуатации выполните CI и нагрузочные проверки на целевой инфраструктуре.
