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
