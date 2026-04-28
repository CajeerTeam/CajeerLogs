<?php
declare(strict_types=1);

namespace CajeerLogs\Http;

use CajeerLogs\AaPanelLogImporter;
use CajeerLogs\Auth;
use CajeerLogs\Database;
use CajeerLogs\Env;
use CajeerLogs\Logger;
use CajeerLogs\Repository;
use CajeerLogs\Response;
use CajeerLogs\RuntimeDiagnostics;
use CajeerLogs\Security;
use CajeerLogs\UpdateManager;
use CajeerLogs\View;
use Throwable;

final class Router
{
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        if (!str_starts_with($path, '/api/') && $path !== '/health' && $path !== '/health/cron' && !Security::uiIpAllowed()) {
            Response::html(View::layout('Доступ запрещён', '<h1>Доступ запрещён</h1><section class="panel"><p>IP-адрес не входит в UI_IP_ALLOWLIST.</p></section>'), 403);
            return;
        }

        try {
            if ($path === '/login' && $method === 'GET') {
                $this->login();
                return;
            }
            if ($path === '/login' && $method === 'POST') {
                $this->loginPost();
                return;
            }
            if ($path === '/logout' && $method === 'POST') {
                Auth::requireLogin();
                $this->logout();
                return;
            }
            if ($path === '/health' && $method === 'GET') {
                $this->health();
                return;
            }
            if ($path === '/health/cron' && $method === 'GET') {
                $this->healthCron();
                return;
            }
            if ($path === '/api/v1/ingest' && $method === 'POST') {
                $this->ingest();
                return;
            }
            if ($path === '/' && $method === 'GET') {
                Auth::requirePermission('logs.view');
                $this->dashboard();
                return;
            }
            if ($path === '/logs/export' && $method === 'GET') {
                Auth::requirePermission('logs.export');
                $this->exportLogs();
                return;
            }
            if (preg_match('#^/logs/(\d+)$#', $path, $m) && $method === 'GET') {
                Auth::requirePermission('logs.view');
                $this->logDetail((int)$m[1]);
                return;
            }
            if ($path === '/logs' && $method === 'GET') {
                Auth::requirePermission('logs.view');
                $this->logs();
                return;
            }
            if ($path === '/bots' && $method === 'GET') {
                Auth::requirePermission('bots.view');
                $this->bots();
                return;
            }
            if ($path === '/bots' && $method === 'POST') {
                Auth::requirePermission('bots.manage');
                $this->createBotTokenFromForm();
                return;
            }
            if ($path === '/bots/action' && $method === 'POST') {
                Auth::requirePermission('bots.manage');
                $this->botTokenAction();
                return;
            }
            if ($path === '/bots/health' && $method === 'GET') {
                Auth::requirePermission('bots.view');
                $this->botsHealth();
                return;
            }
            if ($path === '/sites' && $method === 'GET') {
                Auth::requirePermission('sites.view');
                $this->sites();
                return;
            }
            if ($path === '/sites/import' && $method === 'POST') {
                Auth::requirePermission('sites.manage');
                $this->importAaPanelSiteLogs();
                return;
            }
            if ($path === '/incidents' && $method === 'GET') {
                Auth::requirePermission('incidents.view');
                $this->incidents();
                return;
            }
            if (preg_match('#^/incidents/(\d+)$#', $path, $m) && $method === 'GET') {
                Auth::requirePermission('incidents.view');
                $this->incidentDetail((int)$m[1]);
                return;
            }
            if ($path === '/incidents/action' && $method === 'POST') {
                Auth::requirePermission('incidents.manage');
                $this->incidentAction();
                return;
            }
            if ($path === '/alerts' && $method === 'GET') {
                Auth::requirePermission('alerts.view');
                $this->alerts();
                return;
            }
            if ($path === '/alerts' && $method === 'POST') {
                Auth::requirePermission('alerts.manage');
                $this->createAlertRule();
                return;
            }
            if ($path === '/alerts/action' && $method === 'POST') {
                Auth::requirePermission('alerts.manage');
                $this->alertAction();
                return;
            }
            if ($path === '/alerts/deliveries/action' && $method === 'POST') {
                Auth::requirePermission('alerts.manage');
                $this->alertDeliveryAction();
                return;
            }
            if ($path === '/audit' && $method === 'GET') {
                Auth::requireLogin();
                Auth::requirePermission('audit.view');
                $this->audit();
                return;
            }
            if ($path === '/users' && $method === 'GET') {
                Auth::requirePermission('users.manage');
                $this->users();
                return;
            }
            if ($path === '/users' && $method === 'POST') {
                Auth::requirePermission('users.manage');
                $this->saveUserFromForm();
                return;
            }
            if ($path === '/users/action' && $method === 'POST') {
                Auth::requirePermission('users.manage');
                $this->userAction();
                return;
            }
            if ($path === '/system' && $method === 'GET') {
                Auth::requirePermission('system.view');
                $this->system();
                return;
            }
            if ($path === '/system/pwa' && $method === 'GET') {
                Auth::requirePermission('system.view');
                $this->pwaDiagnostics();
                return;
            }
            if ($path === '/system/runtime' && $method === 'GET') {
                Auth::requirePermission('system.view');
                $this->runtimeDiagnostics();
                return;
            }
            if ($path === '/system/jobs' && $method === 'GET') {
                Auth::requirePermission('system.view');
                $this->jobs();
                return;
            }
            if ($path === '/system/jobs/action' && $method === 'POST') {
                Auth::requirePermission('system.view');
                $this->jobAction();
                return;
            }
            if ($path === '/system/update' && $method === 'GET') {
                Auth::requirePermission('update.manage');
                $this->updateCenter();
                return;
            }
            if ($path === '/system/update/action' && $method === 'POST') {
                Auth::requirePermission('update.manage');
                $this->updateCenterAction();
                return;
            }
            if ($path === '/cron' && $method === 'GET') {
                Auth::requirePermission('cron.view');
                $this->cron();
                return;
            }
            if ($path === '/errors' && $method === 'GET') {
                Auth::requirePermission('logs.view');
                $this->errors();
                return;
            }
            if ($path === '/saved-views' && $method === 'GET') {
                Auth::requirePermission('logs.view');
                $this->savedViews();
                return;
            }
            if ($path === '/saved-views' && $method === 'POST') {
                Auth::requirePermission('logs.view');
                $this->createSavedView();
                return;
            }
            if ($path === '/saved-views/action' && $method === 'POST') {
                Auth::requirePermission('logs.view');
                $this->savedViewAction();
                return;
            }
            if (preg_match('#^/sites/([^/]+)$#', $path, $m) && $method === 'GET') {
                Auth::requirePermission('sites.view');
                $this->siteDetail(rawurldecode($m[1]));
                return;
            }

            Response::json(['ok' => false, 'error' => 'not_found', 'message' => 'Страница не найдена.'], 404);
        } catch (Throwable $e) {
            Logger::error('HTTP dispatch failed', [
                'path' => $path,
                'method' => $method,
                'exception' => $e,
            ]);

            if ($this->wantsJson($path)) {
                $debug = Env::bool('APP_DEBUG', false);
                Response::json([
                    'ok' => false,
                    'error' => 'application_error',
                    'message' => $debug ? $e->getMessage() : 'Ошибка приложения. Проверьте storage/logs/app.log.',
                    'diagnostics' => $debug ? Database::diagnostics() : null,
                ], 500);
                return;
            }

            $this->renderSetupError($e);
        }
    }

    private function repo(): Repository
    {
        return new Repository(Database::pdo());
    }


    private function login(array $errors = []): void
    {
        if (Auth::user()) {
            Response::redirect('/');
            return;
        }
        $csrf = Security::csrfToken('login');
        $next = Security::e(Security::safeInternalPath((string)($_GET['next'] ?? '/'), '/'));
        $errorHtml = '';
        if ($errors) {
            $items = '';
            foreach ($errors as $error) {
                $items .= '<li>' . Security::e($error) . '</li>';
            }
            $errorHtml = '<div class="notice danger"><ul>' . $items . '</ul></div>';
        }
        $body = <<<HTML
<h1>Вход</h1>
{$errorHtml}
<section class="panel auth-panel">
    <form class="form-grid" method="post" action="/login">
        <input type="hidden" name="_csrf" value="{$csrf}">
        <input type="hidden" name="next" value="{$next}">
        <label>Пользователь<input name="username" autocomplete="username" required></label>
        <label>Пароль<input name="password" type="password" autocomplete="current-password" required></label>
        <div class="form-actions full"><button type="submit">Войти</button></div>
    </form>
</section>
HTML;
        Response::html(View::layout('Вход', $body));
    }

    private function loginPost(): void
    {
        if (!Security::verifyCsrfToken('login', (string)($_POST['_csrf'] ?? ''))) {
            $this->login(['Срок действия формы истёк. Обнови страницу и повтори вход.']);
            return;
        }
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (!Auth::attempt(Database::pdo(), $username, $password)) {
            $this->repo()->audit('auth.login_failed', 'user', $username !== '' ? $username : null, 'Неудачная попытка входа');
            $this->login(['Неверный пользователь или пароль.']);
            return;
        }
        $this->repo()->audit('auth.login', 'user', $username, 'Вход в интерфейс');
        $next = Security::safeInternalPath((string)($_POST['next'] ?? '/'), '/');
        Response::redirect($next);
    }

    private function logout(): void
    {
        if (!Security::verifyCsrfToken('logout', (string)($_POST['_csrf'] ?? ''))) {
            Response::redirect('/');
            return;
        }
        $this->repo()->audit('auth.logout', 'user', Auth::username(), 'Выход из интерфейса');
        Auth::logout();
        Response::redirect('/login');
    }

    private function health(): void
    {
        $payload = [
            'ok' => true,
            'service' => 'cajeer-logs-php',
            'time' => gmdate('c'),
            'message' => 'Сервис доступен.',
            'diagnostics' => Database::diagnostics(),
        ];

        try {
            Database::pdo()->query('SELECT 1');
            $payload['driver'] = Database::driver();
            $payload['database'] = 'available';
            $payload['database_message'] = 'База данных доступна.';
        } catch (Throwable $e) {
            Logger::error('Health check database failure', ['exception' => $e]);
            $payload['ok'] = false;
            $payload['database'] = 'unavailable';
            $payload['error'] = 'db_unavailable';
            $payload['message'] = 'База данных недоступна.';
            $payload['details'] = $e->getMessage();
        }

        Response::json($payload, 200);
    }

    private function ingest(): void
    {
        $maxBytes = Env::int('INGEST_MAX_BODY_BYTES', 2097152);
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > $maxBytes) {
            Response::json(['ok' => false, 'error' => 'payload_too_large', 'message' => 'Тело запроса слишком большое.'], 413);
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $token = Security::bearerOrHeaderToken();
        if (!$token) {
            Response::json(['ok' => false, 'error' => 'missing_token', 'message' => 'Не передан токен бота.'], 401);
            return;
        }

        $repo = $this->repo();
        $botToken = $repo->findBotByToken($token);
        if (!$botToken) {
            Response::json(['ok' => false, 'error' => 'invalid_token', 'message' => 'Токен бота недействителен или отключён.'], 403);
            return;
        }

        $signatureRequired = Env::bool('INGEST_REQUIRE_SIGNATURE', true) || ((int)($botToken['require_signature'] ?? 0) === 1);
        $signatureOk = false;
        if ($signatureRequired || isset($_SERVER['HTTP_X_LOG_SIGNATURE'])) {
            $timestamp = (string)($_SERVER['HTTP_X_LOG_TIMESTAMP'] ?? '');
            $nonce = (string)($_SERVER['HTTP_X_LOG_NONCE'] ?? '');
            $signature = (string)($_SERVER['HTTP_X_LOG_SIGNATURE'] ?? '');
            [$ok, $reason] = Security::verifyRequestSignature($token, $raw, $timestamp, $nonce, $signature);
            if (!$ok) {
                Response::json(['ok' => false, 'error' => 'invalid_signature', 'message' => $reason], 401);
                return;
            }
            if (!$repo->rememberNonce($botToken, $nonce, $timestamp)) {
                Response::json(['ok' => false, 'error' => 'replayed_nonce', 'message' => 'Nonce уже использован или не может быть сохранён.'], 409);
                return;
            }
            $signatureOk = true;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            Response::json(['ok' => false, 'error' => 'invalid_json', 'message' => 'Некорректный JSON.'], 400);
            return;
        }

        $events = $payload['logs'] ?? $payload['events'] ?? null;
        if ($events === null && isset($payload['message'])) {
            $events = [$payload];
        }
        if (!is_array($events)) {
            Response::json(['ok' => false, 'error' => 'invalid_logs_array', 'message' => 'Ожидается массив logs/events.'], 400);
            return;
        }
        $globalMaxBatch = Env::int('INGEST_MAX_BATCH_SIZE', 100);
        $tokenMaxBatch = max(1, (int)($botToken['max_batch_size'] ?? $globalMaxBatch));
        $maxBatch = min($globalMaxBatch, $tokenMaxBatch);
        if (count($events) < 1 || count($events) > $maxBatch) {
            Response::json(['ok' => false, 'error' => 'invalid_batch_size', 'message' => 'Некорректный размер пачки логов.', 'max' => $maxBatch], 400);
            return;
        }
        foreach ($events as $event) {
            if (!is_array($event)) {
                Response::json(['ok' => false, 'error' => 'invalid_event', 'message' => 'Каждое событие должно быть объектом.'], 400);
                return;
            }
        }
        [$policyOk, $policyReason] = $repo->validateEventsForBot($botToken, $events);
        if (!$policyOk) {
            Response::json(['ok' => false, 'error' => 'policy_violation', 'message' => $policyReason], 403);
            return;
        }
        $rateViolation = $repo->ingestRateLimitViolation($botToken, count($events), strlen($raw));
        if ($rateViolation !== null) {
            Response::json(['ok' => false, 'error' => $rateViolation['error'], 'message' => $rateViolation['message']], 429);
            return;
        }

        $meta = [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'signed' => $signatureOk,
            'body_bytes' => strlen($raw),
        ];
        $inserted = $repo->insertBatch($botToken, $events, $meta);
        Response::json(['ok' => true, 'inserted' => $inserted, 'message' => 'Журналы приняты.']);
    }

    private function dashboard(): void
    {
        $repo = $this->repo();
        $stats = $repo->stats();
        $levels = $repo->levels();
        $recent = $repo->recentLogs([], 20);
        $cards = '';
        foreach ([
            'Всего записей' => $stats['total'],
            'Ошибки' => $stats['errors'],
            'Активные боты' => $stats['bots'],
            'Открытые инциденты' => $stats['incidents'] ?? 0,
            'Последний лог' => $stats['last'] ?? '—',
        ] as $label => $value) {
            $cards .= '<div class="card"><div class="label">' . Security::e($label) . '</div><div class="value">' . Security::e((string)$value) . '</div></div>';
        }
        $levelRows = '';
        foreach ($levels as $row) {
            $levelRows .= '<tr><td>' . Security::e($this->levelLabel((string)$row['level'])) . '</td><td>' . Security::e((string)$row['cnt']) . '</td></tr>';
        }
        $logRows = $this->renderLogRows($recent);
        $body = <<<HTML
<h1>Панель</h1>
<section class="grid cards">{$cards}</section>
<section class="panel"><h2>Уровни логов</h2><table><thead><tr><th>Уровень</th><th>Количество</th></tr></thead><tbody>{$levelRows}</tbody></table></section>
<section class="panel"><h2>Последние записи</h2><table><thead><tr><th>Время</th><th>Уровень</th><th>Проект</th><th>Бот</th><th>Сообщение</th><th></th></tr></thead><tbody>{$logRows}</tbody></table></section>
HTML;
        Response::html(View::layout('Панель', $body));
    }

    private function logs(): void
    {
        $filters = $this->logFiltersFromQuery();
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 100;
        $offset = ($page - 1) * $limit;
        $repo = $this->repo();
        $total = $repo->countLogs($filters);
        $logs = $repo->recentLogs($filters, $limit, $offset);
        $rows = $this->renderLogRows($logs, true);
        $options = $repo->filterOptions();
        $inputs = $this->renderLogFilters($filters, $options);
        $prev = $page > 1 ? '<a class="button" href="?' . Security::e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) . '">← Назад</a>' : '';
        $next = ($offset + $limit) < $total ? '<a class="button" href="?' . Security::e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) . '">Вперёд →</a>' : '';
        $autoRefresh = (int)($_GET['auto_refresh'] ?? 0);
        $autoAttr = $autoRefresh > 0 ? ' data-auto-refresh="' . max(5, min(120, $autoRefresh)) . '"' : '';
        $exportBase = http_build_query(array_diff_key($_GET, ['format' => true, 'page' => true]));
        $exportPrefix = '/logs/export' . ($exportBase ? '?' . $exportBase . '&' : '?');
        $body = <<<HTML
<h1>Логи</h1>
<div{$autoAttr}></div>
<div class="quick-actions">
    <a class="button ghost" href="/logs?range=15m">15 минут</a>
    <a class="button ghost" href="/logs?range=1h">1 час</a>
    <a class="button ghost" href="/logs?range=24h">24 часа</a>
    <a class="button ghost" href="/logs?range=7d">7 дней</a>
    <a class="button ghost" href="/logs?level=ERROR&range=24h">Ошибки за 24 часа</a>
    <a class="button ghost" href="/logs?level=SECURITY&range=24h">Безопасность</a>
    <a class="button ghost" href="/logs?project=Web+Sites&range=24h">Сайты</a>
    <a class="button ghost" href="/logs?auto_refresh=5">Автообновление 5 сек.</a>
</div>
<form class="filters" method="get">{$inputs}<button type="submit">Фильтровать</button><a class="button ghost" href="/logs">Сбросить</a></form>
<div class="toolbar"><span class="muted">Найдено записей: {$total}. Страница: {$page}.</span><span class="toolbar-actions"><a class="button ghost" href="{$exportPrefix}format=csv">CSV</a><a class="button ghost" href="{$exportPrefix}format=json">JSON</a><a class="button ghost" href="{$exportPrefix}format=ndjson">NDJSON</a></span></div>
<section class="panel wide logs-panel"><table class="logs-table"><thead><tr><th>Время</th><th>Уровень</th><th>Проект</th><th>Бот</th><th>Окружение</th><th>Источник</th><th>Сообщение</th><th>ID трассировки</th><th></th></tr></thead><tbody>{$rows}</tbody></table></section>
<div class="pager">{$prev}{$next}</div>
HTML;
        Response::html(View::layout('Логи', $body));
    }

    private function bots(?array $created = null, array $errors = [], array $form = [], ?string $message = null): void
    {
        $repo = $this->repo();
        $bots = $repo->botTokens();
        $rows = '';
        $actionCsrf = Security::csrfToken('bot_action');
        foreach ($bots as $b) {
            $id = (int)$b['id'];
            $active = ((int)$b['is_active']) === 1;
            $activeText = $active ? 'Активен' : 'Отключён';
            $activeClass = $active ? 'status-ok' : 'status-muted';
            $actions = $this->renderBotActions($id, $active, $actionCsrf);
            $rows .= '<tr><td>' . Security::e((string)$id) . '</td><td>' . Security::e($b['project']) . '</td><td>' . Security::e($b['bot']) . '</td><td>' . Security::e($b['environment']) . '</td><td><span class="status ' . $activeClass . '">' . Security::e($activeText) . '</span></td><td>' . Security::e((string)($b['last_used_at'] ?? '—')) . '</td><td>' . Security::e((string)($b['total_logs'] ?? 0)) . ' / ' . Security::e((string)($b['logs_24h'] ?? 0)) . '</td><td>' . Security::e((string)($b['errors_24h'] ?? 0)) . '</td><td>' . Security::e((string)($b['rate_limit_per_minute'] ?? 0)) . '</td><td>' . Security::e((string)($b['events_limit_per_minute'] ?? 0)) . '</td><td>' . Security::e((string)($b['bytes_limit_per_minute'] ?? 0)) . '</td><td>' . Security::e((string)($b['max_batch_size'] ?? 0)) . '</td><td>' . Security::e(((int)($b['require_signature'] ?? 0) === 1) ? 'Да' : 'Нет') . '</td><td>' . Security::e((string)($b['allowed_levels'] ?? 'Все')) . '</td><td>' . Security::e((string)($b['description'] ?? '')) . '</td><td>' . $actions . '</td></tr>';
        }

        $project = Security::e((string)($form['project'] ?? 'ExampleProject'));
        $bot = Security::e((string)($form['bot'] ?? 'ExampleBot'));
        $environment = Security::e((string)($form['environment'] ?? 'production'));
        $description = Security::e((string)($form['description'] ?? ''));
        $rateLimit = Security::e((string)($form['rate_limit_per_minute'] ?? '120'));
        $maxBatch = Security::e((string)($form['max_batch_size'] ?? '100'));
        $eventsLimit = Security::e((string)($form['events_limit_per_minute'] ?? Env::int('INGEST_MAX_EVENTS_PER_MINUTE', 3000)));
        $bytesLimit = Security::e((string)($form['bytes_limit_per_minute'] ?? Env::int('INGEST_MAX_BYTES_PER_MINUTE', 10485760)));
        $allowedLevels = Security::e((string)($form['allowed_levels'] ?? ''));
        $requireSignature = ((array_key_exists('require_signature', $form) && $form['require_signature'] === '1') || (!array_key_exists('require_signature', $form) && Env::bool('INGEST_REQUIRE_SIGNATURE', true))) ? ' checked' : '';
        $csrf = Security::csrfToken('create_bot_token');
        $docsUrl = Security::e(Env::get('DOCS_URL', 'https://github.com/CajeerTeam/CajeerLogs/wiki') ?: 'https://github.com/CajeerTeam/CajeerLogs/wiki');

        $notice = '';
        if ($errors) {
            $items = '';
            foreach ($errors as $error) {
                $items .= '<li>' . Security::e($error) . '</li>';
            }
            $notice = '<div class="notice danger"><strong>Операция не выполнена.</strong><ul>' . $items . '</ul></div>';
        } elseif ($message) {
            $notice = '<div class="notice success"><p>' . Security::e($message) . '</p></div>';
        }

        if ($created) {
            $envBlock = $this->renderCreatedTokenEnv($created);
            $python = $this->renderCreatedTokenPythonSnippet($created);
            $curl = $this->renderCreatedTokenCurl($created);
            $notice = <<<HTML
<div class="notice success">
    <h2>Токен создан</h2>
    <p><strong>Скопируй исходный токен сейчас.</strong> Повторно он не отображается и в базе хранится только хэш.</p>
    <h3>Переменные окружения для запуска</h3>
    <pre>{$envBlock}</pre>
    <h3>Фрагмент для Python main.py</h3>
    <pre>{$python}</pre>
    <h3>Проверочная команда</h3>
    <pre>{$curl}</pre>
    <p>После успешной отправки открой <a href="/bots/health">здоровье ботов</a> и <a href="/logs">журналы</a>, чтобы убедиться, что событие принято.</p>
</div>
HTML;
        }

        $body = <<<HTML
<h1>Боты</h1>
{$notice}
<section class="panel">
    <h2>Мастер подключения</h2>
    <ol>
        <li>Создай токен под конкретный проект, бота и окружение.</li>
        <li>Скопируй блок переменных окружения в окружение запуска сервиса.</li>
        <li>Положи <code>clients/bot.py</code> рядом с <code>main.py</code> как <code>remote_log_handler.py</code> или подключи его как пакет.</li>
        <li>Добавь фрагмент инициализации логирования в <code>main.py</code>.</li>
        <li>Отправь проверочное событие через curl и проверь страницу «Здоровье ботов».</li>
    </ol>
    <p class="muted">Для Python-приложения достаточно передать переменные <code>REMOTE_LOGS_*</code> и подключить клиент в точке входа. Подробная инструкция: <a href="{$docsUrl}" target="_blank" rel="noopener noreferrer">документация Cajeer Logs</a>.</p>
</section>
<section class="panel">
    <h2>Добавить бота</h2>
    <p class="muted">Форма создаёт отдельный токен приёма журналов для конкретного бота. Исходный токен показывается только один раз.</p>
    <form class="form-grid" method="post" action="/bots">
        <input type="hidden" name="_csrf" value="{$csrf}">
        <label>Проект<input name="project" value="{$project}" required maxlength="120"></label>
        <label>Бот<input name="bot" value="{$bot}" required maxlength="120"></label>
        <label>Окружение<input name="environment" value="{$environment}" required maxlength="60"></label>
        <label>Лимит запросов/мин<input name="rate_limit_per_minute" type="number" min="0" max="10000" value="{$rateLimit}"></label>
        <label>Макс. размер пачки<input name="max_batch_size" type="number" min="1" max="500" value="{$maxBatch}"></label>
        <label>Лимит событий/мин<input name="events_limit_per_minute" type="number" min="0" max="1000000" value="{$eventsLimit}"></label>
        <label>Лимит байт/мин<input name="bytes_limit_per_minute" type="number" min="0" max="104857600" value="{$bytesLimit}"></label>
        <label>Разрешённые уровни<input name="allowed_levels" value="{$allowedLevels}" placeholder="INFO,WARNING,ERROR"></label>
        <label class="check-row"><input type="checkbox" name="require_signature" value="1"{$requireSignature}> Требовать HMAC-подпись</label>
        <label class="full">Описание<textarea name="description" rows="3" maxlength="500">{$description}</textarea></label>
        <div class="form-actions full"><button type="submit">Создать токен</button></div>
    </form>
</section>
<section class="panel wide"><h2>Существующие токены</h2><table><thead><tr><th>ID</th><th>Проект</th><th>Бот</th><th>Окружение</th><th>Статус</th><th>Последнее использование</th><th>Всего / 24ч</th><th>Ошибки 24ч</th><th>Запросы/мин</th><th>События/мин</th><th>Байты/мин</th><th>Пачка</th><th>HMAC</th><th>Уровни</th><th>Описание</th><th>Действия</th></tr></thead><tbody>{$rows}</tbody></table></section>
HTML;
        Response::html(View::layout('Боты', $body));
    }

    private function createBotTokenFromForm(): void
    {
        $form = [
            'project' => trim((string)($_POST['project'] ?? '')),
            'bot' => trim((string)($_POST['bot'] ?? '')),
            'environment' => trim((string)($_POST['environment'] ?? 'production')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'rate_limit_per_minute' => trim((string)($_POST['rate_limit_per_minute'] ?? '120')),
            'max_batch_size' => trim((string)($_POST['max_batch_size'] ?? '100')),
            'events_limit_per_minute' => trim((string)($_POST['events_limit_per_minute'] ?? (string)Env::int('INGEST_MAX_EVENTS_PER_MINUTE', 3000))),
            'bytes_limit_per_minute' => trim((string)($_POST['bytes_limit_per_minute'] ?? (string)Env::int('INGEST_MAX_BYTES_PER_MINUTE', 10485760))),
            'allowed_levels' => trim((string)($_POST['allowed_levels'] ?? '')),
            'require_signature' => isset($_POST['require_signature']) ? '1' : '',
        ];
        $errors = $this->validateBotForm($form);
        if (!Security::verifyCsrfToken('create_bot_token', (string)($_POST['_csrf'] ?? ''))) {
            array_unshift($errors, 'Срок действия формы истёк. Обнови страницу и повтори создание токена.');
        }
        if ($errors) {
            $this->bots(null, $errors, $form);
            return;
        }

        $repo = $this->repo();
        $created = $repo->createBotToken(
            $form['bot'],
            $form['project'],
            $form['environment'],
            $form['description'] !== '' ? $form['description'] : null,
            [
                'rate_limit_per_minute' => (int)$form['rate_limit_per_minute'],
                'max_batch_size' => (int)$form['max_batch_size'],
                'events_limit_per_minute' => (int)$form['events_limit_per_minute'],
                'bytes_limit_per_minute' => (int)$form['bytes_limit_per_minute'],
                'allowed_levels' => $form['allowed_levels'] !== '' ? $form['allowed_levels'] : null,
                'require_signature' => $form['require_signature'] === '1',
            ]
        );
        $created['project'] = $form['project'];
        $created['bot'] = $form['bot'];
        $created['environment'] = $form['environment'];
        $created['require_signature'] = $form['require_signature'] === '1';
        $repo->audit('bot_token.created', 'bot_token', (string)$created['id'], 'Создан токен бота', ['project' => $form['project'], 'bot' => $form['bot'], 'environment' => $form['environment']]);

        $this->bots($created);
    }

    private function botTokenAction(): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        $errors = [];
        if (!Security::verifyCsrfToken('bot_action', (string)($_POST['_csrf'] ?? ''))) {
            $errors[] = 'Срок действия формы истёк. Обнови страницу и повтори действие.';
        }
        if ($id < 1) {
            $errors[] = 'Некорректный ID токена.';
        }
        if (!in_array($action, ['enable', 'disable', 'rotate', 'delete'], true)) {
            $errors[] = 'Некорректное действие.';
        }
        if ($errors) {
            $this->bots(null, $errors);
            return;
        }

        $repo = $this->repo();
        if ($action === 'rotate') {
            $rotated = $repo->rotateBotToken($id);
            if (!$rotated) {
                $this->bots(null, ['Токен не найден.']);
                return;
            }
            $rotated['require_signature'] = ((int)($rotated['require_signature'] ?? 0) === 1);
            $repo->audit('bot_token.rotated', 'bot_token', (string)$id, 'Перевыпущен токен бота');
            $this->bots($rotated);
            return;
        }

        $ok = match ($action) {
            'enable' => $repo->setBotTokenActive($id, true),
            'disable' => $repo->setBotTokenActive($id, false),
            'delete' => $repo->softDeleteBotToken($id),
            default => false,
        };
        if (!$ok) {
            $this->bots(null, ['Токен не найден или действие не применено.']);
            return;
        }
        $messages = [
            'enable' => 'Токен активирован.',
            'disable' => 'Токен отключён.',
            'delete' => 'Токен удалён.',
        ];
        $repo->audit('bot_token.' . $action, 'bot_token', (string)$id, $messages[$action] ?? 'Действие выполнено');
        $this->bots(null, [], [], $messages[$action] ?? 'Действие выполнено.');
    }

    private function logDetail(int $id): void
    {
        $repo = $this->repo();
        $log = $repo->findLog($id);
        if (!$log) {
            Response::html(View::layout('Лог не найден', '<h1>Лог не найден</h1><section class="panel"><p>Запись отсутствует или была удалена retention-задачей.</p><a class="button" href="/logs">Вернуться к логам</a></section>'), 404);
            return;
        }
        $levelClass = 'level level-' . strtolower((string)$log['level']);
        $fields = [
            'ID' => $log['id'],
            'ID пачки' => $log['batch_id'] ?? '—',
            'ID токена бота' => $log['bot_token_id'] ?? '—',
            'Создано' => $log['created_at'],
            'Получено' => $log['received_at'],
            'Уровень' => $this->levelLabel((string)$log['level']),
            'Проект' => $log['project'],
            'Бот' => $log['bot'],
            'Окружение' => $log['environment'],
            'Версия' => $log['version'] ?? '—',
            'Хост' => $log['host'] ?? '—',
            'Логгер' => $log['logger'] ?? '—',
            'ID трассировки' => $log['trace_id'] ?? '—',
            'Отпечаток' => $log['fingerprint'] ?? '—',
            'Хэш пользователя' => $log['user_id_хэш'] ?? '—',
            'Хэш чата' => $log['chat_id_хэш'] ?? '—',
            'Хэш сервера' => $log['guild_id_хэш'] ?? '—',
        ];
        $fieldRows = '';
        foreach ($fields as $k => $v) {
            $fieldRows .= '<tr><th>' . Security::e((string)$k) . '</th><td>' . Security::e((string)$v) . '</td></tr>';
        }
        $context = $this->prettyJson((string)($log['context'] ?? ''));
        $batchMeta = $this->prettyJson((string)($log['batch_meta'] ?? ''));
        $traceLink = $log['trace_id'] ? '<a class="button ghost" href="/logs?trace_id=' . Security::e((string)$log['trace_id']) . '">Связанные записи</a>' : '';
        $body = '<h1>Запись #' . Security::e((string)$id) . '</h1>'
            . '<div class="quick-actions"><a class="button ghost" href="/logs">← К списку</a>' . $traceLink . '</div>'
            . '<section class="panel"><h2><span class="' . $levelClass . '">' . Security::e($this->levelLabel((string)$log['level'])) . '</span></h2><pre>' . Security::e((string)$log['message']) . '</pre></section>'
            . '<section class="panel"><h2>Поля</h2><table class="detail-table"><tbody>' . $fieldRows . '</tbody></table></section>'
            . '<section class="panel"><h2>Исключение</h2><pre>' . Security::e((string)($log['exception'] ?? '—')) . '</pre></section>'
            . '<section class="panel"><h2>Контекст JSON</h2><pre>' . Security::e($context) . '</pre></section>'
            . '<section class="panel"><h2>Метаданные пачки</h2><pre>' . Security::e($batchMeta) . '</pre></section>';
        Response::html(View::layout('Запись #' . $id, $body));
    }



    private function sites(?array $summary = null, array $errors = []): void
    {
        $repo = $this->repo();
        $importer = new AaPanelLogImporter($repo);
        $sources = $importer->listSources();
        $stats = $repo->aaPanelSiteStats();
        $csrf = Security::csrfToken('aapanel_import');

        $notice = '';
        if ($summary) {
            $errText = '';
            if (!empty($summary['errors'])) {
                $items = '';
                foreach ($summary['errors'] as $error) {
                    $items .= '<li>' . Security::e((string)$error) . '</li>';
                }
                $errText = '<ul>' . $items . '</ul>';
            }
            $notice = '<div class="notice success"><h3>Импорт завершён</h3><p>Источников: ' . Security::e((string)$summary['sources']) . '. Добавлено строк: ' . Security::e((string)$summary['inserted']) . '. Пропущено: ' . Security::e((string)$summary['skipped']) . '.</p>' . $errText . '</div>';
        }
        if ($errors) {
            $items = '';
            foreach ($errors as $error) {
                $items .= '<li>' . Security::e($error) . '</li>';
            }
            $notice .= '<div class="notice danger"><ul>' . $items . '</ul></div>';
        }

        $siteOptions = '<option value="">Все сайты</option>';
        $seen = [];
        foreach ($sources as $src) {
            $site = (string)$src['site'];
            if (isset($seen[$site])) {
                continue;
            }
            $seen[$site] = true;
            $siteOptions .= '<option value="' . Security::e($site) . '">' . Security::e($site) . '</option>';
        }

        $sourceRows = '';
        foreach ($sources as $src) {
            $typeLabel = $src['log_type'] === 'error' ? 'Ошибки' : 'Доступ';
            $readable = $src['readable'] ? '<span class="status status-ok">читается</span>' : '<span class="status status-danger">нет доступа</span>';
            $sourceRows .= '<tr>'
                . '<td>' . Security::e($src['site']) . '</td>'
                . '<td>' . Security::e($typeLabel) . '</td>'
                . '<td><code>' . Security::e($src['file_path']) . '</code></td>'
                . '<td>' . Security::e((string)$src['size_bytes']) . '</td>'
                . '<td>' . Security::e((string)$src['offset_bytes']) . '</td>'
                . '<td>' . Security::e((string)$src['imported_lines']) . '</td>'
                . '<td>' . Security::e((string)($src['inode'] ?? '—')) . '</td>'
                . '<td>' . Security::e(!empty($src['rotation_detected']) ? 'да' : 'нет') . '</td>'
                . '<td>' . Security::e((string)($src['last_import_at'] ?? '—')) . '</td>'
                . '<td>' . $readable . '</td>'
                . '</tr>';
        }
        if ($sourceRows === '') {
            $sourceRows = '<tr><td colspan="10" class="muted">Файлы логов не найдены или PHP-FPM не имеет доступа к /www/wwwlogs.</td></tr>';
        }

        $statRows = '';
        foreach ($stats as $row) {
            $statRows .= '<tr>'
                . '<td><a href="/sites/' . rawurlencode((string)$row['site']) . '">' . Security::e((string)$row['site']) . '</a></td>'
                . '<td>' . Security::e((string)$row['total']) . '</td>'
                . '<td>' . Security::e((string)$row['errors']) . '</td>'
                . '<td>' . Security::e((string)($row['last_at'] ?? '—')) . '</td>'
                . '</tr>';
        }
        if ($statRows === '') {
            $statRows = '<tr><td colspan="4" class="muted">Импортированных логов сайтов пока нет.</td></tr>';
        }

        $body = <<<HTML
<h1>Логи сайтов</h1>
{$notice}
<section class="panel">
    <h2>Импорт из /www/wwwlogs</h2>
    <p class="muted">Импорт читает только новые строки с последней сохранённой позиции. После logrotate позиция автоматически сбрасывается.</p>
    <form class="form-grid" method="post" action="/sites/import">
        <input type="hidden" name="_csrf" value="{$csrf}">
        <label>Сайт<select name="site">{$siteOptions}</select></label>
        <label>Максимум строк за запуск<input name="max_lines" type="number" min="1" max="10000" value="1000"></label>
        <div class="form-actions full"><button type="submit" name="mode" value="sync">Импортировать сейчас</button><button class="button ghost" type="submit" name="mode" value="queue">Поставить в очередь</button><a class="button ghost" href="/logs?project=Web+Sites">Открыть логи сайтов</a></div>
    </form>
</section>
<section class="panel wide">
    <h2>Источники</h2>
    <table><thead><tr><th>Сайт</th><th>Тип</th><th>Файл</th><th>Размер</th><th>Позиция</th><th>Импортировано</th><th>Inode</th><th>Ротация</th><th>Последний импорт</th><th>Доступ</th></tr></thead><tbody>{$sourceRows}</tbody></table>
</section>
<section class="panel wide">
    <h2>Статистика импортированных логов</h2>
    <table><thead><tr><th>Сайт</th><th>Всего</th><th>Ошибки</th><th>Последняя запись</th></tr></thead><tbody>{$statRows}</tbody></table>
</section>
HTML;
        Response::html(View::layout('Логи сайтов', $body));
    }

    private function importAaPanelSiteLogs(): void
    {
        if (!Security::verifyCsrfToken('aapanel_import', (string)($_POST['_csrf'] ?? ''))) {
            $this->sites(null, ['Срок действия формы истёк. Обнови страницу и повтори импорт.']);
            return;
        }
        $site = trim((string)($_POST['site'] ?? ''));
        $maxLines = max(1, min(10000, (int)($_POST['max_lines'] ?? 1000)));
        $repo = $this->repo();
        if ((string)($_POST['mode'] ?? 'sync') === 'queue') {
            $jobId = $repo->createJob('aapanel_import', ['site' => $site !== '' ? $site : null, 'max_lines' => $maxLines, 'dir' => Env::get('NGINX_LOG_DIR', Env::get('AAPANEL_LOG_DIR', '/www/wwwlogs'))]);
            $repo->audit('job.created', 'job', (string)$jobId, 'Создана задача импорта логов сайтов', ['site' => $site, 'max_lines' => $maxLines]);
            $this->sites(['sources' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => ['Задача #' . $jobId . ' поставлена в очередь. Запусти bin/process-jobs.php через cron.']]);
            return;
        }
        $summary = (new AaPanelLogImporter($repo))->importAll(Env::get('NGINX_LOG_DIR', Env::get('AAPANEL_LOG_DIR', '/www/wwwlogs')), $site !== '' ? $site : null, $maxLines);
        $repo->audit('aapanel_logs.imported', 'aapanel_site', $site !== '' ? $site : 'all', 'Импортированы логи сайтов', $summary);
        $this->sites($summary);
    }

    private function botsHealth(): void
    {
        $rows = '';
        foreach ($this->repo()->botHealth() as $bot) {
            $status = (string)$bot['health_status'];
            $label = match ($status) {
                'online' => 'на связи',
                'stale' => 'давно не писал',
                'offline' => 'нет данных',
                'never' => 'не подключался',
                default => 'нет данных',
            };
            $class = $status === 'online' ? 'status-ok' : ($status === 'stale' ? 'status-warn' : 'status-danger');
            $age = isset($bot['age_seconds']) && $bot['age_seconds'] !== null ? $this->formatDuration((int)$bot['age_seconds']) : '—';
            $logs24h = (int)($bot['logs_24h'] ?? 0);
            $avgHour = number_format($logs24h / 24, 1, '.', '');
            $logsUrl = '/logs?project=' . rawurlencode((string)$bot['project']) . '&bot=' . rawurlencode((string)$bot['bot']) . '&environment=' . rawurlencode((string)$bot['environment']) . '&range=24h';
            $errorsUrl = $logsUrl . '&level=ERROR';
            $rows .= '<tr>'
                . '<td>' . Security::e($bot['project']) . '</td>'
                . '<td>' . Security::e($bot['bot']) . '</td>'
                . '<td>' . Security::e($bot['environment']) . '</td>'
                . '<td><span class="status ' . $class . '">' . Security::e($label) . '</span></td>'
                . '<td>' . Security::e((string)($bot['last_used_at'] ?? '—')) . '</td>'
                . '<td>' . Security::e($age) . '</td>'
                . '<td>' . Security::e((string)($bot['last_error_at'] ?? '—')) . '</td>'
                . '<td>' . Security::e((string)$logs24h) . '</td>'
                . '<td>' . Security::e((string)$avgHour) . '</td>'
                . '<td>' . Security::e((string)($bot['errors_24h'] ?? 0)) . '</td>'
                . '<td><a class="button small ghost" href="' . Security::e($logsUrl) . '">Журналы</a> <a class="button small ghost" href="' . Security::e($errorsUrl) . '">Ошибки</a></td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="11" class="muted">Боты ещё не подключены</td></tr>';
        }
        $body = '<h1>Здоровье ботов</h1>'
            . '<section class="panel"><p class="muted">Статус считается по последнему успешному приёму журналов: до 10 минут — «на связи», до часа — «давно не писал», больше часа — «нет данных».</p></section>'
            . '<section class="panel wide"><table><thead><tr><th>Проект</th><th>Бот</th><th>Окружение</th><th>Статус</th><th>Последний лог</th><th>Возраст</th><th>Последняя ошибка</th><th>Журналы 24ч</th><th>Средн./час</th><th>Ошибки 24ч</th><th>Действия</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
        Response::html(View::layout('Здоровье ботов', $body));
    }


    private function incidents(): void
    {
        $rows = '';
        $csrf = Security::csrfToken('incident_action');
        foreach ($this->repo()->incidents(200) as $incident) {
            $id = (int)$incident['id'];
            $muted = !empty($incident['muted_until_at']) ? (string)$incident['muted_until_at'] : '—';
            $rows .= '<tr><td><a href="/incidents/' . $id . '">#' . $id . '</a></td><td><span class="level level-' . strtolower((string)$incident['level']) . '">' . Security::e($this->levelLabel((string)$incident['level'])) . '</span></td><td>' . Security::e($incident['project']) . '</td><td>' . Security::e($incident['bot']) . '</td><td>' . Security::e($incident['environment']) . '</td><td>' . Security::e($incident['title']) . '</td><td>' . Security::e((string)$incident['event_count']) . '</td><td>' . Security::e((string)$incident['last_seen_at']) . '</td><td>' . Security::e($this->incidentStatusLabel((string)$incident['status'])) . '</td><td>' . Security::e($muted) . '</td><td><form method="post" action="/incidents/action"><input type="hidden" name="_csrf" value="' . Security::e($csrf) . '"><input type="hidden" name="id" value="' . $id . '"><select name="status"><option value="open">открыт</option><option value="acknowledged">принят в работу</option><option value="resolved">решён</option></select><button class="button small" name="action" value="status" type="submit">OK</button><button class="button small ghost" name="action" value="mute_1h" type="submit">Заглушить 1ч</button><button class="button small ghost" name="action" value="mute_24h" type="submit">Заглушить 24ч</button><button class="button small ghost" name="action" value="unmute" type="submit">Снять заглушение</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="11" class="muted">Инцидентов нет</td></tr>';
        }
        $body = '<h1>Инциденты</h1><section class="panel wide"><table><thead><tr><th>ID</th><th>Уровень</th><th>Проект</th><th>Бот</th><th>Окружение</th><th>Заголовок</th><th>Событий</th><th>Последний раз</th><th>Статус</th><th>Заглушен до</th><th>Действие</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
        Response::html(View::layout('Инциденты', $body));
    }

    private function incidentDetail(int $id): void
    {
        $incident = $this->repo()->findIncident($id);
        if (!$incident) {
            Response::html(View::layout('Инцидент не найден', '<h1>Инцидент не найден</h1><section class="panel"><a class="button" href="/incidents">К списку</a></section>'), 404);
            return;
        }
        $logs = $this->repo()->recentLogs(['fingerprint' => (string)$incident['fingerprint']], 50);
        $body = '<h1>Инцидент #' . $id . '</h1><div class="quick-actions"><a class="button ghost" href="/incidents">← К списку</a><a class="button ghost" href="/logs?fingerprint=' . Security::e((string)$incident['fingerprint']) . '">Логи по отпечатку</a></div>'
            . '<section class="panel"><h2>' . Security::e($incident['title']) . '</h2><p class="muted">Отпечаток группы: <code>' . Security::e((string)$incident['fingerprint']) . '</code></p><p>Событий: ' . Security::e((string)$incident['event_count']) . ', статус: ' . Security::e($this->incidentStatusLabel((string)$incident['status'])) . '</p></section>'
            . '<section class="panel"><h2>Пример сообщения</h2><pre>' . Security::e((string)($incident['sample_message'] ?? '—')) . '</pre></section>'
            . '<section class="panel"><h2>Последние связанные логи</h2><table><thead><tr><th>Время</th><th>Уровень</th><th>Проект</th><th>Бот</th><th>Сообщение</th><th></th></tr></thead><tbody>' . $this->renderLogRows($logs) . '</tbody></table></section>';
        Response::html(View::layout('Инцидент #' . $id, $body));
    }

    private function incidentAction(): void
    {
        if (!Security::verifyCsrfToken('incident_action', (string)($_POST['_csrf'] ?? ''))) {
            Response::redirect('/incidents');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        $action = (string)($_POST['action'] ?? 'status');
        if ($id > 0) {
            if ($action === 'mute_1h') {
                $this->repo()->setIncidentMute($id, 1, 'заглушено через UI');
                $this->repo()->audit('incident.muted', 'incident', (string)$id, 'Инцидент заглушён на 1 час');
            } elseif ($action === 'mute_24h') {
                $this->repo()->setIncidentMute($id, 24, 'заглушено через UI');
                $this->repo()->audit('incident.muted', 'incident', (string)$id, 'Инцидент заглушён на 24 часа');
            } elseif ($action === 'unmute') {
                $this->repo()->setIncidentMute($id, null, null);
                $this->repo()->audit('incident.unmuted', 'incident', (string)$id, 'Заглушение инцидента снято');
            } else {
                $status = (string)($_POST['status'] ?? 'open');
                if ($this->repo()->setIncidentStatus($id, $status)) {
                    $this->repo()->audit('incident.status', 'incident', (string)$id, 'Изменён статус инцидента', ['status' => $status]);
                }
            }
        }
        Response::redirect('/incidents');
    }

    private function alerts(array $errors = [], ?string $message = null): void
    {
        $repo = $this->repo();
        $rows = '';
        $csrf = Security::csrfToken('alert_action');
        foreach ($repo->alertRules() as $rule) {
            $active = (int)$rule['is_active'] === 1;
            $toggle = $active ? 'disable' : 'enable';
            $channel = (string)$rule['channel'];
            $channelLabel = $channel === 'discord' ? 'Discord' : 'Telegram';
            $rows .= '<tr>'
                . '<td>' . Security::e((string)$rule['id']) . '</td>'
                . '<td>' . Security::e($rule['name']) . '</td>'
                . '<td>' . Security::e($channelLabel) . '</td>'
                . '<td>' . Security::e($rule['project'] ?: 'Все') . '</td>'
                . '<td>' . Security::e($rule['bot'] ?: 'Все') . '</td>'
                . '<td><code>' . Security::e(Security::maskSecret((string)$rule['webhook_url'])) . '</code></td>'
                . '<td>' . Security::e($rule['levels']) . '</td>'
                . '<td>' . Security::e((string)$rule['threshold_count']) . ' за ' . Security::e((string)$rule['window_seconds']) . ' сек.</td>'
                . '<td>' . Security::e((string)$rule['cooldown_seconds']) . ' сек.</td>'
                . '<td>' . Security::e((string)($rule['last_fired_at'] ?? '—')) . '</td>'
                . '<td>' . Security::e($active ? 'Активно' : 'Отключено') . '</td>'
                . '<td><form method="post" action="/alerts/action">'
                . '<input type="hidden" name="_csrf" value="' . Security::e($csrf) . '">'
                . '<input type="hidden" name="id" value="' . Security::e((string)$rule['id']) . '">'
                . '<button class="button small ghost" name="action" value="' . $toggle . '">' . Security::e($active ? 'Отключить' : 'Включить') . '</button>'
                . '<button class="button small ghost" name="action" value="test">Проверить</button>'
                . '<button class="button small danger" name="action" value="delete">Удалить</button>'
                . '</form></td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="12" class="muted">Правила пока не созданы</td></tr>';
        }

        $deliveryRows = '';
        $deliveryCsrf = Security::csrfToken('alert_delivery_action');
        foreach ($repo->alertDeliveries(30) as $delivery) {
            $deliveryRows .= '<tr>'
                . '<td>' . Security::e((string)$delivery['delivered_at']) . '</td>'
                . '<td>' . Security::e((string)($delivery['rule_name'] ?? '—')) . '</td>'
                . '<td>' . Security::e((string)($delivery['channel'] ?? '—')) . '</td>'
                . '<td>' . Security::e($this->deliveryStatusLabel((string)$delivery['status'])) . '</td>'
                . '<td>' . Security::e((string)$delivery['message']) . '</td>'
                . '<td><form method="post" action="/alerts/deliveries/action"><input type="hidden" name="_csrf" value="' . Security::e($deliveryCsrf) . '"><input type="hidden" name="id" value="' . Security::e((string)$delivery['id']) . '"><button class="button small ghost" name="action" value="replay">Повторить</button></form></td>'
                . '</tr>';
        }
        if ($deliveryRows === '') {
            $deliveryRows = '<tr><td colspan="6" class="muted">Доставок пока нет.</td></tr>';
        }

        $notice = $message ? '<div class="notice success">' . Security::e($message) . '</div>' : '';
        if ($errors) {
            $notice = '<div class="notice danger">' . Security::e(implode(' ', $errors)) . '</div>';
        }
        $createCsrf = Security::csrfToken('create_alert');
        $body = <<<HTML
<h1>Оповещения</h1>
{$notice}
<section class="panel">
    <h2>Добавить правило уведомлений</h2>
    <p class="muted">Правило группирует повторяющиеся ошибки по проекту, боту, окружению и уровню. Поле «Пауза» ограничивает частоту отправки и защищает от спама.</p>
    <form class="form-grid" method="post" action="/alerts">
        <input type="hidden" name="_csrf" value="{$createCsrf}">
        <label>Название<input name="name" required maxlength="160" placeholder="Ошибки ExampleBot"></label>
        <label>Канал доставки<select name="channel"><option value="telegram">Telegram</option><option value="discord">Discord</option></select></label>
        <label class="full">URL вебхука<input name="webhook_url" required placeholder="https://..."></label>
        <label>Проект<input name="project" placeholder="ExampleProject"></label>
        <label>Бот<input name="bot" placeholder="ExampleBot"></label>
        <label>Окружение<input name="environment" placeholder="production"></label>
        <label>Уровни<input name="levels" value="ERROR,CRITICAL,SECURITY"></label>
        <label>Порог событий<input name="threshold_count" type="number" min="1" value="1"></label>
        <label>Окно, секунд<input name="window_seconds" type="number" min="60" value="300"></label>
        <label>Пауза между отправками, секунд<input name="cooldown_seconds" type="number" min="60" value="900"></label>
        <div class="form-actions full"><button type="submit">Создать правило</button></div>
    </form>
</section>
<section class="panel wide"><h2>Правила</h2><table><thead><tr><th>ID</th><th>Название</th><th>Канал</th><th>Проект</th><th>Бот</th><th>Вебхук</th><th>Уровни</th><th>Порог</th><th>Пауза</th><th>Последняя отправка</th><th>Статус</th><th>Действия</th></tr></thead><tbody>{$rows}</tbody></table></section>
<section class="panel wide"><h2>Журнал доставок</h2><table><thead><tr><th>Время</th><th>Правило</th><th>Канал</th><th>Статус</th><th>Ответ</th><th>Действия</th></tr></thead><tbody>{$deliveryRows}</tbody></table></section>
<section class="panel"><h2>Повтор failed-доставок</h2><form method="post" action="/alerts/deliveries/action"><input type="hidden" name="_csrf" value="{$deliveryCsrf}"><input type="hidden" name="action" value="replay_failed"><label>Период, часов<input name="hours" type="number" min="1" max="168" value="24"></label><div class="form-actions"><button type="submit">Повторить failed/dead</button></div></form></section>
<section class="panel"><h2>Запуск</h2><pre>cd /www/wwwroot/logs.example.com
/www/server/php/83/bin/php bin/alert-dispatch.php
/www/server/php/83/bin/php bin/process-jobs.php</pre></section>
HTML;
        Response::html(View::layout('Оповещения', $body));
    }


    private function createAlertRule(): void
    {
        if (!Security::verifyCsrfToken('create_alert', (string)($_POST['_csrf'] ?? ''))) {
            $this->alerts(['Срок действия формы истёк.']);
            return;
        }
        $data = [
            'name' => trim((string)($_POST['name'] ?? '')),
            'channel' => trim((string)($_POST['channel'] ?? 'telegram')),
            'webhook_url' => trim((string)($_POST['webhook_url'] ?? '')),
            'project' => trim((string)($_POST['project'] ?? '')),
            'bot' => trim((string)($_POST['bot'] ?? '')),
            'environment' => trim((string)($_POST['environment'] ?? '')),
            'levels' => trim((string)($_POST['levels'] ?? 'ERROR,CRITICAL,SECURITY')),
            'threshold_count' => (int)($_POST['threshold_count'] ?? 1),
            'window_seconds' => (int)($_POST['window_seconds'] ?? 300),
            'cooldown_seconds' => (int)($_POST['cooldown_seconds'] ?? 900),
        ];
        $errors = [];
        if ($data['name'] === '' || $data['webhook_url'] === '' || !in_array($data['channel'], ['telegram', 'discord'], true)) {
            $errors[] = 'Заполни название, канал и URL вебхука.';
        }
        if ($data['webhook_url'] !== '') {
            [$webhookOk, $webhookReason] = Security::validateExternalWebhookUrl($data['webhook_url']);
            if (!$webhookOk) {
                $errors[] = $webhookReason;
                $this->repo()->audit('alert_rule.webhook_blocked', 'alert_rule', null, 'Заблокирован небезопасный URL вебхука', ['reason' => $webhookReason]);
            }
        }
        if ($errors) {
            $this->alerts($errors);
            return;
        }
        $id = $this->repo()->createAlertRule($data);
        $this->repo()->audit('alert_rule.created', 'alert_rule', (string)$id, 'Создано правило оповещений', ['name' => $data['name']]);
        $this->alerts([], 'Правило создано.');
    }

    private function alertAction(): void
    {
        if (!Security::verifyCsrfToken('alert_action', (string)($_POST['_csrf'] ?? ''))) {
            Response::redirect('/alerts');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        if ($id > 0) {
            if ($action === 'enable') { $this->repo()->setAlertRuleActive($id, true); }
            if ($action === 'disable') { $this->repo()->setAlertRuleActive($id, false); }
            if ($action === 'test') {
                $result = $this->repo()->testAlertRule($id);
                $this->repo()->audit('alert_rule.tested', 'alert_rule', (string)$id, 'Проверена доставка оповещения', $result);
                $this->alerts($result['ok'] ? [] : [(string)$result['message']], $result['ok'] ? 'Проверочное оповещение отправлено.' : null);
                return;
            }
            if ($action === 'delete') { $this->repo()->deleteAlertRule($id); }
            $this->repo()->audit('alert_rule.' . $action, 'alert_rule', (string)$id, 'Действие с правилом оповещений');
        }
        Response::redirect('/alerts');
    }


    private function alertDeliveryAction(): void
    {
        if (!Security::verifyCsrfToken('alert_delivery_action', (string)($_POST['_csrf'] ?? ''))) {
            Response::redirect('/alerts');
            return;
        }
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'replay') {
                $id = (int)($_POST['id'] ?? 0);
                $jobId = $this->repo()->replayAlertDelivery($id);
                if ($jobId === null) {
                    throw new \InvalidArgumentException('Доставка не найдена или правило недоступно.');
                }
                $this->repo()->audit('alert_delivery.replay', 'alert_delivery', (string)$id, 'Повтор доставки поставлен в очередь', ['job_id' => $jobId]);
                $this->alerts([], 'Повторная доставка поставлена в очередь job #' . $jobId . '.');
                return;
            }
            if ($action === 'replay_failed') {
                $hours = (int)($_POST['hours'] ?? 24);
                $count = $this->repo()->replayFailedAlertDeliveries($hours);
                $this->repo()->audit('alert_delivery.replay_failed', 'alert_delivery', 'bulk', 'Повтор failed-доставок', ['hours' => $hours, 'count' => $count]);
                $this->alerts([], 'В очередь поставлено повторных доставок: ' . $count . '.');
                return;
            }
            throw new \InvalidArgumentException('Неизвестное действие доставки.');
        } catch (Throwable $e) {
            $this->alerts([$e->getMessage()]);
        }
    }

    private function audit(): void
    {
        $repo = $this->repo();
        $events = $repo->recentAudit(200);
        $rows = '';
        foreach ($events as $event) {
            $rows .= '<tr><td>' . Security::e((string)$event['created_at']) . '</td><td>' . Security::e((string)$event['actor']) . '</td><td>' . Security::e((string)$event['action']) . '</td><td>' . Security::e((string)$event['entity_type']) . '</td><td>' . Security::e((string)$event['entity_id']) . '</td><td>' . Security::e((string)$event['message']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="muted">Записей аудита пока нет</td></tr>';
        }
        $body = '<h1>Аудит</h1><section class="panel wide"><table><thead><tr><th>Время</th><th>Пользователь</th><th>Действие</th><th>Сущность</th><th>ID</th><th>Сообщение</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
        Response::html(View::layout('Аудит', $body));
    }


    private function users(array $errors = [], ?string $message = null): void
    {
        $repo = $this->repo();
        $rows = '';
        $csrf = Security::csrfToken('user_action');
        foreach ($repo->users() as $u) {
            $active = (int)$u['is_active'] === 1;
            $rows .= '<tr><td>' . Security::e((string)$u['id']) . '</td><td>' . Security::e((string)$u['username']) . '</td><td>' . Security::e((string)$u['role']) . '</td><td>' . Security::e($active ? 'Активен' : 'Отключён') . '</td><td>' . Security::e((string)($u['last_login_at'] ?? '—')) . '</td><td>' . Security::e((string)$u['created_at']) . '</td><td><form class="inline-actions" method="post" action="/users/action"><input type="hidden" name="_csrf" value="' . Security::e($csrf) . '"><input type="hidden" name="id" value="' . Security::e((string)$u['id']) . '"><button class="button small ghost" name="action" value="' . ($active ? 'disable' : 'enable') . '">' . Security::e($active ? 'Отключить' : 'Включить') . '</button><button class="button small danger" name="action" value="delete">Удалить</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="muted">Пользователей пока нет.</td></tr>';
        }
        $notice = '';
        if ($errors) {
            $notice = '<div class="notice danger">' . Security::e(implode(' ', $errors)) . '</div>';
        } elseif ($message) {
            $notice = '<div class="notice success">' . Security::e($message) . '</div>';
        }
        $createCsrf = Security::csrfToken('save_user');
        $fallback = Env::bool('LOGS_ENV_FALLBACK_LOGIN', false) ? '<div class="notice warn">Аварийный вход через <code>.env</code> включён. После создания пользователя с ролью admin лучше задать <code>LOGS_ENV_FALLBACK_LOGIN=false</code>.</div>' : '';
        $body = <<<HTML
<h1>Пользователи</h1>
{$notice}
{$fallback}
<section class="panel">
    <h2>Создать или обновить пользователя</h2>
    <form class="form-grid" method="post" action="/users">
        <input type="hidden" name="_csrf" value="{$createCsrf}">
        <label>ID для обновления<input name="id" type="number" min="1" placeholder="оставить пустым для создания"></label>
        <label>Логин<input name="username" required maxlength="120"></label>
        <label>Пароль<input name="password" type="password" autocomplete="new-password" placeholder="при обновлении можно оставить пустым"></label>
        <label>Роль<select name="role"><option value="admin">admin</option><option value="operator">operator</option><option value="security">security</option><option value="viewer">viewer</option></select></label>
        <label class="check-row"><input type="checkbox" name="is_active" value="1" checked> Активен</label>
        <div class="form-actions full"><button type="submit">Сохранить пользователя</button></div>
    </form>
</section>
<section class="panel wide">
    <h2>Список пользователей</h2>
    <table><thead><tr><th>ID</th><th>Логин</th><th>Роль</th><th>Статус</th><th>Последний вход</th><th>Создан</th><th>Действия</th></tr></thead><tbody>{$rows}</tbody></table>
</section>
HTML;
        Response::html(View::layout('Пользователи', $body));
    }

    private function saveUserFromForm(): void
    {
        if (!Security::verifyCsrfToken('save_user', (string)($_POST['_csrf'] ?? ''))) {
            $this->users(['Срок действия формы истёк.']);
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? 'viewer');
        $active = isset($_POST['is_active']);
        try {
            $savedId = $this->repo()->saveUser($id > 0 ? $id : null, $username, $password !== '' ? $password : null, $role, $active);
            $this->repo()->audit('user.saved', 'user', (string)$savedId, 'Сохранён пользователь', ['username' => $username, 'role' => $role]);
            $this->users([], 'Пользователь сохранён.');
        } catch (Throwable $e) {
            $this->users([$e->getMessage()]);
        }
    }

    private function userAction(): void
    {
        if (!Security::verifyCsrfToken('user_action', (string)($_POST['_csrf'] ?? ''))) {
            Response::redirect('/users');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        if ($id > 0) {
            try {
                if ($action === 'enable') { $this->repo()->setUserActive($id, true); }
                if ($action === 'disable') { $this->repo()->setUserActive($id, false); }
                if ($action === 'delete') { $this->repo()->deleteUser($id); }
                $this->repo()->audit('user.' . $action, 'user', (string)$id, 'Действие с пользователем');
            } catch (Throwable $e) {
                $this->repo()->audit('user.' . $action . '.denied', 'user', (string)$id, 'Действие с пользователем отклонено', ['reason' => $e->getMessage()]);
                $this->users([$e->getMessage()]);
                return;
            }
        }
        Response::redirect('/users');
    }


    private function updateCenter(array $errors = [], ?string $message = null, ?string $output = null): void
    {
        $manager = new UpdateManager($this->repo());
        $status = $manager->status();
        $checks = $manager->readiness();
        $csrf = Security::csrfToken('update_action');
        $notice = '';
        if ($errors) {
            $notice = '<div class="notice danger">' . Security::e(implode(' ', $errors)) . '</div>';
        } elseif ($message) {
            $notice = '<div class="notice success">' . Security::e($message) . '</div>';
        }
        $cards = '';
        $remoteShort = !empty($status['remote_commit']) ? substr((string)$status['remote_commit'], 0, 12) : '—';
        $currentShort = !empty($status['current_commit']) ? substr((string)$status['current_commit'], 0, 12) : '—';
        $items = [
            'Версия' => (string)$status['version'],
            'Текущий commit' => $currentShort,
            'Удалённый commit' => $remoteShort,
            'Ветка' => (string)($status['config']['branch'] ?? 'main'),
            'Репозиторий' => (string)($status['config']['repo_url'] ?? ''),
        ];
        foreach ($items as $label => $value) {
            $cards .= '<div class="card"><div class="label">' . Security::e($label) . '</div><div class="value small-value">' . Security::e($value) . '</div></div>';
        }

        $checkRows = '';
        foreach ($checks as $check) {
            $class = $check['ok'] ? 'status-ok' : 'status-error';
            $checkRows .= '<tr><td>' . Security::e($check['name']) . '</td><td><span class="status ' . $class . '">' . Security::e($check['ok'] ? 'OK' : 'Проблема') . '</span></td><td>' . Security::e($check['message']) . '</td></tr>';
        }

        $runs = '';
        try {
            foreach ($this->repo()->updateRuns(30) as $run) {
                $runs .= '<tr><td>' . Security::e((string)$run['started_at']) . '</td><td>' . Security::e((string)$run['actor']) . '</td><td>' . Security::e((string)$run['action']) . '</td><td>' . Security::e((string)$run['status']) . '</td><td>' . Security::e(substr((string)$run['from_commit'], 0, 12)) . '</td><td>' . Security::e(substr((string)$run['to_commit'], 0, 12)) . '</td><td>' . Security::e((string)($run['duration_ms'] ?? '—')) . '</td><td>' . Security::e((string)($run['error_message'] ?? '')) . '</td></tr>';
            }
        } catch (Throwable $e) {
            $runs = '<tr><td colspan="8">' . Security::e($e->getMessage()) . '</td></tr>';
        }
        if ($runs === '') {
            $runs = '<tr><td colspan="8" class="muted">Обновлений пока нет.</td></tr>';
        }
        $outputHtml = $output !== null && $output !== '' ? '<section class="panel"><h2>Вывод операции</h2><pre>' . Security::e($output) . '</pre></section>' : '';
        $repo = Security::e((string)($status['config']['repo_url'] ?? 'https://github.com/CajeerTeam/CajeerLogs'));
        $branch = Security::e((string)($status['config']['branch'] ?? 'main'));
        $backupDir = Security::e((string)($status['config']['backup_dir'] ?? 'storage/backups/updates'));
        $localChangesList = trim((string)($status['local_changes_list'] ?? ''));
        $diffStat = trim((string)($status['git_diff_stat'] ?? ''));
        $localChanges = $status['has_local_changes'] === true
            ? '<div class="notice warn"><strong>В рабочем дереве есть локальные изменения.</strong><p>Обновление через <code>git reset --hard</code> их сотрёт. Проверь изменения и создай backup diff.</p><pre>' . Security::e($localChangesList !== '' ? $localChangesList : 'git status --short') . '</pre>' . ($diffStat !== '' ? '<p class="muted">Diff stat:</p><pre>' . Security::e($diffStat) . '</pre>' : '') . '</div>'
            : '';
        $repairRows = '';
        foreach ($manager->repairHints() as $label => $command) {
            $cmdEsc = Security::e((string)$command);
            $repairRows .= '<tr><th>' . Security::e((string)$label) . '</th><td><code>' . $cmdEsc . '</code><button class="button small ghost copy-command" type="button" data-copy-text="' . $cmdEsc . '">Скопировать</button></td></tr>';
        }
        $runtimeRows = '';
        foreach (($status['php_runtime'] ?? []) as $label => $value) {
            $runtimeRows .= '<tr><th>' . Security::e((string)$label) . '</th><td><code>' . Security::e((string)$value) . '</code></td></tr>';
        }
        $phases = [
            '1. Проверить окружение',
            '2. Создать резервную копию',
            '3. Проверить удалённый tag/branch',
            '4. Скачать код через git fetch',
            '5. Переключить код через git reset --hard',
            '6. Восстановить .env из backup',
            '7. Применить миграции',
            '8. Выполнить health/release checks',
            '9. Перезапустить PHP-FPM вручную при необходимости',
        ];
        $phaseItems = '<ol class="timeline-list"><li>' . implode('</li><li>', array_map([Security::class, 'e'], $phases)) . '</li></ol>';
        $body = <<<HTML
<h1>Обновление приложения</h1>
{$notice}
{$localChanges}
<section class="grid cards">{$cards}</section>
<section class="panel">
    <h2>Настройки GitHub</h2>
    <table class="detail-table"><tbody>
        <tr><th>Репозиторий</th><td><code>{$repo}</code></td></tr>
        <tr><th>Ветка</th><td><code>{$branch}</code></td></tr>
        <tr><th>Резервная копия</th><td><code>{$backupDir}</code></td></tr>
        <tr><th>Токен GitHub</th><td>{$this->yesNo((bool)($status['config']['uses_token'] ?? false))}</td></tr>
        <tr><th>Git</th><td><code>{$status['config']['git_bin']}</code></td></tr>
        <tr><th>tar</th><td><code>{$status['config']['tar_bin']}</code></td></tr>
        <tr><th>PHP CLI</th><td><code>{$status['config']['php_bin']}</code></td></tr>
        <tr><th>Режим</th><td><code>git fetch + git reset --hard {$branch}</code></td></tr>
    </tbody></table>
</section>
<section class="panel wide">
    <h2>Готовность к обновлению</h2>
    <table><thead><tr><th>Проверка</th><th>Статус</th><th>Комментарий</th></tr></thead><tbody>{$checkRows}</tbody></table>
</section>
<section class="panel wide">
    <h2>Среда PHP-FPM/CLI</h2>
    <table class="detail-table"><tbody>{$runtimeRows}</tbody></table>
</section>
<section class="panel wide">
    <h2>Команды исправления</h2>
    <table class="detail-table"><tbody>{$repairRows}</tbody></table>
</section>
<section class="panel">
    <h2>Фазы обновления</h2>
    {$phaseItems}
</section>
<section class="panel">
    <h2>Действия</h2>
    <div class="quick-actions update-actions">
        <form method="post" action="/system/update/action"><input type="hidden" name="_csrf" value="{$csrf}"><button name="action" value="backup" type="submit">Создать резервную копию</button></form>
        <form method="post" action="/system/update/action"><input type="hidden" name="_csrf" value="{$csrf}"><input name="confirm" placeholder="Введите UPDATE" autocomplete="off"><button name="action" value="update" type="submit">Обновить из GitHub</button></form>
        <form method="post" action="/system/update/action"><input type="hidden" name="_csrf" value="{$csrf}"><input name="confirm" placeholder="Введите ROLLBACK" autocomplete="off"><button class="danger" name="action" value="rollback" type="submit">Откатить последнее</button></form>
    </div>
    <p class="muted">Перед обновлением создаётся резервная копия файлов и сохраняется предыдущий commit. После обновления запускаются <code>bin/migrate.php</code> и read-only проверка <code>bin/doctor.php</code>.</p>
</section>
{$outputHtml}
<section class="panel wide">
    <h2>История обновлений</h2>
    <table><thead><tr><th>Старт</th><th>Пользователь</th><th>Действие</th><th>Статус</th><th>Было</th><th>Стало</th><th>мс</th><th>Ошибка</th></tr></thead><tbody>{$runs}</tbody></table>
</section>
<section class="panel">
    <h2>CLI-эквивалент</h2>
    <pre>cd /www/wwwroot/logs.example.com
/www/server/php/83/bin/php bin/update.php status
/www/server/php/83/bin/php bin/update.php backup
/www/server/php/83/bin/php bin/update.php update</pre>
</section>
HTML;
        Response::html(View::layout('Обновление приложения', $body));
    }

    private function updateCenterAction(): void
    {
        if (!Security::verifyCsrfToken('update_action', (string)($_POST['_csrf'] ?? ''))) {
            $this->updateCenter(['Срок действия формы истёк.']);
            return;
        }
        $action = (string)($_POST['action'] ?? '');
        $confirm = trim((string)($_POST['confirm'] ?? ''));
        $manager = new UpdateManager($this->repo());
        try {
            if ($action === 'backup') {
                $result = $manager->backupOnly();
                $this->repo()->audit('update.backup', 'update', 'manual', 'Создана резервная копия перед обновлением', ['dir' => $result['dir']]);
                $this->updateCenter([], 'Резервная копия создана: ' . $result['dir'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                return;
            }
            if ($action === 'update') {
                if ($confirm !== 'UPDATE') {
                    $this->updateCenter(['Для запуска обновления введи UPDATE.']);
                    return;
                }
                $result = $manager->updateFromGit();
                $this->updateCenter([], 'Обновление выполнено.', (string)$result['output']);
                return;
            }
            if ($action === 'rollback') {
                if ($confirm !== 'ROLLBACK') {
                    $this->updateCenter(['Для отката введи ROLLBACK.']);
                    return;
                }
                $result = $manager->rollbackLast();
                $this->updateCenter([], 'Откат выполнен до commit ' . $result['commit'], (string)$result['output']);
                return;
            }
            $this->updateCenter(['Неизвестное действие.']);
        } catch (Throwable $e) {
            Logger::error('Update center action failed', ['action' => $action, 'exception' => $e]);
            $this->updateCenter([$e->getMessage()]);
        }
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'да' : 'нет';
    }

    private function system(): void
    {
        $this->repo()->audit('system.viewed', 'system', 'overview', 'Открыта страница состояния системы');
        $diag = Database::diagnostics();
        $root = dirname(__DIR__, 2);
        $docsUrl = Env::get('DOCS_URL', 'https://github.com/CajeerTeam/CajeerLogs/wiki') ?: 'https://github.com/CajeerTeam/CajeerLogs/wiki';
        $docsStatus = $this->docsAvailability($docsUrl);

        $checks = [];
        $checks['PHP'] = PHP_VERSION;
        $checks['Подключение БД'] = Env::get('DB_CONNECTION', 'sqlite');
        $checks['PDO-драйверы'] = implode(', ', $diag['pdo_drivers'] ?? []);
        $checks['APP_DEBUG'] = Env::get('APP_DEBUG', 'false');
        $checks['PHP SAPI'] = PHP_SAPI;
        $checks['PHP binary'] = PHP_BINARY;
        $checks['PATH'] = (string)getenv('PATH');
        $checks['open_basedir'] = (string)ini_get('open_basedir') ?: '—';
        $checks['disabled_functions'] = (string)ini_get('disable_functions') ?: '—';
        $checks['proc_open'] = function_exists('proc_open') ? 'доступен' : 'отключён';
        $checks['exec'] = function_exists('exec') ? 'доступен' : 'отключён';
        $checks['shell_exec'] = function_exists('shell_exec') ? 'доступен' : 'отключён';
        $checks['storage/logs доступен на запись'] = is_writable($root . '/storage/logs') ? 'да' : 'нет';
        $checks['UI_IP_ALLOWLIST'] = Env::get('UI_IP_ALLOWLIST', '') ?: 'не задан';
        $checks['Документация'] = $docsUrl;
        $checks['Статус Wiki'] = $docsStatus['label'];
        try {
            $stats = $this->repo()->stats();
            $checks['Логов всего'] = (string)$stats['total'];
            $checks['Последний лог'] = (string)($stats['last'] ?? '—');
        } catch (Throwable $e) {
            $checks['Ошибка БД'] = $e->getMessage();
        }
        $rows = '';
        foreach ($checks as $k => $v) {
            if ($k === 'Документация') {
                $rows .= '<tr><th>Документация</th><td><a href="' . Security::e((string)$v) . '" target="_blank" rel="noopener noreferrer">' . Security::e((string)$v) . '</a></td></tr>';
                continue;
            }
            $rows .= '<tr><th>' . Security::e((string)$k) . '</th><td>' . Security::e((string)$v) . '</td></tr>';
        }

        $readinessRows = '';
        foreach ($this->productionReadinessChecks() as $check) {
            $class = $check['ok'] ? 'status-ok' : 'status-warn';
            $readinessRows .= '<tr><td>' . Security::e($check['name']) . '</td><td><span class="status ' . $class . '">' . Security::e($check['ok'] ? 'готово' : 'проверить') . '</span></td><td>' . Security::e($check['message']) . '</td></tr>';
        }

        $ownershipRows = '';
        try {
            foreach ($this->repo()->dbOwnershipReport() as $row) {
                $ok = !isset($row['ok']) || $row['ok'] ? 'да' : 'нет';
                $ownershipRows .= '<tr><td>' . Security::e((string)$row['kind']) . '</td><td>' . Security::e((string)$row['name']) . '</td><td>' . Security::e((string)$row['owner']) . '</td><td>' . Security::e((string)$row['expected']) . '</td><td>' . Security::e($ok) . '</td></tr>';
            }
        } catch (Throwable $e) {
            $ownershipRows = '<tr><td colspan="5">' . Security::e($e->getMessage()) . '</td></tr>';
        }
        $body = '<h1>Состояние системы</h1><section class="panel"><h2>Проверки</h2><table class="detail-table"><tbody>' . $rows . '</tbody></table></section>'
            . '<section class="panel wide"><h2>Готовность к production</h2><table><thead><tr><th>Проверка</th><th>Статус</th><th>Комментарий</th></tr></thead><tbody>' . $readinessRows . '</tbody></table><p class="muted">Этот блок не заменяет аудит безопасности, но быстро показывает опасные значения по умолчанию.</p></section>'
            . '<section class="panel wide"><h2>Владельцы таблиц PostgreSQL</h2><table><thead><tr><th>Тип</th><th>Имя</th><th>Владелец</th><th>Ожидается</th><th>OK</th></tr></thead><tbody>' . $ownershipRows . '</tbody></table><p class="muted">Если OK=нет, запусти <code>php bin/db-doctor.php --sql</code> и выполни SQL от суперпользователя PostgreSQL.</p></section>';
        Response::html(View::layout('Система', $body));
    }

    /** @return array{ok:bool,label:string,code:int|null,error:string|null} */
    private function docsAvailability(string $url): array
    {
        if ($url === '') {
            return ['ok' => false, 'label' => 'DOCS_URL не задан', 'code' => null, 'error' => null];
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'label' => 'некорректный URL', 'code' => null, 'error' => null];
        }

        foreach (['HEAD', 'GET'] as $method) {
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'timeout' => 2,
                    'ignore_errors' => true,
                    'max_redirects' => 3,
                    'user_agent' => 'CajeerLogsSystemCheck/1.0',
                    'header' => "Accept: text/html,*/*;q=0.8\r\n",
                ],
            ]);
            $headers = @get_headers($url, true, $context);
            if ($headers === false || !isset($headers[0])) {
                continue;
            }
            $statusLine = is_array($headers[0]) ? (string)end($headers[0]) : (string)$headers[0];
            preg_match('/\s(\d{3})\s/', $statusLine, $m);
            $code = isset($m[1]) ? (int)$m[1] : null;
            $ok = $code !== null && $code >= 200 && $code < 400;
            return ['ok' => $ok, 'label' => ($ok ? 'доступна' : 'недоступна') . ($code ? ' HTTP ' . $code : ''), 'code' => $code, 'error' => null];
        }

        return ['ok' => false, 'label' => 'не проверена', 'code' => null, 'error' => 'network_check_failed'];
    }

    /** @return list<array{name:string,ok:bool,message:string}> */
    private function productionReadinessChecks(): array
    {
        $root = dirname(__DIR__, 2);
        $db = Env::get('DB_CONNECTION', 'sqlite');
        $docsUrl = Env::get('DOCS_URL', 'https://github.com/CajeerTeam/CajeerLogs/wiki') ?: '';
        $checks = [];
        $checks[] = ['name' => 'APP_DEBUG=false', 'ok' => !Env::bool('APP_DEBUG', false), 'message' => 'APP_DEBUG=' . Env::get('APP_DEBUG', '')];
        $checks[] = ['name' => 'DB_CONNECTION=pgsql', 'ok' => $db === 'pgsql', 'message' => 'Текущее значение: ' . (string)$db];
        $checks[] = ['name' => 'LOGS_ENV_FALLBACK_LOGIN=false', 'ok' => !Env::bool('LOGS_ENV_FALLBACK_LOGIN', false), 'message' => 'Аварийный вход должен быть выключен после создания администратора.'];
        $checks[] = ['name' => 'UPDATE_ALLOW_WEB=false', 'ok' => !Env::bool('UPDATE_ALLOW_WEB', false), 'message' => 'Web-обновление лучше держать выключенным в production.'];
        $checks[] = ['name' => 'INGEST_REQUIRE_SIGNATURE=true', 'ok' => Env::bool('INGEST_REQUIRE_SIGNATURE', true), 'message' => 'Для публичных сетей HMAC-подпись должна быть обязательной.'];
        $checks[] = ['name' => 'UI_IP_ALLOWLIST задан', 'ok' => trim((string)Env::get('UI_IP_ALLOWLIST', '')) !== '', 'message' => 'Ограничивает доступ к web-интерфейсу по IP/CIDR.'];
        $checks[] = ['name' => 'DOCS_URL задан', 'ok' => filter_var($docsUrl, FILTER_VALIDATE_URL) !== false, 'message' => $docsUrl ?: 'не задан'];
        $checks[] = ['name' => 'storage/logs доступен на запись', 'ok' => is_writable($root . '/storage/logs'), 'message' => $root . '/storage/logs'];
        $checks[] = ['name' => 'storage/cache доступен на запись', 'ok' => is_writable($root . '/storage/cache'), 'message' => $root . '/storage/cache'];
        $checks[] = ['name' => 'storage/archives доступен на запись', 'ok' => is_writable($root . '/storage/archives'), 'message' => $root . '/storage/archives'];
        $checks[] = ['name' => 'Webhook allowlist включён', 'ok' => Env::bool('ALERT_WEBHOOK_REQUIRE_ALLOWLIST', false) && trim((string)Env::get('ALERT_WEBHOOK_ALLOWED_HOSTS', '')) !== '', 'message' => 'Для production рекомендуется ALERT_WEBHOOK_REQUIRE_ALLOWLIST=true и явный ALERT_WEBHOOK_ALLOWED_HOSTS.'];
        $checks[] = ['name' => 'Update только по тегам', 'ok' => Env::bool('UPDATE_REQUIRE_TAG', true), 'message' => 'Production-обновления должны идти по release tag, а не по main.'];
        $checks[] = ['name' => 'Очередь задач обрабатывается', 'ok' => $this->cronTaskFresh('process-jobs', 900), 'message' => 'process-jobs.php должен запускаться cron-ом минимум раз в несколько минут.'];
        $checks[] = ['name' => 'Проверка релизной готовности доступна', 'ok' => is_file($root . '/bin/release-check.php'), 'message' => 'php bin/release-check.php'];
        return $checks;
    }

    private function cronTaskFresh(string $task, int $maxAgeSeconds): bool
    {
        try {
            $runs = $this->repo()->cronRuns(100);
            foreach ($runs as $run) {
                if ((string)($run['task'] ?? '') !== $task || (string)($run['status'] ?? '') !== 'ok') {
                    continue;
                }
                $finished = strtotime((string)($run['finished_at'] ?? $run['started_at'] ?? ''));
                return $finished !== false && (time() - $finished) <= $maxAgeSeconds;
            }
        } catch (Throwable) {
            return false;
        }
        return false;
    }

    private function pwaDiagnostics(): void
    {
        $this->repo()->audit('system.pwa_viewed', 'system', 'pwa', 'Открыта диагностика PWA');
        $root = dirname(__DIR__, 2);
        $public = $root . '/public';
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $checks = [
            'HTTPS' => $https ? 'да' : 'нет',
            'manifest.json' => is_file($public . '/manifest.json') ? 'да' : 'нет',
            'manifest.webmanifest' => is_file($public . '/manifest.webmanifest') ? 'да' : 'нет',
            'Сервис-воркер /sw.js' => is_file($public . '/sw.js') ? 'да' : 'нет',
            'icon-192.png' => is_file($public . '/assets/img/icon-192.png') ? 'да' : 'нет',
            'icon-512.png' => is_file($public . '/assets/img/icon-512.png') ? 'да' : 'нет',
            'robots.txt noindex' => is_file($public . '/robots.txt') && str_contains((string)file_get_contents($public . '/robots.txt'), 'Disallow: /') ? 'да' : 'нет',
        ];
        $rows = '';
        foreach ($checks as $name => $value) {
            $status = $value === 'да' ? 'status-ok' : 'status-warn';
            $rows .= '<tr><th>' . Security::e((string)$name) . '</th><td><span class="status ' . $status . '">' . Security::e((string)$value) . '</span></td></tr>';
        }
        $nginx = <<<'NGINX'
location = /manifest.webmanifest {
    default_type application/manifest+json;
    try_files $uri =404;
}

location = /manifest.json {
    default_type application/json;
    try_files $uri =404;
}

location = /sw.js {
    default_type application/javascript;
    add_header Cache-Control "no-cache, no-store, must-revalidate" always;
    try_files $uri =404;
}
NGINX;
        $body = '<h1>PWA / домашний экран</h1>'
            . '<section class="panel"><h2>Диагностика установки</h2><table class="detail-table"><tbody>' . $rows . '</tbody></table></section>'
            . '<section class="panel"><h2>Проверка в браузере</h2><div class="grid cards pwa-runtime-grid">'
            . '<div class="card"><div class="label">Режим отображения</div><div class="value" data-pwa-display-mode>проверяется</div></div>'
            . '<div class="card"><div class="label">Сервис-воркер</div><div class="value" data-pwa-sw-state>проверяется</div></div>'
            . '<div class="card"><div class="label">Предложение установки</div><div class="value" data-pwa-install-state>проверяется</div></div>'
            . '</div><p class="muted">Если Chrome пишет «Это приложение нельзя установить», проверьте manifest, иконки, HTTPS, MIME-типы и сервис-воркер. Firefox Android может создавать обычный ярлык даже при корректном manifest.</p></section>'
            . '<section class="panel"><h2>MIME Nginx</h2><p class="muted">Добавь эти location-блоки в server-блок logs.example.com, если Chrome не видит manifest или сервис-воркер.</p><pre>' . Security::e($nginx) . '</pre></section>'
            . '<section class="panel"><h2>Команды проверки</h2><pre>curl -k -I https://logs.example.com/manifest.json\ncurl -k -I https://logs.example.com/manifest.webmanifest\ncurl -k -I https://logs.example.com/sw.js\ncurl -k -I https://logs.example.com/assets/img/icon-192.png\ncurl -k -I https://logs.example.com/assets/img/icon-512.png</pre></section>';
        Response::html(View::layout('PWA / домашний экран', $body));
    }


    private function healthCron(): void
    {
        $tasks = [
            'process-jobs' => 900,
            'alert-dispatch' => 900,
            'import-aapanel-logs' => 3600,
            'retention' => 90000,
        ];
        $runs = [];
        try {
            foreach ($this->repo()->cronRuns(500) as $run) {
                $task = (string)$run['task'];
                if (!isset($runs[$task]) && (string)$run['status'] === 'ok') {
                    $ts = strtotime((string)($run['finished_at'] ?? $run['started_at'] ?? ''));
                    $runs[$task] = [
                        'last_ok' => (string)($run['finished_at'] ?? $run['started_at'] ?? ''),
                        'age_seconds' => $ts !== false ? max(0, time() - $ts) : null,
                        'message' => (string)($run['message'] ?? ''),
                    ];
                }
            }
            $payload = ['ok' => true, 'tasks' => []];
            foreach ($tasks as $task => $maxAge) {
                $row = $runs[$task] ?? ['last_ok' => null, 'age_seconds' => null, 'message' => 'нет успешных запусков'];
                $fresh = is_int($row['age_seconds']) && $row['age_seconds'] <= $maxAge;
                $payload['tasks'][$task] = array_merge($row, ['fresh' => $fresh, 'max_age_seconds' => $maxAge]);
                if (!$fresh && in_array($task, ['process-jobs', 'alert-dispatch'], true)) {
                    $payload['ok'] = false;
                }
            }
            Response::json($payload, $payload['ok'] ? 200 : 503);
        } catch (Throwable $e) {
            Response::json(['ok' => false, 'error' => 'cron_health_failed', 'message' => $e->getMessage()], 500);
        }
    }

    private function runtimeDiagnostics(): void
    {
        $runtime = RuntimeDiagnostics::phpRuntime(Env::get('UPDATE_PHP_BIN', ''));
        $db = RuntimeDiagnostics::databaseProbe();
        $rows = '';
        foreach ($runtime as $label => $value) {
            $rows .= '<tr><th>' . Security::e((string)$label) . '</th><td><code>' . Security::e((string)$value) . '</code></td></tr>';
        }
        $lockViolations = RuntimeDiagnostics::productionLockViolations();
        $lockHtml = $lockViolations
            ? '<div class="notice danger"><strong>Production lock: проблема.</strong> ' . Security::e(implode(' ', $lockViolations)) . '</div>'
            : '<div class="notice success">Production lock: критичных нарушений не найдено.</div>';
        $dbHtml = '<tr><th>База данных</th><td><span class="status ' . ($db['ok'] ? 'status-ok' : 'status-error') . '">' . Security::e($db['ok'] ? 'OK' : 'Проблема') . '</span> <code>' . Security::e((string)$db['driver']) . '</code> ' . Security::e((string)$db['message']) . '</td></tr>';
        $body = <<<HTML
<h1>Runtime-диагностика</h1>
{$lockHtml}
<section class="panel wide">
    <h2>PHP / FPM / CLI</h2>
    <table class="detail-table"><tbody>{$rows}{$dbHtml}</tbody></table>
</section>
<section class="panel">
    <h2>OPcache</h2>
    <p class="muted">Если после git reset сайт показывает старый код, перезапусти PHP-FPM. Для aaPanel PHP 8.3 обычно подходит команда:</p>
    <pre>/etc/init.d/php-fpm-83 restart</pre>
</section>
HTML;
        Response::html(View::layout('Runtime-диагностика', $body));
    }

    private function jobs(array $errors = [], ?string $message = null): void
    {
        $repo = $this->repo();
        $csrf = Security::csrfToken('job_action');
        $notice = $message ? '<div class="notice success">' . Security::e($message) . '</div>' : '';
        if ($errors) {
            $notice = '<div class="notice danger">' . Security::e(implode(' ', $errors)) . '</div>';
        }
        $stats = $repo->jobStats();
        $cards = '';
        foreach ($stats as $status => $count) {
            $cards .= '<div class="card"><div class="label">' . Security::e($this->jobStatusLabel((string)$status)) . '</div><div class="value">' . Security::e((string)$count) . '</div></div>';
        }
        $rows = '';
        foreach ($repo->jobs(150) as $job) {
            $payload = $this->prettyJson((string)($job['payload'] ?? ''));
            $error = (string)($job['error'] ?? '');
            $rows .= '<tr>'
                . '<td>' . Security::e((string)$job['id']) . '</td>'
                . '<td>' . Security::e((string)$job['type']) . '</td>'
                . '<td>' . Security::e($this->jobStatusLabel((string)$job['status'])) . '</td>'
                . '<td>' . Security::e((string)($job['attempts'] ?? 0)) . '/' . Security::e((string)($job['max_attempts'] ?? 0)) . '</td>'
                . '<td>' . Security::e((string)($job['run_after_at'] ?? '—')) . '</td>'
                . '<td>' . Security::e((string)($job['locked_by'] ?? '—')) . '</td>'
                . '<td>' . Security::e($error !== '' ? $error : '—') . '</td>'
                . '<td><details><summary>payload</summary><pre>' . Security::e($payload) . '</pre></details></td>'
                . '<td><form method="post" action="/system/jobs/action"><input type="hidden" name="_csrf" value="' . Security::e($csrf) . '"><input type="hidden" name="id" value="' . Security::e((string)$job['id']) . '"><button class="button small ghost" name="action" value="retry">Повторить</button><button class="button small danger" name="action" value="cancel">Отменить</button></form></td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="9" class="muted">Очередь задач пуста.</td></tr>';
        }
        $body = <<<HTML
<h1>Очередь задач</h1>
{$notice}
<section class="grid cards">{$cards}</section>
<section class="panel wide">
    <h2>Задачи</h2>
    <table><thead><tr><th>ID</th><th>Тип</th><th>Статус</th><th>Попытки</th><th>Запуск после</th><th>Владелец</th><th>Ошибка</th><th>Payload</th><th>Действия</th></tr></thead><tbody>{$rows}</tbody></table>
</section>
<section class="panel"><h2>Обработка</h2><pre>cd /www/wwwroot/logs.example.com && /www/server/php/83/bin/php bin/process-jobs.php</pre></section>
HTML;
        Response::html(View::layout('Очередь задач', $body));
    }

    private function jobAction(): void
    {
        if (!Security::verifyCsrfToken('job_action', (string)($_POST['_csrf'] ?? ''))) {
            Response::redirect('/system/jobs');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($id <= 0) {
                throw new \InvalidArgumentException('Не указан ID задачи.');
            }
            if ($action === 'retry') {
                $this->repo()->resetJob($id);
                $this->repo()->audit('job.retry', 'job', (string)$id, 'Задача возвращена в очередь');
                $this->jobs([], 'Задача возвращена в очередь.');
                return;
            }
            if ($action === 'cancel') {
                $this->repo()->cancelJob($id);
                $this->repo()->audit('job.cancel', 'job', (string)$id, 'Задача отменена');
                $this->jobs([], 'Задача отменена.');
                return;
            }
            throw new \InvalidArgumentException('Неизвестное действие.');
        } catch (Throwable $e) {
            $this->jobs([$e->getMessage()]);
        }
    }

    private function cron(): void
    {
        $rows = '';
        foreach ($this->repo()->cronRuns(200) as $r) {
            $rows .= '<tr><td>' . Security::e((string)$r['started_at']) . '</td><td>' . Security::e((string)$r['task']) . '</td><td>' . Security::e((string)$r['status']) . '</td><td>' . Security::e((string)$r['duration_ms']) . '</td><td>' . Security::e((string)$r['message']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="muted">Запусков cron пока нет.</td></tr>';
        }
        $jobRows = '';
        foreach ($this->repo()->jobs(100) as $job) {
            $jobRows .= '<tr><td>' . Security::e((string)$job['id']) . '</td><td>' . Security::e((string)$job['type']) . '</td><td>' . Security::e((string)$job['status']) . '</td><td>' . Security::e((string)$job['created_at']) . '</td><td>' . Security::e((string)($job['error'] ?? '')) . '</td></tr>';
        }
        if ($jobRows === '') { $jobRows = '<tr><td colspan="5" class="muted">Задач в очереди нет.</td></tr>'; }
        $body = '<h1>Планировщик</h1><section class="panel wide"><h2>Последние запуски</h2><table><thead><tr><th>Старт</th><th>Задача</th><th>Статус</th><th>мс</th><th>Сообщение</th></tr></thead><tbody>' . $rows . '</tbody></table></section>'
            . '<section class="panel"><h2>Рекомендуемые задачи</h2><pre>cd /www/wwwroot/logs.example.com && /www/server/php/83/bin/php bin/alert-dispatch.php\ncd /www/wwwroot/logs.example.com && /www/server/php/83/bin/php bin/process-jobs.php\ncd /www/wwwroot/logs.example.com && /www/server/php/83/bin/php bin/import-aapanel-logs.php --max-lines=1000\ncd /www/wwwroot/logs.example.com && /www/server/php/83/bin/php bin/retention.php</pre></section>';
        Response::html(View::layout('Планировщик', $body));
    }

    private function errors(): void
    {
        $rows = '';
        foreach ($this->repo()->errorsOverview() as $e) {
            $rows .= '<tr><td>' . Security::e((string)$e['cnt']) . '</td><td>' . Security::e((string)$e['level']) . '</td><td>' . Security::e((string)$e['project']) . '</td><td>' . Security::e((string)$e['bot']) . '</td><td>' . Security::e((string)$e['last_at']) . '</td><td><a href="/logs?fingerprint=' . Security::e((string)$e['fingerprint']) . '">' . Security::e((string)$e['sample_message']) . '</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="muted">Ошибок за 24 часа нет.</td></tr>';
        }
        $body = '<h1>Обзор ошибок</h1><section class="panel wide"><table><thead><tr><th>Кол-во</th><th>Уровень</th><th>Проект</th><th>Бот</th><th>Последняя</th><th>Пример</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
        Response::html(View::layout('Ошибки', $body));
    }

    private function savedViews(array $errors = [], ?string $message = null): void
    {
        $rows = '';
        $csrf = Security::csrfToken('saved_view_action');
        foreach ($this->repo()->savedViews() as $v) {
            $url = Security::safeInternalPath((string)$v['route'], '/logs') . ((string)$v['query'] !== '' ? '?' . (string)$v['query'] : '');
            $rows .= '<tr><td><a href="' . Security::e($url) . '">' . Security::e((string)$v['name']) . '</a></td><td><code>' . Security::e($url) . '</code></td><td>' . Security::e((string)$v['created_by']) . '</td><td><form method="post" action="/saved-views/action"><input type="hidden" name="_csrf" value="' . Security::e($csrf) . '"><input type="hidden" name="id" value="' . Security::e((string)$v['id']) . '"><button class="button small danger" name="action" value="delete">Удалить</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">Сохранённых представлений пока нет.</td></tr>';
        }
        $notice = $errors ? '<div class="notice danger">' . Security::e(implode(' ', $errors)) . '</div>' : ($message ? '<div class="notice success">' . Security::e($message) . '</div>' : '');
        $createCsrf = Security::csrfToken('create_saved_view');
        $currentQuery = Security::e((string)($_SERVER['QUERY_STRING'] ?? ''));
        $body = <<<HTML
<h1>Сохранённые представления</h1>
{$notice}
<section class="panel">
    <h2>Добавить представление</h2>
    <form class="form-grid" method="post" action="/saved-views">
        <input type="hidden" name="_csrf" value="{$createCsrf}">
        <label>Название<input name="name" required maxlength="160" placeholder="Ошибки ExampleBot"></label>
        <label>Маршрут<input name="route" value="/logs" required></label>
        <label class="full">Строка запроса<input name="query" value="{$currentQuery}" placeholder="level=ERROR&bot=ExampleBot&range=24h"></label>
        <div class="form-actions full"><button type="submit">Сохранить</button></div>
    </form>
</section>
<section class="panel wide"><table><thead><tr><th>Название</th><th>URL</th><th>Автор</th><th></th></tr></thead><tbody>{$rows}</tbody></table></section>
HTML;
        Response::html(View::layout('Сохранённые представления', $body));
    }

    private function createSavedView(): void
    {
        if (!Security::verifyCsrfToken('create_saved_view', (string)($_POST['_csrf'] ?? ''))) {
            $this->savedViews(['Срок действия формы истёк.']);
            return;
        }
        $name = trim((string)($_POST['name'] ?? ''));
        $route = trim((string)($_POST['route'] ?? '/logs'));
        $query = ltrim(trim((string)($_POST['query'] ?? '')), '?');
        if ($name === '' || !Security::isSafeInternalPath($route)) {
            $this->savedViews(['Заполни название и безопасный внутренний маршрут.']);
            return;
        }
        $route = Security::safeInternalPath($route, '/logs');
        $id = $this->repo()->createSavedView($name, $route, $query);
        $this->repo()->audit('saved_view.created', 'saved_view', (string)$id, 'Создано сохранённое представление', ['name' => $name]);
        $this->savedViews([], 'Представление сохранено.');
    }

    private function savedViewAction(): void
    {
        if (Security::verifyCsrfToken('saved_view_action', (string)($_POST['_csrf'] ?? ''))) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0 && (string)($_POST['action'] ?? '') === 'delete') {
                $this->repo()->deleteSavedView($id);
                $this->repo()->audit('saved_view.deleted', 'saved_view', (string)$id, 'Удалено сохранённое представление');
            }
        }
        Response::redirect('/saved-views');
    }

    private function siteDetail(string $site): void
    {
        $data = $this->repo()->siteDetailStats($site);
        $tableForMap = function (array $map, string $label): string {
            $rows = '';
            foreach ($map as $key => $cnt) {
                $rows .= '<tr><td>' . Security::e((string)$key) . '</td><td>' . Security::e((string)$cnt) . '</td></tr>';
            }
            if ($rows === '') { $rows = '<tr><td colspan="2" class="muted">Нет данных</td></tr>'; }
            return '<section class="panel"><h2>' . Security::e($label) . '</h2><table><thead><tr><th>Значение</th><th>Кол-во</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
        };
        $body = '<h1>Сайт ' . Security::e($site) . '</h1><div class="quick-actions"><a class="button ghost" href="/sites">← Все сайты</a><a class="button ghost" href="/logs?project=Web+Sites&bot=' . Security::e($site) . '">Открыть все логи</a></div>'
            . $tableForMap($data['top404'], 'Топ 404')
            . $tableForMap($data['top500'], 'Топ 5xx')
            . $tableForMap($data['topIp'], 'Топ IP')
            . $tableForMap($data['topUa'], 'Топ User-Agent')
            . '<section class="panel wide"><h2>Последние события</h2><table><thead><tr><th>Время</th><th>Уровень</th><th>Проект</th><th>Бот</th><th>Сообщение</th><th></th></tr></thead><tbody>' . $this->renderLogRows($data['recent']) . '</tbody></table></section>';
        Response::html(View::layout('Сайт ' . $site, $body));
    }

    private function exportLogs(): void
    {
        $filters = $this->logFiltersFromQuery();
        $format = strtolower((string)($_GET['format'] ?? 'csv'));
        $logs = $this->repo()->recentLogs($filters, Env::int('LOGS_EXPORT_MAX_ROWS', 5000), 0);
        $this->repo()->audit('logs.exported', 'log_export', $format, 'Экспортированы журналы', ['format' => $format, 'filters' => $filters, 'rows' => count($logs)]);
        $filename = 'cajeer-logs-' . gmdate('Ymd-His') . '.' . ($format === 'ndjson' ? 'ndjson' : $format);
        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo json_encode(['logs' => $logs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            return;
        }
        if ($format === 'ndjson') {
            header('Content-Type: application/x-ndjson; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            foreach ($logs as $row) {
                echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            return;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'wb');
        fputcsv($out, ['id', 'created_at', 'received_at', 'level', 'project', 'bot', 'environment', 'version', 'host', 'logger', 'message', 'exception', 'trace_id']);
        foreach ($logs as $row) {
            fputcsv($out, [$row['id'], $row['created_at'], $row['received_at'], $row['level'], $row['project'], $row['bot'], $row['environment'], $row['version'], $row['host'], $row['logger'], $row['message'], $row['exception'], $row['trace_id']]);
        }
    }

    private function validateBotForm(array $form): array
    {
        $errors = [];
        if ($form['project'] === '') {
            $errors[] = 'Поле «Проект» обязательно.';
        }
        if ($form['bot'] === '') {
            $errors[] = 'Поле «Бот» обязательно.';
        }
        if ($form['environment'] === '') {
            $errors[] = 'Поле «Окружение» обязательно.';
        }
        if (strlen($form['project']) > 120 || strlen($form['bot']) > 120 || strlen($form['environment']) > 60) {
            $errors[] = 'Слишком длинное значение проекта, бота или окружения.';
        }
        if (strlen($form['description']) > 500) {
            $errors[] = 'Описание не должно быть длиннее 500 символов.';
        }
        $rate = (int)$form['rate_limit_per_minute'];
        if ($rate < 0 || $rate > 10000) {
            $errors[] = 'Лимит запросов должен быть от 0 до 10000.';
        }
        $batch = (int)$form['max_batch_size'];
        if ($batch < 1 || $batch > 500) {
            $errors[] = 'Максимальный размер пачки должен быть от 1 до 500.';
        }
        $eventsLimit = (int)$form['events_limit_per_minute'];
        if ($eventsLimit < 0 || $eventsLimit > 1000000) {
            $errors[] = 'Лимит событий должен быть от 0 до 1000000.';
        }
        $bytesLimit = (int)$form['bytes_limit_per_minute'];
        if ($bytesLimit < 0 || $bytesLimit > 104857600) {
            $errors[] = 'Лимит байт должен быть от 0 до 104857600.';
        }
        return $errors;
    }

    private function renderBotActions(int $id, bool $active, string $csrf): string
    {
        $idEsc = Security::e((string)$id);
        $csrfEsc = Security::e($csrf);
        $toggle = $active ? ['disable', 'Отключить'] : ['enable', 'Включить'];
        return '<div class="action-stack">'
            . '<form method="post" action="/bots/action"><input type="hidden" name="_csrf" value="' . $csrfEsc . '"><input type="hidden" name="id" value="' . $idEsc . '"><input type="hidden" name="action" value="' . $toggle[0] . '"><button class="button small ghost" type="submit">' . Security::e($toggle[1]) . '</button></form>'
            . '<form method="post" action="/bots/action" data-confirm="Перевыпустить токен? Старый токен перестанет работать."><input type="hidden" name="_csrf" value="' . $csrfEsc . '"><input type="hidden" name="id" value="' . $idEsc . '"><input type="hidden" name="action" value="rotate"><button class="button small" type="submit">Перевыпустить</button></form>'
            . '<form method="post" action="/bots/action" data-confirm="Удалить токен? Действие отключит приём логов по этому токену."><input type="hidden" name="_csrf" value="' . $csrfEsc . '"><input type="hidden" name="id" value="' . $idEsc . '"><input type="hidden" name="action" value="delete"><button class="button small danger" type="submit">Удалить</button></form>'
            . '</div>';
    }

    private function renderCreatedTokenEnv(array $created): string
    {
        $url = rtrim(Env::get('APP_URL', 'https://logs.example.com'), '/') . '/api/v1/ingest';
        $lines = [
            'REMOTE_LOGS_ENABLED=true',
            'REMOTE_LOGS_URL=' . $url,
            'REMOTE_LOGS_TOKEN=' . (string)$created['raw_token'],
            'REMOTE_LOGS_PROJECT=' . (string)$created['project'],
            'REMOTE_LOGS_BOT=' . (string)$created['bot'],
            'REMOTE_LOGS_ENVIRONMENT=' . (string)$created['environment'],
            'REMOTE_LOGS_LEVEL=INFO',
            'REMOTE_LOGS_BATCH_SIZE=25',
            'REMOTE_LOGS_FLUSH_INTERVAL=5',
        ];
        if (!empty($created['require_signature'])) {
            $lines[] = 'REMOTE_LOGS_SIGN_REQUESTS=true';
        }
        return Security::e(implode("\n", $lines));
    }


    private function renderCreatedTokenPythonSnippet(array $created): string
    {
        $code = <<<'PY'
import logging
import os
from remote_log_handler import RemoteLogHandler

def setup_remote_logs() -> None:
    if os.getenv("REMOTE_LOGS_ENABLED", "false").lower() not in {"1", "true", "yes", "on"}:
        return
    logging.getLogger().addHandler(RemoteLogHandler())

logging.basicConfig(level=logging.INFO)
setup_remote_logs()
PY;
        return Security::e($code);
    }

    private function renderCreatedTokenCurl(array $created): string
    {
        $url = rtrim(Env::get('APP_URL', 'https://logs.example.com'), '/') . '/api/v1/ingest';
        $payload = json_encode([
            'logs' => [[
                'level' => 'INFO',
                'project' => (string)$created['project'],
                'bot' => (string)$created['bot'],
                'environment' => (string)$created['environment'],
                'message' => 'Проверочный лог из интерфейса logs.example.com',
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cmd = "curl -X POST " . $url . " \\\n  -H 'Content-Type: application/json' \\\n  -H 'X-Log-Token: " . (string)$created['raw_token'] . "' \\\n  -d '" . $payload . "'";
        return Security::e($cmd);
    }

    private function renderLogRows(array $logs, bool $extended = false): string
    {
        if (!$logs) {
            $colspan = $extended ? 9 : 6;
            return '<tr><td colspan="' . $colspan . '" class="muted">Записей нет</td></tr>';
        }
        $rows = '';
        foreach ($logs as $log) {
            $levelClass = 'level level-' . strtolower((string)$log['level']);
            $level = Security::e($this->levelLabel((string)$log['level']));
            $detail = '<a class="button small ghost" href="/logs/' . Security::e((string)$log['id']) . '">Открыть</a>';
            if ($extended) {
                $exception = $log['exception'] ? '<details><summary>исключение</summary><pre>' . Security::e($log['exception']) . '</pre></details>' : '';
                $trace = (string)($log['trace_id'] ?? '');
                $traceHtml = $trace !== '' ? '<a href="/logs?trace_id=' . Security::e($trace) . '">' . Security::e($trace) . '</a>' : '—';
                $rows .= '<tr><td data-label="Время">' . Security::e((string)$log['created_at']) . '</td><td data-label="Уровень"><span class="' . $levelClass . '">' . $level . '</span></td><td data-label="Проект">' . Security::e($log['project']) . '</td><td data-label="Бот">' . Security::e($log['bot']) . '</td><td data-label="Окружение">' . Security::e($log['environment']) . '</td><td data-label="Источник">' . Security::e((string)$log['logger']) . '</td><td data-label="Сообщение"><div class="msg">' . Security::e($log['message']) . '</div>' . $exception . '</td><td data-label="ID трассировки">' . $traceHtml . '</td><td data-label="Действие">' . $detail . '</td></tr>';
            } else {
                $rows .= '<tr><td data-label="Время">' . Security::e((string)$log['created_at']) . '</td><td data-label="Уровень"><span class="' . $levelClass . '">' . $level . '</span></td><td data-label="Проект">' . Security::e($log['project']) . '</td><td data-label="Бот">' . Security::e($log['bot']) . '</td><td data-label="Сообщение">' . Security::e($log['message']) . '</td><td data-label="Действие">' . $detail . '</td></tr>';
            }
        }
        return $rows;
    }

    private function logFiltersFromQuery(): array
    {
        $filters = [
            'project' => $_GET['project'] ?? '',
            'bot' => $_GET['bot'] ?? '',
            'environment' => $_GET['environment'] ?? '',
            'level' => $_GET['level'] ?? '',
            'trace_id' => $_GET['trace_id'] ?? '',
            'fingerprint' => $_GET['fingerprint'] ?? '',
            'q' => $_GET['q'] ?? '',
            'from' => $_GET['from'] ?? '',
            'to' => $_GET['to'] ?? '',
        ];
        $range = (string)($_GET['range'] ?? '');
        if ($range !== '' && $filters['from'] === '') {
            $seconds = match ($range) {
                '15m' => 900,
                '1h' => 3600,
                '24h' => 86400,
                '7d' => 604800,
                default => 0,
            };
            if ($seconds > 0) {
                $filters['from'] = gmdate('Y-m-d H:i:s', time() - $seconds);
            }
        }
        return array_map(static fn($v) => is_string($v) ? trim($v) : $v, $filters);
    }

    private function renderLogFilters(array $filters, array $options): string
    {
        $html = '';
        $html .= '<label>Проект' . $this->select('project', $options['projects'] ?? [], (string)$filters['project']) . '</label>';
        $html .= '<label>Бот' . $this->select('bot', $options['bots'] ?? [], (string)$filters['bot']) . '</label>';
        $html .= '<label>Окружение' . $this->select('environment', $options['environments'] ?? [], (string)$filters['environment']) . '</label>';
        $html .= '<label>Уровень' . $this->select('level', array_merge(['TRACE','DEBUG','INFO','WARNING','ERROR','CRITICAL','AUDIT','SECURITY'], $options['levels'] ?? []), (string)$filters['level']) . '</label>';
        $html .= '<label>ID трассировки<input name="trace_id" value="' . Security::e((string)$filters['trace_id']) . '"></label>';
        $html .= '<label>Отпечаток группы<input name="fingerprint" value="' . Security::e((string)$filters['fingerprint']) . '"></label>';
        $html .= '<label>Поиск<input name="q" value="' . Security::e((string)$filters['q']) . '"></label>';
        $html .= '<label>С даты<input name="from" value="' . Security::e((string)$filters['from']) . '" placeholder="YYYY-MM-DD HH:MM:SS"></label>';
        $html .= '<label>По дату<input name="to" value="' . Security::e((string)$filters['to']) . '" placeholder="YYYY-MM-DD HH:MM:SS"></label>';
        return $html;
    }

    private function select(string $name, array $values, string $selected): string
    {
        $seen = [];
        $html = '<select name="' . Security::e($name) . '"><option value="">Все</option>';
        foreach ($values as $value) {
            $value = (string)$value;
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $sel = $value === $selected ? ' selected' : '';
            $label = $name === 'level' ? $this->levelLabel($value) : $value;
            $html .= '<option value="' . Security::e($value) . '"' . $sel . '>' . Security::e($label) . '</option>';
        }
        return $html . '</select>';
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' сек.';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' мин.';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' ч. ' . intdiv($seconds % 3600, 60) . ' мин.';
        }
        return intdiv($seconds, 86400) . ' д. ' . intdiv($seconds % 86400, 3600) . ' ч.';
    }

    private function prettyJson(string $json): string
    {
        if (trim($json) === '') {
            return '—';
        }
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: $json;
    }


    private function incidentStatusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'открыт',
            'acknowledged' => 'принят в работу',
            'resolved' => 'решён',
            default => $status,
        };
    }

    private function deliveryStatusLabel(string $status): string
    {
        return match ($status) {
            'sent' => 'отправлено',
            'failed' => 'ошибка',
            'test_sent' => 'тест отправлен',
            'test_failed' => 'ошибка теста',
            'muted' => 'заглушено',
            'deduplicated' => 'дедупликация',
            'queued' => 'в очереди',
            'replay_sent' => 'повтор отправлен',
            'replay_failed' => 'ошибка повтора',
            default => $status,
        };
    }

    private function jobStatusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'в очереди',
            'retry' => 'ожидает повтора',
            'running' => 'выполняется',
            'done' => 'выполнено',
            'failed' => 'ошибка',
            'dead' => 'dead-letter',
            'cancelled' => 'отменено',
            default => $status,
        };
    }

    private function levelLabel(string $level): string
    {
        return match (strtoupper($level)) {
            'TRACE' => 'Трассировка',
            'DEBUG' => 'Отладка',
            'INFO' => 'Информация',
            'WARNING', 'WARN' => 'Предупреждение',
            'ERROR' => 'Ошибка',
            'CRITICAL' => 'Критическая ошибка',
            'AUDIT' => 'Аудит',
            'SECURITY' => 'Безопасность',
            default => $level,
        };
    }

    private function wantsJson(string $path): bool
    {
        if ($path === '/health' || $path === '/health/cron' || str_starts_with($path, '/api/')) {
            return true;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return is_string($accept) && str_contains($accept, 'application/json');
    }

    private function renderSetupError(Throwable $e): void
    {
        $debug = Env::bool('APP_DEBUG', false);
        $message = $debug ? $e->getMessage() : 'Приложение не смогло подключиться к базе данных или прочитать схему.';
        $diag = Database::diagnostics();
        $docsUrlEsc = Security::e(Env::get('DOCS_URL', 'https://github.com/CajeerTeam/CajeerLogs/wiki') ?: 'https://github.com/CajeerTeam/CajeerLogs/wiki');
        $diagRows = '';
        foreach ($diag as $key => $value) {
            if (is_array($value)) {
                $value = $value ? implode(', ', $value) : 'нет';
            }
            if (is_bool($value)) {
                $value = $value ? 'да' : 'нет';
            }
            $diagRows .= '<tr><td>' . Security::e((string)$key) . '</td><td>' . Security::e((string)$value) . '</td></tr>';
        }

        $safeMessage = Security::e($message);
        $body = <<<HTML
<h1>Ошибка настройки</h1>
<section class="panel">
    <p>Приложение запустилось, но не смогло открыть рабочую страницу.</p>
    <p><strong>Причина:</strong> {$safeMessage}</p>
    <p>Проверь <code>/www/wwwroot/logs.example.com/.env</code>, наличие PHP-расширения <code>pdo_pgsql</code>, доступность PostgreSQL и выполнены ли миграции.</p>
    <p>Документация: <a href="{$docsUrlEsc}" target="_blank" rel="noopener noreferrer">{$docsUrlEsc}</a></p>
    <pre>cd /www/wwwroot/logs.example.com
php bin/health.php
php bin/migrate.php</pre>
</section>
<section class="panel">
    <h2>Диагностика</h2>
    <table><thead><tr><th>Параметр</th><th>Значение</th></tr></thead><tbody>{$diagRows}</tbody></table>
</section>
<section class="panel">
    <h2>Журналы</h2>
    <pre>tail -n 80 /www/wwwroot/logs.example.com/storage/logs/app.log
tail -n 80 /www/wwwlogs/logs.example.com.error.log</pre>
</section>
HTML;
        Response::html(View::layout('Ошибка настройки', $body), 200);
    }
}
