# Source Management — 0.1.7

## Обновление

Остановите приём логов, queue worker, scheduler и webhook-worker; на 0.1.6 создайте `php bin/cajeer backup:create`, сохраните `.env`, ключи TOTP, TLS и внешние секреты отдельно. Наложите ZIP изменённых файлов на корень 0.1.6. Выполните:

```sh
php bin/cajeer version
php bin/cajeer migrate:plan
php bin/cajeer migrate
php bin/cajeer migrate:verify
php bin/cajeer db:doctor
php bin/cajeer doctor
```

Обязательна миграция `0010_source_management.sql`; 0001–0009 не меняются. При раздельных ролях используйте DB_MIGRATION_USERNAME/DB_MIGRATION_PASSWORD только в доверенном CLI. Миграция создаёт два индекса в транзакции; для большой таблицы логов предусмотрите окно обслуживания. После готовности `/ready` перезапустите сервисы. Новые переменные `.env` не требуются. Backup 0.1.6 восстанавливается в отдельную пустую БД с применением 0010, старые совместимые backup — с 0009 и 0010; история миграций должна точно соответствовать версии backup. Сессии, токены и recovery-коды после restore инвалидируются по прежней политике.

## Жизненный цикл

Оператор с `sources:manage` в проекте либо администратор создаёт и редактирует источники; viewer читает. Существующие источники получают статус active, revision `"1"`, тип application, пустое описание/metadata, наследуемые политики, нулевые счётчики. UUID, project_id и slug неизменяемы; тип — application/server/nginx/aapanel/custom. Тип служит классификацией, а не автоматической установкой коллектора. `disabled` и `archived` запрещают новые sync/async запросы. Архивация обратима: restore переводит в disabled, затем явный enable возобновляет приём. Архив не удаляет историю и токены, retention продолжает действовать. Pending jobs, принятые ранее, могут завершаться; подтверждённые idempotency replays возвращают старый результат. Никаких hard delete/переноса между проектами.

## API

Все пути ниже относительно `/api/v1/projects/{project}/sources`:

- `GET /` — список, `q`, `status=all|active|disabled|archived`, `limit` (1–100), `cursor` UUID. В фактическом URL нет завершающего `/`.
- `POST /` — создание с name, slug и необязательными параметрами из PATCH; возвращает `id`.
- `GET /{source}` — детали; `GET /{source}/stats` — статистика. Доступ через session или `logs:read` token нужного проекта/источника. Source-scoped token не может читать список всего проекта.
- `PATCH /{source}` — редактирование с обязательной строкой revision; не допускает изменение slug, id, project_id или статуса напрямую.
- `POST /{source}/disable|enable|archive|restore` — тело `{"revision":"1"}`. Ревизия повышается при каждом успешном изменении. Архив можно восстановить только через restore, enable архивного источника даёт 409.

Изменяющие операции требуют browser session, `X-CSRF-Token`, проектных прав и (production) HTTPS. Проектная роль может только ограничить глобальную. `source_revision_conflict` — загрузить актуальную запись, не повторять старый PATCH вслепую. Все изменения и audit выполняются в общей транзакции.

Пример PATCH:

```json
{"revision":"1","name":"Nginx frontend","source_type":"nginx","description":"Production access logs","metadata":{"environment":"production","owner":"ops"},"retention_days":7,"max_body_bytes":262144,"max_batch_events":50,"events_per_minute":500}
```

## Политики и статистика

- `retention_days`: null или 1–3650. PostgreSQL удаляет по `received_at`; эффективный срок — минимум из ненулевых сроков проекта и источника (null/null — без удаления). Source не может продлить project retention. Retention работает и для архивных/отключённых источников, блокировка общей политики сериализует её изменения с удалением.
- `max_body_bytes`: null или 1024–1048576. Ограничение на весь HTTP body для каждого участвующего источника, включая смешанные пакеты.
- `max_batch_events`: null или 1–100. Лимит количества событий именно этого источника в пакете; глобальный/project лимит остаётся на весь пакет.
- `events_per_minute`: null или 1–1000000. Фиксированные минутные окна, общие для всех токенов источника. Project и source квоты применяются одновременно. `429` и `Retry-After` при исчерпании; `413` при ограничениях размера; `409` при неактивном состоянии.
- Один пакет атомарен. Project admission и source locks в порядке UUID удерживаются до commit; изменение статуса ждёт окончания приёма. Прежние jobs исполняются без повторного admission, сохраняя гарантии 202. Новые запросы в архив проекта также блокируются. Повтор по существующему receipt/job не расходует квоты и не обновляет счётчики.
- `accepted_events_total` — принятые через API события с 0.1.7, включая durable async enqueue; это не число записанных логов. `last_seen_at` — время последнего подтверждённого нового admission; сохраняется после retention. `rejected_events_total` — число событий в аутентифицированных пакетах с разрешёнными источниками, отклонённых политиками проекта/источника (статус/размер/квота); весь отклонённый пакет учитывается по источникам. Admission savepoint откатывает квоты/accepted; внешний commit сохраняет только отказ и использованный HMAC nonce. Не учитываются неправильные credentials, неизвестные источники, некорректный JSON, глобальные ограничения, инфраструктурные ошибки и DLQ. Эти счётчики начинаются с нуля, не реконструируются из старых логов.
- `events`, `events_24h`, `events_60s`, `last_received_at` вычисляются из реально сохранённых строк (после retention могут уменьшиться или стать null); `active_tokens` — число активных токенов, привязанных непосредственно к источнику, без project-wide credentials. Статистика возвращается в одном SQL snapshot; счётчики bigint представлены строками.

## Admin Panel

`/admin/` → Проекты и источники → Открыть источник. Поиск и фильтр статуса, редактирование типа/метаданных/политик, действия жизненного цикла, статистика. Для подключения используйте slug и токен logs:write из раздела API-токены, существующие PHP/Python/TypeScript/Kotlin SDK или коллектор. Форма тестового лога проверяет project_id/source_id/scopes токена через `/auth/token`, подписывает запрос HMAC через Web Crypto и отправляет реальный `POST /ingest` без session cookie. Требуется HTTPS или localhost и действующий токен. Токен не хранится в localStorage/sessionStorage, поле очищается при отправке; успешный тест создаёт обычное событие. Viewer не видит изменяющих операций.

## Проверки

`php tests/sources.php` — локальные тесты валидации, защиты routes и ветвей admission с контролируемым PDO. `CAJEER_TEST_DATABASE=1 php tests/sources_database.php` — отдельная схема в тестовом PostgreSQL: миграция, RBAC, source-scoped tokens, revision, lifecycle, quota, mixed batch rollback, idempotency, jobs, retention, stats, audit и конкурентная блокировка. `tests/admin_browser.py` — UI с контролируемым API; `tests/admin_browser_database.py` — реальный Chromium/PHP/PostgreSQL и signed test event. CI использует PostgreSQL 17/18 и PHP 8.4/8.5, включает точное обновление с 0.1.6. Для подтверждения production-готовности необходимо выполнить DB suites и deployment smoke.
