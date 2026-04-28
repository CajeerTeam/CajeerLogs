# Changelog

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

