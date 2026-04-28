# Changelog

## 0.8.2-ops-hardening

### queue-update-release-hardening

- API application errors теперь возвращают HTTP 500.
- Очередь jobs получила атомарный claim, retry/backoff и dead-letter.
- OpenAPI синхронизирован с HMAC-default, Bearer auth и `409 replayed_nonce`.
- UI управления пользователями блокирует слабые пароли и защиту последнего admin.
- Production-шаблон update center обновляется по release tag.
- Добавлены release workflow, labels sync workflow, alert pipeline smoke и update-check.


- Добавлены integration-проверки PostgreSQL и SQLite в CI.
- Усилена публикация GitHub Wiki и добавлена проверка релизной готовности.
- Добавлен атомарный PostgreSQL rate limit ingest API.
- Добавлена очередь доставки webhook-оповещений через `process-jobs.php`.
- Усилены проверки URL webhook для IPv4/IPv6 и DNS-записей.
- Уточнён update center: allowlist репозитория, опциональный режим tag-only и проверка чистого рабочего дерева.
- Добавлена страница интеграции aaPanel и social preview SVG.


## 0.8.1-wiki-hardening

- Wiki-исходники переведены на ASCII-имена файлов для корректной упаковки архивов.
- OpenAPI и Wiki синхронизированы с фактическим ответом ingest API: `200` и поле `inserted`.
- Добавлены страницы Wiki по установке, безопасности, оповещениям, HMAC, cron, журналам, ботам и инцидентам.
- Добавлена SSRF-защита исходящих вебхуков оповещений.
- Добавлены лимиты событий и байт в минуту для ingest API.
- Публичные примеры заменены на нейтральные `ExampleProject` и `ExampleBot`.
- Шаблон Nginx переведён на generic-домен `logs.example.com`.

## 0.8.0-automation-ux

- Подготовлена структура GitHub Wiki.
- Добавлена проверка wiki-исходников.
- Добавлена OpenAPI-спецификация ingest API.
- Убраны устаревшие интеграции документационных платформ.
## 0.8.1 — repository ops hardening

- Добавлен ручной workflow публикации GitHub Wiki.
- Добавлены `bin/schema-check.php` и `bin/ingest-smoke.php`.
- HMAC-подпись включена по умолчанию в `.env.example` и форме токена.
- Список ботов показывает лимиты событий и байт из БД.
- SQLite fallback получил подсчёт лимита байт ingest через `json_extract` с PHP-fallback.
- Усилена защита исходящих webhook-оповещений: запрет редиректов, опциональный allowlist и блокировка приватного DNS.
- Добавлены `.github/CODEOWNERS`, `.github/labels.yml` и Wiki-страница `Repository-settings.md`.
- Bootstrap больше не создаёт первого администратора со слабым шаблонным паролем.

