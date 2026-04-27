#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = 0;

$check = static function (string $name, bool $ok, string $message = '') use (&$failed): void {
    echo ($ok ? '[ОК]   ' : '[СБОЙ] ') . $name . ($message !== '' ? ' — ' . $message : '') . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
};

$requiredPages = [
    'Home.md',
    'Установка.md',
    'Настройка-env.md',
    'Развёртывание-Nginx.md',
    'PostgreSQL.md',
    'Cron-задачи.md',
    'API.md',
    'Подпись-HMAC.md',
    'Интеграция-Python.md',
    'Боты.md',
    'Журналы.md',
    'Инциденты.md',
    'Оповещения.md',
    'Retention.md',
    'Центр-обновлений.md',
    'Безопасность.md',
    'RBAC.md',
    'Production-checklist.md',
    'Release-checklist.md',
    'FAQ.md',
    'Changelog.md',
];

foreach ($requiredPages as $page) {
    $path = $root . '/wiki/' . $page;
    $check('Wiki-страница существует: ' . $page, is_file($path), $path);
    if (is_file($path)) {
        $content = trim((string)file_get_contents($path));
        $check('Wiki-страница не пустая: ' . $page, $content !== '', $path);
        $check('Wiki-страница содержит заголовок: ' . $page, str_starts_with($content, '#'), $path);
    }
}

$readme = is_file($root . '/README.md') ? (string)file_get_contents($root . '/README.md') : '';
$envExample = is_file($root . '/.env.example') ? (string)file_get_contents($root . '/.env.example') : '';
$ci = is_file($root . '/.github/workflows/ci.yml') ? (string)file_get_contents($root . '/.github/workflows/ci.yml') : '';
$pr = is_file($root . '/.github/PULL_REQUEST_TEMPLATE.md') ? (string)file_get_contents($root . '/.github/PULL_REQUEST_TEMPLATE.md') : '';

$wikiUrl = 'https://github.com/CajeerTeam/CajeerLogs/wiki';
$check('README ссылается на GitHub Wiki', str_contains($readme, $wikiUrl));
$check('.env.example содержит DOCS_URL на GitHub Wiki', str_contains($envExample, 'DOCS_URL=' . $wikiUrl));
$check('CI запускает wiki-check', str_contains($ci, 'php bin/wiki-check.php'));
$check('PR-шаблон упоминает Wiki, а не внешнюю документационную платформу', str_contains($pr, 'Wiki-документация') && !str_contains($pr, 'устаревшая платформа документации') && !str_contains($pr, 'устаревшая платформа документации'));
$check('OpenAPI-спецификация существует', is_file($root . '/openapi.yaml'));

$openapi = is_file($root . '/openapi.yaml') ? (string)file_get_contents($root . '/openapi.yaml') : '';
$check('OpenAPI описывает POST /api/v1/ingest', str_contains($openapi, '/api/v1/ingest:') && str_contains($openapi, 'post:'));

$forbidden = [
    'Mint' . 'lify',
    'Git' . 'Book',
    'docs.cajeer.ru' . '/logs',
    'Bot' . 'Host',
    'bot' . 'host',
];
$scanFiles = array_merge(
    glob($root . '/wiki/*.md') ?: [],
    [$root . '/README.md', $root . '/.github/PULL_REQUEST_TEMPLATE.md', $root . '/.github/workflows/ci.yml', $root . '/.env.example']
);
foreach ($scanFiles as $file) {
    if (!is_file($file)) {
        continue;
    }
    $content = (string)file_get_contents($file);
    foreach ($forbidden as $needle) {
        $check('Нет запрещённого упоминания ' . $needle . ' в ' . basename($file), !str_contains($content, $needle), $file);
    }
}

exit($failed > 0 ? 1 : 0);
