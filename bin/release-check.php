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
$updateManager = (string)@file_get_contents($root . '/app/UpdateManager.php');
$migrator = (string)@file_get_contents($root . '/app/Migrator.php');
$view = (string)@file_get_contents($root . '/app/View.php');
$bootstrap = (string)@file_get_contents($root . '/app/bootstrap.php');
$sw = (string)@file_get_contents($root . '/public/sw.js');

$check('VERSION задан', $version !== '');
$check('README содержит текущую версию', str_contains($readme, $version));
$check('Changelog содержит текущую версию', str_contains($changelog, $version));
$check('Release checklist содержит SHA256SUMS', str_contains($releaseChecklist, 'SHA256SUMS'));
$check('OpenAPI содержит ingest endpoint', str_contains($openapi, '/api/v1/ingest:') && str_contains($openapi, "'200':") && str_contains($openapi, 'inserted:'));
$check('.env.example не содержит реального секрета', !preg_match('/(ghp_|xoxb-|BEGIN PRIVATE KEY|BOT_TOKEN=\d+:)/', $env));
$check('.nginx.conf использует примерный домен', str_contains($nginx, 'logs.example.com') && !str_contains($nginx, 'logs.cajeer.ru'));
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
$check('Command palette содержит /system/runtime и /system/jobs', str_contains($view, '/system/runtime') && str_contains($view, '/system/jobs'));
$check('Production без .env блокируется для web', str_contains($bootstrap, 'Файл .env обязателен для production'));
$check('Production lock поддерживается', str_contains($bootstrap, 'assertProductionLockSafe') && is_file($root . '/app/RuntimeDiagnostics.php'));
$check('PWA cache привязан к VERSION', str_contains($sw, $version) && str_contains($view, '$assetVersion'));
$check('Update-env-check независим от Repository/Database', !str_contains((string)@file_get_contents($root . '/bin/update-env-check.php'), 'Database::pdo') && !str_contains((string)@file_get_contents($root . '/bin/update-env-check.php'), 'new Repository'));
$check('Release workflow существует', is_file($root . '/.github/workflows/release.yml'));
$check('Workflow Wiki publish существует', is_file($root . '/.github/workflows/wiki-publish.yml'));
$check('Social preview SVG существует', is_file($root . '/assets/social-preview.svg'));
$check('Wiki aaPanel-интеграции существует', is_file($root . '/wiki/AaPanel-integration.md'));
$check('Forbidden scan существует', is_file($root . '/bin/forbidden-scan.php'));
$check('Full check существует', is_file($root . '/bin/full-check.php'));

exit($failed > 0 ? 1 : 0);
