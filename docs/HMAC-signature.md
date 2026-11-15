# HMAC & Replay Protection — 0.2.5

Оба POST `/api/v1/ingest` и `/api/v1/ingest/async` поддерживают HMAC-SHA256. Bearer credential остаётся обязательным; подпись не расширяет project/source scopes. Polling jobs использует Bearer, не HMAC. Webhook signing — отдельный протокол.

## Wire format

Передавайте `Authorization: Bearer <token>`, `Content-Type: application/json`, `X-Log-Timestamp` (10 decimal digits, Unix seconds), `X-Log-Nonce` (32–64 lowercase hex characters), `X-Log-Signature` (64 lowercase hex characters) и исходный `Idempotency-Key` (обязателен для async). Для v2 добавьте `X-Log-Key-Id` (lowercase UUID). Все поля подписи обязательны вместе. Timestamp проверяется по часам PostgreSQL, допускается ±300 секунд включительно.

Canonical v1: LF-разделённые строки без завершающего LF:

```text
cajeerlogs-ingest-v1
POST
/api/v1/ingest
TIMESTAMP
NONCE
IDEMPOTENCY_KEY
SHA256_BODY_HEX
```

Canonical v2 добавляет UUID ключа:

```text
cajeerlogs-ingest-v2
KEY_UUID
POST
/api/v1/ingest
TIMESTAMP
NONCE
IDEMPOTENCY_KEY
SHA256_BODY_HEX
```

Замените путь на `/api/v1/ingest/async` для async. При отсутствии idempotency key оставьте пустую строку. SHA-256 вычисляется по точным байтам HTTP body, включая whitespace и Unicode. HMAC — `hex(HMAC-SHA256(secret, canonical UTF-8 bytes))`, secret — полный текст Bearer `cl_...` для v1 или отдельный `sk_...` для v2, без hex decoding. v2 не допускает query parameters; подпись должна использовать фактический путь приложения, включая API prefix. Reverse proxy не должен менять подписанные путь или тело. TLS обязателен в production.

## Политики

- `INGEST_REQUIRE_HMAC=true` требует подпись для всех ingest credentials.
- Проект: `GET/PUT /api/v1/projects/{project}/signing`, PUT `{"required":true}`. Требуется административная session и CSRF для изменения.
- Credential: `GET/POST /api/v1/projects/{project}/tokens/{token}/signing`. Требуется session с правом управления токенами; POST требует CSRF. `{"mode":"bearer","overlap_seconds":0,"expected_key_id":null}` делает v1 обязательным. `{"mode":"separate","overlap_seconds":0,"expected_key_id":null}` выпускает первый v2 key.
- Выпуск ingest token также принимает `signing_mode: "bearer"` или `"separate"`. Только project credentials с `logs:write` могут иметь signing policy. Ответ выпуска/ротации показывает новый секрет один раз, с Cache-Control no-store. GET возвращает только metadata, без ciphertext или секретов.

Требования объединяются OR; credential с отдельным ключом обязан использовать v2, unsigned и v1 отклоняются. Переход к v2 необратим для этого token UUID; первый выпуск переключает немедленно. Для поэтапной миграции создайте отдельный новый credential. В Admin Panel доступны политика проекта, выпуск и ротация ключа, а также тест ingest v1/v2.

## Ротация

Прочитайте metadata, затем POST `{"mode":"separate","expected_key_id":"CURRENT_UUID","overlap_seconds":3600}`. При устаревшем UUID вернётся 409. Перекрытие 0–604800 секунд; сохраняется максимум один предыдущий ключ, его срок определяется DB clock. 0 немедленно прекращает действие старого ключа. Следующая ротация заменяет предыдущий ключ даже если его overlap ещё не истёк. UUID bearer token, receipts и jobs не меняются при ротации signing key. При ротации самого Bearer credential новый UUID наследует обязательную политику, но получает новый отдельный ключ; старый credential сохраняет своё окно действия. Отзыв Bearer блокирует обе версии подписи.

Ключи шифруются Sodium secretbox с уникальным nonce, привязкой к token UUID и key UUID. Ключ шифрования выводится HKDF из эффективного `security.totp_key` с отдельным domain `cajeerlogs:ingest-signing:v2`. Сохраните SECURITY_TOTP_KEY (или эффективный SECURITY_HMAC_SECRET, если ключ TOTP выводится из него) в отдельном защищённом backup. Не меняйте master произвольно: расшифрование перестанет работать, readiness обнаруживает проблему. Автоматическая ротация master не предусмотрена. Не публикуйте одноразовый ответ с secret; не передавайте secrets в URL или логи.

## Replay и retries

Nonce уникален для token UUID независимо от версии подписи и ключа; хранится 11 минут. Подпись проверяется до и после получения транзакционной блокировки токена и проекта. Claim nonce, INSERT/queue и receipt используют одну транзакцию; rollback отменяет claim, отказ admission может сохранить claim вместе с rejection counters. Для каждой HTTP попытки создавайте новый nonce, timestamp и HMAC, сохраняя исходное тело и Idempotency-Key. Повтор nonce отклоняется; повтор операции с новым nonce возвращает прежний результат в существующем окне идемпотентности. После ротации Bearer UUID namespace меняется; повторите незавершённую операцию старым действительным credential. Уже принятые async jobs не требуют повторной HMAC проверки.

## SDK / upgrade

Четыре SDK поддерживают старый signed mode и отдельный key ID/secret. Python: `CajeerLogsClient(base_url=..., token=..., signing_key_id=..., hmac_secret=...)`. Смотрите README каждого SDK и `tests/sdk_wire.py` для конкретных конструкторов. Коллектор: `CAJEER_SIGNING_KEY_ID`, `CAJEER_SIGNING_SECRET` (оба обязательны для v2).

Остановите старые workers и ingest, сделайте backup и примените миграцию 0013 через `php bin/cajeer migrate`. Выполните `migrate:verify`, `db:verify` и `doctor`, затем запустите новый runtime. Старые миграции не меняются; существующие токены и проекты сохраняют optional v1, если глобальная политика не требует подпись. Тесты: `php tests/hmac.php`; на отдельной тестовой PostgreSQL БД `CAJEER_TEST_DATABASE=1 php tests/hmac_database.php`; SDK: `python3 tests/sdk_wire.py`. Public Preview до прохождения полного PostgreSQL release gate.
