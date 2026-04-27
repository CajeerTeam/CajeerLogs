<?php
declare(strict_types=1);

namespace CajeerLogs;

use RuntimeException;
use Throwable;

final class UpdateManager
{
    public function __construct(private readonly Repository $repo) {}

    public function root(): string
    {
        return dirname(__DIR__);
    }

    public function config(): array
    {
        $repo = Env::get('UPDATE_REPO_URL', 'https://github.com/CajeerTeam/CajeerLogs');
        return [
            'repo_url' => $repo,
            'branch' => Env::get('UPDATE_BRANCH', 'main'),
            'mode' => Env::get('UPDATE_MODE', 'git'),
            'allow_web' => Env::bool('UPDATE_ALLOW_WEB', false),
            'backup_dir' => Env::get('UPDATE_BACKUP_DIR', $this->root() . '/storage/backups/updates'),
            'rollback_on_failure' => Env::bool('UPDATE_ROLLBACK_ON_FAILURE', true),
            'git_bin' => Env::get('UPDATE_GIT_BIN', 'git'),
            'php_bin' => $this->phpBin(),
            'uses_token' => trim((string)Env::get('UPDATE_GITHUB_TOKEN', '')) !== '',
        ];
    }

    public function status(): array
    {
        $root = $this->root();
        $config = $this->config();
        $gitRepo = is_dir($root . '/.git');
        $status = [
            'root' => $root,
            'version' => $this->currentVersion(),
            'config' => $config,
            'is_git_repo' => $gitRepo,
            'current_commit' => null,
            'current_branch' => null,
            'remote_commit' => null,
            'has_local_changes' => null,
            'git_status' => null,
            'git_available' => $this->commandAvailable($config['git_bin']),
            'tar_available' => $this->commandAvailable('tar'),
            'php_available' => is_file($config['php_bin']) || $this->commandAvailable($config['php_bin']),
            'backup_dir_writable' => $this->pathWritableOrCreatable((string)$config['backup_dir']),
        ];

        if (!$gitRepo) {
            $status['git_status'] = 'Каталог не является git-репозиторием.';
            return $status;
        }

        try {
            $status['current_commit'] = trim($this->git(['rev-parse', 'HEAD'])->output);
            $status['current_branch'] = trim($this->git(['rev-parse', '--abbrev-ref', 'HEAD'])->output);
            $porcelain = trim($this->git(['status', '--porcelain'])->output);
            $status['has_local_changes'] = $porcelain !== '';
            $status['git_status'] = $porcelain === '' ? 'Рабочее дерево чистое.' : $porcelain;
        } catch (Throwable $e) {
            $status['git_status'] = $e->getMessage();
        }

        try {
            $status['remote_commit'] = $this->remoteCommit();
        } catch (Throwable $e) {
            $status['remote_error'] = $e->getMessage();
        }

        return $status;
    }

    public function readiness(): array
    {
        $status = $this->status();
        $checks = [];
        $checks[] = $this->check('Web-обновления разрешены', (bool)$status['config']['allow_web'], 'UPDATE_ALLOW_WEB=false');
        $checks[] = $this->check('Git доступен', (bool)$status['git_available'], 'Команда git не найдена или недоступна PHP.');
        $checks[] = $this->check('Каталог является git-репозиторием', (bool)$status['is_git_repo'], 'В каталоге нет .git. Обновление возможно только для git-deploy.');
        $checks[] = $this->check('Резервная директория доступна', (bool)$status['backup_dir_writable'], 'Проверь права на storage/backups/updates.');
        $checks[] = $this->check('tar доступен для backup', (bool)$status['tar_available'], 'Установи tar или настрой другой backup-процесс.');
        $checks[] = $this->check('PHP CLI доступен', (bool)$status['php_available'], 'Проверь UPDATE_PHP_BIN.');
        $checks[] = $this->check('.env существует', is_file($this->root() . '/.env'), 'Без .env обновление не запускается.');
        $checks[] = $this->check('Удалённая ветка доступна', !empty($status['remote_commit']), $status['remote_error'] ?? 'Не удалось получить commit удалённой ветки.');
        $checks[] = $this->check('Нет локальных изменений', $status['has_local_changes'] === false, 'Есть локальные изменения; git reset --hard их сотрёт.');

        try {
            Database::pdo()->query('SELECT 1');
            $checks[] = $this->check('База данных доступна', true, '');
        } catch (Throwable $e) {
            $checks[] = $this->check('База данных доступна', false, $e->getMessage());
        }

        return $checks;
    }

    public function backupOnly(): array
    {
        return $this->createBackup('manual');
    }

    public function updateFromGit(): array
    {
        $config = $this->config();
        if (!$config['allow_web']) {
            throw new RuntimeException('Обновление из веб-интерфейса отключено UPDATE_ALLOW_WEB=false.');
        }

        $readiness = $this->readiness();
        $failed = array_values(array_filter($readiness, static fn(array $check): bool => !$check['ok']));
        if ($failed) {
            throw new RuntimeException('Предварительная проверка не пройдена: ' . $failed[0]['message']);
        }

        $fromVersion = $this->currentVersion();
        $fromCommit = trim($this->git(['rev-parse', 'HEAD'])->output);
        $target = $this->remoteCommit();
        $backup = $this->createBackup('pre-update');
        $runId = $this->repo->createUpdateRun([
            'action' => 'update',
            'status' => 'running',
            'repo_url' => $config['repo_url'],
            'branch' => $config['branch'],
            'from_version' => $fromVersion,
            'to_version' => null,
            'from_commit' => $fromCommit,
            'to_commit' => $target,
            'backup_path' => $backup['dir'],
            'output_log' => '',
            'error_message' => null,
        ]);

        $started = microtime(true);
        $log = [];
        try {
            $this->ensureSafeDirectory($log);
            $fetch = $this->git(['fetch', 'origin', (string)$config['branch']]);
            $log[] = '$ git fetch origin ' . $config['branch'] . "\n" . $fetch->output;
            $reset = $this->git(['reset', '--hard', 'origin/' . $config['branch']]);
            $log[] = '$ git reset --hard origin/' . $config['branch'] . "\n" . $reset->output;
            $this->restoreEnvFromBackup($backup['dir']);

            $migrate = $this->run([$config['php_bin'], 'bin/migrate.php'], $this->root());
            $log[] = '$ php bin/migrate.php' . "\n" . $migrate->output;
            if ($migrate->code !== 0) {
                throw new RuntimeException('Миграции завершились с ошибкой.');
            }

            $doctor = $this->run([$config['php_bin'], 'bin/doctor.php'], $this->root());
            $log[] = '$ php bin/doctor.php' . "\n" . $doctor->output;
            if ($doctor->code !== 0) {
                throw new RuntimeException('Doctor завершился с ошибкой.');
            }

            $toVersion = $this->currentVersion();
            $toCommit = trim($this->git(['rev-parse', 'HEAD'])->output);
            $this->repo->finishUpdateRun($runId, 'success', implode("\n\n", $log), null, $toVersion, $toCommit, (int)((microtime(true) - $started) * 1000));
            $this->repo->audit('update.success', 'update_run', (string)$runId, 'Приложение обновлено из GitHub', ['from_commit' => $fromCommit, 'to_commit' => $toCommit]);
            return ['ok' => true, 'run_id' => $runId, 'backup' => $backup, 'from_commit' => $fromCommit, 'to_commit' => $toCommit, 'output' => implode("\n\n", $log)];
        } catch (Throwable $e) {
            $log[] = 'ERROR: ' . $e->getMessage();
            if ($config['rollback_on_failure']) {
                try {
                    $rollback = $this->git(['reset', '--hard', $fromCommit]);
                    $this->restoreEnvFromBackup($backup['dir']);
                    $log[] = 'AUTO ROLLBACK to ' . $fromCommit . "\n" . $rollback->output;
                } catch (Throwable $rollbackError) {
                    $log[] = 'AUTO ROLLBACK FAILED: ' . $rollbackError->getMessage();
                }
            }
            $this->repo->finishUpdateRun($runId, 'failed', implode("\n\n", $log), $e->getMessage(), null, null, (int)((microtime(true) - $started) * 1000));
            $this->repo->audit('update.failed', 'update_run', (string)$runId, 'Ошибка обновления приложения', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function rollbackLast(): array
    {
        $run = $this->repo->latestRollbackableUpdateRun();
        if (!$run) {
            throw new RuntimeException('Нет update-run с сохранённым предыдущим commit для отката.');
        }
        $commit = (string)$run['from_commit'];
        $backup = (string)($run['backup_path'] ?? '');
        $log = [];
        $reset = $this->git(['reset', '--hard', $commit]);
        $log[] = '$ git reset --hard ' . $commit . "\n" . $reset->output;
        if ($backup !== '') {
            $this->restoreEnvFromBackup($backup);
            $log[] = 'Восстановлен .env из backup, если он был сохранён.';
        }
        $this->repo->audit('update.rollback', 'update_run', (string)$run['id'], 'Выполнен откат приложения', ['commit' => $commit]);
        return ['ok' => true, 'commit' => $commit, 'output' => implode("\n\n", $log)];
    }

    private function pathWritableOrCreatable(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }
        $probe = $path;
        while (!is_dir($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
        }
        return is_dir($probe) && is_writable($probe);
    }

    private function currentVersion(): string
    {
        $file = $this->root() . '/VERSION';
        return is_file($file) ? trim((string)file_get_contents($file)) : 'unknown';
    }

    private function remoteCommit(): string
    {
        $config = $this->config();
        if (is_dir($this->root() . '/.git')) {
            $this->ensureSafeDirectory($tmp = []);
        }
        $result = $this->run([$config['git_bin'], 'ls-remote', '--heads', $this->repoUrlForCommand(), (string)$config['branch']], $this->root());
        if ($result->code !== 0) {
            throw new RuntimeException(trim($this->sanitizeOutput($result->output)) ?: 'git ls-remote failed');
        }
        $line = trim($result->output);
        if ($line === '') {
            throw new RuntimeException('Ветка ' . $config['branch'] . ' не найдена в репозитории.');
        }
        $parts = preg_split('/\s+/', $line);
        return (string)($parts[0] ?? '');
    }

    private function createBackup(string $reason): array
    {
        $root = $this->root();
        $base = rtrim((string)$this->config()['backup_dir'], '/');
        $dir = $base . '/' . gmdate('Ymd-His') . '-' . preg_replace('/[^a-z0-9_-]+/i', '-', $reason);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог backup: ' . $dir);
        }
        if (is_file($root . '/.env')) {
            copy($root . '/.env', $dir . '/env.snapshot');
        }
        $manifest = [
            'created_at' => gmdate('c'),
            'reason' => $reason,
            'version' => $this->currentVersion(),
            'commit' => is_dir($root . '/.git') ? trim($this->git(['rev-parse', 'HEAD'])->output) : null,
            'repo' => $this->config()['repo_url'],
            'branch' => $this->config()['branch'],
        ];
        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $archive = $dir . '/files.tar.gz';
        $tar = $this->run([
            'tar',
            '--exclude=./.git',
            '--exclude=./storage/backups',
            '--exclude=./storage/logs',
            '--exclude=./storage/cache',
            '--exclude=./storage/archives',
            '-czf',
            $archive,
            '.',
        ], $root);
        if ($tar->code !== 0) {
            throw new RuntimeException('Создание tar-архива резервной копии завершилось ошибкой: ' . $this->sanitizeOutput($tar->output));
        }
        return ['dir' => $dir, 'archive' => $archive, 'manifest' => $manifest];
    }

    private function restoreEnvFromBackup(string $backupDir): void
    {
        $env = rtrim($backupDir, '/') . '/env.snapshot';
        if (is_file($env)) {
            copy($env, $this->root() . '/.env');
        }
    }

    private function ensureSafeDirectory(array &$log): void
    {
        $root = $this->root();
        $config = $this->config();
        $result = $this->run([$config['git_bin'], 'config', '--global', '--add', 'safe.directory', $root], $root);
        $log[] = '$ git config --global --add safe.directory ' . $root . "\n" . $result->output;
    }

    private function git(array $args): CommandResult
    {
        $config = $this->config();
        $result = $this->run(array_merge([$config['git_bin'], '-c', 'safe.directory=' . $this->root()], $args), $this->root());
        if ($result->code !== 0) {
            throw new RuntimeException(trim($result->output) ?: 'git command failed');
        }
        return $result;
    }

    private function repoUrlForCommand(): string
    {
        $url = (string)$this->config()['repo_url'];
        $token = trim((string)Env::get('UPDATE_GITHUB_TOKEN', ''));
        if ($token !== '' && str_starts_with($url, 'https://github.com/')) {
            return 'https://x-access-token:' . rawurlencode($token) . '@github.com/' . substr($url, strlen('https://github.com/'));
        }
        return $url;
    }

    private function phpBin(): string
    {
        $explicit = trim((string)Env::get('UPDATE_PHP_BIN', ''));
        if ($explicit !== '') {
            return $explicit;
        }
        foreach (['83', '84', '82', '81'] as $v) {
            $candidate = '/www/server/php/' . $v . '/bin/php';
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return PHP_SAPI === 'cli' ? PHP_BINARY : 'php';
    }

    private function commandAvailable(string $cmd): bool
    {
        if ($cmd === '') {
            return false;
        }
        if (str_contains($cmd, '/') && is_executable($cmd)) {
            return true;
        }
        if (!function_exists('exec')) {
            return false;
        }
        $out = [];
        $code = 1;
        @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $code);
        return $code === 0 && !empty($out);
    }

    private function run(array $argv, string $cwd): CommandResult
    {
        $cmd = implode(' ', array_map('escapeshellarg', $argv));
        if (!function_exists('proc_open')) {
            if (!function_exists('exec')) {
                throw new RuntimeException('В PHP отключены proc_open и exec; запуск shell-команд невозможен.');
            }
            $output = [];
            $code = 0;
            @exec('cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1', $output, $code);
            return new CommandResult($code, $this->sanitizeOutput(implode("\n", $output)));
        }
        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($cmd, $descriptor, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException('Не удалось запустить команду: ' . $cmd);
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        return new CommandResult((int)$code, $this->sanitizeOutput(trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''))));
    }

    private function sanitizeOutput(string $output): string
    {
        $token = trim((string)Env::get('UPDATE_GITHUB_TOKEN', ''));
        if ($token !== '') {
            $output = str_replace($token, '***', $output);
            $output = preg_replace('#x-access-token:[^@\s]+@#', 'x-access-token:***@', $output) ?? $output;
        }
        return $output;
    }

    private function check(string $name, bool $ok, string $message): array
    {
        return ['name' => $name, 'ok' => $ok, 'message' => $ok ? 'OK' : $message];
    }
}

final class CommandResult
{
    public function __construct(public readonly int $code, public readonly string $output) {}
}
