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
    'Installation.md',
    'Env-configuration.md',
    'Nginx-deployment.md',
    'PostgreSQL.md',
    'Cron-jobs.md',
    'API.md',
    'HMAC-signature.md',
    'Python-integration.md',
    'Bots.md',
    'Logs.md',
    'Incidents.md',
    'Alerts.md',
    'Retention.md',
    'Update-center.md',
    'Security.md',
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
$openapi = is_file($root . '/openapi.yaml') ? (string)file_get_contents($root . '/openapi.yaml') : '';

$wikiUrl = 'https://github.com/CajeerTeam/CajeerLogs/wiki';
$check('README ссылается на GitHub Wiki', str_contains($readme, $wikiUrl));
$check('.env.example содержит DOCS_URL на GitHub Wiki', str_contains($envExample, 'DOCS_URL=' . $wikiUrl));
$check('CI запускает wiki-check', str_contains($ci, 'php bin/wiki-check.php'));
$check('PR-шаблон упоминает Wiki-документацию', str_contains($pr, 'Wiki-документация'));
$check('OpenAPI-спецификация существует', is_file($root . '/openapi.yaml'));
$check('OpenAPI описывает POST /api/v1/ingest', str_contains($openapi, '/api/v1/ingest:') && str_contains($openapi, 'post:'));
$check('OpenAPI описывает фактический код ответа 200', str_contains($openapi, "'200':") && str_contains($openapi, 'inserted:'));

$forbidden = [
    'Mint' . 'lify',
    'Git' . 'Book',
    'docs.cajeer.ru' . '/logs',
    'Bot' . 'Host',
    'bot' . 'host',
];

$scanRoots = [
    'wiki',
    'app',
    'bin',
    'clients',
    '.github',
];
$scanFiles = [$root . '/README.md', $root . '/.env.example', $root . '/.nginx.conf', $root . '/SECURITY.md', $root . '/CONTRIBUTING.md', $root . '/openapi.yaml'];
foreach ($scanRoots as $dir) {
    $base = $root . '/' . $dir;
    if (!is_dir($base)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (preg_match('/\.(md|php|py|yml|yaml|sh)$/i', $path)) {
            $scanFiles[] = $path;
        }
    }
}
$scanFiles = array_values(array_unique($scanFiles));
foreach ($scanFiles as $file) {
    if (!is_file($file)) {
        continue;
    }
    $content = (string)file_get_contents($file);
    foreach ($forbidden as $needle) {
        $check('Нет запрещённого упоминания ' . $needle . ' в ' . str_replace($root . '/', '', $file), !str_contains($content, $needle), $file);
    }
}

exit($failed > 0 ? 1 : 0);
