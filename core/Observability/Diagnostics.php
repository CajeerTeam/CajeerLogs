<?php
declare(strict_types=1);

namespace Cajeer\Logs\Observability;

use Cajeer\Logs\Kernel\Application;

final class Diagnostics
{
    public function __construct(private readonly Application $app) {}

    /** @return array<string,bool> */
    public function checks(): array
    {
        $checks = ['php_8_4_or_newer' => PHP_VERSION_ID >= 80400];
        foreach (['json', 'pdo', 'ctype', 'filter', 'pcre','sodium'] as $extension) {
            $checks['extension_' . $extension] = extension_loaded($extension);
        }
        foreach (['core/bootstrap.php', 'public/index.php', 'release.json', 'config/app.php'] as $file) {
            $checks['readable_' . $file] = is_file($this->app->basePath($file)) && is_readable($this->app->basePath($file));
        }
        $checks['configuration_loaded'] = $this->app->container()->has('config');
        $checks['storage_writable'] = is_dir($this->app->basePath('storage')) && is_writable($this->app->basePath('storage'));
        if ($this->app->config()->get('ingest.max_inflight')>0) {
            $cache=$this->app->basePath('storage/cache');
            $checks['ingest_cache_writable']=is_dir($cache) && is_writable($cache);
        }
        return $checks;
    }

    public function targets(): array { return array_keys($this->checks()); }
    public function ok(): bool { return !in_array(false, $this->checks(), true); }
}
