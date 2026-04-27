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
```

Если список задан, исходящие вебхуки разрешены только на перечисленные хосты. Поддерживается формат `example.com` и `*.example.com`.
