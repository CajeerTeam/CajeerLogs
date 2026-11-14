<?php
declare(strict_types=1);
namespace Cajeer\Logs\Installer;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Observability\Diagnostics;

final class InstallPreflight
{
    public function __construct(private readonly Application $app) {}

    public function checks(): array
    {
        $path = $this->app->environmentPath();
        $directory = dirname($path);
        $free = is_dir($directory) ? disk_free_space($directory) : false;
        $storage = $this->app->basePath('storage');
        $storageFree = is_dir($storage) ? disk_free_space($storage) : false;
        return $this->app->container()->get(Diagnostics::class)->checks() + [
            'pdo_pgsql' => in_array('pgsql', \PDO::getAvailableDrivers(), true),
            'argon2id' => defined('PASSWORD_ARGON2ID'),
            'https_origin' => $this->app->config()->get('app.env') !== 'production' || parse_url($this->app->config()->get('app.url'), PHP_URL_SCHEME) === 'https',
            'configuration_directory_writable' => is_dir($directory) && is_writable($directory),
            'configuration_regular_file' => !is_link($path) && (!file_exists($path) || is_file($path)),
            'configuration_disk_space' => $free !== false && $free >= 1048576,
            'storage_disk_space' => $storageFree !== false && $storageFree >= 1048576,
        ];
    }

    public function assertReady(): void
    {
        $failed = array_keys(array_filter($this->checks(), static fn(bool $ok): bool => !$ok));
        if ($failed !== []) { throw new DatabaseException('Installation preflight failed: ' . implode(', ', $failed)); }
    }
}
