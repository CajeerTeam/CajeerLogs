# Интеграция Python

Клиент находится в `clients/bot.py`. Его можно скопировать в приложение как `remote_log_handler.py`.

## Переменные окружения

```env
REMOTE_LOGS_ENABLED=true
REMOTE_LOGS_URL=https://logs.example.com/api/v1/ingest
REMOTE_LOGS_TOKEN=clog_...
REMOTE_LOGS_PROJECT=ExampleProject
REMOTE_LOGS_BOT=ExampleBot
REMOTE_LOGS_ENVIRONMENT=production
REMOTE_LOGS_LEVEL=INFO
REMOTE_LOGS_BATCH_SIZE=25
REMOTE_LOGS_FLUSH_INTERVAL=5
REMOTE_LOGS_SIGN_REQUESTS=true
```

## Подключение

```python
import logging
from remote_log_handler import RemoteLogHandler

logging.basicConfig(level=logging.INFO)
logging.getLogger().addHandler(RemoteLogHandler())
```

После запуска проверьте `/logs` и `/bots/health`.
