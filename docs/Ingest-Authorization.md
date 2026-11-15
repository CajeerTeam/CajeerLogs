# Ingest Authorization — 0.2.4

Версия 0.2.4 усиливает существующий API v1. Новых миграций нет; 0001–0012 не изменяются. Канал public-preview до прохождения реального PostgreSQL release gate.

## Авторизация

`IngestAuthorization` и неизменяемый `IngestPrincipal` обслуживают `POST /api/v1/ingest`, `POST /api/v1/ingest/async` и `GET /api/v1/ingest/jobs/{job}`. Принципал содержит только token UUID, project UUID, необязательный source UUID, service account UUID, проверенные scopes и request quota. Секрет передаётся только для аутентификации/HMAC и не сохраняется в принципале, метриках или диагностике.

- Требуется действующий Bearer credential с `logs:write`, назначенный проекту. Браузерная сессия, read-only или системный токен не дают права ingest. `logs:read` отдельно контролируется Identity и существующими `/logs` endpoints; source-bound токены читают только свой источник. HMAC-протокол не меняется.
- Первичная проверка не обновляет `last_used_at`; телеметрия обновляется в транзакции после получения row lock, не чаще одного раза в пять минут. Это устраняет ожидание блокировки строки токена за пределами транзакционного SQL budget.
- IP/token request limits списываются до транзакции и не повторяются при её retry. Транзакционная проверка получает `FOR UPDATE` на строке токена, заново проверяет отзыв, срок действия по часам PostgreSQL, активность service account, project/source и scopes. Изменение области доступа между проверками отклоняется, изменение индивидуальной квоты даёт 503 `authorization_changed` с `Retry-After: 1`. Клиент повторяет запрос с исходным idempotency key и body, новым HMAC nonce/timestamp.
- Перед подтверждением успешного INSERT, enqueue или replay выполняется ещё одна проверка под тем же row lock. Если expiry наступил во время обработки, транзакция откатывается. Эта проверка происходит до COMMIT; абсолютная проверка в момент передачи HTTP-ответа невозможна. Отзыв, начатый после получения ingest row lock, ждёт завершения транзакции; операции, подтверждённые до отзыва, остаются действительными.
- Разрешение slug ограничено project UUID и при source-bound token также source UUID. Неизвестный, чужой или неподходящий источник дают одинаковый 403. Весь mixed-source batch должен быть разрешён. Проектные/source admission policies и event quotas сохраняются; replay ранее принятой операции не списывает event quota повторно.
- Job status дополнительно связывает job ID с точным token ID и project ID. Другой активный токен, в том числе преемник после rotation, получает 404. Отозванный/истёкший credential получает 401. Polling не требует HMAC (как в предыдущих релизах). Ранее принятые jobs продолжают обработку независимо от последующего отзыва/expiry/архивации.

## Request budgets

`API_RATE_LIMIT_PER_MINUTE` теперь применяется общей корзиной `ingest-ip:` на один peer и `ingest-token:` на один token UUID для sync, async и polling вместе. `requests_per_minute` credential остаётся общим для авторизованных bearer-запросов API через `credential:`. Остальные API сохраняют свои отдельные общие endpoint limits. HTTP retry/replay/polling расходуют request quota; повторная проверка внутри транзакции не расходует её. Токен и IP имеют независимые бюджеты, отказ в любой корзине возвращает HTTP 429 + Retry-After. Фиксированные окна допускают всплеск на границе минуты. При обновлении прежние отдельные job-status buckets больше не используются и очищаются штатным scheduler.

Только серверный `REMOTE_ADDR` используется для IP bucket; `Forwarded`, `X-Forwarded-For`, `X-Real-IP` не доверяются. Эквивалентные IPv6 и IPv4-mapped IPv6 нормализуются. Если peer невалиден, используется общий `unknown` bucket. При reverse proxy настройте доверенные proxy addresses в веб-сервере, который передаёт корректный REMOTE_ADDR; не доверяйте произвольным заголовкам клиента. Без такой настройки общий адрес прокси делит IP budget между клиентами.

## SQL defense in depth

`LogRepository::storeBatch` принимает только JSON-массив с точным разрешённым набором scopes (logs:write либо logs:read+logs:write), без дубликатов или неизвестных значений. Уже принятые jobs намеренно не проверяют текущий revoke/expiry; они обязаны соблюдать исторические project/source/scopes. `PostgresQueueDriver::enqueue` проверяет для нового задания активный токен, project, каждый slug/UUID и scope под row lock до admission/capacity. Внутренний `push` также проверяет область; это доверенный интерфейс репозитория, не публичная точка приёма. Все HTTP записи проходят IngestService с авторизацией, HMAC и квотами. Внешние ключи из 0012 остаются обязательной защитой.

JSON-объекты scopes, включая числовые ключи, не приводятся к спискам. Административный API выпуска/ротации требует JSON array. Неподдерживаемые исторические токены fail closed; исправьте их явным отзывом и выпуском корректного credential, не изменяя секреты через SQL.

## Наблюдаемость и ошибки

`/metrics` сохраняет существующие `cajeerlogs_ingest_requests_total`, `cajeerlogs_ingest_rejections_total`, `cajeerlogs_ingest_duration_seconds_*`; добавлен mode `job`, причины `authorization_changed`, `job_not_found`, `invalid_job`. `unauthorized` отличает невалидные credentials/HMAC от `forbidden` (доступ); `rate_limited` — ограничение запросов. Метрики локальные для экземпляра, без UUID, IP, токенов или данных события. 503 безопасен для retry только при сохранении key/body; для sync без idempotency key неизвестный COMMIT может привести к дубликату.

## Обновление и проверки

Остановите ingress и workers, создайте backup на 0.2.3, наложите changed-files ZIP, сохраните `.env` и ключи. `php bin/cajeer version`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:verify`, `php bin/cajeer doctor`, `php bin/cajeer openapi:lint`. Перезапустите PHP-FPM и workers. Новых env-параметров нет. Backup 0.2.3 совместим.

`php tests/ingest_authorization.php` проверяет реальный PHP policy/runtime через контролируемые PDO statements. Обязательные PostgreSQL suites `tests/ingest_database.php` и `tests/log_repository_database.php` проверяют конкурентный revoke, изменение request policy, общий rate limit, SQL scope и enqueue. Запускайте с `CAJEER_TEST_DATABASE=1` только на отдельной тестовой БД. `scripts/certify.py` использует один полный ZIP и PostgreSQL 17/18 × PHP 8.4/8.5; обновления 0.1.0 и 0.2.3. Само наличие тестов не подтверждает прохождение сертификации.
