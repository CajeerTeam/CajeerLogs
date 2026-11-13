# HMAC signature

Рабочая подпись ingest v1 описана в [Log Ingestion](Log-Ingestion.md#hmac-опционально). Подписываются метод, путь, timestamp, nonce, idempotency key и SHA-256 исходного тела с использованием scoped Bearer-токена. Body-only подписи не принимаются. Webhooks и Event API ещё не реализованы.
