<?php
declare(strict_types=1);

namespace Cajeer\Logs\Kernel;

final class ServiceContainer
{
    /** @var array<string,mixed> */
    private array $bindings = [];
    /** @var array<string,array{factory: callable, shared: bool}> */
    private array $factories = [];
    /** @var array<string,bool> */
    private array $resolving = [];

    public function set(string $id, mixed $value): void
    {
        unset($this->factories[$id]);
        $this->bindings[$id] = $value;
    }

    public function bind(string $id, callable $factory, bool $shared = false): void
    {
        unset($this->bindings[$id]);
        $this->factories[$id] = ['factory' => $factory, 'shared' => $shared];
    }

    public function singleton(string $id, callable $factory): void
    {
        $this->bind($id, $factory, true);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->bindings)) {
            return $this->bindings[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new \RuntimeException("Service [$id] is not registered.");
        }
        if (isset($this->resolving[$id])) {
            throw new \RuntimeException("Circular service dependency [$id].");
        }
        $this->resolving[$id] = true;
        try {
            $binding = $this->factories[$id];
            $value = ($binding['factory'])($this);
            if ($binding['shared']) {
                $this->bindings[$id] = $value;
            }
            return $value;
        } finally {
            unset($this->resolving[$id]);
        }
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->bindings) || isset($this->factories[$id]);
    }
}
