# API Token Management — 0.1.8

## Обновление 0.1.7 → 0.1.8

Остановите ingest и фоновые процессы, сделайте `php bin/cajeer backup:create` на предыдущей версии и отдельно сохраните конфигурацию и ключи. Наложите архив изменённых файлов, выполните `php bin/cajeer migrate:plan`, `php bin/cajeer migrate`, `php bin/cajeer migrate:verify`, `php bin/cajeer db:doctor`, затем перезапустите PHP-FPM и workers. Миграция `0011_token_management.sql` обязательна; файлы 0001–0010 не изменяются. Новая таблица `service_accounts` требует обычных runtime-прав SELECT/INSERT/UPDATE (при разделённых ролях проверьте default privileges). Индексы создаются в транзакции, планируйте окно обслуживания.

Существующие токены сохраняют UUID, hash, scopes, project/source, срок действия и связи с логами, receipts и jobs. Новые поля получают безопасные значения по умолчанию (revision 1, пустое описание, без дополнительной квоты, без service account и successor). Старые бессрочные credentials не меняются автоматически; новый публичный выпуск и ротация всегда имеют срок 60–31536000 секунд (по умолчанию 30 дней). Исторический внутренний метод `TokenRepository::create` оставлен для bootstrap/legacy-сценариев; публичный API использует `issue` с ограниченным TTL.

## Scopes и авторизация

Токен проекта имеет непустое уникальное подмножество `logs:read`, `logs:write`; он привязан к одному проекту и, необязательно, к одному источнику. Системный токен имеет только `system:read` и не привязан к проекту, источнику или сервисной учётной записи. Другие scopes, дубликаты и смешение системных и проектных scopes отклоняются. Bearer-токены не получают браузерных прав управления credentials. Управление токенами проекта требует серверной сессии, CSRF для изменений и `tokens:manage` (глобальный administrator или эффективный project operator); системными токенами управляет только administrator. Секрет `cl_` + 64 hex-символа появляется только в ответе выпуска/ротации; в PostgreSQL хранится SHA-256. Ни список, ни аудит, ни introspection не раскрывают секрет или digest.

## API (база `/api/v1`)

Для проекта `P`, токена `T`, источника `S`, сервисной записи `A`:

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/projects/P/tokens` | Список; `limit`, `cursor`, `status=all/active/expired/revoked`, `source_id`, `service_account_id` |
| POST | `/projects/P/tokens` | Выпуск; name, scopes, expires_in; дополнительно source_id, service_account_id, description, requests_per_minute |
| PATCH | `/projects/P/tokens/T` | revision и name/description/requests_per_minute |
| POST | `/projects/P/tokens/T/rotate` | revision, expires_in, overlap_seconds, необязательные scopes |
| DELETE | `/projects/P/tokens/T` | Немедленный отзыв; повтор идемпотентен |
| POST | `/projects/P/tokens/revoke-all` | `{ "confirm":true }`; опционально ровно один фильтр source_id или service_account_id |
| GET | `/projects/P/service-accounts` | Список сервисных учётных записей с пагинацией |
| POST | `/projects/P/service-accounts` | Создание `{ "name":"Backend" }` |
| POST | `/projects/P/service-accounts/A/disable` | `{ "confirm":true }`, необратимое отключение и атомарный отзыв всех связанных токенов |

Системные аналоги: GET/POST `/system/tokens`, PATCH/DELETE `/system/tokens/T`, POST `/system/tokens/T/rotate`, POST `/system/tokens/revoke-all` (без фильтра проекта/источника/сервиса). Запросы управления принимают JSON, действующую session cookie и `X-CSRF-Token`. Статус HTTP 409 при устаревшей revision, уже выполненной ротации, отключённом сервисе; 422 при неверных полях, расширении scopes и неверных пределах TTL/overlap; 404 при отсутствии ресурса в разрешённой области.

## Ротация без простоя

1. Выпустите замену через POST `/projects/P/tokens/T/rotate` с `{ "revision":"1", "expires_in":2592000, "overlap_seconds":3600, "scopes":["logs:write"] }`. Новый token UUID, secret, revision и metadata возвращаются с HTTP 201; поле `previous` содержит старые публичные метаданные с effective expires_at, revoked_at, rotated_to_id. Новые scopes должны быть подмножеством прежних; project_id, source_id, service_account_id наследуются. Старый scope не меняется.
2. Сохраните новый секрет в secret manager. Проверьте его через GET `/auth/token`, переключите SDK/collector на новый секрет и проверьте реальный ingest. HMAC подписывается новым секретом. SDK не требуют изменения wire protocol.
3. После переключения отзовите старый credential DELETE. Иначе он перестанет работать при наступлении меньшего из первоначального срока и времени ротации + overlap. Overlap 0–604800 секунд, строго меньше TTL нового токена. 0 означает отзыв старого в той же транзакции. Повторная ротация одного UUID запрещена, но можно ротировать его successor. При компрометации не используйте overlap: немедленно отзовите старый и все другие подозрительные токены.

**Важные гарантии и ограничения:** ротация атомарно создаёт successor, ограничивает старый credential и пишет audit log. Первая операция с устаревшей revision отклоняется. Секрет не восстанавливается из БД. При потере ответа после неизвестного COMMIT проверьте список и successor; если новый секрет утрачен, отзовите потерянный successor и выпустите новый credential явно. Не выполняйте слепую повторную ротацию.

UUID credential остаётся областью HMAC nonces и rate-limit. Начиная с 0.2.7 новые receipts и jobs используют общий namespace цепочки ротаций: активные `logs:write` credentials этой семьи могут повторять те же body/key и опрашивать новые jobs, но не получают право чтения логов. Legacy receipts/jobs сохраняют точного владельца, поскольку прежний body HMAC невозможно проверить секретом преемника. Новый credential получает 409 при попытке повторить такой ключ, пока прежняя запись существует. Завершите legacy retry до переключения, не создавайте новый ключ вслепую. См. [Idempotency & Delivery Guarantees](Idempotency-Delivery-Guarantees.md). Pending jobs, уже принятые до отзыва/истечения/отключения сервиса, продолжают обрабатываться по прежнему сохранённому scope. Новый запрос после завершённого отзыва получает 401; запросы, начатые до отзыва, могут завершиться. Транзакционный ingest блокирует строку токена и перепроверяет срок/статус после захвата блокировки, независимо от throttled last_used_at. Отзыв сериализуется с этой блокировкой; данные уже подтверждённой транзакции не удаляются.

## Сервисные учётные записи и отзыв

Service account — именованный владелец credentials в одном проекте, без пароля и браузерного логина. Уникальность имени внутри проекта; создатель фиксируется в created_by. UUID, project_id и связь выданного токена неизменяемы. Один сервис может иметь несколько токенов с разными source/scope. Отключение сервиса блокирует новый выпуск и ротацию его токенов и отзывает все связанные (включая expired) одной транзакцией. Отключение необратимо; для нового сервиса создайте новую запись. Удаление или блокировка создателя не отзывает сервисный credential автоматически; service account управляется проектом, а не личной сессией создателя.

Пакетный отзыв проекта действует на все его credentials. Фильтр source_id отзывает только токены, непосредственно привязанные к источнику, **не** токены всего проекта, которые также могут писать в этот источник. Чтобы остановить все новые события источника, используйте Source Management disable/archive; для компрометации project-wide токенов используйте отзыв проекта. Фильтр service_account_id отзывает credentials сервиса, не отключая сам сервис. Операция возвращает количество изменённых строк, сохраняет историю и транзакционный аудит. Не выполняется физическое удаление токенов.

## Лимиты и мониторинг

`requests_per_minute` — null или 1–1000000. Общий атомарный PostgreSQL minute bucket для всех bearer-вызовов, прошедших первичную аутентификацию: introspection, logs, ingest, job status, system report. Повторная авторизация внутри бизнес-транзакции не расходует квоту повторно. Отдельные глобальные IP и endpoint rate limits продолжают действовать; retry и отклонённые авторизованные запросы тоже расходуют request quota. Счётчик ограничен фиксированными минутами и относится к UUID токена, а не к сервису или проекту. В период overlap старый и новый UUID имеют независимые квоты, проектные/источниковые квоты событий сохраняются. `last_used_at` обновляется при первом использовании и затем не чаще одного раза в пять минут; это приблизительная телеметрия, не время точного последнего запроса. Списки показывают expiry, revoke, successor, owner, revision, quota; статистика активных токенов использует действующее состояние PostgreSQL. Аудит `token.created`, `token.updated`, `token.rotated`, `token.revoked`, `token.bulk_revoked`, `service_account.created`, `service_account.disabled` сохраняется атомарно с операциями, без секретов.

## Проверки

`php tests/tokens.php` — локальная валидация, контролируемые PDO-сценарии, SQL binding и выбор блокировок. `CAJEER_TEST_DATABASE=1 php tests/tokens_database.php` — реальная миграция 0011 в отдельной тестовой схеме PostgreSQL, HTTP-авторизация, expiry/rotation/HMAC, RBAC/CSRF, конкуренция с revoke, rate limit, job ownership, аудит и отключение сервиса. `tests/admin_browser_database.py` проверяет реальный UI с БД. CI выполняет PostgreSQL 17/18 × PHP 8.4/8.5, предыдущие integration suites, обновление 0.1.7 → 0.1.8 и production smoke. Локальный прогон без PostgreSQL/`pdo_pgsql` не является сертификацией положительных SQL-сценариев.
