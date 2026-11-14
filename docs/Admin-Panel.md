# Admin Panel — 0.0.6

Рабочая русскоязычная same-origin административная SPA. PHP 8.4+, PostgreSQL 17+ и `pdo_pgsql`. Используется API v1 из 0.0.3–0.0.5. Серверной авторизации и RBAC доверяет только backend; скрытие кнопок по роли — удобство интерфейса, а не граница безопасности.

Для обновления до 0.0.7 см. [SDK & Integrations](SDK-Integrations.md).

## Обновление 0.0.5 → 0.0.6

1. Сделайте резервные копии PostgreSQL и файлов, остановите веб-трафик на время замены.
2. Наложите changed-files ZIP на корень существующего проекта. Обязательно поставьте `admin/index.html` (непубличный шаблон) и `public/admin/assets/panel.js`, `panel.css`.
3. Проверьте `php bin/cajeer version` (0.0.6), `php bin/cajeer migrate:status`, `php bin/cajeer db:doctor`. Новых миграций нет; 0001–0005 и их checksums не меняются. При наличии старых pending migrations выполните `php bin/cajeer migrate`.
4. Обновите маршрутизацию `/admin/` в Nginx или Apache по примерам ниже. Если использовался PHP OPcache без автоматического revalidation, перезапустите/reload PHP-FPM.
5. Откройте `https://<ваш-домен>/admin/`, войдите с существующим администратором и проверьте доступ к проектам, приём и просмотр события.

## Запуск и production

Для локальной разработки (в `.env` задайте `APP_ENV=local`, `APP_URL=http://127.0.0.1:8080`):

```sh
php -S 127.0.0.1:8080 -t public public/router.php
# http://127.0.0.1:8080/admin/
```

Если проект ещё не установлен, выполните [Database & Installer](Database-Installer.md). Администратор создаётся CLI-установщиком, а не автоматически через браузер. `APP_URL` должен точно совпадать с внешним origin (схема, хост, порт); не используйте `localhost` в конфигурации при обращении к `127.0.0.1`. API prefix берётся из `API_PREFIX` и подставляется в HTML сервером. `/admin` зарезервирован для панели.

Production: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<ваш-домен>`, HTTPS передаётся PHP-FPM только доверенным веб-сервером. Панель не выдаёт страницу входа по HTTP. Защитите `.env`, сохраните DocumentRoot строго `public/`, не включайте directory listing. Cookie — HttpOnly, SameSite=Strict и Secure при HTTPS. Открывать CORS для панели не требуется.

- Nginx: используйте обновлённый `examples/nginx/cajeerlogs.conf`; `/admin` → `/admin/`, `/admin/` → `index.php`, `/admin/assets/` — статические файлы. Остальное API продолжает работать через runtime. См. [Nginx deployment](Nginx-deployment.md).
- Apache: обновите правила из `examples/apache/.htaccess` или перенесите их в VirtualHost; маршрут `admin/?` переписывается в `index.php`. См. [Apache deployment](Apache-optional.md).
- Docker Compose: прежний dev-контейнер PHP работает с панелью без дополнительного сервиса.

## Интерфейс и операции

| Раздел | Рабочие действия |
| --- | --- |
| Обзор | Выбрать проект, увидеть статистику последних 24 ч, почасовой график, распределение по уровням/источникам, последние события. |
| Логи | Полнотекстовый поиск, PSR-3 level, source slug/UUID, service, host, trace/request ID, tag, JSON context, период UTC; cursor pagination; деталь по UUID; CSV/JSON текущей страницы. |
| Проекты и источники | Создание проекта администратором, выбор проекта, добавление источника, список источников, добавление/удаление участника (UUID из пользователей). |
| API-токены | Project/source scopes `logs:read`/`logs:write`, срок 1–365 дней, выдача секрета один раз, отзыв; администратору также `system:read`. |
| Пользователи | Создание, изменение email/роли, установка нового пароля, блокировка; backend отзывает сессии изменённой учётной записи. |
| Система | Health/readiness, версия, PHP, среда, состояние PostgreSQL, диагностический отчёт. |
| Настройки | Профиль, светлая/тёмная тема, смена пароля, отзыв всех сессий. |

Administrator может управлять всеми проектами и пользователями. Operator — читать назначенные проекты, создавать их источники и управлять их токенами. Viewer — читать назначенные проекты и логи. Backend защищает последнего администратора, проверяет membership и CSRF для изменяющих запросов. Сессия проверяется при открытии/возврате на страницу, периодически в активной вкладке и на каждом защищённом API-запросе; HTTP 401 очищает отображаемые данные и показывает вход. HTTP 403/409/422/429/503 отображаются как ошибки, а не успешный результат.

### Первый сквозной сценарий

1. Войти как administrator → «Проекты и источники» → создать проект, затем источник со slug `app`.
2. «API-токены» → выпустить токен с `logs:write` (при необходимости также `logs:read`), сохранить секрет из диалога. Токен не сохраняется в localStorage/sessionStorage; закрытие диалога удаляет секрет из DOM. В случае потери секрета отозвать и выпустить новый.
3. Отправить событие внешним клиентом (не передавайте cookie браузерной сессии вместе с Bearer):

```sh
curl --fail-with-body -X POST https://logs.example.com/api/v1/ingest \
  -H "Authorization: Bearer ${CAJEER_TOKEN}" \
  -H 'Content-Type: application/json' \
  -d '{"timestamp":"2026-11-14T00:00:00Z","source":"app","level":"error","message":"Database connection failed","context":{"code":"DB_DOWN"}}'
```

4. Открыть «Логи», выбрать проект и поиск `database`. Нажать «Подробнее», проверить JSON context, скачать CSV или JSON. Dashboard показывает только последние 24 часа (для исторических событий используйте Explorer).

Экспорт постраничный (25/50/100), сервер защищает CSV от формул. Между страницами нет фиксированного snapshot; новые события могут менять выборку при параллельном ingest. Результаты и фильтры не записываются в браузерное persistent storage. Фильтры сбрасываются при смене проекта. Исторические unscoped логи недоступны по правилам 0.0.5. Статистика агрегируется PostgreSQL, не генерируется frontend.

## Frontend и сборка

В релизе используется нативный ES-module SPA, уже готовый к раздаче PHP/Nginx/Apache. Node.js и npm-зависимости не нужны для эксплуатации. `public/admin/assets/panel.js` и `panel.css` — исходники и одновременно поставляемые production assets; `admin/index.html` — серверный шаблон. `npm --prefix admin run build` выполняет проверку синтаксиса JS и ссылок на assets, а не создаёт новую сборку. Старые `.vue`/`nuxt.config.ts` из исходного каркаса сохранены для совместимости файлового обновления и не используются; npm scripts больше не запускают Nuxt. Не публикуйте весь каталог `admin/` как web root.

Используются DOM text nodes для логов и JSON, CSP без inline script/style и сторонних доменов, `Cache-Control: no-store` для HTML/API, version query у статических assets. Не храните в логах секреты; маскирование backend не заменяет DLP. Для UI нужны актуальные Chromium/Firefox/Safari с ES modules, fetch, AbortController, dialog, SVG и Blob URL. Clipboard API может быть недоступен без HTTPS/разрешения; остаётся ручное копирование. Неиспользуемые возможности (инциденты, расширения, очередь, TOTP, произвольное изменение системной конфигурации) не показаны как рабочие разделы; настройка инфраструктуры по-прежнему через `.env`/CLI.

## Проверки

```sh
npm --prefix admin run build
python3 tests/http_runtime.py
php bin/cajeer openapi:lint
python3 -m venv .browser-venv
.browser-venv/bin/pip install 'playwright==1.52.0'
.browser-venv/bin/python -m playwright install chromium
.browser-venv/bin/python tests/admin_browser.py
# Только на отдельной тестовой PostgreSQL БД с DB_* и pdo_pgsql:
CAJEER_TEST_DATABASE=1 .browser-venv/bin/python tests/admin_browser_database.py
```

`tests/admin_browser.py` проверяет настоящую HTTP-раздачу и UI с контролируемыми API-ответами; это не тест PostgreSQL. Для ограниченной браузерной среды `CAJEER_BROWSER_OFFLINE=1` запускает реальный Chromium DOM с HTML/CSS/JS, полученными по HTTP, но подменяет transport/origin и проверяет CSV Blob без настоящей загрузки. Этот режим не подтверждает фактическое применение cookie/CSP браузером. `tests/admin_browser_database.py` использует настоящую HTTP-сессию, миграции и временную случайную PostgreSQL-схему; после теста удаляет её. Запускать только против отдельной тестовой БД, не production. CI запускает полные сетевые тесты с PostgreSQL 17 и Chromium. В локальном окружении разработчика PostgreSQL/`pdo_pgsql` отсутствовали, навигация Chromium заблокирована политикой среды; успешно выполнены регрессии, HTTP-раздача и offline UI fixture, сетевой E2E требует отдельного прогона.
