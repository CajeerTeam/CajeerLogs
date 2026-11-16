# CajeerLogs 0.1.0 — First Public Release

Первый публичный MVP (public preview, не LTS). PostgreSQL 17+, PHP 8.4/8.5. Основной сервер не требует Composer, Node.js или Redis: готовая SPA поставляется в `public/admin/assets`. Дополнительные драйверы и расширения из roadmap не поддерживаются. API v1 остаётся совместимым с 0.0.9.

## Чистая установка (PHP-FPM + Nginx + PostgreSQL, Docker Compose v2)

Docker Compose требует Docker Engine с Compose v2. Понадобятся настоящий DNS-домен и TLS-сертификат (`fullchain.pem`, `privkey.pem`) в отдельном каталоге, доступном Docker. Порт 443 — единственный опубликованный; PostgreSQL и PHP-FPM не публикуются. Для установки на хосте без Docker см. [Database & Installer](Database-Installer.md), [Nginx](Nginx-deployment.md), [Security & Operations](Security-Operations.md).

```sh
cp deploy/production/environment.example .env.production
chmod 600 .env.production
# Заполните APP_URL, CAJEER_TLS_DIR и три независимых случайных секрета:
# openssl rand -hex 32 (выполнить отдельно для каждого секрета).
# Не используйте шаблонные значения. APP_URL должен быть HTTPS-origin без пути.
# Если используете не 443, укажите порт в APP_URL и CAJEER_HTTPS_PORT.
# Создайте защищённый TLS-каталог и положите туда сертификат и ключ.
docker compose --env-file .env.production -f compose.production.yaml build
docker compose --env-file .env.production -f compose.production.yaml up -d db
# Передайте первый пароль через окружение, не через аргументы командной строки.
read -r -p 'Admin email: ' CAJEER_ADMIN_EMAIL
read -r -s -p 'Admin password: ' CAJEER_ADMIN_PASSWORD; echo
export CAJEER_ADMIN_EMAIL CAJEER_ADMIN_PASSWORD
docker compose --env-file .env.production -f compose.production.yaml run --rm -e CAJEER_ADMIN_EMAIL -e CAJEER_ADMIN_PASSWORD install
unset CAJEER_ADMIN_PASSWORD CAJEER_ADMIN_EMAIL
docker compose --env-file .env.production -f compose.production.yaml up -d
```

Откройте `https://<ваш-домен>/admin/`. При первом входе администратор должен настроить TOTP и сохранить recovery-коды. Создайте проект, источник, токен `logs:write` и отправьте событие согласно [Log Ingestion](Log-Ingestion.md). Проверка `/ready` должна вернуть HTTP 200, версия 0.1.0. `/metrics` требует настроенный PROMETHEUS_TOKEN. Приложение запускается от `www-data` с read-only root filesystem; конфигурация `/config/.env` и данные в отдельных named volumes. Установщик атомарно создаёт конфигурацию в отдельном writable контейнере; приложение и workers читают её в режиме read-only. `CAJEER_ENV_FILE` — опциональный абсолютный путь к `.env` вне public, для read-only deployments. На обычном хосте применяется `.env` из корня проекта.

Compose хранит пароли в конфигурации контейнеров; доверяйте администраторам Docker (доступ к daemon равносилен root). DB_SSLMODE=disable используется только внутри выделенной Docker-сети. Для удалённой PostgreSQL настройте TLS verify-full. Сертификаты обновляйте с перезапуском gateway. Для webhooks задайте `CAJEER_WEBHOOK_*` в защищённой `/config/.env` (редактировать через доверенный администраторский контейнер с writable config volume, затем перезапустить сервисы). Не используйте `docker compose down -v` на рабочей установке.

## Обновление с 0.0.9 (существующий хост)

1. Остановите веб-сервер/ingest и queue, scheduler, webhook workers. Сделайте `php bin/cajeer backup:create`, `backup:verify ID`, отдельно сохраните `.env`, TLS, внешний backup и дерево приложения. Проверьте восстановление на отдельном тестовом сервере. Запишите контрольную сумму и результат `migrate:status`.
2. Распакуйте **архив изменённых файлов** поверх 0.0.9 с заменой файлов (не удаляйте `.env`, storage, vendor). Для полного релиза используйте отдельный пустой каталог; перенесите `.env` и существующий storage, сохраните владельцев и права. Не меняйте TOTP-ключ.
3. Запустите `php bin/cajeer version`, `php bin/cajeer migrate`, `php bin/cajeer migrate:status`, `php bin/cajeer doctor`, `php bin/cajeer openapi:lint`. Миграции 0001–0008 неизменны, новых нет. При несовпадении истории не продолжайте запуск.
4. Перезапустите PHP-FPM (включая OPcache), веб-сервер и workers. Проверьте `/ready`, `/admin/`, вход, поиск старого события, запись нового и обработку async job. При ошибке остановите сервисы, верните старое дерево и конфигурацию; схема не менялась. Не восстанавливайте snapshot поверх активной БД. Если нужно восстановление, используйте отдельную пустую БД и осознанно переключите приложение.

Резервные копии 0.0.9 можно восстановить из 0.1.0, поскольку обе версии имеют идентичную историю миграций. После восстановления история обязательно сверяется с текущими SQL-файлами. Сессии, API-токены и recovery-коды отзываются как в 0.0.9. Старые резервные копии других версий не поддерживаются этим переходом. Бэкап не включает `.env`/TOTP ключи, внешние файлы и WAL/PITR. Не запускайте восстановленные workers до сверки outbox/очереди.

## Сборка и контроль релиза

```sh
python3 scripts/release.py build release-out/CajeerLogs-0.1.0.zip
(cd release-out && sha256sum -c CajeerLogs-0.1.0.zip.sha256)
python3 scripts/release.py verify release-out/CajeerLogs-0.1.0.zip --extract /tmp/cajeer-release
```

Для проверки sidecar: `(cd release-out && sha256sum -c CajeerLogs-0.1.0.zip.sha256)`. Дистрибутив содержит полный runtime, документацию, SDK-исходники, production/development deployment и тесты. Сборка использует фиксированные ZIP timestamps, сортировку и SHA-256 для каждого файла; локальные секреты, runtime storage и зависимости не включаются. SHA-256 — контроль целостности, не подпись издателя; скачивайте архив из доверенного источника. Верификатор запрещает traversal, symlinks, повторяющиеся записи и лишние файлы. Docker-образы строятся отдельно, базовые теги могут обновляться (не обещается побайтовая воспроизводимость образов).

GitHub Actions проверяет существующие PHP/SDK/browser/PostgreSQL tests на PHP 8.4 и 8.5. Также CI извлекает точный исходный коммит 0.0.9 (`5af718bc0df3870c19238137221e8a52ce718348`), устанавливает его на отдельной тестовой схеме, проверяет обновление с сохранением сессии/токена/логов и pending job и восстанавливает его резервную копию. Отсутствие исходного коммита в истории блокирует этот gate. Отдельный release job зависит от их успеха, собирает и проверяет полный ZIP, разворачивает его в production Compose с временным TLS-сертификатом, проверяет реальный HTTP→PHP-FPM→PostgreSQL, TOTP, ingestion, очередь, поиск, backup, затем публикует артефакт CI. Это обязательный gate, а не заявление о выполнении проверок. До успешного CI и восстановления в вашей инфраструктуре выпуск остаётся public preview. Локальный режим проверки: `python3 tests/release.py`, `php tests/release_runtime.php`.

## Эксплуатация

- Compose автоматически запускает queue, scheduler и webhook worker; backup запускайте регулярно через `docker compose ... exec app php bin/cajeer backup:create`, выносите копии на отдельное зашифрованное хранилище. Для хоста используйте systemd timers из `examples/systemd`.
- Проверяйте `db:doctor`, `queue:status`, `scheduler:run`, `webhook:history`, `backup:verify`. Readiness проверяет PostgreSQL WAL fsync, историю миграций, таблицы и корректность TOTP-ключа для одного enrolled пользователя (не полный криптографический аудит всех записей).
- Отдельный мониторинг доступности, свободного диска, backup и очередей обязателен. Retention по умолчанию отключён. ClickHouse, Redis queue, RabbitMQ, OpenSearch, Incidents, Marketplace и HA не входят в 0.1.0.

For 0.4.7 Host validation, trusted proxies, TLS verification and renewal, see [Production Security](Production-Security.md).
