#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Env;
use CajeerLogs\RuntimeDiagnostics;

$root = dirname(__DIR__);
$failed = 0;
$check = static function (string $name, bool $ok, string $message = '') use (&$failed): void {
    echo ($ok ? '[ОК]   ' : '[СБОЙ] ') . $name . ($message !== '' ? ' — ' . $message : '') . PHP_EOL;
    if (!$ok) { $failed++; }
};

$git = trim((string)Env::get('UPDATE_GIT_BIN', 'git'));
$tar = trim((string)Env::get('UPDATE_TAR_BIN', 'tar'));
$php = trim((string)Env::get('UPDATE_PHP_BIN', PHP_BINARY));
$repo = trim((string)Env::get('UPDATE_REPO_URL', 'https://github.com/CajeerTeam/CajeerLogs'));
$branch = trim((string)Env::get('UPDATE_BRANCH', 'v' . trim((string)@file_get_contents($root . '/VERSION'))));
$requireTag = Env::bool('UPDATE_REQUIRE_TAG', true);

$check('.env существует', RuntimeDiagnostics::envExists(), 'Восстанови из storage/backups/updates/*/env.snapshot или создай на основе .env.example.');
$check('UPDATE_GIT_BIN исполняемый', RuntimeDiagnostics::commandAvailable($git), 'Текущее значение: ' . $git . '; пример: UPDATE_GIT_BIN=/usr/bin/git');
$check('UPDATE_TAR_BIN исполняемый', RuntimeDiagnostics::commandAvailable($tar), 'Текущее значение: ' . $tar . '; пример: UPDATE_TAR_BIN=/usr/bin/tar');
$check('UPDATE_PHP_BIN исполняемый', RuntimeDiagnostics::commandAvailable($php), 'Текущее значение: ' . $php . '; пример: UPDATE_PHP_BIN=/www/server/php/83/bin/php');
$check('Каталог является git-репозиторием', is_dir($root . '/.git'), 'В каталоге проекта нет .git.');
$check('Цель update похожа на tag', !$requireTag || (bool)preg_match('/^v?\d+\.\d+\.\d+(?:[-+][A-Za-z0-9._-]+)?$/', $branch), 'UPDATE_REQUIRE_TAG=true требует UPDATE_BRANCH вида v1.2.3.');

$remoteOk = false;
$remoteMessage = 'git ls-remote не запускался';
if (RuntimeDiagnostics::commandAvailable($git)) {
    $out = [];
    $code = 1;
    @exec(escapeshellcmd($git) . ' ls-remote --heads --tags ' . escapeshellarg($repo) . ' ' . escapeshellarg($branch) . ' 2>&1', $out, $code);
    $remoteOk = $code === 0 && trim(implode("\n", $out)) !== '';
    $remoteMessage = trim(implode("\n", $out)) ?: 'нет ответа';
}
$check('Удалённая ветка/тег доступен', $remoteOk, $remoteMessage);

$out = [];
$code = 1;
if (RuntimeDiagnostics::commandAvailable($git) && is_dir($root . '/.git')) {
    @exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellcmd($git) . ' status --short 2>&1', $out, $code);
}
$dirty = $code === 0 && trim(implode("\n", $out)) !== '';
$check('Нет локальных изменений', !$dirty, $dirty ? implode("\n", $out) : 'OK');
$check('safe.directory можно применить', true, $git . ' config --global --add safe.directory ' . $root);

$violations = RuntimeDiagnostics::productionLockViolations();
$check('Production lock безопасен', !$violations, implode(' ', $violations));

echo PHP_EOL . 'Рекомендуемые команды:' . PHP_EOL;
echo '- Git: apt install -y git && echo UPDATE_GIT_BIN=/usr/bin/git >> .env' . PHP_EOL;
echo '- tar: apt install -y tar && echo UPDATE_TAR_BIN=/usr/bin/tar >> .env' . PHP_EOL;
echo '- PHP CLI: echo UPDATE_PHP_BIN=/www/server/php/83/bin/php >> .env' . PHP_EOL;
echo '- safe.directory: ' . $git . ' config --global --add safe.directory ' . $root . PHP_EOL;
echo '- Локальные изменения: git status --short && git diff --stat' . PHP_EOL;

exit($failed > 0 ? 1 : 0);
