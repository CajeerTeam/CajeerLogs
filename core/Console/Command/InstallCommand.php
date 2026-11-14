<?php
declare(strict_types=1);
namespace Cajeer\Logs\Console\Command;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Installer\Installer;
use Cajeer\Logs\Kernel\Application;

final class InstallCommand
{
    public function __construct(private readonly Application $app) {}

    public function handle(array $args): int
    {
        $email = (string)(getenv('CAJEER_ADMIN_EMAIL') ?: '');
        $nonInteractive = false;
        foreach ($args as $arg) {
            if ($arg === '--non-interactive') { $nonInteractive = true; }
            elseif (str_starts_with($arg, '--admin-email=')) { $email = substr($arg, 14); }
            else { throw new DatabaseException('Usage: install [--non-interactive] [--admin-email=EMAIL] (password via CAJEER_ADMIN_PASSWORD, CAJEER_ADMIN_PASSWORD_FILE or terminal).'); }
        }
        $interactive = !$nonInteractive && function_exists('stream_isatty') && stream_isatty(STDIN) && stream_isatty(STDERR);
        if ($interactive) {
            foreach (['host'=>'DB host','port'=>'DB port','database'=>'DB name','username'=>'DB user','schema'=>'DB schema'] as $key => $label) {
                $default = (string) $this->app->config()->get('database.' . $key);
                fwrite(STDERR, "$label [$default]: ");
                $value = fgets(STDIN);
                if ($value === false) { throw new DatabaseException('Installation input ended.'); }
                $value = trim($value);
                if ($value !== '') { $this->app->config()->set('database.' . $key, $value); }
            }
            if (in_array($this->app->config()->get('database.password'), ['', 'change_me'], true)) { $this->app->config()->set('database.password', $this->secret('PostgreSQL password: ')); }
            if ($email === '') { fwrite(STDERR, 'Administrator email: '); $email = trim((string) fgets(STDIN)); }
        }
        $password = \Cajeer\Logs\Installer\AdministratorPassword::supplied('CAJEER_ADMIN_PASSWORD','CAJEER_ADMIN_PASSWORD_FILE');
        if ($password === '' && $interactive) {
            $password = $this->secret('Administrator password (min 12 bytes): ');
            if ($password !== $this->secret('Repeat administrator password: ')) { throw new DatabaseException('Passwords do not match.'); }
        }
        if ($email === '' || $password === '') { throw new DatabaseException('Set CAJEER_ADMIN_EMAIL and CAJEER_ADMIN_PASSWORD or CAJEER_ADMIN_PASSWORD_FILE for non-interactive installation.'); }
        $result = (new Installer($this->app))->run($email, $password);
        foreach ($result['applied'] as $migration) { echo "Applied $migration\n"; }
        echo $result['administrator_created'] ? "Administrator created.\n" : "Administrator already exists; credentials verified.\n";
        echo "Installation complete. Run php bin/cajeer db:doctor.\n";
        return 0;
    }

    public function secret(string $prompt): string
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('exec')) { throw new DatabaseException('Hidden terminal input unavailable; supply password through environment.'); }
        fwrite(STDERR, $prompt);
        exec('stty -g < /dev/tty 2>/dev/null', $state, $code);
        if ($code !== 0 || !isset($state[0]) || !preg_match('/^[a-zA-Z0-9:;]+$/D', $state[0])) { throw new DatabaseException('Cannot configure secure terminal input.'); }
        exec('stty -echo < /dev/tty 2>/dev/null', $unused, $code);
        if ($code !== 0) { throw new DatabaseException('Cannot disable terminal echo.'); }
        $restored=false;
        $restore = static function () use ($state,&$restored): void {
            if (!$restored) { $restored=true; exec('stty ' . escapeshellarg($state[0]) . ' < /dev/tty 2>/dev/null'); }
        };
        register_shutdown_function($restore);
        $handlers=[]; $async=null;
        if (function_exists('pcntl_signal') && function_exists('pcntl_signal_get_handler')) {
            $async=pcntl_async_signals(); pcntl_async_signals(true);
            foreach ([SIGINT,SIGTERM,SIGHUP] as $signal) {
                $handlers[$signal]=pcntl_signal_get_handler($signal);
                pcntl_signal($signal,static function(int $signal) use ($restore): void { $restore(); exit(128+$signal); });
            }
        }
        try { $line = fgets(STDIN); if ($line === false) { throw new DatabaseException('Installation input ended.'); } return rtrim($line, "\r\n"); }
        finally {
            $restore();
            foreach ($handlers as $signal=>$handler) { pcntl_signal($signal,$handler); }
            if ($async!==null) { pcntl_async_signals($async); }
            fwrite(STDERR, PHP_EOL);
        }
    }
}
