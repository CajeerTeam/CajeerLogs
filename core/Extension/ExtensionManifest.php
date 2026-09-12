<?php
declare(strict_types=1);

namespace Cajeer\Logs\Extension;

final class ExtensionManifest
{
    public function __construct(public readonly string $name, public readonly string $type, public readonly string $version) {}
}
