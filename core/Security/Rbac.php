<?php
declare(strict_types=1);

namespace Cajeer\Logs\Security;

final class Rbac
{
    public function allows(array $permissions, string $permission): bool
{
    return in_array($permission, $permissions, true);
}
}
