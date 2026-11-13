# Python client

Минимальный клиент рабочего Ingest API 0.0.4. Требуются зарегистрированный источник (его slug), токен `logs:write` и установленный CajeerLogs с PostgreSQL.

```python
from cajeer_logs_client import CajeerLogsClient

client = CajeerLogsClient("https://logs.example.com", "cl_...", signed=True)
result = client.ingest({
    "timestamp": "2026-11-13T12:00:00Z",
    "source": "app",
    "level": "info",
    "message": "Started",
}, idempotency_key="job-42-start")
print(result["event_ids"])
```

`ingest` также принимает список событий. Повтор с тем же ключом и идентичным телом в течение 24 часов не создаёт дублей; signed=True генерирует свежий nonce для каждой попытки. Ошибки HTTP передаются вызывающему коду (`urllib.error.HTTPError`), автоматических retries в этой версии нет. Не повторяйте запросы с новым ключом после неопределённого сетевого результата. Используйте HTTPS; клиент не следует редиректам с credentials.

HMAC v1 использует **тот же scoped Bearer-токен**, не глобальный `SECURITY_HMAC_SECRET`. Старый параметр `hmac_secret` допустим только если равен `token`; предпочтительнее `signed=True`. Формат подписи: [Log Ingestion](../../docs/Log-Ingestion.md).
