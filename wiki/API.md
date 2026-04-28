# API

Основной внешний контракт — приём событий:

```http
POST /api/v1/ingest
Content-Type: application/json
X-Log-Token: RAW_TOKEN
```

Тело запроса:

```json
{
  "logs": [
    {
      "ts": "2026-04-27T19:00:00Z",
      "level": "ERROR",
      "project": "Cajeer",
      "bot": "Worker",
      "environment": "production",
      "message": "Ошибка обработки задачи",
      "context": {"job_id": "123"}
    }
  ]
}
```

Машиночитаемая спецификация хранится в `openapi.yaml` в корне репозитория.

## Ответы

- `200` — пачка принята и сохранена.
- `400` — некорректный payload.
- `401` — отсутствует токен или неверна HMAC-подпись.
- `403` — токен отключён или не соответствует политике.
- `413` — тело запроса слишком большое.
- `429` — превышен лимит запросов, событий или объёма данных.


## Успешный ответ

```json
{
  "ok": true,
  "inserted": 1,
  "message": "Журналы приняты."
}
```

## Smoke test

Для проверки работающего endpoint используйте:

```bash
php bin/ingest-smoke.php --url=https://logs.example.com/api/v1/ingest --token=RAW_TOKEN --signed
```
