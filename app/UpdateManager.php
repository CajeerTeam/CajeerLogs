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
            'branch' => Env::get('UPDATE_BRANCH', 'v0.8.2-ops-hardening'),
            'mode' => Env::get('UPDATE_MODE', 'git'),
            'allow_web' => Env::bool('UPDATE_ALLOW_WEB', false),
            'backup_dir' => Env::get('UPDATE_BACKUP_DIR', $this->root() . '/storage/backups/updates'),
            'rollback_on_failure' => Env::bool('UPDATE_ROLLBACK_ON_FAILURE', true),
            'git_bin' => Env::get('UPDATE_GIT_BIN', 'git'),
            'tar_bin' => Env::get('UPDATE_TAR_BIN', 'tar'),
            'php_bin' => $this->phpBin(),
            'uses_token' => trim((string)Env::get('UPDATE_GITHUB_TOKEN', '')) !== '',
            'allowed_repo_hosts' => Env::get('UPDATE_ALLOWED_REPO_HOSTS', 'github.com'),
            'allowed_repo_full_name' => Env::get('UPDATE_ALLOWED_REPO_FULL_NAME', 'CajeerTeam/CajeerLogs'),
            'require_tag' => Env::bool('UPDATE_REQUIRE_TAG', true),
            'require_clean_worktree' => Env::bool('UPDATE_REQUIRE_CLEAN_WORKTREE', true),
            'command_timeout_seconds' => Env::int('UPDATE_COMMAND_TIMEOUT_SECONDS', 120),
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
            'git_diff_stat' => null,
            'git_available' => $this->commandAvailable((string)$config['git_bin']),
            'tar_available' => $this->commandAvailable((string)$config['tar_bin']),
            'php_available' => is_file((string)$config['php_bin']) || $this->commandAvailable((string)$config['php_bin']),
            'backup_dir_writable' => $this->pathWritableOrCreatable((string)$config['backup_dir']),
            'php_runtime' => $this->phpRuntimeDiagnostics(),
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
            $status['local_changes_list'] = $porcelain;
            if ($porcelain !== '') {
                try {
                    $status['git_diff_stat'] = trim($this->git(['diff', '--stat'])->output);
                } catch (Throwable) {
                    $status['git_diff_stat'] = '';
                }
            }
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
        $checks[] = $this->check('Git доступен', (bool)$status['git_available'], 'Команда git не найдена или недоступна PHP. Укажи UPDATE_GIT_BIN=/usr/bin/git или установи git.');
        $checks[] = $this->check('Каталог является git-репозиторием', (bool)$status['is_git_repo'], 'В каталоге нет .git. Обновление возможно только для git-deploy.');
        $checks[] = $this->check('Резервная директория доступна', (bool)$status['backup_dir_writable'], 'Проверь права на storage/backups/updates.');
        $checks[] = $this->check('tar доступен для backup', (bool)$status['tar_available'], 'Укажи UPDATE_TAR_BIN=/usr/bin/tar или установи tar.');
        $checks[] = $this->check('PHP CLI доступен', (bool)$status['php_available'], 'Проверь UPDATE_PHP_BIN=/www/server/php/83/bin/php.');
        $checks[] = $this->check('.env существует', is_file($this->root() . '/.env'), 'Без .env обновление не запускается.');
        $checks[] = $this->check('Репозиторий обновления разрешён', $this->repoUrlAllowed((string)$status['config']['repo_url']), 'UPDATE_REPO_URL не входит в UPDATE_ALLOWED_REPO_HOSTS/UPDATE_ALLOWED_REPO_FULL_NAME.');
        $checks[] = $this->check('Цель обновления допустима', !$status['config']['require_tag'] || $this->looksLikeTag((string)$status['config']['branch']), 'UPDATE_REQUIRE_TAG=true требует UPDATE_BRANCH вида v1.2.3.');
        $checks[] = $this->check('Удалённая ветка/тег доступен', !empty($status['remote_commit']), $status['remote_error'] ?? 'Не удалось получить commit удалённой ветки или тега.');
        if ($status['config']['require_clean_worktree']) {
            $details = isset($status['local_changes_list']) && is_string($status['local_changes_list']) && $status['local_changes_list'] !== '' ? $status['local_changes_list'] : 'git status --short';
            $checks[] = $this->check('Нет локальных изменений', $status['has_local_changes'] === false, 'Есть локальные изменения; git reset --hard их сотрёт. Проверь: ' . $details);
        }

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
            $ref = (string)$config['branch'];
            $fetch = $this->git(['fetch', '--tags', 'origin', $ref]);
            $log[] = '$ git fetch --tags origin ' . $ref . "\n" . $fetch->output;
            $targetRef = $this->looksLikeTag($ref) ? $ref : 'origin/' . $ref;
            $reset = $this->git(['reset', '--hard', $targetRef]);
            $log[] = '$ git reset --hard ' . $targetRef . "\n" . $reset->output;
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
            $tmp = [];
            $this->ensureSafeDirectory($tmp);
        }
        $ref = (string)$config['branch'];
        $flag = $this->looksLikeTag($ref) ? '--tags' : '--heads';
        $result = $this->run([$config['git_bin'], 'ls-remote', $flag, $this->repoUrlForCommand(), $ref], $this->root());
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
            (string)$this->config()['tar_bin'],
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


    private function repoUrlAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        $allowedHosts = array_filter(array_map('trim', preg_split('/[\s,;]+/', strtolower((string)Env::get('UPDATE_ALLOWED_REPO_HOSTS', 'github.com'))) ?: []));
        if ($allowedHosts && !in_array($host, $allowedHosts, true)) {
            return false;
        }
        $requiredFullName = trim((string)Env::get('UPDATE_ALLOWED_REPO_FULL_NAME', 'CajeerTeam/CajeerLogs'), '/');
        if ($requiredFullName !== '') {
            $path = trim((string)($parts['path'] ?? ''), '/');
            $path = preg_replace('/\.git$/', '', $path) ?? $path;
            if (strcasecmp($path, $requiredFullName) !== 0) {
                return false;
            }
        }
        return true;
    }

    private function looksLikeTag(string $ref): bool
    {
        return (bool)preg_match('/^v?\d+\.\d+\.\d+(?:[-+][A-Za-z0-9._-]+)?$/', $ref);
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
        $timeout = max(10, Env::int('UPDATE_COMMAND_TIMEOUT_SECONDS', 120));
        if (!function_exists('proc_open')) {
            if (!function_exists('exec')) {
                throw new RuntimeException('В PHP отключены proc_open и exec; запуск shell-команд невозможен.');
            }
            $output = [];
            $code = 0;
            $timeoutPrefix = $this->commandAvailable('timeout') ? 'timeout ' . (int)$timeout . 's ' : '';
            @exec('cd ' . escapeshellarg($cwd) . ' && ' . $timeoutPrefix . $cmd . ' 2>&1', $output, $code);
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
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $stdout = '';
        $stderr = '';
        $started = time();
        $timedOut = false;
        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((time() - $started) > $timeout) {
                $timedOut = true;
                proc_terminate($process, 15);
                usleep(200000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }
            usleep(100000);
        }
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
        if ($timedOut) {
            $output .= ($output !== '' ? "\n" : '') . 'Команда прервана по таймауту ' . $timeout . ' сек.';
            $code = 124;
        }
        return new CommandResult((int)$code, $this->sanitizeOutput($output));
    }

    private function phpRuntimeDiagnostics(): array
    {
        return RuntimeDiagnostics::phpRuntime((string)$this->config()['php_bin']);
    }

    public function repairHints(): array
    {
        $config = $this->config();
        return [
            'Git недоступен' => 'apt install -y git && echo UPDATE_GIT_BIN=/usr/bin/git >> .env',
            'tar недоступен' => 'apt install -y tar && echo UPDATE_TAR_BIN=/usr/bin/tar >> .env',
            'PHP CLI недоступен' => 'echo UPDATE_PHP_BIN=/www/server/php/83/bin/php >> .env',
            'safe.directory' => (string)$config['git_bin'] . ' config --global --add safe.directory ' . $this->root(),
            'Проверка окружения' => (string)$config['php_bin'] . ' bin/update-env-check.php',
            'Локальные изменения' => 'git status --short && git diff --stat',
        ];
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
