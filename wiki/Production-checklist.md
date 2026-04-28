# Чек-лист эксплуатации

Перед запуском проверьте:

- [ ] `APP_DEBUG=false`.
- [ ] `DB_CONNECTION=pgsql`.
- [ ] `LOGS_ENV_FALLBACK_LOGIN=false`.
- [ ] `UPDATE_ALLOW_WEB=false`.
- [ ] `INGEST_REQUIRE_SIGNATURE=true` для внешних отправителей.
- [ ] `LOGS_TOKEN_PEPPER` и `PRIVACY_HASH_PEPPER` уникальны.
- [ ] `storage/*` доступен на запись только приложению.
- [ ] Web root указывает на `public/`.
- [ ] Закрыт прямой доступ к `app/`, `bin/`, `storage/`, `.env`.
- [ ] Cron-задачи настроены.
- [ ] `php bin/self-test.php` проходит.
- [ ] `php bin/wiki-check.php` проходит.
- [ ] `php bin/schema-check.php` проходит.
- [ ] Первый администратор создан через `php bin/make-user.php`, а шаблонный пароль из `.env` не используется.
- [ ] Для webhook-оповещений задан `ALERT_WEBHOOK_ALLOWED_HOSTS` или осознанно выключен `ALERT_WEBHOOK_REQUIRE_ALLOWLIST`.
- [ ] Проверочный ingest проходит через `php bin/ingest-smoke.php --signed`.
