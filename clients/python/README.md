# CajeerLogs Python SDK (0.1.2)

Python 3.10+, без runtime-зависимостей. Из корня репозитория `python3 -m pip install ./clients/python` либо добавьте `clients/python` в PYTHONPATH. Для коллектора требуется Unix/Linux, SQLite и `fcntl`.

```python
import os
from datetime import datetime, timezone
from cajeer_logs_client import CajeerLogsClient

client = CajeerLogsClient(os.environ['CAJEER_URL'], os.environ['CAJEER_TOKEN'], signed=True)
result = client.ingest({
    'timestamp': datetime.now(timezone.utc).isoformat(timespec='microseconds'),
    'source': 'app', 'level': 'info', 'message': 'Started',
}, idempotency_key='job-42-start')
print(result['event_ids'])
```

`ingest` принимает single event, список событий или объект `events`. Клиент проверяет полное подтверждение HTTP 201/200. Повторяемые ошибки сети, 408/429/500/502/503/504 обрабатываются с backoff (3 попытки по умолчанию); ключ и сериализованное тело сохраняются, для HMAC каждый раз новый nonce. Неповторяемый HTTP-код возвращается как `urllib.error.HTTPError`; исчерпанный retry — `DeliveryError` с ключом и статусом. При неизвестном результате повторяйте неизменные байты и тот же ключ до истечения 24-часового окна idempotency. `send_body(bytes, key)` используется для долговременного буфера. Клиент не следует redirects; HTTP вне loopback запрещён без явного `allow_insecure_http=True` (production CajeerLogs всё равно требует HTTPS).

`CajeerLogsHandler(client, 'app', 'backend')` — stdlib `logging.Handler`; синхронно отправляет запись, ошибки направляет в стандартный `handleError`. Не используйте его для логирования самого транспорта и не блокируйте критический request path при высокой нагрузке; пишите JSONL в локальный файл и используйте коллектор.

```sh
export CAJEER_URL=https://logs.example.com
# CAJEER_TOKEN передайте из секретного хранилища или файла окружения с mode 0600.
cajeer-logs-collector --file /var/log/myapp/app.jsonl --state /var/lib/cajeer-collector/app.sqlite --source app --format jsonl --signed
```

Протокол HMAC, восстановление коллектора, ротация, ограничения и webhook-интеграция: [SDK & Integrations](../../docs/SDK-Integrations.md).
