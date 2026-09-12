<?php
declare(strict_types=1);

namespace Cajeer\Logs\Kernel;

final class Application
{
    private ServiceContainer $container;

public function __construct(private readonly string $basePath)
{
    $this->container = new ServiceContainer();
}

public function basePath(string $path = ''): string
{
    return rtrim($this->basePath . '/' . ltrim($path, '/'), '/');
}

public function version(): string
{
    return '0.1.0-initial';
}

public function container(): ServiceContainer
{
    return $this->container;
}

public function boot(): void
{
    $this->container->set('app', $this);
}
}
