#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = 0;
$check = static function (string $name, bool $ok, string $message = '') use (&$failed): void {
    echo ($ok ? '[ОК]   ' : '[СБОЙ] ') . $name . ($message !== '' ? ' — ' . $message : '') . PHP_EOL;
    if (!$ok) { $failed++; }
};

$version = trim((string)@file_get_contents($root . '/VERSION'));
$readme = (string)@file_get_contents($root . '/README.md');
$changelog = (string)@file_get_contents($root . '/wiki/Changelog.md');
$releaseChecklist = (string)@file_get_contents($root . '/wiki/Release-checklist.md');
$env = (string)@file_get_contents($root . '/.env.example');
$nginx = (string)@file_get_contents($root . '/.nginx.conf');
$openapi = (string)@file_get_contents($root . '/openapi.yaml');

$check('VERSION задан', $version !== '');
$check('README содержит текущую версию', str_contains($readme, $version));
$check('Changelog содержит текущую версию', str_contains($changelog, $version));
$check('Release checklist содержит SHA256SUMS', str_contains($releaseChecklist, 'SHA256SUMS'));
$check('OpenAPI содержит ingest endpoint', str_contains($openapi, '/api/v1/ingest:') && str_contains($openapi, "'200':") && str_contains($openapi, 'inserted:'));
$check('.env.example не содержит реального секрета', !preg_match('/(ghp_|xoxb-|BEGIN PRIVATE KEY|BOT_TOKEN=\d+:)/', $env));
$check('.nginx.conf использует примерный домен', str_contains($nginx, 'logs.example.com') && !str_contains($nginx, 'logs.cajeer.ru'));
$check('Social preview SVG существует', is_file($root . '/assets/social-preview.svg'));
$check('Wiki aaPanel-интеграции существует', is_file($root . '/wiki/AaPanel-integration.md'));
$check('Workflow Wiki publish существует', is_file($root . '/.github/workflows/wiki-publish.yml'));
$check('Workflow release существует', is_file($root . '/.github/workflows/release.yml'));
$check('Workflow labels sync существует', is_file($root . '/.github/workflows/labels-sync.yml'));
$check('Update-check существует', is_file($root . '/bin/update-check.php'));
$check('Alert pipeline smoke существует', is_file($root . '/bin/alert-pipeline-smoke.php'));
$check('OpenAPI описывает HMAC required', str_contains($openapi, 'X-Log-Signature') && str_contains($openapi, 'replayed_nonce') && str_contains($openapi, 'BearerToken'));
$check('.env.example требует update по tag', str_contains($env, 'UPDATE_REQUIRE_TAG=true') && str_contains($env, 'UPDATE_BRANCH=v' . $version));
$check('PostgreSQL integration CI есть', str_contains((string)@file_get_contents($root . '/.github/workflows/ci.yml'), 'integration-postgres'));
$check('SQLite smoke CI есть', str_contains((string)@file_get_contents($root . '/.github/workflows/ci.yml'), 'integration-sqlite'));

$updateManager = (string)@file_get_contents($root . '/app/UpdateManager.php');
$migrator = (string)@file_get_contents($root . '/app/Migrator.php');
$view = (string)@file_get_contents($root . '/app/View.php');
$bootstrap = (string)@file_get_contents($root . '/app/bootstrap.php');

$check('UpdateManager не содержит by-reference вызов ensureSafeDirectory($tmp = [])', !str_contains($updateManager, '$this->ensureSafeDirectory($tmp = [])'));
$check('UpdateManager использует UPDATE_TAR_BIN', str_contains($updateManager, "'tar_bin' => Env::get('UPDATE_TAR_BIN'") && str_contains($updateManager, "config()['tar_bin']"));
$check('.env.example содержит UPDATE_TAR_BIN', str_contains($env, 'UPDATE_TAR_BIN='));
$sqliteStart = strpos($migrator, 'private function runSqlite');
$sqliteEnd = strpos($migrator, 'private function recordVersion', $sqliteStart === false ? 0 : $sqliteStart);
$sqliteBlock = $sqliteStart === false ? '' : substr($migrator, $sqliteStart, $sqliteEnd === false ? null : $sqliteEnd - $sqliteStart);
$check('SQLite-блок Migrator не содержит ADD COLUMN IF NOT EXISTS', !str_contains($sqliteBlock, 'ADD COLUMN IF NOT EXISTS'));
$check('SQLite-блок Migrator не содержит TIMESTAMPTZ', !str_contains($sqliteBlock, 'TIMESTAMPTZ'));
$check('SQLite-блок Migrator не содержит NOW()', !str_contains($sqliteBlock, 'NOW()'));
$check('Command palette содержит /system/update', str_contains($view, '/system/update') && str_contains($view, 'Обновление'));
$check('Production без .env блокируется для web', str_contains($bootstrap, 'Файл .env обязателен для production'));
$check('Update-env-check существует', is_file($root . '/bin/update-env-check.php'));

$forbidden = ['Mint' . 'lify', 'Git' . 'Book', 'Bot' . 'Host', 'docs.cajeer.ru' . '/logs'];
$scanFiles = [
    'README.md', '.env.example', '.nginx.conf', 'SECURITY.md', 'CONTRIBUTING.md', 'openapi.yaml',
    '.github/PULL_REQUEST_TEMPLATE.md', '.github/workflows/ci.yml', '.github/workflows/wiki-publish.yml',
];
foreach (['wiki', 'app', 'bin', 'clients'] as $dir) {
    $base = $root . '/' . $dir;
    if (!is_dir($base)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && preg_match('/\.(md|php|py|yml|yaml|sh)$/i', $file->getFilename())) {
            $scanFiles[] = str_replace($root . '/', '', $file->getPathname());
        }
    }
}
foreach (array_unique($scanFiles) as $file) {
    $content = (string)@file_get_contents($root . '/' . $file);
    foreach ($forbidden as $needle) {
        $check('Нет запрещённого упоминания ' . $needle . ' в ' . $file, !str_contains($content, $needle));
    }
}

$commands = [
    ['php', 'bin/self-test.php'],
    ['php', 'bin/wiki-check.php'],
    ['php', 'bin/schema-check.php'],
];
foreach ($commands as $cmd) {
    $line = implode(' ', array_map('escapeshellarg', $cmd));
    $out = [];
    $code = 0;
    $tmp = tempnam(sys_get_temp_dir(), 'cajeer-release-check-');
    exec('cd ' . escapeshellarg($root) . ' && timeout 30s ' . $line . ' > ' . escapeshellarg((string)$tmp) . ' 2>&1', $out, $code);
    $tail = is_string($tmp) && is_file($tmp) ? implode("\n", array_slice(file($tmp, FILE_IGNORE_NEW_LINES) ?: [], -8)) : '';
    if (is_string($tmp) && is_file($tmp)) { @unlink($tmp); }
    $check('Команда проходит: ' . implode(' ', $cmd), $code === 0, $tail);
}

exit($failed > 0 ? 1 : 0);
