# Подпись HMAC

HMAC-подпись защищает ingest API от подмены тела запроса и повторной отправки.

## Заголовки

```http
X-Log-Timestamp: <unix timestamp>
X-Log-Nonce: <unique nonce>
X-Log-Signature: <hex hmac sha256>
```

## Каноническая строка

```text
<timestamp>\n<nonce>\n<sha256(raw_body)>
```

Подпись считается так:

```text
hex(hmac_sha256(canonical_string, raw_token))
```

Nonce сохраняется сервером и не может быть использован повторно в допустимом временном окне.

## Поведение по умолчанию

В `.env.example` параметр `INGEST_REQUIRE_SIGNATURE=true`, поэтому production-конфигурация требует HMAC-подпись для всех ingest-запросов. Для локальной отладки параметр можно временно выключить, но не используйте это в публичной сети.


Токен можно передавать как `X-Log-Token` или как `Authorization: Bearer <token>`. В production обязательны `X-Log-Timestamp`, `X-Log-Nonce` и `X-Log-Signature`; повторный nonce возвращает `409 replayed_nonce`.
