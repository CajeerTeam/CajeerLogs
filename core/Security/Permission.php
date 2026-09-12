<?php
declare(strict_types=1);

namespace Cajeer\Logs\Security;

final class Permission
{
    public function __construct(public readonly string $name) {}
}
