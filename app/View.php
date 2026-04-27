<?php
declare(strict_types=1);

namespace CajeerLogs;

final class View
{
    public static function layout(string $title, string $body): string
    {
        $titleEsc = Security::e($title);
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $nav = [
            '/' => ['Панель', 'logs.view'],
            '/logs' => ['Журналы', 'logs.view'],
            '/errors' => ['Ошибки', 'logs.view'],
            '/saved-views' => ['Представления', 'logs.view'],
            '/bots' => ['Боты', 'bots.view'],
            '/bots/health' => ['Здоровье ботов', 'bots.view'],
            '/sites' => ['Сайты', 'sites.view'],
            '/incidents' => ['Инциденты', 'incidents.view'],
            '/alerts' => ['Оповещения', 'alerts.view'],
            '/audit' => ['Аудит', 'audit.view'],
            '/users' => ['Пользователи', 'users.manage'],
            '/cron' => ['Планировщик', 'cron.view'],
            '/system' => ['Система', 'system.view'],
            '/system/update' => ['Обновление', 'update.manage'],
        ];
        $navHtml = '';
        $bottomNavHtml = '';
        $bottomNav = [
            '/' => ['Панель', '⌂', 'logs.view'],
            '/logs' => ['Логи', '☰', 'logs.view'],
            '/bots/health' => ['Боты', '●', 'bots.view'],
            '/sites' => ['Сайты', '◆', 'sites.view'],
            '/system' => ['Система', '⚙', 'system.view'],
        ];
        foreach ($nav as $href => [$label, $permission]) {
            if (!Auth::user() || !Auth::can($permission)) {
                continue;
            }
            $active = $path === $href || ($href !== '/' && str_starts_with($path, $href . '/'));
            $class = $active ? ' class="is-active"' : '';
            $aria = $active ? ' aria-current="page"' : '';
            $navHtml .= '<a' . $class . $aria . ' href="' . Security::e($href) . '">' . Security::e($label) . '</a>';
        }
        foreach ($bottomNav as $href => [$label, $icon, $permission]) {
            if (!Auth::user() || !Auth::can($permission)) {
                continue;
            }
            $active = $path === $href || ($href !== '/' && str_starts_with($path, $href . '/'));
            $class = $active ? ' class="is-active"' : '';
            $aria = $active ? ' aria-current="page"' : '';
            $bottomNavHtml .= '<a' . $class . $aria . ' href="' . Security::e($href) . '"><span class="bottom-nav-icon" aria-hidden="true">' . Security::e($icon) . '</span><span>' . Security::e($label) . '</span></a>';
        }
        if (Auth::user()) {
            $navHtml .= '<form class="nav-logout" method="post" action="/logout"><input type="hidden" name="_csrf" value="' . Security::e(Security::csrfToken('logout')) . '"><button type="submit">Выйти</button></form>';
        }
        if ($navHtml !== '') {
            $navHtml = '<div class="mobile-nav-head"><span>Навигация</span><button type="button" data-nav-close>Закрыть</button></div>' . $navHtml;
        }
        $navToggleHtml = $navHtml ? '<button class="nav-toggle" type="button" aria-controls="site-nav" aria-expanded="false" data-nav-toggle>Меню</button>' : '';
        $emergencyBanner = Auth::isEmergencyUser()
            ? '<div class="notice warn"><strong>Аварийный вход через .env.</strong> Создайте администратора в БД и отключите LOGS_ENV_FALLBACK_LOGIN.</div>'
            : '';
        $bottomNavBlock = $bottomNavHtml !== '' ? '<nav class="mobile-bottom-nav" aria-label="Быстрая навигация">' . $bottomNavHtml . '</nav>' : '';

        return <<<HTML
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{$titleEsc} — Журналы Cajeer</title>
    <meta name="description" content="Внутренний сервис Cajeer для централизованного хранения и анализа логов ботов.">
    <meta name="theme-color" content="#0b0f19">
    <meta name="color-scheme" content="dark">
    <meta name="application-name" content="Cajeer Logs">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Cajeer Logs">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=no">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
    <link rel="apple-touch-icon" sizes="192x192" href="/assets/img/icon-192.png">
    <link rel="stylesheet" href="/assets/css/app.css?v=20260427-mobile-ui">
    <script defer src="/assets/js/app.js?v=20260427-mobile-ui"></script>
</head>
<body>
<a class="skip-link" href="#main-content">Перейти к содержимому</a>
<div id="pwa-update-banner" class="pwa-update-banner" hidden>
    <span>Доступна новая версия интерфейса.</span>
    <button type="button" data-pwa-reload>Обновить</button>
</div>
<div class="site-shell">
<header class="site-header">
    <div class="container header-row">
        <a class="brand-mark" href="/" aria-label="Журналы Cajeer">
            <img src="/assets/img/logo.png" alt="Логотип Cajeer">
            <span class="brand-full">Журналы Cajeer</span><span class="brand-short">Logs</span>
        </a>
        <div class="header-actions">
            <button class="pwa-install-button" type="button" data-pwa-install hidden>Установить</button>
            <button class="command-button" type="button" data-command-open><span class="desktop-label">Ctrl+K</span><span class="mobile-label">Поиск</span></button>
            {$navToggleHtml}
        </div>
        <nav id="site-nav" class="site-nav" aria-label="Основная навигация">
            {$navHtml}
        </nav>
    </div>
</header>
<div class="nav-backdrop" data-nav-close hidden></div>
<main id="main-content" class="site-main">
    <div class="container app-container">
        <section class="hero-panel">
            <p class="eyebrow">Внутренний контур наблюдаемости</p>
            <h1>{$titleEsc}</h1>
            <p class="lead">Централизованный приём, хранение и анализ событий от ботов и сервисов Cajeer.</p>
        </section>
        {$emergencyBanner}
        {$body}
    </div>
</main>
<footer class="site-footer">
    <div class="container footer-grid">
        <div>
            <div class="footer-title">Cajeer</div>
            <p class="footer-text">Журналы, диагностика и операционный контроль ботов.</p>
        </div>
        <div>
            <div class="footer-title">Разделы</div>
            <ul class="footer-links">
                <li><a href="/">Панель</a></li>
                <li><a href="/logs">Журналы</a></li>
                <li><a href="/bots">Боты</a></li>
                <li><a href="/sites">Сайты aaPanel</a></li>
            </ul>
        </div>
        <div>
            <div class="footer-title">Контур</div>
            <p class="footer-text">Внутренний сервис Cajeer для ботов, сайтов и инфраструктуры.</p>
        </div>
    </div>
</footer>
{$bottomNavBlock}
</div>
<div id="command-palette" class="command-palette" hidden>
    <div class="command-backdrop" data-command-close></div>
    <div class="command-box" role="dialog" aria-modal="true" aria-label="Быстрый поиск">
        <input type="search" id="command-search" placeholder="Найти раздел или открыть поиск…" autocomplete="off">
        <div class="command-list">
            <a href="/logs">Журналы</a>
            <a href="/logs">Поиск по журналам</a>
            <a href="/bots/health">Здоровье ботов</a>
            <a href="/sites">Сайты aaPanel</a>
            <a href="/system">Система</a>
            <a href="/system/pwa">PWA / домашний экран</a>
        </div>
    </div>
</div>
</body>
</html>
HTML;
    }
}
