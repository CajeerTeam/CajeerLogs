#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use CajeerLogs\Database;
use CajeerLogs\Env;
use CajeerLogs\Repository;
use CajeerLogs\UpdateManager;

$root = dirname(__DIR__);
$manager = new UpdateManager(new Repository(Database::pdo()));
$status = $manager->status();
$config = $status['config'];
$failed = 0;

$check = static function (string $name, bool $ok, string $message = '') use (&$failed): void {
    echo ($ok ? '[ОК]   ' : '[СБОЙ] ') . $name . ($message !== '' ? ' — ' . $message : '') . PHP_EOL;
    if (!$ok) { $failed++; }
};

$executable = static function (string $path): bool {
    if ($path === '') { return false; }
    if (str_contains($path, '/')) { return is_file($path) && is_executable($path); }
    $out = [];
    $code = 1;
    @exec('command -v ' . escapeshellarg($path) . ' 2>/dev/null', $out, $code);
    return $code === 0 && !empty($out);
};

$check('.env существует', is_file($root . '/.env'), 'Восстанови из storage/backups/updates/*/env.snapshot или создай на основе .env.example.');
$check('UPDATE_GIT_BIN исполняемый', $executable((string)$config['git_bin']), 'Текущее значение: ' . (string)$config['git_bin'] . '; пример: UPDATE_GIT_BIN=/usr/bin/git');
$check('UPDATE_TAR_BIN исполняемый', $executable((string)$config['tar_bin']), 'Текущее значение: ' . (string)$config['tar_bin'] . '; пример: UPDATE_TAR_BIN=/usr/bin/tar');
$check('UPDATE_PHP_BIN исполняемый', $executable((string)$config['php_bin']), 'Текущее значение: ' . (string)$config['php_bin'] . '; пример: UPDATE_PHP_BIN=/www/server/php/83/bin/php');
$check('Каталог является git-репозиторием', is_dir($root . '/.git'), 'В каталоге проекта нет .git.');
$check('Репозиторий обновления разрешён', $status['config']['allowed_repo_full_name'] !== '' && (bool)$manager->readiness()[7]['ok'], 'Проверь UPDATE_REPO_URL/UPDATE_ALLOWED_REPO_HOSTS/UPDATE_ALLOWED_REPO_FULL_NAME.');

$remoteOk = !empty($status['remote_commit']);
$check('Удалённая ветка/тег доступен', $remoteOk, (string)($status['remote_error'] ?? 'git ls-remote не вернул commit'));
$check('Нет локальных изменений', $status['has_local_changes'] === false, (string)($status['local_changes_list'] ?? 'git status --short'));
$check('safe.directory можно применить', true, (string)$config['git_bin'] . ' config --global --add safe.directory ' . $root);

echo PHP_EOL . 'Рекомендуемые команды:' . PHP_EOL;
foreach ($manager->repairHints() as $label => $command) {
    echo '- ' . $label . ': ' . $command . PHP_EOL;
}

exit($failed > 0 ? 1 : 0);
