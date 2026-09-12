<?php
declare(strict_types=1);

namespace Cajeer\Logs\Extension;

final class HookBus
{
    private array $listeners = [];
public function listen(string $event, callable $listener): void { $this->listeners[$event][] = $listener; }
public function dispatch(string $event, array $payload = []): void { foreach ($this->listeners[$event] ?? [] as $listener) { $listener($payload); } }
}
