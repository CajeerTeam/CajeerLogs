# Настройка окружения

Основная конфигурация хранится в `.env`. Шаблон находится в `.env.example`.

## Обязательные параметры

```env
APP_URL=https://logs.example.com
DB_CONNECTION=pgsql
DB_DATABASE=cajeer_logs
DB_USERNAME=cajeer_logs
DB_PASSWORD=change_me
LOGS_TOKEN_PEPPER=change_me_generate_64_hex_chars
PRIVACY_HASH_PEPPER=change_me_generate_64_hex_chars
DOCS_URL=https://github.com/CajeerTeam/CajeerLogs/wiki
```

Сгенерируйте pepper-значения:

```bash
openssl rand -hex 32
```

## Лимиты ingest API

```env
INGEST_MAX_BATCH_SIZE=100
INGEST_MAX_BODY_BYTES=2097152
INGEST_MAX_EVENTS_PER_MINUTE=3000
INGEST_MAX_BYTES_PER_MINUTE=10485760
INGEST_REQUIRE_SIGNATURE=true
```

## Оповещения

```env
ALERT_WEBHOOK_ALLOWED_HOSTS=
ALERT_WEBHOOK_BLOCK_PRIVATE_DNS=true
ALERT_WEBHOOK_REQUIRE_ALLOWLIST=false
ALERT_WEBHOOK_TIMEOUT_SECONDS=5
```

Если список `ALERT_WEBHOOK_ALLOWED_HOSTS` задан, исходящие вебхуки разрешены только на перечисленные хосты. Поддерживается формат `example.com` и `*.example.com`. В строгом production-режиме можно включить `ALERT_WEBHOOK_REQUIRE_ALLOWLIST=true`.

## Импорт Nginx/aaPanel

```env
NGINX_LOG_DIR=/www/wwwlogs
NGINX_LOG_PROJECT=Web Sites
NGINX_LOG_ENVIRONMENT=production
AAPANEL_LOG_DIR=/www/wwwlogs
AAPANEL_LOG_PROJECT=Web Sites
AAPANEL_LOG_ENVIRONMENT=production
AAPANEL_LOG_IMPORT_MAX_LINES=1000
```

Используйте `NGINX_*` для новых установок. Переменные `AAPANEL_*` сохранены для совместимости с типовым расположением журналов aaPanel.
