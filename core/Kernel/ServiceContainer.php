<?php
declare(strict_types=1);

namespace Cajeer\Logs\Kernel;

final class ServiceContainer
{
    /** @var array<string,mixed> */
private array $bindings = [];

public function set(string $id, mixed $value): void
{
    $this->bindings[$id] = $value;
}

public function get(string $id): mixed
{
    if (!array_key_exists($id, $this->bindings)) {
        throw new \RuntimeException("Service [$id] is not registered.");
    }
    return $this->bindings[$id];
}

public function has(string $id): bool
{
    return array_key_exists($id, $this->bindings);
}
}
