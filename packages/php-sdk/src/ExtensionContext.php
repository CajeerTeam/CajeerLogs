<?php
declare(strict_types=1);

namespace Cajeer\Logs\Sdk;

final class ExtensionContext
{
    public function __construct(public readonly array $manifest) {}

    public function on(string $event, callable $listener): void
    {
        // Hook registration skeleton.
    }
}
